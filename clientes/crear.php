<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../../core/SunatService.php';
session_init();
require_login();
$user = auth_user();
$from = $_POST['from'] ?? $_GET['from'] ?? 'listado';
$cancel_url = $from === 'dashboard' ? APP_URL . '/modules/dashboard/index.php' : APP_URL . '/modules/clientes/index.php';
$page_title  = 'Nueva Empresa';
$active_menu = 'clientes';



$pdo = Database::get();
$errors = [];
$data   = [];

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
        'contacto_email'    => trim($_POST['contacto_email'] ?? ''),
        'contacto_cargo'    => trim($_POST['contacto_cargo'] ?? ''),
        'fecha_contrato'    => $_POST['fecha_contrato'] ?? '',
        'estado'            => $_POST['estado'] ?? 'activo',
        'notas'             => trim($_POST['notas'] ?? ''),
        'ejecutivo_id'      => (int)($_POST['ejecutivo_id'] ?? 0) ?: null,
    ];

    // Validaciones
    if (empty($data['ruc']) || !preg_match('/^\d{11}$/', $data['ruc']))
        $errors['ruc'] = 'RUC debe tener 11 dígitos.';
    if (empty($data['razon_social']))
        $errors['razon_social'] = 'La razón social es requerida.';

    // Verificar RUC duplicado
    if (empty($errors['ruc'])) {
        $existe = $pdo->prepare("SELECT id FROM clientes WHERE ruc = ?");
        $existe->execute([$data['ruc']]);
        if ($existe->fetch()) $errors['ruc'] = 'Ya existe un cliente con este RUC.';
    }

    if (empty($errors)) {
        $stmt = $pdo->prepare("
            INSERT INTO clientes
              (ruc, razon_social, direccion, departamento, provincia, distrito, ubigeo,
               contacto_nombre, contacto_apellido, contacto_telefono, contacto_email, contacto_cargo,
               fecha_contrato, estado, notas, ejecutivo_id, creado_por)
            VALUES
              (:ruc, :razon_social, :direccion, :departamento, :provincia, :distrito, :ubigeo,
               :contacto_nombre, :contacto_apellido, :contacto_telefono, :contacto_email, :contacto_cargo,
               :fecha_contrato, :estado, :notas, :ejecutivo_id, :creado_por)
        ");
        $stmt->execute([
            ':ruc'               => $data['ruc'],
            ':razon_social'      => $data['razon_social'],
            ':direccion'         => $data['direccion'],
            ':departamento'      => $data['departamento'],
            ':provincia'         => $data['provincia'],
            ':distrito'          => $data['distrito'],
            ':ubigeo'            => $data['ubigeo'],
            ':contacto_nombre'   => $data['contacto_nombre'],
            ':contacto_apellido' => $data['contacto_apellido'],
            ':contacto_telefono' => $data['contacto_telefono'],
            ':contacto_email'    => $data['contacto_email'],
            ':contacto_cargo'    => $data['contacto_cargo'],
            ':fecha_contrato'    => $data['fecha_contrato'] ?: null,
            ':estado'            => $data['estado'],
            ':notas'             => $data['notas'],
            ':ejecutivo_id'      => $data['ejecutivo_id'],
            ':creado_por'        => $user['id'],
        ]);

        $nuevo_id = $pdo->lastInsertId();
        log_actividad('clientes', 'crear', 'Cliente registrado: ' . $data['razon_social']);

        header('Location: ' . APP_URL . '/modules/clientes/ver.php?id=' . $nuevo_id . '&nuevo=1&from=' . $from);
        exit;
    }
}
// ── Incluir layout aquí ──
$user = auth_user();
$ejecutivos = $pdo->query("SELECT id, nombre, apellido, rol FROM usuarios WHERE activo = 1 ORDER BY nombre")->fetchAll();
require_once __DIR__ . '/../../templates/layout.php';

?>

<style>
  .form-card { background:#fff; border-radius:12px; border:1px solid var(--gris-borde); overflow:hidden; max-width:860px; }
  .form-section { padding:24px 28px; border-bottom:1px solid var(--gris-borde); }
  .form-section:last-child { border-bottom:none; }
  .section-title { font-family:'Outfit',sans-serif; font-size:14px; font-weight:600; color:var(--azul); margin-bottom:18px; display:flex; align-items:center; gap:8px; }
  .section-title svg { color:var(--verde); }

  .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
  .form-grid.cols-3 { grid-template-columns:1fr 1fr 1fr; }
  .form-grid.cols-1 { grid-template-columns:1fr; }
  .span-2 { grid-column:span 2; }

  .field { display:flex; flex-direction:column; gap:5px; }
  .field label { font-size:12px; font-weight:600; color:var(--azul); }
  .field label .req { color:var(--red); margin-left:2px; }
  .field input, .field select, .field textarea {
    padding:9px 12px; border:1.5px solid var(--gris-borde); border-radius:9px;
    font-size:13px; font-family:'Plus Jakarta Sans',sans-serif; color:var(--gris-dark);
    background:#fafbfe; outline:none; transition:border-color 0.2s, box-shadow 0.2s;
  }
  .field input:focus, .field select:focus, .field textarea:focus {
    border-color:var(--azul); background:#fff; box-shadow:0 0 0 3px rgba(26,58,143,0.07);
  }
  .field input.error, .field select.error { border-color:var(--red); }
  .field .err-msg { font-size:11px; color:var(--red); }
  .field textarea { resize:vertical; min-height:80px; }

  /* RUC con botón buscar */
  .ruc-wrap { position:relative; display:flex; gap:8px; }
  .ruc-wrap input { flex:1; }
  .btn-buscar-ruc {
    padding:9px 16px; background:var(--azul); color:#fff;
    border:none; border-radius:9px; font-size:12px; font-weight:600;
    cursor:pointer; white-space:nowrap; transition:background 0.15s;
    display:flex; align-items:center; gap:6px;
  }
  .btn-buscar-ruc:hover { background:var(--azul-mid); }
  .btn-buscar-ruc:disabled { opacity:0.6; cursor:not-allowed; }

  /* Estado de consulta RUC */
  .ruc-status { font-size:12px; margin-top:4px; display:flex; align-items:center; gap:5px; min-height:18px; }
  .ruc-status.ok  { color:var(--verde-dark); }
  .ruc-status.err { color:var(--red); }
  .ruc-status.loading { color:var(--gris-texto); }

  /* Spinner */
  .spinner { width:13px; height:13px; border:2px solid rgba(255,255,255,0.4); border-top-color:#fff; border-radius:50%; animation:spin 0.6s linear infinite; display:none; }
  @keyframes spin { to { transform:rotate(360deg); } }

  .form-actions { padding:20px 28px; background:#fafbfe; border-top:1px solid var(--gris-borde); display:flex; gap:10px; }

  @media (max-width:768px) {
    .form-grid { grid-template-columns:1fr; }
    .form-grid.cols-3 { grid-template-columns:1fr; }
    .span-2 { grid-column:span 1; }
  }
</style>

<div style="display:flex;gap:8px;justify-content:flex-end;margin-bottom:16px;">
  <a href="<?= $cancel_url ?>" class="btn btn-outline btn-sm">← Volver</a>
</div>

<form method="POST" id="form-cliente" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="from" value="<?= sanitize($from) ?>">

  <div class="form-card">

    <!-- Datos SUNAT -->
    <div class="form-section">
      <div class="section-title">
        <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
        Datos SUNAT
      </div>
      <div class="form-grid">
        <div class="field">
          <label>RUC <span class="req">*</span></label>
          <div class="ruc-wrap">
            <input type="text" id="ruc" name="ruc" maxlength="11" placeholder="20xxxxxxxxx"
                   value="<?= sanitize($data['ruc'] ?? '') ?>"
                   class="<?= isset($errors['ruc'])?'error':'' ?>"
                   oninput="this.value=this.value.replace(/\D/g,'')">
            <button type="button" class="btn-buscar-ruc" id="btn-ruc" onclick="buscarRuc()">
              <span class="spinner" id="spinner-ruc"></span>
              <svg id="icon-search" width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              Consultar
            </button>
          </div>
          <div class="ruc-status" id="ruc-status"></div>
          <?php if (isset($errors['ruc'])): ?>
          <div class="err-msg"><?= $errors['ruc'] ?></div>
          <?php endif; ?>
        </div>
        <div class="field">
          <label>Razón social <span class="req">*</span></label>
          <input type="text" id="razon_social" name="razon_social" placeholder="Se completa automáticamente"
                 value="<?= sanitize($data['razon_social'] ?? '') ?>"
                 class="<?= isset($errors['razon_social'])?'error':'' ?>">
          <?php if (isset($errors['razon_social'])): ?>
          <div class="err-msg"><?= $errors['razon_social'] ?></div>
          <?php endif; ?>
        </div>
        <div class="field span-2">
          <label>Dirección fiscal</label>
          <input type="text" id="direccion" name="direccion" placeholder="Se completa automáticamente"
                 value="<?= sanitize($data['direccion'] ?? '') ?>">
        </div>
        <div class="field">
          <label>Departamento</label>
          <input type="text" id="departamento" name="departamento" value="<?= sanitize($data['departamento'] ?? '') ?>">
        </div>
        <div class="field">
          <label>Provincia</label>
          <input type="text" id="provincia" name="provincia" value="<?= sanitize($data['provincia'] ?? '') ?>">
        </div>
        <div class="field">
          <label>Distrito</label>
          <input type="text" id="distrito" name="distrito" value="<?= sanitize($data['distrito'] ?? '') ?>">
        </div>
        <div class="field">
          <label>Ubigeo</label>
          <input type="text" id="ubigeo" name="ubigeo" maxlength="6" value="<?= sanitize($data['ubigeo'] ?? '') ?>">
        </div>
      </div>
    </div>

    <!-- Datos de contacto -->
    <div class="form-section">
      <div class="section-title">
        <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        Datos de contacto
      </div>
      <div class="form-grid">
        <div class="field">
          <label>Nombre</label>
          <input type="text" name="contacto_nombre" placeholder="Nombre del contacto"
                 value="<?= sanitize($data['contacto_nombre'] ?? '') ?>">
        </div>
        <div class="field">
          <label>Apellido</label>
          <input type="text" name="contacto_apellido" placeholder="Apellido del contacto"
                 value="<?= sanitize($data['contacto_apellido'] ?? '') ?>">
        </div>
        <div class="field">
          <label>Teléfono</label>
          <input type="text" name="contacto_telefono" placeholder="9xx xxx xxx"
                 value="<?= sanitize($data['contacto_telefono'] ?? '') ?>">
        </div>
        <div class="field">
          <label>Email</label>
          <input type="email" name="contacto_email" placeholder="correo@empresa.com"
                 value="<?= sanitize($data['contacto_email'] ?? '') ?>">
        </div>
        <div class="field">
          <label>Cargo</label>
          <input type="text" name="contacto_cargo" placeholder="Gerente General, Administrador..."
                 value="<?= sanitize($data['contacto_cargo'] ?? '') ?>">
        </div>
      </div>
    </div>

    <!-- Info comercial -->
    <div class="form-section">
      <div class="section-title">
        <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
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
          <label>Ejecutivo comercial asignado</label>
          <select name="ejecutivo_id">
            <option value="">— Sin asignar —</option>
            <?php
            $roles_lbl = ['gerente_general'=>'Gerente General','gerente_operaciones'=>'Gte. Operaciones','ventas'=>'Ventas','delivery'=>'Delivery'];
            foreach ($ejecutivos as $ej):
              $sel = ($data['ejecutivo_id'] ?? null) == $ej['id'] ? 'selected' : '';
            ?>
            <option value="<?= $ej['id'] ?>" <?= $sel ?>>
              <?= sanitize($ej['nombre'] . ' ' . $ej['apellido']) ?> — <?= $roles_lbl[$ej['rol']] ?? $ej['rol'] ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field span-2">
          <label>Notas internas</label>
          <textarea name="notas" placeholder="Observaciones, referencias, información adicional..."><?= sanitize($data['notas'] ?? '') ?></textarea>
        </div>
      </div>
    </div>

    <div class="form-actions">
      <button type="submit" class="btn btn-primary">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
        Guardar cliente
      </button>
      <a href="<?= $cancel_url ?>" class="btn btn-outline">Cancelar</a>
    </div>

  </div>
</form>

<script>
const API_URL = '<?= APP_URL ?>/modules/clientes/ajax_ruc.php';

async function buscarRuc() {
  const ruc    = document.getElementById('ruc').value.trim();
  const btn    = document.getElementById('btn-ruc');
  const status = document.getElementById('ruc-status');
  const spinner = document.getElementById('spinner-ruc');
  const iconSearch = document.getElementById('icon-search');

  if (ruc.length !== 11) {
    setStatus('err', 'El RUC debe tener exactamente 11 dígitos.');
    return;
  }

  // Loading state
  btn.disabled = true;
  spinner.style.display = 'block';
  iconSearch.style.display = 'none';
  setStatus('loading', 'Consultando SUNAT...');

  try {
    const res  = await fetch(`${API_URL}?ruc=${ruc}`);
    const json = await res.json();

    if (json.ok) {
      const d = json.data;
      setField('razon_social', d.razon_social);
      setField('direccion',    d.direccion);
      setField('departamento', d.departamento);
      setField('provincia',    d.provincia);
      setField('distrito',     d.distrito);
      setField('ubigeo',       d.ubigeo);
      setStatus('ok', `✓ ${d.razon_social} — ${d.estado || 'ACTIVO'}`);
    } else {
      setStatus('err', '✗ ' + json.mensaje);
    }
  } catch (e) {
    setStatus('err', '✗ Error de conexión. Intenta nuevamente.');
  } finally {
    btn.disabled = false;
    spinner.style.display = 'none';
    iconSearch.style.display = 'block';
  }
}

function setField(id, value) {
  const el = document.getElementById(id);
  if (el) el.value = value || '';
}

function setStatus(type, msg) {
  const el = document.getElementById('ruc-status');
  el.className = 'ruc-status ' + type;
  el.textContent = msg;
}

// Buscar al presionar Enter en el campo RUC
document.getElementById('ruc').addEventListener('keydown', e => {
  if (e.key === 'Enter') { e.preventDefault(); buscarRuc(); }
});
</script>

<script>
// Validación client-side
new GGIForm('form-cliente')
  .required('ruc',          'El RUC es requerido.')
  .ruc('ruc',               'RUC inválido — debe tener 11 dígitos.')
  .required('razon_social', 'La razón social es requerida.')
  .email('contacto_email',  'Ingresa un email válido.')
  .init();
</script>

<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
