<?php
// ---------------------------------------------------------------------
// Database Connection
// Database name: aberrant
// ---------------------------------------------------------------------
$dbHost = 'localhost';
$dbName = 'aberrant'; 
$dbUser = 'root';
$dbPass = '@b3rrAntS0ft';

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    die("Database Connection Failed: " . $e->getMessage());
}

// ---------------------------------------------------------------------
// 0. Date Range Filter Handling
// Default to Today if no date is provided
// ---------------------------------------------------------------------
$startDate = $_GET['start_date'] ?? date('Y-m-d');
$endDate   = $_GET['end_date'] ?? date('Y-m-d');

// ---------------------------------------------------------------------
// 1. KPI Metrics (Filtered by Date Range)
// ---------------------------------------------------------------------
$kpiStmt = $pdo->prepare("
    SELECT 
        COALESCE(SUM(InclLineTotal), 0) AS TotalRevenue,
        COALESCE(SUM(ExclCost * Qty), 0) AS TotalCost,
        COALESCE(SUM(Qty), 0) AS TotalQty,
        COUNT(DISTINCT SaleNr) AS TotalTransactions
    FROM tbltillhistory 
    WHERE DATE(Date) BETWEEN :startDate AND :endDate
");
$kpiStmt->execute([':startDate' => $startDate, ':endDate' => $endDate]);
$kpi = $kpiStmt->fetch();

$revenue = (float)$kpi['TotalRevenue'];
$cost    = (float)$kpi['TotalCost'];
$profit  = $revenue - $cost;
$margin  = $revenue > 0 ? ($profit / $revenue) * 100 : 0;

// ---------------------------------------------------------------------
// 2. Cashup Breakdown Grouped by Cashup Session (`cashupnr`)
// ---------------------------------------------------------------------
$cashupStmt = $pdo->prepare("
    SELECT 
        cashupnr,
        Username,
        MIN(Date) AS SessionStart,
        MAX(Date) AS SessionEnd,
        COUNT(DISTINCT SaleNr) AS TotalTransactions,
        COALESCE(SUM(Qty), 0) AS ItemsSold,
        COALESCE(SUM(InclLineTotal), 0) AS CashupRevenue,
        COALESCE(SUM(CASE WHEN Payment = 'CASH' THEN InclLineTotal ELSE 0 END), 0) AS CashSales,
        COALESCE(SUM(CASE WHEN Payment <> 'CASH' THEN InclLineTotal ELSE 0 END), 0) AS OtherSales
    FROM tbltillhistory
    WHERE DATE(Date) BETWEEN :startDate AND :endDate
    GROUP BY cashupnr, Username
    ORDER BY cashupnr DESC
");
$cashupStmt->execute([':startDate' => $startDate, ':endDate' => $endDate]);
$cashupSessions = $cashupStmt->fetchAll();

// ---------------------------------------------------------------------
// 3. Items Sold Each Day (Detailed Daily Item Matrix)
// ---------------------------------------------------------------------
$dailyItemsStmt = $pdo->prepare("
    SELECT 
        DATE(Date) AS SaleDay,
        Stockcode,
        Description,
        MainDepName,
        SUM(Qty) AS DayQty,
        SUM(InclLineTotal) AS DayTotalSales
    FROM tbltillhistory
    WHERE DATE(Date) BETWEEN :startDate AND :endDate
    GROUP BY DATE(Date), Stockcode, Description, MainDepName
    ORDER BY SaleDay DESC, DayTotalSales DESC
");
$dailyItemsStmt->execute([':startDate' => $startDate, ':endDate' => $endDate]);
$dailyItemsSold = $dailyItemsStmt->fetchAll();

// ---------------------------------------------------------------------
// 4. Sales Performance Trend Chart Data (Filtered Range)
// ---------------------------------------------------------------------
$chartStmt = $pdo->prepare("
    SELECT 
        DATE_FORMAT(Date, '%Y-%m-%d') as SaleDate,
        SUM(InclLineTotal) as DailyTotal
    FROM tbltillhistory
    WHERE DATE(Date) BETWEEN :startDate AND :endDate
    GROUP BY DATE(Date)
    ORDER BY Date ASC
");
$chartStmt->execute([':startDate' => $startDate, ':endDate' => $endDate]);
$chartData = $chartStmt->fetchAll();

$chartLabels = [];
$chartValues = [];
foreach ($chartData as $row) {
    $chartLabels[] = date('M j, Y', strtotime($row['SaleDate']));
    $chartValues[] = (float)$row['DailyTotal'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POS Analytics & Items Sold Report</title>
    <!-- Offline Local Bootstrap 5 CSS -->
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <!-- Offline Local FontAwesome Icons -->
    <link href="css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f4f6f9; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .stat-card { border: none; border-radius: 12px; transition: all 0.2s ease-in-out; }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 8px 15px rgba(0,0,0,0.05); }
        .icon-square { width: 52px; height: 52px; border-radius: 10px; display: flex; align-items: center; justify-content: center; }
        .card-custom { border: none; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); }
        .table-custom thead { background-color: #f8f9fa; font-size: 0.75rem; letter-spacing: 0.5px; }
        .filter-bar { background-color: #ffffff; border-radius: 12px; border: 1px solid #e9ecef; }
        
        /* Fancy Chart Container Styling */
        .chart-card {
            background: linear-gradient(180deg, #ffffff 0%, #fdfdfd 100%);
            border: 1px solid #eef2f6;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.03);
        }
    </style>
</head>
<body>

<!-- Navigation Top Bar -->
<nav class="navbar navbar-expand-lg navbar-dark bg-dark sticky-top shadow-sm mb-4">
    <div class="container-fluid px-4">
        <a class="navbar-brand fw-bold" href="dashboard.php">
            <i class="fas fa-store me-2 text-primary"></i>Aberrant POS
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#topNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="topNav">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item">
                    <a class="nav-link" href="dashboard.php"><i class="fas fa-chart-line me-1"></i> Dashboard</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="index.php"><i class="fas fa-cash-register me-1"></i> POS Register</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active fw-semibold" href="items.php"><i class="fas fa-boxes me-1"></i> Inventory / Items</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="purchases.php"><i class="fas fa-truck-loading me-1"></i> Purchases</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="customers.php"><i class="fas fa-users me-1"></i> Customers</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="suppliers.php"><i class="fas fa-building me-1"></i> Suppliers</a>
                </li>
            </ul>
        </div>
    </div>
</nav>

<div class="container-fluid px-4 py-2">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h3 class="fw-bold text-dark m-0">Till History Analytics</h3>
            <p class="text-muted small m-0">Filtered sales reporting and itemized ledger from <code>tbltillhistory</code></p>
        </div>
        <a href="dashboard.php" class="btn btn-outline-secondary btn-sm rounded-pill px-3">
            <i class="fas fa-undo me-1"></i> Reset Today
        </a>
    </div>

    <!-- Date Range Filter Bar -->
    <div class="filter-bar p-3 mb-4 shadow-sm">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-4 col-sm-6">
                <label for="start_date" class="form-label fw-semibold text-muted small mb-1">Start Date</label>
                <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo htmlspecialchars($startDate); ?>" required>
            </div>
            <div class="col-md-4 col-sm-6">
                <label for="end_date" class="form-label fw-semibold text-muted small mb-1">End Date</label>
                <input type="date" class="form-control" id="end_date" name="end_date" value="<?php echo htmlspecialchars($endDate); ?>" required>
            </div>
            <div class="col-md-4 col-12 d-flex gap-2">
                <button type="submit" class="btn btn-primary w-100 fw-semibold">
                    <i class="fas fa-filter me-1"></i> Apply Filter
                </button>
            </div>
        </form>
    </div>

    <!-- Top Key Metrics Cards -->
    <div class="row g-3 mb-4">
        <!-- Revenue -->
        <div class="col-xl-3 col-md-6">
            <div class="card stat-card bg-white p-3 shadow-sm">
                <div class="d-flex align-items-center">
                    <div class="icon-square bg-primary bg-opacity-10 text-primary me-3">
                        <i class="fas fa-chart-line fa-lg"></i>
                    </div>
                    <div>
                        <div class="text-uppercase text-muted fw-bold small">Total Revenue</div>
                        <h4 class="fw-bold mb-0">E<?php echo number_format($revenue, 2); ?></h4>
                    </div>
                </div>
            </div>
        </div>

        <!-- Profit -->
        <div class="col-xl-3 col-md-6">
            <div class="card stat-card bg-white p-3 shadow-sm">
                <div class="d-flex align-items-center">
                    <div class="icon-square bg-success bg-opacity-10 text-success me-3">
                        <i class="fas fa-coins fa-lg"></i>
                    </div>
                    <div>
                        <div class="text-uppercase text-muted fw-bold small">Gross Profit</div>
                        <h4 class="fw-bold mb-0">
                            E<?php echo number_format($profit, 2); ?>
                            <span class="fs-6 text-muted fw-normal">(<?php echo number_format($margin, 1); ?>%)</span>
                        </h4>
                    </div>
                </div>
            </div>
        </div>

        <!-- Total Items -->
        <div class="col-xl-3 col-md-6">
            <div class="card stat-card bg-white p-3 shadow-sm">
                <div class="d-flex align-items-center">
                    <div class="icon-square bg-info bg-opacity-10 text-info me-3">
                        <i class="fas fa-boxes fa-lg"></i>
                    </div>
                    <div>
                        <div class="text-uppercase text-muted fw-bold small">Items Sold</div>
                        <h4 class="fw-bold mb-0"><?php echo number_format($kpi['TotalQty'], 0); ?></h4>
                    </div>
                </div>
            </div>
        </div>

        <!-- Total Transactions -->
        <div class="col-xl-3 col-md-6">
            <div class="card stat-card bg-white p-3 shadow-sm">
                <div class="d-flex align-items-center">
                    <div class="icon-square bg-warning bg-opacity-10 text-warning me-3">
                        <i class="fas fa-receipt fa-lg"></i>
                    </div>
                    <div>
                        <div class="text-uppercase text-muted fw-bold small">Transactions</div>
                        <h4 class="fw-bold mb-0"><?php echo number_format($kpi['TotalTransactions']); ?></h4>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Fancy Offline Chart Row -->
    <div class="row g-3 mb-4">
        <div class="col-12">
            <div class="card chart-card p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h6 class="fw-bold text-dark m-0"><i class="fas fa-wave-square me-2 text-primary"></i>Revenue Performance Trend</h6>
                        <span class="text-muted small">Daily revenue aggregation across selected timeframe</span>
                    </div>
                    <span class="badge bg-primary bg-opacity-10 text-primary border border-primary-subtle px-3 py-2 rounded-pill">
                        <i class="fas fa-calendar-alt me-1"></i> <?php echo htmlspecialchars($startDate); ?> — <?php echo htmlspecialchars($endDate); ?>
                    </span>
                </div>
                <div style="height: 280px; position: relative;">
                    <canvas id="revenueChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Section: Items Sold Each Day -->
    <div class="row g-3 mb-4">
        <div class="col-12">
            <div class="card card-custom bg-white">
                <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                    <h6 class="fw-bold text-dark m-0"><i class="fas fa-list-ol me-2 text-primary"></i>Items Sold Each Day</h6>
                </div>
                <div class="table-responsive" style="max-height: 420px; overflow-y: auto;">
                    <table class="table align-middle table-custom table-hover mb-0">
                        <thead class="text-uppercase text-muted sticky-top bg-light">
                            <tr>
                                <th class="ps-3">Date</th>
                                <th>Stock Code</th>
                                <th>Description</th>
                                <th>Department</th>
                                <th class="text-center">Qty Sold</th>
                                <th class="pe-3 text-end">Total Revenue</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($dailyItemsSold)): ?>
                                <?php foreach ($dailyItemsSold as $item): ?>
                                <tr>
                                    <td class="ps-3 fw-semibold text-muted"><?php echo date('Y-m-d (D)', strtotime($item['SaleDay'])); ?></td>
                                    <td><code><?php echo htmlspecialchars($item['Stockcode'] ?: 'N/A'); ?></code></td>
                                    <td class="fw-semibold text-dark"><?php echo htmlspecialchars($item['Description'] ?: 'Unassigned Item'); ?></td>
                                    <td class="text-muted small"><?php echo htmlspecialchars($item['MainDepName'] ?: 'General'); ?></td>
                                    <td class="text-center fw-bold"><?php echo number_format($item['DayQty'], 0); ?></td>
                                    <td class="pe-3 text-end fw-bold text-dark">E<?php echo number_format($item['DayTotalSales'], 2); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="6" class="text-center text-muted py-4">No items sold within this date range.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Section: Cashup Session Breakdown -->
    <div class="row g-3 mb-4">
        <div class="col-12">
            <div class="card card-custom bg-white">
                <div class="card-header bg-white py-3 border-0">
                    <h6 class="fw-bold text-dark m-0"><i class="fas fa-cash-register me-2 text-success"></i>Cashup Session Audit (`cashupnr`)</h6>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle table-custom mb-0">
                        <thead class="text-uppercase text-muted">
                            <tr>
                                <th class="ps-3">Cashup #</th>
                                <th>Cashier</th>
                                <th>First Tx Time</th>
                                <th>Last Tx Time</th>
                                <th class="text-center">Receipts</th>
                                <th class="text-center">Items</th>
                                <th>Cash Sales</th>
                                <th>Other Methods</th>
                                <th class="pe-3 text-end">Session Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($cashupSessions)): ?>
                                <?php foreach ($cashupSessions as $session): ?>
                                <tr>
                                    <td class="ps-3 fw-bold text-primary">#<?php echo $session['cashupnr']; ?></td>
                                    <td><i class="fas fa-user-circle me-1 text-muted"></i><?php echo htmlspecialchars($session['Username'] ?: 'System'); ?></td>
                                    <td class="text-muted small"><?php echo !empty($session['SessionStart']) ? date('M j, H:i', strtotime($session['SessionStart'])) : 'N/A'; ?></td>
                                    <td class="text-muted small"><?php echo !empty($session['SessionEnd']) ? date('M j, H:i', strtotime($session['SessionEnd'])) : 'N/A'; ?></td>
                                    <td class="text-center fw-semibold"><?php echo number_format($session['TotalTransactions']); ?></td>
                                    <td class="text-center"><?php echo number_format($session['ItemsSold'], 0); ?></td>
                                    <td class="text-success fw-semibold">E<?php echo number_format($session['CashSales'], 2); ?></td>
                                    <td class="text-info fw-semibold">E<?php echo number_format($session['OtherSales'], 2); ?></td>
                                    <td class="pe-3 text-end fw-bold">E<?php echo number_format($session['CashupRevenue'], 2); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="9" class="text-center text-muted py-4">No cashup records found for selected dates.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Offline Chart.js Core Library -->
<script src="js/chart.umd.js"></script>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const canvas = document.getElementById('revenueChart');
        if (!canvas) return;

        const ctx = canvas.getContext('2d');

        // Multi-stage sleek gradient background
        const fillGradient = ctx.createLinearGradient(0, 0, 0, 260);
        fillGradient.addColorStop(0, 'rgba(13, 110, 253, 0.35)');
        fillGradient.addColorStop(0.5, 'rgba(13, 110, 253, 0.08)');
        fillGradient.addColorStop(1, 'rgba(13, 110, 253, 0.0)');

        const chartLabels = <?php echo json_encode($chartLabels); ?>;
        const chartValues = <?php echo json_encode($chartValues); ?>;

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: chartLabels,
                datasets: [{
                    label: 'Sales Revenue',
                    data: chartValues,
                    borderColor: '#0d6efd',
                    borderWidth: 3,
                    backgroundColor: fillGradient,
                    fill: true,
                    tension: 0.35, // Smooth curves
                    pointRadius: 5,
                    pointHoverRadius: 8,
                    pointBackgroundColor: '#ffffff',
                    pointBorderColor: '#0d6efd',
                    pointBorderWidth: 2.5,
                    pointHoverBackgroundColor: '#0d6efd',
                    pointHoverBorderColor: '#ffffff',
                    pointHoverBorderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#1e293b',
                        titleFont: { size: 13, weight: '600' },
                        bodyFont: { size: 13 },
                        padding: 12,
                        cornerRadius: 8,
                        displayColors: false,
                        callbacks: {
                            label: function(context) {
                                return ' Revenue: E ' + Number(context.raw).toLocaleString('en-US', {
                                    minimumFractionDigits: 2,
                                    maximumFractionDigits: 2
                                });
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        border: { display: false },
                        grid: {
                            color: '#f1f5f9',
                            drawTicks: false
                        },
                        ticks: {
                            color: '#64748b',
                            font: { size: 11, weight: '500' },
                            padding: 10,
                            callback: function(value) {
                                return 'E ' + value.toLocaleString();
                            }
                        }
                    },
                    x: {
                        border: { display: false },
                        grid: { display: false },
                        ticks: {
                            color: '#64748b',
                            font: { size: 11, weight: '500' },
                            padding: 8
                        }
                    }
                }
            }
        });
    });
</script>

</body>
</html>