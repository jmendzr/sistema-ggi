<?php
// ── AJAX: Actividad Comercial ── (debe ir antes del layout)
if (isset($_GET['ajax']) && $_GET['ajax'] === 'ac') {
    require_once __DIR__ . '/../../core/helpers.php';
    require_login();
    $pdo = Database::get();
    $user = auth_user();
    $uid  = $user['id'];
    $mes_ac = $_GET['mes_ac'] ?? date('Y-m');
    if (!preg_match('/^\d{4}-\d{2}$/', $mes_ac)) $mes_ac = date('Y-m');
    // Usar rango de fechas en lugar de YEAR()/MONTH() para que MySQL pueda usar
    // el índice idx_cot_fecha_emision en lugar de hacer un full scan.
    $ac_rango_ini = $mes_ac . '-01';
    $ac_rango_fin = date('Y-m-d', strtotime('+1 month', strtotime($ac_rango_ini)));

    $s = $pdo->prepare("SELECT COUNT(*) FROM cotizaciones WHERE deleted_at IS NULL AND fecha_emision >= ? AND fecha_emision < ?");
    $s->execute([$ac_rango_ini, $ac_rango_fin]); $elaboradas = (int)$s->fetchColumn();

    $s = $pdo->prepare("SELECT COUNT(*) FROM cotizaciones WHERE deleted_at IS NULL AND estado='aceptada' AND fecha_emision >= ? AND fecha_emision < ?");
    $s->execute([$ac_rango_ini, $ac_rango_fin]); $ganadas = (int)$s->fetchColumn();

    $s = $pdo->prepare("SELECT COUNT(DISTINCT p.cotizacion_id) FROM pagos_cliente p JOIN cotizaciones cot ON cot.id=p.cotizacion_id WHERE p.deleted_at IS NULL AND p.estado='validado' AND cot.deleted_at IS NULL AND cot.fecha_emision >= ? AND cot.fecha_emision < ?");
    $s->execute([$ac_rango_ini, $ac_rango_fin]); $pagadas = (int)$s->fetchColumn();

    $s = $pdo->prepare("SELECT COUNT(*) FROM cotizaciones cot WHERE cot.deleted_at IS NULL AND cot.estado='aceptada' AND cot.fecha_emision >= ? AND cot.fecha_emision < ? AND NOT EXISTS (SELECT 1 FROM pagos_cliente p WHERE p.cotizacion_id=cot.id AND p.deleted_at IS NULL AND p.estado='validado')");
    $s->execute([$ac_rango_ini, $ac_rango_fin]); $deudores = (int)$s->fetchColumn();

    header('Content-Type: application/json');
    echo json_encode(compact('elaboradas','ganadas','pagadas','deudores'));
    exit;
}

$page_title  = 'Dashboard';
$active_menu = 'dashboard';
require_once __DIR__ . '/../../templates/layout.php';

$pdo  = Database::get();
$rol  = $user['rol'];
$uid  = $user['id'];

// ── Ranking de asesores (visible para todos los roles) ──
$ranking_asesores = $pdo->query("
    SELECT u.id, u.nombre, u.apellido, u.foto,
           COALESCE(elab.count, 0)  AS elaboradas,
           COALESCE(gan.count, 0)   AS propuestas,
           COALESCE(gan.total, 0)   AS monto_total,
           COALESCE(cli.count, 0)   AS clientes_activos,
           COALESCE(bon.total, 0)   AS bonos_mes,
           COALESCE(pag.count, 0)   AS pagadas,
           COALESCE(pend.count, 0)  AS pendientes
    FROM usuarios u
    LEFT JOIN (
        SELECT ejecutivo_id, COUNT(*) AS count
        FROM cotizaciones
        WHERE deleted_at IS NULL
          AND fecha_emision >= DATE_FORMAT(CURDATE(),'%Y-%m-01')
          AND fecha_emision <  DATE_FORMAT(CURDATE(),'%Y-%m-01') + INTERVAL 1 MONTH
        GROUP BY ejecutivo_id
    ) elab ON elab.ejecutivo_id = u.id
    LEFT JOIN (
        SELECT ejecutivo_id, COUNT(*) AS count, SUM(monto_total) AS total
        FROM cotizaciones
        WHERE deleted_at IS NULL
          AND estado='aceptada'
          AND fecha_emision >= DATE_FORMAT(CURDATE(),'%Y-%m-01')
          AND fecha_emision <  DATE_FORMAT(CURDATE(),'%Y-%m-01') + INTERVAL 1 MONTH
        GROUP BY ejecutivo_id
    ) gan ON gan.ejecutivo_id = u.id
    LEFT JOIN (
        SELECT ejecutivo_id, COUNT(*) AS count
        FROM clientes
        WHERE deleted_at IS NULL AND estado='activo'
        GROUP BY ejecutivo_id
    ) cli ON cli.ejecutivo_id = u.id
    LEFT JOIN (
        SELECT be.ejecutivo_id, SUM(be.monto) AS total
        FROM bonos_ejecutivos be
        JOIN normas_iso n ON n.id = be.norma_iso_id
        WHERE be.created_at >= DATE_FORMAT(CURDATE(),'%Y-%m-01')
          AND be.created_at <  DATE_FORMAT(CURDATE(),'%Y-%m-01') + INTERVAL 1 MONTH
        GROUP BY be.ejecutivo_id
    ) bon ON bon.ejecutivo_id = u.id
    LEFT JOIN (
        SELECT cot.ejecutivo_id, COUNT(DISTINCT p.cotizacion_id) AS count
        FROM pagos_cliente p
        JOIN cotizaciones cot ON cot.id = p.cotizacion_id
        WHERE p.deleted_at IS NULL AND p.estado = 'validado'
          AND cot.deleted_at IS NULL
          AND cot.fecha_emision >= DATE_FORMAT(CURDATE(),'%Y-%m-01')
          AND cot.fecha_emision <  DATE_FORMAT(CURDATE(),'%Y-%m-01') + INTERVAL 1 MONTH
        GROUP BY cot.ejecutivo_id
    ) pag ON pag.ejecutivo_id = u.id
    LEFT JOIN (
        SELECT cot.ejecutivo_id, COUNT(*) AS count
        FROM cotizaciones cot
        WHERE cot.deleted_at IS NULL
          AND cot.estado = 'aceptada'
          AND cot.fecha_emision >= DATE_FORMAT(CURDATE(),'%Y-%m-01')
          AND cot.fecha_emision <  DATE_FORMAT(CURDATE(),'%Y-%m-01') + INTERVAL 1 MONTH
          AND NOT EXISTS (
              SELECT 1 FROM pagos_cliente p
              WHERE p.cotizacion_id = cot.id AND p.deleted_at IS NULL AND p.estado = 'validado'
          )
        GROUP BY cot.ejecutivo_id
    ) pend ON pend.ejecutivo_id = u.id
    WHERE u.activo=1 AND u.rol='ejecutivo_ventas'
    ORDER BY monto_total DESC, propuestas DESC
")->fetchAll();

// ── Queries según rol ──
if ($rol === 'ejecutivo_ventas') {

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cotizaciones WHERE deleted_at IS NULL AND ejecutivo_id=? AND estado IN ('borrador','enviada')"); $stmt->execute([$uid]); $s_mis_activas = (int)$stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cotizaciones WHERE deleted_at IS NULL AND ejecutivo_id=? AND estado='enviada'"); $stmt->execute([$uid]); $s_mis_enviadas = (int)$stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM clientes WHERE deleted_at IS NULL AND ejecutivo_id=? AND estado='activo'"); $stmt->execute([$uid]); $s_mis_clientes = (int)$stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cotizaciones WHERE deleted_at IS NULL AND ejecutivo_id=? AND estado='enviada' AND fecha_vencimiento IS NOT NULL AND fecha_vencimiento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 5 DAY)"); $stmt->execute([$uid]); $s_por_vencer = (int)$stmt->fetchColumn();

    // Bonos pendientes — JOIN normas_iso para excluir filas huérfanas (normas eliminadas)
    // COUNT(DISTINCT cotizacion_id) para contar propuestas, no filas por norma
    $s_bonos_pendiente = 0.0;
    $s_bonos_count = 0;
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT be.cotizacion_id), COALESCE(SUM(be.monto),0)
            FROM bonos_ejecutivos be
            JOIN normas_iso n ON n.id = be.norma_iso_id
            WHERE be.ejecutivo_id=? AND be.estado='pendiente'
        ");
        $stmt->execute([$uid]);
        $row = $stmt->fetch(\PDO::FETCH_NUM);
        $s_bonos_count     = (int)$row[0];
        $s_bonos_pendiente = (float)$row[1];
    } catch (\PDOException $e) { /* tabla puede no existir */ }

    // ── Actividad Comercial (filtro por mes) ──
    $mes_ac = $_GET['mes_ac'] ?? date('Y-m');
    if (!preg_match('/^\d{4}-\d{2}$/', $mes_ac)) $mes_ac = date('Y-m');
    $ac_rango_ini = $mes_ac . '-01';
    $ac_rango_fin = date('Y-m-d', strtotime('+1 month', strtotime($ac_rango_ini)));

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cotizaciones WHERE deleted_at IS NULL AND fecha_emision >= ? AND fecha_emision < ?");
    $stmt->execute([$ac_rango_ini, $ac_rango_fin]); $ac_elaboradas = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cotizaciones WHERE deleted_at IS NULL AND estado='aceptada' AND fecha_emision >= ? AND fecha_emision < ?");
    $stmt->execute([$ac_rango_ini, $ac_rango_fin]); $ac_ganadas = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT p.cotizacion_id)
        FROM pagos_cliente p
        JOIN cotizaciones cot ON cot.id = p.cotizacion_id
        WHERE p.deleted_at IS NULL AND p.estado = 'validado'
          AND cot.deleted_at IS NULL
          AND cot.fecha_emision >= ? AND cot.fecha_emision < ?
    ");
    $stmt->execute([$ac_rango_ini, $ac_rango_fin]); $ac_pagadas = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM cotizaciones cot
        WHERE cot.deleted_at IS NULL
          AND cot.estado = 'aceptada'
          AND cot.fecha_emision >= ? AND cot.fecha_emision < ?
          AND NOT EXISTS (
              SELECT 1 FROM pagos_cliente p
              WHERE p.cotizacion_id = cot.id AND p.deleted_at IS NULL AND p.estado = 'validado'
          )
    ");
    $stmt->execute([$ac_rango_ini, $ac_rango_fin]); $ac_deudores = (int)$stmt->fetchColumn();

    // ── Tablero general de propuestas (read-only) ──
    $tablero_cotizaciones = $pdo->query("
        SELECT cot.numero, cot.estado, cot.monto_total, cot.moneda,
               cot.fecha_emision,
               COALESCE(cl.razon_social, cot.empresa_nombre) AS empresa,
               u.nombre AS asesor_nombre, u.apellido AS asesor_apellido
        FROM cotizaciones cot
        LEFT JOIN clientes cl ON cl.id = cot.cliente_id AND cl.deleted_at IS NULL
        LEFT JOIN usuarios u ON u.id = cot.ejecutivo_id
        WHERE cot.deleted_at IS NULL
        ORDER BY cot.created_at DESC LIMIT 50
    ")->fetchAll();

    $stmt = $pdo->prepare("
        SELECT cot.id, cot.numero, COALESCE(cl.razon_social, cot.empresa_nombre) AS empresa,
               DATEDIFF(CURDATE(), cot.fecha_emision) AS dias_sin_respuesta,
               cot.contacto_telefono
        FROM cotizaciones cot
        LEFT JOIN clientes cl ON cl.id = cot.cliente_id AND cl.deleted_at IS NULL
        LEFT JOIN cotizacion_seguimientos seg ON seg.id = (
            SELECT id FROM cotizacion_seguimientos WHERE cotizacion_id = cot.id ORDER BY created_at DESC LIMIT 1
        )
        WHERE cot.deleted_at IS NULL
          AND cot.ejecutivo_id = ?
          AND cot.estado = 'enviada'
          AND DATEDIFF(CURDATE(), cot.fecha_emision) >= 3
        ORDER BY dias_sin_respuesta DESC LIMIT 6
    ");
    $stmt->execute([$uid]);
    $seguimientos_pendientes = $stmt->fetchAll();

} elseif ($rol === 'gestor_logistico') {

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM envios WHERE deleted_at IS NULL AND agente_id=? AND fecha_recibido IS NULL AND fecha_envio IS NOT NULL"); $stmt->execute([$uid]); $s_mis_transito = (int)$stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM envios e JOIN estados_envio es ON es.id=e.estado_envio_id WHERE e.deleted_at IS NULL AND e.agente_id IS NULL AND e.fecha_recibido IS NULL AND e.fecha_envio IS NOT NULL"); $stmt->execute([]); $s_sin_asignar = (int)$stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM envios WHERE deleted_at IS NULL AND agente_id=? AND fecha_recibido IS NOT NULL AND fecha_recibido >= DATE_FORMAT(CURDATE(),'%Y-%m-01') AND fecha_recibido < DATE_FORMAT(CURDATE(),'%Y-%m-01') + INTERVAL 1 MONTH"); $stmt->execute([$uid]); $s_completados_mes = (int)$stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM envios WHERE deleted_at IS NULL AND agente_id=?"); $stmt->execute([$uid]); $s_mis_total = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT e.id, e.codigo_envio, e.fecha_envio,
               cl.razon_social, ag.nombre AS agencia,
               es.nombre AS estado_nombre, es.color_hex,
               DATEDIFF(CURDATE(), e.fecha_envio) AS dias_transito
        FROM envios e
        JOIN clientes cl ON cl.id = e.cliente_id
        JOIN agencias_envio ag ON ag.id = e.agencia_envio_id
        JOIN estados_envio es ON es.id = e.estado_envio_id
        WHERE e.agente_id = ? AND e.fecha_recibido IS NULL AND e.fecha_envio IS NOT NULL
        ORDER BY e.fecha_envio ASC LIMIT 8
    ");
    $stmt->execute([$uid]);
    $mis_envios_transito = $stmt->fetchAll();

    $stmt = $pdo->query("
        SELECT e.id, e.codigo_envio, e.fecha_envio,
               cl.razon_social, ag.nombre AS agencia,
               es.nombre AS estado_nombre, es.color_hex,
               DATEDIFF(CURDATE(), e.fecha_envio) AS dias_transito
        FROM envios e
        JOIN clientes cl ON cl.id = e.cliente_id
        JOIN agencias_envio ag ON ag.id = e.agencia_envio_id
        JOIN estados_envio es ON es.id = e.estado_envio_id
        WHERE e.agente_id IS NULL AND e.fecha_recibido IS NULL AND e.fecha_envio IS NOT NULL
        ORDER BY e.fecha_envio ASC LIMIT 5
    ");
    $envios_sin_asignar = $stmt->fetchAll();

} else {
    // Gerentes y otros: dashboard completo.
    // Las 8 tarjetas-contador son globales e idénticas para todo admin: se cachean
    // 60 s en un solo bloque (colapsa ~9 consultas a 0 en cache-hit). Los pagos
    // pendientes y las listas de abajo quedan EN VIVO (datos operativos sensibles).
    $stats = cache_remember('dash_stats_global', 60, function () {
        $pdo = Database::get();
        $est = (int)($pdo->query("SELECT id FROM estados_envio WHERE nombre='Por preparar' AND activo=1 LIMIT 1")->fetchColumn() ?: 0);
        return [
            'clientes'             => (int)$pdo->query("SELECT COUNT(*) FROM clientes WHERE deleted_at IS NULL AND estado = 'activo'")->fetchColumn(),
            // Solo 'vigente' (no 'por_vencer', que tiene su propio card) para que el link ?estado=vigente coincida
            'certificados'         => (int)$pdo->query("SELECT COUNT(*) FROM certificados WHERE deleted_at IS NULL AND estado = 'vigente'")->fetchColumn(),
            'cert_por_vencer'      => (int)$pdo->query("SELECT COUNT(*) FROM certificados WHERE deleted_at IS NULL AND estado = 'por_vencer'")->fetchColumn(),
            'cert_vencidos'        => (int)$pdo->query("SELECT COUNT(*) FROM certificados WHERE deleted_at IS NULL AND estado = 'vencido'")->fetchColumn(),
            '_est_por_preparar_id' => $est,
            'envios_por_preparar'  => $est
                ? (int)$pdo->query("SELECT COUNT(*) FROM envios WHERE deleted_at IS NULL AND estado_envio_id = $est")->fetchColumn()
                : 0,
            'auditorias_proximas'  => (int)$pdo->query("SELECT COUNT(*) FROM v_auditorias_proximas")->fetchColumn(),
            // Solo 'enviada' para que coincida exactamente con el link ?estado=enviada
            'cotizaciones_activas' => (int)$pdo->query("SELECT COUNT(*) FROM cotizaciones WHERE deleted_at IS NULL AND estado = 'enviada'")->fetchColumn(),
        ];
    });
    $_est_por_preparar_id = (int)$stats['_est_por_preparar_id'];

    // ── Pagos pendientes ──
    $_pp_row = $pdo->query("
        SELECT COUNT(*) AS cnt,
               SUM(CASE WHEN p.moneda='PEN' THEN p.monto ELSE 0 END) AS total_pen,
               SUM(CASE WHEN p.moneda='USD' THEN p.monto ELSE 0 END) AS total_usd
        FROM pagos_cliente p
        WHERE p.deleted_at IS NULL
          AND p.estado IN ('pendiente_voucher','pendiente_validacion')
    ")->fetch();
    $stats['pagos_pendientes'] = (int)($_pp_row['cnt'] ?? 0);
    $_pp_monto_pen = (float)($_pp_row['total_pen'] ?? 0);
    $_pp_monto_usd = (float)($_pp_row['total_usd'] ?? 0);

    $pagos_pend_list = $pdo->query("
        SELECT p.id, p.estado, p.monto, p.moneda,
               DATEDIFF(CURDATE(), DATE(p.created_at)) AS dias_espera,
               c.razon_social AS empresa,
               cot.numero AS cot_numero
        FROM pagos_cliente p
        JOIN clientes c   ON c.id   = p.cliente_id AND c.deleted_at IS NULL
        JOIN cotizaciones cot ON cot.id = p.cotizacion_id AND cot.deleted_at IS NULL
        WHERE p.deleted_at IS NULL
          AND p.estado IN ('pendiente_voucher','pendiente_validacion')
        ORDER BY p.created_at ASC
        LIMIT 8
    ")->fetchAll();

    $cert_vencer     = $pdo->query("SELECT * FROM v_certificados_por_vencer LIMIT 5")->fetchAll();
    $aud_proximas    = $pdo->query("SELECT * FROM v_auditorias_proximas LIMIT 5")->fetchAll();
    // Certificados por generar: propuestas aceptadas con fecha requerida de hoy en adelante.
    $cert_por_generar = $pdo->query("
        SELECT cot.id, cot.numero, cl.razon_social AS empresa,
               cot.fecha_requerida_certificados AS fecha,
               DATEDIFF(cot.fecha_requerida_certificados, CURDATE()) AS dias
        FROM cotizaciones cot
        JOIN clientes cl ON cl.id = cot.cliente_id
        WHERE cot.estado = 'aceptada' AND cot.deleted_at IS NULL
          AND cot.fecha_requerida_certificados IS NOT NULL
          AND cot.fecha_requerida_certificados >= CURDATE()
        ORDER BY cot.fecha_requerida_certificados ASC
        LIMIT 8
    ")->fetchAll();
    $envios_pend     = $_est_por_preparar_id ? $pdo->query("
        SELECT e.id, cl.razon_social, ag.nombre AS agencia,
               e.created_at,
               DATEDIFF(CURDATE(), DATE(e.created_at)) AS dias_espera
        FROM envios e
        JOIN clientes       cl ON cl.id = e.cliente_id AND cl.deleted_at IS NULL
        JOIN agencias_envio ag ON ag.id = e.agencia_envio_id
        WHERE e.deleted_at IS NULL
          AND e.estado_envio_id = $_est_por_preparar_id
        ORDER BY e.created_at ASC
        LIMIT 8
    ")->fetchAll() : [];
    $ultimos_clientes = $pdo->query("
        SELECT c.ruc, c.razon_social, c.estado, c.created_at, u.nombre AS creado_por
        FROM clientes c JOIN usuarios u ON u.id = c.creado_por
        WHERE c.deleted_at IS NULL
        ORDER BY c.created_at DESC LIMIT 5
    ")->fetchAll();

    // ── Ingresos por mes (últimos 6 meses, propuestas aceptadas) ──
    $ingresos_mes = $pdo->query("
        SELECT DATE_FORMAT(fecha_emision,'%Y-%m') AS mes,
               MONTH(fecha_emision) AS mes_num,
               YEAR(fecha_emision)  AS anio,
               SUM(monto_total)     AS total,
               COUNT(*)             AS cantidad
        FROM cotizaciones
        WHERE deleted_at IS NULL
          AND estado = 'aceptada'
          AND fecha_emision >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY mes, mes_num, anio
        ORDER BY mes ASC
    ")->fetchAll();

    $meses_es = ['','Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
    $max_ingreso = max(array_column($ingresos_mes,'total') ?: [1]);

    // Comparativa mes actual vs anterior (ingresos)
    $ingreso_actual = 0; $ingreso_anterior = 0; $cot_actual = 0;
    $mes_actual_key = date('Y-m');
    $mes_ant_key    = date('Y-m', strtotime('first day of -1 month'));
    foreach ($ingresos_mes as $m) {
        if ($m['mes'] === $mes_actual_key)  { $ingreso_actual   = (float)$m['total']; $cot_actual = (int)$m['cantidad']; }
        if ($m['mes'] === $mes_ant_key)     { $ingreso_anterior = (float)$m['total']; }
    }
    $delta_pct = $ingreso_anterior > 0
        ? round((($ingreso_actual - $ingreso_anterior) / $ingreso_anterior) * 100)
        : null;

    // ── Comparativas mensuales para stat cards ──
    $_d_mes_ini     = date('Y-m-01');
    $_d_mes_fin     = date('Y-m-01', strtotime('+1 month'));
    $_d_mes_ant_ini = date('Y-m-01', strtotime('-1 month'));

    // Nuevos clientes registrados este mes vs mes anterior
    $_nc_mes = (int)$pdo->query("SELECT COUNT(*) FROM clientes WHERE deleted_at IS NULL AND created_at >= '$_d_mes_ini' AND created_at < '$_d_mes_fin'")->fetchColumn();
    $_nc_ant = (int)$pdo->query("SELECT COUNT(*) FROM clientes WHERE deleted_at IS NULL AND created_at >= '$_d_mes_ant_ini' AND created_at < '$_d_mes_ini'")->fetchColumn();
    $_nc_delta = $_nc_ant > 0 ? round((($_nc_mes - $_nc_ant) / $_nc_ant) * 100) : null;

    // Nuevas propuestas creadas este mes vs mes anterior
    $_cot_mes = (int)$pdo->query("SELECT COUNT(*) FROM cotizaciones WHERE deleted_at IS NULL AND created_at >= '$_d_mes_ini' AND created_at < '$_d_mes_fin'")->fetchColumn();
    $_cot_ant = (int)$pdo->query("SELECT COUNT(*) FROM cotizaciones WHERE deleted_at IS NULL AND created_at >= '$_d_mes_ant_ini' AND created_at < '$_d_mes_ini'")->fetchColumn();
    $_cot_delta = $_cot_ant > 0 ? round((($_cot_mes - $_cot_ant) / $_cot_ant) * 100) : null;

    // ── Renovaciones próximas (certificados vigentes por vencer en 90 días) ──
    $renovaciones = $pdo->query("
        SELECT cert.id, cl.id AS cliente_id, cl.razon_social, cl.contacto_telefono,
               n.codigo AS norma, cert.fecha_vencimiento,
               DATEDIFF(cert.fecha_vencimiento, CURDATE()) AS dias_restantes,
               cert.estado
        FROM certificados cert
        JOIN clientes cl  ON cl.id  = cert.cliente_id AND cl.deleted_at IS NULL
        JOIN normas_iso n ON n.id   = cert.norma_iso_id
        WHERE cert.deleted_at IS NULL
          AND cert.fecha_vencimiento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)
          AND cert.estado IN ('vigente','por_vencer')
        ORDER BY cert.fecha_vencimiento ASC
        LIMIT 12
    ")->fetchAll();

    // Conteos por urgencia
    $renov_30 = count(array_filter($renovaciones, fn($r) => $r['dias_restantes'] <= 30));
    $renov_60 = count(array_filter($renovaciones, fn($r) => $r['dias_restantes'] > 30 && $r['dias_restantes'] <= 60));
    $renov_90 = count(array_filter($renovaciones, fn($r) => $r['dias_restantes'] > 60));

}

// ── Cumpleaños del equipo (TODOS los roles, ordenado por proximidad) ──
// Dato global e idéntico para todos; se calcula una sola vez fuera del switch
// de rol para que el panel se muestre en el dashboard de cualquier usuario.
$cumpleanos_proximos = $pdo->query("
    SELECT id, nombre, apellido, rol, foto, fecha_cumpleanos,
           MONTH(fecha_cumpleanos) AS mes, DAY(fecha_cumpleanos) AS dia,
           TIMESTAMPDIFF(YEAR, fecha_cumpleanos, CURDATE()) AS edad_actual,
           DATEDIFF(
               CASE
                 WHEN DATE(CONCAT(YEAR(CURDATE()),'-',LPAD(MONTH(fecha_cumpleanos),2,'0'),'-',LPAD(DAY(fecha_cumpleanos),2,'0'))) >= CURDATE()
                 THEN DATE(CONCAT(YEAR(CURDATE()),'-',LPAD(MONTH(fecha_cumpleanos),2,'0'),'-',LPAD(DAY(fecha_cumpleanos),2,'0')))
                 ELSE DATE(CONCAT(YEAR(CURDATE())+1,'-',LPAD(MONTH(fecha_cumpleanos),2,'0'),'-',LPAD(DAY(fecha_cumpleanos),2,'0')))
               END,
               CURDATE()
           ) AS dias_para_cumple
    FROM usuarios
    WHERE activo = 1 AND fecha_cumpleanos IS NOT NULL
    ORDER BY dias_para_cumple ASC
")->fetchAll();
?>

<style>
  /* ── Stat cards ── */
  .stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 16px; margin-bottom: 28px;
  }
  .stat-card {
    background: var(--gris-card); border-radius: 12px;
    border: 1px solid var(--gris-borde);
    padding: 20px; position: relative; overflow: hidden;
    transition: box-shadow 0.2s, transform 0.2s;
    animation: fadeUp 0.5s ease both;
  }
  .stat-card:hover { box-shadow: 0 6px 24px rgba(26,58,143,0.1); transform: translateY(-2px); }
  a.stat-card { display: block; color: inherit; }
  a.stat-card:hover { box-shadow: 0 8px 28px rgba(26,58,143,0.14); transform: translateY(-3px); border-color: var(--card-color, var(--azul)); }
  a.stat-card:hover .stat-value { color: var(--card-color, var(--azul)); }
  .stat-card::before {
    content: ''; position: absolute;
    top: 0; left: 0; right: 0; height: 3px;
    background: var(--card-color, var(--azul));
  }
  .stat-icon {
    width: 40px; height: 40px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    background: var(--card-bg, var(--azul-light));
    color: var(--card-color, var(--azul));
    margin-bottom: 12px;
  }
  .stat-value {
    font-family: 'Inter', sans-serif; font-size: 32px;
    font-weight: 700; color: var(--gris-dark); line-height: 1;
  }
  .stat-label { font-size: 12px; color: var(--gris-texto); margin-top: 4px; font-weight: 500; }
  .stat-sub { font-size: 11px; margin-top: 6px; }

  /* ── Grid de paneles ── */
  .panels-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px; margin-bottom: 20px;
  }
  .panel-full { grid-column: 1 / -1; }

  .panel {
    background: var(--gris-card); border-radius: 12px;
    border: 1px solid var(--gris-borde);
    overflow: hidden;
    animation: fadeUp 0.5s ease both;
  }
  .panel-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 16px 20px; border-bottom: 1px solid var(--gris-borde);
  }
  .panel-title {
    font-family: 'Inter', sans-serif; font-size: 14px;
    font-weight: 600; color: var(--gris-dark);
    display: flex; align-items: center; gap: 8px;
  }
  .panel-title svg { color: var(--gris-texto); }
  .panel-link { font-size: 12px; color: var(--gris-texto); text-decoration: none; font-weight: 500; }
  .panel-link:hover { text-decoration: underline; }

  /* ── Tabla compacta ── */
  .mini-table { width: 100%; border-collapse: collapse; }
  .mini-table th {
    font-size: 11px; font-weight: 600; color: var(--gris-texto);
    text-transform: uppercase; letter-spacing: 0.5px;
    padding: 10px 20px; text-align: left;
    background: var(--gris-bg); border-bottom: 1px solid var(--gris-borde);
  }
  .mini-table td {
    padding: 11px 20px; font-size: 13px;
    border-bottom: 1px solid var(--gris-borde); color: var(--gris-dark);
  }
  .mini-table tr:last-child td { border-bottom: none; }
  .mini-table tr:hover td { background: var(--gris-bg); }

  /* ── Badges: globales en layout_end.php (dark mode incluido) ── */

  /* ── Empty state ── */
  .empty-state {
    padding: 32px; text-align: center;
    color: var(--gris-texto); font-size: 13px;
  }
  .empty-state svg { margin-bottom: 8px; opacity: 0.3; }

  /* ── Accesos rápidos ── */
  .quick-grid {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
    gap: 12px; margin-bottom: 28px;
  }
  .quick-btn {
    display: flex; flex-direction: column;
    align-items: center; gap: 8px; padding: 16px 12px;
    background: var(--gris-card); border: 1.5px solid var(--gris-borde);
    border-radius: 12px; text-decoration: none;
    color: var(--gris-dark); font-size: 12px; font-weight: 600;
    text-align: center; transition: all 0.15s;
    animation: fadeUp 0.5s ease both;
  }
  .quick-btn:hover { border-color: var(--azul); background: var(--azul-light); color: var(--azul); }
  .quick-btn:hover .qb-icon { background: var(--azul); color: #fff; }
  .qb-icon {
    width: 40px; height: 40px; border-radius: 10px;
    background: var(--gris-bg); display: flex;
    align-items: center; justify-content: center;
    color: var(--azul); transition: all 0.15s;
  }

  /* ── Ingresos: bar chart ── */
  .chart-row {
    display: flex; align-items: center; gap: 12px;
    padding: 9px 20px; border-bottom: 1px solid var(--gris-borde);
    text-decoration: none; color: inherit;
    cursor: pointer; transition: background .15s;
  }
  .chart-row:hover { background: var(--gris-bg); }
  .chart-row:last-child { border-bottom: none; }
  .chart-month {
    font-size: 12px; font-weight: 700; color: var(--gris-texto);
    width: 28px; text-align: right; flex-shrink: 0; text-transform: uppercase;
  }
  .chart-month.current { color: #2DB140; }
  .chart-bar-wrap {
    flex: 1; height: 18px; background: var(--gris-bg);
    border-radius: 6px; overflow: hidden; position: relative;
  }
  .chart-bar {
    height: 100%; background: var(--azul); border-radius: 6px;
    transition: width .7s cubic-bezier(.4,0,.2,1);
    min-width: 3px;
  }
  .chart-bar.current { background: #2DB140; }
  .chart-val {
    font-size: 12px; font-weight: 700; color: var(--gris-dark);
    width: 86px; text-align: right; white-space: nowrap; flex-shrink: 0;
  }
  .chart-qty {
    font-size: 11px; color: var(--gris-texto);
    width: 44px; text-align: right; flex-shrink: 0;
  }
  .chart-summary {
    display: flex; gap: 24px; padding: 14px 20px;
    border-bottom: 1px solid var(--gris-borde); flex-wrap: wrap;
  }
  .chart-kpi { display: flex; flex-direction: column; gap: 2px; }
  .chart-kpi-val { font-size: 22px; font-weight: 700; font-family:'Inter',sans-serif; color: var(--gris-dark); line-height: 1; }
  .chart-kpi-lbl { font-size: 11px; color: var(--gris-texto); }
  .delta-up   { color: #10b981; font-size: 12px; font-weight: 700; }
  .delta-down { color: #ef4444; font-size: 12px; font-weight: 700; }

  /* ── Renovaciones ── */
  .renov-summary {
    display: flex; gap: 0; padding: 0; border-bottom: 1px solid var(--gris-borde);
  }
  .renov-pill {
    flex: 1; text-align: center; padding: 12px 8px;
    border-right: 1px solid var(--gris-borde); cursor: default;
  }
  .renov-pill:last-child { border-right: none; }
  .renov-pill-num { font-size: 24px; font-weight: 700; font-family:'Inter',sans-serif; line-height: 1; }
  .renov-pill-lbl { font-size: 10px; color: var(--gris-texto); margin-top: 3px; font-weight: 500; }
  .renov-row { display: flex; align-items: center; gap: 10px; padding: 10px 20px; border-bottom: 1px solid var(--gris-borde); }
  .renov-row:last-child { border-bottom: none; }
  .renov-dias {
    width: 38px; height: 38px; border-radius: 10px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    font-size: 12px; font-weight: 800; line-height: 1;
  }
  .renov-dias.urgent   { background:var(--s-err-bg); color: #dc2626; }
  .renov-dias.warning  { background:var(--s-warn-bg); color:var(--s-warn-tx); }
  .renov-dias.safe     { background:var(--s-ok-bg); color: #229133; }
  .renov-info { flex: 1; min-width: 0; }
  .renov-empresa { font-size: 13px; font-weight: 600; color: var(--gris-dark); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .renov-norma  { font-size: 11px; color: var(--gris-texto); margin-top: 1px; }
  .renov-fecha  { font-size: 11px; color: var(--gris-texto); flex-shrink: 0; white-space: nowrap; }

  /* ── Cumpleaños ── */
  .cumple-banner {
    display:flex; align-items:center; gap:14px; flex-wrap:wrap;
    background: linear-gradient(135deg,var(--s-warn-bg) 0%,#fffbf0 100%);
    border: 1px solid #fde68a; border-left: 4px solid #f59e0b;
    border-radius: 12px; padding: 14px 18px; margin-bottom: 20px;
    animation: fadeUp 0.5s ease both;
  }
  .cumple-banner-icon { font-size: 28px; flex-shrink:0; }
  .cumple-banner-text { flex:1; }
  .cumple-banner-title { font-weight:700; color:var(--s-warn-tx); font-size:13px; }
  .cumple-banner-names { color:var(--s-warn-tx); font-size:12px; margin-top:2px; }

  .cumple-row {
    display:flex; align-items:center; gap:12px;
    padding:11px 20px; border-bottom:1px solid var(--gris-borde);
  }
  .cumple-row:last-child { border-bottom:none; }
  .cumple-avatar {
    width:36px; height:36px; border-radius:50%; flex-shrink:0;
    object-fit:cover; border:2px solid var(--gris-borde);
  }
  .cumple-avatar-init {
    width:36px; height:36px; border-radius:50%; flex-shrink:0;
    display:flex; align-items:center; justify-content:center;
    font-size:12px; font-weight:700; color:#fff;
    background: var(--azul);
  }
  .cumple-info { flex:1; min-width:0; }
  .cumple-nombre { font-size:13px; font-weight:600; color:var(--gris-dark); }
  .cumple-rol { font-size:11px; color:var(--gris-texto); text-transform:capitalize; margin-top:1px; }
  .cumple-dias {
    font-size:12px; font-weight:700; padding:3px 10px; border-radius:100px;
    flex-shrink:0; white-space:nowrap;
  }
  .cumple-hoy  { background:var(--s-warn-bg); color:var(--s-warn-tx); border:1px solid #fde68a; }
  .cumple-pronto { background:var(--s-info-bg); color:#1d4ed8; border:1px solid #bfdbfe; }

  @keyframes fadeUp {
    from { opacity: 0; transform: translateY(12px); }
    to   { opacity: 1; transform: translateY(0); }
  }

  @media (max-width: 1024px) {
    .panels-grid { grid-template-columns: 1fr; }
    .quick-grid { grid-template-columns: repeat(2, 1fr); }
    .renov-summary { flex-wrap: wrap; }
    .renov-summary .renov-pill { min-width: 50%; }
  }
  @media (max-width: 700px) {
    .chart-summary { gap: 14px; }
    .chart-qty { display: none; }
    /* Renovaciones a 1 columna */
    div[style*="grid-template-columns:1fr 1fr"] { display: block !important; }
  }
  @media (max-width: 480px) {
    .quick-grid { grid-template-columns: repeat(2, 1fr); gap: 8px; }
    .quick-btn { padding: 12px 8px; font-size: 11px; }
    .qb-icon { width: 34px; height: 34px; }
    .chart-val { width: 70px; font-size: 11px; }
  }
  /* ── Actividad Comercial: tarjetas compactas en móvil ── */
  @media (max-width: 768px) {
    .ac-stat { padding: 12px 10px !important; border-radius: 8px; }
    .ac-stat [id^="ac-"] { font-size: 22px !important; }
    .ac-stat div:last-child { font-size: 10px !important; margin-top: 2px !important; }
  }
</style>

<!-- Encabezado -->
<?php
$_h = (int)date('G');
$_saludo = $_h < 12 ? 'Buenos días' : ($_h < 19 ? 'Buenas tardes' : 'Buenas noches');
$_msg = match($rol) {
    'ejecutivo_ventas'   => 'Cada propuesta que envías es un paso más hacia tu meta. ¡Hazlo hoy!',
    'gestor_logistico'   => 'Tus envíos bajo control. ¡Que todo llegue en tiempo y forma!',
    'gerente_operaciones'=> 'Aquí está el resumen de operaciones. ¡Que el equipo siga adelante!',
    default              => 'Aquí tienes el resumen de tu equipo. ¡Sigamos creciendo juntos!',
};
?>
<div class="page-header">
  <h1><?= $_saludo ?>, <?= sanitize($user['nombre']) ?></h1>
  <p><?= $_msg ?></p>
</div>

<?php if ($rol === 'ejecutivo_ventas'): ?>
<!-- ═══════════════ DASHBOARD EJECUTIVO DE VENTAS ═══════════════ -->

<div class="stats-grid">
  <a href="<?= APP_URL ?>/modules/cotizaciones/index.php?estado=activa"
     class="stat-card" style="--card-color:#10b981;--card-bg:var(--s-ok-bg);animation-delay:0.05s;text-decoration:none;cursor:pointer;"
     title="Ver mis propuestas activas">
    <div class="stat-icon"><svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>
    <div class="stat-value"><?= $s_mis_activas ?></div>
    <div class="stat-label">Mis propuestas activas</div>
    <div class="stat-sub"><span style="color:#10b981;font-size:11px;font-weight:600;">▸ Ver propuestas</span></div>
  </a>
  <a href="<?= APP_URL ?>/modules/cotizaciones/index.php?estado=enviada"
     class="stat-card" style="--card-color:#3b82f6;--card-bg:var(--s-info-bg);animation-delay:0.1s;text-decoration:none;cursor:pointer;"
     title="Ver propuestas enviadas sin respuesta">
    <div class="stat-icon"><svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg></div>
    <div class="stat-value"><?= $s_mis_enviadas ?></div>
    <div class="stat-label">Enviadas sin respuesta</div>
    <?php if ($s_mis_enviadas > 0): ?>
    <div class="stat-sub"><span style="color:#3b82f6;font-size:11px;font-weight:600;">▸ Hacer seguimiento</span></div>
    <?php endif; ?>
  </a>
  <a href="<?= APP_URL ?>/modules/clientes/index.php?estado=activo"
     class="stat-card" style="--card-color:#1A3A8F;--card-bg:#e8edf8;animation-delay:0.15s;text-decoration:none;cursor:pointer;"
     title="Ver mis clientes activos">
    <div class="stat-icon"><svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg></div>
    <div class="stat-value"><?= $s_mis_clientes ?></div>
    <div class="stat-label">Mis clientes activos</div>
    <div class="stat-sub"><span style="color:#1A3A8F;font-size:11px;font-weight:600;">▸ Ver clientes</span></div>
  </a>
  <a href="<?= APP_URL ?>/modules/cotizaciones/index.php?estado=enviada"
     class="stat-card" style="--card-color:#f59e0b;--card-bg:var(--s-warn-bg);animation-delay:0.2s;text-decoration:none;cursor:pointer;"
     title="Ver propuestas por vencer">
    <div class="stat-icon"><svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
    <div class="stat-value"><?= $s_por_vencer ?></div>
    <div class="stat-label">Por vencer (5 días)</div>
    <?php if ($s_por_vencer > 0): ?>
    <div class="stat-sub"><span style="color:var(--s-warn);font-weight:600;">⚡ Acción requerida</span></div>
    <?php else: ?>
    <div class="stat-sub"><span style="color:var(--gris-texto);font-size:11px;">Sin urgencias hoy</span></div>
    <?php endif; ?>
  </a>
  <div class="stat-card" style="--card-color:#10b981;--card-bg:var(--s-ok-bg);animation-delay:0.25s">
    <div class="stat-icon"><svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></div>
    <div class="stat-value">S/ <?= number_format($s_bonos_pendiente, 0) ?></div>
    <div class="stat-label">Bonos pendientes</div>
    <div class="stat-sub"><a href="<?= APP_URL ?>/modules/bonos/index.php" style="color:var(--s-ok);font-weight:600;text-decoration:none;"><?= $s_bonos_count ?> bono<?= $s_bonos_count !== 1 ? 's' : '' ?> por cobrar</a></div>
  </div>
</div>

<div class="quick-grid">
  <a href="<?= APP_URL ?>/modules/cotizaciones/crear.php" class="quick-btn" style="animation-delay:0.1s">
    <div class="qb-icon"><svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg></div>
    Nueva propuesta
  </a>
  <a href="<?= APP_URL ?>/modules/cotizaciones/seguimiento.php" class="quick-btn" style="animation-delay:0.15s">
    <div class="qb-icon"><svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2A19.79 19.79 0 0 1 11.63 19a19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg></div>
    Seguimiento
  </a>
  <a href="<?= APP_URL ?>/modules/bonos/index.php" class="quick-btn" style="animation-delay:0.2s">
    <div class="qb-icon"><svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></div>
    Mis bonos
  </a>
</div>

<?php include __DIR__ . '/partials/tabla_honor.php'; ?>

<?php
// Mes labels para el selector
$meses_es_full = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
// Solo meses del año actual hasta el mes en curso
$opciones_mes = [];
$_cur_anio = (int)date('Y');
$_cur_mes  = (int)date('n');
for ($m = $_cur_mes; $m >= 1; $m--) {
    $opciones_mes[] = ['val' => $_cur_anio.'-'.str_pad($m,2,'0',STR_PAD_LEFT), 'label' => $meses_es_full[$m].' '.$_cur_anio];
}
?>

<!-- ── Actividad Comercial ── -->
<div class="panel panel-full" style="animation-delay:0.18s;margin-bottom:20px;">
  <div class="panel-header">
    <div class="panel-title">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
      Actividad Comercial
    </div>
    <select onchange="actualizarAC(this.value)" style="font-size:12px;padding:4px 8px;border-radius:8px;border:1.5px solid var(--gris-borde);background:var(--gris-card);color:var(--gris-dark);cursor:pointer;">
      <?php foreach ($opciones_mes as $om): ?>
      <option value="<?= $om['val'] ?>" <?= $om['val'] === $mes_ac ? 'selected' : '' ?>><?= $om['label'] ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;padding:4px 0 6px;">
    <div class="ac-stat" style="background:var(--gris-bg);border-radius:10px;padding:16px 18px;text-align:center;">
      <div id="ac-elaboradas" style="font-size:28px;font-weight:800;color:var(--azul);font-family:'Inter',sans-serif;"><?= $ac_elaboradas ?></div>
      <div style="font-size:11px;font-weight:600;color:var(--gris-texto);margin-top:3px;">Elaboradas</div>
    </div>
    <div class="ac-stat" style="background:var(--gris-bg);border-radius:10px;padding:16px 18px;text-align:center;">
      <div id="ac-ganadas" style="font-size:28px;font-weight:800;color:#10b981;font-family:'Inter',sans-serif;"><?= $ac_ganadas ?></div>
      <div style="font-size:11px;font-weight:600;color:var(--gris-texto);margin-top:3px;">Ganadas</div>
    </div>
    <div class="ac-stat" style="background:var(--gris-bg);border-radius:10px;padding:16px 18px;text-align:center;">
      <div id="ac-pagadas" style="font-size:28px;font-weight:800;color:#2DB140;font-family:'Inter',sans-serif;"><?= $ac_pagadas ?></div>
      <div style="font-size:11px;font-weight:600;color:var(--gris-texto);margin-top:3px;">Pagadas</div>
    </div>
    <div class="ac-stat" style="background:var(--gris-bg);border-radius:10px;padding:16px 18px;text-align:center;">
      <div id="ac-deudores" style="font-size:28px;font-weight:800;color:#f59e0b;font-family:'Inter',sans-serif;"><?= $ac_deudores ?></div>
      <div style="font-size:11px;font-weight:600;color:var(--gris-texto);margin-top:3px;">Pendientes</div>
    </div>
  </div>
</div>
<script>
function actualizarAC(mes) {
  var url = '<?= APP_URL ?>/modules/dashboard/index.php?ajax=ac&mes_ac=' + encodeURIComponent(mes);
  fetch(url, {credentials: 'same-origin'})
    .then(function(r){ return r.json(); })
    .then(function(d){
      document.getElementById('ac-elaboradas').textContent = d.elaboradas;
      document.getElementById('ac-ganadas').textContent    = d.ganadas;
      document.getElementById('ac-pagadas').textContent    = d.pagadas;
      document.getElementById('ac-deudores').textContent   = d.deudores;
    })
    .catch(function(){});
}
</script>

<!-- ── Tablero General + Seguimientos ── -->
<div class="panels-grid">

  <!-- Tablero general de propuestas (read-only) -->
  <div class="panel" style="animation-delay:0.2s">
    <div class="panel-header">
      <div class="panel-title">
        <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
        Tablero general
      </div>
      <span style="font-size:11px;color:var(--gris-texto);font-style:italic;">Solo lectura</span>
    </div>
    <?php
    $est_col = ['borrador'=>'badge-gray','enviada'=>'badge-blue','aceptada'=>'badge-green','rechazada'=>'badge-red','vencida'=>'badge-amber','desistida'=>'badge-gray'];
    if (empty($tablero_cotizaciones)): ?>
    <div class="empty-state"><svg width="32" height="32" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/></svg><div>Sin propuestas aún</div></div>
    <?php else: ?>
    <div class="ggi-scroll-x">
    <table class="mini-table" id="tablero-table">
      <thead><tr><th scope="col">N°</th><th scope="col">Asesor</th><th scope="col">Empresa</th><th scope="col">Monto</th><th scope="col">Estado</th></tr></thead>
      <tbody>
        <?php foreach ($tablero_cotizaciones as $cot):
          $_tn = preg_match('/^COT-\d{4}-(.+)$/', $cot['numero'], $_tnm) ? $_tnm[1] : $cot['numero'];
        ?>
        <tr>
          <td><code style="font-size:11px;color:var(--gris-dark)"><?= sanitize($_tn) ?></code></td>
          <td style="white-space:nowrap;font-size:12px;color:var(--gris-texto)"><?= sanitize($cot['asesor_nombre'].' '.substr($cot['asesor_apellido'],0,1).'.') ?></td>
          <td style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= sanitize($cot['empresa']) ?></td>
          <td style="white-space:nowrap;"><?= $cot['moneda']==='USD'?'USD':'S/' ?> <?= number_format($cot['monto_total'],0) ?></td>
          <td><span class="badge <?= $est_col[$cot['estado']] ?? 'badge-gray' ?>"><?= ucfirst($cot['estado']) ?></span></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <div id="tablero-pagination"></div>
    <script>
    (function(){
      var PS = 5, page = 1;
      var rows = Array.from(document.querySelectorAll('#tablero-table tbody tr'));
      var pages = Math.ceil(rows.length / PS);
      function render() {
        rows.forEach(function(r,i){ r.style.display=(i>=(page-1)*PS&&i<page*PS)?'':'none'; });
        var el = document.getElementById('tablero-pagination');
        if (!el || pages <= 1) return;
        var btnStyle = 'font-size:12px;padding:4px 12px;border:1.5px solid var(--gris-borde);border-radius:8px;background:var(--gris-card);color:var(--gris-dark);cursor:pointer;';
        el.innerHTML = '<div style="display:flex;align-items:center;justify-content:space-between;padding:10px 20px;border-top:1px solid var(--gris-borde);">'
          +'<button onclick="tableroPage('+(page-1)+')" '+(page<=1?'disabled style="opacity:.4;cursor:default;"':'')+'style="'+btnStyle+'">← Anterior</button>'
          +'<span style="font-size:12px;color:var(--gris-texto);">Pág. '+page+' / '+pages+'</span>'
          +'<button onclick="tableroPage('+(page+1)+')" '+(page>=pages?'disabled style="opacity:.4;cursor:default;"':'')+'style="'+btnStyle+'">Siguiente →</button>'
          +'</div>';
      }
      window.tableroPage = function(p){ page=Math.max(1,Math.min(pages,p)); render(); };
      render();
    })();
    </script>
    </div><!-- /overflow-x:auto -->
    <?php endif; ?>
  </div>

  <!-- Seguimientos pendientes -->
  <div class="panel" style="animation-delay:0.25s">
    <div class="panel-header">
      <div class="panel-title">
        <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2A19.79 19.79 0 0 1 11.63 19"/><path d="M22 16.92v3a2 2 0 0 1-2.18 2"/></svg>
        Requieren seguimiento
      </div>
      <a href="<?= APP_URL ?>/modules/cotizaciones/seguimiento.php" class="panel-link">Ver seguimiento →</a>
    </div>
    <?php if (empty($seguimientos_pendientes)): ?>
    <div class="empty-state"><svg width="32" height="32" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><path d="M9 12l2 2 4-4"/></svg><div>Sin seguimientos pendientes</div></div>
    <?php else: ?>
    <table class="mini-table">
      <thead><tr><th scope="col">Propuesta</th><th scope="col">Empresa</th><th scope="col">Días</th><th scope="col"></th></tr></thead>
      <tbody>
        <?php foreach ($seguimientos_pendientes as $seg):
          $_sn = preg_match('/^COT-\d{4}-(.+)$/', $seg['numero'], $_snm) ? $_snm[1] : $seg['numero'];
        ?>
        <tr>
          <td><code style="font-size:11px;color:var(--gris-dark)"><?= sanitize($_sn) ?></code></td>
          <td style="max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= sanitize($seg['empresa']) ?></td>
          <td><span class="badge <?= $seg['dias_sin_respuesta'] >= 14 ? 'badge-red' : 'badge-amber' ?>"><?= $seg['dias_sin_respuesta'] ?>d</span></td>
          <td>
            <a href="<?= APP_URL ?>/modules/cotizaciones/ver.php?id=<?= $seg['id'] ?>" style="font-size:12px;color:var(--gris-texto);font-weight:600;text-decoration:none;">Ver →</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<?php elseif ($rol === 'gestor_logistico'): ?>
<!-- ═══════════════ DASHBOARD GESTOR LOGÍSTICO ═══════════════ -->

<div class="stats-grid">
  <a href="<?= APP_URL ?>/modules/envios/index.php?gestor=<?= $uid ?>"
     class="stat-card" style="--card-color:#8b5cf6;--card-bg:var(--s-purple-bg);animation-delay:0.05s;text-decoration:none;cursor:pointer;"
     title="Ver mis envíos en tránsito">
    <div class="stat-icon"><svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg></div>
    <div class="stat-value"><?= $s_mis_transito ?></div>
    <div class="stat-label">Mis envíos en tránsito</div>
    <div class="stat-sub"><span style="color:#8b5cf6;font-size:11px;font-weight:600;">▸ Ver mis envíos</span></div>
  </a>
  <a href="<?= APP_URL ?>/modules/envios/index.php?gestor=sin"
     class="stat-card" style="--card-color:#f59e0b;--card-bg:var(--s-warn-bg);animation-delay:0.1s;text-decoration:none;cursor:pointer;"
     title="Ver envíos sin gestor asignado">
    <div class="stat-icon"><svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></div>
    <div class="stat-value"><?= $s_sin_asignar ?></div>
    <div class="stat-label">Sin gestor asignado</div>
    <?php if ($s_sin_asignar > 0): ?>
    <div class="stat-sub"><span style="color:var(--s-warn);font-weight:600;">▸ Disponibles para asumir</span></div>
    <?php else: ?>
    <div class="stat-sub"><span style="color:var(--gris-texto);font-size:11px;">Todos asignados ✓</span></div>
    <?php endif; ?>
  </a>
  <a href="<?= APP_URL ?>/modules/envios/index.php?gestor=<?= $uid ?>"
     class="stat-card" style="--card-color:#10b981;--card-bg:var(--s-ok-bg);animation-delay:0.15s;text-decoration:none;cursor:pointer;"
     title="Ver envíos completados este mes">
    <div class="stat-icon"><svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></div>
    <div class="stat-value"><?= $s_completados_mes ?></div>
    <div class="stat-label">Completados este mes</div>
    <div class="stat-sub"><span style="color:#10b981;font-size:11px;font-weight:600;">▸ Ver historial</span></div>
  </a>
  <a href="<?= APP_URL ?>/modules/envios/index.php"
     class="stat-card" style="--card-color:#1A3A8F;--card-bg:#e8edf8;animation-delay:0.2s;text-decoration:none;cursor:pointer;"
     title="Ver todos mis envíos">
    <div class="stat-icon"><svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/><path d="M12 5l7 7-7 7"/></svg></div>
    <div class="stat-value"><?= $s_mis_total ?></div>
    <div class="stat-label">Total envíos asignados</div>
    <div class="stat-sub"><span style="color:#1A3A8F;font-size:11px;font-weight:600;">▸ Ver todos</span></div>
  </a>
</div>

<div class="quick-grid">
  <a href="<?= APP_URL ?>/modules/envios/crear.php" class="quick-btn" style="animation-delay:0.1s">
    <div class="qb-icon"><svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg></div>
    Nuevo envío
  </a>
  <a href="<?= APP_URL ?>/modules/envios/index.php?gestor=<?= $uid ?>" class="quick-btn" style="animation-delay:0.15s">
    <div class="qb-icon"><svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/></svg></div>
    Mis envíos
  </a>
  <a href="<?= APP_URL ?>/modules/envios/index.php?gestor=sin" class="quick-btn" style="animation-delay:0.2s">
    <div class="qb-icon"><svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></div>
    Sin asignar
  </a>
  <a href="<?= APP_URL ?>/modules/envios/index.php" class="quick-btn" style="animation-delay:0.25s">
    <div class="qb-icon"><svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg></div>
    Todos los envíos
  </a>
</div>

<div class="panels-grid">
  <!-- Mis envíos en tránsito -->
  <div class="panel" style="animation-delay:0.2s">
    <div class="panel-header">
      <div class="panel-title">
        <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
        Mis envíos en tránsito
      </div>
      <a href="<?= APP_URL ?>/modules/envios/index.php?gestor=<?= $uid ?>" class="panel-link">Ver todos →</a>
    </div>
    <?php if (empty($mis_envios_transito)): ?>
    <div class="empty-state"><svg width="32" height="32" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><path d="M9 12l2 2 4-4"/></svg><div>Sin envíos en tránsito</div></div>
    <?php else: ?>
    <table class="mini-table">
      <thead><tr><th scope="col">Cliente</th><th scope="col">Agencia</th><th scope="col">Días</th><th scope="col">Estado</th></tr></thead>
      <tbody>
        <?php foreach ($mis_envios_transito as $e): ?>
        <tr onclick="location.href='<?= APP_URL ?>/modules/envios/ver.php?id=<?= $e['id'] ?>'" style="cursor:pointer;">
          <td><span class="txt-ellipsis" title="<?= sanitize($e['razon_social']) ?>"><?= sanitize($e['razon_social']) ?></span></td>
          <td><?= sanitize($e['agencia']) ?></td>
          <td><span class="badge <?= $e['dias_transito'] > 10 ? 'badge-red' : ($e['dias_transito'] > 5 ? 'badge-amber' : 'badge-blue') ?>"><?= $e['dias_transito'] ?>d</span></td>
          <td><span class="env-estado-chip" style="--chip-color:<?= htmlspecialchars($e['color_hex']) ?>;"><?= sanitize($e['estado_nombre']) ?></span></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <!-- Envíos sin asignar -->
  <div class="panel" style="animation-delay:0.25s">
    <div class="panel-header">
      <div class="panel-title">
        <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        Envíos sin gestor asignado
      </div>
      <a href="<?= APP_URL ?>/modules/envios/index.php?gestor=sin" class="panel-link">Ver todos →</a>
    </div>
    <?php if (empty($envios_sin_asignar)): ?>
    <div class="empty-state"><svg width="32" height="32" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><path d="M9 12l2 2 4-4"/></svg><div>Todos asignados</div></div>
    <?php else: ?>
    <table class="mini-table">
      <thead><tr><th scope="col">Cliente</th><th scope="col">Agencia</th><th scope="col">Días</th><th scope="col"></th></tr></thead>
      <tbody>
        <?php foreach ($envios_sin_asignar as $e): ?>
        <tr>
          <td><span class="txt-ellipsis" title="<?= sanitize($e['razon_social']) ?>"><?= sanitize($e['razon_social']) ?></span></td>
          <td><?= sanitize($e['agencia']) ?></td>
          <td><span class="badge badge-amber"><?= $e['dias_transito'] ?>d</span></td>
          <td><a href="<?= APP_URL ?>/modules/envios/ver.php?id=<?= $e['id'] ?>" style="font-size:12px;color:var(--gris-texto);font-weight:600;text-decoration:none;">Asumir →</a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<?php else: ?>
<!-- ═══════════════ DASHBOARD GERENTES (COMPLETO) ═══════════════ -->

<!-- Métricas -->
<div class="stats-grid">
  <a href="<?= APP_URL ?>/modules/clientes/index.php?estado=activo"
     class="stat-card"
     style="--card-color:#1A3A8F;--card-bg:#e8edf8; animation-delay:0.05s; text-decoration:none; cursor:pointer;"
     title="Ver clientes activos">
    <div class="stat-icon"><svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
    <div class="stat-value"><?= $stats['clientes'] ?></div>
    <div class="stat-label">Clientes activos</div>
    <?php if ($_nc_mes > 0 || $_nc_delta !== null): ?>
    <div class="stat-sub" style="display:flex;align-items:center;gap:5px;flex-wrap:wrap;">
      <?php if ($_nc_mes > 0): ?><span style="font-size:11px;color:#1A3A8F;font-weight:600;">+<?= $_nc_mes ?> este mes</span><?php endif; ?>
      <?php if ($_nc_delta !== null): ?><span class="<?= $_nc_delta >= 0 ? 'delta-up' : 'delta-down' ?>" style="font-size:11px;"><?= $_nc_delta >= 0 ? '▲' : '▼' ?> <?= abs($_nc_delta) ?>%</span><?php endif; ?>
    </div>
    <?php elseif (true): ?><div class="stat-sub"><span style="color:var(--gris-texto);font-size:11px;">▸ Ver clientes</span></div><?php endif; ?>
  </a>
  <a href="<?= APP_URL ?>/modules/certificados/index.php?estado=vigente"
     class="stat-card"
     style="--card-color:#2DB140;--card-bg:var(--s-ok-bg); animation-delay:0.1s; text-decoration:none; cursor:pointer;"
     title="Ver certificados vigentes">
    <div class="stat-icon"><svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/></svg></div>
    <div class="stat-value"><?= $stats['certificados'] ?></div>
    <div class="stat-label">Certificados vigentes</div>
    <div class="stat-sub"><span style="color:#2DB140;font-size:11px;font-weight:600;">▸ Ver certificados</span></div>
  </a>
  <a href="<?= APP_URL ?>/modules/certificados/index.php?estado=por_vencer"
     class="stat-card"
     style="--card-color:#f59e0b;--card-bg:var(--s-warn-bg); animation-delay:0.15s; text-decoration:none; cursor:pointer;"
     title="Ver certificados por vencer">
    <div class="stat-icon"><svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
    <div class="stat-value"><?= $stats['cert_por_vencer'] ?></div>
    <div class="stat-label">Por vencer (90 días)</div>
    <?php if ($stats['cert_por_vencer'] > 0): ?>
    <div class="stat-sub"><span style="color:var(--s-warn);font-weight:600;">⚠ Requieren atención</span></div>
    <?php else: ?>
    <div class="stat-sub"><span style="color:var(--gris-texto);font-size:11px;">▸ Ver alertas</span></div>
    <?php endif; ?>
  </a>
  <a href="<?= APP_URL ?>/modules/certificados/index.php?estado=vencido"
     class="stat-card"
     style="--card-color:#ef4444;--card-bg:var(--s-err-bg); animation-delay:0.2s; text-decoration:none; cursor:pointer;"
     title="Ver certificados vencidos">
    <div class="stat-icon"><svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg></div>
    <div class="stat-value"><?= $stats['cert_vencidos'] ?></div>
    <div class="stat-label">Certificados vencidos</div>
    <?php if ($stats['cert_vencidos'] > 0): ?>
    <div class="stat-sub"><span style="color:#ef4444;font-weight:600;font-size:11px;">▸ Ver vencidos</span></div>
    <?php endif; ?>
  </a>
  <a href="<?= APP_URL ?>/modules/envios/index.php<?= $_est_por_preparar_id ? '?estado='.$_est_por_preparar_id : '' ?>"
     class="stat-card"
     style="--card-color:#f97316;--card-bg:var(--s-warn-bg); animation-delay:0.25s; text-decoration:none; cursor:pointer;"
     title="Ver envíos por preparar">
    <div class="stat-icon"><svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-4 0v2"/><path d="M8 7V5a2 2 0 0 1 4 0"/><line x1="12" y1="12" x2="12" y2="16"/><line x1="10" y1="14" x2="14" y2="14"/></svg></div>
    <div class="stat-value"><?= $stats['envios_por_preparar'] ?></div>
    <div class="stat-label">Envíos sin preparar</div>
    <div class="stat-sub"><span style="color:#f97316;font-weight:600;font-size:11px;">▸ Ver listado</span></div>
  </a>
  <a href="<?= APP_URL ?>/modules/auditorias/index.php?proximas=1"
     class="stat-card"
     style="--card-color:#0ea5e9;--card-bg:#e0f2fe; animation-delay:0.3s; text-decoration:none; cursor:pointer;"
     title="Ver auditorías próximas (próximos 60 días)">
    <div class="stat-icon"><svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg></div>
    <div class="stat-value"><?= $stats['auditorias_proximas'] ?></div>
    <div class="stat-label">Auditorías próximas</div>
    <div class="stat-sub"><span style="color:#0ea5e9;font-size:11px;font-weight:600;">▸ Ver auditorías</span></div>
  </a>
  <a href="<?= APP_URL ?>/modules/cotizaciones/index.php?estado=enviada"
     class="stat-card"
     style="--card-color:#10b981;--card-bg:var(--s-ok-bg); animation-delay:0.35s; text-decoration:none; cursor:pointer;"
     title="Ver propuestas activas">
    <div class="stat-icon"><svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>
    <div class="stat-value"><?= $stats['cotizaciones_activas'] ?></div>
    <div class="stat-label">Propuestas activas</div>
    <?php if ($_cot_mes > 0 || $_cot_delta !== null): ?>
    <div class="stat-sub" style="display:flex;align-items:center;gap:5px;flex-wrap:wrap;">
      <?php if ($_cot_mes > 0): ?><span style="font-size:11px;color:#10b981;font-weight:600;"><?= $_cot_mes ?> creadas este mes</span><?php endif; ?>
      <?php if ($_cot_delta !== null): ?><span class="<?= $_cot_delta >= 0 ? 'delta-up' : 'delta-down' ?>" style="font-size:11px;"><?= $_cot_delta >= 0 ? '▲' : '▼' ?> <?= abs($_cot_delta) ?>%</span><?php endif; ?>
    </div>
    <?php else: ?><div class="stat-sub"><span style="color:var(--gris-texto);font-size:11px;">▸ Ver propuestas</span></div><?php endif; ?>
  </a>
  <a href="<?= APP_URL ?>/modules/pagos/index.php?estado=pendiente_validacion"
     class="stat-card"
     style="--card-color:#eab308;--card-bg:#fef9c3; animation-delay:0.4s; text-decoration:none; cursor:pointer;"
     title="Ver pagos pendientes de validación">
    <div class="stat-icon"><svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg></div>
    <div class="stat-value"><?= $stats['pagos_pendientes'] ?></div>
    <div class="stat-label">Pagos pendientes</div>
    <?php if ($stats['pagos_pendientes'] > 0): ?>
    <div class="stat-sub" style="display:flex;flex-direction:column;gap:2px">
      <?php if ($_pp_monto_pen > 0): ?><span style="color:#a16207;font-weight:600;font-size:11px;">S/ <?= number_format($_pp_monto_pen, 0) ?></span><?php endif; ?>
      <?php if ($_pp_monto_usd > 0): ?><span style="color:#a16207;font-weight:600;font-size:11px;">$ <?= number_format($_pp_monto_usd, 0) ?></span><?php endif; ?>
    </div>
    <?php else: ?><div class="stat-sub"><span style="color:var(--gris-texto);font-size:11px;">▸ Ver pagos</span></div><?php endif; ?>
  </a>
</div>

<!-- Accesos rápidos -->
<div class="quick-grid">
  <a href="<?= APP_URL ?>/modules/clientes/crear.php?from=dashboard" class="quick-btn" style="animation-delay:0.1s">
    <div class="qb-icon"><svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg></div>
    Nuevo cliente
  </a>
  <a href="<?= APP_URL ?>/modules/certificados/crear.php?from=dashboard" class="quick-btn" style="animation-delay:0.15s">
    <div class="qb-icon"><svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/></svg></div>
    Nuevo certificado
  </a>
  <a href="<?= APP_URL ?>/modules/envios/crear.php" class="quick-btn" style="animation-delay:0.2s">
    <div class="qb-icon"><svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/><path d="M12 5l7 7-7 7"/></svg></div>
    Registrar envío
  </a>
  <a href="<?= APP_URL ?>/modules/cotizaciones/crear.php" class="quick-btn" style="animation-delay:0.25s">
    <div class="qb-icon"><svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/></svg></div>
    Nueva propuesta
  </a>
</div>

<!-- Paneles -->
<div class="panels-grid">

  <!-- ── Certificados por generar (fecha requerida por el cliente) — arriba por urgencia ── -->
  <?php if (!empty($cert_por_generar)): ?>
  <div class="panel panel-full" style="animation-delay:0.14s">
    <div class="panel-header">
      <div class="panel-title"><svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><path d="m9 16 2 2 4-4"/></svg> Certificados por generar</div>
      <span style="font-size:11px;color:var(--gris-texto);font-style:italic;">Fecha en que el cliente los necesita</span>
    </div>
    <div class="ggi-scroll-x">
    <table class="mini-table">
      <thead><tr><th scope="col">Empresa</th><th scope="col">Propuesta</th><th scope="col">Fecha requerida</th><th scope="col">Faltan</th></tr></thead>
      <tbody>
        <?php foreach ($cert_por_generar as $cg):
          $_d   = (int)$cg['dias'];
          $_col = $_d <= 1 ? '#dc2626' : ($_d <= 3 ? '#d97706' : ($_d <= 7 ? '#2563eb' : 'var(--gris-texto)'));
          $_lbl = $_d <= 0 ? 'Hoy' : ($_d === 1 ? 'Mañana' : "En {$_d} días");
        ?>
        <tr onclick="location.href='<?= APP_URL ?>/modules/cotizaciones/ver.php?id=<?= (int)$cg['id'] ?>'" style="cursor:pointer;">
          <td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= sanitize($cg['empresa']) ?></td>
          <td><code style="font-size:11px;color:var(--gris-dark);"><?= sanitize($cg['numero']) ?></code></td>
          <td style="white-space:nowrap;"><?= date('d/m/Y', strtotime($cg['fecha'])) ?></td>
          <td style="white-space:nowrap;"><span style="font-weight:700;color:<?= $_col ?>;"><?= $_lbl ?></span></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── INGRESOS POR MES ── FULL WIDTH ── -->
  <div class="panel panel-full" style="animation-delay:0.18s">
    <div class="panel-header">
      <div class="panel-title">
        <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
        Ingresos por mes
        <span style="font-size:11px;font-weight:500;color:var(--gris-texto)">(propuestas aceptadas)</span>
      </div>
      <a href="<?= APP_URL ?>/modules/cotizaciones/index.php?estado=aceptada" class="panel-link">Ver propuestas →</a>
    </div>
    <!-- KPIs resumen -->
    <div class="chart-summary">
      <div class="chart-kpi">
        <div class="chart-kpi-val">S/ <?= number_format($ingreso_actual, 0) ?></div>
        <div class="chart-kpi-lbl">Este mes (<?= $meses_es[(int)date('n')] ?>)</div>
      </div>
      <?php if ($delta_pct !== null): ?>
      <div class="chart-kpi" style="justify-content:flex-end">
        <div class="<?= $delta_pct >= 0 ? 'delta-up' : 'delta-down' ?>">
          <?= $delta_pct >= 0 ? '▲' : '▼' ?> <?= abs($delta_pct) ?>% vs mes anterior
        </div>
        <div class="chart-kpi-lbl">S/ <?= number_format($ingreso_anterior,0) ?> en <?= $meses_es[(int)date('n', strtotime('first day of -1 month'))] ?></div>
      </div>
      <?php endif; ?>
      <div class="chart-kpi" style="margin-left:auto">
        <div class="chart-kpi-val"><?= $cot_actual ?></div>
        <div class="chart-kpi-lbl">Propuestas aceptadas (<?= $meses_es[(int)date('n')] ?>)</div>
      </div>
    </div>
    <!-- Barras -->
    <?php if (empty($ingresos_mes)): ?>
    <div class="empty-state"><svg width="28" height="28" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg><div>Sin ingresos registrados aún</div></div>
    <?php else: ?>
    <?php foreach ($ingresos_mes as $m):
      $pct    = $max_ingreso > 0 ? max(3, round(($m['total']/$max_ingreso)*100)) : 3;
      $isCur  = ($m['mes'] === $mes_actual_key);
      $_chart_url = APP_URL . '/modules/cotizaciones/index.php?estado=aceptada&mes=' . urlencode($m['mes']);
    ?>
    <a class="chart-row" href="<?= $_chart_url ?>" title="Ver propuestas aceptadas de <?= $meses_es[(int)$m['mes_num']] ?>">
      <span class="chart-month <?= $isCur ? 'current' : '' ?>"><?= $meses_es[(int)$m['mes_num']] ?></span>
      <div class="chart-bar-wrap">
        <div class="chart-bar <?= $isCur ? 'current' : '' ?>" style="width:<?= $pct ?>%"></div>
      </div>
      <span class="chart-val">S/ <?= number_format($m['total'],0) ?></span>
      <span class="chart-qty"><?= $m['cantidad'] ?> cot.</span>
    </a>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- ── RENOVACIONES PRÓXIMAS ── FULL WIDTH ── -->
  <div class="panel panel-full" style="animation-delay:0.22s">
    <div class="panel-header">
      <div class="panel-title">
        <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M21 2H3v16l4-4h14V2z"/><line x1="8" y1="8" x2="16" y2="8"/><line x1="8" y1="12" x2="14" y2="12"/></svg>
        Renovaciones próximas
        <span style="font-size:11px;font-weight:500;color:var(--gris-texto)">(próximos 90 días)</span>
      </div>
      <a href="<?= APP_URL ?>/modules/certificados/index.php" class="panel-link">Ver certificados →</a>
    </div>
    <!-- Semáforo resumen -->
    <div class="renov-summary">
      <div class="renov-pill">
        <div class="renov-pill-num" style="color:var(--s-err)"><?= $renov_30 ?></div>
        <div class="renov-pill-lbl">⬤ Crítico (0–30 días)</div>
      </div>
      <div class="renov-pill">
        <div class="renov-pill-num" style="color:var(--s-warn)"><?= $renov_60 ?></div>
        <div class="renov-pill-lbl">⬤ Urgente (31–60 días)</div>
      </div>
      <div class="renov-pill">
        <div class="renov-pill-num" style="color:var(--s-ok)"><?= $renov_90 ?></div>
        <div class="renov-pill-lbl">⬤ Planificar (61–90 días)</div>
      </div>
      <div class="renov-pill" style="background:var(--gris-bg)">
        <div class="renov-pill-num" style="color:var(--gris-dark)"><?= count($renovaciones) ?></div>
        <div class="renov-pill-lbl">Total a gestionar</div>
      </div>
    </div>
    <?php if (empty($renovaciones)): ?>
    <div class="empty-state"><svg width="28" height="28" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><path d="M9 12l2 2 4-4"/></svg><div>Sin renovaciones en los próximos 90 días</div></div>
    <?php else: ?>
    <!-- Lista 2 columnas -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0">
      <?php foreach ($renovaciones as $r):
        $dias = (int)$r['dias_restantes'];
        if ($dias <= 30)      { $cls = 'urgent';  }
        elseif ($dias <= 60)  { $cls = 'warning'; }
        else                  { $cls = 'safe';    }
        $fecha_fmt = date('d/m/Y', strtotime($r['fecha_vencimiento']));
      ?>
      <div class="renov-row">
        <div class="renov-dias <?= $cls ?>">
          <?= $dias ?><div style="font-size:8px;font-weight:600;opacity:.7">días</div>
        </div>
        <div class="renov-info">
          <div class="renov-empresa"><?= sanitize($r['razon_social']) ?></div>
          <div class="renov-norma"><span style="font-weight:700;color:var(--gris-dark)"><?= sanitize($r['norma']) ?></span> — vence <?= $fecha_fmt ?></div>
        </div>
        <a href="<?= APP_URL ?>/modules/clientes/ver.php?id=<?= $r['cliente_id'] ?>" style="font-size:11px;font-weight:600;color:var(--gris-texto);text-decoration:none;white-space:nowrap;flex-shrink:0">Ver →</a>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- ── Certificados por vencer ── -->
  <div class="panel" style="animation-delay:0.2s">
    <div class="panel-header">
      <div class="panel-title"><svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> Certificados por vencer</div>
      <a href="<?= APP_URL ?>/modules/certificados/index.php" class="panel-link">Ver todos →</a>
    </div>
    <?php if (empty($cert_vencer)): ?>
    <div class="empty-state"><svg width="32" height="32" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><path d="M9 12l2 2 4-4"/></svg><div>Sin vencimientos próximos</div></div>
    <?php else: ?>
    <table class="mini-table"><thead><tr><th scope="col">Cliente</th><th scope="col">Norma</th><th scope="col">Días</th><th scope="col">Estado</th></tr></thead><tbody>
      <?php foreach ($cert_vencer as $c): ?>
      <tr><td><span class="txt-ellipsis" title="<?= sanitize($c['razon_social']) ?>"><?= sanitize($c['razon_social']) ?></span></td><td><?= sanitize($c['norma']) ?></td>
        <td><span class="badge <?= $c['dias_restantes'] <= 30 ? 'badge-red' : 'badge-amber' ?>"><?= $c['dias_restantes'] ?> días</span></td>
        <td><span class="badge badge-amber">Por vencer</span></td></tr>
      <?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
  </div>

  <div class="panel" style="animation-delay:0.25s">
    <div class="panel-header">
      <div class="panel-title"><svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg> Auditorías próximas</div>
      <a href="<?= APP_URL ?>/modules/auditorias/index.php" class="panel-link">Ver todas →</a>
    </div>
    <?php if (empty($aud_proximas)): ?>
    <div class="empty-state"><svg width="32" height="32" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><path d="M9 12l2 2 4-4"/></svg><div>Sin auditorías próximas</div></div>
    <?php else: ?>
    <table class="mini-table"><thead><tr><th scope="col">Cliente</th><th scope="col">Tipo</th><th scope="col">Fecha</th><th scope="col">Días</th></tr></thead><tbody>
      <?php foreach ($aud_proximas as $a): ?>
      <tr><td><span class="txt-ellipsis" title="<?= sanitize($a['razon_social']) ?>"><?= sanitize($a['razon_social']) ?></span></td><td><span class="badge badge-blue"><?= sanitize(str_replace('_',' ',$a['tipo'])) ?></span></td>
        <td><?= formato_fecha($a['fecha_programada']) ?></td>
        <td><span class="badge <?= $a['dias_restantes'] <= 15 ? 'badge-red' : 'badge-amber' ?>"><?= $a['dias_restantes'] ?>d</span></td></tr>
      <?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
  </div>

  <div class="panel" style="animation-delay:0.3s">
    <div class="panel-header">
      <div class="panel-title">
        <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-4 0v2"/><path d="M8 7V5a2 2 0 0 1 4 0"/><line x1="12" y1="12" x2="12" y2="16"/><line x1="10" y1="14" x2="14" y2="14"/></svg>
        Envíos por preparar
        <?php if ($stats['envios_por_preparar'] > 0): ?>
        <span style="margin-left:6px;background:var(--s-warn-bg);color:#f97316;border:1px solid #fed7aa;font-size:10px;font-weight:700;padding:1px 7px;border-radius:100px;"><?= $stats['envios_por_preparar'] ?></span>
        <?php endif; ?>
      </div>
      <a href="<?= APP_URL ?>/modules/envios/index.php<?= $_est_por_preparar_id ? '?estado='.$_est_por_preparar_id : '' ?>" class="panel-link">Ver todos →</a>
    </div>
    <?php if (empty($envios_pend)): ?>
    <div class="empty-state">
      <svg width="32" height="32" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-4 0v2"/><path d="M8 7V5a2 2 0 0 1 4 0"/></svg>
      <div>Sin envíos pendientes de preparar</div>
    </div>
    <?php else: ?>
    <table class="mini-table">
      <thead><tr><th scope="col">Cliente</th><th scope="col">Agencia</th><th scope="col">Espera</th></tr></thead>
      <tbody>
        <?php foreach ($envios_pend as $e): ?>
        <tr onclick="location.href='<?= APP_URL ?>/modules/envios/ver.php?id=<?= $e['id'] ?>'" style="cursor:pointer;">
          <td><span class="txt-ellipsis" title="<?= sanitize($e['razon_social']) ?>"><?= sanitize($e['razon_social']) ?></span></td>
          <td><?= sanitize($e['agencia']) ?></td>
          <td>
            <span class="badge <?= (int)$e['dias_espera'] >= 3 ? 'badge-red' : ((int)$e['dias_espera'] >= 1 ? 'badge-amber' : 'badge-green') ?>">
              <?= $e['dias_espera'] == 0 ? 'Hoy' : $e['dias_espera'].'d' ?>
            </span>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <!-- ── PAGOS PENDIENTES ── -->
  <div class="panel" style="animation-delay:0.33s">
    <div class="panel-header">
      <div class="panel-title">
        <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
        Pagos pendientes
        <?php if ($stats['pagos_pendientes'] > 0): ?>
        <span style="margin-left:6px;background:var(--s-warn-bg);color:#a16207;border:1px solid #fde68a;font-size:10px;font-weight:700;padding:1px 7px;border-radius:100px;"><?= $stats['pagos_pendientes'] ?></span>
        <?php endif; ?>
      </div>
      <a href="<?= APP_URL ?>/modules/pagos/index.php?estado=pendiente_validacion" class="panel-link">Ver todos →</a>
    </div>
    <?php if (empty($pagos_pend_list)): ?>
    <div class="empty-state">
      <svg width="32" height="32" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
      <div>Sin pagos pendientes</div>
    </div>
    <?php else: ?>
    <table class="mini-table">
      <thead><tr><th scope="col">Cliente</th><th scope="col">Propuesta</th><th scope="col">Monto</th><th scope="col">Estado</th><th scope="col">Espera</th></tr></thead>
      <tbody>
        <?php foreach ($pagos_pend_list as $pp):
          $pp_badge = $pp['estado'] === 'pendiente_voucher' ? 'badge-gray' : 'badge-amber';
          $pp_label = $pp['estado'] === 'pendiente_voucher' ? 'Sin voucher' : 'Por validar';
          $pp_dias  = (int)$pp['dias_espera'];
          $pp_dias_badge = $pp_dias >= 3 ? 'badge-red' : ($pp_dias >= 1 ? 'badge-amber' : 'badge-green');
        ?>
        <tr onclick="location.href='<?= APP_URL ?>/modules/pagos/index.php?pago_id=<?= $pp['id'] ?>'" style="cursor:pointer;">
          <td><span class="txt-ellipsis" title="<?= sanitize($pp['empresa']) ?>"><?= sanitize($pp['empresa']) ?></span></td>
          <td><code style="font-size:11px;color:var(--gris-dark)"><?= sanitize($pp['cot_numero']) ?></code></td>
          <td style="white-space:nowrap;font-weight:600;"><?= $pp['moneda'] === 'USD' ? '$' : 'S/' ?> <?= number_format((float)$pp['monto'], 0) ?></td>
          <td><span class="badge <?= $pp_badge ?>"><?= $pp_label ?></span></td>
          <td><span class="badge <?= $pp_dias_badge ?>"><?= $pp_dias == 0 ? 'Hoy' : $pp_dias.'d' ?></span></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <div class="panel" style="animation-delay:0.35s">
    <div class="panel-header">
      <div class="panel-title"><svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg> Últimos clientes registrados</div>
      <a href="<?= APP_URL ?>/modules/clientes/index.php" class="panel-link">Ver todos →</a>
    </div>
    <?php if (empty($ultimos_clientes)): ?>
    <div class="empty-state"><svg width="32" height="32" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg><div>Aún no hay clientes</div></div>
    <?php else: ?>
    <table class="mini-table"><thead><tr><th scope="col">RUC</th><th scope="col">Razón social</th><th scope="col">Estado</th><th scope="col">Fecha</th></tr></thead><tbody>
      <?php foreach ($ultimos_clientes as $c): ?>
      <tr><td><code style="font-size:11px;color:var(--gris-dark)"><?= sanitize($c['ruc']) ?></code></td>
        <td><span class="txt-ellipsis" title="<?= sanitize($c['razon_social']) ?>"><?= sanitize($c['razon_social']) ?></span></td>
        <td><span class="badge <?= $c['estado']==='activo' ? 'badge-green' : 'badge-gray' ?>"><?= ucfirst($c['estado']) ?></span></td>
        <td style="color:var(--gris-texto)"><?= formato_fecha($c['created_at']) ?></td></tr>
      <?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
  </div>


</div>

<?php include __DIR__ . '/partials/tabla_honor.php'; ?>

<?php endif; ?>

<?php // Cumpleaños del equipo: compartido por TODOS los roles (banner de hoy + panel) ?>
<?php include __DIR__ . '/partials/cumpleanos_equipo.php'; ?>

<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
