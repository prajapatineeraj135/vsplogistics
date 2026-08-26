<?php
/**
 * Party Search API
 * 
 * Searches for parties in saved party table and in biltys data (for unsaved parties)
 * Returns matching parties with contact and billing information for saved parties,
 * and basic information for unsaved parties from biltys
 * 
 * GET Parameters:
 * - ajax: 'party_search' (required)
 * - type: Party type (Consignor/Consignee) (required)
 * - q: Search query string (required)
 * 
 * @author: Development Team
 * @version: 1.0
 */

// Start output buffering to catch any accidental HTML output
ob_start();

// Start session to access company_id
session_start();

include "../../../protect/db.php";
include "../includes/util.php";
include "../includes/config.php";

// Now start the try-catch after includes
try {
    if (isset($_GET['ajax']) && $_GET['ajax'] === 'party_search') {
        // Get and validate input parameters
        $searchQuery = getGetParam('q', 'string', '');
        $partyType = getGetParam('type', 'string', '');
        
        // Get company_id from session
        $company_id = $_SESSION['company_id'] ?? '102';
        
        // Validate minimum input
        if (strlen($searchQuery) < MIN_SEARCH_CHARS) {
            ob_end_clean();
            sendJsonResponse(true, [], 'No search query');
        }
        
        // Prepare parameters for search
        $likeQuery = "%{$searchQuery}%";
        $limit = MAX_SEARCH_RESULTS;
        
        $data = [];
        
        // First, search in party table
        $sql_party = "
            SELECT id, name, address1, contact, station, bilty_type
            FROM party
            WHERE party_type = ? AND name LIKE ?
            ORDER BY name ASC
            LIMIT ?
        ";
        
        $stmt_party = $conn->prepare($sql_party);
        if (!$stmt_party) {
            throw new Exception("Party query preparation failed: " . $conn->error);
        }
        
        $stmt_party->bind_param("ssi", $partyType, $likeQuery, $limit);
        $stmt_party->execute();
        $result_party = $stmt_party->get_result();
        
        while ($row = $result_party->fetch_assoc()) {
            $data[] = $row;
        }
        
        $stmt_party->close();
        
        // Then, search in biltys data for parties not in party table
        $biltyColumn = $partyType === 'Consignor' ? 'consignor_name' : 'consignee_name';
        $stationSelect = $partyType === 'Consignee' ? 'COALESCE(to_station, \'\')' : '\'\'';
        $sql_biltys = "
            SELECT $biltyColumn as name, '' as address1, '' as contact, $stationSelect as station, '' as bilty_type, NULL as id
            FROM biltys
            WHERE $biltyColumn LIKE ? 
            AND company_id = ?
            AND $biltyColumn NOT IN (
                SELECT name FROM party WHERE party_type = ?
            )
            GROUP BY $biltyColumn, station
            ORDER BY $biltyColumn ASC, station ASC
            LIMIT ?
        ";
        
        $stmt_biltys = $conn->prepare($sql_biltys);
        if (!$stmt_biltys) {
            throw new Exception("Biltys query preparation failed: " . $conn->error);
        }
        
        $stmt_biltys->bind_param("sisi", $likeQuery, $company_id, $partyType, $limit);
        $stmt_biltys->execute();
        $result_biltys = $stmt_biltys->get_result();
        
        while ($row = $result_biltys->fetch_assoc()) {
            $data[] = $row;
        }
        
        $stmt_biltys->close();
        
        // Clear any buffered output and return clean JSON
        ob_end_clean();
        
        // Return search results
        sendJsonResponse(true, $data, count($data) . ' parties found');
    }
    
    // If not a valid AJAX request, return empty
    ob_end_clean();
    sendJsonResponse(true, [], 'Invalid request');
    
} catch (Exception $e) {
    ob_end_clean();
    error_log("Party search error: " . $e->getMessage());
    sendJsonResponse(false, [], "Search failed: " . $e->getMessage());
}
?>
