<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();
$user = auth_user();

$_ggi_embed = !empty($_GET['embed']) || !empty($_POST['_embed']);
$from     = $_GET['from'] ?? 'listado';
$_edit_id = (int)($_GET['edit'] ?? 0);

// Modo del formulario base unificado: EDIT | DUPLICATE | CREATE.
// Las tres operaciones comparten este mismo archivo (form, validaciones y lógica).
$_modo = $_edit_id ? 'EDIT' : (!empty($_GET['duplicar']) ? 'DUPLICATE' : 'CREATE');

$pdo    = Database::get();

// ── Modo EDITAR: cargar propuesta existente ──────────────
$_cot_edit    = null;
$_edit_normas = []; // norma_iso_id => precio
$_edit_casas  = []; // norma_iso_id => casa_certificadora_id
if ($_edit_id) {
    $_s = $pdo->prepare("SELECT * FROM cotizaciones WHERE id=?");
    $_s->execute([$_edit_id]);
    $_cot_edit = $_s->fetch() ?: null;
    if (!$_cot_edit || !in_array($_cot_edit['estado'], ['borrador','enviada'])) {
        header('Location: ' . APP_URL . '/modules/cotizaciones/ver.php?id=' . $_edit_id); exit;
    }
    $_nm = $pdo->prepare("SELECT norma_iso_id, precio, casa_certificadora_id FROM cotizacion_normas WHERE cotizacion_id=?");
    $_nm->execute([$_edit_id]);
    foreach ($_nm->fetchAll() as $_r) {
        $_edit_normas[$_r['norma_iso_id']] = $_r['precio'];
        $_edit_casas[$_r['norma_iso_id']]  = $_r['casa_certificadora_id'];
    }
}

$back_url = $_edit_id
    ? ($from === 'listado'
        ? APP_URL . '/modules/cotizaciones/index.php'
        : APP_URL . '/modules/cotizaciones/ver.php?id=' . $_edit_id)
    : ($from === 'dashboard'
        ? APP_URL . '/modules/dashboard/index.php'
        : APP_URL . '/modules/cotizaciones/index.php');

$normas = $pdo->query("SELECT id, codigo, nombre, precio_base, auditoria_precio, descuento_maximo, orden FROM normas_iso WHERE activo=1 ORDER BY orden ASC, codigo ASC")->fetchAll();
// Split: principales (orden < 90) vs otras
$normas_principales = array_filter($normas, fn($n) => $n['orden'] < 90);
$normas_otras       = array_filter($normas, fn($n) => $n['orden'] >= 90);
$casas = $pdo->query("SELECT id, nombre FROM casas_certificadoras WHERE activo=1 ORDER BY nombre")->fetchAll();
// $clientes no se pre-carga: se buscan vía AJAX (ajax_clientes_buscar.php)

// ── Bonos config para preview en tiempo real ──
$bonos_cfg_rows = $pdo->query("SELECT norma_iso_id, monto_bono FROM bonos_config WHERE activo=1")->fetchAll();
$_bono_default = 0.0;
$_bono_por_norma = [];
foreach ($bonos_cfg_rows as $_br) {
    if ($_br['norma_iso_id'] === null) { $_bono_default = (float)$_br['monto_bono']; }
    else { $_bono_por_norma[(int)$_br['norma_iso_id']] = (float)$_br['monto_bono']; }
}
// Build JS-safe map: norma_id → monto (fallback default)
$_bono_js_map = [];
foreach ($normas as $_n) {
    $_bono_js_map[(int)$_n['id']] = $_bono_por_norma[(int)$_n['id']] ?? $_bono_default;
}

// Asegurar que existe la fila de secuencia para COT del año actual
$pdo->prepare(
    "INSERT INTO secuencias (tipo, año, ultimo) VALUES ('COT', YEAR(NOW()), 0)
     ON DUPLICATE KEY UPDATE tipo = tipo"
)->execute([]);

// ── Preview del correlativo — usa secuencias directamente (rápido) ──
$_año_prev = (int)date('Y');
$_seq_prev = (int)($pdo->query("SELECT ultimo FROM secuencias WHERE tipo='COT' AND año={$_año_prev}")->fetchColumn() ?: 0);
$_next_num = $_seq_prev + 1;
$preview_numero = 'COT-' . $_año_prev . '-' . str_pad($_next_num, 7, '0', STR_PAD_LEFT);

// Pre-fill desde ficha de cliente
$_prefill_cliente = (int)($_GET['cliente_id'] ?? 0);
$_renovar_cert_id = (int)($_GET['renovar_cert'] ?? 0);
$_cert_renovar    = null;

// Pre-fill desde certificado a renovar
if ($_renovar_cert_id && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $s = $pdo->prepare("
        SELECT c.id, c.cliente_id, c.norma_iso_id, c.numero_certificado, c.fecha_vencimiento,
               n.codigo AS norma_codigo, cl.razon_social
        FROM certificados c
        JOIN normas_iso n ON n.id = c.norma_iso_id
        JOIN clientes cl  ON cl.id = c.cliente_id
        WHERE c.id = ?
    ");
    $s->execute([$_renovar_cert_id]);
    $_cert_renovar = $s->fetch() ?: null;
    if ($_cert_renovar) {
        $_prefill_cliente = (int)$_cert_renovar['cliente_id'];
    }
}

$errors = [];
$data   = [
    'tipo_empresa'      => $_prefill_cliente ? 'existente' : 'nueva',
    'cliente_id'        => $_prefill_cliente,
    'empresa_ruc'       => '',
    'empresa_nombre'    => '',
    'empresa_direccion' => '',
    'empresa_dep'       => '',
    'empresa_prov'      => '',
    'empresa_dist'      => '',
    'empresa_ubigeo'    => '',
    'num_trabajadores'  => null,
    'contacto_nombre'   => '',
    'contacto_apellido' => '',
    'contacto_cargo'    => '',
    'contacto_telefono' => '',
    'contacto_email'    => '',
    'monto_total'       => '',
    'observaciones'     => '',
    'notas'             => '',
    'estado'            => 'enviada',
    'ejecutivo_id'      => ($user['rol'] === 'ejecutivo_ventas' ? $user['id'] : null),
    'normas'            => [],
    'precios'           => [],
    'descuentos'        => [],
    'descuento'         => '',
    'auditoria_precio'  => '',
    'auditoria_precio2' => '',
    'fecha_vencimiento' => date('Y-m-d', strtotime('+4 days')),
    'fecha_emision'     => date('Y-m-d'),
    'incluye_igv'       => true,
];

// ── Preload desde duplicar ──
if (!empty($_GET['duplicar']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $dup_stmt = $pdo->prepare("
        SELECT cot.*, GROUP_CONCAT(cn.norma_iso_id) AS normas_str,
               GROUP_CONCAT(cn.precio) AS precios_str,
               GROUP_CONCAT(cn.casa_certificadora_id) AS casas_str,
               GROUP_CONCAT(cn.norma_iso_id ORDER BY cn.norma_iso_id) AS normas_ord
        FROM cotizaciones cot
        LEFT JOIN cotizacion_normas cn ON cn.cotizacion_id = cot.id
        WHERE cot.id = ?
        GROUP BY cot.id
    ");
    $dup_stmt->execute([(int)$_GET['duplicar']]);
    if ($dup_row = $dup_stmt->fetch()) {
        // Build normas/precios/casas maps from concatenated strings
        $dup_norma_ids = $dup_row['normas_str'] ? explode(',', $dup_row['normas_str']) : [];
        $dup_precios_arr = $dup_row['precios_str'] ? explode(',', $dup_row['precios_str']) : [];
        $dup_casas_arr   = $dup_row['casas_str']   ? explode(',', $dup_row['casas_str'])   : [];
        // Re-fetch per norma to build keyed arrays
        $dup_nmap = $pdo->prepare("SELECT norma_iso_id, precio, casa_certificadora_id FROM cotizacion_normas WHERE cotizacion_id=?");
        $dup_nmap->execute([(int)$_GET['duplicar']]);
        $dup_precios_map = []; $dup_casas_map = [];
        foreach ($dup_nmap->fetchAll() as $dnr) {
            $dup_precios_map[$dnr['norma_iso_id']] = $dnr['precio'];
            $dup_casas_map[$dnr['norma_iso_id']]   = $dnr['casa_certificadora_id'];
        }
        $data = array_merge($data, [
            'tipo_empresa'      => $dup_row['tipo_empresa'],
            'cliente_id'        => $dup_row['cliente_id'],
            'empresa_ruc'       => $dup_row['empresa_ruc'] ?? '',
            'empresa_nombre'    => $dup_row['empresa_nombre'] ?? '',
            'empresa_direccion' => $dup_row['empresa_direccion'] ?? '',
            'empresa_dep'       => $dup_row['empresa_dep'] ?? '',
            'empresa_prov'      => $dup_row['empresa_prov'] ?? '',
            'empresa_dist'      => $dup_row['empresa_dist'] ?? '',
            'empresa_ubigeo'    => $dup_row['empresa_ubigeo'] ?? '',
            'num_trabajadores'  => isset($dup_row['num_trabajadores']) ? (int)$dup_row['num_trabajadores'] : null,
            'contacto_nombre'   => $dup_row['contacto_nombre'] ?? '',
            'contacto_apellido' => $dup_row['contacto_apellido'] ?? '',
            'contacto_cargo'    => $dup_row['contacto_cargo'] ?? '',
            'contacto_telefono' => $dup_row['contacto_telefono'] ?? '',
            'contacto_email'    => $dup_row['contacto_email'] ?? '',
            'monto_total'       => $dup_row['monto_total'] ?? '',
            'observaciones'     => $dup_row['observaciones'] ?? '',
            'notas'             => $dup_row['notas'] ?? '',
            'estado'            => 'enviada',
            'ejecutivo_id'      => ($user['rol'] === 'ejecutivo_ventas' ? $user['id'] : $dup_row['ejecutivo_id']),
            'normas'            => $dup_norma_ids,
            'precios'           => $dup_precios_map,
            'casas'             => $dup_casas_map,
            'descuento'         => $dup_row['descuento'] ?? '',
            'auditoria_precio'  => $dup_row['auditoria_precio'] ?? '',
            'auditoria_precio2' => $dup_row['auditoria_precio2'] ?? '',
            'incluye_igv'       => (bool)($dup_row['incluye_igv'] ?? 1),
        ]);
    }
}

// ── Preload desde editar ──
if ($_edit_id && $_cot_edit && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $data = array_merge($data, [
        'tipo_empresa'      => $_cot_edit['tipo_empresa'],
        'cliente_id'        => $_cot_edit['cliente_id'],
        'empresa_ruc'       => $_cot_edit['empresa_ruc'] ?? '',
        'empresa_nombre'    => $_cot_edit['empresa_nombre'] ?? '',
        'empresa_direccion' => $_cot_edit['empresa_direccion'] ?? '',
        'empresa_dep'       => $_cot_edit['empresa_dep'] ?? '',
        'empresa_prov'      => $_cot_edit['empresa_prov'] ?? '',
        'empresa_dist'      => $_cot_edit['empresa_dist'] ?? '',
        'empresa_ubigeo'    => $_cot_edit['empresa_ubigeo'] ?? '',
        'num_trabajadores'  => isset($_cot_edit['num_trabajadores']) ? (int)$_cot_edit['num_trabajadores'] : null,
        'contacto_nombre'   => $_cot_edit['contacto_nombre'] ?? '',
        'contacto_apellido' => $_cot_edit['contacto_apellido'] ?? '',
        'contacto_cargo'    => $_cot_edit['contacto_cargo'] ?? '',
        'contacto_telefono' => $_cot_edit['contacto_telefono'] ?? '',
        'contacto_email'    => $_cot_edit['contacto_email'] ?? '',
        'monto_total'       => $_cot_edit['monto_total'] ?? '',
        'observaciones'     => $_cot_edit['observaciones'] ?? '',
        'notas'             => $_cot_edit['notas'] ?? '',
        'estado'            => $_cot_edit['estado'],
        'ejecutivo_id'      => ($user['rol'] === 'ejecutivo_ventas' ? $user['id'] : $_cot_edit['ejecutivo_id']),
        'normas'            => array_keys($_edit_normas),
        'precios'           => $_edit_normas,
        'casas'             => $_edit_casas,
        'descuento'         => $_cot_edit['descuento'] ?? '',
        'auditoria_precio'  => $_cot_edit['auditoria_precio'] ?? '',
        'auditoria_precio2' => $_cot_edit['auditoria_precio2'] ?? '',
        'fecha_vencimiento' => $_cot_edit['fecha_vencimiento'] ?? '',
        'fecha_emision'     => $_cot_edit['fecha_emision'] ?? date('Y-m-d'),
        'incluye_igv'       => (bool)($_cot_edit['incluye_igv'] ?? 1),
    ]);
}

// Pre-fill contact fields from client record when coming from empresa ficha (?cliente_id=X)
if ($_prefill_cliente && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_cl_stmt = $pdo->prepare("SELECT contacto_nombre, contacto_apellido, contacto_cargo, contacto_telefono, contacto_email FROM clientes WHERE id=? LIMIT 1");
    $_cl_stmt->execute([$_prefill_cliente]);
    if ($_cl = $_cl_stmt->fetch()) {
        $data['contacto_nombre']   = $_cl['contacto_nombre']   ?? '';
        $data['contacto_apellido'] = $_cl['contacto_apellido'] ?? '';
        $data['contacto_cargo']    = $_cl['contacto_cargo']    ?? '';
        $data['contacto_telefono'] = $_cl['contacto_telefono'] ?? '';
        $data['contacto_email']    = $_cl['contacto_email']    ?? '';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $data = [
        'tipo_empresa'     => $_POST['tipo_empresa'] ?? 'nueva',
        'cliente_id'       => (int)($_POST['cliente_id'] ?? 0),
        'empresa_ruc'      => trim($_POST['empresa_ruc'] ?? ''),
        'empresa_nombre'   => trim($_POST['empresa_nombre'] ?? ''),
        'empresa_direccion'=> trim($_POST['empresa_direccion'] ?? ''),
        'empresa_dep'      => trim($_POST['empresa_dep'] ?? ''),
        'empresa_prov'     => trim($_POST['empresa_prov'] ?? ''),
        'empresa_dist'     => trim($_POST['empresa_dist'] ?? ''),
        'empresa_ubigeo'   => substr(trim($_POST['empresa_ubigeo'] ?? ''), 0, 30),
        'num_trabajadores' => !empty($_POST['num_trabajadores']) ? (int)$_POST['num_trabajadores'] : null,
        'contacto_nombre'  => trim($_POST['contacto_nombre'] ?? ''),
        'contacto_apellido'=> trim($_POST['contacto_apellido'] ?? ''),
        'contacto_cargo'   => trim($_POST['contacto_cargo'] ?? ''),
        'contacto_telefono'=> trim($_POST['contacto_telefono'] ?? ''),
        'contacto_email'   => trim($_POST['contacto_email'] ?? ''),
        'monto_total'      => trim($_POST['monto_total'] ?? ''),
        'observaciones'    => trim($_POST['observaciones'] ?? ''),
        'notas'            => trim($_POST['notas'] ?? ''),
        'estado'           => $_edit_id ? ($_cot_edit['estado'] ?? 'enviada') : 'enviada',
        'ejecutivo_id'     => ($user['rol'] === 'ejecutivo_ventas' ? $user['id'] : ((int)($_POST['ejecutivo_id'] ?? 0) ?: null)),
        'normas'           => $_POST['normas'] ?? [],
        'casas'            => $_POST['casas'] ?? [],
        'precios'          => $_POST['precios'] ?? [],
        'descuentos'       => $_POST['descuentos'] ?? [],
        'descuento'        => trim($_POST['descuento'] ?? ''),
        'auditoria_precio'  => trim($_POST['auditoria_precio'] ?? ''),
        'auditoria_precio2' => trim($_POST['auditoria_precio2'] ?? ''),
        'fecha_vencimiento' => trim($_POST['fecha_vencimiento'] ?? ''),
        'fecha_emision'     => trim($_POST['fecha_emision'] ?? date('Y-m-d')),
        'incluye_igv'       => !empty($_POST['incluye_igv']),
    ];

    // Validaciones
    if ($data['tipo_empresa'] === 'existente' && !$data['cliente_id']) {
        $errors['cliente_id'] = 'Selecciona una empresa existente.';
    }
    if ($data['tipo_empresa'] === 'nueva' && empty($data['empresa_nombre'])) {
        $errors['empresa_nombre'] = 'El nombre de la empresa es requerido.';
    }
    if (empty($data['normas'])) {
        $errors['normas'] = 'Selecciona al menos una norma ISO.';
    }
    // Celular obligatorio — formato móvil Perú (9 dígitos, inicia en 9).
    // Se normaliza (solo dígitos, sin prefijo 51) y se guarda limpio.
    $tel_digits = preg_replace('/\D/', '', $data['contacto_telefono']);
    if (strlen($tel_digits) === 11 && str_starts_with($tel_digits, '51')) {
        $tel_digits = substr($tel_digits, 2);
    }
    if ($tel_digits === '') {
        $errors['contacto_telefono'] = 'El celular del contacto es requerido.';
    } elseif (!preg_match('/^9\d{8}$/', $tel_digits)) {
        $errors['contacto_telefono'] = 'Ingresa un celular válido (9 dígitos, empieza en 9).';
    } else {
        $data['contacto_telefono'] = $tel_digits;
    }

    if (empty($errors)) {
        // Empresa: extraer datos desde cliente BD si es existente
        $emp_ruc = null; $emp_nombre = null; $emp_dir = null;
        if ($data['tipo_empresa'] === 'existente' && $data['cliente_id']) {
            $cl = $pdo->prepare("SELECT ruc, razon_social, direccion FROM clientes WHERE id=?");
            $cl->execute([$data['cliente_id']]);
            $cl = $cl->fetch();
            if ($cl) { $emp_ruc = $cl['ruc']; $emp_nombre = $cl['razon_social']; $emp_dir = $cl['direccion']; }
        } else {
            $emp_ruc    = $data['empresa_ruc'] ?: null;
            $emp_nombre = $data['empresa_nombre'] ?: null;
            $emp_dir    = $data['empresa_direccion'] ?: null;
        }

        // Recalcular montos server-side
        $desc_val        = $data['descuento'] !== '' ? max(0, (float)$data['descuento']) : 0.0;
        $subtotal_normas = 0.0;
        foreach ($data['normas'] as $_nid) {
            if (isset($data['precios'][$_nid]) && $data['precios'][$_nid] !== '') {
                $subtotal_normas += (float)$data['precios'][$_nid];
            }
        }
        $subtotal_calc = max(0.0, $subtotal_normas - $desc_val);
        $igv_calc      = $data['incluye_igv'] ? round($subtotal_calc * 0.18, 2) : 0.0;
        $total_calc    = round($subtotal_calc + $igv_calc, 2);

        if ($user['rol'] === 'ejecutivo_ventas' && $total_calc < 500) {
            $errors['monto_min'] = 'El monto total de la propuesta no puede ser menor a S/ 500.00.';
        }

        if (empty($errors)):

        if ($_edit_id) {
            // ── VERSIONADO: snapshot del estado actual antes de modificar ──────────
            // Crea la tabla si todavía no existe (lazy migration en la primera edición).
            // El snapshot guarda todos los campos + normas como JSON; es de solo lectura
            // (nunca se actualiza) y sirve como auditoría y para recuperar versiones previas.
            try {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS cotizaciones_historial (
                        id            INT AUTO_INCREMENT PRIMARY KEY,
                        cotizacion_id INT          NOT NULL,
                        version_num   SMALLINT     NOT NULL DEFAULT 1,
                        editado_por   INT,
                        snapshot      JSON         NOT NULL COMMENT 'Fila completa de cotizaciones antes del cambio',
                        normas_snap   JSON         NOT NULL COMMENT 'Filas de cotizacion_normas antes del cambio',
                        created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_ch_cot (cotizacion_id, created_at)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ");

                // Número de versión = versiones ya guardadas + 1
                $vn_s = $pdo->prepare("SELECT COUNT(*) FROM cotizaciones_historial WHERE cotizacion_id = ?");
                $vn_s->execute([$_edit_id]);
                $vn = (int)$vn_s->fetchColumn() + 1;

                // Normas actuales del borrador (antes del DELETE/INSERT que viene luego)
                $sn = $pdo->prepare("
                    SELECT cn.norma_iso_id, cn.precio, cn.descuento, cn.casa_certificadora_id, n.codigo, n.nombre
                    FROM cotizacion_normas cn
                    JOIN normas_iso n ON n.id = cn.norma_iso_id
                    WHERE cn.cotizacion_id = ?
                ");
                $sn->execute([$_edit_id]);

                $pdo->prepare("
                    INSERT INTO cotizaciones_historial (cotizacion_id, version_num, editado_por, snapshot, normas_snap)
                    VALUES (?, ?, ?, ?, ?)
                ")->execute([
                    $_edit_id,
                    $vn,
                    $user['id'],
                    json_encode($_cot_edit, JSON_UNESCAPED_UNICODE),
                    json_encode($sn->fetchAll(\PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE),
                ]);
            } catch (\Throwable) {
                // El versionado nunca interrumpe el guardado de la propuesta
            }

            // ── MODO EDITAR: UPDATE ────────────────────────────────────────
            $pdo->prepare("
                UPDATE cotizaciones SET
                  tipo_empresa=?, cliente_id=?, empresa_ruc=?, empresa_nombre=?, empresa_direccion=?,
                  empresa_dep=?, empresa_prov=?, empresa_dist=?, empresa_ubigeo=?,
                  num_trabajadores=?,
                  contacto_nombre=?, contacto_apellido=?, contacto_cargo=?,
                  contacto_telefono=?, contacto_email=?,
                  monto_total=?, descuento=?, subtotal=?, igv=?, incluye_igv=?,
                  auditoria_precio=?, auditoria_precio2=?,
                  auditoria_mes=?, auditoria_anio=?,
                  fecha_emision=?, fecha_vencimiento=?,
                  observaciones=?, notas=?,
                  estado=?, ejecutivo_id=?
                WHERE id=?
            ")->execute([
                $data['tipo_empresa'],
                $data['tipo_empresa'] === 'existente' ? $data['cliente_id'] : null,
                $emp_ruc, $emp_nombre, $emp_dir,
                $data['empresa_dep'] ?: null,
                $data['empresa_prov'] ?: null,
                $data['empresa_dist'] ?: null,
                $data['empresa_ubigeo'] ?: null,
                $data['num_trabajadores'] ?? null,
                $data['contacto_nombre'] ?: null,
                $data['contacto_apellido'] ?: null,
                $data['contacto_cargo'] ?: null,
                $data['contacto_telefono'] ?: null,
                $data['contacto_email'] ?: null,
                $total_calc, $desc_val, $subtotal_calc, $igv_calc,
                $data['incluye_igv'] ? 1 : 0,
                ($data['auditoria_precio']  !== '' ? (float)$data['auditoria_precio']  : null),
                ($data['auditoria_precio2'] !== '' ? (float)$data['auditoria_precio2'] : null),
                ($_cot_edit['auditoria_mes']  ?: (int)date('n')),
                ($_cot_edit['auditoria_anio'] ?: (int)date('Y') + 1),
                $data['fecha_emision'] ?: date('Y-m-d'),
                ($data['fecha_vencimiento'] ?: null),
                $data['observaciones'] ?: null,
                $data['notas'] ?: null,
                $data['estado'],
                $data['ejecutivo_id'],
                $_edit_id,
            ]);

            // Re-insertar normas
            $pdo->prepare("DELETE FROM cotizacion_normas WHERE cotizacion_id=?")->execute([$_edit_id]);
            foreach ($data['normas'] as $norma_id) {
                $precio_norma = isset($data['precios'][$norma_id]) && $data['precios'][$norma_id] !== ''
                    ? (float)$data['precios'][$norma_id] : null;
                $casa_id = isset($data['casas'][$norma_id]) ? (int)$data['casas'][$norma_id] : null;
                $pdo->prepare("INSERT INTO cotizacion_normas (cotizacion_id, norma_iso_id, precio, descuento, casa_certificadora_id) VALUES (?,?,?,?,?)")
                    ->execute([$_edit_id, (int)$norma_id, $precio_norma, 0, $casa_id ?: null]);
            }

            log_actividad('propuestas', 'editar', "Propuesta #$_edit_id actualizada");

            // En modo modal (embed): avisar al padre para cerrar y refrescar la vista
            // actual, sin sacar al usuario de su contexto. Mismo protocolo que CREATE.
            if ($_ggi_embed) {
                ?><!DOCTYPE html><html><body><script>
                window.parent.postMessage({
                    ggi:    'created',
                    mode:   'edit',
                    module: 'cotizaciones',
                    id:     <?= (int)$_edit_id ?>,
                    numero: <?= json_encode($_cot_edit['numero'] ?? '') ?>
                }, '*');
                </script></body></html><?php
                exit;
            }

            header('Location: ' . APP_URL . '/modules/cotizaciones/ver.php?id=' . $_edit_id . '&editado=1');
            exit;

        } else {
            // ── MODO CREAR: INSERT ─────────────────────────────────────────
            $año_actual = (int)date('Y');
            $max_real = (int)$pdo->query("SELECT MAX(CAST(SUBSTRING_INDEX(COALESCE(numero,codigo),'-',-1) AS UNSIGNED)) FROM cotizaciones WHERE COALESCE(numero,codigo) LIKE 'COT-{$año_actual}-%'")->fetchColumn();
            $pdo->prepare("INSERT INTO secuencias (tipo, año, ultimo) VALUES ('COT', ?, ?) ON DUPLICATE KEY UPDATE ultimo = GREATEST(ultimo, ?)")
                ->execute([$año_actual, $max_real, $max_real]);
            $numero = next_codigo('COT');

            $pdo->prepare("
                INSERT INTO cotizaciones
                  (codigo, numero, tipo_empresa, cliente_id, empresa_ruc, empresa_nombre, empresa_direccion,
                   empresa_dep, empresa_prov, empresa_dist, empresa_ubigeo, num_trabajadores,
                   contacto_nombre, contacto_apellido, contacto_cargo, contacto_telefono, contacto_email,
                   monto_total, descuento, subtotal, igv, incluye_igv, auditoria_precio, auditoria_precio2, auditoria_mes, auditoria_anio,
                   moneda, fecha_emision, fecha_vencimiento, observaciones, notas, estado, ejecutivo_id, creado_por)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ")->execute([
                $numero, $numero,
                $data['tipo_empresa'],
                $data['tipo_empresa'] === 'existente' ? $data['cliente_id'] : null,
                $emp_ruc, $emp_nombre, $emp_dir,
                $data['empresa_dep'] ?: null,
                $data['empresa_prov'] ?: null,
                $data['empresa_dist'] ?: null,
                $data['empresa_ubigeo'] ?: null,
                $data['num_trabajadores'] ?? null,
                $data['contacto_nombre'] ?: null,
                $data['contacto_apellido'] ?: null,
                $data['contacto_cargo'] ?: null,
                $data['contacto_telefono'] ?: null,
                $data['contacto_email'] ?: null,
                $total_calc, $desc_val, $subtotal_calc, $igv_calc,
                $data['incluye_igv'] ? 1 : 0,
                ($data['auditoria_precio'] !== '' ? (float)$data['auditoria_precio'] : null),
                ($data['auditoria_precio2'] !== '' ? (float)$data['auditoria_precio2'] : null),
                (int)date('n'), (int)date('Y') + 1,
                'PEN',
                $data['fecha_emision'] ?: date('Y-m-d'),
                ($data['fecha_vencimiento'] ?: null),
                $data['observaciones'] ?: null,
                $data['notas'] ?: null,
                $data['estado'],
                $data['ejecutivo_id'],
                $user['id'],
            ]);
            $cot_id = $pdo->lastInsertId();

            // Insertar normas
            foreach ($data['normas'] as $norma_id) {
                $precio_norma = isset($data['precios'][$norma_id]) && $data['precios'][$norma_id] !== ''
                    ? (float)$data['precios'][$norma_id] : null;
                $casa_id = isset($data['casas'][$norma_id]) ? (int)$data['casas'][$norma_id] : null;
                $pdo->prepare("INSERT INTO cotizacion_normas (cotizacion_id, norma_iso_id, precio, descuento, casa_certificadora_id) VALUES (?,?,?,?,?)")
                    ->execute([$cot_id, (int)$norma_id, $precio_norma, 0, $casa_id ?: null]);
            }
            if ($desc_val > 0) {
                $pdo->prepare("UPDATE cotizaciones SET descuento=? WHERE id=?")->execute([$desc_val, $cot_id]);
            }

            // Crear/vincular cliente como prospecto
            if ($data['tipo_empresa'] === 'nueva' && ($emp_ruc || $emp_nombre)) {
                $cli_id_prospecto = null;
                if ($emp_ruc) {
                    $ex = $pdo->prepare("SELECT id FROM clientes WHERE ruc=? LIMIT 1");
                    $ex->execute([$emp_ruc]);
                    $cli_id_prospecto = (int)$ex->fetchColumn() ?: null;
                }
                if (!$cli_id_prospecto && $emp_nombre) {
                    $ex = $pdo->prepare("SELECT id FROM clientes WHERE razon_social=? LIMIT 1");
                    $ex->execute([$emp_nombre]);
                    $cli_id_prospecto = (int)$ex->fetchColumn() ?: null;
                }
                if (!$cli_id_prospecto) {
                    $max_seq = (int)$pdo->query("SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(codigo_interno,'-',-1) AS UNSIGNED)),0) FROM clientes WHERE codigo_interno REGEXP '^GGI-[0-9]+-[0-9]+'")->fetchColumn();
                    $pdo->prepare("UPDATE secuencias SET ultimo=GREATEST(ultimo,?) WHERE tipo='CLIENTE'")->execute([$max_seq]);
                    $pdo->prepare("
                        INSERT INTO clientes (ruc, razon_social, direccion, departamento, provincia, distrito, ubigeo, contacto_nombre, contacto_apellido, contacto_cargo, contacto_telefono, contacto_email, estado, fecha_contrato, ejecutivo_id, creado_por)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'prospecto', CURDATE(), ?,?)
                    ")->execute([
                        $emp_ruc ?: '00000000000', $emp_nombre ?: 'Sin nombre', $emp_dir ?: null,
                        $data['empresa_dep'] ?: null, $data['empresa_prov'] ?: null,
                        $data['empresa_dist'] ?: null, $data['empresa_ubigeo'] ?: null,
                        $data['contacto_nombre'] ?: null, $data['contacto_apellido'] ?: null,
                        $data['contacto_cargo'] ?: null, $data['contacto_telefono'] ?: null,
                        $data['contacto_email'] ?: null,
                        $data['ejecutivo_id'] ?: null, $user['id'],
                    ]);
                    $cli_id_prospecto = (int)$pdo->lastInsertId();
                    log_actividad('clientes','crear',"Cliente prospecto #$cli_id_prospecto creado desde propuesta #$cot_id");
                }
                if ($cli_id_prospecto) {
                    $pdo->prepare("UPDATE cotizaciones SET cliente_id=? WHERE id=?")->execute([$cli_id_prospecto, $cot_id]);
                }
            }

            log_actividad('propuestas', 'crear', "Propuesta #$cot_id creada ($numero)");
            if ($_ggi_embed) {
                // CREATE y DUPLICATE pasan ambos por aquí (id y número nuevos);
                // el callback los trata igual → navegar a la propuesta nueva.
                ?><!DOCTYPE html><html><body><script>
                window.parent.postMessage({
                    ggi:     'created',
                    mode:    <?= json_encode($_modo === 'DUPLICATE' ? 'duplicate' : 'create') ?>,
                    module:  'cotizaciones',
                    id:      <?= (int)$cot_id ?>,
                    numero:  <?= json_encode($numero) ?>,
                    empresa: <?= json_encode($emp_nombre ?? '') ?>,
                    ruc:     <?= json_encode($emp_ruc ?? '') ?>
                }, '*');
                </script></body></html><?php
                exit;
            }
            // Modo directo: redirigir al listado con el ID para highlight + toast
            header('Location: ' . APP_URL . '/modules/cotizaciones/index.php?nuevo=' . $cot_id . '&num=' . urlencode($numero));
            exit;
        }
        endif; // monto_min guard
    }
}

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
$page_title  = $_edit_id ? 'Editar Propuesta' : ($_cert_renovar ? 'Renovar Certificado' : (!empty($_GET['duplicar']) ? 'Duplicar Propuesta' : 'Nueva Propuesta'));
$active_menu = 'propuestas';

// Ejecutivos para el select
$ejecutivos = $pdo->query("SELECT id, nombre, apellido FROM usuarios WHERE activo=1 AND rol='ejecutivo_ventas' ORDER BY nombre")->fetchAll();

// Pre-fetch empresa card data + datos del cliente para autocompletar (sin pre-cargar todos los clientes)
$prefill_empresa    = null;
$prefill_cl_data    = null; // ejec_id + contacto para el cliente pre-seleccionado
if ($data['cliente_id'] && $data['tipo_empresa'] === 'existente') {
    $stmt = $pdo->prepare("
        SELECT id, ruc, razon_social, estado, ejecutivo_id,
               contacto_nombre, contacto_apellido, contacto_cargo, contacto_telefono, contacto_email,
               direccion, departamento, provincia, distrito, ubigeo, num_trabajadores
        FROM clientes WHERE id = ? LIMIT 1
    ");
    $stmt->execute([$data['cliente_id']]);
    $cl_row = $stmt->fetch() ?: null;
    if ($cl_row) {
        $prefill_empresa = $cl_row; // contiene id, ruc, razon_social, estado
        $prefill_cl_data = [
            'ejec_id'  => $cl_row['ejecutivo_id'] ? (int)$cl_row['ejecutivo_id'] : null,
            'contacto' => [
                'nombre'   => $cl_row['contacto_nombre']   ?? '',
                'apellido' => $cl_row['contacto_apellido'] ?? '',
                'cargo'    => $cl_row['contacto_cargo']    ?? '',
                'telefono' => $cl_row['contacto_telefono'] ?? '',
                'email'    => $cl_row['contacto_email']    ?? '',
            ],
        ];
    }
}

require_once __DIR__ . '/../../templates/layout.php';

?>
<?php
// Mapa descuento_maximo por norma (para validación JS)
$normas_desc_max = [];
foreach ($normas as $nm) { $normas_desc_max[$nm['id']] = (int)$nm['descuento_maximo']; }
?>
<style>
  .form-card { background:var(--bg-surface); border-radius:12px; border:1px solid var(--gris-borde); overflow:hidden; max-width:860px; }
  .form-section { padding:24px 28px; border-bottom:1px solid var(--gris-borde); }
  .form-section:last-of-type { border-bottom:none; }
  .section-title { display:flex; align-items:center; gap:8px; font-size:13px; font-weight:700; color:var(--azul); margin-bottom:18px; }
  .fields-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
  .span-2 { grid-column:1/-1; }
  .field { display:flex; flex-direction:column; gap:5px; }
  .field label { font-size:12px; font-weight:600; color:var(--azul); }
  .field input, .field select, .field textarea {
    padding:10px 13px; border:1.5px solid var(--gris-borde); border-radius:9px;
    font-size:13px; font-family:'Inter',sans-serif;
    background:var(--bg-surface2); outline:none; width:100%; transition:border-color 0.2s;
  }
  .field input:focus, .field select:focus, .field textarea:focus { border-color:var(--azul); background:var(--bg-surface); }
  .field textarea { resize:vertical; min-height:80px; }
  .field input.error, .field select.error { border-color:#ef4444; }
  .err-msg { font-size:11px; color:#ef4444; margin-top:2px; }

  /* Empresa card (empresa existente seleccionada) */
  .empresa-card {
    display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;
    background:var(--s-info-bg); border:1.5px solid #bfdbfe; border-radius:10px; padding:14px 16px;
  }
  .empresa-card-info { flex:1; min-width:0; }
  .empresa-card-nombre { font-size:14px; font-weight:700; color:#1d4ed8; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .empresa-card-ruc { font-size:11px; color:#3b82f6; margin-top:2px; font-family:monospace; }
  .empresa-card-estado { margin-top:5px; }
  .empresa-card-change { font-size:12px; font-weight:600; color:#6b7280; background:var(--bg-surface); border:1.5px solid #e2e8f0; border-radius:7px; padding:7px 14px; cursor:pointer; white-space:nowrap; flex-shrink:0; transition:all .15s; }
  .empresa-card-change:hover { border-color:#94a3b8; color:#374151; }
  /* Status line bajo el buscador */
  .empresa-status { font-size:11px; margin-top:4px; min-height:16px; }

  /* ── Normas ISO — fila horizontal ── */
  .norma-item {
    display: flex; align-items: stretch;
    border: 1.5px solid var(--gris-borde); border-radius: 10px;
    background: var(--s-info-bg); overflow: hidden;
    transition: border-color .18s, box-shadow .18s;
  }
  .norma-item:hover { border-color: #93c5fd; box-shadow: 0 1px 6px rgba(37,99,235,.08); }
  .norma-item:has(input:checked) {
    border-color: var(--azul); background:var(--s-info-bg);
    box-shadow: 0 1px 8px rgba(37,99,235,.13);
  }
  .norma-header {
    display: flex; align-items: center; gap: 10px;
    padding: 11px 14px; flex: 1; min-width: 0;
    cursor: pointer;   /* solo el header es clickeable para toggle */
    user-select: none; /* evitar selección de texto al hacer clic rápido */
  }
  /* Los campos inline NO heredan cursor:pointer ni user-select:none */
  .norma-inline-fields,
  .norma-inline-fields * {
    user-select: text;
    cursor: auto;
  }
  .norma-inline-fields input,
  .norma-inline-fields select {
    cursor: text;
  }
  .norma-inline-fields select { cursor: pointer; }
  .norma-item input[type=checkbox] { width:16px; height:16px; accent-color:var(--azul); flex-shrink:0; cursor:pointer; }
  .norma-nombre { flex:1; font-size:13px; font-weight:600; line-height:1.4; color:#1e293b; min-width:0; }
  .norma-desc-text { font-weight:400; color:var(--gris-texto); }
  .norma-badge-precio {
    font-size:11px; font-weight:600; color:#64748b;
    background:var(--s-info-bg); border:1px solid #e2e8f0; border-radius:20px;
    padding:2px 9px; flex-shrink:0; white-space:nowrap;
  }
  .norma-item:has(input:checked) .norma-badge-precio { display:none; }
  /* Panel derecho: campos en línea */
  .norma-inline-fields {
    display: none; /* JS: checked → flex */
    flex-direction: row; align-items: center;
    border-left: 2px solid rgba(37,99,235,.2);
    background: rgba(239,246,255,.75);
    flex-shrink: 0;
  }
  .norma-field-group {
    display: flex; flex-direction: column; align-items: flex-start;
    gap: 3px; padding: 8px 12px;
    border-right: 1px solid rgba(37,99,235,.1);
  }
  .norma-field-group:last-child { border-right: none; }
  .norma-field-lbl {
    font-size: 9px; font-weight: 700; color: #64748b;
    text-transform: uppercase; letter-spacing: .6px; white-space: nowrap;
  }
  .norma-precio {
    width: 90px; padding: 5px 8px; border: 1.5px solid #cbd5e1; border-radius: 6px;
    font-size: 13px; font-weight: 500; font-family: 'Inter',sans-serif;
    outline: none; background:var(--bg-surface); transition: border-color .15s; -moz-appearance: textfield;
  }
  .norma-precio:focus { border-color:var(--azul); box-shadow:0 0 0 2px rgba(37,99,235,.12); }
  .norma-precio::-webkit-outer-spin-button, .norma-precio::-webkit-inner-spin-button { -webkit-appearance:none; margin:0; }
  .norma-descuento {
    width: 74px; padding: 5px 8px; border: 1.5px solid #cbd5e1; border-radius: 6px;
    font-size: 13px; font-weight: 500; font-family: 'Inter',sans-serif;
    outline: none; background:var(--bg-surface); transition: border-color .15s; -moz-appearance: textfield;
  }
  .norma-descuento:focus { border-color:var(--azul); box-shadow:0 0 0 2px rgba(37,99,235,.12); }
  .norma-descuento::-webkit-outer-spin-button, .norma-descuento::-webkit-inner-spin-button { -webkit-appearance:none; margin:0; }
  .norma-max-hint { font-size: 9px; color: #94a3b8; line-height: 1.2; }
  .desc-alerta { font-size: 9px; color: #ef4444; font-weight: 600; display: none; line-height: 1.2; }
  .norma-casa-select {
    width: 156px; padding: 5px 7px; border: 1.5px solid #cbd5e1; border-radius: 6px;
    font-size: 11.5px; font-family: 'Inter',sans-serif;
    outline: none; background:var(--bg-surface); cursor: pointer; transition: border-color .15s;
  }
  .norma-casa-select:focus { border-color:var(--azul); box-shadow:0 0 0 2px rgba(37,99,235,.12); }
  /* Mobile: apila vertical */
  @media (max-width:700px) {
    .norma-item { flex-direction:column; }
    .norma-inline-fields { border-left:none; border-top:2px solid rgba(37,99,235,.15); flex-wrap:wrap; align-items:flex-start; }
    .norma-field-group { flex:1; min-width:110px; }
    .norma-precio, .norma-descuento, .norma-casa-select { width:100%; }
  }

  .form-actions { padding:16px 28px; background:var(--bg-surface2); border-top:1px solid var(--gris-borde); display:flex; gap:10px; flex-wrap:wrap; }

  @media (max-width:768px) {
    .fields-grid { grid-template-columns:1fr; }
    .span-2 { grid-column:1; }
    .form-section { padding:18px 16px; }
    .form-actions { padding:14px 16px; }
  }
</style>

<?php if (!$_ggi_embed): ?>
<?php
$_bc_label = $_edit_id ? 'Editar propuesta' : ($_cert_renovar ? 'Renovar certificado' : (!empty($_GET['duplicar']) ? 'Duplicar propuesta' : 'Nueva propuesta'));
ggi_breadcrumb([
    ['label' => 'Propuestas', 'href' => APP_URL.'/modules/cotizaciones/index.php', 'ss' => 'ggi_ss_cotizaciones'],
    ['label' => $_bc_label],
]);
?>
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
  <div>
    <h1><?= $_edit_id ? 'Editar propuesta' : ($_cert_renovar ? 'Renovar certificado' : (!empty($_GET['duplicar']) ? 'Duplicar propuesta' : 'Nueva propuesta')) ?></h1>
    <p><?= $_edit_id ? 'Modifica los datos de la propuesta '.sanitize($_cot_edit['numero'] ?? '#'.$_edit_id) : ($_cert_renovar ? 'Nueva propuesta de renovación para '.sanitize($_cert_renovar['razon_social']) : (!empty($_GET['duplicar']) ? 'Crea una copia de una propuesta existente' : 'Registra una propuesta')) ?></p>
  </div>
</div>
<?php endif; ?>

<?php if ($_cert_renovar): ?>
<div style="background:var(--s-warn-bg);border:1px solid #fde68a;border-left:4px solid #f59e0b;padding:14px 18px;border-radius:10px;font-size:13px;color:var(--s-warn-tx);margin-bottom:18px;display:flex;align-items:flex-start;gap:12px;">
  <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="#f59e0b" stroke-width="2" style="flex-shrink:0;margin-top:1px;"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.12"/></svg>
  <div>
    <strong style="font-weight:700;">Renovación de certificado</strong><br>
    Certificado <code style="background:var(--s-warn-bg);padding:1px 5px;border-radius:4px;"><?= sanitize($_cert_renovar['numero_certificado'] ?: 'S/N') ?></code>
    — norma <strong><?= sanitize($_cert_renovar['norma_codigo']) ?></strong>
    — vencía el <strong><?= formato_fecha($_cert_renovar['fecha_vencimiento']) ?></strong>.<br>
    <span style="opacity:.8;">La empresa y norma ya están pre-seleccionadas. Completa el precio y envía al cliente.</span>
  </div>
</div>
<input type="hidden" name="renovar_cert_id" value="<?= $_renovar_cert_id ?>">
<?php endif; ?>

<?php
// action EXPLÍCITA → garantiza que el POST llegue a crear.php (que procesa el guardado),
// y no a la URL que la navegación SPA dejó en location.href (p.ej. editar.php, que solo
// redirige y descartaría el POST). Sin esto, la edición "parece guardar" pero no persiste.
$_form_qs = [];
if ($_edit_id)              $_form_qs[] = 'edit=' . (int)$_edit_id;
if (!empty($_GET['embed'])) $_form_qs[] = 'embed=1';
$_form_action = APP_URL . '/modules/cotizaciones/crear.php' . ($_form_qs ? '?' . implode('&', $_form_qs) : '');
?>
<form method="POST" action="<?= htmlspecialchars($_form_action, ENT_QUOTES) ?>" id="form-cot-crear" data-ggi-draft="cot-crear" novalidate>
<?= csrf_field() ?>
<?php if ($_ggi_embed): ?><input type="hidden" name="_embed" value="1"><?php endif; ?>

  <!-- 1. Empresa (búsqueda unificada) -->
  <div class="form-section">
    <div class="section-title">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
      Empresa
    </div>

    <!-- Inputs hidden de estado -->
    <input type="hidden" name="tipo_empresa" id="tipo_empresa_input" value="<?= sanitize($data['tipo_empresa'] ?? 'nueva') ?>">
    <input type="hidden" name="cliente_id"   id="cliente_id_hidden"  value="<?= (int)($data['cliente_id'] ?? 0) ?: '' ?>">

    <!-- Panel A: buscador (siempre visible salvo cuando empresa existente seleccionada) -->
    <div id="panel-empresa-buscar" style="display:<?= $prefill_empresa ? 'none' : 'block' ?>;">
      <div class="field">
        <label>Buscar empresa <span class="req">*</span></label>
        <div class="ss-wrap">
          <svg class="ss-icon" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <input type="text" id="empresa-search-input" class="ss-input <?= isset($errors['cliente_id'])||isset($errors['empresa_nombre'])?'error':'' ?>"
                 placeholder="Escribe RUC o nombre de empresa..." autocomplete="off"
                 value="<?= !$prefill_empresa && $data['tipo_empresa']==='nueva' ? sanitize($data['empresa_nombre']??'') : '' ?>">
          <div id="empresa-search-dropdown" class="ss-dropdown"></div>
        </div>
        <div id="empresa-search-status" class="empresa-status"></div>
        <?php if (isset($errors['cliente_id'])): ?><div class="err-msg"><?= $errors['cliente_id'] ?></div><?php endif; ?>
        <?php if (isset($errors['empresa_nombre'])): ?><div class="err-msg"><?= $errors['empresa_nombre'] ?></div><?php endif; ?>
      </div>
    </div>

    <!-- Panel B: empresa existente seleccionada -->
    <div id="panel-empresa-card" style="display:<?= $prefill_empresa ? 'block' : 'none' ?>;">
      <?php
        $_pe_estado = is_array($prefill_empresa) ? ($prefill_empresa['estado'] ?? '') : '';
        $est_badges = ['activo'=>['var(--s-ok-bg)','var(--s-ok-tx)'],'prospecto'=>['var(--s-warn-bg)','var(--s-warn-tx)'],'inactivo'=>['#f1f5f9','#475569']];
        $eb = $est_badges[$_pe_estado] ?? ['#f1f5f9','#475569'];
        $est_label = ['activo'=>'Activo','prospecto'=>'Prospecto','inactivo'=>'Inactivo'][$_pe_estado] ?? $_pe_estado;
      ?>
      <div class="empresa-card">
        <div class="empresa-card-info">
          <div class="empresa-card-nombre"><?= sanitize(is_array($prefill_empresa) ? ($prefill_empresa['razon_social'] ?? '') : '') ?></div>
          <div class="empresa-card-ruc"><?= (is_array($prefill_empresa) && !empty($prefill_empresa['ruc'])) ? 'RUC: '.sanitize($prefill_empresa['ruc']) : '' ?></div>
          <div class="empresa-card-estado">
            <span style="display:inline-block;padding:2px 9px;border-radius:100px;font-size:11px;font-weight:600;background:<?= $eb[0] ?>;color:<?= $eb[1] ?>;"><?= $est_label ?></span>
          </div>
        </div>
        <button type="button" class="empresa-card-change" onclick="limpiarEmpresa()">↩ Cambiar empresa</button>
        <button type="button" id="btn-sunat-verificar" onclick="verificarConSUNAT()"
                style="margin-left:6px;font-size:11px;padding:4px 10px;border:1px solid #d1d5db;border-radius:5px;background:var(--bg-surface2);color:#374151;cursor:pointer;white-space:nowrap;"
                title="Comparar datos del cliente con SUNAT">↻ Verificar SUNAT</button>
      </div>
      <div id="sunat-diff-panel" style="display:none;margin-top:10px;padding:12px;background:var(--s-warn-bg);border:1px solid #fde68a;border-radius:6px;font-size:12px;"></div>
    </div>

    <!-- Panel C: datos de empresa nueva (pre-llenados desde SUNAT o manual) -->
    <div id="panel-empresa-nueva" style="display:<?= (!$prefill_empresa && $data['tipo_empresa']==='nueva' && !empty($data['empresa_nombre'])) ? 'block' : 'none' ?>;">
      <div style="margin-top:14px;padding-top:14px;border-top:1px dashed var(--gris-borde);">
        <div style="font-size:11px;font-weight:600;color:var(--azul);margin-bottom:10px;display:flex;align-items:center;gap:6px;">
          <svg width="11" height="11" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Empresa nueva — completa los datos
        </div>
        <div class="fields-grid">
          <div class="field">
            <label>RUC</label>
            <input type="text" name="empresa_ruc" id="empresa_ruc" maxlength="11" inputmode="numeric"
                   placeholder="Ej: 20601093899" value="<?= sanitize($data['empresa_ruc']??'') ?>"
                   oninput="this.value=this.value.replace(/\D/g,'')">
          </div>
          <div class="field">
            <label>Razón social <span class="req">*</span> <span style="font-size:10px;color:var(--verde);font-weight:500;">● Auto SUNAT</span></label>
            <input type="text" name="empresa_nombre" id="empresa_nombre"
                   placeholder="Nombre de la empresa"
                   value="<?= sanitize($data['empresa_nombre']??'') ?>">
          </div>
          <div class="field span-2">
            <label>Dirección fiscal <span style="font-size:10px;color:var(--verde);font-weight:500;">● Auto SUNAT</span></label>
            <input type="text" name="empresa_direccion" id="empresa_direccion"
                   placeholder="Se completa automáticamente al consultar RUC"
                   value="<?= sanitize($data['empresa_direccion']??'') ?>">
            <input type="hidden" name="empresa_dep"    id="empresa_dep"    value="<?= sanitize($data['empresa_dep']??'') ?>">
            <input type="hidden" name="empresa_prov"   id="empresa_prov"   value="<?= sanitize($data['empresa_prov']??'') ?>">
            <input type="hidden" name="empresa_dist"   id="empresa_dist"   value="<?= sanitize($data['empresa_dist']??'') ?>">
            <input type="hidden" name="empresa_ubigeo" id="empresa_ubigeo" value="<?= sanitize($data['empresa_ubigeo']??'') ?>">
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Banner contacto empresa seleccionada -->
  <div id="contacto-banner" style="display:none;max-width:860px;margin-bottom:0;">
    <div style="background:var(--s-info-bg);border:1px solid #bfdbfe;border-bottom:none;padding:12px 20px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
      <div style="display:flex;align-items:center;gap:8px;">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="#3B82F6" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        <div>
          <div style="font-size:12px;font-weight:700;color:#1d4ed8;">Esta empresa ya tiene datos de contacto registrados</div>
          <div id="banner-contacto-preview" style="font-size:11px;color:#3B82F6;margin-top:1px;"></div>
        </div>
      </div>
      <div style="display:flex;gap:6px;flex-shrink:0;">
        <button type="button" onclick="usarContactoEmpresa()"
          style="padding:6px 14px;background:var(--ac);color:#fff;border:none;border-radius:7px;font-size:12px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;">
          ✓ Usar estos datos
        </button>
        <button type="button" onclick="ignorarContactoEmpresa()"
          style="padding:6px 14px;background:var(--bg-surface);color:#6B7280;border:1.5px solid #e2e8f0;border-radius:7px;font-size:12px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;">
          Ingresar nuevos
        </button>
      </div>
    </div>
  </div>

  <!-- 2. Representante legal -->
  <div class="form-section">
    <div class="section-title">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
      Representante legal
      <span style="margin-left:auto;font-size:10px;font-weight:500;color:var(--gris-texto);background:var(--azul-light);padding:2px 8px;border-radius:100px;display:flex;align-items:center;gap:4px;">
        <svg width="10" height="10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        Se autocompleta al consultar RUC
      </span>
    </div>

    <!-- Banner: advertencia cuando el representante legal no se pudo obtener de SUNAT -->
    <div id="rep-legal-banner" style="display:none;background:var(--s-warn-bg);border:1px solid #fde68a;border-left:4px solid #f59e0b;border-radius:8px;padding:12px 16px;margin-bottom:16px;display:none;">
      <div style="display:flex;align-items:flex-start;gap:10px;flex-wrap:wrap;">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="var(--s-warn-tx)" stroke-width="2" style="flex-shrink:0;margin-top:1px;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <div style="flex:1;">
          <div id="rep-legal-msg" style="font-size:12px;font-weight:700;color:var(--s-warn-tx);"></div>
          <div id="rep-legal-hint" style="font-size:11px;color:var(--s-warn-tx);margin-top:2px;"></div>
        </div>
        <button type="button" id="btn-rep-retry" onclick="reintentarRepresentante()"
          style="display:none;padding:6px 14px;background:var(--s-warn);color:#fff;border:none;border-radius:7px;font-size:12px;font-weight:600;cursor:pointer;white-space:nowrap;font-family:'Inter',sans-serif;flex-shrink:0;">
          <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" style="display:inline;vertical-align:-1px;margin-right:4px;"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
          Reintentar
        </button>
      </div>
    </div>

    <div class="fields-grid">
      <div class="field">
        <label>Nombre <span style="font-size:10px;color:var(--verde);font-weight:500;">● Auto</span></label>
        <input type="text" id="contacto_nombre" name="contacto_nombre" placeholder="Nombre del representante" value="<?= sanitize($data['contacto_nombre']??'') ?>">
      </div>
      <div class="field">
        <label>Apellido <span style="font-size:10px;color:var(--verde);font-weight:500;">● Auto</span></label>
        <input type="text" id="contacto_apellido" name="contacto_apellido" placeholder="Apellido del representante" value="<?= sanitize($data['contacto_apellido']??'') ?>">
      </div>
      <div class="field">
        <label>Cargo <span style="font-size:10px;color:var(--verde);font-weight:500;">● Auto</span></label>
        <input type="text" id="contacto_cargo" name="contacto_cargo" placeholder="Gerente General, Titular-Gerente..." value="<?= sanitize($data['contacto_cargo']??'') ?>">
      </div>
      <div class="field">
        <label>N.º trabajadores <span style="font-size:10px;color:var(--verde);font-weight:500;">● Auto SUNAT</span></label>
        <input type="number" name="num_trabajadores" id="num_trabajadores" min="0" inputmode="numeric"
               placeholder="Se completa al consultar la empresa"
               value="<?= (int)($data['num_trabajadores']??0) ?: '' ?>">
      </div>
      <div class="field">
        <label>Correo electrónico</label>
        <input type="email" name="contacto_email" placeholder="correo@empresa.com" value="<?= sanitize($data['contacto_email']??'') ?>">
      </div>
      <div class="field">
        <label>Celular <span class="req">*</span></label>
        <input type="tel" name="contacto_telefono" inputmode="numeric" autocomplete="off"
               class="<?= isset($errors['contacto_telefono'])?'error':'' ?>"
               placeholder="Ej: 999888777" value="<?= sanitize($data['contacto_telefono']??'') ?>">
        <?php if (isset($errors['contacto_telefono'])): ?>
        <div class="err-msg"><?= $errors['contacto_telefono'] ?></div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- 3. Normas ISO -->
  <div class="form-section">
    <div class="section-title">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/></svg>
      Normas ISO a cotizar *
    </div>
    <?php if (isset($errors['normas'])): ?>
    <div class="err-msg" style="margin-bottom:12px;"><?= $errors['normas'] ?></div>
    <?php endif; ?>

    <?php if (!empty($normas_principales)): ?>
    <div style="font-size:10px;font-weight:700;color:var(--gris-texto);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;">Principales</div>
    <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:16px;">
      <?php foreach ($normas_principales as $n):
        $checked = in_array($n['id'], $data['normas'] ?? []);
        $precio_default = $data['precios'][$n['id']] ?? ($n['precio_base'] ?? '');
      ?>
      <div class="norma-item" data-norma-id="<?= $n['id'] ?>">
        <!-- norma-header: único área que ACTIVA/DESACTIVA la selección -->
        <div class="norma-header" onclick="toggleNormaItem(<?= $n['id'] ?>)">
          <input type="checkbox" name="normas[]" value="<?= $n['id'] ?>" id="norma_<?= $n['id'] ?>" <?= $checked?'checked':'' ?>
                 data-precio="<?= (float)($n['precio_base'] ?? 0) ?>"
                 data-auditoria-precio="<?= (float)($n['auditoria_precio'] ?? 0) ?>"
                 onclick="event.stopPropagation()"
                 onchange="togglePrecio(<?= $n['id'] ?>, this.checked)">
          <span class="norma-nombre">
            <?= sanitize($n['codigo']) ?>
            <span class="norma-desc-text">— <?= sanitize($n['nombre']) ?></span>
          </span>
          <?php if ($n['precio_base']): ?>
          <span class="norma-badge-precio">S/ <?= number_format($n['precio_base'],2) ?></span>
          <?php endif; ?>
        </div>
        <!-- norma-inline-fields: BLOQUEA toda propagación para proteger la selección -->
        <div id="precio-wrap-<?= $n['id'] ?>" class="norma-inline-fields" style="display:<?= $checked?'flex':'none' ?>"
             onclick="event.stopPropagation()" onmousedown="event.stopPropagation()">
          <div class="norma-field-group">
            <span class="norma-field-lbl">Precio (S/)</span>
            <input type="number" name="precios[<?= $n['id'] ?>]" class="norma-precio" placeholder="0.00" step="0.01" min="0"
                   value="<?= sanitize($precio_default) ?>" oninput="calcularTotal();">
          </div>
          <div class="norma-field-group">
            <span class="norma-field-lbl">Casa certificadora</span>
            <select name="casas[<?= $n['id'] ?>]" class="norma-casa-select">
              <option value="">— Seleccionar —</option>
              <?php $casa_sel = $data['casas'][$n['id']] ?? 1; foreach ($casas as $ca): ?>
              <option value="<?= $ca['id'] ?>" <?= $casa_sel==$ca['id']?'selected':'' ?>><?= sanitize($ca['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($normas_otras)): ?>
    <div style="font-size:10px;font-weight:700;color:var(--gris-texto);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;">Otras normas</div>
    <div style="display:flex;flex-direction:column;gap:8px;">
      <?php foreach ($normas_otras as $n):
        $checked = in_array($n['id'], $data['normas'] ?? []);
        $precio_default = $data['precios'][$n['id']] ?? ($n['precio_base'] ?? '');
      ?>
      <div class="norma-item" data-norma-id="<?= $n['id'] ?>">
        <div class="norma-header" onclick="toggleNormaItem(<?= $n['id'] ?>)">
          <input type="checkbox" name="normas[]" value="<?= $n['id'] ?>" id="norma_<?= $n['id'] ?>" <?= $checked?'checked':'' ?>
                 data-precio="<?= (float)($n['precio_base'] ?? 0) ?>"
                 data-auditoria-precio="<?= (float)($n['auditoria_precio'] ?? 0) ?>"
                 onclick="event.stopPropagation()"
                 onchange="togglePrecio(<?= $n['id'] ?>, this.checked)">
          <span class="norma-nombre">
            <?= sanitize($n['codigo']) ?>
            <span class="norma-desc-text">— <?= sanitize($n['nombre']) ?></span>
          </span>
          <?php if ($n['precio_base']): ?>
          <span class="norma-badge-precio">S/ <?= number_format($n['precio_base'],2) ?></span>
          <?php endif; ?>
        </div>
        <div id="precio-wrap-<?= $n['id'] ?>" class="norma-inline-fields" style="display:<?= $checked?'flex':'none' ?>"
             onclick="event.stopPropagation()" onmousedown="event.stopPropagation()">
          <div class="norma-field-group">
            <span class="norma-field-lbl">Precio (S/)</span>
            <input type="number" name="precios[<?= $n['id'] ?>]" class="norma-precio" placeholder="0.00" step="0.01" min="0"
                   value="<?= sanitize($precio_default) ?>" oninput="calcularTotal();">
          </div>
          <div class="norma-field-group">
            <span class="norma-field-lbl">Casa certificadora</span>
            <select name="casas[<?= $n['id'] ?>]" class="norma-casa-select">
              <option value="">— Seleccionar —</option>
              <?php $casa_sel = $data['casas'][$n['id']] ?? 1; foreach ($casas as $ca): ?>
              <option value="<?= $ca['id'] ?>" <?= $casa_sel==$ca['id']?'selected':'' ?>><?= sanitize($ca['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- 4. Ejecutivo y estado -->
  <div class="form-section">
    <div class="section-title">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
      Ejecutivo
    </div>
    <div class="fields-grid">
      <div class="field">
        <label>Ejecutivo comercial asignado</label>
        <?php if ($user['rol'] === 'ejecutivo_ventas'): ?>
          <?php
            $ej_propio = array_filter($ejecutivos, fn($e) => $e['id'] == $user['id']);
            $ej_propio = reset($ej_propio);
          ?>
          <input type="hidden" name="ejecutivo_id" value="<?= $user['id'] ?>">
          <input type="text" value="<?= $ej_propio ? sanitize($ej_propio['nombre'].' '.$ej_propio['apellido']) : 'Asignado automáticamente' ?>" disabled style="background:var(--s-ok-bg);border-color:#b7e4c0;color:#229133;font-weight:600;width:100%;padding:10px 13px;border:1.5px solid;border-radius:9px;font-size:13px;font-family:'Inter',sans-serif;">
          <span style="font-size:11px;color:var(--gris-texto);">Se asigna automáticamente.</span>
        <?php else: ?>
          <select name="ejecutivo_id" id="ejecutivo_id" style="width:100%;padding:10px 36px 10px 13px;border:1.5px solid var(--gris-borde);border-radius:9px;font-size:13px;font-family:'Inter',sans-serif;background-color:var(--bg-surface2);outline:none;cursor:pointer;transition:border-color .2s;color:var(--gris-dark);">
            <option value="">— Sin asignar —</option>
            <?php foreach ($ejecutivos as $ej): ?>
            <option value="<?= $ej['id'] ?>" <?= ($data['ejecutivo_id']==$ej['id'])?'selected':'' ?>>
              <?= sanitize($ej['nombre'].' '.$ej['apellido']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        <?php endif; ?>
        <span style="font-size:11px;color:var(--gris-texto);">Se carga automáticamente si la empresa ya tiene ejecutivo asignado.</span>
      </div>
      <input type="hidden" name="estado" value="<?= sanitize($data['estado'] ?? 'enviada') ?>">
      <div class="field">
        <label>Fecha de emisión</label>
        <input type="date" name="fecha_emision" value="<?= sanitize($data['fecha_emision'] ?? date('Y-m-d')) ?>">
      </div>
      <div class="field">
        <label>Fecha de vencimiento</label>
        <input type="date" name="fecha_vencimiento" value="<?= sanitize($data['fecha_vencimiento']??date('Y-m-d', strtotime('+4 days'))) ?>">
        <span style="font-size:11px;color:var(--gris-texto);">Por defecto 4 días desde hoy.</span>
      </div>
    </div>
  </div>

  <!-- 5. Resumen económico -->
  <div class="form-section">
    <div class="section-title">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
      Resumen económico
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:start;">
      <!-- Descuento global -->
      <div class="field" style="margin:0;">
        <label style="font-size:12px;font-weight:600;color:var(--azul);">Descuento global (S/)</label>
        <input type="number" name="descuento" id="inp-descuento" placeholder="0" step="1" min="0"
               value="<?= (float)($data['descuento'] ?? 0) ?: '' ?>"
               oninput="calcularTotal()">
        <span style="font-size:11px;color:var(--gris-texto);">Descuento aplicado al total de la propuesta.</span>
      </div>
      <!-- Resumen de montos -->
      <div style="background:var(--s-info-bg);border:1px solid var(--gris-borde);border-radius:10px;padding:16px;">
        <div style="display:flex;justify-content:space-between;font-size:13px;padding:5px 0;color:var(--gris-texto);">
          <span>Subtotal normas</span>
          <span id="lbl-subtotal">S/ 0.00</span>
        </div>
        <div id="fila-descuento" style="display:none;justify-content:space-between;font-size:13px;padding:5px 0;color:#EF4444;">
          <span>Descuento</span>
          <span id="lbl-descuento-monto">— S/ 0.00</span>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;font-size:13px;padding:5px 0;">
          <label style="display:flex;align-items:center;gap:7px;cursor:pointer;color:var(--gris-texto);margin:0;font-weight:400;">
            <input type="checkbox" name="incluye_igv" id="chk-igv" value="1" <?= ($data['incluye_igv'] ?? true) ? 'checked' : '' ?>
                   onchange="calcularTotal()"
                   style="accent-color:var(--azul);width:14px;height:14px;cursor:pointer;flex-shrink:0;">
            IGV (18%)
          </label>
          <span id="lbl-igv-monto" style="color:var(--gris-texto);">S/ 0.00</span>
        </div>
        <div style="border-top:1.5px solid var(--gris-borde);margin-top:8px;padding-top:10px;display:flex;justify-content:space-between;font-size:16px;font-weight:700;color:var(--azul);">
          <span>Total</span>
          <span id="lbl-total">S/ 0.00</span>
        </div>
        <input type="hidden" name="monto_total" id="inp-monto-total" value="<?= sanitize($data['monto_total']??'0') ?>">
        <?php if ($user['rol'] === 'ejecutivo_ventas'): ?>
        <div id="aviso-monto-min" style="display:none;margin-top:8px;padding:8px 12px;background:var(--s-err-bg);border:1px solid var(--s-err-brd);border-radius:8px;font-size:12px;font-weight:600;color:var(--s-err-tx);">
          El monto total no puede ser menor a S/ 500.00.
        </div>
        <?php endif; ?>
        <!-- Bono estimado ejecutivo -->
        <div id="bono-estimado-row" style="display:none;margin-top:10px;padding:8px 10px;background:var(--s-ok-bg);border:1px solid var(--s-ok-brd);border-radius:8px;">
          <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
            <span style="display:flex;align-items:center;gap:5px;font-size:11px;font-weight:700;color:var(--s-ok-tx);text-transform:uppercase;letter-spacing:.4px;">
              <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/></svg>
              Tu comisión estimada
            </span>
            <span id="lbl-bono-estimado" style="font-size:14px;font-weight:800;color:var(--s-ok-tx);">S/ 0.00</span>
          </div>
          <div style="font-size:10px;color:var(--s-ok-tx);margin-top:2px;" id="bono-normas-detalle"></div>
        </div>
      </div>
    </div>
  </div>


  <!-- Auditorías de seguimiento -->
  <div class="form-section">
    <div class="section-title">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
      Auditorías de seguimiento
      <span style="font-size:11px;font-weight:400;color:var(--gris-texto);">— No se suman al total</span>
    </div>
    <?php
      $meses_es = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
      $mes_label = $meses_es[(int)date('n')];
      $aud1_fecha = $mes_label . ' ' . (date('Y')+1);
      $aud2_fecha = $mes_label . ' ' . (date('Y')+2);
    ?>
    <div style="background:var(--s-info-bg);border:1px solid #bfdbfe;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:12px;color:#1d4ed8;">
      <strong>Cálculo automático:</strong> al seleccionar las normas ISO, el sistema suma el precio de auditoría configurado por cada norma. Puedes modificar el valor manualmente si es necesario.
      <span style="margin-left:8px;font-weight:700;">Total calculado: S/ <span id="lbl-aud-calc">0.00</span></span>
    </div>
    <div class="fields-grid">
      <div class="field">
        <label>Costo Auditoría 1 — <?= $aud1_fecha ?> (S/)</label>
        <input type="number" name="auditoria_precio" id="inp-auditoria1" placeholder="0.00" step="0.01" min="0"
               value="<?= sanitize($data['auditoria_precio']??'') ?>"
               oninput="document.getElementById('inp-auditoria2').value=this.value;window._audManual=true;">
        <span style="font-size:11px;color:var(--gris-texto);">Auditoría 1 y Auditoría 2 siempre tienen el mismo monto.</span>
      </div>
      <div class="field">
        <label>Costo Auditoría 2 — <?= $aud2_fecha ?> (S/)</label>
        <input type="number" name="auditoria_precio2" id="inp-auditoria2" placeholder="0.00" step="0.01" min="0"
               value="<?= sanitize($data['auditoria_precio2']??'') ?>"
               oninput="document.getElementById('inp-auditoria1').value=this.value;window._audManual=true;">
      </div>
    </div>
  </div>

  <!-- Observaciones -->
  <div class="form-section">
    <div class="section-title">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
      Observaciones
    </div>
    <div class="fields-grid">
      <div class="field span-2">
        <label>Observaciones <span style="font-weight:400;font-size:11px;color:var(--gris-texto);">(aparece en PDF)</span></label>
        <textarea name="observaciones" placeholder="Condiciones especiales, términos, texto adicional para el cliente..."><?= sanitize($data['observaciones']??'') ?></textarea>
      </div>
      <div class="field span-2">
        <label>Nota interna <span style="font-weight:400;font-size:11px;color:var(--gris-texto);">(solo visible en el sistema)</span></label>
        <textarea name="notas" placeholder="Comentarios internos, seguimiento, recordatorios..."><?= sanitize($data['notas']??'') ?></textarea>
      </div>
    </div>
  </div>

  <div class="form-actions">
  <button type="submit" class="btn btn-primary">
    <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
    <?= $_edit_id ? 'Guardar cambios' : 'Guardar propuesta' ?>
  </button>
  <?php if (!$_ggi_embed): ?>
  <a href="<?= $back_url ?>" class="btn btn-outline">Cancelar</a>
  <?php endif; ?>
  </div>

</div><!-- /form-card -->
</form>

<script>
// ── Empresa: máquina de estados ──────────────────────────────
// state: 'buscar' | 'existente' | 'nueva'
function setEmpresaState(state) {
  document.getElementById('panel-empresa-buscar').style.display = (state !== 'existente') ? 'block' : 'none';
  document.getElementById('panel-empresa-card').style.display   = (state === 'existente')  ? 'block' : 'none';
  document.getElementById('panel-empresa-nueva').style.display  = (state === 'nueva')       ? 'block' : 'none';
  document.getElementById('tipo_empresa_input').value = (state === 'existente') ? 'existente' : 'nueva';
}

var _ESTADO_BADGE = {
  activo:    { bg:'var(--s-ok-bg)', fg:'var(--s-ok-tx)', label:'Activo'    },
  prospecto: { bg:'var(--s-warn-bg)', fg:'var(--s-warn-tx)', label:'Prospecto' },
  inactivo:  { bg:'#f1f5f9', fg:'#475569', label:'Inactivo'  },
};

function seleccionarEmpresaExistente(id, ruc, nombre, estado, ejec_id, contacto, extra) {
  document.getElementById('cliente_id_hidden').value = id;
  document.getElementById('panel-empresa-card').querySelector('.empresa-card-nombre').textContent = nombre;
  const rucEl = document.getElementById('panel-empresa-card').querySelector('.empresa-card-ruc');
  rucEl.textContent = ruc ? 'RUC: ' + ruc : '';
  const estEl = document.getElementById('panel-empresa-card').querySelector('.empresa-card-estado');
  const eb = _ESTADO_BADGE[estado] || { bg:'#f1f5f9', fg:'#475569', label: estado || '' };
  estEl.innerHTML = '<span style="display:inline-block;padding:2px 9px;border-radius:100px;font-size:11px;font-weight:600;background:'+eb.bg+';color:'+eb.fg+';">'+eb.label+'</span>';
  setEmpresaState('existente');
  // N.º trabajadores: usar el guardado del cliente; si está vacío, consultarlo a
  // SUNAT en segundo plano (igual que al crear una empresa) y setearlo al volver.
  var _nt = document.getElementById('num_trabajadores');
  if (_nt) {
    if (extra && extra.num_trabajadores != null && extra.num_trabajadores !== '') {
      _nt.value = extra.num_trabajadores;
    } else {
      _nt.value = '';
      if (ruc && typeof consultarSUNAT === 'function') {
        consultarSUNAT(ruc, function(item){
          var info = (item && item._data) ? item._data : {};
          if (info.num_trabajadores != null && _nt.value === '') _nt.value = info.num_trabajadores;
        });
      }
    }
  }
  if (typeof autoEjecutivo          === 'function') autoEjecutivo(id, ejec_id);
  if (typeof checkContacto          === 'function') checkContacto(id, contacto);
  if (typeof actualizarNormasExistentes === 'function') actualizarNormasExistentes(id);
  setEmpresaStatus('');
  // Guardar datos actuales para comparación SUNAT
  _clienteActual = Object.assign({ id, ruc, razon_social: nombre, estado }, extra || {});
  // Resetear diff panel si estaba abierto de una selección anterior
  const dp = document.getElementById('sunat-diff-panel');
  if (dp) dp.style.display = 'none';
  const vBtn = document.getElementById('btn-sunat-verificar');
  if (vBtn) { vBtn.disabled = false; vBtn.textContent = '↻ Verificar SUNAT'; }
}

function limpiarEmpresa() {
  document.getElementById('cliente_id_hidden').value = '';
  const si = document.getElementById('empresa-search-input');
  if (si) { si.value = ''; }
  ['empresa_ruc','empresa_nombre','empresa_direccion','empresa_dep','empresa_prov','empresa_dist','empresa_ubigeo','num_trabajadores'].forEach(function(id){
    var el = document.getElementById(id); if (el) el.value = '';
  });
  ['contacto_nombre','contacto_apellido','contacto_cargo','contacto_telefono','contacto_email'].forEach(function(n){
    var el = document.querySelector('[name="'+n+'"]'); if (el) el.value = '';
  });
  setEmpresaState('buscar');
  if (typeof actualizarNormasExistentes === 'function') actualizarNormasExistentes(null);
  setEmpresaStatus('');
  setTimeout(function(){ var inp = document.getElementById('empresa-search-input'); if (inp) inp.focus(); }, 50);
}

function setEmpresaStatus(type, msg) {
  var el = document.getElementById('empresa-search-status');
  if (!el) return;
  var colors = { ok:'var(--s-ok-tx)', err:'#c53030', warn:'var(--s-warn-tx)', info:'var(--gris-texto)' };
  el.textContent = msg || '';
  el.style.color = colors[type] || '';
}

/**
 * toggleNormaItem — activado SOLO desde el clic en .norma-header.
 * El checkbox tiene onclick="event.stopPropagation()" para evitar doble toggle
 * cuando el usuario hace clic directamente sobre él.
 */
function toggleNormaItem(id) {
  const cb = document.getElementById('norma_' + id);
  if (!cb) return;
  cb.checked = !cb.checked;
  // Disparar manualmente togglePrecio ya que el cambio programático no dispara 'change'
  togglePrecio(id, cb.checked);
}

function togglePrecio(id, checked) {
  const wrap = document.getElementById('precio-wrap-' + id);
  if (!wrap) return;
  wrap.style.display = checked ? 'flex' : 'none';
  if (checked) {
    // Auto-fill precio_base si el campo está vacío
    const inp = document.querySelector('input[name="precios[' + id + ']"]');
    const chk = document.getElementById('norma_' + id);
    if (inp && (!inp.value || inp.value === '0') && chk && chk.dataset.precio > 0) {
      inp.value = parseFloat(chk.dataset.precio).toFixed(2);
    }
  } else {
    const inp = document.querySelector('input[name="precios[' + id + ']"]');
    if (inp) inp.value = '';
  }
  calcularTotal();
}

// ── Bonos config map: normaId → montoComision ──
var BONOS_MAP = <?= json_encode($_bono_js_map) ?>;
// Solo mostrar a ejecutivo_ventas
var _esEjecutivo = <?= $user['rol'] === 'ejecutivo_ventas' ? 'true' : 'false' ?>;

function calcularTotal() {
  let subtotal = 0;
  document.querySelectorAll('input[name^="precios["]').forEach(inp => {
    const normaId = inp.name.replace('precios[','').replace(']','');
    const checkbox = document.getElementById('norma_' + normaId);
    if (checkbox && checkbox.checked && inp.value) subtotal += parseFloat(inp.value) || 0;
  });

  // Descuento global único
  const descInp = document.getElementById('inp-descuento');
  let descMonto = descInp ? (parseFloat(descInp.value) || 0) : 0;
  descMonto = Math.max(0, descMonto);

  const base      = Math.max(0, subtotal - descMonto);
  const chkIgv    = document.getElementById('chk-igv');
  const igvMonto  = (chkIgv && chkIgv.checked) ? Math.round(base * 0.18 * 100) / 100 : 0;
  const total     = base + igvMonto;

  document.getElementById('lbl-subtotal').textContent   = 'S/ ' + subtotal.toFixed(2);
  document.getElementById('lbl-igv-monto').textContent  = 'S/ ' + igvMonto.toFixed(2);
  document.getElementById('lbl-total').textContent      = 'S/ ' + total.toFixed(2);

  const filaDesc = document.getElementById('fila-descuento');
  if (descMonto > 0 && subtotal > 0) {
    filaDesc.style.display = 'flex';
    document.getElementById('lbl-descuento-monto').textContent = '— S/ ' + descMonto.toFixed(2);
  } else {
    filaDesc.style.display = 'none';
  }

  const hiddenInput = document.getElementById('inp-monto-total');
  if (hiddenInput) hiddenInput.value = total.toFixed(2);

  // ── Monto mínimo S/ 500 para ejecutivo_ventas ──
  if (_esEjecutivo) {
    const avisoMin = document.getElementById('aviso-monto-min');
    const btnGuardar = document.querySelector('.form-actions button[type="submit"]');
    const montoInvalido = total > 0 && total < 500;
    if (avisoMin) avisoMin.style.display = montoInvalido ? 'block' : 'none';
    if (btnGuardar) {
      btnGuardar.disabled = montoInvalido;
      btnGuardar.style.opacity = montoInvalido ? '0.5' : '';
    }
  }

  // ── Cálculo de bono estimado (solo ejecutivo_ventas) ──
  if (_esEjecutivo) {
    let totalBono = 0;
    const detalleParts = [];
    document.querySelectorAll('input[name="normas[]"]').forEach(cb => {
      if (cb.checked) {
        const nid = parseInt(cb.value);
        const monto = BONOS_MAP[nid] || 0;
        if (monto > 0) {
          totalBono += monto;
          // Get norma code from the norma-item div
          const normaItem = document.getElementById('norma_' + nid)?.closest('.norma-item');
          const code = normaItem ? (normaItem.querySelector('.norma-nombre')?.firstChild?.textContent?.trim() || `N°${nid}`) : `N°${nid}`;
          detalleParts.push(code + ' S/' + monto.toFixed(0));
        }
      }
    });
    const row = document.getElementById('bono-estimado-row');
    if (row) {
      if (totalBono > 0) {
        row.style.display = 'block';
        document.getElementById('lbl-bono-estimado').textContent = 'S/ ' + totalBono.toFixed(2);
        const detDiv = document.getElementById('bono-normas-detalle');
        if (detDiv) detDiv.textContent = detalleParts.join(' · ');
      } else {
        row.style.display = 'none';
      }
    }
  }

  // Calcular total auditoría de seguimiento (suma por norma seleccionada)
  let audTotal = 0;
  document.querySelectorAll('input[name="normas[]"]').forEach(cb => {
    if (cb.checked) audTotal += parseFloat(cb.dataset.auditoriaPrecio || 0);
  });
  const lblAud = document.getElementById('lbl-aud-calc');
  if (lblAud) lblAud.textContent = audTotal.toFixed(2);
  // Auto-fill solo si no fue modificado manualmente
  if (!window._audManual) {
    const a1 = document.getElementById('inp-auditoria1');
    const a2 = document.getElementById('inp-auditoria2');
    if (a1 && audTotal > 0) { a1.value = audTotal.toFixed(2); }
    if (a2 && audTotal > 0) { a2.value = audTotal.toFixed(2); }
  }
}


// Trigger on page load to show current values
ggiReady(calcularTotal);

document.querySelectorAll('.norma-precio').forEach(inp => {
  inp.addEventListener('input', calcularTotal);
});

// ── Consulta SUNAT ─────────────────────────────────────────
var _sunatLoading = false;
var _sunatCache   = {};
var _rucActual    = '';  // RUC actualmente cargado (para reintento de representante)
var _clienteActual = null; // Datos del cliente existente seleccionado (para diff SUNAT)

/**
 * Muestra u oculta el banner de advertencia del representante legal.
 * tipo: 'warn' | 'error' | 'sin_rep' | 'ok' | 'cargando'
 */
function mostrarBannerRepresentante(tipo, msg) {
  const banner = document.getElementById('rep-legal-banner');
  const msgEl  = document.getElementById('rep-legal-msg');
  const hint   = document.getElementById('rep-legal-hint');
  const btn    = document.getElementById('btn-rep-retry');
  if (!banner) return;

  if (tipo === 'ok' || !tipo) {
    banner.style.display = 'none';
    return;
  }

  banner.style.display = 'block';

  if (tipo === 'cargando') {
    msgEl.textContent = '⏳ Consultando representante legal en SUNAT…';
    hint.textContent  = 'Este proceso puede tomar unos segundos.';
    btn.style.display = 'none';
    banner.style.borderLeftColor = '#3B82F6';
    banner.style.background = 'var(--s-info-bg)';
    banner.style.borderColor = '#bfdbfe';
    msgEl.style.color = '#1d4ed8';
    hint.style.color  = '#3B82F6';
    return;
  }

  // Restaurar colores warn
  banner.style.borderLeftColor = '#f59e0b';
  banner.style.background = 'var(--s-warn-bg)';
  banner.style.borderColor = '#fde68a';
  msgEl.style.color = 'var(--s-warn-tx)';
  hint.style.color  = 'var(--s-warn-tx)';

  if (tipo === 'sin_rep') {
    msgEl.textContent = '📋 Esta empresa no tiene representante legal registrado en SUNAT.';
    hint.textContent  = 'Completa los datos manualmente.';
    btn.style.display = 'none';
  } else if (tipo === 'error') {
    msgEl.textContent = '⚠ ' + (msg || 'No se pudo obtener el representante legal.');
    hint.textContent  = 'El servicio de SUNAT puede estar temporalmente no disponible. Puedes reintentar o completar los datos manualmente.';
    btn.style.display = 'inline-flex';
  } else if (tipo === 'warn') {
    msgEl.textContent = msg || 'El representante legal no se pudo cargar automáticamente.';
    hint.textContent  = 'Puedes reintentar o completar los datos manualmente.';
    btn.style.display = 'inline-flex';
  }
}

/** Llena los campos de representante legal y resalta brevemente */
function llenarRepresentante(nombre, apellido, cargo) {
  const setF = (id, val) => { const el = document.getElementById(id); if (el) el.value = val ?? ''; };
  setF('contacto_nombre',   nombre   || '');
  setF('contacto_apellido', apellido || '');
  setF('contacto_cargo',    cargo    || '');
  if (nombre || apellido) {
    ['contacto_nombre','contacto_apellido','contacto_cargo'].forEach(id => {
      const el = document.getElementById(id);
      if (el && el.value) {
        el.style.borderColor = '#10B981';
        el.style.background  = 'var(--s-ok-bg)';
        setTimeout(() => { el.style.borderColor = ''; el.style.background = ''; }, 1800);
      }
    });
  }
}

/** Reintenta solo la consulta del representante legal para el RUC actual */
async function reintentarRepresentante() {
  if (!_rucActual) return;
  const btn = document.getElementById('btn-rep-retry');
  if (btn) { btn.disabled = true; btn.textContent = 'Consultando…'; }

  mostrarBannerRepresentante('cargando');

  try {
    const url = `<?= APP_URL ?>/modules/clientes/ajax_ruc.php?ruc=${encodeURIComponent(_rucActual)}&solo_representante=1`;
    const r = await fetch(url, { credentials: 'same-origin' });
    if (!r.ok) throw new Error('HTTP ' + r.status);
    const d = await r.json();

    if (d.ok && d.representante_ok) {
      llenarRepresentante(d.nombre, d.apellido, d.cargo);
      mostrarBannerRepresentante('ok');
      if (typeof showToast === 'function') showToast('Representante legal cargado correctamente.', 'ok');
    } else if (d.sin_representante) {
      mostrarBannerRepresentante('sin_rep');
    } else {
      mostrarBannerRepresentante('error', d.mensaje || 'No se pudo obtener el representante.');
    }
  } catch(e) {
    mostrarBannerRepresentante('error', 'Error de conexión. Intenta nuevamente.');
  } finally {
    if (btn) { btn.disabled = false; btn.innerHTML = '<svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" style="display:inline;vertical-align:-1px;margin-right:4px;"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>Reintentar'; }
  }
}

// ── Verificar datos del cliente existente contra SUNAT ──────────────────────
var _sunatDiffData = null; // datos SUNAT pendientes de aplicar

async function verificarConSUNAT() {
  if (!_clienteActual || !_clienteActual.ruc) return;
  const vBtn = document.getElementById('btn-sunat-verificar');
  const dp   = document.getElementById('sunat-diff-panel');
  if (!dp) return;

  if (vBtn) { vBtn.disabled = true; vBtn.textContent = 'Consultando…'; }
  dp.style.display = 'none';

  try {
    const r = await fetch(`<?= APP_URL ?>/modules/clientes/ajax_ruc.php?ruc=${encodeURIComponent(_clienteActual.ruc)}`, { credentials: 'same-origin' });
    if (!r.ok) throw new Error('HTTP ' + r.status);
    const d = await r.json();

    if (!d.ok || !d.data) {
      dp.innerHTML = '<span style="color:var(--s-warn-tx);">⚠ No se pudo obtener datos de SUNAT.</span>';
      dp.style.display = 'block';
      return;
    }

    const s = d.data; // datos SUNAT
    const c = _clienteActual; // datos actuales en BD

    // Campos a comparar: [clave_bd, label, valor_bd, valor_sunat, campos_extra_a_actualizar]
    const campos = [
      { key: 'razon_social',   label: 'Razón social',      bd: (c.razon_social||'').trim(),   sunat: (s.razon_social||'').trim() },
      { key: 'direccion',      label: 'Dirección fiscal',   bd: (c.direccion||'').trim(),       sunat: (s.direccion||'').trim(),
        extra: { departamento: s.departamento||'', provincia: s.provincia||'', distrito: s.distrito||'', ubigeo: s.ubigeo||'' } },
      { key: 'num_trabajadores', label: 'N° trabajadores',
        bd:    c.num_trabajadores != null ? String(c.num_trabajadores) : '',
        sunat: s.num_trabajadores != null ? String(s.num_trabajadores) : '' },
    ];

    const diffs = campos.filter(f => f.sunat && f.bd.toLowerCase() !== f.sunat.toLowerCase());

    if (!diffs.length) {
      dp.innerHTML = '<span style="color:var(--s-ok-tx);font-weight:600;">✓ Los datos del cliente están actualizados.</span>';
      dp.style.display = 'block';
      if (vBtn) { vBtn.disabled = false; vBtn.textContent = '↻ Verificar SUNAT'; }
      return;
    }

    _sunatDiffData = s;

    let html = '<div style="font-weight:600;color:var(--s-warn-tx);margin-bottom:10px;">↻ '
             + diffs.length + ' campo' + (diffs.length > 1 ? 's' : '') + ' diferente' + (diffs.length > 1 ? 's' : '') + ' en SUNAT</div>';

    diffs.forEach(f => {
      html += '<label style="display:block;margin-bottom:8px;cursor:pointer;user-select:none;">'
            + '<input type="checkbox" checked data-sunat-campo="' + f.key + '" style="margin-right:6px;vertical-align:middle;">'
            + '<strong>' + f.label + '</strong><br>'
            + '<span style="color:#6b7280;margin-left:18px;">Actual:&nbsp;</span><span style="color:#6b7280;">' + _esc(f.bd || '(vacío)') + '</span><br>'
            + '<span style="color:#059669;margin-left:18px;">SUNAT:&nbsp;</span><span style="color:#059669;font-weight:500;">' + _esc(f.sunat) + '</span>'
            + '</label>';
    });

    html += '<div style="display:flex;gap:8px;margin-top:10px;">'
          + '<button type="button" onclick="aplicarDatosSUNAT()" '
          + 'style="font-size:11px;padding:4px 12px;background:#059669;color:#fff;border:none;border-radius:5px;cursor:pointer;font-weight:600;">'
          + 'Aplicar seleccionados</button>'
          + '<button type="button" onclick="cerrarDiffSUNAT()" '
          + 'style="font-size:11px;padding:4px 10px;background:var(--bg-surface2);color:#374151;border:1px solid #d1d5db;border-radius:5px;cursor:pointer;">'
          + 'Ignorar</button>'
          + '</div>';

    dp.innerHTML = html;
    dp.style.display = 'block';
  } catch(e) {
    dp.innerHTML = '<span style="color:var(--s-warn-tx);">⚠ Error al conectar con SUNAT.</span>';
    dp.style.display = 'block';
  } finally {
    if (vBtn) { vBtn.disabled = false; vBtn.textContent = '↻ Verificar SUNAT'; }
  }
}

function cerrarDiffSUNAT() {
  const dp = document.getElementById('sunat-diff-panel');
  if (dp) dp.style.display = 'none';
  _sunatDiffData = null;
}

async function aplicarDatosSUNAT() {
  if (!_clienteActual || !_sunatDiffData) return;
  const dp   = document.getElementById('sunat-diff-panel');
  const chks = dp ? dp.querySelectorAll('[data-sunat-campo]:checked') : [];
  if (!chks.length) return;

  const s = _sunatDiffData;
  const campos = [
    { key: 'razon_social',    extra: null },
    { key: 'direccion',       extra: { departamento: s.departamento||'', provincia: s.provincia||'', distrito: s.distrito||'', ubigeo: s.ubigeo||'' } },
    { key: 'num_trabajadores', extra: null },
  ];
  const campoMap = Object.fromEntries(campos.map(c => [c.key, c]));

  const body = new URLSearchParams({
    cliente_id: _clienteActual.id,
    _csrf: window.CSRF_TOKEN || document.querySelector('meta[name="csrf-token"]')?.content || ''
  });
  const applied = [];
  chks.forEach(chk => {
    const key = chk.dataset.sunatCampo;
    const val = s[key] != null ? String(s[key]) : '';
    if (val) {
      body.append(key, val);
      applied.push(key);
      const info = campoMap[key];
      if (info && info.extra) {
        Object.entries(info.extra).forEach(([k, v]) => { if (v) body.append(k, v); });
      }
    }
  });

  if (!applied.length) return;

  try {
    const r = await fetch(`<?= APP_URL ?>/modules/clientes/ajax_actualizar_sunat.php`, {
      method: 'POST', credentials: 'same-origin', body
    });
    const result = await r.json();
    if (result.ok) {
      // Actualizar _clienteActual con los nuevos valores
      applied.forEach(key => { _clienteActual[key] = s[key]; });
      if (applied.includes('direccion')) {
        ['departamento','provincia','distrito','ubigeo'].forEach(k => { _clienteActual[k] = s[k] || ''; });
      }
      dp.innerHTML = '<span style="color:var(--s-ok-tx);font-weight:600;">✓ Datos actualizados correctamente.</span>';
      _sunatDiffData = null;
      if (typeof showToast === 'function') showToast('Datos del cliente actualizados desde SUNAT.', 'ok');
    } else {
      dp.innerHTML += '<br><span style="color:#c53030;">Error: ' + _esc(result.msg || 'No se pudo actualizar.') + '</span>';
    }
  } catch(e) {
    dp.innerHTML += '<br><span style="color:#c53030;">Error de conexión al guardar.</span>';
  }
}

function _esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

// onResult(item|null): item = {id:null, ruc, name, source:'sunat', _data, label}
async function consultarSUNAT(ruc, onResult) {
  if (_sunatLoading) return;
  _sunatLoading = true;
  try {
    const cached = _sunatCache[ruc];
    const d = cached ?? await fetch(`<?= APP_URL ?>/modules/clientes/ajax_ruc.php?ruc=${encodeURIComponent(ruc)}`, { credentials: 'same-origin' })
                                    .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); });
    // Solo guardar en cache local si el representante fue exitoso (igual que backend)
    if (!cached && d.ok && d.representante_ok) _sunatCache[ruc] = d;

    const info = d.data || d;
    if (d.ok && info.razon_social) {
      const item = { id: null, ruc: ruc, name: info.razon_social, source: 'sunat',
                     label: ruc + ' — ' + info.razon_social,
                     _data: info,
                     representante_ok: d.representante_ok ?? true,
                     sin_representante: d.sin_representante ?? false };
      if (onResult) { onResult(item); }
      else { seleccionarEmpresaSUNAT(item); }
    } else {
      if (onResult) { onResult(null); }
      else {
        setEmpresaStatus('err', '✗ RUC no encontrado en SUNAT. Completa los datos manualmente.');
        setEmpresaState('nueva');
        const r = document.getElementById('empresa_ruc'); if (r) r.value = ruc;
      }
    }
  } catch(e) {
    if (onResult) { onResult(null); }
    else { setEmpresaStatus('warn', '⚠ Error al consultar SUNAT. Verifica el RUC e intenta de nuevo.'); }
  } finally {
    _sunatLoading = false;
  }
}

function seleccionarEmpresaSUNAT(item) {
  const info = item._data || {};
  const setF = (id, val) => { const el = document.getElementById(id); if (el) el.value = val ?? ''; };
  _rucActual = item.ruc;  // guardar para posible reintento
  setF('empresa_ruc',       item.ruc);
  setF('empresa_nombre',    item.name);
  setF('empresa_direccion', info.direccion    || '');
  setF('empresa_dep',       info.departamento || '');
  setF('empresa_prov',      info.provincia    || '');
  setF('empresa_dist',      info.distrito     || '');
  setF('empresa_ubigeo',    info.ubigeo       || '');
  setF('num_trabajadores',  info.num_trabajadores != null ? info.num_trabajadores : '');

  // Llenar representante legal solo si se obtuvo correctamente
  if (item.representante_ok !== false && (info.contacto_nombre || info.contacto_apellido)) {
    llenarRepresentante(info.contacto_nombre, info.contacto_apellido, info.contacto_cargo);
    mostrarBannerRepresentante('ok');
  } else if (item.sin_representante) {
    // La empresa existe pero no tiene representante en SUNAT
    mostrarBannerRepresentante('sin_rep');
  } else {
    // La API del representante falló — mostrar advertencia y botón de reintento
    mostrarBannerRepresentante('error', 'No se pudo obtener el representante legal desde SUNAT.');
  }

  setEmpresaState('nueva');
  setEmpresaStatus('ok', '✓ ' + item.name + (info.estado_sunat ? ' — ' + info.estado_sunat : ''));
}


</script>
<script>
// validacion.js se incluye más abajo (vía layout_end), por lo que GGIForm aún no
// existe en este punto del parseo. Diferir la inicialización hasta DOMContentLoaded
// garantiza que la validación de cliente sí se adjunte (sin esto, lanzaba
// ReferenceError y todo envío caía al servidor mostrando banner + toast).
(function initCotValidacion(){
  if (typeof GGIForm === 'undefined') {
    document.addEventListener('DOMContentLoaded', initCotValidacion, { once: true });
    return;
  }

  new GGIForm('form-cot-crear')
    .required('contacto_nombre',   'El nombre del contacto es requerido.')
    .required('contacto_telefono', 'El celular del contacto es requerido.')
    .custom('contacto_telefono', function(v){
        var d = (v || '').replace(/\D/g, '');
        if (d.length === 11 && d.indexOf('51') === 0) d = d.slice(2);
        return d === '' || /^9\d{8}$/.test(d); // vacío lo maneja required
    }, 'Ingresa un celular válido (9 dígitos, empieza en 9).')
    .email('contacto_email',       'Ingresa un email de contacto válido.')
    .dateRequired('fecha_emision', 'La fecha de emisión es requerida.')
    .init();

  // Normaliza el celular en vivo: deja solo dígitos y quita el prefijo 51 si lo pegan
  var tel = document.querySelector('#form-cot-crear [name="contacto_telefono"]');
  if (tel) {
    tel.addEventListener('input', function(){
      var d = this.value.replace(/\D/g, '');
      if (d.length === 11 && d.indexOf('51') === 0) d = d.slice(2);
      this.value = d.slice(0, 9);
    });
  }
})();
</script>
<script>
// Datos del cliente pre-seleccionado (solo cuando hay prefill; sin cargar todos los clientes)
var _PREFILL_CL = <?= json_encode($prefill_cl_data) ?>;
var _contactoPendiente = null;

function autoEjecutivo(clienteId, ejId) {
  // ejId puede venir del item del dropdown o del prefill server-side
  if (!ejId) return;
  const sel = document.getElementById('ejecutivo_id');
  if (sel) sel.value = ejId;
}

function checkContacto(clienteId, contacto) {
  // contacto puede venir del item del dropdown o del prefill server-side
  const banner = document.getElementById('contacto-banner');
  if (!contacto || (!contacto.nombre && !contacto.apellido && !contacto.telefono && !contacto.email)) {
    if (banner) banner.style.display = 'none';
    _contactoPendiente = null;
    return;
  }
  _contactoPendiente = contacto;
  // Auto-fill contact fields immediately
  document.querySelector('[name="contacto_nombre"]').value   = contacto.nombre   || '';
  document.querySelector('[name="contacto_apellido"]').value = contacto.apellido || '';
  document.querySelector('[name="contacto_cargo"]').value    = contacto.cargo    || '';
  document.querySelector('[name="contacto_telefono"]').value = contacto.telefono || '';
  document.querySelector('[name="contacto_email"]').value    = contacto.email    || '';
  // Highlight filled fields briefly
  ['contacto_nombre','contacto_apellido','contacto_cargo','contacto_telefono','contacto_email'].forEach(name => {
    const el = document.querySelector('[name="' + name + '"]');
    if (el && el.value) {
      el.style.borderColor = '#10B981';
      el.style.background  = 'var(--s-ok-bg)';
      setTimeout(() => { el.style.borderColor = ''; el.style.background = ''; }, 1800);
    }
  });
  if (banner) banner.style.display = 'none';
}

function usarContactoEmpresa() {
  if (!_contactoPendiente) return;
  const ct = _contactoPendiente;
  document.querySelector('[name="contacto_nombre"]').value   = ct.nombre   || '';
  document.querySelector('[name="contacto_apellido"]').value = ct.apellido || '';
  document.querySelector('[name="contacto_cargo"]').value    = ct.cargo    || '';
  document.querySelector('[name="contacto_telefono"]').value = ct.telefono || '';
  document.querySelector('[name="contacto_email"]').value    = ct.email    || '';
  document.getElementById('contacto-banner').style.display  = 'none';
  // Highlight filled fields briefly
  ['contacto_nombre','contacto_apellido','contacto_cargo','contacto_telefono','contacto_email'].forEach(name => {
    const el = document.querySelector('[name="' + name + '"]');
    if (el && el.value) {
      el.style.borderColor = '#10B981';
      el.style.background  = 'var(--s-ok-bg)';
      setTimeout(() => { el.style.borderColor = ''; el.style.background = ''; }, 1800);
    }
  });
  _contactoPendiente = null;
}

function ignorarContactoEmpresa() {
  document.getElementById('contacto-banner').style.display = 'none';
  // Clear contact fields so user starts fresh
  document.querySelector('[name="contacto_nombre"]').value   = '';
  document.querySelector('[name="contacto_apellido"]').value = '';
  document.querySelector('[name="contacto_cargo"]').value    = '';
  document.querySelector('[name="contacto_telefono"]').value = '';
  document.querySelector('[name="contacto_email"]').value    = '';
  document.querySelector('[name="contacto_nombre"]').focus();
  _contactoPendiente = null;
}
// Normas ya certificadas para el cliente seleccionado
async function actualizarNormasExistentes(clienteId) {
  // Restaurar todas las normas
  document.querySelectorAll('input[name="normas[]"]').forEach(cb => {
    cb.disabled = false;
    const item = cb.closest('.norma-item');
    if (item) { item.style.opacity = '1'; item.style.cursor = 'pointer'; }
    const badge = item ? item.querySelector('.norma-existente-badge') : null;
    if (badge) badge.remove();
  });
  if (!clienteId) return;
  try {
    const r = await fetch(window.APP_URL + '/modules/cotizaciones/ajax_normas_cliente.php?cliente_id=' + clienteId);
    const d = await r.json();
    if (d.ok && d.normas.length > 0) {
      d.normas.forEach(function(normaId) {
        const cb = document.getElementById('norma_' + normaId);
        if (!cb) return;
        cb.disabled = true;
        if (cb.checked) { cb.checked = false; togglePrecio(normaId, false); }
        const item = cb.closest('.norma-item');
        if (item) {
          item.style.opacity = '0.55';
          item.style.cursor = 'default';
          const badge = document.createElement('span');
          badge.className = 'norma-existente-badge';
          badge.style.cssText = 'font-size:10px;background:var(--s-err-bg);color:#c53030;border:1px solid var(--s-err-brd);border-radius:100px;padding:2px 8px;white-space:nowrap;flex-shrink:0;';
          badge.textContent = 'Ya certificada';
          const nameSpan = item.querySelector('span[style*="flex:1"]') || item.querySelector('span');
          if (nameSpan) nameSpan.after(badge);
        }
      });
    }
  } catch(e) {}
}

// Aplicar al cargar si ya hay cliente seleccionado (p.ej. desde ficha empresa)
ggiReady(function() {
  const hidCliente = document.getElementById('cliente_id_hidden');
  if (hidCliente && hidCliente.value) {
    // _PREFILL_CL ya tiene ejec_id y contacto del cliente pre-seleccionado (PHP)
    autoEjecutivo(hidCliente.value, _PREFILL_CL ? _PREFILL_CL.ejec_id : null);
    checkContacto(hidCliente.value, _PREFILL_CL ? _PREFILL_CL.contacto : null);
    actualizarNormasExistentes(hidCliente.value);
  }
});

// ── Búsqueda unificada de empresa (AJAX) ────────────────────
(function() {
  var inp  = document.getElementById('empresa-search-input');
  var drop = document.getElementById('empresa-search-dropdown');
  if (!inp || !drop) return;

  var sunatTimer  = null;
  var ajaxTimer   = null;
  var activeIdx   = -1;
  var dropItems   = [];
  var _lastQ      = '';

  function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
  function hl(text, q) {
    if (!q || !text) return esc(text);
    var idx = text.toLowerCase().indexOf(q.toLowerCase());
    if (idx < 0) return esc(text);
    return esc(text.slice(0,idx))+'<mark class="ss-mark">'+esc(text.slice(idx,idx+q.length))+'</mark>'+esc(text.slice(idx+q.length));
  }

  function badgeHtml(estado) {
    var eb = _ESTADO_BADGE[estado] || { bg:'#f1f5f9', fg:'#475569', label: estado || '' };
    return '<span style="font-size:10px;font-weight:600;padding:1px 7px;border-radius:100px;background:'+eb.bg+';color:'+eb.fg+';">'+eb.label+'</span>';
  }

  function _selectItem(c) {
    if (c.source === 'sunat') {
      seleccionarEmpresaSUNAT(c);
    } else {
      seleccionarEmpresaExistente(c.id, c.ruc, c.name, c.estado, c.ejec_id, c.contacto, {
        direccion:        c.direccion        || '',
        departamento:     c.departamento     || '',
        provincia:        c.provincia        || '',
        distrito:         c.distrito         || '',
        ubigeo:           c.ubigeo           || '',
        num_trabajadores: c.num_trabajadores != null ? c.num_trabajadores : null,
      });
    }
    inp.value = '';
    drop.style.display = 'none';
  }

  function renderDrop(list, q) {
    activeIdx = -1;
    dropItems  = list.slice(0, 30);
    drop.innerHTML = '';
    if (!dropItems.length) {
      drop.innerHTML = '<div class="ss-empty">Sin resultados' + (q && q.length >= 2 ? ' para &ldquo;<b>'+esc(q)+'</b>&rdquo;' : '') + '</div>';
      drop.style.display = 'block';
      return;
    }
    dropItems.forEach(function(c, i) {
      var d = document.createElement('div');
      d.className = 'ss-item';
      var nameHtml, rightHtml;
      if (c.source === 'sunat') {
        nameHtml  = '<span class="ss-name" style="color:#1d4ed8;">'+hl(c.name, q)+'</span>';
        rightHtml = '<span style="display:flex;align-items:center;gap:5px;flex-shrink:0;">'
          + (c.ruc ? '<span class="ss-ruc">'+hl(c.ruc, q)+'</span>' : '')
          + '<span style="font-size:10px;font-weight:700;padding:1px 7px;border-radius:100px;background:var(--s-info-bg);color:#1d4ed8;">SUNAT</span>'
          + '</span>';
      } else {
        nameHtml  = '<span class="ss-name">'+hl(c.name, q)+'</span>';
        rightHtml = '<span style="display:flex;align-items:center;gap:5px;flex-shrink:0;">'
          + (c.ruc ? '<span class="ss-ruc">'+hl(c.ruc, q)+'</span>' : '')
          + badgeHtml(c.estado)
          + '</span>';
      }
      d.innerHTML = nameHtml + rightHtml;
      d.addEventListener('mouseenter', function() { clearActive(); d.classList.add('active'); activeIdx = i; });
      d.addEventListener('mouseleave', function() { d.classList.remove('active'); });
      d.addEventListener('mousedown',  function(e) { e.preventDefault(); _selectItem(dropItems[i]); });
      drop.appendChild(d);
    });
    drop.style.display = 'block';
  }

  function _sunatEnDrop(q) {
    drop.innerHTML = '<div class="ss-empty">⏳ Consultando SUNAT para RUC <b>'+esc(q)+'</b>...</div>';
    drop.style.display = 'block';
    setEmpresaStatus('info', '⏳ Consultando SUNAT...');
    consultarSUNAT(q, function(item) {
      if (item) {
        renderDrop([item], q);
        setEmpresaStatus('info', 'Empresa encontrada en SUNAT — haz clic para seleccionar ↑');
      } else {
        drop.innerHTML = '<div class="ss-empty">✗ RUC <b>'+esc(q)+'</b> no encontrado en SUNAT.</div>';
        drop.style.display = 'block';
        setEmpresaStatus('err', '✗ RUC no encontrado. Completa los datos manualmente.');
      }
    });
  }

  function _ajaxSearch(q) {
    _lastQ = q;
    drop.innerHTML = '<div class="ss-empty" style="color:var(--gris-texto);">Buscando...</div>';
    drop.style.display = 'block';
    fetch(window.APP_URL + '/modules/cotizaciones/ajax_clientes_buscar.php?q=' + encodeURIComponent(q))
      .then(function(r){ return r.json(); })
      .then(function(d) {
        if (_lastQ !== q) return; // resultado obsoleto, se descarta
        if (d.ok) {
          renderDrop(d.data, q);
          if (!d.data.length && /^\d{11}$/.test(q)) {
            sunatTimer = setTimeout(function() { _sunatEnDrop(q); }, 200);
          }
        }
      })
      .catch(function() { if (_lastQ === q) { drop.innerHTML = '<div class="ss-empty">Error al buscar. Inténtalo de nuevo.</div>'; } });
  }

  function clearActive() { drop.querySelectorAll('.ss-item.active').forEach(function(el){ el.classList.remove('active'); }); }

  inp.addEventListener('input', function() {
    clearTimeout(sunatTimer);
    clearTimeout(ajaxTimer);
    var q = inp.value.trim();
    if (!q) { drop.style.display = 'none'; setEmpresaStatus('',''); return; }
    if (q.length < 2) { drop.style.display = 'none'; return; }

    if (/^\d{11}$/.test(q)) {
      // RUC completo: buscar en BD primero, luego SUNAT si no hay resultados
      _ajaxSearch(q);
    } else {
      // Texto: debounce 300 ms
      ajaxTimer = setTimeout(function() { _ajaxSearch(q); }, 300);
    }
    setEmpresaStatus('','');
  });

  inp.addEventListener('focus', function() {
    var q = inp.value.trim();
    if (q && q.length >= 2 && drop.innerHTML && drop.style.display !== 'none') return; // ya hay resultados
    if (q && q.length >= 2) _ajaxSearch(q);
  });

  inp.addEventListener('keydown', function(e) {
    var items = drop.querySelectorAll('.ss-item');
    if (e.key === 'ArrowDown') {
      e.preventDefault(); clearActive();
      activeIdx = Math.min(activeIdx+1, items.length-1);
      if (items[activeIdx]) { items[activeIdx].classList.add('active'); items[activeIdx].scrollIntoView({block:'nearest'}); }
    } else if (e.key === 'ArrowUp') {
      e.preventDefault(); clearActive();
      activeIdx = Math.max(activeIdx-1, 0);
      if (items[activeIdx]) { items[activeIdx].classList.add('active'); items[activeIdx].scrollIntoView({block:'nearest'}); }
    } else if (e.key === 'Enter') {
      if (activeIdx >= 0 && drop.style.display !== 'none' && dropItems[activeIdx]) {
        e.preventDefault();
        _selectItem(dropItems[activeIdx]);
      } else if (/^\d{11}$/.test(inp.value.trim())) {
        e.preventDefault();
        clearTimeout(sunatTimer);
        _sunatEnDrop(inp.value.trim());
      }
    } else if (e.key === 'Escape') {
      drop.style.display = 'none';
    }
  });

  document.addEventListener('click', function(e) {
    var wrap = inp.closest('.ss-wrap');
    if (!wrap || !wrap.contains(e.target)) drop.style.display = 'none';
  });

  // Pre-fill si viene desde ?cliente_id o duplicar con existente
  <?php if ($prefill_empresa): ?>
  seleccionarEmpresaExistente(
    <?= (int)$prefill_empresa['id'] ?>,
    <?= json_encode($prefill_empresa['ruc'] ?? '') ?>,
    <?= json_encode($prefill_empresa['razon_social']) ?>,
    <?= json_encode($prefill_empresa['estado'] ?? 'activo') ?>,
    <?= json_encode($prefill_cl_data ? $prefill_cl_data['ejec_id'] : null) ?>,
    <?= json_encode($prefill_cl_data ? $prefill_cl_data['contacto'] : null) ?>,
    <?= json_encode([
      'direccion'        => $prefill_empresa['direccion']        ?? '',
      'departamento'     => $prefill_empresa['departamento']     ?? '',
      'provincia'        => $prefill_empresa['provincia']        ?? '',
      'distrito'         => $prefill_empresa['distrito']         ?? '',
      'ubigeo'           => $prefill_empresa['ubigeo']           ?? '',
      'num_trabajadores' => isset($prefill_empresa['num_trabajadores']) && $prefill_empresa['num_trabajadores'] !== null
                              ? (int)$prefill_empresa['num_trabajadores'] : null,
    ]) ?>
  );
  <?php elseif ($data['tipo_empresa']==='nueva' && !empty($data['empresa_nombre'])): ?>
  setEmpresaState('nueva');
  <?php endif; ?>
})();
</script>
<?php if ($_cert_renovar): ?>
<script>
// Pre-marcar la norma del certificado a renovar
(function() {
  var normaId = <?= (int)$_cert_renovar['norma_iso_id'] ?>;
  var cb = document.getElementById('norma_' + normaId);
  if (cb && !cb.checked) {
    cb.checked = true;
    cb.dispatchEvent(new Event('change', { bubbles: true }));
  }
  // Focalizar en el campo de precio de esa norma
  var precio = document.getElementById('precio_' + normaId);
  if (precio) setTimeout(function(){ precio.focus(); precio.select(); }, 300);
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
