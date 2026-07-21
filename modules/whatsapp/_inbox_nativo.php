<?php
/**
 * _inbox_nativo.php — Inbox multiagente propio (Meta Cloud API nativa).
 *
 * Se incrusta como panel dentro de modules/whatsapp/index.php, en lugar del
 * iframe de Kapso, para el número que ya opera en Meta.
 *
 * Fase 2: solo lectura. La barra de composición está deshabilitada y muestra
 * el estado real de la ventana de 24h de Meta, que es la que decide si se
 * puede escribir texto libre o solo enviar plantillas aprobadas.
 */
?>
<style>
  /* Tres columnas: lista · hilo · contexto. Colapsa a una sola en móvil. */
  .wai { display: grid; grid-template-columns: 320px 1fr 300px; height: 100%; min-height: 0; }
  .wai > * { min-width: 0; min-height: 0; }

  .wai-col      { display: flex; flex-direction: column; border-right: 1px solid var(--border-color, #e8eaf0); }
  .wai-col:last-child { border-right: none; }
  .wai-scroll   { overflow-y: auto; flex: 1; min-height: 0; }

  /* ── Columna 1: lista ── */
  .wai-filtros  { display: flex; gap: 6px; padding: 10px 12px; border-bottom: 1px solid var(--border-color,#e8eaf0); flex-wrap: wrap; }
  .wai-fbtn     { font: inherit; font-size: .74rem; font-weight: 600; padding: 5px 11px; border-radius: 999px;
                  border: 1px solid var(--border-color,#e8eaf0); background: transparent; color: var(--text-muted,#6b7280); cursor: pointer; }
  .wai-fbtn.on  { background: var(--ac, #1d4ed8); border-color: var(--ac, #1d4ed8); color: #fff; }
  .wai-buscar   { padding: 8px 12px; border-bottom: 1px solid var(--border-color,#e8eaf0); }
  .wai-buscar input { width: 100%; padding: 7px 10px; font-size: .82rem; border-radius: 8px;
                  border: 1px solid var(--border-color,#e8eaf0); background: var(--bg-surface,#fff); color: var(--text-color,#1a1d2e); }

  .wai-item     { display: block; width: 100%; text-align: left; padding: 11px 13px; border: none; border-bottom: 1px solid var(--border-color,#e8eaf0);
                  background: transparent; cursor: pointer; font: inherit; border-left: 3px solid transparent; }
  .wai-item:hover  { background: var(--bg-surface2, #f7f8fa); }
  .wai-item.sel    { border-left-color: var(--ac,#1d4ed8); background: var(--bg-surface2,#f7f8fa); }
  .wai-item-top    { display: flex; justify-content: space-between; align-items: baseline; gap: 8px; }
  .wai-item-tit    { font-size: .83rem; font-weight: 700; color: var(--text-color,#1a1d2e); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .wai-item-hora   { font-size: .68rem; color: var(--text-muted,#6b7280); flex-shrink: 0; }
  .wai-item-prev   { font-size: .76rem; color: var(--text-muted,#6b7280); margin-top: 3px;
                     overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .wai-item-meta   { display: flex; gap: 6px; align-items: center; margin-top: 5px; flex-wrap: wrap; }

  .wai-chip     { font-size: .65rem; font-weight: 700; padding: 2px 7px; border-radius: 999px; letter-spacing: .2px; }
  .wai-chip.lib { background: var(--s-warn-bg, #fef3c7); color: var(--s-warn-tx, #92400e); }
  .wai-chip.mia { background: var(--s-ok-bg, #dcfce7);   color: var(--s-ok-tx, #166534); }
  .wai-chip.otro{ background: var(--bg-surface2,#eef0f4); color: var(--text-muted,#6b7280); }
  .wai-chip.est { background: var(--s-err-bg, #fee2e2);  color: var(--s-err-tx, #991b1b); }
  .wai-nuevos   { background: var(--ac,#1d4ed8); color:#fff; font-size:.65rem; font-weight:700;
                  min-width: 18px; height: 18px; border-radius: 999px; display:inline-flex; align-items:center; justify-content:center; padding: 0 5px; }

  /* ── Columna 2: hilo ── */
  .wai-hilo-head { padding: 12px 16px; border-bottom: 1px solid var(--border-color,#e8eaf0); display:flex; justify-content:space-between; align-items:center; gap:12px; }
  .wai-hilo-tit  { font-size: .92rem; font-weight: 700; color: var(--text-color,#1a1d2e); }
  .wai-hilo-sub  { font-size: .72rem; color: var(--text-muted,#6b7280); font-family: monospace; }
  .wai-msgs      { padding: 16px; display: flex; flex-direction: column; gap: 10px; background: var(--bg-surface2, #f7f8fa); }

  .wai-msg       { max-width: 76%; padding: 8px 12px; border-radius: 12px; font-size: .83rem; line-height: 1.45; white-space: pre-wrap; word-break: break-word; }
  .wai-msg.in    { align-self: flex-start; background: var(--card-bg,#fff); border: 1px solid var(--border-color,#e8eaf0); border-bottom-left-radius: 4px; }
  .wai-msg.out   { align-self: flex-end;   background: var(--ac,#1d4ed8); color: #fff; border-bottom-right-radius: 4px; }
  /* Automático del sistema: se distingue del mensaje humano */
  .wai-msg.auto  { background: var(--bg-surface,#eef2ff); color: var(--text-color,#1a1d2e); border: 1px dashed var(--ac,#1d4ed8); }
  .wai-msg-pie   { font-size: .64rem; opacity: .75; margin-top: 5px; display: flex; gap: 6px; justify-content: flex-end; }
  .wai-msg.in .wai-msg-pie { justify-content: flex-start; }
  .wai-msg-err   { font-size: .68rem; margin-top: 4px; padding-top: 4px; border-top: 1px solid rgba(255,255,255,.25); }

  /* Barra de composición: refleja la ventana de 24h de Meta */
  .wai-compose   { border-top: 1px solid var(--border-color,#e8eaf0); padding: 12px 16px; background: var(--card-bg,#fff); }
  .wai-aviso     { display: flex; gap: 10px; align-items: flex-start; padding: 10px 12px; border-radius: 10px; font-size: .78rem; line-height: 1.5; }
  .wai-aviso.cerrada { background: var(--s-warn-bg,#fef3c7); color: var(--s-warn-tx,#92400e); }
  .wai-aviso.fase    { background: var(--bg-surface2,#f1f5f9); color: var(--text-muted,#6b7280); }

  /* ── Columna 3: contexto ── */
  .wai-ctx-sec   { padding: 14px 16px; border-bottom: 1px solid var(--border-color,#e8eaf0); }
  .wai-ctx-tit   { font-size: .66rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: var(--text-muted,#6b7280); margin-bottom: 9px; }
  .wai-ctx-val   { font-size: .84rem; font-weight: 600; color: var(--text-color,#1a1d2e); }
  .wai-ctx-lnk   { font-size: .78rem; color: var(--ac,#1d4ed8); text-decoration: none; display: block; padding: 4px 0; }
  .wai-ctx-lnk:hover { text-decoration: underline; }

  .wai-vacio     { padding: 48px 24px; text-align: center; color: var(--text-muted,#6b7280); font-size: .84rem; }

  @media (max-width: 1100px) { .wai { grid-template-columns: 280px 1fr; } .wai-ctx { display: none; } }
  @media (max-width: 768px) {
    .wai { grid-template-columns: 1fr; }
    .wai-lista { display: flex; }
    .wai-hilo  { display: none; }
    .wai.ver-hilo .wai-lista { display: none; }
    .wai.ver-hilo .wai-hilo  { display: flex; }
  }
</style>

<div class="wai" id="wai">
  <!-- Lista -->
  <div class="wai-col wai-lista">
    <div class="wai-filtros">
      <button type="button" class="wai-fbtn on" data-filtro="todas">Todas <span data-c="todas"></span></button>
      <button type="button" class="wai-fbtn" data-filtro="mias">Mías <span data-c="mias"></span></button>
      <button type="button" class="wai-fbtn" data-filtro="sin_asignar">Sin asignar <span data-c="sin_asignar"></span></button>
    </div>
    <div class="wai-buscar">
      <input type="search" id="wai-q" placeholder="Buscar empresa, teléfono o mensaje…" aria-label="Buscar conversación">
    </div>
    <div class="wai-scroll" id="wai-lista"></div>
  </div>

  <!-- Hilo -->
  <div class="wai-col wai-hilo">
    <div id="wai-hilo-wrap" style="display:flex;flex-direction:column;height:100%;min-height:0;">
      <div class="wai-vacio" id="wai-placeholder">Elige una conversación para ver el hilo.</div>
    </div>
  </div>

  <!-- Contexto -->
  <div class="wai-col wai-ctx" id="wai-ctx"></div>
</div>

<script>
(function () {
  var estado = { filtro: 'todas', q: '', sel: null, timer: null };
  var $ = function (s, r) { return (r || document).querySelector(s); };

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function hora(ts) {
    if (!ts) return '';
    var d = new Date(ts.replace(' ', 'T')), hoy = new Date();
    var mismoDia = d.toDateString() === hoy.toDateString();
    return mismoDia
      ? d.toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit' })
      : d.toLocaleDateString('es-PE', { day: '2-digit', month: '2-digit' });
  }

  // ── Lista ──────────────────────────────────────────────────────────────────
  function cargarLista() {
    var u = 'ajax_inbox.php?accion=lista&filtro=' + encodeURIComponent(estado.filtro)
          + '&q=' + encodeURIComponent(estado.q);

    fetch(u, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) return;

        ['todas', 'mias', 'sin_asignar'].forEach(function (k) {
          var el = $('[data-c="' + k + '"]');
          if (el) el.textContent = d.conteos[k] ? '(' + d.conteos[k] + ')' : '';
        });

        var cont = $('#wai-lista');
        if (!d.items.length) {
          cont.innerHTML = '<div class="wai-vacio">Sin conversaciones.</div>';
          return;
        }

        cont.innerHTML = d.items.map(function (it) {
          var chip = it.asignado_a
            ? (it.es_mia ? '<span class="wai-chip mia">Mía</span>'
                         : '<span class="wai-chip otro">' + esc(it.asignado_nombre || 'Asignada') + '</span>')
            : '<span class="wai-chip lib">Libre</span>';

          // Sin liberación automática, una conversación vieja debe notarse
          var estancada = (it.dias_asignada !== null && it.dias_asignada >= 3)
            ? '<span class="wai-chip est">' + it.dias_asignada + 'd</span>' : '';

          return '<button type="button" class="wai-item' + (estado.sel === it.id ? ' sel' : '') + '" data-id="' + it.id + '">'
               +   '<div class="wai-item-top">'
               +     '<span class="wai-item-tit">' + esc(it.titulo) + '</span>'
               +     '<span class="wai-item-hora">' + hora(it.cuando) + '</span>'
               +   '</div>'
               +   '<div class="wai-item-prev">' + esc(it.preview) + '</div>'
               +   '<div class="wai-item-meta">' + chip + estancada
               +     (it.no_leidos ? '<span class="wai-nuevos">' + it.no_leidos + '</span>' : '')
               +   '</div>'
               + '</button>';
        }).join('');
      })
      .catch(function () {});
  }

  // ── Hilo ───────────────────────────────────────────────────────────────────
  function abrir(id) {
    estado.sel = id;
    document.getElementById('wai').classList.add('ver-hilo');

    fetch('ajax_inbox.php?accion=hilo&id=' + id, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) return;
        pintarHilo(d);
        pintarContexto(d);
        cargarLista();
      })
      .catch(function () {});
  }

  function pintarHilo(d) {
    var c = d.conv;

    var msgs = d.mensajes.map(function (m) {
      var clase = m.dir === 'in' ? 'in' : (m.autor ? 'out' : 'out auto');
      var pie   = [];
      if (m.autor) pie.push(esc(m.autor));
      else if (m.dir === 'out') pie.push('Automático');
      pie.push(hora(m.cuando));
      if (m.estado_txt) pie.push(m.estado_txt);

      var err = m.estado === 'failed' && m.error
        ? '<div class="wai-msg-err">⚠ ' + esc(m.error) + '</div>' : '';

      return '<div class="wai-msg ' + clase + '">' + esc(m.texto || '') + err
           + '<div class="wai-msg-pie">' + pie.join(' · ') + '</div></div>';
    }).join('');

    // La ventana de Meta decide qué se puede mandar. Sin esto, un ejecutivo
    // escribiría mensajes que Meta rechaza creyendo que se enviaron.
    var aviso = c.ventana_abierta
      ? '<div class="wai-aviso fase">Ventana de 24 h abierta. El envío se habilita en la próxima fase.</div>'
      : '<div class="wai-aviso cerrada"><span>⏳</span><span><strong>Ventana de 24 h vencida.</strong> '
        + 'Solo se pueden enviar plantillas aprobadas hasta que el contacto responda.</span></div>';

    $('#wai-hilo-wrap').innerHTML =
        '<div class="wai-hilo-head">'
      +   '<div><div class="wai-hilo-tit">' + esc(c.titulo) + '</div>'
      +   '<div class="wai-hilo-sub">+' + esc(c.telefono) + '</div></div>'
      +   '<div>' + (c.asignado_a
            ? '<span class="wai-chip ' + (c.es_mia ? 'mia' : 'otro') + '">'
              + esc(c.es_mia ? 'Mía' : (c.asignado_nombre || 'Asignada')) + '</span>'
            : '<span class="wai-chip lib">Libre</span>') + '</div>'
      + '</div>'
      + '<div class="wai-scroll wai-msgs" id="wai-msgs">' + msgs + '</div>'
      + '<div class="wai-compose">' + aviso + '</div>';

    var cont = $('#wai-msgs');
    if (cont) cont.scrollTop = cont.scrollHeight;
  }

  function pintarContexto(d) {
    var c = d.conv, x = d.contexto, h = '';

    h += '<div class="wai-ctx-sec"><div class="wai-ctx-tit">Contacto</div>'
       + '<div class="wai-ctx-val">' + esc(c.contacto || '—') + '</div>'
       + '<div class="wai-hilo-sub">+' + esc(c.telefono) + '</div></div>';

    h += '<div class="wai-ctx-sec"><div class="wai-ctx-tit">Empresa</div>';
    h += c.cliente_id
      ? '<a class="wai-ctx-lnk" href="../clientes/ver.php?id=' + c.cliente_id + '">' + esc(c.titulo) + ' ↗</a>'
        + (c.ruc ? '<div class="wai-hilo-sub">RUC ' + esc(c.ruc) + '</div>' : '')
      : '<div class="wai-ctx-val" style="color:var(--text-muted)">Sin empresa vinculada</div>';
    h += '</div>';

    if (x.propuestas && x.propuestas.length) {
      h += '<div class="wai-ctx-sec"><div class="wai-ctx-tit">Propuestas</div>'
         + x.propuestas.map(function (p) {
             return '<div class="wai-ctx-lnk">' + esc(p.numero) + ' · ' + esc(p.estado) + '</div>';
           }).join('') + '</div>';
    }

    if (x.envios && x.envios.length) {
      h += '<div class="wai-ctx-sec"><div class="wai-ctx-tit">Envíos</div>'
         + x.envios.map(function (e) {
             return '<div class="wai-ctx-lnk">' + esc(e.codigo || '—') + '</div>';
           }).join('') + '</div>';
    }

    h += '<div class="wai-ctx-sec"><div class="wai-ctx-tit">Ventana</div>'
       + (c.ventana_abierta
            ? '<span class="wai-chip mia">Abierta</span>'
            : '<span class="wai-chip lib">Vencida</span>')
       + '</div>';

    $('#wai-ctx').innerHTML = h;
  }

  // ── Eventos ────────────────────────────────────────────────────────────────
  document.addEventListener('click', function (e) {
    var f = e.target.closest('.wai-fbtn');
    if (f) {
      document.querySelectorAll('.wai-fbtn').forEach(function (b) { b.classList.remove('on'); });
      f.classList.add('on');
      estado.filtro = f.dataset.filtro;
      cargarLista();
      return;
    }
    var it = e.target.closest('.wai-item');
    if (it) abrir(parseInt(it.dataset.id, 10));
  });

  var q = document.getElementById('wai-q');
  if (q) {
    var t;
    q.addEventListener('input', function () {
      clearTimeout(t);
      t = setTimeout(function () { estado.q = q.value.trim(); cargarLista(); }, 300);
    });
  }

  // Polling: no hay websockets en el monolito. Se pausa con la pestaña oculta
  // para no gastar peticiones cuando nadie está mirando.
  function tick() {
    if (!document.hidden) cargarLista();
  }
  estado.timer = setInterval(tick, 8000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) cargarLista(); });

  cargarLista();
})();
</script>
