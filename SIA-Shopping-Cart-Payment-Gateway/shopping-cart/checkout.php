
<?php
require __DIR__ . '/config.php';

function finish(string $title, string $message): void
{
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
    echo '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>';
    echo '</head><body style="font:16px Arial;max-width:800px;margin:40px auto">';
    echo '<h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>';
    echo '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
    echo '<p><a href="index.php">Store</a> | ';
    echo '<a href="cart.php">Cart</a> | ';
    echo '<a href="orders.php">Orders</a></p>';
    echo '</body></html>';
    exit;
}

// Checkout must use POST.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: cart.php');
    exit;
}

// Read and validate customer information.
$customer = trim((string) ($_POST['customer_name'] ?? ''));
$method = (string) ($_POST['payment_method'] ?? 'CARD');

if ($customer === '') {
    finish('Missing information', 'Enter a customer name.');
}

if (!in_array($method, ['CARD', 'EWALLET', 'BANK'], true)) {
    $method = 'CARD';
}

// Read the shopping cart.
$cart = $_SESSION['cart'] ?? [];

if (!is_array($cart) || empty($cart)) {
    finish('Empty cart', 'Add an item before checkout.');
}

// Validate cart IDs and quantities.
$cleanCart = [];

foreach ($cart as $productId => $quantity) {
    $productId = filter_var($productId, FILTER_VALIDATE_INT);
    $quantity = filter_var($quantity, FILTER_VALIDATE_INT);

    if (
        $productId === false || $productId < 1 ||
        $quantity === false || $quantity < 1
    ) {
        finish('Checkout error', 'The cart contains an invalid item or quantity.');
    }

    $cleanCart[$productId] = $quantity;
}

$pdo = cart_db();

try {
    // Load current product details and prices from the database.
    $ids = array_keys($cleanCart);
    $marks = implode(',', array_fill(0, count($ids), '?'));

    $stmt = $pdo->prepare(
        "SELECT id, name, price, stock
         FROM products
         WHERE id IN ($marks)"
    );

    $stmt->execute($ids);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Checkout product query failed: ' . $e->getMessage());
    finish('Checkout error', 'Could not load product information. Please try again.');
}

$productMap = [];

foreach ($products as $product) {
    $productMap[(int) $product['id']] = $product;
}

$total = 0.0;
$validItems = [];

foreach ($cleanCart as $productId => $qty) {
    if (!isset($productMap[$productId])) {
        finish('Checkout error', 'A product in your cart no longer exists.');
    }

    $product = $productMap[$productId];
    $stock = (int) $product['stock'];

    if ($stock < 1) {
        finish('Out of stock', $product['name'] . ' is currently out of stock.');
    }

    if ($qty > $stock) {
        finish(
            'Insufficient stock',
            'Only ' . $stock . ' unit(s) of ' . $product['name'] .
            ' are currently available. Please update your cart.'
        );
    }

    $price = (float) $product['price'];

    if (!is_finite($price) || $price < 0) {
        finish('Checkout error', 'A product has an invalid price.');
    }

    $total += $qty * $price;

    $validItems[] = [
        'product' => $product,
        'qty' => $qty
    ];
}

$total = round($total, 2);

if (empty($validItems) || !is_finite($total) || $total <= 0) {
    finish('Checkout error', 'No valid items were found in your cart.');
}

// Save the order and its items before contacting the payment gateway.
try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        "INSERT INTO orders (customer_name, total_amount, status)
         VALUES (?, ?, 'PENDING_PAYMENT')"
    );

    $stmt->execute([
        $customer,
        number_format($total, 2, '.', '')
    ]);

    $orderId = (int) $pdo->lastInsertId();

    $itemStmt = $pdo->prepare(
        'INSERT INTO order_items
         (order_id, product_id, product_name, quantity, unit_price)
         VALUES (?, ?, ?, ?, ?)'
    );

    foreach ($validItems as $item) {
        $product = $item['product'];

        $itemStmt->execute([
            $orderId,
            (int) $product['id'],
            $product['name'],
            $item['qty'],
            $product['price']
        ]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Checkout order save failed: ' . $e->getMessage());
    finish('Order error', 'Could not save the order. Please try again.');
}

// --------------------------------------------------
// PAYMENT GATEWAY INTEGRATION
// --------------------------------------------------

$paymentUrl = 'http://localhost/payment-gateway/api/payments.php';

// This key is stable for retries of this same order.
// The gateway must support idempotency for it to prevent duplicates.
$idempotencyKey = 'cart-order-' . $orderId;

// LAB TEST ONLY: change to FAILED or PENDING to test those outcomes.
// Do not trust a client-controlled outcome in a production system.
$outcome = 'SUCCESS';

$payload = [
    'order_id' => $orderId,
    'amount' => $total,
    'customer_name' => $customer,
    'payment_method' => $method,
    'idempotency_key' => $idempotencyKey,
    'outcome' => $outcome
];

$jsonPayload = json_encode($payload);

if ($jsonPayload === false) {
    finish(
        'Payment error',
        'Could not prepare the payment request. Order #' . $orderId .
        ' remains pending.'
    );
}

$ch = curl_init($paymentUrl);

if ($ch === false) {
    finish(
        'Payment status uncertain',
        'Could not initialize the payment connection. Order #' . $orderId .
        ' remains pending. Check the gateway before retrying.'
    );
}

curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $jsonPayload,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Accept: application/json'
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 15
]);

$response = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

curl_close($ch);

// A timeout or connection error does not prove that payment failed.
if ($response === false) {
    error_log('Payment gateway cURL error: ' . $curlError);

    finish(
        'Payment status uncertain',
        'The gateway could not be reached or timed out. Order #' .
        $orderId .
        ' remains pending. Verify the gateway transaction before retrying.'
    );
}

$result = json_decode($response, true);

// Validate the response structure and correlate it with this order.
$validResponse =
    $httpCode === 200 &&
    is_array($result) &&
    isset(
        $result['success'],
        $result['transaction_id'],
        $result['order_id'],
        $result['amount'],
        $result['status']
    ) &&
    is_bool($result['success']) &&
    is_string($result['transaction_id']) &&
    trim($result['transaction_id']) !== '' &&
    is_numeric($result['order_id']) &&
    (int) $result['order_id'] === $orderId &&
    is_numeric($result['amount']) &&
    abs((float) $result['amount'] - $total) < 0.001 &&
    in_array(
        $result['status'],
        ['SUCCESS', 'FAILED', 'PENDING'],
        true
    ) &&
    (
        $result['status'] !== 'SUCCESS' ||
        $result['success'] === true
    );

if (!$validResponse) {
    error_log(
        'Invalid payment gateway response. HTTP status: ' . $httpCode .
        '; response: ' . substr($response, 0, 1000)
    );

    finish(
        'Payment status uncertain',
        'The gateway response could not be verified. Order #' . $orderId .
        ' remains pending. Check the gateway transaction records before retrying.'
    );
}

// Map gateway outcomes to the shopping cart order statuses.
switch ($result['status']) {
    case 'SUCCESS':
        $orderStatus = 'PAID';
        break;

    case 'FAILED':
        $orderStatus = 'PAYMENT_FAILED';
        break;

    case 'PENDING':
        $orderStatus = 'PENDING_PAYMENT';
        break;

    default:
        finish('Payment error', 'The gateway returned an unsupported status.');
}

$transactionId = trim($result['transaction_id']);

// Save the transaction ID and status.
// If this update fails, do not claim the order was successfully recorded.
try {
    $stmt = $pdo->prepare(
        'UPDATE orders
         SET status = ?, gateway_transaction_id = ?
         WHERE id = ?'
    );

    $stmt->execute([
        $orderStatus,
        $transactionId,
        $orderId
    ]);

    if ($stmt->rowCount() === 0) {
        // Some database configurations report zero changed rows when values
        // are unchanged, so verify that the order exists and matches.
        $check = $pdo->prepare(
            'SELECT status, gateway_transaction_id
             FROM orders
             WHERE id = ?'
        );

        $check->execute([$orderId]);
        $savedOrder = $check->fetch(PDO::FETCH_ASSOC);

        if (
            !$savedOrder ||
            $savedOrder['status'] !== $orderStatus ||
            $savedOrder['gateway_transaction_id'] !== $transactionId
        ) {
            throw new RuntimeException('Order payment update was not confirmed.');
        }
    }
} catch (Throwable $e) {
    error_log(
        'Payment result could not be saved for order #' . $orderId .
        ': ' . $e->getMessage()
    );

    finish(
        'Payment record needs verification',
        'The gateway returned transaction ' . $transactionId .
        ', but the order update could not be confirmed. Do not retry payment yet. ' .
        'Verify the gateway transaction and database for order #' . $orderId . '.'
    );
}

// Clear the cart only after a verified successful payment and database update.
if ($result['status'] === 'SUCCESS') {
    unset($_SESSION['cart']);

    finish(
        'Payment successful!',
        'Order #' . $orderId .
        ' was paid successfully. Transaction ID: ' . $transactionId
    );
}

if ($result['status'] === 'FAILED') {
    finish(
        'Payment failed',
        'Order #' . $orderId .
        ' was not paid. Transaction ID: ' . $transactionId .
        '. You can review your order before trying again.'
    );
}

finish(
    'Payment pending',
    'Order #' . $orderId .
    ' is awaiting payment confirmation. Transaction ID: ' .
    $transactionId . '. Check the order status before retrying.'
);