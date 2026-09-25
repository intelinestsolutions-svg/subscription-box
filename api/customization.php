<?php
/**
 * Box Customization Engine — pick variants (flavor/size/roast...) before the cutoff.
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib.php';

$user = require_auth(['subscriber']);
$action = $_GET['action'] ?? 'fields';
$in     = body();

switch ($action) {

    /** GET fields&plan_id=1 → variant fields + the subscriber's current choices. */
    case 'fields': {
        $planId = (int)($_GET['plan_id'] ?? 0);
        $st = $GLOBALS['pdo']->prepare(
            'SELECT vf.* FROM variant_fields vf JOIN plans p ON p.id = vf.plan_id
             WHERE vf.plan_id = ? ORDER BY vf.sort, vf.id'
        );
        $st->execute([$planId]);
        $fields = $st->fetchAll();
        foreach ($fields as &$f) $f['options'] = json_decode($f['options_json'], true) ?: [];

        $subId = (int)($in['subscription_id'] ?? 0) ?: (int)($_GET['subscription_id'] ?? 0);
        $sub = my_sub($subId, (int)$user['id']);
        $ch  = $GLOBALS['pdo']->prepare('SELECT field_id, field_value FROM customizations WHERE subscription_id = ?');
        $ch->execute([$sub['id']]);
        $choices = array_column($ch->fetchAll(), 'field_value', 'field_id');

        json_out(['fields' => $fields, 'choices' => $choices, 'window' => changes_next_cycle($GLOBALS['pdo'], $sub)]);
    }

    /** POST save {subscription_id, choices: {"4":"Spicy","5":"Large"}} */
    case 'save': {
        $sub = my_sub((int)($in['subscription_id'] ?? 0), (int)$user['id']);
        $win = changes_next_cycle($GLOBALS['pdo'], $sub);
        if (!$win['immediate']) {
            json_err("Cutoff for {$sub['next_charge_at']} has passed — your swaps apply from {$win['cycle']}.", 409);
        }
        $choices = $in['choices'] ?? [];
        if (!is_array($choices) || !$choices) json_err('choices must be an object of field_id → value.');

        $fs = $GLOBALS['pdo']->prepare('SELECT * FROM variant_fields WHERE plan_id = ?');
        $fs->execute([$sub['plan_id']]);
        $allowed = [];
        foreach ($fs->fetchAll() as $f) {
            $allowed[(int)$f['id']] = ['options' => json_decode($f['options_json'], true) ?: [], 'label' => $f['field_label']];
        }

        $upsert = $GLOBALS['pdo']->prepare(
            'INSERT INTO customizations (subscription_id, field_id, field_value) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE field_value = VALUES(field_value), updated_at = NOW()'
        );
        $applied = [];
        foreach ($choices as $fidRaw => $value) {
            $fid = (int)$fidRaw;
            if (!isset($allowed[$fid])) json_err("Unknown variant field #$fid.");
            $value = trim((string)$value);
            if (!in_array($value, $allowed[$fid]['options'], true)) {
                json_err("Invalid value for {$allowed[$fid]['label']}: '$value'.");
            }
            $upsert->execute([$sub['id'], $fid, $value]);
            $applied[$fid] = $value;
        }
        $ev = $GLOBALS['pdo']->prepare('INSERT INTO events (type, subscription_id, meta_json) VALUES (?,?,?)');
        $ev->execute(['swap', $sub['id'], json_encode(['choices' => $applied, 'cycle' => $win['cycle']])]);
        json_out(['applied' => $applied, 'cycle' => $win['cycle']]);
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