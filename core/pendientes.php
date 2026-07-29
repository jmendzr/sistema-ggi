<?php
/**
 * core/pendientes.php — Sistema unificado de "pendientes de atención" por empresa.
 *
 * Arquitectura escalable: para agregar un nuevo tipo de pendiente basta con
 *   (1) añadir su entrada en gg_pendiente_tipos()
 *   (2) calcular su conteo en gg_empresas_pendientes_counts()
 * La UI (indicador compacto en listados + alerta en ficha) NO cambia: consume
 * siempre la misma estructura {total, items[]}.
 *
 * Uso:
 *   Listado (lote, 1 set de queries por página):
 *     $map = gg_empresas_pendientes_counts($pdo, $ids);   // [id => counts]
 *     $p   = gg_pendientes_build($map[$id] ?? []);        // {total, items}
 *   Ficha detalle (un cliente):
 *     $p = gg_empresa_pendientes($pdo, $id);
 *   Popover de desglose (ggiChipPop):
 *     gg_pendientes_popover_html($p)
 */

if (!function_exists('gg_pendiente_tipos')) {

    /**
     * Registro central de tipos de pendiente. AGREGAR NUEVOS TIPOS AQUÍ.
     * 'orden' controla la posición en el desglose.
     */
    function gg_pendiente_tipos(): array
    {
        return [
            'documentos' => [
                'singular' => 'documento requerido',
                'plural'   => 'documentos requeridos',
                'orden'    => 10,
            ],
            'facturas' => [
                'singular' => 'factura por cargar',
                'plural'   => 'facturas por cargar',
                'orden'    => 20,
            ],
            // ── Futuro (solo añadir aquí + su conteo abajo) ──
            // 'certificados' => ['singular'=>'certificado por vencer', 'plural'=>'certificados por vencer', 'orden'=>30],
            // 'pagos'        => ['singular'=>'pago observado',         'plural'=>'pagos observados',         'orden'=>40],
            // 'auditorias'   => ['singular'=>'auditoría pendiente',    'plural'=>'auditorías pendientes',    'orden'=>50],
        ];
    }

    /**
     * Construye la estructura de pendientes desde un mapa de conteos.
     * @param array $counts ['documentos'=>1, 'facturas'=>2, ...]
     * @return array ['total'=>int, 'items'=>[ ['tipo','count','label','orden'], ... ]]
     */
    function gg_pendientes_build(array $counts): array
    {
        $tipos = gg_pendiente_tipos();
        $items = [];
        $total = 0;
        foreach ($tipos as $key => $cfg) {
            $n = (int)($counts[$key] ?? 0);
            if ($n <= 0) continue;
            $total  += $n;
            $items[] = [
                'tipo'  => $key,
                'count' => $n,
                'label' => $n . ' ' . ($n === 1 ? $cfg['singular'] : $cfg['plural']),
                'orden' => $cfg['orden'] ?? 99,
            ];
        }
        usort($items, fn($a, $b) => $a['orden'] <=> $b['orden']);
        return ['total' => $total, 'items' => $items];
    }

    /**
     * Conteos de pendientes en LOTE (para listados). 1 set de queries para N clientes.
     * @return array [cliente_id => ['documentos'=>int, 'facturas'=>int, ...]]
     */
    function gg_empresas_pendientes_counts(PDO $pdo, array $cliente_ids): array
    {
        $cliente_ids = array_values(array_unique(array_filter(array_map('intval', $cliente_ids), fn($v) => $v > 0)));
        if (!$cliente_ids) return [];

        $ph  = implode(',', array_fill(0, count($cliente_ids), '?'));
        $out = [];
        foreach ($cliente_ids as $id) $out[$id] = ['documentos' => 0, 'facturas' => 0];

        // ── Documentos requeridos faltantes (solo si la empresa tiene propuesta aceptada) ──
        //    Requeridos: ficha_ruc + acta_alcance vigentes. Cada uno faltante = 1 pendiente.
        try {
            $sql = "
                SELECT c.id,
                       (SELECT COUNT(*) FROM cotizaciones cot
                          WHERE cot.cliente_id = c.id AND cot.estado='aceptada' AND cot.deleted_at IS NULL) AS cot_aceptadas,
                       -- ¿alguna norma de las propuestas aceptadas exige acta? (requiere_acta=1)
                       (SELECT COUNT(*) FROM cotizaciones cot2
                          JOIN cotizacion_normas cn2 ON cn2.cotizacion_id = cot2.id
                          JOIN normas_iso n2 ON n2.id = cn2.norma_iso_id
                          WHERE cot2.cliente_id = c.id AND cot2.estado='aceptada' AND cot2.deleted_at IS NULL
                            AND n2.requiere_acta = 1) AS acta_req,
                       COALESCE(SUM(d.tipo_documento='ficha_ruc'    AND d.es_vigente=1), 0) AS tiene_ficha,
                       COALESCE(SUM(d.tipo_documento='acta_alcance' AND d.es_vigente=1), 0) AS tiene_acta
                FROM clientes c
                LEFT JOIN documentos_cliente d ON d.cliente_id = c.id
                WHERE c.id IN ($ph)
                GROUP BY c.id
            ";
            $st = $pdo->prepare($sql);
            $st->execute($cliente_ids);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if ((int)$r['cot_aceptadas'] <= 0) continue;
                $docs = 0;
                if (!(int)$r['tiene_ficha']) $docs++;
                // El acta solo cuenta como pendiente si alguna norma la exige (no aplica → no cuenta)
                if (!(int)$r['tiene_acta'] && (int)$r['acta_req'] > 0) $docs++;
                $out[(int)$r['id']]['documentos'] = $docs;
            }
        } catch (\Throwable $e) {
            error_log('[pendientes] documentos: ' . $e->getMessage());
        }

        // ── Facturas por cargar: propuestas aceptadas QUE APLICAN IGV, SIN factura y
        //    con AMBOS pagos (inicial + final) ya VALIDADOS. Recién con el pago completo
        //    procede emitir la factura → antes de eso no se cuenta como pendiente.
        //    (Si la propuesta no afecta IGV no se requiere factura → no se cuenta.)
        try {
            $sqlF = "
                SELECT cot.cliente_id, COUNT(*) AS faltan
                FROM cotizaciones cot
                WHERE cot.cliente_id IN ($ph)
                  AND cot.estado='aceptada' AND cot.deleted_at IS NULL
                  AND cot.incluye_igv = 1
                  AND NOT EXISTS (SELECT 1 FROM facturas_cliente f WHERE f.cotizacion_id = cot.id)
                  AND EXISTS (SELECT 1 FROM pagos_cliente pi WHERE pi.cotizacion_id = cot.id
                                AND pi.deleted_at IS NULL AND pi.tipo='inicial' AND pi.estado='validado')
                  AND EXISTS (SELECT 1 FROM pagos_cliente pf WHERE pf.cotizacion_id = cot.id
                                AND pf.deleted_at IS NULL AND pf.tipo='final'   AND pf.estado='validado')
                GROUP BY cot.cliente_id
            ";
            $stF = $pdo->prepare($sqlF);
            $stF->execute($cliente_ids);
            foreach ($stF->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[(int)$r['cliente_id']]['facturas'] = (int)$r['faltan'];
            }
        } catch (\Throwable $e) {
            error_log('[pendientes] facturas: ' . $e->getMessage());
        }

        return $out;
    }

    /**
     * Cláusula SQL EXISTS: ¿la empresa tiene alguna factura pendiente de emitir?
     * (propuesta aceptada con IGV, sin factura cargada, y ambos pagos validados).
     * Reutilizable para filtrar listados sin duplicar la lógica de conteo.
     * $cidCol es un nombre de columna (no entra input de usuario).
     */
    function gg_sql_factura_pendiente(string $cidCol): string
    {
        return "EXISTS (
            SELECT 1 FROM cotizaciones cot
             WHERE cot.cliente_id = $cidCol AND cot.estado='aceptada' AND cot.deleted_at IS NULL
               AND cot.incluye_igv = 1
               AND NOT EXISTS (SELECT 1 FROM facturas_cliente f WHERE f.cotizacion_id = cot.id)
               AND EXISTS (SELECT 1 FROM pagos_cliente pi WHERE pi.cotizacion_id = cot.id
                             AND pi.deleted_at IS NULL AND pi.tipo='inicial' AND pi.estado='validado')
               AND EXISTS (SELECT 1 FROM pagos_cliente pf WHERE pf.cotizacion_id = cot.id
                             AND pf.deleted_at IS NULL AND pf.tipo='final'   AND pf.estado='validado')
        )";
    }

    /** Conteos para UN cliente. */
    function gg_empresa_pendientes_counts(PDO $pdo, int $cliente_id): array
    {
        $map = gg_empresas_pendientes_counts($pdo, [$cliente_id]);
        return $map[$cliente_id] ?? [];
    }

    /** Estructura completa {total, items} para UN cliente (ficha detalle). */
    function gg_empresa_pendientes(PDO $pdo, int $cliente_id): array
    {
        return gg_pendientes_build(gg_empresa_pendientes_counts($pdo, $cliente_id));
    }

    /** HTML del desglose para el popover (window.ggiChipPop). */
    function gg_pendientes_popover_html(array $pend): string
    {
        if (($pend['total'] ?? 0) <= 0) return '';
        $h    = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
        $html = '<div class="ggi-pend-pop"><div class="ggi-pend-pop-title">Pendientes</div><ul class="ggi-pend-pop-list">';
        foreach ($pend['items'] as $it) {
            $html .= '<li>' . $h($it['label']) . '</li>';
        }
        $html .= '</ul></div>';
        return $html;
    }
}
