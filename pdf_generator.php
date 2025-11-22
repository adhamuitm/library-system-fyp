<?php
/**
 * PDF Generator Helper for Fine Management System
 * Generates receipts and letters as downloadable PDFs
 */

require_once __DIR__ . '/vendor/autoload.php';

class PDFGenerator {
    
    /**
     * Generate receipt PDF
     */
    public static function generateReceiptPDF($receiptData, $librarian_name) {
        try {
            $mpdf = new \Mpdf\Mpdf([
                'mode' => 'utf-8',
                'format' => 'A4',
                'margin_left' => 15,
                'margin_right' => 15,
                'margin_top' => 15,
                'margin_bottom' => 15,
            ]);
            
            $html = self::getReceiptHTML($receiptData, $librarian_name);
            $mpdf->WriteHTML($html);
            
            // Save to receipts folder
            $filename = 'receipt_' . $receiptData['receipt_number'] . '.pdf';
            $filepath = __DIR__ . '/receipts/' . $filename;
            
            if (!file_exists(__DIR__ . '/receipts')) {
                mkdir(__DIR__ . '/receipts', 0777, true);
            }
            
            $mpdf->Output($filepath, 'F');
            
            return [
                'success' => true,
                'filename' => $filename,
                'filepath' => $filepath,
                'url' => 'receipts/' . $filename
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Generate letter PDF
     */
    public static function generateLetterPDF($letterNumber, $letterContent) {
        try {
            $mpdf = new \Mpdf\Mpdf([
                'mode' => 'utf-8',
                'format' => 'A4',
                'margin_left' => 20,
                'margin_right' => 20,
                'margin_top' => 20,
                'margin_bottom' => 20,
            ]);
            
            $mpdf->WriteHTML($letterContent);
            
            // Save to letters folder
            $filename = 'letter_' . $letterNumber . '.pdf';
            $filepath = __DIR__ . '/letters/' . $filename;
            
            if (!file_exists(__DIR__ . '/letters')) {
                mkdir(__DIR__ . '/letters', 0777, true);
            }
            
            $mpdf->Output($filepath, 'F');
            
            return [
                'success' => true,
                'filename' => $filename,
                'filepath' => $filepath,
                'url' => 'letters/' . $filename
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Stream PDF to browser for viewing
     */
    public static function streamPDF($filepath, $filename) {
        if (!file_exists($filepath)) {
            return false;
        }
        
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($filepath));
        readfile($filepath);
        exit;
    }
    
    /**
     * Force download PDF
     */
    public static function downloadPDF($filepath, $filename) {
        if (!file_exists($filepath)) {
            return false;
        }
        
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($filepath));
        readfile($filepath);
        exit;
    }
    
    /**
     * Generate receipt HTML
     */
    private static function getReceiptHTML($receiptData, $librarian_name) {
        // Get logo as base64
        $logoPath = __DIR__ . '/photo/logo1.png';
        $logoData = '';
        if (file_exists($logoPath)) {
            $logoData = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));
        }
        
        $html = '
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                @page {
                    margin: 10mm 10mm 10mm 10mm;
                }
                body {
                    font-family: Arial, sans-serif;
                    font-size: 10pt;
                    line-height: 1.3;
                    margin: 0;
                    padding: 0;
                }
                .receipt-container {
                    border: 2px solid #000;
                    padding: 10px;
                    margin-bottom: 10px;
                }
                .receipt-header {
                    text-align: center;
                    padding: 8px;
                    border-bottom: 2px solid #000;
                    margin-bottom: 8px;
                }
                .school-logo {
                    width: 60px;
                    height: 60px;
                    margin: 0 auto 5px;
                }
                .school-name {
                    font-weight: bold;
                    font-size: 12pt;
                    margin: 3px 0;
                }
                .school-details {
                    font-size: 8pt;
                    line-height: 1.2;
                }
                .receipt-title {
                    font-weight: bold;
                    font-size: 11pt;
                    text-align: center;
                    margin: 8px 0;
                    padding: 5px 0;
                    border-top: 1px dashed #000;
                    border-bottom: 1px dashed #000;
                }
                .info-table {
                    width: 100%;
                    margin: 5px 0;
                    font-size: 9pt;
                }
                .info-table td {
                    padding: 3px 5px;
                }
                .info-label {
                    width: 35%;
                    font-weight: bold;
                }
                .items-section {
                    border-top: 1px dashed #000;
                    border-bottom: 1px dashed #000;
                    padding: 5px 0;
                    margin: 8px 0;
                }
                .items-title {
                    font-weight: bold;
                    font-size: 9pt;
                    margin-bottom: 5px;
                }
                .items-table {
                    width: 100%;
                    font-size: 8pt;
                    border-collapse: collapse;
                }
                .items-table td {
                    padding: 2px 3px;
                }
                .item-desc {
                    width: 70%;
                }
                .item-amount {
                    width: 30%;
                    text-align: right;
                }
                .total-section {
                    font-size: 9pt;
                    margin: 5px 0;
                }
                .total-section table {
                    width: 100%;
                }
                .total-section td {
                    padding: 2px 5px;
                }
                .total-row {
                    font-weight: bold;
                    font-size: 10pt;
                    border-top: 2px solid #000;
                    padding-top: 5px;
                }
                .signature-section {
                    margin-top: 15px;
                    display: table;
                    width: 100%;
                }
                .signature-box {
                    display: table-cell;
                    width: 48%;
                    text-align: center;
                    font-size: 8pt;
                }
                .signature-line {
                    border-top: 1px solid #000;
                    margin-top: 25px;
                    padding-top: 3px;
                }
                .footer-note {
                    margin-top: 10px;
                    font-size: 7pt;
                    text-align: center;
                    font-style: italic;
                    border-top: 1px solid #ccc;
                    padding-top: 5px;
                }
                .copy-label {
                    text-align: center;
                    font-weight: bold;
                    font-size: 9pt;
                    margin: 8px 0;
                    padding: 3px;
                    background: #f0f0f0;
                    border: 1px dashed #000;
                }
                .cut-line {
                    text-align: center;
                    margin: 15px 0;
                    border-top: 2px dashed #999;
                    padding-top: 5px;
                    font-size: 8pt;
                    color: #666;
                }
            </style>
        </head>
        <body>';
        
        // CUSTOMER COPY
        $html .= self::generateReceiptCopy($receiptData, $librarian_name, $logoData, 'CUSTOMER COPY / SALINAN PELANGGAN');
        
        // CUT LINE
        $html .= '<div class="cut-line">✂ - - - - - - - - - - CUT HERE / POTONG DI SINI - - - - - - - - - - ✂</div>';
        
        // OFFICE COPY
        $html .= self::generateReceiptCopy($receiptData, $librarian_name, $logoData, 'OFFICE COPY / SALINAN PEJABAT');
        
        $html .= '
        </body>
        </html>';
        
        return $html;
    }
    
    /**
     * Generate single receipt copy (for customer or office)
     */
    private static function generateReceiptCopy($receiptData, $librarian_name, $logoData, $copyLabel) {
        $html = '<div class="receipt-container">';
        
        if ($copyLabel) {
            $html .= '<div class="copy-label">' . $copyLabel . '</div>';
        }
        
        $html .= '<div class="receipt-header">';
        
        if ($logoData) {
            $html .= '<img src="' . $logoData . '" class="school-logo" alt="Logo">';
        }
        
        $html .= '
                <div class="school-name">SMK CHENDERING</div>
                <div style="font-weight: bold; margin: 3px 0; font-size: 10pt;">SEKOLAH MENENGAH KEBANGSAAN CHENDERING</div>
                <div class="school-details">
                    Jalan Sekolah, 21080 Kuala Terengganu, Terengganu<br>
                    Tel: 09-622 3456 | Email: info@smkchendering.edu.my
                </div>
            </div>
            
            <div class="receipt-title">RESIT RASMI / OFFICIAL RECEIPT</div>
            
            <table class="info-table">
                <tr>
                    <td class="info-label">Receipt No / No. Resit:</td>
                    <td>' . htmlspecialchars($receiptData['receipt_number']) . '</td>
                </tr>
                <tr>
                    <td class="info-label">Date/Time / Tarikh/Masa:</td>
                    <td>' . date('d/m/Y H:i:s', strtotime($receiptData['transaction_date'])) . '</td>
                </tr>
                <tr>
                    <td class="info-label">Payer Name / Nama:</td>
                    <td>' . htmlspecialchars($receiptData['user_info']['full_name'] ?? 'N/A') . '</td>
                </tr>
                <tr>
                    <td class="info-label">ID / No. Pengenalan:</td>
                    <td>' . htmlspecialchars($receiptData['user_info']['login_id']) . '</td>
                </tr>
            </table>
            
            <div class="items-section">
                <div class="items-title">FINE PAYMENT DETAILS / BUTIRAN BAYARAN DENDA:</div>
                <table class="items-table">';
        
        foreach ($receiptData['fine_details'] as $fine) {
            $html .= '
                    <tr>
                        <td class="item-desc">Fine #' . $fine['fineID'] . ' - ' . htmlspecialchars($fine['bookTitle'] ?? 'N/A') . '</td>
                        <td class="item-amount">RM ' . number_format($fine['amount_paid'], 2) . '</td>
                    </tr>';
        }
        
        $html .= '
                </table>
            </div>
            
            <div class="total-section">
                <table>
                    <tr class="total-row">
                        <td style="text-align: left;"><strong>TOTAL PAID / JUMLAH DIBAYAR:</strong></td>
                        <td style="text-align: right;"><strong>RM ' . number_format($receiptData['total_paid'], 2) . '</strong></td>
                    </tr>
                    <tr>
                        <td>Cash Received / Tunai:</td>
                        <td style="text-align: right;">RM ' . number_format($receiptData['cash_received'], 2) . '</td>
                    </tr>
                    <tr>
                        <td>Change / Baki:</td>
                        <td style="text-align: right;">RM ' . number_format($receiptData['change_given'], 2) . '</td>
                    </tr>
                </table>
            </div>
            
            <div class="signature-section">
                <div class="signature-box">
                    <div class="signature-line">
                        Disediakan Oleh<br>Prepared By<br>
                        ' . htmlspecialchars($librarian_name) . '
                    </div>
                </div>
                <div class="signature-box">
                    <div class="signature-line">
                        Diterima Oleh<br>Received By<br>
                        ' . htmlspecialchars($receiptData['user_info']['full_name'] ?? 'N/A') . '
                    </div>
                </div>
            </div>
            
            <div class="footer-note">
                Terima kasih atas pembayaran anda. / Thank you for your payment.<br>
                <strong>** SILA SIMPAN RESIT INI / PLEASE KEEP THIS RECEIPT **</strong>
            </div>
        </div>';
        
        return $html;
    }
}