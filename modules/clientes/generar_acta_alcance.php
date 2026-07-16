<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../../core/NotificationService.php';
require_once __DIR__ . '/../../core/PushService.php';
require_once __DIR__ . '/../../vendor/autoload.php';
session_init();
require_login();
require_role('gerente_general', 'gerente_operaciones');

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'msg' => 'Método no permitido.']); exit;
}
if (empty($_POST['_csrf'])) {
    $_POST['_csrf'] = $_POST['csrf_token'] ?? '';
}
csrf_verify();

$cliente_id = (int)($_POST['cliente_id'] ?? 0);
$alcance    = trim($_POST['alcance']     ?? '');
$isos_sel   = array_map('intval', (array)($_POST['isos'] ?? []));

if (!$cliente_id) { echo json_encode(['ok' => false, 'msg' => 'Cliente requerido.']); exit; }
if ($alcance === '')  { echo json_encode(['ok' => false, 'msg' => 'El alcance es requerido.']); exit; }
if (empty($isos_sel)) { echo json_encode(['ok' => false, 'msg' => 'Selecciona al menos una norma ISO.']); exit; }

$pdo = Database::get();

// ── Datos del cliente ─────────────────────────────────────────────────────────
$cl_stmt = $pdo->prepare("SELECT id, razon_social, ruc, direccion FROM clientes WHERE id = ? AND deleted_at IS NULL LIMIT 1");
$cl_stmt->execute([$cliente_id]);
$cl = $cl_stmt->fetch();

if (!$cl)                       { echo json_encode(['ok' => false, 'msg' => 'Cliente no encontrado.']); exit; }
if (empty($cl['ruc']))          { echo json_encode(['ok' => false, 'msg' => 'La empresa no tiene RUC registrado.']); exit; }
if (empty($cl['razon_social'])) { echo json_encode(['ok' => false, 'msg' => 'La empresa no tiene razón social registrada.']); exit; }
if (empty($cl['direccion']))    { echo json_encode(['ok' => false, 'msg' => 'La empresa no tiene dirección registrada.']); exit; }

// ── Última cotización aceptada (fuente de ISOs) ───────────────────────────────
$cot_stmt = $pdo->prepare("
    SELECT id, codigo FROM cotizaciones
    WHERE cliente_id = ? AND estado = 'aceptada' AND deleted_at IS NULL
    ORDER BY id DESC LIMIT 1
");
$cot_stmt->execute([$cliente_id]);
$ultima_cot = $cot_stmt->fetch();

if (!$ultima_cot) {
    echo json_encode(['ok' => false, 'msg' => 'El cliente no tiene propuestas aceptadas.']); exit;
}

// ── Validar ISOs seleccionados contra la última cotización ────────────────────
$iso_stmt = $pdo->prepare("
    SELECT cn.norma_iso_id, n.codigo
    FROM cotizacion_normas cn
    JOIN normas_iso n ON n.id = cn.norma_iso_id
    WHERE cn.cotizacion_id = ?
    ORDER BY n.codigo
");
$iso_stmt->execute([$ultima_cot['id']]);
$isos_cotizacion = $iso_stmt->fetchAll();
$ids_validos     = array_map('intval', array_column($isos_cotizacion, 'norma_iso_id'));

$isos_sel_validos = array_values(array_filter($isos_sel, fn($id) => in_array($id, $ids_validos)));
if (empty($isos_sel_validos)) {
    echo json_encode(['ok' => false, 'msg' => 'Las normas seleccionadas no son válidas para esta propuesta.']); exit;
}

$isos_seleccionadas = array_filter($isos_cotizacion, fn($n) => in_array((int)$n['norma_iso_id'], $isos_sel_validos));
$isos_str = implode(', ', array_column(array_values($isos_seleccionadas), 'codigo'));

// ── Registro existente (un solo acta por cliente) ─────────────────────────────
$existing_stmt = $pdo->prepare("
    SELECT id, nombre_archivo, version FROM documentos_cliente
    WHERE cliente_id = ? AND tipo_documento = 'acta_alcance'
    LIMIT 1
");
$existing_stmt->execute([$cliente_id]);
$existing = $existing_stmt->fetch() ?: null;

// ── Versión: incrementa en cada regeneración ─────────────────────────────────
$version_nueva = $existing ? ((int)$existing['version'] + 1) : 1;
$version_pad   = sprintf('%02d', $version_nueva);

// ── Fechas ───────────────────────────────────────────────────────────────────
$ts = time();
$meses_es = [
    1=>'enero',2=>'febrero',3=>'marzo',4=>'abril',5=>'mayo',6=>'junio',
    7=>'julio',8=>'agosto',9=>'septiembre',10=>'octubre',11=>'noviembre',12=>'diciembre',
];
$dia              = (int)date('d', $ts);
$mes_num          = (int)date('n', $ts);
$anio             = (int)date('Y', $ts);
$hora_acta        = date('H:i', $ts);
$fecha_acta_texto = $dia . ' de ' . $meses_es[$mes_num] . ' del ' . $anio;
$fecha_aprobacion = date('d/m/Y', $ts);
$fecha_hora_firma = date('d/m/Y H:i:s', $ts);

// ── Escape helpers ───────────────────────────────────────────────────────────
$h = fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');

// Convierte texto libre en bloques <p> para que justify funcione correctamente
// (nl2br produce líneas cortas que Dompdf estira, generando texto disperso)
$to_p = function(string $v) use ($h): string {
    $lines = preg_split('/\r\n|\n|\r/', $v);
    $out = '';
    foreach ($lines as $line) {
        $line = trim($line);
        $out .= '<p class="alcance-p">' . ($line !== '' ? $h($line) : '&nbsp;') . '</p>';
    }
    return $out;
};

// ── Imágenes ─────────────────────────────────────────────────────────────────
$_pd      = __DIR__ . '/../../storage/assets/pdf/';
$logo_b64  = 'data:image/png;base64,' . base64_encode(file_get_contents($_pd . 'acta-logo.png'));
$firma_b64 = 'data:image/png;base64,' . base64_encode(file_get_contents($_pd . 'acta-firma.png'));

// ── Variables del documento ──────────────────────────────────────────────────
$nombre_empresa = $h(strtoupper(trim($cl['razon_social'])));
$ruc_val        = $h($cl['ruc']);
$direccion_val  = $h(trim($cl['direccion']));
$isos_h         = $h($isos_str);
$alcance_p      = $to_p($alcance);

$cw = ['logo'=>'22.4%','title'=>'44.8%','lbl'=>'13.4%','val'=>'19.4%'];

$html = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body {
    font-family: Calibri, Arial, sans-serif;
    font-size: 10pt;
    color: #000;
    /*
     * top:    hdr fijo top:10pt, 5 filas×~10.8pt (padding:1pt + line-height:1.1) = 54pt
     *         + logo 62pt limita a 10.7pt/fila → header ~64pt + 16pt de aire = 80pt
     * bottom: pie fijo en bottom:21.3pt, altura ~53pt → top del pie ≈ 74.3pt
     *         + 16pt de aire = 90pt
     */
    margin: 91pt 56.6pt 90pt 63.8pt;
}

/* ── Encabezado fijo: se repite en cada hoja ────────────────────────────── */
.hdr-fixed {
    position: fixed;
    top: 15pt;
    left: 63.8pt;
    right: 56.6pt;
    background: #fff;
}
.hdr-table { width:100%; border-collapse:collapse; font-size:9pt; }
.hdr-table td { border:0.5pt solid #000; padding:1pt 3pt; vertical-align:middle; line-height:1.1; }
.hdr-logo  { width:{$cw['logo']}; text-align:center; vertical-align:middle; }
.hdr-logo img { width:62pt; height:auto; display:block; margin:auto; }
.hdr-title { width:{$cw['title']}; text-align:center; vertical-align:middle; font-weight:bold; font-size:9pt; line-height:1.2; }
.hdr-lbl   { width:{$cw['lbl']}; font-size:8pt; font-weight:bold; background:#F2F2F2; vertical-align:middle; }
.hdr-val   { width:{$cw['val']}; font-size:8pt; vertical-align:middle; }

/* ── Pie fijo: se repite en cada hoja ──────────────────────────────────── */
.footer-firma {
    position: fixed;
    bottom: 21.3pt;
    left: 63.8pt;
    right: 56.6pt;
    border-top: 1pt solid #000;
    padding-top: 5pt;
    background: #fff;
}
.footer-firma table { width:100%; border-collapse:collapse; }
.footer-firma td    { border:none; padding:0; vertical-align:middle; }
.f-img { width:90pt; }
.f-img img { height:48pt; width:auto; display:block; }
.f-txt { font-size:7.5pt; line-height:1.6; padding-left:8pt; }

/* ── Contenido central ──────────────────────────────────────────────────── */
.titulo-acta    { font-size:14pt; font-weight:bold; text-align:center;
                  margin-top:16pt; margin-bottom:14pt; }
.titulo-seccion { font-size:14pt; font-weight:bold; text-align:center;
                  margin-top:22pt; margin-bottom:12pt; }
.cuerpo-texto   { font-size:10pt; text-align:justify; line-height:1.6; margin-bottom:10pt; }
.alcance-p      { font-size:10pt; text-align:justify; line-height:1.6; margin:0 0 5pt 0; }
</style>
</head>
<body>

<!-- Encabezado fijo: se repite en cada página -->
<div class="hdr-fixed">
<table class="hdr-table">
  <colgroup>
    <col style="width:{$cw['logo']}"><col style="width:{$cw['title']}">
    <col style="width:{$cw['lbl']}"><col style="width:{$cw['val']}">
  </colgroup>
  <tr>
    <td class="hdr-logo" rowspan="5"><img src="{$logo_b64}" alt="GGI"></td>
    <td class="hdr-title" rowspan="5">ALCANCE DE SISTEMA DE GESTIÓN INTEGRADO – {$isos_h}</td>
    <td class="hdr-lbl">Código</td><td class="hdr-val">IAF.150</td>
  </tr>
  <tr><td class="hdr-lbl">Versión</td><td class="hdr-val">{$version_pad}</td></tr>
  <tr><td class="hdr-lbl">F. Aprob.</td><td class="hdr-val">{$h($fecha_aprobacion)}</td></tr>
  <tr><td class="hdr-lbl">Aprob. por</td><td class="hdr-val">G. Operaciones</td></tr>
  <tr><td class="hdr-lbl">Página</td><td class="hdr-val" id="pgval">&nbsp;</td></tr>
</table>
</div>

<!-- Pie fijo: se repite en cada página -->
<div class="footer-firma">
  <table><tr>
    <td class="f-img"><img src="{$firma_b64}" alt="Firma"></td>
    <td class="f-txt">
      Firmado digitalmente por: <strong>ING. BEZADA MAGUIÑA ARIAN</strong><br>
      Fecha: {$h($fecha_hora_firma)}<br>
      Cargo: GERENTE DE OPERACIONES
    </td>
  </tr></table>
</div>

<!-- Contenido -->
<div class="titulo-acta">ACTA DE APROBACIÓN DE ALCANCE</div>

<p class="cuerpo-texto">
  Siendo las <strong>{$h($hora_acta)}</strong> hrs del día <strong>{$h($fecha_acta_texto)}</strong>,
  se reunieron el Gerente General y el Coordinador SIG, para definir y aprobar el alcance del
  sistema de gestión Integrado {$isos_h} para
  <strong>{$nombre_empresa}</strong>
  con <strong>RUC: {$ruc_val}</strong>
  y dirección <strong>{$direccion_val}</strong>
</p>

<div class="titulo-seccion">DETERMINACIÓN DEL ALCANCE</div>
{$alcance_p}

</body>
</html>
HTML;

// ── Generar PDF ───────────────────────────────────────────────────────────────
try {
    $options = new \Dompdf\Options();
    $options->setIsRemoteEnabled(false);
    $options->setIsPhpEnabled(false);
    $options->setDefaultFont('Calibri');
    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper([0, 0, 595.5, 842.25]);
    $dompdf->render();

    // Numeración de páginas vía canvas API (más confiable que <script type="text/php">)
    // El canvas aplica la cadena a cada página al momento de output()
    // x: inicio de la columna "val" del encabezado (left_margin=63.8 + 80.6% del ancho útil)
    // y: centro fila 5 -- top:10pt + 4.5x10.8pt (padding:1pt, line-height:1.1) ~58.6pt -> baseline ~56pt
    $canvas      = $dompdf->getCanvas();
    $fontMetrics = $dompdf->getFontMetrics();
    $font_pg     = $fontMetrics->getFont('DejaVu Sans', 'normal');
    $canvas->page_text(452, 66, '{PAGE_NUM} - {PAGE_COUNT}', $font_pg, 8, [0, 0, 0]);

    $pdf_bytes = $dompdf->output();
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'msg' => 'Error al generar el PDF: ' . $e->getMessage()]); exit;
}

// ── Guardar / sobreescribir archivo ───────────────────────────────────────────
$carpeta = rtrim(UPLOAD_PATH, '/') . '/' . $cl['ruc'] . '/';
if (!is_dir($carpeta)) {
    mkdir($carpeta, 0755, true);
    file_put_contents($carpeta . '.htaccess',
        "Options -Indexes\n<FilesMatch \"\\.php\$\">\nDeny from all\n</FilesMatch>\n");
}

$nombre_archivo = $cl['ruc'] . '_acta_aprobacion_alcance.pdf';
$ruta_fisica    = $carpeta . $nombre_archivo;
$ruta_relativa  = $cl['ruc'] . '/' . $nombre_archivo;

if (file_put_contents($ruta_fisica, $pdf_bytes) === false) {
    echo json_encode(['ok' => false, 'msg' => 'Error al guardar el archivo.']); exit;
}

// ── Upsert en BD (un solo registro por cliente) ───────────────────────────────
$user = auth_user();

if ($existing) {
    $pdo->prepare("
        UPDATE documentos_cliente SET
            cotizacion_id  = ?,
            nombre_archivo = ?,
            ruta_archivo   = ?,
            tamano_bytes   = ?,
            alcance_texto  = ?,
            version        = ?,
            confirmada     = 0,
            confirmada_por = NULL,
            confirmada_at  = NULL,
            estado_acta    = 'vigente',
            subido_por     = ?,
            uploaded_at    = NOW()
        WHERE id = ?
    ")->execute([
        $ultima_cot['id'], $nombre_archivo, $ruta_relativa,
        strlen($pdf_bytes), $alcance, $version_nueva, $user['id'], $existing['id'],
    ]);
    $doc_id = $existing['id'];
} else {
    $pdo->prepare("
        INSERT INTO documentos_cliente
          (cliente_id, cotizacion_id, tipo_documento, nombre_archivo, ruta_archivo,
           mime_type, tamano_bytes, alcance_texto, version, es_vigente, estado_acta, subido_por)
        VALUES (?, ?, 'acta_alcance', ?, ?, 'application/pdf', ?, ?, 1, 1, 'vigente', ?)
    ")->execute([
        $cliente_id, $ultima_cot['id'],
        $nombre_archivo, $ruta_relativa,
        strlen($pdf_bytes), $alcance, $user['id'],
    ]);
    $doc_id = (int)$pdo->lastInsertId();
}

// ── Auditoría ─────────────────────────────────────────────────────────────────
// Nombre del actor (primer nombre + primer apellido) para las notificaciones del acta.
$rol_label = trim((explode(' ', trim($user['nombre'] ?? ''))[0] ?? '') . ' ' . (explode(' ', trim($user['apellido'] ?? ''))[0] ?? '')) ?: 'Un usuario';
$accion    = $existing ? 'acta_alcance_regenerada' : 'acta_alcance_generada';
log_actividad(
    'documentos', $accion,
    "{$rol_label} " . ($existing ? 'regeneró' : 'generó') . " Acta de Aprobación de Alcance"
    . " para {$cl['razon_social']} — RUC: {$cl['ruc']}"
    . " — Propuesta: {$ultima_cot['codigo']} — ISOs: {$isos_str}"
);

NotificationService::dispatch('acta_alcance_generada', [
    'id'           => $doc_id,
    'cliente_id'   => $cliente_id,
    'cotizacion_id'=> (int)$ultima_cot['id'],
    'triggered_by' => $user['id'],
    'title'        => "Acta de Alcance " . ($existing ? 'regenerada' : 'generada') . " — {$cl['razon_social']}",
    'message'      => "{$rol_label} " . ($existing ? 'regeneró' : 'generó') . " el Acta de Alcance ({$isos_str}) para {$cl['razon_social']} (v{$version_pad}).",
]);

// El acta (re)generada resuelve la solicitud de cambios pendiente del cliente.
NotificationService::markResolved(['acta_alcance_cambios_solicitados'], (int)$cliente_id);

// Notificar al ejecutivo responsable
$exec_stmt = $pdo->prepare("
    SELECT COALESCE(c.responsible_user_id, c.created_by_user_id) AS ejecutivo_id
    FROM clientes c
    WHERE c.id = ?
");
$exec_stmt->execute([$cliente_id]);
$exec_result = $exec_stmt->fetch();

if ($exec_result && $exec_result['ejecutivo_id']) {
    $titulo_notif = "Acta de Alcance — {$cl['razon_social']}";
    $mensaje_notif = 'Acta regenerada, listo para revisar';

    $notif_stmt = $pdo->prepare("
        INSERT INTO notificaciones (usuario_id, tipo, titulo, mensaje, priority, reference_id, event_code)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $notif_stmt->execute([
        $exec_result['ejecutivo_id'],
        'acta_alcance_generada',
        $titulo_notif,
        $mensaje_notif,
        5,
        $cliente_id,
        'acta_alcance_generada'
    ]);

    // Enviar push notification
    $url_cliente = APP_URL . '/modules/clientes/ver.php?id=' . $cliente_id . '#acta';
    PushService::notify(
        (int)$exec_result['ejecutivo_id'],
        $titulo_notif,
        $mensaje_notif,
        $url_cliente,
        'acta-generada-' . $doc_id
    );
}

echo json_encode([
    'ok'     => true,
    'msg'    => 'Acta generada correctamente.',
    'doc_id' => $doc_id,
    'nombre' => $nombre_archivo,
]);
