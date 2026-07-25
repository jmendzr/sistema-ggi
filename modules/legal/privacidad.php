<?php
/**
 * privacidad.php — Política de privacidad PÚBLICA (sin login).
 *
 * Requerida por Meta para publicar la app. Es un texto base; conviene que lo
 * revise el área legal de la empresa antes de darlo por definitivo.
 */
header('Content-Type: text/html; charset=utf-8');
$empresa = 'Group Global ISO S.A.C.';
$sitio   = 'https://sistema.groupglobaliso.com';
$correo  = 'info@groupglobaliso.com';
$hoy     = date('d/m/Y');
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Política de Privacidad — <?= htmlspecialchars($empresa) ?></title>
  <style>
    body { font-family: -apple-system, Segoe UI, Roboto, sans-serif; color: #1f2937; line-height: 1.6;
           max-width: 820px; margin: 0 auto; padding: 32px 20px 64px; background: #fff; }
    h1 { font-size: 1.6rem; color: #1A3A8F; }
    h2 { font-size: 1.15rem; color: #1A3A8F; margin-top: 28px; }
    p, li { font-size: .98rem; }
    .fecha { color: #6b7280; font-size: .9rem; }
    a { color: #1A3A8F; }
    code { background: #f3f4f6; padding: 1px 5px; border-radius: 4px; }
  </style>
</head>
<body>
  <h1>Política de Privacidad</h1>
  <p class="fecha">Última actualización: <?= $hoy ?> · <?= htmlspecialchars($empresa) ?></p>

  <p><?= htmlspecialchars($empresa) ?> ("la Empresa", "nosotros") opera el sistema de gestión y atención
  disponible en <a href="<?= $sitio ?>"><?= $sitio ?></a>. Esta Política de Privacidad describe qué datos
  tratamos, con qué fin y cuáles son tus derechos.</p>

  <h2>1. Datos que tratamos</h2>
  <ul>
    <li><strong>Datos de contacto y de la empresa cliente:</strong> nombre, razón social, RUC, teléfono,
        correo electrónico y datos necesarios para la prestación de nuestros servicios de certificación.</li>
    <li><strong>Comunicaciones por WhatsApp:</strong> los mensajes, archivos y llamadas intercambiados a
        través de la Plataforma de WhatsApp Business (Meta) para atender consultas y gestionar servicios.</li>
    <li><strong>Datos de uso del sistema:</strong> registros técnicos necesarios para el funcionamiento,
        la seguridad y la trazabilidad de las operaciones.</li>
  </ul>

  <h2>2. Finalidad</h2>
  <p>Utilizamos estos datos para: atender consultas y solicitudes, gestionar procesos de certificación,
  coordinar envíos, enviar notificaciones relacionadas con el servicio y cumplir obligaciones legales.</p>

  <h2>3. WhatsApp Business (Meta)</h2>
  <p>La atención por WhatsApp se realiza mediante la Plataforma de WhatsApp Business de Meta. El tratamiento
  de esos mensajes se rige también por las políticas de Meta/WhatsApp. No usamos los datos de las
  conversaciones para fines distintos de la atención y prestación de nuestros servicios.</p>

  <h2>4. Conservación</h2>
  <p>Conservamos los datos durante el tiempo necesario para las finalidades descritas y para cumplir
  obligaciones legales aplicables. Luego se eliminan o anonimizan.</p>

  <h2>5. Compartir datos</h2>
  <p>No vendemos datos personales. Podemos compartirlos con proveedores que nos ayudan a operar el servicio
  (por ejemplo, Meta para la mensajería, o couriers para los envíos), siempre limitado a lo necesario.</p>

  <h2 id="eliminacion">6. Eliminación de datos</h2>
  <p>Podés solicitar la eliminación de tus datos personales escribiéndonos a
  <a href="mailto:<?= $correo ?>"><?= $correo ?></a> con el asunto <code>Eliminación de datos</code> e
  indicando el número de teléfono o los datos con los que te atendimos. Procesaremos la solicitud dentro de
  los plazos que exija la normativa aplicable.</p>

  <h2>7. Tus derechos</h2>
  <p>Podés solicitar acceso, rectificación, actualización o eliminación de tus datos, así como oponerte a
  su tratamiento, escribiéndonos a <a href="mailto:<?= $correo ?>"><?= $correo ?></a>.</p>

  <h2>8. Contacto</h2>
  <p><?= htmlspecialchars($empresa) ?><br>
  Correo: <a href="mailto:<?= $correo ?>"><?= $correo ?></a><br>
  Sitio: <a href="<?= $sitio ?>"><?= $sitio ?></a></p>
</body>
</html>
