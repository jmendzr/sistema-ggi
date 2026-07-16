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
    $nombre   = trim($_POST['nombre'] ?? '');
    $pais     = trim($_POST['pais'] ?? '');
    $contacto = trim($_POST['contacto'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    if (empty($nombre)) $errors['nombre'] = 'Nombre requerido.';
    if (empty($errors)) {
        if ($id) {
            $pdo->prepare("UPDATE casas_certificadoras SET nombre=?,pais=?,contacto=?,telefono=?,email=? WHERE id=?")
                ->execute([$nombre,$pais,$contacto,$telefono,$email,$id]);
        } else {
            $pdo->prepare("INSERT INTO casas_certificadoras (nombre,pais,contacto,telefono,email) VALUES (?,?,?,?,?)")
                ->execute([$nombre,$pais,$contacto,$telefono,$email]);
        }
        $msg = $id ? 'Casa certificadora actualizada exitosamente.' : 'Casa certificadora registrada exitosamente.';
        if (IS_AJAX) { header('Content-Type: application/json'); echo json_encode(['ok'=>true,'msg'=>'Casa certificadora guardada exitosamente.','csrf'=>csrf_token()]); exit; } header('Location: ?msg='.urlencode('Casa certificadora guardada exitosamente.')); exit;
    }
    if ($id) { $s=$pdo->prepare("SELECT * FROM casas_certificadoras WHERE id=?"); $s->execute([$id]); $edit=$s->fetch(); }
} elseif ($accion === 'editar' && $id) {
    $s=$pdo->prepare("SELECT * FROM casas_certificadoras WHERE id=?"); $s->execute([$id]); $edit=$s->fetch();
} elseif ($accion === 'toggle' && $id) {
    $pdo->prepare("UPDATE casas_certificadoras SET activo = NOT activo WHERE id=?")->execute([$id]);
    if (IS_AJAX) { header('Content-Type: application/json'); echo json_encode(['ok'=>true,'msg'=>'Estado actualizado.','csrf'=>csrf_token()]); exit; } header('Location: ?msg='.urlencode('Estado actualizado.')); exit;
} elseif ($accion === 'eliminar' && $id) {
    // Desactivación lógica — NO se elimina físicamente
    $pdo->prepare("UPDATE casas_certificadoras SET activo = 0 WHERE id=?")->execute([$id]);
    if (IS_AJAX) { header('Content-Type: application/json'); echo json_encode(['ok'=>true,'msg'=>'Casa certificadora desactivada.','csrf'=>csrf_token()]); exit; } header('Location: ?msg='.urlencode('Casa certificadora desactivada.')); exit;
}
if (isset($_GET['msg'])) $msg = $_GET['msg'];
$registros = $pdo->query("SELECT * FROM casas_certificadoras ORDER BY nombre")->fetchAll();

$page_title='Casas Certificadoras'; $active_menu='casas'; $user=auth_user();
require_once __DIR__ . '/../../templates/layout.php';
?>
<?php include __DIR__ . '/../../templates/mantenimiento_layout.php'; ?>
<script>
window.__mantData = {
  titulo: 'Editar Casa',
  campos: {},
  filas: {},
  columnas: {},
  editId: 0,
  msg: '',
  tabla: 'casas_certificadoras'
};
// Llamar con los datos reales
(function() {
  var args = ['<?= $edit ? "Editar Casa" : "Nueva Casa Certificadora" ?>',
  <?= json_encode([
    ['name'=>'nombre','label'=>'Nombre','required'=>true,'value'=>$edit['nombre']??''],
    ['name'=>'pais','label'=>'País','value'=>$edit['pais']??'','placeholder'=>'Perú'],
    ['name'=>'contacto','label'=>'Contacto','value'=>$edit['contacto']??''],
    ['name'=>'telefono','label'=>'Teléfono','value'=>$edit['telefono']??''],
    ['name'=>'email','label'=>'Email','inputType'=>'email','value'=>$edit['email']??''],
  ]) ?>,
  <?= json_encode(array_map(function($r){ return ['id'=>$r['id'],'cols'=>[$r['nombre'],$r['pais']??'—',$r['contacto']??'—'],'activo'=>$r['activo']]; }, $registros)) ?>,
  ['Nombre','País','Contacto'],
  <?= $edit ? (int)$edit['id'] : 0 ?>,
  <?= json_encode($msg) ?>];
  window.__mantData = {
    titulo: args[0],
    campos: args[1],
    filas: args[2],
    columnas: args[3],
    editId: args[4],
    msg: args[5],
    tabla: 'casas_certificadoras'
  };
})();
</script>
<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
