<?php
/**
 * Get the next GR (Goods Receipt) number
 * Format: <branchPrefix>/<sequence>, e.g., 102/001, 102/002, etc.
 * Branch prefix is derived from logged-in user's company/branch (defaults to 102).
 */
function getNextGRNumber($conn) {
    try {
        // Ensure session is available to read company/branch code
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        // Determine branch prefix (use logged-in company_id when available)
        $branchPrefix = 102;
        if (isset($_SESSION['company_id']) && is_numeric($_SESSION['company_id'])) {
            $branchPrefix = (int) $_SESSION['company_id'];
        }

        // Fetch last GR for this branch prefix that matches numeric pattern only
        $sql = "SELECT gr_number FROM biltys WHERE gr_number REGEXP '^" . $branchPrefix . "/[0-9]+$' ORDER BY id DESC LIMIT 1";
        $result = $conn->query($sql);

        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $lastGR = $row['gr_number'];

            // Parse format: <prefix>/<sequence> both numeric
            if (preg_match('/^(\d+)\/(\d+)$/', $lastGR, $matches)) {
                $prefix = (int) $matches[1];
                $sequence = (int) $matches[2];
                $padLen = strlen($matches[2]); // preserve padding width

                // Increment sequence number by 1 for the same branch prefix
                $nextSequence = $sequence + 1;
                $nextGR = sprintf('%d/%0' . $padLen . 'd', $prefix, $nextSequence);

                return $nextGR;
            }
        }

        // If none found for this branch or parse failed, start fresh with 1201 for this prefix
        return $branchPrefix . '/101'; // Start with 1201 to avoid conflict with old format

    } catch (Exception $e) {
        return $branchPrefix . '/101'; // Default if error
    }
}

/**
 * Check if GR number is unique
 */
function isGRNumberUnique($conn, $grNumber, $excludeBiltyId = null) {
    try {
        $grNumber = $conn->real_escape_string($grNumber);
        
        if ($excludeBiltyId) {
            $excludeBiltyId = intval($excludeBiltyId);
            $sql = "SELECT id FROM biltys WHERE gr_number = '$grNumber' AND id != $excludeBiltyId LIMIT 1";
        } else {
            $sql = "SELECT id FROM biltys WHERE gr_number = '$grNumber' LIMIT 1";
        }
        
        $result = $conn->query($sql);
        return $result && $result->num_rows === 0;
        
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Validate GR number - only check if it's not empty
 * User can enter any format they want (numbers, letters, symbols, etc.)
 */
function validateGRNumber($grNumber) {
    // Just check if not empty
    return !empty(trim($grNumber));
}

// Note: Do NOT close $conn here; the caller manages connection lifecycle.
?>
