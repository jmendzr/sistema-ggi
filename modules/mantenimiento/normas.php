<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();
require_role('gerente_general', 'gerente_operaciones');

$pdo    = Database::get();
$errors = [];
$edit   = null;
$msg    = '';

// Acción
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
    $codigo      = trim($_POST['codigo'] ?? '');
    $nombre      = trim($_POST['nombre'] ?? '');
    $desc        = trim($_POST['descripcion'] ?? '');
    $precio_base      = $_POST['precio_base'] !== '' ? (float)$_POST['precio_base'] : null;
    $auditoria_precio = isset($_POST['auditoria_precio']) && $_POST['auditoria_precio'] !== '' ? (float)$_POST['auditoria_precio'] : null;
    $orden            = (int)($_POST['orden'] ?? 99);
    $descuento_maximo = max(0, (int)($_POST['descuento_maximo'] ?? 0));
    // Si la norma requiere Acta de Alcance (checkbox; ausente = no requiere)
    $requiere_acta    = !empty($_POST['requiere_acta']) ? 1 : 0;
    if (empty($codigo)) $errors['codigo'] = 'Código requerido.';
    if (empty($nombre)) $errors['nombre'] = 'Nombre requerido.';
    if (empty($errors)) {
        if ($id) {
            $pdo->prepare("UPDATE normas_iso SET codigo=?,nombre=?,descripcion=?,precio_base=?,auditoria_precio=?,descuento_maximo=?,orden=?,requiere_acta=? WHERE id=?")
                ->execute([$codigo,$nombre,$desc,$precio_base,$auditoria_precio,$descuento_maximo,$orden,$requiere_acta,$id]);
            $msg = 'Norma actualizada exitosamente.';
        } else {
            $existe = $pdo->prepare("SELECT id FROM normas_iso WHERE codigo=?");
            $existe->execute([$codigo]);
            if ($existe->fetch()) { $errors['codigo'] = 'Ya existe una norma con este código.'; }
            else {
                $pdo->prepare("INSERT INTO normas_iso (codigo,nombre,descripcion,precio_base,auditoria_precio,descuento_maximo,orden,requiere_acta) VALUES (?,?,?,?,?,?,?,?)")
                    ->execute([$codigo,$nombre,$desc,$precio_base,$auditoria_precio,$descuento_maximo,$orden,$requiere_acta]);
                $msg = 'Norma registrada exitosamente.';
            }
        }
        if (empty($errors)) {
            if (IS_AJAX) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true, 'msg' => $msg, 'csrf' => csrf_token()]);
                exit;
            }
            header('Location: ?msg='.urlencode($msg)); exit;
        }
    }
    if ($id) $edit = $pdo->prepare("SELECT * FROM normas_iso WHERE id=?")->execute([$id]) ? $pdo->query("SELECT * FROM normas_iso WHERE id=$id")->fetch() : null;
} elseif ($accion === 'editar' && $id) {
    $stmt = $pdo->prepare("SELECT * FROM normas_iso WHERE id=?");
    $stmt->execute([$id]); $edit = $stmt->fetch();
} elseif ($accion === 'toggle' && $id) {
    $pdo->prepare("UPDATE normas_iso SET activo = NOT activo WHERE id=?")->execute([$id]);
    if (IS_AJAX) { header('Content-Type: application/json'); echo json_encode(['ok'=>true,'msg'=>'Estado actualizado.','csrf'=>csrf_token()]); exit; } header('Location: ?msg='.urlencode('Estado actualizado.')); exit;
} elseif ($accion === 'eliminar' && $id) {
    // Desactivación lógica — NO se elimina físicamente
    $pdo->prepare("UPDATE normas_iso SET activo = 0 WHERE id=?")->execute([$id]);
    if (IS_AJAX) { header('Content-Type: application/json'); echo json_encode(['ok'=>true,'msg'=>'Norma desactivada.','csrf'=>csrf_token()]); exit; } header('Location: ?msg='.urlencode('Norma desactivada.')); exit;
}

if (isset($_GET['msg'])) $msg = $_GET['msg'];
$normas = $pdo->query("SELECT * FROM normas_iso ORDER BY orden ASC, codigo ASC")->fetchAll();

$page_title  = 'Normas ISO';
$active_menu = 'normas';
$user        = auth_user();
require_once __DIR__ . '/../../templates/layout.php';
?>
<?php include __DIR__ . '/../../templates/mantenimiento_layout.php'; ?>
<script>
window.__mantData = {
  titulo: 'Editar Norma ISO',
  campos: {},
  filas: {},
  columnas: {},
  editId: 0,
  msg: '',
  tabla: 'normas_iso'
};
(function() {
  var args = ['<?= $edit ? "Editar Norma ISO" : "Nueva Norma ISO" ?>',
  <?= json_encode([
    ['name'=>'codigo','label'=>'Código','placeholder'=>'ISO 9001:2015','required'=>true,'value'=>$edit['codigo']??''],
    ['name'=>'nombre','label'=>'Nombre','placeholder'=>'Sistemas de Gestión de la Calidad','required'=>true,'value'=>$edit['nombre']??''],
    ['name'=>'precio_base','label'=>'Precio base certificación (S/)','placeholder'=>'0.00','inputType'=>'number','value'=>$edit['precio_base']??''],
    ['name'=>'auditoria_precio','label'=>'Precio auditoría seguimiento (S/)','placeholder'=>'0.00','inputType'=>'number','value'=>$edit['auditoria_precio']??''],
    ['name'=>'descuento_maximo','label'=>'Descuento máximo (S/)','placeholder'=>'0','inputType'=>'number','value'=>(int)($edit['descuento_maximo']??0)],
    ['name'=>'orden','label'=>'Categoría','type'=>'select','options'=>[['value'=>'10','label'=>'Principal (aparece primero)'],['value'=>'99','label'=>'Otras normas']],'value'=>(isset($edit['orden']) && $edit['orden'] < 90) ? '10' : '99'],
    ['name'=>'requiere_acta','label'=>'Requiere Acta de Alcance','type'=>'select','options'=>[['value'=>'1','label'=>'Sí — se exige el acta'],['value'=>'0','label'=>'No — el acta no aplica']],'value'=>(string)($edit['requiere_acta'] ?? 1)],
    ['name'=>'descripcion','label'=>'Descripción','placeholder'=>'Descripción opcional','type'=>'textarea','value'=>$edit['descripcion']??''],
  ]) ?>,
  <?= json_encode(array_map(function($r){
    $precio = $r['precio_base'] ? 'S/ '.number_format($r['precio_base'],2) : '—';
    $aud    = $r['auditoria_precio'] ? 'S/ '.number_format($r['auditoria_precio'],2) : '—';
    $tipo   = ($r['orden'] < 90) ? 'Principal' : 'Otras';
    return [
      'id'        => $r['id'],
      'cols'      => [$r['codigo'],$r['nombre'],$tipo,$precio,$aud,($r['descuento_maximo'] > 0 ? 'S/ '.(int)$r['descuento_maximo'] : '—'),((int)($r['requiere_acta'] ?? 1) ? 'Sí' : 'No')],
      'activo'    => $r['activo'],
      'orden'     => ($r['orden'] < 90 ? '10' : '99'),
      'precio_base'=> $r['precio_base'] ?? '',
    ];
  }, $normas)) ?>,
  ['Norma','Sistema de gestión','Categoría','Precio certif.','Precio auditoría','Desc. máx.','Acta alcance'],
  <?= $edit ? (int)$edit['id'] : 0 ?>,
  <?= json_encode($msg) ?>];
  window.__mantData = {
    titulo:   args[0],
    campos:   args[1],
    filas:    args[2],
    columnas: args[3],
    editId:   args[4],
    msg:      args[5],
    tabla:    'normas_iso'
  };
})();
</script>
<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
