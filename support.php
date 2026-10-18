<?php
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/helpers.php';
require_login();

$user = current_user();
if (($user['role'] ?? '') === 'admin') {
    $_SESSION['admin_flash'] = ['message' => 'Admins should manage support tickets from the admin dashboard.', 'type' => 'error'];
    header('Location: ' . BASE_URL . '/admin/index.php#support-tickets');
    exit;
}

if (!ensure_support_schema()) {
    http_response_code(500);
    exit('Support system is not ready yet.');
}

function support_flash_set(string $message, string $type = 'success'): void
{
    $_SESSION['support_flash'] = ['message' => $message, 'type' => $type];
}

function support_flash_get(): ?array
{
    $flash = $_SESSION['support_flash'] ?? null;
    unset($_SESSION['support_flash']);
    return is_array($flash) ? $flash : null;
}

$userId = (int) ($user['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'create_ticket') {
        $subject = trim((string) ($_POST['subject'] ?? ''));
        $priority = trim((string) ($_POST['priority'] ?? 'normal'));
        $message = trim((string) ($_POST['message_text'] ?? ''));
        if ($subject === '' || $message === '') {
            support_flash_set('Please provide a subject and message.', 'error');
            header('Location: ' . BASE_URL . '/support.php');
            exit;
        }
        if (!in_array($priority, ['low', 'normal', 'high'], true)) {
            $priority = 'normal';
        }

        $now = date('Y-m-d H:i:s');
        $ticketStmt = db()->prepare('INSERT INTO support_tickets (user_id, subject, status, priority, last_customer_message_at) VALUES (?, ?, \'open\', ?, ?)');
        if (!($ticketStmt instanceof mysqli_stmt)) {
            support_flash_set('Unable to create support ticket right now.', 'error');
            header('Location: ' . BASE_URL . '/support.php');
            exit;
        }
        $ticketStmt->bind_param('isss', $userId, $subject, $priority, $now);
        $ticketOk = $ticketStmt->execute();
        $ticketId = (int) db()->insert_id;
        $ticketStmt->close();

        if (!$ticketOk || $ticketId < 1) {
            support_flash_set('Unable to create support ticket right now.', 'error');
            header('Location: ' . BASE_URL . '/support.php');
            exit;
        }

        $msgStmt = db()->prepare('INSERT INTO support_ticket_messages (ticket_id, sender_user_id, sender_role, message_text) VALUES (?, ?, \'customer\', ?)');
        if ($msgStmt instanceof mysqli_stmt) {
            $msgStmt->bind_param('iis', $ticketId, $userId, $message);
            $msgStmt->execute();
            $msgStmt->close();
        }

        $aiReply = support_ai_reply($message, $subject);
        $aiStmt = db()->prepare('INSERT INTO support_ticket_messages (ticket_id, sender_user_id, sender_role, message_text) VALUES (?, NULL, \'ai\', ?)');
        if ($aiStmt instanceof mysqli_stmt) {
            $aiStmt->bind_param('is', $ticketId, $aiReply);
            $aiStmt->execute();
            $aiStmt->close();
        }

        support_flash_set('Ticket created. AI assistant posted first guidance and admin can now handle this issue.');
        header('Location: ' . BASE_URL . '/support.php?ticket_id=' . $ticketId);
        exit;
    }

    if ($action === 'send_message') {
        $ticketId = (int) ($_POST['ticket_id'] ?? 0);
        $message = trim((string) ($_POST['message_text'] ?? ''));
        if ($ticketId < 1 || $message === '') {
            support_flash_set('Please write a message before sending.', 'error');
            header('Location: ' . BASE_URL . '/support.php?ticket_id=' . $ticketId);
            exit;
        }

        $ticketStmt = db()->prepare('SELECT id, status FROM support_tickets WHERE id = ? AND user_id = ? LIMIT 1');
        $ticket = null;
        if ($ticketStmt instanceof mysqli_stmt) {
            $ticketStmt->bind_param('ii', $ticketId, $userId);
            $ticketStmt->execute();
            $ticket = $ticketStmt->get_result()->fetch_assoc();
            $ticketStmt->close();
        }
        if (!$ticket) {
            support_flash_set('Ticket not found.', 'error');
            header('Location: ' . BASE_URL . '/support.php');
            exit;
        }

        $msgStmt = db()->prepare('INSERT INTO support_ticket_messages (ticket_id, sender_user_id, sender_role, message_text) VALUES (?, ?, \'customer\', ?)');
        if ($msgStmt instanceof mysqli_stmt) {
            $msgStmt->bind_param('iis', $ticketId, $userId, $message);
            $msgStmt->execute();
            $msgStmt->close();
        }

        $newStatus = in_array((string) ($ticket['status'] ?? ''), ['resolved', 'closed'], true) ? 'open' : (string) ($ticket['status'] ?? 'open');
        $now = date('Y-m-d H:i:s');
        $updateStmt = db()->prepare('UPDATE support_tickets SET status = ?, last_customer_message_at = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        if ($updateStmt instanceof mysqli_stmt) {
            $updateStmt->bind_param('ssi', $newStatus, $now, $ticketId);
            $updateStmt->execute();
            $updateStmt->close();
        }

        $aiReply = support_ai_reply($message, '');
        $aiStmt = db()->prepare('INSERT INTO support_ticket_messages (ticket_id, sender_user_id, sender_role, message_text) VALUES (?, NULL, \'ai\', ?)');
        if ($aiStmt instanceof mysqli_stmt) {
            $aiStmt->bind_param('is', $ticketId, $aiReply);
            $aiStmt->execute();
            $aiStmt->close();
        }

        support_flash_set('Message sent.');
        header('Location: ' . BASE_URL . '/support.php?ticket_id=' . $ticketId);
        exit;
    }
}

$ticketsStmt = db()->prepare('SELECT id, subject, status, priority, created_at, updated_at, last_admin_reply_at FROM support_tickets WHERE user_id = ? ORDER BY updated_at DESC, id DESC');
$tickets = [];
if ($ticketsStmt instanceof mysqli_stmt) {
    $ticketsStmt->bind_param('i', $userId);
    $ticketsStmt->execute();
    $tickets = $ticketsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $ticketsStmt->close();
}

$ticketId = (int) ($_GET['ticket_id'] ?? 0);
if ($ticketId < 1 && $tickets) {
    $ticketId = (int) ($tickets[0]['id'] ?? 0);
}

$activeTicket = null;
$messages = [];
if ($ticketId > 0) {
    $ticketStmt = db()->prepare('SELECT id, subject, status, priority, created_at, updated_at, last_admin_reply_at FROM support_tickets WHERE id = ? AND user_id = ? LIMIT 1');
    if ($ticketStmt instanceof mysqli_stmt) {
        $ticketStmt->bind_param('ii', $ticketId, $userId);
        $ticketStmt->execute();
        $activeTicket = $ticketStmt->get_result()->fetch_assoc();
        $ticketStmt->close();
    }

    if ($activeTicket) {
        $msgStmt = db()->prepare('SELECT sender_role, message_text, created_at FROM support_ticket_messages WHERE ticket_id = ? ORDER BY created_at ASC, id ASC');
        if ($msgStmt instanceof mysqli_stmt) {
            $msgStmt->bind_param('i', $ticketId);
            $msgStmt->execute();
            $messages = $msgStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $msgStmt->close();
        }
    }
}

$flash = support_flash_get();
$pageTitle = 'Customer Support';
require_once __DIR__ . '/includes/header.php';
?>
<section class="section container">
    <h1 class="section-title reveal">Customer Service Support</h1>
    <?php if ($flash): ?>
        <div class="panel" style="border-color:<?= ($flash['type'] ?? '') === 'error' ? '#b44343' : '#3f8d4f' ?>;margin-bottom:12px;">
            <?= e((string) ($flash['message'] ?? '')) ?>
        </div>
    <?php endif; ?>
    <div class="grid support-grid">
        <div class="panel reveal">
            <h3>Open New Ticket</h3>
            <form method="post">
                <input type="hidden" name="action" value="create_ticket">
                <label>Subject</label>
                <input name="subject" placeholder="Payment issue, delivery concern, wrong item..." required>
                <label>Priority</label>
                <select name="priority">
                    <option value="normal">Normal</option>
                    <option value="high">High</option>
                    <option value="low">Low</option>
                </select>
                <label>Message</label>
                <textarea name="message_text" rows="4" placeholder="Describe your concern in detail..." required></textarea>
                <button class="btn btn-gold">Create Ticket</button>
            </form>
            <hr>
            <h3>Your Tickets</h3>
            <?php if (!$tickets): ?>
                <p>No support ticket yet.</p>
            <?php else: ?>
                <div class="support-ticket-list">
                    <?php foreach ($tickets as $ticket): ?>
                        <a class="support-ticket-link<?= (int) $ticket['id'] === $ticketId ? ' is-active' : '' ?>" href="<?= BASE_URL ?>/support.php?ticket_id=<?= (int) $ticket['id'] ?>">
                            <strong><?= e((string) $ticket['subject']) ?></strong>
                            <span><?= e(support_ticket_status_label((string) $ticket['status'])) ?> | <?= e((string) strtoupper((string) $ticket['priority'])) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="panel reveal">
            <?php if (!$activeTicket): ?>
                <p>Select a ticket to view conversation.</p>
            <?php else: ?>
                <h3><?= e((string) $activeTicket['subject']) ?></h3>
                <p>
                    Status: <span class="status-badge"><?= e(support_ticket_status_label((string) $activeTicket['status'])) ?></span>
                    | Priority: <strong><?= e((string) strtoupper((string) $activeTicket['priority'])) ?></strong>
                </p>
                <div class="support-chat-thread">
                    <?php foreach ($messages as $message): ?>
                        <?php
                        $role = (string) ($message['sender_role'] ?? 'customer');
                        $roleLabel = match ($role) {
                            'admin' => 'Admin',
                            'ai' => 'AI Assistant',
                            default => 'You',
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
                <form method="post">
                    <input type="hidden" name="action" value="send_message">
                    <input type="hidden" name="ticket_id" value="<?= (int) $activeTicket['id'] ?>">
                    <label>Reply</label>
                    <textarea name="message_text" rows="3" placeholder="Write your follow-up..." required></textarea>
                    <button class="btn btn-gold">Send Message</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
