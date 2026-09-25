<?php
/**
 * Shared domain helpers: subscription cycles, cutoff windows, JSON body helpers.
 */
declare(strict_types=1);

/** Date-only today, in app timezone. */
function today(): string {
    return (new DateTime('now', new DateTimeZone($GLOBALS['config']['timezone'] ?? 'UTC')))->format('Y-m-d');
}

/** WHERE fragment: exclude subscriptions whose most recent payment attempt failed (in dunning). */
const NOT_IN_DUNNING_SQL = "(SELECT pe.status FROM payments pe WHERE pe.subscription_id = s.id ORDER BY pe.created_at DESC LIMIT 1) <> 'failed'";

/** The date on which changes freeze for a given cycle (shipping cutoff). */
function cutoff_date_for(string $cycleDate, int $cutoffDay): string {
    $dt = new DateTime($cycleDate);
    return $dt->format('Y-m') . '-' . str_pad((string)$cutoffDay, 2, '0', STR_PAD_LEFT);
}

/** Are subscriber changes still open for this cycle? */
function changes_open(string $cycleDate, string $today, int $cutoffDay): bool {
    return strcmp($today, cutoff_date_for($cycleDate, $cutoffDay)) < 0;
}

/**
 * Which cycle do the user's changes apply to?
 * Returns ['cycle' => 'Y-m-d', 'immediate' => bool, 'deadline' => 'Y-m-d'].
 */
function changes_next_cycle(PDO $pdo, array $sub): array {
    $next   = $sub['next_charge_at'];
    $cutoff = (int)($GLOBALS['config']['cutoff_day'] ?? 15);
    $open   = changes_open($next, today(), $cutoff);
    if ($open) {
        return ['cycle' => $next, 'immediate' => true, 'deadline' => cutoff_date_for($next, $cutoff)];
    }
    $dt = new DateTime($next);
    $dt->modify('+1 month');
    $shifted = $dt->format('Y-m-d');
    return ['cycle' => $shifted, 'immediate' => false, 'deadline' => cutoff_date_for($shifted, $cutoff)];
}

/** Is a subscription already skipping the given cycle? */
function cycle_is_skipped(PDO $pdo, int $subscriptionId, string $cycle): bool {
    $st = $pdo->prepare('SELECT COUNT(*) c FROM skip_requests WHERE subscription_id = ? AND cycle_date = ?');
    $st->execute([$subscriptionId, $cycle]);
    return (int)$st->fetch()['c'] > 0;
}

/** Every subscriber (with signals) — used by churn + merchant lists. */
function all_active_subscriptions(PDO $pdo): array {
    return $pdo->query(
        'SELECT s.*, u.name subscriber_name, u.email subscriber_email, p.name plan_name, p.price plan_price
         FROM subscriptions s
         JOIN users u ON u.id = s.user_id
         JOIN plans p ON p.id = s.plan_id
         WHERE s.status = "active"
         ORDER BY s.id'
    )->fetchAll();
}

/** Engagement feature vector for a subscription (churn input). */
function churn_features(PDO $pdo, array $sub): array {
    $uid = (int)$sub['user_id'];
    $f = ['uid' => $uid, 'sub_id' => (int)$sub['id']];

    $q = fn(string $sql, array $p = []) => ($st = $pdo->prepare($sql))->execute($p) ? $st->fetchColumn() : 0;

    $f['opens_30d']          = (int)$q('SELECT COUNT(*) FROM subscriber_signals WHERE user_id=? AND signal_type="email_open"  AND signal_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)', [$uid]);
    $f['clicks_30d']         = (int)$q('SELECT COUNT(*) FROM subscriber_signals WHERE user_id=? AND signal_type="email_click" AND signal_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)', [$uid]);
    $f['skips_90d']          = (int)$q('SELECT COUNT(*) FROM subscriber_signals WHERE user_id=? AND signal_type="skip" AND signal_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)', [$uid]);
    $f['tickets_90d']        = (int)$q('SELECT COUNT(*) FROM subscriber_signals WHERE user_id=? AND signal_type="ticket" AND signal_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)', [$uid]);
    $f['payment_updates_90d']= (int)$q('SELECT COUNT(*) FROM subscriber_signals WHERE user_id=? AND signal_type="payment_update" AND signal_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)', [$uid]);

    $lastLogin = $q('SELECT signal_date FROM subscriber_signals WHERE user_id=? AND signal_type="login" ORDER BY signal_date DESC LIMIT 1', [$uid]);
    $f['last_login_days']     = $lastLogin ? max(0, (int)((strtotime(today()) - strtotime((string)$lastLogin)) / 86400)) : 365;

    $lastPay = $q('SELECT MAX(paid_at) FROM payments WHERE subscription_id=? AND status="succeeded"', [$sub['id']]);
    $f['last_paid_days']      = $lastPay ? max(0, (int)((strtotime(today()) - strtotime((string)$lastPay)) / 86400)) : 999;

    $failed = $q('SELECT COUNT(*) FROM payments WHERE subscription_id=? AND status="failed"', [$sub['id']]);
    $f['failed_payments']     = (int)$failed;

    $daysAgo = max(1, (int)((strtotime(today()) - strtotime((string)$sub['created_at'])) / 86400));
    $f['tenure_days']         = $daysAgo;
    $f['status']              = $sub['status'];
    return $f;
}

/** Deterministic rule-based churn score 0..100 (fallback + mixed signal). */
function rules_churn_score(array $f): array {
    $s = 5;
    if ($f['opens_30d'] === 0)   $s += 28;
    if ($f['opens_30d'] === 1)   $s += 14;
    if ($f['opens_30d'] >= 5)    $s -= 10;
    if ($f['clicks_30d'] === 0)  $s += 8;
    if ($f['skips_90d'] >= 2)    $s += 18;
    if ($f['skips_90d'] === 1)   $s += 8;
    if ($f['tickets_90d'] >= 2)  $s += 10;
    if ($f['payment_updates_90d'] > 0) $s += 20;
    if ($f['failed_payments'] > 0)     $s += 20;
    if ($f['last_login_days'] > 30)    $s += 12;
    if ($f['last_login_days'] < 3)     $s -= 7;
    if ($f['last_paid_days'] > 40)     $s += 15;
    if ($f['tenure_days'] < 30)        $s -= 6;
    $s = max(0, min(98, $s));
    $risk = $s >= 60 ? 'high' : ($s >= 35 ? 'medium' : 'low');
    return ['score' => $s, 'risk' => $risk, 'source' => 'rules'];
}