<?php
// === START OUTPUT BUFFERING IMMEDIATELY ===
ob_start();

// === INCLUDE DATABASE CONNECTION FIRST ===
require_once 'dbconnect.php';

// === AJAX HANDLER: MUST BE AT THE VERY TOP ===
if (isset($_GET['action']) && $_GET['action'] === 'get_user') {
    $user_id = $_GET['user_id'] ?? 0;

    // Clean any output (whitespace, errors, etc.)
    if (ob_get_level()) ob_clean();

    // Set JSON header
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    try {
        $stmt = $conn->prepare("SELECT u.userID, u.user_type, u.first_name, u.last_name, u.email, u.phone_number FROM user u WHERE u.userID = ? AND u.user_type IN ('student', 'staff')");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();
            echo json_encode(['success' => true, 'user' => $user]);
        } else {
            echo json_encode(['success' => false, 'message' => 'User not found']);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Server error']);
    }
    exit; // 🔥 STOP HERE – DO NOT CONTINUE
}
// === END AJAX HANDLER ===

// Now continue with session and auth
session_start();
require_once 'auth_helper.php';
checkPageAccess();
requireRole('librarian');

$librarian_info = getCurrentUser();
$librarian_name = getUserDisplayName();

$message = '';
$message_type = '';

// Your hardcoded arrays...
$student_classes = ['Bijak', 'Amanah'];
$staff_positions = ['Principal', 'Vice Principal', 'Senior Assistant', 'Mathematics Teacher', 'Science Teacher', 'English Teacher', 'Malay Teacher', 'History Teacher', 'Geography Teacher', 'Physics Teacher', 'Chemistry Teacher', 'Biology Teacher', 'Computer Science Teacher', 'Art Teacher', 'Physical Education Teacher', 'Librarian', 'Administrative Officer', 'Clerk', 'Laboratory Assistant', 'Security Guard', 'Janitor'];
$staff_departments = ['Administration', 'Mathematics Department', 'Language Department', 'Humanities Department', 'Technical Department', 'Library', 'Student Affairs', 'Facilities Management', 'Information Technology'];

// Helper functions
function generateStudentID() {
    global $conn;
    $year = date('y');
    $query = "SELECT MAX(CAST(SUBSTRING(student_id_number, 4) AS UNSIGNED)) as max_id FROM student WHERE student_id_number LIKE 'STU$year%'";
    $result = $conn->query($query);
    $row = $result->fetch_assoc();
    $next_number = $row['max_id'] ? $row['max_id'] + 1 : 1;
    return 'STU' . $year . str_pad($next_number, 3, '0', STR_PAD_LEFT);
}

function generateStaffID() {
    global $conn;
    $year = date('y');
    $query = "SELECT MAX(CAST(SUBSTRING(staff_id_number, 4) AS UNSIGNED)) as max_id FROM staff WHERE staff_id_number LIKE 'STF$year%'";
    $result = $conn->query($query);
    $row = $result->fetch_assoc();
    $next_number = $row['max_id'] ? $row['max_id'] + 1 : 1;
    return 'STF' . $year . str_pad($next_number, 3, '0', STR_PAD_LEFT);
}

function validateEmail($email, $user_type) {
    if ($user_type === 'student') {
        return preg_match('/^[a-zA-Z0-9._%+-]+@student\.smkchendering\.edu\.my$/', $email);
    } elseif ($user_type === 'staff') {
        return preg_match('/^[a-zA-Z0-9._%+-]+@smkchendering\.edu\.my$/', $email);
    }
    return false;
}

function convertDateFormat($date) {
    // Handle multiple date formats
    if (empty($date)) return null;
    
    // Check if date is already in YYYY-MM-DD format
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return $date;
    }
    
    // Check if date is in DD/MM/YYYY format
    if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $date)) {
        $parts = explode('/', $date);
        return sprintf('%04d-%02d-%02d', $parts[2], $parts[1], $parts[0]);
    }
    
    // Check if date is in MM/DD/YYYY format
    if (preg_match('/^\d{1,2}-\d{1,2}-\d{4}$/', $date)) {
        $parts = explode('-', $date);
        return sprintf('%04d-%02d-%02d', $parts[2], $parts[0], $parts[1]);
    }
    
    return null;
}

function registerStudent($data) {
    global $conn;
    
    // Validate input
    $required_fields = ['first_name', 'last_name', 'email', 'student_class', 'student_form'];
    foreach ($required_fields as $field) {
        if (empty($data[$field])) {
            return ['success' => false, 'message' => "Please fill in all required fields"];
        }
    }
    
    // Validate email domain
    if (!validateEmail($data['email'], 'student')) {
        return ['success' => false, 'message' => "Email must use @student.smkchendering.edu.my domain"];
    }
    
    // Check if email already exists
    $check_stmt = $conn->prepare("SELECT userID FROM user WHERE email = ?");
    $check_email = $data['email'];
    $check_stmt->bind_param("s", $check_email);
    $check_stmt->execute();
    if ($check_stmt->get_result()->num_rows > 0) {
        return ['success' => false, 'message' => 'Email already exists'];
    }
    
    $conn->begin_transaction();
    try {
        // Generate student ID
        $student_id = generateStudentID();
        
        // Insert into user table with default password
        $default_password = password_hash("Pass123*", PASSWORD_DEFAULT);
        $user_stmt = $conn->prepare("INSERT INTO user (user_type, login_id, password, first_name, last_name, email, phone_number, must_change_password) VALUES ('student', ?, ?, ?, ?, ?, ?, 1)");
        $first_name = $data['first_name'];
        $last_name = $data['last_name'];
        $phone_number = $data['phone_number'] ?? '';
        $user_stmt->bind_param("ssssss", $student_id, $default_password, $first_name, $last_name, $data['email'], $phone_number);
        $user_stmt->execute();
        $user_id = $conn->insert_id;
        
        // Extract form number from student_form (e.g., "Form 1" -> "1")
        $form_number = intval(substr($data['student_form'], -1));
        $class_name = $form_number . ' ' . $data['student_class'];
        
        // Convert date format if provided
        $dob = convertDateFormat($data['dob'] ?? '');
        
        // Insert into student table - FIXED: removed one parameter
        $student_stmt = $conn->prepare("INSERT INTO student (student_id_number, studentName, studentEmail, studentPassword, studentClass, studentForm, studentPhoneNo, studentDOB, userID, student_is_eligible_to_borrow, max_books_allowed) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 3)");
        $student_name = $first_name . ' ' . $last_name;
        $student_stmt->bind_param("ssssssssi", $student_id, $student_name, $data['email'], $default_password, $class_name, $data['student_form'], $phone_number, $dob, $user_id);
        $student_stmt->execute();
        
        $conn->commit();
        return ['success' => true, 'message' => 'Student registered successfully with ID: ' . $student_id];
        
    } catch (Exception $e) {
        $conn->rollback();
        return ['success' => false, 'message' => 'Registration failed: ' . $e->getMessage()];
    }
}

function registerStaff($data) {
    global $conn;
    
    // Validate input
    $required_fields = ['first_name', 'last_name', 'email', 'position', 'department'];
    foreach ($required_fields as $field) {
        if (empty($data[$field])) {
            return ['success' => false, 'message' => "Please fill in all required fields"];
        }
    }
    
    // Validate email domain
    if (!validateEmail($data['email'], 'staff')) {
        return ['success' => false, 'message' => "Email must use @smkchendering.edu.my domain"];
    }
    
    // Check if email already exists
    $check_stmt = $conn->prepare("SELECT userID FROM user WHERE email = ?");
    $check_email = $data['email'];
    $check_stmt->bind_param("s", $check_email);
    $check_stmt->execute();
    if ($check_stmt->get_result()->num_rows > 0) {
        return ['success' => false, 'message' => 'Email already exists'];
    }
    
    $conn->begin_transaction();
    try {
        // Generate staff ID
        $staff_id = generateStaffID();
        
        // Insert into user table with default password
        $default_password = password_hash("Pass123*", PASSWORD_DEFAULT);
        $user_stmt = $conn->prepare("INSERT INTO user (user_type, login_id, password, first_name, last_name, email, phone_number, must_change_password) VALUES ('staff', ?, ?, ?, ?, ?, ?, 1)");
        $first_name = $data['first_name'];
        $last_name = $data['last_name'];
        $phone_number = $data['phone_number'] ?? '';
        $user_stmt->bind_param("ssssss", $staff_id, $default_password, $first_name, $last_name, $data['email'], $phone_number);
        $user_stmt->execute();
        $user_id = $conn->insert_id;
        
        // Convert date format if provided
        $dob = convertDateFormat($data['dob'] ?? '');
        
        // Insert into staff table
        $staff_stmt = $conn->prepare("INSERT INTO staff (staff_id_number, staffName, staffEmail, staffPassword, staffPosition, department, staffPhoneNo, staffDOB, userID, max_books_allowed) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 5)");
        $staff_name = $first_name . ' ' . $last_name;
        $staff_stmt->bind_param("ssssssssi", $staff_id, $staff_name, $data['email'], $default_password, $data['position'], $data['department'], $phone_number, $dob, $user_id);
        $staff_stmt->execute();
        
        $conn->commit();
        return ['success' => true, 'message' => 'Staff registered successfully with ID: ' . $staff_id];
        
    } catch (Exception $e) {
        $conn->rollback();
        return ['success' => false, 'message' => 'Registration failed: ' . $e->getMessage()];
    }
}

function promoteStudents($promotion_year) {
    global $conn;
    
    $conn->begin_transaction();
    try {
        // Get all active students
        $students_query = "SELECT s.studentID, s.studentForm, s.studentClass, s.studentStatus FROM student s JOIN user u ON s.userID = u.userID WHERE u.account_status = 'active' AND s.studentStatus = 'active'";
        $students_result = $conn->query($students_query);
        
        $promotions = [];
        while ($student = $students_result->fetch_assoc()) {
            $current_form = $student['studentForm'];
            $current_class = $student['studentClass'];
            
            // Determine new form and class
            $form_number = intval(substr($current_form, -1));
            $class_number = intval(substr($current_class, 0, 1));
            
            if ($form_number < 5) {
                $new_form = 'Form ' . ($form_number + 1);
                $new_class_prefix = ($form_number + 1) . ' ';
                
                // Find matching class based on current class name
                $class_type = substr($current_class, 2);
                $new_class = $new_class_prefix . $class_type;
                
                $promotions[] = [
                    'studentID' => $student['studentID'],
                    'old_form' => $current_form,
                    'new_form' => $new_form,
                    'old_class' => $current_class,
                    'new_class' => $new_class
                ];
            } else {
                // Student is in Form 5, mark as graduated
                $graduation_stmt = $conn->prepare("UPDATE student SET studentStatus = 'graduated' WHERE studentID = ?");
                $graduation_stmt->bind_param("i", $student['studentID']);
                $graduation_stmt->execute();
                
                $account_stmt = $conn->prepare("UPDATE user SET account_status = 'inactive' WHERE userID = (SELECT userID FROM student WHERE studentID = ?)");
                $account_stmt->bind_param("i", $student['studentID']);
                $account_stmt->execute();
            }
        }
        
        // Apply promotions
        foreach ($promotions as $promo) {
            $update_stmt = $conn->prepare("UPDATE student SET studentForm = ?, studentClass = ? WHERE studentID = ?");
            $update_stmt->bind_param("ssi", $promo['new_form'], $promo['new_class'], $promo['studentID']);
            $update_stmt->execute();
        }
        
        $conn->commit();
        
        $count = count($promotions);
        return [
            'success' => true, 
            'message' => "Successfully promoted $count students to the next level. " . 
                        (count($promotions) > 0 ? "Next level: " . $promotions[0]['new_form'] : "") . 
                        " Students in Form 5 have been marked as graduated."
        ];
        
    } catch (Exception $e) {
        $conn->rollback();
        return ['success' => false, 'message' => 'Promotion failed: ' . $e->getMessage()];
    }
}

function toggleUserEligibility($user_id, $user_type, $is_eligible) {
    global $conn;
    
    if ($user_type !== 'student' && $user_type !== 'staff') {
        return ['success' => false, 'message' => 'User type must be student or staff'];
    }
    
    try {
        if ($user_type === 'student') {
            $stmt = $conn->prepare("UPDATE student SET student_is_eligible_to_borrow = ? WHERE userID = ?");
            $stmt->bind_param("ii", $is_eligible, $user_id);
        } else {
            // For staff, we don't have a specific eligibility field, but we can still use the same logic
            // We'll just return success since staff eligibility is based on overdue books
            return [
                'success' => true, 
                'message' => 'Staff borrowing status updated successfully'
            ];
        }
        
        $stmt->execute();
        
        if ($stmt->affected_rows > 0) {
            return [
                'success' => true, 
                'message' => ucfirst($user_type) . ' borrowing eligibility ' . ($is_eligible ? 'enabled' : 'disabled') . ' successfully'
            ];
        } else {
            return ['success' => false, 'message' => 'No changes were made'];
        }
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Update failed: ' . $e->getMessage()];
    }
}

function resetUserPassword($user_id, $user_type) {
    global $conn;
    
    try {
        $default_password = password_hash("Pass123*", PASSWORD_DEFAULT);
        
        $stmt = $conn->prepare("UPDATE user SET password = ?, must_change_password = 1 WHERE userID = ?");
        $stmt->bind_param("si", $default_password, $user_id);
        $stmt->execute();
        
        if ($stmt->affected_rows > 0) {
            // Also update the password in student or staff table if needed
            if ($user_type === 'student') {
                $stmt2 = $conn->prepare("UPDATE student SET studentPassword = ? WHERE userID = ?");
                $stmt2->bind_param("si", $default_password, $user_id);
                $stmt2->execute();
            } elseif ($user_type === 'staff') {
                $stmt2 = $conn->prepare("UPDATE staff SET staffPassword = ? WHERE userID = ?");
                $stmt2->bind_param("si", $default_password, $user_id);
                $stmt2->execute();
            }
            
            return [
                'success' => true, 
                'message' => 'Password reset successfully. User must change it on next login.'
            ];
        } else {
            return ['success' => false, 'message' => 'No changes were made'];
        }
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Password reset failed: ' . $e->getMessage()];
    }
}

function editUser($data) {
    global $conn;
    $user_id = $data['user_id'] ?? null;
    $user_type = $data['user_type'] ?? '';
    $first_name = trim($data['first_name'] ?? '');
    $last_name = trim($data['last_name'] ?? '');
    $email = trim($data['email'] ?? '');
    $phone_number = trim($data['phone_number'] ?? '');

    if (!$user_id || !$user_type || !in_array($user_type, ['student', 'staff'])) {
        return ['success' => false, 'message' => 'Invalid user data'];
    }

    $conn->begin_transaction();
    try {
        // Update user table
        $stmt = $conn->prepare("UPDATE user SET first_name = ?, last_name = ?, email = ?, phone_number = ? WHERE userID = ?");
        
        // ✅ Assign to variables first
        $fname = $first_name;
        $lname = $last_name;
        $email_val = $email;
        $phone_val = $phone_number;

        // Now bind using variables
        $stmt->bind_param("ssssi", $fname, $lname, $email_val, $phone_val, $user_id);
        $stmt->execute();

        if ($stmt->affected_rows === 0) {
            throw new Exception('No changes were made to user record');
        }

        $conn->commit();
        return ['success' => true, 'message' => 'User updated successfully'];
    } catch (Exception $e) {
        $conn->rollback();
        return ['success' => false, 'message' => 'Update failed: ' . $e->getMessage()];
    }
}

function handleBatchUpload($file, $upload_type) {
    global $conn;
    
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'Error uploading file'];
    }
    
    $success_count = 0;
    $error_count = 0;
    $errors = [];
    
    if (($handle = fopen($file['tmp_name'], 'r')) !== false) {
        $headers = fgetcsv($handle);
        $header_map = array_flip($headers);
        
        $row_index = 0;
        
        while (($row = fgetcsv($handle)) !== false) {
            $row_index++;
            
            $row_data = array_combine($headers, $row);
            
            // Skip empty rows
            if (empty($row_data['first_name']) || empty($row_data['last_name']) || empty($row_data['email'])) {
                continue;
            }
            
            // Process based on upload type
            if ($upload_type === 'student') {
                $result = registerStudent($row_data);
            } else {
                $result = registerStaff($row_data);
            }
            
            if ($result['success']) {
                $success_count++;
            } else {
                $error_count++;
                $errors[] = "Row " . ($row_index + 1) . ": " . $result['message'];
            }
        }
        
        fclose($handle);
    }
    
    $message = "Batch upload completed. Success: $success_count, Errors: $error_count";
    if (!empty($errors)) {
        $message .= " Errors:" . implode("", array_slice($errors, 0, 10));
        if (count($errors) > 10) {
            $message .= "... and " . (count($errors) - 10) . " more errors";
        }
    }
    
    return ['success' => $error_count === 0, 'message' => $message];
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'register_student':
                    $result = registerStudent($_POST);
                    $message = $result['message'];
                    $message_type = $result['success'] ? 'success' : 'danger';
                    break;
                    
                case 'register_staff':
                    $result = registerStaff($_POST);
                    $message = $result['message'];
                    $message_type = $result['success'] ? 'success' : 'danger';
                    break;
                    
                case 'promote_students':
                    $result = promoteStudents($_POST['promotion_year']);
                    $message = $result['message'];
                    $message_type = $result['success'] ? 'success' : 'danger';
                    break;
                    
                case 'toggle_eligibility':
                    $result = toggleUserEligibility($_POST['user_id'], $_POST['user_type'], $_POST['is_eligible']);
                    $message = $result['message'];
                    $message_type = $result['success'] ? 'success' : 'danger';
                    break;
                    
                case 'reset_password':
                    $result = resetUserPassword($_POST['user_id'], $_POST['user_type']);
                    $message = $result['message'];
                    $message_type = $result['success'] ? 'success' : 'danger';
                    break;
                    
                case 'edit_user':
                    $result = editUser($_POST);
                    $message = $result['message'];
                    $message_type = $result['success'] ? 'success' : 'danger';
                    break;
                    
                case 'batch_upload':
                    $result = handleBatchUpload($_FILES['csv_file'], $_POST['upload_type']);
                    $message = $result['message'];
                    $message_type = $result['success'] ? 'success' : 'danger';
                    break;
            }
        }
    } catch (Exception $e) {
        $message = 'An error occurred: ' . $e->getMessage();
        $message_type = 'danger';
        error_log("User management error: " . $e->getMessage());
    }
}

// Handle search and filter
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Build WHERE clause for filtering (exclude librarians)
// Build WHERE clause: only students and staff (exclude librarians)
$where_clause = "WHERE u.user_type IN ('student', 'staff')";
$params = [];
$param_types = '';

// Add filter (student/staff)
if ($filter === 'student') {
    $where_clause .= " AND u.user_type = 'student'";
} elseif ($filter === 'staff') {
    $where_clause .= " AND u.user_type = 'staff'";
}

// Add search
if (!empty($search)) {
    $where_clause .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR u.login_id LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
    $param_types .= 'ssss';
}

// Get total users for pagination
$total_users_query = "SELECT COUNT(*) as total FROM user u $where_clause";
$total_stmt = $conn->prepare($total_users_query);
if (!empty($param_types)) {
    $total_stmt->bind_param($param_types, ...$params);
}
$total_stmt->execute();
$total_result = $total_stmt->get_result();
$total_users = $total_result->fetch_assoc()['total'];
$total_pages = ceil($total_users / $limit);

// Get users with pagination
$users_query = "SELECT u.userID, u.user_type, u.login_id, u.first_name, u.last_name, u.email, u.phone_number, u.account_status, u.created_date,
                CASE WHEN u.user_type = 'student' THEN s.studentClass ELSE NULL END as student_class,
                CASE WHEN u.user_type = 'student' THEN s.studentForm ELSE NULL END as student_form,
                CASE WHEN u.user_type = 'student' THEN s.student_is_eligible_to_borrow ELSE NULL END as student_eligible,
                CASE WHEN u.user_type = 'staff' THEN st.staffPosition ELSE NULL END as staff_position,
                CASE WHEN u.user_type = 'staff' THEN st.department ELSE NULL END as staff_department,
                CASE WHEN u.user_type = 'student' THEN s.max_books_allowed ELSE NULL END as max_books_allowed,
                CASE WHEN u.user_type = 'staff' THEN st.max_books_allowed ELSE NULL END as staff_max_books_allowed,
                -- Determine borrowing status based on overdue, lost, or damaged books
                CASE 
                    WHEN u.user_type = 'student' AND s.student_is_eligible_to_borrow = 1 THEN 
                        CASE 
                            WHEN EXISTS (SELECT 1 FROM borrow b WHERE b.userID = u.userID AND b.borrow_status IN ('overdue', 'lost', 'damaged')) 
                            THEN 'Not Eligible'
                            ELSE 'Eligible'
                        END
                    WHEN u.user_type = 'student' AND s.student_is_eligible_to_borrow = 0 THEN 'Suspended'
                    WHEN u.user_type = 'staff' THEN 
                        CASE 
                            WHEN EXISTS (SELECT 1 FROM borrow b WHERE b.userID = u.userID AND b.borrow_status IN ('overdue', 'lost', 'damaged')) 
                            THEN 'Not Eligible'
                            ELSE 'Eligible'
                        END
                    ELSE 'N/A'
                END as borrowing_status
                FROM user u 
                LEFT JOIN student s ON u.userID = s.userID 
                LEFT JOIN staff st ON u.userID = st.userID 
                $where_clause 
                ORDER BY u.created_date DESC 
                LIMIT ? OFFSET ?";
$users_stmt = $conn->prepare($users_query);
$params[] = $limit;
$params[] = $offset;
$param_types .= 'ii';
$users_stmt->bind_param($param_types, ...$params);
$users_stmt->execute();
$users_result = $users_stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - SMK Chendering Library</title>
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

        /* Search and Filter */
        .search-filter {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }

        .search-container {
            flex: 1;
            min-width: 200px;
            position: relative;
        }

        .search-input {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 2.5rem;
            border: 1px solid var(--light-gray);
            border-radius: var(--border-radius);
            font-family: 'Inter', sans-serif;
            font-size: 0.95rem;
            transition: var(--transition);
        }

        .search-input:focus {
            outline: none;
            border-color: var(--primary-light);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
        }

        .search-icon {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--medium-gray);
        }

        .filter-select {
            padding: 0.75rem 1rem;
            border: 1px solid var(--light-gray);
            border-radius: var(--border-radius);
            font-family: 'Inter', sans-serif;
            font-size: 0.95rem;
            background: white;
            transition: var(--transition);
        }

        .filter-select:focus {
            outline: none;
            border-color: var(--primary-light);
        }

        .search-btn {
            background: var(--primary);
            color: white;
            border: none;
            padding: 0.75rem 1.25rem;
            border-radius: var(--border-radius);
            font-weight: 500;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: var(--transition);
        }

        .search-btn:hover {
            background: var(--primary-dark);
        }

        /* Users Table */
        .users-card {
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

        .users-table {
            width: 100%;
            border-collapse: collapse;
        }

        .users-table th {
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

        .users-table td {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--light-gray);
            color: var(--dark);
            font-size: 0.95rem;
        }

        .users-table tr:hover {
            background: rgba(30, 58, 138, 0.025);
        }

        .users-table tr:last-child td {
            border-bottom: none;
        }

        .type-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            display: inline-block;
        }

        .type-student {
            background: rgba(30, 58, 138, 0.1);
            color: var(--primary);
        }

        .type-staff {
            background: rgba(100, 116, 139, 0.1);
            color: var(--secondary);
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            display: inline-block;
        }

        .status-active {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .status-inactive {
            background: rgba(100, 116, 139, 0.1);
            color: var(--secondary);
        }

        .eligibility-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            display: inline-block;
        }

        .eligibility-eligible {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .eligibility-not-eligible {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        .eligibility-suspended {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        /* Action Buttons */
        .action-buttons-table {
            display: flex;
            gap: 0.5rem;
        }

        .btn-sm {
            padding: 0.25rem 0.5rem;
            border: none;
            border-radius: 6px;
            font-size: 0.8rem;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .btn-edit {
            background: var(--light);
            color: var(--primary);
        }

        .btn-edit:hover {
            background: rgba(30, 58, 138, 0.1);
        }

        .btn-toggle {
            background: rgba(100, 116, 139, 0.1);
            color: var(--secondary);
        }

        .btn-toggle:hover {
            background: rgba(100, 116, 139, 0.2);
        }

        .btn-reset {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .btn-reset:hover {
            background: rgba(245, 158, 11, 0.2);
        }

        .btn-danger {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        .btn-danger:hover {
            background: rgba(239, 68, 68, 0.2);
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            padding: 1.5rem;
            background: white;
            border-top: 1px solid var(--light-gray);
        }

        .pagination-info {
            font-size: 0.9rem;
            color: var(--medium-gray);
        }

        .page-link {
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--light-gray);
            border-radius: 6px;
            color: var(--secondary);
            text-decoration: none;
            transition: var(--transition);
            font-size: 0.9rem;
        }

        .page-link:hover {
            background: rgba(30, 58, 138, 0.05);
            border-color: var(--primary-light);
        }

        .page-link.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .page-link.disabled {
            color: var(--medium-gray);
            cursor: not-allowed;
            opacity: 0.5;
        }

        /* Modals */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: var(--transition);
        }

        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .modal {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            transform: translateY(-20px);
            transition: var(--transition);
            position: relative;
        }

        .modal-overlay.active .modal {
            transform: translateY(0);
        }

        .modal-close {
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: none;
            border: none;
            font-size: 1.5rem;
            color: var(--medium-gray);
            cursor: pointer;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: var(--transition);
        }

        .modal-close:hover {
            background: var(--light);
            color: var(--dark);
        }

        .modal-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--light-gray);
            background: var(--light);
            border-radius: var(--border-radius) var(--border-radius) 0 0;
        }

        .modal-header h2 {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--primary);
            font-size: 1.25rem;
            font-weight: 600;
        }

        .modal-body {
            padding: 1.5rem;
        }

        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--secondary);
            font-size: 0.95rem;
        }

        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid var(--light-gray);
            border-radius: var(--border-radius);
            font-family: 'Inter', sans-serif;
            font-size: 0.95rem;
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-light);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
        }

        /* Add autocomplete attributes to fix browser warnings */
        .form-control[name="first_name"], .form-control[name="last_name"] {
            autocomplete: "name";
        }
        
        .form-control[name="email"] {
            autocomplete: "email";
        }
        
        .form-control[name="phone_number"] {
            autocomplete: "tel";
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .password-info {
            background: rgba(30, 58, 138, 0.05);
            border: 1px solid rgba(30, 58, 138, 0.1);
            border-radius: var(--border-radius);
            padding: 1rem;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
            color: var(--primary);
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
        }

        .password-info i {
            font-size: 1.1rem;
            margin-top: 0.1rem;
        }

        .email-hint {
            font-size: 0.8rem;
            color: var(--medium-gray);
            margin-top: 0.25rem;
            font-style: italic;
        }

        .form-submit {
            background: var(--primary);
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: var(--border-radius);
            font-weight: 500;
            font-size: 1rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: var(--transition);
            width: 100%;
        }

        .form-submit:hover {
            background: var(--primary-dark);
        }

        .form-submit i {
            font-size: 1.1rem;
        }

        /* Batch Upload Modal */
        .batch-upload-form {
            margin-bottom: 1.5rem;
        }

        .batch-upload-form .form-group {
            margin-bottom: 1rem;
        }

        .batch-upload-form .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--text-dark);
            font-size: 0.95rem;
        }

        .batch-upload-form .form-group select,
        .batch-upload-form .form-group input[type="file"] {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid var(--light-gray);
            border-radius: var(--border-radius);
            font-family: 'Inter', sans-serif;
            font-size: 0.95rem;
            transition: var(--transition);
        }

        .batch-upload-form .form-group input[type="file"] {
            padding: 0.75rem;
            border: 1px dashed var(--light-gray);
            background: var(--light);
        }

        .batch-upload-form .form-group input[type="file"]:hover {
            border-color: var(--primary-light);
        }

        .upload-btn {
            background: var(--primary);
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: var(--border-radius);
            font-weight: 500;
            font-size: 1rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: var(--transition);
            width: 100%;
            margin-bottom: 1.5rem;
        }

        .upload-btn:hover {
            background: var(--primary-dark);
        }

        .upload-btn i {
            font-size: 1.1rem;
        }

        .csv-templates {
            margin-top: 1.5rem;
            padding: 1.5rem;
            background: var(--light);
            border-radius: var(--border-radius);
            border: 1px solid var(--light-gray);
        }

        .csv-templates h3 {
            margin-bottom: 1rem;
            color: var(--primary);
            font-size: 1.1rem;
            font-weight: 600;
        }

        .template-section {
            margin-bottom: 1.5rem;
        }

        .template-section h4 {
            margin-bottom: 0.5rem;
            color: var(--dark);
            font-size: 1rem;
            font-weight: 500;
        }

        .download-template {
            background: var(--success);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            font-weight: 500;
            font-size: 0.9rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: var(--transition);
        }

        .download-template:hover {
            background: #0d9f6e;
        }

        .download-template i {
            font-size: 0.9rem;
        }

        .important-notes {
            margin-top: 1.5rem;
            padding: 1.5rem;
            background: rgba(245, 158, 11, 0.1);
            border-radius: var(--border-radius);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }

        .important-notes h4 {
            margin-bottom: 1rem;
            color: var(--warning);
            font-size: 1rem;
            font-weight: 600;
        }

        .important-notes ul {
            margin: 0;
            padding-left: 1.5rem;
        }

        .important-notes li {
            margin-bottom: 0.5rem;
            color: var(--warning);
            font-size: 0.9rem;
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
            }
        }

        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                align-items: stretch;
                gap: 1rem;
            }
            
            .search-filter {
                flex-direction: column;
            }
            
            .search-container {
                width: 100%;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .modal {
                width: 95%;
                margin: 1rem;
            }
            
            .users-table th,
            .users-table td {
                padding: 0.75rem 1rem;
                font-size: 0.85rem;
            }
            
            .action-buttons {
                flex-wrap: wrap;
            }
            
            .btn {
                padding: 0.5rem;
                font-size: 0.8rem;
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

    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <nav class="sidebar-menu">
            <a href="librarian_dashboard.php" class="menu-item">
                <i class="fas fa-home"></i>
                <span>Dashboard</span>
            </a>
            <a href="user_management.php" class="menu-item active">
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
                <h1 class="page-title">User Management</h1>
                <p class="welcome-text">Manage students, staff, and their access to the library system</p>
            </div>
        </div>

        <!-- Alert Messages -->
        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Content Header -->
        <div class="content-header">
            <h2 class="content-title">Users Management</h2>
            <div class="action-buttons">
                <button class="btn btn-green" onclick="openModal('registerStudentModal')">
                    <i class="fas fa-user-plus"></i> Register Student
                </button>
                <button class="btn btn-green" onclick="openModal('registerStaffModal')">
                    <i class="fas fa-user-tie"></i> Register Staff
                </button>
                <button class="btn btn-blue" onclick="openModal('batchUploadModal')">
                    <i class="fas fa-upload"></i> Batch Upload
                </button>
                <button class="btn btn-orange" onclick="openModal('promoteModal')">
                    <i class="fas fa-graduation-cap"></i> Promote Students
                </button>
            </div>
        </div>

        <!-- Search and Filter -->
        <div class="search-filter">
            <div class="search-container">
                <i class="fas fa-search search-icon"></i>
                <input type="text" id="searchInput" class="search-input" placeholder="Search by user ID" value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <select id="filterSelect" class="filter-select">
                <option value="all" <?php echo $filter === 'all' ? 'selected' : ''; ?>>All Users</option>
                <option value="student" <?php echo $filter === 'student' ? 'selected' : ''; ?>>Students</option>
                <option value="staff" <?php echo $filter === 'staff' ? 'selected' : ''; ?>>Staff</option>
            </select>
            <button class="search-btn" onclick="performSearch()">
                <i class="fas fa-search"></i> Search
            </button>
        </div>

        <!-- Users Table -->
        <div class="users-card">
            <div class="table-header">
                <h3 class="table-title">
                    <i class="fas fa-users"></i> Users List (<?php echo $total_users; ?> total)
                </h3>
            </div>
            
            <?php if ($users_result->num_rows > 0): ?>
                <table class="users-table">
                    <thead>
                        <tr>
                            <th>User ID</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Type</th>
                            <th>Details</th>
                            <th>Status</th>
                            <th>Borrowing</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($user = $users_result->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($user['login_id']); ?></td>
                                <td><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></td>
                                <td><?php echo htmlspecialchars($user['email']); ?></td>
                                <td>
                                    <span class="type-badge type-<?php echo $user['user_type']; ?>">
                                        <?php echo ucfirst($user['user_type']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($user['user_type'] === 'student'): ?>
                                        <div>Form: <?php echo htmlspecialchars($user['student_form']); ?></div>
                                        <div>Class: <?php echo htmlspecialchars($user['student_class']); ?></div>
                                    <?php else: ?>
                                        <div>Position: <?php echo htmlspecialchars($user['staff_position']); ?></div>
                                        <div>Dept: <?php echo htmlspecialchars($user['staff_department']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="status-badge status-<?php echo strtolower($user['account_status']); ?>">
                                        <?php echo htmlspecialchars($user['account_status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="eligibility-badge eligibility-<?php 
                                        echo strtolower(str_replace(' ', '-', $user['borrowing_status'])); 
                                    ?>">
                                        <?php echo htmlspecialchars($user['borrowing_status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-buttons-table">
                                        <button class="btn-sm btn-edit" 
                                                onclick="editUser(<?php echo $user['userID']; ?>, '<?php echo $user['user_type']; ?>')">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn-sm btn-toggle" 
                                                onclick="toggleEligibility(<?php echo $user['userID']; ?>, '<?php echo $user['user_type']; ?>', <?php echo $user['borrowing_status'] === 'Eligible' ? '0' : '1'; ?>)">
                                            <i class="fas fa-<?php echo $user['borrowing_status'] === 'Eligible' ? 'ban' : 'check'; ?>"></i>
                                        </button>
                                        <button class="btn-sm btn-reset" 
                                                onclick="resetPassword(<?php echo $user['userID']; ?>, '<?php echo $user['user_type']; ?>')">
                                            <i class="fas fa-redo"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
                
                <!-- Pagination -->
                <div class="pagination">
                    <span class="pagination-info">
                        Showing <?php echo min($limit, $total_users); ?> of <?php echo $total_users; ?> users
                    </span>
                    
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>" 
                       class="page-link <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                        <i class="fas fa-angle-double-left"></i>
                    </a>
                    
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => max(1, $page - 1)])); ?>" 
                       class="page-link <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                        <i class="fas fa-angle-left"></i>
                    </a>
                    
                    <?php
                    $start_page = max(1, $page - 2);
                    $end_page = min($total_pages, $page + 2);
                    
                    for ($i = $start_page; $i <= $end_page; $i++): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
                           class="page-link <?php echo $i === $page ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                    
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => min($total_pages, $page + 1)])); ?>" 
                       class="page-link <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                        <i class="fas fa-angle-right"></i>
                    </a>
                    
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>" 
                       class="page-link <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                        <i class="fas fa-angle-double-right"></i>
                    </a>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <h3 class="empty-title">No Users Found</h3>
                    <p class="empty-description">No users match your search criteria. Try adjusting your search or filter.</p>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Register Student Modal -->
    <div class="modal-overlay" id="registerStudentModal">
        <div class="modal">
            <button class="modal-close" onclick="closeModal('registerStudentModal')">&times;</button>
            <div class="modal-header">
                <h2><i class="fas fa-user-plus"></i> Register Student</h2>
            </div>
            <div class="modal-body">
                <div class="password-info">
                    <i class="fas fa-info-circle"></i> 
                    <div>
                        Default password "Pass123*" will be assigned. Student must change it on first login.
                    </div>
                </div>
                <form method="POST" id="studentForm">
                    <input type="hidden" name="action" value="register_student">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="first_name">First Name *</label>
                            <input type="text" name="first_name" id="first_name" class="form-control" required autocomplete="given-name">
                        </div>
                        <div class="form-group">
                            <label for="last_name">Last Name *</label>
                            <input type="text" name="last_name" id="last_name" class="form-control" required autocomplete="family-name">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="email">Email *</label>
                            <input type="email" name="email" id="email" class="form-control" required placeholder="username@student.smkchendering.edu.my" autocomplete="email">
                            <div class="email-hint">Must use @student.smkchendering.edu.my domain</div>
                        </div>
                        <div class="form-group">
                            <label for="phone_number">Phone Number</label>
                            <input type="text" name="phone_number" id="phone_number" class="form-control" autocomplete="tel">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="student_form">Form *</label>
                            <select name="student_form" id="student_form" class="form-control" required>
                                <option value="">Select Form</option>
                                <option value="Form 1">Form 1</option>
                                <option value="Form 2">Form 2</option>
                                <option value="Form 3">Form 3</option>
                                <option value="Form 4">Form 4</option>
                                <option value="Form 5">Form 5</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="student_class">Class *</label>
                            <select name="student_class" id="student_class" class="form-control" required>
                                <option value="">Select Class</option>
                                <?php foreach ($student_classes as $class): ?>
                                    <option value="<?php echo $class; ?>"><?php echo $class; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="dob">Date of Birth</label>
                        <input type="date" name="dob" id="dob" class="form-control">
                    </div>
                    
                    <button type="submit" class="form-submit">
                        <i class="fas fa-user-plus"></i> Register Student
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Register Staff Modal -->
    <div class="modal-overlay" id="registerStaffModal">
        <div class="modal">
            <button class="modal-close" onclick="closeModal('registerStaffModal')">&times;</button>
            <div class="modal-header">
                <h2><i class="fas fa-user-tie"></i> Register Staff</h2>
            </div>
            <div class="modal-body">
                <div class="password-info">
                    <i class="fas fa-info-circle"></i> 
                    <div>
                        Default password "Pass123*" will be assigned. Staff must change it on first login.
                    </div>
                </div>
                <form method="POST" id="staffForm">
                    <input type="hidden" name="action" value="register_staff">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="staff_first_name">First Name *</label>
                            <input type="text" name="first_name" id="staff_first_name" class="form-control" required autocomplete="given-name">
                        </div>
                        <div class="form-group">
                            <label for="staff_last_name">Last Name *</label>
                            <input type="text" name="last_name" id="staff_last_name" class="form-control" required autocomplete="family-name">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="staff_email">Email *</label>
                            <input type="email" name="email" id="staff_email" class="form-control" required placeholder="username@smkchendering.edu.my" autocomplete="email">
                            <div class="email-hint">Must use @smkchendering.edu.my domain</div>
                        </div>
                        <div class="form-group">
                            <label for="staff_phone">Phone Number</label>
                            <input type="text" name="phone_number" id="staff_phone" class="form-control" autocomplete="tel">
                        </div>
                    </div>
                    
                    <div class="form-group">
                         <label for="staff_position">Position *</label>
                         <select name="staff_position" id="staff_position" class="form-control" required>
                         <option value="">Select Position</option>
                      <?php foreach ($staff_positions as $pos): ?>
                            <option value="<?php echo htmlspecialchars($pos); ?>"><?php echo htmlspecialchars($pos); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="staff_department">Department *</label>
                    <select name="department" id="staff_department" class="form-control" required>
                        <option value="">Select Department</option>
                        <?php foreach ($staff_departments as $dept): ?>
                            <option value="<?php echo htmlspecialchars($dept); ?>"><?php echo htmlspecialchars($dept); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                    
                    <div class="form-group">
                        <label for="staff_dob">Date of Birth</label>
                        <input type="date" name="dob" id="staff_dob" class="form-control">
                    </div>
                    
                    <button type="submit" class="form-submit">
                        <i class="fas fa-user-tie"></i> Register Staff
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Batch Upload Modal -->
    <div class="modal-overlay" id="batchUploadModal">
        <div class="modal">
            <button class="modal-close" onclick="closeModal('batchUploadModal')">&times;</button>
            <div class="modal-header">
                <h2><i class="fas fa-upload"></i> Batch Upload Users</h2>
            </div>
            <div class="modal-body">
                <form method="POST" enctype="multipart/form-data" id="batchUploadForm">
                    <input type="hidden" name="action" value="batch_upload">
                    
                    <div class="batch-upload-form">
                        <div class="form-group">
                            <label for="upload_type">Upload Type *</label>
                            <select name="upload_type" id="upload_type" required>
                                <option value="">Select Type</option>
                                <option value="student">Students</option>
                                <option value="staff">Staff</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="csv_file">CSV File *</label>
                            <input type="file" name="csv_file" id="csv_file" accept=".csv" required>
                        </div>
                        
                        <button type="submit" class="upload-btn">
                            <i class="fas fa-upload"></i> Upload CSV File
                        </button>
                    </div>
                    
                    <div class="csv-templates">
                        <h3>CSV Templates</h3>
                        
                        <div class="template-section">
                            <h4>Student Template</h4>
                            <button class="download-template" onclick="downloadStudentTemplate()">
                                <i class="fas fa-download"></i> Download Student Template
                            </button>
                        </div>
                        
                        <div class="template-section">
                            <h4>Staff Template</h4>
                            <button class="download-template" onclick="downloadStaffTemplate()">
                                <i class="fas fa-download"></i> Download Staff Template
                            </button>
                        </div>
                        
                        <div class="important-notes">
                            <h4>Important Notes:</h4>
                            <ul>
                                <li>User IDs will be auto-generated</li>
                                <li>Default password "Pass123*" will be assigned</li>
                                <li>Email domains must be correct (@student.smkchendering.edu.my for students, @smkchendering.edu.my for staff)</li>
                                <li>Date format: YYYY-MM-DD</li>
                            </ul>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Promote Students Modal -->
    <div class="modal-overlay" id="promoteModal">
        <div class="modal">
            <button class="modal-close" onclick="closeModal('promoteModal')">&times;</button>
            <div class="modal-header">
                <h2><i class="fas fa-graduation-cap"></i> Promote Students</h2>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <input type="hidden" name="action" value="promote_students">
                    
                    <div class="form-group">
                        <label for="promotion_year">Promotion Year</label>
                        <input type="number" name="promotion_year" id="promotion_year" class="form-control" value="<?php echo date('Y'); ?>" min="2020" max="2030" required>
                    </div>
                    
                    <div class="promotion-details">
                        <strong>Promotion Details:</strong>
                        <ul>
                            <li>Form 1 → Form 2</li>
                            <li>Form 2 → Form 3</li>
                            <li>Form 3 → Form 4</li>
                            <li>Form 4 → Form 5</li>
                            <li>Form 5 → Graduated (Inactive)</li>
                        </ul>
                    </div>
                    
                    <button type="submit" class="form-submit" onclick="return confirm('Are you sure you want to promote all students? This action cannot be undone.')">
                        <i class="fas fa-graduation-cap"></i> Promote All Students
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div class="modal-overlay" id="editUserModal">
        <div class="modal">
            <button class="modal-close" onclick="closeModal('editUserModal')">&times;</button>
            <div class="modal-header">
                <h2><i class="fas fa-edit"></i> Edit User</h2>
            </div>
            <div class="modal-body">
                 <div class="modal-body">
                <form method="POST" id="editUserForm">
                        <input type="hidden" name="action" value="edit_user">
                        <input type="hidden" name="user_id" id="editUserId">
                        <input type="hidden" name="user_type" id="editUserType">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="edit_first_name">First Name *</label>
                            <input type="text" name="first_name" id="edit_first_name" class="form-control" required autocomplete="given-name">
                        </div>
                        <div class="form-group">
                            <label for="edit_last_name">Last Name *</label>
                            <input type="text" name="last_name" id="edit_last_name" class="form-control" required autocomplete="family-name">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="edit_email">Email *</label>
                            <input type="email" name="email" id="edit_email" class="form-control" required autocomplete="email">
                            <div class="email-hint">Must use appropriate domain</div>
                        </div>
                        <div class="form-group">
                            <label for="edit_phone_number">Phone Number</label>
                            <input type="text" name="phone_number" id="edit_phone_number" class="form-control" autocomplete="tel">
                        </div>
                    </div>
                    
                    <button type="submit" class="form-submit">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </form>
            </div>
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
        });
        
        // Modal functions
        function openModal(modalId) {
            document.getElementById(modalId).classList.add('active');
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
        }
        
        // Close modal when clicking outside
        window.addEventListener('click', function(event) {
            const modals = document.querySelectorAll('.modal-overlay');
            modals.forEach(modal => {
                if (event.target === modal) {
                    modal.classList.remove('active');
                }
            });
        });
        
        // Search functionality
        function performSearch() {
            const searchInput = document.getElementById('searchInput').value;
            const filterSelect = document.getElementById('filterSelect').value;
            
            let url = 'user_management.php';
            const params = new URLSearchParams();
            
            if (searchInput) params.append('search', searchInput);
            if (filterSelect !== 'all') params.append('filter', filterSelect);
            
            window.location.href = url + '?' + params.toString();
        }
        
        // Handle Enter key in search
        document.getElementById('searchInput').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                performSearch();
            }
        });
        
        // Toggle user eligibility
        function toggleEligibility(userId, userType, isEligible) {
            if (confirm(`Are you sure you want to ${isEligible ? 'disable' : 'enable'} borrowing eligibility for this user?`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="toggle_eligibility">
                    <input type="hidden" name="user_id" value="${userId}">
                    <input type="hidden" name="user_type" value="${userType}">
                    <input type="hidden" name="is_eligible" value="${isEligible}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        // Reset user password
        function resetPassword(userId, userType) {
            if (confirm('Are you sure you want to reset this user\'s password? The password will be set to "Pass123*" and the user will be required to change it on next login.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="reset_password">
                    <input type="hidden" name="user_id" value="${userId}">
                    <input type="hidden" name="user_type" value="${userType}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function editUser(userId, userType) {
    document.getElementById('editUserId').value = userId;
    document.getElementById('editUserType').value = userType;

    const url = `user_management.php?action=get_user&user_id=${userId}&t=${Date.now()}`;

    fetch(url, {
        method: 'GET',
        headers: {
            'Accept': 'application/json'
        },
        credentials: 'same-origin'
    })
    .then(response => {
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            throw new Error(`Response is not JSON: ${contentType}`);
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            const user = data.user;
            document.getElementById('edit_first_name').value = user.first_name;
            document.getElementById('edit_last_name').value = user.last_name;
            document.getElementById('edit_email').value = user.email;
            document.getElementById('edit_phone_number').value = user.phone_number;
            openModal('editUserModal');
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Fetch Error:', error);
        alert('Error: ' + error.message);
    });
}
        
        // Download CSV templates
        function downloadStudentTemplate() {
            const csvContent = "first_name,last_name,email,student_form,student_class,phone_number,dob\nJohn,Doe,john.doe@student.smkchendering.edu.my,Form 1,Bijak,012-3456789,2008-01-15\nJane,Smith,jane.smith@student.smkchendering.edu.my,Form 2,Amanah,013-4567890,2007-03-20";
            downloadCSV(csvContent, 'student_template.csv');
        }
        
        function downloadStaffTemplate() {
            const csvContent = "first_name,last_name,email,position,department,phone_number,dob\nAhmad,Adham,adham@smkchendering.edu.my,Mathematics Teacher,Mathematics Department,012-3456789,1978-06-20\nFaridah,Hassan,faridah.hassan@smkchendering.edu.my,Science Teacher,Science Department,013-4567890,1980-08-15";
            downloadCSV(csvContent, 'staff_template.csv');
        }
        
        function downloadCSV(content, filename) {
            const blob = new Blob([content], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', filename);
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
        
        // Refresh page
        function refreshPage() {
            window.location.reload();
        }
    </script>
</body>
</html>