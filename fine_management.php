<?php
session_start();
require_once 'dbconnect.php';
require_once 'auth_helper.php';
checkPageAccess(); 

requireRole('librarian');

$librarian_info = getCurrentUser();
$librarian_name = getUserDisplayName();

// Fix: Get librarian ID properly from the librarian table using user info
$currentLibrarianID = null;
if (isset($librarian_info['userID'])) {
    $librarianQuery = "SELECT librarianID, librarian_id_number FROM librarian WHERE librarianEmail = ? OR librarian_id_number = ?";
    $stmt = $conn->prepare($librarianQuery);
    $email = $librarian_info['email'] ?? '';
    $loginId = $librarian_info['login_id'] ?? '';
    $stmt->bind_param('ss', $email, $loginId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($librarianData = $result->fetch_assoc()) {
        $currentLibrarianID = $librarianData['librarianID'];
    }
}

// Create receipts table if not exists
$createReceiptsTable = "
CREATE TABLE IF NOT EXISTS receipts (
    receiptID INT AUTO_INCREMENT PRIMARY KEY,
    receipt_number VARCHAR(50) UNIQUE NOT NULL,
    userID INT NOT NULL,
    librarianID INT NOT NULL,
    total_amount_paid DECIMAL(10,2) NOT NULL,
    cash_received DECIMAL(10,2) NOT NULL,
    change_given DECIMAL(10,2) NOT NULL,
    payment_method ENUM('cash') DEFAULT 'cash',
    transaction_date DATETIME NOT NULL,
    fine_ids TEXT NOT NULL,
    created_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (userID) REFERENCES user(userID),
    FOREIGN KEY (librarianID) REFERENCES librarian(librarianID)
)";
$conn->query($createReceiptsTable);

// Create fine letters table
$createFineLettersTable = "
CREATE TABLE IF NOT EXISTS fine_letters (
    letterID INT AUTO_INCREMENT PRIMARY KEY,
    letter_number VARCHAR(50) UNIQUE NOT NULL,
    userID INT NOT NULL,
    librarianID INT NOT NULL,
    letter_type ENUM('warning', 'final_notice', 'replacement_demand') NOT NULL,
    total_fine_amount DECIMAL(10,2) NOT NULL,
    fine_ids TEXT NOT NULL,
    letter_content TEXT NOT NULL,
    issue_date DATE NOT NULL,
    created_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (userID) REFERENCES user(userID),
    FOREIGN KEY (librarianID) REFERENCES librarian(librarianID)
)";
$conn->query($createFineLettersTable);

$searchResults = null;
$userInfo = null;
$userFines = [];
$allUserFines = [];
$paymentSuccess = false;
$receiptData = null;
$letterGenerated = false;
$letterData = null;
$error = null;
$view_mode = $_GET['view'] ?? 'search';

// Handle search for specific user
if (isset($_POST['search_user'])) {
    $loginId = trim($_POST['login_id']);
    
    if (!empty($loginId)) {
        $userQuery = "
            SELECT u.*, 
                   CASE 
                       WHEN u.user_type = 'student' THEN s.student_id_number
                       WHEN u.user_type = 'staff' THEN st.staff_id_number
                   END as id_number,
                   CASE 
                       WHEN u.user_type = 'student' THEN s.studentName
                       WHEN u.user_type = 'staff' THEN st.staffName
                   END as full_name,
                   CASE 
                       WHEN u.user_type = 'student' THEN s.studentClass
                       WHEN u.user_type = 'staff' THEN st.department
                   END as class_dept
            FROM user u
            LEFT JOIN student s ON u.userID = s.userID AND u.user_type = 'student'
            LEFT JOIN staff st ON u.userID = st.userID AND u.user_type = 'staff'
            WHERE u.login_id = ?
        ";
        
        $stmt = $conn->prepare($userQuery);
        $stmt->bind_param('s', $loginId);
        $stmt->execute();
        $result = $stmt->get_result();
        $userInfo = $result->fetch_assoc();
        
        if ($userInfo) {
            $finesQuery = "
                SELECT f.*, b.due_date, b.return_date, book.bookTitle, br.overdue_fine_per_day
                FROM fines f
                LEFT JOIN borrow b ON f.borrowID = b.borrowID
                LEFT JOIN book ON b.bookID = book.bookID
                LEFT JOIN borrowing_rules br ON br.user_type = ?
                WHERE f.userID = ? 
                AND (f.payment_status = 'unpaid' OR f.balance_due > 0)
                ORDER BY f.fine_date ASC
            ";
            
            $stmt = $conn->prepare($finesQuery);
            $stmt->bind_param('si', $userInfo['user_type'], $userInfo['userID']);
            $stmt->execute();
            $result = $stmt->get_result();
            $userFines = $result->fetch_all(MYSQLI_ASSOC);
        }
    }
}

// Fix: Corrected SQL query to comply with ONLY_FULL_GROUP_BY mode
if ($view_mode === 'overview' || empty($view_mode)) {
    $allFinesQuery = "
        SELECT u.userID, u.login_id, u.user_type, u.account_status,
               MAX(CASE 
                   WHEN u.user_type = 'student' THEN s.studentName
                   WHEN u.user_type = 'staff' THEN st.staffName
               END) as full_name,
               MAX(CASE 
                   WHEN u.user_type = 'student' THEN s.studentClass
                   WHEN u.user_type = 'staff' THEN st.department
               END) as class_dept,
               COUNT(f.fineID) as total_fines,
               SUM(f.balance_due) as total_amount,
               MAX(f.fine_date) as latest_fine_date,
               GROUP_CONCAT(DISTINCT f.fine_reason) as fine_reasons
        FROM user u
        LEFT JOIN student s ON u.userID = s.userID AND u.user_type = 'student'
        LEFT JOIN staff st ON u.userID = st.userID AND u.user_type = 'staff'
        INNER JOIN fines f ON u.userID = f.userID
        WHERE (f.payment_status = 'unpaid' OR f.balance_due > 0)
        GROUP BY u.userID, u.login_id, u.user_type, u.account_status
        ORDER BY total_amount DESC, latest_fine_date DESC
    ";
    
    $result = $conn->query($allFinesQuery);
    $allUserFines = $result->fetch_all(MYSQLI_ASSOC);
}

// Handle payment processing
if (isset($_POST['process_payment'])) {
    $selectedFines = $_POST['selected_fines'] ?? [];
    $cashReceived = floatval($_POST['cash_received'] ?? 0);
    
    if (!empty($selectedFines) && $cashReceived > 0 && $currentLibrarianID) {
        $conn->autocommit(FALSE);
        
        try {
            $totalPaid = 0;
            $paidFines = [];
            
            foreach ($selectedFines as $fineId) {
                $fineAmount = floatval($_POST['fine_amount_' . $fineId] ?? 0);
                
                if ($fineAmount > 0) {
                    $stmt = $conn->prepare("SELECT * FROM fines WHERE fineID = ?");
                    $stmt->bind_param('i', $fineId);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $fine = $result->fetch_assoc();
                    
                    if ($fine) {
                        $newAmountPaid = ($fine['amount_paid'] ?? 0) + $fineAmount;
                        $newBalance = $fine['fine_amount'] - $newAmountPaid;
                        $paymentStatus = $newBalance <= 0 ? 'paid' : 'unpaid';
                        
                        $updateStmt = $conn->prepare("
                            UPDATE fines 
                            SET amount_paid = ?, balance_due = ?, payment_status = ?, 
                                payment_date = IF(? = 'paid', NOW(), payment_date),
                                collected_by_librarianID = ?
                            WHERE fineID = ?
                        ");
                        $updateStmt->bind_param('ddssii', $newAmountPaid, $newBalance, $paymentStatus, $paymentStatus, $currentLibrarianID, $fineId);
                        $updateStmt->execute();
                        
                        $totalPaid += $fineAmount;
                        $paidFines[] = $fineId;
                    }
                }
            }
            
            if ($totalPaid > 0) {
                $receiptNumber = 'REC-' . date('Y-m-') . str_pad(rand(100, 999), 3, '0', STR_PAD_LEFT);
                
                $receiptStmt = $conn->prepare("
                    INSERT INTO receipts (receipt_number, userID, librarianID, total_amount_paid, cash_received, change_given, transaction_date, fine_ids)
                    VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)
                ");
                
                $changeGiven = $cashReceived - $totalPaid;
                $receiptStmt->bind_param('siiddds', 
                    $receiptNumber, 
                    $userInfo['userID'], 
                    $currentLibrarianID, 
                    $totalPaid, 
                    $cashReceived, 
                    $changeGiven, 
                    implode(',', $paidFines)
                );
                $receiptStmt->execute();
                
                $logStmt = $conn->prepare("
                    INSERT INTO user_activity_log (userID, action, description) 
                    VALUES (?, 'fine_payment', ?)
                ");
                $logStmt->bind_param('is', 
                    $userInfo['userID'], 
                    "Fine payment processed. Receipt: $receiptNumber. Amount: RM" . number_format($totalPaid, 2)
                );
                $logStmt->execute();
                
                $receiptData = [
                    'receipt_number' => $receiptNumber,
                    'user_info' => $userInfo,
                    'total_paid' => $totalPaid,
                    'cash_received' => $cashReceived,
                    'change_given' => $changeGiven,
                    'paid_fines' => $paidFines,
                    'transaction_date' => date('Y-m-d H:i:s')
                ];
                
                $conn->commit();
                $conn->autocommit(TRUE);
                $paymentSuccess = true;
                
                // Refresh fines data
                $finesQuery = "
                    SELECT f.*, b.due_date, b.return_date, book.bookTitle, br.overdue_fine_per_day
                    FROM fines f
                    LEFT JOIN borrow b ON f.borrowID = b.borrowID
                    LEFT JOIN book ON b.bookID = book.bookID
                    LEFT JOIN borrowing_rules br ON br.user_type = ?
                    WHERE f.userID = ? 
                    AND (f.payment_status = 'unpaid' OR f.balance_due > 0)
                    ORDER BY f.fine_date ASC
                ";
                
                $stmt = $conn->prepare($finesQuery);
                $stmt->bind_param('si', $userInfo['user_type'], $userInfo['userID']);
                $stmt->execute();
                $result = $stmt->get_result();
                $userFines = $result->fetch_all(MYSQLI_ASSOC);
            }
            
        } catch (Exception $e) {
            $conn->rollback();
            $conn->autocommit(TRUE);
            $error = "Payment processing failed: " . $e->getMessage();
        }
    } else {
        $error = "Invalid payment data or librarian not found.";
    }
}

// Handle letter generation
if (isset($_POST['generate_letter'])) {
    $letterType = $_POST['letter_type'];
    $selectedFines = $_POST['letter_fines'] ?? [];
    
    if (!empty($selectedFines) && $currentLibrarianID) {
        $letterNumber = 'LTR-' . date('Y-m-') . str_pad(rand(100, 999), 3, '0', STR_PAD_LEFT);
        
        // Calculate total fine amount
        $totalFineAmount = 0;
        $fineDetails = [];
        
        foreach ($selectedFines as $fineId) {
            $stmt = $conn->prepare("
                SELECT f.*, book.bookTitle, b.due_date 
                FROM fines f 
                LEFT JOIN borrow b ON f.borrowID = b.borrowID 
                LEFT JOIN book ON b.bookID = book.bookID 
                WHERE f.fineID = ?
            ");
            $stmt->bind_param('i', $fineId);
            $stmt->execute();
            $result = $stmt->get_result();
            $fine = $result->fetch_assoc();
            
            if ($fine) {
                $totalFineAmount += $fine['balance_due'] ?: $fine['fine_amount'];
                $fineDetails[] = $fine;
            }
        }
        
        // Generate letter content based on type
        $letterContent = generateLetterContent($letterType, $userInfo, $fineDetails, $totalFineAmount);
        
        // Insert letter record
        $letterStmt = $conn->prepare("
            INSERT INTO fine_letters (letter_number, userID, librarianID, letter_type, total_fine_amount, fine_ids, letter_content, issue_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE())
        ");
        
        $letterStmt->bind_param('sissdss', 
            $letterNumber,
            $userInfo['userID'],
            $currentLibrarianID,
            $letterType,
            $totalFineAmount,
            implode(',', $selectedFines),
            $letterContent
        );
        
        if ($letterStmt->execute()) {
            $letterData = [
                'letter_number' => $letterNumber,
                'letter_type' => $letterType,
                'user_info' => $userInfo,
                'fine_details' => $fineDetails,
                'total_amount' => $totalFineAmount,
                'letter_content' => $letterContent,
                'issue_date' => date('Y-m-d')
            ];
            $letterGenerated = true;
        }
    } else {
        $error = "Unable to generate letter. Please ensure librarian is properly authenticated.";
    }
}

function generateLetterContent($letterType, $userInfo, $fineDetails, $totalAmount) {
    $content = "";
    $userName = $userInfo['full_name'] ?? $userInfo['first_name'] . ' ' . $userInfo['last_name'];
    $userType = $userInfo['user_type'] == 'student' ? 'Pelajar' : 'Kakitangan';
    
    switch ($letterType) {
        case 'warning':
            $content = "
SURAT AMARAN / WARNING LETTER

Kepada / To: $userName
ID: {$userInfo['login_id']}
Status: $userType

Dengan ini adalah dimaklumkan bahawa anda mempunyai tunggakan denda perpustakaan sebanyak RM " . number_format($totalAmount, 2) . ".

This is to inform you that you have outstanding library fines totaling RM " . number_format($totalAmount, 2) . ".

Sila jelaskan tunggakan ini dalam tempoh 7 hari dari tarikh surat ini.
Please settle this outstanding amount within 7 days from the date of this letter.

Butiran Denda / Fine Details:
";
            
            foreach ($fineDetails as $fine) {
                $content .= "- {$fine['bookTitle']}: RM " . number_format($fine['balance_due'] ?: $fine['fine_amount'], 2) . " ({$fine['fine_reason']})\n";
            }
            break;
            
        case 'final_notice':
            $content = "
NOTIS AKHIR / FINAL NOTICE

Kepada / To: $userName
ID: {$userInfo['login_id']}
Status: $userType

INI ADALAH NOTIS AKHIR untuk menjelaskan tunggakan denda perpustakaan sebanyak RM " . number_format($totalAmount, 2) . ".

THIS IS A FINAL NOTICE to settle outstanding library fines totaling RM " . number_format($totalAmount, 2) . ".

Kegagalan menjelaskan tunggakan dalam tempoh 3 hari akan mengakibatkan tindakan tatatertib.
Failure to settle within 3 days will result in disciplinary action.

Butiran Denda / Fine Details:
";
            
            foreach ($fineDetails as $fine) {
                $content .= "- {$fine['bookTitle']}: RM " . number_format($fine['balance_due'] ?: $fine['fine_amount'], 2) . " ({$fine['fine_reason']})\n";
            }
            break;
            
        case 'replacement_demand':
            $content = "
SURAT TUNTUTAN GANTI RUGI / REPLACEMENT DEMAND LETTER

Kepada / To: $userName
ID: {$userInfo['login_id']}
Status: $userType

Dengan ini adalah dituntut bayaran ganti rugi untuk buku yang hilang/rosak sebanyak RM " . number_format($totalAmount, 2) . ".

This is to demand payment for lost/damaged books totaling RM " . number_format($totalAmount, 2) . ".

Bayaran hendaklah dibuat dalam tempoh 14 hari dari tarikh surat ini.
Payment must be made within 14 days from the date of this letter.

Butiran Buku / Book Details:
";
            
            foreach ($fineDetails as $fine) {
                $content .= "- {$fine['bookTitle']}: RM " . number_format($fine['balance_due'] ?: $fine['fine_amount'], 2) . " (Sebab: {$fine['fine_reason']})\n";
            }
            break;
    }
    
    return $content;
}

// Get fine statistics
$fine_stats_query = "
    SELECT 
        COUNT(CASE WHEN payment_status = 'unpaid' OR balance_due > 0 THEN 1 END) as total_unpaid,
        COUNT(CASE WHEN payment_status = 'paid' AND balance_due = 0 THEN 1 END) as total_paid,
        COUNT(CASE WHEN payment_status = 'unpaid' AND amount_paid > 0 THEN 1 END) as total_partial,
        COUNT(DISTINCT userID) as total_users_with_fines,
        SUM(CASE WHEN payment_status = 'unpaid' OR balance_due > 0 THEN balance_due END) as total_outstanding_amount
    FROM fines
";
$fine_stats_result = $conn->query($fine_stats_query);
$fine_stats = $fine_stats_result->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fine Management - SMK Chendering Library</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    
    <style>
        :root {
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

        .user-info {
            display: flex;
            flex-direction: column;
        }

        .user-name {
            font-size: 0.9rem;
            font-weight: 500;
            color: var(--dark);
        }

        .user-role {
            font-size: 0.8rem;
            color: var(--medium-gray);
        }

        .logout-btn {
            margin-left: 1rem;
            background: var(--danger);
            padding: 0.5rem 1rem;
            border-radius: 5px;
            color: white;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.9rem;
            transition: var(--transition);
        }

        .logout-btn:hover {
            background: #dc2626;
            color: white;
        }

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

        .view-tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
        }

        .view-tab {
            padding: 0.75rem 1.5rem;
            background: white;
            border: 1px solid var(--light-gray);
            border-radius: var(--border-radius);
            color: var(--secondary);
            text-decoration: none;
            font-weight: 500;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .view-tab:hover {
            color: var(--primary);
            border-color: var(--primary-light);
        }

        .view-tab.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stats-card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: 1.5rem;
            border: 1px solid var(--light-gray);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .stats-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: var(--primary);
        }

        .stats-card:hover {
            box-shadow: var(--card-shadow-hover);
            transform: translateY(-2px);
        }

        .stats-number {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            color: var(--primary);
        }

        .stats-label {
            font-size: 0.95rem;
            color: var(--secondary);
            font-weight: 500;
        }

        .stats-icon {
            position: absolute;
            top: 1rem;
            right: 1rem;
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            opacity: 0.1;
        }

        .content-card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            overflow: hidden;
            border: 1px solid var(--light-gray);
            margin-bottom: 2rem;
        }

        .card-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--light-gray);
            background: var(--light);
        }

        .card-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--dark);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card-body {
            padding: 1.5rem;
        }

        .search-form {
            display: flex;
            gap: 1rem;
            align-items: end;
            flex-wrap: wrap;
        }

        .form-group {
            flex: 1;
            min-width: 200px;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--secondary);
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--light-gray);
            border-radius: var(--border-radius);
            font-size: 0.95rem;
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-light);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .btn {
            padding: 0.75rem 1.25rem;
            border: none;
            border-radius: var(--border-radius);
            font-weight: 500;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: var(--transition);
            text-decoration: none;
            font-size: 0.95rem;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            color: white;
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-success:hover {
            background: #0d9f6e;
            color: white;
        }

        .btn-secondary {
            background: var(--secondary);
            color: white;
        }

        .btn-secondary:hover {
            background: #5a6268;
            color: white;
        }

        .btn-warning {
            background: var(--warning);
            color: white;
        }

        .btn-warning:hover {
            background: #d97706;
            color: white;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            background: #dc2626;
            color: white;
        }

        .btn-sm {
            padding: 0.5rem 0.75rem;
            font-size: 0.85rem;
        }

        .user-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
            padding: 1rem;
            background: var(--light);
            border-radius: var(--border-radius);
        }

        .info-item {
            display: flex;
            flex-direction: column;
        }

        .info-label {
            font-size: 0.85rem;
            color: var(--medium-gray);
            margin-bottom: 0.25rem;
            font-weight: 500;
        }

        .info-value {
            font-weight: 600;
            color: var(--dark);
        }

        .fines-table, .users-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }

        .fines-table thead th, .users-table thead th {
            background: var(--light);
            padding: 1rem;
            text-align: left;
            font-weight: 500;
            color: var(--secondary);
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid var(--light-gray);
        }

        .fines-table tbody td, .users-table tbody td {
            padding: 1rem;
            border-bottom: 1px solid var(--light-gray);
            color: var(--dark);
            font-size: 0.95rem;
        }

        .fines-table tbody tr:hover, .users-table tbody tr:hover {
            background: rgba(30, 58, 138, 0.025);
        }

        .fines-table tbody tr:last-child td, .users-table tbody tr:last-child td {
            border-bottom: none;
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            display: inline-block;
        }

        .status-unpaid {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        .status-paid {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .status-active {
            background: rgba(14, 165, 233, 0.1);
            color: var(--accent);
        }

        .status-inactive {
            background: rgba(100, 116, 139, 0.1);
            color: var(--secondary);
        }

        .amount-input {
            width: 100px;
            padding: 0.5rem;
            border: 1px solid var(--light-gray);
            border-radius: 4px;
            text-align: right;
        }

        .payment-section {
            margin-top: 1.5rem;
            padding: 1.5rem;
            background: var(--light);
            border-radius: var(--border-radius);
        }

        .payment-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .payment-info {
            display: flex;
            align-items: center;
            gap: 2rem;
            flex-wrap: wrap;
        }

        .payment-total {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--primary);
        }

        .calculator-section {
            margin-top: 1rem;
            padding: 1rem;
            background: white;
            border: 2px solid var(--primary-light);
            border-radius: var(--border-radius);
        }

        .calculator-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 0.5rem 0;
        }

        .calculator-amount {
            font-size: 1.1rem;
            font-weight: 600;
        }

        .change-amount {
            color: var(--success);
            font-size: 1.3rem;
            font-weight: 700;
        }

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

        .alert-warning {
            background: rgba(245, 158, 11, 0.1);
            border-color: rgba(245, 158, 11, 0.2);
            color: var(--warning);
        }

        .alert i {
            font-size: 1.25rem;
        }

        .no-results {
            text-align: center;
            padding: 3rem 2rem;
            color: var(--medium-gray);
        }

        .no-results h3 {
            font-size: 1.25rem;
            margin-bottom: 0.5rem;
            color: var(--secondary);
        }

        .no-results p {
            font-size: 0.95rem;
        }

        .receipt, .letter {
            max-width: 600px;
            margin: 2rem auto;
            background: white;
            border: 2px solid var(--dark);
            font-family: 'Courier New', monospace;
            font-size: 12px;
            line-height: 1.4;
            page-break-inside: avoid;
        }

        .receipt-header, .letter-header {
            text-align: center;
            padding: 1rem;
            border-bottom: 2px solid var(--dark);
        }

        .school-logo-receipt {
            width: 80px;
            height: 80px;
            margin: 0 auto 0.5rem;
            background: var(--light);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            border: 2px solid var(--primary);
        }

        .school-name {
            font-weight: bold;
            font-size: 16px;
            margin-bottom: 0.5rem;
            color: var(--primary);
        }

        .school-details {
            font-size: 10px;
            line-height: 1.2;
        }

        .receipt-title, .letter-title {
            font-weight: bold;
            font-size: 14px;
            margin: 1rem 0;
            text-align: center;
            border-top: 1px dashed var(--dark);
            border-bottom: 1px dashed var(--dark);
            padding: 0.5rem 0;
        }

        .receipt-body, .letter-body {
            padding: 1rem;
        }

        .receipt-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
        }

        .receipt-items {
            border-top: 1px dashed var(--dark);
            border-bottom: 1px dashed var(--dark);
            padding: 0.5rem 0;
            margin: 1rem 0;
        }

        .receipt-total {
            font-weight: bold;
            font-size: 14px;
            border-top: 2px solid var(--dark);
            padding-top: 0.5rem;
        }

        .signature-section {
            display: flex;
            justify-content: space-between;
            margin-top: 2rem;
            padding-top: 1rem;
            border-top: 1px dashed var(--dark);
        }

        .signature-box {
            width: 45%;
            text-align: center;
        }

        .signature-line {
            border-top: 1px solid var(--dark);
            margin-top: 2rem;
            padding-top: 0.25rem;
            font-size: 10px;
        }

        .office-copy {
            margin-top: 2rem;
            padding-top: 1rem;
            border-top: 3px dashed var(--dark);
        }

        .copy-label {
            text-align: center;
            font-weight: bold;
            margin-bottom: 1rem;
            font-size: 14px;
        }

        .letter-content {
            line-height: 1.6;
            white-space: pre-line;
        }

        .letter-footer {
            margin-top: 3rem;
            border-top: 1px dashed var(--dark);
            padding-top: 1rem;
        }

        .action-buttons {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
            flex-wrap: wrap;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }

        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 20px;
            border-radius: var(--border-radius);
            width: 90%;
            max-width: 500px;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .close {
            color: #aaa;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }

        .close:hover {
            color: black;
        }

        @media print {
            body * {
                visibility: hidden;
            }
            .receipt, .receipt *, .letter, .letter * {
                visibility: visible;
            }
            .receipt, .letter {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                max-width: none;
                margin: 0;
                border: none;
            }
            .no-print {
                display: none !important;
            }
        }

        .stats-card.total-unpaid::before {
            background: linear-gradient(90deg, var(--danger), #dc2626);
        }

        .stats-card.total-paid::before {
            background: linear-gradient(90deg, var(--success), #0d9f6e);
        }

        .stats-card.total-partial::before {
            background: linear-gradient(90deg, var(--warning), #d97706);
        }

        .stats-card.total-users::before {
            background: linear-gradient(90deg, var(--accent), #0284c7);
        }

        .stats-card.total-amount::before {
            background: linear-gradient(90deg, var(--primary), var(--primary-dark));
        }

        .stats-number.text-danger {
            color: var(--danger) !important;
        }

        .stats-number.text-success {
            color: var(--success) !important;
        }

        .stats-number.text-warning {
            color: var(--warning) !important;
        }

        .stats-number.text-info {
            color: var(--accent) !important;
        }

        .stats-number.text-primary {
            color: var(--primary) !important;
        }

        @media (max-width: 1024px) {
            .sidebar {
                width: var(--sidebar-collapsed-width);
            }
            
            .main-content {
                margin-left: var(--sidebar-collapsed-width);
            }
            
            .search-form {
                flex-direction: column;
            }

            .form-group {
                min-width: unset;
            }
            
            .user-info-grid {
                grid-template-columns: 1fr;
            }

            .payment-row {
                flex-direction: column;
                align-items: stretch;
            }

            .payment-info {
                justify-content: space-between;
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                width: var(--sidebar-collapsed-width);
            }
            
            .main-content {
                margin-left: var(--sidebar-collapsed-width);
                padding: 1rem;
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
            
            .fines-table, .users-table {
                font-size: 0.85rem;
            }

            .stats-row {
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 1rem;
            }

            .view-tabs {
                flex-direction: column;
            }

            .action-buttons {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
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
            <a href="logout.php" class="logout-btn">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </header>

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
            <a href="book_management.php" class="menu-item">
                <i class="fas fa-book"></i>
                <span>Book Management</span>
            </a>
            <a href="circulation_control.php" class="menu-item">
                <i class="fas fa-exchange-alt"></i>
                <span>Circulation Control</span>
            </a>
            <a href="fine_management.php" class="menu-item active">
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

    <main class="main-content" id="mainContent">
        <div class="page-header">
            <div>
                <h1 class="page-title">Fine Management</h1>
                <p class="welcome-text">Process fine payments and manage outstanding library fines</p>
            </div>
        </div>

        <!-- View Tabs -->
        <div class="view-tabs">
            <a href="?view=overview" class="view-tab <?php echo ($view_mode === 'overview' || empty($view_mode)) ? 'active' : ''; ?>">
                <i class="fas fa-list"></i> All Users with Fines
            </a>
            <a href="?view=search" class="view-tab <?php echo $view_mode === 'search' ? 'active' : ''; ?>">
                <i class="fas fa-search"></i> Search Specific User
            </a>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-row">
            <div class="stats-card total-unpaid">
                <div class="stats-icon" style="background: rgba(239, 68, 68, 0.1); color: var(--danger);">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="stats-number text-danger"><?php echo $fine_stats['total_unpaid'] ?? 0; ?></div>
                <div class="stats-label">Unpaid Fines</div>
            </div>
            <div class="stats-card total-paid">
                <div class="stats-icon" style="background: rgba(16, 185, 129, 0.1); color: var(--success);">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stats-number text-success"><?php echo $fine_stats['total_paid'] ?? 0; ?></div>
                <div class="stats-label">Paid Fines</div>
            </div>
            <div class="stats-card total-partial">
                <div class="stats-icon" style="background: rgba(245, 158, 11, 0.1); color: var(--warning);">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stats-number text-warning"><?php echo $fine_stats['total_partial'] ?? 0; ?></div>
                <div class="stats-label">Partial Payments</div>
            </div>
            <div class="stats-card total-users">
                <div class="stats-icon" style="background: rgba(14, 165, 233, 0.1); color: var(--accent);">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stats-number text-info"><?php echo $fine_stats['total_users_with_fines'] ?? 0; ?></div>
                <div class="stats-label">Users with Fines</div>
            </div>
            <div class="stats-card total-amount">
                <div class="stats-icon" style="background: rgba(30, 58, 138, 0.1); color: var(--primary);">
                    <i class="fas fa-dollar-sign"></i>
                </div>
                <div class="stats-number text-primary">RM <?php echo number_format($fine_stats['total_outstanding_amount'] ?? 0, 2); ?></div>
                <div class="stats-label">Total Outstanding</div>
            </div>
        </div>

        <!-- Alert Messages -->
        <?php if (isset($error)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($paymentSuccess): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                Payment processed successfully! Receipt generated.
            </div>
        <?php endif; ?>

        <?php if ($letterGenerated): ?>
            <div class="alert alert-success">
                <i class="fas fa-envelope"></i>
                Official letter generated successfully!
            </div>
        <?php endif; ?>

        <?php if ($view_mode === 'overview' || empty($view_mode)): ?>
            <!-- All Users with Fines Overview -->
            <div class="content-card">
                <div class="card-header">
                    <h2 class="card-title"><i class="fas fa-list"></i> All Users with Outstanding Fines</h2>
                </div>
                <div class="card-body">
                    <?php if (empty($allUserFines)): ?>
                        <div class="no-results">
                            <h3>No Outstanding Fines</h3>
                            <p>There are currently no users with outstanding fines.</p>
                        </div>
                    <?php else: ?>
                        <table class="users-table">
                            <thead>
                                <tr>
                                    <th>User Info</th>
                                    <th>Type</th>
                                    <th>Class/Dept</th>
                                    <th>Total Fines</th>
                                    <th>Amount Due</th>
                                    <th>Fine Reasons</th>
                                    <th>Latest Fine</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($allUserFines as $userFine): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($userFine['full_name'] ?? 'N/A'); ?></strong><br>
                                        <small><?php echo htmlspecialchars($userFine['login_id']); ?></small>
                                    </td>
                                    <td><?php echo ucfirst($userFine['user_type']); ?></td>
                                    <td><?php echo htmlspecialchars($userFine['class_dept'] ?? 'N/A'); ?></td>
                                    <td><?php echo $userFine['total_fines']; ?></td>
                                    <td><strong>RM <?php echo number_format($userFine['total_amount'], 2); ?></strong></td>
                                    <td><?php echo htmlspecialchars($userFine['fine_reasons']); ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($userFine['latest_fine_date'])); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="?view=search&login_id=<?php echo urlencode($userFine['login_id']); ?>" class="btn btn-primary btn-sm">
                                                <i class="fas fa-credit-card"></i> Process Payment
                                            </a>
                                            <?php if ($userFine['total_amount'] >= 10): ?>
                                            <button onclick="generateLetter('<?php echo $userFine['userID']; ?>')" class="btn btn-warning btn-sm">
                                                <i class="fas fa-envelope"></i> Generate Letter
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

        <?php elseif ($view_mode === 'search'): ?>
            <!-- Search Section -->
            <div class="content-card">
                <div class="card-header">
                    <h2 class="card-title"><i class="fas fa-search"></i> Search User</h2>
                </div>
                <div class="card-body">
                    <form method="POST" class="search-form">
                        <div class="form-group">
                            <label for="login_id">User Login ID</label>
                            <input type="text" class="form-control" id="login_id" name="login_id" 
                                   placeholder="Enter STU001, STF001, etc." 
                                   value="<?php echo htmlspecialchars($_GET['login_id'] ?? $_POST['login_id'] ?? ''); ?>">
                        </div>
                        <button type="submit" name="search_user" class="btn btn-primary">
                            <i class="fas fa-search"></i> Search
                        </button>
                    </form>
                </div>
            </div>

            <?php if ($userInfo): ?>
                <!-- User Information -->
                <div class="content-card">
                    <div class="card-header">
                        <h2 class="card-title"><i class="fas fa-user"></i> User Information</h2>
                    </div>
                    <div class="card-body">
                        <div class="user-info-grid">
                            <div class="info-item">
                                <div class="info-label">Full Name</div>
                                <div class="info-value"><?php echo htmlspecialchars($userInfo['full_name'] ?? $userInfo['first_name'] . ' ' . $userInfo['last_name']); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">ID Number</div>
                                <div class="info-value"><?php echo htmlspecialchars($userInfo['id_number'] ?? 'N/A'); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Login ID</div>
                                <div class="info-value"><?php echo htmlspecialchars($userInfo['login_id']); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">User Type</div>
                                <div class="info-value"><?php echo ucfirst($userInfo['user_type']); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label"><?php echo $userInfo['user_type'] == 'student' ? 'Class' : 'Department'; ?></div>
                                <div class="info-value"><?php echo htmlspecialchars($userInfo['class_dept'] ?? 'N/A'); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Account Status</div>
                                <div class="info-value">
                                    <span class="status-badge status-<?php echo $userInfo['account_status']; ?>">
                                        <?php echo ucfirst($userInfo['account_status']); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Fines Section -->
                <div class="content-card">
                    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
                        <h2 class="card-title"><i class="fas fa-money-bill-wave"></i> Outstanding Fines</h2>
                        <?php if (!empty($userFines)): ?>
                        <button onclick="showLetterModal()" class="btn btn-warning btn-sm">
                            <i class="fas fa-envelope"></i> Generate Official Letter
                        </button>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <?php if (empty($userFines)): ?>
                            <div class="no-results">
                                <h3>No Outstanding Fines</h3>
                                <p>This user has no unpaid fines at this time.</p>
                            </div>
                        <?php else: ?>
                            <form method="POST" id="paymentForm">
                                <input type="hidden" name="user_id" value="<?php echo $userInfo['userID']; ?>">
                                
                                <table class="fines-table">
                                    <thead>
                                        <tr>
                                            <th>Select</th>
                                            <th>Fine ID</th>
                                            <th>Book Title</th>
                                            <th>Reason</th>
                                            <th>Fine Date</th>
                                            <th>Total Amount</th>
                                            <th>Paid</th>
                                            <th>Balance</th>
                                            <th>Payment Amount</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $totalBalance = 0;
                                        foreach ($userFines as $fine): 
                                            $balance = $fine['balance_due'] ?: $fine['fine_amount'];
                                            $totalBalance += $balance;
                                        ?>
                                        <tr>
                                            <td>
                                                <input type="checkbox" name="selected_fines[]" value="<?php echo $fine['fineID']; ?>" 
                                                       class="fine-checkbox" data-balance="<?php echo $balance; ?>">
                                            </td>
                                            <td><?php echo $fine['fineID']; ?></td>
                                            <td><?php echo htmlspecialchars($fine['bookTitle'] ?? 'N/A'); ?></td>
                                            <td><?php echo ucfirst($fine['fine_reason']); ?></td>
                                            <td><?php echo date('d/m/Y', strtotime($fine['fine_date'])); ?></td>
                                            <td>RM <?php echo number_format($fine['fine_amount'], 2); ?></td>
                                            <td>RM <?php echo number_format($fine['amount_paid'] ?: 0, 2); ?></td>
                                            <td>RM <?php echo number_format($balance, 2); ?></td>
                                            <td>
                                                <input type="number" name="fine_amount_<?php echo $fine['fineID']; ?>" 
                                                       class="amount-input payment-amount" step="0.01" min="0" 
                                                       max="<?php echo $balance; ?>" value="<?php echo $balance; ?>" disabled>
                                            </td>
                                            <td>
                                                <span class="status-badge status-<?php echo $fine['payment_status']; ?>">
                                                    <?php echo ucfirst(str_replace('_', ' ', $fine['payment_status'])); ?>
                                                </span>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>

                                <div class="payment-section">
                                    <div class="payment-row">
                                        <div class="payment-info">
                                            <div>
                                                <strong>Total Outstanding: RM <?php echo number_format($totalBalance, 2); ?></strong>
                                            </div>
                                            <div class="payment-total">
                                                Selected Total: RM <span id="selectedTotal">0.00</span>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Calculator Section -->
                                    <div class="calculator-section">
                                        <h4><i class="fas fa-calculator"></i> Payment Calculator</h4>
                                        <div class="calculator-row">
                                            <label for="cash_received"><strong>Cash Received (RM):</strong></label>
                                            <input type="number" id="cash_received" name="cash_received" 
                                                   class="form-control" style="width: 150px;" step="0.01" min="0" 
                                                   placeholder="0.00" onkeyup="calculateChange()">
                                            <button type="button" class="btn btn-secondary btn-sm" onclick="fillExactAmount()">
                                                <i class="fas fa-equals"></i> Exact Amount
                                            </button>
                                        </div>
                                        <div class="calculator-row">
                                            <span><strong>Amount to Pay:</strong></span>
                                            <span class="calculator-amount">RM <span id="amountToPay">0.00</span></span>
                                        </div>
                                        <div class="calculator-row">
                                            <span><strong>Change to Give:</strong></span>
                                            <span class="change-amount">RM <span id="changeAmount">0.00</span></span>
                                        </div>
                                        <div id="changeError" class="alert alert-warning" style="display: none; margin-top: 1rem;">
                                            <i class="fas fa-exclamation-triangle"></i>
                                            <span id="changeErrorText">Insufficient cash received!</span>
                                        </div>
                                    </div>

                                    <div style="margin-top: 1rem; display: flex; gap: 1rem; justify-content: flex-end; flex-wrap: wrap;">
                                        <button type="submit" name="process_payment" class="btn btn-success" id="processBtn" disabled>
                                            <i class="fas fa-credit-card"></i> Process Payment
                                        </button>
                                        <button type="button" class="btn btn-secondary" onclick="clearSelection()">
                                            <i class="fas fa-times"></i> Clear Selection
                                        </button>
                                        <button type="button" class="btn btn-primary" onclick="selectAll()">
                                            <i class="fas fa-check-double"></i> Select All
                                        </button>
                                    </div>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- Letter Generation Modal -->
        <div id="letterModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h3><i class="fas fa-envelope"></i> Generate Official Letter</h3>
                    <span class="close" onclick="closeLetter()">&times;</span>
                </div>
                <form method="POST" id="letterForm">
                    <?php if ($userInfo): ?>
                        <input type="hidden" name="user_id" value="<?php echo $userInfo['userID']; ?>">
                        <?php foreach ($userFines as $fine): ?>
                            <input type="hidden" name="letter_fines[]" value="<?php echo $fine['fineID']; ?>">
                        <?php endforeach; ?>
                    <?php endif; ?>
                    
                    <div class="form-group">
                        <label for="letter_type">Letter Type:</label>
                        <select name="letter_type" id="letter_type" class="form-control" required>
                            <option value="">Select Letter Type</option>
                            <option value="warning">Warning Letter (Surat Amaran)</option>
                            <option value="final_notice">Final Notice (Notis Akhir)</option>
                            <option value="replacement_demand">Replacement Demand (Tuntutan Ganti Rugi)</option>
                        </select>
                    </div>
                    
                    <div style="margin-top: 1rem; display: flex; gap: 1rem; justify-content: flex-end;">
                        <button type="submit" name="generate_letter" class="btn btn-warning">
                            <i class="fas fa-envelope"></i> Generate Letter
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="closeLetter()">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($receiptData): ?>
            <!-- Receipt -->
            <div class="content-card no-print">
                <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
                    <h2 class="card-title"><i class="fas fa-receipt"></i> Payment Receipt</h2>
                    <div>
                        <button type="button" class="btn btn-primary" onclick="printReceipt()">
                            <i class="fas fa-print"></i> Print Receipt
                        </button>
                        <button type="button" class="btn btn-success" onclick="downloadReceiptPDF()">
                            <i class="fas fa-download"></i> Download PDF
                        </button>
                    </div>
                </div>
            </div>

            <div class="receipt" id="receipt">
                <div class="receipt-header">
                    <div class="school-logo-receipt">
                        <div style="font-size: 12px; font-weight: bold; color: var(--primary);">SMK<br>LOGO</div>
                    </div>
                    <div class="school-name">SMK CHENDERING</div>
                    <div style="font-weight: bold; margin: 0.5rem 0;">SEKOLAH MENENGAH KEBANGSAAN CHENDERING</div>
                    <div class="school-details">
                        Jalan Sekolah, 21080 Kuala Terengganu, Terengganu<br>
                        Tel: 09-622 3456 | Email: info@smkchendering.edu.my
                    </div>
                </div>

                <div class="receipt-title">RESIT RASMI / OFFICIAL RECEIPT</div>

                <div class="receipt-body">
                    <div class="receipt-row">
                        <span>Receipt No / No. Resit:</span>
                        <span><?php echo $receiptData['receipt_number']; ?></span>
                    </div>
                    <div class="receipt-row">
                        <span>Date/Time / Tarikh/Masa:</span>
                        <span><?php echo date('d/m/Y H:i:s', strtotime($receiptData['transaction_date'])); ?></span>
                    </div>
                    <div class="receipt-row">
                        <span>Payer Name / Nama Pembayar:</span>
                        <span><?php echo htmlspecialchars($receiptData['user_info']['full_name'] ?? $receiptData['user_info']['first_name'] . ' ' . $receiptData['user_info']['last_name']); ?></span>
                    </div>
                    <div class="receipt-row">
                        <span>ID:</span>
                        <span><?php echo htmlspecialchars($receiptData['user_info']['login_id']); ?></span>
                    </div>

                    <div class="receipt-items">
                        <div style="font-weight: bold; margin-bottom: 0.5rem;">FINE PAYMENT DETAILS / BUTIRAN PEMBAYARAN DENDA:</div>
                        <?php foreach ($receiptData['paid_fines'] as $fineId): ?>
                            <?php
                            $fineQuery = "SELECT f.*, book.bookTitle FROM fines f 
                                         LEFT JOIN borrow b ON f.borrowID = b.borrowID 
                                         LEFT JOIN book ON b.bookID = book.bookID 
                                         WHERE f.fineID = ?";
                            $stmt = $conn->prepare($fineQuery);
                            $stmt->bind_param('i', $fineId);
                            $stmt->execute();
                            $result = $stmt->get_result();
                            $fineDetail = $result->fetch_assoc();
                            
                            $paidAmount = floatval($_POST['fine_amount_' . $fineId] ?? $fineDetail['amount_paid']);
                            ?>
                            <div class="receipt-row">
                                <span>Fine #<?php echo $fineId; ?> - <?php echo htmlspecialchars($fineDetail['bookTitle'] ?? 'N/A'); ?></span>
                                <span>RM <?php echo number_format($paidAmount, 2); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="receipt-row receipt-total">
                        <span>TOTAL PAID / JUMLAH DIBAYAR:</span>
                        <span>RM <?php echo number_format($receiptData['total_paid'], 2); ?></span>
                    </div>
                    <div class="receipt-row">
                        <span>Cash Received / Tunai Diterima:</span>
                        <span>RM <?php echo number_format($receiptData['cash_received'], 2); ?></span>
                    </div>
                    <div class="receipt-row">
                        <span>Change / Baki:</span>
                        <span>RM <?php echo number_format($receiptData['change_given'], 2); ?></span>
                    </div>
                    <div class="receipt-row">
                        <span>Payment Method / Kaedah Bayar:</span>
                        <span>Tunai (Cash)</span>
                    </div>

                    <div class="signature-section">
                        <div class="signature-box">
                            <div class="signature-line">
                                Disediakan Oleh<br>Prepared By<br>
                                <?php echo htmlspecialchars($librarian_name); ?>
                            </div>
                        </div>
                        <div class="signature-box">
                            <div class="signature-line">
                                Diterima Oleh<br>Received By<br>
                                <?php echo htmlspecialchars($receiptData['user_info']['full_name'] ?? $receiptData['user_info']['first_name'] . ' ' . $receiptData['user_info']['last_name']); ?>
                            </div>
                        </div>
                    </div>

                    <div style="margin-top: 2rem; font-size: 10px; text-align: center; color: #666;">
                        Terima kasih atas pembayaran anda.<br>
                        Thank you for your payment.<br>
                        <strong>** SILA SIMPAN RESIT INI SEBAGAI BUKTI PEMBAYARAN **<br>
                        ** PLEASE KEEP THIS RECEIPT AS PROOF OF PAYMENT **</strong>
                    </div>
                </div>

                <!-- Office Copy -->
                <div class="office-copy">
                    <div class="copy-label">SALINAN PEJABAT / OFFICE COPY</div>
                    
                    <div class="receipt-row">
                        <span>Receipt No:</span>
                        <span><?php echo $receiptData['receipt_number']; ?></span>
                    </div>
                    <div class="receipt-row">
                        <span>Date/Time:</span>
                        <span><?php echo date('d/m/Y H:i:s', strtotime($receiptData['transaction_date'])); ?></span>
                    </div>
                    <div class="receipt-row">
                        <span>Payer:</span>
                        <span><?php echo htmlspecialchars($receiptData['user_info']['full_name'] ?? $receiptData['user_info']['first_name'] . ' ' . $receiptData['user_info']['last_name']); ?></span>
                    </div>
                    <div class="receipt-row">
                        <span>Total Paid:</span>
                        <span>RM <?php echo number_format($receiptData['total_paid'], 2); ?></span>
                    </div>
                    <div class="receipt-row">
                        <span>Processed By:</span>
                        <span><?php echo htmlspecialchars($librarian_name); ?></span>
                    </div>

                    <div style="margin-top: 1rem; font-size: 10px; text-align: center; color: #666;">
                        Rekod disimpan dalam sistem / Record saved in system<br>
                        System Reference: <?php echo $receiptData['receipt_number']; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($letterData): ?>
            <!-- Official Letter -->
            <div class="content-card no-print">
                <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
                    <h2 class="card-title"><i class="fas fa-envelope"></i> Official Letter</h2>
                    <div>
                        <button type="button" class="btn btn-primary" onclick="printLetter()">
                            <i class="fas fa-print"></i> Print Letter
                        </button>
                        <button type="button" class="btn btn-success" onclick="downloadLetterPDF()">
                            <i class="fas fa-download"></i> Download PDF
                        </button>
                    </div>
                </div>
            </div>

            <div class="letter" id="officialLetter">
                <div class="letter-header">
                    <div class="school-logo-receipt">
                        <div style="font-size: 12px; font-weight: bold; color: var(--primary);">SMK<br>LOGO</div>
                    </div>
                    <div class="school-name">SMK CHENDERING</div>
                    <div style="font-weight: bold; margin: 0.5rem 0;">SEKOLAH MENENGAH KEBANGSAAN CHENDERING</div>
                    <div class="school-details">
                        Jalan Sekolah, 21080 Kuala Terengganu, Terengganu<br>
                        Tel: 09-622 3456 | Email: info@smkchendering.edu.my
                    </div>
                </div>

                <div class="letter-title">
                    <?php 
                    $letterTitles = [
                        'warning' => 'SURAT AMARAN / WARNING LETTER',
                        'final_notice' => 'NOTIS AKHIR / FINAL NOTICE',
                        'replacement_demand' => 'SURAT TUNTUTAN GANTI RUGI / REPLACEMENT DEMAND'
                    ];
                    echo $letterTitles[$letterData['letter_type']] ?? 'SURAT RASMI';
                    ?>
                </div>

                <div class="letter-body">
                    <div class="receipt-row" style="margin-bottom: 1rem;">
                        <span>Ref No / No. Rujukan:</span>
                        <span><?php echo $letterData['letter_number']; ?></span>
                    </div>
                    <div class="receipt-row" style="margin-bottom: 2rem;">
                        <span>Date / Tarikh:</span>
                        <span><?php echo date('d/m/Y', strtotime($letterData['issue_date'])); ?></span>
                    </div>

                    <div class="letter-content">
                        <?php echo nl2br(htmlspecialchars($letterData['letter_content'])); ?>
                    </div>

                    <div class="letter-footer">
                        <div style="margin-bottom: 2rem;">
                            <strong>Sila hubungi Perpustakaan untuk maklumat lanjut.<br>
                            Please contact the Library for further information.</strong>
                        </div>

                        <div class="signature-section">
                            <div class="signature-box">
                                <div class="signature-line">
                                    Yang benar,<br>Sincerely,<br><br><br>
                                    ________________________<br>
                                    <?php echo htmlspecialchars($librarian_name); ?><br>
                                    Pustakawan / Librarian<br>
                                    SMK Chendering
                                </div>
                            </div>
                            <div class="signature-box">
                                <div style="text-align: right; margin-top: 2rem;">
                                    <strong>Cop Rasmi Sekolah<br>Official School Stamp</strong>
                                    <div style="width: 80px; height: 80px; border: 2px dashed #999; margin: 1rem auto; display: flex; align-items: center; justify-content: center; font-size: 10px;">
                                        COP<br>SEKOLAH
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </main>

    <script>
        document.getElementById('sidebarToggle').addEventListener('click', function() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('collapsed');
        });

        // Payment calculation functions
        function updateSelectedTotal() {
            let total = 0;
            document.querySelectorAll('.fine-checkbox:checked').forEach(checkbox => {
                const fineId = checkbox.value;
                const amountInput = document.querySelector(`input[name="fine_amount_${fineId}"]`);
                if (amountInput && amountInput.value) {
                    total += parseFloat(amountInput.value);
                }
            });
            document.getElementById('selectedTotal').textContent = total.toFixed(2);
            document.getElementById('amountToPay').textContent = total.toFixed(2);
            calculateChange();
        }

        function calculateChange() {
            const amountToPay = parseFloat(document.getElementById('amountToPay').textContent || 0);
            const cashReceived = parseFloat(document.getElementById('cash_received').value || 0);
            const change = cashReceived - amountToPay;
            
            document.getElementById('changeAmount').textContent = Math.max(0, change).toFixed(2);
            
            const errorDiv = document.getElementById('changeError');
            const processBtn = document.getElementById('processBtn');
            
            if (amountToPay > 0) {
                if (cashReceived < amountToPay) {
                    errorDiv.style.display = 'block';
                    document.getElementById('changeErrorText').textContent = 
                        `Insufficient cash! Need RM ${(amountToPay - cashReceived).toFixed(2)} more.`;
                    processBtn.disabled = true;
                } else {
                    errorDiv.style.display = 'none';
                    processBtn.disabled = false;
                }
            } else {
                processBtn.disabled = true;
            }
        }

        // Auto-fill cash received with exact amount
        function fillExactAmount() {
            const selectedTotal = parseFloat(document.getElementById('selectedTotal').textContent || 0);
            if (selectedTotal > 0) {
                document.getElementById('cash_received').value = selectedTotal.toFixed(2);
                calculateChange();
            }
        }

        // Event listeners
        document.querySelectorAll('.fine-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const fineId = this.value;
                const amountInput = document.querySelector(`input[name="fine_amount_${fineId}"]`);
                const balance = parseFloat(this.dataset.balance);
                
                if (this.checked) {
                    amountInput.value = balance.toFixed(2);
                    amountInput.disabled = false;
                } else {
                    amountInput.value = '0.00';
                    amountInput.disabled = true;
                }
                updateSelectedTotal();
            });
        });

        document.querySelectorAll('.payment-amount').forEach(input => {
            input.addEventListener('input', updateSelectedTotal);
        });

        function clearSelection() {
            document.querySelectorAll('.fine-checkbox').forEach(checkbox => {
                checkbox.checked = false;
                const fineId = checkbox.value;
                const amountInput = document.querySelector(`input[name="fine_amount_${fineId}"]`);
                amountInput.value = '0.00';
                amountInput.disabled = true;
            });
            document.getElementById('cash_received').value = '';
            updateSelectedTotal();
        }

        function selectAll() {
            document.querySelectorAll('.fine-checkbox').forEach(checkbox => {
                checkbox.checked = true;
                checkbox.dispatchEvent(new Event('change'));
            });
        }

        // Modal functions
        function showLetterModal() {
            document.getElementById('letterModal').style.display = 'block';
        }

        function closeLetter() {
            document.getElementById('letterModal').style.display = 'none';
        }

        function generateLetter(userId) {
            // This would redirect to the search page with the user pre-loaded
            window.location.href = `?view=search&action=letter&user_id=${userId}`;
        }

        // Print functions
        function printReceipt() {
            window.print();
        }

        function printLetter() {
            window.print();
        }

        // PDF download functions
        function downloadReceiptPDF() {
            const { jsPDF } = window.jspdf;
            const receipt = document.getElementById('receipt');
            
            html2canvas(receipt, {
                scale: 2,
                useCORS: true,
                allowTaint: true
            }).then(canvas => {
                const imgData = canvas.toDataURL('image/png');
                const pdf = new jsPDF('p', 'mm', 'a4');
                const imgWidth = 210;
                const pageHeight = 295;
                const imgHeight = (canvas.height * imgWidth) / canvas.width;
                let heightLeft = imgHeight;
                let position = 0;

                pdf.addImage(imgData, 'PNG', 0, position, imgWidth, imgHeight);
                heightLeft -= pageHeight;

                while (heightLeft >= 0) {
                    position = heightLeft - imgHeight;
                    pdf.addPage();
                    pdf.addImage(imgData, 'PNG', 0, position, imgWidth, imgHeight);
                    heightLeft -= pageHeight;
                }

                const receiptNumber = '<?php echo $receiptData['receipt_number'] ?? 'RECEIPT'; ?>';
                pdf.save(`${receiptNumber}_receipt.pdf`);
            }).catch(error => {
                console.error('Error generating PDF:', error);
                alert('Error generating PDF. Please try printing instead.');
            });
        }

        function downloadLetterPDF() {
            const { jsPDF } = window.jspdf;
            const letter = document.getElementById('officialLetter');
            
            html2canvas(letter, {
                scale: 2,
                useCORS: true,
                allowTaint: true
            }).then(canvas => {
                const imgData = canvas.toDataURL('image/png');
                const pdf = new jsPDF('p', 'mm', 'a4');
                const imgWidth = 210;
                const pageHeight = 295;
                const imgHeight = (canvas.height * imgWidth) / canvas.width;
                let heightLeft = imgHeight;
                let position = 0;

                pdf.addImage(imgData, 'PNG', 0, position, imgWidth, imgHeight);
                heightLeft -= pageHeight;

                while (heightLeft >= 0) {
                    position = heightLeft - imgHeight;
                    pdf.addPage();
                    pdf.addImage(imgData, 'PNG', 0, position, imgWidth, imgHeight);
                    heightLeft -= pageHeight;
                }

                const letterNumber = '<?php echo $letterData['letter_number'] ?? 'LETTER'; ?>';
                pdf.save(`${letterNumber}_official_letter.pdf`);
            }).catch(error => {
                console.error('Error generating PDF:', error);
                alert('Error generating PDF. Please try printing instead.');
            });
        }

        // Form validation
        document.getElementById('paymentForm')?.addEventListener('submit', function(e) {
            const selectedFines = document.querySelectorAll('.fine-checkbox:checked');
            const cashReceived = parseFloat(document.getElementById('cash_received').value || 0);
            const selectedTotal = parseFloat(document.getElementById('selectedTotal').textContent || 0);

            if (selectedFines.length === 0) {
                e.preventDefault();
                alert('Please select at least one fine to pay.');
                return false;
            }

            if (cashReceived < selectedTotal) {
                e.preventDefault();
                alert(`Insufficient cash received. Required: RM ${selectedTotal.toFixed(2)}, Received: RM ${cashReceived.toFixed(2)}`);
                return false;
            }

            const change = cashReceived - selectedTotal;
            if (!confirm(`Process payment of RM ${selectedTotal.toFixed(2)}?\nCash Received: RM ${cashReceived.toFixed(2)}\nChange to Give: RM ${change.toFixed(2)}`)) {
                e.preventDefault();
                return false;
            }

            return true;
        });

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-dismiss alerts after 5 seconds
            setTimeout(function() {
                const alerts = document.querySelectorAll('.alert');
                alerts.forEach(alert => {
                    alert.style.transition = 'opacity 0.5s';
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 500);
                });
            }, 5000);

            // Pre-load search if login_id in URL
            const urlParams = new URLSearchParams(window.location.search);
            const loginId = urlParams.get('login_id');
            if (loginId && document.getElementById('login_id')) {
                document.getElementById('login_id').value = loginId;
                // Auto-submit search if needed
                setTimeout(() => {
                    document.querySelector('button[name="search_user"]').click();
                }, 100);
            }
        });

        // Close modal when clicking outside
        window.addEventListener('click', function(event) {
            const modal = document.getElementById('letterModal');
            if (event.target === modal) {
                modal.style.display = 'none';
            }
        });
    </script>
</body>
</html>