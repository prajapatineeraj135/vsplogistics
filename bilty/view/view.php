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
$bilty_id = isset($_GET['id']) ? intval($_GET['id']) : 6628;
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

  <!-- Print Styles -->
  
  <!-- Page title -->
  <title>test-Bilty-View</title>
</head>
<style>

  .bilty {
    margin-left: auto;
    margin-right: auto;
    height: 14.8cm;
    width: 21cm;
    display: block;
    background-image: url("/company/bilty/view/Bilty.svg");
    background-position: center;
    background-size: 100% 100%;
    background-repeat: no-repeat;
  }
</style>
<body>

  <!-- =======================
       MAIN BILTY CONTAINER
  ======================== -->
  <div class="bilty">




  </div>

  <!-- Notification System -->
  

</body>

</html>
