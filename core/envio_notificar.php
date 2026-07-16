<?php
/**
 * Helper central para notificar cambios de estado de un envío.
 *
 * Mapea estado_envio_id → event_code y despacha vía NotificationService
 * (que resuelve destinatarios internos + WhatsApp al contacto del cliente).
 *
 * DEBE llamarse UNA sola vez por avance real de estado para evitar duplicados:
 *  - tracking auto-sync: solo cuando sincronizar() devuelve un id (avanzó)
 *  - cambio manual (kanban / editar): solo cuando el estado cambió respecto al anterior
 *
 * Estados sin regla de notificación activa (Por preparar, Recepcionado, Devuelto,
 * No Enviado, y actualmente Preparado/Despachado sin reglas) simplemente no despachan.
 */
require_once __DIR__ . '/NotificationService.php';

if (!function_exists('gg_courier_con_tracking')) {
    /**
     * ¿La agencia tiene integración de tracking automático (Olva/Shalom/Serpost)?
     * Para estas, el estado lo maneja el tracking; el cambio manual queda bloqueado.
     * Presencial, Flores y otras sin API siguen permitiendo cambio manual.
     */
    function gg_courier_con_tracking(?string $agencia_nombre): bool
    {
        $n = strtolower((string)$agencia_nombre);
        return str_contains($n, 'olva')
            || str_contains($n, 'shalom')
            || str_contains($n, 'serpost');
    }
}

if (!function_exists('gg_envio_notificar_estado')) {
    /**
     * @param \PDO     $pdo
     * @param int      $envio_id
     * @param int      $estado_id    nuevo estado_envio_id
     * @param int|null $triggered_by user_id que originó el cambio (se excluye de destinatarios)
     * @return int  número de notificaciones/mensajes despachados (0 si el estado no notifica)
     */
    function gg_envio_notificar_estado(\PDO $pdo, int $envio_id, int $estado_id, ?int $triggered_by = null): int
    {
        // estado_envio_id → event_code
        static $MAP = [
            3 => 'envio_en_camino',   // En tránsito
            4 => 'envio_en_destino',  // En distribución
            5 => 'envio_entregado',   // Entregado
            1 => 'envio_en_preparacion', // Preparado (sin regla activa hoy; inocuo)
            2 => 'envio_despachado',     // Despachado (sin regla activa hoy; inocuo)
        ];
        $event = $MAP[$estado_id] ?? null;
        if (!$event) return 0;

        $st = $pdo->prepare("
            SELECT e.cliente_id, e.codigo_envio, e.numero_orden,
                   e.destinatario, e.destinatario_dni, e.codigo_recojo,
                   es.nombre AS estado_nombre,
                   COALESCE(c.razon_social, '') AS razon_social,
                   COALESCE(ag.nombre, '')     AS agencia,
                   (SELECT GROUP_CONCAT(DISTINCT SUBSTRING_INDEX(n.codigo, ':', 1)
                             ORDER BY SUBSTRING_INDEX(n.codigo, ':', 1) SEPARATOR ', ')
                      FROM envios_certificados ec
                      JOIN certificados cert ON cert.id = ec.certificado_id
                      JOIN normas_iso n      ON n.id = cert.norma_iso_id
                     WHERE ec.envio_id = e.id) AS normas
            FROM envios e
            JOIN estados_envio es ON es.id = ?
            LEFT JOIN clientes c        ON c.id = e.cliente_id
            LEFT JOIN agencias_envio ag ON ag.id = e.agencia_envio_id
            WHERE e.id = ?
        ");
        $st->execute([$estado_id, $envio_id]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return 0;

        // Código de rastreo correcto según courier: Shalom rastrea con la GUÍA (numero_orden);
        // Olva y los demás usan el codigo_envio. Fallback al otro si el principal está vacío.
        $es_shalom = stripos($row['agencia'], 'shalom') !== false;
        $codigo = $es_shalom
            ? ($row['numero_orden'] ?: $row['codigo_envio'])
            : ($row['codigo_envio'] ?: $row['numero_orden']);

        $emp = $row['razon_social'] ?: 'el cliente';
        return NotificationService::dispatch($event, [
            'id'           => $envio_id,
            'cliente_id'   => (int)$row['cliente_id'],
            'codigo_envio'     => $codigo ?: '',           // código de rastreo correcto por courier
            'numero_orden'     => $row['numero_orden'] ?: '',
            'agencia'          => $row['agencia'] ?: '',   // dónde recoger
            'normas'           => gg_normas_compact($row['normas'] ?: ''),  // "ISO 9001, 14001, …"
            'destinatario'     => $row['destinatario'] ?: '',        // quién puede recoger
            'destinatario_dni' => $row['destinatario_dni'] ?: '',    // DNI autorizado
            // combinado "NOMBRE — DNI 12345678" (una sola variable para el template de recojo)
            'destinatario_full' => trim(($row['destinatario'] ?: '') .
                                   ($row['destinatario_dni'] ? ' — DNI ' . $row['destinatario_dni'] : '')),
            // formato "DNI - Nombres" para el template de recojo (envio_dest {{4}})
            'destinatario_recojo' => trim(($row['destinatario_dni'] ? $row['destinatario_dni'] . ' - ' : '') .
                                     ($row['destinatario'] ?: '')),
            'clave_recojo'     => $row['codigo_recojo'] ?: '',       // clave para retirar en agencia
            'triggered_by' => $triggered_by,
            'title'        => $row['estado_nombre'],
            'message'      => "Envío #{$envio_id} de {$emp}: {$row['estado_nombre']}.",
        ]);
    }
}
