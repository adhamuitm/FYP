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

// Get book ID from URL
$book_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$book_id) {
    header('Location: student_search_books.php');
    exit;
}

// Handle AJAX requests for borrow/reserve actions
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    $action = $_POST['action'];
    $book_id = (int)$_POST['book_id'];
    
    try {
        if ($action === 'borrow') {
            // Check if book is available
            $check_query = "SELECT bookStatus FROM book WHERE bookID = ? AND bookStatus = 'available'";
            $stmt = $conn->prepare($check_query);
            $stmt->bind_param("i", $book_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                // Check if user hasn't exceeded borrowing limit
                $limit_query = "SELECT COUNT(*) as borrowed_count FROM borrow WHERE userID = ? AND borrow_status = 'borrowed'";
                $stmt = $conn->prepare($limit_query);
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $limit_result = $stmt->get_result();
                $borrowed_count = $limit_result->fetch_assoc()['borrowed_count'];
                
                // Get borrowing rules for students
                $rules_query = "SELECT max_books_allowed, borrow_period_days FROM borrowing_rules WHERE user_type = 'student'";
                $rules_result = $conn->query($rules_query);
                $rules = $rules_result->fetch_assoc();
                
                if ($borrowed_count >= ($rules['max_books_allowed'] ?? 3)) {
                    echo json_encode(['success' => false, 'message' => 'You have reached your borrowing limit']);
                    exit;
                }
                
                // Insert borrow record
                $borrow_period = $rules['borrow_period_days'] ?? 14;
                $due_date = date('Y-m-d', strtotime("+{$borrow_period} days"));
                
                $insert_query = "INSERT INTO borrow (userID, bookID, borrow_date, due_date, borrow_status, checkout_method) VALUES (?, ?, CURDATE(), ?, 'borrowed', 'self_checkout')";
                $stmt = $conn->prepare($insert_query);
                $stmt->bind_param("iis", $user_id, $book_id, $due_date);
                $stmt->execute();
                
                // Update book status
                $update_query = "UPDATE book SET bookStatus = 'borrowed' WHERE bookID = ?";
                $stmt = $conn->prepare($update_query);
                $stmt->bind_param("i", $book_id);
                $stmt->execute();
                
                echo json_encode(['success' => true, 'message' => 'Book borrowed successfully! Due date: ' . date('M d, Y', strtotime($due_date)), 'redirect' => 'student_borrowing_reservations.php']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Book is not available for borrowing']);
            }
            
        } elseif ($action === 'reserve') {
            // Check if book is borrowed
            $check_query = "SELECT bookStatus FROM book WHERE bookID = ? AND bookStatus = 'borrowed'";
            $stmt = $conn->prepare($check_query);
            $stmt->bind_param("i", $book_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                // Check if user already has a reservation for this book
                $existing_query = "SELECT reservationID FROM reservation WHERE userID = ? AND bookID = ? AND reservation_status = 'active'";
                $stmt = $conn->prepare($existing_query);
                $stmt->bind_param("ii", $user_id, $book_id);
                $stmt->execute();
                
                if ($stmt->get_result()->num_rows > 0) {
                    echo json_encode(['success' => false, 'message' => 'You already have a reservation for this book']);
                    exit;
                }
                
                // Get queue position
                $queue_query = "SELECT COALESCE(MAX(queue_position), 0) + 1 as next_position FROM reservation WHERE bookID = ? AND reservation_status = 'active'";
                $stmt = $conn->prepare($queue_query);
                $stmt->bind_param("i", $book_id);
                $stmt->execute();
                $queue_result = $stmt->get_result();
                $queue_position = $queue_result->fetch_assoc()['next_position'];
                
                // Insert reservation
                $expiry_date = date('Y-m-d', strtotime('+30 days'));
                $insert_query = "INSERT INTO reservation (userID, bookID, reservation_date, expiry_date, queue_position, reservation_status) VALUES (?, ?, CURDATE(), ?, ?, 'active')";
                $stmt = $conn->prepare($insert_query);
                $stmt->bind_param("iisi", $user_id, $book_id, $expiry_date, $queue_position);
                $stmt->execute();
                
                echo json_encode(['success' => true, 'message' => "Book reserved successfully! You are position #{$queue_position} in the queue.", 'redirect' => 'student_borrowing_reservations.php']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Book is available for borrowing, no need to reserve']);
            }
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Operation failed. Please try again.']);
        error_log("Book action error: " . $e->getMessage());
    }
    exit;
}

// Get book details
try {
    $book_query = "
        SELECT 
            b.*,
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
        WHERE b.bookID = ? AND b.bookStatus != 'disposed'
    ";
    
    $stmt = $conn->prepare($book_query);
    $stmt->bind_param("i", $book_id);
    $stmt->execute();
    $book_result = $stmt->get_result();
    
    if ($book_result->num_rows === 0) {
        header('Location: student_search_books.php');
        exit;
    }
    
    $book = $book_result->fetch_assoc();
    
    // Get related books (same category)
    $related_query = "
        SELECT bookID, bookTitle, bookAuthor, book_image, book_image_mime, bookStatus
        FROM book 
        WHERE categoryID = ? AND bookID != ? AND bookStatus != 'disposed'
        ORDER BY RAND() 
        LIMIT 4
    ";
    $stmt = $conn->prepare($related_query);
    $stmt->bind_param("ii", $book['categoryID'], $book_id);
    $stmt->execute();
    $related_books = $stmt->get_result();
    
    // Get borrowing history count for this book
    $history_query = "SELECT COUNT(*) as borrow_count FROM borrow WHERE bookID = ?";
    $stmt = $conn->prepare($history_query);
    $stmt->bind_param("i", $book_id);
    $stmt->execute();
    $history_result = $stmt->get_result();
    $borrow_count = $history_result->fetch_assoc()['borrow_count'];
    
    // Get reservation queue if book is borrowed
    $queue_position = 0;
    if ($book['bookStatus'] === 'borrowed') {
        $queue_query = "SELECT COUNT(*) as position FROM reservation WHERE bookID = ? AND reservation_status = 'active'";
        $stmt = $conn->prepare($queue_query);
        $stmt->bind_param("i", $book_id);
        $stmt->execute();
        $queue_result = $stmt->get_result();
        $queue_position = $queue_result->fetch_assoc()['position'];
    }
    
    // Get notifications count
    $notifications_query = "SELECT COUNT(*) as total FROM notifications WHERE userID = ? AND read_status = FALSE";
    $stmt = $conn->prepare($notifications_query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $notifications_count = $stmt->get_result()->fetch_assoc()['total'];
    
} catch (Exception $e) {
    error_log("Book detail error: " . $e->getMessage());
    header('Location: student_search_books.php');
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($book['bookTitle']); ?> - SMK Chendering Library</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* Sidebar */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 280px;
            height: 100vh;
            background: rgba(26, 32, 44, 0.95);
            backdrop-filter: blur(20px);
            border-right: 1px solid rgba(255, 255, 255, 0.1);
            z-index: 1000;
            transition: all 0.3s ease;
            overflow-y: auto;
        }

        .sidebar.collapsed {
            width: 80px;
        }

        .sidebar-header {
            padding: 1.5rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .school-logo {
            width: 45px;
            height: 45px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 1.2rem;
            flex-shrink: 0;
            box-shadow: 0 8px 32px rgba(102, 126, 234, 0.4);
        }

        .school-info h3 {
            font-size: 1rem;
            font-weight: 600;
            color: #e2e8f0;
            margin-bottom: 0.25rem;
        }

        .school-info p {
            font-size: 0.8rem;
            color: #a0aec0;
        }

        .sidebar.collapsed .school-info {
            display: none;
        }

        .sidebar-menu {
            padding: 1rem 0;
        }

        .menu-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.875rem 1.5rem;
            color: #a0aec0;
            text-decoration: none;
            transition: all 0.3s ease;
            border-radius: 0;
            position: relative;
            margin: 0.25rem 0;
        }

        .menu-item:hover,
        .menu-item.active {
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.2), rgba(118, 75, 162, 0.2));
            color: #e2e8f0;
            transform: translateX(5px);
        }

        .menu-item.active::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 3px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 0 3px 3px 0;
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
            padding: 0.875rem;
        }

        /* Main Content */
        .main-content {
            margin-left: 280px;
            transition: margin-left 0.3s ease;
            min-height: 100vh;
            background: rgba(255, 255, 255, 0.02);
        }

        .main-content.expanded {
            margin-left: 80px;
        }

        /* Header */
        .header {
            background: rgba(248, 250, 252, 0.95);
            backdrop-filter: blur(20px);
            padding: 1rem 2rem;
            border-bottom: 1px solid rgba(226, 232, 240, 0.5);
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .toggle-sidebar {
            background: none;
            border: none;
            color: #4a5568;
            font-size: 1.2rem;
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .toggle-sidebar:hover {
            background: rgba(102, 126, 234, 0.1);
            color: #667eea;
            transform: rotate(180deg);
        }

        .header-title {
            font-size: 1.5rem;
            font-weight: 700;
            background: linear-gradient(135deg, #667eea, #764ba2);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .breadcrumb {
            font-size: 0.9rem;
            color: #718096;
            margin-left: 1rem;
        }

        .breadcrumb a {
            color: #667eea;
            text-decoration: none;
        }

        .breadcrumb a:hover {
            text-decoration: underline;
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
            font-size: 1.2rem;
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .notification-btn:hover {
            background: rgba(102, 126, 234, 0.1);
            color: #667eea;
            transform: scale(1.1);
        }

        .notification-badge {
            position: absolute;
            top: 0.25rem;
            right: 0.25rem;
            background: linear-gradient(135deg, #e53e3e, #ff6b6b);
            color: white;
            border-radius: 50%;
            width: 18px;
            height: 18px;
            font-size: 0.7rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            animation: pulse 2s infinite;
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            background: rgba(237, 242, 247, 0.8);
            padding: 0.5rem 1rem;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .user-menu:hover {
            background: rgba(226, 232, 240, 0.9);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.2);
        }

        .user-avatar {
            width: 32px;
            height: 32px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .user-name {
            font-size: 0.9rem;
            font-weight: 500;
            color: #2d3748;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.9);
            color: #4a5568;
            border: 2px solid rgba(203, 213, 224, 0.3);
            backdrop-filter: blur(10px);
        }

        .btn-secondary:hover {
            background: rgba(248, 250, 252, 1);
            transform: translateY(-2px);
            border-color: rgba(102, 126, 234, 0.3);
            color: #667eea;
        }

        /* Book Detail Section */
        .book-detail-section {
            padding: 3rem 2rem;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
        }

        .book-detail-container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .back-button {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: #667eea;
            text-decoration: none;
            font-weight: 500;
            margin-bottom: 2rem;
            padding: 0.75rem 1.5rem;
            background: rgba(102, 126, 234, 0.1);
            border-radius: 12px;
            transition: all 0.3s ease;
        }

        .back-button:hover {
            background: rgba(102, 126, 234, 0.2);
            transform: translateX(-5px);
        }

        .book-detail-grid {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 4rem;
            margin-bottom: 4rem;
        }

        .book-image-section {
            position: sticky;
            top: 120px;
            height: fit-content;
        }

        .book-image-container {
            background: rgba(255, 255, 255, 0.9);
            border-radius: 24px;
            padding: 2rem;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.3);
            backdrop-filter: blur(20px);
        }

        .book-image {
            width: 100%;
            max-width: 400px;
            height: auto;
            border-radius: 16px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
            transition: transform 0.3s ease;
        }

        .book-image:hover {
            transform: scale(1.05);
        }

        .book-placeholder {
            width: 100%;
            max-width: 400px;
            height: 500px;
            background: linear-gradient(135deg, #f7fafc, #edf2f7);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 6rem;
            color: rgba(102, 126, 234, 0.3);
            border: 2px dashed rgba(102, 126, 234, 0.2);
        }

        .book-info-section {
            background: rgba(255, 255, 255, 0.9);
            border-radius: 24px;
            padding: 3rem;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.3);
            backdrop-filter: blur(20px);
        }

        .book-title {
            font-size: 2.5rem;
            font-weight: 800;
            color: #2d3748;
            margin-bottom: 1rem;
            line-height: 1.2;
            background: linear-gradient(135deg, #2d3748, #4a5568);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .book-author {
            font-size: 1.3rem;
            color: #667eea;
            margin-bottom: 2rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            border-radius: 20px;
            font-weight: 600;
            font-size: 1rem;
            margin-bottom: 2rem;
            border: 2px solid;
        }

        .status-available {
            background: rgba(72, 187, 120, 0.1);
            color: #2f855a;
            border-color: rgba(72, 187, 120, 0.3);
        }

        .status-borrowed {
            background: rgba(245, 101, 101, 0.1);
            color: #c53030;
            border-color: rgba(245, 101, 101, 0.3);
        }

        .status-reserved {
            background: rgba(237, 137, 54, 0.1);
            color: #c05621;
            border-color: rgba(237, 137, 54, 0.3);
        }

        .status-maintenance {
            background: rgba(113, 128, 150, 0.1);
            color: #4a5568;
            border-color: rgba(113, 128, 150, 0.3);
        }

        .book-details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .detail-item {
            background: rgba(248, 250, 252, 0.8);
            padding: 1.5rem;
            border-radius: 16px;
            border: 1px solid rgba(226, 232, 240, 0.5);
        }

        .detail-label {
            font-size: 0.85rem;
            font-weight: 600;
            color: #718096;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .detail-value {
            font-size: 1rem;
            font-weight: 600;
            color: #2d3748;
        }

        .book-description {
            background: rgba(248, 250, 252, 0.8);
            padding: 2rem;
            border-radius: 16px;
            margin-bottom: 2rem;
            border: 1px solid rgba(226, 232, 240, 0.5);
        }

        .description-title {
            font-size: 1.2rem;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .description-text {
            font-size: 1rem;
            line-height: 1.7;
            color: #4a5568;
        }

        .action-buttons {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            box-shadow: 0 10px 35px rgba(102, 126, 234, 0.4);
            padding: 1rem 2rem;
            font-size: 1.1rem;
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 45px rgba(102, 126, 234, 0.6);
        }

        .btn-primary:disabled {
            background: linear-gradient(135deg, #a0aec0, #cbd5e0);
            box-shadow: none;
            cursor: not-allowed;
            transform: none;
        }

        .btn-outline {
            background: rgba(255, 255, 255, 0.9);
            color: #667eea;
            border: 2px solid #667eea;
            padding: 1rem 2rem;
            font-size: 1.1rem;
        }

        .btn-outline:hover {
            background: #667eea;
            color: white;
            transform: translateY(-2px);
        }

        .btn-outline:disabled {
            background: rgba(255, 255, 255, 0.5);
            color: #a0aec0;
            border-color: #a0aec0;
            cursor: not-allowed;
            transform: none;
        }

        /* Related Books Section */
        .related-books-section {
            background: rgba(248, 250, 252, 0.8);
            padding: 3rem;
            border-radius: 24px;
            border: 1px solid rgba(226, 232, 240, 0.5);
        }

        .section-title {
            font-size: 1.8rem;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .related-books-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 2rem;
        }

        .related-book-card {
            background: rgba(255, 255, 255, 0.9);
            border-radius: 16px;
            overflow: hidden;
            transition: all 0.3s ease;
            cursor: pointer;
            border: 1px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
        }

        .related-book-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 50px rgba(102, 126, 234, 0.3);
        }

        .related-book-image {
            width: 100%;
            height: 200px;
            object-fit: cover;
            background: linear-gradient(135deg, #f7fafc, #edf2f7);
        }

        .related-book-placeholder {
            width: 100%;
            height: 200px;
            background: linear-gradient(135deg, #f7fafc, #edf2f7);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            color: rgba(102, 126, 234, 0.3);
        }

        .related-book-content {
            padding: 1.5rem;
        }

        .related-book-title {
            font-size: 1rem;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 0.5rem;
            line-height: 1.4;
            overflow: hidden;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
        }

        .related-book-author {
            font-size: 0.9rem;
            color: #667eea;
            font-weight: 500;
        }

        /* Alert Messages */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-weight: 500;
            border: 1px solid;
        }

        .alert-success {
            background: rgba(72, 187, 120, 0.1);
            color: #2f855a;
            border-color: rgba(72, 187, 120, 0.3);
        }

        .alert-error {
            background: rgba(245, 101, 101, 0.1);
            color: #c53030;
            border-color: rgba(245, 101, 101, 0.3);
        }

        .alert-info {
            background: rgba(102, 126, 234, 0.1);
            color: #3c366b;
            border-color: rgba(102, 126, 234, 0.3);
        }

        /* Loading Overlay */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            backdrop-filter: blur(5px);
        }

        .loading-content {
            background: white;
            padding: 2rem;
            border-radius: 16px;
            text-align: center;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.25);
        }

        .loading-spinner {
            font-size: 2rem;
            color: #667eea;
            margin-bottom: 1rem;
            animation: spin 1s linear infinite;
        }

        /* Animations */
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Responsive Design */
        @media (max-width: 1024px) {
            .book-detail-grid {
                grid-template-columns: 1fr;
                gap: 2rem;
            }

            .book-image-section {
                position: static;
            }

            .book-detail-container {
                max-width: 800px;
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                width: 80px;
            }

            .main-content {
                margin-left: 80px;
            }

            .book-detail-section {
                padding: 2rem 1.5rem;
            }

            .book-info-section {
                padding: 2rem;
            }

            .book-title {
                font-size: 2rem;
            }

            .action-buttons {
                flex-direction: column;
            }

            .related-books-grid {
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
                gap: 1.5rem;
            }

            .user-name {
                display: none;
            }
        }

        @media (max-width: 480px) {
            .header {
                padding: 1rem;
            }

            .book-detail-section {
                padding: 1.5rem 1rem;
            }

            .book-info-section,
            .related-books-section {
                padding: 1.5rem;
            }

            .book-details-grid {
                grid-template-columns: 1fr;
            }

            .related-books-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-content">
            <i class="fas fa-spinner fa-spin loading-spinner"></i>
            <div>Processing your request...</div>
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
            
            <a href="student_borrowing_reservations.php" class="menu-item">
                <i class="fas fa-book-open"></i>
                <span>Borrow & Reserve</span>
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
                
                <h2 class="header-title">Book Details</h2>
                <div class="breadcrumb">
                    <a href="student_search_books.php">Catalogue</a> / <?php echo htmlspecialchars($book['bookTitle']); ?>
                </div>
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
                
                <a href="logout.php" class="btn btn-secondary">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </header>

        <!-- Book Detail Section -->
        <section class="book-detail-section">
            <div class="book-detail-container">
                <a href="student_search_books.php" class="back-button">
                    <i class="fas fa-arrow-left"></i>
                    Back to Catalogue
                </a>

                <!-- Alert Messages -->
                <div id="alertContainer"></div>

                <div class="book-detail-grid">
                    <!-- Book Image Section -->
                    <div class="book-image-section">
                        <div class="book-image-container">
                            <?php if ($book['book_image'] && $book['book_image_mime']): ?>
                                <img src="data:<?php echo $book['book_image_mime']; ?>;base64,<?php echo base64_encode($book['book_image']); ?>" 
                                     alt="<?php echo htmlspecialchars($book['bookTitle']); ?>" 
                                     class="book-image">
                            <?php else: ?>
                                <div class="book-placeholder">
                                    <i class="fas fa-book"></i>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Book Information Section -->
                    <div class="book-info-section">
                        <h1 class="book-title"><?php echo htmlspecialchars($book['bookTitle']); ?></h1>
                        
                        <div class="book-author">
                            <i class="fas fa-user-edit"></i>
                            <?php echo htmlspecialchars($book['bookAuthor'] ?: 'Unknown Author'); ?>
                        </div>

                        <div class="status-badge status-<?php echo strtolower($book['bookStatus']); ?>">
                            <i class="fas fa-circle"></i>
                            <?php echo $book['status_display']; ?>
                            <?php if ($book['bookStatus'] === 'borrowed' && $queue_position > 0): ?>
                                (<?php echo $queue_position; ?> in queue)
                            <?php endif; ?>
                        </div>

                        <div class="book-details-grid">
                            <?php if ($book['book_ISBN']): ?>
                            <div class="detail-item">
                                <div class="detail-label">
                                    <i class="fas fa-barcode"></i>
                                    ISBN
                                </div>
                                <div class="detail-value"><?php echo htmlspecialchars($book['book_ISBN']); ?></div>
                            </div>
                            <?php endif; ?>

                            <?php if ($book['categoryName']): ?>
                            <div class="detail-item">
                                <div class="detail-label">
                                    <i class="fas fa-tags"></i>
                                    Category
                                </div>
                                <div class="detail-value"><?php echo htmlspecialchars($book['categoryName']); ?></div>
                            </div>
                            <?php endif; ?>

                            <?php if ($book['bookPublisher']): ?>
                            <div class="detail-item">
                                <div class="detail-label">
                                    <i class="fas fa-building"></i>
                                    Publisher
                                </div>
                                <div class="detail-value"><?php echo htmlspecialchars($book['bookPublisher']); ?></div>
                            </div>
                            <?php endif; ?>

                            <?php if ($book['publication_year']): ?>
                            <div class="detail-item">
                                <div class="detail-label">
                                    <i class="fas fa-calendar-alt"></i>
                                    Year
                                </div>
                                <div class="detail-value"><?php echo $book['publication_year']; ?></div>
                            </div>
                            <?php endif; ?>

                            <?php if ($book['language']): ?>
                            <div class="detail-item">
                                <div class="detail-label">
                                    <i class="fas fa-globe"></i>
                                    Language
                                </div>
                                <div class="detail-value"><?php echo htmlspecialchars($book['language']); ?></div>
                            </div>
                            <?php endif; ?>

                            <?php if ($book['number_of_pages']): ?>
                            <div class="detail-item">
                                <div class="detail-label">
                                    <i class="fas fa-file-alt"></i>
                                    Pages
                                </div>
                                <div class="detail-value"><?php echo $book['number_of_pages']; ?></div>
                            </div>
                            <?php endif; ?>

                            <?php if ($book['shelf_location']): ?>
                            <div class="detail-item">
                                <div class="detail-label">
                                    <i class="fas fa-map-marker-alt"></i>
                                    Location
                                </div>
                                <div class="detail-value"><?php echo htmlspecialchars($book['shelf_location']); ?></div>
                            </div>
                            <?php endif; ?>

                            <div class="detail-item">
                                <div class="detail-label">
                                    <i class="fas fa-chart-bar"></i>
                                    Times Borrowed
                                </div>
                                <div class="detail-value"><?php echo $borrow_count; ?></div>
                            </div>
                        </div>

                        <?php if ($book['book_description']): ?>
                        <div class="book-description">
                            <div class="description-title">
                                <i class="fas fa-align-left"></i>
                                Description
                            </div>
                            <div class="description-text">
                                <?php echo nl2br(htmlspecialchars($book['book_description'])); ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="action-buttons">
                            <?php if ($book['bookStatus'] === 'available'): ?>
                                <button class="btn btn-primary" onclick="borrowBook(<?php echo $book_id; ?>)">
                                    <i class="fas fa-book-open"></i>
                                    Borrow This Book
                                </button>
                                <button class="btn btn-outline" disabled>
                                    <i class="fas fa-bookmark"></i>
                                    Reserve (Not Needed)
                                </button>
                            <?php elseif ($book['bookStatus'] === 'borrowed'): ?>
                                <button class="btn btn-primary" disabled>
                                    <i class="fas fa-book-open"></i>
                                    Currently Borrowed
                                </button>
                                <button class="btn btn-outline" onclick="reserveBook(<?php echo $book_id; ?>)">
                                    <i class="fas fa-bookmark"></i>
                                    Reserve This Book
                                </button>
                            <?php else: ?>
                                <button class="btn btn-primary" disabled>
                                    <i class="fas fa-book-open"></i>
                                    Unavailable
                                </button>
                                <button class="btn btn-outline" disabled>
                                    <i class="fas fa-bookmark"></i>
                                    Cannot Reserve
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Related Books Section -->
                <?php if ($related_books->num_rows > 0): ?>
                <div class="related-books-section">
                    <h2 class="section-title">
                        <i class="fas fa-books"></i>
                        Related Books
                    </h2>
                    <div class="related-books-grid">
                        <?php while ($related = $related_books->fetch_assoc()): ?>
                        <div class="related-book-card" onclick="goToBook(<?php echo $related['bookID']; ?>)">
                            <?php if ($related['book_image'] && $related['book_image_mime']): ?>
                                <img src="data:<?php echo $related['book_image_mime']; ?>;base64,<?php echo base64_encode($related['book_image']); ?>" 
                                     alt="<?php echo htmlspecialchars($related['bookTitle']); ?>" 
                                     class="related-book-image">
                            <?php else: ?>
                                <div class="related-book-placeholder">
                                    <i class="fas fa-book"></i>
                                </div>
                            <?php endif; ?>
                            <div class="related-book-content">
                                <h3 class="related-book-title"><?php echo htmlspecialchars($related['bookTitle']); ?></h3>
                                <div class="related-book-author"><?php echo htmlspecialchars($related['bookAuthor'] ?: 'Unknown Author'); ?></div>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </section>
    </main>

    <script>
        // Toggle sidebar
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
        }

        // Show loading overlay
        function showLoading() {
            document.getElementById('loadingOverlay').style.display = 'flex';
        }

        // Hide loading overlay
        function hideLoading() {
            document.getElementById('loadingOverlay').style.display = 'none';
        }

        // Show alert message
        function showAlert(message, type = 'info') {
            const alertContainer = document.getElementById('alertContainer');
            const alertId = 'alert-' + Date.now();
            
            const alertTypes = {
                'success': { icon: 'fas fa-check-circle', class: 'alert-success' },
                'error': { icon: 'fas fa-exclamation-circle', class: 'alert-error' },
                'info': { icon: 'fas fa-info-circle', class: 'alert-info' }
            };
            
            const alertType = alertTypes[type] || alertTypes['info'];
            
            const alertHtml = `
                <div class="alert ${alertType.class}" id="${alertId}">
                    <i class="${alertType.icon}"></i>
                    <span>${message}</span>
                </div>
            `;
            
            alertContainer.insertAdjacentHTML('beforeend', alertHtml);
            
            // Auto remove after 5 seconds
            setTimeout(() => {
                const alertElement = document.getElementById(alertId);
                if (alertElement) {
                    alertElement.style.opacity = '0';
                    alertElement.style.transform = 'translateY(-20px)';
                    setTimeout(() => {
                        alertElement.remove();
                    }, 300);
                }
            }, 5000);
        }

        // Borrow book function
        async function borrowBook(bookId) {
            if (confirm('Are you sure you want to borrow this book?')) {
                showLoading();
                
                try {
                    const formData = new FormData();
                    formData.append('action', 'borrow');
                    formData.append('book_id', bookId);
                    
                    const response = await fetch('', {
                        method: 'POST',
                        body: formData
                    });
                    
                    const data = await response.json();
                    
                    if (data.success) {
                        showAlert(data.message, 'success');
                        setTimeout(() => {
                            if (data.redirect) {
                                window.location.href = data.redirect;
                            } else {
                                location.reload();
                            }
                        }, 2000);
                    } else {
                        showAlert(data.message, 'error');
                    }
                } catch (error) {
                    console.error('Error:', error);
                    showAlert('Failed to borrow book. Please try again.', 'error');
                } finally {
                    hideLoading();
                }
            }
        }

        // Reserve book function
        async function reserveBook(bookId) {
            if (confirm('Are you sure you want to reserve this book? You will be notified when it becomes available.')) {
                showLoading();
                
                try {
                    const formData = new FormData();
                    formData.append('action', 'reserve');
                    formData.append('book_id', bookId);
                    
                    const response = await fetch('', {
                        method: 'POST',
                        body: formData
                    });
                    
                    const data = await response.json();
                    
                    if (data.success) {
                        showAlert(data.message, 'success');
                        setTimeout(() => {
                            if (data.redirect) {
                                window.location.href = data.redirect;
                            } else {
                                location.reload();
                            }
                        }, 2000);
                    } else {
                        showAlert(data.message, 'error');
                    }
                } catch (error) {
                    console.error('Error:', error);
                    showAlert('Failed to reserve book. Please try again.', 'error');
                } finally {
                    hideLoading();
                }
            }
        }

        // Navigate to book
        function goToBook(bookId) {
            window.location.href = `student_book_detail.php?id=${bookId}`;
        }

        // Initialize page
        document.addEventListener('DOMContentLoaded', function() {
            // Add entrance animations
            const bookDetailSection = document.querySelector('.book-detail-section');
            bookDetailSection.style.animation = 'fadeInUp 0.8s ease';
            
            // Add stagger animation to detail items
            const detailItems = document.querySelectorAll('.detail-item');
            detailItems.forEach((item, index) => {
                item.style.opacity = '0';
                item.style.transform = 'translateY(20px)';
                setTimeout(() => {
                    item.style.transition = 'all 0.5s ease';
                    item.style.opacity = '1';
                    item.style.transform = 'translateY(0)';
                }, index * 100);
            });
            
            // Add animation to related books
            const relatedCards = document.querySelectorAll('.related-book-card');
            relatedCards.forEach((card, index) => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(30px)';
                setTimeout(() => {
                    card.style.transition = 'all 0.6s ease';
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, (index + 5) * 150);
            });
        });

        // Handle keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                window.location.href = 'student_search_books.php';
            }
        });

        // Handle browser back button
        window.addEventListener('popstate', function() {
            window.location.href = 'student_search_books.php';
        });
    </script>
</body>
</html>