<?php
session_start();
require_once 'dbconnect.php';
require_once 'auth_helper.php';
require_once 'auto_calculate_fine_trigger.php';
checkPageAccess();
requireRole('librarian');

function formatBorrowID($id) {
    return 'BWR' . str_pad($id, 3, '0', STR_PAD_LEFT);
}

function formatReservationID($id) {
    return 'RSV' . str_pad($id, 3, '0', STR_PAD_LEFT);
}

$librarian_info = getCurrentUser();
$librarian_name = getUserDisplayName();

$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// FIXED: Borrow records query
$query = "
    SELECT 
        b.borrowID,
        b.userID,
        b.bookID,
        b.borrow_date,
        b.due_date,
        b.return_date,
        b.borrow_status,
        b.renewal_count,
        b.notes,
        u.first_name,
        u.last_name,
        u.user_type,
        u.email,
        u.login_id,
        COALESCE(s.student_id_number, st.staff_id_number, u.login_id) AS id_number,
        bk.bookTitle,
        bk.bookAuthor,
        bk.bookBarcode,
        bk.book_ISBN,
        f.fineID,
        f.fine_amount as existing_fine,
        f.payment_status,
        br.overdue_fine_per_day,
        CASE 
            WHEN b.borrow_status = 'borrowed' AND b.due_date < CURDATE() THEN DATEDIFF(CURDATE(), b.due_date)
            ELSE 0
        END AS days_overdue,
        CASE 
            WHEN b.borrow_status = 'borrowed' AND b.due_date < CURDATE() THEN (DATEDIFF(CURDATE(), b.due_date) * br.overdue_fine_per_day)
            ELSE 0
        END AS calculated_fine
    FROM borrow b
    LEFT JOIN user u ON b.userID = u.userID
    LEFT JOIN student s ON u.userID = s.userID AND u.user_type = 'student'
    LEFT JOIN staff st ON u.userID = st.userID AND u.user_type = 'staff'
    LEFT JOIN book bk ON b.bookID = bk.bookID
    LEFT JOIN fines f ON b.borrowID = f.borrowID
    LEFT JOIN borrowing_rules br ON u.user_type = br.user_type
    WHERE 1=1
";

if ($status_filter != 'all') {
    $status_filter_safe = $conn->real_escape_string($status_filter);
    if ($status_filter_safe == 'overdue') {
        $query .= " AND b.borrow_status = 'borrowed' AND b.due_date < CURDATE()";
    } else {
        $query .= " AND b.borrow_status = '$status_filter_safe'";
    }
}

if (!empty($date_from)) {
    $query .= " AND b.borrow_date >= '" . $conn->real_escape_string($date_from) . "'";
}

if (!empty($date_to)) {
    $query .= " AND b.borrow_date <= '" . $conn->real_escape_string($date_to) . "'";
}

$query .= " ORDER BY b.borrow_date DESC";
$result = $conn->query($query);

// FIXED: Statistics with correct counting
$stats_query = "
    SELECT 
        COUNT(CASE WHEN borrow_status = 'borrowed' AND due_date >= CURDATE() THEN 1 END) as total_borrowed,
        COUNT(CASE WHEN borrow_status = 'returned' THEN 1 END) as total_returned,
        COUNT(CASE WHEN borrow_status = 'borrowed' AND due_date < CURDATE() THEN 1 END) as total_overdue,
        COUNT(CASE WHEN borrow_status = 'lost' THEN 1 END) as total_lost
    FROM borrow
";
$stats_result = $conn->query($stats_query);
$stats = $stats_result->fetch_assoc();

// FIXED: Reservation query with correct status values
$reservation_query = "
    SELECT 
        r.reservationID,
        r.userID,
        r.bookID,
        r.reservation_date,
        r.expiry_date,
        r.queue_position,
        r.reservation_status,
        r.notification_sent,
        r.self_pickup_deadline,
        r.pickup_notification_date,
        r.cancellation_reason,
        u.first_name,
        u.last_name,
        u.user_type,
        u.email,
        u.login_id,
        COALESCE(s.student_id_number, st.staff_id_number, u.login_id) AS id_number,
        bk.bookID,
        bk.bookTitle,
        bk.bookAuthor,
        bk.bookBarcode,
        bk.bookStatus,
        CASE 
            WHEN r.reservation_status = 'ready' AND r.self_pickup_deadline < NOW() THEN 'expired'
            ELSE r.reservation_status
        END AS display_status,
        CASE
            WHEN r.reservation_status = 'ready' THEN TIMESTAMPDIFF(HOUR, NOW(), r.self_pickup_deadline)
            ELSE NULL
        END AS hours_until_expiry
    FROM reservation r
    LEFT JOIN user u ON r.userID = u.userID
    LEFT JOIN student s ON u.userID = s.userID AND u.user_type = 'student'
    LEFT JOIN staff st ON u.userID = st.userID AND u.user_type = 'staff'
    LEFT JOIN book bk ON r.bookID = bk.bookID
    ORDER BY r.reservation_date DESC
";

$reservation_result = $conn->query($reservation_query);

// FIXED: Reservation statistics with correct status values
$res_stats_query = "
    SELECT 
        COUNT(CASE WHEN reservation_status = 'waiting' THEN 1 END) as waiting_reservations,
        COUNT(CASE WHEN reservation_status = 'ready' AND self_pickup_deadline >= NOW() THEN 1 END) as ready_reservations,
        COUNT(CASE WHEN reservation_status = 'fulfilled' THEN 1 END) as fulfilled_reservations,
        COUNT(CASE WHEN reservation_status = 'expired' OR (reservation_status = 'ready' AND self_pickup_deadline < NOW()) THEN 1 END) as expired_reservations,
        COUNT(CASE WHEN reservation_status = 'cancelled' THEN 1 END) as cancelled_reservations
    FROM reservation
";
$res_stats_result = $conn->query($res_stats_query);
$res_stats = $res_stats_result->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Circulation Control - SMK Chendering Library</title>
    <!-- External Libraries -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <style>
        :root {
            /* Professional Education-Themed Color Palette */
            --primary: #1e3a8a;
            --primary-light: #3b82f6;
            --primary-dark: #1d4ed8;
            --secondary: #64748b;
            --accent: #0ea5e9;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --light: #f8fafc;
            --light-gray: #e2e8f0;
            --medium-gray: #94a3b8;
            --dark: #1e293b;
            --card-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --card-shadow-hover: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --border-radius: 0.75rem;
            --transition: all 0.2s ease;
            --header-height: 64px;
            --sidebar-width: 260px;
            --sidebar-collapsed-width: 80px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: #f1f5f9;
            color: var(--dark);
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* Header Styles */
        .header {
            height: var(--header-height);
            background: white;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 50;
            display: flex;
            align-items: center;
            padding: 0 1.5rem;
            justify-content: space-between;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .toggle-sidebar {
            background: none;
            border: none;
            color: var(--secondary);
            font-size: 1.2rem;
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 6px;
            transition: var(--transition);
        }

        .toggle-sidebar:hover {
            background: var(--light);
            color: var(--primary);
        }

        .logo-container {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .school-logo {
            width: 40px;
            height: 40px;
            background: url('photo/logo1.png') no-repeat center center;
            background-size: contain;
            border-radius: 8px;
        }

        .logo-text {
            font-weight: 700;
            font-size: 1.25rem;
            color: var(--primary);
            letter-spacing: -0.025em;
        }

        .logo-text span {
            color: var(--primary-light);
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 1.25rem;
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            cursor: pointer;
        }

        .user-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.9rem;
        }

        /* Sidebar Styles */
        .sidebar {
            position: fixed;
            left: 0;
            top: var(--header-height);
            bottom: 0;
            width: var(--sidebar-width);
            background: white;
            border-right: 1px solid var(--light-gray);
            transition: var(--transition);
            z-index: 40;
            overflow-y: auto;
        }

        .sidebar.collapsed {
            width: var(--sidebar-collapsed-width);
        }

        .sidebar-menu {
            padding: 1rem 0;
        }

        .menu-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1.5rem;
            color: var(--secondary);
            text-decoration: none;
            transition: var(--transition);
            border: none;
            width: 100%;
            background: none;
            cursor: pointer;
        }

        .menu-item:hover {
            background: var(--light);
            color: var(--primary);
        }

        .menu-item.active {
            background: var(--primary);
            color: white;
            border-right: 3px solid var(--primary-dark);
        }

        .menu-item i {
            width: 20px;
            text-align: center;
        }

        .sidebar.collapsed .menu-item span {
            display: none;
        }

        .sidebar-footer {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            padding: 1rem;
            border-top: 1px solid var(--light-gray);
            background: var(--light);
            font-size: 0.75rem;
            color: var(--medium-gray);
            text-align: center;
        }

        .sidebar.collapsed .sidebar-footer {
            display: none;
        }

        /* Main Content */
        .main-content {
            margin-left: var(--sidebar-width);
            margin-top: var(--header-height);
            padding: 2rem;
            min-height: calc(100vh - var(--header-height));
            transition: var(--transition);
        }

        .main-content.collapsed {
            margin-left: var(--sidebar-collapsed-width);
        }

        .page-header {
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .page-title {
            font-size: 2rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .welcome-text {
            color: var(--secondary);
            font-size: 1rem;
        }

        /* Tabs */
        .tabs-container {
            margin-bottom: 2rem;
        }

        .tabs-nav {
            display: flex;
            gap: 0.5rem;
            border-bottom: 2px solid var(--light-gray);
            margin-bottom: 1.5rem;
        }

        .tab-btn {
            padding: 1rem 1.5rem;
            background: none;
            border: none;
            color: var(--secondary);
            font-weight: 500;
            cursor: pointer;
            border-radius: 8px 8px 0 0;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .tab-btn:hover {
            background: var(--light);
            color: var(--primary);
        }

        .tab-btn.active {
            background: var(--primary);
            color: white;
            border-bottom: 3px solid var(--primary-dark);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Statistics Cards */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stats-card {
            background: white;
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            text-align: center;
            transition: var(--transition);
        }

        .stats-card:hover {
            box-shadow: var(--card-shadow-hover);
            transform: translateY(-2px);
        }

        .stats-number {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .stats-label {
            color: var(--secondary);
            font-weight: 500;
            font-size: 0.9rem;
        }

        /* Filter Section */
        .filter-section {
            background: white;
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            margin-bottom: 2rem;
        }

        .filter-row {
            display: flex;
            gap: 1rem;
            align-items: end;
            flex-wrap: wrap;
        }

        .filter-group {
            flex: 1;
            min-width: 200px;
        }

        .filter-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--dark);
        }

        .filter-group input,
        .filter-group select {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--light-gray);
            border-radius: 6px;
            font-size: 0.9rem;
            transition: var(--transition);
        }

        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        /* Buttons */
        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
        }

        .btn-secondary {
            background: var(--secondary);
            color: white;
        }

        .btn-secondary:hover {
            background: #475569;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            background: #dc2626;
        }

        /* Table Styles */
        .table-section {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            overflow: hidden;
        }

        .circulation-table {
            width: 100%;
            border-collapse: collapse;
        }

        .circulation-table th {
            background: var(--light);
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: var(--dark);
            border-bottom: 2px solid var(--light-gray);
        }

        .circulation-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--light-gray);
            vertical-align: middle;
        }

        .circulation-table tbody tr:hover {
            background: var(--light);
        }

        /* Status badges */
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
            text-transform: capitalize;
        }

        .status-badge.borrowed {
            background: #fef3c7;
            color: #92400e;
        }

        .status-badge.returned {
            background: #d1fae5;
            color: #065f46;
        }

        .status-badge.overdue {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-badge.lost {
            background: #f3f4f6;
            color: #374151;
        }

        .status-badge.active {
            background: #dbeafe;
            color: #1e40af;
        }

        .status-badge.fulfilled {
            background: #d1fae5;
            color: #065f46;
        }

        .status-badge.expired {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-badge.cancelled {
            background: #f3f4f6;
            color: #374151;
        }

        .status-badge.available {
            background: #d1fae5;
            color: #065f46;
        }

        .status-badge.borrowed {
            background: #fef3c7;
            color: #92400e;
        }

        .status-badge.reserved {
            background: #dbeafe;
            color: #1e40af;
        }

        /* Row colors */
        .row-overdue {
            background-color: #fef2f2 !important;
        }

        .row-borrowed {
            background-color: #fffbeb !important;
        }

        .row-returned {
            background-color: #f0fdf4 !important;
        }

        .row-active-reservation {
            background-color: #eff6ff !important;
        }

        .row-fulfilled {
            background-color: #f0fdf4 !important;
        }

        .row-expired {
            background-color: #fef2f2 !important;
        }

        /* Action buttons */
        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }

        .btn-action {
            width: 32px;
            height: 32px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: var(--transition);
        }

        .btn-return {
            background: var(--success);
            color: white;
        }

        .btn-return:hover {
            background: #059669;
        }

        .btn-view {
            background: var(--accent);
            color: white;
        }

        .btn-view:hover {
            background: #0284c7;
        }

        .btn-fine {
            background: var(--warning);
            color: white;
        }

        .btn-fine:hover {
            background: #d97706;
        }

        .btn-fulfill {
            background: var(--success);
            color: white;
        }

        .btn-fulfill:hover {
            background: #059669;
        }

        .btn-cancel {
            background: var(--danger);
            color: white;
        }

        .btn-cancel:hover {
            background: #dc2626;
        }

        /* Text colors */
        .text-success { color: var(--success); }
        .text-warning { color: var(--warning); }
        .text-danger { color: var(--danger); }
        .text-secondary { color: var(--secondary); }
        .text-muted { color: var(--medium-gray); }
        .text-info { color: var(--accent); }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            animation: fadeIn 0.3s ease;
        }

        .modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            max-width: 600px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
            animation: slideIn 0.3s ease;
        }

        .modal-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--light-gray);
        }

        .modal-header h2 {
            margin: 0;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            padding: 1.5rem;
            border-top: 1px solid var(--light-gray);
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
        }

        /* Alert Messages */
        .alert {
            position: fixed;
            top: 80px;
            right: 20px;
            min-width: 300px;
            max-width: 500px;
            padding: 1rem 1.5rem;
            border-radius: 8px;
            color: white;
            font-weight: 500;
            z-index: 1001;
            transform: translateX(100%);
            transition: transform 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .alert.show {
            transform: translateX(0);
        }

        .alert-success {
            background: var(--success);
        }

        .alert-danger {
            background: var(--danger);
        }

        .alert-warning {
            background: var(--warning);
        }

        .alert-info {
            background: var(--accent);
        }

        /* Form elements */
        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--dark);
        }

        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--light-gray);
            border-radius: 6px;
            font-size: 0.9rem;
            transition: var(--transition);
        }

        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        /* Responsive Design */
        @media (max-width: 1024px) {
            .sidebar {
                width: var(--sidebar-collapsed-width);
            }
            
            .main-content {
                margin-left: var(--sidebar-collapsed-width);
            }
            
            .filter-row {
                flex-direction: column;
                align-items: stretch;
            }

            .filter-group {
                width: 100%;
            }

            .alert {
                min-width: 300px;
                max-width: 90vw;
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                width: var(--sidebar-collapsed-width);
            }
            
            .main-content {
                margin-left: var(--sidebar-collapsed-width);
            }
            
            .menu-item span {
                display: none;
            }
            
            .menu-item {
                text-align: center;
                padding: 12px;
                justify-content: center;
            }
            
            .menu-item i {
                margin-right: 0;
            }
            
            .page-header {
                flex-direction: column;
                align-items: stretch;
                gap: 1rem;
            }
            
            .stats-row {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .action-buttons {
                flex-direction: column;
                gap: 0.25rem;
            }

            .alert {
                min-width: 250px;
                max-width: 90vw;
                top: 70px;
            }
        }

        @media (max-width: 576px) {
            .stats-row {
                grid-template-columns: 1fr;
            }
            
            .page-title {
                font-size: 1.5rem;
            }

            .alert {
                min-width: 200px;
                max-width: 95vw;
                top: 60px;
            }
        }

        /* Badge */
        .badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            font-size: 0.85rem;
            font-weight: 500;
            border-radius: 3px;
        }

        /* Loading State */
        .loading {
            text-align: center;
            padding: 2rem;
            color: var(--medium-gray);
        }

        .loading i {
            font-size: 2rem;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
    </style>

</head>
<body>
    <!-- HEADER - KEEPING ORIGINAL -->
    <header class="header">
        <div class="header-left">
            <button id="sidebarToggle" class="toggle-sidebar">
                <i class="fas fa-bars"></i>
            </button>
            <div class="logo-container">
                <div class="school-logo"></div>
                <div class="logo-text">SMK <span>Chendering</span></div>
            </div>
        </div>
        <div class="header-right">
            <div class="user-menu" id="userMenu">
                <div class="user-avatar">
                    <?php echo strtoupper(substr($librarian_name, 0, 1)); ?>
                </div>
                <div class="user-info">
                    <div class="user-name"><?php echo htmlspecialchars($librarian_name); ?></div>
                    <div class="user-role">Librarian</div>
                </div>
            </div>
            <a href="logout.php" class="logout-btn" style="margin-left: 1rem; background: var(--danger); padding: 0.5rem 1rem; border-radius: 5px; color: white; text-decoration: none; display: flex; align-items: center; gap: 6px;">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </header>

    <!-- SIDEBAR - KEEPING ORIGINAL -->
    <aside class="sidebar" id="sidebar">
        <!-- [ORIGINAL SIDEBAR CONTENT] -->
        <nav class="sidebar-menu">
            <a href="librarian_dashboard.php" class="menu-item">
                <i class="fas fa-home"></i>
                <span>Dashboard</span>
            </a>
            <a href="user_management.php" class="menu-item">
                <i class="fas fa-users"></i>
                <span>User Management</span>
            </a>
            <a href="book_management.php" class="menu-item">
                <i class="fas fa-book"></i>
                <span>Book Management</span>
            </a>
            <a href="circulation_control.php" class="menu-item active">
                <i class="fas fa-exchange-alt"></i>
                <span>Circulation Control</span>
            </a>
            <a href="fine_management.php" class="menu-item">
                <i class="fas fa-receipt"></i>
                <span>Fine Management</span>
            </a>
            <a href="report_management.php" class="menu-item">
                <i class="fas fa-chart-bar"></i>
                <span>Reports & Analytics</span>
            </a>
            <a href="system_settings.php" class="menu-item">
                <i class="fas fa-cog"></i>
                <span>System Settings</span>
            </a>
            <a href="notifications.php" class="menu-item">
                <i class="fas fa-bell"></i>
                <span>Notifications</span>
            </a>
            <a href="profile.php" class="menu-item">
                <i class="fas fa-user"></i>
                <span>Profile</span>
            </a>
        </nav>
        <div class="sidebar-footer">
            <p>SMK Chendering Library <span>v1.0</span><br>Library Management System</p>
        </div>
    </aside>

    <main class="main-content" id="mainContent">
        <div class="page-header">
            <div>
                <h1 class="page-title">Circulation Control</h1>
                <p class="welcome-text">Monitor book borrowing, returns, reservations and circulation activities</p>
            </div>
        </div>

        <div id="alertMessage" class="alert"></div>

        <div class="tabs-container">
            <div class="tabs-nav">
                <button class="tab-btn active" onclick="switchTab('borrow')" data-tab="borrow">
                    <i class="fas fa-book-reader"></i> Borrow Records
                </button>
                <button class="tab-btn" onclick="switchTab('reservation')" data-tab="reservation">
                    <i class="fas fa-bookmark"></i> Reservations
                </button>
            </div>
        </div>

<!-- TO BE CONTINUED IN PART 2 DUE TO LENGTH -->

        <!-- Borrow Records Tab -->
        <div id="borrowTab" class="tab-content active">
            <!-- Statistics Cards -->
            <div class="stats-row">
                <div class="stats-card">
                    <div class="stats-number text-warning"><?php echo $stats['total_borrowed']; ?></div>
                    <div class="stats-label">Currently Borrowed</div>
                </div>
                <div class="stats-card">
                    <div class="stats-number text-success"><?php echo $stats['total_returned']; ?></div>
                    <div class="stats-label">Returned Books</div>
                </div>
                <div class="stats-card">
                    <div class="stats-number text-danger"><?php echo $stats['total_overdue']; ?></div>
                    <div class="stats-label">Overdue Books</div>
                </div>
                <div class="stats-card">
                    <div class="stats-number text-secondary"><?php echo $stats['total_lost']; ?></div>
                    <div class="stats-label">Lost Books</div>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="filter-section">
                <form id="filterForm" method="GET">
                    <div class="filter-row">
                        <div class="filter-group">
                            <label for="status">Status</label>
                            <select id="status" name="status">
                                <option value="all" <?php echo $status_filter == 'all' ? 'selected' : ''; ?>>All Status</option>
                                <option value="borrowed" <?php echo $status_filter == 'borrowed' ? 'selected' : ''; ?>>Borrowed</option>
                                <option value="returned" <?php echo $status_filter == 'returned' ? 'selected' : ''; ?>>Returned</option>
                                <option value="overdue" <?php echo $status_filter == 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                                <option value="lost" <?php echo $status_filter == 'lost' ? 'selected' : ''; ?>>Lost</option>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label for="date_from">From Date</label>
                            <input type="date" id="date_from" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                        </div>
                        
                        <div class="filter-group">
                            <label for="date_to">To Date</label>
                            <input type="date" id="date_to" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                        </div>
                        
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        
                        <button type="button" class="btn btn-secondary" onclick="clearFilters()">
                            <i class="fas fa-times"></i> Clear
                        </button>
                    </div>
                </form>
            </div>

            <!-- Borrow Records Table -->
            <div class="table-section">
                <table class="circulation-table" id="borrowTable">
                    <thead>
                        <tr>
                            <th>Borrow ID</th>
                            <th>Student/Staff ID</th>
                            <th>Borrower</th>
                            <th>Book Title</th>
                            <th>Borrow Date</th>
                            <th>Due Date</th>
                            <th>Return Date</th>
                            <th>Status</th>
                            <th>Fine (RM)</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($result && $result->num_rows > 0): ?>
                        <?php while ($row = $result->fetch_assoc()): ?>
                            <?php
                                $row_class = '';
                                if ($row['borrow_status'] == 'borrowed' && $row['days_overdue'] > 0) {
                                    $row_class = 'row-overdue';
                                } elseif ($row['borrow_status'] == 'borrowed') {
                                    $row_class = 'row-borrowed';
                                } elseif ($row['borrow_status'] == 'returned') {
                                    $row_class = 'row-returned';
                                }
                                
                                $display_status = $row['borrow_status'];
                                if ($row['borrow_status'] == 'borrowed' && $row['days_overdue'] > 0) {
                                    $display_status = 'overdue';
                                }
                            ?>
                            <tr class="<?php echo $row_class; ?>" id="row-<?php echo $row['borrowID']; ?>">
                                <td><?php echo formatBorrowID($row['borrowID']); ?></td>
                                <td>
                                    <span class="badge" style="background: var(--primary); color: white; padding: 0.25rem 0.5rem; border-radius: 3px; font-size: 0.85rem;">
                                        <?php echo htmlspecialchars($row['id_number']); ?>
                                    </span>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?></strong><br>
                                    <small class="text-muted"><?php echo ucfirst($row['user_type']); ?></small>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($row['bookTitle']); ?></strong><br>
                                    <small class="text-muted"><?php echo htmlspecialchars($row['bookAuthor']); ?></small>
                                </td>
                                <td><?php echo date('d M Y', strtotime($row['borrow_date'])); ?></td>
                                <td>
                                    <?php echo date('d M Y', strtotime($row['due_date'])); ?>
                                    <?php if ($row['days_overdue'] > 0 && $row['borrow_status'] == 'borrowed'): ?>
                                        <br><small class="text-danger"><?php echo $row['days_overdue']; ?> days overdue</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo $row['return_date'] ? date('d M Y', strtotime($row['return_date'])) : '-'; ?>
                                </td>
                                <td>
                                    <?php if ($row['borrow_status'] == 'borrowed' && $row['days_overdue'] == 0): ?>
                                        <!-- FIXED: Editable status dropdown for borrowed (not overdue) books -->
                                        <select class="status-select" onchange="updateBorrowStatus(<?php echo $row['borrowID']; ?>, this.value, this)">
                                            <option value="borrowed" selected>Borrowed</option>
                                            <option value="lost">Lost</option>
                                        </select>
                                    <?php else: ?>
                                        <!-- Auto-updated statuses remain as badges -->
                                        <span class="status-badge <?php echo $display_status; ?>">
                                            <?php echo $display_status; ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php 
                                        $final_fine = $row['calculated_fine'] > 0 ? $row['calculated_fine'] : ($row['existing_fine'] ?? 0);
                                        if ($final_fine > 0) {
                                            echo number_format($final_fine, 2);
                                        } else {
                                            echo '-';
                                        }
                                    ?>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <?php if ($row['borrow_status'] == 'borrowed'): ?>
                                            <button class="btn-action btn-return" onclick="markAsReturned(<?php echo $row['borrowID']; ?>, <?php echo $row['bookID']; ?>)" title="Return Book">
                                                <i class="fas fa-check"></i>
                                            </button>
                                        <?php endif; ?>
                                        
                                        <button class="btn-action btn-view" onclick="viewDetails(<?php echo $row['borrowID']; ?>)" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        
                                        <?php if ($final_fine > 0): ?>
                                            <button class="btn-action btn-fine" onclick="manageFine('<?php echo htmlspecialchars($row['login_id']); ?>')" title="Manage Fine">
                                                <i class="fas fa-dollar-sign"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="10" style="text-align: center; padding: 2rem;">No borrowing records found for the selected filters.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>

        <!-- Reservation Records Tab -->
        <div id="reservationTab" class="tab-content">
            <!-- FIXED: Reservation Statistics with correct status values -->
            <div class="stats-row" style="margin-bottom: 2rem;">
                <div class="stats-card">
                    <div class="stats-number text-info"><?php echo $res_stats['waiting_reservations']; ?></div>
                    <div class="stats-label">Waiting in Queue</div>
                </div>
                <div class="stats-card">
                    <div class="stats-number text-success"><?php echo $res_stats['ready_reservations']; ?></div>
                    <div class="stats-label">Ready for Pickup</div>
                </div>
                <div class="stats-card">
                    <div class="stats-number text-success"><?php echo $res_stats['fulfilled_reservations']; ?></div>
                    <div class="stats-label">Fulfilled</div>
                </div>
                <div class="stats-card">
                    <div class="stats-number text-warning"><?php echo $res_stats['expired_reservations']; ?></div>
                    <div class="stats-label">Expired</div>
                </div>
                <div class="stats-card">
                    <div class="stats-number text-secondary"><?php echo $res_stats['cancelled_reservations']; ?></div>
                    <div class="stats-label">Cancelled</div>
                </div>
            </div>

            <!-- Reservation Filter -->
            <div class="filter-section">
                <div class="filter-row">
                    <div class="filter-group">
                        <label for="res_status">Filter by Status</label>
                        <select id="res_status" name="res_status" class="form-control">
                            <option value="">All Status</option>
                            <option value="waiting">Waiting</option>
                            <option value="ready">Ready for Pickup</option>
                            <option value="fulfilled">Fulfilled</option>
                            <option value="expired">Expired</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                    
                    <button type="button" class="btn btn-primary" onclick="filterReservations()">
                        <i class="fas fa-filter"></i> Apply Filter
                    </button>
                    
                    <button type="button" class="btn btn-secondary" onclick="clearReservationFilters()">
                        <i class="fas fa-times"></i> Clear
                    </button>
                </div>
            </div>

            <!-- Reservation Table -->
            <div class="table-section">
                <table class="circulation-table" id="reservationTable">
                    <thead>
                        <tr>
                            <th>Reservation ID</th>
                            <th>Reserver ID</th>
                            <th>Reserver Name</th>
                            <th>Book Title</th>
                            <th>Reservation Date</th>
                            <th>Pickup Deadline</th>
                            <th>Queue</th>
                            <th>Status</th>
                            <th>Book Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($reservation_result && $reservation_result->num_rows > 0): ?>
                            <?php while ($res = $reservation_result->fetch_assoc()): ?>
                                <?php
                                    $res_row_class = '';
                                    if ($res['display_status'] == 'waiting') {
                                        $res_row_class = 'row-active-reservation';
                                    } elseif ($res['display_status'] == 'fulfilled') {
                                        $res_row_class = 'row-fulfilled';
                                    } elseif ($res['display_status'] == 'expired') {
                                        $res_row_class = 'row-expired';
                                    }
                                ?>
                                <tr class="<?php echo $res_row_class; ?>" id="res-row-<?php echo $res['reservationID']; ?>">
                                    <td><?php echo formatReservationID($res['reservationID']); ?></td>
                                    <td>
                                        <span class="badge" style="background: var(--primary); color: white; padding: 0.25rem 0.5rem; border-radius: 3px; font-size: 0.85rem;">
                                            <?php echo htmlspecialchars($res['id_number']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($res['first_name'] . ' ' . $res['last_name']); ?></strong><br>
                                        <small class="text-muted"><?php echo ucfirst($res['user_type']); ?></small>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($res['bookTitle']); ?></strong><br>
                                        <small class="text-muted"><?php echo htmlspecialchars($res['bookAuthor']); ?></small>
                                    </td>
                                    <td><?php echo date('d M Y', strtotime($res['reservation_date'])); ?></td>
                                    <td>
                                        <?php if ($res['self_pickup_deadline']): ?>
                                            <?php echo date('d M Y', strtotime($res['self_pickup_deadline'])); ?>
                                            <?php if ($res['hours_until_expiry'] !== null): ?>
                                                <br><small class="text-<?php echo $res['hours_until_expiry'] <= 6 ? 'danger' : 'success'; ?>">
                                                    <?php echo abs($res['hours_until_expiry']); ?> hours <?php echo $res['hours_until_expiry'] > 0 ? 'left' : 'overdue'; ?>
                                                </small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($res['queue_position']): ?>
                                            <span class="badge" style="background: #667eea; color: white; padding: 0.25rem 0.5rem; border-radius: 3px;">
                                                #<?php echo $res['queue_position']; ?>
                                            </span>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo $res['display_status']; ?>">
                                            <?php echo $res['display_status']; ?>
                                        </span>
                                        <?php if ($res['notification_sent']): ?>
                                            <br><small class="text-success"><i class="fas fa-bell"></i> Notified</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo $res['bookStatus']; ?>">
                                            <?php echo $res['bookStatus']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <?php if ($res['reservation_status'] == 'waiting' && $res['bookStatus'] == 'available'): ?>
                                                <button class="btn-action btn-fulfill" onclick="fulfillReservation(<?php echo $res['reservationID']; ?>, <?php echo $res['bookID']; ?>, <?php echo $res['userID']; ?>)" title="Fulfill Reservation">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                            <?php endif; ?>
                                            
                                            <?php if (in_array($res['reservation_status'], ['waiting', 'ready'])): ?>
                                                <button class="btn-action btn-cancel" onclick="cancelReservation(<?php echo $res['reservationID']; ?>)" title="Cancel Reservation">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            <?php endif; ?>
                                            
                                            <button class="btn-action btn-view" onclick="viewReservationDetails(<?php echo $res['reservationID']; ?>)" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                             <tr>
                                <td colspan="10" style="text-align: center; padding: 2rem;">No reservation records found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <!-- Return Confirmation Modal -->
    <div id="returnModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-check-circle"></i> Confirm Return</h2>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to mark this book as returned?</p>
                <p id="returnBookInfo" style="margin-top: 1rem; font-weight: 500;"></p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('returnModal')">Cancel</button>
                <button class="btn btn-primary" id="confirmReturnBtn">Yes, Mark as Returned</button>
            </div>
        </div>
    </div>

    <!-- View Details Modal -->
    <div id="detailsModal" class="modal">
        <div class="modal-content" style="max-width: 900px;">
            <div class="modal-header">
                <h2><i class="fas fa-info-circle"></i> Borrowing Details</h2>
            </div>
            <div class="modal-body" id="detailsContent">
                <!-- Details will be loaded here via AJAX -->
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('detailsModal')">Close</button>
            </div>
        </div>
    </div>

    <!-- Fulfill Reservation Modal -->
    <div id="fulfillModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-book"></i> Fulfill Reservation</h2>
            </div>
            <div class="modal-body">
                <p>Convert this reservation to a borrow record?</p>
                <p id="fulfillReservationInfo" style="margin-top: 1rem; font-weight: 500;"></p>
                <div class="form-group" style="margin-top: 1rem;">
                    <label for="borrowPeriod">Borrow Period (days)</label>
                    <input type="number" id="borrowPeriod" name="borrowPeriod" value="14" min="1" max="30" class="form-control">
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('fulfillModal')">Cancel</button>
                <button class="btn btn-primary" id="confirmFulfillBtn">Confirm & Issue Book</button>
            </div>
        </div>
    </div>

    <!-- Cancel Reservation Modal -->
    <div id="cancelReservationModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-times-circle"></i> Cancel Reservation</h2>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to cancel this reservation?</p>
                <p id="cancelReservationInfo" style="margin-top: 1rem; font-weight: 500;"></p>
                <div class="form-group" style="margin-top: 1rem;">
                    <label for="cancellationReason">Cancellation Reason</label>
                    <textarea id="cancellationReason" name="cancellationReason" placeholder="Enter reason for cancellation..." class="form-control" style="min-height: 100px;"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('cancelReservationModal')">Close</button>
                <button class="btn btn-danger" id="confirmCancelReservationBtn">Yes, Cancel Reservation</button>
            </div>
        </div>
    </div>

    <!-- Reservation Details Modal -->
    <div id="reservationDetailsModal" class="modal">
        <div class="modal-content" style="max-width: 900px;">
            <div class="modal-header">
                <h2><i class="fas fa-bookmark"></i> Reservation Details</h2>
            </div>
            <div class="modal-body" id="reservationDetailsContent">
                <!-- Details will be loaded here via AJAX -->
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('reservationDetailsModal')">Close</button>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script>
        // Global variables
        let borrowDataTable = null;
        let reservationDataTable = null;
        let currentActiveTab = 'borrow';

        function debugLog(message, data = null) {
            if (data) {
                console.log(`[Circulation] ${message}:`, data);
            } else {
                console.log(`[Circulation] ${message}`);
            }
        }

        function showError(message, error = null) {
            console.error(`[Circulation Error] ${message}`, error);
            showAlert('danger', message);
        }

        // Initialize on document ready
        $(document).ready(function() {
            debugLog('System initialized successfully');
            
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            
            if (sidebarToggle) {
                sidebarToggle.addEventListener('click', function() {
                    sidebar.classList.toggle('collapsed');
                    mainContent.classList.toggle('collapsed');
                    localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
                    
                    setTimeout(function() {
                        if (borrowDataTable && currentActiveTab === 'borrow') {
                            borrowDataTable.columns.adjust().draw();
                        }
                        if (reservationDataTable && currentActiveTab === 'reservation') {
                            reservationDataTable.columns.adjust().draw();
                        }
                    }, 300);
                });
            }
            
            if (localStorage.getItem('sidebarCollapsed') === 'true') {
                sidebar.classList.add('collapsed');
                mainContent.classList.add('collapsed');
            }

            try {
                initializeBorrowTable();
                debugLog('Borrow table initialized');
            } catch (error) {
                showError('Failed to initialize borrow table', error);
            }
        });

        function initializeBorrowTable() {
            const table = $('#borrowTable');
            
            if (table.length === 0) {
                throw new Error('Borrow table not found in DOM');
            }

            if (borrowDataTable) {
                try {
                    borrowDataTable.destroy();
                    borrowDataTable = null;
                } catch (e) {
                    debugLog('No previous instance to destroy');
                }
            }

            const dataRows = table.find('tbody tr').filter(function() {
                return $(this).find('td[colspan]').length === 0;
            });
            
            const hasValidData = dataRows.length > 0;
            
            if (!hasValidData) {
                debugLog('No valid data rows - skipping DataTable initialization');
                return;
            }

            try {
                borrowDataTable = table.DataTable({
                    "pageLength": 10,
                    "lengthMenu": [[10, 25, 50, -1], [10, 25, 50, "All"]],
                    "order": [[4, "desc"]],
                    "columnDefs": [
                        { "orderable": false, "targets": [9] },
                        { "searchable": false, "targets": [9] }
                    ],
                    "language": {
                        "emptyTable": "No borrowing records found",
                        "zeroRecords": "No matching records found"
                    },
                    "dom": 'lrtip',
                    "autoWidth": false
                });
                
                debugLog('Borrow DataTable initialized successfully');
            } catch (error) {
                showError('DataTable initialization error', error);
                throw error;
            }
        }

        function initializeReservationTable() {
            const table = $('#reservationTable');
            
            if (table.length === 0) {
                throw new Error('Reservation table not found in DOM');
            }

            if (reservationDataTable) {
                try {
                    reservationDataTable.destroy();
                    reservationDataTable = null;
                } catch (e) {
                    debugLog('No previous reservation instance to destroy');
                }
            }

            const dataRows = table.find('tbody tr').filter(function() {
                return $(this).find('td[colspan]').length === 0;
            });
            
            const hasValidData = dataRows.length > 0;
            
            if (!hasValidData) {
                debugLog('No valid reservation data - skipping DataTable initialization');
                return;
            }

            try {
                reservationDataTable = table.DataTable({
                    "pageLength": 10,
                    "lengthMenu": [[10, 25, 50, -1], [10, 25, 50, "All"]],
                    "order": [[4, "desc"]],
                    "columnDefs": [
                        { "orderable": false, "targets": [9] },
                        { "searchable": false, "targets": [9] }
                    ],
                    "language": {
                        "emptyTable": "No reservation records found",
                        "zeroRecords": "No matching records found"
                    },
                    "dom": 'lrtip',
                    "autoWidth": false
                });
                
                debugLog('Reservation DataTable initialized successfully');
            } catch (error) {
                showError('Reservation DataTable initialization error', error);
                throw error;
            }
        }

        function switchTab(tabName) {
            try {
                debugLog('Switching to tab: ' + tabName);
                
                $('.tab-content').removeClass('active');
                $('.tab-btn').removeClass('active');
                
                if (tabName === 'borrow') {
                    $('#borrowTab').addClass('active');
                    $('[data-tab="borrow"]').addClass('active');
                    currentActiveTab = 'borrow';
                    
                    if (borrowDataTable) {
                        setTimeout(() => {
                            borrowDataTable.columns.adjust().draw();
                        }, 100);
                    }
                    
                } else if (tabName === 'reservation') {
                    $('#reservationTab').addClass('active');
                    $('[data-tab="reservation"]').addClass('active');
                    currentActiveTab = 'reservation';
                    
                    if (!reservationDataTable) {
                        setTimeout(() => {
                            try {
                                initializeReservationTable();
                            } catch (error) {
                                debugLog('Could not initialize reservation table');
                            }
                        }, 100);
                    } else {
                        setTimeout(() => {
                            reservationDataTable.columns.adjust().draw();
                        }, 100);
                    }
                }
                
                debugLog('Tab switch completed');
                
            } catch (error) {
                showError('Error during tab switch', error);
            }
        }

        function showAlert(type, message) {
            const alertDiv = document.getElementById('alertMessage');
            alertDiv.className = 'alert alert-' + type;
            alertDiv.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i> ${message}`;
            alertDiv.classList.add('show');
            
            setTimeout(() => {
                alertDiv.classList.remove('show');
            }, 5000);
        }

        function clearFilters() {
            document.getElementById('status').value = 'all';
            document.getElementById('date_from').value = '';
            document.getElementById('date_to').value = '';
            document.getElementById('filterForm').submit();
        }

        function clearReservationFilters() {
            document.getElementById('res_status').value = '';
            if (reservationDataTable) {
                reservationDataTable.columns(7).search('').draw();
            }
        }

        function filterReservations() {
            const status = document.getElementById('res_status').value;
            if (reservationDataTable) {
                reservationDataTable.columns(7).search(status).draw();
            }
        }

        // NEW: Update borrow status (manual change to "lost")
        function updateBorrowStatus(borrowID, newStatus, selectElement) {
            if (!confirm(`Are you sure you want to mark this book as ${newStatus}?\n\nThis will create a fine for the replacement cost.`)) {
                selectElement.value = 'borrowed';
                return;
            }
            
            selectElement.disabled = true;
            
            fetch('update_borrow_status.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'update_status',
                    borrowID: borrowID,
                    newStatus: newStatus
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert('success', data.message);
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showAlert('danger', data.message || 'Error updating status');
                    selectElement.value = 'borrowed';
                    selectElement.disabled = false;
                }
            })
            .catch(error => {
                showError('Network error during status update', error);
                selectElement.value = 'borrowed';
                selectElement.disabled = false;
            });
        }

        let currentBorrowId = null;
        let currentBookId = null;

        function markAsReturned(borrowId, bookId) {
            currentBorrowId = borrowId;
            currentBookId = bookId;
            const row = document.getElementById('row-' + borrowId);
            if (row) {
                const bookTitle = row.cells[3].querySelector('strong').textContent;
                const borrowerName = row.cells[2].querySelector('strong').textContent;
                document.getElementById('returnBookInfo').innerHTML = 
                    `Book: <strong>${bookTitle}</strong><br>Borrower: <strong>${borrowerName}</strong>`;
            }
            document.getElementById('returnModal').classList.add('show');
        }

        document.getElementById('confirmReturnBtn').addEventListener('click', function() {
            if (currentBorrowId && currentBookId) {
                this.disabled = true;
                this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                
                fetch('update_borrow_status.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'return',
                        borrowID: currentBorrowId,
                        bookID: currentBookId
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showAlert('success', data.message);
                        closeModal('returnModal');
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        showAlert('danger', data.message || 'Error processing return');
                    }
                })
                .catch(error => {
                    showError('Network error during return process', error);
                })
                .finally(() => {
                    this.disabled = false;
                    this.innerHTML = 'Yes, Mark as Returned';
                });
            }
        });

        function viewDetails(borrowId) {
            debugLog('Viewing details for borrowID: ' + borrowId);
            
            const detailsContent = document.getElementById('detailsContent');
            detailsContent.innerHTML = '<div class="loading"><i class="fas fa-spinner fa-spin"></i><p>Loading details...</p></div>';
            document.getElementById('detailsModal').classList.add('show');

            fetch(`get_borrow_details.php?borrowID=${encodeURIComponent(borrowId)}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    detailsContent.innerHTML = data.html;
                } else {
                    detailsContent.innerHTML = `
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle"></i>
                            <strong>Error:</strong> ${data.message || 'Failed to load borrowing details'}
                        </div>
                    `;
                }
            })
            .catch(error => {
                showError('Failed to load borrowing details', error);
                detailsContent.innerHTML = `
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>Network Error:</strong> Unable to fetch borrowing details.
                    </div>
                `;
            });
        }

        function manageFine(loginId) {
            window.open(`fine_management.php?user=${encodeURIComponent(loginId)}`, '_blank');
        }

        let currentReservationId = null;
        let currentReservationBookId = null;
        let currentReservationUserId = null;

        function fulfillReservation(reservationId, bookId, userId) {
            currentReservationId = reservationId;
            currentReservationBookId = bookId;
            currentReservationUserId = userId;
            
            const row = document.getElementById('res-row-' + reservationId);
            if (row) {
                const bookTitle = row.cells[3].querySelector('strong').textContent;
                const reserverName = row.cells[2].querySelector('strong').textContent;
                document.getElementById('fulfillReservationInfo').innerHTML = 
                    `Book: <strong>${bookTitle}</strong><br>Reserver: <strong>${reserverName}</strong>`;
            }
            document.getElementById('fulfillModal').classList.add('show');
        }

        document.getElementById('confirmFulfillBtn').addEventListener('click', function() {
            if (currentReservationId && currentReservationBookId && currentReservationUserId) {
                const borrowPeriod = document.getElementById('borrowPeriod').value;
                if (!borrowPeriod || borrowPeriod < 1) {
                    showAlert('danger', 'Please enter a valid borrow period.');
                    return;
                }

                this.disabled = true;
                this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                
                fetch('process_reservation.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'fulfill',
                        reservationID: currentReservationId,
                        bookID: currentReservationBookId,
                        userID: currentReservationUserId,
                        borrowPeriod: parseInt(borrowPeriod)
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showAlert('success', 'Reservation fulfilled successfully!');
                        closeModal('fulfillModal');
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        showAlert('danger', data.message || 'Error fulfilling reservation.');
                    }
                })
                .catch(error => {
                    showError('Network error during reservation fulfillment', error);
                })
                .finally(() => {
                    this.disabled = false;
                    this.innerHTML = 'Confirm & Issue Book';
                });
            }
        });

        function cancelReservation(reservationId) {
            currentReservationId = reservationId;
            const row = document.getElementById('res-row-' + reservationId);
            if (row) {
                const bookTitle = row.cells[3].querySelector('strong').textContent;
                const reserverName = row.cells[2].querySelector('strong').textContent;
                document.getElementById('cancelReservationInfo').innerHTML = 
                    `Book: <strong>${bookTitle}</strong><br>Reserver: <strong>${reserverName}</strong>`;
            }
            document.getElementById('cancelReservationModal').classList.add('show');
        }

        document.getElementById('confirmCancelReservationBtn').addEventListener('click', function() {
            if (currentReservationId) {
                const reason = document.getElementById('cancellationReason').value;
                if (!reason.trim()) {
                    showAlert('danger', 'Please provide a reason for cancellation.');
                    return;
                }

                this.disabled = true;
                this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                
                fetch('process_reservation.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'cancel',
                        reservationID: currentReservationId,
                        cancellationReason: reason
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showAlert('success', 'Reservation cancelled successfully!');
                        closeModal('cancelReservationModal');
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        showAlert('danger', data.message || 'Error cancelling reservation.');
                    }
                })
                .catch(error => {
                    showError('Network error during reservation cancellation', error);
                })
                .finally(() => {
                    this.disabled = false;
                    this.innerHTML = 'Yes, Cancel Reservation';
                });
            }
        });

        function viewReservationDetails(reservationId) {
            debugLog('Viewing reservation details for ID: ' + reservationId);
            
            const detailsContent = document.getElementById('reservationDetailsContent');
            detailsContent.innerHTML = '<div class="loading"><i class="fas fa-spinner fa-spin"></i><p>Loading details...</p></div>';
            document.getElementById('reservationDetailsModal').classList.add('show');

            fetch(`get_reservation_details.php?reservationID=${encodeURIComponent(reservationId)}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    detailsContent.innerHTML = data.html;
                } else {
                    detailsContent.innerHTML = `
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle"></i>
                            <strong>Error:</strong> ${data.message || 'Failed to load reservation details'}
                        </div>
                    `;
                }
            })
            .catch(error => {
                showError('Failed to load reservation details', error);
                detailsContent.innerHTML = `
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>Network Error:</strong> Unable to fetch reservation details.
                    </div>
                `;
            });
        }

        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.remove('show');
            }
        }

        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('show');
            }
        }

        $.fn.dataTable.ext.errMode = 'none';

        window.addEventListener('error', function(e) {
            debugLog('Global error caught', {
                message: e.message,
                filename: e.filename,
                lineno: e.lineno
            });
        });

        $.fn.dataTable.ext.errMode = function (settings, helpPage, message) {
            showError('DataTables Error: ' + message);
            debugLog('DataTables error details', { settings, helpPage, message });
        };
    </script>
</body>
</html>