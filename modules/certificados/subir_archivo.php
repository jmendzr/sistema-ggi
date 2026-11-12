<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();
require_role('gerente_general','gerente_operaciones','administracion_finanzas');

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . APP_URL . '/modules/certificados/index.php'); exit; }

$pdo  = Database::get();
$stmt = $pdo->prepare("SELECT c.*, cl.ruc, cl.razon_social FROM certificados c JOIN clientes cl ON cl.id=c.cliente_id WHERE c.id=?");
$stmt->execute([$id]);
$cert = $stmt->fetch();
if (!$cert) { header('Location: ' . APP_URL . '/modules/certificados/index.php'); exit; }

$user  = auth_user();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (empty($_FILES['certificado_archivo']['name']) || $_FILES['certificado_archivo']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Selecciona un archivo válido.';
    } else {
        $archivo = $_FILES['certificado_archivo'];
        $ext     = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['pdf','jpg','jpeg','png','webp'])) {
            $error = 'Formato no permitido. Solo PDF, JPG, PNG, WEBP.';
        } elseif ($archivo['size'] > 10 * 1024 * 1024) {
            $error = 'El archivo supera 10MB.';
        } else {
            $nombre_limpio = preg_replace('/[^a-zA-Z0-9\-_\.]/', '_', $archivo['name']);
            $carpeta = rtrim(UPLOAD_PATH, '/') . '/' . $cert['ruc'] . '/certificados/';
            if (!is_dir($carpeta)) {
                mkdir($carpeta, 0755, true);
                file_put_contents($carpeta . '.htaccess', "Options -Indexes\n<FilesMatch \"\\.php$\">\nDeny from all\n</FilesMatch>\n");
            }
            if (move_uploaded_file($archivo['tmp_name'], $carpeta . $nombre_limpio)) {
                $ruta = $cert['ruc'] . '/certificados/' . $nombre_limpio;
                $pdo->prepare("UPDATE certificados SET certificado_archivo=? WHERE id=?")->execute([$ruta, $id]);
                log_actividad('certificados', 'subir_archivo', 'Archivo actualizado cert ID: ' . $id);
                header('Location: ' . APP_URL . '/modules/certificados/ver.php?id=' . $id . '&editado=1');
                exit;
            } else {
                $error = 'Error al guardar el archivo. Verifica permisos.';
            }
        }
    }
}

$page_title  = 'Subir Certificado';
$active_menu = 'certificados';
require_once __DIR__ . '/../../templates/layout.php';
?>
<style>
  .upload-card { background:var(--bg-surface); border-radius:12px; border:1px solid var(--gris-borde); overflow:hidden; max-width:560px; }
  .upload-header { padding:18px 22px; border-bottom:1px solid var(--gris-borde); }
  .upload-header h2 { font-family:'Inter',sans-serif; font-size:15px; font-weight:700; color:var(--azul); }
  .upload-header p { font-size:13px; color:var(--gris-texto); margin-top:3px; }
  .upload-body { padding:22px; }
  .upload-actions { padding:16px 22px; background:var(--bg-surface2); border-top:1px solid var(--gris-borde); display:flex; gap:10px; }
  .drop-zone { border:2px dashed var(--gris-borde); border-radius:10px; padding:32px 20px; text-align:center; cursor:pointer; position:relative; transition:all 0.2s; }
  .drop-zone:hover { border-color:var(--azul); background:var(--azul-light); }
  .drop-zone input { position:absolute; inset:0; opacity:0; cursor:pointer; width:100%; height:100%; }
  .file-preview { display:none; margin-top:12px; padding:10px 14px; background:var(--s-ok-bg); border:1px solid #b7e4c0; border-radius:8px; align-items:center; gap:10px; }
  .file-preview.show { display:flex; }
</style>

<?php ggi_breadcrumb([
    ['label' => 'Empresas',     'href' => APP_URL.'/modules/clientes/index.php',    'ss' => 'ggi_ss_clientes'],
    ['label' => sanitize($cert['razon_social']), 'href' => APP_URL.'/modules/clientes/ver.php?id='.(int)$cert['cliente_id']],
    ['label' => 'Certificados', 'href' => APP_URL.'/modules/certificados/ver.php?id='.$id],
    ['label' => 'Actualizar adjunto'],
]); ?>
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
  <h1>Actualizar certificado adjunto</h1>
</div>

<div class="upload-card">
  <div class="upload-header">
    <h2><?= sanitize($cert['razon_social']) ?></h2>
    <p><?= $cert['numero_certificado'] ? sanitize($cert['numero_certificado']) : 'Sin número' ?> — <?= sanitize($cert['ruc']) ?></p>
  </div>
  <div class="upload-body">
    <?php if ($error): ?>
    <div style="background:var(--s-err-bg);border:1px solid var(--s-err-brd);border-left:4px solid var(--red);padding:11px 14px;border-radius:8px;font-size:13px;color:var(--s-err-tx);margin-bottom:14px;"><?= sanitize($error) ?></div>
    <?php endif; ?>
    <form method="POST" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <div class="drop-zone" id="dz">
        <input type="file" name="certificado_archivo" id="cert-file" accept=".pdf,.jpg,.jpeg,.png,.webp" onchange="prev(this)">
        <svg width="32" height="32" fill="none" viewBox="0 0 24 24" stroke="var(--azul)" stroke-width="1.5" style="margin-bottom:8px;opacity:0.6"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
        <div style="font-size:14px;font-weight:600;color:var(--gris-dark);margin-bottom:4px;">Arrastra el certificado o haz clic</div>
        <div style="font-size:12px;color:var(--gris-texto);">PDF, JPG, PNG, WEBP — Máximo 10MB</div>
        <div style="font-size:11px;color:var(--azul);margin-top:4px;"><code>documentaciones_clientes/<?= $cert['ruc'] ?>/certificados/</code></div>
      </div>
      <div class="file-preview" id="fp">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="#2DB140" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        <span id="fn" style="font-size:13px;font-weight:600;flex:1;"></span>
        <span id="fs" style="font-size:11px;color:var(--gris-texto);"></span>
        <button type="button" onclick="quitar()" style="background:none;border:none;cursor:pointer;color:var(--gris-texto);">✕</button>
      </div>
  </div>
  <div class="upload-actions">
    <button type="submit" class="btn btn-primary" id="btn-sub" disabled>
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
      Subir certificado
    </button>
    <a href="<?= APP_URL ?>/modules/certificados/ver.php?id=<?= $id ?>" class="btn btn-outline">Cancelar</a>
  </div>
    </form>
</div>

<script>
function prev(input) {
  if (!input.files[0]) return;
  const f = input.files[0];
  document.getElementById('fn').textContent = f.name;
  const kb = (f.size/1024).toFixed(1);
  document.getElementById('fs').textContent = kb > 1024 ? (kb/1024).toFixed(1)+' MB' : kb+' KB';
  document.getElementById('fp').classList.add('show');
  document.getElementById('dz').style.display = 'none';
  document.getElementById('btn-sub').disabled = false;
}
function quitar() {
  document.getElementById('cert-file').value = '';
  document.getElementById('fp').classList.remove('show');
  document.getElementById('dz').style.display = 'block';
  document.getElementById('btn-sub').disabled = true;
}
</script>
<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
