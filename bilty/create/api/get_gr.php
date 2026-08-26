<?php
// Start output buffering to catch any accidental output
ob_start();

header('Content-Type: application/json');

// Ensure session and login to access branch/company context
include '../../../protect/auth.php';
include '../../../protect/db.php';
include '../includes/gr_handler.php';

$action = isset($_GET['action']) ? $_GET['action'] : '';

// Clear any buffered output before sending JSON
ob_end_clean();

if ($action === 'getNextGR') {
    getNextGRAPI($conn);
} elseif ($action === 'checkGR') {
    checkGRUnique($conn);
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
}



/**
 * API to get next GR number
 */
function getNextGRAPI($conn) {
    try {
        $nextGR = getNextGRNumber($conn);
        echo json_encode([
            'success' => true,
            'gr_number' => $nextGR
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}

/**
 * API to check if GR is unique
 */
function checkGRUnique($conn) {
    try {
        $grNumber = isset($_GET['gr']) ? $_GET['gr'] : '';
        $biltyId = isset($_GET['bilty_id']) ? intval($_GET['bilty_id']) : null;
        
        if (!$grNumber) {
            echo json_encode([
                'success' => false,
                'message' => 'GR number is required'
            ]);
            return;
        }
        
        // Check uniqueness (allow any format)
        $isUnique = isGRNumberUnique($conn, $grNumber, $biltyId);
        
        echo json_encode([
            'success' => true,
            'is_unique' => $isUnique,
            'message' => $isUnique ? 'GR number is available' : 'GR number already exists'
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}

$conn->close();
?>
