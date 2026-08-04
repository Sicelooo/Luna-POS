<?php
session_start();

$dbHost = getenv('DB_HOST') ?: '127.0.0.1';
$dbName = getenv('DB_NAME') ?: '';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: '';

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Database connection failed: ' . $e->getMessage()]);
        exit;
    }
    die("Database Connection Failed: " . $e->getMessage());
}

// ----------------------------------------------------
// FETCH COMPANY BRANDING FROM tblprintlogo
// ----------------------------------------------------
$companyInfo = [
    'name'  => 'POS Store Terminal',
    'tel'   => '',
    'cell'  => '',
    'image' => ''
];

try {
    $logoStmt = $pdo->query("
        SELECT LogoLine1, LogoTelNum, LogoCellNum, LogoImage 
        FROM tblprintlogo 
        LIMIT 1
    ");
    if ($logoData = $logoStmt->fetch()) {
        if (!empty($logoData['LogoLine1'])) {
            $companyInfo['name'] = trim($logoData['LogoLine1']);
        }
        $companyInfo['tel']  = trim($logoData['LogoTelNum'] ?? '');
        $companyInfo['cell'] = trim($logoData['LogoCellNum'] ?? '');

        if (!empty($logoData['LogoImage'])) {
            if (substr($logoData['LogoImage'], 0, 4) === "\x89PNG" || substr($logoData['LogoImage'], 0, 3) === "\xFF\xD8\xFF") {
                $companyInfo['image'] = 'data:image/png;base64,' . base64_encode($logoData['LogoImage']);
            } else {
                $companyInfo['image'] = trim($logoData['LogoImage']);
            }
        }
    }
} catch (Exception $e) {
    // Retain defaults
}

// ----------------------------------------------------
// LOGIN & LOGOUT ROUTING
// ----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    header('Content-Type: application/json');
    $userID   = trim($_POST['user_id'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($userID) || empty($password)) {
        echo json_encode(['status' => 'error', 'message' => 'Please fill in both User ID and Password.']);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT UserID, Username, Password 
        FROM tblpossecurity 
        WHERE (UPPER(UserID) = UPPER(?) OR UPPER(Username) = UPPER(?)) 
        LIMIT 1
    ");
    $stmt->execute([$userID, $userID]);
    $user = $stmt->fetch();

    if ($user && ($user['Password'] === $password || md5($password) === $user['Password'])) {
        $_SESSION['pos_user_id']  = $user['UserID'];
        $_SESSION['pos_username'] = $user['Username'] ?: $user['UserID'];
        echo json_encode(['status' => 'success', 'username' => $_SESSION['pos_username']]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid User ID or Password.']);
    }
    exit;
}

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

if (!isset($_SESSION['pos_user_id'])) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo htmlspecialchars($companyInfo['name']); ?> - Operator Login</title>
        <style>
            * { box-sizing: border-box; margin: 0; padding: 0; font-family: system-ui, -apple-system, sans-serif; }
            body { background: #1e293b; height: 100vh; display: flex; justify-content: center; align-items: center; }
            .login-card { background: #ffffff; width: 380px; padding: 35px 30px; border-radius: 12px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.4); }
            .login-header { text-align: center; margin-bottom: 25px; }
            .login-header img { max-height: 60px; width: auto; margin-bottom: 10px; }
            .login-header h2 { font-size: 22px; color: #1b4998; font-weight: 700; }
            .login-header p { font-size: 13px; color: #64748b; margin-top: 4px; }
            .form-group { margin-bottom: 18px; }
            .form-group label { display: block; font-size: 13px; font-weight: 600; color: #334155; margin-bottom: 6px; }
            .form-group input { width: 100%; padding: 12px; font-size: 15px; border: 1.5px solid #cbd5e1; border-radius: 6px; outline: none; transition: border-color 0.2s; }
            .form-group input:focus { border-color: #1b4998; }
            .btn-login { width: 100%; padding: 12px; background: #1b4998; color: #fff; border: none; border-radius: 6px; font-size: 16px; font-weight: bold; cursor: pointer; transition: background 0.2s; margin-top: 10px; }
            .btn-login:hover { background: #143775; }
            .error-box { background: #fef2f2; border: 1px solid #fca5a5; color: #991b1b; padding: 10px; border-radius: 6px; font-size: 13px; margin-bottom: 15px; display: none; }
        </style>
    </head>
    <body>
        <div class="login-card">
            <div class="login-header">
                <?php if (!empty($companyInfo['image'])): ?>
                    <img src="<?php echo $companyInfo['image']; ?>" alt="Company Logo">
                <?php else: ?>
                    <img src="LOGO.png" alt="Logo" onerror="if (this.src.endsWith('LOGO.png')) { this.src='logo.jpg'; } else { this.style.display='none'; }">
                <?php endif; ?>
                <h2><?php echo htmlspecialchars($companyInfo['name']); ?></h2>
                <p>Enter your User ID and Password to start session</p>
            </div>
            <div class="error-box" id="errorBox"></div>
            <form id="loginForm">
                <div class="form-group">
                    <label for="user_id">User ID / Username</label>
                    <input type="text" id="user_id" name="user_id" required autofocus placeholder="e.g. 101 or Admin">
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required placeholder="••••••••">
                </div>
                <button type="submit" class="btn-login">Sign In</button>
            </form>
        </div>

        <script>
            document.getElementById('loginForm').addEventListener('submit', function(e) {
                e.preventDefault();
                const errBox = document.getElementById('errorBox');
                errBox.style.display = 'none';

                const formData = new FormData(this);
                formData.append('action', 'login');

                fetch('index.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        window.location.reload();
                    } else {
                        errBox.innerText = data.message;
                        errBox.style.display = 'block';
                    }
                })
                .catch(() => {
                    errBox.innerText = 'Network or server error encountered.';
                    errBox.style.display = 'block';
                });
            });
        </script>
    </body>
    </html>
    <?php
    exit;
}

$currentUsername = $_SESSION['pos_username'];
$currentUserID   = $_SESSION['pos_user_id'];

function processCartEngine(PDO $pdo): void {
    $pdo->exec("DELETE FROM tblsale WHERE Stockcode = 'COMBODISCOUNT'");
    $pdo->exec("
        UPDATE tblsale 
        SET ExclSellPrice = DefaultSellingExcl, 
            InclSellPrice = DefaultSellingIncl, 
            Discount = 0, 
            DiscountAmount = 0, 
            ExclLineTotal = (DefaultSellingExcl * Qty), 
            InclLineTotal = (DefaultSellingIncl * Qty), 
            LineVat = ((DefaultSellingIncl * Qty) - (DefaultSellingExcl * Qty)), 
            PriceUsed = 'PRICE1' 
        WHERE PriceUsed IN ('COMBO', 'QTYGROUP')
    ");
}

function getCartState(PDO $pdo): array {
    processCartEngine($pdo);

    $cartStmt = $pdo->query("
        SELECT LineID, Stockcode, Barcode, Description, Qty, Discount, InclSellPrice, ExclSellPrice, InclLineTotal 
        FROM tblsale 
        WHERE ProductType <> 'MESSAGE' 
        ORDER BY LineID
    ");
    $items = $cartStmt->fetchAll();

    $totStmt = $pdo->query("SELECT SUM(InclLineTotal) as InclTotal, SUM(LineVAT) as SaleVAT FROM tblsale");
    $totals = $totStmt->fetch();

    return [
        'status' => 'success',
        'items'  => $items ?: [],
        'total'  => number_format((float)($totals['InclTotal'] ?? 0), 2, '.', ''),
        'vat'    => number_format((float)($totals['SaleVAT'] ?? 0), 2, '.', ''),
        'count'  => count($items)
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    try {
        if ($action === 'search_products') {
            $term = trim($_POST['term'] ?? '');
            if ($term === '') {
                $stmt = $pdo->query("
                    SELECT Stockcode, Barcode, Description, SOH, InclSellPrice 
                    FROM tblproducts 
                    WHERE SalesCode = TRUE AND ActiveProduct = TRUE 
                    ORDER BY Description LIMIT 60
                ");
            } else {
                $stmt = $pdo->prepare("
                    SELECT Stockcode, Barcode, Description, SOH, InclSellPrice 
                    FROM tblproducts 
                    WHERE SalesCode = TRUE AND ActiveProduct = TRUE 
                      AND (UPPER(Stockcode) LIKE UPPER(?) 
                        OR UPPER(Barcode) LIKE UPPER(?) 
                        OR UPPER(Description) LIKE UPPER(?)) 
                    ORDER BY Description LIMIT 60
                ");
                $likeTerm = "%{$term}%";
                $stmt->execute([$likeTerm, $likeTerm, $likeTerm]);
            }
            echo json_encode(['status' => 'success', 'products' => $stmt->fetchAll()]);
            exit;
        }

        if ($action === 'add_item') {
            $stockcode = trim($_POST['stockcode'] ?? '');

            $stmt = $pdo->prepare("
                SELECT * FROM tblproducts 
                WHERE (UPPER(Stockcode) = UPPER(?) OR UPPER(Barcode) = UPPER(?)) 
                  AND SalesCode = TRUE AND ActiveProduct = TRUE 
                LIMIT 1
            ");
            $stmt->execute([$stockcode, $stockcode]);
            $prod = $stmt->fetch();

            if (!$prod) {
                echo json_encode(['status' => 'error', 'message' => 'Item not found or inactive.']);
                exit;
            }

            $check = $pdo->prepare("SELECT LineID, Qty FROM tblsale WHERE Stockcode = ? LIMIT 1");
            $check->execute([$prod['Stockcode']]);
            $existing = $check->fetch();

            if ($existing) {
                $newQty = $existing['Qty'] + 1;
                $update = $pdo->prepare("
                    UPDATE tblsale 
                    SET Qty = ?, 
                        ExclLineTotal = (ExclSellPrice * ?), 
                        InclLineTotal = (InclSellPrice * ?), 
                        LineVat = ((InclSellPrice * ?) - (ExclSellPrice * ?)) 
                    WHERE LineID = ?
                ");
                $update->execute([$newQty, $newQty, $newQty, $newQty, $newQty, $existing['LineID']]);
            } else {
                $exclSell = (float)($prod['ExclSellPrice'] ?? ($prod['InclSellPrice'] / 1.15));
                $inclSell = (float)($prod['InclSellPrice'] ?? 0);
                $exclCost = (float)($prod['ExclCost'] ?? 0);
                $inclCost = (float)($prod['InclCost'] ?? 0);
                $vatRate  = (float)($prod['VatRate'] ?? 0);
                $vatAmt   = $inclSell - $exclSell;

                $insert = $pdo->prepare("
                    INSERT INTO tblsale (
                        RefNr, Stockcode, Barcode, Description, Description2, MainDepNr, MainDepName, SubDepNr, SubDepName, 
                        Qty, Emtpy, PackSize, PackDescription, MaxDiscount, ScaleItem, ExclCost, InclCost, VatRate, 
                        ExclSellPrice, InclSellPrice, PriceUsed, Discount, DiscountAmount, LineVat, ExclLineTotal, InclLineTotal, 
                        ProductType, LinkCode, SupplierNr, SupplierName, MasterCode, MasterDescription, Username, DieselCharge, 
                        SalesRep, CountItem, BadItem, OverrideName, FixedWeight, DefaultSellingExcl, DefaultSellingIncl, NonGPItem, TotalWeight
                    ) VALUES (
                        '', ?, ?, ?, '', ?, ?, 0, '', 
                        1.00, 0, 1, 'EACH', 0, 0, 
                        ?, ?, ?, 
                        ?, ?, 'PRICE1', 0, 0, 
                        ?, ?, ?, 
                        'NORMAL', '', '', '', '', '', ?, 0, 
                        NULL, 1, 0, NULL, 0, ?, ?, 0, 0
                    )
                ");

                $insert->execute([
                    (string)$prod['Stockcode'],
                    (string)($prod['Barcode'] ?? ''),
                    (string)$prod['Description'],
                    (int)($prod['MainDepNr'] ?? 0),
                    (string)($prod['MainDepName'] ?? ''),
                    $exclCost,
                    $inclCost,
                    $vatRate,
                    $exclSell,
                    $inclSell,
                    $vatAmt,
                    $exclSell,
                    $inclSell,
                    $currentUsername,
                    $exclSell,
                    $inclSell
                ]);
            }

            echo json_encode(getCartState($pdo));
            exit;
        }

        if ($action === 'clear_cart') {
            $pdo->query("DELETE FROM tblsale");
            echo json_encode(getCartState($pdo));
            exit;
        }

        if ($action === 'checkout') {
            $paymentAmount = floatval($_POST['payment_amount'] ?? 0);
            $paymentMethod = strtoupper(trim($_POST['payment_method'] ?? 'CASH'));
            
            $paymentCode = 1;
            if ($paymentMethod === 'CARD') {
                $paymentCode = 2;
            } elseif ($paymentMethod === 'MOMO') {
                $paymentCode = 3;
            }

            $tillName = 'POS1';

            $totalsStmt = $pdo->query("SELECT SUM(InclLineTotal) as InclTotal, SUM(LineVAT) as SaleVAT, COUNT(*) as ItemCount FROM tblsale");
            $totals = $totalsStmt->fetch();
            $saleTotal = floatval($totals['InclTotal'] ?? 0);
            $saleVat = floatval($totals['SaleVAT'] ?? 0);
            $itemCount = intval($totals['ItemCount'] ?? 0);

            if ($saleTotal <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'Cart is empty.']);
                exit;
            }

            if (($paymentMethod === 'CARD' || $paymentMethod === 'MOMO') && $paymentAmount <= 0) {
                $paymentAmount = $saleTotal;
            }

            if ($paymentAmount < $saleTotal) {
                echo json_encode(['status' => 'error', 'message' => 'Insufficient payment tendered.']);
                exit;
            }

            $changeDue = ($paymentMethod === 'CARD' || $paymentMethod === 'MOMO') ? 0.00 : ($paymentAmount - $saleTotal);

            $pdo->beginTransaction();

            $saleSeqStmt = $pdo->query("SELECT SaleNr FROM tblsalenumbers FOR UPDATE");
            $saleSeq = $saleSeqStmt->fetch();
            $saleNr = $saleSeq['SaleNr'] ?? 2833;

            $cartStmt = $pdo->query("SELECT Stockcode, Description, Qty, InclSellPrice, ExclSellPrice, InclLineTotal, ExclLineTotal FROM tblsale ORDER BY LineID");
            $soldItems = $cartStmt->fetchAll();

            $infoInsert = $pdo->prepare("
                INSERT INTO tblsaleinfo (
                    SaleNr, RefNr, Date, SaleTotal, SaleVat, PaymentAmount, PaymentMethod, Payment, ChangeDue, 
                    AccountNr, CustomerName, Username
                ) VALUES (
                    ?, '', NOW(), ?, ?, ?, ?, ?, ?, 
                    '', '', ?
                )
            ");
            $infoInsert->execute([
                $saleNr,
                $saleTotal,
                $saleVat,
                $paymentAmount,
                $paymentCode,
                $paymentMethod,
                $changeDue,
                $currentUsername
            ]);

            if ($paymentMethod === 'CARD') {
                $upd = $pdo->prepare("
                    UPDATE tblcashuptotal 
                    SET IsBusy = TRUE,
                        CardSales = CardSales + ?,
                        TotalItems = TotalItems + ?, 
                        LastSale = NOW(), 
                        TaxTotal = TaxTotal + ?, 
                        Headcount = Headcount + 1 
                    WHERE UserTillname = ?
                ");
                $upd->execute([$saleTotal, $itemCount, $saleVat, $tillName]);
            } elseif ($paymentMethod === 'MOMO') {
                $upd = $pdo->prepare("
                    UPDATE tblcashuptotal 
                    SET IsBusy = TRUE,
                        MoMoSales = MoMoSales + ?,
                        TotalItems = TotalItems + ?, 
                        LastSale = NOW(), 
                        TaxTotal = TaxTotal + ?, 
                        Headcount = Headcount + 1 
                    WHERE UserTillname = ?
                ");
                try {
                    $upd->execute([$saleTotal, $itemCount, $saleVat, $tillName]);
                } catch (Exception $e) {
                    $updFallback = $pdo->prepare("
                        UPDATE tblcashuptotal 
                        SET IsBusy = TRUE,
                            TotalItems = TotalItems + ?, 
                            LastSale = NOW(), 
                            TaxTotal = TaxTotal + ?, 
                            Headcount = Headcount + 1 
                        WHERE UserTillname = ?
                    ");
                    $updFallback->execute([$itemCount, $saleVat, $tillName]);
                }
            } else {
                $upd = $pdo->prepare("
                    UPDATE tblcashuptotal 
                    SET IsBusy = TRUE,
                        CashSales = CashSales + ?,
                        TotalItems = TotalItems + ?, 
                        LastSale = NOW(), 
                        TaxTotal = TaxTotal + ?, 
                        Headcount = Headcount + 1 
                    WHERE UserTillname = ?
                ");
                $upd->execute([$saleTotal, $itemCount, $saleVat, $tillName]);
            }

            $pdo->query("
                UPDATE tblproducts p
                INNER JOIN tblsale s ON p.Stockcode = s.Stockcode
                SET p.SOH = p.SOH - s.Qty
            ");

            $pdo->query("
                INSERT INTO tblhistory (
                    LineID, SaleNr, RefNr, Stockcode, Barcode, Description, Description2,
                    MainDepNr, MainDepName, SubDepNr, SubDepName, Qty, Emtpy, PackSize,
                    PackDescription, MaxDiscount, ScaleItem, ExclCost, InclCost, VatRate,
                    ExclSellPrice, InclSellPrice, PriceUsed, Discount, DiscountAmount, LineVat,
                    ExclLineTotal, InclLineTotal, ProductType, LinkCode, SupplierNr, SupplierName,
                    MasterCode, MasterDescription, Username, Date, SaleTotal, SaleVat,
                    PaymentTotal, ChangeDue, PaymentMethod, Payment, AccountNr, CustomerName,
                    DieselCharge, SalesRep, CountItem, BadItem, OverrideName,
                    FixedWeight, DefaultSellingExcl, DefaultSellingIncl, NonGPItem, TotalWeight
                )
                SELECT 
                    LineID, '$saleNr', RefNr, Stockcode, Barcode, Description, Description2,
                    MainDepNr, MainDepName, SubDepNr, SubDepName, Qty, Emtpy, PackSize,
                    PackDescription, MaxDiscount, ScaleItem, ExclCost, InclCost, VatRate,
                    ExclSellPrice, InclSellPrice, PriceUsed, Discount, DiscountAmount, LineVat,
                    ExclLineTotal, InclLineTotal, ProductType, LinkCode, SupplierNr, SupplierName,
                    MasterCode, MasterDescription, Username, NOW(), '$saleTotal', '$saleVat',
                    '$paymentAmount', '$changeDue', '$paymentCode', '$paymentMethod', '', '',
                    DieselCharge, SalesRep, CountItem, BadItem, OverrideName,
                    FixedWeight, DefaultSellingExcl, DefaultSellingIncl, NonGPItem, TotalWeight
                FROM tblsale
            ");

            $pdo->query("DELETE FROM tblsale");
            $pdo->query("DELETE FROM tblsalepricegroup");
            $pdo->query("DELETE FROM tblsalemessages");
            $pdo->query("DELETE FROM tblsalecombos");
            $pdo->query("DELETE FROM tblsaleloyaltylog");

            $pdo->query("UPDATE tblsalenumbers SET SaleNr = SaleNr + 1, NextOrder = NextOrder + 1");

            $pdo->commit();

            echo json_encode([
                'status'         => 'success',
                'sale_nr'        => $saleNr,
                'method'         => $paymentMethod,
                'payment_amount' => number_format($paymentAmount, 2, '.', ''),
                'change_due'     => number_format($changeDue, 2, '.', ''),
                'total'          => number_format($saleTotal, 2, '.', ''),
                'vat'            => number_format($saleVat, 2, '.', ''),
                'date'           => date('d/m/Y h:i A'),
                'username'       => $currentUsername,
                'items'          => $soldItems
            ]);
            exit;
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

$initialCart = getCartState($pdo);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($companyInfo['name']); ?> - POS Register</title>
    <script src="qrcode.min.js"></script>
    <style>
        :root {
            --pos-bg: #f8fafc;
            --pos-border: #cbd5e1;
            --pos-blue: #1b4998;
            --pos-blue-hover: #143775;
            --pos-green: #00a86b;
            --pos-green-hover: #008756;
            --pos-red: #d32f2f;
            --pos-red-hover: #b71c1c;
            --pos-amber: #f59e0b;
            --pos-purple: #8b5cf6;
            --pos-cyan: #06b6d4;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; font-family: system-ui, -apple-system, sans-serif; }
        body { background: var(--pos-bg); color: #0f172a; display: flex; flex-direction: column; height: 100vh; overflow: hidden; }
        
        .app-header { display: flex; align-items: center; justify-content: space-between; background: #ffffff; padding: 10px 20px; border-bottom: 2px solid var(--pos-border); }
        .brand-section { display: flex; align-items: center; gap: 12px; }
        .brand-logo { height: 38px; width: auto; object-fit: contain; }
        .brand-title { font-size: 20px; font-weight: 800; color: var(--pos-blue); }

        .user-section { display: flex; align-items: center; gap: 12px; }
        .user-badge { background: #e0e7ff; color: var(--pos-blue); padding: 6px 14px; border-radius: 6px; font-weight: 700; font-size: 14px; display: flex; align-items: center; gap: 6px; }
        .btn-logout { background: #f1f5f9; color: var(--pos-red); border: 1px solid #fca5a5; padding: 6px 12px; border-radius: 6px; text-decoration: none; font-size: 13px; font-weight: bold; transition: all 0.2s; }
        .btn-logout:hover { background: #fee2e2; }

        .main-container { display: flex; flex: 1; overflow: hidden; padding: 12px; gap: 12px; }
        
        /* Left Section: Item Grid & Search */
        .left-panel { flex: 1.1; display: flex; flex-direction: column; background: #ffffff; border-radius: 8px; border: 1px solid var(--pos-border); padding: 12px; }
        .search-box { width: 100%; padding: 14px; font-size: 16px; border: 2px solid var(--pos-border); background: #ffffff; color: #0f172a; border-radius: 6px; margin-bottom: 12px; outline: none; font-weight: 600; }
        .search-box:focus { border-color: var(--pos-blue); }
        
        .product-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 10px; overflow-y: auto; flex: 1; padding-right: 4px; }
        
        .product-card { 
            background-color: var(--pos-blue); 
            color: #ffffff;
            border-radius: 6px; 
            padding: 12px; 
            cursor: pointer; 
            transition: background-color 0.15s ease, transform 0.1s ease; 
            display: flex; 
            flex-direction: column; 
            justify-content: space-between; 
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .product-card:hover { 
            background-color: var(--pos-blue-hover);
            transform: translateY(-2px);
        }
        .product-card .title { font-size: 13px; font-weight: 700; line-height: 1.3; margin-bottom: 8px; text-transform: uppercase; }
        .product-card .price { font-size: 16px; font-weight: 800; }
        .product-card .soh { font-size: 11px; font-weight: 600; opacity: 0.85; margin-top: 4px; }

        /* Right Section: Split Register View */
        .right-panel { flex: 1; display: flex; flex-direction: column; gap: 10px; }
        
        .cart-table-wrapper { flex: 1; overflow-y: auto; border: 1px solid var(--pos-border); border-radius: 6px; background: #ffffff; }
        table { width: 100%; border-collapse: collapse; text-align: left; font-size: 14px; }
        th { background: #f1f5f9; padding: 10px 12px; border-bottom: 1px solid var(--pos-border); font-weight: 700; color: #475569; position: sticky; top: 0; text-transform: uppercase; font-size: 12px; }
        td { padding: 10px 12px; border-bottom: 1px solid #f1f5f9; color: #1e293b; font-weight: 600; }
        tr:hover { background: #f8fafc; }
        
        /* Total Display Box */
        .pos-display-panel { background: #e2e8f0; border-radius: 6px; padding: 12px 16px; display: flex; justify-content: space-between; align-items: center; border: 1px solid #cbd5e1; }
        .pos-display-label { font-size: 14px; font-weight: 800; color: #334155; text-transform: uppercase; }
        .pos-display-total { font-size: 2rem; font-weight: 900; color: #0f172a; font-family: monospace; }
        .pos-vat-sub { font-size: 12px; color: #64748b; font-weight: 600; }

        /* Keypad / Control Actions Grid */
        .actions-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
        .btn-pos { border: none; border-radius: 6px; padding: 14px 10px; font-size: 15px; font-weight: 800; cursor: pointer; transition: filter 0.15s ease; text-align: center; color: #ffffff; text-transform: uppercase; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .btn-pos:hover { filter: brightness(0.9); }
        .btn-pos:active { transform: translateY(1px); }

        .btn-pos-green  { background-color: var(--pos-green); }
        .btn-pos-blue   { background-color: var(--pos-blue); }
        .btn-pos-amber  { background-color: var(--pos-amber); }
        .btn-pos-red    { background-color: var(--pos-red); }
        .btn-pos-cyan   { background-color: var(--pos-cyan); }
        .btn-pos-purple { background-color: var(--pos-purple); }

        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.7); backdrop-filter: blur(2px); justify-content: center; align-items: center; z-index: 1000; }
        .modal-content { background: #ffffff; width: 380px; padding: 24px; border-radius: 8px; border: 1px solid var(--pos-border); box-shadow: 0 20px 25px -5px rgba(0,0,0,0.3); }
        .modal-content h3 { margin-bottom: 12px; color: #0f172a; font-size: 18px; font-weight: 800; }
        .modal-content input { width: 100%; padding: 12px; font-size: 22px; background: #f8fafc; border: 2px solid var(--pos-border); color: #0f172a; border-radius: 6px; margin-bottom: 16px; text-align: right; outline: none; font-weight: 700; font-family: monospace; }
        .modal-content input:focus { border-color: var(--pos-blue); background: #ffffff; }
        .modal-btns { display: flex; gap: 10px; }

        /* Thermal Till Receipt Slip Layout */
        #receiptContainer {
            display: none;
            width: 320px;
            background: #ffffff;
            padding: 20px 15px;
            font-family: 'Courier New', Courier, monospace;
            color: #000;
            font-size: 13px;
            line-height: 1.35;
        }
        .receipt-asterisks { text-align: center; font-weight: bold; margin: 6px 0; }
        .receipt-title { text-align: center; font-size: 18px; font-weight: 800; text-transform: uppercase; margin: 4px 0; }
        .receipt-header-info { margin: 12px 0 8px 0; font-weight: bold; }
        .receipt-dots { text-align: center; margin: 8px 0; color: #4b5563; }
        .receipt-items-list { width: 100%; margin: 8px 0; }
        .receipt-item-row { display: flex; justify-content: space-between; margin-bottom: 6px; }
        .receipt-summary-row { display: flex; justify-content: space-between; margin-bottom: 4px; }
        .receipt-summary-row.bold { font-weight: 800; font-size: 14px; }
        .receipt-qr-wrapper { display: flex; flex-direction: column; align-items: center; margin: 15px 0 10px 0; }
        .receipt-footer-text { display: flex; justify-content: space-between; font-weight: bold; margin-top: 12px; font-size: 12px; }

        /* Document Invoice Layout */
        #invoiceContainer {
            display: none;
            background: #ffffff;
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto;
            color: #1a202c;
            font-size: 13px;
            box-sizing: border-box;
        }
        .inv-layout { display: flex; min-height: 297mm; border: 1px solid #e2e8f0; }
        .inv-sidebar { width: 220px; background: #ffffff; border-right: 2px solid var(--pos-blue); padding: 30px 20px; display: flex; flex-direction: column; justify-content: space-between; }
        .inv-section-title { font-size: 11px; font-weight: 800; text-transform: uppercase; color: var(--pos-blue); margin-bottom: 6px; }
        .inv-company-details { font-size: 12px; line-height: 1.5; color: #4a5568; margin-bottom: 25px; }
        .inv-company-name { font-weight: 700; color: var(--pos-blue); font-size: 14px; margin-bottom: 4px; }
        .inv-side-logo img { max-width: 140px; height: auto; margin-bottom: 20px; }
        .inv-large-text { font-size: 42px; font-weight: 900; color: var(--pos-blue); letter-spacing: 2px; writing-mode: vertical-lr; transform: rotate(180deg); text-transform: uppercase; opacity: 0.9; margin-top: 40px; }
        .inv-main { flex: 1; padding: 30px 25px; display: flex; flex-direction: column; }
        .inv-meta-bar { background: #e2e8f0; padding: 12px 20px; display: flex; justify-content: space-between; margin-bottom: 25px; }
        .inv-meta-col .label { font-size: 10px; font-weight: 800; text-transform: uppercase; color: #2d3748; }
        .inv-meta-col .val { font-size: 13px; font-weight: 700; color: #1a202c; margin-top: 2px; }
        .inv-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .inv-table th { background: var(--pos-blue); color: #ffffff; text-transform: uppercase; font-size: 11px; font-weight: 700; padding: 10px 8px; text-align: left; }
        .inv-table td { padding: 10px 8px; border: 1px solid #cbd5e0; font-size: 12px; }
        .inv-bottom-section { display: flex; justify-content: flex-end; margin-top: auto; padding-top: 20px; }
        .inv-summary-row { display: flex; justify-content: space-between; background: #f7fafc; padding: 8px 12px; border: 1px solid #e2e8f0; margin-bottom: 4px; font-weight: 600; }
        .inv-summary-row.total-row { background: var(--pos-blue); color: #ffffff; font-size: 15px; font-weight: 800; }
        .inv-signatures { display: flex; justify-content: space-between; margin-top: 40px; padding-top: 15px; }
        .inv-sig-box { width: 45%; border-top: 1px solid #718096; text-align: center; font-size: 11px; color: #4a5568; padding-top: 4px; }

        @media print {
            body * { visibility: hidden !important; }
            
            body.print-slip-mode #receiptContainer, 
            body.print-slip-mode #receiptContainer * { 
                visibility: visible !important; 
            }
            body.print-slip-mode #receiptContainer {
                display: block !important;
                position: absolute;
                left: 0;
                top: 0;
                width: 100% !important;
                max-width: 80mm;
                margin: 0;
                padding: 10px;
            }

            body.print-invoice-mode #invoiceContainer, 
            body.print-invoice-mode #invoiceContainer * { 
                visibility: visible !important; 
            }
            body.print-invoice-mode #invoiceContainer {
                display: block !important;
                position: absolute;
                left: 0;
                top: 0;
                width: 210mm !important;
                min-height: 297mm !important;
                margin: 0;
                padding: 0;
            }

            @page { margin: 0; }
        }
    </style>
</head>
<body>

<header class="app-header">
    <div class="brand-section">
        <?php if (!empty($companyInfo['image'])): ?>
            <img src="<?php echo $companyInfo['image']; ?>" alt="Logo" class="brand-logo">
        <?php else: ?>
            <img src="LOGO.png" alt="Logo" class="brand-logo" onerror="if (this.src.endsWith('LOGO.png')) { this.src='logo.jpg'; } else { this.style.display='none'; }">
        <?php endif; ?>
        <span class="brand-title"><?php echo htmlspecialchars($companyInfo['name']); ?></span>
    </div>
    
    <div class="user-section">
        <div class="user-badge">
            <svg width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
              <path d="M8 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6zm2 5s-1 1-5 1-5-1-5-1 1-4 5-4 5 3 5 4z"/>
            </svg>
            <span><?php echo htmlspecialchars($currentUsername); ?></span>
        </div>
        <a href="?logout=true" class="btn-logout">Logout</a>
    </div>
</header>

<div class="main-container">
    <!-- Item Search & Grid -->
    <div class="left-panel">
        <input type="text" id="searchInput" class="search-box" placeholder="Scan barcode or search item..." autofocus>
        <div class="product-grid" id="productGrid"></div>
    </div>

    <!-- Active Register Controls -->
    <div class="right-panel">
        <div class="cart-table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Item</th>
                        <th style="text-align: center;">Qty</th>
                        <th style="text-align: right;">Price</th>
                        <th style="text-align: right;">Total</th>
                    </tr>
                </thead>
                <tbody id="cartItems"></tbody>
            </table>
        </div>

        <div class="pos-display-panel">
            <div>
                <div class="pos-display-label">Total Amount</div>
                <div class="pos-vat-sub">Includes VAT Ref: <span id="vatAmount">E 0.00</span></div>
            </div>
            <div class="pos-display-total" id="totalAmount">0.00</div>
        </div>

        <div class="actions-grid">
            <button class="btn-pos btn-pos-red" onclick="clearCart()">Void (F8)</button>
            <button class="btn-pos btn-pos-cyan" onclick="printSlip()">Till Slip</button>
            <button class="btn-pos btn-pos-purple" onclick="printInvoice()">Invoice</button>
            <button class="btn-pos btn-pos-amber" onclick="openCheckout('MOMO')">MoMo</button>
            <button class="btn-pos btn-pos-blue" onclick="openCheckout('CARD')">Card</button>
            <button class="btn-pos btn-pos-green" onclick="openCheckout('CASH')">Cash (F10)</button>
        </div>
    </div>
</div>

<div class="modal" id="checkoutModal">
    <div class="modal-content">
        <h3 id="modalTitle">Cash Tendered</h3>
        <input type="number" id="tenderedAmount" step="0.01" placeholder="0.00">
        <div class="modal-btns">
            <button class="btn-pos btn-pos-red" style="flex:1;" onclick="closeCheckout()">Cancel</button>
            <button class="btn-pos btn-pos-green" style="flex:1;" onclick="processCheckout()">Complete</button>
        </div>
    </div>
</div>

<!-- Option 1: Thermal Till Receipt Slip Output -->
<div id="receiptContainer">
    <?php if (!empty($companyInfo['image'])): ?>
        <div style="text-align: center; margin-bottom: 8px;">
            <img src="<?php echo $companyInfo['image']; ?>" style="max-width: 120px; height: auto;">
        </div>
    <?php endif; ?>

    <div class="receipt-title"><?php echo htmlspecialchars($companyInfo['name']); ?></div>
    
    <div style="text-align: center; font-size: 12px; margin-bottom: 6px;">
        <?php if (!empty($companyInfo['tel'])): ?>
            <div>Tel: <?php echo htmlspecialchars($companyInfo['tel']); ?></div>
        <?php endif; ?>
        <?php if (!empty($companyInfo['cell'])): ?>
            <div>Cell: <?php echo htmlspecialchars($companyInfo['cell']); ?></div>
        <?php endif; ?>
    </div>

    <div class="receipt-asterisks">********************************</div>

    <div class="receipt-header-info">
        <div>Receipt No: <span id="recNumber">PREVIEW</span></div>
        <div>Terminal: Terminal 01</div>
        <div>Cashier: <span id="recCashier"><?php echo htmlspecialchars($currentUsername); ?></span></div>
        <div id="recDate">01/02/2026 at 11:32 AM</div>
    </div>

    <div class="receipt-dots">................................</div>
    <div class="receipt-items-list" id="recItemsList"></div>
    <div class="receipt-dots">................................</div>

    <div class="receipt-summary-row bold">
        <span>TOTAL AMOUNT</span>
        <span id="recTotal">E 0.00</span>
    </div>
    <div class="receipt-summary-row" id="recTenderRow">
        <span id="recTenderLabel">CASH</span>
        <span id="recTenderVal">E 0.00</span>
    </div>
    <div class="receipt-summary-row" id="recChangeRow">
        <span>CHANGE</span>
        <span id="recChangeVal">E 0.00</span>
    </div>

    <div class="receipt-dots">................................</div>

    <div class="receipt-qr-wrapper">
        <div id="qrcode"></div>
    </div>

    <div class="receipt-footer-text">
        <span>*********</span>
        <span>THANK YOU!</span>
        <span>*********</span>
    </div>
</div>

<!-- Option 2: Document Invoice Layout Output -->
<div id="invoiceContainer">
    <div class="inv-layout">
        <div class="inv-sidebar">
            <div>
                <div class="inv-side-logo">
                    <?php if (!empty($companyInfo['image'])): ?>
                        <img src="<?php echo $companyInfo['image']; ?>" alt="Company Logo">
                    <?php else: ?>
                        <img src="LOGO.png" alt="Company Logo" onerror="if (this.src.endsWith('LOGO.png')) { this.src='logo.jpg'; } else { this.style.display='none'; }">
                    <?php endif; ?>
                </div>
                <div class="inv-section-title">SELLER</div>
                <div class="inv-company-details">
                    <div class="inv-company-name"><?php echo htmlspecialchars($companyInfo['name']); ?></div>
                    <?php if (!empty($companyInfo['tel'])): ?>
                        <div>Tel: <?php echo htmlspecialchars($companyInfo['tel']); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($companyInfo['cell'])): ?>
                        <div>Cell: <?php echo htmlspecialchars($companyInfo['cell']); ?></div>
                    <?php endif; ?>
                    <div>Company VAT Ref: 100239481</div>
                </div>
                <div class="inv-section-title">BUYER</div>
                <div class="inv-company-details">
                    <div class="inv-company-name">Cash Customer</div>
                    <div>General Retail Account</div>
                </div>
                <div class="inv-large-text">INVOICE</div>
            </div>
        </div>

        <div class="inv-main">
            <div class="inv-meta-bar">
                <div class="inv-meta-col">
                    <div class="label">INVOICE NUMBER:</div>
                    <div class="val" id="invNumber">INV-2833</div>
                </div>
                <div class="inv-meta-col">
                    <div class="label">RECEIPT NO:</div>
                    <div class="val" id="invReceiptNumber">2833</div>
                </div>
                <div class="inv-meta-col">
                    <div class="label">DATE OF ISSUE:</div>
                    <div class="val" id="invDate">28/07/2026</div>
                </div>
                <div class="inv-meta-col">
                    <div class="label">OPERATOR:</div>
                    <div class="val" id="invOperator"><?php echo htmlspecialchars($currentUsername); ?></div>
                </div>
            </div>

            <table class="inv-table">
                <thead>
                    <tr>
                        <th style="width: 50%;">PRODUCT DESCRIPTION</th>
                        <th style="width: 15%; text-align: center;">QTY</th>
                        <th style="width: 17%; text-align: right;">PRICE</th>
                        <th style="width: 18%; text-align: right;">TOTAL</th>
                    </tr>
                </thead>
                <tbody id="invItemsList"></tbody>
            </table>

            <div class="inv-bottom-section">
                <div style="width: 40%;">
                    <div class="inv-summary-row">
                        <span>SUBTOTAL (EXCL)</span>
                        <span id="invSubtotal">E 0.00</span>
                    </div>
                    <div class="inv-summary-row">
                        <span>VAT TOTAL</span>
                        <span id="invVat">E 0.00</span>
                    </div>
                    <div class="inv-summary-row total-row">
                        <span>TOTAL</span>
                        <span id="invTotal">E 0.00</span>
                    </div>
                </div>
            </div>

            <div class="inv-signatures">
                <div class="inv-sig-box">Signature (Seller)</div>
                <div class="inv-sig-box">Signature (Buyer)</div>
            </div>
        </div>
    </div>
</div>

<script>
    const ACTIVE_USERNAME = <?php echo json_encode($currentUsername); ?>;
    let initialCartState = <?php echo json_encode($initialCart); ?>;
    let selectedPaymentMethod = 'CASH';

    document.addEventListener('DOMContentLoaded', () => {
        renderCart(initialCartState);
        searchProducts();

        const searchInput = document.getElementById('searchInput');
        let timer;
        
        searchInput.addEventListener('input', () => {
            clearTimeout(timer);
            timer = setTimeout(searchProducts, 200);
        });

        // BARCODE SCAN / ENTER KEY PRESS EVENT
        searchInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                const term = searchInput.value.trim();
                if (term !== '') {
                    addItemToCart(term);
                }
            }
        });

        // KEYBOARD SHORTCUT ENGINE
        document.addEventListener('keydown', (e) => {
            if (e.key === 'F10') {
                e.preventDefault();
                const modal = document.getElementById('checkoutModal');
                if (modal.style.display === 'flex') {
                    processCheckout();
                } else {
                    openCheckout('CASH');
                }
            } else if (e.key === 'F8') {
                e.preventDefault();
                clearCart();
            } else if (e.key === 'Escape') {
                closeCheckout();
            }
        });

        // Safe reload when print dialog completes or closes
        window.addEventListener('afterprint', () => {
            window.location.reload();
        });
    });

    function postData(action, data = {}) {
        const formData = new FormData();
        formData.append('action', action);
        for (let key in data) {
            formData.append(key, data[key]);
        }
        return fetch('index.php', { method: 'POST', body: formData })
            .then(res => res.text())
            .then(text => JSON.parse(text));
    }

    function searchProducts() {
        const term = document.getElementById('searchInput').value;
        postData('search_products', { term }).then(res => {
            if (res.status === 'success') {
                const grid = document.getElementById('productGrid');
                grid.innerHTML = '';
                res.products.forEach((p) => {
                    const card = document.createElement('div');
                    card.className = 'product-card';
                    card.onclick = () => addItemToCart(p.Stockcode);
                    card.innerHTML = `
                        <div>
                            <div class="title">${p.Description}</div>
                            <div class="soh">SOH: ${p.SOH}</div>
                        </div>
                        <div class="price">E ${parseFloat(p.InclSellPrice).toFixed(2)}</div>
                    `;
                    grid.appendChild(card);
                });
            }
        });
    }

    function addItemToCart(stockcode) {
        postData('add_item', { stockcode }).then(res => {
            if (res.status === 'success') {
                renderCart(res);
            } else {
                alert(res.message);
            }
            const searchInput = document.getElementById('searchInput');
            searchInput.value = '';
            searchInput.focus();
            searchProducts();
        });
    }

    function clearCart() {
        if (confirm('Void / Clear active basket?')) {
            postData('clear_cart').then(res => {
                renderCart(res);
                const searchInput = document.getElementById('searchInput');
                searchInput.value = '';
                searchInput.focus();
                searchProducts();
            });
        }
    }

    function renderCart(cart) {
        const tbody = document.getElementById('cartItems');
        tbody.innerHTML = '';
        
        cart.items.forEach(item => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${item.Description}</td>
                <td style="text-align: center;">${parseInt(item.Qty)}</td>
                <td style="text-align: right;">E ${parseFloat(item.InclSellPrice).toFixed(2)}</td>
                <td style="text-align: right;">E ${parseFloat(item.InclLineTotal).toFixed(2)}</td>
            `;
            tbody.appendChild(tr);
        });

        document.getElementById('vatAmount').innerText = `E ${cart.vat}`;
        document.getElementById('totalAmount').innerText = `E ${cart.total}`;
    }

    function getCartPayload() {
        const currentItems = [];
        const rows = document.querySelectorAll('#cartItems tr');
        rows.forEach(r => {
            const cols = r.querySelectorAll('td');
            if (cols.length >= 4) {
                const qtyVal = parseFloat(cols[1].innerText);
                const lineTotalVal = parseFloat(cols[3].innerText.replace('E ', ''));
                currentItems.push({
                    Description: cols[0].innerText,
                    Qty: qtyVal,
                    InclSellPrice: cols[2].innerText.replace('E ', ''),
                    InclLineTotal: lineTotalVal,
                    ExclLineTotal: (lineTotalVal / 1.15)
                });
            }
        });
        return {
            sale_nr: 'PREVIEW',
            date: new Date().toLocaleString('en-GB'),
            username: ACTIVE_USERNAME,
            items: currentItems,
            total: document.getElementById('totalAmount').innerText.replace('E ', ''),
            vat: document.getElementById('vatAmount').innerText.replace('E ', ''),
            method: 'CASH',
            payment_amount: document.getElementById('totalAmount').innerText.replace('E ', ''),
            change_due: '0.00'
        };
    }

    function generateReceiptHTML(data) {
        document.getElementById('recNumber').innerText = data.sale_nr || 'PREVIEW';
        document.getElementById('recDate').innerText = data.date || new Date().toLocaleString();
        document.getElementById('recCashier').innerText = data.username || ACTIVE_USERNAME;
        
        const itemsContainer = document.getElementById('recItemsList');
        itemsContainer.innerHTML = '';
        if (data.items) {
            data.items.forEach(item => {
                const row = document.createElement('div');
                row.className = 'receipt-item-row';
                row.innerHTML = `
                    <span>${parseInt(item.Qty)}x ${item.Description}</span>
                    <strong>E ${parseFloat(item.InclLineTotal).toFixed(2)}</strong>
                `;
                itemsContainer.appendChild(row);
            });
        }

        document.getElementById('recTotal').innerText = `E ${data.total}`;
        document.getElementById('recTenderLabel').innerText = data.method || 'CASH';
        document.getElementById('recTenderVal').innerText = `E ${data.payment_amount || data.total}`;
        document.getElementById('recChangeVal').innerText = `E ${data.change_due || '0.00'}`;

        const qrElem = document.getElementById('qrcode');
        qrElem.innerHTML = '';
        new QRCode(qrElem, { text: `SALE-${data.sale_nr || '0'}`, width: 80, height: 80 });
    }

    function generateInvoiceHTML(data) {
        const receiptNo = data.sale_nr || 'DRAFT';
        document.getElementById('invNumber').innerText = `INV-${receiptNo}`;
        document.getElementById('invReceiptNumber').innerText = receiptNo;
        
        document.getElementById('invDate').innerText = data.date || new Date().toLocaleDateString();
        document.getElementById('invOperator').innerText = data.username || ACTIVE_USERNAME;
        
        const itemsContainer = document.getElementById('invItemsList');
        itemsContainer.innerHTML = '';

        let subtotalExcl = 0;
        if (data.items) {
            data.items.forEach(item => {
                const qty = parseFloat(item.Qty) || 1;
                const inclPrice = parseFloat(item.InclSellPrice) || 0;
                const lineTotal = parseFloat(item.InclLineTotal) || (qty * inclPrice);
                subtotalExcl += (item.ExclLineTotal ? parseFloat(item.ExclLineTotal) : (lineTotal / 1.15));

                const row = document.createElement('tr');
                row.innerHTML = `
                    <td>${item.Description}</td>
                    <td style="text-align: center;">${parseInt(qty)}</td>
                    <td style="text-align: right;">E ${inclPrice.toFixed(2)}</td>
                    <td style="text-align: right;">E ${lineTotal.toFixed(2)}</td>
                `;
                itemsContainer.appendChild(row);
            });
        }

        const totalVal = parseFloat(data.total) || 0;
        const vatVal = data.vat ? parseFloat(data.vat) : (totalVal - subtotalExcl);

        document.getElementById('invSubtotal').innerText = `E ${subtotalExcl.toFixed(2)}`;
        document.getElementById('invVat').innerText = `E ${vatVal.toFixed(2)}`;
        document.getElementById('invTotal').innerText = `E ${totalVal.toFixed(2)}`;
    }

    function printSlip() {
        const cartTotal = parseFloat(document.getElementById('totalAmount').innerText.replace('E ', ''));
        if (cartTotal <= 0) return alert('Cart is empty!');
        
        document.body.className = 'print-slip-mode';
        generateReceiptHTML(getCartPayload());
        setTimeout(() => { window.print(); }, 200);
    }

    function printInvoice() {
        const cartTotal = parseFloat(document.getElementById('totalAmount').innerText.replace('E ', ''));
        if (cartTotal <= 0) return alert('Cart is empty!');

        document.body.className = 'print-invoice-mode';
        generateInvoiceHTML(getCartPayload());
        setTimeout(() => { window.print(); }, 200);
    }

    function openCheckout(method = 'CASH') {
        const total = parseFloat(document.getElementById('totalAmount').innerText.replace('E ', ''));
        if (total <= 0) return alert('Cart is empty!');

        selectedPaymentMethod = method;
        const inputElem = document.getElementById('tenderedAmount');
        inputElem.value = total.toFixed(2);
        
        if (method === 'CARD' || method === 'MOMO') {
            if (confirm(`Process ${method} Payment for E ${total.toFixed(2)}?`)) processCheckout();
        } else {
            document.getElementById('modalTitle').innerText = 'Cash Tendered';
            document.getElementById('checkoutModal').style.display = 'flex';
            
            setTimeout(() => {
                inputElem.focus();
                inputElem.select();
            }, 50);
        }
    }

    function closeCheckout() {
        document.getElementById('checkoutModal').style.display = 'none';
        const searchInput = document.getElementById('searchInput');
        searchInput.focus();
    }

    function processCheckout() {
        const amount = document.getElementById('tenderedAmount').value;
        postData('checkout', { payment_amount: amount, payment_method: selectedPaymentMethod }).then(res => {
            if (res.status === 'success') {
                closeCheckout();
                
                document.body.className = 'print-slip-mode';
                generateReceiptHTML(res);
                
                setTimeout(() => {
                    window.print();
                    setTimeout(() => { window.location.reload(); }, 1500);
                }, 300);
            } else {
                alert(res.message);
            }
        });
    }
</script>
</body>
</html>