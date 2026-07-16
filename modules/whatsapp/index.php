<?php
/**
 * modules/whatsapp/index.php
 * Bandeja de entrada de WhatsApp (Kapso AI) — dos cuentas con pestañas
 */
$page_title  = 'WhatsApp';
$active_menu = 'whatsapp';
require_once __DIR__ . '/../../core/helpers.php';
session_init();
require_login();
require_role('gerente_general', 'gerente_operaciones');

// Consulta a Kapso API para obtener nombre y calidad del número
function kapso_phone_info(string $api_key, string $phone_id): array {
    if (!$api_key || !$phone_id) return [];
    $url = "https://api.kapso.ai/meta/whatsapp/v24.0/{$phone_id}?fields=verified_name,display_phone_number,quality_rating";
    $ctx = stream_context_create(['http' => [
        'method'  => 'GET',
        'header'  => "X-API-Key: {$api_key}\r\nAccept: application/json\r\n",
        'timeout' => 5,
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    return $raw ? (json_decode($raw, true) ?? []) : [];
}

$cuentas = [
    [
        'key'      => 'cuenta1',
        'fallback' => '913 735 607',
        'token'    => defined('KAPSO_EMBED_TOKEN')   ? KAPSO_EMBED_TOKEN   : '',
        'api_key'  => defined('KAPSO_API_KEY_1')     ? KAPSO_API_KEY_1     : '',
        'phone_id' => defined('KAPSO_PHONE_ID_1')    ? KAPSO_PHONE_ID_1    : '',
    ],
    [
        'key'      => 'cuenta2',
        'fallback' => '944 746 903',
        'token'    => defined('KAPSO_EMBED_TOKEN_2')   ? KAPSO_EMBED_TOKEN_2   : '',
        'api_key'  => defined('KAPSO_API_KEY_2')       ? KAPSO_API_KEY_2       : '',
        'phone_id' => defined('KAPSO_PHONE_ID_2')      ? KAPSO_PHONE_ID_2      : '',
    ],
    [
        'key'      => 'cuenta3',
        'fallback' => 'GGI Notificaciones',
        'token'    => defined('KAPSO_EMBED_TOKEN_3')   ? KAPSO_EMBED_TOKEN_3   : '',
        'api_key'  => defined('KAPSO_API_KEY')         ? KAPSO_API_KEY         : '',
        'phone_id' => defined('KAPSO_PHONE_NUMBER_ID') ? KAPSO_PHONE_NUMBER_ID : '',
    ],
];

// Enriquecer cada cuenta con datos de la API
foreach ($cuentas as &$c) {
    $info = kapso_phone_info($c['api_key'], $c['phone_id']);
    $c['nombre']  = $info['verified_name']       ?? '';
    $c['telefono']= $info['display_phone_number'] ?? $c['fallback'];
    $c['calidad'] = strtolower($info['quality_rating'] ?? 'unknown');
}
unset($c);
?>
<?php include __DIR__ . '/../../templates/layout.php'; ?>

<style>
  .wa-wrapper {
    display: flex;
    flex-direction: column;
    /* Usa dvh para descontar la barra del navegador en móvil;
       var(--header-h) es 60px escritorio / 50px móvil según layout.php */
    height: calc(100dvh - var(--header-h, 60px) - var(--ann-h, 0px) - 24px);
    min-height: 300px;
    padding: 0;
    /* overflow: hidden solo en los paneles, no en el wrapper completo,
       para que el header pueda hacer scroll horizontal en móvil */
  }

  /* ── Header con pestañas ── */
  .wa-header {
    display: flex;
    align-items: stretch;
    padding: 0 20px;
    border-bottom: 1px solid var(--border-color, #e8eaf0);
    flex-shrink: 0;
    background: var(--card-bg, #fff);
    gap: 0;
    /* Evita que el contenido quede cortado en escritorio */
    overflow: visible;
  }
  .wa-header-icon {
    width: 30px; height: 30px;
    background: #25D366;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
    align-self: center;
    margin-right: 16px;
  }
  .wa-header-icon svg { width: 16px; height: 16px; fill: #fff; }

  /* Las pestañas se deslizan horizontalmente cuando no caben */
  .wa-tabs {
    display: flex;
    gap: 0;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;          /* Firefox */
    -ms-overflow-style: none;       /* IE/Edge */
    flex: 1;
    min-width: 0;
  }
  .wa-tabs::-webkit-scrollbar { display: none; }

  .wa-tab {
    display: flex;
    flex-direction: column;
    justify-content: center;
    gap: 2px;
    padding: 12px 22px 10px;
    cursor: pointer;
    border-bottom: 2px solid transparent;
    transition: border-color .15s, background .15s;
    user-select: none;
    white-space: nowrap;
    flex-shrink: 0;   /* no comprimir — hace falta para que el overflow funcione */
    border-radius: 0;
  }
  .wa-tab:hover { background: var(--s-ok-bg); }
  .wa-tab.active { border-bottom-color: #25D366; }

  .wa-tab-name {
    font-size: 13px;
    font-weight: 700;
    color: var(--text-color, #1a1d2e);
    display: flex;
    align-items: center;
    gap: 7px;
  }
  .wa-tab:not(.active) .wa-tab-name { color: var(--text-muted, #6b7280); }

  .wa-tab-phone {
    font-size: 11px;
    color: var(--text-muted, #6b7280);
    font-family: monospace;
    letter-spacing: .3px;
  }

  /* Semáforo de calidad */
  .wa-quality {
    width: 7px; height: 7px;
    border-radius: 50%;
    flex-shrink: 0;
    display: inline-block;
  }
  .wa-quality.green   { background: #22c55e; }
  .wa-quality.yellow  { background: #f59e0b; }
  .wa-quality.red     { background: #ef4444; }
  .wa-quality.unknown { background: var(--bg-surface2); }

  /* ── Paneles ── */
  .wa-panels { flex: 1; overflow: hidden; position: relative; min-height: 0; }
  .wa-panel  { position: absolute; inset: 0; display: none; }
  .wa-panel.active { display: block; }
  .wa-panel iframe { width: 100%; height: 100%; border: none; display: block; }

  /* ── Sin token ── */
  .wa-setup {
    width: 100%; height: 100%;
    display: flex; align-items: center; justify-content: center; padding: 40px 20px;
  }
  .wa-setup-card {
    background: var(--card-bg, #fff);
    border: 1px solid var(--border-color, #e8eaf0);
    border-radius: 14px; padding: 40px 44px;
    max-width: 480px; width: 100%; text-align: center;
    box-shadow: 0 4px 24px rgba(0,0,0,.07);
  }
  .wa-setup-icon {
    width: 56px; height: 56px; background: var(--s-ok-bg); border-radius: 50%;
    display: flex; align-items: center; justify-content: center; margin: 0 auto 16px;
  }
  .wa-setup-icon svg { width: 28px; height: 28px; fill: #25D366; }
  .wa-setup-title { font-size: 1rem; font-weight: 700; color: var(--text-color,#1a1d2e); margin-bottom: 8px; }
  .wa-setup-desc  { font-size: .84rem; color: var(--text-muted,#6b7280); line-height: 1.6; }

  /* ═══════════════════════════════
     MÓVIL (≤ 768px)
     ═══════════════════════════════ */
  @media (max-width: 768px) {
    .wa-wrapper {
      /* En móvil el main tiene padding 12px top+bottom → descontar 24px */
      height: calc(100dvh - 50px - 24px);
      /* fallback para navegadores sin dvh */
      height: calc(100vh - 50px - 24px);
    }

    /* Header más compacto */
    .wa-header { padding: 0 10px; }
    .wa-header-icon { margin-right: 10px; }

    /* Pestañas más compactas para que quepan más en pantalla */
    .wa-tab { padding: 10px 16px 8px; }
    .wa-tab-name { font-size: 12px; gap: 5px; }
    .wa-tab-phone { font-size: 10px; }
  }

  @media (max-width: 480px) {
    /* En pantallas muy pequeñas, ocultar el ícono WA para ganar espacio */
    .wa-header-icon { display: none; }

    .wa-tab { padding: 10px 14px 8px; }

    /* Mostrar número solo en pestaña activa */
    .wa-tab:not(.active) .wa-tab-phone { display: none; }
  }
</style>

<?php
$wa_icon_path = '<path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>';
?>

<div class="wa-wrapper">
  <div class="wa-header">
    <div class="wa-header-icon">
      <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><?= $wa_icon_path ?></svg>
    </div>
    <nav class="wa-tabs">
      <?php foreach ($cuentas as $i => $c):
        $quality_class = in_array($c['calidad'], ['green','yellow','red']) ? $c['calidad'] : 'unknown';
        $nombre_display = $c['nombre'] ?: $c['fallback'];
      ?>
      <div class="wa-tab <?= $i === 0 ? 'active' : '' ?>"
           onclick="switchTab('<?= $c['key'] ?>', this)">
        <div class="wa-tab-name">
          <span class="wa-quality <?= $quality_class ?>"></span>
          <?= htmlspecialchars($nombre_display, ENT_QUOTES) ?>
        </div>
        <div class="wa-tab-phone"><?= htmlspecialchars($c['telefono'], ENT_QUOTES) ?></div>
      </div>
      <?php endforeach; ?>
    </nav>
  </div>

  <div class="wa-panels">
    <?php foreach ($cuentas as $i => $c): ?>
    <div class="wa-panel <?= $i === 0 ? 'active' : '' ?>" id="panel-<?= $c['key'] ?>">
      <?php if (!empty($c['token'])): ?>
        <iframe
          src="https://inbox.kapso.ai/embed/<?= htmlspecialchars($c['token'], ENT_QUOTES) ?>?mode=light"
          allow="microphone; clipboard-write"
          loading="lazy"
          title="WhatsApp <?= htmlspecialchars($c['nombre'] ?: $c['fallback'], ENT_QUOTES) ?>">
        </iframe>
      <?php else: ?>
        <div class="wa-setup">
          <div class="wa-setup-card">
            <div class="wa-setup-icon">
              <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><?= $wa_icon_path ?></svg>
            </div>
            <div class="wa-setup-title">Token no configurado</div>
            <div class="wa-setup-desc">
              Agrega el token de embed de Kapso para la cuenta
              <strong><?= htmlspecialchars($c['nombre'] ?: $c['fallback'], ENT_QUOTES) ?></strong>
              en el archivo <code>.env</code>.
            </div>
          </div>
        </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<script>
function switchTab(key, tabEl) {
  document.querySelectorAll('.wa-tab').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.wa-panel').forEach(p => p.classList.remove('active'));
  tabEl.classList.add('active');
  document.getElementById('panel-' + key).classList.add('active');
}
</script>

<?php include __DIR__ . '/../../templates/layout_end.php'; ?>
