<?php
session_start();
require_once 'dbconnect.php';
require_once 'auth_helper.php';

// Check if user is logged in and is a student
checkPageAccess();
requireRole('student');

// Get student information
$student_name = getUserDisplayName();
$user_id = $_SESSION['user_id'];

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    switch ($_GET['action']) {
        case 'scan_book':
            handleScanBook($conn, $user_id);
            break;
        case 'borrow_book':
            handleBorrowBook($conn, $user_id);
            break;
        case 'reserve_book':
            handleReserveBook($conn, $user_id);
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
    exit;
}

// Function to scan/search for a book
function handleScanBook($conn, $user_id) {
    $barcode = trim($_POST['barcode'] ?? '');
    
    if (empty($barcode)) {
        echo json_encode(['success' => false, 'message' => 'Please enter a barcode or ISBN']);
        return;
    }
    
    try {
        // Search for book by barcode or ISBN
        $query = "
            SELECT 
                b.bookID,
                b.bookTitle,
                b.bookAuthor,
                b.bookPublisher,
                b.book_ISBN,
                b.bookBarcode,
                b.bookStatus,
                b.book_description,
                b.publication_year,
                b.language,
                b.shelf_location,
                b.book_image,
                b.book_image_mime,
                bc.categoryName,
                CASE 
                    WHEN b.bookStatus = 'available' THEN 'Available'
                    WHEN b.bookStatus = 'borrowed' THEN 'Borrowed'
                    WHEN b.bookStatus = 'reserved' THEN 'Reserved'
                    WHEN b.bookStatus = 'maintenance' THEN 'Under Maintenance'
                    ELSE 'Unavailable'
                END as status_display
            FROM book b
            LEFT JOIN book_category bc ON b.categoryID = bc.categoryID
            WHERE (b.bookBarcode = ? OR b.book_ISBN = ?) 
            AND b.bookStatus != 'disposed'
            LIMIT 1
        ";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ss", $barcode, $barcode);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($book = $result->fetch_assoc()) {
            // Convert image to base64 for JSON response
            if ($book['book_image'] && $book['book_image_mime']) {
                $book['book_image_base64'] = 'data:' . $book['book_image_mime'] . ';base64,' . base64_encode($book['book_image']);
            } else {
                $book['book_image_base64'] = null;
            }
            unset($book['book_image']);
            
            // Check if user already has this book borrowed
            $check_borrowed = "SELECT borrowID FROM borrow WHERE userID = ? AND bookID = ? AND borrow_status = 'borrowed'";
            $stmt_check = $conn->prepare($check_borrowed);
            $stmt_check->bind_param("ii", $user_id, $book['bookID']);
            $stmt_check->execute();
            $book['already_borrowed'] = $stmt_check->get_result()->num_rows > 0;
            
            // Check if user has this book reserved
            $check_reserved = "SELECT reservationID FROM reservation WHERE userID = ? AND bookID = ? AND reservation_status IN ('waiting', 'ready')";
            $stmt_check2 = $conn->prepare($check_reserved);
            $stmt_check2->bind_param("ii", $user_id, $book['bookID']);
            $stmt_check2->execute();
            $book['already_reserved'] = $stmt_check2->get_result()->num_rows > 0;
            
            echo json_encode(['success' => true, 'book' => $book]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Book not found. Please check the barcode/ISBN.']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error scanning book: ' . $e->getMessage()]);
        error_log("Scan error: " . $e->getMessage());
    }
}

// Function to borrow a book
function handleBorrowBook($conn, $user_id) {
    $book_id = intval($_POST['book_id'] ?? 0);
    
    if (!$book_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid book ID']);
        return;
    }
    
    try {
        $conn->begin_transaction();
        
        // Check current borrowed books count
        $count_query = "SELECT COUNT(*) as count FROM borrow WHERE userID = ? AND borrow_status = 'borrowed'";
        $stmt_count = $conn->prepare($count_query);
        $stmt_count->bind_param("i", $user_id);
        $stmt_count->execute();
        $count_result = $stmt_count->get_result()->fetch_assoc();
        
        if ($count_result['count'] >= 3) {
            throw new Exception('You have reached the maximum limit of 3 borrowed books');
        }
        
        // Check if book is available
        $check_query = "SELECT bookID, bookTitle, bookStatus FROM book WHERE bookID = ? FOR UPDATE";
        $stmt_check = $conn->prepare($check_query);
        $stmt_check->bind_param("i", $book_id);
        $stmt_check->execute();
        $book_result = $stmt_check->get_result();
        
        if ($book_result->num_rows === 0) {
            throw new Exception('Book not found');
        }
        
        $book_data = $book_result->fetch_assoc();
        if ($book_data['bookStatus'] !== 'available') {
            throw new Exception('Book is not available for borrowing');
        }
        
        // Check if user already borrowed this book
        $existing_check = "SELECT borrowID FROM borrow WHERE userID = ? AND bookID = ? AND borrow_status = 'borrowed'";
        $stmt_existing = $conn->prepare($existing_check);
        $stmt_existing->bind_param("ii", $user_id, $book_id);
        $stmt_existing->execute();
        
        if ($stmt_existing->get_result()->num_rows > 0) {
            throw new Exception('You have already borrowed this book');
        }
        
        // Get borrowing rules
        $rules_query = "SELECT borrow_period_days FROM borrowing_rules WHERE user_type = 'student' LIMIT 1";
        $rules_result = $conn->query($rules_query);
        $borrow_period = 14;
        if ($rules_result && $rule = $rules_result->fetch_assoc()) {
            $borrow_period = $rule['borrow_period_days'];
        }
        
        // Create borrow record
        $borrow_date = date('Y-m-d');
        $due_date = date('Y-m-d', strtotime("+$borrow_period days"));
        
        $borrow_query = "INSERT INTO borrow (userID, bookID, borrow_date, due_date, borrow_status, checkout_method, self_checkout_time) VALUES (?, ?, ?, ?, 'borrowed', 'self_service', NOW())";
        $stmt_borrow = $conn->prepare($borrow_query);
        $stmt_borrow->bind_param("iiss", $user_id, $book_id, $borrow_date, $due_date);
        $stmt_borrow->execute();
        
        // Update book status
        $update_query = "UPDATE book SET bookStatus = 'borrowed', updated_date = NOW() WHERE bookID = ?";
        $stmt_update = $conn->prepare($update_query);
        $stmt_update->bind_param("i", $book_id);
        $stmt_update->execute();
        
        $conn->commit();
        
        echo json_encode([
            'success' => true, 
            'message' => 'Book borrowed successfully! Due date: ' . date('M d, Y', strtotime($due_date)),
            'due_date' => $due_date,
            'book_title' => $book_data['bookTitle']
        ]);
        
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        error_log("Borrow error: " . $e->getMessage());
    }
}

// Function to reserve a book
function handleReserveBook($conn, $user_id) {
    $book_id = intval($_POST['book_id'] ?? 0);
    
    if (!$book_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid book ID']);
        return;
    }
    
    try {
        $conn->begin_transaction();
        
        // Check if book is borrowed
        $check_query = "SELECT bookStatus, bookTitle FROM book WHERE bookID = ? FOR UPDATE";
        $stmt_check = $conn->prepare($check_query);
        $stmt_check->bind_param("i", $book_id);
        $stmt_check->execute();
        $book_result = $stmt_check->get_result();
        
        if ($book_result->num_rows === 0) {
            throw new Exception('Book not found');
        }
        
        $book_data = $book_result->fetch_assoc();
        if ($book_data['bookStatus'] !== 'borrowed') {
            throw new Exception('You can only reserve books that are currently borrowed');
        }
        
        // Check if user already has this book reserved
        $existing_query = "SELECT reservationID FROM reservation WHERE userID = ? AND bookID = ? AND reservation_status IN ('waiting', 'ready')";
        $stmt_existing = $conn->prepare($existing_query);
        $stmt_existing->bind_param("ii", $user_id, $book_id);
        $stmt_existing->execute();
        
        if ($stmt_existing->get_result()->num_rows > 0) {
            throw new Exception('You have already reserved this book');
        }
        
        // Get queue position
        $queue_query = "SELECT COALESCE(MAX(queue_position), 0) as max_pos FROM reservation WHERE bookID = ? AND reservation_status = 'waiting'";
        $stmt_queue = $conn->prepare($queue_query);
        $stmt_queue->bind_param("i", $book_id);
        $stmt_queue->execute();
        $queue_result = $stmt_queue->get_result()->fetch_assoc();
        $queue_position = ($queue_result['max_pos'] ?? 0) + 1;
        
        // Create reservation
        $reservation_date = date('Y-m-d H:i:s');
        $expiry_date = date('Y-m-d H:i:s', strtotime('+30 days'));
        
        $reserve_query = "INSERT INTO reservation (userID, bookID, reservation_date, expiry_date, queue_position, reservation_status, created_date) VALUES (?, ?, ?, ?, ?, 'waiting', NOW())";
        $stmt_reserve = $conn->prepare($reserve_query);
        $stmt_reserve->bind_param("iissi", $user_id, $book_id, $reservation_date, $expiry_date, $queue_position);
        $stmt_reserve->execute();
        
        $conn->commit();
        
        echo json_encode([
            'success' => true, 
            'message' => 'Book reserved successfully! You are #' . $queue_position . ' in the queue.', 
            'queue_position' => $queue_position,
            'book_title' => $book_data['bookTitle']
        ]);
        
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        error_log("Reserve error: " . $e->getMessage());
    }
}

// Get student info
try {
    $stmt = $conn->prepare("SELECT studentClass FROM student WHERE userID = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $student_class = $stmt->get_result()->fetch_assoc()['studentClass'] ?? '';
    
    // Get unread notifications count
    $stmt = $conn->prepare("SELECT COUNT(*) as c FROM notifications WHERE userID = ? AND read_status = 0");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $unread_count = $stmt->get_result()->fetch_assoc()['c'];
} catch (Exception $e) {
    error_log($e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scan Barcode - SMK Chendering Library</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --bg-primary: #FAF8F5;
            --bg-secondary: #F7F4EF;
            --bg-card: #FFFFFF;
            --sidebar-bg: #2C3E50;
            
            --accent-primary: #E74C3C;
            --accent-secondary: #3498DB;
            --accent-success: #27AE60;
            --accent-warning: #F39C12;
            
            --text-primary: #2C3E50;
            --text-secondary: #7F8C8D;
            --text-muted: #95A5A6;
            --text-white: #FFFFFF;
            
            --border: #E8E3DC;
            --shadow-sm: 0 2px 8px rgba(44, 62, 80, 0.08);
            --shadow-md: 0 4px 16px rgba(44, 62, 80, 0.12);
            --shadow-lg: 0 8px 24px rgba(44, 62, 80, 0.15);
            --shadow-3d: 0 20px 40px rgba(44, 62, 80, 0.2);
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            line-height: 1.6;
        }

        /* SIDEBAR */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 280px;
            height: 100vh;
            background: var(--sidebar-bg);
            display: flex;
            flex-direction: column;
            z-index: 1000;
            box-shadow: var(--shadow-lg);
        }

        .brand-section {
            padding: 32px 24px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .brand-logo {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 24px;
        }

        .logo-icon {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, var(--accent-primary), var(--accent-secondary));
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
        }
        .school-logo {
            width: 60px;
            height: 60px;
            object-fit: contain;
            border-radius: 50%;
            margin-right: 10px;
        }

        .brand-text h3 {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-white);
            margin-bottom: 2px;
        }

        .brand-text p {
            font-size: 13px;
            color: rgba(255, 255, 255, 0.6);
        }

        .profile-card {
            background: rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 16px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .profile-avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--accent-secondary), var(--accent-primary));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            font-weight: 700;
            color: white;
        }

        .profile-info h4 {
            font-size: 15px;
            font-weight: 600;
            color: var(--text-white);
            margin-bottom: 2px;
        }

        .profile-info p {
            font-size: 12px;
            color: rgba(255, 255, 255, 0.6);
        }

        .nav-menu {
            flex: 1;
            padding: 24px 16px;
            overflow-y: auto;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 12px 16px;
            margin-bottom: 4px;
            color: rgba(255, 255, 255, 0.7);
            text-decoration: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 500;
            transition: all 0.2s;
        }

        .nav-item:hover {
            background: rgba(255, 255, 255, 0.1);
            color: var(--text-white);
        }

        .nav-item.active {
            background: rgba(231, 76, 60, 0.15);
            color: var(--text-white);
        }

        .nav-item i {
            width: 20px;
            font-size: 18px;
        }

        .nav-badge {
            margin-left: auto;
            background: var(--accent-primary);
            color: white;
            font-size: 11px;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 10px;
            min-width: 20px;
            text-align: center;
        }

        .sidebar-footer {
            padding: 20px 16px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }

        .nav-item.logout {
            color: #E74C3C;
        }

        /* MAIN WRAPPER */
        .main-wrapper {
            margin-left: 280px;
            min-height: 100vh;
        }

        /* TOP HEADER */
        .top-header {
            background: var(--bg-card);
            padding: 24px 40px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid var(--border);
            position: sticky;
            top: 0;
            z-index: 900;
            box-shadow: var(--shadow-sm);
        }

        .page-title {
            font-family: 'Playfair Display', serif;
            font-size: 28px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .action-btn {
            width: 44px;
            height: 44px;
            background: var(--bg-secondary);
            border: none;
            border-radius: 12px;
            color: var(--text-secondary);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
            position: relative;
        }

        .action-btn:hover {
            background: var(--accent-secondary);
            color: white;
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .notif-badge {
            position: absolute;
            top: -4px;
            right: -4px;
            background: var(--accent-primary);
            color: white;
            font-size: 10px;
            font-weight: 700;
            padding: 2px 6px;
            border-radius: 10px;
            min-width: 18px;
        }

        .user-btn {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 8px 16px 8px 8px;
            background: var(--bg-secondary);
            border: none;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .user-btn:hover {
            background: var(--border);
        }

        .user-avatar-sm {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--accent-secondary), var(--accent-primary));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            font-weight: 700;
            color: white;
        }

        .user-info strong {
            display: block;
            font-size: 14px;
            color: var(--text-primary);
        }

        .user-info span {
            font-size: 12px;
            color: var(--text-secondary);
        }

        /* CONTENT AREA */
        .content-area {
            padding: 40px;
            max-width: 1200px;
            margin: 0 auto;
        }

        /* SCANNER SECTION */
        .scanner-section {
            background: linear-gradient(135deg, var(--bg-card) 0%, var(--bg-secondary) 100%);
            border-radius: 24px;
            padding: 48px;
            margin-bottom: 40px;
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--border);
            text-align: center;
        }

        .scanner-icon-wrapper {
            width: 120px;
            height: 120px;
            margin: 0 auto 32px;
            background: linear-gradient(135deg, var(--accent-primary), var(--accent-secondary));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 12px 32px rgba(231, 76, 60, 0.3);
            animation: pulse-ring 2s infinite;
        }

        @keyframes pulse-ring {
            0%, 100% {
                box-shadow: 0 12px 32px rgba(231, 76, 60, 0.3), 0 0 0 0 rgba(231, 76, 60, 0.7);
            }
            50% {
                box-shadow: 0 12px 32px rgba(231, 76, 60, 0.3), 0 0 0 20px rgba(231, 76, 60, 0);
            }
        }

        .scanner-icon-wrapper i {
            font-size: 56px;
            color: white;
        }

        .scanner-title {
            font-family: 'Playfair Display', serif;
            font-size: 36px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 12px;
        }

        .scanner-subtitle {
            font-size: 18px;
            color: var(--text-secondary);
            margin-bottom: 40px;
        }

        .barcode-input-wrapper {
            max-width: 600px;
            margin: 0 auto 24px;
            position: relative;
        }

        .barcode-input-wrapper i {
            position: absolute;
            left: 24px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 24px;
            color: var(--accent-primary);
        }

        .barcode-input {
            width: 100%;
            padding: 20px 24px 20px 72px;
            background: var(--bg-card);
            border: 3px solid var(--border);
            border-radius: 16px;
            font-size: 20px;
            font-weight: 600;
            color: var(--text-primary);
            transition: all 0.3s;
        }

        .barcode-input:focus {
            outline: none;
            border-color: var(--accent-secondary);
            box-shadow: 0 0 0 6px rgba(52, 152, 219, 0.1);
        }

        .btn-scan {
            padding: 18px 48px;
            background: var(--accent-primary);
            color: white;
            border: none;
            border-radius: 16px;
            font-size: 18px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: var(--shadow-md);
            display: inline-flex;
            align-items: center;
            gap: 12px;
        }

        .btn-scan:hover {
            background: #C0392B;
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
        }

        .btn-scan:disabled {
            background: var(--text-muted);
            cursor: not-allowed;
            transform: none;
        }

        /* BOOK RESULT */
        .book-result {
            background: var(--bg-card);
            border-radius: 20px;
            padding: 40px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--border);
            display: none;
        }

        .book-result.show {
            display: block;
            animation: slideUp 0.4s ease;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .book-result-grid {
            display: grid;
            grid-template-columns: 250px 1fr;
            gap: 40px;
            margin-bottom: 32px;
        }

        .book-cover-section {
            position: relative;
        }

        .book-cover-wrapper {
            width: 100%;
            aspect-ratio: 2/3;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: var(--shadow-3d);
            margin-bottom: 16px;
        }

        .book-cover-wrapper img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .book-placeholder {
            width: 100%;
            height: 100%;
            background: var(--bg-secondary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 72px;
            color: var(--text-muted);
        }

        .book-info-section h2 {
            font-family: 'Playfair Display', serif;
            font-size: 32px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 12px;
        }

        .book-author {
            font-size: 20px;
            color: var(--accent-secondary);
            margin-bottom: 24px;
            font-weight: 600;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 24px;
        }

        .status-available {
            background: rgba(39, 174, 96, 0.1);
            color: var(--accent-success);
            border: 2px solid var(--accent-success);
        }

        .status-borrowed {
            background: rgba(231, 76, 60, 0.1);
            color: var(--accent-primary);
            border: 2px solid var(--accent-primary);
        }

        .book-details-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
            margin-bottom: 32px;
        }

        .detail-item {
            background: var(--bg-secondary);
            padding: 16px;
            border-radius: 12px;
        }

        .detail-label {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            margin-bottom: 6px;
        }

        .detail-value {
            font-size: 15px;
            font-weight: 600;
            color: var(--text-primary);
        }

        .book-actions {
            display: flex;
            gap: 12px;
        }

        .btn {
            padding: 14px 32px;
            border: none;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: var(--accent-primary);
            color: white;
        }

        .btn-primary:hover {
            background: #C0392B;
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .btn-secondary {
            background: var(--bg-card);
            color: var(--accent-secondary);
            border: 2px solid var(--accent-secondary);
        }

        .btn-secondary:hover {
            background: var(--accent-secondary);
            color: white;
        }

        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

        /* LOADING OVERLAY */
        .loading-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(8px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
        }

        .loading-overlay.show {
            display: flex;
        }

        .loading-content {
            background: var(--bg-card);
            padding: 32px;
            border-radius: 16px;
            text-align: center;
            box-shadow: var(--shadow-lg);
        }

        .loading-spinner {
            font-size: 48px;
            color: var(--accent-secondary);
            margin-bottom: 16px;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        /* ALERT */
        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            display: none;
            align-items: center;
            gap: 12px;
            font-weight: 500;
            border: 2px solid;
            animation: slideDown 0.3s ease;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .alert.show {
            display: flex;
        }

        .alert-success {
            background: rgba(39, 174, 96, 0.1);
            color: var(--accent-success);
            border-color: var(--accent-success);
        }

        .alert-error {
            background: rgba(231, 76, 60, 0.1);
            color: var(--accent-primary);
            border-color: var(--accent-primary);
        }

        .alert i {
            font-size: 20px;
        }

        /* RESPONSIVE */
        @media (max-width: 1024px) {
            .book-result-grid {
                grid-template-columns: 1fr;
                text-align: center;
            }
            
            .book-cover-wrapper {
                max-width: 300px;
                margin: 0 auto 24px;
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s;
            }
            
            .sidebar.show {
                transform: translateX(0);
            }
            
            .main-wrapper {
                margin-left: 0;
            }
            
            .top-header {
                padding: 16px 20px;
            }
            
            .content-area {
                padding: 24px 20px;
            }
            
            .scanner-section {
                padding: 32px 24px;
            }
            
            .scanner-title {
                font-size: 28px;
            }
            
            .book-result {
                padding: 24px 20px;
            }
            
            .book-details-grid {
                grid-template-columns: 1fr;
            }
            
            .book-actions {
                flex-direction: column;
            }
            
            .user-info {
                display: none;
            }
        }
    </style>
</head>
<body>

<!-- Loading Overlay -->
<div class="loading-overlay" id="loadingOverlay">
    <div class="loading-content">
        <i class="fas fa-spinner loading-spinner"></i>
        <div class="loading-text">Processing...</div>
    </div>
</div>

<!-- Sidebar -->
<aside class="sidebar" id="sidebar">
    <div class="brand-section">
        <div class="brand-logo">
            <img src="photo/logo1.png" alt="School Logo" class="school-logo">
            <div class="brand-text">
                <h3>SMK Chendering</h3>
                <p>Library System</p>
            </div>
        </div>
        
        <div class="profile-card">
            <div class="profile-avatar"><?php echo strtoupper(substr($student_name, 0, 1)); ?></div>
            <div class="profile-info">
                <h4><?php echo htmlspecialchars(explode(' ', $student_name)[0]); ?></h4>
                <p><?php echo htmlspecialchars($student_class ?: 'Student'); ?></p>
            </div>
        </div>
    </div>
    
    <nav class="nav-menu">
        <a href="student_dashboard.php" class="nav-item ">
            <i class="fas fa-home"></i>
            <span>Dashboard</span>
        </a>
        <a href="student_search_books.php" class="nav-item">
            <i class="fas fa-book"></i>
            <span>Browse Books</span>
        </a>
        <a href="student_borrowing_reservations.php" class="nav-item active">
            <i class="fas fa-barcode"></i>
            <span>Scan Barcode</span>
        </a>
        <a href="student_my_borrowed_books.php" class="nav-item">
            <i class="fas fa-bookmark"></i>
            <span>My Books</span>
        </a>
        <a href="student_return_book.php" class="nav-item ">
            <i class="fas fa-undo"></i>
            <span>Return Book</span>
        </a>
        
        <a href="student_notifications.php" class="nav-item">
            <i class="fas fa-bell"></i>
            <span>Notifications</span>
            <?php if ($unread_count > 0): ?>
            <span class="nav-badge"><?php echo $unread_count; ?></span>
            <?php endif; ?>
        </a>
        <a href="student_profile.php" class="nav-item">
            <i class="fas fa-user"></i>
            <span>Profile</span>
        </a>
        
    </nav>
    
    <div class="sidebar-footer">
        <a href="logout.php" class="nav-item logout">
            <i class="fas fa-sign-out-alt"></i>
            <span>Logout</span>
        </a>
    </div>
</aside>

<!-- Main Content -->
<div class="main-wrapper">
    <!-- Top Header -->
    <div class="top-header">
        <h1 class="page-title">Scan Barcode</h1>
        
        <div class="header-actions">
            <button class="action-btn" onclick="window.location.href='student_notifications.php'" title="Notifications">
                <i class="fas fa-bell"></i>
                <?php if ($unread_count > 0): ?>
                <span class="notif-badge"><?php echo min($unread_count, 99); ?></span>
                <?php endif; ?>
            </button>
            <button class="action-btn" onclick="window.location.href='student_profile.php'" title="Profile">
                <i class="fas fa-user"></i>
            </button>
            <button class="user-btn" onclick="window.location.href='student_profile.php'">
                <div class="user-avatar-sm"><?php echo strtoupper(substr($student_name, 0, 1)); ?></div>
                <div class="user-info">
                    <strong><?php echo htmlspecialchars(explode(' ', $student_name)[0]); ?></strong>
                    <span><?php echo htmlspecialchars($student_class ?: 'Student'); ?></span>
                </div>
            </button>
        </div>
    </div>
    
    <!-- Content Area -->
    <div class="content-area">
        <!-- Alert Message -->
        <div id="alertMessage" class="alert">
            <i class="fas fa-info-circle"></i>
            <span id="alertText"></span>
        </div>
        
        <!-- Scanner Section -->
        <div class="scanner-section">
            <div class="scanner-icon-wrapper">
                <i class="fas fa-barcode"></i>
            </div>
            
            <h1 class="scanner-title">Book Barcode Scanner</h1>
            <p class="scanner-subtitle">Scan or enter ISBN/Barcode to borrow or reserve books</p>
            
            <div class="barcode-input-wrapper">
                <i class="fas fa-barcode"></i>
                <input 
                    type="text" 
                    id="barcodeInput" 
                    class="barcode-input" 
                    placeholder="Scan barcode or enter ISBN..."
                    autocomplete="off"
                    autofocus
                >
            </div>
            
            <button class="btn-scan" id="scanButton">
                <i class="fas fa-search"></i>
                Find Book
            </button>
        </div>
        
        <!-- Book Result -->
        <div class="book-result" id="bookResult">
            <div class="book-result-grid">
                <div class="book-cover-section">
                    <div class="book-cover-wrapper" id="bookCoverWrapper">
                        <div class="book-placeholder">
                            <i class="fas fa-book"></i>
                        </div>
                    </div>
                </div>
                
                <div class="book-info-section">
                    <h2 id="bookTitle">Book Title</h2>
                    <p class="book-author" id="bookAuthor">by Author Name</p>
                    
                    <div id="bookStatusBadge" class="status-badge status-available">
                        <i class="fas fa-circle"></i>
                        Available
                    </div>
                    
                    <div class="book-details-grid">
                        <div class="detail-item">
                            <div class="detail-label">ISBN</div>
                            <div class="detail-value" id="bookISBN">-</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Category</div>
                            <div class="detail-value" id="bookCategory">-</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Publisher</div>
                            <div class="detail-value" id="bookPublisher">-</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Location</div>
                            <div class="detail-value" id="bookLocation">-</div>
                        </div>
                    </div>
                    
                    <div class="book-actions" id="bookActions">
                        <button class="btn btn-primary" onclick="borrowBook()">
                            <i class="fas fa-book"></i> Borrow Book
                        </button>
                        <button class="btn btn-secondary" onclick="reserveBook()">
                            <i class="fas fa-bookmark"></i> Reserve Book
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    let currentBook = null;
    let isProcessing = false;
    
    // Initialize
    document.addEventListener('DOMContentLoaded', function() {
        setupEventListeners();
        setupBarcodeScanner();
    });
    
    // Setup event listeners
    function setupEventListeners() {
        const barcodeInput = document.getElementById('barcodeInput');
        const scanButton = document.getElementById('scanButton');
        
        scanButton.addEventListener('click', scanBook);
        
        barcodeInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                scanBook();
            }
        });
        
        barcodeInput.focus();
    }
    
    // Setup barcode scanner
    function setupBarcodeScanner() {
        const barcodeInput = document.getElementById('barcodeInput');
        let scanTimeout = null;
        
        barcodeInput.addEventListener('input', function(e) {
            const value = e.target.value;
            
            if (scanTimeout) {
                clearTimeout(scanTimeout);
            }
            
            // Auto-trigger scan for scanned barcodes
            if (value.length >= 10 && /^\d+$/.test(value)) {
                scanTimeout = setTimeout(() => {
                    scanBook();
                }, 500);
            }
        });
    }
    
    // Scan book
    async function scanBook() {
        const barcode = document.getElementById('barcodeInput').value.trim();
        
        if (!barcode) {
            showAlert('Please enter a barcode or ISBN', 'error');
            return;
        }
        
        if (isProcessing) return;
        
        showLoading(true);
        isProcessing = true;
        hideBookResult();
        
        try {
            const formData = new FormData();
            formData.append('barcode', barcode);
            
            const response = await fetch('?action=scan_book', {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            
            if (data.success) {
                currentBook = data.book;
                displayBook(data.book);
                document.getElementById('barcodeInput').value = '';
                hideAlert();
            } else {
                showAlert(data.message, 'error');
                hideBookResult();
            }
        } catch (error) {
            console.error('Scan error:', error);
            showAlert('Error scanning book. Please try again.', 'error');
            hideBookResult();
        } finally {
            showLoading(false);
            isProcessing = false;
            document.getElementById('barcodeInput').focus();
        }
    }
    
    // Display book
    function displayBook(book) {
        // Show book result
        document.getElementById('bookResult').classList.add('show');
        
        // Book cover
        const coverWrapper = document.getElementById('bookCoverWrapper');
        if (book.book_image_base64) {
            coverWrapper.innerHTML = `<img src="${book.book_image_base64}" alt="${escapeHtml(book.bookTitle)}">`;
        } else {
            coverWrapper.innerHTML = '<div class="book-placeholder"><i class="fas fa-book"></i></div>';
        }
        
        // Book info
        document.getElementById('bookTitle').textContent = book.bookTitle;
        document.getElementById('bookAuthor').textContent = 'by ' + (book.bookAuthor || 'Unknown Author');
        
        // Status badge
        const statusBadge = document.getElementById('bookStatusBadge');
        statusBadge.className = 'status-badge status-' + book.bookStatus.toLowerCase();
        statusBadge.innerHTML = `<i class="fas fa-circle"></i> ${book.status_display}`;
        
        // Details
        document.getElementById('bookISBN').textContent = book.book_ISBN || '-';
        document.getElementById('bookCategory').textContent = book.categoryName || '-';
        document.getElementById('bookPublisher').textContent = book.bookPublisher || '-';
        document.getElementById('bookLocation').textContent = book.shelf_location || '-';
        
        // Actions
        const actionsDiv = document.getElementById('bookActions');
        actionsDiv.innerHTML = generateActionButtons(book);
    }
    
    // Generate action buttons
    function generateActionButtons(book) {
        let buttons = [];
        
        // Borrow button
        if (book.bookStatus === 'available' && !book.already_borrowed) {
            buttons.push(`
                <button class="btn btn-primary" onclick="borrowBook()">
                    <i class="fas fa-book"></i> Borrow Book
                </button>
            `);
        } else if (book.already_borrowed) {
            buttons.push(`
                <button class="btn btn-primary" disabled>
                    <i class="fas fa-check"></i> Already Borrowed by You
                </button>
            `);
        } else {
            buttons.push(`
                <button class="btn btn-primary" disabled>
                    <i class="fas fa-times"></i> Not Available
                </button>
            `);
        }
        
        // Reserve button
        if (book.bookStatus === 'borrowed' && !book.already_reserved && !book.already_borrowed) {
            buttons.push(`
                <button class="btn btn-secondary" onclick="reserveBook()">
                    <i class="fas fa-bookmark"></i> Reserve Book
                </button>
            `);
        } else if (book.already_reserved) {
            buttons.push(`
                <button class="btn btn-secondary" disabled>
                    <i class="fas fa-check"></i> Already Reserved
                </button>
            `);
        } else {
            buttons.push(`
                <button class="btn btn-secondary" disabled>
                    <i class="fas fa-times"></i> Cannot Reserve
                </button>
            `);
        }
        
        return buttons.join('');
    }
    
    // Borrow book
    async function borrowBook() {
        if (!currentBook || isProcessing) return;
        
        if (!confirm(`Borrow "${currentBook.bookTitle}"?`)) return;
        
        showLoading(true);
        isProcessing = true;
        
        try {
            const formData = new FormData();
            formData.append('book_id', currentBook.bookID);
            
            const response = await fetch('?action=borrow_book', {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            
            if (data.success) {
                showAlert(data.message, 'success');
                hideBookResult();
                currentBook = null;
                
                setTimeout(() => {
                    window.location.href = 'student_my_borrowed_books.php';
                }, 2000);
            } else {
                showAlert(data.message, 'error');
            }
        } catch (error) {
            console.error('Borrow error:', error);
            showAlert('Error borrowing book. Please try again.', 'error');
        } finally {
            showLoading(false);
            isProcessing = false;
        }
    }
    
    // Reserve book
    async function reserveBook() {
        if (!currentBook || isProcessing) return;
        
        if (!confirm(`Reserve "${currentBook.bookTitle}"? You will be notified when it becomes available.`)) return;
        
        showLoading(true);
        isProcessing = true;
        
        try {
            const formData = new FormData();
            formData.append('book_id', currentBook.bookID);
            
            const response = await fetch('?action=reserve_book', {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            
            if (data.success) {
                showAlert(data.message, 'success');
                hideBookResult();
                currentBook = null;
                
                setTimeout(() => {
                    window.location.href = 'student_borrowing_reservations.php';
                }, 2000);
            } else {
                showAlert(data.message, 'error');
            }
        } catch (error) {
            console.error('Reserve error:', error);
            showAlert('Error reserving book. Please try again.', 'error');
        } finally {
            showLoading(false);
            isProcessing = false;
        }
    }
    
    // Show/hide book result
    function hideBookResult() {
        document.getElementById('bookResult').classList.remove('show');
    }
    
    // Show loading
    function showLoading(show) {
        const overlay = document.getElementById('loadingOverlay');
        if (show) {
            overlay.classList.add('show');
        } else {
            overlay.classList.remove('show');
        }
    }
    
    // Show alert
    function showAlert(message, type) {
        const alert = document.getElementById('alertMessage');
        const alertText = document.getElementById('alertText');
        
        alert.className = `alert alert-${type} show`;
        alertText.textContent = message;
        
        // Scroll to top
        window.scrollTo({ top: 0, behavior: 'smooth' });
        
        // Auto hide after 5 seconds
        setTimeout(() => {
            hideAlert();
        }, 5000);
    }
    
    // Hide alert
    function hideAlert() {
        document.getElementById('alertMessage').classList.remove('show');
    }
    
    // Escape HTML
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Escape to clear
        if (e.key === 'Escape') {
            hideBookResult();
            document.getElementById('barcodeInput').value = '';
            document.getElementById('barcodeInput').focus();
            currentBook = null;
        }
    });
    
    // Console branding
    console.log('%c📚 SMK Chendering Library - Barcode Scanner', 'color: #E74C3C; font-size: 20px; font-weight: bold; padding: 10px;');
    console.log('%cScan Barcode Page v1.0', 'color: #7F8C8D; font-size: 14px; padding: 5px;');
</script>

</body>
</html>