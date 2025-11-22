<?php
session_start();
require_once 'dbconnect.php';
require_once 'auth_helper.php';

requireRole('librarian');

header('Content-Type: application/json');

$reservationID = isset($_GET['reservationID']) ? intval($_GET['reservationID']) : 0;

if ($reservationID <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid reservation ID']);
    exit;
}

$query = "
    SELECT 
        r.*,
        u.first_name,
        u.last_name,
        u.user_type,
        u.email,
        u.phone_number,
        COALESCE(s.student_id_number, st.staff_id_number, u.login_id) AS id_number,
        bk.bookTitle,
        bk.bookAuthor,
        bk.bookBarcode,
        bk.book_ISBN,
        bk.bookStatus,
        bc.categoryName
    FROM reservation r
    LEFT JOIN user u ON r.userID = u.userID
    LEFT JOIN student s ON u.userID = s.userID AND u.user_type = 'student'
    LEFT JOIN staff st ON u.userID = st.userID AND u.user_type = 'staff'
    LEFT JOIN book bk ON r.bookID = bk.bookID
    LEFT JOIN book_category bc ON bk.categoryID = bc.categoryID
    WHERE r.reservationID = ?
";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $reservationID);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Reservation not found']);
    exit;
}

$data = $result->fetch_assoc();

$html = '
<div class="details-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
    <div>
        <h4 style="margin-bottom: 1rem; color: var(--primary);">Reservation Information</h4>
        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                <td style="padding: 0.5rem 0; font-weight: 500;">Reservation ID:</td>
                <td style="padding: 0.5rem 0;">' . $data['reservationID'] . '</td>
            </tr>
            <tr>
                <td style="padding: 0.5rem 0; font-weight: 500;">Reservation Date:</td>
                <td style="padding: 0.5rem 0;">' . date('d M Y', strtotime($data['reservation_date'])) . '</td>
            </tr>
            <tr>
                <td style="padding: 0.5rem 0; font-weight: 500;">Expiry Date:</td>
                <td style="padding: 0.5rem 0;">' . date('d M Y', strtotime($data['expiry_date'])) . '</td>
            </tr>
            <tr>
                <td style="padding: 0.5rem 0; font-weight: 500;">Queue Position:</td>
                <td style="padding: 0.5rem 0;">' . ($data['queue_position'] ?? 'N/A') . '</td>
            </tr>
            <tr>
                <td style="padding: 0.5rem 0; font-weight: 500;">Status:</td>
                <td style="padding: 0.5rem 0;"><span class="status-badge ' . $data['reservation_status'] . '">' . ucfirst($data['reservation_status']) . '</span></td>
            </tr>
        </table>
    </div>
    
    <div>
        <h4 style="margin-bottom: 1rem; color: var(--primary);">Reserver Information</h4>
        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                <td style="padding: 0.5rem 0; font-weight: 500;">Name:</td>
                <td style="padding: 0.5rem 0;">' . htmlspecialchars($data['first_name'] . ' ' . $data['last_name']) . '</td>
            </tr>
            <tr>
                <td style="padding: 0.5rem 0; font-weight: 500;">ID Number:</td>
                <td style="padding: 0.5rem 0;">' . htmlspecialchars($data['id_number']) . '</td>
            </tr>
            <tr>
                <td style="padding: 0.5rem 0; font-weight: 500;">User Type:</td>
                <td style="padding: 0.5rem 0;">' . ucfirst($data['user_type']) . '</td>
            </tr>
            <tr>
                <td style="padding: 0.5rem 0; font-weight: 500;">Email:</td>
                <td style="padding: 0.5rem 0;">' . htmlspecialchars($data['email']) . '</td>
            </tr>
        </table>
    </div>
</div>

<div style="margin-top: 1.5rem;">
    <h4 style="margin-bottom: 1rem; color: var(--primary);">Book Information</h4>
    <table style="width: 100%; border-collapse: collapse;">
        <tr>
            <td style="padding: 0.5rem 0; font-weight: 500; width: 200px;">Title:</td>
            <td style="padding: 0.5rem 0;">' . htmlspecialchars($data['bookTitle']) . '</td>
        </tr>
        <tr>
            <td style="padding: 0.5rem 0; font-weight: 500;">Author:</td>
            <td style="padding: 0.5rem 0;">' . htmlspecialchars($data['bookAuthor']) . '</td>
        </tr>
        <tr>
            <td style="padding: 0.5rem 0; font-weight: 500;">Category:</td>
            <td style="padding: 0.5rem 0;">' . htmlspecialchars($data['categoryName']) . '</td>
        </tr>
        <tr>
            <td style="padding: 0.5rem 0; font-weight: 500;">Current Status:</td>
            <td style="padding: 0.5rem 0;"><span class="status-badge ' . $data['bookStatus'] . '">' . ucfirst($data['bookStatus']) . '</span></td>
        </tr>
    </table>
</div>';

if ($data['cancellation_reason']) {
    $html .= '
<div style="margin-top: 1.5rem; padding: 1rem; background: #fee2e2; border-left: 4px solid var(--danger); border-radius: 6px;">
    <h4 style="margin-bottom: 0.5rem; color: var(--danger);">Cancellation Reason</h4>
    <p>' . nl2br(htmlspecialchars($data['cancellation_reason'])) . '</p>
</div>';
}

echo json_encode(['success' => true, 'html' => $html]);

$stmt->close();
$conn->close();
?>