<?php
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
require_once __DIR__ . '/../../core/helpers.php';
session_init();

if (is_logged()) {
    header('Location: ' . APP_URL . '/modules/dashboard/index.php');
    exit;
}

$error = '';

// Límites de rate limiting
define('LOGIN_MAX_INTENTOS', 5);
define('LOGIN_VENTANA_SEGUNDOS', 900); // 15 minutos

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();  // bloquea si el token no coincide; llama session_write_close() internamente
    session_init(); // reabre la sesión para poder escribir $_SESSION['usuario'] tras el login
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Ingresa tu usuario o correo y la contraseña.';
    } else {
        try {
            $pdo = Database::get();

            // Obtener IP real
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR']
                ?? $_SERVER['HTTP_X_REAL_IP']
                ?? $_SERVER['REMOTE_ADDR']
                ?? '';
            $ip = trim(explode(',', $ip)[0]);

            // Verificar intentos fallidos recientes para esta IP
            $ventana = date('Y-m-d H:i:s', time() - LOGIN_VENTANA_SEGUNDOS);
            $chk = $pdo->prepare("
                SELECT COUNT(*) FROM log_actividad
                WHERE accion = 'login_failed'
                  AND ip = ?
                  AND created_at >= ?
            ");
            $chk->execute([$ip, $ventana]);
            $intentos_recientes = (int)$chk->fetchColumn();

            if ($intentos_recientes >= LOGIN_MAX_INTENTOS) {
                $error = 'Demasiados intentos fallidos. Espera 15 minutos e intenta nuevamente.';
            } else {
                // Ingreso por usuario O correo: el campo acepta cualquiera de los dos.
                $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE (email = ? OR username = ?) AND activo = 1 LIMIT 1");
                $stmt->execute([$email, $email]);
                $user = $stmt->fetch();

                if ($user && password_verify($password, $user['password_hash'])) {
                    $pdo->prepare("UPDATE usuarios SET ultimo_acceso = NOW(), ultimo_ip = ? WHERE id = ?")
                        ->execute([$ip, $user['id']]);
                    unset($user['password_hash']);
                    $_SESSION['usuario'] = $user;
                    log_actividad('auth', 'login', 'Inicio de sesión exitoso desde ' . $ip);
                    header('Location: ' . APP_URL . '/modules/dashboard/index.php');
                    exit;
                } else {
                    $error = 'Usuario/correo o contraseña incorrectos.';
                    log_actividad('auth', 'login_failed', 'Intento fallido: ' . $email . ' desde ' . $ip);
                }
            }
        } catch (Exception $e) {
            $error = 'Error del sistema. Intenta nuevamente.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>Ingresar — GGI Sistema</title>

  <!-- ── PWA ─────────────────────────────────────────────────────────────── -->
  <link rel="manifest" href="<?= APP_URL ?>/manifest.json">
  <meta name="theme-color" media="(prefers-color-scheme: light)" content="#1A3A8F">
  <meta name="theme-color" media="(prefers-color-scheme: dark)"  content="#0C1118">
  <meta name="theme-color" content="#1A3A8F">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="GGI Sistema">
  <!-- Apple touch icons -->
  <link rel="apple-touch-icon"                href="<?= APP_URL ?>/assets/icons/icon-180.png">
  <link rel="apple-touch-icon" sizes="120x120" href="<?= APP_URL ?>/assets/icons/icon-128.png">
  <link rel="apple-touch-icon" sizes="152x152" href="<?= APP_URL ?>/assets/icons/icon-152.png">
  <link rel="apple-touch-icon" sizes="167x167" href="<?= APP_URL ?>/assets/icons/icon-180.png">
  <link rel="apple-touch-icon" sizes="180x180" href="<?= APP_URL ?>/assets/icons/icon-180.png">
  <!-- iOS Splash Screens -->
  <link rel="apple-touch-startup-image" media="(device-width:320px) and (device-height:568px) and (-webkit-device-pixel-ratio:2)" href="<?= APP_URL ?>/assets/splash/splash-640x1136.png">
  <link rel="apple-touch-startup-image" media="(device-width:375px) and (device-height:667px) and (-webkit-device-pixel-ratio:2)" href="<?= APP_URL ?>/assets/splash/splash-750x1334.png">
  <link rel="apple-touch-startup-image" media="(device-width:414px) and (device-height:736px) and (-webkit-device-pixel-ratio:3)" href="<?= APP_URL ?>/assets/splash/splash-1242x2208.png">
  <link rel="apple-touch-startup-image" media="(device-width:375px) and (device-height:812px) and (-webkit-device-pixel-ratio:3)" href="<?= APP_URL ?>/assets/splash/splash-1125x2436.png">
  <link rel="apple-touch-startup-image" media="(device-width:414px) and (device-height:896px) and (-webkit-device-pixel-ratio:2)" href="<?= APP_URL ?>/assets/splash/splash-828x1792.png">
  <link rel="apple-touch-startup-image" media="(device-width:414px) and (device-height:896px) and (-webkit-device-pixel-ratio:3)" href="<?= APP_URL ?>/assets/splash/splash-1242x2688.png">
  <link rel="apple-touch-startup-image" media="(device-width:360px) and (device-height:780px) and (-webkit-device-pixel-ratio:3)" href="<?= APP_URL ?>/assets/splash/splash-1080x2340.png">
  <link rel="apple-touch-startup-image" media="(device-width:390px) and (device-height:844px) and (-webkit-device-pixel-ratio:3)" href="<?= APP_URL ?>/assets/splash/splash-1170x2532.png">
  <link rel="apple-touch-startup-image" media="(device-width:428px) and (device-height:926px) and (-webkit-device-pixel-ratio:3)" href="<?= APP_URL ?>/assets/splash/splash-1284x2778.png">
  <link rel="apple-touch-startup-image" media="(device-width:393px) and (device-height:852px) and (-webkit-device-pixel-ratio:3)" href="<?= APP_URL ?>/assets/splash/splash-1179x2556.png">
  <link rel="apple-touch-startup-image" media="(device-width:430px) and (device-height:932px) and (-webkit-device-pixel-ratio:3)" href="<?= APP_URL ?>/assets/splash/splash-1290x2796.png">
  <!-- Favicon -->
  <link rel="icon" href="<?= APP_URL ?>/favicon.ico" sizes="any">
  <link rel="icon" type="image/png" sizes="32x32"   href="<?= APP_URL ?>/assets/icons/icon-32.png">
  <link rel="icon" type="image/png" sizes="192x192" href="<?= APP_URL ?>/assets/icons/icon-192.png">
  <link rel="icon" type="image/png" sizes="512x512" href="<?= APP_URL ?>/assets/icons/icon-512.png">

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <!-- Anti-flash: aplicar tema antes de pintar -->
  <script>
  (function(){
    try {
      var t = localStorage.getItem('ggi_tema') || 'claro';
      var esDark = (t === 'oscuro') ||
                   (t === 'sistema' && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
      if (esDark) {
        document.documentElement.setAttribute('data-tema', 'oscuro');
      } else {
        document.documentElement.setAttribute('data-tema', 'claro');
        document.documentElement.style.removeProperty('background');
        document.documentElement.style.removeProperty('background-color');
      }
    } catch(e) {}
  })();
  </script>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --azul:       #1D4ED8;
      --azul-dark:  #1E40AF;
      --azul-light: #2563EB;
      --verde:      #2DB140;
      --verde-dark: #229133;
      --blanco:     #ffffff;
      --gris-claro: #F1F5F9;
      --gris-borde: #E2E8F0;
      --gris-texto: #64748b;
      --error:      #e53935;
      /* Dark mode tokens */
      --dm-bg:       #0C1118;
      --dm-surface:  #111927;
      --dm-surface2: #182336;
      --dm-brd:      #1A2840;
      --dm-tx:       #ECF1FB;
      --dm-tx2:      #8893A8;
      --dm-azul:     #5B9EF5;
      --dm-input-bg: #0F1722;
    }
    html, body {
      height: 100%; min-height: 100dvh;
      font-family: 'Inter', sans-serif;
      background: var(--gris-claro);
    }
    /* ══════════════════════════════════════════
       DARK MODE — login page
       ══════════════════════════════════════════ */
    :root[data-tema="oscuro"] body  { background: var(--dm-bg) !important; }
    :root[data-tema="oscuro"] html  { background: var(--dm-bg) !important; }
    /* Modo claro explícito — :root[data-tema="claro"] selecciona el propio <html> */
    :root[data-tema="claro"],
    :root[data-tema="claro"] body        { background: #ffffff; }
    :root[data-tema="claro"] .panel-form { background: #ffffff; }

    /* Panel form (lado derecho) — desktop */
    :root[data-tema="oscuro"] .panel-form {
      background: var(--dm-surface) !important;
    }

    /* ── MÓVIL oscuro: fondo igual al panel para evitar recuadro visible ── */
    @media (max-width: 680px) {
      :root[data-tema="oscuro"] html,
      :root[data-tema="oscuro"] body        { background: var(--dm-bg) !important; }
      :root[data-tema="oscuro"] .panel-form { background: var(--dm-bg) !important; }
    }

    /* Textos del form */
    :root[data-tema="oscuro"] .form-saludo   { color: var(--dm-azul) !important; }
    :root[data-tema="oscuro"] .form-instruccion { color: var(--dm-tx2) !important; }
    :root[data-tema="oscuro"] .field-label label { color: var(--dm-azul) !important; }
    :root[data-tema="oscuro"] .form-footer a { color: var(--dm-azul) !important; }
    :root[data-tema="oscuro"] .form-footer-note { color: var(--dm-tx2) !important; }
    :root[data-tema="oscuro"] .form-footer { border-top-color: var(--dm-brd) !important; }

    /* Inputs */
    :root[data-tema="oscuro"] .field input {
      background: var(--dm-input-bg) !important;
      border-color: var(--dm-brd) !important;
      color: var(--dm-tx) !important;
    }
    :root[data-tema="oscuro"] .field input::placeholder { color: #3a4a5e !important; }
    :root[data-tema="oscuro"] .field input:focus {
      border-color: var(--dm-azul) !important;
      background: var(--dm-surface2) !important;
      box-shadow: 0 0 0 4px rgba(91,158,245,0.12) !important;
    }
    :root[data-tema="oscuro"] .input-icon { color: var(--dm-tx2) !important; }
    :root[data-tema="oscuro"] .field input:focus ~ .input-icon,
    :root[data-tema="oscuro"] .input-wrap:focus-within .input-icon { color: var(--dm-azul) !important; }
    :root[data-tema="oscuro"] .toggle-pass { color: var(--dm-tx2) !important; }
    :root[data-tema="oscuro"] .toggle-pass:hover { color: var(--dm-azul) !important; background: rgba(91,158,245,0.1) !important; }

    /* ISO chips */
    :root[data-tema="oscuro"] .iso-chip {
      background: var(--dm-surface2) !important;
      border-color: var(--dm-brd) !important;
      color: var(--dm-azul) !important;
    }

    /* Mobile header */
    :root[data-tema="oscuro"] .mobile-logo-inner { background: var(--dm-surface2) !important; }

    /* Botón tema */
    .btn-tema-login {
      position: fixed; top: 14px; right: 16px; z-index: 999;
      width: 38px; height: 38px; border-radius: 10px;
      background: #e2e8f0; border: 1.5px solid #cbd5e1;
      cursor: pointer; display: flex; align-items: center; justify-content: center;
      color: #475569; transition: background 0.18s, border-color 0.18s, transform 0.12s;
    }
    .btn-tema-login:hover { background: #d1dae7; border-color: #94a3b8; color: var(--azul); transform: scale(1.06); }
    .btn-tema-login:active { transform: scale(0.95); }
    :root[data-tema="oscuro"] .btn-tema-login {
      background: var(--dm-surface2);
      border-color: var(--dm-brd);
      color: var(--dm-tx2);
    }
    :root[data-tema="oscuro"] .btn-tema-login:hover {
      background: var(--dm-brd);
      color: var(--dm-azul);
    }
    @media (min-width: 681px) {
      .btn-tema-login {
        background: var(--gris-claro);
        border: 1.5px solid var(--gris-borde);
        color: #475569;
      }
    }

    /* ── Layout ── */
    .page {
      display: grid;
      grid-template-columns: 42% 1fr;
      min-height: 100vh;
    }

    /* ── Panel marca (izquierda) ── */
    .panel-marca {
      background: linear-gradient(175deg, #1D4ED8 0%, #1E3A8A 100%);
      position: relative;
      display: flex; flex-direction: column;
      justify-content: center; align-items: center;
      padding: 60px 50px; overflow: hidden;
    }
    .panel-marca::before {
      content: ''; position: absolute;
      width: 500px; height: 500px; border-radius: 50%;
      border: 80px solid rgba(255,255,255,0.04);
      top: -160px; right: -160px;
    }
    .panel-marca::after {
      content: ''; position: absolute;
      width: 300px; height: 300px; border-radius: 50%;
      border: 50px solid rgba(45,177,64,0.12);
      bottom: -80px; left: -80px;
    }
    .deco-circle {
      position: absolute; width: 180px; height: 180px; border-radius: 50%;
      background: rgba(45,177,64,0.08); bottom: 120px; right: -40px;
    }
    .deco-bar {
      position: absolute; width: 6px; height: 200px;
      background: linear-gradient(to bottom, var(--verde), transparent);
      left: 44px; top: 100px; border-radius: 3px; opacity: 0.5;
    }
    .marca-inner { position: relative; z-index: 1; text-align: center; }
    .logo-wrapper { margin-bottom: 36px; animation: fadeUp 0.7s ease both; }
    .logo-box {
      display: inline-block;
      padding: 0;
    }
    .logo-svg {
      max-width: 200px; width: 100%; height: auto; display: block;
      filter: brightness(0) invert(1);
    }
    .marca-titulo {
      font-family: 'DM Sans', sans-serif; font-size: 24px; font-weight: 700;
      color: var(--blanco); letter-spacing: -0.5px;
      animation: fadeUp 0.7s 0.1s ease both;
    }
    .marca-subtitulo {
      font-size: 11px; font-weight: 400; color: rgba(255,255,255,0.5);
      margin-top: 4px; letter-spacing: 1px; text-transform: uppercase;
      animation: fadeUp 0.7s 0.15s ease both;
    }
    .marca-divider {
      width: 40px; height: 3px; background: var(--verde);
      border-radius: 2px; margin: 20px auto;
      animation: fadeUp 0.7s 0.2s ease both;
    }
    .marca-descripcion {
      font-size: 13px; color: rgba(255,255,255,0.55);
      line-height: 1.8; max-width: 260px;
      animation: fadeUp 0.7s 0.25s ease both;
    }
    .marca-badges {
      display: flex; gap: 8px; justify-content: center;
      margin-top: 28px; flex-wrap: wrap;
      animation: fadeUp 0.7s 0.35s ease both;
    }
    .badge {
      background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.12);
      color: rgba(255,255,255,0.7); font-size: 11px; font-weight: 500;
      padding: 4px 12px; border-radius: 100px;
    }
    .badge.verde {
      background: rgba(45,177,64,0.15); border-color: rgba(45,177,64,0.35); color: #7ee88a;
    }
    .marca-footer {
      position: absolute; bottom: 24px; left: 0; right: 0; text-align: center;
      font-size: 11px; color: rgba(255,255,255,0.25);
      animation: fadeUp 0.7s 0.5s ease both;
    }

    /* ── Panel formulario (derecha) ── */
    .panel-form {
      display: flex; flex-direction: column;
      justify-content: center; align-items: center;
      padding: 60px 50px; background: var(--blanco);
      position: relative;
    }
    .form-card { width: 100%; max-width: 400px; }

    /* Logo compacto — solo en móvil */
    .mobile-header {
      display: none;
      text-align: center;
      margin-bottom: 32px;
      animation: fadeUp 0.6s ease both;
    }
    .mobile-logo-inner {
      display: inline-flex; align-items: center; gap: 14px;
      background: var(--azul); border-radius: 14px;
      padding: 14px 22px; border: none;
    }
    .mobile-logo-inner svg { flex-shrink: 0; }
    .mobile-logo-text { text-align: left; }
    .mobile-logo-text strong {
      display: block; font-family: 'DM Sans', sans-serif;
      font-size: 15px; font-weight: 700; color: #fff;
    }
    .mobile-logo-text span {
      font-size: 10px; color: rgba(255,255,255,0.65);
      letter-spacing: 0.5px; text-transform: uppercase;
    }

    .form-header { margin-bottom: 30px; animation: fadeUp 0.6s 0.15s ease both; }
    .form-saludo {
      font-family: 'DM Sans', sans-serif; font-size: 26px; font-weight: 700;
      color: var(--azul); letter-spacing: -0.5px;
    }
    .form-instruccion { font-size: 14px; color: var(--gris-texto); margin-top: 6px; }

    /* Error */
    .alert-error {
      background: #fff5f5; border: 1px solid #fecaca;
      border-left: 4px solid var(--error); color: #c0392b;
      padding: 12px 16px; border-radius: 8px; font-size: 13px;
      margin-bottom: 20px; display: flex; align-items: flex-start; gap: 10px;
      animation: shake 0.4s ease;
    }
    .alert-error svg { flex-shrink: 0; margin-top: 1px; }

    /* Campos */
    .field { margin-bottom: 18px; animation: fadeUp 0.6s ease both; }
    .field:nth-child(1) { animation-delay: 0.2s; }
    .field:nth-child(2) { animation-delay: 0.28s; }
    .field-label {
      display: flex; align-items: center; justify-content: space-between;
      margin-bottom: 7px;
    }
    .field-label label {
      font-size: 13px; font-weight: 600; color: var(--azul);
    }
    .input-wrap { position: relative; }
    .input-icon {
      position: absolute; left: 14px; top: 50%;
      transform: translateY(-50%); color: var(--gris-texto);
      pointer-events: none; transition: color 0.2s;
    }
    .field input {
      width: 100%; padding: 13px 14px 13px 42px;
      border: 1.5px solid var(--gris-borde); border-radius: 10px;
      font-size: 14px; font-family: 'Inter', sans-serif;
      color: #1a1f36; background: #fafbfe;
      transition: border-color 0.2s, box-shadow 0.2s, background 0.2s;
      outline: none; -webkit-appearance: none;
    }
    .field input:focus {
      border-color: var(--azul); background: var(--blanco);
      box-shadow: 0 0 0 4px rgba(29,78,216,0.10);
    }
    .field input:focus ~ .input-icon,
    .input-wrap:focus-within .input-icon { color: var(--azul); }
    .field input::placeholder { color: #b8c5d8; }

    /* Input con padding-right para el toggle */
    #password { padding-right: 46px; }

    .toggle-pass {
      position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
      background: none; border: none; cursor: pointer; color: var(--gris-texto);
      padding: 6px; display: flex; align-items: center; border-radius: 6px;
      transition: color 0.2s, background 0.2s;
    }
    .toggle-pass:hover { color: var(--azul); background: rgba(26,58,143,0.06); }

    /* Botón submit */
    .btn-login {
      width: 100%; padding: 14px;
      background: var(--azul); color: var(--blanco);
      border: none; border-radius: 10px;
      font-size: 15px; font-weight: 600; font-family: 'DM Sans', sans-serif;
      cursor: pointer; letter-spacing: 0.3px;
      transition: background 0.2s, transform 0.12s, box-shadow 0.2s;
      margin-top: 10px; display: flex; align-items: center; justify-content: center; gap: 8px;
      animation: fadeUp 0.6s 0.35s ease both;
      position: relative; overflow: hidden;
    }
    .btn-login:not(:disabled):hover {
      background: var(--azul-light);
      box-shadow: 0 6px 20px rgba(29,78,216,0.35);
    }
    .btn-login:not(:disabled):active { transform: scale(0.98); }
    .btn-login:disabled { opacity: 0.75; cursor: not-allowed; }
    .btn-spinner {
      display: none; width: 16px; height: 16px;
      border: 2px solid rgba(255,255,255,0.4);
      border-top-color: #fff; border-radius: 50%;
      animation: spin 0.7s linear infinite;
    }
    .btn-login.loading .btn-spinner { display: block; }
    .btn-login.loading .btn-text { opacity: 0.85; }

    /* Footer del form */
    .form-footer {
      margin-top: 28px; padding-top: 22px;
      border-top: 1px solid var(--gris-borde); text-align: center;
      animation: fadeUp 0.6s 0.45s ease both;
    }
    .form-footer a {
      font-size: 13px; color: var(--azul); font-weight: 600;
      text-decoration: none; transition: opacity 0.2s;
    }
    .form-footer a:hover { opacity: 0.75; }
    .form-footer-note {
      margin-top: 8px; font-size: 12px; color: var(--gris-texto);
    }
    .form-footer-note span { color: var(--verde-dark); font-weight: 600; }
    .iso-strip {
      display: flex; gap: 6px; justify-content: center;
      margin-top: 16px; flex-wrap: wrap;
    }
    .iso-chip {
      font-size: 10px; font-weight: 600; letter-spacing: 0.5px;
      padding: 3px 9px; border-radius: 100px;
      background: var(--gris-claro); color: var(--azul);
      border: 1px solid var(--gris-borde);
    }

    /* ── Animaciones ── */
    @keyframes fadeUp {
      from { opacity: 0; transform: translateY(14px); }
      to   { opacity: 1; transform: translateY(0); }
    }
    @keyframes shake {
      0%,100% { transform: translateX(0); }
      20%     { transform: translateX(-5px); }
      40%     { transform: translateX(5px); }
      60%     { transform: translateX(-3px); }
      80%     { transform: translateX(3px); }
    }
    @keyframes spin {
      to { transform: rotate(360deg); }
    }

    /* ── ISO chips: tema claro explícito (evita que se pierdan en fondos claros) ── */
    .iso-chip {
      background: rgba(29,78,216,0.06);
      color: var(--azul);
      border: 1px solid rgba(29,78,216,0.18);
    }

    /* ── Responsive ── */
    /* Tablet apaisada: panel izquierdo más estrecho */
    @media (max-width: 960px) {
      .page { grid-template-columns: 38% 1fr; }
      .panel-marca { padding: 50px 32px; }
      .logo-svg { width: 110px; }
      .marca-descripcion { display: none; }
      .panel-form { padding: 50px 36px; }
    }

    /* Móvil: panel izquierdo oculto, header compacto en form */
    @media (max-width: 680px) {
      .page { grid-template-columns: 1fr; min-height: 100dvh; }
      .panel-marca { display: none; }
      .mobile-header { display: block; }

      html, body { background: #fff; }
      .panel-form {
        justify-content: flex-start;
        padding: 40px 24px 48px;
        min-height: 100dvh;
        background: #fff;
      }
      .form-card { max-width: 100%; }
      .form-saludo { font-size: 22px; }
      .alert-error { animation: none; }
    }

    /* Safe-area en modo PWA standalone */
    @media (max-width: 680px) and (display-mode: standalone) {
      .panel-form {
        padding-top:    calc(40px + env(safe-area-inset-top,    0px));
        padding-bottom: calc(48px + env(safe-area-inset-bottom, 0px));
      }
    }

    /* Pantallas muy pequeñas */
    @media (max-width: 360px) {
      .panel-form { padding: 32px 18px 40px; }
    }
  </style>
</head>
<body>
<?php include __DIR__ . '/../../templates/pwa-splash.php'; ?>

<!-- Botón de tema — esquina superior derecha, visible en todas las vistas -->
<button class="btn-tema-login" id="btn-tema-login" onclick="toggleTemaLogin()" aria-label="Cambiar tema">
  <svg id="login-tema-icon" width="17" height="17" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
    <circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/>
  </svg>
</button>

<div class="page">

  <!-- ── Panel marca (escritorio) ── -->
  <div class="panel-marca">
    <div class="deco-circle"></div>
    <div class="deco-bar"></div>
    <div class="marca-inner">
      <div class="logo-wrapper">
        <div class="logo-box">
          <img src="<?= APP_URL ?>/assets/logo-ggi.png?v=2" alt="Group Global ISO" class="logo-svg">
        </div>
      </div>
      <div class="marca-titulo">Group Global ISO</div>
      <div class="marca-subtitulo">S.A.C. — Sistema de Gestión</div>
      <div class="marca-divider"></div>
      <div class="marca-descripcion">
        Plataforma integral para la gestión documentaria de certificaciones ISO. Administra clientes, certificados, auditorías y envíos desde un solo lugar.
      </div>
      <div class="marca-badges">
        <span class="badge verde">Sistema v1.0</span>
        <span class="badge">Acceso seguro</span>
        <span class="badge">Lima, Perú</span>
      </div>
    </div>
    <div class="marca-footer">© <?= date('Y') ?> Group Global ISO S.A.C.</div>
  </div>

  <!-- ── Panel formulario ── -->
  <div class="panel-form">
    <div class="form-card">

      <!-- Header compacto — solo visible en móvil -->
      <div class="mobile-header">
        <div class="mobile-logo-inner">
          <img src="<?= APP_URL ?>/assets/logo-ggi.png?v=<?= filemtime(__DIR__.'/../../assets/logo-ggi.png') ?>" alt="GGI" style="height:28px;width:auto;max-width:90px;object-fit:contain;flex-shrink:0;display:block;filter:brightness(0) invert(1)">
          <div class="mobile-logo-text">
            <strong>Group Global ISO</strong>
            <span>Sistema de Gestión</span>
          </div>
        </div>
      </div>

      <div class="form-header">
        <div class="form-saludo">Bienvenido de vuelta</div>
        <div class="form-instruccion">Ingresa tus credenciales para continuar</div>
      </div>

      <?php if ($error): ?>
      <div class="alert-error" role="alert">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
        <?= sanitize($error) ?>
      </div>
      <?php endif; ?>

      <form method="POST" id="login-form" novalidate>
        <?= csrf_field() ?>
        <div class="field">
          <div class="field-label">
            <label for="email">Usuario o correo</label>
          </div>
          <div class="input-wrap">
            <svg class="input-icon" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
            </svg>
            <input type="text" id="email" name="email"
                   placeholder="usuario o correo@groupglobaliso.com"
                   value="<?= sanitize($_POST['email'] ?? '') ?>"
                   autocomplete="username"
                   required autofocus>
          </div>
        </div>

        <div class="field">
          <div class="field-label">
            <label for="password">Contraseña</label>
          </div>
          <div class="input-wrap">
            <svg class="input-icon" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>
            </svg>
            <input type="password" id="password" name="password"
                   placeholder="••••••••"
                   autocomplete="current-password"
                   required>
            <button type="button" class="toggle-pass" id="toggle-pass" aria-label="Mostrar u ocultar contraseña">
              <svg id="eye-icon" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
              </svg>
            </button>
          </div>
        </div>

        <button type="submit" class="btn-login" id="btn-login">
          <div class="btn-spinner" id="btn-spinner"></div>
          <span class="btn-text">Ingresar al sistema</span>
        </button>
      </form>

      <div class="form-footer">
        <a href="<?= APP_URL ?>/modules/auth/recuperar.php">¿Olvidaste tu contraseña?</a>
        <p class="form-footer-note">¿Otros problemas? Contacta al <span>administrador</span></p>
        <div class="iso-strip">
          <span class="iso-chip">ISO 9001</span>
          <span class="iso-chip">ISO 14001</span>
          <span class="iso-chip">ISO 45001</span>
          <span class="iso-chip">ISO 27001</span>
        </div>
      </div>

    </div>
  </div>

</div>
<script>
// Toggle contraseña
document.getElementById('toggle-pass').addEventListener('click', function() {
  var inp = document.getElementById('password');
  var ico = document.getElementById('eye-icon');
  var visible = inp.type === 'text';
  inp.type = visible ? 'password' : 'text';
  this.setAttribute('aria-label', visible ? 'Mostrar contraseña' : 'Ocultar contraseña');
  ico.innerHTML = visible
    ? '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>'
    : '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>';
});

// Estado de carga al enviar
document.getElementById('login-form').addEventListener('submit', function() {
  var btn = document.getElementById('btn-login');
  btn.classList.add('loading');
  btn.disabled = true;
  document.querySelector('.btn-text').textContent = 'Ingresando…';
});

// ── Toggle de tema en login ──
var TEMA_LOGIN_ICONS = {
  claro:  '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/>',
  oscuro: '<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>',
};

function _resolverReal(t) {
  if (t !== 'sistema') return t;
  return (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches)
    ? 'oscuro' : 'claro';
}

function aplicarTemaLogin(tema) {
  var real = _resolverReal(tema);
  var root = document.documentElement;

  // Solo manipulamos el atributo — el CSS (via variables) maneja todos los colores
  if (real === 'oscuro') {
    root.setAttribute('data-tema', 'oscuro');
  } else {
    root.setAttribute('data-tema', 'claro');
    // Limpiar cualquier inline background residual de versiones anteriores
    root.style.removeProperty('background');
    root.style.removeProperty('background-color');
    document.body.style.removeProperty('background');
    document.body.style.removeProperty('background-color');
  }

  // Actualizar icono
  var icon = document.getElementById('login-tema-icon');
  if (icon) icon.innerHTML = TEMA_LOGIN_ICONS[real] || TEMA_LOGIN_ICONS.claro;

  try { localStorage.setItem('ggi_tema', tema); } catch(e){}
}

function toggleTemaLogin() {
  var actual = localStorage.getItem('ggi_tema') || 'claro';
  // Resolvemos el tema efectivo actual antes de invertir (cubre el caso 'sistema')
  var real = _resolverReal(actual);
  aplicarTemaLogin(real === 'oscuro' ? 'claro' : 'oscuro');
}

// Inicializar icono al cargar
(function(){
  var t    = localStorage.getItem('ggi_tema') || 'claro';
  var real = _resolverReal(t);
  var icon = document.getElementById('login-tema-icon');
  if (icon) icon.innerHTML = TEMA_LOGIN_ICONS[real] || TEMA_LOGIN_ICONS.claro;
})();
</script>

<?php include __DIR__ . '/../../templates/pwa-install.php'; ?>
</body>
</html>
