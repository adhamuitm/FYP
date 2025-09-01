<?php
session_start();
require_once 'dbconnect.php';
require_once 'auth_helper.php';
checkPageAccess();
requireRole('librarian');

// Get librarian information
$librarian_info = getCurrentUser();
$librarian_name = getUserDisplayName();

// Get library settings for dynamic configuration
$library_settings = [];
try {
    $settings_query = "SELECT setting_name, setting_value FROM library_settings";
    $settings_result = $conn->query($settings_query);
    while ($row = $settings_result->fetch_assoc()) {
        $library_settings[$row['setting_name']] = $row['setting_value'];
    }
} catch (Exception $e) {
    error_log("Settings query error: " . $e->getMessage());
}

// Get dashboard statistics
try {
    // Total Books
    $total_books_query = "SELECT COUNT(*) as total FROM book WHERE bookStatus != 'disposed'";
    $total_books_result = $conn->query($total_books_query);
    $total_books = $total_books_result->fetch_assoc()['total'];
    
    // Books Borrowed
    $books_borrowed_query = "SELECT COUNT(*) as total FROM borrow WHERE borrow_status = 'borrowed'";
    $books_borrowed_result = $conn->query($books_borrowed_query);
    $books_borrowed = $books_borrowed_result->fetch_assoc()['total'];
    
    // Overdue Books
    $overdue_books_query = "SELECT COUNT(*) as total FROM borrow WHERE borrow_status = 'borrowed' AND due_date < CURDATE()";
    $overdue_books_result = $conn->query($overdue_books_query);
    $overdue_books = $overdue_books_result->fetch_assoc()['total'];
    
    // Active Users
    $active_users_query = "SELECT COUNT(*) as total FROM user WHERE account_status = 'active' AND user_type != 'librarian'";
    $active_users_result = $conn->query($active_users_query);
    $active_users = $active_users_result->fetch_assoc()['total'];
    
    // Fines Collected
    $fines_collected_query = "SELECT COALESCE(SUM(amount_paid), 0) as total FROM fines WHERE payment_status IN ('paid_cash', 'partial_paid')";
    $fines_collected_result = $conn->query($fines_collected_query);
    $fines_collected = $fines_collected_result->fetch_assoc()['total'];
    
    // Recent Activities with proper joins
    $recent_activities_query = "
        SELECT 
            b.borrowID,
            u.first_name,
            u.last_name,
            u.user_type,
            bk.bookTitle,
            b.borrow_date,
            b.due_date,
            b.borrow_status,
            'borrow' as activity_type,
            b.created_date
        FROM borrow b
        JOIN user u ON b.userID = u.userID
        JOIN book bk ON b.bookID = bk.bookID
        WHERE b.created_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        UNION ALL
        SELECT 
            r.reservationID as borrowID,
            u.first_name,
            u.last_name,
            u.user_type,
            bk.bookTitle,
            DATE(r.reservation_date) as borrow_date,
            NULL as due_date,
            r.reservation_status as borrow_status,
            'reservation' as activity_type,
            r.created_date
        FROM reservation r
        JOIN user u ON r.userID = u.userID
        JOIN book bk ON r.bookID = bk.bookID
        WHERE r.created_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ORDER BY created_date DESC
        LIMIT 10
    ";
    $recent_activities_result = $conn->query($recent_activities_query);
    
} catch (Exception $e) {
    error_log("Dashboard query error: " . $e->getMessage());
    $total_books = $books_borrowed = $overdue_books = $active_users = $fines_collected = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Librarian Dashboard | SMK Chendering Library</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            /* Professional Education-Themed Color Palette */
            --primary: #1e3a8a;
            --primary-light: #3b82f6;
            --primary-dark: #1d4ed8;
            --secondary: #64748b;
            --accent: #0ea5e9;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --light: #f8fafc;
            --light-gray: #e2e8f0;
            --medium-gray: #94a3b8;
            --dark: #1e293b;
            --card-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --card-shadow-hover: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --border-radius: 0.75rem;
            --transition: all 0.2s ease;
            --header-height: 64px;
            --sidebar-width: 260px;
            --sidebar-collapsed-width: 80px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: #f1f5f9;
            color: var(--dark);
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* Header Styles */
        .header {
            height: var(--header-height);
            background: white;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 50;
            display: flex;
            align-items: center;
            padding: 0 1.5rem;
            justify-content: space-between;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .logo-container {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .school-logo {
            width: 40px;
            height: 40px;
            background: url('photo/logo1.png') no-repeat center center;
            background-size: contain;
            border-radius: 8px;
        }

        .logo-text {
            font-weight: 700;
            font-size: 1.25rem;
            color: var(--primary);
            letter-spacing: -0.025em;
        }

        .logo-text span {
            color: var(--primary-light);
        }

        .search-bar {
            background: var(--light);
            border: 1px solid var(--light-gray);
            border-radius: 0.5rem;
            padding: 0.5rem 1rem;
            display: flex;
            align-items: center;
            width: 320px;
            transition: var(--transition);
        }

        .search-bar:focus-within {
            border-color: var(--primary-light);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
        }

        .search-bar input {
            background: transparent;
            border: none;
            width: 100%;
            padding: 0.25rem 0.5rem;
            font-family: 'Inter', sans-serif;
            font-size: 0.95rem;
            color: var(--dark);
            outline: none;
        }

        .search-bar i {
            color: var(--medium-gray);
            margin-right: 0.5rem;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 1.25rem;
        }

        .notification-badge {
            position: relative;
        }

        .notification-icon {
            font-size: 1.25rem;
            color: var(--secondary);
            cursor: pointer;
            position: relative;
        }

        .notification-count {
            position: absolute;
            top: -6px;
            right: -6px;
            background: var(--danger);
            color: white;
            font-size: 0.65rem;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            cursor: pointer;
        }

        .user-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.9rem;
        }

        /* Sidebar Styles */
        .sidebar {
            position: fixed;
            left: 0;
            top: var(--header-height);
            bottom: 0;
            width: var(--sidebar-width);
            background: white;
            border-right: 1px solid var(--light-gray);
            padding: 1.5rem 0;
            z-index: 40;
            transition: var(--transition);
            overflow-y: auto;
            height: calc(100vh - var(--header-height));
        }

        .sidebar.collapsed {
            width: var(--sidebar-collapsed-width);
        }

        .sidebar.collapsed .menu-item span {
            display: none;
        }

        .sidebar.collapsed .menu-item {
            justify-content: center;
            padding: 0.85rem;
        }

        .sidebar.collapsed .menu-item i {
            margin-right: 0;
        }

        .sidebar.collapsed .sidebar-footer {
            display: none;
        }

        .menu-item {
            display: flex;
            align-items: center;
            padding: 0.75rem 1.5rem;
            color: var(--secondary);
            text-decoration: none;
            transition: var(--transition);
            border-left: 3px solid transparent;
            gap: 0.85rem;
        }

        .menu-item:hover {
            color: var(--primary);
            background: rgba(30, 58, 138, 0.05);
        }

        .menu-item.active {
            color: var(--primary);
            border-left-color: var(--primary);
            font-weight: 500;
            background: rgba(30, 58, 138, 0.05);
        }

        .menu-item i {
            width: 20px;
            text-align: center;
            font-size: 1.1rem;
        }

        .menu-item span {
            font-size: 0.95rem;
            font-weight: 500;
        }

        .sidebar-footer {
            padding: 1.5rem;
            border-top: 1px solid var(--light-gray);
            margin-top: auto;
        }

        .sidebar-footer p {
            font-size: 0.85rem;
            color: var(--medium-gray);
            line-height: 1.4;
        }

        .sidebar-footer p span {
            color: var(--primary);
            font-weight: 500;
        }

        /* Main Content */
        .main-content {
            margin-left: var(--sidebar-width);
            margin-top: var(--header-height);
            min-height: calc(100vh - var(--header-height));
            padding: 1.5rem;
            transition: var(--transition);
        }

        .main-content.collapsed {
            margin-left: var(--sidebar-collapsed-width);
        }

        /* Dashboard Content */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.75rem;
        }

        .page-title {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--primary);
        }

        .welcome-text {
            font-size: 0.95rem;
            color: var(--medium-gray);
            margin-top: 0.25rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: 1.5rem;
            transition: var(--transition);
            border: 1px solid var(--light-gray);
            position: relative;
            overflow: hidden;
        }

        .stat-card:hover {
            box-shadow: var(--card-shadow-hover);
            transform: translateY(-2px);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            height: 4px;
            width: 100%;
            background: var(--primary);
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .stat-title {
            font-size: 0.95rem;
            font-weight: 500;
            color: var(--secondary);
        }

        .stat-icon {
            width: 42px;
            height: 42px;
            border-radius: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
        }

        .stat-icon.books {
            background: rgba(30, 58, 138, 0.1);
            color: var(--primary);
        }

        .stat-icon.borrowed {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .stat-icon.overdue {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .stat-icon.users {
            background: rgba(100, 116, 139, 0.1);
            color: var(--secondary);
        }

        .stat-icon.fines {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        .stat-value {
            font-size: 2.25rem;
            font-weight: 700;
            color: var(--dark);
            line-height: 1.2;
            margin-bottom: 0.25rem;
        }

        .stat-trend {
            display: flex;
            align-items: center;
            font-size: 0.85rem;
            font-weight: 500;
        }

        .trend-up {
            color: var(--success);
        }

        .trend-down {
            color: var(--danger);
        }

        .trend-indicator {
            margin-right: 0.25rem;
        }

        /* Quick Actions */
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 2rem 0 1.25rem;
        }

        .section-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--primary);
        }

        .section-actions a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .section-actions a i {
            font-size: 0.9rem;
        }

        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 1.25rem;
            margin-bottom: 2rem;
        }

        .quick-action-card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: 1.5rem;
            transition: var(--transition);
            border: 1px solid var(--light-gray);
            cursor: pointer;
        }

        .quick-action-card:hover {
            box-shadow: var(--card-shadow-hover);
            transform: translateY(-2px);
            border-color: var(--light-gray);
        }

        .quick-action-icon {
            width: 56px;
            height: 56px;
            border-radius: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1rem;
            font-size: 1.5rem;
        }

        .quick-action-icon.books {
            background: rgba(30, 58, 138, 0.1);
            color: var(--primary);
        }

        .quick-action-icon.users {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .quick-action-icon.borrow {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .quick-action-icon.fines {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        .quick-action-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .quick-action-desc {
            font-size: 0.875rem;
            color: var(--medium-gray);
            line-height: 1.4;
        }

        /* Recent Activities */
        .activity-card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            overflow: hidden;
            border: 1px solid var(--light-gray);
        }

        .activity-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--light-gray);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .activity-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .activity-list {
            max-height: 420px;
            overflow-y: auto;
        }

        .activity-list::-webkit-scrollbar {
            width: 6px;
        }

        .activity-list::-webkit-scrollbar-track {
            background: transparent;
        }

        .activity-list::-webkit-scrollbar-thumb {
            background: var(--medium-gray);
            border-radius: 3px;
        }

        .activity-item {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--light-gray);
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: var(--transition);
        }

        .activity-item:hover {
            background: rgba(30, 58, 138, 0.025);
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .activity-icon.borrow {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .activity-icon.reservation {
            background: rgba(30, 58, 138, 0.1);
            color: var(--primary);
        }

        .activity-info {
            flex: 1;
        }

        .activity-user {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.25rem;
            font-size: 0.95rem;
        }

        .activity-details {
            font-size: 0.9rem;
            color: var(--medium-gray);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .activity-status {
            display: inline-block;
            padding: 0.15rem 0.5rem;
            border-radius: 1rem;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .status-borrowed {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .status-overdue {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .status-reserved {
            background: rgba(30, 58, 138, 0.1);
            color: var(--primary);
        }

        .activity-date {
            font-size: 0.85rem;
            color: var(--medium-gray);
            white-space: nowrap;
        }

        /* Empty State */
        .empty-state {
            padding: 2.5rem 1.5rem;
            text-align: center;
        }

        .empty-icon {
            width: 64px;
            height: 64px;
            border-radius: 1rem;
            background: rgba(30, 58, 138, 0.05);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.25rem;
            font-size: 1.5rem;
            color: var(--primary);
        }

        .empty-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .empty-description {
            color: var(--medium-gray);
            font-size: 0.95rem;
            max-width: 320px;
            margin: 0 auto;
            line-height: 1.5;
        }

        /* System Status Bar */
        .system-status {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            border-top: 1px solid var(--light-gray);
            padding: 0.75rem 1.5rem;
            display: flex;
            justify-content: space-between;
            font-size: 0.85rem;
            color: var(--medium-gray);
            z-index: 30;
        }

        .system-status-left {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .status-indicator {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .status-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
        }

        .status-dot.active {
            background: var(--success);
        }

        .status-dot.warning {
            background: var(--warning);
        }

        /* Toast Notifications */
        .toast-container {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            z-index: 100;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            max-width: 380px;
        }

        .toast {
            background: white;
            border-radius: 0.75rem;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            padding: 1rem 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            border-left: 4px solid var(--primary);
            animation: slideIn 0.3s ease, fadeOut 0.5s 4.5s forwards;
            transition: var(--transition);
        }

        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes fadeOut {
            to {
                opacity: 0;
                transform: translateY(20px);
            }
        }

        .toast-icon {
            width: 28px;
            height: 28px;
            border-radius: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(30, 58, 138, 0.1);
            color: var(--primary);
        }

        .toast-content {
            flex: 1;
        }

        .toast-title {
            font-weight: 600;
            margin-bottom: 0.25rem;
            color: var(--dark);
        }

        .toast-message {
            font-size: 0.875rem;
            color: var(--medium-gray);
        }

        .toast-action {
            background: none;
            border: none;
            color: var(--primary);
            font-weight: 500;
            cursor: pointer;
            padding: 0.25rem 0.5rem;
            border-radius: 0.375rem;
            transition: var(--transition);
            font-size: 0.875rem;
        }

        .toast-action:hover {
            background: rgba(30, 58, 138, 0.05);
        }

        /* Responsive Design */
        @media (max-width: 1280px) {
            .stats-grid {
                grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            }
            
            .quick-actions {
                grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            }
        }

        @media (max-width: 1024px) {
            .search-bar {
                width: 240px;
            }
            
            .sidebar {
                width: var(--sidebar-collapsed-width);
            }
            
            .main-content {
                margin-left: var(--sidebar-collapsed-width);
            }
        }

        @media (max-width: 768px) {
            .search-bar {
                display: none;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .quick-actions {
                grid-template-columns: 1fr;
            }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
        }
    </style>
</head>
<body>
    <!-- Toast Notification Container -->
    <div class="toast-container" id="toastContainer"></div>

    <!-- Header -->
    <header class="header">
        <div class="header-left">
            <button id="sidebarToggle" class="toggle-sidebar">
                <i class="fas fa-bars"></i>
            </button>
            <div class="logo-container">
                <div class="school-logo"></div>
                <div class="logo-text">SMK <span>Chendering</span></div>
            </div>
            <div class="search-bar">
                <i class="fas fa-search"></i>
                <input type="text" placeholder="Search books, users, transactions...">
            </div>
        </div>
        <div class="header-right">
            <div class="notification-badge">
                <i class="fas fa-bell notification-icon"></i>
                <span class="notification-count">3</span>
            </div>
            <div class="user-menu" id="userMenu">
                <div class="user-avatar">
                    <?php echo strtoupper(substr($librarian_name, 0, 1)); ?>
                </div>
                <div class="user-info">
                    <div class="user-name"><?php echo htmlspecialchars($librarian_name); ?></div>
                    <div class="user-role">Librarian</div>
                </div>
            </div>
        </div>
    </header>

    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <nav class="sidebar-menu">
            <a href="librarian_dashboard.php" class="menu-item active">
                <i class="fas fa-home"></i>
                <span>Dashboard</span>
            </a>
            <a href="user_management.php" class="menu-item">
                <i class="fas fa-users"></i>
                <span>User Management</span>
            </a>
            <a href="book_management.php" class="menu-item">
                <i class="fas fa-book"></i>
                <span>Book Management</span>
            </a>
            <a href="circulation_control.php" class="menu-item">
                <i class="fas fa-exchange-alt"></i>
                <span>Circulation Control</span>
            </a>
            <a href="fine_management.php" class="menu-item">
                <i class="fas fa-receipt"></i>
                <span>Fine Management</span>
            </a>
            <a href="report_management.php" class="menu-item">
                <i class="fas fa-chart-bar"></i>
                <span>Reports & Analytics</span>
            </a>
            <a href="system_settings.php" class="menu-item">
                <i class="fas fa-cog"></i>
                <span>System Settings</span>
            </a>
            <a href="notifications.php" class="menu-item">
                <i class="fas fa-bell"></i>
                <span>Notifications</span>
            </a>
            <a href="profile.php" class="menu-item">
                <i class="fas fa-user"></i>
                <span>Profile</span>
            </a>
        </nav>
        <div class="sidebar-footer">
            <p>SMK Chendering Library <span>v1.0</span><br>Library Management System</p>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="main-content" id="mainContent">
        <div class="page-header">
            <div>
                <h1 class="page-title">Dashboard</h1>
                <p class="welcome-text">Welcome back, <?php echo htmlspecialchars($librarian_name); ?>. Here's what's happening today.</p>
            </div>
            <div class="date-display">
                <span><?php echo date('l, F j, Y'); ?></span>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-title">Total Books</div>
                    <div class="stat-icon books">
                        <i class="fas fa-book"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo number_format($total_books); ?></div>
                <div class="stat-trend trend-up">
                    <span class="trend-indicator"><i class="fas fa-arrow-up"></i></span>
                    <span>4.2% from last month</span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-title">Books Borrowed</div>
                    <div class="stat-icon borrowed">
                        <i class="fas fa-book-reader"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo number_format($books_borrowed); ?></div>
                <div class="stat-trend trend-up">
                    <span class="trend-indicator"><i class="fas fa-arrow-up"></i></span>
                    <span>2.1% from yesterday</span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-title">Overdue Books</div>
                    <div class="stat-icon overdue">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo number_format($overdue_books); ?></div>
                <div class="stat-trend trend-down">
                    <span class="trend-indicator"><i class="fas fa-arrow-down"></i></span>
                    <span>0.8% from yesterday</span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-title">Active Users</div>
                    <div class="stat-icon users">
                        <i class="fas fa-users"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo number_format($active_users); ?></div>
                <div class="stat-trend trend-up">
                    <span class="trend-indicator"><i class="fas fa-arrow-up"></i></span>
                    <span>3.5% from last month</span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-title">Fines Collected</div>
                    <div class="stat-icon fines">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                </div>
                <div class="stat-value">RM <?php echo number_format($fines_collected, 2); ?></div>
                <div class="stat-trend trend-up">
                    <span class="trend-indicator"><i class="fas fa-arrow-up"></i></span>
                    <span>12.7% from last month</span>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="section-header">
            <h2 class="section-title">Quick Actions</h2>
            <div class="section-actions">
                <a href="#">
                    <i class="fas fa-ellipsis-h"></i> View All
                </a>
            </div>
        </div>
        <div class="quick-actions">
            <div class="quick-action-card" onclick="location.href='user_management.php?action=register'">
                <div class="quick-action-icon users">
                    <i class="fas fa-user-plus"></i>
                </div>
                <h3 class="quick-action-title">Register New User</h3>
                <p class="quick-action-desc">Add students, staff, or faculty to the library system</p>
            </div>
            <div class="quick-action-card" onclick="location.href='book_management.php?action=add'">
                <div class="quick-action-icon books">
                    <i class="fas fa-plus-circle"></i>
                </div>
                <h3 class="quick-action-title">Add New Book</h3>
                <p class="quick-action-desc">Catalog a new book into the library collection</p>
            </div>
            <div class="quick-action-card" onclick="location.href='circulation_control.php'">
                <div class="quick-action-icon borrow">
                    <i class="fas fa-exchange-alt"></i>
                </div>
                <h3 class="quick-action-title">Process Borrowing</h3>
                <p class="quick-action-desc">Check out books to users or process returns</p>
            </div>
            <div class="quick-action-card" onclick="location.href='fine_management.php'">
                <div class="quick-action-icon fines">
                    <i class="fas fa-receipt"></i>
                </div>
                <h3 class="quick-action-title">Manage Fines</h3>
                <p class="quick-action-desc">Process payments or waive fines as needed</p>
            </div>
        </div>

        <!-- Recent Activities -->
        <div class="section-header">
            <h2 class="section-title">Recent Activities</h2>
            <div class="section-actions">
                <a href="#">
                    <i class="fas fa-sync-alt"></i> Refresh
                </a>
            </div>
        </div>
        <div class="activity-card">
            <div class="activity-header">
                <h3 class="activity-title">
                    <i class="fas fa-clock"></i> Latest Transactions
                </h3>
                <a href="#" class="text-link">
                    View All <i class="fas fa-arrow-right"></i>
                </a>
            </div>
            <div class="activity-list">
                <?php if ($recent_activities_result && $recent_activities_result->num_rows > 0): ?>
                    <?php while ($activity = $recent_activities_result->fetch_assoc()): ?>
                        <div class="activity-item">
                            <div class="activity-icon <?php echo $activity['activity_type']; ?>">
                                <i class="fas fa-<?php echo $activity['activity_type'] === 'borrow' ? 'book-open' : 'bookmark'; ?>"></i>
                            </div>
                            <div class="activity-info">
                                <div class="activity-user">
                                    <?php echo htmlspecialchars($activity['first_name'] . ' ' . $activity['last_name']); ?>
                                </div>
                                <div class="activity-details">
                                    <?php if ($activity['activity_type'] === 'borrow'): ?>
                                        <span><?php echo ucfirst($activity['borrow_status']); ?> "<?php echo htmlspecialchars($activity['bookTitle']); ?>"</span>
                                        <span class="activity-status <?php 
                                            echo $activity['borrow_status'] === 'overdue' ? 'status-overdue' : 'status-borrowed'; 
                                        ?>">
                                            <?php echo ucfirst($activity['borrow_status']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span><?php echo ucfirst($activity['borrow_status']); ?> reservation for "<?php echo htmlspecialchars($activity['bookTitle']); ?>"</span>
                                        <span class="activity-status status-reserved">
                                            <?php echo ucfirst($activity['borrow_status']); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="activity-date">
                                <?php echo date('M j, g:i a', strtotime($activity['borrow_date'])); ?>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-icon">
                            <i class="fas fa-history"></i>
                        </div>
                        <h3 class="empty-title">No Recent Activity</h3>
                        <p class="empty-description">All transactions and activities will appear here as they happen.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- System Status Bar -->
    <div class="system-status">
        <div class="system-status-left">
            <div class="status-indicator">
                <span class="status-dot active"></span>
                <span>System Operational</span>
            </div>
            <div class="status-indicator">
                <span class="status-dot warning"></span>
                <span><?php echo $overdue_books; ?> overdue items</span>
            </div>
        </div>
        <div class="system-status-right">
            <span>Last updated: <?php echo date('h:i A'); ?></span>
        </div>
    </div>

    <script>
        // Professional dashboard functionality
        document.addEventListener('DOMContentLoaded', function() {
            // Sidebar toggle
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            
            sidebarToggle.addEventListener('click', function() {
                sidebar.classList.toggle('collapsed');
                mainContent.classList.toggle('collapsed');
                localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
            });
            
            // Load sidebar state
            if (localStorage.getItem('sidebarCollapsed') === 'true') {
                sidebar.classList.add('collapsed');
                mainContent.classList.add('collapsed');
            }
            
            // Notification click handler
            document.querySelector('.notification-badge').addEventListener('click', function() {
                // In a real system, this would open the notifications panel
                createToast(
                    'New Notification',
                    'You have 3 new notifications requiring attention',
                    'View Notifications'
                );
            });
            
            // Show overdue books notification if needed
            const overdueBooks = <?php echo $overdue_books; ?>;
            if (overdueBooks > 0) {
                createToast(
                    'Overdue Books Alert',
                    `${overdueBooks} book${overdueBooks > 1 ? 's are' : ' is'} currently overdue`,
                    'View Details'
                );
            }
            
            // System status click
            document.querySelector('.status-indicator').addEventListener('click', function() {
                if (overdueBooks > 0) {
                    window.location.href = 'circulation_control.php?filter=overdue';
                }
            });
        });
        
        // Create professional toast notification
        function createToast(title, message, actionText) {
            const toastContainer = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = 'toast';
            
            toast.innerHTML = `
                <div class="toast-icon">
                    <i class="fas fa-bell"></i>
                </div>
                <div class="toast-content">
                    <div class="toast-title">${title}</div>
                    <div class="toast-message">${message}</div>
                </div>
                <button class="toast-action">${actionText}</button>
            `;
            
            toastContainer.appendChild(toast);
            
            // Add event listener to the action button
            const actionButton = toast.querySelector('.toast-action');
            actionButton.addEventListener('click', function() {
                window.location.href = 'circulation_control.php?filter=overdue';
            });
            
            // Auto-dismiss after 5 seconds
            setTimeout(() => {
                toast.style.animation = 'slideIn 0.3s ease, fadeOut 0.5s forwards';
                setTimeout(() => {
                    if (toast.parentNode) {
                        toast.parentNode.removeChild(toast);
                    }
                }, 500);
            }, 5000);
        }
    </script>
</body>
</html>