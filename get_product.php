<?php
header('Content-Type: application/json');
require_once __DIR__ . '/db.php';

$stockcode = trim($_GET['stockcode'] ?? '');
$stmt = $pdo->prepare("SELECT * FROM `tblproducts` WHERE `Stockcode` = :stockcode LIMIT 1");
$stmt->execute([':stockcode' => $stockcode]);
$product = $stmt->fetch();

echo json_encode($product ? ['status' => 'success', 'product' => $product] : ['status' => 'error', 'message' => 'Product not found']);