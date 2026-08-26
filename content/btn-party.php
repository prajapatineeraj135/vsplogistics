<li class="nav-item dropdown">
  <a class="nav-link dropdown-toggle" href="#" id="partyDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
    <i class="bi bi-receipt"></i> Party
  </a>
  <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="partyDropdown">
    <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/party?view=create"><i class="bi bi-plus-circle"></i> Create</a></li>
    <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/party?view=list"><i class="bi bi-search"></i> Search</a></li>
    <?php if (!empty($isAdmin)): ?>
      <li><hr class="dropdown-divider"></li>
      <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/party/export"><i class="bi bi-download"></i> Export</a></li>
    <?php endif; ?>
  </ul>
</li>
