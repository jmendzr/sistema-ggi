<?php
$page_title  = 'Plantilla de alerta por email';
$active_menu = 'alertas';
require_once __DIR__ . '/../../templates/layout.php';
require_role('gerente_general');

$cfg_file = __DIR__ . '/../../config/email_alerta.json';
$defaults = [
    'reply_to'       => 'contacto@groupglobaliso.com',
    'saludo'         => 'Estimado/a {contacto_nombre},',
    'texto_90'       => 'Le informamos con anticipación que su certificado {norma} vencerá en aproximadamente 90 días. Le recomendamos coordinar con nosotros el inicio del proceso de renovación.',
    'texto_60'       => 'Su certificado {norma} vencerá en menos de 60 días. Es momento de iniciar las gestiones de renovación para evitar interrupciones en su certificación.',
    'texto_30'       => 'Su certificado {norma} vence en menos de 30 días. Es necesario iniciar el proceso de renovación a la brevedad posible para evitar la pérdida de su certificación.',
    'cierre'         => 'Para coordinar su renovación o resolver cualquier consulta, comuníquese con su ejecutivo de cuenta de Group Global ISO.',
    'firma_nombre'   => 'Group Global ISO S.A.C.',
    'firma_web'      => 'www.groupglobaliso.com',
    'firma_telefono' => '',
    'mostrar_portal' => '1',
];
$cfg = file_exists($cfg_file)
    ? array_merge($defaults, json_decode(file_get_contents($cfg_file), true) ?: [])
    : $defaults;

$saved = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    foreach (array_keys($defaults) as $k) {
        $cfg[$k] = trim($_POST[$k] ?? $defaults[$k]);
    }
    file_put_contents($cfg_file, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $saved = true;
}
?>
<style>
.ep-wrap{display:grid;grid-template-columns:1fr 1fr;gap:0;min-height:calc(100vh - 130px)}
.ep-left{overflow-y:auto;padding:0 20px 60px 0;border-right:1px solid var(--gris-borde)}
.ep-right{overflow-y:auto;padding:0 0 60px 20px;background:var(--gris-bg,#f0f2f8)}
.tab-row{display:flex;gap:6px;margin-bottom:14px}
.tab-pill{flex:1;padding:9px 4px;text-align:center;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;border:1.5px solid var(--gris-borde);background:#fff;color:var(--gris-texto);transition:.15s}
.ap90{background:#dbeafe;color:#1e3a8a;border-color:#93c5fd}
.ap60{background:#fef3c7;color:#92400e;border-color:#fcd34d}
.ap30{background:#fee2e2;color:#991b1b;border-color:#fca5a5}
.field{margin-bottom:14px}
.field label{display:block;font-size:10px;font-weight:700;color:var(--azul);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px}
.field input,.field textarea,.field select{width:100%;padding:8px 12px;border:1.5px solid var(--gris-borde);border-radius:8px;font-size:13px;font-family:'Plus Jakarta Sans',sans-serif;color:var(--gris-dark);background:#fafbfe;outline:none;transition:.2s}
.field input:focus,.field textarea:focus{border-color:var(--azul);box-shadow:0 0 0 3px rgba(26,58,143,.07);background:#fff}
.field textarea{resize:vertical;min-height:72px;line-height:1.5}
.field .hint{font-size:11px;color:var(--gris-texto);margin-top:4px}
.readonly-field input{background:#f0f2f8;color:var(--gris-texto);cursor:not-allowed;border-color:#e5e7eb}
.sep{font-size:10px;font-weight:700;color:var(--gris-texto);text-transform:uppercase;letter-spacing:.6px;padding:14px 0 10px;border-top:1px solid var(--gris-borde);margin-top:6px;display:flex;align-items:center;gap:8px}
.sep::after{content:'';flex:1;height:1px;background:var(--gris-borde)}
.hito-block{display:none}
.hito-block.vis{display:block}
.hito-lbl{font-size:11px;font-weight:700;padding:4px 12px;border-radius:20px;display:inline-block;margin-bottom:8px}
#preview-frame{width:100%;border:none;border-radius:10px;background:#fff;min-height:680px}
@media(max-width:900px){.ep-wrap{grid-template-columns:1fr;height:auto}.ep-right{border-top:1px solid var(--gris-borde)}}
</style>

<?php ggi_breadcrumb([
    ['label' => 'Monitor de alertas', 'href' => APP_URL.'/modules/alertas/index.php'],
    ['label' => 'Plantilla de email'],
]); ?>
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:10px">
  <div>
    <h1 style="font-size:20px;font-weight:700;color:var(--azul);margin:0">Plantilla de alerta por email</h1>
    <p style="font-size:13px;color:var(--gris-texto);margin:3px 0 0">Los cambios se reflejan en la vista previa al instante</p>
  </div>
</div>

<?php if ($saved): ?>
<script>ggiReady(function(){ if(typeof showToast==='function') showToast('Configuración guardada. Los próximos emails usarán este contenido.','ok'); });</script>
<?php endif; ?>

<div class="ep-wrap">
  <!-- Izquierda: formulario -->
  <div class="ep-left">
    <form method="POST" id="cfg-form">
      <?= csrf_field() ?>

      <div class="sep">Remitente</div>
      <div class="field" style="background:#f8fafc;border:1px solid var(--gris-borde);border-radius:8px;padding:10px 12px">
        <label style="margin-bottom:3px">Email remitente (fijo del sistema)</label>
        <div style="font-size:13px;font-family:monospace;color:var(--gris-texto)">noreply@groupglobaliso.com</div>
        <div class="hint" style="margin-top:4px">Fijado al dominio para evitar que caiga en spam.</div>
      </div>
      <div class="field">
        <label>Reply-To — respuestas y copias</label>
        <input type="text" name="reply_to" id="f_reply_to" value="<?= htmlspecialchars($cfg['reply_to']) ?>" placeholder="correo1@ggi.com, correo2@ggi.com">
        <div class="hint">Separa varios correos con coma. Las respuestas del cliente llegarán aquí.</div>
      </div>

      <div class="sep">Saludo</div>
      <div class="field">
        <label>Saludo</label>
        <input type="text" name="saludo" id="f_saludo" value="<?= htmlspecialchars($cfg['saludo']) ?>">
        <div class="hint">Variables disponibles: <code>{contacto_nombre}</code> <code>{nombre_completo}</code> <code>{empresa}</code></div>
      </div>

      <div class="sep">Texto por hito</div>
      <p style="font-size:12px;color:var(--gris-texto);margin-bottom:12px">Usa las pestañas de la derecha para cambiar de hito. El textarea muestra el texto del hito seleccionado.</p>
      <div class="hito-block vis" id="hito90">
        <div class="hito-lbl" style="background:#dbeafe;color:#1e3a8a">Aviso · 90 días</div>
        <div class="field">
          <textarea name="texto_90" id="f_texto_90" rows="4"><?= htmlspecialchars($cfg['texto_90']) ?></textarea>
          <div class="hint">Variables: <code>{norma}</code> <code>{empresa}</code> <code>{fecha_vencimiento}</code></div>
        </div>
      </div>
      <div class="hito-block" id="hito60">
        <div class="hito-lbl" style="background:#fef3c7;color:#92400e">Atención · 60 días</div>
        <div class="field">
          <textarea name="texto_60" id="f_texto_60" rows="4"><?= htmlspecialchars($cfg['texto_60']) ?></textarea>
          <div class="hint">Variables: <code>{norma}</code> <code>{empresa}</code> <code>{fecha_vencimiento}</code></div>
        </div>
      </div>
      <div class="hito-block" id="hito30">
        <div class="hito-lbl" style="background:#fee2e2;color:#991b1b">Urgente · 30 días</div>
        <div class="field">
          <textarea name="texto_30" id="f_texto_30" rows="4"><?= htmlspecialchars($cfg['texto_30']) ?></textarea>
          <div class="hint">Variables: <code>{norma}</code> <code>{empresa}</code> <code>{fecha_vencimiento}</code></div>
        </div>
      </div>

      <div class="sep">Cierre</div>
      <div class="field">
        <label>Párrafo de cierre</label>
        <textarea name="cierre" id="f_cierre"><?= htmlspecialchars($cfg['cierre']) ?></textarea>
      </div>

      <div class="sep">Firma</div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
        <div class="field">
          <label>Nombre en firma</label>
          <input type="text" name="firma_nombre" id="f_firma_nombre" value="<?= htmlspecialchars($cfg['firma_nombre']) ?>">
        </div>
        <div class="field">
          <label>Sitio web</label>
          <input type="text" name="firma_web" id="f_firma_web" value="<?= htmlspecialchars($cfg['firma_web']) ?>">
        </div>
      </div>
      <div class="field">
        <label>Teléfono en firma (opcional)</label>
        <input type="tel" name="firma_telefono" id="f_firma_telefono" value="<?= htmlspecialchars($cfg['firma_telefono']) ?>">
      </div>
      <div class="field">
        <label>Botón de portal en el email</label>
        <select name="mostrar_portal" id="f_mostrar_portal">
          <option value="1" <?= $cfg['mostrar_portal'] ? 'selected' : '' ?>>Sí — incluir botón "Ver mis certificados"</option>
          <option value=""  <?= !$cfg['mostrar_portal'] ? 'selected' : '' ?>>No — enviar sin botón de portal</option>
        </select>
      </div>

      <button type="submit" class="btn btn-primary" style="width:100%;margin-top:10px">
        <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/></svg>
        Guardar configuración
      </button>
    </form>
  </div>

  <!-- Derecha: preview -->
  <div class="ep-right">
    <div class="tab-row">
      <button id="tab90" class="tab-pill ap90" onclick="setPreview(90)">
        <svg width="10" height="10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" style="display:inline;vertical-align:middle;margin-right:3px"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        Aviso 90 días
      </button>
      <button id="tab60" class="tab-pill" onclick="setPreview(60)">
        <svg width="10" height="10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" style="display:inline;vertical-align:middle;margin-right:3px"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg>
        Atención 60 días
      </button>
      <button id="tab30" class="tab-pill" onclick="setPreview(30)">
        <svg width="10" height="10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" style="display:inline;vertical-align:middle;margin-right:3px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        Urgente 30 días
      </button>
    </div>
    <iframe id="preview-frame" scrolling="yes"></iframe>
  </div>
</div>

<script>
var EJ = {
  nombre:'Carlos Ramírez',empresa:'EMPRESA EJEMPLO S.A.C.',
  norma:'ISO 9001:2015',casa:'QRO',num:'QRO-2024-001',
  f90:'<?= date("d/m/Y",strtotime("+90 days")) ?>',
  f60:'<?= date("d/m/Y",strtotime("+60 days")) ?>',
  f30:'<?= date("d/m/Y",strtotime("+30 days")) ?>',
  portal:'<?= APP_URL ?>/portal/?token=demo'
};
var curDias = 90;

function rep(t,dias){
  const f = dias===90?EJ.f90:dias===60?EJ.f60:EJ.f30;
  return t.replace(/{contacto_nombre}/g,EJ.nombre.split(' ')[0])
          .replace(/{nombre_completo}/g,EJ.nombre)
          .replace(/{empresa}/g,EJ.empresa)
          .replace(/{norma}/g,EJ.norma)
          .replace(/{fecha_vencimiento}/g,f);
}
function v(id){return(document.getElementById(id)||{}).value||'';}

function buildHTML(dias){
  const f=dias===90?EJ.f90:dias===60?EJ.f60:EJ.f30;
  const txts={90:v('f_texto_90'),60:v('f_texto_60'),30:v('f_texto_30')};
  const cs={90:['#1A3A8F','#eff6ff','AVISO'],60:['#d97706','#fffbeb','ATENCIÓN'],30:['#dc2626','#fef2f2','URGENTE']};
  const[col,bg,lbl]=cs[dias];
  const fn=v('f_firma_nombre')||'Group Global ISO S.A.C.';
  const fw=v('f_firma_web')||'www.groupglobaliso.com';
  const ft=v('f_firma_telefono')?'<br>'+v('f_firma_telefono'):'';
  const pb=v('f_mostrar_portal')?`<div style="margin-top:20px;text-align:center"><a href="${EJ.portal}" style="display:inline-block;background:#1A3A8F;color:#fff;padding:11px 24px;border-radius:8px;text-decoration:none;font-family:Arial,sans-serif;font-size:14px;font-weight:bold;">Ver mis certificados en línea</a></div>`:'';
  return`<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:0;background:#f0f2f8;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f0f2f8;padding:20px 10px;"><tr><td align="center">
<table width="550" cellpadding="0" cellspacing="0" style="max-width:550px;width:100%;">
<tr><td style="background:#1A3A8F;border-radius:14px 14px 0 0;padding:20px 24px;">
<table width="100%" cellpadding="0" cellspacing="0"><tr>
<td><div style="font-size:18px;font-weight:bold;color:#fff;">${fn}</div><div style="font-size:10px;color:rgba(255,255,255,0.5);margin-top:3px;letter-spacing:1px;text-transform:uppercase;">Sistema de certificaciones ISO</div></td>
<td align="right"><div style="background:${bg};color:${col};padding:5px 12px;border-radius:20px;font-size:11px;font-weight:bold;white-space:nowrap;">${lbl} — ${dias} días</div></td>
</tr></table></td></tr>
<tr><td style="background:#fff;padding:24px;">
<p style="font-size:15px;color:#1a1f36;margin:0 0 14px;">${rep(v('f_saludo'),dias)}</p>
<div style="background:${bg};border-left:4px solid ${col};padding:13px 16px;margin-bottom:18px;border-radius:0 8px 8px 0;">
<div style="font-size:14px;color:${col};font-weight:bold;line-height:1.5;">${rep(txts[dias],dias)}</div></div>
<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:16px;margin-bottom:18px;">
<div style="font-size:10px;font-weight:bold;color:#6b7a99;text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;">Detalles del certificado</div>
<table width="100%" cellpadding="0" cellspacing="0">
<tr><td style="padding:5px 0;font-size:13px;color:#6b7a99;width:46%">Empresa</td><td style="padding:5px 0;font-size:13px;color:#1a1f36;font-weight:bold">${EJ.empresa}</td></tr>
<tr><td style="padding:5px 0;font-size:13px;color:#6b7a99;border-top:1px solid #f0f2f8">Norma ISO</td><td style="padding:5px 0;font-size:13px;color:#1A3A8F;font-weight:bold;border-top:1px solid #f0f2f8">${EJ.norma}</td></tr>
<tr><td style="padding:5px 0;font-size:13px;color:#6b7a99;border-top:1px solid #f0f2f8">Casa certificadora</td><td style="padding:5px 0;font-size:13px;color:#1a1f36;border-top:1px solid #f0f2f8">${EJ.casa}</td></tr>
<tr><td style="padding:5px 0;font-size:13px;color:#6b7a99;border-top:1px solid #f0f2f8">N° de certificado</td><td style="padding:5px 0;font-size:13px;font-family:monospace;color:#1a1f36;border-top:1px solid #f0f2f8">${EJ.num}</td></tr>
<tr><td style="padding:5px 0;font-size:13px;color:#6b7a99;border-top:1px solid #f0f2f8">Fecha de vencimiento</td><td style="padding:5px 0;font-size:15px;color:${col};font-weight:bold;border-top:1px solid #f0f2f8">${f}</td></tr>
</table></div>
<p style="font-size:14px;color:#1a1f36;line-height:1.6;margin-bottom:14px;">${rep(v('f_cierre'),dias)}</p>
${pb}
</td></tr>
<tr><td style="background:#f8fafc;border-radius:0 0 14px 14px;padding:16px 24px;border-top:1px solid #e2e8f0;">
<div style="font-size:12px;color:#9ca3af;line-height:1.7;"><strong style="color:#6b7a99;">${fn}</strong>${ft}<br><a href="https://${fw}" style="color:#1A3A8F;text-decoration:none;">${fw}</a><br><br>Este es un mensaje automático. Por favor no responda directamente.</div>
</td></tr></table></td></tr></table></body></html>`;
}

function refreshPreview(){
  document.getElementById('preview-frame').srcdoc=buildHTML(curDias);
}

function setPreview(dias){
  curDias=dias;
  const cls={90:'ap90',60:'ap60',30:'ap30'};
  [90,60,30].forEach(d=>{
    const t=document.getElementById('tab'+d);
    t.className='tab-pill'+(d===dias?' '+cls[d]:'');
  });
  // Sincronizar textarea visible en la izquierda
  [90,60,30].forEach(d=>{
    document.getElementById('hito'+d).classList.toggle('vis',d===dias);
  });
  refreshPreview();
}

function setHito(dias){
  [90,60,30].forEach(d=>{
    document.getElementById('hito'+d).classList.toggle('vis',d===dias);
  });
  setPreview(dias);
}

// Preview en tiempo real
document.querySelectorAll('[id^="f_"]').forEach(el=>{
  el.addEventListener('input',refreshPreview);
  el.addEventListener('change',refreshPreview);
});

refreshPreview();
</script>
<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
