<?php
require_once 'db.php';

// Filter Parameters
$startDate = $_GET['start_date'] ?? date('Y-m-d');
$endDate   = $_GET['end_date'] ?? date('Y-m-d');
$tillName  = $_GET['till_name'] ?? '';
$search    = $_GET['search'] ?? '';

// Build Query
$query = "SELECT SaleID, SaleNr, RefNr, TillName, Username, Date, SaleTotal, Payment, CustomerName 
          FROM histsaleinfo 
          WHERE DATE(Date) BETWEEN :start_date AND :end_date";

$params = [':start_date' => $startDate, ':end_date' => $endDate];

if (!empty($tillName)) {
    $query .= " AND TillName = :till_name";
    $params[':till_name'] = $tillName;
}

if (!empty($search)) {
    $query .= " AND (SaleNr LIKE :search OR RefNr LIKE :search OR CustomerName LIKE :search)";
    $params[':search'] = "%$search%";
}

$query .= " ORDER BY Date DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$sales = $stmt->fetchAll();

$totalSalesAmount = array_sum(array_column($sales, 'SaleTotal'));
$totalTransactions = count($sales);

$tillsStmt = $pdo->query("SELECT DISTINCT TillName FROM histsaleinfo WHERE TillName IS NOT NULL AND TillName != ''");
$tills = $tillsStmt->fetchAll(PDO::FETCH_COLUMN);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POS Dashboard - Aberrant DB</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
</head>
<body class="bg-light">

    <!-- Top Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4 shadow-sm">
        <div class="container-fluid">
            <a class="navbar-brand font-monospace fw-bold text-info" href="index.php">
                <i class="bi bi-speedometer2 me-2"></i>POS Dashboard
            </a>
            <div>
                <a href="pos.php" class="btn btn-primary fw-bold">
                    <i class="bi bi-cart4 me-1"></i> Open POS Terminal
                </a>
            </div>
        </div>
    </nav>

    <div class="container-fluid px-3 px-md-4">
        <!-- Metrics -->
        <div class="row g-3 mb-4">
            <div class="col-12 col-sm-6">
                <div class="card border-0 shadow-sm rounded-3">
                    <div class="card-body p-3 p-md-4">
                        <span class="text-uppercase text-muted fw-bold small">Total Revenue</span>
                        <h2 class="text-success fw-bold mb-0 mt-1">$<?= number_format($totalSalesAmount, 2) ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-12 col-sm-6">
                <div class="card border-0 shadow-sm rounded-3">
                    <div class="card-body p-3 p-md-4">
                        <span class="text-uppercase text-muted fw-bold small">Total Transactions</span>
                        <h2 class="text-primary fw-bold mb-0 mt-1"><?= $totalTransactions ?></h2>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter Form -->
        <div class="card border-0 shadow-sm rounded-3 mb-4">
            <div class="card-body p-3">
                <form method="GET" class="row g-2 align-items-end">
                    <div class="col-12 col-sm-6 col-md-3">
                        <label class="form-label text-uppercase text-muted small fw-bold mb-1">Start Date</label>
                        <input type="date" name="start_date" value="<?= htmlspecialchars($startDate) ?>" class="form-control">
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <label class="form-label text-uppercase text-muted small fw-bold mb-1">End Date</label>
                        <input type="date" name="end_date" value="<?= htmlspecialchars($endDate) ?>" class="form-control">
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <label class="form-label text-uppercase text-muted small fw-bold mb-1">Till</label>
                        <select name="till_name" class="form-select">
                            <option value="">All Tills</option>
                            <?php foreach ($tills as $till): ?>
                                <option value="<?= htmlspecialchars($till) ?>" <?= $tillName === $till ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($till) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <label class="form-label text-uppercase text-muted small fw-bold mb-1">Search</label>
                        <div class="input-group">
                            <input type="text" name="search" placeholder="Sale / Ref / Customer" value="<?= htmlspecialchars($search) ?>" class="form-control">
                            <button type="submit" class="btn btn-primary"><i class="bi bi-funnel-fill"></i></button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Transactions Table -->
        <div class="card border-0 shadow-sm rounded-3 mb-5">
            <div class="card-header bg-white py-3 border-0">
                <h5 class="card-title fw-bold m-0 text-secondary">Sales Transactions</h5>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light text-uppercase small text-muted">
                        <tr>
                            <th class="ps-3">Sale #</th>
                            <th>Ref #</th>
                            <th>Date & Time</th>
                            <th>Customer</th>
                            <th>Till</th>
                            <th>Cashier</th>
                            <th>Payment</th>
                            <th>Total</th>
                            <th class="text-end pe-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($sales) > 0): ?>
                            <?php foreach ($sales as $sale): ?>
                                <tr>
                                    <td class="ps-3 fw-bold">#<?= htmlspecialchars($sale['SaleNr']) ?></td>
                                    <td class="text-muted small"><?= htmlspecialchars($sale['RefNr'] ?? 'N/A') ?></td>
                                    <td class="small"><?= date('Y-m-d H:i', strtotime($sale['Date'])) ?></td>
                                    <td><?= htmlspecialchars($sale['CustomerName'] ?? 'Walk-in') ?></td>
                                    <td><?= htmlspecialchars($sale['TillName'] ?? 'N/A') ?></td>
                                    <td class="small"><?= htmlspecialchars($sale['Username']) ?></td>
                                    <td><span class="badge bg-secondary text-uppercase"><?= htmlspecialchars($sale['Payment']) ?></span></td>
                                    <td class="fw-bold text-dark">$<?= number_format($sale['SaleTotal'], 2) ?></td>
                                    <td class="text-end pe-3">
                                        <div class="btn-group btn-group-sm">
                                            <a href="generate_pdf.php?sale_nr=<?= $sale['SaleNr'] ?>" target="_blank" class="btn btn-outline-danger"><i class="bi bi-file-earmark-pdf"></i> PDF</a>
                                            <a href="print_receipt.php?sale_nr=<?= $sale['SaleNr'] ?>" target="_blank" class="btn btn-outline-dark"><i class="bi bi-printer"></i> Print</a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="9" class="text-center py-4 text-muted">No transactions found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>