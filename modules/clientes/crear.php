<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../../core/SunatService.php';
session_init();
require_login();
$user = auth_user();
require_role('gerente_general','gerente_operaciones');

$_ggi_embed = !empty($_GET['embed']) || !empty($_POST['_embed']);
$from       = $_POST['from'] ?? $_GET['from'] ?? 'listado';
$cancel_url = $from === 'dashboard'
    ? APP_URL . '/modules/dashboard/index.php'
    : APP_URL . '/modules/clientes/index.php';

$pdo    = Database::get();
$errors = [];
$data   = ['ejecutivo_id' => ($user['rol'] === 'ejecutivo_ventas' ? $user['id'] : null)];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $data = [
        'ruc'               => trim($_POST['ruc'] ?? ''),
        'razon_social'      => trim($_POST['razon_social'] ?? ''),
        'direccion'         => trim($_POST['direccion'] ?? ''),
        // Campos ocultos — vienen del autocompletado, se guardan en BD pero no se muestran
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
        'fecha_contrato'    => $_POST['fecha_contrato'] ?? '',
        'estado'            => $_POST['estado'] ?? 'activo',
        'notas'             => trim($_POST['notas'] ?? ''),
        'ejecutivo_id'      => ($user['rol'] === 'ejecutivo_ventas'
            ? $user['id']
            : ((int)($_POST['ejecutivo_id'] ?? 0) ?: null)),
    ];

    if (empty($data['ruc']) || !ruc_valido($data['ruc']))
        $errors['ruc'] = 'RUC inválido (debe tener 11 dígitos y pasar la validación del dígito verificador).';
    if (empty($data['razon_social']))
        $errors['razon_social'] = 'La razón social es requerida.';
    if (empty($errors['ruc'])) {
        $existe = $pdo->prepare("SELECT id FROM clientes WHERE ruc=?");
        $existe->execute([$data['ruc']]);
        if ($existe->fetch()) $errors['ruc'] = 'Ya existe un cliente con este RUC.';
    }

    if (empty($errors)) {
        $ej_id = $data['ejecutivo_id'] ?: null;
        // Usar el valor enviado por el form (poblado via JS autocompletar); fallback a API si vacío
        $num_trab = !empty($_POST['num_trabajadores']) ? (int)$_POST['num_trabajadores'] : null;
        if ($num_trab === null) {
            $trab_res = SunatService::consultarTrabajadores($data['ruc']);
            $num_trab = $trab_res['ok'] ? $trab_res['num_trabajadores'] : null;
        }

        $pdo->prepare("
            INSERT INTO clientes
              (ruc, razon_social, direccion, departamento, provincia, distrito, ubigeo,
               contacto_nombre, contacto_apellido, contacto_telefono, horario_contacto, contacto_email, contacto_cargo,
               num_trabajadores,
               fecha_contrato, estado, notas,
               ejecutivo_id, created_by_user_id, responsible_user_id,
               creado_por)
            VALUES
              (?,?,?,?,?,?,?,  ?,?,?,?,?,?,  ?,  ?,?,?,  ?,?,?,  ?)
        ")->execute([
            $data['ruc'],
            $data['razon_social'],
            $data['direccion']    ?: null,
            $data['departamento'] ?: null,
            $data['provincia']    ?: null,
            $data['distrito']     ?: null,
            $data['ubigeo']       ?: null,
            $data['contacto_nombre']   ?: null,
            $data['contacto_apellido'] ?: null,
            $data['contacto_telefono'] ?: null,
            $data['horario_contacto']  ?: null,
            $data['contacto_email']    ?: null,
            $data['contacto_cargo']    ?: null,
            $num_trab,
            $data['fecha_contrato']    ?: null,
            $data['estado'],
            $data['notas'] ?: null,
            $ej_id,
            $ej_id,
            $ej_id,
            $user['id'],
        ]);

        $nuevo_id = (int)$pdo->lastInsertId();

        // Registrar historial de creación
        if ($nuevo_id && $ej_id) {
            $pdo->prepare("
                INSERT INTO historial_responsables_cliente
                  (cliente_id, responsable_anterior, responsable_nuevo, motivo, registrado_por, notas)
                VALUES (?, NULL, ?, 'creacion', ?, 'Empresa registrada manualmente')
            ")->execute([$nuevo_id, $ej_id, $user['id']]);
        }

        log_actividad('clientes','crear','Cliente registrado: '.$data['razon_social']);
        flash('Empresa registrada exitosamente.');

        // ── Notificar a todos los gerentes generales ──────────────────────────
        $creador_nombre = trim(($user['nombre'] ?? '') . ' ' . ($user['apellido'] ?? ''));
        $gerentes = $pdo->query("SELECT id FROM usuarios WHERE rol='gerente_general' AND activo=1")->fetchAll(PDO::FETCH_COLUMN);
        $ins_notif = $pdo->prepare("
            INSERT INTO notificaciones (usuario_id, tipo, titulo, mensaje, url_accion)
            VALUES (?, 'cliente_nuevo', ?, ?, ?)
        ");
        foreach ($gerentes as $gid) {
            $ins_notif->execute([
                $gid,
                'Nuevo cliente registrado',
                'Se registró la empresa ' . $data['razon_social'] . ' (RUC ' . $data['ruc'] . ')' .
                    ($creador_nombre ? ' por ' . $creador_nombre . '.' : '.') .
                    ' Revisa la información y documentos cargados.',
                APP_URL . '/modules/clientes/ver.php?id=' . $nuevo_id,
            ]);
        }

        if ($_ggi_embed) {
            ?><!DOCTYPE html><html><body><script>
            window.parent.postMessage({ggi:'created',module:'clientes'},'*');
            </script></body></html><?php
            exit;
        }
        header('Location: '.APP_URL.'/modules/clientes/ver.php?id='.$nuevo_id);
        exit;
    }
}

$ejecutivos  = $pdo->query("SELECT id, nombre, apellido FROM usuarios WHERE activo=1 AND rol='ejecutivo_ventas' ORDER BY nombre")->fetchAll();
$page_title  = 'Nueva Empresa';
$active_menu = 'clientes';
require_once __DIR__ . '/../../templates/layout.php';
?>
<style>
  .form-card { background:var(--bg-surface); border-radius:12px; border:1px solid var(--gris-borde); overflow:hidden; max-width:860px; }
  .form-section { padding:22px 28px; border-bottom:1px solid var(--gris-borde); }
  .form-section:last-of-type { border-bottom:none; }
  .section-title { font-family:'Inter',sans-serif; font-size:14px; font-weight:600; color:var(--azul); margin-bottom:16px; display:flex; align-items:center; gap:8px; }
  .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
  .span-2 { grid-column:1/-1; }
  .field { display:flex; flex-direction:column; gap:5px; }
  .field label { font-size:12px; font-weight:600; color:var(--azul); }
  .field input, .field select, .field textarea {
    padding:10px 12px; border:1.5px solid var(--gris-borde); border-radius:9px;
    font-size:13px; font-family:'Inter',sans-serif;
    background:var(--bg-surface2); outline:none; width:100%; transition:border-color .2s;
  }
  .field input:focus, .field select:focus, .field textarea:focus { border-color:var(--azul); background:var(--bg-surface); }
  .field input.error { border-color:#ef4444; }
  .err-msg { font-size:11px; color:#ef4444; margin-top:2px; }
  .field textarea { resize:vertical; min-height:80px; }
  .ruc-wrap { display:flex; gap:8px; }
  .ruc-wrap input { flex:1; min-width:0; }
  .btn-consultar {
    flex-shrink:0; padding:10px 14px; background:var(--azul); color:#fff;
    border:none; border-radius:9px; font-size:13px; font-weight:600;
    cursor:pointer; display:flex; align-items:center; gap:6px;
    white-space:nowrap; transition:background .15s;
  }
  .btn-consultar:hover { background:#122870; }
  .btn-consultar:disabled { opacity:.6; cursor:not-allowed; }
  .ruc-status { font-size:11px; margin-top:3px; min-height:15px; }
  .ruc-status.ok { color:#229133; }
  .ruc-status.err { color:#ef4444; }
  .ruc-status.loading { color:var(--gris-texto); }
  .form-actions { padding:16px 28px; background:var(--bg-surface2); border-top:1px solid var(--gris-borde); display:flex; gap:10px; }
  /* Select con flecha personalizada */
  .select-wrap { position:relative; }
  .select-wrap select { width:100%; padding:10px 30px 10px 13px; border:1.5px solid var(--gris-borde); border-radius:9px;
    font-size:13px; font-family:'Inter',sans-serif; background-color:var(--bg-surface2); outline:none;
    cursor:pointer; transition:border-color .2s; color:var(--gris-dark); }
  .select-wrap select:focus { border-color:var(--azul); background:var(--bg-surface); }
  @media (max-width:640px) {
    .form-section { padding:16px; }
    .form-grid { grid-template-columns:1fr; }
    .span-2 { grid-column:1; }
    .ruc-wrap { flex-direction:column; }
  }
</style>

<?php if (!$_ggi_embed): ?>
<?php ggi_breadcrumb([
    ['label' => 'Empresas', 'href' => APP_URL.'/modules/clientes/index.php', 'ss' => 'ggi_ss_clientes'],
    ['label' => 'Nueva empresa'],
]); ?>
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
  <div>
    <h1>Nueva empresa</h1>
    <p>Completa los datos de la empresa a registrar</p>
  </div>
</div>
<?php endif; ?>

<form method="POST" id="form-cliente" data-ggi-draft="cliente-crear" novalidate>
  <?= csrf_field() ?>
  <input type="hidden" name="from" value="<?= sanitize($from) ?>">
  <?php if ($_ggi_embed): ?><input type="hidden" name="_embed" value="1"><?php endif; ?>

  <div class="form-card">

    <!-- Datos SUNAT -->
    <div class="form-section">
      <div class="section-title">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
        Datos SUNAT
        <span style="margin-left:auto;font-size:10px;font-weight:500;color:var(--gris-texto);background:var(--azul-light);padding:2px 8px;border-radius:100px;display:flex;align-items:center;gap:4px;">
          <svg width="10" height="10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          Ingresa el RUC y presiona Consultar
        </span>
      </div>
      <div class="form-grid">
        <div class="field">
          <label>RUC <span class="req">*</span></label>
          <div class="ruc-wrap">
            <input type="text" id="ruc" name="ruc" maxlength="11" inputmode="numeric"
                   placeholder="20xxxxxxxxx"
                   value="<?= sanitize($data['ruc'] ?? '') ?>"
                   class="<?= isset($errors['ruc'])?'error':'' ?>"
                   oninput="this.value=this.value.replace(/\D/g,'')">
            <button type="button" class="btn-consultar" id="btn-ruc" onclick="buscarRuc()">
              <svg id="icon-search" width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              Consultar
            </button>
          </div>
          <div class="ruc-status" id="ruc-status"></div>
          <?php if (isset($errors['ruc'])): ?><div class="err-msg"><?= $errors['ruc'] ?></div><?php endif; ?>
        </div>
        <div class="field">
          <label>Razón social <span class="req">*</span> <span style="font-size:10px;color:var(--verde);font-weight:500;">● Auto</span></label>
          <input type="text" id="razon_social" name="razon_social"
                 placeholder="Se completa al consultar RUC"
                 value="<?= sanitize($data['razon_social'] ?? '') ?>"
                 class="<?= isset($errors['razon_social'])?'error':'' ?>">
          <?php if (isset($errors['razon_social'])): ?><div class="err-msg"><?= $errors['razon_social'] ?></div><?php endif; ?>
        </div>
        <div class="field span-2">
          <label>Dirección fiscal <span style="font-size:10px;color:var(--verde);font-weight:500;">● Auto</span></label>
          <input type="text" id="direccion" name="direccion"
                 placeholder="Se completa al consultar RUC"
                 value="<?= sanitize($data['direccion'] ?? '') ?>">
        </div>
        <div class="field">
          <label>N.º trabajadores <span style="font-size:10px;color:var(--verde);font-weight:500;">● Auto</span></label>
          <input type="number" id="num_trabajadores" name="num_trabajadores" min="0"
                 placeholder="Se completa al consultar RUC"
                 value="<?= (int)($data['num_trabajadores'] ?? 0) ?: '' ?>">
        </div>
      </div>
      <!-- Campos ocultos: se guardan en BD pero no se muestran al usuario -->
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
      <div class="form-grid">
        <div class="field">
          <label>Nombre <span style="font-size:10px;color:var(--verde);font-weight:500;">● Auto</span></label>
          <input type="text" id="contacto_nombre" name="contacto_nombre"
                 placeholder="Nombre del representante"
                 value="<?= sanitize($data['contacto_nombre'] ?? '') ?>">
        </div>
        <div class="field">
          <label>Apellido <span style="font-size:10px;color:var(--verde);font-weight:500;">● Auto</span></label>
          <input type="text" id="contacto_apellido" name="contacto_apellido"
                 placeholder="Apellido del representante"
                 value="<?= sanitize($data['contacto_apellido'] ?? '') ?>">
        </div>
        <div class="field span-2">
          <label>Cargo <span style="font-size:10px;color:var(--verde);font-weight:500;">● Auto</span></label>
          <input type="text" id="contacto_cargo" name="contacto_cargo"
                 placeholder="Gerente General, Titular-Gerente..."
                 value="<?= sanitize($data['contacto_cargo'] ?? '') ?>">
        </div>
        <div class="field">
          <label>Email</label>
          <input type="email" id="contacto_email" name="contacto_email"
                 placeholder="correo@empresa.com"
                 value="<?= sanitize($data['contacto_email'] ?? '') ?>">
        </div>
        <div class="field">
          <label>Teléfono</label>
          <input type="tel" id="contacto_telefono" name="contacto_telefono"
                 placeholder="9xx xxx xxx"
                 value="<?= sanitize($data['contacto_telefono'] ?? '') ?>">
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
      <div class="form-grid">
        <div class="field">
          <label>Fecha de contrato</label>
          <input type="date" name="fecha_contrato" value="<?= sanitize($data['fecha_contrato'] ?? '') ?>">
        </div>
        <div class="field">
          <label>Estado</label>
          <select name="estado">
            <option value="activo"    <?= ($data['estado']??'activo')==='activo'    ?'selected':'' ?>>Activo</option>
            <option value="prospecto" <?= ($data['estado']??'')==='prospecto' ?'selected':'' ?>>Prospecto</option>
            <option value="inactivo"  <?= ($data['estado']??'')==='inactivo'  ?'selected':'' ?>>Inactivo</option>
          </select>
        </div>
        <div class="field">
          <label>Ejecutivo comercial</label>
          <?php if ($user['rol'] === 'ejecutivo_ventas'): ?>
            <?php $ej = array_filter($ejecutivos, fn($e) => $e['id'] == $user['id']); $ej = reset($ej); ?>
            <input type="hidden" name="ejecutivo_id" value="<?= $user['id'] ?>">
            <input type="text" value="<?= $ej ? sanitize($ej['nombre'].' '.$ej['apellido']) : 'Asignado automáticamente' ?>" disabled style="background:var(--s-ok-bg);border-color:#b7e4c0;color:#229133;font-weight:600;">
          <?php else: ?>
            <div class="select-wrap">
              <select name="ejecutivo_id" id="ejecutivo_id">
                <option value="">— Sin asignar —</option>
                <?php foreach ($ejecutivos as $ej): ?>
                <option value="<?= $ej['id'] ?>" <?= ($data['ejecutivo_id']==$ej['id'])?'selected':'' ?>>
                  <?= sanitize($ej['nombre'].' '.$ej['apellido']) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php endif; ?>
        </div>
        <div class="field span-2">
          <label>Notas internas</label>
          <textarea name="notas" placeholder="Observaciones, referencias..."><?= sanitize($data['notas'] ?? '') ?></textarea>
        </div>
      </div>
    </div>

    <div class="form-actions">
      <button type="submit" class="btn btn-primary">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
        Guardar empresa
      </button>
      <?php if (!$_ggi_embed): ?>
      <a href="<?= $cancel_url ?>" class="btn btn-outline">Cancelar</a>
      <?php endif; ?>
    </div>

  </div>
</form>

<script>
async function buscarRuc() {
  const ruc = document.getElementById('ruc').value.trim();
  const btn = document.getElementById('btn-ruc');
  const st  = document.getElementById('ruc-status');
  if (ruc.length !== 11) { setStatus('err','El RUC debe tener 11 dígitos.'); return; }
  btn.disabled = true;
  setStatus('loading','Consultando SUNAT...');
  try {
    const r = await fetch('<?= APP_URL ?>/modules/clientes/ajax_ruc.php?ruc='+ruc);
    const j = await r.json();
    if (j.ok) {
      const d = j.data;
      setF('razon_social',      d.razon_social);
      setF('direccion',         d.direccion);
      // Ocultos — se guardan pero no se muestran
      setF('departamento',      d.departamento || '');
      setF('provincia',         d.provincia    || '');
      setF('distrito',          d.distrito     || '');
      setF('ubigeo',            Array.isArray(d.ubigeo) ? (d.ubigeo[d.ubigeo.length-1]||'') : (d.ubigeo||''));
      // Representante legal
      if (d.contacto_nombre)   setF('contacto_nombre',   d.contacto_nombre);
      if (d.contacto_apellido) setF('contacto_apellido', d.contacto_apellido);
      if (d.contacto_cargo)    setF('contacto_cargo',    d.contacto_cargo);
      if (d.num_trabajadores != null) setF('num_trabajadores', d.num_trabajadores);
      setStatus('ok','✓ '+d.razon_social+(d.estado?' — '+d.estado:''));
    } else {
      setStatus('err','✗ '+(j.mensaje||'No encontrado'));
    }
  } catch(e) { setStatus('err','✗ Error de conexión.'); }
  finally { btn.disabled = false; }
}
function setF(id, val) { const el = document.getElementById(id); if (el) el.value = val || ''; }
function setStatus(type, msg) {
  const el = document.getElementById('ruc-status');
  el.className = 'ruc-status ' + type;
  el.textContent = msg;
}
document.getElementById('ruc').addEventListener('keydown', e => {
  if (e.key === 'Enter') { e.preventDefault(); buscarRuc(); }
});
</script>
<script>
new GGIForm('form-cliente')
  .required('ruc','El RUC es requerido.')
  .ruc('ruc','RUC inválido — debe tener 11 dígitos.')
  .required('razon_social','La razón social es requerida.')
  .email('contacto_email','Ingresa un email válido.')
  .init();
</script>
<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
