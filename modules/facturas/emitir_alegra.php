<?php
/**
 * modules/facturas/emitir_alegra.php — Emite (o previsualiza) una factura vía Alegra.
 *
 * Solo gerente_general. Mientras ALEGRA_EMITIR_REAL sea false, crea BORRADORES
 * (no va a SUNAT). Acciones: 'preview' (calcula montos sin crear) | 'emitir'.
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
if (!can('facturas_emitir')) _j(['ok'=>false,'msg'=>'No tienes permiso para emitir facturas.']);

$user   = auth_user();
$pdo    = Database::get();
$accion = trim($_POST['accion'] ?? 'preview');
$cotId  = (int)($_POST['cotizacion_id'] ?? 0);
$tipo   = trim($_POST['tipo'] ?? '');              // total | anticipo | saldo
$detMod = trim($_POST['detraccion_modo'] ?? 'auto'); // auto | cliente

if (!$cotId || !in_array($tipo, ['total','anticipo','saldo'], true)) _j(['ok'=>false,'msg'=>'Parámetros inválidos.']);
if (!in_array($detMod, ['auto','cliente'], true)) $detMod = 'auto';
if (!AlegraService::configurada()) _j(['ok'=>false,'msg'=>'Alegra no está configurado (.env).']);

// ── Cotización + cliente ──
$cot = $pdo->prepare("
    SELECT c.*, cl.razon_social, cl.ruc, cl.direccion
    FROM cotizaciones c JOIN clientes cl ON cl.id = c.cliente_id
    WHERE c.id = ? AND c.deleted_at IS NULL
");
$cot->execute([$cotId]);
$cot = $cot->fetch(\PDO::FETCH_ASSOC);
if (!$cot) _j(['ok'=>false,'msg'=>'Cotización no encontrada.']);
if ($cot['estado'] !== 'aceptada') _j(['ok'=>false,'msg'=>'La cotización no está aceptada; no se puede facturar.']);
if ((int)$cot['incluye_igv'] !== 1) _j(['ok'=>false,'msg'=>'Esta cotización no incluye IGV; no se factura por Alegra.']);
if (empty($cot['ruc'])) _j(['ok'=>false,'msg'=>'El cliente no tiene RUC registrado.']);
if (strtoupper((string)($cot['moneda'] ?? 'PEN')) !== 'PEN') _j(['ok'=>false,'msg'=>'Esta cotización es en '.$cot['moneda'].'. La emisión automática por ahora solo soporta soles (PEN); emítela manualmente en Alegra.']);

$clienteId = (int)$cot['cliente_id'];

// ── Normas de la cotización → descripción ──
$nq = $pdo->prepare("SELECT ni.codigo FROM cotizacion_normas cn JOIN normas_iso ni ON ni.id = cn.norma_iso_id WHERE cn.cotizacion_id = ? ORDER BY cn.id ASC");
$nq->execute([$cotId]);
// codigo = "ISO 37001:2016" → "ISO 37001" (quita el año); "SA8000:2026" → "SA8000".
$normas = array_map(function($r){ return trim(preg_replace('/:.*/', '', (string)$r['codigo'])); }, $nq->fetchAll(\PDO::FETCH_ASSOC));

// ── Base según tipo ──
$subtotal = (float)$cot['subtotal'];
if ($tipo === 'total') {
    $base = round($subtotal, 2);
    // Total: TODOS los pagos deben estar validados.
    $pc = $pdo->prepare("SELECT COUNT(*) t, SUM(estado='validado') ok FROM pagos_cliente WHERE cotizacion_id=? AND deleted_at IS NULL");
    $pc->execute([$cotId]);
    $pr = $pc->fetch(\PDO::FETCH_ASSOC);
    if (!($pr && (int)$pr['t'] > 0 && (int)$pr['t'] === (int)$pr['ok'])) {
        _j(['ok'=>false,'msg'=>'Para facturar al total, TODOS los pagos deben estar validados.']);
    }
} else {
    // anticipo → pago 'inicial'; saldo → pago 'final'. Base = monto (con IGV) / 1.18
    $ptipo = $tipo === 'anticipo' ? 'inicial' : 'final';
    $pg = $pdo->prepare("SELECT monto, estado FROM pagos_cliente WHERE cotizacion_id=? AND tipo=? AND deleted_at IS NULL ORDER BY id ASC LIMIT 1");
    $pg->execute([$cotId, $ptipo]);
    $pago = $pg->fetch(\PDO::FETCH_ASSOC);
    if (!$pago || (float)$pago['monto'] <= 0) _j(['ok'=>false,'msg'=>"No hay pago '$ptipo' registrado en esta cotización."]);
    if ($pago['estado'] !== 'validado') _j(['ok'=>false,'msg'=>"El pago '$ptipo' aún no está validado. Valídalo antes de facturar."]);
    $base = round((float)$pago['monto'] / 1.18, 2);
}
if ($base <= 0) _j(['ok'=>false,'msg'=>'La base de la factura es 0; revisa los montos de la cotización.']);

// ── Referencia del anticipo para el saldo ──
$refAnticipo = null;
if ($tipo === 'saldo') {
    $ra = $pdo->prepare("SELECT numero_factura FROM facturas_cliente WHERE cotizacion_id=? AND tipo_factura='anticipo' AND origen='alegra' AND estado_timbrado <> 'error' ORDER BY id DESC LIMIT 1");
    $ra->execute([$cotId]);
    $refAnticipo = $ra->fetchColumn() ?: null;
    if (!$refAnticipo) _j(['ok'=>false,'msg'=>'No se encontró la factura de anticipo previa (emítela primero).']);
}

// ── Evitar duplicados ──
$ya = $pdo->prepare("SELECT tipo_factura FROM facturas_cliente WHERE cotizacion_id=? AND origen='alegra' AND estado_timbrado <> 'error'");
$ya->execute([$cotId]);
$emitidos = array_column($ya->fetchAll(\PDO::FETCH_ASSOC), 'tipo_factura');
if (in_array($tipo, $emitidos, true)) _j(['ok'=>false,'msg'=>"Ya existe una factura de tipo '$tipo' para esta cotización."]);
if ($tipo === 'total' && (in_array('anticipo',$emitidos,true) || in_array('saldo',$emitidos,true))) _j(['ok'=>false,'msg'=>'Esta cotización ya tiene facturas de anticipo/saldo.']);
if (in_array('total',$emitidos,true)) _j(['ok'=>false,'msg'=>'Esta cotización ya se facturó al total.']);

// ── Montos ──
$igv   = round($base * 0.18, 2);
$total = round($base + $igv, 2);
$aplicaDet = ($total > AlegraService::DETRACCION_MIN);
$detMonto  = $aplicaDet ? round($total * 0.12, 2) : 0.0;
$detModoFinal = $aplicaDet ? $detMod : 'ninguna';
$descripcion  = AlegraService::descripcionNormas($normas, $tipo, $refAnticipo);

$resumen = [
    'tipo'          => $tipo,
    'base'          => $base,
    'igv'           => $igv,
    'total'         => $total,
    'detraccion'    => $detMonto,
    'detraccion_modo'=> $detModoFinal,
    'operation_type'=> ($detMod==='cliente' && $aplicaDet) ? 'SALE_SUBJECT_TO_DEDUCTION' : 'INTERNAL_SALE',
    'descripcion'   => $descripcion,
    'cliente'       => $cot['razon_social'],
    'ruc'           => $cot['ruc'],
    'ref_anticipo'  => $refAnticipo,
    'modo'          => ALEGRA_EMITIR_REAL ? 'REAL (SUNAT)' : 'BORRADOR (prueba)',
];

// ── Avisos de facturas ya existentes (para no duplicar) ──
//    (a) en el sistema (facturas_cliente de esta cotización) y
//    (b) en Alegra directamente (facturas del cliente emitidas a mano, aún no cargadas).
$avisos = [];
$fg = $pdo->prepare("SELECT numero_factura, origen, tipo_factura, monto_total, created_at FROM facturas_cliente WHERE cotizacion_id=? ORDER BY id DESC");
$fg->execute([$cotId]);
foreach ($fg->fetchAll(\PDO::FETCH_ASSOC) as $f) {
    $avisos[] = ['fuente'=>'sistema', 'numero'=>$f['numero_factura'] ?: '(sin nº)', 'origen'=>$f['origen'], 'total'=>$f['monto_total'], 'fecha'=>substr((string)$f['created_at'],0,10)];
}
$facturasAlegra = [];
$cid = AlegraService::buscarContacto((string)$cot['ruc']);
if ($cid) {
    foreach (AlegraService::facturasDeContacto($cid, 8) as $fa) {
        $facturasAlegra[] = $fa;
    }
}

// ── PREVIEW: resumen + avisos ──
if ($accion === 'preview') _j(['ok'=>true, 'resumen'=>$resumen, 'facturas_sistema'=>$avisos, 'facturas_alegra'=>$facturasAlegra]);

// ── EMITIR ──
$cliente = ['ruc'=>$cot['ruc'], 'razon_social'=>$cot['razon_social'], 'direccion'=>($cot['direccion'] ?: '')];
$contactId = AlegraService::buscarOCrearContacto($cliente);
if (!$contactId) _j(['ok'=>false,'msg'=>'No se pudo obtener el cliente en Alegra: '.AlegraService::$lastError]);

$payload = AlegraService::construirPayload($contactId, $tipo, $base, $descripcion, $detModoFinal, ALEGRA_EMITIR_REAL);
[$hc, $resp] = AlegraService::crear($payload);

if (!in_array($hc, [200,201], true) || !isset($resp['id'])) {
    $err = is_array($resp) ? ($resp['message'] ?? json_encode($resp, JSON_UNESCAPED_UNICODE)) : 'Error HTTP '.$hc;
    error_log('[emitir_alegra] fallo: '.$err);
    _j(['ok'=>false,'msg'=>'Alegra rechazó la factura: '.$err]);
}

$numero = $resp['numberTemplate']['fullNumber'] ?? null;
$stamp  = $resp['stamp'] ?? null;
// SUNAT es asíncrono: al emitir suele quedar "waiting" y minutos después "accepted".
$clase  = ALEGRA_EMITIR_REAL ? AlegraService::clasificarTimbrado($stamp) : 'draft';
$estado = ALEGRA_EMITIR_REAL ? ($stamp['emissionStatus'] ?? 'STAMPED') : 'draft';

// Insertar SIN PDF ni envío todavía. El PDF definitivo y el WhatsApp al cliente
// solo se hacen cuando SUNAT ACEPTA (ahora si es inmediato; si no, vía cron).
$pdo->prepare("
    INSERT INTO facturas_cliente
      (cliente_id, cotizacion_id, origen, numero_factura, alegra_id, tipo_factura, operation_type,
       detraccion_modo, monto_base, monto_igv, monto_total, detraccion_monto, estado_timbrado, alegra_resp,
       archivo_path, archivo_nombre, subido_por)
    VALUES (?,?, 'alegra', ?,?,?,?,?,?,?,?,?,?,?, NULL, NULL, ?)
")->execute([
    $clienteId, $cotId, $numero, (int)$resp['id'], $tipo, $resumen['operation_type'],
    $detModoFinal, $base, $igv, $total, $detMonto, $estado,
    json_encode(['status'=>$resp['status']??null,'stamp'=>$stamp], JSON_UNESCAPED_UNICODE),
    $user['id'],
]);
$facturaRowId = (int)$pdo->lastInsertId();

log_actividad('facturas', 'emitir_alegra', "Factura $tipo ".($numero?:'(borrador)')." emitida vía Alegra (cotización #$cotId, modo ".($resumen['modo'])." · SUNAT: $clase)");

// ── Según el estado SUNAT ──
$waEnviado = false; $espera = false; $rechazada = false;
if ($clase === 'accepted') {
    // Aceptada de inmediato → descargar PDF definitivo + enviar al cliente.
    require_once __DIR__ . '/factura_finalizar.php';
    $fin = factura_finalizar_aceptada($pdo, $facturaRowId, $user['id']);
    $waEnviado = !empty($fin['wa']);
} elseif ($clase === 'rejected') {
    $rechazada = true;
    error_log("[emitir_alegra] SUNAT rechazó $numero: ".json_encode($stamp, JSON_UNESCAPED_UNICODE));
} elseif ($clase !== 'draft') {
    // waiting / unknown → el cron de re-chequeo la completará al aceptarse.
    $espera = true;
}

if (!ALEGRA_EMITIR_REAL) {
    $msg = 'Borrador creado en Alegra: '.($numero ?: '(sin número)');
} elseif ($rechazada) {
    $msg = 'SUNAT RECHAZÓ la factura '.($numero ?: '').'. Revísala en Alegra; NO se envió al cliente.';
} elseif ($clase === 'accepted') {
    $msg = 'Factura emitida y aceptada por SUNAT: '.$numero.($waEnviado ? ' · enviada al cliente por WhatsApp' : '');
} else {
    $msg = 'Factura emitida ('.$numero.'). Esperando la aceptación de SUNAT; al aceptarse se descarga el PDF y se envía al cliente automáticamente.';
}

_j(['ok'=>true, 'numero'=>$numero, 'estado'=>$estado, 'clase'=>$clase, 'resumen'=>$resumen,
    'wa_enviado'=>$waEnviado, 'espera'=>$espera, 'rechazada'=>$rechazada, 'msg'=>$msg]);
