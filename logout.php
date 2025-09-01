<?php
/**
 * Logout Handler
 * SMK Chendering Library System
 */

require_once 'auth_helper.php';
require_once 'dbconnect.php';

// Check if user is logged in
if (isLoggedIn()) {
    // Log the logout activity
    try {
        $user_id = $_SESSION['user_id'];
        $user_name = getUserDisplayName();
        
        $stmt = $conn->prepare("INSERT INTO user_activity_log (userID, action, description) VALUES (?, ?, ?)");
        $action = 'logout';
        $description = "User '$user_name' logged out";
        $stmt->bind_param("iss", $user_id, $action, $description);
        $stmt->execute();
        
    } catch (Exception $e) {
        error_log("Logout logging error: " . $e->getMessage());
    }
}

// Clear all session data
$_SESSION = array();

// Destroy the session cookie
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

// Destroy the session
session_destroy();

// Prevent caching of this page
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");

// Redirect to index.html
header("Location: index.html");
exit();
?>