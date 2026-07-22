<?php
/**
 * modules/whatsapp/numeros.php — Números de WhatsApp conectados.
 *
 * Reemplaza el panel de Kapso: qué números tiene la cuenta, cuál está
 * realmente operativo y cómo está su reputación ante Meta.
 *
 * Nota importante sobre "conectar por QR": la API oficial de Meta NO lo
 * contempla. El QR pertenece a WhatsApp Web y a librerías no oficiales.
 * Con la API oficial un número se agrega desde Meta y se verifica por SMS o
 * llamada; después hay que REGISTRARLO en Cloud API, que es el paso que
 * habitualmente se olvida y deja el número sin poder enviar.
 */
$page_title  = 'Números WhatsApp';
$active_menu = 'wa_numeros';
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../../core/WhatsAppService.php';
session_init();
require_login();
require_role('gerente_general', 'gerente_operaciones');

$pdo     = Database::get();
$numeros = WhatsAppService::listarNumeros();
$error   = $numeros === null ? (WhatsAppService::lastError() ?: 'No se pudo consultar Meta') : null;

// Conversaciones por número, para saber cuál se está usando de verdad
$uso = [];
foreach ($pdo->query("SELECT phone_number_id, COUNT(*) n FROM wa_conversaciones GROUP BY phone_number_id") as $r) {
    $uso[$r['phone_number_id']] = (int) $r['n'];
}

$activo = defined('META_WA_PHONE_NUMBER_ID') ? META_WA_PHONE_NUMBER_ID : '';

$calidad = [
    'GREEN'  => ['Buena',    's-ok'],
    'YELLOW' => ['Media',    's-warn'],
    'RED'    => ['Baja',     's-err'],
    'UNKNOWN'=> ['Sin datos','s-neutral'],
];
?>
<?php include __DIR__ . '/../../templates/layout.php'; ?>

<style>
  .nm-card { background: var(--bg-surface); border: 1px solid var(--brd); border-radius: 12px;
             padding: 16px 18px; margin-bottom: 12px; }
  .nm-card.activo { border-left: 3px solid var(--ac); }
  .nm-r1 { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 6px; }
  .nm-tel { font-size: 1rem; font-weight: 700; color: var(--tx); }
  .nm-nom { font-size: .8rem; color: var(--tx2); }
  .nm-datos { display: flex; gap: 18px; flex-wrap: wrap; margin-top: 12px; }
  .nm-d { font-size: .76rem; color: var(--tx2); }
  .nm-d b { display: block; font-size: .68rem; text-transform: uppercase; letter-spacing: .06em;
            color: var(--tx3); font-weight: 700; margin-bottom: 2px; }
  .nm-chip { font-size: .65rem; font-weight: 700; padding: 2px 9px; border-radius: 999px; }
  .nm-aviso { padding: 13px 15px; border-radius: 10px; font-size: .84rem; line-height: 1.55;
              margin-bottom: 16px; }
  .nm-aviso.info { background: var(--s-info-bg); color: var(--s-info-tx); }
  .nm-aviso.mal  { background: var(--s-err-bg);  color: var(--s-err-tx); }
  .nm-pasos { counter-reset: p; margin: 10px 0 0; padding: 0; list-style: none; }
  .nm-pasos li { counter-increment: p; position: relative; padding: 7px 0 7px 30px; font-size: .83rem;
                 color: var(--tx2); line-height: 1.5; }
  .nm-pasos li::before { content: counter(p); position: absolute; left: 0; top: 7px;
                         width: 20px; height: 20px; border-radius: 50%; background: var(--ac-soft);
                         color: var(--ac); font-size: .68rem; font-weight: 700;
                         display: flex; align-items: center; justify-content: center; }
</style>

<div class="page-header" style="margin-bottom:16px">
  <h1>Números de WhatsApp</h1>
  <p>Números conectados a tu cuenta de Meta y su estado real</p>
</div>

<?php if ($error): ?>
  <div class="nm-aviso mal"><?= sanitize($error) ?></div>
<?php else: ?>

  <?php foreach ($numeros as $n):
    $q  = $calidad[$n['quality_rating'] ?? 'UNKNOWN'] ?? $calidad['UNKNOWN'];
    $es = ($n['id'] ?? '') === $activo;
    $conectado = ($n['status'] ?? '') === 'CONNECTED';
  ?>
    <div class="nm-card <?= $es ? 'activo' : '' ?>">
      <div class="nm-r1">
        <span class="nm-tel"><?= sanitize($n['display_phone_number'] ?? '—') ?></span>
        <?php if ($es): ?>
          <span class="nm-chip" style="background:var(--ac-soft);color:var(--ac)">En uso por el sistema</span>
        <?php endif; ?>
        <span class="nm-chip" style="background:var(--<?= $conectado ? 's-ok' : 's-err' ?>-bg);
              color:var(--<?= $conectado ? 's-ok' : 's-err' ?>-tx)">
          <?= $conectado ? 'Conectado' : sanitize($n['status'] ?? 'Sin registrar') ?>
        </span>
      </div>
      <div class="nm-nom"><?= sanitize($n['verified_name'] ?? '') ?></div>

      <div class="nm-datos">
        <div class="nm-d"><b>Calidad</b>
          <span class="nm-chip" style="background:var(--<?= $q[1] ?>-bg);color:var(--<?= $q[1] ?>-tx)"><?= $q[0] ?></span>
        </div>
        <div class="nm-d"><b>Identificador</b><?= sanitize($n['id'] ?? '') ?></div>
        <div class="nm-d"><b>Conversaciones</b><?= (int) ($uso[$n['id'] ?? ''] ?? 0) ?></div>
        <div class="nm-d"><b>Nombre</b><?= sanitize($n['name_status'] ?? '—') ?></div>
        <div class="nm-d"><b>Capacidad</b><?= sanitize($n['throughput']['level'] ?? '—') ?></div>
      </div>

      <?php if (!$conectado): ?>
        <div class="nm-aviso mal" style="margin:12px 0 0">
          Este número está en la cuenta pero <strong>no registrado en Cloud API</strong>:
          no puede enviar ni recibir. Es el paso que se suele olvidar.
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

  <div class="nm-aviso info">
    <strong>Agregar otro número</strong>
    <p style="margin:6px 0 0">
      La API oficial de Meta <strong>no permite conectar por código QR</strong>: eso pertenece a
      WhatsApp Web y a herramientas no oficiales. Un número solo puede estar en una cuenta a la vez,
      así que si hoy está en otra plataforma primero hay que liberarlo ahí.
    </p>
    <ol class="nm-pasos">
      <li>En <strong>Meta → WhatsApp → Configuración de la API</strong>, elegir <em>Agregar número de teléfono</em>.</li>
      <li>Verificarlo por SMS o llamada.</li>
      <li><strong>Registrarlo en Cloud API</strong> con un PIN de 6 dígitos. Sin este paso el número
          aparece en la lista pero no envía nada.</li>
      <li>Volver acá y recargar: el número aparecerá como <em>Conectado</em>.</li>
    </ol>
  </div>

<?php endif; ?>

<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
