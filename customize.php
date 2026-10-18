<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/helpers.php';

$basePrice = defined('CUSTOM_BOUQUET_BASE_PRICE') ? (float) CUSTOM_BOUQUET_BASE_PRICE : 1499.00;
$error = '';
$sizeOptions = [
    'petite' => ['label' => 'Petite', 'extra' => 0.00],
    'signature' => ['label' => 'Signature', 'extra' => 250.00],
    'grand' => ['label' => 'Grand', 'extra' => 500.00],
];
$styleOptions = [
    'classic_round' => 'Classic Round',
    'garden_wrap' => 'Garden Wrap',
    'cascading' => 'Cascading',
];
$themeOptions = [
    'blush_pink' => 'Blush Pink',
    'ivory_gold' => 'Ivory Gold',
    'sunset_warm' => 'Sunset Warm',
    'lavender_dream' => 'Lavender Dream',
];
$priorityOptions = [
    'regular' => ['label' => 'Regular Prep', 'extra' => 0.00],
    'rush' => ['label' => 'Rush Prep', 'extra' => 150.00],
];
$formState = [
    'bouquet_size' => 'signature',
    'bouquet_style' => 'classic_round',
    'color_theme' => 'blush_pink',
    'prep_priority' => 'regular',
    'photo_reference_url' => '',
];

$optionsByType = [
    'flower' => [],
    'wrap' => [],
    'addon' => [],
];

$optionsResult = db()->query("SELECT id, type, name, extra_price, image_url FROM custom_bouquet_options WHERE is_active = 1 ORDER BY type ASC, sort_order ASC, name ASC");
if ($optionsResult === false) {
    $error = 'Customize options are not ready yet. Please run the latest database schema update.';
} else {
    while ($row = $optionsResult->fetch_assoc()) {
        $type = (string) $row['type'];
        if (!isset($optionsByType[$type])) {
            continue;
        }
        $optionsByType[$type][] = [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'extra_price' => (float) $row['extra_price'],
            'image_url' => (string) ($row['image_url'] ?? ''),
        ];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    if (!is_logged_in()) {
        $redirect = (string) ($_SERVER['REQUEST_URI'] ?? (BASE_URL . '/customize.php'));
        header('Location: ' . BASE_URL . '/login.php?redirect=' . urlencode($redirect));
        exit;
    }
    $activeUser = current_user();
    if (($activeUser['role'] ?? '') === 'admin') {
        $_SESSION['admin_flash'] = ['message' => 'Admin accounts cannot place customer orders.', 'type' => 'error'];
        header('Location: ' . BASE_URL . '/admin/index.php');
        exit;
    }

    $flowerId = (int) ($_POST['flower_option_id'] ?? 0);
    $wrapId = (int) ($_POST['wrap_option_id'] ?? 0);
    $quantity = max(1, min(20, (int) ($_POST['quantity'] ?? 1)));
    $messageNote = trim($_POST['message_note'] ?? '');
    $formState['bouquet_size'] = trim((string) ($_POST['bouquet_size'] ?? 'signature'));
    $formState['bouquet_style'] = trim((string) ($_POST['bouquet_style'] ?? 'classic_round'));
    $formState['color_theme'] = trim((string) ($_POST['color_theme'] ?? 'blush_pink'));
    $formState['prep_priority'] = trim((string) ($_POST['prep_priority'] ?? 'regular'));
    $formState['photo_reference_url'] = trim((string) ($_POST['photo_reference_url'] ?? ''));
    $selectedAddonIds = array_unique(array_map('intval', $_POST['addons'] ?? []));

    $flowersById = [];
    foreach ($optionsByType['flower'] as $item) {
        $flowersById[$item['id']] = $item;
    }
    $wrapsById = [];
    foreach ($optionsByType['wrap'] as $item) {
        $wrapsById[$item['id']] = $item;
    }
    $addonsById = [];
    foreach ($optionsByType['addon'] as $item) {
        $addonsById[$item['id']] = $item;
    }

    $flower = $flowersById[$flowerId] ?? null;
    $wrap = $wrapsById[$wrapId] ?? null;
    if (!isset($sizeOptions[$formState['bouquet_size']])) {
        $formState['bouquet_size'] = 'signature';
    }
    if (!isset($styleOptions[$formState['bouquet_style']])) {
        $formState['bouquet_style'] = 'classic_round';
    }
    if (!isset($themeOptions[$formState['color_theme']])) {
        $formState['color_theme'] = 'blush_pink';
    }
    if (!isset($priorityOptions[$formState['prep_priority']])) {
        $formState['prep_priority'] = 'regular';
    }
    if ($formState['photo_reference_url'] !== '' && !filter_var($formState['photo_reference_url'], FILTER_VALIDATE_URL)) {
        $error = 'Photo reference URL is invalid. Please enter a full https:// link.';
    } elseif (!$flower || !$wrap) {
        $error = 'Please select a flower and wrap option.';
    } else {
        $addons = [];
        $addonsTotal = 0.0;
        foreach ($selectedAddonIds as $addonId) {
            if (!isset($addonsById[$addonId])) {
                continue;
            }
            $addon = $addonsById[$addonId];
            $addons[] = [
                'id' => (int) $addon['id'],
                'name' => (string) $addon['name'],
                'price' => (float) $addon['extra_price'],
            ];
            $addonsTotal += (float) $addon['extra_price'];
        }

        $sizeExtra = (float) ($sizeOptions[$formState['bouquet_size']]['extra'] ?? 0.00);
        $priorityExtra = (float) ($priorityOptions[$formState['prep_priority']]['extra'] ?? 0.00);
        $unitPrice = $basePrice + (float) $flower['extra_price'] + (float) $wrap['extra_price'] + $addonsTotal + $sizeExtra + $priorityExtra;
        if ($unitPrice <= 0) {
            $error = 'Unable to calculate custom bouquet price.';
        } else {
            $imageUrl = trim((string) $flower['image_url']);
            if ($imageUrl === '') {
                $imageUrl = 'https://images.unsplash.com/photo-1490750967868-88aa4486c946?auto=format&fit=crop&w=1200&q=80';
            }
            $sizeLabel = (string) ($sizeOptions[$formState['bouquet_size']]['label'] ?? 'Signature');
            $styleLabel = (string) ($styleOptions[$formState['bouquet_style']] ?? 'Classic Round');
            $themeLabel = (string) ($themeOptions[$formState['color_theme']] ?? 'Blush Pink');
            $priorityLabel = (string) ($priorityOptions[$formState['prep_priority']]['label'] ?? 'Regular Prep');
            $featureLines = [
                'Size: ' . $sizeLabel,
                'Style: ' . $styleLabel,
                'Theme: ' . $themeLabel,
                'Prep: ' . $priorityLabel,
            ];
            if ($formState['photo_reference_url'] !== '') {
                $featureLines[] = 'Photo Ref: ' . $formState['photo_reference_url'];
            }
            $featureText = implode(' | ', $featureLines);
            $description = 'Custom bouquet: ' . $flower['name'] . ' flowers with ' . $wrap['name'] . ' wrap. ' . $featureText;
            if ($messageNote !== '') {
                $messageNote .= PHP_EOL;
            }
            $messageNote .= '[Design] ' . $featureText;
            add_custom_to_cart([
                'name' => 'Custom Bouquet Request',
                'description' => $description,
                'image_url' => $imageUrl,
                'flower_name' => (string) $flower['name'],
                'wrap_name' => (string) $wrap['name'],
                'addons' => $addons,
                'message_note' => $messageNote,
                'flower_option_id' => (int) $flower['id'],
                'wrap_option_id' => (int) $wrap['id'],
                'unit_price' => $unitPrice,
            ], $quantity);

            header('Location: ' . BASE_URL . '/cart.php');
            exit;
        }
    }
}

$pageTitle = 'Customize Bouquet';
$pageUser = current_user();
require_once __DIR__ . '/includes/header.php';
?>
<section class="section container">
    <h1 class="section-title reveal">Customize Bouquet Request</h1>
    <div class="concierge-panel reveal">
        <div>
            <span class="hero-kicker">Build Your Own</span>
            <h2>Create your bouquet using the satin flower set: Rose, Tulips, Plumeria, Dahlia, and Lily.</h2>
            <p>Choose your size, style, theme, prep priority, and optional add-ons in one builder.</p>
        </div>
        <div class="countdown-wrap">
            <div class="countdown-label">Base Custom Price</div>
            <div class="countdown-value"><?= money($basePrice) ?></div>
            <div class="countdown-note">Final total changes based on selected flowers, wrap style, and add-ons.</div>
        </div>
    </div>
</section>

<section class="section container">
    <?php if ($error !== ''): ?>
        <div class="panel" style="border-color:#b44343;"><?= e($error) ?></div>
    <?php elseif (!$optionsByType['flower'] || !$optionsByType['wrap']): ?>
        <div class="panel">No active flower/wrap options yet. Please ask admin to configure custom options.</div>
    <?php else: ?>
        <form method="post" class="panel customize-form">
            <h3>1. Choose Flower</h3>
            <div class="option-grid">
                <?php foreach ($optionsByType['flower'] as $idx => $option): ?>
                    <label class="option-card">
                        <input type="radio" name="flower_option_id" value="<?= (int) $option['id'] ?>" data-price="<?= e((string) $option['extra_price']) ?>" <?= $idx === 0 ? 'checked' : '' ?> required>
                        <?php if ($option['image_url'] !== ''): ?>
                            <img src="<?= e($option['image_url']) ?>" alt="<?= e($option['name']) ?>">
                        <?php endif; ?>
                        <strong><?= e($option['name']) ?></strong>
                        <span>+ <?= money((float) $option['extra_price']) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>

            <h3>2. Choose Wrap Color/Style</h3>
            <div class="option-grid">
                <?php foreach ($optionsByType['wrap'] as $idx => $option): ?>
                    <label class="option-card option-card-compact">
                        <input type="radio" name="wrap_option_id" value="<?= (int) $option['id'] ?>" data-price="<?= e((string) $option['extra_price']) ?>" <?= $idx === 0 ? 'checked' : '' ?> required>
                        <strong><?= e($option['name']) ?></strong>
                        <span>+ <?= money((float) $option['extra_price']) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>

            <h3>3. Add-ons (+charges)</h3>
            <div class="option-grid">
                <?php foreach ($optionsByType['addon'] as $option): ?>
                    <label class="option-card option-card-compact">
                        <input type="checkbox" name="addons[]" value="<?= (int) $option['id'] ?>" data-addon-price="<?= e((string) $option['extra_price']) ?>">
                        <strong><?= e($option['name']) ?></strong>
                        <span>+ <?= money((float) $option['extra_price']) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>

            <h3>4. Bouquet Features</h3>
            <div class="option-grid">
                <label class="option-card option-card-compact">
                    <strong>Bouquet Size</strong>
                    <select name="bouquet_size" id="bouquetSizeSelect">
                        <?php foreach ($sizeOptions as $key => $size): ?>
                            <option value="<?= e($key) ?>" data-extra="<?= e((string) $size['extra']) ?>" <?= $formState['bouquet_size'] === $key ? 'selected' : '' ?>>
                                <?= e((string) $size['label']) ?> (+<?= money((float) $size['extra']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="option-card option-card-compact">
                    <strong>Bouquet Style</strong>
                    <select name="bouquet_style" id="bouquetStyleSelect">
                        <?php foreach ($styleOptions as $key => $label): ?>
                            <option value="<?= e($key) ?>" <?= $formState['bouquet_style'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="option-card option-card-compact">
                    <strong>Color Theme</strong>
                    <select name="color_theme" id="colorThemeSelect">
                        <?php foreach ($themeOptions as $key => $label): ?>
                            <option value="<?= e($key) ?>" <?= $formState['color_theme'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="option-card option-card-compact">
                    <strong>Prep Priority</strong>
                    <select name="prep_priority" id="prepPrioritySelect">
                        <?php foreach ($priorityOptions as $key => $priority): ?>
                            <option value="<?= e($key) ?>" data-extra="<?= e((string) $priority['extra']) ?>" <?= $formState['prep_priority'] === $key ? 'selected' : '' ?>>
                                <?= e((string) $priority['label']) ?> (+<?= money((float) $priority['extra']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>

            <label>Photo Reference URL (Optional)</label>
            <input type="url" name="photo_reference_url" value="<?= e($formState['photo_reference_url']) ?>" placeholder="https://example.com/my-style-reference.jpg">

            <label>Message Card Note (Optional)</label>
            <textarea name="message_note" rows="3" placeholder="Write your dedication or special request..."></textarea>

            <label>Quantity</label>
            <input type="number" name="quantity" value="1" min="1" max="20">

            <div class="panel" style="margin-top:12px;">
                <p>Base price: <strong id="customBasePrice"><?= money($basePrice) ?></strong></p>
                <p>Options total: <strong id="customOptionsTotal"><?= money(0) ?></strong></p>
                <p>Feature extras: <strong id="customFeatureTotal"><?= money(0) ?></strong></p>
                <p>Estimated unit price: <strong id="customUnitPrice"><?= money($basePrice) ?></strong></p>
                <p id="customSummaryLine" class="luxury-note">Size, style, and theme summary will appear here.</p>
            </div>

            <?php if (!is_logged_in()): ?>
                <a class="btn btn-gold" href="<?= BASE_URL ?>/login.php?redirect=<?= urlencode(BASE_URL . '/customize.php') ?>">Login to Add to Cart</a>
            <?php elseif (($pageUser['role'] ?? '') === 'admin'): ?>
                <button class="btn" type="button" disabled>Admin Accounts Cannot Order</button>
            <?php else: ?>
                <button class="btn btn-gold">Add Custom Bouquet to Cart</button>
            <?php endif; ?>
        </form>
    <?php endif; ?>
</section>

<script>
(() => {
  const basePrice = <?= json_encode($basePrice) ?>;
  const wrap = document.querySelectorAll('input[name="wrap_option_id"]');
  const flowers = document.querySelectorAll('input[name="flower_option_id"]');
  const addons = document.querySelectorAll('input[name="addons[]"]');
  const sizeSelect = document.getElementById('bouquetSizeSelect');
  const styleSelect = document.getElementById('bouquetStyleSelect');
  const themeSelect = document.getElementById('colorThemeSelect');
  const prioritySelect = document.getElementById('prepPrioritySelect');
  const optionsTotalNode = document.getElementById('customOptionsTotal');
  const featureTotalNode = document.getElementById('customFeatureTotal');
  const unitPriceNode = document.getElementById('customUnitPrice');
  const summaryLineNode = document.getElementById('customSummaryLine');
  const formatPHP = (value) => 'PHP ' + value.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  if (!optionsTotalNode || !featureTotalNode || !unitPriceNode) return;

  const activePrice = (nodes) => {
    let price = 0;
    nodes.forEach((node) => {
      if (!node.checked) return;
      price += Number(node.getAttribute('data-price') || 0);
    });
    return price;
  };

  const addonsPrice = () => {
    let price = 0;
    addons.forEach((node) => {
      if (!node.checked) return;
      price += Number(node.getAttribute('data-addon-price') || 0);
    });
    return price;
  };

  const update = () => {
    const optionTotal = activePrice(flowers) + activePrice(wrap) + addonsPrice();
    const sizeExtra = Number(sizeSelect?.selectedOptions?.[0]?.getAttribute('data-extra') || 0);
    const priorityExtra = Number(prioritySelect?.selectedOptions?.[0]?.getAttribute('data-extra') || 0);
    const featureTotal = sizeExtra + priorityExtra;
    optionsTotalNode.textContent = formatPHP(optionTotal);
    featureTotalNode.textContent = formatPHP(featureTotal);
    unitPriceNode.textContent = formatPHP(basePrice + optionTotal + featureTotal);
    if (summaryLineNode) {
      const sizeLabel = sizeSelect?.selectedOptions?.[0]?.textContent?.trim() || 'Signature';
      const styleLabel = styleSelect?.selectedOptions?.[0]?.textContent?.trim() || 'Classic Round';
      const themeLabel = themeSelect?.selectedOptions?.[0]?.textContent?.trim() || 'Blush Pink';
      summaryLineNode.textContent = `Summary: ${sizeLabel} | ${styleLabel} | ${themeLabel}`;
    }
  };

  const markSelectedCards = () => {
    document.querySelectorAll('.option-card').forEach((card) => card.classList.remove('selected'));
    document.querySelectorAll('.option-card input').forEach((input) => {
      if (!input.checked) return;
      const parent = input.closest('.option-card');
      if (parent) parent.classList.add('selected');
    });
  };

  [...wrap, ...flowers, ...addons, sizeSelect, styleSelect, themeSelect, prioritySelect].forEach((node) => {
    if (!node) return;
    node.addEventListener('change', () => {
      update();
      markSelectedCards();
    });
  });
  update();
  markSelectedCards();
})();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
