<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/order_payment.php';
require_admin();

function flash_set(string $message, string $type = 'success'): void
{
    $_SESSION['admin_flash'] = ['message' => $message, 'type' => $type];
}

function flash_get(): ?array
{
    $flash = $_SESSION['admin_flash'] ?? null;
    unset($_SESSION['admin_flash']);
    return is_array($flash) ? $flash : null;
}

function rows(mysqli_result $result): array
{
    $all = [];
    while ($row = $result->fetch_assoc()) {
        $all[] = $row;
    }
    return $all;
}

$adminUrl = BASE_URL . '/admin/index.php';
$me = current_user();
$locationReady = ensure_order_location_schema();
$profileReady = ensure_user_profile_schema();
$supportReady = ensure_support_schema();
$maxProductPrice = 2500.00;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'add_product') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $category = trim((string) ($_POST['category'] ?? 'General'));
        $price = (float) ($_POST['price'] ?? 0);
        $stock = max(0, (int) ($_POST['stock'] ?? 0));
        $imageUrl = trim((string) ($_POST['image_url'] ?? ''));
        if ($name === '' || $description === '' || $price <= 0 || $imageUrl === '') {
            flash_set('Invalid product details.', 'error');
        } elseif ($price > $maxProductPrice) {
            flash_set('Product price must be PHP 2,500 or below.', 'error');
        } else {
            $stmt = db()->prepare('INSERT INTO products (name, description, price, category, stock, image_url) VALUES (?, ?, ?, ?, ?, ?)');
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('ssdsis', $name, $description, $price, $category, $stock, $imageUrl);
                $ok = $stmt->execute();
                $stmt->close();
                flash_set($ok ? 'Product added.' : 'Failed to add product.', $ok ? 'success' : 'error');
            }
        }
        header('Location: ' . $adminUrl);
        exit;
    }

    if ($action === 'update_product') {
        $id = (int) ($_POST['product_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $category = trim((string) ($_POST['category'] ?? 'General'));
        $price = (float) ($_POST['price'] ?? 0);
        $stock = max(0, (int) ($_POST['stock'] ?? 0));
        $imageUrl = trim((string) ($_POST['image_url'] ?? ''));
        if ($id < 1 || $name === '' || $description === '' || $price <= 0 || $imageUrl === '') {
            flash_set('Invalid product update request.', 'error');
        } elseif ($price > $maxProductPrice) {
            flash_set('Product price must be PHP 2,500 or below.', 'error');
        } else {
            $stmt = db()->prepare('UPDATE products SET name = ?, description = ?, category = ?, price = ?, stock = ?, image_url = ? WHERE id = ?');
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('sssdisi', $name, $description, $category, $price, $stock, $imageUrl, $id);
                $ok = $stmt->execute();
                $stmt->close();
                flash_set($ok ? 'Product updated.' : 'Failed to update product.', $ok ? 'success' : 'error');
            }
        }
        header('Location: ' . $adminUrl);
        exit;
    }

    if ($action === 'delete_product') {
        $id = (int) ($_POST['product_id'] ?? 0);
        $refStmt = db()->prepare('SELECT COUNT(*) AS c FROM order_items WHERE product_id = ?');
        $refs = 0;
        if ($refStmt instanceof mysqli_stmt) {
            $refStmt->bind_param('i', $id);
            $refStmt->execute();
            $refs = (int) (($refStmt->get_result()->fetch_assoc()['c'] ?? 0));
            $refStmt->close();
        }
        if ($refs > 0) {
            flash_set('Cannot delete product with existing sales records.', 'error');
        } else {
            $stmt = db()->prepare('DELETE FROM products WHERE id = ?');
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $deleted = $stmt->affected_rows > 0;
                $stmt->close();
                flash_set($deleted ? 'Product deleted.' : 'Product not found.', $deleted ? 'success' : 'error');
            }
        }
        header('Location: ' . $adminUrl);
        exit;
    }

    if ($action === 'update_order_status') {
        $orderId = (int) ($_POST['order_id'] ?? 0);
        $status = trim((string) ($_POST['status'] ?? ''));
        $allowed = ['pending', 'paid', 'shipped', 'completed', 'cancelled'];
        if ($orderId < 1 || !in_array($status, $allowed, true)) {
            flash_set('Invalid order status request.', 'error');
            header('Location: ' . $adminUrl);
            exit;
        }
        $statusStmt = db()->prepare('SELECT status FROM orders WHERE id = ? LIMIT 1');
        $current = '';
        if ($statusStmt instanceof mysqli_stmt) {
            $statusStmt->bind_param('i', $orderId);
            $statusStmt->execute();
            $current = (string) (($statusStmt->get_result()->fetch_assoc()['status'] ?? ''));
            $statusStmt->close();
        }
        if ($current === '') {
            flash_set('Order not found.', 'error');
            header('Location: ' . $adminUrl);
            exit;
        }
        if ($status === 'paid') {
            try {
                finalize_order_as_paid($orderId, 'admin-manual-' . $orderId, 'Admin Manual');
                flash_set('Order marked as paid and stock deducted.');
            } catch (Throwable $e) {
                flash_set('Cannot mark paid: ' . $e->getMessage(), 'error');
            }
            header('Location: ' . $adminUrl);
            exit;
        }
        if ($current === 'paid' && in_array($status, ['pending', 'cancelled'], true)) {
            flash_set('Cannot move paid order back to pending/cancelled.', 'error');
            header('Location: ' . $adminUrl);
            exit;
        }
        if (in_array($status, ['shipped', 'completed'], true) && !in_array($current, ['paid', 'shipped', 'completed'], true)) {
            flash_set('Set order to paid before shipped/completed.', 'error');
            header('Location: ' . $adminUrl);
            exit;
        }
        $stmt = db()->prepare('UPDATE orders SET status = ? WHERE id = ?');
        if ($stmt instanceof mysqli_stmt) {
            $stmt->bind_param('si', $status, $orderId);
            $ok = $stmt->execute();
            $stmt->close();
            flash_set($ok ? 'Order status updated.' : 'Failed to update order status.', $ok ? 'success' : 'error');
        }
        header('Location: ' . $adminUrl);
        exit;
    }

    if ($action === 'add_admin_user') {
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        if ($fullName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
            flash_set('Provide valid admin name/email/password (min 8 chars).', 'error');
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = db()->prepare('INSERT INTO users (full_name, email, password_hash, role) VALUES (?, ?, ?, \'admin\')');
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('sss', $fullName, $email, $hash);
                $ok = $stmt->execute();
                $errno = $stmt->errno;
                $stmt->close();
                if ($ok) {
                    flash_set('New admin account created.');
                } elseif ($errno === 1062) {
                    flash_set('Email already exists.', 'error');
                } else {
                    flash_set('Failed to create admin account.', 'error');
                }
            }
        }
        header('Location: ' . $adminUrl);
        exit;
    }

    if ($action === 'update_user_role') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $role = trim((string) ($_POST['role'] ?? ''));
        if ($userId < 1 || !in_array($role, ['customer', 'admin'], true)) {
            flash_set('Invalid role update request.', 'error');
            header('Location: ' . $adminUrl);
            exit;
        }
        $targetStmt = db()->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
        $oldRole = '';
        if ($targetStmt instanceof mysqli_stmt) {
            $targetStmt->bind_param('i', $userId);
            $targetStmt->execute();
            $oldRole = (string) (($targetStmt->get_result()->fetch_assoc()['role'] ?? ''));
            $targetStmt->close();
        }
        if ($oldRole === '') {
            flash_set('User not found.', 'error');
            header('Location: ' . $adminUrl);
            exit;
        }
        if ((int) ($me['id'] ?? 0) === $userId && $role !== 'admin') {
            flash_set('You cannot remove your own admin role.', 'error');
            header('Location: ' . $adminUrl);
            exit;
        }
        if ($oldRole === 'admin' && $role === 'customer') {
            $adminCount = (int) ((db()->query('SELECT COUNT(*) AS c FROM users WHERE role = \'admin\'')->fetch_assoc()['c']) ?? 0);
            if ($adminCount <= 1) {
                flash_set('Cannot demote last admin account.', 'error');
                header('Location: ' . $adminUrl);
                exit;
            }
        }
        $stmt = db()->prepare('UPDATE users SET role = ? WHERE id = ?');
        if ($stmt instanceof mysqli_stmt) {
            $stmt->bind_param('si', $role, $userId);
            $ok = $stmt->execute();
            $stmt->close();
            flash_set($ok ? 'User role updated.' : 'Failed to update role.', $ok ? 'success' : 'error');
        }
        header('Location: ' . $adminUrl);
        exit;
    }

    if ($action === 'add_custom_option' || $action === 'update_custom_option') {
        $type = trim((string) ($_POST['type'] ?? ''));
        $name = trim((string) ($_POST['name'] ?? ''));
        $extraPrice = (float) ($_POST['extra_price'] ?? 0);
        $imageUrl = trim((string) ($_POST['image_url'] ?? ''));
        $sortOrder = (int) ($_POST['sort_order'] ?? 0);
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        if ($action === 'add_custom_option') {
            if (in_array($type, ['flower', 'wrap', 'addon'], true) && $name !== '') {
                $stmt = db()->prepare('INSERT INTO custom_bouquet_options (type, name, extra_price, image_url, is_active, sort_order) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE extra_price = VALUES(extra_price), image_url = VALUES(image_url), is_active = VALUES(is_active), sort_order = VALUES(sort_order)');
                if ($stmt instanceof mysqli_stmt) {
                    $stmt->bind_param('ssdsii', $type, $name, $extraPrice, $imageUrl, $isActive, $sortOrder);
                    $ok = $stmt->execute();
                    $stmt->close();
                    flash_set($ok ? 'Custom option saved.' : 'Failed to save custom option.', $ok ? 'success' : 'error');
                }
            } else {
                flash_set('Invalid custom option data.', 'error');
            }
        } else {
            $optionId = (int) ($_POST['option_id'] ?? 0);
            if ($optionId > 0 && $name !== '') {
                $stmt = db()->prepare('UPDATE custom_bouquet_options SET name = ?, extra_price = ?, image_url = ?, sort_order = ?, is_active = ? WHERE id = ?');
                if ($stmt instanceof mysqli_stmt) {
                    $stmt->bind_param('sdsiii', $name, $extraPrice, $imageUrl, $sortOrder, $isActive, $optionId);
                    $ok = $stmt->execute();
                    $stmt->close();
                    flash_set($ok ? 'Custom option updated.' : 'Failed to update custom option.', $ok ? 'success' : 'error');
                }
            } else {
                flash_set('Invalid custom option update request.', 'error');
            }
        }
        header('Location: ' . $adminUrl);
        exit;
    }

    if ($action === 'update_support_ticket_status' && $supportReady) {
        $ticketId = (int) ($_POST['ticket_id'] ?? 0);
        $status = trim((string) ($_POST['status'] ?? 'open'));
        $priority = trim((string) ($_POST['priority'] ?? 'normal'));
        if ($ticketId < 1 || !in_array($status, ['open', 'in_progress', 'resolved', 'closed'], true) || !in_array($priority, ['low', 'normal', 'high'], true)) {
            flash_set('Invalid support ticket update request.', 'error');
            header('Location: ' . $adminUrl . '#support-tickets');
            exit;
        }
        $stmt = db()->prepare('UPDATE support_tickets SET status = ?, priority = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        if ($stmt instanceof mysqli_stmt) {
            $stmt->bind_param('ssi', $status, $priority, $ticketId);
            $ok = $stmt->execute();
            $stmt->close();
            flash_set($ok ? 'Support ticket updated.' : 'Failed to update support ticket.', $ok ? 'success' : 'error');
        }
        header('Location: ' . $adminUrl . '?support_ticket_id=' . $ticketId . '#support-tickets');
        exit;
    }

    if ($action === 'reply_support_ticket' && $supportReady) {
        $ticketId = (int) ($_POST['ticket_id'] ?? 0);
        $message = trim((string) ($_POST['message_text'] ?? ''));
        $nextStatus = trim((string) ($_POST['next_status'] ?? 'in_progress'));
        if ($ticketId < 1 || $message === '' || !in_array($nextStatus, ['open', 'in_progress', 'resolved', 'closed'], true)) {
            flash_set('Invalid support reply request.', 'error');
            header('Location: ' . $adminUrl . '#support-tickets');
            exit;
        }

        $insertStmt = db()->prepare('INSERT INTO support_ticket_messages (ticket_id, sender_user_id, sender_role, message_text) VALUES (?, ?, \'admin\', ?)');
        if ($insertStmt instanceof mysqli_stmt) {
            $adminUserId = (int) ($_SESSION['user_id'] ?? 0);
            $insertStmt->bind_param('iis', $ticketId, $adminUserId, $message);
            $ok = $insertStmt->execute();
            $insertStmt->close();
            if ($ok) {
                $now = date('Y-m-d H:i:s');
                $updateStmt = db()->prepare('UPDATE support_tickets SET status = ?, last_admin_reply_at = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
                if ($updateStmt instanceof mysqli_stmt) {
                    $updateStmt->bind_param('ssi', $nextStatus, $now, $ticketId);
                    $updateStmt->execute();
                    $updateStmt->close();
                }
                flash_set('Support reply sent.');
            } else {
                flash_set('Failed to send support reply.', 'error');
            }
        }
        header('Location: ' . $adminUrl . '?support_ticket_id=' . $ticketId . '#support-tickets');
        exit;
    }

    flash_set('Unknown admin action.', 'error');
    header('Location: ' . $adminUrl);
    exit;
}

$salesPeriod = trim((string) ($_GET['sales_period'] ?? '30d'));
$periodDays = ['7d' => 7, '30d' => 30, '90d' => 90, 'all' => 0];
if (!isset($periodDays[$salesPeriod])) {
    $salesPeriod = '30d';
}
$periodStart = null;
if ($periodDays[$salesPeriod] > 0) {
    $periodStart = (new DateTimeImmutable('now'))->modify('-' . $periodDays[$salesPeriod] . ' days')->format('Y-m-d H:i:s');
}

$inventoryQ = trim((string) ($_GET['inventory_q'] ?? ''));
$inventoryStock = trim((string) ($_GET['inventory_stock'] ?? 'all'));
if (!in_array($inventoryStock, ['all', 'in', 'low', 'out'], true)) {
    $inventoryStock = 'all';
}

$paidAtExists = false;
$paidAtResult = db()->query("SHOW COLUMNS FROM orders LIKE 'paid_at'");
if ($paidAtResult instanceof mysqli_result) {
    $paidAtExists = $paidAtResult->num_rows > 0;
}
$orderTimeExpr = $paidAtExists ? 'COALESCE(paid_at, created_at)' : 'created_at';
$orderAliasTimeExpr = $paidAtExists ? 'COALESCE(o.paid_at, o.created_at)' : 'o.created_at';

$summary = db()->query("SELECT COUNT(*) AS orders_count, SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) AS pending_orders, SUM(CASE WHEN status IN ('paid','shipped','completed') THEN 1 ELSE 0 END) AS done_orders, COALESCE(SUM(CASE WHEN status IN ('paid','shipped','completed') THEN total ELSE 0 END),0) AS revenue FROM orders")->fetch_assoc();
$inventorySummary = db()->query("SELECT COUNT(*) AS sku_count, COALESCE(SUM(stock),0) AS stock_units, SUM(CASE WHEN stock=0 THEN 1 ELSE 0 END) AS out_count, SUM(CASE WHEN stock BETWEEN 1 AND 5 THEN 1 ELSE 0 END) AS low_count FROM products")->fetch_assoc();

if ($periodStart !== null) {
    $periodStmt = db()->prepare("SELECT COUNT(*) AS orders_count, COALESCE(SUM(total),0) AS revenue FROM orders WHERE status IN ('paid','shipped','completed') AND {$orderTimeExpr} >= ?");
    $periodStmt->bind_param('s', $periodStart);
    $periodStmt->execute();
    $periodSales = $periodStmt->get_result()->fetch_assoc();
    $periodStmt->close();
} else {
    $periodSales = db()->query("SELECT COUNT(*) AS orders_count, COALESCE(SUM(total),0) AS revenue FROM orders WHERE status IN ('paid','shipped','completed')")->fetch_assoc();
}

$trendWhere = "o.status IN ('paid','shipped','completed')";
if ($periodStart !== null) {
    $trendWhere .= ' AND ' . $orderAliasTimeExpr . ' >= ?';
}
$trendSql = "SELECT DATE_FORMAT({$orderAliasTimeExpr}, '%Y-%m') AS month_key, COALESCE(SUM(o.total),0) AS revenue
             FROM orders o
             WHERE {$trendWhere}
             GROUP BY DATE_FORMAT({$orderAliasTimeExpr}, '%Y-%m')
             ORDER BY month_key ASC
             LIMIT 12";
$trendStmt = db()->prepare($trendSql);
$revenueTrend = [];
if ($trendStmt instanceof mysqli_stmt) {
    if ($periodStart !== null) {
        $trendStmt->bind_param('s', $periodStart);
    }
    $trendStmt->execute();
    $revenueTrend = rows($trendStmt->get_result());
    $trendStmt->close();
}
$trendMaxRevenue = 0.0;
foreach ($revenueTrend as $row) {
    $trendMaxRevenue = max($trendMaxRevenue, (float) ($row['revenue'] ?? 0));
}

$where = ['1=1'];
$types = '';
$params = [];
if ($inventoryQ !== '') {
    $where[] = '(name LIKE ? OR description LIKE ? OR category LIKE ?)';
    $types .= 'sss';
    $like = '%' . $inventoryQ . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
if ($inventoryStock === 'in') {
    $where[] = 'stock > 5';
} elseif ($inventoryStock === 'low') {
    $where[] = 'stock BETWEEN 1 AND 5';
} elseif ($inventoryStock === 'out') {
    $where[] = 'stock = 0';
}
$inventorySql = 'SELECT id, name, description, category, price, stock, image_url FROM products WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC';
$inventoryStmt = db()->prepare($inventorySql);
$inventory = [];
if ($inventoryStmt instanceof mysqli_stmt) {
    if ($types !== '') {
        $inventoryStmt->bind_param($types, ...$params);
    }
    $inventoryStmt->execute();
    $inventory = rows($inventoryStmt->get_result());
    $inventoryStmt->close();
}

$topSql = "SELECT p.id, p.name, p.category, p.stock, COALESCE(SUM(CASE WHEN o.id IS NOT NULL THEN oi.quantity ELSE 0 END),0) AS units_sold, COALESCE(SUM(CASE WHEN o.id IS NOT NULL THEN oi.quantity * oi.unit_price ELSE 0 END),0) AS sales_amount FROM products p LEFT JOIN order_items oi ON oi.product_id = p.id LEFT JOIN orders o ON o.id = oi.order_id AND o.status IN ('paid','shipped','completed')";
if ($periodStart !== null) {
    $topSql .= ' AND ' . $orderAliasTimeExpr . ' >= ?';
}
$topSql .= ' GROUP BY p.id, p.name, p.category, p.stock ORDER BY sales_amount DESC, units_sold DESC, p.name ASC LIMIT 15';
$topStmt = db()->prepare($topSql);
$topProducts = [];
if ($topStmt instanceof mysqli_stmt) {
    if ($periodStart !== null) {
        $topStmt->bind_param('s', $periodStart);
    }
    $topStmt->execute();
    $topProducts = rows($topStmt->get_result());
    $topStmt->close();
}

$orders = rows(db()->query('SELECT o.id, o.total, o.status, o.created_at, o.payment_method, o.shipping_address, o.shipping_landmark, o.shipping_map_url, o.shipping_latitude, o.shipping_longitude, o.shipping_pin_full_address, o.shipping_pin_street, o.shipping_pin_barangay, o.shipping_pin_city, o.shipping_pin_province, o.shipping_pin_postcode, u.full_name FROM orders o JOIN users u ON u.id=o.user_id ORDER BY o.created_at DESC LIMIT 25'));
$users = rows(db()->query('SELECT id, full_name, username, email, phone, city, province, role, created_at FROM users ORDER BY created_at DESC LIMIT 50'));
$customOptionsResult = db()->query('SELECT id, type, name, extra_price, image_url, is_active, sort_order FROM custom_bouquet_options ORDER BY type ASC, sort_order ASC, name ASC');
$customOptions = $customOptionsResult ? rows($customOptionsResult) : [];
$supportTickets = [];
$selectedSupportTicket = null;
$selectedSupportMessages = [];
if ($supportReady) {
    $supportTicketsSql = "SELECT t.id, t.subject, t.status, t.priority, t.created_at, t.updated_at, t.last_admin_reply_at,
                                 u.full_name, u.email
                          FROM support_tickets t
                          JOIN users u ON u.id = t.user_id
                          ORDER BY t.updated_at DESC, t.id DESC
                          LIMIT 100";
    $supportTicketsResult = db()->query($supportTicketsSql);
    if ($supportTicketsResult instanceof mysqli_result) {
        $supportTickets = rows($supportTicketsResult);
    }

    $selectedSupportTicketId = (int) ($_GET['support_ticket_id'] ?? 0);
    if ($selectedSupportTicketId < 1 && $supportTickets) {
        $selectedSupportTicketId = (int) ($supportTickets[0]['id'] ?? 0);
    }
    if ($selectedSupportTicketId > 0) {
        $ticketStmt = db()->prepare("SELECT t.id, t.user_id, t.subject, t.status, t.priority, t.created_at, t.updated_at, t.last_admin_reply_at,
                                            u.full_name, u.email
                                     FROM support_tickets t
                                     JOIN users u ON u.id = t.user_id
                                     WHERE t.id = ? LIMIT 1");
        if ($ticketStmt instanceof mysqli_stmt) {
            $ticketStmt->bind_param('i', $selectedSupportTicketId);
            $ticketStmt->execute();
            $selectedSupportTicket = $ticketStmt->get_result()->fetch_assoc();
            $ticketStmt->close();
        }
        if ($selectedSupportTicket) {
            $msgStmt = db()->prepare('SELECT sender_role, message_text, created_at FROM support_ticket_messages WHERE ticket_id = ? ORDER BY created_at ASC, id ASC');
            if ($msgStmt instanceof mysqli_stmt) {
                $msgStmt->bind_param('i', $selectedSupportTicketId);
                $msgStmt->execute();
                $selectedSupportMessages = $msgStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $msgStmt->close();
            }
        }
    }
}
$flash = flash_get();

$pageTitle = 'Admin Panel';
require_once __DIR__ . '/../includes/header.php';
?>
<section class="section container">
    <h1 class="section-title reveal">Admin Dashboard</h1>
    <p><a class="btn btn-gold" href="<?= BASE_URL ?>/admin/logistics.php">Open Logistics Tracking Center</a></p>
    <?php if ($flash): ?>
        <div class="panel" style="margin-bottom:12px;border-color:<?= $flash['type'] === 'error' ? '#b44343' : '#3f8d4f' ?>;"><?= e((string) $flash['message']) ?></div>
    <?php endif; ?>
    <div class="grid admin-profile-grid">
        <div class="panel reveal admin-profile-card">
            <h3>Admin Profile</h3>
            <p><strong>Name:</strong> <?= e((string) ($me['full_name'] ?? 'Administrator')) ?></p>
            <p><strong>Email:</strong> <?= e((string) ($me['email'] ?? '')) ?></p>
            <?php if (trim((string) ($me['phone'] ?? '')) !== ''): ?><p><strong>Mobile:</strong> <?= e(format_ph_mobile((string) ($me['phone'] ?? ''))) ?></p><?php endif; ?>
            <?php if (trim((string) ($me['username'] ?? '')) !== ''): ?><p><strong>Username:</strong> @<?= e((string) ($me['username'] ?? '')) ?></p><?php endif; ?>
            <?php if (trim((string) ($me['created_at'] ?? '')) !== ''): ?><p><strong>Member Since:</strong> <?= e(date('M d, Y', strtotime((string) $me['created_at']) ?: time())) ?></p><?php endif; ?>
        </div>
        <div class="panel reveal admin-profile-card">
            <h3>Operations Snapshot</h3>
            <p>Review suspicious orders faster using verified map pin details and customer profile info.</p>
            <p><strong>Low Stock Items:</strong> <?= (int) ($inventorySummary['low_count'] ?? 0) ?></p>
            <p><strong>Out of Stock Items:</strong> <?= (int) ($inventorySummary['out_count'] ?? 0) ?></p>
            <p><strong>Revenue (selected period):</strong> <?= money((float) ($periodSales['revenue'] ?? 0)) ?></p>
        </div>
    </div>
    <div class="grid">
        <div class="panel"><h3>All Orders</h3><p><?= (int) ($summary['orders_count'] ?? 0) ?></p></div>
        <div class="panel"><h3>Pending Orders</h3><p><?= (int) ($summary['pending_orders'] ?? 0) ?></p></div>
        <div class="panel"><h3>Paid/Shipped/Done</h3><p><?= (int) ($summary['done_orders'] ?? 0) ?></p></div>
        <div class="panel"><h3>All-Time Revenue</h3><p><?= money((float) ($summary['revenue'] ?? 0)) ?></p></div>
        <div class="panel"><h3>Total SKUs</h3><p><?= (int) ($inventorySummary['sku_count'] ?? 0) ?></p></div>
        <div class="panel"><h3>Units in Stock</h3><p><?= (int) ($inventorySummary['stock_units'] ?? 0) ?></p></div>
    </div>
</section>

<section class="section container">
    <div class="panel reveal">
        <form method="get" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;align-items:end;">
            <div>
                <label>Sales Period</label>
                <select name="sales_period">
                    <option value="7d" <?= $salesPeriod === '7d' ? 'selected' : '' ?>>Last 7 days</option>
                    <option value="30d" <?= $salesPeriod === '30d' ? 'selected' : '' ?>>Last 30 days</option>
                    <option value="90d" <?= $salesPeriod === '90d' ? 'selected' : '' ?>>Last 90 days</option>
                    <option value="all" <?= $salesPeriod === 'all' ? 'selected' : '' ?>>All time</option>
                </select>
            </div>
            <div><label>Inventory Search</label><input name="inventory_q" value="<?= e($inventoryQ) ?>" placeholder="Name/category/keyword"></div>
            <div>
                <label>Stock Filter</label>
                <select name="inventory_stock">
                    <option value="all" <?= $inventoryStock === 'all' ? 'selected' : '' ?>>All</option>
                    <option value="in" <?= $inventoryStock === 'in' ? 'selected' : '' ?>>In stock (&gt;5)</option>
                    <option value="low" <?= $inventoryStock === 'low' ? 'selected' : '' ?>>Low stock (1-5)</option>
                    <option value="out" <?= $inventoryStock === 'out' ? 'selected' : '' ?>>Out of stock</option>
                </select>
            </div>
            <div><button class="btn btn-gold">Apply Filters</button></div>
        </form>
        <p style="margin-top:10px;"><strong>Period Sales:</strong> <?= (int) ($periodSales['orders_count'] ?? 0) ?> order(s) | <?= money((float) ($periodSales['revenue'] ?? 0)) ?></p>
        <p><strong>Inventory Health:</strong> <?= (int) ($inventorySummary['low_count'] ?? 0) ?> low stock, <?= (int) ($inventorySummary['out_count'] ?? 0) ?> out of stock</p>
    </div>
</section>

<section class="section container">
    <div class="panel reveal">
        <h3>Revenue Graph</h3>
        <?php if (!$revenueTrend): ?>
            <p>No paid revenue data available for this period.</p>
        <?php else: ?>
            <div class="revenue-chart">
                <?php foreach ($revenueTrend as $point): ?>
                    <?php
                    $monthKey = (string) ($point['month_key'] ?? '');
                    $monthLabel = $monthKey !== '' ? date('M y', strtotime($monthKey . '-01') ?: time()) : '-';
                    $revenue = (float) ($point['revenue'] ?? 0);
                    $height = $trendMaxRevenue > 0 ? max(10, (int) round(($revenue / $trendMaxRevenue) * 170)) : 10;
                    ?>
                    <div class="revenue-bar-item" title="<?= e($monthLabel) ?>: <?= e(money($revenue)) ?>">
                        <div class="revenue-bar" style="height:<?= $height ?>px;"></div>
                        <span class="revenue-month"><?= e($monthLabel) ?></span>
                        <span class="revenue-value"><?= e(money($revenue)) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<section class="section container">
    <div class="panel reveal">
        <h3>Order Management</h3>
        <?php if (!$locationReady): ?>
            <p style="color:#b30000;">Order location columns are not ready yet. Run <code>database/order_location_migration.sql</code>.</p>
        <?php endif; ?>
        <table>
            <thead><tr><th>Order Number</th><th>Customer</th><th>Date</th><th>Shipping Details</th><th>Payment</th><th>Total</th><th>Status</th><th>Update</th></tr></thead>
            <tbody>
            <?php foreach ($orders as $order): ?>
                <tr>
                    <td><?= e(order_display_number((int) $order['id'])) ?></td>
                    <td><?= e((string) $order['full_name']) ?></td>
                    <td><?= e((string) $order['created_at']) ?></td>
                    <td>
                        <strong>Address:</strong> <?= nl2br(e((string) $order['shipping_address'])) ?><br>
                        <?php if (trim((string) ($order['shipping_landmark'] ?? '')) !== ''): ?>
                            <strong>Landmark:</strong> <?= e((string) $order['shipping_landmark']) ?><br>
                        <?php endif; ?>
                        <?php if (!empty($order['shipping_latitude']) && !empty($order['shipping_longitude'])): ?>
                            <strong>Pin:</strong> <?= e((string) $order['shipping_latitude']) ?>, <?= e((string) $order['shipping_longitude']) ?><br>
                        <?php endif; ?>
                        <?php if (trim((string) ($order['shipping_pin_full_address'] ?? '')) !== ''): ?>
                            <strong>Verified Pin Address:</strong> <?= e((string) $order['shipping_pin_full_address']) ?><br>
                        <?php endif; ?>
                        <?php
                        $pinMeta = array_filter([
                            trim((string) ($order['shipping_pin_street'] ?? '')),
                            trim((string) ($order['shipping_pin_barangay'] ?? '')),
                            trim((string) ($order['shipping_pin_city'] ?? '')),
                            trim((string) ($order['shipping_pin_province'] ?? '')),
                            trim((string) ($order['shipping_pin_postcode'] ?? '')),
                        ]);
                        ?>
                        <?php if ($pinMeta): ?>
                            <strong>Pin Components:</strong> <?= e(implode(', ', $pinMeta)) ?><br>
                        <?php endif; ?>
                        <?php if (trim((string) ($order['shipping_map_url'] ?? '')) !== ''): ?>
                            <a class="btn" href="<?= e((string) $order['shipping_map_url']) ?>" target="_blank" rel="noopener">Open Map</a>
                        <?php endif; ?>
                    </td>
                    <td><?= e((string) $order['payment_method']) ?></td>
                    <td><?= money((float) $order['total']) ?></td>
                    <td><?= e((string) $order['status']) ?></td>
                    <td>
                        <form method="post" style="display:flex;gap:8px;">
                            <input type="hidden" name="action" value="update_order_status">
                            <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                            <select name="status">
                                <?php foreach (['pending', 'paid', 'shipped', 'completed', 'cancelled'] as $status): ?>
                                    <option value="<?= e($status) ?>" <?= $status === $order['status'] ? 'selected' : '' ?>><?= e(ucfirst($status)) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn">Save</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="section container" id="support-tickets">
    <div class="panel reveal">
        <h3>Customer Service Ticket Center</h3>
        <?php if (!$supportReady): ?>
            <p style="color:#b30000;">Support tables are not ready. Run <code>database/support_ticket_migration.sql</code>.</p>
        <?php elseif (!$supportTickets): ?>
            <p>No support tickets yet.</p>
        <?php else: ?>
            <div class="grid support-admin-grid">
                <div>
                    <h4>All Tickets</h4>
                    <div class="support-ticket-list">
                        <?php foreach ($supportTickets as $ticket): ?>
                            <?php $isSelected = (int) ($selectedSupportTicket['id'] ?? 0) === (int) $ticket['id']; ?>
                            <a class="support-ticket-link<?= $isSelected ? ' is-active' : '' ?>" href="<?= BASE_URL ?>/admin/index.php?support_ticket_id=<?= (int) $ticket['id'] ?>#support-tickets">
                                <strong><?= e((string) $ticket['subject']) ?></strong>
                                <span><?= e((string) $ticket['full_name']) ?> | <?= e(support_ticket_status_label((string) $ticket['status'])) ?> | <?= e((string) strtoupper((string) $ticket['priority'])) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div>
                    <?php if (!$selectedSupportTicket): ?>
                        <p>Select a ticket to view details.</p>
                    <?php else: ?>
                        <h4><?= e((string) $selectedSupportTicket['subject']) ?></h4>
                        <p>
                            Customer: <strong><?= e((string) $selectedSupportTicket['full_name']) ?></strong> (<?= e((string) $selectedSupportTicket['email']) ?>)
                            | Status: <span class="status-badge"><?= e(support_ticket_status_label((string) $selectedSupportTicket['status'])) ?></span>
                            | Priority: <strong><?= e((string) strtoupper((string) $selectedSupportTicket['priority'])) ?></strong>
                        </p>
                        <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;">
                            <input type="hidden" name="action" value="update_support_ticket_status">
                            <input type="hidden" name="ticket_id" value="<?= (int) $selectedSupportTicket['id'] ?>">
                            <div>
                                <label>Status</label>
                                <select name="status">
                                    <?php foreach (['open', 'in_progress', 'resolved', 'closed'] as $status): ?>
                                        <option value="<?= e($status) ?>" <?= $status === (string) ($selectedSupportTicket['status'] ?? '') ? 'selected' : '' ?>><?= e(support_ticket_status_label($status)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label>Priority</label>
                                <select name="priority">
                                    <?php foreach (['low', 'normal', 'high'] as $priority): ?>
                                        <option value="<?= e($priority) ?>" <?= $priority === (string) ($selectedSupportTicket['priority'] ?? '') ? 'selected' : '' ?>><?= e(strtoupper($priority)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button class="btn">Save Ticket</button>
                        </form>

                        <div class="support-chat-thread" style="margin-top:12px;">
                            <?php foreach ($selectedSupportMessages as $message): ?>
                                <?php
                                $role = (string) ($message['sender_role'] ?? 'customer');
                                $roleLabel = match ($role) {
                                    'admin' => 'Admin',
                                    'ai' => 'AI Assistant',
                                    default => 'Customer',
                                };
                                ?>
                                <article class="support-chat-message support-chat-<?= e($role) ?>">
                                    <div class="support-chat-meta">
                                        <strong><?= e($roleLabel) ?></strong>
                                        <span><?= e((string) ($message['created_at'] ?? '')) ?></span>
                                    </div>
                                    <p><?= nl2br(e((string) ($message['message_text'] ?? ''))) ?></p>
                                </article>
                            <?php endforeach; ?>
                        </div>

                        <form method="post" style="margin-top:12px;">
                            <input type="hidden" name="action" value="reply_support_ticket">
                            <input type="hidden" name="ticket_id" value="<?= (int) $selectedSupportTicket['id'] ?>">
                            <label>Admin Reply</label>
                            <textarea name="message_text" rows="3" placeholder="Write your support response..." required></textarea>
                            <label>Set Next Status</label>
                            <select name="next_status">
                                <option value="in_progress">In Progress</option>
                                <option value="resolved">Resolved</option>
                                <option value="closed">Closed</option>
                                <option value="open">Open</option>
                            </select>
                            <button class="btn btn-gold">Send Reply</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>

<section class="section container">
    <div class="grid" style="grid-template-columns:1fr 1fr;">
        <div class="panel reveal">
            <h3>Add Product</h3>
            <form method="post">
                <input type="hidden" name="action" value="add_product">
                <input name="name" placeholder="Product name" required>
                <textarea name="description" placeholder="Description" required></textarea>
                <input name="category" placeholder="Category" required>
                <input type="number" min="0.01" max="2500" step="0.01" name="price" placeholder="Price (max PHP 2,500)" required>
                <input type="number" min="0" name="stock" placeholder="Stock" required>
                <input name="image_url" placeholder="Image URL" required>
                <button class="btn btn-gold">Save Product</button>
            </form>
        </div>
        <div class="panel reveal">
            <h3>Create Admin Account</h3>
            <form method="post">
                <input type="hidden" name="action" value="add_admin_user">
                <input name="full_name" placeholder="Full name" required>
                <input type="email" name="email" placeholder="Email" required>
                <input type="password" name="password" minlength="8" placeholder="Password (min 8 chars)" required>
                <button class="btn btn-gold">Create Admin</button>
            </form>
        </div>
    </div>
</section>

<section class="section container">
    <div class="panel reveal">
        <h3>Inventory Control</h3>
        <table>
            <thead><tr><th>Product</th><th>Category</th><th>Price</th><th>Stock</th><th>Image</th><th>Description</th><th>Save</th><th>Delete</th></tr></thead>
            <tbody>
            <?php foreach ($inventory as $product): ?>
                <?php $updateFormId = 'product-update-' . (int) $product['id']; ?>
                <tr>
                    <td><input form="<?= e($updateFormId) ?>" name="name" value="<?= e((string) $product['name']) ?>" required></td>
                    <td><input form="<?= e($updateFormId) ?>" name="category" value="<?= e((string) $product['category']) ?>" required></td>
                    <td><input form="<?= e($updateFormId) ?>" type="number" step="0.01" min="0.01" max="2500" name="price" value="<?= e((string) $product['price']) ?>" required></td>
                    <td><input form="<?= e($updateFormId) ?>" type="number" min="0" name="stock" value="<?= (int) $product['stock'] ?>" required></td>
                    <td><input form="<?= e($updateFormId) ?>" name="image_url" value="<?= e((string) $product['image_url']) ?>" required></td>
                    <td><textarea form="<?= e($updateFormId) ?>" name="description" rows="2" required><?= e((string) $product['description']) ?></textarea></td>
                    <td>
                        <form id="<?= e($updateFormId) ?>" method="post" style="display:flex;gap:8px;">
                            <input type="hidden" name="action" value="update_product">
                            <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                            <button class="btn">Save</button>
                        </form>
                    </td>
                    <td>
                        <form method="post" onsubmit="return confirm('Delete this product?');">
                            <input type="hidden" name="action" value="delete_product">
                            <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                            <button class="btn">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="section container">
    <div class="grid" style="grid-template-columns:1fr 1fr;">
        <div class="panel reveal">
            <h3>User Access Control</h3>
            <?php if (!$profileReady): ?>
                <p style="color:#b30000;">User profile columns are not ready yet. Run <code>database/user_profile_migration.sql</code>.</p>
            <?php endif; ?>
            <table>
                <thead><tr><th>User</th><th>Email / Contact</th><th>Location</th><th>Role</th><th>Update</th></tr></thead>
                <tbody>
                <?php foreach ($users as $user): ?>
                    <tr>
                        <td>
                            <?= e((string) $user['full_name']) ?><br>
                            <small>@<?= e((string) ($user['username'] ?? '')) ?></small>
                        </td>
                        <td>
                            <?= e((string) $user['email']) ?><br>
                            <small><?= e((string) ($user['phone'] ?? '')) ?></small>
                        </td>
                        <td>
                            <?= e((string) ($user['city'] ?? '')) ?>
                            <?= trim((string) ($user['province'] ?? '')) !== '' ? ', ' . e((string) $user['province']) : '' ?>
                        </td>
                        <td><?= e((string) $user['role']) ?></td>
                        <td>
                            <form method="post" style="display:flex;gap:8px;">
                                <input type="hidden" name="action" value="update_user_role">
                                <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                                <select name="role">
                                    <option value="customer" <?= $user['role'] === 'customer' ? 'selected' : '' ?>>Customer</option>
                                    <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                                </select>
                                <button class="btn">Save</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="panel reveal">
            <h3>Top Product Sales</h3>
            <table>
                <thead><tr><th>Product</th><th>Units Sold</th><th>Sales</th><th>Stock</th></tr></thead>
                <tbody>
                <?php foreach ($topProducts as $row): ?>
                    <tr>
                        <td><?= e((string) $row['name']) ?><br><small><?= e((string) $row['category']) ?></small></td>
                        <td><?= (int) $row['units_sold'] ?></td>
                        <td><?= money((float) $row['sales_amount']) ?></td>
                        <td><?= (int) $row['stock'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="section container">
    <div class="grid" style="grid-template-columns: 1fr 1fr;">
        <div class="panel reveal">
            <h3>Add Customize Option</h3>
            <form method="post">
                <input type="hidden" name="action" value="add_custom_option">
                <label>Option Type</label>
                <select name="type" required>
                    <option value="flower">Flower</option>
                    <option value="wrap">Wrap</option>
                    <option value="addon">Add-on</option>
                </select>
                <input name="name" placeholder="Option name" required>
                <input type="number" step="0.01" min="0" name="extra_price" placeholder="Additional price (PHP)" required>
                <input name="image_url" placeholder="Image URL (optional for wrap/add-on)">
                <input type="number" name="sort_order" placeholder="Sort order" value="0">
                <label><input type="checkbox" name="is_active" checked> Available / In stock</label>
                <button class="btn btn-gold">Save Option</button>
            </form>
        </div>
        <div class="panel reveal">
            <h3>Customize Options Inventory</h3>
            <?php if ($customOptionsResult === false): ?>
                <p>Customize options table not found. Please run updated schema.sql.</p>
            <?php else: ?>
                <table>
                    <thead><tr><th>Type</th><th>Name</th><th>Price</th><th>Status</th><th>Update</th></tr></thead>
                    <tbody>
                    <?php foreach ($customOptions as $option): ?>
                        <tr>
                            <td><?= e((string) $option['type']) ?></td>
                            <td><?= e((string) $option['name']) ?></td>
                            <td><?= money((float) $option['extra_price']) ?></td>
                            <td><?= (int) $option['is_active'] === 1 ? 'Available' : 'Out of stock' ?></td>
                            <td>
                                <form method="post" style="display:grid; gap:6px;">
                                    <input type="hidden" name="action" value="update_custom_option">
                                    <input type="hidden" name="option_id" value="<?= (int) $option['id'] ?>">
                                    <input name="name" value="<?= e((string) $option['name']) ?>" required>
                                    <input type="number" step="0.01" min="0" name="extra_price" value="<?= e((string) $option['extra_price']) ?>" required>
                                    <input name="image_url" value="<?= e((string) $option['image_url']) ?>" placeholder="Image URL">
                                    <input type="number" name="sort_order" value="<?= (int) $option['sort_order'] ?>">
                                    <label><input type="checkbox" name="is_active" <?= (int) $option['is_active'] === 1 ? 'checked' : '' ?>> Available</label>
                                    <button class="btn">Save</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
