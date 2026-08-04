<?php
// ---------------------------------------------------------------------
// LUNA POS - Terminal Initialization & Startup Diagnostic
// ---------------------------------------------------------------------
session_start();

// Detect if request expects JSON (e.g. Fetch / Axios API calls)
$isJsonRequest = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') 
    || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
    || isset($_GET['format']) && $_GET['format'] === 'json';

$dbHost = 'localhost';
$dbName = '';
$dbUser = 'root';
$dbPass = '';

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    if ($isJsonRequest) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Database connection failed: ' . $e->getMessage()]);
        exit;
    } else {
        die("Database Connection Error: " . $e->getMessage());
    }
}

$computerName  = 'LUNAPOS';
$userTillname  = 'POS1';
$inputPassword = $_POST['password'] ?? '1';

$response = [
    'status'    => 'success',
    'timestamp' => date('Y-m-d H:i:s'),
    'data'      => []
];

try {
    // 1. Fetch POS Settings
    $stmt = $pdo->query("SELECT * FROM tblpossettings");
    $response['data']['posSettings'] = $stmt->fetch();

    // 2. Fetch Branding & Print Logos
    $stmt = $pdo->query("
        SELECT ID, LogoLine1, LogoLine2, LogoTelNum, LogoCellNum, LogoEmailAdd, LogoAdd1, LogoAdd2, LogoAdd3, 
               LogoAdd4, LogoVatNr, LogoWebSite, LogoCKNumber, LogoRegNumber, LogoComment1, LogoComment2, LogoBankDetail, 
               SlipFooter, SlipFooter2, LogoQuoteComment1, LogoQuoteComment2, LogoCSDNr, LogoLoyaltySaving, StorePrefix 
        FROM tblprintlogo
    ");
    $response['data']['printLogo'] = $stmt->fetch();

    // 3. Current Sale Counter
    $stmt = $pdo->query("SELECT * FROM tblsalenumbers");
    $response['data']['saleNumbers'] = $stmt->fetch();

    // 4. Terminal License Check
    $stmt = $pdo->prepare("SELECT ComputerName, CheckSum, Expires, ExpiryDate, LisenseNumber FROM tblregistration WHERE ComputerName = :computerName");
    $stmt->execute([':computerName' => $computerName]);
    $registration = $stmt->fetch();

    if (!$registration) {
        throw new Exception("Unauthorized terminal registration: " . $computerName);
    }
    $response['data']['registration'] = $registration;

    // 5. Security & Operator Permissions
    $stmt = $pdo->prepare("
        SELECT UserID, Username, Password, VoidLine, VoidSale, SplitTender, CashSales, CardSales, 
               OfflineCard, ChequeSales, AccountSales, EFTSales, VoucherSales, LoyaltyRedeem, ROA, Reprint, PriceLookup, NoSale, 
               SaveLoad, ExitPOS, Cashup, CashupTotals, Refund, Supervisor, GlobalDiscount, LineDiscount, SelectPrice, PriceOverride, 
               Payout, ViewandOpenAllTabs, CashWithdrawal, TogglePrinter, OpenItem, Cashback, SplitBill, SelectCustomer, 
               SafePaySupervisor, SendOrder, Empties, OfflineCardCashback, CashGuardRegret, SendMessage, LoginPOS, AccountsFree, 
               ScaleOverride, XReading, Quotations   
        FROM tblpossecurity 
        WHERE UPPER(Password) = UPPER(:password)
    ");
    $stmt->execute([':password' => $inputPassword]);
    $userSecurity = $stmt->fetch();

    if (!$userSecurity) {
        throw new Exception("Invalid operator passcode");
    }
    $response['data']['currentUser'] = $userSecurity;

    // 6. Lock Till
    $stmt = $pdo->prepare("UPDATE tblcashuptotal SET IsBusy = TRUE WHERE UserTillname = :till");
    $stmt->execute([':till' => $userTillname]);
    $response['data']['tillStatus'] = ['tillName' => $userTillname, 'isBusy' => true];

    // 7. Active Cart Items
    $stmt = $pdo->query("SELECT LineId, Stockcode, Barcode, Description, Qty, Discount, InclSellPrice, ExclSellPrice, InclLineTotal, ScaleItem, PriceUsed, FixedWeight FROM tblsale WHERE ProductType <> 'MESSAGE' ORDER BY LineID ASC");
    $response['data']['activeBasket'] = $stmt->fetchAll();

    // Cart Totals
    $stmt = $pdo->query("SELECT SUM(InclLineTotal) as InclTotal, SUM(LineVAT) as SaleVAT FROM tblsale");
    $response['data']['basketTotals'] = $stmt->fetch();

    // 8. Navigation Departments & Catalog
    $stmt = $pdo->query("SELECT * FROM tbldepbuttons WHERE SubNum = 0 AND (ButtonNr, SubNum) NOT IN (SELECT ButtonNr, SubNum FROM tbldepbuttonstoskip) ORDER BY ButtonNr ASC");
    $response['data']['departmentButtons'] = $stmt->fetchAll();

    $stmt = $pdo->query("SELECT UPPER(Stockcode) AS Stockcode, Barcode, Description, InclSellPrice, SOH FROM tblproducts WHERE SalesCode = TRUE AND ActiveProduct = TRUE LIMIT 50");
    $response['data']['productsCatalog'] = $stmt->fetchAll();

    $_SESSION['pos_user'] = $userSecurity['Username'];
    $_SESSION['till_name'] = $userTillname;

} catch (Exception $e) {
    $response['status'] = 'error';
    $response['message'] = $e->getMessage();
}

// IF API/AJAX: Output JSON directly
if ($isJsonRequest) {
    header('Content-Type: application/json');
    echo json_encode($response, JSON_PRETTY_PRINT);
    exit;
}

// IF DIRECT BROWSER LOAD: Output Graphical Interface
$data = $response['data'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Luna POS - Startup Status</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-slate-900 text-slate-100 min-h-screen p-6 font-sans">

    <div class="max-w-5xl mx-auto space-y-6">
        
        <!-- Header status -->
        <div class="bg-slate-800 border border-slate-700 rounded-2xl p-6 flex justify-between items-center shadow-xl">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 rounded-xl bg-indigo-600/20 text-indigo-400 flex items-center justify-center font-bold text-xl border border-indigo-500/30">
                    <i class="fa-solid fa-server"></i>
                </div>
                <div>
                    <h1 class="text-xl font-bold">Luna POS Initialization</h1>
                    <p class="text-xs text-slate-400">Database Connection & Load Sequence Verified</p>
                </div>
            </div>
            
            <a href="index.php" class="bg-emerald-600 hover:bg-emerald-500 text-white font-bold py-2.5 px-5 rounded-xl transition flex items-center gap-2 shadow-lg shadow-emerald-900/30">
                <i class="fa-solid fa-cash-register"></i> Open POS Terminal
            </a>
        </div>

        <!-- Metric Grid -->
        <div class="grid grid-cols-4 gap-4">
            <div class="bg-slate-800/80 border border-slate-700/60 p-4 rounded-xl">
                <span class="text-xs text-slate-400 font-semibold uppercase">Terminal ID</span>
                <p class="text-lg font-bold text-indigo-400 font-mono mt-1"><?= htmlspecialchars($data['registration']['ComputerName'] ?? 'N/A') ?></p>
            </div>
            <div class="bg-slate-800/80 border border-slate-700/60 p-4 rounded-xl">
                <span class="text-xs text-slate-400 font-semibold uppercase">Active Operator</span>
                <p class="text-lg font-bold text-slate-200 mt-1"><?= htmlspecialchars($data['currentUser']['Username'] ?? 'N/A') ?></p>
            </div>
            <div class="bg-slate-800/80 border border-slate-700/60 p-4 rounded-xl">
                <span class="text-xs text-slate-400 font-semibold uppercase">Next Sale Ref</span>
                <p class="text-lg font-bold text-amber-400 font-mono mt-1">#<?= htmlspecialchars($data['saleNumbers']['SaleNr'] ?? '0') ?></p>
            </div>
            <div class="bg-slate-800/80 border border-slate-700/60 p-4 rounded-xl">
                <span class="text-xs text-slate-400 font-semibold uppercase">Pending Items</span>
                <p class="text-lg font-bold text-emerald-400 font-mono mt-1"><?= count($data['activeBasket'] ?? []) ?> Lines</p>
            </div>
        </div>

        <!-- Detail Panels -->
        <div class="grid grid-cols-2 gap-6">
            <!-- Branding Header -->
            <div class="bg-slate-800/50 border border-slate-700/60 rounded-xl p-5">
                <h3 class="text-sm font-bold text-slate-300 uppercase tracking-wider mb-3 flex items-center gap-2">
                    <i class="fa-solid fa-store text-indigo-400"></i> Register Store Info
                </h3>
                <div class="space-y-2 text-sm">
                    <p><strong class="text-slate-400">Store Header:</strong> <?= htmlspecialchars($data['printLogo']['LogoLine1'] ?? 'Default Store') ?></p>
                    <p><strong class="text-slate-400">Phone:</strong> <?= htmlspecialchars($data['printLogo']['LogoTelNum'] ?? 'N/A') ?></p>
                    <p><strong class="text-slate-400">VAT Registration:</strong> <?= htmlspecialchars($data['printLogo']['LogoVatNr'] ?? 'N/A') ?></p>
                    <p><strong class="text-slate-400">Receipt Footer:</strong> <?= htmlspecialchars($data['printLogo']['SlipFooter'] ?? 'Thank You') ?></p>
                </div>
            </div>

            <!-- Operator Flags -->
            <div class="bg-slate-800/50 border border-slate-700/60 rounded-xl p-5">
                <h3 class="text-sm font-bold text-slate-300 uppercase tracking-wider mb-3 flex items-center gap-2">
                    <i class="fa-solid fa-shield-halved text-indigo-400"></i> Key Security Flags
                </h3>
                <div class="grid grid-cols-2 gap-2 text-xs font-mono">
                    <div class="bg-slate-900/60 p-2 rounded border border-slate-700/40">
                        <span class="text-slate-400">Void Sale:</span> 
                        <strong class="float-right text-<?= !empty($data['currentUser']['VoidSale']) ? 'emerald' : 'rose' ?>-400"><?= !empty($data['currentUser']['VoidSale']) ? 'ALLOWED' : 'LOCKED' ?></strong>
                    </div>
                    <div class="bg-slate-900/60 p-2 rounded border border-slate-700/40">
                        <span class="text-slate-400">Supervisor:</span> 
                        <strong class="float-right text-<?= !empty($data['currentUser']['Supervisor']) ? 'emerald' : 'rose' ?>-400"><?= !empty($data['currentUser']['Supervisor']) ? 'YES' : 'NO' ?></strong>
                    </div>
                    <div class="bg-slate-900/60 p-2 rounded border border-slate-700/40">
                        <span class="text-slate-400">Price Override:</span> 
                        <strong class="float-right text-<?= !empty($data['currentUser']['PriceOverride']) ? 'emerald' : 'rose' ?>-400"><?= !empty($data['currentUser']['PriceOverride']) ? 'ALLOWED' : 'LOCKED' ?></strong>
                    </div>
                    <div class="bg-slate-900/60 p-2 rounded border border-slate-700/40">
                        <span class="text-slate-400">Cashup:</span> 
                        <strong class="float-right text-<?= !empty($data['currentUser']['Cashup']) ? 'emerald' : 'rose' ?>-400"><?= !empty($data['currentUser']['Cashup']) ? 'ALLOWED' : 'LOCKED' ?></strong>
                    </div>
                </div>
            </div>
        </div>

    </div>

</body>
</html>