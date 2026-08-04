<?php
require_once 'db.php';
require_once 'vendor/autoload.php'; // Dompdf autoload

use Dompdf\Dompdf;

$saleNr = $_GET['sale_nr'] ?? null;

if (!$saleNr) {
    die("Sale Number is required.");
}

// Fetch Main Sale Information
$saleStmt = $pdo->prepare("SELECT * FROM histsaleinfo WHERE SaleNr = :sale_nr LIMIT 1");
$saleStmt->execute([':sale_nr' => $saleNr]);
$sale = $saleStmt->fetch();

if (!$sale) {
    die("Sale record not found.");
}

// Fetch Sale Line Items from histhistory
$itemsStmt = $pdo->prepare("SELECT * FROM histhistory WHERE SaleNr = :sale_nr");
$itemsStmt->execute([':sale_nr' => $saleNr]);
$items = $itemsStmt->fetchAll();

// Generate HTML Content for PDF
ob_start();
?>
<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: sans-serif; font-size: 12px; color: #333; }
        .header { text-align: center; margin-bottom: 20px; }
        .header h2 { margin: 0; }
        .info-table, .items-table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
        .info-table td { padding: 4px; }
        .items-table th, .items-table td { border: 1px solid #ddd; padding: 6px; text-align: left; }
        .items-table th { background-color: #f4f4f4; }
        .text-right { text-align: right; }
        .totals { width: 40%; margin-left: auto; margin-top: 10px; }
        .totals td { padding: 4px; }
    </style>
</head>
<body>

    <div class="header">
        <h2>TAX INVOICE</h2>
        <p>Sale #: <?= htmlspecialchars($sale['SaleNr']) ?> | Ref: <?= htmlspecialchars($sale['RefNr'] ?? 'N/A') ?></p>
    </div>

    <table class="info-table">
        <tr>
            <td><strong>Date:</strong> <?= htmlspecialchars($sale['Date']) ?></td>
            <td><strong>Till:</strong> <?= htmlspecialchars($sale['TillName'] ?? 'N/A') ?></td>
        </tr>
        <tr>
            <td><strong>Customer:</strong> <?= htmlspecialchars($sale['CustomerName'] ?? 'Walk-In Customer') ?></td>
            <td><strong>Cashier:</strong> <?= htmlspecialchars($sale['Username']) ?></td>
        </tr>
    </table>

    <table class="items-table">
        <thead>
            <tr>
                <th>Code</th>
                <th>Description</th>
                <th class="text-right">Qty</th>
                <th class="text-right">Price</th>
                <th class="text-right">Total</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $item): ?>
            <tr>
                <td><?= htmlspecialchars($item['Stockcode']) ?></td>
                <td><?= htmlspecialchars($item['Description']) ?></td>
                <td class="text-right"><?= number_format($item['Qty'], 2) ?></td>
                <td class="text-right">$<?= number_format($item['InclSellPrice'], 2) ?></td>
                <td class="text-right">$<?= number_format($item['InclLineTotal'], 2) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td><strong>VAT Total:</strong></td>
            <td class="text-right">$<?= number_format($sale['SaleVat'], 2) ?></td>
        </tr>
        <tr>
            <td><strong>Grand Total:</strong></td>
            <td class="text-right"><strong>$<?= number_format($sale['SaleTotal'], 2) ?></strong></td>
        </tr>
        <tr>
            <td><strong>Payment Method:</strong></td>
            <td class="text-right"><?= htmlspecialchars($sale['Payment']) ?></td>
        </tr>
    </table>

</body>
</html>
<?php
$html = ob_get_clean();

$dompdf = new Dompdf();
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream("Invoice_Sale_" . $saleNr . ".pdf", ["Attachment" => false]);