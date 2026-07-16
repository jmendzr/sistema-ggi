<?php
// ============================================================
//  GGI SISTEMA — Cron: Alertas de vencimiento de certificados
//  Ejecutar diariamente: 0 9 * * * php /ruta/cron/alertas_vencimiento.php
//
//  Envía emails automáticos al contacto del cliente cuando
//  un certificado está a 90, 60 o 30 días de vencer.
//  Usa la tabla alertas_email para no reenviar.
// ============================================================

define('RUNNING_FROM_CRON', true);
require_once __DIR__ . '/../config/config.php';

if (PHP_SAPI !== 'cli') {
    $token_web = $_GET['token'] ?? '';
    if (!defined('CRON_TOKEN') || $token_web !== CRON_TOKEN) {
        http_response_code(403);
        exit('Acceso denegado.');
    }
}
require_once __DIR__ . '/../core/Database.php';

date_default_timezone_set(APP_TIMEZONE);

$pdo = Database::get();
$log = [];

// Cargar configuración personalizada del email
$cfg_file = __DIR__ . '/../config/email_alerta.json';
$cfg_defaults = [
    'remitente_nombre' => 'Group Global ISO',
    'remitente_email'  => 'noreply@groupglobaliso.com',
    'reply_to'         => 'contacto@groupglobaliso.com',
    'saludo'           => 'Estimado/a {contacto_nombre},',
    'texto_90'         => 'Le informamos con anticipación que su certificado {norma} vencerá en aproximadamente 90 días. Le recomendamos coordinar con nosotros el inicio del proceso de renovación.',
    'texto_60'         => 'Su certificado {norma} vencerá en menos de 60 días. Es momento de iniciar las gestiones de renovación para evitar interrupciones en su certificación.',
    'texto_30'         => 'URGENTE: Su certificado {norma} vencerá en menos de 30 días. Es necesario iniciar el proceso de renovación a la brevedad posible.',
    'cierre'           => 'Para coordinar su renovación o resolver cualquier consulta, comuníquese con su ejecutivo de cuenta de Group Global ISO.',
    'firma_nombre'     => 'Group Global ISO S.A.C.',
    'firma_web'        => 'www.groupglobaliso.com',
    'firma_telefono'   => '',
    'mostrar_portal'   => '1',
];
$cfg = file_exists($cfg_file)
    ? array_merge($cfg_defaults, json_decode(file_get_contents($cfg_file), true) ?: [])
    : $cfg_defaults;
$enviados = 0;
$errores  = 0;

// ── Configuración de alertas ──────────────────────────────
$hitos = [
    90 => '90_dias',
    60 => '60_dias',
    30 => '30_dias',
];

// ── Obtener certificados próximos a vencer ────────────────
$certs = $pdo->query("
    SELECT
        c.id,
        c.numero_certificado,
        c.fecha_vencimiento,
        c.estado,
        DATEDIFF(c.fecha_vencimiento, CURDATE()) AS dias_restantes,
        n.codigo  AS norma,
        n.nombre  AS norma_nombre,
        cc.nombre AS casa,
        cl.id     AS cliente_id,
        cl.razon_social,
        cl.ruc,
        cl.contacto_nombre,
        cl.contacto_apellido,
        cl.contacto_email,
        cl.portal_token,
        cl.portal_activo
    FROM certificados c
    JOIN normas_iso n           ON n.id  = c.norma_iso_id
    JOIN casas_certificadoras cc ON cc.id = c.casa_certificadora_id
    JOIN clientes cl            ON cl.id  = c.cliente_id
    WHERE c.deleted_at IS NULL
      AND cl.deleted_at IS NULL
      AND c.estado IN ('vigente','por_vencer')
      AND c.fecha_vencimiento > CURDATE()
      AND c.fecha_vencimiento <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)
      AND cl.contacto_email IS NOT NULL
      AND cl.contacto_email != ''
      AND cl.estado != 'inactivo'
")->fetchAll();

$log[] = "[INFO] Certificados encontrados en ventana 90 días: " . count($certs);

foreach ($certs as $cert) {
    $dias = (int)$cert['dias_restantes'];

    // Determinar si este certificado cae en algún hito
    $tipo_alerta = null;
    foreach ($hitos as $umbral => $tipo) {
        // Ventana de ±1 día para no perder el hito si el cron corre con retraso
        if ($dias >= ($umbral - 1) && $dias <= ($umbral + 1)) {
            $tipo_alerta = $tipo;
            break;
        }
    }

    if (!$tipo_alerta) continue;

    // Verificar si ya se envió este tipo de alerta para este certificado
    $ya_enviado = $pdo->prepare("
        SELECT id FROM alertas_email
        WHERE certificado_id = ? AND tipo_alerta = ?
        LIMIT 1
    ");
    $ya_enviado->execute([$cert['id'], $tipo_alerta]);
    if ($ya_enviado->fetch()) {
        $log[] = "[SKIP] Cert #{$cert['id']} ({$cert['norma']}) — alerta {$tipo_alerta} ya enviada";
        continue;
    }

    // Armar email
    $email_ok = enviar_alerta($cert, $dias, $tipo_alerta, $pdo);

    if ($email_ok) {
        // Registrar para no reenviar
        $pdo->prepare("
            INSERT INTO alertas_email (certificado_id, tipo_alerta, email_destino)
            VALUES (?, ?, ?)
        ")->execute([$cert['id'], $tipo_alerta, $cert['contacto_email']]);

        $log[] = "[OK] Cert #{$cert['id']} ({$cert['norma']}) → {$cert['contacto_email']} ({$dias} días)";
        $enviados++;

        // También guardar en log_actividad
        try {
            $pdo->prepare("
                INSERT INTO log_actividad (usuario_id, modulo, accion, descripcion, ip)
                VALUES (NULL, 'cron', 'alerta_email', ?, 'cron')
            ")->execute(["Alerta {$tipo_alerta} enviada: {$cert['razon_social']} — {$cert['norma']} → {$cert['contacto_email']}"]);
        } catch (Exception $e) {}

    } else {
        $log[] = "[ERROR] Cert #{$cert['id']} — fallo al enviar a {$cert['contacto_email']}";
        $errores++;
    }
}

// ── Función de envío ──────────────────────────────────────
function enviar_alerta(array \$cert, int \$dias, string \$tipo, PDO \$pdo, array \$cfg = []): bool {

    $nombre_contacto = trim($cert['contacto_nombre'] . ' ' . $cert['contacto_apellido']);
    $email_destino   = $cert['contacto_email'];
    $fecha_vence     = date('d/m/Y', strtotime($cert['fecha_vencimiento']));

    // Urgencia
    if ($dias <= 30) {
        $urgencia   = 'URGENTE';
        $color_urg  = '#dc2626';
        $bg_urg     = '#fef2f2';
        $mensaje_urg = 'Su certificado vence en menos de 30 días. Es necesario iniciar el proceso de renovación a la brevedad posible.';
    } elseif ($dias <= 60) {
        $urgencia   = 'ATENCIÓN';
        $color_urg  = '#d97706';
        $bg_urg     = '#fffbeb';
        $mensaje_urg = 'Su certificado vence en menos de 60 días. Le recomendamos coordinar con nosotros el proceso de renovación.';
    } else {
        $urgencia   = 'AVISO';
        $color_urg  = '#1A3A8F';
        $bg_urg     = '#eff6ff';
        $mensaje_urg = 'Le informamos con anticipación que su certificado vencerá en aproximadamente 90 días.';
    }

    // Link al portal si está activo
    $portal_link = '';
    if (!empty($cert['portal_token']) && $cert['portal_activo']) {
        $portal_url  = APP_URL . '/portal/?token=' . $cert['portal_token'];
        $portal_link = "
        <div style=\"margin-top:20px;text-align:center;\">
          <a href=\"{$portal_url}\"
             style=\"display:inline-block;background:#1A3A8F;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;font-family:Arial,sans-serif;font-size:14px;font-weight:bold;\">
            Ver mis certificados en línea
          </a>
        </div>
        <p style=\"font-size:12px;color:#9ca3af;text-align:center;margin-top:10px;\">
          O copia este enlace en tu navegador:<br>
          <a href=\"{$portal_url}\" style=\"color:#1A3A8F;\">{$portal_url}</a>
        </p>";
    }

    // ── HTML del email ──
    $asunto = "[{$urgencia}] Su certificado {$cert['norma']} vence en {$dias} días — " . APP_NAME;

    $html = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"></head>
<body style="margin:0;padding:0;background:#f0f2f8;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f0f2f8;padding:32px 16px;">
  <tr><td align="center">
    <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;">

      <!-- Header -->
      <tr>
        <td style="background:#1A3A8F;border-radius:14px 14px 0 0;padding:28px 32px;">
          <table width="100%" cellpadding="0" cellspacing="0">
            <tr>
              <td>
                <div style="font-size:22px;font-weight:bold;color:#fff;letter-spacing:-0.5px;">Group Global ISO</div>
                <div style="font-size:12px;color:rgba(255,255,255,0.6);margin-top:3px;letter-spacing:1px;text-transform:uppercase;">Sistema de certificaciones ISO</div>
              </td>
              <td align="right">
                <div style="background:{$bg_urg};color:{$color_urg};padding:6px 14px;border-radius:20px;font-size:12px;font-weight:bold;white-space:nowrap;">
                  {$urgencia} — {$dias} días
                </div>
              </td>
            </tr>
          </table>
        </td>
      </tr>

      <!-- Body -->
      <tr>
        <td style="background:#fff;padding:32px;">

          <p style="font-size:15px;color:#1a1f36;margin:0 0 20px;">
            Estimado/a <strong>{$nombre_contacto}</strong>,
          </p>

          <!-- Alerta box -->
          <div style="background:{$bg_urg};border-left:4px solid {$color_urg};border-radius:0 8px 8px 0;padding:16px 20px;margin-bottom:24px;">
            <div style="font-size:14px;color:{$color_urg};font-weight:bold;margin-bottom:6px;">
              {$mensaje_urg}
            </div>
          </div>

          <!-- Datos del certificado -->
          <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:20px;margin-bottom:24px;">
            <div style="font-size:11px;font-weight:bold;color:#6b7a99;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:14px;">
              Detalles del certificado
            </div>
            <table width="100%" cellpadding="0" cellspacing="0">
              <tr>
                <td style="padding:6px 0;font-size:13px;color:#6b7a99;width:45%;">Empresa</td>
                <td style="padding:6px 0;font-size:13px;color:#1a1f36;font-weight:bold;">{$cert['razon_social']}</td>
              </tr>
              <tr>
                <td style="padding:6px 0;font-size:13px;color:#6b7a99;border-top:1px solid #f0f2f8;">Norma ISO</td>
                <td style="padding:6px 0;font-size:13px;color:#1A3A8F;font-weight:bold;border-top:1px solid #f0f2f8;">{$cert['norma']}</td>
              </tr>
              <tr>
                <td style="padding:6px 0;font-size:13px;color:#6b7a99;border-top:1px solid #f0f2f8;">{$cert['norma_nombre']}</td>
                <td style="padding:6px 0;font-size:13px;color:#6b7a99;border-top:1px solid #f0f2f8;">&nbsp;</td>
              </tr>
              <tr>
                <td style="padding:6px 0;font-size:13px;color:#6b7a99;border-top:1px solid #f0f2f8;">Casa certificadora</td>
                <td style="padding:6px 0;font-size:13px;color:#1a1f36;border-top:1px solid #f0f2f8;">{$cert['casa']}</td>
              </tr>
              {$cert['numero_certificado'] ? "
              <tr>
                <td style=\"padding:6px 0;font-size:13px;color:#6b7a99;border-top:1px solid #f0f2f8;\">N° de certificado</td>
                <td style=\"padding:6px 0;font-size:13px;color:#1a1f36;font-family:monospace;border-top:1px solid #f0f2f8;\">{$cert['numero_certificado']}</td>
              </tr>" : ""}
              <tr>
                <td style="padding:6px 0;font-size:13px;color:#6b7a99;border-top:1px solid #f0f2f8;">Fecha de vencimiento</td>
                <td style="padding:6px 0;font-size:15px;color:{$color_urg};font-weight:bold;border-top:1px solid #f0f2f8;">{$fecha_vence}</td>
              </tr>
            </table>
          </div>

          <!-- CTA -->
          <p style="font-size:14px;color:#1a1f36;line-height:1.6;margin-bottom:20px;">
            Para iniciar el proceso de renovación o si tiene alguna consulta, no dude en comunicarse con su ejecutivo de cuenta de <strong>Group Global ISO</strong>.
          </p>

          {$portal_link}

        </td>
      </tr>

      <!-- Footer -->
      <tr>
        <td style="background:#f8fafc;border-radius:0 0 14px 14px;padding:20px 32px;border-top:1px solid #e2e8f0;">
          <table width="100%" cellpadding="0" cellspacing="0">
            <tr>
              <td style="font-size:12px;color:#9ca3af;line-height:1.6;">
                <strong style="color:#6b7a99;">Group Global ISO S.A.C.</strong><br>
                Lima, Perú · <a href="https://www.groupglobaliso.com" style="color:#1A3A8F;text-decoration:none;">www.groupglobaliso.com</a><br>
                <br>
                Este es un mensaje automático del sistema de gestión de certificaciones.
                Por favor no responda a este correo directamente.
              </td>
              <td align="right" style="vertical-align:top;">
                <div style="font-size:11px;color:#d1d5db;font-weight:bold;letter-spacing:1px;">GGI SISTEMA</div>
              </td>
            </tr>
          </table>
        </td>
      </tr>

    </table>
  </td></tr>
</table>
</body>
</html>
HTML;

    // ── Versión texto plano (fallback) ──
    $texto = "Group Global ISO — Alerta de vencimiento\n\n"
           . "Estimado/a {$nombre_contacto},\n\n"
           . "{$mensaje_urg}\n\n"
           . "DETALLES DEL CERTIFICADO:\n"
           . "Empresa:           {$cert['razon_social']}\n"
           . "Norma ISO:         {$cert['norma']}\n"
           . "Casa certificadora: {$cert['casa']}\n"
           . "Vencimiento:       {$fecha_vence}\n"
           . "Días restantes:    {$dias}\n\n"
           . "Contáctenos para iniciar el proceso de renovación.\n\n"
           . "Group Global ISO S.A.C.\n"
           . APP_URL;

    // ── Enviar con mail() usando MIME multipart ──
    $boundary = '----=_Part_' . md5(uniqid());
    $headers  = implode("\r\n", [
        'From: Group Global ISO <noreply@groupglobaliso.com>',
        'Reply-To: contacto@groupglobaliso.com',
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        'X-Mailer: PHP/' . phpversion(),
        'X-GGI-Alerta: vencimiento-' . $tipo,
    ]);

    $body  = "--{$boundary}\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
    $body .= quoted_printable_encode($texto) . "\r\n\r\n";
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
    $body .= quoted_printable_encode($html) . "\r\n\r\n";
    $body .= "--{$boundary}--";

    return @mail($email_destino, $asunto, $body, $headers);
}

// ── Log final ────────────────────────────────────────────
$ts      = date('Y-m-d H:i:s');
$resumen = "[{$ts}] Alertas vencimiento: {$enviados} enviadas, {$errores} errores.\n";
foreach ($log as $l) $resumen .= $l . "\n";

$log_dir  = __DIR__ . '/../storage/logs/';
if (!is_dir($log_dir)) @mkdir($log_dir, 0755, true);
file_put_contents($log_dir . 'alertas_' . date('Y-m') . '.log', $resumen, FILE_APPEND | LOCK_EX);

try {
    $pdo->prepare("
        INSERT INTO log_actividad (usuario_id, modulo, accion, descripcion, ip)
        VALUES (NULL, 'cron', 'alertas_email', ?, 'cron')
    ")->execute(["Enviadas: {$enviados}, Errores: {$errores}"]);
} catch (Exception $e) {}

if (PHP_SAPI === 'cli') {
    echo $resumen;
} else {
    header('Content-Type: text/plain; charset=utf-8');
    echo $resumen;
}
