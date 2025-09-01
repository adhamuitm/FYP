<?php
session_start();
require_once 'dbconnect.php';
require_once 'auth_helper.php';

// Check if user is logged in and is a student
checkPageAccess();
requireRole('student');

// Check if it's first login (password change required)
$must_change_password = isset($_SESSION['must_change_password']) && $_SESSION['must_change_password'];

// Get student information
$student_name = getUserDisplayName();
$student_id = $_SESSION['student_id'];
$user_id = $_SESSION['user_id'];

// Handle password change
if (isset($_POST['action']) && $_POST['action'] === 'change_password') {
    header('Content-Type: application/json');
    
    $response = ['success' => false, 'message' => ''];
    
    $current_password = trim($_POST['current_password'] ?? '');
    $new_password = trim($_POST['new_password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');
    
    // Validate input
    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $response['message'] = 'Please fill in all password fields.';
    } elseif ($new_password !== $confirm_password) {
        $response['message'] = 'New passwords do not match.';
    } elseif (strlen($new_password) < 6) {
        $response['message'] = 'New password must be at least 6 characters long.';
    } elseif ($new_password === $current_password) {
        $response['message'] = 'New password must be different from current password.';
    } else {
        try {
            // Get current password
            $password_query = "SELECT studentPassword FROM student WHERE userID = ?";
            $stmt = $conn->prepare($password_query);
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $stored_password = $stmt->get_result()->fetch_assoc()['studentPassword'];
            
            // Verify current password
            $password_valid = false;
            if (password_verify($current_password, $stored_password)) {
                $password_valid = true;
            } elseif ($current_password === $stored_password) {
                $password_valid = true;
            }
            
            if (!$password_valid) {
                $response['message'] = 'Current password is incorrect.';
            } else {
                // Hash new password and update
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                
                $conn->begin_transaction();
                
                try {
                    // Update student table
                    $update_student_query = "UPDATE student SET studentPassword = ?, updated_date = NOW() WHERE userID = ?";
                    $stmt = $conn->prepare($update_student_query);
                    $stmt->bind_param("si", $hashed_password, $user_id);
                    $stmt->execute();
                    
                    // Update user table
                    $update_user_query = "UPDATE user SET password = ?, must_change_password = FALSE, updated_date = NOW() WHERE userID = ?";
                    $stmt = $conn->prepare($update_user_query);
                    $stmt->bind_param("si", $hashed_password, $user_id);
                    $stmt->execute();
                    
                    // Remove must_change_password from session
                    unset($_SESSION['must_change_password']);
                    
                    // Log activity
                    $log_query = "INSERT INTO user_activity_log (userID, action, description) VALUES (?, 'password_change', 'Changed password on first login')";
                    $stmt = $conn->prepare($log_query);
                    $stmt->bind_param("i", $user_id);
                    $stmt->execute();
                    
                    $conn->commit();
                    
                    $response['success'] = true;
                    $response['message'] = 'Password changed successfully! Welcome to the library system!';
                    
                } catch (Exception $e) {
                    $conn->rollback();
                    $response['message'] = 'Error changing password. Please try again.';
                    error_log("Password change transaction error: " . $e->getMessage());
                }
            }
        } catch (Exception $e) {
            $response['message'] = 'An error occurred. Please try again.';
            error_log("Password change error: " . $e->getMessage());
        }
    }
    
    echo json_encode($response);
    exit;
}

// Get dashboard statistics
try {
    // Current borrowed books
    $borrowed_query = "SELECT COUNT(*) as total FROM borrow WHERE userID = ? AND borrow_status = 'borrowed'";
    $stmt = $conn->prepare($borrowed_query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $borrowed_count = $stmt->get_result()->fetch_assoc()['total'];

    // Overdue books
    $overdue_query = "SELECT COUNT(*) as total FROM borrow WHERE userID = ? AND borrow_status = 'borrowed' AND due_date < CURDATE()";
    $stmt = $conn->prepare($overdue_query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $overdue_count = $stmt->get_result()->fetch_assoc()['total'];

    // Active reservations
    $reservations_query = "SELECT COUNT(*) as total FROM reservation WHERE userID = ? AND reservation_status = 'active'";
    $stmt = $conn->prepare($reservations_query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $reservations_count = $stmt->get_result()->fetch_assoc()['total'];

    // Outstanding fines
    $fines_query = "SELECT COALESCE(SUM(balance_due), 0) as total FROM fines WHERE userID = ? AND payment_status = 'unpaid'";
    $stmt = $conn->prepare($fines_query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $outstanding_fines = $stmt->get_result()->fetch_assoc()['total'];

    // Get notifications count
    $notifications_query = "SELECT COUNT(*) as total FROM notifications WHERE userID = ? AND read_status = FALSE";
    $stmt = $conn->prepare($notifications_query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $notifications_count = $stmt->get_result()->fetch_assoc()['total'];

    // Get student reservations with queue position
    $reservations_query = "
        SELECT 
            r.reservationID, 
            r.reservation_date, 
            r.expiry_date, 
            r.queue_position, 
            r.reservation_status,
            r.self_pickup_deadline,
            b.bookID, 
            b.bookTitle, 
            b.bookAuthor, 
            b.book_ISBN,
            bc.categoryName,
            (SELECT COUNT(*) FROM reservation r2 
             WHERE r2.bookID = r.bookID AND r2.reservation_status = 'active' 
             AND r2.reservation_date <= r.reservation_date) as queue_size
        FROM reservation r
        JOIN book b ON r.bookID = b.bookID
        JOIN book_category bc ON b.categoryID = bc.categoryID
        WHERE r.userID = ? 
        AND r.reservation_status IN ('active', 'ready')
        ORDER BY r.reservation_date DESC
    ";
    $stmt = $conn->prepare($reservations_query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $reservations = $stmt->get_result();

    // Subject categories with book counts
    $subjects_query = "
        SELECT c.categoryName, COUNT(b.bookID) as book_count
        FROM book_category c
        LEFT JOIN book b ON c.categoryID = b.categoryID AND b.bookStatus != 'disposed'
        GROUP BY c.categoryID
        ORDER BY book_count DESC
        LIMIT 6
    ";
    $subjects = $conn->query($subjects_query);

} catch (Exception $e) {
    error_log("Dashboard query error: " . $e->getMessage());
    $borrowed_count = $overdue_count = $reservations_count = $outstanding_fines = $notifications_count = 0;
    $reservations = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard - SMK Chendering Library</title>
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
            background: #ffffff;
            color: #333;
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
            background: #1a202c;
            border-right: 1px solid #2d3748;
            z-index: 1000;
            transition: all 0.3s ease;
            overflow-y: auto;
        }

        .sidebar.collapsed {
            width: 80px;
        }

        .sidebar.disabled {
            pointer-events: none;
            opacity: 0.5;
        }

        .sidebar-header {
            padding: 1.5rem;
            border-bottom: 1px solid #2d3748;
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
            transition: all 0.2s ease;
            border-radius: 0;
            position: relative;
        }

        .menu-item:hover,
        .menu-item.active {
            background: #2d3748;
            color: #e2e8f0;
        }

        .menu-item.active::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 3px;
            background: #667eea;
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
            background: #ffffff;
        }

        .main-content.expanded {
            margin-left: 80px;
        }

        .main-content.disabled {
            filter: blur(3px);
            pointer-events: none;
        }

        /* Header */
        .header {
            background: #f8fafc;
            padding: 1rem 2rem;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 100;
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
            transition: all 0.2s ease;
        }

        .toggle-sidebar:hover {
            background: #edf2f7;
            color: #2d3748;
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
            transition: all 0.2s ease;
        }

        .notification-btn:hover {
            background: #edf2f7;
            color: #2d3748;
        }

        .notification-badge {
            position: absolute;
            top: 0.25rem;
            right: 0.25rem;
            background: #e53e3e;
            color: white;
            border-radius: 50%;
            width: 18px;
            height: 18px;
            font-size: 0.7rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            background: #edf2f7;
            padding: 0.5rem 1rem;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .user-menu:hover {
            background: #e2e8f0;
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

        /* Dashboard Content */
        .dashboard-content {
            padding: 2rem;
        }

        /* Stats Overview */
        .stats-overview {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 1.5rem;
            transition: all 0.2s ease;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
        }

        .stat-card:hover {
            border-color: #cbd5e0;
            transform: translateY(-2px);
            box-shadow: 0 10px 15px rgba(0, 0, 0, 0.1);
        }

        .stat-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1rem;
        }

        .stat-title {
            font-size: 0.875rem;
            font-weight: 500;
            color: #718096;
        }

        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            color: white;
        }

        .stat-icon.borrowed { background: linear-gradient(135deg, #667eea, #764ba2); }
        .stat-icon.overdue { background: linear-gradient(135deg, #f56565, #e53e3e); }
        .stat-icon.reservations { background: linear-gradient(135deg, #38b2ac, #319795); }
        .stat-icon.fines { background: linear-gradient(135deg, #ed8936, #dd6b20); }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 0.25rem;
        }

        .stat-change {
            font-size: 0.8rem;
            color: #38a169;
        }

         /* Main Grid */
        .main-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 2rem;
        }

        /* Section Styles */
        .section {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
        }

        .section-header {
            padding: 1.5rem;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #f8fafc;
        }

        .section-title {
            font-size: 1.125rem;
            font-weight: 600;
            color: #2d3748;
        }

        .view-all-btn {
            color: #667eea;
            text-decoration: none;
            font-size: 0.875rem;
            font-weight: 500;
            transition: color 0.2s ease;
        }

        .view-all-btn:hover {
            color: #5a67d8;
        }

        /* Quick Book Reservation */
        .reservations-grid {
            padding: 1.5rem;
            display: grid;
            gap: 1.5rem;
        }

        .reservation-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 1.5rem;
            transition: all 0.2s ease;
        }

        .reservation-card:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .reservation-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .reservation-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 0.5rem;
        }

        .reservation-author {
            font-size: 0.9rem;
            color: #718096;
            margin-bottom: 0.5rem;
        }

        .reservation-details {
            display: flex;
            gap: 1rem;
            font-size: 0.85rem;
            color: #718096;
        }

        .reservation-status {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-block;
        }

        .status-ready {
            background: rgba(104, 211, 145, 0.2);
            color: #38a169;
        }

        .status-queue {
            background: rgba(237, 137, 54, 0.2);
            color: #dd6b20;
        }

        .reservation-queue {
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid #e2e8f0;
        }

        .queue-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }

        .queue-label {
            font-size: 0.9rem;
            color: #718096;
        }

        .queue-position {
            font-weight: 600;
            color: #2d3748;
        }

        .queue-total {
            font-size: 0.8rem;
            color: #a0aec0;
        }

        .reservation-actions {
            display: flex;
            gap: 0.75rem;
            margin-top: 1rem;
        }

        .action-button {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-primary {
            background: #667eea;
            color: white;
        }

        .btn-primary:hover {
            background: #5a67d8;
        }

        .btn-secondary {
            background: #e2e8f0;
            color: #4a5568;
        }

        .btn-secondary:hover {
            background: #cbd5e0;
        }

        .btn-danger {
            background: #fc8181;
            color: white;
        }

        .btn-danger:hover {
            background: #f56565;
        }

        /* Subject Categories */
        .subjects-grid {
            padding: 1.5rem;
            display: grid;
            gap: 1rem;
        }

        .subject-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1rem;
            background: #f8fafc;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .subject-item:hover {
            background: #edf2f7;
        }

        .subject-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .subject-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.1rem;
        }

        .subject-name {
            font-weight: 500;
            color: #2d3748;
        }

        .subject-count {
            font-size: 0.9rem;
            font-weight: 600;
            color: #667eea;
        }

        /* Quick Actions */
        .quick-actions {
            display: grid;
            gap: 0.75rem;
            padding: 1.5rem;
        }

        .action-btn {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            background: #f8fafc;
            border: none;
            border-radius: 12px;
            color: #4a5568;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 0.9rem;
        }

        .action-btn:hover {
            background: #edf2f7;
            transform: translateX(4px);
        }

        .action-btn i {
            color: #667eea;
            font-size: 1.1rem;
        }

        /* First Time User Modal */
        .first-time-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(10px);
            z-index: 3000;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            padding: 3rem;
            border-radius: 20px;
            max-width: 500px;
            width: 90%;
            text-align: center;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.25);
        }

        .modal-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 2rem;
            font-size: 2rem;
            color: white;
        }

        .modal-content h3 {
            font-size: 1.5rem;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 1rem;
        }

        .modal-content p {
            color: #718096;
            margin-bottom: 2rem;
            line-height: 1.6;
        }

        .password-form {
            text-align: left;
            margin-bottom: 2rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #2d3748;
            font-size: 0.9rem;
        }

        .form-group input {
            width: 100%;
            padding: 0.875rem;
            background: #ffffff;
            border: 1px solid #cbd5e0;
            border-radius: 8px;
            color: #2d3748;
            font-size: 0.9rem;
            transition: all 0.2s ease;
        }

        .form-group input::placeholder {
            color: #a0aec0;
        }

        .form-group input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .modal-buttons {
            display: flex;
            gap: 1rem;
            justify-content: center;
        }

        .btn {
            padding: 0.875rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 0.9rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        /* Loading Animation */
        .loading {
            display: none;
        }

        .loading.active {
            display: inline-block;
            margin-left: 0.5rem;
        }

        /* Alert Messages */
        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            border-left: 4px solid;
        }

        .alert-success {
            background: rgba(104, 211, 145, 0.1);
            border-color: #38a169;
            color: #38a169;
        }

        .alert-danger {
            background: rgba(245, 101, 101, 0.1);
            border-color: #e53e3e;
            color: #e53e3e;
        }

        .alert-warning {
            background: rgba(237, 137, 54, 0.1);
            border-color: #ed8936;
            color: #ed8936;
        }

        /* Responsive Design */
        @media (max-width: 1024px) {
            .main-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                width: 80px;
            }

            .main-content {
                margin-left: 80px;
            }

            .header {
                padding: 1rem;
            }

            .dashboard-content {
                padding: 1rem;
            }

            .stats-overview {
                grid-template-columns: repeat(2, 1fr);
            }

            .user-name {
                display: none;
            }
        }

        @media (max-width: 480px) {
            .stats-overview {
                grid-template-columns: 1fr;
            }
            
            .reservation-header {
                flex-direction: column;
            }
            
            .reservation-actions {
                flex-direction: column;
            }
            
            .action-button {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <!-- First Time User Modal (Password Change) -->
    <?php if ($must_change_password): ?>
    <div class="first-time-modal" id="passwordModal">
        <div class="modal-content">
            <div class="modal-icon">
                <i class="fas fa-key"></i>
            </div>
            <h3>Welcome to SMK Chendering Library!</h3>
            <p>For security reasons, you must change your default password before accessing the library system.</p>
            
            <form class="password-form" id="passwordChangeForm">
                <div class="form-group">
                    <label for="current_password">Current Password</label>
                    <input type="password" id="current_password" name="current_password" required 
                           placeholder="Enter your current password" value="pass123*">
                </div>

                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <input type="password" id="new_password" name="new_password" required 
                           placeholder="Enter new password (min 6 characters)">
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm New Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" required 
                           placeholder="Confirm your new password">
                </div>

                <div class="modal-buttons">
                    <button type="submit" class="btn btn-primary" id="changePasswordBtn">
                        <i class="fas fa-save"></i> Change Password
                        <span class="loading" id="passwordLoading">
                            <i class="fas fa-spinner fa-spin"></i>
                        </span>
                    </button>
                </div>

                <input type="hidden" name="action" value="change_password">
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Sidebar -->
    <nav class="sidebar <?php echo $must_change_password ? 'disabled' : ''; ?>" id="sidebar">
        <div class="sidebar-header">
            <div class="school-logo">SMK</div>
            <div class="school-info">
                <h3>SMK Chendering</h3>
                <p>Student Portal</p>
            </div>
        </div>
        
        <div class="sidebar-menu">
            <a href="student_dashboard.php" class="menu-item active">
                <i class="fas fa-home"></i>
                <span>Dashboard</span>
            </a>
            
            <a href="student_search_books.php" class="menu-item">
                <i class="fas fa-search"></i>
                <span>Search / Book Catalogue</span>
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
    <main class="main-content <?php echo $must_change_password ? 'disabled' : ''; ?>" id="mainContent">
        <!-- Header -->
        <header class="header">
            <div class="header-left">
                <button class="toggle-sidebar" onclick="toggleSidebar()">
                    <i class="fas fa-bars"></i>
                </button>
                
                <h2>Student Dashboard</h2>
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
                
                <a href="logout.php" class="action-btn" style="padding: 0.5rem 1rem;">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </header>

        <!-- Dashboard Content -->
        <div class="dashboard-content">
            <!-- Alert placeholder -->
            <div id="alertContainer"></div>

            <!-- Alert Messages -->
            <?php if (isset($_GET['login_success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    Welcome! You have successfully logged in to the library system.
                </div>
            <?php endif; ?>

            <?php if ($overdue_count > 0): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    You have <?php echo $overdue_count; ?> overdue book(s). Please return them as soon as possible.
                </div>
            <?php endif; ?>

            <?php if ($outstanding_fines > 0): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-money-bill-wave"></i>
                    You have outstanding fines of RM <?php echo number_format($outstanding_fines, 2); ?>. Please settle your fines to continue borrowing.
                </div>
            <?php endif; ?>

            <!-- Stats Overview -->
            <div class="stats-overview">
                <div class="stat-card">
                    <div class="stat-header">
                        <span class="stat-title">Books Borrowed</span>
                        <div class="stat-icon borrowed">
                            <i class="fas fa-book-open"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo $borrowed_count; ?></div>
                    <div class="stat-change">Currently active</div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <span class="stat-title">Overdue Books</span>
                        <div class="stat-icon overdue">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo $overdue_count; ?></div>
                    <div class="stat-change">Need attention</div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <span class="stat-title">Active Reservations</span>
                        <div class="stat-icon reservations">
                            <i class="fas fa-bookmark"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo $reservations_count; ?></div>
                    <div class="stat-change">In queue</div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <span class="stat-title">Outstanding Fines</span>
                        <div class="stat-icon fines">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                    </div>
                    <div class="stat-value">RM <?php echo number_format($outstanding_fines, 2); ?></div>
                    <div class="stat-change">Unpaid balance</div>
                </div>
            </div>

            <!-- Main Grid -->
            <div class="main-grid">
                <!-- Left Column -->
                <div class="left-column">
                    <!-- Quick Book Reservation Section -->
                    <section class="section">
                        <div class="section-header">
                            <h3 class="section-title">Quick Book Reservation</h3>
                            <a href="student_borrowing_reservations.php" class="view-all-btn">View All</a>
                        </div>
                        
                        <div class="reservations-grid">
                            <?php if ($reservations && $reservations->num_rows > 0): ?>
                                <?php while ($reservation = $reservations->fetch_assoc()): ?>
                                    <div class="reservation-card">
                                        <div class="reservation-header">
                                            <div>
                                                <h4 class="reservation-title"><?php echo htmlspecialchars($reservation['bookTitle']); ?></h4>
                                                <p class="reservation-author">by <?php echo htmlspecialchars($reservation['bookAuthor']); ?></p>
                                                <div class="reservation-details">
                                                    <span>ISBN: <?php echo htmlspecialchars($reservation['book_ISBN']); ?></span>
                                                    <span>Category: <?php echo htmlspecialchars($reservation['categoryName']); ?></span>
                                                </div>
                                            </div>
                                            <span class="reservation-status <?php echo $reservation['reservation_status'] === 'ready' ? 'status-ready' : 'status-queue'; ?>">
                                                <?php echo ucfirst($reservation['reservation_status']); ?>
                                            </span>
                                        </div>
                                        
                                        <div class="reservation-queue">
                                            <div class="queue-info">
                                                <span class="queue-label">Your position in queue:</span>
                                                <span class="queue-position">#<?php echo $reservation['queue_position']; ?></span>
                                            </div>
                                            <div class="queue-total">Out of <?php echo $reservation['queue_size']; ?> total reservations</div>
                                        </div>
                                        
                                        <div class="reservation-actions">
                                            <?php if ($reservation['reservation_status'] === 'ready'): ?>
                                                <button class="action-button btn-primary" onclick="pickupBook(<?php echo $reservation['reservationID']; ?>)">
                                                    <i class="fas fa-shopping-bag"></i> Pick Up
                                                </button>
                                                <button class="action-button btn-secondary" onclick="cancelReservation(<?php echo $reservation['reservationID']; ?>)">
                                                    <i class="fas fa-times"></i> Cancel
                                                </button>
                                            <?php else: ?>
                                                <button class="action-button btn-secondary" onclick="viewBookDetails(<?php echo $reservation['bookID']; ?>)">
                                                    <i class="fas fa-eye"></i> View Details
                                                </button>
                                                <button class="action-button btn-danger" onclick="cancelReservation(<?php echo $reservation['reservationID']; ?>)">
                                                    <i class="fas fa-times"></i> Cancel
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <div style="text-align: center; padding: 2rem; color: #718096;">
                                    <i class="fas fa-book" style="font-size: 3rem; margin-bottom: 1rem;"></i>
                                    <p>You don't have any active reservations.</p>
                                    <a href="student_search_books.php" class="action-button btn-primary" style="display: inline-flex; margin-top: 1rem;">
                                        <i class="fas fa-search"></i> Browse Books
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </section>
                </div>
                
            </div>
        </div>
    </main>

    <script>
        // Toggle sidebar
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
        }

        // Password change form handling
        document.addEventListener('DOMContentLoaded', function() {
            <?php if ($must_change_password): ?>
            const passwordForm = document.getElementById('passwordChangeForm');
            const changePasswordBtn = document.getElementById('changePasswordBtn');
            const passwordLoading = document.getElementById('passwordLoading');
            
            passwordForm.addEventListener('submit', async function(e) {
                e.preventDefault();
                
                const formData = new FormData(passwordForm);
                changePasswordBtn.disabled = true;
                passwordLoading.classList.add('active');
                
                try {
                    const response = await fetch('student_dashboard.php', {
                        method: 'POST',
                        body: formData
                    });
                    
                    const result = await response.json();
                    
                    if (result.success) {
                        showAlert('success', result.message);
                        setTimeout(() => {
                            window.location.reload();
                        }, 2000);
                    } else {
                        showAlert('error', result.message);
                        changePasswordBtn.disabled = false;
                        passwordLoading.classList.remove('active');
                    }
                } catch (error) {
                    showAlert('error', 'An error occurred. Please try again.');
                    changePasswordBtn.disabled = false;
                    passwordLoading.classList.remove('active');
                }
            });
            <?php endif; ?>
        });

        // Show alert message
        function showAlert(type, message) {
            const alertContainer = document.getElementById('alertContainer');
            const alertClass = type === 'success' ? 'alert-success' : 'alert-danger';
            
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert ${alertClass}`;
            alertDiv.innerHTML = `
                <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i>
                ${message}
            `;
            
            alertContainer.appendChild(alertDiv);
            
            // Auto remove after 5 seconds
            setTimeout(() => {
                alertDiv.remove();
            }, 5000);
        }

        // Placeholder functions for reservation actions
        function pickupBook(reservationId) {
            alert(`Pick up functionality for reservation #${reservationId} would be implemented here.`);
        }

        function cancelReservation(reservationId) {
            if (confirm('Are you sure you want to cancel this reservation?')) {
                alert(`Cancel functionality for reservation #${reservationId} would be implemented here.`);
            }
        }

        function viewBookDetails(bookId) {
            window.location.href = `student_book_details.php?id=${bookId}`;
        }
    </script>
</body>
</html>