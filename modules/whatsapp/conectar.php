<?php
/**
 * conectar.php — Conectar un número de WhatsApp por Embedded Signup / Coexistencia.
 *
 * Abre el flujo oficial de Meta (el del QR) para vincular un número que ya usa
 * la app de WhatsApp Business, sin dejar de usar el celular (coexistencia).
 * Requiere que la app esté Live y con la Revisión de Meta aprobada para números
 * reales; en desarrollo funciona solo con admins/probadores de la app.
 */
$page_title  = 'Conectar número';
$active_menu = 'wa_conectar';
require_once __DIR__ . '/../../core/helpers.php';
session_init();
require_login();
require_role('gerente_general', 'gerente_operaciones');

$APP_ID    = defined('META_APP_ID') ? META_APP_ID : '';
$CONFIG_ID = '1610326027282775';         // Config de Embedded Signup (WhatsApp)
$GRAPH_V   = defined('META_WA_VERSION') ? META_WA_VERSION : 'v21.0';
?>
<?php include __DIR__ . '/../../templates/layout.php'; ?>

<style>
  .cx-wrap { max-width: 620px; margin: 0 auto; }
  .cx-card { background: var(--bg-surface); border: 1px solid var(--brd); border-radius: 14px; padding: 24px; }
  .cx-card h2 { font-size: 1.05rem; font-weight: 700; margin-bottom: 6px; }
  .cx-card p { font-size: .88rem; color: var(--tx2); line-height: 1.55; margin-bottom: 10px; }
  .cx-steps { font-size: .85rem; color: var(--tx2); line-height: 1.6; margin: 10px 0 18px; padding-left: 18px; }
  .cx-btn { background: #1877F2; color: #fff; border: none; border-radius: 10px; padding: 13px 22px;
            font: inherit; font-size: .95rem; font-weight: 700; cursor: pointer; display: inline-flex;
            align-items: center; gap: 10px; }
  .cx-btn:hover { background: #1462c8; }
  .cx-btn:disabled { opacity: .6; cursor: default; }
  .cx-aviso { margin-top: 16px; font-size: .82rem; padding: 12px 14px; border-radius: 10px;
              background: var(--s-warn-bg); color: var(--s-warn-tx); line-height: 1.5; }
  .cx-res { margin-top: 16px; font-size: .9rem; padding: 12px 14px; border-radius: 10px; display: none; }
  .cx-res.ok { background: var(--s-ok-bg); color: var(--s-ok-tx); display: block; }
  .cx-res.err { background: var(--s-err-bg); color: var(--s-err-tx); display: block; }
</style>

<div class="cx-wrap">
  <div class="cx-card">
    <h2>Conectar un número de WhatsApp</h2>
    <p>Vinculá un número que ya usa la <b>app de WhatsApp Business</b> para verlo y responderlo desde
       el sistema, <b>sin dejar de usar el celular</b> (coexistencia). El cliente te sigue escribiendo
       al mismo número de siempre.</p>
    <ol class="cx-steps">
      <li>Tocá "Conectar número" y seguí el flujo de Meta.</li>
      <li>Escaneá el <b>QR</b> con la app de WhatsApp Business del número.</li>
      <li>Listo: el número aparece como bandeja nueva en el inbox.</li>
    </ol>

    <button type="button" class="cx-btn" id="cx-lanzar" disabled>
      <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a10 10 0 00-8.5 15.3L2 22l4.8-1.5A10 10 0 1012 2zm0 18a8 8 0 01-4.1-1.1l-.3-.2-2.8.9.9-2.8-.2-.3A8 8 0 1112 20z"/></svg>
      <span id="cx-lbl">Cargando…</span>
    </button>

    <div class="cx-aviso">
      ⚠️ Para conectar números <b>reales</b> la app de Meta debe estar <b>publicada (Live)</b> y con la
      <b>Revisión aprobada</b>. Si todavía está "En revisión", este botón solo funcionará con vos como
      administrador/probador de la app.
    </div>
    <div class="cx-res" id="cx-res"></div>
  </div>
</div>

<script>
  window.fbAsyncInit = function () {
    FB.init({ appId: '<?= htmlspecialchars($APP_ID) ?>', autoLogAppEvents: true, xfbml: false, version: '<?= htmlspecialchars($GRAPH_V) ?>' });
    var b = document.getElementById('cx-lanzar');
    b.disabled = false; document.getElementById('cx-lbl').textContent = 'Conectar número';
  };
  (function (d, s, id) {
    var js, fjs = d.getElementsByTagName(s)[0];
    if (d.getElementById(id)) return;
    js = d.createElement(s); js.id = id; js.src = 'https://connect.facebook.net/es_LA/sdk.js';
    fjs.parentNode.insertBefore(js, fjs);
  }(document, 'script', 'facebook-jssdk'));

  // Info de sesión que Meta envía durante el Embedded Signup (WABA + número)
  var _sesion = {};
  window.addEventListener('message', function (event) {
    if (!/facebook\.com$/.test(event.origin)) return;
    try {
      var data = JSON.parse(event.data);
      if (data.type === 'WA_EMBEDDED_SIGNUP' && data.data) {
        if (data.data.phone_number_id) _sesion.phone_number_id = data.data.phone_number_id;
        if (data.data.waba_id)         _sesion.waba_id         = data.data.waba_id;
      }
    } catch (e) {}
  });

  function mostrar(txt, ok) {
    var r = document.getElementById('cx-res');
    r.className = 'cx-res ' + (ok ? 'ok' : 'err');
    r.textContent = txt;
  }

  document.getElementById('cx-lanzar').addEventListener('click', function () {
    _sesion = {};
    FB.login(function (response) {
      if (!(response.authResponse && response.authResponse.code)) {
        mostrar('Se canceló la conexión o no se completó el flujo.', false);
        return;
      }
      if (!_sesion.phone_number_id || !_sesion.waba_id) {
        mostrar('El flujo terminó pero Meta no devolvió el número. Reintentá o revisá que la Revisión esté aprobada.', false);
        return;
      }
      mostrar('Conectando el número al sistema…', true);
      ggiPost('ajax_conectar.php', {
        accion: 'finalizar',
        code: response.authResponse.code,
        phone_number_id: _sesion.phone_number_id,
        waba_id: _sesion.waba_id
      }).then(function (j) {
        if (j.ok) {
          mostrar('✅ Número conectado: ' + (j.numero || '') + (j.ya ? ' (ya estaba registrado)' : '')
                + '. Aparecerá como bandeja en el inbox.', true);
        } else {
          mostrar('No se pudo conectar: ' + (j.msg || 'error'), false);
        }
      });
    }, {
      config_id: '<?= $CONFIG_ID ?>',
      response_type: 'code',
      override_default_response_type: true,
      extras: {
        setup: {},
        // Coexistencia: vincular un número que ya usa la app de WhatsApp Business.
        featureType: 'whatsapp_business_app_onboarding',
        sessionInfoVersion: '3'
      }
    });
  });
</script>

<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
