<?php
/**
 * Installer — creates the schema and seeds demo data.
 *
 *   GET/POST api/install.php?install_key=...&action=install   (create + seed if empty)
 *   GET/POST api/install.php?install_key=...&action=reset     (drop + recreate + seed)
 *
 * Keep install_key out of production (see config.example.php).
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$key = (string)($_GET['install_key'] ?? ($_POST['install_key'] ?? ''));
if ($key === '' || $key !== ($GLOBALS['config']['install_key'] ?? '')) {
    json_err('Missing or invalid install_key.', 403);
}

$dbName = $GLOBALS['config']['db_name'];
$pdo    = $GLOBALS['pdo'];

// ---- optional hard reset ----
if (($_GET['action'] ?? '') === 'reset') {
    $tables = ['packing_items','packing_runs','shipments','churn_scores','subscriber_signals',
               'dunning_events','payments','box_line_items','addons','skip_requests',
               'customizations','variant_fields','subscriptions','addresses','plans',
               'events','users'];
    foreach ($tables as $t) { $pdo->exec("DROP TABLE IF EXISTS `$t`"); }
}

// ---- create tables ----
$schema = file_get_contents(__DIR__ . '/../db/schema.sql');
if ($schema === false) json_err('schema.sql not found.', 500);
$schema = preg_replace('/--[^\n]*/', '', $schema);            // strip comments
$statements = array_filter(array_map('trim', explode(';', $schema)));
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');   // tolerate any CREATE order on fresh installs
foreach ($statements as $stmt) {
    if ($stmt !== '') { $pdo->exec($stmt); }
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
$pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName`"); // no-op if exists

// ---- seed (only when empty) ----
$chk = $pdo->query('SELECT COUNT(*) c FROM users')->fetch();
if ((int)$chk['c'] > 0) {
    json_out(['message' => "Schema ready. Demo data already present ({$chk['c']} users).",
              'logins' => ['merchant@demo.test / demo1234', 'ops@demo.test / demo1234', 'alice@demo.test / demo1234']]);
}

seed($pdo);

json_out([
    'message'   => 'Installed & seeded.',
    'tables'    => array_column($pdo->query('SHOW TABLES')->fetchAll(), null),
    'logins'    => [
        'merchant@demo.test / demo1234 (Merchant dashboard)',
        'ops@demo.test / demo1234 (Ops console)',
        'alice@demo.test / demo1234 (Subscriber portal)',
    ],
]);

// ============================================================ SEED
function seed(PDO $pdo): void {
    $now   = new DateTime('now', new DateTimeZone($GLOBALS['config']['timezone']));
    $today = $now->format('Y-m-d');
    $d     = fn(int $off) => (clone $now)->modify("$off days")->format('Y-m-d');

    // ---- users ----
    $users = [];
    $mkUser = function (string $name, string $email, string $role, string $phone = '') use (&$users, $pdo) {
        $st = $pdo->prepare('INSERT INTO users (name, email, phone, password_hash, role) VALUES (?,?,?,?,?)');
        $st->execute([$name, $email, $phone, password_hash('demo1234', PASSWORD_DEFAULT), $role]);
        $users[$email] = (int)$pdo->lastInsertId();
    };
    $mkUser('SBC Admin', 'merchant@demo.test', 'merchant', '+60123450001');
    $mkUser('Ops Lead',  'ops@demo.test',      'ops',      '+60123450002');
    $mkUser('Alice Tan', 'alice@demo.test',  'subscriber', '+60123451001');
    $mkUser('Bob Lee',   'bob@demo.test',    'subscriber', '+60123451002');
    $mkUser('Carol Lim', 'carol@demo.test',  'subscriber', '+60123451003');
    $mkUser('Dave Ooi',  'dave@demo.test',   'subscriber', '+60123451004');
    $mkUser('Erin Wong', 'erin@demo.test',   'subscriber', '+60123451005');
    $mkUser('Frank Yap', 'frank@demo.test',  'subscriber', '+60123451006');
    $mkUser('Grace Hoe', 'grace@demo.test',  'subscriber', '+60123451007');
    $mkUser('Henry Goh', 'henry@demo.test',  'subscriber', '+60123451008');
    $mkUser('Ivy Chin',  'ivy@demo.test',    'subscriber', '+60123451009');
    $mkUser('Jack Mah',  'jack@demo.test',   'subscriber', '+60123451010');

    // ---- plans ----
    $plans = [];
    $mkPlan = function (string $name, string $desc, float $price) use (&$plans, $pdo) {
        $st = $pdo->prepare('INSERT INTO plans (name, description, price) VALUES (?,?,?)');
        $st->execute([$name, $desc, $price]);
        $plans[$name] = (int)$pdo->lastInsertId();
    };
    $mkPlan('Snack Box',  'Monthly curated international snacks', 29.99);
    $mkPlan('Beauty Box', 'Monthly clean-beauty discovery set',   39.99);
    $mkPlan('Coffee Club','Monthly single-origin coffee',         24.50);

    // ---- variant fields (Box Customization Engine) ----
    $fields = [];
    $mkField = function (string $plan, string $key, string $label, array $opts) use (&$fields, $plans, $pdo) {
        $st = $pdo->prepare('INSERT INTO variant_fields (plan_id, field_key, field_label, options_json) VALUES (?,?,?,?)');
        $st->execute([$plans[$plan], $key, $label, json_encode($opts)]);
        $fields[$plan . '.' . $key] = (int)$pdo->lastInsertId();
    };
    $mkField('Snack Box', 'flavor', 'Snack flavor', ['Original', 'Spicy', 'Sweet']);
    $mkField('Snack Box', 'size',   'Box size',    ['Regular', 'Large']);
    $mkField('Beauty Box','skin',   'Skin type',   ['Normal', 'Oily', 'Dry', 'Sensitive']);
    $mkField('Beauty Box','shade',  'Makeup shade',['Light', 'Medium', 'Deep']);
    $mkField('Coffee Club','roast', 'Roast profile',['Light', 'Medium', 'Dark']);
    $mkField('Coffee Club','grind', 'Grind',       ['Whole bean', 'Ground']);

    // ---- add-ons ----
    $mkAddon = function (string $plan, string $name, float $price, string $sku) use ($plans, $pdo) {
        $st = $pdo->prepare('INSERT INTO addons (plan_id, name, price, sku) VALUES (?,?,?,?)');
        $st->execute([$plans[$plan], $name, $price, $sku]);
    };
    $mkAddon('Snack Box',  'Extra protein bar',    4.50, 'SNK-PROT');
    $mkAddon('Snack Box',  'Insulated tumbler',   12.00, 'SNK-TMBL');
    $mkAddon('Beauty Box', 'Sheet mask trio',      6.00, 'BTY-MASK');
    $mkAddon('Beauty Box', 'Mini serum',          19.00, 'BTY-SERM');
    $mkAddon('Coffee Club','Limited roast 250g',  18.00, 'COF-LTD');

    // ---- addresses (some deliberately messy for validation demo) ----
    $addr = [];
    $mkAddr = function (string $email, string $l1, string $city, string $state, string $zip) use (&$addr, $users, $pdo) {
        $st = $pdo->prepare("INSERT INTO addresses (user_id, line1, city, state, postal_code, country) VALUES (?,?,?,?,?,'MY')");
        $st->execute([$users[$email], $l1, $city, $state, $zip]);
        $addr[$email] = (int)$pdo->lastInsertId();
    };
    $mkAddr('alice@demo.test', '12 Jalan Sederhana',        'Kota Kinabalu', 'SBH', '88000');
    $mkAddr('bob@demo.test',   '3 Lorong Durian 42100',     'Klang',         'SGR', '42100');   // street noise
    $mkAddr('carol@demo.test', '88 Jln Gaya',               'Kota Kinabalu', 'SBH', '88000');
    $mkAddr('dave@demo.test',  '5 Taman Indah',             'Sandakan',      'SBH', '90000');
    $mkAddr('erin@demo.test',  '99 Jln Istana 88000 KOTA KINABALU SBH', 'Kota Kinabalu', 'SBH', '88000'); // all-in-one line
    $mkAddr('frank@demo.test', '7 Jln Melati',              'Tawau',         'SBH', '91000');
    $mkAddr('grace@demo.test', '21 Jln Pahlawan',           '',              'SBH', '75000');   // missing city
    $mkAddr('henry@demo.test', '45 Jln Damai',              'Penampang',     'SBH', '89500');
    $mkAddr('ivy@demo.test',   '66 Jln Bahagia',            'Keningau',      'SBH', '89000');
    $mkAddr('jack@demo.test',  '1 Jln Baru',                'Putatan',       'SBH', '88200');

    // ---- subscriptions with realistic, future-dated cycles ----
    // Anchor: 1st of NEXT month (change window open) or 1st of THIS month (dunning cases).
    // created_at is back-dated by tenure so churn/forecast math has real history.
    $subs = [];
    $firstLast = (clone $now)->modify('first day of last month')->format('Y-m-d');
    $firstThis = (clone $now)->modify('first day of this month')->format('Y-m-d');
    $firstNext = (clone $now)->modify('first day of next month')->format('Y-m-d');
    $mkSub = function (string $email, string $plan, int $tenureDays, string $status = 'active', string $anchor = 'next')
             use (&$subs, $users, $plans, $addr, $pdo, $firstLast, $firstThis, $firstNext) {
        $next = $anchor === 'current' ? $firstThis : $firstNext;
        $st = $pdo->prepare(
            'INSERT INTO subscriptions (user_id, plan_id, address_id, status, current_period_start, current_period_end, next_charge_at, created_at)
             VALUES (?,?,?,?,?,?,?, DATE_SUB(?, INTERVAL ? DAY))'
        );
        $st->execute([$users[$email], $plans[$plan], $addr[$email], $status, $firstLast, $next, $next, $next, $tenureDays]);
        $subs[$email] = (int)$pdo->lastInsertId();
    };
    $mkSub('alice@demo.test', 'Snack Box',  120);
    $mkSub('bob@demo.test',   'Snack Box',  240, 'active', 'current');   // ← failed payments → dunning
    $mkSub('carol@demo.test', 'Beauty Box',  90);
    $mkSub('dave@demo.test',  'Coffee Club', 15);
    $mkSub('erin@demo.test',  'Beauty Box',  60);
    $mkSub('frank@demo.test', 'Coffee Club', 300);
    $mkSub('grace@demo.test', 'Snack Box',   75, 'paused');
    $mkSub('henry@demo.test', 'Snack Box',  180);
    $mkSub('ivy@demo.test',   'Beauty Box',  150, 'active', 'current');  // ← failed payments → dunning
    $mkSub('jack@demo.test',  'Coffee Club',  5);

    // ---- customizations (swaps already applied) ----
    $mkCust = function (string $email, int|string $fieldId, string $value) use ($subs, $pdo) {
        $st = $pdo->prepare('INSERT INTO customizations (subscription_id, field_id, field_value) VALUES (?,?,?)');
        $st->execute([$subs[$email], $fieldId, $value]);
    };
    $mkCust('alice@demo.test', $fields['Snack Box.flavor'], 'Spicy');
    $mkCust('bob@demo.test',   $fields['Snack Box.flavor'], 'Sweet');
    $mkCust('carol@demo.test', $fields['Beauty Box.skin'],  'Sensitive');
    $mkCust('frank@demo.test', $fields['Coffee Club.roast'],'Dark');
    $mkCust('henry@demo.test', $fields['Snack Box.size'],   'Large');

    // ---- engagement signals for the churn engine ----
    $mkSig = function (string $email, string $type, int $daysAgo) use ($users, $pdo, $d) {
        $st = $pdo->prepare('INSERT INTO subscriber_signals (user_id, signal_type, signal_date) VALUES (?,?,?)');
        $st->execute([$users[$email], $type, $d(-$daysAgo)]);
    };
    // Healthy: frequent opens + login
    $sigh = function (string $e, int $daysAgo, int $count = 1) use ($mkSig) {
        for ($i = 0; $i < $count; $i++) { $mkSig($e, 'email_open', $daysAgo + $i * 2); }
    };
    $sigh('alice@demo.test', 1, 6); $mkSig('alice@demo.test', 'login', 1);
    $sigh('henry@demo.test', 2, 7); $mkSig('henry@demo.test', 'login', 2);
    $sigh('erin@demo.test', 3, 4); $mkSig('erin@demo.test', 'login', 4);
    $sigh('dave@demo.test', 1, 3); $mkSig('dave@demo.test', 'login', 1);
    $sigh('carol@demo.test', 6, 2); $mkSig('carol@demo.test', 'login', 5);
    $mkSig('carol@demo.test', 'ticket', 12);
    // At-risk: no opens, stale login, payment updates, skips
    $mkSig('frank@demo.test', 'payment_update', 3);   // changed card recently = about to leave
    $mkSig('frank@demo.test', 'login', 45);
    $mkSig('bob@demo.test', 'skip', 20); $mkSig('bob@demo.test', 'skip', 55); $mkSig('bob@demo.test', 'login', 70);
    $mkSig('ivy@demo.test', 'login', 90);
    $mkSig('grace@demo.test', 'login', 40);

    // ---- payments (a few failed → dunning subjects) ----
    $mkPay = function (string $email, string $status, float $amount, int $daysAgo, string $reason = '') use ($subs, $pdo, $d) {
        $st = $pdo->prepare(
            'INSERT INTO payments (subscription_id, provider, provider_ref, amount, status, failure_reason, paid_at, created_at)
             VALUES (?,?,?,?,?,?,?,?)'
        );
        $st->execute([$subs[$email], 'demo', 'pay_' . bin2hex(random_bytes(6)), $amount, $status, $reason,
                      $status === 'succeeded' ? $d(-$daysAgo) : null, $d(-$daysAgo)]);
    };
    $mkPay('alice@demo.test', 'succeeded', 29.99, 8);
    $mkPay('bob@demo.test',   'failed',   29.99, 2, 'expired_card');   // ← dunning case
    $mkPay('bob@demo.test',   'failed',   29.99, 30, 'expired_card');
    $mkPay('carol@demo.test', 'succeeded', 39.99, 10);
    $mkPay('dave@demo.test',  'succeeded', 24.50, 4);
    $mkPay('erin@demo.test',  'succeeded', 39.99, 6);
    $mkPay('frank@demo.test', 'succeeded', 24.50, 12);                 // paid, but just updated card
    $mkPay('henry@demo.test', 'succeeded', 29.99, 5);
    $mkPay('ivy@demo.test',   'failed',   39.99, 3, 'insufficient_funds'); // ← dunning case
    $mkPay('grace@demo.test', 'succeeded', 29.99, 20);
    $mkPay('jack@demo.test',  'succeeded', 24.50, 2);

    // ---- upcoming-cycle box line items (aligns with next_charge_at = 1st of next month) ----
    $cycle = $firstNext;
    $mkLine = function (string $email, string $item, string $sku, float $price, int $qty = 1, string $kind = 'base')
              use ($subs, $pdo, $cycle) {
        $st = $pdo->prepare(
            'INSERT INTO box_line_items (subscription_id, cycle_date, kind, item_name, sku, qty, unit_price, total_price)
             VALUES (?,?,?,?,?,?,?,?)'
        );
        $st->execute([$subs[$email], $cycle, $kind, $item, $sku, $qty, $price, $price * $qty]);
    };
    $mkLine('alice@demo.test', 'Snack Box', 'SNK-BASE', 29.99);
    $mkLine('bob@demo.test',   'Snack Box', 'SNK-BASE', 29.99);
    $mkLine('carol@demo.test', 'Beauty Box','BTY-BASE', 39.99);
    $mkLine('dave@demo.test',  'Coffee Club','COF-BASE', 24.50);
    $mkLine('erin@demo.test',  'Beauty Box','BTY-BASE', 39.99);
    $mkLine('frank@demo.test', 'Coffee Club','COF-BASE', 24.50);
    $mkLine('henry@demo.test', 'Snack Box', 'SNK-BASE', 29.99);
    $mkLine('ivy@demo.test',   'Beauty Box','BTY-BASE', 39.99);
    $mkLine('jack@demo.test',  'Coffee Club','COF-BASE', 24.50);
    $mkLine('alice@demo.test', 'Extra protein bar', 'SNK-PROT', 4.50, 2, 'addon');
    $mkLine('henry@demo.test', 'Insulated tumbler','SNK-TMBL', 12.00, 1, 'addon');

    // ---- events ----
    $mkEv = function (string $type, string $email, string $meta, int $daysAgo) use ($users, $subs, $pdo, $d) {
        $st = $pdo->prepare('INSERT INTO events (type, subscription_id, meta_json, created_at) VALUES (?,?,?,?)');
        $st->execute([$type, $subs[$email] ?? null, $meta, $d(-$daysAgo) . ' 10:00:00']);
    };
    $mkEv('swap',  'alice@demo.test', '{"field":"flavor","to":"Spicy"}', 25);
    $mkEv('skip',  'bob@demo.test',   '{"reason":"travelling"}', 20);
    $mkEv('addon_added', 'henry@demo.test', '{"addon":"tumbler"}', 9);

    // ---- a packing run for last month (reference data) ----
    $lastCycle = (clone $now)->modify('first day of last month')->format('Y-m-d');
    $pr = $pdo->prepare('INSERT INTO packing_runs (cycle_date, plan_id, total_boxes, status, created_at) VALUES (?,?,?,?,?)');
    $pr->execute([$lastCycle, $plans['Snack Box'], 3, 'done', $d(-20)]);
    $runId = (int)$pdo->lastInsertId();
    $pi = $pdo->prepare('INSERT INTO packing_items (run_id, sku, item_name, qty) VALUES (?,?,?,?)');
    $pi->execute([$runId, 'SNK-BASE', 'Snack Box Base Kit', 3]);
    $pi->execute([$runId, 'SNK-PROT', 'Extra protein bar',  4]);

    // ---- a couple of shipments for history ----
    $sh = $pdo->prepare(
        'INSERT INTO shipments (subscription_id, cycle_date, carrier, tracking_number, label_url, cost, status, created_at)
         VALUES (?,?,?,?,?,?,?,?)'
    );
    $sh->execute([$subs['alice@demo.test'], $lastCycle, 'local_csv', 'TRK-ALICE-001', 'storage/labels/alice-001.pdf', 4.80, 'delivered', $d(-15)]);
    $sh->execute([$subs['henry@demo.test'], $lastCycle, 'local_csv', 'TRK-HENRY-001', 'storage/labels/henry-001.pdf', 4.80, 'delivered', $d(-15)]);
}