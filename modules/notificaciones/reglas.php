<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();
require_role('gerente_general');

$pdo = Database::get();

// ══════════════════════════════════════════════════════════════════════════════
// Catálogos centrales — fuente única de verdad
// ══════════════════════════════════════════════════════════════════════════════
const ROLES = [
    'gerente_general'     => ['label' => 'Gerente General',        'color' => 'rol-gm'],
    'gerente_operaciones' => ['label' => 'Gerente de Operaciones',  'color' => 'rol-go'],
    'ejecutivo_ventas'    => ['label' => 'Ejecutivo de Ventas',     'color' => 'rol-ev'],
    'gestor_logistico'    => ['label' => 'Gestor Logístico',        'color' => 'rol-gl'],
    'delivery'            => ['label' => 'Delivery',                'color' => 'rol-dl'],
];

const RESOLUTIONS = [
    'ROLE' => [
        'label'  => 'Todos los usuarios del rol',
        'sub'    => 'Cada usuario activo con este rol recibe la notificación.',
        'color'  => 'res-all',
        'icon'   => '<path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>',
    ],
    'COMPANY_EXECUTIVE' => [
        'label'  => 'Ejecutivo asignado a la empresa',
        'sub'    => 'El ejecutivo responsable de la empresa relacionada al evento.',
        'color'  => 'res-cmp',
        'icon'   => '<path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-2 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>',
    ],
    'PROPOSAL_OWNER' => [
        'label'  => 'Ejecutivo de la propuesta',
        'sub'    => 'El ejecutivo asignado a la cotización relacionada al evento.',
        'color'  => 'res-own',
        'icon'   => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>',
    ],
];

const MOD_LABELS = [
    'cotizaciones' => 'Propuestas / Cotizaciones',
    'pagos'        => 'Pagos',
    'clientes'     => 'Clientes',
    'certificados' => 'Certificados',
    'envios'       => 'Envíos',
];

const PRIO = [1 => 'Alta', 2 => 'Normal', 3 => 'Baja', 4 => 'Muy baja', 5 => 'Mínima'];

// ══════════════════════════════════════════════════════════════════════════════
// AJAX — POST
// ══════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    csrf_verify();
    $accion = trim($_POST['accion'] ?? '');

    // ── Agregar regla ───────────────────────────────────────────────────────
    if ($accion === 'agregar') {
        $event_code  = trim($_POST['event_code'] ?? '');
        $role        = trim($_POST['role'] ?? '');
        $resolution  = trim($_POST['resolution'] ?? '');

        if (!$event_code || !array_key_exists($role, ROLES) || !array_key_exists($resolution, RESOLUTIONS)) {
            echo json_encode(['ok' => false, 'msg' => 'Parámetros inválidos.']); exit;
        }

        // Evitar duplicados exactos
        $dup = $pdo->prepare("SELECT id FROM notification_rules WHERE event_code=? AND recipient_type=? AND role=?");
        $dup->execute([$event_code, $resolution, $role]);
        if ($dup->fetch()) {
            echo json_encode(['ok' => false, 'msg' => 'Ya existe una regla con ese rol y tipo de asignación.']); exit;
        }

        $pdo->prepare("
            INSERT INTO notification_rules (event_code, recipient_type, role, enabled, priority, via_interna, via_push)
            VALUES (?, ?, ?, 1, 2, 1, 0)
        ")->execute([$event_code, $resolution, $role]);

        $new_id = (int)$pdo->lastInsertId();
        log_actividad('notificaciones', 'regla_creada', "Regla #{$new_id}: {$event_code} → rol:{$role} tipo:{$resolution}");
        echo json_encode(['ok' => true, 'rule_id' => $new_id]);
        exit;
    }

    // ── Eliminar regla ──────────────────────────────────────────────────────
    if ($accion === 'eliminar') {
        $rule_id = (int)($_POST['rule_id'] ?? 0);
        if (!$rule_id) { echo json_encode(['ok' => false, 'msg' => 'ID inválido.']); exit; }
        $pdo->prepare("DELETE FROM notification_rules WHERE id=?")->execute([$rule_id]);
        log_actividad('notificaciones', 'regla_eliminada', "Regla #{$rule_id} eliminada");
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── Actualizar campo de regla ───────────────────────────────────────────
    if ($accion === 'actualizar') {
        $rule_id = (int)($_POST['rule_id'] ?? 0);
        $campo   = trim($_POST['campo'] ?? '');
        $valor   = trim($_POST['valor'] ?? '');
        if (!$rule_id || !in_array($campo, ['enabled','via_interna','via_push','priority'], true)) {
            echo json_encode(['ok' => false, 'msg' => 'Parámetros inválidos.']); exit;
        }
        $valor = $campo === 'priority' ? max(1, min(5, (int)$valor)) : ($valor === '1' ? 1 : 0);
        $pdo->prepare("UPDATE notification_rules SET {$campo}=? WHERE id=?")->execute([$valor, $rule_id]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── Activar / desactivar evento ─────────────────────────────────────────
    if ($accion === 'toggle_evento') {
        $event_code = trim($_POST['event_code'] ?? '');
        $valor      = $_POST['valor'] === '1' ? 1 : 0;
        if (!$event_code) { echo json_encode(['ok' => false, 'msg' => 'Código inválido.']); exit; }
        $pdo->prepare("UPDATE notification_events SET activo=? WHERE code=?")->execute([$valor, $event_code]);
        log_actividad('notificaciones', 'evento_' . ($valor ? 'activado' : 'desactivado'), $event_code);
        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['ok' => false, 'msg' => 'Acción no reconocida.']); exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// RENDER — cargar datos
// ══════════════════════════════════════════════════════════════════════════════
$rows = $pdo->query("
    SELECT e.code, e.name, e.module, e.activo,
           r.id AS rule_id, r.recipient_type, r.role,
           r.via_interna, r.via_push, r.priority, r.enabled
    FROM notification_events e
    LEFT JOIN notification_rules r ON r.event_code = e.code
    ORDER BY e.module, e.code, r.id
")->fetchAll();

$grouped      = [];
$total_rules  = 0;
$total_events = 0;

foreach ($rows as $row) {
    $mod  = $row['module'];
    $code = $row['code'];
    if (!isset($grouped[$mod]))        $grouped[$mod] = [];
    if (!isset($grouped[$mod][$code])) {
        $grouped[$mod][$code] = ['name' => $row['name'], 'activo' => (bool)$row['activo'], 'rules' => []];
        $total_events++;
    }
    if ($row['rule_id']) {
        $grouped[$mod][$code]['rules'][] = [
            'id'   => (int)$row['rule_id'],
            'type' => $row['recipient_type'],
            'role' => $row['role'] ?? '',
            'ii'   => (bool)$row['via_interna'],
            'pp'   => (bool)$row['via_push'],
            'prio' => (int)$row['priority'],
            'on'   => (bool)$row['enabled'],
        ];
        $total_rules++;
    }
}

// ── Helpers de render ─────────────────────────────────────────────────────────
function roleLabel(string $r): string  { return ROLES[$r]['label']        ?? $r; }
function roleCls(string $r):   string  { return ROLES[$r]['color']        ?? 'rol-dl'; }
function resLabel(string $t):  string  { return RESOLUTIONS[$t]['label']  ?? $t; }
function resCls(string $t):    string  { return RESOLUTIONS[$t]['color']  ?? ''; }
function resIcon(string $t):   string  { return RESOLUTIONS[$t]['icon']   ?? ''; }

function ini2(string $s): string {
    $w = preg_split('/\s+/', trim($s));
    return count($w) >= 2
        ? strtoupper(mb_substr($w[0], 0, 1) . mb_substr($w[1], 0, 1))
        : strtoupper(mb_substr($w[0], 0, 2));
}

$page_title  = 'Reglas de Notificación';
$active_menu = 'notif_reglas';
require_once __DIR__ . '/../../templates/layout.php';
?>
<style>
/* ── Variables ─────────────────────────────────────────────────────── */
:root {
    /* Role badges */
    --rol-gm-bg:var(--s-err-bg); --rol-gm-tx:var(--s-err-tx); --rol-gm-bd:var(--s-err-brd);
    --rol-go-bg:var(--s-info-bg); --rol-go-tx:#1d4ed8; --rol-go-bd:#bfdbfe;
    --rol-ev-bg:var(--s-purple-bg); --rol-ev-tx:#5b21b6; --rol-ev-bd:#ddd6fe;
    --rol-gl-bg:var(--s-warn-bg); --rol-gl-tx:#c2410c; --rol-gl-bd:#fed7aa;
    --rol-dl-bg:#f1f5f9; --rol-dl-tx:#475569; --rol-dl-bd:#cbd5e1;
    /* Resolution badges */
    --res-all-bg:var(--s-ok-bg); --res-all-tx:var(--s-ok-tx); --res-all-bd:var(--s-ok-brd);
    --res-cmp-bg:#f0fdfa; --res-cmp-tx:#0f766e; --res-cmp-bd:#99f6e4;
    --res-own-bg:var(--s-warn-bg); --res-own-tx:var(--s-warn-tx); --res-own-bd:#fde68a;
}

/* ── Wrap ──────────────────────────────────────────────────────────── */
.nr-wrap        { max-width: 1020px; margin: 0 auto; padding-bottom: 3rem; }

/* ── Cabecera de página ────────────────────────────────────────────── */
.nr-hd          { display:flex; align-items:flex-end; justify-content:space-between;
                  margin-bottom:1.5rem; flex-wrap:wrap; gap:1rem; }
.nr-hd h1       { font-size:1.3rem; font-weight:700; color:var(--tx); }
.nr-hd p        { font-size:.78rem; color:var(--gris-texto); margin-top:.2rem; }
.nr-stats       { display:flex; gap:.75rem; }
.nr-stat        { text-align:center; background:var(--bg-surface); border:1px solid var(--brd);
                  border-radius:8px; padding:.45rem .9rem; min-width:52px; }
.nr-stat-n      { display:block; font-size:1.2rem; font-weight:700; color:var(--ac); line-height:1; }
.nr-stat-l      { display:block; font-size:.62rem; text-transform:uppercase; letter-spacing:.07em;
                  color:var(--gris-texto); margin-top:.1rem; }

/* ── Filtros de módulo ─────────────────────────────────────────────── */
.nr-mf          { display:flex; gap:.3rem; margin-bottom:1.25rem; flex-wrap:wrap; }
.mf-btn         { font-size:.7rem; font-weight:600; padding:.28rem .7rem; border-radius:99px;
                  border:1px solid var(--brd); background:transparent; color:var(--gris-texto);
                  cursor:pointer; transition:.14s; white-space:nowrap; }
.mf-btn:hover   { background:var(--bg-hover); color:var(--tx); }
.mf-btn.active  { background:var(--ac); color:#fff; border-color:var(--ac); }

/* ── Sección de módulo ─────────────────────────────────────────────── */
.nr-section     { margin-bottom:1.5rem; }
.nr-sec-lbl     { font-size:.65rem; font-weight:700; text-transform:uppercase;
                  letter-spacing:.1em; color:var(--gris-texto); padding:0 .2rem;
                  margin-bottom:.45rem; display:flex; align-items:center; gap:.5rem; }
.nr-sec-lbl::after { content:''; flex:1; height:1px; background:var(--brd); }

/* ── Tarjeta de evento ─────────────────────────────────────────────── */
.nr-card        { background:var(--bg-surface); border:1px solid var(--brd); border-radius:10px;
                  margin-bottom:.5rem; overflow:hidden;
                  box-shadow:0 1px 3px rgba(0,0,0,.05);
                  transition:box-shadow .2s, border-color .2s; }
.nr-card.is-open{ box-shadow:0 4px 16px rgba(0,0,0,.08); border-color:var(--ac); }
.nr-card.ev-off { opacity:.62; }

/* ── Cabecera de tarjeta ───────────────────────────────────────────── */
.nr-head        { display:flex; align-items:center; gap:.7rem; padding:.8rem 1rem;
                  cursor:pointer; user-select:none; transition:background .12s; }
.nr-head:hover  { background:var(--bg-hover); }
.nr-chev        { flex-shrink:0; width:15px; height:15px; color:var(--gris-texto);
                  transition:transform .24s ease; }
.nr-card.is-open .nr-chev { transform:rotate(90deg); }

.nr-head-name   { flex:1; min-width:0; }
.nr-head-name strong { font-size:.875rem; font-weight:600; color:var(--tx);
                       white-space:nowrap; overflow:hidden; text-overflow:ellipsis; display:block; }
.nr-head-name code   { font-size:.66rem; color:var(--gris-texto); font-family:monospace; }

/* Meta resumen (derecha de la cabecera) */
.nr-meta        { display:flex; align-items:center; gap:.45rem; flex-shrink:0;
                  flex-wrap:wrap; justify-content:flex-end; }

/* Chips resumen de regla */
.rs-chip        { display:inline-flex; align-items:center; gap:.25rem;
                  font-size:.66rem; font-weight:600; padding:.18rem .5rem;
                  border-radius:99px; border-width:1px; border-style:solid; }

/* Pills de canal */
.ch-ii,.ch-pp   { font-size:.63rem; font-weight:600; padding:.14rem .42rem; border-radius:99px; }
.ch-ii          { background:var(--s-info-bg); color:#1d4ed8; }
.ch-pp          { background:var(--s-purple-bg); color:#6d28d9; }

/* Badge prioridad */
.pb             { font-size:.62rem; font-weight:700; padding:.14rem .42rem; border-radius:4px; }
.pb-1           { background:var(--s-err-bg); color:var(--s-err-tx); }
.pb-2           { background:var(--s-warn-bg); color:var(--s-warn-tx); }
.pb-3,.pb-4,.pb-5 { background:var(--s-info-bg); color:#475569; }

/* Pill de estado del evento */
.ev-st          { font-size:.62rem; font-weight:600; padding:.14rem .48rem; border-radius:99px;
                  display:flex; align-items:center; gap:.28rem; }
.ev-st::before  { content:''; display:inline-block; width:5px; height:5px; border-radius:50%; }
.es-on          { background:var(--s-ok-bg); color:var(--s-ok-tx); }
.es-on::before  { background:#22c55e; }
.es-off         { background:var(--s-err-bg); color:var(--s-err-tx); }
.es-off::before { background:#ef4444; }

/* ── Cuerpo del acordeón ───────────────────────────────────────────── */
.nr-body        { border-top:1px solid var(--brd);
                  display:grid; grid-template-rows:0fr;
                  transition:grid-template-rows .27s ease; }
.nr-card.is-open .nr-body { grid-template-rows:1fr; }
.nr-body-inner  { overflow:hidden; }
.nr-body-pad    { padding:1rem; display:flex; flex-direction:column; gap:1rem; }

/* ── Sección de reglas dentro del cuerpo ──────────────────────────── */
.rules-hd       { font-size:.67rem; font-weight:700; text-transform:uppercase;
                  letter-spacing:.09em; color:var(--gris-texto); }

/* ── Fila de regla ─────────────────────────────────────────────────── */
.rule-row       { display:flex; align-items:center; gap:.6rem; padding:.6rem .8rem;
                  background:var(--bg-base); border:1px solid var(--brd); border-radius:8px;
                  margin-bottom:.35rem; transition:opacity .18s; }
.rule-row.is-disabled { opacity:.5; }
.rule-row.is-saving   { opacity:.55; pointer-events:none; }

/* Bloque Rol → Asignación */
.rule-assign    { flex:1; min-width:0; display:flex; align-items:center; gap:.5rem; flex-wrap:wrap; }

.role-badge     { display:inline-flex; align-items:center; gap:.3rem; font-size:.72rem;
                  font-weight:600; padding:.22rem .55rem; border-radius:6px;
                  border-width:1px; border-style:solid; flex-shrink:0; }
.role-badge svg { width:11px; height:11px; flex-shrink:0; }

.rol-gm { background:var(--rol-gm-bg); color:var(--rol-gm-tx); border-color:var(--rol-gm-bd); }
.rol-go { background:var(--rol-go-bg); color:var(--rol-go-tx); border-color:var(--rol-go-bd); }
.rol-ev { background:var(--rol-ev-bg); color:var(--rol-ev-tx); border-color:var(--rol-ev-bd); }
.rol-gl { background:var(--rol-gl-bg); color:var(--rol-gl-tx); border-color:var(--rol-gl-bd); }
.rol-dl { background:var(--rol-dl-bg); color:var(--rol-dl-tx); border-color:var(--rol-dl-bd); }

.arr-icon { color:var(--gris-texto); flex-shrink:0; font-size:.7rem; }

.res-badge      { display:inline-flex; align-items:center; gap:.28rem; font-size:.7rem;
                  font-weight:500; color:var(--gris-texto); }
.res-badge svg  { width:12px; height:12px; flex-shrink:0; }
.res-badge.res-all { color:var(--res-all-tx); }
.res-badge.res-cmp { color:var(--res-cmp-tx); }
.res-badge.res-own { color:var(--res-own-tx); }

/* Controles canal/prioridad */
.rule-ctrl      { display:flex; align-items:center; gap:.3rem; flex-shrink:0; }
.ctog           { font-size:.63rem; font-weight:700; padding:.18rem .4rem; border-radius:5px;
                  border:1px solid var(--brd); cursor:pointer; transition:.12s; }
.ctog-ii[data-on="1"] { background:var(--s-info-bg); color:#1d4ed8; border-color:#bfdbfe; }
.ctog-ii[data-on="0"] { background:transparent; color:var(--gris-texto); }
.ctog-pp[data-on="1"] { background:var(--s-purple-bg); color:#6d28d9; border-color:#ddd6fe; }
.ctog-pp[data-on="0"] { background:transparent; color:var(--gris-texto); }
.psel           { font-size:.65rem; font-weight:600; padding:.18rem .35rem; border-radius:5px;
                  border:1px solid var(--brd); background:var(--bg-input); color:var(--tx);
                  cursor:pointer; }
.ctog-en        { font-size:.63rem; font-weight:700; padding:.18rem .48rem; border-radius:99px;
                  border-width:1px; border-style:solid; cursor:pointer; transition:.12s; }
.ctog-en[data-on="1"] { background:var(--s-ok-bg); color:var(--s-ok-tx); border-color:#86efac; }
.ctog-en[data-on="0"] { background:var(--s-err-bg); color:var(--s-err-tx); border-color:#fca5a5; }

.rule-del       { flex-shrink:0; width:22px; height:22px; border:none; border-radius:5px;
                  background:transparent; color:var(--gris-texto); cursor:pointer; font-size:.95rem;
                  display:flex; align-items:center; justify-content:center; transition:.12s; padding:0; }
.rule-del:hover { background:var(--s-err-bg); color:var(--s-err-tx); }

.rules-empty    { padding:1.1rem; text-align:center; border:1px dashed var(--brd);
                  border-radius:8px; color:var(--gris-texto); font-size:.78rem;
                  background:var(--bg-base); }

/* ── Formulario de agregar regla ───────────────────────────────────── */
.add-rule-wrap  { background:var(--bg-base); border:1px solid var(--brd); border-radius:8px;
                  padding:.75rem; }
.add-rule-lbl   { font-size:.67rem; font-weight:700; text-transform:uppercase;
                  letter-spacing:.08em; color:var(--gris-texto); margin-bottom:.55rem;
                  display:block; }
.add-rule-form  { display:flex; gap:.5rem; align-items:flex-end; flex-wrap:wrap; }
.arf-field      { display:flex; flex-direction:column; gap:.3rem; flex:1; min-width:160px; }
.arf-field label { font-size:.68rem; font-weight:600; color:var(--gris-texto); }
.arf-sel        { font-size:.78rem; padding:.42rem .55rem; border-radius:7px;
                  border:1px solid var(--brd); background:var(--bg-input); color:var(--tx);
                  width:100%; outline:none; }
.arf-sel:focus  { border-color:var(--ac); box-shadow:0 0 0 3px rgba(29,78,216,.09); }
.btn-add        { font-size:.78rem; font-weight:600; padding:.42rem .9rem; border-radius:7px;
                  background:var(--ac); color:#fff; border:none; cursor:pointer;
                  white-space:nowrap; align-self:flex-end; transition:.14s; flex-shrink:0; }
.btn-add:hover  { background:var(--ac-h, #1d4ed8); }
.btn-add:disabled { opacity:.5; cursor:not-allowed; }

/* Descripción de tipo de asignación */
.res-hint       { font-size:.68rem; color:var(--gris-texto); margin-top:.35rem;
                  padding:.35rem .55rem; background:var(--bg-surface); border-radius:5px;
                  border-left:2px solid var(--brd); display:none; }
.res-hint.visible { display:block; }

/* ── Divider ───────────────────────────────────────────────────────── */
.nr-div         { display:flex; align-items:center; gap:.5rem; font-size:.65rem; font-weight:700;
                  text-transform:uppercase; letter-spacing:.09em; color:var(--gris-texto); }
.nr-div::before,.nr-div::after { content:''; flex:1; height:1px; background:var(--brd); }

/* ── Toggle evento activo ──────────────────────────────────────────── */
.ev-toggle-row  { display:flex; align-items:center; gap:.75rem; }
.ev-toggle-lbl  { flex:1; font-size:.82rem; color:var(--tx); }
.ev-toggle-lbl span { color:var(--gris-texto); font-size:.75rem; }
.sw             { position:relative; display:inline-block; width:38px; height:21px; flex-shrink:0; }
.sw input       { display:none; }
.sw-t           { position:absolute; inset:0; border-radius:99px; background:var(--brd);
                  cursor:pointer; transition:.18s; }
.sw-t::before   { content:''; position:absolute; width:15px; height:15px; left:3px; top:3px;
                  background:var(--bg-surface); border-radius:50%; transition:.18s;
                  box-shadow:0 1px 3px rgba(0,0,0,.18); }
.sw input:checked + .sw-t           { background:#22c55e; }
.sw input:checked + .sw-t::before   { transform:translateX(17px); }

/* ── Toast ─────────────────────────────────────────────────────────── */
#nr-toast       { position:fixed; bottom:1.5rem; right:1.5rem; z-index:9999;
                  display:flex; flex-direction:column; gap:.4rem; pointer-events:none; }
.t-item         { padding:.55rem .9rem; border-radius:8px; font-size:.77rem; font-weight:600;
                  box-shadow:0 4px 14px rgba(0,0,0,.13); pointer-events:auto;
                  animation:tUp .22s ease; }
.t-item.ok      { background:var(--s-ok-bg); color:var(--s-ok-tx); border:1px solid #86efac; }
.t-item.err     { background:var(--s-err-bg); color:var(--s-err-tx); border:1px solid #fca5a5; }
@keyframes tUp  { from{opacity:0;transform:translateY(6px)} to{opacity:1;transform:none} }

@media(max-width:640px) {
    .nr-meta    { gap:.3rem; }
    .rule-ctrl  { gap:.2rem; }
    .add-rule-form { flex-direction:column; }
}
</style>

<div class="nr-wrap">

  <!-- Cabecera -->
  <div class="nr-hd">
    <div>
      <h1>Reglas de Notificación</h1>
      <p>Configura por rol quién recibe cada evento y cómo se resuelve el destinatario.</p>
    </div>
    <div class="nr-stats">
      <div class="nr-stat">
        <span class="nr-stat-n"><?= $total_events ?></span>
        <span class="nr-stat-l">Eventos</span>
      </div>
      <div class="nr-stat">
        <span class="nr-stat-n"><?= $total_rules ?></span>
        <span class="nr-stat-l">Reglas</span>
      </div>
    </div>
  </div>

  <!-- Filtros de módulo -->
  <div class="nr-mf">
    <button class="mf-btn active" data-mod="">Todos</button>
    <?php foreach (array_keys($grouped) as $mod): ?>
    <button class="mf-btn" data-mod="<?= htmlspecialchars($mod) ?>">
      <?= htmlspecialchars(MOD_LABELS[$mod] ?? ucfirst($mod)) ?>
    </button>
    <?php endforeach; ?>
  </div>

  <!-- Acordeón por módulo -->
  <?php foreach ($grouped as $mod => $events_in_mod): ?>
  <div class="nr-section" data-mod="<?= htmlspecialchars($mod) ?>">
    <div class="nr-sec-lbl"><?= htmlspecialchars(MOD_LABELS[$mod] ?? ucfirst($mod)) ?></div>

    <?php foreach ($events_in_mod as $code => $ev):
      $rules      = $ev['rules'];
      $active_r   = array_filter($rules, fn($r) => $r['on']);
      $has_ii     = (bool)array_filter($active_r, fn($r) => $r['ii']);
      $has_pp     = (bool)array_filter($active_r, fn($r) => $r['pp']);
      $min_prio   = $rules ? min(array_column($rules, 'prio')) : 2;
      $show_rules = array_slice($rules, 0, 2);
      $more       = max(0, count($rules) - 2);
    ?>
    <div class="nr-card <?= $ev['activo'] ? '' : 'ev-off' ?>"
         id="card-<?= htmlspecialchars($code) ?>"
         data-code="<?= htmlspecialchars($code) ?>">

      <!-- Cabecera resumen -->
      <div class="nr-head" onclick="NR.toggleCard(this.closest('.nr-card'))">
        <svg class="nr-chev" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 18l6-6-6-6"/>
        </svg>
        <div class="nr-head-name">
          <strong><?= htmlspecialchars($ev['name']) ?></strong>
          <code><?= htmlspecialchars($code) ?></code>
        </div>
        <div class="nr-meta">
          <!-- Chips resumen de reglas -->
          <?php foreach ($show_rules as $r):
            $rLbl = roleLabel($r['role']);
            $rCls = roleCls($r['role']);
          ?>
          <span class="rs-chip <?= $rCls ?>"><?= htmlspecialchars(ini2($rLbl)) ?> <span style="font-weight:400;opacity:.75"><?= htmlspecialchars($rLbl) ?></span></span>
          <?php endforeach; ?>
          <?php if ($more > 0): ?>
          <span class="rs-chip rol-dl">+<?= $more ?></span>
          <?php endif; ?>
          <!-- Canales activos -->
          <?php if ($has_ii): ?><span class="ch-ii">Interna</span><?php endif; ?>
          <?php if ($has_pp): ?><span class="ch-pp">Push</span><?php endif; ?>
          <!-- Prioridad mínima -->
          <?php if ($rules): ?>
          <span class="pb pb-<?= $min_prio ?>"><?= PRIO[$min_prio] ?? $min_prio ?></span>
          <?php endif; ?>
          <!-- Estado evento -->
          <span class="ev-st <?= $ev['activo'] ? 'es-on' : 'es-off' ?>">
            <?= $ev['activo'] ? 'Activo' : 'Inactivo' ?>
          </span>
        </div>
      </div>

      <!-- Cuerpo expandible -->
      <div class="nr-body">
        <div class="nr-body-inner">
          <div class="nr-body-pad">

            <!-- Lista de reglas -->
            <div>
              <div class="rules-hd" style="margin-bottom:.5rem">Reglas configuradas</div>
              <div id="rules-<?= htmlspecialchars($code) ?>">
                <?php if (empty($rules)): ?>
                <div class="rules-empty" id="empty-<?= htmlspecialchars($code) ?>">
                  Sin reglas configuradas — añade una regla abajo.
                </div>
                <?php else: ?>
                <?php foreach ($rules as $r): ?>
                <?= ruleRowHtml($r, $code) ?>
                <?php endforeach; ?>
                <?php endif; ?>
              </div>
            </div>

            <!-- Formulario para agregar regla -->
            <div class="add-rule-wrap">
              <span class="add-rule-lbl">Añadir regla</span>
              <div class="add-rule-form">
                <div class="arf-field">
                  <label>Rol receptor</label>
                  <select class="arf-sel arf-role" data-event="<?= htmlspecialchars($code) ?>">
                    <option value="">Seleccionar rol…</option>
                    <?php foreach (ROLES as $rk => $rv): ?>
                    <option value="<?= htmlspecialchars($rk) ?>"><?= htmlspecialchars($rv['label']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="arf-field">
                  <label>Tipo de asignación</label>
                  <select class="arf-sel arf-res" data-event="<?= htmlspecialchars($code) ?>"
                          onchange="NR.onResChange(this)">
                    <?php foreach (RESOLUTIONS as $tk => $tv): ?>
                    <option value="<?= htmlspecialchars($tk) ?>"><?= htmlspecialchars($tv['label']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <button class="btn-add" onclick="NR.addRule(this)"
                        data-event="<?= htmlspecialchars($code) ?>">+ Agregar</button>
              </div>
              <!-- Descripción del tipo seleccionado -->
              <?php foreach (RESOLUTIONS as $tk => $tv): ?>
              <div class="res-hint" data-hint="<?= htmlspecialchars($tk) ?>-<?= htmlspecialchars($code) ?>"
                   <?= $tk === 'ROLE' ? 'style="display:block"' : '' ?>>
                <?= htmlspecialchars($tv['sub']) ?>
              </div>
              <?php endforeach; ?>
            </div>

            <!-- Estado del evento -->
            <div class="nr-div">Estado del evento</div>
            <div class="ev-toggle-row">
              <div class="ev-toggle-lbl">
                Evento activo
                <span>— cuando está inactivo, ninguna regla de este evento envía notificaciones.</span>
              </div>
              <label class="sw">
                <input type="checkbox" <?= $ev['activo'] ? 'checked' : '' ?>
                       data-code="<?= htmlspecialchars($code) ?>"
                       onchange="NR.toggleEvento(this)">
                <span class="sw-t"></span>
              </label>
            </div>

          </div>
        </div>
      </div>
    </div><!-- /nr-card -->
    <?php endforeach; ?>
  </div><!-- /nr-section -->
  <?php endforeach; ?>

</div><!-- /nr-wrap -->
<div id="nr-toast"></div>

<?php
// Función PHP que genera el HTML de una fila de regla (usada en render y en API inline)
function ruleRowHtml(array $r, string $code): string {
    $rLbl   = roleLabel($r['role']);
    $rCls   = roleCls($r['role']);
    $reSvg  = resIcon($r['type']);
    $reLbl  = resLabel($r['type']);
    $reCls  = resCls($r['type']);
    $prio   = $r['prio'];
    $pMap   = PRIO;
    $prioOpts = '';
    foreach ($pMap as $pv => $pl) {
        $sel      = $prio === $pv ? 'selected' : '';
        $prioOpts .= "<option value=\"{$pv}\" {$sel}>{$pl}</option>";
    }
    $disRow = $r['on'] ? '' : 'is-disabled';
    $iiOn   = $r['ii'] ? 1 : 0;
    $ppOn   = $r['pp'] ? 1 : 0;
    $enOn   = $r['on'] ? 1 : 0;
    $enLbl  = $r['on'] ? 'Activa' : 'Off';
    $rId    = $r['id'];
    $codeH  = htmlspecialchars($code);

    ob_start(); ?>
    <div class="rule-row <?= $disRow ?>" id="row-<?= $rId ?>" data-rule-id="<?= $rId ?>">
      <div class="rule-assign">
        <span class="role-badge <?= $rCls ?>">
          <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
          </svg>
          <?= htmlspecialchars($rLbl) ?>
        </span>
        <span class="arr-icon">→</span>
        <span class="res-badge <?= $reCls ?>">
          <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><?= $reSvg ?></svg>
          <?= htmlspecialchars($reLbl) ?>
        </span>
      </div>
      <div class="rule-ctrl">
        <button class="ctog ctog-ii" data-on="<?= $iiOn ?>" data-rule="<?= $rId ?>"
                data-field="via_interna" onclick="NR.toggleCtrl(this)" title="Notificación interna">Int</button>
        <button class="ctog ctog-pp" data-on="<?= $ppOn ?>" data-rule="<?= $rId ?>"
                data-field="via_push" onclick="NR.toggleCtrl(this)" title="Push web / móvil">Push</button>
        <select class="psel" data-rule="<?= $rId ?>" onchange="NR.updatePriority(this)"><?= $prioOpts ?></select>
        <button class="ctog-en" data-on="<?= $enOn ?>" data-rule="<?= $rId ?>"
                data-field="enabled" onclick="NR.toggleEnabled(this)"><?= $enLbl ?></button>
      </div>
      <button class="rule-del" data-rule="<?= $rId ?>" data-event="<?= $codeH ?>"
              onclick="NR.removeRule(this)" title="Eliminar regla">×</button>
    </div>
    <?php return ob_get_clean();
}
?>

<script>
const NR = (function () {
    'use strict';

    const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const timers = {};

    // ── Comunicación ────────────────────────────────────────────────────
    async function post(body) {
        const fd = new FormData();
        Object.entries(body).forEach(([k, v]) => v != null && fd.append(k, v));
        fd.append('_csrf', CSRF);
        const r = await fetch(location.pathname, {
            method: 'POST', body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        return r.json();
    }

    function toast(msg, type = 'ok') {
        const el = document.createElement('div');
        el.className = 't-item ' + type;
        el.textContent = msg;
        document.getElementById('nr-toast').appendChild(el);
        setTimeout(() => el.remove(), 3000);
    }

    // ── Acordeón ─────────────────────────────────────────────────────────
    function toggleCard(card) {
        card.classList.toggle('is-open');
    }

    // ── Cambio de tipo de asignación → descripción ────────────────────────
    function onResChange(sel) {
        const code = sel.dataset.event;
        const tipo = sel.value;
        // Ocultar todos los hints de esta tarjeta
        document.querySelectorAll(`[data-hint$="-${code}"]`)
            .forEach(h => h.style.display = 'none');
        // Mostrar el del tipo seleccionado
        const hint = document.querySelector(`[data-hint="${tipo}-${code}"]`);
        if (hint) hint.style.display = 'block';
    }

    // ── Agregar regla ─────────────────────────────────────────────────────
    async function addRule(btn) {
        const code = btn.dataset.event;
        const wrap = btn.closest('.add-rule-wrap');
        const role = wrap.querySelector('.arf-role').value;
        const res  = wrap.querySelector('.arf-res').value;

        if (!role) { toast('Selecciona un rol.', 'err'); return; }
        if (!res)  { toast('Selecciona un tipo.', 'err'); return; }

        btn.disabled = true;
        btn.textContent = '…';

        try {
            const data = await post({ accion: 'agregar', event_code: code, role, resolution: res });
            if (!data.ok) {
                toast(data.msg || 'Error al agregar.', 'err');
                btn.disabled = false; btn.textContent = '+ Agregar'; return;
            }
            // Quitar mensaje vacío si existe
            document.getElementById('empty-' + code)?.remove();

            // Construir y agregar la fila al DOM vía AJAX de renderizado PHP-like
            // (usamos un fetch al mismo endpoint con GET param para obtener HTML)
            const row = buildRowDOM(data.rule_id, role, res);
            document.getElementById('rules-' + code)?.appendChild(row);

            // Reset form
            wrap.querySelector('.arf-role').value = '';
            refreshSummary(code);
            toast('Regla agregada.');
        } catch (e) {
            toast('Error de conexión.', 'err');
        }
        btn.disabled = false; btn.textContent = '+ Agregar';
    }

    // Construir fila de regla directamente en JS (reflejo del PHP ruleRowHtml)
    function buildRowDOM(ruleId, role, resType) {
        const ROLES = {
            'gerente_general':     { label: 'Gerente General',        cls: 'rol-gm' },
            'gerente_operaciones': { label: 'Gerente de Operaciones',  cls: 'rol-go' },
            'ejecutivo_ventas':    { label: 'Ejecutivo de Ventas',     cls: 'rol-ev' },
            'gestor_logistico':    { label: 'Gestor Logístico',        cls: 'rol-gl' },
            'delivery':            { label: 'Delivery',                cls: 'rol-dl' },
        };
        const RES = {
            'ROLE':              { label: 'Todos los usuarios del rol',       cls: 'res-all' },
            'COMPANY_EXECUTIVE': { label: 'Ejecutivo asignado a la empresa',  cls: 'res-cmp' },
            'PROPOSAL_OWNER':    { label: 'Ejecutivo de la propuesta',        cls: 'res-own' },
        };
        const rData  = ROLES[role] || { label: role, cls: 'rol-dl' };
        const reData = RES[resType] || { label: resType, cls: '' };

        const div = document.createElement('div');
        div.className = 'rule-row';
        div.id        = 'row-' + ruleId;
        div.dataset.ruleId = ruleId;
        div.innerHTML = `
          <div class="rule-assign">
            <span class="role-badge ${rescape(rData.cls)}">
              <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round"
                  d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
              </svg>
              ${rescape(rData.label)}
            </span>
            <span class="arr-icon">→</span>
            <span class="res-badge ${rescape(reData.cls)}">${rescape(reData.label)}</span>
          </div>
          <div class="rule-ctrl">
            <button class="ctog ctog-ii" data-on="1" data-rule="${ruleId}"
                    data-field="via_interna" onclick="NR.toggleCtrl(this)" title="Notificación interna">Int</button>
            <button class="ctog ctog-pp" data-on="0" data-rule="${ruleId}"
                    data-field="via_push" onclick="NR.toggleCtrl(this)" title="Push web / móvil">Push</button>
            <select class="psel" data-rule="${ruleId}" onchange="NR.updatePriority(this)">
              <option value="1">Alta</option>
              <option value="2" selected>Normal</option>
              <option value="3">Baja</option>
              <option value="4">Muy baja</option>
              <option value="5">Mínima</option>
            </select>
            <button class="ctog-en" data-on="1" data-rule="${ruleId}"
                    data-field="enabled" onclick="NR.toggleEnabled(this)">Activa</button>
          </div>
          <button class="rule-del" data-rule="${ruleId}" data-event=""
                  onclick="NR.removeRule(this)" title="Eliminar regla">×</button>
        `;
        // Seteamos data-event en el botón eliminar
        div.querySelector('.rule-del').dataset.event =
            document.getElementById('row-' + ruleId)?.closest('.nr-card')?.dataset.code || '';
        return div;
    }

    function rescape(s) {
        return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    // ── Eliminar regla ─────────────────────────────────────────────────────
    async function removeRule(btn) {
        const _ok = await ggiConfirm('Eliminar regla', '¿Eliminar esta regla? Esta acción no se puede deshacer.', { type: 'danger', confirmText: 'Eliminar' });
        if (!_ok) return;
        const ruleId = btn.dataset.rule;
        const code   = btn.dataset.event || btn.closest('.nr-card')?.dataset.code;
        const row    = document.getElementById('row-' + ruleId);
        row?.classList.add('is-saving');
        try {
            const data = await post({ accion: 'eliminar', rule_id: ruleId });
            if (!data.ok) { toast(data.msg || 'Error.', 'err'); row?.classList.remove('is-saving'); return; }
            row?.remove();
            const rulesEl = document.getElementById('rules-' + code);
            if (rulesEl && !rulesEl.querySelector('.rule-row')) {
                rulesEl.innerHTML = `<div class="rules-empty" id="empty-${code}">Sin reglas configuradas — añade una regla abajo.</div>`;
            }
            refreshSummary(code);
            toast('Regla eliminada.');
        } catch (e) {
            toast('Error de conexión.', 'err');
            row?.classList.remove('is-saving');
        }
    }

    // ── Controles de canal ─────────────────────────────────────────────────
    async function toggleCtrl(btn) {
        const ruleId = btn.dataset.rule;
        const field  = btn.dataset.field;
        const newVal = btn.dataset.on === '1' ? '0' : '1';
        const row    = document.getElementById('row-' + ruleId);
        row?.classList.add('is-saving');
        try {
            const data = await post({ accion: 'actualizar', rule_id: ruleId, campo: field, valor: newVal });
            if (!data.ok) { toast(data.msg || 'Error.', 'err'); } else {
                btn.dataset.on = newVal;
                refreshSummary(btn.closest('.nr-card')?.dataset.code);
            }
        } catch (e) { toast('Error de conexión.', 'err'); }
        row?.classList.remove('is-saving');
    }

    async function toggleEnabled(btn) {
        const ruleId = btn.dataset.rule;
        const newVal = btn.dataset.on === '1' ? '0' : '1';
        const row    = document.getElementById('row-' + ruleId);
        row?.classList.add('is-saving');
        try {
            const data = await post({ accion: 'actualizar', rule_id: ruleId, campo: 'enabled', valor: newVal });
            if (!data.ok) { toast(data.msg || 'Error.', 'err'); } else {
                btn.dataset.on = newVal;
                btn.textContent = newVal === '1' ? 'Activa' : 'Off';
                row?.classList.toggle('is-disabled', newVal === '0');
                refreshSummary(btn.closest('.nr-card')?.dataset.code);
            }
        } catch (e) { toast('Error de conexión.', 'err'); }
        row?.classList.remove('is-saving');
    }

    function updatePriority(sel) {
        const ruleId = sel.dataset.rule;
        clearTimeout(timers[ruleId]);
        timers[ruleId] = setTimeout(async () => {
            const row = document.getElementById('row-' + ruleId);
            row?.classList.add('is-saving');
            try {
                const data = await post({ accion: 'actualizar', rule_id: ruleId, campo: 'priority', valor: sel.value });
                if (!data.ok) toast(data.msg || 'Error.', 'err');
                else refreshSummary(sel.closest('.nr-card')?.dataset.code);
            } catch (e) { toast('Error de conexión.', 'err'); }
            row?.classList.remove('is-saving');
        }, 500);
    }

    // ── Toggle evento activo ───────────────────────────────────────────────
    async function toggleEvento(inp) {
        const code = inp.dataset.code;
        const val  = inp.checked ? '1' : '0';
        try {
            const data = await post({ accion: 'toggle_evento', event_code: code, valor: val });
            if (!data.ok) { toast(data.msg || 'Error.', 'err'); inp.checked = !inp.checked; return; }
            const card = document.getElementById('card-' + code);
            card?.classList.toggle('ev-off', val === '0');
            const pill = card?.querySelector('.ev-st');
            if (pill) { pill.className = 'ev-st ' + (val === '1' ? 'es-on' : 'es-off');
                        pill.textContent = val === '1' ? 'Activo' : 'Inactivo'; }
            toast(val === '1' ? 'Evento activado.' : 'Evento desactivado.');
        } catch (e) { toast('Error de conexión.', 'err'); inp.checked = !inp.checked; }
    }

    // ── Recalcular resumen en cabecera ─────────────────────────────────────
    function refreshSummary(code) {
        if (!code) return;
        const card  = document.getElementById('card-' + code);
        if (!card) return;
        const rows  = [...card.querySelectorAll('.rule-row')];
        const actR  = rows.filter(r => r.querySelector('.ctog-en[data-on="1"]'));
        const hasIi = actR.some(r => r.querySelector('.ctog-ii[data-on="1"]'));
        const hasPp = actR.some(r => r.querySelector('.ctog-pp[data-on="1"]'));
        const prios = rows.map(r => parseInt(r.querySelector('.psel')?.value || '2'));
        const minP  = prios.length ? Math.min(...prios) : 2;
        const PM    = { 1:'Alta',2:'Normal',3:'Baja',4:'Muy baja',5:'Mínima' };
        const PC    = { 1:'pb-1',2:'pb-2',3:'pb-3',4:'pb-4',5:'pb-5' };

        // Chips resumen
        const RMAP = {
            gerente_general:'rol-gm', gerente_operaciones:'rol-go',
            ejecutivo_ventas:'rol-ev', gestor_logistico:'rol-gl', delivery:'rol-dl'
        };
        const RLBL = {
            gerente_general:'Gerente General', gerente_operaciones:'Gerente Ops.',
            ejecutivo_ventas:'Ejec. Ventas', gestor_logistico:'Gest. Log.', delivery:'Delivery'
        };
        function ini(s) {
            const w = s.trim().split(/\s+/);
            return w.length >= 2 ? (w[0][0]+w[1][0]).toUpperCase() : s.slice(0,2).toUpperCase();
        }

        const shown = rows.slice(0, 2);
        const more  = Math.max(0, rows.length - 2);

        // Leer el rol de cada fila (del badge)
        const chipsHtml = shown.map(row => {
            const badge = row.querySelector('.role-badge');
            if (!badge) return '';
            const cls = [...badge.classList].find(c => c.startsWith('rol-')) || 'rol-dl';
            const lbl = badge.textContent.trim();
            return `<span class="rs-chip ${cls}">${ini(lbl)} <span style="font-weight:400;opacity:.75">${rescape(lbl)}</span></span>`;
        }).join('') + (more > 0 ? `<span class="rs-chip rol-dl">+${more}</span>` : '');

        const iiHtml    = hasIi ? '<span class="ch-ii">Interna</span>' : '';
        const ppHtml    = hasPp ? '<span class="ch-pp">Push</span>' : '';
        const prioHtml  = rows.length ? `<span class="pb ${PC[minP]}">${PM[minP]}</span>` : '';
        const statusPill = card.querySelector('.ev-st')?.outerHTML || '';

        card.querySelector('.nr-meta').innerHTML = chipsHtml + iiHtml + ppHtml + prioHtml + statusPill;
    }

    // ── Filtros de módulo ──────────────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.mf-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                document.querySelectorAll('.mf-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                const mod = this.dataset.mod;
                document.querySelectorAll('.nr-section').forEach(s => {
                    s.style.display = (!mod || s.dataset.mod === mod) ? '' : 'none';
                });
            });
        });
    });

    return { toggleCard, onResChange, addRule, removeRule, toggleCtrl, toggleEnabled, updatePriority, toggleEvento };
})();
</script>

<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
