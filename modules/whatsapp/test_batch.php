<?php
/**
 * Test batch de todos los templates WA — solo gerente_general.
 * Acceso: /modules/whatsapp/test_batch.php
 */
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../../core/WhatsAppService.php';

session_init();
require_login();
require_role('gerente_general');

$cliente_id    = 93;
$razon_social  = 'GROUP GLOBAL ISO S.A.C.';
$cot_numero    = 'COT-2026-0000647';
$numero_factura = 'F001-TEST-001';
$telefono      = '924429032';
$phone_norm    = WhatsAppService::normalizePhone($telefono);

$resultados = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $tests = [
        [
            'evento'   => 'propuesta_aceptada',
            'template' => 'ggi_propuesta_aceptada',
            'params'   => [$razon_social, $cot_numero],
            'doc_url'  => null,
        ],
        [
            'evento'   => 'envio_registrado',
            'template' => 'ggi_envio_registrado',
            'params'   => [$razon_social],
            'doc_url'  => null,
        ],
        [
            'evento'   => 'envio_en_camino',
            'template' => 'ggi_envio_en_camino',
            'params'   => [$razon_social],
            'doc_url'  => null,
        ],
        [
            'evento'   => 'envio_en_destino',
            'template' => 'ggi_envio_destino',
            'params'   => [$razon_social],
            'doc_url'  => null,
        ],
        [
            'evento'   => 'envio_entregado',
            'template' => 'ggi_envio_entregado',
            'params'   => [$razon_social],
            'doc_url'  => null,
        ],
        [
            'evento'   => 'empresa_documentacion_completa',
            'template' => 'ggi_documentos_completos',
            'params'   => [$razon_social],
            'doc_url'  => null,
        ],
        [
            'evento'   => 'factura_emitida',
            'template' => 'ggi_factura_emitida',
            'params'   => [$razon_social, $numero_factura],
            'doc_url'  => APP_URL . '/assets/img/test_factura.pdf',  // URL de prueba — puede no existir
        ],
    ];

    foreach ($tests as $t) {
        $waId = WhatsAppService::sendTemplate(
            $phone_norm,
            $t['template'],
            $t['params'],
            'es',
            $t['doc_url'],
            $t['doc_url'] ? 'Factura_TEST.pdf' : null
        );
        $resultados[] = [
            'evento'   => $t['evento'],
            'template' => $t['template'],
            'params'   => $t['params'],
            'ok'       => (bool)$waId,
            'wa_id'    => $waId,
        ];
        // Pausa mínima para no saturar la API
        usleep(300000); // 300ms
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Test Batch WA — GGI</title>
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
  <style>
    body { max-width:700px; margin:40px auto; padding:0 20px; }
    .row { display:flex; align-items:center; gap:12px; padding:12px 16px; border-radius:10px; margin-bottom:8px; font-size:13px; }
    .row-ok  { background:var(--s-ok-bg); border:1px solid var(--s-ok-brd); }
    .row-err { background:var(--s-err-bg); border:1px solid var(--s-err-brd); }
    .badge-ok  { background:#22C55E;color:#fff;border-radius:100px;padding:2px 10px;font-size:11px;font-weight:700;white-space:nowrap; }
    .badge-err { background:#EF4444;color:#fff;border-radius:100px;padding:2px 10px;font-size:11px;font-weight:700;white-space:nowrap; }
    .tpl  { font-family:monospace;font-size:12px;color:#4B5563; }
    .waid { font-family:monospace;font-size:10px;color:#9CA3AF;margin-left:auto; }
    .params { font-size:11px;color:#6B7280;margin-top:2px; }
  </style>
</head>
<body>

<div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
  <div style="width:40px;height:40px;background:var(--s-ok-bg);border-radius:10px;display:flex;align-items:center;justify-content:center;">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="#22C55E"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
  </div>
  <div>
    <h1 style="margin:0;font-size:18px;font-weight:700;">Test Batch — Todos los Templates</h1>
    <p style="margin:0;font-size:12px;color:#6B7280;">Cliente: <strong><?= $razon_social ?></strong> · Número: <strong><?= $telefono ?></strong> (<?= $phone_norm ?>)</p>
  </div>
</div>
<p style="font-size:12px;color:#6B7280;margin-bottom:24px;">Se enviarán los 7 templates a tu número. Cada uno llega como mensaje separado.</p>

<?php if (!empty($resultados)): ?>
  <?php
    $ok_count  = count(array_filter($resultados, fn($r) => $r['ok']));
    $err_count = count($resultados) - $ok_count;
  ?>
  <div style="background:<?= $err_count === 0 ? 'var(--s-ok-bg)' : 'var(--s-warn-bg)' ?>;border:1px solid <?= $err_count === 0 ? 'var(--s-ok-brd)' : '#FED7AA' ?>;border-radius:10px;padding:12px 16px;margin-bottom:20px;font-size:13px;font-weight:600;">
    <?= $ok_count ?>/<?= count($resultados) ?> templates enviados correctamente
    <?php if ($err_count > 0): ?> · <?= $err_count ?> con error<?php endif; ?>
  </div>

  <?php foreach ($resultados as $r): ?>
  <div class="row <?= $r['ok'] ? 'row-ok' : 'row-err' ?>">
    <span class="<?= $r['ok'] ? 'badge-ok' : 'badge-err' ?>"><?= $r['ok'] ? '✓ OK' : '✗ ERROR' ?></span>
    <div style="flex:1;min-width:0;">
      <div class="tpl"><?= $r['template'] ?></div>
      <div class="params">Params: <?= implode(' · ', array_map('htmlspecialchars', $r['params'])) ?></div>
    </div>
    <?php if ($r['wa_id']): ?>
    <span class="waid" title="WhatsApp Message ID"><?= substr($r['wa_id'], 0, 20) ?>…</span>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>

  <div style="margin-top:24px;text-align:center;">
    <a href="?" class="btn btn-outline btn-sm">Volver a enviar</a>
    &nbsp;
    <a href="<?= APP_URL ?>" class="btn btn-outline btn-sm">← Sistema</a>
  </div>

<?php else: ?>
  <div class="card" style="padding:28px;text-align:center;">
    <p style="font-size:13px;color:#4B5563;margin-bottom:20px;">
      Al presionar el botón se enviarán <strong>7 mensajes</strong> al número <strong><?= $telefono ?></strong>:<br>
      <span style="font-size:12px;color:#6B7280;">propuesta_aceptada · envio_registrado · envio_en_camino · envio_en_destino · envio_entregado · documentos_completos · factura_emitida</span>
    </p>
    <form method="POST">
      <?= csrf_field() ?>
      <button type="submit" class="btn btn-primary" style="min-width:200px;">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" style="margin-right:6px;"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
        Enviar los 7 templates ahora
      </button>
    </form>
  </div>
<?php endif; ?>

</body>
</html>
