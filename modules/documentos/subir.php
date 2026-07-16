<?php
// ── Lógica PHP primero ──
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../../core/NotificationService.php';
session_init();
require_login();

$es_ajax    = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
              && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
$cliente_id = (int)($_GET['cliente_id'] ?? $_POST['cliente_id'] ?? 0);
$tipo       = $_GET['tipo'] ?? $_POST['tipo'] ?? '';

$tipos_validos = ['ficha_ruc','copia_literal','acta_alcance','voucher_inicial','voucher_final','logo'];
$tipos_labels  = [
    'ficha_ruc'      => 'Ficha RUC',
    'copia_literal'  => 'Copia Literal',
    'acta_alcance'   => 'Acta de Alcance',
    'voucher_inicial'=> 'Voucher Pago Inicial',
    'voucher_final'  => 'Voucher Pago Final',
    'logo'           => 'Logo de empresa',
];

if (!$cliente_id || !in_array($tipo, $tipos_validos)) {
    if ($es_ajax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'msg' => 'Parámetros inválidos.']);
        exit;
    }
    header('Location: ' . APP_URL . '/modules/clientes/index.php');
    exit;
}

$pdo     = Database::get();
$cliente = $pdo->prepare("SELECT id, razon_social, codigo_interno, ruc FROM clientes WHERE id = ?");
$cliente->execute([$cliente_id]);
$cliente = $cliente->fetch();
if (!$cliente) {
    if ($es_ajax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'msg' => 'Cliente no encontrado.']);
        exit;
    }
    header('Location: ' . APP_URL . '/modules/clientes/index.php');
    exit;
}

// Versión actual
$doc_actual = $pdo->prepare("SELECT * FROM documentos_cliente WHERE cliente_id = ? AND tipo_documento = ? AND es_vigente = 1");
$doc_actual->execute([$cliente_id, $tipo]);
$doc_actual     = $doc_actual->fetch();
$version_nueva  = $doc_actual ? ($doc_actual['version'] + 1) : 1;

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    // Liberar lock de sesión: ya no necesitamos escribir en ella.
    // Esto permite que otras peticiones del mismo usuario (SPA, polls) no queden bloqueadas
    // mientras el servidor recibe y guarda el archivo.
    session_write_close();
    if (empty($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Debes seleccionar un archivo válido.';
    } else {
        $archivo  = $_FILES['archivo'];
        $ext      = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
        $finfo    = new finfo(FILEINFO_MIME_TYPE);
        $mime     = $finfo->file($archivo['tmp_name']);
        $max_size = 10 * 1024 * 1024;

        if (!in_array($ext, ALLOWED_EXT)) {
            $error = 'Formato no permitido. Solo PDF, JPG, PNG o WEBP.';
        } elseif ($archivo['size'] > $max_size) {
            $error = 'El archivo supera el límite de 10MB.';
        } else {
            // Carpeta por RUC: documentaciones_clientes/{ruc}/
            $carpeta_base = rtrim(UPLOAD_PATH, '/') . '/' . $cliente['ruc'] . '/';
            if (!is_dir($carpeta_base)) {
                mkdir($carpeta_base, 0755, true);
                // Proteger la carpeta
                file_put_contents($carpeta_base . '.htaccess',
                    "Options -Indexes\n<FilesMatch \"\\.php$\">\nDeny from all\n</FilesMatch>\n"
                );
            }

            // Nombre de archivo: {empresa}_{tipo}_v{version}.ext
            $empresa_slug   = preg_replace('/\s+/', '_', trim($cliente['razon_social']));
            $empresa_slug   = preg_replace('/[^A-Za-z0-9_\-]/', '', $empresa_slug);
            $empresa_slug   = strtoupper(substr($empresa_slug, 0, 40));
            $nombre_archivo = $empresa_slug . '_' . $tipo . '_v' . $version_nueva . '.' . $ext;
            $ruta_completa  = $carpeta_base . $nombre_archivo;
            $ruta_relativa  = $cliente['ruc'] . '/' . $nombre_archivo;

            if (move_uploaded_file($archivo['tmp_name'], $ruta_completa)) {
                // ── Compresión de imágenes (JPG / PNG / WEBP) ──────────────────────────
                // Reduce el peso de fotos grandes hasta un 80–90 % sin pérdida visual
                // notable. Los PDF se dejan intactos. Se aplica siempre que GD esté
                // disponible; si falla, se usa el archivo original sin interrumpir.
                $tamano_final = $archivo['size'];
                if (function_exists('imagecreatefromjpeg') && in_array($ext, ['jpg','jpeg','png','webp'])) {
                    try {
                        $im = match ($ext) {
                            'jpg', 'jpeg' => @imagecreatefromjpeg($ruta_completa),
                            'png'         => @imagecreatefrompng($ruta_completa),
                            'webp'        => @imagecreatefromwebp($ruta_completa),
                            default       => false,
                        };
                        if ($im !== false) {
                            $ow = imagesx($im);
                            $oh = imagesy($im);
                            $max = 1200; // px máximos en el lado más largo

                            // Redimensionar solo si supera el límite
                            if ($ow > $max || $oh > $max) {
                                $ratio  = $ow / $oh;
                                $nw = $ow > $oh ? $max : (int)round($max * $ratio);
                                $nh = $oh > $ow ? $max : (int)round($max / $ratio);
                                $dest = imagecreatetruecolor($nw, $nh);
                                // Preservar transparencia en PNG
                                if ($ext === 'png') {
                                    imagealphablending($dest, false);
                                    imagesavealpha($dest, true);
                                    $trans = imagecolorallocatealpha($dest, 0, 0, 0, 127);
                                    imagefilledrectangle($dest, 0, 0, $nw, $nh, $trans);
                                }
                                imagecopyresampled($dest, $im, 0, 0, 0, 0, $nw, $nh, $ow, $oh);
                                imagedestroy($im);
                                $im = $dest;
                            }

                            // Guardar con calidad reducida (sobrescribe el archivo original)
                            $ok_save = match ($ext) {
                                'jpg', 'jpeg' => imagejpeg($im, $ruta_completa, 85),
                                'png'         => imagepng($im, $ruta_completa, 6),   // 0–9; 6 ≈ buen equilibrio
                                'webp'        => imagewebp($im, $ruta_completa, 85),
                                default       => false,
                            };
                            imagedestroy($im);
                            if ($ok_save) {
                                $tamano_final = filesize($ruta_completa);
                            }
                        }
                    } catch (\Throwable) {
                        // La compresión falló; el archivo original ya está guardado → continuar
                    }
                }

                // Marcar versión anterior como no vigente
                if ($doc_actual) {
                    $pdo->prepare("UPDATE documentos_cliente SET es_vigente = 0 WHERE id = ?")
                        ->execute([$doc_actual['id']]);
                }

                // Insertar nuevo registro
                $stmt = $pdo->prepare("
                    INSERT INTO documentos_cliente
                      (cliente_id, tipo_documento, nombre_archivo, ruta_archivo, mime_type, tamano_bytes, version, es_vigente, subido_por)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)
                ");
                $user_doc = auth_user();
                $stmt->execute([
                    $cliente_id,
                    $tipo,
                    $nombre_archivo,
                    $ruta_relativa,
                    $mime,
                    $tamano_final,
                    $version_nueva,
                    $user_doc['id'],
                ]);
                $new_id = (int)$pdo->lastInsertId();

                // Documentación completa = Ficha RUC vigente + Acta de Alcance aprobada.
                // Al subir la Ficha RUC, verificar si el Acta ya está aprobada → disparar.
                if ($tipo === 'ficha_ruc') {
                    require_once __DIR__ . '/../../core/doc_completa.php';
                    gg_verificar_documentacion_completa($pdo, (int)$cliente_id, (int)$user_doc['id']);
                }

                log_actividad('documentos', 'subir', "Documento $tipo v{$version_nueva} para cliente {$cliente['ruc']}");
                if ($es_ajax) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode([
                        'ok'             => true,
                        'msg'            => 'Documento subido correctamente.',
                        'nombre_archivo' => $nombre_archivo,
                        'version'        => $version_nueva,
                        'doc_id'         => $new_id,
                    ]);
                    exit;
                }
                header('Location: ' . APP_URL . '/modules/clientes/ver.php?id=' . $cliente_id . '&doc_ok=1');
                exit;
            } else {
                $error = 'Error al guardar el archivo. Verifica permisos de la carpeta.';
            }
        }
    }
}

// Petición AJAX que llegó con error (no salió antes con JSON)
if ($es_ajax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'msg' => $error ?: 'Error desconocido.']);
    exit;
}

$page_title  = 'Subir Documento';
$active_menu = 'clientes';
$user        = auth_user();
require_once __DIR__ . '/../../templates/layout.php';
?>

<style>
  .upload-card { background:var(--bg-surface); border-radius:12px; border:1px solid var(--gris-borde); overflow:hidden; max-width:600px; width:100%; }
  .upload-header { padding:20px 24px; border-bottom:1px solid var(--gris-borde); }
  .upload-header h2 { font-family:'Inter',sans-serif; font-size:16px; font-weight:700; color:var(--azul); }
  .upload-header p { font-size:13px; color:var(--gris-texto); margin-top:4px; }
  .upload-body { padding:24px; }
  .upload-actions { padding:16px 24px; background:var(--bg-surface2); border-top:1px solid var(--gris-borde); display:flex; gap:10px; flex-wrap:wrap; }

  /* Drop zone */
  .drop-zone {
    border:2px dashed var(--gris-borde); border-radius:12px;
    padding:40px 24px; text-align:center; cursor:pointer;
    transition:border-color 0.2s, background 0.2s;
    position:relative;
  }
  .drop-zone:hover, .drop-zone.dragover {
    border-color:var(--azul); background:var(--azul-light);
  }
  .drop-zone input[type=file] {
    position:absolute; inset:0; opacity:0; cursor:pointer; width:100%; height:100%;
  }
  .drop-icon { margin-bottom:12px; color:var(--azul); opacity:0.6; }
  .drop-title { font-size:15px; font-weight:600; color:var(--gris-dark); margin-bottom:4px; }
  .drop-sub { font-size:12px; color:var(--gris-texto); }
  .drop-formatos { margin-top:8px; font-size:11px; color:var(--gris-texto); }

  /* Preview del archivo seleccionado */
  .file-preview {
    display:none; margin-top:16px; padding:12px 16px;
    background:var(--s-ok-bg); border:1px solid #b7e4c0; border-radius:9px;
    display:none; align-items:center; gap:12px;
  }
  .file-preview.show { display:flex; }
  .file-preview-icon { width:36px; height:36px; background:var(--verde); border-radius:8px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
  .file-preview-info { flex:1; min-width:0; }
  .file-preview-name { font-size:13px; font-weight:600; color:var(--gris-dark); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .file-preview-size { font-size:11px; color:var(--gris-texto); margin-top:2px; }
  .file-preview-remove { cursor:pointer; color:var(--gris-texto); background:none; border:none; padding:4px; flex-shrink:0; }
  .file-preview-remove:hover { color:var(--red); }

  /* Versión anterior */
  .version-info { background:var(--s-warn-bg); border:1px solid #fde68a; border-radius:9px; padding:12px 16px; margin-bottom:20px; font-size:13px; color:var(--s-warn-tx); display:flex; align-items:center; gap:8px; }

  .alert-error { background:var(--s-err-bg); border:1px solid var(--s-err-brd); border-left:4px solid var(--red); padding:12px 16px; border-radius:8px; font-size:13px; color:var(--s-err-tx); margin-bottom:16px; display:flex; align-items:center; gap:8px; }

  @media (max-width:640px) {
    .upload-body { padding:16px; }
    .upload-actions { flex-direction:column; }
    .upload-actions .btn { width:100%; justify-content:center; }
  }
</style>

<?php ggi_breadcrumb([
    ['label' => 'Empresas', 'href' => APP_URL.'/modules/clientes/index.php', 'ss' => 'ggi_ss_clientes'],
    ['label' => sanitize($cliente['razon_social']), 'href' => APP_URL.'/modules/clientes/ver.php?id='.(int)$cliente_id],
    ['label' => 'Documentos'],
    ['label' => 'Subir documento'],
]); ?>
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
  <div>
    <h1>Subir documento</h1>
    <p><?= sanitize($cliente['razon_social']) ?> — <code style="font-size:12px;color:var(--azul);"><?= sanitize($cliente['ruc']) ?></code></p>
  </div>
</div>

<div class="upload-card">
  <div class="upload-header">
    <h2><?= $tipos_labels[$tipo] ?></h2>
    <p>
      <?php if ($doc_actual): ?>
        Versión actual: <strong>v<?= $doc_actual['version'] ?></strong> — <?= $doc_actual['nombre_archivo'] ?>.
        Al subir este archivo se guardará como <strong>v<?= $version_nueva ?></strong>.
      <?php else: ?>
        Primera carga de este documento.
      <?php endif; ?>
    </p>
  </div>

  <div class="upload-body">

    <?php if ($doc_actual): ?>
    <div class="version-info">
      <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      Ya existe una versión de este documento. La versión anterior quedará guardada en el historial.
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="alert-error">
      <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      <?= sanitize($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" id="form-upload">
      <?= csrf_field() ?>

      <div class="drop-zone" id="drop-zone">
        <input type="file" name="archivo" id="archivo" accept=".pdf,.jpg,.jpeg,.png,.webp" onchange="previewFile(this)">
        <div class="drop-icon">
          <svg width="40" height="40" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
            <polyline points="17 8 12 3 7 8"/>
            <line x1="12" y1="3" x2="12" y2="15"/>
          </svg>
        </div>
        <div class="drop-title">Arrastra el archivo aquí o haz clic para seleccionar</div>
        <div class="drop-sub">Se guardará en: <code>documentaciones_clientes/<?= $cliente['ruc'] ?>/</code></div>
        <div class="drop-formatos">Formatos permitidos: PDF, JPG, PNG, WEBP — Máximo 10MB</div>
      </div>

      <div class="file-preview" id="file-preview">
        <div class="file-preview-icon">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="#fff" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        </div>
        <div class="file-preview-info">
          <div class="file-preview-name" id="preview-name"></div>
          <div class="file-preview-size" id="preview-size"></div>
        </div>
        <button type="button" class="file-preview-remove" onclick="removeFile()" title="Quitar archivo">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>

  </div>

  <div class="upload-actions">
    <button type="submit" class="btn btn-primary" id="btn-subir" disabled form="form-upload">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
      Subir documento
    </button>
    <a href="<?= APP_URL ?>/modules/clientes/ver.php?id=<?= $cliente_id ?>" class="btn btn-outline">Cancelar</a>
  </div>

    </form>
</div>

<script>
var dropZone = document.getElementById('drop-zone');
var _dzDepth = 0; // contador para que dragleave de elementos hijo no quite el estado

dropZone.addEventListener('dragenter', function(e) {
  e.preventDefault();
  _dzDepth++;
  dropZone.classList.add('dragover');
});
dropZone.addEventListener('dragleave', function() {
  _dzDepth--;
  if (_dzDepth <= 0) { _dzDepth = 0; dropZone.classList.remove('dragover'); }
});
dropZone.addEventListener('dragover', function(e) { e.preventDefault(); });
dropZone.addEventListener('drop', function(e) {
  e.preventDefault();
  _dzDepth = 0;
  dropZone.classList.remove('dragover');
  var file = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
  if (file) {
    // Asignar al input via DataTransfer para que el form POST lo incluya
    try {
      var dt = new DataTransfer();
      dt.items.add(file);
      document.getElementById('archivo').files = dt.files;
    } catch(x) {
      // Fallback: asignación directa (Chrome/Edge/Firefox soportan esto)
      try { document.getElementById('archivo').files = e.dataTransfer.files; } catch(y) {}
    }
    showPreview(file);
  }
});

function previewFile(input) {
  if (input.files && input.files[0]) showPreview(input.files[0]);
}

function showPreview(file) {
  document.getElementById('preview-name').textContent = file.name;
  document.getElementById('preview-size').textContent = formatSize(file.size);
  document.getElementById('file-preview').classList.add('show');
  document.getElementById('btn-subir').disabled = false;
  dropZone.style.display = 'none';
}

function removeFile() {
  document.getElementById('archivo').value = '';
  document.getElementById('file-preview').classList.remove('show');
  document.getElementById('btn-subir').disabled = true;
  dropZone.style.display = 'block';
}

function formatSize(bytes) {
  if (bytes < 1024) return bytes + ' B';
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
  return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
}
</script>

<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
