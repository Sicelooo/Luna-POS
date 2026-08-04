<?php
require_once __DIR__ . '/db.php';

// Pagination & Filter Parameters
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 15;
$offset = ($page - 1) * $limit;

$search = trim($_GET['search'] ?? '');
$department = trim($_GET['department'] ?? '');

// Build Dynamic Query
$where = ["1=1"];
$params = [];

if (!empty($search)) {
    $where[] = "(`Stockcode` LIKE :search OR `Barcode` LIKE :search OR `Description` LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

if (!empty($department)) {
    $where[] = "`MainDepName` = :dept";
    $params[':dept'] = $department;
}

$whereClause = implode(' AND ', $where);

try {
    // 1. Get Total Count for Pagination
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM `tblproducts` WHERE $whereClause");
    $countStmt->execute($params);
    $totalRows = $countStmt->fetchColumn();
    $totalPages = ceil($totalRows / $limit);

    // 2. Fetch Active Page Records
    $sql = "SELECT `Stockcode`, `Barcode`, `Description`, `MainDepName`, `SOH`, `ExclCost`, `ExclSellPrice`, `GPPercentage`, `ActiveProduct` 
            FROM `tblproducts` 
            WHERE $whereClause 
            ORDER BY `Description` ASC 
            LIMIT $limit OFFSET $offset";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll();

    // 3. Fetch Departments for Filter Dropdown
    $deptStmt = $pdo->query("SELECT DISTINCT `MainDepName` FROM `tblproducts` WHERE `MainDepName` IS NOT NULL AND `MainDepName` != '' ORDER BY `MainDepName` ASC");
    $departments = $deptStmt->fetchAll(PDO::FETCH_COLUMN);

} catch (PDOException $e) {
    die("Error loading inventory: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Management - Luna POS</title>
    <!-- Bootstrap 5 CSS & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        #editProductModal .modal-body {
            max-height: 70vh;
            overflow-y: auto;
        }
        .timeline {
            border-left: 2px solid #0d6efd;
            padding-left: 15px;
            margin-left: 10px;
        }
    </style>
</head>
<body class="bg-light">

<!-- ================= TOP MAIN NAVIGATION MENU ================= -->
<nav class="navbar navbar-expand-lg navbar-dark bg-dark sticky-top shadow-sm mb-4">
    <div class="container-fluid">
        <a class="navbar-brand fw-bold me-4" href="index.php">
            <i class="bi bi-cpu-fill text-primary me-2"></i>Luna POS
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavbar" aria-controls="mainNavbar" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="mainNavbar">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item">
                    <a class="nav-link" href="dashboard.php">
                        <i class="bi bi-speedometer2 me-1"></i> Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active fw-semibold" aria-current="page" href="items.php">
                        <i class="bi bi-boxes me-1"></i> Products
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="sales.php">
                        <i class="bi bi-cart-check me-1"></i> Sales / Terminal
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="reports.php">
                        <i class="bi bi-bar-chart-line me-1"></i> Reports
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="settings.php">
                        <i class="bi bi-gear me-1"></i> Settings
                    </a>
                </li>
            </ul>
            <div class="d-flex align-items-center text-white-50 small">
                <i class="bi bi-person-circle me-1 text-white"></i> Admin
            </div>
        </div>
    </div>
</nav>

<div class="container-fluid px-4">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="mb-0 fw-bold text-dark"><i class="bi bi-boxes me-2"></i>Product Catalog</h3>
            <p class="text-muted small mb-0">Manage pricing, stock levels, and terminal parameters.</p>
        </div>
        <button class="btn btn-primary" onclick="location.reload();">
            <i class="bi bi-arrow-clockwise me-1"></i>Refresh Data
        </button>
    </div>

    <!-- Filters Bar -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" action="items.php" class="row g-3">
                <div class="col-md-6">
                    <div class="input-group">
                        <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
                        <input type="text" name="search" class="form-control" placeholder="Search by Stockcode, Barcode, or Description..." value="<?= htmlspecialchars($search) ?>">
                    </div>
                </div>
                <div class="col-md-4">
                    <select name="department" class="form-select">
                        <option value="">All Departments</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?= htmlspecialchars($dept) ?>" <?= $department === $dept ? 'selected' : '' ?>>
                                <?= htmlspecialchars($dept) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-dark w-100"><i class="bi bi-filter me-1"></i>Filter</button>
                    <a href="items.php" class="btn btn-outline-secondary"><i class="bi bi-x-circle"></i></a>
                </div>
            </form>
        </div>
    </div>

    <!-- Products Table -->
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Stock Code</th>
                            <th>Barcode</th>
                            <th>Description</th>
                            <th>Department</th>
                            <th class="text-end">SOH</th>
                            <th class="text-end">Excl Cost</th>
                            <th class="text-end">Excl Sell</th>
                            <th class="text-end">GP %</th>
                            <th class="text-center">Status</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($products)): ?>
                            <tr>
                                <td colspan="10" class="text-center py-4 text-muted">
                                    <i class="bi bi-inbox fs-3 d-block mb-2"></i>No products found matching criteria.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($products as $p): ?>
                                <tr>
                                    <td class="fw-bold"><?= htmlspecialchars($p['Stockcode']) ?></td>
                                    <td><small class="text-muted"><?= htmlspecialchars($p['Barcode'] ?? '-') ?></small></td>
                                    <td><?= htmlspecialchars($p['Description']) ?></td>
                                    <td><span class="badge bg-secondary"><?= htmlspecialchars($p['MainDepName'] ?? 'Unassigned') ?></span></td>
                                    <td class="text-end fw-semibold <?= $p['SOH'] <= 0 ? 'text-danger' : '' ?>">
                                        <?= number_format((float)$p['SOH'], 2) ?>
                                    </td>
                                    <td class="text-end">E <?= number_format((float)$p['ExclCost'], 2) ?></td>
                                    <td class="text-end fw-bold">E <?= number_format((float)$p['ExclSellPrice'], 2) ?></td>
                                    <td class="text-end">
                                        <span class="badge <?= $p['GPPercentage'] >= 20 ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning-emphasis' ?>">
                                            <?= number_format((float)$p['GPPercentage'], 2) ?>%
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($p['ActiveProduct']): ?>
                                            <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i>Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <button class="btn btn-sm btn-outline-primary" onclick="editProduct('<?= htmlspecialchars($p['Stockcode'], ENT_QUOTES) ?>')">
                                            <i class="bi bi-pencil me-1"></i> Edit & History
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Pagination Footer -->
        <?php if ($totalPages > 1): ?>
            <div class="card-footer bg-white border-0 py-3">
                <nav>
                    <ul class="pagination pagination-sm justify-content-end mb-0">
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&department=<?= urlencode($department) ?>">Previous</a>
                        </li>
                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                            <li class="page-item <?= $page === $i ? 'active' : '' ?>">
                                <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&department=<?= urlencode($department) ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                            <a class="page-link" href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&department=<?= urlencode($department) ?>">Next</a>
                        </li>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ================= EDIT & HISTORY TABBED MODAL ================= -->
<div class="modal fade" id="editProductModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-dark text-white">
        <h5 class="modal-title"><i class="bi bi-sliders me-2"></i>Product Operations Center</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <!-- Navigation Tabs -->
      <div class="bg-light border-bottom px-3 pt-2">
        <ul class="nav nav-tabs border-0" id="productModalTabs" role="tablist">
            <li class="nav-item">
                <button class="nav-link active fw-bold" id="details-tab" data-bs-toggle="tab" data-bs-target="#detailsTabContent" type="button">
                    <i class="bi bi-pencil-square me-1"></i> Edit Details
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link fw-bold" id="history-tab" data-bs-toggle="tab" data-bs-target="#historyTabContent" type="button">
                    <i class="bi bi-clock-history me-1"></i> Price & Movement History
                </button>
            </li>
        </ul>
      </div>

      <div class="modal-body">
        <div class="tab-content" id="productModalTabContent">
            
            <!-- TAB 1: EDIT FORM -->
            <div class="tab-pane fade show active" id="detailsTabContent" role="tabpanel">
                <form id="editProductForm">
                    <!-- Identity -->
                    <div class="card mb-3 border-0 bg-light">
                        <div class="card-body">
                            <h6 class="card-subtitle mb-3 text-muted fw-bold border-bottom pb-2">Identification & Department</h6>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Stock Code</label>
                                    <input type="text" class="form-control bg-body-secondary" id="edit_Stockcode" name="Stockcode" readonly required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Barcode</label>
                                    <input type="text" class="form-control" id="edit_Barcode" name="Barcode">
                                </div>
                                <div class="col-md-12">
                                    <label class="form-label fw-semibold">Description</label>
                                    <input type="text" class="form-control" id="edit_Description" name="Description" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Main Department</label>
                                    <input type="text" class="form-control" id="edit_MainDepName" name="MainDepName">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Sub Department</label>
                                    <input type="text" class="form-control" id="edit_SubDepName" name="SubDepName">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Financials -->
                    <div class="card mb-3 border-0 bg-light">
                        <div class="card-body">
                            <h6 class="card-subtitle mb-3 text-muted fw-bold border-bottom pb-2">Pricing & Margin Auto-Calculator</h6>
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Excl Cost</label>
                                    <input type="number" step="0.0001" class="form-control" id="edit_ExclCost" name="ExclCost">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">VAT Rate (%)</label>
                                    <input type="number" step="0.01" class="form-control" id="edit_VatRate" name="VatRate">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label text-muted">Incl Cost</label>
                                    <input type="number" class="form-control bg-body-secondary" id="edit_InclCost" name="InclCost" readonly>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Markup (%)</label>
                                    <input type="number" step="0.01" class="form-control" id="edit_Markup" name="Markup">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Excl Sell Price</label>
                                    <input type="number" step="0.0001" class="form-control" id="edit_ExclSellPrice" name="ExclSellPrice">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label text-muted">Incl Sell Price</label>
                                    <input type="number" class="form-control bg-body-secondary" id="edit_InclSellPrice" name="InclSellPrice" readonly>
                                </div>
                                <div class="col-md-12">
                                    <label class="form-label text-success fw-bold">Calculated GP %</label>
                                    <input type="text" class="form-control bg-success-subtle text-success-emphasis fw-bold" id="edit_GPPercentage" readonly>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Inventory & Toggles -->
                    <div class="card border-0 bg-light">
                        <div class="card-body">
                            <h6 class="card-subtitle mb-3 text-muted fw-bold border-bottom pb-2">Inventory & POS Settings</h6>
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label">Stock On Hand (SOH)</label>
                                    <input type="number" step="0.001" class="form-control" id="edit_SOH" name="SOH">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Shelf Location</label>
                                    <input type="text" class="form-control" id="edit_ShelfLocation" name="ShelfLocation">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Bin Location</label>
                                    <input type="text" class="form-control" id="edit_BinLocation" name="BinLocation">
                                </div>
                                <div class="col-md-6 pt-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="edit_ActiveProduct" name="ActiveProduct" value="1">
                                        <label class="form-check-label fw-semibold" for="edit_ActiveProduct">Active Product</label>
                                    </div>
                                </div>
                                <div class="col-md-6 pt-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="edit_AllowZeroPrice" name="AllowZeroPrice" value="1">
                                        <label class="form-check-label" for="edit_AllowZeroPrice">Allow Zero Price</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-3 text-end">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary px-4"><i class="bi bi-save me-1"></i>Save Changes</button>
                    </div>
                </form>
            </div>

            <!-- TAB 2: AUDIT & HISTORY TAB -->
            <div class="tab-pane fade" id="historyTabContent" role="tabpanel">
                <div class="table-responsive">
                    <table class="table table-striped table-hover align-middle">
                        <thead class="table-dark">
                            <tr>
                                <th>Date & Time</th>
                                <th>Source</th>
                                <th class="text-end">Cost (Old &rarr; New)</th>
                                <th class="text-end">Sell Price (Old &rarr; New)</th>
                                <th class="text-end">SOH Impact</th>
                                <th>User / Comments</th>
                            </tr>
                        </thead>
                        <tbody id="historyTableBody">
                            <tr>
                                <td colspan="6" class="text-center py-4 text-muted">
                                    <div class="spinner-border spinner-border-sm me-2" role="status"></div> Loading audit log...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
      </div>
    </div>
  </div>
</div>

<!-- JS Libraries & Handler Script -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
let editModal;

document.addEventListener('DOMContentLoaded', () => {
    editModal = new bootstrap.Modal(document.getElementById('editProductModal'));

    // Bind real-time pricing listeners
    ['edit_ExclCost', 'edit_Markup', 'edit_VatRate'].forEach(id => {
        document.getElementById(id).addEventListener('input', () => recalculatePricing('cost'));
    });
    document.getElementById('edit_ExclSellPrice').addEventListener('input', () => recalculatePricing('exclSell'));

    // Handle AJAX Save
    document.getElementById('editProductForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);

        fetch('update_product.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                editModal.hide();
                location.reload(); 
            } else {
                alert('Save failed: ' + data.message);
            }
        })
        .catch(err => alert('Network or server error occurred.'));
    });
});

function editProduct(stockcode) {
    // Reset tabs back to Details view
    const firstTab = new bootstrap.Tab(document.getElementById('details-tab'));
    firstTab.show();

    // 1. Fetch Item Details
    fetch(`get_product.php?stockcode=${encodeURIComponent(stockcode)}`)
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                const p = data.product;
                document.getElementById('edit_Stockcode').value      = p.Stockcode || '';
                document.getElementById('edit_Barcode').value        = p.Barcode || '';
                document.getElementById('edit_Description').value    = p.Description || '';
                document.getElementById('edit_MainDepName').value   = p.MainDepName || '';
                document.getElementById('edit_SubDepName').value    = p.SubDepName || '';
                document.getElementById('edit_SOH').value            = p.SOH || 0;
                document.getElementById('edit_ShelfLocation').value  = p.ShelfLocation || '';
                document.getElementById('edit_BinLocation').value    = p.BinLocation || '';

                document.getElementById('edit_ExclCost').value      = parseFloat(p.ExclCost || 0).toFixed(2);
                document.getElementById('edit_InclCost').value      = parseFloat(p.InclCost || 0).toFixed(2);
                document.getElementById('edit_VatRate').value       = parseFloat(p.VatRate || 0).toFixed(2);
                document.getElementById('edit_Markup').value        = parseFloat(p.Markup || 0).toFixed(2);
                document.getElementById('edit_ExclSellPrice').value = parseFloat(p.ExclSellPrice || 0).toFixed(2);
                document.getElementById('edit_InclSellPrice').value = parseFloat(p.InclSellPrice || 0).toFixed(2);
                document.getElementById('edit_GPPercentage').value  = parseFloat(p.GPPercentage || 0).toFixed(2) + '%';

                document.getElementById('edit_ActiveProduct').checked  = parseInt(p.ActiveProduct) === 1;
                document.getElementById('edit_AllowZeroPrice').checked = parseInt(p.AllowZeroPrice) === 1;

                editModal.show();
            } else {
                alert(data.message);
            }
        });

    // 2. Fetch History & Movement Logs
    const historyBody = document.getElementById('historyTableBody');
    historyBody.innerHTML = `<tr><td colspan="6" class="text-center py-4 text-muted"><div class="spinner-border spinner-border-sm me-2"></div>Loading history...</td></tr>`;

    fetch(`get_product_history.php?stockcode=${encodeURIComponent(stockcode)}`)
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success' && data.history.length > 0) {
                historyBody.innerHTML = '';
                data.history.forEach(row => {
                    let badgeClass = 'bg-secondary';
                    if (row.Type === 'GRV') badgeClass = 'bg-success';
                    if (row.Type === 'Sale') badgeClass = 'bg-primary';
                    if (row.Type === 'Adjustment') badgeClass = 'bg-warning text-dark';

                    historyBody.innerHTML += `
                        <tr>
                            <td><small class="fw-semibold">${row.Date}</small></td>
                            <td><span class="badge ${badgeClass}">${row.Type || 'Update'}</span></td>
                            <td class="text-end">
                                ${row.OldExclCost !== undefined ? `E${parseFloat(row.OldExclCost).toFixed(2)} &rarr; ` : ''}
                                <strong>E${parseFloat(row.NewExclCost || 0).toFixed(2)}</strong>
                            </td>
                            <td class="text-end">
                                ${row.OldInclSellPrice !== undefined ? `E${parseFloat(row.OldInclSellPrice).toFixed(2)} &rarr; ` : ''}
                                <strong>E${parseFloat(row.NewInclSellPrice || 0).toFixed(2)}</strong>
                            </td>
                            <td class="text-end fw-bold">
                                ${parseFloat(row.SOHBefore || 0).toFixed(2)} &rarr; ${parseFloat(row.SOHAfter || 0).toFixed(2)}
                            </td>
                            <td><small class="text-muted">${row.Username || 'System'} ${row.Comments ? `(${row.Comments})` : ''}</small></td>
                        </tr>
                    `;
                });
            } else {
                historyBody.innerHTML = `<tr><td colspan="6" class="text-center py-4 text-muted">No movement or price history found for this item.</td></tr>`;
            }
        });
}

function recalculatePricing(source) {
    let cost     = parseFloat(document.getElementById('edit_ExclCost').value) || 0;
    let vat      = parseFloat(document.getElementById('edit_VatRate').value) || 0;
    let markup   = parseFloat(document.getElementById('edit_Markup').value) || 0;
    let exclSell = parseFloat(document.getElementById('edit_ExclSellPrice').value) || 0;

    if (source === 'cost') {
        exclSell = cost * (1 + (markup / 100));
        document.getElementById('edit_ExclSellPrice').value = exclSell.toFixed(2);
    } else if (source === 'exclSell') {
        markup = cost > 0 ? ((exclSell - cost) / cost) * 100 : 0;
        document.getElementById('edit_Markup').value = markup.toFixed(2);
    }

    let inclSell = exclSell * (1 + (vat / 100));
    let inclCost = cost * (1 + (vat / 100));
    let gpValue  = exclSell - cost;
    let gpPct    = exclSell > 0 ? (gpValue / exclSell) * 100 : 0;

    document.getElementById('edit_InclSellPrice').value = inclSell.toFixed(2);
    document.getElementById('edit_InclCost').value      = inclCost.toFixed(2);
    document.getElementById('edit_GPPercentage').value  = gpPct.toFixed(2) + '%';
}
</script>
</body>
</html>