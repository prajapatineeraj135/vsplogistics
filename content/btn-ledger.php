<li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle" href="#" id="ledgerDropdown" role="button" data-bs-toggle="dropdown"
              aria-expanded="false">
              <i class="bi bi-receipt"></i> Ledger
            </a>

            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="ledgerDropdown">

              <li>
                <a class="dropdown-item" href="<?php echo BASE_URL; ?>/ledger">
                  <i class="bi bi-plus-circle"></i> Create
                </a>
              </li>

              <li>
                <a class="dropdown-item" href="<?php echo BASE_URL; ?>/ledger">
                  <i class="bi bi-search"></i> Search
                </a>
              </li>

              <li>
                <hr class="dropdown-divider">
              </li>

              <?php if (!empty($isAdmin)) { ?>
              <li>
                <a class="dropdown-item" href="<?php echo BASE_URL; ?>/ledger">
                  <i class="bi bi-upload"></i> Import
                </a>
              </li>

              <li>
                <a class="dropdown-item" href="<?php echo BASE_URL; ?>/ledger">
                  <i class="bi bi-download"></i> Export
                </a>
              </li>
              <?php } ?>

            </ul>
          </li>
