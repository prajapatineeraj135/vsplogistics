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

// Fetch one or more bilties by ids, or fallback to single id/gr
$biltyIdsParam = $_GET['ids'] ?? '';
$biltyIds = array_values(array_unique(array_filter(array_map('intval', explode(',', $biltyIdsParam)), function ($v) {
  return $v > 0;
})));

$bilty_id = isset($_GET['id']) ? intval($_GET['id']) : null;
$gr_number = isset($_GET['gr']) ? trim($_GET['gr']) : null;

$bilties = [];

if (!empty($biltyIds)) {
  // Multi-print by ids
  $placeholders = implode(',', array_fill(0, count($biltyIds), '?'));
  $sql = "SELECT * FROM biltys WHERE id IN ($placeholders) AND company_id = ? ORDER BY gr_number ASC";
  $stmt = $conn->prepare($sql);
  $types = str_repeat('i', count($biltyIds)) . 'i';
  $params = array_merge($biltyIds, [$company_id]);
  $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($row = $res->fetch_assoc()) {
    $bilties[] = $row;
  }
  $stmt->close();
} elseif ($bilty_id) {
  $stmt = $conn->prepare("SELECT * FROM biltys WHERE id = ? AND company_id = ?");
  $stmt->bind_param("ii", $bilty_id, $company_id);
  $stmt->execute();
  $res = $stmt->get_result();
  if ($res && $res->num_rows > 0) {
    $bilties[] = $res->fetch_assoc();
  }
  $stmt->close();
} elseif (!empty($gr_number)) {
  $stmt = $conn->prepare("SELECT * FROM biltys WHERE gr_number = ? AND company_id = ?");
  $stmt->bind_param("si", $gr_number, $company_id);
  $stmt->execute();
  $res = $stmt->get_result();
  if ($res && $res->num_rows > 0) {
    $bilties[] = $res->fetch_assoc();
  }
  $stmt->close();
}

if (empty($bilties)) {
  echo "<h3 style='text-align:center;margin-top:50px;'>Bilty not found</h3>";
  exit;
}

// Fetch company names for the bilties to display badges
$biltyCompanyIds = array_values(array_unique(array_column($bilties, 'company_id')));
$companyNames = [];
if (!empty($biltyCompanyIds)) {
  $placeholders = implode(',', array_fill(0, count($biltyCompanyIds), '?'));
  $sql = "SELECT id, branch FROM company WHERE id IN ($placeholders)";
  $stmt = $conn->prepare($sql);
  $types = str_repeat('i', count($biltyCompanyIds));
  $stmt->bind_param($types, ...$biltyCompanyIds);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($row = $res->fetch_assoc()) {
    $companyNames[$row['id']] = $row['branch'];
  }
  $stmt->close();
}

// Fetch items for all selected bilties
$itemsByBilty = [];
$biltyIdsForItems = array_column($bilties, 'id');
if (!empty($biltyIdsForItems)) {
  $placeholders = implode(',', array_fill(0, count($biltyIdsForItems), '?'));
  $sql = "SELECT * FROM bilty_items WHERE bilty_id IN ($placeholders) ORDER BY COALESCE(item_number, 0) ASC, id ASC";
  $stmtItems = $conn->prepare($sql);
  $types = str_repeat('i', count($biltyIdsForItems));
  $stmtItems->bind_param($types, ...$biltyIdsForItems);
  $stmtItems->execute();
  $itemsRes = $stmtItems->get_result();
  while ($row = $itemsRes->fetch_assoc()) {
    $itemsByBilty[$row['bilty_id']][] = $row;
  }
  $stmtItems->close();
}

// Payment type mapping helper
$paymentTypeMap = [
  'topay' => 'Topay',
  'cash' => 'Cash',
  'tbb' => 'TBB',
];

function displayCharge($value, $hideCharges) {
  return $hideCharges ? '00' : number_format((float)$value, 0);
}
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
        font-weight: 500 !important;
        background: #fff !important;
        margin: 0;
        padding: 0;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
        height: auto;
      }

      .notification,
      script {
        display: none !important;
      }

      .bilty-print {
        page-break-after: always;
        page-break-inside: avoid;
        margin: 0;
        padding: 0;
        position: relative;
      }

      .bilty-print:last-child {
        page-break-after: avoid !important;
      }
    }
  </style>

  <!-- Page title -->
  <title>Bilty-Paid</title>
</head>

<body>

  <?php foreach ($bilties as $bilty):
    $items = $itemsByBilty[$bilty['id']] ?? [];
    $displayDate = isset($bilty['bilty_date']) ? date('d/m/Y', strtotime($bilty['bilty_date'])) : (isset($bilty['created_at']) ? date('d/m/Y H:i', strtotime($bilty['created_at'])) : '');
    $itemCount = !empty($bilty['total_qty']) ? $bilty['total_qty'] : array_reduce($items, function($acc, $it){ return $acc + (int)($it['quantity'] ?? 0); }, 0);
    $totalWeight = !empty($bilty['total_weight']) ? $bilty['total_weight'] : array_reduce($items, function($acc, $it){ return $acc + (int) round((float)($it['weight'] ?? 0)); }, 0);
    $biltyCompanyId = $bilty['company_id'] ?? '102';
    $biltyCompanyName = $companyNames[$biltyCompanyId] ?? ('Company ' . $biltyCompanyId);
    $paymentTypeRaw = $bilty['payment_type'] ?? '';
    $paymentTypeNormalized = strtolower(trim($paymentTypeRaw));
    $paymentLabel = $paymentTypeMap[$paymentTypeNormalized] ?? ($paymentTypeRaw !== '' ? $paymentTypeRaw : 'Topay');
    $isHideCharges = in_array($paymentTypeNormalized, ['tbb', 'cash'], true);
  ?>

  <?php for ($copy = 1; $copy <= 2; $copy++): ?>
  <!-- =======================
       MAIN BILTY CONTAINER
  ======================== -->
  <div class="bilty bilty-print" style="position: relative;">
  <div style="position: absolute; top: 5px; left: 10px; font-size: 16px; font-weight: bold; z-index: 10;"> <?= ($copy === 1) ? 'Consignor Copy' : 'Consignee Copy' ?></div>
  <!-- <div style="position: absolute; top: 5px; left: 10px; font-size: 16px; font-weight: bold; z-index: 10;"><? //= ($copy === 1) ? 'Consignor Copy' : (($copy === 2) ? 'Consignee Copy' : 'Branch Copy') ?></div> -->
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
            <td class="v big"><?= (htmlspecialchars($displayDate)) ?></td>
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
          <li><strong>Bakani</strong><br>9001340509</li>
          <li><strong>Ratlai</strong><br>9636008813</li>
          <li><strong>Raipur</strong><br>9950249207</li>
          <li><strong>Soyatkalan</strong><br>9950249207</li>
          <li><strong>Sunel</strong><br>9829220540</li>
          <li><strong>Pidawa</strong><br>9460518506</li>
          <li><strong>Hemda</strong><br>9460518506</li>
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
            <div class="item-desc"><?= htmlspecialchars(number_format((float)$totalWeight, 2)) ?> KG</div>
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
            <div class="c-value"><?= htmlspecialchars(displayCharge($bilty['freight'] ?? 0, $isHideCharges)) ?></div>
          </div>

          <div class="charges-row">
            <div class="c-label">Hammali</div>
            <div class="c-value"><?= htmlspecialchars(displayCharge($bilty['hammali'] ?? 0, $isHideCharges)) ?></div>
          </div>

          <div class="charges-row">
            <div class="c-label">P. Freight</div>
            <div class="c-value"><?= htmlspecialchars(displayCharge($bilty['p_freight'] ?? 0, $isHideCharges)) ?></div>
          </div>

          <div class="charges-row">
            <div class="c-label">Brokerage</div>
            <div class="c-value"><?= htmlspecialchars(displayCharge($bilty['brokerage'] ?? 0, $isHideCharges)) ?></div>
          </div>

          <div class="charges-row">
            <div class="c-label">DD Charge</div>
            <div class="c-value"><?= htmlspecialchars(displayCharge($bilty['dd_charge'] ?? 0, $isHideCharges)) ?></div>
          </div>

          <div class="charges-row">
            <div class="c-label">GR Charge</div>
            <div class="c-value"><?= htmlspecialchars(displayCharge($bilty['gr_charge'] ?? 0, $isHideCharges)) ?></div>
          </div>

          <!-- Total -->
          <div class="charges-row total-row">
            <div class="c-label">Total</div>
            <div class="c-value"><?= htmlspecialchars(displayCharge($bilty['total_charge'] ?? 0, $isHideCharges)) ?></div>
          </div>

          <!-- Paid / To pay -->
          <div class="charges-row">
            <div class="c-value full"><?= htmlspecialchars($paymentLabel) ?></div>
          </div>

        </div>
      </div>

    </section>


  </div>
  <?php endfor; ?>
  <?php endforeach; ?>

  <!-- Notification System -->
  <script>
    // Auto print when page loads
    window.addEventListener('load', () => {
      setTimeout(() => {
        window.print();
      }, 100);
    });

    // After print dialog closes (Print or Cancel), go back to existing page and refresh it.
    function returnToExistingPage() {
      const fallbackUrl = '../filter/';

      // If this print page runs inside an iframe, notify parent and stop redirects.
      if (window.parent && window.parent !== window) {
        try {
          window.parent.postMessage({ type: 'bilty-print-complete' }, window.location.origin);
        } catch (error) {
          // Ignore and continue with fallback logic.
        }
        return;
      }

      // If opened from another page via window.open, refresh that page and close print tab.
      if (window.opener && !window.opener.closed) {
        try {
          window.opener.location.reload();
          window.opener.focus();
          window.close();
          return;
        } catch (error) {
          // If opener is not accessible for any reason, fallback to referrer redirect.
        }
      }

      // If no opener, redirect this page back to referrer and force fresh load.
      if (document.referrer) {
        const separator = document.referrer.includes('?') ? '&' : '?';
        window.location.href = document.referrer + separator + 'refresh=' + Date.now();
        return;
      }

      // Final fallback.
      window.location.href = fallbackUrl;
    }

    window.addEventListener('afterprint', returnToExistingPage);
  </script>

</body>

</html>