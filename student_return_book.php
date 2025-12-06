<?php
/**
 * SMK Chendering Library - Student Return Book Page
 * Modern Interface for Self-Service Book Returns
 * Version 1.0
 */

session_start();
require_once 'dbconnect.php';
require_once 'auth_helper.php';

// Authentication - Check if user is logged in and is a student
checkPageAccess();
requireRole('student');

$student_name = getUserDisplayName();
$student_id = $_SESSION['student_id'] ?? 0;
$user_id = $_SESSION['user_id'] ?? 0;

// Helper Functions
function getBookImage($img, $mime) {
    if (empty($img)) {
        return 'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="200" height="280"%3E%3Crect fill="%23F7F4EF" width="200" height="280"/%3E%3Ctext x="50%25" y="50%25" text-anchor="middle" fill="%23C4B5A0" font-size="16" font-family="Inter"%3ENo Cover%3C/text%3E%3C/svg%3E';
    }
    if (is_string($img) && !preg_match('/[^\x20-\x7E]/', $img)) return htmlspecialchars($img);
    return 'data:' . ($mime ?: 'image/jpeg') . ';base64,' . base64_encode($img);
}

// Check for outstanding fines and overdue books
$has_restrictions = false;
$restriction_message = '';

try {
    // Check for unpaid fines
    $fines_query = "
        SELECT 
            COALESCE(
                (SELECT SUM(balance_due) 
                 FROM fines 
                 WHERE userID = ? 
                 AND payment_status IN ('unpaid', 'partial_paid')
                ), 0
            ) + 
            COALESCE(
                (SELECT SUM(fine_amount) 
                 FROM borrow 
                 WHERE userID = ? 
                 AND borrow_status IN ('borrowed', 'overdue') 
                 AND fine_amount > 0
                 AND borrowID NOT IN (
                     SELECT borrowID FROM fines WHERE payment_status IN ('unpaid', 'partial_paid')
                 )
                ), 0
            ) as total_fines
    ";
    $stmt = $conn->prepare($fines_query);
    $stmt->bind_param("ii", $user_id, $user_id);
    $stmt->execute();
    $fines_result = $stmt->get_result()->fetch_assoc();
    $total_fines = $fines_result['total_fines'];
    
    // Check for overdue books
    $stmt = $conn->prepare("
        SELECT COUNT(*) as overdue_count 
        FROM borrow 
        WHERE userID = ? 
        AND borrow_status = 'borrowed' 
        AND due_date < CURDATE()
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $overdue_result = $stmt->get_result()->fetch_assoc();
    $overdue_count = $overdue_result['overdue_count'];
    
    // Set restriction if fines or overdue books exist
    if ($total_fines > 0 || $overdue_count > 0) {
        $has_restrictions = true;
        $restrictions = [];
        if ($total_fines > 0) {
            $restrictions[] = "outstanding fines of RM " . number_format($total_fines, 2);
        }
        if ($overdue_count > 0) {
            $restrictions[] = "$overdue_count overdue " . ($overdue_count == 1 ? 'book' : 'books');
        }
        $restriction_message = "You have " . implode(" and ", $restrictions) . ".";
    }
    
    // Get borrowed books with detailed information
    $stmt = $conn->prepare("
        SELECT b.*, bc.categoryName, br.due_date, br.borrowID, br.borrow_date,
               br.borrow_status, br.fine_amount,
               DATEDIFF(CURDATE(), br.due_date) as days_overdue,
               DATEDIFF(br.due_date, CURDATE()) as days_left
        FROM borrow br
        JOIN book b ON br.bookID = b.bookID
        JOIN book_category bc ON b.categoryID = bc.categoryID
        WHERE br.userID = ? 
        AND br.borrow_status = 'borrowed'
        ORDER BY br.due_date ASC
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $borrowed_books = $stmt->get_result();
    
    // Get statistics
    $stmt = $conn->prepare("SELECT COUNT(*) as c FROM borrow WHERE userID = ? AND borrow_status = 'borrowed'");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $borrowed_count = $stmt->get_result()->fetch_assoc()['c'];
    
    // Unread Notifications Count
    $stmt = $conn->prepare("SELECT COUNT(*) as c FROM notifications WHERE userID = ? AND read_status = 0");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $unread_count = $stmt->get_result()->fetch_assoc()['c'];
    
    // Student Info
    $stmt = $conn->prepare("SELECT studentClass FROM student WHERE userID = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $student_info = $stmt->get_result()->fetch_assoc();
    $student_class = $student_info['studentClass'] ?? '';
    
} catch (Exception $e) {
    error_log("Return Book Page Error: " . $e->getMessage());
    $borrowed_count = 0;
    $total_fines = 0;
    $overdue_count = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Return Books - SMK Chendering Library</title>
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

        /* MAIN CONTENT */
        .main-wrapper {
            margin-left: 280px;
            min-height: 100vh;
        }

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

        .header-title {
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
        }

        .back-btn:hover {
            background: var(--accent-secondary);
            color: white;
            transform: translateX(-2px);
        }

        .header-title h1 {
            font-size: 24px;
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

        /* CONTENT AREA */
        .content-area {
            padding: 40px;
        }

        /* ALERT BOX */
        .alert-box {
            background: linear-gradient(135deg, #FFF5F5 0%, #FFE8E8 100%);
            border: 2px solid var(--accent-primary);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 32px;
            display: flex;
            align-items: flex-start;
            gap: 20px;
            box-shadow: var(--shadow-md);
            animation: slideDown 0.5s ease;
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

        .alert-icon {
            width: 56px;
            height: 56px;
            background: var(--accent-primary);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            color: white;
            font-size: 24px;
        }

        .alert-content {
            flex: 1;
        }

        .alert-content h3 {
            font-size: 20px;
            font-weight: 700;
            color: var(--accent-primary);
            margin-bottom: 8px;
        }

        .alert-content p {
            font-size: 15px;
            color: var(--text-primary);
            line-height: 1.6;
            margin-bottom: 16px;
        }

        .alert-actions {
            display: flex;
            gap: 12px;
        }

        .btn-primary {
            padding: 12px 24px;
            background: var(--accent-primary);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary:hover {
            background: #C0392B;
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .btn-secondary {
            padding: 12px 24px;
            background: var(--bg-card);
            color: var(--text-primary);
            border: 2px solid var(--border);
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-secondary:hover {
            background: var(--bg-secondary);
            border-color: var(--text-secondary);
        }

        /* SUCCESS BOX */
        .success-box {
            background: linear-gradient(135deg, #F0FFF4 0%, #E6F9EC 100%);
            border: 2px solid var(--accent-success);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 32px;
            display: flex;
            align-items: flex-start;
            gap: 20px;
            box-shadow: var(--shadow-md);
        }

        .success-box .alert-icon {
            background: var(--accent-success);
        }

        .success-box h3 {
            color: var(--accent-success) !important;
        }

        /* STATS BAR */
        .stats-bar {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 32px;
            display: flex;
            justify-content: space-around;
            gap: 24px;
            box-shadow: var(--shadow-sm);
        }

        .stat-item {
            text-align: center;
            flex: 1;
            padding: 16px;
            border-radius: 12px;
            transition: all 0.3s;
        }

        .stat-item:hover {
            background: var(--bg-secondary);
            transform: translateY(-4px);
        }

        .stat-value {
            font-size: 32px;
            font-weight: 800;
            color: var(--text-primary);
            margin-bottom: 8px;
        }

        .stat-label {
            font-size: 14px;
            color: var(--text-secondary);
            font-weight: 500;
        }

        .stat-item:nth-child(1) .stat-value {
            color: var(--accent-secondary);
        }

        .stat-item:nth-child(2) .stat-value {
            color: var(--accent-warning);
        }

        .stat-item:nth-child(3) .stat-value {
            color: var(--accent-primary);
        }

        /* SECTION */
        .section {
            margin-bottom: 48px;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }

        .section-title {
            font-family: 'Playfair Display', serif;
            font-size: 28px;
            font-weight: 700;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .section-title i {
            font-size: 24px;
            color: var(--accent-primary);
        }

        /* BOOKS GRID */
        .books-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 24px;
        }

        .book-return-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 24px;
            display: flex;
            gap: 20px;
            box-shadow: var(--shadow-sm);
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
        }

        .book-return-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-md);
        }

        .book-return-card.disabled {
            opacity: 0.6;
            pointer-events: none;
        }

        .book-cover {
            width: 100px;
            height: 140px;
            border-radius: 10px;
            overflow: hidden;
            flex-shrink: 0;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border);
            position: relative;
        }

        .book-cover img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .overdue-badge {
            position: absolute;
            top: 8px;
            right: 8px;
            background: var(--accent-primary);
            color: white;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 10px;
            font-weight: 700;
        }

        .book-details {
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .book-details h3 {
            font-size: 16px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 6px;
            line-height: 1.4;
        }

        .book-details .author {
            font-size: 14px;
            color: var(--text-secondary);
            margin-bottom: 12px;
        }

        .book-meta {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 16px;
        }

        .meta-row {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: var(--text-muted);
        }

        .meta-row i {
            width: 16px;
            color: var(--accent-secondary);
        }

        .meta-row.overdue {
            color: var(--accent-primary);
            font-weight: 600;
        }

        .meta-row.overdue i {
            color: var(--accent-primary);
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            margin-top: auto;
            width: fit-content;
        }

        .status-badge.safe {
            background: rgba(39, 174, 96, 0.1);
            color: var(--accent-success);
        }

        .status-badge.warning {
            background: rgba(243, 156, 18, 0.1);
            color: var(--accent-warning);
        }

        .status-badge.danger {
            background: rgba(231, 76, 60, 0.1);
            color: var(--accent-primary);
        }

        .book-actions {
            display: flex;
            gap: 8px;
            margin-top: 16px;
        }

        .btn-return {
            flex: 1;
            padding: 12px 20px;
            background: var(--accent-success);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-return:hover {
            background: #229954;
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .btn-return:disabled {
            background: var(--text-muted);
            cursor: not-allowed;
            transform: none;
        }

        .btn-view {
            padding: 12px 20px;
            background: var(--bg-secondary);
            color: var(--text-primary);
            border: 1px solid var(--border);
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-view:hover {
            background: var(--accent-secondary);
            color: white;
            border-color: var(--accent-secondary);
        }

        /* EMPTY STATE */
        .empty-state {
            text-align: center;
            padding: 80px 20px;
            background: var(--bg-card);
            border: 2px dashed var(--border);
            border-radius: 16px;
        }

        .empty-state i {
            font-size: 64px;
            color: var(--text-muted);
            opacity: 0.5;
            margin-bottom: 24px;
        }

        .empty-state h3 {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 12px;
        }

        .empty-state p {
            font-size: 16px;
            color: var(--text-secondary);
            margin-bottom: 24px;
        }

        /* MODAL */
        .modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(44, 62, 80, 0.6);
            backdrop-filter: blur(4px);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal.show {
            display: flex;
        }

        .modal-content {
            background: var(--bg-card);
            border-radius: 20px;
            padding: 40px;
            max-width: 500px;
            width: 100%;
            box-shadow: var(--shadow-lg);
            animation: modalSlideUp 0.3s ease;
        }

        @keyframes modalSlideUp {
            from {
                opacity: 0;
                transform: translateY(40px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, var(--accent-success), #27AE60);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
            color: white;
            font-size: 36px;
        }

        .modal-content h2 {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-primary);
            text-align: center;
            margin-bottom: 12px;
        }

        .modal-content p {
            font-size: 15px;
            color: var(--text-secondary);
            text-align: center;
            margin-bottom: 24px;
            line-height: 1.6;
        }

        .book-info-modal {
            background: var(--bg-secondary);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
        }

        .book-info-modal h4 {
            font-size: 16px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 4px;
        }

        .book-info-modal p {
            font-size: 14px;
            color: var(--text-secondary);
            text-align: left;
            margin: 0;
        }

        .modal-actions {
            display: flex;
            gap: 12px;
        }

        .modal-actions button {
            flex: 1;
        }

        .btn-cancel {
            padding: 14px 24px;
            background: var(--bg-secondary);
            color: var(--text-primary);
            border: 1px solid var(--border);
            border-radius: 12px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-cancel:hover {
            background: var(--border);
        }

        .btn-confirm {
            padding: 14px 24px;
            background: var(--accent-success);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-confirm:hover {
            background: #229954;
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        /* RESPONSIVE */
        @media (max-width: 1024px) {
            .books-grid {
                grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
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
            
            .header-title h1 {
                font-size: 20px;
            }
            
            .stats-bar {
                flex-direction: column;
                gap: 16px;
            }
            
            .books-grid {
                grid-template-columns: 1fr;
            }
            
            .book-return-card {
                flex-direction: column;
            }
            
            .book-cover {
                width: 100%;
                height: 200px;
            }
            
            .modal-content {
                padding: 24px;
            }
            
            .alert-box {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>

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
        <a href="student_borrowing_reservations.php" class="nav-item">
            <i class="fas fa-barcode"></i>
            <span>Scan Barcode</span>
        </a>
        <a href="student_my_borrowed_books.php" class="nav-item">
            <i class="fas fa-bookmark"></i>
            <span>My Books</span>
        </a>
        <a href="student_return_book.php" class="nav-item active ">
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
        <div class="header-title">
            <button class="back-btn" onclick="window.history.back()" title="Go Back">
                <i class="fas fa-arrow-left"></i>
            </button>
            <h1><i class="fas fa-undo"></i> Return Books</h1>
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
        </div>
    </div>
    
    <!-- Content Area -->
    <div class="content-area">
        
        <?php if ($has_restrictions): ?>
        <!-- Restriction Alert -->
        <div class="alert-box">
            <div class="alert-icon">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <div class="alert-content">
                <h3>Self-Service Return Not Available</h3>
                <p><strong><?php echo $restriction_message; ?></strong></p>
                <p>Please visit the library counter to settle your account before you can return books through self-service. Our librarians will assist you with:</p>
                <ul style="margin: 12px 0; padding-left: 24px; line-height: 1.8;">
                    <li>Processing your book returns</li>
                    <li>Settling outstanding fines</li>
                    <li>Resolving overdue book issues</li>
                </ul>
                <div class="alert-actions">
                    <button class="btn-primary" onclick="window.location.href='student_fines_penalties.php'">
                        <i class="fas fa-receipt"></i> View Fines
                    </button>
                    <button class="btn-secondary" onclick="window.location.href='student_my_borrowed_books.php'">
                        <i class="fas fa-book"></i> View My Books
                    </button>
                </div>
            </div>
        </div>
        <?php else: ?>
        <!-- Success Info Box -->
        <div class="success-box">
            <div class="alert-icon">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="alert-content">
                <h3>Self-Service Return Available</h3>
                <p>Great news! You can return your books using our self-service system. Simply select the book you'd like to return below and confirm your return.</p>
                <p style="margin: 0; font-size: 13px; color: var(--text-muted);">
                    <i class="fas fa-info-circle"></i> Make sure to return books on or before the due date to avoid fines.
                </p>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Statistics Bar -->
        <div class="stats-bar">
            <div class="stat-item">
                <div class="stat-value"><?php echo $borrowed_count; ?></div>
                <div class="stat-label">Books Borrowed</div>
            </div>
            <div class="stat-item">
                <div class="stat-value"><?php echo $overdue_count; ?></div>
                <div class="stat-label">Overdue Books</div>
            </div>
            <div class="stat-item">
                <div class="stat-value">RM <?php echo number_format($total_fines, 2); ?></div>
                <div class="stat-label">Outstanding Fines</div>
            </div>
        </div>
        
        <!-- Books Section -->
        <div class="section">
            <div class="section-header">
                <h2 class="section-title">
                    <i class="fas fa-book-open"></i>
                    Books Ready to Return
                </h2>
            </div>
            
            <?php if ($borrowed_books && $borrowed_books->num_rows > 0): ?>
            <div class="books-grid">
                <?php while ($book = $borrowed_books->fetch_assoc()): 
                    $days_left = $book['days_left'];
                    $is_overdue = $days_left < 0;
                    $days_overdue = $book['days_overdue'];
                    
                    // Determine status badge
                    if ($is_overdue) {
                        $badge_class = 'danger';
                        $badge_text = abs($days_overdue) . ' day' . (abs($days_overdue) != 1 ? 's' : '') . ' overdue';
                        $badge_icon = 'exclamation-circle';
                    } elseif ($days_left <= 3) {
                        $badge_class = 'warning';
                        $badge_text = $days_left == 0 ? 'Due today' : $days_left . ' day' . ($days_left != 1 ? 's' : '') . ' left';
                        $badge_icon = 'clock';
                    } else {
                        $badge_class = 'safe';
                        $badge_text = $days_left . ' day' . ($days_left != 1 ? 's' : '') . ' left';
                        $badge_icon = 'check-circle';
                    }
                ?>
                <div class="book-return-card <?php echo $has_restrictions ? 'disabled' : ''; ?>">
                    <div class="book-cover">
                        <img src="<?php echo getBookImage($book['book_image'], $book['book_image_mime']); ?>" 
                             alt="<?php echo htmlspecialchars($book['bookTitle']); ?>">
                        <?php if ($is_overdue): ?>
                        <span class="overdue-badge">OVERDUE</span>
                        <?php endif; ?>
                    </div>
                    
                    <div class="book-details">
                        <h3><?php echo htmlspecialchars($book['bookTitle']); ?></h3>
                        <p class="author">by <?php echo htmlspecialchars($book['bookAuthor']); ?></p>
                        
                        <div class="book-meta">
                            <div class="meta-row">
                                <i class="fas fa-tag"></i>
                                <span><?php echo htmlspecialchars($book['categoryName']); ?></span>
                            </div>
                            <div class="meta-row">
                                <i class="fas fa-calendar-plus"></i>
                                <span>Borrowed: <?php echo date('M d, Y', strtotime($book['borrow_date'])); ?></span>
                            </div>
                            <div class="meta-row <?php echo $is_overdue ? 'overdue' : ''; ?>">
                                <i class="fas fa-calendar-check"></i>
                                <span>Due: <?php echo date('M d, Y', strtotime($book['due_date'])); ?></span>
                            </div>
                            <?php if ($book['fine_amount'] > 0): ?>
                            <div class="meta-row overdue">
                                <i class="fas fa-exclamation-triangle"></i>
                                <span>Fine: RM <?php echo number_format($book['fine_amount'], 2); ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <span class="status-badge <?php echo $badge_class; ?>">
                            <i class="fas fa-<?php echo $badge_icon; ?>"></i>
                            <?php echo $badge_text; ?>
                        </span>
                        
                        <div class="book-actions">
                            <?php if (!$has_restrictions): ?>
                            <button class="btn-return" onclick="confirmReturn(<?php echo $book['borrowID']; ?>, '<?php echo htmlspecialchars($book['bookTitle'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($book['bookAuthor'], ENT_QUOTES); ?>')">
                                <i class="fas fa-check"></i>
                                Return This Book
                            </button>
                            <?php else: ?>
                            <button class="btn-return" disabled title="Please settle fines/overdue books first">
                                <i class="fas fa-ban"></i>
                                Cannot Return
                            </button>
                            <?php endif; ?>
                            <button class="btn-view" onclick="window.location.href='student_book_details.php?id=<?php echo $book['bookID']; ?>'">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>
            <?php else: ?>
            <!-- Empty State -->
            <div class="empty-state">
                <i class="fas fa-book"></i>
                <h3>No Borrowed Books</h3>
                <p>You don't have any books to return at the moment.</p>
                <button class="btn-primary" onclick="window.location.href='student_search_books.php'">
                    <i class="fas fa-search"></i>
                    Browse Books
                </button>
            </div>
            <?php endif; ?>
        </div>
        
    </div>
</div>

<!-- Return Confirmation Modal -->
<div class="modal" id="returnModal">
    <div class="modal-content">
        <div class="modal-icon">
            <i class="fas fa-undo"></i>
        </div>
        <h2>Confirm Book Return</h2>
        <p>Are you sure you want to return this book?</p>
        
        <div class="book-info-modal">
            <h4 id="modalBookTitle">Book Title</h4>
            <p id="modalBookAuthor">by Author Name</p>
        </div>
        
        <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 24px;">
            <i class="fas fa-info-circle"></i> Please make sure you have the physical book with you at the return station.
        </p>
        
        <div class="modal-actions">
            <button class="btn-cancel" onclick="closeModal()">
                <i class="fas fa-times"></i> Cancel
            </button>
            <button class="btn-confirm" id="confirmReturnBtn">
                <i class="fas fa-check"></i> Confirm Return
            </button>
        </div>
    </div>
</div>

<!-- Success Modal -->
<div class="modal" id="successModal">
    <div class="modal-content">
        <div class="modal-icon" style="background: linear-gradient(135deg, var(--accent-success), #229954);">
            <i class="fas fa-check"></i>
        </div>
        <h2>Book Returned Successfully!</h2>
        <p id="successMessage">Your book has been returned successfully.</p>
        
        <button class="btn-primary" onclick="location.reload()" style="width: 100%; justify-content: center;">
            <i class="fas fa-sync"></i> Refresh Page
        </button>
    </div>
</div>

<script>
    let currentBorrowId = null;
    
    // Confirm Return Function
    function confirmReturn(borrowId, bookTitle, bookAuthor) {
        currentBorrowId = borrowId;
        document.getElementById('modalBookTitle').textContent = bookTitle;
        document.getElementById('modalBookAuthor').textContent = 'by ' + bookAuthor;
        document.getElementById('returnModal').classList.add('show');
    }
    
    // Close Modal
    function closeModal() {
        document.getElementById('returnModal').classList.remove('show');
        document.getElementById('successModal').classList.remove('show');
        currentBorrowId = null;
    }
    
    // Handle Return Confirmation
    document.getElementById('confirmReturnBtn').addEventListener('click', function() {
        if (!currentBorrowId) return;
        
        const btn = this;
        const originalHTML = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
        
        // Send return request
        fetch('process_return.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'borrow_id=' + currentBorrowId
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('returnModal').classList.remove('show');
                document.getElementById('successMessage').textContent = data.message || 'Your book has been returned successfully.';
                document.getElementById('successModal').classList.add('show');
                
                // Auto-refresh after 3 seconds
                setTimeout(() => {
                    location.reload();
                }, 3000);
            } else {
                alert(data.message || 'Failed to return book. Please try again or contact the librarian.');
                btn.disabled = false;
                btn.innerHTML = originalHTML;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred while processing your return. Please try again.');
            btn.disabled = false;
            btn.innerHTML = originalHTML;
        });
    });
    
    // Close modal on background click
    document.getElementById('returnModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeModal();
        }
    });
    
    document.getElementById('successModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeModal();
        }
    });
    
    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeModal();
        }
    });
    
    // Mobile sidebar toggle
    if (window.innerWidth <= 768) {
        const sidebar = document.getElementById('sidebar');
        document.addEventListener('click', (e) => {
            if (!sidebar.contains(e.target) && sidebar.classList.contains('show')) {
                sidebar.classList.remove('show');
            }
        });
    }
    
    // Loading animation
    window.addEventListener('load', () => {
        const cards = document.querySelectorAll('.book-return-card');
        cards.forEach((card, index) => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';
            setTimeout(() => {
                card.style.transition = 'all 0.4s ease';
                card.style.opacity = '1';
                card.style.transform = 'translateY(0)';
            }, index * 100);
        });
    });
    
    // Console branding
    console.log('%c📚 SMK Chendering Library System', 'color: #E74C3C; font-size: 20px; font-weight: bold; padding: 10px;');
    console.log('%cReturn Book Page v1.0', 'color: #7F8C8D; font-size: 14px; padding: 5px;');
    console.log('%c© 2025 SMK Chendering', 'color: #95A5A6; font-size: 12px; padding: 5px;');
</script>

</body>
</html>