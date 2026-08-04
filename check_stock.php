<?php
$db_host = 'localhost';
$db_name = '';
$db_user = 'root';
$db_pass = ''; // Set your MySQL password here

try {
    $pdo = new PDO("mysql:host={$db_host};dbname={$db_name};charset=utf8mb4", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    die("Database Connection Error: " . $e->getMessage());
}

$search = filter_input(INPUT_GET, 'q', FILTER_SANITIZE_SPECIAL_CHARS) ?? '';

if (!empty($search)) {
    $stmt = $pdo->prepare("
        SELECT 
            Stockcode,
            Barcode,
            Description,
            MainDepName,
            SOH,
            MinimumStock,
            ExclCost,
            InclSellPrice
        FROM tblproducts 
        WHERE (Stockcode LIKE :search 
           OR Barcode LIKE :search 
           OR Description LIKE :search)
          AND ActiveProduct = 1
        ORDER BY Description ASC
    ");
    $stmt->execute(['search' => "%{$search}%"]);
} else {
    $stmt = $pdo->query("
        SELECT 
            Stockcode,
            Barcode,
            Description,
            MainDepName,
            SOH,
            MinimumStock,
            ExclCost,
            InclSellPrice
        FROM tblproducts 
        WHERE ActiveProduct = 1 
        ORDER BY Description ASC
    ");
}

$products = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Stock On Hand - Aberrant</title>
    <style>
        body { font-family: Segoe UI, Tahoma, Geneva, Verdana, sans-serif; margin: 20px; background-color: #f4f6f9; }
        .container { background: #fff; padding: 25px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        h2 { margin-top: 0; color: #2c3e50; }
        .search-form { margin-bottom: 20px; display: flex; gap: 10px; }
        input[type="text"] { padding: 9px 12px; width: 320px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; }
        button { padding: 9px 18px; background-color: #0d6efd; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; }
        button:hover { background-color: #0b5ed7; }
        .clear-btn { padding: 9px 14px; color: #6c757d; text-decoration: none; align-self: center; font-size: 14px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 14px; }
        th, td { border: 1px solid #e0e0e0; padding: 10px 12px; text-align: left; }
        th { background-color: #0d6efd; color: white; font-weight: 600; }
        tr:nth-child(even) { background-color: #f8f9fa; }
        .out-of-stock { background-color: #f8d7da !important; color: #842029; font-weight: bold; }
        .low-stock { background-color: #fff3cd !important; color: #664d03; font-weight: bold; }
        .text-right { text-align: right; }
    </style>
</head>
<body>

<div class="container">
    <h2>Inventory Stock Levels</h2>

    <form method="GET" action="" class="search-form">
        <input type="text" name="q" placeholder="Search by Stockcode, Barcode, Description..." value="<?= htmlspecialchars($search) ?>">
        <button type="submit">Search</button>
        <?php if ($search): ?>
            <a href="check_stock.php" class="clear-btn">Clear Search</a>
        <?php endif; ?>
    </form>

    <table>
        <thead>
            <tr>
                <th>Stock Code</th>
                <th>Barcode</th>
                <th>Description</th>
                <th>Department</th>
                <th class="text-right">Stock On Hand (SOH)</th>
                <th class="text-right">Excl Cost</th>
                <th class="text-right">Incl Selling Price</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($products) > 0): ?>
                <?php foreach ($products as $row): 
                    $soh = floatval($row['SOH']);
                    $min = floatval($row['MinimumStock']);
                    $row_class = '';
                    
                    if ($soh <= 0) {
                        $row_class = 'out-of-stock';
                    } elseif ($min > 0 && $soh <= $min) {
                        $row_class = 'low-stock';
                    }
                ?>
                    <tr>
                        <td><?= htmlspecialchars($row['Stockcode'] ?? '') ?></td>
                        <td><?= htmlspecialchars($row['Barcode'] ?? '') ?></td>
                        <td><?= htmlspecialchars($row['Description'] ?? '') ?></td>
                        <td><?= htmlspecialchars($row['MainDepName'] ?? '') ?></td>
                        <td class="text-right <?= $row_class ?>">
                            <?= number_format($soh, 2) ?>
                        </td>
                        <td class="text-right"><?= number_format($row['ExclCost'], 2) ?></td>
                        <td class="text-right"><?= number_format($row['InclSellPrice'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="7" style="text-align: center;">No matching products found.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

</body>
</html>