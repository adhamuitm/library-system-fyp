<?php
session_start();
require_once 'dbconnect.php';
require_once 'auth_helper.php';

// Check authentication
checkPageAccess();
requireRole('librarian');

// Set JSON header
header('Content-Type: application/json');

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate input
if (!$input || !isset($input['action'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$response = ['success' => false, 'message' => ''];

try {
    $conn->autocommit(FALSE);
    
    switch ($input['action']) {
        case 'return':
            if (!isset($input['borrowID']) || !isset($input['bookID'])) {
                throw new Exception('Missing required fields');
            }
            
            $borrowID = intval($input['borrowID']);
            $bookID = intval($input['bookID']);
            
            // Get borrow details
            $borrow_query = "SELECT b.*, u.user_type FROM borrow b 
                            JOIN user u ON b.userID = u.userID 
                            WHERE b.borrowID = ?";
            $stmt = $conn->prepare($borrow_query);
            $stmt->bind_param("i", $borrowID);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows == 0) {
                throw new Exception('Borrow record not found');
            }
            
            $borrow = $result->fetch_assoc();
            
            // Calculate any outstanding fines
            $fine_amount = 0;
            if (strtotime($borrow['due_date']) < strtotime(date('Y-m-d'))) {
                $days_overdue = floor((strtotime(date('Y-m-d')) - strtotime($borrow['due_date'])) / 86400);
                
                // Get fine rate
                $fine_query = "SELECT overdue_fine_per_day FROM borrowing_rules WHERE user_type = ?";
                $stmt = $conn->prepare($fine_query);
                $stmt->bind_param("s", $borrow['user_type']);
                $stmt->execute();
                $fine_result = $stmt->get_result();
                $fine_rate = $fine_result->fetch_assoc()['overdue_fine_per_day'];
                
                $fine_amount = $days_overdue * $fine_rate;
                
                // Create or update fine record
                $check_fine = "SELECT fineID FROM fines WHERE borrowID = ?";
                $stmt = $conn->prepare($check_fine);
                $stmt->bind_param("i", $borrowID);
                $stmt->execute();
                $fine_exists = $stmt->get_result()->num_rows > 0;
                
                if (!$fine_exists && $fine_amount > 0) {
                    $insert_fine = "INSERT INTO fines (borrowID, userID, fine_amount, fine_reason, fine_date, balance_due) 
                                   VALUES (?, ?, ?, 'overdue', CURDATE(), ?)";
                    $stmt = $conn->prepare($insert_fine);
                    $stmt->bind_param("iidd", $borrowID, $borrow['userID'], $fine_amount, $fine_amount);
                    $stmt->execute();
                }
            }
            
            // Update borrow record
            $update_borrow = "UPDATE borrow 
                             SET borrow_status = 'returned',
                                 return_date = CURDATE(),
                                 self_return_time = NOW(),
                                 return_method = 'librarian_assisted',
                                 updated_date = NOW()
                             WHERE borrowID = ?";
            
            $stmt = $conn->prepare($update_borrow);
            $stmt->bind_param("i", $borrowID);
            
            if (!$stmt->execute()) {
                throw new Exception('Failed to update borrow record');
            }
            
            // Check if there are reservations for this book
            $check_reservations = "SELECT reservationID, userID FROM reservation 
                                  WHERE bookID = ? 
                                  AND reservation_status = 'waiting'
                                  ORDER BY queue_position ASC 
                                  LIMIT 1";
            
            $stmt = $conn->prepare($check_reservations);
            $stmt->bind_param("i", $bookID);
            $stmt->execute();
            $res_result = $stmt->get_result();
            
            if ($res_result->num_rows > 0) {
                // Book has reservations - mark as ready for pickup
                $reservation = $res_result->fetch_assoc();
                
                $update_reservation = "UPDATE reservation 
                                      SET reservation_status = 'ready',
                                          self_pickup_deadline = DATE_ADD(NOW(), INTERVAL 48 HOUR),
                                          pickup_notification_date = NOW(),
                                          notification_sent = 1,
                                          updated_date = NOW()
                                      WHERE reservationID = ?";
                
                $stmt = $conn->prepare($update_reservation);
                $stmt->bind_param("i", $reservation['reservationID']);
                $stmt->execute();
                
                // Update book status to reserved
                $update_book = "UPDATE book SET bookStatus = 'reserved' WHERE bookID = ?";
                $stmt = $conn->prepare($update_book);
                $stmt->bind_param("i", $bookID);
                $stmt->execute();
                
                // Send notification
                $notif_msg = "Your reserved book is now ready for pickup. Please collect within 48 hours.";
                $insert_notif = "INSERT INTO notifications (userID, notification_type, title, message, related_reservationID, priority) 
                                VALUES (?, 'reservation_ready', 'Book Ready for Pickup', ?, ?, 'high')";
                
                $stmt = $conn->prepare($insert_notif);
                $stmt->bind_param("isi", $reservation['userID'], $notif_msg, $reservation['reservationID']);
                $stmt->execute();
            } else {
                // No reservations - mark book as available
                $update_book = "UPDATE book SET bookStatus = 'available' WHERE bookID = ?";
                $stmt = $conn->prepare($update_book);
                $stmt->bind_param("i", $bookID);
                
                if (!$stmt->execute()) {
                    throw new Exception('Failed to update book status');
                }
            }
            
            // Create notification for borrower
            $return_notif = "Book returned successfully" . ($fine_amount > 0 ? ". Fine: RM " . number_format($fine_amount, 2) : "");
            $insert_return_notif = "INSERT INTO notifications (userID, notification_type, title, message, related_borrowID) 
                                   VALUES (?, 'book_returned', 'Book Returned', ?, ?)";
            
            $stmt = $conn->prepare($insert_return_notif);
            $stmt->bind_param("isi", $borrow['userID'], $return_notif, $borrowID);
            $stmt->execute();
            
            $conn->commit();
            
            $response = [
                'success' => true,
                'message' => 'Book returned successfully' . ($fine_amount > 0 ? ' with fine of RM ' . number_format($fine_amount, 2) : ''),
                'fine_amount' => $fine_amount
            ];
            
            break;
            
        case 'update_status':
            if (!isset($input['borrowID']) || !isset($input['newStatus'])) {
                throw new Exception('Missing required fields');
            }
            
            $borrowID = intval($input['borrowID']);
            $newStatus = $input['newStatus'];
            
            // Validate status
            $allowed_statuses = ['lost'];
            if (!in_array($newStatus, $allowed_statuses)) {
                throw new Exception('Invalid status. Only "lost" status can be manually set.');
            }
            
            // Get borrow and book info
            $borrow_query = "SELECT b.*, bk.book_price FROM borrow b 
                            JOIN book bk ON b.bookID = bk.bookID 
                            WHERE b.borrowID = ?";
            $stmt = $conn->prepare($borrow_query);
            $stmt->bind_param("i", $borrowID);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows == 0) {
                throw new Exception('Borrow record not found');
            }
            
            $borrow = $result->fetch_assoc();
            
            // Update borrow status
            $update_borrow = "UPDATE borrow 
                             SET borrow_status = ?,
                                 is_lost = 1,
                                 replacement_cost = ?,
                                 updated_date = NOW()
                             WHERE borrowID = ?";
            
            $stmt = $conn->prepare($update_borrow);
            $stmt->bind_param("sdi", $newStatus, $borrow['book_price'], $borrowID);
            
            if (!$stmt->execute()) {
                throw new Exception('Failed to update borrow status');
            }
            
            // Create fine for lost book
            $check_fine = "SELECT fineID FROM fines WHERE borrowID = ? AND fine_reason = 'lost'";
            $stmt = $conn->prepare($check_fine);
            $stmt->bind_param("i", $borrowID);
            $stmt->execute();
            $fine_exists = $stmt->get_result()->num_rows > 0;
            
            if (!$fine_exists) {
                $insert_fine = "INSERT INTO fines (borrowID, userID, fine_amount, fine_reason, fine_date, balance_due) 
                               VALUES (?, ?, ?, 'lost', CURDATE(), ?)";
                $stmt = $conn->prepare($insert_fine);
                $stmt->bind_param("iidd", $borrowID, $borrow['userID'], $borrow['book_price'], $borrow['book_price']);
                $stmt->execute();
            }
            
            // Send notification
            $notif_msg = "Book marked as lost. Replacement cost: RM " . number_format($borrow['book_price'], 2);
            $insert_notif = "INSERT INTO notifications (userID, notification_type, title, message, related_borrowID, priority) 
                            VALUES (?, 'fine_notice', 'Book Lost - Payment Required', ?, ?, 'urgent')";
            
            $stmt = $conn->prepare($insert_notif);
            $stmt->bind_param("isi", $borrow['userID'], $notif_msg, $borrowID);
            $stmt->execute();
            
            $conn->commit();
            
            $response = [
                'success' => true,
                'message' => 'Book status updated to lost. Replacement cost: RM ' . number_format($borrow['book_price'], 2)
            ];
            
            break;
            
        default:
            throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    $conn->rollback();
    $response = [
        'success' => false,
        'message' => $e->getMessage()
    ];
    error_log("Borrow Status Update Error: " . $e->getMessage());
}

$conn->autocommit(TRUE);
echo json_encode($response);
?>