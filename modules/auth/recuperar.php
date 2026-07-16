<?php
// modules/auth/recuperar.php — Solicitar recuperación de contraseña
require_once __DIR__ . '/../../core/helpers.php';
session_init();

if (is_logged()) {
    header('Location: ' . APP_URL . '/modules/dashboard/index.php');
    exit;
}

$msg_ok  = '';
$msg_err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify(); // bloquea si el token no coincide (403 + redirect)
    $email = trim($_POST['email'] ?? '');

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $msg_err = 'Ingresa un correo electrónico válido.';
    } else {
        $pdo  = Database::get();
        $stmt = $pdo->prepare("SELECT id, nombre, activo FROM usuarios WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // Siempre mostrar el mismo mensaje (evitar enumeración de emails)
        $msg_ok = 'Si ese correo está registrado, recibirás las instrucciones en breve.';

        if ($user && $user['activo']) {
            // Generar token seguro de 32 bytes (64 chars hex)
            $token     = bin2hex(random_bytes(32));
            $exp       = date('Y-m-d H:i:s', strtotime('+1 hour'));

            $pdo->prepare("UPDATE usuarios SET reset_token = ?, reset_token_exp = ? WHERE id = ?")
                ->execute([$token, $exp, $user['id']]);

            $link = APP_URL . '/modules/auth/reset.php?token=' . $token;

            // Enviar email
            $asunto  = 'Recuperación de contraseña — GGI Sistema';
            $cuerpo  = "Hola {$user['nombre']},\n\n"
                     . "Recibimos una solicitud para restablecer tu contraseña en GGI Sistema.\n\n"
                     . "Haz clic en el siguiente enlace para crear una nueva contraseña:\n"
                     . $link . "\n\n"
                     . "Este enlace expira en 1 hora.\n\n"
                     . "Si no solicitaste este cambio, ignora este mensaje.\n\n"
                     . "— Group Global ISO S.A.C.";

            $headers = "From: GGI Sistema <noreply@groupglobaliso.com>\r\n"
                     . "Reply-To: noreply@groupglobaliso.com\r\n"
                     . "Content-Type: text/plain; charset=UTF-8\r\n"
                     . "X-Mailer: PHP/" . phpversion();

            @mail($email, $asunto, $cuerpo, $headers);

            log_actividad('auth', 'reset_solicitado', 'Solicitud de reset para: ' . $email);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Recuperar contraseña — GGI Sistema</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700&family=Plus+Jakarta+Sans:wght@400;500;600&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --azul:       #1A3A8F;
      --azul-light: #e8edf8;
      --verde:      #2DB140;
      --verde-dark: #229133;
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
      width: 60px; height: 60px; border-radius: 16px;
      background: var(--azul-light); display: flex; align-items: center;
      justify-content: center; margin: 0 auto 22px;
    }
    h1 { font-family: 'Outfit', sans-serif; font-size: 22px; font-weight: 700; color: var(--azul); text-align: center; margin-bottom: 8px; }
    .desc { font-size: 13px; color: var(--gris-texto); text-align: center; line-height: 1.6; margin-bottom: 28px; }
    .field { margin-bottom: 18px; }
    .field label { display: block; font-size: 13px; font-weight: 600; color: var(--azul); margin-bottom: 7px; }
    .input-wrap { position: relative; }
    .input-wrap svg { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); color: var(--gris-texto); pointer-events: none; }
    .field input {
      width: 100%; padding: 11px 14px 11px 40px;
      border: 1.5px solid var(--gris-borde); border-radius: 10px;
      font-size: 14px; font-family: 'Plus Jakarta Sans', sans-serif;
      color: var(--gris-dark); background: #fafbfe; outline: none;
      transition: border-color 0.2s, box-shadow 0.2s;
    }
    .field input:focus { border-color: var(--azul); background: #fff; box-shadow: 0 0 0 4px rgba(26,58,143,0.08); }
    .btn {
      width: 100%; padding: 12px; background: var(--azul); color: #fff;
      border: none; border-radius: 10px; font-size: 14px; font-weight: 600;
      font-family: 'Outfit', sans-serif; cursor: pointer;
      transition: background 0.2s, box-shadow 0.2s;
    }
    .btn:hover { background: #1e4ab0; box-shadow: 0 6px 20px rgba(26,58,143,0.28); }
    .btn:active { transform: scale(0.98); }
    .alert {
      padding: 12px 16px; border-radius: 9px; font-size: 13px;
      margin-bottom: 20px; display: flex; align-items: flex-start; gap: 10px; line-height: 1.5;
    }
    .alert-ok  { background: #e8f7eb; border: 1px solid #b7e4c0; border-left: 4px solid var(--verde); color: #1a6630; }
    .alert-err { background: #fff5f5; border: 1px solid #fecaca; border-left: 4px solid var(--red);  color: #c0392b; }
    .back-link { display: block; text-align: center; margin-top: 22px; font-size: 13px; color: var(--gris-texto); text-decoration: none; }
    .back-link:hover { color: var(--azul); }
    @keyframes fadeUp { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }
  </style>
</head>
<body>
<div class="page">
  <div class="card">

    <div class="icon-wrap">
      <svg width="26" height="26" fill="none" viewBox="0 0 24 24" stroke="#1A3A8F" stroke-width="2">
        <rect x="3" y="11" width="18" height="11" rx="2"/>
        <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
      </svg>
    </div>

    <h1>Recuperar contraseña</h1>
    <p class="desc">Ingresa tu correo y te enviaremos un enlace para crear una nueva contraseña.</p>

    <?php if ($msg_ok): ?>
    <div class="alert alert-ok">
      <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="flex-shrink:0;margin-top:1px"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
      <?= htmlspecialchars($msg_ok) ?>
    </div>
    <?php endif; ?>

    <?php if ($msg_err): ?>
    <div class="alert alert-err">
      <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="flex-shrink:0;margin-top:1px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      <?= htmlspecialchars($msg_err) ?>
    </div>
    <?php endif; ?>

    <?php if (!$msg_ok): ?>
    <form method="POST" novalidate>
      <?= csrf_field() ?>
      <div class="field">
        <label for="email">Correo electrónico</label>
        <div class="input-wrap">
          <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
          <input type="email" id="email" name="email"
                 placeholder="usuario@groupglobaliso.com"
                 value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                 required autofocus>
        </div>
      </div>
      <button type="submit" class="btn">Enviar instrucciones</button>
    </form>
    <?php else: ?>
    <a href="<?= APP_URL ?>/modules/auth/login.php" class="btn" style="display:block;text-align:center;text-decoration:none;">
      Volver al inicio de sesión
    </a>
    <?php endif; ?>

    <a href="<?= APP_URL ?>/modules/auth/login.php" class="back-link">← Volver al inicio de sesión</a>

  </div>
</div>
</body>
</html>
