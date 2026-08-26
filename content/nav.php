<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$isUser = !empty($_SESSION['company_id']);
$isAdmin = !empty($_SESSION['admin_login']);

if (!$isUser && !$isAdmin) {
    header("Location: ./login");
    exit;
}

$userLabel = $isAdmin ? "Admin Account" : "Company Account";
$base = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
?>

<link rel="stylesheet" href="<?= $base ?>/content/notify.css">
<script src="<?= $base ?>/content/notify.js"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css">

<style>
  .simple-nav {
    position: sticky;
    top: 0;
    z-index: 1000;
    background: linear-gradient(90deg, #0f172a 0%, #1d4ed8 55%, #7c3aed 100%);
    color: #fff;
    box-shadow: 0 10px 24px rgba(15, 23, 42, .18);
  }

  .simple-nav__inner {
    max-width: 100%;
    margin: 0 auto;
    padding: 10px 16px;
    display: flex;
    align-items: center;
    gap: 12px;
  }

  .simple-nav__brand {
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 800;
    font-size: 18px;
    letter-spacing: .2px;
    white-space: nowrap;
  }

  .simple-nav__brand-badge {
    width: 36px;
    height: 36px;
    border-radius: 12px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: rgba(255,255,255,.14);
    border: 1px solid rgba(255,255,255,.18);
  }

  .simple-nav__toggle {
    display: none;
  }

  .simple-nav__burger {
    margin-left: auto;
    display: none;
    border: 1px solid rgba(255,255,255,.25);
    background: rgba(255,255,255,.08);
    color: #fff;
    border-radius: 10px;
    padding: 8px 12px;
    cursor: pointer;
  }

  .simple-nav__links {
    margin-left: auto;
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
  }

  .simple-nav__link,
  .simple-nav__summary {
    color: #fff;
    text-decoration: none;
    border: 0;
    background: transparent;
    border-radius: 10px;
    padding: 2px 4px;
    font-size: 16px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    letter-spacing: .2px;
    transition: background .18s ease, color .18s ease, transform .18s ease;
  }

  .simple-nav__link:hover,
  .simple-nav__summary:hover {
    background: rgba(255,255,255,.12);
    color: #fef08a;
  }

  .simple-nav__link--danger {
    background: rgba(239, 68, 68, .18);
  }

  .simple-nav__dropdown {
    position: relative;
  }

  .simple-nav__summary {
    list-style: none;
    cursor: pointer;
  }

  .simple-nav__summary::-webkit-details-marker {
    display: none;
  }

  .simple-nav__summary::after {
    content: "";
    margin-right: 2px;
    border-top: 4px solid currentColor;
    border-left: 4px solid transparent;
    border-right: 4px solid transparent;
    transform: translateY(1px);
  }

  .simple-nav__menu {
    display: none;
    position: absolute;
    top: calc(100% + 8px);
    right: -50px;
    min-width: 125px;
    width: max-content;
    max-width: 260px;
    background: #fff;
    border-radius: 14px;
    padding: 6px;
    box-shadow: 0 18px 30px rgba(15, 23, 42, .18);
    border: 1px solid rgba(148, 163, 184, .25);
  }

  .simple-nav__dropdown--left .simple-nav__menu {
    right: 0;
    left: auto;
  }

  .simple-nav__dropdown--center .simple-nav__menu {
    right: 50%;
    left: auto;
    transform: translateX(-50%);
  }

  .simple-nav__dropdown--right .simple-nav__menu {
    right: 0;
    left: auto;
    transform: none;
  }

  .simple-nav__dropdown[open] .simple-nav__menu {
    display: block;
  }

  .simple-nav__item {
    display: block;
    padding: 8px 12px;
    border-radius: 10px;
    color: #0f172a;
    text-decoration: none;
    font-size: 15px;
    font-weight: 600;
    letter-spacing: .2px;
    white-space: nowrap;
  }

  .simple-nav__item:hover {
    background: #eff6ff;
    color: #1d4ed8;
  }

  .simple-nav__item i,
  .simple-nav__link i,
  .simple-nav__summary i {
    font-size: 0.95em;
  }

  .simple-nav__link i,
  .simple-nav__summary i {
    min-width: 1.05em;
    text-align: center;
  }

  .simple-nav__item i {
    min-width: 1.1em;
    text-align: center;
    margin-right: 4px;
  }

  @media (max-width: 980px) {
    .simple-nav__inner {
      flex-wrap: wrap;
    }

    .simple-nav__burger {
      display: inline-flex;
      align-items: center;
      gap: 8px;
    }

    .simple-nav__links {
      display: none;
      width: 100%;
      margin-left: 0;
      flex-direction: column;
      align-items: stretch;
      gap: 8px;
      padding-top: 10px;
    }

    .simple-nav__toggle:checked ~ .simple-nav__links {
      display: flex;
    }

    .simple-nav__link,
    .simple-nav__summary {
      width: 100%;
      justify-content: flex-start;
    }

    .simple-nav__menu {
      position: static;
      width: 100%;
      margin-top: 6px;
    }
  }
</style>

<nav class="simple-nav">
  <div class="simple-nav__inner">
    <div class="simple-nav__brand">
      <span class="simple-nav__brand-badge"</i></span>
      <span><?= htmlspecialchars($userLabel) ?></span>
    </div>

    <label for="simple-nav-toggle" class="simple-nav__burger">
      <i class="bi bi-list"></i> Menu
    </label>
    <input type="checkbox" id="simple-nav-toggle" class="simple-nav__toggle">

    <div class="simple-nav__links">
      <a class="simple-nav__link" href="<?= $isAdmin ? $base . '/' : BASE_URL; ?>">
        <i class="bi bi-house"></i>Dashboard
      </a>

      <details class="simple-nav__dropdown">
        <summary class="simple-nav__summary">Bilty</summary>
        <div class="simple-nav__menu">
          <a class="simple-nav__item" href="<?= BASE_URL; ?>/bilty/create"><i class="bi bi-plus-circle"></i>Book</a>
          <a class="simple-nav__item" href="<?= BASE_URL; ?>/bilty/filter"><i class="bi bi-search"></i>Search</a>
          <a class="simple-nav__item" href="<?= BASE_URL; ?>/bilty/export?tool=import"><i class="bi bi-upload"></i>Import</a>
          <a class="simple-nav__item" href="<?= BASE_URL; ?>/bilty/export?tool=export"><i class="bi bi-download"></i>Export</a>
        </div>
      </details>

      <details class="simple-nav__dropdown simple-nav__dropdown--right">
        <summary class="simple-nav__summary">Challan</summary>
        <div class="simple-nav__menu">
          <a class="simple-nav__item" href="<?= BASE_URL; ?>/challan/create"><i class="bi bi-plus-circle"></i>Create</a>
          <a class="simple-nav__item" href="<?= BASE_URL; ?>/challan/filter"><i class="bi bi-search"></i>Search</a>
          <a class="simple-nav__item" href="<?= BASE_URL; ?>/challan/export?tool=import"><i class="bi bi-upload"></i>Import</a>
          <a class="simple-nav__item" href="<?= BASE_URL; ?>/challan/export?tool=export"><i class="bi bi-download"></i>Export</a>
        </div>
      </details>

      <details class="simple-nav__dropdown">
        <summary class="simple-nav__summary">Bill</summary>
        <div class="simple-nav__menu">
          <a class="simple-nav__item" href="<?= BASE_URL; ?>/bill?view=generate"><i class="bi bi-plus-circle"></i>Create</a>
          <a class="simple-nav__item" href="<?= BASE_URL; ?>/bill?view=list"><i class="bi bi-search"></i>Search</a>
          <a class="simple-nav__item" href="<?= BASE_URL; ?>/bill/export?tool=import"><i class="bi bi-upload"></i>Import</a>
          <a class="simple-nav__item" href="<?= BASE_URL; ?>/bill/export?tool=export"><i class="bi bi-download"></i>Export</a>
        </div>
      </details>

      <details class="simple-nav__dropdown">
        <summary class="simple-nav__summary">Party</summary>
        <div class="simple-nav__menu">
          <a class="simple-nav__item" href="<?= BASE_URL; ?>/party?view=create"><i class="bi bi-plus-circle"></i>Create</a>
          <a class="simple-nav__item" href="<?= BASE_URL; ?>/party?view=list"><i class="bi bi-search"></i>Search</a>
          <a class="simple-nav__item" href="<?= BASE_URL; ?>/party/export?tool=import"><i class="bi bi-upload"></i>Import</a>
          <a class="simple-nav__item" href="<?= BASE_URL; ?>/party/export?tool=export"><i class="bi bi-download"></i>Export</a>
        </div>
      </details>

      <details class="simple-nav__dropdown">
        <summary class="simple-nav__summary">Station</summary>
        <div class="simple-nav__menu">
          <a class="simple-nav__item" href="<?= BASE_URL; ?>/station?view=create"><i class="bi bi-plus-circle"></i>Create</a>
          <a class="simple-nav__item" href="<?= BASE_URL; ?>/station?view=list"><i class="bi bi-search"></i>Search</a>
          <a class="simple-nav__item" href="<?= BASE_URL; ?>/station/export?tool=import"><i class="bi bi-upload"></i>Import</a>
          <a class="simple-nav__item" href="<?= BASE_URL; ?>/station/export?tool=export"><i class="bi bi-download"></i>Export</a>
        </div>
      </details>

      <details class="simple-nav__dropdown">
        <summary class="simple-nav__summary">Vehicle</summary>
        <div class="simple-nav__menu">
          <a class="simple-nav__item" href="<?= BASE_URL; ?>/vehicle?view=create"><i class="bi bi-plus-circle"></i>Create</a>
          <a class="simple-nav__item" href="<?= BASE_URL; ?>/vehicle?view=list"><i class="bi bi-search"></i>Search</a>
          <a class="simple-nav__item" href="<?= BASE_URL; ?>/vehicle/export?tool=import"><i class="bi bi-upload"></i>Import</a>
          <a class="simple-nav__item" href="<?= BASE_URL; ?>/vehicle/export?tool=export"><i class="bi bi-download"></i>Export</a>
        </div>
      </details>

      <details class="simple-nav__dropdown">
        <summary class="simple-nav__summary">Product</summary>
        <div class="simple-nav__menu">
          <a class="simple-nav__item" href="<?= BASE_URL; ?>/product?view=create"><i class="bi bi-plus-circle"></i>Create</a>
          <a class="simple-nav__item" href="<?= BASE_URL; ?>/product?view=list"><i class="bi bi-search"></i>Search</a>
          <a class="simple-nav__item" href="<?= BASE_URL; ?>/product/export?tool=import"><i class="bi bi-upload"></i>Import</a>
          <a class="simple-nav__item" href="<?= BASE_URL; ?>/product/export?tool=export"><i class="bi bi-download"></i>Export</a>
        </div>
      </details>

      <details class="simple-nav__dropdown simple-nav__dropdown--left">
        <summary class="simple-nav__summary">Agent</summary>
        <div class="simple-nav__menu">
          <a class="simple-nav__item" href="<?= BASE_URL; ?>/agent?view=create"><i class="bi bi-plus-circle"></i>Create</a>
          <a class="simple-nav__item" href="<?= BASE_URL; ?>/agent?view=list"><i class="bi bi-search"></i>Search</a>
          <a class="simple-nav__item" href="<?= BASE_URL; ?>/agent/export?tool=import"><i class="bi bi-upload"></i>Import</a>
          <a class="simple-nav__item" href="<?= BASE_URL; ?>/agent/export?tool=export"><i class="bi bi-download"></i>Export</a>
        </div>
      </details>

     <details class="simple-nav__dropdown simple-nav__dropdown--left">
        <summary class="simple-nav__summary">Ledger</summary>
        <div class="simple-nav__menu">
          <a class="simple-nav__item" href="<?= BASE_URL; ?>/ledger/challan.php?view=create&tab=create"><i class="bi bi-plus-circle"></i>Create</a>
          <a class="simple-nav__item" href="<?= BASE_URL; ?>/ledger?view=list"><i class="bi bi-search"></i>Search</a>
          <a class="simple-nav__item" href="<?= BASE_URL; ?>/ledger/export?tool=import"><i class="bi bi-upload"></i>Import</a>
          <a class="simple-nav__item" href="<?= BASE_URL; ?>/ledger/export?tool=export"><i class="bi bi-download"></i>Export</a>
        </div>
      </details>
      <details class="simple-nav__dropdown simple-nav__dropdown--left">
        <summary class="simple-nav__summary">Voucher</summary>
        <div class="simple-nav__menu">
          <a class="simple-nav__item" href="<?= BASE_URL; ?>/voucher?view=create"><i class="bi bi-plus-circle"></i>Create</a>
          <a class="simple-nav__item" href="<?= BASE_URL; ?>/voucher?view=list"><i class="bi bi-search"></i>Search</a>
          <a class="simple-nav__item" href="<?= BASE_URL; ?>/voucher/export?tool=import"><i class="bi bi-upload"></i>Import</a>
          <a class="simple-nav__item" href="<?= BASE_URL; ?>/voucher/export?tool=export"><i class="bi bi-download"></i>Export</a>
        </div>
      </details>

      <?php if ($isAdmin): ?>
        <a class="simple-nav__link" href="<?= base_url('profile'); ?>"><i class="bi bi-person"></i>Profile</a>
        <a class="simple-nav__link" href="<?= BASE_URL; ?>/company"><i class="bi bi-building"></i>Company</a>
        <a class="simple-nav__link simple-nav__link--danger" href="<?= base_url('protect/admin_logout.php'); ?>"><i class="bi bi-box-arrow-right"></i>Logout</a>
      <?php elseif ($isUser): ?>
        <a class="simple-nav__link" href="<?= base_url('profile'); ?>"><i class="bi bi-person"></i>Profile</a>
        <a class="simple-nav__link simple-nav__link--danger" href="<?= base_url('protect/company-logout.php'); ?>"><i class="bi bi-box-arrow-right"></i>Logout</a>
      <?php endif; ?>
    </div>
  </div>
</nav>

<script>
  (function () {
    const nav = document.querySelector('.simple-nav');
    if (!nav) return;

    const dropdowns = Array.from(nav.querySelectorAll('.simple-nav__dropdown'));
    const toggle = document.getElementById('simple-nav-toggle');

    function closeAll() {
      dropdowns.forEach(d => d.open = false);
    }

    dropdowns.forEach(dropdown => {
      dropdown.addEventListener('toggle', function () {
        if (dropdown.open) {
          dropdowns.forEach(other => {
            if (other !== dropdown) other.open = false;
          });
        }
      });
    });

    document.addEventListener('click', function (event) {
      if (!nav.contains(event.target)) {
        closeAll();
      }
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        closeAll();
        if (toggle) toggle.checked = false;
      }
    });
  })();
</script>
