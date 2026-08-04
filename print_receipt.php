<?php
require_once 'db.php';

$saleNr = $_GET['sale_nr'] ?? null;

if (!$saleNr) {
    die("Sale Number is required.");
}

$saleStmt = $pdo->prepare("SELECT * FROM histsaleinfo WHERE SaleNr = :sale_nr LIMIT 1");
$saleStmt->execute([':sale_nr' => $saleNr]);
$sale = $saleStmt->fetch();

if (!$sale) {
    die("Sale record not found.");
}

$itemsStmt = $pdo->prepare("SELECT * FROM histhistory WHERE SaleNr = :sale_nr");
$itemsStmt->execute([':sale_nr' => $saleNr]);
$items = $itemsStmt->fetchAll();

$paymentAmount = $sale['PaymentAmount'] ?? $sale['SaleTotal'] ?? 0;
$changeDue = $sale['ChangeDue'] ?? 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Print Receipt #<?= htmlspecialchars($saleNr) ?></title>
    <style>
        body {
            font-family: 'Courier New', Courier, monospace;
            font-size: 12px;
            width: 80mm;
            margin: 0 auto;
            padding: 10px;
        }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .divider { border-top: 1px dashed #000; margin: 8px 0; }
        table { width: 100%; border-collapse: collapse; }
        td, th { vertical-align: top; }
        @media print {
            body { width: 100%; padding: 0; }
            @page { margin: 0; }
        }
    </style>
</head>
<body onload="window.print()">

    <div class="text-center">
        <h2>STORE NAME</h2>
        <p>Sales Receipt</p>
    </div>

    <div class="divider"></div>

    <p>
        Sale #: <?= htmlspecialchars($sale['SaleNr']) ?><br>
        Date: <?= htmlspecialchars($sale['Date']) ?><br>
        Till: <?= htmlspecialchars($sale['TillName'] ?? 'N/A') ?><br>
        Cashier: <?= htmlspecialchars($sale['Username'] ?? 'Cashier') ?>
    </p>

    <div class="divider"></div>

    <table>
        <thead>
            <tr>
                <th style="text-align: left;">Item</th>
                <th class="text-right">Qty</th>
                <th class="text-right">Total</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td><?= htmlspecialchars($item['Description']) ?></td>
                    <td class="text-right"><?= number_format($item['Qty'], 0) ?></td>
                    <td class="text-right">$<?= number_format($item['InclLineTotal'], 2) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="divider"></div>

    <table>
        <tr>
            <td>VAT Total</td>
            <td class="text-right">$<?= number_format($sale['SaleVat'] ?? 0, 2) ?></td>
        </tr>
        <tr>
            <td><strong>TOTAL</strong></td>
            <td class="text-right"><strong>$<?= number_format($sale['SaleTotal'] ?? 0, 2) ?></strong></td>
        </tr>
        <tr>
            <td>Paid (<?= htmlspecialchars($sale['Payment'] ?? 'CASH') ?>)</td>
            <td class="text-right">$<?= number_format($paymentAmount, 2) ?></td>
        </tr>
        <tr>
            <td>Change Due</td>
            <td class="text-right">$<?= number_format($changeDue, 2) ?></td>
        </tr>
    </table>

    <div class="divider"></div>

    <div class="text-center">
        <p>Thank you for your business!</p>
    </div>

</body>
</html>