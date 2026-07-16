<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../../vendor/autoload.php';
session_init();
require_login();
require_role('gerente_general', 'gerente_operaciones');

$pdo = Database::get();

$ej_id = (int)($_GET['ejecutivo_id'] ?? 0);
$year  = (int)($_GET['year']  ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n'));
$month = max(1, min(12, $month));

if (!$ej_id) { http_response_code(400); exit('Ejecutivo requerido.'); }

// Datos del ejecutivo (salario visible solo para gerentes — esta ruta ya los requiere)
$ej_stmt = $pdo->prepare("SELECT id, nombre, apellido, salario_mensual FROM usuarios WHERE id=? AND rol='ejecutivo_ventas' AND activo=1 LIMIT 1");
$ej_stmt->execute([$ej_id]);
$ej = $ej_stmt->fetch();
if (!$ej) { http_response_code(404); exit('Ejecutivo no encontrado.'); }

// Bonos pendientes del período — rango explícito para usar índice compuesto
$periodo_ini = sprintf('%04d-%02d-01 00:00:00', $year, $month);
$periodo_fin = date('Y-m-t 23:59:59', mktime(0, 0, 0, $month, 1, $year));
$stmt = $pdo->prepare("
    SELECT be.id, be.monto, be.cotizacion_id, n.codigo AS norma_codigo
    FROM bonos_ejecutivos be
    JOIN normas_iso n ON n.id = be.norma_iso_id
    WHERE be.ejecutivo_id = ?
      AND be.estado = 'pendiente'
      AND be.created_at BETWEEN ? AND ?
    ORDER BY be.created_at ASC
");
$stmt->execute([$ej_id, $periodo_ini, $periodo_fin]);
$bonos = $stmt->fetchAll();

$total_comisiones = array_sum(array_column($bonos, 'monto'));
$ventas_cerradas  = count(array_unique(array_column($bonos, 'cotizacion_id')));
$certs_cerrados   = count($bonos);
$salario          = (float)$ej['salario_mensual'];
$total_pagar      = $salario + $total_comisiones;

$montos_unicos = array_unique(array_map(fn($b) => (float)$b['monto'], $bonos));
$comision_unit = count($montos_unicos) === 1 ? reset($montos_unicos) : null;

$meses_es = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio',
             'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
$nombre_mes = $meses_es[$month];

// Helpers
$h  = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$fmt = fn($n) => 'S/ ' . number_format((float)$n, 2);

// Imágenes en base64
$_pd       = __DIR__ . '/../../storage/assets/pdf/';
$logo_b64  = 'data:image/png;base64,' . base64_encode(file_get_contents($_pd . 'logo-coti.png'));
$firma_b64 = 'data:image/png;base64,' . base64_encode(file_get_contents($_pd . 'sello_arian.png'));

$nombre_completo = strtoupper($ej['apellido'] . ' ' . $ej['nombre']);
$fecha_str       = date('d') . ' de ' . $nombre_mes . ' del ' . $year;

// Filas de comisiones
$fila_comision_unit = '';
if ($comision_unit !== null) {
    $fila_comision_unit = '
    <tr>
      <td class="td-label">Comisión por certificado</td>
      <td class="td-detail">—</td>
      <td class="td-amount">' . $h($fmt($comision_unit)) . '</td>
    </tr>';
}

$html = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
  * { box-sizing:border-box; margin:0; padding:0; }
  body {
    font-family: Arial, sans-serif;
    font-size: 10pt;
    color: #1a1a1a;
    margin: 0;
    padding: 0;
  }

  /* ── Banda superior verde ── */
  .top-band {
    background: #155e3a;
    height: 8pt;
    width: 100%;
    display: block;
  }

  /* ── Área de contenido con márgenes ── */
  .content {
    margin: 12pt 24pt 24pt 24pt;
  }

  /* ── Header ── */
  .header-table { width:100%; border-collapse:collapse; margin-bottom:0; padding-bottom:10pt; border-bottom:1.5pt solid #e5e7eb; }
  .header-logo-cell { width:100pt; vertical-align:middle; }
  .header-logo-cell img { width:88pt; height:auto; }
  .header-title-cell { text-align:center; vertical-align:middle; padding:0 8pt; }
  .header-title-cell h1 {
    font-family: Arial, sans-serif;
    font-size:15pt; font-weight:700; text-transform:uppercase;
    color:#155e3a; line-height:1.3; letter-spacing:1pt;
  }
  .header-doc-cell { width:100pt; vertical-align:top; text-align:right; }
  .doc-label { font-size:7.5pt; color:#9ca3af; text-transform:uppercase; letter-spacing:.8pt; font-weight:700; }
  .doc-period { font-size:11pt; font-weight:700; color:#155e3a; margin-top:2pt; }
  .doc-year { font-size:9pt; color:#6b7280; }

  /* ── Ficha de colaborador ── */
  .ficha-band {
    background: #f0faf4;
    border: 1pt solid #bbdeca;
    border-radius: 6pt;
    padding: 10pt 14pt;
    margin: 12pt 0;
  }
  .ficha-table { width:100%; border-collapse:collapse; }
  .ficha-table td { padding:2.5pt 0; font-size:10pt; vertical-align:top; }
  .ficha-label { font-weight:700; color:#155e3a; width:90pt; white-space:nowrap; }
  .ficha-value { color:#1a1a1a; }

  /* ── Intro ── */
  .intro { font-size:10pt; line-height:1.65; margin-bottom:14pt; color:#374151; text-align:justify; }

  /* ── Main table ── */
  .main-table { width:100%; border-collapse:collapse; margin-bottom:18pt; font-size:10pt; }

  .main-table thead th {
    background:#155e3a; color:#ffffff;
    font-weight:700; font-size:10pt;
    padding:8pt 12pt; text-align:left;
  }
  .main-table thead th.th-center { text-align:center; }
  .main-table thead th.th-right  { text-align:right; }

  /* Alternating rows */
  .main-table tbody tr:nth-child(odd)  td { background:#ffffff; }
  .main-table tbody tr:nth-child(even) td { background:#f9fafb; }
  .main-table tbody td {
    padding:7.5pt 12pt; vertical-align:middle;
    border-bottom:0.5pt solid #e5e7eb;
  }

  .td-label   { font-size:10pt; color:#1a1a1a; }
  .td-detail  { text-align:center; color:#6b7280; font-weight:600; }
  .td-amount  { text-align:right; font-weight:700; color:#1a1a1a; }
  .td-muted   { text-align:right; color:#9ca3af; }

  /* Subtotal row */
  .subtotal-row td {
    background:#edf7f1 !important;
    border-top:1pt solid #a7d7bc;
    border-bottom:1pt solid #a7d7bc;
    color:#155e3a;
    font-weight:700;
  }

  /* Total row */
  .total-row td {
    background:#155e3a !important;
    color:#ffffff !important;
    font-weight:700;
    font-size:11.5pt;
    padding:9pt 12pt;
    border:none;
  }
  .total-row .td-label  { color:#ffffff !important; }
  .total-row .td-amount { color:#ffffff !important; font-size:12pt; }

  /* ── Footer text ── */
  .footer-box {
    background:#f8fafc;
    border-left:3pt solid #155e3a;
    padding:9pt 12pt;
    margin-bottom:22pt;
    font-size:9.5pt;
    line-height:1.7;
    color:#374151;
    text-align:justify;
  }

  /* ── Firma ── */
  .firma-table { width:100%; border-collapse:collapse; margin-top:4pt; }
  .firma-left { vertical-align:bottom; width:50%; }
  .firma-right { vertical-align:bottom; width:50%; text-align:right; }
  .firma-img { height:130pt; width:auto; display:block; }
  .firma-line { border-top:1pt solid #9ca3af; padding-top:5pt; margin-top:4pt; }
  .firma-nombre { font-size:10pt; font-weight:700; text-transform:uppercase; line-height:1.5; }
  .firma-cargo  { font-size:9.5pt; color:#374151; }
  .firma-empresa { font-size:10pt; font-weight:700; color:#155e3a; }

  /* ── Banda inferior ── */
  .bottom-band {
    background:#155e3a;
    height:5pt;
    width:100%;
    display:block;
    position:fixed;
    bottom:0;
    left:0;
  }
</style>
</head>
<body>

<div class="top-band"></div>

<div class="content">

<!-- Header -->
<table class="header-table">
  <tr>
    <td class="header-logo-cell">
      <img src="{$logo_b64}" alt="GROUP GLOBAL ISO">
    </td>
    <td class="header-title-cell">
      <h1>Informe de Pago<br>por Prestación de Servicios</h1>
    </td>
    <td class="header-doc-cell">
      <div class="doc-label">Período</div>
      <div class="doc-period">{$h($nombre_mes)}</div>
      <div class="doc-year">{$h($year)}</div>
    </td>
  </tr>
</table>

<!-- Ficha colaborador -->
<div class="ficha-band">
  <table class="ficha-table">
    <tr>
      <td class="ficha-label">FECHA:</td>
      <td class="ficha-value">{$h($fecha_str)}</td>
      <td width="20pt"></td>
      <td class="ficha-label">COLABORADOR:</td>
      <td class="ficha-value" style="font-weight:700;">{$h($ej['nombre'] . ' ' . $ej['apellido'])}</td>
    </tr>
    <tr>
      <td class="ficha-label">CARGO:</td>
      <td class="ficha-value" colspan="4">Ejecutivo de Ventas</td>
    </tr>
  </table>
</div>

<!-- Intro -->
<p class="intro">
  Por medio del presente se detallan los conceptos correspondientes al pago por los servicios
  prestados correspondientes al mes de <strong>{$h($nombre_mes)} {$h($year)}</strong>:
</p>

<!-- Tabla principal -->
<table class="main-table">
  <thead>
    <tr>
      <th scope="col" style="width:46%;">Concepto</th>
      <th scope="col" class="th-center" style="width:20%;">Detalle</th>
      <th scope="col" class="th-right"  style="width:34%;">Monto (S/.)</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td class="td-label">Monto acordado Mensual</td>
      <td class="td-detail">—</td>
      <td class="td-amount">{$h($fmt($salario))}</td>
    </tr>
    <tr>
      <td class="td-label">Ventas cerradas</td>
      <td class="td-detail">{$h($ventas_cerradas)}</td>
      <td class="td-muted">—</td>
    </tr>
    <tr>
      <td class="td-label">Certificados cerrados</td>
      <td class="td-detail">{$h($certs_cerrados)}</td>
      <td class="td-muted">—</td>
    </tr>
    {$fila_comision_unit}
    <tr class="subtotal-row">
      <td class="td-label">Total comisiones</td>
      <td class="td-detail">—</td>
      <td class="td-amount">{$h($fmt($total_comisiones))}</td>
    </tr>
  </tbody>
  <tfoot>
    <tr class="total-row">
      <td class="td-label" colspan="2"><strong>TOTAL A PAGAR:</strong></td>
      <td class="td-amount"><strong>{$h($fmt($total_pagar))}</strong></td>
    </tr>
  </tfoot>
</table>

<!-- Texto legal -->
<div class="footer-box">
  El presente documento tiene como finalidad dejar constancia del cálculo correspondiente
  al pago por servicios, considerando tanto la remuneración proporcional por días trabajados
  como las comisiones generadas por desempeño.<br>
  Sin otro particular, se emite el presente para los fines correspondientes.
</div>

<!-- Firma -->
<p style="font-size:10pt;margin-bottom:8pt;color:#374151;">Atentamente,</p>
<table class="firma-table">
  <tr>
    <td class="firma-left">
      <img src="{$firma_b64}" alt="Firma" class="firma-img">
      <div class="firma-line">
        <div class="firma-nombre">Arian J. Bezada Maguiña</div>
        <div class="firma-cargo">Gerente de Operaciones</div>
        <div class="firma-empresa">GROUP GLOBAL ISO S.A.C.</div>
      </div>
    </td>
    <td class="firma-right"></td>
  </tr>
</table>

</div><!-- /content -->

<div class="bottom-band"></div>

</body>
</html>
HTML;

// ── Generar PDF ──────────────────────────────────────────────────────────────
$options = new \Dompdf\Options();
$options->setIsRemoteEnabled(false);
$options->setIsPhpEnabled(false);
$options->setDefaultFont('Arial');

$dompdf = new \Dompdf\Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper([0, 0, 595.5, 842.25]); // A4
$dompdf->render();

$nombre_ej  = strtolower(str_replace(' ', '_', $ej['apellido'] . '_' . $ej['nombre']));
$filename   = "informe_pago_{$nombre_ej}_{$year}_{$month}.pdf";

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache');
echo $dompdf->output();
