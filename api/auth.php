<?php
/**
 * Auth — register / login / logout / me.
 * Role-based: subscriber, merchant, ops. When demo_mode is on, all roles can be
 * picked at registration; in production only 'subscriber' may self-register.
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$action = $_GET['action'] ?? 'me';
$in     = body();

switch ($action) {

    case 'register': {
        $name  = trim((string)($in['name'] ?? ''));
        $email = strtolower(trim((string)($in['email'] ?? '')));
        $phone = trim((string)($in['phone'] ?? ''));
        $pass  = (string)($in['password'] ?? '');
        $role  = (string)($in['role'] ?? 'subscriber');

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 6) {
            json_err('Name, a valid email, and a password of 6+ characters are required.');
        }
        if (!in_array($role, ['subscriber', 'merchant', 'ops'], true)) json_err('Invalid role.');
        if ($role !== 'subscriber' && !($GLOBALS['config']['demo_mode'] ?? false)) {
            json_err('Merchant/ops accounts must be created by an administrator.');
        }

        $st = $GLOBALS['pdo']->prepare('SELECT id FROM users WHERE email = ?');
        $st->execute([$email]);
        if ($st->fetch()) json_err('An account with that email already exists.', 409);

        $token  = bin2hex(random_bytes(32));
        $expiry = gmdate('Y-m-d H:i:s', time() + (int)$GLOBALS['config']['auth_token_ttl']);
        $ins = $GLOBALS['pdo']->prepare(
            'INSERT INTO users (name, email, phone, password_hash, role, token, token_expires)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([$name, $email, $phone, password_hash($pass, PASSWORD_DEFAULT), $role, $token, $expiry]);

        json_out(['token' => $token, 'user' => ['id' => (int)$GLOBALS['pdo']->lastInsertId(), 'name' => $name, 'email' => $email, 'role' => $role]]);
    }

    case 'login': {
        $email = strtolower(trim((string)($in['email'] ?? '')));
        $pass  = (string)($in['password'] ?? '');
        $st = $GLOBALS['pdo']->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $st->execute([$email]);
        $u = $st->fetch();
        if (!$u || !password_verify($pass, $u['password_hash'])) {
            json_err('Invalid email or password.', 401);
        }
        $token  = bin2hex(random_bytes(32));
        $expiry = gmdate('Y-m-d H:i:s', time() + (int)$GLOBALS['config']['auth_token_ttl']);
        $up = $GLOBALS['pdo']->prepare('UPDATE users SET token = ?, token_expires = ? WHERE id = ?');
        $up->execute([$token, $expiry, $u['id']]);

        json_out(['token' => $token, 'user' => public_defaults($u)]);
    }

    case 'logout': {
        $token = bearer_token();
        if ($token !== '') {
            $up = $GLOBALS['pdo']->prepare("UPDATE users SET token = NULL, token_expires = NULL WHERE token = ?");
            $up->execute([$token]);
        }
        json_out(true);
    }

    case 'me':
    default: {
        $u = require_auth();
        $st = $GLOBALS['pdo']->prepare(
            'SELECT a.* FROM addresses a WHERE a.user_id = ? ORDER BY a.id DESC LIMIT 1'
        );
        $st->execute([$u['id']]);
        $address = $st->fetch() ?: null;

        $subs = [];
        if ($u['role'] === 'subscriber') {
            $s = $GLOBALS['pdo']->prepare(
                'SELECT s.*, p.name AS plan_name, p.price AS plan_price
                 FROM subscriptions s JOIN plans p ON p.id = s.plan_id
                 WHERE s.user_id = ? AND s.status != "canceled" ORDER BY s.id'
            );
            $s->execute([$u['id']]);
            $subs = $s->fetchAll();
        }

        json_out(['user' => public_defaults($u), 'address' => $address, 'subscriptions' => $subs]);
    }
}

function public_defaults(array $u): array {
    unset($u['password_hash'], $u['token'], $u['token_expires']);
    return $u;
}