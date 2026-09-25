<?php
/**
 * Kitting & Packing Lists — per plan + cycle: which items go in every box variant,
 * aggregated quantities so the warehouse can pick once and kit N boxes.
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib.php';

require_auth(['merchant', 'ops']);
$action = $_GET['action'] ?? 'runs';
$in     = body();

switch ($action) {

    /** Existing packing runs. */
    case 'runs': {
        $st = $GLOBALS['pdo']->query(
            'SELECT pr.*, p.name plan_name
             FROM packing_runs pr JOIN plans p ON p.id = pr.plan_id
             ORDER BY pr.cycle_date DESC, pr.id DESC LIMIT 100'
        );
        json_out($st->fetchAll());
    }

    /** Items for a specific run. */
    case 'items': {
        $st = $GLOBALS['pdo']->prepare('SELECT * FROM packing_items WHERE run_id = ? ORDER BY item_name');
        $st->execute([(int)($_GET['run_id'] ?? 0)]);
        json_out($st->fetchAll());
    }

    /** Generate the packing list for a cycle (default: current month). */
    case 'generate': {
        $cycle = (string)($in['cycle'] ?? date('Y-m-01'));

        $subs = $GLOBALS['pdo']->prepare(
            'SELECT s.id, s.plan_id, p.name plan_name,
                    (SELECT GROUP_CONCAT(CONCAT(f.field_label, ": ", c.field_value) SEPARATOR ", ")
                     FROM customizations c JOIN variant_fields f ON f.id = c.field_id
                     WHERE c.subscription_id = s.id) variant_summary,
                    (SELECT GROUP_CONCAT(li.item_name SEPARATOR " + ")
                     FROM box_line_items li WHERE li.subscription_id = s.id AND li.cycle_date = ? AND li.kind = "addon") addons
             FROM subscriptions s JOIN plans p ON p.id = s.plan_id
             WHERE s.status = "active" AND s.next_charge_at = ?
               AND NOT EXISTS (SELECT 1 FROM skip_requests sk WHERE sk.subscription_id = s.id AND sk.cycle_date = s.next_charge_at)
               AND ' . NOT_IN_DUNNING_SQL . '
             ORDER BY s.plan_id, s.id'
        );
        $subs->execute([$cycle, $cycle]);
        $subs = $subs->fetchAll();

        if (!$subs) json_out(['generated' => false, 'note' => "No boxes to pack for $cycle."]);

        // Per plan: grouped kitting lines + per-box breakdown
        $perPlan = [];
        $created = [];
        foreach ($subs as $s) {
            $pid = (int)$s['plan_id'];
            if (!isset($created[$pid])) {
                $ins = $GLOBALS['pdo']->prepare(
                    'INSERT INTO packing_runs (cycle_date, plan_id, total_boxes, status) VALUES (?,?,?,? )'
                );
                $ins->execute([$cycle, $pid, 0, 'pending']);
                $created[$pid] = (int)$GLOBALS['pdo']->lastInsertId();
                $perPlan[$pid] = ['plan_id' => $pid, 'plan_name' => $s['plan_name'], 'boxes' => [], 'quantities' => []];
            }
            $runId = $created[$pid];
            $perPlan[$pid]['boxes'][] = [
                'subscription_id' => (int)$s['id'],
                'variant'  => $s['variant_summary'] ?: 'Standard',
                'addons'   => $s['addons'] ?: '—',
            ];
            $perPlan[$pid]['quantities']['Snack Box Base Kit'] = ($perPlan[$pid]['quantities']['Snack Box Base Kit'] ?? 0) + 1;
            if ($s['addons']) {
                foreach (explode(' + ', $s['addons']) as $addon) {
                    $perPlan[$pid]['quantities'][trim($addon)] = ($perPlan[$pid]['quantities'][trim($addon)] ?? 0) + 1;
                }
            }
        }

        foreach ($perPlan as $pid => &$pp) {
            $pp['total_boxes'] = count($pp['boxes']);
            $up = $GLOBALS['pdo']->prepare('UPDATE packing_runs SET total_boxes = ? WHERE id = ?');
            $up->execute([$pp['total_boxes'], $created[$pid]]);
            $pi = $GLOBALS['pdo']->prepare('INSERT INTO packing_items (run_id, sku, item_name, qty) VALUES (?,?,?,?)');
            foreach ($pp['quantities'] as $name => $qty) {
                $pi->execute([$created[$pid], sku_for_name($name), $name, $qty]);
            }
            $pp['run_id'] = $created[$pid];
        }

        json_out(['cycle' => $cycle, 'generated' => true, 'plans' => array_values($perPlan)]);
    }

    default: json_err('Unknown action.');
}

function sku_for_name(string $name): string {
    $map = ['Snack Box Base Kit' => 'SNK-BASE', 'Beauty Box' => 'BTY-BASE', 'Coffee Club' => 'COF-BASE',
            'Extra protein bar' => 'SNK-PROT', 'Insulated tumbler' => 'SNK-TMBL',
            'Sheet mask trio' => 'BTY-MASK', 'Mini serum' => 'BTY-SERM', 'Limited roast 250g' => 'COF-LTD'];
    return $map[$name] ?? strtoupper(preg_replace('/[^A-Z0-9]+/', '-', substr($name, 0, 12)));
}