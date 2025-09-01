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
        case 'fulfill':
            // Validate required fields
            if (!isset($input['reservationID']) || !isset($input['bookID'])) {
                throw new Exception('Missing required fields');
            }
            
            $reservationID = intval($input['reservationID']);
            $bookID = intval($input['bookID']);
            $borrowPeriod = intval($input['borrowPeriod'] ?? 14);
            
            // Get reservation details
            $res_query = "SELECT r.*, u.user_type 
                         FROM reservation r
                         JOIN user u ON r.userID = u.userID
                         WHERE r.reservationID = ? AND r.reservation_status = 'active'";
            
            $stmt = $conn->prepare($res_query);
            $stmt->bind_param("i", $reservationID);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows == 0) {
                throw new Exception('Reservation not found or not active');
            }
            
            $reservation = $result->fetch_assoc();
            
            // Check if book is available
            $book_query = "SELECT bookStatus FROM book WHERE bookID = ?";
            $stmt = $conn->prepare($book_query);
            $stmt->bind_param("i", $bookID);
            $stmt->execute();
            $book_result = $stmt->get_result();
            $book = $book_result->fetch_assoc();
            
            if ($book['bookStatus'] != 'available' && $book['bookStatus'] != 'reserved') {
                throw new Exception('Book is not available for borrowing');
            }
            
            // Create borrow record
            $borrow_date = date('Y-m-d');
            $due_date = date('Y-m-d', strtotime("+$borrowPeriod days"));
            
            $insert_borrow = "INSERT INTO borrow (userID, bookID, borrow_date, due_date, borrow_status, checkout_method) 
                             VALUES (?, ?, ?, ?, 'borrowed', 'librarian')";
            
            $stmt = $conn->prepare($insert_borrow);
            $stmt->bind_param("iiss", $reservation['userID'], $bookID, $borrow_date, $due_date);
            
            if (!$stmt->execute()) {
                throw new Exception('Failed to create borrow record');
            }
            
            $borrowID = $conn->insert_id;
            
            // Update book status to borrowed
            $update_book = "UPDATE book SET bookStatus = 'borrowed' WHERE bookID = ?";
            $stmt = $conn->prepare($update_book);
            $stmt->bind_param("i", $bookID);
            
            if (!$stmt->execute()) {
                throw new Exception('Failed to update book status');
            }
            
            // Update reservation status to fulfilled
            $update_reservation = "UPDATE reservation 
                                  SET reservation_status = 'fulfilled',
                                      updated_date = NOW()
                                  WHERE reservationID = ?";
            
            $stmt = $conn->prepare($update_reservation);
            $stmt->bind_param("i", $reservationID);
            
            if (!$stmt->execute()) {
                throw new Exception('Failed to update reservation status');
            }
            
            // Update queue positions for other reservations of the same book
            $update_queue = "UPDATE reservation 
                            SET queue_position = queue_position - 1 
                            WHERE bookID = ? 
                            AND reservation_status = 'active' 
                            AND queue_position > ?";
            
            $stmt = $conn->prepare($update_queue);
            $stmt->bind_param("ii", $bookID, $reservation['queue_position']);
            $stmt->execute();
            
            // Create notification for the user
            $notification_message = "Your reservation has been fulfilled. The book has been issued to you with due date: " . date('d M Y', strtotime($due_date));
            
            $insert_notification = "INSERT INTO notifications (userID, notification_type, title, message, related_borrowID, related_reservationID) 
                                   VALUES (?, 'reservation_fulfilled', 'Book Issued', ?, ?, ?)";
            
            $stmt = $conn->prepare($insert_notification);
            $stmt->bind_param("isii", $reservation['userID'], $notification_message, $borrowID, $reservationID);
            $stmt->execute();
            
            // Log the activity
            $librarian_id = $_SESSION['userID'] ?? 0;
            $log_query = "INSERT INTO user_activity_log (userID, action, description, ip_address) 
                         VALUES (?, 'reservation_fulfill', ?, ?)";
            
            $description = "Fulfilled reservation ID: $reservationID, Created borrow ID: $borrowID";
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
            
            $stmt = $conn->prepare($log_query);
            $stmt->bind_param("iss", $librarian_id, $description, $ip_address);
            $stmt->execute();
            
            // Commit transaction
            $conn->commit();
            
            $response = [
                'success' => true,
                'message' => 'Reservation fulfilled successfully',
                'data' => [
                    'borrowID' => $borrowID,
                    'due_date' => $due_date
                ]
            ];
            
            break;
            
        case 'cancel':
            // Validate required fields
            if (!isset($input['reservationID'])) {
                throw new Exception('Missing reservation ID');
            }
            
            $reservationID = intval($input['reservationID']);
            $cancellationReason = $input['cancellationReason'] ?? 'Cancelled by librarian';
            
            // Get reservation details
            $res_query = "SELECT * FROM reservation WHERE reservationID = ? AND reservation_status = 'active'";
            $stmt = $conn->prepare($res_query);
            $stmt->bind_param("i", $reservationID);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows == 0) {
                throw new Exception('Reservation not found or not active');
            }
            
            $reservation = $result->fetch_assoc();
            
            // Update reservation status
            $update_reservation = "UPDATE reservation 
                                  SET reservation_status = 'cancelled',
                                      cancellation_reason = ?,
                                      updated_date = NOW()
                                  WHERE reservationID = ?";
            
            $stmt = $conn->prepare($update_reservation);
            $stmt->bind_param("si", $cancellationReason, $reservationID);
            
            if (!$stmt->execute()) {
                throw new Exception('Failed to cancel reservation');
            }
            
            // Update queue positions for other reservations
            $update_queue = "UPDATE reservation 
                            SET queue_position = queue_position - 1 
                            WHERE bookID = ? 
                            AND reservation_status = 'active' 
                            AND queue_position > ?";
            
            $stmt = $conn->prepare($update_queue);
            $stmt->bind_param("ii", $reservation['bookID'], $reservation['queue_position']);
            $stmt->execute();
            
            // Check if book should be made available
            $check_other_reservations = "SELECT COUNT(*) as count FROM reservation 
                                        WHERE bookID = ? AND reservation_status = 'active'";
            
            $stmt = $conn->prepare($check_other_reservations);
            $stmt->bind_param("i", $reservation['bookID']);
            $stmt->execute();
            $count_result = $stmt->get_result();
            $count = $count_result->fetch_assoc()['count'];
            
            // If no other active reservations and book is reserved, make it available
            if ($count == 0) {
                $check_book = "SELECT bookStatus FROM book WHERE bookID = ?";
                $stmt = $conn->prepare($check_book);
                $stmt->bind_param("i", $reservation['bookID']);
                $stmt->execute();
                $book_result = $stmt->get_result();
                $book_status = $book_result->fetch_assoc()['bookStatus'];
                
                if ($book_status == 'reserved') {
                    $update_book = "UPDATE book SET bookStatus = 'available' WHERE bookID = ?";
                    $stmt = $conn->prepare($update_book);
                    $stmt->bind_param("i", $reservation['bookID']);
                    $stmt->execute();
                }
            } else {
                // Notify next person in queue
                $next_reservation = "SELECT r.*, u.email, u.first_name 
                                   FROM reservation r
                                   JOIN user u ON r.userID = u.userID
                                   WHERE r.bookID = ? 
                                   AND r.reservation_status = 'active'
                                   ORDER BY r.queue_position ASC
                                   LIMIT 1";
                
                $stmt = $conn->prepare($next_reservation);
                $stmt->bind_param("i", $reservation['bookID']);
                $stmt->execute();
                $next_result = $stmt->get_result();
                
                if ($next_result->num_rows > 0) {
                    $next = $next_result->fetch_assoc();
                    
                    $notification_message = "You are now first in queue for your reserved book. It will be available soon.";
                    
                    $insert_notification = "INSERT INTO notifications (userID, notification_type, title, message, related_reservationID) 
                                           VALUES (?, 'reservation_queue_update', 'Reservation Queue Updated', ?, ?)";
                    
                    $stmt = $conn->prepare($insert_notification);
                    $stmt->bind_param("isi", $next['userID'], $notification_message, $next['reservationID']);
                    $stmt->execute();
                }
            }
            
            // Create notification for the user whose reservation was cancelled
            $cancel_notification = "Your reservation has been cancelled. Reason: " . $cancellationReason;
            
            $insert_cancel_notification = "INSERT INTO notifications (userID, notification_type, title, message, related_reservationID) 
                                          VALUES (?, 'reservation_cancelled', 'Reservation Cancelled', ?, ?)";
            
            $stmt = $conn->prepare($insert_cancel_notification);
            $stmt->bind_param("isi", $reservation['userID'], $cancel_notification, $reservationID);
            $stmt->execute();
            
            // Log the activity
            $librarian_id = $_SESSION['userID'] ?? 0;
            $log_query = "INSERT INTO user_activity_log (userID, action, description, ip_address) 
                         VALUES (?, 'reservation_cancel', ?, ?)";
            
            $description = "Cancelled reservation ID: $reservationID. Reason: $cancellationReason";
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
            
            $stmt = $conn->prepare($log_query);
            $stmt->bind_param("iss", $librarian_id, $description, $ip_address);
            $stmt->execute();
            
            // Commit transaction
            $conn->commit();
            
            $response = [
                'success' => true,
                'message' => 'Reservation cancelled successfully'
            ];
            
            break;
            
        case 'update_queue':
            // Handle queue position updates if needed
            if (!isset($input['reservationID']) || !isset($input['newPosition'])) {
                throw new Exception('Missing required fields');
            }
            
            $reservationID = intval($input['reservationID']);
            $newPosition = intval($input['newPosition']);
            
            // Get current reservation details
            $res_query = "SELECT * FROM reservation WHERE reservationID = ?";
            $stmt = $conn->prepare($res_query);
            $stmt->bind_param("i", $reservationID);
            $stmt->execute();
            $result = $stmt->get_result();
            $reservation = $result->fetch_assoc();
            
            $oldPosition = $reservation['queue_position'];
            $bookID = $reservation['bookID'];
            
            if ($newPosition != $oldPosition) {
                // Update other reservations' positions
                if ($newPosition < $oldPosition) {
                    // Moving up in queue
                    $update_others = "UPDATE reservation 
                                     SET queue_position = queue_position + 1 
                                     WHERE bookID = ? 
                                     AND reservation_status = 'active'
                                     AND queue_position >= ? 
                                     AND queue_position < ?
                                     AND reservationID != ?";
                    
                    $stmt = $conn->prepare($update_others);
                    $stmt->bind_param("iiii", $bookID, $newPosition, $oldPosition, $reservationID);
                    $stmt->execute();
                } else {
                    // Moving down in queue
                    $update_others = "UPDATE reservation 
                                     SET queue_position = queue_position - 1 
                                     WHERE bookID = ? 
                                     AND reservation_status = 'active'
                                     AND queue_position > ? 
                                     AND queue_position <= ?
                                     AND reservationID != ?";
                    
                    $stmt = $conn->prepare($update_others);
                    $stmt->bind_param("iiii", $bookID, $oldPosition, $newPosition, $reservationID);
                    $stmt->execute();
                }
                
                // Update the reservation's position
                $update_position = "UPDATE reservation 
                                   SET queue_position = ?,
                                       updated_date = NOW()
                                   WHERE reservationID = ?";
                
                $stmt = $conn->prepare($update_position);
                $stmt->bind_param("ii", $newPosition, $reservationID);
                $stmt->execute();
            }
            
            // Commit transaction
            $conn->commit();
            
            $response = [
                'success' => true,
                'message' => 'Queue position updated successfully'
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
    error_log("Reservation Processing Error: " . $e->getMessage());
}

// Close connection
$conn->close();

// Return JSON response
echo json_encode($response);
?>