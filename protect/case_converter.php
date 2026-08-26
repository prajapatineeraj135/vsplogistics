<?php
/**
 * Case Converter Utility
 * 
 * Provides functions to handle data case conversion throughout the application.
 * - toLowercase: Converts input to lowercase for database storage
 * - capitalizeWords: Converts output to capitalized display format
 * Data is stored in lowercase but displayed as capitalized text.
 * 
 * @author: Development Team
 * @version: 1.0
 * @last_updated: 2025-03-17
 */

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

/**
 * Capitalize all text fields in database result
 * Automatically capitalizes all string columns in a query result
 * 
 * @param mysqli_result $result - Database query result
 * @param array $excludeFields - Field names to exclude from capitalization
 * @return array - Array of rows with capitalized text fields
 */
function capitalizeResultRows($result, $excludeFields = []) {
    $rows = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            foreach ($row as $key => &$value) {
                if (is_string($value) && !in_array($key, $excludeFields)) {
                    $value = capitalizeWords($value);
                }
            }
            $rows[] = $row;
        }
    }
    return $rows;
}

/**
 * Create a display-friendly row from database data
 * Capitalizes specific fields while preserving others
 * 
 * @param array $row - Database row as associative array
 * @param array $textFields - Field names that contain text to capitalize
 * @return array - Row with specified text fields capitalized
 */
function displayRow($row, $textFields = []) {
    $displayRow = $row;
    
    if (empty($textFields)) {
        // Capitalize all string values
        foreach ($displayRow as &$value) {
            if (is_string($value)) {
                $value = capitalizeWords($value);
            }
        }
    } else {
        // Capitalize only specified fields
        foreach ($textFields as $field) {
            if (isset($displayRow[$field]) && is_string($displayRow[$field])) {
                $displayRow[$field] = capitalizeWords($displayRow[$field]);
            }
        }
    }
    
    return $displayRow;
}

?>
