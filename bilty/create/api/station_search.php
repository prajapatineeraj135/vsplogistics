<?php
/**
 * Station Search API
 * 
 * Searches for stations by name
 * Returns list of matching stations for destination selection
 * 
 * GET Parameters:
 * - ajax: 'station_search' (required)
 * - q: Search query string (required)
 * 
 * @author: Development Team
 * @version: 1.0
 */

// Start output buffering to catch any accidental output
ob_start();

include "../../../protect/db.php";
include "../includes/util.php";
include "../includes/config.php";

// Now start try-catch after includes
try {
    if (isset($_GET['ajax']) && $_GET['ajax'] === 'station_search') {
        // Get and validate input parameters
        $searchQuery = getGetParam('q', 'string', '');
        
        // Validate minimum input
        if (strlen($searchQuery) < MIN_SEARCH_CHARS) {
            ob_end_clean();
            sendJsonResponse(true, [], 'No search query');
        }
        
        // SQL query for station search
        $sql = "
            SELECT station_name
            FROM station
            WHERE station_name LIKE ?
            ORDER BY station_name ASC
            LIMIT ?
        ";
        
        // Prepare search parameters
        $likeQuery = "%{$searchQuery}%";
        $limit = MAX_SEARCH_RESULTS;
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("Query preparation failed: " . $conn->error);
        }
        
        // Bind parameters: string query, integer limit
        $stmt->bind_param("si", $likeQuery, $limit);
        $stmt->execute();
        
        $result = $stmt->get_result();
        $data = [];
        
        // Fetch all matching stations
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        
        $stmt->close();
        
        // Clear any buffered output and return clean JSON
        ob_end_clean();
        
        // Return search results
        sendJsonResponse(true, $data, count($data) . ' stations found');
    }
    
    // If not a valid AJAX request, return empty
    ob_end_clean();
    sendJsonResponse(true, [], 'Invalid request');
    
} catch (Exception $e) {
    ob_end_clean();
    error_log("Station search error: " . $e->getMessage());
    sendJsonResponse(false, [], "Search failed: " . $e->getMessage());
}
?>

