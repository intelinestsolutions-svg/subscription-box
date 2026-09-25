<?php
/**
 * Checkout — pays for the upcoming cycle (base + add-ons) through the billing provider.
 * Demo mode: succeeds unless force_fail is sent (lets you test the dunning flow).
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib.php';

$user = require_auth(['subscriber']);
if (($_GET['action'] ?? '') !== 'checkout') json_err('Unknown action.');

$in = body();
$sub = my_sub((int)($in['subscription_id'] ?? 0), (int)$user['id']);
$win = changes_next_cycle($GLOBALS['pdo'], $sub);

$plan = $GLOBALS['pdo']->prepare('SELECT * FROM plans WHERE id = ?');
$plan->execute([$sub['plan_id']]);
$plan = $plan->fetch();

$items = $GLOBALS['pdo']->prepare(
    'SELECT * FROM box_line_items WHERE subscription_id = ? AND cycle_date = ? ORDER BY kind'
);
$items->execute([$sub['id'], $win['cycle']]);
$items = $items->fetchAll();

$base     = (float)$plan['price'];
$addonSum = 0.0;
foreach ($items as $it) { if ($it['kind'] === 'addon') $addonSum += (float)$it['total_price']; }
$total = round($base + $addonSum, 2);

require_once __DIR__ . '/billing/Provider.php';
$provider = BillingProviderFactory::make($GLOBALS['config']);
$res = $provider->charge($user, $plan['name'] . ' — cycle ' . $win['cycle'], $total,
                         ['force_fail' => !empty($in['force_fail'])]);

$status = $res['success'] ? 'succeeded' : 'failed';
$pay = $GLOBALS['pdo']->prepare(
    'INSERT INTO payments (subscription_id, provider, provider_ref, amount, status, failure_reason, paid_at)
     VALUES (?,?,?,?,?,?,?)'
);
$pay->execute([$sub['id'], $res['provider'], $res['ref'], $total, $status,
               $res['message'] ?? '', $res['success'] ? now_utc() : null]);

if (!$res['success']) {
    $ev = $GLOBALS['pdo']->prepare('INSERT INTO events (type, subscription_id, meta_json) VALUES (?,?,?)');
    $ev->execute(['payment_failed', $sub['id'], json_encode(['ref' => $res['ref']])]);
    json_err('Payment failed: ' . ($res['message'] ?? 'declined'), 402);
}

$ev = $GLOBALS['pdo']->prepare('INSERT INTO events (type, subscription_id, meta_json) VALUES (?,?,?)');
$ev->execute(['payment_succeeded', $sub['id'], json_encode(['ref' => $res['ref'], 'amount' => $total])]);

json_out([
    'receipt' => [
        'ref'       => $res['ref'],
        'plan'      => $plan['name'],
        'cycle'     => $win['cycle'],
        'base'      => $base,
        'addons'    => $addonSum,
        'total'     => $total,
        'items'     => $items,
        'paid_at'   => now_utc(),
    ],
]);

function my_sub(int $id, int $userId): array {
    $st = $GLOBALS['pdo']->prepare('SELECT * FROM subscriptions WHERE id = ? AND user_id = ? AND status != "canceled"');
    $st->execute([$id, $userId]);
    $sub = $st->fetch();
    if (!$sub) json_err('Subscription not found.', 404);
    return $sub;
}