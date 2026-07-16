<?php
// $ranking_asesores must be set by the including file
if (empty($ranking_asesores)) return;
$_fotos_base = defined('APP_URL') ? APP_URL . '/assets/usuarios/fotos/' : '';
$_fotos_dir  = __DIR__ . '/../../../assets/usuarios/fotos/';
$meses_es_th = ['','Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
?>
<div class="panel" style="margin-bottom:20px;animation-delay:0.4s;overflow:visible;">
  <div class="panel-header">
    <div class="panel-title">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
      Tabla de Honor — <?= $meses_es_th[(int)date('n')] ?> <?= date('Y') ?>
    </div>
  </div>
  <div class="ggi-scroll-x" style="border-radius:0 0 12px 12px;">
    <table class="mini-table">
      <thead>
        <tr>
          <th scope="col" style="width:36px;">#</th>
          <th scope="col">Asesor</th>
          <th scope="col" style="text-align:center;">Propuestas</th>
          <th scope="col" style="text-align:center;">Ganadas</th>
          <th scope="col" style="text-align:center;">Pagadas</th>
          <th scope="col" style="text-align:center;">Pendientes</th>
          <th scope="col" style="text-align:right;">Monto cerrado</th>
          <th scope="col" style="text-align:right;">Bonos del mes</th>
          <th scope="col" style="width:90px;">Progreso</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $max_monto = max(array_column($ranking_asesores, 'monto_total') ?: [1]);
        $medallas  = ["\u{1F947}", "\u{1F948}", "\u{1F949}"];
        foreach ($ranking_asesores as $_i => $_a):
            $_pct   = $max_monto > 0 ? max(2, round(($_a['monto_total'] / $max_monto) * 100)) : 2;
            $_color = $_i === 0 ? '#f59e0b' : ($_i === 1 ? '#94a3b8' : ($_i === 2 ? 'var(--s-warn-tx)' : 'var(--azul)'));
            $_foto_url = null;
            if (!empty($_a['foto'])) {
                $_foto_path = $_fotos_dir . basename($_a['foto']);
                if (file_exists($_foto_path)) {
                    $_foto_url = $_fotos_base . htmlspecialchars(basename($_a['foto']));
                }
            }
        ?>
        <tr>
          <td style="text-align:center;font-size:16px;line-height:1;">
            <?= isset($medallas[$_i]) ? $medallas[$_i] : ($_i + 1) ?>
          </td>
          <td>
            <div style="display:flex;align-items:center;gap:9px;">
              <?php if ($_foto_url): ?>
              <img src="<?= $_foto_url ?>" alt="" style="width:32px;height:32px;border-radius:50%;object-fit:cover;flex-shrink:0;border:2px solid <?= $_color ?>;">
              <?php else: ?>
              <div style="width:32px;height:32px;border-radius:50%;background:<?= $_color ?>;color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0;">
                <?= strtoupper(substr($_a['nombre'],0,1).substr($_a['apellido'],0,1)) ?>
              </div>
              <?php endif; ?>
              <span style="font-weight:600;color:var(--gris-dark);"><?= sanitize($_a['nombre'].' '.$_a['apellido']) ?></span>
            </div>
          </td>
          <td style="text-align:center;">
            <span style="background:var(--gris-bg);color:var(--gris-texto);padding:2px 10px;border-radius:100px;font-size:12px;font-weight:700;">
              <?= $_a['elaboradas'] ?? 0 ?>
            </span>
          </td>
          <td style="text-align:center;">
            <span style="background:var(--s-ok-bg);color:#059669;padding:2px 10px;border-radius:100px;font-size:12px;font-weight:700;">
              <?= $_a['propuestas'] ?>
            </span>
          </td>
          <td style="text-align:center;">
            <span style="background:var(--s-info-bg);color:var(--s-info-tx);padding:2px 10px;border-radius:100px;font-size:12px;font-weight:700;">
              <?= $_a['pagadas'] ?? 0 ?>
            </span>
          </td>
          <td style="text-align:center;">
            <span style="background:var(--s-warn-bg);color:var(--s-warn-tx);padding:2px 10px;border-radius:100px;font-size:12px;font-weight:700;">
              <?= $_a['pendientes'] ?? 0 ?>
            </span>
          </td>
          <td style="text-align:right;font-weight:700;color:var(--gris-dark);font-family:'Inter',sans-serif;">
            S/ <?= number_format($_a['monto_total'], 0) ?>
          </td>
          <td style="text-align:right;font-weight:600;color:#229133;">
            <?= $_a['bonos_mes'] > 0 ? 'S/ '.number_format($_a['bonos_mes'], 2) : '—' ?>
          </td>
          <td>
            <div style="background:var(--gris-bg);border-radius:6px;height:8px;overflow:hidden;">
              <div style="height:100%;width:<?= $_pct ?>%;background:<?= $_color ?>;border-radius:6px;transition:width .6s;"></div>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
