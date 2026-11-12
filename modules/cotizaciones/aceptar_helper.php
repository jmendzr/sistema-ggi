<?php
/**
 * aceptar_helper.php — Efectos de datos al ACEPTAR una propuesta, compartidos por
 * el flujo INDIVIDUAL y el BULK de ajax_estado_cot.php.
 *
 * Crea los certificados en_proceso (por norma) y genera los pagos 50/50. Todo
 * idempotente: si ya existen, no duplica. Antes, el bulk-accept NO hacía esto y
 * las propuestas aceptadas en lote quedaban SIN certificados ni pagos.
 */

if (!function_exists('cot_generar_certs_y_pagos')) {

    /**
     * @return array ['certs' => int]  cuántos certificados nuevos se crearon
     */
    function cot_generar_certs_y_pagos(\PDO $pdo, int $cot_id, int $cliente_id, int $user_id): array
    {
        $certs = 0;

        // ── Certificados en_proceso por cada norma de la propuesta ──
        $normas = $pdo->prepare("SELECT norma_iso_id, casa_certificadora_id FROM cotizacion_normas WHERE cotizacion_id = ?");
        $normas->execute([$cot_id]);
        foreach ($normas->fetchAll(\PDO::FETCH_ASSOC) as $nc) {
            // Saltar si ya hay un certificado vigente/en proceso para este cliente+norma
            // (vencido/suspendido/rechazado sí permiten crear uno nuevo).
            $ex = $pdo->prepare("SELECT id FROM certificados WHERE cliente_id=? AND norma_iso_id=? AND estado NOT IN ('vencido','suspendido','rechazado')");
            $ex->execute([$cliente_id, $nc['norma_iso_id']]);
            if ($ex->fetch()) continue;

            $casa_id = $nc['casa_certificadora_id'] ?: 1; // QRO por defecto
            $pdo->prepare("
                INSERT INTO certificados
                  (cliente_id, cotizacion_id, norma_iso_id, casa_certificadora_id, estado, creado_por)
                VALUES (?, ?, ?, ?, 'en_proceso', ?)
            ")->execute([$cliente_id, $cot_id, $nc['norma_iso_id'], $casa_id, $user_id]);
            $certs++;
            if (function_exists('log_actividad')) log_actividad('certificados', 'crear', "Certificado en_proceso desde propuesta #$cot_id");
        }

        // ── Pagos inicial y final (50/50) — idempotente ──
        require_once __DIR__ . '/../pagos/generar.php';
        generar_pagos_cotizacion($pdo, $cot_id, $cliente_id);
        if (function_exists('log_actividad')) log_actividad('pagos', 'generar', "Pagos generados para propuesta #$cot_id (cliente #$cliente_id)");

        return ['certs' => $certs];
    }
}
