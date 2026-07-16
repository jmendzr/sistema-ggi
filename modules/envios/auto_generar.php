<?php
/**
 * auto_generar.php — Auto-generación de envío al aceptar una cotización
 *
 * Incluir desde ajax_estado_cot.php:
 *   require_once __DIR__ . '/../envios/auto_generar.php';
 *   auto_generar_envio($pdo, $cot_id, $cliente_id, $user_id);
 */

if (!function_exists('auto_generar_envio')) {

    /**
     * Crea un envío stub vinculado a la cotización aceptada.
     *
     * - Verifica que no exista ya un envío para esa cotización.
     * - Vincula automáticamente todos los certificados en_proceso de la cotización.
     * - Deja agencia_envio_id = NULL (gestor la asigna después).
     * - Estado: primer estado activo (ej. "Preparando").
     *
     * @param PDO $pdo
     * @param int $cot_id
     * @param int $cliente_id
     * @param int $user_id     ID del usuario que acepta
     * @return int|null        ID del envío creado, o null si ya existe / error
     */
    function auto_generar_envio(\PDO $pdo, int $cot_id, int $cliente_id, int $user_id): ?int
    {
        try {
            // 1. Verificar que no exista ya un envío para esta cotización
            $existe = $pdo->prepare(
                "SELECT id FROM envios WHERE cotizacion_id = ? LIMIT 1"
            );
            $existe->execute([$cot_id]);
            if ($existe->fetchColumn()) {
                return null; // Ya existe
            }

            // 2. Estado "Por preparar" — buscar por nombre exacto para evitar
            //    ambigüedad cuando varios estados comparten el mismo valor de orden.
            //    Fallback: primer estado activo por orden+id si no existe ese nombre.
            $estado_id = $pdo->query(
                "SELECT id FROM estados_envio WHERE activo=1 AND nombre='Por preparar' LIMIT 1"
            )->fetchColumn();
            if (!$estado_id) {
                $estado_id = $pdo->query(
                    "SELECT id FROM estados_envio WHERE activo=1 ORDER BY orden, id LIMIT 1"
                )->fetchColumn();
            }
            if (!$estado_id) return null;

            // 3. Obtener número de cotización para observaciones
            $cot_num = $pdo->prepare("SELECT numero FROM cotizaciones WHERE id=?");
            $cot_num->execute([$cot_id]);
            $numero_cot = $cot_num->fetchColumn() ?: "COT-$cot_id";

            // 4. Código de recojo automático: año invertido (ej. 2026 → 2620)
            $año = date('Y');
            $codigo_recojo = substr($año, 2) . substr($año, 0, 2);

            // 5. Insertar envío stub
            $pdo->prepare("
                INSERT INTO envios
                  (cliente_id, cotizacion_id, agencia_envio_id, estado_envio_id,
                   codigo_recojo, fecha_envio, observaciones, registrado_por)
                VALUES (?, ?, NULL, ?, ?, CURDATE(), ?, ?)
            ")->execute([
                $cliente_id,
                $cot_id,
                $estado_id,
                $codigo_recojo,
                null,   // observaciones: sin auto-texto (antes "Generado automáticamente desde cotización …")
                $user_id,
            ]);
            $envio_id = (int)$pdo->lastInsertId();

            // 6. Vincular certificados en_proceso de esta cotización
            $certs = $pdo->prepare(
                "SELECT id FROM certificados WHERE cotizacion_id=? AND estado NOT IN ('vencido','suspendido','rechazado')"
            );
            $certs->execute([$cot_id]);
            $ins_cert = $pdo->prepare(
                "INSERT IGNORE INTO envios_certificados (envio_id, certificado_id) VALUES (?,?)"
            );
            foreach ($certs->fetchAll(\PDO::FETCH_COLUMN) as $cert_id) {
                $ins_cert->execute([$envio_id, (int)$cert_id]);
            }

            return $envio_id;

        } catch (\PDOException $e) {
            error_log('[auto_generar_envio] Error para cotización #' . $cot_id . ': ' . $e->getMessage());
            return null;
        }
    }

}

/* ─────────────────────────────────────────────────────────────────────────────
   auto_generar_envio_cert — Crea un envío desde un certificado creado
   manualmente (sin cotización asociada).

   Diferencias vs auto_generar_envio():
   - No requiere cotizacion_id.
   - Comprueba duplicados por certificado (tabla envios_certificados).
   - Vincula solo el certificado recién creado.

   @param PDO $pdo
   @param int $cert_id       ID del certificado recién creado
   @param int $cliente_id
   @param int $user_id
   @return int|null          ID del envío creado, null si ya existe o error
 ─────────────────────────────────────────────────────────────────────────────*/
if (!function_exists('auto_generar_envio_cert')) {

    function auto_generar_envio_cert(\PDO $pdo, int $cert_id, int $cliente_id, int $user_id): ?int
    {
        try {
            // 1. Verificar que no exista ya un envío vinculado a este certificado
            $existe = $pdo->prepare("
                SELECT e.id FROM envios e
                JOIN envios_certificados ec ON ec.envio_id = e.id
                WHERE ec.certificado_id = ? AND e.deleted_at IS NULL
                LIMIT 1
            ");
            $existe->execute([$cert_id]);
            if ($existe->fetchColumn()) {
                return null; // Ya existe — no duplicar
            }

            // 2. Estado "Por preparar" con fallback determinístico
            $estado_id = $pdo->query(
                "SELECT id FROM estados_envio WHERE activo=1 AND nombre='Por preparar' LIMIT 1"
            )->fetchColumn();
            if (!$estado_id) {
                $estado_id = $pdo->query(
                    "SELECT id FROM estados_envio WHERE activo=1 ORDER BY orden, id LIMIT 1"
                )->fetchColumn();
            }
            if (!$estado_id) return null;

            // 3. Código de recojo automático (año invertido: 2026 → 2620)
            $año = date('Y');
            $codigo_recojo = substr($año, 2) . substr($año, 0, 2);

            // 4. Insertar envío — agencia queda NULL (gestor la asigna después)
            $pdo->prepare("
                INSERT INTO envios
                  (cliente_id, agencia_envio_id, estado_envio_id,
                   codigo_recojo, observaciones, registrado_por)
                VALUES (?, NULL, ?, ?, ?, ?)
            ")->execute([
                $cliente_id,
                $estado_id,
                $codigo_recojo,
                null,   // observaciones: sin auto-texto (antes "Generado automáticamente desde certificado #…")
                $user_id,
            ]);
            $envio_id = (int)$pdo->lastInsertId();

            // 5. Vincular el certificado al envío
            $pdo->prepare(
                "INSERT IGNORE INTO envios_certificados (envio_id, certificado_id) VALUES (?, ?)"
            )->execute([$envio_id, $cert_id]);

            return $envio_id;

        } catch (\PDOException $e) {
            error_log('[auto_generar_envio_cert] Error cert #' . $cert_id . ': ' . $e->getMessage());
            return null;
        }
    }

}

/* ─────────────────────────────────────────────────────────────────────────────
   verificar_envio_por_cotizacion — Punto de entrada desde los cambios de estado.

   Lógica de negocio:
   - Si el certificado viene de una cotización, verifica que TODOS los certs
     de esa cotización hayan pasado a 'vigente' o 'por_vencer'.
   - Solo cuando todos están activos se crea el envío (anti-duplicado incluido).
   - Si el certificado NO tiene cotización, delega a auto_generar_envio_cert().

   Llamar desde:
     ajax_estado_cert.php, editar.php, ajax_bulk.php, crear.php, ajax_crear.php
   cada vez que un certificado cambia a 'vigente'.

   @param PDO $pdo
   @param int $cert_id    Certificado que acaba de volverse vigente
   @param int $user_id
   @return int|null       ID del envío si fue creado; null si no corresponde aún
 ─────────────────────────────────────────────────────────────────────────────*/
if (!function_exists('verificar_envio_por_cotizacion')) {

    function verificar_envio_por_cotizacion(\PDO $pdo, int $cert_id, int $user_id): ?int
    {
        try {
            // 1. Obtener cotizacion_id y cliente_id del certificado
            $s = $pdo->prepare(
                "SELECT cotizacion_id, cliente_id FROM certificados WHERE id = ? AND deleted_at IS NULL"
            );
            $s->execute([$cert_id]);
            $cert = $s->fetch(\PDO::FETCH_ASSOC);
            if (!$cert) return null;

            $cot_id = (int)($cert['cotizacion_id'] ?? 0);
            $cli_id = (int)($cert['cliente_id']    ?? 0);

            // 2. Sin cotización → usar lógica individual de cert manual
            if (!$cot_id || !$cli_id) {
                return auto_generar_envio_cert($pdo, $cert_id, $cli_id, $user_id);
            }

            // 3. Total de certs no eliminados para esta cotización
            $q = $pdo->prepare(
                "SELECT COUNT(*) FROM certificados WHERE cotizacion_id = ? AND deleted_at IS NULL"
            );
            $q->execute([$cot_id]);
            $total = (int)$q->fetchColumn();
            if ($total === 0) return null;

            // 4. Certs ya activos (vigente o por_vencer)
            $q2 = $pdo->prepare("
                SELECT COUNT(*) FROM certificados
                WHERE cotizacion_id = ? AND deleted_at IS NULL
                  AND estado IN ('vigente','por_vencer')
            ");
            $q2->execute([$cot_id]);
            $activos = (int)$q2->fetchColumn();

            // 5. Si todavía hay certs pendientes de activarse, esperar
            if ($activos < $total) return null;

            // 6. Todos activos → crear envío (auto_generar_envio ya verifica duplicados)
            return auto_generar_envio($pdo, $cot_id, $cli_id, $user_id);

        } catch (\PDOException $e) {
            error_log('[verificar_envio_por_cotizacion] Error cert #' . $cert_id . ': ' . $e->getMessage());
            return null;
        }
    }

}
