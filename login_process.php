<?php
session_start();
require_once 'dbconnect.php';

// Set content type to JSON
header('Content-Type: application/json');

// Check if request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

// Get form data
$user_type = trim($_POST['user_type'] ?? '');
$login_id = trim($_POST['login_id'] ?? '');
$password = trim($_POST['password'] ?? '');

// Validate input
if (empty($user_type) || empty($login_id) || empty($password)) {
    echo json_encode(['success' => false, 'message' => 'Please fill in all fields']);
    exit;
}

try {
    // Prepare query based on user type
    switch ($user_type) {
        case 'student':
            $query = "SELECT s.*, u.userID, u.account_status, u.first_name, u.last_name, u.email 
                     FROM student s 
                     JOIN user u ON s.userID = u.userID 
                     WHERE s.student_id_number = ? AND u.user_type = 'student'";
            break;
            
        case 'staff':
            $query = "SELECT st.*, u.userID, u.account_status, u.first_name, u.last_name, u.email 
                     FROM staff st 
                     JOIN user u ON st.userID = u.userID 
                     WHERE st.staff_id_number = ? AND u.user_type = 'staff'";
            break;
            
        case 'librarian':
            $query = "SELECT l.*, u.userID, u.account_status, u.first_name, u.last_name, u.email 
                     FROM librarian l 
                     JOIN user u ON l.librarianID = u.userID 
                     WHERE l.librarian_id_number = ? AND u.user_type = 'librarian'";
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid user type']);
            exit;
    }
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $login_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid ID number or user type']);
        exit;
    }
    
    $user = $result->fetch_assoc();
    
    // Check account status
    if ($user['account_status'] !== 'active') {
        $status_message = ucfirst($user['account_status']);
        echo json_encode(['success' => false, 'message' => "Account is $status_message. Please contact the librarian."]);
        exit;
    }
    
    // Verify password (check both hashed and plain text for compatibility)
    $password_field = '';
    switch ($user_type) {
        case 'student':
            $password_field = 'studentPassword';
            break;
        case 'staff':
            $password_field = 'staffPassword';
            break;
        case 'librarian':
            $password_field = 'librarianPassword';
            break;
    }
    
    $stored_password = $user[$password_field];
    
    // Check if password matches (support both hashed and plain text)
    $password_valid = false;
    
    if (password_verify($password, $stored_password)) {
        // Hashed password verification
        $password_valid = true;
    } elseif ($password === $stored_password) {
        // Plain text password verification (for development/sample data)
        $password_valid = true;
    }
    
    if (!$password_valid) {
        echo json_encode(['success' => false, 'message' => 'Invalid password']);
        exit;
    }
    
    // Update last login time
    $update_login_query = "UPDATE user SET last_login = NOW() WHERE userID = ?";
    $update_stmt = $conn->prepare($update_login_query);
    $update_stmt->bind_param("i", $user['userID']);
    $update_stmt->execute();
    
    // Set session variables
    $_SESSION['user_id'] = $user['userID'];
    $_SESSION['user_type'] = $user_type;
    $_SESSION['login_id'] = $login_id;
    $_SESSION['first_name'] = $user['first_name'];
    $_SESSION['last_name'] = $user['last_name'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['logged_in'] = true;
    
    // Add specific session data based on user type
    switch ($user_type) {
        case 'student':
            $_SESSION['student_id'] = $user['studentID'];
            $_SESSION['student_name'] = $user['studentName'];
            $_SESSION['student_class'] = $user['studentClass'];
            $_SESSION['student_form'] = $user['studentForm'];
            $_SESSION['max_books_allowed'] = $user['max_books_allowed'];
            $_SESSION['is_eligible_to_borrow'] = $user['student_is_eligible_to_borrow'];
            break;
            
        case 'staff':
            $_SESSION['staff_id'] = $user['staffID'];
            $_SESSION['staff_name'] = $user['staffName'];
            $_SESSION['staff_position'] = $user['staffPosition'];
            $_SESSION['department'] = $user['department'];
            $_SESSION['max_books_allowed'] = $user['max_books_allowed'];
            $_SESSION['is_eligible_to_borrow'] = $user['staff_is_eligible_to_borrow'];
            break;
            
        case 'librarian':
            $_SESSION['librarian_id'] = $user['librarianID'];
            $_SESSION['librarian_name'] = $user['librarianName'];
            break;
    }
    
    // Check for any outstanding fines or restrictions
    $restrictions = checkUserRestrictions($conn, $user['userID'], $user_type);
    $_SESSION['restrictions'] = $restrictions;
    
    // Log successful login
    logActivity($conn, $user['userID'], 'login', "User logged in successfully");
    
    // Return success response
    echo json_encode([
        'success' => true,
        'message' => 'Login successful',
        'user_type' => $user_type,
        'user_name' => $user['first_name'] . ' ' . $user['last_name'],
        'restrictions' => $restrictions
    ]);
    
} catch (Exception $e) {
    error_log("Login error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred during login. Please try again.']);
}

/**
 * Check user restrictions (overdue books, unpaid fines, etc.)
 */
function checkUserRestrictions($conn, $userID, $user_type) {
    $restrictions = [];
    
    try {
        // Check for overdue books
        $overdue_query = "SELECT COUNT(*) as overdue_count 
                         FROM borrow 
                         WHERE userID = ? AND borrow_status = 'borrowed' AND due_date < CURDATE()";
        $stmt = $conn->prepare($overdue_query);
        $stmt->bind_param("i", $userID);
        $stmt->execute();
        $overdue_result = $stmt->get_result()->fetch_assoc();
        
        if ($overdue_result['overdue_count'] > 0) {
            $restrictions[] = [
                'type' => 'overdue_books',
                'count' => $overdue_result['overdue_count'],
                'message' => "You have {$overdue_result['overdue_count']} overdue book(s)"
            ];
        }
        
        // Check for unpaid fines
        $fines_query = "SELECT SUM(fine_amount - amount_paid) as outstanding_fines 
                       FROM fines 
                       WHERE userID = ? AND payment_status = 'unpaid'";
        $stmt = $conn->prepare($fines_query);
        $stmt->bind_param("i", $userID);
        $stmt->execute();
        $fines_result = $stmt->get_result()->fetch_assoc();
        
        $outstanding_fines = $fines_result['outstanding_fines'] ?? 0;
        if ($outstanding_fines > 0) {
            $restrictions[] = [
                'type' => 'outstanding_fines',
                'amount' => $outstanding_fines,
                'message' => "You have RM " . number_format($outstanding_fines, 2) . " in outstanding fines"
            ];
        }
        
        // Check borrowing eligibility for students and staff only
        if ($user_type !== 'librarian') {
            $table_name = $user_type;
            $eligibility_field = $user_type . '_is_eligible_to_borrow';
            
            $eligibility_query = "SELECT $eligibility_field as is_eligible 
                                 FROM $table_name 
                                 WHERE userID = ?";
            $stmt = $conn->prepare($eligibility_query);
            $stmt->bind_param("i", $userID);
            $stmt->execute();
            $eligibility_result = $stmt->get_result()->fetch_assoc();
            
            if (!$eligibility_result['is_eligible']) {
                $restrictions[] = [
                    'type' => 'borrowing_suspended',
                    'message' => "Your borrowing privileges are currently suspended"
                ];
            }
        }
        
    } catch (Exception $e) {
        error_log("Error checking restrictions: " . $e->getMessage());
    }
    
    return $restrictions;
}

/**
 * Log user activity
 */
function logActivity($conn, $userID, $action, $description) {
    try {
        $log_query = "INSERT INTO user_activity_log (userID, action, description, timestamp) 
                     VALUES (?, ?, ?, NOW())";
        
        // Create activity log table if it doesn't exist
        $create_log_table = "CREATE TABLE IF NOT EXISTS user_activity_log (
            logID INT AUTO_INCREMENT PRIMARY KEY,
            userID INT NOT NULL,
            action VARCHAR(50) NOT NULL,
            description TEXT,
            timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            ip_address VARCHAR(45),
            user_agent TEXT,
            FOREIGN KEY (userID) REFERENCES user(userID) ON DELETE CASCADE
        )";
        $conn->query($create_log_table);
        
        $stmt = $conn->prepare($log_query);
        $stmt->bind_param("iss", $userID, $action, $description);
        $stmt->execute();
        
    } catch (Exception $e) {
        error_log("Error logging activity: " . $e->getMessage());
    }
}

$conn->close();
?>