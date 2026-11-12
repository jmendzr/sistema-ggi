<?php
/**
 * modules/whatsapp/campanas.php — Campañas masivas de WhatsApp (Meta nativo).
 * Segmenta clientes (propuestas enviadas sin respuesta, desistidas, sin respuesta WA),
 * envía plantillas aprobadas por Meta en lote y muestra métricas por campaña.
 * Solo gerencia.
 */
$page_title  = 'Campañas WhatsApp';
$active_menu = 'wa_campanas';
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();
require_role('gerente_general', 'gerente_operaciones','gerente_comercial');

$pdo = Database::get();
$campanas = $pdo->query("
    SELECT c.*, u.nombre AS creador_nombre, u.apellido AS creador_apellido
    FROM whatsapp_campanas c
    LEFT JOIN usuarios u ON u.id = c.creado_por
    ORDER BY c.id DESC
    LIMIT 100
")->fetchAll();

$seg_labels = [
    'enviadas_sin_respuesta' => 'Propuestas enviadas sin respuesta',
    'desistidas'             => 'Propuestas desistidas',
    'sin_respuesta_wa'       => 'Sin respuesta en WhatsApp',
    'lista'                  => 'Lista manual / CSV',
];
$est_cfg = [
    'encolada'   => ['label' => 'Encolada',   'cls' => 'badge-gray'],
    'enviando'   => ['label' => 'Enviando',   'cls' => 'badge-blue'],
    'finalizada' => ['label' => 'Completada', 'cls' => 'badge-green'],
    'pausada'    => ['label' => 'Pausada',    'cls' => 'badge-amber'],
    'cancelada'  => ['label' => 'Cancelada',  'cls' => 'badge-red'],
    // Compat. con estados antiguos.
    'draft'      => ['label' => 'Borrador',   'cls' => 'badge-gray'],
    'sending'    => ['label' => 'Enviando',   'cls' => 'badge-blue'],
    'completed'  => ['label' => 'Completada', 'cls' => 'badge-green'],
    'failed'     => ['label' => 'Fallida',    'cls' => 'badge-red'],
];
require_once __DIR__ . '/../../templates/layout.php';
?>
<style>
  .camp-toolbar { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:16px; flex-wrap:wrap; }
  .camp-aviso { display:flex; gap:10px; align-items:flex-start; background:var(--s-warn-bg); border:1px solid var(--s-warn-brd); border-radius:10px; padding:12px 16px; font-size:12.5px; color:var(--s-warn-tx,var(--gris-dark)); margin-bottom:16px; line-height:1.5; }
  .table-wrap { background:var(--bg-surface); border-radius:12px; border:1px solid var(--gris-borde); overflow:hidden; }
  .camp-stats { display:inline-flex; gap:6px; flex-wrap:wrap; }
  .camp-stat { display:inline-flex; align-items:center; gap:4px; font-size:11px; font-weight:600; padding:2px 8px; border-radius:100px; background:var(--gris-bg); border:1px solid var(--gris-borde); color:var(--gris-texto); white-space:nowrap; }
  /* Modal nueva campaña */
  .nc-step { border:1px solid var(--gris-borde); border-radius:10px; padding:14px 16px; margin-bottom:12px; }
  .nc-step-title { font-size:12px; font-weight:700; color:var(--gris-dark); margin-bottom:10px; display:flex; align-items:center; gap:7px; }
  .nc-step-num { width:20px; height:20px; border-radius:50%; background:var(--ac,#1d4ed8); color:#fff; display:inline-flex; align-items:center; justify-content:center; font-size:11px; font-weight:700; flex-shrink:0; }
  .nc-row { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
  .nc-row select, .nc-row input[type=number], .nc-row input[type=text] { padding:8px 10px; border:1.5px solid var(--gris-borde); border-radius:8px; font-size:12.5px; font-family:'Inter',sans-serif; background:var(--gris-card); color:var(--gris-dark); outline:none; }
  .nc-row select:focus, .nc-row input:focus { border-color:var(--azul); }
  .nc-preview { max-height:180px; overflow:auto; border:1px solid var(--gris-borde); border-radius:8px; margin-top:10px; font-size:12px; }
  .nc-preview table { width:100%; border-collapse:collapse; }
  .nc-preview td, .nc-preview th { padding:5px 10px; border-bottom:1px solid var(--gris-borde); text-align:left; white-space:nowrap; }
  .nc-preview th { position:sticky; top:0; background:var(--gris-bg); font-size:10.5px; text-transform:uppercase; letter-spacing:.3px; color:var(--gris-texto); }
  .nc-body-preview { background:var(--gris-bg); border:1px dashed var(--gris-borde); border-radius:8px; padding:10px 12px; font-size:12px; color:var(--gris-dark); white-space:pre-wrap; margin-top:8px; line-height:1.5; }
  .nc-param-row { display:flex; align-items:center; gap:8px; margin-top:8px; font-size:12.5px; }
  .nc-param-row code { background:var(--gris-bg); border-radius:5px; padding:2px 7px; font-weight:700; }
  .nc-counts { font-size:12px; color:var(--gris-texto); margin-top:8px; }
  .nc-counts strong { color:var(--gris-dark); }
</style>

<div class="camp-toolbar">
  <div style="font-size:13px;color:var(--gris-texto);">
    Envíos masivos con plantillas aprobadas por Meta. Se despachan por lotes.
  </div>
  <div style="display:flex;gap:8px;align-items:center;">
    <a class="btn btn-outline btn-sm" href="<?= APP_URL ?>/modules/whatsapp/interesados.php">🔥 Interesados (clics)</a>
    <button type="button" class="btn btn-primary btn-sm" onclick="ncAbrir()">
      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Nueva campaña
    </button>
  </div>
</div>

<div class="camp-aviso">
  <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="flex-shrink:0;margin-top:1px;"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
  <span><strong>Usa las campañas con moderación.</strong> Mensajes masivos a contactos que no responden aumentan bloqueos y reportes, bajan la calidad del número en Meta y pueden restringir también las notificaciones operativas (envíos, facturas). Empieza con lotes pequeños y monitorea la tasa de respuesta.</span>
</div>

<div class="table-wrap">
  <table class="data-table">
    <thead>
      <tr>
        <th>Campaña</th>
        <th>Segmento</th>
        <th>Plantilla</th>
        <th style="text-align:center;">Destinatarios</th>
        <th>Resultados</th>
        <th>Estado</th>
        <th>Fecha</th>
        <th style="text-align:center;">Acciones</th>
      </tr>
    </thead>
    <tbody>
    <?php if (empty($campanas)): ?>
      <tr><td colspan="8">
        <div class="empty-state">
          <svg width="32" height="32" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path d="M22 2 11 13"/><path d="M22 2 15 22l-4-9-9-4 20-7z"/></svg>
          <div>Aún no hay campañas. Crea la primera con "Nueva campaña".</div>
        </div>
      </td></tr>
    <?php else: foreach ($campanas as $c):
      $ec    = $est_cfg[$c['estado']] ?? ['label' => ucfirst($c['estado']), 'cls' => 'badge-gray'];
      $stats = $c['stats_json'] ? (json_decode($c['stats_json'], true) ?: []) : [];
    ?>
      <tr data-campana-id="<?= $c['id'] ?>" data-estado="<?= sanitize($c['estado']) ?>">
        <td style="font-weight:600;color:var(--gris-dark);"><?= sanitize($c['nombre']) ?></td>
        <td style="font-size:12px;"><?= sanitize($seg_labels[$c['segmento']] ?? $c['segmento']) ?></td>
        <td><code style="font-size:11px;"><?= sanitize($c['template_name']) ?></code></td>
        <td style="text-align:center;font-weight:700;color:var(--gris-dark);">
          <?= (int)$c['total_destinatarios'] ?>
          <?php if ($c['excluidos']): ?><span title="Excluidos por teléfono inválido/duplicado" style="font-size:11px;color:var(--s-warn);font-weight:600;"> (+<?= (int)$c['excluidos'] ?> excl.)</span><?php endif; ?>
        </td>
        <td class="camp-stats-cell">
          <?php if ($stats): ?>
          <span class="camp-stats">
            <span class="camp-stat" title="Entregados">📬 <?= (int)($stats['delivered'] ?? 0) ?></span>
            <span class="camp-stat" title="Leídos">👁 <?= (int)($stats['read'] ?? 0) ?></span>
            <span class="camp-stat" title="Respondieron / tasa de respuesta">💬 <?= (int)($stats['responded'] ?? 0) ?><?php if (isset($stats['rate'])): ?> (<?= (int)$stats['rate'] ?>%)<?php endif; ?></span>
            <?php if (!empty($stats['failed'])): ?><span class="camp-stat" style="color:var(--s-err);" title="Fallidos">✕ <?= (int)$stats['failed'] ?></span><?php endif; ?>
            <?php if (!empty($stats['pending'])): ?><span class="camp-stat" title="Pendientes de envío">⏳ <?= (int)$stats['pending'] ?></span><?php endif; ?>
          </span>
          <?php else: ?><span style="font-size:11px;color:var(--gris-texto);">Sin datos — sincroniza</span><?php endif; ?>
        </td>
        <td><span class="<?= $ec['cls'] ?>" style="padding:3px 10px;border-radius:100px;font-size:11px;font-weight:600;"><?= $ec['label'] ?></span></td>
        <td style="font-size:12px;color:var(--gris-texto);white-space:nowrap;" title="<?= sanitize(trim(($c['creador_nombre'] ?? '') . ' ' . ($c['creador_apellido'] ?? ''))) ?>">
          <?= date('d/m/Y H:i', strtotime($c['created_at'])) ?>
        </td>
        <td style="text-align:center;">
          <button type="button" class="btn btn-outline btn-sm" style="padding:3px 10px;font-size:11px;" onclick="campSync(<?= $c['id'] ?>, this)">Sincronizar</button>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<!-- Modal: Nueva campaña -->
<div class="ggi-modal-overlay" id="modal-nc" onclick="if(event.target===this)ncCerrar()">
  <div class="ggi-modal-box" style="max-width:640px;">
    <div class="ggi-modal-header">
      <h3 class="ggi-modal-title">
        <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M22 2 11 13"/><path d="M22 2 15 22l-4-9-9-4 20-7z"/></svg>
        Nueva campaña de WhatsApp
      </h3>
      <button type="button" class="ggi-modal-close" onclick="ncCerrar()">✕</button>
    </div>
    <div class="ggi-modal-body" style="max-height:70vh;overflow:auto;">

      <div class="nc-step">
        <div class="nc-step-title"><span class="nc-step-num">1</span> ¿A quién? — Segmento</div>
        <div class="nc-row">
          <select id="nc-segmento" style="flex:1;min-width:230px;" onchange="ncSegCambio()">
            <option value="enviadas_sin_respuesta">Propuestas enviadas sin respuesta</option>
            <option value="desistidas">Propuestas desistidas</option>
            <option value="sin_respuesta_wa">Clientes sin respuesta en WhatsApp</option>
            <option value="lista">Lista manual / CSV (pegar números)</option>
          </select>
          <label id="nc-dias-lbl" style="font-size:12px;color:var(--gris-texto);display:inline-flex;align-items:center;gap:6px;">
            <span id="nc-dias-txt">con más de</span>
            <input type="number" id="nc-dias" value="7" min="0" max="365" style="width:70px;">
            días
          </label>
          <button type="button" class="btn btn-outline btn-sm" onclick="ncCargarSegmento()">Cargar destinatarios</button>
        </div>
        <div id="nc-lista-box" style="display:none;margin-top:10px;">
          <textarea id="nc-lista" rows="5" placeholder="Un número por línea. Opcional: número,empresa&#10;51999888777,ACME S.A.C.&#10;51988777666" style="width:100%;padding:9px 11px;border:1.5px solid var(--gris-borde);border-radius:8px;font-size:12.5px;font-family:'Inter',sans-serif;background:var(--gris-card);color:var(--gris-dark);outline:none;resize:vertical;"></textarea>
          <div style="margin-top:6px;font-size:11.5px;color:var(--gris-texto);">
            Pega los números (uno por línea; puedes añadir la empresa separada por coma).
            <label style="color:var(--azul);cursor:pointer;font-weight:600;"> · Cargar un .csv<input type="file" id="nc-csv" accept=".csv,.txt" style="display:none;" onchange="ncCsvCargar(this)"></label>
          </div>
        </div>
        <div class="nc-counts" id="nc-counts" style="display:none;"></div>
        <div class="nc-preview" id="nc-preview" style="display:none;"></div>
      </div>

      <div class="nc-step">
        <div class="nc-step-title"><span class="nc-step-num">2</span> ¿Qué mensaje? — Plantilla aprobada</div>
        <div class="nc-row">
          <select id="nc-template" style="flex:1;min-width:280px;" onchange="ncTplCambio()">
            <option value="">Cargando plantillas…</option>
          </select>
        </div>
        <div class="nc-body-preview" id="nc-body" style="display:none;"></div>
        <div id="nc-params"></div>
      </div>

      <div class="nc-step">
        <div class="nc-step-title"><span class="nc-step-num">3</span> Nombre de la campaña</div>
        <div class="nc-row">
          <input type="text" id="nc-nombre" placeholder="Se genera automático si lo dejas vacío" style="flex:1;min-width:280px;" maxlength="120">
        </div>
      </div>

      <div id="nc-err" style="display:none;color:var(--s-err,#dc2626);font-size:12.5px;font-weight:600;margin-top:4px;"></div>
    </div>
    <div class="ggi-modal-footer">
      <button type="button" class="ggi-btn-confirm-cancel" onclick="ncCerrar()">Cancelar</button>
      <button type="button" class="ggi-btn-confirm-action" id="nc-enviar" onclick="ncEnviar()">Enviar campaña</button>
    </div>
  </div>
</div>

<script>
const CAMP_URL  = '<?= APP_URL ?>/modules/whatsapp/ajax_campana.php';
const CAMP_TOK  = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
let _ncTemplates = [];
let _ncSegOk = false;

async function campPost(data) {
  // ggiPost: helper global. silent porque este módulo interpreta la respuesta
  // y muestra sus propios mensajes.
  return await ggiPost(CAMP_URL, data, { silent: true });
}

// ── Sincronizar métricas de una campaña ──
async function campSync(id, btn) {
  window.ggiBtnLoading && window.ggiBtnLoading(btn, true);   // U-05: estado de carga global
  try {
    const d = await campPost({ accion: 'sync', campana_id: id });
    if (d.ok) location.reload();
    else if (typeof showToast === 'function') showToast(d.msg || 'Error al sincronizar.', 'error');
  } catch (e) { if (typeof showToast === 'function') showToast('Error de conexión.', 'error'); }
  finally { window.ggiBtnLoading && window.ggiBtnLoading(btn, false); }
}

// ── Modal nueva campaña ──
function ncAbrir() {
  _ncSegOk = false;
  document.getElementById('nc-counts').style.display = 'none';
  document.getElementById('nc-preview').style.display = 'none';
  document.getElementById('nc-err').style.display = 'none';
  document.getElementById('nc-nombre').value = '';
  document.getElementById('modal-nc').classList.add('open');
  if (!_ncTemplates.length) ncCargarTemplates();
  ncSegCambio();
}
function ncCerrar() { document.getElementById('modal-nc').classList.remove('open'); }

function ncSegCambio() {
  const seg = document.getElementById('nc-segmento').value;
  const txt = document.getElementById('nc-dias-txt');
  const inp = document.getElementById('nc-dias');
  const esLista = seg === 'lista';
  // La lista manual no usa el filtro de días; muestra su cuadro de texto.
  document.getElementById('nc-dias-lbl').style.display = esLista ? 'none' : 'inline-flex';
  document.getElementById('nc-lista-box').style.display = esLista ? 'block' : 'none';
  if (seg === 'enviadas_sin_respuesta') { txt.textContent = 'con más de';        inp.value = inp.value || 7; }
  if (seg === 'desistidas')             { txt.textContent = 'de los últimos';    inp.value = 90; }
  if (seg === 'sin_respuesta_wa')       { txt.textContent = 'enviado hace máx.'; inp.value = 30; }
  _ncSegOk = false;
  document.getElementById('nc-counts').style.display = 'none';
  document.getElementById('nc-preview').style.display = 'none';
}

// Carga un .csv/.txt en el textarea de la lista manual (cliente, sin subir nada al servidor).
function ncCsvCargar(input) {
  const f = input.files && input.files[0];
  if (!f) return;
  const rd = new FileReader();
  rd.onload = () => {
    const ta = document.getElementById('nc-lista');
    ta.value = (ta.value.trim() ? ta.value.trim() + '\n' : '') + (rd.result || '');
    if (typeof showToast === 'function') showToast('Archivo cargado. Revisa y pulsa "Cargar destinatarios".', 'ok');
  };
  rd.readAsText(f);
  input.value = '';
}

async function ncCargarSegmento() {
  const counts = document.getElementById('nc-counts');
  const prev   = document.getElementById('nc-preview');
  counts.style.display = 'block';
  counts.innerHTML = 'Cargando…';
  try {
    const d = await campPost({
      accion: 'segmento',
      segmento: document.getElementById('nc-segmento').value,
      dias: document.getElementById('nc-dias').value,
      lista: document.getElementById('nc-lista').value
    });
    if (!d.ok) { counts.innerHTML = '<span style="color:var(--s-err);">' + (d.msg || 'Error') + '</span>'; return; }
    _ncSegOk = d.validos > 0;
    counts.innerHTML = '<strong>' + d.validos + '</strong> destinatario(s) con teléfono válido' +
      (d.invalidos ? ' · <span style="color:var(--s-warn);">' + d.invalidos + ' excluido(s) por teléfono inválido</span>' : '');
    if (d.rows.length) {
      let h = '<table><thead><tr><th>Empresa</th><th>Propuesta</th><th>Teléfono</th></tr></thead><tbody>';
      d.rows.forEach(r => {
        h += '<tr' + (r.valido ? '' : ' style="opacity:.45;text-decoration:line-through;"') + '><td>' +
          esc(r.empresa) + '</td><td>' + esc(r.numero || '—') + '</td><td>' + esc(r.telefono) + '</td></tr>';
      });
      prev.innerHTML = h + '</tbody></table>';
      prev.style.display = 'block';
    } else prev.style.display = 'none';
  } catch (e) { counts.innerHTML = '<span style="color:var(--s-err);">Error de conexión.</span>'; }
}

async function ncCargarTemplates() {
  const sel = document.getElementById('nc-template');
  try {
    const d = await campPost({ accion: 'templates' });
    if (!d.ok) { sel.innerHTML = '<option value="">Error al cargar plantillas</option>'; return; }
    _ncTemplates = d.templates;
    sel.innerHTML = '<option value="">— Elige una plantilla —</option>' + d.templates.map(t =>
      '<option value="' + t.id + '">' + esc(t.name) + ' · ' + t.category + ' (' + t.language + ')</option>'
    ).join('');
  } catch (e) { sel.innerHTML = '<option value="">Error de conexión</option>'; }
}

function ncTplCambio() {
  const id  = document.getElementById('nc-template').value;
  const t   = _ncTemplates.find(x => x.id === id);
  const bod = document.getElementById('nc-body');
  const box = document.getElementById('nc-params');
  box.innerHTML = '';
  if (!t) { bod.style.display = 'none'; return; }
  bod.textContent = t.body_text || '(sin cuerpo)';
  bod.style.display = 'block';
  (t.params || []).forEach((p, i) => {
    const row = document.createElement('div');
    row.className = 'nc-param-row';
    row.innerHTML = '<code>{{' + esc(p) + '}}</code> = ' +
      '<select data-param-idx="' + i + '" onchange="this.nextElementSibling.style.display = this.value === \'fijo\' ? \'inline-block\' : \'none\'" ' +
      'style="padding:6px 8px;border:1.5px solid var(--gris-borde);border-radius:7px;font-size:12px;background:var(--gris-card);color:var(--gris-dark);">' +
      '<option value="empresa">Razón social / empresa</option>' +
      '<option value="numero">N° de propuesta</option>' +
      '<option value="monto">Monto de la propuesta</option>' +
      '<option value="fijo">Texto fijo…</option></select>' +
      '<input type="text" placeholder="texto fijo" style="display:none;flex:1;padding:6px 8px;border:1.5px solid var(--gris-borde);border-radius:7px;font-size:12px;background:var(--gris-card);color:var(--gris-dark);">';
    box.appendChild(row);
  });
}

function ncEnviar() {
  const err = document.getElementById('nc-err');
  err.style.display = 'none';
  const tplId = document.getElementById('nc-template').value;
  const t     = _ncTemplates.find(x => x.id === tplId);
  if (!_ncSegOk) { err.textContent = 'Primero carga los destinatarios del segmento (paso 1).'; err.style.display = 'block'; return; }
  if (!t)        { err.textContent = 'Elige una plantilla (paso 2).'; err.style.display = 'block'; return; }

  const map = [];
  let falta = false;
  document.querySelectorAll('#nc-params .nc-param-row').forEach(row => {
    const src = row.querySelector('select').value;
    const val = row.querySelector('input').value.trim();
    if (src === 'fijo' && !val) falta = true;
    map.push({ src, valor: val });
  });
  if (falta) { err.textContent = 'Completa el texto fijo de los parámetros marcados.'; err.style.display = 'block'; return; }

  GGIConfirm.show({
    title: '¿Enviar la campaña ahora?',
    message: 'Se enviará la plantilla "' + t.name + '" a todos los destinatarios del segmento. Esta acción no se puede deshacer.',
    icon: 'warning',
    confirmColor: '#1d4ed8',
    onConfirm: async () => {
      const btn = document.getElementById('nc-enviar');
      btn.disabled = true; btn.textContent = 'Enviando…';
      try {
        const d = await campPost({
          accion: 'enviar',
          segmento: document.getElementById('nc-segmento').value,
          dias: document.getElementById('nc-dias').value,
          lista: document.getElementById('nc-lista').value,
          template_id: t.id,
          template_name: t.name,
          template_lang: t.language,   // es / es_PE: sin esto Meta no encuentra la plantilla
          param_names: JSON.stringify(t.params || []),
          params_map: JSON.stringify(map),
          nombre: document.getElementById('nc-nombre').value.trim()
        });
        if (d.ok) {
          ncCerrar();
          if (typeof showToast === 'function') showToast(d.msg, 'ok', 6000);
          setTimeout(() => location.reload(), 1200);
        } else {
          err.textContent = d.msg || 'Error al enviar.';
          err.style.display = 'block';
        }
      } catch (e) { err.textContent = 'Error de conexión.'; err.style.display = 'block'; }
      btn.disabled = false; btn.textContent = 'Enviar campaña';
    }
  });
}

function esc(s) { const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; }

// ── Progreso en vivo: refresca las campañas en curso sin recargar la página ──
const CAMP_EST = {
  encolada:  { l:'Encolada',   c:'badge-gray'  },
  enviando:  { l:'Enviando',   c:'badge-blue'  },
  finalizada:{ l:'Completada', c:'badge-green' },
  pausada:   { l:'Pausada',    c:'badge-amber' },
  cancelada: { l:'Cancelada',  c:'badge-red'   },
};
function campPinta(tr, d) {
  const s = d.stats || {};
  const cell = tr.querySelector('.camp-stats-cell');
  if (cell) {
    let h = '<span class="camp-stats">' +
      '<span class="camp-stat" title="Entregados">📬 ' + (s.delivered || 0) + '</span>' +
      '<span class="camp-stat" title="Leídos">👁 ' + (s.read || 0) + '</span>' +
      '<span class="camp-stat" title="Respondieron / tasa de respuesta">💬 ' + (s.responded || 0) + ' (' + (s.rate || 0) + '%)</span>';
    if (s.failed)  h += '<span class="camp-stat" style="color:var(--s-err);" title="Fallidos">✕ ' + s.failed + '</span>';
    if (s.pending) h += '<span class="camp-stat" title="Pendientes de envío">⏳ ' + s.pending + '</span>';
    cell.innerHTML = h + '</span>';
  }
  const est = CAMP_EST[d.estado];
  if (est) {
    const badge = tr.querySelector('td:nth-child(6) span');
    if (badge) { badge.className = est.c; badge.textContent = est.l; }
    tr.dataset.estado = d.estado;
  }
}
document.addEventListener('DOMContentLoaded', () => {
  const enCurso = () => [...document.querySelectorAll('tr[data-estado]')]
    .filter(r => ['encolada', 'enviando'].includes(r.dataset.estado));
  if (!enCurso().length) return;
  const tick = async () => {
    const filas = enCurso();
    for (const r of filas) {
      try { const d = await campPost({ accion: 'sync', campana_id: r.dataset.campanaId }); if (d && d.ok) campPinta(r, d); } catch (e) {}
    }
    if (enCurso().length) setTimeout(tick, 7000);
  };
  setTimeout(tick, 5000);
});
</script>

<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
