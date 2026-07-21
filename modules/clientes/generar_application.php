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
csrf_verify();

$cliente_id = (int)($_POST['cliente_id'] ?? 0);
$phone      = trim($_POST['phone']           ?? '');
$email      = trim($_POST['email']           ?? '');
$alcance_es = trim($_POST['alcance_espanol'] ?? '');
$alcance_en = trim($_POST['alcance_ingles']  ?? '');
$isos_sel = array_map('intval', (array)($_POST['isos'] ?? []));

if (!$cliente_id)    { echo json_encode(['ok'=>false,'msg'=>'ID de cliente inválido.']); exit; }
if (empty($phone))   { echo json_encode(['ok'=>false,'msg'=>'El teléfono/fax es requerido.']); exit; }
if (empty($email))   { echo json_encode(['ok'=>false,'msg'=>'El email es requerido.']); exit; }
if (empty($alcance_es)) { echo json_encode(['ok'=>false,'msg'=>'El alcance en español es requerido.']); exit; }
if (empty($alcance_en)) { echo json_encode(['ok'=>false,'msg'=>'El alcance en inglés es requerido.']); exit; }

$pdo  = Database::get();
$stmt = $pdo->prepare("
    SELECT id, ruc, razon_social, direccion,
           contacto_nombre, contacto_apellido, num_trabajadores
    FROM clientes WHERE id = ? AND deleted_at IS NULL
");
$stmt->execute([$cliente_id]);
$c = $stmt->fetch();

if (!$c)               { echo json_encode(['ok'=>false,'msg'=>'Empresa no encontrada.']); exit; }
if (empty($c['ruc']))  { echo json_encode(['ok'=>false,'msg'=>'La empresa no tiene RUC registrado.']); exit; }

// ── Helpers ──────────────────────────────────────────────────────────────────
$h = fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_HTML5);

$nombre_empresa = $h(strtoupper(trim($c['razon_social'])));
$direccion      = $h(trim($c['direccion'] ?? ''));
$ruc_val        = $h($c['ruc']);
$celular        = $h($phone);
$correo         = $h($email);
$gerente        = $h(strtoupper(trim($c['contacto_apellido'] . ' ' . $c['contacto_nombre'])));
$colaboradores  = $c['num_trabajadores'] !== null ? (int)$c['num_trabajadores'] : '';
$alc_es         = nl2br($h($alcance_es));
$alc_en         = nl2br($h($alcance_en));

// ── ISO standards: todas las normas del sistema ──────────────────────────────
$all_normas = $pdo->query("SELECT id, codigo FROM normas_iso ORDER BY id")->fetchAll();

// Renderiza cada norma con un checkbox CSS (funciona en dompdf 3.x)
$iso_parts = [];
foreach ($all_normas as $norma) {
    $label   = $h($norma['codigo']);
    $checked = in_array((int)$norma['id'], $isos_sel);
    $box_bg  = $checked ? 'background:#000;' : '';
    $tick    = $checked ? '<span style="color:#fff;font-size:5pt;line-height:7pt;">&#x2713;</span>' : '';
    $box     = '<span style="display:inline-block;width:7pt;height:7pt;border:0.5pt solid #000;'
             . $box_bg . 'vertical-align:middle;text-align:center;font-size:6pt;line-height:7pt;color:#fff;">'
             . ($checked ? 'X' : '') . '</span>';
    $iso_parts[] = '<span style="white-space:nowrap;">' . $box . '&nbsp;<span style="font-size:8pt;">' . $label . '</span></span>';
}
$iso_standards = implode('&nbsp;&nbsp;', $iso_parts);

// ── Márgenes del Word (twips → mm): top=822/56.7=14.5mm, otros=1417/56.7=25mm ──
// Columnas en % de 9464 twips totales:
//   T1/T2: 17.62% | 26.96% | 16.47% | 38.95%
//   T3:    17.62% | 26.96% | 20.98% | 34.44%
//   T4 grid: 22.12% | 44.55% | 9.37% | 23.96%
//   T5:    28.11% | 71.89%
//   T8/T9: 76.04% | 23.96%
//   T10:   13.12% | 86.88%
//   T11:   17.62% | 26.96% | 19.47% | 35.95%

$bg   = '#F3F3F3'; // color de fondo de celdas etiqueta (del Word)

// dompdf no renderiza <svg> inline; usar <img> con data URI
$check_svg_raw = '<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 22 22">'
    . '<rect x="1" y="1" width="20" height="20" fill="none" stroke="#000" stroke-width="1"/>'
    . '<polyline points="4,11 9,16 18,5" fill="none" stroke="#000" stroke-width="2"/>'
    . '</svg>';
$check_img = '<img src="data:image/svg+xml;base64,' . base64_encode($check_svg_raw) . '" width="22" height="22" style="display:block;margin:auto;">';

$html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body {
    font-family: Calibri, Arial, sans-serif;
    font-size: 9pt;
    margin: 14.5mm 25mm 25mm 25mm;
    color: #000;
}
h1.doc-title {
    font-size: 12pt;
    font-weight: bold;
    text-align: center;
    margin-bottom: 5pt;
    font-family: Calibri, Arial, sans-serif;
}
table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
    margin-bottom: 5pt;
}
td {
    border: 0.5pt solid #000;
    padding: 2.5pt 4pt;
    font-size: 9pt;
    font-family: Calibri, Arial, sans-serif;
    vertical-align: middle;
    word-wrap: break-word;
}
.lbl  { background: {$bg}; font-size: 9pt; }
.data { background: #fff; }
.hdr  { background: {$bg}; font-size: 9pt; font-weight: bold; }
.bold { font-weight: bold; }
.sm   { font-size: 8pt; }
.content-area { background: #fff; min-height: 28pt; vertical-align: top; padding: 3pt 4pt; }
</style>
</head>
<body>

<h1 class="doc-title">Application for Certification</h1>

<!-- ─── TABLE 1: Organization name / Regd. Office ─── -->
<table>
<colgroup>
  <col style="width:17.62%"><col style="width:26.96%">
  <col style="width:16.47%"><col style="width:38.95%">
</colgroup>
<tr>
  <td class="lbl">Organization name:</td>
  <td class="data bold">{$nombre_empresa}</td>
  <td class="lbl" style="font-style:italic;font-weight:bold;">Regd. Office/H.O.:</td>
  <td class="data">{$direccion}</td>
</tr>
</table>

<!-- ─── TABLE 2: Legal identity / Phone / Email ─── -->
<table>
<colgroup>
  <col style="width:17.62%"><col style="width:26.96%">
  <col style="width:16.47%"><col style="width:38.95%">
</colgroup>
<tr>
  <td class="lbl">Organization legal identity</td>
  <td class="data">{$ruc_val}</td>
  <td class="lbl">Phone:fax:</td>
  <td class="data">{$celular}</td>
</tr>
<tr>
  <td class="lbl">web:</td>
  <td class="data"></td>
  <td class="lbl">e-mail:</td>
  <td class="data">{$correo}</td>
</tr>
</table>

<!-- ─── TABLE 3: Director/CEO / Person in charge ─── -->
<table>
<colgroup>
  <col style="width:17.62%"><col style="width:26.96%">
  <col style="width:20.98%"><col style="width:34.44%">
</colgroup>
<tr>
  <td class="lbl">Director / CEO of the organization:</td>
  <td class="data">{$gerente}</td>
  <td class="lbl">Person in charge of communication with certification body:</td>
  <td class="data">{$gerente}</td>
</tr>
</table>

<!-- ─── TABLE 4: Locations to be certified (4-col grid, rows differ) ─── -->
<table>
<colgroup>
  <col style="width:22.12%"><col style="width:44.55%">
  <col style="width:9.37%"><col style="width:23.96%">
</colgroup>
<tr>
  <td class="lbl bold">Locations to be certified</td>
  <td class="lbl bold" colspan="2">Address:</td>
  <td class="lbl bold">Number of employees:</td>
</tr>
<tr>
  <td class="data">{$direccion}</td>
  <td class="data">Same as above</td>
  <td class="data" colspan="2">{$colaboradores}</td>
</tr>
</table>

<!-- ─── TABLE 5: Employee breakdown ─── -->
<table>
<colgroup><col style="width:28.11%"><col style="width:71.89%"></colgroup>
<tr><td class="lbl bold" colspan="2">Number of employees</td></tr>
<tr><td class="lbl sm">- in production:</td><td class="data">0</td></tr>
<tr><td class="lbl sm">- in administration:</td><td class="data">0</td></tr>
<tr><td class="lbl sm">-Skilled:</td><td class="data">0</td></tr>
<tr><td class="lbl sm">Unskilled:</td><td class="data">0</td></tr>
<tr><td class="lbl sm">Repetitive:</td><td class="data">0</td></tr>
<tr><td class="lbl bold">Total workmen:</td><td class="data bold">{$colaboradores}</td></tr>
<tr><td class="lbl sm">No. of contractor's employees</td><td class="data">0</td></tr>
<tr><td class="lbl sm">Part time: <span style="padding-right:20pt;">0</span> No. of shifts:</td><td class="data">0</td></tr>
<tr><td class="lbl sm">Total no. of effective personnel <span class="sm" style="font-size:7.5pt;">(to be calculated by QROCERT)</span></td><td class="data">0</td></tr>
</table>

<!-- ─── TABLE 6: Scope in Spanish ─── -->
<table>
<tr><td class="hdr"><span class="bold">Scope in Spanish</span> <span class="sm">(specify those, which shall be the subject matter of an audit)</span></td></tr>
<tr><td class="content-area">{$alc_es}</td></tr>
</table>

<!-- ─── TABLE 7: Scope in English + Processes + Legal + Outsourced ─── -->
<table>
<tr><td class="hdr"><span class="bold">Scope in English</span> <span class="sm">(specify those, which shall be the subject matter of an audit)</span></td></tr>
<tr><td class="content-area">{$alc_en}</td></tr>
<tr><td class="hdr">Orgnaisation Processes and key equipments</td></tr>
<tr><td class="hdr"><span class="bold">Stataus of legal obligations</span> <span class="sm">(tick if valid certificate / license / approval is available)</span><br><span class="sm">a.&nbsp;Organization's legal identity document &nbsp; b.&nbsp;Factory license &nbsp; c.&nbsp;PCB consent &nbsp; d.&nbsp;PAN &nbsp; e.&nbsp;GST &nbsp; f.&nbsp;write other applicable</span></td></tr>
<tr><td class="hdr"><span class="bold">Externally provided (outsourced) processes</span> <span class="sm">(specify externally provided processes, which influence your management system – e.g. maintenance, IT administration, ...)</span></td></tr>
<tr><td class="content-area">N/A</td></tr>
</table>

<!-- ─── TABLE 8: Existing certificate ─── -->
<table>
<colgroup><col style="width:76.04%"><col style="width:23.96%"></colgroup>
<tr><td class="hdr" colspan="2">Do you own a valid management system certificate presently?</td></tr>
<tr>
  <td class="data sm">Yes,&nbsp;according to standard _________________, certification body _________________</td>
  <td class="data sm bold">No</td>
</tr>
</table>

<!-- ─── TABLE 9: Consultant ─── -->
<table>
<colgroup><col style="width:76.04%"><col style="width:23.96%"></colgroup>
<tr><td class="hdr" colspan="2">Do you use services of any consultant?</td></tr>
<tr>
  <td class="data sm">Yes,&nbsp;Organization or person's name: _________________________________</td>
  <td class="data sm bold">No</td>
</tr>
</table>

<!-- ─── TABLE 10: Service / ISO standards (2 cols, igual al Word) ─── -->
<table>
<colgroup><col style="width:13.12%"><col style="width:86.88%"></colgroup>
<tr>
  <td class="lbl sm">What service are you interested in?</td>
  <td class="data" style="vertical-align:top;">
    <span class="bold">Certification audit according to standard:</span><br>{$iso_standards}
  </td>
</tr>
</table>

<!-- ─── TABLE 11: Signature ─── -->
<table>
<colgroup>
  <col style="width:17.62%"><col style="width:26.96%">
  <col style="width:19.47%"><col style="width:35.95%">
</colgroup>
<tr><td class="hdr" colspan="4">Correspondence address:</td></tr>
<tr><td class="content-area" colspan="4"><div style="min-height:16pt;">{$direccion}</div></td></tr>
<tr>
  <td class="lbl sm bold">Place, date</td>
  <td class="data" style="vertical-align:top;">{$direccion}<div style="height:18pt;"></div></td>
  <td class="lbl sm bold">Name and Signature of Director / CEO</td>
  <td class="data" style="text-align:center;vertical-align:middle;">{$check_img}</td>
</tr>
</table>

</body>
</html>
HTML;

// ── Renderizar con Dompdf ────────────────────────────────────────────────────
try {
    $options = new \Dompdf\Options();
    $options->setIsRemoteEnabled(false);
    $options->setIsPhpEnabled(false);
    $options->setDefaultFont('Calibri');

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper([0, 0, 595.5, 842.25]); // A4 portrait
    $dompdf->render();
    $pdf_bytes = $dompdf->output();
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'msg' => 'Error al generar el PDF: ' . $e->getMessage()]); exit;
}

// ── Guardar archivo ──────────────────────────────────────────────────────────
$ruc_dir  = $c['ruc'];
$carpeta  = rtrim(UPLOAD_PATH, '/') . '/' . $ruc_dir . '/';
if (!is_dir($carpeta)) {
    mkdir($carpeta, 0755, true);
    file_put_contents($carpeta . '.htaccess',
        "Options -Indexes\n<FilesMatch \"\\.php$\">\nDeny from all\n</FilesMatch>\n");
}

$nombre_archivo = 'application_form_' . $ruc_dir . '.pdf';
$ruta_fisica    = $carpeta . $nombre_archivo;
$ruta_relativa  = $ruc_dir . '/' . $nombre_archivo;

if (file_put_contents($ruta_fisica, $pdf_bytes) === false) {
    echo json_encode(['ok' => false, 'msg' => 'Error al guardar el archivo. Verifica permisos.']); exit;
}

// ── Versioning ────────────────────────────────────────────────────────────────
$doc_actual = $pdo->prepare("
    SELECT id, version FROM documentos_cliente
    WHERE cliente_id=? AND tipo_documento='application_form' AND es_vigente=1
");
$doc_actual->execute([$cliente_id]);
$doc_actual    = $doc_actual->fetch();
$version_nueva = $doc_actual ? ($doc_actual['version'] + 1) : 1;

if ($doc_actual) {
    $pdo->prepare("UPDATE documentos_cliente SET es_vigente=0 WHERE id=?")
        ->execute([$doc_actual['id']]);
}

// Guardar campos del formulario como JSON para re-poblar en futuras regeneraciones
$alcance_data = json_encode([
    'phone'      => $phone,
    'email'      => $email,
    'alcance_es' => $alcance_es,
    'alcance_en' => $alcance_en,
    'isos'       => $isos_sel_validos,
], JSON_UNESCAPED_UNICODE);

$ins = $pdo->prepare("
    INSERT INTO documentos_cliente
      (cliente_id, tipo_documento, nombre_archivo, ruta_archivo, mime_type, tamano_bytes, alcance_texto, version, es_vigente, subido_por)
    VALUES (?, 'application_form', ?, ?, 'application/pdf', ?, ?, ?, 1, ?)
");
$ins->execute([
    $cliente_id, $nombre_archivo, $ruta_relativa,
    strlen($pdf_bytes), $alcance_data, $version_nueva, auth_user()['id'],
]);
$new_doc_id = (int)$pdo->lastInsertId();

$isos_log = array_column(array_filter($all_normas, fn($n) => in_array((int)$n['id'], $isos_sel)), 'codigo');
log_actividad(
    'documentos', 'application_form',
    "Application Form generado — {$c['razon_social']} — RUC: {$ruc_dir} — v{$version_nueva} — ISOs: " . implode(', ', $isos_log)
);

echo json_encode([
    'ok'      => true,
    'msg'     => 'Application Form generado correctamente.',
    'doc_id'  => $new_doc_id,
    'version' => $version_nueva,
    'nombre'  => $nombre_archivo,
]);
