<?php
/**
 * Dynamic Inventory Forecasting — projects box demand per plan and suggests order quantities.
 * Math: active subs × (1 + growth)^m × (1 − churn)^m × (1 − skip rate), plus safety stock.
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib.php';

require_auth(['merchant', 'ops']);
if (($_GET['action'] ?? '') !== 'forecast') json_err('Unknown action.');

$months     = max(1, min(12, (int)($_GET['months'] ?? 6)));
$safetyPct  = max(0, min(50, (int)($_GET['safety'] ?? 10)));
$churnInput = (float)($_GET['churn'] ?? -1);          // -1 = derive from data
$growthInput= (float)($_GET['growth'] ?? -1);         // -1 = derive from last-90d signups

$pdo = $GLOBALS['pdo'];

// ---- baseline per plan ----
$stmt = $pdo->query(
    'SELECT p.id, p.name, p.price,
            (SELECT COUNT(*) FROM subscriptions s WHERE s.plan_id = p.id AND s.status = "active") active_subs,
            (SELECT COUNT(*) FROM subscriptions s WHERE s.plan_id = p.id AND s.created_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)) new_90d,
            (SELECT COUNT(*) FROM subscriptions s WHERE s.plan_id = p.id AND s.status IN ("paused","skipped")) paused_skipped,
            (SELECT COUNT(*) FROM events e JOIN subscriptions s ON s.id = e.subscription_id
              WHERE s.plan_id = p.id AND e.type IN ("skip","cancel") AND e.created_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)) lost_90d
     FROM plans p WHERE p.is_active = 1 ORDER BY p.id'
);
$plans = $stmt->fetchAll();

// ---- aggregate skip rate across all plans ----
$skipRate = (float)$pdo->query(
    'SELECT COALESCE(COUNT(*),0) / GREATEST((SELECT COUNT(*) FROM subscriptions WHERE status="active"),1)
     FROM skip_requests'
)->fetchColumn();

$out = ['generated_at' => today(), 'assumptions' => [], 'plans' => []];

foreach ($plans as $plan) {
    $active  = max(1, (int)$plan['active_subs']);
    $growth  = $growthInput >= 0 ? $growthInput
             : round($plan['new_90d'] / 3.0 / $active * 100, 1);            // monthly % growth from 90d signups
    $churn   = $churnInput >= 0 ? $churnInput
             : round($plan['lost_90d'] / 3.0 / $active * 100, 1);           // monthly % lost from 90d events

    $projection = [];
    $projectedActive = (float)$active;
    for ($m = 1; $m <= $months; $m++) {
        $projectedActive = $projectedActive * (1 + $growth / 100) * (1 - $churn / 100);
        $boxes = round($projectedActive * (1 - $skipRate), 1);
        $projection[] = [
            'month'          => (new DateTime("first day of +$m month"))->format('Y-m'),
            'active_subs'    => round($projectedActive),
            'expected_boxes' => $boxes,
            'ordered_qty'    => (int)ceil($boxes * (1 + $safetyPct / 100)),
        ];
    }

    $out['plans'][] = [
        'plan_id'     => (int)$plan['id'],
        'plan'        => $plan['name'],
        'active_subs' => $active,
        'growth_rate' => $growth,
        'churn_rate'  => $churn,
        'skip_rate'   => round($skipRate * 100, 1),
        'projection'  => $projection,
    ];
}

$out['assumptions'] = [
    'skip_rate'      => round($skipRate * 100, 1) . '%',
    'safety_stock'   => $safetyPct . '%',
    'growth_mode'    => $growthInput >= 0 ? 'manual' : 'from last-90d signups',
    'churn_mode'     => $churnInput >= 0 ? 'manual' : 'from last-90d lost events',
];

json_out($out);