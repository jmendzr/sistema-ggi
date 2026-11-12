<?php
/**
 * verificar/index.php
 * Verificación PÚBLICA de certificados (sin login). Búsqueda por CÓDIGO EXACTO:
 * devuelve únicamente el certificado solicitado. Solo certificados emitidos (no en_proceso).
 * Diseño moderno (recuadro + botón Validar + tarjeta de resultado), colores de marca GGI.
 */
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/Database.php';

$pdo    = Database::get();
$codigo = trim((string)($_GET['codigo'] ?? ''));   // precargado desde el QR
$q      = $codigo !== '' ? $codigo : trim((string)($_GET['q'] ?? ''));
$busco  = ($q !== '');
$term   = mb_substr($q, 0, 120);

$resultados = [];
$error_msg  = '';
if ($busco) {
    if (mb_strlen($term) < 3) {
        $error_msg = 'Ingrese el código completo del certificado.';
    } else {
        // Verificación por CÓDIGO EXACTO: devuelve únicamente el certificado solicitado
        // (no coincidencias parciales ni listados por empresa). El cotejo es
        // insensible a mayúsculas por la colación utf8mb4_unicode_ci.
        $st = $pdo->prepare("
            SELECT ce.id, ce.numero_certificado, ce.fecha_emision, ce.fecha_vencimiento,
                   ce.fecha_auditoria_1, ce.fecha_auditoria_2,
                   ce.estado, ce.alcance, ce.certificado_archivo, ce.certificado_archivo_en,
                   ce.cotizacion_id, ce.created_at,
                   cl.razon_social, cl.ruc, cl.direccion,
                   no.codigo AS norma, no.nombre AS norma_nombre, cc.nombre AS casa,
                   -- pago_total = tiene cotización con al menos un pago y todos validados
                   (ce.cotizacion_id > 0
                     AND EXISTS (SELECT 1 FROM pagos_cliente pc
                                 WHERE pc.cotizacion_id = ce.cotizacion_id AND pc.deleted_at IS NULL)
                     AND NOT EXISTS (SELECT 1 FROM pagos_cliente pc
                                     WHERE pc.cotizacion_id = ce.cotizacion_id AND pc.deleted_at IS NULL
                                       AND pc.estado <> 'validado')
                   ) AS pago_total
            FROM certificados ce
            JOIN clientes cl        ON cl.id = ce.cliente_id
            LEFT JOIN normas_iso no  ON no.id = ce.norma_iso_id
            LEFT JOIN casas_certificadoras cc ON cc.id = ce.casa_certificadora_id
            WHERE ce.deleted_at IS NULL
              AND ce.estado <> 'en_proceso'
              AND ce.numero_certificado IS NOT NULL AND ce.numero_certificado <> ''
              AND ce.numero_certificado = ?
            ORDER BY ce.fecha_emision DESC
            LIMIT 1
        ");
        $st->execute([$term]);
        $resultados = $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

// ── Habilitación por pago total (ver GGI_GATE_PAGO_TOTAL_DESDE en helpers.php) ──
// Se MUESTRA la información del certificado (autenticidad, alcance, vigencia) igual,
// pero mientras no haya pago TOTAL no se habilita la DESCARGA del PDF ($pago_pendiente).
// La misma condición bloquea el PDF en certificado.php (defensa real del archivo).
$pago_pendiente = false;
if ($resultados) {
    $r0 = $resultados[0];
    $es_nuevo = (($r0['created_at'] ?? '') >= GGI_GATE_PAGO_TOTAL_DESDE);
    // Excepción: certificados emitidos SIN cotización (standalone/manual, p. ej. BPL)
    // no pasan por el flujo de venta → no se les aplica el candado de pago total.
    if ($es_nuevo && !empty($r0['cotizacion_id']) && empty($r0['pago_total'])) {
        $pago_pendiente = true;   // se ve el certificado, pero sin botón de descarga
    }
}

// estado → [label, color, badgeBg, badgeBorder, esValido]
$EST = [
    'vigente'    => ['Certificado vigente',   '#15803d', '#eafaf0', '#bbf7d0', true],
    'por_vencer' => ['Próximo a vencer',      '#a1610a', '#fff8ec', '#fde68a', true],
    'vencido'    => ['Certificado vencido',    '#b91c1c', '#fdecec', '#fecaca', false],
    'suspendido' => ['Certificado suspendido', '#b3401e', '#fbeae2', '#f5c6b0', false],
];
$fmt = fn($d) => ($d && $d !== '0000-00-00') ? date('d/m/Y', strtotime($d)) : '—';
$h   = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$appUrl = defined('APP_URL') ? rtrim(APP_URL, '/') : '';
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Verificación de certificados — Group Global ISO</title>
<link rel="icon" href="<?= $h($appUrl) ?>/assets/icons/icon-128.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Sora:wght@600;700;800&display=swap" rel="stylesheet">
<style>
  :root{
    --navy:#0b1b31; --navy-2:#102844; --blue:#16307a; --blue-soft:#e9eefb;
    --green:#15803d; --green-2:#25c274;
    --ink:#132238; --ink2:#5a6478; --ink3:#8a93a6; --mut:#9aa5b4;
    --line:#e7eaf0; --line2:#eef1f6; --bg:#f5f7fa; --card:#fff;
    --shadow:0 10px 34px rgba(15,32,64,.08); --shadow-sm:0 2px 14px rgba(15,32,64,.06);
    --r:16px;
  }
  *{box-sizing:border-box;margin:0;padding:0}
  html,body{margin:0}
  body{font-family:'Inter',-apple-system,Segoe UI,Roboto,sans-serif;color:var(--ink);
       background:var(--bg);-webkit-font-smoothing:antialiased;line-height:1.55}
  h1,h2,h3,.ff{font-family:'Sora','Inter',sans-serif;letter-spacing:-.01em}
  .wrap{max-width:960px;margin:0 auto;padding:0 20px}
  svg{flex-shrink:0}

  /* Fondo decorativo suave */
  .top{position:relative;overflow:hidden;padding:clamp(48px,8vw,80px) 0 20px}
  .top::before{content:"";position:absolute;top:-30%;right:-10%;width:60%;height:150%;
    background:radial-gradient(circle,rgba(37,194,116,.10),transparent 62%);pointer-events:none}
  .pill{display:inline-flex;align-items:center;gap:7px;background:var(--blue-soft);color:var(--blue);
    font-size:12.5px;font-weight:700;letter-spacing:.02em;padding:7px 14px;border-radius:30px;margin-bottom:20px}
  .pill svg{color:var(--blue)}
  h1{font-size:clamp(30px,5vw,50px);font-weight:800;color:var(--navy);line-height:1.08;margin-bottom:16px}
  .lead{font-size:clamp(15px,2vw,18px);color:var(--ink2);max-width:640px;line-height:1.6}

  /* Recuadro de búsqueda */
  .search{background:var(--card);border:1px solid var(--line);border-radius:var(--r);
    box-shadow:var(--shadow);padding:26px clamp(18px,3vw,28px);margin-top:34px}
  .search label{display:block;font-size:14px;font-weight:700;color:var(--navy);margin-bottom:12px}
  .search-row{display:flex;gap:12px}
  .search input{flex:1;min-width:0;border:1.6px solid #d9dfe8;border-radius:12px;padding:16px 18px;
    font-size:16px;font-family:inherit;color:var(--ink);background:#fbfcfe;outline:none;transition:border-color .15s,background .15s}
  .search input:focus{border-color:var(--blue);background:#fff}
  .search input::placeholder{color:var(--ink3)}
  .btn-validar{display:inline-flex;align-items:center;gap:9px;background:var(--navy);color:#fff;border:none;
    border-radius:12px;padding:0 26px;font-size:15px;font-weight:600;font-family:inherit;cursor:pointer;
    white-space:nowrap;transition:background .15s,transform .12s}
  .btn-validar:hover{background:var(--navy-2)}
  .btn-validar:active{transform:translateY(1px)}
  .hint{margin-top:14px;font-size:12.5px;color:var(--ink3);line-height:1.5}

  .count{font-size:13px;font-weight:600;color:var(--ink3);margin:34px 2px 14px}

  /* Tarjeta de resultado */
  .card{background:var(--card);border:1px solid var(--line);border-radius:var(--r);box-shadow:var(--shadow);
    padding:clamp(22px,3vw,30px);animation:up .35s cubic-bezier(.22,1,.36,1)}
  @keyframes up{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
  .card-top{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:26px}
  .cod-k{font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--ink3);margin-bottom:6px}
  .cod-v{font-family:'Sora';font-size:clamp(22px,3.4vw,30px);font-weight:800;color:var(--navy);
    font-variant-numeric:tabular-nums;letter-spacing:.01em;word-break:break-word}
  .estado{display:inline-flex;align-items:center;gap:8px;font-size:14px;font-weight:700;
    padding:9px 16px;border-radius:30px;white-space:nowrap}

  .grid{display:grid;grid-template-columns:1fr 1fr;gap:24px 30px;margin-bottom:24px}
  .cell{display:flex;gap:13px}
  .cell .ic{flex-shrink:0;width:38px;height:38px;border-radius:10px;background:var(--blue-soft);color:var(--blue);
    display:grid;place-items:center;margin-top:2px}
  .cell .k{font-size:11px;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:var(--ink3);margin-bottom:5px}
  .cell .v{font-size:16.5px;font-weight:600;color:var(--navy);line-height:1.35}
  .cell .s{font-size:13px;color:var(--ink2);margin-top:3px}
  .cell .s.mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12.5px}

  .alcance{background:var(--bg);border:1px solid var(--line2);border-radius:12px;padding:18px 20px;margin-bottom:22px}
  .alcance .k{font-size:11px;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:var(--ink3);margin-bottom:8px}
  .alcance .v{font-size:14.5px;color:var(--ink);line-height:1.65}

  .actions{display:flex;gap:11px;flex-wrap:wrap}
  .btn-pdf{display:inline-flex;align-items:center;gap:8px;background:var(--navy);color:#fff;text-decoration:none;
    border-radius:11px;padding:13px 20px;font-size:14px;font-weight:600;transition:background .15s}
  .btn-pdf:hover{background:var(--navy-2)}
  .btn-pdf.alt{background:var(--blue-soft);color:var(--blue)}
  .btn-pdf.alt:hover{background:#dde6fa}
  .pdf-lock{display:inline-flex;align-items:center;gap:9px;background:#fff8ec;border:1px solid #fde68a;
            color:#8a5b00;border-radius:11px;padding:11px 15px;font-size:13.5px;font-weight:600;line-height:1.35}
  .pdf-lock svg{flex-shrink:0}

  /* Mensajes (no encontrado / error) */
  .msg{background:var(--card);border:1px solid var(--line);border-radius:var(--r);box-shadow:var(--shadow-sm);
    padding:44px 28px;text-align:center;animation:up .35s cubic-bezier(.22,1,.36,1)}
  .msg.nf{border-top:3px solid #b91c1c}
  .msg .mic{width:56px;height:56px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;margin-bottom:16px}
  .msg.nf .mic{background:#fdecec;color:#b91c1c}
  .msg.info .mic{background:var(--blue-soft);color:var(--blue)}
  .msg h3{font-size:20px;color:var(--navy);margin-bottom:9px}
  .msg p{font-size:14.5px;color:var(--ink2);line-height:1.65}
  .msg strong{color:var(--ink)}

  .foot{text-align:center;font-size:12px;color:var(--mut);padding:40px 20px 44px;line-height:1.6}
  .foot b{color:var(--ink3);font-weight:600}

  @media(max-width:640px){
    .grid{grid-template-columns:1fr;gap:20px}
    .search-row{flex-direction:column}
    .btn-validar{width:100%;justify-content:center;padding:15px}
    .card-top .estado{order:-1}
  }
</style>
</head>
<body>

<header class="top">
  <div class="wrap">
    <span class="pill">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path d="M12 3l7 4v5c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V7z"/><path d="M9 12l2 2 4-4"/></svg>
      Verificación pública
    </span>
    <h1>Consulta y valida certificaciones</h1>
    <p class="lead">Verifica la autenticidad, el alcance y la vigencia de cualquier certificado emitido por Group&nbsp;Global&nbsp;ISO. Ingresa el código impreso en el certificado.</p>

    <form class="search" method="GET" action="">
      <label for="q">Código del certificado</label>
      <div class="search-row">
        <input type="text" id="q" name="q" value="<?= $h($busco ? $term : '') ?>" placeholder="Ej: GGI-BPL-032026-1300-PE" autocomplete="off" autofocus>
        <button class="btn-validar" type="submit">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
          Validar
        </button>
      </div>
      <p class="hint">Consulta referencial de nuestros registros oficiales. Para constancias formales, escríbenos y te enviaremos la carta de validación firmada.</p>
    </form>
  </div>
</header>

<main class="wrap">
  <?php if ($busco && $error_msg): ?>
    <div class="msg info">
      <span class="mic"><svg width="26" height="26" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9"><circle cx="12" cy="12" r="9"/><path d="M12 8v5m0 3h.01"/></svg></span>
      <h3>Código incompleto</h3>
      <p><?= $h($error_msg) ?></p>
    </div>

  <?php elseif ($busco && !$resultados): ?>
    <div class="msg nf">
      <span class="mic"><svg width="26" height="26" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg></span>
      <h3>Certificado no encontrado</h3>
      <p>No existe un certificado con el código <strong>“<?= $h($term) ?>”</strong>.<br>Verifica que esté escrito correctamente e inténtalo nuevamente.</p>
    </div>

  <?php elseif ($busco): ?>
    <div class="count"><?= count($resultados) ?> resultado<?= count($resultados) != 1 ? 's' : '' ?> encontrado<?= count($resultados) != 1 ? 's' : '' ?></div>
    <?php foreach ($resultados as $r):
        $em     = $EST[$r['estado']] ?? [ucfirst((string)$r['estado']), '#475569', '#eef2f7', '#d1d5db', false];
        $valido = (bool)$em[4];
    ?>
    <article class="card">
      <div class="card-top">
        <div>
          <div class="cod-k">Código</div>
          <div class="cod-v"><?= $h($r['numero_certificado']) ?></div>
        </div>
        <span class="estado" style="color:<?= $em[1] ?>;background:<?= $em[2] ?>;border:1px solid <?= $em[3] ?>;">
          <?php if ($valido): ?>
            <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4"><path d="M12 3l7 4v5c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V7z"/><path d="M9 12l2 2 4-4"/></svg>
          <?php else: ?>
            <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="9"/><path d="M15 9l-6 6M9 9l6 6"/></svg>
          <?php endif; ?>
          <?= $h($em[0]) ?>
        </span>
      </div>

      <div class="grid">
        <div class="cell">
          <span class="ic"><svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path d="M3 21h18M6 21V7l6-4 6 4v14M10 9h.01M14 9h.01M10 13h.01M14 13h.01M10 17h4"/></svg></span>
          <div>
            <div class="k">Organización</div>
            <div class="v"><?= $h($r['razon_social']) ?></div>
            <?php if (!empty($r['ruc'])): ?><div class="s mono">RUC <?= $h($r['ruc']) ?></div><?php endif; ?>
          </div>
        </div>

        <div class="cell">
          <span class="ic"><svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path d="M12 2l2.4 2.1 3.1-.5 1.1 3 2.9 1.3-.9 3 .9 3-2.9 1.3-1.1 3-3.1-.5L12 22l-2.4-2.1-3.1.5-1.1-3L2.5 15.6l.9-3-.9-3 2.9-1.3 1.1-3 3.1.5z"/><path d="M9 12l2 2 4-4"/></svg></span>
          <div>
            <div class="k">Norma certificada</div>
            <div class="v"><?= $h($r['norma']) ?></div>
            <div class="s"><?= $r['casa'] ? $h($r['casa']) . ' — Organismo de certificación' : 'Group Global ISO — Organismo de certificación acreditado' ?></div>
          </div>
        </div>

        <div class="cell">
          <span class="ic"><svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M3 10h18M8 2v4M16 2v4"/></svg></span>
          <div>
            <div class="k">Fecha de emisión</div>
            <div class="v"><?= $fmt($r['fecha_emision']) ?></div>
          </div>
        </div>

        <div class="cell">
          <span class="ic"><svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M3 10h18M8 2v4M16 2v4M9 15l2 2 4-4"/></svg></span>
          <div>
            <div class="k">Válido hasta</div>
            <div class="v"><?= $fmt($r['fecha_vencimiento']) ?></div>
          </div>
        </div>
      </div>

      <?php if (!empty($r['alcance'])): ?>
      <div class="alcance">
        <div class="k">Alcance</div>
        <div class="v"><?= $h($r['alcance']) ?></div>
      </div>
      <?php endif; ?>

      <?php if ($pago_pendiente): ?>
      <div class="actions">
        <span class="pdf-lock">
          <svg width="17" height="17" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
          La descarga del certificado se habilitará al completar el pago.
        </span>
      </div>
      <?php elseif (!empty($r['certificado_archivo'])): ?>
      <div class="actions">
        <a class="btn-pdf" href="<?= $h($appUrl) ?>/verificar/certificado.php?id=<?= (int)$r['id'] ?>&lang=es" target="_blank" rel="noopener">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
          Ver certificado (PDF)
        </a>
        <?php // BPL solo se emite en español → no se ofrece descarga en inglés.
        if (!empty($r['certificado_archivo_en']) && strtoupper((string)$r['norma']) !== 'BPL'): ?>
        <a class="btn-pdf alt" href="<?= $h($appUrl) ?>/verificar/certificado.php?id=<?= (int)$r['id'] ?>&lang=en" target="_blank" rel="noopener">English (PDF)</a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </article>
    <?php endforeach; ?>
  <?php endif; ?>
</main>

<div class="foot">
  <b>Group Global ISO</b> — Verificación oficial de certificados · © <?= date('Y') ?>
</div>
</body>
</html>
