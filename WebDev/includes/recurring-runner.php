<?php
if (__FILE__ === $_SERVER['SCRIPT_FILENAME']) { header($_SERVER['SERVER_PROTOCOL'].' 403 Forbidden'); exit; }

require_once __DIR__ . '/mailer.php';

function recAdvance($dateStr, $freq, $interval) {
    $t = new DateTime($dateStr);
    if ($freq === 'daily') $t->modify("+{$interval} day");
    elseif ($freq === 'weekly') $t->modify('+' . (7 * $interval) . ' days');
    elseif ($freq === 'yearly') $t->modify("+{$interval} year");
    else $t->modify("+{$interval} month");
    return $t->format('Y-m-d');
}

function generateOneRecurring(PDO $pdo, array $rec, $forceDate = null) {
    $uid = (int)$rec['user_id'];
    $abbr = $rec['business_abbr'] ?: 'BIZ';
    $issue = $forceDate ?: $rec['next_run_date'];
    $due = date('Y-m-d', strtotime($issue . ' +' . (int)$rec['due_days'] . ' days'));

    if (invoiceLimitReached($pdo, $uid)) {
        throw new RuntimeException('Monthly invoice limit reached for user ' . $uid . ' (' . FREE_MONTHLY_INVOICE_LIMIT . ' invoices/month on the Free plan).');
    }

    $pdo->beginTransaction();
    $inv_num = consumeDocNumber($pdo, $uid, 'invoice', $abbr);
    $template_id = $rec['template_id'] ? (int)$rec['template_id'] : null;

    $stmt = $pdo->prepare("INSERT INTO invoices (user_id, invoice_number, customer_name, customer_email, customer_phone, billing_address, issue_date, due_date, amount, notes, discount_type, discount_value, template_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
    $stmt->execute([$uid, $inv_num, $rec['customer_name'], $rec['customer_email'], $rec['customer_phone'], $rec['billing_address'], $issue, $due, $rec['amount'], $rec['notes'], $rec['discount_type'], $rec['discount_value'], $template_id]);
    $inv_id = (int)$pdo->lastInsertId();

    $items = json_decode($rec['items_json'], true) ?: [];
    if (count($items) > 0) {
        $istmt = $pdo->prepare("INSERT INTO invoice_items (invoice_id, name, type, description, quantity, unit_price, amount) VALUES (?, ?, ?, ?, ?, ?, ?)");
        foreach ($items as $it) {
            if (empty($it['name'])) continue;
            $istmt->execute([
                $inv_id,
                $it['name'],
                trim($it['type'] ?? 'product'),
                trim($it['desc'] ?? ''),
                max(0, intval($it['qty'] ?? 1)),
                max(0, floatval($it['price'] ?? 0)),
                round(max(0, floatval($it['amount'] ?? 0)), 2)
            ]);
        }
    }

    $next = recAdvance($issue, $rec['frequency'], (int)$rec['interval_count']);
    $safeguard = 0;
    while ($next <= date('Y-m-d') && $safeguard < 100) {
        $next = recAdvance($next, $rec['frequency'], (int)$rec['interval_count']);
        $safeguard++;
    }

    logActivity($pdo, $uid, 'invoice_created', "Recurring invoice {$inv_num} auto-generated for {$rec['customer_name']} — ₦" . number_format(floatval($rec['amount']), 2), 'invoice', $inv_id);

    $stmt = $pdo->prepare("UPDATE recurring_invoices SET last_generated_at = ?, next_run_date = ? WHERE id = ? AND user_id = ?");
    $stmt->execute([$issue, $next, (int)$rec['id'], $uid]);

    $pdo->commit();

    if (!empty($rec['customer_email'])) {
        sendInvoiceEmail($pdo, $inv_num, $rec['customer_name'], $rec['customer_email'], $due, $rec['amount'], $rec['business_name'] ?? 'BizVaza');
    }
    return $inv_num;
}

function generateRecurringById(PDO $pdo, int $userId, int $id) {
    $stmt = $pdo->prepare("SELECT r.*, u.business_abbr, u.business_name FROM recurring_invoices r JOIN users u ON u.id = r.user_id WHERE r.id = ? AND r.user_id = ? LIMIT 1");
    $stmt->execute([$id, $userId]);
    $rec = $stmt->fetch();
    if (!$rec) return ['ok' => false, 'error' => 'Recurring invoice not found.'];
    if ($rec['status'] !== 'active') return ['ok' => false, 'error' => 'Recurring invoice is paused.'];
    try {
        $inv_num = generateOneRecurring($pdo, $rec, date('Y-m-d'));
        return ['ok' => true, 'invoice' => $inv_num];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function generateRecurringInvoices(PDO $pdo, $userId = null, $limit = 20) {
    if ($userId !== null) {
        $stmt = $pdo->prepare("SELECT r.*, u.business_abbr, u.business_name FROM recurring_invoices r JOIN users u ON u.id = r.user_id WHERE r.user_id = ? AND r.status = 'active' AND r.next_run_date <= CURDATE() ORDER BY r.next_run_date ASC LIMIT $limit");
        $stmt->execute([$userId]);
        $recurrings = $stmt->fetchAll();
    } else {
        $recurrings = $pdo->query("SELECT r.*, u.business_abbr, u.business_name FROM recurring_invoices r JOIN users u ON u.id = r.user_id WHERE r.status = 'active' AND r.next_run_date <= CURDATE() ORDER BY r.next_run_date ASC LIMIT $limit")->fetchAll();
    }

    $generated = [];
    $errors = [];
    foreach ($recurrings as $rec) {
        try {
            $inv_num = generateOneRecurring($pdo, $rec);
            $generated[] = ['recurring_id' => (int)$rec['id'], 'invoice' => $inv_num, 'issue_date' => $rec['next_run_date']];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $errors[] = ['recurring_id' => (int)$rec['id'], 'error' => $e->getMessage()];
        }
    }
    return ['generated' => $generated, 'errors' => $errors];
}