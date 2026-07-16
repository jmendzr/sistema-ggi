<?php
// partials/cumpleanos_equipo.php
// Banner de cumpleaños de hoy + panel "Cumpleaños del equipo" (próximos).
// Compartido por TODOS los roles del dashboard. Requiere $cumpleanos_proximos.
if (empty($cumpleanos_proximos)) return;

$_cumple_hoy = array_filter($cumpleanos_proximos, fn($c) => (int)$c['dias_para_cumple'] === 0);

$_fotos_base_cp = APP_URL . '/assets/usuarios/fotos/';
$_fotos_dir_cp  = __DIR__ . '/../../../assets/usuarios/fotos/';
$meses_cp = ['','Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
?>

<?php if (!empty($_cumple_hoy)): ?>
<div class="cumple-banner">
  <div class="cumple-banner-icon">🎂</div>
  <div class="cumple-banner-text">
    <div class="cumple-banner-title">¡Hoy es el cumpleaños de <?= count($_cumple_hoy) === 1 ? 'un compañero' : 'varios compañeros' ?>!</div>
    <div class="cumple-banner-names">
      <?php echo implode(', ', array_map(fn($c) => sanitize($c['nombre'].' '.$c['apellido']), $_cumple_hoy)); ?>
      — No olvides saludar 🎉
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ── CUMPLEAÑOS DEL EQUIPO ── -->
<div class="panel" style="animation-delay:0.4s;">
  <div class="panel-header">
    <div class="panel-title">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M20 7H4a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2z"/><path d="M16 3v4M8 3v4M3 13h18"/><path d="M8 17h.01M12 17h.01M16 17h.01"/></svg>
      Cumpleaños del equipo
    </div>
    <span style="font-size:11px;color:var(--gris-texto);">Equipo completo</span>
  </div>
  <?php foreach ($cumpleanos_proximos as $_cp):
    $_cp_foto_url = null;
    if (!empty($_cp['foto'])) {
        $_cp_foto_path = $_fotos_dir_cp . basename($_cp['foto']);
        if (file_exists($_cp_foto_path)) {
            $_cp_foto_url = $_fotos_base_cp . htmlspecialchars(basename($_cp['foto']));
        }
    }
    $_cp_es_hoy = (int)$_cp['dias_para_cumple'] === 0;
    $_cp_fecha_txt = $meses_cp[(int)$_cp['mes']] . ' ' . (int)$_cp['dia'];
  ?>
  <div class="cumple-row">
    <?php if ($_cp_foto_url): ?>
    <img src="<?= $_cp_foto_url ?>" class="cumple-avatar" alt="" style="border:2px solid <?= $_cp_es_hoy ? '#f59e0b' : 'var(--azul)' ?>;">
    <?php else: ?>
    <div class="cumple-avatar-init" style="background:<?= $_cp_es_hoy ? '#f59e0b' : 'var(--azul)' ?>;"><?= strtoupper(substr($_cp['nombre'],0,1).substr($_cp['apellido'],0,1)) ?></div>
    <?php endif; ?>
    <div style="flex:1;min-width:0;">
      <div style="font-weight:600;color:var(--gris-dark);font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= sanitize($_cp['nombre'].' '.$_cp['apellido']) ?></div>
      <div style="font-size:11px;color:var(--gris-texto);margin-top:1px;">
        <?= $_cp_fecha_txt ?>
        <?php if (!empty($_cp['edad_actual']) && (int)$_cp['edad_actual'] > 0): ?>
          · <?= $_cp_es_hoy ? 'Cumple '.((int)$_cp['edad_actual'] + 1).' años' : (int)$_cp['edad_actual'].' años' ?>
        <?php endif; ?>
        · <?= sanitize(ucwords(str_replace('_',' ', $_cp['rol']))) ?>
      </div>
    </div>
    <?php
      $_dias = (int)$_cp['dias_para_cumple'];
      if ($_cp_es_hoy)          $_dias_txt = '🎂 ¡Hoy!';
      elseif ($_dias === 1)     $_dias_txt = 'Mañana';
      elseif ($_dias <= 30)     $_dias_txt = 'En '.$_dias.'d';
      elseif ($_dias <= 60)     $_dias_txt = 'En ~1 mes';
      else                      $_dias_txt = 'En '.round($_dias/30).' meses';
    ?>
    <div class="cumple-dias <?= $_cp_es_hoy ? 'cumple-hoy' : ($_dias <= 30 ? 'cumple-pronto' : '') ?>"
         style="<?= (!$_cp_es_hoy && $_dias > 30) ? 'background:var(--s-info-bg);color:#64748b;border:1px solid #e2e8f0;' : '' ?>">
      <?= $_dias_txt ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>
