<?php
// ============================================================
//  GGI SISTEMA — Configuración principal
//  Las credenciales se cargan desde .env (nunca hardcodear aquí)
// ============================================================

// --- Cargador de .env ---
(function () {
    $env_file = __DIR__ . '/../../.env';
    if (!file_exists($env_file)) return;
    $lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (!str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);
        if ($key !== '' && !isset($_ENV[$key])) {
            $_ENV[$key] = $value;
            putenv("$key=$value");
        }
    }
})();

// --- Función helper para leer variables de entorno ---
function env(string $key, string $default = ''): string {
    return $_ENV[$key] ?? getenv($key) ?: $default;
}

// --- Base de datos ---
define('DB_HOST',    env('DB_HOST',    'localhost'));
define('DB_NAME',    env('DB_NAME',    ''));
define('DB_USER',    env('DB_USER',    ''));
define('DB_PASS',    env('DB_PASS',    ''));
define('DB_CHARSET', env('DB_CHARSET', 'utf8mb4'));

// --- Aplicación ---
define('APP_NAME',    'GGI Sistema');
define('APP_VERSION', '1.0.0');
define('APP_URL',     env('APP_URL', 'https://sistema.groupglobaliso.com'));
define('APP_TIMEZONE','America/Lima');

// --- Entorno (production | development) ---
define('APP_ENV', env('APP_ENV', 'production'));

// --- Mostrar errores solo en desarrollo ---
if (APP_ENV === 'development') {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    error_reporting(0);
}

// --- Sesión ---
define('SESSION_NAME',    'ggi_session');
define('SESSION_LIFETIME', 3600 * 24 * 30); // 30 días

// --- Uploads ---
define('UPLOAD_PATH', '/home/groupglo/domains/sistema.groupglobaliso.com/public_html/documentaciones_clientes/');
define('UPLOAD_URL',  'https://sistema.groupglobaliso.com/documentaciones_clientes/');
define('MAX_FILE_SIZE',  10 * 1024 * 1024); // 10 MB
define('ALLOWED_TYPES',  ['application/pdf', 'image/jpeg', 'image/png', 'image/webp']);
define('ALLOWED_EXT',    ['pdf', 'jpg', 'jpeg', 'png', 'webp']);

// --- SUNAT / API Perú ---
define('SUNAT_API_URL',   'https://apiperu.dev/api/ruc');
define('SUNAT_API_TOKEN', env('SUNAT_API_TOKEN', ''));
// Credencial de la API de tracking de Olva Courier.
// Debe definirse exclusivamente en .env; nunca en el código fuente.
define('OLVA_TRACKING_API_KEY', env('OLVA_TRACKING_API_KEY', ''));
define('DIAS_ALERTA_VENCIMIENTO', 90);
define('DIAS_ALERTA_AUDITORIA',   60);

date_default_timezone_set(APP_TIMEZONE);

// --- CRON ---
// Token para disparar crons por web (?token=...). Los crons vía CLI NO lo usan.
// Se lee de .env (CRON_TOKEN). Si faltara, se genera uno aleatorio por proceso
// para que nunca sea un valor predecible/adivinable.
define('CRON_TOKEN', env('CRON_TOKEN', '') ?: bin2hex(random_bytes(32)));

// --- Web Push (VAPID) ---
define('VAPID_PUBLIC_KEY',  env('VAPID_PUBLIC_KEY',  ''));
define('VAPID_PRIVATE_KEY', env('VAPID_PRIVATE_KEY', ''));

// --- Kapso AI — WhatsApp ---
define('KAPSO_EMBED_TOKEN',   env('KAPSO_EMBED_TOKEN',   ''));
define('KAPSO_API_KEY_1',     env('KAPSO_API_KEY_1',     ''));
define('KAPSO_PHONE_ID_1',    env('KAPSO_PHONE_ID_1',    ''));
define('KAPSO_EMBED_TOKEN_2', env('KAPSO_EMBED_TOKEN_2', ''));
define('KAPSO_API_KEY_2',     env('KAPSO_API_KEY_2',     ''));
define('KAPSO_PHONE_ID_2',    env('KAPSO_PHONE_ID_2',    ''));
// Token de verificación para webhook de Meta (generar uno aleatorio y agregar en .env)
define('WA_WEBHOOK_VERIFY_TOKEN', env('WA_WEBHOOK_VERIFY_TOKEN', ''));
// Cuenta dedicada para notificaciones automáticas
define('KAPSO_PHONE_NUMBER_ID', env('KAPSO_PHONE_NUMBER_ID', ''));
define('KAPSO_API_KEY',         env('KAPSO_API_KEY',         ''));
define('KAPSO_EMBED_TOKEN_3',   env('KAPSO_EMBED_TOKEN_3',   ''));

// ── WhatsApp: proveedor activo ────────────────────────────────────────────────
//  'kapso' = proxy actual (por defecto)  |  'meta' = Cloud API nativa de Meta.
//  El payload es idéntico en ambos (Kapso solo proxeaba a Meta); cambian la URL
//  base y el header de autenticación. Cutover/rollback = cambiar WA_PROVIDER.
define('WA_PROVIDER',             env('WA_PROVIDER',             'kapso'));
define('META_WA_VERSION',         env('META_WA_VERSION',         'v21.0'));
define('META_WA_PHONE_NUMBER_ID', env('META_WA_PHONE_NUMBER_ID', ''));
define('META_WA_TOKEN',           env('META_WA_TOKEN',           ''));
define('META_WA_WABA_ID',         env('META_WA_WABA_ID',         ''));
define('META_APP_SECRET',         env('META_APP_SECRET',         '')); // firma de webhooks
