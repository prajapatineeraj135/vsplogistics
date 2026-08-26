<li class="nav-item dropdown">
  <a class="nav-link dropdown-toggle" href="#" id="billDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
    <i class="bi bi-receipt"></i> Bill
  </a>
  <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="billDropdown">
    <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/bill?view=generate"><i class="bi bi-plus-circle"></i> Generate</a></li>
    <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/bill?view=list"><i class="bi bi-search"></i> Search</a></li>
    <?php if (!empty($isAdmin)): ?>
      <li><hr class="dropdown-divider"></li>
      <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/bill/export"><i class="bi bi-download"></i> Export</a></li>
    <?php endif; ?>
  </ul>
</li>
