<?php
// ---------------------------------------------------------------------
// Database Connection
// ---------------------------------------------------------------------
$dbHost = 'localhost';
$dbName = ''; 
$dbUser = 'root';
$dbPass = '';

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => true,
    ]);
} catch (PDOException $e) {
    die("Database Connection Failed: " . $e->getMessage());
}

$username = 'Aberrant User';

// ---------------------------------------------------------------------
// 1. Session / Active GRV Draft Allocation
// ---------------------------------------------------------------------
$activeGrvStmt = $pdo->query("SELECT GRVNr FROM tblgrvinfo ORDER BY GRVNr DESC LIMIT 1");
$activeGrv = $activeGrvStmt->fetchColumn();

if (!$activeGrv) {
    $numStmt = $pdo->query("SELECT GRVNr FROM tblnumbers");
    $activeGrv = (int)$numStmt->fetchColumn();
    if (!$activeGrv) { $activeGrv = 1; }
    
    $pdo->query("UPDATE tblnumbers SET GRVnr = GRVNr + 1");

    $initHeader = $pdo->prepare("
        INSERT INTO tblgrvinfo (
            GRVNr, Date, SupNr, SupRefNr, SupplierName, InvoiceNr, OrderNumber, Username, 
            InvExclTotal, InvVatTotal, InvDiscountTotal, InvInclTotal, 
            GRVExclTotal, GRVVatTotal, GRVDiscountTotal, GRVInclTotal,
            GRVExtraDiscount, InvExtraDiscount, InvDelivery, GRVDelivery, DeliveryRatio
        ) VALUES (:grv, CURRENT_TIMESTAMP, '', '', '', '', '', :user, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0)
    ");
    $initHeader->execute([':grv' => $activeGrv, ':user' => $username]);
}

// ---------------------------------------------------------------------
// 2. Action Handling (Add Item, Update Details, Finalize GRV)
// ---------------------------------------------------------------------
$feedback = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Action A: Update Header / Supplier Details
    if ($action === 'update_header') {
        $supNr       = trim($_POST['sup_nr'] ?? '');
        $invoiceNr   = trim($_POST['invoice_nr'] ?? '');
        $orderNr     = trim($_POST['order_number'] ?? '');
        
        $supStmt = $pdo->prepare("SELECT AccountNr, Company FROM tblsupplierdetails WHERE UPPER(AccountNr) = UPPER(:sup)");
        $supStmt->execute([':sup' => $supNr]);
        $supplier = $supStmt->fetch();

        if ($supplier) {
            $dupCheck = $pdo->prepare("
                SELECT GRVNr FROM tblgrvinfo WHERE SupNr = ? AND InvoiceNr = ? AND GRVNr <> ?
                UNION
                SELECT GRVNr FROM tblthiseodgrvinfo WHERE SupNr = ? AND InvoiceNr = ?
                UNION
                SELECT GRVNr FROM histgrvinfo WHERE SupNr = ? AND InvoiceNr = ?
            ");
            $dupCheck->execute([
                $supNr, $invoiceNr, $activeGrv,
                $supNr, $invoiceNr,
                $supNr, $invoiceNr
            ]);
            
            if ($dupCheck->fetch()) {
                $feedback = '<div class="alert alert-warning">Warning: Invoice number already exists for this supplier!</div>';
            }

            $updHeader = $pdo->prepare("
                UPDATE tblgrvinfo SET 
                    SupNr = :sup, SupplierName = :supName, InvoiceNr = :inv, OrderNumber = :ord, Username = :user
                WHERE GRVNr = :grv
            ");
            $updHeader->execute([
                ':sup'     => $supplier['AccountNr'],
                ':supName' => $supplier['Company'],
                ':inv'     => $invoiceNr,
                ':ord'     => $orderNr,
                ':user'    => $username,
                ':grv'     => $activeGrv
            ]);
        }
    }

    // Action B: Add Item to GRV Lines
    if ($action === 'add_line') {
        $stockcode = trim($_POST['stockcode'] ?? '');
        $qty       = (float)($_POST['qty'] ?? 0);
        $costExcl  = (float)($_POST['cost_excl'] ?? 0);
        $sellPrice = (float)($_POST['sell_price'] ?? 0);

        if ($stockcode && $qty > 0) {
            $prodStmt = $pdo->prepare("SELECT * FROM tblproducts WHERE UPPER(Stockcode) = UPPER(:code) AND ActiveProduct = 1");
            $prodStmt->execute([':code' => $stockcode]);
            $prod = $prodStmt->fetch();

            if ($prod) {
                $lineExcl = $qty * $costExcl;
                $vatRate  = (float)$prod['VatRate'];
                $lineVat  = $lineExcl * ($vatRate / 100);
                $lineIncl = $lineExcl + $lineVat;
                $costIncl = $costExcl * (1 + ($vatRate / 100));

                $gpVal  = $sellPrice - $costExcl;
                $gpPct  = $sellPrice > 0 ? ($gpVal / $sellPrice) * 100 : 0;
                $markup = $costExcl > 0 ? ($gpVal / $costExcl) * 100 : 0;

                $nextIdStmt = $pdo->query("SELECT COALESCE(MAX(GRVLineID), 0) + 1 FROM tblgrvlines");
                $nextId = (int)$nextIdStmt->fetchColumn();

                $insLine = $pdo->prepare("
                    INSERT INTO tblgrvlines (
                        GRVLineID, GRVNr, Username, Stockcode, Barcode, Description, Description2, 
                        MainDepNr, MainDepName, SubDepNr, SubDepName, LastPurchaseCost, LastPurchaseCostIncl, 
                        Qty, ExclCost, VatRate, InclCost, GPPercentage, Markup, ExclSellPrice, InclSellPrice, 
                        Discount, DiscountValue, ExclLineTotal, LineVat, InclLineTotal, LastExclSellPrice, LastInclSellPrice, 
                        OrderNumber, OrderQty, OrderExclCost, OrderInclCost, SupNr, SupplierName, CreditQty, DeliveryCharge, FreeQty, UnitCostExcl, UnitCostIncl
                    ) VALUES (
                        :lineId, :grv, :user, :code, :barcode, :desc, :desc2,
                        :depNr, :depName, :subNr, :subName, :lastCost, :lastCostIncl,
                        :qty, :exclCost, :vat, :inclCost, :gp, :markup, :exclSell, :inclSell,
                        0, 0, :exclTotal, :vatTotal, :inclTotal, :lastExclSell, :lastInclSell,
                        '', 0, 0, 0, '', '', 0, 0, 0, 0, 0
                    )
                ");
                $insLine->execute([
                    ':lineId'       => $nextId,
                    ':grv'          => $activeGrv,
                    ':user'         => $username,
                    ':code'         => $prod['Stockcode'],
                    ':barcode'      => $prod['Barcode'],
                    ':desc'         => $prod['Description'],
                    ':desc2'        => $prod['Description2'] ?? $prod['Description'],
                    ':depNr'        => $prod['MainDepNr'],
                    ':depName'      => $prod['MainDepName'],
                    ':subNr'        => $prod['SubDepNr'],
                    ':subName'      => $prod['SubDepName'],
                    ':lastCost'     => $prod['LastPurchaseCost'],
                    ':lastCostIncl' => $prod['InclCost'],
                    ':qty'          => $qty,
                    ':exclCost'     => $costExcl,
                    ':vat'          => $vatRate,
                    ':inclCost'     => $costIncl,
                    ':gp'           => $gpPct,
                    ':markup'       => $markup,
                    ':exclSell'     => $sellPrice,
                    ':inclSell'     => $sellPrice,
                    ':exclTotal'    => $lineExcl,
                    ':vatTotal'     => $lineVat,
                    ':inclTotal'    => $lineIncl,
                    ':lastExclSell' => $prod['ExclSellPrice'],
                    ':lastInclSell' => $prod['InclSellPrice']
                ]);
            }
        }
    }

    // Action C: Delete Individual Line
    if ($action === 'delete_line') {
        $lineId = (int)($_POST['line_id'] ?? 0);
        if ($lineId > 0) {
            $delStmt = $pdo->prepare("DELETE FROM tblgrvlines WHERE GRVLineID = :id AND GRVNr = :grv");
            $delStmt->execute([':id' => $lineId, ':grv' => $activeGrv]);
        }
    }

    // Action D: Finalize GRV Post Sequence
    if ($action === 'finalize_grv') {
        $isFullPurchase = isset($_POST['full_purchase']);

        try {
            $pdo->beginTransaction();

            $grvHeaderStmt = $pdo->prepare("SELECT * FROM tblgrvinfo WHERE GRVNr = :grv");
            $grvHeaderStmt->execute([':grv' => $activeGrv]);
            $grvHeader = $grvHeaderStmt->fetch();

            $grvLinesStmt = $pdo->prepare("SELECT * FROM tblgrvlines WHERE GRVNr = :grv ORDER BY GRVLineID ASC");
            $grvLinesStmt->execute([':grv' => $activeGrv]);
            $grvLines  = $grvLinesStmt->fetchAll();

            if ($grvHeader && !empty($grvLines)) {
                $nowTimestamp = date('Y-m-d H:i:s');

                foreach ($grvLines as $line) {
                    $stockcode = $line['Stockcode'];

                    $pStmt = $pdo->prepare("SELECT * FROM tblproducts WHERE UPPER(Stockcode) = UPPER(:sc)");
                    $pStmt->execute([':sc' => $stockcode]);
                    $prod = $pStmt->fetch();

                    $openingSOH  = (float)($prod['SOH'] ?? 0);
                    $receivedQty = (float)$line['Qty'];
                    $closingSOH  = $openingSOH + $receivedQty;

                    $newExclCost = (float)$line['ExclCost'];
                    $newInclCost = (float)$line['InclCost'];
                    $newExclSell = (float)$line['ExclSellPrice'];
                    $newInclSell = (float)$line['InclSellPrice'];

                    $gpVal  = $newExclSell - $newExclCost;
                    $gpPct  = $newExclSell > 0 ? ($gpVal / $newExclSell) * 100 : 0;
                    $markup = $newExclCost > 0 ? ($gpVal / $newExclCost) * 100 : 0;

                    // Update Stock & Financials
                    $updProd = $pdo->prepare("
                        UPDATE tblproducts SET 
                            SOH = SOH + :qty, 
                            ExclCost = :excl, 
                            InclCost = :incl, 
                            Markup = :markup,
                            GPPercentage = :gpPct,
                            GPValue = :gpVal,
                            ExclSellPrice = :sellExcl, 
                            InclSellPrice = :sellIncl,
                            LastPurchaseDate = :ts, 
                            LastPurchaseQty = :qty, 
                            LastPurchaseCost = :excl, 
                            LastUpdateDate = :ts
                        WHERE UPPER(Stockcode) = UPPER(:sc)
                    ");
                    $updProd->execute([
                        ':qty'      => $receivedQty,
                        ':excl'     => $newExclCost,
                        ':incl'     => $newInclCost,
                        ':markup'   => $markup,
                        ':gpPct'    => $gpPct,
                        ':gpVal'    => $gpVal,
                        ':sellExcl' => $newExclSell,
                        ':sellIncl' => $newInclSell,
                        ':ts'       => $nowTimestamp,
                        ':sc'       => $stockcode
                    ]);

                    // Audit Price Changes (Matches tblpricechanges table schema)
                    $insPrice = $pdo->prepare("
                        INSERT INTO tblpricechanges (
                            ChangedFrom, UpdDate, Stockcode, OldBarcode, NewBarcode, OldDescription, NewDescription, 
                            SOHBefore, SOHAfter, OldVatRate, NewVatRate, OldExclCost, NewExclCost, OldInclSellPrice, NewInclSellPrice, 
                            OldInclSellPrice2, NewInclSellPrice2, OldInclSellPrice3, NewInclSellPrice3, OldInclSellPrice4, NewInclSellPrice4, 
                            UPDCount, Username, Comments, Qty
                        ) VALUES (
                            'GRV', :updDate, :sc, '', '', '', :desc, 
                            :sohBefore, :sohAfter, 0, 0, :oldCost, :newCost, :oldSell, :newSell, 
                            0, 0, 0, 0, 0, 0, 1, :user, :comments, :qty
                        )
                    ");
                    
                    $insPrice->execute([
                        ':updDate'   => $nowTimestamp,
                        ':sc'        => $stockcode,
                        ':desc'      => $line['Description'],
                        ':sohBefore' => $openingSOH,
                        ':sohAfter'  => $closingSOH,
                        ':oldCost'   => $prod['ExclCost'] ?? 0,
                        ':newCost'   => $newExclCost,
                        ':oldSell'   => $prod['InclSellPrice'] ?? 0,
                        ':newSell'   => $newInclSell,
                        ':user'      => $username,
                        ':comments'  => "$activeGrv GRV - AFTER",
                        ':qty'       => $receivedQty
                    ]);

                    // Record Movement Log (Matches tblthiseodstockmovement schema)
                    $insMov = $pdo->prepare("
                        INSERT INTO tblthiseodstockmovement (
                            Date, TrnNr, TransactionType, Stockcode, Barcode, Description, Description2, 
                            MainDepNr, MainDepName, SubDepNr, SubDepName, PackSize, PackDescription, MasterCode, MasterDescription, 
                            OpeningSOH, OpeningSOH2, QtySold, QtyReceived, QtyAdjust, QtyStocktakeDiff, QtyTransform, ClosingSOH, ClosingSOH2, 
                            TotalCostExcl, TotalCostIncl, TotalSellingExcl, TotalSellingIncl, VatRate, LinkFlag, Username, 
                            ExclCost, InclCost, ExclSellPrice, InclSellPrice
                        ) VALUES (
                            :ts, :grv, 'GRV', :sc, :bc, :desc, :desc2, 
                            :depNr, :depName, :subNr, :subName, 1, 'EACH', '', '', 
                            :openSOH, 0, 0, :qtyRec, 0, 0, 0, :closeSOH, 0, 
                            :totCostExcl, :totCostIncl, :totSellExcl, :totSellIncl, :vat, 1, :user, 
                            :exclCost, :inclCost, :exclSell, :inclSell
                        )
                    ");
                    $insMov->execute([
                        ':ts'          => $nowTimestamp,
                        ':grv'         => $activeGrv,
                        ':sc'          => $stockcode,
                        ':bc'          => $line['Barcode'],
                        ':desc'        => $line['Description'],
                        ':desc2'       => $line['Description2'] ?? $line['Description'],
                        ':depNr'       => $line['MainDepNr'],
                        ':depName'     => $line['MainDepName'],
                        ':subNr'       => $line['SubDepNr'],
                        ':subName'     => $line['SubDepName'],
                        ':openSOH'     => $openingSOH,
                        ':qtyRec'      => $receivedQty,
                        ':closeSOH'    => $closingSOH,
                        ':totCostExcl' => $line['ExclLineTotal'],
                        ':totCostIncl' => $line['InclLineTotal'],
                        ':totSellExcl' => $newExclSell * $receivedQty,
                        ':totSellIncl' => $newInclSell * $receivedQty,
                        ':vat'         => $line['VatRate'],
                        ':user'        => $username,
                        ':exclCost'    => $newExclCost,
                        ':inclCost'    => $newInclCost,
                        ':exclSell'    => $newExclSell,
                        ':inclSell'    => $newInclSell
                    ]);
                }

                // FIXED: Explicit Column Insertion for tblthiseodgrvlines (41 Columns)
                $insEodLines = $pdo->prepare("
                    INSERT INTO tblthiseodgrvlines (
                        GRVLineID, GRVNr, Date, SupNr, SupRefNr, SupplierName, InvoiceNr, OrderNumber, Username, 
                        Stockcode, Barcode, Description, Description2, MainDepNr, MainDepName, SubDepNr, SubDepName, 
                        LastPurchaseCost, LastPurchaseCostIncl, Qty, ExclCost, VatRate, InclCost, GPPercentage, Markup, 
                        ExclSellPrice, InclSellPrice, Discount, DiscountValue, ExclLineTotal, LineVat, InclLineTotal, 
                        LastExclSellPrice, LastInclSellPrice, LinkFlag, OrderQty, OrderExclCost, OrderInclCost, CreditQty, DeliveryCharge, FreeQty
                    )
                    SELECT 
                        GRVLineID, GRVNr, :ts, :supNr, :supRefNr, :supplierName, :invoiceNr, OrderNumber, Username, 
                        Stockcode, Barcode, Description, Description2, MainDepNr, MainDepName, SubDepNr, SubDepName, 
                        LastPurchaseCost, LastPurchaseCostIncl, Qty, ExclCost, VatRate, InclCost, GPPercentage, Markup, 
                        ExclSellPrice, InclSellPrice, Discount, DiscountValue, ExclLineTotal, LineVat, InclLineTotal, 
                        LastExclSellPrice, LastInclSellPrice, 1, OrderQty, OrderExclCost, OrderInclCost, CreditQty, DeliveryCharge, FreeQty
                    FROM tblgrvlines WHERE GRVNr = :grv
                ");
                $insEodLines->execute([
                    ':ts'           => $nowTimestamp,
                    ':supNr'        => $grvHeader['SupNr'] ?? 'COD',
                    ':supRefNr'     => $grvHeader['SupRefNr'] ?? '',
                    ':supplierName' => $grvHeader['SupplierName'] ?? 'CASH SUPPLIER',
                    ':invoiceNr'    => $grvHeader['InvoiceNr'] ?? '',
                    ':grv'          => $activeGrv
                ]);

                // FIXED: Explicit Column Insertion for tblthiseodgrvinfo (22 Columns)
                $insEodInfo = $pdo->prepare("
                    INSERT INTO tblthiseodgrvinfo (
                        GRVNr, Date, SupNr, SupRefNr, SupplierName, InvoiceNr, OrderNumber, Username, 
                        InvExclTotal, InvVatTotal, InvDiscountTotal, InvInclTotal, GRVExclTotal, GRVVatTotal, 
                        GRVDiscountTotal, GRVInclTotal, GRVExtraDiscount, InvExtraDiscount, LinkFlag, InvDelivery, GRVDelivery, DeliveryRatio
                    )
                    SELECT 
                        GRVNr, :ts, SupNr, SupRefNr, SupplierName, InvoiceNr, OrderNumber, Username, 
                        InvExclTotal, InvVatTotal, InvDiscountTotal, InvInclTotal, GRVExclTotal, GRVVatTotal, 
                        GRVDiscountTotal, GRVInclTotal, GRVExtraDiscount, InvExtraDiscount, 1, InvDelivery, GRVDelivery, DeliveryRatio 
                    FROM tblgrvinfo WHERE GRVNr = :grv
                ");
                $insEodInfo->execute([':ts' => $nowTimestamp, ':grv' => $activeGrv]);

                // Cleanup Staging Tables
                $pdo->prepare("DELETE FROM tblgrvlines WHERE GRVNr = :grv")->execute([':grv' => $activeGrv]);
                $pdo->prepare("DELETE FROM tblgrvinfo WHERE GRVNr = :grv")->execute([':grv' => $activeGrv]);

                // Ledger Update
                $supAcc   = $grvHeader['SupNr'];
                $totalAmt = (float)$grvHeader['GRVInclTotal'];

                if ($supAcc) {
                    $pdo->prepare("UPDATE tblsupplierdetails SET CurrentBalance = CurrentBalance + :amt, TotalBalance = TotalBalance + :amt WHERE UPPER(AccountNr) = UPPER(:sup)")
                        ->execute([':amt' => $totalAmt, ':sup' => $supAcc]);

                    $insTx = $pdo->prepare("
                        INSERT INTO tblsuppliertransactions (TrnNr, Date, AccountNr, Company, Contact, SupplierGroup, Transaction, TrnAmount, OrderNr, PaymentMethod, Comments, LinkFlag, AmtOutstanding, Username, InvoiceNr)
                        VALUES (:grv, :ts, :acc, :comp, '', '', 'GRV', :amt, :ord, '', '', 1, :amt, :user, :inv)
                    ");
                    $insTx->execute([
                        ':grv'  => $activeGrv,
                        ':ts'   => $nowTimestamp,
                        ':acc'  => $supAcc,
                        ':comp' => $grvHeader['SupplierName'],
                        ':amt'  => $totalAmt,
                        ':ord'  => $grvHeader['OrderNumber'],
                        ':user' => $username,
                        ':inv'  => $grvHeader['InvoiceNr']
                    ]);

                    // Full Purchase Instant Cash Settlement
                    if ($isFullPurchase) {
                        $negAmt = -$totalAmt;
                        $invComment = "GRV " . $activeGrv . " INV " . $grvHeader['InvoiceNr'];

                        $insPay = $pdo->prepare("
                            INSERT INTO tblsuppliertransactions (TrnNr, Date, AccountNr, Company, Contact, SupplierGroup, Transaction, TrnAmount, OrderNr, PaymentMethod, Comments, LinkFlag, AmtOutstanding, Username, InvoiceNr)
                            VALUES (0, :ts, :acc, :comp, '', '', 'PAYMENT', :amt, '', 'CASH', :comments, 1, :amt, :user, '')
                        ");
                        $insPay->execute([
                            ':ts'       => $nowTimestamp,
                            ':acc'      => $supAcc,
                            ':comp'     => $grvHeader['SupplierName'],
                            ':amt'      => $negAmt,
                            ':comments' => $invComment,
                            ':user'     => $username
                        ]);

                        $lastTrnId = $pdo->lastInsertId();
                        if ($lastTrnId) {
                            $pdo->prepare("UPDATE tblsuppliertransactions SET TrnNr = :trnId WHERE trnID = :trnId")->execute([':trnId' => $lastTrnId]);
                        }

                        $pdo->prepare("UPDATE tblsupplierdetails SET CurrentBalance = 0.00, 30DayBalance = 0.00, 60DayBalance = 0.00, 90DayBalance = 0.00, 120DayBalance = 0.00 WHERE UPPER(AccountNr) = UPPER(:sup)")
                            ->execute([':sup' => $supAcc]);

                        $pdo->prepare("UPDATE tblsupplierdetails SET TotalBalance = TotalBalance + :amt WHERE UPPER(AccountNr) = UPPER(:sup)")
                            ->execute([':amt' => $negAmt, ':sup' => $supAcc]);
                    }
                }

                $pdo->commit();
                header("Location: purchases.php");
                exit;
            } else {
                $feedback = '<div class="alert alert-warning">Cannot finalize empty GRV. Add items first.</div>';
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $feedback = '<div class="alert alert-danger">GRV Finalize Failed: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
    }
}

// ---------------------------------------------------------------------
// 3. Calculation & Display Data
// ---------------------------------------------------------------------
$grvHeaderStmt = $pdo->prepare("SELECT * FROM tblgrvinfo WHERE GRVNr = :grv");
$grvHeaderStmt->execute([':grv' => $activeGrv]);
$grvHeader = $grvHeaderStmt->fetch();

$grvLinesStmt = $pdo->prepare("SELECT * FROM tblgrvlines WHERE GRVNr = :grv ORDER BY GRVLineID ASC");
$grvLinesStmt->execute([':grv' => $activeGrv]);
$grvLines = $grvLinesStmt->fetchAll();

$lineTotalsStmt = $pdo->prepare("
    SELECT 
        COALESCE(SUM(ExclLineTotal), 0) AS ExclTotal, 
        COALESCE(SUM(InclLineTotal), 0) AS InclTotal, 
        COALESCE(SUM(LineVat), 0) AS VATTotal
    FROM tblgrvlines WHERE GRVNr = :grv
");
$lineTotalsStmt->execute([':grv' => $activeGrv]);
$lineTotals = $lineTotalsStmt->fetch();

$updTotStmt = $pdo->prepare("
    UPDATE tblgrvinfo SET 
        GRVExclTotal = :excl, 
        GRVVatTotal = :vat, 
        GRVInclTotal = :incl 
    WHERE GRVNr = :grv
");
$updTotStmt->execute([
    ':excl' => $lineTotals['ExclTotal'],
    ':vat'  => $lineTotals['VATTotal'],
    ':incl' => $lineTotals['InclTotal'],
    ':grv'  => $activeGrv
]);

$receivingProducts = $pdo->query("
    SELECT Stockcode, Barcode, Description, SOH, ExclCost, InclSellPrice 
    FROM tblproducts WHERE ActiveProduct = 1 AND ReceivingCode = 1 ORDER BY Description ASC
")->fetchAll();

$suppliers = $pdo->query("SELECT AccountNr, Company FROM tblsupplierdetails ORDER BY Company ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Goods Received Voucher (GRV) #<?= htmlspecialchars((string)$activeGrv) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .card { border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .table-responsive { max-height: 380px; overflow-y: auto; }
        .total-box { background-color: #212529; color: #fff; padding: 15px; border-radius: 6px; }
    </style>
</head>
<body class="p-4">

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2>Goods Received Voucher <span class="badge bg-primary">#<?= htmlspecialchars((string)$activeGrv) ?></span></h2>
        <div>
            <a href="dashboard.php" class="btn btn-outline-secondary btn-sm">Main Menu</a>
        </div>
    </div>

    <?= $feedback ?>

    <!-- Section 1: Header Info -->
    <div class="card mb-3">
        <div class="card-header bg-dark text-white fw-bold">1. Supplier & Invoice Header</div>
        <div class="card-body">
            <form method="POST" action="purchases.php" class="row g-3">
                <input type="hidden" name="action" value="update_header">
                <div class="col-md-4">
                    <label class="form-label">Supplier</label>
                    <select name="sup_nr" class="form-select" onchange="this.form.submit()">
                        <option value="">-- Select Supplier --</option>
                        <?php foreach ($suppliers as $sup): ?>
                            <option value="<?= htmlspecialchars($sup['AccountNr']) ?>" <?= (($grvHeader['SupNr'] ?? '') === $sup['AccountNr']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($sup['Company']) ?> (<?= htmlspecialchars($sup['AccountNr']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Invoice / Reference #</label>
                    <input type="text" name="invoice_nr" class="form-control" value="<?= htmlspecialchars($grvHeader['InvoiceNr'] ?? '') ?>" onchange="this.form.submit()">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Order Number</label>
                    <input type="text" name="order_number" class="form-control" value="<?= htmlspecialchars($grvHeader['OrderNumber'] ?? '') ?>" onchange="this.form.submit()">
                </div>
            </form>
        </div>
    </div>

    <!-- Section 2: Line Item Entry -->
    <div class="card mb-3">
        <div class="card-header bg-secondary text-white fw-bold">2. Add Line Item</div>
        <div class="card-body">
            <form method="POST" action="purchases.php" class="row g-3">
                <input type="hidden" name="action" value="add_line">
                <div class="col-md-5">
                    <label class="form-label">Stock Item</label>
                    <select name="stockcode" id="stockSelect" class="form-select" required onchange="populateDefaults()">
                        <option value="">-- Select Receiving Product --</option>
                        <?php foreach ($receivingProducts as $p): ?>
                            <option value="<?= htmlspecialchars($p['Stockcode']) ?>" 
                                    data-cost="<?= htmlspecialchars((string)$p['ExclCost']) ?>" 
                                    data-sell="<?= htmlspecialchars((string)$p['InclSellPrice']) ?>">
                                <?= htmlspecialchars($p['Description']) ?> [<?= htmlspecialchars($p['Stockcode']) ?>] (SOH: <?= (float)$p['SOH'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Qty Received</label>
                    <input type="number" step="0.01" name="qty" class="form-control" value="1.00" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Cost Excl</label>
                    <input type="number" step="0.01" name="cost_excl" id="costExcl" class="form-control" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Selling Incl</label>
                    <input type="number" step="0.01" name="sell_price" id="sellPrice" class="form-control" required>
                </div>
                <div class="col-md-1 d-flex align-items-end">
                    <button type="submit" class="btn btn-success w-100">Add</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Section 3: Active GRV Items Grid -->
    <div class="card mb-3">
        <div class="card-header bg-light fw-bold">Current GRV Lines</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0 align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th>Stockcode</th>
                            <th>Description</th>
                            <th class="text-end">Qty</th>
                            <th class="text-end">Excl Cost</th>
                            <th class="text-end">Excl Total</th>
                            <th class="text-end">VAT Total</th>
                            <th class="text-end">Incl Total</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($grvLines)): ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">No items added to this voucher yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($grvLines as $line): ?>
                                <tr>
                                    <td><?= htmlspecialchars($line['Stockcode']) ?></td>
                                    <td><?= htmlspecialchars($line['Description']) ?></td>
                                    <td class="text-end"><?= number_format((float)$line['Qty'], 2) ?></td>
                                    <td class="text-end"><?= number_format((float)$line['ExclCost'], 2) ?></td>
                                    <td class="text-end"><?= number_format((float)$line['ExclLineTotal'], 2) ?></td>
                                    <td class="text-end"><?= number_format((float)$line['LineVat'], 2) ?></td>
                                    <td class="text-end fw-bold"><?= number_format((float)$line['InclLineTotal'], 2) ?></td>
                                    <td class="text-center">
                                        <form method="POST" action="purchases.php" style="display:inline;">
                                            <input type="hidden" name="action" value="delete_line">
                                            <input type="hidden" name="line_id" value="<?= $line['GRVLineID'] ?>">
                                            <button type="submit" class="btn btn-outline-danger btn-sm">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Section 4: Totals & Post Control -->
    <div class="row align-items-center">
        <div class="col-md-8">
            <div class="total-box d-flex justify-content-around align-items-center text-center">
                <div>
                    <small class="text-muted d-block text-uppercase">Total Excl</small>
                    <span class="fs-4 fw-bold">E <?= number_format((float)$lineTotals['ExclTotal'], 2) ?></span>
                </div>
                <div>
                    <small class="text-muted d-block text-uppercase">Total VAT</small>
                    <span class="fs-4 fw-bold">E <?= number_format((float)$lineTotals['VATTotal'], 2) ?></span>
                </div>
                <div>
                    <small class="text-muted d-block text-uppercase">Total Incl</small>
                    <span class="fs-3 fw-bold text-success">E <?= number_format((float)$lineTotals['InclTotal'], 2) ?></span>
                </div>
            </div>
        </div>
        <div class="col-md-4 text-end">
            <form method="POST" action="purchases.php" onsubmit="return confirm('Are you sure you want to finalize and post GRV #<?= htmlspecialchars((string)$activeGrv) ?>?');">
                <input type="hidden" name="action" value="finalize_grv">
                <div class="form-check form-switch text-start mb-2">
                    <input class="form-check-input" type="checkbox" id="full_purchase" name="full_purchase" value="1" checked>
                    <label class="form-check-label fw-bold" for="full_purchase">Full Purchase (Settle Cash Payment Immediately)</label>
                </div>
                <button type="submit" class="btn btn-primary btn-lg w-100 py-3 fw-bold" <?= empty($grvLines) ? 'disabled' : '' ?>>
                    Finalize & Post GRV
                </button>
            </form>
        </div>
    </div>
</div>

<script>
function populateDefaults() {
    const select = document.getElementById('stockSelect');
    const selectedOption = select.options[select.selectedIndex];
    
    if (selectedOption && selectedOption.value !== "") {
        document.getElementById('costExcl').value = parseFloat(selectedOption.getAttribute('data-cost') || 0).toFixed(2);
        document.getElementById('sellPrice').value = parseFloat(selectedOption.getAttribute('data-sell') || 0).toFixed(2);
    } else {
        document.getElementById('costExcl').value = '';
        document.getElementById('sellPrice').value = '';
    }
}
</script>
</body>
</html>