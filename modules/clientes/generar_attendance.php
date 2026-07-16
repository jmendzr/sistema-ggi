<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../../vendor/autoload.php';
session_init();
require_login();
require_role('gerente_general');

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'msg' => 'Método no permitido.']); exit;
}
if (empty($_POST['_csrf'])) {
    $_POST['_csrf'] = $_POST['csrf_token'] ?? '';
}
csrf_verify();

$cliente_id = (int)($_POST['cliente_id'] ?? 0);
if (!$cliente_id) { echo json_encode(['ok' => false, 'msg' => 'ID de cliente inválido.']); exit; }

$pdo = Database::get();

$stmt = $pdo->prepare("SELECT id, ruc, razon_social, estado, fecha_contrato FROM clientes WHERE id = ? AND deleted_at IS NULL");
$stmt->execute([$cliente_id]);
$c = $stmt->fetch();

// ── Validaciones previas ─────────────────────────────────────────────────────
if (!$c) {
    echo json_encode(['ok' => false, 'msg' => 'Empresa no encontrada.']); exit;
}
if (empty($c['ruc'])) {
    echo json_encode(['ok' => false, 'msg' => 'La empresa no tiene RUC registrado.']); exit;
}
if (empty($c['razon_social'])) {
    echo json_encode(['ok' => false, 'msg' => 'La empresa no tiene razón social registrada.']); exit;
}

// ── Fecha base = cierre de la propuesta más reciente aceptada del cliente ─────
//    Si no existe (propuestas antiguas sin fecha registrada), usar fecha_contrato.
$fa = $pdo->prepare("
    SELECT fecha_aceptacion FROM cotizaciones
    WHERE cliente_id = ? AND estado = 'aceptada' AND fecha_aceptacion IS NOT NULL
    ORDER BY fecha_aceptacion DESC
    LIMIT 1
");
$fa->execute([$cliente_id]);
$fecha_base = $fa->fetchColumn() ?: ($c['fecha_contrato'] ?? null);

if (empty($fecha_base)) {
    echo json_encode(['ok' => false, 'msg' => 'No es posible generar el Attendance Sheet porque la empresa aún no tiene una propuesta aceptada ni fecha de activación.']); exit;
}

// ── Calcular fechas Opening / Closing (sin domingo) ──────────────────────────
function skip_sunday(int $ts): int {
    return (int)date('w', $ts) === 0 ? $ts - 86400 : $ts;
}

$ts_activo  = strtotime($fecha_base);
$ts_opening = skip_sunday($ts_activo - 2 * 86400);
$ts_closing = skip_sunday($ts_opening + 86400);

$meses_en = [
    1=>'January',2=>'February',3=>'March',4=>'April',
    5=>'May',6=>'June',7=>'July',8=>'August',
    9=>'September',10=>'October',11=>'November',12=>'December',
];

$fecha_opening  = $meses_en[(int)date('n', $ts_opening)] . ' ' . date('d', $ts_opening) . ', ' . date('Y', $ts_opening);
$fecha_closing  = $meses_en[(int)date('n', $ts_closing)] . ' ' . date('d', $ts_closing) . ', ' . date('Y', $ts_closing);
$nombre_empresa = htmlspecialchars(strtoupper(trim($c['razon_social'])), ENT_QUOTES | ENT_HTML5);
$fo             = htmlspecialchars($fecha_opening, ENT_QUOTES | ENT_HTML5);
$fc             = htmlspecialchars($fecha_closing, ENT_QUOTES | ENT_HTML5);

// ── HTML fiel al Word ────────────────────────────────────────────────────────
// Fuente: Times New Roman 11pt (sz=22 half-points)
// Bordes: single 0.5pt negro (sz=4)
// Sin colores ni fondos — replica exacta del .docx
// Columnas proporcionales a los anchos en twips del Word:
//   Sl No: 646 | Name: 3177 | Designation: 1865 | Opening: 1800 | Closing: 1800
// "Signature" en fila 4 cubre cols Opening+Closing (colspan=2)
// 28 filas totales replicando el original

// Genera filas vacías para firmas de asistentes
function empty_row(): string {
    return '<tr><td>&nbsp;</td><td></td><td></td><td></td><td></td></tr>';
}

$empty_rows = str_repeat(empty_row(), 17); // filas 12–28, igual que el Word original

$html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>
* { margin:0; padding:0; }
body {
    font-family: "Times New Roman", Times, serif;
    font-size: 11pt;
    margin: 1.8cm 1.5cm;
}
p.title {
    font-size: 11pt;
    font-weight: bold;
    text-align: center;
    margin-bottom: 6pt;
}
table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
}
col.c1 { width: 6.96%;  }
col.c2 { width: 34.21%; }
col.c3 { width: 20.08%; }
col.c4 { width: 19.38%; }
col.c5 { width: 19.38%; }
td {
    border: 0.5pt solid #000;
    padding: 3pt 4pt;
    font-size: 11pt;
    font-family: "Times New Roman", Times, serif;
    vertical-align: middle;
    word-wrap: break-word;
}
</style>
</head>
<body>

<p class="title">OPENING/CLOSING MEETING ATTENDENCE SHEET</p>

<table>
<colgroup>
  <col class="c1"><col class="c2"><col class="c3"><col class="c4"><col class="c5">
</colgroup>
<tbody>
  <!-- Row 1: Name of the organization -->
  <tr>
    <td colspan="2">Name of the organization</td>
    <td colspan="3">{$nombre_empresa}</td>
  </tr>
  <!-- Row 2: Place of meeting -->
  <tr>
    <td colspan="2">Place of meeting</td>
    <td colspan="3">{$nombre_empresa}</td>
  </tr>
  <!-- Row 3: Attendance list header -->
  <tr>
    <td colspan="5" style="text-align:justify;">Attendance list of the person of the organization attending the opening and closing meeting</td>
  </tr>
  <!-- Row 4: Signature label spanning Opening+Closing -->
  <tr>
    <td></td>
    <td></td>
    <td></td>
    <td colspan="2" style="text-align:center;font-weight:bold;">Signature</td>
  </tr>
  <!-- Row 5: Column headers -->
  <tr>
    <td>Sl No.</td>
    <td>Name of the person</td>
    <td>Designation</td>
    <td>Opening Meeting</td>
    <td>Closing Meeting</td>
  </tr>
  <!-- Row 6: JOSE MENDOZA RONDAN -->
  <tr>
    <td></td>
    <td>JOSE MENDOZA RONDAN</td>
    <td>Lead Auditor</td>
    <td>{$fo}</td>
    <td>{$fc}</td>
  </tr>
  <!-- Row 7: ARIAN BEZADA MAGUIÑA -->
  <tr>
    <td></td>
    <td>ARIAN BEZADA MAGUIÑA</td>
    <td>Auditor-If any</td>
    <td>{$fo}</td>
    <td>{$fc}</td>
  </tr>
  <!-- Row 8: empty -->
  <tr><td>&nbsp;</td><td></td><td></td><td></td><td></td></tr>
  <!-- Row 9: BETZY DAMAS ZAMORA -->
  <tr>
    <td></td>
    <td>BETZY DAMAS ZAMORA</td>
    <td>Director</td>
    <td>{$fo}</td>
    <td>{$fc}</td>
  </tr>
  <!-- Row 10: empty -->
  <tr><td>&nbsp;</td><td></td><td></td><td></td><td></td></tr>
  <!-- Row 11: MARIBEL MALDONADO SORIA -->
  <tr>
    <td></td>
    <td>MARIBEL MALDONADO SORIA</td>
    <td>MR</td>
    <td>{$fo}</td>
    <td>{$fc}</td>
  </tr>
  <!-- Rows 12–28: blank (empresa attendees) -->
  {$empty_rows}
</tbody>
</table>

</body>
</html>
HTML;

// ── Renderizar con Dompdf ────────────────────────────────────────────────────
try {
    $options = new \Dompdf\Options();
    $options->setIsRemoteEnabled(false);
    $options->setIsPhpEnabled(false);
    $options->setDefaultFont('Times New Roman');

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper([0, 0, 595.5, 842.25]); // A4 portrait
    $dompdf->render();
    $pdf_bytes = $dompdf->output();
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'msg' => 'Error al generar el PDF: ' . $e->getMessage()]); exit;
}

// ── Guardar archivo ──────────────────────────────────────────────────────────
$ruc     = $c['ruc'];
$carpeta = rtrim(UPLOAD_PATH, '/') . '/' . $ruc . '/';
if (!is_dir($carpeta)) {
    mkdir($carpeta, 0755, true);
    file_put_contents($carpeta . '.htaccess',
        "Options -Indexes\n<FilesMatch \"\\.php$\">\nDeny from all\n</FilesMatch>\n");
}

$nombre_archivo = 'attendance_sheet_' . $ruc . '.pdf';
$ruta_fisica    = $carpeta . $nombre_archivo;
$ruta_relativa  = $ruc . '/' . $nombre_archivo;

if (file_put_contents($ruta_fisica, $pdf_bytes) === false) {
    echo json_encode(['ok' => false, 'msg' => 'Error al guardar el archivo. Verifica permisos.']); exit;
}

// ── Versioning en documentos_cliente ────────────────────────────────────────
$doc_actual = $pdo->prepare("SELECT id, version FROM documentos_cliente WHERE cliente_id=? AND tipo_documento='attendance_sheet' AND es_vigente=1");
$doc_actual->execute([$cliente_id]);
$doc_actual    = $doc_actual->fetch();
$version_nueva = $doc_actual ? ($doc_actual['version'] + 1) : 1;

if ($doc_actual) {
    $pdo->prepare("UPDATE documentos_cliente SET es_vigente=0 WHERE id=?")
        ->execute([$doc_actual['id']]);
}

$ins = $pdo->prepare("
    INSERT INTO documentos_cliente
      (cliente_id, tipo_documento, nombre_archivo, ruta_archivo, mime_type, tamano_bytes, version, es_vigente, subido_por)
    VALUES (?, 'attendance_sheet', ?, ?, 'application/pdf', ?, ?, 1, ?)
");
$ins->execute([
    $cliente_id,
    $nombre_archivo,
    $ruta_relativa,
    strlen($pdf_bytes),
    $version_nueva,
    auth_user()['id'],
]);
$new_doc_id = (int)$pdo->lastInsertId();

// ── Auditoría ────────────────────────────────────────────────────────────────
log_actividad(
    'documentos',
    'attendance_sheet',
    "Attendance Sheet generado por Gerencia General — {$c['razon_social']} — RUC: {$ruc} — Opening: {$fecha_opening} — Closing: {$fecha_closing} — v{$version_nueva}"
);

echo json_encode([
    'ok'      => true,
    'msg'     => 'Attendance Sheet generado correctamente.',
    'doc_id'  => $new_doc_id,
    'version' => $version_nueva,
    'nombre'  => $nombre_archivo,
]);
