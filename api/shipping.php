<?php
/**
 * Bulk Shipping Label Generation.
 * Queue: active, non-skipped subscriptions due in a cycle. Generate → purchase labels
 * through the configured carrier (DHL/FedEx/local CSV). One run = hundreds/thousands of labels.
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib.php';
require_once __DIR__ . '/shipping/Providers.php';

require_auth(['merchant', 'ops']);
$action = $_GET['action'] ?? 'queue';
$in     = body();
$cfg    = $GLOBALS['config'];

switch ($action) {

    /** Available carriers + configured flag. */
    case 'carriers': {
        json_out(ShippingProviderRegistry::status($cfg));
    }

    /** Who ships in a given cycle (default: this month) + per-cycle summary. */
    case 'queue': {
        $cycle = (string)($_GET['cycle'] ?? date('Y-m-01'));
        $rows = $GLOBALS['pdo']->prepare(
            'SELECT s.id subscription_id, u.name, u.email, u.phone,
                    a.line1, a.line2, a.city, a.state, a.postal_code, a.country, a.validated,
                    p.name plan_name, s.next_charge_at
             FROM subscriptions s
             JOIN users u ON u.id = s.user_id
             JOIN plans p ON p.id = s.plan_id
             LEFT JOIN addresses a ON a.id = s.address_id
             WHERE s.status = "active"
               AND s.next_charge_at = ?
               AND NOT EXISTS (SELECT 1 FROM skip_requests sk WHERE sk.subscription_id = s.id AND sk.cycle_date = s.next_charge_at)
               AND NOT EXISTS (SELECT 1 FROM shipments sh WHERE sh.subscription_id = s.id AND sh.cycle_date = ?)
             ORDER BY u.name'
        );
        $rows->execute([$cycle, $cycle]);
        json_out(['cycle' => $cycle, 'count' => $rows->rowCount(), 'rows' => $rows->fetchAll()]);
    }

    /** Generate labels for the cycle (default carrier from config, or ?carrier=dhl|fedex|local_csv). */
    case 'generate': {
        $cycle   = (string)($in['cycle'] ?? date('Y-m-01'));
        $carrier = strtolower((string)($in['carrier'] ?? $cfg['default_carrier'] ?? 'local_csv'));
        $rows = $GLOBALS['pdo']->prepare(
            'SELECT s.id subscription_id, u.name, u.email, u.phone,
                    a.line1, a.line2, a.city, a.state, a.postal_code, a.country, a.validated,
                    p.name plan_name, p.price
             FROM subscriptions s
             JOIN users u ON u.id = s.user_id
             JOIN plans p ON p.id = s.plan_id
             LEFT JOIN addresses a ON a.id = s.address_id
             WHERE s.status = "active" AND s.next_charge_at = ?
               AND NOT EXISTS (SELECT 1 FROM skip_requests sk WHERE sk.subscription_id = s.id AND sk.cycle_date = s.next_charge_at)
               AND NOT EXISTS (SELECT 1 FROM shipments sh WHERE sh.subscription_id = s.id AND sh.cycle_date = ?)'
        );
        $rows->execute([$cycle, $cycle]);
        $rows = $rows->fetchAll();

        // Blocker: unvalidated addresses → surface for correction instead of shipping.
        $unvalidated = array_values(array_filter($rows, fn($r) => empty($r['validated'])));
        if ($unvalidated && empty($in['force'])) {
            json_err(count($unvalidated) . ' subscription(s) have unvalidated addresses. Fix them in the Address
                    Validation tool or resend with force=true.', 409);
        }
        if (!$rows) json_out(['generated' => 0, 'note' => 'Nothing to ship for ' . $cycle]);

        $provider = ShippingProviderRegistry::make($carrier);
        $made = $fail = 0; $errors = [];
        foreach ($rows as $r) {
            $items = box_items_for($GLOBALS['pdo'], (int)$r['subscription_id'], $cycle);
            $res = $provider->createLabel([
                'cycle' => $cycle,
                'to'    => ['name' => $r['name'], 'email' => $r['email'], 'phone' => $r['phone'],
                            'line1' => $r['line1'], 'line2' => $r['line2'], 'city' => $r['city'],
                            'state' => $r['state'], 'postal_code' => $r['postal_code'], 'country' => $r['country']],
                'items' => $items,
            ], $cfg);

            if (!$res['success']) {
                $fail++;
                $errors[] = ['subscription_id' => (int)$r['subscription_id'], 'name' => $r['name'], 'message' => $res['message'] ?? 'failed'];
                continue;
            }
            $ins = $GLOBALS['pdo']->prepare(
                'INSERT INTO shipments (subscription_id, cycle_date, carrier, tracking_number, label_url, cost, status)
                 VALUES (?,?,?,?,?,?,"label_purchased")'
            );
            $ins->execute([$r['subscription_id'], $cycle, $carrier, $res['tracking_number'], $res['label_url'], $res['cost']]);
            $made++;
        }

        json_out(['cycle' => $cycle, 'carrier' => $carrier, 'generated' => $made, 'failed' => $fail, 'errors' => $errors]);
    }

    /** Shipment history. */
    case 'shipments': {
        $st = $GLOBALS['pdo']->query(
            'SELECT sh.*, u.name subscriber_name, p.name plan_name
             FROM shipments sh
             JOIN subscriptions s ON s.id = sh.subscription_id
             JOIN users u ON u.id = s.user_id
             JOIN plans p ON p.id = s.plan_id
             ORDER BY sh.created_at DESC LIMIT 200'
        );
        json_out($st->fetchAll());
    }

    default: json_err('Unknown action.');
}

function box_items_for(PDO $pdo, int $subscriptionId, string $cycle): array {
    $st = $pdo->prepare('SELECT item_name FROM box_line_items WHERE subscription_id = ? AND cycle_date = ? ORDER BY kind');
    $st->execute([$subscriptionId, $cycle]);
    return array_column($st->fetchAll(), 'item_name') ?: ['Standard box'];
}