<?php
/**
 * Cron Job Execution File for Fine Calculation
 * This file is called by server cron job daily
 * No authentication needed - runs automatically
 */

// Disable browser caching
header("Cache-Control: no-cache, must-revalidate");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");

// Set timezone
date_default_timezone_set('Asia/Kuala_Lumpur');

// Include required files
require_once 'dbconnect.php';

// Log file path
$log_file = __DIR__ . '/logs/fine_calculation.log';
$log_dir = dirname($log_file);

// Create logs directory if it doesn't exist
if (!file_exists($log_dir)) {
    mkdir($log_dir, 0755, true);
}

// Start logging
$start_time = date('Y-m-d H:i:s');
$separator = str_repeat('=', 80);

function logMessage($message, $log_file) {
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[{$timestamp}] {$message}\n";
    file_put_contents($log_file, $log_entry, FILE_APPEND);
    echo $log_entry; // Also output for cron job logs
}

logMessage($separator, $log_file);
logMessage("AUTOMATIC FINE CALCULATION STARTED", $log_file);
logMessage($separator, $log_file);

try {
    // Function to calculate and insert fines
    function calculateAndInsertFines($conn, $log_file) {
        $results = [
            'success' => false,
            'message' => '',
            'processed' => 0,
            'inserted' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => []
        ];
        
        try {
            // Start transaction
            $conn->begin_transaction();
            
            logMessage("Transaction started", $log_file);
            
            // Get all overdue books
            $query = "
                SELECT 
                    b.borrowID,
                    b.userID,
                    b.bookID,
                    b.due_date,
                    b.borrow_status,
                    u.user_type,
                    u.first_name,
                    u.last_name,
                    u.login_id,
                    bk.bookTitle,
                    br.overdue_fine_per_day,
                    DATEDIFF(CURDATE(), b.due_date) as days_overdue
                FROM borrow b
                JOIN user u ON b.userID = u.userID
                JOIN book bk ON b.bookID = bk.bookID
                JOIN borrowing_rules br ON u.user_type = br.user_type
                WHERE b.borrow_status IN ('borrowed', 'overdue')
                AND b.due_date < CURDATE()
                ORDER BY b.due_date ASC
            ";
            
            $overdue_result = $conn->query($query);
            
            if (!$overdue_result) {
                throw new Exception("Query failed: " . $conn->error);
            }
            
            logMessage("Found {$overdue_result->num_rows} overdue records to process", $log_file);
            
            while ($row = $overdue_result->fetch_assoc()) {
                $results['processed']++;
                
                $borrowID = $row['borrowID'];
                $userID = $row['userID'];
                $days_overdue = $row['days_overdue'];
                $fine_per_day = floatval($row['overdue_fine_per_day']);
                $calculated_fine = $days_overdue * $fine_per_day;
                
                // Skip if no overdue days
                if ($days_overdue <= 0 || $calculated_fine <= 0) {
                    $results['skipped']++;
                    continue;
                }
                
                // Check if fine already exists
                $check_stmt = $conn->prepare("
                    SELECT fineID, fine_amount, balance_due 
                    FROM fines 
                    WHERE borrowID = ? 
                    AND fine_reason = 'overdue'
                    AND payment_status IN ('unpaid', 'partial_paid')
                ");
                $check_stmt->bind_param("i", $borrowID);
                $check_stmt->execute();
                $existing_fine = $check_stmt->get_result()->fetch_assoc();
                
                if ($existing_fine) {
                    // UPDATE existing fine
                    $fineID = $existing_fine['fineID'];
                    $old_amount = floatval($existing_fine['fine_amount']);
                    
                    if ($calculated_fine != $old_amount) {
                        $old_balance = floatval($existing_fine['balance_due']);
                        $amount_paid = $old_amount - $old_balance;
                        $new_balance = $calculated_fine - $amount_paid;
                        
                        $update_fine_stmt = $conn->prepare("
                            UPDATE fines 
                            SET fine_amount = ?,
                                balance_due = ?,
                                updated_date = NOW()
                            WHERE fineID = ?
                        ");
                        $update_fine_stmt->bind_param("ddi", $calculated_fine, $new_balance, $fineID);
                        
                        if ($update_fine_stmt->execute()) {
                            $results['updated']++;
                            logMessage("Updated fine for BorrowID {$borrowID}: RM{$old_amount} -> RM{$calculated_fine}", $log_file);
                            
                            // Update borrow table
                            $update_borrow_stmt = $conn->prepare("
                                UPDATE borrow 
                                SET fine_amount = ?,
                                    borrow_status = 'overdue',
                                    days_overdue = ?
                                WHERE borrowID = ?
                            ");
                            $update_borrow_stmt->bind_param("dii", $calculated_fine, $days_overdue, $borrowID);
                            $update_borrow_stmt->execute();
                        }
                    }
                } else {
                    // INSERT new fine
                    $insert_stmt = $conn->prepare("
                        INSERT INTO fines 
                        (borrowID, userID, fine_amount, fine_reason, fine_date, payment_status, balance_due, created_date)
                        VALUES (?, ?, ?, 'overdue', CURDATE(), 'unpaid', ?, NOW())
                    ");
                    $insert_stmt->bind_param("iidd", $borrowID, $userID, $calculated_fine, $calculated_fine);
                    
                    if ($insert_stmt->execute()) {
                        $results['inserted']++;
                        logMessage("Inserted new fine for User {$row['login_id']} ({$row['first_name']} {$row['last_name']}): BorrowID {$borrowID}, Book: {$row['bookTitle']}, Amount: RM{$calculated_fine}", $log_file);
                        
                        // Update borrow table
                        $update_borrow_stmt = $conn->prepare("
                            UPDATE borrow 
                            SET borrow_status = 'overdue',
                                fine_amount = ?,
                                days_overdue = ?
                            WHERE borrowID = ?
                        ");
                        $update_borrow_stmt->bind_param("dii", $calculated_fine, $days_overdue, $borrowID);
                        $update_borrow_stmt->execute();
                        
                        // Create notification
                        $notif_stmt = $conn->prepare("
                            INSERT INTO notifications 
                            (userID, notification_type, title, message, related_borrowID, priority, sent_date)
                            VALUES 
                            (?, 'overdue_notice', 'Overdue Book Fine', 
                             CONCAT('You have an overdue book fine of RM', ?, ' for \"', ?, '\". Please return the book and pay the fine.'),
                             ?, 'high', NOW())
                        ");
                        $notif_stmt->bind_param("idsi", $userID, $calculated_fine, $row['bookTitle'], $borrowID);
                        $notif_stmt->execute();
                        
                        logMessage("Created notification for User {$row['login_id']}", $log_file);
                    } else {
                        $error_msg = "Failed to insert fine for borrowID {$borrowID}: " . $insert_stmt->error;
                        $results['errors'][] = $error_msg;
                        logMessage("ERROR: {$error_msg}", $log_file);
                    }
                }
            }
            
            // Commit transaction
            $conn->commit();
            logMessage("Transaction committed successfully", $log_file);
            
            $results['success'] = true;
            $results['message'] = "Fine calculation completed successfully";
            
        } catch (Exception $e) {
            $conn->rollback();
            $results['success'] = false;
            $results['message'] = "Error: " . $e->getMessage();
            $results['errors'][] = $e->getMessage();
            logMessage("CRITICAL ERROR: " . $e->getMessage(), $log_file);
            logMessage("Transaction rolled back", $log_file);
        }
        
        return $results;
    }
    
    // Execute the calculation
    $results = calculateAndInsertFines($conn, $log_file);
    
    // Log summary
    logMessage($separator, $log_file);
    logMessage("CALCULATION SUMMARY:", $log_file);
    logMessage("Status: " . ($results['success'] ? 'SUCCESS' : 'FAILED'), $log_file);
    logMessage("Processed: {$results['processed']}", $log_file);
    logMessage("Inserted: {$results['inserted']}", $log_file);
    logMessage("Updated: {$results['updated']}", $log_file);
    logMessage("Skipped: {$results['skipped']}", $log_file);
    
    if (!empty($results['errors'])) {
        logMessage("Errors encountered: " . count($results['errors']), $log_file);
        foreach ($results['errors'] as $error) {
            logMessage("  - {$error}", $log_file);
        }
    }
    
    // Get total unpaid fines summary
    $summary_query = "
        SELECT 
            COUNT(DISTINCT userID) as num_users,
            COUNT(fineID) as num_fines,
            SUM(balance_due) as total_unpaid
        FROM fines
        WHERE payment_status IN ('unpaid', 'partial_paid')
    ";
    $summary_result = $conn->query($summary_query);
    
    if ($summary_result) {
        $summary = $summary_result->fetch_assoc();
        logMessage($separator, $log_file);
        logMessage("CURRENT SYSTEM STATUS:", $log_file);
        logMessage("Total users with fines: {$summary['num_users']}", $log_file);
        logMessage("Total unpaid fines: {$summary['num_fines']}", $log_file);
        logMessage("Total unpaid amount: RM" . number_format($summary['total_unpaid'], 2), $log_file);
    }
    
    logMessage($separator, $log_file);
    logMessage("AUTOMATIC FINE CALCULATION COMPLETED", $log_file);
    logMessage($separator, $log_file);
    logMessage("", $log_file); // Empty line for readability
    
    // Return success status for cron job monitoring
    $exit_code = ($results['success'] ? 0 : 1);
    
} catch (Exception $e) {
    logMessage("FATAL ERROR: " . $e->getMessage(), $log_file);
    logMessage($separator, $log_file);
    exit(1);
}

if (isset($conn) && $conn) {
    $conn->close();
}

exit($exit_code ?? 1);
?>