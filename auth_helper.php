<?php
/**
 * Authentication Helper Functions
 * SMK Chendering Library System
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Check if user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
}

/**
 * Check if user has specific role
 */
function hasRole($role) {
    return isLoggedIn() && $_SESSION['user_type'] === $role;
}

/**
 * Require login - redirect to login page if not logged in
 */
function requireLogin($redirect_to = 'index.php') {
    if (!isLoggedIn()) {
        header("Location: $redirect_to?login_required=1");
        exit;
    }
}

/**
 * Require specific role - redirect if user doesn't have required role
 */
function requireRole($required_role, $redirect_to = 'index.php') {
    requireLogin($redirect_to);
    
    if (!hasRole($required_role)) {
        header("Location: unauthorized.php");
        exit;
    }
}

/**
 * Get current user info
 */
function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }
    
    return [
        'user_id' => $_SESSION['user_id'],
        'user_type' => $_SESSION['user_type'],
        'login_id' => $_SESSION['login_id'],
        'first_name' => $_SESSION['first_name'],
        'last_name' => $_SESSION['last_name'],
        'full_name' => $_SESSION['first_name'] . ' ' . $_SESSION['last_name'],
        'email' => $_SESSION['email']
    ];
}

/**
 * Get user display name
 */
function getUserDisplayName() {
    if (!isLoggedIn()) {
        return 'Guest';
    }
    
    switch ($_SESSION['user_type']) {
        case 'student':
            return $_SESSION['student_name'] ?? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'];
        case 'staff':
            return $_SESSION['staff_name'] ?? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'];
        case 'librarian':
            return $_SESSION['librarian_name'] ?? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'];
        default:
            return $_SESSION['first_name'] . ' ' . $_SESSION['last_name'];
    }
}

/**
 * Get user restrictions
 */
function getUserRestrictions() {
    return $_SESSION['restrictions'] ?? [];
}

/**
 * Check if user can borrow books
 */
function canBorrowBooks() {
    if (!isLoggedIn()) {
        return false;
    }
    
    // Librarians can always borrow
    if ($_SESSION['user_type'] === 'librarian') {
        return true;
    }
    
    // Check if user is eligible to borrow
    $is_eligible = $_SESSION['is_eligible_to_borrow'] ?? false;
    if (!$is_eligible) {
        return false;
    }
    
    // Check for restrictions
    $restrictions = getUserRestrictions();
    foreach ($restrictions as $restriction) {
        if ($restriction['type'] === 'borrowing_suspended') {
            return false;
        }
    }
    
    return true;
}

/**
 * Get user's maximum books allowed
 */
function getMaxBooksAllowed() {
    if (!isLoggedIn()) {
        return 0;
    }
    
    return $_SESSION['max_books_allowed'] ?? 0;
}

/**
 * Logout user - Fixed version
 */
function logout($redirect_to = 'index.html') {
    // Clear all session data
    $_SESSION = array();
    
    // Destroy the session cookie
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time() - 3600, '/');
    }
    
    // Destroy the session
    session_destroy();
    
    // Add cache control headers to prevent back button access
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Cache-Control: post-check=0, pre-check=0", false);
    header("Pragma: no-cache");
    header("Expires: 0");
    
    // Clean redirect - remove any existing query parameters
    $clean_redirect = strtok($redirect_to, '?');
    
    // Redirect to the specified page
    header("Location: $clean_redirect");
    exit();
}

/**
 * Generate CSRF token
 */
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token
 */
function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Get dashboard URL based on user type
 */
function getDashboardURL() {
    if (!isLoggedIn()) {
        return 'index.html';
    }
    
    switch ($_SESSION['user_type']) {
        case 'librarian':
            return 'librarian_dashboard.php';
        case 'student':
            return 'student_dashboard.php';
        case 'staff':
            return 'staff_dashboard.php';
        default:
            return 'index.html';
    }
}

/**
 * Format user info for display
 */
function formatUserInfo() {
    if (!isLoggedIn()) {
        return [];
    }
    
    $user = getCurrentUser();
    $info = [
        'name' => getUserDisplayName(),
        'type' => ucfirst($user['user_type']),
        'login_id' => $user['login_id'],
        'email' => $user['email']
    ];
    
    // Add role-specific information
    switch ($user['user_type']) {
        case 'student':
            $info['class'] = $_SESSION['student_class'] ?? 'N/A';
            $info['form'] = $_SESSION['student_form'] ?? 'N/A';
            break;
        case 'staff':
            $info['position'] = $_SESSION['staff_position'] ?? 'N/A';
            $info['department'] = $_SESSION['department'] ?? 'N/A';
            break;
    }
    
    return $info;
}

/**
 * Check password strength
 */
function checkPasswordStrength($password) {
    $strength = 0;
    $feedback = [];
    
    // Length check
    if (strlen($password) >= 8) {
        $strength += 25;
    } else {
        $feedback[] = "Password should be at least 8 characters long";
    }
    
    // Uppercase check
    if (preg_match('/[A-Z]/', $password)) {
        $strength += 25;
    } else {
        $feedback[] = "Password should contain at least one uppercase letter";
    }
    
    // Lowercase check
    if (preg_match('/[a-z]/', $password)) {
        $strength += 25;
    } else {    
        $feedback[] = "Password should contain at least one lowercase letter";
    }
    
    // Number or special character check
    if (preg_match('/[0-9]/', $password) || preg_match('/[^A-Za-z0-9]/', $password)) {
        $strength += 25;
    } else {
        $feedback[] = "Password should contain at least one number or special character";
    }
    
    return [
        'strength' => $strength,
        'feedback' => $feedback,
        'level' => $strength < 50 ? 'weak' : ($strength < 75 ? 'medium' : 'strong')
    ];
}

/**
 * Sanitize input data
 */
function sanitizeInput($data) {
    if (is_array($data)) {
        return array_map('sanitizeInput', $data);
    }
    
    return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}

/**
 * Generate secure random password
 */
function generateRandomPassword($length = 12) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
    $password = '';
    $max = strlen($chars) - 1;
    
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, $max)];
    }
    
    return $password;
}

/**
 * Log security event
 */
function logSecurityEvent($conn, $event_type, $description, $severity = 'info') {
    try {
        // Create security log table if it doesn't exist
        $create_table_sql = "CREATE TABLE IF NOT EXISTS security_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            event_type VARCHAR(50) NOT NULL,
            description TEXT NOT NULL,
            severity ENUM('info', 'warning', 'error', 'critical') DEFAULT 'info',
            ip_address VARCHAR(45),
            user_agent TEXT,
            timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES user(userID) ON DELETE SET NULL
        )";
        $conn->query($create_table_sql);
        
        $user_id = isLoggedIn() ? $_SESSION['user_id'] : null;
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        
        $stmt = $conn->prepare("INSERT INTO security_log (user_id, event_type, description, severity, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isssss", $user_id, $event_type, $description, $severity, $ip_address, $user_agent);
        $stmt->execute();
        
    } catch (Exception $e) {
        error_log("Failed to log security event: " . $e->getMessage());
    }
}

/**
 * Rate limiting for login attempts
 */
function checkRateLimit($conn, $identifier, $max_attempts = 5, $time_window = 900) { // 15 minutes
    try {
        // Create rate limit table if it doesn't exist
        $create_table_sql = "CREATE TABLE IF NOT EXISTS rate_limit (
            id INT AUTO_INCREMENT PRIMARY KEY,
            identifier VARCHAR(100) NOT NULL,
            attempts INT DEFAULT 1,
            first_attempt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_attempt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_identifier (identifier)
        )";
        $conn->query($create_table_sql);
        
        // Clean old entries
        $conn->query("DELETE FROM rate_limit WHERE last_attempt < DATE_SUB(NOW(), INTERVAL $time_window SECOND)");
        
        // Check current attempts
        $stmt = $conn->prepare("SELECT attempts, first_attempt FROM rate_limit WHERE identifier = ?");
        $stmt->bind_param("s", $identifier);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            if ($row['attempts'] >= $max_attempts) {
                return [
                    'allowed' => false,
                    'attempts' => $row['attempts'],
                    'reset_time' => strtotime($row['first_attempt']) + $time_window
                ];
            }
            
            // Increment attempts
            $conn->query("UPDATE rate_limit SET attempts = attempts + 1 WHERE identifier = '$identifier'");
            return ['allowed' => true, 'attempts' => $row['attempts'] + 1];
        } else {
            // First attempt
            $stmt = $conn->prepare("INSERT INTO rate_limit (identifier) VALUES (?)");
            $stmt->bind_param("s", $identifier);
            $stmt->execute();
            return ['allowed' => true, 'attempts' => 1];
        }
        
    } catch (Exception $e) {
        error_log("Rate limit check failed: " . $e->getMessage());
        return ['allowed' => true, 'attempts' => 0]; // Allow on error
    }
}

/**
 * Clear rate limit for identifier
 */
function clearRateLimit($conn, $identifier) {
    try {
        $stmt = $conn->prepare("DELETE FROM rate_limit WHERE identifier = ?");
        $stmt->bind_param("s", $identifier);
        $stmt->execute();
    } catch (Exception $e) {
        error_log("Failed to clear rate limit: " . $e->getMessage());
    }
}

/**
 * Additional function to prevent back button access after logout
 */
function preventCacheAccess() {
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Cache-Control: post-check=0, pre-check=0", false);
    header("Pragma: no-cache");
    header("Expires: 0");
}

/**
 * Check if page should be accessible (for dashboard pages)
 */
function checkPageAccess() {
    if (!isLoggedIn()) {
        header("Location: index.html");
        exit();
    }
    
    // Add cache control headers to prevent back button issues
    preventCacheAccess();
}
?>