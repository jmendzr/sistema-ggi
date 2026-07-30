<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();
$user = auth_user();
require_role('gerente_general','gerente_operaciones');

$_ggi_embed = !empty($_GET['embed']) || !empty($_POST['_embed']);

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . APP_URL . '/modules/clientes/index.php'); exit; }

$pdo  = Database::get();
$stmt = $pdo->prepare("
    SELECT id, ruc, razon_social, direccion, departamento, provincia, distrito, ubigeo,
           contacto_nombre, contacto_apellido, contacto_telefono, contacto_email, contacto_cargo,
           num_trabajadores,
           fecha_contrato, estado, notas, ejecutivo_id,
           created_by_user_id, responsible_user_id,
           portal_token, portal_activo, creado_por
    FROM clientes WHERE id=?
");
$stmt->execute([$id]);
$cliente = $stmt->fetch();
if (!$cliente) { header('Location: ' . APP_URL . '/modules/clientes/index.php'); exit; }

$errors = [];
$data   = $cliente;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $data = [
        'ruc'               => trim($_POST['ruc'] ?? ''),
        'razon_social'      => trim($_POST['razon_social'] ?? ''),
        'direccion'         => trim($_POST['direccion'] ?? ''),
        'departamento'      => trim($_POST['departamento'] ?? ''),
        'provincia'         => trim($_POST['provincia'] ?? ''),
        'distrito'          => trim($_POST['distrito'] ?? ''),
        'ubigeo'            => trim($_POST['ubigeo'] ?? ''),
        'contacto_nombre'   => trim($_POST['contacto_nombre'] ?? ''),
        'contacto_apellido' => trim($_POST['contacto_apellido'] ?? ''),
        'contacto_telefono' => trim($_POST['contacto_telefono'] ?? ''),
        'horario_contacto'  => mb_substr(trim($_POST['horario_contacto'] ?? ''), 0, 120),
        'contacto_email'    => trim($_POST['contacto_email'] ?? ''),
        'contacto_cargo'    => trim($_POST['contacto_cargo'] ?? ''),
        'num_trabajadores'  => !empty($_POST['num_trabajadores']) ? (int)$_POST['num_trabajadores'] : null,
        'fecha_contrato'    => $_POST['fecha_contrato'] ?? '',
        'estado'            => $_POST['estado'] ?? 'activo',
        'notas'             => trim($_POST['notas'] ?? ''),
        'ejecutivo_id'         => ($user['rol'] === 'ejecutivo_ventas')
                                      ? ($cliente['ejecutivo_id'] ?? $user['id'])
                                      : ((int)($_POST['ejecutivo_id'] ?? 0) ?: null),
        // responsible_user_id: solo gerentes pueden reasignar; ejecutivos no lo cambian
        'responsible_user_id'  => has_role('gerente_general', 'gerente_operaciones')
                                      ? ((int)($_POST['responsible_user_id'] ?? 0) ?: ($cliente['responsible_user_id'] ?? null))
                                      : ($cliente['responsible_user_id'] ?? null),
    ];

    if (empty($data['ruc']) || !ruc_valido($data['ruc']))
        $errors['ruc'] = 'RUC inválido (debe tener 11 dígitos y pasar la validación del dígito verificador).';
    if (empty($data['razon_social']))
        $errors['razon_social'] = 'La razón social es requerida.';
    // Celular obligatorio — formato móvil Perú (9 dígitos, inicia en 9). Se normaliza y guarda limpio.
    $tel_digits = preg_replace('/\D/', '', $data['contacto_telefono']);
    if (strlen($tel_digits) === 11 && str_starts_with($tel_digits, '51')) {
        $tel_digits = substr($tel_digits, 2);
    }
    if ($tel_digits === '') {
        $errors['contacto_telefono'] = 'El celular del contacto es requerido.';
    } elseif (!preg_match('/^9\d{8}$/', $tel_digits)) {
        $errors['contacto_telefono'] = 'Ingresa un celular válido (9 dígitos, empieza en 9).';
    } else {
        $data['contacto_telefono'] = $tel_digits;
    }
    if (empty($errors['ruc'])) {
        $ex = $pdo->prepare("SELECT id FROM clientes WHERE ruc=? AND id!=?");
        $ex->execute([$data['ruc'], $id]);
        if ($ex->fetch()) $errors['ruc'] = 'Ya existe otro cliente con este RUC.';
    }

    if (empty($errors)) {
        $pdo->prepare("
            UPDATE clientes SET
              ruc=?, razon_social=?, direccion=?,
              departamento=?, provincia=?, distrito=?, ubigeo=?,
              contacto_nombre=?, contacto_apellido=?,
              contacto_telefono=?, horario_contacto=?, contacto_email=?, contacto_cargo=?,
              num_trabajadores=?,
              fecha_contrato=?, estado=?, notas=?
            WHERE id=?
        ")->execute([
            $data['ruc'], $data['razon_social'], $data['direccion'] ?: null,
            $data['departamento'] ?: null, $data['provincia'] ?: null, $data['distrito'] ?: null, $data['ubigeo'] ?: null,
            $data['contacto_nombre'], $data['contacto_apellido'],
            $data['contacto_telefono'], $data['horario_contacto'] ?: null, $data['contacto_email'], $data['contacto_cargo'],
            $data['num_trabajadores'],
            $data['fecha_contrato'] ?: null, $data['estado'], $data['notas'],
            $id,
        ]);
        try {
            $pdo->prepare("UPDATE clientes SET ejecutivo_id=? WHERE id=?")
                ->execute([$data['ejecutivo_id'], $id]);
        } catch (Exception $e) {}

        // Actualizar responsible_user_id y registrar historial si cambió
        $nuevo_resp = $data['responsible_user_id'];
        $resp_anterior = (int)($cliente['responsible_user_id'] ?? 0);
        if ($nuevo_resp !== null && (int)$nuevo_resp !== $resp_anterior) {
            try {
                $pdo->prepare("UPDATE clientes SET responsible_user_id=? WHERE id=?")
                    ->execute([$nuevo_resp ?: null, $id]);
                $pdo->prepare("
                    INSERT INTO historial_responsables_cliente
                      (cliente_id, responsable_anterior, responsable_nuevo, motivo, registrado_por, notas)
                    VALUES (?, ?, ?, 'reasignacion_manual', ?, ?)
                ")->execute([
                    $id,
                    $resp_anterior ?: null,
                    $nuevo_resp    ?: null,
                    $user['id'],
                    'Reasignación manual desde formulario de edición',
                ]);
                log_actividad('clientes', 'reasignacion',
                    "Responsable de empresa #$id cambió de #$resp_anterior a #$nuevo_resp");
            } catch (Exception $e) {}
        }

        log_actividad('clientes','editar','Cliente actualizado: '.$data['razon_social']);

        // En modo modal (embed): avisar al padre para cerrar y refrescar la vista actual.
        if ($_ggi_embed) {
            ?><!DOCTYPE html><html><body><script>
            window.parent.postMessage({
                ggi:    'created',
                mode:   'edit',
                module: 'clientes',
                id:     <?= (int)$id ?>
            }, '*');
            </script></body></html><?php
            exit;
        }

        flash('Cambios guardados exitosamente.');
        header('Location: '.APP_URL.'/modules/clientes/ver.php?id='.$id);
        exit;
    }
}

$page_title  = 'Editar Empresa';
$active_menu = 'clientes';
$from        = $_GET['from'] ?? 'ficha';
$cancel_url  = ($from === 'listado')
    ? APP_URL.'/modules/clientes/index.php'
    : APP_URL.'/modules/clientes/ver.php?id='.$id;

$ejecutivos = $pdo->query("SELECT id, nombre, apellido FROM usuarios WHERE activo=1 AND rol='ejecutivo_ventas' ORDER BY nombre")->fetchAll();

// Portal state
$token_actual  = $cliente['portal_token'] ?? null;
$portal_activo = (bool)($cliente['portal_activo'] ?? false);
$portal_url    = ($token_actual && $portal_activo) ? APP_URL.'/portal/?token='.$token_actual : null;

// Default tab — switch to portal tab if redirected from portal_toggle (non-AJAX) or ?tab=portal
$active_tab = 'datos'; // el tab "Portal" se movió al detalle de la empresa

require_once __DIR__ . '/../../templates/layout.php';
?>
<style>
  /* ── Tabs ── */
  .edit-tabs { display:flex; gap:0; border-bottom:2px solid var(--gris-borde); margin-bottom:22px; }
  .edit-tab-btn {
    background:none; border:none; border-bottom:3px solid transparent; margin-bottom:-2px;
    padding:11px 20px; font-size:13px; font-weight:600; color:var(--gris-texto);
    cursor:pointer; transition:all 0.15s; font-family:'Inter',sans-serif;
    display:flex; align-items:center; gap:7px; white-space:nowrap;
  }
  .edit-tab-btn.active { color:var(--azul); border-bottom-color:var(--azul); }
  .edit-tab-btn:hover:not(.active) { color:var(--gris-dark); background:var(--bg-surface2); }
  .tab-pane { display:none; }
  .tab-pane.active { display:block; }

  /* ── Form ── */
  .form-card { background:var(--bg-surface); border-radius:12px; border:1px solid var(--gris-borde); max-width:860px; }
  .form-section { padding:22px 28px; border-bottom:1px solid var(--gris-borde); }
  .form-section:last-of-type { border-bottom:none; }
  .section-title { font-size:13px; font-weight:700; color:var(--azul); margin-bottom:16px; display:flex; align-items:center; gap:8px; }
  .fields-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
  .span-2 { grid-column:1/-1; }
  .field { display:flex; flex-direction:column; gap:5px; }
  .field label { font-size:12px; font-weight:600; color:var(--azul); }
  .field input, .field select, .field textarea {
    padding:10px 13px; border:1.5px solid var(--gris-borde); border-radius:9px;
    font-size:13px; font-family:'Inter',sans-serif;
    background:var(--bg-surface2); outline:none; width:100%; transition:border-color .2s;
  }
  .field input:focus, .field select:focus, .field textarea:focus { border-color:var(--azul); background:var(--bg-surface); }
  .field input.error { border-color:#ef4444; }
  .err-msg { font-size:11px; color:#ef4444; margin-top:2px; }
  .field textarea { resize:vertical; min-height:80px; }
  .ruc-wrap { display:flex; gap:8px; }
  .ruc-wrap input { flex:1; min-width:0; }
  .btn-autocompletar {
    flex-shrink:0; padding:10px 14px; background:var(--azul); color:#fff;
    border:none; border-radius:9px; font-size:13px; font-weight:600;
    cursor:pointer; display:flex; align-items:center; gap:6px;
    white-space:nowrap; transition:background .15s;
  }
  .btn-autocompletar:hover { background:#122870; }
  .btn-autocompletar:disabled { opacity:.6; cursor:not-allowed; }
  .ruc-status { font-size:11px; margin-top:3px; min-height:15px; }
  .ruc-status.ok { color:#229133; }
  .ruc-status.err { color:#ef4444; }
  .ruc-status.loading { color:var(--gris-texto); }
  .form-actions { padding:16px 28px; background:var(--bg-surface2); border-top:1px solid var(--gris-borde); display:flex; gap:10px; flex-wrap:wrap; }
  /* Jerarquía de la barra de acciones (escritorio y móvil) */
  .form-actions.ff-actions { align-items:center; }
  .form-actions.ff-actions .ff-save   { flex:0 0 auto; }
  .form-actions.ff-actions .ff-cancel { flex:0 0 auto; }
  .form-actions.ff-actions .ff-delete { flex:0 0 auto; margin-left:auto;
    background:transparent; color:var(--s-err); border:1.5px solid var(--s-err-brd,#fecaca); font-weight:600; }
  .form-actions.ff-actions .ff-delete:hover { background:var(--s-err-bg,#fef2f2); }
  @media (max-width:768px){
    /* Una sola fila compacta: Guardar (primario, crece) · Cancelar · Eliminar */
    .form-actions.ff-actions { gap:8px; flex-wrap:nowrap; }
    .form-actions.ff-actions .ff-save   { flex:1 1 auto; order:1; min-height:44px; font-weight:700; white-space:nowrap; }
    .form-actions.ff-actions .ff-cancel { flex:0 0 auto; order:2; min-height:44px; margin:0; white-space:nowrap; padding-left:14px; padding-right:14px; }
    .form-actions.ff-actions .ff-delete { flex:0 0 auto; order:3; min-height:44px; margin-left:0; white-space:nowrap; padding-left:14px; padding-right:14px; }
  }
  .select-wrap select { width:100%; padding:10px 36px 10px 13px; border:1.5px solid var(--gris-borde); border-radius:9px;
    font-size:13px; font-family:'Inter',sans-serif; background-color:var(--bg-surface2); outline:none;
    cursor:pointer; transition:border-color .2s; color:var(--gris-dark); }
  .select-wrap select:focus { border-color:var(--azul); background-color:var(--bg-surface); }

  /* ── Portal tab ── */
  .portal-card { background:var(--bg-surface); border-radius:12px; border:1px solid var(--gris-borde); max-width:860px; padding:28px; }
  .portal-desc { font-size:13px; color:var(--gris-texto); line-height:1.6; margin-bottom:20px; }
  .portal-status-banner {
    border-radius:10px; padding:16px 18px; margin-bottom:16px; display:flex; align-items:flex-start; gap:14px;
  }
  .portal-status-banner.active  { background:var(--s-ok-bg); border:1px solid var(--s-ok-brd); }
  .portal-status-banner.inactive{ background:var(--bg-surface2); border:1px solid var(--gris-borde); }
  .portal-status-icon { width:36px; height:36px; border-radius:9px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
  .portal-status-icon.active  { background:var(--s-ok-bg); color:var(--s-ok-tx); }
  .portal-status-icon.inactive{ background:var(--s-info-bg); color:#94a3b8; }
  .portal-status-label { font-size:13px; font-weight:700; margin-bottom:4px; }
  .portal-status-label.active  { color:var(--s-ok-tx); }
  .portal-status-label.inactive{ color:#64748b; }
  .portal-url-row { display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-top:8px; }
  .portal-url-input {
    flex:1; min-width:0; padding:8px 11px; border:1.5px solid var(--s-ok-brd); border-radius:8px;
    font-size:12px; font-family:monospace; background:var(--bg-surface); color:var(--s-ok-tx); outline:none;
  }
  .portal-actions { display:flex; gap:8px; flex-wrap:wrap; margin-top:14px; }
  .portal-btn-spin { opacity:.5; pointer-events:none; }

  @media (max-width:640px) {
    .form-section { padding:16px; }
    .fields-grid { grid-template-columns:1fr; }
    .span-2 { grid-column:1; }
    .ruc-wrap { flex-direction:column; }
    .portal-card { padding:18px 16px; }
    .portal-url-row { flex-direction:column; align-items:stretch; }
    .portal-url-input { width:100%; }
    .edit-tab-btn { padding:10px 14px; font-size:12px; }
  }
</style>

<?php if (!$_ggi_embed): ?>
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
  <div>
    <h1>Editar empresa</h1>
    <p><?= sanitize($cliente['razon_social']) ?></p>
  </div>
  <div style="display:flex;gap:8px;">
    <a href="<?= APP_URL ?>/modules/clientes/ver.php?id=<?= $id ?>" class="btn btn-outline btn-sm">← Ver ficha</a>
    <a href="<?= APP_URL ?>/modules/clientes/index.php" class="btn btn-outline btn-sm">Listado</a>
  </div>
</div>
<?php endif; ?>

<!-- Formulario de datos de la empresa (el Portal se gestiona en la pestaña "Portal" del detalle) -->
<div class="tab-pane active" id="tab-pane-datos" role="tabpanel">
  <form method="POST" id="form-editar-cliente" novalidate>
  <?= csrf_field() ?>
  <?php if ($_ggi_embed): ?><input type="hidden" name="_embed" value="1"><?php endif; ?>
  <div class="form-card">

    <!-- Datos SUNAT -->
    <div class="form-section">
      <div class="section-title">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
        Datos SUNAT
      </div>
      <div class="fields-grid">
        <div class="field">
          <label>RUC <span class="req">*</span></label>
          <div class="ruc-wrap">
            <input type="text" id="ruc" name="ruc" maxlength="11" inputmode="numeric"
                   value="<?= sanitize($data['ruc']) ?>"
                   class="<?= isset($errors['ruc'])?'error':'' ?>"
                   oninput="this.value=this.value.replace(/\D/g,'')">
            <button type="button" class="btn-autocompletar" id="btn-ruc" onclick="buscarRuc()">
              <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              Autocompletar
            </button>
          </div>
          <div class="ruc-status" id="ruc-status"></div>
          <?php if (isset($errors['ruc'])): ?><div class="err-msg"><?= $errors['ruc'] ?></div><?php endif; ?>
        </div>
        <div class="field">
          <label>Razón social <span class="req">*</span></label>
          <input type="text" id="razon_social" name="razon_social"
                 value="<?= sanitize($data['razon_social']) ?>"
                 class="<?= isset($errors['razon_social'])?'error':'' ?>">
          <?php if (isset($errors['razon_social'])): ?><div class="err-msg"><?= $errors['razon_social'] ?></div><?php endif; ?>
        </div>
        <div class="field span-2">
          <label>Dirección fiscal</label>
          <input type="text" id="direccion" name="direccion" value="<?= sanitize($data['direccion'] ?? '') ?>">
        </div>
        <div class="field">
          <label>N.º trabajadores <span style="font-size:10px;color:var(--verde);font-weight:500;">● Auto</span></label>
          <input type="number" id="num_trabajadores" name="num_trabajadores" min="0"
                 placeholder="Se completa al consultar RUC"
                 value="<?= (int)($data['num_trabajadores'] ?? 0) ?: '' ?>">
        </div>
      </div>
      <input type="hidden" id="departamento" name="departamento" value="<?= sanitize($data['departamento'] ?? '') ?>">
      <input type="hidden" id="provincia"    name="provincia"    value="<?= sanitize($data['provincia'] ?? '') ?>">
      <input type="hidden" id="distrito"     name="distrito"     value="<?= sanitize($data['distrito'] ?? '') ?>">
      <input type="hidden" id="ubigeo"       name="ubigeo"       value="<?= sanitize($data['ubigeo'] ?? '') ?>">
    </div>

    <!-- Representante legal -->
    <div class="form-section">
      <div class="section-title">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        Representante legal
        <span style="margin-left:auto;font-size:10px;font-weight:500;color:var(--gris-texto);background:var(--azul-light);padding:2px 8px;border-radius:100px;display:flex;align-items:center;gap:4px;">
          <svg width="10" height="10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          Se autocompleta al consultar RUC
        </span>
      </div>
      <div class="fields-grid">
        <div class="field">
          <label>Nombre <span style="font-size:10px;color:var(--verde);font-weight:500;">● Auto</span></label>
          <input type="text" name="contacto_nombre" value="<?= sanitize($data['contacto_nombre'] ?? '') ?>">
        </div>
        <div class="field">
          <label>Apellido <span style="font-size:10px;color:var(--verde);font-weight:500;">● Auto</span></label>
          <input type="text" name="contacto_apellido" value="<?= sanitize($data['contacto_apellido'] ?? '') ?>">
        </div>
        <div class="field span-2">
          <label>Cargo <span style="font-size:10px;color:var(--verde);font-weight:500;">● Auto</span></label>
          <input type="text" name="contacto_cargo" value="<?= sanitize($data['contacto_cargo'] ?? '') ?>">
        </div>
        <div class="field">
          <label>Email</label>
          <input type="email" name="contacto_email" value="<?= sanitize($data['contacto_email'] ?? '') ?>">
        </div>
        <div class="field">
          <label>Celular <span class="req">*</span></label>
          <input type="tel" name="contacto_telefono" inputmode="numeric" autocomplete="off"
                 class="<?= isset($errors['contacto_telefono'])?'error':'' ?>"
                 placeholder="Ej: 999888777" value="<?= sanitize($data['contacto_telefono'] ?? '') ?>">
          <?php if (isset($errors['contacto_telefono'])): ?><div class="err-msg"><?= $errors['contacto_telefono'] ?></div><?php endif; ?>
        </div>
        <div class="field">
          <label>Horario de contacto <span style="font-size:10px;color:var(--gris-texto);font-weight:400;">(preferencia del cliente)</span></label>
          <input type="text" name="horario_contacto" maxlength="120" autocomplete="off"
                 placeholder="Ej: No llamar después de las 5 PM"
                 value="<?= sanitize($data['horario_contacto'] ?? '') ?>">
        </div>
      </div>
    </div>

    <!-- Información comercial -->
    <div class="form-section">
      <div class="section-title">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        Información comercial
      </div>
      <div class="fields-grid">
        <div class="field">
          <label>Fecha de contrato</label>
          <input type="date" name="fecha_contrato" value="<?= sanitize($data['fecha_contrato'] ?? '') ?>">
        </div>
        <div class="field">
          <label>Estado</label>
          <select name="estado">
            <option value="activo"    <?= ($data['estado']??'')==='activo'    ?'selected':'' ?>>Activo</option>
            <option value="prospecto" <?= ($data['estado']??'')==='prospecto' ?'selected':'' ?>>Prospecto</option>
            <option value="inactivo"  <?= ($data['estado']??'')==='inactivo'  ?'selected':'' ?>>Inactivo</option>
          </select>
        </div>
        <div class="field">
          <label>Ejecutivo captador <span style="font-size:10px;color:var(--gris-texto);font-weight:400;">(original, no editable)</span></label>
          <?php $ej = array_filter($ejecutivos, fn($e) => $e['id'] == $data['ejecutivo_id']); $ej = reset($ej); ?>
          <input type="hidden" name="ejecutivo_id" value="<?= $data['ejecutivo_id'] ?>">
          <input type="text" value="<?= $ej ? sanitize($ej['nombre'].' '.$ej['apellido']) : '— Sin asignar —' ?>" disabled
                 style="background:var(--gris-bg);color:var(--gris-texto);cursor:not-allowed;">
        </div>
        <div class="field">
          <label>Ejecutivo responsable actual</label>
          <?php if (has_role('gerente_general','gerente_operaciones')): ?>
            <div class="select-wrap">
              <select name="responsible_user_id" id="responsible_user_id">
                <option value="">— Sin asignar —</option>
                <?php foreach ($ejecutivos as $ej): ?>
                <option value="<?= $ej['id'] ?>" <?= ($data['responsible_user_id']==$ej['id'])?'selected':'' ?>>
                  <?= sanitize($ej['nombre'].' '.$ej['apellido']) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php else: ?>
            <?php $resp = array_filter($ejecutivos, fn($e) => $e['id'] == ($data['responsible_user_id'] ?? 0)); $resp = reset($resp); ?>
            <input type="hidden" name="responsible_user_id" value="<?= $data['responsible_user_id'] ?? '' ?>">
            <input type="text" value="<?= $resp ? sanitize($resp['nombre'].' '.$resp['apellido']) : '— Sin asignar —' ?>" disabled
                   style="background:var(--gris-bg);color:var(--gris-texto);cursor:not-allowed;">
          <?php endif; ?>
        </div>
        <div class="field span-2">
          <label>Notas internas</label>
          <textarea name="notas"><?= sanitize($data['notas'] ?? '') ?></textarea>
        </div>
      </div>
    </div>

    <!-- Acciones: Guardar (primario prominente) · Cancelar (secundario) ·
         Eliminar (destructivo, separado y de-enfatizado) -->
    <div class="form-actions ff-actions">
      <button type="submit" class="btn btn-primary ff-save">Guardar cambios</button>
      <?php if ($_ggi_embed): ?>
      <button type="button" class="btn btn-outline ff-cancel" onclick="window.parent.postMessage({ggi:'close'}, '*')">Cancelar</button>
      <?php else: ?>
      <a href="<?= $cancel_url ?>" class="btn btn-outline ff-cancel">Cancelar</a>
      <?php endif; ?>
      <?php if (has_role('gerente_general','gerente_operaciones')): ?>
      <button type="button" class="btn ff-delete" title="Eliminar empresa" onclick="confirmarEliminar()">Eliminar</button>
      <?php endif; ?>
    </div>

  </div><!-- /form-card -->
  </form>
</div><!-- /tab-pane-datos -->


<script>
// ── Tab switching ──
function switchEditTab(tab) {
  ['datos'].forEach(t => {
    var pane = document.getElementById('tab-pane-' + t);
    var btn  = document.getElementById('tab-btn-' + t);
    if (pane) pane.classList.toggle('active', t === tab);
    if (btn)  { btn.classList.toggle('active', t === tab); btn.setAttribute('aria-selected', t === tab ? 'true' : 'false'); }
  });
  // Update URL without reload
  const url = new URL(location.href);
  url.searchParams.set('tab', tab);
  history.replaceState({}, '', url);
}

// ── RUC autocompletar ──
function copiarPortal() {
  const inp = document.getElementById('portal-url-input');
  if (!inp) return;
  inp.select();
  document.execCommand('copy');
  if (typeof showToast === 'function') showToast('Enlace copiado.', 'ok');
}

async function buscarRuc() {
  const ruc = document.getElementById('ruc').value.trim();
  const btn = document.getElementById('btn-ruc');
  const st  = document.getElementById('ruc-status');
  if (ruc.length !== 11) { st.className='ruc-status err'; st.textContent='El RUC debe tener 11 dígitos.'; return; }
  btn.disabled = true;
  st.className = 'ruc-status loading'; st.textContent = 'Consultando SUNAT...';
  try {
    const r = await fetch('<?= APP_URL ?>/modules/clientes/ajax_ruc.php?ruc='+ruc);
    const j = await r.json();
    if (j.ok) {
      const d = j.data;
      ['razon_social','direccion','departamento','provincia','distrito'].forEach(f => {
        const el = document.getElementById(f); if (el) el.value = d[f] || '';
      });
      const ubigeoEl = document.getElementById('ubigeo');
      if (ubigeoEl) ubigeoEl.value = Array.isArray(d.ubigeo) ? (d.ubigeo[d.ubigeo.length-1]||'') : (d.ubigeo||'');
      const setIfEmpty = (name, val) => {
        const el = document.querySelector(`[name="${name}"]`);
        if (el && val) el.value = val;
      };
      setIfEmpty('contacto_nombre',   d.contacto_nombre);
      setIfEmpty('contacto_apellido', d.contacto_apellido);
      setIfEmpty('contacto_cargo',    d.contacto_cargo);
      const ntEl = document.getElementById('num_trabajadores');
      if (ntEl && d.num_trabajadores != null) ntEl.value = d.num_trabajadores;
      st.className='ruc-status ok'; st.textContent='✓ '+d.razon_social;
    } else {
      st.className='ruc-status err'; st.textContent='✗ '+(j.mensaje||'No encontrado');
    }
  } catch(e) { st.className='ruc-status err'; st.textContent='✗ Error de conexión.'; }
  finally { btn.disabled = false; }
}

ggiReady(() => {
  const rucEl = document.getElementById('ruc');
  if (rucEl) rucEl.addEventListener('keydown', e => {
    if (e.key === 'Enter') { e.preventDefault(); buscarRuc(); }
  });
});

// ── Portal AJAX actions ──
function portalAction(accion) {
  const configs = {
    desactivar: {
      icon: 'warning',
      title: 'Desactivar portal',
      message: '¿Desactivar el portal? El enlace dejará de funcionar.',
      confirmText: 'Desactivar'
    },
    regenerar: {
      icon: 'warning',
      title: 'Regenerar enlace',
      message: '¿Regenerar el enlace? El enlace anterior dejará de funcionar.',
      confirmText: 'Regenerar'
    }
  };

  const config = configs[accion];
  if (!config) return;

  GGIConfirm.show({
    ...config,
    onConfirm: () => executePortalAction(accion)
  });
}

async function executePortalAction(accion) {
  // Spinner visual
  const area = document.getElementById('portal-status-area');
  const btns = area ? area.querySelectorAll('button') : [];
  btns.forEach(b => b.classList.add('portal-btn-spin'));

  try {
    // ggiPost adjunta el _csrf vigente y absorbe su rotación
    const data = await ggiPost('<?= APP_URL ?>/modules/clientes/portal_toggle.php',
                               { cliente_id: '<?= $id ?>', accion: accion }, { silent: true });

    if (data.ok) {
      // Re-render portal section
      renderPortalArea(data);
      // Update tab button badge
      updatePortalTabBtn(!!data.portal_activo);
      if (typeof showToast === 'function') showToast(data.msg, 'ok');
    } else {
      if (typeof showToast === 'function') showToast(data.msg || 'Error al procesar.', 'error');
      btns.forEach(b => b.classList.remove('portal-btn-spin'));
    }
  } catch(e) {
    if (typeof showToast === 'function') showToast('Error de conexión.', 'error');
    btns.forEach(b => b.classList.remove('portal-btn-spin'));
  }
}

function updatePortalTabBtn(activo) {
  const btn = document.getElementById('tab-btn-portal');
  if (!btn) return;
  // Remove existing badge
  const old = btn.querySelector('span');
  if (old) old.remove();
  if (activo) {
    const badge = document.createElement('span');
    badge.style.cssText = 'background:var(--s-ok-bg);color:var(--s-ok-tx);font-size:10px;font-weight:700;padding:2px 7px;border-radius:100px;margin-left:2px;';
    badge.textContent = 'Activo';
    btn.appendChild(badge);
    // Update shield color
    const svgPath = btn.querySelector('path');
    if (svgPath) btn.querySelector('svg').setAttribute('stroke', '#16a34a');
  } else {
    const svg = btn.querySelector('svg');
    if (svg) svg.setAttribute('stroke', 'currentColor');
  }
}

function renderPortalArea(data) {
  const area = document.getElementById('portal-status-area');
  if (!area) return;

  if (data.portal_activo && data.portal_url) {
    area.innerHTML = `
      <div class="portal-status-banner active">
        <div class="portal-status-icon active">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        </div>
        <div style="flex:1;min-width:0;">
          <div class="portal-status-label active">Portal activo</div>
          <div style="font-size:12px;color:var(--s-ok-tx);margin-bottom:4px;">Comparte este enlace con el cliente para que acceda a su portal.</div>
          <div class="portal-url-row">
            <input type="text" id="portal-url-input" class="portal-url-input" value="${escHtml(data.portal_url)}" readonly>
            <button type="button" onclick="copiarPortal()" class="btn btn-outline btn-sm" style="color:var(--s-ok-tx);border-color:var(--s-ok-brd);flex-shrink:0;">
              <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
              Copiar
            </button>
            <a href="${escHtml(data.portal_url)}" target="_blank" class="btn btn-outline btn-sm" style="color:var(--s-ok-tx);border-color:var(--s-ok-brd);text-decoration:none;flex-shrink:0;">
              <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
              Ver portal
            </a>
          </div>
        </div>
      </div>
      <div class="portal-actions">
        <button type="button" onclick="portalAction('desactivar')" class="btn btn-outline btn-sm" style="color:#dc2626;border-color:#fca5a5;">
          <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          Desactivar portal
        </button>
        <button type="button" onclick="portalAction('regenerar')" class="btn btn-outline btn-sm">
          <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
          Regenerar enlace
        </button>
      </div>
    `;
  } else {
    area.innerHTML = `
      <div class="portal-status-banner inactive">
        <div class="portal-status-icon inactive">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        </div>
        <div>
          <div class="portal-status-label inactive">Portal no activado</div>
          <div style="font-size:12px;color:var(--gris-texto);">Activa el portal para generar un enlace de acceso para el cliente.</div>
        </div>
      </div>
      <div class="portal-actions">
        <button type="button" onclick="portalAction('activar')" class="btn btn-primary btn-sm">
          <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
          Activar portal
        </button>
      </div>
    `;
  }
}

function escHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>

<!-- Modal eliminar -->
<script>
function confirmarEliminar() {
  GGIConfirm.show({
    icon: 'danger',
    title: '¿Eliminar empresa?',
    message: 'Se eliminará permanentemente a <?= json_encode($cliente['razon_social']) ?>. No se puede deshacer.',
    confirmText: 'Sí, eliminar',
    confirmColor: '#ef4444',
    onConfirm: () => {
      ggiPost('<?= APP_URL ?>/modules/clientes/eliminar.php', { id: <?= $id ?> }, { silent: true })
      .then(d => {
        if (d.ok) {
          if (typeof showToast === 'function') showToast('Empresa eliminada.', 'ok');
          <?php if ($_ggi_embed): ?>
          // En modal: avisar al padre (cierra el modal y refresca la vista actual;
          // si era la ficha del cliente eliminado, ver.php redirige al listado).
          setTimeout(function(){ window.parent.postMessage({ ggi: 'created', module: 'clientes', id: <?= (int)$id ?>, deleted: true }, '*'); }, 800);
          <?php else: ?>
          setTimeout(() => location.href = '<?= APP_URL ?>/modules/clientes/index.php', 1000);
          <?php endif; ?>
        } else {
          if (typeof showToast === 'function') showToast(d.msg || 'Error al eliminar.', 'error');
        }
      }).catch(e => {
        if (typeof showToast === 'function') showToast('Error: ' + e.message, 'error');
      });
    }
  });
}

// validacion.js se incluye más abajo (vía layout_end); diferir hasta DOMContentLoaded
// garantiza que GGIForm exista y la validación de cliente sí se adjunte.
(function initEmpresaValidacion(){
  if (typeof GGIForm === 'undefined') {
    document.addEventListener('DOMContentLoaded', initEmpresaValidacion, { once: true });
    return;
  }

  new GGIForm('form-editar-cliente')
    .required('ruc','El RUC es requerido.')
    .ruc('ruc','RUC inválido — debe tener 11 dígitos.')
    .required('razon_social','La razón social es requerida.')
    .required('contacto_telefono','El celular del contacto es requerido.')
    .custom('contacto_telefono', function(v){
        var d = (v || '').replace(/\D/g, '');
        if (d.length === 11 && d.indexOf('51') === 0) d = d.slice(2);
        return d === '' || /^9\d{8}$/.test(d); // vacío lo maneja required
    }, 'Ingresa un celular válido (9 dígitos, empieza en 9).')
    .email('contacto_email','Ingresa un email válido.')
    .init();

  // Normaliza el celular en vivo: solo dígitos y quita el prefijo 51 si lo pegan
  var tel = document.querySelector('#form-editar-cliente [name="contacto_telefono"]');
  if (tel) {
    tel.addEventListener('input', function(){
      var d = this.value.replace(/\D/g, '');
      if (d.length === 11 && d.indexOf('51') === 0) d = d.slice(2);
      this.value = d.slice(0, 9);
    });
  }
})();
</script>
<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
