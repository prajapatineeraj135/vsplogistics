<?php
include "../protect/db.php";

ensureColumn($conn, 'products', 'rate_basis', "VARCHAR(20) NOT NULL DEFAULT 'Nag' AFTER weight");
ensureColumn($conn, 'party_products', 'rate_basis', "VARCHAR(20) NOT NULL DEFAULT 'Nag' AFTER weight");

function ensureColumn($conn, $table, $column, $definition) {
    $check = $conn->query("SHOW COLUMNS FROM {$table} LIKE '{$column}'");
    if ($check && $check->num_rows > 0) {
        return;
    }
    $conn->query("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
}

if (!isset($_GET['party_id'])) {
    // Redirect to central error page with a user-friendly message.
    app_report_error('Validation error', 'Party ID is required', 'web', 400);
}
$party_id = (int) $_GET['party_id'];

$party = $conn->query("SELECT * FROM party WHERE id=$party_id")->fetch_assoc();
$product_list = $conn->query("SELECT * FROM products ORDER BY product_type ASC");
$party_products = $conn->query("
    SELECT * FROM party_products 
    WHERE party_id = $party_id
");


/* =====================
   EDIT
===================== */
$edit = [];
if (isset($_GET['edit'])) {
    $id = (int) $_GET['edit'];
    $edit = $conn->query("SELECT * FROM party_products WHERE id=$id")->fetch_assoc();
}

/* =====================
   DELETE
===================== */
if (isset($_GET['delete'])) {
    $id = (int) $_GET['delete'];
    $conn->query("DELETE FROM party_products WHERE id=$id");
    header("Location: party_product.php?party_id=$party_id&status=4");
    exit;
}

$status = isset($_GET['status']) ? (string) $_GET['status'] : '';

?>


<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>Create Party Products</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">

    <style>
    
        * {
            text-transform: capitalize;
        }
        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 6px 15px rgba(0, 0, 0, .08);
        }

        span {
            text-align: center;
            padding: 2px 15px;
            background-color: transparent;
            color: black;
            border-radius: 8px;
            cursor: pointer;
        }


        .table th,
        .table td {
            font-size: 15px;
            padding: 4px;
        }

        .party-table-wrap {
            overflow-x: auto;
        }

        .party-table-wrap-form {
            overflow: visible;
        }

        .party-product-table {
            min-width: 980px;
            table-layout: auto;
        }

        .party-product-table th,
        .party-product-table td {
            vertical-align: middle;
        }

        .party-product-table .col-product {
            width: 26%;
            white-space: normal;
            word-break: break-word;
        }

        .party-product-table .col-type,
        .party-product-table .col-category {
            width: 14%;
            white-space: normal;
        }

        .party-product-table .col-basis,
        .party-product-table .col-rate,
        .party-product-table .col-weight {
            width: 15%;
            white-space: nowrap;
        }

        .party-product-table .col-action {
            width: 16%;
            white-space: nowrap;
        }

        .product-suggestions {
            position: absolute;
            top: calc(100% + 2px);
            left: 0;
            right: 0;
            background: #fff;
            border: 1px solid #ced4da;
            border-radius: 0 0 6px 6px;
            z-index: 3000;
            max-height: 200px;
            overflow-y: auto;
            display: none;
            box-shadow: 0 4px 10px rgba(0,0,0,.1);
        }
        .product-suggestions .s-item {
            padding: 5px 10px;
            cursor: pointer;
            font-size: 12px;
        }
        .product-suggestions .s-item:hover,
        .product-suggestions .s-item.active {
            background: #d1fae5;
        }
        .product-suggestions .s-none {
            padding: 5px 10px;
            font-size: 12px;
            color: #999;
        }

        .party-form-actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            flex-wrap: wrap;
        }

        .party-form-actions-left,
        .party-form-actions-right {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
    </style>
</head>

<body>
    <?php include "../content/nav.php"; ?>

    <?php if ($status !== ''): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const status = <?= json_encode($status) ?>;

                if (status === '1' && typeof showSave === 'function') {
                    showSave('Products saved successfully');
                }

                if (status === '2' && typeof showWarning === 'function') {
                    showWarning('No product rows found to save');
                }

                if (status === '3' && typeof showUpdate === 'function') {
                    showUpdate('Products updated successfully');
                }

                if (status === '4' && typeof showDelete === 'function') {
                    showDelete('Product deleted successfully');
                }

                if (status === '5' && typeof showUpdate === 'function') {
                    showUpdate('Products saved and updated successfully');
                }

                if (status === '0' && typeof showError === 'function') {
                    showError('Could not save products. Please try again.');
                }

                if (window.history.replaceState) {
                    const url = new URL(window.location.href);
                    url.searchParams.delete('status');
                    const clean = url.pathname + (url.searchParams.toString() ? '?' + url.searchParams.toString() : '') + url.hash;
                    window.history.replaceState({}, document.title, clean);
                }
            });
        </script>
    <?php endif; ?>

    <div class="container my-3">

        <div class="row g-3 justify-content-center">

            <!-- ================= LEFT : FORM ================= -->
            <div class="col-md-10">

                <form method="post" action="party_product_save.php">

                    <div class="card mb-2">
                        <div class="card-body">

                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                                <h4 class="fw-bold mb-0">
                                    <i class="bi bi-box-seam"></i> Add Products for <span style=" background-color: #000000a5; padding: 5px 15px; color: white;"><?= htmlspecialchars($party['name']) ?></span>
                                </h4>

                                
                            </div>

                            <input type="hidden" name="party_id" value="<?= $party_id ?>">

                            <?php

                            $productDataJs = [];

                            while ($p = $product_list->fetch_assoc()) {
                                $productDataJs[$p['product_name']] = [
                                    'type'     => $p['product_type'],
                                    'category' => $p['product_category'],
                                    'rate'     => $p['rate'],
                                    'weight'   => $p['weight'],
                                    'rate_basis' => $p['rate_basis'] ?? 'Nag',
                                ];
                            }
                            ?>



                            <div class="party-table-wrap party-table-wrap-form">
                            <table class="table table-bordered party-product-table" id="productTable">
                                <thead class="table-light">
                                    <tr>
                                        <th class="col-product">Product</th>
                                        <th class="col-type">Type</th>
                                        <th class="col-category">Category</th>
                                        <th class="col-basis">Rate Basis</th>
                                        <th class="col-rate">Rate</th>
                                        <th class="col-weight">Weight</th>
                                        <th class="col-action">Action</th>
                                    </tr>
                                </thead>

                                <tbody>
                                    <tr>
                                        <!-- 🔑 product id for update -->
                                        <input type="hidden" name="product_id[]" value="<?= $edit['id'] ?? '' ?>">

                                        <td class="col-product" style="position:relative">
                                            <input type="text" name="product_name[]"
                                                class="form-control product-search-input"
                                                oninput="showSuggestions(this)"
                                                onkeydown="navigateSuggestions(event, this)"
                                                onblur="hideSuggestions(this)"
                                                value="<?= htmlspecialchars($edit['product_name'] ?? '') ?>"
                                                placeholder="Search product..." required autocomplete="off">
                                            <div class="product-suggestions"></div>
                                        </td>

                                        <td class="col-type">
                                            <input name="product_type[]" class="form-control"
                                                value="<?= $edit['product_type'] ?? '' ?>">
                                        </td>

                                        <td class="col-category">
                                            <input name="product_category[]" class="form-control"
                                                value="<?= $edit['product_category'] ?? '' ?>">
                                        </td>

                                        <td class="col-basis">
                                            <?php $editBasis = ($edit['rate_basis'] ?? 'Nag') === 'Weight' ? 'Weight' : 'Nag'; ?>
                                            <select name="rate_basis[]" class="form-control">
                                                <option value="Nag" <?= $editBasis === 'Nag' ? 'selected' : '' ?>>Per Nag</option>
                                                <option value="Weight" <?= $editBasis === 'Weight' ? 'selected' : '' ?>>Per Quintle</option>
                                            </select>
                                        </td>

                                        <td class="col-rate">
                                            <input name="rate[]" type="text" pattern="[0-9]+" step="1" class="form-control"
                                                value="<?= $edit['rate'] ?? '' ?>">
                                        </td>

                                        <td class="col-weight">
                                            <input name="weight[]" type="text" pattern="[0-9]+" step="1" class="form-control"
                                                value="<?= $edit['weight'] ?? '' ?>">
                                        </td>

                                        <td class="text-center col-action">
                                            <button type="button" class="btn btn-danger btn-sm"
                                                onclick="removeRow(this)">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>

                            </table>
                            </div>

                            <div class="party-form-actions mt-3">
                                <div class="party-form-actions-left">
                                    <button type="submit" name="save" class="btn <?= isset($edit['id']) ? 'btn-warning' : 'btn-success' ?> btn-sm">
                                        <i class="bi <?= isset($edit['id']) ? 'bi-pencil' : 'bi-save' ?>"></i> <?= isset($edit['id']) ? 'Update' : 'Save' ?>
                                    </button>

                                    <?php if (isset($edit['id'])) { ?>
                                        <a href="party_product.php?party_id=<?= $party_id ?>" class="btn btn-danger btn-sm">Cancel</a>
                                    <?php } else { ?>
                                        <a href="index.php?view=list" class="btn btn-danger btn-sm">Cancel</a>
                                    <?php } ?>
                                </div>

                                <div class="party-form-actions-right">
                                    <button type="button" class="btn btn-primary btn-sm" onclick="addRow()">
                                        <i class="bi bi-plus"></i> Add Product
                                    </button>
                                </div>
                            </div>

                            
                                

                        </div>
                    </div>
                </form>
            </div>

            <div class="w-100"></div>

            <!-- ================= RIGHT : LIST ================= -->
            <div class="col-md-10">

                <div class="card">
                    <div class="card-body">

                        <h5 class="fw-bold mb-2">
                            <i class="bi bi-list-ul"></i> Existing Products
                        </h5>

                        <div class="party-table-wrap party-table-wrap-list">
                        <table class="table table-bordered table-sm party-product-table">
                            <thead class="table-light">
                                <tr>
                                    <th>Sr</th>
                                    <th class="col-product">Product</th>
                                    <th class="col-type">Type</th>
                                    <th class="col-category">Category</th>
                                    <th class="col-basis">Rate Basis</th>
                                    <th class="col-rate">Rate</th>
                                    <th class="col-weight">Weight</th>
                                    <th class="col-action">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $i = 1;
                                while ($product = $party_products->fetch_assoc()) { ?>
                                    <tr>
                                        <td><?= $i++ ?></td>
                                        <td class="col-product"><?= htmlspecialchars($product['product_name']) ?></td>
                                        <td class="col-type"><?= htmlspecialchars($product['product_type']) ?></td>
                                        <td class="col-category"><?= htmlspecialchars($product['product_category']) ?></td>
                                        <td class="col-basis"><?= (($product['rate_basis'] ?? 'Nag') === 'Weight') ? 'Per Quintle' : 'Per Nag' ?></td>

                                        <td class="col-rate"><?= $product['rate'] ?></td>
                                        <td class="col-weight"><?= $product['weight'] ?></td>
                                        <td class="col-action" style="display: grid; grid-template-columns: 1fr 1fr; gap: 4px;">
                                            <a href="?party_id=<?= $party_id ?>&edit=<?= $product['id'] ?>"
                                                class="btn btn-warning btn-sm">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                            <a href="?party_id=<?= $party_id ?>&delete=<?= $product['id'] ?>"
                                                onclick="nmNavConfirm(event,'Delete this Product From List?')"
                                                class="btn btn-danger btn-sm">
                                                <i class="bi bi-trash"></i>
                                            </a>

                                        </td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                        </div>

                    </div>
                </div>

            </div>
        </div>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>

        <script>
            const productData = <?= json_encode($productDataJs) ?>;
            const productNames = Object.keys(productData);

            function navigateSuggestions(e, input) {
                const box = input.nextElementSibling;
                if (box.style.display === 'none') return;
                const items = box.querySelectorAll('.s-item');
                if (!items.length) return;
                let active = box.querySelector('.s-item.active');
                let idx = active ? Array.from(items).indexOf(active) : -1;

                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    if (active) active.classList.remove('active');
                    idx = (idx + 1) % items.length;
                    items[idx].classList.add('active');
                    items[idx].scrollIntoView({ block: 'nearest' });
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    if (active) active.classList.remove('active');
                    idx = (idx - 1 + items.length) % items.length;
                    items[idx].classList.add('active');
                    items[idx].scrollIntoView({ block: 'nearest' });
                } else if (e.key === 'Enter') {
                    if (active) {
                        e.preventDefault();
                        input.value = active.textContent;
                        box.style.display = 'none';
                        fillProductDetails(input);
                    }
                } else if (e.key === 'Escape') {
                    box.style.display = 'none';
                }
            }

            function fillProductDetails(input) {
                const data = productData[input.value] || {};
                const row = input.closest("tr");
                row.querySelector('[name="product_type[]"]').value = data.type || '';
                row.querySelector('[name="product_category[]"]').value = data.category || '';
                row.querySelector('[name="rate_basis[]"]').value = data.rate_basis === 'Weight' ? 'Weight' : 'Nag';
                row.querySelector('[name="rate[]"]').value = data.rate || '';
                row.querySelector('[name="weight[]"]').value = data.weight || '';
            }

            function showSuggestions(input) {
                const val = input.value.trim().toLowerCase();
                const box = input.nextElementSibling;
                if (!val) { box.style.display = 'none'; return; }
                const matches = productNames.filter(n => n.toLowerCase().includes(val));
                if (!matches.length) {
                    box.innerHTML = '<div class="s-none">No match</div>';
                } else {
                    box.innerHTML = matches.map(n =>
                        `<div class="s-item" onmousedown="selectProduct(event, this)">${n}</div>`
                    ).join('');
                }
                box.style.display = 'block';
            }

            function selectProduct(e, item) {
                e.preventDefault();
                const td = item.closest('td');
                const input = td.querySelector('[name="product_name[]"]');
                const box = td.querySelector('.product-suggestions');
                input.value = item.textContent;
                box.style.display = 'none';
                fillProductDetails(input);
            }

            function hideSuggestions(input) {
                const box = input.nextElementSibling;
                setTimeout(() => { box.style.display = 'none'; }, 150);
            }

            function addRow() {
                document.querySelector("#productTable tbody").insertAdjacentHTML("beforeend", `
<tr>
<input type="hidden" name="product_id[]" value="">
<td class="col-product" style="position:relative">
<input type="text" name="product_name[]" class="form-control product-search-input"
    oninput="showSuggestions(this)" onkeydown="navigateSuggestions(event, this)" onblur="hideSuggestions(this)"
    placeholder="Search product..." required autocomplete="off">
<div class="product-suggestions"></div>
</td>
<td class="col-type"><input name="product_type[]" class="form-control"></td>
<td class="col-category"><input name="product_category[]" class="form-control"></td>
<td class="col-basis">
    <select name="rate_basis[]" class="form-control">
        <option value="Nag">Per Nag</option>
        <option value="Weight">Per Quintle</option>
    </select>
</td>
<td class="col-rate"><input name="rate[]" type="number" class="form-control"></td>
<td class="col-weight"><input name="weight[]" type="number" class="form-control"></td>
<td class="text-center col-action">
<button type="button" class="btn btn-danger btn-sm" onclick="removeRow(this)">
<i class="bi bi-trash"></i>
</button>
</td>
</tr>`);
            }


            function removeRow(btn) {
                const tbody = document.querySelector("#productTable tbody");
                if (tbody.rows.length > 1) btn.closest("tr").remove();
            }
        </script>

</body>

</html>