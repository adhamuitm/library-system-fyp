<?php
session_start();
require_once 'dbconnect.php';
require_once 'auth_helper.php';
require_once __DIR__ . '/vendor/autoload.php';

use Mpdf\Mpdf;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

checkPageAccess();
requireRole('librarian');

$librarian_info = getCurrentUser();
$librarian_name = getUserDisplayName();

// Get librarian ID
$librarian_id_query = "SELECT librarianID FROM librarian WHERE librarianEmail = ?";
$stmt = $conn->prepare($librarian_id_query);
$stmt->bind_param("s", $librarian_info['email']);
$stmt->execute();
$result = $stmt->get_result();
$librarian_data = $result->fetch_assoc();
$current_librarian_id = $librarian_data['librarianID'] ?? 1;

// Handle AJAX requests
if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    
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
    exit;
}

// Get Summary Statistics
function getSummaryStats($params) {
    global $conn;
    
    $dateFrom = $conn->real_escape_string($params['date_from']);
    $dateTo = $conn->real_escape_string($params['date_to']);
    $classFilter = isset($params['class_filter']) && $params['class_filter'] !== 'all' ? $conn->real_escape_string($params['class_filter']) : '';
    
    $data = [];
    
    // Total Books
    $query = "SELECT COUNT(*) as count FROM book WHERE bookStatus != 'disposed'";
    $result = $conn->query($query);
    $data['total_books'] = $result->fetch_assoc()['count'];
    
    // Total Borrowed Books
    $query = "SELECT COUNT(DISTINCT b.borrowID) as count FROM borrow b";
    if ($classFilter) {
        $query .= " JOIN user u ON b.userID = u.userID 
                    JOIN student s ON u.userID = s.userID 
                    WHERE s.studentClass = '$classFilter' AND";
    } else {
        $query .= " WHERE";
    }
    $query .= " b.borrow_date BETWEEN '$dateFrom' AND '$dateTo'";
    $result = $conn->query($query);
    $data['borrowed_books'] = $result->fetch_assoc()['count'];
    
    // Overdue Books
    $query = "SELECT COUNT(*) as count FROM borrow b";
    if ($classFilter) {
        $query .= " JOIN user u ON b.userID = u.userID 
                    JOIN student s ON u.userID = s.userID 
                    WHERE s.studentClass = '$classFilter' AND";
    } else {
        $query .= " WHERE";
    }
    $query .= " b.borrow_status = 'overdue' AND b.due_date BETWEEN '$dateFrom' AND '$dateTo'";
    $result = $conn->query($query);
    $data['overdue_books'] = $result->fetch_assoc()['count'];
    
    // Active Users
    $query = "SELECT COUNT(DISTINCT b.userID) as count FROM borrow b";
    if ($classFilter) {
        $query .= " JOIN user u ON b.userID = u.userID 
                    JOIN student s ON u.userID = s.userID 
                    WHERE s.studentClass = '$classFilter' AND";
    } else {
        $query .= " WHERE";
    }
    $query .= " b.borrow_date BETWEEN '$dateFrom' AND '$dateTo'";
    $result = $conn->query($query);
    $data['active_users'] = $result->fetch_assoc()['count'];
    
    return ['success' => true, 'data' => $data];
}

// Get Chart Data
function getChartData($params) {
    global $conn;
    
    $dateFrom = $conn->real_escape_string($params['date_from']);
    $dateTo = $conn->real_escape_string($params['date_to']);
    $classFilter = isset($params['class_filter']) && $params['class_filter'] !== 'all' ? $conn->real_escape_string($params['class_filter']) : '';
    
    $data = [];
    
    // Borrowed Books by Month
    $query = "SELECT DATE_FORMAT(b.borrow_date, '%b %Y') as month, COUNT(*) as count
        FROM borrow b";
    if ($classFilter) {
        $query .= " JOIN user u ON b.userID = u.userID 
                    JOIN student s ON u.userID = s.userID 
                    WHERE s.studentClass = '$classFilter' AND";
    } else {
        $query .= " WHERE";
    }
    $query .= " b.borrow_date BETWEEN '$dateFrom' AND '$dateTo'
        GROUP BY DATE_FORMAT(b.borrow_date, '%Y-%m')
        ORDER BY DATE_FORMAT(b.borrow_date, '%Y-%m')";
    $result = $conn->query($query);
    $monthly = [];
    while ($row = $result->fetch_assoc()) {
        $monthly[] = $row;
    }
    $data['monthly_borrowed'] = $monthly;
    
    // Overdue Trends
    $query = "SELECT DATE_FORMAT(b.due_date, '%b %Y') as month, COUNT(*) as count
        FROM borrow b";
    if ($classFilter) {
        $query .= " JOIN user u ON b.userID = u.userID 
                    JOIN student s ON u.userID = s.userID 
                    WHERE s.studentClass = '$classFilter' AND";
    } else {
        $query .= " WHERE";
    }
    $query .= " b.borrow_status = 'overdue' AND b.due_date BETWEEN '$dateFrom' AND '$dateTo'
        GROUP BY DATE_FORMAT(b.due_date, '%Y-%m')
        ORDER BY DATE_FORMAT(b.due_date, '%Y-%m')";
    $result = $conn->query($query);
    $overdue = [];
    while ($row = $result->fetch_assoc()) {
        $overdue[] = $row;
    }
    $data['overdue_trends'] = $overdue;
    
    // Top Categories
    $query = "SELECT bc.categoryName, COUNT(b.borrowID) as count
        FROM borrow b
        JOIN book bk ON b.bookID = bk.bookID
        JOIN book_category bc ON bk.categoryID = bc.categoryID";
    if ($classFilter) {
        $query .= " JOIN user u ON b.userID = u.userID 
                    JOIN student s ON u.userID = s.userID 
                    WHERE s.studentClass = '$classFilter' AND";
    } else {
        $query .= " WHERE";
    }
    $query .= " b.borrow_date BETWEEN '$dateFrom' AND '$dateTo'
        GROUP BY bc.categoryID
        ORDER BY count DESC
        LIMIT 8";
    $result = $conn->query($query);
    $categories = [];
    while ($row = $result->fetch_assoc()) {
        $categories[] = $row;
    }
    $data['top_categories'] = $categories;
    
    // Fines Collected
    $query = "SELECT DATE_FORMAT(f.payment_date, '%b %Y') as month, SUM(f.amount_paid) as total
        FROM fines f";
    if ($classFilter) {
        $query .= " JOIN user u ON f.userID = u.userID 
                    JOIN student s ON u.userID = s.userID 
                    WHERE s.studentClass = '$classFilter' AND";
    } else {
        $query .= " WHERE";
    }
    $query .= " f.payment_date BETWEEN '$dateFrom' AND '$dateTo'
        GROUP BY DATE_FORMAT(f.payment_date, '%Y-%m')
        ORDER BY DATE_FORMAT(f.payment_date, '%Y-%m')";
    $result = $conn->query($query);
    $fines = [];
    while ($row = $result->fetch_assoc()) {
        $fines[] = $row;
    }
    $data['fines_collected'] = $fines;
    
    return ['success' => true, 'data' => $data];
}

// Get Top Borrowers
function getTopBorrowers($params) {
    global $conn;
    
    $dateFrom = $conn->real_escape_string($params['date_from']);
    $dateTo = $conn->real_escape_string($params['date_to']);
    $classFilter = isset($params['class_filter']) && $params['class_filter'] !== 'all' ? $conn->real_escape_string($params['class_filter']) : '';
    
    $query = "SELECT 
        u.userID,
        CONCAT(u.first_name, ' ', u.last_name) as name,
        s.student_id_number,
        s.studentClass,
        COUNT(b.borrowID) as borrow_count
    FROM borrow b
    JOIN user u ON b.userID = u.userID
    JOIN student s ON u.userID = s.userID
    WHERE b.borrow_date BETWEEN '$dateFrom' AND '$dateTo'";
    
    if ($classFilter) {
        $query .= " AND s.studentClass = '$classFilter'";
    }
    
    $query .= " GROUP BY u.userID
    ORDER BY borrow_count DESC
    LIMIT 3";
    
    $result = $conn->query($query);
    $borrowers = [];
    
    while ($row = $result->fetch_assoc()) {
        $borrowers[] = $row;
    }
    
    return ['success' => true, 'borrowers' => $borrowers];
}

// Generate Certificate
function generateCertificate($params) {
    global $conn, $librarian_name;
    
    $studentName = $params['student_name'];
    $className = $params['class_name'];
    $rank = $params['rank'];
    
    $mpdf = new Mpdf(['format' => 'A4-L']);
    
    $html = '
    <html>
    <head>
    <style>
    body {
      font-family: "Times New Roman", serif;
      text-align: center;
      padding: 20px;
      background-color: #ffffff;
    }
    .certificate-border {
      border: 10px solid #1e3a8a;
      padding: 40px;
      min-height: 500px;
    }
    .logo {
      width: 90px;
      margin-top: 20px;
    }
    .title {
      font-size: 32px;
      color: #1e3a8a;
      font-weight: bold;
      margin-top: 20px;
      letter-spacing: 2px;
    }
    .subtitle {
      font-size: 18px;
      color: #3b82f6;
      margin-top: 10px;
    }
    .content {
      margin-top: 50px;
      font-size: 18px;
      line-height: 2;
    }
    .name {
      font-size: 28px;
      font-weight: bold;
      color: #1e3a8a;
      margin: 20px 0;
      text-decoration: underline;
    }
    .class-info {
      font-size: 20px;
      margin: 15px 0;
    }
    .achievement {
      font-size: 22px;
      font-weight: bold;
      color: #3b82f6;
      margin: 25px 0;
    }
    .signature {
      display: table;
      width: 100%;
      margin-top: 80px;
    }
    .signature-box {
      display: table-cell;
      width: 50%;
      text-align: center;
    }
    .signature-line {
      border-top: 2px solid #000;
      width: 220px;
      margin: 0 auto;
      padding-top: 10px;
    }
    .footer {
      margin-top: 40px;
      font-size: 12px;
      color: #666;
    }
    </style>
    </head>
    <body>
      <div class="certificate-border">
        <img src="photo/logo1.png" class="logo" alt="SMK Logo">
        <h1 class="title">SMK CHENDERING LIBRARY</h1>
        <h2 class="subtitle">Recognition Award</h2>
        <div class="content">
          <p>This certificate is proudly presented to</p>
          <div class="name">' . htmlspecialchars($studentName) . '</div>
          <p class="class-info">of <b>' . htmlspecialchars($className) . '</b></p>
          <p>for outstanding participation and excellence as</p>
          <p class="achievement">' . strtoupper($rank) . ' TOP BORROWER OF THE MONTH</p>
          <p>at SMK Chendering Library</p>
        </div>
        <div class="signature">
          <div class="signature-box">
            <div class="signature-line">
              <strong>' . htmlspecialchars($librarian_name) . '</strong><br>
              <span style="font-size: 14px;">Librarian</span>
            </div>
          </div>
          <div class="signature-box">
            <div class="signature-line">
              <strong>' . date('d F Y') . '</strong><br>
              <span style="font-size: 14px;">Date</span>
            </div>
          </div>
        </div>
        <div class="footer">SMK Chendering Library Management System – Official Document</div>
      </div>
    </body>
    </html>';
    
    $mpdf->WriteHTML($html);
    
    $filename = 'certificate_' . preg_replace('/[^a-z0-9]/i', '_', $studentName) . '_' . date('YmdHis') . '.pdf';
    $filepath = 'exports/' . $filename;
    
    if (!is_dir('exports')) {
        mkdir('exports', 0755, true);
    }
    
    $mpdf->Output($filepath, 'F');
    
    return ['success' => true, 'filename' => $filename, 'download_url' => 'exports/' . $filename];
}

// Get Class Students
function getClassStudents($params) {
    global $conn;
    
    $className = $conn->real_escape_string($params['class_name']);
    
    $query = "SELECT 
        s.student_id_number,
        s.studentName,
        s.studentClass,
        s.studentEmail,
        s.studentPhoneNo,
        s.studentStatus,
        COUNT(b.borrowID) as total_borrowed
    FROM student s
    LEFT JOIN user u ON s.userID = u.userID
    LEFT JOIN borrow b ON u.userID = b.userID
    WHERE s.studentClass = '$className'
    GROUP BY s.studentID
    ORDER BY s.studentName";
    
    $result = $conn->query($query);
    $students = [];
    
    while ($row = $result->fetch_assoc()) {
        $students[] = $row;
    }
    
    return ['success' => true, 'students' => $students];
}

// Export Class List
function exportClassList($params) {
    global $conn;
    
    $className = $conn->real_escape_string($params['class_name']);
    $format = $params['format'];
    
    $query = "SELECT 
        s.student_id_number as 'Student ID',
        s.studentName as 'Name',
        s.studentClass as 'Class',
        s.studentEmail as 'Email',
        s.studentPhoneNo as 'Phone',
        s.studentStatus as 'Status'
    FROM student s
    WHERE s.studentClass = '$className'
    ORDER BY s.studentName";
    
    $result = $conn->query($query);
    $students = [];
    
    while ($row = $result->fetch_assoc()) {
        $students[] = $row;
    }
    
    if ($format === 'pdf') {
        $filename = exportClassListPDF($students, $className);
    } else {
        $filename = exportClassListExcel($students, $className);
    }
    
    return ['success' => true, 'filename' => $filename, 'download_url' => 'exports/' . $filename];
}

// Export Class List PDF
function exportClassListPDF($students, $className) {
    $mpdf = new Mpdf(['format' => 'A4']);
    
    $html = '<style>
        body { font-family: Arial, sans-serif; }
        .header { text-align: center; margin-bottom: 20px; border-bottom: 3px solid #1e3a8a; padding-bottom: 10px; }
        .logo { width: 60px; }
        h2 { color: #1e3a8a; margin: 10px 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th { background-color: #1e3a8a; color: white; padding: 10px; text-align: left; }
        td { border: 1px solid #ddd; padding: 8px; }
        tr:nth-child(even) { background-color: #f8fafc; }
        .footer { text-align: center; margin-top: 30px; font-size: 10px; color: #666; }
    </style>';
    
    $html .= '<div class="header">';
    $html .= '<img src="photo/logo1.png" class="logo">';
    $html .= '<h2>SMK CHENDERING LIBRARY MANAGEMENT SYSTEM</h2>';
    $html .= '<p>Class Student List - ' . htmlspecialchars($className) . '</p>';
    $html .= '<p>Generated: ' . date('d F Y, h:i A') . '</p>';
    $html .= '</div>';
    
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
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
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

// Preview Custom Report
function previewCustomReport($params) {
    global $conn;
    
    $reportType = $conn->real_escape_string($params['report_type']);
    $dateFrom = $conn->real_escape_string($params['date_from']);
    $dateTo = $conn->real_escape_string($params['date_to']);
    $classFilter = isset($params['class_filter']) && $params['class_filter'] !== 'all' ? $conn->real_escape_string($params['class_filter']) : '';
    
    $query = buildCustomReportQuery($reportType, $dateFrom, $dateTo, $classFilter);
    $query .= " LIMIT 100";
    
    $result = $conn->query($query);
    
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
    
    return ['success' => true, 'columns' => $columns, 'data' => $data];
}

// Build Custom Report Query
function buildCustomReportQuery($reportType, $dateFrom, $dateTo, $classFilter) {
    $query = "";
    
    switch ($reportType) {
        case 'borrowing':
            $query = "SELECT 
                b.borrowID as 'Borrow ID',
                CONCAT(u.first_name, ' ', u.last_name) as 'Borrower Name',
                s.student_id_number as 'Student ID',
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
            JOIN student s ON u.userID = s.userID
            JOIN book bk ON b.bookID = bk.bookID
            LEFT JOIN book_category bc ON bk.categoryID = bc.categoryID
            WHERE b.borrow_date BETWEEN '$dateFrom' AND '$dateTo'";
            
            if ($classFilter) {
                $query .= " AND s.studentClass = '$classFilter'";
            }
            
            $query .= " ORDER BY b.borrow_date DESC";
            break;
            
        case 'fines':
            $query = "SELECT 
                f.fineID as 'Fine ID',
                CONCAT(u.first_name, ' ', u.last_name) as 'Student Name',
                s.student_id_number as 'Student ID',
                s.studentClass as 'Class',
                bk.bookTitle as 'Book Title',
                f.fine_amount as 'Fine Amount',
                f.fine_reason as 'Reason',
                f.fine_date as 'Fine Date',
                f.payment_status as 'Payment Status',
                f.balance_due as 'Balance Due'
            FROM fines f
            JOIN user u ON f.userID = u.userID
            JOIN student s ON u.userID = s.userID
            JOIN borrow b ON f.borrowID = b.borrowID
            JOIN book bk ON b.bookID = bk.bookID
            WHERE f.fine_date BETWEEN '$dateFrom' AND '$dateTo'";
            
            if ($classFilter) {
                $query .= " AND s.studentClass = '$classFilter'";
            }
            
            $query .= " ORDER BY f.fine_date DESC";
            break;
            
        case 'reservations':
            $query = "SELECT 
                r.reservationID as 'Reservation ID',
                CONCAT(u.first_name, ' ', u.last_name) as 'Student Name',
                s.student_id_number as 'Student ID',
                s.studentClass as 'Class',
                bk.bookTitle as 'Book Title',
                r.reservation_date as 'Reserved Date',
                r.queue_position as 'Queue Position',
                r.reservation_status as 'Status'
            FROM reservation r
            JOIN user u ON r.userID = u.userID
            JOIN student s ON u.userID = s.userID
            JOIN book bk ON r.bookID = bk.bookID
            WHERE r.reservation_date BETWEEN '$dateFrom' AND '$dateTo'";
            
            if ($classFilter) {
                $query .= " AND s.studentClass = '$classFilter'";
            }
            
            $query .= " ORDER BY r.reservation_date DESC";
            break;
    }
    
    return $query;
}

// Export Custom Report
function exportCustomReport($params) {
    global $conn, $current_librarian_id;
    
    $reportType = $conn->real_escape_string($params['report_type']);
    $dateFrom = $conn->real_escape_string($params['date_from']);
    $dateTo = $conn->real_escape_string($params['date_to']);
    $classFilter = isset($params['class_filter']) && $params['class_filter'] !== 'all' ? $conn->real_escape_string($params['class_filter']) : '';
    $format = $params['format'];
    
    $query = buildCustomReportQuery($reportType, $dateFrom, $dateTo, $classFilter);
    $result = $conn->query($query);
    
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    
    if ($format === 'pdf') {
        $filename = exportCustomReportPDF($data, $reportType, $dateFrom, $dateTo);
    } else {
        $filename = exportCustomReportExcel($data, $reportType, $dateFrom, $dateTo);
    }
    
    // Log download
    $fileSize = file_exists('exports/' . $filename) ? filesize('exports/' . $filename) : 0;
    $stmt = $conn->prepare("INSERT INTO report_downloads (report_name, export_format, file_size, downloaded_by_librarianID, filters_applied, record_count) VALUES (?, ?, ?, ?, ?, ?)");
    $reportName = ucfirst($reportType) . ' Report';
    $filtersApplied = json_encode($params);
    $recordCount = count($data);
    $stmt->bind_param("ssiiis", $reportName, $format, $fileSize, $current_librarian_id, $filtersApplied, $recordCount);
    $stmt->execute();
    
    return ['success' => true, 'filename' => $filename, 'download_url' => 'exports/' . $filename];
}

// Export Custom Report PDF
function exportCustomReportPDF($data, $reportType, $dateFrom, $dateTo) {
    $mpdf = new Mpdf(['format' => 'A4-L', 'margin_left' => 10, 'margin_right' => 10]);
    
    $html = '<style>
        body { font-family: Arial, sans-serif; font-size: 9px; }
        .header { text-align: center; margin-bottom: 20px; border-bottom: 3px solid #1e3a8a; padding-bottom: 10px; }
        .logo { width: 50px; }
        h2 { color: #1e3a8a; margin: 5px 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th { background-color: #1e3a8a; color: white; padding: 8px; font-size: 8px; }
        td { border: 1px solid #ddd; padding: 6px; font-size: 8px; }
        tr:nth-child(even) { background-color: #f8fafc; }
        .footer { text-align: center; margin-top: 20px; font-size: 8px; color: #666; }
    </style>';
    
    $html .= '<div class="header">';
    $html .= '<img src="photo/logo1.png" class="logo">';
    $html .= '<h2>SMK CHENDERING LIBRARY MANAGEMENT SYSTEM</h2>';
    $html .= '<p>' . strtoupper($reportType) . ' REPORT</p>';
    $html .= '<p>Period: ' . $dateFrom . ' to ' . $dateTo . ' | Generated: ' . date('d F Y, h:i A') . '</p>';
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
    }
    
    $html .= '<div class="footer">Official Document – SMK Chendering Library</div>';
    
    $mpdf->WriteHTML($html);
    
    $filename = 'report_' . $reportType . '_' . date('YmdHis') . '.pdf';
    $filepath = 'exports/' . $filename;
    
    if (!is_dir('exports')) {
        mkdir('exports', 0755, true);
    }
    
    $mpdf->Output($filepath, 'F');
    
    return $filename;
}

// Export Custom Report Excel
function exportCustomReportExcel($data, $reportType, $dateFrom, $dateTo) {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    
    $sheet->setCellValue('A1', 'SMK CHENDERING LIBRARY MANAGEMENT SYSTEM');
    $sheet->mergeCells('A1:F1');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
    
    $sheet->setCellValue('A2', strtoupper($reportType) . ' REPORT');
    $sheet->mergeCells('A2:F2');
    $sheet->getStyle('A2')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
    
    $sheet->setCellValue('A3', 'Period: ' . $dateFrom . ' to ' . $dateTo);
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
        
        foreach (range('A', chr(ord('A') + count($data[0]) - 1)) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
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

// Save Template
function saveTemplate($params) {
    global $conn, $current_librarian_id;
    
    $templateName = $conn->real_escape_string($params['template_name']);
    $reportType = $conn->real_escape_string($params['report_type']);
    $filters = json_encode($params);
    
    $stmt = $conn->prepare("INSERT INTO report_templates (template_name, data_source, selected_fields, filters, chart_type, created_by_librarianID, is_public) VALUES (?, ?, '[]', ?, 'table', ?, 0)");
    $stmt->bind_param("sssi", $templateName, $reportType, $filters, $current_librarian_id);
    
    if ($stmt->execute()) {
        return ['success' => true, 'message' => 'Template saved successfully'];
    } else {
        return ['success' => false, 'message' => 'Failed to save template'];
    }
}

// Load Template
function loadTemplate($params) {
    global $conn, $current_librarian_id;
    
    $templateID = intval($params['template_id']);
    
    $query = "SELECT * FROM report_templates WHERE templateID = $templateID AND (created_by_librarianID = $current_librarian_id OR is_public = 1)";
    $result = $conn->query($query);
    
    if ($result && $row = $result->fetch_assoc()) {
        $filters = json_decode($row['filters'], true);
        return ['success' => true, 'template' => $row, 'filters' => $filters];
    } else {
        return ['success' => false, 'message' => 'Template not found'];
    }
}

// Delete Template
function deleteTemplate($params) {
    global $conn, $current_librarian_id;
    
    $templateID = intval($params['template_id']);
    
    $stmt = $conn->prepare("DELETE FROM report_templates WHERE templateID = ? AND created_by_librarianID = ?");
    $stmt->bind_param("ii", $templateID, $current_librarian_id);
    
    if ($stmt->execute()) {
        return ['success' => true, 'message' => 'Template deleted successfully'];
    } else {
        return ['success' => false, 'message' => 'Failed to delete template'];
    }
}

// Export Analytics
function exportAnalytics($params) {
    global $conn;
    
    $dateFrom = $conn->real_escape_string($params['date_from']);
    $dateTo = $conn->real_escape_string($params['date_to']);
    
    // Get all data
    $stats = getSummaryStats(['date_from' => $dateFrom, 'date_to' => $dateTo]);
    $chartData = getChartData(['date_from' => $dateFrom, 'date_to' => $dateTo]);
    
    $mpdf = new Mpdf(['format' => 'A4']);
    
    $html = '<style>
        body { font-family: Arial, sans-serif; }
        .header { text-align: center; margin-bottom: 20px; border-bottom: 3px solid #1e3a8a; padding-bottom: 10px; }
        .logo { width: 60px; }
        h2 { color: #1e3a8a; }
        .stats-box { border: 2px solid #1e3a8a; padding: 15px; margin: 10px 0; background: #f8fafc; }
        .stat-item { margin: 10px 0; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        th { background-color: #1e3a8a; color: white; padding: 8px; }
        td { border: 1px solid #ddd; padding: 8px; }
    </style>';
    
    $html .= '<div class="header">';
    $html .= '<img src="photo/logo1.png" class="logo">';
    $html .= '<h2>SMK CHENDERING LIBRARY</h2>';
    $html .= '<h3>Statistical Analysis Report</h3>';
    $html .= '<p>Period: ' . $dateFrom . ' to ' . $dateTo . '</p>';
    $html .= '<p>Generated: ' . date('d F Y, h:i A') . '</p>';
    $html .= '</div>';
    
    $html .= '<div class="stats-box">';
    $html .= '<h3>Summary Statistics</h3>';
    $html .= '<div class="stat-item"><strong>Total Books:</strong> ' . $stats['data']['total_books'] . '</div>';
    $html .= '<div class="stat-item"><strong>Borrowed Books:</strong> ' . $stats['data']['borrowed_books'] . '</div>';
    $html .= '<div class="stat-item"><strong>Overdue Books:</strong> ' . $stats['data']['overdue_books'] . '</div>';
    $html .= '<div class="stat-item"><strong>Active Users:</strong> ' . $stats['data']['active_users'] . '</div>';
    $html .= '</div>';
    
    $html .= '<h3>Top Categories Borrowed</h3>';
    $html .= '<table><thead><tr><th>Category</th><th>Count</th></tr></thead><tbody>';
    foreach ($chartData['data']['top_categories'] as $cat) {
        $html .= '<tr><td>' . $cat['categoryName'] . '</td><td>' . $cat['count'] . '</td></tr>';
    }
    $html .= '</tbody></table>';
    
    $html .= '<div style="margin-top: 40px; text-align: center; font-size: 10px; color: #666;">Official Document – SMK Chendering Library</div>';
    
    $mpdf->WriteHTML($html);
    
    $filename = 'analytics_' . date('YmdHis') . '.pdf';
    $filepath = 'exports/' . $filename;
    
    if (!is_dir('exports')) {
        mkdir('exports', 0755, true);
    }
    
    $mpdf->Output($filepath, 'F');
    
    return ['success' => true, 'filename' => $filename, 'download_url' => 'exports/' . $filename];
}

// Get saved templates
$templates_query = "SELECT * FROM report_templates WHERE created_by_librarianID = $current_librarian_id OR is_public = 1 ORDER BY created_date DESC";
$templates_result = $conn->query($templates_query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Analytics - SMK Chendering Library</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    
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
        }

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
        }

        .logout-btn:hover {
            background: #dc2626;
            color: white;
        }

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

        .main-content {
            margin-left: var(--sidebar-width);
            margin-top: var(--header-height);
            padding: 2rem;
            min-height: calc(100vh - var(--header-height));
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

        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
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
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59,130,246,0.1);
        }

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
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: #1d4ed8;
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-success:hover {
            background: #059669;
        }

        .btn-warning {
            background: var(--warning);
            color: white;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

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
            transition: transform 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
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

        .charts-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.5rem;
            margin-top: 1.5rem;
        }

        .chart-card {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .chart-title {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .chart-container {
            position: relative;
            height: 280px;
        }

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
            transition: transform 0.2s;
            border: 2px solid transparent;
        }

        .borrower-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.15);
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
        }

        .table tbody tr:hover {
            background: var(--light);
        }

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

        .template-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
        }

        .alert {
            position: fixed;
            top: 80px;
            right: 20px;
            min-width: 300px;
            padding: 1rem 1.5rem;
            border-radius: 8px;
            color: white;
            z-index: 1001;
            display: none;
            align-items: center;
            gap: 0.75rem;
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }

        .alert.show {
            display: flex;
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from {
                transform: translateX(400px);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        .alert-success { background: var(--success); }
        .alert-danger { background: var(--danger); }
        .alert-info { background: var(--primary-light); }

        .loading-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

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
        }

        .nav-tabs .nav-link.active {
            color: var(--primary);
            border-bottom: 3px solid var(--primary);
            background: transparent;
        }

        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .top-borrowers-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .charts-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-bar {
                flex-direction: column;
            }
            
            .form-group {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-left">
            <button id="sidebarToggle" class="toggle-sidebar">
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

    <main class="main-content" id="mainContent">
        <div class="page-header">
            <h1 class="page-title">Library Reports & Analytics</h1>
            <p style="color: var(--secondary);">Comprehensive reporting and statistical analysis for library operations</p>
        </div>

        <div id="alertMessage" class="alert"></div>

        <!-- Filter Bar -->
        <div class="card">
            <div class="filter-bar">
                <div class="form-group">
                    <label><i class="fas fa-calendar"></i> From Date</label>
                    <input type="date" id="filter_date_from" class="form-control">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-calendar"></i> To Date</label>
                    <input type="date" id="filter_date_to" class="form-control">
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
                    <button class="btn btn-primary" onclick="refreshAllData()">
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
                <div class="stat-label">Total Borrowed Books</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(245,158,11,0.1); color: var(--warning);">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="stat-value" style="color: var(--warning);">0</div>
                <div class="stat-label">Total Overdue Books</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(139,92,246,0.1); color: #8b5cf6;">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-value" style="color: #8b5cf6;">0</div>
                <div class="stat-label">Total Active Users</div>
            </div>
        </div>

        <!-- Charts Section -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-chart-line"></i> Statistical Analysis
                <button class="btn btn-success" onclick="exportAnalytics()" style="margin-left: auto; font-size: 0.85rem;">
                    <i class="fas fa-download"></i> Download Statistical Analysis
                </button>
            </div>
            
            <div class="charts-grid">
                <div class="chart-card">
                    <div class="chart-title">
                        <i class="fas fa-chart-bar"></i> Borrowed Books by Month
                    </div>
                    <div class="chart-container">
                        <canvas id="monthlyChart"></canvas>
                    </div>
                </div>
                <div class="chart-card">
                    <div class="chart-title">
                        <i class="fas fa-chart-line"></i> Overdue Trends
                    </div>
                    <div class="chart-container">
                        <canvas id="overdueChart"></canvas>
                    </div>
                </div>
                <div class="chart-card">
                    <div class="chart-title">
                        <i class="fas fa-chart-pie"></i> Top Categories Borrowed
                    </div>
                    <div class="chart-container">
                        <canvas id="categoryChart"></canvas>
                    </div>
                </div>
                <div class="chart-card">
                    <div class="chart-title">
                        <i class="fas fa-dollar-sign"></i> Total Fines Collected
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
                <div style="grid-column: 1/-1; text-align: center; padding: 3rem; color: var(--secondary);">
                    <i class="fas fa-spinner fa-spin" style="font-size: 2rem;"></i>
                    <p style="margin-top: 1rem;">Loading top borrowers...</p>
                </div>
            </div>
        </div>

        <!-- Tabbed Content -->
        <div class="card">
            <ul class="nav nav-tabs" role="tablist">
                <li class="nav-item">
                    <a class="nav-link active" data-bs-toggle="tab" href="#analyticsTab">
                        <i class="fas fa-chart-area"></i> Analytics Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" data-bs-toggle="tab" href="#customReportTab">
                        <i class="fas fa-file-alt"></i> Custom Report Builder
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" data-bs-toggle="tab" href="#classListTab">
                        <i class="fas fa-list"></i> Generate Class Student List
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" data-bs-toggle="tab" href="#templatesTab">
                        <i class="fas fa-bookmark"></i> Saved Report Templates
                    </a>
                </li>
            </ul>

            <div class="tab-content">
                <!-- Analytics Dashboard Tab -->
                <div id="analyticsTab" class="tab-pane fade show active" style="padding: 1.5rem 0;">
                    <h5 style="color: var(--primary); margin-bottom: 1rem;">
                        <i class="fas fa-info-circle"></i> Analytics Dashboard
                    </h5>
                    <p style="color: var(--secondary);">
                        The analytics dashboard displays real-time statistics and visual representations of library activities. 
                        Use the filters above to customize the date range and class selection. Click "Refresh Data" to update all charts and statistics.
                    </p>
                    <div style="margin-top: 1.5rem;">
                        <button class="btn btn-primary" onclick="refreshAllData()">
                            <i class="fas fa-sync-alt"></i> Refresh All Analytics
                        </button>
                    </div>
                </div>

                <!-- Custom Report Builder Tab -->
                <div id="customReportTab" class="tab-pane fade" style="padding: 1.5rem 0;">
                    <h5 style="color: var(--primary); margin-bottom: 1.5rem;">
                        <i class="fas fa-sliders-h"></i> Custom Report Builder
                    </h5>
                    
                    <form id="customReportForm">
                        <div class="filter-bar">
                            <div class="form-group">
                                <label>Report Type</label>
                                <select id="report_type" class="form-control" required>
                                    <option value="">Select Report Type</option>
                                    <option value="borrowing">Borrowing Records</option>
                                    <option value="fines">Fines Report</option>
                                    <option value="reservations">Reservations Report</option>
                                </select>
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
                            <div class="form-group">
                                <label>From Date</label>
                                <input type="date" id="report_date_from" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label>To Date</label>
                                <input type="date" id="report_date_to" class="form-control" required>
                            </div>
                        </div>

                        <div style="display: flex; gap: 1rem; flex-wrap: wrap; margin-top: 1rem;">
                            <button type="button" class="btn btn-primary" onclick="previewReport()">
                                <i class="fas fa-eye"></i> Preview Report
                            </button>
                            <button type="button" class="btn btn-success" onclick="exportReport('pdf')">
                                <i class="fas fa-file-pdf"></i> Export to PDF
                            </button>
                            <button type="button" class="btn btn-success" onclick="exportReport('excel')">
                                <i class="fas fa-file-excel"></i> Export to Excel
                            </button>
                            <button type="button" class="btn btn-warning" onclick="saveReportTemplate()">
                                <i class="fas fa-save"></i> Save as Template
                            </button>
                        </div>
                    </form>

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

                <!-- Class Student List Tab -->
                <div id="classListTab" class="tab-pane fade" style="padding: 1.5rem 0;">
                    <h5 style="color: var(--primary); margin-bottom: 1.5rem;">
                        <i class="fas fa-list-alt"></i> Generate Class Student List
                    </h5>
                    
                    <div class="filter-bar">
                        <div class="form-group">
                            <label>Select Class</label>
                            <select id="class_select" class="form-control">
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
                            <button class="btn btn-success" onclick="exportClassList('pdf')">
                                <i class="fas fa-file-pdf"></i> Download PDF
                            </button>
                            <button class="btn btn-success" onclick="exportClassList('excel')">
                                <i class="fas fa-file-excel"></i> Export Excel
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
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>

                <!-- Saved Templates Tab -->
                <div id="templatesTab" class="tab-pane fade" style="padding: 1.5rem 0;">
                    <h5 style="color: var(--primary); margin-bottom: 1.5rem;">
                        <i class="fas fa-bookmark"></i> Saved Report Templates
                    </h5>
                    
                    <div class="template-grid">
                        <?php if ($templates_result && $templates_result->num_rows > 0): ?>
                            <?php while ($template = $templates_result->fetch_assoc()): ?>
                                <div class="template-card">
                                    <div class="template-name"><?php echo htmlspecialchars($template['template_name']); ?></div>
                                    <div style="color: var(--secondary); font-size: 0.85rem; margin: 0.5rem 0;">
                                        <i class="fas fa-database"></i> <?php echo ucfirst($template['data_source']); ?> Report
                                    </div>
                                    <div style="font-size: 0.8rem; color: var(--secondary);">
                                        <i class="fas fa-calendar"></i> Created: <?php echo date('M d, Y', strtotime($template['created_date'])); ?>
                                    </div>
                                    <div class="template-actions">
                                        <button class="btn btn-primary" style="flex: 1; padding: 0.5rem;" 
                                                onclick="loadSavedTemplate(<?php echo $template['templateID']; ?>)">
                                            <i class="fas fa-download"></i> Load Template
                                        </button>
                                        <button class="btn btn-danger" style="padding: 0.5rem;" 
                                                onclick="deleteTemplateConfirm(<?php echo $template['templateID']; ?>)">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div style="grid-column: 1/-1; text-align: center; padding: 3rem; color: var(--secondary);">
                                <i class="fas fa-inbox" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                <p>No saved templates yet. Create and save your custom reports from the Custom Report Builder!</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let chartInstances = {};

        $(document).ready(function() {
            // Sidebar toggle
            $('#sidebarToggle').click(function() {
                $('#sidebar').toggleClass('collapsed');
                $('#mainContent').toggleClass('collapsed');
            });

            // Set default dates
            const today = new Date().toISOString().split('T')[0];
            const firstDay = new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().split('T')[0];
            
            $('#filter_date_from, #report_date_from').val(firstDay);
            $('#filter_date_to, #report_date_to').val(today);

            // Initial load
            refreshAllData();
        });

        function refreshAllData() {
            const dateFrom = $('#filter_date_from').val();
            const dateTo = $('#filter_date_to').val();
            const classFilter = $('#filter_class').val();

            if (!dateFrom || !dateTo) {
                showAlert('danger', 'Please select date range');
                return;
            }

            showAlert('info', 'Refreshing data...');

            // Load summary stats
            $.post('report_management.php', {
                ajax_action: 'get_summary_stats',
                date_from: dateFrom,
                date_to: dateTo,
                class_filter: classFilter
            }, function(response) {
                if (response.success) {
                    updateStats(response.data);
                }
            }, 'json');

            // Load charts
            $.post('report_management.php', {
                ajax_action: 'get_chart_data',
                date_from: dateFrom,
                date_to: dateTo,
                class_filter: classFilter
            }, function(response) {
                if (response.success) {
                    renderCharts(response.data);
                    showAlert('success', 'Data refreshed successfully!');
                }
            }, 'json');

            // Load top borrowers
            $.post('report_management.php', {
                ajax_action: 'get_top_borrowers',
                date_from: dateFrom,
                date_to: dateTo,
                class_filter: classFilter
            }, function(response) {
                if (response.success) {
                    displayTopBorrowers(response.borrowers);
                }
            }, 'json');
        }

        function updateStats(data) {
            const stats = $('#statsGrid .stat-card');
            $(stats[0]).find('.stat-value').text(data.total_books);
            $(stats[1]).find('.stat-value').text(data.borrowed_books);
            $(stats[2]).find('.stat-value').text(data.overdue_books);
            $(stats[3]).find('.stat-value').text(data.active_users);
        }

        function renderCharts(data) {
            // Monthly Borrowed Books Chart
            if (chartInstances['monthly']) {
                chartInstances['monthly'].destroy();
            }
            const monthlyCtx = document.getElementById('monthlyChart').getContext('2d');
            chartInstances['monthly'] = new Chart(monthlyCtx, {
                type: 'bar',
                data: {
                    labels: data.monthly_borrowed.map(d => d.month),
                    datasets: [{
                        label: 'Books Borrowed',
                        data: data.monthly_borrowed.map(d => d.count),
                        backgroundColor: '#3b82f6',
                        borderColor: '#1e3a8a',
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
                        y: { beginAtZero: true }
                    }
                }
            });

            // Overdue Trends Chart
            if (chartInstances['overdue']) {
                chartInstances['overdue'].destroy();
            }
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
                        tension: 0.4
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

            // Top Categories Chart
            if (chartInstances['category']) {
                chartInstances['category'].destroy();
            }
            const categoryCtx = document.getElementById('categoryChart').getContext('2d');
            const colors = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899', '#14b8a6', '#f97316'];
            chartInstances['category'] = new Chart(categoryCtx, {
                type: 'pie',
                data: {
                    labels: data.top_categories.map(d => d.categoryName),
                    datasets: [{
                        data: data.top_categories.map(d => d.count),
                        backgroundColor: colors
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false
                }
            });

            // Fines Collected Chart
            if (chartInstances['fines']) {
                chartInstances['fines'].destroy();
            }
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
                        y: { beginAtZero: true }
                    }
                }
            });
        }

        function displayTopBorrowers(borrowers) {
            const container = $('#topBorrowersContainer');
            container.empty();

            if (borrowers.length === 0) {
                container.html('<div style="grid-column: 1/-1; text-align: center; padding: 3rem; color: var(--secondary);">No borrowing data found for the selected period</div>');
                return;
            }

            const rankings = ['1st', '2nd', '3rd'];
            const rankClasses = ['rank-1', 'rank-2', 'rank-3'];

            borrowers.forEach((borrower, index) => {
                const initial = borrower.name.charAt(0).toUpperCase();
                const card = `
                    <div class="borrower-card ${rankClasses[index]}">
                        <div class="rank-badge ${rankClasses[index]}">${rankings[index]}</div>
                        <div class="borrower-avatar">${initial}</div>
                        <div class="borrower-name">${borrower.name}</div>
                        <div class="borrower-info"><i class="fas fa-id-card"></i> ${borrower.student_id_number}</div>
                        <div class="borrower-info"><i class="fas fa-school"></i> ${borrower.studentClass}</div>
                        <div class="borrow-count">
                            <i class="fas fa-book-reader"></i> ${borrower.borrow_count} Books
                        </div>
                        <button class="btn btn-success" style="width: 100%; margin-top: 1rem; font-size: 0.85rem;" 
                                onclick="generateCertificate('${borrower.name.replace(/'/g, "\\'")}', '${borrower.studentClass}', '${rankings[index]}')">
                            <i class="fas fa-certificate"></i> Generate Certificate
                        </button>
                    </div>
                `;
                container.append(card);
            });
        }

        function generateCertificate(name, className, rank) {
            showAlert('info', 'Generating certificate...');

            $.post('report_management.php', {
                ajax_action: 'generate_certificate',
                student_name: name,
                class_name: className,
                rank: rank
            }, function(response) {
                if (response.success) {
                    showAlert('success', 'Certificate generated successfully!');
                    window.open(response.download_url, '_blank');
                } else {
                    showAlert('danger', 'Failed to generate certificate');
                }
            }, 'json').fail(function() {
                showAlert('danger', 'Network error occurred');
            });
        }

        function loadClassStudents() {
            const className = $('#class_select').val();
            if (!className) {
                showAlert('danger', 'Please select a class');
                return;
            }

            showAlert('info', 'Loading students...');

            $.post('report_management.php', {
                ajax_action: 'get_class_students',
                class_name: className
            }, function(response) {
                if (response.success) {
                    displayClassStudents(response.students);
                    showAlert('success', `Loaded ${response.students.length} students`);
                } else {
                    showAlert('danger', 'Failed to load students');
                }
            }, 'json').fail(function() {
                showAlert('danger', 'Network error occurred');
            });
        }

        function displayClassStudents(students) {
            const tbody = $('#classStudentsTable tbody');
            tbody.empty();

            if (students.length === 0) {
                tbody.html('<tr><td colspan="7" style="text-align: center; padding: 2rem;">No students found in this class</td></tr>');
            } else {
                students.forEach(student => {
                    const row = `
                        <tr>
                            <td>${student.student_id_number}</td>
                            <td>${student.studentName}</td>
                            <td>${student.studentClass}</td>
                            <td>${student.studentEmail || '-'}</td>
                            <td>${student.studentPhoneNo || '-'}</td>
                            <td><span class="badge bg-${student.studentStatus === 'active' ? 'success' : 'secondary'}">${student.studentStatus}</span></td>
                            <td>${student.total_borrowed}</td>
                        </tr>
                    `;
                    tbody.append(row);
                });
            }

            $('#classStudentsContainer').show();
        }

        function exportClassList(format) {
            const className = $('#class_select').val();
            if (!className) {
                showAlert('danger', 'Please select a class and load students first');
                return;
            }

            showAlert('info', 'Generating class list...');

            $.post('report_management.php', {
                ajax_action: 'export_class_list',
                class_name: className,
                format: format
            }, function(response) {
                if (response.success) {
                    showAlert('success', 'Class list generated successfully!');
                    window.open(response.download_url, '_blank');
                } else {
                    showAlert('danger', 'Failed to generate class list');
                }
            }, 'json').fail(function() {
                showAlert('danger', 'Network error occurred');
            });
        }

        function previewReport() {
            const reportType = $('#report_type').val();
            const dateFrom = $('#report_date_from').val();
            const dateTo = $('#report_date_to').val();
            const classFilter = $('#report_class').val();

            if (!reportType || !dateFrom || !dateTo) {
                showAlert('danger', 'Please fill all required fields');
                return;
            }

            showAlert('info', 'Loading preview...');

            $.post('report_management.php', {
                ajax_action: 'preview_custom_report',
                report_type: reportType,
                date_from: dateFrom,
                date_to: dateTo,
                class_filter: classFilter
            }, function(response) {
                if (response.success) {
                    displayPreview(response.columns, response.data);
                    showAlert('success', `Preview loaded: ${response.data.length} records`);
                } else {
                    showAlert('danger', 'Failed to load preview');
                }
            }, 'json').fail(function() {
                showAlert('danger', 'Network error occurred');
            });
        }

        function displayPreview(columns, data) {
            const table = $('#previewTable');
            
            // Build header
            let thead = '<tr>';
            columns.forEach(col => {
                thead += `<th>${col}</th>`;
            });
            thead += '</tr>';
            table.find('thead').html(thead);

            // Build body
            const tbody = table.find('tbody');
            tbody.empty();

            if (data.length === 0) {
                tbody.html(`<tr><td colspan="${columns.length}" style="text-align: center; padding: 2rem;">No data found</td></tr>`);
            } else {
                data.forEach(row => {
                    let tr = '<tr>';
                    columns.forEach(col => {
                        tr += `<td>${row[col] || '-'}</td>`;
                    });
                    tr += '</tr>';
                    tbody.append(tr);
                });
            }

            $('#previewContainer').show();
            
            // Scroll to preview
            $('html, body').animate({
                scrollTop: $('#previewContainer').offset().top - 100
            }, 500);
        }

        function exportReport(format) {
            const reportType = $('#report_type').val();
            const dateFrom = $('#report_date_from').val();
            const dateTo = $('#report_date_to').val();
            const classFilter = $('#report_class').val();

            if (!reportType || !dateFrom || !dateTo) {
                showAlert('danger', 'Please fill all required fields');
                return;
            }

            showAlert('info', 'Generating report...');

            $.post('report_management.php', {
                ajax_action: 'export_custom_report',
                report_type: reportType,
                date_from: dateFrom,
                date_to: dateTo,
                class_filter: classFilter,
                format: format
            }, function(response) {
                if (response.success) {
                    showAlert('success', 'Report generated successfully!');
                    window.open(response.download_url, '_blank');
                } else {
                    showAlert('danger', 'Failed to generate report');
                }
            }, 'json').fail(function() {
                showAlert('danger', 'Network error occurred');
            });
        }

        function saveReportTemplate() {
            const templateName = prompt('Enter a name for this template:');
            if (!templateName) return;

            const reportType = $('#report_type').val();
            const dateFrom = $('#report_date_from').val();
            const dateTo = $('#report_date_to').val();
            const classFilter = $('#report_class').val();

            if (!reportType) {
                showAlert('danger', 'Please select a report type first');
                return;
            }

            $.post('report_management.php', {
                ajax_action: 'save_template',
                template_name: templateName,
                report_type: reportType,
                date_from: dateFrom,
                date_to: dateTo,
                class_filter: classFilter
            }, function(response) {
                if (response.success) {
                    showAlert('success', 'Template saved successfully!');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showAlert('danger', 'Failed to save template');
                }
            }, 'json').fail(function() {
                showAlert('danger', 'Network error occurred');
            });
        }

        function loadSavedTemplate(templateID) {
            showAlert('info', 'Loading template...');

            $.post('report_management.php', {
                ajax_action: 'load_template',
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
                    
                    showAlert('success', 'Template loaded successfully!');
                    
                    // Scroll to form
                    setTimeout(() => {
                        $('html, body').animate({
                            scrollTop: $('#customReportForm').offset().top - 100
                        }, 500);
                    }, 300);
                } else {
                    showAlert('danger', 'Failed to load template');
                }
            }, 'json').fail(function() {
                showAlert('danger', 'Network error occurred');
            });
        }

        function deleteTemplateConfirm(templateID) {
            if (!confirm('Are you sure you want to delete this template? This action cannot be undone.')) {
                return;
            }

            $.post('report_management.php', {
                ajax_action: 'delete_template',
                template_id: templateID
            }, function(response) {
                if (response.success) {
                    showAlert('success', 'Template deleted successfully!');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showAlert('danger', 'Failed to delete template');
                }
            }, 'json').fail(function() {
                showAlert('danger', 'Network error occurred');
            });
        }

        function exportAnalytics() {
            const dateFrom = $('#filter_date_from').val();
            const dateTo = $('#filter_date_to').val();

            if (!dateFrom || !dateTo) {
                showAlert('danger', 'Please select date range');
                return;
            }

            showAlert('info', 'Generating statistical analysis document...');

            $.post('report_management.php', {
                ajax_action: 'export_analytics',
                date_from: dateFrom,
                date_to: dateTo
            }, function(response) {
                if (response.success) {
                    showAlert('success', 'Statistical analysis generated successfully!');
                    window.open(response.download_url, '_blank');
                } else {
                    showAlert('danger', 'Failed to generate document');
                }
            }, 'json').fail(function() {
                showAlert('danger', 'Network error occurred');
            });
        }

        function showAlert(type, message) {
            const alert = $('#alertMessage');
            alert.removeClass().addClass(`alert alert-${type} show`);
            
            let icon = 'info-circle';
            if (type === 'success') icon = 'check-circle';
            if (type === 'danger') icon = 'exclamation-circle';
            if (type === 'info') icon = 'spinner fa-spin';
            
            alert.html(`<i class="fas fa-${icon}"></i> ${message}`);
            
            if (type !== 'info') {
                setTimeout(() => {
                    alert.removeClass('show');
                }, 5000);
            }
        }
    </script>
</body>
</html>