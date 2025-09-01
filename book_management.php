<?php
session_start();
require_once 'dbconnect.php';
require_once 'auth_helper.php';
checkPageAccess();
requireRole('librarian');

if (!isset($_SESSION['user_id'])) {
    header("Location: index.html");
    exit();
}

$librarian_info = getCurrentUser();
$librarian_name = getUserDisplayName();

$current_librarian_id = null;
if (isset($_SESSION['userID'])) {
    $stmt = $conn->prepare("SELECT librarianID FROM librarian WHERE librarianID = ?");
    $stmt->bind_param("i", $_SESSION['userID']);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $current_librarian_id = $row['librarianID'];
    }
    $stmt->close();
}

if (!$current_librarian_id) {
    $current_librarian_id = $_SESSION['userID'] ?? 1;
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();
    
    header('Content-Type: application/json');
    
    set_error_handler(function($severity, $message, $file, $line) {
        ob_clean();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => "PHP Error: $message in $file on line $line"]);
        exit;
    });

    try {
        switch ($_POST['action']) {
            case 'add_book':
                handleAddBook();
                break;
            case 'update_book':
                handleUpdateBook();
                break;
            case 'delete_book':
                handleDeleteBook();
                break;
            case 'dispose_book':
                handleDisposeBook();
                break;
            case 'get_book':
                handleGetBook();
                break;
            case 'add_category':
                handleAddCategory();
                break;
            case 'batch_upload':
                handleBatchUpload();
                break;
            case 'get_book_image':
                handleGetBookImage();
                break;
            case 'auto_dispose_books':
                handleAutoDisposeBooks();
                break;
            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
    } catch (Exception $e) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    
    restore_error_handler();
    ob_end_flush();
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'download_template') {
    downloadTemplate();
    exit;
}

// [Include all the existing PHP functions here - they remain unchanged]
function getBookCoverFromAPI($isbn) {
    if (empty($isbn)) {
        return null;
    }
    
    $isbn = preg_replace('/[^0-9X]/', '', $isbn);
    $coverUrl = "https://covers.openlibrary.org/b/isbn/{$isbn}-L.jpg";
    
    $context = stream_context_create([
        'http' => [
            'timeout' => 10,
            'user_agent' => 'Library Management System'
        ]
    ]);
    
    $headers = @get_headers($coverUrl, 0, $context);
    if ($headers && strpos($headers[0], '200') !== false) {
        $imageData = @file_get_contents($coverUrl, false, $context);
        if ($imageData !== false && strlen($imageData) > 1000) {
            return [
                'data' => $imageData,
                'mime' => 'image/jpeg'
            ];
        }
    }
    
    return null;
}

function generateISBN13($isbn = null) {
    if (!empty($isbn)) {
        $cleanISBN = preg_replace('/[^0-9X]/', '', $isbn);
        
        if (strlen($cleanISBN) == 10) {
            $isbn13 = '978' . substr($cleanISBN, 0, 9);
            
            $sum = 0;
            for ($i = 0; $i < 12; $i++) {
                $sum += intval($isbn13[$i]) * (($i % 2 == 0) ? 1 : 3);
            }
            $checkDigit = (10 - ($sum % 10)) % 10;
            $isbn13 .= $checkDigit;
            
            return $isbn13;
        } elseif (strlen($cleanISBN) == 13) {
            return $cleanISBN;
        }
    }
    
    return '978' . str_pad(mt_rand(1000000000, 9999999999), 10, '0', STR_PAD_LEFT);
}

function generateBookBarcode($isbn = null, $bookId = null) {
    if (!empty($isbn)) {
        return generateISBN13($isbn);
    } else {
        return '978' . date('ymd') . sprintf('%04d', $bookId ?? rand(1000, 9999));
    }
}

function handleImageUpload($file) {
    $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
    $max_size = 5 * 1024 * 1024;
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error_messages = [
            UPLOAD_ERR_INI_SIZE => 'File too large (server limit)',
            UPLOAD_ERR_FORM_SIZE => 'File too large (form limit)', 
            UPLOAD_ERR_PARTIAL => 'File upload was interrupted',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'File upload stopped by extension'
        ];
        
        $error_msg = isset($error_messages[$file['error']]) 
            ? $error_messages[$file['error']] 
            : 'Unknown upload error';
            
        throw new Exception('Upload error: ' . $error_msg);
    }
    
    $file_info = finfo_open(FILEINFO_MIME_TYPE);
    $detected_type = finfo_file($file_info, $file['tmp_name']);
    finfo_close($file_info);
    
    if (!in_array($detected_type, $allowed_types) && !in_array($file['type'], $allowed_types)) {
        throw new Exception('Invalid image type. Only JPEG, PNG, GIF, and WebP are allowed. Detected: ' . $detected_type);
    }
    
    if ($file['size'] > $max_size) {
        throw new Exception('Image size too large. Maximum 5MB allowed. File size: ' . round($file['size']/1024/1024, 2) . 'MB');
    }
    
    $image_info = getimagesize($file['tmp_name']);
    if ($image_info === false) {
        throw new Exception('File is not a valid image.');
    }
    
    $max_width = 2000;
    $max_height = 2000;
    if ($image_info[0] > $max_width || $image_info[1] > $max_height) {
        throw new Exception("Image dimensions too large. Maximum allowed: {$max_width}x{$max_height}px. Your image: {$image_info[0]}x{$image_info[1]}px");
    }
    
    $imageData = file_get_contents($file['tmp_name']);
    if ($imageData === false) {
        throw new Exception('Failed to read image file.');
    }
    
    return [
        'data' => $imageData,
        'mime' => $detected_type,
        'width' => $image_info[0],
        'height' => $image_info[1]
    ];
}

function handleAddBook() {
    global $conn, $current_librarian_id;
    
    try {
        if (!$conn) {
            throw new Exception('Database connection failed.');
        }
        
        $title = trim($_POST['title']);
        $author = trim($_POST['author']);
        $isbn = trim($_POST['isbn']);
        $publisher = trim($_POST['publisher']);
        $categoryID = intval($_POST['categoryID']);
        $shelf_location = trim($_POST['shelf_location']);
        $description = trim($_POST['description']);
        $publication_year = !empty($_POST['publication_year']) ? intval($_POST['publication_year']) : null;
        $language = trim($_POST['language']);
        $number_of_pages = !empty($_POST['number_of_pages']) ? intval($_POST['number_of_pages']) : null;
        $condition = $_POST['condition'];
        $acquisition_date = $_POST['acquisition_date'];
        $book_price = floatval($_POST['book_price']);
        $quantity = intval($_POST['quantity']) ?: 1;
        
        $image_data = null;
        $image_mime = null;
        
        if (isset($_FILES['book_image']) && $_FILES['book_image']['error'] === UPLOAD_ERR_OK) {
            $imageInfo = handleImageUpload($_FILES['book_image']);
            $image_data = $imageInfo['data'];
            $image_mime = $imageInfo['mime'];
        } else {
            if (!empty($isbn)) {
                $apiImage = getBookCoverFromAPI($isbn);
                if ($apiImage) {
                    $image_data = $apiImage['data'];
                    $image_mime = $apiImage['mime'];
                }
            }
        }
        
        if (empty($title) || empty($author) || $categoryID <= 0) {
            throw new Exception('Title, Author, and Category are required fields.');
        }
        
        $valid_conditions = ['excellent', 'good', 'fair', 'poor'];
        if (!in_array($condition, $valid_conditions)) {
            throw new Exception('Invalid book condition selected.');
        }
        
        $conn->begin_transaction();
        
        $inserted_books = [];
        for ($i = 0; $i < $quantity; $i++) {
            $barcode = generateBookBarcode($isbn);
            
            if ($i > 0) {
                $barcode .= sprintf("-%02d", $i + 1);
            }
            
            $attempt = 0;
            while ($attempt < 10) {
                $check_barcode = $conn->prepare("SELECT bookID FROM book WHERE bookBarcode = ?");
                $check_barcode->bind_param("s", $barcode);
                $check_barcode->execute();
                if ($check_barcode->get_result()->num_rows == 0) {
                    break;
                }
                $barcode = generateBookBarcode($isbn) . sprintf("-%03d", rand(100, 999));
                $attempt++;
            }
            
            $book_title = $i > 0 ? $title . " (Copy " . ($i + 1) . ")" : $title;
            
            if ($image_data !== null) {
                $sql = "INSERT INTO book (
                    bookTitle, bookAuthor, book_ISBN, bookPublisher, categoryID, 
                    shelf_location, bookBarcode, book_description, publication_year, 
                    language, number_of_pages, book_condition, acquisition_date, 
                    book_price, librarianID, book_entry_date, bookStatus, book_image, book_image_mime
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'available', ?, ?)";
                
                $stmt = $conn->prepare($sql);
                if (!$stmt) {
                    throw new Exception('Failed to prepare statement: ' . $conn->error);
                }
                
                $stmt->bind_param(
                    "ssssisssissssdiss", 
                    $book_title, $author, $isbn, $publisher, $categoryID, 
                    $shelf_location, $barcode, $description, $publication_year, 
                    $language, $number_of_pages, $condition, $acquisition_date, 
                    $book_price, $current_librarian_id, $image_data, $image_mime
                );
                
            } else {
                $sql = "INSERT INTO book (
                    bookTitle, bookAuthor, book_ISBN, bookPublisher, categoryID, 
                    shelf_location, bookBarcode, book_description, publication_year, 
                    language, number_of_pages, book_condition, acquisition_date, 
                    book_price, librarianID, book_entry_date, bookStatus
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'available')";
                
                $stmt = $conn->prepare($sql);
                if (!$stmt) {
                    throw new Exception('Failed to prepare statement: ' . $conn->error);
                }
                
                $stmt->bind_param(
                    "ssssisssissssdi", 
                    $book_title, $author, $isbn, $publisher, $categoryID, 
                    $shelf_location, $barcode, $description, $publication_year, 
                    $language, $number_of_pages, $condition, $acquisition_date, 
                    $book_price, $current_librarian_id
                );
            }
            
            if (!$stmt->execute()) {
                throw new Exception('Failed to add book: ' . $stmt->error);
            }
            
            $inserted_books[] = $conn->insert_id;
            $stmt->close();
        }
        
        $conn->commit();
        
        echo json_encode([
            'success' => true, 
            'message' => $quantity > 1 ? "$quantity books added successfully" : "Book added successfully",
            'book_ids' => $inserted_books
        ]);
        
    } catch (Exception $e) {
        if ($conn && $conn->ping()) {
            $conn->rollback();
        }
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function handleUpdateBook() {
    global $conn;
    
    try {
        if (!$conn) {
            throw new Exception('Database connection failed.');
        }
        
        $bookID = intval($_POST['bookID']);
        $title = trim($_POST['title']);
        $author = trim($_POST['author']);
        $isbn = trim($_POST['isbn']);
        $publisher = trim($_POST['publisher']);
        $categoryID = intval($_POST['categoryID']);
        $shelf_location = trim($_POST['shelf_location']);
        $description = trim($_POST['description']);
        $publication_year = !empty($_POST['publication_year']) ? intval($_POST['publication_year']) : null;
        $language = trim($_POST['language']);
        $number_of_pages = !empty($_POST['number_of_pages']) ? intval($_POST['number_of_pages']) : null;
        $condition = $_POST['condition'];
        $acquisition_date = $_POST['acquisition_date'];
        $book_price = floatval($_POST['book_price']);
        
        $image_data = null;
        $image_mime = null;
        $update_image = false;
        
        if (isset($_FILES['book_image']) && $_FILES['book_image']['error'] === UPLOAD_ERR_OK) {
            $imageInfo = handleImageUpload($_FILES['book_image']);
            $image_data = $imageInfo['data'];
            $image_mime = $imageInfo['mime'];
            $update_image = true;
        }
        
        if (empty($title) || empty($author) || $categoryID <= 0) {
            throw new Exception('Title, Author, and Category are required fields.');
        }
        
        if ($bookID <= 0) {
            throw new Exception('Invalid book ID.');
        }
        
        $valid_conditions = ['excellent', 'good', 'fair', 'poor'];
        if (!in_array($condition, $valid_conditions)) {
            throw new Exception('Invalid book condition selected.');
        }
        
        if ($update_image && $image_data !== null) {
            $sql = "UPDATE book SET 
                bookTitle = ?, bookAuthor = ?, book_ISBN = ?, bookPublisher = ?, 
                categoryID = ?, shelf_location = ?, book_description = ?, 
                publication_year = ?, language = ?, number_of_pages = ?, 
                book_condition = ?, acquisition_date = ?, book_price = ?,
                book_image = ?, book_image_mime = ?
                WHERE bookID = ?";
                
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception('Failed to prepare statement: ' . $conn->error);
            }
            
            $stmt->bind_param(
                "ssssisssisssdssi", 
                $title, $author, $isbn, $publisher, $categoryID, 
                $shelf_location, $description, $publication_year, 
                $language, $number_of_pages, $condition, $acquisition_date, 
                $book_price, $image_data, $image_mime, $bookID
            );
            
        } else {
            $sql = "UPDATE book SET 
                bookTitle = ?, bookAuthor = ?, book_ISBN = ?, bookPublisher = ?, 
                categoryID = ?, shelf_location = ?, book_description = ?, 
                publication_year = ?, language = ?, number_of_pages = ?, 
                book_condition = ?, acquisition_date = ?, book_price = ?
                WHERE bookID = ?";
                
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception('Failed to prepare statement: ' . $conn->error);
            }
            
            $stmt->bind_param(
                "ssssisssisssdi", 
                $title, $author, $isbn, $publisher, $categoryID, 
                $shelf_location, $description, $publication_year, 
                $language, $number_of_pages, $condition, $acquisition_date, 
                $book_price, $bookID
            );
        }
        
        if (!$stmt->execute()) {
            throw new Exception('Failed to update book: ' . $stmt->error);
        }
        
        if ($stmt->affected_rows === 0) {
            $check_stmt = $conn->prepare("SELECT bookID FROM book WHERE bookID = ?");
            $check_stmt->bind_param("i", $bookID);
            $check_stmt->execute();
            if ($check_stmt->get_result()->num_rows === 0) {
                throw new Exception('Book not found.');
            }
        }
        
        $stmt->close();
        
        echo json_encode(['success' => true, 'message' => 'Book updated successfully']);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function handleDeleteBook() {
    global $conn;
    
    try {
        $bookID = intval($_POST['bookID']);
        if ($bookID <= 0) {
            throw new Exception('Invalid book ID.');
        }
        
        $check_borrowed = $conn->prepare("SELECT borrowID FROM borrow WHERE bookID = ? AND borrow_status = 'borrowed'");
        $check_borrowed->bind_param("i", $bookID);
        $check_borrowed->execute();
        
        if ($check_borrowed->get_result()->num_rows > 0) {
            throw new Exception('Cannot delete book that is currently borrowed.');
        }
        
        $check_reserved = $conn->prepare("SELECT reservationID FROM reservation WHERE bookID = ? AND reservation_status IN ('active')");
        $check_reserved->bind_param("i", $bookID);
        $check_reserved->execute();
        
        if ($check_reserved->get_result()->num_rows > 0) {
            throw new Exception('Cannot delete book that has active reservations.');
        }
        
        $stmt = $conn->prepare("UPDATE book SET 
            bookStatus = 'disposed', 
            book_ISBN = CASE 
                WHEN book_ISBN IS NOT NULL AND CHAR_LENGTH(book_ISBN) > 0 
                THEN CONCAT(LEFT(book_ISBN, 40), '_DEL_', bookID) 
                ELSE NULL 
            END,
            bookBarcode = CONCAT(LEFT(IFNULL(bookBarcode, ''), 40), '_DEL_', bookID)
            WHERE bookID = ?");
        $stmt->bind_param("i", $bookID);
        
        if (!$stmt->execute()) {
            throw new Exception('Failed to delete book: ' . $stmt->error);
        }
        
        if ($stmt->affected_rows === 0) {
            throw new Exception('Book not found or already deleted.');
        }
        
        echo json_encode(['success' => true, 'message' => 'Book deleted successfully']);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function handleDisposeBook() {
    global $conn, $current_librarian_id;
    
    try {
        $bookID = intval($_POST['bookID']);
        $reason = trim($_POST['reason']);
        $description = trim($_POST['description']);
        
        if ($bookID <= 0) {
            throw new Exception('Invalid book ID.');
        }
        
        if (empty($reason)) {
            throw new Exception('Disposal reason is required.');
        }
        
        $check_age = $conn->prepare("
            SELECT bookID, bookTitle, DATEDIFF(NOW(), acquisition_date) as days_old 
            FROM book 
            WHERE bookID = ? AND DATEDIFF(NOW(), acquisition_date) >= 2555
        ");
        $check_age->bind_param("i", $bookID);
        $check_age->execute();
        $age_result = $check_age->get_result();
        
        if ($age_result->num_rows === 0) {
            throw new Exception('This book does not qualify for disposal. Only books older than 7 years (based on acquisition date) can be disposed.');
        }
        
        $check_borrowed = $conn->prepare("SELECT borrowID FROM borrow WHERE bookID = ? AND borrow_status = 'borrowed'");
        $check_borrowed->bind_param("i", $bookID);
        $check_borrowed->execute();
        
        if ($check_borrowed->get_result()->num_rows > 0) {
            throw new Exception('Cannot dispose book that is currently borrowed.');
        }
        
        $conn->begin_transaction();
        
        $stmt = $conn->prepare("UPDATE book SET bookStatus = 'disposed' WHERE bookID = ?");
        $stmt->bind_param("i", $bookID);
        
        if (!$stmt->execute()) {
            throw new Exception('Failed to dispose book: ' . $stmt->error);
        }
        
        $disposal_stmt = $conn->prepare("
            INSERT INTO disposal (bookID, disposal_reason, disposal_description, librarianID, disposal_status, disposal_date) 
            VALUES (?, ?, ?, ?, 'completed', NOW())
        ");
        $disposal_stmt->bind_param("issi", $bookID, $reason, $description, $current_librarian_id);
        
        if (!$disposal_stmt->execute()) {
            throw new Exception('Failed to log disposal: ' . $disposal_stmt->error);
        }
        
        $conn->commit();
        
        echo json_encode(['success' => true, 'message' => 'Book disposed successfully']);
        
    } catch (Exception $e) {
        if ($conn && $conn->ping()) {
            $conn->rollback();
        }
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function handleAutoDisposeBooks() {
    global $conn, $current_librarian_id;
    
    try {
        $dispose_query = "
            SELECT bookID, bookTitle, acquisition_date, 
                   DATEDIFF(NOW(), acquisition_date) as days_old
            FROM book 
            WHERE bookStatus NOT IN ('disposed', 'borrowed') 
            AND DATEDIFF(NOW(), acquisition_date) >= 2555
            AND acquisition_date IS NOT NULL
            ORDER BY acquisition_date ASC
        ";
        
        $result = $conn->query($dispose_query);
        $disposed_count = 0;
        
        if ($result && $result->num_rows > 0) {
            $conn->begin_transaction();
            
            while ($book = $result->fetch_assoc()) {
                $update_stmt = $conn->prepare("UPDATE book SET bookStatus = 'disposed' WHERE bookID = ?");
                $update_stmt->bind_param("i", $book['bookID']);
                
                if ($update_stmt->execute()) {
                    $disposal_stmt = $conn->prepare("
                        INSERT INTO disposal (bookID, disposal_reason, disposal_description, librarianID, disposal_status, disposal_date, fifo_priority) 
                        VALUES (?, 'Auto-disposed (7+ years old)', ?, ?, 'completed', NOW(), ?)
                    ");
                    
                    $description = "Automatically disposed - Book acquired on " . $book['acquisition_date'] . " (" . $book['days_old'] . " days old)";
                    $disposal_stmt->bind_param("isii", $book['bookID'], $description, $current_librarian_id, $disposed_count + 1);
                    
                    if ($disposal_stmt->execute()) {
                        $disposed_count++;
                    }
                }
                
                $update_stmt->close();
                if (isset($disposal_stmt)) {
                    $disposal_stmt->close();
                }
            }
            
            $conn->commit();
        }
        
        echo json_encode([
            'success' => true, 
            'message' => $disposed_count > 0 ? "$disposed_count books automatically disposed using FIFO principle" : "No books found for disposal",
            'disposed_count' => $disposed_count
        ]);
        
    } catch (Exception $e) {
        if ($conn && $conn->ping()) {
            $conn->rollback();
        }
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function handleGetBook() {
    global $conn;
    
    try {
        $bookID = intval($_POST['bookID']);
        if ($bookID <= 0) {
            throw new Exception('Invalid book ID.');
        }
        
        $stmt = $conn->prepare("
            SELECT b.*, bc.categoryName 
            FROM book b 
            LEFT JOIN book_category bc ON b.categoryID = bc.categoryID 
            WHERE b.bookID = ?
        ");
        $stmt->bind_param("i", $bookID);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $book = $result->fetch_assoc();
            
            if (!empty($book['book_image'])) {
                $book['book_image_base64'] = 'data:' . $book['book_image_mime'] . ';base64,' . base64_encode($book['book_image']);
            }
            
            unset($book['book_image']);
            
            echo json_encode(['success' => true, 'book' => $book]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Book not found']);
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function handleAddCategory() {
    global $conn;
    
    try {
        $categoryName = trim($_POST['categoryName']);
        $categoryDescription = trim($_POST['categoryDescription']);
        
        if (empty($categoryName)) {
            throw new Exception('Category name is required.');
        }
        
        $check_stmt = $conn->prepare("SELECT categoryID FROM book_category WHERE categoryName = ?");
        $check_stmt->bind_param("s", $categoryName);
        $check_stmt->execute();
        
        if ($check_stmt->get_result()->num_rows > 0) {
            throw new Exception('Category already exists.');
        }
        
        $stmt = $conn->prepare("INSERT INTO book_category (categoryName, categoryDescription) VALUES (?, ?)");
        $stmt->bind_param("ss", $categoryName, $categoryDescription);
        
        if (!$stmt->execute()) {
            throw new Exception('Failed to add category: ' . $stmt->error);
        }
        
        $newCategoryID = $conn->insert_id;
        
        echo json_encode([
            'success' => true, 
            'message' => 'Category added successfully',
            'category' => [
                'categoryID' => $newCategoryID,
                'categoryName' => $categoryName
            ]
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function handleBatchUpload() {
    global $conn, $current_librarian_id;
    
    try {
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Please select a valid CSV file.');
        }
        
        $file = $_FILES['csv_file'];
        $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (!in_array($file_extension, ['csv'])) {
            throw new Exception('Only CSV files are allowed.');
        }
        
        $books_data = parseCsvFile($file['tmp_name']);
        
        if (empty($books_data)) {
            throw new Exception('No valid data found in the file.');
        }
        
        $conn->begin_transaction();
        
        $success_count = 0;
        $errors = [];
        
        foreach ($books_data as $row_index => $book_data) {
            try {
                if (empty($book_data['title']) || empty($book_data['author'])) {
                    $errors[] = "Row " . ($row_index + 2) . ": Title and Author are required";
                    continue;
                }
                
                $csv_librarian_id = !empty($book_data['librarianID']) ? intval($book_data['librarianID']) : $current_librarian_id;
                
                $librarian_check = $conn->prepare("SELECT librarianID FROM librarian WHERE librarianID = ?");
                $librarian_check->bind_param("i", $csv_librarian_id);
                $librarian_check->execute();
                if ($librarian_check->get_result()->num_rows === 0) {
                    $errors[] = "Row " . ($row_index + 2) . ": Invalid librarianID '$csv_librarian_id'. Using current librarian instead.";
                    $csv_librarian_id = $current_librarian_id;
                }
                
                $categoryID = getCategoryID($book_data['category']);
                if (!$categoryID) {
                    $errors[] = "Row " . ($row_index + 2) . ": Invalid category '" . $book_data['category'] . "'";
                    continue;
                }
                
                $barcode = generateBookBarcode($book_data['isbn']);
                
                $attempt = 0;
                while ($attempt < 10) {
                    $check_barcode = $conn->prepare("SELECT bookID FROM book WHERE bookBarcode = ?");
                    $check_barcode->bind_param("s", $barcode);
                    $check_barcode->execute();
                    if ($check_barcode->get_result()->num_rows == 0) {
                        break;
                    }
                    $barcode = generateBookBarcode($book_data['isbn']) . sprintf("-%03d", rand(100, 999));
                    $attempt++;
                }
                
                $image_data = null;
                $image_mime = null;
                if (!empty($book_data['isbn'])) {
                    $apiImage = getBookCoverFromAPI($book_data['isbn']);
                    if ($apiImage) {
                        $image_data = $apiImage['data'];
                        $image_mime = $apiImage['mime'];
                    }
                }
                
                $publication_year = !empty($book_data['publication_year']) ? intval($book_data['publication_year']) : null;
                $number_of_pages = !empty($book_data['number_of_pages']) ? intval($book_data['number_of_pages']) : null;
                $book_price = !empty($book_data['price']) ? floatval($book_data['price']) : 0.00;
                $acquisition_date = !empty($book_data['acquisition_date']) ? $book_data['acquisition_date'] : date('Y-m-d');
                $condition = !empty($book_data['condition']) && in_array($book_data['condition'], ['excellent', 'good', 'fair', 'poor']) ? $book_data['condition'] : 'good';
                $language = !empty($book_data['language']) ? $book_data['language'] : 'English';
                
                if ($image_data !== null) {
                    $sql = "INSERT INTO book (
                        bookTitle, bookAuthor, book_ISBN, bookPublisher, categoryID, 
                        shelf_location, bookBarcode, book_description, publication_year, 
                        language, number_of_pages, book_condition, acquisition_date, 
                        book_price, librarianID, book_entry_date, bookStatus, book_image, book_image_mime
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'available', ?, ?)";
                    
                    $stmt = $conn->prepare($sql);
                    
                    $stmt->bind_param(
                        "ssssisssissssdiss", 
                        $book_data['title'], $book_data['author'], $book_data['isbn'], 
                        $book_data['publisher'], $categoryID, $book_data['shelf_location'], 
                        $barcode, $book_data['description'], $publication_year, 
                        $language, $number_of_pages, $condition, $acquisition_date, 
                        $book_price, $csv_librarian_id, $image_data, $image_mime
                    );
                } else {
                    $sql = "INSERT INTO book (
                        bookTitle, bookAuthor, book_ISBN, bookPublisher, categoryID, 
                        shelf_location, bookBarcode, book_description, publication_year, 
                        language, number_of_pages, book_condition, acquisition_date, 
                        book_price, librarianID, book_entry_date, bookStatus
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'available')";
                    
                    $stmt = $conn->prepare($sql);
                    
                    $stmt->bind_param(
                        "ssssisssissssdi", 
                        $book_data['title'], $book_data['author'], $book_data['isbn'], 
                        $book_data['publisher'], $categoryID, $book_data['shelf_location'], 
                        $barcode, $book_data['description'], $publication_year, 
                        $language, $number_of_pages, $condition, $acquisition_date, 
                        $book_price, $csv_librarian_id
                    );
                }
                
                if ($stmt->execute()) {
                    $success_count++;
                } else {
                    $errors[] = "Row " . ($row_index + 2) . ": Database error - " . $stmt->error;
                }
                
                $stmt->close();
                
            } catch (Exception $e) {
                $errors[] = "Row " . ($row_index + 2) . ": " . $e->getMessage();
            }
        }
        
        $conn->commit();
        
        $response = [
            'success' => true,
            'message' => "$success_count books imported successfully",
            'success_count' => $success_count,
            'total_rows' => count($books_data)
        ];
        
        if (!empty($errors)) {
            $response['errors'] = $errors;
            $response['message'] .= ". " . count($errors) . " rows had errors.";
        }
        
        echo json_encode($response);
        
    } catch (Exception $e) {
        if ($conn && $conn->ping()) {
            $conn->rollback();
        }
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function parseCsvFile($file_path) {
    $books_data = [];
    
    if (($handle = fopen($file_path, "r")) !== FALSE) {
        $header = fgetcsv($handle, 1000, ",");
        
        $expected_headers = ['title', 'author', 'isbn', 'publisher', 'category', 'shelf_location', 'description', 'publication_year', 'language', 'number_of_pages', 'condition', 'acquisition_date', 'price', 'librarianID'];
        
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            if (count($data) >= 3) {
                $book_data = [];
                foreach ($expected_headers as $index => $header) {
                    $book_data[$header] = isset($data[$index]) ? trim($data[$index]) : '';
                }
                $books_data[] = $book_data;
            }
        }
        fclose($handle);
    }
    
    return $books_data;
}

function getCategoryID($category_name) {
    global $conn;
    
    if (empty($category_name)) {
        return null;
    }
    
    $stmt = $conn->prepare("SELECT categoryID FROM book_category WHERE categoryName = ?");
    $stmt->bind_param("s", $category_name);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        return $result->fetch_assoc()['categoryID'];
    }
    
    $stmt = $conn->prepare("INSERT INTO book_category (categoryName, categoryDescription) VALUES (?, 'Auto-created from batch upload')");
    $stmt->bind_param("s", $category_name);
    
    if ($stmt->execute()) {
        return $conn->insert_id;
    }
    
    return null;
}

function downloadTemplate() {
    $filename = 'book_import_template.csv';
    $headers = [
        'title', 'author', 'isbn', 'publisher', 'category', 'shelf_location', 
        'description', 'publication_year', 'language', 'number_of_pages', 
        'condition', 'acquisition_date', 'price', 'librarianID'
    ];
    
    $sample_data = [
        'Harry Potter and the Philosopher Stone',
        'J.K. Rowling',
        '9780747532699',
        'Bloomsbury',
        'Fiction',
        'A1-B3',
        'First book in the Harry Potter series',
        '1997',
        'English',
        '223',
        'good',
        '2024-01-15',
        '25.90',
        '1'
    ];
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Pragma: public');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, $headers);
    fputcsv($output, $sample_data);
    fclose($output);
}

function handleGetBookImage() {
    global $conn;
    
    try {
        $bookID = intval($_POST['bookID']);
        
        $stmt = $conn->prepare("SELECT book_image, book_image_mime FROM book WHERE bookID = ?");
        $stmt->bind_param("i", $bookID);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $book = $result->fetch_assoc();
            
            if (!empty($book['book_image'])) {
                while (ob_get_level()) {
                    ob_end_clean();
                }
                
                header('Content-Type: ' . $book['book_image_mime']);
                echo $book['book_image'];
                exit;
            } else {
                while (ob_get_level()) {
                    ob_end_clean();
                }
                
                header('Content-Type: image/svg+xml');
                echo '<svg width="60" height="80" xmlns="http://www.w3.org/2000/svg"><rect width="60" height="80" fill="#f8f9fa" stroke="#dee2e6"/><text x="30" y="45" text-anchor="middle" font-size="10" fill="#6c757d">No Image</text></svg>';
                exit;
            }
        } else {
            header("HTTP/1.0 404 Not Found");
            exit;
        }
        
    } catch (Exception $e) {
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// Get categories and books data
$categories_query = "SELECT categoryID, categoryName FROM book_category ORDER BY categoryName";
$categories_result = $conn->query($categories_query);

$books_query = "
    SELECT 
        b.bookID,
        b.bookTitle,
        b.bookAuthor,
        b.book_ISBN,
        bc.categoryName,
        b.bookStatus,
        b.book_condition,
        b.shelf_location,
        b.acquisition_date,
        b.bookBarcode,
        b.publication_year,
        b.book_price,
        b.book_entry_date,
        b.book_image,
        b.book_image_mime,
        DATEDIFF(NOW(), b.acquisition_date) as days_old,
        CASE 
            WHEN DATEDIFF(NOW(), b.acquisition_date) >= 2555 THEN 1 
            ELSE 0 
        END as can_dispose,
        CASE 
            WHEN br.borrow_status = 'borrowed' THEN CONCAT(u.first_name, ' ', u.last_name)
            ELSE NULL 
        END as current_borrower
    FROM book b
    LEFT JOIN book_category bc ON b.categoryID = bc.categoryID
    LEFT JOIN borrow br ON b.bookID = br.bookID AND br.borrow_status = 'borrowed'
    LEFT JOIN user u ON br.userID = u.userID
    WHERE b.bookStatus != 'disposed'
    ORDER BY b.acquisition_date ASC, b.bookTitle
";
$books_result = $conn->query($books_query);

$disposal_count_query = "
    SELECT COUNT(*) as disposal_count 
    FROM book 
    WHERE bookStatus NOT IN ('disposed', 'borrowed') 
    AND DATEDIFF(NOW(), acquisition_date) >= 2555
    AND acquisition_date IS NOT NULL
";
$disposal_count_result = $conn->query($disposal_count_query);
$disposal_count = $disposal_count_result->fetch_assoc()['disposal_count'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book Management - SMK Chendering Library</title>
    
    <!-- External Libraries -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    
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

        .toggle-sidebar {
            background: none;
            border: none;
            color: var(--secondary);
            font-size: 1.2rem;
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 6px;
            transition: var(--transition);
        }

        .toggle-sidebar:hover {
            background: var(--light);
            color: var(--primary);
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

        .header-right {
            display: flex;
            align-items: center;
            gap: 1.25rem;
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

        /* Page Header */
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

        /* Alert Messages */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: var(--border-radius);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            border: 1px solid transparent;
            position: fixed;
            top: 80px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 9999;
            min-width: 400px;
            max-width: 600px;
            box-shadow: var(--card-shadow-hover);
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            border-color: rgba(16, 185, 129, 0.2);
            color: var(--success);
        }

        .alert-danger {
            background: rgba(239, 68, 68, 0.1);
            border-color: rgba(239, 68, 68, 0.2);
            color: var(--danger);
        }

        .alert i {
            font-size: 1.25rem;
        }

        /* Content Header */
        .content-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .content-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--primary);
        }

        .action-buttons {
            display: flex;
            gap: 0.75rem;
        }

        /* Button Styles */
        .btn {
            padding: 0.75rem 1.25rem;
            border: none;
            border-radius: var(--border-radius);
            font-weight: 500;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: var(--transition);
        }

        .btn-green {
            background: var(--success);
            color: white;
        }

        .btn-green:hover {
            background: #0d9f6e;
        }

        .btn-blue {
            background: var(--primary);
            color: white;
        }

        .btn-blue:hover {
            background: var(--primary-dark);
        }

        .btn-orange {
            background: var(--warning);
            color: white;
        }

        .btn-orange:hover {
            background: #d97706;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            color: white;
        }

        /* Statistics Cards */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stats-card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            overflow: hidden;
            border: 1px solid var(--light-gray);
            margin-bottom: 2rem;
            padding: 1.5rem;
            text-align: center;
            transition: transform 0.2s ease;
            position: relative;
        }

        .stats-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--card-shadow-hover);
        }

        .stats-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--accent));
        }

        .stats-number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 0.5rem;
            line-height: 1;
        }

        .stats-label {
            color: var(--medium-gray);
            font-size: 0.875rem;
            font-weight: 500;
        }

        /* Main Content Card */
        .content-card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            overflow: hidden;
            border: 1px solid var(--light-gray);
            margin-bottom: 2rem;
        }

        .table-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--light-gray);
            background: var(--light);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .table-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Table Styles */
        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th {
            padding: 1rem 1.5rem;
            text-align: left;
            background: var(--light);
            color: var(--secondary);
            font-weight: 500;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid var(--light-gray);
        }

        .table td {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--light-gray);
            color: var(--dark);
            font-size: 0.95rem;
        }

        .table tbody tr:hover {
            background: rgba(30, 58, 138, 0.025);
        }

        .table tbody tr:last-child td {
            border-bottom: none;
        }

        /* Status Badges */
        .badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            display: inline-block;
        }

        .status-available {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .status-borrowed {
            background: rgba(14, 165, 233, 0.1);
            color: var(--accent);
        }

        .status-reserved {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .status-maintenance {
            background: rgba(100, 116, 139, 0.1);
            color: var(--secondary);
        }

        .status-disposed {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        .condition-excellent {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .condition-good {
            background: rgba(34, 197, 94, 0.1);
            color: #059669;
        }

        .condition-fair {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .condition-poor {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        /* Book Images */
        .book-image {
            width: 50px;
            height: 70px;
            object-fit: cover;
            border-radius: 5px;
            border: 1px solid var(--border);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .book-image-placeholder {
            width: 50px;
            height: 70px;
            background: var(--light-bg);
            border: 1px solid var(--border);
            border-radius: 5px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-muted);
            font-size: 0.75rem;
        }

        /* Bootstrap Modal Overrides */
        .modal-dialog {
            margin: 1.75rem auto;
            max-width: 90%;
        }

        .modal-dialog-centered {
            display: flex;
            align-items: center;
            min-height: calc(100vh - 3.5rem);
        }

        .modal-dialog-centered .modal-content {
            width: 100%;
        }

        .modal-content {
            border-radius: var(--border-radius);
            border: none;
            box-shadow: var(--card-shadow-hover);
        }

        .modal-xl {
            max-width: 1200px;
        }

        .modal-lg {
            max-width: 800px;
        }

        .modal-header {
            background: var(--primary);
            color: white;
            border-bottom: none;
            border-radius: var(--border-radius) var(--border-radius) 0 0;
            padding: 1.5rem;
        }

        .modal-title {
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            color: white;
        }

        .modal-body {
            padding: 2rem;
            max-height: 70vh;
            overflow-y: auto;
        }

        .modal-footer {
            padding: 1.5rem;
            border-top: 1px solid var(--light-gray);
            background: var(--light);
        }

        .btn-close {
            background: none;
            border: none;
            color: white;
            opacity: 0.8;
            font-size: 1.5rem;
            padding: 0.5rem;
        }

        .btn-close:hover {
            opacity: 1;
            color: white;
        }

        .btn-close-white {
            filter: none;
            color: white;
        }

        /* Form styling within modals */
        .modal .form-control, .modal .form-select {
            padding: 0.75rem 1rem;
            border: 1px solid var(--light-gray);
            border-radius: var(--border-radius);
            font-size: 0.95rem;
            transition: var(--transition);
        }

        .modal .form-control:focus, .modal .form-select:focus {
            outline: none;
            border-color: var(--primary-light);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
        }

        .modal .form-label {
            font-weight: 500;
            color: var(--secondary);
            margin-bottom: 0.5rem;
            font-size: 0.95rem;
        }

        /* Age Indicators */
        .age-indicator {
            font-size: 0.75rem;
            padding: 0.2rem 0.5rem;
            border-radius: 3px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .age-dispose {
            background: var(--danger);
            color: white;
        }

        .age-old {
            background: var(--warning);
            color: var(--dark-text);
        }

        .age-new {
            background: var(--success);
            color: white;
        }

        /* Disposal Badge */
        .disposal-badge {
            position: relative;
        }

        .disposal-count {
            position: absolute;
            top: -8px;
            right: -8px;
            background: var(--danger);
            color: white;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            font-size: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            border: 2px solid var(--white);
        }

        /* Auto Dispose Button */
        .btn-auto-dispose {
            background: linear-gradient(135deg, var(--danger), #c82333);
            color: white;
            animation: pulse-red 2s infinite;
            font-weight: 600;
        }

        @keyframes pulse-red {
            0% { box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.7); }
            70% { box-shadow: 0 0 0 10px rgba(220, 53, 69, 0); }
            100% { box-shadow: 0 0 0 0 rgba(220, 53, 69, 0); }
        }

        /* Upload Area */
        .upload-area {
            border: 2px dashed var(--border);
            border-radius: 10px;
            padding: 3rem;
            text-align: center;
            background: var(--light-bg);
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .upload-area:hover, .upload-area.dragover {
            border-color: var(--primary);
            background: rgba(0, 86, 179, 0.05);
        }

        .upload-area i {
            color: var(--text-muted);
            margin-bottom: 1rem;
        }

        .upload-area:hover i, .upload-area.dragover i {
            color: var(--primary);
        }

        /* DataTables Custom Styling */
        .dataTables_wrapper {
            padding: 1.5rem;
        }

        .dataTables_length, .dataTables_filter {
            margin-bottom: 1rem;
        }

        .dataTables_length select, .dataTables_filter input {
            padding: 0.75rem 1rem !important;
            border: 1px solid var(--light-gray) !important;
            border-radius: var(--border-radius) !important;
            font-size: 0.95rem !important;
            font-family: 'Inter', sans-serif !important;
            transition: var(--transition) !important;
            background: white !important;
        }

        .dataTables_length select:focus, .dataTables_filter input:focus {
            outline: none !important;
            border-color: var(--primary-light) !important;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2) !important;
        }

        .dataTables_length label, .dataTables_filter label {
            font-weight: 500;
            color: var(--secondary);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .dataTables_info {
            color: var(--medium-gray);
            font-size: 0.9rem;
            padding: 1rem 0;
        }

        .dataTables_paginate {
            padding: 1rem 0;
        }

        .dataTables_paginate .paginate_button {
            padding: 0.5rem 1rem !important;
            margin: 0 2px !important;
            border-radius: var(--border-radius) !important;
            border: 1px solid var(--light-gray) !important;
            background: white !important;
            color: var(--secondary) !important;
            font-size: 0.9rem !important;
            transition: var(--transition) !important;
        }

        .dataTables_paginate .paginate_button:hover {
            background: var(--primary) !important;
            color: white !important;
            border-color: var(--primary) !important;
        }

        .dataTables_paginate .paginate_button.current {
            background: var(--primary) !important;
            color: white !important;
            border-color: var(--primary) !important;
        }

        .dataTables_paginate .paginate_button.disabled {
            background: var(--light) !important;
            color: var(--medium-gray) !important;
            cursor: not-allowed !important;
        }

        .dataTables_length {
            float: left;
            margin-bottom: 1rem;
        }

        .dataTables_filter {
            float: right;
            margin-bottom: 1rem;
        }

        .dataTables_info {
            float: left;
            padding-top: 1rem;
        }

        .dataTables_paginate {
            float: right;
            padding-top: 1rem;
        }

        /* Responsive Design */
        @media (max-width: 1024px) {
            .sidebar {
                width: var(--sidebar-collapsed-width);
            }
            
            .main-content {
                margin-left: var(--sidebar-collapsed-width);
            }
            
            .content-header {
                flex-direction: column;
                align-items: stretch;
            }
            
            .action-buttons {
                justify-content: flex-end;
                flex-wrap: wrap;
            }

            .alert {
                min-width: 300px;
                max-width: 90vw;
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                width: var(--sidebar-collapsed-width);
            }
            
            .main-content {
                margin-left: var(--sidebar-collapsed-width);
            }
            
            .menu-item span {
                display: none;
            }
            
            .menu-item {
                text-align: center;
                padding: 12px;
                justify-content: center;
            }
            
            .menu-item i {
                margin-right: 0;
            }
            
            .page-header {
                flex-direction: column;
                align-items: stretch;
                gap: 1rem;
            }
            
            .stats-row {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .content-header {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
            }
            
            .table-responsive {
                font-size: 0.85rem;
            }
            
            .btn-group {
                flex-wrap: wrap;
                gap: 0.25rem;
            }

            .alert {
                min-width: 250px;
                max-width: 90vw;
                top: 70px;
            }

            .dataTables_length, .dataTables_filter {
                float: none !important;
                text-align: center;
                margin-bottom: 1rem;
            }

            .dataTables_info, .dataTables_paginate {
                float: none !important;
                text-align: center;
            }
        }

        @media (max-width: 576px) {
            .stats-row {
                grid-template-columns: 1fr;
            }
            
            .page-title {
                font-size: 1.5rem;
            }
            
            .content-title {
                font-size: 1.25rem;
            }

            .action-buttons {
                flex-direction: column;
                gap: 0.5rem;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .alert {
                min-width: 200px;
                max-width: 95vw;
                top: 60px;
            }
        }

        /* Book Details Styling */
        .book-details strong {
            color: var(--dark-text);
            display: block;
            margin-bottom: 0.25rem;
        }

        .book-details small {
            color: var(--text-muted);
            font-size: 0.75rem;
        }

        .borrower-info {
            color: var(--info) !important;
            font-weight: 500;
        }

        .book-isbn {
            font-family: 'Courier New', monospace;
            font-size: 0.8rem;
            color: var(--text-muted);
        }

        .shelf-location {
            font-weight: 600;
            color: var(--primary);
            background: rgba(0, 86, 179, 0.1);
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
        }

        /* Action Buttons Styling */
        .btn-group {
            display: flex;
            gap: 0.25rem;
        }

        .btn-group .btn {
            border: 1px solid var(--border);
            background: var(--white);
            color: var(--text-muted);
            padding: 0.4rem;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 4px;
            font-size: 0.75rem;
            transition: all 0.2s ease;
        }

        .btn-group .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .btn-outline-primary:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .btn-outline-info:hover {
            background: var(--info);
            color: white;
            border-color: var(--info);
        }

        .btn-outline-success:hover {
            background: var(--success);
            color: white;
            border-color: var(--success);
        }

        .btn-outline-warning:hover {
            background: var(--warning);
            color: var(--dark-text);
            border-color: var(--warning);
        }

        .btn-outline-danger:hover {
            background: var(--danger);
            color: white;
            border-color: var(--danger);
        }

        /* Image Preview Styling */
        #imagePreview img {
            border-radius: 8px;
            box-shadow: var(--shadow);
        }

        #imagePlaceholder {
            border: 2px dashed var(--border);
            border-radius: 8px;
            background: var(--light-bg);
        }

        /* Loading States */
        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .btn .fa-spinner {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
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
        </div>
        <div class="header-right">
            <div class="user-menu" id="userMenu">
                <div class="user-avatar">
                    <?php echo strtoupper(substr($librarian_name, 0, 1)); ?>
                </div>
                <div class="user-info">
                    <div class="user-name"><?php echo htmlspecialchars($librarian_name); ?></div>
                    <div class="user-role">Librarian</div>
                </div>
            </div>
            <a href="logout.php" class="logout-btn" style="margin-left: 1rem; background: var(--danger); padding: 0.5rem 1rem; border-radius: 5px; color: white; text-decoration: none; display: flex; align-items: center; gap: 6px;">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </header>

    <!-- Sidebar Navigation -->
    <aside class="sidebar" id="sidebar">
        <nav class="sidebar-menu">
            <a href="librarian_dashboard.php" class="menu-item">
                <i class="fas fa-home"></i>
                <span>Dashboard</span>
            </a>
            <a href="user_management.php" class="menu-item">
                <i class="fas fa-users"></i>
                <span>User Management</span>
            </a>
            <a href="book_management.php" class="menu-item active">
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
                <h1 class="page-title">Book Management</h1>
                <p class="welcome-text">Manage library book collection, categories, and disposal processes</p>
            </div>
        </div>

        <!-- Alert Messages -->
        <div id="alertContainer"></div>

        <!-- Statistics Cards -->
        <div class="stats-row">
            <div class="stats-card">
                <div class="stats-number" id="totalBooks">
                    <?php 
                    $total_books = $conn->query("SELECT COUNT(*) as count FROM book WHERE bookStatus != 'disposed'");
                    echo $total_books->fetch_assoc()['count'];
                    ?>
                </div>
                <div class="stats-label">Total Books</div>
            </div>
            
            <div class="stats-card">
                <div class="stats-number text-success" id="availableBooks">
                    <?php 
                    $available_books = $conn->query("SELECT COUNT(*) as count FROM book WHERE bookStatus = 'available'");
                    echo $available_books->fetch_assoc()['count'];
                    ?>
                </div>
                <div class="stats-label">Available Books</div>
            </div>
            
            <div class="stats-card">
                <div class="stats-number text-warning" id="borrowedBooks">
                    <?php 
                    $borrowed_books = $conn->query("SELECT COUNT(*) as count FROM book WHERE bookStatus = 'borrowed'");
                    echo $borrowed_books->fetch_assoc()['count'];
                    ?>
                </div>
                <div class="stats-label">Borrowed Books</div>
            </div>
            
            <div class="stats-card">
                <div class="disposal-badge">
                    <div class="stats-number text-danger" id="disposalBooks">
                        <?php echo $disposal_count; ?>
                    </div>
                    <div class="stats-label">Need Disposal (FIFO)</div>
                    <?php if ($disposal_count > 0): ?>
                    <div class="disposal-count"><?php echo $disposal_count; ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Content Header -->
        <div class="content-header">
            <h2 class="content-title">Books Library</h2>
            <div class="action-buttons">
                <?php if ($disposal_count > 0): ?>
                <button type="button" class="btn btn-auto-dispose" onclick="autoDisposeBooks()">
                    <i class="fas fa-trash-alt"></i> Auto Dispose <?php echo $disposal_count; ?> Books (FIFO)
                </button>
                <?php endif; ?>
                
                <button type="button" class="btn btn-blue" data-bs-toggle="modal" data-bs-target="#batchUploadModal">
                    <i class="fas fa-upload"></i> Batch Upload
                </button>
                
                <button type="button" class="btn btn-green" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                    <i class="fas fa-plus"></i> Add Category
                </button>
                
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addBookModal">
                    <i class="fas fa-plus"></i> Add New Book
                </button>
            </div>
        </div>

        <!-- Main Content Card -->
        <div class="content-card">
            <div class="table-header">
                <h3 class="table-title">
                    <i class="fas fa-book"></i> Books List
                </h3>
            </div>
                <div class="table-responsive">
                    <table id="booksTable" class="table table-hover">
                        <thead>
                            <tr>
                                <th>Image</th>
                                <th>ID</th>
                                <th>Title</th>
                                <th>Author</th>
                                <th>ISBN</th>
                                <th>Category</th>
                                <th>Status</th>
                                <th>Condition</th>
                                <th>Age (Acquisition)</th>
                                <th>Location</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($books_result && $books_result->num_rows > 0): ?>
                                <?php while ($book = $books_result->fetch_assoc()): ?>
                                    <tr>
                                        <td>
                                            <?php if (!empty($book['book_image'])): ?>
                                                <img src="data:<?php echo $book['book_image_mime']; ?>;base64,<?php echo base64_encode($book['book_image']); ?>" alt="Book Cover" class="book-image">
                                            <?php else: ?>
                                                <div class="book-image-placeholder">
                                                    <i class="fas fa-book"></i>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo $book['bookID']; ?></td>
                                        <td>
                                            <div class="book-details">
                                                <strong><?php echo htmlspecialchars($book['bookTitle']); ?></strong>
                                                <?php if ($book['publication_year']): ?>
                                                    <small class="text-muted d-block">(<?php echo $book['publication_year']; ?>)</small>
                                                <?php endif; ?>
                                                <?php if ($book['current_borrower']): ?>
                                                    <small class="borrower-info d-block">
                                                        <i class="fas fa-user"></i> Borrowed by: <?php echo htmlspecialchars($book['current_borrower']); ?>
                                                    </small>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars($book['bookAuthor']); ?></td>
                                        <td>
                                            <?php if ($book['book_ISBN']): ?>
                                                <span class="book-isbn"><?php echo htmlspecialchars($book['book_ISBN']); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($book['categoryName'] ?? 'Uncategorized'); ?></td>
                                        <td>
                                            <span class="badge status-<?php echo $book['bookStatus']; ?>">
                                                <?php echo ucfirst($book['bookStatus']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge condition-<?php echo $book['book_condition']; ?>">
                                                <?php echo ucfirst($book['book_condition']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php
                                            $days_old = $book['days_old'];
                                            $years_old = round($days_old / 365, 1);
                                            
                                            if ($book['can_dispose']) {
                                                echo '<span class="age-indicator age-dispose">Dispose (' . $years_old . 'y)</span>';
                                            } elseif ($days_old > 1825) {
                                                echo '<span class="age-indicator age-old">Old (' . $years_old . 'y)</span>';
                                            } else {
                                                echo '<span class="age-indicator age-new">New (' . $years_old . 'y)</span>';
                                            }
                                            ?>
                                            <?php if ($book['acquisition_date']): ?>
                                                <small class="d-block text-muted">Acquired: <?php echo date('Y-m-d', strtotime($book['acquisition_date'])); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($book['shelf_location']): ?>
                                                <span class="shelf-location"><?php echo htmlspecialchars($book['shelf_location']); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group" role="group">
                                                <button type="button" class="btn btn-outline-primary" 
                                                        onclick="editBook(<?php echo $book['bookID']; ?>)"
                                                        title="Edit Book">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <a href="generate_barcode.php?bookID=<?php echo $book['bookID']; ?>" 
                                                   class="btn btn-outline-info" target="_blank"
                                                   title="Generate Barcode">
                                                    <i class="fas fa-barcode"></i>
                                                </a>
                                                <button type="button" class="btn btn-outline-success" 
                                                        onclick="viewBookDetails(<?php echo $book['bookID']; ?>)"
                                                        title="View Details">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <?php if ($book['can_dispose'] && $book['bookStatus'] !== 'borrowed'): ?>
                                                    <button type="button" class="btn btn-outline-warning" 
                                                            onclick="disposeBook(<?php echo $book['bookID']; ?>)"
                                                            title="Dispose Book (7+ years old - FIFO)">
                                                        <i class="fas fa-trash-alt"></i>
                                                    </button>
                                                <?php endif; ?>
                                                <?php if ($book['bookStatus'] !== 'borrowed'): ?>
                                                    <button type="button" class="btn btn-outline-danger" 
                                                            onclick="deleteBook(<?php echo $book['bookID']; ?>)"
                                                            title="Delete Book">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

<!-- Add/Edit Book Modal -->
    <div class="modal fade" id="addBookModal" tabindex="-1" aria-labelledby="addBookModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addBookModalLabel">
                        <i class="fas fa-plus"></i> Add New Book
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="bookForm" enctype="multipart/form-data">
                    <div class="modal-body">
                        <input type="hidden" id="bookID" name="bookID">
                        <input type="hidden" id="formAction" name="action" value="add_book">
                        
                        <div class="row">
                            <div class="col-md-8">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="title" class="form-label">Title <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" id="title" name="title" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="author" class="form-label">Author <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" id="author" name="author" required>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="isbn" class="form-label">ISBN</label>
                                            <input type="text" class="form-control" id="isbn" name="isbn" placeholder="9780000000000">
                                            <div class="form-text">Enter ISBN-10 or ISBN-13. Will auto-fetch book cover if available.</div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="publisher" class="form-label">Publisher</label>
                                            <input type="text" class="form-control" id="publisher" name="publisher">
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="categoryID" class="form-label">Category <span class="text-danger">*</span></label>
                                            <div class="input-group">
                                                <select class="form-select" id="categoryID" name="categoryID" required>
                                                    <option value="">Select Category</option>
                                                    <?php if ($categories_result): ?>
                                                        <?php $categories_result->data_seek(0); ?>
                                                        <?php while ($category = $categories_result->fetch_assoc()): ?>
                                                            <option value="<?php echo $category['categoryID']; ?>">
                                                                <?php echo htmlspecialchars($category['categoryName']); ?>
                                                            </option>
                                                        <?php endwhile; ?>
                                                    <?php endif; ?>
                                                </select>
                                                <button type="button" class="btn btn-outline-secondary" onclick="openAddCategoryModal()">
                                                    <i class="fas fa-plus"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="shelf_location" class="form-label">Shelf Location</label>
                                            <input type="text" class="form-control" id="shelf_location" name="shelf_location" placeholder="e.g., A1-B3">
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label for="publication_year" class="form-label">Publication Year</label>
                                            <input type="number" class="form-control" id="publication_year" name="publication_year" 
                                                   min="1800" max="<?php echo date('Y'); ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label for="language" class="form-label">Language</label>
                                            <select class="form-select" id="language" name="language">
                                                <option value="English">English</option>
                                                <option value="Bahasa Malaysia">Bahasa Malaysia</option>
                                                <option value="Chinese">Chinese</option>
                                                <option value="Tamil">Tamil</option>
                                                <option value="Other">Other</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label for="number_of_pages" class="form-label">Number of Pages</label>
                                            <input type="number" class="form-control" id="number_of_pages" name="number_of_pages" min="1">
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label for="condition" class="form-label">Condition</label>
                                            <select class="form-select" id="condition" name="condition">
                                                <option value="excellent">Excellent</option>
                                                <option value="good" selected>Good</option>
                                                <option value="fair">Fair</option>
                                                <option value="poor">Poor</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label for="acquisition_date" class="form-label">Acquisition Date</label>
                                            <input type="date" class="form-control" id="acquisition_date" name="acquisition_date" 
                                                   value="<?php echo date('Y-m-d'); ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label for="book_price" class="form-label">Book Price (RM)</label>
                                            <input type="number" class="form-control" id="book_price" name="book_price" 
                                                   min="0" step="0.01" placeholder="0.00">
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row" id="quantityRow">
                                    <div class="col-md-12">
                                        <div class="mb-3">
                                            <label for="quantity" class="form-label">Quantity (Number of copies)</label>
                                            <input type="number" class="form-control" id="quantity" name="quantity" 
                                                   min="1" max="50" value="1">
                                            <div class="form-text">Enter the number of copies to add for this book.</div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="description" class="form-label">Description</label>
                                    <textarea class="form-control" id="description" name="description" rows="3" 
                                              placeholder="Brief description of the book..."></textarea>
                                </div>
                            </div>
                            
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="book_image" class="form-label">Book Cover Image</label>
                                    <div class="text-center mb-3">
                                        <div id="imagePreview" class="mb-3" style="display: none;">
                                            <img id="previewImg" src="" alt="Book Cover Preview" style="max-width: 200px; max-height: 250px; border: 1px solid var(--border); border-radius: 8px;">
                                        </div>
                                        <div id="imagePlaceholder" class="mb-3" style="width: 200px; height: 250px; background: var(--light-bg); border: 2px dashed var(--border); border-radius: 8px; display: flex; align-items: center; justify-content: center; margin: 0 auto;">
                                            <div class="text-center text-muted">
                                                <i class="fas fa-image fa-2x mb-2"></i>
                                                <div>No Image</div>
                                            </div>
                                        </div>
                                    </div>
                                    <input type="file" class="form-control" id="book_image" name="book_image" accept="image/*" onchange="previewImage(this)">
                                    <div class="form-text">Upload book cover image (JPEG, PNG, GIF, WebP). Max size: 5MB<br>
                                    <small class="text-muted">Auto-fetch: Book cover will be automatically fetched from library API if ISBN is provided and no image uploaded</small></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                        <button type="submit" class="btn btn-green" id="saveBookBtn">
                            <i class="fas fa-save"></i> Save Book
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

 <!-- View Book Details Modal -->
    <div class="modal fade" id="viewBookModal" tabindex="-1" aria-labelledby="viewBookModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="viewBookModalLabel">
                        <i class="fas fa-book"></i> Book Details
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="bookDetailsContent">
                        <!-- Book details will be loaded here -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

<!-- Dispose Book Modal -->
    <div class="modal fade" id="disposeModal" tabindex="-1" aria-labelledby="disposeModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" style="background: var(--warning); color: var(--dark-text);">
                    <h5 class="modal-title" id="disposeModalLabel">
                        <i class="fas fa-exclamation-triangle"></i> Dispose Book (FIFO)
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="disposeForm">
                    <div class="modal-body">
                        <input type="hidden" id="disposeBookID" name="bookID">
                        <input type="hidden" name="action" value="dispose_book">
                        
                        <div class="alert alert-warning">
                            <i class="fas fa-info-circle"></i>
                            This book is eligible for disposal (7+ years old based on acquisition date, FIFO principle). This action cannot be undone easily.
                        </div>
                        
                        <div class="mb-3">
                            <label for="disposeReason" class="form-label">Disposal Reason <span class="text-danger">*</span></label>
                            <select class="form-select" id="disposeReason" name="reason" required>
                                <option value="">Select Reason</option>
                                <option value="damaged">Damaged</option>
                                <option value="lost">Lost</option>
                                <option value="outdated">Outdated</option>
                                <option value="duplicate">Duplicate</option>
                                <option value="worn_out">Worn Out</option>
                                <option value="policy_change">Policy Change</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="disposeDescription" class="form-label">Description</label>
                            <textarea class="form-control" id="disposeDescription" name="description" rows="3" 
                                      placeholder="Additional details about the disposal..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning">
                            <i class="fas fa-trash-alt"></i> Dispose Book
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

 <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" style="background: var(--danger); color: white;">
                    <h5 class="modal-title" id="deleteModalLabel">
                        <i class="fas fa-trash"></i> Delete Book
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>Warning!</strong> This action will permanently remove the book from the system.
                    </div>
                    <p>Are you sure you want to delete this book? This action cannot be undone.</p>
                    <div id="deleteBookInfo"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="confirmDeleteBtn">
                        <i class="fas fa-trash"></i> Delete Book
                    </button>
                </div>
            </div>
        </div>
    </div>

 <!-- Batch Upload Modal -->
    <div class="modal fade" id="batchUploadModal" tabindex="-1" aria-labelledby="batchUploadModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="batchUploadModalLabel">
                        <i class="fas fa-upload"></i> Batch Upload Books
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="batchUploadForm" enctype="multipart/form-data">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="batch_upload">
                        
                        <div class="mb-4">
                            <h6 class="text-primary">Step 1: Download Template</h6>
                            <p class="text-muted">Download the CSV template and fill in your book data:</p>
                            <button type="button" class="btn btn-outline-primary" onclick="downloadTemplate()">
                                <i class="fas fa-download"></i> Download CSV Template
                            </button>
                        </div>
                        
                        <hr>
                        
                        <div class="mb-4">
                            <h6 class="text-primary">Step 2: Upload Your File</h6>
                            <div class="upload-area" id="uploadArea">
                                <i class="fas fa-cloud-upload-alt fa-3x text-muted mb-3"></i>
                                <p class="text-muted">Drag and drop your CSV file here, or click to browse</p>
                                <input type="file" class="form-control" id="csvFile" name="csv_file" accept=".csv" style="display: none;">
                                <button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('csvFile').click();">
                                    <i class="fas fa-folder-open"></i> Browse Files
                                </button>
                            </div>
                            <div class="form-text">Supported formats: CSV. Maximum file size: 10MB</div>
                        </div>
                        
                        <div id="fileInfo" class="alert alert-info" style="display: none;">
                            <i class="fas fa-file"></i> <span id="fileName"></span>
                        </div>
                        
                        <div class="mb-3">
                            <h6 class="text-primary">Template Format & Features:</h6>
                            <small class="text-muted">
                                Your CSV should have the following columns:<br>
                                <code>title, author, isbn, publisher, category, shelf_location, description, publication_year, language, number_of_pages, condition, acquisition_date, price, librarianID</code><br><br>
                                <strong>New Features:</strong><br>
                                • <strong>librarianID column:</strong> Specify which librarian added each book (validates against librarian table)<br>
                                • <strong>Auto Image Feature:</strong> Books with valid ISBN will automatically get cover images from Open Library API<br>
                                • <strong>Disposal Logic:</strong> Books older than 7 years (based on acquisition_date) will be flagged for disposal using FIFO<br>
                                • <strong>Note:</strong> If librarianID is empty, the current logged-in librarian will be used
                            </small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-blue" id="uploadBtn" disabled>
                            <i class="fas fa-upload"></i> Upload Books
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<!-- Add Category Modal -->
    <div class="modal fade" id="addCategoryModal" tabindex="-1" aria-labelledby="addCategoryModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addCategoryModalLabel">
                        <i class="fas fa-plus"></i> Add New Category
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="categoryForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_category">
                        
                        <div class="mb-3">
                            <label for="categoryName" class="form-label">Category Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="categoryName" name="categoryName" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="categoryDescription" class="form-label">Description</label>
                            <textarea class="form-control" id="categoryDescription" name="categoryDescription" rows="3" 
                                      placeholder="Brief description of the category..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-green" id="saveCategoryBtn">
                            <i class="fas fa-save"></i> Save Category
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

    <script>
        // Global variables
        let booksTable;
        let currentBookID = null;

        // Initialize when document is ready
        $(document).ready(function() {
            // Sidebar toggle
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            
            if (sidebarToggle) {
                sidebarToggle.addEventListener('click', function() {
                    sidebar.classList.toggle('collapsed');
                    mainContent.classList.toggle('collapsed');
                    localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
                });
            }
            
            // Load sidebar state
            if (localStorage.getItem('sidebarCollapsed') === 'true') {
                sidebar.classList.add('collapsed');
                mainContent.classList.add('collapsed');
            }

            // Test modal functionality
            console.log('Modal script loaded');
            
            // Debug modal events
            $('.modal').on('show.bs.modal', function (e) {
                console.log('Modal showing:', $(this).attr('id'));
            });
            
            $('.modal').on('shown.bs.modal', function (e) {
                console.log('Modal shown:', $(this).attr('id'));
            });
            
            $('.modal').on('hide.bs.modal', function (e) {
                console.log('Modal hiding:', $(this).attr('id'));
            });
            
            // Ensure Bootstrap modals work
            $('[data-bs-toggle="modal"]').on('click', function(e) {
                console.log('Modal button clicked:', $(this).data('bs-target'));
                const targetModal = $($(this).data('bs-target'));
                if (targetModal.length) {
                    console.log('Target modal found:', targetModal.attr('id'));
                } else {
                    console.log('Target modal NOT found!');
                }
            });

            // Initialize DataTable
            if ($.fn.DataTable.isDataTable('#booksTable')) {
                $('#booksTable').DataTable().destroy();
            }
            
            booksTable = $('#booksTable').DataTable({
                responsive: true,
                pageLength: 25,
                order: [[1, 'desc']],
                columnDefs: [
                    {
                        targets: [0, 10], // Image and Actions columns
                        orderable: false,
                        searchable: false
                    }
                ],
                language: {
                    search: "Search books:",
                    lengthMenu: "Show _MENU_ books per page",
                    info: "Showing _START_ to _END_ of _TOTAL_ books",
                    infoEmpty: "No books found",
                    infoFiltered: "(filtered from _MAX_ total books)"
                }
            });

            // Setup drag and drop for file upload
            setupDragAndDrop();
            
            console.log('Book Management Page Loaded');
            console.log('Current Librarian ID: <?php echo $current_librarian_id; ?>');
        });

        // Open Add Category Modal function
        function openAddCategoryModal() {
            $('#categoryForm')[0].reset();
            $('#addCategoryModal').modal('show');
        }

        // Auto dispose books function
        function autoDisposeBooks() {
            if (!confirm('Are you sure you want to automatically dispose all books older than 7 years using FIFO (First-In, First-Out) principle? This action cannot be undone easily.')) {
                return;
            }
            
            $.ajax({
                url: 'book_management.php',
                type: 'POST',
                data: { action: 'auto_dispose_books' },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        showAlert('success', response.message);
                        setTimeout(() => location.reload(), 2000);
                    } else {
                        showAlert('danger', response.message);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Auto dispose error:', xhr.responseText);
                    showAlert('danger', 'An error occurred during auto disposal.');
                }
            });
        }

        // File upload handling
        $(document).on('change', '#csvFile', function() {
            const file = this.files[0];
            if (file) {
                $('#fileName').text(file.name);
                $('#fileInfo').show();
                $('#uploadBtn').prop('disabled', false);
            } else {
                $('#fileInfo').hide();
                $('#uploadBtn').prop('disabled', true);
            }
        });

        // Setup drag and drop functionality
        function setupDragAndDrop() {
            const uploadArea = document.getElementById('uploadArea');
            
            if (uploadArea) {
                ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
                    uploadArea.addEventListener(eventName, preventDefaults, false);
                });

                function preventDefaults(e) {
                    e.preventDefault();
                    e.stopPropagation();
                }

                ['dragenter', 'dragover'].forEach(eventName => {
                    uploadArea.addEventListener(eventName, highlight, false);
                });

                ['dragleave', 'drop'].forEach(eventName => {
                    uploadArea.addEventListener(eventName, unhighlight, false);
                });

                function highlight(e) {
                    uploadArea.classList.add('dragover');
                }

                function unhighlight(e) {
                    uploadArea.classList.remove('dragover');
                }

                uploadArea.addEventListener('drop', handleDrop, false);

                function handleDrop(e) {
                    const dt = e.dataTransfer;
                    const files = dt.files;
                    
                    if (files.length > 0) {
                        document.getElementById('csvFile').files = files;
                        $('#csvFile').trigger('change');
                    }
                }
            }
        }

        // Book Form Submit Handler
        $(document).on('submit', '#bookForm', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            // Show loading state
            $('#saveBookBtn').prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Saving...');
            
            $.ajax({
                url: 'book_management.php',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    console.log('Book form response:', response);
                    if (response.success) {
                        showAlert('success', response.message);
                        $('#addBookModal').modal('hide');
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        showAlert('danger', response.message || 'An error occurred while saving the book.');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Book form error:', xhr.responseText);
                    let errorMessage = 'An error occurred while saving the book.';
                    
                    try {
                        const response = JSON.parse(xhr.responseText);
                        errorMessage = response.message || errorMessage;
                    } catch(e) {
                        if (xhr.responseText.includes('Fatal error') || xhr.responseText.includes('<b>')) {
                            errorMessage = 'Server error occurred. Please check the server logs for details.';
                        }
                    }
                    showAlert('danger', errorMessage);
                },
                complete: function() {
                    $('#saveBookBtn').prop('disabled', false).html('<i class="fas fa-save"></i> Save Book');
                }
            });
        });

        // Batch Upload Form Submit Handler
        $(document).on('submit', '#batchUploadForm', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            $('#uploadBtn').prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Uploading...');
            
            $.ajax({
                url: 'book_management.php',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        let message = response.message;
                        if (response.errors && response.errors.length > 0) {
                            message += '<br><br><strong>Errors:</strong><br>' + response.errors.join('<br>');
                        }
                        showAlert('success', message);
                        $('#batchUploadModal').modal('hide');
                        setTimeout(() => location.reload(), 2000);
                    } else {
                        showAlert('danger', response.message);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Batch upload error:', xhr.responseText);
                    showAlert('danger', 'An error occurred during batch upload.');
                },
                complete: function() {
                    $('#uploadBtn').prop('disabled', false).html('<i class="fas fa-upload"></i> Upload Books');
                }
            });
        });

        // Category Form Submit Handler
        $(document).on('submit', '#categoryForm', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            $('#saveCategoryBtn').prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Saving...');
            
            $.ajax({
                url: 'book_management.php',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        showAlert('success', response.message);
                        $('#addCategoryModal').modal('hide');
                        
                        // Add new category to dropdown
                        const newOption = `<option value="${response.category.categoryID}">${response.category.categoryName}</option>`;
                        $('#categoryID').append(newOption);
                        $('#categoryID').val(response.category.categoryID);
                        
                        // Reset form
                        $('#categoryForm')[0].reset();
                    } else {
                        showAlert('danger', response.message);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Category form error:', xhr.responseText);
                    showAlert('danger', 'An error occurred while adding category.');
                },
                complete: function() {
                    $('#saveCategoryBtn').prop('disabled', false).html('<i class="fas fa-save"></i> Save Category');
                }
            });
        });

        // Dispose Form Submit Handler
        $(document).on('submit', '#disposeForm', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            $.ajax({
                url: 'book_management.php',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        showAlert('success', response.message);
                        $('#disposeModal').modal('hide');
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        showAlert('danger', response.message);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Dispose form error:', xhr.responseText);
                    showAlert('danger', 'An error occurred while disposing book.');
                }
            });
        });

        // Reset forms when modals are hidden
        $('#addBookModal').on('hidden.bs.modal', function() {
            resetBookForm();
        });

        $('#addCategoryModal').on('hidden.bs.modal', function() {
            $('#categoryForm')[0].reset();
        });

        $('#batchUploadModal').on('hidden.bs.modal', function() {
            $('#batchUploadForm')[0].reset();
            $('#fileInfo').hide();
            $('#uploadBtn').prop('disabled', true);
        });

        // Image preview function
        function previewImage(input) {
            if (input.files && input.files[0]) {
                const file = input.files[0];
                
                if (file.size > 5 * 1024 * 1024) {
                    showAlert('danger', 'Image file is too large. Maximum size is 5MB.');
                    input.value = '';
                    return;
                }
                
                const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
                if (!allowedTypes.includes(file.type)) {
                    showAlert('danger', 'Invalid image type. Only JPEG, PNG, GIF, and WebP are allowed.');
                    input.value = '';
                    return;
                }
                
                const reader = new FileReader();
                
                reader.onload = function(e) {
                    $('#previewImg').attr('src', e.target.result);
                    $('#imagePreview').show();
                    $('#imagePlaceholder').hide();
                }
                
                reader.readAsDataURL(file);
            } else {
                $('#imagePreview').hide();
                $('#imagePlaceholder').show();
            }
        }

        // Download template function
        function downloadTemplate() {
            window.location.href = 'book_management.php?action=download_template';
        }

        // Show alert function
        function showAlert(type, message) {
            const alertHtml = `
                <div class="alert alert-${type} alert-dismissible fade show" role="alert">
                    <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-triangle'}"></i>
                    ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close" style="margin-left: auto;">&times;</button>
                </div>
            `;
            $('#alertContainer').html(alertHtml);
            
            // Auto dismiss after 5 seconds
            setTimeout(() => {
                $('.alert').fadeOut(300, function() {
                    $(this).remove();
                });
            }, 5000);
        }

        // Reset book form function
        function resetBookForm() {
            $('#bookForm')[0].reset();
            $('#bookID').val('');
            $('#formAction').val('add_book');
            $('#addBookModalLabel').html('<i class="fas fa-plus"></i> Add New Book');
            $('#saveBookBtn').html('<i class="fas fa-save"></i> Save Book');
            $('#quantityRow').show();
            
            const today = new Date().toISOString().split('T')[0];
            $('#acquisition_date').val(today);
            $('#quantity').val(1);
            $('#language').val('English');
            $('#condition').val('good');
            
            $('#imagePreview').hide();
            $('#imagePlaceholder').show();
            $('#book_image').val('');
        }

        // Edit book function
        function editBook(bookID) {
            if (!bookID) {
                showAlert('danger', 'Invalid book ID');
                return;
            }
            
            $.ajax({
                url: 'book_management.php',
                type: 'POST',
                data: {
                    action: 'get_book',
                    bookID: bookID
                },
                dataType: 'json',
                success: function(response) {
                    console.log('Get book response:', response);
                    if (response.success) {
                        const book = response.book;
                        
                        $('#bookID').val(book.bookID);
                        $('#title').val(book.bookTitle || '');
                        $('#author').val(book.bookAuthor || '');
                        $('#isbn').val(book.book_ISBN || '');
                        $('#publisher').val(book.bookPublisher || '');
                        $('#categoryID').val(book.categoryID || '');
                        $('#shelf_location').val(book.shelf_location || '');
                        $('#description').val(book.book_description || '');
                        $('#publication_year').val(book.publication_year || '');
                        $('#language').val(book.language || 'English');
                        $('#number_of_pages').val(book.number_of_pages || '');
                        $('#condition').val(book.book_condition || 'good');
                        $('#acquisition_date').val(book.acquisition_date || '');
                        $('#book_price').val(book.book_price || '');
                        
                        if (book.book_image_base64) {
                            $('#previewImg').attr('src', book.book_image_base64);
                            $('#imagePreview').show();
                            $('#imagePlaceholder').hide();
                        } else {
                            $('#imagePreview').hide();
                            $('#imagePlaceholder').show();
                        }
                        
                        $('#formAction').val('update_book');
                        $('#addBookModalLabel').html('<i class="fas fa-edit"></i> Edit Book');
                        $('#saveBookBtn').html('<i class="fas fa-save"></i> Update Book');
                        $('#quantityRow').hide();
                        
                        $('#addBookModal').modal('show');
                    } else {
                        showAlert('danger', response.message || 'Failed to load book data');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Edit book error:', xhr.responseText);
                    showAlert('danger', 'Failed to load book data: ' + error);
                }
            });
        }

        // View book details function
        function viewBookDetails(bookID) {
            if (!bookID) {
                showAlert('danger', 'Invalid book ID');
                return;
            }
            
            $.ajax({
                url: 'book_management.php',
                type: 'POST',
                data: {
                    action: 'get_book',
                    bookID: bookID
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        const book = response.book;
                        
                        let detailsHtml = `
                            <div class="row">
                                <div class="col-md-4">
                                    ${book.book_image_base64 ? 
                                        `<img src="${book.book_image_base64}" alt="Book Cover" class="img-fluid rounded mb-3" style="max-height: 300px;">` :
                                        `<div class="text-center p-4 bg-light rounded mb-3">
                                            <i class="fas fa-book fa-4x text-muted"></i>
                                            <p class="text-muted mt-2">No Image Available</p>
                                        </div>`
                                    }
                                </div>
                                <div class="col-md-8">
                                    <h6 class="text-primary">Basic Information</h6>
                                    <table class="table table-sm">
                                        <tr><td><strong>Title:</strong></td><td>${book.bookTitle || 'N/A'}</td></tr>
                                        <tr><td><strong>Author:</strong></td><td>${book.bookAuthor || 'N/A'}</td></tr>
                                        <tr><td><strong>ISBN:</strong></td><td>${book.book_ISBN || 'N/A'}</td></tr>
                                        <tr><td><strong>Publisher:</strong></td><td>${book.bookPublisher || 'N/A'}</td></tr>
                                        <tr><td><strong>Category:</strong></td><td>${book.categoryName || 'Uncategorized'}</td></tr>
                                        <tr><td><strong>Language:</strong></td><td>${book.language || 'N/A'}</td></tr>
                                        <tr><td><strong>Pages:</strong></td><td>${book.number_of_pages || 'N/A'}</td></tr>
                                        <tr><td><strong>Publication Year:</strong></td><td>${book.publication_year || 'N/A'}</td></tr>
                                    </table>
                                    
                                    <h6 class="text-primary mt-3">Status & Details</h6>
                                    <table class="table table-sm">
                                        <tr><td><strong>Status:</strong></td><td><span class="badge status-${book.bookStatus}">${book.bookStatus ? book.bookStatus.charAt(0).toUpperCase() + book.bookStatus.slice(1) : 'N/A'}</span></td></tr>
                                        <tr><td><strong>Condition:</strong></td><td><span class="badge condition-${book.book_condition}">${book.book_condition ? book.book_condition.charAt(0).toUpperCase() + book.book_condition.slice(1) : 'N/A'}</span></td></tr>
                                        <tr><td><strong>Shelf Location:</strong></td><td>${book.shelf_location || 'N/A'}</td></tr>
                                        <tr><td><strong>Barcode:</strong></td><td><code>${book.bookBarcode || 'N/A'}</code></td></tr>
                                        <tr><td><strong>Price:</strong></td><td>RM ${parseFloat(book.book_price || 0).toFixed(2)}</td></tr>
                                        <tr><td><strong>Acquired:</strong></td><td>${book.acquisition_date ? new Date(book.acquisition_date).toLocaleDateString() : 'N/A'}</td></tr>
                                        <tr><td><strong>Added:</strong></td><td>${book.book_entry_date ? new Date(book.book_entry_date).toLocaleDateString() : 'N/A'}</td></tr>
                                    </table>
                                </div>
                            </div>
                        `;
                        
                        if (book.book_description) {
                            detailsHtml += `
                                <div class="row mt-3">
                                    <div class="col-12">
                                        <h6 class="text-primary">Description</h6>
                                        <p class="text-muted">${book.book_description}</p>
                                    </div>
                                </div>
                            `;
                        }
                        
                        $('#bookDetailsContent').html(detailsHtml);
                        $('#viewBookModal').modal('show');
                    } else {
                        showAlert('danger', response.message || 'Failed to load book details');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('View book error:', xhr.responseText);
                    showAlert('danger', 'Failed to load book details: ' + error);
                }
            });
        }

        // Dispose book function
        function disposeBook(bookID) {
            if (!bookID) {
                showAlert('danger', 'Invalid book ID');
                return;
            }
            
            currentBookID = bookID;
            $('#disposeBookID').val(bookID);
            $('#disposeForm')[0].reset();
            $('#disposeBookID').val(bookID);
            $('#disposeModal').modal('show');
        }

        // Delete book function
        function deleteBook(bookID) {
            if (!bookID) {
                showAlert('danger', 'Invalid book ID');
                return;
            }
            
            currentBookID = bookID;
            
            try {
                const row = booksTable.row($(`button[onclick="deleteBook(${bookID})"]`).closest('tr'));
                const rowData = row.data();
                
                if (rowData) {
                    const title = $(rowData[2]).find('strong').text() || $(rowData[2]).text() || 'Unknown';
                    const author = rowData[3] || 'Unknown';
                    const isbn = $(rowData[4]).text() || 'N/A';
                    
                    $('#deleteBookInfo').html(`
                        <strong>Book:</strong> ${title}<br>
                        <strong>Author:</strong> ${author}<br>
                        <strong>ISBN:</strong> ${isbn}
                    `);
                } else {
                    $('#deleteBookInfo').html(`<strong>Book ID:</strong> ${bookID}`);
                }
            } catch (e) {
                console.warn('Could not extract book info from table:', e);
                $('#deleteBookInfo').html(`<strong>Book ID:</strong> ${bookID}`);
            }
            
            $('#deleteModal').modal('show');
        }

        // Confirm delete button click handler
        $(document).on('click', '#confirmDeleteBtn', function() {
            if (!currentBookID) {
                showAlert('danger', 'No book selected for deletion');
                return;
            }
            
            $(this).prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Deleting...');
            
            $.ajax({
                url: 'book_management.php',
                type: 'POST',
                data: {
                    action: 'delete_book',
                    bookID: currentBookID
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        showAlert('success', response.message);
                        $('#deleteModal').modal('hide');
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        showAlert('danger', response.message || 'Failed to delete book');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Delete book error:', xhr.responseText);
                    showAlert('danger', 'An error occurred while deleting book: ' + error);
                },
                complete: function() {
                    $('#confirmDeleteBtn').prop('disabled', false).html('<i class="fas fa-trash"></i> Delete Book');
                }
            });
        });

        // Sidebar toggle function (for mobile responsiveness)
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            
            if (sidebar && mainContent) {
                sidebar.classList.toggle('collapsed');
                mainContent.classList.toggle('expanded');
                
                const isCollapsed = sidebar.classList.contains('collapsed');
                localStorage.setItem('sidebarCollapsed', isCollapsed);
            }
        }

        // Load sidebar state from localStorage
        document.addEventListener('DOMContentLoaded', function() {
            const isCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
            if (isCollapsed) {
                const sidebar = document.getElementById('sidebar');
                const mainContent = document.getElementById('mainContent');
                if (sidebar && mainContent) {
                    sidebar.classList.add('collapsed');
                    mainContent.classList.add('expanded');
                }
            }
        });

        // Add click handler for upload area
        $(document).on('click', '#uploadArea', function() {
            $('#csvFile').click();
        });

        // Prevent form submission on Enter in search fields
        $(document).on('keypress', '.dataTables_filter input', function(e) {
            if (e.which === 13) {
                e.preventDefault();
            }
        });

        // Enhanced error handling for AJAX requests
        $(document).ajaxError(function(event, xhr, settings, thrownError) {
            console.error('AJAX Error:', {
                url: settings.url,
                status: xhr.status,
                statusText: xhr.statusText,
                responseText: xhr.responseText,
                error: thrownError
            });
        });

        // Auto-refresh stats every 30 seconds
        setInterval(function() {
            // Only refresh if no modals are open
            if (!$('.modal.show').length) {
                refreshStats();
            }
        }, 30000);

        function refreshStats() {
            // Refresh statistics without full page reload
            $.ajax({
                url: 'book_management.php',
                type: 'POST',
                data: { action: 'get_stats' },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        $('#totalBooks').text(response.stats.total || 0);
                        $('#availableBooks').text(response.stats.available || 0);
                        $('#borrowedBooks').text(response.stats.borrowed || 0);
                        $('#disposalBooks').text(response.stats.disposal || 0);
                        
                        // Update disposal count badge
                        if (response.stats.disposal > 0) {
                            $('.disposal-count').text(response.stats.disposal).show();
                        } else {
                            $('.disposal-count').hide();
                        }
                    }
                },
                error: function() {
                    // Silently fail for stats refresh
                    console.log('Stats refresh failed');
                }
            });
        }
    </script>
</body>
</html>