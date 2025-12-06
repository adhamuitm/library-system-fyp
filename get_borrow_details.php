<?php
session_start();
require_once 'dbconnect.php';
require_once 'auth_helper.php';
checkPageAccess();
requireRole('librarian');

header('Content-Type: application/json');

$borrowID = intval($_GET['borrowID'] ?? 0);

if ($borrowID == 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid borrow ID']);
    exit;
}

$query = "
    SELECT 
        b.*,
        u.first_name, u.last_name, u.user_type, u.login_id, u.email, u.phone_number,
        COALESCE(s.student_id_number, st.staff_id_number, u.login_id) AS id_number,
        COALESCE(s.studentClass, st.department) AS class_dept,
        bk.bookTitle, bk.bookAuthor, bk.book_ISBN, bk.bookBarcode, bk.bookStatus,
        bc.categoryName,
        f.fineID, f.fine_amount, f.balance_due, f.payment_status,
        br.overdue_fine_per_day, br.borrow_period_days
    FROM borrow b
    LEFT JOIN user u ON b.userID = u.userID
    LEFT JOIN student s ON u.userID = s.userID AND u.user_type = 'student'
    LEFT JOIN staff st ON u.userID = st.userID AND u.user_type = 'staff'
    LEFT JOIN book bk ON b.bookID = bk.bookID
    LEFT JOIN book_category bc ON bk.categoryID = bc.categoryID
    LEFT JOIN fines f ON b.borrowID = f.borrowID
    LEFT JOIN borrowing_rules br ON u.user_type = br.user_type
    WHERE b.borrowID = ?
";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $borrowID);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    echo json_encode(['success' => false, 'message' => 'Borrow record not found']);
    exit;
}

$data = $result->fetch_assoc();

// Calculate days info
$days_borrowed = floor((strtotime($data['return_date'] ?? date('Y-m-d')) - strtotime($data['borrow_date'])) / 86400);
$days_overdue = $data['return_date'] ? 0 : max(0, floor((strtotime(date('Y-m-d')) - strtotime($data['due_date'])) / 86400));

$html = '
<div style="display: grid; gap: 1.5rem;">
    <!-- Borrower Information -->
    <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 1.5rem; border-radius: 12px;">
        <h3 style="margin: 0 0 1rem 0; font-size: 1.1rem; display: flex; align-items: center; gap: 0.5rem;">
            <i class="fas fa-user-circle"></i> Borrower Information
        </h3>
        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem;">
            <div>
                <div style="font-size: 0.85rem; opacity: 0.9;">Name</div>
                <div style="font-weight: 600; font-size: 1.05rem;">' . htmlspecialchars($data['first_name'] . ' ' . $data['last_name']) . '</div>
            </div>
            <div>
                <div style="font-size: 0.85rem; opacity: 0.9;">ID Number</div>
                <div style="font-weight: 600; font-size: 1.05rem;">' . htmlspecialchars($data['id_number']) . '</div>
            </div>
            <div>
                <div style="font-size: 0.85rem; opacity: 0.9;">User Type</div>
                <div style="font-weight: 600; font-size: 1.05rem;">' . ucfirst($data['user_type']) . '</div>
            </div>
            <div>
                <div style="font-size: 0.85rem; opacity: 0.9;">' . ($data['user_type'] == 'student' ? 'Class' : 'Department') . '</div>
                <div style="font-weight: 600; font-size: 1.05rem;">' . htmlspecialchars($data['class_dept'] ?? 'N/A') . '</div>
            </div>
        </div>
    </div>

    <!-- Book Information -->
    <div style="background: #f8fafc; padding: 1.5rem; border-radius: 12px; border: 2px solid #e2e8f0;">
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
        </div>
    </div>

    <!-- Borrowing Timeline -->
    <div style="background: white; padding: 1.5rem; border-radius: 12px; border: 2px solid #e2e8f0;">
        <h3 style="margin: 0 0 1rem 0; font-size: 1.1rem; color: #1e293b; display: flex; align-items: center; gap: 0.5rem;">
            <i class="fas fa-calendar-alt"></i> Borrowing Timeline
        </h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
            <div style="text-align: center; padding: 1rem; background: #eff6ff; border-radius: 8px;">
                <div style="font-size: 0.85rem; color: #1e40af; margin-bottom: 0.5rem;">Borrow Date</div>
                <div style="font-weight: 700; font-size: 1.1rem; color: #1e3a8a;">' . date('d M Y', strtotime($data['borrow_date'])) . '</div>
            </div>
            <div style="text-align: center; padding: 1rem; background: ' . ($days_overdue > 0 ? '#fee2e2' : '#fef3c7') . '; border-radius: 8px;">
                <div style="font-size: 0.85rem; color: ' . ($days_overdue > 0 ? '#991b1b' : '#92400e') . '; margin-bottom: 0.5rem;">Due Date</div>
                <div style="font-weight: 700; font-size: 1.1rem; color: ' . ($days_overdue > 0 ? '#dc2626' : '#b45309') . ';">' . date('d M Y', strtotime($data['due_date'])) . '</div>
            </div>
            <div style="text-align: center; padding: 1rem; background: ' . ($data['return_date'] ? '#d1fae5' : '#f3f4f6') . '; border-radius: 8px;">
                <div style="font-size: 0.85rem; color: ' . ($data['return_date'] ? '#065f46' : '#6b7280') . '; margin-bottom: 0.5rem;">Return Date</div>
                <div style="font-weight: 700; font-size: 1.1rem; color: ' . ($data['return_date'] ? '#10b981' : '#9ca3af') . ';">' . ($data['return_date'] ? date('d M Y', strtotime($data['return_date'])) : 'Not returned') . '</div>
            </div>
        </div>
        
        <div style="margin-top: 1rem; padding: 1rem; background: #fafafa; border-radius: 8px; display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; text-align: center;">
            <div>
                <div style="font-size: 0.85rem; color: #64748b;">Days Borrowed</div>
                <div style="font-weight: 700; font-size: 1.2rem; color: #3b82f6;">' . $days_borrowed . '</div>
            </div>
            <div>
                <div style="font-size: 0.85rem; color: #64748b;">Renewal Count</div>
                <div style="font-weight: 700; font-size: 1.2rem; color: #8b5cf6;">' . ($data['renewal_count'] ?? 0) . '</div>
            </div>
            <div>
                <div style="font-size: 0.85rem; color: #64748b;">Days Overdue</div>
                <div style="font-weight: 700; font-size: 1.2rem; color: ' . ($days_overdue > 0 ? '#ef4444' : '#10b981') . ';">' . $days_overdue . '</div>
            </div>
        </div>
    </div>

    <!-- Fine Information -->
    ' . ($data['fineID'] ? '
    <div style="background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%); padding: 1.5rem; border-radius: 12px; border: 2px solid #fca5a5;">
        <h3 style="margin: 0 0 1rem 0; font-size: 1.1rem; color: #991b1b; display: flex; align-items: center; gap: 0.5rem;">
            <i class="fas fa-exclamation-triangle"></i> Fine Information
        </h3>
        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem;">
            <div style="text-align: center; padding: 1rem; background: white; border-radius: 8px;">
                <div style="font-size: 0.85rem; color: #991b1b;">Fine Amount</div>
                <div style="font-weight: 700; font-size: 1.3rem; color: #dc2626;">RM ' . number_format($data['fine_amount'], 2) . '</div>
            </div>
            <div style="text-align: center; padding: 1rem; background: white; border-radius: 8px;">
                <div style="font-size: 0.85rem; color: #991b1b;">Balance Due</div>
                <div style="font-weight: 700; font-size: 1.3rem; color: #dc2626;">RM ' . number_format($data['balance_due'], 2) . '</div>
            </div>
        </div>
        <div style="margin-top: 1rem; padding: 1rem; background: white; border-radius: 8px;">
            <span style="display: inline-block; padding: 0.5rem 1rem; background: ' . ($data['payment_status'] == 'paid_cash' ? '#d1fae5' : '#fee2e2') . '; color: ' . ($data['payment_status'] == 'paid_cash' ? '#065f46' : '#991b1b') . '; border-radius: 20px; font-weight: 600;">
                Status: ' . ucfirst(str_replace('_', ' ', $data['payment_status'])) . '
            </span>
        </div>
    </div>
    ' : '') . '

    <!-- Notes -->
    ' . ($data['notes'] ? '
    <div style="background: #fffbeb; padding: 1.5rem; border-radius: 12px; border: 2px solid #fde047;">
        <h3 style="margin: 0 0 0.5rem 0; font-size: 1.1rem; color: #92400e; display: flex; align-items: center; gap: 0.5rem;">
            <i class="fas fa-sticky-note"></i> Notes
        </h3>
        <p style="margin: 0; color: #78350f; line-height: 1.6;">' . nl2br(htmlspecialchars($data['notes'])) . '</p>
    </div>
    ' : '') . '
</div>
';

echo json_encode(['success' => true, 'html' => $html]);
?>