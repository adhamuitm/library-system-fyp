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

// Get book ID from URL
$book_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$book_id) {
    header('Location: student_search_books.php');
    exit;
}

// Handle AJAX requests for borrow/reserve actions
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    $action = $_POST['action'];
    $book_id = (int)$_POST['book_id'];
    
    try {
        if ($action === 'borrow') {
            // Start transaction
            $conn->begin_transaction();
            
            // Check if book is available
            $check_query = "SELECT bookStatus FROM book WHERE bookID = ? AND bookStatus = 'available'";
            $stmt = $conn->prepare($check_query);
            $stmt->bind_param("i", $book_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                // Check if user hasn't exceeded borrowing limit
                $limit_query = "SELECT COUNT(*) as borrowed_count FROM borrow WHERE userID = ? AND borrow_status = 'borrowed'";
                $stmt = $conn->prepare($limit_query);
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $limit_result = $stmt->get_result();
                $borrowed_count = $limit_result->fetch_assoc()['borrowed_count'];
                
                // Get borrowing rules for students
                $rules_query = "SELECT max_books_allowed, borrow_period_days FROM borrowing_rules WHERE user_type = 'student'";
                $rules_result = $conn->query($rules_query);
                $rules = $rules_result->fetch_assoc();
                
                $max_books = $rules['max_books_allowed'] ?? 3;
                
                if ($borrowed_count >= $max_books) {
                    $conn->rollback();
                    echo json_encode(['success' => false, 'message' => 'You have reached your borrowing limit of ' . $max_books . ' books']);
                    exit;
                }
                
                // Insert borrow record
                $borrow_period = $rules['borrow_period_days'] ?? 14;
                $due_date = date('Y-m-d', strtotime("+{$borrow_period} days"));
                
                $insert_query = "INSERT INTO borrow (userID, bookID, borrow_date, due_date, borrow_status, checkout_method) 
                                VALUES (?, ?, CURDATE(), ?, 'borrowed', 'self_service')";
                $stmt = $conn->prepare($insert_query);
                $stmt->bind_param("iis", $user_id, $book_id, $due_date);
                
                if (!$stmt->execute()) {
                    throw new Exception("Failed to create borrow record");
                }
                
                // Update book status
                $update_query = "UPDATE book SET bookStatus = 'borrowed' WHERE bookID = ?";
                $stmt = $conn->prepare($update_query);
                $stmt->bind_param("i", $book_id);
                
                if (!$stmt->execute()) {
                    throw new Exception("Failed to update book status");
                }
                
                // Commit transaction
                $conn->commit();
                
                echo json_encode([
                    'success' => true, 
                    'message' => 'Book borrowed successfully! Due date: ' . date('M d, Y', strtotime($due_date)), 
                    'redirect' => 'student_my_borrowed_books.php'
                ]);
            } else {
                $conn->rollback();
                echo json_encode(['success' => false, 'message' => 'Book is not available for borrowing']);
            }
            
        } elseif ($action === 'reserve') {
            // Start transaction
            $conn->begin_transaction();
            
            // Check if book is borrowed or reserved
            $check_query = "SELECT bookStatus FROM book WHERE bookID = ? AND bookStatus IN ('borrowed', 'reserved')";
            $stmt = $conn->prepare($check_query);
            $stmt->bind_param("i", $book_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                // Check if user already has a reservation for this book
                $existing_query = "SELECT reservationID FROM reservation 
                                  WHERE userID = ? AND bookID = ? AND reservation_status IN ('waiting','ready')";
                $stmt = $conn->prepare($existing_query);
                $stmt->bind_param("ii", $user_id, $book_id);
                $stmt->execute();
                
                if ($stmt->get_result()->num_rows > 0) {
                    $conn->rollback();
                    echo json_encode(['success' => false, 'message' => 'You already have a reservation for this book']);
                    exit;
                }
                
                // Check reservation limit
                $reservation_limit_query = "SELECT COUNT(*) as reserve_count FROM reservation 
                                           WHERE userID = ? AND reservation_status IN ('waiting','ready')";
                $stmt = $conn->prepare($reservation_limit_query);
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $reserve_result = $stmt->get_result();
                $reserve_count = $reserve_result->fetch_assoc()['reserve_count'];
                
                // Get reservation limit from rules
                $rules_query = "SELECT reservation_limit FROM borrowing_rules WHERE user_type = 'student'";
                $rules_result = $conn->query($rules_query);
                $rules = $rules_result->fetch_assoc();
                $reservation_limit = $rules['reservation_limit'] ?? 2;
                
                if ($reserve_count >= $reservation_limit) {
                    $conn->rollback();
                    echo json_encode(['success' => false, 'message' => 'You have reached your reservation limit of ' . $reservation_limit . ' books']);
                    exit;
                }
                
                // Get queue position
                $queue_query = "SELECT COALESCE(MAX(queue_position), 0) + 1 as next_position 
                               FROM reservation 
                               WHERE bookID = ? AND reservation_status IN ('waiting','ready')";
                $stmt = $conn->prepare($queue_query);
                $stmt->bind_param("i", $book_id);
                $stmt->execute();
                $queue_result = $stmt->get_result();
                $queue_position = $queue_result->fetch_assoc()['next_position'];
                
                // Insert reservation
                $expiry_date = date('Y-m-d H:i:s', strtotime('+30 days'));
                $insert_query = "INSERT INTO reservation (userID, bookID, reservation_date, expiry_date, queue_position, reservation_status) 
                                VALUES (?, ?, NOW(), ?, ?, 'waiting')";
                $stmt = $conn->prepare($insert_query);
                $stmt->bind_param("iisi", $user_id, $book_id, $expiry_date, $queue_position);
                
                if (!$stmt->execute()) {
                    throw new Exception("Failed to create reservation");
                }
                
                // Create notification
                $notification_query = "INSERT INTO notifications (userID, notification_type, title, message, priority) 
                                      VALUES (?, 'reservation_ready', 'Reservation Confirmed', ?, 'medium')";
                $notification_message = "You have successfully reserved a book. You are position #{$queue_position} in the queue. We'll notify you when it's ready for pickup.";
                $stmt = $conn->prepare($notification_query);
                $stmt->bind_param("is", $user_id, $notification_message);
                $stmt->execute();
                
                // Commit transaction
                $conn->commit();
                
                echo json_encode([
                    'success' => true, 
                    'message' => "Book reserved successfully! You are position #{$queue_position} in the queue.", 
                    'redirect' => 'student_borrowing_reservations.php'
                ]);
            } else {
                $conn->rollback();
                echo json_encode(['success' => false, 'message' => 'Book is available for borrowing, no need to reserve']);
            }
        }
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Book action error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Operation failed: ' . $e->getMessage()]);
    }
    exit;
}

// Get book details
try {
    $book_query = "
        SELECT 
            b.*,
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
        WHERE b.bookID = ? AND b.bookStatus != 'disposed'
    ";
    
    $stmt = $conn->prepare($book_query);
    $stmt->bind_param("i", $book_id);
    $stmt->execute();
    $book_result = $stmt->get_result();
    
    if ($book_result->num_rows === 0) {
        header('Location: student_search_books.php');
        exit;
    }
    
    $book = $book_result->fetch_assoc();
    
    // Get student info
    $stmt = $conn->prepare("SELECT studentClass FROM student WHERE userID = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $student_class = $stmt->get_result()->fetch_assoc()['studentClass'] ?? '';
    
    // Get related books (same category)
    $related_query = "
        SELECT bookID, bookTitle, bookAuthor, book_image, book_image_mime, bookStatus
        FROM book 
        WHERE categoryID = ? AND bookID != ? AND bookStatus != 'disposed'
        ORDER BY RAND() 
        LIMIT 4
    ";
    $stmt = $conn->prepare($related_query);
    $stmt->bind_param("ii", $book['categoryID'], $book_id);
    $stmt->execute();
    $related_books = $stmt->get_result();
    
    // Get borrowing history count for this book
    $history_query = "SELECT COUNT(*) as borrow_count FROM borrow WHERE bookID = ?";
    $stmt = $conn->prepare($history_query);
    $stmt->bind_param("i", $book_id);
    $stmt->execute();
    $history_result = $stmt->get_result();
    $borrow_count = $history_result->fetch_assoc()['borrow_count'];
    
    // Get reservation queue if book is borrowed
    $queue_position = 0;
    if ($book['bookStatus'] === 'borrowed' || $book['bookStatus'] === 'reserved') {
        $queue_query = "SELECT COUNT(*) as position FROM reservation WHERE bookID = ? AND reservation_status IN ('waiting','ready')";
        $stmt = $conn->prepare($queue_query);
        $stmt->bind_param("i", $book_id);
        $stmt->execute();
        $queue_result = $stmt->get_result();
        $queue_position = $queue_result->fetch_assoc()['position'];
    }
    
    // Get notifications count
    $notifications_query = "SELECT COUNT(*) as total FROM notifications WHERE userID = ? AND read_status = 0";
    $stmt = $conn->prepare($notifications_query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $unread_count = $stmt->get_result()->fetch_assoc()['total'];
    
} catch (Exception $e) {
    error_log("Book detail error: " . $e->getMessage());
    header('Location: student_search_books.php');
    exit;
}

// Helper function for book image
function getBookImage($img, $mime) {
    if (empty($img)) {
        return 'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="400" height="600"%3E%3Crect fill="%23F7F4EF" width="400" height="600"/%3E%3Ctext x="50%25" y="50%25" text-anchor="middle" fill="%23C4B5A0" font-size="48" font-family="Inter"%3ENo Cover%3C/text%3E%3C/svg%3E';
    }
    return 'data:' . ($mime ?: 'image/jpeg') . ';base64,' . base64_encode($img);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($book['bookTitle']); ?> - SMK Chendering Library</title>
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
            --accent-gold: #F1C40F;
            
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

        /* SIDEBAR - Same as other pages */
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

        .header-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .back-btn {
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
            text-decoration: none;
        }

        .back-btn:hover {
            background: var(--accent-secondary);
            color: white;
            transform: translateX(-4px);
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
        }

        /* BOOK DETAIL LAYOUT */
        .book-detail-grid {
            display: grid;
            grid-template-columns: 400px 1fr;
            gap: 40px;
            margin-bottom: 40px;
        }

        /* BOOK IMAGE SECTION */
        .book-image-section {
            position: sticky;
            top: 120px;
            height: fit-content;
        }

        .book-image-container {
            background: var(--bg-card);
            border-radius: 20px;
            padding: 32px;
            box-shadow: var(--shadow-3d);
            border: 1px solid var(--border);
        }

        .book-cover-wrapper {
            position: relative;
            width: 100%;
            aspect-ratio: 2/3;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 
                0 10px 20px rgba(0, 0, 0, 0.15),
                0 6px 6px rgba(0, 0, 0, 0.1),
                inset 0 -2px 5px rgba(0, 0, 0, 0.1);
            margin-bottom: 24px;
        }

        .book-cover-wrapper img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s;
        }

        .book-cover-wrapper:hover img {
            transform: scale(1.05);
        }

        .book-placeholder {
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, var(--bg-secondary), var(--border));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 80px;
            color: var(--text-muted);
        }

        .book-rating {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px;
            background: var(--bg-secondary);
            border-radius: 12px;
        }

        .stars {
            display: flex;
            gap: 4px;
        }

        .star {
            color: var(--text-muted);
            font-size: 18px;
        }

        .star.filled {
            color: var(--accent-gold);
        }

        .rating-text {
            font-size: 14px;
            color: var(--text-secondary);
            font-weight: 600;
        }

        /* BOOK INFO SECTION */
        .book-info-section {
            background: var(--bg-card);
            border-radius: 20px;
            padding: 40px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--border);
        }

        .book-title {
            font-family: 'Playfair Display', serif;
            font-size: 36px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 16px;
            line-height: 1.3;
        }

        .book-author {
            font-size: 20px;
            color: var(--accent-secondary);
            margin-bottom: 24px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
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

        .status-reserved {
            background: rgba(243, 156, 18, 0.1);
            color: var(--accent-warning);
            border: 2px solid var(--accent-warning);
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
            border: 1px solid var(--border);
        }

        .detail-label {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .detail-value {
            font-size: 15px;
            font-weight: 600;
            color: var(--text-primary);
        }

        .book-description {
            background: var(--bg-secondary);
            padding: 24px;
            border-radius: 12px;
            margin-bottom: 32px;
            border: 1px solid var(--border);
        }

        .description-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .description-text {
            font-size: 15px;
            line-height: 1.8;
            color: var(--text-secondary);
        }

        .action-buttons {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
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
            box-shadow: var(--shadow-sm);
        }

        .btn-primary:hover:not(:disabled) {
            background: #C0392B;
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .btn-primary:disabled {
            background: var(--text-muted);
            cursor: not-allowed;
            transform: none;
            opacity: 0.6;
        }

        .btn-secondary {
            background: var(--bg-card);
            color: var(--accent-secondary);
            border: 2px solid var(--accent-secondary);
        }

        .btn-secondary:hover:not(:disabled) {
            background: var(--accent-secondary);
            color: white;
        }

        .btn-secondary:disabled {
            background: var(--bg-secondary);
            color: var(--text-muted);
            border-color: var(--text-muted);
            cursor: not-allowed;
            opacity: 0.6;
        }

        /* RELATED BOOKS */
        .related-books-section {
            background: var(--bg-card);
            border-radius: 20px;
            padding: 40px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--border);
        }

        .section-title {
            font-family: 'Playfair Display', serif;
            font-size: 28px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 32px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .section-title i {
            color: var(--accent-primary);
        }

        .related-books-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 24px;
        }

        .related-book-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 16px;
            transition: all 0.3s;
            cursor: pointer;
            box-shadow: var(--shadow-sm);
        }

        .related-book-card:hover {
            transform: translateY(-8px);
            box-shadow: var(--shadow-lg);
            border-color: var(--accent-primary);
        }

        .related-book-cover {
            width: 100%;
            aspect-ratio: 2/3;
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 12px;
            box-shadow: var(--shadow-sm);
        }

        .related-book-cover img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .related-placeholder {
            width: 100%;
            height: 100%;
            background: var(--bg-secondary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
            color: var(--text-muted);
        }

        .related-book-title {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 6px;
            overflow: hidden;
            text-overflow: ellipsis;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            line-height: 1.4;
        }

        .related-book-author {
            font-size: 13px;
            color: var(--text-secondary);
        }

        /* ALERT MESSAGES */
        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            display: flex;
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

        .loading-text {
            font-size: 16px;
            font-weight: 600;
            color: var(--text-primary);
        }

        /* RESPONSIVE */
        @media (max-width: 1400px) {
            .related-books-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media (max-width: 1024px) {
            .book-detail-grid {
                grid-template-columns: 1fr;
            }
            
            .book-image-section {
                position: static;
                max-width: 400px;
                margin: 0 auto;
            }
            
            .related-books-grid {
                grid-template-columns: repeat(2, 1fr);
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
            
            .book-info-section,
            .related-books-section {
                padding: 24px 20px;
            }
            
            .book-title {
                font-size: 28px;
            }
            
            .book-details-grid {
                grid-template-columns: 1fr;
            }
            
            .related-books-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 16px;
            }
            
            .user-info {
                display: none;
            }
        }

        @media (max-width: 480px) {
            .related-books-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

<!-- Loading Overlay -->
<div class="loading-overlay" id="loadingOverlay">
    <div class="loading-content">
        <i class="fas fa-spinner loading-spinner"></i>
        <div class="loading-text">Processing your request...</div>
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
        <a href="student_dashboard.php" class="nav-item active">
            <i class="fas fa-home"></i>
            <span>Dashboard</span>
        </a>
        <a href="student_search_books.php" class="nav-item">
            <i class="fas fa-book"></i>
            <span>Browse Books</span>
        </a>
        <a href="student_borrowing_reservations.php" class="nav-item">
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
        <div class="header-left">
            <a href="student_search_books.php" class="back-btn">
                <i class="fas fa-arrow-left"></i>
            </a>
            <h1 class="page-title">Book Details</h1>
        </div>
        
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
        <!-- Alert Messages Container -->
        <div id="alertContainer"></div>
        
        <!-- Book Detail Grid -->
        <div class="book-detail-grid">
            <!-- Book Image Section -->
            <div class="book-image-section">
                <div class="book-image-container">
                    <div class="book-cover-wrapper">
                        <?php if ($book['book_image'] && $book['book_image_mime']): ?>
                            <img src="<?php echo getBookImage($book['book_image'], $book['book_image_mime']); ?>" 
                                 alt="<?php echo htmlspecialchars($book['bookTitle']); ?>">
                        <?php else: ?>
                            <div class="book-placeholder">
                                <i class="fas fa-book"></i>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="book-rating">
                        <div class="stars">
                            <i class="far fa-star star"></i>
                            <i class="far fa-star star"></i>
                            <i class="far fa-star star"></i>
                            <i class="far fa-star star"></i>
                            <i class="far fa-star star"></i>
                        </div>
                        <span class="rating-text">No reviews yet</span>
                    </div>
                </div>
            </div>
            
            <!-- Book Information Section -->
            <div class="book-info-section">
                <h1 class="book-title"><?php echo htmlspecialchars($book['bookTitle']); ?></h1>
                
                <div class="book-author">
                    <i class="fas fa-user-edit"></i>
                    <?php echo htmlspecialchars($book['bookAuthor'] ?: 'Unknown Author'); ?>
                </div>
                
                <div class="status-badge status-<?php echo strtolower($book['bookStatus']); ?>">
                    <i class="fas fa-circle"></i>
                    <?php echo $book['status_display']; ?>
                    <?php if (($book['bookStatus'] === 'borrowed' || $book['bookStatus'] === 'reserved') && $queue_position > 0): ?>
                        (<?php echo $queue_position; ?> in queue)
                    <?php endif; ?>
                </div>
                
                <div class="book-details-grid">
                    <?php if ($book['book_ISBN']): ?>
                    <div class="detail-item">
                        <div class="detail-label">
                            <i class="fas fa-barcode"></i>
                            ISBN
                        </div>
                        <div class="detail-value"><?php echo htmlspecialchars($book['book_ISBN']); ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($book['categoryName']): ?>
                    <div class="detail-item">
                        <div class="detail-label">
                            <i class="fas fa-tags"></i>
                            Category
                        </div>
                        <div class="detail-value"><?php echo htmlspecialchars($book['categoryName']); ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($book['bookPublisher']): ?>
                    <div class="detail-item">
                        <div class="detail-label">
                            <i class="fas fa-building"></i>
                            Publisher
                        </div>
                        <div class="detail-value"><?php echo htmlspecialchars($book['bookPublisher']); ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($book['publication_year']): ?>
                    <div class="detail-item">
                        <div class="detail-label">
                            <i class="fas fa-calendar-alt"></i>
                            Year
                        </div>
                        <div class="detail-value"><?php echo $book['publication_year']; ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($book['language']): ?>
                    <div class="detail-item">
                        <div class="detail-label">
                            <i class="fas fa-globe"></i>
                            Language
                        </div>
                        <div class="detail-value"><?php echo htmlspecialchars($book['language']); ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($book['number_of_pages']): ?>
                    <div class="detail-item">
                        <div class="detail-label">
                            <i class="fas fa-file-alt"></i>
                            Pages
                        </div>
                        <div class="detail-value"><?php echo $book['number_of_pages']; ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($book['shelf_location']): ?>
                    <div class="detail-item">
                        <div class="detail-label">
                            <i class="fas fa-map-marker-alt"></i>
                            Location
                        </div>
                        <div class="detail-value"><?php echo htmlspecialchars($book['shelf_location']); ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="detail-item">
                        <div class="detail-label">
                            <i class="fas fa-chart-bar"></i>
                            Times Borrowed
                        </div>
                        <div class="detail-value"><?php echo $borrow_count; ?></div>
                    </div>
                </div>
                
                <?php if ($book['book_description']): ?>
                <div class="book-description">
                    <div class="description-title">
                        <i class="fas fa-align-left"></i>
                        Description
                    </div>
                    <div class="description-text">
                        <?php echo nl2br(htmlspecialchars($book['book_description'])); ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="action-buttons">
                    <?php if ($book['bookStatus'] === 'available'): ?>
                        <button class="btn btn-primary" onclick="borrowBook(<?php echo $book_id; ?>)">
                            <i class="fas fa-book-open"></i>
                            Borrow This Book
                        </button>
                        <button class="btn btn-secondary" disabled>
                            <i class="fas fa-bookmark"></i>
                            Reserve (Not Needed)
                        </button>
                    <?php elseif ($book['bookStatus'] === 'borrowed' || $book['bookStatus'] === 'reserved'): ?>
                        <button class="btn btn-primary" disabled>
                            <i class="fas fa-book-open"></i>
                            Currently Borrowed
                        </button>
                        <button class="btn btn-secondary" onclick="reserveBook(<?php echo $book_id; ?>)">
                            <i class="fas fa-bookmark"></i>
                            Reserve This Book
                        </button>
                    <?php else: ?>
                        <button class="btn btn-primary" disabled>
                            <i class="fas fa-book-open"></i>
                            Unavailable
                        </button>
                        <button class="btn btn-secondary" disabled>
                            <i class="fas fa-bookmark"></i>
                            Cannot Reserve
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Related Books Section -->
        <?php if ($related_books && $related_books->num_rows > 0): ?>
        <div class="related-books-section">
            <h2 class="section-title">
                <i class="fas fa-layer-group"></i>
                Related Books
            </h2>
            <div class="related-books-grid">
                <?php while ($related = $related_books->fetch_assoc()): ?>
                <div class="related-book-card" onclick="goToBook(<?php echo $related['bookID']; ?>)">
                    <div class="related-book-cover">
                        <?php if ($related['book_image'] && $related['book_image_mime']): ?>
                            <img src="data:<?php echo $related['book_image_mime']; ?>;base64,<?php echo base64_encode($related['book_image']); ?>" 
                                 alt="<?php echo htmlspecialchars($related['bookTitle']); ?>">
                        <?php else: ?>
                            <div class="related-placeholder">
                                <i class="fas fa-book"></i>
                            </div>
                        <?php endif; ?>
                    </div>
                    <h3 class="related-book-title"><?php echo htmlspecialchars($related['bookTitle']); ?></h3>
                    <div class="related-book-author"><?php echo htmlspecialchars($related['bookAuthor'] ?: 'Unknown Author'); ?></div>
                </div>
                <?php endwhile; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
    // Show loading overlay
    function showLoading() {
        document.getElementById('loadingOverlay').classList.add('show');
    }
    
    // Hide loading overlay
    function hideLoading() {
        document.getElementById('loadingOverlay').classList.remove('show');
    }
    
    // Show alert message
    function showAlert(message, type = 'success') {
        const alertContainer = document.getElementById('alertContainer');
        const alertId = 'alert-' + Date.now();
        
        const alertClass = type === 'success' ? 'alert-success' : 'alert-error';
        const icon = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';
        
        const alertHtml = `
            <div class="alert ${alertClass}" id="${alertId}">
                <i class="fas ${icon}"></i>
                <span>${message}</span>
            </div>
        `;
        
        alertContainer.insertAdjacentHTML('beforeend', alertHtml);
        
        // Scroll to top to see alert
        window.scrollTo({ top: 0, behavior: 'smooth' });
        
        // Auto remove after 5 seconds
        setTimeout(() => {
            const alertElement = document.getElementById(alertId);
            if (alertElement) {
                alertElement.style.opacity = '0';
                alertElement.style.transform = 'translateY(-20px)';
                setTimeout(() => alertElement.remove(), 300);
            }
        }, 5000);
    }
    
    // Borrow book function
    async function borrowBook(bookId) {
        if (!confirm('Are you sure you want to borrow this book?')) {
            return;
        }
        
        showLoading();
        
        try {
            const formData = new FormData();
            formData.append('action', 'borrow');
            formData.append('book_id', bookId);
            
            const response = await fetch(window.location.href, {
                method: 'POST',
                body: formData
            });
            
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            
            const data = await response.json();
            
            hideLoading();
            
            if (data.success) {
                showAlert(data.message, 'success');
                // Wait for alert animation, then redirect
                setTimeout(() => {
                    window.location.href = data.redirect || 'student_my_borrowed_books.php';
                }, 1500);
            } else {
                showAlert(data.message, 'error');
            }
        } catch (error) {
            hideLoading();
            console.error('Error:', error);
            showAlert('Failed to borrow book. Please try again.', 'error');
        }
    }
    
    // Reserve book function
    async function reserveBook(bookId) {
        if (!confirm('Are you sure you want to reserve this book? You will be notified when it becomes available.')) {
            return;
        }
        
        showLoading();
        
        try {
            const formData = new FormData();
            formData.append('action', 'reserve');
            formData.append('book_id', bookId);
            
            const response = await fetch(window.location.href, {
                method: 'POST',
                body: formData
            });
            
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            
            const data = await response.json();
            
            hideLoading();
            
            if (data.success) {
                showAlert(data.message, 'success');
                // Wait for alert animation, then redirect
                setTimeout(() => {
                    window.location.href = data.redirect || 'student_borrowing_reservations.php';
                }, 1500);
            } else {
                showAlert(data.message, 'error');
            }
        } catch (error) {
            hideLoading();
            console.error('Error:', error);
            showAlert('Failed to reserve book. Please try again.', 'error');
        }
    }
    
    // Navigate to book
    function goToBook(bookId) {
        window.location.href = `student_book_detail.php?id=${bookId}`;
    }
    
    // Initialize page
    document.addEventListener('DOMContentLoaded', function() {
        // Add entrance animations
        const detailItems = document.querySelectorAll('.detail-item');
        detailItems.forEach((item, index) => {
            item.style.opacity = '0';
            item.style.transform = 'translateY(20px)';
            setTimeout(() => {
                item.style.transition = 'all 0.4s ease';
                item.style.opacity = '1';
                item.style.transform = 'translateY(0)';
            }, index * 100);
        });
        
        // Animate related books
        const relatedCards = document.querySelectorAll('.related-book-card');
        relatedCards.forEach((card, index) => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(30px)';
            setTimeout(() => {
                card.style.transition = 'all 0.5s ease';
                card.style.opacity = '1';
                card.style.transform = 'translateY(0)';
            }, (index + 4) * 150);
        });
    });
    
    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Escape key to go back
        if (e.key === 'Escape') {
            window.location.href = 'student_search_books.php';
        }
    });
    
    // Console branding
    console.log('%c📚 SMK Chendering Library - Book Details', 'color: #E74C3C; font-size: 20px; font-weight: bold; padding: 10px;');
    console.log('%cBook Detail Page v1.1 - Fixed', 'color: #27AE60; font-size: 14px; padding: 5px;');
</script>

</body>
</html>