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

// Handle AJAX search requests
if (isset($_GET['action']) && $_GET['action'] === 'search') {
    header('Content-Type: application/json');
    
    $search_title = trim($_GET['title'] ?? '');
    $search_author = trim($_GET['author'] ?? '');
    $search_isbn = trim($_GET['isbn'] ?? '');
    $search_category = trim($_GET['category'] ?? '');
    $search_year = trim($_GET['year'] ?? '');
    
    // Build search query
    $where_conditions = ["b.bookStatus != 'disposed'"];
    $params = [];
    $types = '';
    
    if (!empty($search_title)) {
        $where_conditions[] = "b.bookTitle LIKE ?";
        $params[] = "%$search_title%";
        $types .= 's';
    }
    
    if (!empty($search_author)) {
        $where_conditions[] = "b.bookAuthor LIKE ?";
        $params[] = "%$search_author%";
        $types .= 's';
    }
    
    if (!empty($search_isbn)) {
        $where_conditions[] = "b.book_ISBN LIKE ?";
        $params[] = "%$search_isbn%";
        $types .= 's';
    }
    
    if (!empty($search_category)) {
        $where_conditions[] = "bc.categoryName LIKE ?";
        $params[] = "%$search_category%";
        $types .= 's';
    }
    
    if (!empty($search_year)) {
        $where_conditions[] = "b.publication_year = ?";
        $params[] = $search_year;
        $types .= 'i';
    }
    
    $query = "
        SELECT 
            b.bookID,
            b.bookTitle,
            b.bookAuthor,
            b.bookPublisher,
            b.book_ISBN,
            b.bookStatus,
            b.book_description,
            b.publication_year,
            b.language,
            b.number_of_pages,
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
        WHERE " . implode(' AND ', $where_conditions) . "
        ORDER BY b.bookTitle ASC
        LIMIT 50
    ";
    
    try {
        $stmt = $conn->prepare($query);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        
        $books = [];
        while ($book = $result->fetch_assoc()) {
            // Convert image to base64 for JSON response
            if ($book['book_image'] && $book['book_image_mime']) {
                $book['book_image_base64'] = 'data:' . $book['book_image_mime'] . ';base64,' . base64_encode($book['book_image']);
            } else {
                $book['book_image_base64'] = null;
            }
            unset($book['book_image']); // Remove binary data
            $books[] = $book;
        }
        
        echo json_encode(['success' => true, 'books' => $books]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Search failed']);
        error_log("Search error: " . $e->getMessage());
    }
    exit;
}

// Get all books initially
try {
    $books_query = "
        SELECT 
            b.bookID,
            b.bookTitle,
            b.bookAuthor,
            b.bookPublisher,
            b.book_ISBN,
            b.bookStatus,
            b.book_description,
            b.publication_year,
            b.language,
            b.number_of_pages,
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
        WHERE b.bookStatus != 'disposed'
        ORDER BY b.bookTitle ASC
        LIMIT 50
    ";
    $books_result = $conn->query($books_query);
    
    // Get all categories for dropdown
    $categories_query = "SELECT categoryName FROM book_category ORDER BY categoryName ASC";
    $categories_result = $conn->query($categories_query);
    
    // Get notifications count
    $notifications_query = "SELECT COUNT(*) as total FROM notifications WHERE userID = ? AND read_status = FALSE";
    $stmt = $conn->prepare($notifications_query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $notifications_count = $stmt->get_result()->fetch_assoc()['total'];
    
} catch (Exception $e) {
    error_log("Books query error: " . $e->getMessage());
    $books_result = null;
    $categories_result = null;
    $notifications_count = 0;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OPAC Book Search - SMK Chendering Library</title>
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

        /* Search Section */
        .search-section {
            padding: 3rem 2rem;
            background: rgba(255, 255, 255, 0.05);
            position: relative;
        }

        .search-container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .search-hero {
            text-align: center;
            margin-bottom: 3rem;
        }

        .search-title {
            font-size: 3.5rem;
            font-weight: 800;
            background: linear-gradient(135deg, #667eea, #764ba2);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 1rem;
            animation: fadeInUp 1s ease;
        }

        .search-subtitle {
            font-size: 1.3rem;
            color: rgba(255, 255, 255, 0.9);
            margin-bottom: 0.5rem;
            font-weight: 500;
            animation: fadeInUp 1s ease 0.2s both;
        }

        .search-description {
            color: rgba(255, 255, 255, 0.7);
            font-size: 1rem;
            animation: fadeInUp 1s ease 0.4s both;
        }

        .search-form {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(30px);
            padding: 2.5rem;
            border-radius: 24px;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.3);
            animation: fadeInUp 1s ease 0.6s both;
        }

        .search-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .search-field {
            position: relative;
        }

        .search-field label {
            display: block;
            margin-bottom: 0.75rem;
            font-weight: 600;
            color: #2d3748;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .search-field label i {
            color: #667eea;
            font-size: 1rem;
        }

        .search-field input,
        .search-field select {
            width: 100%;
            padding: 1.25rem 1.5rem;
            background: rgba(255, 255, 255, 0.9);
            border: 2px solid rgba(203, 213, 224, 0.4);
            border-radius: 16px;
            color: #2d3748;
            font-size: 1rem;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
            font-weight: 500;
        }

        .search-field input::placeholder {
            color: #a0aec0;
            font-weight: 400;
        }

        .search-field input:focus,
        .search-field select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.15);
            background: rgba(255, 255, 255, 1);
            transform: translateY(-2px);
        }

        .isbn-scanner {
            position: relative;
        }

        .scanner-icon {
            position: absolute;
            right: 1.25rem;
            top: 50%;
            transform: translateY(-50%);
            color: #667eea;
            font-size: 1.3rem;
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .scanner-icon:hover {
            background: rgba(102, 126, 234, 0.1);
            transform: translateY(-50%) scale(1.1);
            color: #764ba2;
        }

        .search-actions {
            display: flex;
            gap: 1.5rem;
            justify-content: center;
            flex-wrap: wrap;
        }

        .btn {
            padding: 1.25rem 2.5rem;
            border: none;
            border-radius: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            position: relative;
            overflow: hidden;
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s;
        }

        .btn:hover::before {
            left: 100%;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            box-shadow: 0 10px 35px rgba(102, 126, 234, 0.4);
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 45px rgba(102, 126, 234, 0.6);
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

        .advanced-filters {
            display: none;
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 2px solid rgba(226, 232, 240, 0.3);
            animation: slideDown 0.5s ease;
        }

        .advanced-filters.active {
            display: block;
        }

        /* Books Section */
        .books-section {
            padding: 3rem 2rem;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
        }

        .books-container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .results-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 3rem;
            padding: 1.5rem 0;
        }

        .results-count {
            color: #4a5568;
            font-size: 1.1rem;
            font-weight: 600;
        }

        .view-options {
            display: flex;
            gap: 0.5rem;
            background: rgba(237, 242, 247, 0.8);
            padding: 0.5rem;
            border-radius: 12px;
            border: 1px solid rgba(226, 232, 240, 0.5);
        }

        .view-btn {
            padding: 0.75rem;
            background: transparent;
            border: none;
            border-radius: 8px;
            color: #718096;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 1rem;
        }

        .view-btn.active,
        .view-btn:hover {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            transform: scale(1.05);
        }

        .books-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 2rem;
            margin-top: 2rem;
        }

        .book-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(226, 232, 240, 0.3);
            border-radius: 16px;
            overflow: hidden;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: pointer;
            position: relative;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 1.5rem;
            text-align: center;
        }

        .book-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 40px rgba(102, 126, 234, 0.2);
            border-color: rgba(102, 126, 234, 0.3);
        }

        .book-image-container {
            width: 150px;
            height: 220px;
            margin-bottom: 1rem;
            position: relative;
            flex-shrink: 0;
        }

        .book-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border: 1px solid #ccc;
            border-radius: 8px;
            transition: transform 0.3s ease;
        }

        .book-placeholder {
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, #f7fafc, #edf2f7);
            border: 1px solid #ccc;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            color: rgba(102, 126, 234, 0.3);
        }

        .book-card:hover .book-image {
            transform: scale(1.05);
        }

        .book-title {
            font-size: 1rem;
            font-weight: 600;
            color: #2d3748;
            line-height: 1.4;
            overflow: hidden;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            transition: color 0.3s ease;
        }

        .book-card:hover .book-title {
            color: #667eea;
        }

        /* Loading States */
        .loading {
            text-align: center;
            padding: 4rem;
            color: #718096;
        }

        .loading i {
            font-size: 3rem;
            margin-bottom: 1rem;
            animation: spin 1s linear infinite;
        }

        .loading-text {
            font-size: 1.2rem;
            font-weight: 500;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: #718096;
            grid-column: 1 / -1;
        }

        .empty-state i {
            font-size: 5rem;
            margin-bottom: 1.5rem;
            color: #cbd5e0;
        }

        .empty-state h3 {
            font-size: 1.8rem;
            font-weight: 700;
            color: #4a5568;
            margin-bottom: 1rem;
        }

        .empty-state p {
            font-size: 1.1rem;
            margin-bottom: 2rem;
        }

        /* Animations */
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

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        /* Responsive Design */
        @media (max-width: 1024px) {
            .search-container,
            .books-container {
                max-width: 900px;
            }
            
            .search-grid {
                grid-template-columns: 1fr;
                gap: 1.5rem;
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                width: 80px;
            }

            .main-content {
                margin-left: 80px;
            }

            .search-section {
                padding: 2rem 1.5rem;
            }

            .books-section {
                padding: 2rem 1.5rem;
            }

            .books-grid {
                grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
                gap: 1.5rem;
            }

            .search-actions {
                flex-direction: column;
            }

            .user-name {
                display: none;
            }

            .search-title {
                font-size: 2.5rem;
            }

            .header-title {
                font-size: 1.2rem;
            }
        }

        @media (max-width: 480px) {
            .header {
                padding: 1rem;
            }

            .search-section {
                padding: 1.5rem 1rem;
            }

            .books-section {
                padding: 2rem 1rem;
            }

            .search-form {
                padding: 2rem;
            }

            .book-card {
                border-radius: 12px;
                padding: 1rem;
            }

            .book-image-container {
                width: 120px;
                height: 180px;
            }

            .books-grid {
                grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            }
        }
    </style>
</head>
<body>
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
            
            <a href="student_search_books.php" class="menu-item active">
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
                
                <h2 class="header-title">OPAC Book Search</h2>
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
                
                <a href="logout.php" class="btn btn-secondary" style="padding: 0.75rem 1.5rem; text-decoration: none; font-size: 0.9rem;">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </header>

        <!-- Search Section -->
        <section class="search-section">
            <div class="search-container">
                <div class="search-hero">
                    <h1 class="search-title">Discover Your Next Great Read</h1>
                    <p class="search-subtitle">Online Public Access Catalog</p>
                    
                </div>
                
                <form class="search-form" id="searchForm">
                    <div class="search-grid">
                        <div class="search-field">
                            <label for="title">
                                <i class="fas fa-book"></i>
                                Book Title
                            </label>
                            <input type="text" id="title" name="title" placeholder="Search by title...">
                        </div>
                        
                        <div class="search-field">
                            <label for="author">
                                <i class="fas fa-user-edit"></i>
                                Author
                            </label>
                            <input type="text" id="author" name="author" placeholder="Search by author...">
                        </div>
                        
                        <div class="search-field isbn-scanner">
                            <label for="isbn">
                                <i class="fas fa-barcode"></i>
                                ISBN
                            </label>
                            <input type="text" id="isbn" name="isbn" placeholder="Scan or enter ISBN...">
                            <i class="fas fa-qrcode scanner-icon" title="Click to scan barcode"></i>
                        </div>
                    </div>
                    
                    <div class="advanced-filters" id="advancedFilters">
                        <div class="search-grid">
                            <div class="search-field">
                                <label for="category">
                                    <i class="fas fa-tags"></i>
                                    Category
                                </label>
                                <select id="category" name="category">
                                    <option value="">All Categories</option>
                                    <?php if ($categories_result): ?>
                                        <?php while ($category = $categories_result->fetch_assoc()): ?>
                                            <option value="<?php echo htmlspecialchars($category['categoryName']); ?>">
                                                <?php echo htmlspecialchars($category['categoryName']); ?>
                                            </option>
                                        <?php endwhile; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                            
                            <div class="search-field">
                                <label for="year">
                                    <i class="fas fa-calendar-alt"></i>
                                    Publication Year
                                </label>
                                <input type="number" id="year" name="year" placeholder="e.g., 2023" min="1900" max="2030">
                            </div>
                        </div>
                    </div>
                    
                    <div class="search-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i>
                            Search Books
                        </button>
                        
                        <button type="button" class="btn btn-secondary" id="toggleAdvanced">
                            <i class="fas fa-filter"></i>
                            Advanced Filters
                        </button>
                        
                        <button type="button" class="btn btn-secondary" id="clearSearch">
                            <i class="fas fa-times"></i>
                            Clear All
                        </button>
                    </div>
                </form>
            </div>
        </section>

        <!-- Books Section -->
        <section class="books-section">
            <div class="books-container">
                <div class="results-header">
                    <div class="results-count" id="resultsCount">Loading books...</div>
                    <div class="view-options">
                        <button class="view-btn active" data-view="grid">
                            <i class="fas fa-th-large"></i>
                        </button>
                        <button class="view-btn" data-view="list">
                            <i class="fas fa-list"></i>
                        </button>
                    </div>
                </div>
                
                <div class="books-grid" id="booksGrid">
                    <div class="loading">
                        <i class="fas fa-spinner fa-spin"></i>
                        <div class="loading-text">Discovering amazing books for you...</div>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <script>
        let allBooks = [];
        let isLoading = false;

        // Initialize page
        document.addEventListener('DOMContentLoaded', function() {
            loadAllBooks();
            setupEventListeners();
            setupISBNScanner();
            setupViewToggle();
            setupEntranceAnimations();
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
            // Search form
            document.getElementById('searchForm').addEventListener('submit', handleSearch);
            
            // Advanced filters toggle
            document.getElementById('toggleAdvanced').addEventListener('click', toggleAdvancedFilters);
            
            // Clear search
            document.getElementById('clearSearch').addEventListener('click', clearSearch);
            
            // Real-time search on input
            const inputs = ['title', 'author', 'isbn', 'category', 'year'];
            inputs.forEach(inputId => {
                const element = document.getElementById(inputId);
                element.addEventListener('input', debounce(handleSearch, 500));
            });
        }

        // Setup view toggle
        function setupViewToggle() {
            const viewBtns = document.querySelectorAll('.view-btn');
            viewBtns.forEach(btn => {
                btn.addEventListener('click', function() {
                    viewBtns.forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                    
                    const view = this.dataset.view;
                    const grid = document.getElementById('booksGrid');
                    
                    if (view === 'list') {
                        grid.style.gridTemplateColumns = '1fr';
                        grid.querySelectorAll('.book-card').forEach(card => {
                            card.style.display = 'flex';
                            card.style.flexDirection = 'row';
                            card.style.alignItems = 'center';
                            card.style.textAlign = 'left';
                            card.style.padding = '1rem';
                            card.querySelector('.book-image-container').style.marginBottom = '0';
                            card.querySelector('.book-image-container').style.marginRight = '1rem';
                            card.querySelector('.book-image-container').style.width = '80px';
                            card.querySelector('.book-image-container').style.height = '120px';
                        });
                    } else {
                        grid.style.gridTemplateColumns = 'repeat(auto-fill, minmax(200px, 1fr))';
                        grid.querySelectorAll('.book-card').forEach(card => {
                            card.style.display = 'flex';
                            card.style.flexDirection = 'column';
                            card.style.alignItems = 'center';
                            card.style.textAlign = 'center';
                            card.style.padding = '1.5rem';
                            card.querySelector('.book-image-container').style.marginBottom = '1rem';
                            card.querySelector('.book-image-container').style.marginRight = '0';
                            card.querySelector('.book-image-container').style.width = '150px';
                            card.querySelector('.book-image-container').style.height = '220px';
                        });
                    }
                });
            });
        }

        // Setup ISBN scanner
        function setupISBNScanner() {
            const isbnField = document.getElementById('isbn');
            const scannerIcon = document.querySelector('.scanner-icon');
            let scanTimeout = null;

            isbnField.addEventListener('input', function(e) {
                const value = e.target.value;
                
                if (scanTimeout) {
                    clearTimeout(scanTimeout);
                }
                
                // Auto-trigger search for scanned barcodes
                if (value.length >= 10 && /^\d+$/.test(value)) {
                    scanTimeout = setTimeout(() => {
                        handleSearch(e);
                    }, 300);
                }
            });

            isbnField.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    handleSearch(e);
                }
            });

            scannerIcon.addEventListener('click', function() {
                isbnField.focus();
                // Animate scanner icon
                scannerIcon.style.transform = 'translateY(-50%) scale(1.2)';
                scannerIcon.style.color = '#22543d';
                setTimeout(() => {
                    scannerIcon.style.transform = 'translateY(-50%) scale(1)';
                    scannerIcon.style.color = '#667eea';
                }, 200);
            });
        }

        // Load all books initially
        async function loadAllBooks() {
            try {
                const response = await fetch('?action=search');
                const data = await response.json();
                
                if (data.success) {
                    allBooks = data.books;
                    displayBooks(data.books);
                    updateResultsCount(data.books.length);
                } else {
                    showError('Failed to load books');
                }
            } catch (error) {
                console.error('Error loading books:', error);
                showError('Error loading books');
            }
        }

        // Handle search form submission
        async function handleSearch(e) {
            if (e && e.preventDefault) {
                e.preventDefault();
            }
            
            if (isLoading) return;
            
            const formData = new FormData(document.getElementById('searchForm'));
            const params = new URLSearchParams();
            
            // Add search parameters
            for (let [key, value] of formData.entries()) {
                if (value.trim()) {
                    params.append(key, value.trim());
                }
            }
            params.append('action', 'search');
            
            // Show loading
            showLoading();
            isLoading = true;
            
            try {
                const response = await fetch('?' + params.toString());
                const data = await response.json();
                
                if (data.success) {
                    displayBooks(data.books);
                    updateResultsCount(data.books.length);
                } else {
                    showError(data.message || 'Search failed');
                }
            } catch (error) {
                console.error('Search error:', error);
                showError('Search failed. Please try again.');
            } finally {
                isLoading = false;
            }
        }

        // Toggle advanced filters
        function toggleAdvancedFilters() {
            const filters = document.getElementById('advancedFilters');
            const button = document.getElementById('toggleAdvanced');
            
            filters.classList.toggle('active');
            
            if (filters.classList.contains('active')) {
                button.innerHTML = '<i class="fas fa-filter"></i> Hide Filters';
            } else {
                button.innerHTML = '<i class="fas fa-filter"></i> Advanced Filters';
            }
        }

        // Clear search
        function clearSearch() {
            document.getElementById('searchForm').reset();
            loadAllBooks();
            
            // Hide advanced filters
            const filters = document.getElementById('advancedFilters');
            const button = document.getElementById('toggleAdvanced');
            filters.classList.remove('active');
            button.innerHTML = '<i class="fas fa-filter"></i> Advanced Filters';
        }

        // Display books in grid
        function displayBooks(books) {
            const grid = document.getElementById('booksGrid');
            
            if (books.length === 0) {
                grid.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-search"></i>
                        <h3>No Books Found</h3>
                        <p>We couldn't find any books matching your search criteria.</p>
                        <button class="btn btn-primary" onclick="clearSearch()">
                            <i class="fas fa-refresh"></i> Show All Books
                        </button>
                    </div>
                `;
                return;
            }
            
            grid.innerHTML = books.map(book => `
                <div class="book-card" onclick="goToBookDetail(${book.bookID})">
                    <div class="book-image-container">
                        ${book.book_image_base64 ? 
                            `<img src="${book.book_image_base64}" alt="${escapeHtml(book.bookTitle)}" class="book-image">` :
                            `<div class="book-placeholder"><i class="fas fa-book"></i></div>`
                        }
                    </div>
                    
                    <div class="book-title">${escapeHtml(book.bookTitle)}</div>
                </div>
            `).join('');
            
            // Add stagger animation to cards
            const cards = grid.querySelectorAll('.book-card');
            cards.forEach((card, index) => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                setTimeout(() => {
                    card.style.transition = 'all 0.5s ease';
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, index * 50);
            });
        }

        // Show loading state
        function showLoading() {
            document.getElementById('booksGrid').innerHTML = `
                <div class="loading">
                    <i class="fas fa-spinner fa-spin"></i>
                    <div class="loading-text">Searching through our amazing collection...</div>
                </div>
            `;
        }

        // Update results count
        function updateResultsCount(count) {
            const searchTerms = getActiveSearchTerms();
            document.getElementById('resultsCount').textContent = 
                `Found ${count} book${count !== 1 ? 's' : ''} ${searchTerms ? 'for: ' + searchTerms : ''}`;
        }

        // Get active search terms for display
        function getActiveSearchTerms() {
            const formData = new FormData(document.getElementById('searchForm'));
            const terms = [];
            
            for (let [key, value] of formData.entries()) {
                if (value.trim()) {
                    terms.push(`${key}: "${value}"`);
                }
            }
            
            return terms.join(', ');
        }

        // Show error message
        function showError(message) {
            document.getElementById('booksGrid').innerHTML = `
                <div class="empty-state">
                    <i class="fas fa-exclamation-triangle" style="color: #e53e3e;"></i>
                    <h3>Oops! Something went wrong</h3>
                    <p>${message}</p>
                    <button class="btn btn-primary" onclick="loadAllBooks()">
                        <i class="fas fa-refresh"></i> Try Again
                    </button>
                </div>
            `;
        }

        // Navigate to book detail page
        function goToBookDetail(bookId) {
            window.location.href = `student_book_detail.php?id=${bookId}`;
        }

        // Utility function to escape HTML
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Debounce function for search
        function debounce(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        }

        // Setup entrance animations
        function setupEntranceAnimations() {
            const searchSection = document.querySelector('.search-section');
            const booksSection = document.querySelector('.books-section');
            
            // Initial state
            searchSection.style.opacity = '0';
            searchSection.style.transform = 'translateY(30px)';
            booksSection.style.opacity = '0';
            booksSection.style.transform = 'translateY(30px)';
            
            // Animate search section
            setTimeout(() => {
                searchSection.style.transition = 'all 0.8s ease';
                searchSection.style.opacity = '1';
                searchSection.style.transform = 'translateY(0)';
            }, 100);
            
            // Animate books section
            setTimeout(() => {
                booksSection.style.transition = 'all 0.8s ease';
                booksSection.style.opacity = '1';
                booksSection.style.transform = 'translateY(0)';
            }, 300);
        }

        // Handle keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'k') {
                e.preventDefault();
                document.getElementById('title').focus();
            }
        });
    </script>
</body>
</html>