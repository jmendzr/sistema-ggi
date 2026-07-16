<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init(); require_login();
require_role('gerente_general', 'gerente_operaciones');

$pdo = Database::get(); $errors = []; $edit = null; $msg = '';
$accion = $_POST['accion'] ?? $_GET['accion'] ?? '';
$id     = (int)($_POST['id'] ?? $_GET['id'] ?? 0);

if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    // Modo AJAX
    define('IS_AJAX', true);
} else {
    define('IS_AJAX', false);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($accion, ['guardar', 'eliminar', 'toggle'])) {
    csrf_verify();
}

if ($accion === 'guardar') {
    $nombre    = trim($_POST['nombre'] ?? '');
    $telefono  = trim($_POST['telefono'] ?? '');
    $web       = trim($_POST['web'] ?? '');
    $sede_modo = ($_POST['sede_modo'] ?? 'manual') === 'api' ? 'api' : 'manual';
    if (empty($nombre)) $errors['nombre'] = 'Nombre requerido.';
    if (empty($errors)) {
        if ($id) {
            $pdo->prepare("UPDATE agencias_envio SET nombre=?,telefono=?,web=?,sede_modo=? WHERE id=?")
                ->execute([$nombre,$telefono,$web,$sede_modo,$id]);
        } else {
            $pdo->prepare("INSERT INTO agencias_envio (nombre,telefono,web,sede_modo) VALUES (?,?,?,?)")
                ->execute([$nombre,$telefono,$web,$sede_modo]);
        }
        $msg = $id ? 'Agencia actualizada exitosamente.' : 'Agencia registrada exitosamente.';
        if (IS_AJAX) { header('Content-Type: application/json'); echo json_encode(['ok'=>true,'msg'=>'Agencia guardada exitosamente.','csrf'=>csrf_token()]); exit; } header('Location: ?msg='.urlencode('Agencia guardada exitosamente.')); exit;
    }
    if ($id) { $s=$pdo->prepare("SELECT * FROM agencias_envio WHERE id=?"); $s->execute([$id]); $edit=$s->fetch(); }
} elseif ($accion === 'editar' && $id) {
    $s=$pdo->prepare("SELECT * FROM agencias_envio WHERE id=?"); $s->execute([$id]); $edit=$s->fetch();
} elseif ($accion === 'toggle' && $id) {
    $pdo->prepare("UPDATE agencias_envio SET activo = NOT activo WHERE id=?")->execute([$id]);
    if (IS_AJAX) { header('Content-Type: application/json'); echo json_encode(['ok'=>true,'msg'=>'Estado actualizado.','csrf'=>csrf_token()]); exit; } header('Location: ?msg='.urlencode('Estado actualizado.')); exit;
} elseif ($accion === 'eliminar' && $id) {
    // Desactivación lógica — NO se elimina físicamente
    $pdo->prepare("UPDATE agencias_envio SET activo = 0 WHERE id=?")->execute([$id]);
    if (IS_AJAX) { header('Content-Type: application/json'); echo json_encode(['ok'=>true,'msg'=>'Agencia desactivada.','csrf'=>csrf_token()]); exit; } header('Location: ?msg='.urlencode('Agencia desactivada.')); exit;
}
if (isset($_GET['msg'])) $msg = $_GET['msg'];
$registros = $pdo->query("SELECT * FROM agencias_envio ORDER BY nombre")->fetchAll();

$page_title='Agencias de Envío'; $active_menu='agencias'; $user=auth_user();
require_once __DIR__ . '/../../templates/layout.php';
?>
<?php include __DIR__ . '/../../templates/mantenimiento_layout.php'; ?>
<script>
window.__mantData = {
  titulo: 'Editar Agencia',
  campos: {},
  filas: {},
  columnas: {},
  editId: 0,
  msg: '',
  tabla: 'agencias_envio'
};
// Llamar con los datos reales
(function() {
  var args = ['<?= $edit ? "Editar Agencia" : "Nueva Agencia de Envío" ?>',
  <?= json_encode([
    ['name'=>'nombre','label'=>'Nombre','required'=>true,'value'=>$edit['nombre']??''],
    ['name'=>'telefono','label'=>'Teléfono','value'=>$edit['telefono']??''],
    ['name'=>'web','label'=>'Sitio web','value'=>$edit['web']??'','placeholder'=>'https://...'],
    ['name'=>'sede_modo','label'=>'Modo de destino','type'=>'select','value'=>$edit['sede_modo']??'manual',
     'options'=>[
       ['value'=>'manual','label'=>'Manual — sede y dirección de texto libre'],
       ['value'=>'api','label'=>'Integración (API) — buscador de sedes, sin dirección'],
     ]],
  ]) ?>,
  <?= json_encode(array_map(function($r){ return ['id'=>$r['id'],'cols'=>[$r['nombre'],$r['telefono']??'—', ($r['sede_modo']??'manual')==='api'?'Integración':'Manual'],'activo'=>$r['activo']]; }, $registros)) ?>,
  ['Nombre','Teléfono','Modo'],
  <?= $edit ? (int)$edit['id'] : 0 ?>,
  <?= json_encode($msg) ?>];
  window.__mantData = {
    titulo: args[0],
    campos: args[1],
    filas: args[2],
    columnas: args[3],
    editId: args[4],
    msg: args[5],
    tabla: 'agencias_envio'
  };
})();
</script>
<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
