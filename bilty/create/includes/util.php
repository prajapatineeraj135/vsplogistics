<?php
/**
 * Bilty Create Module - PHP Utility Functions
 * 
 * Common utility functions used across the bilty creation module.
 * This reduces code duplication and centralizes reusable logic.
 * 
 * @author: Development Team
 * @version: 1.0
 * @last_updated: 2025-01-22
 */

/**
 * Generic search function for database queries
 * 
 * Executes a parameterized search query and returns results as JSON array
 * 
 * @param mysqli $conn - Database connection object
 * @param string $sql - SQL query with placeholders
 * @param array $types - Parameter types (e.g., 'is', 'ss')
 * @param array $params - Parameter values to bind
 * @return array - Array of search results
 */
function genericSearch($conn, $sql, $types, $params) {
    try {
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("Query preparation failed: " . $conn->error);
        }
        
        // Bind parameters dynamically
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        
        $result = $stmt->get_result();
        $data = [];
        
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        
        $stmt->close();
        return $data;
        
    } catch (Exception $e) {
        error_log("Search error: " . $e->getMessage());
        return [];
    }
}

/**
 * Send JSON response to client
 * 
 * For search API results (indexed arrays), returns array directly for compatibility with bilty.js
 * For save/update operations (associative arrays or objects), returns structured response
 * 
 * @param bool $success - Success status
 * @param mixed $data - Data to return (optional)
 * @param string $message - Status message (optional)
 * @return void - Outputs JSON and exits
 */
function sendJsonResponse($success, $data = null, $message = '') {
    header('Content-Type: application/json; charset=utf-8');
    
    // Check if data is an indexed array (search results) vs associative array (save response)
    if (is_array($data) && !empty($data) && array_keys($data) === range(0, count($data) - 1)) {
        // Indexed array - return directly for search APIs
        echo json_encode($data);
        exit;
    }
    
    // For save/update responses or error cases, return structured response
    $response = [
        'success' => $success,
        'message' => $message
    ];
    
    if ($data !== null && is_array($data)) {
        // Merge associative array into response
        $response = array_merge($response, $data);
    } elseif ($data !== null) {
        $response['data'] = $data;
    }
    
    echo json_encode($response);
    exit;
}

/**
 * Validate and sanitize string input
 * 
 * @param mysqli $conn - Database connection object
 * @param string $input - Input string to sanitize
 * @return string - Sanitized string
 */
function sanitizeString($conn, $input) {
    return $conn->real_escape_string(trim($input ?? ''));
}

/**
 * Validate and convert float value
 * 
 * @param mixed $input - Input value
 * @return int - Converted whole number value (0 if invalid)
 */
function sanitizeFloat($input) {
    $value = (int) round((float) ($input ?? 0));
    return $value >= 0 ? $value : 0;
}

/**
 * Validate and convert integer value
 * 
 * @param mixed $input - Input value
 * @return int - Converted integer value (0 if invalid)
 */
function sanitizeInt($input) {
    return intval($input ?? 0);
}

/**
 * Validate date format (YYYY-MM-DD HH:MM:SS)
 * 
 * @param string $date - Date string to validate
 * @return bool - True if valid format
 */
function isValidDateFormat($date) {
    return preg_match('/^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2}$/', $date) === 1;
}

/**
 * Get current date in database format
 * 
 * @return string - Current date in format YYYY-MM-DD HH:MM:SS
 */
function getCurrentDateTime() {
    return date('Y-m-d H:i:s');
}

/**
 * Validate and get POST parameter with default type conversion
 * 
 * @param string $key - POST key
 * @param string $type - Type: 'string', 'int', 'float'
 * @param mixed $default - Default value if not set
 * @return mixed - Sanitized and converted value
 */
function getPostParam($key, $type = 'string', $default = null) {
    if (!isset($_POST[$key])) {
        return $default;
    }
    
    $value = $_POST[$key];
    
    switch ($type) {
        case 'int':
            return sanitizeInt($value);
        case 'float':
            return sanitizeFloat($value);
        case 'string':
        default:
            return is_string($value) ? trim($value) : $default;
    }
}

/**
 * Validate and get GET parameter with type conversion
 * 
 * @param string $key - GET key
 * @param string $type - Type: 'string', 'int', 'float'
 * @param mixed $default - Default value if not set
 * @return mixed - Sanitized and converted value
 */
function getGetParam($key, $type = 'string', $default = null) {
    if (!isset($_GET[$key])) {
        return $default;
    }
    
    $value = $_GET[$key];
    
    switch ($type) {
        case 'int':
            return sanitizeInt($value);
        case 'float':
            return sanitizeFloat($value);
        case 'string':
        default:
            return is_string($value) ? trim($value) : $default;
    }
}

/**
 * Convert string to lowercase for database storage
 * Converts user input to lowercase before saving to database
 * 
 * @param string $input - Input string
 * @return string - Lowercase string
 */
function toLowercase($input) {
    return strtolower(trim($input ?? ''));
}

/**
 * Capitalize first letter of each word
 * Converts database data to capitalized display format
 * Example: "john smith" becomes "John Smith"
 * 
 * @param string $input - Input string
 * @return string - Capitalized string
 */
function capitalizeWords($input) {
    return ucwords(strtolower(trim($input ?? '')));
}

/**
 * Capitalize first letter only
 * Example: "john smith" becomes "John smith"
 * 
 * @param string $input - Input string
 * @return string - String with first letter capitalized
 */
function capitalizeFirst($input) {
    $str = strtolower(trim($input ?? ''));
    return ucfirst($str);
}

/**
 * Convert array values to lowercase for database storage
 * Used for batch processing of multiple fields
 * 
 * @param array $data - Array of data with string values
 * @param array $fields - Field names to convert (if empty, converts all)
 * @return array - Array with specified fields converted to lowercase
 */
function toLowercaseArray(&$data, $fields = []) {
    if (empty($fields)) {
        // Convert all string values to lowercase
        foreach ($data as &$value) {
            if (is_string($value)) {
                $value = toLowercase($value);
            }
        }
    } else {
        // Convert only specified fields
        foreach ($fields as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $data[$field] = toLowercase($data[$field]);
            }
        }
    }
    return $data;
}

/**
 * Convert array values to capitalized format for display
 * Used when retrieving and displaying data from database
 * 
 * @param array $data - Array of data with string values
 * @param array $fields - Field names to capitalize (if empty, capitalizes all)
 * @return array - Array with specified fields capitalized
 */
function capitalizeArray(&$data, $fields = []) {
    if (empty($fields)) {
        // Capitalize all string values
        foreach ($data as &$value) {
            if (is_string($value)) {
                $value = capitalizeWords($value);
            }
        }
    } else {
        // Capitalize only specified fields
        foreach ($fields as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $data[$field] = capitalizeWords($data[$field]);
            }
        }
    }
    return $data;
}

?>
