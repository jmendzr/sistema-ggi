<?php
/**
 * ajax_generar.php — Generador de bonos por cotización aceptada
 *
 * Este archivo NO se llama directamente desde el navegador.
 * Se incluye desde ajax_estado_cot.php con:
 *
 *   require_once __DIR__ . '/../bonos/ajax_generar.php';
 *   generar_bonos_cotizacion($pdo, $cot_id);
 *
 * Requiere que las tablas bonos_config y bonos_ejecutivos existan.
 * Si no existen, la función retorna silenciosamente para no romper
 * el flujo de cambio de estado de cotización.
 */

if (!function_exists('generar_bonos_cotizacion')) {

    /**
     * Genera registros en bonos_ejecutivos para cada norma de la cotización.
     *
     * Lógica:
     * 1. Obtiene el ejecutivo_id de la cotización.
     * 2. Obtiene todas las normas asociadas a esa cotización (cotizacion_normas).
     * 3. Por cada norma, verifica si ya existe un bono para esa combinación cot+norma.
     * 4. Busca el monto configurado: primero por norma específica, luego el bono por defecto (norma_iso_id IS NULL).
     * 5. Si monto > 0, inserta el registro de bono con estado 'pendiente'.
     *
     * @param PDO $pdo     Conexión PDO activa.
     * @param int $cot_id  ID de la cotización aceptada.
     */
    function generar_bonos_cotizacion(\PDO $pdo, int $cot_id): void
    {
        try {
            // 1. Obtener el ejecutivo asignado a la cotización
            $stmt = $pdo->prepare("SELECT ejecutivo_id FROM cotizaciones WHERE id = ?");
            $stmt->execute([$cot_id]);
            $cot = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$cot || empty($cot['ejecutivo_id'])) {
                // Cotización sin ejecutivo asignado — no generar bono
                return;
            }

            $ejecutivo_id = (int)$cot['ejecutivo_id'];

            // 2. Obtener las normas vinculadas a esta cotización
            $stmt_normas = $pdo->prepare(
                "SELECT norma_iso_id FROM cotizacion_normas WHERE cotizacion_id = ?"
            );
            $stmt_normas->execute([$cot_id]);
            $normas = $stmt_normas->fetchAll(\PDO::FETCH_COLUMN);

            if (empty($normas)) {
                return;
            }

            // Preparar queries reutilizables
            $chk_exist = $pdo->prepare(
                "SELECT id FROM bonos_ejecutivos WHERE cotizacion_id = ? AND norma_iso_id = ? LIMIT 1"
            );
            $get_monto_norma = $pdo->prepare(
                "SELECT monto_bono FROM bonos_config WHERE norma_iso_id = ? AND activo = 1 LIMIT 1"
            );
            $ins_bono = $pdo->prepare(
                "INSERT IGNORE INTO bonos_ejecutivos (ejecutivo_id, cotizacion_id, norma_iso_id, monto)
                 VALUES (?, ?, ?, ?)"
            );

            // Obtener bono por defecto una sola vez
            $default_monto = $pdo->query(
                "SELECT monto_bono FROM bonos_config WHERE norma_iso_id IS NULL AND activo = 1 LIMIT 1"
            )->fetchColumn();
            $default_monto = ($default_monto !== false) ? (float)$default_monto : 0.0;

            // 3. Procesar cada norma
            foreach ($normas as $norma_id) {
                $norma_id = (int)$norma_id;

                // Evitar duplicados
                $chk_exist->execute([$cot_id, $norma_id]);
                if ($chk_exist->fetch()) {
                    continue; // Bono ya generado para esta combinación
                }

                // Resolver monto: específico de la norma primero, luego el default
                $get_monto_norma->execute([$norma_id]);
                $monto = $get_monto_norma->fetchColumn();

                if ($monto === false || $monto === null) {
                    $monto = $default_monto;
                } else {
                    $monto = (float)$monto;
                }

                // No insertar bono de monto cero
                if ($monto <= 0) {
                    continue;
                }

                // 4. Insertar bono con estado 'pendiente'
                $ins_bono->execute([$ejecutivo_id, $cot_id, $norma_id, $monto]);
            }

        } catch (\PDOException $e) {
            // Registrar en error_log pero no interrumpir el flujo de cotizaciones
            error_log('[bonos] Error generando bonos para cotizacion #' . $cot_id . ': ' . $e->getMessage());
        }
    }

}
