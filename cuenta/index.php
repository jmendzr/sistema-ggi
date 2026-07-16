<?php
require_once __DIR__ . '/../../core/helpers.php';
session_init();
require_login();

$pdo  = Database::get();
$uid  = auth_user()['id'];
$tab  = $_GET['tab'] ?? 'perfil';
$tabs = ['perfil', 'seguridad', 'preferencias'];
if (!in_array($tab, $tabs)) $tab = 'perfil';

$msg_ok  = '';
$msg_err = '';

// ── POST: Perfil — ANTES del layout ──────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'perfil') {
    csrf_verify();
    $nombre   = trim($_POST['nombre']   ?? '');
    $apellido = trim($_POST['apellido'] ?? '');
    if (empty($nombre) || empty($apellido)) {
        $msg_err = 'Nombre y apellido son requeridos.';
        $tab = 'perfil';
    } else {
        $pdo->prepare("UPDATE usuarios SET nombre=?, apellido=? WHERE id=?")
            ->execute([$nombre, $apellido, $uid]);
        $_SESSION['usuario']['nombre']   = $nombre;
        $_SESSION['usuario']['apellido'] = $apellido;
        log_actividad('cuenta', 'perfil', 'Perfil actualizado');
        header('Location: ' . APP_URL . '/modules/cuenta/index.php?tab=perfil&ok=perfil');
        exit;
    }
}

// ── POST: Seguridad — ANTES del layout ───────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'seguridad') {
    csrf_verify();
    $pass_actual  = $_POST['pass_actual']  ?? '';
    $pass_nueva   = $_POST['pass_nueva']   ?? '';
    $pass_confirm = $_POST['pass_confirm'] ?? '';

    $row = $pdo->prepare("SELECT password_hash FROM usuarios WHERE id=?");
    $row->execute([$uid]);
    $hash_actual = $row->fetchColumn();

    if (!password_verify($pass_actual, $hash_actual)) {
        $msg_err = 'La contraseña actual es incorrecta.';
        $tab = 'seguridad';
    } elseif (strlen($pass_nueva) < 8) {
        $msg_err = 'La nueva contraseña debe tener al menos 8 caracteres.';
        $tab = 'seguridad';
    } elseif ($pass_nueva !== $pass_confirm) {
        $msg_err = 'Las contraseñas nuevas no coinciden.';
        $tab = 'seguridad';
    } else {
        $pdo->prepare("UPDATE usuarios SET password_hash=? WHERE id=?")
            ->execute([password_hash($pass_nueva, PASSWORD_BCRYPT, ['cost'=>12]), $uid]);
        log_actividad('cuenta', 'seguridad', 'Contraseña cambiada');
        header('Location: ' . APP_URL . '/modules/cuenta/index.php?tab=seguridad&ok=pass');
        exit;
    }
}

// Mensajes de éxito via GET (después del redirect)
if (isset($_GET['ok'])) {
    if ($_GET['ok'] === 'perfil') $msg_ok = 'Perfil actualizado correctamente.';
    if ($_GET['ok'] === 'pass')   $msg_ok = 'Contraseña actualizada correctamente.';
}

$page_title  = 'Mi Cuenta';
$active_menu = 'cuenta';
require_once __DIR__ . '/../../templates/layout.php';

$user = auth_user();

// Recargar datos del usuario frescos de la BD
$u_fresh = $pdo->prepare("SELECT * FROM usuarios WHERE id=?");
$u_fresh->execute([$uid]);
$u_fresh = $u_fresh->fetch();
?>
<style>
.cuenta-wrap { display:grid; grid-template-columns:220px 1fr; gap:28px; max-width:900px; }
.cuenta-nav { background:#fff; border-radius:12px; border:1px solid var(--gris-borde); overflow:hidden; align-self:start; position:sticky; top:80px; }
.cuenta-nav-item {
  display:flex; align-items:center; gap:10px;
  padding:13px 18px; font-size:13px; font-weight:500;
  color:var(--gris-texto); text-decoration:none;
  border-bottom:1px solid #f0f2f8; transition:background 0.1s, color 0.1s;
}
.cuenta-nav-item:last-child { border-bottom:none; }
.cuenta-nav-item:hover { background:var(--azul-light); color:var(--azul); }
.cuenta-nav-item.active { background:var(--azul-light); color:var(--azul); font-weight:600; }
.cuenta-nav-item.active svg { color:var(--azul); }

.cuenta-panel { display:flex; flex-direction:column; gap:18px; }
.cuenta-section { background:#fff; border-radius:12px; border:1px solid var(--gris-borde); overflow:hidden; }
.cuenta-section-head { padding:20px 24px 0; }
.cuenta-section-title { font-family:'Outfit',sans-serif; font-size:17px; font-weight:700; color:var(--azul); }
.cuenta-section-desc  { font-size:13px; color:var(--gris-texto); margin-top:4px; }
.cuenta-section-body  { padding:20px 24px 24px; }
.cuenta-divider { height:1px; background:var(--gris-borde); margin:16px 0; }

.cta-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.field { display:flex; flex-direction:column; gap:5px; }
.field label { font-size:12px; font-weight:600; color:var(--azul); }
.field input, .field select {
  padding:10px 12px; border:1.5px solid var(--gris-borde); border-radius:9px;
  font-size:14px; font-family:'Plus Jakarta Sans',sans-serif; color:var(--gris-dark);
  background:#fafbfe; outline:none; transition:border-color 0.2s, box-shadow 0.2s;
  width:100%;
}
.field input:focus, .field select:focus {
  border-color:var(--azul); background:#fff; box-shadow:0 0 0 3px rgba(26,58,143,0.07);
}
.field .field-note { font-size:11px; color:var(--gris-texto); margin-top:2px; }
.field .pass-wrap { position:relative; }
.field .pass-wrap input { padding-right:38px; }
.field .pass-wrap .eye-btn {
  position:absolute; right:10px; top:50%; transform:translateY(-50%);
  background:none; border:none; cursor:pointer; color:var(--gris-texto);
  display:flex; align-items:center; padding:3px;
}
.field .pass-wrap .eye-btn:hover { color:var(--azul); }

.email-display {
  padding:10px 14px; background:var(--gris-bg); border-radius:9px;
  font-size:14px; color:var(--gris-texto); border:1.5px solid transparent;
}

.alert-ok  { background:#e8f7eb; border:1px solid #b7e4c0; border-left:4px solid var(--verde); color:#1a6630; padding:12px 16px; border-radius:9px; font-size:13px; margin-bottom:16px; display:flex; align-items:center; gap:8px; }
.alert-err { background:#fff5f5; border:1px solid #fecaca; border-left:4px solid #ef4444; color:#c0392b;  padding:12px 16px; border-radius:9px; font-size:13px; margin-bottom:16px; display:flex; align-items:center; gap:8px; }

/* Selector de tema */
.tema-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; }
.tema-option {
  border:2px solid var(--gris-borde); border-radius:12px;
  padding:16px 12px; cursor:pointer; text-align:center;
  transition:border-color 0.15s, background 0.15s;
  background:#fff;
}
.tema-option:hover { border-color:var(--azul); background:var(--azul-light); }
.tema-option.selected { border-color:var(--azul); background:var(--azul-light); }
.tema-option input[type="radio"] { display:none; }
.tema-preview {
  width:100%; height:50px; border-radius:8px; margin-bottom:10px;
  border:1px solid var(--gris-borde); overflow:hidden; position:relative;
  display:flex;
}
.tema-preview-claro  { background:#f0f2f8; }
.tema-preview-oscuro { background:#1a1f36; }
.tema-preview-sistema { background:linear-gradient(to right, #f0f2f8 50%, #1a1f36 50%); }
.tema-preview-bar { position:absolute; top:0; left:0; width:30%; height:100%; background:rgba(26,58,143,0.15); }
.tema-label { font-size:12px; font-weight:600; color:var(--gris-dark); }
.tema-option.selected .tema-label { color:var(--azul); }

@media(max-width:768px) {
  .cuenta-wrap { grid-template-columns:1fr; }
  .cuenta-nav { position:static; display:flex; overflow-x:auto; border-radius:10px; }
  .cuenta-nav-item { border-bottom:none; border-right:1px solid #f0f2f8; white-space:nowrap; }
  .cta-grid { grid-template-columns:1fr; }
  .tema-grid { grid-template-columns:repeat(3,1fr); }
}
</style>

<div class="page-header" style="margin-bottom:20px;">
  <div>
    <h1>Mi Cuenta</h1>
    <p>Gestiona tu perfil, seguridad y preferencias</p>
  </div>
</div>

<?php if ($msg_ok): ?>
<div class="alert-ok" style="max-width:900px;margin-bottom:16px;">
  <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
  <?= htmlspecialchars($msg_ok) ?>
</div>
<?php endif; ?>
<?php if ($msg_err): ?>
<div class="alert-err" style="max-width:900px;margin-bottom:16px;">
  <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
  <?= htmlspecialchars($msg_err) ?>
</div>
<?php endif; ?>

<div class="cuenta-wrap">

  <!-- Nav lateral -->
  <nav class="cuenta-nav">
    <a href="?tab=perfil"        class="cuenta-nav-item <?= $tab==='perfil'       ?'active':'' ?>">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
      Perfil
    </a>
    <a href="?tab=seguridad"     class="cuenta-nav-item <?= $tab==='seguridad'    ?'active':'' ?>">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
      Seguridad
    </a>
    <a href="?tab=preferencias"  class="cuenta-nav-item <?= $tab==='preferencias' ?'active':'' ?>">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93l-1.41 1.41M4.93 4.93l1.41 1.41M12 2v2M12 20v2M2 12h2M20 12h2M19.07 19.07l-1.41-1.41M4.93 19.07l1.41-1.41"/></svg>
      Preferencias
    </a>
  </nav>

  <!-- Paneles -->
  <div class="cuenta-panel">

    <?php if ($tab === 'perfil'): ?>
    <!-- ── PERFIL ── -->
    <div class="cuenta-section">
      <div class="cuenta-section-head">
        <div class="cuenta-section-title">Información personal</div>
        <div class="cuenta-section-desc">Administra tu nombre y datos de contacto.</div>
      </div>
      <div class="cuenta-section-body">
        <form method="POST" novalidate id="form-perfil">
          <?= csrf_field() ?>
          <input type="hidden" name="accion" value="perfil">
          <div class="cta-grid" style="margin-bottom:14px;">
            <div class="field">
              <label>Nombre <span style="color:var(--red)">*</span></label>
              <input type="text" name="nombre" value="<?= htmlspecialchars($u_fresh['nombre']) ?>" required>
            </div>
            <div class="field">
              <label>Apellido <span style="color:var(--red)">*</span></label>
              <input type="text" name="apellido" value="<?= htmlspecialchars($u_fresh['apellido']) ?>" required>
            </div>
          </div>
          <div class="field" style="margin-bottom:20px;">
            <label>Email</label>
            <div class="email-display"><?= htmlspecialchars($u_fresh['email']) ?></div>
            <div class="field-note">El email no se puede cambiar desde aquí. Contacta al administrador si necesitas actualizarlo.</div>
          </div>
          <div class="field" style="margin-bottom:20px;">
            <label>Rol</label>
            <div class="email-display" style="text-transform:capitalize;"><?= str_replace('_',' ', $u_fresh['rol']) ?></div>
          </div>
          <div style="display:flex;justify-content:flex-end;">
            <button type="submit" class="btn btn-primary">
              <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
              Guardar cambios
            </button>
          </div>
        </form>
      </div>
    </div>

    <?php elseif ($tab === 'seguridad'): ?>
    <!-- ── SEGURIDAD ── -->
    <div class="cuenta-section">
      <div class="cuenta-section-head">
        <div class="cuenta-section-title">Seguridad</div>
        <div class="cuenta-section-desc">Cambia tu contraseña de acceso al sistema.</div>
      </div>
      <div class="cuenta-section-body">
        <form method="POST" novalidate id="form-seguridad">
          <?= csrf_field() ?>
          <input type="hidden" name="accion" value="seguridad">
          <div class="field" style="margin-bottom:14px;">
            <label>Contraseña actual <span style="color:var(--red)">*</span></label>
            <div class="pass-wrap">
              <input type="password" name="pass_actual" id="pa" placeholder="Tu contraseña actual" autocomplete="current-password">
              <button type="button" class="eye-btn" onclick="toggleEye('pa','ea')">
                <svg id="ea" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              </button>
            </div>
          </div>
          <div class="cuenta-divider"></div>
          <div class="field" style="margin-bottom:14px;">
            <label>Nueva contraseña <span style="color:var(--red)">*</span></label>
            <div class="pass-wrap">
              <input type="password" name="pass_nueva" id="pn" placeholder="Tu nueva contraseña" autocomplete="new-password">
              <button type="button" class="eye-btn" onclick="toggleEye('pn','en')">
                <svg id="en" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              </button>
            </div>
            <div class="strength-wrap" style="margin-top:6px;">
              <div style="height:4px;background:var(--gris-borde);border-radius:2px;overflow:hidden;"><div id="str-bar" style="height:100%;border-radius:2px;transition:width .3s,background .3s;width:0;"></div></div>
              <div id="str-lbl" style="font-size:11px;margin-top:3px;"></div>
            </div>
            <div class="field-note">Mínimo 8 caracteres. Recomendamos incluir mayúsculas, números y símbolos.</div>
          </div>
          <div class="field" style="margin-bottom:20px;">
            <label>Confirmar contraseña <span style="color:var(--red)">*</span></label>
            <div class="pass-wrap">
              <input type="password" name="pass_confirm" id="pc" placeholder="Confirma tu nueva contraseña" autocomplete="new-password">
              <button type="button" class="eye-btn" onclick="toggleEye('pc','ec')">
                <svg id="ec" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              </button>
            </div>
            <div id="err-confirm" style="font-size:11px;color:var(--red);margin-top:3px;display:none;">Las contraseñas no coinciden.</div>
          </div>
          <div style="display:flex;justify-content:flex-end;">
            <button type="submit" class="btn btn-primary" id="btn-cambiar-pass">Cambiar contraseña</button>
          </div>
        </form>
      </div>
    </div>

    <?php elseif ($tab === 'preferencias'): ?>
    <!-- ── PREFERENCIAS ── -->
    <div class="cuenta-section">
      <div class="cuenta-section-head">
        <div class="cuenta-section-title">Preferencias</div>
        <div class="cuenta-section-desc">Personaliza tu experiencia en el sistema.</div>
      </div>
      <div class="cuenta-section-body">
        <div class="field" style="margin-bottom:8px;"><label>Tema de la interfaz</label></div>
        <div class="tema-grid" id="tema-grid">
          <label class="tema-option" id="tema-claro">
            <input type="radio" name="tema" value="claro">
            <div class="tema-preview tema-preview-claro"><div class="tema-preview-bar"></div></div>
            <div class="tema-label">Claro</div>
          </label>
          <label class="tema-option" id="tema-oscuro">
            <input type="radio" name="tema" value="oscuro">
            <div class="tema-preview tema-preview-oscuro"><div class="tema-preview-bar" style="background:rgba(255,255,255,0.08)"></div></div>
            <div class="tema-label">Oscuro</div>
          </label>
          <label class="tema-option" id="tema-sistema">
            <input type="radio" name="tema" value="sistema">
            <div class="tema-preview tema-preview-sistema"><div class="tema-preview-bar"></div></div>
            <div class="tema-label">Sistema</div>
          </label>
        </div>
        <div style="font-size:12px;color:var(--gris-texto);margin-top:10px;">
          <strong>Sistema</strong> aplica el tema según la configuración de tu dispositivo.
        </div>
      </div>
    </div>
    <?php endif; ?>

  </div><!-- /cuenta-panel -->
</div><!-- /cuenta-wrap -->

<script>
// Toggle mostrar/ocultar contraseña
function toggleEye(inputId, iconId) {
  const inp = document.getElementById(inputId);
  const ico = document.getElementById(iconId);
  const show = inp.type === 'password';
  inp.type = show ? 'text' : 'password';
  ico.innerHTML = show
    ? '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>'
    : '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
}

<?php if ($tab === 'seguridad'): ?>
// Fortaleza de contraseña
document.getElementById('pn')?.addEventListener('input', function() {
  const v = this.value, bar = document.getElementById('str-bar'), lbl = document.getElementById('str-lbl');
  let s = 0;
  if (v.length >= 8)  s++;
  if (v.length >= 12) s++;
  if (/[A-Z]/.test(v)) s++;
  if (/[0-9]/.test(v)) s++;
  if (/[^A-Za-z0-9]/.test(v)) s++;
  const cfg = [
    {w:'0%',   bg:'transparent', t:''},
    {w:'20%',  bg:'#ef4444',     t:'Muy débil'},
    {w:'40%',  bg:'#f59e0b',     t:'Débil'},
    {w:'60%',  bg:'#f59e0b',     t:'Regular'},
    {w:'80%',  bg:'#2DB140',     t:'Buena'},
    {w:'100%', bg:'#2DB140',     t:'Excelente'},
  ][Math.min(s,5)];
  bar.style.width = cfg.w; bar.style.background = cfg.bg;
  lbl.textContent = cfg.t; lbl.style.color = cfg.bg;
});
// Validar coincidencia
document.getElementById('form-seguridad')?.addEventListener('submit', function(e) {
  const pn = document.getElementById('pn').value;
  const pc = document.getElementById('pc').value;
  const err = document.getElementById('err-confirm');
  if (pn !== pc) { err.style.display='block'; document.getElementById('pc').focus(); e.preventDefault(); return; }
  err.style.display = 'none';
  const btn = document.getElementById('btn-cambiar-pass');
  btn.disabled = true; btn.textContent = 'Guardando...';
});
<?php endif; ?>

<?php if ($tab === 'preferencias'): ?>
// Cargar tema guardado y aplicar selección
(function() {
  const saved = localStorage.getItem('ggi_tema') || 'sistema';
  const opt = document.getElementById('tema-' + saved);
  if (opt) { opt.classList.add('selected'); opt.querySelector('input').checked = true; }
})();
document.querySelectorAll('.tema-option').forEach(function(opt) {
  opt.addEventListener('click', function() {
    document.querySelectorAll('.tema-option').forEach(o => o.classList.remove('selected'));
    this.classList.add('selected');
    const val = this.querySelector('input').value;
    localStorage.setItem('ggi_tema', val);
    applyTema(val);
  });
});
<?php endif; ?>

// Validación simple perfil
document.getElementById('form-perfil')?.addEventListener('submit', function(e) {
  const n = this.querySelector('[name="nombre"]').value.trim();
  const a = this.querySelector('[name="apellido"]').value.trim();
  if (!n || !a) { e.preventDefault(); alert('Nombre y apellido son requeridos.'); }
});
</script>

<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
