<?php
require_once __DIR__ . '/vendor/autoload.php';

use Mpdf\Mpdf;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;

class LetterPDF {
    public function generate($html, $filename) {
        $defaultConfig = (new ConfigVariables())->getDefaults();
        $fontDirs = $defaultConfig['fontDir'];
        
        $defaultFontConfig = (new FontVariables())->getDefaults();
        $fontData = $defaultFontConfig['fontdata'];
        
        $mpdf = new Mpdf([
            'fontDir' => array_merge($fontDirs, [
                __DIR__ . '/assets/fonts',
            ]),
            'fontdata' => $fontData + [
                'arial' => [
                    'R' => 'arial.ttf',
                    'B' => 'arialbd.ttf',
                ]
            ],
            'default_font' => 'arial',
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 20,
            'margin_right' => 20,
            'margin_top' => 20,
            'margin_bottom' => 20,
        ]);
        
        $mpdf->WriteHTML($html);
        $mpdf->Output($filename, 'D');
    }
}

// FIXED: Single-page dual receipt layout
class ReceiptPDF {
    public function generate($receiptData, $filename) {
        $defaultConfig = (new ConfigVariables())->getDefaults();
        $fontDirs = $defaultConfig['fontDir'];
        
        $defaultFontConfig = (new FontVariables())->getDefaults();
        $fontData = $defaultFontConfig['fontdata'];
        
        $mpdf = new Mpdf([
            'fontDir' => array_merge($fontDirs, [
                __DIR__ . '/assets/fonts',
            ]),
            'fontdata' => $fontData + [
                'arial' => [
                    'R' => 'arial.ttf',
                    'B' => 'arialbd.ttf',
                ]
            ],
            'default_font' => 'arial',
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 10,
            'margin_right' => 10,
            'margin_top' => 10,
            'margin_bottom' => 10,
        ]);
        
        // Prepare librarian name from session or config
        $librarian_name = $_SESSION['full_name'] ?? 'Librarian';
        
        // FIXED: Create two copies on one page using a table layout
        $html = '
        <style>
            body { font-family: Arial, sans-serif; font-size: 10pt; }
            .receipt-container { width: 100%; border-collapse: collapse; }
            .receipt-section { width: 50%; vertical-align: top; padding: 10px; }
            .receipt-header { text-align: center; margin-bottom: 15px; }
            .receipt-header h3 { margin: 5px 0; font-size: 12pt; }
            .receipt-header h4 { margin: 5px 0; font-size: 11pt; }
            .copy-label { 
                background: #000; 
                color: #fff; 
                padding: 5px; 
                text-align: center; 
                font-weight: bold; 
                font-size: 10pt;
                margin-bottom: 15px;
            }
            .info-table { width: 100%; border-collapse: collapse; margin-bottom: 15px; font-size: 9pt; }
            .info-table td { border: 1px solid #000; padding: 4px; }
            .info-table td.label { font-weight: bold; width: 40%; }
            .details-table { width: 100%; border-collapse: collapse; margin: 15px 0; font-size: 9pt; }
            .details-table th { background: #f2f2f2; border: 1px solid #000; padding: 5px; }
            .details-table td { border: 1px solid #000; padding: 4px; }
            .total-row { font-weight: bold; background: #f9f9f9; }
            .signature { margin-top: 30px; text-align: center; }
            .signature-line { border-top: 1px solid #000; width: 200px; margin: 40px auto 5px; }
            .center-line { border-left: 2px dashed #999; padding-left: 15px; }
        </style>
        
        <table class="receipt-container">
            <tr>
                <!-- User Copy (LEFT SIDE) -->
                <td class="receipt-section">
                    <div class="copy-label">USER COPY</div>
                    
                    <div class="receipt-header">
                        <h3>SMK CHENDERING LIBRARY</h3>
                        <h4>OFFICIAL RECEIPT</h4>
                        <p><small>Resit Rasmi</small></p>
                    </div>
                    
                    <table class="info-table">
                        <tr>
                            <td class="label">Receipt No. / No. Resit:</td>
                            <td>' . htmlspecialchars($receiptData['receipt_number']) . '</td>
                        </tr>
                        <tr>
                            <td class="label">Date/Time / Tarikh/Masa:</td>
                            <td>' . date('d/m/Y H:i:s', strtotime($receiptData['transaction_date'])) . '</td>
                        </tr>
                        <tr>
                            <td class="label">Payer Name / Nama:</td>
                            <td>' . htmlspecialchars($receiptData['user_info']['full_name']) . '</td>
                        </tr>
                        <tr>
                            <td class="label">ID / No. Pengenalan:</td>
                            <td>' . htmlspecialchars($receiptData['user_info']['login_id']) . '</td>
                        </tr>
                    </table>
                    
                    <p style="font-weight: bold; margin-top: 15px;">FINE PAYMENT DETAILS / BUTIRAN BAYARAN DENDA:</p>
                    
                    <table class="details-table">
                        <thead>
                            <tr>
                                <th>Description / Butiran</th>
                                <th style="text-align: right;">Amount (RM) / Amaun</th>
                            </tr>
                        </thead>
                        <tbody>';
        
        foreach ($receiptData['fine_details'] as $fine) {
            $html .= '
                            <tr>
                                <td>Fine #' . htmlspecialchars($fine['fineID']) . ' - ' . htmlspecialchars($fine['bookTitle'] ?? 'N/A') . '</td>
                                <td style="text-align: right;">' . number_format($fine['amount_paid'], 2) . '</td>
                            </tr>';
        }
        
        $html .= '
                            <tr class="total-row">
                                <td style="text-align: right;"><strong>TOTAL / JUMLAH:</strong></td>
                                <td style="text-align: right;"><strong>' . number_format($receiptData['total_paid'], 2) . '</strong></td>
                            </tr>
                        </tbody>
                    </table>
                    
                    <table class="info-table" style="margin-top: 20px;">
                        <tr>
                            <td class="label">Cash Received / Tunai:</td>
                            <td style="text-align: right;">' . number_format($receiptData['cash_received'], 2) . '</td>
                        </tr>
                        <tr>
                            <td class="label">Change / Baki:</td>
                            <td style="text-align: right;">' . number_format($receiptData['change_given'], 2) . '</td>
                        </tr>
                    </table>
                    
                    <div class="signature">
                        <div class="signature-line"></div>
                        <p><strong>Disediakan Oleh / Prepared By</strong></p>
                        <p>' . htmlspecialchars($librarian_name) . '</p>
                        <p>Librarian</p>
                    </div>
                    
                    <div class="signature">
                        <div class="signature-line"></div>
                        <p><strong>Diterima Oleh / Received By</strong></p>
                        <p>' . htmlspecialchars($receiptData['user_info']['full_name']) . '</p>
                    </div>
                </td>
                
                <!-- Divider -->
                <td style="width: 2px; background: #999;"></td>
                
                <!-- Library Copy (RIGHT SIDE) -->
                <td class="receipt-section center-line">
                    <div class="copy-label">LIBRARY COPY</div>
                    
                    <div class="receipt-header">
                        <h3>SMK CHENDERING LIBRARY</h3>
                        <h4>OFFICIAL RECEIPT</h4>
                        <p><small>Resit Rasmi</small></p>
                    </div>
                    
                    <table class="info-table">
                        <tr>
                            <td class="label">Receipt No. / No. Resit:</td>
                            <td>' . htmlspecialchars($receiptData['receipt_number']) . '</td>
                        </tr>
                        <tr>
                            <td class="label">Date/Time / Tarikh/Masa:</td>
                            <td>' . date('d/m/Y H:i:s', strtotime($receiptData['transaction_date'])) . '</td>
                        </tr>
                        <tr>
                            <td class="label">Payer Name / Nama:</td>
                            <td>' . htmlspecialchars($receiptData['user_info']['full_name']) . '</td>
                        </tr>
                        <tr>
                            <td class="label">ID / No. Pengenalan:</td>
                            <td>' . htmlspecialchars($receiptData['user_info']['login_id']) . '</td>
                        </tr>
                    </table>
                    
                    <p style="font-weight: bold; margin-top: 15px;">FINE PAYMENT DETAILS / BUTIRAN BAYARAN DENDA:</p>
                    
                    <table class="details-table">
                        <thead>
                            <tr>
                                <th>Description / Butiran</th>
                                <th style="text-align: right;">Amount (RM) / Amaun</th>
                            </tr>
                        </thead>
                        <tbody>';
        
        foreach ($receiptData['fine_details'] as $fine) {
            $html .= '
                            <tr>
                                <td>Fine #' . htmlspecialchars($fine['fineID']) . ' - ' . htmlspecialchars($fine['bookTitle'] ?? 'N/A') . '</td>
                                <td style="text-align: right;">' . number_format($fine['amount_paid'], 2) . '</td>
                            </tr>';
        }
        
        $html .= '
                            <tr class="total-row">
                                <td style="text-align: right;"><strong>TOTAL / JUMLAH:</strong></td>
                                <td style="text-align: right;"><strong>' . number_format($receiptData['total_paid'], 2) . '</strong></td>
                            </tr>
                        </tbody>
                    </table>
                    
                    <table class="info-table" style="margin-top: 20px;">
                        <tr>
                            <td class="label">Cash Received / Tunai:</td>
                            <td style="text-align: right;">' . number_format($receiptData['cash_received'], 2) . '</td>
                        </tr>
                        <tr>
                            <td class="label">Change / Baki:</td>
                            <td style="text-align: right;">' . number_format($receiptData['change_given'], 2) . '</td>
                        </tr>
                    </table>
                    
                    <div class="signature">
                        <div class="signature-line"></div>
                        <p><strong>Disediakan Oleh / Prepared By</strong></p>
                        <p>' . htmlspecialchars($librarian_name) . '</p>
                        <p>Librarian</p>
                    </div>
                    
                    <div class="signature">
                        <div class="signature-line"></div>
                        <p><strong>Diterima Oleh / Received By</strong></p>
                        <p>' . htmlspecialchars($receiptData['user_info']['full_name']) . '</p>
                    </div>
                </td>
            </tr>
        </table>';
        
        $mpdf->WriteHTML($html);
        $mpdf->Output($filename, 'D');
    }
}

// Generate letter PDF from database
function generateLetterPDF($letterId) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            SELECT l.*, u.full_name, u.login_id, u.user_type, u.class_dept
            FROM letters l
            JOIN users u ON l.user_id = u.login_id
            WHERE l.letter_number = ?
        ");
        $stmt->execute([$letterId]);
        $letterData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$letterData) {
            throw new Exception('Letter not found');
        }
        
        // Get fine details
        $stmt = $pdo->prepare("
            SELECT f.*, b.title as bookTitle
            FROM fines f
            LEFT JOIN borrowed_books bb ON f.borrow_id = bb.id
            LEFT JOIN books b ON bb.book_id = b.id
            WHERE f.user_id = ? AND f.payment_status = 'unpaid'
            ORDER BY f.created_at DESC
        ");
        $stmt->execute([$letterData['user_id']]);
        $fineDetails = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($fineDetails)) {
            throw new Exception('No fines associated with this letter');
        }
        
        $todayDate = date('d/m/Y', strtotime($letterData['letter_date']));
        $userName = $letterData['full_name'];
        $userType = ucfirst($letterData['user_type']);
        $totalAmount = array_sum(array_column($fineDetails, 'amount'));
        
        switch($letterData['letter_type']) {
            case 'warning':
                $title = '<h3>WARNING LETTER</h3>';
                break;
            case 'final_notice':
                $title = '<h3>FINAL NOTICE LETTER</h3>';
                break;
            case 'replacement_demand':
                $title = '<h3>BOOK REPLACEMENT DEMAND LETTER</h3>';
                break;
            default:
                $title = '<h3>LIBRARY NOTICE</h3>';
        }
        
        $html = '
        <style>
            body { font-family: Arial, sans-serif; margin: 40px; line-height: 1.6; }
            .header { text-align: center; margin-bottom: 30px; }
            .user-info { margin-bottom: 25px; }
            table { width: 100%; border-collapse: collapse; margin: 20px 0; }
            th, td { border: 1px solid #000; padding: 8px; text-align: left; }
            th { background-color: #f2f2f2; }
            .footer { margin-top: 40px; }
            .signature { margin-top: 60px; }
        </style>
        
        <div class="header">
            <h2>SMK CHENDERING LIBRARY</h2>
            <p>Official Letter</p>
        </div>
        
        <div class="user-info">
            <p><strong>Reference:</strong> ' . htmlspecialchars($letterData['letter_number']) . '</p>
            <p><strong>Date:</strong> ' . $todayDate . '</p>
            <br>
            <p><strong>' . htmlspecialchars($userName) . '</strong></p>
            <p>ID: ' . htmlspecialchars($letterData['login_id']) . '</p>
            <p>Status: ' . $userType . '</p>
            <p>' . ($letterData['user_type'] == 'student' ? 'Class' : 'Department') . ': ' . htmlspecialchars($letterData['class_dept'] ?? 'N/A') . '</p>
        </div>
        
        <div class="content">
            ' . $title;
        
        switch ($letterData['letter_type']) {
            case 'warning':
                $html .= '
                <p>Dear Sir/Madam,</p>
                <h4>WARNING REGARDING OUTSTANDING LIBRARY FINES</h4>
                <p>With due respect, I am writing to you regarding the above matter.</p>
                <p>2. This is to inform you that you have outstanding library fines that have not been paid. The details of the outstanding fines are as follows:</p>';
                break;
                
            case 'final_notice':
                $html .= '
                <p>Dear Sir/Madam,</p>
                <h4>FINAL NOTICE - OUTSTANDING LIBRARY FINES</h4>
                <p>With due respect, I am writing to you regarding the above matter.</p>
                <p>2. This is a <strong>FINAL NOTICE</strong> to you to settle the outstanding library fines. The details of the outstanding fines are as follows:</p>';
                break;
                
            case 'replacement_demand':
                $html .= '
                <p>Dear Sir/Madam,</p>
                <h4>LIBRARY BOOK REPLACEMENT DEMAND</h4>
                <p>With due respect, I am writing to you regarding the above matter.</p>
                <p>2. This is to inform you that the library books borrowed by you have been lost/damaged. Therefore, you are required to pay the replacement cost as follows:</p>';
                break;
        }
        
        $html .= '
            <table>
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Book Title</th>
                        <th>Reason</th>
                        <th>Amount (RM)</th>
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
                        <td>RM ' . number_format($fine['amount'], 2) . '</td>
                    </tr>';
        }
        
        $html .= '
                    <tr>
                        <td colspan="3" style="text-align: right;"><strong>TOTAL AMOUNT:</strong></td>
                        <td><strong>RM ' . number_format($totalAmount, 2) . '</strong></td>
                    </tr>
                </tbody>
            </table>';
        
        switch ($letterData['letter_type']) {
            case 'warning':
                $html .= '<p>3. You are required to settle this outstanding amount within <strong>SEVEN (7) DAYS</strong> from the date of this letter. Failure to do so will result in further action being taken.</p>';
                break;
            case 'final_notice':
                $html .= '<p>3. This is a final notice to you. You <strong>MUST</strong> settle the outstanding amount within <strong>THREE (3) DAYS</strong> from the date of this letter. Failure to do so will result in disciplinary action being taken against you.</p>';
                break;
            case 'replacement_demand':
                $html .= '<p>3. You are required to pay the replacement cost within <strong>FOURTEEN (14) DAYS</strong> from the date of this letter. Payment should be made at the school library office.</p>';
                break;
        }
        
        $html .= '
                <p>4. Your cooperation in this matter is greatly appreciated.</p>
                <p>Thank you.</p>
            </div>
            
            <div class="signature">
                <p>Yours faithfully,</p>
                <br><br>
                <p>______________________</p>
                <p>Librarian</p>
                <p>SMK Chendering Library</p>
            </div>';
        
        $filename = 'letter_' . $letterData['letter_number'] . '.pdf';
        $pdf = new LetterPDF();
        $pdf->generate($html, $filename);
        
    } catch (Exception $e) {
        die('Error generating letter PDF: ' . $e->getMessage());
    }
}

// Generate receipt PDF from database
function generateReceiptPDF($receiptId) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            SELECT p.*, u.full_name, u.login_id, u.user_type
            FROM payments p
            JOIN users u ON p.user_id = u.login_id
            WHERE p.receipt_number = ?
        ");
        $stmt->execute([$receiptId]);
        $paymentData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$paymentData) {
            throw new Exception('Receipt not found');
        }
        
        // Get fine details
        $stmt = $pdo->prepare("
            SELECT f.*, pd.amount_paid, b.title as bookTitle
            FROM payment_details pd
            JOIN fines f ON pd.fine_id = f.id
            LEFT JOIN borrowed_books bb ON f.borrow_id = bb.id
            LEFT JOIN books b ON bb.book_id = b.id
            WHERE pd.payment_id = ?
        ");
        $stmt->execute([$paymentData['id']]);
        $fineDetails = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $receiptData = [
            'receipt_number' => $paymentData['receipt_number'],
            'transaction_date' => $paymentData['payment_date'],
            'user_info' => [
                'full_name' => $paymentData['full_name'],
                'login_id' => $paymentData['login_id']
            ],
            'fine_details' => $fineDetails,
            'total_paid' => $paymentData['total_amount'],
            'cash_received' => $paymentData['cash_received'],
            'change_given' => $paymentData['change_given']
        ];
        
        $pdf = new ReceiptPDF();
        $pdf->generate($receiptData, $receiptId . '.pdf');
        
    } catch (Exception $e) {
        die('Error generating receipt PDF: ' . $e->getMessage());
    }
}

// Helper function to output receipts directly from URL
if (!function_exists('generateReceiptPDF')) {
    function generateReceiptPDF($receiptId) {
        $pdf = new ReceiptPDF();
        // This is a placeholder - actual implementation should fetch from DB
        // See the full function above for complete implementation
    }
}