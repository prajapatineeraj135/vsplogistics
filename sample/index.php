<?php
// Protect page (login required)
include "../../protect/auth.php";
include "../../protect/db.php";

// Get logged-in company ID from session
$company_id = $_SESSION['company_id'] ?? '101'; // Default to '102' if not set for testing

// Fetch company data
$stmt = $conn->prepare("SELECT * FROM company WHERE id = ?");
$stmt->bind_param("i", $company_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
  echo "<h3 style='text-align:center;margin-top:50px;'>Company data not found</h3>";
  exit;
}

$company = $result->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <!-- Meta information -->
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />

  <!-- External CSS -->
  <link rel="stylesheet" href="biltystyle.css">


  <!-- Page title -->
  <title>Bilty-Paid</title>
</head>

<body>

  <!-- =======================
       MAIN BILTY CONTAINER
  ======================== -->
  <div class="bilty">

    <!-- =======================
         HEADER / COMPANY INFO
    ======================== -->
    <header class="header">

      <!-- Company name & address -->
      <div class="company-name">
        <h1><?= htmlspecialchars($company['company_name']) ?></h1>
        <p>
          <?= htmlspecialchars($company['address1']) ?>, <?= htmlspecialchars($company['address2']) ?>,
          <?= htmlspecialchars($company['address3']) ?>, <?= htmlspecialchars($company['city']) ?>
          <?= htmlspecialchars($company['pincode']) ?> <?= htmlspecialchars($company['state']) ?>
        </p>
        <p><?= htmlspecialchars($company['phone1']) ?>, <?= htmlspecialchars($company['phone2']) ?></p>
      </div>

      <!-- Branch & GST details -->
      <div class="company-branch-details">
        <p><strong>Branch:</strong> <?= htmlspecialchars($company['branch']) ?></p>
        <p><strong>Contact:</strong> <?= htmlspecialchars($company['owner_phone']) ?></p>
        <p><strong>Transoprt ID:</strong> <?= htmlspecialchars($company['gst_no']) ?></p>
      </div>

    </header>

    <!-- =======================
         CONSIGNOR & CONSIGNEE
    ======================== -->
    <section class="headingdata">

      <!-- Consignor & Consignee box -->
      <div class="customer-horizontal">

        <!-- Consignor details -->
        <div class="consignor">
          <center>
            <h1>
              Consignor
            </h1>
          </center>
          <div class="customer-details">
            <p><strong>Name:</strong> Aditya Birla</p>
            <p><strong>Address:</strong> Kota</p>
            <p><strong>Contact:</strong> 9509930493</p>
          </div>
        </div>

        <!-- Vertical divider -->
        <hr class="vertical-separator">

        <!-- Consignee details -->
        <div class="consignee">
          <center>
            <h1>
              Consignee
            </h1>
          </center>
          <div class="customer-details">
            <p><strong>Name:</strong> Shree Ji Enterprises</p>
            <p><strong>Address:</strong> Pidawa</p>
            <p><strong>Contact:</strong> 9509930493</p>
          </div>
        </div>

      </div>

      <!-- =======================
           GR / DATE / ROUTE INFO
      ======================== -->
      <aside class="details" aria-label="GR Details">
        <table>
          <tr>
            <th class="k">G.R.</th>
            <td class="sep">:</td>
            <td class="v big">10/123456</td>
          </tr>
          <tr>
            <th class="k">Date</th>
            <td class="sep">:</td>
            <td class="v">13/11/2025 22:02</td>
          </tr>
          <tr>
            <th class="k">Kota To</th>
            <td class="sep">:</td>
            <td class="v big">Pidawa</td>
          </tr>
        </table>
      </aside>

    </section>

    <!-- =======================
         MAIN BILTY BODY
    ======================== -->
    <section class="bilty-image-row">

      <!-- =======================
           STATION LIST
      ======================== -->
      <div class="station-area" aria-label="Stations">
        <h3>Station</h3>
        <ul>
          <li><strong>Bakani</strong><br>9001339506</li>
          <li><strong>Ratlai</strong><br>9001339507</li>
          <li><strong>Raipur</strong><br>9001339508</li>
          <li><strong>Soyatkalan</strong><br>9001339509</li>
          <li><strong>Sunel</strong><br>9001339510</li>
          <li><strong>Pidawa</strong><br>9001339511</li>
          <li><strong>Hemda</strong><br>9001339512</li>
          <li><strong>All Rajasthan</strong></li>
        </ul>
      </div>

      <!-- =======================
           ITEM DETAILS TABLE
      ======================== -->
      <div class="middle-area">

        <!-- Table header -->
        <div class="heads">
          <div class="head">No.</div>
          <div class="head">Description</div>
          <div class="head">Weight</div>
        </div>

        <!-- Item rows -->
        <div class="desc-area">

          <div class="desc-content">
            <!-- Single item row (repeatable) -->
            <div class="item-row">
              <div class="item-desc">1</div>
              <div class="item-desc">Tyr 1000/20</div>
              <div class="item-desc">252 KG</div>
            </div>

            <!-- More item rows... -->
          </div>

          <!-- Total row -->
          <div class="desc-footer">
            <div class="item-desc" style="padding-left: 20px;">7</div>
            <div class="item-desc">Total</div>
            <div class="item-desc">252 KG</div>
          </div>

          <!-- Invoice / value / eway -->
          <div class="bill-footer">
            <p><strong>Invoice :</strong> 124521</p>
            <p><strong>Value :</strong> 25623</p>
            <p><strong>Eway :</strong> 154216414</p>
          </div>

          <!-- Mark & remark -->
          <div class="mark-footer">
            <p><strong>Private Marka :</strong> 25623</p>
            <p><strong>Remark :</strong> </p>
          </div>


          <!-- Note -->
          <div class="note-footer">
            <p><strong>Note:</strong> We Are Not Responsible For Any Type Of Damage, Breakage, Leakage, Fire& Any
              Natural Calamiteis.</p>
          </div>
          <!-- Delivery location -->
          <div class="deliver-footer">
            <p><strong>Delivery At :</strong> <?php echo htmlspecialchars($company['company_name']); ?></p>
          </div>

        </div>
      </div>

      <!-- =======================
           CHARGES SECTION
      ======================== -->
      <div class="charges-area" aria-label="Charges">

        <h3>Charges</h3>

        <div class="charges-inner">

          <!-- Individual charge row -->
          <div class="charges-row">
            <div class="c-label">Freight</div>
            <div class="c-value">250</div>
          </div>

          <div class="charges-row">
            <div class="c-label">Hammali</div>
            <div class="c-value">0</div>
          </div>

          <div class="charges-row">
            <div class="c-label">P. Freight</div>
            <div class="c-value">0</div>
          </div>

          <div class="charges-row">
            <div class="c-label">Brokerage</div>
            <div class="c-value">0</div>
          </div>

          <div class="charges-row">
            <div class="c-label">DD Charge</div>
            <div class="c-value">0</div>
          </div>

          <div class="charges-row">
            <div class="c-label">GR Charge</div>
            <div class="c-value">20</div>
          </div>

          <!-- Total -->
          <div class="charges-row total-row">
            <div class="c-label">Total</div>
            <div class="c-value">20</div>
          </div>

          <!-- Paid / To pay -->
          <div class="charges-row">
            <div class="c-value full">Paid</div>
          </div>

        </div>
      </div>

    </section>


  </div>

</body>

</html>