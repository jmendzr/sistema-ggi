<?php // Template reutilizable para CRUDs de mantenimiento ?>
<style>
  .mant-wrap { display:grid; grid-template-columns:1fr 380px; gap:20px; align-items:start; }
  .mant-table-card { background:#fff; border-radius:12px; border:1px solid var(--gris-borde); overflow:hidden; }
  .mant-form-card  { background:#fff; border-radius:12px; border:1px solid var(--gris-borde); overflow:hidden; position:sticky; top:80px; }
  .mant-card-header { padding:16px 20px; border-bottom:1px solid var(--gris-borde); display:flex; align-items:center; justify-content:space-between; }
  .mant-card-title { font-family:'Inter',sans-serif; font-size:14px; font-weight:700; color:var(--azul); }
  .mant-table { width:100%; border-collapse:collapse; }
  .mant-table th { font-size:11px; font-weight:600; color:var(--gris-texto); text-transform:uppercase; letter-spacing:0.5px; padding:11px 16px; background:#fafbfe; border-bottom:1px solid var(--gris-borde); text-align:left; }
  .mant-table td { padding:12px 16px; font-size:13px; border-bottom:1px solid #f0f2f8; vertical-align:middle; }
  .mant-table tr:last-child td { border-bottom:none; }
  .mant-table tr:hover td { background:#fafbfe; }
  .mant-form-body { padding:20px; }
  .field { display:flex; flex-direction:column; gap:5px; margin-bottom:14px; }
  .field label { font-size:12px; font-weight:600; color:var(--azul); }
  .field input, .field textarea, .field select {
    padding:9px 12px; border:1.5px solid var(--gris-borde); border-radius:9px;
    font-size:13px; font-family:'Inter',sans-serif; color:var(--gris-dark);
    background:#fafbfe; outline:none; width:100%;
    transition:border-color 0.2s; -webkit-appearance:none;
  }
  .field input:focus, .field textarea:focus, .field select:focus { border-color:var(--azul); background:#fff; }
  .field input.error { border-color:var(--red); }
  .field .err-msg { font-size:11px; color:var(--red); }
  .field textarea { min-height:72px; resize:vertical; }
  .form-actions { display:flex; gap:8px; flex-wrap:wrap; }
  .action-btns { display:flex; gap:6px; }
  .btn-icon { width:30px; height:30px; border-radius:7px; display:flex; align-items:center; justify-content:center; border:1.5px solid var(--gris-borde); background:#fff; cursor:pointer; color:var(--gris-texto); text-decoration:none; transition:all 0.15s; }
  .btn-icon:hover { border-color:var(--azul); color:var(--azul); background:var(--azul-light); }
  .btn-icon.danger:hover { border-color:var(--red); color:var(--red); background:#fee2e2; }
  .badge-activo   { background:#e8f7eb; color:#229133; padding:2px 9px; border-radius:100px; font-size:11px; font-weight:600; }
  .badge-inactivo { background:#f1f5f9; color:#64748b; padding:2px 9px; border-radius:100px; font-size:11px; font-weight:600; }
  .alert-ok  { background:#e8f7eb; border:1px solid #b7e4c0; border-left:4px solid var(--verde); padding:11px 16px; border-radius:8px; font-size:13px; color:#1a6630; margin-bottom:16px; display:flex; align-items:center; gap:8px; }
  .empty-state { padding:32px; text-align:center; color:var(--gris-texto); font-size:13px; }

  /* Ícono de fila: oculto en escritorio, visible en móvil */
  .mobile-icon { display:none; }
  .mant-row-icon { color:var(--azul); flex-shrink:0; margin-top:1px; }

  @media (max-width:900px) {
    .mant-wrap { grid-template-columns:1fr; }
    .mant-form-card { position:static; }
  }

  @media (max-width:640px) {
    .mant-table thead { display:none; }
    .mant-table, .mant-table tbody, .mant-table tr, .mant-table td { display:block; width:100%; }
    .mant-table tr {
      border-radius:10px; border:1px solid var(--gris-borde);
      margin-bottom:10px; padding:12px 14px;
      background:var(--gris-card);
    }
    .mant-table td { padding:4px 0; border:none; }
    /* Etiqueta de columna en móvil */
    .mant-table td[data-label]::before {
      content: attr(data-label) ": ";
      font-size:10px; font-weight:700; color:var(--gris-texto);
      text-transform:uppercase; letter-spacing:0.4px;
      display:inline;
    }
    /* Primera celda: sin etiqueta, más prominente */
    .mant-table td:first-child::before { content:''; }
    .mant-first-col { font-size:14px; font-weight:700; color:var(--gris-dark); }
    .action-btns { margin-top:10px; padding-top:10px; border-top:1px solid var(--gris-borde); justify-content:flex-end; }
    .btn-icon { width:38px; height:38px; border-radius:9px; }
    .mobile-icon { display:inline-block; }
  }
</style>
<div id="mant-root"></div>
