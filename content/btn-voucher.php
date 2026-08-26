<?php if (!empty($isAdmin)) { ?>
<li class="nav-item dropdown">
  <a class="nav-link dropdown-toggle" href="#" id="voucherDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
    <i class="bi bi-receipt"></i> Voucher
  </a>
  <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="voucherDropdown">
    <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/voucher"><i class="bi bi-plus-circle"></i> Create</a></li>
    <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/voucher?view=search"><i class="bi bi-search"></i> Search</a></li>
    <li><hr class="dropdown-divider"></li>
    <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/bill"><i class="bi bi-upload"></i> Import</a></li>
    <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/bill/export"><i class="bi bi-download"></i> Export</a></li>
  </ul>
</li>
<?php } ?>
