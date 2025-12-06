<?php
/**
 * SMK Chendering Library - Process Book Return
 * Backend handler for self-service book returns
 * Version 1.0
 */

session_start();
require_once 'dbconnect.php';
require_once 'auth_helper.php';

header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'student') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

$user_id = $_SESSION['user_id'];
$borrow_id = isset($_POST['borrow_id']) ? intval($_POST['borrow_id']) : 0;

if ($borrow_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid borrow ID']);
    exit;
}

try {
    // Start transaction
    $conn->begin_transaction();
    
    // Verify the borrow record belongs to this user and is currently borrowed
    $stmt = $conn->prepare("
        SELECT br.*, b.bookTitle, b.bookID, br.due_date
        FROM borrow br
        JOIN book b ON br.bookID = b.bookID
        WHERE br.borrowID = ? 
        AND br.userID = ? 
        AND br.borrow_status = 'borrowed'
        FOR UPDATE
    ");
    $stmt->bind_param("ii", $borrow_id, $user_id);
    $stmt->execute();
    $borrow_result = $stmt->get_result();
    
    if ($borrow_result->num_rows === 0) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Book not found or already returned']);
        exit;
    }
    
    $borrow_data = $borrow_result->fetch_assoc();
    $book_id = $borrow_data['bookID'];
    $book_title = $borrow_data['bookTitle'];
    $due_date = $borrow_data['due_date'];
    
    // Check for outstanding fines or overdue books
    $fines_query = "
        SELECT 
            COALESCE(
                (SELECT SUM(balance_due) 
                 FROM fines 
                 WHERE userID = ? 
                 AND payment_status IN ('unpaid', 'partial_paid')
                ), 0
            ) + 
            COALESCE(
                (SELECT SUM(fine_amount) 
                 FROM borrow 
                 WHERE userID = ? 
                 AND borrow_status IN ('borrowed', 'overdue') 
                 AND fine_amount > 0
                 AND borrowID NOT IN (
                     SELECT borrowID FROM fines WHERE payment_status IN ('unpaid', 'partial_paid')
                 )
                ), 0
            ) as total_fines
    ";
    $stmt = $conn->prepare($fines_query);
    $stmt->bind_param("ii", $user_id, $user_id);
    $stmt->execute();
    $fines_result = $stmt->get_result()->fetch_assoc();
    $total_fines = $fines_result['total_fines'];
    
    // Check for overdue books
    $stmt = $conn->prepare("
        SELECT COUNT(*) as overdue_count 
        FROM borrow 
        WHERE userID = ? 
        AND borrow_status = 'borrowed' 
        AND due_date < CURDATE()
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $overdue_result = $stmt->get_result()->fetch_assoc();
    $overdue_count = $overdue_result['overdue_count'];
    
    // If there are fines or overdue books, deny self-service return
    if ($total_fines > 0 || $overdue_count > 0) {
        $conn->rollback();
        echo json_encode([
            'success' => false, 
            'message' => 'Self-service return not available. Please visit the library counter to settle outstanding fines or overdue books.'
        ]);
        exit;
    }
    
    // Update borrow record - mark as returned
    $stmt = $conn->prepare("
        UPDATE borrow 
        SET borrow_status = 'returned',
            return_date = CURDATE(),
            self_return_time = NOW(),
            return_method = 'self_service',
            updated_date = NOW()
        WHERE borrowID = ?
    ");
    $stmt->bind_param("i", $borrow_id);
    $stmt->execute();
    
    if ($stmt->affected_rows === 0) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Failed to update borrow record']);
        exit;
    }
    
    // Update book status to available
    $stmt = $conn->prepare("
        UPDATE book 
        SET bookStatus = 'available',
            updated_date = NOW()
        WHERE bookID = ?
    ");
    $stmt->bind_param("i", $book_id);
    $stmt->execute();
    
    // Log the activity
    $action = "Book Returned (Self-Service)";
    $description = "Returned book: " . $book_title . " (Borrow ID: " . $borrow_id . ")";
    $stmt = $conn->prepare("
        INSERT INTO user_activity_log (userID, action, description, timestamp) 
        VALUES (?, ?, ?, NOW())
    ");
    $stmt->bind_param("iss", $user_id, $action, $description);
    $stmt->execute();
    
    // Send notification to user
    $notification_title = "Book Returned Successfully";
    $notification_message = "You have successfully returned '{$book_title}'. Thank you for using our self-service return system!";
    $stmt = $conn->prepare("
        INSERT INTO notifications (userID, notification_type, title, message, sent_date) 
        VALUES (?, 'book_returned', ?, ?, NOW())
    ");
    $stmt->bind_param("iss", $user_id, $notification_title, $notification_message);
    $stmt->execute();
    
    // Check if there's a reservation queue for this book
    // If yes, process the next reservation
    $stmt = $conn->prepare("
        SELECT reservationID, userID 
        FROM reservation 
        WHERE bookID = ? 
        AND reservation_status = 'waiting'
        ORDER BY queue_position ASC
        LIMIT 1
    ");
    $stmt->bind_param("i", $book_id);
    $stmt->execute();
    $reservation_result = $stmt->get_result();
    
    if ($reservation_result->num_rows > 0) {
        $reservation = $reservation_result->fetch_assoc();
        $reservation_id = $reservation['reservationID'];
        $reserved_user_id = $reservation['userID'];
        
        // Update reservation status to ready
        $stmt = $conn->prepare("
            UPDATE reservation 
            SET reservation_status = 'ready',
                self_pickup_deadline = DATE_ADD(NOW(), INTERVAL 48 HOUR),
                pickup_notification_date = NOW(),
                updated_date = NOW()
            WHERE reservationID = ?
        ");
        $stmt->bind_param("i", $reservation_id);
        $stmt->execute();
        
        // Update book status to reserved
        $stmt = $conn->prepare("
            UPDATE book 
            SET bookStatus = 'reserved',
                updated_date = NOW()
            WHERE bookID = ?
        ");
        $stmt->bind_param("i", $book_id);
        $stmt->execute();
        
        // Send notification to the user whose reservation is ready
        $notification_title = "Book Ready for Pickup";
        $notification_message = "Great news! '{$book_title}' is now available and ready for pickup. Please collect it within 48 hours.";
        $stmt = $conn->prepare("
            INSERT INTO notifications (userID, notification_type, title, message, related_reservationID, priority, sent_date) 
            VALUES (?, 'reservation_ready', ?, ?, ?, 'high', NOW())
        ");
        $stmt->bind_param("issi", $reserved_user_id, $notification_title, $notification_message, $reservation_id);
        $stmt->execute();
    }
    
    // Commit transaction
    $conn->commit();
    
    // Return success response
    echo json_encode([
        'success' => true, 
        'message' => "'{$book_title}' has been returned successfully! Thank you for using our self-service return system."
    ]);
    
} catch (Exception $e) {
    // Rollback on error
    $conn->rollback();
    error_log("Return Book Error: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'An error occurred while processing your return. Please try again or contact the librarian.'
    ]);
}

$conn->close();
?>