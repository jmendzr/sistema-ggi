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
  .ib-ctx-a { font-size: .8rem; color: var(--ac); text-decoration: none;
              display: flex; justify-content: space-between; gap: 8px; padding: 5px 0; }
  .ib-ctx-a:hover { text-decoration: underline; }

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

  .ib-emojis { display: grid; grid-template-columns: repeat(8, 1fr); gap: 2px; max-height: 232px; overflow-y: auto; }
  .ib-emojis button {
    border: none; background: transparent; font-size: 1.15rem; line-height: 1;
    padding: 5px; border-radius: 6px; cursor: pointer; min-height: 32px;
  }
  .ib-emojis button:hover { background: var(--bg-hover); }

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
  /* .ib-ctx-a pasó de <a> a <button>: hay que reponer lo que el botón no hereda */
  .ib-ctx-a { width: 100%; border: none; background: transparent; cursor: pointer;
              font: inherit; align-items: center; }

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
    .ib, .ib.sin-lista, .ib.sin-ctx { grid-template-columns: 1fr; }
    .ib { height: calc(100dvh - 50px - 24px); border-radius: 12px; }
    .ib-chat-col { display: none; }
    .ib.abierto .ib-lista-col { display: none; }
    .ib.abierto .ib-chat-col  { display: flex; }
    .ib-volver { display: inline-flex; }
    /* En móvil la lista ocupa todo: el colapsar no aplica */
    .ib.sin-lista .ib-lista-col { display: flex; }
    .ib-b { max-width: 88%; }
    /* 16px evita el zoom automático de iOS al enfocar el campo */
    .ib-box input, .ib-search input, .ib-editable { font-size: 16px; }
    .ib-fmt { min-width: 38px; height: 38px; }
    .ib-emojis { grid-template-columns: repeat(7, 1fr); }
    .ib-emojis button { min-height: 40px; font-size: 1.3rem; }
    .ib-pop { max-width: calc(100vw - 24px); }
    .ib-item { padding: 12px 14px; }
    .ib-msgs { padding: 14px 12px; }
    .ib-foot { padding: 10px 12px calc(10px + var(--safe-bottom, 0px)); }
  }
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
      <div class="ib-pills">
        <button type="button" class="ib-pill on" data-f="todas">Todas<b data-c="todas"></b></button>
        <button type="button" class="ib-pill" data-f="mias">A mi cargo<b data-c="mias"></b></button>
        <button type="button" class="ib-pill" data-f="sin_asignar">Sin asignar<b data-c="sin_asignar"></b></button>
        <button type="button" class="ib-pill" data-f="sin_persona">Sin persona<b data-c="sin_persona"></b></button>
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

<script>
(function () {
  var st = { f: 'todas', q: '', sel: null, etq: 0, etqActuales: [], firma: null, adjunto: null };
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
  // Formato de WhatsApp → HTML. Se aplica SOBRE texto ya escapado, así que
  // no puede inyectar marcado: los < > del usuario ya son entidades.
  function formato(txt) {
    return esc(txt)
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
    fetch('ajax.php?accion=lista&filtro=' + encodeURIComponent(st.f)
          + '&q=' + encodeURIComponent(st.q)
          + '&etiqueta=' + (st.etq || 0),
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
            ? (it.es_mia ? '<span class="ib-tag mia">A mi cargo</span>'
                         : '<span class="ib-tag otro">' + esc(it.asignado_nombre || 'Asignada') + '</span>')
            : '<span class="ib-tag libre">Libre</span>';
          var viejo = (it.dias_asignada !== null && it.dias_asignada >= 3)
            ? '<span class="ib-tag viejo">' + it.dias_asignada + ' d sin mover</span>' : '';
          var etqs = (it.etiquetas || []).map(function (e) {
            return '<span class="ib-etq ' + esc(e.color || 'neutral') + '">' + esc(e.nombre) + '</span>';
          }).join('');

          return '<button type="button" class="ib-item' + (st.sel === it.id ? ' sel' : '') + '" data-id="' + it.id + '">'
            + '<span class="ib-av ' + colorDe(it.telefono) + (it.ventana_abierta ? ' viva' : '') + '">'
            +   esc(ini(it.titulo)) + '</span>'
            + '<span class="ib-item-body">'
            +   '<span class="ib-item-r1"><span class="ib-item-n">' + esc(it.titulo) + '</span>'
            +   '<span class="ib-item-t">' + relativo(it.cuando) + '</span></span>'
            +   '<span class="ib-item-ph">+' + esc(it.telefono) + '</span>'
            +   '<span class="ib-item-p">' + esc(it.preview) + '</span>'
            +   '<span class="ib-item-m">' + tag + viejo + etqs
            +     (it.no_leidos ? '<span class="ib-unread">' + it.no_leidos + '</span>' : '')
            +   '</span>'
            + '</span></button>';
        }).join('');
      }).catch(function () {});
  }

  // ── Hilo ──
  // silencioso = refresco automático: no debe pisar lo que el ejecutivo está
  // escribiendo, ni robarle el foco, ni saltarle el scroll si está leyendo
  // mensajes anteriores.
  function abrir(id, silencioso) {
    st.sel = id;
    $('#ib').classList.add('abierto');

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
    }

    fetch('ajax.php?accion=hilo&id=' + id, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j.ok) return;

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
      .catch(function () {});
  }

  function chat(j, previo) {
    var c = j.conv, ultDia = '', cuerpo = '';

    j.mensajes.forEach(function (m) {
      var dia = fechaLarga(m.cuando);
      if (dia !== ultDia) { cuerpo += '<div class="ib-sep">' + esc(dia) + '</div>'; ultDia = dia; }

      var cls = m.dir === 'in' ? 'in' : (m.autor ? 'out' : 'sis');
      var who = m.dir === 'out'
        ? '<div class="ib-b-who">' + esc(m.autor || 'Sistema · automático') + '</div>' : '';
      var err = (m.estado === 'failed' && m.error)
        ? '<div class="ib-b-err">' + esc(m.error) + '</div>' : '';

      // Adjuntos entrantes (imagen, sticker, gif, audio, documento): todavía
      // no se descarga el archivo, así que se indica el tipo en vez de dejar
      // la burbuja vacía y que parezca un mensaje perdido.
      var cuerpoMsg = '';

      // Adjuntos: el archivo se descarga de Meta la primera vez que se abre
      if (m.media) {
        var src = 'media.php?id=' + m.media;
        if (m.tipo === 'image' || m.tipo === 'sticker') {
          cuerpoMsg += '<img class="ib-img' + (m.tipo === 'sticker' ? ' ib-sticker' : '') + '" src="' + src + '" loading="lazy" '
                     + 'alt="' + esc(m.tipo === 'sticker' ? 'Sticker' : 'Imagen recibida') + '" '
                     + 'data-ggi-preview="' + src + '">';
        } else if (m.tipo === 'video') {
          cuerpoMsg += '<video class="ib-img" src="' + src + '" controls preload="metadata"></video>';
        } else if (m.tipo === 'audio') {
          cuerpoMsg += '<audio src="' + src + '" controls preload="none" style="max-width:100%"></audio>';
        } else {
          cuerpoMsg += '<a class="ib-doc" href="' + src + '" target="_blank" rel="noopener">'
                     + '📄 ' + esc(m.media_nombre || 'Documento') + '</a>';
        }
      } else if (m.tipo && m.tipo !== 'text' && !m.texto) {
        var etq = { image: '📷 Imagen', sticker: 'Sticker', video: '🎬 Video',
                    audio: '🎤 Audio', document: '📄 Documento', location: '📍 Ubicación' };
        cuerpoMsg += '<span class="ib-adj">' + (etq[m.tipo] || m.tipo) + '</span>';
      }

      if (m.texto) cuerpoMsg += (cuerpoMsg ? '<div class="ib-pie-foto">' : '')
                              + formato(m.texto) + (cuerpoMsg ? '</div>' : '');

      cuerpo += '<div class="ib-b ' + cls + '">' + who + cuerpoMsg + err
             +  '<div class="ib-b-foot">' + hhmm(m.cuando)
             +  (m.dir === 'out' ? ' <span>' + ticks(m.estado) + '</span>' : '') + '</div></div>';
    });

    // Acción de asignación: el candado que pediste
    var accion = c.asignado_a
      ? (c.puede_liberar
          ? '<button type="button" class="btn btn-ghost ib-btn-sm" id="ib-liberar">Soltar</button>'
          : '<span class="ib-tag otro">Atiende ' + esc(c.asignado_nombre) + '</span>')
      : '<button type="button" class="btn btn-primary ib-btn-sm" id="ib-asignar">Tomar conversación</button>';

    // Meta solo admite texto libre dentro de las 24h desde el último entrante
    var pie;
    if (!c.ventana_abierta) {
      pie = '<div class="ib-warn">'
          + '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">'
          + '<circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 7v5l3 2"/></svg>'
          + '<span class="ib-warn-b"><strong>Ventana de 24 h vencida</strong>'
          + 'Solo se pueden enviar plantillas aprobadas hasta que el contacto responda.</span>'
          + (c.es_mia
              ? '<button type="button" class="btn btn-primary ib-btn-sm" id="ib-plantilla">Enviar plantilla</button>'
              : '<button type="button" class="btn btn-primary ib-btn-sm" disabled '
                + 'title="Toma la conversación para poder responder">Enviar plantilla</button>')
          + '</div>';
    } else if (!c.es_mia) {
      pie = '<div class="ib-box">'
          + '<input type="text" placeholder="' + (c.asignado_a ? 'La atiende ' + esc(c.asignado_nombre) : 'Asígnate la conversación para responder') + '" disabled aria-label="Mensaje">'
          + '<button type="button" class="btn btn-primary ib-btn-sm" disabled>Enviar</button>'
          + '</div>';
    } else {
      // Franja del adjunto: vive DENTRO del compositor, no en una ventana
      // aparte, para poder escribir el mensaje junto a la imagen.
      pie = '<div id="ib-adj-prev"></div>'
          + '<div class="ib-barra">'
          + '<button type="button" class="ib-fmt" data-fmt="*" title="Negrita (*texto*)" aria-label="Negrita"><b>B</b></button>'
          + '<button type="button" class="ib-fmt" data-fmt="_" title="Cursiva (_texto_)" aria-label="Cursiva"><i>I</i></button>'
          + '<button type="button" class="ib-fmt" data-fmt="~" title="Tachado (~texto~)" aria-label="Tachado"><s>S</s></button>'
          + '<button type="button" class="ib-fmt" data-fmt="```" title="Monoespaciado" aria-label="Monoespaciado">&lt;/&gt;</button>'
          + '<span class="ib-barra-sep"></span>'
          + '<button type="button" class="ib-fmt" id="ib-emoji" title="Emojis" aria-label="Insertar emoji">☺</button>'
          + '<button type="button" class="ib-fmt" id="ib-adjunto" title="Adjuntar archivo" aria-label="Adjuntar archivo">📎</button>'
          + '<input type="file" id="ib-file" hidden '
          + 'accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/3gpp,audio/*,'
          + '.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt">'
          + '</div>'
          + '<div class="ib-box">'
          // contenteditable, no <input>: un input no puede mostrar el texto
          // con formato mientras se escribe.
          + '<div id="ib-texto" class="ib-editable" contenteditable="true" role="textbox"'
          + ' aria-multiline="true" aria-label="Mensaje" data-ph="Escribe un mensaje…"></div>'
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
      +   '</div>' + accion
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
      var plano = el.innerText.replace(/\n$/, '');
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
    el.focus();
    var o = _offsets(el), v = el.innerText.replace(/\n$/, '');
    var sel = v.slice(o.a, o.b) || 'texto';
    repintar_con(el, v.slice(0, o.a) + marca + sel + marca + v.slice(o.b),
                 o.a + marca.length + sel.length + marca.length);
  }

  function insertar(txt) {
    var el = $('#ib-texto');
    if (!el) return;
    el.focus();
    var o = _offsets(el), v = el.innerText.replace(/\n$/, '');
    repintar_con(el, v.slice(0, o.a) + txt + v.slice(o.b), o.a + txt.length);
  }

  function repintar_con(el, plano, pos) {
    el.innerHTML = formatoVivo(plano);
    _setCursor(el, Math.min(pos, plano.length));
  }

  var EMOJIS = ('😀 😃 😄 😁 😅 😂 🙂 😉 😊 😍 😘 🤗 🤔 😐 😴 😎 🥳 😢 😭 😡 '
    + '👍 👎 👌 🙏 👏 💪 🤝 👋 ✍️ 🫡 ❤️ 🔥 ⭐ ✅ ❌ ⚠️ ℹ️ 📌 📎 📄 '
    + '📅 ⏰ 📦 🚚 💰 💳 🧾 🏢 📞 📧 🔒 🎯 🎉 🙌 ✨').split(' ');

  // Un solo panel con pestañas, como WhatsApp: emojis y stickers juntos.
  // Los stickers son los ya usados en conversaciones (propios y recibidos):
  // no existe un catálogo que ofrecer, pero el historial sí sirve.
  function popEmojis(ancla) {
    var d = abrirPop(ancla,
        '<div class="ib-tabs">'
      +   '<button type="button" class="ib-tab on" data-tab="emoji">Emojis</button>'
      +   '<button type="button" class="ib-tab" data-tab="sticker">Stickers</button>'
      + '</div>'
      + '<div id="ib-panel-emoji"><div class="ib-emojis">' + EMOJIS.map(function (x) {
          return '<button type="button" data-emoji="' + x + '" aria-label="' + x + '">' + x + '</button>';
        }).join('') + '</div></div>'
      + '<div id="ib-panel-sticker" hidden><div class="ib-emojis ib-stk" id="ib-stk-lista">'
      +   '<div style="grid-column:1/-1;padding:14px;color:var(--tx3);font-size:.78rem">Cargando…</div>'
      + '</div></div>');

    d.addEventListener('click', function (e) {
      var t = e.target.closest('.ib-tab');
      if (!t) return;
      d.querySelectorAll('.ib-tab').forEach(function (b) { b.classList.toggle('on', b === t); });
      d.querySelector('#ib-panel-emoji').hidden   = t.dataset.tab !== 'emoji';
      d.querySelector('#ib-panel-sticker').hidden = t.dataset.tab !== 'sticker';
      if (t.dataset.tab === 'sticker') cargarStickers(d);
    });
  }

  function cargarStickers(d) {
    var caja = d.querySelector('#ib-stk-lista');
    if (!caja || caja.dataset.listo) return;

    fetch('ajax.php?accion=stickers', { credentials: 'same-origin' })
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
    else if (a.mime.indexOf('audio/') === 0) mini = '<span class="ib-adj-ico">🎤</span>';
    else                                     mini = '<span class="ib-adj-ico">📄</span>';

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

  // ── Vista previa del adjunto antes de enviarlo (queda sin uso) ─────────────
  function previewAdjunto(archivo, inputFile) {
    var url  = URL.createObjectURL(archivo);
    var mime = archivo.type || '';
    var kb   = archivo.size / 1024;
    var peso = kb < 1024 ? Math.round(kb) + ' KB' : (kb / 1024).toFixed(1) + ' MB';

    // El sticker no admite pie de foto: Meta rechaza el mensaje si se manda
    var esSticker = mime === 'image/webp';
    var esGif     = mime === 'image/gif';

    var vista;
    if (mime.indexOf('image/') === 0) {
      vista = '<img src="' + url + '" alt="Vista previa" class="ib-prev-img">';
    } else if (mime.indexOf('video/') === 0) {
      vista = '<video src="' + url + '" class="ib-prev-img" controls preload="metadata"></video>';
    } else if (mime.indexOf('audio/') === 0) {
      vista = '<audio src="' + url + '" controls style="width:100%"></audio>';
    } else {
      vista = '<div class="ib-prev-doc">📄 ' + esc(archivo.name) + '</div>';
    }

    mantOpen('Enviar archivo',
        '<div class="ib-prev">' + vista + '</div>'
      + '<div class="ib-prev-meta">' + esc(archivo.name) + ' · ' + peso
      + (esSticker ? ' · se enviará como <b>sticker</b>' : '')
      + (esGif ? ' · <b>GIF</b>: se convertirá a sticker animado para que se reproduzca' : '')
      + '</div>'
      + (esSticker
          ? '<p class="ib-prev-nota">Los stickers no admiten texto acompañante.</p>'
          : '<label class="ib-plt-f"><span>Mensaje (opcional)</span>'
            + '<input type="text" id="ib-prev-txt" maxlength="1024" placeholder="Añade un mensaje…"></label>')
      + '<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">'
      + '<button type="button" class="ggi-btn-confirm-cancel" id="ib-prev-cancel">Cancelar</button>'
      + '<button type="button" class="ggi-btn-confirm-action" id="ib-prev-ok">Enviar</button>'
      + '</div>');

    // Arrastrar el texto ya escrito en la caja, si lo había
    var pie = document.getElementById('ib-prev-txt');
    if (pie) { pie.value = textoActual().trim(); pie.focus(); }

    function limpiar() {
      URL.revokeObjectURL(url);
      if (inputFile) inputFile.value = '';
    }

    var cancelar = document.getElementById('ib-prev-cancel');
    if (cancelar) cancelar.addEventListener('click', function () {
      limpiar();
      if (typeof ggiModalClose === 'function') ggiModalClose();
    });

    var ok = document.getElementById('ib-prev-ok');
    if (ok) ok.addEventListener('click', function () {
      ok.disabled = true; ok.textContent = 'Enviando…';

      var fd = new FormData();
      fd.append('accion', 'adjuntar');
      fd.append('id', st.sel);
      fd.append('archivo', archivo);
      fd.append('texto', pie ? pie.value.trim() : '');

      ggiPost('ajax.php', fd).then(function (j) {
        ok.disabled = false; ok.textContent = 'Enviar';
        if (!j.ok) return;
        limpiar();
        if (typeof ggiModalClose === 'function') ggiModalClose();
        var caja = $('#ib-texto');
        if (caja) caja.innerHTML = '';
        if (j.aviso && typeof showToast === 'function') showToast(j.aviso, 'warn', 7000);
        abrir(st.sel);
      });
    });
  }

  // ── Plantillas (única vía fuera de la ventana de 24 h) ─────────────────────
  var _plt = null;   // plantilla elegida

  function popPlantillas(ancla) {
    if (!st.sel) return;
    fetch('ajax.php?accion=plantillas', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j.ok) return;
        if (!j.items.length) {
          abrirPop(ancla, '<div class="ib-pop-item" style="color:var(--tx3)">No hay plantillas aprobadas</div>');
          return;
        }
        abrirPop(ancla, '<div class="ib-pop-t">Elige una plantilla</div>'
          + j.items.map(function (p, i) {
              return '<button type="button" class="ib-pop-item ib-plt" data-plt="' + i + '">'
                   + '<span><b>' + esc(p.nombre) + '</b>'
                   + '<small>' + esc(p.cuerpo.slice(0, 90)) + (p.cuerpo.length > 90 ? '…' : '') + '</small></span>'
                   + '</button>';
            }).join(''));
        _plt = j.items;
      });
  }

  // Formulario para rellenar {{1}}, {{2}}… antes de enviar
  function formPlantilla(p) {
    cerrarPop();
    var campos = p.claves.map(function (k, i) {
      return '<label class="ib-plt-f"><span>' + esc(k.replace(/_/g, ' ')) + '</span>'
           + '<input type="text" data-pi="' + i + '" placeholder="{{' + (i + 1) + '}}"></label>';
    }).join('');

    mantOpen('Enviar plantilla · ' + p.nombre,
        '<div class="ib-plt-prev" id="ib-plt-prev">' + esc(p.cuerpo) + '</div>'
      + (campos || '<p style="font-size:.82rem;color:var(--tx2)">Esta plantilla no lleva variables.</p>')
      + '<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px">'
      + '<button type="button" class="ggi-btn-confirm-cancel" onclick="ggiModalClose()">Cancelar</button>'
      + '<button type="button" class="ggi-btn-confirm-action" id="ib-plt-enviar">Enviar</button>'
      + '</div>');

    // Vista previa en vivo: se ve el mensaje real antes de mandarlo
    function preview() {
      var txt = p.cuerpo;
      document.querySelectorAll('[data-pi]').forEach(function (inp) {
        txt = txt.replace('{{' + (parseInt(inp.dataset.pi, 10) + 1) + '}}', inp.value || ('{{' + (parseInt(inp.dataset.pi, 10) + 1) + '}}'));
      });
      var box = document.getElementById('ib-plt-prev');
      if (box) box.innerHTML = formato(txt);
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
      ggiPost('ajax.php', datos).then(function (j) {
        btn.disabled = false; btn.textContent = 'Enviar';
        if (!j.ok) return;
        if (typeof ggiModalClose === 'function') ggiModalClose();
        if (typeof showToast === 'function') showToast('Plantilla enviada', 'ok');
        abrir(st.sel);
      });
    });
  }

  // ── Envío ──
  function enviar() {
    var inp = $('#ib-texto'), btn = $('#ib-enviar');
    if (!inp || !st.sel) return;

    var texto = textoActual().trim();

    // Con adjunto puesto, Enviar manda el archivo con el texto como pie
    if (st.adjunto) { enviarAdjunto(texto, btn, inp); return; }

    if (!texto) return;

    inp.setAttribute('contenteditable', 'false');
    if (btn) { btn.disabled = true; btn.textContent = 'Enviando…'; }

    // Restaura la caja pase lo que pase, para que nunca quede en "Enviando…"
    function restaurar() {
      if (!document.body.contains(inp)) return;   // el hilo ya se re-renderizó
      inp.setAttribute('contenteditable', 'true');
      if (btn) { btn.disabled = false; btn.textContent = 'Enviar'; }
      inp.focus();
    }

    ggiPost('ajax.php', { accion: 'enviar', id: st.sel, texto: texto })
      .then(function (j) {
        if (j.ok) inp.innerHTML = '';
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
    h += sec('Empresa', iEmp, c.cliente_id
      ? '<button type="button" class="ib-ctx-a" data-ver="../clientes/ver.php?id=' + c.cliente_id + '" '
        + 'data-titulo="' + esc(c.titulo) + '"><span>' + esc(c.titulo) + '</span><span>↗</span></button>'
        + (c.ruc ? '<div class="ib-ctx-s2">RUC ' + esc(c.ruc) + '</div>' : '')
      : '<div class="ib-ctx-s2">Número no vinculado a ninguna empresa</div>');

    if (x.propuestas.length) {
      h += sec('Propuestas', '', x.propuestas.map(function (p) {
        return '<button type="button" class="ib-ctx-a" data-ver="../cotizaciones/ver.php?id=' + p.id + '" '
             + 'data-titulo="Propuesta ' + esc(p.numero) + '"><span>' + esc(p.numero) + '</span>'
             + '<span style="color:var(--tx3)">' + esc(p.estado) + '</span></button>';
      }).join(''));
    }
    if (x.envios.length) {
      h += sec('Envíos', '', x.envios.map(function (e) {
        return '<button type="button" class="ib-ctx-a" data-ver="../envios/ver.php?id=' + e.id + '" '
             + 'data-titulo="Envío ' + esc(e.codigo_envio || '') + '"><span>' + esc(e.codigo_envio || '—') + '</span>'
             + '<span>↗</span></button>';
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

  function abrirPop(ancla, html) {
    cerrarPop();
    var r = ancla.getBoundingClientRect();
    var d = document.createElement('div');
    d.className = 'ib-pop';
    d.id = 'ib-pop';
    d.innerHTML = html;
    document.body.appendChild(d);
    // position:fixed para no depender del contenedor con overflow del inbox
    d.style.position = 'fixed';
    d.style.top  = Math.min(r.bottom + 6, window.innerHeight - d.offsetHeight - 10) + 'px';
    d.style.left = Math.min(r.left, window.innerWidth - d.offsetWidth - 10) + 'px';
    return d;
  }

  // Etiquetas de la conversación abierta
  function popEtiquetas(ancla) {
    if (!st.sel) return;
    fetch('ajax.php?accion=etiquetas', { credentials: 'same-origin' })
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
    fetch('ajax.php?accion=etiquetas', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j.ok) return;
        var d = abrirPop(ancla,
            '<input type="search" id="ib-etqq" placeholder="Buscar etiqueta…" aria-label="Buscar etiqueta">'
          + '<div id="ib-etql"></div>');

        function pintar(txt) {
          txt = (txt || '').toLowerCase();
          d.querySelector('#ib-etql').innerHTML =
              '<button type="button" class="ib-pop-item" data-fetq="0">'
            + '<span style="color:var(--tx2)">Todas las etiquetas</span>'
            + (st.etq ? '' : '<span style="margin-left:auto;color:var(--ac)">✓</span>') + '</button>'
            + j.items.filter(function (e) { return e.nombre.toLowerCase().indexOf(txt) !== -1; })
                .map(function (e) {
                  return '<button type="button" class="ib-pop-item" data-fetq="' + e.id + '" data-nom="' + esc(e.nombre) + '">'
                       + '<span class="ib-etq ' + esc(e.color) + '">' + esc(e.nombre) + '</span>'
                       + (st.etq === e.id ? '<span style="margin-left:auto;color:var(--ac)">✓</span>' : '') + '</button>';
                }).join('');
        }
        pintar('');
        var inp = d.querySelector('#ib-etqq');
        inp.focus();
        inp.addEventListener('input', function () { pintar(inp.value); });
      });
  }

  // Nueva conversación: busca empresa por nombre/RUC/teléfono
  function popNueva(ancla) {
    var d = abrirPop(ancla,
        '<input type="search" id="ib-nq" placeholder="Empresa, RUC o teléfono…" aria-label="Buscar contacto">'
      + '<div id="ib-nres"><div class="ib-pop-item" style="color:var(--tx3)">Escribe para buscar…</div></div>');

    var inp = d.querySelector('#ib-nq'); inp.focus();
    var t;
    inp.addEventListener('input', function () {
      clearTimeout(t);
      t = setTimeout(function () {
        fetch('ajax.php?accion=buscar_contacto&q=' + encodeURIComponent(inp.value.trim()),
              { credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (j) {
            var box = d.querySelector('#ib-nres');
            if (!box) return;
            box.innerHTML = (j.items && j.items.length)
              ? j.items.map(function (c) {
                  return '<button type="button" class="ib-pop-item" data-tel="' + esc(c.contacto_telefono) + '">'
                       + '<span>' + esc(c.razon_social)
                       + '<small>+' + esc(c.contacto_telefono) + (c.ruc ? ' · RUC ' + esc(c.ruc) : '') + '</small></span>'
                       + '</button>';
                }).join('')
              : '<div class="ib-pop-item" style="color:var(--tx3)">Sin resultados</div>';
          });
      }, 280);
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

  document.addEventListener('click', function (e) {
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
    if (e.target.closest('#ib-filtro-etq')){ popFiltroEtiquetas(e.target.closest('#ib-filtro-etq')); return; }
    if (e.target.closest('#ib-add-etq'))   { popEtiquetas(e.target.closest('#ib-add-etq')); return; }

    var elegido = e.target.closest('[data-tel]');
    if (elegido) {
      cerrarPop();
      ggiPost('ajax.php', { accion: 'abrir_contacto', telefono: elegido.dataset.tel })
        .then(function (j) { if (j.ok) { lista(); abrir(j.id); } });
      return;
    }

    var fEtq = e.target.closest('[data-fetq]');
    if (fEtq) {
      cerrarPop();
      st.etq = parseInt(fEtq.dataset.fetq, 10) || 0;
      var chip = $('#ib-etq-activa');
      if (chip) {
        chip.style.display = st.etq ? '' : 'none';
        chip.textContent   = st.etq ? (fEtq.dataset.nom || 'Etiqueta') + ' ✕' : '';
        chip.classList.toggle('on', !!st.etq);
      }
      var bEtq = $('#ib-filtro-etq');
      if (bEtq) bEtq.classList.toggle('on', !!st.etq);
      lista();
      return;
    }

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

    // Quitar el filtro de etiqueta desde su propio chip
    if (e.target.closest('#ib-etq-activa')) {
      st.etq = 0;
      var c2 = $('#ib-etq-activa');
      if (c2) { c2.style.display = 'none'; c2.classList.remove('on'); }
      lista();
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
    if (e.target.closest('#ib-enviar'))  { enviar(); return; }

    // Formato de WhatsApp: envuelve la selección con los marcadores
    var fmt = e.target.closest('.ib-fmt[data-fmt]');
    if (fmt) { envolver(fmt.dataset.fmt); return; }

    if (e.target.closest('#ib-emoji')) { popEmojis(e.target.closest('#ib-emoji')); return; }

    if (e.target.closest('#ib-adjunto')) {
      var f = $('#ib-file');
      if (f) f.click();
      return;
    }

    if (e.target.closest('#ib-adj-quitar')) { quitarAdjunto(); return; }

    if (e.target.closest('#ib-plantilla')) { popPlantillas(e.target.closest('#ib-plantilla')); return; }

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
