<?php
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');

$stockcode = trim($_GET['stockcode'] ?? '');

if (empty($stockcode)) {
    echo json_encode(['status' => 'error', 'message' => 'Missing stockcode parameter.']);
    exit;
}

try {
    // Limit search window to last 90 days for lightning-fast queries
    $fromDate = date('Y-m-d H:i:s', strtotime('-90 days'));

    // 1. Fetch Price Changes (Indexed Lookup)
    $priceStmt = $pdo->prepare("
        SELECT 
            UpdDate AS Date, 
            ChangedFrom AS Type, 
            OldExclCost, 
            NewExclCost, 
            OldInclSellPrice, 
            NewInclSellPrice, 
            SOHBefore, 
            SOHAfter, 
            Username, 
            Comments 
        FROM tblpricechanges 
        WHERE UPPER(Stockcode) = UPPER(:sc)
        ORDER BY ID DESC 
        LIMIT 15
    ");
    $priceStmt->execute([':sc' => $stockcode]);
    $priceHistory = $priceStmt->fetchAll();

    // 2. Fetch Active EOD Movement
    $eodStmt = $pdo->prepare("
        SELECT 
            Date, 
            TransactionType AS Type, 
            TrnNr, 
            OpeningSOH AS SOHBefore, 
            ClosingSOH AS SOHAfter, 
            ExclCost AS NewExclCost, 
            InclSellPrice AS NewInclSellPrice, 
            Username, 
            CONCAT('Trn #', TrnNr) AS Comments
        FROM tblthiseodstockmovement 
        WHERE UPPER(Stockcode) = UPPER(:sc) AND Date >= :fromDate
        ORDER BY Date DESC 
        LIMIT 15
    ");
    $eodStmt->execute([':sc' => $stockcode, ':fromDate' => $fromDate]);
    $eodHistory = $eodStmt->fetchAll();

    // 3. Fetch Historical Movement (If EOD is sparse)
    $histHistory = [];
    if (count($eodHistory) < 15) {
        $histStmt = $pdo->prepare("
            SELECT 
                Date, 
                TransactionType AS Type, 
                TrnNr, 
                OpeningSOH AS SOHBefore, 
                ClosingSOH AS SOHAfter, 
                ExclCost AS NewExclCost, 
                InclSellPrice AS NewInclSellPrice, 
                Username, 
                CONCAT('Hist Trn #', TrnNr) AS Comments
            FROM histstockmovement 
            WHERE UPPER(Stockcode) = UPPER(:sc) AND Date >= :fromDate
            ORDER BY Date DESC 
            LIMIT 15
        ");
        $histStmt->execute([':sc' => $stockcode, ':fromDate' => $fromDate]);
        $histHistory = $histStmt->fetchAll();
    }

    // Combine and sort
    $combined = array_merge($priceHistory, $eodHistory, $histHistory);
    usort($combined, function ($a, $b) {
        return strtotime($b['Date']) - strtotime($a['Date']);
    });

    echo json_encode([
        'status'  => 'success',
        'history' => array_slice($combined, 0, 25)
    ]);

} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}