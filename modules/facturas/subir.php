<?php
/**
 * modules/facturas/subir.php — AJAX: sube o reemplaza una factura PDF.
 *
 * POST params:
 *   cliente_id      (int)  requerido
 *   cotizacion_id   (int)  requerido
 *   factura_id      (int)  opcional — si se envía, actualiza en vez de insertar
 *   factura         (file) requerido — PDF ≤ 10 MB
 */

require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../../core/NotificationService.php';

session_init();
require_login();
require_role('gerente_general', 'gerente_operaciones', 'administracion_finanzas');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'msg' => 'Método no permitido']); exit;
}

csrf_verify();

$cliente_id    = (int)($_POST['cliente_id']    ?? 0);
$cotizacion_id = (int)($_POST['cotizacion_id'] ?? 0) ?: null;
$factura_id    = (int)($_POST['factura_id']    ?? 0) ?: null; // para actualizar
$user          = auth_user();

// ── Validaciones básicas ──────────────────────────────────────────────────────
if (!$cliente_id) {
    echo json_encode(['ok' => false, 'msg' => 'Cliente no especificado']); exit;
}

$file_requerido = !$factura_id || !empty($_FILES['factura']['name']);

if ($file_requerido) {
    if (empty($_FILES['factura']) || $_FILES['factura']['error'] !== UPLOAD_ERR_OK) {
        $errores = [1=>'Archivo muy grande',2=>'Archivo muy grande',3=>'Subida incompleta',4=>'No se recibió archivo'];
        $msg = $errores[$_FILES['factura']['error'] ?? 4] ?? 'Error al recibir el archivo';
        echo json_encode(['ok' => false, 'msg' => $msg]); exit;
    }

    $file = $_FILES['factura'];
    $mime = mime_content_type($file['tmp_name']);
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if ($ext !== 'pdf' || !in_array($mime, ['application/pdf','application/x-pdf','application/octet-stream'])) {
        echo json_encode(['ok' => false, 'msg' => 'Solo se permiten archivos PDF']); exit;
    }
    if ($file['size'] > 10 * 1024 * 1024) {
        echo json_encode(['ok' => false, 'msg' => 'El archivo supera el límite de 10 MB']); exit;
    }
} else {
    $file = null;
}

$pdo = Database::get();

// ── Verificar cliente y cotización ───────────────────────────────────────────
$cli = $pdo->prepare("SELECT razon_social FROM clientes WHERE id = ? AND deleted_at IS NULL");
$cli->execute([$cliente_id]);
$cliente = $cli->fetch(\PDO::FETCH_ASSOC);
if (!$cliente) {
    echo json_encode(['ok' => false, 'msg' => 'Cliente no encontrado']); exit;
}

$cot_numero = '';
if ($cotizacion_id) {
    $cq = $pdo->prepare("SELECT numero FROM cotizaciones WHERE id = ?");
    $cq->execute([$cotizacion_id]);
    $cot_numero = (string)($cq->fetchColumn() ?: '');
}

// ── Límite de 2 facturas por propuesta (solo en inserción) ───────────────────
if (!$factura_id && $cotizacion_id) {
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM facturas_cliente WHERE cliente_id=? AND cotizacion_id=?");
    $cnt->execute([$cliente_id, $cotizacion_id]);
    if ((int)$cnt->fetchColumn() >= 2) {
        echo json_encode(['ok' => false, 'msg' => 'Máximo 2 facturas por propuesta. Actualiza una existente si necesitas reemplazarla.']); exit;
    }
}

// ── Si es actualización, verificar que la factura pertenece al cliente ────────
$factura_anterior = null;
if ($factura_id) {
    $fq = $pdo->prepare("SELECT * FROM facturas_cliente WHERE id=? AND cliente_id=?");
    $fq->execute([$factura_id, $cliente_id]);
    $factura_anterior = $fq->fetch(\PDO::FETCH_ASSOC);
    if (!$factura_anterior) {
        echo json_encode(['ok' => false, 'msg' => 'Factura no encontrada']); exit;
    }
}

// ── Guardar archivo ───────────────────────────────────────────────────────────
$dir_base = '/home/groupglo/domains/sistema.groupglobaliso.com/public_html/documentaciones_clientes/facturas/';
$dir      = $dir_base . $cliente_id . '/';
if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
    echo json_encode(['ok' => false, 'msg' => 'Error al crear directorio de almacenamiento']); exit;
}

if ($file) {
    $nombre_base   = pathinfo($file['name'], PATHINFO_FILENAME);
    $nombre_seguro = preg_replace('/[^A-Za-z0-9\-_]/', '_', $nombre_base);
    $filename      = $nombre_seguro . '_' . date('Ymd_His') . '.pdf';
    $filepath      = $dir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        echo json_encode(['ok' => false, 'msg' => 'Error al guardar el archivo']); exit;
    }

    $ruta_relativa = 'documentaciones_clientes/facturas/' . $cliente_id . '/' . $filename;
    $archivo_nombre = $file['name'];

    // Eliminar archivo anterior si es actualización
    if ($factura_anterior) {
        $ruta_old = '/home/groupglo/domains/sistema.groupglobaliso.com/public_html/' . $factura_anterior['archivo_path'];
        if (file_exists($ruta_old)) @unlink($ruta_old);
    }
} else {
    // Sin archivo nuevo: conservar el existente
    $ruta_relativa  = $factura_anterior['archivo_path'];
    $archivo_nombre = $factura_anterior['archivo_nombre'];
}

// ── Insertar o actualizar en DB ───────────────────────────────────────────────
if ($factura_id) {
    $pdo->prepare("
        UPDATE facturas_cliente
           SET archivo_path = ?, archivo_nombre = ?, wa_enviado = 0, wa_enviado_at = NULL, subido_por = ?
         WHERE id = ?
    ")->execute([$ruta_relativa, $archivo_nombre, $user['id'], $factura_id]);
    $id_resultado = $factura_id;
    $accion = 'actualizada';
} else {
    $pdo->prepare("
        INSERT INTO facturas_cliente (cliente_id, cotizacion_id, numero_factura, archivo_path, archivo_nombre, subido_por)
        VALUES (?, ?, NULL, ?, ?, ?)
    ")->execute([$cliente_id, $cotizacion_id, $ruta_relativa, $archivo_nombre, $user['id']]);
    $id_resultado = (int)$pdo->lastInsertId();
    $accion = 'cargada';
}

// ── Disparar notificación WhatsApp con PDF adjunto ───────────────────────────
$doc_url      = APP_URL . '/' . $ruta_relativa;
$doc_filename = $archivo_nombre;

NotificationService::dispatch('factura_emitida', [
    'id'            => $id_resultado,
    'cliente_id'    => $cliente_id,
    'cotizacion_id' => $cotizacion_id,
    'cot_numero'    => $cot_numero,
    'triggered_by'  => $user['id'],
    'title'         => "Factura emitida — {$cliente['razon_social']}",
    'message'       => "Se {$accion} una factura para {$cliente['razon_social']}.",
    'doc_url'       => $doc_url,
    'doc_filename'  => $doc_filename,
]);

$pdo->prepare("UPDATE facturas_cliente SET wa_enviado = 1, wa_enviado_at = NOW() WHERE id = ?")->execute([$id_resultado]);

// La factura ya fue cargada → limpiar la notificación interna "factura por cargar".
NotificationService::markResolved(['factura_por_cargar'], (int)$cliente_id);

log_actividad('facturas', $factura_id ? 'actualizar' : 'subir', "Factura {$accion} para cliente #{$cliente_id} — {$archivo_nombre}");

echo json_encode([
    'ok'        => true,
    'msg'       => "Factura {$accion}. El cliente será notificado por WhatsApp.",
    'factura_id'=> $id_resultado,
]);
