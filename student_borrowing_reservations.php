<?php
session_start();
require_once 'dbconnect.php';
require_once 'auth_helper.php';

// Check if user is logged in and is a student
checkPageAccess();
requireRole('student');

// Get student information
$student_name = getUserDisplayName();
$user_id = $_SESSION['user_id'];

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    switch ($_GET['action']) {
        case 'scan_book':
            handleScanBook($conn, $user_id);
            break;
        case 'borrow_book':
            handleBorrowBook($conn, $user_id);
            break;
        case 'reserve_book':
            handleReserveBook($conn, $user_id);
            break;
        case 'return_book':
            handleReturnBook($conn, $user_id);
            break;
        case 'get_borrowed_books':
            getBorrowedBooks($conn, $user_id);
            break;
        case 'remove_from_cart':
            handleRemoveFromCart();
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
    exit;
}

// Function to scan/search for a book
function handleScanBook($conn, $user_id) {
    $barcode = trim($_POST['barcode'] ?? '');
    
    if (empty($barcode)) {
        echo json_encode(['success' => false, 'message' => 'Please enter a barcode or ISBN']);
        return;
    }
    
    try {
        // Search for book by barcode or ISBN
        $query = "
            SELECT 
                b.bookID,
                b.bookTitle,
                b.bookAuthor,
                b.bookPublisher,
                b.book_ISBN,
                b.bookBarcode,
                b.bookStatus,
                b.book_description,
                b.publication_year,
                b.language,
                b.shelf_location,
                b.book_image,
                b.book_image_mime,
                bc.categoryName,
                CASE 
                    WHEN b.bookStatus = 'available' THEN 'Available'
                    WHEN b.bookStatus = 'borrowed' THEN 'Borrowed'
                    WHEN b.bookStatus = 'reserved' THEN 'Reserved'
                    WHEN b.bookStatus = 'maintenance' THEN 'Under Maintenance'
                    ELSE 'Unavailable'
                END as status_display
            FROM book b
            LEFT JOIN book_category bc ON b.categoryID = bc.categoryID
            WHERE (b.bookBarcode = ? OR b.book_ISBN = ?) 
            AND b.bookStatus != 'disposed'
            LIMIT 1
        ";
        
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            throw new Exception("Database prepare error: " . $conn->error);
        }
        
        $stmt->bind_param("ss", $barcode, $barcode);
        if (!$stmt->execute()) {
            throw new Exception("Database execute error: " . $stmt->error);
        }
        
        $result = $stmt->get_result();
        
        if ($book = $result->fetch_assoc()) {
            // Convert image to base64 for JSON response
            if ($book['book_image'] && $book['book_image_mime']) {
                $book['book_image_base64'] = 'data:' . $book['book_image_mime'] . ';base64,' . base64_encode($book['book_image']);
            } else {
                $book['book_image_base64'] = null;
            }
            unset($book['book_image']); // Remove binary data
            
            // Check if user already has this book borrowed
            $check_borrowed = "SELECT borrowID FROM borrow WHERE userID = ? AND bookID = ? AND borrow_status = 'borrowed'";
            $stmt_check = $conn->prepare($check_borrowed);
            if (!$stmt_check) {
                throw new Exception("Database prepare error: " . $conn->error);
            }
            
            $stmt_check->bind_param("ii", $user_id, $book['bookID']);
            $stmt_check->execute();
            $borrowed_result = $stmt_check->get_result();
            
            $book['already_borrowed'] = $borrowed_result->num_rows > 0;
            
            // Check if user has this book reserved
            $check_reserved = "SELECT reservationID FROM reservation WHERE userID = ? AND bookID = ? AND reservation_status = 'waiting'";
            $stmt_check2 = $conn->prepare($check_reserved);
            if (!$stmt_check2) {
                throw new Exception("Database prepare error: " . $conn->error);
            }
            
            $stmt_check2->bind_param("ii", $user_id, $book['bookID']);
            $stmt_check2->execute();
            $reserved_result = $stmt_check2->get_result();
            
            $book['already_reserved'] = $reserved_result->num_rows > 0;
            
            echo json_encode(['success' => true, 'book' => $book]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Book not found. Please check the barcode/ISBN.']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error scanning book: ' . $e->getMessage()]);
        error_log("Scan error: " . $e->getMessage());
    }
}

// Function to borrow a book
function handleBorrowBook($conn, $user_id) {
    $book_id = intval($_POST['book_id'] ?? 0);
    
    if (!$book_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid book ID']);
        return;
    }
    
    try {
        // Start transaction
        if (!$conn->autocommit(FALSE)) {
            throw new Exception("Failed to start transaction: " . $conn->error);
        }
        
        // Check if user exists and is eligible
        $user_check = "SELECT user_type FROM user WHERE userID = ? AND account_status = 'active'";
        $stmt_user = $conn->prepare($user_check);
        if (!$stmt_user) {
            throw new Exception("Database prepare error: " . $conn->error);
        }
        
        $stmt_user->bind_param("i", $user_id);
        if (!$stmt_user->execute()) {
            throw new Exception("Database execute error: " . $stmt_user->error);
        }
        
        $user_result = $stmt_user->get_result();
        if ($user_result->num_rows === 0) {
            throw new Exception('User not found or inactive');
        }
        $user_data = $user_result->fetch_assoc();
        
        // Check current borrowed books count
        $count_query = "SELECT COUNT(*) as count FROM borrow WHERE userID = ? AND borrow_status = 'borrowed'";
        $stmt_count = $conn->prepare($count_query);
        if (!$stmt_count) {
            throw new Exception("Database prepare error: " . $conn->error);
        }
        
        $stmt_count->bind_param("i", $user_id);
        if (!$stmt_count->execute()) {
            throw new Exception("Database execute error: " . $stmt_count->error);
        }
        
        $count_result = $stmt_count->get_result()->fetch_assoc();
        
        if ($count_result['count'] >= 3) {
            throw new Exception('You have reached the maximum limit of 3 borrowed books');
        }
        
        // Check if book exists and is available
        $check_query = "SELECT bookID, bookTitle, bookStatus FROM book WHERE bookID = ?";
        $stmt_check = $conn->prepare($check_query);
        if (!$stmt_check) {
            throw new Exception("Database prepare error: " . $conn->error);
        }
        
        $stmt_check->bind_param("i", $book_id);
        if (!$stmt_check->execute()) {
            throw new Exception("Database execute error: " . $stmt_check->error);
        }
        
        $book_result = $stmt_check->get_result();
        if ($book_result->num_rows === 0) {
            throw new Exception('Book not found');
        }
        
        $book_data = $book_result->fetch_assoc();
        if ($book_data['bookStatus'] !== 'available') {
            throw new Exception('Book is not available for borrowing (Status: ' . $book_data['bookStatus'] . ')');
        }
        
        // Check if user already borrowed this book
        $existing_check = "SELECT borrowID FROM borrow WHERE userID = ? AND bookID = ? AND borrow_status = 'borrowed'";
        $stmt_existing = $conn->prepare($existing_check);
        if (!$stmt_existing) {
            throw new Exception("Database prepare error: " . $conn->error);
        }
        
        $stmt_existing->bind_param("ii", $user_id, $book_id);
        if (!$stmt_existing->execute()) {
            throw new Exception("Database execute error: " . $stmt_existing->error);
        }
        
        if ($stmt_existing->get_result()->num_rows > 0) {
            throw new Exception('You have already borrowed this book');
        }
        
        // Get borrowing rules for students
        $rules_query = "SELECT borrow_period_days FROM borrowing_rules WHERE user_type = 'student' LIMIT 1";
        $rules_result = $conn->query($rules_query);
        $borrow_period = 14; // Default
        if ($rules_result && $rule = $rules_result->fetch_assoc()) {
            $borrow_period = $rule['borrow_period_days'];
        }
        
        // Create borrow record - Fixed checkout_method to match enum values
        $borrow_date = date('Y-m-d');
        $due_date = date('Y-m-d', strtotime("+$borrow_period days"));
        
        $borrow_query = "INSERT INTO borrow (userID, bookID, borrow_date, due_date, borrow_status, checkout_method, created_date) VALUES (?, ?, ?, ?, 'borrowed', 'self_service', NOW())";
        $stmt_borrow = $conn->prepare($borrow_query);
        if (!$stmt_borrow) {
            throw new Exception("Database prepare error: " . $conn->error);
        }
        
        $stmt_borrow->bind_param("iiss", $user_id, $book_id, $borrow_date, $due_date);
        if (!$stmt_borrow->execute()) {
            throw new Exception("Failed to create borrow record: " . $stmt_borrow->error);
        }
        
        // Update book status
        $update_query = "UPDATE book SET bookStatus = 'borrowed', updated_date = NOW() WHERE bookID = ?";
        $stmt_update = $conn->prepare($update_query);
        if (!$stmt_update) {
            throw new Exception("Database prepare error: " . $conn->error);
        }
        
        $stmt_update->bind_param("i", $book_id);
        if (!$stmt_update->execute()) {
            throw new Exception("Failed to update book status: " . $stmt_update->error);
        }
        
        // Commit transaction
        if (!$conn->commit()) {
            throw new Exception("Failed to commit transaction: " . $conn->error);
        }
        
        // Restore autocommit
        $conn->autocommit(TRUE);
        
        echo json_encode([
            'success' => true, 
            'message' => 'Book borrowed successfully!', 
            'due_date' => $due_date,
            'book_title' => $book_data['bookTitle']
        ]);
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        $conn->autocommit(TRUE);
        
        $error_message = $e->getMessage();
        echo json_encode(['success' => false, 'message' => $error_message]);
        error_log("Borrow error for user $user_id, book $book_id: " . $error_message);
    }
}

// Function to reserve a book
function handleReserveBook($conn, $user_id) {
    $book_id = intval($_POST['book_id'] ?? 0);
    
    if (!$book_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid book ID']);
        return;
    }
    
    try {
        if (!$conn->autocommit(FALSE)) {
            throw new Exception("Failed to start transaction: " . $conn->error);
        }
        
        // Check if book is borrowed (can only reserve borrowed books)
        $check_query = "SELECT bookStatus, bookTitle FROM book WHERE bookID = ?";
        $stmt_check = $conn->prepare($check_query);
        if (!$stmt_check) {
            throw new Exception("Database prepare error: " . $conn->error);
        }
        
        $stmt_check->bind_param("i", $book_id);
        if (!$stmt_check->execute()) {
            throw new Exception("Database execute error: " . $stmt_check->error);
        }
        
        $book_result = $stmt_check->get_result();
        if ($book_result->num_rows === 0) {
            throw new Exception('Book not found');
        }
        
        $book_data = $book_result->fetch_assoc();
        if ($book_data['bookStatus'] !== 'borrowed') {
            throw new Exception('You can only reserve books that are currently borrowed');
        }
        
        // Check if user already has this book reserved
        $existing_query = "SELECT reservationID FROM reservation WHERE userID = ? AND bookID = ? AND reservation_status = 'waiting'";
        $stmt_existing = $conn->prepare($existing_query);
        if (!$stmt_existing) {
            throw new Exception("Database prepare error: " . $conn->error);
        }
        
        $stmt_existing->bind_param("ii", $user_id, $book_id);
        if (!$stmt_existing->execute()) {
            throw new Exception("Database execute error: " . $stmt_existing->error);
        }
        
        if ($stmt_existing->get_result()->num_rows > 0) {
            throw new Exception('You have already reserved this book');
        }
        
        // Get queue position
        $queue_query = "SELECT COALESCE(MAX(queue_position), 0) as max_pos FROM reservation WHERE bookID = ? AND reservation_status = 'waiting'";
        $stmt_queue = $conn->prepare($queue_query);
        if (!$stmt_queue) {
            throw new Exception("Database prepare error: " . $conn->error);
        }
        
        $stmt_queue->bind_param("i", $book_id);
        if (!$stmt_queue->execute()) {
            throw new Exception("Database execute error: " . $stmt_queue->error);
        }
        
        $queue_result = $stmt_queue->get_result()->fetch_assoc();
        $queue_position = ($queue_result['max_pos'] ?? 0) + 1;
        
        // Create reservation - using 'active' instead of 'waiting'
        $reservation_date = date('Y-m-d');
        $expiry_date = date('Y-m-d', strtotime('+30 days'));
        
        $reserve_query = "INSERT INTO reservation (userID, bookID, reservation_date, expiry_date, queue_position, reservation_status, created_date) VALUES (?, ?, ?, ?, ?, 'active', NOW())";
        $stmt_reserve = $conn->prepare($reserve_query);
        if (!$stmt_reserve) {
            throw new Exception("Database prepare error: " . $conn->error);
        }
        
        $stmt_reserve->bind_param("iissi", $user_id, $book_id, $reservation_date, $expiry_date, $queue_position);
        if (!$stmt_reserve->execute()) {
            throw new Exception("Failed to create reservation: " . $stmt_reserve->error);
        }
        
        if (!$conn->commit()) {
            throw new Exception("Failed to commit transaction: " . $conn->error);
        }
        
        $conn->autocommit(TRUE);
        
        echo json_encode([
            'success' => true, 
            'message' => 'Book reserved successfully! You are #' . $queue_position . ' in the queue.', 
            'queue_position' => $queue_position,
            'book_title' => $book_data['bookTitle']
        ]);
        
    } catch (Exception $e) {
        $conn->rollback();
        $conn->autocommit(TRUE);
        
        $error_message = $e->getMessage();
        echo json_encode(['success' => false, 'message' => $error_message]);
        error_log("Reserve error for user $user_id, book $book_id: " . $error_message);
    }
}

// Function to return a book
function handleReturnBook($conn, $user_id) {
    $book_id = intval($_POST['book_id'] ?? 0);
    
    if (!$book_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid book ID']);
        return;
    }
    
    try {
        if (!$conn->autocommit(FALSE)) {
            throw new Exception("Failed to start transaction: " . $conn->error);
        }
        
        // Check if user has this book borrowed
        $check_query = "SELECT b.borrowID, bk.bookTitle FROM borrow b JOIN book bk ON b.bookID = bk.bookID WHERE b.userID = ? AND b.bookID = ? AND b.borrow_status = 'borrowed'";
        $stmt_check = $conn->prepare($check_query);
        if (!$stmt_check) {
            throw new Exception("Database prepare error: " . $conn->error);
        }
        
        $stmt_check->bind_param("ii", $user_id, $book_id);
        if (!$stmt_check->execute()) {
            throw new Exception("Database execute error: " . $stmt_check->error);
        }
        
        $borrow_result = $stmt_check->get_result();
        if ($borrow_result->num_rows === 0) {
            throw new Exception('You have not borrowed this book');
        }
        
        $borrow_record = $borrow_result->fetch_assoc();
        
        // Update borrow record - Fixed return_method to match enum values
        $return_date = date('Y-m-d');
        $update_borrow = "UPDATE borrow SET return_date = ?, borrow_status = 'returned', return_method = 'self_service', updated_date = NOW() WHERE borrowID = ?";
        $stmt_update_borrow = $conn->prepare($update_borrow);
        if (!$stmt_update_borrow) {
            throw new Exception("Database prepare error: " . $conn->error);
        }
        
        $stmt_update_borrow->bind_param("si", $return_date, $borrow_record['borrowID']);
        if (!$stmt_update_borrow->execute()) {
            throw new Exception("Failed to update borrow record: " . $stmt_update_borrow->error);
        }
        
        // Check if there are any reservations for this book - Fixed reservation status check
        $reservation_query = "SELECT reservationID, userID FROM reservation WHERE bookID = ? AND reservation_status = 'waiting' ORDER BY queue_position ASC LIMIT 1";
        $stmt_reservation = $conn->prepare($reservation_query);
        if (!$stmt_reservation) {
            throw new Exception("Database prepare error: " . $conn->error);
        }
        
        $stmt_reservation->bind_param("i", $book_id);
        if (!$stmt_reservation->execute()) {
            throw new Exception("Database execute error: " . $stmt_reservation->error);
        }
        
        $reservation_result = $stmt_reservation->get_result();
        
        if ($reservation_result->num_rows > 0) {
            // Book is reserved, update status to reserved and notify next user
            $reservation = $reservation_result->fetch_assoc();
            
            $update_book = "UPDATE book SET bookStatus = 'reserved', updated_date = NOW() WHERE bookID = ?";
            $stmt_update_book = $conn->prepare($update_book);
            if (!$stmt_update_book) {
                throw new Exception("Database prepare error: " . $conn->error);
            }
            
            $stmt_update_book->bind_param("i", $book_id);
            if (!$stmt_update_book->execute()) {
                throw new Exception("Failed to update book status: " . $stmt_update_book->error);
            }
            
            // Update reservation status - Using 'fulfilled' instead of 'ready' to match schema enum
            $update_reservation = "UPDATE reservation SET reservation_status = 'fulfilled', self_pickup_deadline = DATE_ADD(NOW(), INTERVAL 48 HOUR), pickup_notification_date = NOW(), updated_date = NOW() WHERE reservationID = ?";
            $stmt_update_res = $conn->prepare($update_reservation);
            if (!$stmt_update_res) {
                throw new Exception("Database prepare error: " . $conn->error);
            }
            
            $stmt_update_res->bind_param("i", $reservation['reservationID']);
            if (!$stmt_update_res->execute()) {
                throw new Exception("Failed to update reservation: " . $stmt_update_res->error);
            }
            
            // Create notification
            $notification_query = "INSERT INTO notifications (userID, notification_type, title, message, related_reservationID, sent_date) VALUES (?, 'reservation_ready', 'Book Ready for Pickup', 'Your reserved book is now ready for pickup. Please collect within 48 hours.', ?, NOW())";
            $stmt_notification = $conn->prepare($notification_query);
            if ($stmt_notification) {
                $stmt_notification->bind_param("ii", $reservation['userID'], $reservation['reservationID']);
                $stmt_notification->execute();
            }
            
        } else {
            // No reservations, book becomes available
            $update_book = "UPDATE book SET bookStatus = 'available', updated_date = NOW() WHERE bookID = ?";
            $stmt_update_book = $conn->prepare($update_book);
            if (!$stmt_update_book) {
                throw new Exception("Database prepare error: " . $conn->error);
            }
            
            $stmt_update_book->bind_param("i", $book_id);
            if (!$stmt_update_book->execute()) {
                throw new Exception("Failed to update book status: " . $stmt_update_book->error);
            }
        }
        
        if (!$conn->commit()) {
            throw new Exception("Failed to commit transaction: " . $conn->error);
        }
        
        $conn->autocommit(TRUE);
        
        echo json_encode([
            'success' => true, 
            'message' => 'Book returned successfully!',
            'book_title' => $borrow_record['bookTitle']
        ]);
        
    } catch (Exception $e) {
        $conn->rollback();
        $conn->autocommit(TRUE);
        
        $error_message = $e->getMessage();
        echo json_encode(['success' => false, 'message' => $error_message]);
        error_log("Return error for user $user_id, book $book_id: " . $error_message);
    }
}

// Function to get borrowed books
function getBorrowedBooks($conn, $user_id) {
    try {
        $query = "
            SELECT 
                b.bookID,
                b.bookTitle,
                b.bookAuthor,
                b.book_image,
                b.book_image_mime,
                br.borrow_date,
                br.due_date,
                DATEDIFF(br.due_date, CURDATE()) as days_remaining
            FROM borrow br
            JOIN book b ON br.bookID = b.bookID
            WHERE br.userID = ? AND br.borrow_status = 'borrowed'
            ORDER BY br.borrow_date DESC
        ";
        
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            throw new Exception("Database prepare error: " . $conn->error);
        }
        
        $stmt->bind_param("i", $user_id);
        if (!$stmt->execute()) {
            throw new Exception("Database execute error: " . $stmt->error);
        }
        
        $result = $stmt->get_result();
        
        $books = [];
        while ($book = $result->fetch_assoc()) {
            // Convert image to base64
            if ($book['book_image'] && $book['book_image_mime']) {
                $book['book_image_base64'] = 'data:' . $book['book_image_mime'] . ';base64,' . base64_encode($book['book_image']);
            } else {
                $book['book_image_base64'] = null;
            }
            unset($book['book_image']);
            $books[] = $book;
        }
        
        echo json_encode(['success' => true, 'books' => $books]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error fetching borrowed books: ' . $e->getMessage()]);
        error_log("Get borrowed books error for user $user_id: " . $e->getMessage());
    }
}

// Function to remove item from cart (client-side only)
function handleRemoveFromCart() {
    echo json_encode(['success' => true, 'message' => 'Item removed from cart']);
}

// Get notifications count
try {
    $notifications_query = "SELECT COUNT(*) as total FROM notifications WHERE userID = ? AND read_status = FALSE";
    $stmt = $conn->prepare($notifications_query);
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $notifications_count = $stmt->get_result()->fetch_assoc()['total'];
    } else {
        $notifications_count = 0;
    }
} catch (Exception $e) {
    $notifications_count = 0;
    error_log("Notification count error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book Checkout Station - SMK Chendering Library</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --secondary-gradient: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            --success-gradient: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            --warning-gradient: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            --error-gradient: linear-gradient(135deg, #fc466b 0%, #3f5efb 100%);
            --glass-bg: rgba(255, 255, 255, 0.95);
            --glass-border: rgba(255, 255, 255, 0.18);
            --dark-glass: rgba(26, 32, 44, 0.95);
            --shadow-light: 0 8px 32px rgba(31, 38, 135, 0.37);
            --shadow-heavy: 0 25px 50px rgba(0, 0, 0, 0.15);
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--primary-gradient);
            min-height: 100vh;
            overflow-x: hidden;
            position: relative;
        }

        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg width="60" height="60" viewBox="0 0 60 60" xmlns="http://www.w3.org/2000/svg"><g fill="none" fill-rule="evenodd"><g fill="rgba(255,255,255,0.03)" fill-opacity="0.4"><circle cx="20" cy="20" r="2"/><circle cx="40" cy="40" r="2"/></g></svg>');
            z-index: -1;
            opacity: 0.3;
        }

        /* Sidebar */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 280px;
            height: 100vh;
            background: var(--dark-glass);
            backdrop-filter: blur(20px);
            border-right: 1px solid rgba(255, 255, 255, 0.1);
            z-index: 1000;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            overflow-y: auto;
        }

        .sidebar.collapsed {
            width: 80px;
        }

        .sidebar-header {
            padding: 2rem 1.5rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .school-logo {
            width: 50px;
            height: 50px;
            background: var(--primary-gradient);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 800;
            font-size: 1.3rem;
            flex-shrink: 0;
            box-shadow: var(--shadow-light);
            position: relative;
            overflow: hidden;
        }

        .school-logo::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(45deg, transparent, rgba(255,255,255,0.1), transparent);
            transform: rotate(45deg);
            animation: shine 3s infinite;
        }

        .school-info h3 {
            font-size: 1.1rem;
            font-weight: 700;
            color: #e2e8f0;
            margin-bottom: 0.25rem;
        }

        .school-info p {
            font-size: 0.85rem;
            color: #a0aec0;
            font-weight: 500;
        }

        .sidebar.collapsed .school-info {
            display: none;
        }

        .sidebar-menu {
            padding: 1.5rem 0;
        }

        .menu-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem 1.5rem;
            color: #a0aec0;
            text-decoration: none;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border-radius: 0;
            position: relative;
            margin: 0.25rem 0;
            font-weight: 500;
        }

        .menu-item::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 0;
            background: var(--primary-gradient);
            border-radius: 0 6px 6px 0;
            transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .menu-item:hover,
        .menu-item.active {
            background: rgba(102, 126, 234, 0.1);
            color: #e2e8f0;
            transform: translateX(8px);
        }

        .menu-item.active::before {
            width: 4px;
        }

        .menu-item i {
            width: 20px;
            text-align: center;
            font-size: 1.1rem;
            flex-shrink: 0;
        }

        .sidebar.collapsed .menu-item span {
            display: none;
        }

        .sidebar.collapsed .menu-item {
            justify-content: center;
            padding: 1rem;
        }

        /* Main Content */
        .main-content {
            margin-left: 280px;
            transition: margin-left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            min-height: 100vh;
            background: rgba(255, 255, 255, 0.02);
        }

        .main-content.expanded {
            margin-left: 80px;
        }

        /* Header */
        .header {
            background: var(--glass-bg);
            backdrop-filter: blur(30px);
            padding: 1.5rem 2rem;
            border-bottom: 1px solid var(--glass-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: var(--shadow-light);
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .toggle-sidebar {
            background: none;
            border: none;
            color: #4a5568;
            font-size: 1.3rem;
            cursor: pointer;
            padding: 0.75rem;
            border-radius: 12px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .toggle-sidebar:hover {
            background: rgba(102, 126, 234, 0.1);
            color: #667eea;
            transform: rotate(180deg) scale(1.1);
        }

        .header-title {
            font-size: 1.8rem;
            font-weight: 800;
            background: var(--primary-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .notification-btn {
            position: relative;
            background: none;
            border: none;
            color: #4a5568;
            font-size: 1.3rem;
            cursor: pointer;
            padding: 0.75rem;
            border-radius: 12px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .notification-btn:hover {
            background: rgba(102, 126, 234, 0.1);
            color: #667eea;
            transform: scale(1.1);
        }

        .notification-badge {
            position: absolute;
            top: 0.5rem;
            right: 0.5rem;
            background: var(--error-gradient);
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: 0.7rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            animation: pulse 2s infinite;
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            background: rgba(237, 242, 247, 0.9);
            padding: 0.75rem 1.25rem;
            border-radius: 16px;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid var(--glass-border);
        }

        .user-menu:hover {
            background: rgba(226, 232, 240, 1);
            transform: translateY(-2px);
            box-shadow: var(--shadow-light);
        }

        .user-avatar {
            width: 36px;
            height: 36px;
            background: var(--primary-gradient);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 1rem;
        }

        .user-name {
            font-size: 0.95rem;
            font-weight: 600;
            color: #2d3748;
        }

        /* Checkout Section */
        .checkout-section {
            padding: 3rem 2rem;
            display: grid;
            grid-template-columns: 1fr 400px;
            gap: 3rem;
            max-width: 1400px;
            margin: 0 auto;
            min-height: calc(100vh - 120px);
        }

        /* Scanner Panel */
        .scanner-panel {
            background: var(--glass-bg);
            backdrop-filter: blur(30px);
            border: 1px solid var(--glass-border);
            border-radius: 24px;
            padding: 3rem;
            box-shadow: var(--shadow-heavy);
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            gap: 2.5rem;
        }

        .scanner-panel::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.03), rgba(118, 75, 162, 0.03));
            z-index: -1;
        }

        .scanner-header {
            text-align: center;
        }

        .scanner-title {
            font-size: 2.5rem;
            font-weight: 900;
            background: var(--primary-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 0.75rem;
            letter-spacing: -0.02em;
        }

        .scanner-subtitle {
            font-size: 1.2rem;
            color: #64748b;
            font-weight: 500;
            margin-bottom: 1rem;
        }

        .scanner-description {
            color: #94a3b8;
            font-size: 1rem;
            line-height: 1.6;
        }

        /* Barcode Scanner */
        .barcode-scanner {
            position: relative;
        }

        .scanner-input {
            width: 100%;
            padding: 1.5rem 2rem 1.5rem 4rem;
            background: rgba(255, 255, 255, 0.9);
            border: 3px solid rgba(203, 213, 224, 0.3);
            border-radius: 20px;
            color: #2d3748;
            font-size: 1.2rem;
            font-weight: 600;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            backdrop-filter: blur(10px);
            position: relative;
        }

        .scanner-input::placeholder {
            color: #a0aec0;
            font-weight: 500;
        }

        .scanner-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 6px rgba(102, 126, 234, 0.15);
            background: rgba(255, 255, 255, 1);
            transform: translateY(-2px);
        }

        .scanner-icon {
            position: absolute;
            left: 1.5rem;
            top: 50%;
            transform: translateY(-50%);
            color: #667eea;
            font-size: 1.4rem;
            pointer-events: none;
        }

        .scan-button {
            width: 100%;
            padding: 1.5rem 2rem;
            background: var(--primary-gradient);
            color: white;
            border: none;
            border-radius: 20px;
            font-size: 1.1rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: var(--shadow-light);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 1rem;
            position: relative;
            overflow: hidden;
        }

        .scan-button::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s;
        }

        .scan-button:hover {
            transform: translateY(-3px);
            box-shadow: 0 20px 40px rgba(102, 126, 234, 0.4);
        }

        .scan-button:hover::before {
            left: 100%;
        }

        .scan-button:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
        }

        /* Book Display */
        .book-display {
            background: rgba(248, 250, 252, 0.8);
            border: 2px dashed rgba(203, 213, 224, 0.5);
            border-radius: 16px;
            padding: 2rem;
            text-align: center;
            min-height: 200px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            gap: 1rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .book-display.has-book {
            background: rgba(255, 255, 255, 0.95);
            border: 2px solid rgba(102, 126, 234, 0.3);
            box-shadow: var(--shadow-light);
        }

        .book-placeholder {
            color: #cbd5e0;
            font-size: 4rem;
            margin-bottom: 1rem;
        }

        .book-placeholder-text {
            color: #94a3b8;
            font-size: 1.1rem;
            font-weight: 500;
        }

        .scanned-book {
            display: flex;
            align-items: center;
            gap: 1.5rem;
            text-align: left;
            width: 100%;
            animation: slideInUp 0.5s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .scanned-book-image {
            width: 80px;
            height: 120px;
            object-fit: cover;
            border-radius: 8px;
            border: 2px solid #e2e8f0;
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .scanned-book-placeholder {
            width: 80px;
            height: 120px;
            background: linear-gradient(135deg, #f7fafc, #edf2f7);
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            color: rgba(102, 126, 234, 0.3);
            flex-shrink: 0;
        }

        .scanned-book-info {
            flex: 1;
        }

        .scanned-book-title {
            font-size: 1.2rem;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 0.5rem;
            line-height: 1.4;
        }

        .scanned-book-author {
            color: #64748b;
            font-size: 1rem;
            font-weight: 500;
            margin-bottom: 0.75rem;
        }

        .book-status {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 12px;
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 1rem;
        }

        .status-available {
            background: rgba(34, 197, 94, 0.1);
            color: #16a34a;
            border: 1px solid rgba(34, 197, 94, 0.2);
        }

        .status-borrowed {
            background: rgba(239, 68, 68, 0.1);
            color: #dc2626;
            border: 1px solid rgba(239, 68, 68, 0.2);
        }

        .status-reserved {
            background: rgba(245, 158, 11, 0.1);
            color: #d97706;
            border: 1px solid rgba(245, 158, 11, 0.2);
        }

        .status-maintenance {
            background: rgba(107, 114, 128, 0.1);
            color: #6b7280;
            border: 1px solid rgba(107, 114, 128, 0.2);
        }

        /* Action Buttons */
        .book-actions {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
        }

        .action-btn {
            flex: 1;
            padding: 0.875rem 1.5rem;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            position: relative;
            overflow: hidden;
        }

        .action-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s;
        }

        .action-btn:hover::before {
            left: 100%;
        }

        .btn-borrow {
            background: var(--success-gradient);
            color: white;
            box-shadow: 0 4px 15px rgba(79, 172, 254, 0.4);
        }

        .btn-borrow:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(79, 172, 254, 0.6);
        }

        .btn-reserve {
            background: var(--warning-gradient);
            color: white;
            box-shadow: 0 4px 15px rgba(240, 147, 251, 0.4);
        }

        .btn-reserve:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(240, 147, 251, 0.6);
        }

        .btn-return {
            background: var(--error-gradient);
            color: white;
            box-shadow: 0 4px 15px rgba(252, 70, 107, 0.4);
        }

        .btn-return:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(252, 70, 107, 0.6);
        }

        .btn-add-cart {
            background: rgba(102, 126, 234, 0.1);
            color: #667eea;
            border: 2px solid rgba(102, 126, 234, 0.2);
        }

        .btn-add-cart:hover {
            background: rgba(102, 126, 234, 0.2);
            transform: translateY(-2px);
        }

        .action-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

        /* Checkout Cart */
        .checkout-cart {
            background: var(--glass-bg);
            backdrop-filter: blur(30px);
            border: 1px solid var(--glass-border);
            border-radius: 24px;
            padding: 2rem;
            box-shadow: var(--shadow-heavy);
            position: sticky;
            top: 140px;
            max-height: calc(100vh - 180px);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .cart-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid rgba(226, 232, 240, 0.3);
        }

        .cart-title {
            font-size: 1.5rem;
            font-weight: 800;
            color: #2d3748;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .cart-title i {
            color: #667eea;
            font-size: 1.3rem;
        }

        .cart-count {
            background: var(--primary-gradient);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 700;
        }

        .cart-items {
            flex: 1;
            overflow-y: auto;
            margin-bottom: 1.5rem;
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .cart-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            background: rgba(248, 250, 252, 0.8);
            border: 1px solid rgba(226, 232, 240, 0.5);
            border-radius: 12px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            animation: slideInRight 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .cart-item:hover {
            background: rgba(255, 255, 255, 0.9);
            border-color: rgba(102, 126, 234, 0.3);
            transform: translateX(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.1);
        }

        .cart-item-image {
            width: 50px;
            height: 70px;
            object-fit: cover;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
            flex-shrink: 0;
        }

        .cart-item-placeholder {
            width: 50px;
            height: 70px;
            background: linear-gradient(135deg, #f7fafc, #edf2f7);
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: rgba(102, 126, 234, 0.3);
            flex-shrink: 0;
        }

        .cart-item-info {
            flex: 1;
        }

        .cart-item-title {
            font-size: 0.95rem;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 0.25rem;
            line-height: 1.3;
            overflow: hidden;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
        }

        .cart-item-author {
            color: #64748b;
            font-size: 0.8rem;
            font-weight: 500;
            margin-bottom: 0.5rem;
        }

        .cart-item-action {
            font-size: 0.8rem;
            padding: 0.25rem 0.75rem;
            border-radius: 8px;
            font-weight: 600;
        }

        .action-borrow {
            background: rgba(34, 197, 94, 0.1);
            color: #16a34a;
        }

        .action-reserve {
            background: rgba(245, 158, 11, 0.1);
            color: #d97706;
        }

        .action-return {
            background: rgba(239, 68, 68, 0.1);
            color: #dc2626;
        }

        .remove-item {
            background: none;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 6px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            flex-shrink: 0;
        }

        .remove-item:hover {
            background: rgba(239, 68, 68, 0.1);
            color: #dc2626;
            transform: scale(1.1);
        }

        .cart-empty {
            text-align: center;
            color: #94a3b8;
            font-size: 1rem;
            padding: 2rem 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 1rem;
        }

        .cart-empty i {
            font-size: 3rem;
            color: #cbd5e0;
        }

        .cart-summary {
            border-top: 2px solid rgba(226, 232, 240, 0.3);
            padding-top: 1.5rem;
            margin-top: auto;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            font-weight: 600;
            color: #4a5568;
        }

        .summary-total {
            font-size: 1.1rem;
            color: #2d3748;
            font-weight: 800;
        }

        .process-cart {
            width: 100%;
            padding: 1.25rem 2rem;
            background: var(--primary-gradient);
            color: white;
            border: none;
            border-radius: 16px;
            font-size: 1.1rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: var(--shadow-light);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            position: relative;
            overflow: hidden;
            margin-top: 1rem;
        }

        .process-cart::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s;
        }

        .process-cart:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 35px rgba(102, 126, 234, 0.5);
        }

        .process-cart:hover::before {
            left: 100%;
        }

        .process-cart:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        /* Quick Actions */
        .quick-actions {
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 1px solid rgba(226, 232, 240, 0.3);
        }

        .quick-actions-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: #4a5568;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .quick-action-btn {
            width: 100%;
            padding: 1rem;
            background: rgba(248, 250, 252, 0.8);
            border: 1px solid rgba(226, 232, 240, 0.5);
            border-radius: 12px;
            color: #64748b;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            margin-bottom: 0.75rem;
        }

        .quick-action-btn:hover {
            background: rgba(102, 126, 234, 0.1);
            color: #667eea;
            border-color: rgba(102, 126, 234, 0.3);
            transform: translateY(-2px);
        }

        /* Notifications */
        .notification {
            position: fixed;
            top: 2rem;
            right: 2rem;
            padding: 1rem 1.5rem;
            border-radius: 12px;
            color: white;
            font-weight: 600;
            box-shadow: var(--shadow-heavy);
            backdrop-filter: blur(20px);
            z-index: 9999;
            transform: translateX(400px);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            max-width: 400px;
        }

        .notification.show {
            transform: translateX(0);
        }

        .notification.success {
            background: var(--success-gradient);
        }

        .notification.error {
            background: var(--error-gradient);
        }

        .notification.warning {
            background: var(--warning-gradient);
        }

        /* Loading States */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }

        .loading-overlay.show {
            opacity: 1;
            visibility: visible;
        }

        .loading-content {
            text-align: center;
            color: #667eea;
        }

        .loading-spinner {
            width: 60px;
            height: 60px;
            border: 4px solid rgba(102, 126, 234, 0.1);
            border-left-color: #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 1rem;
        }

        .loading-text {
            font-size: 1.1rem;
            font-weight: 600;
        }

        /* Animations */
        @keyframes slideInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        @keyframes shine {
            0% { transform: translateX(-100%) translateY(-100%) rotate(45deg); }
            100% { transform: translateX(100%) translateY(100%) rotate(45deg); }
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        /* Responsive Design */
        @media (max-width: 1200px) {
            .checkout-section {
                grid-template-columns: 1fr;
                gap: 2rem;
            }

            .checkout-cart {
                position: relative;
                top: auto;
                max-height: none;
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                width: 80px;
            }

            .main-content {
                margin-left: 80px;
            }

            .checkout-section {
                padding: 2rem 1rem;
            }

            .scanner-panel {
                padding: 2rem;
            }

            .scanner-title {
                font-size: 2rem;
            }

            .header {
                padding: 1rem;
            }

            .header-title {
                font-size: 1.4rem;
            }

            .user-name {
                display: none;
            }
        }

        @media (max-width: 480px) {
            .checkout-section {
                padding: 1.5rem 1rem;
                gap: 1.5rem;
            }

            .scanner-panel {
                padding: 1.5rem;
            }

            .checkout-cart {
                padding: 1.5rem;
            }

            .book-actions {
                flex-direction: column;
            }

            .scanned-book {
                flex-direction: column;
                text-align: center;
                gap: 1rem;
            }
        }
    </style>
</head>
<body>
    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-content">
            <div class="loading-spinner"></div>
            <div class="loading-text">Processing...</div>
        </div>
    </div>

    <!-- Sidebar -->
    <nav class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="school-logo">SMK</div>
            <div class="school-info">
                <h3>SMK Chendering</h3>
                <p>Digital Library</p>
            </div>
        </div>
        
        <div class="sidebar-menu">
            <a href="student_dashboard.php" class="menu-item">
                <i class="fas fa-home"></i>
                <span>Dashboard</span>
            </a>
            
            <a href="student_search_books.php" class="menu-item">
                <i class="fas fa-search"></i>
                <span>Book Catalogue</span>
            </a>
            
            <a href="student_borrowing_reservations.php" class="menu-item active">
                <i class="fas fa-shopping-cart"></i>
                <span>Checkout Station</span>
            </a>
            
            <a href="student_my_borrowed_books.php" class="menu-item">
                <i class="fas fa-bookmark"></i>
                <span>My Books</span>
            </a>
            
            <a href="student_fines_penalties.php" class="menu-item">
                <i class="fas fa-money-bill-wave"></i>
                <span>Fines</span>
            </a>
            
            <a href="student_profile.php" class="menu-item">
                <i class="fas fa-user"></i>
                <span>Profile</span>
            </a>
            
            <a href="logout.php" class="menu-item">
                <i class="fas fa-sign-out-alt"></i>
                <span>Logout</span>
            </a>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="main-content" id="mainContent">
        <!-- Header -->
        <header class="header">
            <div class="header-left">
                <button class="toggle-sidebar" onclick="toggleSidebar()">
                    <i class="fas fa-bars"></i>
                </button>
                
                <h2 class="header-title">Book Checkout Station</h2>
            </div>
            
            <div class="header-right">
                <button class="notification-btn" onclick="window.location.href='student_notifications.php'">
                    <i class="fas fa-bell"></i>
                    <?php if ($notifications_count > 0): ?>
                        <span class="notification-badge"><?php echo min($notifications_count, 99); ?></span>
                    <?php endif; ?>
                </button>
                
                <div class="user-menu" onclick="window.location.href='student_profile.php'">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($student_name, 0, 1)); ?>
                    </div>
                    <span class="user-name"><?php echo htmlspecialchars(explode(' ', $student_name)[0]); ?></span>
                </div>
                
                <a href="logout.php" class="btn btn-secondary" style="padding: 0.75rem 1.5rem; text-decoration: none; font-size: 0.9rem; background: rgba(255, 255, 255, 0.9); color: #4a5568; border-radius: 12px; font-weight: 600;">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </header>

        <!-- Checkout Section -->
        <section class="checkout-section">
            <!-- Scanner Panel -->
            <div class="scanner-panel">
                <div class="scanner-header">
                    <h1 class="scanner-title">
                        <i class="fas fa-barcode" style="color: #667eea; margin-right: 0.5rem;"></i>
                        Book Scanner
                    </h1>
                    <p class="scanner-subtitle">Scan or Enter Book Details</p>
                    <p class="scanner-description">
                        Use your barcode scanner or manually enter the ISBN/barcode to find books for borrowing, reserving, or returning.
                    </p>
                </div>

                <div class="barcode-scanner">
                    <div style="position: relative;">
                        <i class="fas fa-barcode scanner-icon"></i>
                        <input 
                            type="text" 
                            id="barcodeInput" 
                            class="scanner-input" 
                            placeholder="Scan barcode or enter ISBN..."
                            autocomplete="off"
                        >
                    </div>
                    <button class="scan-button" id="scanButton">
                        <i class="fas fa-search"></i>
                        <span>Find Book</span>
                    </button>
                </div>

                <div class="book-display" id="bookDisplay">
                    <i class="fas fa-book book-placeholder"></i>
                    <p class="book-placeholder-text">Scan a book to see its details here</p>
                </div>
            </div>

            <!-- Checkout Cart -->
            <div class="checkout-cart">
                <div class="cart-header">
                    <div class="cart-title">
                        <i class="fas fa-shopping-cart"></i>
                        Checkout Cart
                    </div>
                    <div class="cart-count" id="cartCount">0 items</div>
                </div>

                <div class="cart-items" id="cartItems">
                    <div class="cart-empty">
                        <i class="fas fa-shopping-cart"></i>
                        <p>Your cart is empty</p>
                        <small>Scan books to add them to your cart</small>
                    </div>
                </div>

                <div class="cart-summary" id="cartSummary" style="display: none;">
                    <div class="summary-row">
                        <span>Books to Borrow:</span>
                        <span id="borrowCount">0</span>
                    </div>
                    <div class="summary-row">
                        <span>Books to Reserve:</span>
                        <span id="reserveCount">0</span>
                    </div>
                    <div class="summary-row">
                        <span>Books to Return:</span>
                        <span id="returnCount">0</span>
                    </div>
                    <div class="summary-row summary-total">
                        <span>Total Actions:</span>
                        <span id="totalCount">0</span>
                    </div>
                </div>

                <button class="process-cart" id="processCart" disabled>
                    <i class="fas fa-check-circle"></i>
                    <span>Process All Items</span>
                </button>

                <div class="quick-actions">
                    <div class="quick-actions-title">
                        <i class="fas fa-bolt"></i>
                        Quick Actions
                    </div>
                    <button class="quick-action-btn" onclick="loadMyBorrowedBooks()">
                        <i class="fas fa-book-open"></i>
                        View My Borrowed Books
                    </button>
                    <button class="quick-action-btn" onclick="clearCart()">
                        <i class="fas fa-trash-alt"></i>
                        Clear Cart
                    </button>
                </div>
            </div>
        </section>
    </main>

    <script>
        let cart = [];
        let currentBook = null;
        let isProcessing = false;

        // Initialize page
        document.addEventListener('DOMContentLoaded', function() {
            setupEventListeners();
            setupBarcodeScanner();
            updateCartDisplay();
            loadMyBorrowedBooks();
        });

        // Toggle sidebar
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
        }

        // Setup event listeners
        function setupEventListeners() {
            const barcodeInput = document.getElementById('barcodeInput');
            const scanButton = document.getElementById('scanButton');
            const processCartBtn = document.getElementById('processCart');

            // Scan button click
            scanButton.addEventListener('click', scanBook);

            // Enter key on barcode input
            barcodeInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    scanBook();
                }
            });

            // Process cart button
            processCartBtn.addEventListener('click', processCart);

            // Auto-focus on barcode input
            barcodeInput.focus();
        }

        // Setup barcode scanner functionality
        function setupBarcodeScanner() {
            const barcodeInput = document.getElementById('barcodeInput');
            let scanTimeout = null;

            barcodeInput.addEventListener('input', function(e) {
                const value = e.target.value;
                
                if (scanTimeout) {
                    clearTimeout(scanTimeout);
                }
                
                // Auto-trigger scan for scanned barcodes (typically 10+ digits)
                if (value.length >= 10 && /^\d+$/.test(value)) {
                    scanTimeout = setTimeout(() => {
                        scanBook();
                    }, 500); // Slight delay for barcode scanner completion
                }
            });

            // Clear scan timeout on manual input
            barcodeInput.addEventListener('keydown', function(e) {
                if (scanTimeout && e.key !== 'Enter') {
                    clearTimeout(scanTimeout);
                }
            });
        }

        // Scan book function
        async function scanBook() {
            const barcode = document.getElementById('barcodeInput').value.trim();
            
            if (!barcode) {
                showNotification('Please enter a barcode or ISBN', 'warning');
                return;
            }

            if (isProcessing) return;

            showLoading(true);
            isProcessing = true;

            try {
                const formData = new FormData();
                formData.append('barcode', barcode);

                const response = await fetch('?action=scan_book', {
                    method: 'POST',
                    body: formData
                });

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const data = await response.json();

                if (data.success) {
                    currentBook = data.book;
                    displayScannedBook(data.book);
                    document.getElementById('barcodeInput').value = '';
                    
                    // Auto-focus back to input for next scan
                    setTimeout(() => {
                        document.getElementById('barcodeInput').focus();
                    }, 100);
                } else {
                    showNotification(data.message || 'Book not found', 'error');
                    clearBookDisplay();
                }
            } catch (error) {
                console.error('Scan error:', error);
                showNotification('Error scanning book. Please try again.', 'error');
                clearBookDisplay();
            } finally {
                showLoading(false);
                isProcessing = false;
            }
        }

        // Display scanned book
        function displayScannedBook(book) {
            const bookDisplay = document.getElementById('bookDisplay');
            
            const imageHtml = book.book_image_base64 
                ? `<img src="${book.book_image_base64}" alt="${escapeHtml(book.bookTitle)}" class="scanned-book-image">`
                : `<div class="scanned-book-placeholder"><i class="fas fa-book"></i></div>`;

            const statusClass = getStatusClass(book.bookStatus);
            const statusIcon = getStatusIcon(book.bookStatus);

            bookDisplay.className = 'book-display has-book';
            bookDisplay.innerHTML = `
                <div class="scanned-book">
                    ${imageHtml}
                    <div class="scanned-book-info">
                        <div class="scanned-book-title">${escapeHtml(book.bookTitle)}</div>
                        <div class="scanned-book-author">by ${escapeHtml(book.bookAuthor || 'Unknown Author')}</div>
                        <div class="book-status ${statusClass}">
                            <i class="${statusIcon}"></i>
                            ${book.status_display}
                        </div>
                        <div class="book-actions">
                            ${generateActionButtons(book)}
                        </div>
                    </div>
                </div>
            `;
        }

        // Generate action buttons based on book status
        function generateActionButtons(book) {
            let buttons = [];
            
            // Check if book is already in cart
            const inCart = cart.find(item => item.bookID === book.bookID);
            
            if (inCart) {
                buttons.push(`
                    <button class="action-btn btn-add-cart" disabled>
                        <i class="fas fa-check"></i> In Cart
                    </button>
                `);
                return buttons.join('');
            }

            // Borrow button (only if available and not already borrowed by user)
            if (book.bookStatus === 'available' && !book.already_borrowed) {
                buttons.push(`
                    <button class="action-btn btn-borrow" onclick="addToCart('borrow')">
                        <i class="fas fa-book"></i> Borrow
                    </button>
                `);
            }

            // Reserve button (only if borrowed by someone else and user hasn't reserved)
            if (book.bookStatus === 'borrowed' && !book.already_reserved && !book.already_borrowed) {
                buttons.push(`
                    <button class="action-btn btn-reserve" onclick="addToCart('reserve')">
                        <i class="fas fa-calendar-plus"></i> Reserve
                    </button>
                `);
            }

            // Return button (only if currently borrowed by this user)
            if (book.already_borrowed) {
                buttons.push(`
                    <button class="action-btn btn-return" onclick="addToCart('return')">
                        <i class="fas fa-undo"></i> Return
                    </button>
                `);
            }

            // If no actions available
            if (buttons.length === 0) {
                let reason = '';
                if (book.bookStatus === 'maintenance') {
                    reason = 'Book is under maintenance';
                } else if (book.bookStatus === 'reserved') {
                    reason = 'Book is reserved by another user';
                } else if (book.already_reserved) {
                    reason = 'You have already reserved this book';
                } else {
                    reason = 'No actions available for this book';
                }
                
                buttons.push(`
                    <button class="action-btn" disabled style="background: rgba(107, 114, 128, 0.1); color: #6b7280;">
                        <i class="fas fa-info-circle"></i> ${reason}
                    </button>
                `);
            }

            return buttons.join('');
        }

        // Add book to cart
        function addToCart(action) {
            if (!currentBook) return;

            const cartItem = {
                bookID: currentBook.bookID,
                bookTitle: currentBook.bookTitle,
                bookAuthor: currentBook.bookAuthor,
                book_image_base64: currentBook.book_image_base64,
                action: action,
                timestamp: Date.now()
            };

            cart.push(cartItem);
            updateCartDisplay();
            clearBookDisplay();
            
            showNotification(`Book added to cart for ${action}ing!`, 'success');
            
            // Focus back to input
            document.getElementById('barcodeInput').focus();
        }

        // Remove item from cart
        function removeFromCart(index) {
            if (index >= 0 && index < cart.length) {
                const item = cart[index];
                cart.splice(index, 1);
                updateCartDisplay();
                showNotification(`${item.bookTitle} removed from cart`, 'success');
            }
        }

        // Update cart display
        function updateCartDisplay() {
            const cartItems = document.getElementById('cartItems');
            const cartCount = document.getElementById('cartCount');
            const cartSummary = document.getElementById('cartSummary');
            const processCartBtn = document.getElementById('processCart');

            // Update count
            cartCount.textContent = `${cart.length} item${cart.length !== 1 ? 's' : ''}`;

            if (cart.length === 0) {
                cartItems.innerHTML = `
                    <div class="cart-empty">
                        <i class="fas fa-shopping-cart"></i>
                        <p>Your cart is empty</p>
                        <small>Scan books to add them to your cart</small>
                    </div>
                `;
                cartSummary.style.display = 'none';
                processCartBtn.disabled = true;
                return;
            }

            // Display cart items
            cartItems.innerHTML = cart.map((item, index) => {
                const imageHtml = item.book_image_base64 
                    ? `<img src="${item.book_image_base64}" alt="${escapeHtml(item.bookTitle)}" class="cart-item-image">`
                    : `<div class="cart-item-placeholder"><i class="fas fa-book"></i></div>`;

                const actionClass = `action-${item.action}`;
                const actionIcon = getActionIcon(item.action);

                return `
                    <div class="cart-item">
                        ${imageHtml}
                        <div class="cart-item-info">
                            <div class="cart-item-title">${escapeHtml(item.bookTitle)}</div>
                            <div class="cart-item-author">by ${escapeHtml(item.bookAuthor || 'Unknown Author')}</div>
                            <div class="cart-item-action ${actionClass}">
                                <i class="${actionIcon}"></i> ${item.action.charAt(0).toUpperCase() + item.action.slice(1)}
                            </div>
                        </div>
                        <button class="remove-item" onclick="removeFromCart(${index})" title="Remove from cart">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                `;
            }).join('');

            // Update summary
            const borrowCount = cart.filter(item => item.action === 'borrow').length;
            const reserveCount = cart.filter(item => item.action === 'reserve').length;
            const returnCount = cart.filter(item => item.action === 'return').length;

            document.getElementById('borrowCount').textContent = borrowCount;
            document.getElementById('reserveCount').textContent = reserveCount;
            document.getElementById('returnCount').textContent = returnCount;
            document.getElementById('totalCount').textContent = cart.length;

            cartSummary.style.display = 'block';
            processCartBtn.disabled = false;
        }

        // Process entire cart
        async function processCart() {
            if (cart.length === 0 || isProcessing) return;

            // Check borrow limit before processing
            const borrowItems = cart.filter(item => item.action === 'borrow').length;
            if (borrowItems > 0) {
                try {
                    const borrowedBooks = await getBorrowedBooksCount();
                    if (borrowedBooks + borrowItems > 3) {
                        showNotification(`You can only borrow ${3 - borrowedBooks} more books (limit: 3 total)`, 'error');
                        return;
                    }
                } catch (error) {
                    console.error('Error checking borrow count:', error);
                }
            }

            const confirmed = confirm(`Process all ${cart.length} items in your cart?`);
            if (!confirmed) return;

            showLoading(true);
            isProcessing = true;

            let successCount = 0;
            let errorCount = 0;
            const errors = [];
            const successes = [];

            for (const item of cart) {
                try {
                    const result = await processBookAction(item.bookID, item.action);
                    if (result.success) {
                        successCount++;
                        successes.push(`${item.bookTitle}: Successfully ${item.action}ed`);
                    } else {
                        errorCount++;
                        errors.push(`${item.bookTitle}: ${result.message || 'Failed to ' + item.action}`);
                    }
                } catch (error) {
                    errorCount++;
                    errors.push(`${item.bookTitle}: ${error.message}`);
                }
            }

            // Clear cart and update display
            cart = [];
            updateCartDisplay();
            clearBookDisplay();

            // Show results
            if (successCount > 0 && errorCount === 0) {
                showNotification(`Successfully processed all ${successCount} items!`, 'success');
            } else if (successCount > 0 && errorCount > 0) {
                showNotification(`Processed ${successCount} items. ${errorCount} failed.`, 'warning');
                console.log('Errors:', errors);
            } else {
                showNotification(`Failed to process items. Check console for details.`, 'error');
                console.log('Errors:', errors);
            }

            showLoading(false);
            isProcessing = false;
            
            // Refresh borrowed books list
            setTimeout(() => {
                loadMyBorrowedBooks();
            }, 1000);
            
            // Focus back to input
            document.getElementById('barcodeInput').focus();
        }

        // Process individual book action
        async function processBookAction(bookId, action) {
            const formData = new FormData();
            formData.append('book_id', bookId);

            const response = await fetch(`?action=${action}_book`, {
                method: 'POST',
                body: formData
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const data = await response.json();
            return data;
        }

        // Get borrowed books count
        async function getBorrowedBooksCount() {
            try {
                const response = await fetch('?action=get_borrowed_books');
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                const data = await response.json();
                return data.success ? data.books.length : 0;
            } catch (error) {
                console.error('Error getting borrowed books count:', error);
                return 0;
            }
        }

        // Load my borrowed books for quick return
        async function loadMyBorrowedBooks() {
            try {
                const response = await fetch('?action=get_borrowed_books');
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                const data = await response.json();

                if (data.success && data.books.length > 0) {
                    // Could display borrowed books in a modal or section
                    console.log('Borrowed books:', data.books);
                }
            } catch (error) {
                console.error('Error loading borrowed books:', error);
            }
        }

        // Clear cart
        function clearCart() {
            if (cart.length === 0) return;
            
            const confirmed = confirm('Clear all items from cart?');
            if (confirmed) {
                cart = [];
                updateCartDisplay();
                showNotification('Cart cleared', 'success');
                document.getElementById('barcodeInput').focus();
            }
        }

        // Clear book display
        function clearBookDisplay() {
            const bookDisplay = document.getElementById('bookDisplay');
            bookDisplay.className = 'book-display';
            bookDisplay.innerHTML = `
                <i class="fas fa-book book-placeholder"></i>
                <p class="book-placeholder-text">Scan a book to see its details here</p>
            `;
            currentBook = null;
        }

        // Utility functions
        function getStatusClass(status) {
            switch (status) {
                case 'available': return 'status-available';
                case 'borrowed': return 'status-borrowed';
                case 'reserved': return 'status-reserved';
                case 'maintenance': return 'status-maintenance';
                default: return 'status-maintenance';
            }
        }

        function getStatusIcon(status) {
            switch (status) {
                case 'available': return 'fas fa-check-circle';
                case 'borrowed': return 'fas fa-user';
                case 'reserved': return 'fas fa-clock';
                case 'maintenance': return 'fas fa-tools';
                default: return 'fas fa-question-circle';
            }
        }

        function getActionIcon(action) {
            switch (action) {
                case 'borrow': return 'fas fa-book';
                case 'reserve': return 'fas fa-calendar-plus';
                case 'return': return 'fas fa-undo';
                default: return 'fas fa-question';
            }
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Show loading overlay
        function showLoading(show) {
            const overlay = document.getElementById('loadingOverlay');
            if (show) {
                overlay.classList.add('show');
            } else {
                overlay.classList.remove('show');
            }
        }

        // Show notification
        function showNotification(message, type = 'success') {
            // Remove existing notifications
            const existingNotifications = document.querySelectorAll('.notification');
            existingNotifications.forEach(n => n.remove());

            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            
            const icon = type === 'success' ? 'fas fa-check-circle' : 
                        type === 'error' ? 'fas fa-exclamation-circle' :
                        type === 'warning' ? 'fas fa-exclamation-triangle' :
                        'fas fa-info-circle';
            
            notification.innerHTML = `
                <i class="${icon}"></i>
                <span>${message}</span>
            `;

            document.body.appendChild(notification);

            // Show notification
            setTimeout(() => {
                notification.classList.add('show');
            }, 100);

            // Hide notification after 5 seconds
            setTimeout(() => {
                notification.classList.remove('show');
                setTimeout(() => {
                    notification.remove();
                }, 300);
            }, 5000);
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Escape to clear current book
            if (e.key === 'Escape') {
                clearBookDisplay();
                document.getElementById('barcodeInput').focus();
            }
            
            // Ctrl+Enter to process cart
            if (e.ctrlKey && e.key === 'Enter') {
                if (cart.length > 0 && !isProcessing) {
                    processCart();
                }
            }
            
            // Ctrl+/ to focus on barcode input
            if (e.ctrlKey && e.key === '/') {
                e.preventDefault();
                document.getElementById('barcodeInput').focus();
            }
        });

        // Auto-focus on barcode input when clicking anywhere
        document.addEventListener('click', function(e) {
            // Only if not clicking on buttons or inputs
            if (!e.target.matches('button, input, a, .cart-item, .cart-item *')) {
                document.getElementById('barcodeInput').focus();
            }
        });

        // Prevent form submission on enter
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && e.target.tagName !== 'BUTTON') {
                e.preventDefault();
            }
        });

        // Initialize entrance animation
        setTimeout(() => {
            document.querySelector('.scanner-panel').style.animation = 'slideInUp 0.8s cubic-bezier(0.4, 0, 0.2, 1)';
            document.querySelector('.checkout-cart').style.animation = 'slideInRight 0.8s cubic-bezier(0.4, 0, 0.2, 1) 0.2s both';
        }, 100);
    </script>
</body>
</html>