<?php
declare(strict_types=1);
$pageTitle = 'Home';
require_once __DIR__ . '/includes/header.php';

$products = db()->query('SELECT id, name, price, image_url, description, category, stock FROM products ORDER BY created_at DESC LIMIT 8');
$feedbackReady = ensure_feedback_schema();
$testimonials = [];
if ($feedbackReady) {
    $testimonialResult = db()->query('SELECT f.rating, f.review_title, f.review_text, f.created_at, u.full_name, p.name AS product_name FROM product_feedback f JOIN users u ON u.id = f.user_id JOIN products p ON p.id = f.product_id WHERE f.is_testimonial = 1 ORDER BY f.created_at DESC LIMIT 8');
    if ($testimonialResult instanceof mysqli_result) {
        $testimonials = $testimonialResult->fetch_all(MYSQLI_ASSOC);
    }
}

$productRows = [];
$collectionMap = ['all' => 'All Flowers'];
$toSlug = static function (string $value): string {
    $slug = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $value));
    $slug = trim($slug, '-');
    return $slug !== '' ? $slug : 'all';
};

while ($row = $products->fetch_assoc()) {
    $productRows[] = $row;
    $slug = $toSlug($row['category']);
    if (!isset($collectionMap[$slug])) {
        $collectionMap[$slug] = $row['category'];
    }
}

$showcaseSlides = [];
foreach ($productRows as $row) {
    $imageUrl = trim((string) ($row['image_url'] ?? ''));
    if ($imageUrl !== '') {
        $showcaseSlides[] = $imageUrl;
    }
    if (count($showcaseSlides) >= 3) {
        break;
    }
}

$fallbackShowcaseSlides = [
    'https://images.unsplash.com/photo-1490750967868-88aa4486c946?auto=format&fit=crop&w=1800&q=80',
    'https://images.unsplash.com/photo-1526047932273-341f2a7631f9?auto=format&fit=crop&w=1800&q=80',
    'https://images.unsplash.com/photo-1508615070457-7baeba4003ab?auto=format&fit=crop&w=1800&q=80',
];

foreach ($fallbackShowcaseSlides as $fallbackSlide) {
    if (count($showcaseSlides) >= 3) {
        break;
    }
    $showcaseSlides[] = $fallbackSlide;
}

while (count($showcaseSlides) < 3) {
    $showcaseSlides[] = end($showcaseSlides);
}
?>
<section class="hero">
    <div class="hero-slideshow" aria-hidden="true">
        <?php foreach ($showcaseSlides as $index => $slide): ?>
            <span
                class="hero-slide"
                style="background-image: url('<?= e($slide) ?>'); animation-delay: <?= (int) ($index * 5) ?>s;"
            ></span>
        <?php endforeach; ?>
    </div>
    <div class="hero-content reveal">
        <span class="hero-kicker">Handcrafted Satin Blooms</span>
        <h1>Premium Satin Flower Bouquets, Wrapped in Elegance</h1>
        <p>Discover luxury handmade bouquets for birthdays, anniversaries, bridal events, and heartfelt gifting.</p>
        <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
            <a class="btn btn-gold" href="<?= BASE_URL ?>/shop.php">Shop Bouquets</a>
            <a class="btn" href="<?= BASE_URL ?>/customize.php">Customize Bouquet</a>
        </div>
    </div>
</section>

<section class="container premium-strip reveal">
    <article class="premium-metric">
        <strong>15,000+</strong>
        <span>Bouquets Delivered</span>
    </article>
    <article class="premium-metric">
        <strong>4.9/5</strong>
        <span>Client Satisfaction</span>
    </article>
    <article class="premium-metric">
        <strong>24/7</strong>
        <span>Styling Concierge</span>
    </article>
    <article class="premium-metric">
        <strong>Same-Day</strong>
        <span>Metro Delivery</span>
    </article>
</section>

<section class="section container">
    <div class="concierge-panel reveal">
        <div>
            <span class="hero-kicker">Luxury Concierge</span>
            <h2>Order Before Cutoff for Priority Flower Styling Today</h2>
            <p>Reserve your flowers with premium wrapping, curated scent card, and dedicated handling for events and gifting.</p>
        </div>
        <div class="countdown-wrap" data-countdown data-cutoff-hour="18">
            <div class="countdown-label">Same-day order cutoff</div>
            <div class="countdown-value" data-countdown-value>00:00:00</div>
            <div class="countdown-note">Time remaining to qualify for same-day dispatch.</div>
        </div>
    </div>
</section>

<section class="section container">
    <h2 class="section-title reveal">Signature Highlights</h2>
    <div class="flower-video reveal">
        <div class="flower-showcase" aria-hidden="true">
            <?php foreach ($showcaseSlides as $index => $slide): ?>
                <img
                    class="flower-showcase-slide"
                    src="<?= e($slide) ?>"
                    alt=""
                    loading="<?= $index === 0 ? 'eager' : 'lazy' ?>"
                    decoding="async"
                    style="animation-delay: <?= (int) ($index * 5) ?>s;"
                >
            <?php endforeach; ?>
        </div>
        <p>Signature flower showcase: handcrafted satin petals, premium wrapping, and curated color stories.</p>
    </div>
    <div class="grid">
        <?php foreach (array_slice($productRows, 0, 4) as $row): ?>
            <article class="card">
                <img src="<?= e($row['image_url']) ?>" alt="<?= e($row['name']) ?>">
                <div class="card-body">
                    <span class="flower-chip">Flower Highlight</span>
                    <h3><?= e($row['name']) ?></h3>
                    <p><?= e($row['description']) ?></p>
                    <p><?= money((float) $row['price']) ?></p>
                    <p class="luxury-note"><?= e($row['category']) ?> | <?= (int) $row['stock'] > 0 ? 'Ready for priority handling' : 'Pre-order available' ?></p>
                    <a class="btn" href="<?= BASE_URL ?>/product.php?id=<?= (int) $row['id'] ?>">View Flowers</a>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>

<section class="section container">
    <div class="promo reveal">
        <h2>Premium Gift Wrap Upgrade</h2>
        <p>Get complimentary message card + signature ribbon styling on orders above PHP 3,500.</p>
    </div>
</section>

<section class="section container">
    <h2 class="section-title reveal">Curated Collections</h2>
    <div class="collection-tabs reveal">
        <?php foreach ($collectionMap as $slug => $label): ?>
            <button class="tab-btn <?= $slug === 'all' ? 'active' : '' ?>" type="button" data-filter="<?= e($slug) ?>"><?= e($label) ?></button>
        <?php endforeach; ?>
    </div>
    <div class="grid collection-grid">
        <?php foreach ($productRows as $row): ?>
            <?php $slug = $toSlug($row['category']); ?>
            <article class="card collection-card" data-collection="<?= e($slug) ?>">
                <img src="<?= e($row['image_url']) ?>" alt="<?= e($row['name']) ?>">
                <div class="card-body">
                    <span class="flower-chip"><?= e($row['category']) ?></span>
                    <h3><?= e($row['name']) ?></h3>
                    <p><?= money((float) $row['price']) ?></p>
                    <a class="btn" href="<?= BASE_URL ?>/product.php?id=<?= (int) $row['id'] ?>">Details</a>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>

<section class="section container">
    <h2 class="section-title reveal">More Flower Features</h2>
    <div class="feature-grid">
        <article class="feature-item reveal">
            <h3>Same-Day Styling</h3>
            <p>Rush-ready bouquets prepared within hours for urgent surprises and events.</p>
        </article>
        <article class="feature-item reveal">
            <h3>Custom Flower Theme</h3>
            <p>Choose mood palettes from romantic, minimalist, bridal, or grand celebration styles.</p>
        </article>
        <article class="feature-item reveal">
            <h3>Premium Gift Add-ons</h3>
            <p>Add greeting cards, keepsake boxes, mini lights, and satin ribbon upgrades.</p>
        </article>
        <article class="feature-item reveal">
            <h3>Event Bundles</h3>
            <p>Bulk flower packages for weddings, debuts, and corporate gifting programs.</p>
        </article>
        <article class="feature-item reveal">
            <h3>Luxury Unboxing</h3>
            <p>Every premium order includes layered wraps, satin seal, and fragrance card presentation.</p>
        </article>
        <article class="feature-item reveal">
            <h3>VIP Corporate Gifting</h3>
            <p>Personalized branding cards, recurring schedules, and monthly premium flower drops.</p>
        </article>
    </div>
</section>

<section class="section container">
    <h2 class="section-title reveal">More Products We Sell</h2>
    <div class="feature-grid">
        <article class="feature-item reveal"><h3>Mini Flower Tokens</h3><p>Affordable petite satin blooms for giveaways and souvenirs.</p></article>
        <article class="feature-item reveal"><h3>Bridal Flower Sets</h3><p>Bride bouquet + bridesmaid bundles with matching ribbon and accents.</p></article>
        <article class="feature-item reveal"><h3>Luxury Gift Flower Box</h3><p>Premium flower boxes with chocolates, perfumes, and dedication cards.</p></article>
        <article class="feature-item reveal"><h3>Forever Flower Dome</h3><p>Display-ready flowers in acrylic dome cases for home and office decor.</p></article>
    </div>
</section>

<section class="section container">
    <h2 class="section-title reveal">Client Testimonials</h2>
    <div class="testimonial-track">
        <?php if (!$testimonials): ?>
            <article class="testimonial"><p>"The satin flowers look incredibly real and the wrapping feels very luxurious."</p><strong>- Sofia M.</strong></article>
            <article class="testimonial"><p>"Delivery was fast and the bouquet looked perfect for our anniversary dinner."</p><strong>- Camille R.</strong></article>
            <article class="testimonial"><p>"My bridal bouquet from Blossom was stunning and photographed beautifully."</p><strong>- Andrea L.</strong></article>
        <?php else: ?>
            <?php foreach ($testimonials as $testimonial): ?>
                <article class="testimonial">
                    <p>"<?= e((string) $testimonial['review_text']) ?>"</p>
                    <strong>- <?= e((string) $testimonial['full_name']) ?> | <?= e((string) $testimonial['product_name']) ?> | <?= str_repeat('★', max(1, min(5, (int) $testimonial['rating']))) ?></strong>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>

<section class="section container">
    <div class="concierge-panel reveal">
        <div>
            <span class="hero-kicker">Event Concierge</span>
            <h2>Planning a Wedding, Debut, or Corporate Event?</h2>
            <p>Our florist concierge can curate complete flower styling packages, bouquet bundles, and premium table arrangements.</p>
        </div>
        <div>
            <a class="btn btn-gold" href="<?= BASE_URL ?>/shop.php">Start Premium Inquiry</a>
        </div>
    </div>
</section>

<section class="section container reveal">
    <h2 class="section-title">Bloom Letter</h2>
    <form class="panel">
        <input type="email" placeholder="Enter your email for bouquet drops and offers">
        <button type="button" class="btn btn-gold">Join Now</button>
    </form>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
