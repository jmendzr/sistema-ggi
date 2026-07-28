<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Database.php';

$token   = trim($_GET['token'] ?? '');
$cliente = null;
$error   = '';

if ($token) {
    try {
        $pdo  = Database::get();
        $stmt = $pdo->prepare("SELECT * FROM clientes WHERE portal_token=? AND portal_activo=1 AND estado!='inactivo' AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([$token]);
        $cliente = $stmt->fetch();
        if (!$cliente) $error = 'El enlace no es válido o ha sido desactivado.';
    } catch (Exception $e) { $error = 'Error al conectar. Intenta más tarde.'; }
}

if ($cliente) {
    $pdo = Database::get();

    $certs_raw = $pdo->prepare("
        SELECT c.*, n.codigo AS norma, n.nombre AS norma_nombre, cc.nombre AS casa
        FROM certificados c
        JOIN normas_iso n  ON n.id  = c.norma_iso_id
        JOIN casas_certificadoras cc ON cc.id = c.casa_certificadora_id
        WHERE c.cliente_id = ?
        ORDER BY FIELD(c.estado,'vigente','por_vencer','en_proceso','suspendido','vencido')
    ");
    $certs_raw->execute([$cliente['id']]);
    $certificados = $certs_raw->fetchAll();

    $auds_por_cert = [];
    $auds_raw = $pdo->prepare("
        SELECT a.*, c.id AS cert_id FROM auditorias a
        JOIN certificados c ON c.id = a.certificado_id
        WHERE c.cliente_id = ?
        ORDER BY a.tipo ASC, a.fecha_programada ASC
    ");
    $auds_raw->execute([$cliente['id']]);
    foreach ($auds_raw->fetchAll() as $a) $auds_por_cert[$a['cert_id']][$a['tipo']] = $a;

    $envs_raw = $pdo->prepare("
        SELECT e.*, ag.nombre AS agencia, es.nombre AS estado_nombre, es.color_hex,
               GROUP_CONCAT(DISTINCT n.codigo ORDER BY n.codigo SEPARATOR ' · ') AS normas_enviadas
        FROM envios e
        LEFT JOIN agencias_envio ag ON ag.id = e.agencia_envio_id
        JOIN estados_envio  es ON es.id = e.estado_envio_id
        LEFT JOIN envios_certificados ec ON ec.envio_id = e.id
        LEFT JOIN certificados c2 ON c2.id = ec.certificado_id
        LEFT JOIN normas_iso n ON n.id = c2.norma_iso_id
        WHERE e.cliente_id = ? AND e.deleted_at IS NULL
        GROUP BY e.id ORDER BY e.created_at DESC LIMIT 10
    ");
    $envs_raw->execute([$cliente['id']]);
    $envios = $envs_raw->fetchAll();

    // Estado inicial (Por preparar) para marcar envíos editables desde el portal
    $estado_inicial_id = (int)($pdo->query(
        "SELECT id FROM estados_envio WHERE activo=1 AND nombre='Por preparar' LIMIT 1"
    )->fetchColumn() ?: 0);

    // Agencias activas para el formulario del portal (excluir Presencial)
    $agencias_portal = $pdo->query("SELECT id, nombre FROM agencias_envio WHERE activo=1 AND LOWER(nombre) NOT LIKE '%presencial%' ORDER BY nombre")->fetchAll();

    // Id de la agencia "a domicilio" (Lima): el portal muestra el mapa para ella.
    $agencia_domicilio_id = (int) ($pdo->query("SELECT id FROM agencias_envio WHERE es_domicilio=1 AND activo=1 LIMIT 1")->fetchColumn() ?: 0);

    $tiene_archivos = (bool) array_filter($certificados, fn($c) =>
        !empty($c['certificado_archivo']) || !empty($c['certificado_archivo_en'])
    );

    // Cert details per envío (one query, all envíos)
    $env_certs_map = [];
    if (!empty($envios)) {
        $env_ids = array_column($envios, 'id');
        $ph = implode(',', array_fill(0, count($env_ids), '?'));
        $ec_q = $pdo->prepare("
            SELECT ec.envio_id, n.codigo, n.nombre AS norma_nombre
            FROM envios_certificados ec
            JOIN certificados c ON c.id = ec.certificado_id
            JOIN normas_iso n ON n.id = c.norma_iso_id
            WHERE ec.envio_id IN ($ph)
            ORDER BY n.codigo
        ");
        $ec_q->execute($env_ids);
        foreach ($ec_q->fetchAll() as $row) {
            $env_certs_map[$row['envio_id']][] = [
                'codigo' => $row['codigo'],
                'nombre' => $row['norma_nombre'],
            ];
        }
    }

    // Build cert data for JS panel
    $certs_js = [];
    foreach ($certificados as $cert) {
        $s1 = $auds_por_cert[$cert['id']]['seguimiento_1'] ?? null;
        $s2 = $auds_por_cert[$cert['id']]['seguimiento_2'] ?? null;
        $fv = function($d) {
            if (!$d) return null;
            $meses = ['','Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
            $ts = strtotime($d); return date('d', $ts).' '.$meses[(int)date('n',$ts)].' '.date('Y',$ts);
        };
        $certs_js[$cert['id']] = [
            'norma'      => $cert['norma'],
            'nombre'     => $cert['norma_nombre'],
            'casa'       => $cert['casa'],
            'num'        => $cert['numero_certificado'] ?? '',
            'estado'     => $cert['estado'],
            'emision'    => $fv($cert['fecha_emision']),
            'vencimiento'=> $fv($cert['fecha_vencimiento']),
            'archivo_es' => $cert['certificado_archivo'] ?? '',
            'archivo_en' => $cert['certificado_archivo_en'] ?? '',
            'seg1' => $s1 ? [
                'fecha'      => $fv($s1['fecha_reprogramada'] ?: $s1['fecha_programada']),
                'reprog'     => !empty($s1['fecha_reprogramada']),
                'realizada'  => $fv($s1['fecha_realizada']),
                'resultado'  => $s1['resultado'],
            ] : null,
            'seg2' => $s2 ? [
                'fecha'      => $fv($s2['fecha_reprogramada'] ?: $s2['fecha_programada']),
                'reprog'     => !empty($s2['fecha_reprogramada']),
                'realizada'  => $fv($s2['fecha_realizada']),
                'resultado'  => $s2['resultado'],
            ] : null,
        ];
    }

    // Build envío data for JS panel
    $envios_js = [];
    foreach ($envios as $e) {
        $fields = [];
        if (!empty($e['sede_registro']))     $fields[] = ['label'=>'Sede de destino',        'val'=>$e['sede_registro'],'mono'=>false];
        if (!empty($e['tienda_destino']))    $fields[] = ['label'=>'Punto de recojo',        'val'=>$e['tienda_destino'],'mono'=>false];
        if (!empty($e['codigo_envio']))      $fields[] = ['label'=>'Código de seguimiento','val'=>$e['codigo_envio'],'mono'=>true];
        if (!empty($e['clave_envio']))       $fields[] = ['label'=>'Clave de recojo',       'val'=>$e['clave_envio'],'mono'=>true];
        if (!empty($e['numero_orden']))      $fields[] = ['label'=>'N° de orden',            'val'=>$e['numero_orden'],'mono'=>true];
        if (!empty($e['codigo_recojo']))     $fields[] = ['label'=>'Código de recojo',       'val'=>$e['codigo_recojo'],'mono'=>true];
        if (!empty($e['fecha_envio']))       $fields[] = ['label'=>'Fecha de envío',         'val'=>fdate($e['fecha_envio']),'mono'=>false];
        if (!empty($e['destinatario']))      $fields[] = ['label'=>'Destinatario',           'val'=>$e['destinatario'],'mono'=>false];
        $certs_det = $env_certs_map[$e['id']] ?? [];
        $normas_str = $e['normas_enviadas'] ?: '';
        $ag_nombre    = $e['agencia'] ?? '';
        $es_editable  = ((int)$e['estado_envio_id'] === $estado_inicial_id);
        $envios_js[$e['id']] = [
            'agencia'         => $ag_nombre,
            'agencia_id'      => (int)($e['agencia_envio_id'] ?? 0),
            'estado'          => $e['estado_nombre'],
            'estado_id'       => (int)$e['estado_envio_id'],
            'color'           => $e['color_hex'] ?: '#9CA3AF',
            'normas_str'      => $normas_str,
            'n_certs'         => count($certs_det),
            'certs'           => $certs_det,
            'fecha_envio'     => fdate($e['fecha_envio']),
            'entregado'       => !empty($e['fecha_recibido']),
            'fecha_recibido'  => !empty($e['fecha_recibido']) ? fdate($e['fecha_recibido']) : null,
            'fields'          => $fields,
            'notas'           => trim($e['notas_entrega'] ?? '') ?: trim($e['observaciones'] ?? '') ?: '',
            'is_olva'         => stripos($ag_nombre, 'olva')   !== false,
            'is_shalom'       => stripos($ag_nombre, 'shalom') !== false,
            'has_tracking'    => (stripos($ag_nombre, 'olva') !== false && !empty($e['codigo_envio']))
                              || (stripos($ag_nombre, 'shalom') !== false && !empty($e['numero_orden']) && !empty($e['codigo_envio'])),
            'codigo_envio'    => $e['codigo_envio'] ?? '',
            'editable'        => $es_editable,
            'sede_registro'   => $e['sede_registro'] ?? '',
            'tienda_destino'  => $e['tienda_destino'] ?? '',
            'direccion'       => $e['direccion_destino'] ?? '',
            'destinatario'    => $e['destinatario'] ?? '',
            'destinatario_dni'=> $e['destinatario_dni'] ?? '',
            'lat'             => $e['lat'] !== null ? (float) $e['lat'] : null,
            'lng'             => $e['lng'] !== null ? (float) $e['lng'] : null,
            'referencia'      => $e['referencia_direccion'] ?? '',
        ];
    }
}

$st_cfg = [
    'vigente'    => ['label'=>'Vigente',    'dot'=>'#059669','bar'=>'#10B981'],
    'por_vencer' => ['label'=>'Por vencer', 'dot'=>'#D97706','bar'=>'#F59E0B'],
    'vencido'    => ['label'=>'Vencido',    'dot'=>'#DC2626','bar'=>'#EF4444'],
    'en_proceso' => ['label'=>'En proceso', 'dot'=>'#2563EB','bar'=>'#3B82F6'],
    'suspendido' => ['label'=>'Suspendido', 'dot'=>'#9CA3AF','bar'=>'#D1D5DB'],
];
function fdate($d) {
    if (!$d) return null;
    $meses = ['','Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
    $ts = strtotime($d);
    return date('d', $ts) . ' ' . $meses[(int)date('n', $ts)] . ' ' . date('Y', $ts);
}
function fmon($d) {
    if (!$d) return null;
    $meses = ['','Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
    $ts = strtotime($d);
    return $meses[(int)date('n', $ts)] . ' ' . date('Y', $ts);
}
function initials($s) { return strtoupper(mb_substr(trim($s), 0, 2)); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>Portal · Group Global ISO</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/leaflet/leaflet.css?v=<?= @filemtime(__DIR__ . '/../assets/leaflet/leaflet.css') ?>">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth}

:root{
  --bg:#F5F7FA;
  --surface:#FFFFFF;
  --border:#E4E8EE;
  --border-soft:#EEF1F6;
  --txt:#0D0D0F;
  --sub:#52596A;
  --muted:#8E96A3;
  --brand:#1A3A8F;
  --r:14px;
  --r-sm:8px;
}

body{
  font-family:'Inter',-apple-system,system-ui,sans-serif;
  background:var(--bg);
  color:var(--txt);
  min-height:100vh;
  -webkit-font-smoothing:antialiased;
  -moz-osx-font-smoothing:grayscale;
  font-size:14px;
  line-height:1.5;
  -webkit-tap-highlight-color:transparent;
}
/* Elimina el delay de 300ms del tap en elementos interactivos */
button,a,.cert-card,.env-card,.cp-sede-opt,.trk-phase-head{touch-action:manipulation}

/* ── Nav ── */
.nav{
  background:#0A0A0B;
  height:50px;
  display:flex;align-items:center;justify-content:space-between;
  padding:0 28px;
  position:sticky;top:0;z-index:200;
}
.nav-logo{display:flex;align-items:center;gap:9px;text-decoration:none}
.nav-shield{
  width:26px;height:26px;border-radius:7px;
  background:rgba(255,255,255,.09);
  display:flex;align-items:center;justify-content:center;
}
.nav-word{font-size:13px;font-weight:600;color:rgba(255,255,255,.9);letter-spacing:-.01em}
.nav-lock{display:flex;align-items:center;gap:5px;font-size:11px;color:rgba(255,255,255,.28);letter-spacing:.01em}

/* ── Wrapper ── */
.wrap{max-width:820px;margin:0 auto;padding:52px 28px 96px}

/* ── Splash ── */
.splash{
  max-width:340px;margin:72px auto;
  background:var(--surface);border:1px solid var(--border);
  border-radius:18px;padding:44px 32px;text-align:center;
}
.splash-ico{
  width:48px;height:48px;border-radius:13px;
  display:flex;align-items:center;justify-content:center;
  margin:0 auto 18px;
}
.splash h2{font-size:18px;font-weight:700;color:var(--txt);margin-bottom:8px}
.splash p{font-size:13px;color:var(--sub);line-height:1.65}

/* ── Hero ── */
.hero{margin-bottom:48px}
.hero-kicker{
  font-size:11px;font-weight:600;color:var(--muted);
  text-transform:uppercase;letter-spacing:.9px;margin-bottom:10px;
  display:flex;align-items:center;gap:7px;
}
.hero-kicker-dot{width:5px;height:5px;border-radius:50%}
.hero-name{
  font-size:30px;font-weight:700;
  color:var(--txt);letter-spacing:-.6px;
  line-height:1.15;margin-bottom:6px;
}
.hero-desc{font-size:14px;color:var(--sub);margin-bottom:20px}
.hero-stats{display:flex;align-items:center;gap:20px;flex-wrap:wrap}
.hero-stat{
  display:flex;align-items:center;gap:6px;
  font-size:12.5px;color:var(--muted);
}
.hero-stat strong{font-weight:600;color:var(--sub)}

/* ── Section head ── */
.sec-head{
  display:flex;align-items:center;justify-content:space-between;
  margin-bottom:16px;
}
.sec-label{
  font-size:11px;font-weight:600;
  color:var(--muted);
  text-transform:uppercase;letter-spacing:.85px;
}
.btn-dl{
  display:inline-flex;align-items:center;gap:5px;
  padding:5px 12px;border-radius:7px;
  border:1px solid var(--border);
  background:var(--surface);
  font-size:12px;font-weight:500;color:var(--sub);
  text-decoration:none;transition:all .15s;cursor:pointer;
}
.btn-dl:hover{background:var(--brand);color:#fff;border-color:var(--brand)}

/* ── Cert grid ── */
.cert-grid{
  display:grid;
  grid-template-columns:repeat(2,1fr);
  gap:12px;
  margin-bottom:40px;
}

/* ── Cert card ── */
.cert-card{
  background:var(--surface);
  border:1px solid var(--border);
  border-radius:var(--r);
  padding:20px 20px 16px;
  cursor:pointer;
  transition:transform .18s ease,box-shadow .18s ease,border-color .18s ease;
  display:flex;flex-direction:column;
  position:relative;overflow:hidden;
  outline:none;
}
.cert-card::after{
  content:'';
  position:absolute;top:0;left:0;right:0;
  height:2.5px;
  background:var(--card-bar,transparent);
  border-radius:var(--r) var(--r) 0 0;
}
.cert-card:hover{
  transform:translateY(-3px);
  box-shadow:0 10px 32px rgba(13,13,15,.08);
  border-color:#D0D8E8;
}
.cert-card:focus-visible{box-shadow:0 0 0 3px rgba(26,58,143,.25)}
.cert-card:active{transform:translateY(-1px)}

.card-status{
  display:flex;align-items:center;gap:6px;
  margin-bottom:14px;
}
.s-dot{width:7px;height:7px;border-radius:50%;flex-shrink:0}
.s-text{font-size:12px;font-weight:500}

.card-code{
  font-size:21px;font-weight:700;
  color:var(--txt);letter-spacing:-.4px;
  line-height:1;margin-bottom:4px;
}
.card-name{
  font-size:12px;color:var(--sub);
  line-height:1.45;flex:1;margin-bottom:18px;
}

.card-foot{
  display:flex;align-items:flex-end;
  justify-content:space-between;gap:8px;
  margin-top:auto;
  padding-top:14px;
  border-top:1px solid var(--border-soft);
}
.card-expiry{font-size:11.5px;color:var(--muted)}
.card-expiry strong{display:block;font-size:13px;font-weight:600;color:var(--txt);margin-top:1px}
.card-cta{
  display:inline-flex;align-items:center;gap:4px;
  font-size:12px;font-weight:500;color:var(--brand);
  white-space:nowrap;
}
.card-cta svg{transition:transform .15s}
.cert-card:hover .card-cta svg{transform:translate(2px,-2px)}

/* ── Overlay + Panel ── */
.overlay{
  position:fixed;inset:0;
  background:rgba(10,10,15,.4);
  backdrop-filter:blur(3px);
  -webkit-backdrop-filter:blur(3px);
  z-index:300;
  opacity:0;pointer-events:none;
  transition:opacity .22s ease;
}
.overlay.open{opacity:1;pointer-events:all}

.panel{
  position:fixed;top:0;right:0;bottom:0;
  width:380px;max-width:100vw;
  background:#fff;
  z-index:301;
  transform:translateX(100%);
  transition:transform .28s cubic-bezier(.22,1,.36,1);
  display:flex;flex-direction:column;
  box-shadow:-4px 0 40px rgba(0,0,0,.1);
  overflow:hidden;
}
.panel.open{transform:translateX(0)}

.panel-bar{
  display:flex;align-items:center;justify-content:space-between;
  padding:14px 20px;
  border-bottom:1px solid var(--border-soft);
  flex-shrink:0;background:#fff;
}
.panel-bar-left{display:flex;align-items:center;gap:8px}
.panel-close{
  width:28px;height:28px;border-radius:7px;
  background:#F3F4F6;border:none;cursor:pointer;
  display:flex;align-items:center;justify-content:center;
  color:#6B7280;transition:background .12s;
}
.panel-close:hover{background:#E5E7EB;color:#374151}
.panel-close svg{display:block}

.panel-scroll{overflow-y:auto;flex:1;padding:22px 20px 32px}

.p-code{font-size:26px;font-weight:700;letter-spacing:-.5px;color:var(--txt);margin-bottom:3px}
.p-name{font-size:13.5px;font-weight:500;color:var(--sub);margin-bottom:3px}
.p-casa{font-size:12.5px;color:var(--muted);margin-bottom:24px}

.p-section{margin-bottom:22px}
.p-sec-title{
  font-size:10.5px;font-weight:600;color:var(--muted);
  text-transform:uppercase;letter-spacing:.75px;
  margin-bottom:8px;
}
.p-row{
  display:flex;align-items:baseline;justify-content:space-between;
  gap:12px;padding:7px 0;
  border-bottom:1px solid #F3F5F8;
}
.p-row:last-child{border-bottom:none}
.p-lbl{font-size:12.5px;color:var(--muted)}
.p-val{font-size:13px;font-weight:500;color:var(--txt);text-align:right}
.p-mono{
  font-family:'SF Mono','Fira Code',ui-monospace,monospace;
  font-size:11.5px;background:#F3F4F6;color:#374151;
  padding:2px 8px;border-radius:5px;border:1px solid var(--border);
}

/* Seguimientos en panel */
.seg-item{
  display:flex;align-items:flex-start;gap:10px;
  padding:9px 0;border-bottom:1px solid #F3F5F8;
}
.seg-item:last-child{border-bottom:none}
.seg-num{
  width:20px;height:20px;border-radius:50%;flex-shrink:0;
  background:#F3F4F6;border:1px solid #E5E7EB;
  font-size:10px;font-weight:700;color:var(--muted);
  display:flex;align-items:center;justify-content:center;
  margin-top:1px;
}
.seg-info{flex:1}
.seg-fecha{font-size:13px;font-weight:500;color:var(--txt)}
.seg-sub{font-size:11px;color:var(--muted);margin-top:1px}
.seg-chip{
  display:inline-flex;margin-top:5px;
  padding:2px 8px;border-radius:20px;
  font-size:11px;font-weight:600;
}
.seg-empty{font-size:12.5px;color:var(--muted);font-style:italic;padding:4px 0}

/* Downloads en panel */
.p-downloads{display:flex;flex-direction:column;gap:8px}
.dl-row{
  display:flex;align-items:center;gap:12px;
  padding:12px 14px;
  background:#F9FAFB;border:1px solid var(--border);
  border-radius:9px;text-decoration:none;
  transition:all .15s;
}
.dl-row:hover{background:var(--brand);border-color:var(--brand)}
.dl-row:hover .dl-lbl,.dl-row:hover .dl-sub{color:rgba(255,255,255,.92)}
.dl-row:hover .dl-ico{background:rgba(255,255,255,.14);color:#fff}
.dl-ico{
  width:30px;height:30px;border-radius:7px;
  background:#EEF2FA;color:var(--brand);
  display:flex;align-items:center;justify-content:center;
  flex-shrink:0;transition:all .15s;
}
.dl-txt{flex:1}
.dl-lbl{font-size:13px;font-weight:500;color:var(--txt)}
.dl-sub{font-size:11px;color:var(--muted);margin-top:1px}
.dl-arr{color:var(--muted);flex-shrink:0;transition:all .15s}
.dl-row:hover .dl-arr{color:rgba(255,255,255,.6)}

/* ── Envíos ── */
.env-list{display:flex;flex-direction:column;gap:10px}
.env-card{
  background:var(--surface);
  border:1px solid var(--border);
  border-radius:var(--r);
  padding:18px 20px 14px;
  cursor:pointer;outline:none;
  transition:transform .18s ease,box-shadow .18s ease,border-color .18s ease;
}
.env-card:hover{
  transform:translateY(-2px);
  box-shadow:0 8px 28px rgba(13,13,15,.07);
  border-color:#D0D8E8;
}
.env-card:focus-visible{box-shadow:0 0 0 3px rgba(26,58,143,.25)}
.env-card:active{transform:translateY(0)}

.env-top{
  display:flex;align-items:flex-start;
  justify-content:space-between;
  gap:10px;margin-bottom:8px;
}
.env-agency-name{font-size:14px;font-weight:600;color:var(--txt);line-height:1.3}
.env-norms{font-size:12px;color:var(--muted);margin-top:3px;line-height:1.4}

.env-st-wrap{display:flex;align-items:center;gap:5px;flex-shrink:0;padding-top:2px}
.env-st-text{font-size:12px;font-weight:500;white-space:nowrap}

.env-foot{
  display:flex;align-items:center;justify-content:space-between;
  gap:8px;margin-top:12px;
  padding-top:12px;border-top:1px solid var(--border-soft);
}
.env-date{font-size:12px;color:var(--muted)}
.env-delivered-badge{
  display:inline-flex;align-items:center;gap:4px;
  font-size:12px;font-weight:500;color:#059669;
}
.env-cta{
  display:inline-flex;align-items:center;gap:4px;
  font-size:12px;font-weight:500;color:var(--brand);
  white-space:nowrap;
}
.env-cta svg{transition:transform .15s}
.env-card:hover .env-cta svg{transform:translate(2px,-2px)}

/* ── Env panel extras ── */
.ep-agency-bar{display:flex;align-items:center;gap:11px;margin-bottom:20px}
.ep-av{
  width:38px;height:38px;flex-shrink:0;
  border-radius:9px;background:#EEF2FA;
  border:1px solid #D8E2F5;
  font-size:13px;font-weight:700;color:var(--brand);
  display:flex;align-items:center;justify-content:center;
  letter-spacing:-.3px;
}
.ep-agency-name{font-size:20px;font-weight:700;letter-spacing:-.4px;color:var(--txt)}
.ep-status{
  display:flex;align-items:center;gap:7px;
  margin-bottom:24px;
}
.ep-st-dot{width:9px;height:9px;border-radius:50%;flex-shrink:0}
.ep-st-label{font-size:13.5px;font-weight:500}

.ep-cert-list{display:flex;flex-direction:column;gap:0}
.ep-cert-item{
  display:flex;align-items:baseline;gap:10px;
  padding:8px 0;border-bottom:1px solid #F3F5F8;
}
.ep-cert-item:last-child{border-bottom:none}
.ep-cert-code{font-size:13px;font-weight:600;color:var(--txt);flex-shrink:0;min-width:72px}
.ep-cert-name{font-size:12.5px;color:var(--muted);line-height:1.3}

.ep-nota{
  padding:10px 13px;
  background:#F9FAFB;border:1px solid var(--border-soft);
  border-radius:8px;font-size:12.5px;color:var(--sub);line-height:1.6;
}
.ep-delivered{
  display:flex;align-items:center;gap:8px;
  padding:11px 14px;
  background:#F0FDF4;border:1px solid #BBF7D0;
  border-radius:9px;
  font-size:13px;font-weight:600;color:#059669;
}

/* ── Tracking stepper ── */
.trk-stepper{display:flex;flex-direction:column;gap:0;margin-bottom:4px}
.trk-phase{display:flex;flex-direction:column;gap:0}
.trk-phase-head{
  display:flex;align-items:center;gap:10px;
  padding:10px 0 10px 2px;cursor:pointer;
  user-select:none;
}
.trk-icon{
  width:28px;height:28px;border-radius:50%;
  display:flex;align-items:center;justify-content:center;
  flex-shrink:0;font-size:12px;font-weight:700;
  border:2px solid currentColor;
  transition:all .2s;
}
.trk-icon.done{background:#10B981;border-color:#10B981;color:#fff}
.trk-icon.active{background:#3B82F6;border-color:#3B82F6;color:#fff}
.trk-icon.pending{background:transparent;border-color:#D1D5DB;color:#9CA3AF}
.trk-phase-label{
  font-size:13px;font-weight:600;flex:1;
  transition:color .2s;
}
.trk-phase-label.done{color:#059669}
.trk-phase-label.active{color:#2563EB}
.trk-phase-label.pending{color:#9CA3AF}
.trk-toggle{
  font-size:11px;color:var(--brand);font-weight:500;white-space:nowrap;
  opacity:0;transition:opacity .15s;
}
.trk-phase-head:hover .trk-toggle{opacity:1}
.trk-phase.has-events .trk-toggle{opacity:1}
.trk-connector{
  width:2px;height:18px;margin-left:15px;
  border-radius:1px;background:#E5E7EB;
  transition:background .2s;
}
.trk-connector.done{background:#10B981}
.trk-events{
  display:none;margin-left:38px;margin-bottom:6px;
  border-left:2px solid #E5E7EB;padding-left:12px;
}
.trk-events.open{display:block}
.trk-ev{padding:5px 0;border-bottom:1px solid #F3F5F8}
.trk-ev:last-child{border-bottom:none}
.trk-ev-label{font-size:12.5px;font-weight:500;color:var(--txt)}
.trk-ev-meta{font-size:11px;color:var(--muted);margin-top:2px}
.trk-skeleton{
  display:flex;flex-direction:column;gap:14px;padding:8px 0;
}
.trk-sk-row{display:flex;align-items:center;gap:10px}
.trk-sk-circle{width:28px;height:28px;border-radius:50%;background:#F3F4F6;flex-shrink:0;animation:trk-pulse 1.4s ease-in-out infinite}
.trk-sk-line{height:12px;border-radius:6px;background:#F3F4F6;animation:trk-pulse 1.4s ease-in-out infinite}
@keyframes trk-pulse{0%,100%{opacity:1}50%{opacity:.4}}
@keyframes spin{to{transform:rotate(360deg)}}
.trk-msg{font-size:12.5px;color:var(--muted);padding:8px 2px}
.trk-st-upd{
  display:inline-flex;align-items:center;gap:5px;
  margin-top:10px;padding:6px 12px;border-radius:8px;
  background:#F0FDF4;border:1px solid #BBF7D0;
  font-size:12px;font-weight:500;color:#059669;
}

/* ── Empty ── */
.empty-state{
  text-align:center;padding:32px;
  color:var(--muted);font-size:13px;
}

/* ── Footer ── */
footer{
  margin-top:60px;padding-top:18px;
  border-top:1px solid var(--border-soft);
  text-align:center;font-size:11.5px;color:var(--muted);
}
footer a{color:var(--brand);text-decoration:none}
footer a:hover{text-decoration:underline}

/* ── Responsive ── */
@media(max-width:640px){
  /* Safe-areas (notch / home-indicator): requieren viewport-fit=cover */
  .wrap{padding:28px max(16px,env(safe-area-inset-right,0px)) calc(80px + env(safe-area-inset-bottom,0px)) max(16px,env(safe-area-inset-left,0px))}
  .nav{padding:0 max(16px,env(safe-area-inset-right,0px)) 0 max(16px,env(safe-area-inset-left,0px))}
  .hero-name{font-size:22px}
  .cert-grid{grid-template-columns:1fr;gap:10px}
  .cert-card{padding:18px 18px 14px}
  .card-code{font-size:19px}

  /* iOS hace auto-zoom al enfocar inputs con font-size <16px (salta el layout).
     Crítico en el formulario "Completar datos de envío". */
  .cp-input,.cp-select,.cp-sede-input{font-size:16px}

  /* Panel → bottom sheet */
  .panel{
    top:auto;left:0;right:0;bottom:0;
    width:100%;max-height:88svh;
    border-radius:20px 20px 0 0;
    transform:translateY(100%);
    box-shadow:0 -8px 40px rgba(0,0,0,.13);
  }
  .panel.open{transform:translateY(0)}
  /* padding inferior sobre el home-indicator (gesture bar) */
  .panel-scroll{padding:18px 18px calc(40px + env(safe-area-inset-bottom,0px))}
}
/* ── Landscape en móvil (pantalla baja): el bottom sheet a 88svh deja una
   franja útil mínima; usar altura completa sin radios para aprovecharla. ── */
@media (orientation:landscape) and (max-height:500px){
  .panel{max-height:100svh;border-radius:0}
  .hero{margin-bottom:24px}
  .wrap{padding-top:20px}
}
/* ── Táctil: targets ≥44px (Apple HIG / Material 48dp) ── */
@media(hover:none){
  .panel-close{width:44px;height:44px;border-radius:10px}
  .btn-dl{min-height:44px;padding:10px 16px}
  .btn-completar{min-height:44px;padding:11px 16px}
  .cp-sede-opt{min-height:44px;display:flex;align-items:center}
  .trk-phase-head{min-height:44px}
  .trk-toggle{opacity:1} /* sin hover no hay reveal: siempre visible */
}
@media(max-width:400px){
  .hero-name{font-size:19px}
  .card-code{font-size:17px}
  .card-name{display:none}
}
/* ── Completar datos form ── */
.btn-completar{display:inline-flex;align-items:center;gap:5px;margin-top:10px;padding:7px 13px;font-size:12px;font-weight:600;color:#1d4ed8;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;cursor:pointer;transition:background .15s;user-select:none}
.btn-completar:hover{background:#dbeafe}
.btn-completar svg{flex-shrink:0}
.cp-field{margin-bottom:16px}
.cp-field label{display:block;font-size:12px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.6px;margin-bottom:6px}
.cp-select,.cp-input{width:100%;padding:9px 12px;font-size:14px;border:1px solid var(--border);border-radius:8px;background:#fff;color:var(--txt);outline:none;transition:border .15s;box-sizing:border-box}
.cp-select:focus,.cp-input:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.1)}
.cp-sede-wrap{position:relative}
.cp-sede-input{width:100%;padding:9px 32px 9px 12px;font-size:14px;border:1px solid var(--border);border-radius:8px;background:#fff;color:var(--txt);outline:none;transition:border .15s;box-sizing:border-box}
.cp-sede-input:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.1)}
.cp-sede-wrap::after{content:'';position:absolute;right:12px;top:50%;width:9px;height:9px;border-right:2px solid #888;border-bottom:2px solid #888;transform:translateY(-65%) rotate(45deg);pointer-events:none}
.cp-sede-drop{display:none;position:absolute;top:calc(100% + 4px);left:0;right:0;z-index:300;background:#fff;border:1px solid var(--border);border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.12);max-height:220px;overflow-y:auto}
.cp-sede-drop.open{display:block}
.cp-sede-opt{padding:9px 14px;font-size:13px;cursor:pointer;color:var(--txt);line-height:1.4;border-bottom:1px solid var(--border-soft)}
.cp-sede-opt:last-child{border-bottom:none}
.cp-sede-opt:hover,.cp-sede-opt.focused{background:#eff6ff}
.cp-sede-opt mark{background:transparent;color:#2563eb;font-weight:700}
.cp-sede-empty{padding:12px 14px;font-size:13px;color:var(--muted);text-align:center}
.cp-sugerida{display:inline-flex;align-items:center;gap:5px;margin-top:5px;font-size:11.5px;color:#92400e;background:#fef3c7;border:1px solid #fde68a;border-radius:5px;padding:3px 8px;cursor:pointer}
.cp-sugerida:hover{background:#fde68a}
.cp-sugerida strong{font-weight:600;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.cp-btn-row{display:flex;gap:10px;margin-top:20px}
.cp-btn-save{flex:1;padding:11px;font-size:14px;font-weight:600;color:#fff;background:#0D0D0F;border:none;border-radius:10px;cursor:pointer;transition:opacity .15s}
.cp-btn-save:hover{opacity:.85}
.cp-btn-save:disabled{opacity:.5;cursor:not-allowed}
.cp-btn-cancel{padding:11px 16px;font-size:14px;font-weight:500;color:var(--txt);background:var(--bg);border:1px solid var(--border);border-radius:10px;cursor:pointer}
.cp-msg{margin-top:12px;padding:10px 14px;border-radius:8px;font-size:13px;display:none}
.cp-msg.ok{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}
.cp-msg.err{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
.env-pending-note{font-size:12px;color:var(--muted);margin-top:4px;font-style:italic}
</style>
</head>
<body>

<!-- Nav -->
<nav class="nav">
  <a href="#" class="nav-logo">
    <div class="nav-shield">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
    </div>
    <span class="nav-word">Group Global ISO</span>
  </a>
  <span class="nav-lock">
    <svg width="10" height="10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
    Acceso seguro
  </span>
</nav>

<div class="wrap">

<?php if (!$token): ?>
<!-- No token -->
<div class="splash">
  <div class="splash-ico" style="background:#EFF6FF">
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#1A3A8F" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
  </div>
  <h2>Portal de certificaciones</h2>
  <p>Accede con el enlace personalizado que te compartió tu ejecutivo de Group Global ISO.</p>
</div>

<?php elseif ($error): ?>
<!-- Error -->
<div class="splash">
  <div class="splash-ico" style="background:#FEF2F2">
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#DC2626" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
  </div>
  <h2>Enlace no válido</h2>
  <p><?= htmlspecialchars($error) ?><br><br>Contacta a tu ejecutivo para obtener un nuevo enlace de acceso.</p>
</div>

<?php else:
  [$hd, $hc, $hb] = match($cliente['estado']) {
    'activo'    => ['#059669','#059669','#10B981'],
    'prospecto' => ['#D97706','#D97706','#F59E0B'],
    default     => ['#9CA3AF','#6B7280','#D1D5DB'],
  };
  $n_vigentes = count(array_filter($certificados, fn($c) => $c['estado'] === 'vigente'));
  $n_total    = count($certificados);
?>

<!-- Hero -->
<div class="hero">
  <div class="hero-kicker">
    <span class="hero-kicker-dot" style="background:<?= $hb ?>"></span>
    Portal privado · Certificaciones y documentos
  </div>
  <div class="hero-name"><?= htmlspecialchars($cliente['razon_social']) ?></div>
  <div class="hero-desc">RUC <?= htmlspecialchars($cliente['ruc']) ?><?= $cliente['departamento'] ? ' &nbsp;·&nbsp; ' . htmlspecialchars($cliente['departamento']) : '' ?></div>
  <div class="hero-stats">
    <?php if ($n_total > 0): ?>
    <span class="hero-stat">
      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/></svg>
      <strong><?= $n_total ?></strong> <?= $n_total === 1 ? 'certificación' : 'certificaciones' ?>
      <?php if ($n_vigentes > 0): ?>&nbsp;·&nbsp;<strong><?= $n_vigentes ?></strong> vigente<?= $n_vigentes > 1 ? 's' : '' ?><?php endif; ?>
    </span>
    <?php endif; ?>
    <?php if (!empty($envios)): ?>
    <span class="hero-stat">
      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
      <strong><?= count($envios) ?></strong> <?= count($envios) === 1 ? 'envío' : 'envíos' ?>
    </span>
    <?php endif; ?>
  </div>
</div>

<!-- ══ CERTIFICACIONES ══ -->
<div>
  <div class="sec-head">
    <span class="sec-label">Certificaciones</span>
    <?php if ($tiene_archivos): ?>
    <a href="download_certs.php?token=<?= urlencode($token) ?>" class="btn-dl">
      <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      Descargar todo
    </a>
    <?php endif; ?>
  </div>

  <?php if (empty($certificados)): ?>
  <p class="empty-state">Sin certificados registrados aún.</p>
  <?php else: ?>

  <div class="cert-grid">
    <?php foreach ($certificados as $cert):
      $sc  = $st_cfg[$cert['estado']] ?? $st_cfg['suspendido'];
      $fve = fmon($cert['fecha_vencimiento']);
    ?>
    <div class="cert-card"
         style="--card-bar:<?= $sc['bar'] ?>"
         data-cert-id="<?= (int)$cert['id'] ?>"
         role="button" tabindex="0"
         aria-label="Ver <?= htmlspecialchars($cert['norma']) ?>">

      <div class="card-status">
        <span class="s-dot" style="background:<?= $sc['dot'] ?>"></span>
        <span class="s-text" style="color:<?= $sc['dot'] ?>"><?= $sc['label'] ?></span>
      </div>

      <div class="card-code"><?= htmlspecialchars($cert['norma']) ?></div>
      <div class="card-name"><?= htmlspecialchars($cert['norma_nombre']) ?></div>

      <div class="card-foot">
        <div class="card-expiry">
          <?php if ($fve): ?>
          Vence
          <strong><?= $fve ?></strong>
          <?php else: ?>
          <strong style="color:var(--muted)">—</strong>
          <?php endif; ?>
        </div>
        <span class="card-cta">
          Ver
          <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M7 17L17 7M7 7h10v10"/></svg>
        </span>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <?php endif; ?>
</div>

<!-- ══ ENVÍOS ══ -->
<?php if (!empty($envios)): ?>
<div style="margin-top:40px">
  <div class="sec-head">
    <span class="sec-label">Seguimiento de envío</span>
  </div>
  <div class="env-list">
    <?php foreach ($envios as $e):
      $col      = $e['color_hex'] ?: '#9CA3AF';
      $norms    = $e['normas_enviadas'] ?: '';
      $entregado = !empty($e['fecha_recibido']);
    ?>
    <?php $es_editable_card = ((int)$e['estado_envio_id'] === $estado_inicial_id); ?>
    <div class="env-card <?= $es_editable_card ? 'env-card-pending' : '' ?>"
         data-env-id="<?= (int)$e['id'] ?>"
         <?= !$es_editable_card ? 'role="button" tabindex="0"' : '' ?>
         aria-label="<?= $es_editable_card ? 'Envío pendiente de datos' : 'Ver detalle de envío ' . htmlspecialchars($e['agencia'] ?? '') ?>">

      <div class="env-top">
        <div>
          <div class="env-agency-name"><?= $e['agencia'] ? htmlspecialchars($e['agencia']) : '<em style="color:var(--muted);font-style:normal">Sin courier asignado</em>' ?></div>
          <?php if ($norms): ?>
          <div class="env-norms"><?= htmlspecialchars($norms) ?></div>
          <?php endif; ?>
        </div>
        <div class="env-st-wrap">
          <span class="s-dot" style="background:<?= htmlspecialchars($col) ?>"></span>
          <span class="env-st-text" style="color:<?= htmlspecialchars($col) ?>"><?= htmlspecialchars($e['estado_nombre']) ?></span>
        </div>
      </div>

      <?php if ($es_editable_card): ?>
      <div class="env-foot" style="flex-direction:column;align-items:flex-start;gap:0">
        <p class="env-pending-note">Tu certificado está listo. Completa los datos para coordinar el envío.</p>
        <button class="btn-completar" onclick="event.stopPropagation();openCompletarPanel(<?= (int)$e['id'] ?>)">
          <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
          Completar datos de envío
        </button>
      </div>
      <?php else: ?>
      <div class="env-foot">
        <?php if ($entregado): ?>
        <span class="env-delivered-badge">
          <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg>
          Entregado el <?= fdate($e['fecha_recibido']) ?>
        </span>
        <?php elseif ($e['fecha_envio']): ?>
        <span class="env-date">Enviado <?= fdate($e['fecha_envio']) ?></span>
        <?php else: ?>
        <span class="env-date"></span>
        <?php endif; ?>
        <span class="env-cta">
          Ver detalle
          <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M7 17L17 7M7 7h10v10"/></svg>
        </span>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php endif; ?>

<footer>
  © <?= date('Y') ?> Group Global ISO S.A.C. &nbsp;·&nbsp; <a href="https://www.groupglobaliso.com">groupglobaliso.com</a>
</footer>
</div>

<!-- ══ SIDE PANEL ══ -->
<div class="overlay" id="overlay" aria-hidden="true"></div>
<aside class="panel" id="panel" role="dialog" aria-modal="true" aria-label="Detalle de certificación">
  <div class="panel-bar">
    <div class="panel-bar-left">
      <span class="s-dot" id="p-dot" style="width:8px;height:8px;border-radius:50%"></span>
      <span class="s-text" id="p-status-lbl" style="font-size:12.5px;font-weight:500"></span>
    </div>
    <button class="panel-close" id="panel-close" aria-label="Cerrar">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M18 6L6 18M6 6l12 12"/></svg>
    </button>
  </div>

  <div class="panel-scroll">
    <div id="p-code" class="p-code"></div>
    <div id="p-name" class="p-name"></div>
    <div id="p-casa" class="p-casa"></div>

    <!-- Vigencia -->
    <div class="p-section" id="p-sec-vigencia">
      <div class="p-sec-title">Vigencia</div>
      <div id="p-vigencia-rows"></div>
    </div>

    <!-- Seguimientos -->
    <div class="p-section" id="p-sec-segs">
      <div class="p-sec-title">Seguimientos</div>
      <div id="p-segs-content"></div>
    </div>

    <!-- Documentos -->
    <div class="p-section" id="p-sec-docs" style="display:none">
      <div class="p-sec-title">Documentos</div>
      <div class="p-downloads" id="p-downloads"></div>
    </div>
  </div>
</aside>

<!-- ══ ENV SIDE PANEL ══ -->
<aside class="panel" id="env-panel" role="dialog" aria-modal="true" aria-label="Detalle de envío" aria-hidden="true">
  <div class="panel-bar">
    <div class="panel-bar-left">
      <span id="ep-st-dot" class="s-dot" style="width:8px;height:8px;border-radius:50%"></span>
      <span id="ep-st-lbl" class="s-text" style="font-size:12.5px;font-weight:500"></span>
    </div>
    <button class="panel-close" id="env-panel-close" aria-label="Cerrar">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M18 6L6 18M6 6l12 12"/></svg>
    </button>
  </div>

  <div class="panel-scroll">
    <!-- Agency + status header -->
    <div class="ep-agency-bar">
      <div id="ep-av" class="ep-av"></div>
      <div id="ep-agency" class="ep-agency-name"></div>
    </div>

    <!-- Documentación asociada -->
    <div class="p-section" id="ep-sec-certs">
      <div class="p-sec-title">Documentación asociada</div>
      <div id="ep-certs" class="ep-cert-list"></div>
    </div>

    <!-- Información dinámica -->
    <div class="p-section" id="ep-sec-info">
      <div class="p-sec-title">Información del envío</div>
      <div id="ep-fields"></div>
    </div>

    <!-- Notas -->
    <div class="p-section" id="ep-sec-notas" style="display:none">
      <div class="p-sec-title">Notas</div>
      <div id="ep-nota" class="ep-nota"></div>
    </div>

    <!-- Entregado -->
    <div id="ep-delivered" class="ep-delivered" style="display:none">
      <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg>
      <span id="ep-delivered-txt"></span>
    </div>

    <!-- Seguimiento (Olva / Shalom) -->
    <div class="p-section" id="ep-sec-tracking" style="display:none">
      <div class="p-sec-title">Seguimiento en tiempo real
        <span id="ep-trk-badge" style="margin-left:6px;font-size:10px;font-weight:600;letter-spacing:.5px;text-transform:uppercase;background:#ECFDF5;color:#059669;border:1px solid #A7F3D0;border-radius:5px;padding:2px 7px;vertical-align:middle;display:none"></span>
      </div>
      <div id="ep-tracking-body">
        <div class="trk-skeleton" id="ep-trk-skeleton">
          <div class="trk-sk-row"><div class="trk-sk-circle"></div><div class="trk-sk-line" style="width:120px"></div></div>
          <div class="trk-sk-row"><div class="trk-sk-circle"></div><div class="trk-sk-line" style="width:90px"></div></div>
          <div class="trk-sk-row"><div class="trk-sk-circle"></div><div class="trk-sk-line" style="width:140px"></div></div>
        </div>
        <div id="ep-trk-content" style="display:none"></div>
      </div>
    </div>
  </div>
</aside>

<!-- ══ COMPLETAR DATOS PANEL ══ -->
<aside class="panel" id="cp-panel" role="dialog" aria-modal="true" aria-label="Completar datos de envío" aria-hidden="true">
  <div class="panel-bar">
    <div class="panel-bar-left" style="font-size:13px;font-weight:600;color:var(--txt)">Completar datos de envío</div>
    <button class="panel-close" id="cp-panel-close" aria-label="Cerrar">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M18 6L6 18M6 6l12 12"/></svg>
    </button>
  </div>
  <div class="panel-scroll">
    <input type="hidden" id="cp-envio-id" value="">

    <!-- Autocompletar con los datos del último envío del cliente -->
    <div id="cp-ultimo-chip" onclick="cpUsarUltimoEnvio()" style="display:none;align-items:center;gap:8px;margin-bottom:12px;font-size:13px;color:#92400e;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:8px 12px;cursor:pointer;">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/></svg>
      <span>Usar datos del último envío: <strong id="cp-ultimo-texto"></strong></span>
    </div>

    <div class="cp-field">
      <label>Courier *</label>
      <select id="cp-agencia" class="cp-select">
        <option value="">Selecciona courier...</option>
        <?php foreach ($agencias_portal as $ag): ?>
        <option value="<?= (int)$ag['id'] ?>"><?= htmlspecialchars($ag['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- Sede modo INTEGRACIÓN (API): buscador de sedes -->
    <div class="cp-field" id="cp-field-sede" style="display:none">
      <label>Sede de destino</label>
      <div class="cp-sede-wrap" id="cp-sede-wrap">
        <input type="text" id="cp-sede-search" class="cp-sede-input" autocomplete="off" placeholder="Buscar sede...">
        <div class="cp-sede-drop" id="cp-sede-drop"></div>
      </div>
      <input type="hidden" id="cp-sede-val" value="">
      <div id="cp-sugerida-chip" class="cp-sugerida" style="display:none" onclick="cpUsarSugerida()">
        <svg width="11" height="11" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
        Usar sede anterior: <strong id="cp-sugerida-texto"></strong>
      </div>
    </div>

    <!-- Punto de recojo Olva: buscador (mismo componente que Sede); se llena tras elegir el distrito -->
    <div class="cp-field" id="cp-field-tienda" style="display:none">
      <label>Punto de recojo *</label>
      <div class="cp-sede-wrap" id="cp-tienda-wrap">
        <input type="text" id="cp-tienda-search" class="cp-sede-input" autocomplete="off" placeholder="Selecciona primero el distrito…" disabled>
        <div class="cp-sede-drop" id="cp-tienda-drop"></div>
      </div>
      <input type="hidden" id="cp-tienda-val" value="">
      <input type="text" id="cp-tienda-manual" class="cp-input" style="display:none" autocomplete="off" placeholder="Escribe el punto de recojo (tienda / oficina Olva)" maxlength="500">
    </div>

    <!-- Sede modo MANUAL: texto libre (couriers sin integración) -->
    <div class="cp-field" id="cp-field-sede-manual" style="display:none">
      <label>Sede / punto de destino</label>
      <input type="text" id="cp-sede-manual" class="cp-input" autocomplete="off" placeholder="Ej: Agencia Av. La Marina 123, Lima">
    </div>

    <!-- Dirección de destino: solo couriers manuales -->
    <div class="cp-field" id="cp-field-direccion" style="display:none">
      <label>Dirección de destino</label>
      <input type="text" id="cp-direccion" class="cp-input" autocomplete="off" placeholder="Dirección completa de entrega">
    </div>

    <!-- Entrega a domicilio (Lima): dirección + referencia + punto exacto en el mapa -->
    <div id="cp-domicilio" style="display:none">
      <div class="cp-field">
        <label>Dirección de entrega *</label>
        <div style="display:flex;gap:6px">
          <input type="text" id="cp-dom-direccion" class="cp-input" autocomplete="off" style="flex:1"
                 placeholder="Calle, número, distrito (ej: Av. La Marina 123, San Miguel)">
          <button type="button" id="cp-dom-buscar" class="cp-btn-sec" style="font-size:13px;padding:0 14px;white-space:nowrap"
                  title="Ubicar esta dirección en el mapa">🔍 Buscar</button>
        </div>
        <div id="cp-dom-geores" style="display:none;margin-top:6px;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;max-height:190px;overflow-y:auto"></div>
      </div>
      <div class="cp-field">
        <label>Referencia</label>
        <input type="text" id="cp-dom-ref" class="cp-input" autocomplete="off"
               placeholder="Ej: portón azul, 2do piso, al lado de la farmacia" maxlength="300">
      </div>
      <div class="cp-field">
        <label>Marca el punto exacto en el mapa *</label>
        <p style="margin:2px 0 8px;font-size:12.5px;color:#64748b">
          Arrastra el pin (o toca el mapa) al lugar exacto de entrega. El motorizado irá ahí.</p>
        <div style="display:flex;gap:8px;margin-bottom:8px">
          <button type="button" id="cp-dom-geo" class="cp-btn-sec" style="font-size:13px;padding:8px 12px">
            📍 Usar mi ubicación actual</button>
        </div>
        <div id="cp-mapa" style="height:280px;border-radius:12px;border:1px solid #e2e8f0;overflow:hidden;background:#eef2f7"></div>
        <div id="cp-dom-coords" style="margin-top:6px;font-size:12px;color:#64748b"></div>
        <input type="hidden" id="cp-dom-lat" value="">
        <input type="hidden" id="cp-dom-lng" value="">
      </div>
    </div>

    <div class="cp-field">
      <label>DNI del destinatario</label>
      <div style="position:relative">
        <input type="text" id="cp-dni" class="cp-input" placeholder="12345678" maxlength="8" inputmode="numeric" autocomplete="off">
        <span id="cp-dni-spinner" style="display:none;position:absolute;right:10px;top:50%;transform:translateY(-50%)">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" style="animation:spin .8s linear infinite;color:#6b7280"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
        </span>
      </div>
      <div id="cp-dni-msg" style="display:none;font-size:12px;margin-top:4px"></div>
    </div>

    <div class="cp-field">
      <label>Nombre del destinatario</label>
      <input type="text" id="cp-destinatario" class="cp-input" placeholder="Se completará al ingresar el DNI" readonly style="background:#f9fafb;color:#6b7280;cursor:not-allowed">
    </div>

    <div class="cp-btn-row">
      <button class="cp-btn-cancel" onclick="closeCpPanel()">Cancelar</button>
      <button class="cp-btn-save" id="cp-btn-save" onclick="submitCompletarEnvio()">Guardar</button>
    </div>
    <div class="cp-msg" id="cp-msg"></div>
  </div>
</aside>

<?php if ($cliente): ?>
<script src="<?= APP_URL ?>/assets/leaflet/leaflet.js?v=<?= @filemtime(__DIR__ . '/../assets/leaflet/leaflet.js') ?>"></script>
<script>
(function(){
  const CERTS        = <?= json_encode($certs_js,   JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>;
  const ENVIOS       = <?= json_encode($envios_js ?? [], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>;
  const BASE         = <?= json_encode(rtrim(UPLOAD_URL,'/')) ?>;
  const PORTAL_TOKEN = <?= json_encode($token) ?>;
  const PORTAL_URL   = <?= json_encode(rtrim(APP_URL,'/') . '/portal') ?>;

  const ST = {
    vigente:    {dot:'#059669',label:'Vigente'},
    por_vencer: {dot:'#D97706',label:'Por vencer'},
    vencido:    {dot:'#DC2626',label:'Vencido'},
    en_proceso: {dot:'#2563EB',label:'En proceso'},
    suspendido: {dot:'#9CA3AF',label:'Suspendido'},
  };
  const RC = {
    pendiente:   {bg:'#FFFBEB',color:'#B45309',label:'Pendiente'},
    aprobado:    {bg:'#F0FDF4',color:'#059669',label:'Aprobado'},
    observado:   {bg:'#FFF7ED',color:'#C2410C',label:'Observado'},
    no_aprobado: {bg:'#FEF2F2',color:'#DC2626',label:'No aprobado'},
  };

  const overlay   = document.getElementById('overlay');
  const certPanel = document.getElementById('panel');
  const envPanel  = document.getElementById('env-panel');
  const cpPanel   = document.getElementById('cp-panel');

  function esc(s){ return s ? String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;') : '' }
  function ini(s){ return s ? s.trim().substring(0,2).toUpperCase() : '?' }

  /* ── Historial móvil: el botón atrás (Android) debe cerrar el sheet, no salir
     del portal. Al abrir un panel se hace pushState; el popstate cierra. Cuando
     el cierre es manual (X / overlay / Escape) se consume esa entrada con
     history.back() — el flag ya en false evita el doble cierre. ── */
  let _sheetPushed = false;
  function _pushSheet() {
    if (!_sheetPushed) {
      try { history.pushState({ggiSheet:1}, ''); _sheetPushed = true; } catch(e) {}
    }
  }
  window.addEventListener('popstate', function() {
    if (_sheetPushed) { _sheetPushed = false; closeAll(); }
  });

  function closeAll() {
    overlay.classList.remove('open');
    certPanel.classList.remove('open');
    envPanel.classList.remove('open');
    cpPanel.classList.remove('open');
    certPanel.setAttribute('aria-hidden','true');
    envPanel.setAttribute('aria-hidden','true');
    cpPanel.setAttribute('aria-hidden','true');
    document.body.style.overflow = '';
    if (_sheetPushed) { _sheetPushed = false; try { history.back(); } catch(e) {} }
  }

  window.closeCpPanel = function() { closeAll(); };

  /* ── Cert panel ── */
  function openCertPanel(id) {
    const c = CERTS[id];
    if (!c) return;
    const st = ST[c.estado] || ST.suspendido;

    document.getElementById('p-dot').style.background = st.dot;
    document.getElementById('p-status-lbl').textContent = st.label;
    document.getElementById('p-status-lbl').style.color = st.dot;
    document.getElementById('p-code').textContent = c.norma;
    document.getElementById('p-name').textContent = c.nombre;
    document.getElementById('p-casa').textContent = c.casa || '';

    let vigRows = '';
    if (c.emision)     vigRows += `<div class="p-row"><span class="p-lbl">Emitido</span><span class="p-val">${esc(c.emision)}</span></div>`;
    if (c.vencimiento) vigRows += `<div class="p-row"><span class="p-lbl">Vence</span><span class="p-val" style="color:${st.dot};font-weight:600">${esc(c.vencimiento)}</span></div>`;
    if (c.num)         vigRows += `<div class="p-row"><span class="p-lbl">N° certificado</span><span class="p-val"><span class="p-mono">${esc(c.num)}</span></span></div>`;
    document.getElementById('p-vigencia-rows').innerHTML = vigRows || '<span style="font-size:12px;color:var(--muted)">Sin fechas registradas</span>';

    let segsHtml = '';
    [c.seg1, c.seg2].forEach(function(seg, i) {
      const rc = seg ? (RC[seg.resultado] || {bg:'#F3F4F6',color:'#6B7280',label:'—'}) : null;
      segsHtml += `<div class="seg-item"><div class="seg-num">${i+1}</div><div class="seg-info">`;
      if (seg) {
        segsHtml += `<div class="seg-fecha">${esc(seg.fecha)}</div>`;
        if (seg.reprog)    segsHtml += `<div class="seg-sub" style="color:#D97706">↻ Reprogramada</div>`;
        if (seg.realizada) segsHtml += `<div class="seg-sub">Realizada el ${esc(seg.realizada)}</div>`;
        segsHtml += `<span class="seg-chip" style="background:${rc.bg};color:${rc.color}">${rc.label}</span>`;
      } else { segsHtml += `<span class="seg-empty">No programada</span>`; }
      segsHtml += `</div></div>`;
    });
    document.getElementById('p-segs-content').innerHTML = segsHtml;

    const dlIcon = `<svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>`;
    const dlArr  = `<svg class="dl-arr" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>`;
    let dlHtml = '';
    if (c.archivo_es) dlHtml += `<a href="${BASE}/${esc(c.archivo_es)}" target="_blank" download class="dl-row"><div class="dl-ico">${dlIcon}</div><div class="dl-txt"><div class="dl-lbl">Certificado</div><div class="dl-sub">Español · PDF</div></div>${dlArr}</a>`;
    if (c.archivo_en) dlHtml += `<a href="${BASE}/${esc(c.archivo_en)}" target="_blank" download class="dl-row"><div class="dl-ico">${dlIcon}</div><div class="dl-txt"><div class="dl-lbl">Certificate</div><div class="dl-sub">English · PDF</div></div>${dlArr}</a>`;
    document.getElementById('p-downloads').innerHTML = dlHtml;
    document.getElementById('p-sec-docs').style.display = dlHtml ? '' : 'none';

    closeAll();
    overlay.classList.add('open');
    certPanel.classList.add('open');
    certPanel.setAttribute('aria-hidden','false');
    document.getElementById('panel-close').focus();
    document.body.style.overflow = 'hidden';
    _pushSheet();
  }

  /* ── Tracking stepper (Olva + Shalom) ── */
  var _trkCache = {};

  var OLVA_FASE_MAP = {
    1: ['REGISTRADO','RECEPCION TIENDA','CONFIRMACION RECOJO OFICINA'],
    2: ['DESPACHADO','RECEPCION DESPACHO','TRANSITO'],
    3: ['ASIGNADO','CONFIRMACION EN TIENDA','MOTIVADO','ENTREGADO','CONFIRMACION RECOJO','ASIGNADO A DEVOLUCION','REZAGADO','DEVUELTO'],
  };

  var SHALOM_FASES = [
    {label:'Recepcionado', num:1, fases:['origen']},
    {label:'En tránsito',  num:2, fases:['transito','demora']},
    {label:'En destino',   num:3, fases:['destino','reparto']},
    {label:'Entregado',    num:4, fases:['entregado']},
  ];

  function renderStepper(data) {
    var isShalom = data.stepper_type === 'shalom';
    var fase     = data.fase_stepper || 0;
    var fases    = isShalom ? SHALOM_FASES : [
      {label:'Recepcionado',          num:1},
      {label:'En camino',             num:2},
      {label:'En proceso de entrega', num:3},
    ];

    var html = '<div class="trk-stepper">';

    fases.forEach(function(f, i) {
      var st   = fase > f.num ? 'done' : (fase === f.num ? 'active' : 'pending');
      var icon = st === 'done'
        ? '<svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path d="M20 6L9 17l-5-5"/></svg>'
        : String(f.num);

      // Filtrar eventos de esta fase
      var evsFase = [];
      if (isShalom) {
        // Para Shalom: eventos_friendly ya tiene fase identificada
        evsFase = (data.eventos_friendly || []).filter(function(ev) {
          return (f.fases || []).includes(ev.fase);
        });
      } else {
        // Para Olva: filtrar por estado
        evsFase = (data.eventos_friendly || []).filter(function(ev) {
          return (OLVA_FASE_MAP[f.num] || []).includes(ev.estado);
        });
      }

      var hasEvs = evsFase.length > 0;
      var evId   = 'trk-ev-' + i;

      html += '<div class="trk-phase' + (hasEvs ? ' has-events' : '') + '">';
      html += '<div class="trk-phase-head" onclick="toggleTrkPhase(\'' + evId + '\', this)">';
      html += '<div class="trk-icon ' + st + '">' + icon + '</div>';
      html += '<span class="trk-phase-label ' + st + '">' + esc(f.label) + '</span>';
      if (hasEvs) html += '<span class="trk-toggle">VER DETALLE</span>';
      html += '</div>';

      if (i < fases.length - 1) {
        html += '<div class="trk-connector' + (fase > f.num ? ' done' : '') + '"></div>';
      }

      if (hasEvs) {
        html += '<div class="trk-events" id="' + evId + '">';
        evsFase.forEach(function(ev) {
          html += '<div class="trk-ev">';
          html += '<div class="trk-ev-label">' + esc(ev.label) + '</div>';
          html += '<div class="trk-ev-meta">' + esc(ev.fecha) + (ev.sede ? ' · ' + esc(ev.sede) : '') + '</div>';
          html += '</div>';
        });
        html += '</div>';
      }
      html += '</div>';
    });

    html += '</div>';
    return html;
  }

  window.toggleTrkPhase = function(evId, headEl) {
    const el = document.getElementById(evId);
    if (!el) return;
    const open = el.classList.toggle('open');
    const tog = headEl.querySelector('.trk-toggle');
    if (tog) tog.textContent = open ? 'OCULTAR' : 'VER DETALLE';
  };

  function cargarTracking(envio_id) {
    if (_trkCache[envio_id]) {
      mostrarTracking(_trkCache[envio_id]);
      return;
    }
    document.getElementById('ep-trk-skeleton').style.display = '';
    document.getElementById('ep-trk-content').style.display = 'none';

    fetch(PORTAL_URL + '/ajax_tracking.php?token=' + encodeURIComponent(PORTAL_TOKEN) + '&envio_id=' + envio_id)
      .then(function(r){ return r.json(); })
      .then(function(res) {
        document.getElementById('ep-trk-skeleton').style.display = 'none';
        if (res.ok && res.data) {
          _trkCache[envio_id] = res.data;
          mostrarTracking(res.data);
        } else if (res.error === 'sin_codigo') {
          document.getElementById('ep-trk-content').innerHTML = '<p class="trk-msg">Tu certificado está siendo preparado para el envío.</p>';
          document.getElementById('ep-trk-content').style.display = '';
        } else {
          document.getElementById('ep-trk-content').innerHTML = '<p class="trk-msg">No se pudo obtener el estado del envío en este momento.</p>';
          document.getElementById('ep-trk-content').style.display = '';
        }
      })
      .catch(function() {
        document.getElementById('ep-trk-skeleton').style.display = 'none';
        document.getElementById('ep-trk-content').innerHTML = '<p class="trk-msg">No se pudo obtener el estado del envío en este momento.</p>';
        document.getElementById('ep-trk-content').style.display = '';
      });
  }

  function mostrarTracking(data) {
    const ct = document.getElementById('ep-trk-content');
    let html = renderStepper(data);
    if (data.estado_actualizado) {
      html += '<div class="trk-st-upd"><svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg> Estado actualizado</div>';
    }
    ct.innerHTML = html;
    ct.style.display = '';
  }

  /* ── Envío panel ── */
  function openEnvPanel(id) {
    const e = ENVIOS[id];
    if (!e) return;

    document.getElementById('ep-st-dot').style.background = e.color;
    document.getElementById('ep-st-lbl').textContent = e.estado;
    document.getElementById('ep-st-lbl').style.color = e.color;

    document.getElementById('ep-av').textContent = ini(e.agencia);
    document.getElementById('ep-agency').textContent = e.agencia;

    const certsEl  = document.getElementById('ep-certs');
    const secCerts = document.getElementById('ep-sec-certs');
    if (e.certs && e.certs.length) {
      certsEl.innerHTML = e.certs.map(function(c) {
        return `<div class="ep-cert-item"><span class="ep-cert-code">${esc(c.codigo)}</span><span class="ep-cert-name">${esc(c.nombre)}</span></div>`;
      }).join('');
      secCerts.style.display = '';
    } else if (e.normas_str) {
      certsEl.innerHTML = e.normas_str.split(' · ').map(function(n) {
        return `<div class="ep-cert-item"><span class="ep-cert-code">${esc(n)}</span></div>`;
      }).join('');
      secCerts.style.display = '';
    } else {
      secCerts.style.display = 'none';
    }

    const fieldsEl = document.getElementById('ep-fields');
    const secInfo  = document.getElementById('ep-sec-info');
    if (e.fields && e.fields.length) {
      fieldsEl.innerHTML = e.fields.map(function(f) {
        const v = f.mono
          ? `<span class="p-val"><span class="p-mono">${esc(f.val)}</span></span>`
          : `<span class="p-val">${esc(f.val)}</span>`;
        return `<div class="p-row"><span class="p-lbl">${esc(f.label)}</span>${v}</div>`;
      }).join('');
      secInfo.style.display = '';
    } else { secInfo.style.display = 'none'; }

    const secNota = document.getElementById('ep-sec-notas');
    if (e.notas) {
      document.getElementById('ep-nota').textContent = e.notas;
      secNota.style.display = '';
    } else { secNota.style.display = 'none'; }

    const delEl = document.getElementById('ep-delivered');
    if (e.entregado && e.fecha_recibido) {
      document.getElementById('ep-delivered-txt').textContent = 'Entregado el ' + e.fecha_recibido;
      delEl.style.display = '';
    } else { delEl.style.display = 'none'; }

    // Sección de tracking (Olva o Shalom)
    const secTrk = document.getElementById('ep-sec-tracking');
    const badge   = document.getElementById('ep-trk-badge');
    if (e.has_tracking) {
      const agencyLabel = e.is_shalom ? 'Shalom' : 'Olva';
      const agencyBg    = e.is_shalom ? '#FFF7ED' : '#ECFDF5';
      const agencyColor = e.is_shalom ? '#C2410C' : '#059669';
      const agencyBord  = e.is_shalom ? '#FED7AA' : '#A7F3D0';
      secTrk.style.display     = '';
      badge.style.display      = '';
      badge.textContent        = agencyLabel;
      badge.style.background   = agencyBg;
      badge.style.color        = agencyColor;
      badge.style.borderColor  = agencyBord;
      // Reset para nueva carga
      document.getElementById('ep-trk-skeleton').style.display = '';
      document.getElementById('ep-trk-content').style.display = 'none';
      document.getElementById('ep-trk-content').innerHTML = '';
    } else {
      secTrk.style.display = 'none';
      badge.style.display  = 'none';
    }

    closeAll();
    overlay.classList.add('open');
    envPanel.classList.add('open');
    envPanel.setAttribute('aria-hidden','false');
    document.getElementById('env-panel-close').focus();
    document.body.style.overflow = 'hidden';
    _pushSheet();

    // Cargar tracking lazily si hay seguimiento disponible
    if (e.has_tracking) {
      cargarTracking(id);
    }
  }

  /* ── Completar datos panel ── */
  var _cpSedeAll     = [];
  var _cpSedeSug     = null;
  var _cpEsOlva      = false;
  var _cpTiendaPre   = '';   // tienda ya guardada (al editar), para preseleccionar

  function cpDetectOlva() {
    var o = document.querySelector('#cp-agencia option:checked');
    return !!(o && /olva/i.test(o.textContent));
  }
  var _cpTiendaAll = [];
  function cpSetTiendaManual(on, ph) {
    var wrap = document.getElementById('cp-tienda-wrap');
    var man  = document.getElementById('cp-tienda-manual');
    if (on)  { if (wrap) wrap.style.display='none'; if (man){ man.style.display=''; man.disabled=false; if(ph) man.placeholder=ph; } }
    else     { if (wrap) wrap.style.display='';     if (man){ man.style.display='none'; man.disabled=true; man.value=''; } }
  }
  function cpResetTienda() {
    var field = document.getElementById('cp-field-tienda');
    if (field) field.style.display='none';
    _cpTiendaAll = [];
    var s = document.getElementById('cp-tienda-search'); if (s){ s.value=''; s.disabled=true; s.placeholder='Selecciona primero el distrito…'; }
    var v = document.getElementById('cp-tienda-val'); if (v) v.value='';
    var d = document.getElementById('cp-tienda-drop'); if (d){ d.innerHTML=''; d.classList.remove('open'); }
    cpSetTiendaManual(false);
  }
  function cpLoadTiendas(ubigeo, preSel) {
    var field = document.getElementById('cp-field-tienda');
    var s     = document.getElementById('cp-tienda-search');
    var v     = document.getElementById('cp-tienda-val');
    if (!field || !s) return;
    field.style.display='';
    cpSetTiendaManual(false);
    if (v) v.value='';
    s.value=''; s.disabled=true; s.placeholder='Cargando puntos de recojo…';
    if (!ubigeo) { cpSetTiendaManual(true,'Escribe el punto de recojo'); if(preSel) document.getElementById('cp-tienda-manual').value=preSel; return; }
    fetch(PORTAL_URL + '/ajax_tiendas_portal.php?token=' + encodeURIComponent(PORTAL_TOKEN) + '&ubigeo=' + encodeURIComponent(ubigeo))
      .then(function(r){ return r.json(); })
      .then(function(d){
        var items = d.tiendas || [];
        if (!items.length) { cpSetTiendaManual(true,'Sin tiendas en el distrito: escribe el punto de recojo'); if(preSel) document.getElementById('cp-tienda-manual').value=preSel; return; }
        _cpTiendaAll = items.map(function(t){ return t.nombre || ''; }).filter(Boolean);
        s.disabled=false; s.placeholder='Buscar punto de recojo…';
        cpInitTiendaDropdown();
        if (preSel) {
          if (_cpTiendaAll.indexOf(preSel) !== -1) { v.value = preSel; s.value = preSel; }
          else { cpSetTiendaManual(true); document.getElementById('cp-tienda-manual').value = preSel; }
        }
      })
      .catch(function(){ cpSetTiendaManual(true,'Olva no disponible: escribe el punto de recojo'); if(preSel) document.getElementById('cp-tienda-manual').value=preSel; });
  }
  function cpRenderTiendas(q) {
    var drop = document.getElementById('cp-tienda-drop');
    var val  = document.getElementById('cp-tienda-val');
    var qLow = q.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g,'');
    var matches = q ? _cpTiendaAll.filter(function(n){ return n.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g,'').includes(qLow); }) : _cpTiendaAll;
    if (!matches.length) { drop.innerHTML = '<div class="cp-sede-empty">Sin resultados</div>'; return; }
    drop.innerHTML = matches.slice(0,60).map(function(n){
      var hi = q ? n.replace(new RegExp('('+q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')+')','gi'),'<mark>$1</mark>') : n;
      return '<div class="cp-sede-opt" data-val="'+n.replace(/"/g,'&quot;')+'">'+hi+'</div>';
    }).join('');
    drop.querySelectorAll('.cp-sede-opt').forEach(function(opt){
      opt.addEventListener('click', function(){
        val.value = this.dataset.val;
        document.getElementById('cp-tienda-search').value = this.dataset.val;
        drop.classList.remove('open');
      });
    });
  }
  function cpInitTiendaDropdown() {
    var inp  = document.getElementById('cp-tienda-search');
    var drop = document.getElementById('cp-tienda-drop');
    var val  = document.getElementById('cp-tienda-val');
    inp.oninput = function(){ cpRenderTiendas(inp.value.trim()); if(!inp.value.trim()) val.value=''; drop.classList.add('open'); };
    inp.onfocus = function(){ cpRenderTiendas(inp.value.trim()); drop.classList.add('open'); };
    document.addEventListener('click', function(e){ if(!document.getElementById('cp-tienda-wrap')?.contains(e.target)) drop.classList.remove('open'); }, { capture:true });
  }

  // ── Autocompletar con el último envío del cliente ──
  var _cpUltimo = null;
  function cpCargarUltimoEnvio(envioId, agenciaIdActual) {
    var chip = document.getElementById('cp-ultimo-chip');
    _cpUltimo = null;
    if (chip) chip.style.display = 'none';
    if (agenciaIdActual) return;  // el envío ya tiene datos → no ofrecer
    fetch(PORTAL_URL + '/ajax_ultimo_envio_portal.php?token=' + encodeURIComponent(PORTAL_TOKEN) + '&envio_id=' + (envioId || 0))
      .then(function(r){ return r.json(); })
      .then(function(d){
        if (!d.ok || !d.data) return;
        _cpUltimo = d.data;
        var resumen = [d.data.agencia, d.data.sede_registro || d.data.direccion_destino, d.data.destinatario].filter(Boolean).join(' · ');
        document.getElementById('cp-ultimo-texto').textContent = resumen || 'último envío';
        if (chip) chip.style.display = 'flex';
      })
      .catch(function(){});
  }
  window.cpUsarUltimoEnvio = function() {
    var d = _cpUltimo; if (!d) return;
    var envioId = parseInt(document.getElementById('cp-envio-id').value) || 0;
    document.getElementById('cp-agencia').value = String(d.agencia_envio_id);
    _cpEsOlva = cpDetectOlva();
    _cpTiendaPre = d.tienda_destino || '';
    // Destinatario + DNI
    document.getElementById('cp-dni').value = d.destinatario_dni || '';
    var dest = document.getElementById('cp-destinatario');
    if (dest) { dest.value = d.destinatario || ''; if (d.destinatario) { dest.style.color = '#374151'; } }
    // Cargar sedes con sede/dirección previas (preselecciona sede y, si Olva, la tienda vía _cpTiendaPre)
    cpCargarSedes(d.agencia_envio_id, envioId, d.sede_registro || '', d.direccion_destino || '');
    var chip = document.getElementById('cp-ultimo-chip'); if (chip) chip.style.display = 'none';
  };

  window.openCompletarPanel = function(id) {
    const e = ENVIOS[id];
    if (!e) return;
    document.getElementById('cp-envio-id').value = id;
    // Pre-rellenar campos si ya tienen datos
    const selAg = document.getElementById('cp-agencia');
    selAg.value = e.agencia_id || '';
    document.getElementById('cp-dni').value          = e.destinatario_dni || '';
    document.getElementById('cp-destinatario').value = e.destinatario     || '';
    // Si ya hay destinatario guardado, mostrar bloqueado
    if (e.destinatario) {
      document.getElementById('cp-destinatario').readOnly = true;
      document.getElementById('cp-destinatario').style.background = '#f9fafb';
      document.getElementById('cp-destinatario').style.color = '#374151';
      document.getElementById('cp-destinatario').style.cursor = 'not-allowed';
    }
    // Reset sede (ambos modos)
    _cpSedeAll = []; _cpSedeSug = null;
    _cpEsOlva = !!e.is_olva; _cpTiendaPre = e.tienda_destino || '';
    cpResetTienda();
    document.getElementById('cp-sede-val').value    = '';
    document.getElementById('cp-sede-search').value = e.sede_registro || '';
    var _sm = document.getElementById('cp-sede-manual'); if (_sm) _sm.value = '';
    var _dd = document.getElementById('cp-direccion');   if (_dd) _dd.value = '';
    document.getElementById('cp-field-sede-manual').style.display = 'none';
    document.getElementById('cp-field-direccion').style.display   = 'none';
    document.getElementById('cp-sugerida-chip').style.display = 'none';
    document.getElementById('cp-sede-drop').classList.remove('open');
    document.getElementById('cp-msg').style.display = 'none';
    document.getElementById('cp-msg').className = 'cp-msg';
    document.getElementById('cp-dni-msg').style.display = 'none';
    document.getElementById('cp-destinatario').readOnly = true;
    document.getElementById('cp-destinatario').style.background = '#f9fafb';
    document.getElementById('cp-destinatario').style.color = '#6b7280';
    document.getElementById('cp-destinatario').style.cursor = 'not-allowed';
    // Domicilio (Lima): pre-cargar dirección, referencia y el punto guardado
    var esDomE = (CP_DOMICILIO_ID && e.agencia_id === CP_DOMICILIO_ID);
    document.getElementById('cp-dom-direccion').value = esDomE ? (e.direccion || '') : '';
    document.getElementById('cp-dom-ref').value       = esDomE ? (e.referencia || '') : '';
    document.getElementById('cp-dom-lat').value       = (esDomE && e.lat != null) ? e.lat : '';
    document.getElementById('cp-dom-lng').value       = (esDomE && e.lng != null) ? e.lng : '';
    document.getElementById('cp-dom-coords').textContent = (esDomE && e.lat != null) ? '✓ Punto guardado' : '';
    // Si ya hay agencia pre-seleccionada, cargar sedes/dirección según su modo
    if (e.agencia_id) {
      cpCargarSedes(e.agencia_id, id, e.sede_registro, e.direccion);
    } else {
      document.getElementById('cp-field-sede').style.display = 'none';
      document.getElementById('cp-field-sede-manual').style.display = 'none';
      document.getElementById('cp-field-direccion').style.display = 'none';
    }
    // Chip "usar último envío" solo si el envío aún no tiene agencia (form vacío)
    cpCargarUltimoEnvio(id, e.agencia_id);
    closeAll();
    overlay.classList.add('open');
    cpPanel.classList.add('open');
    cpPanel.setAttribute('aria-hidden','false');
    document.getElementById('cp-panel-close').focus();
    document.body.style.overflow = 'hidden';
    _pushSheet();
  };

  // DNI auto-lookup
  document.getElementById('cp-dni').addEventListener('input', function() {
    const dni = this.value.replace(/\D/g,'');
    const msg  = document.getElementById('cp-dni-msg');
    const dest = document.getElementById('cp-destinatario');
    if (dni.length < 8) {
      msg.style.display = 'none';
      dest.value = '';
      return;
    }
    if (dni.length === 8) {
      document.getElementById('cp-dni-spinner').style.display = '';
      msg.style.display = 'none';
      dest.value = '';
      fetch(PORTAL_URL + '/ajax_dni_portal.php?token=' + encodeURIComponent(PORTAL_TOKEN) + '&dni=' + dni)
        .then(function(r){ return r.json(); })
        .then(function(res) {
          document.getElementById('cp-dni-spinner').style.display = 'none';
          if (res.ok) {
            dest.value = res.nombre_completo;
            dest.readOnly = true;
            dest.style.background = '#f9fafb';
            dest.style.color = '#374151';
            dest.style.cursor = 'not-allowed';
            msg.textContent = '✓ ' + res.nombre_completo;
            msg.style.color = '#166534'; msg.style.display = '';
          } else {
            dest.value = ''; dest.readOnly = false;
            dest.style.background = ''; dest.style.color = ''; dest.style.cursor = '';
            msg.textContent = res.msg || 'DNI no encontrado.';
            msg.style.color = '#991b1b'; msg.style.display = '';
          }
        })
        .catch(function() {
          document.getElementById('cp-dni-spinner').style.display = 'none';
          dest.readOnly = false;
          dest.style.background = ''; dest.style.color = ''; dest.style.cursor = '';
          msg.textContent = 'Error de conexión.';
          msg.style.color = '#991b1b'; msg.style.display = '';
        });
    }
  });

  document.getElementById('cp-agencia').addEventListener('change', function() {
    const agId    = parseInt(this.value) || 0;
    const envioId = parseInt(document.getElementById('cp-envio-id').value) || 0;
    document.getElementById('cp-sede-val').value    = '';
    document.getElementById('cp-sede-search').value = '';
    var _sm = document.getElementById('cp-sede-manual'); if (_sm) _sm.value = '';
    var _dd = document.getElementById('cp-direccion');   if (_dd) _dd.value = '';
    document.getElementById('cp-sugerida-chip').style.display = 'none';
    _cpTiendaPre = '';  // al cambiar de courier ya no aplica la tienda previa
    if (agId) {
      cpCargarSedes(agId, envioId, '', '');
    } else {
      _cpEsOlva = false; cpResetTienda();
      document.getElementById('cp-field-sede').style.display = 'none';
      document.getElementById('cp-field-sede-manual').style.display = 'none';
      document.getElementById('cp-field-direccion').style.display = 'none';
    }
  });

  var CP_DOMICILIO_ID = <?= (int) ($agencia_domicilio_id ?? 0) ?>;

  // ── Mapa de entrega a domicilio (Leaflet + OpenStreetMap) ──
  var _cpMap = null, _cpMarker = null;
  function cpSetPunto(lat, lng, centrar) {
    document.getElementById('cp-dom-lat').value = lat.toFixed(7);
    document.getElementById('cp-dom-lng').value = lng.toFixed(7);
    document.getElementById('cp-dom-coords').textContent = '✓ Punto marcado (' + lat.toFixed(5) + ', ' + lng.toFixed(5) + ')';
    if (_cpMarker) _cpMarker.setLatLng([lat, lng]);
    if (centrar && _cpMap) _cpMap.setView([lat, lng], 16);
  }
  function cpInitMapa() {
    if (typeof L === 'undefined') { return; }          // Leaflet aún no cargó
    if (_cpMap) {
      setTimeout(function(){ _cpMap.invalidateSize(); }, 150);
      var la = parseFloat(document.getElementById('cp-dom-lat').value),
          ln = parseFloat(document.getElementById('cp-dom-lng').value);
      if (!isNaN(la) && !isNaN(ln)) { _cpMarker.setLatLng([la, ln]); _cpMap.setView([la, ln], 16); }
      return;
    }
    var base = '<?= APP_URL ?>/assets/leaflet/images/';
    var icon = L.icon({ iconUrl: base+'marker-icon.png', iconRetinaUrl: base+'marker-icon-2x.png',
      shadowUrl: base+'marker-shadow.png', iconSize:[25,41], iconAnchor:[12,41], shadowSize:[41,41] });
    var hayPunto = !!document.getElementById('cp-dom-lat').value;
    var lat0 = parseFloat(document.getElementById('cp-dom-lat').value) || -12.0464;  // Lima
    var lng0 = parseFloat(document.getElementById('cp-dom-lng').value) || -77.0428;
    _cpMap = L.map('cp-mapa').setView([lat0, lng0], hayPunto ? 16 : 12);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
      { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(_cpMap);
    _cpMarker = L.marker([lat0, lng0], { draggable: true, icon: icon }).addTo(_cpMap);
    _cpMarker.on('dragend', function(){ var p = _cpMarker.getLatLng(); cpSetPunto(p.lat, p.lng, false); });
    _cpMap.on('click', function(e){ cpSetPunto(e.latlng.lat, e.latlng.lng, false); });
    if (hayPunto) cpSetPunto(lat0, lng0, false);
    setTimeout(function(){ _cpMap.invalidateSize(); }, 200);
  }
  document.addEventListener('click', function(e){
    if (!e.target.closest('#cp-dom-geo')) return;
    if (!navigator.geolocation) { alert('Tu navegador no permite la ubicación.'); return; }
    var btn = e.target.closest('#cp-dom-geo'); btn.disabled = true; btn.textContent = 'Buscando ubicación…';
    navigator.geolocation.getCurrentPosition(function(pos){
      btn.disabled = false; btn.textContent = '📍 Usar mi ubicación actual';
      cpSetPunto(pos.coords.latitude, pos.coords.longitude, true);
    }, function(){
      btn.disabled = false; btn.textContent = '📍 Usar mi ubicación actual';
      alert('No se pudo obtener tu ubicación. Toca el mapa para marcar el punto.');
    }, { enableHighAccuracy: true, timeout: 10000 });
  });

  // Buscar la dirección escrita y ubicarla en el mapa (Nominatim de OpenStreetMap,
  // gratis, sin API key). Solo al tocar "Buscar" (respeta el límite de la API).
  function cpGeocodificar() {
    var q = document.getElementById('cp-dom-direccion').value.trim();
    var box = document.getElementById('cp-dom-geores');
    if (q.length < 4) { alert('Escribe la dirección primero.'); return; }
    var btn = document.getElementById('cp-dom-buscar');
    btn.disabled = true; btn.textContent = 'Buscando…';
    var url = 'https://nominatim.openstreetmap.org/search?format=jsonv2&countrycodes=pe&limit=6&q=' + encodeURIComponent(q);
    fetch(url, { headers: { 'Accept-Language': 'es' } })
      .then(function(r){ return r.json(); })
      .then(function(items){
        btn.disabled = false; btn.textContent = '🔍 Buscar';
        if (!items || !items.length) {
          box.innerHTML = '<div style="padding:10px 12px;color:#64748b;font-size:13px">Sin resultados. Marca el punto tocando el mapa.</div>';
          box.style.display = 'block'; return;
        }
        box.innerHTML = items.map(function(it){
          return '<div class="cp-geo-opt" data-lat="'+it.lat+'" data-lng="'+it.lon+'" '
               + 'style="padding:9px 12px;font-size:13px;cursor:pointer;border-bottom:1px solid #f1f5f9">'
               + (it.display_name || '') + '</div>';
        }).join('');
        box.style.display = 'block';
        box.querySelectorAll('.cp-geo-opt').forEach(function(o){
          o.addEventListener('click', function(){
            cpInitMapa();
            cpSetPunto(parseFloat(this.dataset.lat), parseFloat(this.dataset.lng), true);
            box.style.display = 'none';
          });
          o.addEventListener('mouseenter', function(){ this.style.background = '#f1f5f9'; });
          o.addEventListener('mouseleave', function(){ this.style.background = ''; });
        });
      })
      .catch(function(){
        btn.disabled = false; btn.textContent = '🔍 Buscar';
        alert('No se pudo buscar la dirección. Marca el punto tocando el mapa.');
      });
  }
  document.addEventListener('click', function(e){ if (e.target.closest('#cp-dom-buscar')) cpGeocodificar(); });
  document.addEventListener('keydown', function(e){
    if (e.key === 'Enter' && e.target && e.target.id === 'cp-dom-direccion') { e.preventDefault(); cpGeocodificar(); }
  });

  function cpCargarSedes(agId, envioId, preVal, preDir) {
    const dom = document.getElementById('cp-domicilio');
    // ── Entrega a domicilio (Lima): mostrar el mapa, ocultar todo lo de courier ──
    if (dom && CP_DOMICILIO_ID && parseInt(agId, 10) === CP_DOMICILIO_ID) {
      document.getElementById('cp-field-sede').style.display = 'none';
      document.getElementById('cp-field-sede-manual').style.display = 'none';
      document.getElementById('cp-field-direccion').style.display = 'none';
      document.getElementById('cp-field-tienda').style.display = 'none';
      _cpEsOlva = false; cpResetTienda();
      dom.style.display = '';
      cpInitMapa();
      return;
    }
    if (dom) dom.style.display = 'none';

    const fieldSede   = document.getElementById('cp-field-sede');
    const fieldManual = document.getElementById('cp-field-sede-manual');
    const fieldDir    = document.getElementById('cp-field-direccion');
    const inp         = document.getElementById('cp-sede-search');
    // Por defecto ocultar ambos modos hasta saber sede_modo
    fieldSede.style.display = 'none';
    if (fieldManual) fieldManual.style.display = 'none';
    if (fieldDir)    fieldDir.style.display    = 'none';
    inp.disabled = true; inp.placeholder = 'Cargando sedes...';
    const url = PORTAL_URL + '/ajax_sedes_portal.php?token=' + encodeURIComponent(PORTAL_TOKEN)
              + '&agencia_id=' + agId + '&envio_id=' + (envioId || 0);
    fetch(url)
      .then(function(r){ return r.json(); })
      .then(function(data) {
        const modo = data.sede_modo || 'manual';
        if (modo === 'api') {
          // ── Integración: buscador de sedes ──
          fieldSede.style.display = '';
          inp.disabled = false; inp.placeholder = 'Buscar sede...';
          _cpSedeAll = data.sedes || [];
          if (preVal) {
            inp.value = preVal;
            document.getElementById('cp-sede-val').value = preVal;
          }
          if (data.sede_sugerida && !preVal) {
            _cpSedeSug = data.sede_sugerida;
            document.getElementById('cp-sugerida-texto').textContent = data.sede_sugerida;
            document.getElementById('cp-sugerida-chip').style.display = 'inline-flex';
          }
          cpInitSedeDropdown();
          // Olva: punto de recojo tras la sede (si ya hay sede guardada, cargar y preseleccionar)
          _cpEsOlva = cpDetectOlva();
          if (_cpEsOlva && preVal) {
            var _m = _cpSedeAll.find(function(s){ return s.valor === preVal; });
            cpLoadTiendas(_m ? _m.ubigeo : '', _cpTiendaPre);
          } else {
            cpResetTienda();
          }
        } else {
          // ── Manual: sede de texto libre + dirección ──
          _cpEsOlva = false; cpResetTienda();
          if (fieldManual) fieldManual.style.display = '';
          if (fieldDir)    fieldDir.style.display    = '';
          const sm = document.getElementById('cp-sede-manual');
          const dd = document.getElementById('cp-direccion');
          if (sm) sm.value = preVal || '';
          if (dd) dd.value = preDir || '';
        }
      })
      .catch(function() { inp.disabled = false; inp.placeholder = 'Error al cargar'; });
  }

  window.cpUsarSugerida = function() {
    if (!_cpSedeSug) return;
    document.getElementById('cp-sede-val').value    = _cpSedeSug;
    document.getElementById('cp-sede-search').value = _cpSedeSug;
    document.getElementById('cp-sugerida-chip').style.display = 'none';
    document.getElementById('cp-sede-drop').classList.remove('open');
    if (_cpEsOlva) { var _m=_cpSedeAll.find(function(s){return s.valor===_cpSedeSug;}); cpLoadTiendas(_m?_m.ubigeo:'', ''); }
  };

  function cpInitSedeDropdown() {
    const inp  = document.getElementById('cp-sede-search');
    const drop = document.getElementById('cp-sede-drop');
    const val  = document.getElementById('cp-sede-val');
    inp.oninput = function() {
      cpRenderSedes(inp.value.trim());
      if (!inp.value.trim()) val.value = '';
      drop.classList.add('open');
    };
    inp.onfocus = function() { cpRenderSedes(inp.value.trim()); drop.classList.add('open'); };
    inp.onkeydown = function(ev) {
      const items = drop.querySelectorAll('.cp-sede-opt');
      const cur   = drop.querySelector('.cp-sede-opt.focused');
      if (ev.key==='ArrowDown'){ ev.preventDefault(); const n=cur?cur.nextElementSibling:items[0]; if(n){cur?.classList.remove('focused');n.classList.add('focused');n.scrollIntoView({block:'nearest'});} }
      else if (ev.key==='ArrowUp'){ ev.preventDefault(); const p=cur?cur.previousElementSibling:items[items.length-1]; if(p){cur?.classList.remove('focused');p.classList.add('focused');p.scrollIntoView({block:'nearest'});} }
      else if (ev.key==='Enter'){ ev.preventDefault(); if(cur) cur.click(); }
      else if (ev.key==='Escape'){ drop.classList.remove('open'); }
    };
    document.addEventListener('click', function(ev) {
      if (!document.getElementById('cp-sede-wrap')?.contains(ev.target)) drop.classList.remove('open');
    }, { capture: true });
  }

  function cpRenderSedes(q) {
    const drop = document.getElementById('cp-sede-drop');
    const val  = document.getElementById('cp-sede-val');
    const qLow = q.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g,'');
    const matches = q ? _cpSedeAll.filter(function(s){
      return s.label.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g,'').includes(qLow);
    }) : _cpSedeAll;
    if (!matches.length) { drop.innerHTML = '<div class="cp-sede-empty">Sin resultados</div>'; return; }
    drop.innerHTML = matches.slice(0,80).map(function(s) {
      const hi = q ? s.label.replace(new RegExp('('+q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')+')','gi'),'<mark>$1</mark>') : s.label;
      return '<div class="cp-sede-opt" data-val="'+s.valor.replace(/"/g,'&quot;')+'" data-ubigeo="'+(s.ubigeo||'')+'">'+hi+'</div>';
    }).join('');
    drop.querySelectorAll('.cp-sede-opt').forEach(function(opt) {
      opt.addEventListener('click', function() {
        val.value = this.dataset.val;
        document.getElementById('cp-sede-search').value = this.dataset.val;
        drop.classList.remove('open');
        document.getElementById('cp-sugerida-chip').style.display = 'none';
        // Olva: cargar los puntos de recojo del distrito elegido
        if (_cpEsOlva) cpLoadTiendas(this.dataset.ubigeo, '');
      });
    });
  }

  window.submitCompletarEnvio = function() {
    const envioId   = parseInt(document.getElementById('cp-envio-id').value) || 0;
    const agenciaId = parseInt(document.getElementById('cp-agencia').value) || 0;
    // Modo manual visible → usar sede de texto libre; si no, la sede del buscador (API)
    const manualOn  = document.getElementById('cp-field-sede-manual').style.display !== 'none';
    const sede      = manualOn
      ? document.getElementById('cp-sede-manual').value.trim()
      : document.getElementById('cp-sede-val').value.trim();
    let direccion   = manualOn ? document.getElementById('cp-direccion').value.trim() : '';
    const dni       = document.getElementById('cp-dni').value.replace(/\D/g,'');
    const dest      = document.getElementById('cp-destinatario').value.trim();
    const msg       = document.getElementById('cp-msg');
    const btn       = document.getElementById('cp-btn-save');

    // Entrega a domicilio (Lima): dirección + referencia + punto del mapa
    const esDom = CP_DOMICILIO_ID && agenciaId === CP_DOMICILIO_ID;
    let refDom = '', latDom = '', lngDom = '';
    if (esDom) {
      direccion = document.getElementById('cp-dom-direccion').value.trim();
      refDom    = document.getElementById('cp-dom-ref').value.trim();
      latDom    = document.getElementById('cp-dom-lat').value;
      lngDom    = document.getElementById('cp-dom-lng').value;
      if (!direccion) { showCpMsg('Escribe la dirección de entrega.', false); return; }
      if (!latDom || !lngDom) { showCpMsg('Marca el punto exacto en el mapa.', false); return; }
    }

    // Punto de recojo Olva (select o texto libre según cuál esté visible)
    let tienda = '';
    if (document.getElementById('cp-field-tienda').style.display !== 'none') {
      const tman = document.getElementById('cp-tienda-manual');
      tienda = (tman && tman.style.display !== 'none')
        ? tman.value.trim()
        : document.getElementById('cp-tienda-val').value.trim();
    }

    if (!agenciaId) { showCpMsg('Selecciona un courier.', false); return; }
    if (_cpEsOlva && !tienda) { showCpMsg('Selecciona el distrito y el punto de recojo de Olva.', false); return; }
    if (dni && dni.length !== 8) { showCpMsg('El DNI debe tener 8 dígitos.', false); return; }

    btn.disabled = true; btn.textContent = 'Guardando...';
    msg.style.display = 'none';

    const fd = new FormData();
    fd.append('token',            PORTAL_TOKEN);
    fd.append('envio_id',         envioId);
    fd.append('agencia_envio_id', agenciaId);
    fd.append('sede_registro',    sede);
    fd.append('tienda_destino',   tienda);
    fd.append('direccion_destino',direccion);
    fd.append('destinatario',     dest);
    fd.append('destinatario_dni', dni);
    fd.append('referencia_direccion', refDom);
    fd.append('lat',              latDom);
    fd.append('lng',              lngDom);

    fetch(PORTAL_URL + '/ajax_actualizar_envio.php', { method:'POST', body: fd })
      .then(function(r){ return r.json(); })
      .then(function(res) {
        btn.disabled = false; btn.textContent = 'Guardar';
        if (res.ok) {
          showCpMsg(res.msg || 'Datos guardados correctamente.', true);
          // Actualizar datos en memoria y en la tarjeta
          if (ENVIOS[envioId]) {
            ENVIOS[envioId].agencia_id      = agenciaId;
            ENVIOS[envioId].agencia         = res.agencia || '';
            ENVIOS[envioId].sede_registro   = sede;
            ENVIOS[envioId].tienda_destino  = tienda;
            ENVIOS[envioId].direccion       = direccion;
            ENVIOS[envioId].destinatario    = dest;
            ENVIOS[envioId].destinatario_dni= dni;
          }
          // Refrescar el texto del courier en la tarjeta
          const card = document.querySelector('.env-card[data-env-id="'+envioId+'"] .env-agency-name');
          if (card && res.agencia) card.textContent = res.agencia;
          setTimeout(function(){ closeAll(); }, 1400);
        } else {
          showCpMsg(res.msg || 'Error al guardar.', false);
        }
      })
      .catch(function() {
        btn.disabled = false; btn.textContent = 'Guardar';
        showCpMsg('Error de conexión. Intenta de nuevo.', false);
      });
  };

  function showCpMsg(txt, ok) {
    const el = document.getElementById('cp-msg');
    el.textContent = txt;
    el.className = 'cp-msg ' + (ok ? 'ok' : 'err');
    el.style.display = '';
  }

  /* ── Wire up ── */
  document.querySelectorAll('.cert-card').forEach(function(card) {
    function h(){ openCertPanel(card.dataset.certId); }
    card.addEventListener('click', h);
    card.addEventListener('keydown', function(e){ if(e.key==='Enter'||e.key===' '){e.preventDefault();h();} });
  });
  document.querySelectorAll('.env-card:not(.env-card-pending)').forEach(function(card) {
    function h(){ openEnvPanel(card.dataset.envId); }
    card.addEventListener('click', h);
    card.addEventListener('keydown', function(e){ if(e.key==='Enter'||e.key===' '){e.preventDefault();h();} });
  });

  overlay.addEventListener('click', closeAll);
  document.getElementById('panel-close').addEventListener('click', closeAll);
  document.getElementById('env-panel-close').addEventListener('click', closeAll);
  document.getElementById('cp-panel-close').addEventListener('click', closeAll);
  document.addEventListener('keydown', function(e){ if(e.key==='Escape') closeAll(); });
})();
</script>
<?php endif; ?>

</body>
</html>
