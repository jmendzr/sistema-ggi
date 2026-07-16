<?php
// modules/alertas/ejecutar.php
// Ejecuta las alertas de vencimiento desde el panel web (autenticado)
require_once __DIR__ . '/../../core/helpers.php';
session_init();
require_login();
require_role('gerente_general');

header('Content-Type: text/plain; charset=utf-8');

// Incluir la lógica del cron directamente
// Definir constantes que el cron espera
if (!defined('RUNNING_FROM_CRON')) define('RUNNING_FROM_CRON', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../core/Database.php';

date_default_timezone_set(APP_TIMEZONE);

$pdo    = Database::get();
$log    = [];
$hoy    = date('Y-m-d');
$enviados = 0;
$errores  = 0;

// Cargar configuración del email
$cfg_file = __DIR__ . '/../../config/email_alerta.json';
$defaults = [
    'reply_to'      => 'contacto@groupglobaliso.com',
    'saludo'        => 'Estimado/a {contacto_nombre},',
    'texto_90'      => 'Le informamos con anticipación que su certificado {norma} vencerá en aproximadamente 90 días.',
    'texto_60'      => 'Su certificado {norma} vencerá en menos de 60 días.',
    'texto_30'      => 'URGENTE: Su certificado {norma} vence en menos de 30 días.',
    'cierre'        => 'Para coordinar su renovación comuníquese con nosotros.',
    'firma_nombre'  => 'Group Global ISO S.A.C.',
    'firma_web'     => 'www.groupglobaliso.com',
    'firma_telefono'=> '',
    'mostrar_portal'=> '1',
];
$cfg = file_exists($cfg_file)
    ? array_merge($defaults, json_decode(file_get_contents($cfg_file), true) ?: [])
    : $defaults;

// Hitos a verificar
$hitos = [
    ['dias' => 90, 'tipo' => '90_dias', 'color' => '#1A3A8F', 'bg' => 'var(--s-info-bg)', 'label' => 'AVISO',    'texto_key' => 'texto_90'],
    ['dias' => 60, 'tipo' => '60_dias', 'color' => '#d97706', 'bg' => 'var(--s-warn-bg)', 'label' => 'ATENCIÓN', 'texto_key' => 'texto_60'],
    ['dias' => 30, 'tipo' => '30_dias', 'color' => '#dc2626', 'bg' => 'var(--s-err-bg)', 'label' => 'URGENTE',  'texto_key' => 'texto_30'],
];

foreach ($hitos as $hito) {
    $d = $hito['dias'];
    // Ventana ±1 día
    $fecha_min = date('Y-m-d', strtotime("+".($d-1)." days"));
    $fecha_max = date('Y-m-d', strtotime("+".($d+1)." days"));

    $stmt = $pdo->prepare("
        SELECT c.id, c.numero_certificado, c.fecha_vencimiento,
               n.codigo AS norma, n.nombre AS norma_nombre,
               cc.nombre AS casa,
               cl.razon_social, cl.contacto_nombre, cl.contacto_apellido,
               cl.contacto_email
        FROM certificados c
        JOIN normas_iso n             ON n.id  = c.norma_iso_id
        JOIN casas_certificadoras cc  ON cc.id = c.casa_certificadora_id
        JOIN clientes cl              ON cl.id = c.cliente_id
        WHERE c.estado IN ('vigente','por_vencer')
          AND c.fecha_vencimiento BETWEEN ? AND ?
          AND cl.contacto_email IS NOT NULL
          AND cl.contacto_email != ''
    ");
    $stmt->execute([$fecha_min, $fecha_max]);
    $certificados = $stmt->fetchAll();

    foreach ($certificados as $cert) {
        // Verificar si ya se envió esta alerta
        $existe = $pdo->prepare("SELECT id FROM alertas_email WHERE certificado_id=? AND tipo_alerta=?");
        $existe->execute([$cert['id'], $hito['tipo']]);
        if ($existe->fetch()) {
            $log[] = "SKIP [{$hito['tipo']}] {$cert['razon_social']} — ya enviada";
            continue;
        }

        // Construir el email
        $dias_reales = (int)((strtotime($cert['fecha_vencimiento']) - strtotime($hoy)) / 86400);
        $nombre_contacto = trim($cert['contacto_nombre'] . ' ' . $cert['contacto_apellido']);
        $nombre_corto    = $cert['contacto_nombre'] ?: $nombre_contacto;
        $fecha_vence_fmt = date('d/m/Y', strtotime($cert['fecha_vencimiento']));

        $reemplazar = function($texto) use ($nombre_corto, $nombre_contacto, $cert, $fecha_vence_fmt) {
            return str_replace(
                ['{contacto_nombre}', '{nombre_completo}', '{empresa}', '{norma}', '{fecha_vencimiento}'],
                [$nombre_corto, $nombre_contacto, $cert['razon_social'], $cert['norma'], $fecha_vence_fmt],
                $texto
            );
        };

        $saludo  = $reemplazar($cfg['saludo']);
        $alerta  = $reemplazar($cfg[$hito['texto_key']]);
        $cierre  = $reemplazar($cfg['cierre']);
        $firma_n = $cfg['firma_nombre'] ?: 'Group Global ISO S.A.C.';
        $firma_w = $cfg['firma_web']    ?: 'www.groupglobaliso.com';
        $firma_t = $cfg['firma_telefono'] ? '<br>' . htmlspecialchars($cfg['firma_telefono']) : '';

        $portal_btn = '';
        // Portal solo si las columnas existen (SQL 06 ejecutado)
        try {
            $chk = $pdo->prepare("SELECT portal_activo, portal_token FROM clientes WHERE id = (SELECT cliente_id FROM certificados WHERE id = ?)");
            $chk->execute([$cert['id']]);
            $portal_row = $chk->fetch();
            if ($cfg['mostrar_portal'] && $portal_row && $portal_row['portal_activo'] && $portal_row['portal_token']) {
                $url = APP_URL . '/portal/?token=' . urlencode($portal_row['portal_token']);
                $portal_btn = "<div style='margin-top:20px;text-align:center'><a href='{$url}' style='display:inline-block;background:#1A3A8F;color:#fff;padding:11px 26px;border-radius:8px;text-decoration:none;font-family:Arial,sans-serif;font-size:14px;font-weight:bold;'>Ver mis certificados en línea</a></div>";
            }
        } catch (Exception $e) { /* columnas de portal aún no existen */ }

        $asunto = "[{$hito['label']}] Su certificado {$cert['norma']} vence en {$dias_reales} días — {$cert['razon_social']}";

        $body = "<!DOCTYPE html><html><head><meta charset='UTF-8'></head>
<body style='margin:0;padding:0;background:var(--bg-surface2);font-family:Arial,Helvetica,sans-serif;'>
<table width='100%' cellpadding='0' cellspacing='0' style='background:var(--bg-surface2);padding:24px 12px;'><tr><td align='center'>
<table width='560' cellpadding='0' cellspacing='0' style='max-width:560px;width:100%;'>
<tr><td style='background:#1A3A8F;border-radius:14px 14px 0 0;padding:22px 26px;'>
  <table width='100%' cellpadding='0' cellspacing='0'><tr>
    <td><div style='font-size:19px;font-weight:bold;color:#fff;'>" . htmlspecialchars($firma_n) . "</div>
        <div style='font-size:10px;color:rgba(255,255,255,0.5);margin-top:3px;letter-spacing:1px;text-transform:uppercase;'>Sistema de certificaciones ISO</div></td>
    <td align='right'><div style='background:{$hito['bg']};color:{$hito['color']};padding:5px 12px;border-radius:20px;font-size:11px;font-weight:bold;white-space:nowrap;'>{$hito['label']} — {$d} días</div></td>
  </tr></table>
</td></tr>
<tr><td style='background:var(--bg-surface);padding:26px;'>
  <p style='font-size:15px;color:#1a1f36;margin:0 0 16px;'>" . htmlspecialchars($saludo) . "</p>
  <div style='background:{$hito['bg']};border-left:4px solid {$hito['color']};padding:14px 18px;margin-bottom:20px;border-radius:0 8px 8px 0;'>
    <div style='font-size:14px;color:{$hito['color']};font-weight:bold;line-height:1.5;'>" . htmlspecialchars($alerta) . "</div>
  </div>
  <div style='background:var(--bg-surface2);border:1px solid #e2e8f0;border-radius:10px;padding:18px;margin-bottom:20px;'>
    <div style='font-size:10px;font-weight:bold;color:#6b7a99;text-transform:uppercase;letter-spacing:.5px;margin-bottom:12px;'>Detalles del certificado</div>
    <table width='100%' cellpadding='0' cellspacing='0'>
      <tr><td style='padding:5px 0;font-size:13px;color:#6b7a99;width:46%'>Empresa</td><td style='padding:5px 0;font-size:13px;color:#1a1f36;font-weight:bold'>" . htmlspecialchars($cert['razon_social']) . "</td></tr>
      <tr><td style='padding:5px 0;font-size:13px;color:#6b7a99;border-top:1px solid var(--bg-surface2)'>Norma ISO</td><td style='padding:5px 0;font-size:13px;color:#1A3A8F;font-weight:bold;border-top:1px solid var(--bg-surface2)'>" . htmlspecialchars($cert['norma']) . "</td></tr>
      <tr><td style='padding:5px 0;font-size:13px;color:#6b7a99;border-top:1px solid var(--bg-surface2)'>Casa certificadora</td><td style='padding:5px 0;font-size:13px;color:#1a1f36;border-top:1px solid var(--bg-surface2)'>" . htmlspecialchars($cert['casa']) . "</td></tr>
      <tr><td style='padding:5px 0;font-size:13px;color:#6b7a99;border-top:1px solid var(--bg-surface2)'>N° de certificado</td><td style='padding:5px 0;font-size:13px;font-family:monospace;color:#1a1f36;border-top:1px solid var(--bg-surface2)'>" . htmlspecialchars($cert['numero_certificado']) . "</td></tr>
      <tr><td style='padding:5px 0;font-size:13px;color:#6b7a99;border-top:1px solid var(--bg-surface2)'>Fecha de vencimiento</td><td style='padding:5px 0;font-size:15px;color:{$hito['color']};font-weight:bold;border-top:1px solid var(--bg-surface2)'>{$fecha_vence_fmt}</td></tr>
    </table>
  </div>
  <p style='font-size:14px;color:#1a1f36;line-height:1.6;margin-bottom:16px;'>" . htmlspecialchars($cierre) . "</p>
  {$portal_btn}
</td></tr>
<tr><td style='background:var(--bg-surface2);border-radius:0 0 14px 14px;padding:18px 26px;border-top:1px solid #e2e8f0;'>
  <div style='font-size:12px;color:#9ca3af;line-height:1.7;'>
    <strong style='color:#6b7a99;'>" . htmlspecialchars($firma_n) . "</strong>{$firma_t}<br>
    <a href='https://" . htmlspecialchars($firma_w) . "' style='color:#1A3A8F;text-decoration:none;'>" . htmlspecialchars($firma_w) . "</a><br><br>
    Este es un mensaje automático. Por favor no responda directamente a este correo.
  </div>
</td></tr>
</table></td></tr></table>
</body></html>";

        // Enviar email
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: " . $firma_n . " <noreply@groupglobaliso.com>\r\n";
        if ($cfg['reply_to']) {
            $headers .= "Reply-To: " . $cfg['reply_to'] . "\r\n";
        }
        $headers .= "X-Mailer: GGI-Sistema\r\n";

        $ok = mail($cert['contacto_email'], $asunto, $body, $headers);

        if ($ok) {
            // Registrar alerta enviada
            $pdo->prepare("INSERT INTO alertas_email (certificado_id, tipo_alerta, email_destino, enviado_at) VALUES (?,?,?,NOW())")
                ->execute([$cert['id'], $hito['tipo'], $cert['contacto_email']]);
            $log[] = "OK [{$hito['tipo']}] {$cert['razon_social']} → {$cert['contacto_email']}";
            $enviados++;
        } else {
            $log[] = "ERROR [{$hito['tipo']}] {$cert['razon_social']} — fallo al enviar";
            $errores++;
        }
    }
}

$resumen = date('Y-m-d H:i:s') . " | Enviados: {$enviados} | Errores: {$errores}\n" . implode("\n", $log);

// Guardar log
$log_file = __DIR__ . '/../../logs/alertas_cron.log';
if (!is_dir(dirname($log_file))) mkdir(dirname($log_file), 0755, true);
file_put_contents($log_file, $resumen . "\n\n", FILE_APPEND);

echo $resumen;

// ── Seguimientos pendientes en cotizaciones enviadas ──
echo "<h3>Seguimientos pendientes</h3>";
$stale = $pdo->query("
    SELECT cot.id, cot.numero, cot.ejecutivo_id,
           COALESCE(cl.razon_social, cot.empresa_nombre) AS empresa,
           DATEDIFF(CURDATE(), cot.fecha_emision) AS dias
    FROM cotizaciones cot
    LEFT JOIN clientes cl ON cl.id=cot.cliente_id
    WHERE cot.estado='enviada'
      AND cot.ejecutivo_id IS NOT NULL
      AND cot.fecha_emision <= DATE_SUB(CURDATE(), INTERVAL 3 DAY)
      AND NOT EXISTS (
          SELECT 1 FROM cotizacion_seguimientos cs
          WHERE cs.cotizacion_id=cot.id
            AND cs.created_at >= DATE_SUB(NOW(), INTERVAL 3 DAY)
      )
      AND NOT EXISTS (
          SELECT 1 FROM notificaciones n
          WHERE n.usuario_id=cot.ejecutivo_id
            AND n.tipo='seguimiento_pendiente'
            AND n.url_accion LIKE CONCAT('%cotizaciones/ver.php?id=', cot.id)
            AND n.leida=0
            AND n.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
      )
    LIMIT 50
")->fetchAll();

$sent_rem = 0;
foreach ($stale as $s) {
    $pdo->prepare("INSERT INTO notificaciones (usuario_id, titulo, mensaje, tipo, url_accion) VALUES (?,?,?,?,?)")
        ->execute([
            $s['ejecutivo_id'],
            "Seguimiento pendiente: {$s['numero']}",
            "La cotización {$s['numero']} para {$s['empresa']} lleva {$s['dias']} días enviada sin seguimiento. Contacta al cliente.",
            'seguimiento_pendiente',
            APP_URL . "/modules/cotizaciones/ver.php?id={$s['id']}",
        ]);
    $sent_rem++;
}
echo "<p>$sent_rem recordatorios de seguimiento generados.</p>";
