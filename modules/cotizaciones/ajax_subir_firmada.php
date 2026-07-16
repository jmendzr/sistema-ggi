<?php
/**
 * ajax_subir_firmada.php — Subida de propuesta firmada a cotizacion_archivos
 */
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();

header('Content-Type: application/json; charset=utf-8');

function _json(array $d): void { echo json_encode($d); exit; }

if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    _json(['ok' => false, 'msg' => 'Acceso denegado.']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    _json(['ok' => false, 'msg' => 'Método no permitido.']);
}
csrf_verify();

$user = auth_user();
$roles_permitidos = ['gerente_general','gerente_operaciones','ejecutivo_ventas'];
if (!in_array($user['rol'], $roles_permitidos)) {
    _json(['ok' => false, 'msg' => 'Sin permiso.']);
}

$cotizacion_id = (int)($_POST['cotizacion_id'] ?? 0);
if (!$cotizacion_id) { _json(['ok' => false, 'msg' => 'Propuesta no especificada.']); }

$pdo = Database::get();

// Verificar que la propuesta existe y obtener datos del cliente
$stmt = $pdo->prepare("
    SELECT cot.id, cot.numero,
           COALESCE(cl.ruc, cot.empresa_ruc) AS ruc,
           COALESCE(cl.razon_social, cot.empresa_nombre) AS razon_social
    FROM cotizaciones cot
    LEFT JOIN clientes cl ON cl.id = cot.cliente_id
    WHERE cot.id = ?
");
$stmt->execute([$cotizacion_id]);
$cot = $stmt->fetch();
if (!$cot) { _json(['ok' => false, 'msg' => 'Propuesta no encontrada.']); }

// Validar archivo
if (empty($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
    _json(['ok' => false, 'msg' => 'Debes seleccionar un archivo válido.']);
}

$archivo  = $_FILES['archivo'];
$ext      = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
$finfo    = new finfo(FILEINFO_MIME_TYPE);
$mime     = $finfo->file($archivo['tmp_name']);

if (!in_array($ext, ['pdf','jpg','jpeg','png','webp'])) {
    _json(['ok' => false, 'msg' => 'Formato no permitido. Solo PDF, JPG, PNG o WEBP.']);
}
if ($archivo['size'] > 10 * 1024 * 1024) {
    _json(['ok' => false, 'msg' => 'El archivo supera el límite de 10 MB.']);
}

// Determinar versión
$ver_stmt = $pdo->prepare("SELECT COALESCE(MAX(version), 0) + 1 FROM cotizacion_archivos WHERE cotizacion_id = ? AND tipo = 'firmada'");
$ver_stmt->execute([$cotizacion_id]);
$version_nueva = (int)$ver_stmt->fetchColumn();

// Carpeta: documentaciones_clientes/{ruc}/firmadas/
$ruc_safe = preg_replace('/[^0-9]/', '', $cot['ruc']);
$carpeta  = rtrim(UPLOAD_PATH, '/') . '/' . ($ruc_safe ?: 'sin_ruc') . '/firmadas/';
if (!is_dir($carpeta)) {
    mkdir($carpeta, 0755, true);
    file_put_contents($carpeta . '.htaccess',
        "Options -Indexes\n<FilesMatch \"\\.php$\">\nDeny from all\n</FilesMatch>\n"
    );
}

// Nombre de archivo: {COT-XXXX}_firmada_v{n}.ext
$cot_slug      = preg_replace('/[^A-Za-z0-9\-]/', '_', $cot['numero']);
$nombre_archivo = $cot_slug . '_firmada_v' . $version_nueva . '.' . $ext;
$ruta_completa  = $carpeta . $nombre_archivo;
$ruta_relativa  = ($ruc_safe ?: 'sin_ruc') . '/firmadas/' . $nombre_archivo;

if (!move_uploaded_file($archivo['tmp_name'], $ruta_completa)) {
    _json(['ok' => false, 'msg' => 'Error al guardar el archivo. Verifica permisos.']);
}

// Insertar en cotizacion_archivos
$ins = $pdo->prepare("
    INSERT INTO cotizacion_archivos
      (cotizacion_id, tipo, nombre_archivo, ruta_archivo, mime_type, tamano_bytes, version, subido_por)
    VALUES (?, 'firmada', ?, ?, ?, ?, ?, ?)
");
$ins->execute([
    $cotizacion_id,
    $nombre_archivo,
    $ruta_relativa,
    $mime,
    $archivo['size'],
    $version_nueva,
    $user['id'],
]);
$new_id = (int)$pdo->lastInsertId();

log_actividad('propuestas', 'subir_firmada', "Propuesta firmada v{$version_nueva} para {$cot['numero']}");

_json([
    'ok'             => true,
    'msg'            => 'Propuesta firmada adjuntada correctamente.',
    'nombre_archivo' => $nombre_archivo,
    'ruta_archivo'   => $ruta_relativa,
    'version'        => $version_nueva,
    'archivo_id'     => $new_id,
    'created_at'     => date('d/m/Y H:i'),
    'url'            => rtrim(UPLOAD_URL, '/') . '/' . $ruta_relativa,
]);
