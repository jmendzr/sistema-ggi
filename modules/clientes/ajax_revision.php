<?php
/**
 * modules/clientes/ajax_revision.php
 * Marca un cliente como revisado por el gerente general.
 */
require_once __DIR__ . '/../../core/helpers.php';
session_init();
require_login();
require_role('gerente_general');
csrf_verify();

$pdo = Database::get();
$id  = (int)($_POST['cliente_id'] ?? 0);
if (!$id) json_error('ID de cliente inválido.');

// Verificar que el cliente existe
$row = $pdo->prepare("SELECT id, razon_social FROM clientes WHERE id = ?");
$row->execute([$id]);
$c = $row->fetch();
if (!$c) json_error('Cliente no encontrado.');

// Marcar como revisado
$pdo->prepare("UPDATE clientes SET revision_gerente = 'revisado' WHERE id = ?")
    ->execute([$id]);

// Marcar como leídas las notificaciones de tipo cliente_nuevo asociadas a este cliente
$uid     = auth_user()['id'];
$url_pat = '%/clientes/ver.php?id=' . $id;
$pdo->prepare("
    UPDATE notificaciones
    SET leida = 1
    WHERE usuario_id = ? AND tipo = 'cliente_nuevo' AND url_accion LIKE ? AND leida = 0
")->execute([$uid, $url_pat]);

log_actividad('clientes', 'revision_gerente', 'Cliente revisado: ' . $c['razon_social']);
json_ok(null, 'Cliente marcado como revisado.');
