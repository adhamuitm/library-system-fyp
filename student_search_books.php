<?php
/**
 * SMK Chendering Library - OPAC Book Search
 * Online Public Access Catalog with 3D Book Cards & Ratings
 */

session_start();
require_once 'dbconnect.php';
require_once 'auth_helper.php';

// Authentication
checkPageAccess();
requireRole('student');

$student_name = getUserDisplayName();
$user_id = $_SESSION['user_id'] ?? 0;

// Helper Functions
function getBookImage($img, $mime) {
    if (empty($img)) {
        return 'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="200" height="280"%3E%3Crect fill="%23F7F4EF" width="200" height="280"/%3E%3Ctext x="50%25" y="50%25" text-anchor="middle" fill="%23C4B5A0" font-size="16" font-family="Inter"%3ENo Cover%3C/text%3E%3C/svg%3E';
    }
    if (is_string($img) && !preg_match('/[^\x20-\x7E]/', $img)) return htmlspecialchars($img);
    return 'data:' . ($mime ?: 'image/jpeg') . ';base64,' . base64_encode($img);
}

// Handle AJAX Search
if (isset($_GET['action']) && $_GET['action'] === 'search') {
    header('Content-Type: application/json');
    
    $search_title = trim($_GET['title'] ?? '');
    $search_author = trim($_GET['author'] ?? '');
    $search_category = trim($_GET['category'] ?? '');
    $search_isbn = trim($_GET['isbn'] ?? '');
    
    $where_conditions = ["b.bookStatus != 'disposed'"];
    $params = [];
    $types = '';
    
    if (!empty($search_title)) {
        $where_conditions[] = "b.bookTitle LIKE ?";
        $params[] = "%$search_title%";
        $types .= 's';
    }
    
    if (!empty($search_author)) {
        $where_conditions[] = "b.bookAuthor LIKE ?";
        $params[] = "%$search_author%";
        $types .= 's';
    }
    
    if (!empty($search_category) && $search_category !== 'all') {
        $where_conditions[] = "bc.categoryName = ?";
        $params[] = $search_category;
        $types .= 's';
    }
    
    if (!empty($search_isbn)) {
        $where_conditions[] = "b.book_ISBN LIKE ?";
        $params[] = "%$search_isbn%";
        $types .= 's';
    }
    
    $query = "
        SELECT 
            b.bookID,
            b.bookTitle,
            b.bookAuthor,
            b.bookPublisher,
            b.book_ISBN,
            b.bookStatus,
            b.publication_year,
            b.book_image,
            b.book_image_mime,
            bc.categoryName,
            0 as rating,
            0 as review_count
        FROM book b
        LEFT JOIN book_category bc ON b.categoryID = bc.categoryID
        WHERE " . implode(' AND ', $where_conditions) . "
        ORDER BY b.bookTitle ASC
        LIMIT 100
    ";
    
    try {
        $stmt = $conn->prepare($query);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        
        $books = [];
        while ($book = $result->fetch_assoc()) {
            if ($book['book_image'] && $book['book_image_mime']) {
                $book['book_image_url'] = 'data:' . $book['book_image_mime'] . ';base64,' . base64_encode($book['book_image']);
            } else {
                $book['book_image_url'] = null;
            }
            unset($book['book_image']);
            $books[] = $book;
        }
        
        echo json_encode(['success' => true, 'books' => $books]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Search failed']);
        error_log($e->getMessage());
    }
    exit;
}

// Initial Data Queries
try {
    // Get student info
    $stmt = $conn->prepare("SELECT studentClass FROM student WHERE userID = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $student_class = $stmt->get_result()->fetch_assoc()['studentClass'] ?? '';
    
    // Get categories for filter
    $categories = $conn->query("SELECT DISTINCT categoryName FROM book_category ORDER BY categoryName ASC");
    
    // Get unread notifications count
    $stmt = $conn->prepare("SELECT COUNT(*) as c FROM notifications WHERE userID = ? AND read_status = 0");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $unread_count = $stmt->get_result()->fetch_assoc()['c'];
    
    // Initial books (all available) - with rating placeholder
    $initial_books = $conn->query("
        SELECT 
            b.bookID,
            b.bookTitle,
            b.bookAuthor,
            b.bookPublisher,
            b.book_ISBN,
            b.bookStatus,
            b.publication_year,
            b.book_image,
            b.book_image_mime,
            bc.categoryName,
            0 as rating,
            0 as review_count
        FROM book b
        LEFT JOIN book_category bc ON b.categoryID = bc.categoryID
        WHERE b.bookStatus != 'disposed'
        ORDER BY b.book_entry_date DESC
        LIMIT 50
    ");
    
} catch (Exception $e) {
    error_log($e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book Catalog - SMK Chendering Library</title>
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
        width: 60px;      /* adjust size as needed */
        height: 60px;     /* maintain aspect ratio if possible */
        object-fit: contain;
        border-radius: 50%; /* remove if you don’t want rounded logo */
        margin-right: 10px;
        }
        .brand-logo {
            display: flex;
            align-items: center;
            gap: 10px;
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
        }

        /* SEARCH SECTION */
        .search-section {
            background: linear-gradient(135deg, var(--bg-card) 0%, var(--bg-secondary) 100%);
            border-radius: 20px;
            padding: 40px;
            margin-bottom: 40px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--border);
        }

        .search-header {
            text-align: center;
            margin-bottom: 32px;
        }

        .search-header h2 {
            font-family: 'Playfair Display', serif;
            font-size: 32px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 8px;
        }

        .search-header p {
            font-size: 16px;
            color: var(--text-secondary);
        }

        .search-form {
            max-width: 1000px;
            margin: 0 auto;
        }

        .search-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }

        .search-field {
            position: relative;
        }

        .search-field label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-secondary);
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .search-field i {
            color: var(--accent-primary);
        }

        .search-field input,
        .search-field select {
            width: 100%;
            padding: 12px 16px;
            background: var(--bg-card);
            border: 2px solid var(--border);
            border-radius: 12px;
            color: var(--text-primary);
            font-size: 14px;
            transition: all 0.2s;
        }

        .search-field input:focus,
        .search-field select:focus {
            outline: none;
            border-color: var(--accent-secondary);
            box-shadow: 0 0 0 4px rgba(52, 152, 219, 0.1);
        }

        .search-actions {
            display: flex;
            gap: 12px;
            justify-content: center;
        }

        .btn {
            padding: 12px 32px;
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

        .btn-primary:hover {
            background: #C0392B;
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .btn-secondary {
            background: var(--bg-card);
            color: var(--text-primary);
            border: 2px solid var(--border);
        }

        .btn-secondary:hover {
            background: var(--bg-secondary);
            border-color: var(--accent-secondary);
            color: var(--accent-secondary);
        }

        /* RESULTS SECTION */
        .results-section {
            margin-bottom: 40px;
        }

        .results-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 32px;
        }

        .results-count {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-primary);
        }

        .results-count span {
            color: var(--accent-primary);
            font-weight: 700;
        }

        .view-toggle {
            display: flex;
            gap: 8px;
            background: var(--bg-card);
            padding: 6px;
            border-radius: 12px;
            border: 1px solid var(--border);
        }

        .view-btn {
            width: 40px;
            height: 40px;
            background: transparent;
            border: none;
            border-radius: 8px;
            color: var(--text-secondary);
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .view-btn.active,
        .view-btn:hover {
            background: var(--accent-primary);
            color: white;
        }

        /* 3D BOOK CARDS */
        .books-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 32px;
            perspective: 1000px;
        }

        .book-card {
            background: var(--bg-card);
            border-radius: 16px;
            padding: 24px;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: pointer;
            box-shadow: 
                0 4px 8px rgba(44, 62, 80, 0.1),
                0 8px 16px rgba(44, 62, 80, 0.08);
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            position: relative;
            transform-style: preserve-3d;
            border: 1px solid var(--border);
        }

        .book-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--accent-primary), var(--accent-secondary));
            border-radius: 16px 16px 0 0;
            transform: scaleX(0);
            transition: transform 0.3s;
        }

        .book-card:hover {
            transform: translateY(-12px) rotateX(2deg) scale(1.02);
            box-shadow: 
                0 12px 24px rgba(44, 62, 80, 0.15),
                0 20px 40px rgba(44, 62, 80, 0.2),
                0 0 0 1px rgba(231, 76, 60, 0.1);
        }

        .book-card:hover::before {
            transform: scaleX(1);
        }

        .book-cover-container {
            width: 170px;
            height: 240px;
            margin-bottom: 20px;
            position: relative;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 
                0 10px 20px rgba(0, 0, 0, 0.15),
                0 6px 6px rgba(0, 0, 0, 0.1),
                inset 0 -2px 5px rgba(0, 0, 0, 0.1);
            transition: all 0.3s;
        }

        .book-card:hover .book-cover-container {
            transform: translateZ(20px) scale(1.05);
            box-shadow: 
                0 20px 40px rgba(0, 0, 0, 0.25),
                0 10px 10px rgba(0, 0, 0, 0.15),
                inset 0 -3px 7px rgba(0, 0, 0, 0.15);
        }

        .book-cover {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s;
        }

        .book-card:hover .book-cover {
            transform: scale(1.08);
        }

        .book-placeholder {
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, var(--bg-secondary), var(--border));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 48px;
            color: var(--text-muted);
        }

        .book-status {
            position: absolute;
            top: 8px;
            right: 8px;
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 11px;
            font-weight: 700;
            backdrop-filter: blur(8px);
            z-index: 1;
        }

        .status-available {
            background: rgba(39, 174, 96, 0.95);
            color: white;
            box-shadow: 0 2px 8px rgba(39, 174, 96, 0.4);
        }

        .status-borrowed {
            background: rgba(231, 76, 60, 0.95);
            color: white;
            box-shadow: 0 2px 8px rgba(231, 76, 60, 0.4);
        }

        .status-reserved {
            background: rgba(243, 156, 18, 0.95);
            color: white;
            box-shadow: 0 2px 8px rgba(243, 156, 18, 0.4);
        }

        .book-details {
            width: 100%;
        }

        .book-details h3 {
            font-size: 16px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 8px;
            overflow: hidden;
            text-overflow: ellipsis;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            line-height: 1.4;
            min-height: 44px;
        }

        .book-author {
            font-size: 13px;
            color: var(--text-secondary);
            margin-bottom: 12px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* RATING SYSTEM */
        .book-rating {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-bottom: 12px;
            padding: 8px 12px;
            background: var(--bg-secondary);
            border-radius: 8px;
        }

        .stars {
            display: flex;
            gap: 3px;
        }

        .star {
            color: var(--text-muted);
            font-size: 14px;
        }

        .star.filled {
            color: var(--accent-gold);
        }

        .star.half-filled {
            position: relative;
            color: var(--text-muted);
        }

        .star.half-filled::before {
            content: '\f005';
            position: absolute;
            left: 0;
            color: var(--accent-gold);
            width: 50%;
            overflow: hidden;
        }

        .rating-text {
            font-size: 12px;
            color: var(--text-muted);
            font-weight: 600;
        }

        .no-reviews {
            font-size: 11px;
            color: var(--text-muted);
            font-style: italic;
        }

        .book-category {
            display: inline-block;
            padding: 6px 12px;
            background: linear-gradient(135deg, rgba(231, 76, 60, 0.1), rgba(52, 152, 219, 0.1));
            border-radius: 8px;
            font-size: 11px;
            color: var(--text-primary);
            font-weight: 600;
            border: 1px solid rgba(231, 76, 60, 0.2);
        }

        /* LOADING & EMPTY STATES */
        .loading-state {
            text-align: center;
            padding: 60px 20px;
            grid-column: 1 / -1;
        }

        .loading-state i {
            font-size: 48px;
            color: var(--accent-secondary);
            margin-bottom: 16px;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            grid-column: 1 / -1;
        }

        .empty-state i {
            font-size: 64px;
            color: var(--text-muted);
            margin-bottom: 16px;
            opacity: 0.5;
        }

        .empty-state h3 {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 8px;
        }

        .empty-state p {
            font-size: 16px;
            color: var(--text-secondary);
            margin-bottom: 24px;
        }

        /* RESPONSIVE */
        @media (max-width: 1400px) {
            .search-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .books-grid {
                grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
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
            
            .search-section {
                padding: 24px 20px;
            }
            
            .search-grid {
                grid-template-columns: 1fr;
            }
            
            .books-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 20px;
            }
            
            .user-info {
                display: none;
            }
        }
    </style>
</head>
<body>

<!-- Sidebar -->
<aside class="sidebar" id="sidebar">
    <div class="brand-section">
        <div class="brand-logo">
            <!-- Replace the icon with your logo image -->
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
        <a href="student_search_books.php" class="nav-item active">
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
        <h1 class="page-title">Book Catalog</h1>
        
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
        <!-- Search Section -->
        <div class="search-section">
            <div class="search-header">
                <h2>Online Public Access Catalog</h2>
                <p>Search and discover thousands of books in our collection</p>
            </div>
            
            <form class="search-form" id="searchForm">
                <div class="search-grid">
                    <div class="search-field">
                        <label>
                            <i class="fas fa-book"></i>
                            Book Title
                        </label>
                        <input type="text" name="title" id="searchTitle" placeholder="Enter book title...">
                    </div>
                    
                    <div class="search-field">
                        <label>
                            <i class="fas fa-user-edit"></i>
                            Author
                        </label>
                        <input type="text" name="author" id="searchAuthor" placeholder="Enter author name...">
                    </div>
                    
                    <div class="search-field">
                        <label>
                            <i class="fas fa-tags"></i>
                            Category
                        </label>
                        <select name="category" id="searchCategory">
                            <option value="all">All Categories</option>
                            <?php if ($categories): ?>
                                <?php while ($cat = $categories->fetch_assoc()): ?>
                                    <option value="<?php echo htmlspecialchars($cat['categoryName']); ?>">
                                        <?php echo htmlspecialchars($cat['categoryName']); ?>
                                    </option>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    
                    <div class="search-field">
                        <label>
                            <i class="fas fa-barcode"></i>
                            ISBN
                        </label>
                        <input type="text" name="isbn" id="searchISBN" placeholder="Enter ISBN...">
                    </div>
                </div>
                
                <div class="search-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i>
                        Search Books
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="clearSearch()">
                        <i class="fas fa-times"></i>
                        Clear Filters
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Results Section -->
        <div class="results-section">
            <div class="results-header">
                <div class="results-count">
                    Found <span id="resultCount">0</span> books
                </div>
                <div class="view-toggle">
                    <button class="view-btn active" onclick="setView('grid')" title="Grid View">
                        <i class="fas fa-th"></i>
                    </button>
                    <button class="view-btn" onclick="setView('list')" title="List View">
                        <i class="fas fa-list"></i>
                    </button>
                </div>
            </div>
            
            <div class="books-grid" id="booksGrid">
                <div class="loading-state">
                    <i class="fas fa-spinner"></i>
                    <p>Loading books...</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    let allBooks = [];
    let currentView = 'grid';
    
    // Initialize on page load
    document.addEventListener('DOMContentLoaded', function() {
        loadInitialBooks();
        setupSearchForm();
    });
    
    // Load initial books
    function loadInitialBooks() {
        <?php if ($initial_books && $initial_books->num_rows > 0): ?>
        allBooks = <?php 
            $books_array = [];
            while ($book = $initial_books->fetch_assoc()) {
                if ($book['book_image'] && $book['book_image_mime']) {
                    $book['book_image_url'] = 'data:' . $book['book_image_mime'] . ';base64,' . base64_encode($book['book_image']);
                } else {
                    $book['book_image_url'] = null;
                }
                unset($book['book_image']);
                $books_array[] = $book;
            }
            echo json_encode($books_array);
        ?>;
        displayBooks(allBooks);
        <?php else: ?>
        displayBooks([]);
        <?php endif; ?>
    }
    
    // Setup search form
    function setupSearchForm() {
        const form = document.getElementById('searchForm');
        
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            performSearch();
        });
        
        // Real-time search on input
        const inputs = ['searchTitle', 'searchAuthor', 'searchCategory', 'searchISBN'];
        inputs.forEach(id => {
            const element = document.getElementById(id);
            element.addEventListener('input', debounce(performSearch, 500));
        });
    }
    
    // Perform search
    async function performSearch() {
        const formData = new FormData(document.getElementById('searchForm'));
        const params = new URLSearchParams();
        
        params.append('action', 'search');
        for (let [key, value] of formData.entries()) {
            if (value.trim()) {
                params.append(key, value.trim());
            }
        }
        
        showLoading();
        
        try {
            const response = await fetch('?' + params.toString());
            const data = await response.json();
            
            if (data.success) {
                allBooks = data.books;
                displayBooks(data.books);
            } else {
                showError('Search failed. Please try again.');
            }
        } catch (error) {
            console.error('Search error:', error);
            showError('An error occurred while searching.');
        }
    }
    
    // Display books with 3D cards and ratings
    function displayBooks(books) {
        const grid = document.getElementById('booksGrid');
        const count = document.getElementById('resultCount');
        
        count.textContent = books.length;
        
        if (books.length === 0) {
            grid.innerHTML = `
                <div class="empty-state">
                    <i class="fas fa-book-open"></i>
                    <h3>No Books Found</h3>
                    <p>We couldn't find any books matching your search criteria.</p>
                    <button class="btn btn-primary" onclick="clearSearch()">
                        <i class="fas fa-refresh"></i> Show All Books
                    </button>
                </div>
            `;
            return;
        }
        
        grid.innerHTML = books.map(book => {
            const statusClass = book.bookStatus === 'available' ? 'status-available' : 
                               book.bookStatus === 'borrowed' ? 'status-borrowed' : 
                               'status-reserved';
            const statusText = book.bookStatus === 'available' ? 'Available' : 
                              book.bookStatus === 'borrowed' ? 'Borrowed' : 
                              'Reserved';
            
            // Generate star rating display
            const rating = book.rating || 0;
            const reviewCount = book.review_count || 0;
            const starsHTML = generateStars(rating);
            
            return `
                <div class="book-card" onclick="viewBookDetails(${book.bookID})">
                    <div class="book-cover-container">
                        ${book.book_image_url ? 
                            `<img src="${book.book_image_url}" alt="${escapeHtml(book.bookTitle)}" class="book-cover">` :
                            `<div class="book-placeholder"><i class="fas fa-book"></i></div>`
                        }
                        <div class="book-status ${statusClass}">${statusText}</div>
                    </div>
                    <div class="book-details">
                        <h3>${escapeHtml(book.bookTitle)}</h3>
                        <p class="book-author">${escapeHtml(book.bookAuthor)}</p>
                        
                        <div class="book-rating">
                            <div class="stars">${starsHTML}</div>
                            ${reviewCount > 0 ? 
                                `<span class="rating-text">${rating.toFixed(1)} (${reviewCount})</span>` :
                                `<span class="no-reviews">No reviews yet</span>`
                            }
                        </div>
                        
                        <span class="book-category">${escapeHtml(book.categoryName || 'Uncategorized')}</span>
                    </div>
                </div>
            `;
        }).join('');
        
        // Animate cards
        animateCards();
    }
    
    // Generate star rating HTML
    function generateStars(rating) {
        let starsHTML = '';
        const fullStars = Math.floor(rating);
        const hasHalfStar = rating % 1 >= 0.5;
        const emptyStars = 5 - fullStars - (hasHalfStar ? 1 : 0);
        
        // Full stars
        for (let i = 0; i < fullStars; i++) {
            starsHTML += '<i class="fas fa-star star filled"></i>';
        }
        
        // Half star
        if (hasHalfStar) {
            starsHTML += '<i class="fas fa-star-half-alt star filled"></i>';
        }
        
        // Empty stars
        for (let i = 0; i < emptyStars; i++) {
            starsHTML += '<i class="far fa-star star"></i>';
        }
        
        return starsHTML;
    }
    
    // Animate cards on load
    function animateCards() {
        const cards = document.querySelectorAll('.book-card');
        cards.forEach((card, index) => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';
            setTimeout(() => {
                card.style.transition = 'all 0.5s ease';
                card.style.opacity = '1';
                card.style.transform = 'translateY(0)';
            }, index * 50);
        });
    }
    
    // Show loading state
    function showLoading() {
        document.getElementById('booksGrid').innerHTML = `
            <div class="loading-state">
                <i class="fas fa-spinner"></i>
                <p>Searching for books...</p>
            </div>
        `;
    }
    
    // Show error
    function showError(message) {
        document.getElementById('booksGrid').innerHTML = `
            <div class="empty-state">
                <i class="fas fa-exclamation-triangle" style="color: var(--accent-primary);"></i>
                <h3>Error</h3>
                <p>${message}</p>
                <button class="btn btn-primary" onclick="loadInitialBooks()">
                    <i class="fas fa-refresh"></i> Try Again
                </button>
            </div>
        `;
    }
    
    // Clear search
    function clearSearch() {
        document.getElementById('searchForm').reset();
        loadInitialBooks();
    }
    
    // Set view (grid/list)
    function setView(view) {
        currentView = view;
        const buttons = document.querySelectorAll('.view-btn');
        buttons.forEach(btn => btn.classList.remove('active'));
        event.target.closest('.view-btn').classList.add('active');
        
        const grid = document.getElementById('booksGrid');
        
        if (view === 'list') {
            grid.style.gridTemplateColumns = '1fr';
            const cards = grid.querySelectorAll('.book-card');
            cards.forEach(card => {
                card.style.flexDirection = 'row';
                card.style.textAlign = 'left';
                card.style.padding = '20px';
                card.style.alignItems = 'flex-start';
                const container = card.querySelector('.book-cover-container');
                if (container) {
                    container.style.width = '120px';
                    container.style.height = '170px';
                    container.style.marginBottom = '0';
                    container.style.marginRight = '20px';
                }
            });
        } else {
            grid.style.gridTemplateColumns = 'repeat(auto-fill, minmax(220px, 1fr))';
            const cards = grid.querySelectorAll('.book-card');
            cards.forEach(card => {
                card.style.flexDirection = 'column';
                card.style.textAlign = 'center';
                card.style.padding = '24px';
                card.style.alignItems = 'center';
                const container = card.querySelector('.book-cover-container');
                if (container) {
                    container.style.width = '170px';
                    container.style.height = '240px';
                    container.style.marginBottom = '20px';
                    container.style.marginRight = '0';
                }
            });
        }
    }
    
    // View book details
    function viewBookDetails(bookId) {
        window.location.href = `student_book_detail.php?id=${bookId}`;
    }
    
    // Escape HTML
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // Debounce function
    function debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }
    
    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Ctrl/Cmd + K to focus search
        if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
            e.preventDefault();
            document.getElementById('searchTitle').focus();
        }
        
        // Escape to clear search
        if (e.key === 'Escape') {
            clearSearch();
        }
    });
    
    // Add entrance animation
    window.addEventListener('load', function() {
        const searchSection = document.querySelector('.search-section');
        const resultsSection = document.querySelector('.results-section');
        
        searchSection.style.opacity = '0';
        searchSection.style.transform = 'translateY(30px)';
        resultsSection.style.opacity = '0';
        resultsSection.style.transform = 'translateY(30px)';
        
        setTimeout(() => {
            searchSection.style.transition = 'all 0.6s ease';
            searchSection.style.opacity = '1';
            searchSection.style.transform = 'translateY(0)';
        }, 100);
        
        setTimeout(() => {
            resultsSection.style.transition = 'all 0.6s ease';
            resultsSection.style.opacity = '1';
            resultsSection.style.transform = 'translateY(0)';
        }, 300);
    });
    
    // Console branding
    console.log('%c📚 SMK Chendering Library - OPAC', 'color: #E74C3C; font-size: 20px; font-weight: bold; padding: 10px;');
    console.log('%cBook Catalog Search with 3D Cards & Ratings v1.0', 'color: #7F8C8D; font-size: 14px; padding: 5px;');
</script>

</body>
</html>