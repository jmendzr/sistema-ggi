<?php
/**
 * perfil.php — Perfil de negocio de WhatsApp (foto, descripción, dirección…).
 *
 * Se edita con el token actual (scope whatsapp_business_management). La foto va
 * por el resumable upload de Meta (ver WhatsAppService::perfilSubirFoto).
 */
$page_title  = 'Perfil de WhatsApp';
$active_menu = 'wa_perfil';
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/WhatsAppService.php';
session_init();
require_login();
require_role('gerente_general', 'gerente_operaciones');

$perfil = WhatsAppService::perfilLeer();
$error  = $perfil === null ? (WhatsAppService::lastError() ?: 'No se pudo consultar Meta') : null;
$perfil = $perfil ?: [];

$verticales = [
    'UNDEFINED' => 'Sin definir', 'OTHER' => 'Otro', 'PROF_SERVICES' => 'Servicios profesionales',
    'EDU' => 'Educación', 'FINANCE' => 'Finanzas', 'HEALTH' => 'Salud', 'RETAIL' => 'Comercio',
    'RESTAURANT' => 'Restaurante', 'TRAVEL' => 'Viajes', 'HOTEL' => 'Hotelería', 'EVENT_PLAN' => 'Eventos',
    'GOVT' => 'Gobierno', 'NONPROFIT' => 'Sin fines de lucro', 'AUTO' => 'Automotor', 'BEAUTY' => 'Belleza',
    'APPAREL' => 'Indumentaria', 'GROCERY' => 'Alimentos', 'ENTERTAIN' => 'Entretenimiento',
];
$webs = $perfil['websites'] ?? [];
?>
<?php include __DIR__ . '/../../templates/layout.php'; ?>

<style>
  .wp-wrap { max-width: 640px; margin: 0 auto; }
  .wp-card { background: var(--bg-surface); border: 1px solid var(--brd); border-radius: 14px; padding: 22px; margin-bottom: 16px; }
  .wp-foto-row { display: flex; align-items: center; gap: 18px; }
  .wp-foto { width: 92px; height: 92px; border-radius: 50%; object-fit: cover; border: 1px solid var(--brd); background: var(--bg-surface2); flex-shrink: 0; }
  .wp-foto-ph { display: flex; align-items: center; justify-content: center; color: var(--tx3); font-size: 2rem; }
  .wp-field { margin-bottom: 15px; }
  .wp-field label { display: block; font-size: .8rem; font-weight: 600; color: var(--tx2); margin-bottom: 5px; }
  .wp-field input, .wp-field textarea, .wp-field select {
    width: 100%; padding: 10px 12px; font: inherit; font-size: .88rem;
    border: 1px solid var(--brd); border-radius: 9px; background: var(--bg-input); color: var(--tx);
  }
  .wp-field textarea { resize: vertical; min-height: 74px; }
  .wp-hint { font-size: .72rem; color: var(--tx3); margin-top: 4px; }
  .wp-2col { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
  @media (max-width: 560px) { .wp-2col { grid-template-columns: 1fr; } }
  .wp-title { font-size: .95rem; font-weight: 700; color: var(--tx); margin-bottom: 14px; }
</style>

<div class="wp-wrap">
  <?php if ($error): ?>
    <div class="wp-card" style="border-color:var(--s-err);color:var(--s-err-tx);background:var(--s-err-bg)">
      No se pudo cargar el perfil desde Meta: <?= htmlspecialchars($error) ?>
    </div>
  <?php endif; ?>

  <!-- Foto -->
  <div class="wp-card">
    <div class="wp-title">Foto de perfil</div>
    <div class="wp-foto-row">
      <?php if (!empty($perfil['profile_picture_url'])): ?>
        <img class="wp-foto" id="wp-foto" src="<?= htmlspecialchars($perfil['profile_picture_url']) ?>" alt="Foto de perfil">
      <?php else: ?>
        <div class="wp-foto wp-foto-ph" id="wp-foto">GGI</div>
      <?php endif; ?>
      <div>
        <input type="file" id="wp-foto-file" accept="image/jpeg,image/png" hidden>
        <button type="button" class="btn btn-outline" id="wp-foto-btn">Cambiar foto</button>
        <div class="wp-hint">JPG o PNG, cuadrada, hasta 5 MB.</div>
      </div>
    </div>
  </div>

  <!-- Datos -->
  <form class="wp-card" id="wp-form">
    <div class="wp-title">Información de la empresa</div>

    <div class="wp-field">
      <label for="wp-about">Estado / recado (about)</label>
      <input type="text" id="wp-about" maxlength="139" value="<?= htmlspecialchars($perfil['about'] ?? '') ?>">
      <div class="wp-hint">Texto corto bajo el nombre. Máx. 139 caracteres.</div>
    </div>

    <div class="wp-field">
      <label for="wp-description">Descripción</label>
      <textarea id="wp-description" maxlength="512"><?= htmlspecialchars($perfil['description'] ?? '') ?></textarea>
      <div class="wp-hint">Máx. 512 caracteres.</div>
    </div>

    <div class="wp-2col">
      <div class="wp-field">
        <label for="wp-email">Correo</label>
        <input type="email" id="wp-email" maxlength="128" value="<?= htmlspecialchars($perfil['email'] ?? '') ?>">
      </div>
      <div class="wp-field">
        <label for="wp-vertical">Rubro</label>
        <select id="wp-vertical">
          <?php $vsel = $perfil['vertical'] ?? 'UNDEFINED'; foreach ($verticales as $k => $v): ?>
            <option value="<?= $k ?>" <?= $vsel === $k ? 'selected' : '' ?>><?= htmlspecialchars($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="wp-field">
      <label for="wp-address">Dirección</label>
      <input type="text" id="wp-address" maxlength="256" value="<?= htmlspecialchars($perfil['address'] ?? '') ?>">
    </div>

    <div class="wp-2col">
      <div class="wp-field">
        <label for="wp-web1">Sitio web 1</label>
        <input type="text" id="wp-web1" value="<?= htmlspecialchars($webs[0] ?? '') ?>">
      </div>
      <div class="wp-field">
        <label for="wp-web2">Sitio web 2</label>
        <input type="text" id="wp-web2" value="<?= htmlspecialchars($webs[1] ?? '') ?>">
      </div>
    </div>

    <button type="submit" class="btn btn-primary" id="wp-guardar">Guardar cambios</button>
  </form>
</div>

<script>
(function () {
  var AJAX = 'ajax_perfil.php';

  // Guardar datos
  document.getElementById('wp-form').addEventListener('submit', function (e) {
    e.preventDefault();
    var btn = document.getElementById('wp-guardar');
    btn.disabled = true; btn.textContent = 'Guardando…';
    ggiPost(AJAX, {
      accion: 'guardar',
      about: val('wp-about'), description: val('wp-description'),
      email: val('wp-email'), vertical: val('wp-vertical'),
      address: val('wp-address'), web1: val('wp-web1'), web2: val('wp-web2')
    }).then(function (j) {
      btn.disabled = false; btn.textContent = 'Guardar cambios';
      if (window.showToast) showToast(j.ok ? 'Perfil actualizado' : (j.msg || 'No se pudo guardar'), j.ok ? 'ok' : 'error');
    });
  });

  // Cambiar foto
  var file = document.getElementById('wp-foto-file');
  document.getElementById('wp-foto-btn').addEventListener('click', function () { file.click(); });
  file.addEventListener('change', function () {
    if (!file.files || !file.files[0]) return;
    var fd = new FormData();
    fd.append('accion', 'foto');
    fd.append('foto', file.files[0]);
    if (window.showToast) showToast('Subiendo foto…', 'ok');
    ggiPost(AJAX, fd).then(function (j) {
      if (j.ok) {
        var img = document.getElementById('wp-foto');
        var url = URL.createObjectURL(file.files[0]);
        if (img.tagName === 'IMG') img.src = url;
        if (window.showToast) showToast('Foto actualizada', 'ok');
      } else if (window.showToast) showToast(j.msg || 'No se pudo cambiar la foto', 'error');
    });
  });

  function val(id) { return (document.getElementById(id).value || '').trim(); }
})();
</script>

<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
