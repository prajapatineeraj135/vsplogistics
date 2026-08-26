<?php
/**
 * Delete Bilty Handler
 * Marks a bilty as 'Trash' and returns JSON response
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
$sql = "UPDATE biltys
        SET status = 'Trash'
        WHERE company_id = ?
          AND id IN ($placeholders)
          AND status IN ('Booked', 'Dispatch', 'Deliver')";

$stmt = $conn->prepare($sql);
if (!$stmt) {
  echo json_encode(['success' => false, 'message' => 'Failed to prepare delete query']);
  exit;
}

$types = 's' . str_repeat('i', count($ids));
$bindParams = array_merge([$company_id], $ids);
$stmt->bind_param($types, ...$bindParams);
$ok = $stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();
$conn->close();

if (!$ok) {
  echo json_encode(['success' => false, 'message' => 'Failed to move bilty to trash']);
  exit;
}

if ($affected <= 0) {
  echo json_encode(['success' => false, 'message' => 'No eligible bilty found for delete']);
  exit;
}

$total = count($ids);
if ($total > 1) {
  echo json_encode([
    'success' => true,
    'message' => "Moved {$affected}/{$total} bilty(s) to trash",
    'affected' => $affected,
    'requested' => $total
  ]);
} else {
  echo json_encode(['success' => true, 'message' => 'Bilty moved to trash successfully']);
}
?>
