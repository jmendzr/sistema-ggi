<?php
/**
 * modules/ajax_restaurar.php — "Deshacer" de eliminaciones (soft-delete)
 *
 * Revierte un borrado lógico reciente. Reglas de seguridad:
 *  - Solo módulos de la whitelist (tabla derivada, nunca del cliente).
 *  - Solo registros borrados hace menos de UNDO_VENTANA_MIN minutos.
 *  - Solo quien hizo el borrado puede deshacerlo (deleted_by = usuario actual),
 *    salvo gerente_general. Así no se duplica la matriz de permisos de cada
 *    endpoint de eliminación: si pudiste borrar, puedes deshacer tu borrado.
 */
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/Database.php';
session_init();
require_login();
csrf_verify();
session_write_close();

header('Content-Type: application/json; charset=utf-8');

const UNDO_VENTANA_MIN = 10;

$modulo = $_POST['modulo'] ?? '';
$id     = (int)($_POST['id'] ?? 0);

// Whitelist módulo → tabla (misma nomenclatura que los ajax_eliminar existentes)
$tablas = [
    'clientes'     => 'clientes',
    'cotizaciones' => 'cotizaciones',
    'certificados' => 'certificados',
    'envios'       => 'envios',
    'auditorias'   => 'auditorias',
    'pagos'        => 'pagos_cliente',
];

if (!isset($tablas[$modulo]) || !$id) {
    json_error('Parámetros inválidos.');
}

$tabla = $tablas[$modulo];
$user  = auth_user();
$pdo   = Database::get();

// Verificar registro borrado, reciente y propio (o gerente general)
$stmt = $pdo->prepare("SELECT deleted_at, deleted_by FROM `$tabla` WHERE id = ? AND deleted_at IS NOT NULL");
$stmt->execute([$id]);
$row = $stmt->fetch();

if (!$row) {
    json_error('El registro no está eliminado o no existe.');
}
if (strtotime($row['deleted_at']) < time() - UNDO_VENTANA_MIN * 60) {
    json_error('Ya no se puede deshacer: pasó la ventana de ' . UNDO_VENTANA_MIN . ' minutos.');
}
if ((int)$row['deleted_by'] !== (int)$user['id'] && !has_role('gerente_general')) {
    json_error('Solo quien eliminó el registro puede deshacerlo.');
}

$pdo->prepare("UPDATE `$tabla` SET deleted_at = NULL, deleted_by = NULL WHERE id = ?")
    ->execute([$id]);

log_actividad($modulo, 'restaurar', "Deshacer eliminación de {$tabla} #{$id}");

json_ok(null, 'Eliminación deshecha.');
