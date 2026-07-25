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
    position: relative;
  }
  /* Punto de estado. En WhatsApp Business no existe "en línea": lo que sí
     se puede saber es si la ventana de 24 h sigue abierta, que es lo que
     de verdad determina si se puede escribir. */
  .ib-av::after {
    content: ''; position: absolute; right: -1px; bottom: -1px;
    width: 11px; height: 11px; border-radius: 50%;
    background: var(--tx3); border: 2px solid var(--bg-surface);
  }
  .ib-av.viva::after { background: var(--gr, #2DB140); }
  .ib-av.c0 { background: #6366F1 } .ib-av.c1 { background: #0EA5E9 }
  .ib-av.c2 { background: #10B981 } .ib-av.c3 { background: #F59E0B }
  .ib-av.c4 { background: #EC4899 } .ib-av.c5 { background: #8B5CF6 }
  .ib-av.c6 { background: #14B8A6 } .ib-av.c7 { background: #F43F5E }

  /* ── Columna 1 ── */
  .ib-head {
    padding: 10px 14px 8px; border-bottom: 1px solid var(--brd); flex-shrink: 0;
  }
  .ib-head-r { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 8px; }
  .ib-head-t { font-size: .88rem; font-weight: 700; color: var(--tx); }
  .ib-icon {
    width: 30px; height: 30px; border: none; background: transparent; border-radius: 8px;
    display: inline-flex; align-items: center; justify-content: center;
    color: var(--tx2); cursor: pointer;
  }
  .ib-icon:hover { background: var(--bg-hover); color: var(--tx); }
  .ib-icon svg { width: 17px; height: 17px; }

  /* Selector de bandeja: elegir qué número mirar cuando hay varios */
  .ib-bandeja { margin-bottom: 8px; }
  .ib-bandeja select {
    width: 100%; padding: 7px 10px; font: inherit; font-size: .8rem; font-weight: 600;
    border: 1px solid var(--brd); border-radius: 9px; background: var(--bg-input); color: var(--tx);
    cursor: pointer;
  }
  .ib-bandeja select:focus { outline: none; border-color: var(--ac); box-shadow: 0 0 0 3px var(--ac-soft); }

  .ib-pills { display: flex; gap: 5px; flex-wrap: wrap; }
  .ib-pill {
    font: inherit; font-size: .7rem; font-weight: 600; padding: 3px 9px;
    border-radius: 999px; border: 1px solid var(--brd); background: transparent;
    color: var(--tx2); cursor: pointer; transition: background .15s, color .15s, border-color .15s;
    line-height: 1.5;
  }
  .ib-pill:hover { background: var(--bg-hover); color: var(--tx); }
  .ib-pill.on { background: var(--ac); border-color: var(--ac); color: #fff; }
  .ib-pill b { font-weight: 700; opacity: .8; margin-left: 3px; }

  .ib-search { padding: 8px 14px 10px; border-bottom: 1px solid var(--brd); flex-shrink: 0;
               display: flex; gap: 6px; align-items: center; }
  .ib-search-in { position: relative; flex: 1; min-width: 0; }
  .ib-search input {
    width: 100%; padding: 8px 12px 8px 32px; font: inherit; font-size: .8rem;
    border: 1px solid var(--brd); border-radius: 9px;
    background: var(--bg-input); color: var(--tx);
  }
  .ib-search input:focus { outline: 2px solid var(--ac-soft); outline-offset: 0; border-color: var(--ac); }
  .ib-search-in svg { position: absolute; left: 10px; top: 50%; transform: translateY(-50%);
                      width: 15px; height: 15px; color: var(--tx3); pointer-events: none; }
  .ib-icon-brd { border: 1px solid var(--brd); border-radius: 9px; width: 34px; height: 34px; flex-shrink: 0; }
  .ib-icon-brd.on { background: var(--ac); border-color: var(--ac); color: #fff; }

  .ib-item {
    display: flex; gap: 11px; width: 100%; text-align: left; padding: 11px 16px;
    border: none; border-bottom: 1px solid var(--brd2); background: transparent;
    cursor: pointer; font: inherit; border-left: 3px solid transparent;
    transition: background .12s;
  }
  .ib-item:hover { background: var(--bg-hover); }
  .ib-item.sel { background: var(--ac-soft); border-left-color: var(--ac); }
  /* display:flex column es IMPRESCINDIBLE: sin él los <span> hijos quedan
     inline, el teléfono y el mensaje se pegan al costado del nombre y
     text-overflow no aplica, provocando scroll horizontal. */
  .ib-item-body { flex: 1; min-width: 0; display: flex; flex-direction: column; }
  .ib-item-r1 { display: flex; justify-content: space-between; align-items: baseline; gap: 8px; min-width: 0; }
  .ib-item-n  { font-size: .83rem; font-weight: 700; color: var(--tx); min-width: 0;
                overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .ib-item-t  { font-size: .68rem; color: var(--tx3); flex-shrink: 0; white-space: nowrap; }
  .ib-item-ph { display: block; font-size: .72rem; color: var(--tx3); margin-top: 1px;
                overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .ib-item-p  { display: block; font-size: .76rem; color: var(--tx2); margin-top: 3px;
                overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  /* El formato se ve en la vista previa, pero sin gritar: es una linea de
     resumen, no el mensaje. */
  .ib-item-p b { font-weight: 600; color: var(--tx); }
  .ib-item-p i { font-style: italic; }
  .ib-item-p s { text-decoration: line-through; }
  .ib-item-p code { font-family: inherit; }
  .ib-item-m  { display: flex; gap: 5px; align-items: center; margin-top: 6px; flex-wrap: wrap; }

  /* ── Etiquetas ── */
  .ib-etq { font-size: .62rem; font-weight: 700; padding: 2px 7px; border-radius: 999px;
            display: inline-flex; align-items: center; gap: 4px; white-space: nowrap; }
  .ib-etq::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
  .ib-etq.ok      { background: var(--s-ok-bg);      color: var(--s-ok-tx); }
  .ib-etq.info    { background: var(--s-info-bg);    color: var(--s-info-tx); }
  .ib-etq.warn    { background: var(--s-warn-bg);    color: var(--s-warn-tx); }
  .ib-etq.purple  { background: var(--s-purple-bg);  color: var(--s-purple-tx); }
  .ib-etq.neutral { background: var(--s-neutral-bg); color: var(--s-neutral-tx); }

  .ib-etq-quitar { background: none; border: none; padding: 0 0 0 2px; cursor: pointer;
                   color: inherit; font-size: .8rem; line-height: 1; opacity: .65; }
  .ib-etq-quitar:hover { opacity: 1; }

  /* Menú de etiquetas y de nueva conversación */
  .ib-pop { position: absolute; z-index: 50; background: var(--bg-surface); border: 1px solid var(--brd);
            border-radius: 11px; box-shadow: 0 8px 28px rgba(0,0,0,.13); padding: 6px; min-width: 232px; }
  .ib-pop-item { display: flex; align-items: center; gap: 8px; width: 100%; text-align: left;
                 padding: 7px 9px; border: none; background: transparent; border-radius: 7px;
                 cursor: pointer; font: inherit; font-size: .78rem; color: var(--tx); }
  .ib-pop-item:hover { background: var(--bg-hover); }
  .ib-pop-item small { display: block; color: var(--tx3); font-size: .7rem; }
  .ib-pop-t { font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .07em;
              color: var(--tx3); padding: 6px 9px 4px; }
  .ib-pop-item small { white-space: normal; line-height: 1.35; }

  /* Vista previa del adjunto */
  .ib-prev { display: flex; align-items: center; justify-content: center;
             background: var(--bg-surface2); border: 1px solid var(--brd);
             border-radius: 10px; padding: 12px; margin-bottom: 10px; min-height: 120px; }
  .ib-prev-img { max-width: 100%; max-height: 320px; border-radius: 8px; display: block; }
  .ib-prev-doc { font-size: .9rem; font-weight: 600; color: var(--tx); text-align: center; }
  .ib-prev-meta { font-size: .74rem; color: var(--tx2); margin-bottom: 12px; word-break: break-word; }
  .ib-prev-nota { font-size: .78rem; color: var(--tx2); margin-bottom: 8px; }

  /* ── Selector de plantillas ── */
  .ib-plt-busca { margin-bottom: 12px; }
  .ib-plt-busca input { width: 100%; padding: 10px 13px; font: inherit; font-size: .85rem;
                        border: 1px solid var(--brd); border-radius: 10px;
                        background: var(--bg-input); color: var(--tx); }
  .ib-plt-busca input:focus { outline: none; border-color: var(--ac); box-shadow: 0 0 0 3px var(--ac-soft); }

  /* display:block y márgenes explícitos, no flex: así el apilado no depende
     del contenedor donde se monte el listado. */
  .ib-plt-lista { max-height: 420px; overflow-y: auto; display: block; }

  /* Encabezado de categoría: separa Utilidad de Marketing y explica por qué
     importa la diferencia, en vez de solo etiquetar. */
  .ib-plt-grupo { display: block; padding: 14px 2px 6px; }
  .ib-plt-grupo b, .ib-plt-grupo span { display: inline; }
  .ib-plt-grupo:first-child { padding-top: 2px; }
  .ib-plt-grupo b { font-size: .72rem; font-weight: 700; text-transform: uppercase;
                    letter-spacing: .07em; color: var(--tx2); margin-right: 8px; }
  .ib-plt-grupo span { font-size: .72rem; color: var(--tx3); }
  .ib-plt-grupo.mk b { color: var(--s-warn-tx); }
  .ib-plt-grupo.mk span { color: var(--s-warn-tx); opacity: .85; }
  .ib-plt-card { display: block; width: 100%; text-align: left; padding: 12px 14px; cursor: pointer;
                 background: var(--bg-surface); border: 1px solid var(--brd); border-radius: 12px;
                 font: inherit; margin: 0 0 8px; float: none; vertical-align: top;
                 transition: border-color .13s, box-shadow .13s; }
  .ib-plt-card:hover { border-color: var(--ac); box-shadow: 0 2px 10px rgba(29,78,216,.10); }
  .ib-plt-card-h { display: flex; align-items: center; justify-content: space-between;
                   gap: 10px; margin-bottom: 8px; }
  .ib-plt-card-h b { font-size: .85rem; color: var(--tx); text-transform: capitalize; }
  .ib-plt-n { font-size: .66rem; font-weight: 700; color: var(--tx3);
              background: var(--bg-surface2); padding: 2px 8px; border-radius: 999px; white-space: nowrap; }

  /* Burbuja: se lee como lo verá el cliente, no como una ficha técnica */
  .ib-plt-burbuja { background: var(--ac); color: #fff; border-radius: 12px; border-bottom-right-radius: 4px;
                    padding: 9px 12px; font-size: .82rem; line-height: 1.5; white-space: normal; }
  .ib-plt-burbuja.grande { font-size: .85rem; padding: 12px 14px; }
  .ib-plt-burbuja b { font-weight: 700; }
  .ib-plt-burbuja i { font-style: italic; }
  .ib-plt-burbuja s { text-decoration: line-through; }
  .ib-var { background: rgba(255,255,255,.26); border-radius: 5px; padding: 0 5px;
            font-weight: 600; font-style: italic; }

  /* Campo precargado por el sistema: se distingue de lo escrito a mano */
  .ib-plt-f input.ib-auto { border-color: var(--s-ok); background: var(--s-ok-bg); }
  .ib-auto-aviso { font-size: .76rem; color: var(--s-ok-tx); background: var(--s-ok-bg);
                   padding: 8px 11px; border-radius: 8px; margin-bottom: 12px; line-height: 1.45; }

  .ib-plt-volver { background: none; border: none; padding: 0 0 12px; cursor: pointer;
                   font: inherit; font-size: .8rem; font-weight: 600; color: var(--ac); }
  .ib-plt-volver:hover { text-decoration: underline; }
  .ib-plt-prevlbl { font-size: .66rem; font-weight: 700; text-transform: uppercase; letter-spacing: .07em;
                    color: var(--tx3); margin-bottom: 6px; }
  .ib-plt-prevbox { margin-bottom: 16px; }
  .ib-vacio-p { padding: 22px 16px; text-align: center; color: var(--tx2); font-size: .84rem; line-height: 1.6; }

  /* Formulario de plantilla */
  .ib-plt-prev { background: var(--bg-surface2); border: 1px solid var(--brd); border-radius: 10px;
                 padding: 12px 14px; font-size: .84rem; line-height: 1.5; color: var(--tx);
                 margin-bottom: 14px; white-space: pre-wrap; }
  .ib-plt-f { display: block; margin-bottom: 10px; }
  .ib-plt-f span { display: block; font-size: .74rem; font-weight: 600; color: var(--tx2);
                   margin-bottom: 4px; text-transform: capitalize; }
  .ib-plt-f input { width: 100%; padding: 9px 12px; font: inherit; font-size: .85rem;
                    border: 1px solid var(--brd); border-radius: 9px;
                    background: var(--bg-input); color: var(--tx); }

  .ib-pop input { width: 100%; padding: 7px 10px; font: inherit; font-size: .8rem; margin-bottom: 4px;
                  border: 1px solid var(--brd); border-radius: 8px; background: var(--bg-input); color: var(--tx); }

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

  /* Fondo de conversación: patrón propio de trazos sutiles (no es el arte de
     WhatsApp). Se adapta a claro/oscuro cambiando el color del trazo. */
  .ib-msgs { padding: 18px; display: flex; flex-direction: column; gap: 8px;
    background-color: var(--bg-base);
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='96' height='96' viewBox='0 0 96 96'%3E%3Cg fill='none' stroke='%23334155' stroke-width='1.5' stroke-linecap='round' stroke-linejoin='round' opacity='0.05'%3E%3Ccircle cx='18' cy='20' r='6'/%3E%3Cpath d='M56 13v13M49 19h13'/%3E%3Cpath d='M8 62q6 -8 12 0t12 0'/%3E%3Crect x='62' y='55' width='12' height='12' rx='3'/%3E%3Cpath d='M40 80l5 -9 5 9z'/%3E%3Ccircle cx='78' cy='30' r='3'/%3E%3Cpath d='M22 44h12'/%3E%3Cpath d='M70 82h12'/%3E%3C/g%3E%3C/svg%3E");
    background-size: 96px 96px; }
  :root[data-tema="oscuro"] .ib-msgs {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='96' height='96' viewBox='0 0 96 96'%3E%3Cg fill='none' stroke='%23FFFFFF' stroke-width='1.5' stroke-linecap='round' stroke-linejoin='round' opacity='0.045'%3E%3Ccircle cx='18' cy='20' r='6'/%3E%3Cpath d='M56 13v13M49 19h13'/%3E%3Cpath d='M8 62q6 -8 12 0t12 0'/%3E%3Crect x='62' y='55' width='12' height='12' rx='3'/%3E%3Cpath d='M40 80l5 -9 5 9z'/%3E%3Ccircle cx='78' cy='30' r='3'/%3E%3Cpath d='M22 44h12'/%3E%3Cpath d='M70 82h12'/%3E%3C/g%3E%3C/svg%3E"); }
  .ib-sep { align-self: center; font-size: .68rem; font-weight: 600; color: var(--tx2);
            background: var(--bg-surface); border: 1px solid var(--brd);
            padding: 3px 12px; border-radius: 999px; margin: 10px 0 4px; }

  .ib-b { max-width: 72%; min-width: 74px; padding: 8px 12px 6px; border-radius: 14px;
          font-size: .84rem; line-height: 1.5; white-space: pre-wrap; word-break: break-word; }
  .ib-b.in  { align-self: flex-start; background: var(--bg-surface); color: var(--tx);
              border: 1px solid var(--brd); border-bottom-left-radius: 5px; }
  .ib-b.out { align-self: flex-end; background: var(--ac); color: #fff; border-bottom-right-radius: 5px; }
  /* Automático del sistema: se distingue del mensaje humano sin gritar */
  .ib-b.sis { align-self: flex-end; background: var(--s-neutral-bg); color: var(--tx);
              border: 1px dashed var(--brd); border-bottom-right-radius: 5px; }
  .ib-b-who { font-size: .68rem; font-weight: 700; margin-bottom: 3px; opacity: .9;
              white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100%; }
  .ib-b.out .ib-b-who { color: #fff; }
  /* El pie (hora + estado) nunca se parte: white-space nowrap + el ancho mínimo
     de la burbuja garantiza que quepa en una sola línea aun en mensajes cortos. */
  .ib-b-foot { font-size: .62rem; margin-top: 2px; display: flex; gap: 4px;
               align-items: center; justify-content: flex-end; opacity: .85;
               white-space: nowrap; }
  .ib-b.in .ib-b-foot { justify-content: flex-start; }
  .ib-b-foot .ib-tick { flex-shrink: 0; margin-left: -1px; }
  /* Doble check de leído en azul WhatsApp (sobre burbuja azul mantiene contraste) */
  .ib-tick-read { display: inline-flex; color: #6FD0F6; }
  .ib-tick-err  { display: inline-flex; color: #FCA5A5; }
  .ib-b-err { font-size: .7rem; margin-top: 5px; padding: 5px 8px; border-radius: 7px;
              background: var(--s-err-bg); color: var(--s-err-tx); white-space: normal; }

  /* Reenviado */
  .ib-fwd { font-size: .68rem; font-style: italic; color: inherit; opacity: .7; margin-bottom: 3px;
            display: flex; align-items: center; gap: 3px; }

  /* Cita: mensaje al que se responde, dentro de la burbuja */
  .ib-cita { font-size: .74rem; line-height: 1.35; padding: 5px 9px; margin-bottom: 5px;
             border-left: 3px solid currentColor; border-radius: 5px;
             background: rgba(127,127,127,.14); opacity: .85;
             overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

  /* Acciones al pasar sobre un mensaje: responder / reaccionar */
  .ib-msg-acts { display: flex; gap: 3px; opacity: 0; transition: opacity .12s; flex-shrink: 0; }
  .ib-b-wrap:hover .ib-msg-acts { opacity: 1; }
  .ib-msg-act { border: 1px solid var(--brd); background: var(--bg-surface); color: var(--tx2);
                width: 30px; height: 30px; border-radius: 50%; cursor: pointer; padding: 0;
                display: inline-flex; align-items: center; justify-content: center; }
  .ib-msg-act svg { width: 15px; height: 15px; }
  .ib-msg-act:hover { background: var(--bg-hover); color: var(--ac); border-color: var(--ac); }

  /* Barra "Respondiendo a…" sobre el compositor */
  .ib-resp { display: flex; align-items: center; gap: 10px; padding: 8px 12px; margin-bottom: 8px;
             background: var(--bg-surface2); border-left: 3px solid var(--ac); border-radius: 8px; }
  .ib-resp-txt { flex: 1; min-width: 0; font-size: .78rem; color: var(--tx2);
                 overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .ib-resp-txt b { display: block; font-size: .7rem; color: var(--ac); }
  .ib-resp-x { border: none; background: transparent; color: var(--tx2); font-size: 1rem;
               cursor: pointer; padding: 0 4px; }
  .ib-resp-x:hover { color: var(--s-err); }

  /* Micrófono en el compositor */
  .ib-mic { flex-shrink: 0; width: 40px; height: 40px; border-radius: 50%;
            border: 1px solid var(--brd); background: var(--bg-surface);
            color: var(--tx2); cursor: pointer; display: inline-flex;
            align-items: center; justify-content: center; }
  .ib-mic:hover { background: var(--bg-hover); color: var(--ac); border-color: var(--ac); }

  /* Barra de grabación de voz */
  .ib-voz-barra { display: flex; align-items: center; gap: 11px; padding: 8px 12px;
                  background: var(--bg-surface2); border: 1px solid var(--brd); border-radius: 12px; }
  .ib-voz-x { border: none; background: transparent; color: var(--s-err); font-size: 1.1rem;
              cursor: pointer; width: 32px; height: 32px; border-radius: 50%; flex-shrink: 0; }
  .ib-voz-x:hover { background: var(--s-err-bg); }
  .ib-voz-dot { width: 10px; height: 10px; border-radius: 50%; background: var(--s-err);
                animation: ib-pulso 1s ease-in-out infinite; flex-shrink: 0; }
  @keyframes ib-pulso { 0%,100% { opacity: 1; } 50% { opacity: .25; } }
  .ib-voz-t { font-variant-numeric: tabular-nums; font-weight: 700; color: var(--tx); font-size: .9rem; }
  .ib-voz-msg { flex: 1; font-size: .78rem; color: var(--tx2); }

  /* Zona de arrastre para adjuntar (estilo WhatsApp Web) */
  .ib-drop { border: 2px dashed var(--brd); border-radius: 14px; padding: 34px 20px;
             text-align: center; cursor: pointer; color: var(--tx3); transition: border-color .13s, background .13s; }
  .ib-drop:hover, .ib-drop:focus { border-color: var(--ac); background: var(--ac-soft); outline: none; }
  .ib-drop.sobre { border-color: var(--ac); background: var(--ac-soft); color: var(--ac); }
  .ib-drop-t { font-size: .92rem; font-weight: 700; color: var(--tx); margin: 12px 0 6px; }
  .ib-drop-s { font-size: .76rem; color: var(--tx2); line-height: 1.5; }

  /* Modal de mensaje interactivo (pestañas estilo Kapso) */
  .ib-int-tabs { display: flex; gap: 4px; margin-bottom: 16px; border-bottom: 1px solid var(--brd);
                 padding-bottom: 2px; overflow-x: auto; scrollbar-width: none; }
  .ib-int-tabs::-webkit-scrollbar { display: none; }
  .ib-int-tab { border: none; background: transparent; padding: 8px 12px; border-radius: 8px 8px 0 0;
                font: inherit; font-size: .82rem; font-weight: 600; color: var(--tx2); cursor: pointer;
                white-space: nowrap; border-bottom: 2px solid transparent; margin-bottom: -3px; }
  .ib-int-tab:hover { color: var(--tx); }
  .ib-int-tab.on { color: var(--ac); border-bottom-color: var(--ac); }
  .ib-int-in { width: 100%; padding: 8px 11px; font: inherit; font-size: .84rem; margin-bottom: 6px;
               border: 1px solid var(--brd); border-radius: 8px; background: var(--bg-input); color: var(--tx); }
  .ib-plt-hint { font-size: .68rem; color: var(--tx3); text-align: right; margin-top: 2px; }
  .ib-plt-f small { color: var(--tx3); font-weight: 400; }
  #it-especifico textarea, #it-body { width: 100%; padding: 9px 12px; font: inherit; font-size: .85rem;
    border: 1px solid var(--brd); border-radius: 9px; background: var(--bg-input); color: var(--tx); resize: vertical; }
  [data-btn], [data-opt] { width: 100%; padding: 8px 11px; font: inherit; font-size: .84rem;
    border: 1px solid var(--brd); border-radius: 8px; background: var(--bg-input); color: var(--tx); }

  /* Interactivo en el hilo: cuerpo + botones/opciones dibujados */
  .ib-int-body { margin-bottom: 6px; }
  .ib-int-opts { border-top: 1px solid rgba(255,255,255,.2); margin-top: 6px; padding-top: 4px; }
  .ib-b.out .ib-int-opt, .ib-b.sis .ib-int-opt { border-top: 1px solid rgba(255,255,255,.18); }
  .ib-int-opt { text-align: center; padding: 6px; font-size: .8rem; font-weight: 600;
                opacity: .92; border-top: 1px solid var(--brd); }
  .ib-int-opt:first-child { border-top: none; }

  /* Reacción del cliente: emoji flotante en la esquina de la burbuja.
     El wrap toma la alineación izq/der; dentro, la burbuja ya no se auto-alinea
     (si no, pelearía con el wrap). max-width se mueve al wrap. */
  .ib-b-wrap { position: relative; display: flex; align-items: center; gap: 4px; max-width: 76%; }
  .ib-b-wrap.in  { align-self: flex-start; }
  .ib-b-wrap.out { align-self: flex-end; flex-direction: row-reverse; }
  .ib-b-wrap .ib-b { position: relative; align-self: auto; max-width: 100%; }
  .ib-rx { position: absolute; bottom: -10px; right: 8px; font-size: .82rem;
           background: var(--bg-surface); border: 1px solid var(--brd);
           border-radius: 999px; padding: 0 3px; line-height: 1.5; }
  .ib-b-wrap.in .ib-rx { right: auto; left: 8px; }

  /* Mini selector de emoji para reaccionar */
  .ib-rx-pick { display: flex; gap: 2px; }
  .ib-rx-pick button { border: none; background: transparent; font-size: 1.25rem; cursor: pointer;
                       padding: 5px 6px; border-radius: 8px; line-height: 1; }
  .ib-rx-pick button:hover { background: var(--bg-hover); transform: scale(1.15); }

  /* Marcar como no leída: menú "•••" y punto azul */
  .ib-item-menu { margin-left: 6px; color: var(--tx3); cursor: pointer;
                  opacity: 0; transition: opacity .12s; display: inline-flex; align-items: center; }
  .ib-item:hover .ib-item-menu { opacity: 1; }
  .ib-item-menu:hover { color: var(--ac); }
  .ib-item.nuevo .ib-item-n { font-weight: 800; }
  /* Segunda fila de la conversación: preview + indicadores a la derecha */
  .ib-item-r2 { display: flex; align-items: center; justify-content: space-between; gap: 6px; margin-top: 2px; }
  .ib-item-r2 .ib-item-p { margin-top: 0; flex: 1; min-width: 0; }
  .ib-item-ind { display: inline-flex; align-items: center; gap: 5px; flex-shrink: 0; }
  /* Indicadores de fijada / silenciada, a la derecha como WhatsApp Web */
  .ib-item-pin  { display: inline-flex; color: var(--tx3); }
  .ib-item-mute { display: inline-flex; color: var(--tx3); }
  .ib-unread.punto { min-width: 10px; width: 10px; height: 10px; padding: 0; }

  /* Formato de WhatsApp renderizado dentro de la burbuja */
  .ib-b b { font-weight: 700; }
  .ib-b i { font-style: italic; }
  .ib-b s { text-decoration: line-through; }
  .ib-mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .92em;
             background: rgba(127,127,127,.16); padding: 1px 5px; border-radius: 5px; }

  /* Adjuntos en el hilo */
  .ib-adj { display: inline-flex; flex-direction: column; gap: 2px; font-weight: 600; }
  .ib-adj small { font-weight: 400; opacity: .75; font-size: .7rem; }
  .ib-img { display: block; max-width: 100%; max-height: 300px; border-radius: 9px;
            cursor: pointer; background: rgba(127,127,127,.12); }
  /* Los stickers van sin fondo y más chicos, como en WhatsApp */
  .ib-sticker { max-width: 150px; max-height: 150px; background: transparent; }
  .ib-doc { display: inline-flex; align-items: center; gap: 7px; padding: 8px 11px;
            border-radius: 9px; background: rgba(127,127,127,.14);
            color: inherit; text-decoration: none; font-weight: 600; font-size: .8rem; }
  .ib-doc:hover { text-decoration: underline; }
  .ib-pie-foto { margin-top: 6px; }

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

  .ib-hist-t { font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .07em;
              color: var(--tx3); margin: 16px 0 8px; }
  .ib-hist-i { font-size: .78rem; color: var(--tx2); padding: 6px 0; border-bottom: 1px solid var(--brd2); }
  .ib-hist-i b { color: var(--tx); }
  .ib-hist-i small { display: block; font-size: .68rem; color: var(--tx3); margin-top: 2px; }
  .ib-hist-nota { font-size: .76rem; color: var(--tx2); background: var(--s-warn-bg);
                  color: var(--s-warn-tx); padding: 9px 11px; border-radius: 8px; line-height: 1.45; }

  /* Aviso de "tomá la conversación": reemplaza a la caja deshabilitada, que
     no explicaba por qué no se podía escribir. */
  .ib-tomar { display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
              padding: 11px 14px; border-radius: 10px;
              background: var(--bg-surface2); border: 1px dashed var(--brd);
              font-size: .82rem; color: var(--tx2); }
  .ib-tomar b { color: var(--tx); }
  .ib-tomar button { margin-left: auto; }

  /* Campo editable: muestra el formato mientras se escribe */
  .ib-editable {
    flex: 1; min-width: 0; padding: 10px 13px; font: inherit; font-size: .85rem;
    line-height: 1.45; border: 1px solid var(--brd); border-radius: 10px;
    background: var(--bg-input); color: var(--tx);
    max-height: 132px; overflow-y: auto; outline: none;
    white-space: pre-wrap; word-break: break-word;
  }
  .ib-editable:focus { border-color: var(--ac); box-shadow: 0 0 0 3px var(--ac-soft); }
  .ib-editable:empty::before { content: attr(data-ph); color: var(--tx3); pointer-events: none; }
  .ib-editable[contenteditable="false"] { opacity: .55; cursor: not-allowed; }
  .ib-editable b { font-weight: 700; }
  .ib-editable i { font-style: italic; }
  .ib-editable s { text-decoration: line-through; }
  /* Marcadores (* _ ~) atenuados: siguen ahí para no descuadrar el cursor,
     pero se ven como una guía y no como parte del mensaje. */
  .ib-editable .ib-mk { opacity: .38; font-weight: 400; }

  /* ── Columna 3: contexto ── */
  .ib-ctx-head { padding: 13px 17px; border-bottom: 1px solid var(--brd);
                 font-size: .88rem; font-weight: 700; color: var(--tx); }
  .ib-ctx-s { padding: 15px 17px; border-bottom: 1px solid var(--brd); }

  /* Caja de estado, como en la referencia */
  .ib-estado { background: var(--bg-surface2); border: 1px solid var(--brd);
               border-radius: 10px; padding: 11px 13px; margin-bottom: 10px; }
  .ib-punto { display: inline-block; width: 8px; height: 8px; border-radius: 50%;
              background: var(--tx3); margin-right: 7px; vertical-align: middle; }
  .ib-punto.viva { background: var(--gr, #2DB140); }
  .ib-resolver { width: 100%; background: none; border: none; padding: 6px 0; cursor: pointer;
                 font: inherit; font-size: .8rem; font-weight: 600; color: var(--ac); text-align: left; }
  .ib-resolver:hover { text-decoration: underline; }
  .ib-ctx-t { font-size: .64rem; font-weight: 700; letter-spacing: .09em; text-transform: uppercase;
              color: var(--tx3); margin-bottom: 9px; display: flex; align-items: center; gap: 6px; }
  .ib-ctx-t svg { width: 13px; height: 13px; }
  .ib-ctx-v { font-size: .85rem; font-weight: 600; color: var(--tx); }
  .ib-ctx-s2 { font-size: .74rem; color: var(--tx3); margin-top: 2px; }
  /* Filas del panel: se comportan como elementos de lista, no como enlaces
     sueltos en azul. El azul se reserva para el hover, que es cuando importa. */
  .ib-ctx-a {
    display: flex; align-items: center; gap: 7px; width: calc(100% + 16px);
    margin: 0 -8px; padding: 7px 8px; border-radius: 8px;
    font-size: .81rem; color: var(--tx); text-align: left;
    background: transparent; border: none; cursor: pointer; font-family: inherit;
    transition: background .13s, color .13s;
  }
  .ib-ctx-a:hover { background: var(--bg-hover); color: var(--ac); }
  .ib-ctx-a + .ib-ctx-a { margin-top: 1px; }
  .ib-ctx-nom { flex: 1; min-width: 0; font-weight: 600;
                overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .ib-ctx-est { font-size: .68rem; color: var(--tx3); text-transform: capitalize; flex-shrink: 0; }
  .ib-ctx-ico { width: 12px; height: 12px; flex-shrink: 0; color: var(--tx3); opacity: 0; transition: opacity .13s; }
  .ib-ctx-a:hover .ib-ctx-ico { opacity: 1; color: var(--ac); }

  .ib-empty { padding: 56px 28px; text-align: center; color: var(--tx2); font-size: .85rem; }
  .ib-empty svg { width: 40px; height: 40px; color: var(--tx3); margin: 0 auto 12px; display: block; }

  /* Botón: reutiliza el canónico .btn del design system */
  .ib-btn-sm { font-size: .76rem; padding: 6px 13px; min-height: 34px; }

  /* Volver a la lista: solo en móvil, donde la lista queda oculta */
  .ib-volver { display: none; }

  /* Rescate: solo con la lista oculta Y sin hilo abierto */
  .ib-rescate { display: none; position: absolute; top: 10px; left: 10px; z-index: 3;
                border: 1px solid var(--brd); border-radius: 8px; background: var(--bg-surface); }
  .ib.sin-lista:not(.abierto) .ib-rescate { display: inline-flex; }
  .ib-chat-col { position: relative; }

  /* Barra de formato y emojis */
  .ib-barra { display: flex; align-items: center; gap: 2px; margin-bottom: 8px; flex-wrap: wrap; }
  .ib-fmt {
    min-width: 32px; height: 30px; padding: 0 7px; border: none; background: transparent;
    border-radius: 7px; cursor: pointer; color: var(--tx2); font: inherit; font-size: .85rem;
    display: inline-flex; align-items: center; justify-content: center;
  }
  .ib-fmt:hover:not(:disabled) { background: var(--bg-hover); color: var(--tx); }
  .ib-fmt:disabled { opacity: .4; cursor: not-allowed; }
  .ib-barra-sep { width: 1px; height: 18px; background: var(--brd); margin: 0 5px; }

  .ib-emojis { display: grid; grid-template-columns: repeat(8, 1fr); gap: 1px; }
  .ib-emojis button {
    border: none; background: transparent; font-size: 1.4rem; line-height: 1;
    padding: 4px; border-radius: 7px; cursor: pointer; min-height: 34px;
    display: inline-flex; align-items: center; justify-content: center;
  }
  .ib-emojis button:hover { background: var(--bg-hover); }

  /* Picker de emojis con categorías (estilo WhatsApp) */
  #ib-panel-emoji, #ib-panel-sticker { width: 328px; max-width: calc(100vw - 24px); }
  .ib-emo-buscar { width: 100%; padding: 7px 11px; margin-bottom: 6px; font: inherit;
    font-size: .8rem; border: 1px solid var(--brd); border-radius: 8px;
    background: var(--bg-input); color: var(--tx); }
  .ib-emo-scroll { max-height: 264px; overflow-y: auto; scroll-behavior: smooth; }
  .ib-emo-sec-h { position: sticky; top: 0; z-index: 1; background: var(--bg-surface);
    font-size: .62rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em;
    color: var(--tx3); padding: 5px 3px 3px; }
  .ib-emo-cats { display: flex; gap: 1px; padding: 6px 2px 0; margin-top: 6px;
    border-top: 1px solid var(--brd); overflow-x: auto; scrollbar-width: none; }
  .ib-emo-cats::-webkit-scrollbar { display: none; }
  .ib-emo-cats button { flex: 1 0 auto; border: none; background: transparent;
    font-size: 1.15rem; line-height: 1; padding: 6px 8px; border-radius: 7px; cursor: pointer;
    opacity: .6; transition: opacity .12s, background .12s; }
  .ib-emo-cats button:hover { opacity: 1; background: var(--bg-hover); }
  .ib-emo-cats button.on { opacity: 1; background: var(--ac-soft); }
  .ib-emo-vacio { padding: 22px 10px; text-align: center; color: var(--tx3); font-size: .78rem; }

  /* Pestañas del panel emojis / stickers */
  .ib-tabs { display: flex; gap: 4px; padding: 2px 2px 6px; border-bottom: 1px solid var(--brd); margin-bottom: 6px; }
  .ib-tab { flex: 1; border: none; background: transparent; padding: 6px 10px; border-radius: 7px;
            font: inherit; font-size: .76rem; font-weight: 600; color: var(--tx2); cursor: pointer; }
  .ib-tab:hover { background: var(--bg-hover); }
  .ib-tab.on { background: var(--ac-soft); color: var(--ac); }
  .ib-stk { grid-template-columns: repeat(4, 1fr); }
  .ib-stk-b { padding: 4px; min-height: 64px; }
  .ib-stk-b img { width: 100%; height: 56px; object-fit: contain; }

  /* Adjunto dentro del compositor */
  .ib-adj-chip { display: flex; align-items: center; gap: 10px; padding: 8px 10px; margin-bottom: 8px;
                 background: var(--bg-surface2); border: 1px solid var(--brd); border-radius: 10px; }
  .ib-adj-mini { width: 46px; height: 46px; border-radius: 8px; overflow: hidden; flex-shrink: 0;
                 display: flex; align-items: center; justify-content: center; background: var(--bg-base); }
  .ib-adj-mini img, .ib-adj-mini video { width: 100%; height: 100%; object-fit: cover; }
  .ib-adj-ico { font-size: 1.3rem; }
  .ib-adj-info { flex: 1; min-width: 0; display: flex; flex-direction: column; }
  .ib-adj-info b { font-size: .8rem; color: var(--tx); overflow: hidden;
                   text-overflow: ellipsis; white-space: nowrap; }
  .ib-adj-info small { font-size: .7rem; color: var(--tx2); }
  .ib-adj-x { border: none; background: transparent; font-size: 1.3rem; line-height: 1;
              color: var(--tx2); cursor: pointer; padding: 0 6px; border-radius: 6px; }
  .ib-adj-x:hover { background: var(--bg-hover); color: var(--s-err); }

  .ib-linkico { border: none; background: transparent; padding: 0; cursor: pointer;
                color: var(--ac); display: inline-flex; align-items: center; }

  /* Paneles colapsables.
     OJO: un hijo con display:none sale del flujo del grid y los siguientes se
     corren a las columnas anteriores. Por eso al ocultar un panel hay que
     redefinir las columnas SOLO para los que quedan; si no, el hilo hereda
     el ancho del panel oculto y el layout se rompe. */
  .ib.sin-lista { grid-template-columns: minmax(0, 1fr) 296px; }
  .ib.sin-lista .ib-lista-col { display: none; }
  .ib.sin-ctx   { grid-template-columns: 340px minmax(0, 1fr); }
  .ib.sin-ctx .ib-ctx { display: none; }
  .ib.sin-lista.sin-ctx { grid-template-columns: minmax(0, 1fr); }

  @media (max-width: 1180px) {
    .ib, .ib.sin-lista, .ib.sin-ctx { grid-template-columns: 300px minmax(0,1fr); }
    .ib-ctx { display: none; }
    .ib-solo-desk { display: none; }
  }

  @media (max-width: 768px) {
    /* En móvil NO se usa grid: cada panel se posiciona absoluto y ocupa toda
       la pantalla. El grid con filas implícitas dejaba el chat a media altura
       y mostraba la lista debajo ("pantalla dividida"). Con absolute solo hay
       un panel visible por vez, siempre a pantalla completa. */
    .ib, .ib.sin-lista, .ib.sin-ctx {
      display: block; position: relative;
      height: calc(100dvh - 50px - 24px); border-radius: 12px; overflow: hidden;
    }
    .ib-lista-col, .ib-chat-col {
      position: absolute; inset: 0; width: 100%; height: 100%;
      border-right: none; display: flex;
    }
    .ib-chat-col { display: none; }              /* oculto hasta abrir un hilo */
    .ib.abierto .ib-lista-col { display: none; }
    .ib.abierto .ib-chat-col  { display: flex; }
    .ib-ctx { display: none; }                    /* el contexto no va en móvil */
    .ib-volver { display: inline-flex; }
    /* sin-lista es un toggle de escritorio; en móvil solo debe remostrar la lista
       cuando NO hay un hilo abierto. Si no, la lista y el chat (ambos absolute
       inset:0) se pintan encima y el título del hilo se mezcla con el listado. */
    .ib.sin-lista:not(.abierto) .ib-lista-col { display: flex; }

    /* Cabecera de la lista más compacta */
    .ib-head { padding: 10px 12px 8px; }
    .ib-pills { gap: 4px; }
    .ib-pill { padding: 4px 10px; }

    /* Cabecera del hilo: los controles no deben apretujar el nombre */
    .ib-chat-head { padding: 9px 10px; gap: 7px; flex-wrap: nowrap; }
    .ib-chat-n { font-size: .9rem; }
    .ib-chat-head .ib-icon { width: 36px; height: 36px; flex-shrink: 0; }
    /* El botón de acción (Tomar/Soltar) se compacta para no romper la fila */
    .ib-chat-head .ib-btn-sm { padding: 6px 10px; font-size: .74rem; white-space: nowrap; flex-shrink: 0; }
    .ib-chat-head .ib-tag { flex-shrink: 0; }

    /* Mensajes */
    .ib-b-wrap { max-width: 86%; }
    .ib-msgs { padding: 14px 12px; }

    /* La barra de formato tiene 8 botones: en móvil se desliza en horizontal
       en vez de romper el layout o apilarse. */
    .ib-barra { flex-wrap: nowrap; overflow-x: auto; -webkit-overflow-scrolling: touch;
                scrollbar-width: none; padding-bottom: 2px; }
    .ib-barra::-webkit-scrollbar { display: none; }
    .ib-fmt { min-width: 40px; height: 40px; flex-shrink: 0; }

    /* 16px evita el zoom automático de iOS al enfocar */
    .ib-box input, .ib-search input, .ib-editable,
    #it-body, [data-btn], [data-opt], .ib-plt-f input, .ib-plt-f textarea { font-size: 16px; }
    .ib-box .btn, .ib-btn-sm { min-height: 42px; }

    .ib-emojis { grid-template-columns: repeat(6, 1fr); }
    .ib-emojis button { min-height: 44px; font-size: 1.4rem; }
    .ib-stk { grid-template-columns: repeat(3, 1fr); }
    .ib-pop { max-width: calc(100vw - 20px); }

    .ib-item { padding: 12px 14px; }
    .ib-item-menu { opacity: 1; }   /* sin hover en táctil: siempre visible */
    .ib-msg-acts { opacity: 1; }    /* responder/reaccionar, idem */

    .ib-foot { padding: 10px 12px calc(10px + var(--safe-bottom, 0px)); }

    /* Zona de arrastre: en móvil el drag no existe, es solo un botón grande */
    .ib-drop { padding: 26px 16px; }
  }

  /* Pantallas muy chicas: recuperar aún más espacio */
  @media (max-width: 420px) {
    .ib-pill b { display: none; }          /* ocultar los contadores */
    .ib-chat-ph { display: none; }         /* el teléfono bajo el nombre */
    .ib-b-wrap { max-width: 90%; }
  }

  /* El botón de la cabecera del chat para mostrar la lista solo aparece cuando
     la lista está OCULTA; si no, se duplicaba con el de la propia lista. */
  .ib-chat-head [data-panel="lista"] { display: none; }
  .ib.sin-lista .ib-chat-head [data-panel="lista"] { display: inline-flex; }

  /* Lista de personas para reasignar (en vez de un select básico) */
  .ib-ra-lista { display: flex; flex-direction: column; gap: 3px; max-height: 260px; overflow-y: auto;
                 border: 1px solid var(--brd); border-radius: 10px; padding: 5px; }
  .ib-ra-op { display: flex; align-items: center; gap: 10px; width: 100%; text-align: left;
              padding: 8px 10px; border: 1px solid transparent; border-radius: 8px; cursor: pointer;
              background: transparent; font: inherit; font-size: .85rem; color: var(--tx); }
  .ib-ra-op:hover { background: var(--bg-hover); }
  .ib-ra-op.sel { background: var(--ac-soft); border-color: var(--ac); color: var(--ac); font-weight: 600; }
  .ib-ra-op .ib-av { width: 30px; height: 30px; font-size: .7rem; flex-shrink: 0; }
  .ib-av.gris { background: var(--s-neutral); color: #fff; }

  /* Botón de llamar en la cabecera del chat */
  .ib-llamar { color: var(--s-ok); flex-shrink: 0; }
  .ib-llamar:hover { background: var(--s-ok-bg); }

  /* Burbuja de llamada en el hilo (estilo WhatsApp) */
  .ib-call { display: inline-flex; align-items: center; gap: 10px; }
  .ib-call svg { flex-shrink: 0; color: var(--s-ok); }
  .ib-call.perdida svg { color: var(--s-err); }
  .ib-call-txt { display: flex; flex-direction: column; line-height: 1.25; }
  .ib-call-txt b { font-weight: 600; font-size: .86rem; }
  .ib-call-txt small { font-size: .68rem; opacity: .7; }
  .ib-b.out .ib-call svg { color: #fff; }
  .ib-b.out .ib-call.perdida svg { color: #FCA5A5; }

  /* ── Softphone: panel flotante de llamada ── */
  .sf-pop {
    position: fixed; right: 20px; bottom: 20px; z-index: 10500;
    width: 280px; padding: 16px 18px; border-radius: 16px;
    background: var(--bg-surface); border: 1px solid var(--brd);
    box-shadow: 0 16px 48px rgba(0,0,0,.28); color: var(--tx);
    animation: sf-in .18s ease-out;
  }
  @keyframes sf-in { from { transform: translateY(10px); opacity: 0; } to { transform: none; opacity: 1; } }
  .sf-head { display: flex; align-items: center; gap: 7px; font-size: .72rem; font-weight: 700;
             text-transform: uppercase; letter-spacing: .06em; color: var(--tx3); }
  .sf-dot { width: 9px; height: 9px; border-radius: 50%; background: var(--s-warn); }
  .sf-dot.viva { background: var(--s-ok); animation: sf-pulse 1.2s infinite; }
  @keyframes sf-pulse { 0%,100% { opacity: 1; } 50% { opacity: .35; } }
  .sf-ring .sf-dot { animation: sf-pulse .9s infinite; }
  .sf-nombre { font-size: 1.02rem; font-weight: 700; margin-top: 10px; word-break: break-word; }
  .sf-sub { font-size: .78rem; color: var(--tx2); margin-top: 2px; font-variant-numeric: tabular-nums; }
  .sf-acts { display: flex; gap: 8px; margin-top: 14px; }
  .sf-btn { flex: 1; border: none; border-radius: 10px; padding: 10px; font: inherit;
            font-size: .82rem; font-weight: 700; cursor: pointer; color: #fff; }
  .sf-contestar { background: var(--s-ok); }
  .sf-rechazar, .sf-colgar { background: var(--s-err); }
  .sf-btn:disabled { opacity: .6; cursor: default; }
  @media (max-width: 768px) { .sf-pop { right: 10px; left: 10px; bottom: 10px; width: auto; } }
</style>

<div class="ib" id="ib">
  <!-- ══ Lista ══ -->
  <div class="ib-col ib-lista-col">
    <div class="ib-head">
      <div class="ib-head-r">
        <button type="button" class="ib-icon" data-panel="lista" title="Ocultar lista de conversaciones" aria-label="Ocultar lista de conversaciones">
          <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16"/></svg>
        </button>
        <span class="ib-head-t" style="flex:1">Conversaciones</span>
        <button type="button" class="ib-icon" id="ib-nueva" title="Nueva conversación" aria-label="Nueva conversación">
          <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 10h8M8 14h5M21 12a8 8 0 01-8 8H7l-4 3V12a8 8 0 018-8h2a8 8 0 018 8z"/></svg>
        </button>
      </div>
      <!-- Selector de bandeja (número): solo aparece si hay más de uno -->
      <div class="ib-bandeja" id="ib-bandeja" style="display:none">
        <select id="ib-bandeja-sel" aria-label="Elegir bandeja"></select>
      </div>
      <div class="ib-pills">
        <button type="button" class="ib-pill on" data-f="todas">Todas<b data-c="todas"></b></button>
        <button type="button" class="ib-pill" data-f="mias">A mi cargo<b data-c="mias"></b></button>
        <button type="button" class="ib-pill" data-f="sin_asignar">Sin asignar<b data-c="sin_asignar"></b></button>
        <button type="button" class="ib-pill" data-f="sin_persona">Sin persona<b data-c="sin_persona"></b></button>
        <button type="button" class="ib-pill" data-f="archivadas">Archivados</button>
        <button type="button" class="ib-pill" id="ib-etq-activa" style="display:none"></button>
      </div>
    </div>
    <div class="ib-search">
      <span class="ib-search-in">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/>
        </svg>
        <input type="search" id="ib-q" placeholder="Buscar conversación…" aria-label="Buscar conversación">
      </span>
      <button type="button" class="ib-icon ib-icon-brd" id="ib-filtro-etq" title="Filtrar por etiqueta" aria-label="Filtrar por etiqueta">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M3 6l0 5.2a2 2 0 00.6 1.4l7.8 7.8a2 2 0 002.8 0l5.2-5.2a2 2 0 000-2.8l-7.8-7.8A2 2 0 0010.2 4L5 4a2 2 0 00-2 2z"/></svg>
      </button>
    </div>
    <div class="ib-scroll" id="ib-lista"></div>
  </div>

  <!-- ══ Hilo ══ -->
  <div class="ib-col ib-chat-col">
    <!-- Rescate: visible solo si la lista está oculta y no hay hilo abierto,
         donde no existe ningún otro control para traerla de vuelta. -->
    <button type="button" class="ib-icon ib-rescate" data-panel="lista"
            title="Mostrar la lista de conversaciones" aria-label="Mostrar la lista de conversaciones">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16"/></svg>
    </button>
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

<!-- Grabador de nota de voz: opus-recorder graba OGG/Opus nativo en Chrome,
     Firefox y Safari (encoder WASM), único formato que WhatsApp entrega como voz -->
<script src="<?= APP_URL ?>/assets/js/recorder.min.js?v=<?= @filemtime(__DIR__ . '/../../assets/js/recorder.min.js') ?>"></script>
<!-- Catálogo de emojis por categorías para el picker -->
<script src="<?= APP_URL ?>/assets/js/emojis.js?v=<?= @filemtime(__DIR__ . '/../../assets/js/emojis.js') ?>"></script>
<!-- Softphone: recibir llamadas de WhatsApp (Calling API) -->
<script src="<?= APP_URL ?>/assets/js/softphone.js?v=<?= @filemtime(__DIR__ . '/../../assets/js/softphone.js') ?>"></script>
<script>
(function () {
  var st = { f: 'todas', q: '', sel: null, etqs: [], etqActuales: [], firma: null, adjunto: null, bandeja: '', responder: null, abrirSeq: 0, listaSeq: 0 };
  var ES_JEFE = <?= json_encode(in_array($_SESSION['usuario']['rol'] ?? '', ['gerente_general','gerente_operaciones'], true)) ?>;
  var $ = function (s) { return document.querySelector(s); };

  // Iconos SVG (nunca emoji como icono de UI). Trazo, heredan currentColor.
  var _ICO = {
    reply:   '<path d="M9 17l-5-5 5-5M4 12h11a5 5 0 015 5v1"/>',
    forward: '<path d="M15 17l5-5-5-5M20 12H9a5 5 0 00-5 5v1"/>',
    smile:   '<circle cx="12" cy="12" r="9"/><path d="M8 14s1.5 2 4 2 4-2 4-2M9 9h.01M15 9h.01"/>',
    emoji:   '<circle cx="12" cy="12" r="9"/><path d="M8 14s1.5 2 4 2 4-2 4-2M9 9h.01M15 9h.01"/>',
    clip:    '<path d="M21.44 11.05l-9.19 9.19a5 5 0 01-7.07-7.07l9.19-9.19a3.5 3.5 0 014.95 4.95l-9.2 9.19a2 2 0 01-2.83-2.83l8.49-8.48"/>',
    x:       '<path d="M18 6L6 18M6 6l12 12"/>',
    send:    '<path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/>',
    mic:     '<rect x="9" y="2" width="6" height="12" rx="3"/><path d="M5 10a7 7 0 0014 0M12 17v4"/>',
    chat:    '<path d="M21 11.5a8.38 8.38 0 01-9 8.5 8.4 8.4 0 01-4-1L3 21l1-4a8.4 8.4 0 01-1-4 8.38 8.38 0 018.5-9 8.38 8.38 0 019 8.5z"/>',
    list:    '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
    link:    '<path d="M10 13a5 5 0 007.5.5l3-3a5 5 0 00-7-7l-1.5 1.5M14 11a5 5 0 00-7.5-.5l-3 3a5 5 0 007 7L12 19"/>',
    person:  '<path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2M12 11a4 4 0 100-8 4 4 0 000 8z"/>',
    doc:     '<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path d="M14 2v6h6"/>',
    image:   '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/>',
    video:   '<rect x="2" y="6" width="14" height="12" rx="2"/><path d="M22 8l-6 4 6 4V8z"/>',
    audio:   '<rect x="9" y="2" width="6" height="12" rx="3"/><path d="M5 10a7 7 0 0014 0M12 17v4"/>',
    sticker: '<path d="M15.5 3H5a2 2 0 00-2 2v14a2 2 0 002 2h9l7-7V5a2 2 0 00-2-2z"/><path d="M14 21v-5a2 2 0 012-2h5"/>',
    pin:     '<path d="M9 4h6l-1 6 3 3v2H7v-2l3-3-1-6zM12 15v5"/>',
    archive: '<rect x="3" y="4" width="18" height="4" rx="1"/><path d="M5 8v11a1 1 0 001 1h12a1 1 0 001-1V8M10 12h4"/>',
    bell:    '<path d="M18 8a6 6 0 00-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 01-3.4 0"/>',
    bell_off:'<path d="M13.7 21a2 2 0 01-3.4 0M18 8a6 6 0 00-9.3-5M6 8c0 7-3 9-3 9h13M3 3l18 18"/>',
    unread:  '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>'
  };
  function svg(nombre, size) {
    size = size || 15;
    return '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none" '
      + 'stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" '
      + 'style="vertical-align:middle;flex-shrink:0">' + _ICO[nombre] + '</svg>';
  }

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
  // Formato de WhatsApp → HTML. Se aplica SOBRE texto ya escapado, así que
  // no puede inyectar marcado: los < > del usuario ya son entidades.
  function formato(txt) { return _fmtHtml(esc(txt)); }

  /** Aplica el formato de WhatsApp sobre texto YA escapado. */
  function _fmtHtml(h) {
    return h
      .replace(/```([\s\S]+?)```/g, '<code class="ib-mono">$1</code>')
      .replace(/(^|[\s(])\*(\S(?:[^*\n]*\S)?)\*(?=[\s.,;:!?)]|$)/g, '$1<b>$2</b>')
      .replace(/(^|[\s(])_(\S(?:[^_\n]*\S)?)_(?=[\s.,;:!?)]|$)/g, '$1<i>$2</i>')
      .replace(/(^|[\s(])~(\S(?:[^~\n]*\S)?)~(?=[\s.,;:!?)]|$)/g, '$1<s>$2</s>');
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
  // Estado de entrega con el mismo lenguaje visual que WhatsApp:
  //  ✓ enviado · ✓✓ entregado · ✓✓ azul leído · ⚠ no entregado. Dibujado en SVG
  //  (íconos "done"/"done_all") para que se vea nítido y no como texto.
  function ticks(e) {
    var DOBLE  = 'M18 7l-1.41-1.41-6.34 6.34 1.41 1.41L18 7zm4.24-1.41L11.66 16.17 '
               + '7.48 12l-1.41 1.41L11.66 19l12-12-1.42-1.41zM.41 13.41L6 19l1.41-1.41L1.83 12 .41 13.41z';
    var SIMPLE = 'M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z';
    function svgTick(d) {
      return '<svg class="ib-tick" viewBox="0 0 24 24" width="15" height="15" '
           + 'fill="currentColor" aria-hidden="true"><path d="' + d + '"/></svg>';
    }
    if (e === 'read')      return '<span class="ib-tick-read" title="Leído">' + svgTick(DOBLE) + '</span>';
    if (e === 'delivered') return svgTick(DOBLE);
    if (e === 'sent')      return svgTick(SIMPLE);
    if (e === 'failed')    return '<span class="ib-tick-err" title="No entregado">'
                                + '<svg class="ib-tick" viewBox="0 0 24 24" width="14" height="14" '
                                + 'fill="currentColor" aria-hidden="true"><path d="M12 2L1 21h22L12 2zm1 '
                                + '15h-2v-2h2v2zm0-4h-2V9h2v4z"/></svg></span>';
    return '';
  }

  // ── Lista ──
  function lista(mantener) {
    // Token anti-carrera: si se cambia de bandeja/filtro (o corre el auto-refresco)
    // antes de que responda, la respuesta vieja se descarta en vez de pintar
    // conversaciones de otro número encima (era el "parpadeo" con datos ajenos).
    var token = ++st.listaSeq;
    fetch('ajax.php?accion=lista&filtro=' + encodeURIComponent(st.f)
          + '&q=' + encodeURIComponent(st.q)
          + '&etiqueta=' + (st.etqs || []).join(',')
          + '&bandeja=' + encodeURIComponent(st.bandeja || ''),
          { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (token !== st.listaSeq) return;   // respuesta vieja → descartar
        if (!j.ok) return;
        ['todas','mias','sin_asignar'].forEach(function (k) {
          var e = document.querySelector('[data-c="' + k + '"]');
          if (e) e.textContent = j.conteos[k] ? ' ' + j.conteos[k] : '';
        });

        pintarBandejas(j.bandejas || []);

        var c = $('#ib-lista');
        if (!j.items.length) { c.innerHTML = '<div class="ib-empty">Sin conversaciones</div>'; return; }

        c.innerHTML = j.items.map(function (it) {
          var tag = it.asignado_a
            ? (it.es_mia ? '<span class="ib-tag mia">A mi cargo</span>'
                         : '<span class="ib-tag otro">' + esc(it.asignado_nombre || 'Asignada') + '</span>')
            : '<span class="ib-tag libre">Libre</span>';
          var viejo = (it.dias_asignada !== null && it.dias_asignada >= 3)
            ? '<span class="ib-tag viejo">' + it.dias_asignada + ' d sin mover</span>' : '';
          var etqs = (it.etiquetas || []).map(function (e) {
            return '<span class="ib-etq ' + esc(e.color || 'neutral') + '">' + esc(e.nombre) + '</span>';
          }).join('');

          // "No leída" manual O mensajes nuevos → punto azul en la fila
          var pendiente = it.no_leidos || it.sin_leer;

          var pin  = it.fijada ? '<span class="ib-item-pin" title="Fijada">' + svg('pin', 13) + '</span>' : '';
          var mute = it.silenciada ? '<span class="ib-item-mute" title="Silenciada">' + svg('bell_off', 13) + '</span>' : '';

          return '<button type="button" class="ib-item' + (st.sel === it.id ? ' sel' : '')
            +     (pendiente ? ' nuevo' : '') + '" data-id="' + it.id + '">'
            + '<span class="ib-av ' + colorDe(it.telefono) + (it.ventana_abierta ? ' viva' : '') + '">'
            +   esc(ini(it.titulo)) + '</span>'
            + '<span class="ib-item-body">'
            +   '<span class="ib-item-r1"><span class="ib-item-n">' + esc(it.titulo) + '</span>'
            +   '<span class="ib-item-t">' + relativo(it.cuando)
            +     '<span class="ib-item-menu" data-menu="' + it.id + '" data-fij="' + (it.fijada ? 1 : 0)
            +       '" data-sil="' + (it.silenciada ? 1 : 0) + '" data-arch="' + (it.archivada ? 1 : 0)
            +       '" title="Opciones" aria-label="Opciones de la conversación">'
            +       '<svg viewBox="0 0 24 24" width="15" height="15" fill="currentColor" aria-hidden="true"><circle cx="12" cy="5" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="12" cy="19" r="2"/></svg>'
            +     '</span>'
            +   '</span></span>'
            +   '<span class="ib-item-ph">+' + esc(it.telefono) + '</span>'
            // Segunda fila: preview a la izquierda y, a la derecha, los mismos
            // indicadores que WhatsApp Web (silenciada, fijada y no leídos).
            +   '<span class="ib-item-r2">'
            // formato(), no esc(): en la lista se guardaba el texto crudo y se
            // veían los marcadores (*hola*) en vez del texto en negrita.
            +     '<span class="ib-item-p">' + formato(it.preview) + '</span>'
            +     '<span class="ib-item-ind">' + mute + pin
            +       (it.no_leidos ? '<span class="ib-unread">' + it.no_leidos + '</span>'
                    : (it.sin_leer ? '<span class="ib-unread punto"></span>' : ''))
            +     '</span>'
            +   '</span>'
            +   (tag + viejo + etqs ? '<span class="ib-item-m">' + tag + viejo + etqs + '</span>' : '')
            + '</span></button>';
        }).join('');
      }).catch(function () {});
  }

  // Selector de bandeja: solo tiene sentido con mas de un numero
  function pintarBandejas(bandejas) {
    var wrap = $('#ib-bandeja'), sel = $('#ib-bandeja-sel');
    if (!wrap || !sel) return;

    // Con una sola bandeja no se muestra: no hay nada que elegir
    if (bandejas.length < 2) { wrap.style.display = 'none'; return; }

    // No repintar si no cambio (perderia la seleccion abierta)
    var firma = bandejas.map(function (b) { return b.phone_number_id + ':' + b.n; }).join('|');
    if (sel.dataset.firma === firma) return;
    sel.dataset.firma = firma;

    var total = bandejas.reduce(function (a, b) { return a + parseInt(b.n, 10); }, 0);
    sel.innerHTML = '<option value="">Todas las bandejas (' + total + ')</option>'
      + bandejas.map(function (b) {
          return '<option value="' + esc(b.phone_number_id) + '"' + (st.bandeja === b.phone_number_id ? ' selected' : '') + '>'
               + esc(b.etiqueta) + ' (' + b.n + ')</option>';
        }).join('');
    wrap.style.display = '';
  }

  var _selB = document.getElementById('ib-bandeja-sel');
  if (_selB) _selB.addEventListener('change', function () {
    st.bandeja = _selB.value;
    st.firma = null;
    lista();
  });

  // ── Hilo ──
  // silencioso = refresco automático: no debe pisar lo que el ejecutivo está
  // escribiendo, ni robarle el foco, ni saltarle el scroll si está leyendo
  // mensajes anteriores.
  function abrir(id, silencioso) {
    st.sel = id;
    $('#ib').classList.add('abierto');

    // Token de apertura: si se abre otra conversación (o el auto-refresco dispara
    // otra carga) antes de que esta responda, la respuesta vieja se descarta en
    // vez de pintarse encima de la nueva. Eso era lo que dejaba el hilo "pegado".
    var token = ++st.abrirSeq;

    var previo = null;
    if (silencioso) {
      var inp = $('#ib-texto'), cont = $('#ib-msgs');
      previo = {
        texto: inp ? textoActual() : null,
        foco:  inp ? (document.activeElement === inp) : false,
        pos:   inp ? _offsets(inp).b : 0,
        abajo: cont ? (cont.scrollHeight - cont.scrollTop - cont.clientHeight < 60) : true,
        scroll: cont ? cont.scrollTop : 0
      };
    } else {
      // Feedback inmediato al hacer clic: marcar la fila y avisar que carga, para
      // que nunca se sienta "congelado" mientras llega la respuesta.
      document.querySelectorAll('.ib-item.sel').forEach(function (el) { el.classList.remove('sel'); });
      var fila = document.querySelector('.ib-item[data-id="' + id + '"]');
      if (fila) fila.classList.add('sel');
      var chatEl = $('#ib-chat');
      if (chatEl) chatEl.innerHTML = '<div class="ib-empty">Cargando conversación…</div>';
    }

    fetch('ajax.php?accion=hilo&id=' + id, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (token !== st.abrirSeq) return;   // respuesta vieja → descartar
        if (!j.ok) {
          if (!silencioso && $('#ib-chat'))
            $('#ib-chat').innerHTML = '<div class="ib-empty">No se pudo abrir la conversación.</div>';
          return;
        }

        // Firma del hilo: si nada cambió, NO se repinta. Repintar cada 8 s
        // aunque no hubiera novedades era lo que hacía saltar la conversación.
        var firma = j.mensajes.length + '|'
                  + j.mensajes.map(function (m) { return m.id + ':' + (m.estado || ''); }).join(',')
                  + '|' + (j.conv.asignado_a || '') + '|' + (j.conv.ventana_abierta ? 1 : 0)
                  + '|' + (j.etiquetas || []).map(function (e) { return e.id; }).join(',');

        if (silencioso && firma === st.firma) return;
        st.firma = firma;

        st.etqActuales = j.etiquetas || [];
        chat(j, previo); ctx(j);
        if (!silencioso) lista(true);
      })
      .catch(function () {
        if (token === st.abrirSeq && !silencioso && $('#ib-chat'))
          $('#ib-chat').innerHTML = '<div class="ib-empty">No se pudo abrir. Revisa tu conexión e intenta de nuevo.</div>';
      });
  }

  function chat(j, previo) {
    var c = j.conv, ultDia = '', cuerpo = '';

    // Reaccionar y responder solo si es mía y la ventana está abierta
    var puedeEscribir = c.es_mia && c.ventana_abierta;

    j.mensajes.forEach(function (m) {
      var dia = fechaLarga(m.cuando);
      if (dia !== ultDia) { cuerpo += '<div class="ib-sep">' + esc(dia) + '</div>'; ultDia = dia; }

      var cls = m.dir === 'in' ? 'in' : (m.autor ? 'out' : 'sis');
      var whoTxt = m.autor || 'Sistema · automático';
      var who = m.dir === 'out'
        ? '<div class="ib-b-who" title="' + esc(whoTxt) + '">' + esc(whoTxt) + '</div>' : '';
      var err = (m.estado === 'failed' && m.error)
        ? '<div class="ib-b-err">' + esc(m.error) + '</div>' : '';

      // Adjuntos entrantes (imagen, sticker, gif, audio, documento): todavía
      // no se descarga el archivo, así que se indica el tipo en vez de dejar
      // la burbuja vacía y que parezca un mensaje perdido.
      var cuerpoMsg = '';

      // ── Llamada de voz: burbuja con estado y duración, como WhatsApp ──
      var esLlamada = (m.tipo === 'call');
      if (esLlamada) {
        var tk = (m.texto || 'call:ringing').split(':');
        var eLl = tk[1] || 'ringing';                 // answered|missed|rejected|failed|ringing
        var durLl = parseInt(tk[2] || '0', 10);
        var saliente = (m.dir === 'out');
        var txtLl, clsLl = '';
        if (eLl === 'answered')      { txtLl = 'Llamada de voz'; }
        else if (eLl === 'rejected') { txtLl = 'Llamada rechazada'; clsLl = 'perdida'; }
        else if (eLl === 'failed')   { txtLl = 'Llamada fallida';   clsLl = 'perdida'; }
        else if (eLl === 'missed')   { txtLl = saliente ? 'Sin respuesta' : 'Llamada perdida'; clsLl = 'perdida'; }
        else                         { txtLl = 'Llamada de voz…'; }
        var durTxt = (eLl === 'answered' && durLl > 0)
          ? ' · ' + Math.floor(durLl / 60) + ':' + ('0' + (durLl % 60)).slice(-2) : '';
        // Flecha entrante (baja-izq) o saliente (sube-der) sobre el ícono
        var flecha = saliente ? 'M7 17h10V7' : 'M17 7H7v10';
        cuerpoMsg = '<span class="ib-call ' + clsLl + '">'
          + '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
          + '<path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6A19.79 19.79 0 012.12 4.18 2 2 0 014.11 2h3a2 2 0 012 1.72c.13.96.36 1.9.7 2.81a2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0122 16.92z"/></svg>'
          + '<span class="ib-call-txt"><b>' + txtLl + '</b><small>' + (saliente ? 'Saliente' : 'Entrante') + durTxt + '</small></span>'
          + '</span>';
      }

      // Adjuntos: el archivo se descarga de Meta la primera vez que se abre
      if (esLlamada) {
        /* ya renderizada arriba */
      } else if (m.media) {
        var src = 'media.php?id=' + m.media;
        if (m.tipo === 'image' || m.tipo === 'sticker') {
          cuerpoMsg += '<img class="ib-img' + (m.tipo === 'sticker' ? ' ib-sticker' : '') + '" src="' + src + '" loading="lazy" '
                     + 'alt="' + esc(m.tipo === 'sticker' ? 'Sticker' : 'Imagen recibida') + '" '
                     + 'data-ggi-preview="' + src + '">';
        } else if (m.tipo === 'video') {
          cuerpoMsg += '<video class="ib-img" src="' + src + '" controls preload="none"></video>';
        } else if (m.tipo === 'audio') {
          cuerpoMsg += '<audio src="' + src + '" controls preload="none" style="max-width:100%"></audio>';
        } else {
          cuerpoMsg += '<a class="ib-doc" href="' + src + '" target="_blank" rel="noopener">'
                     + svg('doc', 15) + ' ' + esc(m.media_nombre || 'Documento') + '</a>';
        }
      } else if (m.tipo && m.tipo !== 'text' && !m.texto) {
        var etq = { image: ['image','Imagen'], sticker: ['sticker','Sticker'], video: ['video','Video'],
                    audio: ['audio','Audio'], document: ['doc','Documento'], location: ['image','Ubicación'],
                    interactive: ['list','Mensaje interactivo'], call: ['audio','Llamada de voz'],
                    button: ['list','Respuesta'], contacts: ['person','Contacto'] };
        var ei = etq[m.tipo] || ['doc', m.tipo];
        cuerpoMsg += '<span class="ib-adj">' + svg(ei[0], 15) + ' ' + esc(ei[1]) + '</span>';
      }

      // Interactivo saliente: se guardó como "cuerpo\n[Botones: a · b · c]".
      // Se dibuja el cuerpo arriba y las opciones como botones simulados.
      if (m.tipo === 'interactive' && m.texto) {
        var mm = m.texto.match(/^([\s\S]*?)\n\[(Botones|Lista|Enlace):\s*([\s\S]*)\]$/);
        if (mm) {
          var opts = mm[2] === 'Enlace'
            ? [mm[3].split('→')[0].trim()]
            : mm[3].split('·').map(function (x) { return x.trim(); });
          cuerpoMsg += '<div class="ib-int-body">' + formato(mm[1]) + '</div>'
                     + '<div class="ib-int-opts">' + opts.map(function (o) {
                         return '<div class="ib-int-opt">' + esc(o) + '</div>';
                       }).join('') + '</div>';
        } else {
          cuerpoMsg += formato(m.texto);
        }
      } else if (m.texto && !esLlamada) {
        cuerpoMsg += (cuerpoMsg ? '<div class="ib-pie-foto">' : '')
                   + formato(m.texto) + (cuerpoMsg ? '</div>' : '');
      }

      // Etiqueta "Reenviado" cuando Meta lo marca como tal
      var fwd = m.reenviado
        ? '<div class="ib-fwd">' + svg('forward',13) + ' Reenviado</div>' : '';

      // Cita: si este mensaje responde a otro, se muestra arriba el fragmento
      var cita = m.cita
        ? '<div class="ib-cita ' + (m.cita.dir === 'in' ? 'de-cliente' : 'de-nosotros') + '">'
          + esc(m.cita.texto) + '</div>' : '';

      // Reacción del cliente sobre este mensaje (emoji flotante)
      var rx = m.reaccion
        ? '<span class="ib-rx">' + esc(m.reaccion) + '</span>' : '';

      // Acciones al pasar sobre el mensaje: responder siempre; reaccionar solo
      // sobre mensajes entrantes. Ambas requieren poder escribir.
      var acts = '';
      if (puedeEscribir) {
        acts += '<button type="button" class="ib-msg-act" data-reply="' + m.id + '" '
              + 'title="Responder" aria-label="Responder">' + svg('reply',16) + '</button>';
        if (m.dir === 'in') {
          acts += '<button type="button" class="ib-msg-act" data-rx="' + m.id + '" '
                + 'title="Reaccionar" aria-label="Reaccionar">' + svg('smile',16) + '</button>';
        }
      }

      cuerpo += '<div class="ib-b-wrap ' + (m.dir === 'in' ? 'in' : 'out') + '">'
             +  '<div class="ib-b ' + cls + '">' + fwd + cita + who + cuerpoMsg + err
             +  '<div class="ib-b-foot">' + hhmm(m.cuando)
             +  (m.dir === 'out' ? ' <span>' + ticks(m.estado) + '</span>' : '') + '</div>'
             +  rx + '</div>'
             +  (acts ? '<span class="ib-msg-acts">' + acts + '</span>' : '') + '</div>';
    });

    // Acción de asignación: el candado que pediste
    var accion = c.asignado_a
      ? (c.puede_liberar
          ? '<button type="button" class="btn btn-ghost ib-btn-sm" id="ib-liberar">'
            + (c.es_mia ? 'Soltar' : 'Quitar asignación') + '</button>'
          : '<span class="ib-tag otro">Atiende ' + esc(c.asignado_nombre) + '</span>')
      : '<button type="button" class="btn btn-primary ib-btn-sm" id="ib-asignar">Tomar conversación</button>';

    // Gerencia puede pasarla a otro ejecutivo sin tener que tomarla primero
    if (ES_JEFE) {
      accion += '<button type="button" class="btn btn-ghost ib-btn-sm ib-reasignar-btn" id="ib-reasignar" '
              + 'title="Reasignar a otro ejecutivo" aria-label="Reasignar a otro ejecutivo">'
              + '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" width="15" height="15" '
              + 'style="vertical-align:-3px;margin-right:4px">'
              + '<path stroke-linecap="round" stroke-linejoin="round" '
              + 'd="M16 3l4 4-4 4M20 7H8M8 21l-4-4 4-4M4 17h12"/></svg>Reasignar</button>';
    }

    // Meta solo admite texto libre dentro de las 24h desde el último entrante
    var pie;
    if (!c.ventana_abierta) {
      pie = '<div class="ib-warn">'
          + '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">'
          + '<circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 7v5l3 2"/></svg>'
          + '<span class="ib-warn-b"><strong>Ventana de 24 h vencida</strong>'
          + 'Solo se pueden enviar plantillas aprobadas hasta que el contacto responda.</span>'
          // Sin la conversación a cargo, ofrecer tomarla es más útil que un
          // botón muerto: el bloqueo real es la asignación, no la plantilla.
          + (c.es_mia
              ? '<button type="button" class="btn btn-primary ib-btn-sm" id="ib-plantilla">Enviar plantilla</button>'
              : (c.asignado_a
                  ? '<span class="ib-tag otro">Atiende ' + esc(c.asignado_nombre) + '</span>'
                  : '<button type="button" class="btn btn-primary ib-btn-sm" id="ib-asignar">Tomar para responder</button>'))
          + '</div>';
    } else if (!c.es_mia) {
      pie = '<div class="ib-tomar">'
          + '<span>' + (c.asignado_a
              ? 'Esta conversación la atiende <b>' + esc(c.asignado_nombre) + '</b>.'
              : 'Nadie la está atendiendo. Tómala para poder responder.') + '</span>'
          + (c.asignado_a
              ? '' : '<button type="button" class="btn btn-primary ib-btn-sm" id="ib-asignar">Tomar conversación</button>')
          + '</div>';
    } else {
      // Franja del adjunto: vive DENTRO del compositor, no en una ventana
      // aparte, para poder escribir el mensaje junto a la imagen.
      pie = '<div id="ib-resp-prev"></div>'
          + '<div id="ib-adj-prev"></div>'
          + '<div class="ib-barra">'
          + '<button type="button" class="ib-fmt" data-fmt="*" title="Negrita (*texto*)" aria-label="Negrita"><b>B</b></button>'
          + '<button type="button" class="ib-fmt" data-fmt="_" title="Cursiva (_texto_)" aria-label="Cursiva"><i>I</i></button>'
          + '<button type="button" class="ib-fmt" data-fmt="~" title="Tachado (~texto~)" aria-label="Tachado"><s>S</s></button>'
          + '<button type="button" class="ib-fmt" data-fmt="```" title="Monoespaciado" aria-label="Monoespaciado">&lt;/&gt;</button>'
          + '<span class="ib-barra-sep"></span>'
          + '<button type="button" class="ib-fmt" id="ib-emoji" title="Emojis" aria-label="Insertar emoji">' + svg('emoji') + '</button>'
          + '<button type="button" class="ib-fmt" id="ib-adjunto" title="Adjuntar archivo" aria-label="Adjuntar archivo">' + svg('clip') + '</button>'
          // También dentro de la ventana: hay plantillas útiles en una
          // conversación viva (factura, documentos completos) y no tiene
          // sentido obligar a reescribirlas a mano.
          + '<button type="button" class="ib-fmt" id="ib-plantilla" title="Enviar una plantilla" aria-label="Enviar una plantilla">'
          + '<svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">'
          + '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18M8 14h8"/></svg></button>'
          + '<button type="button" class="ib-fmt" id="ib-interactivo" title="Mensaje interactivo (botones o lista)" aria-label="Mensaje interactivo">'
          + '<svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">'
          + '<rect x="3" y="5" width="18" height="4" rx="1"/><rect x="3" y="12" width="18" height="4" rx="1"/><path d="M7 19h10"/></svg></button>'
          + '<input type="file" id="ib-file" hidden '
          + 'accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/3gpp,audio/*,'
          + '.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt">'
          + '</div>'
          + '<div class="ib-box">'
          // contenteditable, no <input>: un input no puede mostrar el texto
          // con formato mientras se escribe.
          + '<div id="ib-texto" class="ib-editable" contenteditable="true" role="textbox"'
          + ' aria-multiline="true" aria-label="Mensaje" data-ph="Escribe un mensaje…"></div>'
          // Micrófono a la derecha del compositor, siempre visible (como WA Web),
          // en vez de perdido al final de la barra de formato.
          + '<button type="button" class="ib-mic" id="ib-voz" title="Grabar mensaje de voz" aria-label="Grabar mensaje de voz">'
          + '<svg width="19" height="19" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">'
          + '<rect x="9" y="2" width="6" height="12" rx="3"/><path d="M5 10a7 7 0 0014 0M12 17v4"/></svg></button>'
          + '<button type="button" class="btn btn-primary ib-btn-sm" id="ib-enviar">Enviar</button>'
          + '</div>';
    }

    $('#ib-chat').innerHTML =
        '<div class="ib-chat-head">'
      +   '<button type="button" class="ib-icon ib-volver" id="ib-volver" title="Volver a la lista" aria-label="Volver a la lista">'
      +     '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">'
      +     '<path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>'
      +   '</button>'
      // El botón de ocultar la lista vive DENTRO de la lista: sin este de acá
      // no habría forma de recuperarla una vez oculta.
      +   '<button type="button" class="ib-icon ib-solo-desk" data-panel="lista" '
      +     'title="Mostrar u ocultar la lista de conversaciones" aria-label="Mostrar u ocultar la lista de conversaciones">'
      +     '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">'
      +     '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16"/></svg>'
      +   '</button>'
      +   '<span class="ib-av ' + colorDe(c.telefono) + (c.ventana_abierta ? ' viva' : '') + '">'
      +     esc(ini(c.titulo)) + '</span>'
      +   '<div style="flex:1;min-width:0">'
      +     '<div class="ib-chat-n">' + esc(c.titulo)
      +       (c.cliente_id ? ' <button type="button" class="ib-linkico" data-ver="../clientes/ver.php?id=' + c.cliente_id + '" '
      +         'data-titulo="' + esc(c.titulo) + '" title="Abrir ficha de la empresa" aria-label="Abrir ficha de la empresa">'
      +         '<svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">'
      +         '<path stroke-linecap="round" stroke-linejoin="round" d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6M15 3h6v6M10 14L21 3"/></svg></button>' : '')
      +     '</div>'
      +     '<div class="ib-chat-ph">+' + esc(c.telefono) + '</div>'
      +   '</div>'
      // Llamar por WhatsApp (saliente). Requiere ventana de permiso de Meta
      // (que el cliente haya llamado o callback habilitado).
      +   '<button type="button" class="ib-icon ib-llamar" id="ib-llamar" '
      +     'data-nombre="' + esc(c.titulo) + '" title="Llamar por WhatsApp" aria-label="Llamar por WhatsApp">'
      +     '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">'
      +     '<path stroke-linecap="round" stroke-linejoin="round" d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6A19.79 19.79 0 012.12 4.18 2 2 0 014.11 2h3a2 2 0 012 1.72c.13.96.36 1.9.7 2.81a2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0122 16.92z"/></svg>'
      +   '</button>' + accion
      +   '<button type="button" class="ib-icon ib-solo-desk" data-panel="ctx" '
      +     'title="Mostrar u ocultar el panel de contexto" aria-label="Mostrar u ocultar el panel de contexto">'
      +     '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">'
      +     '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M15 4v16"/></svg>'
      +   '</button>'
      + '</div>'
      + '<div class="ib-scroll ib-msgs" id="ib-msgs">' + cuerpo + '</div>'
      + '<div class="ib-foot">' + pie + '</div>';

    var m = $('#ib-msgs');
    if (m) {
      // Si venía leyendo mensajes viejos, no lo arrastres al final
      var irAlFinal = !(previo && !previo.abajo);

      if (!irAlFinal) {
        m.scrollTop = previo.scroll;
      } else {
        var bajar = function () { m.scrollTop = m.scrollHeight; };
        bajar();

        // Imágenes y videos cargan DESPUÉS de pintar el HTML: al crecer el
        // contenido, el scroll queda un mensaje más arriba. Se vuelve a bajar
        // cuando cada uno termina de cargar.
        m.querySelectorAll('img, video').forEach(function (el) {
          ['load', 'loadedmetadata', 'error'].forEach(function (ev) {
            el.addEventListener(ev, bajar, { once: true });
          });
        });

        requestAnimationFrame(bajar);
        setTimeout(bajar, 300);
      }
    }

    var t = $('#ib-texto');
    if (t) {
      if (previo && previo.texto) {
        t.innerHTML = formatoVivo(previo.texto);
        if (previo.foco) { t.focus(); _setCursor(t, previo.pos); }
      } else if (!previo) {
        t.focus();
      }

      t.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); enviar(); }
      });

      // Repintar con formato en cada cambio
      t.addEventListener('input', function () { repintar(t); });

      // Adjuntar: primero vista previa, como WhatsApp. Enviar de golpe no da
      // oportunidad de revisar el archivo ni de acompañarlo con un mensaje.
      var file = $('#ib-file');
      if (file) file.addEventListener('change', function () {
        if (!file.files || !file.files[0] || !st.sel) return;
        ponerAdjunto(file.files[0]);
      });

      // Reponer el adjunto si el hilo se repintó mientras estaba puesto
      if (st.adjunto) pintarAdjunto();
      if (st.responder) pintarResponder();

      // Pegar SIEMPRE como texto plano: si no, entra HTML del portapapeles
      t.addEventListener('paste', function (e) {
        e.preventDefault();
        var txt = (e.clipboardData || window.clipboardData).getData('text/plain') || '';
        var o = _offsets(t), v = t.innerText.replace(/\n$/, '');
        repintar_con(t, v.slice(0, o.a) + txt + v.slice(o.b), o.a + txt.length);
      });
    }
  }

  // ── Campo editable con formato en vivo ─────────────────────────────────────
  // formatoVivo CONSERVA los marcadores (*, _, ~): así el texto plano del
  // campo no cambia de longitud al aplicar estilos, y las posiciones del
  // cursor siguen siendo válidas. Si se ocultaran, el cursor saltaría.
  function formatoVivo(txt) {
    // Los marcadores NO se borran (eso cambiaría la longitud del texto y el
    // cursor saltaría en cada tecla), pero se atenúan con .ib-mk para que se
    // note que el formato ya se aplicó.
    var mk = function (c) { return '<span class="ib-mk">' + c + '</span>'; };
    return esc(txt)
      .replace(/```([\s\S]+?)```/g, function (_, t) {
        return '<code class="ib-mono">' + mk('```') + t + mk('```') + '</code>';
      })
      .replace(/(^|[\s(])\*(\S(?:[^*\n]*\S)?)\*(?=[\s.,;:!?)]|$)/g, function (_, p, t) {
        return p + '<b>' + mk('*') + t + mk('*') + '</b>';
      })
      .replace(/(^|[\s(])_(\S(?:[^_\n]*\S)?)_(?=[\s.,;:!?)]|$)/g, function (_, p, t) {
        return p + '<i>' + mk('_') + t + mk('_') + '</i>';
      })
      .replace(/(^|[\s(])~(\S(?:[^~\n]*\S)?)~(?=[\s.,;:!?)]|$)/g, function (_, p, t) {
        return p + '<s>' + mk('~') + t + mk('~') + '</s>';
      });
  }

  /** Posición del cursor medida en caracteres del texto plano. */
  function _offsets(el) {
    var s = window.getSelection();
    if (!s || !s.rangeCount || !el.contains(s.anchorNode)) return { a: 0, b: 0 };
    var r = s.getRangeAt(0);
    function medir(nodo, off) {
      var x = document.createRange();
      x.selectNodeContents(el);
      x.setEnd(nodo, off);
      return x.toString().length;
    }
    var a = medir(r.startContainer, r.startOffset);
    var b = medir(r.endContainer, r.endOffset);
    return { a: Math.min(a, b), b: Math.max(a, b) };
  }

  function _setCursor(el, pos) {
    var w = document.createTreeWalker(el, NodeFilter.SHOW_TEXT), n, acc = 0, r = document.createRange();
    while ((n = w.nextNode())) {
      if (acc + n.length >= pos) {
        r.setStart(n, pos - acc); r.collapse(true);
        var s = window.getSelection(); s.removeAllRanges(); s.addRange(r);
        return;
      }
      acc += n.length;
    }
    r.selectNodeContents(el); r.collapse(false);
    var s2 = window.getSelection(); s2.removeAllRanges(); s2.addRange(r);
  }

  /** Repinta el campo con formato y devuelve el cursor a su sitio. */
  function repintar(el, pos) {
    // Si algo fallara acá, el formato dejaría de aplicarse sin ningún aviso:
    // el texto se sigue escribiendo, así que el fallo pasa desapercibido.
    try {
      // NO se recorta el \n final: hacerlo borraba el salto de línea recién
      // hecho con Shift+Enter al final del texto. El recorte va solo al enviar.
      var plano = el.innerText;
      if (pos === undefined) pos = _offsets(el).b;
      el.innerHTML = formatoVivo(plano);
      _setCursor(el, Math.min(pos, plano.length));
    } catch (err) {
      if (window.console) console.error('[inbox] repintar:', err);
    }
  }

  function textoActual() {
    var el = $('#ib-texto');
    return el ? el.innerText.replace(/\n$/, '') : '';
  }

  // ── Formato y emojis ───────────────────────────────────────────────────────
  // WhatsApp interpreta *negrita*, _cursiva_, ~tachado~ y ```mono``` como
  // texto plano: no hace falta nada del lado de la API, solo insertar marcas.
  function envolver(marca) {
    var el = $('#ib-texto');
    if (!el) return;
    // Leer la selección ANTES de tocar el foco: enfocar puede colapsarla
    var o = _offsets(el), v = el.innerText.replace(/\n$/, '');
    el.focus();
    var sel = v.slice(o.a, o.b);

    // Sin selección: insertar los marcadores vacíos y dejar el cursor EN MEDIO,
    // para escribir dentro (como WhatsApp Web). Con selección, envolverla y
    // dejar el cursor tras el cierre.
    var cursor = sel === ''
      ? o.a + marca.length
      : o.a + marca.length + sel.length + marca.length;

    repintar_con(el, v.slice(0, o.a) + marca + sel + marca + v.slice(o.b), cursor);
  }

  function insertar(txt) {
    var el = $('#ib-texto');
    if (!el) return;
    var o = _offsets(el), v = el.innerText.replace(/\n$/, '');
    el.focus();
    repintar_con(el, v.slice(0, o.a) + txt + v.slice(o.b), o.a + txt.length);
  }

  function repintar_con(el, plano, pos) {
    el.innerHTML = formatoVivo(plano);
    _setCursor(el, Math.min(pos, plano.length));
  }

  // Panel con pestañas Emojis / Stickers. Los emojis vienen categorizados de
  // GGI_EMOJIS (assets/js/emojis.js): barra de categorías abajo + secciones,
  // como WhatsApp. Los stickers son los ya usados en conversaciones.
  function popEmojis(ancla) {
    var cats = window.GGI_EMOJIS || [];
    var barra = cats.map(function (c, i) {
      return '<button type="button" class="ib-emo-cat' + (i === 0 ? ' on' : '') + '" '
           + 'data-cat="' + c.id + '" title="' + c.nombre + '" aria-label="' + c.nombre + '">'
           + c.ico + '</button>';
    }).join('');
    var secciones = cats.map(function (c) {
      return '<div class="ib-emo-sec" id="ib-emo-sec-' + c.id + '">'
           + '<div class="ib-emo-sec-h">' + c.nombre + '</div>'
           + '<div class="ib-emojis">' + c.items.map(function (x) {
               return '<button type="button" data-emoji="' + x + '" aria-label="' + x + '">' + x + '</button>';
             }).join('') + '</div></div>';
    }).join('');

    var d = abrirPop(ancla,
        '<div class="ib-tabs">'
      +   '<button type="button" class="ib-tab on" data-tab="emoji">Emojis</button>'
      +   '<button type="button" class="ib-tab" data-tab="sticker">Stickers</button>'
      + '</div>'
      + '<div id="ib-panel-emoji">'
      +   '<div class="ib-emo-scroll" id="ib-emo-scroll">' + secciones + '</div>'
      +   '<div class="ib-emo-cats">' + barra + '</div>'
      + '</div>'
      + '<div id="ib-panel-sticker" hidden><div class="ib-emojis ib-stk" id="ib-stk-lista">'
      +   '<div class="ib-emo-vacio">Cargando…</div>'
      + '</div></div>', { arriba: true });
    d.dataset.pop = 'emoji';   // marca para poder cerrarlo con el mismo botón

    var scroll = d.querySelector('#ib-emo-scroll');

    d.addEventListener('click', function (e) {
      var t = e.target.closest('.ib-tab');
      if (t) {
        d.querySelectorAll('.ib-tab').forEach(function (b) { b.classList.toggle('on', b === t); });
        d.querySelector('#ib-panel-emoji').hidden   = t.dataset.tab !== 'emoji';
        d.querySelector('#ib-panel-sticker').hidden = t.dataset.tab !== 'sticker';
        if (t.dataset.tab === 'sticker') cargarStickers(d);
        return;
      }
      var c = e.target.closest('.ib-emo-cat');
      if (c && scroll) {
        var sec = d.querySelector('#ib-emo-sec-' + c.dataset.cat);
        if (sec) scroll.scrollTop = sec.offsetTop - scroll.offsetTop;
      }
    });

    // Resaltar en la barra la categoría visible según el scroll
    if (scroll) {
      scroll.addEventListener('scroll', function () {
        var act = null;
        d.querySelectorAll('.ib-emo-sec').forEach(function (s) {
          if (s.offsetTop - scroll.offsetTop <= scroll.scrollTop + 8) act = s.id.replace('ib-emo-sec-', '');
        });
        if (act) d.querySelectorAll('.ib-emo-cat').forEach(function (b) {
          b.classList.toggle('on', b.dataset.cat === act);
        });
      });
    }
  }

  function cargarStickers(d) {
    var caja = d.querySelector('#ib-stk-lista');
    if (!caja || caja.dataset.listo) return;

    fetch('ajax.php?accion=stickers', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        caja.dataset.listo = '1';
        caja.innerHTML = (j.ok && j.items.length)
          ? j.items.map(function (s) {
              return '<button type="button" class="ib-stk-b" data-stk="' + s.id + '" title="Enviar sticker">'
                   + '<img src="media.php?id=' + s.id + '" alt="Sticker" loading="lazy"></button>';
            }).join('')
          : '<div style="grid-column:1/-1;padding:14px;color:var(--tx3);font-size:.78rem">'
            + 'Aún no hay stickers. Los que envíes o recibas aparecerán acá.</div>';
      })
      .catch(function () {});
  }

  function enviarAdjunto(texto, btn, inp) {
    var a = st.adjunto;
    if (btn) { btn.disabled = true; btn.textContent = 'Enviando…'; }

    var fd = new FormData();
    fd.append('accion', 'adjuntar');
    fd.append('id', st.sel);
    fd.append('archivo', a.file);
    fd.append('texto', a.sticker ? '' : texto);

    ggiPost('ajax.php', fd).then(function (j) {
      if (btn && document.body.contains(btn)) { btn.disabled = false; btn.textContent = 'Enviar'; }
      if (!j.ok) return;
      quitarAdjunto();
      if (inp && document.body.contains(inp)) inp.innerHTML = '';
      if (j.aviso && typeof showToast === 'function') showToast(j.aviso, 'warn', 6000);
      st.firma = null;          // forzar repintado con el mensaje nuevo
      abrir(st.sel);
    });
  }

  // ── Responder a un mensaje (cita) ──────────────────────────────────────────
  function ponerResponder(mid) {
    // Busca el texto del mensaje citado en el hilo ya pintado
    var b = document.querySelector('.ib-b-wrap [data-reply="' + mid + '"]');
    var burbuja = b && b.closest('.ib-b-wrap').querySelector('.ib-b');
    var txt = burbuja ? (burbuja.innerText || '').trim().slice(0, 90) : '(mensaje)';
    st.responder = { id: mid, texto: txt };
    pintarResponder();
    var caja = $('#ib-texto'); if (caja) caja.focus();
  }

  function quitarResponder() { st.responder = null; pintarResponder(); }

  function pintarResponder() {
    var box = $('#ib-resp-prev');
    if (!box) return;
    if (!st.responder) { box.innerHTML = ''; return; }
    box.innerHTML = '<div class="ib-resp"><div class="ib-resp-txt">'
      + '<b>Respondiendo</b>' + esc(st.responder.texto) + '</div>'
      + '<button type="button" class="ib-resp-x" id="ib-resp-quitar" '
      + 'title="Cancelar" aria-label="Cancelar respuesta">' + svg('x',14) + '</button></div>';
  }

  // ── Mensaje de voz ─────────────────────────────────────────────────────────
  // WhatsApp solo ENTREGA notas de voz en OGG/Opus. Los navegadores graban
  // formatos que Meta acepta al subir pero luego no reproduce (Chrome=webm,
  // Safari=mp4/aac), por eso quedaban en "sent" sin llegar al destinatario. Con
  // opus-recorder (encoder Opus en WASM) grabamos OGG/Opus real en todos.
  var _rec = null, _recTimer = null, _recT0 = 0, _recCancelado = false, _recStream = null, _recCtx = null;

  function vozDisponible() {
    return !!(window.Recorder && window.WebAssembly &&
      navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
  }

  function _errorMic(err) {
    var nombre = (err && err.name) || '';
    var msg;
    if (nombre === 'NotAllowedError')
      msg = 'El navegador no dio acceso al micrófono. En Mac revisa también '
          + 'Ajustes del sistema → Privacidad y seguridad → Micrófono → activa Chrome, y reintenta.';
    else if (nombre === 'SecurityError')
      msg = 'El micrófono se bloqueó por seguridad (¿la página está incrustada o sin HTTPS?).';
    else if (nombre === 'NotFoundError' || nombre === 'DevicesNotFoundError')
      msg = 'No se detectó ningún micrófono en este equipo.';
    else if (nombre === 'NotReadableError' || nombre === 'TrackStartError')
      msg = 'El micrófono está en uso por otra app (Zoom, Meet, otra pestaña). Ciérrala y reintenta.';
    else
      msg = 'No se pudo grabar' + (nombre ? ' (' + nombre + ')' : '') + '.';
    console.error('[voz] error micrófono:', nombre, err);
    if (window.showToast) showToast(msg, 'error');
  }

  function _soltarMic() {
    if (_recStream) { _recStream.getTracks().forEach(function (t) { t.stop(); }); _recStream = null; }
    if (_recCtx) { try { _recCtx.close(); } catch (_) {} _recCtx = null; }
  }

  function toggleVoz() {
    if (_rec) { _rec.stop(); return; }   // segunda pulsación → detener y enviar
    if (!st.sel) return;
    if (!vozDisponible()) {
      if (window.showToast) showToast('Este navegador no permite grabar audio', 'error');
      return;
    }

    _recCancelado = false;
    // Pedimos el micrófono NOSOTROS (no opus-recorder) para tener el error real
    // y no un genérico. El stream se le pasa a opus-recorder vía sourceNode.
    navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
      _recStream = stream;
      var AC = window.AudioContext || window.webkitAudioContext;
      _recCtx = new AC();
      if (_recCtx.resume) { try { _recCtx.resume(); } catch (_) {} }
      var source = _recCtx.createMediaStreamSource(stream);

      // 16 kHz + VOIP: igual que las notas de voz reales de WhatsApp (SILK). A
      // 48 kHz salía en modo fullband y WhatsApp la mostraba como archivo.
      _rec = new Recorder({
        sourceNode:         source,       // usa NUESTRO stream, no vuelve a pedir permiso
        encoderPath:        '<?= APP_URL ?>/assets/js/encoderWorker.min.js',
        encoderApplication: 2048,         // VOIP, optimizado para voz (SILK)
        encoderSampleRate:  16000,
        numberOfChannels:   1,
        streamPages:        false,        // entrega el OGG completo al terminar
        recordingGain:      1,
        monitorGain:        0,
        maxFramesPerPage:   40,
      });
      // El envío va en ondataavailable (no onstop): con streamPages:false el OGG
      // completo llega aquí DESPUÉS de stop(); en laptop onstop se disparaba antes.
      _rec.ondataavailable = function (typedArray) {
        if (_recCancelado) return;
        procesarVoz(typedArray);
      };
      _rec.onstop = function () {
        clearInterval(_recTimer);
        pintarBarraVoz(false);
        _rec = null;
        _soltarMic();
      };
      _rec.start().then(function () {
        _recT0 = Date.now();
        pintarBarraVoz(true);
        _recTimer = setInterval(function () {
          var s = Math.floor((Date.now() - _recT0) / 1000);
          var el = document.getElementById('ib-voz-t');
          if (el) el.textContent = ('0' + Math.floor(s / 60)).slice(-2) + ':' + ('0' + (s % 60)).slice(-2);
          if (s >= 300 && _rec) _rec.stop();   // tope de 5 min
        }, 250);
      }).catch(function (err) {
        _rec = null; _soltarMic();
        console.error('[voz] fallo al iniciar el encoder:', err);
        if (window.showToast) showToast('No se pudo iniciar la grabación de voz.', 'error');
      });
    }).catch(function (err) {
      _soltarMic();
      _errorMic(err);
    });
  }

  function cancelarVoz() {
    if (!_rec) return;
    _recCancelado = true;
    _rec.stop();
  }

  // Barra de grabación en lugar del compositor
  function pintarBarraVoz(grabando) {
    var foot = document.querySelector('.ib-foot');
    if (!foot) return;
    if (!grabando) { if (foot.dataset.voz) { foot.dataset.voz = ''; abrir(st.sel); } return; }
    foot.dataset.voz = '1';
    foot.innerHTML =
        '<div class="ib-voz-barra">'
      +   '<button type="button" class="ib-voz-x" id="ib-voz-cancel" title="Cancelar" aria-label="Cancelar">' + svg('x',16) + '</button>'
      +   '<span class="ib-voz-dot"></span>'
      +   '<span class="ib-voz-t" id="ib-voz-t">00:00</span>'
      +   '<span class="ib-voz-msg">Grabando… suelta para enviar</span>'
      +   '<button type="button" class="btn btn-primary ib-btn-sm" id="ib-voz-stop">Enviar</button>'
      + '</div>';
  }

  function procesarVoz(bytes) {
    if (!bytes || bytes.length < 400) {
      if (window.showToast) showToast('La grabación fue muy corta', 'error');
      return;
    }
    // opus-recorder ya entrega OGG/Opus válido; confirmamos la firma "OggS".
    // Nota: WhatsApp Cloud API entrega el audio como ARCHIVO reproducible, no
    // como nota de voz con waveform (eso es exclusivo de audios grabados dentro
    // de la app de WhatsApp). Se probó OGG/Opus SILK 16 kHz e incluso reescribir
    // el OpusHead: WhatsApp igual lo muestra como archivo. Es límite de la API.
    if (bytes[0] !== 0x4F || bytes[1] !== 0x67 || bytes[2] !== 0x67 || bytes[3] !== 0x53) {
      if (window.showToast) showToast('El audio no se grabó en un formato válido', 'error');
      return;
    }

    if (window.showToast) showToast('Enviando nota de voz…', 'ok');

    var blob = new Blob([bytes], { type: 'audio/ogg' });
    var fd = new FormData();
    fd.append('accion', 'adjuntar');
    fd.append('id', st.sel);
    fd.append('voz', '1');
    fd.append('archivo', blob, 'nota_de_voz.ogg');
    ggiPost('ajax.php', fd).then(function (j) {
      st.firma = null; abrir(st.sel);
      if (!j.ok && window.showToast) showToast(j.msg || 'No se pudo enviar la nota de voz', 'error');
    });
  }

  // ── Modal de adjuntar con zona de arrastre (estilo WhatsApp Web) ───────────
  function modalAdjuntar() {
    if (!st.sel) return;

    mantOpen('Adjuntar archivo',
        '<div class="ib-drop" id="ib-drop" tabindex="0" role="button" '
      + 'aria-label="Soltar archivos aquí o clic para elegir">'
      +   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" width="42" height="42">'
      +     '<path stroke-linecap="round" stroke-linejoin="round" d="M21.44 11.05l-9.19 9.19a5 5 0 01-7.07-7.07l9.19-9.19a3.5 3.5 0 014.95 4.95l-9.2 9.19a2 2 0 01-2.83-2.83l8.49-8.48"/></svg>'
      +   '<div class="ib-drop-t">Suelta el archivo aquí o haz clic para elegir</div>'
      +   '<div class="ib-drop-s">Imágenes, video, audio o documentos. Cada archivo se envía por separado.</div>'
      +   '<div class="ib-drop-s">Máx.: imágenes 5 MB · video/audio 16 MB · documentos 100 MB</div>'
      + '</div>'
      + '<input type="file" id="ib-drop-file" hidden '
      +   'accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/3gpp,audio/*,'
      +   '.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt">'
      + '<div style="display:flex;justify-content:flex-end;margin-top:14px">'
      + '<button type="button" class="ggi-btn-confirm-cancel" onclick="ggiModalClose()">Cancelar</button></div>');

    var drop = document.getElementById('ib-drop');
    var file = document.getElementById('ib-drop-file');

    function tomar(f) {
      if (!f) return;
      ggiModalClose();
      ponerAdjunto(f);   // va a la franja del compositor, con vista previa y pie
    }

    drop.addEventListener('click', function () { file.click(); });
    drop.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); file.click(); }
    });
    file.addEventListener('change', function () { if (file.files[0]) tomar(file.files[0]); });

    ['dragenter', 'dragover'].forEach(function (ev) {
      drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('sobre'); });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
      drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('sobre'); });
    });
    drop.addEventListener('drop', function (e) {
      var f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
      if (f) tomar(f);
    });
  }

  // ── Adjunto en la propia caja de escritura ─────────────────────────────────
  function ponerAdjunto(archivo) {
    if (st.adjunto && st.adjunto.url) URL.revokeObjectURL(st.adjunto.url);

    var mime = archivo.type || '';
    st.adjunto = {
      file: archivo,
      url:  URL.createObjectURL(archivo),
      mime: mime,
      sticker: mime === 'image/webp',
      gif: mime === 'image/gif'
    };
    pintarAdjunto();
  }

  function quitarAdjunto() {
    if (st.adjunto && st.adjunto.url) URL.revokeObjectURL(st.adjunto.url);
    st.adjunto = null;
    var f = $('#ib-file');
    if (f) f.value = '';
    pintarAdjunto();
  }

  function pintarAdjunto() {
    var box = $('#ib-adj-prev');
    if (!box) return;

    if (!st.adjunto) { box.innerHTML = ''; return; }

    var a = st.adjunto, kb = a.file.size / 1024;
    var peso = kb < 1024 ? Math.round(kb) + ' KB' : (kb / 1024).toFixed(1) + ' MB';

    var mini;
    if (a.mime.indexOf('image/') === 0)      mini = '<img src="' + a.url + '" alt="">';
    else if (a.mime.indexOf('video/') === 0) mini = '<video src="' + a.url + '" muted></video>';
    else if (a.mime.indexOf('audio/') === 0) mini = '<span class="ib-adj-ico">' + svg('audio',20) + '</span>';
    else                                     mini = '<span class="ib-adj-ico">' + svg('doc',20) + '</span>';

    var nota = a.sticker ? 'Se enviará como sticker (sin texto)'
             : (a.gif ? 'Se convertirá a sticker animado' : '');

    box.innerHTML =
        '<div class="ib-adj-chip">'
      +   '<span class="ib-adj-mini">' + mini + '</span>'
      +   '<span class="ib-adj-info"><b>' + esc(a.file.name) + '</b>'
      +     '<small>' + peso + (nota ? ' · ' + nota : '') + '</small></span>'
      +   '<button type="button" class="ib-adj-x" id="ib-adj-quitar" '
      +     'title="Quitar archivo" aria-label="Quitar archivo">&times;</button>'
      + '</div>';

    // El sticker no admite pie de foto: se bloquea la caja para que se vea
    var caja = $('#ib-texto');
    if (caja) {
      caja.setAttribute('contenteditable', a.sticker ? 'false' : 'true');
      caja.setAttribute('data-ph', a.sticker ? 'Los stickers se envían sin texto' : 'Añade un mensaje…');
      if (!a.sticker) caja.focus();
    }
  }

  // ── Plantillas (única vía fuera de la ventana de 24 h) ─────────────────────
  var _plt = null;   // plantilla elegida

  // Selector en modal, no en un desplegable estrecho: elegir un mensaje que va
  // a salir a un cliente requiere leerlo entero, no un fragmento cortado.
  function popPlantillas() {
    if (!st.sel) return;

    fetch('ajax.php?accion=plantillas', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j.ok) return;
        _plt = j.items || [];

        if (!_plt.length) {
          mantOpen('Enviar plantilla',
            '<div class="ib-vacio-p">No hay plantillas aprobadas todavía.<br>'
            + 'Se gestionan en <b>Plantillas WA</b>.</div>');
          return;
        }

        mantOpen('Elegir plantilla',
            '<div class="ib-plt-busca">'
          +   '<input type="search" id="plt-q" placeholder="Buscar plantilla…" aria-label="Buscar plantilla">'
          + '</div>'
          + '<div class="ib-plt-lista" id="plt-lista"></div>');

        pintarLista('');
        var q = document.getElementById('plt-q');
        if (q) { q.focus(); q.addEventListener('input', function () { pintarLista(q.value); }); }
      });
  }

  function pintarLista(filtro) {
    var cont = document.getElementById('plt-lista');
    if (!cont) return;

    filtro = (filtro || '').toLowerCase();
    var vis = _plt.filter(function (p) {
      return p.nombre.toLowerCase().indexOf(filtro) !== -1
          || (p.cuerpo || '').toLowerCase().indexOf(filtro) !== -1;
    });

    if (!vis.length) {
      cont.innerHTML = '<div class="ib-vacio-p">Ninguna plantilla coincide.</div>';
      return;
    }

    // Separadas por categoría: Marketing cuesta más por mensaje y exige
    // consentimiento. Mezcladas, es fácil mandar la cara sin querer.
    var grupos = [
      { cat: 'UTILITY',   t: 'Utilidad',  d: 'Avisos de un trámite en curso' },
      { cat: 'MARKETING', t: 'Marketing', d: 'Cuestan más y requieren consentimiento del cliente' }
    ];

    cont.innerHTML = grupos.map(function (g) {
      var items = vis.filter(function (p) { return (p.categoria || 'UTILITY') === g.cat; });
      if (!items.length) return '';
      return '<div class="ib-plt-grupo ' + (g.cat === 'MARKETING' ? 'mk' : '') + '">'
           + '<b>' + g.t + '</b><span>' + g.d + '</span></div>'
           + tarjetas(items);
    }).join('');
  }

  function tarjetas(lista) {
    return lista.map(function (p) {
      var i = _plt.indexOf(p);
      // Vista previa con las variables resaltadas: se entiende de un vistazo
      // qué datos hay que completar antes de enviar.
      // Se escapa PRIMERO, luego se aplican formato y variables sobre el HTML
      // ya seguro: al revés, volvería a escapar las etiquetas insertadas.
      var prev = _fmtHtml(esc(p.cuerpo || ''))
        .replace(/\n/g, '<br>')
        .replace(/\{\{(\d+)\}\}/g, function (_, n) {
          var k = (p.claves && p.claves[parseInt(n, 10) - 1]) || ('dato ' + n);
          return '<span class="ib-var">' + esc(k.replace(/_/g, ' ')) + '</span>';
        });

      return '<button type="button" class="ib-plt-card ib-plt" data-plt="' + i + '">'
        + '<div class="ib-plt-card-h">'
        +   '<b>' + esc(p.nombre.replace(/^ggi_/, '').replace(/_/g, ' ')) + '</b>'
        +   '<span class="ib-plt-n">' + p.claves.length + ' dato' + (p.claves.length === 1 ? '' : 's') + '</span>'
        + '</div>'
        + '<div class="ib-plt-burbuja">' + prev + '</div>'
        + '</button>';
    }).join('');
  }

  // Formulario para rellenar {{1}}, {{2}}… antes de enviar
  function formPlantilla(p) {
    cerrarPop();
    var campos = p.claves.map(function (k, i) {
      return '<label class="ib-plt-f"><span>' + esc(k.replace(/_/g, ' ')) + '</span>'
           + '<input type="text" data-pi="' + i + '" autocomplete="off" '
           + 'placeholder="' + esc(k.replace(/_/g, ' ')) + '"></label>';
    }).join('');

    // Botón URL dinámico: Meta lo exige aparte del cuerpo. Sin él, el envío
    // falla con "Button at index 0 of type Url requires a parameter".
    if (p.boton) {
      campos += '<label class="ib-plt-f"><span>' + esc(p.boton.replace(/_/g, ' '))
              + ' <small>(dato del botón)</small></span>'
              + '<input type="text" id="plt-boton" autocomplete="off" '
              + 'placeholder="' + esc(p.boton.replace(/_/g, ' ')) + '"></label>';
    }

    mantOpen('Completar y enviar',
        '<button type="button" class="ib-plt-volver" id="plt-volver">‹ Elegir otra plantilla</button>'
      + '<div class="ib-plt-prevbox">'
      +   '<div class="ib-plt-prevlbl">Así lo recibirá el cliente</div>'
      +   '<div class="ib-plt-burbuja grande" id="ib-plt-prev"></div>'
      + '</div>'
      + '<div class="ib-auto-aviso" id="plt-auto" style="display:none"></div>'
      + (campos || '<p class="ib-vacio-p">Esta plantilla no lleva datos variables.</p>')
      + '<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px">'
      + '<button type="button" class="ggi-btn-confirm-cancel" onclick="ggiModalClose()">Cancelar</button>'
      + '<button type="button" class="ggi-btn-confirm-action" id="ib-plt-enviar">Enviar mensaje</button>'
      + '</div>');

    var volver = document.getElementById('plt-volver');
    if (volver) volver.addEventListener('click', function () { popPlantillas(); });

    // Precargar lo que el sistema ya sabe de esta empresa. El ejecutivo no
    // debería copiar a mano un dato que está en la ficha.
    if (p.claves.length || p.boton) {
      var pedir = p.claves.slice();
      if (p.boton) pedir.push(p.boton);
      fetch('ajax.php?accion=sugerir_params&id=' + st.sel
            + '&claves=' + encodeURIComponent(pedir.join(',')),
            { credentials: 'same-origin', cache: 'no-store' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (!j.ok) return;
          var auto = 0;
          document.querySelectorAll('[data-pi]').forEach(function (inp) {
            var k = p.claves[parseInt(inp.dataset.pi, 10)];
            var v = j.valores[k];
            if (v && !inp.value) {
              inp.value = v;
              inp.classList.add('ib-auto');
              auto++;
            }
          });
          var bt = document.getElementById('plt-boton');
          if (bt && p.boton && j.valores[p.boton] && !bt.value) {
            bt.value = j.valores[p.boton];
            bt.classList.add('ib-auto');
            auto++;
          }

          if (auto) {
            var lbl = document.getElementById('plt-auto');
            if (lbl) {
              lbl.textContent = auto + (auto === 1 ? ' dato se completó' : ' datos se completaron')
                              + ' con la información de la empresa. Puedes editarlos.';
              lbl.style.display = '';
            }
          }
          preview();
        })
        .catch(function () {});
    }

    // Vista previa en vivo: se ve el mensaje real antes de mandarlo
    function preview() {
      var valores = {};
      document.querySelectorAll('[data-pi]').forEach(function (inp) {
        valores[parseInt(inp.dataset.pi, 10) + 1] = inp.value.trim();
      });

      // Lo ya completado se ve como texto normal; lo que falta queda resaltado,
      // así se distingue de un vistazo qué queda pendiente.
      var html = _fmtHtml(esc(p.cuerpo || ''))
        .replace(/\n/g, '<br>')
        .replace(/\{\{(\d+)\}\}/g, function (_, n) {
          var v = valores[parseInt(n, 10)];
          if (v) return esc(v);
          var k = (p.claves && p.claves[parseInt(n, 10) - 1]) || ('dato ' + n);
          return '<span class="ib-var">' + esc(k.replace(/_/g, ' ')) + '</span>';
        });

      var box = document.getElementById('ib-plt-prev');
      if (box) box.innerHTML = html;
    }
    document.querySelectorAll('[data-pi]').forEach(function (inp) {
      inp.addEventListener('input', preview);
    });
    preview();

    var btn = document.getElementById('ib-plt-enviar');
    if (btn) btn.addEventListener('click', function () {
      var vals = [];
      var falta = false;
      document.querySelectorAll('[data-pi]').forEach(function (inp) {
        if (!inp.value.trim()) { falta = true; inp.style.borderColor = 'var(--s-err)'; }
        vals[parseInt(inp.dataset.pi, 10)] = inp.value.trim();
      });
      if (falta) {
        if (typeof showToast === 'function') showToast('Completa todos los campos', 'error');
        return;
      }
      btn.disabled = true; btn.textContent = 'Enviando…';
      var datos = { accion: 'enviar_plantilla', id: st.sel, plantilla: p.nombre };
      vals.forEach(function (v, i) { datos['params[' + i + ']'] = v; });

      var btn2 = document.getElementById('plt-boton');
      if (btn2) {
        if (!btn2.value.trim()) {
          btn2.style.borderColor = 'var(--s-err)';
          if (typeof showToast === 'function') showToast('Falta el dato del botón', 'error');
          return;
        }
        datos.boton = btn2.value.trim();
      }
      ggiPost('ajax.php', datos).then(function (j) {
        btn.disabled = false; btn.textContent = 'Enviar';
        if (!j.ok) return;
        if (typeof ggiModalClose === 'function') ggiModalClose();
        if (typeof showToast === 'function') showToast('Plantilla enviada', 'ok');
        abrir(st.sel);
      });
    });
  }

  // ── Mensaje interactivo (botones / lista / enlace) ─────────────────────────
  function formInteractivo() {
    if (!st.sel) return;

    // Modal con pestañas, como el chat de Kapso: Botones / Lista / Enlace / Contacto
    mantOpen('Enviar mensaje interactivo',
        '<div class="ib-int-tabs">'
      +   '<button type="button" class="ib-int-tab on" data-it="button">' + svg('chat',14) + ' Botones</button>'
      +   '<button type="button" class="ib-int-tab" data-it="list">' + svg('list',14) + ' Lista</button>'
      +   '<button type="button" class="ib-int-tab" data-it="cta_url">' + svg('link',14) + ' Enlace</button>'
      +   '<button type="button" class="ib-int-tab" data-it="contact">' + svg('person',14) + ' Contacto</button>'
      + '</div>'
      + '<div id="it-comun">'
      +   '<label class="ib-plt-f"><span>Encabezado <small>(opcional)</small></span>'
      +     '<input type="text" id="it-header" maxlength="60" placeholder="Título breve"></label>'
      +   '<label class="ib-plt-f"><span>Mensaje</span>'
      +     '<textarea id="it-body" maxlength="1024" rows="3" placeholder="Escribe el mensaje…"></textarea>'
      +     '<div class="ib-plt-hint" id="it-count">0/1024</div></label>'
      +   '<label class="ib-plt-f"><span>Pie de página <small>(opcional)</small></span>'
      +     '<input type="text" id="it-footer" maxlength="60" placeholder="Texto pequeño al final"></label>'
      + '</div>'
      + '<div id="it-especifico"></div>'
      + '<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px">'
      + '<button type="button" class="ggi-btn-confirm-cancel" onclick="ggiModalClose()">Cancelar</button>'
      + '<button type="button" class="ggi-btn-confirm-action" id="it-enviar">Enviar</button></div>');

    var sub = 'button';
    pintarEspecifico(sub);

    // Contador del cuerpo, como en Kapso
    var body = document.getElementById('it-body');
    body.addEventListener('input', function () {
      var c = document.getElementById('it-count');
      if (c) c.textContent = body.value.length + '/1024';
    });

    document.querySelectorAll('.ib-int-tab').forEach(function (t) {
      t.addEventListener('click', function () {
        document.querySelectorAll('.ib-int-tab').forEach(function (x) { x.classList.toggle('on', x === t); });
        sub = t.dataset.it;
        // El bloque común (encabezado/mensaje/pie) no aplica al contacto
        document.getElementById('it-comun').style.display = (sub === 'contact') ? 'none' : '';
        pintarEspecifico(sub);
      });
    });

    document.getElementById('it-enviar').addEventListener('click', function () {
      var btn = this;
      var datos = { accion: 'enviar_interactivo', id: st.sel, subtipo: sub };

      if (sub === 'contact') {
        datos.ct_nombre   = document.getElementById('it-cn').value.trim();
        datos.ct_telefono = document.getElementById('it-ct').value.trim();
        datos.ct_empresa  = document.getElementById('it-ce').value.trim();
        if (!datos.ct_nombre || !datos.ct_telefono) {
          if (window.showToast) showToast('Nombre y teléfono son obligatorios', 'error'); return;
        }
      } else {
        var cuerpo = body.value.trim();
        if (!cuerpo) { if (window.showToast) showToast('Escribe el mensaje', 'error'); return; }
        datos.cuerpo = cuerpo;
        datos.header = document.getElementById('it-header').value.trim();
        datos.pie    = document.getElementById('it-footer').value.trim();

        if (sub === 'button') {
          var i = 0;
          document.querySelectorAll('[data-btn]').forEach(function (b) {
            if (b.value.trim()) datos['botones[' + (i++) + ']'] = b.value.trim();
          });
          if (i === 0) { if (window.showToast) showToast('Agrega al menos un botón', 'error'); return; }
        } else if (sub === 'list') {
          datos.boton_lista = document.getElementById('it-lbtn').value.trim();
          var k = 0;
          document.querySelectorAll('[data-opt]').forEach(function (o) {
            if (o.value.trim()) datos['opciones[' + (k++) + ']'] = o.value.trim();
          });
          if (k === 0) { if (window.showToast) showToast('Agrega al menos una opción', 'error'); return; }
        } else {
          datos.cta_texto = document.getElementById('it-ctxt').value.trim();
          datos.cta_url   = document.getElementById('it-curl').value.trim();
          if (!datos.cta_url) { if (window.showToast) showToast('Falta la URL', 'error'); return; }
        }
      }

      btn.disabled = true; btn.textContent = 'Enviando…';
      ggiPost('ajax.php', datos).then(function (j) {
        btn.disabled = false; btn.textContent = 'Enviar';
        if (!j.ok) return;
        ggiModalClose();
        if (window.showToast) showToast('Mensaje enviado', 'ok');
        st.firma = null; abrir(st.sel);
      });
    });
  }

  function pintarEspecifico(sub) {
    var box = document.getElementById('it-especifico');
    if (!box) return;

    if (sub === 'button') {
      box.innerHTML = '<div class="ib-plt-f"><span>Botones de respuesta <small>(máx. 3, 20 caracteres)</small></span>'
        + '<div id="it-btns"><input type="text" data-btn maxlength="20" placeholder="Botón 1" class="ib-int-in"></div>'
        + '<button type="button" class="btn btn-ghost btn-sm" id="it-add-btn">+ Agregar botón</button></div>';
      document.getElementById('it-add-btn').addEventListener('click', function () {
        var cont = document.getElementById('it-btns');
        if (cont.querySelectorAll('[data-btn]').length >= 3) return;
        var inp = document.createElement('input');
        inp.type = 'text'; inp.setAttribute('data-btn', ''); inp.maxLength = 20; inp.className = 'ib-int-in';
        inp.placeholder = 'Botón ' + (cont.querySelectorAll('[data-btn]').length + 1);
        cont.appendChild(inp); inp.focus();
      });

    } else if (sub === 'list') {
      box.innerHTML = '<label class="ib-plt-f"><span>Texto del botón que abre la lista</span>'
        + '<input type="text" id="it-lbtn" maxlength="20" placeholder="Ver opciones"></label>'
        + '<div class="ib-plt-f"><span>Opciones <small>(máx. 10, 24 caracteres)</small></span>'
        + '<div id="it-opts">'
        + '<input type="text" data-opt maxlength="24" placeholder="Opción 1" class="ib-int-in">'
        + '<input type="text" data-opt maxlength="24" placeholder="Opción 2" class="ib-int-in">'
        + '</div>'
        + '<button type="button" class="btn btn-ghost btn-sm" id="it-add-opt">+ Agregar opción</button></div>';
      document.getElementById('it-add-opt').addEventListener('click', function () {
        var cont = document.getElementById('it-opts');
        if (cont.querySelectorAll('[data-opt]').length >= 10) return;
        var inp = document.createElement('input');
        inp.type = 'text'; inp.setAttribute('data-opt', ''); inp.maxLength = 24; inp.className = 'ib-int-in';
        inp.placeholder = 'Opción ' + (cont.querySelectorAll('[data-opt]').length + 1);
        cont.appendChild(inp); inp.focus();
      });

    } else if (sub === 'cta_url') {
      box.innerHTML = '<label class="ib-plt-f"><span>Texto del botón</span>'
        + '<input type="text" id="it-ctxt" maxlength="20" placeholder="Abrir enlace"></label>'
        + '<label class="ib-plt-f"><span>Dirección web</span>'
        + '<input type="url" id="it-curl" placeholder="https://…"></label>';

    } else {   // contact
      box.innerHTML = '<label class="ib-plt-f"><span>Nombre</span>'
        + '<input type="text" id="it-cn" placeholder="Nombre del contacto"></label>'
        + '<label class="ib-plt-f"><span>Teléfono</span>'
        + '<input type="tel" id="it-ct" placeholder="Ej.: 51987654321"></label>'
        + '<label class="ib-plt-f"><span>Empresa <small>(opcional)</small></span>'
        + '<input type="text" id="it-ce" placeholder="Empresa"></label>';
    }
  }
  // ── Envío ──
  function enviar() {
    // Guardia anti doble envío: el mismo "Enviar" puede dispararse por la tecla
    // Enter (keydown) y por el clic delegado; sin este flag el mensaje se
    // mandaba dos veces porque ambos corren antes de deshabilitar el botón.
    if (st.enviando) return;

    var inp = $('#ib-texto'), btn = $('#ib-enviar');
    if (!inp || !st.sel) return;

    var texto = textoActual().trim();

    // Con adjunto puesto, Enviar manda el archivo con el texto como pie
    if (st.adjunto) { enviarAdjunto(texto, btn, inp); return; }

    if (!texto) return;

    st.enviando = true;
    inp.setAttribute('contenteditable', 'false');
    if (btn) { btn.disabled = true; btn.textContent = 'Enviando…'; }

    // Restaura la caja pase lo que pase, para que nunca quede en "Enviando…"
    function restaurar() {
      st.enviando = false;
      if (!document.body.contains(inp)) return;   // el hilo ya se re-renderizó
      inp.setAttribute('contenteditable', 'true');
      if (btn) { btn.disabled = false; btn.textContent = 'Enviar'; }
      inp.focus();
    }

    var datos = { accion: 'enviar', id: st.sel, texto: texto };
    if (st.responder) datos.responder_a = st.responder.id;

    ggiPost('ajax.php', datos)
      .then(function (j) {
        if (j.ok) { inp.innerHTML = ''; quitarResponder(); }
        // Se recarga el hilo en ambos casos: si falló, el mensaje queda
        // registrado como fallido con el motivo real de Meta a la vista.
        abrir(st.sel);
      })
      .catch(function () {
        if (typeof showToast === 'function') showToast('No se pudo enviar el mensaje', 'error');
      })
      .then(restaurar);
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

    // Todo se abre en modal (ggiOpenForm): salir del inbox perdería el hilo
    // que el ejecutivo está atendiendo.
    // Ícono de "abrir" pegado al texto, no empujado al borde: con
    // space-between quedaba una flecha suelta al final de la fila.
    var iAbrir = '<svg class="ib-ctx-ico" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">'
               + '<path stroke-linecap="round" stroke-linejoin="round" '
               + 'd="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6M15 3h6v6M10 14L21 3"/></svg>';

    h += sec('Empresa', iEmp, c.cliente_id
      ? '<button type="button" class="ib-ctx-a" data-ver="../clientes/ver.php?id=' + c.cliente_id + '" '
        + 'data-titulo="' + esc(c.titulo) + '">'
        + '<span class="ib-ctx-nom">' + esc(c.titulo) + '</span>' + iAbrir + '</button>'
        + (c.ruc ? '<div class="ib-ctx-s2">RUC ' + esc(c.ruc) + '</div>' : '')
      : '<div class="ib-ctx-s2">Número no vinculado a ninguna empresa</div>');

    if (x.propuestas.length) {
      h += sec('Propuestas', '', x.propuestas.map(function (p) {
        return '<button type="button" class="ib-ctx-a" data-ver="../cotizaciones/ver.php?id=' + p.id + '" '
             + 'data-titulo="Propuesta ' + esc(p.numero) + '">'
             + '<span class="ib-ctx-nom">' + esc(p.numero) + '</span>'
             + '<span class="ib-ctx-est">' + esc(p.estado) + '</span>' + iAbrir + '</button>';
      }).join(''));
    }
    if (x.envios.length) {
      h += sec('Envíos', '', x.envios.map(function (e) {
        return '<button type="button" class="ib-ctx-a" data-ver="../envios/ver.php?id=' + e.id + '" '
             + 'data-titulo="Envío ' + esc(e.codigo_envio || '') + '">'
             + '<span class="ib-ctx-nom">' + esc(e.codigo_envio || '—') + '</span>' + iAbrir + '</button>';
      }).join(''));
    }

    var iEtq = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M3 6l0 5.2a2 2 0 00.6 1.4l7.8 7.8a2 2 0 002.8 0l5.2-5.2a2 2 0 000-2.8l-7.8-7.8A2 2 0 0010.2 4L5 4a2 2 0 00-2 2z"/></svg>';
    var puestas = (j.etiquetas || []).map(function (e) {
      return '<span class="ib-etq ' + esc(e.color || 'neutral') + '">' + esc(e.nombre)
           + '<button type="button" class="ib-etq-quitar" data-quitar="' + e.id + '" '
           + 'title="Quitar etiqueta" aria-label="Quitar etiqueta ' + esc(e.nombre) + '">&times;</button></span>';
    }).join(' ');

    h += sec('Etiquetas', iEtq,
        '<div style="display:flex;flex-wrap:wrap;gap:5px;margin-bottom:8px;">'
      + (puestas || '<span class="ib-ctx-s2">Sin etiquetas</span>') + '</div>'
      + '<button type="button" class="btn btn-ghost ib-btn-sm" id="ib-add-etq">+ Editar</button>');

    h += sec('Ventana', iReloj, c.ventana_abierta
      ? '<span class="ib-etq ok">Ventana abierta</span>'
      : '<span class="ib-etq warn">Ventana vencida</span>');

    var iEstado = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>';
    var resuelta = c.estado === 'resuelta';
    h += sec('Estado', iEstado,
        '<div class="ib-estado">'
      +   '<div class="ib-ctx-v"><span class="ib-punto' + (resuelta ? '' : ' viva') + '"></span>'
      +     (resuelta ? 'Resuelta' : 'Abierta') + '</div>'
      +   '<div class="ib-ctx-s2">' + (resuelta ? 'Cerrada para atención.' : 'Activa para atención.') + '</div>'
      + '</div>'
      + '<button type="button" class="ib-resolver" id="ib-resolver" data-estado="' + esc(c.estado) + '">'
      +   (resuelta ? '↺ Reabrir conversación' : '✓ Resolver conversación') + '</button>');

    $('#ib-ctx').innerHTML = '<div class="ib-ctx-head">Contexto</div>' + h;
  }

  // ── Menús emergentes ───────────────────────────────────────────────────────
  function cerrarPop() {
    var p = document.getElementById('ib-pop');
    if (p) p.remove();
  }

  function abrirPop(ancla, html, opts) {
    opts = opts || {};
    cerrarPop();
    var r = ancla.getBoundingClientRect();
    var d = document.createElement('div');
    d.className = 'ib-pop';
    d.id = 'ib-pop';
    d.innerHTML = html;
    document.body.appendChild(d);
    // position:fixed para no depender del contenedor con overflow del inbox
    d.style.position = 'fixed';
    // opts.arriba: abrir HACIA ARRIBA (el panel queda por encima del ancla, sin
    // tapar la caja de texto), como el picker de emojis de WhatsApp. Si no cabe
    // arriba, cae hacia abajo.
    var top;
    if (opts.arriba) {
      top = r.top - d.offsetHeight - 6;
      if (top < 10) top = Math.min(r.bottom + 6, window.innerHeight - d.offsetHeight - 10);
    } else {
      top = Math.min(r.bottom + 6, window.innerHeight - d.offsetHeight - 10);
    }
    d.style.top  = Math.max(10, top) + 'px';
    d.style.left = Math.max(10, Math.min(r.left, window.innerWidth - d.offsetWidth - 10)) + 'px';
    return d;
  }

  // Etiquetas de la conversación abierta
  function popEtiquetas(ancla) {
    if (!st.sel) return;
    fetch('ajax.php?accion=etiquetas', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j.ok) return;
        var puestas = (st.etqActuales || []).map(function (e) { return e.id; });
        abrirPop(ancla, j.items.map(function (e) {
          var on = puestas.indexOf(e.id) !== -1;
          return '<button type="button" class="ib-pop-item" data-etq="' + e.id + '" data-on="' + (on ? 1 : 0) + '">'
               + '<span class="ib-etq ' + esc(e.color) + '">' + esc(e.nombre) + '</span>'
               + (on ? '<span style="margin-left:auto;color:var(--ac)">✓</span>' : '') + '</button>';
        }).join(''));
      });
  }

  // Filtro por etiqueta
  function popFiltroEtiquetas(ancla) {
    fetch('ajax.php?accion=etiquetas', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j.ok) return;
        var d = abrirPop(ancla,
            '<input type="search" id="ib-etqq" placeholder="Buscar etiqueta…" aria-label="Buscar etiqueta">'
          + '<div id="ib-etql"></div>');
        d.dataset.pop = 'filtro';   // marca para poder cerrarlo con el mismo ícono

        var check = '<span style="margin-left:auto;color:var(--ac)">✓</span>';
        function pintar(txt) {
          txt = (txt || '').toLowerCase();
          d.querySelector('#ib-etql').innerHTML =
              '<button type="button" class="ib-pop-item" data-fetq="0">'
            + '<span style="color:var(--tx2)">Todas las etiquetas</span>'
            + (st.etqs.length ? '' : check) + '</button>'
            + j.items.filter(function (e) { return e.nombre.toLowerCase().indexOf(txt) !== -1; })
                .map(function (e) {
                  var on = st.etqs.indexOf(e.id) !== -1;
                  return '<button type="button" class="ib-pop-item" data-fetq="' + e.id + '" data-nom="' + esc(e.nombre) + '">'
                       + '<span class="ib-etq ' + esc(e.color) + '">' + esc(e.nombre) + '</span>'
                       + (on ? check : '') + '</button>';
                }).join('');
        }
        pintar('');
        // Multi-selección viva: cada clic alterna una etiqueta y re-aplica el
        // filtro SIN cerrar el panel. "Todas" limpia la selección.
        d.querySelector('#ib-etql').addEventListener('click', function (e) {
          var b = e.target.closest('[data-fetq]');
          if (!b) return;
          e.stopPropagation();
          var id = parseInt(b.dataset.fetq, 10) || 0;
          if (!id) { st.etqs = []; }
          else {
            var i = st.etqs.indexOf(id);
            if (i === -1) st.etqs.push(id); else st.etqs.splice(i, 1);
          }
          pintar(d.querySelector('#ib-etqq').value);
          aplicarFiltroEtq();
        });
        var inp = d.querySelector('#ib-etqq');
        inp.focus();
        inp.addEventListener('input', function () { pintar(inp.value); });
      });
  }

  // Refleja st.etqs en el chip y el botón, y recarga la lista.
  function aplicarFiltroEtq() {
    var n = st.etqs.length;
    var chip = $('#ib-etq-activa');
    if (chip) {
      chip.style.display = n ? '' : 'none';
      chip.textContent   = n ? (n === 1 ? '1 etiqueta ×' : n + ' etiquetas ×') : '';
      chip.classList.toggle('on', n > 0);
    }
    var bEtq = $('#ib-filtro-etq');
    if (bEtq) bEtq.classList.toggle('on', n > 0);
    lista();
  }

  // Menú (…) de una conversación: fijar, archivar, marcar no leída, silenciar.
  function popMenuConv(ancla) {
    var id   = ancla.dataset.menu;
    var fij  = ancla.dataset.fij  === '1';
    var sil  = ancla.dataset.sil  === '1';
    var arch = ancla.dataset.arch === '1';
    function fila(accion, ico, txt) {
      return '<button type="button" class="ib-pop-item" data-convmenu="' + accion + '" data-cid="' + id + '">'
           + '<span style="display:inline-flex;color:var(--tx3)">' + svg(ico, 15) + '</span>' + txt + '</button>';
    }
    var d = abrirPop(ancla,
        fila(fij  ? 'desfijar'  : 'fijar',   'pin',      fij  ? 'Desfijar' : 'Fijar arriba')
      + fila('no_leido', 'unread', 'Marcar como no leída')
      + fila(sil  ? 'activar'   : 'silenciar', 'bell_off', sil ? 'Activar avisos' : 'Silenciar avisos')
      + fila(arch ? 'desarchivar' : 'archivar', 'archive', arch ? 'Desarchivar' : 'Archivar'));
    d.dataset.pop = 'convmenu';   // marca para poder cerrarlo con el mismo ícono
  }

  // Ejecuta una acción del menú (…) y refresca la lista.
  function accionMenuConv(accion, cid) {
    cerrarPop();
    if (accion === 'no_leido') {
      ggiPost('ajax.php', { accion: 'sin_leer', id: cid }).then(function (j) { if (j.ok) lista(); });
      return;
    }
    var mapa = {
      fijar:      ['fijada', 1],     desfijar:    ['fijada', 0],
      silenciar:  ['silenciada', 1], activar:     ['silenciada', 0],
      archivar:   ['archivada', 1],  desarchivar: ['archivada', 0]
    };
    var m = mapa[accion];
    if (!m) return;
    ggiPost('ajax.php', { accion: 'conv_flag', id: cid, campo: m[0], valor: m[1] })
      .then(function (j) {
        if (!j.ok) { if (window.showToast) showToast(j.msg || 'No se pudo aplicar', 'error'); return; }
        // Al archivar la conversación abierta, se limpia el hilo
        if (accion === 'archivar' && String(st.sel) === String(cid)) {
          st.sel = null;
          $('#ib').classList.remove('abierto');
          $('#ib-chat').innerHTML = '<div class="ib-empty">Elige una conversación para ver el hilo</div>';
          $('#ib-ctx').innerHTML = '';
        }
        lista();
      });
  }

  // Nueva conversación: busca empresa por nombre/RUC/teléfono
  function popNueva(ancla) {
    var d = abrirPop(ancla,
        '<input type="search" id="ib-nq" placeholder="Empresa, RUC o teléfono…" aria-label="Buscar contacto o teléfono">'
      + '<div id="ib-nres"><div class="ib-pop-item" style="color:var(--tx3)">Escribe una empresa o un número…</div></div>');

    var inp = d.querySelector('#ib-nq'); inp.focus();
    var t;
    inp.addEventListener('input', function () {
      clearTimeout(t);
      var q = inp.value.trim();
      // Si escribió un número (9+ dígitos), ofrecer escribirle directo aunque no
      // esté registrado. Los móviles peruanos de 9 dígitos se prefijan con 51.
      var digitos = q.replace(/\D/g, '');
      var telOpc = '';
      if (digitos.length >= 9) {
        var telNorm = digitos.length === 9 ? '51' + digitos : digitos;
        telOpc = '<button type="button" class="ib-pop-item" data-tel="' + telNorm + '">'
               + '<span>Escribir a +' + telNorm + '<small>Número nuevo (fuera de ventana → se envía una plantilla)</small></span>'
               + '</button>';
      }
      t = setTimeout(function () {
        fetch('ajax.php?accion=buscar_contacto&q=' + encodeURIComponent(q),
              { credentials: 'same-origin', cache: 'no-store' })
          .then(function (r) { return r.json(); })
          .then(function (j) {
            var box = d.querySelector('#ib-nres');
            if (!box) return;
            var empresas = (j.items && j.items.length)
              ? j.items.map(function (c) {
                  return '<button type="button" class="ib-pop-item" data-tel="' + esc(c.contacto_telefono) + '">'
                       + '<span>' + esc(c.razon_social)
                       + '<small>+' + esc(c.contacto_telefono) + (c.ruc ? ' · RUC ' + esc(c.ruc) : '') + '</small></span>'
                       + '</button>';
                }).join('')
              : '';
            box.innerHTML = telOpc + empresas
              || '<div class="ib-pop-item" style="color:var(--tx3)">Sin resultados. Escribe un número para chatear.</div>';
          });
      }, 280);
    });
  }

  // ── Reasignación (solo gerencia) ───────────────────────────────────────────
  function formReasignar() {
    if (!st.sel) return;

    fetch('ajax.php?accion=equipo&id=' + st.sel, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j.ok) return;

        // Lista de personas con avatar (en vez del <select> básico). Sin el
        // "(gerencia)" entre paréntesis. Se elige haciendo clic en la fila.
        var opts = '<div class="ib-ra-lista" id="ra-lista">'
          + '<button type="button" class="ib-ra-op" data-ra="">'
          +   '<span class="ib-av gris">–</span><span>Dejarla libre</span></button>'
          + j.usuarios.map(function (u) {
              return '<button type="button" class="ib-ra-op" data-ra="' + u.id + '">'
                   + '<span class="ib-av ' + colorDe(u.nombre) + '">' + esc(ini(u.nombre)) + '</span>'
                   + '<span>' + esc(u.nombre) + '</span></button>';
            }).join('')
          + '</div>';

        var hist = j.historial.length
          ? '<div class="ib-hist-t">Historial</div>' + j.historial.map(function (h) {
              var q = { tomar: 'tomó', soltar: 'soltó', reasignar: 'reasignó', quitar: 'quitó la asignación' }[h.accion] || h.accion;
              return '<div class="ib-hist-i"><b>' + esc(h.por_nombre || '—') + '</b> ' + q
                   + (h.a_nombre ? ' → <b>' + esc(h.a_nombre) + '</b>' : '')
                   + (h.motivo ? ' · ' + esc(h.motivo) : '')
                   + '<small>' + esc(h.created_at) + '</small></div>';
            }).join('')
          : '';

        mantOpen('Reasignar conversación',
            '<div class="ib-plt-f"><span>Pasar a</span>' + opts + '</div>'
          + '<label class="ib-plt-f"><span>Motivo (opcional)</span>'
          + '<input type="text" id="ra-m" maxlength="200" placeholder="Ej.: de vacaciones, carga de trabajo"></label>'
          + '<p class="ib-hist-nota">Queda registrado quién hizo el cambio. Si el ejecutivo estaba '
          + 'atendiendo, pierde el acceso a escribir de inmediato.</p>'
          + hist
          + '<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">'
          + '<button type="button" class="ggi-btn-confirm-cancel" onclick="ggiModalClose()">Cancelar</button>'
          + '<button type="button" class="ggi-btn-confirm-action" id="ra-ok">Reasignar</button></div>');

        // Selección: al tocar una fila se marca como elegida (una sola)
        var lista = document.getElementById('ra-lista');
        lista.addEventListener('click', function (e) {
          var op = e.target.closest('.ib-ra-op');
          if (!op) return;
          lista.querySelectorAll('.ib-ra-op').forEach(function (b) { b.classList.remove('sel'); });
          op.classList.add('sel');
        });

        document.getElementById('ra-ok').addEventListener('click', function () {
          var sel = lista.querySelector('.ib-ra-op.sel');
          if (!sel) { if (window.showToast) showToast('Elige a quién pasar la conversación', 'error'); return; }
          ggiPost('ajax.php', {
            accion: 'reasignar', id: st.sel,
            a_usuario: sel.dataset.ra,
            motivo: document.getElementById('ra-m').value.trim()
          }).then(function (r) {
            if (!r.ok) return;
            ggiModalClose();
            if (typeof showToast === 'function') showToast('Conversación reasignada', 'ok');
            st.firma = null;
            abrir(st.sel); lista();
          });
        });
      });
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

  // Al pulsar un botón de la barra, el navegador mueve el foco y DESCARTA la
  // selección del campo editable: envolver() encontraba el cursor en 0 y no
  // aplicaba el formato al texto marcado. Cancelar el mousedown conserva la
  // selección; el click sigue funcionando igual.
  document.addEventListener('mousedown', function (e) {
    if (e.target.closest('.ib-fmt[data-fmt]') || e.target.closest('[data-emoji]')) {
      e.preventDefault();
    }
  });

  document.addEventListener('click', function (e) {
    // Estado del popover ANTES de que este mismo clic lo cierre, para permitir
    // que el ícono de filtro funcione como toggle (abrir/cerrar).
    var _popPrev = document.getElementById('ib-pop');
    var _eraFiltro = !!(_popPrev && _popPrev.dataset.pop === 'filtro');

    // Empresa / propuesta / envío en modal: salir del inbox perdería el hilo
    var ver = e.target.closest('[data-ver]');
    if (ver) {
      if (typeof ggiOpenForm === 'function') ggiOpenForm(ver.dataset.ver, ver.dataset.titulo || '');
      else window.open(ver.dataset.ver, '_blank');
      return;
    }

    // Volver a la lista (móvil)
    if (e.target.closest('#ib-volver')) {
      $('#ib').classList.remove('abierto');
      st.sel = null;
      return;
    }

    // Colapsar / mostrar paneles laterales
    var tg = e.target.closest('[data-panel]');
    if (tg) {
      var cual = tg.dataset.panel;
      $('#ib').classList.toggle('sin-' + cual);
      try { localStorage.setItem('ib_sin_' + cual, $('#ib').classList.contains('sin-' + cual) ? '1' : ''); } catch (_) {}
      return;
    }

    // ── Menús emergentes ──
    if (e.target.closest('#ib-nueva'))     { popNueva(e.target.closest('#ib-nueva')); return; }
    if (e.target.closest('#ib-filtro-etq')){
      if (_eraFiltro) { cerrarPop(); return; }   // ya estaba abierto → cerrar (toggle)
      popFiltroEtiquetas(e.target.closest('#ib-filtro-etq')); return;
    }
    if (e.target.closest('#ib-add-etq'))   { popEtiquetas(e.target.closest('#ib-add-etq')); return; }

    var elegido = e.target.closest('[data-tel]');
    if (elegido) {
      cerrarPop();
      ggiPost('ajax.php', { accion: 'abrir_contacto', telefono: elegido.dataset.tel })
        .then(function (j) { if (j.ok) { lista(); abrir(j.id); } });
      return;
    }

    // El clic sobre etiquetas del filtro (data-fetq) lo maneja el propio panel
    // (multi-selección viva en popFiltroEtiquetas), no este manejador global.

    var tog = e.target.closest('[data-etq]');
    if (tog) {
      cerrarPop();
      ggiPost('ajax.php', {
        accion: 'etiquetar', id: st.sel,
        etiqueta_id: tog.dataset.etq,
        activar: tog.dataset.on === '1' ? '' : '1'
      }).then(function (j) { if (j.ok) { abrir(st.sel); lista(); } });
      return;
    }

    var res = e.target.closest('#ib-resolver');
    if (res) {
      var vuelve = res.dataset.estado === 'resuelta';
      ggiPost('ajax.php', { accion: vuelve ? 'reabrir' : 'resolver', id: st.sel }).then(function (j) {
        if (!j.ok) return;
        if (typeof showToast === 'function') {
          showToast(vuelve ? 'Conversación reabierta' : 'Conversación resuelta', 'ok');
        }
        // Al resolver sale del listado (que excluye resueltas): se limpia el hilo
        if (!vuelve) {
          st.sel = null;
          $('#ib').classList.remove('abierto');
          $('#ib-ctx').innerHTML = '';
          $('#ib-chat').innerHTML = '<div class="ib-empty">Elige una conversación para ver el hilo</div>';
        }
        else abrir(st.sel);
        lista();
      });
      return;
    }

    var quitar = e.target.closest('[data-quitar]');
    if (quitar) {
      ggiPost('ajax.php', { accion: 'etiquetar', id: st.sel, etiqueta_id: quitar.dataset.quitar, activar: '' })
        .then(function (j) { if (j.ok) { abrir(st.sel); lista(); } });
      return;
    }

    // Quitar el filtro de etiquetas desde su propio chip
    if (e.target.closest('#ib-etq-activa')) {
      st.etqs = [];
      aplicarFiltroEtq();
      return;
    }

    if (!e.target.closest('.ib-pop')) cerrarPop();

    var p = e.target.closest('.ib-pill');
    if (p) {
      document.querySelectorAll('.ib-pill').forEach(function (b) { b.classList.remove('on'); });
      p.classList.add('on'); st.f = p.dataset.f; lista(); return;
    }
    if (e.target.closest('#ib-asignar')) { accionar('asignar', st.sel); return; }
    if (e.target.closest('#ib-liberar')) { accionar('liberar', st.sel); return; }
    if (e.target.closest('#ib-reasignar')) { formReasignar(); return; }
    var llb = e.target.closest('#ib-llamar');
    if (llb) {
      if (window.SoftphoneLlamar && st.sel) window.SoftphoneLlamar(st.sel, llb.dataset.nombre || '');
      return;
    }
    if (e.target.closest('#ib-enviar'))  { enviar(); return; }

    // Formato de WhatsApp: envuelve la selección con los marcadores
    var fmt = e.target.closest('.ib-fmt[data-fmt]');
    if (fmt) { envolver(fmt.dataset.fmt); return; }

    if (e.target.closest('#ib-emoji')) {
      if (_popPrev && _popPrev.dataset.pop === 'emoji') { cerrarPop(); return; }  // toggle
      popEmojis(e.target.closest('#ib-emoji')); return;
    }

    if (e.target.closest('#ib-adjunto')) { modalAdjuntar(); return; }

    if (e.target.closest('#ib-adj-quitar')) { quitarAdjunto(); return; }

    if (e.target.closest('#ib-plantilla')) { popPlantillas(); return; }
    if (e.target.closest('#ib-interactivo')) { formInteractivo(); return; }
    if (e.target.closest('#ib-voz'))        { toggleVoz(); return; }

    var rep = e.target.closest('[data-reply]');
    if (rep) { ponerResponder(rep.dataset.reply); return; }
    if (e.target.closest('#ib-resp-quitar')) { quitarResponder(); return; }
    if (e.target.closest('#ib-voz-stop'))   { if (_rec) _rec.stop(); return; }
    if (e.target.closest('#ib-voz-cancel'))  { cancelarVoz(); return; }

    // Reaccionar a un mensaje: abre un mini-selector de emoji
    var rxb = e.target.closest('[data-rx]');
    if (rxb) {
      var mid = rxb.dataset.rx;
      var EMORX = ['👍','❤️','😂','😮','😢','🙏','🔥','✅'];
      abrirPop(rxb, '<div class="ib-rx-pick">' + EMORX.map(function (x) {
        return '<button type="button" data-rxe="' + mid + '" data-emo="' + x + '">' + x + '</button>';
      }).join('') + '</div>');
      return;
    }
    var rxe = e.target.closest('[data-rxe]');
    if (rxe) {
      cerrarPop();
      ggiPost('ajax.php', { accion: 'reaccionar', id: st.sel, mensaje_id: rxe.dataset.rxe, emoji: rxe.dataset.emo })
        .then(function (r) { if (r.ok) { st.firma = null; abrir(st.sel); } });
      return;
    }

    // Abrir el menú (…) de una conversación desde la fila del listado
    var mn = e.target.closest('[data-menu]');
    if (mn) {
      e.stopPropagation();
      if (_popPrev && _popPrev.dataset.pop === 'convmenu') { cerrarPop(); return; }  // toggle
      popMenuConv(mn);
      return;
    }
    // Acción elegida dentro de ese menú
    var cm = e.target.closest('[data-convmenu]');
    if (cm) {
      e.stopPropagation();
      accionMenuConv(cm.dataset.convmenu, cm.dataset.cid);
      return;
    }

    var elegida = e.target.closest('.ib-plt');
    if (elegida && _plt) { formPlantilla(_plt[parseInt(elegida.dataset.plt, 10)]); return; }

    var emo = e.target.closest('[data-emoji]');
    if (emo) { insertar(emo.dataset.emoji); return; }

    var stk = e.target.closest('[data-stk]');
    if (stk) {
      cerrarPop();
      ggiPost('ajax.php', { accion: 'enviar_sticker', id: st.sel, mensaje_id: stk.dataset.stk })
        .then(function (j) { if (j.ok) { st.firma = null; abrir(st.sel); } });
      return;
    }
    var it = e.target.closest('.ib-item');
    if (it) abrir(parseInt(it.dataset.id, 10));
  });

  var q = document.getElementById('ib-q');
  if (q) { var t; q.addEventListener('input', function () {
    clearTimeout(t); t = setTimeout(function () { st.q = q.value.trim(); lista(); }, 300); }); }

  // Polling: el monolito no tiene websockets. Pausa con la pestaña oculta.
  // Refresca TAMBIÉN el hilo abierto: antes había que recargar la página para
  // ver los mensajes nuevos de la conversación que estabas atendiendo.
  function refrescar() {
    if (document.hidden) return;
    lista(true);
    if (st.sel) abrir(st.sel, true);
  }
  setInterval(refrescar, 8000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) refrescar(); });

  // Restaurar paneles colapsados de la sesión anterior
  try {
    ['lista', 'ctx'].forEach(function (k) {
      if (localStorage.getItem('ib_sin_' + k)) $('#ib').classList.add('sin-' + k);
    });
  } catch (_) {}

  lista();
})();
</script>

<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
