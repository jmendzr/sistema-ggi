<?php
/**
 * modules/mantenimiento/wa_bot.php — Constructor de flujos del Bot de WhatsApp.
 *
 * Editor SIN código del flujo del bot: crear/editar/enlazar NODOS (menú, mensaje,
 * pedir RUC, pedir normas, derivar, fin), botones que enlazan a otros nodos y
 * disparadores por palabra clave. Guarda en wa_bot_nodos / wa_bot_botones /
 * wa_bot_triggers. El motor que recorre todo esto es core/WaBot.php.
 */
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();
require_role('gerente_general', 'gerente_operaciones','gerente_comercial');

$pdo = Database::get();

function cfg_get(PDO $pdo, string $k, string $def = ''): string {
    $r = $pdo->prepare("SELECT valor FROM configuracion WHERE clave=? LIMIT 1");
    $r->execute([$k]); $v = $r->fetchColumn();
    return $v !== false ? (string)$v : $def;
}
function cfg_set(PDO $pdo, string $k, string $v): void {
    $pdo->prepare("INSERT INTO configuracion (clave, valor) VALUES (?,?) ON DUPLICATE KEY UPDATE valor=?, updated_at=NOW()")->execute([$k, $v, $v]);
}
function slugify(string $s): string {
    $s = iconv('UTF-8', 'ASCII//TRANSLIT', $s) ?: $s;
    $s = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $s));
    return trim($s, '_') ?: ('nodo_' . substr(md5(uniqid('', true)), 0, 6));
}

// Orden y etiquetas al estilo Kapso.
$TIPOS = [
    'mensaje'      => 'Enviar texto',
    'send_template'=> 'Enviar plantilla',
    'menu'         => 'Enviar interactivo (botones/lista)',
    'esperar'      => 'Esperar respuesta',
    'pedir_ruc'    => 'Pedir RUC',
    'pedir_normas' => 'Pedir norma(s)',
    'condicion'    => 'Decisión (ramificar)',
    'webhook'      => 'Webhook (API externa)',
    'ia'           => 'Agente IA',
    'emit'         => 'Registrar evento',
    'derivar'      => 'Handoff (derivar a humano)',
    'fin'          => 'Fin (sin aviso)',
];
// Ícono + color por tipo (para las tarjetas del lienzo).
$TIPO_META = [
    'mensaje'      => ['💬', '#0f766e'],
    'send_template'=> ['📤', '#0369a1'],
    'menu'         => ['🔘', '#1461b8'],
    'esperar'      => ['⏳', '#0891b2'],
    'pedir_ruc'    => ['🔢', '#7c3aed'],
    'pedir_normas' => ['📋', '#7c3aed'],
    'condicion'    => ['🔀', '#b45309'],
    'webhook'      => ['🌐', '#4338ca'],
    'ia'           => ['🤖', '#be185d'],
    'emit'         => ['📈', '#9333ea'],
    'derivar'      => ['🙋', '#15803d'],
    'fin'          => ['⏹️', '#6b7280'],
];
$CAMPOS_REGLA = ['texto' => 'El mensaje', 'ruc' => 'El RUC', 'normas' => 'Las normas', 'razon' => 'La razón social'];
$OPERS_REGLA  = ['contiene' => 'contiene', 'no_contiene' => 'no contiene', 'tiene' => 'ya se capturó', 'no_tiene' => 'aún no se captura'];

// ── POST ──────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $accion = $_POST['accion'] ?? '';

    // Guardado de posición al arrastrar en el lienzo → JSON, sin recargar.
    if ($accion === 'pos') {
        header('Content-Type: application/json');
        $pdo->prepare("UPDATE wa_bot_nodos SET pos_x=?, pos_y=? WHERE id=?")
            ->execute([(int)round((float)($_POST['x'] ?? 0)), (int)round((float)($_POST['y'] ?? 0)), (int)($_POST['id'] ?? 0)]);
        echo json_encode(['ok' => true]); exit;
    }

    // Conectar dos nodos arrastrando (crea el enlace según el tipo del origen).
    if ($accion === 'conectar') {
        header('Content-Type: application/json');
        $fromId  = (int)($_POST['from_id'] ?? 0);
        $toClave = trim($_POST['to_clave'] ?? '');
        $fn = $pdo->prepare("SELECT id, tipo, clave, siguiente_clave FROM wa_bot_nodos WHERE id=?"); $fn->execute([$fromId]);
        $from = $fn->fetch(PDO::FETCH_ASSOC);
        $tn = $pdo->prepare("SELECT nombre FROM wa_bot_nodos WHERE clave=?"); $tn->execute([$toClave]);
        $toNombre = $tn->fetchColumn();
        if (!$from || $toNombre === false || $from['clave'] === $toClave) { echo json_encode(['ok' => false, 'msg' => 'Conexión inválida']); exit; }
        $t = $from['tipo'];
        if (in_array($t, ['mensaje', 'pedir_ruc', 'pedir_normas', 'ia', 'send_template'], true)) {
            $pdo->prepare("UPDATE wa_bot_nodos SET siguiente_clave=? WHERE id=?")->execute([$toClave, $fromId]);
        } elseif ($t === 'condicion') {
            if (empty($from['siguiente_clave'])) $pdo->prepare("UPDATE wa_bot_nodos SET siguiente_clave=? WHERE id=?")->execute([$toClave, $fromId]);
            else $pdo->prepare("INSERT INTO wa_bot_reglas (nodo_id, orden, campo, operador, valor, destino_clave) VALUES (?, (SELECT COALESCE(MAX(orden),0)+1 FROM (SELECT * FROM wa_bot_reglas WHERE nodo_id=?) x), 'texto','contiene','', ?)")->execute([$fromId, $fromId, $toClave]);
        } elseif ($t === 'menu') {
            $pdo->prepare("INSERT INTO wa_bot_botones (nodo_id, orden, label, destino_clave) VALUES (?, (SELECT COALESCE(MAX(orden),0)+1 FROM (SELECT * FROM wa_bot_botones WHERE nodo_id=?) x), ?, ?)")->execute([$fromId, $fromId, mb_substr($toNombre ?: 'Opción', 0, 20), $toClave]);
        } else { echo json_encode(['ok' => false, 'msg' => 'Este paso no tiene salida']); exit; }
        echo json_encode(['ok' => true]); exit;
    }

    // Quitar una conexión (clic en la flecha).
    if ($accion === 'desconectar') {
        header('Content-Type: application/json');
        $kind = $_POST['kind'] ?? ''; $lid = (int)($_POST['lid'] ?? 0);
        if ($kind === 'sig')        $pdo->prepare("UPDATE wa_bot_nodos SET siguiente_clave=NULL WHERE id=?")->execute([$lid]);
        elseif ($kind === 'btn')    $pdo->prepare("DELETE FROM wa_bot_botones WHERE id=?")->execute([$lid]);
        elseif ($kind === 'regla')  $pdo->prepare("DELETE FROM wa_bot_reglas WHERE id=?")->execute([$lid]);
        echo json_encode(['ok' => true]); exit;
    }

    if ($accion === 'general') {
        cfg_set($pdo, 'wa_bot_activo', isset($_POST['wa_bot_activo']) ? '1' : '0');
        cfg_set($pdo, 'wa_bot_test_numero', preg_replace('/\D/', '', $_POST['wa_bot_test_numero'] ?? ''));
        log_actividad('mantenimiento', 'wa_bot_config', 'Ajustes generales del bot');
        header('Location: ?ok=general'); exit;
    }

    if ($accion === 'guardar_nodo') {
        $id     = (int)($_POST['id'] ?? 0);
        $wasNew = $id < 1;
        $nombre = trim($_POST['nombre'] ?? '');
        $tipo   = in_array($_POST['tipo'] ?? '', array_keys($TIPOS), true) ? $_POST['tipo'] : 'mensaje';
        $texto  = trim($_POST['texto'] ?? '');
        $clave  = trim($_POST['clave'] ?? '');
        $sig    = trim($_POST['siguiente_clave'] ?? '');
        $fijar  = trim($_POST['fijar_normas'] ?? '');
        $etq    = trim($_POST['etiqueta_nombre'] ?? '');
        $inicio = isset($_POST['es_inicio']) ? 1 : 0;
        $activo = isset($_POST['activo']) ? 1 : 0;
        if ($clave === '') $clave = slugify($nombre !== '' ? $nombre : 'nodo');

        // Campos que no aplican al tipo → se limpian. Estos usan "siguiente" como continuación/else/fallback.
        if (!in_array($tipo, ['mensaje', 'pedir_ruc', 'pedir_normas', 'condicion', 'ia', 'send_template', 'esperar', 'webhook', 'emit'], true)) $sig = '';
        if ($tipo !== 'derivar') $etq = '';
        if ($tipo !== 'mensaje') $fijar = '';

        // Config específica del paso.
        $config = null;
        if ($tipo === 'send_template') {
            $params = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $_POST['tpl_params'] ?? '')), fn($v) => $v !== ''));
            $config = json_encode(['template' => trim($_POST['tpl_name'] ?? ''), 'lang' => trim($_POST['tpl_lang'] ?? 'es') ?: 'es', 'params' => $params], JSON_UNESCAPED_UNICODE);
        } elseif ($tipo === 'webhook') {
            $mth = strtoupper(trim($_POST['wh_method'] ?? 'POST'));
            $config = json_encode(['url' => trim($_POST['wh_url'] ?? ''), 'method' => in_array($mth, ['GET', 'POST', 'PUT'], true) ? $mth : 'POST', 'body' => trim($_POST['wh_body'] ?? '')], JSON_UNESCAPED_UNICODE);
        } elseif ($tipo === 'emit') {
            $config = json_encode(['evento' => trim($_POST['ev_nombre'] ?? '')], JSON_UNESCAPED_UNICODE);
        }

        if ($id > 0) {
            $pdo->prepare("UPDATE wa_bot_nodos SET nombre=?, tipo=?, texto=?, siguiente_clave=?, fijar_normas=?, etiqueta_nombre=?, config_json=?, es_inicio=?, activo=? WHERE id=?")
                ->execute([$nombre, $tipo, $texto, $sig ?: null, $fijar ?: null, $etq ?: null, $config, $inicio, $activo, $id]);
        } else {
            // Evitar clave duplicada.
            $ex = $pdo->prepare("SELECT COUNT(*) FROM wa_bot_nodos WHERE clave=?"); $ex->execute([$clave]);
            if ($ex->fetchColumn() > 0) $clave .= '_' . substr(md5(uniqid('', true)), 0, 4);
            $pdo->prepare("INSERT INTO wa_bot_nodos (clave, nombre, tipo, texto, siguiente_clave, fijar_normas, etiqueta_nombre, config_json, es_inicio, activo, orden) VALUES (?,?,?,?,?,?,?,?,?,?,(SELECT COALESCE(MAX(orden),0)+1 FROM (SELECT * FROM wa_bot_nodos) t))")
                ->execute([$clave, $nombre, $tipo, $texto, $sig ?: null, $fijar ?: null, $etq ?: null, $config, $inicio, $activo]);
            $id = (int)$pdo->lastInsertId();
        }

        // Un solo nodo de inicio.
        if ($inicio) $pdo->prepare("UPDATE wa_bot_nodos SET es_inicio=0 WHERE id<>?")->execute([$id]);

        // Botones (sólo para menú).
        $pdo->prepare("DELETE FROM wa_bot_botones WHERE nodo_id=?")->execute([$id]);
        if ($tipo === 'menu') {
            $labels  = $_POST['btn_label'] ?? [];
            $destinos = $_POST['btn_destino'] ?? [];
            $ins = $pdo->prepare("INSERT INTO wa_bot_botones (nodo_id, orden, label, destino_clave) VALUES (?,?,?,?)");
            $o = 0;
            foreach ($labels as $i => $lab) {
                $lab = trim($lab); $dst = trim($destinos[$i] ?? '');
                if ($lab === '' || $dst === '') continue;
                $ins->execute([$id, $o++, mb_substr($lab, 0, 30), $dst]);
            }
        }

        // Reglas (sólo para condición).
        $pdo->prepare("DELETE FROM wa_bot_reglas WHERE nodo_id=?")->execute([$id]);
        if ($tipo === 'condicion') {
            $rc = $_POST['rg_campo'] ?? []; $ro = $_POST['rg_oper'] ?? [];
            $rv = $_POST['rg_valor'] ?? []; $rd = $_POST['rg_destino'] ?? [];
            $insR = $pdo->prepare("INSERT INTO wa_bot_reglas (nodo_id, orden, campo, operador, valor, destino_clave) VALUES (?,?,?,?,?,?)");
            $o = 0;
            foreach ($rc as $i => $campo) {
                $campo = in_array($campo, ['texto','ruc','normas','razon'], true) ? $campo : 'texto';
                $oper  = in_array($ro[$i] ?? '', ['contiene','no_contiene','tiene','no_tiene'], true) ? $ro[$i] : 'contiene';
                $val   = trim($rv[$i] ?? ''); $dst = trim($rd[$i] ?? '');
                if ($dst === '') continue;                                   // sin destino → ignora
                if ($campo === 'texto' && $val === '') continue;             // "contiene" sin valor → ignora
                $insR->execute([$id, $o++, $campo, $oper, mb_substr($val, 0, 190), $dst]);
            }
        }
        log_actividad('mantenimiento', 'wa_bot_config', "Nodo del bot guardado ($clave)");
        // Dentro del modal (embed): avisar al padre para que cierre y refresque el lienzo.
        if (!empty($_GET['embed']) || !empty($_POST['_embed'])) {
            echo '<!doctype html><meta charset="utf-8"><script>parent.postMessage({ggi:' . ($wasNew ? "'created'" : "'updated'") . ',id:' . $id . '},"*");</script>';
            exit;
        }
        header('Location: ?ok=nodo'); exit;
    }

    if ($accion === 'borrar_nodo') {
        $id = (int)($_POST['id'] ?? 0);
        $n = $pdo->prepare("SELECT es_inicio FROM wa_bot_nodos WHERE id=?"); $n->execute([$id]);
        if ($n->fetchColumn()) { header('Location: ?err=inicio'); exit; } // no borrar el inicio
        $pdo->prepare("DELETE FROM wa_bot_botones WHERE nodo_id=?")->execute([$id]);
        $pdo->prepare("DELETE FROM wa_bot_reglas WHERE nodo_id=?")->execute([$id]);
        $pdo->prepare("DELETE FROM wa_bot_nodos WHERE id=?")->execute([$id]);
        header('Location: ?ok=borrado'); exit;
    }

    if ($accion === 'guardar_trigger') {
        $id = (int)($_POST['id'] ?? 0);
        $palabras = trim($_POST['palabras'] ?? '');
        $destino  = trim($_POST['destino_clave'] ?? '');
        $activo   = isset($_POST['activo']) ? 1 : 0;
        if ($palabras !== '' && $destino !== '') {
            if ($id > 0) $pdo->prepare("UPDATE wa_bot_triggers SET palabras=?, destino_clave=?, activo=? WHERE id=?")->execute([$palabras, $destino, $activo, $id]);
            else $pdo->prepare("INSERT INTO wa_bot_triggers (palabras, destino_clave, activo) VALUES (?,?,?)")->execute([$palabras, $destino, $activo]);
        }
        header('Location: ?ok=trigger'); exit;
    }

    if ($accion === 'borrar_trigger') {
        $pdo->prepare("DELETE FROM wa_bot_triggers WHERE id=?")->execute([(int)($_POST['id'] ?? 0)]);
        header('Location: ?ok=borrado'); exit;
    }
}

// ── Datos para la vista ─────────────────────────────────────────────────────
$activo  = cfg_get($pdo, 'wa_bot_activo', '1') === '1';
$testNum = cfg_get($pdo, 'wa_bot_test_numero', '');
$nodos   = $pdo->query("SELECT * FROM wa_bot_nodos ORDER BY orden, id")->fetchAll(PDO::FETCH_ASSOC);
$triggers = $pdo->query("SELECT * FROM wa_bot_triggers ORDER BY prioridad DESC, id")->fetchAll(PDO::FETCH_ASSOC);
$etiquetas = $pdo->query("SELECT nombre FROM wa_etiquetas ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
$botonesPorNodo = [];
foreach ($pdo->query("SELECT * FROM wa_bot_botones ORDER BY orden, id") as $b) $botonesPorNodo[$b['nodo_id']][] = $b;

// Nodo en edición.
$edit = null;
$editBtns = [];
$editReglas = [];
if (isset($_GET['nodo'])) {
    if ($_GET['nodo'] === 'new') {
        $tpre = in_array($_GET['tipo'] ?? '', array_keys($TIPOS), true) ? $_GET['tipo'] : 'mensaje';
        $edit = ['id' => 0, 'clave' => '', 'nombre' => '', 'tipo' => $tpre, 'texto' => '', 'es_inicio' => 0, 'siguiente_clave' => '', 'fijar_normas' => '', 'etiqueta_nombre' => '', 'config_json' => '', 'activo' => 1];
    } else {
        foreach ($nodos as $n) if ((int)$n['id'] === (int)$_GET['nodo']) { $edit = $n; break; }
        if ($edit) {
            $editBtns = $botonesPorNodo[$edit['id']] ?? [];
            $rq = $pdo->prepare("SELECT * FROM wa_bot_reglas WHERE nodo_id=? ORDER BY orden, id");
            $rq->execute([$edit['id']]);
            $editReglas = $rq->fetchAll(PDO::FETCH_ASSOC);
        }
    }
}
// opciones de destino (clave => etiqueta)
$opcNodos = [];
foreach ($nodos as $n) $opcNodos[$n['clave']] = $n['nombre'] . ' (' . $n['clave'] . ')';

// ── Datos del lienzo (canvas) ────────────────────────────────────────────────
$vista = ($_GET['vista'] ?? 'canvas') === 'lista' ? 'lista' : 'canvas';
$reglasPorNodo = [];
foreach ($pdo->query("SELECT * FROM wa_bot_reglas ORDER BY orden, id") as $r) $reglasPorNodo[$r['nodo_id']][] = $r;

// Posiciones por defecto (para nodos sin coordenadas aún): rejilla escalonada.
$gi = 0;
foreach ($nodos as &$_n) {
    if ($_n['pos_x'] === null || $_n['pos_x'] === '') { $_n['pos_x'] = 80 + ($gi % 4) * 250; $_n['pos_y'] = 150 + intdiv($gi, 4) * 150; }
    $gi++;
}
unset($_n);

// Enlaces entre nodos (para dibujar las líneas del flujo). kind/lid = identidad para borrarlos.
$links = [];
$claveInicio = null;
foreach ($nodos as $n) {
    if ($n['es_inicio']) $claveInicio = $n['clave'];
    $t = $n['tipo'];
    if ($t === 'menu') {
        foreach ($botonesPorNodo[$n['id']] ?? [] as $b) $links[] = ['from' => $n['clave'], 'to' => $b['destino_clave'], 'label' => $b['label'], 'kind' => 'btn', 'lid' => (int)$b['id']];
    } elseif ($t === 'condicion') {
        foreach ($reglasPorNodo[$n['id']] ?? [] as $r) {
            $lbl = ($CAMPOS_REGLA[$r['campo']] ?? $r['campo']) . ' ' . ($OPERS_REGLA[$r['operador']] ?? $r['operador']) . ($r['valor'] ? ' "' . $r['valor'] . '"' : '');
            $links[] = ['from' => $n['clave'], 'to' => $r['destino_clave'], 'label' => $lbl, 'kind' => 'regla', 'lid' => (int)$r['id']];
        }
        if ($n['siguiente_clave']) $links[] = ['from' => $n['clave'], 'to' => $n['siguiente_clave'], 'label' => 'si nada coincide', 'kind' => 'sig', 'lid' => (int)$n['id']];
    } elseif (in_array($t, ['mensaje', 'pedir_ruc', 'pedir_normas', 'ia', 'send_template'], true)) {
        if ($n['siguiente_clave']) $links[] = ['from' => $n['clave'], 'to' => $n['siguiente_clave'], 'label' => $t === 'ia' ? 'si no hay IA' : '', 'kind' => 'sig', 'lid' => (int)$n['id']];
    }
}
// Tipos con salida (muestran el conector de arrastre).
$TIPOS_SALIDA = ['mensaje', 'menu', 'pedir_ruc', 'pedir_normas', 'condicion', 'ia', 'send_template', 'esperar', 'webhook', 'emit'];

$page_title  = 'Bot de WhatsApp';
$active_menu = 'wa_bot';
require_once __DIR__ . '/../../templates/layout.php';

$flash = ['general' => 'Ajustes guardados.', 'nodo' => 'Nodo guardado. Aplica de inmediato.', 'borrado' => 'Eliminado.', 'trigger' => 'Disparador guardado.'];
?>
<style>
  .wb-wrap { max-width: 900px; }
  .wb-card { background:var(--gris-card); border:1px solid var(--gris-borde); border-radius:12px; padding:18px 20px; margin-bottom:16px; }
  .wb-card h2 { font-size:15px; margin:0 0 4px; }
  .wb-card .sub { font-size:12px; color:var(--gris-texto); margin:0 0 14px; }
  .wb-field { margin-bottom:14px; }
  .wb-field label { display:block; font-size:12.5px; font-weight:700; color:var(--gris-dark); margin-bottom:5px; }
  .wb-field .hint { font-size:11.5px; color:var(--gris-texto); margin-top:4px; }
  .wb-field input[type=text], .wb-field textarea, .wb-field select {
    width:100%; padding:9px 12px; border:1.5px solid var(--gris-borde); border-radius:9px;
    font-size:13px; font-family:inherit; background:var(--bg-surface); color:var(--gris-dark); outline:none; resize:vertical;
  }
  .wb-field textarea { min-height:80px; line-height:1.5; }
  .wb-field input:focus, .wb-field textarea:focus, .wb-field select:focus { border-color:var(--azul); }
  .wb-ok { background:var(--s-ok-bg); color:var(--s-ok-tx); border:1px solid var(--s-ok-brd); border-radius:9px; padding:10px 14px; font-size:13px; font-weight:600; margin-bottom:16px; }
  .wb-err { background:var(--s-err-bg,#fdecec); color:var(--s-err-tx,#a12); border:1px solid var(--s-err-brd,#f3c9c9); border-radius:9px; padding:10px 14px; font-size:13px; font-weight:600; margin-bottom:16px; }
  .wb-note { background:var(--s-info-bg); border:1px solid var(--gris-borde); border-radius:9px; padding:10px 14px; font-size:12px; color:var(--gris-texto); line-height:1.6; margin-bottom:16px; }
  .wb-tokens { font-family:monospace; background:var(--bg-surface2); padding:1px 5px; border-radius:4px; }
  .wb-tbl { width:100%; border-collapse:collapse; font-size:13px; }
  .wb-tbl th { text-align:left; font-size:11px; text-transform:uppercase; letter-spacing:.4px; color:var(--gris-texto); padding:6px 8px; border-bottom:1px solid var(--gris-borde); }
  .wb-tbl td { padding:9px 8px; border-bottom:1px solid var(--gris-borde); vertical-align:top; }
  .wb-tag { display:inline-block; font-size:11px; font-weight:700; padding:2px 8px; border-radius:20px; background:var(--bg-surface2); color:var(--gris-dark); }
  .wb-tag.inicio { background:#e7f3ff; color:#1461b8; }
  .wb-flow { font-size:12px; color:var(--gris-texto); }
  .wb-flow b { color:var(--gris-dark); }
  .wb-btnrow { display:flex; gap:8px; align-items:center; margin-bottom:8px; }
  .wb-btnrow input { flex:1; }
  .wb-btnrow select { flex:1; }
  .wb-actions { display:flex; gap:8px; justify-content:flex-end; }
  .wb-mini { font-size:12px; padding:5px 10px; }
  .wb-inline { display:inline; }
</style>

<div class="wb-wrap"<?= ($edit === null && $vista === 'canvas') ? ' style="max-width:1400px;"' : '' ?>>
  <div class="page-header" style="margin-bottom:16px;">
    <h1>Bot de WhatsApp</h1>
    <p>Arma el flujo de respuesta del bot — sin tocar código. Los cambios aplican de inmediato.</p>
  </div>

  <?php if (isset($_GET['ok'])): ?><div class="wb-ok">✅ <?= sanitize($flash[$_GET['ok']] ?? 'Guardado.') ?></div><?php endif; ?>
  <?php if (isset($_GET['err']) && $_GET['err'] === 'inicio'): ?><div class="wb-err">No se puede borrar el nodo de inicio. Marca otro como inicio primero.</div><?php endif; ?>

  <!-- Ajustes generales -->
  <form method="POST" class="wb-card">
    <?= csrf_field() ?><input type="hidden" name="accion" value="general">
    <h2>Ajustes generales</h2>
    <div class="wb-field" style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">
      <input type="checkbox" name="wa_bot_activo" id="act" <?= $activo ? 'checked' : '' ?> style="width:20px;height:20px;">
      <label for="act" style="margin:0;">Bot activo (responde a quienes escriben a Frank/Andrés)</label>
    </div>
    <div class="wb-field">
      <label>Modo prueba — responder solo a este número</label>
      <input type="text" name="wa_bot_test_numero" value="<?= sanitize($testNum) ?>" placeholder="Ej.: 51924429032 · vacío = todos los clientes">
      <div class="hint">Mientras tenga un número, el bot solo responde a ese. <strong>Vacío = suelta el bot a todos</strong>.</div>
    </div>
    <div class="wb-actions"><button class="btn btn-primary btn-sm" type="submit">Guardar ajustes</button></div>
  </form>

  <?php if ($edit === null && $vista === 'lista'): ?>
  <div style="margin-bottom:12px;"><a class="btn btn-outline btn-sm" href="?">← Ver como lienzo</a></div>
  <!-- Lista de nodos -->
  <div class="wb-card">
    <h2>Flujo — pasos (nodos)</h2>
    <p class="sub">El primer contacto entra por el nodo de <b>inicio</b>. Los botones de un menú llevan a otros nodos.</p>
    <table class="wb-tbl">
      <thead><tr><th>Paso</th><th>Tipo</th><th>Enlaces</th><th style="width:120px;"></th></tr></thead>
      <tbody>
      <?php foreach ($nodos as $n): $bts = $botonesPorNodo[$n['id']] ?? []; ?>
        <tr>
          <td>
            <b><?= sanitize($n['nombre']) ?></b>
            <?php if ($n['es_inicio']): ?> <span class="wb-tag inicio">inicio</span><?php endif; ?>
            <?php if (!$n['activo']): ?> <span class="wb-tag">inactivo</span><?php endif; ?>
            <div class="wb-flow" style="color:var(--gris-texto);font-family:monospace;"><?= sanitize($n['clave']) ?></div>
          </td>
          <td><span class="wb-tag"><?= sanitize($TIPOS[$n['tipo']] ?? $n['tipo']) ?></span></td>
          <td class="wb-flow">
            <?php if ($bts): foreach ($bts as $b): ?>
              🔘 <b><?= sanitize($b['label']) ?></b> → <?= sanitize($b['destino_clave']) ?><br>
            <?php endforeach; endif; ?>
            <?php if (!empty($n['siguiente_clave'])): ?>➡️ sigue → <b><?= sanitize($n['siguiente_clave']) ?></b><br><?php endif; ?>
            <?php if (!empty($n['fijar_normas'])): ?>📌 fija normas: <?= sanitize(mb_substr($n['fijar_normas'],0,32)) ?><br><?php endif; ?>
            <?php if (!empty($n['etiqueta_nombre'])): ?>🏷️ etiqueta: <?= sanitize($n['etiqueta_nombre']) ?><?php endif; ?>
          </td>
          <td>
            <a class="btn btn-outline btn-sm" href="?nodo=<?= (int)$n['id'] ?>">Editar</a>
            <?php if (!$n['es_inicio']): ?>
            <form method="POST" class="wb-inline" onsubmit="return ggiConfirmForm(this,'¿Borrar este paso?');">
              <?= csrf_field() ?><input type="hidden" name="accion" value="borrar_nodo"><input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
              <button class="btn btn-danger btn-sm" type="submit">Borrar</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div style="margin-top:14px;"><a class="btn btn-primary btn-sm" href="?nodo=new">+ Nuevo paso</a></div>
  </div>

  <!-- Disparadores -->
  <div class="wb-card">
    <h2>Disparadores por palabra clave</h2>
    <p class="sub">En cualquier punto, si el cliente escribe alguna de estas palabras, el bot salta al paso indicado.</p>
    <table class="wb-tbl">
      <thead><tr><th>Palabras (separadas por coma)</th><th>Salta a</th><th style="width:90px;"></th></tr></thead>
      <tbody>
      <?php foreach ($triggers as $t): ?>
        <tr>
          <form method="POST">
          <?= csrf_field() ?><input type="hidden" name="accion" value="guardar_trigger"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
          <td><input type="text" name="palabras" value="<?= sanitize($t['palabras']) ?>" style="width:100%;padding:6px 9px;border:1.5px solid var(--gris-borde);border-radius:8px;background:var(--bg-surface);color:var(--gris-dark);"></td>
          <td>
            <select name="destino_clave" style="width:100%;padding:6px 9px;border:1.5px solid var(--gris-borde);border-radius:8px;background:var(--bg-surface);color:var(--gris-dark);">
              <?php foreach ($opcNodos as $k => $lbl): ?><option value="<?= sanitize($k) ?>" <?= $t['destino_clave']===$k?'selected':'' ?>><?= sanitize($lbl) ?></option><?php endforeach; ?>
            </select>
          </td>
          <td><button class="btn btn-outline btn-sm" type="submit">Guardar</button></td>
          </form>
          <td style="border:0;padding-left:0;">
            <form method="POST" onsubmit="return ggiConfirmForm(this,'¿Borrar disparador?');">
              <?= csrf_field() ?><input type="hidden" name="accion" value="borrar_trigger"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
              <button class="btn btn-danger btn-sm" type="submit">✕</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
        <!-- nuevo -->
        <tr>
          <form method="POST">
          <?= csrf_field() ?><input type="hidden" name="accion" value="guardar_trigger"><input type="hidden" name="id" value="0">
          <td><input type="text" name="palabras" placeholder="precio, costo, cuánto cuesta" style="width:100%;padding:6px 9px;border:1.5px solid var(--gris-borde);border-radius:8px;background:var(--bg-surface);color:var(--gris-dark);"></td>
          <td>
            <select name="destino_clave" style="width:100%;padding:6px 9px;border:1.5px solid var(--gris-borde);border-radius:8px;background:var(--bg-surface);color:var(--gris-dark);">
              <?php foreach ($opcNodos as $k => $lbl): ?><option value="<?= sanitize($k) ?>"><?= sanitize($lbl) ?></option><?php endforeach; ?>
            </select>
          </td>
          <td><button class="btn btn-primary btn-sm" type="submit">+ Añadir</button></td>
          </form>
        </tr>
      </tbody>
    </table>
  </div>

  <?php elseif ($edit !== null): /* ── Formulario de nodo ── */ ?>
  <div class="wb-note">
    💡 Tokens en los textos: <span class="wb-tokens">{precio}</span> <span class="wb-tokens">{ruc}</span> <span class="wb-tokens">{normas}</span> <span class="wb-tokens">{razon}</span>. Para *negrita* en WhatsApp usa un solo asterisco: <span class="wb-tokens">*texto*</span>.
  </div>
  <form method="POST" class="wb-card">
    <?= csrf_field() ?><input type="hidden" name="accion" value="guardar_nodo"><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php if (!empty($_GET['embed'])): ?><input type="hidden" name="_embed" value="1"><?php endif; ?>
    <h2><?= $edit['id'] ? 'Editar paso' : 'Nuevo paso' ?></h2>

    <div class="wb-field">
      <label>Nombre del paso</label>
      <input type="text" name="nombre" value="<?= sanitize($edit['nombre']) ?>" placeholder="Ej.: Precios ISO 9001" required>
    </div>

    <div class="wb-field">
      <label>Tipo de paso</label>
      <select name="tipo" id="tipo" onchange="wbTipo()">
        <?php foreach ($TIPOS as $k => $lbl): ?><option value="<?= $k ?>" <?= $edit['tipo']===$k?'selected':'' ?>><?= sanitize($lbl) ?></option><?php endforeach; ?>
      </select>
      <div class="hint">
        <b>Menú</b>: envía texto + botones que llevan a otros pasos. <b>Mensaje</b>: envía texto y (opcional) pasa al siguiente. <b>Pedir RUC/normas</b>: captura el dato y avanza. <b>Derivar</b>: cierra, etiqueta y avisa al asesor.
      </div>
    </div>

    <div class="wb-field">
      <label>Texto que envía</label>
      <textarea name="texto" rows="5"><?= sanitize($edit['texto']) ?></textarea>
      <div class="hint" data-solo="ia" style="display:none;">🤖 En un nodo <b>IA</b> este texto es un saludo de entrada opcional (ej. "¡Claro! Cuéntame tu duda 🙂"). Luego el copiloto responde cada mensaje del cliente con la información interna, hasta que un disparador (p. ej. "asesor") lo derive.</div>
      <div class="hint" data-solo="condicion" style="display:none;">🔀 En un nodo <b>Condición</b> el texto normalmente se deja vacío: es un paso que solo decide a dónde ir según las reglas de abajo.</div>
      <div class="hint" data-solo="esperar" style="display:none;">⏳ Texto <b>opcional</b> que se envía antes de esperar la respuesta del cliente. Déjalo vacío para solo esperar.</div>
      <div class="hint" data-solo="webhook,emit" style="display:none;">Este paso no envía texto al cliente; se configura abajo.</div>
    </div>

    <!-- Siguiente paso (mensaje / pedir_* = a dónde sigue; condición/IA = si nada aplica) -->
    <div class="wb-field" data-solo="mensaje,pedir_ruc,pedir_normas,condicion,ia,esperar,webhook,emit">
      <label><span data-solo="mensaje,pedir_ruc,pedir_normas,esperar,webhook,emit">Al terminar, ir a</span><span data-solo="condicion" style="display:none;">Si ninguna regla coincide, ir a (else)</span><span data-solo="ia" style="display:none;">Si la IA no está disponible, ir a</span></label>
      <select name="siguiente_clave">
        <option value="">— (fin del flujo) —</option>
        <?php foreach ($opcNodos as $k => $lbl): if ($k===$edit['clave']) continue; ?><option value="<?= sanitize($k) ?>" <?= $edit['siguiente_clave']===$k?'selected':'' ?>><?= sanitize($lbl) ?></option><?php endforeach; ?>
      </select>
    </div>

    <!-- Fijar normas (mensaje) -->
    <div class="wb-field" data-solo="mensaje">
      <label>Fijar norma(s) al pasar <span style="font-weight:400;color:var(--gris-texto);">(opcional)</span></label>
      <input type="text" name="fijar_normas" value="<?= sanitize($edit['fijar_normas']) ?>" placeholder="Ej.: Pack Cuádruple (ISO 9001, 14001, 45001, 37001)">
      <div class="hint">Úsalo para el Pack: al pasar por aquí se registran esas normas sin pedirlas.</div>
    </div>

    <!-- Etiqueta (derivar) -->
    <div class="wb-field" data-solo="derivar">
      <label>Etiqueta a aplicar</label>
      <select name="etiqueta_nombre">
        <option value="">— (según datos) —</option>
        <?php foreach ($etiquetas as $e): ?><option value="<?= sanitize($e) ?>" <?= $edit['etiqueta_nombre']===$e?'selected':'' ?>><?= sanitize($e) ?></option><?php endforeach; ?>
      </select>
    </div>

    <!-- Enviar plantilla (send_template) -->
    <?php $editCfg = json_decode($edit['config_json'] ?? '{}', true) ?: []; ?>
    <div class="wb-field" data-solo="send_template" style="display:none;">
      <label>Plantilla aprobada de Meta</label>
      <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <input type="text" name="tpl_name" value="<?= sanitize($editCfg['template'] ?? '') ?>" placeholder="nombre_de_la_plantilla" style="flex:1;min-width:200px;">
        <input type="text" name="tpl_lang" value="<?= sanitize($editCfg['lang'] ?? 'es') ?>" placeholder="es / es_PE" style="width:110px;">
      </div>
      <label style="margin-top:10px;">Parámetros ({{1}}, {{2}}…) — uno por línea</label>
      <textarea name="tpl_params" rows="3" placeholder="Un valor por línea. Puedes usar {ruc} {normas} {razon} {precio}"><?= sanitize(implode("\n", $editCfg['params'] ?? [])) ?></textarea>
      <div class="hint">El nombre y el idioma deben coincidir con la plantilla en Meta. OJO: las plantillas viven en la WABA de notificaciones; desde Frank/Andrés podrían no existir. Ideal para reenganchar fuera de la ventana de 24h.</div>
    </div>

    <!-- Webhook -->
    <div class="wb-field" data-solo="webhook" style="display:none;">
      <label>Webhook — llamar a una API externa</label>
      <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <select name="wh_method" style="width:110px;">
          <?php foreach (['POST','GET','PUT'] as $mm): ?><option value="<?= $mm ?>" <?= ($editCfg['method'] ?? 'POST')===$mm?'selected':'' ?>><?= $mm ?></option><?php endforeach; ?>
        </select>
        <input type="text" name="wh_url" value="<?= sanitize($editCfg['url'] ?? '') ?>" placeholder="https://api.tuservicio.com/endpoint" style="flex:1;min-width:240px;">
      </div>
      <label style="margin-top:10px;">Cuerpo (JSON, opcional — para POST/PUT)</label>
      <textarea name="wh_body" rows="3" placeholder='{"ruc":"{ruc}","normas":"{normas}"}'><?= sanitize($editCfg['body'] ?? '') ?></textarea>
      <div class="hint">Se llama al pasar por el nodo (timeout 6s, best-effort) y el flujo continúa. Puedes usar tokens {ruc} {normas} {razon} {precio} en el cuerpo. Cubre integraciones con apps externas y lógica en tu propio servidor.</div>
    </div>

    <!-- Registrar evento -->
    <div class="wb-field" data-solo="emit" style="display:none;">
      <label>Nombre del evento a registrar</label>
      <input type="text" name="ev_nombre" value="<?= sanitize($editCfg['evento'] ?? '') ?>" placeholder="Ej.: pidio_pack, cliente_calificado">
      <div class="hint">Guarda una marca en <code>wa_bot_eventos</code> (con la conversación y la fecha) para medir cuántos leads pasan por este punto del flujo.</div>
    </div>

    <!-- Botones (menu) -->
    <div class="wb-field" data-solo="menu">
      <label>Botones (cada uno lleva a otro paso)</label>
      <div id="btns">
        <?php
          $rows = $editBtns;
          for ($i = 0; $i < 4; $i++) $rows[] = ['label' => '', 'destino_clave' => ''];  // 4 renglones vacíos extra
          foreach ($rows as $b):
        ?>
        <div class="wb-btnrow">
          <input type="text" name="btn_label[]" maxlength="30" placeholder="Texto del botón (≤20)" value="<?= sanitize($b['label']) ?>">
          <select name="btn_destino[]">
            <option value="">— destino —</option>
            <?php foreach ($opcNodos as $k => $lbl): if ($k===$edit['clave']) continue; ?><option value="<?= sanitize($k) ?>" <?= $b['destino_clave']===$k?'selected':'' ?>><?= sanitize($lbl) ?></option><?php endforeach; ?>
          </select>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="hint">Hasta 3 botones se muestran como botones; de 4 a 10, como lista. Los renglones vacíos se ignoran.</div>
    </div>

    <!-- Reglas (condición) -->
    <div class="wb-field" data-solo="condicion" style="display:none;">
      <label>Reglas — se evalúan en orden; la primera que coincide manda</label>
      <div id="reglas">
        <?php
          $rrows = $editReglas;
          for ($i = 0; $i < 3; $i++) $rrows[] = ['campo' => 'texto', 'operador' => 'contiene', 'valor' => '', 'destino_clave' => '']; // 3 renglones vacíos extra
          foreach ($rrows as $r):
        ?>
        <div class="wb-btnrow" style="flex-wrap:wrap;">
          <select name="rg_campo[]" style="flex:0 0 auto;">
            <?php foreach ($CAMPOS_REGLA as $ck => $cl): ?><option value="<?= $ck ?>" <?= ($r['campo']??'')===$ck?'selected':'' ?>><?= sanitize($cl) ?></option><?php endforeach; ?>
          </select>
          <select name="rg_oper[]" style="flex:0 0 auto;">
            <?php foreach ($OPERS_REGLA as $ok => $ol): ?><option value="<?= $ok ?>" <?= ($r['operador']??'')===$ok?'selected':'' ?>><?= sanitize($ol) ?></option><?php endforeach; ?>
          </select>
          <input type="text" name="rg_valor[]" placeholder="valor (para contiene)" value="<?= sanitize($r['valor'] ?? '') ?>" style="flex:1;min-width:120px;">
          <select name="rg_destino[]" style="flex:1;min-width:120px;">
            <option value="">— ir a —</option>
            <?php foreach ($opcNodos as $k => $lbl): if ($k===$edit['clave']) continue; ?><option value="<?= sanitize($k) ?>" <?= ($r['destino_clave']??'')===$k?'selected':'' ?>><?= sanitize($lbl) ?></option><?php endforeach; ?>
          </select>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="hint">Ej.: "El mensaje" · contiene · "precio" → ir a Precios. Con "ya se capturó" / "aún no se captura" ramificas según si tienes el RUC o las normas. Si nada coincide, va al paso "else" de arriba.</div>
    </div>

    <div class="wb-field" style="display:flex;align-items:center;gap:10px;">
      <input type="checkbox" name="es_inicio" id="ini" <?= $edit['es_inicio']?'checked':'' ?> style="width:19px;height:19px;">
      <label for="ini" style="margin:0;">Es el paso de <b>inicio</b> (primer contacto)</label>
    </div>
    <div class="wb-field" style="display:flex;align-items:center;gap:10px;">
      <input type="checkbox" name="activo" id="acn" <?= $edit['activo']?'checked':'' ?> style="width:19px;height:19px;">
      <label for="acn" style="margin:0;">Activo</label>
    </div>

    <div class="wb-actions">
      <?php if (!empty($_GET['embed'])): ?>
        <button type="button" class="btn btn-outline btn-sm" onclick="parent.postMessage({ggi:'close'},'*')">Cancelar</button>
      <?php else: ?>
        <a class="btn btn-outline btn-sm" href="<?= $_SERVER['PHP_SELF'] ?? 'wa_bot.php' ?>">Cancelar</a>
      <?php endif; ?>
      <button class="btn btn-primary btn-sm" type="submit">Guardar paso</button>
    </div>
  </form>
  <script>
    function wbTipo(){
      var t = document.getElementById('tipo').value;
      document.querySelectorAll('[data-solo]').forEach(function(el){
        el.style.display = el.getAttribute('data-solo').split(',').indexOf(t) >= 0 ? '' : 'none';
      });
    }
    wbTipo();
  </script>

  <?php else: /* ── Lienzo visual (canvas) ── */
    $sx = 80; $sy = 40;
    foreach ($nodos as $n) if ($n['clave'] === $claveInicio) { $sx = (int)$n['pos_x']; $sy = max(20, (int)$n['pos_y'] - 90); break; }
  ?>
  <div class="cv-top">
    <div class="cv-add-wrap">
      <button type="button" class="btn btn-primary btn-sm" onclick="cvTogglePal(event)">+ Add Step</button>
      <div class="cv-pal" id="cv-pal">
        <div class="cv-pal-h">Elige un paso</div>
        <?php foreach ($TIPOS as $k => $lbl): [$ico, $col] = $TIPO_META[$k] ?? ['•', '#888']; ?>
        <a class="cv-pal-item" href="?nodo=new&tipo=<?= $k ?>" onclick="return cvOpen(this.href,'Nuevo paso')"><span class="cv-pal-ico" style="color:<?= $col ?>;"><?= $ico ?></span> <?= sanitize($lbl) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
    <div style="display:flex;gap:8px;align-items:center;">
      <span class="hint" style="margin:0;">Arrastra las tarjetas para ordenarlas. Clic en “Editar” para configurar el paso.</span>
      <a class="btn btn-outline btn-sm" href="?vista=lista">Ver como lista</a>
    </div>
  </div>

  <div class="cv-canvas" id="cv-canvas">
    <svg class="cv-svg" id="cv-svg" width="2200" height="1500">
      <defs><marker id="cv-arrow" markerWidth="9" markerHeight="9" refX="7" refY="3" orient="auto"><path d="M0,0 L7,3 L0,6 Z" fill="var(--gris-texto)"/></marker></defs>
    </svg>

    <div class="cv-node cv-start" data-clave="__start__" style="left:<?= $sx ?>px;top:<?= $sy ?>px;">▶ Start</div>

    <?php foreach ($nodos as $n): [$ico, $col] = $TIPO_META[$n['tipo']] ?? ['•', '#888']; ?>
    <div class="cv-node<?= $n['activo'] ? '' : ' cv-off' ?>" data-id="<?= (int)$n['id'] ?>" data-clave="<?= sanitize($n['clave']) ?>"
         style="left:<?= (int)$n['pos_x'] ?>px;top:<?= (int)$n['pos_y'] ?>px;border-left:4px solid <?= $col ?>;">
      <div class="cv-h"><span class="cv-ico"><?= $ico ?></span> <b><?= sanitize($n['nombre'] ?: $n['clave']) ?></b><?php if ($n['es_inicio']): ?> <span class="cv-badge">inicio</span><?php endif; ?></div>
      <div class="cv-t"><?= sanitize($TIPOS[$n['tipo']] ?? $n['tipo']) ?></div>
      <div class="cv-acts"><a href="?nodo=<?= (int)$n['id'] ?>" onclick="return cvOpen(this.href,'Configurar paso')">Editar</a><?php if (!$n['es_inicio']): ?> · <a href="#" data-del="<?= (int)$n['id'] ?>">Borrar</a><?php endif; ?></div>
      <?php if (in_array($n['tipo'], $TIPOS_SALIDA, true)): ?><div class="cv-out" title="Arrastra hasta otro paso para conectar"></div><?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>

  <form id="cv-del-form" method="POST" style="display:none;"><?= csrf_field() ?><input type="hidden" name="accion" value="borrar_nodo"><input type="hidden" name="id" id="cv-del-id"></form>

  <script>
    const CV_LINKS = <?= json_encode($links, JSON_UNESCAPED_UNICODE) ?>;
    const CV_START = <?= json_encode(['to' => $claveInicio]) ?>;
    const CV_TOKEN = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const cvCanvas = document.getElementById('cv-canvas');
    const cvSvg = document.getElementById('cv-svg');

    function cvTogglePal(e){ if(e) e.stopPropagation(); document.getElementById('cv-pal').classList.toggle('open'); }
    document.addEventListener('click', () => document.getElementById('cv-pal')?.classList.remove('open'));

    // Abre la configuración del paso en un POPUP (modal-iframe global). Al guardar,
    // el form avisa {ggi:created/updated} y recargamos el lienzo.
    function cvOpen(url, titulo){
      document.getElementById('cv-pal')?.classList.remove('open');
      if (typeof ggiOpenForm === 'function'){ ggiOpenForm(url, titulo, function(){ location.reload(); }); return false; }
      return true; // sin el modal global → navega normal (fallback)
    }

    const NS = 'http://www.w3.org/2000/svg';
    function mk(tag, attrs){ const e = document.createElementNS(NS, tag); for (const k in attrs) e.setAttribute(k, attrs[k]); return e; }
    function cvPost(data){ const b = new URLSearchParams(Object.assign({ csrf_token:CV_TOKEN }, data));
      return fetch('', { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body:b }).then(r=>r.json()).catch(()=>({ok:false})); }

    function cvNodo(clave){ return cvCanvas.querySelector('.cv-node[data-clave="'+CSS.escape(clave)+'"]'); }
    function cvAnchor(el, side){ const x = el.offsetLeft + el.offsetWidth/2; const y = side === 'out' ? el.offsetTop + el.offsetHeight : el.offsetTop; return {x, y}; }
    function cvPath(a, b){ const dy = Math.max(30, Math.abs(b.y-a.y)/2); return `M${a.x},${a.y} C${a.x},${a.y+dy} ${b.x},${b.y-dy} ${b.x},${b.y}`; }
    function cvXY(e){ const cr = cvCanvas.getBoundingClientRect(); return { x:e.clientX-cr.left+cvCanvas.scrollLeft, y:e.clientY-cr.top+cvCanvas.scrollTop }; }

    function cvDraw(){
      [...cvSvg.querySelectorAll('.cv-edge')].forEach(n=>n.remove());
      const all = CV_LINKS.slice();
      if (CV_START.to) all.push({from:'__start__', to:CV_START.to, label:'', kind:'start'});
      let maxX=1200, maxY=700;
      all.forEach(lk => {
        const s = cvNodo(lk.from), t = cvNodo(lk.to); if(!s || !t) return;
        const a = cvAnchor(s,'out'), b = cvAnchor(t,'in'); const d = cvPath(a,b);
        maxX=Math.max(maxX, s.offsetLeft+s.offsetWidth+120, t.offsetLeft+t.offsetWidth+120);
        maxY=Math.max(maxY, s.offsetTop+s.offsetHeight+120, t.offsetTop+t.offsetHeight+120);
        const g = mk('g',{class:'cv-edge'});
        g.appendChild(mk('path',{d, fill:'none', stroke:'transparent','stroke-width':14, class:'cv-hit'}));
        g.appendChild(mk('path',{d, fill:'none', stroke:'var(--gris-texto)','stroke-width':1.8,'marker-end':'url(#cv-arrow)', opacity:.55, class:'cv-line'}));
        const mx=(a.x+b.x)/2, my=(a.y+b.y)/2;
        if (lk.label){ const tx = mk('text',{x:mx, y:my-8,'text-anchor':'middle','font-size':10, fill:'var(--gris-texto)', class:'cv-lbl'}); tx.textContent = lk.label.length>28?lk.label.slice(0,28)+'…':lk.label; g.appendChild(tx); }
        if (lk.kind && lk.kind!=='start'){
          const del = mk('g',{class:'cv-del', transform:`translate(${mx},${my})`});
          del.appendChild(mk('circle',{r:8, fill:'#dc2626'}));
          const xx = mk('text',{x:0, y:3.5,'text-anchor':'middle','font-size':12, fill:'#fff'}); xx.textContent='×'; del.appendChild(xx);
          del.addEventListener('click', ev => { ev.stopPropagation(); GGIConfirm.show({ icon:'danger', title:'Quitar conexión', message:'¿Quitar esta conexión?', onConfirm:()=>cvPost({accion:'desconectar', kind:lk.kind, lid:lk.lid}).then(()=>location.reload()) }); });
          g.appendChild(del);
        }
        cvSvg.appendChild(g);
      });
      cvSvg.setAttribute('width', maxX); cvSvg.setAttribute('height', maxY);
      cvCanvas.style.minWidth = maxX+'px'; cvCanvas.style.minHeight = maxY+'px';
    }

    // ── Arrastre: mover tarjeta O conectar (según dónde empieza) ──
    let cvDrag = null, cvConn = null, cvTmp = null, cvTmpFrom = null;
    cvCanvas.addEventListener('mousedown', e => {
      const handle = e.target.closest('.cv-out');
      if (handle){ // iniciar CONEXIÓN
        const card = handle.closest('.cv-node');
        cvConn = { fromId: card.dataset.id, fromClave: card.dataset.clave };
        cvTmpFrom = cvAnchor(card,'out');
        const g = mk('g',{class:'cv-edge cv-tmp'});
        cvTmp = mk('path',{d:'', fill:'none', stroke:'#22c55e','stroke-width':2.2,'stroke-dasharray':'6 4','marker-end':'url(#cv-arrow)'});
        g.appendChild(cvTmp); cvSvg.appendChild(g);
        e.preventDefault(); e.stopPropagation(); return;
      }
      const card = e.target.closest('.cv-node');
      if (!card || card.classList.contains('cv-start') || e.target.closest('a')) return;
      const r = card.getBoundingClientRect();
      cvDrag = { card, dx: e.clientX - r.left, dy: e.clientY - r.top, moved:false };
      card.classList.add('cv-dragging'); e.preventDefault();
    });

    document.addEventListener('mousemove', e => {
      if (cvConn){
        const p = cvXY(e); cvTmp.setAttribute('d', cvPath(cvTmpFrom, p));
        const el = document.elementFromPoint(e.clientX, e.clientY);
        const tgt = el && el.closest ? el.closest('.cv-node') : null;
        cvCanvas.querySelectorAll('.cv-drop').forEach(n=>n.classList.remove('cv-drop'));
        if (tgt && !tgt.classList.contains('cv-start') && tgt.dataset.clave !== cvConn.fromClave) tgt.classList.add('cv-drop');
        return;
      }
      if (!cvDrag) return;
      const cr = cvCanvas.getBoundingClientRect();
      let x = Math.max(0, e.clientX - cr.left - cvDrag.dx + cvCanvas.scrollLeft);
      let y = Math.max(0, e.clientY - cr.top  - cvDrag.dy + cvCanvas.scrollTop);
      cvDrag.card.style.left = x+'px'; cvDrag.card.style.top = y+'px';
      cvDrag.moved = true; cvDraw();
    });

    document.addEventListener('mouseup', e => {
      if (cvConn){
        const el = document.elementFromPoint(e.clientX, e.clientY);
        const tgt = el && el.closest ? el.closest('.cv-node') : null;
        cvCanvas.querySelectorAll('.cv-drop').forEach(n=>n.classList.remove('cv-drop'));
        cvSvg.querySelector('.cv-tmp')?.remove();
        if (tgt && !tgt.classList.contains('cv-start') && tgt.dataset.clave !== cvConn.fromClave){
          cvPost({ accion:'conectar', from_id:cvConn.fromId, to_clave:tgt.dataset.clave })
            .then(r => { if(r.ok) location.reload(); else if(typeof showToast==='function') showToast(r.msg||'No se pudo conectar','error'); });
        }
        cvConn = null; return;
      }
      if (!cvDrag) return;
      const c = cvDrag.card; c.classList.remove('cv-dragging');
      if (cvDrag.moved) cvPost({ accion:'pos', id:c.dataset.id, x:parseInt(c.style.left), y:parseInt(c.style.top) });
      cvDrag = null;
    });

    // Borrar paso
    cvCanvas.addEventListener('click', e => {
      const d = e.target.closest('[data-del]');
      if (!d) return; e.preventDefault();
      GGIConfirm.show({ icon:'danger', title:'Borrar paso', message:'¿Borrar este paso?', onConfirm:()=>{ document.getElementById('cv-del-id').value = d.dataset.del; document.getElementById('cv-del-form').submit(); } });
    });

    window.addEventListener('resize', cvDraw);
    cvDraw();
  </script>
  <?php endif; ?>
</div>

<style>
  .cv-top { display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:10px; flex-wrap:wrap; }
  .cv-add-wrap { position:relative; }
  .cv-pal { position:absolute; top:calc(100% + 6px); left:0; z-index:30; min-width:240px; background:var(--bg-surface); border:1px solid var(--gris-borde); border-radius:10px; box-shadow:0 12px 30px rgba(0,0,0,.18); padding:6px; display:none; }
  .cv-pal.open { display:block; }
  .cv-pal-h { font-size:11px; text-transform:uppercase; letter-spacing:.4px; color:var(--gris-texto); padding:6px 8px; }
  .cv-pal-item { display:flex; align-items:center; gap:9px; padding:8px 9px; border-radius:8px; font-size:13px; color:var(--gris-dark); text-decoration:none; }
  .cv-pal-item:hover { background:var(--bg-hover); }
  .cv-pal-ico { width:18px; text-align:center; }
  .cv-canvas { position:relative; width:100%; height:70vh; overflow:auto; background:var(--gris-bg);
    background-image:radial-gradient(var(--gris-borde) 1px, transparent 1px); background-size:20px 20px;
    border:1px solid var(--gris-borde); border-radius:12px; }
  .cv-svg { position:absolute; top:0; left:0; pointer-events:none; }
  .cv-hit { pointer-events:stroke; cursor:pointer; }
  .cv-del { cursor:pointer; opacity:0; pointer-events:all; transition:opacity .12s; }
  .cv-edge:hover .cv-del { opacity:1; }
  .cv-edge:hover .cv-line { opacity:.95 !important; stroke:var(--azul,#1d4ed8) !important; }
  .cv-out { position:absolute; left:50%; bottom:-7px; width:14px; height:14px; margin-left:-7px;
    background:#22c55e; border:2px solid var(--bg-surface); border-radius:50%; cursor:crosshair; z-index:4; }
  .cv-out:hover { transform:scale(1.2); box-shadow:0 0 0 4px rgba(34,197,94,.25); }
  .cv-node.cv-drop { outline:2px solid #22c55e; outline-offset:2px; }
  .cv-node { position:absolute; width:190px; background:var(--bg-surface); border:1px solid var(--gris-borde);
    border-radius:10px; padding:9px 11px; box-shadow:0 2px 8px rgba(0,0,0,.08); cursor:grab; user-select:none; z-index:2; }
  .cv-node.cv-dragging { cursor:grabbing; box-shadow:0 8px 22px rgba(0,0,0,.22); z-index:5; }
  .cv-node.cv-off { opacity:.55; }
  .cv-h { font-size:13px; color:var(--gris-dark); display:flex; align-items:center; gap:6px; }
  .cv-h b { font-weight:700; }
  .cv-ico { font-size:14px; }
  .cv-t { font-size:11px; color:var(--gris-texto); margin:3px 0 6px; }
  .cv-acts { font-size:11.5px; }
  .cv-acts a { color:var(--azul,#1d4ed8); text-decoration:none; }
  .cv-acts a:hover { text-decoration:underline; }
  .cv-badge { font-size:10px; font-weight:700; background:#e7f3ff; color:#1461b8; padding:1px 6px; border-radius:20px; }
  .cv-start { width:auto; background:#111827; color:#fff; font-weight:700; font-size:13px; cursor:default; border:0; }
</style>

<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
