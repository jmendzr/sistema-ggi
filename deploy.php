<?php
/**
 * deploy.php — Webhook de despliegue (GitHub → servidor).
 *
 * En cada push a `main`, GitHub llama a este endpoint por HTTPS; verificamos la
 * firma HMAC (secreto compartido) y, si es válida, hacemos que el servidor quede
 * EXACTAMENTE igual a origin/main (fetch + reset --hard).
 *
 * Configuración en GitHub → repo → Settings → Webhooks:
 *   Payload URL:  https://sistema.groupglobaliso.com/deploy.php
 *   Content type: application/json
 *   Secret:       (el valor de DEPLOY_SECRET del .env)
 *   Eventos:      Just the push event
 *
 * Seguridad: el secreto vive en .env (fuera del repo). Sin firma válida → 401.
 */

header('Content-Type: application/json');

// ── Solo POST ──
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'msg' => 'method not allowed']);
    exit;
}

// ── Leer el secreto desde .env (fuera de public_html) ──
$secret = '';
$env_lines = @file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
foreach ($env_lines as $line) {
    if (strpos($line, 'DEPLOY_SECRET=') === 0) { $secret = trim(substr($line, 14)); break; }
}
if ($secret === '') {
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => 'deploy secret not configured']);
    exit;
}

// ── Verificar firma HMAC de GitHub ──
$payload = file_get_contents('php://input');
$sig     = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);
if ($sig === '' || !hash_equals($expected, $sig)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'msg' => 'invalid signature']);
    exit;
}

// ── Filtrar evento / rama ──
$event = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '';
if ($event === 'ping') { echo json_encode(['ok' => true, 'msg' => 'pong']); exit; }
if ($event !== 'push') { echo json_encode(['ok' => true, 'msg' => 'ignored (event: ' . $event . ')']); exit; }

$data = json_decode($payload, true);
if (($data['ref'] ?? '') !== 'refs/heads/main') {
    echo json_encode(['ok' => true, 'msg' => 'ignored (ref: ' . ($data['ref'] ?? '?') . ')']);
    exit;
}

// ── Desplegar: dejar el servidor idéntico a origin/main ──
$repo = __DIR__;
$git  = '/usr/bin/git';
$cmd  = 'HOME=/home/groupglo ' . $git . ' -C ' . escapeshellarg($repo)
      . ' fetch --quiet origin main 2>&1 && '
      . 'HOME=/home/groupglo ' . $git . ' -C ' . escapeshellarg($repo)
      . ' reset --hard origin/main 2>&1';
$output = shell_exec($cmd);
$commit = trim((string) shell_exec($git . ' -C ' . escapeshellarg($repo) . ' rev-parse --short HEAD 2>&1'));

// ── Log (no exponer detalles en la respuesta) ──
$ts  = date('Y-m-d H:i:s');
$who = $data['head_commit']['message'] ?? '';
@file_put_contents(
    $repo . '/logs/deploy.log',
    "[$ts] deploy → $commit | " . str_replace("\n", ' ', trim($who)) . "\n" . trim((string)$output) . "\n\n",
    FILE_APPEND | LOCK_EX
);

echo json_encode(['ok' => true, 'msg' => 'deployed', 'commit' => $commit]);
