<?php
/**
 * modules/facturas/nota_credito.php — Emite (o previsualiza) una nota de crédito
 * electrónica contra una factura ya emitida por Alegra.
 *
 * Requiere el permiso 'facturas_emitir'. Mientras ALEGRA_NC_REAL sea false crea BORRADORES.
 * Acciones: 'preview' (resumen sin crear) | 'emitir'.
 *
 * Alcance v1: notas de crédito sobre facturas de origen 'alegra' (emitidas por el
 * sistema y con alegra_id). Las facturas cargadas a mano se rectifican en Alegra.
 */
ob_start();
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../../core/AlegraService.php';
session_init();
require_login();

function _j(array $d): void { while (ob_get_level()>0) ob_end_clean(); header('Content-Type: application/json; charset=utf-8'); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') _j(['ok'=>false,'msg'=>'Método no permitido']);
csrf_verify();
if (!can('facturas_emitir')) _j(['ok'=>false,'msg'=>'No tienes permiso para emitir notas de crédito.']);

$user      = auth_user();
$pdo       = Database::get();
$accion    = trim($_POST['accion'] ?? 'preview');
$facturaId = (int)($_POST['factura_id'] ?? 0);
$tipo      = trim($_POST['tipo'] ?? '');
$causa     = trim($_POST['motivo'] ?? '');

if (!$facturaId) _j(['ok'=>false,'msg'=>'Factura no indicada.']);
if (!array_key_exists($tipo, AlegraService::NOTA_CR_TIPOS)) _j(['ok'=>false,'msg'=>'Tipo de nota de crédito inválido.']);
if ($accion === 'emitir') {
    if (mb_strlen($causa) < 5)   _j(['ok'=>false,'msg'=>'Indica el motivo de emisión (mínimo 5 caracteres).']);
    if (mb_strlen($causa) > 500) _j(['ok'=>false,'msg'=>'El motivo es demasiado largo (máx. 500).']);
}
if (!AlegraService::configurada()) _j(['ok'=>false,'msg'=>'Alegra no está configurado (.env).']);

// ── Factura de origen ──
$f = $pdo->prepare("
    SELECT f.*, cl.razon_social
    FROM facturas_cliente f JOIN clientes cl ON cl.id = f.cliente_id
    WHERE f.id = ?
");
$f->execute([$facturaId]);
$f = $f->fetch(\PDO::FETCH_ASSOC);
if (!$f) _j(['ok'=>false,'msg'=>'Factura no encontrada.']);
if (($f['origen'] ?? 'manual') !== 'alegra' || empty($f['alegra_id'])) {
    _j(['ok'=>false,'msg'=>'Esta factura no se emitió por el sistema (Alegra); su nota de crédito se hace directamente en Alegra.']);
}
if (($f['estado_timbrado'] ?? '') === 'error') _j(['ok'=>false,'msg'=>'La factura quedó en error; no tiene comprobante que rectificar.']);

$clienteId = (int)$f['cliente_id'];
$alegraId  = (int)$f['alegra_id'];

// ── Ya rectificada: no permitir 2ª nota de crédito sobre la misma factura ──
$prev = $pdo->prepare("SELECT numero, estado_timbrado FROM notas_credito_cliente WHERE factura_id = ? AND (estado_timbrado IS NULL OR estado_timbrado <> 'error') ORDER BY id DESC LIMIT 1");
$prev->execute([$facturaId]);
if ($ncPrev = $prev->fetch(\PDO::FETCH_ASSOC)) {
    _j(['ok'=>false,'msg'=>'Esta factura ya tiene una nota de crédito ('.($ncPrev['numero'] ?: 'emitida').').']);
}

// ── Traer la factura de Alegra (ítems + total reales) ──
$fa = AlegraService::facturaAlegra($alegraId);
if (!$fa) _j(['ok'=>false,'msg'=>'No se pudo leer la factura en Alegra: '.AlegraService::$lastError]);
if (empty($fa['items'])) _j(['ok'=>false,'msg'=>'La factura en Alegra no tiene ítems.']);

$total = (float)($fa['total'] ?? $f['monto_total']);
$igv   = round($total - ($total / 1.18), 2);
$base  = round($total - $igv, 2);

$resumen = [
    'factura'      => $f['numero_factura'] ?: ('#'.$alegraId),
    'cliente'      => $f['razon_social'],
    'tipo'         => $tipo,
    'tipo_label'   => AlegraService::NOTA_CR_TIPOS[$tipo],
    'motivo'       => $causa,
    'base'         => $base,
    'igv'          => $igv,
    'total'        => $total,
    'modo'         => ALEGRA_NC_REAL ? 'REAL (SUNAT)' : 'BORRADOR (prueba)',
];

if ($accion === 'preview') _j(['ok'=>true, 'resumen'=>$resumen]);

// ── EMITIR ──
$payload = AlegraService::construirNotaCredito($fa, $tipo, $causa, ALEGRA_NC_REAL);
[$hc, $resp] = AlegraService::crearNotaCredito($payload);

if (!in_array($hc, [200,201], true) || !isset($resp['id'])) {
    $err = is_array($resp) ? ($resp['message'] ?? json_encode($resp, JSON_UNESCAPED_UNICODE)) : 'Error HTTP '.$hc;
    error_log('[nota_credito] fallo: '.$err);
    _j(['ok'=>false,'msg'=>'Alegra rechazó la nota de crédito: '.$err]);
}

$numero = $resp['numberTemplate']['fullNumber'] ?? null;
$estado = ALEGRA_NC_REAL ? ($resp['stamp']['emissionStatus'] ?? 'stamped') : 'draft';

// PDF (solo emisión real timbrada; los borradores no generan PDF).
$pdfPath = null; $pdfNombre = null;
$pdfInfo = AlegraService::descargarPdfNota((int)$resp['id'], $clienteId, (string)($numero ?? ''));
if ($pdfInfo) { [$pdfPath, $pdfNombre] = $pdfInfo; }
else { error_log('[nota_credito] PDF no guardado: '.AlegraService::$lastError); }

$pdo->prepare("
    INSERT INTO notas_credito_cliente
      (factura_id, cliente_id, cotizacion_id, alegra_id, numero, tipo, motivo,
       monto_base, monto_igv, monto_total, estado_timbrado, alegra_resp,
       archivo_path, archivo_nombre, creado_por)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
")->execute([
    $facturaId, $clienteId, ($f['cotizacion_id'] ?: null), (int)$resp['id'], $numero, $tipo, $causa,
    $base, $igv, $total, $estado,
    json_encode(['status'=>$resp['status']??null,'stamp'=>$resp['stamp']??null], JSON_UNESCAPED_UNICODE),
    $pdfPath, $pdfNombre, $user['id'],
]);

log_actividad('facturas', 'nota_credito', "Nota de crédito ".($numero?:'(borrador)')." a la factura ".($f['numero_factura']?:('#'.$alegraId))." (".AlegraService::NOTA_CR_TIPOS[$tipo].") — modo ".$resumen['modo']);

_j(['ok'=>true, 'numero'=>$numero, 'estado'=>$estado, 'resumen'=>$resumen,
    'msg'=> (ALEGRA_NC_REAL ? 'Nota de crédito emitida a SUNAT: ' : 'Borrador de nota de crédito creado: ').($numero?:'(sin número)')]);
