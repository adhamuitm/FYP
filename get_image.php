<?php
// Clean any output buffer first
while (ob_get_level()) {
    ob_end_clean();
}

require_once 'dbconnect.php';

// Ensure bookID is provided and is a number
if (!isset($_GET['bookID']) || !is_numeric($_GET['bookID'])) {
    header("HTTP/1.0 404 Not Found");
    exit;
}

$bookID = intval($_GET['bookID']);

try {
    $stmt = $conn->prepare("SELECT book_image, book_image_mime FROM book WHERE bookID = ?");
    $stmt->bind_param("i", $bookID);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $book = $result->fetch_assoc();
        
        // If the image data and MIME type exist, display the image
        if (!empty($book['book_image']) && !empty($book['book_image_mime'])) {
            header("Content-Type: " . $book['book_image_mime']);
            header("Content-Length: " . strlen($book['book_image'])); 
            header("Cache-Control: public, max-age=3600"); // Cache for 1 hour
            
            // Output the binary image data directly (not base64 encoded)
            echo $book['book_image'];
        } else {
            // Display a default placeholder SVG
            header('Content-Type: image/svg+xml');
            header("Cache-Control: public, max-age=86400"); // Cache for 24 hours
            
            // Generate a simple book placeholder SVG
            $placeholderSVG = '<?xml version="1.0" encoding="UTF-8"?>
            <svg width="100" height="120" xmlns="http://www.w3.org/2000/svg">
                <rect width="100" height="120" fill="#f8f9fa" stroke="#dee2e6" stroke-width="2"/>
                <rect x="10" y="10" width="80" height="100" fill="#e9ecef" stroke="#adb5bd" stroke-width="1"/>
                <line x1="20" y1="30" x2="70" y2="30" stroke="#6c757d" stroke-width="2"/>
                <line x1="20" y1="45" x2="80" y2="45" stroke="#6c757d" stroke-width="1"/>
                <line x1="20" y1="55" x2="75" y2="55" stroke="#6c757d" stroke-width="1"/>
                <line x1="20" y1="65" x2="65" y2="65" stroke="#6c757d" stroke-width="1"/>
                <text x="50" y="90" text-anchor="middle" font-family="Arial, sans-serif" font-size="12" fill="#6c757d">No Image</text>
            </svg>';
            
            echo $placeholderSVG;
        }
    } else {
        header("HTTP/1.0 404 Not Found");
        
        // Return a "not found" SVG instead of text
        header('Content-Type: image/svg+xml');
        $notFoundSVG = '<?xml version="1.0" encoding="UTF-8"?>
        <svg width="100" height="120" xmlns="http://www.w3.org/2000/svg">
            <rect width="100" height="120" fill="#f8d7da" stroke="#f5c6cb" stroke-width="2"/>
            <text x="50" y="60" text-anchor="middle" font-family="Arial, sans-serif" font-size="10" fill="#721c24">Not Found</text>
        </svg>';
        
        echo $notFoundSVG;
    }
} catch (Exception $e) {
    header("HTTP/1.0 500 Internal Server Error");
    error_log("get_image.php error: " . $e->getMessage());
    
    // Return error image
    header('Content-Type: image/svg+xml');
    $errorSVG = '<?xml version="1.0" encoding="UTF-8"?>
    <svg width="100" height="120" xmlns="http://www.w3.org/2000/svg">
        <rect width="100" height="120" fill="#f8d7da" stroke="#f5c6cb" stroke-width="2"/>
        <text x="50" y="60" text-anchor="middle" font-family="Arial, sans-serif" font-size="10" fill="#721c24">Error</text>
    </svg>';
    
    echo $errorSVG;
} finally {
    if (isset($stmt)) {
        $stmt->close();
    }
    if (isset($conn)) {
        $conn->close();
    }
}
exit;
?>