<?php if (!empty($isAdmin)) { ?>
<li class="nav-item dropdown">
  <a class="nav-link dropdown-toggle" href="#" id="companyDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
    <i class="bi bi-receipt"></i> Company
  </a>
  <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="companyDropdown">
    <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/company"><i class="bi bi-plus-circle"></i> Manage</a></li>
    <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/company"><i class="bi bi-search"></i> Search</a></li>
    <li><hr class="dropdown-divider"></li>
    <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/company"><i class="bi bi-upload"></i> Import</a></li>
    <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/company"><i class="bi bi-download"></i> Export</a></li>
  </ul>
</li>
<?php } ?>
