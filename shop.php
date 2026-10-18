<?php
declare(strict_types=1);
$pageTitle = 'Shop';
require_once __DIR__ . '/includes/header.php';

function shop_slug(string $value): string
{
    $slug = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $value));
    $slug = trim($slug, '-');
    return $slug !== '' ? $slug : 'category';
}

$search = trim((string) ($_GET['q'] ?? ''));
$category = trim((string) ($_GET['category'] ?? ''));
$sort = trim((string) ($_GET['sort'] ?? 'latest'));
$curatedCategories = [
    'Satin Rose Bouquet' => 'SATIN ROSE BOUQUET',
    'Satin Tulips Bouquet' => 'SATIN TULIPS BOUQUET',
    'Satin Plumeria Bouquet' => 'SATIN PLUMERIA BOUQUET',
    'Satin Dahlia Bouquet' => 'SATIN DAHLIA BOUQUET',
    'Satin Lily Bouquet' => 'SATIN LILY BOUQUET',
];
if ($category !== '' && !array_key_exists($category, $curatedCategories)) {
    $category = '';
}

$where = [];
$types = '';
$params = [];
$allowedCategories = array_keys($curatedCategories);
$where[] = 'category IN (' . implode(',', array_fill(0, count($allowedCategories), '?')) . ')';
$types .= str_repeat('s', count($allowedCategories));
foreach ($allowedCategories as $allowedCategory) {
    $params[] = $allowedCategory;
}
if ($search !== '') {
    $where[] = '(name LIKE ? OR description LIKE ?)';
    $types .= 'ss';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
}
if ($category !== '') {
    $where[] = 'category = ?';
    $types .= 's';
    $params[] = $category;
}

$orderBy = match ($sort) {
    'price_asc' => 'price ASC',
    'price_desc' => 'price DESC',
    'name_asc' => 'name ASC',
    default => 'created_at DESC'
};

$sql = 'SELECT id, name, description, price, category, stock, image_url FROM products';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= " ORDER BY $orderBy";

$stmt = db()->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$productRows = $result instanceof mysqli_result ? $result->fetch_all(MYSQLI_ASSOC) : [];
$totalProducts = count($productRows);

$categories = array_keys($curatedCategories);

$groupedProductsRaw = [];
foreach ($productRows as $row) {
    $groupName = trim((string) ($row['category'] ?? 'Uncategorized'));
    if (!isset($groupedProductsRaw[$groupName])) {
        $groupedProductsRaw[$groupName] = [];
    }
    $groupedProductsRaw[$groupName][] = $row;
}
$groupedProducts = [];
foreach ($categories as $categoryName) {
    if (isset($groupedProductsRaw[$categoryName])) {
        $groupedProducts[$categoryName] = $groupedProductsRaw[$categoryName];
    }
}
foreach ($groupedProductsRaw as $categoryName => $rows) {
    if (!isset($groupedProducts[$categoryName])) {
        $groupedProducts[$categoryName] = $rows;
    }
}

$showGrouped = $search === '' && $category === '';
$baseParams = [];
if ($search !== '') {
    $baseParams['q'] = $search;
}
if ($sort !== 'latest') {
    $baseParams['sort'] = $sort;
}
?>
<section class="section container">
    <h1 class="section-title reveal">Shop</h1>
    <div class="service-strip reveal">
        <article class="service-item"><strong>Same-Day Delivery</strong><span>Metro orders confirmed before cutoff.</span></article>
        <article class="service-item"><strong>Premium Wrapping</strong><span>Layered satin wrap and signature ribbon seal.</span></article>
        <article class="service-item"><strong>Secure Checkout</strong><span>Protected payments and verified order handling.</span></article>
        <article class="service-item"><strong>Concierge Support</strong><span>Need custom flowers? We style by event mood.</span></article>
    </div>
    <form class="filters reveal" method="get">
        <input type="text" name="q" placeholder="Search bouquets..." value="<?= e($search) ?>">
        <select name="category">
            <option value="">ALL SATIN</option>
            <?php foreach ($categories as $categoryName): ?>
                <option value="<?= e($categoryName) ?>" <?= $category === $categoryName ? 'selected' : '' ?>><?= e((string) ($curatedCategories[$categoryName] ?? $categoryName)) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="sort">
            <option value="latest" <?= $sort === 'latest' ? 'selected' : '' ?>>Latest</option>
            <option value="price_asc" <?= $sort === 'price_asc' ? 'selected' : '' ?>>Price Low to High</option>
            <option value="price_desc" <?= $sort === 'price_desc' ? 'selected' : '' ?>>Price High to Low</option>
            <option value="name_asc" <?= $sort === 'name_asc' ? 'selected' : '' ?>>Name A-Z</option>
        </select>
        <button class="btn btn-gold">Apply</button>
    </form>
    <div class="collection-tabs reveal">
        <?php
        $allUrlParams = $baseParams;
        $allUrl = BASE_URL . '/shop.php' . ($allUrlParams ? '?' . http_build_query($allUrlParams) : '');
        ?>
        <a class="tab-btn <?= $category === '' ? 'active' : '' ?>" href="<?= e($allUrl) ?>">ALL SATIN</a>
        <?php foreach ($categories as $categoryName): ?>
            <?php
            $urlParams = $baseParams;
            $urlParams['category'] = $categoryName;
            $url = BASE_URL . '/shop.php?' . http_build_query($urlParams);
            ?>
            <a class="tab-btn <?= $category === $categoryName ? 'active' : '' ?>" href="<?= e($url) ?>"><?= e((string) ($curatedCategories[$categoryName] ?? $categoryName)) ?></a>
        <?php endforeach; ?>
        <a class="tab-btn" href="<?= BASE_URL ?>/customize.php">CUSTOMIZE BOUQUET</a>
    </div>
    <p class="result-note reveal"><?= $totalProducts ?> satin flower option(s) found for your selection.</p>
    <p class="result-note reveal"><a class="btn" href="<?= BASE_URL ?>/customize.php">Create a Custom Bouquet Request</a></p>
</section>

<?php if (!$productRows): ?>
<section class="section container">
    <div class="panel reveal">
        <p>No flowers found for this filter yet.</p>
    </div>
</section>
<?php elseif ($showGrouped): ?>
    <?php foreach ($groupedProducts as $groupName => $items): ?>
        <section class="section container shop-category-block" id="cat-<?= e(shop_slug($groupName)) ?>">
            <h2 class="shop-category-title reveal"><?= e($groupName) ?></h2>
            <div class="grid">
                <?php foreach ($items as $row): ?>
                    <article class="card">
                        <img src="<?= e((string) $row['image_url']) ?>" alt="<?= e((string) $row['name']) ?>">
                        <div class="card-body">
                            <div class="meta-row">
                                <span class="flower-chip"><?= e((string) $row['category']) ?></span>
                                <span class="rating-stars">5.0 Premium Rating</span>
                            </div>
                            <h3><?= e((string) $row['name']) ?></h3>
                            <p><?= money((float) $row['price']) ?></p>
                            <p class="stock-pill <?= (int) $row['stock'] < 6 ? 'low' : '' ?>">
                                <?= (int) $row['stock'] > 0 ? (int) $row['stock'] . ' bouquet(s) available' : 'Out of stock' ?>
                            </p>
                            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                                <a class="btn" href="<?= BASE_URL ?>/product.php?id=<?= (int) $row['id'] ?>">Bouquet Details</a>
                                <button type="button" class="btn btn-gold" data-quick data-name="<?= e((string) $row['name']) ?>" data-price="<?= money((float) $row['price']) ?>" data-desc="<?= e((string) $row['description']) ?>">Quick View</button>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
<?php else: ?>
<section class="section container">
    <div class="grid">
        <?php foreach ($productRows as $row): ?>
            <article class="card">
                <img src="<?= e((string) $row['image_url']) ?>" alt="<?= e((string) $row['name']) ?>">
                <div class="card-body">
                    <div class="meta-row">
                        <span class="flower-chip"><?= e((string) $row['category']) ?></span>
                        <span class="rating-stars">5.0 Premium Rating</span>
                    </div>
                    <h3><?= e((string) $row['name']) ?></h3>
                    <p><?= money((float) $row['price']) ?></p>
                    <p class="stock-pill <?= (int) $row['stock'] < 6 ? 'low' : '' ?>">
                        <?= (int) $row['stock'] > 0 ? (int) $row['stock'] . ' bouquet(s) available' : 'Out of stock' ?>
                    </p>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <a class="btn" href="<?= BASE_URL ?>/product.php?id=<?= (int) $row['id'] ?>">Bouquet Details</a>
                        <button type="button" class="btn btn-gold" data-quick data-name="<?= e((string) $row['name']) ?>" data-price="<?= money((float) $row['price']) ?>" data-desc="<?= e((string) $row['description']) ?>">Quick View</button>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<section class="section container">
    <div class="concierge-panel reveal">
        <div>
            <span class="hero-kicker">VIP Flower Desk</span>
            <h2>Need Bulk or Event Orders?</h2>
            <p>Get premium volume pricing and a dedicated stylist for weddings, proposals, and company gifting.</p>
        </div>
        <div>
            <a class="btn btn-gold" href="<?= BASE_URL ?>/checkout.php">Book Concierge Call</a>
        </div>
    </div>
</section>

<div class="modal" id="quickModal">
    <div class="modal-content">
        <h3 data-name></h3>
        <p data-price></p>
        <p data-desc></p>
        <button class="btn" data-close>Close</button>
    </div>
</div>

<?php
$stmt->close();
require_once __DIR__ . '/includes/footer.php';
?>
