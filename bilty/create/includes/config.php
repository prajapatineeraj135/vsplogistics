<?php
/**
 * Bilty Create Module - Configuration & Constants
 * 
 * This file contains all constants and configuration settings for the bilty creation system.
 * Centralized configuration reduces duplication and makes maintenance easier.
 * 
 * @author: Development Team
 * @version: 1.0
 * @last_updated: 2025-01-22
 */

// ========================
// SYSTEM CONSTANTS
// ========================

/** Maximum number of items allowed in a bilty */
define('MAX_BILTY_ITEMS', 7);

/** Default company ID for testing */
define('DEFAULT_COMPANY_ID', 102);

/** Date format for display */
define('DATE_FORMAT_DISPLAY', 'DD-MM-YYYY');

/** Date format for database */
define('DATE_FORMAT_DB', 'Y-m-d H:i:s');

/** Default delivery location option */
define('DEFAULT_DELIVERY_LOCATION', 'G');

/** Default GR number format prefix */
define('GR_DEFAULT_PREFIX', '102');

// ========================
// DROPDOWN LIMITS
// ========================

/** Maximum search results to display in dropdown */
define('MAX_SEARCH_RESULTS', 20);

/** Minimum characters to trigger search */
define('MIN_SEARCH_CHARS', 1);

// ========================
// PAYMENT TYPES
// ========================

/** Array of valid payment types */
$PAYMENT_TYPES = [
    'Topay' => 'To Pay',
    'Cash' => 'Cash',
    'TBB' => 'TBB'
];

// ========================
// DELIVERY LOCATIONS
// ========================

/** Array of delivery location options */
$DELIVERY_LOCATIONS = [
    'G' => 'New Mahalaxmi Branch Office (Godown)',
    'D' => 'Door Delivery'
];

// ========================
// CHARGE TYPES
// ========================

/** Array of charge types for bilty */
$CHARGE_TYPES = [
    'freight' => ['label' => 'Freight', 'required' => true],
    'hammali' => ['label' => 'Hammali', 'required' => false],
    'p_freight' => ['label' => 'P. Freight', 'required' => false],
    'brokerage' => ['label' => 'Brokerage', 'required' => false],
    'dd_charge' => ['label' => 'DD Charge', 'required' => false],
    'gr_charge' => ['label' => 'GR Charge', 'required' => false]
];

/** Default GR Charge value */
define('DEFAULT_GR_CHARGE', 10);

?>
