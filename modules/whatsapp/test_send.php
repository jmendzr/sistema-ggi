<?php
/**
 * Test de envío WhatsApp — solo accesible por gerente_general.
 * Acceso: /modules/whatsapp/test_send.php
 */
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../../core/WhatsAppService.php';

session_init();
require_login();
require_role('gerente_general');

$resultado = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $telefono  = trim($_POST['telefono']  ?? '');
    $template  = trim($_POST['template']  ?? '');
    $param1    = trim($_POST['param1']    ?? '');
    $param2    = trim($_POST['param2']    ?? '');
    $doc_url   = trim($_POST['doc_url']   ?? '');

    $phone_norm = WhatsAppService::normalizePhone($telefono);
    if (!$phone_norm) {
        $resultado = ['ok' => false, 'msg' => "Número inválido: \"{$telefono}\" → no pasa validación (debe ser móvil peruano de 9 dígitos)"];
    } else {
        $params = array_filter([$param1, $param2], fn($p) => $p !== '');
        $waId = WhatsAppService::sendTemplate(
            $phone_norm,
            $template,
            array_values($params),
            'es',
            $doc_url ?: null,
            $doc_url ? 'documento_prueba.pdf' : null
        );
        if ($waId) {
            $resultado = ['ok' => true, 'msg' => "Enviado correctamente. WA Message ID: {$waId}", 'phone' => $phone_norm];
        } else {
            $resultado = ['ok' => false, 'msg' => "La API no devolvió un message_id. Revisa los logs del servidor (error_log)."];
        }
    }
}

// Cargar templates disponibles
$pdo = Database::get();
$templates = $pdo->query("SELECT event_code, template_name, param_keys FROM whatsapp_templates WHERE activo=1 ORDER BY event_code")->fetchAll(\PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Test WhatsApp — GGI</title>
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
  <style>
    body { max-width: 620px; margin: 40px auto; padding: 0 20px; }
    .result-ok  { background:var(--s-ok-bg);color:var(--s-ok-tx);border:1px solid var(--s-ok-brd);padding:14px 16px;border-radius:10px;margin-bottom:20px;font-size:13px; }
    .result-err { background:var(--s-err-bg);color:#DC2626;border:1px solid var(--s-err-brd);padding:14px 16px;border-radius:10px;margin-bottom:20px;font-size:13px; }
    label { display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;margin-top:14px; }
    input, select { width:100%;box-sizing:border-box;padding:9px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:13px; }
    .hint { font-size:11px;color:#6B7280;margin-top:3px; }
    .param-preview { font-size:12px;color:#4B5563;background:var(--bg-surface2);border:1px solid #E5E7EB;border-radius:6px;padding:8px 12px;margin-top:8px;font-family:monospace; }
  </style>
</head>
<body>
  <div style="display:flex;align-items:center;gap:10px;margin-bottom:24px;">
    <div style="width:40px;height:40px;background:var(--s-ok-bg);border-radius:10px;display:flex;align-items:center;justify-content:center;">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="#22C55E"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
    </div>
    <div>
      <h1 style="margin:0;font-size:18px;font-weight:700;">Test de envío WhatsApp</h1>
      <p style="margin:0;font-size:12px;color:#6B7280;">Solo visible para gerente_general</p>
    </div>
  </div>

  <?php if ($resultado): ?>
  <div class="<?= $resultado['ok'] ? 'result-ok' : 'result-err' ?>">
    <?php if ($resultado['ok']): ?>
    <strong>✓ Mensaje enviado</strong><br>
    <?= sanitize($resultado['msg']) ?><br>
    <span style="font-size:11px;opacity:.8;">Número normalizado: <?= sanitize($resultado['phone']) ?></span>
    <?php else: ?>
    <strong>✗ Error</strong><br><?= sanitize($resultado['msg']) ?>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="card" style="padding:24px;">
    <form method="POST">
      <?= csrf_field() ?>

      <label>Tu número de WhatsApp</label>
      <input type="tel" name="telefono" placeholder="Ej: 987654321 o 51987654321"
             value="<?= sanitize($_POST['telefono'] ?? '') ?>" required>
      <p class="hint">Número móvil peruano. Se normaliza automáticamente a formato E.164.</p>

      <label>Template</label>
      <select name="template" id="sel-template" onchange="actualizarPreview()" required>
        <option value="">— Selecciona —</option>
        <?php foreach ($templates as $t): ?>
        <option value="<?= $t['template_name'] ?>"
                data-params="<?= htmlspecialchars($t['param_keys']) ?>"
                data-event="<?= $t['event_code'] ?>"
                <?= (($_POST['template'] ?? '') === $t['template_name']) ? 'selected' : '' ?>>
          <?= $t['template_name'] ?> (<?= $t['event_code'] ?>)
        </option>
        <?php endforeach; ?>
      </select>

      <div id="params-box" style="display:none;">
        <div id="param1-wrap">
          <label>{{1}} — razon_social / nombre</label>
          <input type="text" name="param1" id="param1" placeholder="Ej: Empresa SAC"
                 value="<?= sanitize($_POST['param1'] ?? 'Empresa de Prueba SAC') ?>">
        </div>
        <div id="param2-wrap" style="display:none;">
          <label id="param2-label">{{2}}</label>
          <input type="text" name="param2" id="param2" placeholder=""
                 value="<?= sanitize($_POST['param2'] ?? '') ?>">
        </div>
        <div id="doc-wrap" style="display:none;">
          <label>URL del PDF (header documento)</label>
          <input type="url" name="doc_url" placeholder="https://..." value="<?= sanitize($_POST['doc_url'] ?? '') ?>">
          <p class="hint">Solo para el template ggi_factura_emitida. Debe ser URL pública accesible por Meta.</p>
        </div>
        <div class="param-preview" id="preview" style="margin-top:12px;"></div>
      </div>

      <div style="margin-top:20px;">
        <button type="submit" class="btn btn-primary" style="width:100%;">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" style="margin-right:6px;"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
          Enviar mensaje de prueba
        </button>
      </div>
    </form>
  </div>

  <p style="text-align:center;font-size:11px;color:#9CA3AF;margin-top:16px;">
    <a href="<?= APP_URL ?>" style="color:#6B7280;">← Volver al sistema</a>
  </p>

<script>
const templateBodies = {
  'ggi_propuesta_aceptada': 'Hola {{1}}, tu propuesta {{2}} ha sido aceptada. Pronto recibirás más información sobre los siguientes pasos. — GGI Group Global ISO',
  'ggi_envio_registrado':   'Hola {{1}}, hemos registrado el inicio de tu proceso con GGI. Nuestro equipo ya está trabajando en ello. — GGI Group Global ISO',
  'ggi_envio_en_camino':    'Hola {{1}}, tu documentación está en camino. Te avisaremos cuando llegue a destino. — GGI Group Global ISO',
  'ggi_envio_destino':      'Hola {{1}}, tu documentación ha llegado a destino y está siendo procesada. — GGI Group Global ISO',
  'ggi_envio_entregado':    'Hola {{1}}, tu documentación ha sido entregada exitosamente. Gracias por confiar en GGI Group Global ISO.',
  'ggi_documentos_completos':'Hola {{1}}.\n\nLa documentación requerida para iniciar su proceso con GGI ha sido completada correctamente.\n\nNuestro equipo continuará con la siguiente etapa.',
  'ggi_factura_emitida':    'Estimado {{1}}, adjuntamos su factura {{2}}. Cualquier consulta estamos a su disposición. — GGI Group Global ISO',
};
const param2Labels = {
  'ggi_propuesta_aceptada': '{{2}} — N° de cotización (ej: COT-0023)',
  'ggi_factura_emitida':    '{{2}} — N° de factura (ej: F001-00001)',
};

function actualizarPreview() {
  const sel = document.getElementById('sel-template');
  const opt = sel.options[sel.selectedIndex];
  const tplName = sel.value;
  const params = opt.dataset.params ? JSON.parse(opt.dataset.params) : [];

  const paramsBox  = document.getElementById('params-box');
  const param2wrap = document.getElementById('param2-wrap');
  const docWrap    = document.getElementById('doc-wrap');
  const preview    = document.getElementById('preview');
  const p2label    = document.getElementById('param2-label');

  if (!tplName) { paramsBox.style.display='none'; return; }
  paramsBox.style.display = 'block';

  const has2 = params.length > 1;
  param2wrap.style.display = has2 ? 'block' : 'none';
  if (has2 && param2Labels[tplName]) p2label.textContent = param2Labels[tplName];

  docWrap.style.display = tplName === 'ggi_factura_emitida' ? 'block' : 'none';

  // Preview del cuerpo del mensaje
  const body = templateBodies[tplName] || '(cuerpo no disponible)';
  const p1 = document.getElementById('param1').value || '{{1}}';
  const p2 = document.getElementById('param2').value || '{{2}}';
  preview.textContent = body.replace('{{1}}', p1).replace('{{2}}', p2);
}

document.getElementById('param1').addEventListener('input', actualizarPreview);
document.getElementById('param2') && document.getElementById('param2').addEventListener('input', actualizarPreview);

// Inicializar si hay valor previo
if (document.getElementById('sel-template').value) actualizarPreview();
</script>
</body>
</html>
