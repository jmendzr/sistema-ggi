<?php
/**
 * core/WaCampanas.php — Envíos masivos por WhatsApp, nativos sobre Meta.
 *
 * Meta NO tiene un endpoint de "broadcast": cada mensaje es una llamada
 * individual. Enviar en el momento de pulsar el botón haría que una campaña de
 * 300 contactos tardara minutos con la petición web abierta, y cualquier corte
 * dejaría la campaña a medias sin saber por dónde iba.
 *
 * Por eso el envío es una COLA: la campaña se encola completa y un cron la va
 * despachando por lotes. Así se puede reanudar, reintentar solo lo fallido y
 * ver el avance real.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/WhatsAppService.php';
require_once __DIR__ . '/WaInbox.php';

class WaCampanas
{
    /** Mensajes por ejecución del cron. Conservador a propósito: ver nota abajo. */
    public const LOTE = 40;

    /** Pausa entre mensajes, en microsegundos (≈8 por segundo). */
    public const PAUSA_US = 120000;

    /** Reintentos por destinatario antes de darlo por perdido. */
    public const MAX_INTENTOS = 3;

    /**
     * Encola una campaña. No envía nada: deja todo listo para el cron.
     *
     * @param array $dest Filas con al menos telefono; opcional cliente_id,
     *                    empresa y params (valores ya resueltos de la plantilla).
     * @return array{campana_id:int,encolados:int,omitidos:int}
     */
    public static function encolar(
        \PDO $pdo, string $nombre, string $plantilla, string $idioma,
        array $paramKeys, string $segmento, array $dest, int $usuarioId
    ): array {
        // template_id es NOT NULL y viene de la época de Kapso, donde el id era
        // distinto del nombre. En Meta el nombre ES el identificador, así que se
        // guarda el mismo valor en ambas columnas.
        $pdo->prepare(
            "INSERT INTO whatsapp_campanas
                (nombre, template_id, template_name, template_lang, param_keys, segmento,
                 total_destinatarios, estado, creado_por)
             VALUES (?, ?, ?, ?, ?, ?, 0, 'encolada', ?)"
        )->execute([$nombre, $plantilla, $plantilla, $idioma, json_encode($paramKeys), $segmento, $usuarioId]);

        $campanaId = (int) $pdo->lastInsertId();

        // INSERT IGNORE + UNIQUE(campana_id, telefono): si el segmento trae el
        // mismo teléfono dos veces, el cliente recibe UN mensaje, no dos.
        $ins = $pdo->prepare(
            "INSERT IGNORE INTO wa_campana_destinatarios
                (campana_id, cliente_id, telefono, empresa, params_json)
             VALUES (?, ?, ?, ?, ?)"
        );

        $ok = 0; $omitidos = 0;
        foreach ($dest as $d) {
            $tel = WhatsAppService::normalizePhone((string) ($d['telefono'] ?? $d['telefono_raw'] ?? ''));
            if ($tel === '') { $omitidos++; continue; }

            $ins->execute([
                $campanaId,
                !empty($d['cliente_id']) ? (int) $d['cliente_id'] : null,
                $tel,
                $d['empresa'] ?? null,
                json_encode(array_values($d['params'] ?? []), JSON_UNESCAPED_UNICODE),
            ]);
            $ok += $ins->rowCount();
        }

        $pdo->prepare("UPDATE whatsapp_campanas SET total_destinatarios = ?, excluidos = ? WHERE id = ?")
            ->execute([$ok, $omitidos, $campanaId]);

        return ['campana_id' => $campanaId, 'encolados' => $ok, 'omitidos' => $omitidos];
    }

    /**
     * Despacha un lote de la campaña. Pensado para el cron.
     *
     * El ritmo es deliberadamente conservador: Meta limita por segundo y, más
     * importante, un volumen alto de plantillas hacia números que no responden
     * baja la calificación de calidad del número — y esa calificación afecta a
     * TODAS las notificaciones, no solo a la campaña.
     *
     * @return array{enviados:int,fallidos:int,restantes:int}
     */
    public static function procesarLote(\PDO $pdo, int $campanaId, int $lote = self::LOTE): array
    {
        $c = $pdo->prepare("SELECT * FROM whatsapp_campanas WHERE id = ?");
        $c->execute([$campanaId]);
        $camp = $c->fetch(\PDO::FETCH_ASSOC);
        if (!$camp || in_array($camp['estado'], ['pausada', 'cancelada'], true)) {
            return ['enviados' => 0, 'fallidos' => 0, 'restantes' => 0];
        }

        if ($camp['estado'] === 'encolada') {
            $pdo->prepare("UPDATE whatsapp_campanas SET estado = 'enviando', iniciada_at = NOW() WHERE id = ?")
                ->execute([$campanaId]);
        }

        $q = $pdo->prepare(
            "SELECT id, cliente_id, telefono, params_json, intentos
               FROM wa_campana_destinatarios
              WHERE campana_id = ? AND estado = 'pendiente' AND intentos < ?
              ORDER BY id LIMIT " . (int) $lote
        );
        $q->execute([$campanaId, self::MAX_INTENTOS]);
        $filas = $q->fetchAll(\PDO::FETCH_ASSOC);

        $upd = $pdo->prepare(
            "UPDATE wa_campana_destinatarios
                SET estado = ?, wa_message_id = ?, error_msg = ?, intentos = intentos + 1, enviado_at = NOW()
              WHERE id = ?"
        );

        $enviados = 0; $fallidos = 0;
        foreach ($filas as $f) {
            $params = json_decode($f['params_json'] ?? '[]', true) ?: [];

            $waId = WhatsAppService::sendTemplate(
                $f['telefono'], $camp['template_name'], $params, $camp['template_lang'] ?: 'es'
            );

            if ($waId) {
                $upd->execute(['enviado', $waId, null, $f['id']]);
                $enviados++;
            } else {
                // Se deja en 'pendiente' mientras queden intentos: un fallo de red
                // no debe descartar al destinatario.
                $err   = WhatsAppService::lastError();
                $ultimo = ((int) $f['intentos'] + 1) >= self::MAX_INTENTOS;
                $upd->execute([$ultimo ? 'fallido' : 'pendiente', null, $err, $f['id']]);
                $fallidos++;
            }

            // El mensaje también entra al hilo del inbox: si el cliente responde,
            // el ejecutivo ve qué se le mandó.
            self::registrarEnHilo($pdo, $f, $camp, $params, $waId);

            usleep(self::PAUSA_US);
        }

        $rest = $pdo->prepare(
            "SELECT COUNT(*) FROM wa_campana_destinatarios
              WHERE campana_id = ? AND estado = 'pendiente' AND intentos < ?"
        );
        $rest->execute([$campanaId, self::MAX_INTENTOS]);
        $restantes = (int) $rest->fetchColumn();

        if ($restantes === 0) {
            $pdo->prepare("UPDATE whatsapp_campanas SET estado = 'finalizada', finalizada_at = NOW() WHERE id = ?")
                ->execute([$campanaId]);
        }

        return ['enviados' => $enviados, 'fallidos' => $fallidos, 'restantes' => $restantes];
    }

    /** Deja constancia del envío en el hilo del inbox (best-effort). */
    private static function registrarEnHilo(\PDO $pdo, array $f, array $camp, array $params, ?string $waId): void
    {
        try {
            $texto = $camp['template_name'];
            $t = $pdo->prepare("SELECT body_text FROM whatsapp_templates WHERE template_name = ? LIMIT 1");
            $t->execute([$camp['template_name']]);
            if ($body = $t->fetchColumn()) {
                foreach ($params as $i => $v) $body = str_replace('{{' . ($i + 1) . '}}', (string) $v, $body);
                $texto = $body;
            }

            WaInbox::registrarSaliente(
                $pdo, $f['telefono'], $waId, 'template', $texto,
                $camp['template_name'], null, $waId ? 'sent' : 'failed',
                $waId ? null : WhatsAppService::lastError()
            );
        } catch (\Throwable $e) {
            error_log('[WaCampanas] hilo: ' . $e->getMessage());
        }
    }

    /** Métricas reales, calculadas de nuestra propia cola. */
    public static function metricas(\PDO $pdo, int $campanaId): array
    {
        $q = $pdo->prepare(
            "SELECT estado, COUNT(*) n FROM wa_campana_destinatarios
              WHERE campana_id = ? GROUP BY estado"
        );
        $q->execute([$campanaId]);

        $m = ['pendiente' => 0, 'enviado' => 0, 'entregado' => 0, 'leido' => 0, 'fallido' => 0];
        foreach ($q as $r) $m[$r['estado']] = (int) $r['n'];
        $m['total'] = array_sum($m);

        return $m;
    }

    /**
     * Refleja en la campaña el estado que llega por webhook.
     * Se llama desde el webhook con el message_id de Meta.
     */
    public static function actualizarEstado(\PDO $pdo, string $waMessageId, string $estado): void
    {
        $mapa = ['delivered' => 'entregado', 'read' => 'leido', 'failed' => 'fallido'];
        if (!isset($mapa[$estado])) return;

        // Solo avanzar: un 'delivered' que llega tarde no debe pisar un 'read'
        $orden = ['enviado' => 1, 'entregado' => 2, 'leido' => 3, 'fallido' => 4];
        $q = $pdo->prepare("SELECT id, estado FROM wa_campana_destinatarios WHERE wa_message_id = ?");
        $q->execute([$waMessageId]);
        $row = $q->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return;

        if (($orden[$mapa[$estado]] ?? 0) >= ($orden[$row['estado']] ?? 0)) {
            $pdo->prepare("UPDATE wa_campana_destinatarios SET estado = ? WHERE id = ?")
                ->execute([$mapa[$estado], $row['id']]);
        }
    }
}
