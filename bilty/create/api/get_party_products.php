<?php
/**
 * Party Products Search API
 * 
 * Retrieves party-specific products linked to a party record
 * Allows customers to use their own rate cards when creating bilties
 * 
 * GET Parameters:
 * - ajax: 'party_products' (required)
 * - party_id: Party ID to fetch products for (required)
 * - q: Optional search query to filter products
 * 
 * @author: Development Team
 * @version: 1.0
 */

// Start output buffering to catch any accidental output
ob_start();

include "../../../protect/db.php";
include "../includes/util.php";
include "../includes/config.php";

ensureProductsRateBasisColumn($conn);

// Now start try-catch after includes
try {
    if (isset($_GET['ajax']) && $_GET['ajax'] === 'party_products') {
        // Get and validate parameters
        $partyId     = getGetParam('party_id', 'int', 0);
        $searchQuery = getGetParam('q', 'string', '');
        $station     = trim(getGetParam('station', 'string', ''));

        // Validate party ID
        if ($partyId <= 0) {
            ob_end_clean();
            sendJsonResponse(false, [], 'Invalid party ID');
        }

        // Join product_station_rates when a destination station is given
        // Priority: party_product rate -> station-specific rate on base product -> party rate
        $stationJoin = $station !== ''
            ? "LEFT JOIN product_station_rates psr ON psr.product_id = p.id AND LOWER(psr.station_name) = LOWER(?)"
            : "";

        $rateCol = $station !== ''
            ? "COALESCE(NULLIF(pp.rate,0), psr.rate, p.rate) as rate, COALESCE(NULLIF(pp.rate_basis,''), psr.rate_basis, p.rate_basis, 'Nag') as rate_basis"
            : "pp.rate, COALESCE(NULLIF(pp.rate_basis, ''), p.rate_basis, 'Nag') as rate_basis";

        if (strlen($searchQuery) >= MIN_SEARCH_CHARS) {
            $sql = "SELECT pp.product_name as name, $rateCol, pp.weight
                    FROM party_products pp
                    LEFT JOIN products p ON p.product_name = pp.product_name
                    $stationJoin
                    WHERE pp.party_id = ? AND pp.product_name LIKE ?
                    ORDER BY pp.product_name ASC
                    LIMIT ?";

            $likeQuery = "%{$searchQuery}%";
            $limit     = MAX_SEARCH_RESULTS;

            $stmt = $conn->prepare($sql);
            if (!$stmt) throw new Exception("Query preparation failed: " . $conn->error);

            if ($station !== '') {
                $stmt->bind_param("sisi", $station, $partyId, $likeQuery, $limit);
            } else {
                $stmt->bind_param("isi", $partyId, $likeQuery, $limit);
            }

        } else {
            $sql = "SELECT pp.product_name as name, $rateCol, pp.weight
                    FROM party_products pp
                    LEFT JOIN products p ON p.product_name = pp.product_name
                    $stationJoin
                    WHERE pp.party_id = ?
                    ORDER BY pp.product_name ASC
                    LIMIT ?";

            $limit = MAX_SEARCH_RESULTS;

            $stmt = $conn->prepare($sql);
            if (!$stmt) throw new Exception("Query preparation failed: " . $conn->error);

            if ($station !== '') {
                $stmt->bind_param("sii", $station, $partyId, $limit);
            } else {
                $stmt->bind_param("ii", $partyId, $limit);
            }
        }
        
        // Execute query
        $stmt->execute();
        $result = $stmt->get_result();
        
        $products = [];
        while ($row = $result->fetch_assoc()) {
            // Add source label for UI differentiation
            $products[] = array_merge($row, [
                'source' => 'party',
                'source_label' => 'Party'
            ]);
        }
        
        $stmt->close();
        
        // Clear any buffered output and return clean JSON
        ob_end_clean();
        
        // Return search results
        sendJsonResponse(true, $products, 'Party products found');
    }
    
    ob_end_clean();
    sendJsonResponse(false, [], 'Invalid request');
    
} catch (Exception $e) {
    ob_end_clean();
    error_log("Party products search error: " . $e->getMessage());
    sendJsonResponse(false, [], "Search failed: " . $e->getMessage());
}

function ensureProductsRateBasisColumn($conn) {
    $check = $conn->query("SHOW COLUMNS FROM products LIKE 'rate_basis'");
    if ($check && $check->num_rows > 0) {
        return;
    }

    if (!$conn->query("ALTER TABLE products ADD COLUMN rate_basis VARCHAR(20) NOT NULL DEFAULT 'Nag' AFTER weight")) {
        throw new Exception('Could not add products.rate_basis column: ' . $conn->error);
    }
}
?>

