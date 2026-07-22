<?php
/**
 * core/WaInbox.php
 *
 * Hilo unificado de conversaciones de WhatsApp (inbox multiagente).
 *
 * Concentra la escritura sobre wa_conversaciones / wa_mensajes para que el
 * webhook, el envío automático (WhatsAppService) y el envío manual del
 * ejecutivo produzcan exactamente el mismo hilo.
 *
 * Reglas de negocio que viven aquí:
 *  - La ventana de servicio de Meta son 24h desde el ÚLTIMO mensaje entrante.
 *    Fuera de ella solo se pueden enviar plantillas aprobadas.
 *  - Una conversación se asigna a un único ejecutivo (claim atómico) y no se
 *    libera sola: solo al resolverla o si gerencia reasigna.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/Database.php';

class WaInbox
{
    /** Duración de la ventana de servicio de Meta, en horas. */
    public const VENTANA_HORAS = 24;

    /**
     * Devuelve el id de la conversación del teléfono dado, creándola si no existe.
     * Resuelve el cliente por teléfono cuando se puede.
     */
    public static function ensureConversacion(
        \PDO $pdo,
        string $telefono,
        ?string $phoneNumberId = null,
        ?int $clienteId = null
    ): int {
        $telefono      = preg_replace('/\D/', '', $telefono);
        $phoneNumberId = $phoneNumberId ?: (defined('META_WA_PHONE_NUMBER_ID') ? META_WA_PHONE_NUMBER_ID : '');

        if ($clienteId === null) {
            $clienteId = self::resolverCliente($pdo, $telefono);
        }

        // INSERT ... ON DUPLICATE KEY: evita la carrera entre dos webhooks simultáneos
        $pdo->prepare(
            "INSERT INTO wa_conversaciones (telefono, phone_number_id, cliente_id)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE cliente_id = COALESCE(cliente_id, VALUES(cliente_id))"
        )->execute([$telefono, $phoneNumberId, $clienteId]);

        $q = $pdo->prepare("SELECT id FROM wa_conversaciones WHERE telefono = ? AND phone_number_id = ?");
        $q->execute([$telefono, $phoneNumberId]);

        return (int) $q->fetchColumn();
    }

    /**
     * Registra un mensaje ENTRANTE y reabre la ventana de 24h.
     * Idempotente: si el webhook se reintenta, no duplica.
     */
    public static function registrarEntrante(
        \PDO $pdo,
        string $telefono,
        ?string $waMessageId,
        string $tipo,
        ?string $texto,
        ?string $phoneNumberId = null,
        ?array $media = null
    ): int {
        $convId = self::ensureConversacion($pdo, $telefono, $phoneNumberId);

        $ins = $pdo->prepare(
            "INSERT IGNORE INTO wa_mensajes
                (conversacion_id, wa_message_id, direccion, tipo, texto, media_id, media_mime, media_nombre)
             VALUES (?, ?, 'in', ?, ?, ?, ?, ?)"
        );
        $ins->execute([
            $convId, $waMessageId, $tipo, $texto,
            $media['id'] ?? null, $media['mime'] ?? null, $media['nombre'] ?? null,
        ]);

        // Solo mover contadores/ventana si el mensaje era nuevo de verdad
        if ($ins->rowCount() > 0) {
            $pdo->prepare(
                "UPDATE wa_conversaciones
                    SET ventana_expira_at      = DATE_ADD(NOW(), INTERVAL " . self::VENTANA_HORAS . " HOUR),
                        ultimo_mensaje_at      = NOW(),
                        ultimo_mensaje_preview = ?,
                        no_leidos              = no_leidos + 1,
                        estado                 = 'abierta'
                  WHERE id = ?"
            )->execute([self::preview($texto, $tipo), $convId]);
        }

        return $convId;
    }

    /**
     * Registra un mensaje SALIENTE (automático del sistema o escrito por un ejecutivo).
     * NO toca la ventana: solo un entrante del cliente la reabre.
     */
    public static function registrarSaliente(
        \PDO $pdo,
        string $telefono,
        ?string $waMessageId,
        string $tipo,
        ?string $texto,
        ?string $templateName = null,
        ?int $enviadoPor = null,
        string $estado = 'sent',
        ?string $errorMsg = null,
        ?string $phoneNumberId = null
    ): int {
        $convId = self::ensureConversacion($pdo, $telefono, $phoneNumberId);

        $ins = $pdo->prepare(
            "INSERT IGNORE INTO wa_mensajes
                (conversacion_id, wa_message_id, direccion, tipo, texto, template_name, estado, error_msg, enviado_por)
             VALUES (?, ?, 'out', ?, ?, ?, ?, ?, ?)"
        );
        $ins->execute([$convId, $waMessageId, $tipo, $texto, $templateName, $estado, $errorMsg, $enviadoPor]);

        if ($ins->rowCount() > 0) {
            $pdo->prepare(
                "UPDATE wa_conversaciones
                    SET ultimo_mensaje_at = NOW(), ultimo_mensaje_preview = ?
                  WHERE id = ?"
            )->execute([self::preview($texto, $tipo, $templateName), $convId]);
        }

        return $convId;
    }

    /**
     * Actualiza el estado de entrega de un saliente. Solo avanza (sent → delivered →
     * read), nunca retrocede, porque los webhooks pueden llegar desordenados.
     */
    public static function actualizarEstado(\PDO $pdo, string $waMessageId, string $estado, ?string $errorMsg = null): void
    {
        $orden = ['queued' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3, 'failed' => 4];
        if (!isset($orden[$estado])) return;

        $q = $pdo->prepare("SELECT id, estado FROM wa_mensajes WHERE wa_message_id = ?");
        $q->execute([$waMessageId]);
        $row = $q->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return;

        if (($orden[$estado] ?? 0) >= ($orden[$row['estado'] ?? 'queued'] ?? 0)) {
            $pdo->prepare("UPDATE wa_mensajes SET estado = ?, error_msg = ? WHERE id = ?")
                ->execute([$estado, $errorMsg, $row['id']]);
        }
    }

    /** ¿Se puede escribir texto libre, o Meta solo admite plantillas? */
    public static function ventanaAbierta(?string $ventanaExpiraAt): bool
    {
        return $ventanaExpiraAt !== null && strtotime($ventanaExpiraAt) > time();
    }

    /**
     * Toma la conversación para un ejecutivo. Atómico: si dos hacen clic a la vez,
     * la base decide y el segundo recibe false.
     */
    public static function asignar(\PDO $pdo, int $convId, int $usuarioId): bool
    {
        $st = $pdo->prepare(
            "UPDATE wa_conversaciones
                SET asignado_a = ?, asignado_at = NOW()
              WHERE id = ? AND asignado_a IS NULL"
        );
        $st->execute([$usuarioId, $convId]);

        return $st->rowCount() > 0;
    }

    /** Libera la conversación. $forzar lo usa gerencia para reasignar. */
    public static function liberar(\PDO $pdo, int $convId, int $usuarioId, bool $forzar = false): bool
    {
        $sql = "UPDATE wa_conversaciones SET asignado_a = NULL, asignado_at = NULL WHERE id = ?";
        $arg = [$convId];

        if (!$forzar) {
            $sql .= " AND asignado_a = ?";
            $arg[] = $usuarioId;
        }

        $st = $pdo->prepare($sql);
        $st->execute($arg);

        return $st->rowCount() > 0;
    }

    /** Resuelve un teléfono a cliente por los últimos 9 dígitos (móvil Perú). */
    private static function resolverCliente(\PDO $pdo, string $telefono): ?int
    {
        $last9 = substr($telefono, -9);
        if (strlen($last9) !== 9) return null;

        $limpio = "REPLACE(REPLACE(REPLACE(COALESCE(%s,''),' ',''),'+',''),'-','')";

        $q = $pdo->prepare("SELECT id FROM clientes WHERE deleted_at IS NULL AND "
            . sprintf($limpio, 'contacto_telefono') . " LIKE ? LIMIT 1");
        $q->execute(['%' . $last9]);
        if ($id = (int) $q->fetchColumn()) return $id;

        // Fallback: el contacto que negoció la propuesta
        $q = $pdo->prepare("SELECT cliente_id FROM cotizaciones WHERE deleted_at IS NULL AND cliente_id IS NOT NULL AND "
            . sprintf($limpio, 'contacto_telefono') . " LIKE ? ORDER BY id DESC LIMIT 1");
        $q->execute(['%' . $last9]);

        return (int) $q->fetchColumn() ?: null;
    }

    /** Texto corto para la lista de conversaciones. */
    private static function preview(?string $texto, string $tipo, ?string $templateName = null): string
    {
        if ($texto !== null && trim($texto) !== '') {
            return mb_substr(trim(preg_replace('/\s+/', ' ', $texto)), 0, 200);
        }

        $etiquetas = [
            'image'    => '📷 Imagen',
            'document' => '📄 Documento',
            'audio'    => '🎤 Audio',
            'video'    => '🎬 Video',
            'sticker'  => 'Sticker',
            'location' => '📍 Ubicación',
        ];

        return $etiquetas[$tipo] ?? ($templateName ? 'Plantilla: ' . $templateName : ucfirst($tipo));
    }
}
