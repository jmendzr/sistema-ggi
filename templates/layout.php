<?php
// templates/layout.php
// Uso: include con $page_title, $active_menu definidos antes
require_once __DIR__ . '/../core/helpers.php';
session_init();
require_login();
$user = auth_user();

// ── Modo embed (iframe dentro de un modal) ────────────────────────────────
// Se deduce aquí, de forma global, para TODAS las páginas. Antes cada página
// tenía que declarar $_ggi_embed a mano antes del include; las que se
// olvidaban (ver.php de empresas, propuestas y envíos) se abrían en el modal
// con el sistema entero adentro: sidebar, cabecera y todo.
// Si una página ya lo definió, se respeta.
if (!isset($_ggi_embed)) {
    $_ggi_embed = !empty($_GET['embed']) || !empty($_POST['_embed']);
}
// Contador de notificaciones no leídas para el header
$_notif_count = 0;
try {
    $_pdo_layout = Database::get();
    $_stmt_notif = $_pdo_layout->prepare("SELECT COUNT(*) FROM notificaciones WHERE usuario_id=? AND leida=0");
    $_stmt_notif->execute([$user['id']]);
    $_notif_count = (int)$_stmt_notif->fetchColumn();
} catch (Exception $e) { $_notif_count = 0; }
// Precalentar el token CSRF en caché estático y luego liberar el lock de sesión.
// csrf_token() usa static $_cached, así que las llamadas posteriores no re-abren la sesión.
csrf_token();

// Pre-computar permisos y badge ANTES de session_write_close(), ya que can() llama
// a auth_user() -> session_init(), que puede fallar si la sesión ya está cerrada.
$_can_clientes       = can('clientes') || can('clientes_ver');
$_can_certificados   = can('certificados');
$_can_auditorias     = can('auditorias');
$_can_envios         = can('envios');
$_can_cotizaciones   = can('cotizaciones');
$_can_pagos          = can('pagos');
$_can_bonos          = can('bonos');
$_can_metas          = can('metas');
$_can_reportes       = can('reportes');
$_can_notificaciones = can('notificaciones');
$_can_whatsapp       = can('whatsapp');
$_can_inbox          = can('inbox');
$_can_historial      = has_role('gerente_general');
$_can_mantenimiento  = can('mantenimiento');

// Badge de pagos (se usa en el sidebar)
$_pagos_badge = 0;
try {
    if ($user['rol'] === 'ejecutivo_ventas') {
        $_pag_sess_key = 'pag_last_visit_' . $user['id'];
        if (isset($_SESSION[$_pag_sess_key])) {
            $_pag_last = $_SESSION[$_pag_sess_key];
        } else {
            $_pag_row = Database::get()->prepare('SELECT ultima_visita_pagos FROM usuarios WHERE id = ?');
            $_pag_row->execute([$user['id']]);
            $_pag_dbval = $_pag_row->fetchColumn();
            $_pag_last = $_pag_dbval ?: date('Y-m-d H:i:s', strtotime('-30 days'));
        }
        $_s = Database::get()->prepare("
            SELECT COUNT(*) FROM pagos_cliente p
            JOIN cotizaciones cot ON cot.id = p.cotizacion_id
            JOIN clientes     c   ON c.id   = p.cliente_id
            WHERE p.estado IN ('validado','rechazado')
            AND p.fecha_validacion > ?
            AND COALESCE(cot.ejecutivo_id, c.ejecutivo_id) = ?
        ");
        $_s->execute([$_pag_last, $user['id']]);
        $_pagos_badge = (int)$_s->fetchColumn();
    } else {
        $_pagos_badge = (int)Database::get()->query("SELECT COUNT(*) FROM pagos_cliente WHERE estado='pendiente_validacion'")->fetchColumn();
    }
} catch(Exception $e) { $_pagos_badge = 0; }

// Badge certificados por vencer (ámbar) — visible para quien tenga acceso al módulo.
// Global e idéntico para todos los usuarios → se cachea 60 s (cambia lentamente).
$_cert_vencer_badge = 0;
try {
    if ($_can_certificados) {
        $_cert_vencer_badge = (int)cache_remember('badge_cert_por_vencer', 60, function () {
            return (int)Database::get()->query(
                "SELECT COUNT(*) FROM certificados WHERE estado = 'por_vencer'"
            )->fetchColumn();
        });
    }
} catch(Exception $e) { $_cert_vencer_badge = 0; }

// Badge propuestas próximas a vencer (≤ 48 h) — solo si el usuario NO visitó propuestas hoy
$_cot_vencer_badge = 0;
try {
    if ($_can_cotizaciones) {
        // Verificar si el usuario ya visitó propuestas hoy (columna puede no existir aún)
        $_uv_row = Database::get()->prepare("SELECT ultima_visita_propuestas FROM usuarios WHERE id = ?");
        $_uv_row->execute([$user['id']]);
        $_uv_val = $_uv_row->fetchColumn();
        $_ya_visito_hoy = ($_uv_val && $_uv_val === date('Y-m-d'));

        if (!$_ya_visito_hoy) {
            if ($user['rol'] === 'ejecutivo_ventas') {
                $_sv = Database::get()->prepare("
                    SELECT COUNT(*) FROM cotizaciones
                    WHERE ejecutivo_id = ?
                      AND estado IN ('borrador','enviada')
                      AND fecha_vencimiento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 2 DAY)
                ");
                $_sv->execute([$user['id']]);
                $_cot_vencer_badge = (int)$_sv->fetchColumn();
            } else {
                $_cot_vencer_badge = (int)Database::get()->query("
                    SELECT COUNT(*) FROM cotizaciones
                    WHERE estado IN ('borrador','enviada')
                      AND fecha_vencimiento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 2 DAY)
                ")->fetchColumn();
            }
        }
    }
} catch(Exception $e) { $_cot_vencer_badge = 0; }

// ── Anuncio global ────────────────────────────────────────────────────────────
$_ann_activo  = false;
$_ann_texto   = '';
$_ann_tipo    = 'info'; // info | warning | maintenance | success
$_ann_version = '1';
$_ann_hash    = '';
try {
    // Config del anuncio: global e idéntica para todos → cacheada 60 s.
    // Al cambiar el anuncio (módulo mantenimiento) debe llamarse
    // cache_forget('anuncio_global') para reflejarlo de inmediato.
    $_ann_row = cache_remember('anuncio_global', 60, function () {
        return Database::get()->query(
            "SELECT clave, valor FROM configuracion
             WHERE clave IN ('anuncio_activo','anuncio_texto','anuncio_tipo','anuncio_version')"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
    });
    $_ann_activo  = ($_ann_row['anuncio_activo']  ?? '0') === '1';
    $_ann_texto   = $_ann_row['anuncio_texto']    ?? '';
    $_ann_tipo    = $_ann_row['anuncio_tipo']     ?? 'info';
    $_ann_version = $_ann_row['anuncio_version']  ?? '1';
} catch(Exception $e) {}
$_ann_show = $_ann_activo && $_ann_texto !== '';

// ── Verificar si este usuario ya cerró este anuncio (persistencia en DB) ──
// Hash único = texto + tipo + versión. Cambiar cualquiera de los tres fuerza re-show.
if ($_ann_show && isset($user['id'])) {
    $_ann_hash = md5($_ann_texto . $_ann_tipo . $_ann_version);
    try {
        $stmt = Database::get()->prepare(
            "SELECT 1 FROM anuncios_vistos WHERE usuario_id = ? AND ann_hash = ? LIMIT 1"
        );
        $stmt->execute([$user['id'], $_ann_hash]);
        if ($stmt->fetchColumn()) {
            $_ann_show = false; // ya lo cerró antes → no mostrar
        }
    } catch(Exception $e) {
        // Si la tabla no existe (migración pendiente), usar comportamiento anterior
    }
}

session_write_close();
?>
<!DOCTYPE html>
<html lang="es" style="background:#F1F5F9">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <meta name="csrf-token" content="<?= csrf_token() ?>">
  <!-- Prevent flash of wrong theme/background before CSS loads -->
  <script>try{var _t=localStorage.getItem('ggi_tema')||'sistema';var _dark=_t==='oscuro'||(_t==='sistema'&&window.matchMedia&&window.matchMedia('(prefers-color-scheme:dark)').matches);var _r=document.documentElement;if(_dark){_r.setAttribute('data-tema','oscuro');}else{_r.setAttribute('data-tema','claro');_r.style.removeProperty('background');_r.style.removeProperty('background-color');}}catch(e){}</script>

  <title><?= sanitize($page_title ?? 'GGI Sistema') ?> — GGI</title>

  <!-- ── PWA ─────────────────────────────────────────────────────────────── -->
  <link rel="manifest" href="<?= APP_URL ?>/manifest.php">

  <!-- theme-color: azul corporativo en light, oscuro en dark -->
  <meta name="theme-color" media="(prefers-color-scheme: light)" content="#1A3A8F">
  <meta name="theme-color" media="(prefers-color-scheme: dark)"  content="#0C1118">
  <meta name="theme-color" content="#1A3A8F">

  <!-- Android PWA -->
  <meta name="mobile-web-app-capable" content="yes">

  <!-- iOS PWA -->
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="GGI Sistema">

  <!-- Apple touch icons (iOS home screen) -->
  <link rel="apple-touch-icon"                href="<?= APP_URL ?>/assets/icons/icon-180.png">
  <link rel="apple-touch-icon" sizes="120x120" href="<?= APP_URL ?>/assets/icons/icon-128.png">
  <link rel="apple-touch-icon" sizes="152x152" href="<?= APP_URL ?>/assets/icons/icon-152.png">
  <link rel="apple-touch-icon" sizes="167x167" href="<?= APP_URL ?>/assets/icons/icon-180.png">
  <link rel="apple-touch-icon" sizes="180x180" href="<?= APP_URL ?>/assets/icons/icon-180.png">
  <link rel="apple-touch-icon" sizes="192x192" href="<?= APP_URL ?>/assets/icons/icon-192.png">

  <!-- iOS Splash Screens (startup images) — fondo azul corporativo con icono -->
  <!-- iPhone SE / 5 -->
  <link rel="apple-touch-startup-image" media="(device-width:320px) and (device-height:568px) and (-webkit-device-pixel-ratio:2)" href="<?= APP_URL ?>/assets/splash/splash-640x1136.png">
  <!-- iPhone 6/7/8 -->
  <link rel="apple-touch-startup-image" media="(device-width:375px) and (device-height:667px) and (-webkit-device-pixel-ratio:2)" href="<?= APP_URL ?>/assets/splash/splash-750x1334.png">
  <!-- iPhone 6+/7+/8+ -->
  <link rel="apple-touch-startup-image" media="(device-width:414px) and (device-height:736px) and (-webkit-device-pixel-ratio:3)" href="<?= APP_URL ?>/assets/splash/splash-1242x2208.png">
  <!-- iPhone X/XS/11 Pro -->
  <link rel="apple-touch-startup-image" media="(device-width:375px) and (device-height:812px) and (-webkit-device-pixel-ratio:3)" href="<?= APP_URL ?>/assets/splash/splash-1125x2436.png">
  <!-- iPhone XR/11 -->
  <link rel="apple-touch-startup-image" media="(device-width:414px) and (device-height:896px) and (-webkit-device-pixel-ratio:2)" href="<?= APP_URL ?>/assets/splash/splash-828x1792.png">
  <!-- iPhone 11 Pro Max/XS Max -->
  <link rel="apple-touch-startup-image" media="(device-width:414px) and (device-height:896px) and (-webkit-device-pixel-ratio:3)" href="<?= APP_URL ?>/assets/splash/splash-1242x2688.png">
  <!-- iPhone 12/13/14 mini -->
  <link rel="apple-touch-startup-image" media="(device-width:360px) and (device-height:780px) and (-webkit-device-pixel-ratio:3)" href="<?= APP_URL ?>/assets/splash/splash-1080x2340.png">
  <!-- iPhone 12/13/14 -->
  <link rel="apple-touch-startup-image" media="(device-width:390px) and (device-height:844px) and (-webkit-device-pixel-ratio:3)" href="<?= APP_URL ?>/assets/splash/splash-1170x2532.png">
  <!-- iPhone 12/13/14 Pro Max -->
  <link rel="apple-touch-startup-image" media="(device-width:428px) and (device-height:926px) and (-webkit-device-pixel-ratio:3)" href="<?= APP_URL ?>/assets/splash/splash-1284x2778.png">
  <!-- iPhone 14 Pro -->
  <link rel="apple-touch-startup-image" media="(device-width:393px) and (device-height:852px) and (-webkit-device-pixel-ratio:3)" href="<?= APP_URL ?>/assets/splash/splash-1179x2556.png">
  <!-- iPhone 14 Pro Max / 15 Plus -->
  <link rel="apple-touch-startup-image" media="(device-width:430px) and (device-height:932px) and (-webkit-device-pixel-ratio:3)" href="<?= APP_URL ?>/assets/splash/splash-1290x2796.png">

  <!-- Favicon: ICO para escritorio, PNG para modernos y PWA -->
  <link rel="icon" href="<?= APP_URL ?>/favicon.ico" sizes="any">
  <link rel="icon" type="image/png" sizes="32x32"   href="<?= APP_URL ?>/assets/icons/icon-32.png">
  <link rel="icon" type="image/png" sizes="192x192" href="<?= APP_URL ?>/assets/icons/icon-192.png">
  <link rel="icon" type="image/png" sizes="512x512" href="<?= APP_URL ?>/assets/icons/icon-512.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <!-- Fuentes: carga asíncrona para no bloquear el render -->
  <link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Inter:wght@400;500;600&display=swap">
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
  <noscript><link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet"></noscript>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    /* ══════════════════════════════════════════════════════
       DESIGN TOKENS — Light Theme (default)
    ══════════════════════════════════════════════════════ */
    :root {
      /* ── Backgrounds ── */
      --bg-base:     #F1F5F9;
      --bg-surface:  #FFFFFF;
      --bg-surface2: #F8FAFC;
      --bg-hover:    #F1F5F9;
      --bg-sidebar:  #FFFFFF;
      --bg-header:   #FFFFFF;
      --bg-th:       #FAFBFE;
      --bg-input:    #FAFBFE;

      /* ── Borders ── */
      --brd:  #E2E8F0;
      --brd2: #F0F2F8;

      /* ── Text ── */
      --tx:  #0F172A;
      --tx2: #64748B;
      --tx3: #94A3B8;

      /* ── Accent (blue) ── */
      --ac:      #1D4ED8;
      --ac-h:    #1E40AF;
      --ac-mid:  #2563EB;
      --ac-soft: #DBEAFE;
      --ac-tx:   #1D4ED8;

      /* ── Green ── */
      --gr:      #2DB140;
      --gr-h:    #229133;
      --gr-soft: #DCFCE7;

      /* ── Status ── */
      --s-ok:       #059669;  --s-ok-bg:     #DCFCE7;  --s-ok-brd:     rgba(5,150,105,.25);
      --s-warn:     #D97706;  --s-warn-bg:   #FEF3C7;  --s-warn-brd:   rgba(217,119,6,.25);
      --s-err:      #DC2626;  --s-err-bg:    #FEE2E2;  --s-err-brd:    rgba(220,38,38,.25);
      --s-info:     #0369A1;  --s-info-bg:   #F0F9FF;  --s-info-brd:   #BAE6FD;
      --s-purple:   #7C3AED;  --s-purple-bg: #F5F3FF;  --s-purple-brd: rgba(124,58,237,.25);
      --s-neutral:  #475569;  --s-neutral-bg:#F1F5F9;  --s-neutral-brd:var(--brd);
      /* Texto de caja de estado (voltea según tema): oscuro en claro, claro en oscuro.
         Úsalo con --s-*-bg para cajas/pills tokenizadas en ambos temas. */
      --s-ok-tx:#166534; --s-warn-tx:#92400E; --s-err-tx:#B91C1C; --s-info-tx:#075985; --s-purple-tx:#6D28D9; --s-neutral-tx:#475569;

      /* ── Layout ── */
      --sidebar-w:       240px;
      --sidebar-mini:    56px;
      --sidebar-current: var(--sidebar-w); /* fuente de verdad — JS la sobreescribe al colapsar */
      --header-h:        60px;
      --ann-h:           0px; /* se sobreescribe con JS si hay anuncio activo */

      /* ── Safe areas (notch / Dynamic Island / home indicator) — PWA standalone.
         Requieren viewport-fit=cover. En navegador/desktop valen 0. ── */
      --safe-top:    env(safe-area-inset-top, 0px);
      --safe-bottom: env(safe-area-inset-bottom, 0px);
      --safe-left:   env(safe-area-inset-left, 0px);
      --safe-right:  env(safe-area-inset-right, 0px);

      /* ── Backward-compat aliases (existing modules keep working) ── */
      --azul:        var(--ac);
      --azul-dark:   var(--ac-h);
      --azul-mid:    var(--ac-mid);
      --azul-light:  var(--ac-soft);
      --verde:       var(--gr);
      --verde-dark:  var(--gr-h);
      --verde-light: var(--gr-soft);
      --gris-bg:     var(--bg-base);
      --gris-card:   var(--bg-surface);
      --gris-borde:  var(--brd);
      --gris-texto:  var(--tx2);
      --gris-dark:   var(--tx);
      --amber:       var(--s-warn);
      --red:         var(--s-err);
    }

    /* ══════════════════════════════════════════════════════
       DESIGN TOKENS — Dark Theme
    ══════════════════════════════════════════════════════ */
    :root[data-tema="oscuro"] {
      --bg-base:     #0B0F14;
      --bg-surface:  #111827;
      --bg-surface2: #1F2937;
      --bg-hover:    #1F2937;
      --bg-sidebar:  #0A0E13;
      --bg-header:   #111827;
      --bg-th:       #0D1117;
      --bg-input:    #1F2937;

      --brd:  #1E293B;
      --brd2: #162032;

      --tx:  #E5E7EB;
      --tx2: #9CA3AF;
      --tx3: #7C8694;  /* era #4B5563: placeholders/labels daban 1.94:1 (ilegible).
                          #7C8694 → ~3.6:1 en inputs y ~4.5:1 en superficies, sin
                          competir con --tx2. (§ color-contrast / color-dark-mode) */

      --ac:      #3B82F6;
      --ac-h:    #2563EB;
      --ac-mid:  #60A5FA;
      --ac-soft: #1E3058;
      --ac-tx:   #60A5FA;

      --gr:      #34D399;
      --gr-h:    #10B981;
      --gr-soft: rgba(52,211,153,.12);

      --s-ok:       #10B981;  --s-ok-bg:     rgba(16,185,129,.13); --s-ok-brd:     rgba(16,185,129,.2);
      --s-warn:     #F59E0B;  --s-warn-bg:   rgba(245,158,11,.12); --s-warn-brd:   rgba(245,158,11,.2);
      --s-err:      #EF4444;  --s-err-bg:    rgba(239,68,68,.12);  --s-err-brd:    rgba(239,68,68,.2);
      --s-info:     #38BDF8;  --s-info-bg:   rgba(14,165,233,.12); --s-info-brd:   rgba(56,189,248,.2);
      --s-purple:   #A78BFA;  --s-purple-bg: rgba(139,92,246,.12); --s-purple-brd: rgba(167,139,250,.2);
      --s-neutral:  #94A3B8;  --s-neutral-bg:var(--bg-surface2);   --s-neutral-brd:var(--brd);
      /* Texto de caja de estado en oscuro (claro, ≥4.5:1 sobre --s-*-bg oscuro) */
      --s-ok-tx:#86EFAC; --s-warn-tx:#FCD34D; --s-err-tx:#FCA5A5; --s-info-tx:#7DD3FC; --s-purple-tx:#C4B5FD; --s-neutral-tx:#CBD5E1;

      color-scheme: dark;
    }

    /* ── Light forced ── */
    :root[data-tema="claro"] {
      --bg-base: #F0F2F8; --bg-surface: #FFFFFF;
      --brd: #DDE3F0; --tx: #1A1F36;
      color-scheme: light;
    }

    html { background: var(--bg-base); scrollbar-gutter: stable; }
    html, body { height: 100%; font-family: 'Inter', sans-serif; background: var(--bg-base); color: var(--tx); font-size: 14px; }
    /* Links sin clase específica usan el color de texto base — evita azul UA */
    a { color: var(--tx); }
    a:hover { color: var(--azul); }

    /* ── Transición entre páginas sin flash blanco (Chrome 111+) ──
       Captura screenshot de la página actual y la sostiene hasta que
       la nueva página esté pintada → cero gap blanco entre navegaciones. */
    @view-transition { navigation: auto; }
    ::view-transition-old(root) { animation: none; }
    ::view-transition-new(root) { animation: none; }

    /* ── Sidebar ── */
    .sidebar {
      position: fixed;
      top: var(--ann-h); left: 0;
      width: var(--sidebar-w); height: calc(100vh - var(--ann-h));
      padding-top: var(--safe-top);   /* baja logo/nav bajo el notch (caso sin anuncio) */
      background: var(--bg-sidebar);
      border-right: 1px solid var(--brd);
      display: flex; flex-direction: column;
      z-index: 100; overflow: hidden;
      transition: width 0.25s ease, transform 0.25s ease, top 0.25s ease, height 0.25s ease;
    }
    .sidebar-nav {
      flex: 1; overflow-y: auto; min-height: 0;
      scrollbar-width: thin;
      scrollbar-color: var(--brd) transparent;
      padding-bottom: 8px;
    }
    .sidebar-nav::-webkit-scrollbar { width: 3px; }
    .sidebar-nav::-webkit-scrollbar-thumb { background: var(--brd); border-radius: 2px; }

    /* Línea de acento en la parte superior */
    .sidebar::before {
      content: ''; display: block; height: 3px; flex-shrink: 0;
      background: linear-gradient(90deg, var(--ac) 0%, var(--gr) 100%);
    }

    .sidebar-logo {
      padding: 18px 16px 16px;
      border-bottom: 1px solid var(--brd2);
      display: flex; align-items: center; gap: 10px;
    }
    .sidebar-logo-icon {
      width: 36px; height: 36px; border-radius: 9px; flex-shrink: 0;
      background: var(--ac-soft);
      display: flex; align-items: center; justify-content: center;
      overflow: hidden;
    }
    .sidebar-logo-icon img { filter: none !important; width:100%;height:100%;object-fit:contain;padding:5px; }
    .sidebar-logo-text { line-height: 1.25; min-width: 0; }
    .sidebar-logo-text strong {
      font-family: 'Inter', sans-serif; font-size: 13px;
      font-weight: 700; color: var(--tx); display: block; white-space: nowrap; letter-spacing: -0.01em;
    }
    .sidebar-logo-text span { font-size: 10px; color: var(--tx3); letter-spacing: 0.3px; }

    .sidebar-section { padding: 16px 10px 4px; }
    .sidebar-section:first-of-type { padding-top: 10px; }
    .sidebar-section-label {
      font-size: 10px; font-weight: 600; color: var(--tx3);
      letter-spacing: 0.7px; text-transform: uppercase;
      padding: 0 12px; margin-bottom: 5px;
      display: flex; align-items: center; gap: 8px;
    }
    .sidebar-section-label::after { display: none; }

    .nav-item {
      display: flex; align-items: center; gap: 9px;
      padding: 8px 12px; border-radius: 8px;
      color: var(--tx2); font-size: 13px; font-weight: 500;
      text-decoration: none; margin-bottom: 1px;
      transition: background 0.13s, color 0.13s;
      position: relative; letter-spacing: -0.01em;
    }
    .nav-item:hover { background: var(--bg-hover); color: var(--tx); }
    .nav-item:hover .nav-icon { color: var(--tx2); }
    .nav-item:not(.active):hover::before {
      content: '';
      position: absolute; left: 0; top: 10px; bottom: 10px;
      width: 2px; border-radius: 0 2px 2px 0;
      background: var(--ac-soft);
    }
    .nav-item.active { background: var(--ac-soft); color: var(--ac); font-weight: 600; }
    .nav-item.active::before {
      content: '';
      position: absolute; left: 0; top: 7px; bottom: 7px;
      width: 3px; border-radius: 0 3px 3px 0;
      background: var(--ac);
    }
    .nav-item.active .nav-icon { color: var(--ac-mid); }
    .nav-icon { width: 16px; height: 16px; flex-shrink: 0; color: var(--tx3); transition: color 0.13s; }
    .nav-badge {
      margin-left: auto; background: var(--gr);
      color: #fff; font-size: 10px; font-weight: 700;
      padding: 1px 6px; border-radius: 100px; min-width: 18px;
      text-align: center; line-height: 1.6; letter-spacing: 0;
    }
    .nav-badge.red { background: var(--s-err); }
    .nav-badge.amber { background: var(--s-warn); }

    /* ── Sección colapsable ── */
    .sidebar-section-label.collapsible { cursor: pointer; user-select: none; }
    .sidebar-section-label.collapsible:hover { color: var(--tx2); }
    .section-chevron { flex-shrink: 0; order: 2; color: var(--brd); transition: transform 0.2s ease, color 0.12s; }
    .sidebar-section-label.collapsible::after { order: 1; display: none; }
    .sidebar-section.collapsed .section-chevron { transform: rotate(-90deg); }
    .section-items { overflow: hidden; max-height: 600px; transition: max-height 0.25s ease, opacity 0.18s ease; opacity: 1; }
    .sidebar-section.collapsed .section-items { max-height: 0; opacity: 0; }

    .sidebar-bottom {
      margin-top: auto; padding: 10px 10px 12px;
      border-top: 1px solid var(--brd2);
      position: relative;
    }
    .user-card {
      display: flex; align-items: center; gap: 9px;
      padding: 8px 10px; border-radius: 8px;
      background: transparent; border: 1px solid transparent;
      cursor: pointer; transition: background 0.12s, border-color 0.12s; user-select: none;
    }
    .user-card:hover { background: var(--bg-hover); border-color: var(--brd); }
    .user-avatar {
      width: 30px; height: 30px; border-radius: 8px;
      background: linear-gradient(135deg, var(--gr) 0%, var(--gr-h) 100%);
      display: flex; align-items: center; justify-content: center;
      font-size: 11px; font-weight: 700; color: #fff; flex-shrink: 0; text-transform: uppercase;
    }
    .user-info { flex: 1; min-width: 0; }
    .user-name { font-size: 12px; font-weight: 600; color: var(--tx); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .user-role { font-size: 10.5px; color: var(--tx3); text-transform: capitalize; }
    .user-chevron { color: var(--brd); flex-shrink:0; transition: transform 0.2s; }
    .user-card.open .user-chevron { transform: rotate(180deg); }

    /* Popup menú usuario */
    .user-popup {
      position: absolute; bottom: calc(100% + 6px); left: 8px; right: 8px;
      background: var(--bg-surface); border: 1px solid var(--brd); border-radius: 10px;
      box-shadow: 0 6px 24px rgba(0,0,0,0.20);
      overflow: hidden; display: none; z-index: 200;
      animation: fadeUp 0.15s ease both;
    }
    .user-popup.open { display: block; }
    .user-popup-header {
      padding: 10px 14px; border-bottom: 1px solid var(--brd2);
      display: flex; align-items: flex-start; gap: 8px;
    }
    .user-popup-avatar {
      width: 30px; height: 30px; border-radius: 50%; flex-shrink: 0;
      background: var(--gr); display: flex; align-items: center;
      justify-content: center; font-size: 12px; font-weight: 700;
      color: #fff; text-transform: uppercase; margin-top: 1px;
    }
    .user-popup-name { font-size: 12px; font-weight: 700; color: var(--tx); line-height: 1.35; word-break: break-word; }
    .user-popup-email { font-size: 10px; color: var(--tx2); margin-top: 1px; word-break: break-all; line-height: 1.3; }
    .user-popup-item {
      display: flex; align-items: center; gap: 8px;
      padding: 9px 14px; font-size: 12px; font-weight: 500;
      color: var(--tx); text-decoration: none; transition: background 0.1s;
    }
    .user-popup-item:hover { background: var(--ac-soft); color: var(--ac); }
    .user-popup-item.danger:hover { background: var(--s-err-bg); color: var(--s-err); }
    .user-popup-item svg { flex-shrink: 0; width:14px; height:14px; }

    /* ── Header ── */
    .header {
      position: fixed; top: var(--ann-h); left: var(--sidebar-current); right: 0;
      height: calc(var(--header-h) + var(--safe-top)); background: var(--bg-header);
      border-bottom: 1px solid var(--brd);
      display: flex; align-items: center; justify-content: space-between;
      padding: var(--safe-top) max(24px, var(--safe-right)) 0 max(24px, var(--safe-left));
      z-index: 90;
      transition: left 0.25s ease, top 0.25s ease;
    }
    .header-title { display: none; }

    /* ── Buscador global ── */
    .global-search-wrap { position: relative; flex: 1; max-width: 560px; }
    .global-search-inner { position: relative; }
    .global-search-inner input {
      width: 100%; padding: 8px 36px 8px 34px;
      border: 1.5px solid var(--brd); border-radius: 9px;
      font-size: 13px; font-family: 'Inter', sans-serif;
      background: var(--bg-input); color: var(--tx); outline: none;
      transition: border-color 0.2s, background 0.2s;
    }
    .global-search-inner input:focus { border-color: var(--ac); background: var(--bg-surface); box-shadow: 0 0 0 3px rgba(29,78,216,0.09); outline: none; }
    .global-search-inner input::placeholder { color: var(--tx3); }
    .global-search-inner kbd {
      position: absolute; right: 9px; top: 50%; transform: translateY(-50%);
      font-size: 10px; color: var(--tx2); background: var(--brd);
      padding: 1px 5px; border-radius: 4px; font-family: inherit; pointer-events: none;
    }
    .global-search-inner input:focus ~ kbd { display: none; }
    .gs-dropdown {
      position: absolute; top: calc(100% + 6px); left: 0; right: 0;
      background: var(--bg-surface); border: 1px solid var(--brd); border-radius: 10px;
      box-shadow: 0 8px 28px rgba(29,78,216,0.12); z-index: 999; overflow: hidden;
      max-height: 380px; overflow-y: auto;
    }
    .gs-item {
      display: flex; align-items: center; gap: 10px;
      padding: 10px 14px; cursor: pointer; text-decoration: none;
      transition: background 0.1s; border-bottom: 1px solid var(--brd2);
    }
    .gs-item:last-child { border-bottom: none; }
    .gs-item:hover { background: var(--ac-soft); }
    .gs-icon { width: 30px; height: 30px; border-radius: 7px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; }
    .gs-titulo { font-size: 13px; font-weight: 600; color: var(--tx); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .gs-sub { font-size: 11px; color: var(--tx2); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .gs-tipo { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-left: auto; flex-shrink: 0; padding: 1px 6px; border-radius: 4px; }
    .gs-empty { padding: 20px; text-align: center; color: var(--tx2); font-size: 13px; }
    .gs-loading { padding: 14px; text-align: center; color: var(--tx2); font-size: 13px; }
    @media (max-width: 768px) { .global-search-wrap { display: none; } }
    .header-right { display: flex; align-items: center; gap: 12px; }
    .header-date { font-size: 12px; color: var(--tx2); }
    .notif-btn {
      width: 36px; height: 36px; border-radius: 8px;
      background: var(--bg-base); border: 1px solid var(--brd);
      cursor: pointer; display: flex; align-items: center; justify-content: center;
      color: var(--tx2); position: relative; transition: background 0.15s;
    }
    .notif-btn:hover { background: var(--ac-soft); color: var(--ac); }
    .notif-dot {
      position: absolute; top: 6px; right: 6px;
      width: 8px; height: 8px; border-radius: 50%;
      background: var(--s-err); border: 2px solid var(--bg-surface);
    }

    /* ── Main ── */
    .main {
      margin-left: var(--sidebar-current);
      margin-top: calc(var(--header-h) + var(--ann-h) + var(--safe-top));
      padding: 28px;
      min-height: calc(100vh - var(--header-h) - var(--ann-h) - var(--safe-top));
      transition: margin-left 0.25s ease, margin-top 0.25s ease;
    }

    /* ── Select: flecha visible en todos los selects ── */
    select:not([multiple]):not([size]) {
      appearance: none; -webkit-appearance: none;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%234b5563' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
      background-repeat: no-repeat; background-position: right 10px center; padding-right: 30px;
    }

    /* ── Inputs numéricos: quitar flechitas de incremento ── */
    input[type="number"] { -moz-appearance: textfield; appearance: textfield; }
    input[type="number"]::-webkit-inner-spin-button,
    input[type="number"]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }

    /* ── Date/time: el panel desplegable nativo respeta el tema (claro/oscuro) ── */
    :root[data-tema="oscuro"] input[type="date"],
    :root[data-tema="oscuro"] input[type="datetime-local"],
    :root[data-tema="oscuro"] input[type="month"],
    :root[data-tema="oscuro"] input[type="time"] { color-scheme: dark; }

    /* ── Utilidades globales ── */
    .txt-ellipsis {
      white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
      max-width: 220px; display: inline-block; vertical-align: middle;
    }

    /* ── Tooltip en nombres recortados (desktop hover + mobile tap) ── */
    [data-tip] { position: relative; }
    [data-tip]::after {
      content: attr(data-tip);
      position: absolute;
      bottom: calc(100% + 8px);
      left: 50%; transform: translateX(-50%);
      background: rgba(15,23,42,.93);
      color: #f1f5f9;
      font-size: 11px; font-weight: 500; font-family: 'Inter', sans-serif;
      padding: 6px 11px;
      border-radius: 7px;
      white-space: normal; max-width: 260px; text-align: center;
      line-height: 1.45;
      z-index: 9999; pointer-events: none;
      opacity: 0; transition: opacity .15s;
      box-shadow: 0 4px 14px rgba(0,0,0,.28);
    }
    [data-tip]:hover::after { opacity: 1; }
    /* Mobile: show on focus/active (long-press triggers active) */
    @media (hover: none) {
      [data-tip]:active::after { opacity: 1; }
    }

    /* ── txt-ellipsis interactivo: expandir + copiar ── */
    /* Texto siempre seleccionable con drag */
    .txt-ellipsis { user-select: text; }

    /* ── Todos los .txt-ellipsis: hint de copiado al hover ──
       Aplica a nombres cortos, largos, truncados y no truncados */
    .txt-ellipsis:hover {
      color: var(--azul, #1a3a8f);
      transition: color 0.15s;
    }

    /* Nombres truncados: el nombre completo se ve en el tooltip (data-tip) al
       hacer hover. Ya NO se expande al clic (el clic navega vía .row-link), así
       que no llevan subrayado punteado ni cursor extra. */

    /* ── Botón flotante copiar ── */
    #ggi-copy-btn {
      position: fixed; z-index: 9998;
      background: var(--gris-card, #fff);
      border: 1px solid var(--gris-borde, #e2e8f0);
      border-radius: 6px; padding: 0;
      width: 26px; height: 26px; line-height: 1;
      color: var(--gris-texto, #64748b);
      cursor: pointer;
      display: flex; align-items: center; justify-content: center;
      opacity: 0;
      transition: opacity 0.15s, background 0.15s, color 0.15s, border-color 0.15s;
      pointer-events: none;
      box-shadow: 0 2px 8px rgba(0,0,0,0.12);
      user-select: none;
    }
    #ggi-copy-btn.visible    { opacity: 1; pointer-events: auto; }
    #ggi-copy-btn:hover      { background: var(--azul-light,#e8edf8); color: var(--azul,#1a3a8f); border-color: var(--azul,#1a3a8f); }
    #ggi-copy-btn.ggi-copied { background: #d1fae5; color: #059669; border-color: #6ee7b7; }

    /* ── Tooltip dark mode ── */
    :root[data-tema="oscuro"] #ggi-copy-btn { background: #1A2840; border-color: #253B56; color: #8893A8; }
    :root[data-tema="oscuro"] #ggi-copy-btn:hover { background: #162d78; color: #93c5fd; border-color: #162d78; }

    /* ── Quitar lupa del buscador en todos los módulos ── */
    .search-wrap > svg:first-child { display: none !important; }
    .search-input { padding-left: 12px !important; }
    /* Lupa en autocomplete de empresa (crear/editar propuestas y envíos) */
    .ss-wrap .ss-icon { display: none !important; }
    .ss-input { padding-left: 12px !important; }

    .page-header { margin-bottom: 24px; }
    .page-header h1 { font-family: 'Inter', sans-serif; font-size: 22px; font-weight: 700; color: var(--tx); }
    .page-header p { font-size: 13px; color: var(--tx2); margin-top: 4px; }

    .card { background: var(--bg-surface); border-radius: 12px; border: 1px solid var(--brd); padding: 20px; }

    .btn {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 9px 16px; border-radius: 8px; font-size: 13px;
      font-weight: 600; font-family: 'Inter', sans-serif;
      cursor: pointer; border: none; text-decoration: none; transition: all 0.15s;
    }
    .btn-primary { background: var(--ac); color: #fff; }
    .btn-primary:hover { background: var(--ac-mid); box-shadow: 0 4px 12px rgba(29,78,216,0.25); }
    .btn-success { background: var(--gr); color: #fff; }
    .btn-success:hover { background: var(--gr-h); }
    .btn-outline { background: var(--bg-surface); color: var(--tx); border: 1.5px solid var(--brd); }
    .btn-outline:hover { border-color: var(--ac); color: var(--ac); background: var(--ac-soft); }
    /* Ghost: botón sutil (fondo transparente). Estandarizado con efecto hover. */
    .btn-ghost { background: transparent; color: var(--tx2); border: 1px solid var(--brd); transition: background .15s, color .15s, border-color .15s; }
    .btn-ghost:hover { background: var(--bg-hover); color: var(--tx); border-color: var(--ac); }
    .btn-danger { background: var(--s-err); color: #fff; }
    .btn-sm { padding: 6px 12px; font-size: 12px; }
    .btn:focus-visible { outline: 2px solid var(--ac); outline-offset: 2px; }
    .btn-primary:focus-visible { outline-color: #fff; box-shadow: 0 0 0 4px var(--ac); }
    input:focus-visible, select:focus-visible, textarea:focus-visible {
      outline: none; border-color: var(--ac) !important;
      box-shadow: 0 0 0 3px rgba(29,78,216,0.09) !important;
    }

    /* Sidebar colapsable en desktop — modo mini (solo iconos) */
    .sidebar.collapsed { width: var(--sidebar-mini); overflow: hidden; }
    /* Header y main se mueven a través de --sidebar-current (gestionada por JS) */

    /* Anti-FOUC: estado pre-colapsado aplicado antes del primer paint */
    ._sb-pre .sidebar { width: var(--sidebar-mini) !important; }
    ._sb-pre .sidebar .sidebar-logo-text,
    ._sb-pre .sidebar .sidebar-section-label,
    ._sb-pre .sidebar .nav-badge,
    ._sb-pre .sidebar .section-chevron,
    ._sb-pre .sidebar .user-info,
    ._sb-pre .sidebar .user-chevron { display: none !important; }
    ._sb-pre .sidebar .sidebar-logo { justify-content: center; padding: 18px 0 16px; }
    ._sb-pre .sidebar .nav-item { justify-content: center; gap: 0; padding: 9px 0; font-size: 0; }

    /* Ocultar texto y elementos no-icono cuando está colapsado */
    .sidebar.collapsed .sidebar-logo-text,
    .sidebar.collapsed .sidebar-section-label,
    .sidebar.collapsed .nav-badge,
    .sidebar.collapsed .section-chevron,
    .sidebar.collapsed .user-info,
    .sidebar.collapsed .user-chevron { display: none; }

    .sidebar.collapsed .sidebar-logo { justify-content: center; padding: 18px 0 16px; }
    .sidebar.collapsed .sidebar-section { padding: 10px 6px 4px; }
    .sidebar.collapsed .nav-item {
      justify-content: center; gap: 0; padding: 9px 0;
      font-size: 0;       /* oculta el texto del nodo */
      border-radius: 8px; overflow: visible; position: relative;
    }
    .sidebar.collapsed .nav-icon { width: 18px; height: 18px; flex-shrink: 0; }
    .sidebar.collapsed .sidebar-bottom { padding: 8px 6px 10px; }
    .sidebar.collapsed .user-card { justify-content: center; padding: 8px 0; }
    /* Popup en modo colapsado: position:fixed para escapar overflow:hidden del sidebar */
    .sidebar.collapsed .user-popup {
      position: fixed;
      left: calc(var(--sidebar-mini) + 8px);
      bottom: 10px;
      right: auto;
      width: 220px;
      top: auto;
    }

    /* Tooltip al hacer hover cuando está colapsado */
    .sidebar.collapsed .nav-item::after {
      content: attr(data-label);
      position: absolute; left: calc(var(--sidebar-mini) - 2px); top: 50%;
      transform: translateY(-50%);
      background: var(--bg-sidebar); border: 1px solid var(--brd);
      color: var(--tx); font-size: 12px; font-weight: 500;
      white-space: nowrap; padding: 5px 10px; border-radius: 7px;
      box-shadow: 0 4px 14px rgba(0,0,0,.18);
      pointer-events: none; opacity: 0; transition: opacity .15s;
      z-index: 200;
    }
    .sidebar.collapsed .nav-item:hover::after { opacity: 1; }

    /* Botón toggle desktop */
    .btn-toggle-desktop {
      display: flex; align-items: center; justify-content: center;
      width: 32px; height: 32px; border-radius: 7px;
      background: var(--bg-base); border: 1px solid var(--brd);
      cursor: pointer; color: var(--tx2); flex-shrink: 0;
      transition: background 0.15s, color 0.15s;
    }
    .btn-toggle-desktop:hover { background: var(--ac-soft); color: var(--ac); }

    /* ── Botón hamburguesa (solo móvil) ── */
    .btn-menu {
      display:none; width:36px; height:36px; border-radius:8px;
      background:var(--gris-bg); border:1px solid var(--gris-borde);
      cursor:pointer; align-items:center; justify-content:center;
      color:var(--azul); flex-shrink:0;
    }

    @media (max-width: 768px) {
      :root { --header-h: 50px; }
      /* Sidebar móvil — permanente en <head> para sobrevivir el swap SPA */
      .btn-menu { display:flex; }
      .sidebar { transform: translateX(-100%); transition:transform 0.25s ease; z-index:101; }
      .sidebar.open { transform: translateX(0); }
      /* Touch targets ≥44px en el drawer móvil (Apple HIG / Material 48dp).
         En desktop los ítems son compactos (mouse); en táctil deben ser cómodos. */
      .sidebar .nav-item { min-height: 44px; padding-top: 10px; padding-bottom: 10px; }
      .sidebar .user-card { min-height: 44px; }
      .sidebar .sidebar-section-label.collapsible { min-height: 36px; }
      .header { left: 0; padding: var(--safe-top) max(14px, var(--safe-right)) 0 max(14px, var(--safe-left)); }
      .main { margin-left: 0; padding: 12px max(12px, var(--safe-right)) calc(12px + var(--safe-bottom)) max(12px, var(--safe-left)); }
      #ggi-ann-bar .ggi-ann-text { white-space: normal; }
      .btn-toggle-desktop { display: none; }
      .sidebar.open .user-popup {
        position: fixed;
        left: 10px;
        right: 10px;
        bottom: 10px;
        width: auto;
        z-index: 9999;
      }
      /* ── Tipografía compacta ── */
      .page-header { margin-bottom: 12px !important; }
      .page-header h1 { font-size: 17px !important; }
      .page-header p { font-size: 12px !important; margin-top: 2px !important; }
      /* ── Cards y paneles ── */
      .card { padding: 14px !important; }
      /* ── Espaciado entre secciones ── */
      .panel, .det-card, .form-card { margin-bottom: 12px !important; }
      .stat-row, .stats-grid, .rep-kpis, .quick-grid { gap: 10px !important; margin-bottom: 12px !important; }
    }
    @media (max-width: 480px) {
      .main { padding: 8px max(8px, var(--safe-right)) calc(8px + var(--safe-bottom)) max(8px, var(--safe-left)); }
      .page-header h1 { font-size: 15px !important; }
      .page-header { margin-bottom: 10px !important; }
      .card { padding: 12px !important; }
    }

  </style>
  <!-- Sidebar + tema: aplicar antes de pintar para evitar flash -->
  <script>
  (function(){
    /* Sidebar collapsed state → set --sidebar-current immediately */
    try {
      if (localStorage.getItem('sidebar_collapsed') === '1' && window.innerWidth > 768) {
        document.documentElement.style.setProperty('--sidebar-current', '56px');
        document.documentElement.classList.add('_sb-pre');
      }
    } catch(e) {}
    var t = localStorage.getItem('ggi_tema') || 'sistema';
    var root = document.documentElement;
    var isDark = t === 'oscuro' || (t === 'sistema' && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
    if (isDark) {
      root.setAttribute('data-tema', 'oscuro');
    } else {
      root.setAttribute('data-tema', 'claro');
      root.style.removeProperty('background');
      root.style.removeProperty('background-color');
    }
  })();
  </script>
  <style>
    /*
     * DARK MODE — token-based overrides
     * Tokens flip automatically via :root[data-tema="oscuro"] in the first <style> block.
     * Only body/layout forces, sidebar specifics, module components with hardcoded
     * colors, and inline-style overrides remain here.
     */

    /* ── Body / layout ── */
    :root[data-tema="oscuro"] body { background: var(--bg-base) !important; color: var(--tx) !important; font-family: 'Inter', sans-serif !important; }
    :root[data-tema="oscuro"] .main { background: var(--bg-base) !important; }
    /* ── Safety net: garantiza fondo claro aunque el inline style de html persista ── */
    html:not([data-tema="oscuro"]) body { background: var(--bg-base) !important; color: var(--tx) !important; }
    html:not([data-tema="oscuro"]) .main { background: var(--bg-base) !important; }
    /* ── Font enforcement ── */
    :root[data-tema="oscuro"] h1, :root[data-tema="oscuro"] h2,
    :root[data-tema="oscuro"] h3, :root[data-tema="oscuro"] h4,
    :root[data-tema="oscuro"] label, :root[data-tema="oscuro"] th,
    :root[data-tema="oscuro"] .section-title, :root[data-tema="oscuro"] .form-label,
    :root[data-tema="oscuro"] .field-label, :root[data-tema="oscuro"] .panel-title
    { font-family: 'Inter', sans-serif !important; }
    :root[data-tema="oscuro"] h2, :root[data-tema="oscuro"] h3, :root[data-tema="oscuro"] h4
    { color: #F1F5F9 !important; }

    /* ── Header shadow ── */
    :root[data-tema="oscuro"] .header
    { box-shadow: 0 1px 0 var(--brd), 0 2px 20px rgba(0,0,0,0.32) !important; }

    /* ── Sidebar specifics (dark sidebar background is very deep navy) ── */
    :root[data-tema="oscuro"] .sidebar-logo-icon
    { background: rgba(255,255,255,0.06) !important; }
    :root[data-tema="oscuro"] .sidebar-logo-icon img
    { filter: brightness(0) invert(1) !important; opacity: 0.85; }
    :root[data-tema="oscuro"] .nav-item.active::before
    { background: var(--gr) !important; }
    :root[data-tema="oscuro"] .sidebar-nav
    { scrollbar-color: rgba(255,255,255,0.06) transparent !important; }
    :root[data-tema="oscuro"] .sidebar-nav::-webkit-scrollbar-thumb
    { background: rgba(255,255,255,0.06) !important; }

    /* ── Module surfaces (hardcoded bg in modules) ── */
    :root[data-tema="oscuro"] .card, :root[data-tema="oscuro"] .panel,
    :root[data-tema="oscuro"] .stat-card,
    :root[data-tema="oscuro"] .table-wrap, :root[data-tema="oscuro"] .form-card,
    :root[data-tema="oscuro"] .upload-card, :root[data-tema="oscuro"] .detail-panel,
    :root[data-tema="oscuro"] .info-card, :root[data-tema="oscuro"] .rep-card,
    :root[data-tema="oscuro"] .rep-kpi, :root[data-tema="oscuro"] .stat-mini,
    :root[data-tema="oscuro"] .quick-btn, :root[data-tema="oscuro"] .cuenta-section,
    :root[data-tema="oscuro"] .cuenta-nav, :root[data-tema="oscuro"] .notif-list,
    :root[data-tema="oscuro"] .notif-item, :root[data-tema="oscuro"] .mant-table-card,
    :root[data-tema="oscuro"] .mant-form-card, :root[data-tema="oscuro"] .env-header,
    :root[data-tema="oscuro"] .tema-opt, :root[data-tema="oscuro"] .mant-modal-box,
    :root[data-tema="oscuro"] .form-actions, :root[data-tema="oscuro"] .form-section,
    :root[data-tema="oscuro"] .cliente-header, :root[data-tema="oscuro"] .cert-header,
    :root[data-tema="oscuro"] .aud-header, :root[data-tema="oscuro"] .doc-card,
    :root[data-tema="oscuro"] .doc-viewer-card, :root[data-tema="oscuro"] .com-form,
    :root[data-tema="oscuro"] .kpi, :root[data-tema="oscuro"] .cot-card,
    :root[data-tema="oscuro"] .det-card, :root[data-tema="oscuro"] .seg-panel,
    :root[data-tema="oscuro"] .modal-box
    { background: var(--bg-surface) !important; border-color: var(--brd) !important; color: var(--tx) !important; }

    :root[data-tema="oscuro"] .card, :root[data-tema="oscuro"] .panel,
    :root[data-tema="oscuro"] .form-card, :root[data-tema="oscuro"] .det-card,
    :root[data-tema="oscuro"] .table-wrap, :root[data-tema="oscuro"] .mant-table-card,
    :root[data-tema="oscuro"] .notif-list
    { box-shadow: 0 1px 3px rgba(0,0,0,0.32), 0 4px 20px rgba(0,0,0,0.20) !important; }
    :root[data-tema="oscuro"] .table-wrap
    { box-shadow: 0 0 0 1px var(--brd) !important; border-radius: 12px !important; overflow: hidden !important; }
    :root[data-tema="oscuro"] .doc-card.uploaded
    { background: #1e3028 !important; border-color: #2e5e3a !important; }

    /* ── Tables ── */
    :root[data-tema="oscuro"] .data-table { border: none !important; }
    :root[data-tema="oscuro"] .data-table th,
    :root[data-tema="oscuro"] .mini-table th,
    :root[data-tema="oscuro"] .mant-table th,
    :root[data-tema="oscuro"] .table-rep th
    { background: var(--bg-th) !important; color: var(--tx2) !important; border-color: var(--brd) !important;
      font-family: 'Inter', sans-serif !important; font-size: 11px !important; font-weight: 600 !important;
      letter-spacing: .06em; text-transform: uppercase;
      border-left: none !important; border-right: none !important; border-top: none !important;
      border-bottom: 1px solid var(--brd) !important; }
    :root[data-tema="oscuro"] .data-table td,
    :root[data-tema="oscuro"] .mini-table td,
    :root[data-tema="oscuro"] .mant-table td,
    :root[data-tema="oscuro"] .table-rep td
    { border-color: var(--brd) !important; color: var(--tx) !important;
      font-family: 'Inter', sans-serif !important;
      border-left: none !important; border-right: none !important; border-top: none !important;
      border-bottom: 1px solid rgba(255,255,255,0.035) !important; }
    :root[data-tema="oscuro"] .data-table tbody tr:nth-child(even) td { background: transparent !important; }
    :root[data-tema="oscuro"] tr:hover td { background: var(--bg-hover) !important; }
    :root[data-tema="oscuro"] .data-table tr:hover td { background: rgba(59,130,246,0.05) !important; }
    :root[data-tema="oscuro"] .mant-table tr { background: var(--bg-surface) !important; border-color: var(--brd) !important; }

    /* ── Inputs ── */
    :root[data-tema="oscuro"] input:not([type=checkbox]):not([type=radio]):not([type=submit]):not([type=button]):not([type=range]),
    :root[data-tema="oscuro"] select,
    :root[data-tema="oscuro"] textarea
    { background-color: var(--bg-input) !important; border-color: var(--brd) !important; color: var(--tx) !important; font-family: 'Inter', sans-serif !important; }
    :root[data-tema="oscuro"] input::placeholder,
    :root[data-tema="oscuro"] textarea::placeholder { color: var(--tx3) !important; }
    :root[data-tema="oscuro"] input:focus, :root[data-tema="oscuro"] select:focus, :root[data-tema="oscuro"] textarea:focus
    { border-color: var(--ac) !important; box-shadow: 0 0 0 3px rgba(59,130,246,0.14) !important; }
    :root[data-tema="oscuro"] .seg-form-field select, :root[data-tema="oscuro"] .seg-form-field input,
    :root[data-tema="oscuro"] .seg-form-field textarea, :root[data-tema="oscuro"] .modal-field select,
    :root[data-tema="oscuro"] .modal-field input, :root[data-tema="oscuro"] .modal-field textarea,
    :root[data-tema="oscuro"] .search-input, :root[data-tema="oscuro"] .filter-select
    { background-color: var(--bg-surface) !important; color: var(--tx) !important; border-color: var(--brd) !important; }

    /* ── Tabs ── */
    :root[data-tema="oscuro"] .tabs { background: var(--bg-base) !important; }
    :root[data-tema="oscuro"] .tab { color: var(--tx2) !important; }
    :root[data-tema="oscuro"] .tab.active { background: var(--bg-surface) !important; color: var(--ac-mid) !important; }

    /* ── Buttons ── */
    :root[data-tema="oscuro"] .btn-outline
    { background: var(--bg-surface2) !important; border-color: var(--brd) !important; color: var(--tx) !important; }
    :root[data-tema="oscuro"] .btn-outline:hover
    { background: var(--bg-hover) !important; border-color: var(--brd) !important; color: var(--tx2) !important; }
    :root[data-tema="oscuro"] .filtro-btn, :root[data-tema="oscuro"] .btn-accion,
    :root[data-tema="oscuro"] .notif-btn-sm, :root[data-tema="oscuro"] .pag-btn
    { background: var(--bg-surface) !important; border-color: var(--brd) !important; color: var(--tx2) !important; }
    :root[data-tema="oscuro"] .filtro-btn:hover, :root[data-tema="oscuro"] .filtro-btn.active,
    :root[data-tema="oscuro"] .btn-accion:hover, :root[data-tema="oscuro"] .notif-btn-sm:hover,
    :root[data-tema="oscuro"] .pag-btn:hover, :root[data-tema="oscuro"] .pag-btn.active
    { background: var(--bg-hover) !important; border-color: var(--brd) !important; color: var(--tx2) !important; }
    :root[data-tema="oscuro"] .btn-icon
    { background: var(--bg-surface2) !important; border-color: var(--brd) !important; color: var(--tx2) !important; }
    :root[data-tema="oscuro"] .btn-icon:hover
    { background: var(--bg-hover) !important; border-color: var(--brd) !important; }
    :root[data-tema="oscuro"] .btn-icon.danger:hover
    { background: #2d1e20 !important; border-color: var(--s-err) !important; color: var(--s-err) !important; }

    /* ── Btn-ic colored: modo oscuro ahora vía tokens en design-system.css
       (.btn-ic-* usa var(--ac-soft)/--s-err-bg/--s-warn-bg/--gr-soft, que ya
       tienen variante oscura). Bloque hardcodeado eliminado para evitar conflicto. ── */

    /* ── Btn-acc (pagos) ── */
    :root[data-tema="oscuro"] .btn-acc        { background: var(--bg-surface2) !important; border-color: var(--brd) !important; color: var(--tx2) !important; }
    :root[data-tema="oscuro"] .btn-acc-blue   { background: #1E3558 !important; color: #60A5FA !important; border-color: rgba(96,165,250,0.25) !important; }
    :root[data-tema="oscuro"] .btn-acc-green  { background: rgba(52,211,153,0.12) !important; color: #34D399 !important; border-color: rgba(52,211,153,0.22) !important; }
    :root[data-tema="oscuro"] .btn-acc-red    { background: rgba(248,113,113,0.10) !important; color: #f87171 !important; border-color: rgba(248,113,113,0.2) !important; }
    :root[data-tema="oscuro"] .btn-acc-amber  { background: rgba(251,191,36,0.10) !important; color: #FBBF24 !important; border-color: rgba(251,191,36,0.2) !important; }
    :root[data-tema="oscuro"] .pago-cell-monto { color: var(--tx) !important; font-weight: 700; }
    :root[data-tema="oscuro"] .pago-actualizado { color: #34D399 !important; }

    /* ── Misc module components ── */
    :root[data-tema="oscuro"] .quick-btn { color: var(--tx2) !important; }
    :root[data-tema="oscuro"] .quick-btn:hover { background: var(--bg-surface2) !important; border-color: var(--ac) !important; color: var(--ac) !important; }
    :root[data-tema="oscuro"] .quick-btn:hover .qb-icon { background: var(--ac) !important; color: #fff !important; }
    :root[data-tema="oscuro"] .read-field { background: var(--bg-surface2) !important; color: var(--tx2) !important; border-color: var(--brd) !important; }
    :root[data-tema="oscuro"] .cuenta-nav-item { color: var(--tx2) !important; border-color: var(--brd) !important; }
    :root[data-tema="oscuro"] .cuenta-nav-item:hover,
    :root[data-tema="oscuro"] .cuenta-nav-item.active { background: var(--bg-hover) !important; }
    :root[data-tema="oscuro"] .cuenta-head { border-color: var(--brd) !important; }
    :root[data-tema="oscuro"] .cuenta-title { color: #F1F5F9 !important; }
    :root[data-tema="oscuro"] .cuenta-desc { color: var(--tx2) !important; }
    :root[data-tema="oscuro"] .divider, :root[data-tema="oscuro"] .str-track { background: var(--brd) !important; }
    :root[data-tema="oscuro"] .tema-opt.sel { border-color: #475569 !important; background: var(--bg-hover) !important; }
    :root[data-tema="oscuro"] .tema-lbl { color: var(--tx) !important; }
    :root[data-tema="oscuro"] .tema-opt.sel .tema-lbl { color: var(--tx2) !important; }
    /* ── Norma items — cotizaciones/crear (full interactive coverage) ── */
    :root[data-tema="oscuro"] .norma-item { background: var(--bg-surface2) !important; border-color: var(--brd) !important; color: var(--tx) !important; }
    :root[data-tema="oscuro"] .norma-item:hover { border-color: var(--ac-mid) !important; }
    :root[data-tema="oscuro"] .norma-item:has(input:checked) { background: rgba(59,130,246,.10) !important; border-color: var(--ac-mid) !important; color: var(--tx) !important; }
    /* Child text elements have own color declarations that override inherited color — must target explicitly */
    :root[data-tema="oscuro"] .norma-nombre { color: var(--tx) !important; }
    :root[data-tema="oscuro"] .norma-desc-text { color: var(--tx2) !important; }
    :root[data-tema="oscuro"] .norma-badge-precio { background: var(--bg-surface) !important; border-color: var(--brd) !important; color: var(--tx2) !important; }
    :root[data-tema="oscuro"] .norma-inline-fields { background: rgba(59,130,246,.07) !important; border-left-color: rgba(59,130,246,.22) !important; }
    :root[data-tema="oscuro"] .norma-field-group { border-right-color: rgba(59,130,246,.12) !important; }
    :root[data-tema="oscuro"] .norma-field-lbl { color: var(--tx2) !important; }
    :root[data-tema="oscuro"] .norma-precio,
    :root[data-tema="oscuro"] .norma-descuento,
    :root[data-tema="oscuro"] .norma-casa-select { background-color: var(--bg-surface) !important; border-color: var(--brd) !important; color: var(--tx) !important; }
    :root[data-tema="oscuro"] .norma-precio:focus,
    :root[data-tema="oscuro"] .norma-descuento:focus,
    :root[data-tema="oscuro"] .norma-casa-select:focus { border-color: var(--ac-mid) !important; box-shadow: 0 0 0 2px rgba(59,130,246,.18) !important; }
    :root[data-tema="oscuro"] .norma-max-hint { color: #64748b !important; }
    /* ── Empresa card (cotizaciones/crear) ── */
    :root[data-tema="oscuro"] .empresa-card { background: rgba(59,130,246,.08) !important; border-color: rgba(59,130,246,.22) !important; }
    :root[data-tema="oscuro"] .empresa-card-nombre { color: var(--ac-mid) !important; }
    :root[data-tema="oscuro"] .empresa-card-ruc { color: #7EB3F5 !important; }
    :root[data-tema="oscuro"] .empresa-card-change { background: var(--bg-surface) !important; border-color: var(--brd) !important; color: var(--tx2) !important; }
    :root[data-tema="oscuro"] .empresa-card-change:hover { border-color: var(--ac-mid) !important; color: var(--tx) !important; }
    /* ── Calendar (auditorias/calendario) ── */
    :root[data-tema="oscuro"] .cal-day { border-color: var(--brd) !important; }
    :root[data-tema="oscuro"] .cal-day.empty { background: var(--bg-base) !important; }
    :root[data-tema="oscuro"] .cal-day.today { background: rgba(59,130,246,.12) !important; border-color: rgba(59,130,246,.3) !important; }
    /* ── Portal status (clientes/editar) ── */
    :root[data-tema="oscuro"] .portal-status-banner.active { background: rgba(52,211,153,.07) !important; border-color: rgba(52,211,153,.22) !important; }
    :root[data-tema="oscuro"] .portal-status-icon.active { background: rgba(52,211,153,.15) !important; color: #34D399 !important; }
    :root[data-tema="oscuro"] .portal-status-icon.inactive { background: var(--bg-surface2) !important; color: var(--tx2) !important; }
    :root[data-tema="oscuro"] .portal-status-label.active { color: #34D399 !important; }
    :root[data-tema="oscuro"] .portal-status-label.inactive { color: var(--tx2) !important; }
    /* ── Kanban (envios/kanban) ── */
    :root[data-tema="oscuro"] .kanban-card { background: var(--bg-surface) !important; border-color: var(--brd) !important; }
    :root[data-tema="oscuro"] .kanban-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,.35) !important; }
    :root[data-tema="oscuro"] .col-body { background: var(--bg-base) !important; }
    /* ── Dir-check / file-info (envios/crear) ── */
    :root[data-tema="oscuro"] .dir-check { background: rgba(52,211,153,.06) !important; border-color: rgba(52,211,153,.2) !important; }
    :root[data-tema="oscuro"] .dir-check:hover { border-color: #34D399 !important; }
    :root[data-tema="oscuro"] .file-info { background: var(--bg-surface2) !important; border-color: var(--brd) !important; }
    /* ── Pagos module ── */
    :root[data-tema="oscuro"] .kpi-value.amber { color: #FBBF24 !important; }
    :root[data-tema="oscuro"] .kpi-value.blue  { color: var(--ac-mid) !important; }
    :root[data-tema="oscuro"] .kpi-value.green { color: #34D399 !important; }
    :root[data-tema="oscuro"] .kpi-value.red   { color: #f87171 !important; }
    :root[data-tema="oscuro"] .kpi-value.teal  { color: #2DD4BF !important; }
    :root[data-tema="oscuro"] .pagos-table tr.highlight-row td { background: rgba(251,191,36,.08) !important; }
    :root[data-tema="oscuro"] .btn-acc-blue  { background: var(--bg-surface2) !important; border-color: var(--brd) !important; color: var(--tx2) !important; }
    :root[data-tema="oscuro"] .btn-acc-amber { background: rgba(251,191,36,.10) !important; border-color: rgba(251,191,36,.22) !important; color: #FBBF24 !important; }
    :root[data-tema="oscuro"] .mes-badge { background: var(--bg-surface2) !important; border-color: var(--brd) !important; color: var(--tx2) !important; }
    :root[data-tema="oscuro"] .tipo-btn { background: var(--bg-surface2) !important; border-color: var(--brd) !important; color: var(--tx) !important; }
    :root[data-tema="oscuro"] .tipo-btn.active { background: var(--bg-hover) !important; }
    :root[data-tema="oscuro"] .tipo-btn span, :root[data-tema="oscuro"] .tipo-btn.active span { color: var(--tx2) !important; }
    :root[data-tema="oscuro"] .notif-item:hover { background: var(--bg-hover) !important; }
    :root[data-tema="oscuro"] .notif-item.no-leida { background: #1B2540 !important; }
    :root[data-tema="oscuro"] .notif-item.no-leida::before { background: var(--ac-mid); }
    :root[data-tema="oscuro"] .panel-header, :root[data-tema="oscuro"] .rep-card-head,
    :root[data-tema="oscuro"] .detail-panel-header
    { background: var(--bg-surface) !important; border-color: var(--brd) !important; }
    :root[data-tema="oscuro"] .seg-panel-hdr, :root[data-tema="oscuro"] .seg-form-wrap
    { background: var(--bg-th) !important; border-color: var(--brd) !important; }
    :root[data-tema="oscuro"] .detail-row, :root[data-tema="oscuro"] .seg-item,
    :root[data-tema="oscuro"] .norma-row, :root[data-tema="oscuro"] .det-header,
    :root[data-tema="oscuro"] .det-section, :root[data-tema="oscuro"] .det-card,
    :root[data-tema="oscuro"] .seg-panel, :root[data-tema="oscuro"] .data-table td
    { border-color: var(--brd) !important; }
    :root[data-tema="oscuro"] .panel-title, :root[data-tema="oscuro"] .panel-header-title,
    :root[data-tema="oscuro"] .table-title, :root[data-tema="oscuro"] .card-header-title
    { color: #D0D8E8 !important; font-family: 'Inter', sans-serif !important; }
    :root[data-tema="oscuro"] .cert-title, :root[data-tema="oscuro"] .aud-title,
    :root[data-tema="oscuro"] .cliente-main h2 { color: #F1F5F9 !important; }
    :root[data-tema="oscuro"] .panel-link { color: var(--tx2) !important; }
    :root[data-tema="oscuro"] .stat-val, :root[data-tema="oscuro"] .info-card-value,
    :root[data-tema="oscuro"] .kpi-value { color: var(--tx) !important; font-family: 'Inter', sans-serif !important; }
    :root[data-tema="oscuro"] .stat-icon { background: rgba(255,255,255,0.07) !important; color: var(--tx) !important; }
    :root[data-tema="oscuro"] .stat-label { color: var(--tx2) !important; }
    :root[data-tema="oscuro"] a.stat-card:hover { border-color: var(--ac-mid) !important; background: var(--bg-surface2) !important; }
    :root[data-tema="oscuro"] a.stat-card:hover .stat-value { color: var(--ac-mid) !important; }
    :root[data-tema="oscuro"] .cal-mes, :root[data-tema="oscuro"] .sum-val { color: #D0D8E8 !important; }
    :root[data-tema="oscuro"] .feed-tipo { background: var(--bg-surface2) !important; color: var(--tx2) !important; border-color: var(--brd) !important; }
    :root[data-tema="oscuro"] .doc-tipo { color: var(--tx2) !important; }
    :root[data-tema="oscuro"] .card-header-icon { background: var(--bg-surface2) !important; color: var(--tx2) !important; }
    :root[data-tema="oscuro"] .bulk-count { color: var(--tx2) !important; }
    :root[data-tema="oscuro"] .norma-code { background: #1E3558 !important; color: var(--ac-mid) !important; }
    :root[data-tema="oscuro"] .ip-tag { background: var(--bg-surface2) !important; color: var(--tx2) !important; }
    :root[data-tema="oscuro"] .section-title { color: var(--tx2) !important; border-color: var(--brd) !important; }
    :root[data-tema="oscuro"] code { background: var(--bg-surface2) !important; color: #93C5FD !important; border-color: var(--brd) !important; }
    :root[data-tema="oscuro"] .page-header h1 { color: #F1F5F9 !important; font-family: 'Inter', sans-serif !important; }
    :root[data-tema="oscuro"] .page-header p { color: var(--tx2) !important; }
    :root[data-tema="oscuro"] .mant-modal-box { background: var(--bg-surface) !important; border-color: var(--brd) !important; }
    :root[data-tema="oscuro"] .mant-modal-header { background: var(--bg-surface) !important; border-color: var(--brd) !important; }
    :root[data-tema="oscuro"] .mant-modal-title { color: #F1F5F9 !important; }
    :root[data-tema="oscuro"] .mant-modal-close { color: var(--tx2) !important; }
    :root[data-tema="oscuro"] .mant-modal-close:hover { background: var(--bg-surface2) !important; }
    :root[data-tema="oscuro"] .mant-card-title { color: #D0D8E8 !important; }
    :root[data-tema="oscuro"] .mant-card-header { border-color: var(--brd) !important; }

    /* ── Dropzones & upload areas (envios, auditorias, certificados) ── */
    :root[data-tema="oscuro"] .dropzone { background: var(--bg-surface) !important; border-color: var(--brd) !important; }
    :root[data-tema="oscuro"] .dropzone:hover,
    :root[data-tema="oscuro"] .upload-zone:hover { border-color: var(--ac-mid) !important; background: rgba(59,130,246,.06) !important; }
    /* ── Cert check items (envios/crear, envios/editar) ── */
    :root[data-tema="oscuro"] .cert-check-item { background: var(--bg-surface2) !important; border-color: var(--brd) !important; }
    :root[data-tema="oscuro"] .cert-check-item:hover { border-color: var(--ac-mid) !important; background: rgba(59,130,246,.08) !important; }
    :root[data-tema="oscuro"] .cert-check-item.checked { border-color: var(--ac-mid) !important; background: rgba(59,130,246,.12) !important; }
    :root[data-tema="oscuro"] .cert-check-norma { background: rgba(59,130,246,.15) !important; color: #93C5FD !important; }
    /* ── Seg-form (cotizaciones/seguimiento) ── */
    :root[data-tema="oscuro"] .seg-form { background: var(--bg-surface2) !important; border-color: var(--brd) !important; }
    /* ── Search suggestion highlight mark ── */
    :root[data-tema="oscuro"] .ss-mark { background: rgba(91,158,245,.22) !important; color: #7EB3F5 !important; }
    /* ── Norma-existente badge (JS-generated in crear.php) ── */
    :root[data-tema="oscuro"] .norma-existente-badge { background: rgba(248,113,113,.12) !important; border-color: rgba(248,113,113,.22) !important; color: #f87171 !important; }
    /* ── Disabled/opaque states ── */
    :root[data-tema="oscuro"] [style*="opacity:0.5"] .norma-item,
    :root[data-tema="oscuro"] .cert-check-item[style*="opacity:0.5"] { background: var(--bg-base) !important; }

    /* ── Alerts ── */
    :root[data-tema="oscuro"] .alert-ok { background: #1e3028 !important; border-color: #38a852 !important; color: #34D399 !important; }
    :root[data-tema="oscuro"] .alert-err { background: #311e1e !important; border-color: #c53030 !important; color: #f87171 !important; }

    /* ── Badges ── */
    :root[data-tema="oscuro"] .badge-activo   { background: rgba(52,211,153,0.14) !important; color: #34D399 !important; border: 1px solid rgba(52,211,153,0.2) !important; }
    :root[data-tema="oscuro"] .badge-inactivo { background: rgba(148,163,184,0.10) !important; color: var(--tx2) !important; border: 1px solid rgba(148,163,184,0.18) !important; }
    :root[data-tema="oscuro"] .be-validado             { background: rgba(52,211,153,0.14) !important; color: #34D399 !important; }
    :root[data-tema="oscuro"] .be-rechazado            { background: rgba(248,113,113,0.12) !important; color: #f87171 !important; }
    :root[data-tema="oscuro"] .be-pendiente_validacion { background: rgba(96,165,250,0.14) !important; color: #60A5FA !important; }
    :root[data-tema="oscuro"] .be-pendiente_voucher    { background: rgba(251,191,36,0.12) !important; color: #FBBF24 !important; }
    :root[data-tema="oscuro"] .estado-badge { background: var(--bg-surface2) !important; border-color: #253550 !important; }
    :root[data-tema="oscuro"] .estado-badge[style*="color:#3B82F6"] { color: #5B9EF5 !important; }
    :root[data-tema="oscuro"] .estado-badge[style*="color:#10B981"] { color: #34D399 !important; }
    :root[data-tema="oscuro"] .estado-badge[style*="color:#EF4444"] { color: #F87171 !important; }
    :root[data-tema="oscuro"] .estado-badge[style*="color:#F59E0B"] { color: #FBBF24 !important; }
    :root[data-tema="oscuro"] .estado-badge[style*="color:#6B7280"] { color: var(--tx2) !important; }
    :root[data-tema="oscuro"] .cli-estado-select { font-weight: 700 !important; }

    /* ── Badge status genéricos (clientes, envíos, certificados, auditorías) ── */
    :root[data-tema="oscuro"] .badge-green  { background: rgba(16,185,129,.15)  !important; color: #34D399 !important; border-color: rgba(16,185,129,.22)  !important; }
    :root[data-tema="oscuro"] .badge-amber  { background: rgba(245,158,11,.14)  !important; color: #FBBF24 !important; border-color: rgba(245,158,11,.2)   !important; }
    :root[data-tema="oscuro"] .badge-red    { background: rgba(239,68,68,.14)   !important; color: #f87171 !important; border-color: rgba(239,68,68,.2)    !important; }
    :root[data-tema="oscuro"] .badge-gray   { background: rgba(148,163,184,.12) !important; color: #94A3B8 !important; border-color: rgba(148,163,184,.18) !important; }
    :root[data-tema="oscuro"] .badge-blue   { background: rgba(59,130,246,.14)  !important; color: #60A5FA !important; border-color: rgba(59,130,246,.22)  !important; }
    :root[data-tema="oscuro"] .badge-prospecto { background: rgba(245,158,11,.14) !important; color: #FBBF24 !important; }

    /* ── Página botones de paginación ── */
    :root[data-tema="oscuro"] .page-btn { background: var(--bg-surface) !important; border-color: var(--brd) !important; color: var(--tx) !important; }
    :root[data-tema="oscuro"] .page-btn:hover, :root[data-tema="oscuro"] .page-btn.active { background: var(--ac) !important; border-color: var(--ac) !important; color: #fff !important; }

    /* ── Mobile card rows (data-table tr como tarjeta en móvil) ── */
    :root[data-tema="oscuro"] .data-table tr { background: var(--bg-surface) !important; border-color: var(--brd) !important; }
    :root[data-tema="oscuro"] .data-table td:last-child { border-color: var(--brd) !important; }

    /* ── Norma tags ── */
    :root[data-tema="oscuro"] .norma-tag { background: rgba(59,130,246,.13) !important; color: #93C5FD !important; border-color: rgba(59,130,246,.22) !important; }

    /* ── Scrollbar ── */
    :root[data-tema="oscuro"] ::-webkit-scrollbar { width: 6px; height: 6px; }
    :root[data-tema="oscuro"] ::-webkit-scrollbar-track { background: var(--bg-base); }
    :root[data-tema="oscuro"] ::-webkit-scrollbar-thumb { background: var(--brd); border-radius: 6px; }
    :root[data-tema="oscuro"] ::-webkit-scrollbar-thumb:hover { background: #283D58; }
    :root[data-tema="oscuro"] * { scrollbar-width: thin; scrollbar-color: var(--brd) var(--bg-base); }

    /* ══════════════════════════════════════════════════════
       INLINE-STYLE OVERRIDES (PHP-hardcoded colors in modules)
    ══════════════════════════════════════════════════════ */

    /* White/near-white → surface */
    :root[data-tema="oscuro"] [style*="background:#fff"],
    :root[data-tema="oscuro"] [style*="background: #fff"],
    :root[data-tema="oscuro"] [style*="background:#fafbfe"],
    :root[data-tema="oscuro"] [style*="background:#f8f9fb"],
    :root[data-tema="oscuro"] [style*="background:#f9fafb"],
    :root[data-tema="oscuro"] [style*="background:#f1f5f9"],
    :root[data-tema="oscuro"] [style*="background:#f0f2f8"],
    :root[data-tema="oscuro"] [style*="background:#f8fafc"],
    :root[data-tema="oscuro"] [style*="background:#fafafa"],
    :root[data-tema="oscuro"] [style*="background:#f4f6fa"],
    :root[data-tema="oscuro"] [style*="background:#f8f9fc"],
    :root[data-tema="oscuro"] [style*="background:#f9f9f9"],
    :root[data-tema="oscuro"] [style*="background:#f2f2f2"],
    :root[data-tema="oscuro"] [style*="background:#f0f0f0"],
    :root[data-tema="oscuro"] [style*="background:#e0e0e0"],
    :root[data-tema="oscuro"] [style*="background:#dde1ef"],
    :root[data-tema="oscuro"] [style*="background:#e2e6f0"]
    { background: var(--bg-surface) !important; }

    /* Light blue/indigo/green tints → surface2 */
    :root[data-tema="oscuro"] [style*="background:#e8edf8"],
    :root[data-tema="oscuro"] [style*="background:#e8f7eb"],
    :root[data-tema="oscuro"] [style*="background:#e8f0fe"],
    :root[data-tema="oscuro"] [style*="background:#e5e7eb"],
    :root[data-tema="oscuro"] [style*="background:#eff6ff"],
    :root[data-tema="oscuro"] [style*="background:#f0f7ff"],
    :root[data-tema="oscuro"] [style*="background:#f7f9ff"],
    :root[data-tema="oscuro"] [style*="background:#f0f4ff"],
    :root[data-tema="oscuro"] [style*="background:#c7d2fe"],
    :root[data-tema="oscuro"] [style*="background:#ecfdf5"],
    :root[data-tema="oscuro"] [style*="background:#dbeafe"],
    :root[data-tema="oscuro"] [style*="background:#dcfce7"],
    :root[data-tema="oscuro"] [style*="background:#d1fae5"],
    :root[data-tema="oscuro"] [style*="background:#c6f0dc"],
    :root[data-tema="oscuro"] [style*="background:#bbf7d0"],
    :root[data-tema="oscuro"] [style*="background:#b3c8f7"],
    :root[data-tema="oscuro"] [style*="background:#eef2ff"],
    :root[data-tema="oscuro"] [style*="background:#e0f2fe"],
    :root[data-tema="oscuro"] [style*="background:#f5f3ff"]
    { background: var(--bg-surface2) !important; }

    /* Alert backgrounds — red */
    :root[data-tema="oscuro"] [style*="background:#fee2e2"],
    :root[data-tema="oscuro"] [style*="background:#fef2f2"],
    :root[data-tema="oscuro"] [style*="background:#fff5f5"],
    :root[data-tema="oscuro"] [style*="background:#fff9f9"],
    :root[data-tema="oscuro"] [style*="background:#fff1f2"],
    :root[data-tema="oscuro"] [style*="background:#fecaca"],
    :root[data-tema="oscuro"] [style*="background:#fca5a5"]
    { background: rgba(248,113,113,.10) !important; }
    /* Alert backgrounds — amber/orange */
    :root[data-tema="oscuro"] [style*="background:#fff7e6"],
    :root[data-tema="oscuro"] [style*="background:#fffbeb"],
    :root[data-tema="oscuro"] [style*="background:#fef3c7"],
    :root[data-tema="oscuro"] [style*="background:#fef9c3"],
    :root[data-tema="oscuro"] [style*="background:#fff7ed"],
    :root[data-tema="oscuro"] [style*="background:#fed7aa"],
    :root[data-tema="oscuro"] [style*="background:#fde68a"]
    { background: rgba(251,191,36,.10) !important; }
    /* Alert backgrounds — green */
    :root[data-tema="oscuro"] [style*="background:#f0fdf4"],
    :root[data-tema="oscuro"] [style*="background:#f0faf2"]
    { background: rgba(52,211,153,.08) !important; }

    /* Blue primary backgrounds → dark hover */
    :root[data-tema="oscuro"] [style*="background:#1D4ED8"],
    :root[data-tema="oscuro"] [style*="background:#1E40AF"],
    :root[data-tema="oscuro"] [style*="background:#2563EB"],
    :root[data-tema="oscuro"] [style*="background:#1d4ed8"],
    :root[data-tema="oscuro"] [style*="background:#2563eb"]
    { background: var(--bg-hover) !important; }

    /* (Eliminado) Regla "Badge solid colors → dark surface": agrisaba CUALQUIER elemento
       con background:#3B82F6/#6B7280/#10B981/#EF4444/#F59E0B inline, matando el color
       semántico de los badges/dots de estado en oscuro (rompía los estados de Propuestas).
       Los badges ahora se tokenizan con var(--s-*-bg)/var(--s-*-tx) y los botones/toast con
       var(--ac)/var(--s-warn)/var(--s-ok), que adaptan solos a ambos temas. No reintroducir. */

    /* Inline var overrides */
    :root[data-tema="oscuro"] [style*="color:var(--azul)"],
    :root[data-tema="oscuro"] [style*="color: var(--azul)"] { color: #D0D8E8 !important; }
    :root[data-tema="oscuro"] [style*="background:var(--azul-light)"],
    :root[data-tema="oscuro"] [style*="background: var(--azul-light)"] { background: var(--bg-surface2) !important; }
    :root[data-tema="oscuro"] [style*="background:var(--azul)"],
    :root[data-tema="oscuro"] [style*="background: var(--azul)"] { background: var(--bg-hover) !important; }

    /* Dark text → light */
    :root[data-tema="oscuro"] [style*="color:#1a1f36"], :root[data-tema="oscuro"] [style*="color: #1a1f36"],
    :root[data-tema="oscuro"] [style*="color:#111827"], :root[data-tema="oscuro"] [style*="color:#1f2937"],
    :root[data-tema="oscuro"] [style*="color:#374151"], :root[data-tema="oscuro"] [style*="color:#1a306a"],
    :root[data-tema="oscuro"] [style*="color:#1e3a5f"], :root[data-tema="oscuro"] [style*="color:#0f172a"],
    :root[data-tema="oscuro"] [style*="color:#1e293b"], :root[data-tema="oscuro"] [style*="color:#212529"],
    :root[data-tema="oscuro"] [style*="color:#2d3748"], :root[data-tema="oscuro"] [style*="color:#0F172A"],
    :root[data-tema="oscuro"] [style*="color:#1E293B"], :root[data-tema="oscuro"] [style*="color:#1A3A8F"],
    :root[data-tema="oscuro"] [style*="color:#1a3a8f"]
    { color: var(--tx) !important; }

    /* Blue text → neutral */
    :root[data-tema="oscuro"] [style*="color:#1D4ED8"], :root[data-tema="oscuro"] [style*="color:#1E40AF"],
    :root[data-tema="oscuro"] [style*="color:#2563EB"], :root[data-tema="oscuro"] [style*="color:#1e40af"],
    :root[data-tema="oscuro"] [style*="color:#1d4ed8"], :root[data-tema="oscuro"] [style*="color:#2563eb"],
    :root[data-tema="oscuro"] [style*="color:#1D4EFF"], :root[data-tema="oscuro"] [style*="color:#1a56db"]
    { color: #D0D8E8 !important; }
    :root[data-tema="oscuro"] [style*="color:#3B82F6"],
    :root[data-tema="oscuro"] [style*="color:#60A5FA"] { color: #7EB3F5 !important; }

    /* Secondary text */
    :root[data-tema="oscuro"] [style*="color:#6b7a99"], :root[data-tema="oscuro"] [style*="color: #6b7a99"],
    :root[data-tema="oscuro"] [style*="color:#6b7280"], :root[data-tema="oscuro"] [style*="color:#64748b"],
    :root[data-tema="oscuro"] [style*="color:#64748B"], :root[data-tema="oscuro"] [style*="color:#9ca3af"],
    :root[data-tema="oscuro"] [style*="color:#718096"], :root[data-tema="oscuro"] [style*="color:#475569"],
    :root[data-tema="oscuro"] [style*="color:#4B5563"], :root[data-tema="oscuro"] [style*="color:#94A3B8"],
    :root[data-tema="oscuro"] [style*="color:var(--gris-texto)"]
    { color: var(--tx2) !important; }

    /* Status text */
    :root[data-tema="oscuro"] [style*="color:#c0392b"], :root[data-tema="oscuro"] [style*="color:#7f1d1d"],
    :root[data-tema="oscuro"] [style*="color:#991b1b"] { color: #f87171 !important; }
    :root[data-tema="oscuro"] [style*="color:#d97706"], :root[data-tema="oscuro"] [style*="color:#92400e"]
    { color: #fbbf24 !important; }
    :root[data-tema="oscuro"] [style*="color:#065f46"], :root[data-tema="oscuro"] [style*="color:#166534"],
    :root[data-tema="oscuro"] [style*="color:#2DB140"], :root[data-tema="oscuro"] [style*="color:#15803d"],
    :root[data-tema="oscuro"] [style*="color:#0F6E56"], :root[data-tema="oscuro"] [style*="color:#0f6e56"],
    :root[data-tema="oscuro"] [style*="color:#1D9E75"], :root[data-tema="oscuro"] [style*="color:#1d9e75"]
    { color: #34D399 !important; }

    /* Borders */
    :root[data-tema="oscuro"] [style*="border-bottom:1px solid #f"],
    :root[data-tema="oscuro"] [style*="border-bottom: 1px solid #f"],
    :root[data-tema="oscuro"] [style*="border-bottom:1px solid #e"],
    :root[data-tema="oscuro"] [style*="border-bottom: 1px solid #e"],
    :root[data-tema="oscuro"] [style*="border:1px solid #e"],
    :root[data-tema="oscuro"] [style*="border: 1px solid #e"],
    :root[data-tema="oscuro"] [style*="border:1.5px solid #e2e8f0"],
    :root[data-tema="oscuro"] [style*="border-top:1px solid #e"],
    :root[data-tema="oscuro"] [style*="border-top: 1px solid #e"]
    { border-color: var(--brd) !important; }
    :root[data-tema="oscuro"] [style*="border-left:3px solid #1D4ED8"],
    :root[data-tema="oscuro"] [style*="border-left: 3px solid #1D4ED8"],
    :root[data-tema="oscuro"] [style*="border-left:4px solid #1D4ED8"],
    :root[data-tema="oscuro"] [style*="border-left:3px solid #2563EB"],
    :root[data-tema="oscuro"] [style*="border-left:4px solid var(--azul)"]
    { border-left-color: var(--ac-mid) !important; }

    /* ── Dark mode: estado-select — evitar que el override global de select borre el color inline ── */
    :root[data-tema="oscuro"] select.estado-select {
      background: revert !important;
      filter: saturate(1.4) brightness(1.5);
    }
    /* Spans de estado con colores dinámicos inline */
    :root[data-tema="oscuro"] span[style*="border-radius:100px"][style*="color:#"] {
      filter: saturate(1.3) brightness(1.4) !important;
    }

    /* ── Dark mode: botón WhatsApp ── */
    :root[data-tema="oscuro"] .btn-ic-wa {
      background: rgba(37,211,102,0.15) !important;
      color: #25D366 !important;
    }
    :root[data-tema="oscuro"] .btn-ic-wa:hover {
      background: rgba(37,211,102,0.28) !important;
    }
    :root[data-tema="oscuro"] .btn-ic-wa-off {
      background: rgba(148,163,184,0.12) !important;
      color: #64748b !important;
    }

    /* ══════════════════════════════════════════════════════════════
       DARK MODE — REVISIÓN GLOBAL v2
       Cubre todos los componentes sin override previo:
       announcement bar · dashboard charts · renovaciones ·
       cumpleaños · cotizaciones banners · inline-style gaps ·
       hover states · dropdowns · tooltips · formularios extras
    ══════════════════════════════════════════════════════════════ */

    /* ── Announcement bar — colores sólidos para correcta visibilidad en oscuro ── */
    :root[data-tema="oscuro"] #ggi-ann-bar[data-tipo="info"]        { --ann-bg:#1E3A5F; --ann-brd:#2D5A9E; --ann-tx:#93C5FD; --ann-icon:#60A5FA; }
    :root[data-tema="oscuro"] #ggi-ann-bar[data-tipo="warning"]     { --ann-bg:#3D2C07; --ann-brd:#6B4A10; --ann-tx:#FDE68A; --ann-icon:#FBBF24; }
    :root[data-tema="oscuro"] #ggi-ann-bar[data-tipo="maintenance"] { --ann-bg:#2D1B5E; --ann-brd:#5B35A0; --ann-tx:#C4B5FD; --ann-icon:#A78BFA; }
    :root[data-tema="oscuro"] #ggi-ann-bar[data-tipo="success"]     { --ann-bg:#0D3A28; --ann-brd:#1A5E40; --ann-tx:#6EE7B7; --ann-icon:#34D399; }
    :root[data-tema="oscuro"] .ggi-ann-close:hover                  { background:rgba(255,255,255,.08) !important; }

    /* ── Buscador global dropdown ── */
    :root[data-tema="oscuro"] .gs-dropdown     { box-shadow:0 8px 32px rgba(0,0,0,.5) !important; }
    :root[data-tema="oscuro"] .gs-item:hover   { background: var(--bg-hover) !important; }
    :root[data-tema="oscuro"] .gs-titulo       { color: var(--tx) !important; }
    :root[data-tema="oscuro"] .gs-sub          { color: var(--tx2) !important; }
    :root[data-tema="oscuro"] .gs-tipo         { background: var(--bg-surface2) !important; color: var(--tx2) !important; }

    /* ── User popup ── */
    :root[data-tema="oscuro"] .user-popup      { box-shadow:0 8px 32px rgba(0,0,0,.5) !important; }
    :root[data-tema="oscuro"] .user-popup-item:hover { background: var(--bg-hover) !important; color: var(--ac-mid) !important; }
    :root[data-tema="oscuro"] .user-popup-item.danger:hover { background: rgba(239,68,68,.12) !important; color: #f87171 !important; }

    /* ── Dashboard — Ingresos por mes (chart bars) ── */
    :root[data-tema="oscuro"] .chart-bar-wrap  { background: var(--bg-surface2) !important; }
    :root[data-tema="oscuro"] .chart-bar       { background: var(--ac) !important; }
    :root[data-tema="oscuro"] .chart-bar.current { background: #34D399 !important; }
    :root[data-tema="oscuro"] .chart-month     { color: var(--tx2) !important; }
    :root[data-tema="oscuro"] .chart-month.current { color: #34D399 !important; }
    :root[data-tema="oscuro"] .chart-val       { color: var(--tx) !important; }
    :root[data-tema="oscuro"] .chart-qty       { color: var(--tx2) !important; }
    :root[data-tema="oscuro"] .chart-row       { border-color: rgba(255,255,255,.045) !important; }
    :root[data-tema="oscuro"] .chart-summary   { border-color: var(--brd) !important; }
    :root[data-tema="oscuro"] .chart-kpi-val   { color: var(--tx) !important; }
    :root[data-tema="oscuro"] .chart-kpi-lbl   { color: var(--tx2) !important; }
    :root[data-tema="oscuro"] .delta-up        { color: #34D399 !important; }
    :root[data-tema="oscuro"] .delta-down      { color: #f87171 !important; }

    /* ── Dashboard — Renovaciones próximas ── */
    :root[data-tema="oscuro"] .renov-summary   { border-color: var(--brd) !important; }
    :root[data-tema="oscuro"] .renov-pill      { border-color: var(--brd) !important; }
    :root[data-tema="oscuro"] .renov-pill-num  { color: var(--tx) !important; }
    :root[data-tema="oscuro"] .renov-pill-lbl  { color: var(--tx2) !important; }
    :root[data-tema="oscuro"] .renov-row       { border-color: rgba(255,255,255,.045) !important; }
    :root[data-tema="oscuro"] .renov-empresa   { color: var(--tx) !important; }
    :root[data-tema="oscuro"] .renov-norma     { color: var(--tx2) !important; }
    :root[data-tema="oscuro"] .renov-fecha     { color: var(--tx2) !important; }
    :root[data-tema="oscuro"] .renov-dias.urgent  { background:rgba(239,68,68,.15)  !important; color:#f87171 !important; }
    :root[data-tema="oscuro"] .renov-dias.warning { background:rgba(245,158,11,.14) !important; color:#fbbf24 !important; }
    :root[data-tema="oscuro"] .renov-dias.safe    { background:rgba(52,211,153,.13) !important; color:#34D399 !important; }

    /* ── Dashboard — Cumpleaños del equipo ── */
    :root[data-tema="oscuro"] .cumple-banner {
      background: linear-gradient(135deg,rgba(180,83,9,.16) 0%,rgba(120,53,15,.10) 100%) !important;
      border-color: rgba(245,158,11,.20) !important;
      border-left-color: rgba(245,158,11,.50) !important;
    }
    :root[data-tema="oscuro"] .cumple-banner-title { color: #fbbf24 !important; }
    :root[data-tema="oscuro"] .cumple-banner-names { color: #fde68a !important; }
    :root[data-tema="oscuro"] .cumple-row         { border-color: rgba(255,255,255,.045) !important; }
    :root[data-tema="oscuro"] .cumple-nombre       { color: var(--tx)  !important; }
    :root[data-tema="oscuro"] .cumple-rol          { color: var(--tx2) !important; }
    :root[data-tema="oscuro"] .cumple-avatar       { border-color: var(--brd) !important; }
    :root[data-tema="oscuro"] .cumple-avatar-init  { background: var(--ac) !important; }
    :root[data-tema="oscuro"] .cumple-hoy   { background:rgba(245,158,11,.15)  !important; color:#fbbf24 !important; border-color:rgba(245,158,11,.25) !important; }
    :root[data-tema="oscuro"] .cumple-pronto { background:rgba(59,130,246,.13) !important; color:#60A5FA !important; border-color:rgba(59,130,246,.22) !important; }

    /* ── Dashboard ejecutivo — Actividad comercial (ac-stat) ── */
    :root[data-tema="oscuro"] .ac-stat { background: var(--bg-surface2) !important; border-color: var(--brd) !important; }

    /* ── Cotizaciones — banner de éxito al crear propuesta ── */
    :root[data-tema="oscuro"] .cot-success-banner {
      background: rgba(52,211,153,.09) !important;
      border-color: rgba(52,211,153,.22) !important;
      color: #34D399 !important;
    }
    :root[data-tema="oscuro"] .cot-success-banner strong { color: #34D399 !important; }
    :root[data-tema="oscuro"] .cot-row-new {
      background: rgba(52,211,153,.07) !important;
    }
    @keyframes cot-highlight-in-dark {
      0%   { background-color: rgba(52,211,153,.18); }
      100% { background-color: transparent; }
    }
    :root[data-tema="oscuro"] .cot-row-new {
      animation: cot-highlight-in-dark 3s ease forwards !important;
    }

    /* ── Formularios — estados adicionales no cubiertos ── */
    :root[data-tema="oscuro"] .field input,
    :root[data-tema="oscuro"] .field textarea,
    :root[data-tema="oscuro"] .field select
    { background-color: var(--bg-input) !important; border-color: var(--brd) !important; color: var(--tx) !important; }
    :root[data-tema="oscuro"] .field label      { color: var(--tx2) !important; }
    :root[data-tema="oscuro"] .form-hint        { color: var(--tx2) !important; }
    :root[data-tema="oscuro"] .form-error       { color: #f87171  !important; }
    :root[data-tema="oscuro"] .input-group-addon { background: var(--bg-surface2) !important; border-color: var(--brd) !important; color: var(--tx2) !important; }

    /* ── Alertas del sistema (genéricas) ── */
    :root[data-tema="oscuro"] .alert-info     { background: rgba(59,130,246,.10)  !important; border-color: rgba(59,130,246,.22) !important; color: #93C5FD !important; }
    :root[data-tema="oscuro"] .alert-warn,
    :root[data-tema="oscuro"] .alert-warning  { background: rgba(245,158,11,.10)  !important; border-color: rgba(245,158,11,.22) !important; color: #fbbf24 !important; }
    :root[data-tema="oscuro"] .alert-success  { background: rgba(52,211,153,.10)  !important; border-color: rgba(52,211,153,.22) !important; color: #34D399 !important; }

    /* ── Tooltips (el [data-tip]::after ya usa fondo oscuro — refinamos) ── */
    :root[data-tema="oscuro"] [data-tip]::after {
      background: rgba(15,20,30,.95) !important;
      box-shadow: 0 4px 14px rgba(0,0,0,.5) !important;
    }

    /* ── background-color: variant (alternative to background:) ── */
    :root[data-tema="oscuro"] [style*="background-color:#fff"],
    :root[data-tema="oscuro"] [style*="background-color: #fff"],
    :root[data-tema="oscuro"] [style*="background-color:#ffffff"],
    :root[data-tema="oscuro"] [style*="background-color:#FFFFFF"],
    :root[data-tema="oscuro"] [style*="background:white"],
    :root[data-tema="oscuro"] [style*="background: white"],
    :root[data-tema="oscuro"] [style*="background:#FFFFFF"]
    { background: var(--bg-surface) !important; }
    :root[data-tema="oscuro"] [style*="background-color:#e8edf8"],
    :root[data-tema="oscuro"] [style*="background-color:#e8f7eb"],
    :root[data-tema="oscuro"] [style*="background-color:#eff6ff"],
    :root[data-tema="oscuro"] [style*="background-color:#d1fae5"],
    :root[data-tema="oscuro"] [style*="background-color:#dbeafe"],
    :root[data-tema="oscuro"] [style*="background-color:#f0f7ff"],
    :root[data-tema="oscuro"] [style*="background-color:#f8f9fc"],
    :root[data-tema="oscuro"] [style*="background-color:#fafbfe"],
    :root[data-tema="oscuro"] [style*="background-color:#f1f5f9"],
    :root[data-tema="oscuro"] [style*="background-color:#f8fafc"]
    { background: var(--bg-surface2) !important; }
    :root[data-tema="oscuro"] [style*="background-color:#fef9c3"],
    :root[data-tema="oscuro"] [style*="background-color:#fff7ed"],
    :root[data-tema="oscuro"] [style*="background-color:#fffbeb"],
    :root[data-tema="oscuro"] [style*="background-color:#fef3c7"]
    { background: rgba(251,191,36,.10) !important; }
    :root[data-tema="oscuro"] [style*="background-color:#fee2e2"],
    :root[data-tema="oscuro"] [style*="background-color:#fff5f5"]
    { background: rgba(248,113,113,.10) !important; }

    /* Textos amarillos/dorados inline */
    :root[data-tema="oscuro"] [style*="color:#a16207"],
    :root[data-tema="oscuro"] [style*="color: #a16207"]
    { color: #fbbf24 !important; }

    /* Textos naranja inline */
    :root[data-tema="oscuro"] [style*="color:#f97316"],
    :root[data-tema="oscuro"] [style*="color: #f97316"]
    { color: #fb923c !important; }

    /* Textos amber inline */
    :root[data-tema="oscuro"] [style*="color:#b45309"],
    :root[data-tema="oscuro"] [style*="color: #b45309"]
    { color: #fbbf24 !important; }

    /* Textos verdes pastel (cert badges) */
    :root[data-tema="oscuro"] [style*="color:#229133"],
    :root[data-tema="oscuro"] [style*="color: #229133"]
    { color: #34D399 !important; }

    /* Border colors inline adicionales */
    :root[data-tema="oscuro"] [style*="border-color:#e2e8f0"],
    :root[data-tema="oscuro"] [style*="border-color: #e2e8f0"]
    { border-color: var(--brd) !important; }

    /* ── stat-card top stripe: mantener var(--card-color) propio de cada card ── */
    /* (ya usa var() inline — no necesita override; las que usan #hex sí) */
    :root[data-tema="oscuro"] .stat-value { color: var(--tx) !important; }

    /* ── Panel links y sub-labels adicionales ── */
    :root[data-tema="oscuro"] .empty-state       { color: var(--tx2) !important; }
    :root[data-tema="oscuro"] .empty-state svg   { color: var(--tx3) !important; }
    :root[data-tema="oscuro"] .mini-table tr:hover td { background: rgba(59,130,246,.06) !important; }

    /* ── Pagination active button extra ── */
    :root[data-tema="oscuro"] .page-btn.active   { background: var(--ac) !important; border-color: var(--ac) !important; color:#fff !important; }

    /* ── Ranking asesores (dashboard) ── */
    :root[data-tema="oscuro"] .av-row            { color: var(--tx) !important; }

    /* ── Loading spinner overlay ── */
    :root[data-tema="oscuro"] #ggi-spin { background: var(--bg-surface) !important; color: var(--tx2) !important; }

    /* ── Hover row color unificado para mini-table y data-table ── */
    :root[data-tema="oscuro"] .mini-table tr:hover td,
    :root[data-tema="oscuro"] .data-table tr:hover td { background: rgba(59,130,246,.055) !important; }

    /* ── Código inline (monospace) ── */
    :root[data-tema="oscuro"] code { background: rgba(59,130,246,.10) !important; color: #93C5FD !important; border-color: rgba(59,130,246,.18) !important; }

    /* ── Links de acción en tablas ── */
    :root[data-tema="oscuro"] a[style*="color:var(--gris-texto)"],
    :root[data-tema="oscuro"] a[style*="color: var(--gris-texto)"] { color: var(--tx2) !important; }

    /* ── txt-ellipsis hover en dark mode ── */
    :root[data-tema="oscuro"] .txt-ellipsis:hover         { color: var(--ac-mid) !important; }
    :root[data-tema="oscuro"] .txt-ellipsis[data-overflows]:hover { text-decoration-color: rgba(96,165,250,.45) !important; }
    :root[data-tema="oscuro"] .txt-ellipsis.ggi-exp       { text-decoration-color: rgba(96,165,250,.35) !important; }

    /* ── Claro forzado ── */
    :root[data-tema="claro"] body { background: var(--bg-base) !important; color: var(--tx) !important; }

    /* ── Transición suave al cambiar tema ── */
    html.tema-transitioning,
    html.tema-transitioning body,
    html.tema-transitioning .header,
    html.tema-transitioning .sidebar,
    html.tema-transitioning .main,
    html.tema-transitioning .card,
    html.tema-transitioning .user-popup,
    html.tema-transitioning .gs-dropdown,
    html.tema-transitioning input,
    html.tema-transitioning select,
    html.tema-transitioning textarea,
    html.tema-transitioning table,
    html.tema-transitioning th,
    html.tema-transitioning td,
    html.tema-transitioning .notif-btn,
    html.tema-transitioning .btn-outline,
    html.tema-transitioning .btn-toggle-desktop,
    html.tema-transitioning .btn-tema {
      transition: background-color 0.22s ease, border-color 0.22s ease, color 0.22s ease, box-shadow 0.22s ease !important;
    }

    /* ── Botón de tema en el header ── */
    .btn-tema {
      width: 36px; height: 36px; border-radius: 8px;
      background: var(--bg-base); border: 1px solid var(--brd);
      cursor: pointer; display: flex; align-items: center; justify-content: center;
      color: var(--tx2); flex-shrink: 0;
      transition: background 0.15s, color 0.15s, border-color 0.15s;
      position: relative;
    }
    .btn-tema:hover { background: var(--ac-soft); color: var(--ac); border-color: var(--ac); }
    .btn-tema::after { display: none; }

    /* ── Mini selector de tema ── */
    .tema-pill-row {
      display: flex; align-items: center; justify-content: center;
      padding: 8px 14px 6px; border-bottom: 1px solid var(--brd2);
    }
    .tema-pill-label { font-size: 11px; font-weight: 600; color: var(--tx2); text-transform: uppercase; letter-spacing: 0.5px; }
    .tema-pill { display: flex; gap: 2px; background: var(--bg-base); border: 1px solid var(--brd); border-radius: 8px; padding: 2px; }
    .tema-mini-btn {
      width: 28px; height: 28px; border-radius: 6px; border: none;
      background: none; cursor: pointer; color: var(--tx2);
      display: flex; align-items: center; justify-content: center;
      transition: background 0.15s, color 0.15s; position: relative;
    }
    .tema-mini-btn:hover { background: var(--ac-soft); color: var(--ac); }
    .tema-mini-btn.activo { background: var(--bg-surface); color: var(--ac); box-shadow: 0 1px 4px rgba(29,78,216,0.15); }
    .tema-mini-btn::after {
      content: attr(title);
      position: absolute; bottom: calc(100% + 4px); left: 50%; transform: translateX(-50%);
      background: #1a1f36; color: #fff;
      font-size: 10px; font-weight: 500; font-family: 'Inter', sans-serif;
      padding: 3px 7px; border-radius: 4px; white-space: nowrap;
      opacity: 0; pointer-events: none; transition: opacity 0.15s;
    }
    .tema-mini-btn:hover::after { opacity: 1; }

    /* ── Avatar chips ── */
    .av-chip {
      display: inline-flex; align-items: center; justify-content: center;
      width: 34px; height: 34px; min-width: 34px; border-radius: 50%;
      font-size: 12px; font-weight: 700; color: #fff; letter-spacing: .4px;
      flex-shrink: 0; user-select: none; cursor: default;
    }
    .av-row { display: flex; align-items: center; gap: 10px; }
    .av-name { display: flex; flex-direction: column; gap: 2px; min-width: 0; }

  </style>
  <!-- ggiReady: disponible antes de los scripts de módulo.
       layout_end.php lo redefine de forma idéntica; la doble definición es inofensiva. -->
  <script>
  window.ggiReady = function(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  };

  // ESC para cerrar modales
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
      // Cierra GGIConfirm
      if (window.GGIConfirm) {
        const dialog = document.getElementById('confirm-dialog');
        if (dialog && dialog.classList.contains('open')) {
          GGIConfirm.close();
          return;
        }
      }
      // Cierra ggi-modal-overlay
      const openModal = document.querySelector('.ggi-modal-overlay.open');
      if (openModal) {
        openModal.classList.remove('open');
      }
    }
  });
  </script>
  <script src="<?= APP_URL ?>/modules/assets/js/search-select.js?v=1"></script>
  <script>
  // Tooltip temprano: aplica data-tip SOLO a elementos realmente truncados.
  // ggiMarkOverflowing (layout_end) se encarga de las pasadas posteriores.
  (function applyTip() {
    function scan() {
      document.querySelectorAll('.txt-ellipsis[title], [title].trunc-name').forEach(function(el) {
        if (!el.title || el.dataset.tip) return;
        if (el.scrollWidth > el.clientWidth + 2) el.setAttribute('data-tip', el.title);
      });
    }
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', function() { scan(); setTimeout(scan, 80); });
    } else {
      scan(); setTimeout(scan, 80);
    }
  })();
  </script>
  <!-- Design System CSS — Componentes core reutilizables -->
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/design-system.css?v=<?= @filemtime(__DIR__ . '/../assets/css/design-system.css') ?>">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/flatpickr/flatpickr.min.css">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/flatpickr/ggi-theme.css?v=<?= @filemtime(__DIR__ . '/../assets/vendor/flatpickr/ggi-theme.css') ?>">
  <!-- CSS global de componentes (toasts, modales, pager, bulk bar…): extraído de
       layout_end.php a un archivo cacheable (auditoría 2026-07). -->
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/app.css?v=<?= @filemtime(__DIR__ . '/../assets/css/app.css') ?>">
</head>
<body>
<?php if (empty($_ggi_embed)): ?>
<a href="#ggi-main" class="ggi-skip-link">Saltar al contenido principal</a>
<?php include __DIR__ . '/pwa-splash.php'; ?>
<?php endif; ?>
<?php if (!empty($_ggi_embed)): ?>
<!-- EMBED MODE: sin sidebar ni navegación -->
<style>
/* Dentro de un preview (modal iframe) no se navega a otros módulos: el
   breadcrumb y cualquier enlace a listados serían una "fuga" de salida.
   Se ocultan las migas; queda el botón ← Volver del modal para cerrar. */
.ggi-bc { display: none !important; }
/* Enlaces a los listados de módulos (índices) quedan inertes dentro del embed */
.ggi-embed-wrap a[href*="/index.php"]:not(.btn):not([data-ggi-preview]) {
  pointer-events: none; color: inherit; text-decoration: none; cursor: default;
}
/* ── Barra de acciones sticky (solo en embed mobile — dentro del iframe) ── */
@media (max-width: 767px) {
  .ggi-embed-wrap {
    padding-bottom: 80px; /* espacio para la barra sticky inferior */
  }
  .form-actions {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    z-index: 100;
    background: var(--bg-surface, #fff);
    border-top: 1px solid var(--gris-borde, #E2E8F0);
    /* safe-bottom: la barra fija no debe quedar bajo el gesture bar / home indicator */
    padding: 12px 16px calc(12px + var(--safe-bottom, 0px));
    margin: 0;
    box-shadow: 0 -4px 20px rgba(0,0,0,.06);
    border-radius: 0;
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
  }
  .form-actions .btn {
    flex: 1;
    min-width: 0;
    justify-content: center;
  }
}
</style>
<div class="ggi-embed-wrap">
<script>
/* ── Embed mode: comunicación con la ventana padre ──────────────────────── */
(function() {
  /* Notificar al padre que queremos cerrar (usado por botón ← Volver) */
  window.ggiEmbedClose = function() {
    try { window.parent.postMessage({ ggi: 'close' }, '*'); } catch(e) {}
  };

  /* Marcar formulario como "sucio" (con cambios sin guardar) */
  window.ggiEmbedDirty = function(dirty) {
    try { window.parent.postMessage({ ggi: 'dirty', dirty: !!dirty }, '*'); } catch(e) {}
  };

  /* Auto-detectar cambios en formularios y notificar al padre */
  document.addEventListener('input',  function(e) {
    var el = e.target;
    if (el && el.form && !el.closest('[data-no-dirty]')) {
      window.ggiEmbedDirty(true);
    }
  }, { passive: true });
  document.addEventListener('change', function(e) {
    var el = e.target;
    if (el && (el.tagName === 'SELECT' || el.type === 'checkbox' || el.type === 'radio')) {
      if (!el.closest('[data-no-dirty]')) window.ggiEmbedDirty(true);
    }
  }, { passive: true });

  /* Al enviar el formulario con éxito, limpiar el flag de dirty */
  window.addEventListener('message', function(ev) {
    if (ev.data && ev.data.ggi === 'clearDirty') window.ggiEmbedDirty(false);
  });

  /* ── Safe-area dentro del iframe (notch / Dynamic Island) ──────────────────
     Dentro de un iframe env(safe-area-inset-top) vale 0, así que un modal/header
     abierto dentro del embed se iría bajo la isla dinámica. Pedimos el inset REAL
     al documento padre (que sí lo conoce) y lo fijamos como --safe-top; además lo
     retransmitimos a iframes hijos (modales anidados). No-op en escritorio (0). */
  var _ggiSafeTop = 0, _ggiSafeBottom = 0;
  window.addEventListener('message', function(ev) {
    if (ev.data && ev.data.ggi === 'safeTop' && typeof ev.data.value === 'number') {
      _ggiSafeTop    = ev.data.value;
      _ggiSafeBottom = (typeof ev.data.bottom === 'number') ? ev.data.bottom : _ggiSafeBottom;
      document.documentElement.style.setProperty('--safe-top', _ggiSafeTop + 'px');
      document.documentElement.style.setProperty('--safe-bottom', _ggiSafeBottom + 'px');
      // Reenviar a iframes hijos ya presentes (modales anidados)
      document.querySelectorAll('iframe').forEach(function(f) {
        try { f.contentWindow.postMessage({ ggi: 'safeTop', value: _ggiSafeTop, top: _ggiSafeTop, bottom: _ggiSafeBottom }, '*'); } catch (e) {}
      });
    }
    // Un iframe hijo pide el inset → le paso el que tengo
    if (ev.data && ev.data.ggi === 'getSafeTop' && ev.source) {
      try { ev.source.postMessage({ ggi: 'safeTop', value: _ggiSafeTop, top: _ggiSafeTop, bottom: _ggiSafeBottom }, '*'); } catch (e) {}
    }
  });
  try { window.parent.postMessage({ ggi: 'getSafeTop' }, '*'); } catch (e) {}
})();
</script>
<?php else: ?>
<!-- ── Safe-area: el documento top-level mide el inset real del dispositivo y lo
     sirve a los iframes embed que lo pidan (dentro de un iframe env() vale 0). ── -->
<script>
(function () {
  function medir(prop) {
    var p = document.createElement('div');
    p.style.cssText = 'position:fixed;top:0;left:0;width:0;height:0;padding-top:env(' + prop + ',0px);visibility:hidden;pointer-events:none;';
    document.documentElement.appendChild(p);
    var v = parseFloat(getComputedStyle(p).paddingTop) || 0;
    p.remove();
    return v;
  }
  function medirInsets() { return { top: medir('safe-area-inset-top'), bottom: medir('safe-area-inset-bottom') }; }
  var insets = medirInsets();
  window.addEventListener('resize', function () { insets = medirInsets(); }, { passive: true });
  window.addEventListener('message', function (ev) {
    if (ev.data && ev.data.ggi === 'getSafeTop' && ev.source) {
      // 'value' (compat) = top; 'top'/'bottom' explícitos
      try { ev.source.postMessage({ ggi: 'safeTop', value: insets.top, top: insets.top, bottom: insets.bottom }, '*'); } catch (e) {}
    }
  });
})();
</script>
<!-- Barra de progreso de navegación -->
<div id="nav-progress" style="position:fixed;top:0;left:0;right:0;height:3px;z-index:99999;pointer-events:none;opacity:0;transition:opacity 0.2s;">
  <div id="nav-progress-bar" style="height:100%;width:0%;background:linear-gradient(90deg,var(--azul),#4f8ef7);border-radius:0 2px 2px 0;transition:width 0.4s ease;box-shadow:0 0 8px rgba(29,78,216,0.5);"></div>
</div>

<!-- ── Barra de anuncios global ─────────────────────────────────────────── -->
<?php if ($_ann_show): ?>
<div id="ggi-ann-bar" data-tipo="<?= htmlspecialchars($_ann_tipo) ?>" role="alert" aria-live="polite">
  <div class="ggi-ann-inner">
    <span class="ggi-ann-icon" aria-hidden="true">
      <?php
        $ann_icons = [
          'info'        => '<svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
          'warning'     => '<svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
          'maintenance' => '<svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93l-1.41 1.41M4.93 4.93l1.41 1.41M4.93 19.07l1.41-1.41M19.07 19.07l-1.41-1.41M12 2v2M12 20v2M2 12h2M20 12h2"/></svg>',
          'success'     => '<svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
        ];
        echo $ann_icons[$_ann_tipo] ?? $ann_icons['info'];
      ?>
    </span>
    <span class="ggi-ann-text"><?= htmlspecialchars($_ann_texto) ?></span>
    <button class="ggi-ann-close" onclick="ggiAnnClose()" title="Cerrar anuncio" aria-label="Cerrar">
      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </button>
  </div>
</div>
<style>
  /* Paleta por tipo */
  #ggi-ann-bar[data-tipo="info"]        { --ann-bg:#EFF6FF; --ann-brd:#BFDBFE; --ann-tx:#1E40AF; --ann-icon:#2563EB; }
  #ggi-ann-bar[data-tipo="warning"]     { --ann-bg:#FFFBEB; --ann-brd:#FDE68A; --ann-tx:#92400E; --ann-icon:#D97706; }
  #ggi-ann-bar[data-tipo="maintenance"] { --ann-bg:#F5F3FF; --ann-brd:#DDD6FE; --ann-tx:#4C1D95; --ann-icon:#7C3AED; }
  #ggi-ann-bar[data-tipo="success"]     { --ann-bg:#ECFDF5; --ann-brd:#A7F3D0; --ann-tx:#065F46; --ann-icon:#059669; }

  #ggi-ann-bar {
    position: fixed; top: 0; left: 0; right: 0; z-index: 200;
    background: var(--ann-bg);
    border-bottom: 1px solid var(--ann-brd);
    height: calc(var(--ggi-ann-height, 38px) + var(--safe-top));
    padding-top: var(--safe-top); /* el notch: la barra extiende su fondo y baja el texto */
    display: flex; align-items: center;
    /* La animación solo corre al montar — animation-fill-mode:both evita flash */
    animation: annSlideDown 0.25s ease both;
    animation-iteration-count: 1;
  }
  /* Una vez terminada la animación, bloquear re-ejecución en reflows SPA */
  #ggi-ann-bar.ann-settled {
    animation: none !important;
  }
  @keyframes annSlideDown {
    from { transform: translateY(-100%); opacity: 0; }
    to   { transform: translateY(0);     opacity: 1; }
  }
  #ggi-ann-bar.hiding {
    animation: annSlideUp 0.2s ease forwards !important;
  }
  @keyframes annSlideUp {
    to { transform: translateY(-100%); opacity: 0; }
  }
  .ggi-ann-inner {
    display: flex; align-items: center; gap: 8px;
    width: 100%; padding: 0 14px 0 16px;
    max-width: 100%;
  }
  .ggi-ann-icon { color: var(--ann-icon); flex-shrink: 0; display: flex; align-items: center; }
  .ggi-ann-text {
    flex: 1; font-size: 12.5px; font-weight: 500;
    color: var(--ann-tx); line-height: 1.4;
    font-family: 'Inter', 'Inter', sans-serif;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  }
  .ggi-ann-close {
    flex-shrink: 0; display: flex; align-items: center; justify-content: center;
    width: 24px; height: 24px; border-radius: 6px; border: none;
    background: transparent; cursor: pointer;
    color: var(--ann-tx); opacity: 0.6;
    transition: background 0.15s, opacity 0.15s;
  }
  .ggi-ann-close:hover { background: rgba(0,0,0,0.08); opacity: 1; }

  @media (max-width: 600px) {
    .ggi-ann-text { font-size: 11.5px; white-space: normal; }
    #ggi-ann-bar  { height: auto; min-height: 38px; padding: 6px 0; }
  }
</style>
<script>
(function() {
  // Clave única para este anuncio (texto + tipo + versión)
  // Si el admin cambia texto, tipo o versión → nueva clave → se muestra de nuevo
  var ANN_HASH  = '<?= $_ann_hash ?>';
  var KEY_LOCAL = 'ggi_ann_v2_' + ANN_HASH; // localStorage (misma sesión browser)
  var DISMISS_URL = '<?= APP_URL ?>/modules/ajax_ann_dismiss.php';

  // ── Verificación rápida vía localStorage (sin esperar DB) ──
  // Útil para el caso en que la DB ya registró el dismiss pero la misma pestaña
  // todavía tiene el elemento renderizado (navegación SPA / back button).
  try {
    if (localStorage.getItem(KEY_LOCAL)) {
      var _b = document.getElementById('ggi-ann-bar');
      if (_b) _b.style.display = 'none';
      return;
    }
  } catch(e) {}

  function applyAnnHeight() {
    var bar = document.getElementById('ggi-ann-bar');
    if (!bar || bar.style.display === 'none') return;
    var h = bar.offsetHeight;
    document.documentElement.style.setProperty('--ann-h', h + 'px');
  }

  /** Envía el dismiss al servidor con reintentos silenciosos */
  function persistDismiss(hash, attempt) {
    attempt = attempt || 1;
    if (attempt > 3) return;
    // ggiPost vive en layout_end.php; esta función solo corre al hacer clic,
    // cuando ya está definida. El guard evita cualquier orden inesperado.
    if (typeof ggiPost !== 'function') {
      setTimeout(function() { persistDismiss(hash, attempt + 1); }, attempt * 2000);
      return;
    }
    ggiPost(DISMISS_URL, { ann_hash: hash }, { silent: true }).then(function(j) {
      if (j.ok) return;
      // Reintento con backoff: 2s, 4s
      setTimeout(function() { persistDismiss(hash, attempt + 1); }, attempt * 2000);
    });
  }

  window.ggiAnnClose = function() {
    var bar = document.getElementById('ggi-ann-bar');
    if (!bar) return;
    bar.classList.add('hiding');

    // 1. localStorage: efecto inmediato en esta sesión de browser
    try { localStorage.setItem(KEY_LOCAL, '1'); } catch(e) {}

    // 2. DB: persistir para futuras sesiones y otros dispositivos
    persistDismiss(ANN_HASH);

    setTimeout(function() {
      bar.style.display = 'none';
      document.documentElement.style.setProperty('--ann-h', '0px');
    }, 200);
  };

  // Aplicar altura después de render
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', applyAnnHeight);
  } else {
    applyAnnHeight();
  }
  // Re-calcular en resize (texto puede hacer wrap en móvil)
  window.addEventListener('resize', applyAnnHeight);

  // Marcar como "settled" al terminar la animación de entrada
  var _bar = document.getElementById('ggi-ann-bar');
  if (_bar) {
    _bar.addEventListener('animationend', function _onAnnEnd() {
      _bar.classList.add('ann-settled');
      _bar.removeEventListener('animationend', _onAnnEnd);
    });
  }
})();
</script>
<?php endif; ?>

<!-- Sidebar -->
<aside class="sidebar">
  <nav class="sidebar-nav" aria-label="Navegación principal">
  <div class="sidebar-logo">
    <div class="sidebar-logo-icon">
      <img src="<?= APP_URL ?>/assets/logo-ggi.png" alt="GGI" style="width:100%;height:100%;object-fit:contain;padding:2px;filter:brightness(0) invert(1)">
    </div>
    <div class="sidebar-logo-text">
      <strong>Group Global ISO</strong>
      <span>Sistema v1.0</span>
    </div>
  </div>

  <!-- ── Dashboard ── -->
  <div class="sidebar-section">
    <div class="section-items">
      <a href="<?= APP_URL ?>/modules/dashboard/index.php" data-label="Dashboard" class="nav-item <?= ($active_menu??'')==='dashboard' ? 'active':'' ?>">
        <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
        Dashboard
      </a>
      <?php if ($_can_inbox): ?>
      <a href="<?= APP_URL ?>/modules/inbox/index.php" data-label="Inbox" class="nav-item <?= ($active_menu??'')==='inbox' ? 'active':'' ?>">
        <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.42-4.03 8-9 8a9.9 9.9 0 01-4.29-.96L3 20l1.4-3.7A7.6 7.6 0 013 12c0-4.42 4.03-8 9-8s9 3.58 9 8z"/></svg>
        Inbox
      </a>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── Comercial ── -->
  <?php if ($_can_clientes || $_can_cotizaciones): ?>
  <div class="sidebar-section">
    <div class="sidebar-section-label">Comercial</div>
    <div class="section-items">
      <?php if ($_can_clientes): ?>
      <a href="<?= APP_URL ?>/modules/clientes/index.php" data-label="Empresas" class="nav-item <?= ($active_menu??'')==='clientes' ? 'active':'' ?>">
        <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        Empresas
      </a>
      <?php endif; ?>
      <?php if ($_can_cotizaciones): ?>
      <a href="<?= APP_URL ?>/modules/cotizaciones/index.php" data-label="Propuestas" class="nav-item <?= ($active_menu??'')==='propuestas' ? 'active':'' ?>">
        <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
        Propuestas
        <span id="nav-badge-cot" class="nav-badge amber"
          style="<?= ($_cot_vencer_badge <= 0 || ($active_menu??'') === 'propuestas') ? 'display:none' : '' ?>">
          <?= $_cot_vencer_badge > 9 ? '9+' : $_cot_vencer_badge ?>
        </span>
      </a>
      <a href="<?= APP_URL ?>/modules/llamadas/index.php" data-label="Calendario de llamadas" class="nav-item <?= ($active_menu??'')==='llamadas' ? 'active':'' ?>">
        <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        Calendario
      </a>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── Operaciones ── -->
  <?php if ($_can_envios || $_can_certificados || $_can_auditorias): ?>
  <div class="sidebar-section">
    <div class="sidebar-section-label">Operaciones</div>
    <div class="section-items">
      <?php if ($_can_envios): ?>
      <a href="<?= APP_URL ?>/modules/envios/index.php" data-label="Envíos" class="nav-item <?= ($active_menu??'')==='envios' ? 'active':'' ?>">
        <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M22 2L11 13"/><path d="M22 2L15 22l-4-9-9-4 20-7z"/></svg>
        Envíos
      </a>
      <?php endif; ?>
      <?php if ($_can_certificados): ?>
      <a href="<?= APP_URL ?>/modules/certificados/index.php" data-label="Certificados" class="nav-item <?= ($active_menu??'')==='certificados' ? 'active':'' ?>">
        <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/></svg>
        Certificados
        <span id="nav-badge-cert" class="nav-badge amber"
          style="<?= ($_cert_vencer_badge <= 0 || ($active_menu??'') === 'certificados') ? 'display:none' : '' ?>">
          <?= $_cert_vencer_badge > 9 ? '9+' : $_cert_vencer_badge ?>
        </span>
      </a>
      <?php endif; ?>
      <?php if ($_can_auditorias): ?>
      <a href="<?= APP_URL ?>/modules/auditorias/index.php" data-label="Auditorías" class="nav-item <?= ($active_menu??'')==='auditorias' ? 'active':'' ?>">
        <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
        Auditorías
      </a>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── Finanzas ── -->
  <?php if ($_can_pagos || $_can_bonos || $_can_metas || $_can_reportes): ?>
  <div class="sidebar-section" id="section-finanzas">
    <div class="sidebar-section-label collapsible" onclick="toggleSidebarSection('finanzas')" title="Clic para colapsar">
      Finanzas
      <svg class="section-chevron" width="10" height="10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
    </div>
    <div class="section-items">
      <?php if ($_can_pagos): ?>
      <a href="<?= APP_URL ?>/modules/pagos/index.php" data-label="Pagos" class="nav-item <?= ($active_menu??'')==='pagos' ? 'active':'' ?>">
        <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
        Pagos
        <span id="nav-badge-pagos" class="nav-badge red"
          style="<?= ($_pagos_badge <= 0 || ($active_menu??'') === 'pagos') ? 'display:none' : '' ?>">
          <?= $_pagos_badge > 9 ? '9+' : $_pagos_badge ?>
        </span>
      </a>
      <?php endif; ?>
      <?php if ($_can_bonos): ?>
      <a href="<?= APP_URL ?>/modules/bonos/index.php" data-label="Bonos" class="nav-item <?= ($active_menu??'')==='bonos' ? 'active':'' ?>">
        <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/></svg>
        Bonos
      </a>
      <?php endif; ?>
      <?php if ($_can_metas): ?>
      <a href="<?= APP_URL ?>/modules/metas/index.php" data-label="Metas" class="nav-item <?= ($active_menu??'')==='metas' ? 'active':'' ?>">
        <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        Metas
      </a>
      <?php endif; ?>
      <?php if ($_can_reportes): ?>
      <a href="<?= APP_URL ?>/modules/reportes/index.php" data-label="Reportes" class="nav-item <?= ($active_menu??'')==='reportes' ? 'active':'' ?>">
        <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
        Reportes
      </a>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── Sistema ── -->
  <?php if ($_can_notificaciones || $_can_whatsapp || $_can_historial): ?>
  <div class="sidebar-section" id="section-sistema">
    <div class="sidebar-section-label collapsible" onclick="toggleSidebarSection('sistema')" title="Clic para colapsar">
      Sistema
      <svg class="section-chevron" width="10" height="10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
    </div>
    <div class="section-items">
      <?php if ($_can_notificaciones): ?>
      <a href="<?= APP_URL ?>/modules/notificaciones/index.php" data-label="Notificaciones" class="nav-item <?= ($active_menu??'')==='notificaciones' ? 'active':'' ?>">
        <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
        Notificaciones
        <span id="nav-badge-notif" class="nav-badge red"
          style="<?= ($_notif_count <= 0) ? 'display:none' : '' ?>">
          <?= $_notif_count > 9 ? '9+' : $_notif_count ?>
        </span>
      </a>
      <?php if (has_role('gerente_general')): ?>
      <a href="<?= APP_URL ?>/modules/notificaciones/reglas.php" data-label="Reglas de notif." class="nav-item <?= ($active_menu??'')==='notif_reglas' ? 'active':'' ?>">
        <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 0 0 2.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 0 0 1.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 0 0-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 0 0-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 0 0-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 0 0-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 0 0 1.066-2.573c-.94-1.543.826-3.31 2.37-2.37a1.724 1.724 0 0 0 2.572-1.065z"/><circle cx="12" cy="12" r="3"/></svg>
        Reglas de notif.
      </a>
      <?php endif; ?>
      <?php endif; ?>
      <?php if ($_can_whatsapp): ?>
      <a href="<?= APP_URL ?>/modules/whatsapp/index.php" data-label="WhatsApp" class="nav-item <?= ($active_menu??'')==='whatsapp' ? 'active':'' ?>">
        <svg class="nav-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
        WhatsApp
      </a>
      <a href="<?= APP_URL ?>/modules/notificaciones/log.php" data-label="Log WhatsApp" class="nav-item <?= ($active_menu??'')==='notif_log' ? 'active':'' ?>">
        <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/></svg>
        Log WhatsApp
      </a>
      <a href="<?= APP_URL ?>/modules/whatsapp/campanas.php" data-label="Campañas" class="nav-item <?= ($active_menu??'')==='wa_campanas' ? 'active':'' ?>">
        <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M22 2 11 13"/><path d="M22 2 15 22l-4-9-9-4 20-7z"/></svg>
        Campañas
      </a>
      <a href="<?= APP_URL ?>/modules/whatsapp/plantillas.php" data-label="Plantillas" class="nav-item <?= ($active_menu??'')==='wa_plantillas' ? 'active':'' ?>">
        <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18M8 14h8"/></svg>
        Plantillas
      </a>
      <a href="<?= APP_URL ?>/modules/whatsapp/numeros.php" data-label="Números y configuración" class="nav-item <?= in_array(($active_menu??''),['wa_numeros','wa_conectar','wa_perfil'],true) ? 'active':'' ?>">
        <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 11-2.83 2.83l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 11-2.83-2.83l.06-.06a1.65 1.65 0 00.33-1.82 1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 112.83-2.83l.06.06a1.65 1.65 0 001.82.33H9a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 112.83 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
        Números
      </a>
      <?php endif; ?>
      <?php if ($_can_historial): ?>
      <a href="<?= APP_URL ?>/modules/historial/index.php" data-label="Historial" class="nav-item <?= ($active_menu??'')==='historial' ? 'active':'' ?>">
        <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 8v4l3 3m6-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0z"/></svg>
        Historial
      </a>
      <?php /* "Alertas email" retirado del menú (auditoría 2026-07-29): sistema
               redundante (tabla alertas_email con 0 registros), solapado con
               Notificaciones (in-app + push). El código queda archivado. */ ?>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($_can_mantenimiento): ?>
  <div class="sidebar-section" id="section-mant">
    <div class="sidebar-section-label collapsible" onclick="toggleSidebarSection('mant')" title="Clic para colapsar">
      Configuración
      <svg class="section-chevron" width="10" height="10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
    </div>
    <div class="section-items" id="section-items-mant">
      <a href="<?= APP_URL ?>/modules/mantenimiento/configuracion.php" data-label="Propuesta" class="nav-item <?= ($active_menu??'')==='configuracion' ? 'active':'' ?>">
        <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
        Propuesta
      </a>
      <a href="<?= APP_URL ?>/modules/mantenimiento/normas.php" data-label="Normas ISO" class="nav-item <?= ($active_menu??'')==='normas' ? 'active':'' ?>">
        <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93l-1.41 1.41M4.93 4.93l1.41 1.41M12 2v2M12 20v2M2 12h2M20 12h2M19.07 19.07l-1.41-1.41M4.93 19.07l1.41-1.41"/></svg>
        Normas ISO
      </a>
      <a href="<?= APP_URL ?>/modules/mantenimiento/casas.php" data-label="Casas Certif." class="nav-item <?= ($active_menu??'')==='casas' ? 'active':'' ?>">
        <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
        Casas Certif.
      </a>
      <a href="<?= APP_URL ?>/modules/mantenimiento/agencias.php" data-label="Agencias Envío" class="nav-item <?= ($active_menu??'')==='agencias' ? 'active':'' ?>">
        <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
        Agencias Envío
      </a>
      <a href="<?= APP_URL ?>/modules/mantenimiento/estados_envio.php" data-label="Estados Envío" class="nav-item <?= ($active_menu??'')==='estados_envio' ? 'active':'' ?>">
        <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        Estados Envío
      </a>
      <a href="<?= APP_URL ?>/modules/mantenimiento/usuarios.php" data-label="Usuarios" class="nav-item <?= ($active_menu??'')==='usuarios' ? 'active':'' ?>">
        <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        Usuarios
      </a>
      <?php if (has_role('gerente_general')): ?>
      <a href="<?= APP_URL ?>/modules/mantenimiento/roles.php" data-label="Roles y Permisos" class="nav-item <?= ($active_menu??'')==='roles' ? 'active':'' ?>">
        <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        Roles y Permisos
      </a>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
  </nav><!-- /sidebar-nav -->

  <div class="sidebar-bottom">
    <!-- Popup menú -->
    <div class="user-popup" id="user-popup">
      <div class="user-popup-header">
        <div class="user-popup-avatar"><?= strtoupper(substr($user['nombre'],0,1).substr($user['apellido'],0,1)) ?></div>
        <div>
          <div class="user-popup-name"><?= sanitize($user['nombre'].' '.$user['apellido']) ?></div>
          <div class="user-popup-email"><?= sanitize($user['email']) ?></div>
        </div>
      </div>
      <!-- Mini selector de tema -->
      <div class="tema-pill-row">
        <div class="tema-pill">
          <button class="tema-mini-btn" id="tpb-claro" data-tema="claro" title="Claro" onclick="applyTema('claro')" aria-label="Tema claro">
            <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/></svg>
          </button>
          <button class="tema-mini-btn" id="tpb-sistema" data-tema="sistema" title="Automático" onclick="applyTema('sistema')" aria-label="Tema automático del sistema">
            <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
          </button>
          <button class="tema-mini-btn" id="tpb-oscuro" data-tema="oscuro" title="Oscuro" onclick="applyTema('oscuro')" aria-label="Tema oscuro">
            <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
          </button>
        </div>
      </div>
      <a href="<?= APP_URL ?>/modules/cuenta/index.php" class="user-popup-item">
        <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        Mi cuenta
      </a>
      <a href="<?= APP_URL ?>/modules/auth/logout.php" class="user-popup-item danger">
        <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        Cerrar sesión
      </a>
    </div>
    <!-- Tarjeta usuario clickeable -->
    <div class="user-card" id="user-card-btn" onclick="toggleUserMenu()">
      <div class="user-avatar"><?= strtoupper(substr($user['nombre'],0,1).substr($user['apellido'],0,1)) ?></div>
      <div class="user-info">
        <div class="user-name"><?= sanitize($user['nombre'].' '.$user['apellido']) ?></div>
        <div class="user-role"><?= str_replace('_',' ', $user['rol']) ?></div>
      </div>
      <svg class="user-chevron" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"/></svg>
    </div>
  </div>
</aside>

<!-- Header -->
<header class="header">
  <!-- Toggle móvil -->
  <button class="btn-menu" onclick="toggleSidebar()" aria-label="Menú"><svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg></button>
  <!-- Toggle desktop -->
  <button class="btn-toggle-desktop" onclick="toggleSidebarDesktop()" title="Ocultar/mostrar menú">
    <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="18" rx="1"/><path d="M14 9l3 3-3 3"/></svg>
  </button>
  <div class="header-title"><?= sanitize($page_title ?? 'Dashboard') ?></div>

  <!-- Buscador global -->
  <div class="global-search-wrap" id="gsw">
    <div class="global-search-inner">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--gris-texto);pointer-events:none;">
        <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
      </svg>
      <input type="text" id="global-search-input" placeholder="Buscar clientes, certificados..." autocomplete="off" aria-label="Buscar en el sistema" role="searchbox">
      <kbd>⌘K</kbd>
    </div>
    <div class="gs-dropdown" id="gs-dropdown" style="display:none;"></div>
  </div>
  <div class="header-right">
    <span class="header-date"><?= date('d/m/Y') ?></span>
    <!-- Botón cambio de tema -->
    <button class="btn-tema" id="btn-tema" onclick="ciclarTema()" data-tooltip="Tema claro" aria-label="Cambiar tema de interfaz">
      <svg id="tema-icon" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/>
      </svg>
    </button>
    <button class="notif-btn" onclick="window.location='<?= APP_URL ?>/modules/notificaciones/index.php'" title="<?= $_notif_count ?> notificación(es) sin leer" style="position:relative">
      <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>
      </svg>
      <?php if ($_notif_count > 0): ?>
      <span style="position:absolute;top:-4px;right:-4px;background:#ef4444;color:#fff;font-size:9px;font-weight:700;padding:1px 4px;border-radius:100px;min-width:16px;text-align:center;line-height:14px;">
        <?= $_notif_count > 99 ? '99+' : $_notif_count ?>
      </span>
      <?php endif; ?>
    </button>
  </div>
</header>

<!-- Overlay móvil — fuera de <main> para que SPA no lo reinicie -->
<div id="sidebar-overlay" onclick="closeSidebar()" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:99;"></div>

<!-- Confirm Dialog — Componente reutilizable para confirmaciones -->
<div class="ggi-confirm-dialog" id="confirm-dialog" role="alertdialog" aria-labelledby="confirm-title">
  <div class="ggi-confirm-overlay" onclick="GGIConfirm.close()"></div>
  <div class="ggi-confirm-content">
    <button class="ggi-confirm-close" onclick="GGIConfirm.close()" aria-label="Cerrar">&times;</button>
    <div class="ggi-confirm-icon" id="confirm-icon"></div>
    <div class="ggi-confirm-body">
      <div class="ggi-confirm-title" id="confirm-title"></div>
      <div class="ggi-confirm-message" id="confirm-message"></div>
    </div>
    <div class="ggi-confirm-actions">
      <button class="ggi-btn-confirm-cancel" id="confirm-btn-cancel" onclick="GGIConfirm.close()">Cancelar</button>
      <button id="confirm-btn" onclick="GGIConfirm.confirm()" style="padding:10px 18px;border-radius:8px;border:none;background:#ef4444;color:#fff;cursor:pointer;font-weight:500;font-size:13px;transition:all 0.2s;">Confirmar</button>
    </div>
  </div>
</div>

<!-- Contenido principal -->
<main class="main" id="ggi-main" tabindex="-1">
<?php endif; // end embed check ?>

