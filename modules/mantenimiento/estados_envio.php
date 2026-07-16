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
    $nombre = trim($_POST['nombre'] ?? '');
    $desc   = trim($_POST['descripcion'] ?? '');
    $color  = trim($_POST['color_hex'] ?? '#6B7280');
    $orden  = (int)($_POST['orden'] ?? 0);
    if (empty($nombre)) $errors['nombre'] = 'Nombre requerido.';
    if (empty($errors)) {
        if ($id) {
            $pdo->prepare("UPDATE estados_envio SET nombre=?,descripcion=?,color_hex=?,orden=? WHERE id=?")
                ->execute([$nombre,$desc,$color,$orden,$id]);
        } else {
            $pdo->prepare("INSERT INTO estados_envio (nombre,descripcion,color_hex,orden) VALUES (?,?,?,?)")
                ->execute([$nombre,$desc,$color,$orden]);
        }
        $msg = $id ? 'Estado actualizado exitosamente.' : 'Estado registrado exitosamente.';
        if (IS_AJAX) { header('Content-Type: application/json'); echo json_encode(['ok'=>true,'msg'=>'Estado guardado exitosamente.','csrf'=>csrf_token()]); exit; } header('Location: ?msg='.urlencode('Estado guardado exitosamente.')); exit;
    }
    if ($id) { $s=$pdo->prepare("SELECT * FROM estados_envio WHERE id=?"); $s->execute([$id]); $edit=$s->fetch(); }
} elseif ($accion === 'editar' && $id) {
    $s=$pdo->prepare("SELECT * FROM estados_envio WHERE id=?"); $s->execute([$id]); $edit=$s->fetch();
} elseif ($accion === 'toggle' && $id) {
    $pdo->prepare("UPDATE estados_envio SET activo = NOT activo WHERE id=?")->execute([$id]);
    if (IS_AJAX) { header('Content-Type: application/json'); echo json_encode(['ok'=>true,'msg'=>'Estado actualizado.','csrf'=>csrf_token()]); exit; } header('Location: ?msg='.urlencode('Estado actualizado.')); exit;
} elseif ($accion === 'eliminar' && $id) {
    // Desactivación lógica — NO se elimina físicamente
    $pdo->prepare("UPDATE estados_envio SET activo = 0 WHERE id=?")->execute([$id]);
    if (IS_AJAX) { header('Content-Type: application/json'); echo json_encode(['ok'=>true,'msg'=>'Estado desactivado.','csrf'=>csrf_token()]); exit; } header('Location: ?msg='.urlencode('Estado desactivado.')); exit;
}
if (isset($_GET['msg'])) $msg = $_GET['msg'];
$registros = $pdo->query("SELECT * FROM estados_envio ORDER BY orden,nombre")->fetchAll();

$page_title='Estados de Envío'; $active_menu='estados_envio'; $user=auth_user();
require_once __DIR__ . '/../../templates/layout.php';
?>
<?php include __DIR__ . '/../../templates/mantenimiento_layout.php'; ?>
<script>
window.__mantData = {
  titulo: 'Editar Estado',
  campos: {},
  filas: {},
  columnas: {},
  editId: 0,
  msg: '',
  tabla: 'estados_envio'
};
// Llamar con los datos reales
(function() {
  var args = ['<?= $edit ? "Editar Estado" : "Nuevo Estado de Envío" ?>',
  <?= json_encode([
    ['name'=>'nombre','label'=>'Nombre','required'=>true,'value'=>$edit['nombre']??''],
    ['name'=>'descripcion','label'=>'Descripción','value'=>$edit['descripcion']??''],
    ['name'=>'color_hex','label'=>'Color (hex)','value'=>$edit['color_hex']??'#6B7280','placeholder'=>'#10B981','inputType'=>'color'],
    ['name'=>'orden','label'=>'Orden','value'=>$edit['orden']??0,'inputType'=>'number'],
  ]) ?>,
  <?= json_encode(array_map(function($r){ return [
    'id'=>$r['id'],
    'cols'=>[
      "<span style='display:inline-flex;align-items:center;gap:6px'><span style='width:12px;height:12px;border-radius:50%;background:{$r['color_hex']};display:inline-block;flex-shrink:0;box-shadow:0 0 0 1.5px rgba(128,128,128,0.35),0 0 0 3px rgba(128,128,128,0.1);'></span>{$r['nombre']}</span>",
      $r['descripcion']??'—',
      $r['orden']
    ],
    'activo'=>$r['activo']
  ]; }, $registros)) ?>,
  ['Nombre','Descripción','Orden'],
  <?= $edit ? (int)$edit['id'] : 0 ?>,
  <?= json_encode($msg) ?>];
  window.__mantData = {
    titulo: args[0],
    campos: args[1],
    filas: args[2],
    columnas: args[3],
    editId: args[4],
    msg: args[5],
    tabla: 'estados_envio'
  };
})();
</script>
<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
