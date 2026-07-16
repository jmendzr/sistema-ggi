<?php
/**
 * generar.php — Generador de pagos por propuesta aceptada
 *
 * NO se llama directamente. Se incluye desde ajax_estado_cot.php:
 *
 *   require_once __DIR__ . '/../pagos/generar.php';
 *   generar_pagos_cotizacion($pdo, $cot_id, $cliente_id);
 */

if (!function_exists('generar_pagos_cotizacion')) {

    /**
     * Genera 2 registros en pagos_cliente (inicial 50% y final 50%)
     * para la propuesta aceptada, si aún no existen.
     *
     * @param PDO $pdo
     * @param int $cot_id
     * @param int $cliente_id
     */
    function generar_pagos_cotizacion(\PDO $pdo, int $cot_id, int $cliente_id): void
    {
        try {
            // Verificar si ya se generaron pagos para esta propuesta
            $existe = $pdo->prepare("SELECT COUNT(*) FROM pagos_cliente WHERE cotizacion_id = ?");
            $existe->execute([$cot_id]);
            if ((int)$existe->fetchColumn() > 0) return;

            // Obtener monto y moneda de la propuesta
            $stmt = $pdo->prepare("SELECT monto_total, moneda FROM cotizaciones WHERE id = ?");
            $stmt->execute([$cot_id]);
            $cot = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$cot || (float)$cot['monto_total'] <= 0) return;

            $monto_total = (float)$cot['monto_total'];
            $moneda      = $cot['moneda'] ?: 'PEN';
            $mitad        = round($monto_total / 2, 2);
            // El pago final recibe el resto para no perder centavos por redondeo
            $mitad_final = round($monto_total - $mitad, 2);

            $ins = $pdo->prepare("
                INSERT INTO pagos_cliente
                  (cliente_id, cotizacion_id, tipo, porcentaje, monto, moneda, estado)
                VALUES (?, ?, ?, 50.00, ?, ?, 'pendiente_voucher')
            ");

            $ins->execute([$cliente_id, $cot_id, 'inicial', $mitad,       $moneda]);
            $ins->execute([$cliente_id, $cot_id, 'final',   $mitad_final, $moneda]);

        } catch (\PDOException $e) {
            error_log('[pagos] Error generando pagos para propuesta #' . $cot_id . ': ' . $e->getMessage());
        }
    }

}
