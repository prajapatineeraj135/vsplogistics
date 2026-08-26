<?php
include "../protect/db.php";

ensureColumn($conn, 'party_products', 'rate_basis', "VARCHAR(20) NOT NULL DEFAULT 'Nag' AFTER weight");

function ensureColumn($conn, $table, $column, $definition) {
    $check = $conn->query("SHOW COLUMNS FROM {$table} LIKE '{$column}'");
    if ($check && $check->num_rows > 0) {
        return;
    }
    $conn->query("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!isset($_POST['party_id'])) {
        // Use centralized error handling instead of abrupt plain-text stop.
        app_report_error('Validation error', 'Party ID missing', 'web', 400);
    }

    $party_id = (int) $_POST['party_id'];

    $product_id       = $_POST['product_id'] ?? [];
    $product_name     = $_POST['product_name'] ?? [];
    $product_type     = $_POST['product_type'] ?? [];
    $product_category = $_POST['product_category'] ?? [];
    $rate_basis       = $_POST['rate_basis'] ?? [];
    $rate             = $_POST['rate'] ?? [];
    $weight           = $_POST['weight'] ?? [];

    /* PREPARE INSERT */
    $insert = $conn->prepare("
        INSERT INTO party_products
        (party_id, product_name, product_type, product_category, rate_basis, rate, weight)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    /* PREPARE UPDATE */
    $update = $conn->prepare("
        UPDATE party_products SET
            product_name = ?,
            product_type = ?,
            product_category = ?,
            rate_basis = ?,
            rate = ?,
            weight = ?
        WHERE id = ? AND party_id = ?
    ");

    $insertedCount = 0;
    $updatedCount = 0;
    $hasError = false;

    for ($i = 0; $i < count($product_name); $i++) {

        if (trim($product_name[$i]) === '') {
            continue;
        }

        $basis = (isset($rate_basis[$i]) && $rate_basis[$i] === 'Weight') ? 'Weight' : 'Nag';

        // 👉 UPDATE (Edit)
        if (!empty($product_id[$i])) {

            $update->bind_param(
                "ssssddii",
                $product_name[$i],
                $product_type[$i],
                $product_category[$i],
                $basis,
                $rate[$i],
                $weight[$i],
                $product_id[$i],
                $party_id
            );
            if (!$update->execute()) {
                $hasError = true;
                break;
            }
            $updatedCount++;

        } 
        // 👉 INSERT (New)
        else {

            $insert->bind_param(
                "issssdd",
                $party_id,
                $product_name[$i],
                $product_type[$i],
                $product_category[$i],
                $basis,
                $rate[$i],
                $weight[$i]
            );
            if (!$insert->execute()) {
                $hasError = true;
                break;
            }
            $insertedCount++;
        }
    }

    $insert->close();
    $update->close();

    if ($hasError) {
        header("Location: party_product.php?party_id=$party_id&status=0");
        exit;
    }

    $status = '2'; // No valid rows
    if ($insertedCount > 0 && $updatedCount > 0) {
        $status = '5';
    } elseif ($insertedCount > 0) {
        $status = '1';
    } elseif ($updatedCount > 0) {
        $status = '3';
    }

    header("Location: party_product.php?party_id=$party_id&status=$status");
    exit;
}
?>
