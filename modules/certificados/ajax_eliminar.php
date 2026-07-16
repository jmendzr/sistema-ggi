<?php
/**
 * Eliminación lógica de certificados (soft delete via deleted_at).
 * NO restringe por estado — cualquier estado puede eliminarse.
 * POST preview=1  → devuelve info de dependencias sin eliminar
 * POST preview=0  → elimina definitivamente
 */
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();
header('Content-Type: application/json');

if (!has_role('gerente_general', 'gerente_operaciones')) {
    echo json_encode(['ok' => false, 'msg' => 'Sin permiso para eliminar certificados.']);
    exit;
}

csrf_verify();

$pdo     = Database::get();
$cert_id = (int)($_POST['cert_id'] ?? 0);
$preview = (int)($_POST['preview'] ?? 0);
$user    = auth_user();

if (!$cert_id) {
    echo json_encode(['ok' => false, 'msg' => 'ID de certificado inválido.']);
    exit;
}

$stmt = $pdo->prepare("
    SELECT c.*, cl.razon_social, n.codigo AS norma_codigo
    FROM certificados c
    LEFT JOIN clientes cl  ON cl.id = c.cliente_id
    LEFT JOIN normas_iso n ON n.id  = c.norma_iso_id
    WHERE c.id = ? AND c.deleted_at IS NULL
");
$stmt->execute([$cert_id]);
$cert = $stmt->fetch();

if (!$cert) {
    echo json_encode(['ok' => false, 'msg' => 'Certificado no encontrado o ya fue eliminado.']);
    exit;
}

// Dependencias (informativas — no bloquean)
$q = $pdo->prepare("SELECT COUNT(*) FROM auditorias          WHERE certificado_id = ?"); $q->execute([$cert_id]); $n_aud  = (int)$q->fetchColumn();
$q = $pdo->prepare("SELECT COUNT(*) FROM envios_certificados WHERE certificado_id = ?"); $q->execute([$cert_id]); $n_env  = (int)$q->fetchColumn();

if ($preview) {
    $deps = [];
    if ($n_aud) $deps[] = "$n_aud auditoría(s) registrada(s)";
    if ($n_env) $deps[] = "$n_env envío(s) vinculado(s)";

    $labels = [
        'vigente'=>'Vigente','por_vencer'=>'Por vencer','vencido'=>'Vencido',
        'en_proceso'=>'En proceso','suspendido'=>'Suspendido',
    ];
    echo json_encode([
        'ok'      => true,
        'preview' => true,
        'numero'  => $cert['numero_certificado'] ?: "Certificado #{$cert_id}",
        'norma'   => $cert['norma_codigo'] ?? '—',
        'estado'  => $labels[$cert['estado']] ?? $cert['estado'],
        'empresa' => $cert['razon_social'] ?? '—',
        'deps'    => $deps,
        'hay_deps'=> !empty($deps),
    ]);
    exit;
}

// Eliminación lógica — sin importar el estado
$pdo->prepare("
    UPDATE certificados SET deleted_at = NOW(), deleted_by = ?
    WHERE id = ? AND deleted_at IS NULL
")->execute([$user['id'], $cert_id]);

log_actividad('certificados', 'eliminar',
    "Certificado #{$cert_id} ({$cert['numero_certificado']}) eliminado (estado: {$cert['estado']}, empresa: " . ($cert['razon_social'] ?? '—') . ")");

echo json_encode(['ok' => true, 'msg' => 'Certificado eliminado correctamente.']);
