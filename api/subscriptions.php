<?php
/**
 * Subscriptions — the subscriber's own plans: list, create, pause, resume, cancel.
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib.php';

$user = require_auth(['subscriber']);
if ($user['role'] !== 'subscriber') json_err('Subscriber portal only.', 403);

$action = $_GET['action'] ?? 'list';
$in     = body();

switch ($action) {

    case 'list': {
        $st = $GLOBALS['pdo']->prepare(
            'SELECT s.*, p.name plan_name, p.price plan_price,
                    (SELECT COUNT(*) FROM skip_requests sk WHERE sk.subscription_id = s.id AND sk.cycle_date = s.next_charge_at) skip_pending
             FROM subscriptions s JOIN plans p ON p.id = s.plan_id
             WHERE s.user_id = ? AND s.status != "canceled" ORDER BY s.id'
        );
        $st->execute([$user['id']]);
        $out = [];
        foreach ($st->fetchAll() as $sub) {
            $win = changes_next_cycle($GLOBALS['pdo'], $sub);
            $sub['changes_window'] = $win;
            $sub['field_choices']  = field_choices_for($GLOBALS['pdo'], (int)$sub['id']);
            $sub['upcoming_items'] = upcoming_items($GLOBALS['pdo'], (int)$sub['id'], $win['cycle']);
            $out[] = $sub;
        }
        json_out($out);
    }

    case 'create': {
        $planId = (int)($in['plan_id'] ?? 0);
        if (!$planId) json_err('plan_id is required.');
        $pl = $GLOBALS['pdo']->prepare('SELECT * FROM plans WHERE id = ? AND is_active = 1');
        $pl->execute([$planId]);
        $plan = $pl->fetch();
        if (!$plan) json_err('Plan not found.', 404);

        // Demo checkout: first cycle is charged now via demo provider.
        require_once __DIR__ . '/billing/Provider.php';
        $provider = BillingProvider::make($GLOBALS['config']);
        $res = $provider->charge($user, $plan['name'], (float)$plan['price'], ['force_fail' => !empty($in['force_fail'])]);
        if (!$res['success']) json_err('Payment failed: ' . ($res['message'] ?? 'declined'), 402);

        $start = today();
        $end   = (new DateTime($start))->modify('+1 month')->format('Y-m-d');
        $ins = $GLOBALS['pdo']->prepare(
            'INSERT INTO subscriptions (user_id, plan_id, address_id, status, current_period_start, current_period_end, next_charge_at)
             VALUES (?,?,?,?,?,?,?)'
        );
        $ins->execute([$user['id'], $planId, $in['address_id'] ?? null, 'active', $start, $end, $end]);
        $subId = (int)$GLOBALS['pdo']->lastInsertId();

        $pay = $GLOBALS['pdo']->prepare(
            'INSERT INTO payments (subscription_id, provider, provider_ref, amount, status, paid_at) VALUES (?,?,?,?,?,?)'
        );
        $pay->execute([$subId, $res['provider'], $res['ref'], $plan['price'], 'succeeded', now_utc()]);

        $event = $GLOBALS['pdo']->prepare('INSERT INTO events (type, subscription_id, meta_json) VALUES (?,?,?)');
        $event->execute(['subscribe', $subId, json_encode(['plan' => $plan['name']])]);

        json_out(['subscription_id' => $subId, 'payment' => $res], 201);
    }

    case 'pause': {
        $sub = my_sub((int)($in['subscription_id'] ?? 0), (int)$user['id']);
        // Pause takes effect after the current cycle (billing already ran).
        $until = (new DateTime($sub['next_charge_at']))->modify('+1 month')->format('Y-m-d');
        $up = $GLOBALS['pdo']->prepare('UPDATE subscriptions SET status = "paused", pause_until = ? WHERE id = ?');
        $up->execute([$until, $sub['id']]);
        $ev = $GLOBALS['pdo']->prepare('INSERT INTO events (type, subscription_id, meta_json) VALUES (?,?,?)');
        $ev->execute(['pause', $sub['id'], json_encode(['until' => $until])]);
        json_out(['status' => 'paused', 'resumes' => $until]);
    }

    case 'resume': {
        $sub = my_sub((int)($in['subscription_id'] ?? 0), (int)$user['id']);
        $up = $GLOBALS['pdo']->prepare('UPDATE subscriptions SET status = "active", pause_until = NULL WHERE id = ?');
        $up->execute([$sub['id']]);
        $ev = $GLOBALS['pdo']->prepare('INSERT INTO events (type, subscription_id, meta_json) VALUES (?,?,?)');
        $ev->execute(['resume', $sub['id'], '{}']);
        json_out(['status' => 'active']);
    }

    case 'cancel': {
        $sub = my_sub((int)($in['subscription_id'] ?? 0), (int)$user['id']);
        $up = $GLOBALS['pdo']->prepare('UPDATE subscriptions SET status = "canceled" WHERE id = ?');
        $up->execute([$sub['id']]);
        $ev = $GLOBALS['pdo']->prepare('INSERT INTO events (type, subscription_id, meta_json) VALUES (?,?,?)');
        $ev->execute(['cancel', $sub['id'], '{}']);
        json_out(['status' => 'canceled']);
    }

    default: json_err('Unknown action.');
}

function my_sub(int $id, int $userId): array {
    $st = $GLOBALS['pdo']->prepare('SELECT * FROM subscriptions WHERE id = ? AND user_id = ? AND status != "canceled"');
    $st->execute([$id, $userId]);
    $sub = $st->fetch();
    if (!$sub) json_err('Subscription not found.', 404);
    return $sub;
}

function field_choices_for(PDO $pdo, int $subscriptionId): array {
    $st = $pdo->prepare(
        'SELECT c.field_id, c.field_value, f.field_key, f.field_label, f.options_json
         FROM customizations c JOIN variant_fields f ON f.id = c.field_id
         WHERE c.subscription_id = ?'
    );
    $st->execute([$subscriptionId]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[] = ['field_id' => (int)$r['field_id'], 'value' => $r['field_value'],
                  'field_key' => $r['field_key'], 'field_label' => $r['field_label'],
                  'options' => json_decode($r['options_json'], true) ?: []];
    }
    return $out;
}

function upcoming_items(PDO $pdo, int $subscriptionId, string $cycle): array {
    $st = $pdo->prepare('SELECT * FROM box_line_items WHERE subscription_id = ? AND cycle_date = ? ORDER BY kind');
    $st->execute([$subscriptionId, $cycle]);
    return $st->fetchAll();
}