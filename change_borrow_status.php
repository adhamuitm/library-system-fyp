<?php
session_start();
require_once 'dbconnect.php';
require_once 'auth_helper.php';

requireRole('librarian');

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['borrowID']) || !isset($input['newStatus'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$borrowID = intval($input['borrowID']);
$newStatus = $input['newStatus'];

$allowed_statuses = ['borrowed', 'returned', 'overdue', 'lost', 'renewed'];

if (!in_array($newStatus, $allowed_statuses)) {
    echo json_encode(['success' => false, 'message' => 'Invalid status']);
    exit;
}

$conn->begin_transaction();

try {
    // Get current borrow info
    $check_stmt = $conn->prepare("SELECT bookID, borrow_status, return_date FROM borrow WHERE borrowID = ?");
    $check_stmt->bind_param("i", $borrowID);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    
    if ($result->num_rows === 0) {
        throw new Exception("Borrow record not found");
    }
    
    $borrow_data = $result->fetch_assoc();
    $bookID = $borrow_data['bookID'];
    $current_status = $borrow_data['borrow_status'];
    $check_stmt->close();
    
    // Update borrow status
    $update_stmt = $conn->prepare("UPDATE borrow SET borrow_status = ? WHERE borrowID = ?");
    $update_stmt->bind_param("si", $newStatus, $borrowID);
    
    if (!$update_stmt->execute()) {
        throw new Exception("Failed to update borrow status");
    }
    $update_stmt->close();
    
    // Update book status based on new borrow status
    $bookStatus = 'borrowed';
    if ($newStatus === 'returned') {
        $bookStatus = 'available';
        
        // Set return date if not already set
        if (!$borrow_data['return_date']) {
            $return_stmt = $conn->prepare("UPDATE borrow SET return_date = CURDATE() WHERE borrowID = ?");
            $return_stmt->bind_param("i", $borrowID);
            $return_stmt->execute();
            $return_stmt->close();
        }
    }
    
    $book_stmt = $conn->prepare("UPDATE book SET bookStatus = ? WHERE bookID = ?");
    $book_stmt->bind_param("si", $bookStatus, $bookID);
    $book_stmt->execute();
    $book_stmt->close();
    
    // Log activity
    $librarian_id = $_SESSION['userID'];
    $log_stmt = $conn->prepare("
        INSERT INTO user_activity_log (userID, action, description) 
        VALUES (?, 'status_change', ?)
    ");
    $description = "Changed borrow status from '$current_status' to '$newStatus' for borrowID: $borrowID";
    $log_stmt->bind_param("is", $librarian_id, $description);
    $log_stmt->execute();
    $log_stmt->close();
    
    $conn->commit();
    
    echo json_encode([
        'success' => true, 
        'message' => "Status changed to '$newStatus' successfully"
    ]);
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode([
        'success' => false, 
        'message' => 'Error: ' . $e->getMessage()
    ]);
}

$conn->close();
?>