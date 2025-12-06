<?php
/**
 * SMK Chendering Library - Modern Student Dashboard
 * Light & Welcoming Theme for Students
 * Version 2.1 - Fixed Fines & Reservation Queue
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

// Data Queries
try {
    // Statistics
    
    // 1. Borrowed Books Count - Count only borrowed books for this user
    $stmt = $conn->prepare("SELECT COUNT(*) as c FROM borrow WHERE userID = ? AND borrow_status = 'borrowed'");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $borrowed_count = $stmt->get_result()->fetch_assoc()['c'];
    
    // 2. Overdue Books Count - Count only overdue books for this user
    $stmt = $conn->prepare("SELECT COUNT(*) as c FROM borrow WHERE userID = ? AND borrow_status = 'borrowed' AND due_date < CURDATE()");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $overdue_count = $stmt->get_result()->fetch_assoc()['c'];
    
    // 3. Active Reservations Count - Count only active reservations for this user
    $stmt = $conn->prepare("SELECT COUNT(*) as c FROM reservation WHERE userID = ? AND reservation_status IN ('waiting','ready')");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $reservations_count = $stmt->get_result()->fetch_assoc()['c'];
    
    // 4. FIXED: Total Fines - Get from BOTH fines table AND borrow table
// 4. FIXED: Total Fines - Get from BOTH fines table AND borrow table
// This ensures we capture fines whether they're in the fines table or just in borrow table
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
$fines_amount = $fines_result['total_fines'];

    
    
    // Student Info - Get student class information
    $stmt = $conn->prepare("SELECT studentClass FROM student WHERE userID = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $student_info = $stmt->get_result()->fetch_assoc();
    $student_class = $student_info['studentClass'] ?? '';
    
    // Current Borrowed Books with Detailed Information
    $stmt = $conn->prepare("
        SELECT b.*, bc.categoryName, br.due_date, br.borrowID, br.borrow_date,
               DATEDIFF(br.due_date, CURDATE()) as days_left,
               br.renewal_count,
               (SELECT max_renewals_allowed FROM borrowing_rules WHERE user_type = 'student') as max_renewals
        FROM borrow br
        JOIN book b ON br.bookID = b.bookID
        JOIN book_category bc ON b.categoryID = bc.categoryID
        WHERE br.userID = ? AND br.borrow_status = 'borrowed'
        ORDER BY br.borrow_date DESC
        LIMIT 6
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $borrowed_books = $stmt->get_result();
    
    // NEW: Active Reservations with Queue Position
    $stmt = $conn->prepare("
        SELECT r.*, b.bookTitle, b.bookAuthor, b.book_image, b.book_image_mime, 
               bc.categoryName, r.queue_position,
               (SELECT COUNT(*) FROM reservation r2 
                WHERE r2.bookID = r.bookID 
                AND r2.reservation_status IN ('waiting', 'ready') 
                AND r2.queue_position < r.queue_position) as ahead_in_queue
        FROM reservation r
        JOIN book b ON r.bookID = b.bookID
        JOIN book_category bc ON b.categoryID = bc.categoryID
        WHERE r.userID = ? 
        AND r.reservation_status IN ('waiting', 'ready')
        ORDER BY r.reservation_date ASC
        LIMIT 4
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $active_reservations = $stmt->get_result();
    
    // New Releases - Books added in the last 60 days
    $new_releases = $conn->query("
        SELECT b.*, bc.categoryName
        FROM book b
        JOIN book_category bc ON b.categoryID = bc.categoryID
        WHERE b.bookStatus IN ('available','borrowed')
        AND b.book_entry_date >= DATE_SUB(NOW(), INTERVAL 60 DAY)
        ORDER BY b.book_entry_date DESC
        LIMIT 8
    ");
    
    // Popular Books - Most borrowed in the last 90 days
    $popular_books = $conn->query("
        SELECT b.*, bc.categoryName, COUNT(br.borrowID) as borrow_count
        FROM book b
        JOIN book_category bc ON b.categoryID = bc.categoryID
        LEFT JOIN borrow br ON b.bookID = br.bookID AND br.borrow_date >= DATE_SUB(NOW(), INTERVAL 90 DAY)
        WHERE b.bookStatus IN ('available','borrowed')
        GROUP BY b.bookID
        ORDER BY borrow_count DESC
        LIMIT 8
    ");
    
    // Recent Notifications - Last 5 notifications for this user
    $stmt = $conn->prepare("
        SELECT * FROM notifications 
        WHERE userID = ? 
        ORDER BY sent_date DESC 
        LIMIT 5
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $notifications = $stmt->get_result();
    
    // Unread Notifications Count
    $stmt = $conn->prepare("SELECT COUNT(*) as c FROM notifications WHERE userID = ? AND read_status = 0");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $unread_count = $stmt->get_result()->fetch_assoc()['c'];
    
} catch (Exception $e) {
    error_log("Dashboard Error: " . $e->getMessage());
    // Set default values if queries fail
    $borrowed_count = 0;
    $overdue_count = 0;
    $reservations_count = 0;
    $fines_amount = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - SMK Chendering Library</title>
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

        /* SIDEBAR - UNCHANGED */
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

        /* MAIN CONTENT - UNCHANGED */
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

        .search-wrapper {
            flex: 1;
            max-width: 500px;
            position: relative;
        }

        .search-wrapper input {
            width: 100%;
            padding: 12px 20px 12px 48px;
            background: var(--bg-secondary);
            border: 2px solid var(--border);
            border-radius: 12px;
            color: var(--text-primary);
            font-size: 15px;
            transition: all 0.2s;
        }

        .search-wrapper input:focus {
            outline: none;
            border-color: var(--accent-secondary);
            background: var(--bg-card);
            box-shadow: 0 0 0 4px rgba(52, 152, 219, 0.1);
        }

        .search-wrapper i {
            position: absolute;
            left: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
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

        .hero-section {
            background: linear-gradient(135deg, var(--bg-card) 0%, var(--bg-secondary) 100%);
            border-radius: 20px;
            padding: 48px;
            margin-bottom: 40px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--border);
        }

        .hero-greeting {
            font-family: 'Playfair Display', serif;
            font-size: 42px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 12px;
            line-height: 1.2;
        }

        .hero-subtitle {
            font-size: 18px;
            color: var(--text-secondary);
            margin-bottom: 24px;
        }

        .quick-search {
            display: flex;
            gap: 12px;
            max-width: 600px;
        }

        .quick-search input {
            flex: 1;
            padding: 14px 20px;
            background: var(--bg-card);
            border: 2px solid var(--border);
            border-radius: 12px;
            font-size: 15px;
            color: var(--text-primary);
        }

        .quick-search input:focus {
            outline: none;
            border-color: var(--accent-secondary);
            box-shadow: 0 0 0 4px rgba(52, 152, 219, 0.1);
        }

        .btn-primary {
            padding: 14px 28px;
            background: var(--accent-primary);
            color: white;
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

        .btn-primary:hover {
            background: #C0392B;
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        /* STATS GRID - UPDATED */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 24px;
            margin-bottom: 40px;
        }

        .stat-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 24px;
            transition: all 0.3s;
            box-shadow: var(--shadow-sm);
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-md);
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .stat-card:nth-child(1) .stat-icon {
            background: rgba(231, 76, 60, 0.1);
            color: var(--accent-primary);
        }

        .stat-card:nth-child(2) .stat-icon {
            background: rgba(52, 152, 219, 0.1);
            color: var(--accent-secondary);
        }

        .stat-card:nth-child(3) .stat-icon {
            background: rgba(243, 156, 18, 0.1);
            color: var(--accent-warning);
        }

        .stat-card:nth-child(4) .stat-icon {
            background: rgba(39, 174, 96, 0.1);
            color: var(--accent-success);
        }

        .stat-value {
            font-size: 32px;
            font-weight: 800;
            color: var(--text-primary);
            margin-bottom: 4px;
        }

        .stat-label {
            font-size: 14px;
            color: var(--text-secondary);
        }

        /* NEW: Reservation Queue Indicator */
        .queue-indicator {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 14px;
            background: rgba(52, 152, 219, 0.1);
            color: var(--accent-secondary);
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            margin-top: 8px;
        }

        .queue-indicator.first {
            background: rgba(39, 174, 96, 0.1);
            color: var(--accent-success);
        }

        .queue-indicator i {
            font-size: 14px;
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

        .view-all-link {
            color: var(--accent-secondary);
            text-decoration: none;
            font-size: 15px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }

        .view-all-link:hover {
            gap: 12px;
            color: var(--accent-primary);
        }

        /* BOOK SCROLL */
        .book-scroll {
            display: flex;
            gap: 24px;
            overflow-x: auto;
            padding: 8px 0 24px;
            scroll-behavior: smooth;
            scrollbar-width: thin;
            scrollbar-color: var(--accent-primary) var(--bg-secondary);
        }

        .book-scroll::-webkit-scrollbar {
            height: 8px;
        }

        .book-scroll::-webkit-scrollbar-track {
            background: var(--bg-secondary);
            border-radius: 4px;
        }

        .book-scroll::-webkit-scrollbar-thumb {
            background: var(--accent-primary);
            border-radius: 4px;
        }

        .book-card {
            flex: 0 0 180px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .book-card:hover {
            transform: translateY(-8px);
        }

        .book-cover-wrapper {
            position: relative;
            width: 180px;
            height: 260px;
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 16px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--border);
            background: var(--bg-secondary);
        }

        .book-cover-wrapper img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .book-status-badge {
            position: absolute;
            top: 12px;
            right: 12px;
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 11px;
            font-weight: 700;
            background: rgba(255, 255, 255, 0.95);
            color: var(--text-primary);
            backdrop-filter: blur(4px);
        }

        .book-status-badge.available {
            background: var(--accent-success);
            color: white;
        }

        .book-info h4 {
            font-size: 15px;
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

        .book-author {
            font-size: 13px;
            color: var(--text-secondary);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* READING PROGRESS */
        .reading-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 24px;
        }

        .reading-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 24px;
            display: flex;
            gap: 20px;
            box-shadow: var(--shadow-sm);
            transition: all 0.3s;
        }

        .reading-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-md);
        }

        .reading-cover {
            width: 100px;
            height: 140px;
            border-radius: 10px;
            overflow: hidden;
            flex-shrink: 0;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border);
        }

        .reading-cover img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .reading-details {
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .reading-details h4 {
            font-size: 16px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 6px;
        }

        .reading-details p {
            font-size: 14px;
            color: var(--text-secondary);
            margin-bottom: 12px;
        }

        .reading-meta {
            display: flex;
            gap: 16px;
            font-size: 13px;
            color: var(--text-muted);
            margin-bottom: 12px;
        }

        .reading-meta span {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .progress-bar {
            height: 6px;
            background: var(--bg-secondary);
            border-radius: 3px;
            overflow: hidden;
            margin-bottom: 12px;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--accent-primary), var(--accent-secondary));
            border-radius: 3px;
            transition: width 0.3s;
        }

        .due-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            margin-top: auto;
        }

        .due-badge.overdue {
            background: rgba(231, 76, 60, 0.1);
            color: var(--accent-primary);
        }

        .due-badge.warning {
            background: rgba(243, 156, 18, 0.1);
            color: var(--accent-warning);
        }

        .due-badge.safe {
            background: rgba(39, 174, 96, 0.1);
            color: var(--accent-success);
        }

        .action-btns {
            display: flex;
            gap: 8px;
            margin-top: 12px;
        }

        .btn-secondary {
            padding: 8px 16px;
            background: var(--bg-secondary);
            color: var(--text-primary);
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-secondary:hover {
            background: var(--accent-secondary);
            color: white;
            border-color: var(--accent-secondary);
        }

        /* NOTIFICATIONS PANEL */
        .notifications-panel {
            position: fixed;
            right: -400px;
            top: 0;
            width: 380px;
            height: 100vh;
            background: var(--bg-card);
            border-left: 1px solid var(--border);
            transition: right 0.3s;
            z-index: 1100;
            display: flex;
            flex-direction: column;
            box-shadow: var(--shadow-lg);
        }

        .notifications-panel.show {
            right: 0;
        }

        .panel-header {
            padding: 24px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .panel-header h3 {
            font-size: 20px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .close-btn {
            background: none;
            border: none;
            color: var(--text-secondary);
            cursor: pointer;
            font-size: 20px;
            padding: 8px;
            transition: all 0.2s;
        }

        .close-btn:hover {
            color: var(--accent-primary);
        }

        .panel-body {
            flex: 1;
            overflow-y: auto;
            padding: 16px;
        }

        .notif-item {
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 12px;
            transition: all 0.2s;
        }

        .notif-item:hover {
            background: var(--bg-card);
            box-shadow: var(--shadow-sm);
        }

        .notif-item strong {
            display: block;
            font-size: 14px;
            color: var(--text-primary);
            margin-bottom: 6px;
        }

        .notif-item p {
            font-size: 13px;
            color: var(--text-secondary);
            margin-bottom: 8px;
            line-height: 1.5;
        }

        .notif-time {
            font-size: 11px;
            color: var(--text-muted);
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-muted);
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.5;
        }

        /* OVERLAY */
        .overlay {
            position: fixed;
            inset: 0;
            background: rgba(44, 62, 80, 0.4);
            backdrop-filter: blur(4px);
            z-index: 1050;
            display: none;
        }

        .overlay.show {
            display: block;
        }

        /* RESPONSIVE */
        @media (max-width: 1400px) {
            .reading-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 1024px) {
            .stats-grid {
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
            
            .hero-section {
                padding: 32px 24px;
            }
            
            .hero-greeting {
                font-size: 32px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .book-scroll {
                gap: 16px;
            }
            
            .book-card {
                flex: 0 0 140px;
            }
            
            .book-cover-wrapper {
                width: 140px;
                height: 200px;
            }
            
            .user-info {
                display: none;
            }
        }
    </style>
</head>
<body>

<!-- Sidebar - UNCHANGED -->
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
    <!-- Top Header - UNCHANGED -->
    <div class="top-header">
        <div class="search-wrapper">
            <i class="fas fa-search"></i>
            <input type="text" placeholder="Search book name, author, edition..." id="searchInput">
        </div>
        
        <div class="header-actions">
            <button class="action-btn" onclick="toggleNotifications()" title="Notifications">
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
    
    <!-- Content Area - UPDATED WITH FIXES -->
    <div class="content-area">
        <!-- Hero Section -->
        <div class="hero-section">
            <h1 class="hero-greeting">Happy reading, <?php echo htmlspecialchars(explode(' ', $student_name)[0]); ?></h1>
            <p class="hero-subtitle">Discover your next favorite book from our collection</p>
            <form class="quick-search" action="student_search_books.php" method="GET">
                <input type="text" name="q" placeholder="Search for books...">
                <button type="submit" class="btn-primary">
                    <i class="fas fa-search"></i> Search
                </button>
            </form>
        </div>
        
        <!-- Stats Grid - UPDATED WITH FIXED FINES -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-value"><?php echo $borrowed_count; ?></div>
                        <div class="stat-label">Books Borrowed</div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-book-open"></i>
                    </div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-value"><?php echo $reservations_count; ?></div>
                        <div class="stat-label">Reservations</div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-value"><?php echo $overdue_count; ?></div>
                        <div class="stat-label">Overdue Books</div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                </div>
            </div>
            
            <!-- FIXED: Fines Card - Now shows only logged-in student's fines -->
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-value">RM <?php echo number_format($fines_amount, 2); ?></div>
                        <div class="stat-label">Fines</div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-wallet"></i>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Currently Reading Section -->
        <?php if ($borrowed_books && $borrowed_books->num_rows > 0): ?>
        <div class="section">
            <div class="section-header">
                <h2 class="section-title">
                    <i class="fas fa-book-reader"></i>
                    Currently Reading
                </h2>
                <a href="student_my_borrowed_books.php" class="view-all-link">
                    View All <i class="fas fa-arrow-right"></i>
                </a>
            </div>
            
            <div class="reading-grid">
                <?php 
                $count = 0;
                while ($book = $borrowed_books->fetch_assoc()): 
                    if ($count >= 4) break;
                    $count++;
                    
                    $days_left = $book['days_left'];
                    $total_days = (strtotime($book['due_date']) - strtotime($book['borrow_date'])) / 86400;
                    $progress = max(0, min(100, (($total_days - $days_left) / $total_days) * 100));
                    
                    // Determine badge status based on days left
                    if ($days_left < 0) {
                        $badge_class = 'overdue';
                        $badge_text = 'Overdue';
                        $badge_icon = 'exclamation-circle';
                    } elseif ($days_left <= 3) {
                        $badge_class = 'warning';
                        $badge_text = $days_left == 0 ? 'Due Today' : ($days_left == 1 ? '1 day left' : "$days_left days left");
                        $badge_icon = 'clock';
                    } else {
                        $badge_class = 'safe';
                        $badge_text = "$days_left days left";
                        $badge_icon = 'check-circle';
                    }
                    
                    // Check if renewal is allowed
                    $can_renew = ($book['renewal_count'] < $book['max_renewals']) && ($days_left >= 0);
                ?>
                <div class="reading-card">
                    <div class="reading-cover">
                        <img src="<?php echo getBookImage($book['book_image'], $book['book_image_mime']); ?>" alt="<?php echo htmlspecialchars($book['bookTitle']); ?>">
                    </div>
                    <div class="reading-details">
                        <h4><?php echo htmlspecialchars($book['bookTitle']); ?></h4>
                        <p><?php echo htmlspecialchars($book['bookAuthor']); ?></p>
                        
                        <div class="reading-meta">
                            <span>
                                <i class="fas fa-tag"></i>
                                <?php echo htmlspecialchars($book['categoryName']); ?>
                            </span>
                            <span>
                                <i class="fas fa-calendar"></i>
                                Due: <?php echo date('M d, Y', strtotime($book['due_date'])); ?>
                            </span>
                        </div>
                        
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?php echo $progress; ?>%"></div>
                        </div>
                        
                        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap;">
                            <span class="due-badge <?php echo $badge_class; ?>">
                                <i class="fas fa-<?php echo $badge_icon; ?>"></i>
                                <?php echo $badge_text; ?>
                            </span>
                            <div class="action-btns">
                                <?php if ($can_renew): ?>
                                <button class="btn-secondary" onclick="renewBook(<?php echo $book['borrowID']; ?>)">
                                    <i class="fas fa-redo"></i> Renew
                                </button>
                                <?php else: ?>
                                <button class="btn-secondary" disabled title="<?php echo $days_left < 0 ? 'Cannot renew overdue books' : 'Maximum renewals reached'; ?>">
                                    <i class="fas fa-ban"></i> 
                                    <?php echo $days_left < 0 ? 'Overdue' : 'Max Renewals'; ?>
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- NEW: Active Reservations Section with Queue Indicators -->
        <?php if ($active_reservations && $active_reservations->num_rows > 0): ?>
        <div class="section">
            <div class="section-header">
                <h2 class="section-title">
                    <i class="fas fa-bookmark"></i>
                    My Reservations
                </h2>
                <a href="student_borrowing_reservations.php" class="view-all-link">
                    View All <i class="fas fa-arrow-right"></i>
                </a>
            </div>
            
            <div class="reading-grid">
                <?php while ($reservation = $active_reservations->fetch_assoc()): ?>
                <div class="reading-card">
                    <div class="reading-cover">
                        <img src="<?php echo getBookImage($reservation['book_image'], $reservation['book_image_mime']); ?>" alt="<?php echo htmlspecialchars($reservation['bookTitle']); ?>">
                    </div>
                    <div class="reading-details">
                        <h4><?php echo htmlspecialchars($reservation['bookTitle']); ?></h4>
                        <p><?php echo htmlspecialchars($reservation['bookAuthor']); ?></p>
                        
                        <div class="reading-meta">
                            <span>
                                <i class="fas fa-tag"></i>
                                <?php echo htmlspecialchars($reservation['categoryName']); ?>
                            </span>
                            <span>
                                <i class="fas fa-calendar"></i>
                                Reserved: <?php echo date('M d, Y', strtotime($reservation['reservation_date'])); ?>
                            </span>
                        </div>
                        
                        <!-- Status Badge -->
                        <?php if ($reservation['reservation_status'] === 'ready'): ?>
                            <span class="due-badge safe">
                                <i class="fas fa-check-circle"></i>
                                Ready for Pickup
                            </span>
                            <?php if ($reservation['self_pickup_deadline']): ?>
                            <p style="font-size: 12px; color: var(--text-muted); margin-top: 8px;">
                                <i class="fas fa-clock"></i> 
                                Pickup by: <?php echo date('M d, Y H:i', strtotime($reservation['self_pickup_deadline'])); ?>
                            </p>
                            <?php endif; ?>
                        <?php else: ?>
                            <!-- Queue Position Indicator -->
                            <?php 
                            $queue_pos = $reservation['queue_position'];
                            $is_first = ($queue_pos == 1);
                            ?>
                            <div class="queue-indicator <?php echo $is_first ? 'first' : ''; ?>">
                                <i class="fas fa-<?php echo $is_first ? 'crown' : 'users'; ?>"></i>
                                <?php if ($is_first): ?>
                                    You're next in line!
                                <?php else: ?>
                                    Position #<?php echo $queue_pos; ?> in queue
                                <?php endif; ?>
                            </div>
                            <p style="font-size: 12px; color: var(--text-muted); margin-top: 8px;">
                                <i class="fas fa-hourglass-half"></i> 
                                <?php 
                                $ahead = $reservation['ahead_in_queue'];
                                echo $ahead == 0 ? "You're first!" : "$ahead " . ($ahead == 1 ? "person" : "people") . " ahead";
                                ?>
                            </p>
                        <?php endif; ?>
                        
                        <div class="action-btns" style="margin-top: 12px;">
                            <button class="btn-secondary" onclick="viewBook(<?php echo $reservation['bookID']; ?>)">
                                <i class="fas fa-eye"></i> View Details
                            </button>
                            <?php if ($reservation['reservation_status'] === 'waiting'): ?>
                            <button class="btn-secondary" onclick="cancelReservation(<?php echo $reservation['reservationID']; ?>)">
                                <i class="fas fa-times"></i> Cancel
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Popular Now -->
        <?php if ($popular_books && $popular_books->num_rows > 0): ?>
        <div class="section">
            <div class="section-header">
                <h2 class="section-title">
                    <i class="fas fa-fire"></i>
                    Popular Now
                </h2>
                <a href="student_search_books.php?filter=popular" class="view-all-link">
                    View All <i class="fas fa-arrow-right"></i>
                </a>
            </div>
            
            <div class="book-scroll">
                <?php while ($book = $popular_books->fetch_assoc()): ?>
                <div class="book-card" onclick="viewBook(<?php echo $book['bookID']; ?>)">
                    <div class="book-cover-wrapper">
                        <img src="<?php echo getBookImage($book['book_image'], $book['book_image_mime']); ?>" alt="<?php echo htmlspecialchars($book['bookTitle']); ?>">
                        <?php if ($book['bookStatus'] === 'available'): ?>
                        <span class="book-status-badge available">Available</span>
                        <?php endif; ?>
                    </div>
                    <div class="book-info">
                        <h4><?php echo htmlspecialchars($book['bookTitle']); ?></h4>
                        <p class="book-author"><?php echo htmlspecialchars($book['bookAuthor']); ?></p>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- New Releases -->
        <?php 
        if ($new_releases) $new_releases->data_seek(0);
        if ($new_releases && $new_releases->num_rows > 0): 
        ?>
        <div class="section">
            <div class="section-header">
                <h2 class="section-title">
                    <i class="fas fa-star"></i>
                    New Releases
                </h2>
                <a href="student_search_books.php?filter=new" class="view-all-link">
                    View All <i class="fas fa-arrow-right"></i>
                </a>
            </div>
            
            <div class="book-scroll">
                <?php while ($book = $new_releases->fetch_assoc()): ?>
                <div class="book-card" onclick="viewBook(<?php echo $book['bookID']; ?>)">
                    <div class="book-cover-wrapper">
                        <img src="<?php echo getBookImage($book['book_image'], $book['book_image_mime']); ?>" alt="<?php echo htmlspecialchars($book['bookTitle']); ?>">
                        <?php if ($book['bookStatus'] === 'available'): ?>
                        <span class="book-status-badge available">Available</span>
                        <?php endif; ?>
                    </div>
                    <div class="book-info">
                        <h4><?php echo htmlspecialchars($book['bookTitle']); ?></h4>
                        <p class="book-author"><?php echo htmlspecialchars($book['bookAuthor']); ?></p>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Notifications Panel - UNCHANGED -->
<div class="notifications-panel" id="notificationsPanel">
    <div class="panel-header">
        <h3>Notifications</h3>
        <button class="close-btn" onclick="toggleNotifications()">
            <i class="fas fa-times"></i>
        </button>
    </div>
    
    <div class="panel-body">
        <?php if ($notifications && $notifications->num_rows > 0): ?>
            <?php while ($notif = $notifications->fetch_assoc()): ?>
            <div class="notif-item">
                <strong><?php echo htmlspecialchars($notif['title']); ?></strong>
                <p><?php echo htmlspecialchars($notif['message']); ?></p>
                <span class="notif-time"><?php echo date('M d, Y - H:i', strtotime($notif['sent_date'])); ?></span>
            </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-bell-slash"></i>
                <p>No notifications yet</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Overlay -->
<div class="overlay" id="overlay" onclick="closeAll()"></div>

<script>
    // Toggle Notifications
    function toggleNotifications() {
        const panel = document.getElementById('notificationsPanel');
        const overlay = document.getElementById('overlay');
        const isVisible = panel.classList.contains('show');
        
        if (isVisible) {
            panel.classList.remove('show');
            overlay.classList.remove('show');
        } else {
            panel.classList.add('show');
            overlay.classList.add('show');
        }
    }
    
    // Close All Panels
    function closeAll() {
        document.getElementById('notificationsPanel').classList.remove('show');
        document.getElementById('overlay').classList.remove('show');
    }
    
    // View Book Details
    function viewBook(bookId) {
        window.location.href = 'student_book_details.php?id=' + bookId;
    }
    
    // Renew Book Function
    function renewBook(borrowId) {
        if (confirm('Do you want to renew this book?')) {
            // Disable the button to prevent double submission
            event.target.disabled = true;
            event.target.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            
            fetch('renew_book.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'borrow_id=' + borrowId
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Book renewed successfully! New due date: ' + (data.new_due_date || ''));
                    location.reload();
                } else {
                    alert(data.message || 'Failed to renew book');
                    event.target.disabled = false;
                    event.target.innerHTML = '<i class="fas fa-redo"></i> Renew';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while renewing the book');
                event.target.disabled = false;
                event.target.innerHTML = '<i class="fas fa-redo"></i> Renew';
            });
        }
    }
    
    // Cancel Reservation Function
    function cancelReservation(reservationId) {
        if (confirm('Are you sure you want to cancel this reservation?')) {
            event.target.disabled = true;
            event.target.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Cancelling...';
            
            fetch('cancel_reservation.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'reservation_id=' + reservationId
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Reservation cancelled successfully!');
                    location.reload();
                } else {
                    alert(data.message || 'Failed to cancel reservation');
                    event.target.disabled = false;
                    event.target.innerHTML = '<i class="fas fa-times"></i> Cancel';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while cancelling the reservation');
                event.target.disabled = false;
                event.target.innerHTML = '<i class="fas fa-times"></i> Cancel';
            });
        }
    }
    
    // Search Functionality
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                const query = e.target.value.trim();
                if (query) {
                    window.location.href = 'student_search_books.php?q=' + encodeURIComponent(query);
                }
            }
        });
    }
    
    // Keyboard Shortcuts
    document.addEventListener('keydown', (e) => {
        // ESC to close panels
        if (e.key === 'Escape') {
            closeAll();
        }
        
        // Ctrl+K or Cmd+K for search
        if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
            e.preventDefault();
            searchInput?.focus();
        }
    });
    
    // Smooth Scroll for Book Collections
    document.querySelectorAll('.book-scroll').forEach(scroll => {
        let isDown = false;
        let startX;
        let scrollLeft;
        
        scroll.addEventListener('mousedown', (e) => {
            isDown = true;
            scroll.style.cursor = 'grabbing';
            startX = e.pageX - scroll.offsetLeft;
            scrollLeft = scroll.scrollLeft;
        });
        
        scroll.addEventListener('mouseleave', () => {
            isDown = false;
            scroll.style.cursor = 'default';
        });
        
        scroll.addEventListener('mouseup', () => {
            isDown = false;
            scroll.style.cursor = 'default';
        });
        
        scroll.addEventListener('mousemove', (e) => {
            if (!isDown) return;
            e.preventDefault();
            const x = e.pageX - scroll.offsetLeft;
            const walk = (x - startX) * 2;
            scroll.scrollLeft = scrollLeft - walk;
        });
    });
    
    // Lazy Loading Images
    if ('IntersectionObserver' in window) {
        const imageObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const img = entry.target;
                    if (img.dataset.src) {
                        img.src = img.dataset.src;
                        img.removeAttribute('data-src');
                        imageObserver.unobserve(img);
                    }
                }
            });
        });
        
        document.querySelectorAll('img[data-src]').forEach(img => {
            imageObserver.observe(img);
        });
    }
    
    // Mobile Sidebar Toggle
    if (window.innerWidth <= 768) {
        const sidebar = document.getElementById('sidebar');
        document.addEventListener('click', (e) => {
            if (!sidebar.contains(e.target) && sidebar.classList.contains('show')) {
                sidebar.classList.remove('show');
                closeAll();
            }
        });
    }
    
    // Prevent Multiple Button Clicks
    document.querySelectorAll('button').forEach(btn => {
        btn.addEventListener('click', function(e) {
            if (this.disabled) {
                e.preventDefault();
                return;
            }
            if (!this.classList.contains('no-disable')) {
                this.disabled = true;
                setTimeout(() => this.disabled = false, 1000);
            }
        });
    });
    
    // Welcome Animation
    window.addEventListener('load', () => {
        const hero = document.querySelector('.hero-section');
        if (hero) {
            hero.style.opacity = '0';
            hero.style.transform = 'translateY(20px)';
            setTimeout(() => {
                hero.style.transition = 'all 0.6s ease';
                hero.style.opacity = '1';
                hero.style.transform = 'translateY(0)';
            }, 100);
        }
    });
    
    // Performance Monitoring
    window.addEventListener('load', () => {
        if (window.performance) {
            const loadTime = performance.timing.loadEventEnd - performance.timing.navigationStart;
            console.log(`✨ Dashboard loaded in ${loadTime}ms`);
        }
    });
    
    // Console Branding
    console.log('%c📚 SMK Chendering Library System', 'color: #E74C3C; font-size: 20px; font-weight: bold; padding: 10px;');
    console.log('%cStudent Dashboard v2.1 - Fixed Version', 'color: #7F8C8D; font-size: 14px; padding: 5px;');
    console.log('%c© 2025 SMK Chendering', 'color: #95A5A6; font-size: 12px; padding: 5px;');
</script>

</body>
</html>