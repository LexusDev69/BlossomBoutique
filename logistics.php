<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_admin();

function logistics_flash_set(string $message, string $type = 'success'): void
{
    $_SESSION['logistics_flash'] = ['message' => $message, 'type' => $type];
}

function logistics_flash_get(): ?array
{
    $flash = $_SESSION['logistics_flash'] ?? null;
    unset($_SESSION['logistics_flash']);
    return is_array($flash) ? $flash : null;
}

function to_datetime_local(?string $value): string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return '';
    }
    $ts = strtotime($raw);
    if ($ts === false) {
        return '';
    }
    return date('Y-m-d\TH:i', $ts);
}

function shipment_status_badge_class(string $status): string
{
    return 'status-' . preg_replace('/[^a-z_]/', '', $status);
}

function logistics_chat_time_label(?string $value): string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return '';
    }
    $ts = strtotime($raw);
    if ($ts === false) {
        return $raw;
    }
    return date('M d, Y h:i A', $ts);
}

$schemaReady = ensure_logistics_schema();
$deliveryChatReady = ensure_delivery_chat_schema();
$locationReady = ensure_order_location_schema();
$adminUrl = BASE_URL . '/admin/logistics.php';
$statuses = ['preparing', 'packed', 'picked_up', 'in_transit', 'out_for_delivery', 'delivered', 'failed_attempt', 'returned', 'cancelled'];
$parcelSizes = ['small', 'medium', 'large', 'oversized'];
$deliveryChatStatuses = ['pending', 'ready_to_deliver', 'delivered', 'cancelled'];
$deliveryProviders = ['lalamove', 'grab', 'staff', 'other'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));

    if (!$schemaReady && in_array($action, ['save_tracking', 'add_event'], true)) {
        logistics_flash_set('Logistics tracking is not ready yet.', 'error');
        header('Location: ' . $adminUrl);
        exit;
    }

    if ($action === 'save_tracking') {
        $orderId = (int) ($_POST['order_id'] ?? 0);
        $courier = trim((string) ($_POST['courier_name'] ?? ''));
        $trackingNumber = trim((string) ($_POST['tracking_number'] ?? ''));
        $trackingStatus = trim((string) ($_POST['tracking_status'] ?? 'preparing'));
        $location = trim((string) ($_POST['current_location'] ?? ''));
        $note = trim((string) ($_POST['note'] ?? ''));
        $etaInput = trim((string) ($_POST['estimated_delivery'] ?? ''));
        $riderName = trim((string) ($_POST['rider_name'] ?? ''));
        $riderPhone = trim((string) ($_POST['rider_phone'] ?? ''));
        $deliveryProofUrl = trim((string) ($_POST['delivery_proof_url'] ?? ''));
        $deliveredTo = trim((string) ($_POST['delivered_to_name'] ?? ''));
        $parcelSize = trim((string) ($_POST['parcel_size'] ?? 'medium'));

        if ($orderId < 1 || !in_array($trackingStatus, $statuses, true)) {
            logistics_flash_set('Invalid tracking request.', 'error');
            header('Location: ' . $adminUrl);
            exit;
        }
        if ($courier === '') {
            logistics_flash_set('Courier name is required.', 'error');
            header('Location: ' . $adminUrl . '?order_id=' . $orderId);
            exit;
        }
        if (!in_array($parcelSize, $parcelSizes, true)) {
            $parcelSize = 'medium';
        }
        if ($deliveryProofUrl !== '' && !filter_var($deliveryProofUrl, FILTER_VALIDATE_URL)) {
            logistics_flash_set('Delivery proof URL is invalid.', 'error');
            header('Location: ' . $adminUrl . '?order_id=' . $orderId);
            exit;
        }

        $eta = null;
        if ($etaInput !== '') {
            $etaTs = strtotime($etaInput);
            if ($etaTs !== false) {
                $eta = date('Y-m-d H:i:s', $etaTs);
            }
        }

        $orderStmt = db()->prepare('SELECT id, status FROM orders WHERE id = ? LIMIT 1');
        $orderStatus = '';
        if ($orderStmt instanceof mysqli_stmt) {
            $orderStmt->bind_param('i', $orderId);
            $orderStmt->execute();
            $order = $orderStmt->get_result()->fetch_assoc();
            $orderStmt->close();
            $orderStatus = (string) ($order['status'] ?? '');
        }
        if ($orderStatus === '') {
            logistics_flash_set('Order not found.', 'error');
            header('Location: ' . $adminUrl);
            exit;
        }

        $existingStmt = db()->prepare('SELECT id, failed_attempts, out_for_delivery_at, delivered_at FROM order_shipments WHERE order_id = ? LIMIT 1');
        $existingShipment = null;
        if ($existingStmt instanceof mysqli_stmt) {
            $existingStmt->bind_param('i', $orderId);
            $existingStmt->execute();
            $existingShipment = $existingStmt->get_result()->fetch_assoc();
            $existingStmt->close();
        }

        $now = date('Y-m-d H:i:s');
        $failedAttempts = (int) ($existingShipment['failed_attempts'] ?? 0);
        if ($trackingStatus === 'failed_attempt') {
            $failedAttempts++;
        }

        $outForDeliveryAt = trim((string) ($existingShipment['out_for_delivery_at'] ?? ''));
        if ($outForDeliveryAt === '' && $trackingStatus === 'out_for_delivery') {
            $outForDeliveryAt = $now;
        }

        $deliveredAt = trim((string) ($existingShipment['delivered_at'] ?? ''));
        if ($deliveredAt === '' && $trackingStatus === 'delivered') {
            $deliveredAt = $now;
        }

        $saveSql = "INSERT INTO order_shipments (
            order_id, courier_name, tracking_number, tracking_status, current_location, estimated_delivery,
            last_update_note, rider_name, rider_phone, delivery_proof_url, delivered_to_name,
            out_for_delivery_at, delivered_at, failed_attempts, parcel_size
        ) VALUES (
            ?, ?, NULLIF(?, ''), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
        )
        ON DUPLICATE KEY UPDATE
            courier_name = VALUES(courier_name),
            tracking_number = VALUES(tracking_number),
            tracking_status = VALUES(tracking_status),
            current_location = VALUES(current_location),
            estimated_delivery = VALUES(estimated_delivery),
            last_update_note = VALUES(last_update_note),
            rider_name = VALUES(rider_name),
            rider_phone = VALUES(rider_phone),
            delivery_proof_url = VALUES(delivery_proof_url),
            delivered_to_name = VALUES(delivered_to_name),
            out_for_delivery_at = COALESCE(order_shipments.out_for_delivery_at, VALUES(out_for_delivery_at)),
            delivered_at = COALESCE(order_shipments.delivered_at, VALUES(delivered_at)),
            failed_attempts = VALUES(failed_attempts),
            parcel_size = VALUES(parcel_size)";
        $saveStmt = db()->prepare($saveSql);
        if (!($saveStmt instanceof mysqli_stmt)) {
            logistics_flash_set('Failed to prepare tracking update query.', 'error');
            header('Location: ' . $adminUrl);
            exit;
        }

        $bindTypes = 'i' . str_repeat('s', 12) . 'is';
        $saveStmt->bind_param(
            $bindTypes,
            $orderId,
            $courier,
            $trackingNumber,
            $trackingStatus,
            $location,
            $eta,
            $note,
            $riderName,
            $riderPhone,
            $deliveryProofUrl,
            $deliveredTo,
            $outForDeliveryAt,
            $deliveredAt,
            $failedAttempts,
            $parcelSize
        );
        $ok = $saveStmt->execute();
        $errno = $saveStmt->errno;
        $insertId = (int) db()->insert_id;
        $saveStmt->close();

        if (!$ok) {
            if ($errno === 1062) {
                logistics_flash_set('Tracking number already used by another order.', 'error');
            } else {
                logistics_flash_set('Failed to save logistics tracking.', 'error');
            }
            header('Location: ' . $adminUrl . '?order_id=' . $orderId);
            exit;
        }

        $shipmentId = $insertId;
        if ($shipmentId < 1) {
            $shipStmt = db()->prepare('SELECT id FROM order_shipments WHERE order_id = ? LIMIT 1');
            if ($shipStmt instanceof mysqli_stmt) {
                $shipStmt->bind_param('i', $orderId);
                $shipStmt->execute();
                $shipmentId = (int) (($shipStmt->get_result()->fetch_assoc()['id'] ?? 0));
                $shipStmt->close();
            }
        }

        if ($shipmentId > 0) {
            $eventNote = $note !== '' ? $note : 'Status changed to ' . shipment_status_label($trackingStatus) . '.';
            $eventTime = $now;
            $eventStmt = db()->prepare('INSERT INTO order_shipment_events (shipment_id, event_status, event_location, event_note, event_time) VALUES (?, ?, ?, ?, ?)');
            if ($eventStmt instanceof mysqli_stmt) {
                $eventStmt->bind_param('issss', $shipmentId, $trackingStatus, $location, $eventNote, $eventTime);
                $eventStmt->execute();
                $eventStmt->close();
            }
        }

        if (in_array($trackingStatus, ['picked_up', 'in_transit', 'out_for_delivery', 'failed_attempt'], true) && in_array($orderStatus, ['paid', 'shipped'], true)) {
            $updateOrderStmt = db()->prepare("UPDATE orders SET status = 'shipped' WHERE id = ?");
            if ($updateOrderStmt instanceof mysqli_stmt) {
                $updateOrderStmt->bind_param('i', $orderId);
                $updateOrderStmt->execute();
                $updateOrderStmt->close();
            }
        }
        if ($trackingStatus === 'delivered' && !in_array($orderStatus, ['completed', 'cancelled'], true)) {
            $updateOrderStmt = db()->prepare("UPDATE orders SET status = 'completed' WHERE id = ?");
            if ($updateOrderStmt instanceof mysqli_stmt) {
                $updateOrderStmt->bind_param('i', $orderId);
                $updateOrderStmt->execute();
                $updateOrderStmt->close();
            }
        }

        logistics_flash_set('Tracking saved for Order ' . order_display_number($orderId) . '.');
        header('Location: ' . $adminUrl . '?order_id=' . $orderId);
        exit;
    }

    if ($action === 'add_event') {
        $orderId = (int) ($_POST['order_id'] ?? 0);
        $eventStatus = trim((string) ($_POST['event_status'] ?? 'in_transit'));
        $eventLocation = trim((string) ($_POST['event_location'] ?? ''));
        $eventNote = trim((string) ($_POST['event_note'] ?? ''));
        $eventTimeInput = trim((string) ($_POST['event_time'] ?? ''));
        $syncCurrent = isset($_POST['sync_current']) ? 1 : 0;

        if ($orderId < 1 || !in_array($eventStatus, $statuses, true) || $eventNote === '') {
            logistics_flash_set('Invalid timeline event request.', 'error');
            header('Location: ' . $adminUrl . '?order_id=' . $orderId);
            exit;
        }

        $eventTime = date('Y-m-d H:i:s');
        if ($eventTimeInput !== '') {
            $eventTs = strtotime($eventTimeInput);
            if ($eventTs !== false) {
                $eventTime = date('Y-m-d H:i:s', $eventTs);
            }
        }

        $shipmentStmt = db()->prepare('SELECT id FROM order_shipments WHERE order_id = ? LIMIT 1');
        $shipmentId = 0;
        if ($shipmentStmt instanceof mysqli_stmt) {
            $shipmentStmt->bind_param('i', $orderId);
            $shipmentStmt->execute();
            $shipmentId = (int) (($shipmentStmt->get_result()->fetch_assoc()['id'] ?? 0));
            $shipmentStmt->close();
        }

        if ($shipmentId < 1) {
            logistics_flash_set('No shipment yet for this order. Save tracking first.', 'error');
            header('Location: ' . $adminUrl . '?order_id=' . $orderId);
            exit;
        }

        $eventStmt = db()->prepare('INSERT INTO order_shipment_events (shipment_id, event_status, event_location, event_note, event_time) VALUES (?, ?, ?, ?, ?)');
        if ($eventStmt instanceof mysqli_stmt) {
            $eventStmt->bind_param('issss', $shipmentId, $eventStatus, $eventLocation, $eventNote, $eventTime);
            $eventStmt->execute();
            $eventStmt->close();
        }

        if ($syncCurrent === 1) {
            $now = date('Y-m-d H:i:s');
            $failedIncrement = $eventStatus === 'failed_attempt' ? 1 : 0;
            $syncStmt = db()->prepare("UPDATE order_shipments SET
                tracking_status = ?,
                current_location = ?,
                last_update_note = ?,
                failed_attempts = failed_attempts + ?,
                out_for_delivery_at = CASE WHEN out_for_delivery_at IS NULL AND ? = 'out_for_delivery' THEN ? ELSE out_for_delivery_at END,
                delivered_at = CASE WHEN delivered_at IS NULL AND ? = 'delivered' THEN ? ELSE delivered_at END
                WHERE id = ?");
            if ($syncStmt instanceof mysqli_stmt) {
                $syncStmt->bind_param('sssissssi', $eventStatus, $eventLocation, $eventNote, $failedIncrement, $eventStatus, $now, $eventStatus, $now, $shipmentId);
                $syncStmt->execute();
                $syncStmt->close();
            }
        }

        logistics_flash_set('Timeline event added for Order ' . order_display_number($orderId) . '.');
        header('Location: ' . $adminUrl . '?order_id=' . $orderId);
        exit;
    }

    if ($action === 'send_delivery_message' && $deliveryChatReady) {
        $orderId = (int) ($_POST['order_id'] ?? 0);
        $provider = trim((string) ($_POST['delivery_provider'] ?? 'staff'));
        $deliveryStatus = trim((string) ($_POST['delivery_status'] ?? 'pending'));
        $message = trim((string) ($_POST['message_text'] ?? ''));

        if ($orderId < 1 || !in_array($provider, $deliveryProviders, true) || !in_array($deliveryStatus, $deliveryChatStatuses, true) || $message === '') {
            logistics_flash_set('Invalid delivery chat update.', 'error');
            header('Location: ' . $adminUrl . ($orderId > 0 ? '?order_id=' . $orderId : ''));
            exit;
        }

        $orderStmt = db()->prepare('SELECT id FROM orders WHERE id = ? LIMIT 1');
        $orderExists = false;
        if ($orderStmt instanceof mysqli_stmt) {
            $orderStmt->bind_param('i', $orderId);
            $orderStmt->execute();
            $orderExists = (bool) $orderStmt->get_result()->fetch_assoc();
            $orderStmt->close();
        }

        if (!$orderExists) {
            logistics_flash_set('Order not found for delivery chat.', 'error');
            header('Location: ' . $adminUrl);
            exit;
        }

        $senderUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
        $chatStmt = db()->prepare('INSERT INTO order_delivery_messages (order_id, sender_user_id, sender_role, delivery_provider, delivery_status, message_text) VALUES (?, ?, \'admin\', ?, ?, ?)');
        if (!($chatStmt instanceof mysqli_stmt)) {
            logistics_flash_set('Failed to prepare delivery chat message.', 'error');
            header('Location: ' . $adminUrl . '?order_id=' . $orderId);
            exit;
        }

        $chatStmt->bind_param('iisss', $orderId, $senderUserId, $provider, $deliveryStatus, $message);
        $ok = $chatStmt->execute();
        $chatStmt->close();

        if (!$ok) {
            logistics_flash_set('Unable to send delivery chat update.', 'error');
            header('Location: ' . $adminUrl . '?order_id=' . $orderId);
            exit;
        }

        sync_delivery_chat_to_tracking($orderId, $provider, $deliveryStatus, $message);
        logistics_flash_set('Delivery chat update sent for Order ' . order_display_number($orderId) . '.');
        header('Location: ' . $adminUrl . '?order_id=' . $orderId . '#delivery-chat');
        exit;
    }
}

$search = trim((string) ($_GET['q'] ?? ''));
$statusFilter = trim((string) ($_GET['status'] ?? 'all'));
if ($statusFilter !== 'all' && !in_array($statusFilter, $statuses, true)) {
    $statusFilter = 'all';
}

$summaryRows = [];
$shipments = [];
if ($schemaReady) {
    $summaryResult = db()->query('SELECT tracking_status, COUNT(*) AS total FROM order_shipments GROUP BY tracking_status');
    if ($summaryResult instanceof mysqli_result) {
        while ($row = $summaryResult->fetch_assoc()) {
            $summaryRows[(string) $row['tracking_status']] = (int) $row['total'];
        }
    }

    $shipSql = "SELECT
        s.id, s.order_id, s.courier_name, s.tracking_number, s.tracking_status, s.current_location,
        s.estimated_delivery, s.updated_at, s.rider_name, s.rider_phone, s.failed_attempts, s.parcel_size,
        s.delivered_at, o.status AS order_status, o.total, u.full_name,
        (SELECT COUNT(*) FROM order_shipment_events e WHERE e.shipment_id = s.id) AS event_count
        FROM order_shipments s
        JOIN orders o ON o.id = s.order_id
        JOIN users u ON u.id = o.user_id";
    $types = '';
    $params = [];
    $where = [];
    if ($search !== '') {
        $where[] = '(s.tracking_number LIKE ? OR s.courier_name LIKE ? OR u.full_name LIKE ? OR CAST(s.order_id AS CHAR) LIKE ?)';
        $types .= 'ssss';
        $like = '%' . $search . '%';
        $params = [$like, $like, $like, $like];
    }
    if ($statusFilter !== 'all') {
        $where[] = 's.tracking_status = ?';
        $types .= 's';
        $params[] = $statusFilter;
    }
    if ($where) {
        $shipSql .= ' WHERE ' . implode(' AND ', $where);
    }
    $shipSql .= ' ORDER BY s.updated_at DESC LIMIT 80';
    $shipStmt = db()->prepare($shipSql);
    if ($shipStmt instanceof mysqli_stmt) {
        if ($types !== '') {
            $shipStmt->bind_param($types, ...$params);
        }
        $shipStmt->execute();
        $shipments = $shipStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $shipStmt->close();
    }
}

$orderRows = db()->query("SELECT o.id, o.status, o.total, o.created_at, s.tracking_status, s.tracking_number FROM orders o LEFT JOIN order_shipments s ON s.order_id = o.id WHERE o.status IN ('paid','shipped','completed') ORDER BY o.created_at DESC LIMIT 80");
$candidateOrders = $orderRows instanceof mysqli_result ? $orderRows->fetch_all(MYSQLI_ASSOC) : [];

$selectedOrderId = (int) ($_GET['order_id'] ?? 0);
$selectedShipment = null;
$selectedEvents = [];
$selectedDeliveryMessages = [];
$selectedDeliverySummary = null;
$selectedOrderDetails = null;

if ($schemaReady && $selectedOrderId > 0) {
    $selSql = "SELECT
        s.id, s.order_id, s.courier_name, s.tracking_number, s.tracking_status, s.current_location,
        s.estimated_delivery, s.last_update_note, s.updated_at, s.rider_name, s.rider_phone,
        s.delivery_proof_url, s.delivered_to_name, s.out_for_delivery_at, s.delivered_at, s.failed_attempts, s.parcel_size
        FROM order_shipments s WHERE s.order_id = ? LIMIT 1";
    $selStmt = db()->prepare($selSql);
    if ($selStmt instanceof mysqli_stmt) {
        $selStmt->bind_param('i', $selectedOrderId);
        $selStmt->execute();
        $selectedShipment = $selStmt->get_result()->fetch_assoc();
        $selStmt->close();
    }

    if ($selectedShipment) {
        $eventsStmt = db()->prepare('SELECT event_status, event_location, event_note, event_time FROM order_shipment_events WHERE shipment_id = ? ORDER BY event_time DESC, id DESC LIMIT 100');
        if ($eventsStmt instanceof mysqli_stmt) {
            $shipmentId = (int) $selectedShipment['id'];
            $eventsStmt->bind_param('i', $shipmentId);
            $eventsStmt->execute();
            $selectedEvents = $eventsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $eventsStmt->close();
        }
    }
}

if ($locationReady && $selectedOrderId > 0) {
    $orderDetailStmt = db()->prepare('SELECT o.id, o.shipping_address, o.shipping_landmark, o.shipping_map_url, o.shipping_latitude, o.shipping_longitude, o.shipping_pin_full_address, o.shipping_pin_street, o.shipping_pin_barangay, o.shipping_pin_city, o.shipping_pin_province, o.shipping_pin_postcode, u.full_name FROM orders o JOIN users u ON u.id = o.user_id WHERE o.id = ? LIMIT 1');
    if ($orderDetailStmt instanceof mysqli_stmt) {
        $orderDetailStmt->bind_param('i', $selectedOrderId);
        $orderDetailStmt->execute();
        $selectedOrderDetails = $orderDetailStmt->get_result()->fetch_assoc();
        $orderDetailStmt->close();
    }
}

if ($deliveryChatReady && $selectedOrderId > 0) {
    $chatSummaryStmt = db()->prepare('SELECT delivery_provider, delivery_status, message_text, created_at FROM order_delivery_messages WHERE order_id = ? ORDER BY id DESC LIMIT 1');
    if ($chatSummaryStmt instanceof mysqli_stmt) {
        $chatSummaryStmt->bind_param('i', $selectedOrderId);
        $chatSummaryStmt->execute();
        $selectedDeliverySummary = $chatSummaryStmt->get_result()->fetch_assoc();
        $chatSummaryStmt->close();
    }

    $chatStmt = db()->prepare("SELECT m.delivery_provider, m.delivery_status, m.message_text, m.created_at, m.sender_role, COALESCE(u.full_name, 'Blossom Team') AS sender_name FROM order_delivery_messages m LEFT JOIN users u ON u.id = m.sender_user_id WHERE m.order_id = ? ORDER BY m.created_at ASC, m.id ASC LIMIT 100");
    if ($chatStmt instanceof mysqli_stmt) {
        $chatStmt->bind_param('i', $selectedOrderId);
        $chatStmt->execute();
        $selectedDeliveryMessages = $chatStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $chatStmt->close();
    }
}
$selectedDeliveryMessagesNewestFirst = $selectedDeliveryMessages ? array_reverse($selectedDeliveryMessages) : [];

$form = [
    'order_id' => $selectedOrderId > 0 ? (string) $selectedOrderId : '',
    'courier_name' => '',
    'tracking_number' => '',
    'tracking_status' => 'preparing',
    'current_location' => '',
    'estimated_delivery' => '',
    'note' => '',
    'rider_name' => '',
    'rider_phone' => '',
    'delivery_proof_url' => '',
    'delivered_to_name' => '',
    'parcel_size' => 'medium',
];
if (is_array($selectedShipment)) {
    $form['order_id'] = (string) ($selectedShipment['order_id'] ?? '');
    $form['courier_name'] = (string) ($selectedShipment['courier_name'] ?? '');
    $form['tracking_number'] = (string) ($selectedShipment['tracking_number'] ?? '');
    $form['tracking_status'] = (string) ($selectedShipment['tracking_status'] ?? 'preparing');
    $form['current_location'] = (string) ($selectedShipment['current_location'] ?? '');
    $form['estimated_delivery'] = to_datetime_local((string) ($selectedShipment['estimated_delivery'] ?? ''));
    $form['note'] = (string) ($selectedShipment['last_update_note'] ?? '');
    $form['rider_name'] = (string) ($selectedShipment['rider_name'] ?? '');
    $form['rider_phone'] = (string) ($selectedShipment['rider_phone'] ?? '');
    $form['delivery_proof_url'] = (string) ($selectedShipment['delivery_proof_url'] ?? '');
    $form['delivered_to_name'] = (string) ($selectedShipment['delivered_to_name'] ?? '');
    $form['parcel_size'] = (string) ($selectedShipment['parcel_size'] ?? 'medium');
}

$chatForm = [
    'order_id' => $selectedOrderId > 0 ? (string) $selectedOrderId : $form['order_id'],
    'delivery_provider' => (string) ($selectedDeliverySummary['delivery_provider'] ?? 'staff'),
    'delivery_status' => (string) ($selectedDeliverySummary['delivery_status'] ?? 'pending'),
    'message_text' => '',
];

$flash = logistics_flash_get();
$pageTitle = 'Logistics Tracking';
require_once __DIR__ . '/../includes/header.php';
?>
<section class="section container">
    <h1 class="section-title reveal">Logistics Tracking Center</h1>
    <p style="display:flex;gap:8px;flex-wrap:wrap;">
        <a class="btn" href="<?= BASE_URL ?>/admin/index.php">Back to Admin Dashboard</a>
        <a class="btn btn-gold" href="<?= BASE_URL ?>/admin/logistics.php">Refresh Tracking</a>
    </p>
    <?php if ($flash): ?>
        <div class="panel" style="border-color:<?= ($flash['type'] ?? '') === 'error' ? '#b44343' : '#3f8d4f' ?>;"><?= e((string) ($flash['message'] ?? '')) ?></div>
    <?php endif; ?>
    <?php if (!$schemaReady): ?>
        <div class="panel" style="border-color:#b44343;">
            Logistics tables are not ready. Run <code>database/logistics_migration.sql</code> in phpMyAdmin, then refresh.
        </div>
    <?php endif; ?>
    <?php if (!$deliveryChatReady): ?>
        <div class="panel" style="border-color:#b44343;">
            Delivery chat table is not ready yet. Run the updated schema or migration, then refresh.
        </div>
    <?php endif; ?>
</section>

<?php if ($schemaReady): ?>
<section class="section container">
    <div class="grid">
        <div class="panel"><h3>Total Shipments</h3><p><?= array_sum($summaryRows) ?></p></div>
        <div class="panel"><h3>In Transit</h3><p><?= (int) ($summaryRows['in_transit'] ?? 0) ?></p></div>
        <div class="panel"><h3>Out for Delivery</h3><p><?= (int) ($summaryRows['out_for_delivery'] ?? 0) ?></p></div>
        <div class="panel"><h3>Delivered</h3><p><?= (int) ($summaryRows['delivered'] ?? 0) ?></p></div>
        <div class="panel"><h3>Failed Attempts</h3><p><?= (int) ($summaryRows['failed_attempt'] ?? 0) ?></p></div>
    </div>
</section>

<section class="section container">
    <div class="panel reveal">
        <h3>Create / Update Tracking</h3>
        <form method="post" style="display:grid; gap:10px; grid-template-columns:repeat(auto-fit,minmax(220px,1fr));">
            <input type="hidden" name="action" value="save_tracking">
            <div>
                <label>Order ID</label>
                <input type="number" name="order_id" min="1" required list="trackOrderList" value="<?= e($form['order_id']) ?>">
            </div>
            <div><label>Courier</label><input name="courier_name" value="<?= e($form['courier_name']) ?>" placeholder="LBC / J&T / Grab Express" required></div>
            <div><label>Tracking Number</label><input name="tracking_number" value="<?= e($form['tracking_number']) ?>" placeholder="Required for customer confidence"></div>
            <div>
                <label>Tracking Status</label>
                <select name="tracking_status">
                    <?php foreach ($statuses as $status): ?>
                        <option value="<?= e($status) ?>" <?= $status === $form['tracking_status'] ? 'selected' : '' ?>><?= e(shipment_status_label($status)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Parcel Size</label>
                <select name="parcel_size">
                    <?php foreach ($parcelSizes as $size): ?>
                        <option value="<?= e($size) ?>" <?= $size === $form['parcel_size'] ? 'selected' : '' ?>><?= e(ucfirst($size)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><label>Current Location</label><input name="current_location" value="<?= e($form['current_location']) ?>" placeholder="Hub / City / Rider area"></div>
            <div><label>Estimated Delivery</label><input type="datetime-local" name="estimated_delivery" value="<?= e($form['estimated_delivery']) ?>"></div>
            <div><label>Rider Name</label><input name="rider_name" value="<?= e($form['rider_name']) ?>" placeholder="Visible when out for delivery"></div>
            <div><label>Rider Phone</label><input name="rider_phone" value="<?= e($form['rider_phone']) ?>" placeholder="+63..."></div>
            <div><label>Delivered To</label><input name="delivered_to_name" value="<?= e($form['delivered_to_name']) ?>" placeholder="Recipient name"></div>
            <div><label>Proof Image URL</label><input name="delivery_proof_url" value="<?= e($form['delivery_proof_url']) ?>" placeholder="https://..."></div>
            <div style="grid-column:1 / -1;"><label>Update Note</label><textarea name="note" rows="3" placeholder="Package dispatched, arrived at sorting hub, rider nearby, etc."><?= e($form['note']) ?></textarea></div>
            <div><button class="btn btn-gold">Save Tracking Update</button></div>
        </form>
        <datalist id="trackOrderList">
            <?php foreach ($candidateOrders as $ord): ?>
                <option value="<?= (int) $ord['id'] ?>"><?= e(order_display_number((int) $ord['id'])) ?> - <?= e((string) $ord['status']) ?> - <?= money((float) $ord['total']) ?></option>
            <?php endforeach; ?>
        </datalist>
    </div>
</section>

<section class="section container">
    <div class="panel reveal">
        <h3>Add Timeline Event</h3>
        <form method="post" style="display:grid;gap:10px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));">
            <input type="hidden" name="action" value="add_event">
            <div><label>Order ID</label><input type="number" name="order_id" min="1" required value="<?= e($form['order_id']) ?>"></div>
            <div>
                <label>Event Status</label>
                <select name="event_status">
                    <?php foreach ($statuses as $status): ?>
                        <option value="<?= e($status) ?>"><?= e(shipment_status_label($status)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><label>Event Time</label><input type="datetime-local" name="event_time"></div>
            <div><label>Location</label><input name="event_location" placeholder="Hub / district / street"></div>
            <div style="grid-column:1 / -1;"><label>Event Note</label><textarea name="event_note" rows="2" placeholder="Parcel scanned at gateway facility" required></textarea></div>
            <label style="display:flex;gap:8px;align-items:center;"><input type="checkbox" name="sync_current" checked style="width:auto;margin:0;"> Also update current shipment status</label>
            <div><button class="btn">Add Event</button></div>
        </form>
    </div>
</section>

<?php if ($deliveryChatReady): ?>
<section class="section container" id="delivery-chat">
    <div class="panel reveal">
        <h3>Send Delivery Chat Update</h3>
        <p>Post customer-facing delivery updates for Lalamove, Grab, or your in-house staff. Each message also syncs the shipment status.</p>
        <form method="post" style="display:grid;gap:10px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));">
            <input type="hidden" name="action" value="send_delivery_message">
            <div>
                <label>Order ID</label>
                <input type="number" name="order_id" min="1" required list="trackOrderList" value="<?= e($chatForm['order_id']) ?>">
            </div>
            <div>
                <label>Delivery Provider</label>
                <select name="delivery_provider">
                    <?php foreach ($deliveryProviders as $provider): ?>
                        <option value="<?= e($provider) ?>" <?= $provider === $chatForm['delivery_provider'] ? 'selected' : '' ?>><?= e(delivery_provider_label($provider)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Delivery Status</label>
                <select name="delivery_status">
                    <?php foreach ($deliveryChatStatuses as $status): ?>
                        <option value="<?= e($status) ?>" <?= $status === $chatForm['delivery_status'] ? 'selected' : '' ?>><?= e(delivery_chat_status_label($status)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="grid-column:1 / -1;">
                <label>Message</label>
                <textarea name="message_text" rows="3" placeholder="Example: Your bouquet is now booked with Lalamove and waiting for rider pickup." required><?= e($chatForm['message_text']) ?></textarea>
            </div>
            <div><button class="btn btn-gold">Send Delivery Message</button></div>
        </form>
    </div>
</section>
<?php endif; ?>

<section class="section container">
    <div class="panel reveal">
        <h3>Tracked Shipments</h3>
        <form method="get" style="display:grid;gap:8px;grid-template-columns:2fr 1fr auto;margin-bottom:10px;">
            <input name="q" value="<?= e($search) ?>" placeholder="Search order number, tracking #, courier, customer">
            <select name="status">
                <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Status</option>
                <?php foreach ($statuses as $status): ?>
                    <option value="<?= e($status) ?>" <?= $status === $statusFilter ? 'selected' : '' ?>><?= e(shipment_status_label($status)) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn">Filter</button>
        </form>
        <table>
            <thead><tr><th>Order</th><th>Customer</th><th>Status</th><th>Tracking #</th><th>Courier</th><th>Rider</th><th>Location</th><th>ETA</th><th>Attempts</th><th>Events</th><th>Open</th></tr></thead>
            <tbody>
            <?php foreach ($shipments as $row): ?>
                <tr>
                    <td><?= e(order_display_number((int) $row['order_id'])) ?><br><small><?= e((string) $row['order_status']) ?> | <?= money((float) $row['total']) ?></small></td>
                    <td><?= e((string) $row['full_name']) ?></td>
                    <td><span class="status-badge <?= e(shipment_status_badge_class((string) $row['tracking_status'])) ?>"><?= e(shipment_status_label((string) $row['tracking_status'])) ?></span></td>
                    <td><?= e((string) ($row['tracking_number'] ?? 'N/A')) ?></td>
                    <td><?= e((string) $row['courier_name']) ?><br><small><?= e((string) ucfirst((string) ($row['parcel_size'] ?? 'medium'))) ?></small></td>
                    <td><?= e((string) ($row['rider_name'] ?? '')) ?><br><small><?= e((string) ($row['rider_phone'] ?? '')) ?></small></td>
                    <td><?= e((string) $row['current_location']) ?></td>
                    <td><?= e((string) ($row['estimated_delivery'] ?? '')) ?></td>
                    <td><?= (int) ($row['failed_attempts'] ?? 0) ?></td>
                    <td><?= (int) $row['event_count'] ?></td>
                    <td><a class="btn" href="<?= BASE_URL ?>/admin/logistics.php?order_id=<?= (int) $row['order_id'] ?>">View</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php if ($deliveryChatReady && $selectedOrderId > 0): ?>
<section class="section container">
    <div class="panel reveal">
        <h3>Delivery Chat: Order <?= e(order_display_number((int) $selectedOrderId)) ?></h3>
        <?php if ($selectedDeliverySummary): ?>
            <p>
                Latest Provider: <strong><?= e(delivery_provider_label((string) $selectedDeliverySummary['delivery_provider'])) ?></strong>
                | Status: <span class="status-badge <?= e(shipment_status_badge_class((string) $selectedDeliverySummary['delivery_status'])) ?>"><?= e(delivery_chat_status_label((string) $selectedDeliverySummary['delivery_status'])) ?></span>
                | Updated: <?= e(logistics_chat_time_label((string) $selectedDeliverySummary['created_at'])) ?>
            </p>
        <?php endif; ?>
        <?php if (!$selectedDeliveryMessagesNewestFirst): ?>
            <p>No delivery chat messages yet for this order.</p>
        <?php else: ?>
            <p class="chat-thread-note">Newest updates appear first.</p>
            <div class="chat-thread">
                <?php foreach ($selectedDeliveryMessagesNewestFirst as $message): ?>
                    <?php $roleClass = ((string) ($message['sender_role'] ?? 'admin')) === 'customer' ? 'is-customer' : 'is-admin'; ?>
                    <article class="chat-bubble <?= e($roleClass) ?>">
                        <div class="chat-meta">
                            <div class="chat-meta-main">
                                <strong><?= e((string) $message['sender_name']) ?></strong>
                                <span class="chat-role"><?= e(ucfirst((string) ($message['sender_role'] ?? 'admin'))) ?></span>
                            </div>
                            <time class="chat-time" datetime="<?= e((string) $message['created_at']) ?>"><?= e(logistics_chat_time_label((string) $message['created_at'])) ?></time>
                        </div>
                        <div class="chat-tags">
                            <span class="status-badge"><?= e(delivery_provider_label((string) $message['delivery_provider'])) ?></span>
                            <span class="status-badge <?= e(shipment_status_badge_class((string) $message['delivery_status'])) ?>"><?= e(delivery_chat_status_label((string) $message['delivery_status'])) ?></span>
                        </div>
                        <p class="chat-message"><?= nl2br(e((string) $message['message_text'])) ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($selectedOrderDetails): ?>
<section class="section container">
    <div class="panel reveal">
        <h3>Customer Delivery Location</h3>
        <p><strong>Customer:</strong> <?= e((string) $selectedOrderDetails['full_name']) ?></p>
        <p><strong>Shipping Address:</strong><br><?= nl2br(e((string) $selectedOrderDetails['shipping_address'])) ?></p>
        <?php if (trim((string) ($selectedOrderDetails['shipping_landmark'] ?? '')) !== ''): ?>
            <p><strong>Landmark / Directions:</strong> <?= e((string) $selectedOrderDetails['shipping_landmark']) ?></p>
        <?php endif; ?>
        <?php if (!empty($selectedOrderDetails['shipping_latitude']) && !empty($selectedOrderDetails['shipping_longitude'])): ?>
            <p><strong>Pinned Coordinates:</strong> <?= e((string) $selectedOrderDetails['shipping_latitude']) ?>, <?= e((string) $selectedOrderDetails['shipping_longitude']) ?></p>
        <?php endif; ?>
        <?php if (trim((string) ($selectedOrderDetails['shipping_pin_full_address'] ?? '')) !== ''): ?>
            <p><strong>Verified Pin Address:</strong> <?= e((string) $selectedOrderDetails['shipping_pin_full_address']) ?></p>
        <?php endif; ?>
        <?php
        $pinParts = array_filter([
            trim((string) ($selectedOrderDetails['shipping_pin_street'] ?? '')),
            trim((string) ($selectedOrderDetails['shipping_pin_barangay'] ?? '')),
            trim((string) ($selectedOrderDetails['shipping_pin_city'] ?? '')),
            trim((string) ($selectedOrderDetails['shipping_pin_province'] ?? '')),
            trim((string) ($selectedOrderDetails['shipping_pin_postcode'] ?? '')),
        ]);
        ?>
        <?php if ($pinParts): ?>
            <p><strong>Pin Components:</strong> <?= e(implode(', ', $pinParts)) ?></p>
        <?php endif; ?>
        <?php if (trim((string) ($selectedOrderDetails['shipping_map_url'] ?? '')) !== ''): ?>
            <p><a class="btn btn-gold" href="<?= e((string) $selectedOrderDetails['shipping_map_url']) ?>" target="_blank" rel="noopener">Open Exact Map Pin</a></p>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($selectedShipment): ?>
<section class="section container">
    <div class="panel reveal">
        <h3>Tracking Timeline: Order <?= e(order_display_number((int) $selectedShipment['order_id'])) ?></h3>
        <p>
            Courier: <strong><?= e((string) $selectedShipment['courier_name']) ?></strong>
            | Tracking #: <strong><?= e((string) ($selectedShipment['tracking_number'] ?? 'N/A')) ?></strong>
            | Status: <span class="status-badge <?= e(shipment_status_badge_class((string) $selectedShipment['tracking_status'])) ?>"><?= e(shipment_status_label((string) $selectedShipment['tracking_status'])) ?></span>
        </p>
        <p>
            Rider: <?= e((string) ($selectedShipment['rider_name'] ?? 'N/A')) ?> <?= trim((string) ($selectedShipment['rider_phone'] ?? '')) !== '' ? '(' . e((string) $selectedShipment['rider_phone']) . ')' : '' ?>
            | Failed Attempts: <?= (int) ($selectedShipment['failed_attempts'] ?? 0) ?>
            | Out for Delivery At: <?= e((string) ($selectedShipment['out_for_delivery_at'] ?? '')) ?>
            | Delivered At: <?= e((string) ($selectedShipment['delivered_at'] ?? '')) ?>
        </p>
        <table>
            <thead><tr><th>When</th><th>Status</th><th>Location</th><th>Note</th></tr></thead>
            <tbody>
            <?php foreach ($selectedEvents as $event): ?>
                <tr>
                    <td><?= e((string) $event['event_time']) ?></td>
                    <td><?= e(shipment_status_label((string) $event['event_status'])) ?></td>
                    <td><?= e((string) $event['event_location']) ?></td>
                    <td><?= e((string) $event['event_note']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
