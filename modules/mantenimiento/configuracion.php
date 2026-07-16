<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();
require_role('gerente_general');

$pdo = Database::get();
$success = '';

// Asegurar tabla configuracion
$pdo->exec("CREATE TABLE IF NOT EXISTS configuracion (
  clave VARCHAR(100) NOT NULL,
  valor TEXT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function cfg_get(PDO $pdo, string $clave, string $default = ''): string {
    $r = $pdo->prepare("SELECT valor FROM configuracion WHERE clave=? LIMIT 1");
    $r->execute([$clave]);
    $v = $r->fetchColumn();
    return $v !== false ? (string)$v : $default;
}
function cfg_set(PDO $pdo, string $clave, string $valor): void {
    $pdo->prepare("INSERT INTO configuracion (clave, valor) VALUES (?,?)
        ON DUPLICATE KEY UPDATE valor=?, updated_at=NOW()")
        ->execute([$clave, $valor, $valor]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $accion_cfg = $_POST['accion_cfg'] ?? 'cotizacion';

    if ($accion_cfg === 'cotizacion') {
        $campana = trim($_POST['campana_descuento'] ?? '');
        cfg_set($pdo, 'campana_descuento', $campana);
        log_actividad('mantenimiento', 'config_update', 'Campaña descuento actualizada');
        $success = 'Configuración de cotización guardada.';
    } elseif ($accion_cfg === 'anuncio') {
        cfg_set($pdo, 'anuncio_activo', isset($_POST['anuncio_activo']) ? '1' : '0');
        cfg_set($pdo, 'anuncio_texto',  trim($_POST['anuncio_texto']  ?? ''));
        cfg_set($pdo, 'anuncio_tipo',   in_array($_POST['anuncio_tipo'] ?? '', ['info','warning','maintenance','success']) ? $_POST['anuncio_tipo'] : 'info');

        // Si el admin marca "forzar re-notificación", incrementar versión.
        // Esto invalida el dismiss de TODOS los usuarios, forzando que vean el anuncio de nuevo.
        if (!empty($_POST['anuncio_force_notify'])) {
            $v_actual = (int)cfg_get($pdo, 'anuncio_version', '1');
            cfg_set($pdo, 'anuncio_version', (string)($v_actual + 1));
            // Borrar todos los dismisses anteriores para que el anuncio se muestre de nuevo a todos
            try {
                $nuevo_hash = md5(
                    trim($_POST['anuncio_texto'] ?? '') .
                    (in_array($_POST['anuncio_tipo'] ?? '', ['info','warning','maintenance','success']) ? $_POST['anuncio_tipo'] : 'info') .
                    (string)($v_actual + 1)
                );
                // Al cambiar la versión, el hash ya cambia, por lo que los dismiss
                // anteriores no aplican. No es necesario borrar la tabla.
            } catch (Exception $e) {}
            $success = 'Anuncio guardado. Versión incrementada — todos los usuarios verán el anuncio nuevamente.';
        } else {
            $success = 'Anuncio global guardado.';
        }
        cache_forget('anuncio_global'); // refleja el cambio en el header sin esperar el TTL
        log_actividad('mantenimiento', 'config_update', 'Anuncio global actualizado');
    }
}

$cfg_campana     = cfg_get($pdo, 'campana_descuento', 'Impulso Empresarial ' . date('Y'));
$cfg_ann_activo  = cfg_get($pdo, 'anuncio_activo',  '0') === '1';
$cfg_ann_texto   = cfg_get($pdo, 'anuncio_texto',   '');
$cfg_ann_tipo    = cfg_get($pdo, 'anuncio_tipo',    'info');
$cfg_ann_version = cfg_get($pdo, 'anuncio_version', '1');

// Contar cuántos usuarios ya cerraron el anuncio actual
$_ann_hash_actual = md5($cfg_ann_texto . $cfg_ann_tipo . $cfg_ann_version);
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM anuncios_vistos WHERE ann_hash = ?");
    $stmt->execute([$_ann_hash_actual]);
    $cfg_ann_vistos = (int)$stmt->fetchColumn();
} catch (Exception $e) {
    $cfg_ann_vistos = 0;
}

$page_title  = 'Configuración de Cotización';
$active_menu = 'configuracion';
require_once __DIR__ . '/../../templates/layout.php';
?>

<div class="page-header">
  <h1>Configuración</h1>
  <p>Parámetros globales del sistema.</p>
</div>

<?php if ($success): ?>
<div style="display:flex;align-items:center;gap:10px;background:var(--s-ok-bg);border:1px solid var(--s-ok-brd);border-left:4px solid #2DB140;border-radius:8px;padding:12px 16px;margin-bottom:20px;font-size:13px;color:var(--s-ok-tx)">
  <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="#2DB140" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
  <?= sanitize($success) ?>
</div>
<?php endif ?>

<!-- ══════════ ANUNCIO GLOBAL ══════════ -->
<form method="POST" style="max-width:680px;margin-bottom:28px">
  <?= csrf_field() ?>
  <input type="hidden" name="accion_cfg" value="anuncio">

  <div class="card">
    <div style="display:flex;align-items:center;gap:10px;padding-bottom:16px;border-bottom:1px solid var(--gris-borde);margin-bottom:20px">
      <div style="width:34px;height:34px;background:var(--gris-bg);border:1px solid var(--gris-borde);border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="var(--gris-texto)" stroke-width="2"><path d="M22 17H2a3 3 0 0 0 3-3V9a7 7 0 0 1 14 0v5a3 3 0 0 0 3 3zm-8.27 4a2 2 0 0 1-3.46 0"/></svg>
      </div>
      <div>
        <div style="font-family:'Inter',sans-serif;font-size:14px;font-weight:700;color:var(--gris-dark)">Anuncio global del sistema</div>
        <div style="font-size:12px;color:var(--gris-texto);margin-top:1px">Barra de anuncio visible para todos los usuarios en la parte superior</div>
      </div>
    </div>

    <div style="display:grid;gap:16px">

      <!-- Toggle activo -->
      <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 14px;background:var(--gris-bg);border-radius:9px;border:1px solid var(--gris-borde)">
        <div>
          <div style="font-size:13px;font-weight:600;color:var(--gris-dark)">Mostrar barra de anuncio</div>
          <div style="font-size:11.5px;color:var(--gris-texto);margin-top:2px">Cuando está activo, el mensaje se muestra a todos los usuarios</div>
        </div>
        <label style="position:relative;display:inline-flex;align-items:center;cursor:pointer;gap:0">
          <input type="checkbox" name="anuncio_activo" id="ann-toggle" value="1" <?= $cfg_ann_activo ? 'checked' : '' ?>
                 style="position:absolute;opacity:0;width:0;height:0" onchange="annPreview()">
          <span id="ann-toggle-ui" style="
            display:inline-block;width:40px;height:22px;border-radius:11px;
            background:<?= $cfg_ann_activo ? 'var(--azul)' : 'var(--gris-borde)' ?>;
            position:relative;transition:background 0.2s;flex-shrink:0;">
            <span style="position:absolute;top:3px;left:3px;width:16px;height:16px;border-radius:50%;background:var(--bg-surface);
              transition:transform 0.2s;transform:<?= $cfg_ann_activo ? 'translateX(18px)' : 'translateX(0)' ?>"></span>
          </span>
        </label>
      </div>

      <!-- Tipo -->
      <div>
        <label style="display:block;font-size:12px;font-weight:600;color:var(--gris-texto);margin-bottom:7px">Tipo de anuncio</label>
        <div style="display:flex;gap:8px;flex-wrap:wrap" id="ann-tipo-btns">
          <?php
            $tipos = [
              'info'        => ['Info',         'var(--s-info-bg)','#1E40AF','#BFDBFE'],
              'warning'     => ['Advertencia',  'var(--s-warn-bg)','var(--s-warn-tx)','#FDE68A'],
              'maintenance' => ['Mantenimiento','var(--s-purple-bg)','#4C1D95','#DDD6FE'],
              'success'     => ['Éxito',        'var(--s-ok-bg)','#065F46','#A7F3D0'],
            ];
            foreach ($tipos as $key => [$label, $bg, $tx, $brd]):
          ?>
          <label style="cursor:pointer">
            <input type="radio" name="anuncio_tipo" value="<?= $key ?>" <?= $cfg_ann_tipo === $key ? 'checked' : '' ?>
                   style="display:none" onchange="annPreview()">
            <span class="ann-tipo-opt" data-key="<?= $key ?>" style="
              display:inline-flex;align-items:center;gap:5px;padding:5px 12px;border-radius:100px;
              font-size:12px;font-weight:600;cursor:pointer;border:1.5px solid;
              transition:all .15s;
              background:<?= $cfg_ann_tipo === $key ? $bg : '#fff' ?>;
              color:<?= $cfg_ann_tipo === $key ? $tx : 'var(--gris-texto)' ?>;
              border-color:<?= $cfg_ann_tipo === $key ? $brd : 'var(--gris-borde)' ?>">
              <?= $label ?>
            </span>
          </label>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Texto -->
      <div>
        <label style="display:block;font-size:12px;font-weight:600;color:var(--gris-texto);margin-bottom:6px">
          Mensaje del anuncio
        </label>
        <textarea name="anuncio_texto" id="ann-texto" rows="2" maxlength="300"
                  placeholder="Ej: ¡Haremos algunos ajustes! Entre las 11:00 p. m. y las 11:59 p. m. estaremos en mantenimiento."
                  oninput="annPreview()" style="width:100%;padding:9px 12px;border:1.5px solid var(--gris-borde);border-radius:9px;font-size:13px;font-family:inherit;outline:none;background:var(--bg-surface2);color:var(--gris-dark);resize:vertical;transition:border-color 0.2s"
                  onfocus="this.style.borderColor='var(--azul)'" onblur="this.style.borderColor='var(--gris-borde)'"><?= htmlspecialchars($cfg_ann_texto) ?></textarea>
        <p style="font-size:11.5px;color:var(--gris-texto);margin-top:4px">Máximo 300 caracteres. Cada usuario ve el anuncio solo una vez — se persiste en la base de datos.</p>
      </div>

      <!-- Preview -->
      <div>
        <div style="font-size:12px;font-weight:600;color:var(--gris-texto);margin-bottom:7px">Vista previa</div>
        <div id="ann-preview-wrap" style="border-radius:9px;overflow:hidden;border:1px solid var(--gris-borde)">
          <div id="ann-preview" style="display:flex;align-items:center;gap:8px;padding:9px 14px;font-size:12.5px;font-weight:500;min-height:38px">
            <span id="ann-prev-icon" style="flex-shrink:0;display:flex;align-items:center"></span>
            <span id="ann-prev-text" style="flex:1"></span>
            <span style="width:24px;height:24px;border-radius:6px;background:rgba(0,0,0,0.06);display:flex;align-items:center;justify-content:center;flex-shrink:0;opacity:0.5">
              <svg width="11" height="11" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </span>
          </div>
        </div>
      </div>

      <!-- Estado del anuncio actual -->
      <?php if ($cfg_ann_texto): ?>
      <div style="background:var(--gris-bg);border:1px solid var(--gris-borde);border-radius:9px;padding:12px 14px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
        <div style="display:flex;align-items:center;gap:8px;">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="var(--gris-texto)" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
          <span style="font-size:12px;color:var(--gris-texto);">
            <strong style="color:var(--gris-dark)"><?= $cfg_ann_vistos ?></strong>
            usuario<?= $cfg_ann_vistos !== 1 ? 's' : '' ?> ya cerraron este anuncio (versión <?= htmlspecialchars($cfg_ann_version) ?>)
          </span>
        </div>
        <span style="font-size:11px;font-family:monospace;color:var(--gris-texto);background:var(--bg-surface);border:1px solid var(--gris-borde);padding:2px 8px;border-radius:6px;"
              title="Hash identificador del anuncio actual">
          #<?= substr($_ann_hash_actual, 0, 8) ?>
        </span>
      </div>
      <?php endif; ?>

      <!-- Forzar re-notificación -->
      <div style="padding:12px 14px;background:var(--s-warn-bg);border:1px solid #fde68a;border-radius:9px;display:flex;align-items:flex-start;gap:10px">
        <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;flex:1">
          <input type="checkbox" name="anuncio_force_notify" value="1" id="ann-force"
                 style="width:15px;height:15px;margin-top:2px;accent-color:var(--azul);flex-shrink:0;cursor:pointer">
          <div>
            <div style="font-size:12px;font-weight:700;color:var(--s-warn-tx);">Forzar re-notificación a todos los usuarios</div>
            <div style="font-size:11px;color:var(--s-warn-tx);margin-top:2px;">
              Al marcar esta opción, todos los usuarios verán el anuncio nuevamente, incluso quienes ya lo cerraron.
              Útil cuando el contenido del anuncio no cambia pero la información es urgente.
            </div>
          </div>
        </label>
      </div>

    </div>

    <div style="display:flex;align-items:center;gap:10px;margin-top:20px;padding-top:16px;border-top:1px solid var(--gris-borde)">
      <button type="submit" class="btn btn-primary">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
        Guardar anuncio
      </button>
      <span style="font-size:12px;color:var(--gris-texto)">Los cambios se aplican de inmediato. Usuarios nuevos siempre ven el anuncio activo.</span>
    </div>
  </div>
</form>

<!-- ══════════ COTIZACIONES ══════════ -->
<form method="POST" style="max-width:680px">
  <?= csrf_field() ?>
  <input type="hidden" name="accion_cfg" value="cotizacion">

  <!-- Sección: Cotizaciones -->
  <div class="card" style="margin-bottom:20px">
    <div style="display:flex;align-items:center;gap:10px;padding-bottom:16px;border-bottom:1px solid var(--gris-borde);margin-bottom:20px">
      <div style="width:34px;height:34px;background:var(--gris-bg);border:1px solid var(--gris-borde);border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="var(--gris-texto)" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
      </div>
      <div>
        <div style="font-family:'Inter',sans-serif;font-size:14px;font-weight:700;color:var(--gris-dark)">Propuestas y Cotizaciones</div>
        <div style="font-size:12px;color:var(--gris-texto);margin-top:1px">Parámetros globales que aparecen en el PDF de propuesta comercial</div>
      </div>
    </div>

    <div style="display:grid;gap:18px">

      <div>
        <label style="display:block;font-size:12px;font-weight:600;color:var(--gris-texto);margin-bottom:6px">
          Nombre de campaña de descuento
        </label>
        <input type="text" name="campana_descuento" maxlength="100"
               placeholder="Ej: Impulso Empresarial 2026"
               value="<?= sanitize($cfg_campana) ?>"
               style="width:100%;padding:9px 12px;border:1.5px solid var(--gris-borde);border-radius:9px;font-size:13px;font-family:inherit;outline:none;background:var(--bg-surface2);color:var(--gris-dark);transition:border-color 0.2s"
               onfocus="this.style.borderColor='var(--azul)'"
               onblur="this.style.borderColor='var(--gris-borde)'">
        <p style="font-size:11.5px;color:var(--gris-texto);margin-top:5px">
          Aparece en el badge rojo de descuento en la propuesta PDF (P4). Ejemplo: <em>Impulso Empresarial 2026</em>, <em>Campaña Aniversario</em>, etc.
          Si se deja vacío, se mostrará <em>Impulso Empresarial <?= date('Y') ?></em> por defecto.
        </p>
      </div>

    </div>
  </div>

  <div style="display:flex;align-items:center;gap:10px">
    <button type="submit" class="btn btn-primary">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
      Guardar configuración
    </button>
    <span style="font-size:12px;color:var(--gris-texto)">Los cambios se aplican a todas las propuestas generadas desde este momento.</span>
  </div>
</form>

<script>
// ── Preview del anuncio ──────────────────────────────────────────────────────
var ANN_PALETTES = {
  info:        { bg:'var(--s-info-bg)', tx:'#1E40AF', brd:'#BFDBFE', icon:'#2563EB' },
  warning:     { bg:'var(--s-warn-bg)', tx:'var(--s-warn-tx)', brd:'#FDE68A', icon:'#D97706' },
  maintenance: { bg:'var(--s-purple-bg)', tx:'#4C1D95', brd:'#DDD6FE', icon:'#7C3AED' },
  success:     { bg:'var(--s-ok-bg)', tx:'#065F46', brd:'#A7F3D0', icon:'#059669' },
};
var ANN_ICONS = {
  info:        '<svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
  warning:     '<svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
  maintenance: '<svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93l-1.41 1.41M4.93 4.93l1.41 1.41M4.93 19.07l1.41-1.41M19.07 19.07l-1.41-1.41M12 2v2M12 20v2M2 12h2M20 12h2"/></svg>',
  success:     '<svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
};

function annPreview() {
  var tipo    = document.querySelector('input[name="anuncio_tipo"]:checked')?.value || 'info';
  var texto   = document.getElementById('ann-texto')?.value || '';
  var activo  = document.getElementById('ann-toggle')?.checked;
  var p       = ANN_PALETTES[tipo] || ANN_PALETTES.info;
  var preview = document.getElementById('ann-preview');
  var prevTxt = document.getElementById('ann-prev-text');
  var prevIco = document.getElementById('ann-prev-icon');

  if (preview) {
    preview.style.background = p.bg;
    preview.style.color      = p.tx;
    document.getElementById('ann-preview-wrap').style.borderColor = p.brd;
  }
  if (prevIco) { prevIco.style.color = p.icon; prevIco.innerHTML = ANN_ICONS[tipo] || ''; }
  if (prevTxt) prevTxt.textContent = texto || 'El mensaje del anuncio aparecerá aquí…';

  // Estilo de los botones de tipo
  document.querySelectorAll('.ann-tipo-opt').forEach(function(el) {
    var k = el.dataset.key;
    var pp = ANN_PALETTES[k];
    var sel = k === tipo;
    el.style.background   = sel ? pp.bg  : '#fff';
    el.style.color        = sel ? pp.tx  : 'var(--gris-texto)';
    el.style.borderColor  = sel ? pp.brd : 'var(--gris-borde)';
  });

  // Toggle UI
  var ui = document.getElementById('ann-toggle-ui');
  if (ui) {
    ui.style.background = activo ? 'var(--azul)' : 'var(--gris-borde)';
    ui.querySelector('span').style.transform = activo ? 'translateX(18px)' : 'translateX(0)';
  }
}

// Inicializar preview al cargar
ggiReady(annPreview);
</script>

<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
