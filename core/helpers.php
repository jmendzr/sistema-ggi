<?php
// ============================================================
//  GGI SISTEMA — Funciones globales de ayuda
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/Database.php';

// --- SESIÓN ---

function session_init(): void {
    if (session_status() === PHP_SESSION_NONE) {
        // ── Directorio privado de sesiones ────────────────────────────────────
        // Usar una ruta fuera de public_html y de /tmp evita que el GC del
        // sistema (cron de cPanel o PHP-FPM daemon) elimine archivos de sesión
        // usando el gc_maxlifetime global de php.ini (1440 s por defecto).
        // Solo el GC disparado por nuestras propias peticiones (que ya hicieron
        // ini_set más abajo) tocará este directorio.
        $sess_dir = '/home/groupglo/sessions';
        if (!is_dir($sess_dir)) {
            @mkdir($sess_dir, 0700, true);
        }
        if (is_dir($sess_dir) && is_writable($sess_dir)) {
            session_save_path($sess_dir);
        }

        session_name(SESSION_NAME);
        // gc_maxlifetime debe ser >= lifetime de la cookie, o el servidor
        // elimina los datos de sesión antes de que expire la cookie.
        // El .user.ini y .htaccess también lo fijan a nivel proceso/vhost,
        // pero ini_set aquí garantiza el valor correcto en ESTE request.
        ini_set('session.gc_maxlifetime', (string) SESSION_LIFETIME);
        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path'     => '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        // Permitir caché privado para que el botón "Atrás" del navegador funcione
        session_cache_limiter('private_no_expire');
        session_start();
    }
}

function auth_user(): ?array {
    session_init();
    return $_SESSION['usuario'] ?? null;
}

function is_logged(): bool {
    return auth_user() !== null;
}

function require_login(): void {
    pwa_log('require_login');
    if (!is_logged()) {
        pwa_log('require_login:REDIRECT_TO_LOGIN');
        header('Location: ' . APP_URL . '/modules/auth/login.php');
        exit;
    }
}

// ─── Diagnóstico iOS PWA ──────────────────────────────────────────────────────
// Escribe en /home/groupglo/logs/pwa_debug.log únicamente para User-Agents iOS.
// Activar: crear el archivo /home/groupglo/logs/.pwa_debug_on
// Desactivar: eliminar ese archivo (o dejar de crearlo)
function pwa_log(string $ctx): void {
    static $enabled = null;
    if ($enabled === null) {
        $enabled = file_exists('/home/groupglo/logs/.pwa_debug_on');
    }
    if (!$enabled) return;

    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (!str_contains($ua, 'iPhone') && !str_contains($ua, 'iPad')) return;

    $log_dir  = '/home/groupglo/logs';
    $log_file = $log_dir . '/pwa_debug.log';

    if (!is_dir($log_dir)) @mkdir($log_dir, 0750, true);

    // Rotar si supera 400 KB
    if (@filesize($log_file) > 409600) {
        $lines = @file($log_file);
        if ($lines) @file_put_contents($log_file, implode('', array_slice($lines, -300)));
    }

    $sid    = session_id() ?: '-';
    $uid    = ($_SESSION['usuario']['id'] ?? null) !== null
              ? (string)$_SESSION['usuario']['id'] : 'no-user';
    $cookie = isset($_COOKIE[SESSION_NAME])
              ? substr($_COOKIE[SESSION_NAME], 0, 12) . '…' : 'NO-COOKIE';
    $sstat  = match (session_status()) {
        PHP_SESSION_DISABLED => 'DISABLED',
        PHP_SESSION_NONE     => 'NONE',
        PHP_SESSION_ACTIVE   => 'ACTIVE',
        default              => '?',
    };

    $line = implode(' | ', [
        date('Y-m-d H:i:s'),
        "[$ctx]",
        'URI='  . substr($_SERVER['REQUEST_URI']  ?? '-', 0, 120),
        'REF='  . substr($_SERVER['HTTP_REFERER'] ?? '-', 0,  80),
        'SID='  . $sid,
        'UID='  . $uid,
        'COOK=' . $cookie,
        'SESS=' . $sstat,
        'UA='   . substr($ua, 0, 80),
    ]) . "\n";

    @file_put_contents($log_file, $line, FILE_APPEND | LOCK_EX);
}

function has_role(string ...$roles): bool {
    $user = auth_user();
    return $user && in_array($user['rol'], $roles, true);
}

function require_role(string ...$roles): void {
    require_login();
    if (!has_role(...$roles)) {
        http_response_code(403);
        include __DIR__ . '/../templates/403.php';
        exit;
    }
}

// Permisos por módulo
function can(string $modulo, string $accion = 'ver'): bool {
    $user = auth_user();
    if (!$user) return false;

    $rol = $user['rol'];

    // gerente_general siempre tiene acceso total (inmutable por seguridad)
    if ($rol === 'gerente_general') return true;

    // ── Intentar leer permisos desde la BD (cache estático por request) ──
    static $_rp = null;
    if ($_rp === null) {
        try {
            $rows = Database::get()
                ->query("SELECT rol, modulo FROM rol_permisos WHERE activo = 1")
                ->fetchAll(\PDO::FETCH_ASSOC);
            $_rp = [];
            foreach ($rows as $r) {
                $_rp[$r['rol']][] = $r['modulo'];
            }
        } catch (\Throwable $e) {
            $_rp = false; // tabla aún no instalada → usar fallback
        }
    }

    if ($_rp !== false) {
        return in_array($modulo, $_rp[$rol] ?? [], true);
    }

    // ── Fallback: permisos hardcodeados originales (tabla no instalada) ──
    static $_hc = [
        'gerente_operaciones' => ['clientes','certificados','cotizaciones','envios','auditorias','documentos','reportes','comunicaciones','notificaciones','bonos','metas','pagos','whatsapp','inbox','mantenimiento'],
        // 'inbox' (bandeja multiagente) sí lo tienen los ejecutivos; 'whatsapp'
        // (bandejas embebidas de Kapso) sigue siendo solo de gerencia.
        'ejecutivo_ventas'    => ['clientes','certificados','cotizaciones','envios','documentos','comunicaciones','notificaciones','bonos','metas','pagos','inbox'],
        'gestor_logistico'    => ['envios','certificados','notificaciones'],
        'delivery'            => ['envios','clientes_ver','notificaciones'],
    ];

    return in_array($modulo, $_hc[$rol] ?? [], true);
}

// --- CSRF ---

function csrf_token(): string {
    // Cache estático: evita re-abrir la sesión tras session_write_close()
    static $_cached = null;
    if ($_cached !== null) return $_cached;
    session_init();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    $_cached = $_SESSION['csrf_token'];
    return $_cached;
}

function csrf_field(): string {
    return '<input type="hidden" name="_csrf" value="' . csrf_token() . '">';
}

/**
 * Compacta un listado de normas para mostrarlo en notificaciones.
 * El primer ítem conserva el prefijo "ISO"; los siguientes solo el número.
 * Ej: "ISO 9001, ISO 14001, ISO 37001" → "ISO 9001, 14001, 37001".
 * Normas no-ISO (BPM, HACCP…) se dejan intactas.
 */
function gg_normas_compact(string $raw): string {
    $parts = array_filter(array_map('trim', explode(',', trim($raw))), fn($x) => $x !== '');
    $out = [];
    $first = true;
    foreach ($parts as $p) {
        if ($first) { $out[] = $p; $first = false; }
        else        { $out[] = preg_replace('/^ISO\s+/i', '', $p); }
    }
    return implode(', ', $out);
}

/**
 * Ícono + color + etiqueta para una notificación, por tipo/event_code (canónico global).
 * Devuelve ['color','bg','icon'(paths SVG feather),'label']. Cubre alertas legadas
 * y eventos de NotificationService; fallback por prefijo de dominio y genérico final.
 */
function gg_notif_icono(string $tipo): array {
    static $IC = [
        'truck'   => '<rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>',
        'box'     => '<path d="M12.89 1.45l8 4A2 2 0 0 1 22 7.24v9.53a2 2 0 0 1-1.11 1.79l-8 4a2 2 0 0 1-1.79 0l-8-4a2 2 0 0 1-1.1-1.8V7.24a2 2 0 0 1 1.11-1.79l8-4a2 2 0 0 1 1.78 0z"/><polyline points="2.32 6.16 12 11 21.68 6.16"/><line x1="12" y1="22.76" x2="12" y2="11"/>',
        'card'    => '<rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/>',
        'receipt' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
        'doccheck'=> '<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
        'doc'     => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>',
        'shield'  => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/>',
        'user'    => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>',
        'folder'  => '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/><polyline points="9 14 11 16 15 12"/>',
        'generic' => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
    ];
    $C = [
        'purple' => ['#8b5cf6', 'var(--s-purple-bg)'],
        'blue'   => ['#1d4ed8', 'var(--s-info-bg)'],
        'cyan'   => ['#0ea5e9', '#e0f2fe'],
        'green'  => ['var(--s-ok-tx)', 'var(--s-ok-bg)'],
        'amber'  => ['#d97706', 'var(--s-warn-bg)'],
        'red'    => ['#dc2626', 'var(--s-err-bg)'],
        'info'   => ['var(--s-info-tx)', 'var(--s-info-bg)'],
        'gray'   => ['#6b7a99', 'var(--bg-surface2)'],
    ];
    $mk = fn(string $ic, string $c, string $label) => ['color' => $C[$c][0], 'bg' => $C[$c][1], 'icon' => $IC[$ic], 'label' => $label];

    static $MAP = null;
    if ($MAP === null) {
        $MAP = [
            // Alertas legadas
            'cert_por_vencer'     => ['color' => '#d97706', 'bg' => 'var(--s-warn-bg)', 'icon' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="12 6 12 12 16 14"/>', 'label' => 'Certificado por vencer'],
            'cert_vencido'        => ['color' => '#dc2626', 'bg' => 'var(--s-err-bg)',  'icon' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>', 'label' => 'Certificado vencido'],
            'auditoria_proxima'   => ['color' => '#3b82f6', 'bg' => 'var(--s-info-bg)', 'icon' => '<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>', 'label' => 'Auditoría próxima'],
            'envio_sin_confirmar' => ['color' => '#8b5cf6', 'bg' => 'var(--s-purple-bg)', 'icon' => '<rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>', 'label' => 'Envío sin confirmar'],
            'cotizacion_vencida'  => ['color' => 'var(--s-ok-tx)', 'bg' => 'var(--s-ok-bg)', 'icon' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/>', 'label' => 'Propuesta por vencer'],
            'pago_voucher'        => ['color' => '#1d4ed8', 'bg' => 'var(--s-info-bg)', 'icon' => '<rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/>', 'label' => 'Voucher de pago subido'],
            'cliente_nuevo'       => ['color' => 'var(--s-info-tx)', 'bg' => 'var(--s-info-bg)', 'icon' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/>', 'label' => 'Nuevo cliente'],
            'descuento_excedido'  => ['color' => '#dc2626', 'bg' => 'var(--s-err-bg)', 'icon' => '<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/><line x1="18" y1="6" x2="6" y2="18"/>', 'label' => 'Descuento excedido'],
            // Eventos de NotificationService
            'propuesta_aceptada'             => $mk('doccheck', 'green', 'Propuesta aceptada'),
            'empresa_documentacion_completa' => $mk('folder',   'info',  'Documentación completa'),
            'certificados_cargados'          => $mk('shield',   'green', 'Certificados cargados'),
            'factura_emitida'                => $mk('receipt',  'cyan',  'Factura emitida'),
            'factura_por_cargar'             => $mk('receipt',  'amber', 'Factura por cargar'),
            'pago_registrado'                => $mk('card',     'blue',  'Voucher de pago registrado'),
            'pago_aprobado'                  => $mk('card',     'green', 'Pago aprobado'),
            'pago_validado'                  => $mk('card',     'green', 'Pago validado'),
            'pago_rechazado'                 => $mk('card',     'red',   'Pago rechazado'),
            'envio_registrado'               => $mk('truck',    'purple','Envío registrado'),
            'envio_en_preparacion'           => $mk('truck',    'purple','Envío en preparación'),
            'envio_despachado'               => $mk('truck',    'purple','Envío despachado'),
            'envio_en_camino'                => $mk('truck',    'purple','Envío en camino'),
            'envio_en_destino'               => $mk('truck',    'purple','Envío en destino'),
            'envio_entregado'                => $mk('truck',    'green', 'Envío entregado'),
            'envio_datos_pendientes'         => $mk('box',      'amber', 'Datos de envío pendientes'),
            'envio_datos_pendientes_final'   => $mk('box',      'amber', 'Datos de envío pendientes'),
            'envio_datos_registrados'        => $mk('box',      'green', 'Datos de envío registrados'),
            'acta_alcance_generada'          => $mk('doc',      'blue',  'Acta de alcance generada'),
            'acta_alcance_aprobada'          => $mk('doccheck', 'green', 'Acta de alcance aprobada'),
            'acta_alcance_cambios_solicitados'=> $mk('doc',     'amber', 'Cambios en acta de alcance'),
        ];
    }
    if (isset($MAP[$tipo])) return $MAP[$tipo];

    // Fallback por prefijo de dominio
    if (str_starts_with($tipo, 'envio'))       return $mk('truck',   'purple', 'Envío');
    if (str_starts_with($tipo, 'pago'))        return $mk('card',    'blue',   'Pago');
    if (str_starts_with($tipo, 'factura'))     return $mk('receipt', 'cyan',   'Factura');
    if (str_starts_with($tipo, 'propuesta') || str_starts_with($tipo, 'cotizacion')) return $mk('doc', 'green', 'Propuesta');
    if (str_starts_with($tipo, 'acta'))        return $mk('doc',     'amber',  'Acta de alcance');
    if (str_starts_with($tipo, 'cert'))        return $mk('shield',  'green',  'Certificado');
    if (str_starts_with($tipo, 'cliente') || str_starts_with($tipo, 'empresa')) return $mk('user', 'info', 'Empresa');
    if (str_starts_with($tipo, 'auditoria'))   return $mk('doccheck','blue',   'Auditoría');

    return ['color' => $C['gray'][0], 'bg' => $C['gray'][1], 'icon' => $IC['generic'], 'label' => 'Sistema'];
}

function csrf_verify(): void {
    $token_post    = $_POST['_csrf']               ?? '';
    $token_header  = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $token_session = $_SESSION['csrf_token']       ?? '';

    $token_enviado = $token_post ?: $token_header;

    $es_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
               strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    $es_json = strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;

    // Si el token de sesión está vacío o el token enviado no coincide → bloquear siempre
    if (empty($token_session) || empty($token_enviado) || !hash_equals($token_session, $token_enviado)) {
        // Regenerar token para el siguiente intento legítimo
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        session_write_close(); // Persistir la rotación y liberar el lock

        if ($es_ajax || $es_json) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'mensaje' => 'Token de seguridad inválido. Recarga la página e intenta de nuevo.']);
            exit;
        }

        http_response_code(403);
        // Redirigir a la página anterior para que el usuario pueda reintentar con el token nuevo
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        if ($referer && str_starts_with($referer, APP_URL)) {
            header('Location: ' . $referer);
        } else {
            header('Location: ' . APP_URL . '/modules/dashboard/index.php');
        }
        exit;
    }
    // Token válido: liberar el lock de sesión; el resto del endpoint no necesita escribirla
    session_write_close();
}

// --- RESPUESTAS JSON ---

function json_ok($data = null, string $mensaje = 'OK'): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'mensaje' => $mensaje, 'data' => $data]);
    exit;
}

function json_error(string $mensaje, int $code = 400, $data = null): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'mensaje' => $mensaje, 'data' => $data]);
    exit;
}

// --- FLASH MESSAGES (toast tras redirect) ---

function flash(string $msg, string $type = 'ok'): void {
    // Usar session_init() (no session_start() directamente) para que los
    // parámetros de cookie y gc_maxlifetime siempre estén configurados.
    session_init();
    $_SESSION['_flash'] = ['msg' => $msg, 'type' => $type, 'id' => uniqid('f_', true)];
}

function flash_consume(): ?array {
    session_init();
    if (!isset($_SESSION['_flash'])) return null;
    $f = $_SESSION['_flash'];
    unset($_SESSION['_flash']);
    return $f;
}

// --- ACTA DE ALCANCE: aplicabilidad por normas ---

/**
 * ¿El cliente REQUIERE Acta de Alcance?
 *
 * Regla (def. con el negocio, jun 2026): se requiere si AL MENOS UNA norma de sus
 * propuestas ACEPTADAS tiene `normas_iso.requiere_acta = 1`. Si ninguna lo requiere
 * (o no hay propuestas aceptadas con normas que lo exijan) → el acta NO aplica.
 *
 * Fuente única de verdad — usada por pendientes.php, doc_completa.php y clientes/ver.php
 * para no exigir/contar/ofrecer el acta cuando no corresponde.
 */
function acta_alcance_requerida(PDO $pdo, int $cliente_id): bool {
    if ($cliente_id <= 0) return false;
    try {
        $st = $pdo->prepare("
            SELECT 1
            FROM cotizaciones cot
            JOIN cotizacion_normas cn ON cn.cotizacion_id = cot.id
            JOIN normas_iso n        ON n.id = cn.norma_iso_id
            WHERE cot.cliente_id = ?
              AND cot.estado = 'aceptada'
              AND cot.deleted_at IS NULL
              AND n.requiere_acta = 1
            LIMIT 1
        ");
        $st->execute([$cliente_id]);
        return (bool)$st->fetchColumn();
    } catch (\Throwable $e) {
        // Ante cualquier error, comportarse como antes (exigir el acta) para no
        // ocultar un requisito por una falla técnica.
        return true;
    }
}

// --- LOG DE ACTIVIDAD ---

function log_actividad(string $modulo, string $accion, string $descripcion = ''): void {
    try {
        $pdo  = Database::get();
        $user = auth_user();
        $stmt = $pdo->prepare("
            INSERT INTO log_actividad (usuario_id, modulo, accion, descripcion, ip)
            VALUES (:uid, :mod, :acc, :desc, :ip)
        ");
        $stmt->execute([
            ':uid'  => $user['id'] ?? null,
            ':mod'  => $modulo,
            ':acc'  => $accion,
            ':desc' => $descripcion,
            ':ip'   => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);

        // ── Purga lazy: 1 de cada 200 llamadas elimina logs de más de 90 días ──
        // Se usa LIMIT para que la DELETE nunca bloquee la tabla más de unos ms,
        // incluso cuando haya acumulado cientos de miles de filas.
        if (random_int(1, 200) === 1) {
            $pdo->exec("
                DELETE FROM log_actividad
                WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)
                LIMIT 500
            ");
        }
    } catch (Exception) {
        // El log nunca debe romper el flujo principal
    }
}

// --- UTILIDADES ---

function sanitize(?string $value): string {
    return htmlspecialchars(trim($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * Trunca un texto largo y añade puntos suspensivos.
 * Límite estándar: 42 caracteres (suficiente para nombres de empresas medianas).
 */
function truncar(string $texto, int $max = 42): string {
    $texto = trim($texto);
    if (mb_strlen($texto, 'UTF-8') <= $max) return $texto;
    return mb_substr($texto, 0, $max, 'UTF-8') . '…';
}

/**
 * Igual que truncar() pero también aplica sanitize().
 */
function sanitize_trunc(?string $value, int $max = 42): string {
    return sanitize(truncar($value ?? '', $max));
}

function ruc_valido(string $ruc): bool {
    if (!preg_match('/^\d{11}$/', $ruc)) return false;
    // Algoritmo de validación RUC Perú
    $suma   = 0;
    $factor = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
    for ($i = 0; $i < 10; $i++) {
        $suma += (int)$ruc[$i] * $factor[$i];
    }
    $resto    = $suma % 11;
    $digito   = 11 - $resto;
    if ($digito >= 10) $digito -= 10;
    return (int)$ruc[10] === $digito;
}

function formato_fecha(?string $fecha): string {
    if (empty($fecha)) return '-';
    return date('d/m/Y', strtotime($fecha));
}

function dias_hasta(?string $fecha): int {
    if (empty($fecha)) return 0;
    return (int)ceil((strtotime($fecha) - time()) / 86400);
}

/**
 * Badge de estado para certificados usando clases CSS globales (soporta dark mode).
 * Retorna un <span class="badge-*"> listo para renderizar.
 */
function badge_estado_certificado(?string $estado): string {
    $estado = $estado ?? '';
    $map = [
        'vigente'    => ['cls' => 'badge-green',  'label' => 'Vigente'],
        'por_vencer' => ['cls' => 'badge-amber',  'label' => 'Por vencer'],
        'vencido'    => ['cls' => 'badge-red',    'label' => 'Vencido'],
        'suspendido' => ['cls' => 'badge-gray',   'label' => 'Suspendido'],
        'en_proceso' => ['cls' => 'badge-blue',   'label' => 'En proceso'],
    ];
    $e = $map[$estado] ?? ['cls' => 'badge-gray', 'label' => $estado ?: '—'];
    return '<span class="' . $e['cls'] . '">' . htmlspecialchars($e['label']) . '</span>';
}

/**
 * Retorna la clase CSS de badge para cualquier estado del negocio.
 * Uso: <span class="<?= badge_clase($estado) ?>">Texto</span>
 *
 * Mapeado a los 5 semánticos del design system:
 *   verde (ok) · ámbar (warn) · rojo (err) · azul (info/proceso) · gris (neutral)
 */
function badge_clase(string $estado): string {
    static $map = [
        // ── Verde: estados positivos/finales exitosos ──
        'activo'       => 'badge-green', 'vigente'      => 'badge-green',
        'aprobado'     => 'badge-green', 'aprobada'     => 'badge-green',
        'entregado'    => 'badge-green', 'entregada'    => 'badge-green',
        'pagado'       => 'badge-green', 'pagada'       => 'badge-green',
        'validado'     => 'badge-green', 'aceptado'     => 'badge-green',
        'aceptada'     => 'badge-green', 'completado'   => 'badge-green',
        // ── Ámbar: estados intermedios/pendientes ──
        'prospecto'    => 'badge-amber', 'pendiente'    => 'badge-amber',
        'pendiente_pago'     => 'badge-amber', 'pendiente_voucher' => 'badge-amber',
        'por_vencer'   => 'badge-amber', 'en_revision'  => 'badge-amber',
        'modificar'    => 'badge-amber',
        // ── Azul: estados en proceso/tránsito ──
        'en_transito'  => 'badge-blue',  'enviada'      => 'badge-blue',
        'enviado'      => 'badge-blue',  'en_proceso'   => 'badge-blue',
        'en_tramite'   => 'badge-blue',  'pendiente_validacion' => 'badge-blue',
        // ── Rojo: estados negativos/fallidos ──
        'vencido'      => 'badge-red',   'vencida'      => 'badge-red',
        'devuelto'     => 'badge-red',   'devuelta'     => 'badge-red',
        'rechazado'    => 'badge-red',   'rechazada'    => 'badge-red',
        'suspendido'   => 'badge-red',   'revocado'     => 'badge-red',
        // ── Gris: estados inactivos/neutrales ──
        'desistida'    => 'badge-gray',
        'inactivo'     => 'badge-gray',  'inactiva'     => 'badge-gray',
        'anulado'      => 'badge-gray',  'anulada'      => 'badge-gray',
        'borrador'     => 'badge-gray',  'por_preparar' => 'badge-gray',
        'cancelado'    => 'badge-gray',
    ];
    return $map[strtolower(trim($estado))] ?? 'badge-gray';
}

function next_codigo(string $tipo): string {
    $pdo = Database::get();
    $año = (int)date('Y');

    // Usar transacción con SELECT FOR UPDATE para evitar race conditions
    $pdo->beginTransaction();
    try {
        // Insertar fila si no existe, luego bloquearla
        $pdo->prepare(
            "INSERT INTO secuencias (tipo, año, ultimo) VALUES (?, ?, 0)
             ON DUPLICATE KEY UPDATE tipo = tipo"
        )->execute([$tipo, $año]);

        // Bloqueo exclusivo de la fila para este tipo+año
        $stmt = $pdo->prepare(
            "SELECT ultimo FROM secuencias WHERE tipo = ? AND año = ? FOR UPDATE"
        );
        $stmt->execute([$tipo, $año]);
        $ultimo = (int)$stmt->fetchColumn() + 1;

        $pdo->prepare(
            "UPDATE secuencias SET ultimo = ? WHERE tipo = ? AND año = ?"
        )->execute([$ultimo, $tipo, $año]);

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

    return strtoupper($tipo) . '-' . $año . '-' . str_pad($ultimo, 7, '0', STR_PAD_LEFT);
}

/**
 * Renderiza la barra de navegación contextual (breadcrumb).
 *
 * Uso:
 *   ggi_breadcrumb([
 *     ['label' => 'Empresas',  'href' => APP_URL.'/modules/clientes/index.php', 'ss' => 'ggi_ss_clientes'],
 *     ['label' => 'ACME S.A.', 'href' => APP_URL.'/modules/clientes/ver.php?id=5'],
 *     ['label' => 'Certificados'],  // último elemento = página actual, sin href
 *   ]);
 *
 * @param array $crumbs  Array de ['label'=>string, 'href'=>string|null, 'ss'=>string|null]
 */
function ggi_breadcrumb(array $crumbs): void {
    $h   = fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $n   = count($crumbs);
    $sep = '<svg class="ggi-bc-sep" width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>';

    echo '<nav class="ggi-bc" aria-label="Ruta de navegación">';
    foreach ($crumbs as $i => $c) {
        $last  = ($i === $n - 1);
        $label = $h($c['label'] ?? '');
        if ($last) {
            echo '<span class="ggi-bc-cur" aria-current="page">' . $label . '</span>';
        } else {
            $href = $h($c['href'] ?? '#');
            $ss   = !empty($c['ss']) ? ' data-bc-ss="' . $h($c['ss']) . '"' : '';
            echo '<a href="' . $href . '" class="ggi-bc-link"' . $ss . '>' . $label . '</a>';
            echo $sep;
        }
    }
    echo '</nav>';
}

if (!function_exists('ggi_copy')) {
    /**
     * Identificador copiable (RUC, N° certificado, código). Ícono discreto en hover,
     * copia al click (window: delegación .ggi-copyable). Texto seleccionable/accesible.
     *
     * @param string $text   Valor a copiar y mostrar.
     * @param array  $opts    label (texto visible si difiere del valor a copiar).
     */
    function ggi_copy(string $text, array $opts = []): string
    {
        $text = trim($text);
        if ($text === '') return '<span style="color:var(--gris-texto);">—</span>';
        $h     = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
        $label = $opts['label'] ?? $text;
        return '<span class="ggi-copyable" data-ggi-copy="' . $h($text) . '" role="button" tabindex="0"'
             . ' title="Copiar" aria-label="Copiar ' . $h($text) . '">'
             . '<span>' . $h($label) . '</span>'
             . '<svg class="ggi-copy-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">'
             . '<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>'
             . '</span>';
    }
}

if (!function_exists('ggi_msdrop')) {
    /**
     * Filtro dropdown multiselect estandarizado (estilo Empresas: .ms-drop + msDrop JS).
     * Reemplaza a los <select class="filter-select"> nativos para unificar todos los filtros.
     *
     * @param string $id        ID único del dropdown (ej. 'ms-cot-ejec').
     * @param string $name      Nombre del campo; se envía como name[] (multiselect).
     * @param array  $options   value => label  |  value => ['label'=>.., 'cls'=>'is-ok'(dot opcional)].
     * @param array  $selected  Valores actualmente seleccionados.
     * @param array  $opts      placeholder (def 'Todos'), aria (def placeholder).
     * @return string HTML.
     */
    function ggi_msdrop(string $id, string $name, array $options, array $selected, array $opts = []): string
    {
        $placeholder = $opts['placeholder'] ?? 'Todos';
        $aria        = $opts['aria'] ?? $placeholder;
        $h           = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
        $selected    = array_map('strval', $selected);

        $labels = [];
        foreach ($selected as $v) {
            $o = $options[$v] ?? null;
            $labels[] = is_array($o) ? ($o['label'] ?? $v) : ($o ?? $v);
        }
        if (!$labels)                 $btn_lbl = $placeholder;
        elseif (count($labels) <= 2)  $btn_lbl = implode(', ', $labels);
        else                          $btn_lbl = implode(', ', array_slice($labels, 0, 2)) . ' +' . (count($labels) - 2) . ' más';
        $is_ph = empty($selected);

        ob_start(); ?>
<div class="ms-drop" id="<?= $h($id) ?>">
  <button type="button" class="ms-drop-btn" onclick="msDrop.toggle('<?= $h($id) ?>')" aria-haspopup="listbox" aria-expanded="false" aria-label="<?= $h($aria) ?>">
    <span class="ms-drop-lbl <?= $is_ph ? 'is-placeholder' : '' ?>" data-placeholder="<?= $h($placeholder) ?>"><?= $h($btn_lbl) ?></span>
    <?php if (!$is_ph): ?><span class="ms-drop-count"><?= count($selected) ?></span><?php endif; ?>
    <svg class="ms-drop-arrow" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
  </button>
  <div class="ms-drop-menu" hidden>
    <?php foreach ($options as $val => $o):
        $lbl = is_array($o) ? ($o['label'] ?? $val) : $o;
        $cls = is_array($o) ? ($o['cls'] ?? '') : '';
    ?>
    <label class="ms-drop-item">
      <input type="checkbox" name="<?= $h($name) ?>[]" value="<?= $h($val) ?>" <?= in_array((string)$val, $selected, true) ? 'checked' : '' ?> onchange="msDrop.change('<?= $h($id) ?>', this)">
      <?php if ($cls): ?><span class="ms-drop-dot <?= $h($cls) ?>"></span><?php endif; ?>
      <span><?= $h($lbl) ?></span>
    </label>
    <?php endforeach; ?>
  </div>
</div>
<?php
        return ob_get_clean();
    }
}

if (!function_exists('ggi_tags')) {
    /**
     * Lista de tags con overflow "+N" (altura de fila uniforme).
     * Muestra hasta $opts['max'] badges y un chip "+N" que abre un popover
     * (window.ggiChipPop) con la lista completa. Touch + teclado accesible.
     *
     * @param array $items  Lista de etiquetas (strings).
     * @param array $opts   max (int, def 2), noun (str p/aria, def 'elementos'),
     *                      empty (str, def '—').
     * @return string HTML.
     */
    function ggi_tags(array $items, array $opts = []): string
    {
        $items = array_values(array_filter(array_map('strval', $items), fn($s) => $s !== ''));
        $empty = $opts['empty'] ?? '—';
        if (!$items) {
            return '<span style="color:var(--gris-texto);">' . htmlspecialchars($empty) . '</span>';
        }
        $max  = max(1, (int)($opts['max'] ?? 2));
        $noun = $opts['noun'] ?? 'elementos';
        $h    = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);

        $shown = array_slice($items, 0, $max);
        $extra = count($items) - count($shown);

        $html = '<span class="ggi-tags">';
        foreach ($shown as $t) $html .= '<span class="ggi-tag">' . $h($t) . '</span>';

        if ($extra > 0) {
            // Contenido del popover: TODOS los tags como badges
            $pop = '';
            foreach ($items as $t) $pop .= '<span class="ggi-tag">' . $h($t) . '</span>';
            $html .= '<span class="ggi-tag ggi-tag-more" role="button" tabindex="0"'
                  . ' aria-haspopup="true" aria-expanded="false"'
                  . ' aria-label="Ver los ' . count($items) . ' ' . $h($noun) . '"'
                  . ' data-ggipop="' . $h($pop) . '"'
                  . ' onclick="ggiChipPop(this)"'
                  . ' onkeydown="if(event.key===\'Enter\'||event.key===\' \'){event.preventDefault();ggiChipPop(this);}">'
                  . '+' . $extra . '</span>';
        }
        $html .= '</span>';
        return $html;
    }
}

if (!function_exists('ggi_pager')) {
    /**
     * Footer de paginación canónico (.ggi-pager). Server-side offset + total.
     * Preserva los filtros actuales del query string ($_GET) en la navegación.
     *
     * @param int   $total     Total de registros (COUNT con filtros aplicados).
     * @param int   $page      Página actual (1-based).
     * @param int   $per_page  Filas por página.
     * @param array $opts      Opcionales:
     *   - per_page_options (int[])  Opciones del selector. Default [15,25,50,100].
     *   - noun (string)             Sustantivo singular para el rango. Default 'registro'.
     *   - noun_plural (string)      Plural. Default noun.'s'.
     *   - param_page (string)       Nombre del parámetro de página. Default 'p'.
     *   - param_per (string)        Nombre del parámetro de tamaño. Default 'per_page'.
     *   - drop_params (string[])    Parámetros extra a eliminar de los enlaces (ej. cursores).
     * @return string HTML del footer.
     */
    function ggi_pager(int $total, int $page, int $per_page, array $opts = []): string
    {
        $per_opts = $opts['per_page_options'] ?? [10, 20, 30];
        $pp_param = $opts['param_per']  ?? 'per_page';
        $pg_param = $opts['param_page'] ?? 'p';
        $noun     = $opts['noun']        ?? 'registro';
        $noun_pl  = $opts['noun_plural'] ?? ($noun . 's');

        $per_page    = max(1, $per_page);
        $total_pages = max(1, (int)ceil(max(0, $total) / $per_page));
        $page        = min(max(1, $page), $total_pages);
        $from        = $total <= 0 ? 0 : (($page - 1) * $per_page + 1);
        $to          = min($page * $per_page, $total);

        // Base del query string preservando filtros (sin página ni cursores legados)
        $q = $_GET;
        unset($q[$pg_param], $q['cursor'], $q['prev']);
        foreach (($opts['drop_params'] ?? []) as $d) unset($q[$d]);

        $url = function (array $extra) use ($q): string {
            $qs = http_build_query(array_merge($q, $extra));
            return htmlspecialchars('?' . $qs, ENT_QUOTES);
        };

        $h        = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
        $prev_dis = $page <= 1 ? ' disabled' : '';
        $next_dis = $page >= $total_pages ? ' disabled' : '';
        $prev_url = $page <= 1 ? '#' : $url([$pg_param => $page - 1]);
        $next_url = $page >= $total_pages ? '#' : $url([$pg_param => $page + 1]);
        $nounLbl  = $total === 1 ? $noun : $noun_pl;

        // Selector "Ítems por página"
        $sel = '';
        foreach ($per_opts as $opt) {
            $opt = (int)$opt;
            $sel .= '<option value="' . $opt . '"' . ($opt === $per_page ? ' selected' : '') . '>' . $opt . '</option>';
        }

        $ppJs = $h($pp_param);
        $pgJs = $h($pg_param);

        ob_start(); ?>
<div class="ggi-pager">
  <div class="ggi-pager-left">
    <div class="ggi-pager-perpage">
      <span>Ítems por página:</span>
      <select class="ggi-pager-select" title="Ítems por página"
              onchange="ggiPagerGo('<?= $ppJs ?>', this.value, true)"><?= $sel ?></select>
    </div>
    <div class="ggi-pager-sep"></div>
    <div class="ggi-pager-range"><?= $from ?>&ndash;<?= $to ?> de <?= number_format($total) ?> <?= $h($nounLbl) ?></div>
  </div>
  <div class="ggi-pager-right">
    <span class="ggi-pager-pagelabel">Página</span>
    <input class="ggi-pager-input" type="number" min="1" max="<?= $total_pages ?>" value="<?= $page ?>"
           aria-label="Número de página"
           onchange="ggiPagerJump(this, '<?= $pgJs ?>', <?= $total_pages ?>)"
           onkeydown="if(event.key==='Enter'){event.preventDefault();ggiPagerJump(this,'<?= $pgJs ?>',<?= $total_pages ?>)}">
    <span class="ggi-pager-total">de <?= $total_pages ?></span>
    <a class="ggi-pager-nav<?= $prev_dis ?>" href="<?= $prev_url ?>" aria-label="Página anterior" rel="prev">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
    </a>
    <a class="ggi-pager-nav<?= $next_dis ?>" href="<?= $next_url ?>" aria-label="Página siguiente" rel="next">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
    </a>
  </div>
</div>
<?php
        return ob_get_clean();
    }
}

/**
 * Config de campos por agencia de envío (única fuente de verdad).
 * La usa modules/envios/ajax_agencia_config.php y el render de <option> en crear/editar
 * (data-* en cada opción → la visibilidad de campos no depende de un AJAX).
 *
 * @param string $nombre     Nombre de la agencia
 * @param string $sede_modo  Valor de agencias_envio.sede_modo ('api' | 'manual')
 */
if (!function_exists('ggi_agencia_envio_config')) {
    function ggi_agencia_envio_config(string $nombre, string $sede_modo = 'manual'): array {
        $n   = strtolower($nombre);
        $cfg = [
            'tiene_numero_orden'       => false,
            'tiene_codigo_seguimiento' => true,
            'tiene_codigo_recojo'      => false,
            'tiene_sede'               => true,
            'sede_modo'                => ($sede_modo === 'api' ? 'api' : 'manual'),
            'label_codigo_seguimiento' => 'Código de seguimiento',
            'label_numero_orden'       => 'N° de Orden',
            'label_codigo_recojo'      => 'Código de recojo',
        ];
        if (strpos($n, 'shalom') !== false) {
            $cfg['tiene_numero_orden']  = true;
            $cfg['tiene_codigo_recojo'] = true;
            $cfg['label_numero_orden']  = 'N° de Orden Shalom';
        } elseif (strpos($n, 'olva') !== false) {
            $cfg['tiene_codigo_recojo'] = true;
        } elseif (strpos($n, 'serpost') !== false || strpos($n, 'dhl') !== false || strpos($n, 'fedex') !== false) {
            $cfg['tiene_numero_orden']  = true;
            $cfg['label_numero_orden']  = 'N° de guía';
        } elseif (strpos($n, 'indriver') !== false || strpos($n, 'rappi') !== false || strpos($n, 'urbano') !== false) {
            $cfg['tiene_codigo_recojo'] = true;
            $cfg['label_codigo_recojo'] = 'Código de entrega';
        } else {
            $cfg['tiene_numero_orden']  = true;
            $cfg['tiene_codigo_recojo'] = true;
        }
        return $cfg;
    }
}

// ── Motivos de "No Enviado" (catálogo único) ───────────────────────────────────
// Fuente de verdad para envios.motivo_no_envio. El equipo ve la etiqueta;
// la BD guarda el código. 'otro' exige detalle en motivo_no_envio_detalle.
if (!function_exists('gg_motivos_no_envio')) {
    function gg_motivos_no_envio(): array {
        return [
            'sin_datos'          => 'Cliente no compartió datos de envío',
            'no_responde'        => 'Cliente no responde',
            'direccion_invalida' => 'Dirección/datos inválidos',
            'pospuesto_cliente'  => 'Cliente pidió posponer',
            'otro'               => 'Otro motivo',
        ];
    }
    /** Etiqueta legible de un código de motivo ('' si no existe). */
    function gg_motivo_no_envio_label(?string $codigo): string {
        return gg_motivos_no_envio()[$codigo ?? ''] ?? '';
    }
    /** ¿El nombre de estado corresponde a "No Enviado"? (robusto a cambios de id) */
    function gg_es_estado_no_enviado(?string $nombre_estado): bool {
        return str_contains(mb_strtolower($nombre_estado ?? ''), 'no enviado');
    }
}

// ── Caché ligera en archivo (clave-valor) ──────────────────────────────────────
// Pensada para valores globales, idénticos entre usuarios y baratos de regenerar
// (p. ej. contadores del sidebar). NO usar para datos por-usuario ni sensibles.
if (!function_exists('cache_remember')) {
    /**
     * Devuelve el valor cacheado de $key; si no existe o expiró, ejecuta $cb,
     * guarda el resultado por $ttl segundos y lo devuelve. A prueba de fallos:
     * ante cualquier error de disco cae a ejecutar $cb en vivo.
     *
     * @param string   $key Identificador lógico (se normaliza a nombre de archivo).
     * @param int      $ttl Segundos de vida.
     * @param callable $cb  Genera el valor cuando la caché falla.
     */
    function cache_remember(string $key, int $ttl, callable $cb) {
        $dir  = __DIR__ . '/../storage/cache/kv/';
        $file = $dir . preg_replace('/[^A-Za-z0-9_\-]/', '_', $key) . '.cache';

        if (is_file($file) && (time() - filemtime($file)) < $ttl) {
            $raw = @file_get_contents($file);
            if ($raw !== false) {
                $val = @unserialize($raw);
                if ($val !== false || $raw === serialize(false)) {
                    return $val;
                }
            }
        }

        $val = $cb();

        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        @file_put_contents($file, serialize($val), LOCK_EX);

        return $val;
    }
}

if (!function_exists('cache_forget')) {
    /** Invalida una clave de caché (llamar tras cambiar el dato de origen). */
    function cache_forget(string $key): void {
        $file = __DIR__ . '/../storage/cache/kv/'
              . preg_replace('/[^A-Za-z0-9_\-]/', '_', $key) . '.cache';
        if (is_file($file)) @unlink($file);
    }
}

// ── Reducir imagen en sitio ────────────────────────────────────────────────────
if (!function_exists('imagen_reducir')) {
    /**
     * Si la imagen supera $max px en su lado mayor, la redimensiona in situ
     * conservando formato, nombre y transparencia. Evita que avatares/fotos de
     * varios MB se guarden a resolución absurda. Silencioso ante errores: deja
     * el archivo original intacto si GD no puede procesarlo.
     */
    function imagen_reducir(string $path, int $max = 512, int $jpeg_q = 85): void {
        if (!is_file($path) || !extension_loaded('gd')) return;
        $info = @getimagesize($path);
        if (!$info) return;
        [$w, $h, $type] = $info;
        if (max($w, $h) <= $max) return; // ya está dentro del límite

        if     ($type === IMAGETYPE_PNG)  $src = @imagecreatefrompng($path);
        elseif ($type === IMAGETYPE_JPEG) $src = @imagecreatefromjpeg($path);
        elseif ($type === IMAGETYPE_WEBP) $src = @imagecreatefromwebp($path);
        else return;
        if (!$src) return;

        $scale = $max / max($w, $h);
        $nw = (int) round($w * $scale);
        $nh = (int) round($h * $scale);

        $dst = imagecreatetruecolor($nw, $nh);
        if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_WEBP) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        $tmp = $path . '.tmp';
        $ok = false;
        if     ($type === IMAGETYPE_PNG)  $ok = imagepng($dst, $tmp, 6);
        elseif ($type === IMAGETYPE_JPEG) $ok = imagejpeg($dst, $tmp, $jpeg_q);
        elseif ($type === IMAGETYPE_WEBP) $ok = imagewebp($dst, $tmp, $jpeg_q);

        imagedestroy($src);
        imagedestroy($dst);

        if ($ok && is_file($tmp) && filesize($tmp) > 0) {
            @rename($tmp, $path);
        } else {
            @unlink($tmp);
        }
    }
}
