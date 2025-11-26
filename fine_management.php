<?php
session_start();
require_once 'dbconnect.php';
require_once 'auth_helper.php';
checkPageAccess(); 
requireRole('librarian');

// Include mPDF library
require_once __DIR__ . '/vendor/autoload.php';

$librarian_info = getCurrentUser();
$librarian_name = getUserDisplayName();

// Get librarian ID
$currentLibrarianID = null;

if (isset($librarian_info['user_id'])) {
    if (isset($_SESSION['librarianID']) && !empty($_SESSION['librarianID'])) {
        $currentLibrarianID = $_SESSION['librarianID'];
    } else {
        $login_id = $_SESSION['login_id'] ?? null;
        
        if ($login_id) {
            $librarianQuery = "SELECT librarianID FROM librarian WHERE librarian_id_number = ?";
            $stmt = $conn->prepare($librarianQuery);
            $stmt->bind_param('s', $login_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $librarianData = $result->fetch_assoc();
                $currentLibrarianID = $librarianData['librarianID'];
                $_SESSION['librarianID'] = $currentLibrarianID;
            }
        }
        
        if (!$currentLibrarianID) {
            $email = $_SESSION['email'] ?? '';
            if ($email) {
                $librarianQuery2 = "SELECT librarianID FROM librarian WHERE librarianEmail = ?";
                $stmt2 = $conn->prepare($librarianQuery2);
                $stmt2->bind_param('s', $email);
                $stmt2->execute();
                $result2 = $stmt2->get_result();
                
                if ($result2->num_rows > 0) {
                    $librarianData = $result2->fetch_assoc();
                    $currentLibrarianID = $librarianData['librarianID'];
                    $_SESSION['librarianID'] = $currentLibrarianID;
                }
            }
        }
    }
}

// Handle PDF Generation Requests
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    
    if ($action === 'generate_receipt_pdf' && isset($_GET['receipt_id'])) {
        generateReceiptPDF($_GET['receipt_id'], $conn, $librarian_name);
        exit;
    }
    
    if ($action === 'generate_letter_pdf' && isset($_GET['letter_id'])) {
        generateLetterPDF($_GET['letter_id'], $conn, $librarian_name);
        exit;
    }
}

// Function to generate Receipt PDF
function generateReceiptPDF($receiptNumber, $conn, $librarianName) {
    try {
        // Fetch receipt data
        $query = "SELECT r.*, u.login_id, u.first_name, u.last_name, u.user_type,
                         CASE WHEN u.user_type = 'student' THEN s.studentName 
                              WHEN u.user_type = 'staff' THEN st.staffName END as full_name,
                         CASE WHEN u.user_type = 'student' THEN s.studentClass 
                              WHEN u.user_type = 'staff' THEN st.department END as class_dept
                  FROM receipts r
                  JOIN user u ON r.userID = u.userID
                  LEFT JOIN student s ON u.userID = s.userID AND u.user_type = 'student'
                  LEFT JOIN staff st ON u.userID = st.userID AND u.user_type = 'staff'
                  WHERE r.receipt_number = ?";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param('s', $receiptNumber);
        $stmt->execute();
        $result = $stmt->get_result();
        $receiptData = $result->fetch_assoc();
        
        if (!$receiptData) {
            die('Receipt not found.');
        }
        
        // Get fine details
        $fineIds = explode(',', $receiptData['fine_ids']);
        $fineDetails = [];
        
        foreach ($fineIds as $fineId) {
            $fineQuery = "SELECT f.*, book.bookTitle, b.due_date 
                         FROM fines f 
                         LEFT JOIN borrow b ON f.borrowID = b.borrowID 
                         LEFT JOIN book ON b.bookID = book.bookID 
                         WHERE f.fineID = ?";
            $stmt = $conn->prepare($fineQuery);
            $stmt->bind_param('i', $fineId);
            $stmt->execute();
            $result = $stmt->get_result();
            $fine = $result->fetch_assoc();
            if ($fine) {
                $fineDetails[] = $fine;
            }
        }
        
        // Get logo
        $logoPath = __DIR__ . '/photo/logo1.png';
        $logoData = '';
        if (file_exists($logoPath)) {
            $logoData = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));
        }
        
        // Generate HTML
        $html = '
<!DOCTYPE html>
<html>
<head>
    <style>
        @page {
            margin: 15mm;
        }
        body {
            font-family: Arial, sans-serif;
            font-size: 11pt;
            line-height: 1.4;
            color: #000;
        }
        .header {
            text-align: center;
            border-bottom: 3px solid #000;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }
        .logo {
            width: 80px;
            height: 80px;
            margin: 0 auto 10px;
        }
        .school-name {
            font-size: 16pt;
            font-weight: bold;
            margin: 5px 0;
        }
        .school-details {
            font-size: 9pt;
            margin: 3px 0;
        }
        .receipt-title {
            text-align: center;
            font-size: 14pt;
            font-weight: bold;
            margin: 15px 0;
            background: #f0f0f0;
            padding: 8px;
            border: 2px solid #000;
        }
        .info-row {
            margin: 8px 0;
            font-size: 10pt;
        }
        .info-label {
            display: inline-block;
            width: 150px;
            font-weight: bold;
        }
        .fine-table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }
        .fine-table th, .fine-table td {
            border: 1px solid #000;
            padding: 8px;
            text-align: left;
            font-size: 10pt;
        }
        .fine-table th {
            background: #e0e0e0;
            font-weight: bold;
        }
        .total-row {
            font-weight: bold;
            background: #f5f5f5;
            font-size: 11pt;
        }
        .payment-summary {
            margin: 20px 0;
            padding: 15px;
            border: 2px solid #000;
            background: #fafafa;
        }
        .payment-row {
            margin: 8px 0;
            font-size: 11pt;
        }
        .payment-row.total {
            font-size: 13pt;
            font-weight: bold;
            border-top: 2px solid #000;
            padding-top: 8px;
            margin-top: 10px;
        }
        .signature-section {
            margin-top: 40px;
            display: table;
            width: 100%;
        }
        .signature-box {
            display: table-cell;
            width: 48%;
            text-align: center;
            vertical-align: top;
        }
        .signature-line {
            border-top: 1px solid #000;
            margin-top: 50px;
            padding-top: 5px;
            font-size: 10pt;
        }
        .footer-note {
            margin-top: 30px;
            padding-top: 15px;
            border-top: 2px dashed #000;
            text-align: center;
            font-size: 9pt;
            font-style: italic;
        }
        .copy-separator {
            margin: 30px 0;
            border-top: 3px dashed #000;
            padding-top: 20px;
        }
        .copy-label {
            text-align: center;
            font-weight: bold;
            font-size: 12pt;
            margin-bottom: 15px;
            background: #e0e0e0;
            padding: 5px;
        }
    </style>
</head>
<body>';
        
        // Customer Copy
        $html .= generateReceiptContent($receiptData, $fineDetails, $logoData, $librarianName, false);
        
        // Office Copy
        $html .= '<div class="copy-separator"></div>';
        $html .= '<div class="copy-label">OFFICE COPY</div>';
        $html .= generateReceiptContent($receiptData, $fineDetails, $logoData, $librarianName, true);
        
        $html .= '</body></html>';
        
        // Generate PDF
        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 15,
            'margin_right' => 15,
            'margin_top' => 15,
            'margin_bottom' => 15,
        ]);
        
        $mpdf->WriteHTML($html);
        $mpdf->Output('Receipt_' . $receiptNumber . '.pdf', 'I');
        
    } catch (Exception $e) {
        die('Error generating PDF: ' . $e->getMessage());
    }
}

function generateReceiptContent($receiptData, $fineDetails, $logoData, $librarianName, $isOfficeCopy) {
    $html = '<div class="header">';
    
    if ($logoData) {
        $html .= '<img src="' . $logoData . '" class="logo" alt="School Logo">';
    }
    
    $html .= '
        <div class="school-name">SEKOLAH KEBANGSAAN SAUJANA IMPIAN 2</div>
        <div class="school-details">
            Persiaran Impian Perdana, Saujana Impian 2<br>
            43000 Kajang, Selangor Darul Ehsan<br>
            Tel: 03 – 8733 9008 | Fax: 03 – 87349017<br>
            Email: bbat049@btpnsel.edu.my
        </div>
    </div>
    
    <div class="receipt-title">OFFICIAL RECEIPT</div>
    
    <div class="info-row">
        <span class="info-label">Receipt No:</span>
        <span>' . htmlspecialchars($receiptData['receipt_number']) . '</span>
    </div>
    <div class="info-row">
        <span class="info-label">Date:</span>
        <span>' . date('d/m/Y H:i:s', strtotime($receiptData['transaction_date'])) . '</span>
    </div>
    <div class="info-row">
        <span class="info-label">Payer Name:</span>
        <span>' . htmlspecialchars($receiptData['full_name']) . '</span>
    </div>
    <div class="info-row">
        <span class="info-label">User ID:</span>
        <span>' . htmlspecialchars($receiptData['login_id']) . '</span>
    </div>';
    
    if (!$isOfficeCopy) {
        $html .= '
    <table class="fine-table">
        <thead>
            <tr>
                <th width="8%">No</th>
                <th width="12%">Fine ID</th>
                <th width="50%">Book Title</th>
                <th width="15%">Reason</th>
                <th width="15%">Amount</th>
            </tr>
        </thead>
        <tbody>';
        
        $no = 1;
        foreach ($fineDetails as $fine) {
            $reason = '';
            switch($fine['fine_reason']) {
                case 'overdue': $reason = 'Overdue'; break;
                case 'lost': $reason = 'Lost'; break;
                case 'damage': $reason = 'Damaged'; break;
                default: $reason = ucfirst($fine['fine_reason']);
            }
            
            $amountPaid = $fine['amount_paid'];
            
            $html .= '
            <tr>
                <td>' . $no++ . '</td>
                <td>' . $fine['fineID'] . '</td>
                <td>' . htmlspecialchars($fine['bookTitle'] ?? 'N/A') . '</td>
                <td>' . $reason . '</td>
                <td align="right">RM ' . number_format($amountPaid, 2) . '</td>
            </tr>';
        }
        
        $html .= '
            <tr class="total-row">
                <td colspan="4" align="right">TOTAL PAID:</td>
                <td align="right">RM ' . number_format($receiptData['total_amount_paid'], 2) . '</td>
            </tr>
        </tbody>
    </table>';
    }
    
    $html .= '
    <div class="payment-summary">
        <div class="payment-row">
            <span class="info-label">Total Paid:</span>
            <span>RM ' . number_format($receiptData['total_amount_paid'], 2) . '</span>
        </div>
        <div class="payment-row">
            <span class="info-label">Cash Received:</span>
            <span>RM ' . number_format($receiptData['cash_received'], 2) . '</span>
        </div>
        <div class="payment-row">
            <span class="info-label">Change:</span>
            <span>RM ' . number_format($receiptData['change_given'], 2) . '</span>
        </div>
        <div class="payment-row total">
            <span class="info-label">Payment Method:</span>
            <span>CASH</span>
        </div>
    </div>
    
    <div class="signature-section">
        <div class="signature-box">
            <div class="signature-line">
                <strong>Prepared By</strong><br>
                ' . htmlspecialchars($librarianName) . '<br>
                Librarian
            </div>
        </div>
        <div class="signature-box">
            <div class="signature-line">
                <strong>Received By</strong><br>
                ' . htmlspecialchars($receiptData['full_name']) . '<br>
                Payer
            </div>
        </div>
    </div>
    
    <div class="footer-note">
        <strong>** PLEASE KEEP THIS RECEIPT AS PROOF OF PAYMENT **</strong><br><br>
        Thank you for your payment<br>
        Library Management System - SMK Chendering
    </div>';
    
    return $html;
}

// Function to generate Letter PDF
function generateLetterPDF($letterNumber, $conn, $librarianName) {
    try {
        // Fetch letter data
        $query = "SELECT l.*, u.login_id, u.first_name, u.last_name, u.user_type,
                         CASE WHEN u.user_type = 'student' THEN s.studentName 
                              WHEN u.user_type = 'staff' THEN st.staffName END as full_name,
                         CASE WHEN u.user_type = 'student' THEN s.studentClass 
                              WHEN u.user_type = 'staff' THEN st.department END as class_dept
                  FROM fine_letters l
                  JOIN user u ON l.userID = u.userID
                  LEFT JOIN student s ON u.userID = s.userID AND u.user_type = 'student'
                  LEFT JOIN staff st ON u.userID = st.userID AND u.user_type = 'staff'
                  WHERE l.letter_number = ?";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param('s', $letterNumber);
        $stmt->execute();
        $result = $stmt->get_result();
        $letterData = $result->fetch_assoc();
        
        if (!$letterData) {
            die('Letter not found.');
        }
        
        // Get fine details
        $fineIds = explode(',', $letterData['fine_ids']);
        $fineDetails = [];
        
        foreach ($fineIds as $fineId) {
            $fineQuery = "SELECT f.*, book.bookTitle, book.book_price, b.due_date 
                         FROM fines f 
                         LEFT JOIN borrow b ON f.borrowID = b.borrowID 
                         LEFT JOIN book ON b.bookID = book.bookID 
                         WHERE f.fineID = ?";
            $stmt = $conn->prepare($fineQuery);
            $stmt->bind_param('i', $fineId);
            $stmt->execute();
            $result = $stmt->get_result();
            $fine = $result->fetch_assoc();
            if ($fine) {
                if (in_array($fine['fine_reason'], ['lost', 'damage']) && $fine['book_price'] > 0) {
                    $fine['display_amount'] = $fine['book_price'];
                } else {
                    $fine['display_amount'] = $fine['balance_due'] ?: $fine['fine_amount'];
                }
                $fineDetails[] = $fine;
            }
        }
        
        // Get logo
        $logoPath = __DIR__ . '/photo/logo1.png';
        $logoData = '';
        if (file_exists($logoPath)) {
            $logoData = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));
        }
        
        // Generate HTML
        $html = generateLetterHTML(
            $letterData['letter_type'], 
            $letterData, 
            $fineDetails, 
            $letterData['total_fine_amount'], 
            $letterNumber, 
            $librarianName,
            $logoData
        );
        
        // Generate PDF
        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 20,
            'margin_right' => 20,
            'margin_top' => 20,
            'margin_bottom' => 20,
        ]);
        
        $mpdf->WriteHTML($html);
        $mpdf->Output('Letter_' . $letterNumber . '.pdf', 'I');
        
    } catch (Exception $e) {
        die('Error generating PDF: ' . $e->getMessage());
    }
}

function generateLetterHTML($letterType, $userInfo, $fineDetails, $totalAmount, $letterNumber, $librarianName, $logoData) {
    $userName = $userInfo['full_name'];
    $userType = $userInfo['user_type'] == 'student' ? 'Student' : 'Staff';
    
    $todayDate = date('d F Y');
    
    $letterTitles = [
        'warning' => 'WARNING LETTER',
        'final_notice' => 'FINAL NOTICE',
        'replacement_demand' => 'REPLACEMENT DEMAND LETTER'
    ];
    
    $title = $letterTitles[$letterType] ?? 'OFFICIAL LETTER';
    
    $html = '
<!DOCTYPE html>
<html>
<head>
    <style>
        @page {
            margin: 20mm;
        }
        body {
            font-family: Arial, sans-serif;
            font-size: 11pt;
            line-height: 1.6;
            color: #000;
        }
        .header {
            text-align: center;
            margin-bottom: 15px;
            border-bottom: 2px solid #000;
            padding-bottom: 10px;
        }
        .logo {
            width: 80px;
            height: 80px;
            margin: 0 auto 10px;
        }
        .school-name {
            font-size: 15pt;
            font-weight: bold;
            margin: 5px 0;
        }
        .school-details {
            font-size: 9pt;
            margin: 3px 0;
        }
        .letter-title {
            text-align: center;
            font-size: 13pt;
            font-weight: bold;
            margin: 15px 0;
            text-decoration: underline;
        }
        .ref-section {
            margin: 12px 0;
            font-size: 10pt;
        }
        .recipient {
            margin: 12px 0;
            font-size: 10pt;
        }
        .content {
            text-align: justify;
            margin: 15px 0;
            font-size: 10.5pt;
        }
        .content p {
            margin: 10px 0;
        }
        .fine-table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }
        .fine-table th, .fine-table td {
            border: 1px solid #000;
            padding: 8px;
            text-align: left;
            font-size: 10pt;
        }
        .fine-table th {
            background: #e0e0e0;
            font-weight: bold;
        }
        .total-row {
            font-weight: bold;
            background: #f0f0f0;
        }
        .signature-section {
            margin-top: 40px;
        }
        .signature-box {
            margin-top: 60px;
        }
        .footer-note {
            margin-top: 25px;
            font-size: 9pt;
            font-style: italic;
            border-top: 1px solid #ccc;
            padding-top: 10px;
        }
    </style>
</head>
<body>
    <div class="header">';
    
    if ($logoData) {
        $html .= '<img src="' . $logoData . '" class="logo" alt="School Logo">';
    }
    
    $html .= '
        <div class="school-name">SEKOLAH KEBANGSAAN SAUJANA IMPIAN 2</div>
        <div class="school-details">
            Persiaran Impian Perdana, Saujana Impian 2<br>
            43000 Kajang, Selangor Darul Ehsan<br>
            Tel: 03 – 8733 9008 | Fax: 03 – 87349017<br>
            Email: bbat049@btpnsel.edu.my
        </div>
    </div>
    
    <div class="ref-section">
        <strong>Reference:</strong> ' . htmlspecialchars($letterNumber) . '<br>
        <strong>Date:</strong> ' . $todayDate . '
    </div>
    
    <div class="recipient">
        <strong>' . htmlspecialchars($userName) . '</strong><br>
        ID: ' . htmlspecialchars($userInfo['login_id']) . '<br>
        Status: ' . $userType . '<br>
        ' . ($userInfo['user_type'] == 'student' ? 'Class' : 'Department') . ': ' . htmlspecialchars($userInfo['class_dept'] ?? 'N/A') . '
    </div>
    
    <div class="letter-title">' . $title . '</div>
    
    <div class="content">';
    
    switch ($letterType) {
        case 'warning':
            $html .= '
        <p>Dear Sir/Madam,</p>
        <p><strong>WARNING REGARDING OUTSTANDING LIBRARY FINES</strong></p>
        <p>With due respect, I am writing to you regarding the above matter.</p>
        <p>2. This is to inform you that you have outstanding library fines that have not been paid. The details of the outstanding fines are as follows:</p>';
            break;
            
        case 'final_notice':
            $html .= '
        <p>Dear Sir/Madam,</p>
        <p><strong>FINAL NOTICE - OUTSTANDING LIBRARY FINES</strong></p>
        <p>With due respect, I am writing to you regarding the above matter.</p>
        <p>2. This is a <strong>FINAL NOTICE</strong> to you to settle the outstanding library fines. The details of the outstanding fines are as follows:</p>';
            break;
            
        case 'replacement_demand':
            $html .= '
        <p>Dear Sir/Madam,</p>
        <p><strong>LIBRARY BOOK REPLACEMENT DEMAND</strong></p>
        <p>With due respect, I am writing to you regarding the above matter.</p>
        <p>2. This is to inform you that the library books borrowed by you have been lost/damaged. Therefore, you are required to pay the replacement cost as follows:</p>';
            break;
    }
    
    $html .= '
    <table class="fine-table">
        <thead>
            <tr>
                <th width="8%">No</th>
                <th width="52%">Book Title</th>
                <th width="20%">Reason</th>
                <th width="20%">Amount (RM)</th>
            </tr>
        </thead>
        <tbody>';
    
    $no = 1;
    foreach ($fineDetails as $fine) {
        $reason = '';
        switch($fine['fine_reason']) {
            case 'overdue': $reason = 'Overdue'; break;
            case 'lost': $reason = 'Lost'; break;
            case 'damage': $reason = 'Damaged'; break;
            case 'late_return': $reason = 'Late Return'; break;
            default: $reason = ucfirst($fine['fine_reason']);
        }
        
        $html .= '
            <tr>
                <td>' . $no++ . '</td>
                <td>' . htmlspecialchars($fine['bookTitle'] ?? 'N/A') . '</td>
                <td>' . $reason . '</td>
                <td align="right">' . number_format($fine['display_amount'], 2) . '</td>
            </tr>';
    }
    
    $html .= '
            <tr class="total-row">
                <td colspan="3" align="right"><strong>TOTAL AMOUNT:</strong></td>
                <td align="right"><strong>RM ' . number_format($totalAmount, 2) . '</strong></td>
            </tr>
        </tbody>
    </table>
    
    <p>';
    
    switch ($letterType) {
        case 'warning':
            $html .= '3. You are required to settle this outstanding amount within <strong>SEVEN (7) DAYS</strong> from the date of this letter. Failure to do so will result in further action being taken.';
            break;
            
        case 'final_notice':
            $html .= '3. This is a final notice to you. You <strong>MUST</strong> settle the outstanding amount within <strong>THREE (3) DAYS</strong> from the date of this letter. Failure to do so will result in disciplinary action being taken against you.';
            break;
            
        case 'replacement_demand':
            $html .= '3. You are required to pay the replacement cost within <strong>FOURTEEN (14) DAYS</strong> from the date of this letter. Payment should be made at the school library office.';
            break;
    }
    
    $html .= '</p>
    
    <p>4. Your cooperation in this matter is greatly appreciated.</p>
    
    <p>Thank you.</p>
    
    </div>
    
    <div class="signature-section">
        <p><strong>"SERVING THE NATION"</strong></p>
        <p>Yours sincerely,</p>
        <div class="signature-box">
            <strong>' . htmlspecialchars($librarianName) . '</strong><br>
            Librarian<br>
            Sekolah Kebangsaan Saujana Impian 2
        </div>
    </div>
    
    <div class="footer-note">
        <p><strong>Note:</strong> Please bring this letter when making payment at the library.</p>
        <p><em>This letter is system-generated and does not require a signature.</em></p>
    </div>
</body>
</html>';
    
    return $html;
}

$searchResults = null;
$userInfo = null;
$userFines = [];
$allUserFines = [];
$paymentSuccess = false;
$receiptData = null;
$letterGenerated = false;
$letterData = null;
$error = null;
$view_mode = $_GET['view'] ?? 'overview';

// Handle Process Payment button from overview
if (isset($_POST['process_payment_user'])) {
    $view_mode = 'search';
    $_POST['search_user'] = 1;
    $_POST['login_id'] = $_POST['payment_login_id'];
}

// Handle search for specific user
$searchPerformed = false;
if (isset($_POST['search_user'])) {
    $searchPerformed = true;
    $loginId = trim($_POST['login_id']);
    
    if (!empty($loginId)) {
        $userQuery = "
            SELECT u.*, 
                   CASE 
                       WHEN u.user_type = 'student' THEN s.student_id_number
                       WHEN u.user_type = 'staff' THEN st.staff_id_number
                   END as id_number,
                   CASE 
                       WHEN u.user_type = 'student' THEN s.studentName
                       WHEN u.user_type = 'staff' THEN st.staffName
                   END as full_name,
                   CASE 
                       WHEN u.user_type = 'student' THEN s.studentClass
                       WHEN u.user_type = 'staff' THEN st.department
                   END as class_dept
            FROM user u
            LEFT JOIN student s ON u.userID = s.userID AND u.user_type = 'student'
            LEFT JOIN staff st ON u.userID = st.userID AND u.user_type = 'staff'
            WHERE u.login_id = ?
        ";
        
        $stmt = $conn->prepare($userQuery);
        $stmt->bind_param('s', $loginId);
        $stmt->execute();
        $result = $stmt->get_result();
        $userInfo = $result->fetch_assoc();
        
        if ($userInfo) {
            $finesQuery = "
                SELECT f.*, b.due_date, b.return_date, book.bookTitle, book.book_price, br.overdue_fine_per_day
                FROM fines f
                LEFT JOIN borrow b ON f.borrowID = b.borrowID
                LEFT JOIN book ON b.bookID = book.bookID
                LEFT JOIN borrowing_rules br ON br.user_type = ?
                WHERE f.userID = ? 
                AND (f.payment_status = 'unpaid' OR f.balance_due > 0)
                ORDER BY f.fine_date ASC
            ";
            
            $stmt = $conn->prepare($finesQuery);
            $stmt->bind_param('si', $userInfo['user_type'], $userInfo['userID']);
            $stmt->execute();
            $result = $stmt->get_result();
            $userFines = $result->fetch_all(MYSQLI_ASSOC);
        }
    }
}

if ($view_mode === 'overview') {
    $allFinesQuery = "
        SELECT u.userID, u.login_id, u.user_type, u.account_status,
               MAX(CASE 
                   WHEN u.user_type = 'student' THEN s.studentName
                   WHEN u.user_type = 'staff' THEN st.staffName
               END) as full_name,
               MAX(CASE 
                   WHEN u.user_type = 'student' THEN s.studentClass
                   WHEN u.user_type = 'staff' THEN st.department
               END) as class_dept,
               COUNT(f.fineID) as total_fines,
               SUM(f.balance_due) as total_amount,
               MAX(f.fine_date) as latest_fine_date,
               GROUP_CONCAT(DISTINCT f.fine_reason) as fine_reasons
        FROM user u
        LEFT JOIN student s ON u.userID = s.userID AND u.user_type = 'student'
        LEFT JOIN staff st ON u.userID = st.userID AND u.user_type = 'staff'
        INNER JOIN fines f ON u.userID = f.userID
        WHERE (f.payment_status = 'unpaid' OR f.balance_due > 0)
        GROUP BY u.userID, u.login_id, u.user_type, u.account_status
        ORDER BY total_amount DESC, latest_fine_date DESC
    ";
    
    $result = $conn->query($allFinesQuery);
    $allUserFines = $result->fetch_all(MYSQLI_ASSOC);
}

// ==================== COPY FROM HERE ====================
// Handle payment processing - FIXED VERSION
if (isset($_POST['process_payment'])) {
    $selectedFines = $_POST['selected_fines'] ?? [];
    $cashReceived = floatval($_POST['cash_received'] ?? 0);
    
    if (empty($selectedFines)) {
        $error = "Please select at least one fine to pay.";
    } elseif ($cashReceived <= 0) {
        $error = "Please enter a valid cash amount.";
    } elseif (!$currentLibrarianID) {
        $error = "Librarian authentication failed. Please log out and log in again.";
    } else {
        $conn->autocommit(FALSE);
        
        try {
            $totalPaid = 0;
            $paidFines = [];
            $fineDetails = [];
            $borrowIDsToUpdate = [];  // NEW: Track borrows that need status update
            $bookIDsToUpdate = [];    // NEW: Track books that need status update
            
            $userId = intval($_POST['user_id'] ?? 0);
            if ($userId > 0) {
                $userQuery = "SELECT u.*, 
                             CASE WHEN u.user_type = 'student' THEN s.studentName WHEN u.user_type = 'staff' THEN st.staffName END as full_name,
                             CASE WHEN u.user_type = 'student' THEN s.studentClass WHEN u.user_type = 'staff' THEN st.department END as class_dept
                             FROM user u 
                             LEFT JOIN student s ON u.userID = s.userID AND u.user_type = 'student'
                             LEFT JOIN staff st ON u.userID = st.userID AND u.user_type = 'staff'
                             WHERE u.userID = ?";
                $stmt = $conn->prepare($userQuery);
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $result = $stmt->get_result();
                $userInfo = $result->fetch_assoc();
            }
            
            // Process each selected fine
            foreach ($selectedFines as $fineId) {
                $fineAmount = floatval($_POST['fine_amount_' . $fineId] ?? 0);
                
                if ($fineAmount > 0) {
                    $stmt = $conn->prepare("SELECT f.*, b.bookID, book.bookTitle 
                                           FROM fines f 
                                           LEFT JOIN borrow b ON f.borrowID = b.borrowID 
                                           LEFT JOIN book ON b.bookID = book.bookID 
                                           WHERE f.fineID = ?");
                    $stmt->bind_param('i', $fineId);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $fine = $result->fetch_assoc();
                    
                    if ($fine) {
                        $newAmountPaid = ($fine['amount_paid'] ?? 0) + $fineAmount;
                        $newBalance = $fine['fine_amount'] - $newAmountPaid;
                        
                        // Mark as fully paid if balance is negligible (less than 1 cent)
                        $paymentStatus = $newBalance <= 0.01 ? 'paid_cash' : 'unpaid';
                        if ($newBalance <= 0.01) {
                            $newBalance = 0; // Zero out small balances
                        }
                        
                        // UPDATE FINES TABLE
                        $updateStmt = $conn->prepare("
                            UPDATE fines 
                            SET amount_paid = ?, 
                                balance_due = ?, 
                                payment_status = ?, 
                                payment_date = NOW(),
                                collected_by_librarianID = ?,
                                updated_date = NOW()
                            WHERE fineID = ?
                        ");
                        $updateStmt->bind_param('ddsii', $newAmountPaid, $newBalance, $paymentStatus, $currentLibrarianID, $fineId);
                        $updateStmt->execute();
                        
                        $totalPaid += $fineAmount;
                        $paidFines[] = $fineId;
                        
                        $fineDetails[] = [
                            'fineID' => $fineId,
                            'bookTitle' => $fine['bookTitle'] ?? 'N/A',
                            'fine_reason' => $fine['fine_reason'],
                            'amount_paid' => $fineAmount
                        ];

                        if ($paymentStatus === 'paid_cash') {
            // Update return_date if not set
            $conn->query("UPDATE borrow SET return_date = COALESCE(return_date, CURDATE()) 
                         WHERE borrowID = {$fine['borrowID']} AND return_date IS NULL");
            
            // Update borrow status to returned
            $conn->query("UPDATE borrow SET borrow_status = 'returned', fine_amount = 0, days_overdue = 0 
                         WHERE borrowID = {$fine['borrowID']}");
            
            // Update book status to available
            $conn->query("UPDATE book bk 
                         SET bk.bookStatus = 'available' 
                         WHERE bk.bookID = {$fine['bookID']}
                         AND NOT EXISTS (SELECT 1 FROM borrow b WHERE b.bookID = {$fine['bookID']} AND b.borrow_status IN ('borrowed', 'overdue'))");
        }
    

                        
                        // **FIX #1 & #2: Track borrowIDs and bookIDs if fine is fully paid**
                        if ($paymentStatus === 'paid_cash' && $fine['borrowID']) {
                            $borrowIDsToUpdate[] = $fine['borrowID'];
                            if ($fine['bookID']) {
                                $bookIDsToUpdate[] = $fine['bookID'];
                            }
                        }
                    }
                }
            }
            
            // **FIX #1: UPDATE BORROW STATUS TO 'RETURNED' FOR PAID FINES**
            if (!empty($borrowIDsToUpdate)) {
                foreach ($borrowIDsToUpdate as $borrowID) {
                    // First, set return_date if not already set
                    $setReturnDateStmt = $conn->prepare("
                        UPDATE borrow 
                        SET return_date = COALESCE(return_date, CURDATE()),
                            updated_date = NOW()
                        WHERE borrowID = ?
                        AND return_date IS NULL
                    ");
                    $setReturnDateStmt->bind_param('i', $borrowID);
                    $setReturnDateStmt->execute();
                    
                    // Then update status to 'returned'
                    $updateBorrowStmt = $conn->prepare("
                        UPDATE borrow 
                        SET borrow_status = 'returned',
                            fine_amount = 0,
                            days_overdue = 0,
                            updated_date = NOW()
                        WHERE borrowID = ?
                        AND borrow_status IN ('overdue', 'borrowed')
                    ");
                    $updateBorrowStmt->bind_param('i', $borrowID);
                    $updateBorrowStmt->execute();
                }
            }
            
            // **FIX #2: UPDATE BOOK STATUS TO 'AVAILABLE' FOR PAID FINES**
            if (!empty($bookIDsToUpdate)) {
                foreach ($bookIDsToUpdate as $bookID) {
                    // Check if this book has any active borrows
                    $checkBookStmt = $conn->prepare("
                        SELECT COUNT(*) as active_borrows 
                        FROM borrow 
                        WHERE bookID = ? 
                        AND borrow_status IN ('borrowed', 'overdue')
                    ");
                    $checkBookStmt->bind_param('i', $bookID);
                    $checkBookStmt->execute();
                    $bookResult = $checkBookStmt->get_result()->fetch_assoc();
                    
                    // Only set to 'available' if no active borrows exist
                    if ($bookResult['active_borrows'] == 0) {
                        // Check for reservations
                        $resStmt = $conn->prepare("
                            SELECT reservationID, userID 
                            FROM reservation 
                            WHERE bookID = ? 
                            AND reservation_status = 'waiting' 
                            ORDER BY queue_position ASC 
                            LIMIT 1
                        ");
                        $resStmt->bind_param('i', $bookID);
                        $resStmt->execute();
                        $resResult = $resStmt->get_result();
                        
                        if ($resResult->num_rows > 0) {
                            // Book has reservations - set to 'reserved' and notify next user
                            $reservation = $resResult->fetch_assoc();
                            
                            $updateBookStmt = $conn->prepare("
                                UPDATE book 
                                SET bookStatus = 'reserved',
                                    updated_date = NOW()
                                WHERE bookID = ?
                            ");
                            $updateBookStmt->bind_param('i', $bookID);
                            $updateBookStmt->execute();
                            
                            // Update reservation status
                            $updateResStmt = $conn->prepare("
                                UPDATE reservation 
                                SET reservation_status = 'ready',
                                    self_pickup_deadline = DATE_ADD(NOW(), INTERVAL 48 HOUR),
                                    pickup_notification_date = NOW()
                                WHERE reservationID = ?
                            ");
                            $updateResStmt->bind_param('i', $reservation['reservationID']);
                            $updateResStmt->execute();
                            
                            // Send notification
                            $notifStmt = $conn->prepare("
                                INSERT INTO notifications (userID, notification_type, title, message, related_reservationID, priority)
                                VALUES (?, 'reservation_ready', 'Book Ready for Pickup', 'Your reserved book is now available. Please collect within 48 hours.', ?, 'high')
                            ");
                            $notifStmt->bind_param('ii', $reservation['userID'], $reservation['reservationID']);
                            $notifStmt->execute();
                            
                        } else {
                            // No reservations - set to 'available'
                            $updateBookStmt = $conn->prepare("
                                UPDATE book 
                                SET bookStatus = 'available',
                                    updated_date = NOW()
                                WHERE bookID = ?
                            ");
                            $updateBookStmt->bind_param('i', $bookID);
                            $updateBookStmt->execute();
                        }
                    }
                }
            }
            
            // Generate receipt
            if ($totalPaid > 0 && $userInfo) {
                $receiptNumber = 'REC-' . date('Ymd-His') . '-' . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
                
                $receiptStmt = $conn->prepare("
                    INSERT INTO receipts (receipt_number, userID, librarianID, total_amount_paid, cash_received, change_given, transaction_date, fine_ids)
                    VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)
                ");
                
                $changeGiven = $cashReceived - $totalPaid;
                $fineIdsStr = implode(',', $paidFines);
                $receiptStmt->bind_param('siiddds', 
                    $receiptNumber, 
                    $userInfo['userID'], 
                    $currentLibrarianID, 
                    $totalPaid, 
                    $cashReceived, 
                    $changeGiven, 
                    $fineIdsStr
                );
                $receiptStmt->execute();
                
                $receiptID = $conn->insert_id;
                
                // Log activity
                $logStmt = $conn->prepare("
                    INSERT INTO user_activity_log (userID, action, description) 
                    VALUES (?, 'fine_payment', ?)
                ");
                $logDescription = "Fine payment processed. Receipt: $receiptNumber. Amount: RM" . number_format($totalPaid, 2);
                $logStmt->bind_param('is', $userInfo['userID'], $logDescription);
                $logStmt->execute();
                
                $receiptData = [
                    'receiptID' => $receiptID,
                    'receipt_number' => $receiptNumber,
                    'user_info' => $userInfo,
                    'total_paid' => $totalPaid,
                    'cash_received' => $cashReceived,
                    'change_given' => $changeGiven,
                    'paid_fines' => $paidFines,
                    'fine_details' => $fineDetails,
                    'transaction_date' => date('Y-m-d H:i:s')
                ];
                
                $conn->commit();
                $conn->autocommit(TRUE);
                $paymentSuccess = true;
                
                // **FIX #4: Refresh fines data - ONLY SHOW UNPAID FINES**
                $finesQuery = "
                    SELECT f.*, b.due_date, b.return_date, book.bookTitle, book.book_price, br.overdue_fine_per_day
                    FROM fines f
                    LEFT JOIN borrow b ON f.borrowID = b.borrowID
                    LEFT JOIN book ON b.bookID = book.bookID
                    LEFT JOIN borrowing_rules br ON br.user_type = ?
                    WHERE f.userID = ? 
                    AND (f.payment_status = 'unpaid' OR (f.payment_status = 'partial_paid' AND f.balance_due > 0))
                    ORDER BY f.fine_date ASC
                ";
                
                $stmt = $conn->prepare($finesQuery);
                $stmt->bind_param('si', $userInfo['user_type'], $userInfo['userID']);
                $stmt->execute();
                $result = $stmt->get_result();
                $userFines = $result->fetch_all(MYSQLI_ASSOC);
            }
            
        } catch (Exception $e) {
            $conn->rollback();
            $conn->autocommit(TRUE);
            $error = "Payment processing failed: " . $e->getMessage();
        }
    }
}
// ==================== COPY UNTIL HERE ====================


// Handle letter generation - FIXED
if (isset($_POST['generate_letter'])) {
    $letterType = $_POST['letter_type'];
    $selectedFines = $_POST['letter_fines'] ?? [];
    $userId = intval($_POST['user_id'] ?? 0);
    
    if (!$currentLibrarianID) {
        $error = "Librarian authentication failed. Please log out and log in again.";
    } elseif (empty($selectedFines)) {
        $error = "No fines selected for letter generation.";
    } elseif ($userId == 0) {
        $error = "User information not found.";
    } elseif (empty($letterType)) {
        $error = "Please select a letter type.";
    } else {
        $userQuery = "SELECT u.*, 
                      CASE WHEN u.user_type = 'student' THEN s.studentName WHEN u.user_type = 'staff' THEN st.staffName END as full_name,
                      CASE WHEN u.user_type = 'student' THEN s.studentClass WHEN u.user_type = 'staff' THEN st.department END as class_dept
                      FROM user u 
                      LEFT JOIN student s ON u.userID = s.userID 
                      LEFT JOIN staff st ON u.userID = st.userID 
                      WHERE u.userID = ?";
        $stmt = $conn->prepare($userQuery);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $userInfo = $result->fetch_assoc();
        
        if (!$userInfo) {
            $error = "User not found in database.";
        } else {
            $letterNumber = 'LTR-' . date('Ymd-His') . '-' . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
            
            $totalFineAmount = 0;
            $fineDetails = [];
            
            foreach ($selectedFines as $fineId) {
                $stmt = $conn->prepare("
                    SELECT f.*, book.bookTitle, book.book_price, b.due_date 
                    FROM fines f 
                    LEFT JOIN borrow b ON f.borrowID = b.borrowID 
                    LEFT JOIN book ON b.bookID = book.bookID 
                    WHERE f.fineID = ?
                ");
                $stmt->bind_param('i', $fineId);
                $stmt->execute();
                $result = $stmt->get_result();
                $fine = $result->fetch_assoc();
                
                if ($fine) {
                    if (in_array($fine['fine_reason'], ['lost', 'damage']) && $fine['book_price'] > 0) {
                        $fine['display_amount'] = $fine['book_price'];
                    } else {
                        $fine['display_amount'] = $fine['balance_due'] ?: $fine['fine_amount'];
                    }
                    
                    $totalFineAmount += $fine['display_amount'];
                    $fineDetails[] = $fine;
                }
            }
            
            try {
                // FIXED: Removed letter_file_path column and fixed bind_param
                $letterStmt = $conn->prepare("
                    INSERT INTO fine_letters (letter_number, userID, librarianID, letter_type, total_fine_amount, fine_ids, letter_content, issue_date)
                    VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE())
                ");
                
                $fineIdsStr = implode(',', $selectedFines);
                $letterContentText = "Letter generated for $letterType";
                
                // FIXED: Changed to 'siiidss' (7 parameters: string, int, int, string, decimal, string, string)
                $letterStmt->bind_param('siiidss', 
                    $letterNumber,
                    $userInfo['userID'],
                    $currentLibrarianID,
                    $letterType,
                    $totalFineAmount,
                    $fineIdsStr,
                    $letterContentText
                );
                
                if ($letterStmt->execute()) {
                    $letterData = [
                        'letter_number' => $letterNumber,
                        'letter_type' => $letterType,
                        'user_info' => $userInfo,
                        'fine_details' => $fineDetails,
                        'total_amount' => $totalFineAmount,
                        'issue_date' => date('Y-m-d')
                    ];
                    $letterGenerated = true;
                    $view_mode = 'search';
                } else {
                    $error = "Failed to save letter to database: " . $conn->error;
                }
            } catch (Exception $e) {
                $error = "Failed to generate letter: " . $e->getMessage();
            }
        }
    }
}

// Get fine statistics
$fine_stats_query = "
    SELECT 
        COUNT(CASE WHEN payment_status = 'unpaid' OR balance_due > 0 THEN 1 END) as total_unpaid,
        COUNT(CASE WHEN payment_status IN ('paid_cash', 'paid') AND balance_due = 0 THEN 1 END) as total_paid,
        COUNT(CASE WHEN payment_status = 'unpaid' AND amount_paid > 0 THEN 1 END) as total_partial,
        SUM(CASE WHEN payment_status = 'unpaid' OR balance_due > 0 THEN balance_due END) as total_outstanding_amount
    FROM fines
";
$fine_stats_result = $conn->query($fine_stats_query);
$fine_stats = $fine_stats_result->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fine Management - SMK Chendering Library</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <style>
        :root {
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

        .user-info {
            display: flex;
            flex-direction: column;
        }

        .user-name {
            font-size: 0.9rem;
            font-weight: 500;
            color: var(--dark);
        }

        .user-role {
            font-size: 0.8rem;
            color: var(--medium-gray);
        }

        .logout-btn {
            margin-left: 1rem;
            background: var(--danger);
            padding: 0.5rem 1rem;
            border-radius: 5px;
            color: white;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.9rem;
            transition: var(--transition);
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
            border-right: 1px solid var(--light-gray);
            padding: 1.5rem 0;
            z-index: 40;
            transition: var(--transition);
            overflow-y: auto;
            height: calc(100vh - var(--header-height));
        }

        .sidebar.collapsed {
            width: var(--sidebar-collapsed-width);
        }

        .sidebar.collapsed .menu-item span {
            display: none;
        }

        .sidebar.collapsed .menu-item {
            justify-content: center;
            padding: 0.85rem;
        }

        .sidebar.collapsed .menu-item i {
            margin-right: 0;
        }

        .sidebar.collapsed .sidebar-footer {
            display: none;
        }

        .menu-item {
            display: flex;
            align-items: center;
            padding: 0.75rem 1.5rem;
            color: var(--secondary);
            text-decoration: none;
            transition: var(--transition);
            border-left: 3px solid transparent;
            gap: 0.85rem;
        }

        .menu-item:hover {
            color: var(--primary);
            background: rgba(30, 58, 138, 0.05);
        }

        .menu-item.active {
            color: var(--primary);
            border-left-color: var(--primary);
            font-weight: 500;
            background: rgba(30, 58, 138, 0.05);
        }

        .menu-item i {
            width: 20px;
            text-align: center;
            font-size: 1.1rem;
        }

        .menu-item span {
            font-size: 0.95rem;
            font-weight: 500;
        }

        .sidebar-footer {
            padding: 1.5rem;
            border-top: 1px solid var(--light-gray);
            margin-top: auto;
        }

        .sidebar-footer p {
            font-size: 0.85rem;
            color: var(--medium-gray);
            line-height: 1.4;
        }

        .sidebar-footer p span {
            color: var(--primary);
            font-weight: 500;
        }

        .main-content {
            margin-left: var(--sidebar-width);
            margin-top: var(--header-height);
            min-height: calc(100vh - var(--header-height));
            padding: 1.5rem;
            transition: var(--transition);
        }

        .main-content.collapsed {
            margin-left: var(--sidebar-collapsed-width);
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.75rem;
        }

        .page-title {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--primary);
        }

        .welcome-text {
            font-size: 0.95rem;
            color: var(--medium-gray);
            margin-top: 0.25rem;
        }

        .view-tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
        }

        .view-tab {
            padding: 0.75rem 1.5rem;
            background: white;
            border: 1px solid var(--light-gray);
            border-radius: var(--border-radius);
            color: var(--secondary);
            text-decoration: none;
            font-weight: 500;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .view-tab:hover {
            color: var(--primary);
            border-color: var(--primary-light);
        }

        .view-tab.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stats-card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: 1.5rem;
            border: 1px solid var(--light-gray);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .stats-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: var(--primary);
        }

        .stats-card:hover {
            box-shadow: var(--card-shadow-hover);
            transform: translateY(-2px);
        }

        .stats-number {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            color: var(--primary);
        }

        .stats-label {
            font-size: 0.95rem;
            color: var(--secondary);
            font-weight: 500;
        }

        .stats-icon {
            position: absolute;
            top: 1rem;
            right: 1rem;
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            opacity: 0.1;
        }

        .content-card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            overflow: hidden;
            border: 1px solid var(--light-gray);
            margin-bottom: 2rem;
        }

        .card-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--light-gray);
            background: var(--light);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--dark);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card-body {
            padding: 1.5rem;
        }

        .search-form {
            display: flex;
            gap: 1rem;
            align-items: end;
            flex-wrap: wrap;
        }

        .form-group {
            flex: 1;
            min-width: 200px;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--secondary);
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--light-gray);
            border-radius: var(--border-radius);
            font-size: 0.95rem;
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-light);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .btn {
            padding: 0.75rem 1.25rem;
            border: none;
            border-radius: var(--border-radius);
            font-weight: 500;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: var(--transition);
            text-decoration: none;
            font-size: 0.95rem;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            color: white;
        }

        .btn-primary:disabled {
            background: var(--medium-gray);
            cursor: not-allowed;
            opacity: 0.6;
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-success:hover:not(:disabled) {
            background: #0d9f6e;
            color: white;
        }

        .btn-success:disabled {
            background: var(--medium-gray);
            cursor: not-allowed;
            opacity: 0.6;
        }

        .btn-secondary {
            background: var(--secondary);
            color: white;
        }

        .btn-secondary:hover {
            background: #5a6268;
            color: white;
        }

        .btn-warning {
            background: var(--warning);
            color: white;
        }

        .btn-warning:hover {
            background: #d97706;
            color: white;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            background: #dc2626;
            color: white;
        }

        .btn-sm {
            padding: 0.5rem 0.75rem;
            font-size: 0.85rem;
        }

        .user-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
            padding: 1rem;
            background: var(--light);
            border-radius: var(--border-radius);
        }

        .info-item {
            display: flex;
            flex-direction: column;
        }

        .info-label {
            font-size: 0.85rem;
            color: var(--medium-gray);
            margin-bottom: 0.25rem;
            font-weight: 500;
        }

        .info-value {
            font-weight: 600;
            color: var(--dark);
        }

        .fines-table, .users-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }

        .fines-table thead th, .users-table thead th {
            background: var(--light);
            padding: 1rem;
            text-align: left;
            font-weight: 500;
            color: var(--secondary);
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid var(--light-gray);
        }

        .fines-table tbody td, .users-table tbody td {
            padding: 1rem;
            border-bottom: 1px solid var(--light-gray);
            color: var(--dark);
            font-size: 0.95rem;
        }

        .fines-table tbody tr:hover, .users-table tbody tr:hover {
            background: rgba(30, 58, 138, 0.025);
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            display: inline-block;
        }

        .status-unpaid {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        .status-paid, .status-paid_cash {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .status-active {
            background: rgba(14, 165, 233, 0.1);
            color: var(--accent);
        }

        .amount-input {
            width: 100px;
            padding: 0.5rem;
            border: 1px solid var(--light-gray);
            border-radius: 4px;
            text-align: right;
        }

        .payment-section {
            margin-top: 1.5rem;
            padding: 1.5rem;
            background: var(--light);
            border-radius: var(--border-radius);
        }

        .payment-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .payment-info {
            display: flex;
            align-items: center;
            gap: 2rem;
            flex-wrap: wrap;
        }

        .payment-total {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--primary);
        }

        .calculator-section {
            margin-top: 1rem;
            padding: 1rem;
            background: white;
            border: 2px solid var(--primary-light);
            border-radius: var(--border-radius);
        }

        .calculator-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 0.5rem 0;
            gap: 1rem;
        }

        .calculator-amount {
            font-size: 1.1rem;
            font-weight: 600;
        }

        .change-amount {
            color: var(--success);
            font-size: 1.3rem;
            font-weight: 700;
        }

        .alert {
            padding: 1rem 1.5rem;
            border-radius: var(--border-radius);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            border: 1px solid transparent;
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            border-color: rgba(16, 185, 129, 0.2);
            color: var(--success);
        }

        .alert-danger {
            background: rgba(239, 68, 68, 0.1);
            border-color: rgba(239, 68, 68, 0.2);
            color: var(--danger);
        }

        .alert i {
            font-size: 1.25rem;
        }

        .no-results {
            text-align: center;
            padding: 3rem 2rem;
            color: var(--medium-gray);
        }

        .no-results h3 {
            font-size: 1.25rem;
            margin-bottom: 0.5rem;
            color: var(--secondary);
        }

        .action-buttons {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
            flex-wrap: wrap;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }

        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 20px;
            border-radius: var(--border-radius);
            width: 90%;
            max-width: 500px;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .close {
            color: #aaa;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }

        .close:hover {
            color: black;
        }

        .stats-card.total-unpaid::before {
            background: linear-gradient(90deg, var(--danger), #dc2626);
        }

        .stats-card.total-paid::before {
            background: linear-gradient(90deg, var(--success), #0d9f6e);
        }

        .stats-card.total-partial::before {
            background: linear-gradient(90deg, var(--warning), #d97706);
        }

        .stats-card.total-amount::before {
            background: linear-gradient(90deg, var(--primary), var(--primary-dark));
        }

        .stats-number.text-danger {
            color: var(--danger) !important;
        }

        .stats-number.text-success {
            color: var(--success) !important;
        }

        .stats-number.text-warning {
            color: var(--warning) !important;
        }

        .stats-number.text-primary {
            color: var(--primary) !important;
        }

        @media (max-width: 1024px) {
            .sidebar {
                width: var(--sidebar-collapsed-width);
            }
            
            .main-content {
                margin-left: var(--sidebar-collapsed-width);
            }
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
            }
            
            .stats-row {
                grid-template-columns: 1fr;
            }

            .view-tabs {
                flex-direction: column;
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
                <div class="logo-text">SMK <span>Chendering</span></div>
            </div>
        </div>
        <div class="header-right">
            <div class="user-menu">
                <div class="user-avatar">
                    <?php echo strtoupper(substr($librarian_name, 0, 1)); ?>
                </div>
                <div class="user-info">
                    <div class="user-name"><?php echo htmlspecialchars($librarian_name); ?></div>
                    <div class="user-role">Librarian</div>
                </div>
            </div>
            <a href="logout.php" class="logout-btn">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </header>

    <!-- Sidebar Navigation -->
    <aside class="sidebar" id="sidebar">
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
            <a href="circulation_control.php" class="menu-item ">
                <i class="fas fa-exchange-alt"></i>
                <span>Circulation Control</span>
            </a>
            <a href="fine_management.php" class="menu-item active">
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
                <h1 class="page-title">Fine Management</h1>
                <p class="welcome-text">Process fine payments and manage library fines</p>
            </div>
        </div>

        <div class="view-tabs">
            <a href="?view=overview" class="view-tab <?php echo $view_mode === 'overview' ? 'active' : ''; ?>">
                <i class="fas fa-list"></i> All Users with Fines
            </a>
            <a href="?view=search" class="view-tab <?php echo $view_mode === 'search' ? 'active' : ''; ?>">
                <i class="fas fa-search"></i> Search Specific User
            </a>
        </div>

        <?php if ($view_mode === 'overview'): ?>
        <div class="stats-row">
            <div class="stats-card total-unpaid">
                <div class="stats-icon" style="background: rgba(239, 68, 68, 0.1); color: var(--danger);">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="stats-number text-danger"><?php echo $fine_stats['total_unpaid'] ?? 0; ?></div>
                <div class="stats-label">Unpaid Fines</div>
            </div>
            <div class="stats-card total-paid">
                <div class="stats-icon" style="background: rgba(16, 185, 129, 0.1); color: var(--success);">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stats-number text-success"><?php echo $fine_stats['total_paid'] ?? 0; ?></div>
                <div class="stats-label">Paid Fines</div>
            </div>
            <div class="stats-card total-partial">
                <div class="stats-icon" style="background: rgba(245, 158, 11, 0.1); color: var(--warning);">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stats-number text-warning"><?php echo $fine_stats['total_partial'] ?? 0; ?></div>
                <div class="stats-label">Partial Payments</div>
            </div>
            <div class="stats-card total-amount">
                <div class="stats-icon" style="background: rgba(30, 58, 138, 0.1); color: var(--primary);">
                    <i class="fas fa-dollar-sign"></i>
                </div>
                <div class="stats-number text-primary">RM <?php echo number_format($fine_stats['total_outstanding_amount'] ?? 0, 2); ?></div>
                <div class="stats-label">Total Outstanding</div>
            </div>
        </div>
        <?php elseif ($view_mode === 'search' && $searchPerformed && $userInfo): ?>
        <div class="stats-row">
            <div class="stats-card total-unpaid">
                <div class="stats-icon" style="background: rgba(239, 68, 68, 0.1); color: var(--danger);">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="stats-number text-danger"><?php echo count($userFines); ?></div>
                <div class="stats-label">Unpaid Fines for <?php echo htmlspecialchars($userInfo['full_name'] ?? $userInfo['login_id']); ?></div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($paymentSuccess && $receiptData): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                Payment processed successfully! Receipt generated.
                <a href="?action=generate_receipt_pdf&receipt_id=<?php echo urlencode($receiptData['receipt_number']); ?>" 
                   target="_blank" class="btn btn-sm btn-primary" style="margin-left: auto;">
                    <i class="fas fa-file-pdf"></i> View Receipt PDF
                </a>
            </div>
        <?php endif; ?>

        <?php if ($letterGenerated && $letterData): ?>
            <div class="alert alert-success">
                <i class="fas fa-envelope"></i>
                Official letter generated successfully!
                <a href="?action=generate_letter_pdf&letter_id=<?php echo urlencode($letterData['letter_number']); ?>" 
                   target="_blank" class="btn btn-sm btn-warning" style="margin-left: auto;">
                    <i class="fas fa-file-pdf"></i> View Letter PDF
                </a>
            </div>
        <?php endif; ?>

        <?php if ($view_mode === 'overview'): ?>
            <div class="content-card">
                <div class="card-header">
                    <h2 class="card-title"><i class="fas fa-list"></i> All Users with Outstanding Fines</h2>
                </div>
                <div class="card-body">
                    <?php if (empty($allUserFines)): ?>
                        <div class="no-results">
                            <h3>No Outstanding Fines</h3>
                            <p>There are currently no users with outstanding fines.</p>
                        </div>
                    <?php else: ?>
                        <table class="users-table">
                            <thead>
                                <tr>
                                    <th>User Info</th>
                                    <th>Type</th>
                                    <th>Class/Dept</th>
                                    <th>Total Fines</th>
                                    <th>Amount Due</th>
                                    <th>Reasons</th>
                                    <th>Latest Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($allUserFines as $userFine): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($userFine['full_name'] ?? 'N/A'); ?></strong><br>
                                        <small><?php echo htmlspecialchars($userFine['login_id']); ?></small>
                                    </td>
                                    <td><?php echo ucfirst($userFine['user_type']); ?></td>
                                    <td><?php echo htmlspecialchars($userFine['class_dept'] ?? 'N/A'); ?></td>
                                    <td><?php echo $userFine['total_fines']; ?></td>
                                    <td><strong>RM <?php echo number_format($userFine['total_amount'], 2); ?></strong></td>
                                    <td><?php echo htmlspecialchars($userFine['fine_reasons']); ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($userFine['latest_fine_date'])); ?></td>
                                    <td>
                                        <form method="POST" action="?view=search" style="display:inline;">
                                            <input type="hidden" name="login_id" value="<?php echo htmlspecialchars($userFine['login_id']); ?>">
                                            <input type="hidden" name="payment_login_id" value="<?php echo htmlspecialchars($userFine['login_id']); ?>">
                                            <input type="hidden" name="process_payment_user" value="1">
                                            <button type="submit" class="btn btn-primary btn-sm">
                                                <i class="fas fa-credit-card"></i> Process Payment
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

        <?php elseif ($view_mode === 'search'): ?>
            <div class="content-card">
                <div class="card-header">
                    <h2 class="card-title"><i class="fas fa-search"></i> Search User</h2>
                </div>
                <div class="card-body">
                    <form method="POST" class="search-form">
                        <div class="form-group">
                            <label for="login_id">User Login ID</label>
                            <input type="text" class="form-control" id="login_id" name="login_id" 
                                   placeholder="Enter STU001, STF001, etc." 
                                   value="<?php echo htmlspecialchars($_POST['login_id'] ?? ''); ?>" required>
                        </div>
                        <button type="submit" name="search_user" class="btn btn-primary">
                            <i class="fas fa-search"></i> Search
                        </button>
                    </form>
                </div>
            </div>

            <?php if ($userInfo): ?>
                <div class="content-card">
                    <div class="card-header">
                        <h2 class="card-title"><i class="fas fa-user"></i> User Information</h2>
                    </div>
                    <div class="card-body">
                        <div class="user-info-grid">
                            <div class="info-item">
                                <div class="info-label">Full Name</div>
                                <div class="info-value"><?php echo htmlspecialchars($userInfo['full_name'] ?? $userInfo['first_name'] . ' ' . $userInfo['last_name']); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Login ID</div>
                                <div class="info-value"><?php echo htmlspecialchars($userInfo['login_id']); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">User Type</div>
                                <div class="info-value"><?php echo ucfirst($userInfo['user_type']); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label"><?php echo $userInfo['user_type'] == 'student' ? 'Class' : 'Department'; ?></div>
                                <div class="info-value"><?php echo htmlspecialchars($userInfo['class_dept'] ?? 'N/A'); ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="content-card">
                    <div class="card-header">
                        <h2 class="card-title"><i class="fas fa-money-bill-wave"></i> Outstanding Fines</h2>
                        <?php if (!empty($userFines)): ?>
                        <button onclick="showLetterModal()" class="btn btn-warning btn-sm">
                            <i class="fas fa-envelope"></i> Generate Letter
                        </button>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <?php if (empty($userFines)): ?>
                            <div class="no-results">
                                <h3>No Outstanding Fines</h3>
                                <p>This user has no unpaid fines.</p>
                            </div>
                        <?php else: ?>
                            <form method="POST" id="paymentForm">
                                <input type="hidden" name="user_id" value="<?php echo $userInfo['userID']; ?>">
                                
                                <table class="fines-table">
                                    <thead>
                                        <tr>
                                            <th>Select</th>
                                            <th>Fine ID</th>
                                            <th>Book Title</th>
                                            <th>Reason</th>
                                            <th>Date</th>
                                            <th>Total</th>
                                            <th>Paid</th>
                                            <th>Balance</th>
                                            <th>Payment</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $totalBalance = 0;
                                        foreach ($userFines as $fine): 
                                            $balance = $fine['balance_due'] ?: $fine['fine_amount'];
                                            $totalBalance += $balance;
                                        ?>
                                        <tr>
                                            <td>
                                                <input type="checkbox" name="selected_fines[]" value="<?php echo $fine['fineID']; ?>" 
                                                       class="fine-checkbox" data-balance="<?php echo $balance; ?>">
                                            </td>
                                            <td><?php echo $fine['fineID']; ?></td>
                                            <td><?php echo htmlspecialchars($fine['bookTitle'] ?? 'N/A'); ?></td>
                                            <td><?php echo ucfirst($fine['fine_reason']); ?></td>
                                            <td><?php echo date('d/m/Y', strtotime($fine['fine_date'])); ?></td>
                                            <td>RM <?php echo number_format($fine['fine_amount'], 2); ?></td>
                                            <td>RM <?php echo number_format($fine['amount_paid'] ?: 0, 2); ?></td>
                                            <td>RM <?php echo number_format($balance, 2); ?></td>
                                            <td>
                                                <input type="number" name="fine_amount_<?php echo $fine['fineID']; ?>" 
                                                       class="amount-input payment-amount" step="0.01" min="0" 
                                                       max="<?php echo $balance; ?>" value="<?php echo $balance; ?>" disabled>
                                            </td>
                                            <td>
                                                <span class="status-badge status-<?php echo $fine['payment_status']; ?>">
                                                    <?php echo ucfirst(str_replace('_', ' ', $fine['payment_status'])); ?>
                                                </span>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>

                                <div class="payment-section">
                                    <div class="payment-row">
                                        <div class="payment-info">
                                            <div>
                                                <strong>Total Outstanding: RM <?php echo number_format($totalBalance, 2); ?></strong>
                                            </div>
                                            <div class="payment-total">
                                                Selected Total: RM <span id="selectedTotal">0.00</span>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="calculator-section">
                                        <h4><i class="fas fa-calculator"></i> Payment Calculator</h4>
                                        <div class="calculator-row">
                                            <label for="cash_received"><strong>Cash Received (RM):</strong></label>
                                            <input type="number" id="cash_received" name="cash_received" 
                                                   class="form-control" style="width: 200px;" step="0.01" min="0" 
                                                   placeholder="0.00">
                                            <button type="button" class="btn btn-secondary btn-sm" onclick="fillExactAmount()">
                                                <i class="fas fa-equals"></i> Exact Amount
                                            </button>
                                        </div>
                                        <div class="calculator-row">
                                            <span><strong>Amount to Pay:</strong></span>
                                            <span class="calculator-amount">RM <span id="amountToPay">0.00</span></span>
                                        </div>
                                        <div class="calculator-row">
                                            <span><strong>Change to Give:</strong></span>
                                            <span class="change-amount">RM <span id="changeAmount">0.00</span></span>
                                        </div>
                                        <div id="changeError" class="alert alert-danger" style="display: none; margin-top: 1rem;">
                                            <i class="fas fa-exclamation-triangle"></i>
                                            <span id="changeErrorText">Insufficient cash received!</span>
                                        </div>
                                    </div>

                                    <div style="margin-top: 1rem; display: flex; gap: 1rem; justify-content: flex-end; flex-wrap: wrap;">
                                        <button type="submit" name="process_payment" class="btn btn-success" id="processBtn">
                                            <i class="fas fa-credit-card"></i> Process Payment
                                        </button>
                                        <button type="button" class="btn btn-secondary" onclick="clearSelection()">
                                            <i class="fas fa-times"></i> Clear
                                        </button>
                                        <button type="button" class="btn btn-primary" onclick="selectAll()">
                                            <i class="fas fa-check-double"></i> Select All
                                        </button>
                                    </div>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php elseif ($searchPerformed): ?>
                <div class="content-card">
                    <div class="card-body">
                        <div class="no-results">
                            <h3>User Not Found</h3>
                            <p>No user found with Login ID: <?php echo htmlspecialchars($_POST['login_id'] ?? ''); ?></p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <div id="letterModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h3><i class="fas fa-envelope"></i> Generate Official Letter</h3>
                    <span class="close" onclick="closeLetterModal()">&times;</span>
                </div>
                <form method="POST">
                    <?php if ($userInfo): ?>
                        <input type="hidden" name="user_id" value="<?php echo $userInfo['userID']; ?>">
                        <?php foreach ($userFines as $fine): ?>
                            <input type="hidden" name="letter_fines[]" value="<?php echo $fine['fineID']; ?>">
                        <?php endforeach; ?>
                    <?php endif; ?>
                    
                    <div class="form-group">
                        <label for="letter_type">Letter Type:</label>
                        <select name="letter_type" id="letter_type" class="form-control" required>
                            <option value="">Select Letter Type</option>
                            <option value="warning">Warning Letter</option>
                            <option value="final_notice">Final Notice</option>
                            <option value="replacement_demand">Replacement Demand</option>
                        </select>
                    </div>
                    
                    <div style="margin-top: 1rem; display: flex; gap: 1rem; justify-content: flex-end;">
                        <button type="submit" name="generate_letter" class="btn btn-warning">
                            <i class="fas fa-envelope"></i> Generate Letter
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="closeLetterModal()">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </main>

    <script>
        document.getElementById('sidebarToggle').addEventListener('click', function() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('collapsed');
        });

        function updateSelectedTotal() {
            let total = 0;
            const checkboxes = document.querySelectorAll('.fine-checkbox:checked');
            
            checkboxes.forEach(checkbox => {
                const fineId = checkbox.value;
                const amountInput = document.querySelector(`input[name="fine_amount_${fineId}"]`);
                if (amountInput && amountInput.value) {
                    total += parseFloat(amountInput.value);
                }
            });
            
            document.getElementById('selectedTotal').textContent = total.toFixed(2);
            document.getElementById('amountToPay').textContent = total.toFixed(2);
            calculateChange();
        }

        function calculateChange() {
            const amountToPay = parseFloat(document.getElementById('amountToPay').textContent || 0);
            const cashReceived = parseFloat(document.getElementById('cash_received').value || 0);
            const change = cashReceived - amountToPay;
            
            document.getElementById('changeAmount').textContent = Math.max(0, change).toFixed(2);
            
            const errorDiv = document.getElementById('changeError');
            const processBtn = document.getElementById('processBtn');
            
            if (amountToPay > 0 && cashReceived >= amountToPay) {
                errorDiv.style.display = 'none';
                processBtn.disabled = false;
            } else if (amountToPay > 0 && cashReceived > 0 && cashReceived < amountToPay) {
                errorDiv.style.display = 'block';
                document.getElementById('changeErrorText').textContent = 
                    `Insufficient cash! Need RM ${(amountToPay - cashReceived).toFixed(2)} more.`;
                processBtn.disabled = true;
            } else {
                errorDiv.style.display = 'none';
                processBtn.disabled = true;
            }
        }

        function fillExactAmount() {
            const selectedTotal = parseFloat(document.getElementById('selectedTotal').textContent || 0);
            if (selectedTotal > 0) {
                document.getElementById('cash_received').value = selectedTotal.toFixed(2);
                calculateChange();
            }
        }

        document.querySelectorAll('.fine-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const fineId = this.value;
                const amountInput = document.querySelector(`input[name="fine_amount_${fineId}"]`);
                const balance = parseFloat(this.dataset.balance);
                
                if (this.checked) {
                    amountInput.value = balance.toFixed(2);
                    amountInput.disabled = false;
                } else {
                    amountInput.value = '0.00';
                    amountInput.disabled = true;
                }
                updateSelectedTotal();
            });
        });

        document.querySelectorAll('.payment-amount').forEach(input => {
            input.addEventListener('input', updateSelectedTotal);
        });

        if (document.getElementById('cash_received')) {
            document.getElementById('cash_received').addEventListener('input', calculateChange);
        }

        function clearSelection() {
            document.querySelectorAll('.fine-checkbox').forEach(checkbox => {
                checkbox.checked = false;
                const fineId = checkbox.value;
                const amountInput = document.querySelector(`input[name="fine_amount_${fineId}"]`);
                if (amountInput) {
                    amountInput.value = '0.00';
                    amountInput.disabled = true;
                }
            });
            if (document.getElementById('cash_received')) {
                document.getElementById('cash_received').value = '';
            }
            updateSelectedTotal();
        }

        function selectAll() {
            document.querySelectorAll('.fine-checkbox').forEach(checkbox => {
                checkbox.checked = true;
                checkbox.dispatchEvent(new Event('change'));
            });
        }

        function showLetterModal() {
            document.getElementById('letterModal').style.display = 'block';
        }

        function closeLetterModal() {
            document.getElementById('letterModal').style.display = 'none';
        }

        if (document.getElementById('paymentForm')) {
            document.getElementById('paymentForm').addEventListener('submit', function(e) {
                const selectedFines = document.querySelectorAll('.fine-checkbox:checked');
                const cashReceived = parseFloat(document.getElementById('cash_received').value || 0);
                const selectedTotal = parseFloat(document.getElementById('selectedTotal').textContent || 0);

                if (selectedFines.length === 0) {
                    e.preventDefault();
                    alert('Please select at least one fine to pay.');
                    return false;
                }

                if (cashReceived < selectedTotal) {
                    e.preventDefault();
                    alert(`Insufficient cash. Required: RM ${selectedTotal.toFixed(2)}, Received: RM ${cashReceived.toFixed(2)}`);
                    return false;
                }

                const change = cashReceived - selectedTotal;
                if (!confirm(`Process payment of RM ${selectedTotal.toFixed(2)}?\nCash Received: RM ${cashReceived.toFixed(2)}\nChange: RM ${change.toFixed(2)}`)) {
                    e.preventDefault();
                    return false;
                }

                return true;
            });
        }

        window.addEventListener('click', function(event) {
            const modal = document.getElementById('letterModal');
            if (event.target === modal) {
                modal.style.display = 'none';
            }
        });
    </script>
</body>
</html>
