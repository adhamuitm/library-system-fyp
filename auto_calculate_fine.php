<?php
/**
 * Automatic Fine Calculation and Database Insertion
 * This script calculates overdue fines and inserts them into the database
 * Can be run manually or called automatically
 */

require_once 'dbconnect.php';

// Function to calculate and insert fines
function calculateAndInsertFines($conn) {
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
        // Start transaction for data integrity
        $conn->begin_transaction();
        
        // Step 1: Get all overdue books
        $query = "
            SELECT 
                b.borrowID,
                b.userID,
                b.bookID,
                b.due_date,
                b.borrow_date,
                b.borrow_status,
                b.fine_amount as current_fine_amount,
                u.user_type,
                u.first_name,
                u.last_name,
                u.email,
                bk.bookTitle,
                bk.bookAuthor,
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
            throw new Exception("Failed to fetch overdue records: " . $conn->error);
        }
        
        while ($row = $overdue_result->fetch_assoc()) {
            $results['processed']++;
            
            $borrowID = $row['borrowID'];
            $userID = $row['userID'];
            $days_overdue = $row['days_overdue'];
            $fine_per_day = floatval($row['overdue_fine_per_day']);
            
            // Calculate total fine
            $calculated_fine = $days_overdue * $fine_per_day;
            
            // Skip if no overdue days or negative
            if ($days_overdue <= 0 || $calculated_fine <= 0) {
                $results['skipped']++;
                continue;
            }
            
            // Check if fine already exists for this borrow
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
                // UPDATE existing fine if amount changed
                $fineID = $existing_fine['fineID'];
                $old_amount = floatval($existing_fine['fine_amount']);
                
                if ($calculated_fine != $old_amount) {
                    // Calculate new balance (preserve any partial payments)
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
                        
                        // Also update borrow table
                        $update_borrow_stmt = $conn->prepare("
                            UPDATE borrow 
                            SET fine_amount = ?,
                                borrow_status = 'overdue',
                                days_overdue = ?,
                                updated_date = NOW()
                            WHERE borrowID = ?
                        ");
                        $update_borrow_stmt->bind_param("dii", $calculated_fine, $days_overdue, $borrowID);
                        $update_borrow_stmt->execute();
                    }
                }
            } else {
                // INSERT new fine record
                $insert_stmt = $conn->prepare("
                    INSERT INTO fines 
                    (borrowID, userID, fine_amount, fine_reason, fine_date, payment_status, balance_due, created_date)
                    VALUES (?, ?, ?, 'overdue', CURDATE(), 'unpaid', ?, NOW())
                ");
                $insert_stmt->bind_param("iidd", $borrowID, $userID, $calculated_fine, $calculated_fine);
                
                if ($insert_stmt->execute()) {
                    $results['inserted']++;
                    
                    // Update borrow table status and fine amount
                    $update_borrow_stmt = $conn->prepare("
                        UPDATE borrow 
                        SET borrow_status = 'overdue',
                            fine_amount = ?,
                            days_overdue = ?,
                            updated_date = NOW()
                        WHERE borrowID = ?
                    ");
                    $update_borrow_stmt->bind_param("dii", $calculated_fine, $days_overdue, $borrowID);
                    $update_borrow_stmt->execute();
                    
                    // Create notification for user
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
                    
                } else {
                    $results['errors'][] = "Failed to insert fine for borrowID {$borrowID}: " . $insert_stmt->error;
                }
            }
        }
        
        // Commit all changes
        $conn->commit();
        
        $results['success'] = true;
        $results['message'] = "Fine calculation completed successfully. Processed: {$results['processed']}, Inserted: {$results['inserted']}, Updated: {$results['updated']}, Skipped: {$results['skipped']}";
        
    } catch (Exception $e) {
        // Rollback on error
        $conn->rollback();
        $results['success'] = false;
        $results['message'] = "Error: " . $e->getMessage();
        $results['errors'][] = $e->getMessage();
    }
    
    return $results;
}

// If accessed directly via browser (manual execution)
if (php_sapi_name() !== 'cli') {
    session_start();
    
    // Security check - only librarians can run manually
    if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'librarian') {
        die('
            <html>
            <head>
                <title>Unauthorized Access</title>
                <style>
                    body { font-family: Arial, sans-serif; padding: 40px; background: #f5f5f5; }
                    .error { background: white; padding: 30px; border-left: 4px solid #ef4444; border-radius: 8px; max-width: 600px; margin: 0 auto; }
                    .error h2 { color: #ef4444; margin-top: 0; }
                </style>
            </head>
            <body>
                <div class="error">
                    <h2>⛔ Unauthorized Access</h2>
                    <p>Only librarians can manually execute this script.</p>
                    <p><a href="login.php">← Back to Login</a></p>
                </div>
            </body>
            </html>
        ');
    }
    
    // Execute fine calculation
    $results = calculateAndInsertFines($conn);
    
    // Display results
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Fine Calculation Results</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body {
                font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                padding: 40px 20px;
                min-height: 100vh;
            }
            .container {
                max-width: 800px;
                margin: 0 auto;
            }
            .card {
                background: white;
                border-radius: 12px;
                box-shadow: 0 10px 40px rgba(0,0,0,0.2);
                overflow: hidden;
                animation: slideIn 0.3s ease;
            }
            @keyframes slideIn {
                from { opacity: 0; transform: translateY(-20px); }
                to { opacity: 1; transform: translateY(0); }
            }
            .card-header {
                background: <?php echo $results['success'] ? '#10b981' : '#ef4444'; ?>;
                color: white;
                padding: 30px;
                text-align: center;
            }
            .card-header i {
                font-size: 48px;
                margin-bottom: 10px;
            }
            .card-header h1 {
                font-size: 28px;
                margin-bottom: 10px;
            }
            .card-body {
                padding: 30px;
            }
            .stats {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
                gap: 20px;
                margin: 30px 0;
            }
            .stat-box {
                text-align: center;
                padding: 20px;
                background: #f8fafc;
                border-radius: 8px;
                border: 2px solid #e2e8f0;
            }
            .stat-number {
                font-size: 36px;
                font-weight: 700;
                color: #1e293b;
                margin-bottom: 5px;
            }
            .stat-label {
                font-size: 14px;
                color: #64748b;
                font-weight: 500;
            }
            .message {
                background: #f0fdf4;
                border-left: 4px solid #10b981;
                padding: 15px 20px;
                border-radius: 6px;
                margin: 20px 0;
            }
            .error-box {
                background: #fef2f2;
                border-left: 4px solid #ef4444;
                padding: 15px 20px;
                border-radius: 6px;
                margin: 20px 0;
            }
            .details-table {
                width: 100%;
                border-collapse: collapse;
                margin: 20px 0;
            }
            .details-table th {
                background: #f8fafc;
                padding: 12px;
                text-align: left;
                font-weight: 600;
                color: #475569;
                border-bottom: 2px solid #e2e8f0;
            }
            .details-table td {
                padding: 12px;
                border-bottom: 1px solid #e2e8f0;
            }
            .actions {
                display: flex;
                gap: 15px;
                margin-top: 30px;
                flex-wrap: wrap;
            }
            .btn {
                padding: 12px 24px;
                border-radius: 8px;
                text-decoration: none;
                font-weight: 600;
                display: inline-flex;
                align-items: center;
                gap: 8px;
                transition: all 0.2s;
            }
            .btn-primary {
                background: #3b82f6;
                color: white;
            }
            .btn-primary:hover {
                background: #2563eb;
                transform: translateY(-2px);
            }
            .btn-success {
                background: #10b981;
                color: white;
            }
            .btn-success:hover {
                background: #059669;
                transform: translateY(-2px);
            }
            .btn-secondary {
                background: #64748b;
                color: white;
            }
            .btn-secondary:hover {
                background: #475569;
                transform: translateY(-2px);
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-<?php echo $results['success'] ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                    <h1><?php echo $results['success'] ? '✓ Fine Calculation Completed' : '✗ Calculation Failed'; ?></h1>
                    <p><?php echo htmlspecialchars($results['message']); ?></p>
                </div>
                
                <div class="card-body">
                    <div class="stats">
                        <div class="stat-box">
                            <div class="stat-number"><?php echo $results['processed']; ?></div>
                            <div class="stat-label">Total Processed</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-number" style="color: #10b981;"><?php echo $results['inserted']; ?></div>
                            <div class="stat-label">New Fines Inserted</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-number" style="color: #3b82f6;"><?php echo $results['updated']; ?></div>
                            <div class="stat-label">Fines Updated</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-number" style="color: #64748b;"><?php echo $results['skipped']; ?></div>
                            <div class="stat-label">Skipped</div>
                        </div>
                    </div>
                    
                    <?php if (!empty($results['errors'])): ?>
                    <div class="error-box">
                        <strong><i class="fas fa-exclamation-circle"></i> Errors Encountered:</strong>
                        <ul style="margin: 10px 0 0 20px;">
                            <?php foreach ($results['errors'] as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>
                    
                    <?php
                    // Display current fines summary
                    $summary_query = "
                        SELECT 
                            u.login_id,
                            u.first_name,
                            u.last_name,
                            u.user_type,
                            COUNT(f.fineID) as num_fines,
                            SUM(f.balance_due) as total_amount
                        FROM fines f
                        JOIN user u ON f.userID = u.userID
                        WHERE f.payment_status IN ('unpaid', 'partial_paid')
                        GROUP BY u.userID
                        ORDER BY total_amount DESC
                        LIMIT 10
                    ";
                    
                    $summary_result = $conn->query($summary_query);
                    
                    if ($summary_result && $summary_result->num_rows > 0):
                    ?>
                    <h3 style="margin-top: 30px; color: #1e293b;">
                        <i class="fas fa-list"></i> Top Users with Unpaid Fines
                    </h3>
                    <table class="details-table">
                        <thead>
                            <tr>
                                <th>Login ID</th>
                                <th>Name</th>
                                <th>Type</th>
                                <th style="text-align: center;">No. of Fines</th>
                                <th style="text-align: right;">Total Amount (RM)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($row = $summary_result->fetch_assoc()): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($row['login_id']); ?></strong></td>
                                <td><?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?></td>
                                <td>
                                    <span style="background: #e0e7ff; color: #3730a3; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 600;">
                                        <?php echo strtoupper($row['user_type']); ?>
                                    </span>
                                </td>
                                <td style="text-align: center;"><?php echo $row['num_fines']; ?></td>
                                <td style="text-align: right; font-weight: 700; color: #dc2626;">
                                    RM <?php echo number_format($row['total_amount'], 2); ?>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <div class="message">
                        <i class="fas fa-info-circle"></i> <strong>No unpaid fines in the system.</strong>
                    </div>
                    <?php endif; ?>
                    
                    <div class="actions">
                        <a href="circulation_control.php" class="btn btn-primary">
                            <i class="fas fa-arrow-left"></i> Back to Circulation Control
                        </a>
                        <a href="fine_management.php" class="btn btn-success">
                            <i class="fas fa-receipt"></i> Manage Fines
                        </a>
                        <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-secondary">
                            <i class="fas fa-redo"></i> Run Again
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
}

// Return the function for use in other scripts
return [
    'calculateAndInsertFines' => 'calculateAndInsertFines'
];
?>