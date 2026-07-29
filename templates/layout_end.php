<?php if (!empty($_ggi_embed)): ?></div><?php else: ?></main><?php endif; ?>

<style>
/* ── Embed mode: wrapper sin sidebar ── */
.ggi-embed-wrap {
  padding: 20px;
  background: var(--bg-base, #F1F5F9);
  min-height: 100vh;
}

/* Nota: la barra sticky de acciones en embed mobile se aplica dentro del
   iframe mediante layout.php (modo embed) — NO aquí, para no afectar páginas normales. */

/* ── Modal grande (formularios embed) ── */
.mant-modal-box.mant-modal-lg {
  max-width: 860px;
  width: 90vw;
  max-height: 94vh;
}
.mant-modal-box.mant-modal-lg .mant-modal-body {
  padding: 0;
  overflow: hidden;
  position: relative;
}
.ggi-embed-frame {
  width: 100%;
  height: 82vh;
  border: none;
  display: block;
  border-radius: 0 0 14px 14px;
}
/* Dark mode support for large modal */
:root[data-tema="oscuro"] .mant-modal-box.mant-modal-lg { background: #111927 !important; }
:root[data-tema="oscuro"] #ggi-spin { background: #111927 !important; color: #8893A8 !important; }
@keyframes ggiSpin { to { transform: rotate(360deg); } }
</style>

<!-- ═══════════════════════════════════════════════════════════════════════
     PAGINACIÓN GLOBAL — aplica a todos los módulos.
     Los módulos no deben definir sus propios estilos de paginación.
     ═══════════════════════════════════════════════════════════════════════ -->
<style>
/* ── Contenedor ── */
.pagination {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 12px 20px;
  border-top: 1px solid var(--brd, #E2E8F0);
  flex-wrap: wrap;
}

/* ── Info (total / rango) ── */
.pagination-info,
.pag-info {
  font-size: 12px;
  color: var(--tx2, #64748B);
  line-height: 1;
  white-space: nowrap;
}

/* ── Grupo de botones ── */
.pagination-btns,
.pag-links {
  display: flex;
  align-items: center;
  gap: 4px;
  flex-wrap: nowrap;
}

/* ── Botón cuadrado: números de página y flechas simples ‹ › ── */
.page-btn,
.pag-btn {
  min-width: 32px;
  height: 32px;
  padding: 0 8px;
  border-radius: 7px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: 1.5px solid var(--brd, #E2E8F0);
  background: var(--bg-surface, #fff);
  cursor: pointer;
  font-size: 12px;
  font-weight: 600;
  text-decoration: none;
  color: var(--tx, #0F172A);
  transition: background .12s, border-color .12s, color .12s;
  white-space: nowrap;
  user-select: none;
  line-height: 1;
}
.page-btn:hover,  .pag-btn:hover  { background: var(--ac, #1D4ED8); color: #fff; border-color: var(--ac, #1D4ED8); }
.page-btn.active, .pag-btn.active { background: var(--ac, #1D4ED8); color: #fff; border-color: var(--ac, #1D4ED8); }
.page-btn.disabled, .pag-btn.disabled { opacity: .35; pointer-events: none; cursor: default; }

/* ── Botón de texto: "← Anterior" / "Siguiente →" ── */
.page-nav {
  height: 32px;
  padding: 0 13px;
  border-radius: 7px;
  display: inline-flex;
  align-items: center;
  gap: 5px;
  border: 1.5px solid var(--brd, #E2E8F0);
  background: var(--bg-surface, #fff);
  cursor: pointer;
  font-size: 12px;
  font-weight: 600;
  text-decoration: none;
  color: var(--tx, #0F172A);
  transition: background .12s, border-color .12s, color .12s;
  white-space: nowrap;
  user-select: none;
  line-height: 1;
}
.page-nav:hover   { background: var(--ac, #1D4ED8); color: #fff; border-color: var(--ac, #1D4ED8); }
.page-nav.disabled { opacity: .35; pointer-events: none; cursor: default; }

/* ── Select "15 / pág." ── */
.per-page-sel {
  height: 32px;
  padding: 0 8px;
  border: 1.5px solid var(--brd, #E2E8F0);
  border-radius: 7px;
  background: var(--bg-surface, #fff);
  color: var(--tx, #0F172A);
  font-size: 12px;
  cursor: pointer;
  outline: none;
  margin-left: 4px;
}

/* ── Dark mode ── */
:root[data-tema="oscuro"] .page-btn,
:root[data-tema="oscuro"] .pag-btn,
:root[data-tema="oscuro"] .page-nav  { background: #182336; border-color: #1A2840; color: #ECF1FB; }
:root[data-tema="oscuro"] .per-page-sel { background: #182336; border-color: #1A2840; color: #ECF1FB; }

/* ── Responsive ── */
@media (max-width: 520px) {
  .pagination { flex-direction: column; align-items: flex-start; padding: 10px 14px; gap: 8px; }
  .pagination-info, .pag-info { font-size: 11px; }
  .page-nav { padding: 0 10px; font-size: 11px; }
  .per-page-sel { font-size: 11px; }
}
</style>

<style>
/* ── Transición suave entre páginas ── */


/* ── Botones Volver mejorados ── */
.btn-back {
  transition: transform 0.18s ease, background 0.18s ease, border-color 0.18s ease, color 0.18s ease !important;
  display: inline-flex !important;
  align-items: center !important;
  gap: 6px !important;
}
.btn-back:hover {
  transform: translateX(-4px) !important;
  background: var(--azul-light) !important;
  border-color: var(--azul) !important;
  color: var(--azul) !important;
  box-shadow: 0 2px 8px rgba(26,58,143,0.12) !important;
}
.btn-back-icon {
  transition: transform 0.18s ease;
  flex-shrink: 0;
}
.btn-back:hover .btn-back-icon {
  transform: translateX(-2px);
}

/* ── Modal upload de documentos ── */
.modal-dz {
  border: 2px dashed var(--gris-borde); border-radius: 10px;
  padding: 28px 24px; text-align: center;
  transition: border-color .2s, background .2s;
}
.modal-dz.dragover {
  border-color: var(--azul); background: var(--azul-light);
  box-shadow: 0 0 0 4px rgba(26,58,143,0.08);
}
/* Cuando se arrastra sobre el modal completo, resaltar la zona */
.mant-modal-overlay.dz-over .modal-dz {
  border-color: var(--azul); background: var(--azul-light);
}
.modal-fp {
  display: none; align-items: center; gap: 10px;
  padding: 10px 14px; background: #f0faf2;
  border: 1px solid #b7e4c0; border-radius: 9px; margin-top: 12px;
}
.modal-fp.show { display: flex; }
.modal-fp-name { flex: 1; font-size: 12px; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--gris-dark); min-width: 0; }
.modal-version-info {
  background: #fff7e6; border: 1px solid #fde68a; border-radius: 9px;
  padding: 10px 14px; font-size: 12px; color: #92400e;
  display: flex; align-items: center; gap: 8px; margin-bottom: 14px;
}

/* ── Toast notification system (premium) ── */
#toast-container {
  position: fixed;
  top: calc(20px + var(--safe-top, 0px)); right: max(20px, var(--safe-right, 0px));
  z-index: 99999;
  display: flex; flex-direction: column;
  gap: 10px; pointer-events: none;
  width: 356px; max-width: calc(100vw - 24px);
}
@media (max-width: 520px) {
  /* safe-area: el toast NO debe quedar bajo el notch/Dynamic Island en PWA */
  #toast-container {
    top: calc(12px + var(--safe-top, 0px));
    right: max(12px, var(--safe-right, 0px));
    left: max(12px, var(--safe-left, 0px));
    width: auto;
  }
}
.ggi-toast {
  background: var(--bg-surface, #fff);
  border: 1px solid var(--brd, #e2e8f0);
  border-radius: 14px;
  padding: 13px 13px 16px 13px;
  display: flex; align-items: flex-start; gap: 11px;
  box-shadow: 0 8px 32px rgba(0,0,0,.10), 0 2px 8px rgba(0,0,0,.06);
  pointer-events: auto; position: relative; overflow: hidden;
  animation: _gt-in .3s cubic-bezier(.16,1,.3,1) forwards;
  font-family: 'Inter','Inter',sans-serif;
}
.ggi-toast.hiding {
  animation: _gt-out .24s cubic-bezier(.4,0,1,1) forwards;
  pointer-events: none;
}
.ggi-toast-ico {
  flex-shrink: 0; width: 34px; height: 34px;
  border-radius: 9px;
  display: flex; align-items: center; justify-content: center;
  margin-top: 1px;
}
.ggi-toast-body { flex: 1; min-width: 0; padding-top: 1px; }
.ggi-toast-title {
  font-size: 13.5px; font-weight: 700;
  color: var(--tx, #0f172a); line-height: 1.35;
  letter-spacing: -.01em;
}
.ggi-toast-sub {
  font-size: 12px; color: var(--tx2, #64748b);
  line-height: 1.5; margin-top: 3px;
}
.ggi-toast-x {
  flex-shrink: 0; align-self: flex-start;
  width: 24px; height: 24px;
  border-radius: 6px; border: none;
  background: transparent; color: var(--tx2, #64748b); /* ≥3:1 control no-texto (era --tx3, 2.35:1) */
  cursor: pointer; display: flex; align-items: center; justify-content: center;
  padding: 0; margin-top: 1px;
  transition: background .14s, color .14s;
  font-size: 15px; line-height: 1;
}
.ggi-toast-x:hover { background: var(--bg-hover, #f1f5f9); color: var(--tx, #0f172a); }
/* Botón de acción del toast (p.ej. "Deshacer") */
.ggi-toast-act {
  flex-shrink: 0; align-self: center;
  padding: 6px 12px;
  border-radius: 8px; border: 1.5px solid var(--brd, #e2e8f0);
  background: transparent; color: var(--ac, #1A3A8F);
  font-family: inherit; font-size: 12.5px; font-weight: 700;
  cursor: pointer; white-space: nowrap;
  transition: background .14s, border-color .14s;
}
.ggi-toast-act:hover { background: var(--ac-soft, #eef2ff); border-color: var(--ac, #1A3A8F); }
@media (hover: none) {
  .ggi-toast-act { min-height: 44px; padding: 10px 14px; }
}
.ggi-toast-bar {
  position: absolute; bottom: 0; left: 0; right: 0;
  height: 3px; transform-origin: left;
  border-radius: 0 0 14px 14px;
  animation: _gt-bar linear forwards;
}
/* ── Type variants ── */
.ggi-toast.t-ok      .ggi-toast-ico { background: var(--s-ok-bg,   #dcfce7); color: var(--s-ok,   #059669); }
.ggi-toast.t-ok      .ggi-toast-bar { background: var(--s-ok,      #059669); }
.ggi-toast.t-error   .ggi-toast-ico { background: var(--s-err-bg,  #fee2e2); color: var(--s-err,  #dc2626); }
.ggi-toast.t-error   .ggi-toast-bar { background: var(--s-err,     #dc2626); }
.ggi-toast.t-warning .ggi-toast-ico { background: var(--s-warn-bg, #fef3c7); color: var(--s-warn, #d97706); }
.ggi-toast.t-warning .ggi-toast-bar { background: var(--s-warn,    #d97706); }
.ggi-toast.t-info    .ggi-toast-ico { background: var(--s-info-bg, #f0f9ff); color: var(--s-info, #0369a1); }
.ggi-toast.t-info    .ggi-toast-bar { background: var(--s-info,    #0369a1); }
.ggi-toast.t-loading .ggi-toast-ico { background: var(--bg-hover,  #f1f5f9); color: var(--tx2,    #64748b); }
/* En oscuro, el rojo medio (#EF4444) sobre el chip tenue da 4.23:1; un rojo más
   claro lo lleva a ≥4.5 y lo iguala con los demás iconos (§ color-contrast). */
:root[data-tema="oscuro"] .ggi-toast.t-error .ggi-toast-ico { color: #f87171; }
/* ── Animations ── */
@keyframes _gt-in  { from { opacity:0; transform:translateX(calc(100% + 24px)); } to { opacity:1; transform:translateX(0); } }
@keyframes _gt-out { from { opacity:1; transform:translateX(0); max-height:120px; margin-bottom:0; } to { opacity:0; transform:translateX(calc(100% + 24px)); max-height:0; padding-top:0; padding-bottom:0; } }
@keyframes _gt-bar  { from { transform:scaleX(1); } to { transform:scaleX(0); } }
@keyframes _gt-spin { to { transform:rotate(360deg); } }
/* ── Respeta prefers-reduced-motion (§ reduced-motion): sin deslizamiento ni
   barra; el toast aparece/desaparece con un fundido suave y mínimo. ── */
@media (prefers-reduced-motion: reduce) {
  .ggi-toast { animation: _gt-in-rm .01s linear forwards; }
  .ggi-toast.hiding { animation: _gt-out-rm .15s linear forwards; }
  .ggi-toast-bar { animation: none; display: none; }
  .ggi-toast.t-loading .ggi-toast-ico svg { animation: none !important; }
  @keyframes _gt-in-rm  { from { opacity:0; } to { opacity:1; } }
  @keyframes _gt-out-rm { from { opacity:1; } to { opacity:0; } }
}
/* ── Legacy .toast elements hidden (showToast now creates .ggi-toast) ── */
.toast { opacity:0; pointer-events:none; position:absolute; }

/* ── Bulk action bar (global — appended to body, outside <main>) ── */
.ggi-bulk-bar {
  position: fixed; bottom: 28px; left: 50%;
  transform: translateX(-50%) translateY(12px);
  z-index: 9800;
  background: #1e293b; color: #f8fafc;
  border-radius: 14px; padding: 10px 14px;
  display: flex; align-items: center; gap: 9px;
  box-shadow: 0 16px 48px rgba(0,0,0,.32), 0 2px 8px rgba(0,0,0,.14);
  font-family: 'Inter','Inter',sans-serif;
  font-size: 13px; font-weight: 600; white-space: nowrap;
  opacity: 0; pointer-events: none;
  transition: opacity .22s ease, transform .22s ease;
}
.ggi-bulk-bar.visible {
  opacity: 1; pointer-events: auto;
  transform: translateX(-50%) translateY(0);
}
:root[data-tema="oscuro"] .ggi-bulk-bar {
  /* Mantener una superficie slate (no azul) para no enturbiar los botones
     semánticos translúcidos; un borde sutil la separa de la página oscura. */
  background: #273344;
  border: 1px solid rgba(255,255,255,.10);
  box-shadow: 0 16px 48px rgba(0,0,0,.55), 0 2px 8px rgba(0,0,0,.35);
}
.ggi-bulk-count {
  background: rgba(255,255,255,.16); border-radius: 7px;
  padding: 2px 10px; font-size: 12px; font-weight: 700;
}
.ggi-bulk-sep { width: 1px; height: 18px; background: rgba(255,255,255,.18); flex-shrink: 0; }
.ggi-bulk-btn {
  padding: 6px 14px; border: none; border-radius: 8px;
  font-size: 12.5px; font-weight: 700; cursor: pointer;
  font-family: inherit; transition: opacity .14s, transform .1s;
  letter-spacing: -.01em;
}
.ggi-bulk-btn:hover  { opacity: .82; }
.ggi-bulk-btn:active { transform: scale(.96); }
.ggi-bulk-btn-ok   { background: rgba(34,197,94,.28);  color: #86efac; }
.ggi-bulk-btn-warn { background: rgba(251,191,36,.28); color: #fde68a; }
.ggi-bulk-btn-err  { background: rgba(239,68,68,.28);  color: #fca5a5; }
.ggi-bulk-btn-neutral { background: rgba(255,255,255,.12); color: #f1f5f9; border: 1px solid rgba(255,255,255,.15); }
.ggi-bulk-btn-cancel { background: transparent; border: none; cursor: pointer; color: #94a3b8; font-size: 12px; padding: 4px 8px; font-family: inherit; transition: color .14s; }
.ggi-bulk-btn-cancel:hover { color: #f8fafc; }
/* ── Selección de fila moderna (SaaS: borde izq + fondo suave + elevación) ── */
.data-table tbody tr.bulk-selected,
.ggi-dt-table tbody tr.bulk-selected {
  position: relative; z-index: 1;
}
.data-table tbody tr.bulk-selected td,
.ggi-dt-table tbody tr.bulk-selected td {
  background: rgba(29,78,216,.05) !important;
}
/* Borde izquierdo indicador + ligera elevación en la primera celda */
.data-table tbody tr.bulk-selected td:first-child,
.ggi-dt-table tbody tr.bulk-selected td:first-child {
  box-shadow: inset 3px 0 0 var(--ac, #1d4ed8);
}
.data-table tbody tr.bulk-selected td:first-child,
.data-table tbody tr.bulk-selected td:last-child,
.ggi-dt-table tbody tr.bulk-selected td:first-child,
.ggi-dt-table tbody tr.bulk-selected td:last-child {
  position: relative;
}
.data-table tbody tr.bulk-selected,
.ggi-dt-table tbody tr.bulk-selected {
  box-shadow: 0 1px 2px rgba(15,23,42,.06), 0 4px 12px rgba(29,78,216,.07);
}
:root[data-tema="oscuro"] .data-table tbody tr.bulk-selected td,
:root[data-tema="oscuro"] .ggi-dt-table tbody tr.bulk-selected td {
  background: rgba(59,130,246,.09) !important;
}
:root[data-tema="oscuro"] .data-table tbody tr.bulk-selected td:first-child,
:root[data-tema="oscuro"] .ggi-dt-table tbody tr.bulk-selected td:first-child {
  box-shadow: inset 3px 0 0 var(--ac, #3b82f6);
}
:root[data-tema="oscuro"] .data-table tbody tr.bulk-selected,
:root[data-tema="oscuro"] .ggi-dt-table tbody tr.bulk-selected {
  box-shadow: 0 1px 2px rgba(0,0,0,.3), 0 4px 12px rgba(59,130,246,.12);
}

/* ── Modal mantenimiento ── */
.mant-modal-overlay {
  display: none; position: fixed; inset: 0;
  background: rgba(0,0,0,0.5); z-index: 9999;
  align-items: center; justify-content: center; padding: 20px;
}
.mant-modal-overlay.open { display: flex; }
.mant-modal-box {
  background: #fff; border-radius: 14px; width: 100%;
  max-width: 500px; max-height: 90vh; overflow-y: auto;
  box-shadow: 0 20px 60px rgba(0,0,0,0.25);
}
.mant-modal-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 18px 22px; border-bottom: 1px solid var(--gris-borde);
  position: sticky; top: 0; background: #fff; z-index: 1;
}
:root[data-tema="oscuro"] .mant-modal-box { background: #111927 !important; border-color: #1A2840 !important; color: #ECF1FB !important; }
:root[data-tema="oscuro"] .mant-modal-header { background: #111927 !important; border-color: #1A2840 !important; }
:root[data-tema="oscuro"] .mant-modal-title { color: #F1F5F9 !important; font-family:'Inter',sans-serif !important; }
:root[data-tema="oscuro"] .mant-modal-close { color: #8893A8 !important; }
:root[data-tema="oscuro"] .mant-modal-close:hover { background: #182336 !important; }
:root[data-tema="oscuro"] .mant-card-title { color: #D0D8E8 !important; font-family:'Inter',sans-serif !important; }
:root[data-tema="oscuro"] .mant-card-header { border-color:#1A2840 !important; }
.mant-modal-title { font-family:'Inter',sans-serif; font-size:15px; font-weight:700; color:var(--azul); }
.mant-modal-close {
  background: none; border: none; cursor: pointer; font-size: 18px;
  color: var(--gris-texto); width: 32px; height: 32px; border-radius: 8px;
  display: flex; align-items: center; justify-content: center;
}
.mant-modal-close:hover { background: var(--gris-bg); }
.mant-modal-body { padding: 22px; }

/* ════════════════════════════════════════════════════════════
   MOBILE / PWA — Modales como pantallas nativas completas
   Solo afecta al componente .mant-modal-* en pantallas < 768px.
   NO afecta a páginas normales ni a clases fuera del modal.
   Desktop: sin cambios.
   ════════════════════════════════════════════════════════════ */
@media (max-width: 767px) {

  /* Overlay: sin padding, sin oscurecimiento de fondo */
  .mant-modal-overlay {
    padding: 0 !important;
    align-items: flex-start !important;
    background: rgba(0,0,0,0) !important;
  }

  /* Caja del modal: full-screen, columna flex */
  .mant-modal-box,
  .mant-modal-box.mant-modal-lg {
    max-width:      100% !important;
    width:          100% !important;
    max-height:     100dvh !important;
    height:         100dvh !important;
    border-radius:  0 !important;
    box-shadow:     none !important;
    overflow-y:     hidden !important; /* cada zona gestiona su propio scroll */
    display:        flex;
    flex-direction: column;
  }

  /* Header: barra nativa — sin overflow. Baja bajo el notch (safe-area) en PWA. */
  .mant-modal-header {
    padding:     var(--safe-top, 0px) 4px 0 0 !important;
    min-height:  calc(54px + var(--safe-top, 0px)) !important;
    height:      calc(54px + var(--safe-top, 0px));
    box-sizing:  border-box;
    flex-shrink: 0;
    overflow:    hidden;
  }
  .mant-modal-close { height: 54px !important; }

  /* Body de modales pequeños (mantOpen — sin iframe) */
  .mant-modal-box:not(.mant-modal-lg) .mant-modal-body {
    flex:       1;
    overflow-y: auto;   /* scroll interno del contenido */
    -webkit-overflow-scrolling: touch;
  }

  /* Body de modales grandes (ggiOpenForm — con iframe) */
  .mant-modal-box.mant-modal-lg .mant-modal-body {
    flex:       1;
    overflow:   hidden; /* el iframe maneja su propio scroll */
    padding:    0 !important;
    display:    flex;
    flex-direction: column;
    min-height: 0;
  }

  /* iframe: flex stretch sin conflicto de altura */
  .ggi-embed-frame {
    flex:        1 1 0 !important;
    height:      0 !important;    /* flex-grow lo expande; height:0 evita conflictos */
    min-height:  0 !important;
    width:       100% !important;
  }

  /* ── Botón ← Volver: izquierda del header, tamaño touch ── */
  .mant-modal-close {
    order:         -1;          /* posición: antes del título */
    width:         auto !important;
    min-width:     44px;
    height:        54px !important;
    padding:       0 14px !important;
    border-radius: 0 !important;
    font-size:     inherit !important;  /* NO font-size:0 — muestra el texto Volver */
    flex-shrink:   0;
  }

  /* En mobile mostrar "← Volver"; en desktop mostrar "✕" */
  .mant-modal-close .ggi-close-icon-mobile  { display: flex !important; }
  .mant-modal-close .ggi-close-icon-desktop { display: none !important; }

  /* Título: ocupa espacio restante, centrado */
  .mant-modal-title {
    flex:          1 !important;
    text-align:    center !important;
    font-size:     15px !important;
    overflow:      hidden;
    text-overflow: ellipsis;
    white-space:   nowrap;
    padding-right: 10px; /* compensa visualmente el botón izquierdo */
  }
}

/* ════════════════════════════════════════════════════════════
   MOBILE — Modales SIMPLES (sin iframe) como POPUP centrado.
   Los modales grandes con iframe (.mant-modal-lg: editar empresa,
   propuestas…) siguen a pantalla completa arriba. Los simples
   (adjuntar, plantillas, interactivo, confirmaciones…) se ven como
   popup con márgenes y letra más chica, no cubriendo toda la pantalla.
   Mayor especificidad + !important para ganar a las reglas de arriba.
   ════════════════════════════════════════════════════════════ */
@media (max-width: 767px) {
  .mant-modal-overlay:has(.mant-modal-box:not(.mant-modal-lg)) {
    padding:      18px !important;
    align-items:  center !important;
    background:   rgba(0,0,0,.5) !important;
  }
  .mant-modal-box:not(.mant-modal-lg) {
    max-width:     460px !important;
    width:         100% !important;
    max-height:    86dvh !important;
    height:        auto !important;
    border-radius: 14px !important;
    box-shadow:    0 20px 60px rgba(0,0,0,.35) !important;
    overflow-y:    hidden !important;
    display:       flex;
    flex-direction: column;
  }
  .mant-modal-box:not(.mant-modal-lg) .mant-modal-header {
    min-height:   46px !important;
    height:       auto !important;
    padding:      10px 8px 10px 14px !important;
  }
  /* Popup: botón de cierre = "✕" a la derecha (no el "← Volver" de pantalla completa) */
  .mant-modal-box:not(.mant-modal-lg) .mant-modal-close {
    order:        0 !important;
    width:        32px !important;
    min-width:    32px !important;
    height:       32px !important;
    padding:      0 !important;
    border-radius: 8px !important;
    font-size:    16px !important;
  }
  .mant-modal-box:not(.mant-modal-lg) .mant-modal-close .ggi-close-icon-mobile  { display: none !important; }
  .mant-modal-box:not(.mant-modal-lg) .mant-modal-close .ggi-close-icon-desktop { display: flex !important; }
  .mant-modal-box:not(.mant-modal-lg) .mant-modal-title {
    flex:         1 !important;
    text-align:   left !important;
    font-size:    14px !important;
    padding-right: 8px !important;
  }
  .mant-modal-box:not(.mant-modal-lg) .mant-modal-body { padding: 16px !important; }
}

/* Desktop: iconos originales */
@media (min-width: 768px) {
  .mant-modal-close .ggi-close-icon-mobile  { display: none  !important; }
  .mant-modal-close .ggi-close-icon-desktop { display: flex  !important; }
}

/* ── Banner de dirty-check (cambios sin guardar) ── */
#ggi-dirty-banner {
  display: none;
  position: fixed;
  inset: 0;
  z-index: 10200;
  background: rgba(0,0,0,.55);
  align-items: center;
  justify-content: center;
  padding: 20px;
}
#ggi-dirty-banner.open { display: flex; }
#ggi-dirty-box {
  background: var(--bg-surface, #fff);
  border-radius: 16px;
  padding: 28px 24px 22px;
  max-width: 360px;
  width: 100%;
  box-shadow: 0 12px 40px rgba(0,0,0,.18);
}
#ggi-dirty-box h3 { font-size:16px; font-weight:700; color:var(--gris-dark,#0f172a); margin:0 0 8px; }
#ggi-dirty-box p  { font-size:13px; color:var(--gris-texto,#64748b); margin:0 0 20px; line-height:1.5; }
.ggi-dirty-btns   { display:flex; gap:10px; justify-content:flex-end; flex-wrap:wrap; }
:root[data-tema="oscuro"] #ggi-dirty-box { background: #182336; }
:root[data-tema="oscuro"] #ggi-dirty-box h3 { color: #E2E8F0; }
:root[data-tema="oscuro"] #ggi-dirty-box p  { color: #8893A8; }

/* ── Tabla mantenimiento ── */
.mant-table-card { background:#fff; border-radius:12px; border:1px solid var(--gris-borde); overflow:hidden; }
.mant-card-header { display:flex; align-items:center; justify-content:space-between; padding:16px 20px; border-bottom:1px solid var(--gris-borde); }
.mant-card-title { font-family:'Inter',sans-serif; font-size:14px; font-weight:700; color:var(--azul); }
.mant-table { width:100%; border-collapse:collapse; }
.mant-table th { font-size:11px; font-weight:600; color:var(--gris-texto); text-transform:uppercase; letter-spacing:0.5px; padding:11px 16px; background:var(--bg-th); border-bottom:1px solid var(--gris-borde); text-align:left; }
.mant-table td { padding:12px 16px; font-size:13px; border-bottom:1px solid #f0f2f8; vertical-align:middle; }
.mant-table tr:last-child td { border-bottom:none; }
.mant-table tr:hover td { background:var(--bg-surface2); }
/* ── Badge activo/inactivo ── */
.badge-activo   { background:var(--s-ok-bg);      color:var(--s-ok);      padding:3px 9px; border-radius:100px; font-size:11px; font-weight:600; }
.badge-inactivo { background:var(--s-neutral-bg);  color:var(--s-neutral); padding:3px 9px; border-radius:100px; font-size:11px; font-weight:600; }
.alert-ok { display:none; }
.empty-state { padding:32px; text-align:center; color:var(--gris-texto); font-size:13px; }

/* ── Botones icono ── ahora usan CSS vars → dark mode gratis ── */
.btn-ic { display:inline-flex; align-items:center; justify-content:center; width:30px; height:30px; border-radius:7px; border:none; cursor:pointer; text-decoration:none; transition:all 0.15s; flex-shrink:0; }
.btn-ic:focus-visible { outline:2px solid var(--azul); outline-offset:2px; }
@media (max-width:768px) { .btn-ic { width:40px; height:40px; border-radius:9px; } }
.btn-ic-blue  { background:var(--ac-soft);      color:var(--ac); }
.btn-ic-blue:hover  { background:var(--ac);      color:#fff; }
.btn-ic-amber { background:var(--s-warn-bg);    color:var(--s-warn); }
.btn-ic-amber:hover { background:var(--s-warn);  color:#fff; }
.btn-ic-green { background:var(--s-ok-bg);      color:var(--s-ok); }
.btn-ic-green:hover { background:var(--gr);      color:#fff; }
.btn-ic-red   { background:var(--s-err-bg);     color:var(--s-err); }
.btn-ic-red:hover   { background:var(--s-err);   color:#fff; }
.btn-ic-wa    { background:rgba(37,211,102,0.13); color:#19a84a; }
.btn-ic-wa:hover    { background:#25D366;          color:#fff; }
.btn-ic-wa-off { background:rgba(148,163,184,0.15); color:#94a3b8; }
.btn-ic-wa-off:hover { background:rgba(148,163,184,0.30); color:#64748b; }

/* ════════════════════════════════════════════════════
   UTILITY CLASSES — Design System
   Todos usan CSS vars → dark mode automático
════════════════════════════════════════════════════ */

/* ── Chips / Badges ── */
.chip {
  display:inline-flex; align-items:center; gap:3px;
  padding:2px 8px; border-radius:100px;
  font-size:11px; font-weight:600; line-height:1.6;
  border:1px solid transparent; white-space:nowrap;
}
.chip-sm { padding:1px 7px; font-size:10px; }
.chip-md { padding:3px 10px; font-size:11.5px; }
.chip-ok      { background:var(--s-ok-bg);      color:var(--s-ok);      border-color:var(--s-ok-brd); }
.chip-warn    { background:var(--s-warn-bg);    color:var(--s-warn);    border-color:var(--s-warn-brd); }
.chip-err     { background:var(--s-err-bg);     color:var(--s-err);     border-color:var(--s-err-brd); }
.chip-info    { background:var(--s-info-bg);    color:var(--s-info);    border-color:var(--s-info-brd); }
.chip-blue    { background:var(--ac-soft);      color:var(--ac-tx);     border-color:transparent; }
.chip-purple  { background:var(--s-purple-bg);  color:var(--s-purple);  border-color:var(--s-purple-brd); }
.chip-neutral { background:var(--s-neutral-bg); color:var(--s-neutral); border-color:var(--s-neutral-brd); }

/* Dark mode: chips con colores sólidos y visibles — las variables usan rgba(.13)
   que resultan casi invisibles sobre fondos oscuros.                           */
:root[data-tema="oscuro"] .chip-ok      { background:#14532d !important; color:#86efac !important; border-color:#166534   !important; }
:root[data-tema="oscuro"] .chip-warn    { background:#451a03 !important; color:#fcd34d !important; border-color:#92400e   !important; }
:root[data-tema="oscuro"] .chip-err     { background:#450a0a !important; color:#fca5a5 !important; border-color:#7f1d1d   !important; }
:root[data-tema="oscuro"] .chip-info    { background:#1e3a5f !important; color:#93c5fd !important; border-color:#2d5a9e   !important; }
:root[data-tema="oscuro"] .chip-blue    { background:#1e3a5f !important; color:#93c5fd !important; border-color:transparent !important; }
:root[data-tema="oscuro"] .chip-purple  { background:#2e1065 !important; color:#c4b5fd !important; border-color:#4c1d95   !important; }
:root[data-tema="oscuro"] .chip-neutral { background:#1e293b !important; color:#94a3b8 !important; border-color:#334155   !important; }

/* ── Alert banners ── */
.alert-banner {
  display:flex; align-items:center; gap:10px;
  padding:12px 16px; border-radius:8px; font-size:13px; line-height:1.5;
  border:1px solid transparent; border-left-width:4px; margin-bottom:16px;
  flex-wrap:nowrap;
}
.alert-banner svg { flex-shrink:0; }
@media (max-width:480px) {
  .alert-banner { font-size:12px; padding:10px 12px; gap:8px; }
}
.alert-banner p { margin:0; }
.alert-banner.ok   { background:var(--s-ok-bg);   color:var(--s-ok);   border-color:var(--s-ok-brd);   border-left-color:var(--s-ok); }
.alert-banner.warn { background:var(--s-warn-bg);  color:var(--s-warn); border-color:var(--s-warn-brd);  border-left-color:var(--s-warn); }
.alert-banner.err  { background:var(--s-err-bg);   color:var(--s-err);  border-color:var(--s-err-brd);   border-left-color:var(--s-err); }
.alert-banner.info { background:var(--s-info-bg);  color:var(--s-info); border-color:var(--s-info-brd);  border-left-color:var(--s-info); }

/* ════════════════════════════════════════════════════
   MODULE LIST PATTERNS — Shared across all list pages
   (clientes, cotizaciones, auditorias, certificados, envios)
   Modules may define matching local rules; they will safely override.
════════════════════════════════════════════════════ */

/* ── Table wrapper card ── */
.table-wrap {
  background:var(--bg-surface); border-radius:12px;
  border:1px solid var(--brd); overflow:hidden;
  box-shadow:0 1px 4px rgba(0,0,0,.04);
}

/* ── Toolbar (search + filters + CTA) ── */
.toolbar {
  display:flex; align-items:center; gap:10px;
  margin-bottom:20px; flex-wrap:wrap;
}
.toolbar form { display:flex; align-items:center; gap:8px; flex-wrap:wrap; flex:1; }
.toolbar .search-wrap { position:relative; flex:1; min-width:180px; max-width:320px; }
.toolbar .search-wrap input[type=search],
.toolbar .search-wrap input[type=text] {
  width:100%; padding:8px 10px 8px 34px;
  border:1.5px solid var(--brd); border-radius:8px;
  font-size:13px; background:var(--bg-surface); color:var(--tx);
}
.toolbar .search-wrap input:focus { border-color:var(--ac); }
.toolbar .search-icon {
  position:absolute; left:10px; top:50%; transform:translateY(-50%);
  color:var(--tx3); pointer-events:none;
}

/* ── Pagination ── */
.pagination {
  display:flex; align-items:center; justify-content:space-between;
  padding:14px 20px; border-top:1px solid var(--brd); flex-wrap:wrap; gap:8px;
}
.pagination-info { font-size:12px; color:var(--tx2); }
.pagination-btns { display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
.page-btn {
  min-width:32px; height:32px; padding:0 8px;
  display:inline-flex; align-items:center; justify-content:center;
  border:1px solid var(--brd); border-radius:7px; font-size:13px;
  text-decoration:none; color:var(--tx); background:var(--bg-surface);
  transition:all .13s; cursor:pointer; font-weight:500;
}
.page-btn:hover  { border-color:var(--ac); color:var(--ac); background:var(--ac-soft); }
.page-btn.active { background:var(--ac); border-color:var(--ac); color:#fff; }
.page-btn.disabled { opacity:.4; pointer-events:none; }

/* ── Empty state ── */
.empty-state {
  padding:48px 24px; text-align:center;
  color:var(--tx2); font-size:13px;
}
.empty-state svg { color:var(--tx3); margin-bottom:10px; }
.empty-state p { margin:0 0 4px; }

/* ════════════════════════════════════════════════════════════════════════════
   DESIGN SYSTEM — Badge & Chip components
   Tamaño estándar: 3×10 px | radio 100px | 11px 600w
   Uso: <span class="badge-green">Activo</span>
   ════════════════════════════════════════════════════════════════════════════ */

/* ── Base badge-* (6 semánticos) ─────────────────────────────────────────── */
.badge-green  { background:var(--s-ok-bg)      !important; color:var(--s-ok)      !important; border:1px solid var(--s-ok-brd)      !important; padding:3px 10px; border-radius:100px; font-size:11px; font-weight:600; white-space:nowrap; display:inline-flex !important; align-items:center; flex-shrink:0; }
.badge-amber  { background:var(--s-warn-bg)    !important; color:var(--s-warn)    !important; border:1px solid var(--s-warn-brd)    !important; padding:3px 10px; border-radius:100px; font-size:11px; font-weight:600; white-space:nowrap; display:inline-flex !important; align-items:center; flex-shrink:0; }
.badge-red    { background:var(--s-err-bg)     !important; color:var(--s-err)     !important; border:1px solid var(--s-err-brd)     !important; padding:3px 10px; border-radius:100px; font-size:11px; font-weight:600; white-space:nowrap; display:inline-flex !important; align-items:center; flex-shrink:0; }
.badge-blue   { background:var(--ac-soft)      !important; color:var(--ac-tx)     !important; border:1px solid rgba(29,78,216,.15)   !important; padding:3px 10px; border-radius:100px; font-size:11px; font-weight:600; white-space:nowrap; display:inline-flex !important; align-items:center; flex-shrink:0; }
.badge-gray   { background:var(--s-neutral-bg) !important; color:var(--s-neutral) !important; border:1px solid var(--s-neutral-brd) !important; padding:3px 10px; border-radius:100px; font-size:11px; font-weight:600; white-space:nowrap; display:inline-flex !important; align-items:center; flex-shrink:0; }
.badge-purple { background:var(--s-purple-bg)  !important; color:var(--s-purple)  !important; border:1px solid var(--s-purple-brd)  !important; padding:3px 10px; border-radius:100px; font-size:11px; font-weight:600; white-space:nowrap; display:inline-flex !important; align-items:center; flex-shrink:0; }

/* ── Aliases semánticos del sistema — estructura compartida ─────────────────
   Usar cuando el estado es conocido en PHP (no dinámico desde BD).
   Basta con una sola clase: <span class="badge-activo">Activo</span>         */
.badge-activo,.badge-vigente,.badge-aprobado,.badge-entregado,
.badge-pagado,.badge-validado,.badge-aceptada,
.badge-prospecto,.badge-pendiente,.badge-por-vencer,.badge-pendiente-pago,
.badge-vencido,.badge-rechazado,.badge-rechazada,.badge-devuelto,
.badge-suspendido,.badge-revocado,
.badge-inactivo,.badge-borrador,.badge-por-preparar,.badge-anulado,
.badge-en-transito,.badge-en-proceso {
  padding:3px 10px !important; border-radius:100px; font-size:11px;
  font-weight:600; white-space:nowrap !important;
  display:inline-flex !important; align-items:center; flex-shrink:0;
  border:1px solid transparent;
}
/* verde */
.badge-activo,.badge-vigente,.badge-aprobado,.badge-entregado,.badge-pagado,.badge-validado,.badge-aceptada
  { background:var(--s-ok-bg) !important; color:var(--s-ok) !important; border-color:var(--s-ok-brd) !important; }
/* ámbar */
.badge-prospecto,.badge-pendiente,.badge-por-vencer,.badge-pendiente-pago
  { background:var(--s-warn-bg) !important; color:var(--s-warn) !important; border-color:var(--s-warn-brd) !important; }
/* rojo */
.badge-vencido,.badge-rechazado,.badge-rechazada,.badge-devuelto,.badge-suspendido,.badge-revocado
  { background:var(--s-err-bg) !important; color:var(--s-err) !important; border-color:var(--s-err-brd) !important; }
/* gris */
.badge-inactivo,.badge-borrador,.badge-por-preparar,.badge-anulado
  { background:var(--s-neutral-bg) !important; color:var(--s-neutral) !important; border-color:var(--s-neutral-brd) !important; }
/* azul */
.badge-en-transito,.badge-en-proceso
  { background:var(--ac-soft) !important; color:var(--ac-tx) !important; border-color:rgba(29,78,216,.15) !important; }

/* ── Estado-badge global (propuestas y cualquier módulo con data-estado) ─────
   Uso: <span class="estado-badge" data-estado="aceptada">Aceptada</span>     */
.estado-badge {
  display:inline-block; padding:3px 10px; border-radius:100px;
  font-size:11px; font-weight:600; white-space:nowrap; text-align:center;
  border:1px solid transparent; flex-shrink:0;
}
.estado-badge[data-estado="borrador"]    { background:var(--s-neutral-bg); color:var(--s-neutral); border-color:var(--s-neutral-brd); }
.estado-badge[data-estado="pendiente"]   { background:var(--s-warn-bg);    color:var(--s-warn);    border-color:var(--s-warn-brd); }
.estado-badge[data-estado="enviada"]     { background:var(--ac-soft);      color:var(--ac-tx);     border-color:rgba(29,78,216,.15); }
.estado-badge[data-estado="en_revision"] { background:var(--s-info-bg);    color:var(--s-info);    border-color:var(--s-info-brd); }
.estado-badge[data-estado="aceptada"]   { background:var(--s-ok-bg);      color:var(--s-ok);      border-color:var(--s-ok-brd); }
.estado-badge[data-estado="rechazada"]  { background:var(--s-err-bg);     color:var(--s-err);     border-color:var(--s-err-brd); }
.estado-badge[data-estado="vencida"],
.estado-badge[data-estado="modificar"]  { background:var(--s-warn-bg);    color:var(--s-warn);    border-color:var(--s-warn-brd); }
.estado-badge[data-estado="desistida"]  { background:var(--s-neutral-bg); color:var(--s-neutral); border-color:var(--s-neutral-brd); }

/* ── Protección global — evita que mobile/dark-mode rompa cualquier badge ─── */
.badge-green,.badge-amber,.badge-red,.badge-blue,.badge-gray,.badge-purple,
.badge-activo,.badge-vigente,.badge-aprobado,.badge-entregado,.badge-pagado,
.badge-validado,.badge-aceptada,.badge-prospecto,.badge-pendiente,.badge-por-vencer,
.badge-pendiente-pago,.badge-vencido,.badge-rechazado,.badge-rechazada,
.badge-devuelto,.badge-suspendido,.badge-revocado,.badge-inactivo,.badge-borrador,
.badge-por-preparar,.badge-anulado,.badge-en-transito,.badge-en-proceso,
.chip,.chip-ok,.chip-warn,.chip-err,.chip-info,.chip-neutral,.chip-sm,.chip-xs,
.estado-badge,.badge-estado,
.be-validado,.be-rechazado,.be-pendiente_validacion,.be-pendiente_voucher,
.dias-chip,.dias-badge,[class*="badge-"],[class*="chip-"],[class*="be-"] {
  flex-shrink:0 !important; white-space:nowrap !important;
  max-width:none !important; box-sizing:border-box !important;
}

/* ── Dark mode: badge-* base ─────────────────────────────────────────────── */
:root[data-tema="oscuro"] .badge-green  { background:#14532d !important; color:#86efac !important; border-color:#166534 !important; }
:root[data-tema="oscuro"] .badge-amber  { background:#451a03 !important; color:#fcd34d !important; border-color:#92400e !important; }
:root[data-tema="oscuro"] .badge-red    { background:#450a0a !important; color:#fca5a5 !important; border-color:#7f1d1d !important; }
:root[data-tema="oscuro"] .badge-blue   { background:#1e3a5f !important; color:#93c5fd !important; border-color:#2d5a9e !important; }
:root[data-tema="oscuro"] .badge-gray   { background:#1e293b !important; color:#94a3b8 !important; border-color:#334155 !important; }
:root[data-tema="oscuro"] .badge-purple { background:#2e1065 !important; color:#c4b5fd !important; border-color:#4c1d95 !important; }

/* ── Dark mode: aliases semánticos ──────────────────────────────────────── */
:root[data-tema="oscuro"] .badge-activo,:root[data-tema="oscuro"] .badge-vigente,
:root[data-tema="oscuro"] .badge-aprobado,:root[data-tema="oscuro"] .badge-entregado,
:root[data-tema="oscuro"] .badge-pagado,:root[data-tema="oscuro"] .badge-validado,
:root[data-tema="oscuro"] .badge-aceptada { background:#14532d !important; color:#86efac !important; border-color:#166534 !important; }

:root[data-tema="oscuro"] .badge-prospecto,:root[data-tema="oscuro"] .badge-pendiente,
:root[data-tema="oscuro"] .badge-por-vencer,:root[data-tema="oscuro"] .badge-pendiente-pago
  { background:#451a03 !important; color:#fcd34d !important; border-color:#92400e !important; }

:root[data-tema="oscuro"] .badge-vencido,:root[data-tema="oscuro"] .badge-rechazado,
:root[data-tema="oscuro"] .badge-rechazada,:root[data-tema="oscuro"] .badge-devuelto,
:root[data-tema="oscuro"] .badge-suspendido,:root[data-tema="oscuro"] .badge-revocado
  { background:#450a0a !important; color:#fca5a5 !important; border-color:#7f1d1d !important; }

:root[data-tema="oscuro"] .badge-inactivo,:root[data-tema="oscuro"] .badge-borrador,
:root[data-tema="oscuro"] .badge-por-preparar,:root[data-tema="oscuro"] .badge-anulado
  { background:#1e293b !important; color:#94a3b8 !important; border-color:#334155 !important; }

:root[data-tema="oscuro"] .badge-en-transito,:root[data-tema="oscuro"] .badge-en-proceso
  { background:#1e3a5f !important; color:#93c5fd !important; border-color:#2d5a9e !important; }

/* ── Dark mode: estado-badge global ─────────────────────────────────────── */
:root[data-tema="oscuro"] .estado-badge[data-estado="borrador"]    { background:#1e293b !important; color:#94a3b8 !important; border-color:#334155 !important; }
:root[data-tema="oscuro"] .estado-badge[data-estado="pendiente"]   { background:#451a03 !important; color:#fcd34d !important; border-color:#92400e !important; }
:root[data-tema="oscuro"] .estado-badge[data-estado="enviada"]     { background:#1e3a5f !important; color:#93c5fd !important; border-color:#2d5a9e !important; }
:root[data-tema="oscuro"] .estado-badge[data-estado="en_revision"] { background:#1e3a5f !important; color:#93c5fd !important; border-color:#2d5a9e !important; }
:root[data-tema="oscuro"] .estado-badge[data-estado="aceptada"]    { background:#14532d !important; color:#86efac !important; border-color:#166534 !important; }
:root[data-tema="oscuro"] .estado-badge[data-estado="rechazada"]   { background:#450a0a !important; color:#fca5a5 !important; border-color:#7f1d1d !important; }
:root[data-tema="oscuro"] .estado-badge[data-estado="vencida"],
:root[data-tema="oscuro"] .estado-badge[data-estado="modificar"]   { background:#451a03 !important; color:#fcd34d !important; border-color:#92400e !important; }
:root[data-tema="oscuro"] .estado-badge[data-estado="desistida"]   { background:#1e293b !important; color:#94a3b8 !important; border-color:#334155 !important; }

/* ── Dark mode: badges locales pagos (be-*) ─────────────────────────────── */
:root[data-tema="oscuro"] .be-validado             { background:#14532d !important; color:#86efac !important; }
:root[data-tema="oscuro"] .be-rechazado            { background:#450a0a !important; color:#fca5a5 !important; }
:root[data-tema="oscuro"] .be-pendiente_validacion { background:#1e293b !important; color:#94a3b8 !important; }
:root[data-tema="oscuro"] .be-pendiente_voucher    { background:#451a03 !important; color:#fcd34d !important; }

/* ── Chips de estado dinámicos (color desde BD via CSS custom property) ──────
   Uso: <span class="env-estado-chip" style="--chip-color:#hexcolor;">texto</span>
   Funciona en envíos, dashboard y cualquier módulo que muestre estados de envío. */
.env-estado-chip {
  --chip-color: #6b7280;
  padding: 3px 10px; border-radius: 100px; font-size: 11px; font-weight: 600;
  display: inline-block; white-space: nowrap;
  background: color-mix(in srgb, var(--chip-color) 15%, #f0f4ff);
  color: var(--chip-color);
}
:root[data-tema="oscuro"] .env-estado-chip {
  background: color-mix(in srgb, var(--chip-color) 28%, #1e293b) !important;
  color: color-mix(in srgb, var(--chip-color) 75%, #ffffff) !important;
}

/* ── Standard data table ── */
.data-table { width:100%; border-collapse:collapse; }
.data-table th {
  font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.5px;
  color:var(--tx2); padding:11px 16px; background:var(--bg-th);
  border-bottom:1px solid var(--brd); text-align:left;
}
.data-table td { padding:12px 16px; font-size:13px; border-bottom:1px solid var(--brd); vertical-align:middle; color:var(--tx); }
.data-table tr:last-child td { border-bottom:none; }
.data-table tbody tr:hover td { background:var(--bg-hover); }

/* ── Filter active indicator ── */
.filter-active-tag {
  display:inline-flex; align-items:center; gap:5px;
  background:var(--ac-soft); color:var(--ac-tx);
  border:1px solid var(--ac); border-radius:100px;
  font-size:11px; font-weight:600; padding:2px 8px;
  white-space:nowrap;
}
.filter-active-tag .close { cursor:pointer; margin-left:2px; opacity:.7; }
.filter-active-tag .close:hover { opacity:1; }

/* ── Icon squares ── */
.icon-sq {
  width:38px; height:38px; border-radius:9px;
  display:flex; align-items:center; justify-content:center; flex-shrink:0;
}
.icon-sq-sm { width:28px; height:28px; border-radius:7px; }
.icon-sq-ok     { background:var(--s-ok-bg);      color:var(--s-ok); }
.icon-sq-warn   { background:var(--s-warn-bg);    color:var(--s-warn); }
.icon-sq-err    { background:var(--s-err-bg);     color:var(--s-err); }
.icon-sq-blue   { background:var(--ac-soft);      color:var(--ac); }
.icon-sq-purple { background:var(--s-purple-bg);  color:var(--s-purple); }

/* ── Urgency chips extensiones semánticas ── */
.dias-urgente.red  { background:var(--s-err-bg);   color:var(--s-err); }
.dias-urgente.warn { background:var(--s-warn-bg);  color:var(--s-warn); }
.dias-urgente.info { background:var(--s-info-bg);  color:var(--s-info); }

/* ── Stat-card dark mode (icon box) ── */
:root[data-tema="oscuro"] .stat-icon { background:rgba(255,255,255,.06) !important; }
:root[data-tema="oscuro"] .stat-card:hover { box-shadow:0 6px 24px rgba(0,0,0,.4) !important; }

/* ── Mant-table dark mode via vars ── */
.mant-table tr:hover td { background:var(--bg-surface2); }
.mant-table th { background:var(--bg-th); }
/* ── Dark mode: hover de filas (unificado con tokens) ── */
:root[data-tema="oscuro"] tr:hover td                        { background: var(--bg-hover) !important; }
:root[data-tema="oscuro"] .mini-table tr:hover td,
:root[data-tema="oscuro"] .data-table tr:hover td            { background: rgba(59,130,246,.055) !important; }
/* ── Dark mode: botones de acción y paginación ── */
:root[data-tema="oscuro"] .filtro-btn,
:root[data-tema="oscuro"] .btn-accion,
:root[data-tema="oscuro"] .notif-btn-sm,
:root[data-tema="oscuro"] .pag-btn,
:root[data-tema="oscuro"] .page-btn,
:root[data-tema="oscuro"] .btn-icon                          { background: var(--bg-surface) !important; border-color: var(--brd) !important; color: var(--tx2) !important; }
:root[data-tema="oscuro"] .filtro-btn:hover,
:root[data-tema="oscuro"] .filtro-btn.active,
:root[data-tema="oscuro"] .btn-accion:hover,
:root[data-tema="oscuro"] .notif-btn-sm:hover,
:root[data-tema="oscuro"] .pag-btn:hover,
:root[data-tema="oscuro"] .pag-btn.active,
:root[data-tema="oscuro"] .page-btn:hover,
:root[data-tema="oscuro"] .page-btn.active,
:root[data-tema="oscuro"] .btn-icon:hover                    { background: var(--bg-surface2) !important; border-color: var(--brd) !important; color: var(--tx) !important; }
:root[data-tema="oscuro"] .pagination-info                   { color: var(--tx2) !important; }
:root[data-tema="oscuro"] .notif-item:hover                  { background: var(--bg-hover) !important; }
:root[data-tema="oscuro"] .notif-item.no-leida               { background: var(--bg-surface) !important; }
:root[data-tema="oscuro"] .ip-tag                            { background: var(--bg-surface2) !important; color: var(--tx2) !important; border-color: var(--brd) !important; }
/* ── Dark mode: flecha de selects visible en oscuro ── */
:root[data-tema="oscuro"] select:not([multiple]):not([size]) {
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%238b96b4' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E") !important;
  background-repeat:no-repeat !important; background-position:right 10px center !important;
}
/* ── Dark mode: campos bloqueados (readonly / disabled) ── */
:root[data-tema="oscuro"] input:not([type=checkbox]):not([type=radio]):not([type=submit]):not([type=button]):not([type=range])[readonly],
:root[data-tema="oscuro"] input:not([type=checkbox]):not([type=radio]):not([type=submit]):not([type=button]):not([type=range]):disabled,
:root[data-tema="oscuro"] textarea[readonly],
:root[data-tema="oscuro"] textarea:disabled,
:root[data-tema="oscuro"] select:disabled                    { background: var(--bg-th) !important; border-color: var(--brd) !important; color: var(--tx3) !important; opacity:1 !important; cursor:default; }
/* ── Dark mode: upload areas ── */
:root[data-tema="oscuro"] .file-actual                       { background: var(--bg-surface2) !important; border-color: var(--brd) !important; color: var(--tx2) !important; }
:root[data-tema="oscuro"] .file-info                         { background: var(--bg-hover) !important; border-color: var(--brd) !important; color: var(--tx) !important; }
:root[data-tema="oscuro"] .dropzone                          { background: var(--bg-surface) !important; border-color: var(--brd) !important; }
:root[data-tema="oscuro"] .dropzone:hover,
:root[data-tema="oscuro"] .dropzone.dragover                 { background: var(--bg-hover) !important; border-color: var(--ac-mid) !important; }
:root[data-tema="oscuro"] .upload-zone                       { background: var(--bg-surface) !important; border-color: var(--brd) !important; color: var(--tx2) !important; }
:root[data-tema="oscuro"] .upload-zone:hover                 { background: var(--bg-hover) !important; border-color: var(--ac-mid) !important; }
/* ── Dark mode: cert-check items y norma tags ── */
:root[data-tema="oscuro"] .cert-check-item                   { background: var(--bg-surface) !important; border-color: var(--brd) !important; color: var(--tx) !important; }
:root[data-tema="oscuro"] .cert-check-item:hover,
:root[data-tema="oscuro"] .cert-check-item.checked           { background: var(--bg-surface2) !important; border-color: var(--brd) !important; }
:root[data-tema="oscuro"] .cert-check-norma                  { background: var(--bg-surface2) !important; color: var(--tx2) !important; }
:root[data-tema="oscuro"] .norma-tag                         { background: rgba(59,130,246,.13) !important; color: #93C5FD !important; border-color: rgba(59,130,246,.22) !important; }
/* .badge-blue dark mode: definido en el bloque de design tokens arriba (#1e3a5f) */
:root[data-tema="oscuro"] .dir-check                         { background: var(--bg-th) !important; border-color: var(--brd) !important; }
:root[data-tema="oscuro"] .dir-preview,
:root[data-tema="oscuro"] .dir-fiscal-preview                { background: var(--bg-surface2) !important; border-color: var(--brd) !important; color: var(--tx) !important; }
:root[data-tema="oscuro"] .cot-num                           { background: var(--bg-surface2) !important; color: var(--tx2) !important; border-color: var(--brd) !important; }
:root[data-tema="oscuro"] .feed-tipo                         { background: var(--bg-surface2) !important; color: var(--tx2) !important; border-color: var(--brd) !important; }
:root[data-tema="oscuro"] .feed-avatar                       { background: var(--bg-hover) !important; color: var(--tx2) !important; }
:root[data-tema="oscuro"] .cert-badge                        { background: var(--bg-surface2) !important; color: var(--tx2) !important; border-color: var(--brd) !important; }
/* ── Dark mode: norma items (propuestas) ── */
:root[data-tema="oscuro"] .norma-item                        { background: var(--bg-surface) !important; border-color: var(--brd) !important; color: var(--tx) !important; }
:root[data-tema="oscuro"] .tipo-btn                          { background: var(--bg-surface) !important; border-color: var(--brd) !important; color: var(--tx2) !important; }
:root[data-tema="oscuro"] .tipo-btn.active                   { background: var(--bg-surface2) !important; border-color: var(--brd) !important; color: var(--tx) !important; }
/* ── Dark mode: propuestas ver (seg panels, modals) ── */
:root[data-tema="oscuro"] .seg-panel-hdr                     { background: var(--bg-th) !important; border-color: var(--brd) !important; }
:root[data-tema="oscuro"] .seg-form-wrap                     { background: var(--bg-th) !important; }
:root[data-tema="oscuro"] .modal-close                       { background: var(--bg-surface2) !important; color: var(--tx2) !important; }
:root[data-tema="oscuro"] .modal-hdr                         { background: var(--bg-surface) !important; border-color: var(--brd) !important; }
:root[data-tema="oscuro"] .modal-box                         { background: var(--bg-surface) !important; border-color: var(--brd) !important; color: var(--tx) !important; }
/* ── Dark mode: modal-fp (upload preview) ── */
:root[data-tema="oscuro"] .modal-fp                          { background: rgba(52,211,153,.08) !important; border-color: rgba(52,211,153,.20) !important; }
:root[data-tema="oscuro"] .modal-version-info                { background: rgba(245,158,11,.10) !important; border-color: rgba(245,158,11,.22) !important; color: #fbbf24 !important; }
/* ── Dark mode: calendario auditorías ── */
:root[data-tema="oscuro"] .cal-day.today                     { background: var(--bg-surface2) !important; }
:root[data-tema="oscuro"] .cal-day.today .day-num            { color: var(--tx2) !important; }
:root[data-tema="oscuro"] .cal-day.empty                     { background: var(--bg-th) !important; }
:root[data-tema="oscuro"] .cal-day                           { border-color: var(--brd) !important; }
.field { display:flex; flex-direction:column; gap:5px; margin-bottom:14px; }
.field label { font-size:12px; font-weight:600; color:var(--gris-texto); }
.field input, .field textarea, .field select {
  padding:9px 12px; border:1.5px solid var(--gris-borde); border-radius:9px;
  font-size:13px; font-family:'Inter',sans-serif; color:var(--gris-dark);
  background:#fafbfe; outline:none; width:100%;
  transition:border-color 0.2s; -webkit-appearance:none;
}
.field input:focus, .field textarea:focus, .field select:focus {
  border-color:var(--azul); background:#fff;
  box-shadow:0 0 0 3px rgba(26,58,143,0.09); outline:none;
}
.field textarea { min-height:72px; resize:vertical; }
/* ── Estados deshabilitado ── */
.btn:disabled, button:disabled { opacity:0.5; cursor:not-allowed; pointer-events:none; }
input:disabled, select:disabled, textarea:disabled {
  opacity:0.6; cursor:not-allowed;
  background:var(--gris-bg) !important; color:var(--gris-texto) !important;
  border-color:var(--gris-borde) !important; box-shadow:none !important;
}
:root[data-tema="oscuro"] .field input:not([type=checkbox]):not([type=radio]):not([type=submit]):not([type=button]),
:root[data-tema="oscuro"] .field select,
:root[data-tema="oscuro"] .field textarea { background:#182336 !important; border-color:#1A2840 !important; color:#ECF1FB !important; font-family:'Inter',sans-serif !important; }
:root[data-tema="oscuro"] .field label { color:#8893A8 !important; font-family:'Inter',sans-serif !important; }
:root[data-tema="oscuro"] .field input:focus,
:root[data-tema="oscuro"] .field textarea:focus,
:root[data-tema="oscuro"] .field select:focus { background:#182336 !important; border-color:#60A5FA !important; box-shadow:0 0 0 3px rgba(91,158,245,0.14) !important; }

/* ── Nombres clickeables en tablas ── */
.row-link { color:var(--tx); text-decoration:none; font-weight:600; }
.row-link:hover { color:var(--azul); text-decoration:underline; text-underline-offset:2px; }

/* ── Dark mode: cards de tabla en móvil ── */
@media (max-width: 768px) {
  :root[data-tema="oscuro"] .data-table tr { background:#111927 !important; border-color:#1A2840 !important; }
  :root[data-tema="oscuro"] .data-table tr:hover td { background:transparent !important; }
  :root[data-tema="oscuro"] .data-table td { color:#ECF1FB !important; border-color:#1A2840 !important; font-family:'Inter',sans-serif !important; }
  :root[data-tema="oscuro"] .data-table td:last-child { border-top-color:#1A2840 !important; }
  :root[data-tema="oscuro"] .data-table td[data-label]::before { color:#8893A8 !important; font-weight:500 !important; }
  :root[data-tema="oscuro"] .table-wrap { background:#111927 !important; border-color:#1A2840 !important; }
  :root[data-tema="oscuro"] .action-btns { border-color:#1A2840 !important; background:#111927 !important; }
  :root[data-tema="oscuro"] .toolbar { background:#0C1118 !important; border-color:#1A2840 !important; }
  :root[data-tema="oscuro"] .search-box input { background:#182336 !important; color:#ECF1FB !important; border-color:#1A2840 !important; }
  :root[data-tema="oscuro"] .filter-select { background:#182336 !important; color:#ECF1FB !important; border-color:#1A2840 !important; }
}
@media (max-width: 640px) {
  :root[data-tema="oscuro"] .mant-table tr { background:#111927 !important; border-color:#1A2840 !important; }
  :root[data-tema="oscuro"] .mant-table td { color:#ECF1FB !important; font-family:'Inter',sans-serif !important; }
}

/* ── Espaciado normalizado entre módulos ── */
/* Grids de métricas/KPIs: gap y margin-bottom estándar */
.stat-row, .stats-grid, .rep-kpis, .quick-grid {
  gap: 14px !important;
}
.stat-row, .rep-kpis { margin-bottom: 20px !important; }
/* Separación estándar entre secciones */
.panel, .det-card, .form-card { margin-bottom: 20px; }
/* Header de página uniforme */
.page-header { margin-bottom: 20px !important; }

/* ── Override compacto en móvil — DEBE ir después de las reglas globales
   para ganar la cascada y vencer también inline styles (via !important) ── */
@media (max-width: 768px) {
  .page-header                              { margin-bottom: 12px !important; }
  .stat-row, .rep-kpis                      { margin-bottom: 12px !important; }
  .stat-row, .stats-grid, .rep-kpis, .quick-grid { gap: 10px !important; }
  .panel, .det-card, .form-card             { margin-bottom: 12px !important; }
  .card                                     { padding: 14px !important; }
  .page-header h1                           { font-size: 17px !important; }
  .page-header p                            { font-size: 12px !important; margin-top: 2px !important; }
  /* En tarjeta móvil todos los <td> alineados a la izquierda,
     EXCEPTO las celdas que contienen badges/chips de estado (td.td-estado, td.td-badge).
     Esas celdas mantienen su alineación original para que el badge se vea correctamente. */
  .data-table td:not(.td-estado):not(.td-badge) { text-align: left !important; }
  /* Los td que SÍ contienen badges: alinear a la izquierda pero mantener el badge inline */
  .data-table td.td-estado,
  .data-table td.td-badge                   { text-align: left !important; }
  /* Garantía de colores: los badges dentro de tablas nunca heredan color/bg del td */
  .data-table td .badge-green,
  .data-table td .badge-amber,
  .data-table td .badge-red,
  .data-table td .badge-blue,
  .data-table td .badge-gray,
  .data-table td .badge-purple,
  .data-table td .chip,
  .data-table td .chip-ok,
  .data-table td .chip-warn,
  .data-table td .chip-err,
  .data-table td .chip-info,
  .data-table td .chip-neutral,
  .data-table td .estado-badge,
  .data-table td .badge-estado,
  .data-table td [class*="be-"],
  .data-table td .dias-chip                 { display: inline-flex !important; }
}
@media (max-width: 480px) {
  .page-header                              { margin-bottom: 10px !important; }
  .page-header h1                           { font-size: 15px !important; }
  .card                                     { padding: 12px !important; }
}

/* ── Protección universal de badges en TODOS los breakpoints ────────────────
   Aplica desde 768px hacia abajo. Garantiza que los badges con inline style
   (background:X;color:Y) de envíos, cotizaciones, auditorías, alertas etc.
   nunca pierdan sus colores por herencia del td o del dark mode override.    */
@media (max-width: 768px) {
  /* Spans de estado con inline style: preservar background y color definidos */
  .data-table td span[style*="background"],
  .data-table td span[style*="background-color"] {
    opacity: 1 !important;
    filter: none !important;
  }
  /* Chips locales de envíos (estado dinámico con color_hex de BD) */
  .data-table td .env-estado-chip,
  .data-table td span.chip {
    display: inline-flex !important;
    align-items: center !important;
    white-space: nowrap !important;
  }
  /* Padding de celdas compactado en mobile para que los badges quepan bien */
  .data-table td { padding: 8px 10px !important; }
  .data-table th { padding: 8px 10px !important; }
}

@media (max-width:640px) {
  .mant-table thead { display:none; }
  .mant-table, .mant-table tbody, .mant-table tr, .mant-table td { display:block; width:100%; }
  .mant-table tr { border-radius:8px; border:1px solid var(--gris-borde); margin-bottom:8px; padding:12px 14px; background:var(--gris-card); }
  .mant-table td { padding:3px 0; border:none; }
}

/* ── Expansión de contenido cuando el sidebar colapsa ─────────────────
   Todos los contenedores con max-width fija se expanden fluidamente.
   La transición está en cada elemento individual (no en el body).
   ─────────────────────────────────────────────────────────────────── */
.form-card, .det-card, .estado-panel,
.edit-context-card, .table-wrap, .panel,
.env-header, .cliente-header, .cert-header, .aud-header,
.rep-card, .cot-card, .kanban-wrap, .doc-card {
  transition: max-width 0.25s ease;
}

body.sidebar-collapsed .form-card,
body.sidebar-collapsed .det-card,
body.sidebar-collapsed .estado-panel,
body.sidebar-collapsed .edit-context-card,
body.sidebar-collapsed .table-wrap,
body.sidebar-collapsed .panel,
body.sidebar-collapsed .env-header,
body.sidebar-collapsed .cliente-header,
body.sidebar-collapsed .cert-header,
body.sidebar-collapsed .aud-header,
body.sidebar-collapsed .rep-card,
body.sidebar-collapsed .cot-card,
body.sidebar-collapsed .kanban-wrap,
body.sidebar-collapsed .doc-card {
  max-width: 100% !important;
}

/* Inline max-width en cualquier valor común */
body.sidebar-collapsed [style*="max-width:760px"],
body.sidebar-collapsed [style*="max-width: 760px"],
body.sidebar-collapsed [style*="max-width:820px"],
body.sidebar-collapsed [style*="max-width: 820px"],
body.sidebar-collapsed [style*="max-width:860px"],
body.sidebar-collapsed [style*="max-width: 860px"],
body.sidebar-collapsed [style*="max-width:900px"],
body.sidebar-collapsed [style*="max-width: 900px"],
body.sidebar-collapsed [style*="max-width:940px"],
body.sidebar-collapsed [style*="max-width: 940px"],
body.sidebar-collapsed [style*="max-width:960px"],
body.sidebar-collapsed [style*="max-width: 960px"],
body.sidebar-collapsed [style*="max-width:1000px"],
body.sidebar-collapsed [style*="max-width: 1000px"],
body.sidebar-collapsed [style*="max-width:1100px"],
body.sidebar-collapsed [style*="max-width: 1100px"] {
  max-width: 100% !important;
  transition: max-width 0.25s ease;
}

/* ── Tom Select — match app design ── */
.ts-wrapper { width: 100%; }
.ts-wrapper .ts-control {
  padding: 8px 12px !important; border: 1.5px solid var(--gris-borde) !important;
  border-radius: 9px !important; font-size: 13px !important;
  font-family: 'Inter', sans-serif !important; color: var(--gris-dark) !important;
  background: var(--gris-bg) !important; box-shadow: none !important;
  min-height: unset !important; cursor: pointer !important; gap: 4px;
}
.ts-wrapper.focus .ts-control, .ts-wrapper .ts-control:focus-within {
  border-color: var(--azul) !important; background: var(--gris-card) !important;
  box-shadow: 0 0 0 3px rgba(29,78,216,0.09) !important;
}
.ts-wrapper .ts-control input { font-size: 13px !important; font-family: 'Inter', sans-serif !important; color: var(--gris-dark) !important; }
.ts-wrapper .ts-control .item { font-size: 13px; color: var(--gris-dark); }
.ts-dropdown {
  border: 1.5px solid var(--gris-borde) !important; border-radius: 9px !important;
  box-shadow: 0 8px 28px rgba(0,0,0,0.12) !important; font-size: 13px !important;
  font-family: 'Inter', sans-serif !important; margin-top: 3px !important;
  background: var(--gris-card) !important; color: var(--gris-dark) !important; overflow: hidden;
}
.ts-dropdown .ts-dropdown-content { max-height: 220px; }
.ts-dropdown .option { padding: 9px 13px !important; color: var(--gris-dark) !important; }
.ts-dropdown .option.active,
.ts-dropdown .option:hover { background: var(--azul-light) !important; color: var(--azul) !important; }
.ts-dropdown .option.selected { background: var(--azul-light) !important; color: var(--azul) !important; font-weight: 600; }
.ts-dropdown .ts-dropdown-content input.ts-input { display: none; }
/* Arrow icon */
.ts-wrapper:not(.multi) .ts-control:after {
  content: ''; border-left: 5px solid transparent; border-right: 5px solid transparent;
  border-top: 5px solid var(--gris-texto); position: absolute; right: 12px; top: 50%;
  transform: translateY(-50%); pointer-events: none;
}
/* Dark mode overrides — TomSelect */
:root[data-tema="oscuro"] .ts-wrapper .ts-control { background: #182336 !important; border-color: #1A2840 !important; color: #ECF1FB !important; font-family:'Inter',sans-serif !important; }
:root[data-tema="oscuro"] .ts-wrapper.focus .ts-control { border-color: #60A5FA !important; background: #182336 !important; box-shadow: 0 0 0 3px rgba(91,158,245,0.14) !important; }
:root[data-tema="oscuro"] .ts-wrapper .ts-control input { color: #ECF1FB !important; background: transparent !important; font-family:'Inter',sans-serif !important; }
:root[data-tema="oscuro"] .ts-wrapper .ts-control .item { color: #ECF1FB !important; }
:root[data-tema="oscuro"] .ts-dropdown { background: #111927 !important; border-color: #1A2840 !important; color: #ECF1FB !important; box-shadow: 0 8px 32px rgba(0,0,0,0.45) !important; font-family:'Inter',sans-serif !important; }
:root[data-tema="oscuro"] .ts-dropdown .option { color: #ECF1FB !important; }
:root[data-tema="oscuro"] .ts-dropdown .option.active,
:root[data-tema="oscuro"] .ts-dropdown .option:hover { background: #252d3d !important; color: #94a3b8 !important; }
:root[data-tema="oscuro"] .ts-dropdown .option.selected { background: #252d3d !important; color: #94a3b8 !important; }
:root[data-tema="oscuro"] .ts-wrapper:not(.multi) .ts-control:after { border-top-color: #8893A8; }

/* ── Modal overlay global (compartido entre módulos) ── */
.modal-overlay {
  display: none; position: fixed; inset: 0;
  background: rgba(10,20,60,.45); z-index: 1000;
  align-items: center; justify-content: center;
  padding: 16px; backdrop-filter: blur(3px);
}
.modal-overlay.open { display: flex; }
.modal-box {
  background: var(--bg-surface, #fff); border-radius: 16px;
  box-shadow: 0 24px 64px rgba(0,0,0,.22);
  width: 100%; max-height: 90vh; overflow-y: auto;
  animation: modalIn .2s ease;
}
@keyframes modalIn { from { opacity:0; transform:translateY(12px) scale(.97); } to { opacity:1; transform:none; } }
/* ── Botón X cierre estandarizado (usado en modal-box internos) ── */
.modal-x-close {
  flex-shrink: 0; width: 28px; height: 28px; padding: 0;
  background: none; border: none; cursor: pointer; border-radius: 6px;
  display: flex; align-items: center; justify-content: center;
  color: var(--tx3, #9ca3af); transition: background .15s, color .15s;
}
.modal-x-close:hover { background: var(--bg-hover, #f3f4f6); color: var(--tx, #1a1d2e); }
:root[data-tema="oscuro"] .modal-x-close:hover { background: var(--bg-surface2) !important; color: var(--tx) !important; }

/* ── Navegación contextual — breadcrumb (EntityBreadcrumb) ── */
.ggi-bc {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 1px;
  margin-bottom: 14px;
  font-size: 13px;
  line-height: 1;
}
.ggi-bc-link {
  color: var(--tx3, #9ca3af);
  text-decoration: none;
  font-weight: 500;
  padding: 2px 3px;
  border-radius: 4px;
  transition: color .15s, background .15s;
  white-space: nowrap;
  max-width: 200px;
  overflow: hidden;
  text-overflow: ellipsis;
}
.ggi-bc-link:hover { color: var(--azul, #1a3a8f); background: var(--bg-hover, #f3f4f6); }
:root[data-tema="oscuro"] .ggi-bc-link:hover { color: var(--azul-claro, #93c5fd) !important; background: var(--bg-surface2) !important; }
.ggi-bc-sep { color: var(--tx3, #9ca3af); flex-shrink: 0; opacity: .45; margin: 0 1px; vertical-align: middle; }
.ggi-bc-cur {
  color: var(--tx, #1a1d2e);
  font-weight: 600;
  white-space: nowrap;
  max-width: 280px;
  overflow: hidden;
  text-overflow: ellipsis;
  padding: 2px 3px;
}
/* Alias retrocompatible */
.page-back-link { display:none; }

/* ── ShortcutManager — Modal de ayuda ── */
.sm-group { margin-bottom: 20px; }
.sm-group:last-child { margin-bottom: 0; }
.sm-group-title {
  font-size: 10px; font-weight: 700; color: var(--gris-texto, #6b7280);
  text-transform: uppercase; letter-spacing: .6px;
  padding-bottom: 8px; margin-bottom: 8px;
  border-bottom: 1px solid var(--gris-borde, #e8eaf0);
}
.sm-row {
  display: flex; align-items: center; justify-content: space-between;
  gap: 16px; padding: 6px 0;
}
.sm-keys { display: flex; align-items: center; gap: 3px; flex-shrink: 0; }
.sm-desc { font-size: 13px; color: var(--gris-dark, #374151); }
.sm-plus { font-size: 11px; color: var(--gris-texto, #9ca3af); padding: 0 1px; }
kbd {
  display: inline-flex; align-items: center; justify-content: center;
  background: var(--gris-bg, #f3f4f6); border: 1px solid var(--gris-borde, #d1d5db);
  border-bottom-width: 2px; border-radius: 5px;
  font-family: 'Inter', ui-monospace, monospace; font-size: 11px; font-weight: 600;
  color: var(--gris-dark, #374151); padding: 2px 7px; min-width: 24px; line-height: 1.5;
}
/* Dark mode ayuda */
:root[data-tema="oscuro"] .sm-desc { color: #94a3b8 !important; }
:root[data-tema="oscuro"] kbd { background: #182336 !important; border-color: #334155 !important; color: #94a3b8 !important; }
:root[data-tema="oscuro"] .sm-group-title { color: #4A5568 !important; border-color: #1A2840 !important; }

/* ── Fila activa en listados (navegación ↑↓ / click) ──
   Mismo lenguaje visual que la selección por checkbox (.bulk-selected):
   fondo suave + barra de acento a la izquierda, theme-aware. Sin outline duro. */
tr.sm-row-active td { background: rgba(29,78,216,.06) !important; }
tr.sm-row-active td:first-child { box-shadow: inset 3px 0 0 var(--ac, #1d4ed8); }
:root[data-tema="oscuro"] tr.sm-row-active td { background: rgba(59,130,246,.10) !important; }
:root[data-tema="oscuro"] tr.sm-row-active td:first-child { box-shadow: inset 3px 0 0 var(--ac, #3b82f6); }

/* ── Selección en móvil (listados en formato tarjeta): acento limpio, sin relleno pesado ── */
@media (max-width: 768px) {
  /* Selección móvil = tarjeta LIMPIA: barra de acento de 4px + borde acentuado +
     leve elevación. SIN relleno de fondo (se veía pesada/"encajonada"). El color
     no es el único indicador (hay barra + borde + sombra → cumple color-not-only). */
  .data-table tbody tr.bulk-selected,
  .data-table tbody tr.sm-row-active,
  .ggi-dt-table tbody tr.bulk-selected,
  .ggi-dt-table tbody tr.sm-row-active {
    border-color: var(--ac, #1d4ed8) !important;
    border-left-width: 4px !important;
    box-shadow: 0 2px 10px rgba(29,78,216,.14) !important;
  }
  .data-table tbody tr.bulk-selected td,
  .data-table tbody tr.sm-row-active td,
  .ggi-dt-table tbody tr.bulk-selected td,
  .ggi-dt-table tbody tr.sm-row-active td {
    background: transparent !important;   /* sin relleno: el acento va en el borde */
  }
  /* Separador sobre los botones: ocultarlo en la tarjeta seleccionada para que
     no se vea como una caja anidada dentro del acento. */
  .data-table tbody tr.bulk-selected td:last-child,
  .data-table tbody tr.sm-row-active td:last-child {
    border-top-color: transparent !important;
  }
}

/* ══════════════════════════════════════════════════
   GLOBAL MOBILE COMPACT — layout_end.php
   Complementa las reglas base de layout.php
══════════════════════════════════════════════════ */

@media (max-width: 768px) {
  /* ── Paneles, modales y tablas de configuración ── */
  .panel { padding: 14px !important; }
  .form-card { padding: 14px !important; }
  .mant-card-header { padding: 11px 14px !important; }
  .mant-modal-body { padding: 16px !important; }
  .mant-modal-header { padding: 13px 16px !important; }
  .mant-card-title { font-size: 13px !important; }
  .mant-table th { font-size: 10px !important; padding: 9px 12px !important; }
  .mant-table td { font-size: 12px !important; padding: 10px 12px !important; }
  /* ── Botones de página de detalle ── */
  .page-header .btn { font-size: 12px !important; padding: 7px 12px !important; }
  .page-header .btn-sm { font-size: 11px !important; padding: 5px 10px !important; }
  /* ── Filtros / toolbar en índices ── */
  .toolbar { flex-direction: column !important; gap: 8px !important; }
  .toolbar form { flex-direction: column !important; width: 100% !important; gap: 8px !important; }
  .search-wrap { max-width: 100% !important; width: 100% !important; }
  .filter-select { width: 100% !important; }
}

@media (max-width: 480px) {
  .mant-table tr { padding: 10px 12px !important; }
}

</style>

<!-- ShortcutManager — sistema centralizado de atajos de teclado -->
<script src="<?= APP_URL ?>/assets/js/shortcuts.js?v=<?= @filemtime(__DIR__ . '/../assets/js/shortcuts.js') ?>"></script>

<!-- Modal mantenimiento -->
<div class="mant-modal-overlay" id="mant-modal" onclick="mantCloseOnBg(event)">
  <div class="mant-modal-box" role="dialog" aria-modal="true" aria-labelledby="mant-modal-title">
    <div class="mant-modal-header">
      <div class="mant-modal-title" id="mant-modal-title">Nuevo registro</div>
      <button type="button" class="mant-modal-close" onclick="ggiModalClose()" aria-label="Cerrar">
        <!-- Desktop: X -->
        <span class="ggi-close-icon-desktop" aria-hidden="true">
          <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </span>
        <!-- Mobile: ← Volver -->
        <span class="ggi-close-icon-mobile" style="display:none;align-items:center;gap:5px;font-size:14px;font-weight:600;color:var(--azul,#1A3A8F);">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
          Volver
        </span>
      </button>
    </div>
    <div class="mant-modal-body" id="mant-modal-body"></div>
  </div>
</div>

<!-- Banner: cambios sin guardar (dirty-check) -->
<div id="ggi-dirty-banner" role="dialog" aria-modal="true" aria-labelledby="ggi-dirty-title">
  <div id="ggi-dirty-box">
    <h3 id="ggi-dirty-title">Cambios sin guardar</h3>
    <p>Tienes cambios que no se han guardado. Si sales ahora, se perderán.</p>
    <div class="ggi-dirty-btns">
      <button type="button" class="btn btn-outline" onclick="ggiDirtyCancel()">
        Continuar editando
      </button>
      <button type="button" class="btn" style="background:#ef4444;color:#fff;border-color:#ef4444;" onclick="ggiDirtyConfirm()">
        Salir sin guardar
      </button>
    </div>
  </div>
</div>

<!-- Toast container -->
<div id="toast-container" role="region" aria-label="Notificaciones" aria-live="polite" aria-atomic="false"></div>

<script>
window.APP_URL    = '<?= APP_URL ?>';
window.CSRF_TOKEN = '<?= csrf_token() ?>';
window.ACTIVE_MENU = '<?= addslashes($active_menu ?? '') ?>';

// ── Navegación de paginación canónica (.ggi-pager / helper ggi_pager) ──
// Actualiza un parámetro del query string y recarga. Limpia cursores legados.
// resetPage=true vuelve a la página 1 (al cambiar "ítems por página").
window.ggiPagerGo = function(param, value, resetPage) {
  var u = new URLSearchParams(window.location.search);
  u.set(param, value);
  if (resetPage) u.delete('p');
  u.delete('cursor'); u.delete('prev');
  window.location.search = u.toString();
};
// ── Copiar identificadores (.ggi-copyable / helper ggi_copy) ──
(function () {
  function flash(el) {
    el.classList.add('ggi-copied');
    if (typeof window.showToast === 'function') window.showToast('Copiado', 'ok');
    setTimeout(function () { el.classList.remove('ggi-copied'); }, 1400);
  }
  function fallback(text, el) {
    var ta = document.createElement('textarea');
    ta.value = text; ta.style.cssText = 'position:fixed;top:-9999px;opacity:0';
    document.body.appendChild(ta); ta.select();
    try { if (document.execCommand('copy')) flash(el); } catch (_) {}
    document.body.removeChild(ta);
  }
  function copy(el) {
    var text = (el.getAttribute('data-ggi-copy') || el.textContent || '').trim();
    if (!text) return;
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(function () { flash(el); }, function () { fallback(text, el); });
    } else { fallback(text, el); }
  }
  document.addEventListener('click', function (e) {
    var el = e.target.closest('.ggi-copyable');
    if (!el) return;
    e.preventDefault(); e.stopPropagation();
    copy(el);
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' && e.key !== ' ') return;
    var el = e.target.closest && e.target.closest('.ggi-copyable');
    if (!el) return;
    e.preventDefault(); copy(el);
  });
})();

// Salto de página validado desde el input (clamp 1..max).
window.ggiPagerJump = function(input, pageParam, maxPages) {
  var v = parseInt(input.value, 10);
  if (isNaN(v) || v < 1) v = 1;
  if (v > maxPages) v = maxPages;
  window.ggiPagerGo(pageParam, v, false);
};

// ── Popover genérico para chips "+N" (normas, etc.) ──
// position:fixed → escapa contenedores con overflow. Click/tap/teclado (touch-friendly).
// El disparador debe tener data-ggipop="<html del contenido>".
(function () {
  var _pop = null, _trigger = null, _hoverTimer = null;
  var _canHover = !(window.matchMedia && window.matchMedia('(hover: none)').matches);
  function close() {
    if (!_pop) return;
    _pop.remove(); _pop = null;
    if (_trigger) { _trigger.setAttribute('aria-expanded', 'false'); _trigger = null; }
    document.removeEventListener('mousedown', onDoc, true);
    document.removeEventListener('keydown', onKey, true);
    window.removeEventListener('resize', close); window.removeEventListener('scroll', close, true);
  }
  function onDoc(e) { if (_pop && !_pop.contains(e.target) && e.target !== _trigger) close(); }
  function onKey(e) { if (e.key === 'Escape') { close(); if (_trigger) _trigger.focus(); } }
  function open(trigger) {
    close();
    _trigger = trigger;
    _pop = document.createElement('div');
    _pop.className = 'ggi-chip-pop';
    _pop.innerHTML = trigger.getAttribute('data-ggipop') || '';
    document.body.appendChild(_pop);
    /* Mantener abierto mientras el puntero está sobre el popover (modo hover) */
    _pop.addEventListener('mouseenter', function () { clearTimeout(_hoverTimer); });
    _pop.addEventListener('mouseleave', function () { _hoverTimer = setTimeout(close, 160); });
    var r = trigger.getBoundingClientRect();
    var pw = _pop.offsetWidth, ph = _pop.offsetHeight;
    var left = Math.min(r.left, window.innerWidth - pw - 10);
    var top  = r.bottom + 6;
    if (top + ph > window.innerHeight - 10) top = r.top - ph - 6;  // abre arriba si no cabe abajo
    _pop.style.left = Math.max(8, left) + 'px';
    _pop.style.top  = Math.max(8, top) + 'px';
    trigger.setAttribute('aria-expanded', 'true');
    setTimeout(function () {
      document.addEventListener('mousedown', onDoc, true);
      document.addEventListener('keydown', onKey, true);
      window.addEventListener('resize', close);
      window.addEventListener('scroll', close, true);
    }, 0);
  }
  /* Click/tap/teclado: toggle (funciona en todas las plataformas) */
  window.ggiChipPop = function (trigger) {
    if (_trigger === trigger) { close(); return; }
    open(trigger);
  };
  /* Hover en escritorio (solo dispositivos con puntero) — mejora, no requisito.
     Se activa en disparadores con [data-pop-hover]. En touch nunca corre. */
  if (_canHover) {
    document.addEventListener('mouseover', function (e) {
      var t = e.target.closest('[data-pop-hover]');
      if (!t) return;
      clearTimeout(_hoverTimer);
      if (_trigger !== t) open(t);
    });
    document.addEventListener('mouseout', function (e) {
      var t = e.target.closest('[data-pop-hover]');
      if (!t) return;
      var to = e.relatedTarget;
      if (to && _pop && _pop.contains(to)) return;       // pasa al popover
      if (to && to.closest && to.closest('[data-pop-hover]') === t) return;
      _hoverTimer = setTimeout(close, 160);
    });
  }
})();

// ── CSRF helper — lee siempre desde <meta name="csrf-token"> como fuente
// de verdad, con window.CSRF_TOKEN como fallback (puede quedar obsoleto tras SPA).
function _csrfToken() {
  var m = document.querySelector('meta[name="csrf-token"]');
  return (m && m.getAttribute('content')) || window.CSRF_TOKEN || '';
}
// Actualiza tanto window.CSRF_TOKEN como la meta tag
function _csrfUpdate(newToken) {
  if (!newToken) return;
  window.CSRF_TOKEN = newToken;
  var m = document.querySelector('meta[name="csrf-token"]');
  if (m) m.setAttribute('content', newToken);
}

/**
 * ggiPost(url, datos, opciones) — ÚNICA forma de hacer POST autenticado.
 *
 * Cada módulo repetía a mano el mismo baile (FormData + _csrf + fetch +
 * parseo + error), con variantes distintas del nombre del campo y de la
 * clave del mensaje de error. Equivocarse en cualquiera de las dos produce
 * un 403 que la interfaz se traga en silencio: la acción no ocurre y el
 * usuario no se entera. Este helper lo centraliza:
 *
 *  - Adjunta el _csrf vigente (campo Y cabecera).
 *  - Marca la petición como AJAX para que el backend responda JSON.
 *  - Absorbe la rotación de token (json.csrf).
 *  - Normaliza el error venga como msg, mensaje o error, y lo MUESTRA.
 *
 * Devuelve siempre un objeto (nunca lanza), con .ok booleano.
 * Pasar {silent:true} para manejar el error por cuenta propia.
 */
window.ggiPost = function (url, datos, opciones) {
  opciones = opciones || {};

  function avisar(m) {
    if (opciones.silent) return;
    if (typeof showToast === 'function') showToast(m, 'error');
  }

  var cabeceras = { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': _csrfToken() };
  var cuerpo;

  if (opciones.json) {
    // Endpoints que leen el cuerpo crudo con json_decode(php://input).
    // Ahí el token no puede viajar como campo: va solo en la cabecera,
    // que csrf_verify() también acepta.
    cabeceras['Content-Type'] = 'application/json';
    cuerpo = JSON.stringify(datos || {});
  } else {
    var fd = (datos instanceof FormData) ? datos : new FormData();
    if (!(datos instanceof FormData) && datos) {
      Object.keys(datos).forEach(function (k) { fd.append(k, datos[k]); });
    }
    fd.set('_csrf', _csrfToken());
    cuerpo = fd;
  }

  return fetch(url, {
    method: 'POST',
    body: cuerpo,
    credentials: 'same-origin',
    headers: cabeceras
  })
  .then(function (r) {
    return r.json().then(
      function (j) { return { http: r.ok, j: j }; },
      function ()  { return { http: r.ok, j: { _sinJson: true, _status: r.status } }; }
    );
  })
  .then(function (res) {
    var j = res.j || {};
    if (j.csrf) _csrfUpdate(j.csrf);

    // Solo se considera fallo lo EXPLÍCITO. Los endpoints del sistema no
    // comparten una forma de respuesta única; si se tratara como error todo
    // lo que no traiga ok:true, las respuestas con otra forma dispararían
    // avisos falsos.
    var falla = !res.http || j.ok === false || j.success === false
             || (j.error && j.ok === undefined && j.success === undefined);

    if (falla) {
      var m = j.msg || j.mensaje || j.error
           || (j._sinJson ? 'El servidor respondió algo inesperado (HTTP ' + j._status + ')'
                          : 'No se pudo completar la acción');
      avisar(m);
      j.ok = false;
    } else if (j.ok === undefined) {
      j.ok = true;   // respuesta sin campo de estado → se asume correcta
    }

    return j;
  })
  .catch(function () {
    avisar('Sin conexión con el servidor');
    return { ok: false, msg: 'Sin conexión' };
  });
};


// ── Tema claro/oscuro ──
var TEMA_ICONS = {
  claro:   '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/>',
  oscuro:  '<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>',
  sistema: '<rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>',
};
var TEMA_LABELS = {
  claro:   'Tema claro',
  oscuro:  'Tema oscuro',
  sistema: 'Tema automático',
};

function _resolverTemaReal(tema) {
  // Para 'sistema', retorna 'claro' u 'oscuro' según el OS
  if (tema !== 'sistema') return tema;
  return (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches)
    ? 'oscuro' : 'claro';
}

function actualizarUITema(tema) {
  // Botón del header
  var icon = document.getElementById('tema-icon');
  var btn  = document.getElementById('btn-tema');
  if (icon) icon.innerHTML = TEMA_ICONS[tema] || TEMA_ICONS.sistema;
  if (btn)  btn.setAttribute('data-tooltip', TEMA_LABELS[tema] || 'Tema');

  // Mini botones del popup
  document.querySelectorAll('.tema-mini-btn').forEach(function(b) {
    b.classList.toggle('activo', b.dataset.tema === tema);
  });
}

function applyTema(tema) {
  // Transición suave
  var html = document.documentElement;
  html.classList.add('tema-transitioning');
  setTimeout(function() { html.classList.remove('tema-transitioning'); }, 280);

  // El atributo data-tema controla todo — el CSS (via variables) maneja los colores
  var real = _resolverTemaReal(tema);
  if (real === 'oscuro') {
    html.setAttribute('data-tema', 'oscuro');
  } else {
    html.setAttribute('data-tema', 'claro');
    // Limpiar inline backgrounds residuales de versiones anteriores del código
    html.style.removeProperty('background');
    html.style.removeProperty('background-color');
    document.body.style.removeProperty('background');
    document.body.style.removeProperty('background-color');
  }

  localStorage.setItem('ggi_tema', tema);
  actualizarUITema(tema);
  /* Actualizar colores de estado-sel al cambiar de tema */
  if (typeof window.ggiApplySelectColors === 'function') {
    setTimeout(window.ggiApplySelectColors, 0);
  }
  if (typeof window.initEstadoSel === 'function') {
    setTimeout(window.initEstadoSel, 0);
  }
}

function ciclarTema() {
  var actual = localStorage.getItem('ggi_tema') || 'sistema';
  var ciclo  = { claro: 'oscuro', oscuro: 'sistema', sistema: 'claro' };
  applyTema(ciclo[actual] || 'claro');
}

// Escuchar cambios de preferencia del OS (afecta sólo en modo "sistema")
if (window.matchMedia) {
  window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function() {
    if ((localStorage.getItem('ggi_tema') || 'sistema') === 'sistema') {
      applyTema('sistema');
    }
  });
}

// Inicializar UI al cargar
(function() {
  var t = localStorage.getItem('ggi_tema') || 'sistema';
  // Llamar después de que el DOM esté listo
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() { actualizarUITema(t); });
  } else {
    actualizarUITema(t);
  }
})();

// ── Menú usuario sidebar ──
function toggleUserMenu() {
  const popup = document.getElementById('user-popup');
  const card  = document.getElementById('user-card-btn');
  const open  = popup.classList.toggle('open');
  card.classList.toggle('open', open);
}
document.addEventListener('click', function(e) {
  const card  = document.getElementById('user-card-btn');
  const popup = document.getElementById('user-popup');
  if (card && popup && !card.contains(e.target) && !popup.contains(e.target)) {
    popup.classList.remove('open');
    card.classList.remove('open');
  }
});

// ── Buscador global ──
(function() {
  const input    = document.getElementById('global-search-input');
  const dropdown = document.getElementById('gs-dropdown');
  if (!input) return;

  const _dark = () => document.documentElement.getAttribute('data-tema') === 'oscuro';
  const TIPO_META_LIGHT = {
    cliente:    { color: '#1A3A8F', bg: '#e8edf8', label: 'Cliente' },
    certificado:{ color: '#2DB140', bg: '#e8f7eb', label: 'Certificado' },
    envio:      { color: '#8b5cf6', bg: '#f5f3ff', label: 'Envío' },
    propuesta: { color: '#10b981', bg: '#d1fae5', label: 'Propuesta' },
  };
  const TIPO_META_DARK = {
    cliente:    { color: '#94a3b8', bg: '#252d3d', label: 'Cliente' },
    certificado:{ color: '#34D399', bg: '#0f3020', label: 'Certificado' },
    envio:      { color: '#a78bfa', bg: '#2e1a4a', label: 'Envío' },
    propuesta: { color: '#34D399', bg: '#0f3020', label: 'Propuesta' },
  };
  const TIPO_META = new Proxy({}, { get: (_, k) => (_dark() ? TIPO_META_DARK : TIPO_META_LIGHT)[k] });
  const ICONOS = {
    empresa:    '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
    certificado:'<circle cx="12" cy="8" r="6"/><path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/>',
    envio:      '<rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>',
    propuesta: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/>',
  };

  let timer = null;
  let ultimaQuery = '';

  // ── Caché localStorage: resultados instantáneos en búsquedas repetidas ───────
  // Guarda las últimas 5 consultas (LRU). Cada entrada expira a los 10 minutos.
  // En la práctica, los mismos clientes/certificados se buscan varias veces al día,
  // por lo que el caché los muestra de inmediato mientras el AJAX actualiza en segundo plano.
  const CACHE_KEY  = 'ggi_gsearch_v1';
  const CACHE_MAX  = 5;
  const CACHE_TTL  = 10 * 60 * 1000; // 10 min en ms

  function cacheRead() {
    try { return JSON.parse(localStorage.getItem(CACHE_KEY) || '[]'); } catch { return []; }
  }
  function cacheWrite(entries) {
    try { localStorage.setItem(CACHE_KEY, JSON.stringify(entries)); } catch {}
  }
  function cacheGet(q) {
    const now = Date.now();
    return cacheRead().find(e => e.q === q && (now - e.ts) < CACHE_TTL) || null;
  }
  function cacheSet(q, data) {
    const now  = Date.now();
    let entries = cacheRead().filter(e => e.q !== q && (now - e.ts) < CACHE_TTL);
    entries.unshift({ q, data, ts: now });
    if (entries.length > CACHE_MAX) entries = entries.slice(0, CACHE_MAX);
    cacheWrite(entries);
  }

  function mostrarLoading() {
    dropdown.style.display = 'block';
    dropdown.innerHTML = '<div class="gs-loading">Buscando...</div>';
  }

  function renderResultados(data, q) {
    if (!data.resultados || data.resultados.length === 0) {
      return `<div class="gs-empty">Sin resultados para "<strong>${q}</strong>"</div>`;
    }
    return data.resultados.map(r => {
      const m = TIPO_META[r.tipo] || { color: '#6b7a99', bg: '#f0f2f8', label: r.tipo };
      const ico = ICONOS[r.icono] || ICONOS.empresa;
      return `<a href="${r.url}" class="gs-item">
        <div class="gs-icon" style="background:${m.bg}">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="${m.color}" stroke-width="2">${ico}</svg>
        </div>
        <div style="flex:1;min-width:0;">
          <div class="gs-titulo">${r.titulo}</div>
          <div class="gs-sub">${r.subtitulo}</div>
        </div>
        <span class="gs-tipo" style="background:${m.bg};color:${m.color}">${m.label}</span>
      </a>`;
    }).join('');
  }

  function mostrarResultados(data, q) {
    dropdown.innerHTML = renderResultados(data, q);
  }

  function buscar(q) {
    if (q === ultimaQuery) return;
    ultimaQuery = q;
    if (q.length < 2) { dropdown.style.display = 'none'; return; }

    // Mostrar caché inmediatamente (si existe) para que el resultado sea instantáneo
    const cached = cacheGet(q);
    if (cached) {
      dropdown.style.display = 'block';
      dropdown.innerHTML = renderResultados(cached, q);
    } else {
      mostrarLoading();
    }

    // Siempre lanzar el AJAX para obtener resultados frescos y actualizar el caché
    fetch(`${window.APP_URL}/modules/ajax_busqueda_global.php?q=${encodeURIComponent(q)}`)
      .then(r => r.json())
      .then(data => {
        if (input.value.trim() !== q) return; // el usuario ya escribió otra cosa
        cacheSet(q, data);
        mostrarResultados(data, q);
      })
      .catch(() => {
        if (!cached) { // si tenemos caché, no sobreescribir con el error
          dropdown.innerHTML = '<div class="gs-empty">Error al buscar.</div>';
        }
      });
  }

  input.addEventListener('input', () => {
    const q = input.value.trim();
    clearTimeout(timer);
    if (q.length < 2) { dropdown.style.display = 'none'; ultimaQuery = ''; return; }
    dropdown.style.display = 'block';
    timer = setTimeout(() => buscar(q), 280);
  });

  input.addEventListener('focus', () => {
    const q = input.value.trim();
    if (q.length >= 2) {
      // Si ya hay contenido en el dropdown (sesión actual) → mostrarlo
      if (dropdown.innerHTML) { dropdown.style.display = 'block'; return; }
      // Si hay caché → mostrar inmediatamente y refrescar en segundo plano
      const cached = cacheGet(q);
      if (cached) {
        dropdown.style.display = 'block';
        dropdown.innerHTML = renderResultados(cached, q);
        // Refrescar en background (sin mostrar loading ni resetear ultimaQuery)
        fetch(`${window.APP_URL}/modules/ajax_busqueda_global.php?q=${encodeURIComponent(q)}`)
          .then(r => r.json()).then(data => { cacheSet(q, data); if (input.value.trim() === q) mostrarResultados(data, q); })
          .catch(() => {});
      }
    }
  });

  document.addEventListener('click', e => {
    if (!document.getElementById('gsw').contains(e.target)) {
      dropdown.style.display = 'none';
    }
  });

  // Atajo ⌘K / Ctrl+K → enfocar buscador (delegado a ShortcutManager)
  if (window.ShortcutManager) {
    ShortcutManager.register('ctrl+k', 'global', function(e, info) {
      if (info.context === 'modal') return false; // no interrumpir modales abiertos
      e.preventDefault();
      input.focus();
      if (input.select) input.select();
    }, { description: 'Buscar en el sistema', group: 'General' });
  } else {
    document.addEventListener('keydown', function(e) {
      if ((e.metaKey || e.ctrlKey) && e.key === 'k') { e.preventDefault(); input.focus(); input.select(); }
    });
  }

  // Escape → cerrar dropdown de búsqueda (delegado a ShortcutManager)
  if (window.ShortcutManager) {
    ShortcutManager.register('escape', 'search', function() {
      if (dropdown.style.display !== 'none' && dropdown.innerHTML) {
        dropdown.style.display = 'none';
        input.blur();
        return true;
      }
      return false;
    }, { description: 'Cerrar búsqueda', group: 'General' });
  } else {
    // Fallback si ShortcutManager no cargó
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') { dropdown.style.display = 'none'; input.blur(); }
    });
  }
})();

// ── Filtros persistentes (sessionStorage) ──
(function() {
  var form = document.querySelector('.filter-form');
  if (!form) return;

  var key = 'ggi_filters:' + location.pathname;

  // Determina si la URL actual ya tiene filtros activos (ignora "page" y valores vacíos o "0")
  function hasActiveParams(search) {
    var sp = new URLSearchParams(search);
    sp.delete('page');
    var active = false;
    sp.forEach(function(v) { if (v !== '' && v !== '0') active = true; });
    return active;
  }

  if (hasActiveParams(location.search)) {
    // Guardar filtros actuales
    sessionStorage.setItem(key, location.search);
  } else {
    // Restaurar filtros guardados (si los hay)
    var saved = sessionStorage.getItem(key);
    if (saved && hasActiveParams(saved)) {
      location.replace(location.pathname + saved);
      return;
    }
  }

  // Borrar sessionStorage cuando se pulsa "Limpiar filtros"
  document.addEventListener('click', function(e) {
    var link = e.target.closest('a');
    if (!link) return;
    var txt = link.textContent || '';
    if (txt.indexOf('Limpiar') !== -1) {
      sessionStorage.removeItem(key);
    }
  });
})();

// ── Botón "← Volver": usar referrer para preservar filtros ──
(function() {
  var form = document.querySelector('.filter-form');
  if (form) return; // Solo aplica en páginas de detalle (sin filtros)

  var ref = document.referrer;
  if (!ref) {
    // Sin referrer: intentar recuperar de sessionStorage (por si se refrescó la página)
    var stored = sessionStorage.getItem('ggi_back:' + location.href);
    if (stored) ref = stored;
  }
  if (!ref || ref.indexOf(window.APP_URL) !== 0) return;
  if (ref === location.href) return; // Evitar apuntar a sí mismo

  // Guardar en sessionStorage por si el usuario refresca la página de detalle
  sessionStorage.setItem('ggi_back:' + location.href, ref);

  document.querySelectorAll('a.btn-outline, a.btn-outline.btn-sm').forEach(function(link) {
    if (link.textContent.trim().indexOf('← Volver') === 0) {
      link.href = ref;
    }
  });
})();

// ── Sidebar móvil ──
function toggleSidebar() {
  document.querySelector('.sidebar').classList.toggle('open');
  const ov = document.getElementById('sidebar-overlay');
  ov.style.display = ov.style.display === 'none' ? 'block' : 'none';
}
function closeSidebar() {
  document.querySelector('.sidebar').classList.remove('open');
  document.getElementById('sidebar-overlay').style.display = 'none';
}
// A11y (escape-routes): Escape cierra el drawer móvil cuando está abierto.
document.addEventListener('keydown', function (e) {
  if (e.key !== 'Escape') return;
  var sb = document.querySelector('.sidebar.open');
  if (sb && window.innerWidth <= 768) { closeSidebar(); }
});

// ── Sidebar desktop colapsable ──
function toggleSidebarDesktop() {
  const root    = document.documentElement;
  const body    = document.body;
  const sidebar = document.querySelector('.sidebar');
  const btn     = document.querySelector('.btn-toggle-desktop svg');

  const collapsed = sidebar.classList.toggle('collapsed');
  body.classList.toggle('sidebar-collapsed', collapsed);

  // Una sola variable CSS conduce header + main + todo el contenido
  const mini = getComputedStyle(root).getPropertyValue('--sidebar-mini').trim() || '56px';
  if (collapsed) {
    root.style.setProperty('--sidebar-current', mini);
    if (btn) btn.style.transform = 'scaleX(-1)';
  } else {
    root.style.removeProperty('--sidebar-current'); // hereda var(--sidebar-w) del :root
    if (btn) btn.style.transform = '';
  }

  // Barra flotante de selección múltiple (módulo envíos)
  const bulkBar = document.getElementById('bulk-bar-mobile');
  if (bulkBar && bulkBar.style.display !== 'none') {
    bulkBar.style.left = collapsed ? '0' : 'var(--sidebar-w)';
  }

  // Clase anti-FOUC ya no es necesaria una vez que JS tomó el control
  root.classList.remove('_sb-pre');

  localStorage.setItem('sidebar_collapsed', collapsed ? '1' : '0');
}

// ── Accesibilidad: auto-asociar labels con sus inputs ──
document.addEventListener('DOMContentLoaded', function() {
  document.querySelectorAll('.field label:not([for])').forEach(function(label) {
    var field = label.closest('.field');
    if (!field) return;
    var inp = field.querySelector(
      'input:not([type="hidden"]):not([type="radio"]):not([type="checkbox"]), select, textarea'
    );
    if (!inp) return;
    if (!inp.id) inp.id = 'lbl_' + Math.random().toString(36).slice(2, 8);
    label.setAttribute('for', inp.id);
  });
});

// Restaurar preferencia al cargar
document.addEventListener('DOMContentLoaded', () => {
  if (localStorage.getItem('sidebar_collapsed') === '1' && window.innerWidth > 768) {
    // El <head> ya fijó --sidebar-current=56px (anti-FOUC).
    // toggleSidebarDesktop aplica las clases CSS y limpia _sb-pre.
    toggleSidebarDesktop();
  } else {
    // Asegurar que _sb-pre no quede activa si localStorage cambió
    document.documentElement.classList.remove('_sb-pre');
    document.documentElement.style.removeProperty('--sidebar-current');
  }
  // Restaurar secciones colapsadas
  ['mant', 'finanzas', 'sistema'].forEach(function(id) {
    if (localStorage.getItem('sidebar_sec_' + id) === '1') {
      var sec = document.getElementById('section-' + id);
      if (sec) sec.classList.add('collapsed');
    }
  });
});
document.querySelectorAll('.nav-item').forEach(el => {
  el.addEventListener('click', () => { if (window.innerWidth <= 768) closeSidebar(); });
});

// A11y: marcar la página actual para lectores de pantalla (no solo visualmente).
// Se invoca al cargar y tras cada navegación SPA (ver swap del router).
function _ggiSyncAriaCurrent() {
  document.querySelectorAll('.nav-item').forEach(function (el) {
    if (el.classList.contains('active')) el.setAttribute('aria-current', 'page');
    else el.removeAttribute('aria-current');
  });
}
_ggiSyncAriaCurrent();

// ── Secciones colapsables del sidebar ──
function toggleSidebarSection(id) {
  var sec = document.getElementById('section-' + id);
  if (!sec) return;
  var isCollapsed = sec.classList.toggle('collapsed');
  localStorage.setItem('sidebar_sec_' + id, isCollapsed ? '1' : '0');
}

// ── Toast (premium) ──────────────────────────────────────────────────────────
// showToast(msg, type, duration)
// type: 'ok' | 'error' | 'warning' | 'info' | 'loading'   (alias: 'success' → 'ok')
// msg:  string  OR  { title, msg }  for title + subtitle
// Returns the toast element (useful for loading → update pattern)
function showToast(msg, type, duration) {
  if (type === undefined)     type     = 'ok';
  if (duration === undefined) duration = 3500;
  if (type === 'success')     type     = 'ok';

  var container = document.getElementById('toast-container');
  if (!container) return null;

  var ICONS = {
    ok:      '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
    error:   '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
    warning: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
    info:    '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>',
    loading: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="animation:_gt-spin .7s linear infinite"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>',
  };

  // Parse message: plain string or { title, msg/message, action:{label,onClick} }
  var titleText, subText = null, action = null;
  if (msg && typeof msg === 'object') {
    titleText = msg.title  || '';
    subText   = msg.msg    || msg.message || null;
    action    = (msg.action && msg.action.label && typeof msg.action.onClick === 'function') ? msg.action : null;
  } else {
    titleText = String(msg || '');
  }

  var el = document.createElement('div');
  el.className = 'ggi-toast t-' + type;
  // A11y: error/warning se anuncian con urgencia (alert/assertive); el resto, status/polite.
  el.setAttribute('role', (type === 'error' || type === 'warning') ? 'alert' : 'status');
  if (type === 'error' || type === 'warning') el.setAttribute('aria-live', 'assertive');

  var barHtml = (duration > 0 && type !== 'loading')
    ? '<div class="ggi-toast-bar" style="animation-duration:' + duration + 'ms"></div>'
    : '';

  el.innerHTML =
    '<div class="ggi-toast-ico">' + (ICONS[type] || ICONS.ok) + '</div>' +
    '<div class="ggi-toast-body">' +
      '<div class="ggi-toast-title">' + titleText + '</div>' +
      (subText ? '<div class="ggi-toast-sub">' + subText + '</div>' : '') +
    '</div>' +
    (action ? '<button class="ggi-toast-act" type="button">' + action.label + '</button>' : '') +
    '<button class="ggi-toast-x" aria-label="Cerrar" title="Cerrar">&#x2715;</button>' +
    barHtml;

  container.appendChild(el);

  if (action) {
    el.querySelector('.ggi-toast-act').addEventListener('click', function() {
      try { action.onClick(); } catch(e) {}
      _closeToast();
    });
  }

  // ── Close logic ──
  var _timer = null, _remaining = duration, _startedAt = null;

  function _closeToast() {
    if (_timer) { clearTimeout(_timer); _timer = null; }
    el.classList.add('hiding');
    el.addEventListener('animationend', function() { try { el.remove(); } catch(e){} }, { once: true });
  }

  el.querySelector('.ggi-toast-x').addEventListener('click', _closeToast);
  el._ggiClose = _closeToast;

  if (duration > 0 && type !== 'loading') {
    function _startTimer() {
      _startedAt = Date.now();
      _timer = setTimeout(_closeToast, _remaining);
    }
    el.addEventListener('mouseenter', function() {
      if (!_timer) return;
      clearTimeout(_timer); _timer = null;
      _remaining -= (Date.now() - _startedAt);
      var pb = el.querySelector('.ggi-toast-bar');
      if (pb) pb.style.animationPlayState = 'paused';
    });
    el.addEventListener('mouseleave', function() {
      var pb = el.querySelector('.ggi-toast-bar');
      if (pb) pb.style.animationPlayState = 'running';
      _startTimer();
    });
    _startTimer();
  }

  return el; // callers can call el._ggiClose() or replace for loading → done pattern
}

// Alias
window.ggiToast = showToast;



// ════════════════════════════════════════════════════════════
// MODAL HELPERS + MOBILE/PWA NAVIGATION
// ════════════════════════════════════════════════════════════

/* ── Estado interno del modal ── */
var _ggiModalOpen           = false;  // hay un modal abierto
var _ggiModalHistoryPushed  = false;  // pushState fue llamado para este modal
var _ggiModalDirty          = false;  // formulario tiene cambios sin guardar
var _ggiDirtyCallback       = null;   // función a ejecutar tras confirmar salida sucia
var _ggiIgnoreNextPopstate  = false;  // suprimir el next popstate (limpieza silenciosa)

/* ── Detección mobile (se re-evalúa en cada apertura) ── */
function _ggiIsMobile() { return window.innerWidth < 768; }

/* ── A11y de modal: gestión de foco (focus-management + focus trap + restore) ──
   - Al abrir: guarda el disparador y mueve el foco al modal.
   - Mientras abierto: si el foco se fuga fuera del modal (Tab/Shift+Tab), lo
     regresa al botón Cerrar → el foco queda atrapado en el diálogo (incluye el
     iframe embebido, que cuenta como un único punto de tabulación contenido).
   - Al cerrar: restaura el foco al disparador original. */
var _ggiModalTrigger = null;
function _ggiModalFocusIn(e) {
  if (!_ggiModalOpen) return;
  var modal = document.getElementById('mant-modal');
  if (modal && !modal.contains(e.target)) {
    var cb = modal.querySelector('.mant-modal-close');
    if (cb) cb.focus();
  }
}
function _ggiModalFocusActivate(focusClose) {
  _ggiModalTrigger = (document.activeElement && document.activeElement !== document.body)
    ? document.activeElement : _ggiModalTrigger;
  document.addEventListener('focusin', _ggiModalFocusIn);
  // Mover el foco dentro del diálogo. focusClose=true (modal iframe, sin input
  // accesible en el documento padre) enfoca el botón Cerrar; si no, el caller ya
  // movió el foco a su primer campo.
  if (focusClose) {
    setTimeout(function () {
      var cb = document.querySelector('#mant-modal .mant-modal-close');
      if (cb) cb.focus();
    }, 60);
  }
}
function _ggiModalFocusRestore() {
  document.removeEventListener('focusin', _ggiModalFocusIn);
  var t = _ggiModalTrigger;
  _ggiModalTrigger = null;
  if (t && typeof t.focus === 'function' && document.contains(t)) {
    try { t.focus(); } catch (_) {}
  }
}

/* ── A11y: Escape dentro del iframe embed → cerrar el diálogo ──
   Este layout_end.php también se incluye dentro del formulario embed (iframe).
   Cuando el foco está en el formulario, el keydown ocurre en el documento del
   iframe y el padre no puede capturarlo, así que Esc no cerraba. Aquí, solo en
   contexto iframe, reenviamos {ggi:'close'} al padre (que respeta el dirty-check),
   salvo que haya un dropdown/menú interno abierto (lo cierra su propio handler). */
if (window.self !== window.top) {
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    if (document.querySelector('.ss-drop[style*="block"], .estado-sel-menu:not([hidden]), .ts-dropdown.is-open, .ggi-row-menu-wrap.open')) return;
    try { window.parent.postMessage({ ggi: 'close' }, '*'); } catch (_) {}
  });
} else {
  // Documento PADRE: Escape cierra el mant-modal abierto. Cubre modales NO-iframe y el caso
  // en que el foco está en el padre (botón Cerrar) y no dentro del iframe embed → antes Esc
  // no hacía nada ("Escape no funciona en varios modales"). ggiModalClose restaura el scroll.
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    if (!_ggiModalOpen) return;
    if (document.querySelector('.ss-drop[style*="block"], .estado-sel-menu:not([hidden]), .ts-dropdown.is-open, .ggi-row-menu-wrap.open, .ms-drop.is-open')) return;
    if (typeof ggiModalClose === 'function') ggiModalClose();
  });
}

/* ── mantOpen: modal pequeño (mantenimiento simple) ── */
function mantOpen(titulo, formHtml) {
  document.getElementById('mant-modal-title').textContent = titulo;
  document.getElementById('mant-modal-body').innerHTML = formHtml;
  document.getElementById('mant-modal').classList.add('open');
  document.body.style.overflow = 'hidden';
  _ggiModalOpen  = true;
  _ggiModalDirty = false;
  if (_ggiIsMobile()) _ggiModalPushState(titulo);
  const first = document.querySelector('#mant-modal-body input:not([type=hidden]), #mant-modal-body select');
  if (first) setTimeout(function(){ first.focus(); }, 50);
  _ggiModalFocusActivate(!first); // trap + restore; enfoca Cerrar solo si no hay campo
}

/* ── mantClose: cierra sin preguntar (usado internamente) ── */
function mantClose() {
  document.getElementById('mant-modal').classList.remove('open');
  var _lgBox = document.querySelector('#mant-modal .mant-modal-box');
  if (_lgBox) _lgBox.classList.remove('mant-modal-lg');
  document.body.style.overflow = '';
  _ggiModalOpen  = false;
  _ggiModalDirty = false;
  _ggiModalHistoryPushed = false;
  // Limpieza del listener de postMessage del iframe embed (punto único de cierre).
  if (_ggiEmbedHandler) { window.removeEventListener('message', _ggiEmbedHandler); _ggiEmbedHandler = null; }
  _ggiModalFocusRestore(); // a11y: quitar trampa de foco y devolverlo al disparador
}

/* ── mantCloseOnBg: cierra al tocar el fondo ── */
function mantCloseOnBg(e) {
  if (e.target !== document.getElementById('mant-modal')) return;
  // En móvil los modales grandes (iframe, pantalla completa) NO cierran al tocar
  // fuera; los popups simples sí (tienen fondo oscurecido visible).
  if (_ggiIsMobile()) {
    var box = document.querySelector('#mant-modal .mant-modal-box');
    if (box && box.classList.contains('mant-modal-lg')) return;
  }
  ggiModalClose();
}

/* ════════════════════════════════════════════════════════════
   ggiModalClose — punto de entrada único para cerrar modal.
   Respeta dirty-check y el historial de navegación.
   ════════════════════════════════════════════════════════════ */
window.ggiModalClose = function(force) {
  if (!_ggiModalOpen) return;

  if (!force && _ggiModalDirty) {
    // Mostrar confirmación de cambios sin guardar
    _ggiDirtyCallback = function() { _ggiDoClose(); };
    document.getElementById('ggi-dirty-banner').classList.add('open');
    return;
  }
  _ggiDoClose();
};

function _ggiDoClose() {
  document.getElementById('ggi-dirty-banner').classList.remove('open');

  // Capturar el flag ANTES de mantClose(): mantClose() resetea
  // _ggiModalHistoryPushed=false, así que leerlo después siempre daría false y
  // la entrada ficticia del historial nunca se limpiaría (quedaba colgada en
  // móvil → navegación/scroll inconsistentes al cerrar con Escape o ←Volver).
  var _hadHistory = _ggiModalHistoryPushed;

  // ── CORRECTO: cerrar el modal PRIMERO (sincrónico, sin flash) ──────────────
  // Luego limpiar el historial silenciosamente si se había hecho pushState.
  // El orden anterior era el inverso (history.back → popstate → mantClose),
  // lo que producía una página en blanco durante la transición.
  mantClose(); // cierre inmediato — el usuario ve el listado al instante

  if (_hadHistory) {
    // Limpiar la entrada ficticia del historial sin efecto visual.
    // _ggiIgnoreNextPopstate evita que el SPA router o nuestro handler reaccionen.
    _ggiIgnoreNextPopstate = true;
    history.back();
  }
}

/* ── Dirty check: banner de confirmación ── */
window.ggiDirtyCancel = function() {
  document.getElementById('ggi-dirty-banner').classList.remove('open');
  _ggiDirtyCallback = null;
};
window.ggiDirtyConfirm = function() {
  document.getElementById('ggi-dirty-banner').classList.remove('open');
  _ggiModalDirty = false;
  if (_ggiDirtyCallback) { _ggiDirtyCallback(); _ggiDirtyCallback = null; }
};

/* ── pushState para capturar el botón Atrás del dispositivo ── */
function _ggiModalPushState(titulo) {
  if (_ggiModalHistoryPushed) return;
  try {
    history.pushState({ ggiModal: true, titulo: titulo || '' }, '', location.href);
    _ggiModalHistoryPushed = true;
  } catch(e) {}
}

/* ── popstate: gestión de historial del modal ──────────────────────────────
   Fase CAPTURA → se ejecuta antes que el SPA router.
   Tres casos posibles:

   A) _ggiIgnoreNextPopstate = true
      → Nosotros llamamos history.back() para limpiar la entrada ficticia.
        El modal ya está cerrado. Sólo suprimir el evento, no navegar.

   B) _ggiModalOpen = true (botón físico Atrás del dispositivo)
      → El usuario presionó el back button nativo con el modal abierto.
        Cerramos el modal; el navegador ya revirtió la URL (misma URL).
        No necesitamos hacer history.back() adicional.

   C) Ninguno de los anteriores
      → Navegación SPA normal; no intervenir.
   ─────────────────────────────────────────────────────────────────────── */
window.addEventListener('popstate', function(ev) {
  if (_ggiIgnoreNextPopstate) {
    // Caso A: limpieza silenciosa después del cierre por botón ← Volver
    ev.stopImmediatePropagation();
    _ggiIgnoreNextPopstate = false;
    return;
  }
  if (_ggiModalOpen) {
    // Caso B: botón físico Atrás con modal abierto
    ev.stopImmediatePropagation();
    _ggiModalHistoryPushed = false; // el historial ya retrocedió
    mantClose();
    return;
  }
  // Caso C: navegación normal — dejar que el SPA router maneje
}, true);

// ── ggiConfirm — Modal de confirmación premium ────────────────────────────
// Uso: const ok = await ggiConfirm('Título', 'Mensaje', { type:'danger', confirmText:'Eliminar' });
// type: 'danger' | 'warning' | 'info' | 'success'   (default: 'danger')
window.ggiConfirm = (function() {
  // Inyectar estilos una sola vez
  function _ensureStyles() {
    if (document.getElementById('_gci-css')) return;
    var s = document.createElement('style');
    s.id = '_gci-css';
    s.textContent =
      '#_gci-ov{position:fixed;inset:0;z-index:199999;display:flex;align-items:center;justify-content:center;padding:20px;' +
        'background:rgba(0,0,0,.48);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);' +
        'animation:_gci-fo .18s ease forwards;}' +
      '#_gci-ov.closing{animation:_gci-fo .16s ease reverse forwards;}' +
      '._gci-box{background:var(--bg-surface,#fff);border:1px solid var(--brd,#e2e8f0);border-radius:20px;' +
        'box-shadow:0 32px 80px rgba(0,0,0,.22),0 8px 24px rgba(0,0,0,.1);' +
        'max-width:396px;width:100%;padding:28px 26px 22px;' +
        'animation:_gci-si .24s cubic-bezier(.16,1,.3,1);' +
        'font-family:"Inter",sans-serif;}' +
      '._gci-hd{display:flex;align-items:flex-start;gap:16px;margin-bottom:22px;}' +
      '._gci-ic{flex-shrink:0;width:44px;height:44px;border-radius:13px;' +
        'display:flex;align-items:center;justify-content:center;}' +
      '._gci-ic.d{background:var(--s-err-bg,#fee2e2);color:var(--s-err,#dc2626);}' +
      '._gci-ic.w{background:var(--s-warn-bg,#fef3c7);color:var(--s-warn,#d97706);}' +
      '._gci-ic.i{background:var(--s-info-bg,#f0f9ff);color:var(--s-info,#0369a1);}' +
      '._gci-ic.s{background:var(--s-ok-bg,#dcfce7);color:var(--s-ok,#059669);}' +
      '._gci-ti{font-size:15.5px;font-weight:750;color:var(--tx,#0f172a);line-height:1.3;letter-spacing:-.02em;margin-bottom:5px;}' +
      '._gci-ms{font-size:13px;color:var(--tx2,#64748b);line-height:1.6;}' +
      '._gci-ft{display:flex;gap:9px;justify-content:flex-end;margin-top:4px;}' +
      '._gci-cn{padding:9px 20px;border-radius:10px;border:1.5px solid var(--brd,#e2e8f0);' +
        'background:var(--bg-surface,#fff);color:var(--tx,#0f172a);' +
        'font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;' +
        'transition:background .14s,border-color .14s;}' +
      '._gci-cn:hover{background:var(--bg-hover,#f1f5f9);border-color:var(--tx3,#94a3b8);}' +
      '._gci-ok{padding:9px 22px;border-radius:10px;border:none;' +
        'font-size:13px;font-weight:700;cursor:pointer;color:#fff;font-family:inherit;' +
        'transition:opacity .14s,transform .1s;letter-spacing:-.01em;}' +
      '._gci-ok:hover{opacity:.88;}._gci-ok:active{transform:scale(.97);}' +
      '._gci-ok.d{background:linear-gradient(135deg,#f87171,#dc2626);}' +
      '._gci-ok.w{background:linear-gradient(135deg,#fbbf24,#d97706);}' +
      '._gci-ok.i{background:linear-gradient(135deg,#60a5fa,#2563eb);}' +
      '._gci-ok.s{background:linear-gradient(135deg,#34d399,#059669);}' +
      '@keyframes _gci-fo{from{opacity:0}to{opacity:1}}' +
      '@keyframes _gci-si{from{opacity:0;transform:scale(.93) translateY(10px)}to{opacity:1;transform:scale(1) translateY(0)}}' +
      /* Táctil: targets ≥44px y más separación entre Cancelar y la acción
         destructiva (Apple HIG / Material) para evitar mis-taps. */
      '@media (hover:none){' +
        '._gci-cn,._gci-ok{min-height:44px;padding-top:11px;padding-bottom:11px;}' +
        '._gci-ft{gap:14px;}' +
      '}';
    document.head.appendChild(s);
  }

  var ICONS = {
    d: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
    w: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
    i: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>',
    s: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
  };

  return function(title, message, opts) {
    opts = opts || {};
    var rawType     = opts.type        || 'danger';
    var confirmText = opts.confirmText || 'Confirmar';
    var cancelText  = opts.cancelText  || 'Cancelar';

    // Normalize type to single-char key
    var k = { danger:'d', warning:'w', info:'i', success:'s' }[rawType] || 'd';

    _ensureStyles();

    return new Promise(function(resolve) {
      var prev = document.getElementById('_gci-ov');
      if (prev) prev.remove();

      var ov = document.createElement('div');
      ov.id = '_gci-ov';

      ov.innerHTML =
        '<div class="_gci-box">' +
          '<div class="_gci-hd">' +
            '<div class="_gci-ic ' + k + '">' + (ICONS[k]) + '</div>' +
            '<div style="flex:1;min-width:0;">' +
              '<div class="_gci-ti">' + (title   || 'Confirmar acción') + '</div>' +
              '<div class="_gci-ms">' + (message || '') + '</div>' +
            '</div>' +
          '</div>' +
          '<div class="_gci-ft">' +
            '<button class="_gci-cn" id="_gci-cancel">' + cancelText  + '</button>' +
            '<button class="_gci-ok ' + k + '" id="_gci-ok">' + confirmText + '</button>' +
          '</div>' +
        '</div>';

      document.body.appendChild(ov);
      document.getElementById('_gci-ok').focus();

      function done(val) {
        ov.classList.add('closing');
        var box = ov.querySelector('._gci-box');
        if (box) { box.style.transition = 'opacity .14s,transform .14s'; box.style.opacity = '0'; box.style.transform = 'scale(.96) translateY(4px)'; }
        setTimeout(function() { ov.remove(); resolve(val); }, 180);
      }

      document.getElementById('_gci-ok').addEventListener('click',     function() { done(true);  });
      document.getElementById('_gci-cancel').addEventListener('click', function() { done(false); });
      ov.addEventListener('click', function(e) { if (e.target === ov) done(false); });

      // Keyboard: Escape = cancel, Enter on OK button = confirm
      var _kh = function(e) {
        if (e.key === 'Escape') { document.removeEventListener('keydown', _kh); done(false); }
        if (e.key === 'Enter' && document.activeElement === document.getElementById('_gci-ok')) {
          document.removeEventListener('keydown', _kh); done(true);
        }
      };
      document.addEventListener('keydown', _kh);
    });
  };
}());

// ── Modal de formulario embed (iframe + postMessage) ──────────────
// Definido en scope global para que el SPA no rompa la referencia.
var _ggiEmbedHandler = null;

function ggiOpenForm(url, titulo, onSuccess) {
  var modal   = document.getElementById('mant-modal');
  var box     = document.querySelector('#mant-modal .mant-modal-box');
  var body    = document.getElementById('mant-modal-body');
  var titleEl = document.getElementById('mant-modal-title');
  if (!modal || !box || !body) return;

  // Limpiar listener anterior si quedó pendiente
  if (_ggiEmbedHandler) {
    window.removeEventListener('message', _ggiEmbedHandler);
    _ggiEmbedHandler = null;
  }

  // Resetear estado
  _ggiModalDirty = false;
  _ggiModalHistoryPushed = false;

  // Expandir modal al modo formulario
  box.classList.add('mant-modal-lg');
  titleEl.textContent = titulo || '';

  // Montar spinner + iframe en el body del modal.
  body.style.position = 'relative';
  body.innerHTML =
    '<div id="ggi-spin" style="position:absolute;inset:0;display:flex;align-items:center;'
    + 'justify-content:center;gap:8px;font-size:13px;color:var(--gris-texto,#64748b);'
    + 'background:var(--bg-surface,#fff);z-index:2;border-radius:0 0 14px 14px;">'
    + '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
    + ' style="animation:ggiSpin .8s linear infinite;flex-shrink:0">'
    + '<path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83'
    + 'M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>'
    + 'Cargando…</div>'
    + '<iframe id="ggi-embed-frame" class="ggi-embed-frame" allowfullscreen'
    + ' style="opacity:0;transition:opacity .25s"></iframe>';

  // Abrir modal
  modal.classList.add('open');
  document.body.style.overflow = 'hidden';
  _ggiModalOpen = true;

  // ── MOBILE: push history state para capturar botón Atrás ──
  if (_ggiIsMobile()) {
    _ggiModalPushState(titulo);
  }

  // A11y: guardar disparador, atrapar foco y moverlo al botón Cerrar (el form
  // vive en el iframe, sin punto de foco accesible en el documento padre).
  _ggiModalFocusActivate(true);

  // Asignar src DESPUÉS de que el iframe está en el DOM
  var frame = document.getElementById('ggi-embed-frame');
  var sep   = url.indexOf('?') === -1 ? '?' : '&';
  frame.src = url + sep + 'embed=1';

  // Ocultar spinner cuando el iframe termine de cargar
  frame.addEventListener('load', function onFrameLoad() {
    var sp = document.getElementById('ggi-spin');
    if (sp) sp.remove();
    frame.style.opacity = '1';
    frame.removeEventListener('load', onFrameLoad);
  });

  // Escuchar postMessages desde el iframe
  _ggiEmbedHandler = function (ev) {
    if (!ev.data || typeof ev.data !== 'object') return;

    // ── Formulario tiene cambios sin guardar ──
    if (ev.data.ggi === 'dirty') {
      _ggiModalDirty = !!ev.data.dirty;
      return;
    }

    // ── Usuario pidió cerrar desde dentro del iframe ──
    // NO removemos el listener aquí: si el form está "dirty", ggiModalClose muestra
    // el banner de confirmación y el modal sigue abierto; el listener debe seguir vivo
    // para recibir un guardado/cierre posterior. mantClose lo limpia al cerrar de verdad.
    if (ev.data.ggi === 'close') {
      ggiModalClose();
      return;
    }

    // ── Formulario guardado con éxito ──
    if (ev.data.ggi !== 'created' && ev.data.ggi !== 'updated') return;
    window.removeEventListener('message', _ggiEmbedHandler);
    _ggiEmbedHandler = null;
    _ggiModalDirty = false;          // limpiado: no hay cambios pendientes
    mantClose();

    if (typeof onSuccess === 'function') {
      onSuccess(ev.data);
      return;
    }

    // Navegar al listado destacando la fila recién creada
    var destBase = window.location.pathname + window.location.search;
    try {
      var destUrl = new URL(destBase, window.location.origin);
      if (ev.data.id)  destUrl.searchParams.set('nuevo', ev.data.id);
      if (ev.data.num) destUrl.searchParams.set('num',   ev.data.num || ev.data.numero || '');
      destBase = destUrl.pathname + (destUrl.search || '');
    } catch(_) {}

    if (typeof window.ggiNavigate === 'function') {
      window.ggiNavigate(destBase);
    } else {
      window.location.href = destBase;
    }
  };
  window.addEventListener('message', _ggiEmbedHandler);
}

// ── Motor de mantenimiento ──
function renderMantenimiento(titulo, campos, filas, columnas, editId, msg) {
  const root = document.getElementById('mant-root');
  if (!root) return;

  window.__mant = { titulo, campos, editId, tabla: (window.__mantData && window.__mantData.tabla) || '' };

  function buildForm(isEdit, rowData) {
    const t = isEdit ? titulo : titulo.replace(/^Editar\s+/, 'Nuevo ');
    const fc = campos.map(c => {
      const val = isEdit ? (rowData ? (rowData[c.name] || '') : (c.value || '')) : '';
      const req = c.required ? ' <span style="color:var(--red)">*</span>' : '';

      if (c.type === 'textarea') {
        return `<div class="field"><label>${c.label}${req}</label><textarea name="${c.name}" placeholder="${c.placeholder || ''}">${val}</textarea></div>`;
      }
      if (c.type === 'select') {
        const opts = c.options.map(o => `<option value="${o.value}"${val == o.value ? ' selected' : ''}>${o.label}</option>`).join('');
        return `<div class="field">
          <label>${c.label}${req}</label>
          <div style="position:relative">
            <select name="${c.name}" style="-webkit-appearance:none;appearance:none;padding-right:32px;width:100%;padding:9px 32px 9px 12px;border:1.5px solid var(--gris-borde);border-radius:9px;font-size:13px;font-family:'Inter',sans-serif;color:var(--gris-dark);background:#fafbfe;outline:none;background-image:url('data:image/svg+xml,%3Csvg xmlns=\\'http://www.w3.org/2000/svg\\' width=\\'12\\' height=\\'12\\' viewBox=\\'0 0 24 24\\' fill=\\'none\\' stroke=\\'%236b7a99\\' stroke-width=\\'2.5\\'%3E%3Cpolyline points=\\'6 9 12 15 18 9\\'/%3E%3C/svg%3E');background-repeat:no-repeat;background-position:right 10px center;">
              ${opts}
            </select>
          </div>
        </div>`;
      }
      if (c.type === 'file') {
        // Preview: use c.preview (static) or derive from rowData value + previewBaseUrl (modal edit)
        let previewUrl = c.preview || null;
        if (!previewUrl && val && c.previewBaseUrl) previewUrl = c.previewBaseUrl + val;
        const previewHtml = previewUrl
          ? `<div style="margin-bottom:8px"><img id="mant-file-prev-${c.name}" src="${previewUrl}" alt="Foto actual" style="width:64px;height:64px;border-radius:50%;object-fit:cover;border:2px solid var(--gris-borde);display:block"></div>`
          : `<div style="margin-bottom:8px"><img id="mant-file-prev-${c.name}" src="" alt="" style="width:64px;height:64px;border-radius:50%;object-fit:cover;border:2px solid var(--gris-borde);display:none"></div>`;
        return `<div class="field">
          <label>${c.label}${req}</label>
          ${previewHtml}
          <input type="file" name="${c.name}" accept="${c.accept || 'image/*'}" style="padding:6px 10px;font-size:12px"
            onchange="(function(e){var f=e.target.files[0];if(!f)return;var img=document.getElementById('mant-file-prev-${c.name}');img.src=URL.createObjectURL(f);img.style.display='block';})(event)">
          ${c.hint ? `<span style="font-size:11px;color:var(--gris-texto)">${c.hint}</span>` : ''}
        </div>`;
      }
      // Campo contraseña con generador automático
      if (c.inputType === 'password') {
        const opcional = !c.required ? ' <span style="font-size:10px;color:var(--gris-texto);font-weight:400;">(opcional)</span>' : '';
        return `<div class="field">
          <label>${c.label}${req}${opcional}</label>
          <div style="display:flex;gap:6px;align-items:center;">
            <div style="position:relative;flex:1;">
              <input type="password" name="${c.name}" id="mant-pass-input" value="${val}" placeholder="${c.placeholder || ''}"
                style="width:100%;padding:9px 36px 9px 12px;border:1.5px solid var(--gris-borde);border-radius:9px;font-size:13px;font-family:'Inter',sans-serif;color:var(--gris-dark);background:#fafbfe;outline:none;">
              <button type="button" onclick="toggleMantPass()" title="Ver contraseña"
                style="position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--gris-texto);display:flex;align-items:center;padding:2px;">
                <svg id="mant-eye-ico" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              </button>
            </div>
            <button type="button" onclick="generarPass()" title="Generar contraseña segura"
              style="flex-shrink:0;padding:9px 12px;border:1.5px solid var(--gris-borde);border-radius:9px;background:#fff;cursor:pointer;font-size:11px;font-weight:600;color:var(--azul);white-space:nowrap;font-family:'Inter',sans-serif;display:flex;align-items:center;gap:5px;">
              <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
              Generar
            </button>
          </div>
          <div id="mant-pass-hint" style="font-size:11px;color:var(--gris-texto);margin-top:4px;"></div>
        </div>`;
      }
      // Selector mes+día (sin año)
      if (c.inputType === 'month-day') {
        let _m = 0, _d = 0;
        if (val) {
          const pts = val.split('-');
          if (pts.length === 3) { _m = parseInt(pts[1], 10); _d = parseInt(pts[2], 10); }
          else if (pts.length === 2) { _m = parseInt(pts[0], 10); _d = parseInt(pts[1], 10); }
        }
        const _meses = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
        const _mOpts = '<option value="">— Mes —</option>' + _meses.map((m, i) => {
          const v = String(i + 1).padStart(2, '0');
          return `<option value="${v}"${_m === i + 1 ? ' selected' : ''}>${m}</option>`;
        }).join('');
        const _dOpts = '<option value="">— Día —</option>' + Array.from({length: 31}, (_, i) => {
          const v = String(i + 1).padStart(2, '0');
          return `<option value="${v}"${_d === i + 1 ? ' selected' : ''}>${i + 1}</option>`;
        }).join('');
        const _hidVal = (_m > 0 && _d > 0) ? '2000-' + String(_m).padStart(2,'0') + '-' + String(_d).padStart(2,'0') : '';
        const _hint = c.hint ? `<span style="font-size:11px;color:var(--gris-texto)">${c.hint}</span>` : '';
        const _selStyle = 'flex:1;padding:9px 12px;border:1.5px solid var(--gris-borde);border-radius:9px;font-size:13px;font-family:\'Inter\',sans-serif;color:var(--gris-dark);background:#fafbfe;outline:none;';
        return `<div class="field">
          <label>${c.label}${req}</label>
          <input type="hidden" name="${c.name}" id="mant-md-${c.name}" value="${_hidVal}">
          <div style="display:flex;gap:8px;">
            <select id="mant-md-mes-${c.name}" onchange="mantMdUpdate('${c.name}')" style="${_selStyle}">${_mOpts}</select>
            <select id="mant-md-dia-${c.name}" onchange="mantMdUpdate('${c.name}')" style="${_selStyle}max-width:90px;">${_dOpts}</select>
          </div>
          ${_hint}
        </div>`;
      }
      return `<div class="field"><label>${c.label}${req}</label><input type="${c.inputType || 'text'}" name="${c.name}" value="${val}" placeholder="${c.placeholder || ''}"></div>`;
    }).join('');

    const rowId = isEdit ? (rowData ? rowData.id : editId) : '';
    return { titulo: t, html: `
      <form onsubmit="mantAjaxSubmit(event, '${window.location.pathname}', ${rowId || 0})">
        <input type="hidden" name="_csrf"  value="${_csrfToken()}">
        <input type="hidden" name="accion" value="guardar">
        <input type="hidden" name="id"     value="${rowId}">
        ${fc}
        <div style="display:flex;gap:10px;margin-top:8px;flex-wrap:wrap;">
          <button type="submit" class="btn btn-primary btn-sm" id="mant-save-btn">
            <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
            ${isEdit ? 'Actualizar' : 'Guardar'}
          </button>
          <button type="button" onclick="mantClose()" class="btn btn-outline btn-sm">Cancelar</button>
        </div>
      </form>` };
  }

  window.__mantBuildForm = buildForm;

  const __mantIconos = {
    normas_iso:          `<svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11"/></svg>`,
    casas_certificadoras:`<svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>`,
    agencias_envio:      `<svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>`,
    estados_envio:       `<svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/></svg>`,
    usuarios:            `<svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>`,
  };
  const __mantTabla = (window.__mantData && window.__mantData.tabla) || '';
  const __mantIcono = __mantIconos[__mantTabla] || `<svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/></svg>`;

  const theads = columnas.map(c => `<th>${c}</th>`).join('') + '<th>Estado</th><th></th>';
  const tbody = filas.length === 0
    ? `<tr><td colspan="${columnas.length + 2}" class="empty-state">Sin registros aún.</td></tr>`
    : filas.map(f => {
        const tds = f.cols.map((c, i) => {
          if (i === 0) {
            return `<td><div style="display:flex;align-items:flex-start;gap:8px;"><span class="mobile-icon mant-row-icon">${__mantIcono}</span><span class="mant-first-col">${c || '—'}</span></div></td>`;
          }
          return `<td data-label="${columnas[i] || ''}">${c || '—'}</td>`;
        }).join('');
        const isActivo = f.activo != 0;
        const badge = isActivo ? `<span class="badge-activo">Activo</span>` : `<span class="badge-inactivo">Inactivo</span>`;
        return `<tr data-activo="${isActivo ? '1' : '0'}" data-mant-id="${f.id}" style="${isActivo ? '' : 'opacity:.6;background:var(--gris-bg,#f8f9fd);'}">
          ${tds}<td data-label="Estado">${badge}</td>
          <td style="white-space:nowrap">
            <div style="display:flex;gap:4px;">
              <button type="button" onclick="mantEditRow(${f.id})" class="btn-ic btn-ic-blue" title="Editar">
                <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
              </button>
              <button type="button" onclick="mantToggleRow(${f.id}, this)" class="btn-ic ${isActivo ? 'btn-ic-amber' : 'btn-ic-green'}" title="${isActivo ? 'Desactivar' : 'Activar'}">
                <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M18.36 6.64a9 9 0 1 1-12.73 0"/><line x1="12" y1="2" x2="12" y2="12"/></svg>
              </button>
              <button type="button" onclick="mantDeleteRow(${f.id})" class="btn-ic btn-ic-red" title="Eliminar">
                <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
              </button>
            </div>
          </td>
        </tr>`;
      }).join('');

  root.innerHTML = `
    <div class="mant-table-card">
      <div class="mant-card-header">
        <div class="mant-card-title">Registros (${filas.length})</div>
        <button type="button" onclick="mantNuevo()" class="btn btn-primary btn-sm">
          <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Nuevo
        </button>
      </div>
      <div style="overflow-x:auto">
        <table class="mant-table">
          <thead><tr>${theads}</tr></thead>
          <tbody>${tbody}</tbody>
        </table>
      </div>
    </div>`;
}

function mantNuevo() {
  if (!window.__mantBuildForm) {
    if (window.__mantData && typeof renderMantenimiento === 'function') {
      var _d = window.__mantData;
      renderMantenimiento(_d.titulo, _d.campos, _d.filas, _d.columnas, _d.editId, _d.msg);
      if (typeof window.__mantPostRender === 'function') window.__mantPostRender();
    }
    if (!window.__mantBuildForm) return;
  }
  const { titulo, html } = window.__mantBuildForm(false, null);
  mantOpen(titulo, html);
}

async function mantEditRow(id) {
  if (!window.__mant) {
    if (window.__mantData && typeof renderMantenimiento === 'function') {
      var _d = window.__mantData;
      renderMantenimiento(_d.titulo, _d.campos, _d.filas, _d.columnas, _d.editId, _d.msg);
      if (typeof window.__mantPostRender === 'function') window.__mantPostRender();
    }
    if (!window.__mant) return;
  }
  try {
    const res  = await fetch(`${window.APP_URL}/modules/mantenimiento/ajax_get.php?tabla=${window.__mant.tabla}&id=${id}`);
    const json = await res.json();
    if (!json.ok) { showToast('Error al cargar datos.', 'error'); return; }
    const { titulo, html } = window.__mantBuildForm(true, json.data);
    mantOpen(titulo, html);
  } catch(e) {
    showToast('Error de conexión.', 'error');
  }
}


// ── Helpers modal de mantenimiento ──
function generarPass() {
  const chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789!@#$%';
  let pass = '';
  for (let i = 0; i < 12; i++) pass += chars[Math.floor(Math.random() * chars.length)];
  const inp = document.getElementById('mant-pass-input');
  if (!inp) return;
  inp.value = pass;
  inp.type  = 'text'; // mostrar para que se vea
  const hint = document.getElementById('mant-pass-hint');
  if (hint) {
    hint.textContent = '✓ Contraseña generada: ' + pass + ' — cópiala antes de guardar';
    hint.style.color = 'var(--verde, #2DB140)';
  }
  // Cambiar ícono del ojo a "ocultar"
  const ico = document.getElementById('mant-eye-ico');
  if (ico) ico.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>';
}

function mantMdUpdate(name) {
  const m = document.getElementById('mant-md-mes-' + name);
  const d = document.getElementById('mant-md-dia-' + name);
  const h = document.getElementById('mant-md-' + name);
  if (!h) return;
  h.value = (m && d && m.value && d.value) ? '2000-' + m.value + '-' + d.value : '';
}

function toggleMantPass() {
  const inp = document.getElementById('mant-pass-input');
  const ico = document.getElementById('mant-eye-ico');
  if (!inp) return;
  const show = inp.type === 'password';
  inp.type = show ? 'text' : 'password';
  if (ico) ico.innerHTML = show
    ? '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>'
    : '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
}

async function mantAjaxSubmit(e, url, rowId) {
  e.preventDefault();
  const form = e.target;

  // Validar contraseña si el campo existe
  const passInput = form.querySelector('[name="password"]');
  if (passInput) {
    const passVal  = passInput.value.trim();
    const isNew    = !rowId || rowId === 0;
    let passErrDiv = form.querySelector('.mant-pass-err');
    if (!passErrDiv) {
      passErrDiv = document.createElement('div');
      passErrDiv.className = 'mant-pass-err';
      passErrDiv.style.cssText = 'font-size:11px;color:var(--red,#ef4444);margin-top:3px;';
      passInput.closest('.field').appendChild(passErrDiv);
    }
    passErrDiv.textContent = '';
    passInput.style.borderColor = '';
    if (isNew && passVal.length === 0) {
      passErrDiv.textContent = 'La contraseña es requerida para nuevo usuario.';
      passInput.style.borderColor = 'var(--red,#ef4444)';
      passInput.focus();
      return;
    }
    if (passVal.length > 0 && passVal.length < 8) {
      passErrDiv.textContent = 'Mínimo 8 caracteres.';
      passInput.style.borderColor = 'var(--red,#ef4444)';
      passInput.focus();
      return;
    }
  }

  const btn  = form.querySelector('#mant-save-btn');
  const btnOrigText = rowId > 0 ? 'Actualizar' : 'Guardar';
  btn.disabled = true;
  btn.textContent = 'Guardando...';

  // Actualizar el _csrf del form con el token más reciente antes de enviar
  const csrfInput = form.querySelector('[name="_csrf"]');
  if (csrfInput) csrfInput.value = _csrfToken();

  try {
    const formData = new FormData(form);
    const res = await fetch(url, {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: formData
    });
    const json = await res.json();

    // Actualizar CSRF si el servidor devuelve token renovado
    if (json.csrf) _csrfUpdate(json.csrf);

    if (json.ok) {
      mantClose();
      showToast(json.msg || 'Guardado exitosamente.', 'ok');
      // Refrescar tabla via AJAX sin recargar la página completa
      _mantRefreshTable();
    } else {
      showToast(json.msg || 'Error al guardar.', 'error');
      btn.disabled = false;
      btn.textContent = btnOrigText;
    }
  } catch(err) {
    showToast('Error de conexión.', 'error');
    btn.disabled = false;
    btn.textContent = btnOrigText;
  }
}

// ── Inicialización del motor de mantenimiento ──
// Se llama tanto en carga directa como en navegación SPA (navigate() lo invoca
// explícitamente). El DOMContentLoaded es seguridad adicional para casos donde
// el script se evaluó antes de que el DOM estuviera listo.
function _initMantUI() {
  if (!window.__mantData) return;
  var d = window.__mantData;
  if (!Array.isArray(d.campos) || !Array.isArray(d.filas)) return; // datos incompletos
  try {
    renderMantenimiento(d.titulo, d.campos, d.filas, d.columnas, d.editId, d.msg);
    if (typeof window.__mantPostRender === 'function') window.__mantPostRender();
  } catch(e) {
    console.error('[GGI] renderMantenimiento error:', e);
  }
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', _initMantUI);
} else {
  _initMantUI();
}

// ── Toggle activo/inactivo vía AJAX (sin recargar página) ──
async function mantToggleRow(id, btn) {
  const tr      = btn.closest('tr[data-mant-id]');
  const esActivo = tr && tr.dataset.activo === '1';
  const accion   = esActivo ? 'Desactivar' : 'Activar';
  const _confirmed1 = await ggiConfirm(accion + ' registro', '¿' + accion + ' este registro?', { type: esActivo ? 'warning' : 'info', confirmText: accion });
  if (!_confirmed1) return;

  try {
    // silent: los avisos de esta función son propios (incluye "_noaction")
    const json = await ggiPost(window.location.pathname, { accion: 'toggle', id: id }, { silent: true });
    // Si hay un mensaje de aviso (incluyendo "no puedes desactivarte"), mostrarlo y salir
    if (json.msg && (!json.ok || json._noaction)) { showToast(json.msg, json.ok ? 'ok' : 'error'); return; }

    // Actualizar fila sin recarga completa
    const nuevoActivo = !esActivo;
    if (tr) {
      tr.dataset.activo    = nuevoActivo ? '1' : '0';
      tr.style.opacity     = nuevoActivo ? ''  : '0.6';
      tr.style.background  = nuevoActivo ? ''  : 'var(--gris-bg,#f8f9fd)';
      const badgeTd = tr.querySelector('[data-label="Estado"]');
      if (badgeTd) badgeTd.innerHTML = nuevoActivo
        ? '<span class="badge-activo">Activo</span>'
        : '<span class="badge-inactivo">Inactivo</span>';
      btn.className = 'btn-ic ' + (nuevoActivo ? 'btn-ic-amber' : 'btn-ic-green');
      btn.title     = nuevoActivo ? 'Desactivar' : 'Activar';
    }
    // Actualizar datos en memoria y contadores del filtro
    if (window.__mantData && window.__mantData.filas) {
      const f = window.__mantData.filas.find(function(x){ return x.id == id; });
      if (f) f.activo = nuevoActivo ? 1 : 0;
    }
    _usrActualizarContadores();
    showToast(nuevoActivo ? 'Activado correctamente.' : 'Desactivado correctamente.', 'ok');
  } catch(e) {
    showToast('Error de conexión.', 'error');
  }
}

// ── Eliminar registro vía AJAX ──
async function mantDeleteRow(id) {
  const _confirmed2 = await ggiConfirm('Eliminar registro', '¿Eliminar este registro? Esta acción no se puede deshacer.', { type: 'danger', confirmText: 'Eliminar' });
  if (!_confirmed2) return;
  try {
    // silent: esta función muestra sus propios mensajes de éxito y error
    const json = await ggiPost(window.location.pathname, { accion: 'eliminar', id: id }, { silent: true });
    if (json.ok || json.ok === undefined) {
      const tr = document.querySelector('tr[data-mant-id="' + id + '"]');
      if (tr) tr.remove();
      if (window.__mantData && window.__mantData.filas)
        window.__mantData.filas = window.__mantData.filas.filter(function(x){ return x.id != id; });
      _usrActualizarContadores();
      showToast(json.msg || 'Registro eliminado.', 'ok');
    } else {
      showToast(json.msg || 'Error al eliminar.', 'error');
    }
  } catch(e) {
    showToast('Error de conexión.', 'error');
  }
}

// ── Actualiza los contadores del filtro de usuarios ──
function _usrActualizarContadores() {
  var filas = (window.__mantData && window.__mantData.filas) || [];
  var total    = filas.length;
  var activos  = filas.filter(function(f){ return f.activo == 1; }).length;
  var inactivos= filas.filter(function(f){ return f.activo != 1; }).length;
  var bar = document.getElementById('usr-filter-bar');
  if (!bar) return;
  var btns = bar.querySelectorAll('[data-usr-filtro]');
  var counts = [total, activos, inactivos];
  btns.forEach(function(b, i) {
    var span = b.querySelector('span');
    if (span) span.textContent = counts[i];
  });
  // Actualizar contador del card header
  var titulo = document.querySelector('#mant-root .mant-card-title');
  if (titulo) titulo.textContent = 'Registros (' + total + ')';
}

// ── Refrescar tabla de mantenimiento vía AJAX (sin recargar página) ──
async function _mantRefreshTable() {
  try {
    const r = await fetch(window.location.href, { credentials: 'same-origin' });
    if (!r.ok) return;
    const html = await r.text();
    const parser = new DOMParser();
    const doc = parser.parseFromString(html, 'text/html');
    // Ejecutar los scripts inline del nuevo <main> para actualizar __mantData y hooks
    const newMain = doc.querySelector('.main');
    if (!newMain) return;
    const inlineScripts = Array.from(newMain.querySelectorAll('script:not([src])'));
    inlineScripts.forEach(function(s) {
      try { eval(s.textContent); } catch(ex) {}
    });
    // Re-renderizar tabla con los datos frescos
    if (window.__mantData && window.__mantData.editId !== undefined) {
      window.__mantData.editId = 0; // reset edit mode tras guardar
    }
    if (typeof _initMantUI === 'function') _initMantUI();
  } catch(e) {
    // Si falla el fetch, el toast ya informó el éxito — no hacer nada más
  }
}

// ── Auto-mejorar botones "← Volver" ──
(function() {
  function enhanceVolverButtons() {
    document.querySelectorAll('a.btn-outline, a.btn, a.back-link').forEach(function(el) {
      if (el.classList.contains('btn-back')) return;
      var txt = el.textContent.trim();
      if (txt.indexOf('←') === -1) return;
      el.classList.add('btn-back');
      el.innerHTML = el.innerHTML.replace(/←\s*/g,
        '<svg class="btn-back-icon" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>'
      );
    });
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', enhanceVolverButtons);
  } else {
    enhanceVolverButtons();
  }
})();

// ── Modal upload de documentos (popup) ──
function abrirSubirDoc(tipo, label, version) {
  var esAct = version > 0;
  var titulo = (esAct ? 'Actualizar: ' : 'Subir: ') + label;
  var vInfo = esAct
    ? '<div class="modal-version-info"><svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>Se guardará como versión v' + (version + 1) + '. La versión anterior queda en el historial.</div>'
    : '';
  mantOpen(titulo,
    vInfo +
    '<form id="modal-doc-form">' +
    '<input type="hidden" name="_csrf" value="' + _csrfToken() + '">' +
    '<input type="hidden" name="cliente_id" value="' + (window.__doc_cliente_id || 0) + '">' +
    '<input type="hidden" name="tipo" value="' + tipo + '">' +
    '<div class="modal-dz" id="modal-dz">' +
      '<svg width="32" height="32" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" style="color:var(--azul);opacity:0.45;margin:0 auto 10px;display:block;pointer-events:none;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>' +
      '<div style="font-size:13px;font-weight:600;color:var(--gris-dark);margin-bottom:3px;pointer-events:none;">Arrastra el archivo aquí</div>' +
      '<div style="font-size:11px;color:var(--gris-texto);margin-bottom:12px;pointer-events:none;">PDF, JPG, PNG, WEBP — máx. 10 MB</div>' +
      '<label for="modal-file-inp" class="btn btn-outline btn-sm" style="cursor:pointer;display:inline-flex;align-items:center;gap:6px;">' +
        '<svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="pointer-events:none;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>' +
        'Seleccionar archivo' +
      '</label>' +
    '</div>' +
    '<input type="file" id="modal-file-inp" name="archivo" accept=".pdf,.jpg,.jpeg,.png,.webp" style="display:none" onchange="modalDocPreview(this)">' +
    '<div class="modal-fp" id="modal-fp">' +
      '<svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="#2DB140" stroke-width="2" style="flex-shrink:0"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>' +
      '<span class="modal-fp-name" id="modal-fp-name"></span>' +
      '<button type="button" onclick="modalDocRemove()" style="background:none;border:none;cursor:pointer;color:var(--gris-texto);padding:2px 6px;font-size:15px;line-height:1;flex-shrink:0;" title="Quitar">✕</button>' +
    '</div>' +
    '<div id="modal-upload-prog" style="display:none;margin-top:12px;">' +
      '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px;">' +
        '<span style="font-size:11px;color:var(--gris-texto);">Subiendo...</span>' +
        '<span id="modal-upload-pct" style="font-size:11px;font-weight:700;color:var(--azul);">0%</span>' +
      '</div>' +
      '<div style="height:5px;background:var(--gris-borde);border-radius:999px;overflow:hidden;">' +
        '<div id="modal-upload-bar" style="height:100%;width:0%;background:var(--azul);border-radius:999px;transition:width .1s linear;"></div>' +
      '</div>' +
    '</div>' +
    '<div style="display:flex;gap:8px;margin-top:16px;">' +
      '<button type="submit" class="btn btn-primary btn-sm" id="modal-doc-btn" disabled>' +
        '<svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" style="flex-shrink:0;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg> Subir documento' +
      '</button>' +
      '<button type="button" class="btn btn-outline btn-sm" onclick="mantClose()">Cancelar</button>' +
    '</div>' +
    '</form>'
  );

  // ── Drag & drop robusto ──
  // Usamos el modal completo como zona de drop para máxima comodidad.
  // El archivo se guarda en dzFile para recuperarlo en el submit
  // aunque el truco DataTransfer no funcione en algún navegador.
  var dzFile   = null;
  var modal    = document.getElementById('mant-modal');
  var dz       = document.getElementById('modal-dz');
  var dzDepth  = 0; // contador para ignorar dragleave de elementos hijo

  function dzSetActive(on) {
    if (dz) dz.classList.toggle('dragover', on);
    if (modal) modal.classList.toggle('dz-over', on);
  }

  function dzHandleFile(file) {
    if (!file) return;
    dzFile = file;
    // Intentar asignar al input para que new FormData(form) lo incluya
    var inp = document.getElementById('modal-file-inp');
    try { var dt = new DataTransfer(); dt.items.add(file); inp.files = dt.files; } catch(x) {}
    modalDocPreview(inp, file);
  }

  if (modal) {
    modal.addEventListener('dragenter', function(e) {
      e.preventDefault();
      dzDepth++;
      dzSetActive(true);
    });
    modal.addEventListener('dragleave', function(e) {
      dzDepth--;
      if (dzDepth <= 0) { dzDepth = 0; dzSetActive(false); }
    });
    modal.addEventListener('dragover', function(e) {
      e.preventDefault(); // necesario para que drop funcione
    });
    modal.addEventListener('drop', function(e) {
      e.preventDefault();
      dzDepth = 0;
      dzSetActive(false);
      var f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
      if (f) dzHandleFile(f);
    });
  }

  // Submit via XHR (necesario para barra de progreso)
  var form = document.getElementById('modal-doc-form');
  if (form) {
    form.addEventListener('submit', function(e) {
      e.preventDefault();
      var btn      = document.getElementById('modal-doc-btn');
      var progWrap = document.getElementById('modal-upload-prog');
      var progBar  = document.getElementById('modal-upload-bar');
      var progPct  = document.getElementById('modal-upload-pct');

      var fd = new FormData(this);
      // Si DataTransfer falló, inyectar el archivo arrastrado manualmente
      var inp = document.getElementById('modal-file-inp');
      if (dzFile && (!inp || !inp.files || !inp.files[0])) {
        fd.set('archivo', dzFile, dzFile.name);
      }

      btn.disabled = true;
      btn.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="flex-shrink:0;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg> Subiendo...';
      if (progWrap) progWrap.style.display = 'block';

      var xhr = new XMLHttpRequest();
      xhr.open('POST', window.APP_URL + '/modules/documentos/subir.php');
      xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

      xhr.upload.addEventListener('progress', function(ev) {
        if (!ev.lengthComputable) return;
        var pct = Math.round(ev.loaded / ev.total * 100);
        if (progBar) progBar.style.width = pct + '%';
        if (progPct) progPct.textContent = pct + '%';
      });

      xhr.addEventListener('load', function() {
        if (progWrap) progWrap.style.display = 'none';
        try {
          var json = JSON.parse(xhr.responseText);
          if (json.ok) {
            mantClose();
            showToast(json.msg || 'Documento subido correctamente.', 'ok');
            _mantRefreshTable();
          } else {
            showToast(json.msg || 'Error al subir el documento.', 'error');
            btn.disabled = false;
            btn.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="flex-shrink:0;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg> Subir documento';
          }
        } catch(ex) {
          showToast('Respuesta inválida del servidor.', 'error');
          btn.disabled = false;
          btn.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="flex-shrink:0;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg> Subir documento';
        }
      });

      xhr.addEventListener('error', function() {
        if (progWrap) progWrap.style.display = 'none';
        showToast('Error de conexión al subir.', 'error');
        btn.disabled = false;
        btn.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="flex-shrink:0;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg> Subir documento';
      });

      xhr.send(fd);
    });
  }
}

function modalDocPreview(input, file) {
  var f = file || (input && input.files && input.files[0]);
  if (!f) return;
  var sz = f.size < 1048576 ? (f.size / 1024).toFixed(1) + ' KB' : (f.size / 1048576).toFixed(1) + ' MB';
  document.getElementById('modal-fp-name').textContent = f.name + ' (' + sz + ')';
  document.getElementById('modal-fp').classList.add('show');
  document.getElementById('modal-dz').style.display = 'none';
  document.getElementById('modal-doc-btn').disabled = false;
}

function modalDocRemove() {
  var inp = document.getElementById('modal-file-inp');
  if (inp) inp.value = '';
  document.getElementById('modal-fp').classList.remove('show');
  document.getElementById('modal-dz').style.display = 'block';
  document.getElementById('modal-doc-btn').disabled = true;
}
</script>

<style>
@keyframes spin { to { transform: rotate(360deg); } }

/* ── Inputs numéricos: sin flechitas (refuerzo para módulos con <style> propio) ── */
input[type="number"] { -moz-appearance: textfield !important; appearance: textfield !important; }
input[type="number"]::-webkit-inner-spin-button,
input[type="number"]::-webkit-outer-spin-button { -webkit-appearance: none !important; margin: 0 !important; }

/* ── Date inputs: estilo visual consistente ──────────────────────────────────
   input[type="date"] usa el picker nativo del navegador.
   Le damos el mismo look que el resto de los campos del sistema.
   ─────────────────────────────────────────────────────────────────────────── */
input[type="date"] {
  font-family: 'Inter', 'Inter', sans-serif;
  font-size: 13px;
  color: var(--tx, #1E2739);
  background: var(--bg-surface, #fff);
  border: 1.5px solid var(--brd, #dce3ee);
  border-radius: 8px;
  padding: 8px 10px;
  cursor: pointer;
  min-width: 140px;
  transition: border-color .15s, box-shadow .15s;
}
input[type="date"]:hover  { border-color: var(--ac, #1d4ed8); }
input[type="date"]:focus  {
  outline: none;
  border-color: var(--ac, #1d4ed8);
  box-shadow: 0 0 0 3px rgba(29,78,216,.09);
}
input[type="date"][readonly] { opacity:.75; cursor:default; background:var(--bg-th,#f7f9fc); }
/* Icono calendario: chromium-only, otros navegadores lo ignoran */
input[type="date"]::-webkit-calendar-picker-indicator {
  opacity: .6;
  cursor: pointer;
  filter: var(--date-cal-filter, none); /* invertido en dark mode */
}
input[type="date"]::-webkit-calendar-picker-indicator:hover { opacity: 1; }
/* Dark mode */
:root[data-tema="oscuro"] input[type="date"] {
  background: var(--bg-surface) !important;
  border-color: var(--brd) !important;
  color: var(--tx) !important;
  --date-cal-filter: invert(1) brightness(.8);
}
:root[data-tema="oscuro"] input[type="date"]:focus {
  border-color: var(--ac) !important;
  box-shadow: 0 0 0 3px rgba(99,139,255,.12) !important;
}

</style>
<script src="<?= APP_URL ?>/assets/js/validacion.js?v=2"></script>
<style>
/* ══════════════════════════════════════════════
   GGI Search-Select — estilos compartidos
   ══════════════════════════════════════════════ */
.ss-wrap { position:relative; display:block; }

.ss-wrap .ss-icon {
  display: none;
}

.ss-input {
  width:100%; padding:9px 32px 9px 12px;
  border:1.5px solid var(--gris-borde); border-radius:9px;
  font-size:13px; font-family:'Inter',sans-serif;
  outline:none; background:var(--gris-card,#fff);
  color:var(--gris-dark); transition:border-color .18s, box-shadow .18s;
  box-sizing:border-box;
}
/* Sin ícono de lupa: solo padding derecho para el botón × */
.ss-input--no-icon { padding-left:12px; }
.ss-input::placeholder { color:var(--gris-texto); }
.ss-input:focus { border-color:var(--azul); box-shadow:0 0 0 3px rgba(26,58,143,.07); }
.ss-input.error { border-color:#ef4444; }

/* Botón × */
.ss-clear {
  position:absolute; right:8px; top:50%; transform:translateY(-50%);
  width:18px; height:18px; border-radius:50%;
  background:var(--gris-borde,#e2e8f0); border:none; cursor:pointer;
  display:none; align-items:center; justify-content:center;
  color:var(--gris-texto); transition:background .15s, color .15s;
  padding:0; z-index:2; flex-shrink:0;
}
.ss-clear:hover { background:#1A3A8F; color:#fff; }

/* Dropdown */
.ss-dropdown {
  display:none; position:absolute; left:0; right:0; top:calc(100% + 5px);
  z-index:400;
  background:var(--gris-card,#fff);
  border:1.5px solid #c7d2e8;
  border-radius:10px;
  max-height:270px; overflow-y:auto;
  box-shadow:0 4px 24px rgba(0,0,0,.10), 0 1px 4px rgba(0,0,0,.06);
  animation:ssIn .13s cubic-bezier(.22,.68,0,1.2) both;
}
@keyframes ssIn { from{opacity:0;transform:translateY(-6px) scale(.98)} to{opacity:1;transform:none} }
.ss-dropdown::-webkit-scrollbar { width:4px; }
.ss-dropdown::-webkit-scrollbar-thumb { background:var(--gris-borde); border-radius:4px; }

/* Ítem: nombre a la izquierda, RUC como chip a la derecha */
.ss-item {
  display:flex; align-items:center; justify-content:space-between; gap:10px;
  padding:9px 12px; cursor:pointer;
  border-bottom:1px solid var(--gris-borde,#edf0f7);
  transition:background .1s; color:var(--gris-dark);
  min-width:0;
}
.ss-item:last-child { border-bottom:none; }
.ss-item:first-child { border-radius:8px 8px 0 0; }
.ss-item:hover, .ss-item.active { background:var(--azul-light,#eef2ff); }

.ss-name {
  font-size:13px; font-weight:500; color:var(--gris-dark);
  white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
  flex:1; min-width:0;
}
.ss-ruc {
  font-size:10.5px; font-weight:600; color:var(--gris-texto);
  background:var(--gris-bg,#f1f5f9);
  border:1px solid var(--gris-borde,#dde3f0);
  border-radius:5px; padding:2px 7px;
  white-space:nowrap; flex-shrink:0; letter-spacing:.2px;
  font-family:'Courier New',monospace;
}
.ss-item:hover .ss-name, .ss-item.active .ss-name { color:var(--azul); }
.ss-item:hover .ss-ruc,  .ss-item.active .ss-ruc  {
  background:rgba(26,58,143,.1); border-color:rgba(26,58,143,.2); color:var(--azul);
}

/* Highlight */
.ss-mark { background:rgba(26,58,143,.13); color:var(--azul); border-radius:2px; font-weight:700; }

/* Empty */
.ss-empty {
  padding:14px 14px; font-size:12.5px; color:var(--gris-texto);
  display:flex; align-items:center; gap:7px; font-style:italic;
}

/* ── Buscadores de listado ── */
.search-clear-btn {
  position:absolute; right:9px; top:50%; transform:translateY(-50%);
  width:18px; height:18px; border-radius:50%;
  background:var(--gris-borde,#e2e8f0); border:none; cursor:pointer;
  color:var(--gris-texto); transition:background .15s, color .15s;
  padding:0; margin:0; box-sizing:border-box;
}
.search-clear-btn svg {
  position:absolute; top:50%; left:50%;
  transform:translate(-50%,-50%);
  width:8px; height:8px; display:block; pointer-events:none;
}
.search-clear-btn:hover { background:var(--azul,#1A3A8F); color:#fff; }
.search-wrap input[name="q"] { padding-right:32px; }

/* ── Dark mode — search-select ── */
:root[data-tema="oscuro"] .ss-input { background:#182336 !important; border-color:#1A2840 !important; color:#ECF1FB !important; font-family:'Inter',sans-serif !important; }
:root[data-tema="oscuro"] .ss-input::placeholder { color:#4A5568 !important; }
:root[data-tema="oscuro"] .ss-input:focus { border-color:#5B9EF5 !important; box-shadow:0 0 0 3px rgba(91,158,245,.14) !important; }
:root[data-tema="oscuro"] .ss-dropdown { background:#111927 !important; border-color:#1A2840 !important; box-shadow:0 8px 32px rgba(0,0,0,.5) !important; }
:root[data-tema="oscuro"] .ss-item { border-color:#1A2840 !important; color:#ECF1FB !important; }
:root[data-tema="oscuro"] .ss-item:hover, :root[data-tema="oscuro"] .ss-item.active { background:#182336 !important; }
:root[data-tema="oscuro"] .ss-item:hover .ss-name, :root[data-tema="oscuro"] .ss-item.active .ss-name { color:#5B9EF5 !important; }
:root[data-tema="oscuro"] .ss-name { color:#ECF1FB !important; font-family:'Inter',sans-serif !important; }
:root[data-tema="oscuro"] .ss-ruc { background:#0C1118 !important; border-color:#1A2840 !important; color:#8893A8 !important; }
:root[data-tema="oscuro"] .ss-item:hover .ss-ruc, :root[data-tema="oscuro"] .ss-item.active .ss-ruc { background:rgba(91,158,245,.12) !important; border-color:rgba(91,158,245,.25) !important; color:#5B9EF5 !important; }
:root[data-tema="oscuro"] .ss-mark { background:rgba(91,158,245,.18) !important; color:#5B9EF5 !important; }
:root[data-tema="oscuro"] .ss-empty { color:#8893A8 !important; }
:root[data-tema="oscuro"] .ss-wrap .ss-icon { color:#8893A8 !important; }
:root[data-tema="oscuro"] .ss-clear, :root[data-tema="oscuro"] .search-clear-btn { background:#1A2840 !important; color:#8893A8 !important; }
:root[data-tema="oscuro"] .ss-clear:hover, :root[data-tema="oscuro"] .search-clear-btn:hover { background:#5B9EF5 !important; color:#fff !important; }

/* ── Modal de confirmación de eliminación ── */
#ggi-del-modal-backdrop {
  position:fixed; inset:0; z-index:9000;
  background:rgba(0,0,0,.45);
  display:flex; align-items:center; justify-content:center;
  animation:ggiDelFadeIn .15s ease;
}
@keyframes ggiDelFadeIn { from{opacity:0} to{opacity:1} }
#ggi-del-modal {
  background:var(--bg-surface); border:1px solid var(--brd);
  border-radius:14px; box-shadow:0 12px 40px rgba(0,0,0,.2);
  padding:24px 28px; max-width:420px; width:90%;
  animation:ggiDelSlideIn .18s ease;
}
@keyframes ggiDelSlideIn { from{transform:translateY(-10px);opacity:0} to{transform:translateY(0);opacity:1} }
#ggi-del-modal h3 { font-size:15px; font-weight:700; color:var(--tx); margin:0 0 6px; }
#ggi-del-modal p  { font-size:13px; color:var(--tx2); margin:0 0 20px; line-height:1.5; }
.ggi-del-warning  {
  display:flex; align-items:flex-start; gap:10px;
  background:var(--s-err-bg); border:1px solid var(--s-err-brd);
  border-radius:9px; padding:10px 12px; margin-bottom:18px;
  font-size:12px; color:var(--s-err); font-weight:500; line-height:1.4;
}
.ggi-del-actions  { display:flex; gap:10px; justify-content:flex-end; }
:root[data-tema="oscuro"] #ggi-del-modal {
  background:#182336 !important; border-color:#253b56 !important;
}
:root[data-tema="oscuro"] .ggi-del-warning {
  background:#450a0a !important; border-color:#7f1d1d !important; color:#fca5a5 !important;
}
.btn-ic-red { background:var(--s-err-bg); color:var(--s-err); }
.btn-ic-red:hover { background:var(--s-err); color:#fff; }
</style>

<script>
/* Botón × universal para buscadores de listado.
   Se expone como window.initSearchClearBtns(root) para poder llamarse
   tanto en el DOMContentLoaded inicial como después de cada swap SPA. */
window.initSearchClearBtns = function(root) {
  (root || document).querySelectorAll('.search-wrap').forEach(function(wrap) {
    if (wrap.querySelector('.search-clear-btn')) return; // ya inicializado
    var inp = wrap.querySelector('input[name="q"]');
    if (!inp) return;
    var btn = document.createElement('button');
    btn.type = 'button'; btn.className = 'search-clear-btn'; btn.title = 'Limpiar';
    btn.innerHTML = '<svg width="8" height="8" viewBox="0 0 8 8" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="1" y1="1" x2="7" y2="7"/><line x1="7" y1="1" x2="1" y2="7"/></svg>';
    btn.style.display = inp.value.trim() ? 'block' : 'none';
    btn.addEventListener('click', function() {
      inp.value = '';
      btn.style.display = 'none';
      /* Navegar al pathname sin parámetros → PHP aplica todos sus defaults
         (estado=activo, sin búsqueda, sin filtros). Más limpio que resetear
         cada select manualmente y evita quedarse con filtros residuales. */
      var form = inp.form;
      if (!form) return;
      try {
        var base = new URL(form.getAttribute('action') || location.pathname, location.href).pathname;
        if (typeof window.ggiNavigate === 'function') { window.ggiNavigate(base); return; }
      } catch(x) {}
      form.reset();
      if (typeof form.requestSubmit === 'function') form.requestSubmit();
      else form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    });
    inp.addEventListener('input', function() {
      btn.style.display = inp.value.trim() ? 'block' : 'none';
    });
    wrap.appendChild(btn);
  });
};
document.addEventListener('DOMContentLoaded', function() { window.initSearchClearBtns(); });
</script>

<script>
/**
 * ggiEliminar(url, formData, onSuccess)
 * ─────────────────────────────────────────────────────────────────────────────
 * Flujo de eliminación con previsualización de dependencias:
 *   1. Llama al endpoint con preview=1  → obtiene info (nombre, estado, deps)
 *   2. Muestra modal con los detalles y la lista de registros afectados
 *   3. Si el usuario confirma → llama con preview=0 → elimina
 *
 * Parámetros:
 *   url        — URL del ajax_eliminar.php
 *   formData   — FormData con los IDs y el _csrf (SIN preview)
 *   onSuccess  — función(row?) llamada tras éxito para quitar la fila del DOM
 *   titulo     — título del modal (opcional)
 *   labelTipo  — "envío", "propuesta", etc. (opcional, para el mensaje genérico)
 */
/**
 * ggiToastUndo(modulo, id, msg) — toast "Deshacer" tras una eliminación lógica.
 * El endpoint ajax_restaurar.php valida whitelist de módulos, ventana de tiempo
 * y autoría (solo quien borró puede deshacer, salvo gerente general).
 * Al deshacer con éxito recarga la página para restaurar la fila.
 */
window.ggiToastUndo = function(modulo, id, msg) {
  if (typeof showToast !== 'function') return;
  showToast({
    title: msg || 'Eliminado correctamente.',
    action: {
      label: 'Deshacer',
      onClick: async function() {
        // ggiPost lee el token vigente de la meta tag: window.CSRF_TOKEN
        // puede haber quedado obsoleto tras una navegación SPA.
        var dd = await ggiPost('<?= APP_URL ?>/modules/ajax_restaurar.php',
                               { modulo: modulo, id: id });
        if (dd.ok) {
          if (typeof window.invalidatePageCache === 'function') window.invalidatePageCache(location.href);
          location.reload();
        }
      }
    }
  }, 'ok', 6000);
};

window.ggiEliminar = async function(url, formData, onSuccess, titulo, labelTipo) {
  // Quitar modal anterior
  var prev = document.getElementById('ggi-del-modal-backdrop');
  if (prev) prev.remove();

  titulo    = titulo    || 'Eliminar registro';
  labelTipo = labelTipo || 'registro';

  // Step 1 — previsualización (no elimina)
  var fdPreview = new FormData();
  formData.forEach(function(v, k) { fdPreview.append(k, v); });
  fdPreview.set('preview', '1');

  // silent: el error se muestra abajo con el mensaje propio de esta función
  var info = await ggiPost(url, fdPreview, { silent: true });

  if (info && !info.ok) {
    if (typeof showToast === 'function') showToast(info.msg || 'Error al verificar el registro.', 'error');
    return;
  }

  // Construir contenido del modal con la info recibida
  var nombre   = (info && (info.empresa || info.numero || info.codigo)) || ('Este ' + labelTipo);
  var estado   = info && info.estado ? ' <span class="badge-gray" style="font-size:10px;">' + info.estado + '</span>' : '';
  var norma    = info && info.norma  ? ' · ' + info.norma : '';
  var ruc      = info && info.ruc    ? ' <span style="font-size:11px;color:var(--tx2);">RUC: ' + info.ruc + '</span>' : '';
  var hayDeps  = info && info.hay_deps;
  var depsList = '';
  if (hayDeps && info.deps && info.deps.length) {
    depsList = '<ul style="margin:8px 0 0 16px;font-size:12px;color:var(--tx2);line-height:1.7;">' +
      info.deps.map(function(d){ return '<li>' + d + '</li>'; }).join('') +
      '</ul>';
  }

  var advertencia = hayDeps
    ? 'Este ' + labelTipo + ' tiene registros asociados que <strong>seguirán existiendo</strong> en el sistema:<br>' + depsList + '<br>Solo se ocultará el ' + labelTipo + ' del listado.'
    : 'El ' + labelTipo + ' desaparecerá del listado. Podrás deshacerlo durante unos segundos desde el aviso.';

  var backdrop = document.createElement('div');
  backdrop.id = 'ggi-del-modal-backdrop';
  backdrop.innerHTML =
    '<div id="ggi-del-modal">' +
      '<h3>' + titulo + '</h3>' +
      '<p style="font-size:13px;color:var(--tx);font-weight:600;margin:0 0 4px;">' + nombre + norma + estado + ruc + '</p>' +
      '<p style="font-size:12px;color:var(--tx2);margin:0 0 16px;">¿Está seguro de eliminar este ' + labelTipo + '?</p>' +
      '<div class="ggi-del-warning">' +
        '<svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="flex-shrink:0;margin-top:1px"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>' +
        '<div>' + advertencia + '</div>' +
      '</div>' +
      '<div class="ggi-del-actions">' +
        '<button class="btn btn-outline btn-sm" id="ggi-del-cancelar">Cancelar</button>' +
        '<button class="btn btn-sm" id="ggi-del-confirmar" style="background:var(--s-err);color:#fff;border-color:var(--s-err)">Sí, eliminar</button>' +
      '</div>' +
    '</div>';

  document.body.appendChild(backdrop);

  function cerrar() { backdrop.remove(); }
  document.getElementById('ggi-del-cancelar').addEventListener('click', cerrar);
  backdrop.addEventListener('click', function(e) { if (e.target === backdrop) cerrar(); });
  document.addEventListener('keydown', function esc(e) {
    if (e.key === 'Escape') { cerrar(); document.removeEventListener('keydown', esc); }
  });

  document.getElementById('ggi-del-confirmar').addEventListener('click', async function() {
    var btn = this; btn.disabled = true; btn.textContent = 'Eliminando…';
    try {
      // Step 2 — eliminar definitivamente
      formData.set('preview', '0');
      // silent: los avisos de esta función son propios (incluye "Deshacer")
      var data = await ggiPost(url, formData, { silent: true });
      if (data.ok) {
        // "Deshacer": módulo desde la URL del endpoint, id desde el primer campo *_id.
        // El endpoint de restauración valida ventana de tiempo y autoría (deleted_by).
        var _undoMod = (url.match(/\/modules\/(\w+)\/ajax_eliminar\.php/) || [])[1] || '';
        var _undoId  = 0;
        formData.forEach(function(v, k) { if (!_undoId && /_id$/.test(k)) _undoId = parseInt(v) || 0; });

        if (_undoMod && _undoId) {
          ggiToastUndo(_undoMod, _undoId, data.msg || 'Eliminado correctamente.');
        } else if (typeof showToast === 'function') {
          showToast(data.msg || 'Eliminado correctamente.', 'ok');
        }
        if (typeof onSuccess === 'function') onSuccess();
        if (typeof window.invalidatePageCache === 'function') window.invalidatePageCache(location.href);
      } else {
        if (typeof showToast === 'function') showToast(data.msg || 'No se pudo eliminar.', 'error');
      }
    } catch(err) {
      if (typeof showToast === 'function') showToast('Error de conexión al eliminar.', 'error');
    }
    cerrar();
  });
};

/**
 * ggiConfirmarEliminar(opts) — API simple (sin preview).
 * Mantener por compatibilidad con código antiguo.
 */
window.ggiConfirmarEliminar = function(opts) {
  var prev = document.getElementById('ggi-del-modal-backdrop');
  if (prev) prev.remove();
  var advertencia = opts.advertencia || 'Esta acción no se puede deshacer desde la aplicación.';
  var backdrop = document.createElement('div');
  backdrop.id = 'ggi-del-modal-backdrop';
  backdrop.innerHTML =
    '<div id="ggi-del-modal">' +
      '<h3>' + (opts.titulo || 'Eliminar registro') + '</h3>' +
      '<p>' + (opts.mensaje || '¿Está seguro?') + '</p>' +
      '<div class="ggi-del-warning">' +
        '<svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="flex-shrink:0;margin-top:1px"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>' +
        '<span>' + advertencia + '</span>' +
      '</div>' +
      '<div class="ggi-del-actions">' +
        '<button class="btn btn-outline btn-sm" id="ggi-del-cancelar">Cancelar</button>' +
        '<button class="btn btn-sm" id="ggi-del-confirmar" style="background:var(--s-err);color:#fff;border-color:var(--s-err)">' + (opts.labelConfirmar || 'Eliminar') + '</button>' +
      '</div>' +
    '</div>';
  document.body.appendChild(backdrop);
  function cerrar() { backdrop.remove(); }
  document.getElementById('ggi-del-cancelar').addEventListener('click', cerrar);
  backdrop.addEventListener('click', function(e) { if (e.target === backdrop) cerrar(); });
  document.addEventListener('keydown', function esc(e) { if (e.key==='Escape') { cerrar(); document.removeEventListener('keydown', esc); }});
  document.getElementById('ggi-del-confirmar').addEventListener('click', async function() {
    var btn = this; btn.disabled = true; btn.textContent = 'Eliminando…';
    try { await opts.onConfirmar(); } catch(e) { if (typeof showToast==='function') showToast('Error inesperado.','error'); }
    cerrar();
  });
};
</script>

<style>
/* ════════════════════════════════════════════════════════════════════════════
   ESTADO-SEL — Selector de estado interactivo, visualmente idéntico a badge-*
   Sin flecha · Sin apariencia de select · Sin indicador de dropdown.
   Un único componente para todos los módulos, desktop, mobile, light y dark.

   PHP: <div class="estado-sel" data-id="ID" data-color="#hex" data-module="MOD"
              role="button" tabindex="0">
          <span class="estado-sel-label">Texto</span>
          <div class="estado-sel-menu" hidden>
            <div class="estado-sel-option" data-value="VAL" data-color="#hex"
                 style="--opt-color:#hex">Opción</div>
          </div>
        </div>
   ════════════════════════════════════════════════════════════════════════════ */

.estado-sel {
  display: inline-block;
  position: relative;
  cursor: pointer;
  user-select: none; -webkit-user-select: none;
  vertical-align: middle;
}
/* La "pill" — idéntica en estructura a badge-* */
.estado-sel-label {
  display: inline-flex;
  align-items: center;
  padding: 3px 10px;
  border-radius: 100px;
  border: 1px solid transparent;
  font-size: 11px;
  font-weight: 600;
  font-family: 'Inter', sans-serif;
  white-space: nowrap;
  transition: opacity 0.12s;
  /* Colores: aplicados por ggiApplySelectColors() en JS (light + dark) */
}
.estado-sel:hover .estado-sel-label { opacity: 0.80; }
.estado-sel:focus { outline: none; }
.estado-sel:focus-visible .estado-sel-label { box-shadow: 0 0 0 2px var(--ac); }

/* Menú de opciones */
.estado-sel-menu {
  position: absolute;
  top: calc(100% + 5px);
  left: 50%;
  transform: translateX(-50%);
  background: var(--bg-surface);
  border: 1.5px solid var(--brd);
  border-radius: 10px;
  box-shadow: 0 8px 28px rgba(0,0,0,.13);
  z-index: 450;
  min-width: 170px;
  padding: 4px;
  animation: ggiSelIn .12s ease;
}
.estado-sel-menu[hidden] { display: none !important; }
@keyframes ggiSelIn {
  from { opacity:0; transform:translateX(-50%) translateY(-5px); }
  to   { opacity:1; transform:translateX(-50%) translateY(0); }
}
/* Opción individual */
.estado-sel-option {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 7px 10px;
  border-radius: 7px;
  cursor: pointer;
  font-size: 12px;
  font-weight: 500;
  color: var(--tx);
  white-space: nowrap;
  transition: background .1s;
}
.estado-sel-option:hover { background: var(--bg-hover); }
/* Punto de color de cada opción */
.estado-sel-option::before {
  content: '';
  flex-shrink: 0;
  width: 8px; height: 8px;
  border-radius: 50%;
  background: var(--opt-color, #6b7280);
}
/* Dark mode */
:root[data-tema="oscuro"] .estado-sel-menu {
  background: #182336 !important;
  border-color: #253b56 !important;
  box-shadow: 0 8px 28px rgba(0,0,0,.45) !important;
}
:root[data-tema="oscuro"] .estado-sel-option { color: #e2e8f0 !important; }
:root[data-tema="oscuro"] .estado-sel-option:hover { background: #1e293b !important; }
</style>

<script>
/* ── Helpers de color (compartidos por ggiApplySelectColors e initEstadoSel) ── */
(function() {
  function _hexToRgb(hex) {
    hex = hex.trim(); if (hex.charAt(0)!=='#') hex='#'+hex;
    var h = hex.replace('#','');
    if (h.length===3) h=h[0]+h[0]+h[1]+h[1]+h[2]+h[2];
    if (h.length!==6) return null;
    var r=parseInt(h.substring(0,2),16), g=parseInt(h.substring(2,4),16), b=parseInt(h.substring(4,6),16);
    return (isNaN(r)||isNaN(g)||isNaN(b)) ? null : [r,g,b];
  }
  function _applyBadgeColor(hex, target) {
    var rgb = _hexToRgb(hex); if (!rgb) return;
    var r=rgb[0],g=rgb[1],b=rgb[2];
    var isDark = document.documentElement.getAttribute('data-tema')==='oscuro';
    if (isDark) {
      // Fondo más oscuro (.18) y texto más claro (.52 + blanco .48) para garantizar
      // contraste WCAG AA (≥4.5:1) incluso en tonos medios como el azul. (skill §1)
      target.style.background  = 'rgb('+((r*.18+24*.82)|0)+','+((g*.18+33*.82)|0)+','+((b*.18+47*.82)|0)+')';
      target.style.color       = 'rgb('+((r*.52+255*.48)|0)+','+((g*.52+255*.48)|0)+','+((b*.52+255*.48)|0)+')';
      target.style.borderColor = 'rgba('+r+','+g+','+b+',.38)';
    } else {
      target.style.background  = hex.replace('#','#')+'22';
      target.style.color       = hex;
      target.style.borderColor = hex.replace('#','#')+'55';
    }
  }
  // Exponer para uso en módulos
  window._ggiApplyBadgeColor = _applyBadgeColor;
  window._ggiHexToRgb        = _hexToRgb;
})();

/* ── ggiApplySelectColors ─ aplica colores a estado-sel (y pill-wrap legado) ── */
window.ggiApplySelectColors = function(root) {
  (root || document).querySelectorAll('.estado-sel[data-color]').forEach(function(el) {
    var lbl = el.querySelector('.estado-sel-label');
    if (lbl) window._ggiApplyBadgeColor(el.getAttribute('data-color'), lbl);
  });
  // Backward compat: .estado-pill-wrap todavía en uso
  (root || document).querySelectorAll('.estado-pill-wrap[data-color]').forEach(function(wrap) {
    window._ggiApplyBadgeColor(wrap.getAttribute('data-color'), wrap);
  });
};

/* ── initEstadoSel ─ inicializa los selectores de estado custom ──────────── */
window.initEstadoSel = function(root) {
  (root || document).querySelectorAll('.estado-sel:not([data-sel-init])').forEach(function(el) {
    el.setAttribute('data-sel-init','1');
    var menu = el.querySelector('.estado-sel-menu');
    if (!menu) return;

    /* Abrir / cerrar al hacer clic */
    el.addEventListener('click', function(e) {
      e.stopPropagation();
      var wasOpen = !menu.hidden;
      document.querySelectorAll('.estado-sel-menu:not([hidden])').forEach(function(m){ m.hidden=true; });
      if (!wasOpen) {
        menu.hidden = false;
        /* Flip hacia arriba si el menú se saldría de la ventana */
        var rect = el.getBoundingClientRect();
        if (rect.bottom + 190 > window.innerHeight) {
          menu.style.top='auto'; menu.style.bottom='calc(100% + 5px)';
        } else {
          menu.style.top=''; menu.style.bottom='';
        }
      }
    });

    /* Teclado: Enter/Espacio abren; Escape cierra; flechas navegan */
    el.addEventListener('keydown', function(e) {
      if (e.key==='Enter'||e.key===' ') { e.preventDefault(); el.click(); return; }
      if (e.key==='Escape') { menu.hidden=true; }
    });

    /* Clic en una opción → emitir evento para que el módulo lo maneje */
    menu.querySelectorAll('.estado-sel-option').forEach(function(opt) {
      opt.addEventListener('click', function(e) {
        e.stopPropagation();
        menu.hidden = true;
        el.dispatchEvent(new CustomEvent('estado-sel-change', {
          bubbles: true,
          detail: {
            el: el,
            id: el.getAttribute('data-id'),
            module: el.getAttribute('data-module'),
            value: opt.getAttribute('data-value'),
            color: opt.getAttribute('data-color'),
            label: opt.textContent.trim(),
            prevValue: el.getAttribute('data-value'),
            prevColor: el.getAttribute('data-color'),
            prevLabel: (el.querySelector('.estado-sel-label')||{}).textContent || ''
          }
        }));
      });
    });
  });
};

/* Cerrar cualquier menú abierto al hacer clic fuera */
document.addEventListener('click', function() {
  document.querySelectorAll('.estado-sel-menu:not([hidden])').forEach(function(m){ m.hidden=true; });
});

document.addEventListener('DOMContentLoaded', function() {
  window.ggiApplySelectColors();
  window.initEstadoSel();
});
</script>

<!-- Tom Select (local) -->
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/tom-select.min.css?v=2.3.1">
<script src="<?= APP_URL ?>/assets/js/tom-select.complete.min.js?v=2.3.1"></script>
<script>
(function() {
  function initTomSelects() {
    document.querySelectorAll('select').forEach(function(el) {
      if (el.tomselect) return;                        // ya inicializado
      if (el.dataset.noSearch !== undefined) return;   // excluido explícitamente
      if (el.closest('#mant-modal')) return;           // modal dinámico
      if (el.offsetWidth === 0) return;                // dentro de display:none, inicializar cuando sea visible
      if (el.options.length <= 8) return;              // pocos items, no necesita búsqueda

      var placeholder = (el.options[0] && el.options[0].value === '')
        ? el.options[0].text
        : '— Seleccionar —';

      new TomSelect(el, {
        maxOptions: null,
        create: false,
        allowEmptyOption: true,
        placeholder: placeholder,
        dropdownParent: 'body',
        onInitialize: function() {
          if (this.wrapper) this.wrapper.style.width = '100%';
          // Forzar apertura al hacer clic aunque ya haya un valor seleccionado
          var ts = this;
          ts.control.addEventListener('mousedown', function(e) {
            if (!ts.isOpen) {
              ts.open();
              e.preventDefault();
              e.stopPropagation();
            }
          });
        },
        onChange: function(value) {
          // Fire native change event so onchange attributes (e.g. autoEjecutivo, checkContacto) still work
          var nativeChange = new Event('change', { bubbles: true });
          el.dispatchEvent(nativeChange);
        }
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initTomSelects);
  } else {
    initTomSelects();
  }

  // Exponer globalmente por si algún módulo necesita reinicializar (p.ej. paneles que se muestran)
  window.initTomSelects = initTomSelects;
})();

/* ggiSubmitForm(form) — reemplaza form.submit() en onchange de selects.
   form.submit() nativo NO dispara el evento submit, así el SPA nunca lo intercepta
   y el navegador hace un reload completo. requestSubmit() sí dispara el evento. */
window.ggiSubmitForm = function(form) {
  if (!form) return;
  if (typeof form.requestSubmit === 'function') {
    form.requestSubmit();
  } else {
    form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
  }
};
</script>

<!-- ─────────────────────────────────────────────────────────────────────────
     ggiReady() — helper SPA-aware para inicialización de módulos.
     Problema: el SPA runner (navigate) ejecuta los <script> de cada página
     cuando document.readyState ya es 'complete', por lo que los callbacks
     registrados con addEventListener('DOMContentLoaded', fn) NUNCA disparan.
     Solución: ggiReady(fn) llama fn() de inmediato si el DOM ya está listo,
     o lo programa para cuando lo esté en carga directa (primera vez).
     USO: en cualquier módulo, sustituir
       document.addEventListener('DOMContentLoaded', fn)
     por
       ggiReady(fn)
     ───────────────────────────────────────────────────────────────────────── -->
<script>
window.ggiReady = function(fn) {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', fn);
  } else {
    fn();
  }
};

/* ── Debounce por clave: cada buscador tiene su propio timer, nunca se cancelan ──
   Uso: oninput="ggiDebounce('clientes-q', () => this.form.submit(), 600)"
   Si delay se omite, usa 600 ms (estándar del sistema).                        */
window.ggiDebounce = (function () {
  var _timers = Object.create(null);
  return function (key, fn, delay) {
    clearTimeout(_timers[key]);
    _timers[key] = setTimeout(fn, delay !== undefined ? delay : 600);
  };
})();
</script>

<!-- Navegación AJAX: swap de .main sin recargar la página completa.
     El sidebar y header permanecen intactos → cero flash blanco entre secciones. -->
<script>
(function () {
  'use strict';

  var mainEl        = document.querySelector('.main');
  var bar           = document.getElementById('nav-progress-bar');
  var barWrap       = document.getElementById('nav-progress');
  var barTimer      = null;
  var pending         = null;   // AbortController del fetch en curso
  var cache           = {};     // href → html(string) | Promise | null(error)
  var __ggiNavDepth   = 0;      // cuántos navigate() hacia adelante se hicieron
  var _focusRestore   = null;   // {id, name} del input a re-enfocar tras swap de búsqueda

  /* ── Barra de progreso ── */
  function startBar() {
    if (!bar || !barWrap) return;
    clearTimeout(barTimer);
    barWrap.style.opacity = '1';
    bar.style.transition = 'none'; bar.style.width = '0%';
    requestAnimationFrame(function () {
      requestAnimationFrame(function () {
        bar.style.transition = 'width 0.4s ease'; bar.style.width = '70%';
      });
    });
  }
  function doneBar() {
    if (!bar || !barWrap) return;
    bar.style.transition = 'width 0.15s ease'; bar.style.width = '100%';
    barTimer = setTimeout(function () {
      barWrap.style.opacity = '0';
      setTimeout(function () { bar.style.width = '0%'; }, 200);
    }, 200);
  }

  /* ── ¿Aplica navegación AJAX? ── */
  function canAjax(href) {
    if (!href || /^(#|javascript:|mailto:|tel:)/i.test(href)) return false;
    try {
      var u = new URL(href, location.href);
      if (u.origin !== location.origin) return false;
      if (u.searchParams.has('dl') || u.searchParams.has('export')) return false;
      if (/\/(logout|login)/i.test(u.pathname)) return false;
      return true;
    } catch (e) { return false; }
  }

  /* ── Obtener HTML (reutiliza prefetch si existe; nunca cachea al ir atrás) ── */
  function getPage(href, signal, forceRefresh) {
    if (!forceRefresh && typeof cache[href] === 'string') return Promise.resolve(cache[href]);
    if (!forceRefresh && cache[href] && typeof cache[href].then === 'function') return cache[href];
    var p = fetch(href, { signal: signal, credentials: 'same-origin', cache: 'no-cache' })
      .then(function (r) { if (!r.ok) throw new Error(r.status); return r.text(); })
      .then(function (html) { cache[href] = html; return html; })
      .catch(function (err) { cache[href] = null; throw err; });
    if (!forceRefresh) cache[href] = p;
    return p;
  }

  /* ── Exponer invalidación de caché (llamar tras mutaciones AJAX) ── */
  window.invalidatePageCache = function(href) {
    delete cache[href || location.href];
  };

  /* ── Navegar atrás respetando el historial AJAX ── */
  window.ggiNavBack = function(fallbackHref) {
    if (__ggiNavDepth > 0) {
      history.back();
    } else {
      location.href = fallbackHref || location.href;
    }
  };

  /* ── Navegación contextual: guardar URL de listas y restaurar en breadcrumbs ── */
  (function () {
    var _map = {
      '/modules/clientes/index.php':     'ggi_ss_clientes',
      '/modules/cotizaciones/index.php': 'ggi_ss_cotizaciones',
      '/modules/certificados/index.php': 'ggi_ss_certificados',
      '/modules/envios/index.php':       'ggi_ss_envios',
      '/modules/auditorias/index.php':   'ggi_ss_auditorias',
      '/modules/pagos/index.php':        'ggi_ss_pagos',
    };
    var _path = window.location.pathname;
    if (_map[_path]) {
      try { sessionStorage.setItem(_map[_path], location.href); } catch (e) {}
    }
    // Actualizar hrefs de breadcrumb con el URL guardado (preserva filtros/paginación)
    document.querySelectorAll('[data-bc-ss]').forEach(function (el) {
      try {
        var saved = sessionStorage.getItem(el.getAttribute('data-bc-ss'));
        if (saved) el.href = saved;
      } catch (e) {}
    });
  })();

  /* ── Alias retrocompatible: ggiInitBackLink ya no hace nada (reemplazado por ggi-bc) ── */
  window.ggiInitBackLink = function() {};

  /* ── Navegar programáticamente desde cualquier módulo ── */
  window.ggiNavigate = function(href) { navigate(href); };

  /* ── Construir URL desde un formulario GET ──────────────────────────────
     Regla: los <select> siempre se incluyen aunque su valor sea "" (vacío),
     porque un select vacío es una elección explícita del usuario ("Todos").
     Los inputs de texto vacíos se omiten para mantener URLs limpias.        */
  function buildFormUrl(form) {
    var action = form.getAttribute('action') || location.pathname;
    var url = new URL(action, location.href);
    url.search = '';
    Array.from(form.elements).forEach(function(el) {
      if (!el.name || el.disabled) return;
      if (el.type === 'checkbox' || el.type === 'radio') {
        if (el.checked) url.searchParams.set(el.name, el.value);
        return;
      }
      if (el.tagName === 'SELECT') {
        // Siempre incluir selects (valor vacío = "Todos" explícito)
        url.searchParams.set(el.name, el.value);
        return;
      }
      // Inputs de texto: solo incluir si tienen valor
      if (el.value !== '') url.searchParams.set(el.name, el.value);
    });
    return url.pathname + (url.search || '');
  }

  /* ── Buscador con debounce + SPA + preservación de foco ──────────────────
     PROBLEMA RAÍZ: form.submit() (nativo) NO dispara el evento "submit",
     por lo que el interceptor SPA nunca lo ve y el browser hace reload completo.
     SOLUCIÓN: construir la URL aquí mismo y llamar navigate() directamente,
     guardando _focusRestore antes para que el swap restaure el cursor.

     Uso: oninput="ggiSearch('modulo-q', this, 600)"                         */
  window.ggiSearch = function(key, inputEl, delay) {
    window.ggiDebounce(key, function() {
      var form = inputEl ? inputEl.form : null;
      if (!form) return;
      _focusRestore = { id: inputEl.id || '', name: inputEl.name || '' };
      try {
        navigate(buildFormUrl(form));
      } catch(x) {
        _focusRestore = null;
        form.submit();
      }
    }, delay !== undefined ? delay : 600);
  };

  /* ── Navegar ── */
  function navigate(href, isPop) {
    if (!mainEl) { location.href = href; return; }
    if (pending) { pending.abort(); }
    // Liberar botones congelados antes de cada navegación SPA
    window.dispatchEvent(new Event('spa:before'));
    var ac = new AbortController();
    pending = ac;
    startBar();

    getPage(href, ac.signal, isPop)   // popstate siempre refresca (sin caché)
      .then(function (html) {
        if (ac !== pending && !isPop) return; // superado por navegación posterior
        pending = null;

        /* Ajustar profundidad de historial */
        if (isPop) {
          __ggiNavDepth = Math.max(0, __ggiNavDepth - 1);
        } else {
          __ggiNavDepth++;
        }

        var doc = (new DOMParser()).parseFromString(html, 'text/html');
        var newMain = doc.querySelector('.main');
        if (!newMain) { location.href = href; return; } // error/login → normal

        /* URL y título */
        if (!isPop) history.pushState({ ggi: 1 }, doc.title || '', href);
        document.title = doc.title || '';

        /* Actualizar ítem activo del sidebar leyendo el de la respuesta */
        var srcActive = doc.querySelector('.nav-item.active');
        document.querySelectorAll('.nav-item').forEach(function (el) {
          el.classList.remove('active');
        });
        if (srcActive) {
          var srcHref = srcActive.getAttribute('href') || '';
          var match = document.querySelector('.nav-item[href="' + srcHref.replace(/"/g, '\\"') + '"]');
          if (match) match.classList.add('active');
        }
        if (typeof _ggiSyncAriaCurrent === 'function') _ggiSyncAriaCurrent();

        /* Actualizar window.ACTIVE_MENU para que el polling de badges refleje la página actual */
        var _amScript = Array.from(doc.body.querySelectorAll('script:not([src])')).find(function(s) {
          return s.textContent.indexOf('window.ACTIVE_MENU') !== -1;
        });
        if (_amScript) {
          var _amMatch = _amScript.textContent.match(/window\.ACTIVE_MENU\s*=\s*'([^']*)'/);
          if (_amMatch) window.ACTIVE_MENU = _amMatch[1];
        }

        /* Extraer <script> antes del swap */
        var scripts = Array.from(newMain.querySelectorAll('script'));
        scripts.forEach(function (s) { s.remove(); });

        /* Limpiar datos de módulos JS anteriores antes de cargar los nuevos */
        window.__mantData        = null;
        window.__mantBuildForm   = null;
        window.__mantPostRender  = null;
        window.__mant            = null;

        /* Cerrar sidebar móvil si estaba abierto */
        if (typeof closeSidebar === 'function') closeSidebar();

        /* Cerrar modal si estaba abierto (usar mantClose para restaurar overflow) */
        if (typeof mantClose === 'function') {
          try { mantClose(); } catch(e) {}
        } else {
          var _om = document.getElementById('mant-modal');
          if (_om) _om.classList.remove('open');
          document.body.style.overflow = '';
        }

        /* Swap de contenido */
        mainEl.innerHTML = newMain.innerHTML;

        /* Re-inicializar datepickers en el contenido recién insertado (SPA) */
        if (typeof window.ggiInitDatepickers === 'function') window.ggiInitDatepickers(mainEl);

        /* Restaurar foco si la navegación fue originada por un form de búsqueda.
           requestAnimationFrame garantiza que el nuevo DOM ya pintó antes de enfocar. */
        if (_focusRestore) {
          var _fr = _focusRestore;
          _focusRestore = null;
          requestAnimationFrame(function () {
            var el = (_fr.id && document.getElementById(_fr.id))
                  || (_fr.name && document.querySelector('[name="' + _fr.name + '"]'));
            if (el && !el.disabled && !el.readOnly) {
              el.focus({ preventScroll: true });
              try { el.setSelectionRange(el.value.length, el.value.length); } catch (_) {}
            }
          });
        } else {
          /* Scroll al tope solo cuando NO es una búsqueda (evita salto incómodo) */
          window.scrollTo(0, 0);
          document.documentElement.scrollTop = 0;
          document.body.scrollTop = 0;
        }

        /* Ejecutar scripts de la nueva página */
        scripts.forEach(function (old) {
          var s = document.createElement('script');
          Array.from(old.attributes).forEach(function (a) { s.setAttribute(a.name, a.value); });
          s.textContent = old.textContent;
          document.body.appendChild(s);
          if (!old.src) document.body.removeChild(s);
        });

        /* Re-renderizar módulo de mantenimiento si la nueva página lo necesita */
        if (typeof _initMantUI === 'function') _initMantUI();

        /* Re-inicializar TomSelect en los nuevos <select> */
        if (typeof window.initTomSelects === 'function') {
          try { window.initTomSelects(); } catch (e) {}
        }

        /* Re-inicializar avatar chips en el nuevo contenido */
        if (typeof window.initAvatarChips === 'function') {
          try { window.initAvatarChips(mainEl); } catch (e) {}
        }

        /* Re-crear botones × en los buscadores del nuevo contenido */
        if (typeof window.initSearchClearBtns === 'function') {
          try { window.initSearchClearBtns(mainEl); } catch (e) {}
        }

        /* Re-aplicar colores de estado (tema actual) y re-inicializar estado-sel */
        if (typeof window.ggiApplySelectColors === 'function') {
          try { window.ggiApplySelectColors(mainEl); } catch (e) {}
        }
        if (typeof window.initEstadoSel === 'function') {
          try { window.initEstadoSel(mainEl); } catch (e) {}
        }

        doneBar();
      })
      .catch(function (err) {
        if (err && err.name === 'AbortError') return;
        pending = null;
        doneBar();
        location.href = href; // fallback a navegación normal
      });
  }

  /* ── Interceptar clics (capture para tener prioridad) ── */
  document.addEventListener('click', function (e) {
    var a = e.target.closest('a[href]');
    if (!a || a.hasAttribute('download') || a.target === '_blank' || a.hasAttribute('data-ggi-preview')) return;
    if (e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
    var href = a.getAttribute('href');
    if (!canAjax(href)) return;

    /* Si es un botón "← Volver" y hay historial AJAX, usar history.back()
       para que el navegador restaure la página/paginación anterior. */
    var txt = (a.textContent || a.innerText || '').trim();
    if (__ggiNavDepth > 0 && (txt.indexOf('Volver') !== -1 || txt.indexOf('←') !== -1 || a.classList.contains('btn-back'))) {
      e.preventDefault();
      history.back();
      return;
    }

    try {
      var url = new URL(href, location.href);
      e.preventDefault();
      navigate(url.href);
    } catch (x) {}
  }, true);

  /* ── Botones atrás / adelante ── */
  window.addEventListener('popstate', function () { navigate(location.href, true); });

  /* ── Prefetch al pasar el cursor ── */
  document.addEventListener('mouseover', function (e) {
    var a = e.target.closest('a[href]');
    if (!a || a.hasAttribute('download') || a.target === '_blank' || a.hasAttribute('data-ggi-preview')) return;
    var href = a.getAttribute('href');
    if (!canAjax(href) || cache[href] != null) return;
    try {
      var url = new URL(href, location.href);
      if (cache[url.href] != null) return;
      cache[url.href] = fetch(url.href, { credentials: 'same-origin', cache: 'no-cache' })
        .then(function (r) { return r.text(); })
        .then(function (html) { cache[url.href] = html; return html; })
        .catch(function () { cache[url.href] = null; });
    } catch (x) {}
  }, { passive: true });

  /* ── Forms GET: interceptar para enrutar por SPA y preservar foco ── */
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form) return;

    // POST y formularios especiales: dejar al browser, solo animar barra
    if ((form.method || 'get').toUpperCase() !== 'GET') { startBar(); return; }

    var action = form.getAttribute('action') || location.pathname;
    if (!canAjax(action)) { startBar(); return; }

    e.preventDefault();

    // Guardar elemento con foco para restaurarlo tras el swap
    var active = document.activeElement;
    if (active && active.tagName && /^(INPUT|TEXTAREA|SELECT)$/.test(active.tagName)) {
      _focusRestore = { id: active.id || '', name: active.name || '' };
    }

    // Construir URL con datos del formulario
    try {
      navigate(buildFormUrl(form));
    } catch (x) {
      _focusRestore = null;
      form.submit(); // fallback a navegación normal
    }
  }, true); // capture=true → prioridad sobre los submit nativos

  window.addEventListener('pageshow', function () { doneBar(); });

  /* ── Descarga de archivos sin salir de la SPA ── */
  window.ggiDownloadBlob = function(url) {
    // Sincrónico: el clic debe ocurrir dentro del mismo frame de usuario
    // para que Android/Chrome lo acepte como descarga (user-activation).
    var a = document.createElement('a');
    a.href        = url;
    a.download    = ''; // el servidor envía el nombre vía Content-Disposition
    a.rel         = 'noopener';
    a.style.display = 'none';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
  };

})();
</script>

<script>
// ── Polling de badges en tiempo real ──────────────────────────────────────
// Consulta el servidor cada 20 s y actualiza los badges sin recargar la página.
// Se pausa automáticamente cuando la pestaña está en segundo plano.
(function () {
  var INTERVAL          = 30000;  // 30 s cuando el tab está visible
  var INTERVAL_HIDDEN   = 300000; // 5 min cuando el tab está oculto (heartbeat mínimo)
  var _timer            = null;
  var _sessionExpired   = false;

  function _setBadge(id, count, skip) {
    var el = document.getElementById(id);
    if (!el) return;
    if (count > 0 && !skip) {
      el.textContent = count > 9 ? '9+' : count;
      el.style.display = '';
    } else {
      el.style.display = 'none';
    }
  }

  // Redirige al login mostrando un aviso claro al usuario
  function _handleSessionExpired() {
    if (_sessionExpired) return; // evitar llamadas múltiples
    _sessionExpired = true;
    clearInterval(_timer);
    // Toast de aviso antes del redirect (2 s para que el usuario lo vea)
    var banner = document.createElement('div');
    banner.innerHTML = '<strong>Sesión expirada.</strong> Redirigiendo al inicio de sesión…';
    banner.style.cssText = [
      'position:fixed', 'top:0', 'left:0', 'right:0', 'z-index:99999',
      'background:#dc2626', 'color:#fff', 'text-align:center',
      'padding:calc(14px + env(safe-area-inset-top,0px)) 20px 14px', 'font-size:14px', 'font-family:Inter,sans-serif',
      'box-shadow:0 2px 12px rgba(0,0,0,.3)'
    ].join(';');
    document.body.appendChild(banner);
    setTimeout(function () {
      window.location.href = window.APP_URL + '/modules/auth/login.php';
    }, 2000);
  }

  var _lastPagosCount = -1; // Rastrea cambios para auto-reload en página de pagos
  var _badgesEtag     = '';  // ETag del último poll para evitar procesar respuestas sin cambios

  function poll() {
    var headers = { 'X-Requested-With': 'XMLHttpRequest' };
    if (_badgesEtag) headers['If-None-Match'] = _badgesEtag;

    fetch(window.APP_URL + '/modules/ajax_badges.php', {
      headers: headers,
      credentials: 'same-origin'
    })
    .then(function (r) {
      // HTTP 401 = sesión expirada → redirigir al login
      if (r.status === 401) { _handleSessionExpired(); return null; }
      // 304 Not Modified: badges sin cambios, no hay nada que actualizar
      if (r.status === 304) return null;
      var etag = r.headers.get('ETag');
      if (etag) _badgesEtag = etag;
      return r.ok ? r.json() : null;
    })
    .then(function (d) {
      if (!d || !d.ok) return;
      if (d.expired) { _handleSessionExpired(); return; }
      // Leer _active dinámicamente en cada poll para reflejar la página actual (SPA navigation)
      var _active = window.ACTIVE_MENU || '';
      // Solo actualizar badges de UI cuando el tab está visible (evitar parpadeo en segundo plano)
      if (!document.hidden) {
        _setBadge('nav-badge-cot',   d.cot,   _active === 'propuestas');
        _setBadge('nav-badge-pagos', d.pagos,  _active === 'pagos');
        _setBadge('nav-badge-notif', d.notif,  _active === 'notificaciones');
        // Notificar con toast cuando hay nuevos pagos pendientes (sin recargar)
        if (_active === 'pagos' && _lastPagosCount >= 0 && d.pagos > _lastPagosCount) {
          if (typeof showToast === 'function') showToast('Hay nuevos pagos pendientes de validación.', 'ok');
        }
        _lastPagosCount = d.pagos;
      }
    })
    .catch(function () {});
  }

  // Ajustar el intervalo del timer según visibilidad del tab
  function _startTimer() {
    clearInterval(_timer);
    _timer = setInterval(poll, document.hidden ? INTERVAL_HIDDEN : INTERVAL);
  }

  // Exponer globalmente para invocar tras acciones AJAX (validar, rechazar, subir)
  window._pollBadges = poll;

  document.addEventListener('DOMContentLoaded', function () {
    poll(); // Actualizar badges inmediatamente (corrige datos cacheados por el navegador)
    _startTimer();
  });

  // Cambio de visibilidad del tab: ajustar frecuencia del heartbeat
  // • Visible  → poll inmediato + volver a 20 s (el usuario está mirando)
  // • Oculto   → bajar a 5 min (keepalive mínimo para que el GC no borre la sesión)
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) {
      poll(); // ping inmediato al volver: detecta expiración en el acto
    }
    _startTimer(); // ajusta el intervalo
  });

  // Re-sincronizar al volver (bfcache o cache HTTP)
  window.addEventListener('pageshow', function (e) {
    if (e.persisted) {
      // Restauración desde bfcache: reiniciar timer que fue limpiado en pagehide
      _startTimer();
    }
    poll();
  });

  // Detener al salir de la página. Se usa 'pagehide' en vez de 'beforeunload'
  // porque beforeunload desactiva el bfcache del navegador → página en blanco al retroceder.
  window.addEventListener('pagehide', function () {
    clearInterval(_timer);
  });
})();
</script>

<script>
/* ── Bloquear scroll en inputs numéricos ── */
document.addEventListener('wheel', function(e) {
  if (document.activeElement && document.activeElement.type === 'number') {
    document.activeElement.blur();
  }
}, { passive: true });
</script>

<script>
/* ── Avatar chips con iniciales y color ──────────────────────────────
   Busca cualquier .av-chip[data-name] y lo convierte en un chip
   de color con las iniciales del nombre. El color es determinístico
   basado en un hash del nombre, así la misma empresa siempre
   tiene el mismo color.                                              */
(function () {
  var COLORS = [
    '#3B82F6','#8B5CF6','#10B981','#F97316',
    '#EC4899','#0EA5E9','#6366F1','#14B8A6',
    '#E8714A','#F59E0B','#EF4444','#6366F1',
    '#1D4ED8','#9333EA','#059669','#DC2626'
  ];
  function strHash(s) {
    var h = 5381;
    for (var i = 0; i < s.length; i++) { h = ((h << 5) + h) ^ s.charCodeAt(i); }
    return Math.abs(h);
  }
  function initAvatarChips(root) {
    (root || document).querySelectorAll('.av-chip[data-name]:not([data-av-init])').forEach(function(el) {
      var name = (el.dataset.name || '').trim();
      if (!name) { el.textContent = '?'; el.style.background = '#475569'; el.dataset.avInit = '1'; return; }
      var words = name.replace(/[^\w\sáéíóúüñÁÉÍÓÚÜÑ]/g,'').trim().split(/\s+/);
      var initials = words.length >= 2
        ? (words[0][0] + words[words.length - 1][0]).toUpperCase()
        : name.slice(0, 2).toUpperCase();
      el.textContent = initials;
      el.style.background = COLORS[strHash(name) % COLORS.length];
      el.dataset.avInit = '1';
    });
  }
  // Ejecutar de inmediato: el script está al final del body,
  // el DOM ya existe. El listener cubre el caso de navegación con caché.
  initAvatarChips();
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() { initAvatarChips(); });
  }
  window.initAvatarChips = initAvatarChips;
})();
</script>
<?php
// ── Flash message global (session-based, tras redirect con flash()) ──
$__flash = function_exists('flash_consume') ? flash_consume() : null;
if ($__flash): ?>
<script>
(function() {
  var key = <?= json_encode('_flash_' . $__flash['id']) ?>;
  // localStorage (no sessionStorage): el SW cachea el HTML de la página CON este
  // <script> incrustado; al reabrir la PWA (sesión nueva) sessionStorage está vacío
  // y el toast se re-disparaba en cada apertura. localStorage recuerda el id del
  // flash para siempre → cada mensaje se muestra una sola vez aunque la página esté
  // cacheada por el Service Worker.
  try {
    if (localStorage.getItem(key)) return;
    localStorage.setItem(key, '1');
    // Poda simple: evitar crecimiento ilimitado de claves _flash_/_urlmsg_
    var _fk = Object.keys(localStorage).filter(function(k){ return k.indexOf('_flash_')===0 || k.indexOf('_urlmsg_')===0; });
    if (_fk.length > 40) _fk.slice(0, _fk.length - 40).forEach(function(k){ localStorage.removeItem(k); });
  } catch(_) {}
  if (typeof showToast === 'function') {
    showToast(<?= json_encode($__flash['msg']) ?>, <?= json_encode($__flash['type']) ?>);
  } else {
    document.addEventListener('DOMContentLoaded', function() {
      if (typeof showToast === 'function') showToast(<?= json_encode($__flash['msg']) ?>, <?= json_encode($__flash['type']) ?>);
    });
  }
})();
</script>
<?php endif; ?>
<?php
// ── Error de validación de formulario (toast cuando $errors no está vacío) ──
if (!empty($errors)): ?>
<script>
if (typeof showToast === 'function') showToast('Revisa los campos marcados en rojo.', 'error');
</script>
<?php endif; ?>
<script>
// ── Lector global de ?msg= y ?msg_type= en URL (backward-compat, una sola vez) ──
(function() {
  var p = new URLSearchParams(window.location.search);
  var msg = p.get('msg');
  if (!msg) return;
  var key = '_urlmsg_' + btoa(msg).slice(0, 16);
  // localStorage: mismo motivo que el flash — el SW cachea la URL con ?msg= y se
  // re-disparaba en cada apertura. Recordar el mensaje evita el replay.
  try {
    if (localStorage.getItem(key)) return;
    localStorage.setItem(key, '1');
  } catch(_) {}
  var type = p.get('msg_type') || 'ok';
  p.delete('msg'); p.delete('msg_type');
  history.replaceState({}, '', window.location.pathname + (p.toString() ? '?' + p.toString() : ''));
  if (typeof showToast === 'function') showToast(decodeURIComponent(msg), type);
})();
</script>

<script>
// ── Loading states globales ───────────────────────────────────────────────────
// Proporciona feedback visual inmediato al usuario cuando:
//   1. Se envía cualquier formulario (botón submit se desactiva + muestra spinner)
//   2. Se hace clic en un botón de acción que no es submit pero podría tardar
//
// No afecta a botones que ya manejan su propio estado (ej: modales, AJAX inline).
// El estado se revierte automáticamente si el navegador vuelve a la página
// (bfcache/popstate) para evitar botones que quedan "atascados".
(function () {
  'use strict';

  var SPINNER_SVG = '<svg style="animation:spin .7s linear infinite;display:inline-block;vertical-align:middle;margin-right:5px;" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>';

  // Inyectar keyframe solo una vez
  if (!document.getElementById('_spin_style')) {
    var st = document.createElement('style');
    st.id = '_spin_style';
    st.textContent = '@keyframes spin{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}';
    document.head.appendChild(st);
  }

  function freezeBtn(btn) {
    if (btn.dataset.loading === '1') return;
    btn.dataset.loading   = '1';
    btn.dataset.origHtml  = btn.innerHTML;
    btn.dataset.origWidth = btn.offsetWidth + 'px';
    btn.disabled          = true;
    btn.style.minWidth    = btn.dataset.origWidth;
    btn.style.opacity     = '0.8';
    btn.innerHTML         = SPINNER_SVG + (btn.dataset.loadingText || 'Guardando…');
  }

  function thawBtn(btn) {
    if (btn.dataset.loading !== '1') return;
    btn.dataset.loading  = '0';
    btn.disabled         = false;
    btn.style.opacity    = '';
    btn.style.minWidth   = '';
    if (btn.dataset.origHtml) btn.innerHTML = btn.dataset.origHtml;
  }

  // Liberar todos los botones congelados (por si el usuario vuelve con bfcache)
  function thawAll() {
    document.querySelectorAll('[data-loading="1"]').forEach(thawBtn);
  }

  // Delegar en document para capturar dinámicamente formularios en SPA
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || form.tagName !== 'FORM') return;
    // No interferir con formularios que ya tienen su propio manejo de estado
    if (form.dataset.noAutoLoading === '1') return;

    // Encontrar el botón que disparó el submit
    var submitter = e.submitter || form.querySelector('button[type="submit"], input[type="submit"]');
    if (!submitter || submitter.dataset.noAutoLoading === '1') return;

    // Si el formulario tiene action AJAX (data-ajax) o se procesa con JS, no congelar
    if (form.dataset.ajax) return;

    freezeBtn(submitter);

    // Timeout de seguridad: si la página no navega en 12 s, liberar el botón
    // (p.ej. validación HTML5 bloqueó el submit, o el servidor respondió con error)
    setTimeout(function () { thawBtn(submitter); }, 12000);
  }, true);

  // Restaurar botones al volver con bfcache
  window.addEventListener('pageshow', function (e) {
    if (e.persisted) thawAll();
  });

  // Restaurar botones en navegación SPA (el main se reemplaza, los botones desaparecen,
  // pero por si acaso queda alguno fuera del main)
  window.addEventListener('spa:before', function () { thawAll(); });

  // Exponer para que código externo pueda congelar/liberar manualmente
  window.freezeBtn = freezeBtn;
  window.thawBtn   = thawBtn;
  window.thawAllBtns = thawAll;
})();
</script>

<script>
/* ══════════════════════════════════════════════════════════════════
   txt-ellipsis interactivo v2 — expandir / contraer + copiar
   Sin necesidad de refrescar página. Funciona en carga inicial,
   SPA navigation, tablas AJAX y contenido dinámico.
   ══════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  /* ── Constantes SVG ── */
  var SVG_COPY  = '<svg width="11" height="11" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true" style="flex-shrink:0"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>';
  var SVG_CHECK = '<svg width="11" height="11" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true" style="flex-shrink:0"><polyline points="20 6 9 17 4 12"/></svg>';

  var copyBtn   = null;
  var hoverEl   = null;
  var hideTimer = null;
  var moTimer   = null;   // MutationObserver debounce
  var _ready    = false;  // init called flag

  /* ════════════════════════════════════════
     BOTÓN FLOTANTE COPIAR
     ════════════════════════════════════════ */
  function initCopyBtn() {
    if (document.getElementById('ggi-copy-btn')) return; // ya existe (bfcache)
    copyBtn = document.createElement('button');
    copyBtn.id   = 'ggi-copy-btn';
    copyBtn.type = 'button';
    copyBtn.setAttribute('aria-label', 'Copiar nombre completo');
    copyBtn.title = 'Copiar nombre';
    copyBtn.innerHTML = SVG_COPY;   // ícono discreto (sin texto), pegado al nombre
    document.body.appendChild(copyBtn);
    copyBtn.addEventListener('click',      doCopy);
    copyBtn.addEventListener('mouseenter', function () { clearTimeout(hideTimer); });
    copyBtn.addEventListener('mouseleave', scheduleHide);
  }

  function doCopy(e) {
    e.stopPropagation();
    e.preventDefault();
    if (!hoverEl) return;
    /* Prioridad: data-fulltext > data-tip > title > textContent completo */
    var text = (hoverEl.dataset.fulltext || hoverEl.dataset.tip || hoverEl.title || hoverEl.textContent || '').trim();
    if (!text) return;
    if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
      navigator.clipboard.writeText(text).then(showCopied, function () { fallbackCopy(text); });
    } else {
      fallbackCopy(text);
    }
  }

  function fallbackCopy(text) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.cssText = 'position:fixed;top:-9999px;left:-9999px;opacity:0;pointer-events:none';
    document.body.appendChild(ta);
    ta.focus();
    ta.select();
    try { if (document.execCommand('copy')) showCopied(); } catch (_) { /* silencioso */ }
    document.body.removeChild(ta);
  }

  function showCopied() {
    if (!copyBtn) return;
    copyBtn.innerHTML = SVG_CHECK;
    copyBtn.classList.add('ggi-copied');
    if (typeof window.showToast === 'function') window.showToast('Nombre copiado', 'ok');
    setTimeout(function () {
      if (copyBtn) {
        copyBtn.innerHTML = SVG_COPY;
        copyBtn.classList.remove('ggi-copied');
      }
    }, 1600);
  }

  /* ════════════════════════════════════════
     POSICIONAMIENTO DEL BOTÓN
     ════════════════════════════════════════ */
  function positionBtn(el) {
    if (!copyBtn) return;
    var rect = el.getBoundingClientRect();
    var bh = 26, bw = 28;   // ícono compacto
    var vw = window.innerWidth  || document.documentElement.clientWidth;
    var vh = window.innerHeight || document.documentElement.clientHeight;
    /* Pegado al final del nombre, en su MISMA línea (centrado vertical).
       No flota sobre otras filas como el chip anterior. */
    var top  = rect.top + (rect.height - bh) / 2;
    var left = rect.right + 6;
    /* Si no cabe a la derecha (nombre llega al borde), ubicarlo a la izquierda */
    if (left + bw > vw - 6) left = rect.left - bw - 6;
    if (left < 4)           left = 4;
    if (top < 4)            top  = 4;
    if (top + bh > vh - 4)  top  = vh - bh - 4;
    copyBtn.style.top  = top  + 'px';
    copyBtn.style.left = left + 'px';
  }

  function scheduleHide() {
    clearTimeout(hideTimer);
    hideTimer = setTimeout(function () {
      if (copyBtn) copyBtn.classList.remove('visible');
      hoverEl = null;
    }, 200);
  }

  /* ════════════════════════════════════════
     DETECCIÓN DE OVERFLOW — núcleo
     Mide el overflow REAL comparando scrollWidth vs clientWidth.
     Se invoca en múltiples puntos para garantizar inmediatez.
     ════════════════════════════════════════ */
  function markOverflowing() {
    document.querySelectorAll('.txt-ellipsis').forEach(function (el) {
      var isOverflowing = el.scrollWidth > el.clientWidth + 2;
      if (isOverflowing) {
        el.setAttribute('data-overflows', '1');
        /* Mover el title nativo → data-tip (tooltip estilizado) y QUITAR el title
           para que no aparezca el tooltip nativo del navegador encima (doble sombra).
           Guardamos el texto en data-fulltext para el botón Copiar. */
        var full = el.getAttribute('title') || el.dataset.tip || el.textContent || '';
        if (full) {
          el.setAttribute('data-tip', full.trim());
          el.setAttribute('data-fulltext', full.trim());
          if (el.hasAttribute('title')) el.removeAttribute('title');
        }
      } else {
        el.removeAttribute('data-overflows');
        el.removeAttribute('data-tip');
        /* texto completo visible: el title nativo tampoco aporta → quitarlo */
        if (el.hasAttribute('title')) {
          el.setAttribute('data-fulltext', (el.getAttribute('title') || '').trim());
          el.removeAttribute('title');
        }
      }
    });
  }

  /* ════════════════════════════════════════
     MUTATIONOBSERVER — detecta contenido dinámico
     (tablas recargadas por AJAX, SPA swaps, etc.)
     ════════════════════════════════════════ */
  function initObserver() {
    if (!window.MutationObserver) return;
    var root = document.querySelector('main, #main-content, .main') || document.body;
    var obs  = new MutationObserver(function (mutations) {
      var hasNewNodes = mutations.some(function (m) {
        return m.type === 'childList' && m.addedNodes.length > 0;
      });
      if (!hasNewNodes) return;
      clearTimeout(moTimer);
      moTimer = setTimeout(markOverflowing, 60); /* pequeño delay para que el layout se calcule */
    });
    obs.observe(root, { childList: true, subtree: true });
  }

  /* ════════════════════════════════════════
     EVENTOS DE INTERACCIÓN
     ════════════════════════════════════════ */
  function bindEvents() {
    /* Clic en cualquier parte: ocultar el botón Copiar. NO se expande el nombre
       (eliminado jun 2026): el clic sobre el nombre debe navegar (es un link), y el
       nombre completo ya se ve en el tooltip al hacer hover. */
    document.addEventListener('click', function (e) {
      if (copyBtn && !copyBtn.contains(e.target)) {
        clearTimeout(hideTimer);
        copyBtn.classList.remove('visible');
        hoverEl = null;
      }
    });

    /* Hover: mostrar botón copiar en TODOS los .txt-ellipsis
       (sin importar si está truncado o no — se aplica a toda razón social) */
    document.addEventListener('mouseover', function (e) {
      var el = e.target.closest('.txt-ellipsis');
      if (!el || !copyBtn) return;
      /* Solo mostrar si hay algo que copiar */
      var text = (el.dataset.fulltext || el.dataset.tip || el.title || el.textContent || '').trim();
      if (!text) return;
      clearTimeout(hideTimer);
      hoverEl = el;
      positionBtn(el);
      copyBtn.classList.add('visible');
    });

    document.addEventListener('mouseout', function (e) {
      var el = e.target.closest('.txt-ellipsis');
      if (!el) return;
      var to = e.relatedTarget;
      if (to && copyBtn && (to === copyBtn || copyBtn.contains(to))) return;
      scheduleHide();
    });
  }

  /* ════════════════════════════════════════
     INICIALIZACIÓN ROBUSTA
     Funciona tanto si DOMContentLoaded ya disparó (bfcache, SPA)
     como si todavía no (primera carga normal).
     ════════════════════════════════════════ */
  function init() {
    if (_ready) return;
    _ready = true;

    /* Copiar nombre completo: botón flotante "Copiar" en hover sobre .txt-ellipsis.
       (Reactivado jun 2026 — el usuario quiere copiar nombres.) El click-expandir
       se eliminó para que el clic del nombre navegue sin ambigüedad. */
    initCopyBtn();
    bindEvents();

    /* Escaneo inmediato */
    markOverflowing();
    /* Safety net: segundo escaneo a 80 ms para cuando el navegador
       aún no ha calculado el layout en el primer tick */
    setTimeout(markOverflowing, 80);

    /* Re-escaneo en resize */
    window.addEventListener('resize', debounce(markOverflowing, 220));

    /* Re-escaneo tras navegación SPA (varios eventos posibles) */
    window.addEventListener('spa:loaded',      function () { setTimeout(markOverflowing, 50); });
    window.addEventListener('ggi:pagechanged', function () { setTimeout(markOverflowing, 50); });

    /* Re-escaneo tras carga completa de recursos (imágenes pueden desplazar layout) */
    window.addEventListener('load', debounce(markOverflowing, 120));

    /* MutationObserver para contenido AJAX / dinámico */
    initObserver();

    /* Restaurar al volver con bfcache */
    window.addEventListener('pageshow', function (e) {
      if (e.persisted) {
        /* Ocultar botón copiar que pudo quedar visible antes de navegar */
        if (copyBtn) { clearTimeout(hideTimer); copyBtn.classList.remove('visible'); hoverEl = null; }
        setTimeout(markOverflowing, 60);
      }
    });
  }

  function debounce(fn, ms) {
    var t;
    return function () { clearTimeout(t); t = setTimeout(fn, ms); };
  }

  /* ── Lanzar según estado del DOM ── */
  if (document.readyState === 'loading') {
    /* Parsing aún en curso → esperar DOMContentLoaded */
    document.addEventListener('DOMContentLoaded', init);
  } else {
    /* DOM ya disponible (script cargado async / tras SPA) → ejecutar ahora */
    setTimeout(init, 0);
  }

  /* API pública: llamar desde cualquier módulo tras refrescar una tabla por AJAX */
  window.ggiMarkOverflowing = markOverflowing;

})();
</script>

<!-- ── PWA: Service Worker + Web Push ────────────────────────────────────── -->
<script>
(function() {
  if (!('serviceWorker' in navigator)) return;

  // Cuando un Service Worker NUEVO toma control (tras skipWaiting+clients.claim),
  // recargar una vez para que la página quede gobernada por él. Sin esto, un SW
  // viejo sin el bypass de embed seguía activo y el modal mostraba el dashboard.
  var _ggiSWRefreshing = false;
  navigator.serviceWorker.addEventListener('controllerchange', function() {
    if (_ggiSWRefreshing) return;
    _ggiSWRefreshing = true;
    window.location.reload();
  });

  // Purga TODOS los SW + cachés y recarga limpio. Reutilizable.
  function _ggiPurgarSW(reload) {
    return navigator.serviceWorker.getRegistrations()
      .then(function(regs){ return Promise.all(regs.map(function(r){ return r.unregister(); })); })
      .then(function(){ return (window.caches ? caches.keys() : []); })
      .then(function(keys){ return Promise.all((keys || []).map(function(k){ return caches.delete(k); })); })
      .catch(function(){})
      .then(function(){ if (reload) location.replace(location.pathname); });
  }

  // Reset manual: visitar cualquier URL con ?swreset=1.
  if (location.search.indexOf('swreset=1') !== -1) {
    _ggiPurgarSW(true);
    return; // no registrar de nuevo en esta carga
  }

  // ── Auto-reparación: los SW viejos (sin el bypass de embed) NO responden a
  // GET_VERSION. Si el SW que controla la página no responde, está viejo → se
  // purga y recarga UNA vez. Destraba solo el "modal muestra dashboard" sin que
  // el usuario haga nada. Los SW al día (v12+) responden y no se tocan, así que
  // futuros despliegues no requieren cambiar nada aquí.
  (function autoSanarSW() {
    var ctrl = navigator.serviceWorker.controller;
    if (!ctrl) return;                                   // sin SW controlando: nada que sanar
    if (sessionStorage.getItem('_ggiSWHeal')) return;    // ya intentado en esta sesión
    var respondio = false;
    var mc = new MessageChannel();
    mc.port1.onmessage = function() { respondio = true; }; // SW moderno → todo bien
    try { ctrl.postMessage({ type: 'GET_VERSION' }, [mc.port2]); } catch (e) { return; }
    setTimeout(function() {
      if (respondio) return;                              // SW al día: no tocar
      sessionStorage.setItem('_ggiSWHeal', '1');
      _ggiPurgarSW(false).then(function(){ window.location.reload(); });
    }, 1500);
  })();

  var VAPID_PUB = '<?= VAPID_PUBLIC_KEY ?>';

  // Convierte la clave pública VAPID (base64url) al Uint8Array que exige la API
  function urlBase64ToUint8Array(base64String) {
    var padding = '='.repeat((4 - base64String.length % 4) % 4);
    var base64  = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    var raw     = atob(base64);
    var arr     = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) arr[i] = raw.charCodeAt(i);
    return arr;
  }

  function sendSubToServer(sub, unsubscribe) {
    var s   = sub.toJSON ? sub.toJSON() : sub;
    var body = unsubscribe
      ? JSON.stringify({ unsubscribe: true, endpoint: s.endpoint })
      : JSON.stringify({ endpoint: s.endpoint, p256dh: s.keys.p256dh, auth: s.keys.auth });
    fetch('<?= APP_URL ?>/modules/ajax_push_subscribe.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: body
    }).catch(function() {});
  }

  // Registrar SW. updateViaCache:'none' obliga al navegador a NO usar la caché HTTP
  // al revisar sw.js (clave para que un SW viejo cacheado como immutable se actualice).
  navigator.serviceWorker.register('<?= APP_URL ?>/sw.js', { scope: '/', updateViaCache: 'none' })
    .then(function(reg) {
      // Forzar comprobación de versión nueva en cada carga completa
      // (la navegación SPA no revalida sw.js por sí sola).
      try { reg.update(); } catch (e) {}
      // Actualización silenciosa
      if (reg.waiting) reg.waiting.postMessage({ type: 'SKIP_WAITING' });
      reg.addEventListener('updatefound', function() {
        var sw = reg.installing;
        if (!sw) return;
        sw.addEventListener('statechange', function() {
          if (sw.state === 'installed' && navigator.serviceWorker.controller) {
            sw.postMessage({ type: 'SKIP_WAITING' });
          }
        });
      });

      // ── Suscripción push (solo si Push API disponible y VAPID configurado) ──
      if (!('PushManager' in window) || !VAPID_PUB) return;

      reg.pushManager.getSubscription().then(function(existing) {
        if (existing) {
          // Ya suscrito — re-enviar al servidor por si se reinició la BD
          sendSubToServer(existing, false);
          return;
        }

        // Si el permiso fue denegado, mostrar banner persistente para guiar al usuario
        if (Notification.permission === 'denied') {
          _showPushDeniedBanner();
          return;
        }

        // Mostrar banner amigable en lugar de pedir permiso directamente (mejor UX)
        _showPushPromptBanner(reg);
      }).catch(function() {});
    })
    .catch(function() {});

  // ── Banner: invitar al usuario a activar notificaciones ──────────────────
  function _showPushPromptBanner(reg) {
    // Solo mostrar una vez por sesión
    if (sessionStorage.getItem('ggi_push_prompted')) return;
    sessionStorage.setItem('ggi_push_prompted', '1');

    var b = document.createElement('div');
    b.id = 'ggi-push-banner';
    b.innerHTML =
      '<span style="flex:1">🔔 <strong>Activar notificaciones</strong> — recibe alertas de propuestas y pagos en tu celular</span>' +
      '<button id="ggi-push-yes" style="background:#1A3A8F;color:#fff;border:none;border-radius:7px;padding:6px 14px;font-size:12px;font-weight:600;cursor:pointer;white-space:nowrap">Activar</button>' +
      '<button id="ggi-push-no"  style="background:transparent;border:none;color:#64748B;font-size:12px;cursor:pointer;padding:4px 8px">Ahora no</button>';
    b.style.cssText = 'position:fixed;bottom:20px;left:50%;transform:translateX(-50%);z-index:9999;' +
      'background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:12px 16px;' +
      'display:flex;align-items:center;gap:10px;box-shadow:0 4px 20px rgba(0,0,0,.12);' +
      'max-width:500px;width:calc(100% - 32px);font-size:13px;color:#1e293b;';

    document.body.appendChild(b);

    document.getElementById('ggi-push-yes').addEventListener('click', function() {
      b.remove();
      Notification.requestPermission().then(function(perm) {
        if (perm !== 'granted') return;
        reg.pushManager.subscribe({
          userVisibleOnly:      true,
          applicationServerKey: urlBase64ToUint8Array(VAPID_PUB),
        }).then(function(sub) {
          sendSubToServer(sub, false);
          _showPushSuccessToast();
        }).catch(function(err) {
          console.warn('[GGI Push] subscribe error:', err);
        });
      });
    });

    document.getElementById('ggi-push-no').addEventListener('click', function() {
      b.remove();
    });

    // Auto-cerrar después de 15 s
    setTimeout(function() { if (b.parentNode) b.remove(); }, 15000);
  }

  // ── Banner: permiso ya denegado, instrucciones para reactivar ───────────
  function _showPushDeniedBanner() {
    if (sessionStorage.getItem('ggi_push_denied_shown')) return;
    sessionStorage.setItem('ggi_push_denied_shown', '1');
    var b = document.createElement('div');
    b.innerHTML =
      '🔕 <strong>Notificaciones bloqueadas.</strong> Para reactivarlas, ve a Configuración del navegador → Permisos del sitio → Notificaciones y permite este sitio.' +
      '<button style="margin-left:12px;background:transparent;border:none;color:#64748B;font-size:12px;cursor:pointer" onclick="this.parentNode.remove()">✕</button>';
    b.style.cssText = 'position:fixed;bottom:20px;left:50%;transform:translateX(-50%);z-index:9999;' +
      'background:#FFF7ED;border:1px solid #FED7AA;border-radius:12px;padding:12px 16px;' +
      'max-width:500px;width:calc(100% - 32px);font-size:13px;color:#92400E;box-shadow:0 4px 20px rgba(0,0,0,.1);';
    document.body.appendChild(b);
    setTimeout(function() { if (b.parentNode) b.remove(); }, 10000);
  }

  function _showPushSuccessToast() {
    var t = document.createElement('div');
    t.textContent = '✅ Notificaciones activadas correctamente';
    t.style.cssText = 'position:fixed;bottom:20px;left:50%;transform:translateX(-50%);z-index:9999;' +
      'background:#065F46;color:#fff;border-radius:10px;padding:10px 20px;font-size:13px;font-weight:600;' +
      'box-shadow:0 4px 20px rgba(0,0,0,.2);';
    document.body.appendChild(t);
    setTimeout(function() { t.remove(); }, 4000);
  }
})();
</script>

<!-- ═══════════════════════════════════════════════════════════════════════
     COMPONENTE GLOBAL: ms-drop — Filtro multiselección de estados
     Uso: <div class="ms-drop" id="ms-XXX"> ... </div>
     JS:  msDrop.toggle(id)   — abrir/cerrar
          msDrop.change(id)   — registrar cambio + debounce submit
     ═══════════════════════════════════════════════════════════════════════ -->
<style>
/* ── ms-drop: contenedor ── */
.ms-drop {
  position: relative;
  display: inline-block;
  flex-shrink: 0;
}

/* ── Botón activador ── */
.ms-drop-btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 9px 12px;
  border: 1.5px solid var(--gris-borde, #E2E8F0);
  border-radius: 9px;
  background: var(--bg-surface, #fff);
  color: var(--gris-dark, #0F172A);
  font-size: 13px;
  font-family: 'Inter', sans-serif;
  font-weight: 400;
  cursor: pointer;
  white-space: nowrap;
  max-width: 240px;
  transition: border-color .15s;
  user-select: none;
  text-align: left; /* el control puede heredar text-align:center del contenedor;
                       en móvil (full-width) eso centraba el label — forzar izquierda */
}
.ms-drop-btn:hover { border-color: var(--azul, #1A3A8F); }
.ms-drop.is-open .ms-drop-btn {
  border-color: var(--azul, #1A3A8F);
  box-shadow: 0 0 0 3px rgba(26,58,143,.08);
}

/* ── Etiqueta de texto dentro del botón ── */
.ms-drop-lbl {
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  min-width: 0;
  color: var(--gris-dark, #0F172A);
}
.ms-drop-lbl.is-placeholder { color: var(--gris-texto, #64748B); }

/* ── Badge contador ── */
.ms-drop-count {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 18px;
  height: 18px;
  padding: 0 5px;
  border-radius: 100px;
  background: var(--azul, #1A3A8F);
  color: #fff;
  font-size: 10px;
  font-weight: 700;
  flex-shrink: 0;
}

/* ── Flecha ── */
.ms-drop-arrow {
  flex-shrink: 0;
  color: var(--gris-texto, #64748B);
  transition: transform .18s;
}
.ms-drop.is-open .ms-drop-arrow { transform: rotate(180deg); }

/* ── Menú desplegable ── */
.ms-drop-menu {
  position: absolute;
  top: calc(100% + 6px);
  left: 0;
  min-width: 100%;
  max-width: 280px;
  background: var(--bg-surface, #fff);
  border: 1.5px solid var(--gris-borde, #E2E8F0);
  border-radius: 10px;
  box-shadow: 0 8px 32px rgba(0,0,0,.10), 0 2px 8px rgba(0,0,0,.06);
  z-index: 9000;
  padding: 6px 4px;
  max-height: 320px;
  overflow-y: auto;
  animation: msDropIn .14s ease;
}
@keyframes msDropIn {
  from { opacity: 0; transform: translateY(-6px); }
  to   { opacity: 1; transform: translateY(0); }
}

/* ── Opción con checkbox ── */
.ms-drop-item {
  display: flex;
  align-items: center;
  gap: 9px;
  padding: 8px 10px;
  border-radius: 7px;
  cursor: pointer;
  font-size: 13px;
  color: var(--gris-dark, #0F172A);
  transition: background .1s;
  white-space: nowrap;
  user-select: none;
}
.ms-drop-item:hover { background: var(--gris-bg, #F8FAFC); }
.ms-drop-item input[type="checkbox"] {
  width: 15px;
  height: 15px;
  border-radius: 4px;
  accent-color: var(--azul, #1A3A8F);
  cursor: pointer;
  flex-shrink: 0;
  margin: 0;
}

/* ── Separador opcional ── */
.ms-drop-sep {
  height: 1px;
  background: var(--gris-borde, #E2E8F0);
  margin: 4px 6px;
}

/* ── Color dot (para estados con color) ── */
.ms-drop-dot {
  display: inline-block;
  width: 10px;
  height: 10px;
  border-radius: 50%;
  flex-shrink: 0;
  box-shadow: 0 0 0 2px rgba(0,0,0,.04);  /* anillo sutil para separar del fondo */
  forced-color-adjust: none;              /* evita que el modo de alto contraste del SO lo vuelva gris */
}
/* Colores de estado por CLASE (ganan ante cualquier override y no dependen del style inline) */
.ms-drop-dot.is-ok    { background:#10B981 !important; }
.ms-drop-dot.is-info  { background:#3B82F6 !important; }
.ms-drop-dot.is-warn  { background:#F59E0B !important; }
.ms-drop-dot.is-err   { background:#EF4444 !important; }
.ms-drop-dot.is-muted { background:#94A3B8 !important; }
:root[data-tema="oscuro"] .ms-drop-dot { box-shadow: 0 0 0 2px rgba(255,255,255,.06); }

/* ── Dark mode ── */
:root[data-tema="oscuro"] .ms-drop-btn {
  background: #182336;
  border-color: #1A2840;
  color: #ECF1FB;
}
:root[data-tema="oscuro"] .ms-drop-menu {
  background: #182336;
  border-color: #1A2840;
}
:root[data-tema="oscuro"] .ms-drop-item { color: #ECF1FB; }
:root[data-tema="oscuro"] .ms-drop-item:hover { background: #1D2C42; }
:root[data-tema="oscuro"] .ms-drop-sep { background: #1A2840; }
:root[data-tema="oscuro"] .ms-drop-lbl.is-placeholder { color: #8893A8; }

/* ── Mobile: filtro y menú full-width (alineado al breakpoint del toolbar/tarjetas) ── */
@media (max-width: 768px) {
  .ms-drop { width: 100%; }
  .ms-drop-btn { width: 100%; max-width: unset; justify-content: space-between; }
  .ms-drop-menu { min-width: 100%; max-width: 100%; }
}
</style>

<script>
/* ── msDrop: controlador global del componente ms-drop ── */
(function() {
  var _timers = {};  // debounce timers por instancia
  var _open = null;  // id del dropdown actualmente abierto

  window.msDrop = {

    // Abrir / cerrar el dropdown
    toggle: function(id) {
      var el = document.getElementById(id);
      if (!el) return;
      var menu = el.querySelector('.ms-drop-menu');
      if (!menu) return;

      if (_open && _open !== id) {
        msDrop.close(_open);
      }
      var isOpen = el.classList.contains('is-open');
      if (isOpen) {
        msDrop.close(id);
      } else {
        menu.hidden = false;
        el.classList.add('is-open');
        var btn = el.querySelector('.ms-drop-btn');
        if (btn) btn.setAttribute('aria-expanded', 'true');
        _open = id;
      }
    },

    // Cerrar sin submit
    close: function(id) {
      var el = document.getElementById(id);
      if (!el) return;
      var menu = el.querySelector('.ms-drop-menu');
      if (menu) menu.hidden = true;
      el.classList.remove('is-open');
      var btn = el.querySelector('.ms-drop-btn');
      if (btn) btn.setAttribute('aria-expanded', 'false');
      if (_open === id) _open = null;
    },

    // Registrar cambio de checkbox → debounce 420ms → submit
    change: function(id, cb) {
      msDrop._updateLabel(id);
      clearTimeout(_timers[id]);
      _timers[id] = setTimeout(function() {
        msDrop.close(id);
        var form = cb.closest('form');
        if (form) form.submit();
      }, 420);
    },

    // Actualizar el texto del botón según checkboxes marcados
    _updateLabel: function(id) {
      var el = document.getElementById(id);
      if (!el) return;
      var checked = Array.from(el.querySelectorAll('input[type="checkbox"]:checked'));
      var lbl = el.querySelector('.ms-drop-lbl');
      var cnt = el.querySelector('.ms-drop-count');
      if (!lbl) return;

      if (checked.length === 0) {
        lbl.textContent = lbl.dataset.placeholder || 'Todos';
        lbl.classList.add('is-placeholder');
        if (cnt) cnt.hidden = true;
      } else {
        var names = checked.map(function(c) {
          var item = c.closest('.ms-drop-item');
          var span = item ? item.querySelector('span') : null;
          return span ? span.textContent.trim() : c.value;
        });
        // Mostrar hasta 2 nombres, luego "+N más"
        if (names.length <= 2) {
          lbl.textContent = names.join(', ');
        } else {
          lbl.textContent = names.slice(0, 2).join(', ') + ' +' + (names.length - 2) + ' más';
        }
        lbl.classList.remove('is-placeholder');
        if (cnt) { cnt.hidden = false; cnt.textContent = checked.length; }
      }
    }
  };

  // Cerrar al hacer clic fuera de cualquier ms-drop abierto
  document.addEventListener('click', function(e) {
    if (!_open) return;
    var el = document.getElementById(_open);
    if (el && !el.contains(e.target)) {
      // Si hay un timer pendiente, cancelar y hacer submit inmediato
      if (_timers[_open]) {
        clearTimeout(_timers[_open]);
        var form = el.querySelector('input[type="checkbox"]');
        form = form ? form.closest('form') : null;
        msDrop.close(_open);
        if (form) form.submit();
      } else {
        msDrop.close(_open);
      }
    }
  });

  // Cerrar con Escape y devolver el foco al botón activador (a11y)
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && _open) {
      var el = document.getElementById(_open);
      msDrop.close(_open);
      var btn = el && el.querySelector('.ms-drop-btn');
      if (btn) btn.focus();
    }
  });

  // Estado inicial aria-expanded en TODOS los ms-drop (helper o markup inline)
  document.querySelectorAll('.ms-drop-btn').forEach(function(b) {
    if (!b.hasAttribute('aria-expanded')) b.setAttribute('aria-expanded', 'false');
  });
})();
</script>

<!-- ═══════════════════════════════════════════════════════════════════════
     GGI Lightbox — visor global de imágenes/PDF en modal (sin salir de la página)
     Uso: <a href="archivo.jpg" data-ggi-preview data-title="Voucher">Ver</a>
          o JS: ggiLightbox.open(url, {title, download, filename})
     Detecta imagen vs PDF automáticamente (img → fallback iframe).
     ═══════════════════════════════════════════════════════════════════════ -->
<style>
.ggi-lightbox { position: fixed; inset: 0; z-index: 10050; display: flex; align-items: center; justify-content: center; padding: calc(18px + env(safe-area-inset-top, 0px)) 18px calc(18px + env(safe-area-inset-bottom, 0px)); }
.ggi-lightbox[hidden] { display: none; }
.ggi-lightbox-backdrop { position: absolute; inset: 0; background: rgba(0,0,0,.62); backdrop-filter: blur(3px); -webkit-backdrop-filter: blur(3px); animation: ggiLbFade .18s ease; }
.ggi-lightbox-stage { position: relative; z-index: 1; display: flex; flex-direction: column; max-width: 96vw; max-height: 94vh; animation: ggiLbIn .2s cubic-bezier(.16,1,.3,1); }
.ggi-lightbox-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 0 2px 10px; }
.ggi-lightbox-title { color: #fff; opacity: .92; font-size: 13px; font-weight: 500; font-family: 'Inter', sans-serif; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 58vw; }
.ggi-lightbox-actions { display: flex; gap: 6px; flex-shrink: 0; }
.ggi-lightbox-btn { width: 40px; height: 40px; display: inline-flex; align-items: center; justify-content: center; border: none; border-radius: 10px; background: rgba(255,255,255,.13); color: #fff; cursor: pointer; transition: background .15s; text-decoration: none; }
.ggi-lightbox-btn:hover { background: rgba(255,255,255,.26); }
.ggi-lightbox-btn:focus-visible { outline: 2px solid #fff; outline-offset: 2px; }
.ggi-lightbox-body { position: relative; display: flex; align-items: center; justify-content: center; max-width: 96vw; max-height: 84vh; overflow: auto; background: var(--bg-surface); border-radius: 12px; box-shadow: 0 24px 70px rgba(0,0,0,.45); min-width: 140px; min-height: 120px; }
.ggi-lightbox-img { display: block; max-width: 96vw; max-height: 84vh; object-fit: contain; cursor: zoom-in; }
.ggi-lightbox-img[hidden], .ggi-lightbox-frame[hidden] { display: none !important; }
.ggi-lightbox-img.zoomed { max-width: none; max-height: none; cursor: zoom-out; }
/* Al hacer zoom: el body pasa a block para que el scroll alcance todos los bordes
   (flex + align-center + overflow recorta el top/left y no deja desplazar). */
.ggi-lightbox-body.is-zoomed { display: block; }
.ggi-lightbox-frame { width: 88vw; height: 84vh; border: none; background: #fff; -webkit-overflow-scrolling: touch; }
/* Visor PDF con pdf.js — el OVERLAY ENTERO es el contenedor scrolleable. Un position:fixed
   a pantalla completa con overflow:auto es lo que iOS/iPhone desplaza de forma fiable
   (los contenedores anidados dentro de un overlay fixed NO scrollean en iOS). Barra sticky. */
.ggi-lightbox-pdf { display: flex; flex-direction: column; align-items: center; gap: 12px; padding: 12px 12px 28px; }
.ggi-lightbox-pdf[hidden] { display: none !important; }
.ggi-lightbox-pdf canvas { display: block; max-width: 100%; height: auto; box-shadow: 0 2px 10px rgba(0,0,0,.35); border-radius: 2px; background: #fff; }
/* Página = canvas + capa de texto (seleccionable/copiable) superpuesta */
.ggi-pdf-page { position: relative; max-width: 100%; }
.ggi-pdf-page .textLayer {
  position: absolute; left: 0; top: 0; right: 0; bottom: 0;
  overflow: hidden; line-height: 1; text-align: initial;
  text-size-adjust: none; forced-color-adjust: none; transform-origin: 0 0; z-index: 2;
}
.ggi-pdf-page .textLayer span, .ggi-pdf-page .textLayer br {
  color: transparent; position: absolute; white-space: pre; cursor: text; transform-origin: 0% 0%;
}
.ggi-pdf-page .textLayer span.markedContent { top: 0; height: 0; }
.ggi-pdf-page .textLayer ::selection { background: rgba(56,130,221,.35); }
.ggi-pdf-page .textLayer ::-moz-selection { background: rgba(56,130,221,.35); }
.ggi-lightbox.lb-pdf { display: block; padding: 0; overflow-y: auto; -webkit-overflow-scrolling: touch; overscroll-behavior: contain; }
.ggi-lightbox.lb-pdf .ggi-lightbox-backdrop { position: fixed; }
.ggi-lightbox.lb-pdf .ggi-lightbox-stage { display: block; width: min(900px, 100%); max-width: 100%; max-height: none; margin: 0 auto; min-height: 100%; }
.ggi-lightbox.lb-pdf .ggi-lightbox-toolbar { position: sticky; top: 0; z-index: 5; margin: 0; padding: calc(10px + env(safe-area-inset-top, 0px)) 14px 10px; background: rgba(15,17,20,.85); -webkit-backdrop-filter: blur(6px); backdrop-filter: blur(6px); }
.ggi-lightbox.lb-pdf .ggi-lightbox-body { display: block; width: 100%; max-width: 100%; max-height: none; overflow: visible; background: #3a3d42; border-radius: 0; box-shadow: none; min-height: 0; }
.ggi-lightbox-loading { position: absolute; color: var(--tx2); font-size: 13px; font-family: 'Inter', sans-serif; }
@keyframes ggiLbFade { from { opacity: 0; } to { opacity: 1; } }
@keyframes ggiLbIn { from { opacity: 0; transform: scale(.96); } to { opacity: 1; transform: scale(1); } }
@media (prefers-reduced-motion: reduce) { .ggi-lightbox-backdrop, .ggi-lightbox-stage { animation: none; } }
@media (max-width: 600px) { .ggi-lightbox-frame { width: 96vw; height: 80vh; } .ggi-lightbox-title { max-width: 40vw; } .ggi-lightbox-btn { width: 44px; height: 44px; } }
</style>

<div id="ggi-lightbox" class="ggi-lightbox" role="dialog" aria-modal="true" aria-label="Vista previa de archivo" hidden>
  <div class="ggi-lightbox-backdrop" data-close></div>
  <div class="ggi-lightbox-stage">
    <div class="ggi-lightbox-toolbar">
      <span class="ggi-lightbox-title"></span>
      <div class="ggi-lightbox-actions">
        <button type="button" class="ggi-lightbox-btn" data-print aria-label="Imprimir" title="Imprimir">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
        </button>
        <a class="ggi-lightbox-btn" data-download href="#" download aria-label="Descargar archivo" title="Descargar">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        </a>
        <button type="button" class="ggi-lightbox-btn ggi-lightbox-close" data-close aria-label="Cerrar vista previa" title="Cerrar (Esc)">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>
    </div>
    <div class="ggi-lightbox-body">
      <img class="ggi-lightbox-img" alt="" hidden>
      <div class="ggi-lightbox-pdf" hidden></div>
      <iframe class="ggi-lightbox-frame" title="Vista previa de documento" hidden></iframe>
      <div class="ggi-lightbox-loading">Cargando…</div>
    </div>
  </div>
</div>

<script>
(function() {
  var lb, imgEl, frameEl, pdfEl, bodyEl, zoomBtn, titleEl, dlEl, loadEl, _lastFocus = null;
  var _curUrl = '', _curKind = '';   // para el botón imprimir (image | pdf | iframe)
  var PDFJS_SRC    = <?= json_encode(APP_URL . '/assets/vendor/pdfjs/pdf.min.js') ?>;
  var PDFJS_WORKER = <?= json_encode(APP_URL . '/assets/vendor/pdfjs/pdf.worker.min.js') ?>;
  var _pdfDoc = null, _pdfZoom = false, _pdfToken = 0, _pdfLibPromise = null;
  function refs() {
    lb = document.getElementById('ggi-lightbox');
    if (!lb) return false;
    imgEl   = lb.querySelector('.ggi-lightbox-img');
    frameEl = lb.querySelector('.ggi-lightbox-frame');
    pdfEl   = lb.querySelector('.ggi-lightbox-pdf');
    bodyEl  = lb.querySelector('.ggi-lightbox-body');
    zoomBtn = lb.querySelector('[data-zoom]');
    titleEl = lb.querySelector('.ggi-lightbox-title');
    dlEl    = lb.querySelector('[data-download]');
    loadEl  = lb.querySelector('.ggi-lightbox-loading');
    return true;
  }
  function loadPdfLib() {
    if (window.pdfjsLib) return Promise.resolve();
    if (_pdfLibPromise) return _pdfLibPromise;
    _pdfLibPromise = new Promise(function(res, rej) {
      var s = document.createElement('script');
      s.src = PDFJS_SRC;
      s.onload = function() { try { pdfjsLib.GlobalWorkerOptions.workerSrc = PDFJS_WORKER; } catch(e) {} res(); };
      s.onerror = rej;
      document.head.appendChild(s);
    });
    return _pdfLibPromise;
  }
  function _withTimeout(promise, ms) {
    return Promise.race([ promise, new Promise(function (r) { setTimeout(function () { r('__timeout'); }, ms); }) ]);
  }
  function _diag(t) { /* diagnóstico retirado tras validar en iOS */ }
  async function renderPdfPages(token) {
    if (!_pdfDoc) return;
    pdfEl.innerHTML = '';
    var n = _pdfDoc.numPages;
    var bodyW = (bodyEl.clientWidth || window.innerWidth || 800) - 16;
    var dpr = Math.min(window.devicePixelRatio || 1, 2);  // tope 2: un canvas enorme (dpr 3) cuelga en iOS
    var p1 = await _withTimeout(_pdfDoc.getPage(1), 6000);
    if (token !== _pdfToken) return;
    if (!p1 || p1 === '__timeout' || !p1.getViewport) return;
    var vp1   = p1.getViewport({ scale: 1 });
    var scale = bodyW / vp1.width;
    var vpBase = p1.getViewport({ scale: scale });
    // FASE 1: crear YA N páginas (envoltura + canvas) con dims de la pág.1 → aparecen todas
    // las hojas y se puede desplazar de inmediato, aunque el contenido tarde en pintar.
    var pages = [];
    for (var i = 0; i < n; i++) {
      var wrap = document.createElement('div');
      wrap.className = 'ggi-pdf-page';
      wrap.style.width  = vpBase.width + 'px';
      wrap.style.height = vpBase.height + 'px';
      var cv = document.createElement('canvas');
      cv.width  = Math.round(vpBase.width * dpr);
      cv.height = Math.round(vpBase.height * dpr);
      cv.style.width  = vpBase.width + 'px';
      cv.style.height = vpBase.height + 'px';
      wrap.appendChild(cv);
      pdfEl.appendChild(wrap);
      pages.push({ wrap: wrap, cv: cv });
    }
    loadEl.hidden = true;
    // FASE 2: pintar el canvas y montar la CAPA DE TEXTO (seleccionable/copiable) encima.
    for (var k = 1; k <= n; k++) {
      if (token !== _pdfToken) return;
      var page = (k === 1) ? p1 : await _withTimeout(_pdfDoc.getPage(k), 6000);
      if (token !== _pdfToken) return;
      if (!page || page === '__timeout' || !page.getViewport) continue;
      var vp = page.getViewport({ scale: scale });
      var pg = pages[k - 1];
      pg.wrap.style.width  = vp.width + 'px';
      pg.wrap.style.height = vp.height + 'px';
      pg.cv.width  = Math.round(vp.width * dpr);
      pg.cv.height = Math.round(vp.height * dpr);
      pg.cv.style.width  = vp.width + 'px';
      pg.cv.style.height = vp.height + 'px';
      var ctx = pg.cv.getContext('2d'); ctx.scale(dpr, dpr);
      // Pintar el canvas SIN await: su promesa puede colgar (bug pdf.js 3.11) pero el canvas se pinta.
      try { page.render({ canvasContext: ctx, viewport: vp }).promise.catch(function () {}); } catch (e) {}
      // CAPA DE TEXTO (seleccionable/copiable): getTextContent SÍ resuelve; renderTextLayer se dispara
      // sin await (su promesa también puede colgar, pero monta los spans igual).
      if (token !== _pdfToken) return;
      try {
        var tc = await _withTimeout(page.getTextContent(), 6000);
        if (tc && tc !== '__timeout' && tc.items && tc.items.length && pdfjsLib.renderTextLayer) {
          var tl = document.createElement('div');
          tl.className = 'textLayer';
          tl.style.width  = vp.width + 'px';
          tl.style.height = vp.height + 'px';
          tl.style.setProperty('--scale-factor', scale);
          pg.wrap.appendChild(tl);
          try { pdfjsLib.renderTextLayer({ textContentSource: tc, container: tl, viewport: vp, textDivs: [] }).promise.catch(function () {}); } catch (e) {}
        }
      } catch (e) {}
    }
  }
  async function renderPdfData(data, url, token) {
    pdfEl.hidden = false; pdfEl.innerHTML = '';
    bodyEl.classList.add('is-pdf'); lb.classList.add('lb-pdf');
    loadEl.hidden = false;
    _diag('v4 cargando pdf.js…');
    try {
      await loadPdfLib();
      if (token !== _pdfToken) return;
      _diag('v4 getDocument…');
      var doc = await _withTimeout(pdfjsLib.getDocument({ data: data }).promise, 12000);
      if (token !== _pdfToken) { try { doc.destroy(); } catch(e) {} return; }
      if (!doc || doc === '__timeout' || !doc.numPages) throw new Error('getDocument-timeout');
      _pdfDoc = doc;
      await renderPdfPages(token);
      loadEl.hidden = true;
    } catch (e) {
      // pdf.js falló → fallback a iframe (con la URL original)
      if (token !== _pdfToken) return;
      _diag('v4 pdf.js FALLO→iframe: ' + (e && e.message ? e.message : e));
      pdfEl.hidden = true; pdfEl.innerHTML = '';
      bodyEl.classList.remove('is-pdf'); lb.classList.remove('lb-pdf');
      loadEl.hidden = true;
      frameEl.hidden = false; frameEl.src = url;
    }
  }
  function open(url, opts) {
    opts = opts || {};
    if (!lb && !refs()) return;
    if (!url) return;
    _curUrl = url; _curKind = '';
    _lastFocus = document.activeElement;
    titleEl.textContent = opts.title || '';
    dlEl.setAttribute('href', opts.download || url);
    if (opts.filename) dlEl.setAttribute('download', opts.filename); else dlEl.setAttribute('download', '');
    // reset estado
    _pdfToken++; _pdfDoc = null; _pdfZoom = false;
    imgEl.hidden = true; imgEl.classList.remove('zoomed'); imgEl.removeAttribute('src');
    bodyEl.classList.remove('is-zoomed', 'is-pdf'); lb.classList.remove('lb-pdf');
    pdfEl.hidden = true; pdfEl.innerHTML = '';
    frameEl.hidden = true; frameEl.removeAttribute('src');
    if (zoomBtn) zoomBtn.hidden = true;   // se muestra si es imagen o PDF
    loadEl.hidden = false;
    lb.hidden = false;
    lockScroll();
    imgEl.alt = opts.title || 'Vista previa';
    _diag('v4 detectando…');
    // NO usar img.onload/onerror para detectar tipo: iOS Safari carga PDFs DENTRO de un <img>
    // (dispara onload y muestra solo la 1ª página). Obtenemos el content-type real por fetch
    // y a los PDF los pasamos a pdf.js DESDE LOS BYTES (así iOS no los trata como imagen).
    var token = _pdfToken;
    fetch(url, { credentials: 'same-origin' }).then(function (resp) {
      var ct = (resp.headers.get('content-type') || '').toLowerCase();
      return resp.blob().then(function (blob) { return { ct: ct || (blob.type || ''), blob: blob }; });
    }).then(function (res) {
      if (token !== _pdfToken) return;
      var ct = (res.ct || res.blob.type || '').toLowerCase();
      _diag('v4 tipo:' + (ct || '?'));
      if (ct.indexOf('image/') === 0) {
        _curKind = 'image';
        var ou = URL.createObjectURL(res.blob);
        imgEl.onload  = function () { loadEl.hidden = true; imgEl.hidden = false; };
        imgEl.onerror = function () { loadEl.hidden = true; _diag('v4 img ERROR'); };
        imgEl.src = ou;
      } else {
        _curKind = 'pdf';
        res.blob.arrayBuffer().then(function (buf) {
          if (token !== _pdfToken) return;
          renderPdfData(buf, url, token);
        });
      }
    }).catch(function (e) {
      if (token !== _pdfToken) return;
      _curKind = 'iframe';
      _diag('v4 fetch FALLO:' + (e && e.message ? e.message : e) + '→iframe');
      loadEl.hidden = true; frameEl.hidden = false; frameEl.src = url;
    });
    var closeBtn = lb.querySelector('.ggi-lightbox-close');
    if (closeBtn) closeBtn.focus();
  }
  function close() {
    if (!lb || lb.hidden) return;
    lb.hidden = true;
    _pdfToken++; _pdfDoc = null;
    imgEl.removeAttribute('src'); frameEl.removeAttribute('src');
    pdfEl.innerHTML = ''; pdfEl.hidden = true;
    bodyEl.classList.remove('is-pdf', 'is-zoomed'); lb.classList.remove('lb-pdf');
    unlockScroll();
    if (_lastFocus && _lastFocus.focus) _lastFocus.focus();
    _lastFocus = null;
  }
  // El scroll lo hace de forma nativa el propio overlay (.lb-pdf con overflow:auto +
  // -webkit-overflow-scrolling:touch), que es lo fiable en iOS. Solo bloqueamos el scroll
  // de la página de fondo mientras el modal está abierto, sin tocar overflow de <html>.
  function _blockBgScroll(e) {
    // permitir el gesto dentro del overlay (que es el scroller); bloquear fuera
    if (lb && lb.contains(e.target)) return;
    if (e.cancelable) e.preventDefault();
  }
  function lockScroll() {
    document.addEventListener('touchmove', _blockBgScroll, { passive: false });
  }
  function unlockScroll() {
    document.removeEventListener('touchmove', _blockBgScroll, { passive: false });
  }
  // Imprimir el archivo actual (imagen o PDF) mediante un iframe oculto.
  function printCurrent() {
    if (!_curUrl) return;
    var old = document.getElementById('ggi-print-frame');
    if (old) { try { old.parentNode.removeChild(old); } catch (_) {} }
    var f = document.createElement('iframe');
    f.id = 'ggi-print-frame';
    f.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden;';
    document.body.appendChild(f);
    var doPrint = function () {
      try { f.contentWindow.focus(); f.contentWindow.print(); }
      catch (_) { window.open(_curUrl, '_blank'); }  // fallback (iOS/PWA que bloquee print)
    };
    if (_curKind === 'image') {
      var doc = f.contentWindow.document;
      doc.open();
      doc.write('<!DOCTYPE html><html><head><meta charset="utf-8"><title>Imprimir</title>'
        + '<style>@page{margin:10mm}html,body{margin:0;height:100%}'
        + 'img{max-width:100%;max-height:100vh;display:block;margin:0 auto}</style></head>'
        + '<body><img src="' + _curUrl.replace(/"/g, '&quot;') + '"></body></html>');
      doc.close();
      var img = doc.querySelector('img');
      if (img) { img.onload = function () { setTimeout(doPrint, 60); }; }
      else setTimeout(doPrint, 300);
    } else {
      // PDF u otro documento: cargar el archivo directo en el iframe
      f.onload = function () { setTimeout(doPrint, 120); };
      f.src = _curUrl;
    }
  }

  window.ggiLightbox = { open: open, close: close, print: printCurrent };

  // Apertura por delegación: cualquier [data-ggi-preview] (link o botón)
  document.addEventListener('click', function(e) {
    var trg = e.target.closest('[data-ggi-preview]');
    if (trg) {
      // data-src (archivo crudo) tiene prioridad; href queda como fallback sin-JS
      var url = trg.getAttribute('data-src') || trg.getAttribute('href');
      if (url && url !== '#') {
        e.preventDefault();
        open(url, { title: trg.getAttribute('data-title') || trg.getAttribute('title') || '', download: trg.getAttribute('data-download-url') || url });
      }
      return;
    }
    if (!lb || lb.hidden) return;
    if (e.target.closest('[data-print]'))    { e.preventDefault(); printCurrent(); return; }
    if (e.target.closest('[data-download]')) return;      // dejar descargar nativo
    if (e.target.closest('[data-close]'))    { close(); return; }
  });

  // Teclado: Escape cierra; Tab atrapado dentro del diálogo
  document.addEventListener('keydown', function(e) {
    if (!lb || lb.hidden) return;
    if (e.key === 'Escape') { e.preventDefault(); close(); return; }
    if (e.key === 'Tab') {
      var f = lb.querySelectorAll('button, a[href], iframe');
      f = Array.prototype.filter.call(f, function(el){ return el.offsetParent !== null; });
      if (!f.length) return;
      var first = f[0], last = f[f.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }
  });
})();
</script>

<?php include __DIR__ . '/pwa-install.php'; ?>

<script>
/* ── Descargas iOS y Android PWA ────────────────────────────────────────────
 *
 * PROBLEMA DIAGNOSTICADO:
 *   En iOS WKWebView (standalone PWA y Safari móvil), el atributo `download`
 *   se ignora para URLs de servidor. El navegador navega a la URL, recibe
 *   Content-Disposition: attachment, muestra Quick Look, y al cerrar
 *   Quick Look el WebView queda en estado inconsistente (posible reset del
 *   contexto de cookies), provocando pérdida de sesión.
 *
 * SOLUCIÓN (iOS — PWA y Safari):
 *   Interceptar el clic. Descargar con fetch() same-origin (cookie siempre
 *   enviada, sin navegación del WebView). Compartir con Web Share API o
 *   blob URL. El WebView nunca abandona la página → sesión intacta.
 *
 * Android PWA:
 *   window.open(_blank) abre Chrome externo donde attachment funciona nativo.
 * ─────────────────────────────────────────────────────────────────────────── */
(function () {
  /* Detecta iOS aunque Safari tenga "Sitio web para ordenador" activo.
     En ese modo el UA reporta "Macintosh" pero maxTouchPoints sigue siendo > 1. */
  var isIos     = /iP(hone|ad|od)/i.test(navigator.userAgent)
                  || (/Mac/i.test(navigator.userAgent) && navigator.maxTouchPoints > 1);
  var isAndroid = !isIos && window.matchMedia
                  && window.matchMedia('(display-mode:standalone)').matches;

  /* ── Android PWA ── */
  if (isAndroid) {
    document.addEventListener('click', function (e) {
      var a = e.target.closest('a[href*="descargar=1"]');
      if (!a) return;
      e.preventDefault();
      window.open(a.href, '_blank');
    }, true);
    return;
  }

  /* ── iOS (PWA + Safari) — fetch + Web Share / blob URL ── */
  if (!isIos) return;

  var LOG_EP = '/modules/ajax_pwa_log.php';

  /* Inyectar animación de spinner una sola vez */
  if (!document.getElementById('_ggi_dl_css')) {
    var st = document.createElement('style');
    st.id  = '_ggi_dl_css';
    st.textContent = '@keyframes _ggiSpin{to{transform:rotate(360deg)}}';
    document.head.appendChild(st);
  }

  /* Registra en consola + pwa_debug.log (fire-and-forget) */
  function ggiLog(step, detail) {
    var msg = detail ? (step + ' | ' + detail) : step;
    console.log('[GGI-DL]', msg);
    try {
      var fd = new FormData();
      fd.append('msg', msg);
      fetch(LOG_EP, { method: 'POST', body: fd, credentials: 'same-origin' }).catch(function(){});
    } catch(ignore) {}
  }

  function SPIN(txt) {
    return '<svg width="13" height="13" viewBox="0 0 24 24" fill="none"'
      + ' stroke="currentColor" stroke-width="2.5" stroke-linecap="round"'
      + ' style="animation:_ggiSpin .7s linear infinite;vertical-align:-1px;margin-right:4px">'
      + '<path d="M12 2a10 10 0 0 1 10 10"/></svg>' + txt;
  }

  document.addEventListener('click', function (e) {
    var a = e.target.closest('a[href*="descargar=1"]');
    if (!a || a._ggiBusy) return;
    e.preventDefault();
    e.stopPropagation();

    a._ggiBusy            = true;
    var savedHTML         = a.innerHTML;
    var savedPointer      = a.style.pointerEvents;
    a.style.pointerEvents = 'none';
    var navigateAway      = false;

    /* ── PASO 1 ── */
    ggiLog('PASO_1_CLICK', 'url=' + a.href);
    a.innerHTML = SPIN('Descargando archivo…');

    /* ── PASO 2 ── */
    ggiLog('PASO_2_FETCH_INICIO', 'credentials=same-origin');

    fetch(a.href, { credentials: 'same-origin' })

      /* ── PASO 3 ── */
      .then(function (resp) {
        ggiLog('PASO_3_FETCH_RESPUESTA',
          'status=' + resp.status + ' ok=' + resp.ok + ' type=' + resp.type);

        if (!resp.ok) { ggiLog('PASO_3_ERROR', 'HTTP_' + resp.status); throw new Error('HTTP_' + resp.status); }

        var cd      = resp.headers.get('content-disposition') || '';
        var ct      = resp.headers.get('content-type')        || '';
        var fnMatch = cd.match(/filename="?([^";\r\n]+)"?/i);
        var fname   = fnMatch ? fnMatch[1].trim() : 'documento';
        ggiLog('PASO_3b_HEADERS', 'ct=' + ct + ' | cd=' + cd + ' | fname=' + fname);

        a.innerHTML = SPIN('Preparando archivo…');
        return resp.blob().then(function (blob) { return { blob: blob, fname: fname }; });
      })

      /* ── PASO 4 ── */
      .then(function (r) {
        ggiLog('PASO_4_BLOB', 'size=' + r.blob.size + ' | type=' + r.blob.type + ' | fname=' + r.fname);
        if (r.blob.size === 0) { ggiLog('PASO_4_ERROR', 'blob_vacio'); throw new Error('BLOB_VACIO'); }

        /* ── PASO 5 ── */
        var file = new File([r.blob], r.fname, { type: r.blob.type });
        ggiLog('PASO_5_FILE', 'name=' + file.name + ' size=' + file.size);

        /* ── PASO 6 ── */
        var shareOk = !!(navigator.canShare && navigator.canShare({ files: [file] }));
        ggiLog('PASO_6_CANSHARE',
          'share_fn=' + (typeof navigator.share) +
          ' | canShare_fn=' + (typeof navigator.canShare) +
          ' | result=' + shareOk);

        if (shareOk) {
          /* ── PASO 7 ── */
          ggiLog('PASO_7_SHARE_INICIO', 'title=' + r.fname);
          a.innerHTML = SPIN('Abriendo menú de compartir…');

          return navigator.share({ files: [file], title: r.fname })
            .then(function () {
              /* ── PASO 8 ── */
              ggiLog('PASO_8_SHARE_OK', 'completado');
            })
            .catch(function (shareErr) {
              /* ── PASO 9 ── */
              ggiLog('PASO_9_SHARE_ERROR', 'name=' + shareErr.name + ' | msg=' + shareErr.message);
              if (shareErr.name === 'AbortError') {
                ggiLog('PASO_9b_SHARE_CANCELADO', 'usuario_cerro_share_sheet');
                return;
              }
              ggiLog('PASO_9c_SHARE_FALLO_REAL', 'fallback_a_blob');
              _blobFallback(r.blob, r.fname);
            });
        }

        /* ── PASO 10 ── */
        ggiLog('PASO_10_BLOB_FALLBACK', 'canShare=false');
        _blobFallback(r.blob, r.fname);
      })

      .catch(function (err) {
        var msg = err && err.message ? err.message : String(err);
        if (msg === 'BLOB_VACIO') {
          a.innerHTML = SPIN('Error: archivo vacío');
          setTimeout(function () {
            a.innerHTML = savedHTML; a.style.pointerEvents = savedPointer; a._ggiBusy = false;
          }, 2500);
          return;
        }
        /* ── PASO 11 ── */
        ggiLog('PASO_11_FALLBACK_NAV', 'error=' + msg);
        navigateAway = true;
        a.innerHTML  = SPIN('Error al descargar…');
        setTimeout(function () { window.location.href = a.href; }, 900);
      })

      .finally(function () {
        if (!navigateAway) {
          a.innerHTML = savedHTML; a.style.pointerEvents = savedPointer; a._ggiBusy = false;
        }
      });

    function _blobFallback(blob, fname) {
      try {
        var blobUrl = URL.createObjectURL(blob);
        var tmp = document.createElement('a');
        tmp.href = blobUrl; tmp.download = fname;
        document.body.appendChild(tmp); tmp.click(); document.body.removeChild(tmp);
        ggiLog('PASO_10b_BLOB_CLICK', 'blobUrl_generado_y_clic_disparado');
        setTimeout(function () { URL.revokeObjectURL(blobUrl); }, 6000);
      } catch (blobErr) {
        ggiLog('PASO_10c_BLOB_ERROR', String(blobErr));
      }
    }
  }, true);
})();
</script>

<!-- Design System JS — Componentes core reutilizables -->
<script src="<?= APP_URL ?>/assets/js/components.js?v=<?= @filemtime(__DIR__ . '/../assets/js/components.js') ?>"></script>
<!-- Datepicker propio (flatpickr) — calendario moderno alineado al design system -->
<script src="<?= APP_URL ?>/assets/vendor/flatpickr/flatpickr.min.js"></script>
<script src="<?= APP_URL ?>/assets/vendor/flatpickr/es.js"></script>
<script>
(function () {
  // Inicializa flatpickr en los <input type="date"> aún sin inicializar (idempotente).
  // Mantiene el VALOR en formato Y-m-d (lo que espera el backend) y MUESTRA d/m/Y.
  window.ggiInitDatepickers = function (root) {
    if (!window.flatpickr) return;
    if (window.flatpickr.localize && window.flatpickr.l10ns && window.flatpickr.l10ns.es) {
      window.flatpickr.localize(window.flatpickr.l10ns.es);
    }
    var scope = root || document;
    scope.querySelectorAll('input[type="date"]:not([data-fp])').forEach(function (inp) {
      inp.setAttribute('data-fp', '1');
      try {
        flatpickr(inp, {
          dateFormat: 'Y-m-d',   // valor enviado (no cambia el backend)
          altInput:   true,
          altFormat:  'd/m/Y',   // lo que ve el usuario
          allowInput: true,
          disableMobile: false   // en móvil/PWA usa el picker nativo (mejor en táctil)
        });
      } catch (e) { inp.removeAttribute('data-fp'); }
    });
  };
  if (document.readyState !== 'loading') window.ggiInitDatepickers();
  else document.addEventListener('DOMContentLoaded', function () { window.ggiInitDatepickers(); });
})();
</script>
</body>
</html>
