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

// --- Módulos activables (flip para activar/desactivar sin borrar código) ---
define('MODULO_PLANILLA_ACTIVO', true); // Planilla activa

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
// PIN de verificación en dos pasos (6 díg.) para registrar números NATIVOS en Cloud API
// (POST /{phone_id}/register). Para números nuevos, este valor QUEDA como su PIN.
define('META_WA_REGISTER_PIN',    env('META_WA_REGISTER_PIN',    ''));
define('META_APP_SECRET',         env('META_APP_SECRET',         '')); // firma de webhooks
define('META_APP_ID',             env('META_APP_ID',             '1486329949910978')); // público; usado en el resumable upload del perfil

// ── Meta Conversions API (atribución de cierres de venta a Meta Ads) ──────────
//  Dataset (Pixel) + token de acceso de la Conversions API. Se usan para enviar
//  el evento de cierre de venta a Meta atribuyéndolo por ctwa_clid del referral
//  del click-to-WhatsApp. Ambos viven en .env (nunca en el código).
define('META_DATASET_ID',        env('META_DATASET_ID',        ''));
define('META_CAPI_ACCESS_TOKEN', env('META_CAPI_ACCESS_TOKEN', ''));

// ── Meta Marketing API (gasto en anuncios / insights) ────────────────────────
//  Token de usuario del sistema con permiso ads_read + ID de la cuenta
//  publicitaria (formato act_XXXX). Solo lectura del gasto/rendimiento. .env.
define('META_ADS_TOKEN',      env('META_ADS_TOKEN',      ''));
define('META_AD_ACCOUNT_ID',  env('META_AD_ACCOUNT_ID',  ''));

// ── Agente IA (copiloto del inbox) ────────────────────────────────────────────
//  Conmutable por IA_PROVIDER: 'claude' (Anthropic) u 'openai'. Las llaves viven
//  en .env (fuera del repo), nunca aquí. Sin llave → el copiloto queda inactivo.
define('IA_PROVIDER',       env('IA_PROVIDER',       'claude'));   // auto | gemini | claude | openai | lista "gemini,claude,openai"
define('IA_ANTHROPIC_KEY',  env('IA_ANTHROPIC_KEY',  ''));
define('IA_ANTHROPIC_MODEL',env('IA_ANTHROPIC_MODEL','claude-sonnet-4-5-20250929'));
define('IA_OPENAI_KEY',     env('IA_OPENAI_KEY',     ''));
define('IA_OPENAI_MODEL',   env('IA_OPENAI_MODEL',   'gpt-4o'));
// Gemini (Google AI Studio, tier gratuito). Dos llaves: si la 1ª agota su cuota
// (429), el copiloto reintenta solo con la 2ª (dos cuentas Google distintas).
define('IA_GEMINI_KEY',     env('IA_GEMINI_KEY',     ''));
define('IA_GEMINI_KEY_2',   env('IA_GEMINI_KEY_2',   ''));
define('IA_GEMINI_MODEL',   env('IA_GEMINI_MODEL',   'gemini-flash-latest'));

// ── Alegra — facturación electrónica SUNAT (Perú) ─────────────────────────────
//  Basic Auth = base64(email:token). Credenciales solo en .env (fuera del repo).
//  Base API: https://api.alegra.com/api/v1/
define('ALEGRA_EMAIL', env('ALEGRA_EMAIL', ''));
define('ALEGRA_TOKEN', env('ALEGRA_TOKEN', ''));
define('ALEGRA_API',   'https://api.alegra.com/api/v1');
// Interruptor maestro: '0' = solo BORRADOR (prueba, no va a SUNAT) · '1' = emisión REAL.
// Mientras esté en '0', el botón "Emitir factura" crea borradores en Alegra.
define('ALEGRA_EMITIR_REAL', env('ALEGRA_STAMP', '0') === '1');
// Interruptor independiente para NOTAS DE CRÉDITO: '0' = BORRADOR (default) · '1' = REAL a SUNAT.
// Se mantiene en borrador hasta que el usuario ponga ALEGRA_NC_STAMP=1 en .env.
define('ALEGRA_NC_REAL', env('ALEGRA_NC_STAMP', '0') === '1');
// Cuenta de detracción (Banco de la Nación) — obligatoria para "Operación sujeta a
// detracción" (cliente deposita). Editable en .env si cambia.
define('ALEGRA_DETRACCION_CUENTA', env('ALEGRA_DETRACCION_CUENTA', '00-059-203142'));
