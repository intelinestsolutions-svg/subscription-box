<?php
/**
 * Merchant dashboard — KPIs, subscriber roster, revenue series.
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib.php';

require_auth(['merchant', 'ops']);
$action = $_GET['action'] ?? 'summary';

switch ($action) {

    case 'summary': {
        $pdo = $GLOBALS['pdo'];
        $q = fn(string $sql, array $p = []) => ($st = $pdo->prepare($sql))->execute($p) ? $st->fetchColumn() : 0;

        $activeSubs = (int)$q('SELECT COUNT(*) FROM subscriptions WHERE status = "active"');
        $mrr        = (float)$q('SELECT COALESCE(SUM(p.price),0) FROM subscriptions s JOIN plans p ON p.id = s.plan_id WHERE s.status = "active"');
        $paidMonth  = (float)$q('SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = "succeeded" AND created_at >= DATE_FORMAT(CURDATE(), "%Y-%m-01")');
        $failedCount= (int)$q('SELECT COUNT(*) FROM payments WHERE status = "failed"');
        $skipCount  = (int)$q('SELECT COUNT(*) FROM skip_requests WHERE cycle_date >= CURRENT_DATE()');
        $atRisk     = (int)$q('SELECT COUNT(*) FROM churn_scores cs WHERE cs.score >= 60 AND cs.scored_at = (SELECT MAX(cs2.scored_at) FROM churn_scores cs2 WHERE cs2.subscription_id = cs.subscription_id)');
        $dunning    = (int)$q('SELECT COUNT(*) FROM subscriptions s WHERE s.status = "active" AND EXISTS (SELECT 1 FROM payments pe WHERE pe.subscription_id = s.id AND pe.status = "failed") AND NOT EXISTS (SELECT 1 FROM payments pe2 WHERE pe2.subscription_id = s.id AND pe2.status = "succeeded" AND pe2.created_at > (SELECT MAX(pe3.created_at) FROM payments pe3 WHERE pe3.subscription_id = s.id AND pe3.status = "failed"))');
        $toShip     = (int)$q("SELECT COUNT(*) FROM subscriptions s WHERE s.status = 'active' AND s.next_charge_at = DATE_FORMAT(CURDATE(), '%Y-%m-01') AND NOT EXISTS (SELECT 1 FROM skip_requests sk WHERE sk.subscription_id = s.id AND sk.cycle_date = s.next_charge_at) AND NOT EXISTS (SELECT 1 FROM shipments sh WHERE sh.subscription_id = s.id AND sh.cycle_date = s.next_charge_at) AND " . NOT_IN_DUNNING_SQL);

        json_out([
            'active_subscribers' => $activeSubs,
            'mrr'                => $mrr,
            'paid_this_month'    => $paidMonth,
            'failed_payments'    => $failedCount,
            'skips_this_month'   => $skipCount,
            'at_risk'            => $atRisk,
            'dunning_subjects'   => $dunning,
            'to_ship_this_cycle' => $toShip,
            'as_of'              => today(),
        ]);
    }

    case 'subscribers': {
        $pdo = $GLOBALS['pdo'];
        $page = max(1, (int)($_GET['page'] ?? 1));
        $per  = 25;
        $where = '';
        $params = [];
        if (($q = trim((string)($_GET['q'] ?? ''))) !== '') {
            $where = ' WHERE u.name LIKE ? OR u.email LIKE ? OR p.name LIKE ?';
            $params = ["%$q%", "%$q%", "%$q%"];
        }
        $cnt = $pdo->prepare('SELECT COUNT(*) FROM subscriptions s JOIN users u ON u.id = s.user_id JOIN plans p ON p.id = s.plan_id' . $where);
        $cnt->execute($params);
        $total = (int)$cnt->fetchColumn();

        $st = $pdo->prepare(
            'SELECT s.id, s.status, s.next_charge_at, s.created_at,
                    u.name subscriber_name, u.email subscriber_email, u.phone,
                    p.name plan_name, p.price plan_price,
                    (SELECT cs.score FROM churn_scores cs WHERE cs.subscription_id = s.id ORDER BY cs.scored_at DESC LIMIT 1) churn_score,
                    (SELECT cs.risk FROM churn_scores cs WHERE cs.subscription_id = s.id ORDER BY cs.scored_at DESC LIMIT 1) churn_risk,
                    (SELECT pe.status FROM payments pe WHERE pe.subscription_id = s.id ORDER BY pe.created_at DESC LIMIT 1) last_payment,
                    (SELECT a.line1 FROM addresses a WHERE a.id = s.address_id) address,
                    (SELECT a.validated FROM addresses a WHERE a.id = s.address_id) address_validated
             FROM subscriptions s
             JOIN users u ON u.id = s.user_id
             JOIN plans p ON p.id = s.plan_id' . $where . '
             ORDER BY churn_score DESC, s.id DESC
             LIMIT ' . (int)$per . ' OFFSET ' . (int)(($page - 1) * $per)
        );
        $st->execute($params);

        json_out(['page' => $page, 'per' => $per, 'total' => $total, 'rows' => $st->fetchAll()]);
    }

    case 'revenue': {
        $st = $GLOBALS['pdo']->query(
            'SELECT DATE_FORMAT(paid_at, "%Y-%m") ym, SUM(amount) total, COUNT(*) count
             FROM payments WHERE status = "succeeded" AND paid_at IS NOT NULL
             GROUP BY ym ORDER BY ym DESC LIMIT 6'
        );
        json_out(array_reverse($st->fetchAll()));
    }

    default: json_err('Unknown action.');
}