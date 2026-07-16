<?php
/**
 * verificar/index.php
 * Verificación PÚBLICA de certificados (sin login). Busca por código de certificado
 * o razón social/RUC y muestra la validez. Solo certificados emitidos (no en_proceso).
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
        $error_msg = 'Ingrese al menos 3 caracteres del código o de la razón social.';
    } else {
        $like = '%' . $term . '%';
        $st = $pdo->prepare("
            SELECT ce.id, ce.numero_certificado, ce.fecha_emision, ce.fecha_vencimiento,
                   ce.estado, ce.alcance, ce.certificado_archivo, ce.certificado_archivo_en,
                   cl.razon_social, cl.ruc,
                   no.codigo AS norma, cc.nombre AS casa
            FROM certificados ce
            JOIN clientes cl        ON cl.id = ce.cliente_id
            LEFT JOIN normas_iso no  ON no.id = ce.norma_iso_id
            LEFT JOIN casas_certificadoras cc ON cc.id = ce.casa_certificadora_id
            WHERE ce.deleted_at IS NULL
              AND ce.estado <> 'en_proceso'
              AND ce.numero_certificado IS NOT NULL AND ce.numero_certificado <> ''
              AND (ce.numero_certificado = ? OR ce.numero_certificado LIKE ? OR cl.razon_social LIKE ? OR cl.ruc = ?)
            ORDER BY (ce.numero_certificado = ?) DESC, ce.fecha_emision DESC
            LIMIT 20
        ");
        $st->execute([$term, $like, $like, $term, $term]);
        $resultados = $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

// estado → [label, color, bg, icon svg-path]
$icoCheck = '<path d="M9 12.5l2 2 4-4.5"/><circle cx="12" cy="12" r="9"/>';
$icoClock = '<circle cx="12" cy="12" r="9"/><path d="M12 8v4l3 2"/>';
$icoAlert = '<path d="M12 9v4m0 4h.01M10.3 4.3 2.8 17a2 2 0 0 0 1.7 3h15a2 2 0 0 0 1.7-3L13.7 4.3a2 2 0 0 0-3.4 0z"/>';
$EST = [
    'vigente'    => ['Certificado vigente',  '#0f7a3d', '#e7f6ec', $icoCheck],
    'por_vencer' => ['Próximo a vencer',     '#a1610a', '#fdf1dc', $icoClock],
    'vencido'    => ['Certificado vencido',  '#b3261e', '#fbe9e7', $icoAlert],
    'suspendido' => ['Certificado suspendido','#b3401e', '#fbeae2', $icoAlert],
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
<title>Verificar certificado — Group Global ISO</title>
<link rel="icon" href="<?= $h($appUrl) ?>/assets/icons/icon-128.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
  :root{
    --azul:#16307a; --azul-d:#0f2559; --azul-l:#3a5bb5;
    --ink:#141a2e; --ink2:#5a6478; --ink3:#8a93a6;
    --line:#e7eaf1; --bg:#eef1f8; --card:#ffffff;
    --ok:#0f7a3d; --shadow:0 10px 30px rgba(16,32,80,.10);
  }
  *{ box-sizing:border-box; }
  html,body{ margin:0; }
  body{ font-family:'Inter',-apple-system,Segoe UI,Roboto,sans-serif; color:var(--ink);
        background:var(--bg); -webkit-font-smoothing:antialiased; }

  /* Hero */
  .hero{ background:linear-gradient(135deg,var(--azul) 0%,var(--azul-d) 100%); color:#fff;
         padding:40px 16px 92px; text-align:center; position:relative; }
  .hero::after{ content:""; position:absolute; inset:0; background:
      radial-gradient(600px 200px at 50% -40px, rgba(255,255,255,.12), transparent 70%); pointer-events:none; }
  .hero .logo{ height:46px; width:auto; margin-bottom:16px; filter:brightness(0) invert(1); opacity:.96; }
  .hero h1{ font-size:24px; font-weight:800; letter-spacing:-.02em; margin:0 0 8px; }
  .hero p{ font-size:14px; line-height:1.5; margin:0 auto; max-width:440px; color:#cdd7f2; }

  .wrap{ max-width:640px; margin:-64px auto 0; padding:0 16px 56px; position:relative; z-index:2; }

  /* Search */
  .search{ display:flex; align-items:center; gap:8px; background:var(--card);
           border-radius:16px; padding:8px 8px 8px 16px; box-shadow:var(--shadow); }
  .search svg.mag{ color:var(--ink3); flex-shrink:0; }
  .search input{ flex:1; border:none; outline:none; font-family:inherit; font-size:16px;
                 color:var(--ink); background:transparent; min-width:0; padding:12px 4px; }
  .search input::placeholder{ color:var(--ink3); }
  .search button{ border:none; background:var(--azul); color:#fff; font-family:inherit;
                  font-weight:600; font-size:15px; padding:13px 22px; border-radius:11px;
                  cursor:pointer; white-space:nowrap; transition:background .15s; }
  .search button:hover{ background:var(--azul-d); }
  .hint{ font-size:12.5px; color:var(--ink2); text-align:center; margin:14px 8px 0; }

  /* States / results */
  .count{ font-size:12px; font-weight:600; letter-spacing:.03em; text-transform:uppercase;
          color:var(--ink3); margin:26px 4px 12px; }
  .msg{ background:var(--card); border-radius:16px; padding:34px 24px; text-align:center;
        color:var(--ink2); font-size:14px; line-height:1.6; box-shadow:var(--shadow); margin-top:22px; }
  .msg .ic{ width:56px; height:56px; border-radius:50%; background:#f1f4fb; color:var(--ink3);
            display:inline-flex; align-items:center; justify-content:center; margin-bottom:14px; }
  .msg strong{ color:var(--ink); }

  .card{ background:var(--card); border-radius:18px; overflow:hidden; box-shadow:var(--shadow); margin-bottom:16px; }
  .ribbon{ display:flex; align-items:center; gap:10px; padding:14px 18px; font-weight:700; font-size:15px; }
  .ribbon .ic{ width:30px; height:30px; border-radius:50%; background:rgba(255,255,255,.6);
               display:inline-flex; align-items:center; justify-content:center; }
  .ribbon .ver{ margin-left:auto; font-size:11px; font-weight:700; letter-spacing:.06em;
                text-transform:uppercase; opacity:.7; display:flex; align-items:center; gap:4px; }
  .body{ padding:18px; display:flex; gap:18px; }
  .info{ flex:1; min-width:0; }
  .emp{ font-size:18px; font-weight:800; letter-spacing:-.01em; line-height:1.3; margin:0 0 3px; }
  .ruc{ font-size:12.5px; color:var(--ink2); font-family:ui-monospace,SFMono-Regular,Menlo,monospace; margin:0 0 14px; }
  .grid{ display:grid; grid-template-columns:1fr; gap:0; }
  .cell{ display:flex; justify-content:space-between; gap:14px; padding:9px 0; border-top:1px solid var(--line); font-size:14px; }
  .cell:first-child{ border-top:none; }
  .cell .k{ color:var(--ink2); }
  .cell .v{ font-weight:600; text-align:right; }
  .cell .v.mono{ font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:13px; }
  .qr{ flex-shrink:0; width:118px; text-align:center; }
  .qr .box{ padding:8px; background:#fff; border:1px solid var(--line); border-radius:12px; }
  .qr img{ width:100%; height:auto; display:block; }
  .qr span{ display:block; font-size:11px; color:var(--ink2); margin-top:7px; line-height:1.3; }
  .actions{ display:flex; gap:10px; padding:0 18px 18px; flex-wrap:wrap; }
  .btn{ flex:1; text-align:center; text-decoration:none; font-weight:600; font-size:14px;
        padding:13px 14px; border-radius:11px; white-space:nowrap; transition:filter .15s,background .15s; }
  .btn.pdf{ background:var(--azul); color:#fff; display:inline-flex; align-items:center; justify-content:center; gap:7px; }
  .btn.pdf:hover{ background:var(--azul-d); }
  .btn.alt{ background:#f1f4fb; color:var(--azul); }
  .btn.alt:hover{ filter:brightness(.97); }

  .foot{ text-align:center; font-size:11.5px; color:var(--ink3); margin-top:30px; line-height:1.6; }
  .foot b{ color:var(--ink2); font-weight:600; }

  @media(max-width:520px){
    .hero{ padding:34px 16px 88px; }
    .body{ flex-direction:column-reverse; gap:16px; }
    .qr{ width:100%; display:flex; align-items:center; gap:12px; text-align:left; }
    .qr .box{ width:96px; flex-shrink:0; }
    .qr span{ margin-top:0; }
  }
</style>
</head>
<body>

<header class="hero">
  <img class="logo" src="<?= $h($appUrl) ?>/assets/logo-ggi.png" alt="Group Global ISO" onerror="this.style.display='none'">
  <h1>Verificación de certificados</h1>
  <p>Confirme la autenticidad y vigencia de un certificado emitido por Group&nbsp;Global&nbsp;ISO.</p>
</header>

<div class="wrap">

  <form class="search" method="GET" action="">
    <svg class="mag" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
    <input type="text" name="q" value="<?= $h($busco ? $term : '') ?>" placeholder="Código del certificado o razón social" aria-label="Código o empresa" autocomplete="off" autofocus>
    <button type="submit">Consultar</button>
  </form>
  <p class="hint">Busque por el código impreso en el certificado o por la razón social de la empresa.</p>

  <?php if ($busco && $error_msg): ?>
    <div class="msg"><div class="ic"><svg width="26" height="26" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M12 8v5m0 3h.01"/></svg></div><br><?= $h($error_msg) ?></div>

  <?php elseif ($busco && !$resultados): ?>
    <div class="msg">
      <div class="ic"><svg width="26" height="26" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg></div><br>
      No se encontró ningún certificado para <strong>“<?= $h($term) ?>”</strong>.<br>Verifique el código o el nombre de la empresa e intente nuevamente.
    </div>

  <?php elseif ($busco): ?>
    <div class="count"><?= count($resultados) ?> resultado<?= count($resultados) != 1 ? 's' : '' ?></div>
    <?php foreach ($resultados as $r):
        $em = $EST[$r['estado']] ?? [ucfirst($r['estado']), '#475569', '#eef2f7', $icoCheck];
        $verifyUrl = $appUrl . '/verificar/?codigo=' . rawurlencode($r['numero_certificado']);
        $qrSrc = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&margin=0&data=' . rawurlencode($verifyUrl);
    ?>
    <div class="card">
      <div class="ribbon" style="color:<?= $em[1] ?>;background:<?= $em[2] ?>;">
        <span class="ic"><svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $em[3] ?></svg></span>
        <?= $h($em[0]) ?>
        <span class="ver"><svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4"><path d="M20 6 9 17l-5-5"/></svg> Verificado</span>
      </div>
      <div class="body">
        <div class="info">
          <p class="emp"><?= $h($r['razon_social']) ?></p>
          <p class="ruc">RUC <?= $h($r['ruc']) ?></p>
          <div class="grid">
            <div class="cell"><span class="k">Norma</span><span class="v"><?= $h($r['norma']) ?></span></div>
            <div class="cell"><span class="k">Código</span><span class="v mono"><?= $h($r['numero_certificado']) ?></span></div>
            <?php if ($r['casa']): ?><div class="cell"><span class="k">Casa certificadora</span><span class="v"><?= $h($r['casa']) ?></span></div><?php endif; ?>
            <div class="cell"><span class="k">Fecha de emisión</span><span class="v"><?= $fmt($r['fecha_emision']) ?></span></div>
            <div class="cell"><span class="k">Vencimiento</span><span class="v"><?= $fmt($r['fecha_vencimiento']) ?></span></div>
          </div>
        </div>
        <div class="qr">
          <div class="box"><img src="<?= $h($qrSrc) ?>" alt="Código QR de verificación" loading="lazy"></div>
          <span>Escanee para<br>volver a verificar</span>
        </div>
      </div>
      <div class="actions">
        <a class="btn pdf" href="<?= $h($appUrl) ?>/verificar/certificado.php?id=<?= (int)$r['id'] ?>&lang=es" target="_blank" rel="noopener">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
          Ver certificado (PDF)
        </a>
        <?php if (!empty($r['certificado_archivo_en'])): ?>
        <a class="btn alt" href="<?= $h($appUrl) ?>/verificar/certificado.php?id=<?= (int)$r['id'] ?>&lang=en" target="_blank" rel="noopener">English</a>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <div class="foot">
    <b>Group Global ISO</b> — Verificación oficial de certificados.<br>
    © <?= date('Y') ?> · Todos los derechos reservados.
  </div>
</div>
</body>
</html>
