<?php
session_start();
require_once 'dbconnect.php';
require_once 'auth_helper.php';

requireRole('librarian');

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['borrowID'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$borrowID = intval($input['borrowID']);

$conn->begin_transaction();

try {
    // Get borrow details
    $stmt = $conn->prepare("
        SELECT b.bookID, b.userID, b.renewal_count, b.due_date, u.user_type, br.max_renewals_allowed, br.borrow_period_days
        FROM borrow b
        JOIN user u ON b.userID = u.userID
        JOIN borrowing_rules br ON u.user_type = br.user_type
        WHERE b.borrowID = ? AND b.borrow_status = 'borrowed'
    ");
    $stmt->bind_param("i", $borrowID);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        throw new Exception("Borrow record not found or already returned");
    }
    
    $data = $result->fetch_assoc();
    $stmt->close();
    
    // Check if book is reserved by someone else
    $reserve_check = $conn->prepare("
        SELECT reservationID 
        FROM reservation 
        WHERE bookID = ? AND reservation_status = 'waiting' AND userID != ?
    ");
    $reserve_check->bind_param("ii", $data['bookID'], $data['userID']);
    $reserve_check->execute();
    $reserve_result = $reserve_check->get_result();
    
    if ($reserve_result->num_rows > 0) {
        throw new Exception("Cannot renew: Book is reserved by another user");
    }
    $reserve_check->close();
    
    // Check renewal limit
    if ($data['renewal_count'] >= $data['max_renewals_allowed']) {
        throw new Exception("Maximum renewal limit reached");
    }
    
    // Calculate new due date
    $old_due_date = $data['due_date'];
    $new_due_date = date('Y-m-d', strtotime($old_due_date . ' + ' . $data['borrow_period_days'] . ' days'));
    $new_renewal_count = $data['renewal_count'] + 1;
    
    // Update borrow record
    $update_stmt = $conn->prepare("
        UPDATE borrow 
        SET due_date = ?, 
            renewal_count = ?,
            borrow_status = 'renewed'
        WHERE borrowID = ?
    ");
    $update_stmt->bind_param("sii", $new_due_date, $new_renewal_count, $borrowID);
    $update_stmt->execute();
    $update_stmt->close();
    
    // Insert renewal record
    $renewal_stmt = $conn->prepare("
        INSERT INTO renewals (borrowID, userID, old_due_date, new_due_date, renewal_count, renewal_method)
        VALUES (?, ?, ?, ?, ?, 'librarian_assisted')
    ");
    $renewal_stmt->bind_param("iissi", $borrowID, $data['userID'], $old_due_date, $new_due_date, $new_renewal_count);
    $renewal_stmt->execute();
    $renewal_stmt->close();
    
    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Book renewed successfully',
        'new_due_date' => date('d M Y', strtotime($new_due_date))
    ]);
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

$conn->close();
?>