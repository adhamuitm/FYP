<?php
require_once 'dbconnect.php';
require_once 'auth_helper.php';
checkPageAccess(); // This prevents back button access after logout

// Check if user is logged in and is a librarian
requireRole('librarian');

// Get parameters
$bookID = $_GET['bookID'] ?? '';

// Get book details
$book_info = null;
$barcodeText = '';

if ($bookID) {
    $stmt = $conn->prepare("SELECT bookTitle, bookAuthor, bookBarcode, book_ISBN FROM book WHERE bookID = ?");
    $stmt->bind_param("i", $bookID);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $book_info = $result->fetch_assoc();
        
        // Generate proper ISBN-13 for barcode
        $isbn = $book_info['book_ISBN'];
        if (!empty($isbn)) {
            // Clean the ISBN
            $cleanISBN = preg_replace('/[^0-9X]/', '', $isbn);
            
            // If it's ISBN-10, convert to ISBN-13
            if (strlen($cleanISBN) == 10) {
                $isbn13 = '978' . substr($cleanISBN, 0, 9);
                
                // Calculate check digit for ISBN-13
                $sum = 0;
                for ($i = 0; $i < 12; $i++) {
                    $sum += intval($isbn13[$i]) * (($i % 2 == 0) ? 1 : 3);
                }
                $checkDigit = (10 - ($sum % 10)) % 10;
                $barcodeText = $isbn13 . $checkDigit;
            } elseif (strlen($cleanISBN) == 13) {
                $barcodeText = $cleanISBN;
            } else {
                // Use the existing barcode from database
                $barcodeText = $book_info['bookBarcode'];
            }
        } else {
            // Use the existing barcode from database
            $barcodeText = $book_info['bookBarcode'];
        }
    }
}

if (empty($barcodeText)) {
    $barcodeText = '9780000000000'; // Default ISBN-13
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book Barcode - SMK Chendering Library</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: #f8f9fa;
            padding: 20px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .barcode-container {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            text-align: center;
            max-width: 600px;
            margin: 0 auto;
        }
        .barcode-label {
            margin: 20px 0;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 8px;
            border: 2px solid #ddd;
        }
        .barcode-display {
            margin: 20px 0;
            padding: 15px;
            background: white;
            border: 1px solid #000;
            display: inline-block;
            min-height: 120px;
            min-width: 300px;
        }
        .book-info {
            text-align: left;
            margin: 20px 0;
        }
        .book-info h5 {
            color: #667eea;
            margin-bottom: 15px;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #eee;
        }
        .info-row:last-child {
            border-bottom: none;
        }
        .print-btn {
            background: linear-gradient(135deg, #667eea, #764ba2);
            border: none;
            color: white;
            padding: 12px 25px;
            border-radius: 8px;
            margin: 10px 8px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        .print-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
        }
        .print-btn.success {
            background: linear-gradient(135deg, #28a745, #20c997);
        }
        .print-btn.secondary {
            background: linear-gradient(135deg, #6c757d, #5a6268);
        }
        @media print {
            body {
                background: white;
                padding: 0;
            }
            .no-print {
                display: none !important;
            }
            .barcode-container {
                box-shadow: none;
                border: 2px solid #000;
                page-break-inside: avoid;
                max-width: none;
            }
            .barcode-label {
                border: 1px solid #000;
                background: white;
            }
        }
        .barcode-number {
            font-family: 'Courier New', monospace;
            font-size: 1.2rem;
            font-weight: bold;
            margin: 15px 0;
            letter-spacing: 3px;
            color: #000;
        }
        .library-header {
            font-size: 1.3rem;
            font-weight: bold;
            color: #333;
            margin-bottom: 20px;
        }
        .book-title {
            font-size: 1rem;
            font-weight: bold;
            color: #333;
            margin: 10px 0 5px 0;
        }
        .book-author {
            font-size: 0.9rem;
            color: #666;
            font-style: italic;
        }
        .barcode-svg {
            max-width: 100%;
            height: auto;
        }
        .instructions-card {
            background: #e3f2fd;
            border: 1px solid #bbdefb;
            border-radius: 8px;
            padding: 20px;
            margin-top: 20px;
        }
        .instructions-card h6 {
            color: #1976d2;
            margin-bottom: 15px;
        }
        .instructions-card ul {
            text-align: left;
            margin: 0;
            color: #333;
        }
        .instructions-card li {
            margin-bottom: 8px;
        }
        .error-message {
            color: #dc3545;
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
        }
    </style>
</head>
<body>
    <div class="barcode-container">
        <h3 class="no-print" style="color: #667eea; margin-bottom: 30px;">
            <i class="fas fa-barcode"></i> Book Barcode Generator
        </h3>
        
        <?php if ($book_info): ?>
        <div class="book-info no-print">
            <h5><i class="fas fa-book"></i> Book Information</h5>
            <div class="card">
                <div class="card-body">
                    <div class="info-row">
                        <strong>Title:</strong>
                        <span><?php echo htmlspecialchars($book_info['bookTitle']); ?></span>
                    </div>
                    <div class="info-row">
                        <strong>Author:</strong>
                        <span><?php echo htmlspecialchars($book_info['bookAuthor']); ?></span>
                    </div>
                    <?php if ($book_info['book_ISBN']): ?>
                    <div class="info-row">
                        <strong>ISBN:</strong>
                        <span><?php echo htmlspecialchars($book_info['book_ISBN']); ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="info-row">
                        <strong>Book ID:</strong>
                        <span><?php echo htmlspecialchars($bookID); ?></span>
                    </div>
                    <div class="info-row">
                        <strong>Barcode (ISBN-13):</strong>
                        <span style="font-family: monospace;"><?php echo htmlspecialchars($barcodeText); ?></span>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="barcode-label">
            <div class="library-header">SMK CHENDERING LIBRARY</div>
            
            <!-- Barcode Display Area -->
            <div class="barcode-display">
                <canvas id="barcodeCanvas"></canvas>
                <div id="barcodeError" class="error-message" style="display: none;">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>Error:</strong> Could not generate barcode. Please check the barcode format.
                </div>
            </div>
            
            <!-- Barcode number below the barcode -->
            <div class="barcode-number"><?php echo htmlspecialchars($barcodeText); ?></div>
            
            <?php if ($book_info): ?>
            <div style="margin-top: 20px;">
                <div class="book-title"><?php echo htmlspecialchars($book_info['bookTitle']); ?></div>
                <div class="book-author">by <?php echo htmlspecialchars($book_info['bookAuthor']); ?></div>
            </div>
            <?php endif; ?>
        </div>

        <div class="no-print" style="margin-top: 30px;">
            <button onclick="window.print()" class="print-btn">
                <i class="fas fa-print"></i> Print Barcode
            </button>
            <button onclick="downloadBarcode()" class="print-btn success">
                <i class="fas fa-download"></i> Download PNG
            </button>
            <button onclick="window.close()" class="print-btn secondary">
                <i class="fas fa-times"></i> Close
            </button>
        </div>

        <div class="instructions-card no-print">
            <h6><i class="fas fa-info-circle"></i> Barcode Instructions</h6>
            <ul>
                <li><strong>Format:</strong> This barcode uses EAN-13 format (compatible with ISBN-13)</li>
                <li><strong>Scanning:</strong> Compatible with standard barcode scanners</li>
                <li><strong>Printing:</strong> Print on white paper for best scanning results</li>
                <li><strong>Size:</strong> Ensure the barcode is at least 1 inch (2.5cm) wide when printed</li>
                <li><strong>Quality:</strong> Use high-quality printer settings for clear lines</li>
                <li><strong>Testing:</strong> Test scan the barcode before attaching to books</li>
            </ul>
        </div>
    </div>

    <!-- Include JsBarcode library with multiple CDN fallbacks -->
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
    <script>
        // Fallback CDN if first one fails
        if (typeof JsBarcode === 'undefined') {
            document.write('<script src="https://cdnjs.cloudflare.com/ajax/libs/jsbarcode/3.11.5/JsBarcode.all.min.js"><\/script>');
        }
    </script>
    
    <script>
        // Wait for DOM and library to load
        document.addEventListener('DOMContentLoaded', function() {
            // Add delay to ensure library is loaded
            setTimeout(function() {
                if (typeof JsBarcode === 'undefined') {
                    showError('Barcode library failed to load. Please refresh the page.');
                    return;
                }
                
                generateBarcode();
            }, 500);
        });

        function generateBarcode() {
            const barcodeText = '<?php echo addslashes($barcodeText); ?>';
            
            console.log('Generating barcode for:', barcodeText);
            
            // Validate barcode text
            if (!barcodeText || barcodeText.trim().length === 0) {
                showError('Barcode text is empty');
                return;
            }
            
            try {
                const canvas = document.getElementById('barcodeCanvas');
                
                if (!canvas) {
                    showError('Canvas element not found');
                    return;
                }
                
                // Try EAN-13 first if it's 13 digits
                if (/^\d{13}$/.test(barcodeText)) {
                    try {
                        JsBarcode(canvas, barcodeText, {
                            format: "EAN13",
                            width: 2,
                            height: 80,
                            displayValue: false,
                            margin: 10,
                            background: "#ffffff",
                            lineColor: "#000000",
                            fontSize: 14,
                            textAlign: "center",
                            textPosition: "bottom"
                        });
                        console.log('EAN-13 barcode generated successfully');
                        return;
                    } catch (eanError) {
                        console.log('EAN-13 failed, trying CODE128:', eanError);
                    }
                }
                
                // Fallback to CODE128
                JsBarcode(canvas, barcodeText, {
                    format: "CODE128",
                    width: 2,
                    height: 80,
                    displayValue: false,
                    margin: 10,
                    background: "#ffffff",
                    lineColor: "#000000",
                    fontSize: 14,
                    textAlign: "center",
                    textPosition: "bottom"
                });
                
                console.log('CODE128 barcode generated successfully');
                
            } catch (error) {
                console.error('Barcode generation failed:', error);
                showError('Could not generate barcode: ' + error.message);
            }
        }

        function showError(message) {
            const canvas = document.getElementById('barcodeCanvas');
            const errorDiv = document.getElementById('barcodeError');
            
            if (canvas) canvas.style.display = 'none';
            
            if (errorDiv) {
                errorDiv.style.display = 'block';
                errorDiv.innerHTML = `
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>Error:</strong> ${message}
                `;
            } else {
                // Create error display if it doesn't exist
                const barcodeDisplay = document.querySelector('.barcode-display');
                if (barcodeDisplay) {
                    barcodeDisplay.innerHTML = `
                        <div class="error-message">
                            <i class="fas fa-exclamation-triangle"></i>
                            <strong>Error:</strong> ${message}
                        </div>
                    `;
                }
            }
        }

        function downloadBarcode() {
            try {
                const canvas = document.getElementById('barcodeCanvas');
                
                if (!canvas || canvas.style.display === 'none') {
                    alert('Cannot download: Barcode not generated successfully');
                    return;
                }
                
                // Create download link
                const link = document.createElement('a');
                link.download = 'barcode_book_<?php echo $bookID; ?>.png';
                link.href = canvas.toDataURL('image/png');
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                
            } catch (error) {
                console.error('Download failed:', error);
                alert('Download failed: ' + error.message);
            }
        }

        // Test barcode scanning simulation
        function testBarcode() {
            const barcodeValue = '<?php echo addslashes($barcodeText); ?>';
            alert('Barcode scanned successfully!\n\nValue: ' + barcodeValue + '\n\nThis would normally trigger your library system to identify the book.');
        }

        // Auto-focus print dialog when Ctrl+P is pressed
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
                e.preventDefault();
                window.print();
            }
        });
    </script>