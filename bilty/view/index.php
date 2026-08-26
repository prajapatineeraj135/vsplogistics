<?php
// Protect page (login required)
include "../../protect/auth.php";
include "../../protect/db.php";
include "../../protect/case_converter.php";

// Get logged-in company ID from session
$company_id = $_SESSION['company_id'] ?? '101';

// Fetch company data
$stmt = $conn->prepare("SELECT * FROM company WHERE id = ?");
$stmt->bind_param("i", $company_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
  echo "<h3 style='text-align:center;margin-top:50px;'>Company data not found</h3>";
  exit;
}

$company = $result->fetch_assoc();

// Fetch bilty by id or GR number from query params
$bilty = null;
$items = [];
$bilty_id = isset($_GET['id']) ? intval($_GET['id']) : null;
$gr_number = isset($_GET['gr']) ? trim($_GET['gr']) : null;

if ($bilty_id) {
  $stmt = $conn->prepare("SELECT * FROM biltys WHERE id = ? AND company_id = ?");
  $stmt->bind_param("ii", $bilty_id, $company_id);
} elseif (!empty($gr_number)) {
  $stmt = $conn->prepare("SELECT * FROM biltys WHERE gr_number = ? AND company_id = ?");
  $stmt->bind_param("si", $gr_number, $company_id);
} else {
  echo "<h3 style='text-align:center;margin-top:50px;'>Missing bilty id or GR number</h3>";
  exit;
}

$stmt->execute();
$res = $stmt->get_result();
if ($res && $res->num_rows > 0) {
  $bilty = $res->fetch_assoc();
} else {
  echo "<h3 style='text-align:center;margin-top:50px;'>Bilty not found</h3>";
  exit;
}

// Fetch bilty items
$stmtItems = $conn->prepare("SELECT * FROM bilty_items WHERE bilty_id = ? ORDER BY COALESCE(item_number, 0) ASC, id ASC");
$stmtItems->bind_param("i", $bilty['id']);
$stmtItems->execute();
$itemsRes = $stmtItems->get_result();
while ($row = $itemsRes->fetch_assoc()) {
  $items[] = $row;
}

// Derived display values
$displayDateSource = !empty($bilty['bilty_date']) ? $bilty['bilty_date'] : ($bilty['created_at'] ?? '');
$displayDate = $displayDateSource !== '' ? date('d/m/Y', strtotime($displayDateSource)) : '';
$displayTime = $displayDateSource !== '' ? date('h:i A', strtotime($displayDateSource)) : '';
$itemCount = !empty($bilty['total_qty']) ? $bilty['total_qty'] : array_reduce($items, function($acc, $it){ return $acc + (int)($it['quantity'] ?? 0); }, 0);
$totalWeight = !empty($bilty['total_weight']) ? $bilty['total_weight'] : array_reduce($items, function($acc, $it){ return $acc + (int) round((float)($it['weight'] ?? 0)); }, 0);

// Get company name for display
$biltyCompanyId = $bilty['company_id'] ?? '102';
$companyStmt = $conn->prepare("SELECT branch FROM company WHERE id = ?");
$companyStmt->bind_param("s", $biltyCompanyId);
$companyStmt->execute();
$companyResult = $companyStmt->get_result();
$biltyCompanyName = 'Company ' . $biltyCompanyId;
if ($companyResult && $companyResult->num_rows > 0) {
  $companyRow = $companyResult->fetch_assoc();
  $biltyCompanyName = $companyRow['branch'];
}

// Payment type mapping
$paymentTypeMap = [
  'topay' => 'Topay',
  'cash' => 'Cash',
  'tbb' => 'TBB',
];
$paymentLabel = isset($bilty['payment_type']) ? ($paymentTypeMap[strtolower($bilty['payment_type'])] ?? $bilty['payment_type']) : 'Topay';
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <!-- Meta information -->
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <!-- External CSS -->
  <link rel="stylesheet" href="biltystyle.css">

  <!-- Print Styles -->
  <style>
    .branch-badge {
      display: inline-block;
      padding: 2px 8px;
      font-size: 10px;
      font-weight: bold;
      border-radius: 3px;
      margin-left: 8px;
      background-color: #6c757d;
      color: white;
      vertical-align: middle;
    }

    @media print {
      html, body {
        background: #fff !important;
        margin: 0;
        padding: 0;
        -webkit-print-color-adjust: exact;
      }

      section[style*="margin-top: 20px"] {
        display: none !important;
      }

      .notification {
        display: none !important;
      }

   

      .bilty-print:last-child {
        page-break-after: auto;
      }
    }
  </style>

  <!-- Page title -->
  <title>Bilty-View</title>
</head>

<body>

  <!-- =======================
       MAIN BILTY CONTAINER
  ======================== -->
  <div class="bilty">

    <!-- =======================
         HEADER / COMPANY INFO
    ======================== -->
    <header class="header">

      <!-- Company name & address -->
      <div class="company-name">
        <h1><?= htmlspecialchars(capitalizeWords($company['company_name'])) ?></h1>
        <p>
          <?= htmlspecialchars(capitalizeWords($company['address1'])) ?>, <?= htmlspecialchars(capitalizeWords($company['address2'])) ?>,
          <?= htmlspecialchars(capitalizeWords($company['address3'])) ?>, <?= htmlspecialchars(capitalizeWords($company['city'])) ?>
          <?= htmlspecialchars($company['pincode']) ?> <?= htmlspecialchars(capitalizeWords($company['state'])) ?>
        </p>
        <p><?= htmlspecialchars($company['phone1']) ?>, <?= htmlspecialchars($company['phone2']) ?></p>
      </div>

      <!-- Branch & GST details -->
      <div class="company-branch-details">
        <p><strong>Branch:</strong> <?= htmlspecialchars(capitalizeWords($company['branch'])) ?></p>
        <p><strong>Contact:</strong> <?= htmlspecialchars($company['owner_phone']) ?></p>
        <p><strong>Transoprt ID:</strong> <?= htmlspecialchars($company['gst_no']) ?></p>
      </div>

    </header>

    <!-- =======================
         CONSIGNOR & CONSIGNEE
    ======================== -->
    <section class="headingdata">

      <!-- Consignor & Consignee box -->
      <div class="customer-horizontal">

        <!-- Consignor details -->
        <div class="consignor">
          <center>
            <h1>
              Consignor
            </h1>
          </center>
          <div class="customer-details">
            <p><strong>Name:</strong> <?= htmlspecialchars(capitalizeWords($bilty['consignor_name'] ?? '')) ?></p>
            <p><strong>Address:</strong> —</p>
            <p><strong>Contact:</strong> —</p>
          </div>
        </div>

        <!-- Vertical divider -->
        <hr class="vertical-separator">

        <!-- Consignee details -->
        <div class="consignee">
          <center>
            <h1>
              Consignee
            </h1>
          </center>
          <div class="customer-details">
            <p><strong>Name:</strong> <?= htmlspecialchars(capitalizeWords($bilty['consignee_name'] ?? '')) ?></p>
            <p><strong>Address:</strong> —</p>
            <p><strong>Contact:</strong> —</p>
          </div>
        </div>

      </div>

      <!-- =======================
           GR / DATE / ROUTE INFO
      ======================== -->
      <aside class="details" aria-label="GR Details">
        <table>
          <tr>
            <th class="k">G.R.</th>
            <td class="sep">:</td>
            <td class="v big">
              <?= htmlspecialchars($bilty['gr_number']) ?>
            </td>
          </tr>
          <tr>
            <th class="k">Date</th>
            <td class="sep">:</td>
            <td class="v"><?= (htmlspecialchars($displayDate)) ?> <?= (htmlspecialchars($displayTime)) ?></td>
          </tr>
          <tr>
            <th class="k">Status</th>
            <td class="sep">:</td>
            <td class="v big"><?= htmlspecialchars((string) ($bilty['status'] ?? '')) ?></td>
          </tr>
          <tr>
            <th class="k"><?= htmlspecialchars(capitalizeWords(($company['city'] ?? ''))) ?> To</th>
            <td class="sep">:</td>
            <td class="v big"><?= htmlspecialchars(capitalizeWords($bilty['to_station'] ?? '')) ?></td>
          </tr>
        </table>
      </aside>

    </section>

    <!-- =======================
         MAIN BILTY BODY
    ======================== -->
    <section class="bilty-image-row">

      <!-- =======================
           STATION LIST
      ======================== -->
      <div class="station-area" aria-label="Stations">
        <h3>Station</h3>
        <ul>
          <li><strong>Bakani</strong><br>9001339506</li>
          <li><strong>Ratlai</strong><br>9001339507</li>
          <li><strong>Raipur</strong><br>9001339508</li>
          <li><strong>Soyatkalan</strong><br>9001339509</li>
          <li><strong>Sunel</strong><br>9001339510</li>
          <li><strong>Pidawa</strong><br>9001339511</li>
          <li><strong>Hemda</strong><br>9001339512</li>
          <li><strong>All Rajasthan</strong></li>
        </ul>
      </div>

      <!-- =======================
           ITEM DETAILS TABLE
      ======================== -->
      <div class="middle-area">

        <!-- Table header -->
        <div class="heads">
          <div class="head">Qty</div>
          <div class="head">Description</div>
          <div class="head">Weight</div>
        </div>

        <!-- Item rows -->
        <div class="desc-area">

          <div class="desc-content">
            <?php if (!empty($items)) : ?>
              <?php foreach ($items as $idx => $it) : ?>
                <div class="item-row">
                  <div class="item-desc"><?= htmlspecialchars((int)($it['quantity'] ?? 0)) ?></div>
                  <div class="item-desc"><?= htmlspecialchars(capitalizeWords($it['item_name'] ?? '')) ?></div>
                  <div class="item-desc"><?= htmlspecialchars(number_format((float)($it['weight'] ?? 0), 0)) ?> KG</div>
                </div>
              <?php endforeach; ?>
            <?php else : ?>
              <div class="item-row">
                <div class="item-desc">—</div>
                <div class="item-desc">No items</div>
                <div class="item-desc">0 KG</div>
              </div>
            <?php endif; ?>
          </div>

          <!-- Total row -->
          <div class="desc-footer">
            <div class="item-desc" style="padding-left: 20px;"><?= htmlspecialchars(number_format((float)$itemCount, 0)) ?></div>
            <div class="item-desc">Total</div>
            <div class="item-desc"><?= htmlspecialchars(number_format((float)$totalWeight, 0)) ?> KG</div>
          </div>

          <!-- Invoice / value / eway -->
          <div class="bill-footer">
            <p><strong>Invoice :</strong> <?= htmlspecialchars($bilty['invoice_number'] ?? '—') ?></p>
            <p><strong>Value :</strong> <?= htmlspecialchars(number_format((float)($bilty['invoice_value'] ?? 0), 0)) ?></p>
            <p><strong>Eway :</strong> <?= htmlspecialchars($bilty['eway_bill'] ?? '—') ?></p>
          </div>

          <!-- Mark & remark -->
          <div class="mark-footer">
            <p><strong>Private Marka :</strong> <?= htmlspecialchars($bilty['private_mark'] ?? '—') ?></p>
            <p><strong>Remark :</strong> <?= htmlspecialchars($bilty['remark'] ?? '') ?></p>
          </div>


          <!-- Note -->
          <div class="note-footer">
            <p><strong>Note:</strong> We Are Not Responsible For Any Type Of Damage, Breakage, Leakage, Fire& Any
              Natural Calamiteis.</p>
          </div>
          <!-- Delivery location -->
          <div class="deliver-footer">
            <p><strong>Delivery At :</strong> <?php 
              $deliveryLocation = $bilty['delivery_location'] ?? 'G';
              if (strtoupper($deliveryLocation) === 'D') {
                echo 'Door Delivery';
              } else {
                echo htmlspecialchars($company['company_name']);
              }
            ?></p>
          </div>

        </div>
      </div>

      <!-- =======================
           CHARGES SECTION
      ======================== -->
      <div class="charges-area" aria-label="Charges">

        <h3>Charges</h3>

        <div class="charges-inner">

          <!-- Individual charge row -->
          <div class="charges-row">
            <div class="c-label">Freight</div>
            <div class="c-value"><?= number_format((float)($bilty['freight'] ?? 0), 0) ?></div>
          </div>

          <div class="charges-row">
            <div class="c-label">Hammali</div>
            <div class="c-value"><?= number_format((float)($bilty['hammali'] ?? 0), 0) ?></div>
          </div>

          <div class="charges-row">
            <div class="c-label">P. Freight</div>
            <div class="c-value"><?= number_format((float)($bilty['p_freight'] ?? 0), 0) ?></div>
          </div>

          <div class="charges-row">
            <div class="c-label">Brokerage</div>
            <div class="c-value"><?= number_format((float)($bilty['brokerage'] ?? 0), 0) ?></div>
          </div>

          <div class="charges-row">
            <div class="c-label">DD Charge</div>
            <div class="c-value"><?= number_format((float)($bilty['dd_charge'] ?? 0), 0) ?></div>
          </div>

          <div class="charges-row">
            <div class="c-label">GR Charge</div>
            <div class="c-value"><?= number_format((float)($bilty['gr_charge'] ?? 0), 0) ?></div>
          </div>

          <!-- Total -->
          <div class="charges-row total-row">
            <div class="c-label">Total</div>
            <div class="c-value"><?= number_format((float)($bilty['total_charge'] ?? 0), 0) ?></div>
          </div>

          <!-- Paid / To pay -->
          <div class="charges-row">
            <div class="c-value full"><?= htmlspecialchars($paymentLabel) ?></div>
          </div>

        </div>
      </div>

    </section>


  </div>

  <!-- Notification System -->
  

</body>

</html>
