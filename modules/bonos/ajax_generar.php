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
            // 1. Destinatario del bono. Regla: si quien CREÓ la propuesta es el
            //    Gerente Comercial, el bono es SUYO aunque el documento salga a
            //    nombre de un ejecutivo (usó su perfil). En cualquier otro caso el
            //    bono va al ejecutivo asignado (ejecutivo_id).
            $stmt = $pdo->prepare("SELECT c.ejecutivo_id, c.creado_por, u.rol AS creador_rol
                                     FROM cotizaciones c
                                     LEFT JOIN usuarios u ON u.id = c.creado_por
                                    WHERE c.id = ?");
            $stmt->execute([$cot_id]);
            $cot = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$cot) return;

            $ejecutivo_id = ($cot['creador_rol'] === 'gerente_comercial')
                ? (int)$cot['creado_por']
                : (int)$cot['ejecutivo_id'];

            if (!$ejecutivo_id) {
                // Sin destinatario (propuesta sin ejecutivo y no creada por el comercial)
                return;
            }

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
