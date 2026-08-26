-- ========================================
-- BILTY MANAGEMENT SYSTEM - COMPLETE DATABASE
-- ========================================
-- Database: u448438938_vsp
-- Version: 1.0
-- Created: 2026-01-23
-- 
-- This file contains all required tables for the bilty management system.
-- Run this script to set up the complete database structure.
-- 
-- Tables included:
-- 1. company - Company master data
-- 2. party - Consignor/Consignee records
-- 3. station - Station/location master
-- 4. agent - Agent master
-- 5. products - Product master list
-- 6. party_products - Party-specific product rates
-- 7. product_station_rates - Station-specific product rates
-- 8. biltys - Main bilty records
-- 9. challans - Challan header records
-- 10. bilty_items - Bilty line items
-- 11. device_sessions - Login session tracking
-- 12. vehicles - Vehicle master
-- 13. inword_challans - Inword challan headers
-- 14. inword_biltys - Inword bilty headers
-- 15. inword_bilty_items - Inword bilty line items
-- 16. bills - TBB bill records
-- 17. ledger_payments - Ledger payment/recovery records
-- 18. bilty_tbb - Legacy TBB records
-- ========================================

-- Use database
CREATE DATABASE IF NOT EXISTS u448438938_vsp;
USE u448438938_vsp;

-- ========================================
-- TABLE 1: COMPANY
-- ========================================
-- Stores company/branch information
-- Each company gets login credentials and can manage their biltys
-- ========================================

CREATE TABLE IF NOT EXISTS `company` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_name` varchar(255) NOT NULL,
  `legal_name` varchar(255) DEFAULT NULL,
  `business_type` varchar(100) DEFAULT NULL,
  `gst_no` varchar(50) DEFAULT NULL,
  
  `owner_name` varchar(255) DEFAULT NULL,
  `owner_phone` varchar(20) DEFAULT NULL,
  `manager_name` varchar(255) DEFAULT NULL,
  `manager_phone` varchar(20) DEFAULT NULL,
  
  `branch` varchar(100) DEFAULT NULL,
  `address1` varchar(255) DEFAULT NULL,
  `address2` varchar(255) DEFAULT NULL,
  `address3` varchar(255) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `pincode` varchar(10) DEFAULT NULL,
  `state` varchar(100) DEFAULT NULL,
  
  `phone1` varchar(20) DEFAULT NULL,
  `phone2` varchar(20) DEFAULT NULL,
  `whatsapp` varchar(20) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `bank_name` varchar(255) DEFAULT NULL,
  `bank_account_name` varchar(255) DEFAULT NULL,
  `bank_account_number` varchar(100) DEFAULT NULL,
  `bank_ifsc_code` varchar(50) DEFAULT NULL,
  `upi_id` varchar(150) DEFAULT NULL,
  `upi_qr_path` varchar(500) DEFAULT NULL,
  
  `username` varchar(100) DEFAULT NULL UNIQUE,
  `pass` varchar(100) DEFAULT NULL COMMENT 'Plain text password for display',
  `password` varchar(255) DEFAULT NULL COMMENT 'Hashed password for authentication',
  
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  PRIMARY KEY (`id`),
  KEY `idx_username` (`username`),
  KEY `idx_branch` (`branch`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ========================================
-- TABLE 2: PARTY
-- ========================================
-- Stores Consignor/Consignee party information
-- Each party can have custom products with rates
-- ========================================

CREATE TABLE IF NOT EXISTS `party` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `party_type` ENUM('Consignor', 'Consignee') NOT NULL COMMENT 'Type of party',
  `bilty_type` varchar(50) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `contact` varchar(20) DEFAULT NULL,
  `station` varchar(255) DEFAULT NULL COMMENT 'Associated station name',
  `address1` varchar(255) DEFAULT NULL,
  `address2` varchar(255) DEFAULT NULL,
  `pincode` varchar(10) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `state` varchar(100) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  PRIMARY KEY (`id`),
  KEY `idx_party_type` (`party_type`),
  KEY `idx_name` (`name`),
  KEY `idx_station` (`station`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ========================================
-- TABLE 3: STATION
-- ========================================
-- Master list of stations/locations
-- Used for destination selection in bilty
-- ========================================

CREATE TABLE IF NOT EXISTS `station` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `station_name` varchar(255) NOT NULL,
  `city` varchar(100) DEFAULT NULL,
  `state` varchar(100) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  PRIMARY KEY (`id`),
  KEY `idx_station_name` (`station_name`),
  KEY `idx_city` (`city`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ========================================
-- TABLE 4: AGENT
-- ========================================
-- Agent master list
-- Used for challan/bilty assignments
-- ========================================

CREATE TABLE IF NOT EXISTS `agent` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `agent_name` varchar(255) NOT NULL,
  `contact` varchar(50) NOT NULL,
  `station` varchar(255) NOT NULL,
  `address` varchar(500) DEFAULT NULL,
  `commission_percent` decimal(5,2) DEFAULT 0.00,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  PRIMARY KEY (`id`),
  KEY `idx_agent_name` (`agent_name`),
  KEY `idx_agent_station` (`station`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ========================================
-- TABLE 5: PRODUCTS
-- ========================================
-- Master product list
-- Used as template for party-specific products
-- ========================================

CREATE TABLE IF NOT EXISTS `products` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_name` varchar(255) NOT NULL,
  `product_type` varchar(100) DEFAULT NULL,
  `product_category` varchar(100) DEFAULT NULL,
  `rate` decimal(10,2) DEFAULT 0.00,
  `weight` decimal(10,2) DEFAULT 0.00,
  `rate_basis` varchar(20) NOT NULL DEFAULT 'Nag',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  PRIMARY KEY (`id`),
  KEY `idx_product_name` (`product_name`),
  KEY `idx_product_type` (`product_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ========================================
-- TABLE 6: PARTY_PRODUCTS
-- ========================================
-- Party-specific products with custom rates
-- Allows each party to have their own rate card
-- ========================================

CREATE TABLE IF NOT EXISTS `party_products` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `party_id` int(11) NOT NULL,
  `product_name` varchar(255) NOT NULL,
  `product_type` varchar(100) DEFAULT NULL,
  `product_category` varchar(100) DEFAULT NULL,
  `rate` decimal(10,2) DEFAULT 0.00,
  `weight` decimal(10,2) DEFAULT 0.00,
  `rate_basis` varchar(20) NOT NULL DEFAULT 'Nag',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  PRIMARY KEY (`id`),
  KEY `idx_party_id` (`party_id`),
  KEY `idx_product_name` (`product_name`),
  CONSTRAINT `fk_party_products_party` FOREIGN KEY (`party_id`) REFERENCES `party` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ========================================
-- TABLE 7: PRODUCT_STATION_RATES
-- ========================================
-- Station-specific rate overrides per product
-- When a product is shipped to a particular station,
-- this rate is used instead of the default product rate.
-- If no record exists for a station, the default rate applies.
-- ========================================

CREATE TABLE IF NOT EXISTS `product_station_rates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL COMMENT 'References products.id',
  `station_name` varchar(100) NOT NULL COMMENT 'Destination station name (case-insensitive match)',
  `rate` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Rate for this station',
  `rate_basis` varchar(20) NOT NULL DEFAULT 'Nag' COMMENT 'Nag or Weight',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_product_station` (`product_id`, `station_name`),
  KEY `idx_psr_product_id` (`product_id`),
  KEY `idx_psr_station_name` (`station_name`),
  CONSTRAINT `fk_psr_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ========================================
-- TABLE 8: BILTYS
-- ========================================
-- Main bilty records (Goods Receipt)
-- Stores header information for each bilty
-- ========================================

CREATE TABLE IF NOT EXISTS `biltys` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) DEFAULT NULL COMMENT 'Company/branch that created this bilty',
  
  -- Party Information
  `consignor_id` int(11) DEFAULT 0,
  `consignor_name` varchar(255) NOT NULL,
  `consignee_id` int(11) DEFAULT 0,
  `consignee_name` varchar(255) NOT NULL,
  
  -- Bilty Details
  `to_station` varchar(255) NOT NULL COMMENT 'Destination station',
  `challan_id` int(11) DEFAULT NULL COMMENT 'Linked challan record when dispatched',
  `gr_number` varchar(50) DEFAULT NULL COMMENT 'Goods Receipt number',
  `gr_type` ENUM('auto', 'manual') DEFAULT 'auto' COMMENT 'auto=branch/sequence, manual=user-entered',
  `bilty_date` datetime DEFAULT CURRENT_TIMESTAMP,
  
  -- Invoice/Document Details
  `invoice_number` varchar(50) DEFAULT NULL,
  `invoice_value` decimal(10,2) DEFAULT 0.00,
  `eway_bill` varchar(50) DEFAULT NULL,
  `private_mark` varchar(255) DEFAULT NULL,
  `remark` text DEFAULT NULL,
  
  -- Delivery Information
  `delivery_location` varchar(10) DEFAULT 'G' COMMENT 'G=Godown, D=Door Delivery',
  
  -- Charges
  `freight` decimal(10,2) DEFAULT 0.00,
  `hammali` decimal(10,2) DEFAULT 0.00,
  `p_freight` decimal(10,2) DEFAULT 0.00,
  `brokerage` decimal(10,2) DEFAULT 0.00,
  `dd_charge` decimal(10,2) DEFAULT 0.00,
  `gr_charge` decimal(10,2) DEFAULT 10.00,
  `total_charge` decimal(10,2) DEFAULT 0.00,
  `payment_type` varchar(20) DEFAULT 'Topay' COMMENT 'Topay, Cash, TBB',
  
  -- Item Totals
  `total_qty` decimal(10,2) DEFAULT 0.00,
  `total_weight` decimal(10,2) DEFAULT 0.00,
  
  -- Status & Timestamps
  `status` ENUM('Booked', 'Dispatch', 'Deliver', 'Cancel', 'Trash') DEFAULT 'Booked',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_gr_number` (`gr_number`),
  KEY `idx_company_id` (`company_id`),
  KEY `idx_consignor_id` (`consignor_id`),
  KEY `idx_consignee_id` (`consignee_id`),
  KEY `idx_consignor_name` (`consignor_name`),
  KEY `idx_consignee_name` (`consignee_name`),
  KEY `idx_gr_number` (`gr_number`),
  KEY `idx_challan_id` (`challan_id`),
  KEY `idx_gr_type` (`gr_type`),
  KEY `idx_bilty_date` (`bilty_date`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_biltys_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ========================================
-- TABLE 9: CHALLANS
-- ========================================
-- Challan header records
-- Links to biltys through biltys.challan_id
-- ========================================

CREATE TABLE IF NOT EXISTS `challans` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `challan_no` varchar(100) NOT NULL,
  `challan_date` datetime DEFAULT CURRENT_TIMESTAMP,
  `challan_station` varchar(255) DEFAULT NULL,
  `vehicle_no` varchar(50) DEFAULT NULL,
  `driver_name` varchar(100) DEFAULT NULL,
  `driver_contact` varchar(50) DEFAULT NULL,
  `owner_name` varchar(255) DEFAULT NULL,
  `contact` varchar(100) DEFAULT NULL COMMENT 'Legacy agent/owner contact field',
  `agent_name` varchar(255) DEFAULT NULL,
  `agent_contact` varchar(100) DEFAULT NULL,
  `paid_total` decimal(10,2) DEFAULT 0.00,
  `freight_total` decimal(10,2) DEFAULT 0.00,
  `recovery_total` decimal(10,2) DEFAULT 0.00,
  `cutting_total` decimal(10,2) DEFAULT 0.00,
  `commission_total` decimal(10,2) DEFAULT 0.00,
  `final_total` decimal(10,2) DEFAULT 0.00,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_company_challan_no` (`company_id`, `challan_no`),
  KEY `idx_challan_no` (`challan_no`),
  KEY `idx_challan_station` (`challan_station`),
  KEY `idx_challan_date` (`challan_date`),
  KEY `idx_company_id` (`company_id`),
  CONSTRAINT `fk_challans_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Add FK from biltys to challans after both tables exist
ALTER TABLE `biltys`
  ADD CONSTRAINT `fk_biltys_challan`
  FOREIGN KEY (`challan_id`) REFERENCES `challans` (`id`)
  ON DELETE SET NULL;


-- ========================================
-- TABLE 10: BILTY_ITEMS
-- ========================================
-- Line items for each bilty
-- Multiple products/items per bilty
-- ========================================

CREATE TABLE IF NOT EXISTS `bilty_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `bilty_id` int(11) NOT NULL,
  `item_number` int(11) DEFAULT NULL COMMENT 'Quantity/number of packages',
  `item_name` varchar(255) NOT NULL COMMENT 'Product/item description',
  `rate` decimal(10,2) DEFAULT 0.00,
  `weight` decimal(10,2) DEFAULT 0.00,
  `rate_basis` varchar(20) NOT NULL DEFAULT 'Nag',
  `quantity` int(11) DEFAULT 0 COMMENT 'Legacy field, use item_number instead',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  
  PRIMARY KEY (`id`),
  KEY `idx_bilty_id` (`bilty_id`),
  CONSTRAINT `fk_bilty_items_bilty` FOREIGN KEY (`bilty_id`) REFERENCES `biltys` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ========================================
-- TABLE 11: DEVICE_SESSIONS
-- ========================================
-- Tracks user login sessions by device
-- Enforces single-login-per-device policy
-- ========================================

CREATE TABLE IF NOT EXISTS `device_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `device_id` varchar(255) NOT NULL COMMENT 'Unique device identifier',
  `user_type` ENUM('admin', 'company') NOT NULL,
  `user_id` varchar(255) NOT NULL,
  `login_time` timestamp DEFAULT CURRENT_TIMESTAMP,
  `logout_time` timestamp NULL DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  
  PRIMARY KEY (`id`),
  KEY `idx_device_id` (`device_id`),
  KEY `idx_active_session` (`device_id`, `logout_time`),
  KEY `idx_login_time` (`login_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ========================================
-- TABLE 12: VEHICLES
-- ========================================
-- Vehicle master for company
-- ========================================

CREATE TABLE IF NOT EXISTS `vehicles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` varchar(20) NOT NULL,
  `vehicle_number` varchar(20) NOT NULL,
  `driver_name` varchar(100) NOT NULL,
  `owner_name` varchar(100) NOT NULL,
  `mobile` varchar(15) NOT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_vehicle_company` (`company_id`, `vehicle_number`),
  KEY `idx_vehicle_number` (`vehicle_number`),
  KEY `idx_company_id` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ========================================
-- TABLE 13: INWORD_CHALLANS
-- ========================================

CREATE TABLE IF NOT EXISTS `inword_challans` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_inword_challan_company_id` (`company_id`),
  KEY `idx_inword_challan_no` (`challan_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ========================================
-- TABLE 14: INWORD_BILTYS
-- ========================================

CREATE TABLE IF NOT EXISTS `inword_biltys` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  `bilty_date` datetime DEFAULT CURRENT_TIMESTAMP,
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
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_inword_bilty_company_id` (`company_id`),
  KEY `idx_inword_gr` (`inword_gr`),
  KEY `idx_inword_bilty_challan_no` (`challan_no`),
  KEY `idx_inword_challan_id` (`inword_challan_id`),
  KEY `idx_inword_dispatch_challan_id` (`challan_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ========================================
-- TABLE 15: INWORD_BILTY_ITEMS
-- ========================================

CREATE TABLE IF NOT EXISTS `inword_bilty_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `inword_bilty_id` int(11) NOT NULL,
  `quantity` int(11) DEFAULT 0,
  `product_name` varchar(255) DEFAULT NULL,
  `rate` int(11) DEFAULT 0,
  `weight` int(11) DEFAULT 0,
  `rate_basis` varchar(20) DEFAULT 'Nag',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_inword_bilty_id` (`inword_bilty_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Add inword foreign keys after tables exist
ALTER TABLE `inword_bilty_items`
  ADD CONSTRAINT `fk_inword_items_header`
  FOREIGN KEY (`inword_bilty_id`) REFERENCES `inword_biltys` (`id`)
  ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `inword_biltys`
  ADD CONSTRAINT `fk_inword_header_challan`
  FOREIGN KEY (`inword_challan_id`) REFERENCES `inword_challans` (`id`)
  ON DELETE SET NULL ON UPDATE CASCADE;


-- ========================================
-- TABLE 16: BILLS
-- ========================================
-- Stores generated/manual bills, including auto TBB bills.
-- ========================================

CREATE TABLE IF NOT EXISTS `bills` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_bill_number` (`bill_number`),
  KEY `idx_bills_company_id` (`company_id`),
  KEY `idx_bills_party_id` (`party_id`),
  KEY `idx_bills_bill_month` (`bill_month`),
  KEY `idx_bills_bill_type` (`bill_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ========================================
-- TABLE 17: LEDGER_PAYMENTS
-- ========================================
-- Stores ledger recovery/payment entries for TBB, agents, transporters, etc.
-- ========================================

CREATE TABLE IF NOT EXISTS `ledger_payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  KEY `idx_ledger_company_id` (`company_id`),
  KEY `idx_ledger_account_type` (`account_type`),
  KEY `idx_ledger_account_name` (`account_name`),
  KEY `idx_ledger_payment_date` (`payment_date`),
  KEY `idx_ledger_challan_no` (`challan_no`),
  KEY `idx_ledger_voucher_no` (`voucher_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ========================================
-- TABLE 18: BILTY_TBB
-- ========================================
-- Legacy TBB table retained for older reports/tools.
-- ========================================

CREATE TABLE IF NOT EXISTS `bilty_tbb` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `gr_number` int(10) NOT NULL,
  `pm` int(11) NOT NULL,
  `date` date NOT NULL,
  `consignor` varchar(100) NOT NULL,
  `consignee` varchar(100) NOT NULL,
  `station` varchar(10) NOT NULL,
  `nag` int(10) NOT NULL,
  `type` varchar(5) NOT NULL,

  PRIMARY KEY (`id`),
  UNIQUE KEY `gr_number` (`gr_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ========================================
-- SAMPLE DATA (OPTIONAL)
-- ========================================
-- Uncomment to insert sample data for testing

/*
-- Sample Company
INSERT INTO `company` (`id`, `company_name`, `branch`, `address1`, `address2`, `city`, `pincode`, `state`, `phone1`, `gst_no`, `owner_phone`, `username`, `pass`, `password`) VALUES
(102, 'New Mahalaxmi Transport Co.', 'Kota Branch', 'Transport Nagar', 'Near Railway Station', 'Kota', '324001', 'Rajasthan', '0744-2345678', '08AABCN1234M1Z5', '9876543210', 'kota102', 'pass123', '$2y$10$examplehashhere');

-- Sample Stations
INSERT INTO `station` (`station_name`, `city`, `state`) VALUES
('Kota', 'Kota', 'Rajasthan'),
('Jaipur', 'Jaipur', 'Rajasthan'),
('Delhi', 'Delhi', 'Delhi'),
('Mumbai', 'Mumbai', 'Maharashtra');

-- Sample Products
INSERT INTO `products` (`product_name`, `product_type`, `product_category`, `rate`, `weight`) VALUES
('Rice Bags', 'Food Grains', 'Agricultural', 50.00, 50.00),
('Cement Bags', 'Construction', 'Building Material', 30.00, 50.00),
('Electronics', 'Consumer Goods', 'Electronics', 100.00, 10.00);

-- Sample Party
INSERT INTO `party` (`party_type`, `name`, `contact`, `station`, `address1`, `city`, `state`) VALUES
('Consignor', 'ABC Traders', '9876543210', 'Kota', 'Shop No 123, Market Area', 'Kota', 'Rajasthan'),
('Consignee', 'XYZ Enterprises', '9123456780', 'Jaipur', 'Plot No 456, Industrial Area', 'Jaipur', 'Rajasthan');
*/


-- ========================================
-- DATABASE SETUP COMPLETE
-- ========================================
-- All tables created successfully.
-- 
-- NEXT STEPS:
-- 1. Import this file: mysql -u root -p < database_complete.sql
-- 2. Or use phpMyAdmin to import this file
-- 3. Create at least one company record with login credentials
-- 4. Start adding stations, parties, and products
-- 5. Begin creating biltys
-- 
-- MAINTENANCE:
-- - Regular backups recommended
-- - Use provided migration scripts only for existing databases
-- - This file is for fresh installations only
-- ========================================
