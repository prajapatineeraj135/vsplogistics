<?php
/**
 * Bilty Save & Update Handler
 * 
 * Handles creation and updates of bilty records and associated items.
 * Uses database transactions to ensure data consistency.
 * 
 * POST Parameters:
 * - action: 'save' or 'update'
 * - All bilty header fields (consignor_name, consignee_name, etc.)
 * - items: JSON array of item objects
 * 
 * @author: Development Team
 * @version: 1.0
 * @last_updated: 2025-01-22
 */

// Clean output buffer and set JSON header
ob_start();
header('Content-Type: application/json; charset=utf-8');

// Include required files
include '../../../protect/db.php';
include '../includes/gr_handler.php';
include '../includes/util.php';
include '../includes/config.php';
include '../../../bill/includes/bill_sync.php';

// Clear any unwanted output
ob_end_clean();

ensureBiltyItemRateBasisColumn($conn);
ensureBiltyTransGrColumn($conn);

// Get the action (save or update)
$action = isset($_POST['action']) ? $_POST['action'] : '';

// Route to appropriate handler
if ($action === 'save') {
    saveBilty($conn);
} elseif ($action === 'update') {
    updateBilty($conn);
} else {
    sendJsonResponse(false, [], 'Invalid action. Use "save" or "update"');
}

/**
 * Collect and sanitize bilty data from POST request
 * 
 * Extracts all bilty fields from POST data with proper type conversion
 * and sanitization to prevent SQL injection
 * 
 * @param mysqli $conn - Database connection object
 * @return array - Sanitized bilty data
 */
function collectBiltyData($conn) {
    $grNumber = sanitizeString($conn, getPostParam('gr_number', 'string'));
    $transGr = sanitizeString($conn, getPostParam('trans_gr', 'string'));
    
    // Get company ID from session
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    $companyId = isset($_SESSION['company_id']) ? $_SESSION['company_id'] : '102';
    
    $data = [
        'consignor_id' => sanitizeInt(getPostParam('consignor_id', 'int')),
        'consignor_name' => toLowercase(sanitizeString($conn, getPostParam('consignor_name', 'string'))),
        'consignee_id' => sanitizeInt(getPostParam('consignee_id', 'int')),
        'consignee_name' => toLowercase(sanitizeString($conn, getPostParam('consignee_name', 'string'))),
        'to_station' => toLowercase(sanitizeString($conn, getPostParam('to_station', 'string'))),
        'gr_number' => toLowercase($grNumber),
        'trans_gr' => toLowercase($transGr),
        'company_id' => $companyId,
        'invoice_number' => toLowercase(sanitizeString($conn, getPostParam('invoice_number', 'string'))),
        'invoice_value' => sanitizeFloat(getPostParam('invoice_value', 'float')),
        'eway_bill' => toLowercase(sanitizeString($conn, getPostParam('eway_bill', 'string'))),
        'private_mark' => toLowercase(sanitizeString($conn, getPostParam('private_mark', 'string'))),
        'remark' => toLowercase(sanitizeString($conn, getPostParam('remark', 'string'))),
        'delivery_location' => toLowercase(sanitizeString($conn, getPostParam('delivery_location', 'string', DEFAULT_DELIVERY_LOCATION))),
        'freight' => sanitizeFloat(getPostParam('freight', 'float')),
        'hammali' => sanitizeFloat(getPostParam('hammali', 'float')),
        'p_freight' => sanitizeFloat(getPostParam('p_freight', 'float')),
        'brokerage' => sanitizeFloat(getPostParam('brokerage', 'float')),
        'dd_charge' => sanitizeFloat(getPostParam('dd_charge', 'float')),
        'gr_charge' => sanitizeFloat(getPostParam('gr_charge', 'float', DEFAULT_GR_CHARGE)),
        'total_charge' => sanitizeFloat(getPostParam('total_charge', 'float')),
        'payment_type' => toLowercase(sanitizeString($conn, getPostParam('payment_type', 'string', 'Topay'))),
        'bilty_date' => validateAndFormatDate(sanitizeString($conn, getPostParam('bilty_date', 'string'))),
        'total_qty' => sanitizeFloat(getPostParam('total_qty', 'float')),
        'total_weight' => sanitizeFloat(getPostParam('total_weight', 'float'))
    ];
    
    return $data;
}

/**
 * Validate and format bilty date
 * 
 * Ensures date is in proper format YYYY-MM-DD HH:MM:SS
 * Falls back to current date if invalid
 * 
 * @param string $date - Date string to validate
 * @return string - Properly formatted date
 */
function validateAndFormatDate($date) {
    if (empty($date)) {
        return getCurrentDateTime();
    }
    
    if (isValidDateFormat($date)) {
        return $date;
    }
    
    // Return current date if format invalid
    return getCurrentDateTime();
}

/**
 * Process bilty items from POST JSON
 * 
 * Extracts and sanitizes item data from JSON array
 * Filters out empty items
 * 
 * @param mysqli $conn - Database connection object
 * @return array - Array of sanitized items
 */
function processBiltyItems($conn) {
    $items = [];
    
    if (isset($_POST['items'])) {
        $rawItems = json_decode($_POST['items'], true);
        
        if (is_array($rawItems) && count($rawItems) > 0) {
            foreach ($rawItems as $index => $item) {
                $name = toLowercase(sanitizeString($conn, isset($item['name']) ? $item['name'] : ''));
                
                // Only add items with product name
                if (!empty($name)) {
                    $rateBasis = isset($item['rate_basis']) && $item['rate_basis'] === 'Weight' ? 'Weight' : 'Nag';
                    $items[] = [
                        'item_number' => $index + 1,
                        'quantity' => sanitizeInt($item['quantity'] ?? 0),
                        'name' => $name,
                        'rate' => sanitizeFloat($item['rate'] ?? 0),
                        'weight' => sanitizeFloat($item['weight'] ?? 0),
                        'rate_basis' => $rateBasis
                    ];
                }
            }
        }
    }
    
    return $items;
}

/**
 * Validate bilty data before saving
 * 
 * Checks required fields and business logic rules
 * 
 * @param array $data - Bilty data to validate
 * @param mysqli $conn - Database connection object
 * @param int|null $excludeBiltyId - Bilty ID to exclude from GR uniqueness check (for updates)
 * @return array - ['valid' => bool, 'message' => string]
 */
function validateBiltyData($data, $conn, $excludeBiltyId = null) {
    // Check required fields
    if (empty($data['consignor_name'])) {
        return ['valid' => false, 'message' => 'Consignor name is required'];
    }
    
    if (empty($data['consignee_name'])) {
        return ['valid' => false, 'message' => 'Consignee name is required'];
    }
    
    if (empty($data['to_station'])) {
        return ['valid' => false, 'message' => 'Destination station is required'];
    }

    if (empty($data['trans_gr'])) {
        return ['valid' => false, 'message' => 'Trans GR is required'];
    }
    
    // Validate GR number if provided
    if (!empty($data['gr_number'])) {
        if (!isGRNumberUnique($conn, $data['gr_number'], $excludeBiltyId)) {
            return ['valid' => false, 'message' => 'GR number already exists. Please use a different GR number'];
        }
    }
    
    return ['valid' => true, 'message' => 'All validations passed'];
}

/**
 * Insert bilty items into database
 * 
 * Inserts array of items for a specific bilty record
 * Part of transaction-based save/update operation
 * 
 * @param mysqli $conn - Database connection object
 * @param int $biltyId - Bilty ID to link items to
 * @param array $items - Array of item data
 * @throws Exception - On database error
 * @return void
 */
function insertBiltyItems($conn, $biltyId, $items) {
    if (empty($items)) {
        return;
    }
    
    foreach ($items as $item) {
        $sql = "
            INSERT INTO bilty_items (
                bilty_id, item_number, item_name, rate, weight, quantity, rate_basis
            ) VALUES (
                {$biltyId}, {$item['item_number']}, '{$item['name']}', 
                {$item['rate']}, {$item['weight']}, {$item['quantity']}, '{$item['rate_basis']}'
            )
        ";
        
        if (!$conn->query($sql)) {
            throw new Exception('Error inserting bilty item: ' . $conn->error);
        }
    }
}

function ensureBiltyItemRateBasisColumn($conn) {
    $check = $conn->query("SHOW COLUMNS FROM bilty_items LIKE 'rate_basis'");
    if ($check && $check->num_rows > 0) {
        return;
    }

    if (!$conn->query("ALTER TABLE bilty_items ADD COLUMN rate_basis VARCHAR(20) NOT NULL DEFAULT 'Nag' AFTER weight")) {
        throw new Exception('Could not add bilty_items.rate_basis column: ' . $conn->error);
    }
}

function ensureBiltyTransGrColumn($conn) {
    $check = $conn->query("SHOW COLUMNS FROM biltys LIKE 'trans_gr'");
    if ($check && $check->num_rows > 0) {
        return;
    }

    if (!$conn->query("ALTER TABLE biltys ADD COLUMN trans_gr VARCHAR(100) NOT NULL DEFAULT '' AFTER gr_number")) {
        throw new Exception('Could not add biltys.trans_gr column: ' . $conn->error);
    }
}

/**
 * Save a new bilty record to the database
 * 
 * Creates new bilty header and associated items using database transaction
 * Ensures data consistency in case of errors
 * 
 * @param mysqli $conn - Database connection object
 * @return void - Outputs JSON response and exits
 */
function saveBilty($conn) {
    try {
        ensureBillsSchema($conn);

        // Collect and validate bilty data
        $data = collectBiltyData($conn);
        $items = processBiltyItems($conn);
        
        // Validate required fields and business rules
        $validation = validateBiltyData($data, $conn);
        if (!$validation['valid']) {
            sendJsonResponse(false, [], $validation['message']);
        }
        
        // Start database transaction
        $conn->begin_transaction();
        
        // Build INSERT query for bilty header
        $sql = "
            INSERT INTO biltys (
                consignor_id, consignor_name, consignee_id, consignee_name, to_station,
                gr_number, trans_gr, company_id, invoice_number, invoice_value, eway_bill, private_mark, remark,
                delivery_location, freight, hammali, p_freight, brokerage, dd_charge,
                gr_charge, total_charge, payment_type, bilty_date, total_qty, total_weight,
                created_at, status
            ) VALUES (
                {$data['consignor_id']}, '{$data['consignor_name']}', {$data['consignee_id']},
                '{$data['consignee_name']}', '{$data['to_station']}', '{$data['gr_number']}', '{$data['trans_gr']}', '{$data['company_id']}',
                '{$data['invoice_number']}', {$data['invoice_value']}, '{$data['eway_bill']}',
                '{$data['private_mark']}', '{$data['remark']}', '{$data['delivery_location']}',
                {$data['freight']}, {$data['hammali']}, {$data['p_freight']},
                {$data['brokerage']}, {$data['dd_charge']}, {$data['gr_charge']},
                {$data['total_charge']}, '{$data['payment_type']}', '{$data['bilty_date']}',
                {$data['total_qty']}, {$data['total_weight']},
                NOW(), 'Booked'
            )
        ";
        
        // Execute bilty header insert
        if (!$conn->query($sql)) {
            throw new Exception('Error inserting bilty: ' . $conn->error);
        }
        
        $biltyId = $conn->insert_id;
        
        // Insert associated items
        insertBiltyItems($conn, $biltyId, $items);
        
        // Commit transaction
        $conn->commit();

        // Auto-sync monthly TBB bill for this party/month.
        if (strtoupper(trim((string) $data['payment_type'])) === 'TBB') {
            $monthKey = date('Y-m', strtotime($data['bilty_date']));
            syncAutoBillForPartyMonth(
                $conn,
                (int) $data['company_id'],
                $monthKey,
                (int) $data['consignor_id'],
                (string) $data['consignor_name']
            );
        }
        
        // Return success response with both DB ID and GR reference
        sendJsonResponse(true, [
            'id' => $data['gr_number'],
            'gr_number' => $data['gr_number'],
            'bilty_id' => $biltyId
        ], 'Bilty saved successfully');
        
        
    } catch (Exception $e) {
        // Rollback on any error
        $conn->rollback();
        error_log("Bilty save error: " . $e->getMessage());
        sendJsonResponse(false, [], 'Error: ' . $e->getMessage());
    }
}

/**
 * Update an existing bilty record
 * 
 * Modifies bilty header and replaces associated items using transaction
 * Maintains data consistency through rollback on errors
 * 
 * @param mysqli $conn - Database connection object
 * @return void - Outputs JSON response and exits
 */
function updateBilty($conn) {
    try {
        ensureBillsSchema($conn);

        // Get bilty ID from POST
        $biltyId = sanitizeInt(getPostParam('bilty_id', 'int'));
        
        if ($biltyId <= 0) {
            sendJsonResponse(false, [], 'Bilty ID is required for update');
        }

        // Snapshot old values so previous month/party bill can be resynced if changed.
        $oldRow = null;
        $oldStmt = $conn->prepare('SELECT company_id, consignor_id, consignor_name, payment_type, bilty_date FROM biltys WHERE id = ? LIMIT 1');
        $oldStmt->bind_param('i', $biltyId);
        $oldStmt->execute();
        $oldRow = $oldStmt->get_result()->fetch_assoc();
        $oldStmt->close();
        
        // Collect and validate bilty data
        $data = collectBiltyData($conn);
        $items = processBiltyItems($conn);
        
        // Validate required fields and business rules (exclude current bilty from GR check)
        $validation = validateBiltyData($data, $conn, $biltyId);
        if (!$validation['valid']) {
            sendJsonResponse(false, [], $validation['message']);
        }
        
        // Start database transaction
        $conn->begin_transaction();
        
        // Build UPDATE query for bilty header
        $sql = "
            UPDATE biltys SET
                consignor_id = {$data['consignor_id']},
                consignor_name = '{$data['consignor_name']}',
                consignee_id = {$data['consignee_id']},
                consignee_name = '{$data['consignee_name']}',
                to_station = '{$data['to_station']}',
                gr_number = '{$data['gr_number']}',
                trans_gr = '{$data['trans_gr']}',
                company_id = '{$data['company_id']}',
                invoice_number = '{$data['invoice_number']}',
                invoice_value = {$data['invoice_value']},
                eway_bill = '{$data['eway_bill']}',
                private_mark = '{$data['private_mark']}',
                remark = '{$data['remark']}',
                delivery_location = '{$data['delivery_location']}',
                freight = {$data['freight']},
                hammali = {$data['hammali']},
                p_freight = {$data['p_freight']},
                brokerage = {$data['brokerage']},
                dd_charge = {$data['dd_charge']},
                gr_charge = {$data['gr_charge']},
                total_charge = {$data['total_charge']},
                payment_type = '{$data['payment_type']}',
                bilty_date = '{$data['bilty_date']}',
                total_qty = {$data['total_qty']},
                total_weight = {$data['total_weight']},
                updated_at = NOW()
            WHERE id = {$biltyId}
        ";
        
        // Execute bilty header update
        if (!$conn->query($sql)) {
            throw new Exception('Error updating bilty: ' . $conn->error);
        }
        
        // Delete existing items
        $deleteSql = "DELETE FROM bilty_items WHERE bilty_id = {$biltyId}";
        if (!$conn->query($deleteSql)) {
            throw new Exception('Error deleting old items: ' . $conn->error);
        }
        
        // Insert new items
        insertBiltyItems($conn, $biltyId, $items);
        
        // Commit transaction
        $conn->commit();

        // Resync old and new TBB bill contexts.
        if (!empty($oldRow) && strtoupper(trim((string) ($oldRow['payment_type'] ?? ''))) === 'TBB') {
            $oldMonth = date('Y-m', strtotime((string) $oldRow['bilty_date']));
            syncAutoBillForPartyMonth(
                $conn,
                (int) ($oldRow['company_id'] ?? 0),
                $oldMonth,
                (int) ($oldRow['consignor_id'] ?? 0),
                (string) ($oldRow['consignor_name'] ?? '')
            );
        }

        if (strtoupper(trim((string) $data['payment_type'])) === 'TBB') {
            $newMonth = date('Y-m', strtotime($data['bilty_date']));
            syncAutoBillForPartyMonth(
                $conn,
                (int) $data['company_id'],
                $newMonth,
                (int) $data['consignor_id'],
                (string) $data['consignor_name']
            );
        }
        
        // Return success response
        sendJsonResponse(true, [
            'id' => $data['gr_number'],
            'gr_number' => $data['gr_number'],
            'bilty_id' => $biltyId
        ], 'Bilty updated successfully');
        
    } catch (Exception $e) {
        // Rollback on any error
        $conn->rollback();
        error_log("Bilty update error: " . $e->getMessage());
        sendJsonResponse(false, [], 'Error: ' . $e->getMessage());
    }
}

// Close database connection
$conn->close();
?>
