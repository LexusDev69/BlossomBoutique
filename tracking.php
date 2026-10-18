<?php
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/helpers.php';
require_login();

function tracking_status_badge_class(string $status): string
{
    return 'status-' . preg_replace('/[^a-z_]/', '', $status);
}

function tracking_chat_time_label(?string $value): string
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

function feedback_flash_set(string $message, string $type = 'success'): void
{
    $_SESSION['feedback_flash'] = ['message' => $message, 'type' => $type];
}

function feedback_flash_get(): ?array
{
    $flash = $_SESSION['feedback_flash'] ?? null;
    unset($_SESSION['feedback_flash']);
    return is_array($flash) ? $flash : null;
}

$logisticsReady = ensure_logistics_schema();
$feedbackReady = ensure_feedback_schema();
$deliveryChatReady = ensure_delivery_chat_schema();
$orderId = (int) ($_GET['id'] ?? $_POST['order_id'] ?? 0);
if ($orderId < 1) {
    http_response_code(404);
    exit('Order not found');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_feedback') {
    if (!$feedbackReady) {
        feedback_flash_set('Feedback system is not ready. Please try again later.', 'error');
        header('Location: ' . BASE_URL . '/tracking.php?id=' . $orderId . '#feedback');
        exit;
    }

    $productId = (int) ($_POST['product_id'] ?? 0);
    $rating = max(1, min(5, (int) ($_POST['rating'] ?? 0)));
    $title = trim((string) ($_POST['review_title'] ?? ''));
    $reviewText = trim((string) ($_POST['review_text'] ?? ''));
    $isTestimonial = isset($_POST['is_testimonial']) ? 1 : 0;

    if ($productId < 1 || $reviewText === '') {
        feedback_flash_set('Please provide a valid rating and feedback message.', 'error');
        header('Location: ' . BASE_URL . '/tracking.php?id=' . $orderId . '#feedback');
        exit;
    }

    $verifyStmt = db()->prepare('SELECT o.id FROM orders o JOIN order_items oi ON oi.order_id = o.id WHERE o.id = ? AND o.user_id = ? AND o.status = \'completed\' AND oi.product_id = ? LIMIT 1');
    $isValid = false;
    if ($verifyStmt instanceof mysqli_stmt) {
        $verifyStmt->bind_param('iii', $orderId, $_SESSION['user_id'], $productId);
        $verifyStmt->execute();
        $isValid = (bool) $verifyStmt->get_result()->fetch_assoc();
        $verifyStmt->close();
    }

    if (!$isValid) {
        feedback_flash_set('You can only review completed items from your own order.', 'error');
        header('Location: ' . BASE_URL . '/tracking.php?id=' . $orderId . '#feedback');
        exit;
    }

    $saveStmt = db()->prepare("INSERT INTO product_feedback (order_id, user_id, product_id, rating, review_title, review_text, is_testimonial) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE rating = VALUES(rating), review_title = VALUES(review_title), review_text = VALUES(review_text), is_testimonial = VALUES(is_testimonial), updated_at = CURRENT_TIMESTAMP");
    if ($saveStmt instanceof mysqli_stmt) {
        $userId = (int) $_SESSION['user_id'];
        $saveStmt->bind_param('iiiissi', $orderId, $userId, $productId, $rating, $title, $reviewText, $isTestimonial);
        $ok = $saveStmt->execute();
        $saveStmt->close();
        feedback_flash_set($ok ? 'Feedback saved. Thank you for your review!' : 'Unable to save feedback right now.', $ok ? 'success' : 'error');
    } else {
        feedback_flash_set('Unable to prepare feedback save.', 'error');
    }

    header('Location: ' . BASE_URL . '/tracking.php?id=' . $orderId . '#feedback');
    exit;
}

$sql = $logisticsReady
    ? "SELECT
        o.id, o.total, o.status, o.created_at,
        s.id AS shipment_id, s.courier_name, s.tracking_number, s.tracking_status, s.current_location,
        s.estimated_delivery, s.last_update_note, s.updated_at, s.rider_name, s.rider_phone,
        s.delivery_proof_url, s.delivered_to_name, s.out_for_delivery_at, s.delivered_at,
        s.failed_attempts, s.parcel_size
       FROM orders o
       LEFT JOIN order_shipments s ON s.order_id = o.id
       WHERE o.id = ? AND o.user_id = ? LIMIT 1"
    : "SELECT
        o.id, o.total, o.status, o.created_at,
        NULL AS shipment_id, NULL AS courier_name, NULL AS tracking_number, NULL AS tracking_status, NULL AS current_location,
        NULL AS estimated_delivery, NULL AS last_update_note, NULL AS updated_at, NULL AS rider_name, NULL AS rider_phone,
        NULL AS delivery_proof_url, NULL AS delivered_to_name, NULL AS out_for_delivery_at, NULL AS delivered_at,
        NULL AS failed_attempts, NULL AS parcel_size
       FROM orders o
       WHERE o.id = ? AND o.user_id = ? LIMIT 1";
$stmt = db()->prepare($sql);
$stmt->bind_param('ii', $orderId, $_SESSION['user_id']);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order) {
    http_response_code(404);
    exit('Order not found');
}
$displayOrderNumber = order_display_number((int) $order['id']);

$events = [];
if ($logisticsReady && (int) ($order['shipment_id'] ?? 0) > 0) {
    $eventStmt = db()->prepare('SELECT event_status, event_location, event_note, event_time FROM order_shipment_events WHERE shipment_id = ? ORDER BY event_time DESC, id DESC LIMIT 120');
    if ($eventStmt instanceof mysqli_stmt) {
        $shipmentId = (int) $order['shipment_id'];
        $eventStmt->bind_param('i', $shipmentId);
        $eventStmt->execute();
        $events = $eventStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $eventStmt->close();
    }
}

$deliveryMessages = [];
$latestDeliveryMessage = null;
if ($deliveryChatReady) {
    $chatStmt = db()->prepare("SELECT m.delivery_provider, m.delivery_status, m.message_text, m.created_at, COALESCE(u.full_name, 'Blossom Team') AS sender_name FROM order_delivery_messages m LEFT JOIN users u ON u.id = m.sender_user_id WHERE m.order_id = ? ORDER BY m.created_at ASC, m.id ASC LIMIT 100");
    if ($chatStmt instanceof mysqli_stmt) {
        $chatStmt->bind_param('i', $orderId);
        $chatStmt->execute();
        $deliveryMessages = $chatStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $chatStmt->close();
    }
    if ($deliveryMessages) {
        $latestDeliveryMessage = $deliveryMessages[count($deliveryMessages) - 1];
    }
}
$deliveryMessagesNewestFirst = $deliveryMessages ? array_reverse($deliveryMessages) : [];

$feedbackItems = [];
if ($feedbackReady && $order['status'] === 'completed') {
    $feedbackStmt = db()->prepare('SELECT oi.product_id, p.name AS product_name, p.image_url, SUM(oi.quantity) AS quantity, MIN(oi.unit_price) AS unit_price, f.rating, f.review_title, f.review_text, f.is_testimonial FROM order_items oi JOIN products p ON p.id = oi.product_id LEFT JOIN product_feedback f ON f.order_id = oi.order_id AND f.user_id = ? AND f.product_id = oi.product_id WHERE oi.order_id = ? GROUP BY oi.product_id, p.name, p.image_url, f.rating, f.review_title, f.review_text, f.is_testimonial ORDER BY p.name ASC');
    if ($feedbackStmt instanceof mysqli_stmt) {
        $userId = (int) $_SESSION['user_id'];
        $feedbackStmt->bind_param('ii', $userId, $orderId);
        $feedbackStmt->execute();
        $feedbackItems = $feedbackStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $feedbackStmt->close();
    }
}

$trackingStatus = (string) ($order['tracking_status'] ?? 'preparing');
$progressSteps = shipment_progress_steps();
$progressPos = shipment_progress_position($trackingStatus);
$progressPercent = (int) round(($progressPos / max(1, count($progressSteps))) * 100);
$etaTs = strtotime((string) ($order['estimated_delivery'] ?? ''));
$etaText = '';
if ($etaTs !== false && $etaTs > 0) {
    $diff = $etaTs - time();
    if ($diff > 0) {
        $hours = (int) floor($diff / 3600);
        $minutes = (int) floor(($diff % 3600) / 60);
        $etaText = $hours > 0 ? $hours . 'h ' . $minutes . 'm remaining' : max(1, $minutes) . 'm remaining';
    } else {
        $etaText = 'ETA has passed';
    }
}

$feedbackFlash = feedback_flash_get();
$pageTitle = 'Track Order';
require_once __DIR__ . '/includes/header.php';
?>
<section class="section container">
    <div class="panel reveal">
        <h1>Track Order <?= e($displayOrderNumber) ?></h1>
        <p>Order Status: <strong><?= e((string) $order['status']) ?></strong></p>
        <p>Total: <strong><?= money((float) $order['total']) ?></strong></p>
        <p>Placed: <?= e((string) $order['created_at']) ?></p>
        <p style="display:flex;gap:8px;flex-wrap:wrap;">
            <a class="btn" href="<?= BASE_URL ?>/account.php">Back to My Account</a>
            <?php if ($deliveryChatReady): ?>
                <a class="btn" href="#delivery-chat">Open Delivery Chat</a>
            <?php endif; ?>
            <?php if (!empty($order['tracking_number'])): ?>
                <button class="btn" type="button" id="copyTrackingBtn" data-tracking="<?= e((string) $order['tracking_number']) ?>">Copy Tracking Number</button>
            <?php endif; ?>
        </p>
    </div>
</section>

<?php if (!$logisticsReady): ?>
<section class="section container">
    <div class="panel" style="border-color:#b44343;">
        Logistics tracking is not ready yet.
    </div>
</section>
<?php elseif ((int) ($order['shipment_id'] ?? 0) < 1): ?>
<section class="section container">
    <div class="panel">
        Tracking details are not yet shared by courier for this order. Current order status: <strong><?= e((string) $order['status']) ?></strong>.
        <?php if ($order['status'] === 'completed'): ?>
            You can still submit your item feedback below.
        <?php endif; ?>
    </div>
</section>
<?php else: ?>
<section class="section container">
    <div class="panel reveal tracking-journey">
        <div class="tracking-head">
            <h2>Parcel Journey</h2>
            <span class="status-badge <?= e(tracking_status_badge_class($trackingStatus)) ?>"><?= e(shipment_status_label($trackingStatus)) ?></span>
        </div>
        <div class="journey-progress">
            <div class="journey-progress-fill" style="width:<?= $progressPercent ?>%;"></div>
        </div>
        <div class="journey-steps">
            <?php foreach ($progressSteps as $idx => $step): ?>
                <?php
                $stepPos = $idx + 1;
                $stepClass = $stepPos <= $progressPos ? 'done' : '';
                if ($trackingStatus === 'returned' || $trackingStatus === 'cancelled') {
                    $stepClass = $stepPos <= min($progressPos, 4) ? 'done' : '';
                }
                ?>
                <div class="journey-step <?= e($stepClass) ?>">
                    <span class="dot"><?= $stepPos <= $progressPos ? '&#10003;' : (string) $stepPos ?></span>
                    <span class="label"><?= e((string) $step['label']) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if ($trackingStatus === 'failed_attempt'): ?>
            <p class="tracking-alert">Delivery attempt failed. Courier will retry soon. Attempts: <?= (int) ($order['failed_attempts'] ?? 0) ?></p>
        <?php elseif ($trackingStatus === 'returned' || $trackingStatus === 'cancelled'): ?>
            <p class="tracking-alert">Shipment is marked as <?= e(shipment_status_label($trackingStatus)) ?>. Please contact support for assistance.</p>
        <?php endif; ?>
    </div>
</section>

<section class="section container">
    <div class="grid tracking-grid">
        <div class="panel reveal">
            <h2>Shipment Details</h2>
            <p>Courier: <strong><?= e((string) $order['courier_name']) ?></strong></p>
            <p>Tracking Number: <strong><?= e((string) ($order['tracking_number'] ?? 'N/A')) ?></strong></p>
            <p>Parcel Size: <?= e(ucfirst((string) ($order['parcel_size'] ?? 'medium'))) ?></p>
            <p>Current Location: <?= e((string) ($order['current_location'] ?? '')) ?></p>
            <p>Estimated Delivery: <?= e((string) ($order['estimated_delivery'] ?? '')) ?></p>
            <?php if ($etaText !== ''): ?>
                <p><strong>ETA:</strong> <?= e($etaText) ?></p>
            <?php endif; ?>
            <?php if (trim((string) ($order['last_update_note'] ?? '')) !== ''): ?>
                <p>Latest Note: <?= e((string) $order['last_update_note']) ?></p>
            <?php endif; ?>
            <p>Last Updated: <?= e((string) ($order['updated_at'] ?? '')) ?></p>
        </div>
        <div class="panel reveal">
            <h2>Rider & Delivery</h2>
            <p>Rider: <strong><?= e((string) ($order['rider_name'] ?? 'Not assigned yet')) ?></strong></p>
            <p>Contact: <?= e((string) ($order['rider_phone'] ?? 'N/A')) ?></p>
            <p>Out for Delivery At: <?= e((string) ($order['out_for_delivery_at'] ?? '')) ?></p>
            <p>Delivered At: <?= e((string) ($order['delivered_at'] ?? '')) ?></p>
            <p>Failed Attempts: <?= (int) ($order['failed_attempts'] ?? 0) ?></p>
            <p>Received By: <?= e((string) ($order['delivered_to_name'] ?? '')) ?></p>
            <?php if (trim((string) ($order['delivery_proof_url'] ?? '')) !== ''): ?>
                <p><a class="btn" href="<?= e((string) $order['delivery_proof_url']) ?>" target="_blank" rel="noopener">View Proof of Delivery</a></p>
                <img src="<?= e((string) $order['delivery_proof_url']) ?>" alt="Proof of delivery" style="width:100%;max-height:260px;object-fit:cover;border-radius:12px;border:1px solid var(--border);">
            <?php endif; ?>
        </div>
    </div>
</section>

<section class="section container">
    <div class="panel reveal">
        <h2>Tracking Timeline</h2>
        <div class="timeline-list">
            <?php foreach ($events as $event): ?>
                <article class="timeline-item">
                    <div class="time"><?= e((string) $event['event_time']) ?></div>
                    <div class="body">
                        <h4><?= e(shipment_status_label((string) $event['event_status'])) ?></h4>
                        <p><?= e((string) $event['event_location']) ?></p>
                        <p><?= e((string) $event['event_note']) ?></p>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if ($deliveryChatReady): ?>
<section class="section container" id="delivery-chat">
    <div class="panel reveal">
        <div class="tracking-head">
            <h2>Delivery Chat</h2>
            <?php if ($latestDeliveryMessage): ?>
                <span class="status-badge <?= e(tracking_status_badge_class((string) $latestDeliveryMessage['delivery_status'])) ?>"><?= e(delivery_chat_status_label((string) $latestDeliveryMessage['delivery_status'])) ?></span>
            <?php endif; ?>
        </div>
        <?php if ($latestDeliveryMessage): ?>
            <p>
                Delivery via <strong><?= e(delivery_provider_label((string) $latestDeliveryMessage['delivery_provider'])) ?></strong>
                | Latest update: <?= e(tracking_chat_time_label((string) $latestDeliveryMessage['created_at'])) ?>
            </p>
        <?php else: ?>
            <p>We have not posted a delivery chat update for this order yet.</p>
        <?php endif; ?>

        <?php if ($deliveryMessagesNewestFirst): ?>
            <p class="chat-thread-note">Newest updates appear first.</p>
            <div class="chat-thread">
                <?php foreach ($deliveryMessagesNewestFirst as $message): ?>
                    <article class="chat-bubble is-admin">
                        <div class="chat-meta">
                            <div class="chat-meta-main">
                                <strong><?= e((string) $message['sender_name']) ?></strong>
                                <span class="chat-role">Support</span>
                            </div>
                            <time class="chat-time" datetime="<?= e((string) $message['created_at']) ?>"><?= e(tracking_chat_time_label((string) $message['created_at'])) ?></time>
                        </div>
                        <div class="chat-tags">
                            <span class="status-badge"><?= e(delivery_provider_label((string) $message['delivery_provider'])) ?></span>
                            <span class="status-badge <?= e(tracking_status_badge_class((string) $message['delivery_status'])) ?>"><?= e(delivery_chat_status_label((string) $message['delivery_status'])) ?></span>
                        </div>
                        <p class="chat-message"><?= nl2br(e((string) $message['message_text'])) ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($order['status'] === 'completed'): ?>
<section class="section container" id="feedback">
    <div class="panel reveal">
        <h2>Item Feedback</h2>
        <?php if ($feedbackFlash): ?>
            <p style="color:<?= ($feedbackFlash['type'] ?? '') === 'error' ? '#b30000' : '#2d7a32' ?>;"><?= e((string) ($feedbackFlash['message'] ?? '')) ?></p>
        <?php endif; ?>
        <?php if (!$feedbackReady): ?>
            <p>Feedback feature is currently unavailable.</p>
        <?php elseif (!$feedbackItems): ?>
            <p>No product items found in this order for feedback.</p>
        <?php else: ?>
            <div class="grid" style="grid-template-columns:1fr;">
                <?php foreach ($feedbackItems as $item): ?>
                    <article class="panel" style="margin:0;">
                        <h3><?= e((string) $item['product_name']) ?></h3>
                        <p>Ordered Quantity: <?= (int) $item['quantity'] ?> | Price: <?= money((float) $item['unit_price']) ?></p>
                        <?php if (!empty($item['rating'])): ?>
                            <p>Current Rating: <?= str_repeat('★', (int) $item['rating']) . str_repeat('☆', max(0, 5 - (int) $item['rating'])) ?></p>
                        <?php endif; ?>
                        <form method="post">
                            <input type="hidden" name="action" value="submit_feedback">
                            <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                            <input type="hidden" name="product_id" value="<?= (int) $item['product_id'] ?>">
                            <label>Rating</label>
                            <select name="rating" required>
                                <?php for ($r = 5; $r >= 1; $r--): ?>
                                    <option value="<?= $r ?>" <?= (int) ($item['rating'] ?? 0) === $r ? 'selected' : '' ?>><?= $r ?> Star<?= $r > 1 ? 's' : '' ?></option>
                                <?php endfor; ?>
                            </select>
                            <input name="review_title" placeholder="Short title" value="<?= e((string) ($item['review_title'] ?? '')) ?>">
                            <textarea name="review_text" rows="3" placeholder="Share your experience with this item..." required><?= e((string) ($item['review_text'] ?? '')) ?></textarea>
                            <label><input type="checkbox" name="is_testimonial" <?= (int) ($item['is_testimonial'] ?? 0) === 1 ? 'checked' : '' ?> style="width:auto;margin:0 8px 0 0;">Show this review as homepage testimonial</label>
                            <button class="btn btn-gold">Save Feedback</button>
                        </form>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<script>
(() => {
  const btn = document.getElementById('copyTrackingBtn');
  if (!btn) return;
  btn.addEventListener('click', async () => {
    const val = btn.getAttribute('data-tracking') || '';
    if (!val) return;
    try {
      await navigator.clipboard.writeText(val);
      const old = btn.textContent;
      btn.textContent = 'Copied!';
      setTimeout(() => { btn.textContent = old; }, 1200);
    } catch (err) {
      alert('Unable to copy tracking number.');
    }
  });
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
