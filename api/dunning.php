<?php
/**
 * Failed Payment Recovery (Dunning).
 * Subjects: subscriptions whose latest payment failed (and no successful payment since).
 * Sequence: day 2 email · day 5 email · day 8 SMS — then the box is at risk of skipping.
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib.php';
require_once __DIR__ . '/notify/Sender.php';

require_auth(['merchant', 'ops']);
$action = $_GET['action'] ?? 'subjects';
$in     = body();
$cfg    = $GLOBALS['config'];

switch ($action) {

    /** Subscriptions currently in dunning, with the stage reached so far. */
    case 'subjects': {
        $rows = $GLOBALS['pdo']->query(
            'SELECT s.id, s.next_charge_at, u.name subscriber_name, u.email subscriber_email, u.phone,
                    p.name plan_name,
                    (SELECT MAX(pay2.created_at) FROM payments pay2
                      WHERE pay2.subscription_id = s.id AND pay2.status = "failed") last_failed_at,
                    (SELECT pay3.failure_reason FROM payments pay3
                      WHERE pay3.subscription_id = s.id AND pay3.status = "failed"
                      ORDER BY pay3.created_at DESC LIMIT 1) failure_reason,
                    (SELECT COUNT(*) FROM payments pay4
                      WHERE pay4.subscription_id = s.id AND pay4.status = "succeeded"
                      AND pay4.created_at >= COALESCE((SELECT MAX(pay5.created_at) FROM payments pay5
                        WHERE pay5.subscription_id = s.id AND pay5.status = "failed"), "1900-01-01")) paid_since_failure,
                    (SELECT MAX(de.stage) FROM dunning_events de WHERE de.subscription_id = s.id) stage_reached,
                    (SELECT MAX(de.sent_at) FROM dunning_events de WHERE de.subscription_id = s.id) last_touched
             FROM subscriptions s
             JOIN users u ON u.id = s.user_id
             JOIN plans p ON p.id = s.plan_id
             WHERE s.status = "active"
               AND EXISTS (SELECT 1 FROM payments pe WHERE pe.subscription_id = s.id AND pe.status = "failed")
               AND NOT EXISTS (SELECT 1 FROM payments pe2
                    WHERE pe2.subscription_id = s.id AND pe2.status = "succeeded"
                    AND pe2.created_at > (SELECT MAX(pe3.created_at) FROM payments pe3
                                          WHERE pe3.subscription_id = s.id AND pe3.status = "failed"))
             ORDER BY last_failed_at DESC'
        )->fetchAll();

        foreach ($rows as &$r) {
            $r['days_since_failure'] = $r['last_failed_at']
                ? max(0, (int)((strtotime(today()) - strtotime((string)$r['last_failed_at'])) / 86400)) : null;
            $r['next_stage'] = dunning_next_stage((int)$r['stage_reached'], (int)$r['days_since_failure']);
        }
        json_out($rows);
    }

    /** Run the flow for one (or all) subject(s). */
    case 'run': {
        $subjects = subjects_with_failure($GLOBALS['pdo']);
        if (isset($in['subscription_id'])) {
            $subjects = array_values(array_filter($subjects, fn($s) => (int)$s['id'] === (int)$in['subscription_id']));
        }
        if (!$subjects) json_out(['sent' => [], 'note' => 'No dunning subjects.']);

        $sent = [];
        foreach ($subjects as $sub) {
            $sent[] = send_next_stage($GLOBALS['pdo'], $cfg, $sub);
        }
        json_out(['sent' => $sent]);
    }

    /** Notification log (where 'log' mode writes). */
    case 'log': {
        $file = rtrim((string)$cfg['storage_dir'], '/') . '/logs/notifications.log';
        $lines = is_file($file) ? array_slice(file($file, FILE_IGNORE_NEW_LINES), -50) : [];
        json_out(array_map(fn($l) => json_decode($l, true), array_filter($lines, 'strlen')));
    }

    default: json_err('Unknown action.');
}

function subjects_with_failure(PDO $pdo): array {
    return $pdo->query(
        'SELECT s.id, s.next_charge_at, u.name subscriber_name, u.email subscriber_email, u.phone, p.name plan_name,
                (SELECT MAX(pay.created_at) FROM payments pay WHERE pay.subscription_id = s.id AND pay.status = "failed") last_failed_at,
                (SELECT MAX(de.stage) FROM dunning_events de WHERE de.subscription_id = s.id) stage_reached
         FROM subscriptions s
         JOIN users u ON u.id = s.user_id
         JOIN plans p ON p.id = s.plan_id
         WHERE s.status = "active"
            AND EXISTS (SELECT 1 FROM payments pe WHERE pe.subscription_id = s.id AND pe.status = "failed")
            AND NOT EXISTS (SELECT 1 FROM payments pe2 WHERE pe2.subscription_id = s.id AND pe2.status = "succeeded"
                AND pe2.created_at > (SELECT MAX(pe3.created_at) FROM payments pe3
                                      WHERE pe3.subscription_id = s.id AND pe3.status = "failed"))
         ORDER BY last_failed_at DESC'
    )->fetchAll();
}

/** Which stage should fire next, based on days since the failure? */
function dunning_next_stage(int $stageReached, int $daysSince): ?array {
    $plan = [
        1 => ['day' => 2,  'channel' => 'email', 'subject' => "Payment failed — keep your box coming"],
        2 => ['day' => 5,  'channel' => 'email', 'subject' => "Last chance: update your payment method"],
        3 => ['day' => 8,  'channel' => 'sms',   'subject' => 'Payment reminder'],
    ];
    $next = $stageReached + 1;
    $s    = $plan[$next] ?? null;
    if (!$s || $daysSince < $s['day']) return ['stage' => $next, 'due_in_days' => max(0, $s['day'] - $daysSince), 'fired' => false];
    return ['stage' => $next, 'due_in_days' => 0, 'fired' => true, 'channel' => $s['channel'], 'subject' => $s['subject']];
}

/** Fire the current stage's channel (email or SMS) and record the dunning event. */
function send_next_stage(PDO $pdo, array $cfg, array $sub): array {
    $days = max(0, (int)((strtotime(today()) - strtotime((string)$sub['last_failed_at'])) / 86400));
    $nf   = dunning_next_stage((int)$sub['stage_reached'], $days);
    if (empty($nf['fired'])) {
        return ['subscription_id' => (int)$sub['id'], 'skipped' => 'not due yet', 'next' => $nf];
    }
    $stage = $nf['stage'];
    $email = (string)($sub['subscriber_email'] ?? $sub['email'] ?? '');
    $link  = rtrim((string)($cfg['app_url'] ?? ''), '/') . '/subscriber/portal.html';

    $messages = [
        1 => "Hi {$sub['subscriber_name']}, your {$sub['plan_name']} payment of the next cycle failed. Update your payment method so we can keep your box coming: $link",
        2 => "Hi {$sub['subscriber_name']}, we still couldn't charge for your {$sub['plan_name']}. Update your card by today or your box will be skipped this month: $link",
        3 => "{$sub['subscriber_name']}, your {$sub['plan_name']} box will be skipped unless you update payment now: $link",
    ];

    $result = $nf['channel'] === 'sms'
        ? Sender::sms($cfg, (string)($sub['phone'] ?? ''), $messages[$stage])
        : Sender::email($cfg, $email, $nf['subject'], $messages[$stage]);

    $pay = $pdo->prepare('SELECT id FROM payments WHERE subscription_id = ? AND status = "failed" ORDER BY created_at DESC LIMIT 1');
    $pay->execute([$sub['id']]);
    $paymentId = $pay->fetchColumn() ?: null;

    $ins = $pdo->prepare(
        'INSERT INTO dunning_events (subscription_id, payment_id, stage, channel, subject, body) VALUES (?,?,?,?,?,?)'
    );
    $ins->execute([$sub['id'], $paymentId, $stage, $nf['channel'], $nf['subject'], $messages[$stage]]);

    return ['subscription_id' => (int)$sub['id'], 'stage' => $stage, 'channel' => $nf['channel'],
            'to' => $nf['channel'] === 'sms' ? (string)($sub['phone'] ?? '') : $email, 'result' => $result];
}