<?php
/**
 * conectar.php — Conectar un número de WhatsApp por Embedded Signup / Coexistencia.
 *
 * Abre el flujo oficial de Meta (el del QR) para vincular un número que ya usa
 * la app de WhatsApp Business, sin dejar de usar el celular (coexistencia).
 * Requiere que la app esté Live y con la Revisión de Meta aprobada para números
 * reales; en desarrollo funciona solo con admins/probadores de la app.
 */
// Idioma opcional SOLO para grabar el App Review de Meta (?lang=en): deja esta
// pantalla Y el popup de Meta en inglés, sin afectar a los usuarios reales
// (default: español). Para grabar: abrí .../modules/whatsapp/conectar.php?lang=en
$EN = (($_GET['lang'] ?? '') === 'en');
$page_title  = $EN ? 'Connect a WhatsApp number' : 'Conectar número';
$active_menu = 'wa_conectar';
require_once __DIR__ . '/../../core/helpers.php';
session_init();
require_login();
require_role('gerente_general', 'gerente_operaciones');

$APP_ID    = defined('META_APP_ID') ? META_APP_ID : '';
$CONFIG_ID = '1610326027282775';         // Config de Embedded Signup (WhatsApp)
$GRAPH_V   = defined('META_WA_VERSION') ? META_WA_VERSION : 'v21.0';
$fbLocale  = $EN ? 'en_US' : 'es_LA';     // idioma del popup/login de Meta
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
    <h2><?= $EN ? 'Connect a WhatsApp number' : 'Conectar un número de WhatsApp' ?></h2>
    <?php if ($EN): ?>
    <p>Link a number that already uses the <b>WhatsApp Business app</b> so you can view and reply to it
       from this system, <b>while still using the phone</b> (coexistence). The customer keeps writing to
       the same number as always.</p>
    <ol class="cx-steps">
      <li>Click "Connect number" and follow Meta's flow (Facebook login).</li>
      <li>Scan the <b>QR code</b> with the WhatsApp Business app of that number.</li>
      <li>Done: the number appears as a new inbox inside the system.</li>
    </ol>
    <?php else: ?>
    <p>Vinculá un número que ya usa la <b>app de WhatsApp Business</b> para verlo y responderlo desde
       el sistema, <b>sin dejar de usar el celular</b> (coexistencia). El cliente te sigue escribiendo
       al mismo número de siempre.</p>
    <ol class="cx-steps">
      <li>Tocá "Conectar número" y seguí el flujo de Meta.</li>
      <li>Escaneá el <b>QR</b> con la app de WhatsApp Business del número.</li>
      <li>Listo: el número aparece como bandeja nueva en el inbox.</li>
    </ol>
    <?php endif; ?>

    <button type="button" class="cx-btn" id="cx-lanzar" disabled
            title="<?= $EN ? 'Opens Meta Embedded Signup: log in with Facebook and connect the WhatsApp Business number' : 'Abre el Embedded Signup de Meta: inicia sesión con Facebook y conecta el número' ?>">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a10 10 0 00-8.5 15.3L2 22l4.8-1.5A10 10 0 1012 2zm0 18a8 8 0 01-4.1-1.1l-.3-.2-2.8.9.9-2.8-.2-.3A8 8 0 1112 20z"/></svg>
      <span id="cx-lbl"><?= $EN ? 'Loading…' : 'Cargando…' ?></span>
    </button>

    <div class="cx-aviso">
      <?php if ($EN): ?>⚠️ To connect <b>real</b> numbers, the Meta app must be <b>published (Live)</b> and the <b>App Review approved</b>. While it is still "In review", this button only works for you as an app <b>admin/tester</b>.<?php else: ?>⚠️ Para conectar números <b>reales</b> la app de Meta debe estar <b>publicada (Live)</b> y con la <b>Revisión aprobada</b>. Si todavía está "En revisión", este botón solo funcionará con vos como administrador/probador de la app.<?php endif; ?>
    </div>
    <div class="cx-res" id="cx-res"></div>
  </div>
</div>

<script>
  window.fbAsyncInit = function () {
    FB.init({ appId: '<?= htmlspecialchars($APP_ID) ?>', autoLogAppEvents: true, xfbml: false, version: '<?= htmlspecialchars($GRAPH_V) ?>' });
    var b = document.getElementById('cx-lanzar');
    b.disabled = false; document.getElementById('cx-lbl').textContent = '<?= $EN ? 'Connect number' : 'Conectar número' ?>';
  };
  (function (d, s, id) {
    var js, fjs = d.getElementsByTagName(s)[0];
    if (d.getElementById(id)) return;
    js = d.createElement(s); js.id = id; js.src = 'https://connect.facebook.net/<?= $fbLocale ?>/sdk.js';
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
        mostrar('<?= $EN ? 'The connection was cancelled or the flow was not completed.' : 'Se canceló la conexión o no se completó el flujo.' ?>', false);
        return;
      }
      if (!_sesion.phone_number_id || !_sesion.waba_id) {
        mostrar('<?= $EN ? 'The flow finished but Meta did not return the number. Retry, or check that the App Review is approved.' : 'El flujo terminó pero Meta no devolvió el número. Reintentá o revisá que la Revisión esté aprobada.' ?>', false);
        return;
      }
      mostrar('<?= $EN ? 'Connecting the number to the system…' : 'Conectando el número al sistema…' ?>', true);
      ggiPost('ajax_conectar.php', {
        accion: 'finalizar',
        code: response.authResponse.code,
        phone_number_id: _sesion.phone_number_id,
        waba_id: _sesion.waba_id
      }).then(function (j) {
        if (j.ok) {
          mostrar((<?= $EN ? "'✅ Number connected: '" : "'✅ Número conectado: '" ?>) + (j.numero || '') + (j.ya ? (<?= $EN ? "' (already registered)'" : "' (ya estaba registrado)'" ?>) : '')
                + (<?= $EN ? "'. It will appear as an inbox.'" : "'. Aparecerá como bandeja en el inbox.'" ?>)
                + (j.msg_extra || ''), (!j.msg_extra));
        } else {
          mostrar((<?= $EN ? "'Could not connect: '" : "'No se pudo conectar: '" ?>) + (j.msg || 'error'), false);
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
