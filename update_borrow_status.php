<?php
session_start();
require_once 'dbconnect.php';
require_once 'auth_helper.php';
checkPageAccess(); // This prevents back button access after logout

// Check if user is logged in and is a librarian
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
    // Begin transaction
    $conn->autocommit(FALSE);
    
    switch ($input['action']) {
        case 'return':
            // Validate required fields
            if (!isset($input['borrowID']) || !isset($input['bookID'])) {
                throw new Exception('Missing required fields');
            }
            
            $borrowID = intval($input['borrowID']);
            $bookID = intval($input['bookID']);
            
            // Check if borrow record exists and is currently borrowed
            $check_query = "SELECT b.*, u.user_type, br.overdue_fine_per_day 
                           FROM borrow b 
                           LEFT JOIN user u ON b.userID = u.userID
                           LEFT JOIN borrowing_rules br ON u.user_type = br.user_type
                           WHERE b.borrowID = ? AND b.borrow_status = 'borrowed'";
            
            $stmt = $conn->prepare($check_query);
            $stmt->bind_param("i", $borrowID);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows == 0) {
                throw new Exception('Borrow record not found or already returned');
            }
            
            $borrow_data = $result->fetch_assoc();
            
            // Calculate overdue days and fine if applicable
            $return_date = date('Y-m-d');
            $due_date = $borrow_data['due_date'];
            $days_overdue = 0;
            $fine_amount = 0;
            
            if (strtotime($return_date) > strtotime($due_date)) {
                $days_overdue = floor((strtotime($return_date) - strtotime($due_date)) / (60 * 60 * 24));
                $fine_per_day = $borrow_data['overdue_fine_per_day'] ?? 0.50;
                $fine_amount = $days_overdue * $fine_per_day;
            }
            
            // Update borrow record
            $update_borrow_query = "UPDATE borrow 
                                   SET borrow_status = 'returned',
                                       return_date = ?,
                                       days_overdue = ?,
                                       fine_amount = ?,
                                       return_method = 'librarian',
                                       updated_date = NOW()
                                   WHERE borrowID = ?";
            
            $stmt = $conn->prepare($update_borrow_query);
            $stmt->bind_param("sidi", $return_date, $days_overdue, $fine_amount, $borrowID);
            
            if (!$stmt->execute()) {
                throw new Exception('Failed to update borrow record');
            }
            
            // Update book status to available
            $update_book_query = "UPDATE book 
                                 SET bookStatus = 'available',
                                     updated_date = NOW()
                                 WHERE bookID = ?";
            
            $stmt = $conn->prepare($update_book_query);
            $stmt->bind_param("i", $bookID);
            
            if (!$stmt->execute()) {
                throw new Exception('Failed to update book status');
            }
            
            // If there's a fine, create or update fine record
            if ($fine_amount > 0) {
                // Check if fine record already exists
                $check_fine_query = "SELECT fineID FROM fines WHERE borrowID = ?";
                $stmt = $conn->prepare($check_fine_query);
                $stmt->bind_param("i", $borrowID);
                $stmt->execute();
                $fine_result = $stmt->get_result();
                
                if ($fine_result->num_rows > 0) {
                    // Update existing fine record
                    $update_fine_query = "UPDATE fines 
                                         SET fine_amount = ?,
                                             balance_due = ?,
                                             fine_reason = 'Overdue book return',
                                             updated_date = NOW()
                                         WHERE borrowID = ?";
                    
                    $stmt = $conn->prepare($update_fine_query);
                    $stmt->bind_param("ddi", $fine_amount, $fine_amount, $borrowID);
                    $stmt->execute();
                } else {
                    // Create new fine record
                    $insert_fine_query = "INSERT INTO fines (borrowID, userID, fine_amount, fine_reason, fine_date, payment_status, balance_due) 
                                         VALUES (?, ?, ?, 'Overdue book return', CURDATE(), 'unpaid', ?)";
                    
                    $stmt = $conn->prepare($insert_fine_query);
                    $stmt->bind_param("iidd", $borrowID, $borrow_data['userID'], $fine_amount, $fine_amount);
                    $stmt->execute();
                }
            }
            
            // Check if there are any reservations for this book
            $check_reservation_query = "SELECT r.*, u.email, u.first_name, u.last_name 
                                       FROM reservation r
                                       JOIN user u ON r.userID = u.userID
                                       WHERE r.bookID = ? 
                                       AND r.reservation_status = 'active'
                                       ORDER BY r.queue_position ASC
                                       LIMIT 1";
            
            $stmt = $conn->prepare($check_reservation_query);
            $stmt->bind_param("i", $bookID);
            $stmt->execute();
            $reservation_result = $stmt->get_result();
            
            if ($reservation_result->num_rows > 0) {
                $reservation = $reservation_result->fetch_assoc();
                
                // Update book status to reserved
                $update_book_reserved = "UPDATE book SET bookStatus = 'reserved' WHERE bookID = ?";
                $stmt = $conn->prepare($update_book_reserved);
                $stmt->bind_param("i", $bookID);
                $stmt->execute();
                
                // Create notification for the user who reserved the book
                $notification_message = "The book you reserved is now available for pickup. Please collect it within 3 days.";
                $insert_notification = "INSERT INTO notifications (userID, notification_type, title, message, related_reservationID) 
                                       VALUES (?, 'reservation_ready', 'Reserved Book Available', ?, ?)";
                
                $stmt = $conn->prepare($insert_notification);
                $stmt->bind_param("isi", $reservation['userID'], $notification_message, $reservation['reservationID']);
                $stmt->execute();
                
                // Update reservation with pickup deadline
                $pickup_deadline = date('Y-m-d', strtotime('+3 days'));
                $update_reservation = "UPDATE reservation 
                                      SET self_pickup_deadline = ?,
                                          pickup_notification_date = CURDATE(),
                                          notification_sent = TRUE
                                      WHERE reservationID = ?";
                
                $stmt = $conn->prepare($update_reservation);
                $stmt->bind_param("si", $pickup_deadline, $reservation['reservationID']);
                $stmt->execute();
            }
            
            // Log the activity
            $librarian_id = $_SESSION['userID'] ?? 0;
            $log_query = "INSERT INTO user_activity_log (userID, action, description, ip_address) 
                         VALUES (?, 'book_return', ?, ?)";
            
            $description = "Marked book (ID: $bookID) as returned for borrow ID: $borrowID";
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
            
            $stmt = $conn->prepare($log_query);
            $stmt->bind_param("iss", $librarian_id, $description, $ip_address);
            $stmt->execute();
            
            // Commit transaction
            $conn->commit();
            
            $response = [
                'success' => true,
                'message' => 'Book successfully marked as returned',
                'data' => [
                    'borrowID' => $borrowID,
                    'return_date' => $return_date,
                    'days_overdue' => $days_overdue,
                    'fine_amount' => $fine_amount,
                    'has_reservation' => ($reservation_result->num_rows > 0)
                ]
            ];
            
            break;
            
        case 'renew':
            // Handle renewal logic (if needed)
            if (!isset($input['borrowID'])) {
                throw new Exception('Missing borrow ID');
            }
            
            $borrowID = intval($input['borrowID']);
            
            // Check if renewal is allowed
            $check_query = "SELECT b.*, u.user_type, br.max_renewals_allowed, br.borrow_period_days
                           FROM borrow b
                           JOIN user u ON b.userID = u.userID
                           LEFT JOIN borrowing_rules br ON u.user_type = br.user_type
                           WHERE b.borrowID = ? AND b.borrow_status = 'borrowed'";
            
            $stmt = $conn->prepare($check_query);
            $stmt->bind_param("i", $borrowID);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows == 0) {
                throw new Exception('Borrow record not found');
            }
            
            $borrow_data = $result->fetch_assoc();
            
            // Check renewal count
            if ($borrow_data['renewal_count'] >= $borrow_data['max_renewals_allowed']) {
                throw new Exception('Maximum renewals reached');
            }
            
            // Check if book has reservations
            $check_reservation = "SELECT COUNT(*) as count FROM reservation 
                                 WHERE bookID = ? AND reservation_status = 'active'";
            
            $stmt = $conn->prepare($check_reservation);
            $stmt->bind_param("i", $borrow_data['bookID']);
            $stmt->execute();
            $res_result = $stmt->get_result();
            $res_count = $res_result->fetch_assoc()['count'];
            
            if ($res_count > 0) {
                throw new Exception('Cannot renew - book has pending reservations');
            }
            
            // Calculate new due date
            $old_due_date = $borrow_data['due_date'];
            $borrow_period = $borrow_data['borrow_period_days'] ?? 14;
            $new_due_date = date('Y-m-d', strtotime($old_due_date . " + $borrow_period days"));
            $new_renewal_count = $borrow_data['renewal_count'] + 1;
            
            // Update borrow record
            $update_query = "UPDATE borrow 
                           SET due_date = ?,
                               renewal_count = ?,
                               updated_date = NOW()
                           WHERE borrowID = ?";
            
            $stmt = $conn->prepare($update_query);
            $stmt->bind_param("sii", $new_due_date, $new_renewal_count, $borrowID);
            
            if (!$stmt->execute()) {
                throw new Exception('Failed to renew book');
            }
            
            // Insert renewal record
            $insert_renewal = "INSERT INTO renewals (borrowID, userID, renewal_date, old_due_date, new_due_date, renewal_count, renewal_method) 
                              VALUES (?, ?, CURDATE(), ?, ?, ?, 'librarian')";
            
            $stmt = $conn->prepare($insert_renewal);
            $stmt->bind_param("iissi", $borrowID, $borrow_data['userID'], $old_due_date, $new_due_date, $new_renewal_count);
            $stmt->execute();
            
            // Commit transaction
            $conn->commit();
            
            $response = [
                'success' => true,
                'message' => 'Book successfully renewed',
                'data' => [
                    'borrowID' => $borrowID,
                    'new_due_date' => $new_due_date,
                    'renewal_count' => $new_renewal_count
                ]
            ];
            
            break;
            
        default:
            throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    // Rollback transaction on error
    $conn->rollback();
    
    $response = [
        'success' => false,
        'message' => $e->getMessage()
    ];
    
    // Log error
    error_log("Circulation Control Error: " . $e->getMessage());
}

// Close connection
$conn->close();

// Return JSON response
echo json_encode($response);
?>