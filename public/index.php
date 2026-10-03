<?php

declare(strict_types=1);

use Bitenxt\SupportAgent\App;
use Bitenxt\SupportAgent\Config;

require dirname(__DIR__) . '/vendor/autoload.php';

// Never let PHP print warnings, paths or stack traces to the client.
ini_set('display_errors', '0');
error_reporting(E_ALL);

$config = Config::fromEnvironment(dirname(__DIR__) . '/.env');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Local `php -S` only: let the built-in server serve static pages (demo.html,
// staff.html). In the container, Apache serves them before PHP runs.
if (PHP_SAPI === 'cli-server' && preg_match('#^/[\w-]+\.html$#', (string) $path) && is_file(__DIR__ . $path)) {
    return false;
}

// --- CORS: only the configured frontends (and this service's own demo page) may call the API ---
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$originAllowed = in_array($origin, $config->allowedOrigins, true)
    || ($origin !== '' && preg_replace('#^https?://#', '', $origin) === ($_SERVER['HTTP_HOST'] ?? null));
if ($origin !== '' && $originAllowed) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Max-Age: 600');
}
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$respond = static function (int $status, array $body): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($path === '/health') {
    $respond(200, ['ok' => true]);
}
if ($path === '/widget.js') {
    header('Content-Type: application/javascript; charset=utf-8');
    header('Cache-Control: public, max-age=300');
    readfile(__DIR__ . '/widget.js');
    exit;
}
$routes = [
    'POST /chat' => 'chat',
    'GET /chat/history' => 'history',
    'GET /staff/api/escalated' => 'staff_escalated',
    'GET /staff/api/customer' => 'staff_customer',
    'GET /staff/api/conversation' => 'staff_conversation',
];
$route = $routes[$method . ' ' . $path] ?? null;
if ($route === null) {
    $respond(404, ['error' => 'not_found']);
}
$isStaff = str_starts_with($route, 'staff_');
$sameOrigin = $origin !== '' && preg_replace('#^https?://#', '', $origin) === ($_SERVER['HTTP_HOST'] ?? null);
// Customer endpoints: allowed frontends only. Staff endpoints: only the staff
// page served by this service (no other site can call them from a browser).
if ($origin !== '' && ($isStaff ? !$sameOrigin : !$originAllowed)) {
    $respond(403, ['error' => 'origin_not_allowed']);
}

// Behind Cloud Run (and any load balancer) REMOTE_ADDR is the proxy, not the
// visitor. Each trusted proxy appends the address it saw to X-Forwarded-For,
// so count back that many entries; anything further left is client-supplied.
$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if ($config->trustedProxyHops > 0 && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $hops = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
    $clientIp = $hops[count($hops) - $config->trustedProxyHops] ?? $clientIp;
}

// Chat is for logged-in Pro customers only: the Pro frontend forwards the
// customer's Magento token, and requests without a valid one get 401. The
// token is only used for this request and is never stored or sent to the model.
$token = null;
if (preg_match('/^Bearer\s+([A-Za-z0-9._\-]{10,2048})$/', $_SERVER['HTTP_AUTHORIZATION'] ?? '', $m)) {
    $token = $m[1];
}

$scheme = ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'http' && $config->trustedProxyHops > 0 ? 'http' : 'https';
$host = $_SERVER['HTTP_HOST'] ?? '';
$publicBaseUrl = preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$/', $host) ? $scheme . '://' . $host : '';

try {
    [$chat, $staff] = App::services($config, $publicBaseUrl);

    if ($route === 'chat') {
        $body = json_decode((string) file_get_contents('php://input', length: 16384), true);
        if (!is_array($body) || !is_string($body['message'] ?? null)) {
            $respond(400, ['error' => 'message_required']);
        }
        $result = $chat->handle($body['message'], $token, $clientIp);
    } elseif ($route === 'history') {
        $result = $chat->history($token, $clientIp);
    } else {
        $result = $staff->authorize($_SERVER['HTTP_X_STAFF_KEY'] ?? null, $clientIp) ?? match ($route) {
            'staff_escalated' => $staff->escalated($clientIp),
            'staff_customer' => $staff->customer((string) ($_GET['email'] ?? ''), $clientIp),
            'staff_conversation' => $staff->conversation((string) ($_GET['id'] ?? ''), $clientIp),
        };
    }

    $status = $result['status'];
    unset($result['status']);
    $respond($status, $result);
} catch (\Throwable $e) {
    error_log('[support-agent] ' . $e::class . ': ' . $e->getMessage());
    $respond(500, [
        'error' => 'internal_error',
        'reply' => "Sorry, something went wrong on our side. Please try again shortly.",
    ]);
}
