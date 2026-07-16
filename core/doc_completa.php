<?php
/**
 * doc_completa.php — Verificación centralizada de "documentación completa" de un cliente.
 *
 * Criterio (jun 2026): un cliente tiene la documentación completa cuando cuenta con
 *   1) Ficha RUC vigente, y
 *   2) Acta de Alcance APROBADA (confirmada = 1).
 * La Copia Literal ya NO es requerida.
 *
 * Se invoca desde los dos puntos donde puede completarse el par, sin importar el orden:
 *   - modules/documentos/subir.php          (al subir la Ficha RUC)
 *   - modules/clientes/confirmar_acta_alcance.php (al aprobar el Acta de Alcance)
 *
 * Dispara el evento 'empresa_documentacion_completa' UNA sola vez por cliente
 * (anti-duplicado: no re-dispara si ya existe una notificación de ese evento
 * para el cliente).
 */

if (!function_exists('gg_verificar_documentacion_completa')) {
    /**
     * @return bool true si se disparó el evento en esta llamada.
     */
    function gg_verificar_documentacion_completa(PDO $pdo, int $cliente_id, ?int $triggered_by = null): bool
    {
        if (!$cliente_id) return false;

        // 1) Ficha RUC vigente
        $ruc = $pdo->prepare("
            SELECT 1 FROM documentos_cliente
            WHERE cliente_id = ? AND tipo_documento = 'ficha_ruc' AND es_vigente = 1
            LIMIT 1
        ");
        $ruc->execute([$cliente_id]);
        if (!$ruc->fetchColumn()) return false;

        // 2) Acta de Alcance aprobada (confirmada) — SOLO si aplica según las normas.
        //    Si ninguna norma de las propuestas aceptadas la exige, el acta no aplica
        //    y la documentación se considera completa solo con la Ficha RUC.
        $acta_aplica = !function_exists('acta_alcance_requerida') || acta_alcance_requerida($pdo, $cliente_id);
        if ($acta_aplica) {
            $acta = $pdo->prepare("
                SELECT 1 FROM documentos_cliente
                WHERE cliente_id = ? AND tipo_documento = 'acta_alcance' AND es_vigente = 1 AND confirmada = 1
                LIMIT 1
            ");
            $acta->execute([$cliente_id]);
            if (!$acta->fetchColumn()) return false;
        }

        // 3) Anti-duplicado: no re-disparar si ya se notificó para este cliente
        $ya = $pdo->prepare("
            SELECT 1 FROM notificaciones
            WHERE event_code = 'empresa_documentacion_completa' AND reference_id = ?
            LIMIT 1
        ");
        $ya->execute([$cliente_id]);
        if ($ya->fetchColumn()) return false;

        // Documentación completa → disparar evento
        $cl = $pdo->prepare("SELECT razon_social FROM clientes WHERE id = ?");
        $cl->execute([$cliente_id]);
        $razon = $cl->fetchColumn() ?: '';

        require_once __DIR__ . '/NotificationService.php';
        NotificationService::dispatch('empresa_documentacion_completa', [
            'cliente_id'   => $cliente_id,
            'triggered_by' => $triggered_by,
            'title'        => "Documentación completa — {$razon}",
            'message'      => "{$razon} ya cuenta con la Ficha RUC y el Acta de Alcance aprobada.",
        ]);
        return true;
    }
}
