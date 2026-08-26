<?php
/**
 * Permanent Delete Bilty Handler
 * Permanently deletes a bilty and its items from the database
 */

header('Content-Type: application/json');
include "../../protect/auth.php";
include "../../protect/db.php";

$company_id = $_SESSION['company_id'] ?? '';

if ($company_id === '') {
  echo json_encode(['success' => false, 'message' => 'Session expired. Please login again.']);
  exit;
}

// Accept either single id, comma-separated ids, or ids[]
$rawIds = [];
if (isset($_POST['ids']) && is_array($_POST['ids'])) {
  $rawIds = $_POST['ids'];
} elseif (isset($_POST['ids'])) {
  $rawIds = explode(',', (string) $_POST['ids']);
} elseif (isset($_POST['id'])) {
  $rawIds = [$_POST['id']];
}

$ids = [];
foreach ($rawIds as $raw) {
  $id = intval($raw);
  if ($id > 0) {
    $ids[$id] = $id;
  }
}
$ids = array_values($ids);

if (empty($ids)) {
  echo json_encode(['success' => false, 'message' => 'Invalid bilty ID(s)']);
  exit;
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$selectSql = "SELECT id FROM biltys WHERE company_id = ? AND status = 'Trash' AND id IN ($placeholders)";
$selectStmt = $conn->prepare($selectSql);

if (!$selectStmt) {
  echo json_encode(['success' => false, 'message' => 'Failed to prepare validation query']);
  exit;
}

$selectTypes = 's' . str_repeat('i', count($ids));
$selectParams = array_merge([$company_id], $ids);
$selectStmt->bind_param($selectTypes, ...$selectParams);
$selectStmt->execute();
$validResult = $selectStmt->get_result();
$validIds = [];
while ($row = $validResult->fetch_assoc()) {
  $validIds[] = intval($row['id']);
}
$selectStmt->close();

if (empty($validIds)) {
  echo json_encode(['success' => false, 'message' => 'No trash bilty found for permanent delete']);
  exit;
}

$validPlaceholders = implode(',', array_fill(0, count($validIds), '?'));

// Start transaction
$conn->begin_transaction();

try {
  // Delete bilty items first (foreign key constraint)
  $stmtItems = $conn->prepare("DELETE FROM bilty_items WHERE bilty_id IN ($validPlaceholders)");
  $itemsTypes = str_repeat('i', count($validIds));
  $stmtItems->bind_param($itemsTypes, ...$validIds);
  $stmtItems->execute();
  $stmtItems->close();

  // Delete trash bilty rows for this company only
  $stmtBilty = $conn->prepare("DELETE FROM biltys WHERE company_id = ? AND status = 'Trash' AND id IN ($validPlaceholders)");
  $biltyTypes = 's' . str_repeat('i', count($validIds));
  $biltyParams = array_merge([$company_id], $validIds);
  $stmtBilty->bind_param($biltyTypes, ...$biltyParams);
  $stmtBilty->execute();
  $affected = $stmtBilty->affected_rows;
  $stmtBilty->close();

  // Commit transaction
  $conn->commit();

  $total = count($ids);
  if ($total > 1) {
    echo json_encode([
      'success' => true,
      'message' => "Permanently deleted {$affected}/{$total} bilty(s)",
      'affected' => $affected,
      'requested' => $total
    ]);
  } else {
    echo json_encode(['success' => true, 'message' => 'Bilty permanently deleted']);
  }
} catch (Exception $e) {
  // Rollback on error
  $conn->rollback();
  echo json_encode(['success' => false, 'message' => 'Failed to delete bilty: ' . $e->getMessage()]);
}

$conn->close();
?>
