<?php
/**
 * Skip-a-Month — pause a single cycle with one click (before the shipping cutoff).
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib.php';

$user = require_auth(['subscriber']);
$action = $_GET['action'] ?? 'list';
$in     = body();

$sub = fn() => my_sub_or_404($GLOBALS['pdo'], (int)($in['subscription_id'] ?? 0), (int)$user['id']);

switch ($action) {

    case 'list': {
        $st = $GLOBALS['pdo']->prepare(
            'SELECT sk.*, s.plan_id, p.name plan_name
             FROM skip_requests sk
             JOIN subscriptions s ON s.id = sk.subscription_id
             JOIN plans p ON p.id = s.plan_id
             WHERE s.user_id = ? ORDER BY sk.cycle_date DESC'
        );
        $st->execute([$user['id']]);
        json_out($st->fetchAll());
    }

    case 'create': {
        $s   = $sub();
        $win = changes_next_cycle($GLOBALS['pdo'], $s);
        if (!$win['immediate']) {
            json_err("The shipping window for {$s['next_charge_at']} is already closed. This skip applies to the cycle after it.", 409);
        }
        if (cycle_is_skipped($GLOBALS['pdo'], (int)$s['id'], $win['cycle'])) {
            json_err('This cycle is already skipped.', 409);
        }
        $ins = $GLOBALS['pdo']->prepare('INSERT INTO skip_requests (subscription_id, cycle_date, reason) VALUES (?,?,?)');
        $ins->execute([$s['id'], $win['cycle'], trim((string)($in['reason'] ?? ''))]);
        $ev = $GLOBALS['pdo']->prepare('INSERT INTO events (type, subscription_id, meta_json) VALUES (?,?,?)');
        $ev->execute(['skip', $s['id'], json_encode(['cycle' => $win['cycle'], 'reason' => $in['reason'] ?? ''])]);
        $sig = $GLOBALS['pdo']->prepare('INSERT INTO subscriber_signals (user_id, signal_type, signal_date) VALUES (?,?,?)');
        $sig->execute([$user['id'], 'skip', today()]);
        json_out(['skipped' => $win['cycle']]);
    }

    case 'undo': {
        $st = $GLOBALS['pdo']->prepare(
            'SELECT sk.* FROM skip_requests sk JOIN subscriptions s ON s.id = sk.subscription_id
             WHERE sk.id = ? AND s.user_id = ?'
        );
        $st->execute([(int)($in['id'] ?? 0), $user['id']]);
        $row = $st->fetch();
        if (!$row) json_err('Skip request not found.', 404);
        $del = $GLOBALS['pdo']->prepare('DELETE FROM skip_requests WHERE id = ?');
        $del->execute([$row['id']]);
        $sig = $GLOBALS['pdo']->prepare('DELETE FROM subscriber_signals WHERE user_id=? AND signal_type="skip" ORDER BY signal_date DESC LIMIT 1');
        $sig->execute([$user['id']]);
        json_out(['restored' => $row['cycle_date']]);
    }

    default: json_err('Unknown action.');
}

function my_sub_or_404(PDO $pdo, int $id, int $userId): array {
    $st = $pdo->prepare('SELECT * FROM subscriptions WHERE id = ? AND user_id = ? AND status != "canceled"');
    $st->execute([$id, $userId]);
    $sub = $st->fetch();
    if (!$sub) json_err('Subscription not found.', 404);
    return $sub;
}