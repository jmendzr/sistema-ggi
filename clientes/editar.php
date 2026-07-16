<?php
// ── Toda la lógica PHP PRIMERO, antes de cualquier output ──
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . APP_URL . '/modules/clientes/index.php'); exit; }

$pdo = Database::get();
$stmt = $pdo->prepare("SELECT * FROM clientes WHERE id = ?");
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
        'contacto_email'    => trim($_POST['contacto_email'] ?? ''),
        'contacto_cargo'    => trim($_POST['contacto_cargo'] ?? ''),
        'fecha_contrato'    => $_POST['fecha_contrato'] ?? '',
        'estado'            => $_POST['estado'] ?? 'activo',
        'notas'             => trim($_POST['notas'] ?? ''),
        'ejecutivo_id'      => (int)($_POST['ejecutivo_id'] ?? 0) ?: null,
    ];

    if (empty($data['ruc']) || !preg_match('/^\d{11}$/', $data['ruc']))
        $errors['ruc'] = 'RUC debe tener 11 dígitos.';
    if (empty($data['razon_social']))
        $errors['razon_social'] = 'La razón social es requerida.';

    if (empty($errors['ruc'])) {
        $existe = $pdo->prepare("SELECT id FROM clientes WHERE ruc = ? AND id != ?");
        $existe->execute([$data['ruc'], $id]);
        if ($existe->fetch()) $errors['ruc'] = 'Ya existe otro cliente con este RUC.';
    }

    if (empty($errors)) {
        $stmt = $pdo->prepare("
            UPDATE clientes SET
              ruc=:ruc, razon_social=:razon_social,
              direccion=:direccion, departamento=:departamento,
              provincia=:provincia, distrito=:distrito, ubigeo=:ubigeo,
              contacto_nombre=:contacto_nombre, contacto_apellido=:contacto_apellido,
              contacto_telefono=:contacto_telefono, contacto_email=:contacto_email,
              contacto_cargo=:contacto_cargo, fecha_contrato=:fecha_contrato,
              estado=:estado, notas=:notas
            WHERE id=:id
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
            ':id'                => $id,
        ]);
        // Actualizar ejecutivo_id solo si la columna existe (requiere SQL 08)
        try {
            $pdo->prepare("UPDATE clientes SET ejecutivo_id=? WHERE id=?")
                ->execute([$data['ejecutivo_id'], $id]);
        } catch (Exception $e) { /* columna aún no existe, ignorar */ }
        log_actividad('clientes', 'editar', 'Cliente actualizado: ' . $data['razon_social']);
        header('Location: ' . APP_URL . '/modules/clientes/editar.php?id=' . $id . '&editado=1');
        exit;
    }
}

// ── Recién aquí incluimos el layout (output HTML) ──
$page_title  = 'Editar Empresa';
$from = $_GET['from'] ?? 'ficha';

// Cargar ejecutivos disponibles (todos los usuarios activos)
$ejecutivos = $pdo->query("
    SELECT id, nombre, apellido, rol FROM usuarios
    WHERE activo = 1 ORDER BY nombre
")->fetchAll();
$cancel_url_map = [
    'listado'   => APP_URL . '/modules/clientes/index.php',
    'dashboard' => APP_URL . '/modules/dashboard/index.php',
];
$cancel_url = $cancel_url_map[$from] ?? (APP_URL . '/modules/clientes/ver.php?id=' . $id);
$active_menu = 'clientes';
$user        = auth_user();
require_once __DIR__ . '/../../templates/layout.php';
?>

<style>
  .form-card { background:#fff; border-radius:12px; border:1px solid var(--gris-borde); overflow:hidden; max-width:860px; width:100%; }
  .form-section { padding:20px 24px; border-bottom:1px solid var(--gris-borde); }
  .form-section:last-child { border-bottom:none; }
  .section-title { font-family:'Outfit',sans-serif; font-size:14px; font-weight:600; color:var(--azul); margin-bottom:16px; display:flex; align-items:center; gap:8px; }
  .section-title svg { color:var(--verde); }
  .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
  .span-2 { grid-column:span 2; }
  .field { display:flex; flex-direction:column; gap:5px; }
  .field label { font-size:12px; font-weight:600; color:var(--azul); }
  .field label .req { color:var(--red); margin-left:2px; }
  .field input, .field select, .field textarea {
    padding:10px 12px; border:1.5px solid var(--gris-borde); border-radius:9px;
    font-size:14px; font-family:'Plus Jakarta Sans',sans-serif; color:var(--gris-dark);
    background:#fafbfe; outline:none; width:100%;
    transition:border-color 0.2s, box-shadow 0.2s;
    -webkit-appearance:none; appearance:none;
  }
  .field select {
    padding-right:34px;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%236b7a99' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 10px center;
  }
  }
  .field input:focus, .field select:focus, .field textarea:focus {
    border-color:var(--azul); background:#fff; box-shadow:0 0 0 3px rgba(26,58,143,0.07);
  }
  .field input.error { border-color:var(--red); }
  .field .err-msg { font-size:11px; color:var(--red); }
  .field textarea { resize:vertical; min-height:80px; }
  .ruc-wrap { display:flex; gap:8px; }
  .ruc-wrap input { flex:1; min-width:0; }
  .btn-consultar {
    flex-shrink:0; padding:10px 14px; background:var(--azul); color:#fff;
    border:none; border-radius:9px; font-size:13px; font-weight:600;
    cursor:pointer; display:flex; align-items:center; gap:6px;
    transition:background 0.15s; white-space:nowrap;
  }
  .btn-consultar:hover { background:var(--azul-mid); }
  .btn-consultar:disabled { opacity:0.6; cursor:not-allowed; }
  .ruc-status { font-size:12px; margin-top:4px; min-height:16px; }
  .ruc-status.ok { color:var(--verde-dark); }
  .ruc-status.err { color:var(--red); }
  .ruc-status.loading { color:var(--gris-texto); }
  .spinner { width:13px; height:13px; border:2px solid rgba(255,255,255,0.4); border-top-color:#fff; border-radius:50%; animation:spin 0.6s linear infinite; display:none; flex-shrink:0; }
  @keyframes spin { to { transform:rotate(360deg); } }
  .form-actions { padding:16px 24px; background:#fafbfe; border-top:1px solid var(--gris-borde); display:flex; gap:10px; flex-wrap:wrap; }
  @media (max-width:640px) {
    .form-section { padding:16px; }
    .form-grid { grid-template-columns:1fr; }
    .span-2 { grid-column:span 1; }
    .ruc-wrap { flex-direction:column; }
    .btn-consultar { width:100%; justify-content:center; }
    .form-actions { flex-direction:column; }
    .form-actions .btn { width:100%; justify-content:center; }
  }
</style>

<div style="display:flex;gap:8px;justify-content:flex-end;margin-bottom:16px;">
  <div style="display:flex;gap:8px;flex-wrap:wrap;">
    <a href="<?= APP_URL ?>/modules/clientes/ver.php?id=<?= $id ?>" class="btn btn-outline btn-sm">← Ver ficha</a>
    <a href="<?= APP_URL ?>/modules/clientes/index.php" class="btn btn-outline btn-sm">Listado</a>
  </div>
</div>

<form method="POST" id="form-editar-cliente" novalidate>

    <div class="form-section">
      <div class="section-title">
        <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
        Datos SUNAT
      </div>
      <div class="form-grid">
        <div class="field">
          <label>RUC <span class="req">*</span></label>
          <div class="ruc-wrap">
            <input type="text" id="ruc" name="ruc" maxlength="11" inputmode="numeric"
                   value="<?= sanitize($data['ruc']) ?>"
                   class="<?= isset($errors['ruc'])?'error':'' ?>"
                   oninput="this.value=this.value.replace(/\D/g,'')">
            <button type="button" class="btn-consultar" id="btn-ruc" onclick="buscarRuc()">
              <span class="spinner" id="spinner-ruc"></span>
              <svg id="icon-search" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              Actualizar
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
          <input type="text" id="direccion" name="direccion" value="<?= sanitize($data['direccion']) ?>">
        </div>
        <div class="field">
          <label>Departamento</label>
          <input type="text" id="departamento" name="departamento" value="<?= sanitize($data['departamento']) ?>">
        </div>
        <div class="field">
          <label>Provincia</label>
          <input type="text" id="provincia" name="provincia" value="<?= sanitize($data['provincia']) ?>">
        </div>
        <div class="field">
          <label>Distrito</label>
          <input type="text" id="distrito" name="distrito" value="<?= sanitize($data['distrito']) ?>">
        </div>
        <div class="field">
          <label>Ubigeo</label>
          <input type="text" id="ubigeo" name="ubigeo" maxlength="20" value="<?= sanitize($data['ubigeo']) ?>">
        </div>
      </div>
    </div>

    <div class="form-section">
      <div class="section-title">
        <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        Datos de contacto
      </div>
      <div class="form-grid">
        <div class="field">
          <label>Nombre</label>
          <input type="text" name="contacto_nombre" value="<?= sanitize($data['contacto_nombre']) ?>">
        </div>
        <div class="field">
          <label>Apellido</label>
          <input type="text" name="contacto_apellido" value="<?= sanitize($data['contacto_apellido']) ?>">
        </div>
        <div class="field">
          <label>Teléfono</label>
          <input type="tel" name="contacto_telefono" inputmode="tel" value="<?= sanitize($data['contacto_telefono']) ?>">
        </div>
        <div class="field">
          <label>Email</label>
          <input type="email" name="contacto_email" inputmode="email" value="<?= sanitize($data['contacto_email']) ?>">
        </div>
        <div class="field span-2">
          <label>Cargo</label>
          <input type="text" name="contacto_cargo" value="<?= sanitize($data['contacto_cargo']) ?>">
        </div>
      </div>
    </div>

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
            <option value="activo"    <?= ($data['estado']??'')==='activo'    ?'selected':'' ?>>Activo</option>
            <option value="prospecto" <?= ($data['estado']??'')==='prospecto' ?'selected':'' ?>>Prospecto</option>
            <option value="inactivo"  <?= ($data['estado']??'')==='inactivo'  ?'selected':'' ?>>Inactivo</option>
          </select>
        </div>
        <div class="field">
          <label>Ejecutivo comercial asignado</label>
          <select name="ejecutivo_id">
            <option value="">— Sin asignar —</option>
            <?php foreach ($ejecutivos as $ej):
              $sel = ($data['ejecutivo_id'] ?? null) == $ej['id'] ? 'selected' : '';
              $roles_lbl = ['gerente_general'=>'Gerente General','gerente_operaciones'=>'Gte. Operaciones','ventas'=>'Ventas','delivery'=>'Delivery'];
              $rol_txt = $roles_lbl[$ej['rol']] ?? $ej['rol'];
            ?>
            <option value="<?= $ej['id'] ?>" <?= $sel ?>>
              <?= sanitize($ej['nombre'] . ' ' . $ej['apellido']) ?> — <?= $rol_txt ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field span-2">
          <label>Notas internas</label>
          <textarea name="notas"><?= sanitize($data['notas'] ?? '') ?></textarea>
        </div>
      </div>
    </div>

    <div class="form-actions">
      <button type="submit" class="btn btn-primary">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
        Guardar cambios
      </button>
      <a href="<?= $cancel_url ?>" class="btn btn-outline">Cancelar</a>
      <?php if (has_role('gerente_general', 'gerente_operaciones')): ?>
      <button type="button" class="btn btn-danger" style="margin-left:auto;" onclick="confirmarEliminar()">Eliminar empresa</button>
      <?php endif; ?>
    </div>
  </div>
</form>

<!-- Portal externo de seguimiento -->
<?php
$token_actual = $data['portal_token'] ?? null;
$portal_activo = (bool)($data['portal_activo'] ?? false);
if ($token_actual && $portal_activo):
    $portal_url = APP_URL . '/portal/?token=' . $token_actual;
endif;
?>
<div class="form-card" style="margin-top:20px">
  <div class="form-section">
    <div class="section-head">
      <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="#2DB140" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
      Portal de seguimiento para el cliente
    </div>
    <p style="font-size:13px;color:var(--gris-texto);margin-bottom:16px;">
      Genera un enlace único para que el cliente pueda consultar sus certificados, auditorías y envíos desde cualquier dispositivo, sin necesidad de cuenta.
    </p>

    <?php if ($token_actual && $portal_activo): ?>
    <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:14px 16px;margin-bottom:14px;">
      <div style="font-size:12px;font-weight:700;color:#15803d;margin-bottom:6px;">Portal activo</div>
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
        <input type="text" id="portal-url" value="<?= $portal_url ?>" readonly
               style="flex:1;min-width:0;padding:7px 10px;border:1.5px solid #bbf7d0;border-radius:7px;font-size:12px;font-family:monospace;background:#fff;color:#15803d;outline:none">
        <button type="button" onclick="copiarPortal()" class="btn btn-sm" style="background:#fff;border:1.5px solid #bbf7d0;color:#15803d;flex-shrink:0">
          <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
          Copiar
        </button>
        <a href="<?= $portal_url ?>" target="_blank" class="btn btn-sm" style="background:#fff;border:1.5px solid #bbf7d0;color:#15803d;flex-shrink:0;text-decoration:none">
          <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
          Ver portal
        </a>
      </div>
    </div>
    <?php endif; ?>

    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <?php if (!$token_actual || !$portal_activo): ?>
      <form method="POST" action="<?= APP_URL ?>/modules/clientes/portal_toggle.php" style="display:inline">
        <?= csrf_field() ?>
        <input type="hidden" name="cliente_id" value="<?= $data['id'] ?>">
        <input type="hidden" name="accion" value="activar">
        <button type="submit" class="btn btn-primary btn-sm">
          <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
          Activar portal
        </button>
      </form>
      <?php else: ?>
      <form method="POST" action="<?= APP_URL ?>/modules/clientes/portal_toggle.php" style="display:inline" onsubmit="return confirm('¿Desactivar el portal? El enlace actual dejará de funcionar.')">
        <?= csrf_field() ?>
        <input type="hidden" name="cliente_id" value="<?= $data['id'] ?>">
        <input type="hidden" name="accion" value="desactivar">
        <button type="submit" class="btn btn-outline btn-sm" style="color:#ef4444;border-color:#ef4444">
          Desactivar portal
        </button>
      </form>
      <form method="POST" action="<?= APP_URL ?>/modules/clientes/portal_toggle.php" style="display:inline" onsubmit="return confirm('¿Regenerar el enlace? El enlace anterior dejará de funcionar.')">
        <?= csrf_field() ?>
        <input type="hidden" name="cliente_id" value="<?= $data['id'] ?>">
        <input type="hidden" name="accion" value="regenerar">
        <button type="submit" class="btn btn-outline btn-sm">
          <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
          Regenerar enlace
        </button>
      </form>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
function copiarPortal() {
  var inp = document.getElementById('portal-url');
  inp.select();
  document.execCommand('copy');
  if (typeof showToast === 'function') showToast('Enlace copiado al portapapeles', 'ok');
}
</script>
<script>
const API_URL = '<?= APP_URL ?>/modules/clientes/ajax_ruc.php';
async function buscarRuc() {
  const ruc = document.getElementById('ruc').value.trim();
  const btn = document.getElementById('btn-ruc');
  const spinner = document.getElementById('spinner-ruc');
  const icon = document.getElementById('icon-search');
  if (ruc.length !== 11) { setStatus('err','El RUC debe tener 11 dígitos.'); return; }
  btn.disabled=true; spinner.style.display='block'; icon.style.display='none';
  setStatus('loading','Consultando SUNAT...');
  try {
    const res = await fetch(`${API_URL}?ruc=${ruc}`);
    const json = await res.json();
    if (json.ok) {
      const d = json.data;
      setField('razon_social',d.razon_social); setField('direccion',d.direccion);
      setField('departamento',d.departamento); setField('provincia',d.provincia);
      setField('distrito',d.distrito); setField('ubigeo',d.ubigeo);
      setStatus('ok','✓ '+d.razon_social+(d.estado?' — '+d.estado:''));
    } else { setStatus('err','✗ '+json.mensaje); }
  } catch(e) { setStatus('err','✗ Error de conexión.'); }
  finally { btn.disabled=false; spinner.style.display='none'; icon.style.display='block'; }
}
function setField(id,val){ const el=document.getElementById(id); if(el) el.value=val||''; }
function setStatus(type,msg){ const el=document.getElementById('ruc-status'); el.className='ruc-status '+type; el.textContent=msg; }
document.getElementById('ruc').addEventListener('keydown',e=>{ if(e.key==='Enter'){e.preventDefault();buscarRuc();} });
</script>

<!-- Modal confirmar eliminar -->
<div id="modal-eliminar" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:999;align-items:center;justify-content:center;padding:20px;">
  <div style="background:#fff;border-radius:12px;padding:28px;max-width:400px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,0.2);">
    <h3 style="font-family:Outfit,sans-serif;font-size:18px;font-weight:700;color:#1a1f36;margin-bottom:8px;">¿Eliminar empresa?</h3>
    <p style="font-size:13px;color:#6b7a99;margin-bottom:20px;">Esta acción eliminará permanentemente a <strong><?= sanitize($cliente['razon_social']) ?></strong> y todos sus documentos, comunicaciones e ISOs asociadas. No se puede deshacer.</p>
    <form method="POST" action="<?= APP_URL ?>/modules/clientes/eliminar.php">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= $id ?>">
      <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <button type="submit" class="btn btn-danger" style="flex:1;">Sí, eliminar</button>
        <button type="button" class="btn btn-outline" style="flex:1;" onclick="cerrarModal()">Cancelar</button>
      </div>
    </form>
  </div>
</div>
<script>
function confirmarEliminar() {
  const m = document.getElementById('modal-eliminar');
  m.style.display = 'flex';
}
function cerrarModal() {
  document.getElementById('modal-eliminar').style.display = 'none';
}
document.getElementById('modal-eliminar').addEventListener('click', function(e) {
  if (e.target === this) cerrarModal();
});
</script>
<script>
new GGIForm('form-editar-cliente')
  .required('ruc',          'El RUC es requerido.')
  .ruc('ruc',               'RUC inválido — debe tener 11 dígitos.')
  .required('razon_social', 'La razón social es requerida.')
  .email('contacto_email',  'Ingresa un email válido.')
  .init();
</script>
<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
<?php if (isset($_GET['editado'])): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
  showToast('Cambios guardados exitosamente.', 'ok');
});
</script>
<?php endif; ?>
