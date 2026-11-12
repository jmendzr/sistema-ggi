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
        ?array $media = null,
        bool $reenviado = false
    ): int {
        $convId = self::ensureConversacion($pdo, $telefono, $phoneNumberId);

        $ins = $pdo->prepare(
            "INSERT IGNORE INTO wa_mensajes
                (conversacion_id, wa_message_id, direccion, tipo, reenviado, texto, media_id, media_mime, media_nombre)
             VALUES (?, ?, 'in', ?, ?, ?, ?, ?, ?)"
        );
        $ins->execute([
            $convId, $waMessageId, $tipo, $reenviado ? 1 : 0, $texto,
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
                        sin_leer_manual        = 0,
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
        ?string $phoneNumberId = null,
        ?array $media = null,  // ['path'=>ruta relativa, 'mime'=>..., 'nombre'=>...] p. ej. la factura PDF adjunta
        bool $desdeApp = false // true = eco de coexistencia (enviado desde la app de WhatsApp del número)
    ): int {
        $convId = self::ensureConversacion($pdo, $telefono, $phoneNumberId);

        $ins = $pdo->prepare(
            "INSERT IGNORE INTO wa_mensajes
                (conversacion_id, wa_message_id, direccion, tipo, texto, template_name, estado, error_msg, enviado_por,
                 media_path, media_mime, media_nombre, desde_app)
             VALUES (?, ?, 'out', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $ins->execute([$convId, $waMessageId, $tipo, $texto, $templateName, $estado, $errorMsg, $enviadoPor,
            $media['path'] ?? null, $media['mime'] ?? null, $media['nombre'] ?? null, $desdeApp ? 1 : 0]);

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

    /**
     * Números que un usuario puede ver en el inbox.
     *
     * Regla: un número SIN lista de acceso lo ve todo el mundo — así el
     * comportamiento no cambia hasta que alguien decida restringir. Con lista,
     * solo quienes estén en ella. Gerencia ve todo.
     *
     * @return array|null  phone_number_id permitidos, o null = sin restricción.
     */
    public static function numerosVisibles(\PDO $pdo, int $usuarioId, bool $esJefe): ?array
    {
        if ($esJefe) return null;

        try {
            $q = $pdo->prepare(
                "SELECT n.phone_number_id
                   FROM wa_numeros n
                   LEFT JOIN wa_numero_usuarios nu ON nu.numero_id = n.id
                  WHERE n.activo = 1
                  GROUP BY n.id, n.phone_number_id
                 HAVING COUNT(nu.usuario_id) = 0
                     OR SUM(nu.usuario_id = ?) > 0"
            );
            $q->execute([$usuarioId]);
            $ids = $q->fetchAll(\PDO::FETCH_COLUMN) ?: [];
        } catch (\Throwable $e) {
            return null;   // ante un fallo, no ocultar nada por accidente
        }

        // Sin ningún número registrado todavía: no restringir
        $total = (int) $pdo->query("SELECT COUNT(*) FROM wa_numeros WHERE activo = 1")->fetchColumn();
        if ($total === 0) return null;

        return $ids;
    }

    /**
     * Reasigna una conversación a otro usuario. Solo gerencia.
     *
     * Se pisa la asignación actual a propósito: es la salida cuando alguien
     * queda con conversaciones tomadas y no está disponible. Queda registrado
     * quién lo hizo y a quién, porque quitarle el trabajo a un ejecutivo sin
     * dejar rastro genera discusiones que nadie puede resolver después.
     *
     * @param int|null $aUsuario  null = dejarla libre.
     */
    public static function reasignar(
        \PDO $pdo, int $convId, ?int $aUsuario, int $hechoPor, ?string $motivo = null
    ): bool {
        $q = $pdo->prepare("SELECT asignado_a FROM wa_conversaciones WHERE id = ?");
        $q->execute([$convId]);
        $actual = $q->fetchColumn();
        if ($actual === false) return false;

        $de = $actual !== null ? (int) $actual : null;
        if ($de === $aUsuario) return true;   // nada que cambiar

        $pdo->prepare(
            "UPDATE wa_conversaciones
                SET asignado_a = ?, asignado_at = " . ($aUsuario ? 'NOW()' : 'NULL') . "
              WHERE id = ?"
        )->execute([$aUsuario, $convId]);

        self::registrarAsignacion(
            $pdo, $convId, $aUsuario ? 'reasignar' : 'quitar', $de, $aUsuario, $hechoPor, $motivo
        );

        return true;
    }

    /** Deja constancia de cada cambio de asignación. */
    public static function registrarAsignacion(
        \PDO $pdo, int $convId, string $accion,
        ?int $de, ?int $a, int $hechoPor, ?string $motivo = null
    ): void {
        try {
            $pdo->prepare(
                "INSERT INTO wa_asignaciones_log
                    (conversacion_id, accion, de_usuario, a_usuario, hecho_por, motivo)
                 VALUES (?, ?, ?, ?, ?, ?)"
            )->execute([$convId, $accion, $de, $a, $hechoPor, $motivo ? mb_substr($motivo, 0, 200) : null]);
        } catch (\Throwable $e) {
            error_log('[WaInbox] log asignación: ' . $e->getMessage());
        }
    }

    /** Historial de asignaciones de una conversación. */
    public static function historialAsignaciones(\PDO $pdo, int $convId, int $limite = 10): array
    {
        $q = $pdo->prepare(
            "SELECT l.accion, l.motivo, l.created_at,
                    TRIM(CONCAT(COALESCE(ud.nombre,''),' ',COALESCE(ud.apellido,''))) AS de_nombre,
                    TRIM(CONCAT(COALESCE(ua.nombre,''),' ',COALESCE(ua.apellido,''))) AS a_nombre,
                    TRIM(CONCAT(COALESCE(uh.nombre,''),' ',COALESCE(uh.apellido,''))) AS por_nombre
               FROM wa_asignaciones_log l
               LEFT JOIN usuarios ud ON ud.id = l.de_usuario
               LEFT JOIN usuarios ua ON ua.id = l.a_usuario
               LEFT JOIN usuarios uh ON uh.id = l.hecho_por
              WHERE l.conversacion_id = ?
              ORDER BY l.id DESC LIMIT " . (int) $limite
        );
        $q->execute([$convId]);

        return $q->fetchAll(\PDO::FETCH_ASSOC) ?: [];
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

    /**
     * Vincula conversaciones de WhatsApp aún SIN cliente a un cliente, por teléfono
     * (últimos 9 dígitos). Se llama cuando un lead se vuelve cliente (p. ej. al aceptar
     * la propuesta): así la conversación —incluida la que trae el ctwa_clid del anuncio—
     * queda enganchada al cliente sin depender de que llegue un nuevo mensaje.
     * @return int cuántas conversaciones se vincularon.
     */
    public static function vincularPorTelefono(\PDO $pdo, int $clienteId, ?string $telefono): int
    {
        if ($clienteId <= 0) return 0;
        $last9 = substr(preg_replace('/\D/', '', (string)$telefono), -9);
        if (strlen($last9) !== 9) return 0;
        $limpio = "REPLACE(REPLACE(REPLACE(COALESCE(telefono,''),' ',''),'+',''),'-','')";
        $st = $pdo->prepare("UPDATE wa_conversaciones SET cliente_id = ? WHERE cliente_id IS NULL AND $limpio LIKE ?");
        $st->execute([$clienteId, '%' . $last9]);
        return $st->rowCount();
    }

    /** Texto corto para la lista de conversaciones. */
    private static function preview(?string $texto, string $tipo, ?string $templateName = null): string
    {
        // Las llamadas guardan un token técnico en "texto" (call:answered:154…);
        // el listado debe mostrar la etiqueta, no el token.
        if ($tipo === 'call') return '📞 Llamada de voz';

        if ($texto !== null && trim($texto) !== '') {
            return mb_substr(trim(preg_replace('/\s+/', ' ', $texto)), 0, 200);
        }

        $etiquetas = [
            'image'       => '📷 Imagen',
            'document'    => '📄 Documento',
            'audio'       => '🎤 Audio',
            'video'       => '🎬 Video',
            'sticker'     => 'Sticker',
            'location'    => '📍 Ubicación',
            'interactive' => '📋 Mensaje interactivo',
            'call'        => '📞 Llamada de voz',
            'button'      => '📋 Respuesta',
            'contacts'    => '👤 Contacto',
        ];

        return $etiquetas[$tipo] ?? ($templateName ? 'Plantilla: ' . $templateName : ucfirst($tipo));
    }

    /**
     * Marca la conversación como LEAD CALIENTE (el cliente pulsó "Quiero
     * aprovecharlo" en el aviso de cotización por vencer): la fija arriba en el
     * inbox y notifica (push + campanita) al ejecutivo del cliente para que la
     * atienda de inmediato. Se hace una sola vez por conversación.
     */
    public static function marcarLeadCaliente(\PDO $pdo, int $convId, ?string $motivo = null): void
    {
        // Solo la primera vez (evita re-notificar si el cliente vuelve a pulsar).
        $upd = $pdo->prepare("UPDATE wa_conversaciones SET lead_caliente_at = NOW(), fijada = 1 WHERE id = ? AND lead_caliente_at IS NULL");
        $upd->execute([$convId]);
        if ($upd->rowCount() === 0) return;

        $c = $pdo->prepare("
            SELECT co.cliente_id, co.asignado_a, cl.razon_social, cl.ejecutivo_id
            FROM wa_conversaciones co
            LEFT JOIN clientes cl ON cl.id = co.cliente_id
            WHERE co.id = ?
        ");
        $c->execute([$convId]);
        $row = $c->fetch(\PDO::FETCH_ASSOC) ?: [];
        $razon = $row['razon_social'] ?? 'Un cliente';

        if ($motivo === 'promo') {
            $titulo  = "🔥 Interesado en promo — {$razon}";
            $mensaje = "{$razon} pulsó \"Quiero aprovechar\" en la promoción. Contáctalo cuanto antes para cerrar.";
        } else {
            $titulo  = "🔥 Interesado — {$razon}";
            $mensaje = "{$razon} pulsó \"Quiero aprovecharlo\" en el aviso de su cotización por vencer. Contáctalo cuanto antes.";
        }
        $url     = (defined('APP_URL') ? APP_URL : '') . '/modules/inbox/index.php';

        // Destinatarios: ejecutivo del cliente + agente asignado; si no hay, gerencia.
        $dest = [];
        if (!empty($row['ejecutivo_id'])) $dest[] = (int)$row['ejecutivo_id'];
        if (!empty($row['asignado_a']))   $dest[] = (int)$row['asignado_a'];
        if (!$dest) {
            foreach ($pdo->query("SELECT id FROM usuarios WHERE activo=1 AND rol IN ('gerente_general','gerente_operaciones')")->fetchAll(\PDO::FETCH_COLUMN) as $g) {
                $dest[] = (int)$g;
            }
        }

        if (is_file(__DIR__ . '/PushService.php')) require_once __DIR__ . '/PushService.php';
        $insN = $pdo->prepare("INSERT INTO notificaciones (usuario_id, tipo, titulo, mensaje, url_accion) VALUES (?, 'lead_caliente', ?, ?, ?)");
        foreach (array_unique($dest) as $uid) {
            try { $insN->execute([$uid, $titulo, $mensaje, $url]); } catch (\Throwable $e) {}
            if (class_exists('PushService')) {
                try { PushService::notify($uid, $titulo, $mensaje, $url, 'lead-' . $convId); } catch (\Throwable $e) {}
            }
        }
    }

    /**
     * ¿Estamos FUERA del horario de atención? (hora Lima)
     * Atención: Lun–Vie 08:00–18:30 · Sáb 08:00–13:00 · Dom cerrado · feriados cerrado.
     */
    public static function fueraDeHorario(\PDO $pdo, ?\DateTime $ahora = null): bool
    {
        $tz    = new \DateTimeZone(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'America/Lima');
        $ahora = $ahora ? (clone $ahora) : new \DateTime('now', $tz);
        $ahora->setTimezone($tz);

        // Feriado → cerrado.
        $fer = $pdo->prepare("SELECT 1 FROM feriados WHERE fecha = ?");
        $fer->execute([$ahora->format('Y-m-d')]);
        if ($fer->fetchColumn()) return true;

        $dow  = (int)$ahora->format('N');                               // 1=Lun … 7=Dom
        $mins = (int)$ahora->format('H') * 60 + (int)$ahora->format('i');
        $abre = 8 * 60;                                                 // 08:00
        if ($dow === 7) return true;                                   // Domingo
        if ($dow === 6) return $mins < $abre || $mins >= 13 * 60;      // Sábado 08:00–13:00
        return $mins < $abre || $mins >= (18 * 60 + 30);              // L–V 08:00–18:30
    }

    /**
     * Auto-respuesta cuando el cliente escribe FUERA de horario a un número
     * CONVERSACIONAL (Frank/Andrés) — nunca al de notificaciones. Responde desde
     * ese mismo número, una vez cada 8 h como máximo (anti-spam).
     */
    public static function autoRespuestaFuera(\PDO $pdo, int $convId, string $telefono, ?string $phoneNumberId): void
    {
        // Nunca en el número de notificaciones (una sola vía).
        $notif = defined('META_WA_PHONE_NUMBER_ID') ? META_WA_PHONE_NUMBER_ID : '';
        if (!$phoneNumberId || ($notif !== '' && $phoneNumberId === $notif)) return;

        if (!self::fueraDeHorario($pdo)) return;

        // Anti-spam: máximo una auto-respuesta cada 8 h (claim atómico contra ráfagas).
        $claim = $pdo->prepare(
            "UPDATE wa_conversaciones SET auto_fuera_at = NOW()
              WHERE id = ? AND (auto_fuera_at IS NULL OR auto_fuera_at < (NOW() - INTERVAL 8 HOUR))"
        );
        $claim->execute([$convId]);
        if ($claim->rowCount() === 0) return;

        // Mensaje según el motivo: feriado vs fuera de horario, + cuándo retomamos.
        $tz    = new \DateTimeZone(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'America/Lima');
        $ahora = new \DateTime('now', $tz);
        $ferQ  = $pdo->prepare("SELECT nombre FROM feriados WHERE fecha = ?");
        $ferQ->execute([$ahora->format('Y-m-d')]);
        $feriadoNombre = $ferQ->fetchColumn();
        $apertura = self::proximaAperturaTexto($pdo, $ahora);

        $cab = $feriadoNombre
            ? "Hoy es *feriado* ({$feriadoNombre}) y no tenemos atención."
            : "En este momento estamos *fuera de horario de atención* (Lun–Vie 8:00 a. m. a 6:30 p. m. · Sáb hasta 1:00 p. m.).";

        $msg = "¡Hola! 👋 Gracias por escribir a Group Global ISO.\n\n"
             . $cab . " Retomamos la atención {$apertura}.\n\n"
             . "Tu mensaje quedó registrado y un asesor te responderá apenas volvamos. "
             . "Si gustas, cuéntanos qué norma o certificación te interesa para adelantar tu atención. ¡Gracias! 🙌";

        require_once __DIR__ . '/WhatsAppService.php';
        $waId = WhatsAppService::sendText($telefono, $msg, null, $phoneNumberId);
        self::registrarSaliente(
            $pdo, $telefono, $waId, 'text', $msg,
            null, null, $waId ? 'sent' : 'failed',
            $waId ? null : WhatsAppService::lastError(), $phoneNumberId
        );
    }

    /**
     * Texto amigable de la próxima apertura ("hoy"/"mañana"/"el lunes"/"el 12/08"
     * + "a las 8:00 a. m."), salteando domingos y feriados. Apertura siempre 08:00.
     */
    private static function proximaAperturaTexto(\PDO $pdo, \DateTime $desde): string
    {
        $tz  = new \DateTimeZone(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'America/Lima');
        $d   = clone $desde; $d->setTimezone($tz);
        $hoy = $d->format('Y-m-d');
        $fer = $pdo->prepare("SELECT 1 FROM feriados WHERE fecha = ?");

        $apertura = null;
        for ($i = 0; $i < 45; $i++) {
            $dow = (int)$d->format('N');                 // 1=Lun … 7=Dom
            $fer->execute([$d->format('Y-m-d')]);
            $abierto = !$fer->fetchColumn() && $dow !== 7; // Domingo y feriados cerrados
            if ($abierto) {
                if ($i > 0) { $apertura = (clone $d)->setTime(8, 0, 0); break; }
                // Es hoy: solo sirve si aún no dieron las 8:00 (si ya pasó, siguiente día hábil).
                $curMin = (int)$desde->format('H') * 60 + (int)$desde->format('i');
                if ($curMin < 8 * 60) { $apertura = (clone $d)->setTime(8, 0, 0); break; }
            }
            $d->modify('+1 day')->setTime(0, 0, 0);
        }
        if (!$apertura) return 'pronto';

        $dias    = [1=>'lunes',2=>'martes',3=>'miércoles',4=>'jueves',5=>'viernes',6=>'sábado',7=>'domingo'];
        $difDias = (int)(new \DateTime($hoy, $tz))->diff(new \DateTime($apertura->format('Y-m-d'), $tz))->format('%a');
        if     ($difDias === 0) $cuando = 'hoy';
        elseif ($difDias === 1) $cuando = 'mañana';
        elseif ($difDias <= 6)  $cuando = 'el ' . $dias[(int)$apertura->format('N')];
        else                    $cuando = 'el ' . $apertura->format('d/m');

        return $cuando . ' a las 8:00 a. m.';
    }
}
