<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/helpers.php';

function review_stars(int $rating): string
{
    $score = max(1, min(5, $rating));
    return str_repeat('★', $score) . str_repeat('☆', max(0, 5 - $score));
}

$id = (int) ($_GET['id'] ?? 0);
if ($id < 1) {
    http_response_code(404);
    exit('Product not found');
}

$stmt = db()->prepare('SELECT id, name, description, price, stock, image_url, category FROM products WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $id);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$product) {
    http_response_code(404);
    exit('Product not found');
}

$roseColorVariants = [];
$isRoseBouquet = stripos((string) $product['category'], 'ROSES BOUQUET') !== false
    || stripos((string) $product['name'], 'rose') !== false;
if ($isRoseBouquet) {
    $roseColorVariants = [
        ['key' => 'classic_red', 'name' => 'Classic Red', 'image_url' => 'https://images.pexels.com/photos/56866/garden-rose-red-pink-56866.jpeg?auto=compress&cs=tinysrgb&w=1200', 'swatch' => '#b32233'],
        ['key' => 'blush_pink', 'name' => 'Blush Pink', 'image_url' => 'https://images.pexels.com/photos/931177/pexels-photo-931177.jpeg?auto=compress&cs=tinysrgb&w=1200', 'swatch' => '#f4a7b9'],
        ['key' => 'ivory_white', 'name' => 'Ivory White', 'image_url' => 'assets/images/ivory.jpg', 'swatch' => '#f0ece3'],
        ['key' => 'peach_champagne', 'name' => 'Peach Champagne', 'image_url' => 'https://images.pexels.com/photos/1005711/pexels-photo-1005711.jpeg?auto=compress&cs=tinysrgb&w=1200', 'swatch' => '#f0bc9a'],
    ];
}

$selectedRoseColor = trim((string) ($_POST['rose_color'] ?? $_GET['rose_color'] ?? ''));
$selectedRoseImage = (string) $product['image_url'];
if ($roseColorVariants) {
    $selectedVariant = $roseColorVariants[0];
    foreach ($roseColorVariants as $variant) {
        if ($selectedRoseColor !== '' && $selectedRoseColor === (string) $variant['key']) {
            $selectedVariant = $variant;
            break;
        }
    }
    $selectedRoseColor = (string) $selectedVariant['key'];
    $selectedRoseImage = (string) $selectedVariant['image_url'];
}

$feedbackReady = ensure_feedback_schema();
$reviews = [];
$reviewCount = 0;
$averageRating = 0.0;
if ($feedbackReady) {
    $summaryStmt = db()->prepare('SELECT COUNT(*) AS reviews_count, COALESCE(AVG(rating), 0) AS avg_rating FROM product_feedback WHERE product_id = ?');
    if ($summaryStmt instanceof mysqli_stmt) {
        $summaryStmt->bind_param('i', $id);
        $summaryStmt->execute();
        $summary = $summaryStmt->get_result()->fetch_assoc();
        $summaryStmt->close();
        $reviewCount = (int) ($summary['reviews_count'] ?? 0);
        $averageRating = (float) ($summary['avg_rating'] ?? 0);
    }

    $reviewsStmt = db()->prepare('SELECT f.rating, f.review_title, f.review_text, f.created_at, u.full_name FROM product_feedback f JOIN users u ON u.id = f.user_id WHERE f.product_id = ? ORDER BY f.created_at DESC LIMIT 20');
    if ($reviewsStmt instanceof mysqli_stmt) {
        $reviewsStmt->bind_param('i', $id);
        $reviewsStmt->execute();
        $reviews = $reviewsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $reviewsStmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_logged_in()) {
        $redirect = (string) ($_SERVER['REQUEST_URI'] ?? (BASE_URL . '/product.php?id=' . (int) $product['id']));
        header('Location: ' . BASE_URL . '/login.php?redirect=' . urlencode($redirect));
        exit;
    }
    $activeUser = current_user();
    if (($activeUser['role'] ?? '') === 'admin') {
        $_SESSION['admin_flash'] = ['message' => 'Admin accounts cannot place customer orders.', 'type' => 'error'];
        header('Location: ' . BASE_URL . '/admin/index.php');
        exit;
    }

    $qty = max(1, (int) ($_POST['quantity'] ?? 1));
    add_to_cart((int) $product['id'], $qty);
    header('Location: ' . BASE_URL . '/cart.php');
    exit;
}

$pageTitle = 'Product Details';
$pageUser = current_user();
require_once __DIR__ . '/includes/header.php';

$now = new DateTimeImmutable('now');
$cutoffHour = 18;
if ((int) $now->format('G') >= $cutoffHour) {
    $now = $now->modify('+1 day');
}
$deliveryStart = $now->modify('+1 day');
$deliveryEnd = $now->modify('+2 day');
?>
<section class="section container reveal">
    <div class="grid" style="grid-template-columns: 1fr 1fr;">
        <div>
            <img id="productMainImage" src="<?= e($selectedRoseImage) ?>" alt="<?= e($product['name']) ?>" style="width:100%; border-radius:18px; box-shadow: var(--shadow);">
            <?php if ($roseColorVariants): ?>
                <p id="roseColorCaption" class="luxury-note">Selected Rose Color: <strong><?= e((string) ($selectedVariant['name'] ?? 'Classic Red')) ?></strong></p>
            <?php endif; ?>
            <div class="trust-grid">
                <article class="trust-item"><strong>Authentic Handcraft</strong><span>Each bloom is assembled and checked by a stylist.</span></article>
                <article class="trust-item"><strong>Secure Packaging</strong><span>Shock-safe premium box with satin seal and care card.</span></article>
                <article class="trust-item"><strong>Priority Handling</strong><span>Express lane fulfillment for event and same-day orders.</span></article>
            </div>
        </div>
        <div class="panel">
            <span class="flower-chip"><?= e($product['category']) ?></span>
            <h1><?= e($product['name']) ?></h1>
            <p><?= e($product['description']) ?></p>
            <p><strong><?= money((float) $product['price']) ?></strong></p>
            <?php if ($feedbackReady): ?>
                <p>Customer Rating: <strong><?= $reviewCount > 0 ? e(review_stars((int) round($averageRating))) . ' (' . number_format($averageRating, 1) . '/5)' : 'No ratings yet' ?></strong> <small>(<?= $reviewCount ?> review<?= $reviewCount === 1 ? '' : 's' ?>)</small></p>
            <?php endif; ?>
            <p>Stock: <?= (int) $product['stock'] > 0 ? (int) $product['stock'] . ' bouquet(s) available' : 'Out of stock' ?></p>
            <p class="luxury-note">Estimated delivery window: <?= e($deliveryStart->format('M d')) ?> - <?= e($deliveryEnd->format('M d, Y')) ?></p>
            <form method="post">
                <?php if ($roseColorVariants): ?>
                    <div class="rose-color-picker">
                        <label>Pick Rose Color</label>
                        <div class="rose-color-grid">
                            <?php foreach ($roseColorVariants as $variant): ?>
                                <?php $isSelected = $selectedRoseColor === (string) $variant['key']; ?>
                                <button
                                    type="button"
                                    class="rose-color-option<?= $isSelected ? ' is-selected' : '' ?>"
                                    data-rose-color
                                    data-color-key="<?= e((string) $variant['key']) ?>"
                                    data-color-name="<?= e((string) $variant['name']) ?>"
                                    data-image-url="<?= e((string) $variant['image_url']) ?>"
                                >
                                    <span class="rose-color-swatch" style="background:<?= e((string) $variant['swatch']) ?>;"></span>
                                    <span><?= e((string) $variant['name']) ?></span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                        <input type="hidden" name="rose_color" id="roseColorInput" value="<?= e($selectedRoseColor) ?>">
                    </div>
                <?php endif; ?>
                <label>Ribbon Color</label>
                <select name="ribbon_color">
                    <option>Champagne Gold</option>
                    <option>Blush Pink</option>
                    <option>Ivory White</option>
                    <option>Matte Black</option>
                </select>
                <label>Message Card (Optional)</label>
                <textarea name="message_note" rows="3" placeholder="Write your short dedication..."></textarea>
                <label>Premium Add-ons</label>
                <div class="addon-list">
                    <label><input type="checkbox" name="addons[]" value="scent-card"> Signature scent card</label>
                    <label><input type="checkbox" name="addons[]" value="gift-box"> Luxury keepsake gift box</label>
                    <label><input type="checkbox" name="addons[]" value="lights"> Soft warm fairy lights</label>
                </div>
                <label>Quantity</label>
                <input type="number" min="1" max="<?= (int) $product['stock'] ?>" value="1" name="quantity">
                <?php if (!is_logged_in()): ?>
                    <a class="btn btn-gold" href="<?= BASE_URL ?>/login.php?redirect=<?= urlencode(BASE_URL . '/product.php?id=' . (int) $product['id']) ?>">Login to Add to Cart</a>
                <?php elseif (($pageUser['role'] ?? '') === 'admin'): ?>
                    <button class="btn" type="button" disabled>Admin Accounts Cannot Order</button>
                <?php else: ?>
                    <button class="btn btn-gold" <?= (int) $product['stock'] < 1 ? 'disabled' : '' ?>>Add to Cart</button>
                <?php endif; ?>
            </form>
            <ul class="highlights-list">
                <li>Free dedication card for orders above PHP 3,500.</li>
                <li>Concierge support for event styling and color matching.</li>
                <li>Secure checkout with trusted payment methods.</li>
            </ul>
        </div>
    </div>
</section>

<?php if ($feedbackReady): ?>
<section class="section container">
    <div class="panel reveal">
        <h2>Customer Feedback</h2>
        <?php if (!$reviews): ?>
            <p>No feedback for this item yet.</p>
        <?php else: ?>
            <div class="timeline-list">
                <?php foreach ($reviews as $review): ?>
                    <article class="timeline-item">
                        <div class="time"><?= e((string) $review['created_at']) ?></div>
                        <div class="body">
                            <h4><?= e((string) $review['full_name']) ?> - <?= e(review_stars((int) $review['rating'])) ?></h4>
                            <?php if (trim((string) $review['review_title']) !== ''): ?>
                                <p><strong><?= e((string) $review['review_title']) ?></strong></p>
                            <?php endif; ?>
                            <p><?= e((string) $review['review_text']) ?></p>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>
<?php if ($roseColorVariants): ?>
<script>
(() => {
  const mainImage = document.getElementById('productMainImage');
  const colorButtons = document.querySelectorAll('[data-rose-color]');
  const colorInput = document.getElementById('roseColorInput');
  const caption = document.getElementById('roseColorCaption');
  if (!mainImage || !colorButtons.length || !colorInput) return;

  colorButtons.forEach((btn) => {
    btn.addEventListener('click', () => {
      colorButtons.forEach((item) => item.classList.remove('is-selected'));
      btn.classList.add('is-selected');
      const imageUrl = btn.getAttribute('data-image-url') || '';
      const colorKey = btn.getAttribute('data-color-key') || '';
      const colorName = btn.getAttribute('data-color-name') || '';
      if (imageUrl !== '') {
        mainImage.src = imageUrl;
      }
      colorInput.value = colorKey;
      if (caption) {
        caption.innerHTML = 'Selected Rose Color: <strong>' + colorName + '</strong>';
      }
    });
  });
})();
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
