<?php
session_start();
require_once 'dbconnect.php';
require_once 'auth_helper.php';
checkPageAccess();
requireRole('librarian');

header('Content-Type: application/json');

$reservationID = intval($_GET['reservationID'] ?? 0);

if ($reservationID == 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid reservation ID']);
    exit;
}

$query = "
    SELECT 
        r.*,
        u.first_name, u.last_name, u.user_type, u.login_id, u.email, u.phone_number,
        COALESCE(s.student_id_number, st.staff_id_number, u.login_id) AS id_number,
        COALESCE(s.studentClass, st.department) AS class_dept,
        bk.bookTitle, bk.bookAuthor, bk.book_ISBN, bk.bookBarcode, bk.bookStatus,
        bc.categoryName,
        CASE
            WHEN r.reservation_status = 'ready' THEN TIMESTAMPDIFF(HOUR, NOW(), r.self_pickup_deadline)
            ELSE NULL
        END AS hours_until_expiry,
        CASE
            WHEN r.reservation_status = 'ready' AND r.self_pickup_deadline < NOW() THEN 'expired'
            ELSE r.reservation_status
        END AS display_status
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

if ($result->num_rows == 0) {
    echo json_encode(['success' => false, 'message' => 'Reservation record not found']);
    exit;
}

$data = $result->fetch_assoc();

// Format reservation ID
$formattedResID = 'RSV' . str_pad($reservationID, 3, '0', STR_PAD_LEFT);

// Status colors
$statusColors = [
    'waiting' => ['bg' => '#dbeafe', 'text' => '#1e40af'],
    'ready' => ['bg' => '#d1fae5', 'text' => '#065f46'],
    'fulfilled' => ['bg' => '#d1fae5', 'text' => '#065f46'],
    'expired' => ['bg' => '#fee2e2', 'text' => '#991b1b'],
    'cancelled' => ['bg' => '#f3f4f6', 'text' => '#374151']
];

$statusColor = $statusColors[$data['display_status']] ?? ['bg' => '#f3f4f6', 'text' => '#6b7280'];

$html = '
<div style="display: grid; gap: 1.5rem;">
    <!-- Reservation Header -->
    <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 1.5rem; border-radius: 12px;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
            <div>
                <h3 style="margin: 0 0 0.5rem 0; font-size: 1.3rem;">Reservation ' . $formattedResID . '</h3>
                <div style="font-size: 0.9rem; opacity: 0.9;">Reserved on ' . date('d M Y, h:i A', strtotime($data['reservation_date'])) . '</div>
            </div>
            <div style="background: ' . $statusColor['bg'] . '; color: ' . $statusColor['text'] . '; padding: 0.5rem 1.5rem; border-radius: 20px; font-weight: 600; font-size: 1rem;">
                ' . ucfirst($data['display_status']) . '
            </div>
        </div>
    </div>

    <!-- Reserver Information -->
    <div style="background: #f8fafc; padding: 1.5rem; border-radius: 12px; border: 2px solid #e2e8f0;">
        <h3 style="margin: 0 0 1rem 0; font-size: 1.1rem; color: #1e293b; display: flex; align-items: center; gap: 0.5rem;">
            <i class="fas fa-user-circle"></i> Reserver Information
        </h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
            <div style="display: grid; grid-template-columns: 100px 1fr; gap: 0.5rem;">
                <span style="color: #64748b; font-weight: 500;">Name:</span>
                <span style="font-weight: 600; color: #1e293b;">' . htmlspecialchars($data['first_name'] . ' ' . $data['last_name']) . '</span>
            </div>
            <div style="display: grid; grid-template-columns: 100px 1fr; gap: 0.5rem;">
                <span style="color: #64748b; font-weight: 500;">ID Number:</span>
                <span style="font-weight: 600; color: #1e293b;">' . htmlspecialchars($data['id_number']) . '</span>
            </div>
            <div style="display: grid; grid-template-columns: 100px 1fr; gap: 0.5rem;">
                <span style="color: #64748b; font-weight: 500;">User Type:</span>
                <span style="font-weight: 600; color: #1e293b;">' . ucfirst($data['user_type']) . '</span>
            </div>
            <div style="display: grid; grid-template-columns: 100px 1fr; gap: 0.5rem;">
                <span style="color: #64748b; font-weight: 500;">' . ($data['user_type'] == 'student' ? 'Class:' : 'Department:') . '</span>
                <span style="font-weight: 600; color: #1e293b;">' . htmlspecialchars($data['class_dept'] ?? 'N/A') . '</span>
            </div>
        </div>
    </div>

    <!-- Book Information -->
    <div style="background: white; padding: 1.5rem; border-radius: 12px; border: 2px solid #e2e8f0;">
        <h3 style="margin: 0 0 1rem 0; font-size: 1.1rem; color: #1e293b; display: flex; align-items: center; gap: 0.5rem;">
            <i class="fas fa-book"></i> Book Information
        </h3>
        <div style="display: grid; gap: 0.75rem;">
            <div style="display: grid; grid-template-columns: 140px 1fr; gap: 0.5rem;">
                <span style="color: #64748b; font-weight: 500;">Title:</span>
                <span style="font-weight: 600; color: #1e293b;">' . htmlspecialchars($data['bookTitle']) . '</span>
            </div>
            <div style="display: grid; grid-template-columns: 140px 1fr; gap: 0.5rem;">
                <span style="color: #64748b; font-weight: 500;">Author:</span>
                <span style="color: #1e293b;">' . htmlspecialchars($data['bookAuthor']) . '</span>
            </div>
            <div style="display: grid; grid-template-columns: 140px 1fr; gap: 0.5rem;">
                <span style="color: #64748b; font-weight: 500;">ISBN:</span>
                <span style="color: #1e293b;">' . htmlspecialchars($data['book_ISBN'] ?? 'N/A') . '</span>
            </div>
            <div style="display: grid; grid-template-columns: 140px 1fr; gap: 0.5rem;">
                <span style="color: #64748b; font-weight: 500;">Barcode:</span>
                <span style="color: #1e293b;">' . htmlspecialchars($data['bookBarcode']) . '</span>
            </div>
            <div style="display: grid; grid-template-columns: 140px 1fr; gap: 0.5rem;">
                <span style="color: #64748b; font-weight: 500;">Category:</span>
                <span style="color: #1e293b;">' . htmlspecialchars($data['categoryName'] ?? 'N/A') . '</span>
            </div>
            <div style="display: grid; grid-template-columns: 140px 1fr; gap: 0.5rem;">
                <span style="color: #64748b; font-weight: 500;">Current Status:</span>
                <span style="color: #1e293b;">
                    <span style="display: inline-block; padding: 0.25rem 0.75rem; background: ' . 
                    ($data['bookStatus'] == 'available' ? '#d1fae5' : 
                     ($data['bookStatus'] == 'borrowed' ? '#fef3c7' : 
                      ($data['bookStatus'] == 'reserved' ? '#dbeafe' : '#fee2e2'))) . 
                    '; color: ' . 
                    ($data['bookStatus'] == 'available' ? '#065f46' : 
                     ($data['bookStatus'] == 'borrowed' ? '#92400e' : 
                      ($data['bookStatus'] == 'reserved' ? '#1e40af' : '#991b1b'))) . 
                    '; border-radius: 20px; font-size: 0.85rem; font-weight: 600;">
                        ' . ucfirst($data['bookStatus']) . '
                    </span>
                </span>
            </div>
        </div>
    </div>

    <!-- Reservation Timeline -->
    <div style="background: #fffbeb; padding: 1.5rem; border-radius: 12px; border: 2px solid #fde047;">
        <h3 style="margin: 0 0 1rem 0; font-size: 1.1rem; color: #92400e; display: flex; align-items: center; gap: 0.5rem;">
            <i class="fas fa-clock"></i> Reservation Timeline
        </h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">';

// Queue Position
if ($data['queue_position']) {
    $html .= '
            <div style="text-align: center; padding: 1rem; background: white; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="font-size: 0.85rem; color: #92400e; margin-bottom: 0.5rem;">Queue Position</div>
                <div style="font-weight: 700; font-size: 1.5rem; color: #b45309;">#' . $data['queue_position'] . '</div>
            </div>';
}

// Reservation Date
$html .= '
            <div style="text-align: center; padding: 1rem; background: white; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="font-size: 0.85rem; color: #92400e; margin-bottom: 0.5rem;">Reserved On</div>
                <div style="font-weight: 700; font-size: 1rem; color: #b45309;">' . date('d M Y', strtotime($data['reservation_date'])) . '</div>
                <div style="font-size: 0.8rem; color: #78350f; margin-top: 0.25rem;">' . date('h:i A', strtotime($data['reservation_date'])) . '</div>
            </div>';

// Expiry/Pickup Deadline
if ($data['reservation_status'] == 'ready' || $data['display_status'] == 'expired') {
    $isExpired = $data['display_status'] == 'expired' || $data['hours_until_expiry'] < 0;
    $html .= '
            <div style="text-align: center; padding: 1rem; background: ' . ($isExpired ? '#fee2e2' : '#d1fae5') . '; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="font-size: 0.85rem; color: ' . ($isExpired ? '#991b1b' : '#065f46') . '; margin-bottom: 0.5rem;">Pickup Deadline</div>
                <div style="font-weight: 700; font-size: 1rem; color: ' . ($isExpired ? '#dc2626' : '#10b981') . ';">' . date('d M Y', strtotime($data['self_pickup_deadline'])) . '</div>
                <div style="font-size: 0.8rem; color: ' . ($isExpired ? '#7f1d1d' : '#047857') . '; margin-top: 0.25rem;">' . date('h:i A', strtotime($data['self_pickup_deadline'])) . '</div>';
    
    if (!$isExpired && $data['hours_until_expiry'] !== null) {
        $html .= '
                <div style="margin-top: 0.5rem; font-size: 0.85rem; font-weight: 600; color: ' . ($data['hours_until_expiry'] < 6 ? '#dc2626' : '#10b981') . ';">
                    ' . abs($data['hours_until_expiry']) . ' hours ' . ($data['hours_until_expiry'] > 0 ? 'remaining' : 'overdue') . '
                </div>';
    } elseif ($isExpired) {
        $html .= '
                <div style="margin-top: 0.5rem; font-size: 0.85rem; font-weight: 600; color: #dc2626;">
                    <i class="fas fa-exclamation-triangle"></i> EXPIRED
                </div>';
    }
    
    $html .= '
            </div>';
}

// Notification Status
if ($data['notification_sent']) {
    $html .= '
            <div style="text-align: center; padding: 1rem; background: white; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="font-size: 0.85rem; color: #92400e; margin-bottom: 0.5rem;">Notification</div>
                <div style="font-weight: 700; font-size: 1rem; color: #10b981;">
                    <i class="fas fa-check-circle"></i> Sent
                </div>';
    if ($data['pickup_notification_date']) {
        $html .= '
                <div style="font-size: 0.75rem; color: #78350f; margin-top: 0.25rem;">' . date('d M Y, h:i A', strtotime($data['pickup_notification_date'])) . '</div>';
    }
    $html .= '
            </div>';
}

$html .= '
        </div>
    </div>';

// Cancellation Reason
if ($data['reservation_status'] == 'cancelled' && $data['cancellation_reason']) {
    $html .= '
    <div style="background: #fee2e2; padding: 1.5rem; border-radius: 12px; border: 2px solid #fca5a5;">
        <h3 style="margin: 0 0 0.5rem 0; font-size: 1.1rem; color: #991b1b; display: flex; align-items: center; gap: 0.5rem;">
            <i class="fas fa-times-circle"></i> Cancellation Reason
        </h3>
        <p style="margin: 0; color: #7f1d1d; line-height: 1.6;">' . nl2br(htmlspecialchars($data['cancellation_reason'])) . '</p>
    </div>';
}

// Contact Information
$html .= '
    <div style="background: #e0f2fe; padding: 1.5rem; border-radius: 12px; border: 2px solid #7dd3fc;">
        <h3 style="margin: 0 0 1rem 0; font-size: 1.1rem; color: #075985; display: flex; align-items: center; gap: 0.5rem;">
            <i class="fas fa-address-card"></i> Contact Information
        </h3>
        <div style="display: grid; gap: 0.75rem;">
            <div style="display: grid; grid-template-columns: 100px 1fr; gap: 0.5rem;">
                <span style="color: #0c4a6e; font-weight: 500;">Email:</span>
                <span style="color: #075985;">' . htmlspecialchars($data['email'] ?? 'N/A') . '</span>
            </div>
            <div style="display: grid; grid-template-columns: 100px 1fr; gap: 0.5rem;">
                <span style="color: #0c4a6e; font-weight: 500;">Phone:</span>
                <span style="color: #075985;">' . htmlspecialchars($data['phone_number'] ?? 'N/A') . '</span>
            </div>
        </div>
    </div>
</div>
';

echo json_encode(['success' => true, 'html' => $html]);
?>