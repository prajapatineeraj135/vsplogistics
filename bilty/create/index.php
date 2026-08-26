<?php
/**
 * Bilty Create Module - Main Form Page
 * 
 * Renders the bilty creation form with all input fields and sections.
 * Integrates with search, calculation, and data submission functionality.
 * 
 * Features:
 * - Company information display
 * - Party (Consignor/Consignee) search and selection
 * - GR number auto-generation and validation
 * - Dynamic item row management
 * - Real-time freight and charge calculations
 * 
 * - Multi-purpose delivery location selection
 * Security:
 * - Authentication required via auth.php
 * - All output escaped with htmlspecialchars()
 * - Session-based company context
 * 
 * @author: Development Team
 * @version: 1.0
 * @last_updated: 2025-01-22
 */

// ========================
// SECURITY & SETUP
// ========================

// Require authentication - redirects to login if not authenticated
include "../../protect/auth.php";
include "../../protect/db.php";

// Get logged-in company ID from session
// Default to '102' for testing if not in session
$company_id = $_SESSION['company_id'] ?? '102';
// Fetch company data from database
// Uses prepared statement to prevent SQL injection
$stmt = $conn->prepare("SELECT * FROM company WHERE id = ?");
$stmt->bind_param("i", $company_id);
$stmt->execute();
$result = $stmt->get_result();
// Display error if company not found
if ($result->num_rows === 0) {
    echo "<h3 style='text-align:center;margin-top:50px;'>Company data not found</h3>";
    exit;
}
// Store company data for use in HTML template
$company = $result->fetch_assoc();

$lastBilties = [];
$stmt_last = $conn->prepare("
    SELECT id, gr_number, consignee_name, consignor_name, payment_type
    FROM biltys
    WHERE company_id = ?
      AND status <> 'Trash'
    ORDER BY id DESC
    LIMIT 2
");
if ($stmt_last) {
    $stmt_last->bind_param("i", $company_id);
    $stmt_last->execute();
    $result_last = $stmt_last->get_result();
    while ($row = $result_last->fetch_assoc()) {
        $lastBilties[] = $row;
    }
    $stmt_last->close();
}


// Check if editing an existing bilty
// If 'edit_id' is present in GET parameters, fetch bilty data

if (isset(($_GET['edit_id']))) {
    $edit_id = $_GET['edit_id'];
    $stmt_edit = $conn->prepare("SELECT * FROM biltys WHERE id = ?");
    $stmt_edit->bind_param("i", $edit_id);
    $stmt_edit->execute();
    $result_edit = $stmt_edit->get_result();
    $edit = $result_edit->fetch_assoc();

}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <!-- ======================== CHARACTER & DISPLAY ======================== -->
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />

    <!-- ======================== STYLESHEET ======================== -->
    <link rel="stylesheet" href="assets/css/mobile.css" media="screen and (max-width: 768px)" />
    <link rel="stylesheet" href="assets/css/style.css" />


    <!-- ======================== PAGE TITLE ======================== -->
    <title>Bilty - Create / Edit</title>
</head>

<body>
    <!-- 
        ========================
        MAIN BILTY CONTAINER
        ========================
        Main wrapper for the entire bilty form
    -->
         <?php include "../../content/nav.php"; ?>
    <div class="bilty">
       

   <!--
            ========================
            CONSIGNOR & CONSIGNEE SECTION
            Party Selection & Details
            ========================
            Two-column layout for Consignor (Sender) and Consignee (Receiver)
        -->
        <section class="headingdata">
            <!-- Party Selection Container -->
            <div class="customer-horizontal">

                <!-- CONSIGNOR (LEFT SIDE) -->
                <div class="consignor" style="position:relative;">
                    <!-- Section Header -->
                    <h6 style="border-bottom:1px solid #000;text-align:center;">Consignor</h6>

                    <div class="party-grid">
                        <!-- Party Name Input with Search -->
                        <div class="party-label">Name:</div>
                        <div class="party-name-control">
                            <!-- Search input with autocomplete and dropdown handler -->
                            <input type="text" id="consignor_name" autocomplete="off" value="<?php echo htmlspecialchars($edit['consignor_name'] ?? 'Self'); ?>"
                                onkeyup="searchParty(this,'Consignor')" onkeydown="handlePartyKey(event,'Consignor')"
                                required>
                            <!-- Quick Add Party Button -->
                            <button type="button" class="party-add-btn" tabindex="-1"
                                onclick="showInfo('Opening Party Create Page'); window.open('../../party/', '_blank');">
                                +
                            </button>
                        </div>

                        <!-- Party Address Display (read-only) -->
                        <div class="party-label">Address:</div>
                        <div class="party-value" id="consignor_address">—</div>

                        <!-- Party Contact Display (read-only) -->
                        <div class="party-label">Contact:</div>
                        <div class="party-value" id="consignor_contact">—</div>
                    </div>

                    <!-- Dropdown container for search results -->
                    <div id="consignor_float" class="float-box" tabindex="-1"></div>

                    <!-- Hidden field to store selected party ID -->
                    <input type="hidden" id="consignor_id" value="<?php echo htmlspecialchars($edit['consignor_id'] ?? ''); ?>">
                </div>

                <!-- VERTICAL DIVIDER -->
                <hr class="vertical-separator">

                <!-- CONSIGNEE (RIGHT SIDE) -->
                <div class="consignee" style="position:relative;">
                    <!-- Section Header -->
                    <h6 style="border-bottom:1px solid #000;text-align:center;">Consignee</h6>

                    <div class="party-grid">
                        <!-- Party Name Input with Search -->
                        <div class="party-label">Name:</div>
                        <div class="party-name-control">
                            <!-- Search input with autocomplete and dropdown handler -->
                            <input type="text" id="consignee_name" autocomplete="off" value="<?php echo htmlspecialchars($edit['consignee_name'] ?? ''); ?>"
                                onkeyup="searchParty(this,'Consignee')" onkeydown="handlePartyKey(event,'Consignee')"
                                required>
                            <!-- Quick Add Party Button -->
                            <button type="button" class="party-add-btn" tabindex="-1"
                                onclick="showInfo('Opening Party Create Page'); window.open('../../party/', '_blank');">
                                +
                            </button>
                        </div>

                        <!-- Party Address Display (read-only) -->
                        <div class="party-label">Address:</div>
                        <div class="party-value" id="consignee_address">—</div>

                        <!-- Party Contact Display (read-only) -->
                        <div class="party-label">Contact:</div>
                        <div class="party-value" id="consignee_contact">—</div>
                    </div>

                    <!-- Dropdown container for search results -->
                    <div id="consignee_float" class="float-box" tabindex="-1"></div>

                    <!-- Hidden field to store selected party ID -->
                    <input type="hidden" id="consignee_id" value="<?php echo htmlspecialchars($edit['consignee_id'] ?? ''); ?>">
                </div>
            </div>

            <!-- 
                ========================
                GR / DATE / ROUTE INFO SECTION
                ========================
                Quick reference details table
            -->
            <aside class="details" aria-label="GR Details">
                <table>
                    <!-- GR (Goods Receipt) Number -->
                    <tr>
                        <th class="k">G.R.</th>
                        <td class="sep">:</td>
                        <td class="v big">
                            <!-- Auto-populated GR number field -->
                            <!-- Validates uniqueness on blur -->
                            <div class="gr-entry-control">
                                <input type="text" name="gr_number" autocomplete="off" onblur="validateGRNumber(this)" value="<?php echo htmlspecialchars($edit['gr_number'] ?? ''); ?>"
                                    tabindex="-1"> <!--here add tebindex -->
                                <label class="manual-gr-toggle" title="Manual bilty entry">
                                    <input type="checkbox" id="manual_gr_entry" tabindex="-1">
                                    <span>Manual</span>
                                </label>
                            </div>
                        </td>
                    </tr>

                    <!-- Bilty Date -->
                    <tr>
                        <th class="k">Date</th>
                        <td class="sep">:</td>
                        <td class="v">
                            <!-- Date input in DD-MM-YYYY format -->
                            <!-- Auto-focuses on first field on click -->
                            <input type="text" id="currentDateTime" autocomplete="off" placeholder="DD-MM-YYYY" value="<?php echo htmlspecialchars($edit['date'] ?? ''); ?>"    
                                tabindex="-1" onfocus="selectDateInput(this)">
                        </td>
                    </tr>

                    <!-- Destination Station -->
                    <tr>
                        <th class="k">Kota To</th>
                        <td class="sep">:</td>
                        <td class="v big" style="position:relative;">
                            <!-- Station name input with search dropdown -->
                            <input type="text" id="to_station" name="to_station" placeholder="Station"
                                value="<?php echo htmlspecialchars($edit['to_station'] ?? ''); ?>"
                                autocomplete="off" onkeyup="searchStation(this)" onkeydown="handleStationKey(event)"
                                required>


                            <!-- Floating station list dropdown -->
                            <div id="station_float" class="float-box" tabindex="-1"></div>
                        </td>
                    </tr>
                </table>
            </aside>
        </section>

        <!-- 
            ========================
            MAIN BILTY BODY SECTION
            Item Details & Charges
            ========================
        -->
        <section class="bilty-image-row">

            <!-- 
                ========================
                ITEM DETAILS TABLE SECTION
                Product/Item Management
                ========================
            -->
            <div class="middle-area">
                <!-- Table Column Headers -->
                <div class="heads">
                    <div class="head">No.</div>
                    <div class="head">Description</div>
                    <div class="head">Basis</div>
                    <div class="head">Rate</div>
                    <div class="head">Weight</div>
                    <div class="head">Action</div>
                </div>

                <!-- Item Rows Container -->
                <div class="desc-area">
                    <div class="desc-content" id="itemContainer">
                        <!-- TEMPLATE: Single Item Row (Repeatable) -->
                        <div class="item-row">
                            <!-- Item Number/Quantity -->
                            <div class="item-desc">
                                <input type="text" class="item-quantity" placeholder="No." autocomplete="off" required>
                            </div>

                            <!-- Product/Item Name with Search -->
                            <div class="item-desc" style="position:relative;">
                                <input type="text" class="item-product-name" placeholder="Item Name" name="dec_name"
                                    autocomplete="off" onkeyup="searchProduct(this)" onkeydown="handleProductKey(event)"
                                    required>
                                <!-- Dropdown container for product search results -->
                                <div class="product_float float-box" tabindex="-1"></div>
                            </div>
                           <!-- Product Rate/Price -->
                            <div class="item-desc">
                                <select class="product-rate-basis" placeholder="Rate" autocomplete="off">
                                    <option class="basis" value="per_nag">Per Nag</option>
                                    <option class="basis" value="per_quintle">Per Quintle</option>
                                </select>
                            </div> 
                            <!-- Product Rate/Price -->
                            <div class="item-desc">
                                <input type="text" class="product-rate" placeholder="Rate" autocomplete="off">
                            </div>

                            <!-- Product Weight -->
                            <div class="item-desc">
                                <input type="text" class="product-weight" placeholder="Weight" autocomplete="off">
                            </div>

                            <!-- Action Button (Add/Remove) -->
                            <div class="btn">
                                <button type="button" id="addItemBtn" class="add-item-btn" tabindex="-1" title="Shortcut: Ctrl+Plus">
                                    ➕
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Total Row (Quantity & Weight Summary) -->
                    <div class="desc-footer">
                        <div class="item-desc" style="padding-left: 20px;">
                            <!-- Total Quantity Display -->
                            <span id="total-quantity">0</span>
                        </div>
                        <div class="item-desc">Total</div>
                        <div class="item-desc">
                            <!-- Total Weight Display in KG -->
                            <span id="total-weight">0</span> KG
                        </div>
                    </div>

                    <!-- Invoice / Value / E-way Section -->
                    <div class="bill-footer">
                        <p>
                            <strong>Invoice :</strong>
                            <input type="text" name="invoice_number" autocomplete="off" tabindex="-1">
                        </p>
                        <p>
                            <strong>Value :</strong>
                            <input type="text" name="invoice_value" autocomplete="off" tabindex="-1">
                        </p>
                        <p>
                            <strong>Eway :</strong>
                            <input type="text" name="eway_bill" autocomplete="off" tabindex="-1">
                        </p>
                    </div>

                    <!-- Private Mark & Remark Section -->
                    <div class="mark-footer">
                        <p>
                            <strong>Private Mark :</strong>
                            <input type="text" name="private_mark" autocomplete="off" tabindex="-1">
                        </p>
                        <p>
                            <strong>Remark :</strong>
                            <input type="text" name="remark" autocomplete="off" tabindex="-1">
                        </p>
                        <p>
                            <strong>Delivery At :</strong>
                            <select name="delivery_location" id="delivery_location" tabindex="-1" required>
                                <!-- Default: Godown Delivery -->
                                <option value="G" selected>Office Godown Delivery</option>
                                <!-- Alternative: Door Delivery -->
                                <option value="D">Door Delivery</option>
                            </select>
                        </p>
                    </div>
                </div>
            </div>

            <!-- 
                ========================
                CHARGES SECTION
                Freight & Other Charges
                ========================
            -->
            <div class="charges-area" aria-label="Charges">
                <h3>Charges</h3>
                <div class="charges-inner">

                    <!-- Freight Charge (Auto-calculated) -->
                    <div class="charges-row">
                        <div class="c-label">Freight</div>
                        <div class="c-value">
                            <input type="text" id="freight-input" class="charge-input" autocomplete="off" required>
                        </div>
                    </div>

                    <!-- Hammali Charge (Labor/Handling) -->
                    <div class="charges-row">
                        <div class="c-label">Hammali</div>
                        <div class="c-value">
                            <input type="text" class="charge-input" autocomplete="off">
                        </div>
                    </div>

                    <!-- Peak Freight Charge -->
                    <div class="charges-row">
                        <div class="c-label">P. Freight</div>
                        <div class="c-value">
                            <input type="text" class="charge-input" autocomplete="off">
                        </div>
                    </div>

                    <!-- Brokerage Charge -->
                    <div class="charges-row">
                        <div class="c-label">Brokerage</div>
                        <div class="c-value">
                            <input type="text" class="charge-input" autocomplete="off" tabindex="-1">
                        </div>
                    </div>

                    <!-- Door Delivery Charge -->
                    <div class="charges-row">
                        <div class="c-label">DD Charge</div>
                        <div class="c-value">
                            <input type="text" class="charge-input" autocomplete="off" tabindex="-1">
                        </div>
                    </div>

                    <!-- GR (Goods Receipt) Charge (Default: 10) -->
                    <div class="charges-row">
                        <div class="c-label">GR Charge</div>
                        <div class="c-value">
                            <input type="text" class="charge-input" autocomplete="off" value="" tabindex="-1">
                        </div>
                    </div>

                    <!-- Total Charges (Read-only, Auto-calculated) -->
                    <div class="charges-row total-row">
                        <div class="c-label">Total</div>
                        <div class="c-value">
                            <input type="text" id="totalCharge" autocomplete="off" tabindex="-1" readonly>
                        </div>
                    </div>

                    <!-- Payment Type Selection -->
                    <div class="charges-row">
                        <div class="c-value full">
                            <select name="payment" id="payment">
                                <option value="Topay" selected>Topay</option>
                                <option value="Cash">Cash</option>
                                <option value="TBB">TBB</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- 
            ========================
            ACTION BUTTONS SECTION
            Save, Print, Update Controls
            ========================
        -->
        <div class="bilty-btn">
            <!-- Save/Book Button -->
            <div>
                <button type="button" class="btn" name="bilty_save" id="bilty_save" onclick="saveBilty()">
                    Book
                </button>
            </div>

             <!-- Update Button -->
            <div>
                <button type="button" class="btn" name="bilty_update" id="bilty_update" style="display:none;" onclick="updateBilty()">
                    Update
                </button>
            </div>

<!-- cancel Button -->
             <div>
                <button type="button" class="btn" name="bilty_cancel" id="bilty_cancel" style="display:none;" onclick="cancelBilty()">
                    Cancel
                </button>
            </div>
            <!-- Print Button -->
            <div>
                <button type="button" class="btn" name="bilty_print" id="bilty_print" title="Shortcut: Alt+Numpad Enter" onclick="printLastSavedBilty(false, true);">
                    Print
                </button>
            </div>
            

          
        </div>

        <div class="last-bilty-panel" aria-label="Recent Bilties">
            <?php if (!empty($lastBilties)): ?>
                <table class="last-bilty-table">
                    <tbody>
                        <?php foreach ($lastBilties as $lastBilty): ?>
                            <tr>
                                <td><?php echo htmlspecialchars(strtoupper((string) ($lastBilty['gr_number'] ?? ''))); ?></td>
                                <td><?php echo htmlspecialchars(ucwords(strtolower((string) ($lastBilty['consignee_name'] ?? '')))); ?></td>
                                <td><?php echo htmlspecialchars(ucwords(strtolower((string) ($lastBilty['consignor_name'] ?? '')))); ?></td>
                                <td><?php echo htmlspecialchars(strtoupper((string) ($lastBilty['payment_type'] ?? ''))); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <table class="last-bilty-table">
                    <tbody>
                        <tr>
                            <td>No bilty entry found.</td>
                        </tr>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    <!-- 
        ========================
        JAVASCRIPT INCLUDES
        ========================
        Load main bilty functionality
    -->
    <script src="assets/js/bilty.js"></script>

    <!-- 
        Alternative (RECOMMENDED): Use optimized version:
        <script src="bilty_optimized.js"></script>
    -->

    <!-- 
        ========================
        PAGE INITIALIZATION SCRIPT
        ========================
        Runs on page load
    -->
    <script>
        // Initialize page when DOM is fully loaded
        window.addEventListener('load', initializePage);
    </script>

</body>

</html>
