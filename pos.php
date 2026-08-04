<?php
require_once 'db.php';

// Handle AJAX Request: Dynamic Product Search
if (isset($_GET['action']) && $_GET['action'] === 'search_item') {
    header('Content-Type: application/json');
    $term = trim($_GET['term'] ?? '');
    
    if (empty($term)) {
        echo json_encode([]);
        exit;
    }

    $stmt = $pdo->prepare("SELECT Stockcode, Description, InclSellingPrice FROM stock WHERE Stockcode LIKE :term OR Description LIKE :term LIMIT 10");
    $stmt->execute([':term' => "%$term%"]);
    echo json_encode($stmt->fetchAll());
    exit;
}

// Handle AJAX Request: Complete Transaction
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'complete_sale') {
    header('Content-Type: application/json');
    $cart = json_decode($_POST['cart'] ?? '[]', true);
    $paymentMethod = $_POST['payment_method'] ?? 'CASH';
    $customerName = $_POST['customer_name'] ?? 'Walk-In Customer';
    $tendered = floatval($_POST['tendered_amount'] ?? 0);
    
    if (empty($cart)) {
        echo json_encode(['success' => false, 'message' => 'Cart is empty.']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        $subtotal = 0;
        foreach ($cart as $item) {
            $subtotal += floatval($item['total']);
        }
        $vat = $subtotal * 0.15; // 15% VAT calculation standard
        $total = $subtotal;

        $saleNr = date('YmdHis') . rand(10, 99);
        $refNr = 'REF' . $saleNr;
        
        $paymentAmount = ($tendered >= $total) ? $tendered : $total;
        $changeDue = max(0, $paymentAmount - $total);

        // 1. Insert Master Sale Record into histsaleinfo
        $stmtSale = $pdo->prepare("INSERT INTO histsaleinfo 
            (SaleNr, RefNr, TillName, Username, Date, SaleTotal, SaleVat, Payment, PaymentAmount, ChangeDue, CustomerName) 
            VALUES (:sale_nr, :ref_nr, 'POS-1', 'Admin', NOW(), :total, :vat, :payment, :payment_amount, :change_due, :customer)");
        
        $stmtSale->execute([
            ':sale_nr'        => $saleNr,
            ':ref_nr'         => $refNr,
            ':total'          => $total,
            ':vat'            => $vat,
            ':payment'        => $paymentMethod,
            ':payment_amount' => $paymentAmount,
            ':change_due'     => $changeDue,
            ':customer'       => $customerName
        ]);

        // 2. Insert Line Items into histhistory
        $stmtItem = $pdo->prepare("INSERT INTO histhistory 
            (SaleNr, Stockcode, Description, Qty, InclSellPrice, InclLineTotal, Date) 
            VALUES (:sale_nr, :code, :desc, :qty, :price, :line_total, NOW())");

        foreach ($cart as $item) {
            $stmtItem->execute([
                ':sale_nr'    => $saleNr,
                ':code'       => $item['code'],
                ':desc'       => $item['desc'],
                ':qty'        => $item['qty'],
                ':price'      => $item['price'],
                ':line_total' => $item['total']
            ]);
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'sale_nr' => $saleNr]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Fetch Current Shift Statistics
$today = date('Y-m-d');
$todayStats = $pdo->prepare("SELECT COUNT(DISTINCT SaleNr) as count, SUM(SaleTotal) as total FROM histsaleinfo WHERE DATE(Date) = :today");
$todayStats->execute([':today' => $today]);
$stats = $todayStats->fetch();

$todayCount = $stats['count'] ?? 0;
$todayTotal = $stats['total'] ?? 0.00;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POS Terminal - Aberrant DB</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root {
            --pos-bg: #0f172a;
            --pos-card-bg: #1e293b;
            --pos-accent: #0ea5e9;
            --pos-border: #334155;
            --pos-text: #f8fafc;
        }
        body { background-color: var(--pos-bg); color: var(--pos-text); font-family: system-ui, -apple-system, sans-serif; height: 100vh; display: flex; flex-direction: column; overflow: hidden; }
        .pos-header { background-color: var(--pos-card-bg); border-bottom: 1px solid var(--pos-border); }
        .pos-display { background: linear-gradient(135deg, #0284c7, #0369a1); color: #ffffff; border-radius: 12px; padding: 1rem 1.5rem; }
        .pos-total-box { background: rgba(0, 0, 0, 0.25); border-radius: 8px; padding: 0.5rem 1.25rem; border: 1px solid rgba(255, 255, 255, 0.15); }
        .pos-card { background-color: var(--pos-card-bg); border: 1px solid var(--pos-border); border-radius: 12px; }
        .cart-table-wrapper { height: calc(100vh - 430px); min-height: 220px; overflow-y: auto; }
        .table-dark-custom { --bs-table-bg: var(--pos-card-bg); --bs-table-color: #cbd5e1; --bs-table-border-color: var(--pos-border); }
        .table-dark-custom thead th { background-color: #0f172a; color: #94a3b8; font-size: 0.8rem; text-transform: uppercase; position: sticky; top: 0; z-index: 2; }
        .form-control-pos { background-color: #0f172a; border: 1px solid var(--pos-border); color: #ffffff; font-weight: 600; }
        .form-control-pos:focus { background-color: #0f172a; color: #ffffff; border-color: var(--pos-accent); shadow: none; }
        .numpad-btn { background-color: #0f172a; color: #f8fafc; border: 1px solid var(--pos-border); font-size: 1.25rem; font-weight: 600; border-radius: 8px; height: 48px; }
        .numpad-btn:hover { background-color: #334155; color: #ffffff; }
        .btn-quick-item { background: linear-gradient(135deg, #334155, #1e293b); border: 1px solid #475569; color: #f8fafc; font-weight: 600; border-radius: 8px; height: 50px; }
        .btn-action-pay { background-color: #10b981; color: #fff; font-size: 1.2rem; border: none; }
        .btn-action-pay:hover { background-color: #059669; color: #fff; }
        .status-bar { background-color: #090d16; border-top: 1px solid var(--pos-border); font-size: 0.8rem; color: #94a3b8; }
        .search-results { position: absolute; top: 100%; left: 0; right: 0; z-index: 1000; background: #1e293b; border: 1px solid #334155; max-height: 220px; overflow-y: auto; }
        .search-results div { padding: 10px 14px; cursor: pointer; border-bottom: 1px solid #334155; }
        .search-results div:hover { background: #0ea5e9; color: white; }
    </style>
</head>
<body>

    <header class="pos-header px-3 py-2 d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-3">
            <span class="fw-bold text-white fs-5"><i class="bi bi-cart4 text-info me-2"></i>Terminal 01</span>
            <span class="badge bg-info-subtle text-info border border-info-subtle rounded-pill px-3 py-1">Online</span>
        </div>
        <div class="d-none d-md-flex align-items-center gap-4 text-white-50 small">
            <div>Shift Transactions: <strong class="text-white"><?= number_format($todayCount) ?></strong></div>
            <div>Shift Sales: <strong class="text-info">$<?= number_format($todayTotal, 2) ?></strong></div>
        </div>
        <div>
            <a href="index.php" class="btn btn-sm btn-outline-info"><i class="bi bi-speedometer2 me-1"></i> Dashboard</a>
        </div>
    </header>

    <div class="container-fluid flex-grow-1 p-3 d-flex flex-column gap-3 overflow-hidden">
        
        <!-- POS Customer Display -->
        <div class="pos-display d-flex flex-wrap justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-3">
                <div class="bg-white rounded-3 p-2 d-none d-sm-block"><i class="bi bi-bag-check text-primary fs-3"></i></div>
                <div>
                    <h3 class="fw-bold m-0 text-white" id="last-item-desc">READY TO SCAN</h3>
                    <small class="text-white-50">Current Item / Status</small>
                </div>
            </div>
            <div class="pos-total-box text-end mt-2 mt-md-0">
                <span class="text-uppercase text-white-50 fw-semibold small d-block">Grand Total</span>
                <span class="fs-1 fw-bolder font-monospace text-white" id="grand-total">$0.00</span>
            </div>
        </div>

        <div class="row g-3 flex-grow-1 overflow-hidden">
            <!-- Left Side Cart & Search -->
            <div class="col-12 col-lg-7 d-flex flex-column gap-3 h-100">
                <div class="pos-card p-3 position-relative">
                    <div class="row g-2">
                        <div class="col-12 col-sm-6 position-relative">
                            <label class="form-label text-muted small fw-bold mb-1">Product Lookup / Barcode</label>
                            <input type="text" id="product-search" class="form-control form-control-pos" placeholder="Scan barcode or type description..." autocomplete="off" autofocus>
                            <div id="search-results" class="search-results rounded-2 d-none"></div>
                        </div>
                        <div class="col-4 col-sm-2">
                            <label class="form-label text-muted small fw-bold mb-1">Qty</label>
                            <input type="number" id="input-qty" class="form-control form-control-pos text-center" value="1" min="1">
                        </div>
                        <div class="col-8 col-sm-4">
                            <label class="form-label text-muted small fw-bold mb-1">Action</label>
                            <button id="btn-add-item" class="btn btn-info w-100 fw-bold"><i class="bi bi-plus-lg me-1"></i> Add Line</button>
                        </div>
                    </div>
                </div>

                <!-- Shopping Cart Panel -->
                <div class="pos-card flex-grow-1 d-flex flex-column overflow-hidden">
                    <div class="cart-table-wrapper flex-grow-1">
                        <table class="table table-dark-custom align-middle mb-0">
                            <thead>
                                <tr>
                                    <th class="ps-3">Description</th>
                                    <th class="text-center">Qty</th>
                                    <th class="text-end">Unit Price</th>
                                    <th class="text-end pe-3">Line Total</th>
                                    <th class="text-center">Remove</th>
                                </tr>
                            </thead>
                            <tbody id="cart-table-body">
                                <tr><td colspan="5" class="text-center py-4 text-muted">No items scanned into cart.</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="p-3 bg-dark border-top border-secondary d-flex justify-content-between align-items-center">
                        <span class="text-muted fw-semibold">Items Count: <strong id="total-items-count" class="text-white fs-5 ms-1">0</strong></span>
                        <button id="btn-clear-cart" class="btn btn-sm btn-outline-danger">Clear Cart</button>
                    </div>
                </div>
            </div>

            <!-- Right Side Controls & Numpad -->
            <div class="col-12 col-lg-5 d-flex flex-column gap-3">
                <div class="pos-card p-3">
                    <span class="text-muted small fw-bold text-uppercase d-block mb-2">Quick Items</span>
                    <div class="row g-2">
                        <div class="col-6">
                            <button class="btn btn-quick-item w-100" onclick="addQuickItem('AIRTIME', 'Airtime Voucher $10', 10.00)">
                                <i class="bi bi-phone me-1"></i> AIRTIME ($10)
                            </button>
                        </div>
                        <div class="col-6">
                            <button class="btn btn-quick-item w-100" onclick="addQuickItem('BAG01', 'Carry Bag', 2.00)">
                                <i class="bi bi-bag me-1"></i> CARRY BAG ($2)
                            </button>
                        </div>
                    </div>
                </div>

                <div class="row g-2 flex-grow-1">
                    <div class="col-8">
                        <div class="pos-card p-3 h-100 d-flex flex-column justify-content-between">
                            <div class="row g-2 mb-1">
                                <div class="col-4"><button class="btn numpad-btn w-100" onclick="pressNum('7')">7</button></div>
                                <div class="col-4"><button class="btn numpad-btn w-100" onclick="pressNum('8')">8</button></div>
                                <div class="col-4"><button class="btn numpad-btn w-100" onclick="pressNum('9')">9</button></div>
                            </div>
                            <div class="row g-2 mb-1">
                                <div class="col-4"><button class="btn numpad-btn w-100" onclick="pressNum('4')">4</button></div>
                                <div class="col-4"><button class="btn numpad-btn w-100" onclick="pressNum('5')">5</button></div>
                                <div class="col-4"><button class="btn numpad-btn w-100" onclick="pressNum('6')">6</button></div>
                            </div>
                            <div class="row g-2 mb-1">
                                <div class="col-4"><button class="btn numpad-btn w-100" onclick="pressNum('1')">1</button></div>
                                <div class="col-4"><button class="btn numpad-btn w-100" onclick="pressNum('2')">2</button></div>
                                <div class="col-4"><button class="btn numpad-btn w-100" onclick="pressNum('3')">3</button></div>
                            </div>
                            <div class="row g-2">
                                <div class="col-4"><button class="btn numpad-btn w-100" onclick="pressNum('.')">.</button></div>
                                <div class="col-4"><button class="btn numpad-btn w-100" onclick="pressNum('0')">0</button></div>
                                <div class="col-4"><button class="btn numpad-btn w-100 text-warning" onclick="clearNum()"><i class="bi bi-backspace"></i></button></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-4 d-flex flex-column gap-2">
                        <button id="btn-pay" class="btn btn-action-pay fw-bold p-2 text-center rounded-3 flex-grow-1 d-flex flex-column justify-content-center align-items-center">
                            <i class="bi bi-credit-card fs-1 mb-2"></i>
                            PAYMENT
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="status-bar px-3 py-2 d-flex justify-content-between align-items-center">
        <div><i class="bi bi-database me-1"></i> Connected to Aberrant Database</div>
        <div><?= date('Y-m-d H:i') ?></div>
    </div>

    <script>
        let cart = [];
        let selectedProduct = null;

        const searchInput = document.getElementById('product-search');
        const searchResults = document.getElementById('search-results');

        // Autocomplete Search Execution
        searchInput.addEventListener('input', function() {
            let term = this.value.trim();
            if (term.length < 2) {
                searchResults.classList.add('d-none');
                return;
            }
            fetch(`pos.php?action=search_item&term=${encodeURIComponent(term)}`)
                .then(res => res.json())
                .then(data => {
                    searchResults.innerHTML = '';
                    if (data.length > 0) {
                        data.forEach(item => {
                            let div = document.createElement('div');
                            div.innerHTML = `<strong>${item.Stockcode}</strong> - ${item.Description} ($${parseFloat(item.InclSellingPrice).toFixed(2)})`;
                            div.onclick = () => {
                                selectedProduct = item;
                                searchInput.value = item.Description;
                                searchResults.classList.add('d-none');
                            };
                            searchResults.appendChild(div);
                        });
                        searchResults.classList.remove('d-none');
                    } else {
                        searchResults.classList.add('d-none');
                    }
                });
        });

        document.getElementById('btn-add-item').addEventListener('click', () => {
            let qty = parseFloat(document.getElementById('input-qty').value) || 1;
            if (selectedProduct) {
                addCartItem(selectedProduct.Stockcode, selectedProduct.Description, parseFloat(selectedProduct.InclSellingPrice), qty);
                selectedProduct = null;
                searchInput.value = '';
            }
        });

        function addQuickItem(code, desc, price) {
            addCartItem(code, desc, price, 1);
        }

        function addCartItem(code, desc, price, qty) {
            cart.push({ code, desc, price, qty, total: price * qty });
            document.getElementById('last-item-desc').innerText = desc;
            renderCart();
        }

        function renderCart() {
            const tbody = document.getElementById('cart-table-body');
            tbody.innerHTML = '';
            let grandTotal = 0;

            if (cart.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-muted">No items scanned into cart.</td></tr>';
                document.getElementById('grand-total').innerText = '$0.00';
                document.getElementById('total-items-count').innerText = '0';
                return;
            }

            cart.forEach((item, index) => {
                grandTotal += item.total;
                tbody.innerHTML += `
                    <tr>
                        <td class="ps-3 fw-semibold">${item.desc}</td>
                        <td class="text-center">${item.qty}</td>
                        <td class="text-end">$${item.price.toFixed(2)}</td>
                        <td class="text-end pe-3 font-monospace fw-bold text-info">$${item.total.toFixed(2)}</td>
                        <td class="text-center">
                            <button class="btn btn-sm btn-outline-danger py-0 px-2" onclick="removeCartItem(${index})">&times;</button>
                        </td>
                    </tr>
                `;
            });

            document.getElementById('grand-total').innerText = `$${grandTotal.toFixed(2)}`;
            document.getElementById('total-items-count').innerText = cart.length;
        }

        function removeCartItem(index) {
            cart.splice(index, 1);
            renderCart();
        }

        document.getElementById('btn-clear-cart').addEventListener('click', () => {
            cart = [];
            renderCart();
        });

        function pressNum(num) {
            let qtyInput = document.getElementById('input-qty');
            qtyInput.value = qtyInput.value === '1' ? num : qtyInput.value + num;
        }

        function clearNum() {
            document.getElementById('input-qty').value = '1';
        }

        // Post Sale via AJAX
        document.getElementById('btn-pay').addEventListener('click', () => {
            if (cart.length === 0) {
                alert('Cart is empty!');
                return;
            }

            let btnPay = document.getElementById('btn-pay');
            btnPay.disabled = true;

            let formData = new FormData();
            formData.append('action', 'complete_sale');
            formData.append('cart', JSON.stringify(cart));
            formData.append('payment_method', 'CASH');

            fetch('pos.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(res => {
                    btnPay.disabled = false;
                    if (res.success) {
                        window.open(`print_receipt.php?sale_nr=${res.sale_nr}`, '_blank');
                        cart = [];
                        renderCart();
                        document.getElementById('last-item-desc').innerText = 'READY TO SCAN';
                    } else {
                        alert('Error: ' + res.message);
                    }
                })
                .catch(() => {
                    btnPay.disabled = false;
                    alert('Network error while processing sale.');
                });
        });
    </script>
</body>
</html>