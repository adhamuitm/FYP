<?php
session_start();
require_once 'dbconnect.php';
require_once 'auth_helper.php';

header('Content-Type: application/json');

// Check authentication
try {
    checkPageAccess();
    requireRole('librarian');
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode([
        'success' => false, 
        'message' => 'Authentication error: ' . $e->getMessage()
    ]);
    exit;
}

// Validate reservationID parameter
if (!isset($_GET['reservationID'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'message' => 'Missing reservationID parameter'
    ]);
    exit;
}

$reservationID = intval($_GET['reservationID']);

if ($reservationID <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'message' => 'Invalid reservationID parameter'
    ]);
    exit;
}

try {
    // Query reservation details
    $query = "
        SELECT 
            r.*,
            u.first_name,
            u.last_name,
            u.email,
            u.phone_number,
            u.user_type,
            u.login_id,
            COALESCE(s.student_id_number, st.staff_id_number, u.login_id) AS id_number,
            bk.bookTitle,
            bk.bookAuthor,
            bk.bookPublisher,
            bk.book_ISBN,
            bk.bookBarcode,
            bk.shelf_location,
            bc.categoryName,
            CASE 
                WHEN r.reservation_status = 'active' AND r.expiry_date < CURDATE() THEN 'expired'
                ELSE r.reservation_status
            END AS display_status,
            DATEDIFF(r.expiry_date, CURDATE()) AS days_until_expiry
        FROM reservation r
        LEFT JOIN user u ON r.userID = u.userID
        LEFT JOIN student s ON u.userID = s.userID AND u.user_type = 'student'
        LEFT JOIN staff st ON u.userID = st.userID AND u.user_type = 'staff'
        LEFT JOIN book bk ON r.bookID = bk.bookID
        LEFT JOIN book_category bc ON bk.categoryID = bc.categoryID
        WHERE r.reservationID = ?
    ";
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception('Database prepare error: ' . $conn->error);
    }
    
    $stmt->bind_param("i", $reservationID);
    
    if (!$stmt->execute()) {
        throw new Exception('Database execution error: ' . $stmt->error);
    }
    
    $result = $stmt->get_result();
    
    if ($result->num_rows == 0) {
        http_response_code(404);
        echo json_encode([
            'success' => false, 
            'message' => 'Reservation record not found'
        ]);
        exit;
    }
    
    $data = $result->fetch_assoc();
    
    // Build HTML content
    $html = '
    <div style="display: grid; gap: 1.5rem; font-family: Inter, sans-serif;">
        <!-- Reserver Information -->
        <div style="border: 1px solid #e2e8f0; border-radius: 8px; padding: 1.5rem; background: #f8fafc;">
            <h4 style="color: #1e3a8a; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;">
                <i class="fas fa-user" style="color: #3b82f6;"></i> Reserver Information
            </h4>
            <div style="display: grid; gap: 0.75rem;">
                <div style="display: grid; grid-template-columns: 150px 1fr; gap: 1rem;">
                    <span style="font-weight: 500; color: #64748b;">Name:</span>
                    <span style="color: #1e293b;">' . htmlspecialchars($data['first_name'] . ' ' . $data['last_name']) . '</span>
                </div>
                <div style="display: grid; grid-template-columns: 150px 1fr; gap: 1rem;">
                    <span style="font-weight: 500; color: #64748b;">ID Number:</span>
                    <span style="color: #1e293b; font-family: monospace; background: #e2e8f0; padding: 2px 6px; border-radius: 4px;">' . htmlspecialchars($data['id_number']) . '</span>
                </div>
                <div style="display: grid; grid-template-columns: 150px 1fr; gap: 1rem;">
                    <span style="font-weight: 500; color: #64748b;">Type:</span>
                    <span style="color: #1e293b; text-transform: capitalize;">' . htmlspecialchars($data['user_type']) . '</span>
                </div>
                <div style="display: grid; grid-template-columns: 150px 1fr; gap: 1rem;">
                    <span style="font-weight: 500; color: #64748b;">Email:</span>
                    <span style="color: #1e293b;">' . htmlspecialchars($data['email'] ?? 'N/A') . '</span>
                </div>
            </div>
        </div>
        
        <!-- Book Information -->
        <div style="border: 1px solid #e2e8f0; border-radius: 8px; padding: 1.5rem; background: #f8fafc;">
            <h4 style="color: #1e3a8a; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;">
                <i class="fas fa-book" style="color: #3b82f6;"></i> Book Information
            </h4>
            <div style="display: grid; gap: 0.75rem;">
                <div style="display: grid; grid-template-columns: 150px 1fr; gap: 1rem;">
                    <span style="font-weight: 500; color: #64748b;">Title:</span>
                    <span style="color: #1e293b; font-weight: 500;">' . htmlspecialchars($data['bookTitle']) . '</span>
                </div>
                <div style="display: grid; grid-template-columns: 150px 1fr; gap: 1rem;">
                    <span style="font-weight: 500; color: #64748b;">Author:</span>
                    <span style="color: #1e293b;">' . htmlspecialchars($data['bookAuthor'] ?? 'N/A') . '</span>
                </div>
                <div style="display: grid; grid-template-columns: 150px 1fr; gap: 1rem;">
                    <span style="font-weight: 500; color: #64748b;">Category:</span>
                    <span style="color: #1e293b;">' . htmlspecialchars($data['categoryName'] ?? 'N/A') . '</span>
                </div>
                <div style="display: grid; grid-template-columns: 150px 1fr; gap: 1rem;">
                    <span style="font-weight: 500; color: #64748b;">Location:</span>
                    <span style="color: #1e293b;">' . htmlspecialchars($data['shelf_location'] ?? 'N/A') . '</span>
                </div>
            </div>
        </div>
        
        <!-- Reservation Details -->
        <div style="border: 1px solid #e2e8f0; border-radius: 8px; padding: 1.5rem; background: #f8fafc;">
            <h4 style="color: #1e3a8a; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;">
                <i class="fas fa-bookmark" style="color: #3b82f6;"></i> Reservation Details
            </h4>
            <div style="display: grid; gap: 0.75rem;">
                <div style="display: grid; grid-template-columns: 150px 1fr; gap: 1rem;">
                    <span style="font-weight: 500; color: #64748b;">Reservation ID:</span>
                    <span style="color: #1e293b; font-family: monospace; font-weight: 500;">#' . $data['reservationID'] . '</span>
                </div>
                <div style="display: grid; grid-template-columns: 150px 1fr; gap: 1rem;">
                    <span style="font-weight: 500; color: #64748b;">Reserved Date:</span>
                    <span style="color: #1e293b;">' . date('d M Y', strtotime($data['reservation_date'])) . '</span>
                </div>
                <div style="display: grid; grid-template-columns: 150px 1fr; gap: 1rem;">
                    <span style="font-weight: 500; color: #64748b;">Expiry Date:</span>
                    <span style="color: #1e293b;">' . date('d M Y', strtotime($data['expiry_date'])) . '</span>
                </div>';
    
    // Status display
    $status_color = '#64748b';
    $status_bg = '#f1f5f9';
    
    switch ($data['display_status']) {
        case 'active':
            $status_color = '#0ea5e9';
            $status_bg = '#e0f2fe';
            break;
        case 'fulfilled':
            $status_color = '#10b981';
            $status_bg = '#ecfdf5';
            break;
        case 'expired':
            $status_color = '#f59e0b';
            $status_bg = '#fffbeb';
            break;
        case 'cancelled':
            $status_color = '#ef4444';
            $status_bg = '#fef2f2';
            break;
    }
    
    $html .= '
                <div style="display: grid; grid-template-columns: 150px 1fr; gap: 1rem;">
                    <span style="font-weight: 500; color: #64748b;">Status:</span>
                    <span style="padding: 4px 12px; border-radius: 20px; background: ' . $status_bg . '; color: ' . $status_color . '; font-weight: 500; text-transform: capitalize; display: inline-block; width: fit-content;">' . $data['display_status'] . '</span>
                </div>';
    
    if ($data['queue_position']) {
        $html .= '
                <div style="display: grid; grid-template-columns: 150px 1fr; gap: 1rem;">
                    <span style="font-weight: 500; color: #64748b;">Queue Position:</span>
                    <span style="color: #1e293b; font-weight: 500;">#' . $data['queue_position'] . '</span>
                </div>';
    }
    
    if ($data['days_until_expiry'] >= 0 && $data['reservation_status'] == 'active') {
        $expiry_color = $data['days_until_expiry'] <= 3 ? '#ef4444' : '#64748b';
        $html .= '
                <div style="display: grid; grid-template-columns: 150px 1fr; gap: 1rem;">
                    <span style="font-weight: 500; color: #64748b;">Days Until Expiry:</span>
                    <span style="color: ' . $expiry_color . '; font-weight: 500;">' . $data['days_until_expiry'] . ' days</span>
                </div>';
    }
    
    if ($data['notification_sent']) {
        $html .= '
                <div style="display: grid; grid-template-columns: 150px 1fr; gap: 1rem;">
                    <span style="font-weight: 500; color: #64748b;">Notification:</span>
                    <span style="color: #10b981; font-weight: 500;"><i class="fas fa-check-circle"></i> Sent</span>
                </div>';
    }
    
    if ($data['self_pickup_deadline']) {
        $html .= '
                <div style="display: grid; grid-template-columns: 150px 1fr; gap: 1rem;">
                    <span style="font-weight: 500; color: #64748b;">Pickup Deadline:</span>
                    <span style="color: #ef4444; font-weight: 500;">' . date('d M Y H:i', strtotime($data['self_pickup_deadline'])) . '</span>
                </div>';
    }
    
    if ($data['cancellation_reason']) {
        $html .= '
                <div style="display: grid; grid-template-columns: 150px 1fr; gap: 1rem;">
                    <span style="font-weight: 500; color: #64748b;">Cancellation Reason:</span>
                    <span style="color: #ef4444; font-style: italic;">' . htmlspecialchars($data['cancellation_reason']) . '</span>
                </div>';
    }
    
    $html .= '
            </div>
        </div>
    </div>';
    
    echo json_encode([
        'success' => true,
        'html' => $html,
        'data' => $data
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}

if (isset($stmt)) {
    $stmt->close();
}

$conn->close();
?>