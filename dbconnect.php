<?php
$servername = "localhost:3308";  // Update with your DB server
$username = "root";         // Update with your DB username
$password = "";             // Update with your DB password
$dbname = "school_library_system";  // Update with your database name

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}


?>
