<?php
/**
 * Address Validation Tool — screens addresses at checkout to cut return-to-sender costs.
 * Providers: 'demo' (built-in rules, no keys), 'loqate' / 'smartystreets' (plug real keys in config).
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$action = $_GET['action'] ?? 'validate';
if ($action !== 'validate') json_err('Unknown action.');

$in = body();
$addr = [
    'line1'       => trim((string)($in['line1'] ?? '')),
    'line2'       => trim((string)($in['line2'] ?? '')),
    'city'        => trim((string)($in['city'] ?? '')),
    'state'       => trim((string)($in['state'] ?? '')),
    'postal_code' => trim((string)($in['postal_code'] ?? '')),
    'country'     => strtoupper(trim((string)($in['country'] ?? 'US'))),
];

if ($addr['line1'] === '' || $addr['city'] === '' || $addr['postal_code'] === '') {
    json_err('line1, city and postal_code are required.');
}

$provider = strtolower((string)($GLOBALS['config']['address_provider'] ?? 'demo'));
$result   = match ($provider) {
    'loqate'        => loqate_validate($addr),
    'smartystreets' => smartystreets_validate($addr),
    default         => demo_validate($addr),
};

// Persist best-known address when a user id is provided
if (!empty($in['save']) && !empty($in['user_id'])) {
    $st = $GLOBALS['pdo']->prepare(
        'INSERT INTO addresses (user_id, line1, line2, city, state, postal_code, country, validated, validation_source, created_at)
         VALUES (?,?,?,?,?,?,?,?,?,NOW())'
    );
    $st->execute([(int)$in['user_id'], $result['address']['line1'] ?? $addr['line1'], $addr['line2'],
                  $result['address']['city'] ?? $addr['city'], $result['address']['state'] ?? $addr['state'],
                  $result['address']['postal_code'] ?? $addr['postal_code'], $addr['country'],
                  $result['valid'] ? 1 : 0, $provider]);
}

json_out($result);

/** Built-in rules: catches empty/garbage postal codes, obvious typos, missing city. */
function demo_validate(array $a): array {
    $issues = [];
    $fixed  = $a;

    // Postal sanity — must match the country pattern (US ZIP or MY 5-digit).
    $zipPattern = $a['country'] === 'MY' ? '/^\d{5}$/' : '/^\d{5}(-\d{4})?$/';
    if (!preg_match($zipPattern, $a['postal_code'])) {
        $issues[] = "Postal code '{$a['postal_code']}' does not look valid for {$a['country']}.";
    }
    if ($a['country'] === 'MY' && preg_match('/^\d{5}$/', $a['postal_code']) && $a['postal_code'] === '00000') {
        $issues[] = 'Postal code 00000 is a placeholder — please confirm the real code.';
    }
    if ($a['country'] === 'US' && $a['postal_code'] !== '' && strlen(preg_replace('/\D/', '', $a['postal_code'])) < 5) {
        $issues[] = 'US ZIP code must have at least 5 digits.';
    }

    // Common street-type shorthand normalisation.
    $sh = ['str' => 'St', 'st.' => 'St', 'avenue' => 'Ave', 'ave.' => 'Ave', 'road' => 'Rd',
           'boulevard' => 'Blvd', 'blvd.' => 'Blvd', 'lane' => 'Ln', 'drive' => 'Dr',
           'jalan' => 'Jln', 'jln.' => 'Jln', 'street' => 'St'];
    $lower = strtolower($fixed['line1']);
    foreach ($sh as $from => $to) {
        if ($lower !== strtolower($to) && preg_match('/\b' . preg_quote($from, '/') . '\b/i', $fixed['line1'])) {
            $fixed['line1'] = preg_replace('/\b' . preg_quote($from, '/') . '\b/i', $to, $fixed['line1']);
            $issues[] = "Standardised street type: '$from' → '$to'.";
            break; // one pass is enough
        }
    }
    // Line1 → city duplication (whole address crammed in one field).
    $cityLower = strtolower($fixed['city']);
    foreach (['KOTA KINABALU', 'KUALA LUMPUR', 'TAWAU', 'SANDAKAN', 'PENAMPANG', 'KLANG', 'PUTATAN'] as $known) {
        if (stripos($fixed['line1'], $known) !== false && stripos($fixed['city'], $known) === false) {
            $issues[] = "City '{$known}' appears in the street line — split your address properly.";
            break;
        }
    }

    // Postal code crammed into the street line.
    if (preg_match('/\b\d{5}\b/', $fixed['line1'])) {
        $issues[] = "A 5-digit postal code appears in the street line — remove it (postal code is its own field).";
    }

    // Missing city.
    if ($fixed['city'] === '') $issues[] = 'City is required and was missing.';

    $valid = count($issues) === 0;
    return ['valid' => $valid, 'issues' => $issues, 'address' => $fixed,
            'source' => 'demo', 'score' => $valid ? 100 : max(30, 100 - count($issues) * 25)];
}

/** Loqate (Address Verification) — drop-in adapter for a real account. */
function loqate_validate(array $a): array {
    $key = $GLOBALS['config']['loqate_api_key'] ?? '';
    if ($key === '') return ['valid' => false, 'issues' => ['Loqate key not configured — using demo rules.'], 'address' => $a, 'source' => 'loqate', 'score' => 0, 'fallback' => demo_validate($a)];
    $q = http_build_query([
        'Key' => $key,
        'Text' => implode(', ', array_filter([$a['line1'] . ' ' . $a['line2'], $a['city'], $a['state'], $a['postal_code']])),
        'Countries' => $a['country'],
    ]);
    $json = @file_get_contents("https://api.addressy.com/Capture/Interactive/Find/v1.00/json3.ws?$q");
    $data = json_decode((string)$json, true);
    $items = $data['Items'] ?? [];
    return ['valid' => !empty($items), 'issues' => empty($items) ? ['No verified match.'] : [],
            'address' => $items[0]['Text'] ?? $a, 'source' => 'loqate', 'score' => empty($items) ? 0 : 100];
}

/** SmartyStreets US — drop-in adapter. */
function smartystreets_validate(array $a): array {
    $key = $GLOBALS['config']['smartystreets_key'] ?? '';
    if ($key === '') return ['valid' => false, 'issues' => ['SmartyStreets key not configured — using demo rules.'], 'address' => $a, 'source' => 'smartystreets', 'score' => 0, 'fallback' => demo_validate($a)];
    $payload = [[
        'street' => $a['line1'] . ' ' . $a['line2'],
        'city'   => $a['city'],
        'state'  => $a['state'],
        'zipcode'=> $a['postal_code'],
    ]];
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => json_encode($payload)]]);
    $json = @file_get_contents("https://us-street.api.smartystreets.com/street-address?auth-id=$key", false, $ctx);
    $data = json_decode((string)$json, true);
    $hit = $data[0]['components'] ?? null;
    return ['valid' => (bool)$hit, 'issues' => $hit ? [] : ['No USPS match.'],
            'address' => $hit ? [
                'line1' => ($data[0]['delivery_line_1'] ?? $a['line1']),
                'city' => $hit['city_name'] ?? $a['city'], 'state' => $hit['state_abbreviation'] ?? $a['state'],
                'postal_code' => ($hit['zipcode'] ?? '') . ($hit['plus4_code'] ? '-' . $hit['plus4_code'] : ''),
            ] : $a, 'source' => 'smartystreets', 'score' => $hit ? 100 : 0];
}