<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init(); require_login();
require_role('gerente_general');

$pdo = Database::get(); $errors = []; $edit = null; $msg = '';
$user   = auth_user();

// ── Cargar roles dinámicamente desde BD ──────────────────────────────────────
$_roles_db = [];
try {
    $_roles_db = $pdo->query("SELECT nombre, label FROM roles ORDER BY orden ASC")->fetchAll(\PDO::FETCH_ASSOC);
} catch (\Throwable $e) { /* tabla no instalada aún — usar fallback */ }
if (empty($_roles_db)) {
    $_roles_db = [
        ['nombre' => 'gerente_general',     'label' => 'Gerente General'],
        ['nombre' => 'gerente_operaciones',  'label' => 'Gerente de Operaciones'],
        ['nombre' => 'ejecutivo_ventas',     'label' => 'Ejecutivo de Ventas'],
        ['nombre' => 'gestor_logistico',     'label' => 'Gestor Logístico'],
        ['nombre' => 'delivery',             'label' => 'Delivery'],
    ];
}
$roles_validos  = array_column($_roles_db, 'nombre');
$roles_labels   = array_column($_roles_db, 'label', 'nombre');
$roles_opciones = array_map(fn($r) => ['value' => $r['nombre'], 'label' => $r['label']], $_roles_db);
$accion = $_POST['accion'] ?? $_GET['accion'] ?? '';
$id     = (int)($_POST['id'] ?? $_GET['id'] ?? 0);

if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    // Modo AJAX
    define('IS_AJAX', true);
} else {
    define('IS_AJAX', false);
}

// Verificar CSRF en cualquier acción que mute datos
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($accion, ['guardar', 'eliminar', 'toggle'])) {
    csrf_verify();
}

if ($accion === 'guardar') {
    $nombre   = trim($_POST['nombre'] ?? '');
    $apellido = trim($_POST['apellido'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $rol      = $_POST['rol'] ?? '';
    $password = $_POST['password'] ?? '';
    $telefono = trim($_POST['telefono'] ?? '');
    // Cumpleaños: guardar fecha completa con año
    $_fc_raw  = trim($_POST['fecha_cumpleanos'] ?? '');
    $fecha_cumpleanos = null;
    if ($_fc_raw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_fc_raw)) {
        $fecha_cumpleanos = $_fc_raw;
    }

    if (empty($nombre))   $errors['nombre']   = 'Nombre requerido.';
    if (empty($apellido)) $errors['apellido']  = 'Apellido requerido.';
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Email válido requerido.';
    if (!in_array($rol, $roles_validos)) $errors['rol'] = 'Rol inválido.';
    if (!$id && empty($password)) $errors['password'] = 'Contraseña requerida para nuevo usuario.';
    if (!empty($password) && strlen($password) < 8) $errors['password'] = 'Mínimo 8 caracteres.';

    if (empty($errors['email'])) {
        $existe = $pdo->prepare("SELECT id FROM usuarios WHERE email=? AND id!=?");
        $existe->execute([$email, $id]);
        if ($existe->fetch()) $errors['email'] = 'Ya existe un usuario con este email.';
    }

    // Manejo de foto
    $foto_filename = null;
    if (!empty($_FILES['foto']['tmp_name'])) {
        $allowed_types = ['image/jpeg','image/png','image/webp'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $_FILES['foto']['tmp_name']);
        finfo_close($finfo);
        $ext_map = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
        if (!in_array($mime, $allowed_types)) {
            $errors['foto'] = 'Solo se permiten imágenes JPG, PNG o WebP.';
        } elseif ($_FILES['foto']['size'] > 2 * 1024 * 1024) {
            $errors['foto'] = 'La imagen no puede superar 2 MB.';
        } else {
            $foto_filename = ($id ?: 'tmp') . '.' . $ext_map[$mime];
        }
    }

    // Bug fix: si hay errores de validación en modo AJAX, retornar JSON (no HTML)
    if (!empty($errors)) {
        if (IS_AJAX) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'msg' => implode(' ', $errors)]);
            exit;
        }
        if ($id) { $s=$pdo->prepare("SELECT * FROM usuarios WHERE id=?"); $s->execute([$id]); $edit=$s->fetch(); }
    } else {
        try {
            if ($id) {
                // Guardar foto si se subió
                if ($foto_filename) {
                    $foto_filename = $id . '.' . pathinfo($foto_filename, PATHINFO_EXTENSION);
                    $foto_dir = __DIR__ . '/../../assets/usuarios/fotos/';
                    // Eliminar fotos anteriores de este usuario
                    foreach (glob($foto_dir . $id . '.*') as $old) { @unlink($old); }
                    move_uploaded_file($_FILES['foto']['tmp_name'], $foto_dir . $foto_filename);
                    imagen_reducir($foto_dir . $foto_filename, 512); // cap a 512px
                }
                if (!empty($password)) {
                    $pdo->prepare("UPDATE usuarios SET nombre=?,apellido=?,email=?,rol=?,telefono=?,fecha_cumpleanos=?," . ($foto_filename ? "foto=?," : "") . "password_hash=? WHERE id=?")
                        ->execute($foto_filename
                            ? [$nombre,$apellido,$email,$rol,$telefono,$fecha_cumpleanos,$foto_filename,password_hash($password,PASSWORD_BCRYPT),$id]
                            : [$nombre,$apellido,$email,$rol,$telefono,$fecha_cumpleanos,password_hash($password,PASSWORD_BCRYPT),$id]);
                } else {
                    $pdo->prepare("UPDATE usuarios SET nombre=?,apellido=?,email=?,rol=?,telefono=?,fecha_cumpleanos=?" . ($foto_filename ? ",foto=?" : "") . " WHERE id=?")
                        ->execute($foto_filename
                            ? [$nombre,$apellido,$email,$rol,$telefono,$fecha_cumpleanos,$foto_filename,$id]
                            : [$nombre,$apellido,$email,$rol,$telefono,$fecha_cumpleanos,$id]);
                }
                $msg = 'Usuario actualizado exitosamente.';
                log_actividad('usuarios', 'editar', "Usuario: $email");
            } else {
                // activo=1 asegura que el nuevo usuario quede activo en la BD
                $pdo->prepare("INSERT INTO usuarios (nombre,apellido,email,rol,telefono,fecha_cumpleanos,password_hash,activo) VALUES (?,?,?,?,?,?,?,1)")
                    ->execute([$nombre,$apellido,$email,$rol,$telefono,$fecha_cumpleanos,password_hash($password, PASSWORD_BCRYPT)]);
                $new_id = (int)$pdo->lastInsertId();
                // Guardar foto si se subió al crear
                if ($foto_filename) {
                    $foto_filename = $new_id . '.' . pathinfo($foto_filename, PATHINFO_EXTENSION);
                    $foto_dir = __DIR__ . '/../../assets/usuarios/fotos/';
                    move_uploaded_file($_FILES['foto']['tmp_name'], $foto_dir . $foto_filename);
                    imagen_reducir($foto_dir . $foto_filename, 512); // cap a 512px
                    $pdo->prepare("UPDATE usuarios SET foto=? WHERE id=?")->execute([$foto_filename, $new_id]);
                }

                // Generar token de activación para que el usuario establezca su propia contraseña
                $reset_token = bin2hex(random_bytes(32));
                $reset_exp   = date('Y-m-d H:i:s', strtotime('+48 hours'));
                $pdo->prepare("UPDATE usuarios SET reset_token=?, reset_token_exp=? WHERE email=?")
                    ->execute([$reset_token, $reset_exp, $email]);

                $link_activacion = APP_URL . '/modules/auth/reset.php?token=' . $reset_token;

                $rol_nombre = $roles_labels[$rol] ?? $rol;
                $asunto = 'Bienvenido a GGI Sistema — Activa tu cuenta';
                $cuerpo = "Hola {$nombre} {$apellido},\n\n"
                        . "Tu cuenta en el sistema de gestión de Group Global ISO ha sido creada exitosamente.\n\n"
                        . "Para activar tu cuenta y establecer tu contraseña, haz clic en el siguiente enlace:\n\n"
                        . "  {$link_activacion}\n\n"
                        . "Este enlace es válido por 48 horas.\n\n"
                        . "--- DATOS DE ACCESO ---\n\n"
                        . "  URL del sistema : " . APP_URL . "\n"
                        . "  Correo         : {$email}\n"
                        . "  Rol asignado   : {$rol_nombre}\n\n"
                        . "Si tienes algún problema para acceder, contacta al administrador del sistema.\n\n"
                        . "Group Global ISO S.A.C.\n"
                        . APP_URL;
                $headers = "From: GGI Sistema <noreply@groupglobaliso.com>\r\n"
                         . "Reply-To: noreply@groupglobaliso.com\r\n"
                         . "Content-Type: text/plain; charset=UTF-8\r\n"
                         . "X-Mailer: PHP/" . phpversion();
                $email_enviado = @mail($email, $asunto, $cuerpo, $headers);
                $msg = 'Usuario registrado exitosamente.'
                     . ($email_enviado ? ' Credenciales enviadas a ' . $email . '.' : ' (No se pudo enviar el email de bienvenida.)');
                log_actividad('usuarios', 'crear', "Usuario: {$email} — Email: " . ($email_enviado ? 'enviado' : 'no enviado'));
            }
            if (IS_AJAX) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true, 'msg' => $msg, 'csrf' => csrf_token()]);
                exit;
            }
            header('Location: ?msg=' . urlencode($msg));
            exit;
        } catch (\Throwable $e) {
            if (IS_AJAX) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'msg' => 'Error al guardar: ' . $e->getMessage(), 'csrf' => csrf_token()]);
                exit;
            }
            throw $e;
        }
    }
} elseif ($accion === 'editar' && $id) {
    $s=$pdo->prepare("SELECT * FROM usuarios WHERE id=?"); $s->execute([$id]); $edit=$s->fetch();
} elseif ($accion === 'toggle' && $id) {
    // No desactivar al propio usuario
    $current = auth_user();
    if ($id === (int)$current['id']) {
        if (IS_AJAX) { header('Content-Type: application/json'); echo json_encode(['ok'=>true,'_noaction'=>true,'msg'=>'No puedes desactivarte a ti mismo.','csrf'=>csrf_token()]); exit; }
        header('Location: ?msg='.urlencode('No puedes desactivarte a ti mismo.')); exit;
    }
    // CASE WHEN para manejar NULL correctamente (NOT NULL = NULL en MySQL)
    $pdo->prepare("UPDATE usuarios SET activo = CASE WHEN activo = 1 THEN 0 ELSE 1 END WHERE id=?")->execute([$id]);
    if (IS_AJAX) { header('Content-Type: application/json'); echo json_encode(['ok'=>true,'msg'=>'Estado actualizado.','csrf'=>csrf_token()]); exit; } header('Location: ?msg='.urlencode('Estado actualizado.')); exit;
} elseif ($accion === 'eliminar' && $id) {
    $current = auth_user();
    if ($id === (int)$current['id']) {
        if (IS_AJAX) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'msg'=>'No puedes eliminarte a ti mismo.','csrf'=>csrf_token()]); exit; }
        header('Location: ?msg='.urlencode('No puedes eliminarte a ti mismo.')); exit;
    }
    // Desactivación lógica — NO se elimina físicamente, se desactiva
    $pdo->prepare("UPDATE usuarios SET activo = 0 WHERE id=?")->execute([$id]);
    if (IS_AJAX) { header('Content-Type: application/json'); echo json_encode(['ok'=>true,'msg'=>'Usuario desactivado.','csrf'=>csrf_token()]); exit; } header('Location: ?msg='.urlencode('Usuario desactivado.')); exit;
}
if (isset($_GET['msg'])) $msg = $_GET['msg'];
// Se listan TODOS los usuarios (activos e inactivos) para poder reactivarlos
$registros = $pdo->prepare("SELECT *, COALESCE(activo, 0) AS activo FROM usuarios WHERE id != ? ORDER BY activo DESC, nombre ASC");
$registros->execute([$user['id']]);
$registros = $registros->fetchAll();

// $roles_labels ya cargado dinámicamente desde BD arriba

$page_title='Usuarios'; $active_menu='usuarios'; $user=auth_user();
require_once __DIR__ . '/../../templates/layout.php';
?>
<?php include __DIR__ . '/../../templates/mantenimiento_layout.php'; ?>
<script>
window.__mantData = {
  titulo: 'Editar Usuario',
  campos: {},
  filas: {},
  columnas: {},
  editId: 0,
  msg: '',
  tabla: 'usuarios'
};
// Llamar con los datos reales
(function() {
  var args = ['<?= $edit ? "Editar Usuario" : "Nuevo Usuario" ?>',
  <?= json_encode([
    ['name'=>'nombre','label'=>'Nombre','required'=>true,'value'=>$edit['nombre']??''],
    ['name'=>'apellido','label'=>'Apellido','required'=>true,'value'=>$edit['apellido']??''],
    ['name'=>'email','label'=>'Email','required'=>true,'inputType'=>'email','value'=>$edit['email']??''],
    ['name'=>'rol','label'=>'Rol','required'=>true,'type'=>'select','value'=>$edit['rol']??'','options'=>$roles_opciones],
    ['name'=>'password','label'=>$edit ? 'Nueva contraseña (dejar vacío para no cambiar)' : 'Contraseña','required'=>!$edit,'inputType'=>'password','value'=>'','placeholder'=>'Mínimo 8 caracteres'],
    ['name'=>'telefono','label'=>'Teléfono','value'=>$edit['telefono']??'','placeholder'=>'Ej: 961 399 268'],
    ['name'=>'fecha_cumpleanos','label'=>'Fecha de cumpleaños','inputType'=>'date',
      'value'=> $edit['fecha_cumpleanos'] ?? '',
      'hint'=>'Se usará para mostrar la edad y el recordatorio de cumpleaños'],
    ['name'=>'foto','label'=>'Foto de perfil','type'=>'file','accept'=>'image/jpeg,image/png,image/webp',
      'preview'=>($edit && !empty($edit['foto'])) ? (APP_URL.'/assets/usuarios/fotos/'.htmlspecialchars($edit['foto'])) : null,
      'previewBaseUrl'=>APP_URL.'/assets/usuarios/fotos/',
      'hint'=>'JPG, PNG o WebP · Máx 2 MB'],
  ]) ?>,
  <?= json_encode(array_map(function($r) use ($roles_labels) { return [
    'id'=>$r['id'],
    'cols'=>[
      htmlspecialchars($r['nombre'].' '.$r['apellido']),
      htmlspecialchars($r['email']),
      htmlspecialchars($r['telefono'] ?? '—'),
      $roles_labels[$r['rol']] ?? $r['rol'],
      $r['ultimo_acceso'] ? date('d/m/Y H:i', strtotime($r['ultimo_acceso'])) : '—',
    ],
    'activo'=>$r['activo']
  ]; }, $registros)) ?>,
  ['Nombre','Email','Teléfono','Rol','Último acceso'],
  <?= $edit ? (int)$edit['id'] : 0 ?>,
  <?= json_encode($msg) ?>];
  window.__mantData = {
    titulo: args[0],
    campos: args[1],
    filas: args[2],
    columnas: args[3],
    editId: args[4],
    msg: args[5],
    tabla: 'usuarios'
  };
})();

// ── Hook post-render: filtro de estado para Usuarios ──────────────────────────
window.__mantPostRender = function() {
  var root   = document.getElementById('mant-root');
  if (!root) return;

  var filas  = (window.__mantData && window.__mantData.filas) || [];
  var activos   = filas.filter(function(f){ return f.activo == 1; }).length;
  var inactivos = filas.filter(function(f){ return f.activo != 1; }).length;

  // ── Insertar barra de filtro antes del card ──
  var filterBar = document.createElement('div');
  filterBar.id  = 'usr-filter-bar';
  filterBar.style.cssText = 'display:flex;align-items:center;gap:8px;margin-bottom:12px;flex-wrap:wrap;';
  filterBar.innerHTML =
    '<span style="font-size:12px;font-weight:600;color:var(--gris-texto);">Mostrar:</span>' +
    _usrTabBtn('todos',     'Todos',     filas.length,    true)  +
    _usrTabBtn('activos',   'Activos',   activos,         false) +
    _usrTabBtn('inactivos', 'Inactivos', inactivos,       false);
  root.insertBefore(filterBar, root.firstChild);

  // ── Listeners de filtro ──
  filterBar.querySelectorAll('[data-usr-filtro]').forEach(function(btn) {
    btn.addEventListener('click', function() {
      filterBar.querySelectorAll('[data-usr-filtro]').forEach(function(b) { _usrTabActivo(b, false); });
      _usrTabActivo(btn, true);
      _usrFiltrar(btn.dataset.usrFiltro);
    });
  });
};

function _usrTabBtn(filtro, label, count, activo) {
  var base  = 'display:inline-flex;align-items:center;gap:5px;padding:5px 12px;border-radius:100px;font-size:12px;font-weight:600;cursor:pointer;border:1.5px solid;transition:all .15s;';
  var style = activo
    ? base + 'background:var(--azul,#1A3A8F);color:#fff;border-color:var(--azul,#1A3A8F);'
    : base + 'background:transparent;color:var(--gris-texto);border-color:var(--gris-borde);';
  return '<button type="button" data-usr-filtro="' + filtro + '" style="' + style + '">' +
    label +
    '<span style="background:rgba(0,0,0,.12);padding:1px 6px;border-radius:100px;font-size:10px;">' + count + '</span>' +
    '</button>';
}

function _usrTabActivo(btn, on) {
  if (on) {
    btn.style.background    = 'var(--azul,#1A3A8F)';
    btn.style.color         = '#fff';
    btn.style.borderColor   = 'var(--azul,#1A3A8F)';
  } else {
    btn.style.background    = 'transparent';
    btn.style.color         = 'var(--gris-texto)';
    btn.style.borderColor   = 'var(--gris-borde)';
  }
}

function _usrFiltrar(filtro) {
  var tbody = document.querySelector('#mant-root .mant-table tbody');
  if (!tbody) return;
  tbody.querySelectorAll('tr[data-activo]').forEach(function(tr) {
    var a = tr.dataset.activo;
    if (filtro === 'todos') {
      tr.style.display = '';
    } else if (filtro === 'activos') {
      tr.style.display = a === '1' ? '' : 'none';
    } else {
      tr.style.display = a === '0' ? '' : 'none';
    }
  });
}


</script>
<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
