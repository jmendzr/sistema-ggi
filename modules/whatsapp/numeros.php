<?php
/**
 * modules/whatsapp/numeros.php — Números de WhatsApp conectados.
 *
 * Reemplaza el panel de Kapso: qué números tiene la cuenta, cuál está
 * realmente operativo y cómo está su reputación ante Meta.
 *
 * Nota importante sobre "conectar por QR": la API oficial de Meta NO lo
 * contempla. El QR pertenece a WhatsApp Web y a librerías no oficiales.
 * Con la API oficial un número se agrega desde Meta y se verifica por SMS o
 * llamada; después hay que REGISTRARLO en Cloud API, que es el paso que
 * habitualmente se olvida y deja el número sin poder enviar.
 */
$page_title  = 'Números WhatsApp';
$active_menu = 'wa_numeros';
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../../core/WhatsAppService.php';
session_init();
require_login();
require_role('gerente_general', 'gerente_operaciones');

$pdo     = Database::get();
$numeros = WhatsAppService::listarNumeros();
$error   = $numeros === null ? (WhatsAppService::lastError() ?: 'No se pudo consultar Meta') : null;

// Conversaciones por número, para saber cuál se está usando de verdad
$uso = [];
foreach ($pdo->query("SELECT phone_number_id, COUNT(*) n FROM wa_conversaciones GROUP BY phone_number_id") as $r) {
    $uso[$r['phone_number_id']] = (int) $r['n'];
}

$activo = defined('META_WA_PHONE_NUMBER_ID') ? META_WA_PHONE_NUMBER_ID : '';

$calidad = [
    'GREEN'  => ['Buena',    's-ok'],
    'YELLOW' => ['Media',    's-warn'],
    'RED'    => ['Baja',     's-err'],
    'UNKNOWN'=> ['Sin datos','s-neutral'],
];
?>
<?php include __DIR__ . '/../../templates/layout.php'; ?>

<style>
  .nm-card { background: var(--bg-surface); border: 1px solid var(--brd); border-radius: 12px;
             padding: 16px 18px; margin-bottom: 12px; }
  .nm-card.activo { border-left: 3px solid var(--ac); }
  .nm-r1 { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 6px; }
  .nm-tel { font-size: 1rem; font-weight: 700; color: var(--tx); }
  .nm-nom { font-size: .8rem; color: var(--tx2); }
  .nm-datos { display: flex; gap: 18px; flex-wrap: wrap; margin-top: 12px; }
  .nm-d { font-size: .76rem; color: var(--tx2); }
  .nm-d b { display: block; font-size: .68rem; text-transform: uppercase; letter-spacing: .06em;
            color: var(--tx3); font-weight: 700; margin-bottom: 2px; }
  .nm-chip { font-size: .65rem; font-weight: 700; padding: 2px 9px; border-radius: 999px; }
  .nm-aviso { padding: 13px 15px; border-radius: 10px; font-size: .84rem; line-height: 1.55;
              margin-bottom: 16px; }
  .nm-aviso.info { background: var(--s-info-bg); color: var(--s-info-tx); }
  .nm-aviso.mal  { background: var(--s-err-bg);  color: var(--s-err-tx); }
  .nm-pasos { counter-reset: p; margin: 10px 0 0; padding: 0; list-style: none; }
  .nm-pasos li { counter-increment: p; position: relative; padding: 7px 0 7px 30px; font-size: .83rem;
                 color: var(--tx2); line-height: 1.5; }
  .pl-f { display: block; margin-bottom: 12px; }
  .pl-f span { display: block; font-size: .74rem; font-weight: 600; color: var(--tx2); margin-bottom: 4px; }
  .pl-f input { width: 100%; padding: 9px 12px; font: inherit; font-size: .85rem;
                border: 1px solid var(--brd); border-radius: 9px;
                background: var(--bg-input); color: var(--tx); }
  .pl-hint { font-size: .72rem; color: var(--tx3); margin-top: 4px; }
  .nm-pasos li::before { content: counter(p); position: absolute; left: 0; top: 7px;
                         width: 20px; height: 20px; border-radius: 50%; background: var(--ac-soft);
                         color: var(--ac); font-size: .68rem; font-weight: 700;
                         display: flex; align-items: center; justify-content: center; }
</style>

<div class="page-header pl-head" data-spa-nocache style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:16px">
  <div>
    <h1>Números de WhatsApp</h1>
    <p>Números que el sistema puede usar, con su estado real en Meta</p>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <!-- TEMPORAL (grabación App Review de Meta): lleva a la pantalla en INGLÉS.
         Revertir a "…/conectar.php" (sin ?lang=en) cuando termine la revisión. -->
    <a href="<?= APP_URL ?>/modules/whatsapp/conectar.php?lang=en" class="btn btn-primary">+ Conectar número</a>
    <button type="button" class="btn btn-ghost" id="nm-add">Agregar por identificador</button>
  </div>
</div>

<div id="nm-lista"><div class="nm-aviso info">Consultando Meta…</div></div>

<div class="nm-aviso info" style="margin-top:16px">
  <strong>¿Dónde encuentro el identificador?</strong>
  <p style="margin:6px 0 0">
    En <strong>Meta → WhatsApp → Configuración de la API</strong>, en el desplegable de números:
    cada uno muestra su <em>Identificador del número de teléfono</em>. Es un número largo,
    no el teléfono.
  </p>
  <p style="margin:6px 0 0">
    Si un número pertenece a otra cuenta de WhatsApp Business, además hay que asignar esa
    cuenta al usuario del sistema en <strong>Configuración del negocio → Usuarios del sistema →
    sistemaggi-api → Agregar activos</strong>. Sin eso el token no puede leerlo.
  </p>
</div>

<script>
(function () {
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c];
    });
  }

  var CAL = { GREEN: ['Buena','s-ok'], YELLOW: ['Media','s-warn'],
              RED: ['Baja','s-err'], UNKNOWN: ['Sin datos','s-neutral'] };

  function cargar() {
    fetch('ajax_numeros.php?accion=lista', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j.ok) return;
        document.getElementById('nm-lista').innerHTML = j.items.map(function (n) {
          var q  = CAL[n.calidad || 'UNKNOWN'] || CAL.UNKNOWN;
          var ok = n.estado === 'CONNECTED';
          var sinAcceso = n.estado === 'SIN_ACCESO';
          var esPrincipal = n.phone_number_id === j.principal;

          return '<div class="nm-card ' + (esPrincipal ? 'activo' : '') + '">'
            + '<div class="nm-r1">'
            +   '<span class="nm-tel">' + esc(n.telefono || n.etiqueta || n.phone_number_id) + '</span>'
            +   (esPrincipal ? '<span class="nm-chip" style="background:var(--ac-soft);color:var(--ac)">Principal</span>' : '')
            +   '<span class="nm-chip" style="background:var(--' + (sinAcceso ? 's-warn' : (ok ? 's-ok' : 's-err')) + '-bg);'
            +     'color:var(--' + (sinAcceso ? 's-warn' : (ok ? 's-ok' : 's-err')) + '-tx)">'
            +     (sinAcceso ? 'Sin acceso' : (ok ? 'Conectado' : esc(n.estado || 'Sin registrar'))) + '</span>'
            + '</div>'
            + '<div class="nm-nom">' + esc(n.verified_name || n.etiqueta || '') + '</div>'
            + '<div class="nm-datos">'
            +   '<div class="nm-d"><b>Calidad</b><span class="nm-chip" style="background:var(--' + q[1] + '-bg);color:var(--' + q[1] + '-tx)">' + q[0] + '</span></div>'
            +   '<div class="nm-d"><b>Identificador</b>' + esc(n.phone_number_id) + '</div>'
            +   (n.waba_id ? '<div class="nm-d"><b>Cuenta (WABA)</b>' + esc(n.waba_id) + '</div>' : '')
            +   '<div class="nm-d"><b>Conversaciones</b>' + n.conversaciones + '</div>'
            + '</div>'
            + (sinAcceso
                ? '<div class="nm-aviso mal" style="margin:12px 0 0">El token del sistema no puede leer este número. '
                  + 'Asigna su cuenta de WhatsApp al usuario <b>sistemaggi-api</b> en Configuración del negocio.</div>'
                : (ok ? '' : '<div class="nm-aviso mal" style="margin:12px 0 0">Está en la cuenta pero '
                  + '<b>no registrado en Cloud API</b>: no puede enviar ni recibir.</div>'))
            + '<div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">'
            +   (ok ? '<button type="button" class="btn btn-ghost btn-sm" data-perfil="' + esc(n.phone_number_id) + '" '
                + 'data-nom="' + esc(n.verified_name || n.telefono || '') + '">Editar perfil</button>' : '')
            +   '<button type="button" class="btn btn-ghost btn-sm" data-acc="' + n.id + '" '
            +     'title="Elegir qué ejecutivos ven este número" style="display:inline-flex;align-items:center;gap:6px">'
            +     '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>'
            +     'Quién la ve: ' + (n.con_acceso ? n.con_acceso + ' ejecutivo' + (n.con_acceso === 1 ? '' : 's') : 'todos')
            +   '</button>'
            +   (esPrincipal ? '' : '<button type="button" class="btn btn-ghost btn-sm" '
                + 'data-del="' + n.id + '">Quitar del registro</button>')
            + '</div>'
            + '</div>';
        }).join('') || '<div class="nm-aviso info">Sin números registrados.</div>';
      });
  }

  document.addEventListener('click', function (e) {
    if (e.target.closest('#nm-add')) {
      mantOpen('Agregar número',
          '<label class="pl-f"><span>Identificador del número de teléfono</span>'
        + '<input type="text" id="nm-pid" inputmode="numeric" placeholder="Ej.: 1185249991340361">'
        + '<div class="pl-hint">Lo da Meta en Configuración de la API. No es el teléfono.</div></label>'
        + '<label class="pl-f"><span>Identificador de la cuenta (WABA) — opcional</span>'
        + '<input type="text" id="nm-waba" inputmode="numeric" placeholder="Ej.: 856664760391069"></label>'
        + '<label class="pl-f"><span>Etiqueta interna — opcional</span>'
        + '<input type="text" id="nm-lbl" placeholder="Ej.: Ventas Michael"></label>'
        + '<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">'
        + '<button type="button" class="ggi-btn-confirm-cancel" onclick="ggiModalClose()">Cancelar</button>'
        + '<button type="button" class="ggi-btn-confirm-action" id="nm-ok">Verificar y agregar</button></div>');

      document.getElementById('nm-ok').addEventListener('click', function () {
        var b = this; b.disabled = true; b.textContent = 'Verificando…';
        ggiPost('ajax_numeros.php', {
          accion: 'agregar',
          phone_number_id: document.getElementById('nm-pid').value.trim(),
          waba_id: document.getElementById('nm-waba').value.trim(),
          etiqueta: document.getElementById('nm-lbl').value.trim()
        }).then(function (j) {
          b.disabled = false; b.textContent = 'Verificar y agregar';
          if (!j.ok) return;
          ggiModalClose();
          if (window.showToast) showToast('Número ' + j.numero + ' agregado (' + j.estado + ')', 'ok');
          cargar();
        });
      });
      return;
    }

    // Editar el PERFIL de un número (foto, descripción…) — por número
    var pf = e.target.closest('[data-perfil]');
    if (pf) {
      var pid = pf.dataset.perfil, pnom = pf.dataset.nom || '';
      fetch('ajax_perfil.php?accion=leer&phone_number_id=' + encodeURIComponent(pid),
            { credentials: 'same-origin', cache: 'no-store' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (!j.ok) { if (window.showToast) showToast(j.msg || 'No se pudo leer el perfil', 'error'); return; }
          var p = j.perfil || {}, w = p.websites || [];
          var vert = ['UNDEFINED','OTHER','PROF_SERVICES','EDU','FINANCE','HEALTH','RETAIL','RESTAURANT',
                      'TRAVEL','HOTEL','EVENT_PLAN','GOVT','NONPROFIT','AUTO','BEAUTY','APPAREL','GROCERY','ENTERTAIN'];
          var vlbl = {UNDEFINED:'Sin definir',OTHER:'Otro',PROF_SERVICES:'Servicios profesionales',EDU:'Educación',
                      FINANCE:'Finanzas',HEALTH:'Salud',RETAIL:'Comercio',RESTAURANT:'Restaurante',TRAVEL:'Viajes',
                      HOTEL:'Hotelería',EVENT_PLAN:'Eventos',GOVT:'Gobierno',NONPROFIT:'Sin fines de lucro',
                      AUTO:'Automotor',BEAUTY:'Belleza',APPAREL:'Indumentaria',GROCERY:'Alimentos',ENTERTAIN:'Entretenimiento'};
          function inp(id, lbl, val) { return '<label style="display:block;margin-bottom:10px;font-size:.82rem;color:var(--tx2)">'+lbl
            +'<input id="'+id+'" value="'+esc(val||'')+'" style="width:100%;margin-top:4px;padding:9px 11px;border:1px solid var(--brd);border-radius:8px;background:var(--bg-input);color:var(--tx);font:inherit"></label>'; }
          var opts = vert.map(function (v) { return '<option value="'+v+'"'+((p.vertical||'UNDEFINED')===v?' selected':'')+'>'+vlbl[v]+'</option>'; }).join('');
          mantOpen('Perfil · ' + pnom,
              (p.profile_picture_url ? '<div style="text-align:center;margin-bottom:12px"><img src="'+esc(p.profile_picture_url)+'" style="width:74px;height:74px;border-radius:50%;object-fit:cover;border:1px solid var(--brd)"></div>' : '')
            + '<div style="text-align:center;margin-bottom:14px"><input type="file" id="pf-foto" accept="image/jpeg,image/png" hidden>'
            + '<button type="button" class="btn btn-ghost btn-sm" id="pf-foto-btn">Cambiar foto</button></div>'
            + '<label style="display:block;margin-bottom:10px;font-size:.82rem;color:var(--tx2)">Descripción'
            + '<textarea id="pf-desc" style="width:100%;margin-top:4px;padding:9px 11px;border:1px solid var(--brd);border-radius:8px;background:var(--bg-input);color:var(--tx);font:inherit;min-height:64px">'+esc(p.description||'')+'</textarea></label>'
            + inp('pf-about','Estado / recado (about)', p.about)
            + inp('pf-email','Correo', p.email)
            + '<label style="display:block;margin-bottom:10px;font-size:.82rem;color:var(--tx2)">Rubro'
            + '<select id="pf-vert" style="width:100%;margin-top:4px;padding:9px 11px;border:1px solid var(--brd);border-radius:8px;background:var(--bg-input);color:var(--tx);font:inherit">'+opts+'</select></label>'
            + inp('pf-addr','Dirección', p.address)
            + inp('pf-web1','Sitio web 1', w[0])
            + inp('pf-web2','Sitio web 2', w[1])
            + '<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">'
            + '<button type="button" class="ggi-btn-confirm-cancel" onclick="ggiModalClose()">Cancelar</button>'
            + '<button type="button" class="ggi-btn-confirm-action" id="pf-ok">Guardar</button></div>');

          var v = function (id) { return (document.getElementById(id).value || '').trim(); };
          var file = document.getElementById('pf-foto');
          document.getElementById('pf-foto-btn').addEventListener('click', function () { file.click(); });
          file.addEventListener('change', function () {
            if (!file.files || !file.files[0]) return;
            var fd = new FormData(); fd.append('accion','foto'); fd.append('phone_number_id', pid); fd.append('foto', file.files[0]);
            if (window.showToast) showToast('Subiendo foto…', 'ok');
            ggiPost('ajax_perfil.php', fd).then(function (r) { if (window.showToast) showToast(r.ok ? 'Foto actualizada' : (r.msg||'No se pudo'), r.ok?'ok':'error'); });
          });
          document.getElementById('pf-ok').addEventListener('click', function () {
            ggiPost('ajax_perfil.php', { accion:'guardar', phone_number_id: pid, about:v('pf-about'),
              description:v('pf-desc'), email:v('pf-email'), vertical:v('pf-vert'), address:v('pf-addr'),
              web1:v('pf-web1'), web2:v('pf-web2') }).then(function (r) {
              if (r.ok) { ggiModalClose(); if (window.showToast) showToast('Perfil actualizado', 'ok'); }
              else if (window.showToast) showToast(r.msg || 'No se pudo guardar', 'error');
            });
          });
        });
      return;
    }

    var ac = e.target.closest('[data-acc]');
    if (ac) {
      var nid = ac.dataset.acc;
      fetch('ajax_numeros.php?accion=accesos&id=' + nid, { credentials: 'same-origin', cache: 'no-store' })
        .then(function (r) {
          if (!r.ok) throw new Error('HTTP ' + r.status);   // sesión caída / 403 → no romper en silencio
          return r.json();
        })
        .then(function (j) {
          if (!j.ok) { if (window.showToast) showToast(j.msg || 'No se pudo cargar el acceso', 'error'); return; }
          mantOpen('Quién ve esta bandeja',
              '<p class="pl-hint" style="margin-bottom:12px">Sin nadie marcado, la bandeja la ven '
            + '<b>todos</b> los ejecutivos. Al marcar a alguien, solo esas personas la verán. '
            + 'Gerencia siempre ve todo.</p>'
            + j.usuarios.map(function (u) {
                var on = j.con_acceso.indexOf(parseInt(u.id, 10)) !== -1;
                return '<label style="display:flex;gap:10px;align-items:center;padding:8px 0;font-size:.9rem;cursor:pointer">'
                     + '<input type="checkbox" class="ggi-check" data-u="' + u.id + '"' + (on ? ' checked' : '') + '> '
                     + esc(u.nombre) + (u.rol !== 'ejecutivo_ventas' ? ' <span style="color:var(--tx3)">(gerencia)</span>' : '')
                     + '</label>';
              }).join('')
            + '<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">'
            + '<button type="button" class="ggi-btn-confirm-cancel" onclick="ggiModalClose()">Cancelar</button>'
            + '<button type="button" class="ggi-btn-confirm-action" id="acc-ok">Guardar</button></div>');

          document.getElementById('acc-ok').addEventListener('click', function () {
            var datos = { accion: 'guardar_accesos', id: nid };
            var i = 0;
            document.querySelectorAll('[data-u]').forEach(function (c) {
              if (c.checked) datos['usuarios[' + (i++) + ']'] = c.dataset.u;
            });
            ggiPost('ajax_numeros.php', datos).then(function (r) {
              if (!r.ok) return;
              ggiModalClose();
              if (window.showToast) showToast(r.restringido ? 'Acceso restringido' : 'La bandeja la ven todos', 'ok');
              cargar();
            });
          });
        })
        .catch(function (err) {
          if (window.showToast) showToast('No se pudo abrir "Quién la ve" (' + (err && err.message || 'error') + ')', 'error');
        });
      return;
    }

    var d = e.target.closest('[data-del]');
    if (d) {
      ggiConfirm('Quitar del registro', 'El número dejará de estar disponible en el sistema. '
        + 'No se elimina de Meta ni se borran las conversaciones.', { type: 'warning', confirmText: 'Quitar' })
        .then(function (si) {
          if (!si) return;
          ggiPost('ajax_numeros.php', { accion: 'eliminar', id: d.dataset.del })
            .then(function (j) { if (j.ok) cargar(); });
        });
    }
  });

  cargar();
})();
</script>

<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
