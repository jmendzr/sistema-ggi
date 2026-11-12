<?php
/**
 * modules/facturas/factura_finalizar.php
 *
 * Finaliza una factura Alegra YA ACEPTADA por SUNAT: descarga el PDF definitivo
 * (si aún no está) y la envía al cliente por WhatsApp (una sola vez). Reutilizado
 * por la emisión (`emitir_alegra.php`) cuando la aceptación es inmediata y por el
 * cron de re-chequeo (`cron/facturas_sunat_recheck.php`) cuando llega después.
 *
 * IMPORTANTE: nunca se envía al cliente un comprobante que aún no aceptó SUNAT.
 */
require_once __DIR__ . '/../../core/AlegraService.php';
require_once __DIR__ . '/../../core/NotificationService.php';

if (!function_exists('factura_finalizar_aceptada')) {
    /**
     * @return array ['ok'=>bool, 'pdf'=>?string, 'wa'=>bool, 'msg'=>?string]
     */
    function factura_finalizar_aceptada(\PDO $pdo, int $rowId, ?int $triggeredBy = null): array
    {
        $q = $pdo->prepare("SELECT f.*, cl.razon_social FROM facturas_cliente f JOIN clientes cl ON cl.id = f.cliente_id WHERE f.id = ?");
        $q->execute([$rowId]);
        $f = $q->fetch(\PDO::FETCH_ASSOC);
        if (!$f) return ['ok'=>false, 'msg'=>'factura no encontrada'];

        $alegraId  = (int)$f['alegra_id'];
        $clienteId = (int)$f['cliente_id'];
        $numero    = $f['numero_factura'] ?: ('#' . $alegraId);

        // 1) PDF definitivo (aceptado) — descargar solo si aún no está guardado.
        $pdfPath   = $f['archivo_path'];
        $pdfNombre = $f['archivo_nombre'];
        if (empty($pdfPath)) {
            $info = AlegraService::descargarPdf($alegraId, $clienteId, (string)($f['numero_factura'] ?? ''));
            if ($info) {
                [$pdfPath, $pdfNombre] = $info;
                $pdo->prepare("UPDATE facturas_cliente SET archivo_path=?, archivo_nombre=? WHERE id=?")
                    ->execute([$pdfPath, $pdfNombre, $rowId]);
            }
        }

        // 2) Marcar aceptada.
        $pdo->prepare("UPDATE facturas_cliente SET estado_timbrado='STAMPED_AND_ACCEPTED' WHERE id=?")->execute([$rowId]);

        // 3) WhatsApp al cliente — solo con PDF y una única vez.
        $waEnviado = (bool)$f['wa_enviado'];
        if ($pdfPath && !$waEnviado) {
            try {
                NotificationService::dispatch('factura_emitida', [
                    'id'            => $rowId,
                    'cliente_id'    => $clienteId,
                    'cotizacion_id' => ($f['cotizacion_id'] ?: null),
                    // {{2}} de la plantilla = N° de FACTURA (FA01-XXXXX).
                    'cot_numero'    => $numero,
                    'triggered_by'  => $triggeredBy,
                    'title'         => "Factura emitida — {$f['razon_social']}",
                    'message'       => "Se emitió la factura {$numero} para {$f['razon_social']}.",
                    'doc_url'       => APP_URL . '/' . $pdfPath,
                    'doc_filename'  => $pdfNombre,
                ]);
                $pdo->prepare("UPDATE facturas_cliente SET wa_enviado=1, wa_enviado_at=NOW() WHERE id=?")->execute([$rowId]);
                NotificationService::markResolved(['factura_por_cargar'], $clienteId);
                $waEnviado = true;
            } catch (\Throwable $e) {
                error_log('[factura_finalizar] WhatsApp: ' . $e->getMessage());
            }
        }

        return ['ok'=>true, 'pdf'=>$pdfPath, 'wa'=>$waEnviado];
    }
}
