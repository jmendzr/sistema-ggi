<?php
// modules/auth/reset.php — Establecer nueva contraseña con token
require_once __DIR__ . '/../../core/helpers.php';
session_init();

if (is_logged()) {
    header('Location: ' . APP_URL . '/modules/dashboard/index.php');
    exit;
}

$token   = trim($_GET['token'] ?? $_POST['token'] ?? '');
$msg_err = '';
$done    = false;

// Validar token
$pdo  = Database::get();
$user = null;

if ($token) {
    $stmt = $pdo->prepare("
        SELECT id, nombre, email
        FROM usuarios
        WHERE reset_token = ?
          AND reset_token_exp > NOW()
          AND activo = 1
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $user = $stmt->fetch();
}

if (!$token || !$user) {
    $msg_err = 'El enlace es inválido o ya expiró. Solicita uno nuevo.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user) {
    csrf_verify(); // bloquea si el token no coincide (403 + redirect)
    $pass1 = $_POST['password']  ?? '';
    $pass2 = $_POST['password2'] ?? '';

    if (strlen($pass1) < 8) {
        $msg_err = 'La contraseña debe tener al menos 8 caracteres.';
    } elseif ($pass1 !== $pass2) {
        $msg_err = 'Las contraseñas no coinciden.';
    } else {
        $hash = password_hash($pass1, PASSWORD_BCRYPT, ['cost' => 12]);

        // Actualizar contraseña e invalidar el token
        $pdo->prepare("
            UPDATE usuarios
            SET password_hash = ?, reset_token = NULL, reset_token_exp = NULL, updated_at = NOW()
            WHERE id = ?
        ")->execute([$hash, $user['id']]);

        log_actividad('auth', 'reset_completado', 'Contraseña restablecida para: ' . $user['email']);
        $done = true;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Nueva contraseña — GGI Sistema</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700&family=Plus+Jakarta+Sans:wght@400;500;600&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --azul:       #1A3A8F;
      --azul-light: #e8edf8;
      --verde:      #2DB140;
      --gris-bg:    #f0f2f8;
      --gris-borde: #dde3f0;
      --gris-texto: #6b7a99;
      --gris-dark:  #1a1f36;
      --red:        #ef4444;
    }
    html, body { height: 100%; font-family: 'Plus Jakarta Sans', sans-serif; background: var(--gris-bg); }
    .page { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; }
    .card {
      background: #fff; border-radius: 20px; border: 1px solid var(--gris-borde);
      padding: 44px 40px; max-width: 420px; width: 100%;
      animation: fadeUp 0.4s ease both;
    }
    .icon-wrap {
      width: 60px; height: 60px; border-radius: 16px; display: flex;
      align-items: center; justify-content: center; margin: 0 auto 22px;
    }
    .icon-wrap.ok  { background: #e8f7eb; }
    .icon-wrap.key { background: var(--azul-light); }
    .icon-wrap.err { background: #fee2e2; }
    h1 { font-family: 'Outfit', sans-serif; font-size: 22px; font-weight: 700; color: var(--azul); text-align: center; margin-bottom: 8px; }
    .desc { font-size: 13px; color: var(--gris-texto); text-align: center; line-height: 1.6; margin-bottom: 28px; }
    .field { margin-bottom: 18px; }
    .field label { display: block; font-size: 13px; font-weight: 600; color: var(--azul); margin-bottom: 7px; }
    .input-wrap { position: relative; }
    .input-wrap > svg { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); color: var(--gris-texto); pointer-events: none; }
    .field input {
      width: 100%; padding: 11px 42px 11px 40px;
      border: 1.5px solid var(--gris-borde); border-radius: 10px;
      font-size: 14px; font-family: 'Plus Jakarta Sans', sans-serif;
      color: var(--gris-dark); background: #fafbfe; outline: none;
      transition: border-color 0.2s, box-shadow 0.2s;
    }
    .field input:focus { border-color: var(--azul); background: #fff; box-shadow: 0 0 0 4px rgba(26,58,143,0.08); }
    .field input.error { border-color: var(--red); }
    .err-msg { font-size: 11px; color: var(--red); margin-top: 4px; }
    .toggle-pass {
      position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
      background: none; border: none; cursor: pointer; color: var(--gris-texto);
      display: flex; align-items: center; padding: 4px;
    }
    .toggle-pass:hover { color: var(--azul); }
    .strength { margin-top: 6px; height: 4px; border-radius: 2px; background: var(--gris-borde); overflow: hidden; }
    .strength-bar { height: 100%; border-radius: 2px; transition: width 0.3s, background 0.3s; width: 0; }
    .strength-label { font-size: 11px; color: var(--gris-texto); margin-top: 4px; }
    .btn {
      width: 100%; padding: 12px; background: var(--azul); color: #fff;
      border: none; border-radius: 10px; font-size: 14px; font-weight: 600;
      font-family: 'Outfit', sans-serif; cursor: pointer;
      transition: background 0.2s, box-shadow 0.2s;
    }
    .btn:hover { background: #1e4ab0; box-shadow: 0 6px 20px rgba(26,58,143,0.28); }
    .btn:active { transform: scale(0.98); }
    .alert-err {
      background: #fff5f5; border: 1px solid #fecaca; border-left: 4px solid var(--red);
      color: #c0392b; padding: 12px 16px; border-radius: 9px; font-size: 13px;
      margin-bottom: 20px; display: flex; align-items: flex-start; gap: 10px; line-height: 1.5;
    }
    .back-link { display: block; text-align: center; margin-top: 22px; font-size: 13px; color: var(--gris-texto); text-decoration: none; }
    .back-link:hover { color: var(--azul); }
    @keyframes fadeUp { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }
  </style>
</head>
<body>
<div class="page">
  <div class="card">

    <?php if ($done): ?>
      <!-- ── Contraseña actualizada exitosamente ── -->
      <div class="icon-wrap ok">
        <svg width="28" height="28" fill="none" viewBox="0 0 24 24" stroke="#2DB140" stroke-width="2">
          <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>
        </svg>
      </div>
      <h1>¡Contraseña actualizada!</h1>
      <p class="desc">Tu contraseña se actualizó correctamente. Ya puedes iniciar sesión con tu nueva contraseña.</p>
      <a href="<?= APP_URL ?>/modules/auth/login.php" class="btn" style="display:block;text-align:center;text-decoration:none;">
        Iniciar sesión
      </a>

    <?php elseif ($msg_err && !$user): ?>
      <!-- ── Token inválido o expirado ── -->
      <div class="icon-wrap err">
        <svg width="28" height="28" fill="none" viewBox="0 0 24 24" stroke="#ef4444" stroke-width="2">
          <circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>
        </svg>
      </div>
      <h1>Enlace inválido</h1>
      <p class="desc"><?= htmlspecialchars($msg_err) ?></p>
      <a href="<?= APP_URL ?>/modules/auth/recuperar.php" class="btn" style="display:block;text-align:center;text-decoration:none;">
        Solicitar nuevo enlace
      </a>
      <a href="<?= APP_URL ?>/modules/auth/login.php" class="back-link">← Volver al inicio de sesión</a>

    <?php else: ?>
      <!-- ── Formulario nueva contraseña ── -->
      <div class="icon-wrap key">
        <svg width="26" height="26" fill="none" viewBox="0 0 24 24" stroke="#1A3A8F" stroke-width="2">
          <path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/>
        </svg>
      </div>
      <h1>Nueva contraseña</h1>
      <p class="desc">Hola <strong><?= htmlspecialchars($user['nombre']) ?></strong>, ingresa tu nueva contraseña.</p>

      <?php if ($msg_err): ?>
      <div class="alert-err">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="flex-shrink:0;margin-top:1px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <?= htmlspecialchars($msg_err) ?>
      </div>
      <?php endif; ?>

      <form method="POST" id="form-reset" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

        <div class="field">
          <label for="password">Nueva contraseña</label>
          <div class="input-wrap">
            <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            <input type="password" id="password" name="password" placeholder="Mínimo 8 caracteres" autocomplete="new-password">
            <button type="button" class="toggle-pass" onclick="toggleVer('password','ico1')">
              <svg id="ico1" width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
          <div class="strength"><div class="strength-bar" id="strength-bar"></div></div>
          <div class="strength-label" id="strength-label"></div>
          <div class="err-msg" id="err-pass"></div>
        </div>

        <div class="field">
          <label for="password2">Confirmar contraseña</label>
          <div class="input-wrap">
            <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            <input type="password" id="password2" name="password2" placeholder="Repite la contraseña" autocomplete="new-password">
            <button type="button" class="toggle-pass" onclick="toggleVer('password2','ico2')">
              <svg id="ico2" width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
          <div class="err-msg" id="err-pass2"></div>
        </div>

        <button type="submit" class="btn" id="btn-submit">Guardar nueva contraseña</button>
      </form>

      <a href="<?= APP_URL ?>/modules/auth/login.php" class="back-link">← Volver al inicio de sesión</a>

      <script>
      function toggleVer(inputId, iconId) {
        const inp = document.getElementById(inputId);
        const ico = document.getElementById(iconId);
        const show = inp.type === 'password';
        inp.type = show ? 'text' : 'password';
        ico.innerHTML = show
          ? '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>'
          : '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
      }

      // Indicador de fortaleza de contraseña
      document.getElementById('password').addEventListener('input', function() {
        const val = this.value;
        const bar = document.getElementById('strength-bar');
        const lbl = document.getElementById('strength-label');
        let score = 0;
        if (val.length >= 8)  score++;
        if (val.length >= 12) score++;
        if (/[A-Z]/.test(val)) score++;
        if (/[0-9]/.test(val)) score++;
        if (/[^A-Za-z0-9]/.test(val)) score++;
        const config = [
          { w: '0%',   bg: 'transparent', txt: '' },
          { w: '20%',  bg: '#ef4444',     txt: 'Muy débil' },
          { w: '40%',  bg: '#f59e0b',     txt: 'Débil' },
          { w: '60%',  bg: '#f59e0b',     txt: 'Regular' },
          { w: '80%',  bg: '#2DB140',     txt: 'Buena' },
          { w: '100%', bg: '#2DB140',     txt: 'Excelente' },
        ][Math.min(score, 5)];
        bar.style.width      = config.w;
        bar.style.background = config.bg;
        lbl.textContent      = config.txt;
        lbl.style.color      = config.bg;
      });

      // Validación client-side
      document.getElementById('form-reset').addEventListener('submit', function(e) {
        const p1 = document.getElementById('password').value;
        const p2 = document.getElementById('password2').value;
        const e1 = document.getElementById('err-pass');
        const e2 = document.getElementById('err-pass2');
        let ok = true;
        e1.textContent = ''; e2.textContent = '';
        document.getElementById('password').classList.remove('error');
        document.getElementById('password2').classList.remove('error');

        if (p1.length < 8) {
          e1.textContent = 'Mínimo 8 caracteres.';
          document.getElementById('password').classList.add('error');
          ok = false;
        }
        if (p1 !== p2) {
          e2.textContent = 'Las contraseñas no coinciden.';
          document.getElementById('password2').classList.add('error');
          ok = false;
        }
        if (!ok) { e.preventDefault(); return; }

        const btn = document.getElementById('btn-submit');
        btn.disabled = true;
        btn.textContent = 'Guardando...';
      });
      </script>

    <?php endif; ?>
  </div>
</div>
</body>
</html>
