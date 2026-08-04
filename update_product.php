<?php
header('Content-Type: application/json');
require_once __DIR__ . '/db.php';

$stockcode = trim($_POST['Stockcode'] ?? '');
if (empty($stockcode)) {
    echo json_encode(['status' => 'error', 'message' => 'Stockcode missing']);
    exit;
}

$exclCost      = (float)($_POST['ExclCost'] ?? 0);
$vatRate       = (float)($_POST['VatRate'] ?? 0);
$exclSellPrice = (float)($_POST['ExclSellPrice'] ?? 0);

$inclCost      = $exclCost * (1 + ($vatRate / 100));
$inclSellPrice = $exclSellPrice * (1 + ($vatRate / 100));
$gpValue       = $exclSellPrice - $exclCost;
$gpPercentage  = ($exclSellPrice > 0) ? (($gpValue / $exclSellPrice) * 100) : 0;
$markup        = ($exclCost > 0) ? (($gpValue / $exclCost) * 100) : 0;

$fields = [
    'Barcode'        => $_POST['Barcode'] ?? null,
    'Description'    => $_POST['Description'] ?? '',
    'MainDepName'    => $_POST['MainDepName'] ?? null,
    'SubDepName'     => $_POST['SubDepName'] ?? null,
    'ExclCost'       => round($exclCost, 4),
    'InclCost'       => round($inclCost, 4),
    'VatRate'        => round($vatRate, 4),
    'Markup'         => round($markup, 4),
    'GPPercentage'   => round($gpPercentage, 4),
    'GPValue'        => round($gpValue, 4),
    'ExclSellPrice'  => round($exclSellPrice, 4),
    'InclSellPrice'  => round($inclSellPrice, 4),
    'SOH'            => (float)($_POST['SOH'] ?? 0),
    'ShelfLocation'  => $_POST['ShelfLocation'] ?? null,
    'BinLocation'    => $_POST['BinLocation'] ?? null,
    'ActiveProduct'  => isset($_POST['ActiveProduct']) ? 1 : 0,
    'AllowZeroPrice' => isset($_POST['AllowZeroPrice']) ? 1 : 0,
    'LastUpdateDate' => date('Y-m-d H:i:s'),
];

$setClauses = [];
$params = [':StockcodeKey' => $stockcode];

foreach ($fields as $col => $val) {
    $setClauses[] = "`$col` = :$col";
    $params[":$col"] = $val;
}

try {
    $stmt = $pdo->prepare("UPDATE `tblproducts` SET " . implode(', ', $setClauses) . " WHERE `Stockcode` = :StockcodeKey");
    $stmt->execute($params);
    echo json_encode(['status' => 'success']);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}