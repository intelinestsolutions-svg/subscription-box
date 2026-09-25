<?php
/**
 * Add-On Upsell Shop — one-off items attached to the upcoming box (no extra shipping).
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib.php';

$user = require_auth(['subscriber']);
$action = $_GET['action'] ?? 'list';
$in     = body();

switch ($action) {

    /** GET list&subscription_id=1 → add-ons for the plan + what's already in the next cycle. */
    case 'list': {
        $sub = my_sub((int)($_GET['subscription_id'] ?? 0), (int)$user['id']);
        $win = changes_next_cycle($GLOBALS['pdo'], $sub);

        $add = $GLOBALS['pdo']->prepare('SELECT * FROM addons WHERE plan_id = ? AND is_active = 1 ORDER BY price');
        $add->execute([$sub['plan_id']]);

        $li = $GLOBALS['pdo']->prepare(
            'SELECT id, item_name, sku, qty, unit_price, total_price FROM box_line_items
             WHERE subscription_id = ? AND cycle_date = ? AND kind = "addon"'
        );
        $li->execute([$sub['id'], $win['cycle']]);

        json_out([
            'plan_id'      => (int)$sub['plan_id'],
            'window'       => $win,
            'addons'       => $add->fetchAll(),
            'in_next_box'  => $li->fetchAll(),
        ]);
    }

    /** POST add {subscription_id, addon_id} */
    case 'add': {
        $sub = my_sub((int)($in['subscription_id'] ?? 0), (int)$user['id']);
        $win = changes_next_cycle($GLOBALS['pdo'], $sub);
        if (!$win['immediate']) {
            json_err("The window for {$sub['next_charge_at']} is closed; add-ons go to the cycle after it.", 409);
        }
        $ad = $GLOBALS['pdo']->prepare('SELECT * FROM addons WHERE id = ? AND plan_id = ? AND is_active = 1');
        $ad->execute([(int)($in['addon_id'] ?? 0), $sub['plan_id']]);
        $addon = $ad->fetch();
        if (!$addon) json_err('Add-on not found for this plan.', 404);

        // increment if already present for that cycle
        $exists = $GLOBALS['pdo']->prepare(
            'SELECT id, qty FROM box_line_items WHERE subscription_id=? AND cycle_date=? AND sku=?'
        );
        $exists->execute([$sub['id'], $win['cycle'], $addon['sku']]);
        $row = $exists->fetch();
        if ($row) {
            $up = $GLOBALS['pdo']->prepare(
                'UPDATE box_line_items SET qty = qty + 1, total_price = unit_price * (qty + 1) WHERE id = ?'
            );
            $up->execute([$row['id']]);
        } else {
            $ins = $GLOBALS['pdo']->prepare(
                'INSERT INTO box_line_items (subscription_id, cycle_date, kind, item_name, sku, qty, unit_price, total_price)
                 VALUES (?,?,?,?,?,?,?,?)'
            );
            $ins->execute([$sub['id'], $win['cycle'], 'addon', $addon['name'], $addon['sku'],
                           1, (float)$addon['price'], (float)$addon['price']]);
        }
        $ev = $GLOBALS['pdo']->prepare('INSERT INTO events (type, subscription_id, meta_json) VALUES (?,?,?)');
        $ev->execute(['addon_added', $sub['id'], json_encode(['addon' => $addon['name'], 'cycle' => $win['cycle']])]);
        json_out(['added' => $addon['name'], 'cycle' => $win['cycle']]);
    }

    /** POST remove {line_item_id} */
    case 'remove': {
        $st = $GLOBALS['pdo']->prepare(
            'SELECT li.* FROM box_line_items li WHERE li.id = ? AND li.kind = "addon"'
        );
        $st->execute([(int)($in['line_item_id'] ?? 0)]);
        $li = $st->fetch();
        if (!$li) json_err('Line item not found.', 404);
        $del = $GLOBALS['pdo']->prepare('DELETE FROM box_line_items WHERE id = ?');
        $del->execute([$li['id']]);
        json_out(['removed' => $li['item_name']]);
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