<?php
/**
 * Churn Prediction Alerts.
 * Primary: scores via the free Colab/Ollama endpoint (OpenAI-compatible).
 * Fallback: deterministic rules when the tunnel is down/unreachable.
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib.php';

require_auth(['merchant', 'ops']);
$action = $_GET['action'] ?? 'list';
$in     = body();

switch ($action) {

    /** Re-score every active subscription now (AI with rules fallback). */
    case 'score_all': {
        $subs = all_active_subscriptions($GLOBALS['pdo']);
        $results = [];
        foreach ($subs as $sub) {
            $results[] = score_one($GLOBALS['pdo'], $sub);
        }
        json_out(['scored' => count($results), 'results' => $results]);
    }

    /** Latest scores (persisted), optionally filtered by risk. */
    case 'list': {
        $risk = (string)($_GET['risk'] ?? '');
        $sql = 'SELECT cs.*, s.plan_id, u.name subscriber_name, u.email subscriber_email,
                       p.name plan_name, s.status
                FROM churn_scores cs
                JOIN subscriptions s ON s.id = cs.subscription_id
                JOIN users u ON u.id = s.user_id
                JOIN plans p ON p.id = s.plan_id
                WHERE cs.scored_at = (
                    SELECT MAX(cs2.scored_at) FROM churn_scores cs2 WHERE cs2.subscription_id = cs.subscription_id
                )';
        $params = [];
        if ($risk !== '') { $sql .= ' AND cs.risk = ?'; $params[] = $risk; }
        $sql .= ' ORDER BY cs.score DESC';
        $st = $GLOBALS['pdo']->prepare($sql);
        $st->execute($params);
        json_out($st->fetchAll());
    }

    default: json_err('Unknown action.');
}

/** Score one subscription: try AI, fall back to rules; persist the result. */
function score_one(PDO $pdo, array $sub): array {
    $features = churn_features($pdo, $sub);
    $cfg      = $GLOBALS['config'];

    $result = null;
    if (!empty($cfg['ollama_enabled']) && !empty($cfg['ollama_url'])) {
        $result = ai_churn_score($config = $cfg, $features);
    }
    if (!$result) {
        $result = rules_churn_score($features);
    }

    $ins = $pdo->prepare(
        'INSERT INTO churn_scores (subscription_id, score, risk, source, features_json) VALUES (?,?,?,?,?)'
    );
    $ins->execute([$sub['id'], $result['score'], $result['risk'], $result['source'], json_encode($features)]);

    return ['subscription_id' => (int)$sub['id'], 'subscriber' => $sub['subscriber_name'],
            'features' => $features, 'score' => $result['score'], 'risk' => $result['risk'],
            'source' => $result['source'], 'reasons' => $result['reasons'] ?? []];
}

/** Ask the remote Ollama (via tunnel) for a churn score; null on any failure. */
function ai_churn_score(array $config, array $features): ?array {
    $url = rtrim($config['ollama_url'], '/') . '/chat/completions';
    $model = $config['ollama_model'] ?? 'qwen2.5-coder:7b';

    $payload = [
        'model' => $model,
        'stream' => false,
        'temperature' => 0.2,
        'messages' => [
            ['role' => 'system', 'content' =>
                "You are a subscription-business churn analyst. Given subscriber engagement features, return ONLY a " .
                "compact JSON object, no prose: {\"score\": <int 0-100>, \"risk\": \"low|medium|high\", \"reasons\": [\"<max 3 short reasons>\"]}."],
            ['role' => 'user', 'content' => "Features: " . json_encode($features)],
        ],
    ];
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => json_encode($payload),
            'timeout' => (int)($config['ollama_timeout'] ?? 15),
            'ignore_errors' => true,
        ],
    ]);
    $raw  = @file_get_contents($url, false, $ctx);
    if ($raw === false) return null;

    $data = json_decode($raw, true);
    $text = trim((string)($data['choices'][0]['message']['content'] ?? ''));
    if ($text === '') return null;

    // Model may wrap in ```json fences
    if (preg_match('/\{.*\}/s', $text, $m)) $text = $m[0];
    $j = json_decode($text, true);
    if (!is_array($j) || !isset($j['score'])) return null;

    $score = max(0, min(100, (int)$j['score']));
    $risk  = in_array($j['risk'] ?? '', ['low', 'medium', 'high'], true) ? $j['risk'] : ($score >= 60 ? 'high' : ($score >= 35 ? 'medium' : 'low'));
    return ['score' => $score, 'risk' => $risk, 'source' => 'ai', 'reasons' => (array)($j['reasons'] ?? [])];
}