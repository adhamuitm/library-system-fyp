<?php
session_start();
require_once 'dbconnect.php';
require_once 'auth_helper.php';
require_once __DIR__ . '/vendor/autoload.php';

use Mpdf\Mpdf;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Security: Check authentication and authorization
checkPageAccess();
requireRole('librarian');

// Get current librarian info
$librarian_info = getCurrentUser();
$librarian_name = getUserDisplayName();

// Database: Get librarian ID for logging
$librarian_id_query = "SELECT librarianID FROM librarian WHERE librarianEmail = ?";
$stmt = $conn->prepare($librarian_id_query);
$stmt->bind_param("s", $librarian_info['email']);
$stmt->execute();
$result = $stmt->get_result();
$librarian_data = $result->fetch_assoc();
$current_librarian_id = $librarian_data['librarianID'] ?? 1;
$stmt->close();

// CSRF Token generation
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle AJAX requests
if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    
    // CSRF validation for POST requests
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'message' => 'Security token validation failed']);
        exit;
    }
    
    try {
        switch ($_POST['ajax_action']) {
            case 'get_summary_stats':
                echo json_encode(getSummaryStats($_POST));
                break;
            case 'get_chart_data':
                echo json_encode(getChartData($_POST));
                break;
            case 'get_top_borrowers':
                echo json_encode(getTopBorrowers($_POST));
                break;
            case 'get_popular_books':
                echo json_encode(getPopularBooks($_POST));
                break;
            case 'get_user_activity_stats':
                echo json_encode(getUserActivityStats($_POST));
                break;
            case 'get_financial_stats':
                echo json_encode(getFinancialStats($_POST));
                break;
            case 'get_book_status_stats':
                echo json_encode(getBookStatusStats());
                break;
            case 'generate_certificate':
                echo json_encode(generateCertificate($_POST));
                break;
            case 'get_class_students':
                echo json_encode(getClassStudents($_POST));
                break;
            case 'export_class_list':
                echo json_encode(exportClassList($_POST));
                break;
            case 'preview_custom_report':
                echo json_encode(previewCustomReport($_POST));
                break;
            case 'export_custom_report':
                echo json_encode(exportCustomReport($_POST));
                break;
            case 'save_template':
                echo json_encode(saveTemplate($_POST));
                break;
            case 'load_template':
                echo json_encode(loadTemplate($_POST));
                break;
            case 'delete_template':
                echo json_encode(deleteTemplate($_POST));
                break;
            case 'export_analytics':
                echo json_encode(exportAnalytics($_POST));
                break;
            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
    } catch (Exception $e) {
        error_log("AJAX Error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error occurred']);
    }
    exit;
}

// Enhanced Summary Statistics
function getSummaryStats($params) {
    global $conn;
    
    $dateFrom = $params['date_from'] ?? date('Y-m-01');
    $dateTo = $params['date_to'] ?? date('Y-m-d');
    $classFilter = $params['class_filter'] ?? 'all';
    
    $data = [];
    
    // Total Books (excluding disposed)
    $query = "SELECT COUNT(*) as count FROM book WHERE bookStatus != 'disposed'";
    $result = $conn->query($query);
    $data['total_books'] = $result->fetch_assoc()['count'] ?? 0;
    
    // Total Borrowed Books (period)
    $query = "SELECT COUNT(*) as count FROM borrow b 
              JOIN user u ON b.userID = u.userID 
              LEFT JOIN student s ON u.userID = s.userID 
              WHERE b.borrow_date BETWEEN ? AND ?";
    $stmt = $conn->prepare($query);
    
    if ($classFilter !== 'all') {
        $query .= " AND s.studentClass = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("sss", $dateFrom, $dateTo, $classFilter);
    } else {
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ss", $dateFrom, $dateTo);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $data['borrowed_books'] = $result->fetch_assoc()['count'] ?? 0;
    $stmt->close();
    
    // Overdue Books (current)
    $query = "SELECT COUNT(*) as count FROM borrow WHERE borrow_status = 'overdue'";
    $result = $conn->query($query);
    $data['overdue_books'] = $result->fetch_assoc()['count'] ?? 0;
    
    // Active Users (period)
    $query = "SELECT COUNT(DISTINCT b.userID) as count FROM borrow b 
              JOIN user u ON b.userID = u.userID 
              LEFT JOIN student s ON u.userID = s.userID 
              WHERE b.borrow_date BETWEEN ? AND ?";
    $stmt = $conn->prepare($query);
    
    if ($classFilter !== 'all') {
        $query .= " AND s.studentClass = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("sss", $dateFrom, $dateTo, $classFilter);
    } else {
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ss", $dateFrom, $dateTo);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $data['active_users'] = $result->fetch_assoc()['count'] ?? 0;
    $stmt->close();
    
    return ['success' => true, 'data' => $data];
}

// Chart Data Functions
function getChartData($params) {
    global $conn;
    
    $dateFrom = $params['date_from'] ?? date('Y-m-01');
    $dateTo = $params['date_to'] ?? date('Y-m-d');
    $classFilter = $params['class_filter'] ?? 'all';
    
    return [
        'success' => true,
        'data' => [
            'monthly_borrowed' => getMonthlyBorrowed($dateFrom, $dateTo, $classFilter),
            'overdue_trends' => getOverdueTrends($dateFrom, $dateTo, $classFilter),
            'top_categories' => getTopCategories($dateFrom, $dateTo, $classFilter),
            'fines_collected' => getFinesCollected($dateFrom, $dateTo, $classFilter)
        ]
    ];
}

function getMonthlyBorrowed($dateFrom, $dateTo, $classFilter) {
    global $conn;
    
    $query = "SELECT DATE_FORMAT(b.borrow_date, '%b %Y') as month, 
              COUNT(*) as count,
              SUM(CASE WHEN u.user_type = 'student' THEN 1 ELSE 0 END) as student_count,
              SUM(CASE WHEN u.user_type = 'staff' THEN 1 ELSE 0 END) as staff_count
              FROM borrow b
              JOIN user u ON b.userID = u.userID
              LEFT JOIN student s ON u.userID = s.userID
              WHERE b.borrow_date BETWEEN ? AND ?";
    
    $params = [$dateFrom, $dateTo];
    if ($classFilter !== 'all') {
        $query .= " AND s.studentClass = ?";
        $params[] = $classFilter;
    }
    
    $query .= " GROUP BY DATE_FORMAT(b.borrow_date, '%Y-%m')
                ORDER BY DATE_FORMAT(b.borrow_date, '%Y-%m')";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param(str_repeat('s', count($params)), ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    $stmt->close();
    
    return $data;
}

function getOverdueTrends($dateFrom, $dateTo, $classFilter) {
    global $conn;
    
    $query = "SELECT DATE_FORMAT(b.due_date, '%b %Y') as month, COUNT(*) as count
              FROM borrow b
              JOIN user u ON b.userID = u.userID
              LEFT JOIN student s ON u.userID = s.userID
              WHERE b.borrow_status = 'overdue' AND b.due_date BETWEEN ? AND ?";
    
    $params = [$dateFrom, $dateTo];
    if ($classFilter !== 'all') {
        $query .= " AND s.studentClass = ?";
        $params[] = $classFilter;
    }
    
    $query .= " GROUP BY DATE_FORMAT(b.due_date, '%Y-%m')
                ORDER BY DATE_FORMAT(b.due_date, '%Y-%m')";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param(str_repeat('s', count($params)), ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    $stmt->close();
    
    return $data;
}

function getTopCategories($dateFrom, $dateTo, $classFilter) {
    global $conn;
    
    $query = "SELECT bc.categoryName, COUNT(b.borrowID) as count
              FROM borrow b
              JOIN book bk ON b.bookID = bk.bookID
              JOIN book_category bc ON bk.categoryID = bc.categoryID
              JOIN user u ON b.userID = u.userID
              LEFT JOIN student s ON u.userID = s.userID
              WHERE b.borrow_date BETWEEN ? AND ?";
    
    $params = [$dateFrom, $dateTo];
    if ($classFilter !== 'all') {
        $query .= " AND s.studentClass = ?";
        $params[] = $classFilter;
    }
    
    $query .= " GROUP BY bc.categoryID
                ORDER BY count DESC
                LIMIT 8";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param(str_repeat('s', count($params)), ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    $stmt->close();
    
    return $data;
}

function getFinesCollected($dateFrom, $dateTo, $classFilter) {
    global $conn;
    
    $query = "SELECT DATE_FORMAT(f.payment_date, '%b %Y') as month, 
              SUM(f.amount_paid) as total
              FROM fines f
              JOIN user u ON f.userID = u.userID
              LEFT JOIN student s ON u.userID = s.userID
              WHERE f.payment_date BETWEEN ? AND ? AND f.payment_status = 'paid_cash'";
    
    $params = [$dateFrom, $dateTo];
    if ($classFilter !== 'all') {
        $query .= " AND s.studentClass = ?";
        $params[] = $classFilter;
    }
    
    $query .= " GROUP BY DATE_FORMAT(f.payment_date, '%Y-%m')
                ORDER BY DATE_FORMAT(f.payment_date, '%Y-%m')";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param(str_repeat('s', count($params)), ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    $stmt->close();
    
    return $data;
}

// Get Popular Books for Top 10
function getPopularBooks($params) {
    global $conn;
    
    $dateFrom = $params['date_from'] ?? date('Y-m-01');
    $dateTo = $params['date_to'] ?? date('Y-m-d');
    $classFilter = $params['class_filter'] ?? 'all';
    
    $query = "SELECT 
              bk.bookTitle, 
              bk.bookAuthor,
              COUNT(b.borrowID) as borrow_count
              FROM borrow b
              JOIN book bk ON b.bookID = bk.bookID
              JOIN user u ON b.userID = u.userID
              LEFT JOIN student s ON u.userID = s.userID
              WHERE b.borrow_date BETWEEN ? AND ?";
    
    $params = [$dateFrom, $dateTo];
    if ($classFilter !== 'all') {
        $query .= " AND s.studentClass = ?";
        $params[] = $classFilter;
    }
    
    $query .= " GROUP BY bk.bookID
                ORDER BY borrow_count DESC
                LIMIT 10";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param(str_repeat('s', count($params)), ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    $stmt->close();
    
    return ['success' => true, 'books' => $data];
}

// User Activity Stats
function getUserActivityStats($params) {
    global $conn;
    
    $dateFrom = $params['date_from'] ?? date('Y-m-01');
    $dateTo = $params['date_to'] ?? date('Y-m-d');
    
    // Active vs Inactive users
    $activeUsersQuery = "SELECT COUNT(DISTINCT userID) as active FROM borrow 
                        WHERE borrow_date BETWEEN ? AND ?";
    $stmt = $conn->prepare($activeUsersQuery);
    $stmt->bind_param("ss", $dateFrom, $dateTo);
    $stmt->execute();
    $active = $stmt->get_result()->fetch_assoc()['active'] ?? 0;
    $stmt->close();
    
    $totalUsersQuery = "SELECT COUNT(*) as total FROM user WHERE account_status = 'active'";
    $totalUsers = $conn->query($totalUsersQuery)->fetch_assoc()['total'] ?? 0;
    
    // Student vs Staff ratio
    $ratioQuery = "SELECT 
                   SUM(CASE WHEN user_type = 'student' THEN 1 ELSE 0 END) as students,
                   SUM(CASE WHEN user_type = 'staff' THEN 1 ELSE 0 END) as staff
                   FROM user WHERE account_status = 'active'";
    $ratio = $conn->query($ratioQuery)->fetch_assoc();
    
    // Users with overdue books
    $overdueUsersQuery = "SELECT COUNT(DISTINCT userID) as overdue_users FROM borrow 
                         WHERE borrow_status = 'overdue'";
    $overdueUsers = $conn->query($overdueUsersQuery)->fetch_assoc()['overdue_users'] ?? 0;
    
    // Users with unpaid fines
    $unpaidFinesQuery = "SELECT COUNT(DISTINCT userID) as users_with_fines FROM fines 
                        WHERE payment_status = 'unpaid'";
    $unpaidFinesUsers = $conn->query($unpaidFinesQuery)->fetch_assoc()['users_with_fines'] ?? 0;
    
    return [
        'success' => true,
        'data' => [
            'active_users' => $active,
            'inactive_users' => max(0, $totalUsers - $active),
            'total_users' => $totalUsers,
            'student_count' => $ratio['students'] ?? 0,
            'staff_count' => $ratio['staff'] ?? 0,
            'users_with_overdue' => $overdueUsers,
            'users_with_unpaid_fines' => $unpaidFinesUsers
        ]
    ];
}

// Financial Stats
function getFinancialStats($params) {
    global $conn;
    
    $dateFrom = $params['date_from'] ?? date('Y-m-01');
    $dateTo = $params['date_to'] ?? date('Y-m-d');
    
    // Total fines collected
    $collectedQuery = "SELECT SUM(amount_paid) as total FROM fines 
                      WHERE payment_date BETWEEN ? AND ? AND payment_status = 'paid_cash'";
    $stmt = $conn->prepare($collectedQuery);
    $stmt->bind_param("ss", $dateFrom, $dateTo);
    $stmt->execute();
    $collected = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
    $stmt->close();
    
    // Outstanding fines
    $outstandingQuery = "SELECT SUM(balance_due) as total FROM fines WHERE payment_status = 'unpaid'";
    $outstanding = $conn->query($outstandingQuery)->fetch_assoc()['total'] ?? 0;
    
    // Fine types breakdown
    $typesQuery = "SELECT fine_reason, SUM(fine_amount) as total 
                   FROM fines WHERE fine_date BETWEEN ? AND ?
                   GROUP BY fine_reason";
    $stmt = $conn->prepare($typesQuery);
    $stmt->bind_param("ss", $dateFrom, $dateTo);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $types = [];
    while ($row = $result->fetch_assoc()) {
        $types[] = $row;
    }
    $stmt->close();
    
    // Monthly revenue trend
    $trendQuery = "SELECT DATE_FORMAT(payment_date, '%b %Y') as month,
                   SUM(amount_paid) as total
                   FROM fines
                   WHERE payment_date BETWEEN ? AND ? AND payment_status = 'paid_cash'
                   GROUP BY DATE_FORMAT(payment_date, '%Y-%m')
                   ORDER BY DATE_FORMAT(payment_date, '%Y-%m')";
    $stmt = $conn->prepare($trendQuery);
    $stmt->bind_param("ss", $dateFrom, $dateTo);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $trend = [];
    while ($row = $result->fetch_assoc()) {
        $trend[] = $row;
    }
    $stmt->close();
    
    return [
        'success' => true,
        'data' => [
            'total_collected' => $collected,
            'total_outstanding' => $outstanding,
            'fine_types' => $types,
            'revenue_trend' => $trend
        ]
    ];
}

// Book Status Statistics
function getBookStatusStats() {
    global $conn;
    
    $query = "SELECT 
              SUM(CASE WHEN bookStatus = 'available' THEN 1 ELSE 0 END) as available,
              SUM(CASE WHEN bookStatus = 'borrowed' THEN 1 ELSE 0 END) as borrowed,
              SUM(CASE WHEN bookStatus = 'reserved' THEN 1 ELSE 0 END) as reserved,
              SUM(CASE WHEN bookStatus = 'maintenance' THEN 1 ELSE 0 END) as maintenance,
              SUM(CASE WHEN bookStatus = 'disposed' THEN 1 ELSE 0 END) as disposed
              FROM book";
    
    $result = $conn->query($query);
    return ['success' => true, 'data' => $result->fetch_assoc()];
}

// Top Borrowers with enhanced data
function getTopBorrowers($params) {
    global $conn;
    
    $dateFrom = $params['date_from'] ?? date('Y-m-01');
    $dateTo = $params['date_to'] ?? date('Y-m-d');
    $classFilter = $params['class_filter'] ?? 'all';
    
    $query = "SELECT 
              u.userID,
              CONCAT(u.first_name, ' ', u.last_name) as name,
              u.user_type,
              s.student_id_number,
              s.studentClass,
              s.student_image,
              st.staff_id_number,
              COUNT(b.borrowID) as borrow_count
              FROM borrow b
              JOIN user u ON b.userID = u.userID
              LEFT JOIN student s ON u.userID = s.userID
              LEFT JOIN staff st ON u.userID = st.userID
              WHERE b.borrow_date BETWEEN ? AND ?";
    
    $params = [$dateFrom, $dateTo];
    if ($classFilter !== 'all') {
        $query .= " AND s.studentClass = ?";
        $params[] = $classFilter;
    }
    
    $query .= " GROUP BY u.userID
                ORDER BY borrow_count DESC
                LIMIT 3";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param(str_repeat('s', count($params)), ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $borrowers = [];
    while ($row = $result->fetch_assoc()) {
        // Add profile image if exists
        $row['profile_image'] = $row['student_image'] ?? ($row['user_type'] === 'student' ? 'photo/student/default.png' : 'photo/staff/default.png');
        $borrowers[] = $row;
    }
    $stmt->close();
    
    return ['success' => true, 'borrowers' => $borrowers];
}

// Certificate Generation
function generateCertificate($params) {
    global $conn, $librarian_name;
    
    $studentName = $params['student_name'] ?? '';
    $className = $params['class_name'] ?? '';
    $rank = $params['rank'] ?? '';
    $userID = $params['user_id'] ?? 0;
    
    // Get borrow count for personalization
    $borrowCount = 0;
    if ($userID > 0) {
        $query = "SELECT COUNT(*) as count FROM borrow WHERE userID = ? 
                  AND borrow_date BETWEEN DATE_SUB(NOW(), INTERVAL 1 MONTH) AND NOW()";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $userID);
        $stmt->execute();
        $result = $stmt->get_result();
        $borrowCount = $result->fetch_assoc()['count'] ?? 0;
        $stmt->close();
    }
    
    $mpdf = new Mpdf([
        'format' => 'A4-L',
        'margin_left' => 15,
        'margin_right' => 15,
        'margin_top' => 15,
        'margin_bottom' => 15
    ]);
    
    $html = '
    <html>
    <head>
    <style>
    body {
      font-family: "Times New Roman", serif;
      text-align: center;
      padding: 40px;
      background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
    }
    .certificate-container {
      border: 12px double #1e3a8a;
      padding: 50px;
      background: white;
      box-shadow: 0 10px 30px rgba(0,0,0,0.1);
      position: relative;
    }
    .certificate-container::before {
      content: "";
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: url("data:image/svg+xml;utf8,<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"100\" height=\"100\" viewBox=\"0 0 100 100\"><rect width=\"100\" height=\"100\" fill=\"none\" stroke=\"rgba(30,58,138,0.05)\" stroke-width=\"2\" stroke-dasharray=\"5,5\"/></svg>");
      pointer-events: none;
    }
    .logo {
      width: 100px;
      margin-bottom: 20px;
    }
    .title {
      font-size: 42px;
      color: #1e3a8a;
      font-weight: bold;
      margin: 20px 0;
      letter-spacing: 3px;
      text-transform: uppercase;
    }
    .subtitle {
      font-size: 22px;
      color: #3b82f6;
      margin-bottom: 30px;
      font-style: italic;
    }
    .content {
      margin: 40px 0;
      font-size: 20px;
      line-height: 1.8;
    }
    .name {
      font-size: 36px;
      font-weight: bold;
      color: #1e3a8a;
      margin: 25px 0;
      padding: 10px;
      border-bottom: 3px solid #3b82f6;
      display: inline-block;
    }
    .details {
      font-size: 18px;
      margin: 15px 0;
      color: #475569;
    }
    .achievement {
      font-size: 28px;
      font-weight: bold;
      color: #3b82f6;
      margin: 30px 0;
      padding: 15px;
      background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
      border-radius: 8px;
      display: inline-block;
    }
    .stats {
      margin: 20px 0;
      padding: 15px;
      background: #f8fafc;
      border-left: 5px solid #1e3a8a;
      text-align: left;
      max-width: 400px;
      margin-left: auto;
      margin-right: auto;
    }
    .signature {
      display: table;
      width: 100%;
      margin-top: 60px;
    }
    .signature-box {
      display: table-cell;
      width: 50%;
      text-align: center;
      padding: 0 20px;
    }
    .signature-line {
      border-top: 2px solid #1e3a8a;
      width: 250px;
      margin: 0 auto;
      padding-top: 10px;
    }
    .footer {
      margin-top: 40px;
      font-size: 12px;
      color: #64748b;
      border-top: 1px solid #e2e8f0;
      padding-top: 15px;
    }
    .seal {
      position: absolute;
      bottom: 30px;
      right: 30px;
      width: 80px;
      height: 80px;
      border: 3px solid #1e3a8a;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 10px;
      color: #1e3a8a;
      font-weight: bold;
      transform: rotate(-15deg);
    }
    </style>
    </head>
    <body>
      <div class="certificate-container">
        <img src="photo/logo1.png" class="logo" alt="School Logo">
        <h1 class="title">SMK CHENDERING LIBRARY</h1>
        <h2 class="subtitle">Certificate of Recognition</h2>
        
        <div class="content">
          <p>This certificate is proudly presented to</p>
          <div class="name">' . htmlspecialchars($studentName) . '</div>
          <p class="details">of <strong>' . htmlspecialchars($className) . '</strong></p>
          <p>for outstanding dedication and excellence in reading</p>
          <div class="achievement">' . strtoupper($rank) . ' TOP BORROWER OF THE MONTH</div>
          
          <div class="stats">
            <strong>Statistics:</strong><br>
            • Books Borrowed: <strong>' . $borrowCount . '</strong><br>
            • Borrowing Period: <strong>' . date('M Y') . '</strong>
          </div>
        </div>
        
        <div class="signature">
          <div class="signature-box">
            <div class="signature-line">
              <strong>' . htmlspecialchars($librarian_name) . '</strong><br>
              <span style="font-size: 14px; color: #64748b;">Chief Librarian</span>
            </div>
          </div>
          <div class="signature-box">
            <div class="signature-line">
              <strong>' . date('d F Y') . '</strong><br>
              <span style="font-size: 14px; color: #64748b;">Date Issued</span>
            </div>
          </div>
        </div>
        
        <div class="seal">
          OFFICIAL<br>SEAL
        </div>
        
        <div class="footer">
          SMK Chendering Library Management System – Certificate #CERT-' . date('Ymd-His') . '
        </div>
      </div>
    </body>
    </html>';
    
    $mpdf->WriteHTML($html);
    
    $filename = 'certificate_' . preg_replace('/[^a-z0-9]/i', '_', $studentName) . '_' . date('YmdHis') . '.pdf';
    $filepath = 'exports/certificates/' . $filename;
    
    // Ensure directory exists
    if (!is_dir('exports/certificates')) {
        mkdir('exports/certificates', 0755, true);
    }
    
    $mpdf->Output($filepath, 'F');
    
    return [
        'success' => true, 
        'filename' => $filename, 
        'download_url' => 'exports/certificates/' . $filename,
        'message' => 'Certificate generated successfully'
    ];
}

// Get Class Students
function getClassStudents($params) {
    global $conn;
    
    $className = $params['class_name'] ?? '';
    
    $query = "SELECT 
              s.student_id_number,
              s.studentName,
              s.studentClass,
              s.studentEmail,
              s.studentPhoneNo,
              s.studentStatus,
              s.student_image,
              COUNT(b.borrowID) as total_borrowed
              FROM student s
              LEFT JOIN user u ON s.userID = u.userID
              LEFT JOIN borrow b ON u.userID = b.userID
              WHERE s.studentClass = ?
              GROUP BY s.studentID
              ORDER BY s.studentName";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $className);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $students = [];
    while ($row = $result->fetch_assoc()) {
        $students[] = $row;
    }
    $stmt->close();
    
    return ['success' => true, 'students' => $students];
}

// Export Class List
function exportClassList($params) {
    global $conn;
    
    $className = $params['class_name'] ?? '';
    $format = $params['format'] ?? 'pdf';
    
    $query = "SELECT 
              s.student_id_number as 'Student ID',
              s.studentName as 'Name',
              s.studentClass as 'Class',
              s.studentEmail as 'Email',
              s.studentPhoneNo as 'Phone',
              s.studentStatus as 'Status'
              FROM student s
              WHERE s.studentClass = ?
              ORDER BY s.studentName";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $className);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $students = [];
    while ($row = $result->fetch_assoc()) {
        $students[] = $row;
    }
    $stmt->close();
    
    if ($format === 'pdf') {
        $filename = exportClassListPDF($students, $className);
    } else {
        $filename = exportClassListExcel($students, $className);
    }
    
    return [
        'success' => true, 
        'filename' => $filename, 
        'download_url' => 'exports/' . $filename
    ];
}

// Export Class List PDF
function exportClassListPDF($students, $className) {
    $mpdf = new Mpdf(['format' => 'A4', 'margin_top' => 15, 'margin_bottom' => 15]);
    
    $html = '<style>
        body { font-family: Arial, sans-serif; font-size: 11px; }
        .header { text-align: center; margin-bottom: 20px; border-bottom: 3px solid #1e3a8a; padding-bottom: 10px; }
        .logo { width: 50px; }
        h2 { color: #1e3a8a; margin: 10px 0; font-size: 16px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th { background-color: #1e3a8a; color: white; padding: 10px; text-align: left; font-size: 10px; }
        td { border: 1px solid #ddd; padding: 8px; font-size: 10px; }
        tr:nth-child(even) { background-color: #f8fafc; }
        .footer { text-align: center; margin-top: 30px; font-size: 8px; color: #666; }
        .info { margin: 10px 0; font-size: 10px; color: #666; }
    </style>';
    
    $html .= '<div class="header">';
    $html .= '<img src="photo/logo1.png" class="logo">';
    $html .= '<h2>SMK CHENDERING LIBRARY MANAGEMENT SYSTEM</h2>';
    $html .= '<p style="font-size:12px; font-weight:bold;">CLASS STUDENT LIST</p>';
    $html .= '<p style="font-size:10px;">' . htmlspecialchars($className) . '</p>';
    $html .= '</div>';
    
    $html .= '<div class="info">Generated: ' . date('d F Y, h:i A') . ' | Total Students: ' . count($students) . '</div>';
    
    $html .= '<table>';
    $html .= '<thead><tr>';
    if (count($students) > 0) {
        foreach (array_keys($students[0]) as $header) {
            $html .= '<th>' . htmlspecialchars($header) . '</th>';
        }
    }
    $html .= '</tr></thead><tbody>';
    
    foreach ($students as $student) {
        $html .= '<tr>';
        foreach ($student as $value) {
            $html .= '<td>' . htmlspecialchars($value ?? '') . '</td>';
        }
        $html .= '</tr>';
    }
    
    $html .= '</tbody></table>';
    $html .= '<div class="footer">Official Document – SMK Chendering Library</div>';
    
    $mpdf->WriteHTML($html);
    
    $filename = 'class_list_' . preg_replace('/[^a-z0-9]/i', '_', $className) . '_' . date('YmdHis') . '.pdf';
    $filepath = 'exports/' . $filename;
    
    if (!is_dir('exports')) {
        mkdir('exports', 0755, true);
    }
    
    $mpdf->Output($filepath, 'F');
    
    return $filename;
}

// Export Class List Excel
function exportClassListExcel($students, $className) {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    
    // Header
    $sheet->setCellValue('A1', 'SMK CHENDERING LIBRARY MANAGEMENT SYSTEM');
    $sheet->mergeCells('A1:F1');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
    
    $sheet->setCellValue('A2', 'Class Student List - ' . $className);
    $sheet->mergeCells('A2:F2');
    $sheet->getStyle('A2')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
    
    $sheet->setCellValue('A3', 'Generated: ' . date('d F Y, h:i A'));
    $sheet->mergeCells('A3:F3');
    $sheet->getStyle('A3')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
    
    // Column headers
    $row = 5;
    if (count($students) > 0) {
        $col = 'A';
        foreach (array_keys($students[0]) as $header) {
            $sheet->setCellValue($col . $row, $header);
            $sheet->getStyle($col . $row)->getFont()->setBold(true);
            $sheet->getStyle($col . $row)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('1e3a8a');
            $sheet->getStyle($col . $row)->getFont()->getColor()->setRGB('FFFFFF');
            $col++;
        }
        
        // Data
        $row++;
        foreach ($students as $student) {
            $col = 'A';
            foreach ($student as $value) {
                $sheet->setCellValue($col . $row, $value);
                $col++;
            }
            $row++;
        }
        
        // Auto-size columns
        foreach (range('A', 'F') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
    }
    
    $filename = 'class_list_' . preg_replace('/[^a-z0-9]/i', '_', $className) . '_' . date('YmdHis') . '.xlsx';
    $filepath = 'exports/' . $filename;
    
    if (!is_dir('exports')) {
        mkdir('exports', 0755, true);
    }
    
    $writer = new Xlsx($spreadsheet);
    $writer->save($filepath);
    
    return $filename;
}

// Custom Report Builder Functions
function previewCustomReport($params) {
    global $conn;
    
    $reportType = $params['report_type'] ?? '';
    $dateFrom = $params['date_from'] ?? '';
    $dateTo = $params['date_to'] ?? '';
    $classFilter = $params['class_filter'] ?? 'all';
    
    if (empty($reportType) || empty($dateFrom) || empty($dateTo)) {
        return ['success' => false, 'message' => 'Missing required parameters'];
    }
    
    $query = buildCustomReportQuery($reportType, $dateFrom, $dateTo, $classFilter);
    $query .= " LIMIT 100";
    
    $stmt = $conn->prepare($query);
    
    // Bind parameters based on query
    if (strpos($query, 's.studentClass = ?') !== false) {
        $stmt->bind_param("sss", $dateFrom, $dateTo, $classFilter);
    } else {
        $stmt->bind_param("ss", $dateFrom, $dateTo);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $data = [];
    $columns = [];
    
    if ($result && $result->num_rows > 0) {
        $firstRow = $result->fetch_assoc();
        $columns = array_keys($firstRow);
        $data[] = $firstRow;
        
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
    }
    
    $stmt->close();
    
    return [
        'success' => true, 
        'columns' => $columns, 
        'data' => $data,
        'record_count' => count($data)
    ];
}

function buildCustomReportQuery($reportType, $dateFrom, $dateTo, $classFilter) {
    $query = "";
    
    switch ($reportType) {
        case 'borrowing':
            $query = "SELECT 
                     b.borrowID as 'Borrow ID',
                     CONCAT(u.first_name, ' ', u.last_name) as 'Borrower Name',
                     u.user_type as 'User Type',
                     COALESCE(s.student_id_number, st.staff_id_number) as 'ID Number',
                     s.studentClass as 'Class',
                     bk.bookTitle as 'Book Title',
                     bk.bookAuthor as 'Author',
                     bc.categoryName as 'Category',
                     b.borrow_date as 'Borrow Date',
                     b.due_date as 'Due Date',
                     b.return_date as 'Return Date',
                     b.borrow_status as 'Status'
                     FROM borrow b
                     JOIN user u ON b.userID = u.userID
                     LEFT JOIN student s ON u.userID = s.userID
                     LEFT JOIN staff st ON u.userID = st.userID
                     LEFT JOIN book bk ON b.bookID = bk.bookID
                     LEFT JOIN book_category bc ON bk.categoryID = bc.categoryID
                     WHERE b.borrow_date BETWEEN ? AND ?";
            break;
            
        case 'fines':
            $query = "SELECT 
                     f.fineID as 'Fine ID',
                     CONCAT(u.first_name, ' ', u.last_name) as 'Student Name',
                     COALESCE(s.student_id_number, st.staff_id_number) as 'ID Number',
                     s.studentClass as 'Class',
                     bk.bookTitle as 'Book Title',
                     f.fine_amount as 'Fine Amount',
                     f.fine_reason as 'Reason',
                     f.fine_date as 'Fine Date',
                     f.payment_status as 'Payment Status',
                     f.balance_due as 'Balance Due'
                     FROM fines f
                     JOIN user u ON f.userID = u.userID
                     LEFT JOIN student s ON u.userID = s.userID
                     LEFT JOIN staff st ON u.userID = st.userID
                     JOIN borrow b ON f.borrowID = b.borrowID
                     JOIN book bk ON b.bookID = bk.bookID
                     WHERE f.fine_date BETWEEN ? AND ?";
            break;
            
        case 'reservations':
            $query = "SELECT 
                     r.reservationID as 'Reservation ID',
                     CONCAT(u.first_name, ' ', u.last_name) as 'Student Name',
                     COALESCE(s.student_id_number, st.staff_id_number) as 'ID Number',
                     s.studentClass as 'Class',
                     bk.bookTitle as 'Book Title',
                     r.reservation_date as 'Reserved Date',
                     r.queue_position as 'Queue Position',
                     r.reservation_status as 'Status'
                     FROM reservation r
                     JOIN user u ON r.userID = u.userID
                     LEFT JOIN student s ON u.userID = s.userID
                     LEFT JOIN staff st ON u.userID = st.userID
                     JOIN book bk ON r.bookID = bk.bookID
                     WHERE r.reservation_date BETWEEN ? AND ?";
            break;
            
        case 'books':
            $query = "SELECT 
                     bk.bookID as 'Book ID',
                     bk.bookTitle as 'Title',
                     bk.bookAuthor as 'Author',
                     bc.categoryName as 'Category',
                     bk.bookStatus as 'Status',
                     bk.shelf_location as 'Location',
                     bk.book_ISBN as 'ISBN',
                     bk.acquisition_date as 'Acquisition Date'
                     FROM book bk
                     LEFT JOIN book_category bc ON bk.categoryID = bc.categoryID
                     WHERE bk.acquisition_date BETWEEN ? AND ?";
            break;
    }
    
    // Add class filter for student reports
    if ($classFilter !== 'all' && in_array($reportType, ['borrowing', 'fines', 'reservations'])) {
        $query .= " AND s.studentClass = ?";
    }
    
    $query .= " ORDER BY 1 DESC";
    
    return $query;
}

function exportCustomReport($params) {
    global $conn, $current_librarian_id;
    
    $reportType = $params['report_type'] ?? '';
    $dateFrom = $params['date_from'] ?? '';
    $dateTo = $params['date_to'] ?? '';
    $classFilter = $params['class_filter'] ?? 'all';
    $format = $params['format'] ?? 'pdf';
    
    if (empty($reportType) || empty($dateFrom) || empty($dateTo)) {
        return ['success' => false, 'message' => 'Missing required parameters'];
    }
    
    $query = buildCustomReportQuery($reportType, $dateFrom, $dateTo, $classFilter);
    $stmt = $conn->prepare($query);
    
    if (strpos($query, 's.studentClass = ?') !== false) {
        $stmt->bind_param("sss", $dateFrom, $dateTo, $classFilter);
    } else {
        $stmt->bind_param("ss", $dateFrom, $dateTo);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    $stmt->close();
    
    if ($format === 'pdf') {
        $filename = exportCustomReportPDF($data, $reportType, $dateFrom, $dateTo, $classFilter);
    } else {
        $filename = exportCustomReportExcel($data, $reportType, $dateFrom, $dateTo);
    }
    
    // Log download
    $fileSize = file_exists('exports/' . $filename) ? filesize('exports/' . $filename) : 0;
    $stmt = $conn->prepare("INSERT INTO report_downloads 
                           (report_name, export_format, file_size, downloaded_by_librarianID, filters_applied, record_count) 
                           VALUES (?, ?, ?, ?, ?, ?)");
    $reportName = ucfirst($reportType) . ' Report';
    $filtersApplied = json_encode($params);
    $recordCount = count($data);
    $stmt->bind_param("ssiisi", $reportName, $format, $fileSize, $current_librarian_id, $filtersApplied, $recordCount);
    $stmt->execute();
    $stmt->close();
    
    return [
        'success' => true, 
        'filename' => $filename, 
        'download_url' => 'exports/' . $filename,
        'record_count' => $recordCount
    ];
}

function exportCustomReportPDF($data, $reportType, $dateFrom, $dateTo, $classFilter) {
    $mpdf = new Mpdf([
        'format' => 'A4-L',
        'margin_left' => 10,
        'margin_right' => 10,
        'margin_top' => 15,
        'margin_bottom' => 15
    ]);
    
    $html = '<style>
        body { font-family: Arial, sans-serif; font-size: 9px; }
        .header { text-align: center; margin-bottom: 20px; border-bottom: 3px solid #1e3a8a; padding-bottom: 10px; }
        .logo { width: 40px; }
        h2 { color: #1e3a8a; margin: 5px 0; font-size: 14px; }
        .meta { font-size: 8px; color: #666; margin: 5px 0; }
        .filters { background: #f8fafc; padding: 10px; border-left: 4px solid #1e3a8a; margin: 10px 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th { background-color: #1e3a8a; color: white; padding: 8px; font-size: 8px; word-wrap: break-word; }
        td { border: 1px solid #ddd; padding: 6px; font-size: 8px; word-wrap: break-word; }
        tr:nth-child(even) { background-color: #f8fafc; }
        .footer { text-align: center; margin-top: 20px; font-size: 8px; color: #666; }
        .summary { margin: 15px 0; padding: 10px; background: #f0f9ff; border: 1px solid #3b82f6; }
    </style>';
    
    $html .= '<div class="header">';
    $html .= '<img src="photo/logo1.png" class="logo">';
    $html .= '<h2>SMK CHENDERING LIBRARY MANAGEMENT SYSTEM</h2>';
    $html .= '<p style="font-size:12px; font-weight:bold; text-transform:uppercase;">' . ucfirst($reportType) . ' Report</p>';
    $html .= '</div>';
    
    $html .= '<div class="meta">';
    $html .= 'Period: ' . $dateFrom . ' to ' . $dateTo . ' | ';
    if ($classFilter !== 'all') {
        $html .= 'Class: ' . htmlspecialchars($classFilter) . ' | ';
    }
    $html .= 'Generated: ' . date('d F Y, h:i A') . ' | ';
    $html .= 'Records: ' . count($data);
    $html .= '</div>';
    
    $html .= '<div class="filters">';
    $html .= '<strong>Filters Applied:</strong> Date Range: ' . $dateFrom . ' to ' . $dateTo;
    if ($classFilter !== 'all') {
        $html .= ' | Class: ' . htmlspecialchars($classFilter);
    }
    $html .= '</div>';
    
    if (count($data) > 0) {
        $html .= '<table><thead><tr>';
        foreach (array_keys($data[0]) as $header) {
            $html .= '<th>' . htmlspecialchars($header) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        
        foreach ($data as $row) {
            $html .= '<tr>';
            foreach ($row as $value) {
                $html .= '<td>' . htmlspecialchars($value ?? '') . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
        
        // Summary statistics
        if ($reportType === 'fines') {
            $totalFines = array_sum(array_column($data, 'Fine Amount'));
            $html .= '<div class="summary">';
            $html .= '<strong>Summary:</strong> Total Fine Amount: RM ' . number_format($totalFines, 2);
            $html .= '</div>';
        }
    } else {
        $html .= '<div style="text-align:center; padding:40px; color:#666;">No data found for the selected criteria.</div>';
    }
    
    $html .= '<div class="footer">Official Document – SMK Chendering Library | Generated by automatic report system</div>';
    
    $mpdf->WriteHTML($html);
    
    $filename = 'report_' . $reportType . '_' . date('YmdHis') . '.pdf';
    $filepath = 'exports/' . $filename;
    
    if (!is_dir('exports')) {
        mkdir('exports', 0755, true);
    }
    
    $mpdf->Output($filepath, 'F');
    
    return $filename;
}

function exportCustomReportExcel($data, $reportType, $dateFrom, $dateTo) {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    
    // Header
    $sheet->setCellValue('A1', 'SMK CHENDERING LIBRARY MANAGEMENT SYSTEM');
    $sheet->mergeCells('A1:F1');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
    
    $sheet->setCellValue('A2', strtoupper($reportType) . ' REPORT');
    $sheet->mergeCells('A2:F2');
    $sheet->getStyle('A2')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
    
    $sheet->setCellValue('A3', 'Period: ' . $dateFrom . ' to ' . $dateTo . ' | Generated: ' . date('d F Y, h:i A'));
    $sheet->mergeCells('A3:F3');
    $sheet->getStyle('A3')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
    
    $row = 5;
    if (count($data) > 0) {
        $col = 'A';
        foreach (array_keys($data[0]) as $header) {
            $sheet->setCellValue($col . $row, $header);
            $sheet->getStyle($col . $row)->getFont()->setBold(true);
            $sheet->getStyle($col . $row)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('1e3a8a');
            $sheet->getStyle($col . $row)->getFont()->getColor()->setRGB('FFFFFF');
            $col++;
        }
        
        $row++;
        foreach ($data as $dataRow) {
            $col = 'A';
            foreach ($dataRow as $value) {
                $sheet->setCellValue($col . $row, $value);
                $col++;
            }
            $row++;
        }
        
        // Auto-size columns
        foreach (range('A', chr(ord('A') + count($data[0]) - 1)) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
        
        // Add filters
        $sheet->setAutoFilter('A5:' . chr(ord('A') + count($data[0]) - 1) . '5');
    }
    
    $filename = 'report_' . $reportType . '_' . date('YmdHis') . '.xlsx';
    $filepath = 'exports/' . $filename;
    
    if (!is_dir('exports')) {
        mkdir('exports', 0755, true);
    }
    
    $writer = new Xlsx($spreadsheet);
    $writer->save($filepath);
    
    return $filename;
}

// Template Management Functions
function saveTemplate($params) {
    global $conn, $current_librarian_id, $librarian_name;
    
    $templateName = $params['template_name'] ?? '';
    $reportType = $params['report_type'] ?? '';
    $description = $params['description'] ?? '';
    $isPublic = isset($params['is_public']) ? 1 : 0;
    
    if (empty($templateName) || empty($reportType)) {
        return ['success' => false, 'message' => 'Template name and report type are required'];
    }
    
    // Remove csrf_token and ajax_action from filters
    $filters = $params;
    unset($filters['ajax_action'], $filters['csrf_token'], $filters['template_name'], 
          $filters['is_public'], $filters['description']);
    
    $filtersJson = json_encode($filters);
    
    $stmt = $conn->prepare("INSERT INTO report_templates 
                           (template_name, template_description, data_source, selected_fields, filters, 
                            chart_type, created_by_librarianID, is_public) 
                           VALUES (?, ?, ?, '[]', ?, 'table', ?, ?)");
    $stmt->bind_param("ssssii", $templateName, $description, $reportType, $filtersJson, $current_librarian_id, $isPublic);
    
    if ($stmt->execute()) {
        $templateID = $stmt->insert_id;
        $stmt->close();
        
        // Log the creation
        $logQuery = "INSERT INTO user_activity_log (userID, action, description) 
                    VALUES (?, 'template_created', ?)";
        $logStmt = $conn->prepare($logQuery);
        $logDesc = "Created report template: $templateName (ID: $templateID)";
        $logStmt->bind_param("is", $current_librarian_id, $logDesc);
        $logStmt->execute();
        $logStmt->close();
        
        return [
            'success' => true, 
            'message' => 'Template saved successfully',
            'template_id' => $templateID
        ];
    } else {
        $stmt->close();
        return ['success' => false, 'message' => 'Failed to save template'];
    }
}

function loadTemplate($params) {
    global $conn, $current_librarian_id;
    
    $templateID = intval($params['template_id'] ?? 0);
    
    $query = "SELECT * FROM report_templates 
              WHERE templateID = ? AND (created_by_librarianID = ? OR is_public = 1)";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $templateID, $current_librarian_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $row = $result->fetch_assoc()) {
        $filters = json_decode($row['filters'], true);
        $stmt->close();
        return [
            'success' => true, 
            'template' => $row, 
            'filters' => $filters
        ];
    } else {
        $stmt->close();
        return ['success' => false, 'message' => 'Template not found or access denied'];
    }
}

function deleteTemplate($params) {
    global $conn, $current_librarian_id;
    
    $templateID = intval($params['template_id'] ?? 0);
    
    // Verify ownership before deletion
    $verifyQuery = "SELECT template_name FROM report_templates 
                    WHERE templateID = ? AND created_by_librarianID = ?";
    $stmt = $conn->prepare($verifyQuery);
    $stmt->bind_param("ii", $templateID, $current_librarian_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $row = $result->fetch_assoc()) {
        $templateName = $row['template_name'];
        $stmt->close();
        
        $deleteQuery = "DELETE FROM report_templates WHERE templateID = ? AND created_by_librarianID = ?";
        $deleteStmt = $conn->prepare($deleteQuery);
        $deleteStmt->bind_param("ii", $templateID, $current_librarian_id);
        
        if ($deleteStmt->execute()) {
            $deleteStmt->close();
            
            // Log deletion
            $logQuery = "INSERT INTO user_activity_log (userID, action, description) 
                        VALUES (?, 'template_deleted', ?)";
            $logStmt = $conn->prepare($logQuery);
            $logDesc = "Deleted report template: $templateName (ID: $templateID)";
            $logStmt->bind_param("is", $current_librarian_id, $logDesc);
            $logStmt->execute();
            $logStmt->close();
            
            return ['success' => true, 'message' => 'Template deleted successfully'];
        } else {
            $deleteStmt->close();
            return ['success' => false, 'message' => 'Failed to delete template'];
        }
    } else {
        $stmt->close();
        return ['success' => false, 'message' => 'Template not found or access denied'];
    }
}

// Export Analytics Dashboard
function exportAnalytics($params) {
    global $librarian_name;
    
    $dateFrom = $params['date_from'] ?? date('Y-m-01');
    $dateTo = $params['date_to'] ?? date('Y-m-d');
    
    // Get all statistics
    $stats = getSummaryStats(['date_from' => $dateFrom, 'date_to' => $dateTo]);
    $bookStatus = getBookStatusStats();
    $userActivity = getUserActivityStats(['date_from' => $dateFrom, 'date_to' => $dateTo]);
    $financial = getFinancialStats(['date_from' => $dateFrom, 'date_to' => $dateTo]);
    
    $mpdf = new Mpdf(['format' => 'A4']);
    
    $html = '<style>
        body { font-family: Arial, sans-serif; }
        .header { text-align: center; margin-bottom: 20px; border-bottom: 3px solid #1e3a8a; padding-bottom: 10px; }
        .logo { width: 50px; }
        h2 { color: #1e3a8a; }
        .stats-box { border: 2px solid #1e3a8a; padding: 15px; margin: 10px 0; background: #f8fafc; }
        .stat-item { margin: 10px 0; }
        .stat-item strong { color: #1e3a8a; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        th { background-color: #1e3a8a; color: white; padding: 8px; }
        td { border: 1px solid #ddd; padding: 8px; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .section { margin: 20px 0; }
        .highlight { background: #dbeafe; padding: 10px; border-left: 5px solid #3b82f6; margin: 10px 0; }
    </style>';
    
    $html .= '<div class="header">';
    $html .= '<img src="photo/logo1.png" class="logo">';
    $html .= '<h2>SMK CHENDERING LIBRARY</h2>';
    $html .= '<h3>Statistical Analysis Report</h3>';
    $html .= '<p>Period: ' . $dateFrom . ' to ' . $dateTo . '</p>';
    $html .= '<p>Generated: ' . date('d F Y, h:i A') . ' by ' . htmlspecialchars($librarian_name) . '</p>';
    $html .= '</div>';
    
    // Executive Summary
    $html .= '<div class="section">';
    $html .= '<h3>📊 Executive Summary</h3>';
    $html .= '<div class="stats-box">';
    $html .= '<div class="grid">';
    $html .= '<div>';
    $html .= '<div class="stat-item"><strong>Total Books:</strong> ' . ($stats['data']['total_books'] ?? 0) . '</div>';
    $html .= '<div class="stat-item"><strong>Books Borrowed:</strong> ' . ($stats['data']['borrowed_books'] ?? 0) . '</div>';
    $html .= '</div>';
    $html .= '<div>';
    $html .= '<div class="stat-item"><strong>Overdue Books:</strong> ' . ($stats['data']['overdue_books'] ?? 0) . '</div>';
    $html .= '<div class="stat-item"><strong>Active Users:</strong> ' . ($stats['data']['active_users'] ?? 0) . '</div>';
    $html .= '</div>';
    $html .= '</div>';
    $html .= '</div>';
    $html .= '</div>';
    
    // Book Status Distribution
    $html .= '<div class="section">';
    $html .= '<h3>📚 Book Status Distribution</h3>';
    $html .= '<table>';
    $html .= '<thead><tr><th>Status</th><th>Count</th><th>Percentage</th></tr></thead><tbody>';
    $total = array_sum($bookStatus['data']);
    foreach ($bookStatus['data'] as $status => $count) {
        $percentage = $total > 0 ? round(($count / $total) * 100, 1) : 0;
        $html .= '<tr>';
        $html .= '<td>' . ucfirst($status) . '</td>';
        $html .= '<td>' . $count . '</td>';
        $html .= '<td>' . $percentage . '%</td>';
        $html .= '</tr>';
    }
    $html .= '</tbody></table>';
    $html .= '</div>';
    
    // User Activity
    $html .= '<div class="section">';
    $html .= '<h3>👥 User Activity Analysis</h3>';
    $html .= '<div class="grid">';
    $html .= '<div class="highlight">';
    $html .= '<strong>User Distribution:</strong><br>';
    $html .= '• Active Users: ' . ($userActivity['data']['active_users'] ?? 0) . '<br>';
    $html .= '• Inactive Users: ' . ($userActivity['data']['inactive_users'] ?? 0) . '<br>';
    $html .= '• Total Users: ' . ($userActivity['data']['total_users'] ?? 0);
    $html .= '</div>';
    $html .= '<div class="highlight">';
    $html .= '<strong>User Breakdown:</strong><br>';
    $html .= '• Students: ' . ($userActivity['data']['student_count'] ?? 0) . '<br>';
    $html .= '• Staff: ' . ($userActivity['data']['staff_count'] ?? 0) . '<br>';
    $html .= '• With Overdue: ' . ($userActivity['data']['users_with_overdue'] ?? 0);
    $html .= '</div>';
    $html .= '</div>';
    $html .= '</div>';
    
    // Financial Overview
    $html .= '<div class="section">';
    $html .= '<h3>💰 Financial Overview</h3>';
    $html .= '<div class="stats-box">';
    $html .= '<div class="grid">';
    $html .= '<div>';
    $html .= '<div class="stat-item"><strong>Total Collected:</strong> RM ' . number_format($financial['data']['total_collected'] ?? 0, 2) . '</div>';
    $html .= '<div class="stat-item"><strong>Outstanding:</strong> RM ' . number_format($financial['data']['total_outstanding'] ?? 0, 2) . '</div>';
    $html .= '</div>';
    $html .= '<div>';
    foreach ($financial['data']['fine_types'] as $type) {
        $html .= '<div class="stat-item"><strong>' . ucfirst($type['fine_reason']) . ':</strong> RM ' . number_format($type['total'], 2) . '</div>';
    }
    $html .= '</div>';
    $html .= '</div>';
    $html .= '</div>';
    $html .= '</div>';
    
    $html .= '<div style="margin-top: 40px; text-align: center; font-size: 10px; color: #666;">';
    $html .= 'Official Document – SMK Chendering Library | Confidential Report';
    $html .= '</div>';
    
    $mpdf->WriteHTML($html);
    
    $filename = 'analytics_dashboard_' . date('YmdHis') . '.pdf';
    $filepath = 'exports/' . $filename;
    
    if (!is_dir('exports')) {
        mkdir('exports', 0755, true);
    }
    
    $mpdf->Output($filepath, 'F');
    
    return [
        'success' => true, 
        'filename' => $filename, 
        'download_url' => 'exports/' . $filename
    ];
}

// Get saved templates for display
$templates_query = "SELECT * FROM report_templates 
                    WHERE created_by_librarianID = ? OR is_public = 1 
                    ORDER BY created_date DESC";
$templates_stmt = $conn->prepare($templates_query);
$templates_stmt->bind_param("i", $current_librarian_id);
$templates_stmt->execute();
$templates_result = $templates_stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Analytics - SMK Chendering Library</title>
    
    <!-- External Libraries -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- SweetAlert2 for notifications -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>
    
    <!-- DataTables -->
    <link href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.dataTables.min.css" rel="stylesheet">
    
    <!-- Flatpickr for date ranges -->
    <link href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/datatables.net@1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/datatables.net-buttons@2.4.1/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/datatables.net-buttons@2.4.1/js/buttons.html5.min.js"></script>
    
    <style>
        :root {
            --primary: #1e3a8a;
            --primary-light: #3b82f6;
            --secondary: #64748b;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --light: #f8fafc;
            --header-height: 64px;
            --sidebar-width: 260px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: #f1f5f9;
            color: #1e293b;
            overflow-x: hidden;
        }

        /* Header - EXACT MATCH to existing */
        .header {
            height: var(--header-height);
            background: white;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
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
            transition: all 0.2s;
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
            background: url('photo/logo1.png') no-repeat center;
            background-size: contain;
        }

        .logo-text {
            font-weight: 700;
            font-size: 1.25rem;
            color: var(--primary);
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
        }

        .user-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), #1d4ed8);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
        }

        .logout-btn {
            padding: 0.5rem 1rem;
            background: var(--danger);
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-size: 0.9rem;
            transition: all 0.2s;
        }

        .logout-btn:hover {
            background: #dc2626;
            color: white;
        }

        /* Sidebar - EXACT MATCH to existing */
        .sidebar {
            position: fixed;
            left: 0;
            top: var(--header-height);
            bottom: 0;
            width: var(--sidebar-width);
            background: white;
            border-right: 1px solid #e2e8f0;
            overflow-y: auto;
            z-index: 40;
            transition: width 0.3s;
        }

        .sidebar.collapsed {
            width: 80px;
        }

        .menu-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1.5rem;
            color: var(--secondary);
            text-decoration: none;
            transition: all 0.2s;
            white-space: nowrap;
        }

        .menu-item:hover {
            background: var(--light);
            color: var(--primary);
        }

        .menu-item.active {
            background: var(--primary);
            color: white;
        }

        .sidebar.collapsed .menu-item span {
            display: none;
        }

        /* Main Content */
        .main-content {
            margin-left: var(--sidebar-width);
            margin-top: var(--header-height);
            padding: 2rem;
            min-height: calc(100vh - var(--header-height));
            transition: margin-left 0.3s;
        }

        .main-content.collapsed {
            margin-left: 80px;
        }

        .page-header {
            margin-bottom: 2rem;
        }

        .page-title {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 0.5rem;
        }

        /* Cards */
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }

        .card-header {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Filter Bar */
        .filter-bar {
            display: flex;
            gap: 1rem;
            align-items: end;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }

        .form-group {
            flex: 1;
            min-width: 180px;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--primary);
            font-size: 0.9rem;
        }

        .form-control {
            width: 100%;
            padding: 0.6rem;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 0.9rem;
            transition: all 0.2s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59,130,246,0.1);
        }

        /* Buttons */
        .btn {
            padding: 0.6rem 1.2rem;
            border: none;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s;
            font-size: 0.9rem;
            text-decoration: none;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: #1d4ed8;
            transform: translateY(-1px);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-success:hover {
            background: #059669;
            transform: translateY(-1px);
        }

        .btn-warning {
            background: var(--warning);
            color: white;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: var(--primary);
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.15);
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 1rem;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .stat-label {
            color: var(--secondary);
            font-size: 0.9rem;
        }

        /* Chart Cards */
        .chart-card {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            position: relative;
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .chart-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .chart-actions {
            display: flex;
            gap: 0.5rem;
        }

        .chart-action-btn {
            padding: 0.4rem 0.8rem;
            font-size: 0.8rem;
            border: 1px solid #e2e8f0;
            background: white;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .chart-action-btn:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .chart-container {
            position: relative;
            height: 300px;
        }

        /* Date Range Picker */
        .date-range-container {
            position: relative;
        }

        .range-presets {
            position: absolute;
            top: 100%;
            left: 0;
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            display: none;
            z-index: 100;
            min-width: 200px;
        }

        .range-presets.show {
            display: block;
        }

        .preset-item {
            padding: 0.5rem 1rem;
            cursor: pointer;
            border-bottom: 1px solid #f1f5f9;
            font-size: 0.9rem;
            transition: background 0.2s;
        }

        .preset-item:hover {
            background: var(--light);
            color: var(--primary);
        }

        .preset-item:last-child {
            border-bottom: none;
        }

        /* Loading Overlay */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255,255,255,0.9);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 2000;
        }

        .loading-overlay.show {
            display: flex;
        }

        .spinner {
            width: 50px;
            height: 50px;
            border: 4px solid rgba(30,58,138,0.1);
            border-top: 4px solid var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Top Borrowers */
        .top-borrowers-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1.5rem;
            margin-top: 1.5rem;
        }

        .borrower-card {
            background: white;
            padding: 2rem 1.5rem;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            text-align: center;
            position: relative;
            transition: all 0.3s;
            border: 2px solid transparent;
        }

        .borrower-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 12px 30px rgba(0,0,0,0.15);
        }

        .borrower-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('photo/logo1.png') no-repeat center;
            background-size: 120px;
            opacity: 0.03;
            border-radius: 12px;
            pointer-events: none;
        }

        .rank-badge {
            position: absolute;
            top: -15px;
            right: -15px;
            width: 55px;
            height: 55px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: white;
            font-size: 1.1rem;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            border: 3px solid white;
        }

        .rank-1 { background: linear-gradient(135deg, #FFD700, #FFA500); }
        .rank-2 { background: linear-gradient(135deg, #C0C0C0, #808080); }
        .rank-3 { background: linear-gradient(135deg, #CD7F32, #8B4513); }

        .borrower-card.rank-1 { border-color: #FFD700; }
        .borrower-card.rank-2 { border-color: #C0C0C0; }
        .borrower-card.rank-3 { border-color: #CD7F32; }

        .borrower-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            margin: 0 auto 1rem;
            background: var(--light);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            color: var(--primary);
            font-weight: 700;
            border: 3px solid var(--primary);
            overflow: hidden;
        }

        .borrower-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .borrower-name {
            font-size: 1.15rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--primary);
        }

        .borrower-info {
            color: var(--secondary);
            font-size: 0.9rem;
            margin-bottom: 0.3rem;
        }

        .borrow-count {
            display: inline-block;
            padding: 0.6rem 1.2rem;
            background: var(--primary-light);
            color: white;
            border-radius: 20px;
            font-weight: 600;
            margin-top: 1rem;
            font-size: 0.95rem;
        }

        /* Tabs */
        .nav-tabs {
            border-bottom: 2px solid #e2e8f0;
            margin-bottom: 1.5rem;
        }

        .nav-tabs .nav-link {
            color: var(--secondary);
            border: none;
            padding: 0.75rem 1.5rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }

        .nav-tabs .nav-link:hover {
            color: var(--primary);
            background: var(--light);
            border-bottom-color: transparent;
        }

        .nav-tabs .nav-link.active {
            color: var(--primary);
            border-bottom: 3px solid var(--primary);
            background: transparent;
        }

        .tab-content {
            padding: 1rem 0;
        }

        /* Custom Report Builder */
        .wizard-steps {
            display: flex;
            justify-content: space-between;
            margin-bottom: 2rem;
        }

        .wizard-step {
            flex: 1;
            text-align: center;
            padding: 1rem;
            position: relative;
        }

        .wizard-step::after {
            content: '';
            position: absolute;
            top: 50%;
            right: -50%;
            width: 100%;
            height: 2px;
            background: #e2e8f0;
            z-index: -1;
        }

        .wizard-step:last-child::after {
            display: none;
        }

        .step-number {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #e2e8f0;
            color: var(--secondary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            margin: 0 auto 0.5rem;
            transition: all 0.3s;
        }

        .wizard-step.active .step-number {
            background: var(--primary);
            color: white;
        }

        .wizard-step.completed .step-number {
            background: var(--success);
            color: white;
        }

        .field-selector {
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 0.5rem;
        }

        .field-item {
            display: flex;
            align-items: center;
            padding: 0.5rem;
            border-bottom: 1px solid #f1f5f9;
            cursor: pointer;
            transition: background 0.2s;
        }

        .field-item:hover {
            background: var(--light);
        }

        .field-item:last-child {
            border-bottom: none;
        }

        .field-item input[type="checkbox"] {
            margin-right: 0.5rem;
        }

        /* Templates */
        .template-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-top: 1.5rem;
        }

        .template-card {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border-left: 4px solid var(--primary);
            transition: all 0.2s;
            position: relative;
        }

        .template-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }

        .template-name {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--primary);
        }

        .template-meta {
            font-size: 0.8rem;
            color: var(--secondary);
            margin: 0.3rem 0;
        }

        .template-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
        }

        /* Responsive Tables */
        .table-responsive {
            overflow-x: auto;
            margin-top: 1rem;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th,
        .table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
            font-size: 0.9rem;
        }

        .table th {
            background: var(--light);
            font-weight: 600;
            color: var(--primary);
            position: sticky;
            top: 0;
        }

        .table tbody tr:hover {
            background: var(--light);
        }

        /* Status Badges */
        .badge {
            padding: 0.35rem 0.7rem;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .bg-success { background: var(--success); color: white; }
        .bg-danger { background: var(--danger); color: white; }
        .bg-warning { background: var(--warning); color: white; }
        .bg-secondary { background: var(--secondary); color: white; }

        /* Progress Bar */
        .progress {
            width: 100%;
            height: 8px;
            background: #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
        }

        .progress-bar {
            height: 100%;
            background: var(--primary);
            transition: width 0.3s;
        }

        /* Tooltip */
        .tooltip-custom {
            position: relative;
        }

        .tooltip-custom::after {
            content: attr(data-tooltip);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            background: var(--primary);
            color: white;
            padding: 0.5rem 0.75rem;
            border-radius: 6px;
            font-size: 0.8rem;
            white-space: nowrap;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.2s;
            z-index: 1000;
        }

        .tooltip-custom:hover::after {
            opacity: 1;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--secondary);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        /* Dynamic Fields */
        .dynamic-fields-container {
            max-height: 350px;
            overflow-y: auto;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 0.5rem;
        }

        .field-option {
            display: flex;
            align-items: center;
            padding: 0.5rem;
            border-bottom: 1px solid #f1f5f9;
            cursor: pointer;
            transition: background 0.2s;
        }

        .field-option:hover {
            background: var(--light);
        }

        .field-option input {
            margin-right: 0.5rem;
        }

        .field-option:last-child {
            border-bottom: none;
        }

        /* Keyboard Shortcuts Help */
        .shortcuts-help {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: var(--primary);
            color: white;
            padding: 0.75rem 1rem;
            border-radius: 50px;
            cursor: pointer;
            z-index: 100;
            font-size: 0.9rem;
            transition: all 0.3s;
        }

        .shortcuts-help:hover {
            background: #1d4ed8;
            transform: scale(1.05);
        }

        /* Mobile Responsiveness */
        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .top-borrowers-grid {
                grid-template-columns: 1fr;
            }
            
            .charts-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-bar {
                flex-direction: column;
            }
            
            .form-group {
                width: 100%;
            }
            
            .wizard-steps {
                flex-direction: column;
                gap: 1rem;
            }
            
            .wizard-step::after {
                display: none;
            }
        }
    </style>
</head>
<body>
    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner"></div>
    </div>

    <!-- Header - EXACT MATCH to existing -->
    <header class="header">
        <div class="header-left">
            <button id="sidebarToggle" class="toggle-sidebar" title="Toggle Sidebar">
                <i class="fas fa-bars"></i>
            </button>
            <div class="logo-container">
                <div class="school-logo"></div>
                <div class="logo-text">SMK Chendering</div>
            </div>
        </div>
        <div class="header-right">
            <div class="user-menu">
                <div class="user-avatar">
                    <?php echo strtoupper(substr($librarian_name, 0, 1)); ?>
                </div>
                <div>
                    <div style="font-weight: 600; font-size: 0.9rem;"><?php echo htmlspecialchars($librarian_name); ?></div>
                    <div style="font-size: 0.8rem; color: var(--secondary);">Librarian</div>
                </div>
            </div>
            <a href="logout.php" class="logout-btn">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </header>

    <!-- Sidebar - EXACT MATCH to existing -->
    <aside class="sidebar" id="sidebar">
        <nav style="padding: 1rem 0;">
            <a href="l

            <!-- Complete Sidebar Navigation -->
<aside class="sidebar" id="sidebar">
    <nav style="padding: 1rem 0;">
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
        <a href="circulation_control.php" class="menu-item">
            <i class="fas fa-exchange-alt"></i>
            <span>Circulation Control</span>
        </a>
        <a href="fine_management.php" class="menu-item">
            <i class="fas fa-receipt"></i>
            <span>Fine Management</span>
        </a>
        <a href="report_management.php" class="menu-item active">
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
</aside>

<!-- Main Content -->
<main class="main-content" id="mainContent">
    <!-- CSRF Token -->
    <input type="hidden" id="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
    
    <div class="page-header">
        <h1 class="page-title">Library Reports & Analytics</h1>
        <p style="color: var(--secondary);">Comprehensive reporting and statistical analysis for library operations</p>
    </div>

    <!-- Filter Bar -->
    <div class="card">
        <div class="card-header">
            <i class="fas fa-filter"></i> Global Filters
        </div>
        <div class="filter-bar">
            <div class="form-group">
                <label><i class="fas fa-calendar"></i> From Date</label>
                <div class="date-range-container">
                    <input type="text" id="filter_date_from" class="form-control" placeholder="Select start date">
                    <div class="range-presets" id="fromPresets"></div>
                </div>
            </div>
            <div class="form-group">
                <label><i class="fas fa-calendar"></i> To Date</label>
                <div class="date-range-container">
                    <input type="text" id="filter_date_to" class="form-control" placeholder="Select end date">
                    <div class="range-presets" id="toPresets"></div>
                </div>
            </div>
            <div class="form-group">
                <label><i class="fas fa-users"></i> Class Filter</label>
                <select id="filter_class" class="form-control">
                    <option value="all">All Classes</option>
                    <option value="1 Amanah">Form 1 Amanah</option>
                    <option value="1 Bijak">Form 1 Bijak</option>
                    <option value="2 Amanah">Form 2 Amanah</option>
                    <option value="2 Bijak">Form 2 Bijak</option>
                    <option value="3 Amanah">Form 3 Amanah</option>
                    <option value="3 Bijak">Form 3 Bijak</option>
                    <option value="4 Amanah">Form 4 Amanah</option>
                    <option value="4 Bijak">Form 4 Bijak</option>
                    <option value="5 Amanah">Form 5 Amanah</option>
                    <option value="5 Bijak">Form 5 Bijak</option>
                </select>
            </div>
            <div style="align-self: end;">
                <button class="btn btn-primary" onclick="refreshAllData()" id="refreshBtn">
                    <i class="fas fa-sync-alt"></i> Refresh Data
                </button>
            </div>
        </div>
    </div>

    <!-- Summary Statistics -->
    <div class="stats-grid" id="statsGrid">
        <div class="stat-card">
            <div class="stat-icon" style="background: rgba(59,130,246,0.1); color: var(--primary);">
                <i class="fas fa-book"></i>
            </div>
            <div class="stat-value" style="color: var(--primary);">0</div>
            <div class="stat-label">Total Books</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background: rgba(16,185,129,0.1); color: var(--success);">
                <i class="fas fa-book-open"></i>
            </div>
            <div class="stat-value" style="color: var(--success);">0</div>
            <div class="stat-label">Books Borrowed</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background: rgba(245,158,11,0.1); color: var(--warning);">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <div class="stat-value" style="color: var(--warning);">0</div>
            <div class="stat-label">Overdue Books</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background: rgba(139,92,246,0.1); color: #8b5cf6;">
                <i class="fas fa-users"></i>
            </div>
            <div class="stat-value" style="color: #8b5cf6;">0</div>
            <div class="stat-label">Active Users</div>
        </div>
    </div>

    <!-- Charts Section -->
    <div class="card">
        <div class="card-header">
            <i class="fas fa-chart-line"></i> Statistical Analysis
            <button class="btn btn-success" onclick="exportAnalytics()" style="margin-left: auto; font-size: 0.85rem;">
                <i class="fas fa-download"></i> Download Full Report
            </button>
        </div>
        
        <div class="charts-grid">
            <!-- Borrowing Trends -->
            <div class="chart-card">
                <div class="chart-header">
                    <div class="chart-title">
                        <i class="fas fa-chart-area"></i> Borrowing Trends
                    </div>
                    <div class="chart-actions">
                        <button class="chart-action-btn" onclick="exportChart('monthlyChart', 'borrowing_trends')">
                            <i class="fas fa-image"></i> Export
                        </button>
                    </div>
                </div>
                <div class="chart-container">
                    <canvas id="monthlyChart"></canvas>
                </div>
            </div>

            <!-- Overdue Trends -->
            <div class="chart-card">
                <div class="chart-header">
                    <div class="chart-title">
                        <i class="fas fa-chart-line"></i> Overdue Books Trend
                    </div>
                    <div class="chart-actions">
                        <button class="chart-action-btn" onclick="exportChart('overdueChart', 'overdue_trends')">
                            <i class="fas fa-image"></i> Export
                        </button>
                    </div>
                </div>
                <div class="chart-container">
                    <canvas id="overdueChart"></canvas>
                </div>
            </div>

            <!-- Top Categories -->
            <div class="chart-card">
                <div class="chart-header">
                    <div class="chart-title">
                        <i class="fas fa-chart-pie"></i> Popular Categories
                    </div>
                    <div class="chart-actions">
                        <button class="chart-action-btn" onclick="exportChart('categoryChart', 'top_categories')">
                            <i class="fas fa-image"></i> Export
                        </button>
                    </div>
                </div>
                <div class="chart-container">
                    <canvas id="categoryChart"></canvas>
                </div>
            </div>

            <!-- Fines Collected -->
            <div class="chart-card">
                <div class="chart-header">
                    <div class="chart-title">
                        <i class="fas fa-dollar-sign"></i> Revenue from Fines
                    </div>
                    <div class="chart-actions">
                        <button class="chart-action-btn" onclick="exportChart('finesChart', 'fines_revenue')">
                            <i class="fas fa-image"></i> Export
                        </button>
                    </div>
                </div>
                <div class="chart-container">
                    <canvas id="finesChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Top 3 Borrowers -->
    <div class="card">
        <div class="card-header">
            <i class="fas fa-trophy"></i> Top Borrowers of the Month
        </div>
        <div id="topBorrowersContainer" class="top-borrowers-grid">
            <div class="empty-state">
                <i class="fas fa-spinner fa-spin"></i>
                <p>Loading top borrowers...</p>
            </div>
        </div>
    </div>

    <!-- Tabbed Content -->
    <div class="card">
        <ul class="nav nav-tabs" id="reportTabs" role="tablist">
            <li class="nav-item">
                <a class="nav-link active" data-bs-toggle="tab" href="#analyticsTab" role="tab">
                    <i class="fas fa-chart-area"></i> Analytics Dashboard
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#customReportTab" role="tab">
                    <i class="fas fa-file-alt"></i> Custom Report Builder
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#classListTab" role="tab">
                    <i class="fas fa-list"></i> Class Student List
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#templatesTab" role="tab">
                    <i class="fas fa-bookmark"></i> Saved Templates
                </a>
            </li>
        </ul>

        <div class="tab-content">
            <!-- Analytics Dashboard Tab -->
            <div id="analyticsTab" class="tab-pane fade show active">
                <h5 style="color: var(--primary); margin-bottom: 1rem;">
                    <i class="fas fa-info-circle"></i> Analytics Dashboard Overview
                </h5>
                <p style="color: var(--secondary); line-height: 1.6;">
                    The analytics dashboard provides real-time insights into library operations with interactive charts and statistics.
                    Use the global filters above to customize the date range and class selection. Each chart can be exported as an image.
                </p>
                <div style="margin-top: 1.5rem;">
                    <button class="btn btn-primary" onclick="refreshAllData()">
                        <i class="fas fa-sync-alt"></i> Refresh All Analytics
                    </button>
                </div>
            </div>

            <!-- Custom Report Builder Tab -->
            <div id="customReportTab" class="tab-pane fade">
                <h5 style="color: var(--primary); margin-bottom: 1.5rem;">
                    <i class="fas fa-sliders-h"></i> Custom Report Builder
                </h5>

                <!-- Wizard Steps -->
                <div class="wizard-steps">
                    <div class="wizard-step active" id="step1">
                        <div class="step-number">1</div>
                        <div>Data Source</div>
                    </div>
                    <div class="wizard-step" id="step2">
                        <div class="step-number">2</div>
                        <div>Select Fields</div>
                    </div>
                    <div class="wizard-step" id="step3">
                        <div class="step-number">3</div>
                        <div>Apply Filters</div>
                    </div>
                    <div class="wizard-step" id="step4">
                        <div class="step-number">4</div>
                        <div>Generate</div>
                    </div>
                </div>

                <!-- Step 1: Data Source -->
                <div id="wizard-step-content-1" class="wizard-content">
                    <div class="form-group">
                        <label>Report Type <span class="text-danger">*</span></label>
                        <select id="report_type" class="form-control" required>
                            <option value="">Select Data Source</option>
                            <option value="borrowing">📚 Borrowing Records</option>
                            <option value="fines">💰 Fine Records</option>
                            <option value="reservations">📅 Reservations</option>
                            <option value="books">📖 Books Inventory</option>
                        </select>
                    </div>
                    <button type="button" class="btn btn-primary" onclick="nextWizardStep(2)">
                        Next <i class="fas fa-arrow-right"></i>
                    </button>
                </div>

                <!-- Step 2: Select Fields -->
                <div id="wizard-step-content-2" class="wizard-content" style="display:none;">
                    <div class="form-group">
                        <label>Choose Fields <span class="text-danger">*</span></label>
                        <div class="dynamic-fields-container" id="fieldsContainer">
                            <!-- Fields will be populated dynamically -->
                        </div>
                    </div>
                    <div style="display:flex; gap:1rem;">
                        <button type="button" class="btn btn-secondary" onclick="previousWizardStep(1)">
                            <i class="fas fa-arrow-left"></i> Previous
                        </button>
                        <button type="button" class="btn btn-primary" onclick="nextWizardStep(3)">
                            Next <i class="fas fa-arrow-right"></i>
                        </button>
                    </div>
                </div>

                <!-- Step 3: Filters -->
                <div id="wizard-step-content-3" class="wizard-content" style="display:none;">
                    <div class="filter-bar">
                        <div class="form-group">
                            <label>From Date</label>
                            <input type="date" id="report_date_from" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>To Date</label>
                            <input type="date" id="report_date_to" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Class Filter</label>
                            <select id="report_class" class="form-control">
                                <option value="all">All Classes</option>
                                <option value="1 Amanah">Form 1 Amanah</option>
                                <option value="1 Bijak">Form 1 Bijak</option>
                                <option value="2 Amanah">Form 2 Amanah</option>
                                <option value="2 Bijak">Form 2 Bijak</option>
                                <option value="3 Amanah">Form 3 Amanah</option>
                                <option value="3 Bijak">Form 3 Bijak</option>
                                <option value="4 Amanah">Form 4 Amanah</option>
                                <option value="4 Bijak">Form 4 Bijak</option>
                                <option value="5 Amanah">Form 5 Amanah</option>
                                <option value="5 Bijak">Form 5 Bijak</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Template Name (optional)</label>
                        <input type="text" id="template_name" class="form-control" placeholder="Save as template for future use">
                    </div>
                    <div class="form-group">
                        <label>Template Description</label>
                        <textarea id="template_description" class="form-control" rows="2" placeholder="Brief description of this report"></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-check-label">
                            <input type="checkbox" id="is_public_template" class="form-check-input">
                            Make this template public (visible to all librarians)
                        </label>
                    </div>
                    <div style="display:flex; gap:1rem;">
                        <button type="button" class="btn btn-secondary" onclick="previousWizardStep(2)">
                            <i class="fas fa-arrow-left"></i> Previous
                        </button>
                        <button type="button" class="btn btn-primary" onclick="nextWizardStep(4)">
                            Next <i class="fas fa-arrow-right"></i>
                        </button>
                    </div>
                </div>

                <!-- Step 4: Generate -->
                <div id="wizard-step-content-4" class="wizard-content" style="display:none;">
                    <h6 style="color: var(--primary); margin-bottom: 1rem;">Report Actions</h6>
                    <div style="display:flex; gap:1rem; flex-wrap: wrap;">
                        <button type="button" class="btn btn-primary" onclick="previewReport()">
                            <i class="fas fa-eye"></i> Preview Report
                        </button>
                        <button type="button" class="btn btn-success" onclick="exportReport('pdf')">
                            <i class="fas fa-file-pdf"></i> Export PDF
                        </button>
                        <button type="button" class="btn btn-success" onclick="exportReport('excel')">
                            <i class="fas fa-file-excel"></i> Export Excel
                        </button>
                        <button type="button" class="btn btn-warning" onclick="saveReportTemplate()">
                            <i class="fas fa-save"></i> Save Template
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="resetWizard()">
                            <i class="fas fa-redo"></i> Start Over
                        </button>
                    </div>

                    <!-- Preview Table -->
                    <div id="previewContainer" style="display: none; margin-top: 2rem;">
                        <h6 style="color: var(--primary); margin-bottom: 1rem;">Report Preview (First 100 records)</h6>
                        <div class="table-responsive">
                            <table class="table" id="previewTable">
                                <thead></thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Class Student List Tab -->
            <div id="classListTab" class="tab-pane fade">
                <h5 style="color: var(--primary); margin-bottom: 1.5rem;">
                    <i class="fas fa-list-alt"></i> Generate Class Student List
                </h5>
                
                <div class="filter-bar">
                    <div class="form-group">
                        <label>Select Class <span class="text-danger">*</span></label>
                        <select id="class_select" class="form-control" required>
                            <option value="">Select Class</option>
                            <option value="1 Amanah">Form 1 Amanah</option>
                            <option value="1 Bijak">Form 1 Bijak</option>
                            <option value="2 Amanah">Form 2 Amanah</option>
                            <option value="2 Bijak">Form 2 Bijak</option>
                            <option value="3 Amanah">Form 3 Amanah</option>
                            <option value="3 Bijak">Form 3 Bijak</option>
                            <option value="4 Amanah">Form 4 Amanah</option>
                            <option value="4 Bijak">Form 4 Bijak</option>
                            <option value="5 Amanah">Form 5 Amanah</option>
                            <option value="5 Bijak">Form 5 Bijak</option>
                        </select>
                    </div>
                    <div style="align-self: end; display: flex; gap: 0.5rem;">
                        <button class="btn btn-primary" onclick="loadClassStudents()">
                            <i class="fas fa-search"></i> Load Students
                        </button>
                        <button class="btn btn-success" onclick="exportClassList('pdf')" disabled id="exportPdfBtn">
                            <i class="fas fa-file-pdf"></i> PDF
                        </button>
                        <button class="btn btn-success" onclick="exportClassList('excel')" disabled id="exportExcelBtn">
                            <i class="fas fa-file-excel"></i> Excel
                        </button>
                    </div>
                </div>

                <div id="classStudentsContainer" class="table-responsive" style="margin-top: 1.5rem; display: none;">
                    <table class="table" id="classStudentsTable">
                        <thead>
                            <tr>
                                <th>Student ID</th>
                                <th>Name</th>
                                <th>Class</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>Status</th>
                                <th>Total Borrowed</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>

            <!-- Saved Templates Tab -->
            <div id="templatesTab" class="tab-pane fade">
                <h5 style="color: var(--primary); margin-bottom: 1.5rem;">
                    <i class="fas fa-bookmark"></i> Saved Report Templates
                </h5>
                
                <div class="template-grid" id="templatesGrid">
                    <?php if ($templates_result && $templates_result->num_rows > 0): ?>
                        <?php while ($template = $templates_result->fetch_assoc()): ?>
                            <div class="template-card">
                                <div class="template-name"><?php echo htmlspecialchars($template['template_name']); ?></div>
                                <div class="template-meta">
                                    <i class="fas fa-database"></i> <?php echo ucfirst($template['data_source']); ?> Report
                                </div>
                                <div class="template-meta">
                                    <i class="fas fa-calendar"></i> Created: <?php echo date('M d, Y', strtotime($template['created_date'])); ?>
                                </div>
                                <?php if ($template['is_public']): ?>
                                    <div class="template-meta">
                                        <i class="fas fa-globe"></i> Public Template
                                    </div>
                                <?php else: ?>
                                    <div class="template-meta">
                                        <i class="fas fa-lock"></i> Private
                                    </div>
                                <?php endif; ?>
                                <div class="template-actions">
                                    <button class="btn btn-primary" style="flex: 1; padding: 0.5rem;" 
                                            onclick="loadSavedTemplate(<?php echo $template['templateID']; ?>)">
                                        <i class="fas fa-download"></i> Load
                                    </button>
                                    <button class="btn btn-danger" style="padding: 0.5rem;" 
                                            onclick="deleteTemplateConfirm(<?php echo $template['templateID']; ?>)"
                                            title="Delete Template">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="empty-state" style="grid-column: 1/-1;">
                            <i class="fas fa-inbox"></i>
                            <p>No saved templates yet.</p>
                            <p style="font-size: 0.9rem;">Create and save your custom reports from the Custom Report Builder tab!</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- Keyboard Shortcuts Help -->
<div class="shortcuts-help tooltip-custom" data-tooltip="Press ? for keyboard shortcuts">
    <i class="fas fa-keyboard"></i> Shortcuts
</div>

<!-- Scripts -->
<script>
// Global Variables
let chartInstances = {};
let currentWizardStep = 1;
let selectedFields = [];
const fieldOptions = {
    borrowing: [
        { value: 'borrowID', label: 'Borrow ID' },
        { value: 'userName', label: 'Borrower Name' },
        { value: 'userType', label: 'User Type' },
        { value: 'studentID', label: 'Student ID' },
        { value: 'class', label: 'Class' },
        { value: 'bookTitle', label: 'Book Title' },
        { value: 'author', label: 'Author' },
        { value: 'category', label: 'Category' },
        { value: 'borrowDate', label: 'Borrow Date' },
        { value: 'dueDate', label: 'Due Date' },
        { value: 'status', label: 'Status' }
    ],
    fines: [
        { value: 'fineID', label: 'Fine ID' },
        { value: 'userName', label: 'Student Name' },
        { value: 'studentID', label: 'Student ID' },
        { value: 'class', label: 'Class' },
        { value: 'bookTitle', label: 'Book Title' },
        { value: 'amount', label: 'Fine Amount' },
        { value: 'reason', label: 'Reason' },
        { value: 'date', label: 'Fine Date' },
        { value: 'paymentStatus', label: 'Payment Status' }
    ],
    reservations: [
        { value: 'reservationID', label: 'Reservation ID' },
        { value: 'userName', label: 'Student Name' },
        { value: 'studentID', label: 'Student ID' },
        { value: 'class', label: 'Class' },
        { value: 'bookTitle', label: 'Book Title' },
        { value: 'date', label: 'Reserved Date' },
        { value: 'queue', label: 'Queue Position' },
        { value: 'status', label: 'Status' }
    ],
    books: [
        { value: 'bookID', label: 'Book ID' },
        { value: 'title', label: 'Title' },
        { value: 'author', label: 'Author' },
        { value: 'category', label: 'Category' },
        { value: 'status', label: 'Status' },
        { value: 'isbn', label: 'ISBN' },
        { value: 'location', label: 'Shelf Location' },
        { value: 'acquisition', label: 'Acquisition Date' }
    ]
};

// Initialize on page load
$(document).ready(function() {
    // Sidebar toggle
    $('#sidebarToggle').click(function() {
        $('#sidebar').toggleClass('collapsed');
        $('#mainContent').toggleClass('collapsed');
    });

    // Initialize date pickers with presets
    initializeDatePickers();

    // Set default dates to current month
    const today = new Date();
    const firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
    
    $('#filter_date_from').flatpickr({
        defaultDate: firstDay,
        dateFormat: 'Y-m-d',
        onChange: function() {
            $('#filter_date_to')[0]._flatpickr.set('minDate', this.selectedDates[0]);
        }
    });
    
    $('#filter_date_to').flatpickr({
        defaultDate: today,
        dateFormat: 'Y-m-d',
        onChange: function() {
            $('#filter_date_from')[0]._flatpickr.set('maxDate', this.selectedDates[0]);
        }
    });

    // Initialize tabs
    $('#reportTabs a').on('click', function(e) {
        e.preventDefault();
        $(this).tab('show');
    });

    // Initial data load
    refreshAllData();

    // Keyboard shortcuts
    $(document).keydown(function(e) {
        // Ctrl + S to save template
        if (e.ctrlKey && e.key === 's') {
            e.preventDefault();
            if ($('#customReportTab').hasClass('show')) {
                saveReportTemplate();
            }
        }
        
        // ? for help
        if (e.key === '?') {
            e.preventDefault();
            showShortcutsHelp();
        }
    });
});

// Date Picker Initialization
function initializeDatePickers() {
    const presets = [
        { label: 'Today', days: 0 },
        { label: 'Yesterday', days: 1 },
        { label: 'Last 7 Days', days: 7 },
        { label: 'Last 30 Days', days: 30 },
        { label: 'This Month', days: 'month' },
        { label: 'Last Month', days: 'lastMonth' },
        { label: 'This Year', days: 'year' }
    ];

    function createPresetDropdown(pickerId, presetId) {
        const $presets = $('#' + presetId);
        presets.forEach(preset => {
            const $item = $('<div>').addClass('preset-item').text(preset.label);
            $item.click(() => applyPreset(pickerId, preset));
            $presets.append($item);
        });
    }

    createPresetDropdown('filter_date_from', 'fromPresets');
    createPresetDropdown('filter_date_to', 'toPresets');
}

function applyPreset(pickerId, preset) {
    const $picker = $('#' + pickerId);
    const today = new Date();
    let date;

    switch (preset.days) {
        case 0: // Today
            date = today;
            break;
        case 1: // Yesterday
            date = new Date(today.getTime() - 86400000);
            break;
        case 7: // Last 7 days
            date = new Date(today.getTime() - (7 * 86400000));
            break;
        case 30: // Last 30 days
            date = new Date(today.getTime() - (30 * 86400000));
            break;
        case 'month': // This month
            date = new Date(today.getFullYear(), today.getMonth(), 1);
            break;
        case 'lastMonth': // Last month
            date = new Date(today.getFullYear(), today.getMonth() - 1, 1);
            break;
        case 'year': // This year
            date = new Date(today.getFullYear(), 0, 1);
            break;
    }

    $picker[0]._flatpickr.setDate(date);
    $picker.closest('.date-range-container').find('.range-presets').removeClass('show');
}

// Data Refresh Functions
function refreshAllData() {
    const dateFrom = $('#filter_date_from').val();
    const dateTo = $('#filter_date_to').val();
    const classFilter = $('#filter_class').val();

    if (!dateFrom || !dateTo) {
        Swal.fire('Error', 'Please select date range', 'error');
        return;
    }

    showLoading(true);
    showAlert('info', 'Refreshing data...');

    // Load all data concurrently
    Promise.all([
        loadSummaryStats(dateFrom, dateTo, classFilter),
        loadChartData(dateFrom, dateTo, classFilter),
        loadTopBorrowers(dateFrom, dateTo, classFilter)
    ]).then(() => {
        showLoading(false);
        showAlert('success', 'Data refreshed successfully!');
    }).catch(error => {
        showLoading(false);
        showAlert('error', 'Failed to refresh data');
    });
}

function loadSummaryStats(dateFrom, dateTo, classFilter) {
    return $.post('report_management.php', {
        ajax_action: 'get_summary_stats',
        csrf_token: $('#csrf_token').val(),
        date_from: dateFrom,
        date_to: dateTo,
        class_filter: classFilter
    }).then(response => {
        if (response.success) {
            updateStats(response.data);
        }
    });
}

function loadChartData(dateFrom, dateTo, classFilter) {
    return $.post('report_management.php', {
        ajax_action: 'get_chart_data',
        csrf_token: $('#csrf_token').val(),
        date_from: dateFrom,
        date_to: dateTo,
        class_filter: classFilter
    }).then(response => {
        if (response.success) {
            renderCharts(response.data);
        }
    });
}

function loadTopBorrowers(dateFrom, dateTo, classFilter) {
    return $.post('report_management.php', {
        ajax_action: 'get_top_borrowers',
        csrf_token: $('#csrf_token').val(),
        date_from: dateFrom,
        date_to: dateTo,
        class_filter: classFilter
    }).then(response => {
        if (response.success) {
            displayTopBorrowers(response.borrowers);
        }
    });
}

function updateStats(data) {
    const $stats = $('#statsGrid .stat-value');
    $($stats[0]).text(data.total_books || 0);
    $($stats[1]).text(data.borrowed_books || 0);
    $($stats[2]).text(data.overdue_books || 0);
    $($stats[3]).text(data.active_users || 0);
}

function renderCharts(data) {
    // Destroy existing charts
    Object.values(chartInstances).forEach(chart => chart.destroy());
    chartInstances = {};

    // Monthly Borrowed Chart
    const monthlyCtx = document.getElementById('monthlyChart').getContext('2d');
    chartInstances['monthly'] = new Chart(monthlyCtx, {
        type: 'bar',
        data: {
            labels: data.monthly_borrowed.map(d => d.month),
            datasets: [
                {
                    label: 'Students',
                    data: data.monthly_borrowed.map(d => d.student_count),
                    backgroundColor: '#3b82f6',
                    borderColor: '#1e3a8a',
                    borderWidth: 1
                },
                {
                    label: 'Staff',
                    data: data.monthly_borrowed.map(d => d.staff_count),
                    backgroundColor: '#10b981',
                    borderColor: '#059669',
                    borderWidth: 1
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'top' },
                tooltip: { mode: 'index', intersect: false }
            },
            scales: {
                y: { beginAtZero: true, stacked: true },
                x: { stacked: true }
            }
        }
    });

    // Overdue Chart
    const overdueCtx = document.getElementById('overdueChart').getContext('2d');
    chartInstances['overdue'] = new Chart(overdueCtx, {
        type: 'line',
        data: {
            labels: data.overdue_trends.map(d => d.month),
            datasets: [{
                label: 'Overdue Books',
                data: data.overdue_trends.map(d => d.count),
                borderColor: '#f59e0b',
                backgroundColor: 'rgba(245, 158, 11, 0.1)',
                fill: true,
                tension: 0.4,
                pointRadius: 4,
                pointHoverRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: { beginAtZero: true }
            }
        }
    });

    // Category Chart
    const categoryCtx = document.getElementById('categoryChart').getContext('2d');
    const colors = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899', '#14b8a6', '#f97316'];
    chartInstances['category'] = new Chart(categoryCtx, {
        type: 'doughnut',
        data: {
            labels: data.top_categories.map(d => d.categoryName),
            datasets: [{
                data: data.top_categories.map(d => d.count),
                backgroundColor: colors,
                borderWidth: 2,
                borderColor: '#fff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'right',
                    labels: { boxWidth: 15 }
                },
                datalabels: {
                    color: '#fff',
                    font: { weight: 'bold' },
                    formatter: (value, ctx) => {
                        const sum = ctx.dataset.data.reduce((a,b) => a + b, 0);
                        return ((value / sum) * 100).toFixed(1) + '%';
                    }
                }
            }
        }
    });

    // Fines Chart
    const finesCtx = document.getElementById('finesChart').getContext('2d');
    chartInstances['fines'] = new Chart(finesCtx, {
        type: 'bar',
        data: {
            labels: data.fines_collected.map(d => d.month),
            datasets: [{
                label: 'Fines Collected (RM)',
                data: data.fines_collected.map(d => d.total),
                backgroundColor: '#10b981',
                borderColor: '#059669',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: { 
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return 'RM ' + value;
                        }
                    }
                }
            }
        }
    });
}

function displayTopBorrowers(borrowers) {
    const $container = $('#topBorrowersContainer');
    $container.empty();

    if (borrowers.length === 0) {
        $container.html('<div class="empty-state" style="grid-column: 1/-1;"><i class="fas fa-inbox"></i><p>No borrowing data found for the selected period</p></div>');
        return;
    }

    const rankings = ['1st', '2nd', '3rd'];
    const rankClasses = ['rank-1', 'rank-2', 'rank-3'];

    borrowers.forEach((borrower, index) => {
        const initial = borrower.name.charAt(0).toUpperCase();
        const profileImage = borrower.profile_image || 'photo/default.png';
        const userTypeIcon = borrower.user_type === 'student' ? 'fa-user-graduate' : 'fa-user-tie';
        
        const card = `
            <div class="borrower-card ${rankClasses[index]}">
                <div class="rank-badge ${rankClasses[index]}">${rankings[index]}</div>
                <div class="borrower-avatar">
                    ${borrower.profile_image ? 
                        `<img src="${profileImage}" alt="${borrower.name}" onerror="this.src='photo/default.png'">` : 
                        initial
                    }
                </div>
                <div class="borrower-name">${borrower.name}</div>
                <div class="borrower-info">
                    <i class="fas ${userTypeIcon}"></i> ${borrower.user_type || 'N/A'}
                </div>
                <div class="borrower-info">
                    <i class="fas fa-id-card"></i> ${borrower.student_id_number || borrower.staff_id_number || 'N/A'}
                </div>
                ${borrower.studentClass ? 
                    `<div class="borrower-info"><i class="fas fa-school"></i> ${borrower.studentClass}</div>` : ''
                }
                <div class="borrow-count">
                    <i class="fas fa-book-reader"></i> ${borrower.borrow_count} Books
                </div>
                <button class="btn btn-success" style="width: 100%; margin-top: 1rem; font-size: 0.85rem;" 
                        onclick="generateCertificate('${borrower.name.replace(/'/g, "\\'")}', '${borrower.studentClass || 'N/A'}', '${rankings[index]}', ${borrower.userID || 0})">
                    <i class="fas fa-certificate"></i> Generate Certificate
                </button>
            </div>
        `;
        $container.append(card);
    });
}

// Certificate Generation
function generateCertificate(name, className, rank, userID = 0) {
    Swal.fire({
        title: 'Generating Certificate...',
        text: 'Please wait while we create the certificate PDF',
        allowOutsideClick: false,
        didOpen: () => { Swal.showLoading(); }
    });

    $.post('report_management.php', {
        ajax_action: 'generate_certificate',
        csrf_token: $('#csrf_token').val(),
        student_name: name,
        class_name: className,
        rank: rank,
        user_id: userID
    }, function(response) {
        if (response.success) {
            Swal.fire('Success', response.message, 'success');
            window.open(response.download_url, '_blank');
        } else {
            Swal.fire('Error', response.message || 'Failed to generate certificate', 'error');
        }
    }, 'json').fail(function() {
        Swal.fire('Error', 'Network error occurred', 'error');
    });
}

// Class Student List Functions
function loadClassStudents() {
    const className = $('#class_select').val();
    if (!className) {
        Swal.fire('Error', 'Please select a class', 'error');
        return;
    }

    Swal.fire({
        title: 'Loading Students...',
        allowOutsideClick: false,
        didOpen: () => { Swal.showLoading(); }
    });

    $.post('report_management.php', {
        ajax_action: 'get_class_students',
        csrf_token: $('#csrf_token').val(),
        class_name: className
    }, function(response) {
        if (response.success) {
            displayClassStudents(response.students);
            $('#exportPdfBtn, #exportExcelBtn').prop('disabled', false);
            Swal.fire('Success', `Loaded ${response.students.length} students`, 'success');
        } else {
            Swal.fire('Error', response.message || 'Failed to load students', 'error');
        }
    }, 'json').fail(function() {
        Swal.fire('Error', 'Network error occurred', 'error');
    });
}

function displayClassStudents(students) {
    const $tbody = $('#classStudentsTable tbody');
    $tbody.empty();

    if (students.length === 0) {
        $tbody.html('<tr><td colspan="8" class="empty-state">No students found in this class</td></tr>');
        return;
    }

    students.forEach(student => {
        const statusBadge = student.studentStatus === 'active' ? 'success' : 'secondary';
        const profileImage = student.student_image || 'photo/default.png';
        
        const row = `
            <tr>
                <td>${student.student_id_number}</td>
                <td>
                    <div style="display:flex; align-items:center; gap:0.5rem;">
                        <img src="${profileImage}" alt="${student.studentName}" style="width:30px; height:30px; border-radius:50%; object-fit:cover;" onerror="this.src='photo/default.png'">
                        ${student.studentName}
                    </div>
                </td>
                <td>${student.studentClass}</td>
                <td>${student.studentEmail || '-'}</td>
                <td>${student.studentPhoneNo || '-'}</td>
                <td><span class="badge bg-${statusBadge}">${student.studentStatus}</span></td>
                <td><strong>${student.total_borrowed || 0}</strong> books</td>
                <td>
                    <button class="btn btn-primary btn-sm" onclick="viewStudentDetails('${student.student_id_number}')">
                        <i class="fas fa-eye"></i>
                    </button>
                </td>
            </tr>
        `;
        $tbody.append(row);
    });

    // Initialize DataTable
    if ($.fn.DataTable.isDataTable('#classStudentsTable')) {
        $('#classStudentsTable').DataTable().destroy();
    }

    $('#classStudentsTable').DataTable({
        pageLength: 25,
        language: { search: 'Search students:' },
        dom: 'Bfrtip',
        buttons: ['copy', 'csv', 'excel', 'pdf'],
        order: [[6, 'desc']] // Sort by total borrowed
    });

    $('#classStudentsContainer').show();
}

function viewStudentDetails(studentID) {
    // In a real app, this would open a modal with detailed student info
    Swal.fire('Info', `Viewing details for student: ${studentID}`, 'info');
}

function exportClassList(format) {
    const className = $('#class_select').val();
    if (!className) {
        Swal.fire('Error', 'Please select a class first', 'error');
        return;
    }

    Swal.fire({
        title: 'Generating...',
        allowOutsideClick: false,
        didOpen: () => { Swal.showLoading(); }
    });

    $.post('report_management.php', {
        ajax_action: 'export_class_list',
        csrf_token: $('#csrf_token').val(),
        class_name: className,
        format: format
    }, function(response) {
        if (response.success) {
            Swal.fire('Success', 'File generated successfully!', 'success');
            window.open(response.download_url, '_blank');
        } else {
            Swal.fire('Error', response.message || 'Failed to generate file', 'error');
        }
    }, 'json').fail(function() {
        Swal.fire('Error', 'Network error occurred', 'error');
    });
}

// Custom Report Builder Functions
function nextWizardStep(step) {
    // Validation
    if (step === 2 && !$('#report_type').val()) {
        Swal.fire('Error', 'Please select a report type', 'error');
        return;
    }

    // Hide current step
    $('.wizard-content').hide();
    $('.wizard-step').removeClass('active');

    // Show target step
    $('#wizard-step-content-' + step).show();
    $('#step' + step).addClass('active');

    // Populate fields for step 2
    if (step === 2) {
        populateFields();
    }

    currentWizardStep = step;
}

function previousWizardStep(step) {
    $('.wizard-content').hide();
    $('.wizard-step').removeClass('active');
    $('#wizard-step-content-' + step).show();
    $('#step' + step).addClass('active');
    currentWizardStep = step;
}

function populateFields() {
    const reportType = $('#report_type').val();
    const $container = $('#fieldsContainer');
    $container.empty();

    const fields = fieldOptions[reportType] || [];
    fields.forEach(field => {
        const $field = $(`
            <div class="field-option">
                <input type="checkbox" id="field_${field.value}" value="${field.value}" checked>
                <label for="field_${field.value}">${field.label}</label>
            </div>
        `);
        $container.append($field);
    });
}

function resetWizard() {
    $('.wizard-content').hide();
    $('#wizard-step-content-1').show();
    $('.wizard-step').removeClass('active completed');
    $('#step1').addClass('active');
    $('#previewContainer').hide();
    $('#report_type').val('');
    $('#template_name').val('');
    $('#template_description').val('');
    $('#is_public_template').prop('checked', false);
    currentWizardStep = 1;
}

function previewReport() {
    const reportType = $('#report_type').val();
    const dateFrom = $('#report_date_from').val();
    const dateTo = $('#report_date_to').val();
    const classFilter = $('#report_class').val();

    if (!reportType || !dateFrom || !dateTo) {
        Swal.fire('Error', 'Please fill all required fields', 'error');
        return;
    }

    Swal.fire({
        title: 'Loading Preview...',
        allowOutsideClick: false,
        didOpen: () => { Swal.showLoading(); }
    });

    $.post('report_management.php', {
        ajax_action: 'preview_custom_report',
        csrf_token: $('#csrf_token').val(),
        report_type: reportType,
        date_from: dateFrom,
        date_to: dateTo,
        class_filter: classFilter
    }, function(response) {
        if (response.success) {
            displayPreview(response.columns, response.data);
            Swal.fire('Success', `Preview loaded: ${response.record_count} records`, 'success');
            // Auto-scroll to preview
            $('html, body').animate({
                scrollTop: $('#previewContainer').offset().top - 100
            }, 500);
        } else {
            Swal.fire('Error', response.message || 'Failed to load preview', 'error');
        }
    }, 'json').fail(function() {
        Swal.fire('Error', 'Network error occurred', 'error');
    });
}

function displayPreview(columns, data) {
    const $table = $('#previewTable');
    const $thead = $table.find('thead').empty();
    const $tbody = $table.find('tbody').empty();

    // Build header
    let headerHtml = '<tr>';
    columns.forEach(col => {
        headerHtml += `<th>${col}</th>`;
    });
    headerHtml += '</tr>';
    $thead.html(headerHtml);

    // Build body
    if (data.length === 0) {
        $tbody.html(`<tr><td colspan="${columns.length}" style="text-align:center; padding:2rem;">No data found</td></tr>`);
    } else {
        data.forEach(row => {
            let rowHtml = '<tr>';
            columns.forEach(col => {
                rowHtml += `<td>${row[col] || '-'}</td>`;
            });
            rowHtml += '</tr>';
            $tbody.append(rowHtml);
        });
    }

    // Initialize DataTable
    if ($.fn.DataTable.isDataTable('#previewTable')) {
        $('#previewTable').DataTable().destroy();
    }
    $('#previewTable').DataTable({ pageLength: 10 });

    $('#previewContainer').show();
}

function exportReport(format) {
    const reportType = $('#report_type').val();
    const dateFrom = $('#report_date_from').val();
    const dateTo = $('#report_date_to').val();
    const classFilter = $('#report_class').val();

    if (!reportType || !dateFrom || !dateTo) {
        Swal.fire('Error', 'Please fill all required fields', 'error');
        return;
    }

    Swal.fire({
        title: 'Generating Report...',
        html: 'Please wait while we create your file<br><div class="progress" style="margin-top:10px;"><div class="progress-bar" style="width:0%"></div></div>',
        allowOutsideClick: false,
        didOpen: () => { Swal.showLoading(); }
    });

    // Simulate progress
    let progress = 0;
    const progressInterval = setInterval(() => {
        progress += 10;
        $('.progress-bar').css('width', progress + '%');
        if (progress >= 90) clearInterval(progressInterval);
    }, 200);

    $.post('report_management.php', {
        ajax_action: 'export_custom_report',
        csrf_token: $('#csrf_token').val(),
        report_type: reportType,
        date_from: dateFrom,
        date_to: dateTo,
        class_filter: classFilter,
        format: format
    }, function(response) {
        clearInterval(progressInterval);
        $('.progress-bar').css('width', '100%');
        
        if (response.success) {
            Swal.fire('Success', `Report generated! ${response.record_count} records exported`, 'success');
            window.open(response.download_url, '_blank');
        } else {
            Swal.fire('Error', response.message || 'Failed to generate report', 'error');
        }
    }, 'json').fail(function() {
        clearInterval(progressInterval);
        Swal.fire('Error', 'Network error occurred', 'error');
    });
}

function saveReportTemplate() {
    const templateName = $('#template_name').val();
    const reportType = $('#report_type').val();
    const dateFrom = $('#report_date_from').val();
    const dateTo = $('#report_date_to').val();
    const classFilter = $('#report_class').val();
    const description = $('#template_description').val();
    const isPublic = $('#is_public_template').is(':checked');

    if (!templateName) {
        Swal.fire('Error', 'Please enter a template name', 'error');
        return;
    }

    if (!reportType) {
        Swal.fire('Error', 'Please select a report type first', 'error');
        return;
    }

    Swal.fire({
        title: 'Saving Template...',
        allowOutsideClick: false,
        didOpen: () => { Swal.showLoading(); }
    });

    const params = {
        ajax_action: 'save_template',
        csrf_token: $('#csrf_token').val(),
        template_name: templateName,
        report_type: reportType,
        date_from: dateFrom,
        date_to: dateTo,
        class_filter: classFilter,
        description: description,
        is_public: isPublic
    };

    $.post('report_management.php', params, function(response) {
        if (response.success) {
            Swal.fire('Success', response.message, 'success');
            // Reload templates tab
            setTimeout(() => location.reload(), 1500);
        } else {
            Swal.fire('Error', response.message || 'Failed to save template', 'error');
        }
    }, 'json').fail(function() {
        Swal.fire('Error', 'Network error occurred', 'error');
    });
}

function loadSavedTemplate(templateID) {
    Swal.fire({
        title: 'Loading Template...',
        allowOutsideClick: false,
        didOpen: () => { Swal.showLoading(); }
    });

    $.post('report_management.php', {
        ajax_action: 'load_template',
        csrf_token: $('#csrf_token').val(),
        template_id: templateID
    }, function(response) {
        if (response.success) {
            const filters = response.filters;
            
            // Switch to Custom Report Builder tab
            $('a[href="#customReportTab"]').tab('show');
            
            // Populate form
            $('#report_type').val(filters.report_type || '');
            $('#report_date_from').val(filters.date_from || '');
            $('#report_date_to').val(filters.date_to || '');
            $('#report_class').val(filters.class_filter || 'all');
            $('#template_name').val(response.template.template_name);
            $('#template_description').val(response.template.template_description || '');
            $('#is_public_template').prop('checked', response.template.is_public == 1);
            
            Swal.fire('Success', 'Template loaded successfully!', 'success');
            
            // Go to step 4
            setTimeout(() => nextWizardStep(4), 500);
        } else {
            Swal.fire('Error', response.message || 'Failed to load template', 'error');
        }
    }, 'json').fail(function() {
        Swal.fire('Error', 'Network error occurred', 'error');
    });
}

function deleteTemplateConfirm(templateID) {
    Swal.fire({
        title: 'Are you sure?',
        text: 'This will permanently delete the template. You cannot undo this action.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('report_management.php', {
                ajax_action: 'delete_template',
                csrf_token: $('#csrf_token').val(),
                template_id: templateID
            }, function(response) {
                if (response.success) {
                    Swal.fire('Deleted!', response.message, 'success');
                    location.reload();
                } else {
                    Swal.fire('Error', response.message || 'Failed to delete template', 'error');
                }
            }, 'json').fail(function() {
                Swal.fire('Error', 'Network error occurred', 'error');
            });
        }
    });
}

// Export Analytics Dashboard
function exportAnalytics() {
    const dateFrom = $('#filter_date_from').val();
    const dateTo = $('#filter_date_to').val();

    if (!dateFrom || !dateTo) {
        Swal.fire('Error', 'Please select date range', 'error');
        return;
    }

    Swal.fire({
        title: 'Generating Statistical Report...',
        html: 'Creating comprehensive PDF report<br><div class="progress" style="margin-top:10px;"><div class="progress-bar" style="width:0%"></div></div>',
        allowOutsideClick: false,
        didOpen: () => { Swal.showLoading(); }
    });

    // Simulate progress
    let progress = 0;
    const progressInterval = setInterval(() => {
        progress += 5;
        $('.progress-bar').css('width', progress + '%');
        if (progress >= 90) clearInterval(progressInterval);
    }, 100);

    $.post('report_management.php', {
        ajax_action: 'export_analytics',
        csrf_token: $('#csrf_token').val(),
        date_from: dateFrom,
        date_to: dateTo
    }, function(response) {
        clearInterval(progressInterval);
        $('.progress-bar').css('width', '100%');
        
        if (response.success) {
            Swal.fire('Success', 'Statistical analysis report generated!', 'success');
            window.open(response.download_url, '_blank');
        } else {
            Swal.fire('Error', response.message || 'Failed to generate report', 'error');
        }
    }, 'json').fail(function() {
        clearInterval(progressInterval);
        Swal.fire('Error', 'Network error occurred', 'error');
    });
}

// Export Chart as Image
function exportChart(chartId, filename) {
    const chart = chartInstances[chartId];
    if (!chart) {
        Swal.fire('Error', 'Chart not found', 'error');
        return;
    }

    const url = chart.toBase64Image();
    const link = document.createElement('a');
    link.download = filename + '_' + Date.now() + '.png';
    link.href = url;
    link.click();
    Swal.fire('Success', 'Chart exported as PNG', 'success');
}

// UI Helper Functions
function showLoading(show) {
    $('#loadingOverlay').toggle(show);
}

function showAlert(type, message) {
    // Use SweetAlert2 for consistency
    const icon = type === 'success' ? 'success' : type === 'error' ? 'error' : 'info';
    Swal.fire({ icon: icon, title: message, toast: true, position: 'top-end', showConfirmButton: false, timer: 3000 });
}

function showShortcutsHelp() {
    Swal.fire({
        title: 'Keyboard Shortcuts',
        html: `
            <div style="text-align:left;">
                <strong>Global Shortcuts:</strong><br>
                <kbd>Ctrl</kbd> + <kbd>S</kbd> - Save current template (in Custom Report tab)<br>
                <kbd>?</kbd> - Show this help dialog<br><br>
                <strong>Navigation:</strong><br>
                <kbd>1-4</kbd> - Switch to tab 1-4<br>
                <kbd>R</kbd> - Refresh all data<br><br>
                <strong>Report Builder:</strong><br>
                <kbd>Enter</kbd> - Preview report<br>
                <kbd>Shift</kbd> + <kbd>Enter</kbd> - Export PDF
            </div>
        `,
        icon: 'info',
        width: 600
    });
}

// Keyboard Navigation
$(document).keydown(function(e) {
    // Tab navigation with numbers
    if (e.key >= '1' && e.key <= '4') {
        const tabIndex = parseInt(e.key) - 1;
        $('#reportTabs .nav-link').eq(tabIndex).tab('show');
    }
    
    // R for refresh
    if (e.key.toLowerCase() === 'r' && !e.ctrlKey && !e.shiftKey) {
        e.preventDefault();
        refreshAllData();
    }
    
    // Shift + Enter for export PDF
    if (e.shiftKey && e.key === 'Enter' && $('#customReportTab').hasClass('show')) {
        e.preventDefault();
        exportReport('pdf');
    }
});
</script>
</body>
</html>
