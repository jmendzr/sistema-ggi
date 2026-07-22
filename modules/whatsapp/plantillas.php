<?php
/**
 * modules/whatsapp/plantillas.php — Mantenimiento de plantillas de WhatsApp.
 *
 * Muestra en un solo lugar las dos realidades que pueden desincronizarse: qué
 * plantilla usa cada evento del sistema y en qué estado está esa plantilla en
 * Meta. Cuando divergen, las notificaciones fallan sin avisar.
 */
$page_title  = 'Plantillas WhatsApp';
$active_menu = 'wa_plantillas';
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../../core/WaPlantillas.php';
session_init();
require_login();
require_role('gerente_general', 'gerente_operaciones');

$pdo  = Database::get();
$diag = WaPlantillas::diagnostico($pdo, null);   // sin llamar a Meta al cargar
?>
<?php include __DIR__ . '/../../templates/layout.php'; ?>

<style>
  .pl-head { display: flex; align-items: center; justify-content: space-between;
             gap: 12px; flex-wrap: wrap; margin-bottom: 16px; }
  .pl-aviso { display: flex; gap: 10px; align-items: flex-start; padding: 12px 14px;
              border-radius: 10px; font-size: .84rem; line-height: 1.5; margin-bottom: 16px; }
  .pl-aviso.mal { background: var(--s-err-bg); color: var(--s-err-tx); }
  .pl-aviso.ok  { background: var(--s-ok-bg);  color: var(--s-ok-tx); }

  .pl-card { background: var(--bg-surface); border: 1px solid var(--brd);
             border-radius: 12px; padding: 14px 16px; margin-bottom: 10px; }
  .pl-card.urgente { border-left: 3px solid var(--s-err); }
  .pl-r1 { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
  .pl-ev { font-size: .88rem; font-weight: 700; color: var(--tx); }
  .pl-tn { font-size: .76rem; color: var(--tx2); font-family: ui-monospace, monospace; }
  .pl-body { font-size: .8rem; color: var(--tx2); margin-top: 8px; line-height: 1.5;
             background: var(--bg-surface2); border-radius: 8px; padding: 9px 11px; white-space: pre-wrap; }
  .pl-prob { display: flex; align-items: center; gap: 6px; font-size: .78rem;
             margin-top: 8px; padding: 7px 10px; border-radius: 8px; }
  .pl-prob.grave { background: var(--s-err-bg); color: var(--s-err-tx); }
  .pl-prob.aviso { background: var(--s-warn-bg); color: var(--s-warn-tx); }
  .pl-acc { display: flex; gap: 6px; margin-top: 10px; flex-wrap: wrap; }

  .chip-est { font-size: .65rem; font-weight: 700; padding: 2px 9px; border-radius: 999px; }
  .est-APPROVED { background: var(--s-ok-bg);   color: var(--s-ok-tx); }
  .est-REJECTED { background: var(--s-err-bg);  color: var(--s-err-tx); }
  .est-PENDING  { background: var(--s-warn-bg); color: var(--s-warn-tx); }
  .est-PAUSED, .est-DISABLED { background: var(--s-err-bg); color: var(--s-err-tx); }
  .est-none     { background: var(--s-neutral-bg); color: var(--s-neutral-tx); }
  .chip-cat { font-size: .63rem; font-weight: 600; padding: 2px 8px; border-radius: 999px;
              background: var(--s-neutral-bg); color: var(--s-neutral-tx); }
  .pl-inact { font-size: .63rem; font-weight: 600; padding: 2px 8px; border-radius: 999px;
              background: var(--bg-hover); color: var(--tx3); }

  .pl-sec-t { font-size: .68rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase;
              color: var(--tx3); margin: 22px 0 10px; }
  .pl-f { display: block; margin-bottom: 12px; }
  .pl-f span { display: block; font-size: .74rem; font-weight: 600; color: var(--tx2); margin-bottom: 4px; }
  .pl-f input, .pl-f select, .pl-f textarea {
    width: 100%; padding: 9px 12px; font: inherit; font-size: .85rem;
    border: 1px solid var(--brd); border-radius: 9px; background: var(--bg-input); color: var(--tx);
  }
  .pl-f textarea { min-height: 96px; resize: vertical; line-height: 1.5; }
  .pl-hint { font-size: .72rem; color: var(--tx3); margin-top: 4px; }
</style>

<div class="page-header pl-head">
  <div>
    <h1>Plantillas de WhatsApp</h1>
    <p>Estado real de cada notificación automática en tu cuenta de Meta</p>
  </div>
  <div style="display:flex;gap:8px">
    <button type="button" class="btn btn-ghost" id="pl-sync">Sincronizar con Meta</button>
    <button type="button" class="btn btn-primary" id="pl-nueva">Nueva plantilla</button>
  </div>
</div>

<div id="pl-resumen"></div>
<div id="pl-lista">
  <div class="pl-aviso ok">Pulsa <b>Sincronizar con Meta</b> para ver el estado actualizado.</div>
</div>

<script>
(function () {
  var $ = function (s) { return document.querySelector(s); };
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c];
    });
  }

  var EVENTOS = <?= json_encode(array_column($diag['filas'], 'event_code')) ?>;

  function pintar(d) {
    var rotas = d.filas.filter(function (f) { return f.urgente; }).length;

    $('#pl-resumen').innerHTML = rotas
      ? '<div class="pl-aviso mal"><b>' + rotas + '</b> notificación' + (rotas > 1 ? 'es activas' : ' activa')
        + ' no se está enviando por un problema con su plantilla.</div>'
      : '<div class="pl-aviso ok">Todas las notificaciones activas tienen su plantilla aprobada.</div>';

    var html = d.filas.map(function (f) {
      var est = f.estado || 'none';
      var probs = f.problemas.map(function (p) {
        return '<div class="pl-prob ' + p[0] + '">' + esc(p[1]) + '</div>';
      }).join('');

      return '<div class="pl-card' + (f.urgente ? ' urgente' : '') + '">'
        + '<div class="pl-r1">'
        +   '<span class="pl-ev">' + esc(f.event_code.replace(/_/g, ' ')) + '</span>'
        +   '<span class="chip-est est-' + esc(est) + '">' + esc(f.estado || 'sin sincronizar') + '</span>'
        +   (f.categoria ? '<span class="chip-cat">' + esc(f.categoria) + '</span>' : '')
        +   (f.activo ? '' : '<span class="pl-inact">inactiva</span>')
        + '</div>'
        + '<div class="pl-tn">' + esc(f.template_name) + ' · ' + esc(f.idioma)
        +   ' · ' + f.claves.length + ' variable' + (f.claves.length === 1 ? '' : 's')
        +   (f.claves.length ? ' (' + esc(f.claves.join(', ')) + ')' : '') + '</div>'
        + (f.cuerpo ? '<div class="pl-body">' + esc(f.cuerpo) + '</div>' : '')
        + probs
        + '<div class="pl-acc">'
        +   '<button type="button" class="btn btn-ghost btn-sm" data-vinc="' + esc(f.event_code) + '">Editar vínculo</button>'
        +   (f.problemas.length && !f.cuerpo
              ? '<button type="button" class="btn btn-primary btn-sm" data-crear="' + esc(f.event_code) + '">Crear en Meta</button>'
              : '')
        + '</div>'
        + '</div>';
    }).join('');

    if (d.huerfanas && d.huerfanas.length) {
      html += '<div class="pl-sec-t">En Meta pero sin usar en el sistema</div>'
        + d.huerfanas.map(function (h) {
            return '<div class="pl-card"><div class="pl-r1">'
              + '<span class="pl-ev">' + esc(h.nombre) + '</span>'
              + '<span class="chip-est est-' + esc(h.status) + '">' + esc(h.status) + '</span>'
              + (h.categoria ? '<span class="chip-cat">' + esc(h.categoria) + '</span>' : '')
              + '</div>'
              + (h.body ? '<div class="pl-body">' + esc(h.body) + '</div>' : '')
              + '<div class="pl-acc"><button type="button" class="btn btn-ghost btn-sm" '
              + 'data-borrar="' + esc(h.nombre) + '">Eliminar de Meta</button></div></div>';
          }).join('');
    }

    $('#pl-lista').innerHTML = html;
  }

  function sincronizar() {
    var b = $('#pl-sync');
    b.disabled = true; b.textContent = 'Sincronizando…';
    fetch('ajax_plantillas.php?accion=sincronizar', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        b.disabled = false; b.textContent = 'Sincronizar con Meta';
        if (!j.ok) { if (window.showToast) showToast(j.msg || 'Error', 'error'); return; }
        pintar(j);
        if (window.showToast) showToast(j.total_meta + ' plantillas leídas de Meta', 'ok');
      })
      .catch(function () { b.disabled = false; b.textContent = 'Sincronizar con Meta'; });
  }

  // ── Crear ──
  function formCrear(evento) {
    var opts = EVENTOS.map(function (e) {
      return '<option value="' + esc(e) + '"' + (e === evento ? ' selected' : '') + '>' + esc(e) + '</option>';
    }).join('');

    mantOpen('Nueva plantilla en Meta',
        '<label class="pl-f"><span>Nombre técnico</span>'
      +   '<input type="text" id="pl-n" placeholder="ggi_mi_plantilla" maxlength="512">'
      +   '<div class="pl-hint">Solo minúsculas, números y guion bajo.</div></label>'
      + '<label class="pl-f"><span>Categoría</span><select id="pl-c">'
      +   '<option value="UTILITY">Utilidad — avisos de un trámite en curso</option>'
      +   '<option value="MARKETING">Marketing — promociones, novedades, felicitaciones</option>'
      + '</select>'
      +   '<div class="pl-hint">La causa más común de rechazo es elegir Utilidad para un texto '
      +   'promocional. Si el mensaje felicita, ofrece o invita, es Marketing.</div></label>'
      + '<label class="pl-f"><span>Idioma</span><select id="pl-i">'
      +   '<option value="es">Español (es)</option><option value="es_PE">Español Perú (es_PE)</option>'
      + '</select></label>'
      + '<label class="pl-f"><span>Mensaje</span>'
      +   '<textarea id="pl-b" maxlength="1024" placeholder="Hola {{1}}, su certificación ya está lista."></textarea>'
      +   '<div class="pl-hint">Usa {{1}}, {{2}}… correlativos. No puede empezar ni terminar con una variable.</div></label>'
      + '<div id="pl-ejs"></div>'
      + '<label class="pl-f"><span>Vincular al evento (opcional)</span><select id="pl-e">'
      +   '<option value="">— No vincular —</option>' + opts + '</select>'
      +   '<div class="pl-hint">Queda inactiva hasta que Meta la apruebe y la actives.</div></label>'
      + '<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">'
      + '<button type="button" class="ggi-btn-confirm-cancel" onclick="ggiModalClose()">Cancelar</button>'
      + '<button type="button" class="ggi-btn-confirm-action" id="pl-ok">Enviar a revisión</button></div>');

    var body = document.getElementById('pl-b');
    body.addEventListener('input', function () {
      // Meta exige un ejemplo por variable: se piden en el momento
      var n = (body.value.match(/\{\{(\d+)\}\}/g) || [])
                .filter(function (v, i, a) { return a.indexOf(v) === i; }).length;
      var c = document.getElementById('pl-ejs');
      var actual = c.querySelectorAll('input').length;
      if (n === actual) return;
      c.innerHTML = '';
      for (var i = 0; i < n; i++) {
        c.innerHTML += '<label class="pl-f"><span>Ejemplo para {{' + (i + 1) + '}}</span>'
                     + '<input type="text" data-ej="' + i + '" placeholder="Valor de muestra"></label>';
      }
      if (n) c.insertAdjacentHTML('afterbegin',
        '<div class="pl-hint" style="margin-bottom:8px">Meta exige un valor de ejemplo por variable.</div>');
    });

    document.getElementById('pl-ok').addEventListener('click', function () {
      var btn = this;
      var datos = {
        accion: 'crear',
        nombre: document.getElementById('pl-n').value.trim().toLowerCase(),
        categoria: document.getElementById('pl-c').value,
        idioma: document.getElementById('pl-i').value,
        cuerpo: body.value,
        event_code: document.getElementById('pl-e').value
      };
      document.querySelectorAll('[data-ej]').forEach(function (i) {
        datos['ejemplos[' + i.dataset.ej + ']'] = i.value.trim();
      });

      btn.disabled = true; btn.textContent = 'Enviando…';
      ggiPost('ajax_plantillas.php', datos).then(function (j) {
        btn.disabled = false; btn.textContent = 'Enviar a revisión';
        if (!j.ok) return;
        ggiModalClose();
        if (window.showToast) showToast('Enviada a revisión de Meta (' + (j.estado || 'PENDING') + ')', 'ok');
        sincronizar();
      });
    });
  }

  // ── Vincular ──
  function formVincular(evento, d) {
    var f = d.filas.filter(function (x) { return x.event_code === evento; })[0];
    if (!f) return;

    mantOpen('Vínculo · ' + evento,
        '<label class="pl-f"><span>Plantilla en Meta</span>'
      +   '<input type="text" id="v-n" value="' + esc(f.template_name) + '"></label>'
      + '<label class="pl-f"><span>Idioma</span>'
      +   '<input type="text" id="v-i" value="' + esc(f.idioma) + '"></label>'
      + '<label class="pl-f"><span>Variables, en orden ({{1}}, {{2}}…)</span>'
      +   '<input type="text" id="v-k" value="' + esc(f.claves.join(', ')) + '" '
      +   'placeholder="razon_social, cot_numero">'
      +   '<div class="pl-hint">El orden importa: define qué dato va en cada variable.</div></label>'
      + '<label class="pl-f"><span>Clave del botón URL (opcional)</span>'
      +   '<input type="text" id="v-btn" value="' + esc(f.boton_url || '') + '"></label>'
      + '<label style="display:flex;gap:8px;align-items:center;font-size:.85rem;margin:14px 0">'
      +   '<input type="checkbox" id="v-a"' + (f.activo ? ' checked' : '') + '> Activa (se usa para enviar)</label>'
      + '<div style="display:flex;gap:8px;justify-content:flex-end">'
      + '<button type="button" class="ggi-btn-confirm-cancel" onclick="ggiModalClose()">Cancelar</button>'
      + '<button type="button" class="ggi-btn-confirm-action" id="v-ok">Guardar</button></div>');

    document.getElementById('v-ok').addEventListener('click', function () {
      var datos = {
        accion: 'vincular', event_code: evento,
        template_name: document.getElementById('v-n').value.trim().toLowerCase(),
        idioma: document.getElementById('v-i').value.trim(),
        url_button_key: document.getElementById('v-btn').value.trim(),
        activo: document.getElementById('v-a').checked ? '1' : ''
      };
      document.getElementById('v-k').value.split(',').forEach(function (k, i) {
        if (k.trim()) datos['claves[' + i + ']'] = k.trim();
      });

      ggiPost('ajax_plantillas.php', datos).then(function (j) {
        if (j.confirmar) {
          ggiConfirm('Activar de todos modos', j.msg, { type: 'warning', confirmText: 'Activar' })
            .then(function (si) {
              if (!si) return;
              datos.forzar = '1';
              ggiPost('ajax_plantillas.php', datos).then(function (r) {
                if (r.ok) { ggiModalClose(); sincronizar(); }
              });
            });
          return;
        }
        if (j.ok) { ggiModalClose(); sincronizar(); }
      });
    });
  }

  var ultimo = null;
  var _pintar = pintar;
  pintar = function (d) { ultimo = d; _pintar(d); };

  document.addEventListener('click', function (e) {
    if (e.target.closest('#pl-sync'))  { sincronizar(); return; }
    if (e.target.closest('#pl-nueva')) { formCrear(''); return; }

    var c = e.target.closest('[data-crear]');
    if (c) { formCrear(c.dataset.crear); return; }

    var v = e.target.closest('[data-vinc]');
    if (v && ultimo) { formVincular(v.dataset.vinc, ultimo); return; }

    var b = e.target.closest('[data-borrar]');
    if (b) {
      var nom = b.dataset.borrar;
      ggiConfirm('Eliminar de Meta', 'Se eliminará <b>' + esc(nom) + '</b> de tu cuenta de WhatsApp. '
               + 'No se puede deshacer.', { type: 'danger', confirmText: 'Eliminar' })
        .then(function (si) {
          if (!si) return;
          ggiPost('ajax_plantillas.php', { accion: 'eliminar', nombre: nom }).then(function (j) {
            if (j.confirmar) {
              ggiConfirm('Eliminar igualmente', j.msg, { type: 'danger', confirmText: 'Eliminar' })
                .then(function (si2) {
                  if (!si2) return;
                  ggiPost('ajax_plantillas.php', { accion: 'eliminar', nombre: nom, forzar: '1' })
                    .then(function (r) { if (r.ok) sincronizar(); });
                });
              return;
            }
            if (j.ok) { if (window.showToast) showToast('Eliminada de Meta', 'ok'); sincronizar(); }
          });
        });
      return;
    }
  });

  sincronizar();   // estado real al entrar
})();
</script>

<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
