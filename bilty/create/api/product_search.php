<?php
/**
 * Product Search API
 * 
 * Searches for general products from the product catalog
 * Returns product list with rate and weight information
 * 
 * GET Parameters:
 * - ajax: 'product_search' (required)
 * - q: Search query string (optional)
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
ensureBiltyItemsRateBasisColumn($conn);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Now start try-catch after includes
try {
    if (isset($_GET['ajax']) && $_GET['ajax'] === 'product_search') {
        // Get search query
        $searchQuery = getGetParam('q', 'string', '');
        $station     = trim(getGetParam('station', 'string', ''));

        // Base SELECT — use station-specific rate when available
        $selectCols = $station !== ''
            ? "SELECT p.id, p.product_name as name,
                      COALESCE(psr.rate, p.rate) as rate,
                      COALESCE(psr.rate_basis, p.rate_basis) as rate_basis,
                      p.weight"
            : "SELECT p.id, p.product_name as name, p.rate, p.rate_basis, p.weight";

        $joinClause = $station !== ''
            ? "LEFT JOIN product_station_rates psr ON psr.product_id = p.id AND LOWER(psr.station_name) = LOWER(?)"
            : "";

        // If search query provided, search for matching products
        if (strlen($searchQuery) >= MIN_SEARCH_CHARS) {
            $sql = "$selectCols FROM products p $joinClause
                WHERE p.product_name LIKE ?
                ORDER BY p.product_name ASC
                LIMIT ?";

            $likeQuery = "%{$searchQuery}%";
            $limit     = MAX_SEARCH_RESULTS;

            $stmt = $conn->prepare($sql);
            if (!$stmt) throw new Exception("Query preparation failed: " . $conn->error);

            if ($station !== '') {
                $stmt->bind_param("ssi", $station, $likeQuery, $limit);
            } else {
                $stmt->bind_param("si", $likeQuery, $limit);
            }
            $stmt->execute();

            $result = $stmt->get_result();
            $catalogProducts = [];
            while ($row = $result->fetch_assoc()) {
                $catalogProducts[] = array_merge($row, ['source' => 'general', 'source_label' => 'General']);
            }
            $stmt->close();

            $products = mergeProductSearchResults(
                $catalogProducts,
                getSavedBiltyProducts($conn, $searchQuery, $station, MAX_SEARCH_RESULTS)
            );

            ob_end_clean();
            sendJsonResponse(true, $products, 'Products found');
        }

        // No search query — return limited products
        $sql = "$selectCols FROM products p $joinClause
            ORDER BY p.product_name ASC
            LIMIT ?";

        $limit = MAX_SEARCH_RESULTS;
        $stmt  = $conn->prepare($sql);
        if (!$stmt) throw new Exception("Query preparation failed: " . $conn->error);

        if ($station !== '') {
            $stmt->bind_param("si", $station, $limit);
        } else {
            $stmt->bind_param("i", $limit);
        }
        $stmt->execute();

        $result = $stmt->get_result();
        $catalogProducts = [];
        while ($row = $result->fetch_assoc()) {
            $catalogProducts[] = array_merge($row, ['source' => 'general', 'source_label' => 'General']);
        }
        $stmt->close();

        $products = mergeProductSearchResults(
            $catalogProducts,
            getSavedBiltyProducts($conn, '', $station, MAX_SEARCH_RESULTS)
        );

        ob_end_clean();
        sendJsonResponse(true, $products, 'All products');
    }
    
    // If not a valid AJAX request, return empty
    ob_end_clean();
    sendJsonResponse(true, [], 'Invalid request');
    
} catch (Exception $e) {
    ob_end_clean();
    error_log("Product search error: " . $e->getMessage());
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

function ensureBiltyItemsRateBasisColumn($conn) {
    $check = $conn->query("SHOW COLUMNS FROM bilty_items LIKE 'rate_basis'");
    if ($check && $check->num_rows > 0) {
        return;
    }

    if (!$conn->query("ALTER TABLE bilty_items ADD COLUMN rate_basis VARCHAR(20) NOT NULL DEFAULT 'Nag' AFTER weight")) {
        throw new Exception('Could not add bilty_items.rate_basis column: ' . $conn->error);
    }
}

function getSavedBiltyProducts($conn, $searchQuery, $station, $limit) {
    $companyId = (int) ($_SESSION['company_id'] ?? 0);
    $where = ["TRIM(bi2.item_name) <> ''"];
    $types = '';
    $params = [];

    if (strlen($searchQuery) >= MIN_SEARCH_CHARS) {
        $where[] = "bi2.item_name LIKE ?";
        $types .= 's';
        $params[] = "%{$searchQuery}%";
    }

    if ($station !== '') {
        $where[] = "LOWER(b2.to_station) = LOWER(?)";
        $types .= 's';
        $params[] = $station;
    }

    if ($companyId > 0) {
        $where[] = "b2.company_id = ?";
        $types .= 'i';
        $params[] = $companyId;
    }

    $whereSql = implode(' AND ', $where);
    $sql = "
        SELECT
            bi.item_name AS name,
            NULL AS rate,
            'Nag' AS rate_basis,
            NULL AS weight,
            bi.id
        FROM bilty_items bi
        INNER JOIN (
            SELECT
                LOWER(TRIM(bi2.item_name)) AS item_key,
                MAX(bi2.id) AS latest_id
            FROM bilty_items bi2
            INNER JOIN biltys b2 ON b2.id = bi2.bilty_id
            WHERE {$whereSql}
            GROUP BY item_key
        ) latest ON latest.latest_id = bi.id
        ORDER BY bi.id DESC
        LIMIT ?
    ";

    $types .= 'i';
    $params[] = $limit;

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Saved bilty product query preparation failed: " . $conn->error);
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();

    $result = $stmt->get_result();
    $products = [];
    while ($row = $result->fetch_assoc()) {
        $products[] = array_merge($row, [
            'source' => 'saved_bilty',
            'source_label' => 'Saved Bilty'
        ]);
    }
    $stmt->close();

    return $products;
}

function mergeProductSearchResults($catalogProducts, $savedBiltyProducts) {
    $merged = [];
    $catalogNames = [];
    $seenNames = [];

    foreach ($catalogProducts as $product) {
        $name = strtolower(trim((string) ($product['name'] ?? '')));
        if ($name !== '') {
            $catalogNames[$name] = true;
        }
    }

    foreach (array_merge($catalogProducts, $savedBiltyProducts) as $product) {
        $name = strtolower(trim((string) ($product['name'] ?? '')));
        if ($name === '') {
            continue;
        }

        $source = strtolower(trim((string) ($product['source_label'] ?? $product['source'] ?? '')));
        if ($source === 'saved bilty' && isset($catalogNames[$name])) {
            continue;
        }

        if (isset($seenNames[$name])) {
            continue;
        }

        $seenNames[$name] = true;
        $merged[] = $product;

        if (count($merged) >= MAX_SEARCH_RESULTS) {
            break;
        }
    }

    return array_values($merged);
}
?>
