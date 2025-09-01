<?php
session_start();
require_once 'dbconnect.php';
require_once 'auth_helper.php';
checkPageAccess(); // This prevents back button access after logout

// Check if user is logged in and is a librarian
requireRole('librarian');

// Set JSON header
header('Content-Type: application/json');

try {
    // Query to count overdue books
    $query = "
        SELECT COUNT(*) as overdue_count
        FROM borrow 
        WHERE borrow_status = 'borrowed' 
        AND due_date < CURDATE()
    ";
    
    $result = $conn->query($query);
    
    if (!$result) {
        throw new Exception('Database query failed: ' . $conn->error);
    }
    
    $data = $result->fetch_assoc();
    $overdue_count = intval($data['overdue_count']);
    
    // Get details of overdue books if needed
    $overdue_details = [];
    if ($overdue_count > 0) {
        $details_query = "
            SELECT 
                b.borrowID,
                b.userID,
                b.bookID,
                b.borrow_date,
                b.due_date,
                DATEDIFF(CURDATE(), b.due_date) as days_overdue,
                u.first_name,
                u.last_name,
                u.email,
                bk.bookTitle,
                bk.bookAuthor
            FROM borrow b
            LEFT JOIN user u ON b.userID = u.userID
            LEFT JOIN book bk ON b.bookID = bk.bookID
            WHERE b.borrow_status = 'borrowed' 
            AND b.due_date < CURDATE()
            ORDER BY b.due_date ASC
            LIMIT 10
        ";
        
        $details_result = $conn->query($details_query);
        
        if ($details_result && $details_result->num_rows > 0) {
            while ($row = $details_result->fetch_assoc()) {
                $overdue_details[] = [
                    'borrowID' => $row['borrowID'],
                    'borrower' => $row['first_name'] . ' ' . $row['last_name'],
                    'book' => $row['bookTitle'],
                    'days_overdue' => $row['days_overdue'],
                    'due_date' => $row['due_date']
                ];
            }
        }
    }
    
    // Return JSON response
    echo json_encode([
        'success' => true,
        'overdue_count' => $overdue_count,
        'overdue_details' => $overdue_details,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    // Log error for debugging
    error_log('Check Overdue Error: ' . $e->getMessage());
    
    // Return error response
    echo json_encode([
        'success' => false,
        'error' => 'Failed to check overdue books',
        'message' => $e->getMessage()
    ]);
}

// Close database connection
$conn->close();
?>