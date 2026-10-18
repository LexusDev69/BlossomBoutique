<?php
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/payrex.php';
require_once __DIR__ . '/includes/order_payment.php';
require_login();

function checkout_flash_error(): string
{
    $message = trim((string) ($_SESSION['checkout_flash_error'] ?? ''));
    unset($_SESSION['checkout_flash_error']);
    return $message;
}

function checkout_set_flash_error(string $message): void
{
    $_SESSION['checkout_flash_error'] = $message;
}

function checkout_get_retry_order(int $orderId, int $userId): ?array
{
    if ($orderId < 1 || $userId < 1) {
        return null;
    }

    $stmt = db()->prepare('SELECT id, total, status, payment_method FROM orders WHERE id = ? AND user_id = ? LIMIT 1');
    if (!($stmt instanceof mysqli_stmt)) {
        return null;
    }

    $stmt->bind_param('ii', $orderId, $userId);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$order) {
        return null;
    }

    if ((string) ($order['status'] ?? '') !== 'pending') {
        return null;
    }

    if ((string) ($order['payment_method'] ?? '') !== 'PayRex Payment') {
        return null;
    }

    return $order;
}

function checkout_start_payrex_for_order(int $orderId, float $total, array $user): array
{
    $customerName = trim((string) ($user['full_name'] ?? 'Customer'));
    $customerEmail = trim((string) ($user['email'] ?? ''));
    $session = payrex_create_checkout_session($orderId, $total, $customerName, $customerEmail);
    attach_payrex_session_to_order(
        $orderId,
        (string) ($session['id'] ?? ''),
        (string) ($session['payment_intent_id'] ?? '')
    );

    return $session;
}

function checkout_normalize_text(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9\s]/', ' ', $value);
    if (!is_string($value)) {
        return '';
    }
    $value = preg_replace('/\s+/', ' ', $value);
    return is_string($value) ? trim($value) : '';
}

function checkout_pick_address_part(array $address, array $keys): string
{
    foreach ($keys as $key) {
        $value = trim((string) ($address[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function checkout_reverse_geocode_pin(float $latitude, float $longitude): ?array
{
    $url = 'https://nominatim.openstreetmap.org/reverse?format=jsonv2&addressdetails=1&lat='
        . rawurlencode(number_format($latitude, 7, '.', ''))
        . '&lon=' . rawurlencode(number_format($longitude, 7, '.', ''));

    $ch = curl_init($url);
    if ($ch === false) {
        return null;
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'User-Agent: BlossomBoutiqueCheckout/1.0',
        ],
    ]);
    $body = curl_exec($ch);
    if (!is_string($body)) {
        curl_close($ch);
        return null;
    }
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($status >= 400) {
        return null;
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return null;
    }

    $address = is_array($decoded['address'] ?? null) ? $decoded['address'] : [];
    $street = trim(checkout_pick_address_part($address, ['road', 'pedestrian', 'footway', 'street']));
    $houseNumber = trim(checkout_pick_address_part($address, ['house_number']));
    if ($street !== '' && $houseNumber !== '') {
        $street = trim($houseNumber . ' ' . $street);
    }

    return [
        'full_address' => trim((string) ($decoded['display_name'] ?? '')),
        'street' => $street,
        'barangay' => trim(checkout_pick_address_part($address, ['suburb', 'village', 'neighbourhood', 'quarter'])),
        'city' => trim(checkout_pick_address_part($address, ['city', 'town', 'municipality', 'city_district', 'county'])),
        'province' => trim(checkout_pick_address_part($address, ['state', 'region', 'province'])),
        'postcode' => trim(checkout_pick_address_part($address, ['postcode'])),
    ];
}

function checkout_pin_matches_shipping(string $shippingAddress, array $pin): bool
{
    $shipping = checkout_normalize_text($shippingAddress);
    if ($shipping === '') {
        return false;
    }

    $city = checkout_normalize_text((string) ($pin['city'] ?? ''));
    $province = checkout_normalize_text((string) ($pin['province'] ?? ''));
    $street = checkout_normalize_text((string) ($pin['street'] ?? ''));

    if ($city !== '' && str_contains($shipping, $city)) {
        return true;
    }
    if ($province !== '' && str_contains($shipping, $province)) {
        return true;
    }
    if ($street !== '') {
        $streetWords = array_filter(explode(' ', $street), static fn(string $w): bool => strlen($w) >= 4);
        foreach ($streetWords as $word) {
            if (str_contains($shipping, $word)) {
                return true;
            }
        }
    }

    return false;
}

$extraHead = <<<'HTML'
<link
    rel="stylesheet"
    href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
    integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
    crossorigin=""
>
<script
    src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
    integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
    crossorigin=""
></script>
HTML;

$pageTitle = 'Checkout';
$user = current_user();
if (($user['role'] ?? '') === 'admin') {
    $_SESSION['admin_flash'] = ['message' => 'Admin accounts cannot place customer orders.', 'type' => 'error'];
    header('Location: ' . BASE_URL . '/admin/index.php');
    exit;
}
$retryOrderId = (int) ($_SESSION['checkout_retry_order_id'] ?? 0);
$retryOrder = checkout_get_retry_order($retryOrderId, (int) ($_SESSION['user_id'] ?? 0));
if ($retryOrder === null) {
    unset($_SESSION['checkout_retry_order_id']);
    $retryOrderId = 0;
}
$retryOrderId = $retryOrder !== null ? (int) $retryOrder['id'] : 0;
$retryMessage = $retryOrderId > 0 ? 'Pending PayRex order #' . $retryOrderId . ' is waiting for checkout to start. Retry from here without creating a duplicate order.' : '';

$totals = cart_totals();
if (!$totals['items'] && $retryOrderId < 1) {
    header('Location: ' . BASE_URL . '/cart.php');
    exit;
}

$shippingFee = 150.00;
$error = checkout_flash_error();
$address = '';
$landmark = '';
$shippingMapUrl = '';
$shippingLatitude = '';
$shippingLongitude = '';
$shippingMethod = 'standard';
$paymentMethod = 'payrex';
$shippingPinFullAddress = '';
$shippingPinStreet = '';
$shippingPinBarangay = '';
$shippingPinCity = '';
$shippingPinProvince = '';
$shippingPinPostcode = '';
$locationReady = ensure_order_location_schema();
$coordinatesValid = false;
$payrexConfigError = '';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['retry_order_id'])) {
    $requestedRetryOrderId = (int) ($_GET['retry_order_id'] ?? 0);
    $retryOrder = checkout_get_retry_order($requestedRetryOrderId, (int) ($_SESSION['user_id'] ?? 0));

    if ($retryOrder === null) {
        checkout_set_flash_error('That pending PayRex order is no longer available for retry.');
        unset($_SESSION['checkout_retry_order_id']);
        header('Location: ' . BASE_URL . '/checkout.php');
        exit;
    }

    try {
        $session = checkout_start_payrex_for_order((int) $retryOrder['id'], (float) $retryOrder['total'], $user ?? []);
        $sessionUrl = trim((string) ($session['checkout_url'] ?? ''));
        unset($_SESSION['checkout_retry_order_id']);
        if ($sessionUrl !== '') {
            header('Location: ' . $sessionUrl);
            exit;
        }

        throw new RuntimeException('PayRex did not return a payment link.');
    } catch (Throwable $e) {
        payrex_log_error('PayRex checkout start failed (retry)', [
            'order_id' => (int) $retryOrder['id'],
            'error' => $e->getMessage(),
        ]);
        $_SESSION['checkout_retry_order_id'] = (int) $retryOrder['id'];
        checkout_set_flash_error('Order saved as pending, but we could not start PayRex checkout for Order #' . (int) $retryOrder['id'] . '. ' . $e->getMessage());
        header('Location: ' . BASE_URL . '/checkout.php');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $address = trim((string) ($_POST['shipping_address'] ?? ''));
    $landmark = trim((string) ($_POST['shipping_landmark'] ?? ''));
    $shippingMapUrl = trim((string) ($_POST['shipping_map_url'] ?? ''));
    $shippingLatitude = trim((string) ($_POST['shipping_latitude'] ?? ''));
    $shippingLongitude = trim((string) ($_POST['shipping_longitude'] ?? ''));
    $shippingPinFullAddress = trim((string) ($_POST['shipping_pin_full_address'] ?? ''));
    $shippingPinStreet = trim((string) ($_POST['shipping_pin_street'] ?? ''));
    $shippingPinBarangay = trim((string) ($_POST['shipping_pin_barangay'] ?? ''));
    $shippingPinCity = trim((string) ($_POST['shipping_pin_city'] ?? ''));
    $shippingPinProvince = trim((string) ($_POST['shipping_pin_province'] ?? ''));
    $shippingPinPostcode = trim((string) ($_POST['shipping_pin_postcode'] ?? ''));
    $shippingMethod = trim((string) ($_POST['shipping_method'] ?? 'standard'));
    $rawPaymentMethod = trim((string) ($_POST['payment_method'] ?? 'payrex'));
    $paymentMethod = $rawPaymentMethod === 'cod' ? 'cod' : 'payrex';
    payrex_log_error('Checkout POST payment capture', [
        'raw_payment_method' => $rawPaymentMethod,
        'normalized_payment_method' => $paymentMethod,
    ]);
    $shippingMethod = $shippingMethod === 'express' ? 'express' : 'standard';
    $shippingFee = $shippingMethod === 'express' ? 250.00 : 150.00;
    $latitudeValue = filter_var($shippingLatitude, FILTER_VALIDATE_FLOAT);
    $longitudeValue = filter_var($shippingLongitude, FILTER_VALIDATE_FLOAT);
    $coordinatesValid = $latitudeValue !== false && $longitudeValue !== false;
    if ($coordinatesValid) {
        $shippingLatitude = number_format((float) $latitudeValue, 7, '.', '');
        $shippingLongitude = number_format((float) $longitudeValue, 7, '.', '');
        $shippingMapUrl = 'https://www.openstreetmap.org/?mlat=' . rawurlencode($shippingLatitude) . '&mlon=' . rawurlencode($shippingLongitude) . '#map=18/' . rawurlencode($shippingLatitude) . '/' . rawurlencode($shippingLongitude);
    } else {
        $shippingLatitude = '';
        $shippingLongitude = '';
        $shippingMapUrl = '';
        $shippingPinFullAddress = '';
        $shippingPinStreet = '';
        $shippingPinBarangay = '';
        $shippingPinCity = '';
        $shippingPinProvince = '';
        $shippingPinPostcode = '';
    }
    $latitudeForDb = $coordinatesValid ? (float) $latitudeValue : 0.0;
    $longitudeForDb = $coordinatesValid ? (float) $longitudeValue : 0.0;

    $paymentLabel = match ($paymentMethod) {
        'cod' => 'Cash on Delivery',
        'payrex' => 'PayRex Payment',
        default => '',
    };

    if (!$locationReady) {
        $error = 'Location fields are not ready yet. Please run the order location migration and try again.';
        if ($paymentMethod === 'payrex') {
            payrex_log_error('Checkout validation blocked before PayRex (location schema)', [
                'error' => $error,
            ]);
        }
    } elseif (!is_valid_ph_mobile((string) ($user['phone'] ?? ''))) {
        $error = 'Please save a valid Philippine mobile number in your account before checkout.';
    } elseif ($paymentLabel === '') {
        $error = 'Please complete shipping and payment details.';
        if ($paymentMethod === 'payrex') {
            payrex_log_error('Checkout validation blocked before PayRex (missing details)', [
                'error' => $error,
                'payment_label' => $paymentLabel,
            ]);
        }
    } elseif (!$coordinatesValid) {
        $error = 'Please pin your exact map location before placing the order.';
    } else {
        $pinLookup = checkout_reverse_geocode_pin((float) $latitudeValue, (float) $longitudeValue);
        if ($pinLookup === null || trim((string) ($pinLookup['full_address'] ?? '')) === '') {
            $error = 'We could not verify your pinned location yet. Please pin again and retry.';
        } else {
            $shippingPinFullAddress = trim((string) ($pinLookup['full_address'] ?? ''));
            $shippingPinStreet = trim((string) ($pinLookup['street'] ?? ''));
            $shippingPinBarangay = trim((string) ($pinLookup['barangay'] ?? ''));
            $shippingPinCity = trim((string) ($pinLookup['city'] ?? ''));
            $shippingPinProvince = trim((string) ($pinLookup['province'] ?? ''));
            $shippingPinPostcode = trim((string) ($pinLookup['postcode'] ?? ''));

            if ($address === '') {
                $address = $shippingPinFullAddress;
            }
            if (!checkout_pin_matches_shipping($address, $pinLookup)) {
                $error = 'Your typed shipping address does not match the pinned map location. Please use the exact pinned location details.';
            }
        }
    }

    if ($error === '' && $address === '' && $shippingPinFullAddress !== '') {
        $address = $shippingPinFullAddress;
    }

    if ($error === '' && $paymentMethod === 'payrex' && ($payrexConfigError = payrex_configuration_error()) !== '') {
        payrex_log_error('PayRex checkout blocked by configuration', [
            'error' => $payrexConfigError,
        ]);
        $error = 'PayRex is not configured yet. ' . $payrexConfigError;
    }

    if ($error === '') {
        $subtotal = $totals['subtotal'];
        $total = $subtotal + $shippingFee;
        $orderId = 0;
        $sessionUrl = '';

        db()->begin_transaction();
        try {
            $stmt = db()->prepare('INSERT INTO orders (user_id, shipping_address, shipping_landmark, shipping_map_url, shipping_latitude, shipping_longitude, shipping_pin_full_address, shipping_pin_street, shipping_pin_barangay, shipping_pin_city, shipping_pin_province, shipping_pin_postcode, shipping_method, payment_method, subtotal, shipping_fee, total, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $status = 'pending';
            $stmt->bind_param(
                'isssddssssssssddds',
                $_SESSION['user_id'],
                $address,
                $landmark,
                $shippingMapUrl,
                $latitudeForDb,
                $longitudeForDb,
                $shippingPinFullAddress,
                $shippingPinStreet,
                $shippingPinBarangay,
                $shippingPinCity,
                $shippingPinProvince,
                $shippingPinPostcode,
                $shippingMethod,
                $paymentLabel,
                $subtotal,
                $shippingFee,
                $total,
                $status
            );
            $stmt->execute();
            $orderId = db()->insert_id;
            $stmt->close();

            $itemStmt = db()->prepare('INSERT INTO order_items (order_id, product_id, quantity, unit_price) VALUES (?, ?, ?, ?)');
            $hasCustomItems = false;
            foreach ($totals['items'] as $item) {
                if ((string) ($item['type'] ?? 'product') === 'custom') {
                    $hasCustomItems = true;
                    break;
                }
            }
            $customStmt = null;
            if ($hasCustomItems) {
                $customStmt = db()->prepare('INSERT INTO order_custom_bouquets (order_id, flower_option_id, wrap_option_id, flower_name, wrap_name, addons_json, message_note, quantity, unit_price, line_total) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                if (!($customStmt instanceof mysqli_stmt)) {
                    throw new RuntimeException('Custom bouquet table missing.');
                }
            }

            foreach ($totals['items'] as $item) {
                $itemType = (string) ($item['type'] ?? 'product');
                if ($itemType === 'custom') {
                    if (!($customStmt instanceof mysqli_stmt)) {
                        throw new RuntimeException('Custom bouquet table missing.');
                    }
                    $flowerOptionId = (int) ($item['flower_option_id'] ?? 0);
                    $wrapOptionId = (int) ($item['wrap_option_id'] ?? 0);
                    $flowerName = (string) ($item['flower_name'] ?? '');
                    $wrapName = (string) ($item['wrap_name'] ?? '');
                    $addons = is_array($item['addons'] ?? null) ? $item['addons'] : [];
                    $addonsJson = json_encode($addons, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    if (!is_string($addonsJson)) {
                        $addonsJson = '[]';
                    }
                    $messageNote = (string) ($item['message_note'] ?? '');
                    $quantity = (int) $item['quantity'];
                    $unitPrice = (float) $item['price'];
                    $lineTotal = (float) $item['line_total'];
                    $customStmt->bind_param(
                        'iiissssidd',
                        $orderId,
                        $flowerOptionId,
                        $wrapOptionId,
                        $flowerName,
                        $wrapName,
                        $addonsJson,
                        $messageNote,
                        $quantity,
                        $unitPrice,
                        $lineTotal
                    );
                    $customStmt->execute();
                    continue;
                }

                $productId = (int) ($item['id'] ?? 0);
                $quantity = (int) ($item['quantity'] ?? 0);
                $unitPrice = (float) ($item['price'] ?? 0);
                if ($productId < 1 || $quantity < 1 || $unitPrice <= 0) {
                    continue;
                }

                $itemStmt->bind_param('iiid', $orderId, $productId, $quantity, $unitPrice);
                $itemStmt->execute();
            }
            $itemStmt->close();
            if ($customStmt instanceof mysqli_stmt) {
                $customStmt->close();
            }
            db()->commit();
        } catch (Throwable $e) {
            db()->rollback();
            $error = 'Order failed. Please try again.';
        }

        if ($error === '' && $orderId > 0) {
            try {
                if ($paymentMethod === 'payrex') {
                    $session = checkout_start_payrex_for_order($orderId, $total, $user ?? []);
                    $sessionUrl = trim((string) ($session['checkout_url'] ?? ''));
                    unset($_SESSION['checkout_retry_order_id']);
                } elseif ($paymentMethod === 'cod') {
                    unset($_SESSION['checkout_retry_order_id']);
                    set_cart([]);
                    header('Location: ' . BASE_URL . '/order-confirmation.php?id=' . $orderId);
                    exit;
                } else {
                    throw new RuntimeException('Invalid payment method selected.');
                }
            } catch (Throwable $e) {
                if ($paymentMethod === 'payrex') {
                    payrex_log_error('PayRex checkout start failed (new order)', [
                        'order_id' => $orderId,
                        'error' => $e->getMessage(),
                    ]);
                    $_SESSION['checkout_retry_order_id'] = $orderId;
                    checkout_set_flash_error('Order saved as pending, but we could not start PayRex checkout for Order #' . $orderId . '. ' . $e->getMessage());
                    header('Location: ' . BASE_URL . '/checkout.php');
                    exit;
                } else {
                    $error = 'Order saved as pending, but payment confirmation failed for Order #' . $orderId . '.';
                }
            }
        }

        if ($sessionUrl !== '') {
            header('Location: ' . $sessionUrl);
            exit;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $paymentMethod === 'payrex' && $error !== '') {
    payrex_log_error('Checkout remained on page after PayRex submit', [
        'error' => $error,
        'location_ready' => $locationReady,
        'coordinates_valid' => $coordinatesValid,
        'payrex_config_error' => $payrexConfigError,
    ]);
}

require_once __DIR__ . '/includes/header.php';
?>
<section class="section container">
    <h1 class="section-title reveal">Secure Checkout</h1>
    <?php if ($retryOrderId > 0): ?>
        <div class="panel reveal" style="margin-bottom:20px;">
            <p><strong><?= e($retryMessage) ?></strong></p>
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <a class="btn btn-gold" href="<?= BASE_URL ?>/checkout.php?retry_order_id=<?= $retryOrderId ?>">Retry PayRex for Order #<?= $retryOrderId ?></a>
                <a class="btn" href="<?= BASE_URL ?>/order-confirmation.php?id=<?= $retryOrderId ?>">Open Pending Order</a>
            </div>
        </div>
    <?php endif; ?>
    <div class="grid" style="grid-template-columns: 1.1fr .9fr;">
        <form method="post" class="panel reveal">
            <?php if ($error): ?><p style="color:#b30000;"><?= e($error) ?></p><?php endif; ?>
            <label>Shipping Address</label>
            <textarea name="shipping_address" rows="4" required placeholder="House no., street, barangay, city, province"><?= e($address) ?></textarea>
            <label>Landmark / Extra Directions</label>
            <input name="shipping_landmark" value="<?= e($landmark) ?>" placeholder="Near church, beside gate 2, unit/floor, building color">
            <div class="checkout-map-panel">
                <div class="checkout-map-head">
                    <div>
                        <label style="margin-bottom:4px;">Pinned Location</label>
                        <p class="checkout-map-note">Required: pin your exact drop-off point so the street and full location can be verified.</p>
                    </div>
                    <button type="button" class="btn" id="useCurrentLocationBtn">Use My Current Location</button>
                </div>
                <div id="checkoutMap" class="checkout-map" aria-label="Shipping location map"></div>
                <div class="checkout-map-actions">
                    <button type="button" class="btn" id="clearLocationBtn">Clear Pin</button>
                    <a id="mapPreviewLink" class="btn" href="<?= e($shippingMapUrl !== '' ? $shippingMapUrl : '#') ?>" target="_blank" rel="noopener" <?= $shippingMapUrl !== '' ? '' : 'hidden' ?>>Open Pinned Location</a>
                </div>
                <p id="locationPinNote" class="checkout-map-note<?= $shippingLatitude !== '' && $shippingLongitude !== '' ? ' is-active' : '' ?>">
                    <?php if ($shippingLatitude !== '' && $shippingLongitude !== ''): ?>
                        Pin saved at <?= e($shippingLatitude) ?>, <?= e($shippingLongitude) ?>
                    <?php else: ?>
                        No map pin yet. Click the map or use your current location.
                    <?php endif; ?>
                </p>
                <p id="pinResolveNote" class="checkout-map-note<?= $shippingPinFullAddress !== '' ? ' is-active' : '' ?>">
                    <?php if ($shippingPinFullAddress !== ''): ?>
                        Verified pin address: <?= e($shippingPinFullAddress) ?>
                    <?php else: ?>
                        Pin verification pending.
                    <?php endif; ?>
                </p>
                <p id="pinMismatchNote" class="checkout-map-note checkout-map-warning" hidden>
                    Your typed address does not seem to match the pinned location details. Please correct it before checkout.
                </p>
                <input type="hidden" name="shipping_latitude" id="shippingLatitude" value="<?= e($shippingLatitude) ?>">
                <input type="hidden" name="shipping_longitude" id="shippingLongitude" value="<?= e($shippingLongitude) ?>">
                <input type="hidden" name="shipping_map_url" id="shippingMapUrl" value="<?= e($shippingMapUrl) ?>">
                <input type="hidden" name="shipping_pin_full_address" id="shippingPinFullAddress" value="<?= e($shippingPinFullAddress) ?>">
                <input type="hidden" name="shipping_pin_street" id="shippingPinStreet" value="<?= e($shippingPinStreet) ?>">
                <input type="hidden" name="shipping_pin_barangay" id="shippingPinBarangay" value="<?= e($shippingPinBarangay) ?>">
                <input type="hidden" name="shipping_pin_city" id="shippingPinCity" value="<?= e($shippingPinCity) ?>">
                <input type="hidden" name="shipping_pin_province" id="shippingPinProvince" value="<?= e($shippingPinProvince) ?>">
                <input type="hidden" name="shipping_pin_postcode" id="shippingPinPostcode" value="<?= e($shippingPinPostcode) ?>">
            </div>
            <label>Shipping Method</label>
            <select name="shipping_method" id="shippingMethod">
                <option value="standard" <?= $shippingMethod === 'standard' ? 'selected' : '' ?>>Standard (PHP 150)</option>
                <option value="express" <?= $shippingMethod === 'express' ? 'selected' : '' ?>>Express (PHP 250)</option>
            </select>
            <label>Payment Method</label>
            <div class="payment-option-grid" id="paymentMethod">
                <label class="payment-choice">
                    <input type="radio" name="payment_method" value="cod" <?= $paymentMethod === 'cod' ? 'checked' : '' ?>>
                    <span class="payment-choice-card">
                        <span class="payment-choice-head">
                            <span class="payment-choice-icon">COD</span>
                            <strong>Cash on Delivery</strong>
                        </span>
                        <span class="payment-choice-copy">Customer pays when the order arrives.</span>
                    </span>
                </label>
                <label class="payment-choice">
                    <input type="radio" name="payment_method" value="payrex" <?= $paymentMethod === 'payrex' ? 'checked' : '' ?>>
                    <span class="payment-choice-card">
                        <span class="payment-choice-head">
                            <span class="payment-choice-icon">PX</span>
                            <strong>PayRex Checkout</strong>
                        </span>
                        <span class="payment-choice-copy">Secure checkout handled by PayRex.</span>
                    </span>
                </label>
            </div>
            <button id="checkoutSubmitBtn" class="btn btn-gold"><?=
                $paymentMethod === 'payrex'
                    ? 'Proceed to PayRex'
                    : 'Place Order (Cash on Delivery)'
            ?></button>
        </form>
        <div class="panel reveal">
            <h3>Order Summary</h3>
            <?php foreach ($totals['items'] as $item): ?>
                <p>
                    <?= e($item['name']) ?> x <?= $item['quantity'] ?> - <?= money($item['line_total']) ?>
                    <?php if (($item['type'] ?? 'product') === 'custom'): ?>
                        <br><small>Custom: <?= e((string) ($item['flower_name'] ?? 'Flower')) ?> + <?= e((string) ($item['wrap_name'] ?? 'Wrap')) ?></small>
                        <?php if (trim((string) ($item['description'] ?? '')) !== ''): ?>
                            <br><small><?= e((string) $item['description']) ?></small>
                        <?php endif; ?>
                    <?php endif; ?>
                </p>
            <?php endforeach; ?>
            <hr>
            <p>Subtotal: <?= money($totals['subtotal']) ?></p>
            <p>Shipping: <span id="shippingFeeLabel"><?= money($shippingFee) ?></span></p>
            <p><strong>Total: <span id="checkoutTotalLabel"><?= money($totals['subtotal'] + $shippingFee) ?></span></strong></p>
        </div>
    </div>
</section>
<script>
(() => {
  const method = document.getElementById('shippingMethod');
  const paymentMethodInputs = document.querySelectorAll('input[name="payment_method"]');
  const submitBtn = document.getElementById('checkoutSubmitBtn');
  const checkoutForm = submitBtn ? submitBtn.closest('form') : null;
  const addressInput = checkoutForm ? checkoutForm.querySelector('textarea[name="shipping_address"]') : null;
  const feeLabel = document.getElementById('shippingFeeLabel');
  const totalLabel = document.getElementById('checkoutTotalLabel');
  const latInput = document.getElementById('shippingLatitude');
  const lngInput = document.getElementById('shippingLongitude');
  const mapUrlInput = document.getElementById('shippingMapUrl');
  const pinFullAddressInput = document.getElementById('shippingPinFullAddress');
  const pinStreetInput = document.getElementById('shippingPinStreet');
  const pinBarangayInput = document.getElementById('shippingPinBarangay');
  const pinCityInput = document.getElementById('shippingPinCity');
  const pinProvinceInput = document.getElementById('shippingPinProvince');
  const pinPostcodeInput = document.getElementById('shippingPinPostcode');
  const mapPreviewLink = document.getElementById('mapPreviewLink');
  const pinNote = document.getElementById('locationPinNote');
  const pinResolveNote = document.getElementById('pinResolveNote');
  const pinMismatchNote = document.getElementById('pinMismatchNote');
  const useCurrentLocationBtn = document.getElementById('useCurrentLocationBtn');
  const clearLocationBtn = document.getElementById('clearLocationBtn');
  const subtotal = <?= json_encode((float) $totals['subtotal']) ?>;
  const formatPHP = (value) => 'PHP ' + value.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  const normalizeText = (value) => (value || '')
    .toString()
    .toLowerCase()
    .replace(/[^a-z0-9\s]/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();
  const evaluateAddressMismatch = () => {
    if (!addressInput || !pinMismatchNote) return false;
    const shipping = normalizeText(addressInput.value);
    const city = normalizeText(pinCityInput?.value || '');
    const province = normalizeText(pinProvinceInput?.value || '');
    const street = normalizeText(pinStreetInput?.value || '');
    let matches = false;
    if (shipping !== '') {
      if (city && shipping.includes(city)) matches = true;
      if (!matches && province && shipping.includes(province)) matches = true;
      if (!matches && street) {
        const parts = street.split(' ').filter((w) => w.length >= 4);
        matches = parts.some((part) => shipping.includes(part));
      }
    }

    const mismatch = shipping !== '' && (city || province || street) && !matches;
    pinMismatchNote.hidden = !mismatch;
    return mismatch;
  };
  const setPinResolvedData = (data) => {
    if (pinFullAddressInput) pinFullAddressInput.value = (data.full_address || '').trim();
    if (pinStreetInput) pinStreetInput.value = (data.street || '').trim();
    if (pinBarangayInput) pinBarangayInput.value = (data.barangay || '').trim();
    if (pinCityInput) pinCityInput.value = (data.city || '').trim();
    if (pinProvinceInput) pinProvinceInput.value = (data.province || '').trim();
    if (pinPostcodeInput) pinPostcodeInput.value = (data.postcode || '').trim();

    if (pinResolveNote) {
      const label = pinFullAddressInput?.value?.trim() || '';
      pinResolveNote.textContent = label !== '' ? `Verified pin address: ${label}` : 'Pin verification pending.';
      pinResolveNote.classList.toggle('is-active', label !== '');
    }

    if (addressInput && addressInput.value.trim() === '' && (pinFullAddressInput?.value || '').trim() !== '') {
      addressInput.value = pinFullAddressInput.value.trim();
    }
    evaluateAddressMismatch();
  };
  let geocodeRequestId = 0;
  const resolvePinnedAddress = async (lat, lng) => {
    if (pinResolveNote) {
      pinResolveNote.textContent = 'Verifying pin address...';
      pinResolveNote.classList.add('is-active');
    }
    const requestId = ++geocodeRequestId;
    const endpoint = `https://nominatim.openstreetmap.org/reverse?format=jsonv2&addressdetails=1&lat=${encodeURIComponent(lat.toFixed(7))}&lon=${encodeURIComponent(lng.toFixed(7))}`;
    try {
      const response = await fetch(endpoint, { headers: { 'Accept': 'application/json' } });
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      const payload = await response.json();
      if (requestId !== geocodeRequestId) return;
      const addr = payload.address || {};
      const streetCore = (addr.road || addr.pedestrian || addr.footway || addr.street || '').toString().trim();
      const houseNo = (addr.house_number || '').toString().trim();
      const street = houseNo && streetCore ? `${houseNo} ${streetCore}` : streetCore;
      setPinResolvedData({
        full_address: (payload.display_name || '').toString(),
        street,
        barangay: (addr.suburb || addr.village || addr.neighbourhood || addr.quarter || '').toString(),
        city: (addr.city || addr.town || addr.municipality || addr.city_district || addr.county || '').toString(),
        province: (addr.state || addr.region || addr.province || '').toString(),
        postcode: (addr.postcode || '').toString(),
      });
    } catch (error) {
      if (requestId !== geocodeRequestId) return;
      setPinResolvedData({
        full_address: '',
        street: '',
        barangay: '',
        city: '',
        province: '',
        postcode: '',
      });
      if (pinResolveNote) {
        pinResolveNote.textContent = 'Pin verification failed. Please pin again or try after a few seconds.';
        pinResolveNote.classList.add('is-active');
      }
    }
  };
  if (!method || !feeLabel || !totalLabel) return;
  const update = () => {
    const fee = method.value === 'express' ? 250 : 150;
    feeLabel.textContent = formatPHP(fee);
    totalLabel.textContent = formatPHP(subtotal + fee);
  };
  const updatePaymentUI = () => {
    if (!submitBtn) return;
    const checkedInput = document.querySelector('input[name="payment_method"]:checked');
    const selected = checkedInput ? checkedInput.value : 'payrex';
    submitBtn.textContent = selected === 'payrex'
      ? 'Proceed to PayRex'
      : 'Place Order (Cash on Delivery)';
  };
  if (paymentMethodInputs.length > 0) {
    paymentMethodInputs.forEach((input) => {
      input.addEventListener('change', updatePaymentUI);
    });
  }
  const mapElement = document.getElementById('checkoutMap');
  let map = null;
  let marker = null;
  const defaultCenter = [14.5995, 120.9842];
  const currentLat = parseFloat(latInput?.value || '');
  const currentLng = parseFloat(lngInput?.value || '');
  const hasInitialLocation = Number.isFinite(currentLat) && Number.isFinite(currentLng);
  const buildMapUrl = (lat, lng) => `https://www.openstreetmap.org/?mlat=${lat}&mlon=${lng}#map=18/${lat}/${lng}`;
  const updatePinState = (lat, lng) => {
    if (!latInput || !lngInput || !mapUrlInput || !pinNote) return;
    latInput.value = lat.toFixed(7);
    lngInput.value = lng.toFixed(7);
    mapUrlInput.value = buildMapUrl(latInput.value, lngInput.value);
    pinNote.textContent = `Pin saved at ${latInput.value}, ${lngInput.value}`;
    pinNote.classList.add('is-active');
    if (mapPreviewLink) {
      mapPreviewLink.hidden = false;
      mapPreviewLink.href = mapUrlInput.value;
    }
    resolvePinnedAddress(lat, lng);
  };
  const clearPinState = () => {
    if (latInput) latInput.value = '';
    if (lngInput) lngInput.value = '';
    if (mapUrlInput) mapUrlInput.value = '';
    setPinResolvedData({
      full_address: '',
      street: '',
      barangay: '',
      city: '',
      province: '',
      postcode: '',
    });
    if (pinNote) {
      pinNote.textContent = 'No map pin yet. Click the map or use your current location.';
      pinNote.classList.remove('is-active');
    }
    if (pinResolveNote) {
      pinResolveNote.textContent = 'Pin verification pending.';
      pinResolveNote.classList.remove('is-active');
    }
    if (mapPreviewLink) {
      mapPreviewLink.hidden = true;
      mapPreviewLink.href = '#';
    }
    if (marker && map) {
      map.removeLayer(marker);
      marker = null;
    }
  };
  if (mapElement && window.L) {
    map = L.map(mapElement).setView(hasInitialLocation ? [currentLat, currentLng] : defaultCenter, hasInitialLocation ? 17 : 12);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);
    const setMarker = (latlng, shouldCenter = false) => {
      if (!marker) {
        marker = L.marker(latlng, { draggable: true }).addTo(map);
        marker.on('dragend', () => {
          const pos = marker.getLatLng();
          updatePinState(pos.lat, pos.lng);
        });
      } else {
        marker.setLatLng(latlng);
      }
      updatePinState(latlng.lat, latlng.lng);
      if (shouldCenter) {
        map.setView(latlng, 17);
      }
    };
    if (hasInitialLocation) {
      setMarker({ lat: currentLat, lng: currentLng }, true);
    }
    map.on('click', (event) => {
      setMarker(event.latlng, false);
    });
    if (useCurrentLocationBtn) {
      useCurrentLocationBtn.addEventListener('click', () => {
        if (!navigator.geolocation) {
          alert('Geolocation is not available in this browser.');
          return;
        }
        navigator.geolocation.getCurrentPosition((position) => {
          setMarker({
            lat: position.coords.latitude,
            lng: position.coords.longitude
          }, true);
        }, () => {
          alert('Unable to get your current location. You can still click the map manually.');
        }, { enableHighAccuracy: true, timeout: 10000 });
      });
    }
    if (clearLocationBtn) {
      clearLocationBtn.addEventListener('click', clearPinState);
    }
  }
  if (checkoutForm) {
    checkoutForm.addEventListener('submit', (event) => {
      const hasLat = latInput && latInput.value.trim() !== '';
      const hasLng = lngInput && lngInput.value.trim() !== '';
      const hasVerifiedPin = pinFullAddressInput && pinFullAddressInput.value.trim() !== '';
      const mismatch = evaluateAddressMismatch();
      if (!hasLat || !hasLng) {
        event.preventDefault();
        alert('Please pin your exact location on the map before checkout.');
        return;
      }
      if (!hasVerifiedPin) {
        event.preventDefault();
        alert('Please wait until the pinned location is verified.');
        return;
      }
      if (mismatch) {
        event.preventDefault();
        alert('Your shipping address does not match the pinned location. Please correct it before checkout.');
      }
    });
  }
  if (addressInput) {
    addressInput.addEventListener('input', evaluateAddressMismatch);
  }
  evaluateAddressMismatch();
  method.addEventListener('change', update);
  update();
  updatePaymentUI();
})();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
