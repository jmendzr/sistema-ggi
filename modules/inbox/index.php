<?php
/**
 * modules/inbox/index.php — Inbox multiagente de WhatsApp.
 *
 * Módulo propio (no depende de Kapso). Varios ejecutivos comparten un número;
 * cada uno se asigna conversaciones y queda bloqueada para el resto.
 *
 * Colores: SOLO tokens del design system (--tx, --brd, --ac, --s-*). Nada de
 * hex sueltos, o el tema oscuro se rompe.
 */
$page_title  = 'Inbox';
$active_menu = 'inbox';
require_once __DIR__ . '/../../core/helpers.php';
session_init();
require_login();
require_role('gerente_general', 'gerente_operaciones', 'ejecutivo_ventas');
?>
<?php include __DIR__ . '/../../templates/layout.php'; ?>

<style>
  .ib {
    display: grid;
    grid-template-columns: 340px minmax(0, 1fr) 296px;
    height: calc(100dvh - var(--header-h, 60px) - var(--ann-h, 0px) - 24px);
    min-height: 420px;
    background: var(--bg-surface);
    border: 1px solid var(--brd);
    border-radius: 14px;
    overflow: hidden;
  }
  .ib > * { min-width: 0; min-height: 0; }
  .ib-col { display: flex; flex-direction: column; border-right: 1px solid var(--brd); }
  .ib-col:last-child { border-right: none; }
  .ib-scroll { overflow-y: auto; flex: 1; min-height: 0; }

  /* ── Avatares con iniciales ── */
  .ib-av {
    width: 38px; height: 38px; border-radius: 50%; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    font-size: .74rem; font-weight: 700; color: #fff; letter-spacing: .3px;
  }
  .ib-av.c0 { background: #6366F1 } .ib-av.c1 { background: #0EA5E9 }
  .ib-av.c2 { background: #10B981 } .ib-av.c3 { background: #F59E0B }
  .ib-av.c4 { background: #EC4899 } .ib-av.c5 { background: #8B5CF6 }
  .ib-av.c6 { background: #14B8A6 } .ib-av.c7 { background: #F43F5E }

  /* ── Columna 1 ── */
  .ib-head {
    padding: 14px 16px 12px; border-bottom: 1px solid var(--brd); flex-shrink: 0;
  }
  .ib-head-t { font-size: .95rem; font-weight: 700; color: var(--tx); margin-bottom: 12px; }
  .ib-pills { display: flex; gap: 6px; flex-wrap: wrap; }
  .ib-pill {
    font: inherit; font-size: .74rem; font-weight: 600; padding: 5px 11px;
    border-radius: 999px; border: 1px solid var(--brd); background: transparent;
    color: var(--tx2); cursor: pointer; transition: background .15s, color .15s, border-color .15s;
    min-height: 30px;
  }
  .ib-pill:hover { background: var(--bg-hover); color: var(--tx); }
  .ib-pill.on { background: var(--ac); border-color: var(--ac); color: #fff; }
  .ib-pill b { font-weight: 700; opacity: .85; margin-left: 3px; }

  .ib-search { padding: 10px 16px; border-bottom: 1px solid var(--brd); position: relative; flex-shrink: 0; }
  .ib-search input {
    width: 100%; padding: 8px 12px 8px 34px; font: inherit; font-size: .82rem;
    border: 1px solid var(--brd); border-radius: 9px;
    background: var(--bg-input); color: var(--tx);
  }
  .ib-search input:focus { outline: 2px solid var(--ac-soft); outline-offset: 0; border-color: var(--ac); }
  .ib-search svg { position: absolute; left: 26px; top: 50%; transform: translateY(-50%);
                   width: 15px; height: 15px; color: var(--tx3); pointer-events: none; }

  .ib-item {
    display: flex; gap: 11px; width: 100%; text-align: left; padding: 11px 16px;
    border: none; border-bottom: 1px solid var(--brd2); background: transparent;
    cursor: pointer; font: inherit; border-left: 3px solid transparent;
    transition: background .12s;
  }
  .ib-item:hover { background: var(--bg-hover); }
  .ib-item.sel { background: var(--ac-soft); border-left-color: var(--ac); }
  .ib-item-body { flex: 1; min-width: 0; }
  .ib-item-r1 { display: flex; justify-content: space-between; align-items: baseline; gap: 8px; }
  .ib-item-n  { font-size: .83rem; font-weight: 700; color: var(--tx);
                overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .ib-item-t  { font-size: .68rem; color: var(--tx3); flex-shrink: 0; }
  .ib-item-ph { font-size: .72rem; color: var(--tx3); margin-top: 1px; }
  .ib-item-p  { font-size: .76rem; color: var(--tx2); margin-top: 3px;
                overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .ib-item-m  { display: flex; gap: 5px; align-items: center; margin-top: 6px; flex-wrap: wrap; }

  .ib-tag { font-size: .64rem; font-weight: 700; padding: 2px 8px; border-radius: 999px; }
  .ib-tag.libre { background: var(--s-warn-bg); color: var(--s-warn-tx); }
  .ib-tag.mia   { background: var(--s-ok-bg);   color: var(--s-ok-tx); }
  .ib-tag.otro  { background: var(--s-neutral-bg); color: var(--s-neutral-tx); }
  .ib-tag.viejo { background: var(--s-err-bg);  color: var(--s-err-tx); }
  .ib-unread {
    background: var(--ac); color: #fff; font-size: .64rem; font-weight: 700;
    min-width: 18px; height: 18px; padding: 0 5px; border-radius: 999px;
    display: inline-flex; align-items: center; justify-content: center;
  }

  /* ── Columna 2: hilo ── */
  .ib-chat { display: flex; flex-direction: column; height: 100%; min-height: 0; }
  .ib-chat-head {
    display: flex; align-items: center; gap: 11px; padding: 12px 18px;
    border-bottom: 1px solid var(--brd); flex-shrink: 0;
  }
  .ib-chat-n { font-size: .92rem; font-weight: 700; color: var(--tx);
               display: flex; align-items: center; gap: 6px; }
  .ib-chat-n a { color: var(--ac); display: inline-flex; }
  .ib-chat-ph { font-size: .74rem; color: var(--tx3); }

  .ib-msgs { padding: 18px; display: flex; flex-direction: column; gap: 8px; background: var(--bg-base); }
  .ib-sep { align-self: center; font-size: .68rem; font-weight: 600; color: var(--tx2);
            background: var(--bg-surface); border: 1px solid var(--brd);
            padding: 3px 12px; border-radius: 999px; margin: 10px 0 4px; }

  .ib-b { max-width: 72%; padding: 8px 12px 6px; border-radius: 14px;
          font-size: .84rem; line-height: 1.5; white-space: pre-wrap; word-break: break-word; }
  .ib-b.in  { align-self: flex-start; background: var(--bg-surface); color: var(--tx);
              border: 1px solid var(--brd); border-bottom-left-radius: 5px; }
  .ib-b.out { align-self: flex-end; background: var(--ac); color: #fff; border-bottom-right-radius: 5px; }
  /* Automático del sistema: se distingue del mensaje humano sin gritar */
  .ib-b.sis { align-self: flex-end; background: var(--s-neutral-bg); color: var(--tx);
              border: 1px dashed var(--brd); border-bottom-right-radius: 5px; }
  .ib-b-who { font-size: .68rem; font-weight: 700; margin-bottom: 3px; opacity: .9; }
  .ib-b.out .ib-b-who { color: #fff; }
  .ib-b-foot { font-size: .64rem; margin-top: 4px; display: flex; gap: 5px;
               align-items: center; justify-content: flex-end; opacity: .8; }
  .ib-b.in .ib-b-foot { justify-content: flex-start; }
  .ib-b-err { font-size: .7rem; margin-top: 5px; padding: 5px 8px; border-radius: 7px;
              background: var(--s-err-bg); color: var(--s-err-tx); white-space: normal; }

  /* Barra inferior: refleja la ventana de 24h de Meta */
  .ib-foot { border-top: 1px solid var(--brd); padding: 12px 18px; background: var(--bg-surface); flex-shrink: 0; }
  .ib-warn { display: flex; align-items: center; gap: 11px; padding: 11px 14px; border-radius: 10px;
             background: var(--s-warn-bg); color: var(--s-warn-tx); font-size: .79rem; line-height: 1.45; }
  .ib-warn svg { width: 18px; height: 18px; flex-shrink: 0; }
  .ib-warn-b { flex: 1; }
  .ib-warn strong { display: block; }
  .ib-box { display: flex; gap: 9px; align-items: center; }
  .ib-box input {
    flex: 1; padding: 10px 13px; font: inherit; font-size: .85rem;
    border: 1px solid var(--brd); border-radius: 10px; background: var(--bg-input); color: var(--tx);
  }
  .ib-box input:disabled { opacity: .55; cursor: not-allowed; }

  /* ── Columna 3: contexto ── */
  .ib-ctx-s { padding: 15px 17px; border-bottom: 1px solid var(--brd); }
  .ib-ctx-t { font-size: .64rem; font-weight: 700; letter-spacing: .09em; text-transform: uppercase;
              color: var(--tx3); margin-bottom: 9px; display: flex; align-items: center; gap: 6px; }
  .ib-ctx-t svg { width: 13px; height: 13px; }
  .ib-ctx-v { font-size: .85rem; font-weight: 600; color: var(--tx); }
  .ib-ctx-s2 { font-size: .74rem; color: var(--tx3); margin-top: 2px; }
  .ib-ctx-a { font-size: .8rem; color: var(--ac); text-decoration: none;
              display: flex; justify-content: space-between; gap: 8px; padding: 5px 0; }
  .ib-ctx-a:hover { text-decoration: underline; }

  .ib-empty { padding: 56px 28px; text-align: center; color: var(--tx2); font-size: .85rem; }
  .ib-empty svg { width: 40px; height: 40px; color: var(--tx3); margin: 0 auto 12px; display: block; }

  /* Botón: reutiliza el canónico .btn del design system */
  .ib-btn-sm { font-size: .76rem; padding: 6px 13px; min-height: 34px; }

  @media (max-width: 1180px) { .ib { grid-template-columns: 300px minmax(0,1fr); } .ib-ctx { display: none; } }
  @media (max-width: 768px) {
    .ib { grid-template-columns: 1fr; height: calc(100dvh - 50px - 24px); border-radius: 12px; }
    .ib-chat-col { display: none; }
    .ib.abierto .ib-lista-col { display: none; }
    .ib.abierto .ib-chat-col  { display: flex; }
    .ib-b { max-width: 86%; }
  }
</style>

<div class="ib" id="ib">
  <!-- ══ Lista ══ -->
  <div class="ib-col ib-lista-col">
    <div class="ib-head">
      <div class="ib-head-t">Conversaciones</div>
      <div class="ib-pills">
        <button type="button" class="ib-pill on" data-f="todas">Todas<b data-c="todas"></b></button>
        <button type="button" class="ib-pill" data-f="mias">Mías<b data-c="mias"></b></button>
        <button type="button" class="ib-pill" data-f="sin_asignar">Sin asignar<b data-c="sin_asignar"></b></button>
      </div>
    </div>
    <div class="ib-search">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/>
      </svg>
      <input type="search" id="ib-q" placeholder="Buscar empresa, teléfono o mensaje…" aria-label="Buscar conversación">
    </div>
    <div class="ib-scroll" id="ib-lista"></div>
  </div>

  <!-- ══ Hilo ══ -->
  <div class="ib-col ib-chat-col">
    <div class="ib-chat" id="ib-chat">
      <div class="ib-empty">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.42-4.03 8-9 8a9.9 9.9 0 01-4.29-.96L3 20l1.4-3.7A7.6 7.6 0 013 12c0-4.42 4.03-8 9-8s9 3.58 9 8z"/>
        </svg>
        Elige una conversación para ver el hilo
      </div>
    </div>
  </div>

  <!-- ══ Contexto ══ -->
  <div class="ib-col ib-ctx" id="ib-ctx"></div>
</div>

<script>
(function () {
  var st = { f: 'todas', q: '', sel: null };
  var $ = function (s) { return document.querySelector(s); };

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c];
    });
  }
  function ini(t) {
    var p = String(t || '?').replace(/^\+/, '').trim().split(/\s+/);
    return ((p[0]||'?')[0] + (p.length > 1 ? p[1][0] : '')).toUpperCase();
  }
  function colorDe(s) {
    var h = 0, x = String(s || '');
    for (var i = 0; i < x.length; i++) h = (h * 31 + x.charCodeAt(i)) % 8;
    return 'c' + h;
  }
  function d(ts) { return ts ? new Date(ts.replace(' ', 'T')) : null; }
  function relativo(ts) {
    var f = d(ts); if (!f) return '';
    var m = Math.floor((Date.now() - f) / 60000);
    if (m < 1)    return 'ahora';
    if (m < 60)   return m + ' min';
    if (m < 1440) return Math.floor(m / 60) + ' h';
    var dd = Math.floor(m / 1440);
    return dd < 30 ? dd + ' d' : f.toLocaleDateString('es-PE', { day: '2-digit', month: 'short' });
  }
  function hhmm(ts) {
    var f = d(ts); return f ? f.toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit' }) : '';
  }
  function fechaLarga(ts) {
    var f = d(ts); if (!f) return '';
    var hoy = new Date(), ayer = new Date(Date.now() - 86400000);
    if (f.toDateString() === hoy.toDateString())  return 'Hoy';
    if (f.toDateString() === ayer.toDateString()) return 'Ayer';
    return f.toLocaleDateString('es-PE', { day: '2-digit', month: 'long', year: 'numeric' });
  }
  // ✓ enviado · ✓✓ entregado · ✓✓ azul leído — convención que la gente ya conoce
  function ticks(e) {
    if (e === 'read')      return '<span style="color:#7DD3FC">✓✓</span>';
    if (e === 'delivered') return '✓✓';
    if (e === 'sent')      return '✓';
    if (e === 'failed')    return '⚠';
    return '';
  }

  // ── Lista ──
  function lista(mantener) {
    fetch('ajax.php?accion=lista&filtro=' + encodeURIComponent(st.f) + '&q=' + encodeURIComponent(st.q),
          { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j.ok) return;
        ['todas','mias','sin_asignar'].forEach(function (k) {
          var e = document.querySelector('[data-c="' + k + '"]');
          if (e) e.textContent = j.conteos[k] ? ' ' + j.conteos[k] : '';
        });

        var c = $('#ib-lista');
        if (!j.items.length) { c.innerHTML = '<div class="ib-empty">Sin conversaciones</div>'; return; }

        c.innerHTML = j.items.map(function (it) {
          var tag = it.asignado_a
            ? (it.es_mia ? '<span class="ib-tag mia">Mía</span>'
                         : '<span class="ib-tag otro">' + esc(it.asignado_nombre || 'Asignada') + '</span>')
            : '<span class="ib-tag libre">Libre</span>';
          var viejo = (it.dias_asignada !== null && it.dias_asignada >= 3)
            ? '<span class="ib-tag viejo">' + it.dias_asignada + ' d sin mover</span>' : '';
          return '<button type="button" class="ib-item' + (st.sel === it.id ? ' sel' : '') + '" data-id="' + it.id + '">'
            + '<span class="ib-av ' + colorDe(it.telefono) + '">' + esc(ini(it.titulo)) + '</span>'
            + '<span class="ib-item-body">'
            +   '<span class="ib-item-r1"><span class="ib-item-n">' + esc(it.titulo) + '</span>'
            +   '<span class="ib-item-t">' + relativo(it.cuando) + '</span></span>'
            +   '<span class="ib-item-ph">+' + esc(it.telefono) + '</span>'
            +   '<span class="ib-item-p">' + esc(it.preview) + '</span>'
            +   '<span class="ib-item-m">' + tag + viejo
            +     (it.no_leidos ? '<span class="ib-unread">' + it.no_leidos + '</span>' : '')
            +   '</span>'
            + '</span></button>';
        }).join('');
      }).catch(function () {});
  }

  // ── Hilo ──
  function abrir(id) {
    st.sel = id;
    $('#ib').classList.add('abierto');
    fetch('ajax.php?accion=hilo&id=' + id, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) { if (j.ok) { chat(j); ctx(j); lista(true); } })
      .catch(function () {});
  }

  function chat(j) {
    var c = j.conv, ultDia = '', cuerpo = '';

    j.mensajes.forEach(function (m) {
      var dia = fechaLarga(m.cuando);
      if (dia !== ultDia) { cuerpo += '<div class="ib-sep">' + esc(dia) + '</div>'; ultDia = dia; }

      var cls = m.dir === 'in' ? 'in' : (m.autor ? 'out' : 'sis');
      var who = m.dir === 'out'
        ? '<div class="ib-b-who">' + esc(m.autor || 'Sistema · automático') + '</div>' : '';
      var err = (m.estado === 'failed' && m.error)
        ? '<div class="ib-b-err">' + esc(m.error) + '</div>' : '';

      cuerpo += '<div class="ib-b ' + cls + '">' + who + esc(m.texto || '') + err
             +  '<div class="ib-b-foot">' + hhmm(m.cuando)
             +  (m.dir === 'out' ? ' <span>' + ticks(m.estado) + '</span>' : '') + '</div></div>';
    });

    // Acción de asignación: el candado que pediste
    var accion = c.asignado_a
      ? (c.puede_liberar
          ? '<button type="button" class="btn btn-ghost ib-btn-sm" id="ib-liberar">Liberar</button>'
          : '<span class="ib-tag otro">Atiende ' + esc(c.asignado_nombre) + '</span>')
      : '<button type="button" class="btn btn-primary ib-btn-sm" id="ib-asignar">Asignármela</button>';

    // Meta solo admite texto libre dentro de las 24h desde el último entrante
    var pie;
    if (!c.ventana_abierta) {
      pie = '<div class="ib-warn">'
          + '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">'
          + '<circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 7v5l3 2"/></svg>'
          + '<span class="ib-warn-b"><strong>Ventana de 24 h vencida</strong>'
          + 'Solo se pueden enviar plantillas aprobadas hasta que el contacto responda.</span>'
          + '<button type="button" class="btn btn-primary ib-btn-sm" disabled title="Envío de plantillas: próxima entrega">Enviar plantilla</button>'
          + '</div>';
    } else if (!c.es_mia) {
      pie = '<div class="ib-box">'
          + '<input type="text" placeholder="' + (c.asignado_a ? 'La atiende ' + esc(c.asignado_nombre) : 'Asígnate la conversación para responder') + '" disabled aria-label="Mensaje">'
          + '<button type="button" class="btn btn-primary ib-btn-sm" disabled>Enviar</button>'
          + '</div>';
    } else {
      pie = '<div class="ib-box">'
          + '<input type="text" id="ib-texto" placeholder="Escribe un mensaje…" aria-label="Mensaje" maxlength="4096" autocomplete="off">'
          + '<button type="button" class="btn btn-primary ib-btn-sm" id="ib-enviar">Enviar</button>'
          + '</div>';
    }

    $('#ib-chat').innerHTML =
        '<div class="ib-chat-head">'
      +   '<span class="ib-av ' + colorDe(c.telefono) + '">' + esc(ini(c.titulo)) + '</span>'
      +   '<div style="flex:1;min-width:0">'
      +     '<div class="ib-chat-n">' + esc(c.titulo)
      +       (c.cliente_id ? ' <a href="../clientes/ver.php?id=' + c.cliente_id + '" title="Abrir ficha de la empresa" aria-label="Abrir ficha de la empresa">'
      +         '<svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">'
      +         '<path stroke-linecap="round" stroke-linejoin="round" d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6M15 3h6v6M10 14L21 3"/></svg></a>' : '')
      +     '</div>'
      +     '<div class="ib-chat-ph">+' + esc(c.telefono) + '</div>'
      +   '</div>' + accion
      + '</div>'
      + '<div class="ib-scroll ib-msgs" id="ib-msgs">' + cuerpo + '</div>'
      + '<div class="ib-foot">' + pie + '</div>';

    var m = $('#ib-msgs'); if (m) m.scrollTop = m.scrollHeight;

    var t = $('#ib-texto');
    if (t) { t.focus(); t.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); enviar(); }
    }); }
  }

  // ── Envío ──
  function enviar() {
    var inp = $('#ib-texto'), btn = $('#ib-enviar');
    if (!inp || !st.sel) return;

    var texto = inp.value.trim();
    if (!texto) return;

    inp.disabled = true;
    if (btn) { btn.disabled = true; btn.textContent = 'Enviando…'; }

    ggiPost('ajax.php', { accion: 'enviar', id: st.sel, texto: texto }).then(function (j) {
      if (j.ok) inp.value = '';
      // Se recarga el hilo en ambos casos: si falló, el mensaje queda
      // registrado como fallido con el motivo real de Meta a la vista.
      abrir(st.sel);
    });
  }

  function ctx(j) {
    var c = j.conv, x = j.contexto;
    function sec(t, icono, cuerpo) {
      return '<div class="ib-ctx-s"><div class="ib-ctx-t">' + icono + t + '</div>' + cuerpo + '</div>';
    }
    var iPersona = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>';
    var iEmp = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 21h18M5 21V7l7-4 7 4v14M9 9h1m4 0h1M9 13h1m4 0h1M9 17h1m4 0h1"/></svg>';
    var iReloj = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 7v5l3 2"/></svg>';

    var h = sec('Contacto', iPersona,
        '<div class="ib-ctx-v">' + esc(c.contacto || 'Sin nombre registrado') + '</div>'
      + '<div class="ib-ctx-s2">+' + esc(c.telefono) + '</div>');

    h += sec('Empresa', iEmp, c.cliente_id
      ? '<a class="ib-ctx-a" href="../clientes/ver.php?id=' + c.cliente_id + '"><span>' + esc(c.titulo) + '</span><span>↗</span></a>'
        + (c.ruc ? '<div class="ib-ctx-s2">RUC ' + esc(c.ruc) + '</div>' : '')
      : '<div class="ib-ctx-s2">Número no vinculado a ninguna empresa</div>');

    if (x.propuestas.length) {
      h += sec('Propuestas', '', x.propuestas.map(function (p) {
        return '<a class="ib-ctx-a" href="../cotizaciones/ver.php?id=' + p.id + '"><span>' + esc(p.numero) + '</span>'
             + '<span style="color:var(--tx3)">' + esc(p.estado) + '</span></a>';
      }).join(''));
    }
    if (x.envios.length) {
      h += sec('Envíos', '', x.envios.map(function (e) {
        return '<a class="ib-ctx-a" href="../envios/index.php"><span>' + esc(e.codigo_envio || '—') + '</span><span>↗</span></a>';
      }).join(''));
    }

    h += sec('Ventana de 24 h', iReloj, c.ventana_abierta
      ? '<span class="ib-tag mia">Abierta</span>'
      : '<span class="ib-tag libre">Vencida</span>');

    $('#ib-ctx').innerHTML = h;
  }

  // ── Acciones ──
  // Usa el helper global ggiPost: adjunta el _csrf correcto y muestra el error
  // si lo hay, en vez de fallar en silencio.
  function accionar(accion, id) {
    ggiPost('ajax.php', { accion: accion, id: id }).then(function (j) {
      if (j.ok && typeof showToast === 'function') {
        showToast(accion === 'asignar' ? 'Conversación asignada' : 'Conversación liberada', 'ok');
      }
      abrir(id);
    });
  }

  document.addEventListener('click', function (e) {
    var p = e.target.closest('.ib-pill');
    if (p) {
      document.querySelectorAll('.ib-pill').forEach(function (b) { b.classList.remove('on'); });
      p.classList.add('on'); st.f = p.dataset.f; lista(); return;
    }
    if (e.target.closest('#ib-asignar')) { accionar('asignar', st.sel); return; }
    if (e.target.closest('#ib-liberar')) { accionar('liberar', st.sel); return; }
    if (e.target.closest('#ib-enviar'))  { enviar(); return; }
    var it = e.target.closest('.ib-item');
    if (it) abrir(parseInt(it.dataset.id, 10));
  });

  var q = document.getElementById('ib-q');
  if (q) { var t; q.addEventListener('input', function () {
    clearTimeout(t); t = setTimeout(function () { st.q = q.value.trim(); lista(); }, 300); }); }

  // Polling: el monolito no tiene websockets. Pausa con la pestaña oculta.
  setInterval(function () { if (!document.hidden) lista(true); }, 8000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) lista(true); });

  lista();
})();
</script>
