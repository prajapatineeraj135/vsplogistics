<?php
/**
 * Structure-only Database Installer / Updater
 *
 * Usage:
 * 1. Upload install.php and success.php to your server.
 * 2. Edit DB_HOST, DB_USER, DB_PASS, DB_NAME below.
 * 3. Open install.php in your browser.
 *
 * This file creates ONLY database structure:
 * - Creates database if missing.
 * - Creates missing tables.
 * - Adds missing columns to existing tables.
 * - Applies keys/indexes/auto-increment/foreign keys when possible.
 * - Skips existing tables, columns, and duplicate keys.
 * - Does NOT insert any data.
 */

session_start();

if (file_exists(__DIR__ . '/../protect/config.php')) {
    require_once __DIR__ . '/../protect/config.php';
}

$isLocalDatabase = defined('BASE_URL') && strpos(BASE_URL, 'localhost') !== false;
define('DB_HOST', 'localhost');
define('DB_USER', $isLocalDatabase ? 'root' : 'u448438938_nmtc');
define('DB_PASS', $isLocalDatabase ? '' : 'Nmtc@135@135');
define('DB_NAME', $isLocalDatabase ? 'nmtc135' : 'u448438938_nmtc');
define('DB_CHARSET', 'utf8mb4');

$schemaSql = <<<'NMTCSQL'
CREATE TABLE `agent` (
  `id` int(11) NOT NULL,
  `agent_name` varchar(255) NOT NULL,
  `contact` varchar(50) NOT NULL,
  `station` varchar(255) NOT NULL,
  `address` varchar(500) DEFAULT NULL,
  `commission_percent` decimal(5,2) DEFAULT 0.00,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `bills` (
  `id` int(11) NOT NULL,
  `company_id` int(11) DEFAULT NULL,
  `bill_number` varchar(80) NOT NULL,
  `bill_date` date NOT NULL,
  `party_id` int(11) DEFAULT NULL,
  `party_name` varchar(255) NOT NULL,
  `bill_month` char(7) DEFAULT NULL,
  `bill_type` varchar(30) DEFAULT 'AUTO_TBB',
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_bilty` int(11) NOT NULL DEFAULT 0,
  `total_nag` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` varchar(50) NOT NULL DEFAULT 'Pending',
  `remarks` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `biltys` (
  `id` int(11) NOT NULL,
  `gr_number` varchar(50) DEFAULT NULL,
  `company_id` int(11) DEFAULT NULL,
  `bilty_date` datetime DEFAULT current_timestamp(),
  `consignor_id` int(11) DEFAULT 0,
  `consignor_name` varchar(255) DEFAULT '',
  `consignee_id` int(11) DEFAULT 0,
  `consignee_name` varchar(255) DEFAULT '',
  `to_station` varchar(255) NOT NULL,
  `total_qty` decimal(10,2) DEFAULT 0.00,
  `total_weight` decimal(10,2) DEFAULT 0.00,
  `freight` decimal(10,2) DEFAULT 0.00,
  `p_freight` decimal(10,2) DEFAULT 0.00,
  `hammali` decimal(10,2) DEFAULT 0.00,
  `brokerage` decimal(10,2) DEFAULT 0.00,
  `dd_charge` decimal(10,2) DEFAULT 0.00,
  `gr_charge` decimal(10,2) DEFAULT 10.00,
  `total_charge` decimal(10,2) DEFAULT 0.00,
  `invoice_number` varchar(50) DEFAULT NULL,
  `invoice_value` decimal(10,2) DEFAULT 0.00,
  `eway_bill` varchar(50) DEFAULT NULL,
  `private_mark` varchar(255) DEFAULT NULL,
  `remark` text DEFAULT NULL,
  `delivery_location` varchar(10) DEFAULT 'G',
  `payment_type` varchar(20) DEFAULT 'Topay',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `status` enum('Booked','Dispatch','Deliver','Cancel','Trash') DEFAULT 'Booked',
  `challan_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `bilty_items` (
  `id` int(11) NOT NULL,
  `bilty_id` int(11) NOT NULL,
  `item_number` int(11) DEFAULT NULL,
  `item_name` varchar(255) NOT NULL,
  `rate` decimal(10,2) DEFAULT 0.00,
  `weight` decimal(10,2) DEFAULT 0.00,
  `rate_basis` varchar(20) NOT NULL DEFAULT 'Nag',
  `quantity` int(11) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `bilty_tbb` (
  `id` int(10) NOT NULL,
  `gr_number` int(10) NOT NULL,
  `pm` int(11) NOT NULL,
  `date` date NOT NULL,
  `consignor` varchar(100) NOT NULL,
  `consignee` varchar(100) NOT NULL,
  `station` varchar(10) NOT NULL,
  `nag` int(10) NOT NULL,
  `type` varchar(5) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `challans` (
  `id` int(11) NOT NULL,
  `company_id` int(11) DEFAULT NULL,
  `challan_no` varchar(50) NOT NULL,
  `challan_date` date NOT NULL,
  `challan_station` varchar(255) NOT NULL,
  `vehicle_no` varchar(50) DEFAULT NULL,
  `driver_name` varchar(255) DEFAULT NULL,
  `driver_contact` varchar(50) DEFAULT NULL,
  `owner_name` varchar(255) DEFAULT NULL,
  `contact` varchar(100) DEFAULT NULL,
  `agent_name` varchar(255) DEFAULT NULL,
  `agent_contact` varchar(50) DEFAULT NULL,
  `paid_total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `freight_total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `recovery_total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `cutting_total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `commission_total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `final_total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `company` (
  `id` int(11) NOT NULL,
  `username` varchar(20) DEFAULT NULL,
  `pass` varchar(255) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `company_name` varchar(200) DEFAULT NULL,
  `legal_name` varchar(200) DEFAULT NULL,
  `business_type` varchar(100) DEFAULT NULL,
  `gst_no` varchar(50) DEFAULT NULL,
  `owner_name` varchar(150) DEFAULT NULL,
  `owner_phone` varchar(20) DEFAULT NULL,
  `manager_name` varchar(150) DEFAULT NULL,
  `manager_phone` varchar(20) DEFAULT NULL,
  `phone1` varchar(20) DEFAULT NULL,
  `phone2` varchar(20) DEFAULT NULL,
  `whatsapp` varchar(20) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `bank_name` varchar(255) DEFAULT NULL,
  `bank_account_name` varchar(255) DEFAULT NULL,
  `bank_account_number` varchar(100) DEFAULT NULL,
  `bank_ifsc_code` varchar(50) DEFAULT NULL,
  `upi_id` varchar(150) DEFAULT NULL,
  `upi_qr_path` varchar(500) DEFAULT NULL,
  `branch` varchar(100) DEFAULT NULL,
  `address1` varchar(200) DEFAULT NULL,
  `address2` varchar(200) DEFAULT NULL,
  `address3` varchar(200) DEFAULT NULL,
  `pincode` varchar(10) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `state` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `device_sessions` (
  `id` int(11) NOT NULL,
  `device_id` varchar(255) NOT NULL,
  `user_type` enum('admin','company') NOT NULL,
  `user_id` varchar(255) NOT NULL,
  `login_time` timestamp NOT NULL DEFAULT current_timestamp(),
  `logout_time` timestamp NULL DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `party` (
  `id` int(11) NOT NULL,
  `party_type` varchar(20) DEFAULT NULL,
  `bilty_type` varchar(20) NOT NULL,
  `name` varchar(150) DEFAULT NULL,
  `contact` varchar(15) DEFAULT NULL,
  `station` varchar(100) DEFAULT NULL,
  `address1` varchar(255) DEFAULT NULL,
  `address2` varchar(255) DEFAULT NULL,
  `pincode` varchar(10) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `state` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `party_products` (
  `id` int(11) NOT NULL,
  `party_id` int(11) DEFAULT NULL,
  `product_name` varchar(150) DEFAULT NULL,
  `product_type` varchar(100) DEFAULT NULL,
  `product_category` varchar(100) DEFAULT NULL,
  `rate` decimal(10,2) DEFAULT NULL,
  `weight` decimal(10,2) DEFAULT NULL,
  `rate_basis` varchar(20) NOT NULL DEFAULT 'Nag'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `products` (
  `id` int(11) NOT NULL,
  `product_name` varchar(150) DEFAULT NULL,
  `product_type` varchar(100) DEFAULT NULL,
  `product_category` varchar(100) DEFAULT NULL,
  `rate` decimal(10,2) DEFAULT NULL,
  `weight` decimal(10,2) DEFAULT NULL,
  `rate_basis` varchar(20) NOT NULL DEFAULT 'Nag'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `product_station_rates` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `station_name` varchar(100) NOT NULL,
  `rate` decimal(10,2) NOT NULL DEFAULT 0.00,
  `rate_basis` varchar(20) NOT NULL DEFAULT 'Nag'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `station` (
  `id` int(11) NOT NULL,
  `station_name` varchar(100) NOT NULL,
  `city` varchar(100) NOT NULL,
  `state` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `vehicles` (
  `id` int(11) NOT NULL,
  `company_id` varchar(20) NOT NULL,
  `vehicle_number` varchar(20) NOT NULL,
  `driver_name` varchar(100) NOT NULL,
  `owner_name` varchar(100) NOT NULL,
  `mobile` varchar(15) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `ledger_payments` (
  `id` int(11) NOT NULL,
  `company_id` int(11) DEFAULT NULL,
  `account_type` varchar(30) NOT NULL,
  `account_id` int(11) DEFAULT NULL,
  `account_name` varchar(255) NOT NULL,
  `payment_date` date NOT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `transaction_type` enum('CR','DR') NOT NULL DEFAULT 'CR',
  `challan_no` varchar(100) DEFAULT NULL,
  `voucher_no` varchar(100) DEFAULT NULL,
  `mode` varchar(50) DEFAULT NULL,
  `reference_no` varchar(100) DEFAULT NULL,
  `remarks` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `inword_challans` (
  `id` int(11) NOT NULL,
  `company_id` int(11) DEFAULT NULL,
  `challan_no` varchar(50) NOT NULL DEFAULT '',
  `challan_date` date NOT NULL,
  `other_transporter` varchar(255) NOT NULL DEFAULT '',
  `dr_total` int(11) DEFAULT 0,
  `cr_total` int(11) DEFAULT 0,
  `cr_rate` int(11) DEFAULT 0,
  `cr_per` varchar(20) DEFAULT '100kg',
  `net_amount` int(11) DEFAULT 0,
  `remark` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `inword_biltys` (
  `id` int(11) NOT NULL,
  `company_id` int(11) DEFAULT NULL,
  `inword_gr` varchar(50) DEFAULT NULL,
  `other_transporter` varchar(255) NOT NULL DEFAULT '',
  `other_transporter_id` int(11) DEFAULT NULL,
  `other_gr_no` varchar(50) DEFAULT NULL,
  `challan_no` varchar(50) DEFAULT NULL,
  `consignor_name` varchar(255) DEFAULT NULL,
  `consignor_id` int(11) DEFAULT NULL,
  `consignee_name` varchar(255) DEFAULT NULL,
  `consignee_id` int(11) DEFAULT NULL,
  `to_station` varchar(255) NOT NULL DEFAULT '',
  `bilty_date` datetime DEFAULT current_timestamp(),
  `total_qty` int(11) DEFAULT 0,
  `total_weight` int(11) DEFAULT 0,
  `payment_type` varchar(20) DEFAULT 'Topay',
  `dr_amount` int(11) DEFAULT 0,
  `cr_rate` int(11) DEFAULT 0,
  `cr_per` varchar(20) DEFAULT '100kg',
  `cr_amount` int(11) DEFAULT 0,
  `freight` int(11) DEFAULT 0,
  `hammali` int(11) DEFAULT 0,
  `dd_charge` int(11) DEFAULT 0,
  `gr_charge` int(11) DEFAULT 0,
  `total_charge` int(11) DEFAULT 0,
  `invoice_number` varchar(100) DEFAULT NULL,
  `invoice_value` int(11) DEFAULT 0,
  `eway_bill` varchar(100) DEFAULT NULL,
  `private_mark` varchar(255) DEFAULT NULL,
  `remark` text DEFAULT NULL,
  `delivery_location` varchar(10) DEFAULT 'G',
  `inword_challan_id` int(11) DEFAULT NULL,
  `challan_id` int(11) DEFAULT NULL,
  `status` enum('Booked','Dispatch','Received','Dispatched','Delivered','Trash') DEFAULT 'Booked',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `inword_bilty_items` (
  `id` int(11) NOT NULL,
  `inword_bilty_id` int(11) NOT NULL,
  `quantity` int(11) DEFAULT 0,
  `product_name` varchar(255) DEFAULT NULL,
  `rate` int(11) DEFAULT 0,
  `weight` int(11) DEFAULT 0,
  `rate_basis` varchar(20) DEFAULT 'Nag',
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `agent`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_agent_name` (`agent_name`),
  ADD KEY `idx_agent_station` (`station`);

ALTER TABLE `bills`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_bill_number` (`bill_number`),
  ADD KEY `idx_company_id` (`company_id`),
  ADD KEY `idx_bill_month` (`bill_month`),
  ADD KEY `idx_party_id` (`party_id`),
  ADD KEY `idx_bill_type` (`bill_type`);

ALTER TABLE `biltys`
  ADD PRIMARY KEY (`id`),
  ADD KEY `consignor_id` (`consignor_id`),
  ADD KEY `consignee_id` (`consignee_id`),
  ADD KEY `consignor_name_idx` (`consignor_name`),
  ADD KEY `consignee_name_idx` (`consignee_name`),
  ADD KEY `idx_company_id` (`company_id`),
  ADD KEY `idx_challan_id` (`challan_id`);

ALTER TABLE `bilty_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `bilty_id` (`bilty_id`);

ALTER TABLE `bilty_tbb`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `gr_number` (`gr_number`);

ALTER TABLE `challans`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_company_challan_no` (`company_id`,`challan_no`),
  ADD KEY `idx_challan_no` (`challan_no`),
  ADD KEY `idx_challan_station` (`challan_station`);

ALTER TABLE `company`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

ALTER TABLE `device_sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_device_id` (`device_id`),
  ADD KEY `idx_active_session` (`device_id`,`logout_time`),
  ADD KEY `idx_login_time` (`login_time`);

ALTER TABLE `party`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `party_products`
  ADD PRIMARY KEY (`id`),
  ADD KEY `party_id` (`party_id`);

ALTER TABLE `products`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `product_station_rates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_product_station` (`product_id`,`station_name`),
  ADD KEY `idx_psr_product_id` (`product_id`),
  ADD KEY `idx_psr_station_name` (`station_name`);

ALTER TABLE `station`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `vehicles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_vehicle_company` (`company_id`,`vehicle_number`);

ALTER TABLE `ledger_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_company_id` (`company_id`),
  ADD KEY `idx_account_type` (`account_type`),
  ADD KEY `idx_account_name` (`account_name`),
  ADD KEY `idx_payment_date` (`payment_date`),
  ADD KEY `idx_ledger_challan_no` (`challan_no`),
  ADD KEY `idx_ledger_voucher_no` (`voucher_no`);

ALTER TABLE `inword_challans`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_inword_challan_company_id` (`company_id`),
  ADD KEY `idx_inword_challan_no` (`challan_no`);

ALTER TABLE `inword_biltys`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_inword_bilty_company_id` (`company_id`),
  ADD KEY `idx_inword_gr` (`inword_gr`),
  ADD KEY `idx_inword_bilty_challan_no` (`challan_no`),
  ADD KEY `idx_inword_challan_id` (`inword_challan_id`),
  ADD KEY `idx_inword_dispatch_challan_id` (`challan_id`);

ALTER TABLE `inword_bilty_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_inword_bilty_id` (`inword_bilty_id`);

ALTER TABLE `agent`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

ALTER TABLE `bills`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

ALTER TABLE `biltys`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=435;

ALTER TABLE `bilty_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=462;

ALTER TABLE `bilty_tbb`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=351;

ALTER TABLE `challans`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=36;

ALTER TABLE `device_sessions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=114;

ALTER TABLE `party`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=280;

ALTER TABLE `party_products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=55;

ALTER TABLE `products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=168;

ALTER TABLE `product_station_rates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

ALTER TABLE `station`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

ALTER TABLE `vehicles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

ALTER TABLE `ledger_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `inword_challans`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `inword_biltys`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `inword_bilty_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `bilty_items`
  ADD CONSTRAINT `bilty_items_ibfk_1` FOREIGN KEY (`bilty_id`) REFERENCES `biltys` (`id`) ON DELETE CASCADE;

ALTER TABLE `challans`
  ADD CONSTRAINT `fk_challans_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`id`) ON DELETE SET NULL;

ALTER TABLE `party_products`
  ADD CONSTRAINT `party_products_ibfk_1` FOREIGN KEY (`party_id`) REFERENCES `party` (`id`) ON DELETE CASCADE;

ALTER TABLE `inword_bilty_items`
  ADD CONSTRAINT `fk_inword_items_header` FOREIGN KEY (`inword_bilty_id`) REFERENCES `inword_biltys` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `inword_biltys`
  ADD CONSTRAINT `fk_inword_header_challan` FOREIGN KEY (`inword_challan_id`) REFERENCES `inword_challans` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;
NMTCSQL;

$logs = [];

function addLog(string $type, string $message): void
{
    global $logs;
    $logs[] = [
        'time' => date('Y-m-d H:i:s'),
        'type' => $type,
        'message' => $message,
    ];
}

function dbQuote(mysqli $db, string $value): string
{
    return "'" . $db->real_escape_string($value) . "'";
}

function runQuery(mysqli $db, string $sql, string $successMessage, string $skipMessage = ''): bool
{
    try {
        if ($db->query($sql)) {
            addLog('success', $successMessage);
            return true;
        }
        addLog('skipped', ($skipMessage ?: $successMessage) . ' — ' . ($db->error ?: 'Unknown SQL error'));
        return false;
    } catch (Throwable $e) {
        addLog('skipped', ($skipMessage ?: $successMessage) . ' — ' . $e->getMessage());
        return false;
    }
}

function tableExists(mysqli $db, string $table): bool
{
    $sql = "SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = " . dbQuote($db, DB_NAME) . " AND TABLE_NAME = " . dbQuote($db, $table);
    $res = $db->query($sql);
    return $res && ((int)$res->fetch_assoc()['c'] > 0);
}

function columnExists(mysqli $db, string $table, string $column): bool
{
    $sql = "SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = " . dbQuote($db, DB_NAME) . " AND TABLE_NAME = " . dbQuote($db, $table) . " AND COLUMN_NAME = " . dbQuote($db, $column);
    $res = $db->query($sql);
    return $res && ((int)$res->fetch_assoc()['c'] > 0);
}

function splitSqlStatements(string $sql): array
{
    $statements = [];
    $buffer = '';
    $len = strlen($sql);
    $inSingle = false;
    $inDouble = false;
    $escape = false;

    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        $buffer .= $ch;

        if ($escape) {
            $escape = false;
            continue;
        }
        if ($ch === '\\') {
            $escape = true;
            continue;
        }
        if ($ch === "'" && !$inDouble) {
            $inSingle = !$inSingle;
        } elseif ($ch === '"' && !$inSingle) {
            $inDouble = !$inDouble;
        } elseif ($ch === ';' && !$inSingle && !$inDouble) {
            $stmt = trim($buffer);
            if ($stmt !== '') {
                $statements[] = $stmt;
            }
            $buffer = '';
        }
    }

    $tail = trim($buffer);
    if ($tail !== '') {
        $statements[] = $tail;
    }
    return $statements;
}

function extractCreateTables(string $sql): array
{
    preg_match_all('/CREATE\s+TABLE\s+`([^`]+)`\s*\((.*?)\)\s*ENGINE\s*=\s*[^;]+;/is', $sql, $matches, PREG_SET_ORDER);
    return $matches;
}

function extractAlterTables(string $sql): array
{
    $statements = splitSqlStatements($sql);
    return array_values(array_filter($statements, fn($s) => preg_match('/^ALTER\s+TABLE\s+`[^`]+`/i', trim($s))));
}

function extractAlterTableName(string $sql): string
{
    return preg_match('/^ALTER\s+TABLE\s+`([^`]+)`/i', trim($sql), $m) ? $m[1] : '';
}

function extractColumnsFromCreateBody(string $body): array
{
    $columns = [];
    $lines = preg_split('/
|
|
/', $body);
    foreach ($lines as $line) {
        $line = trim($line);
        $line = rtrim($line, ',');
        if (preg_match('/^`([^`]+)`\s+(.+)$/s', $line, $m)) {
            $columns[$m[1]] = '`' . $m[1] . '` ' . $m[2];
        }
    }
    return $columns;
}

try {
    mysqli_report(MYSQLI_REPORT_OFF);
    $db = new mysqli(DB_HOST, DB_USER, DB_PASS);
    if ($db->connect_error) {
        throw new Exception('Database connection failed: ' . $db->connect_error);
    }
    $db->set_charset(DB_CHARSET);

    if (!$db->select_db(DB_NAME)) {
        runQuery($db, "CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci", 'Database created: ' . DB_NAME);
        if (!$db->select_db(DB_NAME)) {
            throw new Exception('Could not select database: ' . DB_NAME);
        }
    } else {
        addLog('success', 'Database selected: ' . DB_NAME);
    }

    $createTables = extractCreateTables($schemaSql);
    addLog('info', 'Structure-only mode: found ' . count($createTables) . ' table definitions. No data will be inserted.');

    $createdTables = [];

    foreach ($createTables as $tableDef) {
        $table = $tableDef[1];
        $createSql = preg_replace('/^CREATE\s+TABLE\s+`/i', 'CREATE TABLE IF NOT EXISTS `', $tableDef[0]);

        if (!tableExists($db, $table)) {
            if (runQuery($db, $createSql, "Created table `$table`", "Skipped create table `$table`")) {
                $createdTables[$table] = true;
            }
            continue;
        }

        addLog('skipped', "Table `$table` already exists");
        $columns = extractColumnsFromCreateBody($tableDef[2]);
        foreach ($columns as $column => $definition) {
            if (columnExists($db, $table, $column)) {
                addLog('skipped', "Column `$table`.`$column` already exists");
                continue;
            }
            runQuery($db, "ALTER TABLE `$table` ADD COLUMN $definition", "Added missing column `$table`.`$column`", "Skipped column `$table`.`$column`");
        }
    }

    $alterTables = extractAlterTables($schemaSql);
    addLog('info', 'Found ' . count($alterTables) . ' structure ALTER statements. They are applied only to tables created in this run.');
    foreach ($alterTables as $alterSql) {
        $short = preg_replace('/\s+/', ' ', trim($alterSql));
        $short = function_exists('mb_substr') ? mb_substr($short, 0, 170) : substr($short, 0, 170);
        $alterTable = extractAlterTableName($alterSql);

        if ($alterTable === '' || !isset($createdTables[$alterTable])) {
            addLog('skipped', 'Skipped ALTER for existing table: ' . $short);
            continue;
        }

        runQuery($db, $alterSql, 'Applied: ' . $short, 'Skipped existing/invalid ALTER: ' . $short);
    }

    addLog('success', 'Structure installer finished successfully. No data inserted.');
} catch (Throwable $e) {
    addLog('error', $e->getMessage());
}

$_SESSION['install_logs'] = $logs;
header('Location: success.php');
exit;
