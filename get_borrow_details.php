<?php
session_start();
require_once 'dbconnect.php';
require_once 'auth_helper.php';

// Set content type
header('Content-Type: application/json');

// Enable detailed error reporting (remove or disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Authenticate and authorize access
try {
    checkPageAccess();
    requireRole('librarian');
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Authentication error: ' . $e->getMessage()
    ]);
    exit;
}

// Validate required parameter
if (!isset($_GET['borrowID'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Missing borrowID parameter'
    ]);
    exit;
}

$borrowID = intval($_GET['borrowID']);
if ($borrowID <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid borrowID parameter'
    ]);
    exit;
}

try {
    // Comprehensive query to fetch all relevant data
    $query = "
        SELECT 
            b.*,
            u.first_name,
            u.last_name,
            u.email,
            u.phone_number,
            u.user_type,
            u.login_id,
            COALESCE(s.student_id_number, st.staff_id_number, u.login_id) AS id_number,
            bk.bookTitle,
            bk.bookAuthor,
            bk.bookPublisher,
            bk.book_ISBN,
            bk.bookBarcode,
            bk.shelf_location,
            bk.publication_year,
            bc.categoryName,
            f.fine_amount AS existing_fine,
            f.payment_status,
            f.amount_paid,
            f.balance_due,
            f.fine_date,
            br.overdue_fine_per_day,
            br.borrow_period_days,
            br.max_renewals_allowed,
            CASE 
                WHEN b.borrow_status = 'borrowed' AND b.due_date < CURDATE() 
                THEN DATEDIFF(CURDATE(), b.due_date)
                ELSE 0
            END AS days_overdue,
            CASE 
                WHEN b.borrow_status = 'borrowed' AND b.due_date < CURDATE() 
                THEN DATEDIFF(CURDATE(), b.due_date) * COALESCE(br.overdue_fine_per_day, 0.50)
                ELSE 0
            END AS calculated_fine
        FROM borrow b
        LEFT JOIN user u ON b.userID = u.userID
        LEFT JOIN student s ON u.userID = s.userID AND u.user_type = 'student'
        LEFT JOIN staff st ON u.userID = st.userID AND u.user_type = 'staff'
        LEFT JOIN book bk ON b.bookID = bk.bookID
        LEFT JOIN book_category bc ON bk.categoryID = bc.categoryID
        LEFT JOIN fines f ON b.borrowID = f.borrowID
        LEFT JOIN borrowing_rules br ON u.user_type = br.user_type
        WHERE b.borrowID = ?
    ";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }

    $stmt->bind_param("i", $borrowID);
    if (!$stmt->execute()) {
        throw new Exception("Execute failed: " . $stmt->error);
    }

    $result = $stmt->get_result();
    if ($result->num_rows == 0) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Borrow record not found'
        ]);
        exit;
    }

    $data = $result->fetch_assoc();

    // Calculate final fine to display
    $fine_amount = max($data['calculated_fine'], $data['existing_fine'] ?? 0);

    // Fetch renewal history
    $renewal_query = "
        SELECT renewal_date, old_due_date, new_due_date, renewal_reason, renewal_method
        FROM renewals 
        WHERE borrowID = ? 
        ORDER BY renewal_date DESC
    ";
    $renewal_stmt = $conn->prepare($renewal_query);
    $renewal_stmt->bind_param("i", $borrowID);
    $renewal_stmt->execute();
    $renewal_result = $renewal_stmt->get_result();
    $renewals = [];
    while ($row = $renewal_result->fetch_assoc()) {
        $renewals[] = $row;
    }
    $renewal_stmt->close();

    // Start building HTML
    $html = '
    <div style="display: grid; gap: 1.5rem; font-family: Inter, sans-serif;">

        <!-- Borrower Information -->
        <div style="border: 1px solid #e2e8f0; border-radius: 8px; padding: 1.5rem; background: #f8fafc;">
            <h4 style="color: #1e3a8a; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;">
                <i class="fas fa-user" style="color: #3b82f6;"></i> Borrower Information
            </h4>
            <div style="display: grid; gap: 0.75rem;">
                <div style="display: grid; grid-template-columns: 150px 1fr; gap: 1rem;">
                    <span style="font-weight: 500; color: #64748b;">Name:</span>
                    <span style="color: #1e293b;">' . htmlspecialchars($data['first_name'] . ' ' . $data['last_name']) . '</span>
                </div>
                <div style="display: grid; grid-template-columns: 150px 1fr; gap: 1rem;">
                    <span style="font-weight: 500; color: #64748b;">ID Number:</span>
                    <span style="color: #1e293b; font-family: monospace; background: #e2e8f0; padding: 2px 6px; border-radius: 4px;">' . htmlspecialchars($data['id_number']) . '</span>
                </div>
                <div style="display: grid; grid-template-columns: 150px 1fr; gap: 1rem;">
                    <span style="font-weight: 500; color: #64748b;">Type:</span>
                    <span style="color: #1e293b; text-transform: capitalize;">' . htmlspecialchars($data['user_type']) . '</span>
                </div>
                <div style="display: grid; grid-template-columns: 150px 1fr; gap: 1rem;">
                    <span style="font-weight: 500; color: #64748b;">Email:</span>
                    <span style="color: #1e293b;">' . htmlspecialchars($data['email'] ?? 'N/A') . '</span>
                </div>
                <div style="display: grid; grid-template-columns: 150px 1fr; gap: 1rem;">
                    <span style="font-weight: 500; color: #64748b;">Phone:</span>
                    <span style="color: #1e293b;">' . htmlspecialchars($data['phone_number'] ?? 'N/A') . '</span>
                </div>
            </div>
        </div>

        <!-- Book Information -->
        <div style="border: 1px solid #e2e8f0; border-radius: 8px; padding: 1.5rem; background: #f8fafc;">
            <h4 style="color: #1e3a8a; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;">
                <i class="fas fa-book" style="color: #3b82f6;"></i> Book Information
            </h4>
            <div style="display: grid; gap: 0.75rem;">
                <div style="display: grid; grid-template-columns: 150px 1fr; gap: 1rem;">
                    <span style="font-weight: 500; color: #64748b;">Title:</span>
                    <span style="color: #1e293b; font-weight: 500;">' . htmlspecialchars($data['bookTitle']) . '</span>
                </div>
                <div style="display: grid; grid-template-columns: 150px 1fr; gap: 1rem;">
                    <span style="font-weight: 500; color: #64748b;">Author:</span>
                    <span style="color: #1e293b;">' . htmlspecialchars($data['bookAuthor'] ?? 'N/A') . '</span>
                </div>
                <div style="display: grid; grid-template-columns: 150px 1fr; gap: 1rem;">
                    <span style="font-weight: 500; color: #64748b;">Publisher:</span>
                    <span style="color: #1e293b;">' . htmlspecialchars($data['bookPublisher'] ?? 'N/A') . '</span>
                </div>
                <div style="display: grid; grid-template-columns: 150px 1fr; gap: 1rem;">
                    <span style="font-weight: 500; color: #64748b;">ISBN:</span>
                    <span style="color: #1e293b; font-family: monospace;">' . htmlspecialchars($data['book_ISBN'] ?? 'N/A') . '</span>
                </div>
                <div style="display: grid; grid-template-columns: 150px 1fr; gap: 1rem;">
                    <span style="font-weight: 500; color: #64748b;">Barcode:</span>
                    <span style="color: #1e293b; font-family: monospace;">' . htmlspecialchars($data['bookBarcode'] ?? 'N/A') . '</span>
                </div>
                <div style="display: grid; grid-template-columns: 150px 1fr; gap: 1rem;">
                    <span style="font-weight: 500; color: #64748b;">Category:</span>
                    <span style="color: #1e293b;">' . htmlspecialchars($data['categoryName'] ?? 'N/A') . '</span>
                </div>
                <div style="display: grid; grid-template-columns: 150px 1fr; gap: 1rem;">
                    <span style="font-weight: 500; color: #64748b;">Location:</span>
                    <span style="color: #1e293b;">' . htmlspecialchars($data['shelf_location'] ?? 'N/A') . '</span>
                </div>
                ' . (!empty($data['publication_year']) ? '
                <div style="display: grid; grid-template-columns: 150px 1fr; gap: 1rem;">
                    <span style="font-weight: 500; color: #64748b;">Published:</span>
                    <span style="color: #1e293b;">' . htmlspecialchars($data['publication_year']) . '</span>
                </div>
                ' : '') . '
            </div>
        </div>

        <!-- Borrowing Details -->
        <div style="border: 1px solid #e2e8f0; border-radius: 8px; padding: 1.5rem; background: #f8fafc;">
            <h4 style="color: #1e3a8a; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;">
                <i class="fas fa-calendar-alt" style="color: #3b82f6;"></i> Borrowing Details
            </h4>
            <div style="display: grid; gap: 0.75rem;">
                <div style="display: grid; grid-template-columns: 150px 1fr; gap: 1rem;">
                    <span style="font-weight: 500; color: #64748b;">Borrow ID:</span>
                    <span style="color: #1e293b; font-family: monospace; font-weight: 500;">#' . $data['borrowID'] . '</span>
                </div>
                <div style="display: grid; grid-template-columns: 150px 1fr; gap: 1rem;">
                    <span style="font-weight: 500; color: #64748b;">Borrow Date:</span>
                    <span style="color: #1e293b;">' . date('d M Y', strtotime($data['borrow_date'])) . '</span>
                </div>
                <div style="display: grid; grid-template-columns: 150px 1fr; gap: 1rem;">
                    <span style="font-weight: 500; color: #64748b;">Due Date:</span>
                    <span style="color: #1e293b;">' . date('d M Y', strtotime($data['due_date'])) . '</span>
                </div>';

    if ($data['return_date']) {
        $html .= '
                <div style="display: grid; grid-template-columns: 150px 1fr; gap: 1rem;">
                    <span style="font-weight: 500; color: #64748b;">Return Date:</span>
                    <span style="color: #10b981; font-weight: 500;">' . date('d M Y', strtotime($data['return_date'])) . '</span>
                </div>';
    }

    // Status badge logic
    $status = $data['borrow_status'];
    $status_color = '#64748b';
    $status_bg = '#f1f5f9';

    if ($data['borrow_status'] == 'borrowed' && $data['days_overdue'] > 0) {
        $status = 'overdue';
        $status_color = '#ef4444';
        $status_bg = '#fef2f2';
    } elseif ($data['borrow_status'] == 'borrowed') {
        $status_color = '#f59e0b';
        $status_bg = '#fffbeb';
    } elseif ($data['borrow_status'] == 'returned') {
        $status_color = '#10b981';
        $status_bg = '#ecfdf5';
    }

    $html .= '
                <div style="display: grid; grid-template-columns: 150px 1fr; gap: 1rem;">
                    <span style="font-weight: 500; color: #64748b;">Status:</span>
                    <span style="padding: 4px 12px; border-radius: 20px; background: ' . $status_bg . '; color: ' . $status_color . '; font-weight: 500; text-transform: capitalize; display: inline-block; width: fit-content;">' . $status . '</span>
                </div>';

    if ($data['days_overdue'] > 0 && $data['borrow_status'] == 'borrowed') {
        $html .= '
                <div style="display: grid; grid-template-columns: 150px 1fr; gap: 1rem;">
                    <span style="font-weight: 500; color: #64748b;">Days Overdue:</span>
                    <span style="color: #ef4444; font-weight: 600;">' . $data['days_overdue'] . ' days</span>
                </div>';
    }

    $html .= '
                <div style="display: grid; grid-template-columns: 150px 1fr; gap: 1rem;">
                    <span style="font-weight: 500; color: #64748b;">Renewals:</span>
                    <span style="color: #1e293b;">' . $data['renewal_count'] . ' / ' . ($data['max_renewals_allowed'] ?? 2) . '</span>
                </div>';

    if (!empty($data['notes'])) {
        $html .= '
                <div style="display: grid; grid-template-columns: 150px 1fr; gap: 1rem;">
                    <span style="font-weight: 500; color: #64748b;">Notes:</span>
                    <span style="color: #1e293b; font-style: italic;">' . htmlspecialchars($data['notes']) . '</span>
                </div>';
    }

    $html .= '
            </div>
        </div>';

    // Fine Information Section
    if ($fine_amount > 0) {
        $html .= '
        <div style="border: 1px solid #fecaca; border-radius: 8px; padding: 1.5rem; background: #fef2f2;">
            <h4 style="color: #dc2626; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;">
                <i class="fas fa-exclamation-triangle" style="color: #ef4444;"></i> Fine Information
            </h4>
            <div style="display: grid; gap: 0.75rem;">
                <div style="display: grid; grid-template-columns: 150px 1fr; gap: 1rem;">
                    <span style="font-weight: 500; color: #991b1b;">Fine Amount:</span>
                    <span style="color: #dc2626; font-weight: 600; font-size: 1.1em;">RM ' . number_format($fine_amount, 2) . '</span>
                </div>';

        if (!empty($data['payment_status'])) {
            $payment_color = $data['payment_status'] === 'paid' ? '#10b981' : '#f59e0b';
            $html .= '
                <div style="display: grid; grid-template-columns: 150px 1fr; gap: 1rem;">
                    <span style="font-weight: 500; color: #991b1b;">Payment Status:</span>
                    <span style="color: ' . $payment_color . '; font-weight: 500; text-transform: capitalize;">' . htmlspecialchars($data['payment_status']) . '</span>
                </div>';

            if ($data['amount_paid'] > 0) {
                $html .= '
                <div style="display: grid; grid-template-columns: 150px 1fr; gap: 1rem;">
                    <span style="font-weight: 500; color: #991b1b;">Amount Paid:</span>
                    <span style="color: #10b981; font-weight: 500;">RM ' . number_format($data['amount_paid'], 2) . '</span>
                </div>';
            }

            if ($data['balance_due'] > 0) {
                $html .= '
                <div style="display: grid; grid-template-columns: 150px 1fr; gap: 1rem;">
                    <span style="font-weight: 500; color: #991b1b;">Balance Due:</span>
                    <span style="color: #dc2626; font-weight: 600;">RM ' . number_format($data['balance_due'], 2) . '</span>
                </div>';
            }

            if ($data['fine_date']) {
                $html .= '
                <div style="display: grid; grid-template-columns: 150px 1fr; gap: 1rem;">
                    <span style="font-weight: 500; color: #991b1b;">Fine Date:</span>
                    <span style="color: #991b1b;">' . date('d M Y', strtotime($data['fine_date'])) . '</span>
                </div>';
            }
        }

        $html .= '
            </div>
        </div>';
    }

    // Renewal History Section
    if (!empty($renewals)) {
        $html .= '
        <div style="border: 1px solid #e2e8f0; border-radius: 8px; padding: 1.5rem; background: #f8fafc;">
            <h4 style="color: #1e3a8a; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;">
                <i class="fas fa-history" style="color: #3b82f6;"></i> Renewal History
            </h4>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; background: white; border-radius: 6px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                    <thead>
                        <tr style="background: #f8fafc;">
                            <th style="padding: 12px; text-align: left; border-bottom: 1px solid #e2e8f0; font-weight: 500; color: #64748b;">Date</th>
                            <th style="padding: 12px; text-align: left; border-bottom: 1px solid #e2e8f0; font-weight: 500; color: #64748b;">Old Due Date</th>
                            <th style="padding: 12px; text-align: left; border-bottom: 1px solid #e2e8f0; font-weight: 500; color: #64748b;">New Due Date</th>
                            <th style="padding: 12px; text-align: left; border-bottom: 1px solid #e2e8f0; font-weight: 500; color: #64748b;">Method</th>
                        </tr>
                    </thead>
                    <tbody>';

        foreach ($renewals as $renewal) {
            $html .= '
                <tr style="border-bottom: 1px solid #f1f5f9;">
                    <td style="padding: 12px; color: #1e293b;">' . date('d M Y', strtotime($renewal['renewal_date'])) . '</td>
                    <td style="padding: 12px; color: #1e293b;">' . date('d M Y', strtotime($renewal['old_due_date'])) . '</td>
                    <td style="padding: 12px; color: #10b981; font-weight: 500;">' . date('d M Y', strtotime($renewal['new_due_date'])) . '</td>
                    <td style="padding: 12px; color: #64748b; text-transform: capitalize;">' . htmlspecialchars($renewal['renewal_method'] ?? 'Manual') . '</td>
                </tr>';
        }

        $html .= '
                    </tbody>
                </table>
            </div>
        </div>';
    }

    $html .= '</div>'; // Close main container

    // Return success response
    echo json_encode([
        'success' => true,
        'html' => $html,
        'data' => $data
    ]);

} catch (Exception $e) {
    error_log("Error in get_borrow_details.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while retrieving data. Please try again.',
        'debug' => [
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]
    ]);
}

// Close prepared statement if exists
if (isset($stmt)) {
    $stmt->close();
}
if (isset($renewal_stmt) && is_object($renewal_stmt)) {
    $renewal_stmt->close();
}

$conn->close();
?>