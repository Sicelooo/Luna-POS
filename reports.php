<?php
require_once __DIR__ . '/db.php';

// Date Filters (Default to Current Month)
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate   = $_GET['end_date'] ?? date('Y-m-d');
$activeTab = $_GET['tab'] ?? 'sales';
$department = trim($_GET['department'] ?? '');

$startTs = $startDate . ' 00:00:00';
$endTs   = $endDate . ' 23:59:59';

// Fetch Active Departments for Filter
$departments = [];
try {
    $deptStmt = $pdo->query("SELECT DISTINCT `MainDepName` FROM `tblproducts` WHERE `MainDepName` IS NOT NULL AND `MainDepName` != '' ORDER BY `MainDepName` ASC");
    $departments = $deptStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    // Graceful fallback
}

// KPI Summaries Initialization
$kpiSales  = 0;
$kpiCost   = 0;
$kpiProfit = 0;
$kpiMargin = 0;

try {
    // Calculate KPI Totals from EOD Movement
    $kpiSql = "
        SELECT 
            COALESCE(SUM(TotalSellingExcl), 0) AS TotalSales,
            COALESCE(SUM(TotalCostExcl), 0) AS TotalCost
        FROM tblthiseodstockmovement 
        WHERE Date BETWEEN :start AND :end AND TransactionType IN ('Sale', 'POS')
    ";
    $kpiStmt = $pdo->prepare($kpiSql);
    $kpiStmt->execute([':start' => $startTs, ':end' => $endTs]);
    $kpiData = $kpiStmt->fetch();

    $kpiSales  = (float)$kpiData['TotalSales'];
    $kpiCost   = (float)$kpiData['TotalCost'];
    $kpiProfit = $kpiSales - $kpiCost;
    $kpiMargin = $kpiSales > 0 ? ($kpiProfit / $kpiSales) * 100 : 0;

} catch (PDOException $e) {
    // Handle database error
}

// Data Arrays
$reportData = [];

try {
    // Tab 1: Sales Movement
    if ($activeTab === 'sales') {
        $where = ["Date BETWEEN :start AND :end", "TransactionType IN ('Sale', 'POS')"];
        $params = [':start' => $startTs, ':end' => $endTs];

        if (!empty($department)) {
            $where[] = "MainDepName = :dept";
            $params[':dept'] = $department;
        }

        $sql = "
            SELECT 
                Stockcode, Barcode, Description, MainDepName,
                SUM(QtySold) AS TotalQty,
                SUM(TotalCostExcl) AS CostTotal,
                SUM(TotalSellingExcl) AS SellTotal,
                (SUM(TotalSellingExcl) - SUM(TotalCostExcl)) AS ProfitTotal
            FROM tblthiseodstockmovement 
            WHERE " . implode(' AND ', $where) . "
            GROUP BY Stockcode, Barcode, Description, MainDepName
            ORDER BY SellTotal DESC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $reportData = $stmt->fetchAll();
    }

    // Tab 2: GRV Purchases
    if ($activeTab === 'purchases') {
        $sql = "
            SELECT 
                GRVNr, Date, SupNr, SupplierName, InvoiceNr, OrderNumber, 
                GRVExclTotal, GRVVatTotal, GRVInclTotal, Username
            FROM tblthiseodgrvinfo 
            WHERE Date BETWEEN :start AND :end
            ORDER BY Date DESC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':start' => $startTs, ':end' => $endTs]);
        $reportData = $stmt->fetchAll();
    }

    // Tab 3: Inventory Valuation
    if ($activeTab === 'valuation') {
        $where = ["ActiveProduct = 1"];
        $params = [];

        if (!empty($department)) {
            $where[] = "MainDepName = :dept";
            $params[':dept'] = $department;
        }

        $sql = "
            SELECT 
                Stockcode, Barcode, Description, MainDepName, SOH, ExclCost, ExclSellPrice,
                (SOH * ExclCost) AS TotalCostValue,
                (SOH * ExclSellPrice) AS TotalRetailValue
            FROM tblproducts 
            WHERE " . implode(' AND ', $where) . "
            ORDER BY Description ASC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $reportData = $stmt->fetchAll();
    }

    // Tab 4: Price Audit
    if ($activeTab === 'audit') {
        $sql = "
            SELECT 
                UpdDate AS Date, ChangedFrom, Stockcode, NewDescription AS Description, 
                OldExclCost, NewExclCost, OldInclSellPrice, NewInclSellPrice, 
                Username, Comments
            FROM tblpricechanges 
            WHERE UpdDate BETWEEN :start AND :end
            ORDER BY ID DESC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':start' => $startTs, ':end' => $endTs]);
        $reportData = $stmt->fetchAll();
    }

} catch (PDOException $e) {
    $errorMsg = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Analytics - Luna POS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .kpi-card { border: none; border-radius: 10px; transition: transform 0.2s; }
        .kpi-card:hover { transform: translateY(-3px); }
        @media print {
            .no-print { display: none !important; }
            .card { border: none !important; box-shadow: none !important; }
            body { background-color: #fff; }
        }
    </style>
</head>
<body>

<!-- Navigation -->
<nav class="navbar navbar-expand-lg navbar-dark bg-dark sticky-top shadow-sm mb-4 no-print">
    <div class="container-fluid">
        <a class="navbar-brand fw-bold me-4" href="index.php">
            <i class="bi bi-cpu-fill text-primary me-2"></i>Luna POS
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavbar">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="mainNavbar">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item"><a class="nav-link" href="dashboard.php"><i class="bi bi-speedometer2 me-1"></i> Dashboard</a></li>
                <li class="nav-item"><a class="nav-link" href="items.php"><i class="bi bi-boxes me-1"></i> Products</a></li>
                <li class="nav-item"><a class="nav-link" href="sales.php"><i class="bi bi-cart-check me-1"></i> Sales / Terminal</a></li>
                <li class="nav-item"><a class="nav-link active fw-semibold" href="reports.php"><i class="bi bi-bar-chart-line me-1"></i> Reports</a></li>
                <li class="nav-item"><a class="nav-link" href="settings.php"><i class="bi bi-gear me-1"></i> Settings</a></li>
            </ul>
        </div>
    </div>
</nav>

<div class="container-fluid px-4">
    <!-- Header Controls -->
    <div class="d-flex justify-content-between align-items-center mb-4 no-print">
        <div>
            <h3 class="mb-0 fw-bold text-dark"><i class="bi bi-file-earmark-bar-graph me-2"></i>Financial & Stock Reports</h3>
            <p class="text-muted small mb-0">Auditing period sales, valuation, GRVs, and price movements.</p>
        </div>
        <div class="d-flex gap-2">
            <button onclick="window.print()" class="btn btn-outline-dark"><i class="bi bi-printer me-1"></i> Print Report</button>
        </div>
    </div>

    <!-- Period KPI Summary Row -->
    <div class="row g-3 mb-4 no-print">
        <div class="col-md-3">
            <div class="card kpi-card bg-primary text-white shadow-sm p-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <small class="text-white-50 text-uppercase fw-bold">Period Sales Excl</small>
                        <h3 class="mb-0 fw-bold mt-1">E <?= number_format($kpiSales, 2) ?></h3>
                    </div>
                    <i class="bi bi-currency-dollar fs-1 text-white-50"></i>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card kpi-card bg-secondary text-white shadow-sm p-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <small class="text-white-50 text-uppercase fw-bold">Period COGS</small>
                        <h3 class="mb-0 fw-bold mt-1">E <?= number_format($kpiCost, 2) ?></h3>
                    </div>
                    <i class="bi bi-cart-x fs-1 text-white-50"></i>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card kpi-card bg-success text-white shadow-sm p-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <small class="text-white-50 text-uppercase fw-bold">Gross Profit</small>
                        <h3 class="mb-0 fw-bold mt-1">E <?= number_format($kpiProfit, 2) ?></h3>
                    </div>
                    <i class="bi bi-graph-up-arrow fs-1 text-white-50"></i>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card kpi-card bg-dark text-white shadow-sm p-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <small class="text-white-50 text-uppercase fw-bold">Gross Margin %</small>
                        <h3 class="mb-0 fw-bold mt-1"><?= number_format($kpiMargin, 2) ?>%</h3>
                    </div>
                    <i class="bi bi-percent fs-1 text-white-50"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="card border-0 shadow-sm mb-4 no-print">
        <div class="card-body">
            <form method="GET" action="reports.php" class="row g-3 align-items-end">
                <input type="hidden" name="tab" value="<?= htmlspecialchars($activeTab) ?>">
                
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Start Date</label>
                    <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($startDate) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">End Date</label>
                    <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($endDate) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Department</label>
                    <select name="department" class="form-select">
                        <option value="">All Departments</option>
                        <?php foreach ($departments as $d): ?>
                            <option value="<?= htmlspecialchars($d) ?>" <?= $department === $d ? 'selected' : '' ?>>
                                <?= htmlspecialchars($d) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-funnel me-1"></i> Apply Filter</button>
                    <a href="reports.php?tab=<?= $activeTab ?>" class="btn btn-outline-secondary"><i class="bi bi-x-circle"></i></a>
                </div>
            </form>
        </div>
    </div>

    <!-- Report Tabs -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-bottom pt-3 px-3 no-print">
            <ul class="nav nav-tabs card-header-tabs border-0">
                <li class="nav-item">
                    <a class="nav-link fw-bold <?= $activeTab === 'sales' ? 'active text-primary border-bottom border-primary border-3' : 'text-muted' ?>" 
                       href="reports.php?tab=sales&start_date=<?= $startDate ?>&end_date=<?= $endDate ?>&department=<?= urlencode($department) ?>">
                       <i class="bi bi-bag-check me-1"></i> Item Sales Movement
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link fw-bold <?= $activeTab === 'purchases' ? 'active text-primary border-bottom border-primary border-3' : 'text-muted' ?>" 
                       href="reports.php?tab=purchases&start_date=<?= $startDate ?>&end_date=<?= $endDate ?>">
                       <i class="bi bi-truck me-1"></i> GRV Purchases
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link fw-bold <?= $activeTab === 'valuation' ? 'active text-primary border-bottom border-primary border-3' : 'text-muted' ?>" 
                       href="reports.php?tab=valuation&department=<?= urlencode($department) ?>">
                       <i class="bi bi-calculator me-1"></i> Stock Valuation
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link fw-bold <?= $activeTab === 'audit' ? 'active text-primary border-bottom border-primary border-3' : 'text-muted' ?>" 
                       href="reports.php?tab=audit&start_date=<?= $startDate ?>&end_date=<?= $endDate ?>">
                       <i class="bi bi-journal-text me-1"></i> Price Changes Audit
                    </a>
                </li>
            </ul>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">
                
                <!-- TAB 1: ITEM SALES MOVEMENT -->
                <?php if ($activeTab === 'sales'): ?>
                    <table class="table table-hover table-striped align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Stockcode</th>
                                <th>Description</th>
                                <th>Department</th>
                                <th class="text-end">Qty Sold</th>
                                <th class="text-end">Cost Total (Excl)</th>
                                <th class="text-end">Sales Total (Excl)</th>
                                <th class="text-end">Gross Profit</th>
                                <th class="text-end">GP %</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($reportData)): ?>
                                <tr><td colspan="8" class="text-center py-4 text-muted">No sales movement logged for the selected period.</td></tr>
                            <?php else: ?>
                                <?php foreach ($reportData as $r): 
                                    $gp = (float)$r['ProfitTotal'];
                                    $sell = (float)$r['SellTotal'];
                                    $gpPct = $sell > 0 ? ($gp / $sell) * 100 : 0;
                                ?>
                                    <tr>
                                        <td class="fw-bold"><?= htmlspecialchars($r['Stockcode']) ?></td>
                                        <td><?= htmlspecialchars($r['Description']) ?></td>
                                        <td><span class="badge bg-secondary"><?= htmlspecialchars($r['MainDepName'] ?? 'Unassigned') ?></span></td>
                                        <td class="text-end fw-semibold"><?= number_format((float)$r['TotalQty'], 2) ?></td>
                                        <td class="text-end">E <?= number_format((float)$r['CostTotal'], 2) ?></td>
                                        <td class="text-end fw-bold">E <?= number_format((float)$r['SellTotal'], 2) ?></td>
                                        <td class="text-end text-success fw-bold">E <?= number_format($gp, 2) ?></td>
                                        <td class="text-end"><?= number_format($gpPct, 2) ?>%</td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>

                <!-- TAB 2: GRV PURCHASES -->
                <?php elseif ($activeTab === 'purchases'): ?>
                    <table class="table table-hover table-striped align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>GRV #</th>
                                <th>Date</th>
                                <th>Supplier Code</th>
                                <th>Supplier Name</th>
                                <th>Invoice #</th>
                                <th>Order #</th>
                                <th class="text-end">Excl Total</th>
                                <th class="text-end">VAT Total</th>
                                <th class="text-end">Incl Total</th>
                                <th>User</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($reportData)): ?>
                                <tr><td colspan="10" class="text-center py-4 text-muted">No GRVs finalized for the selected date range.</td></tr>
                            <?php else: ?>
                                <?php foreach ($reportData as $r): ?>
                                    <tr>
                                        <td class="fw-bold">#<?= htmlspecialchars($r['GRVNr']) ?></td>
                                        <td><small><?= htmlspecialchars($r['Date']) ?></small></td>
                                        <td><?= htmlspecialchars($r['SupNr']) ?></td>
                                        <td><?= htmlspecialchars($r['SupplierName']) ?></td>
                                        <td><?= htmlspecialchars($r['InvoiceNr'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($r['OrderNumber'] ?? '-') ?></td>
                                        <td class="text-end">E <?= number_format((float)$r['GRVExclTotal'], 2) ?></td>
                                        <td class="text-end">E <?= number_format((float)$r['GRVVatTotal'], 2) ?></td>
                                        <td class="text-end fw-bold">E <?= number_format((float)$r['GRVInclTotal'], 2) ?></td>
                                        <td><small class="text-muted"><?= htmlspecialchars($r['Username'] ?? 'System') ?></small></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>

                <!-- TAB 3: STOCK VALUATION -->
                <?php elseif ($activeTab === 'valuation'): ?>
                    <table class="table table-hover table-striped align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Stockcode</th>
                                <th>Description</th>
                                <th>Department</th>
                                <th class="text-end">SOH</th>
                                <th class="text-end">Excl Cost</th>
                                <th class="text-end">Excl Sell</th>
                                <th class="text-end">Total Cost Value</th>
                                <th class="text-end">Total Retail Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($reportData)): ?>
                                <tr><td colspan="8" class="text-center py-4 text-muted">No active products found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($reportData as $r): ?>
                                    <tr>
                                        <td class="fw-bold"><?= htmlspecialchars($r['Stockcode']) ?></td>
                                        <td><?= htmlspecialchars($r['Description']) ?></td>
                                        <td><span class="badge bg-secondary"><?= htmlspecialchars($r['MainDepName'] ?? 'Unassigned') ?></span></td>
                                        <td class="text-end fw-semibold"><?= number_format((float)$r['SOH'], 2) ?></td>
                                        <td class="text-end">E <?= number_format((float)$r['ExclCost'], 2) ?></td>
                                        <td class="text-end">E <?= number_format((float)$r['ExclSellPrice'], 2) ?></td>
                                        <td class="text-end fw-bold">E <?= number_format((float)$r['TotalCostValue'], 2) ?></td>
                                        <td class="text-end text-primary fw-bold">E <?= number_format((float)$r['TotalRetailValue'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>

                <!-- TAB 4: PRICE CHANGES AUDIT -->
                <?php elseif ($activeTab === 'audit'): ?>
                    <table class="table table-hover table-striped align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Date</th>
                                <th>Source</th>
                                <th>Stockcode</th>
                                <th>Description</th>
                                <th class="text-end">Excl Cost (Old &rarr; New)</th>
                                <th class="text-end">Incl Sell (Old &rarr; New)</th>
                                <th>User / Comments</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($reportData)): ?>
                                <tr><td colspan="7" class="text-center py-4 text-muted">No price change entries recorded for the period.</td></tr>
                            <?php else: ?>
                                <?php foreach ($reportData as $r): ?>
                                    <tr>
                                        <td><small><?= htmlspecialchars($r['Date']) ?></small></td>
                                        <td><span class="badge bg-dark"><?= htmlspecialchars($r['ChangedFrom'] ?? 'System') ?></span></td>
                                        <td class="fw-bold"><?= htmlspecialchars($r['Stockcode']) ?></td>
                                        <td><?= htmlspecialchars($r['Description']) ?></td>
                                        <td class="text-end">
                                            E <?= number_format((float)$r['OldExclCost'], 2) ?> &rarr; <strong>E <?= number_format((float)$r['NewExclCost'], 2) ?></strong>
                                        </td>
                                        <td class="text-end">
                                            E <?= number_format((float)$r['OldInclSellPrice'], 2) ?> &rarr; <strong>E <?= number_format((float)$r['NewInclSellPrice'], 2) ?></strong>
                                        </td>
                                        <td><small class="text-muted"><?= htmlspecialchars($r['Username'] ?? 'System') ?> - <?= htmlspecialchars($r['Comments'] ?? '') ?></small></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>