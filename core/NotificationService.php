<?php
/**
 * NotificationService — Despachador centralizado de notificaciones por evento y rol.
 *
 * Uso:
 *   NotificationService::dispatch('propuesta_aceptada', [
 *     'id'           => $cot_id,       // para patrones de URL como {id}
 *     'cliente_id'   => $cliente_id,   // resolución COMPANY_EXECUTIVE + URL {cliente_id}
 *     'cotizacion_id'=> $cot_id,       // resolución PROPOSAL_OWNER
 *     'triggered_by' => $user['id'],   // excluido automáticamente de destinatarios
 *     'title'        => 'Propuesta aceptada — Empresa ABC',
 *     'message'      => 'La propuesta COT-2025-001 fue aceptada.',
 *   ]);
 *
 * recipient_type:
 *   ROLE              → todos los usuarios activos con ese rol
 *   PROPOSAL_OWNER    → ejecutivo de cotizacion (COALESCE ejecutivo_id, creado_por)
 *   COMPANY_EXECUTIVE → ejecutivo del cliente  (COALESCE responsible_user_id, created_by_user_id, ejecutivo_id)
 *
 * Diagnóstico: define NS_DEBUG antes de require para activar logs detallados.
 *   define('NS_DEBUG', true);
 *   require_once '.../NotificationService.php';
 */
class NotificationService
{
    private static function dbg(string $msg): void
    {
        if (defined('NS_DEBUG') && NS_DEBUG) {
            error_log('[NS:DEBUG] ' . $msg);
        }
    }

    /**
     * Disparar un evento de notificación.
     * Retorna el número de notificaciones internas insertadas.
     */
    public static function dispatch(string $event_code, array $context = []): int
    {
        // Auto-cargar servicios de canal si aún no están disponibles
        if (!class_exists('PushService')) {
            $f = __DIR__ . '/PushService.php';
            if (file_exists($f)) require_once $f;
        }
        if (!class_exists('WhatsAppService')) {
            $f = __DIR__ . '/WhatsAppService.php';
            if (file_exists($f)) require_once $f;
        }

        self::dbg("─── dispatch({$event_code}) triggered_by=" . ($context['triggered_by'] ?? 'null'));

        try {
            $pdo = Database::get();

            // ── Paso 1: Cargar el evento ──────────────────────────────────────
            $ev = $pdo->prepare("SELECT * FROM notification_events WHERE code = ? AND activo = 1");
            $ev->execute([$event_code]);
            $event = $ev->fetch();
            if (!$event) {
                self::dbg("PASO 1 FAIL: evento '{$event_code}' no existe o activo=0");
                error_log("[NotificationService] evento no encontrado o inactivo: {$event_code}");
                return 0;
            }
            self::dbg("PASO 1 OK: evento id={$event['id']} activo=1");

            // ── Paso 2: Cargar reglas habilitadas ─────────────────────────────
            $rq = $pdo->prepare("SELECT * FROM notification_rules WHERE event_code = ? AND enabled = 1");
            $rq->execute([$event_code]);
            $rules = $rq->fetchAll();
            if (!$rules) {
                self::dbg("PASO 2 FAIL: 0 reglas habilitadas para '{$event_code}'");
                error_log("[NotificationService] sin reglas habilitadas para: {$event_code}");
                return 0;
            }
            self::dbg("PASO 2 OK: " . count($rules) . " regla(s) → " .
                implode(', ', array_map(fn($r) => "{$r['recipient_type']}:{$r['role']}", $rules)));

            // ── Paso 3: Resolver destinatarios ───────────────────────────────
            $recipients = self::resolveRecipients($pdo, $rules, $context);
            if (!$recipients) {
                self::dbg("PASO 3 FAIL: resolveRecipients devolvió 0 destinatarios (triggered_by=" .
                    ($context['triggered_by'] ?? 'null') . ")");
                error_log("[NotificationService] 0 destinatarios resueltos para: {$event_code}");
                return 0;
            }
            self::dbg("PASO 3 OK: " . count($recipients) . " destinatario(s) → user_ids=[" .
                implode(',', array_column($recipients, 'user_id')) . "]");

            // ── Paso 4: Insertar notificaciones y push ────────────────────────
            $url      = $context['url']       ?? self::buildUrl($event['url_pattern'] ?? '', $context);
            $title    = $context['title']     ?? '';
            $message  = $context['message']   ?? '';
            // push_body permite un cuerpo diferente para el push sin afectar la notificación interna
            $push_msg = $context['push_body'] ?? $message;
            $ref_id   = (int)($context['cliente_id'] ?? $context['id'] ?? $context['cotizacion_id'] ?? 0) ?: null;

            self::dbg("PASO 4: title='{$title}' url='{$url}' ref_id=" . ($ref_id ?? 'null'));

            if ($title === '' || $message === '') {
                self::dbg("PASO 4 WARN: title o message vacíos — notificaciones internas no se insertarán");
            }

            $push_available = class_exists('PushService');
            $wa_available   = class_exists('WhatsAppService');
            self::dbg("PASO 4: PushService=" . ($push_available ? 'sí' : 'NO') .
                      " WhatsAppService=" . ($wa_available ? 'sí' : 'NO'));

            $ins = $pdo->prepare("
                INSERT INTO notificaciones
                  (usuario_id, tipo, titulo, mensaje, url_accion, priority, reference_id, event_code)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");

            // WhatsApp: los destinatarios COMPANY_CONTACT llevan cliente_id en lugar de user_id
            $wa_cliente_ids_sent = [];  // evitar duplicados si hay múltiples reglas WA para el mismo cliente

            $count = 0;
            foreach ($recipients as $rec) {
                $uid = $rec['user_id'];

                // ── WhatsApp (COMPANY_CONTACT) ────────────────────────────────
                if (!empty($rec['via_whatsapp']) && $wa_available) {
                    $wa_cliente_id = (int)($rec['cliente_id'] ?? 0);
                    if ($wa_cliente_id && !in_array($wa_cliente_id, $wa_cliente_ids_sent, true)) {
                        self::dbg("  → WhatsApp INTENTANDO cliente_id={$wa_cliente_id}");
                        try {
                            WhatsAppService::notifyClient($wa_cliente_id, $event_code, $context);
                            $wa_cliente_ids_sent[] = $wa_cliente_id;
                            self::dbg("  → WhatsApp OK cliente_id={$wa_cliente_id}");
                        } catch (\Throwable $we) {
                            self::dbg("  → WhatsApp ERROR cliente_id={$wa_cliente_id}: " . $we->getMessage());
                            error_log('[NotificationService] WhatsApp error cliente_id=' . $wa_cliente_id . ': ' . $we->getMessage());
                        }
                    }
                    continue;  // COMPANY_CONTACT no tiene user_id → no procesar canales internos
                }

                // Notificación interna
                if ($rec['via_interna'] && $title !== '' && $message !== '') {
                    $ins->execute([
                        $uid,
                        $event_code,
                        $title,
                        $message,
                        $url ?: null,
                        $rec['priority'],
                        $ref_id,
                        $event_code,
                    ]);
                    $count++;
                    self::dbg("  → notificacion interna INSERT OK user_id={$uid}");
                } else {
                    self::dbg("  → notificacion interna SKIP user_id={$uid} via_interna=" .
                        ($rec['via_interna'] ? '1' : '0') . " title_ok=" . ($title !== '' ? '1' : '0'));
                }

                // Push web
                if ($rec['via_push'] && $push_available && $title !== '') {
                    self::dbg("  → push INTENTANDO user_id={$uid}");
                    try {
                        PushService::notify(
                            (int)$uid,
                            $title,
                            $push_msg,
                            $url ?: '',
                            $event_code . '-' . ($ref_id ?? '0')
                        );
                        self::dbg("  → push OK user_id={$uid}");
                    } catch (\Throwable $pe) {
                        self::dbg("  → push ERROR user_id={$uid}: " . $pe->getMessage());
                        error_log('[NotificationService] push error user_id=' . $uid . ': ' . $pe->getMessage());
                    }
                } else {
                    self::dbg("  → push SKIP user_id={$uid} via_push=" .
                        ($rec['via_push'] ? '1' : '0') . " push_available=" .
                        ($push_available ? '1' : '0'));
                }
            }

            // ── Paso 5: Historial ─────────────────────────────────────────────
            if ($count > 0) {
                $pdo->prepare("
                    INSERT INTO notification_history
                      (event_code, triggered_by, reference_id, recipients, context)
                    VALUES (?, ?, ?, ?, ?)
                ")->execute([
                    $event_code,
                    $context['triggered_by'] ? (int)$context['triggered_by'] : null,
                    $ref_id,
                    $count,
                    json_encode($context, JSON_UNESCAPED_UNICODE),
                ]);
                self::dbg("PASO 5 OK: historial INSERT count={$count}");
            } else {
                self::dbg("PASO 5: sin inserts en notificaciones → historial omitido");
            }

            self::dbg("─── dispatch({$event_code}) FIN count={$count}");
            return $count;

        } catch (\Throwable $e) {
            error_log('[NotificationService] dispatch error (' . $event_code . '): ' .
                $e->getMessage() . ' en ' . basename($e->getFile()) . ':' . $e->getLine());
            self::dbg("EXCEPCIÓN: " . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
            return 0;
        }
    }

    /**
     * Eventos que representan una TAREA pendiente para el staff (no meramente
     * informativos). Estos NO se limpian solo por ver la ficha: se limpian cuando
     * se completa la acción (ver markResolved en cada flujo).
     */
    const ACTIONABLE_EVENTS = ['pago_registrado', 'factura_por_cargar', 'acta_alcance_cambios_solicitados'];

    /**
     * Marca como leídas las notificaciones INFORMATIVAS de un usuario para una
     * entidad (reference_id) cuando entra a revisarla (ej. abrir la ficha del
     * cliente). Por defecto excluye los eventos accionables para no borrar
     * recordatorios de tareas aún pendientes.
     *
     * @param int  $userId
     * @param int  $referenceId        normalmente cliente_id
     * @param bool $includeActionable  si true, también limpia los accionables
     * @return int  nº de notificaciones marcadas
     */
    public static function markSeen(int $userId, int $referenceId, bool $includeActionable = false): int
    {
        if ($userId <= 0 || $referenceId <= 0) return 0;
        try {
            $pdo  = Database::get();
            $sql  = "UPDATE notificaciones SET leida = 1 WHERE leida = 0 AND usuario_id = ? AND reference_id = ?";
            $args = [$userId, $referenceId];
            if (!$includeActionable && self::ACTIONABLE_EVENTS) {
                $ph   = implode(',', array_fill(0, count(self::ACTIONABLE_EVENTS), '?'));
                $sql .= " AND event_code NOT IN ($ph)";
                $args = array_merge($args, self::ACTIONABLE_EVENTS);
            }
            $st = $pdo->prepare($sql);
            $st->execute($args);
            return $st->rowCount();
        } catch (\Throwable $e) {
            error_log('[NotificationService] markSeen error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Marca como leídas (para todos los usuarios) las notificaciones internas de
     * un evento ya resuelto. Úsalo cuando se completa la acción asociada sin pasar
     * por la notificación (ej. al validar/rechazar un pago → limpiar "pago_registrado").
     * Coincide por event_code + reference_id (que en dispatch() = cliente_id).
     *
     * @param string[] $eventCodes  Códigos de evento a limpiar
     * @param int      $referenceId  reference_id de la notificación (normalmente cliente_id)
     * @return int  nº de notificaciones marcadas
     */
    public static function markResolved(array $eventCodes, int $referenceId): int
    {
        if (!$eventCodes || $referenceId <= 0) return 0;
        try {
            $pdo = Database::get();
            $ph  = implode(',', array_fill(0, count($eventCodes), '?'));
            $st  = $pdo->prepare(
                "UPDATE notificaciones SET leida = 1
                 WHERE leida = 0 AND reference_id = ? AND event_code IN ($ph)"
            );
            $st->execute(array_merge([$referenceId], array_values($eventCodes)));
            return $st->rowCount();
        } catch (\Throwable $e) {
            error_log('[NotificationService] markResolved error: ' . $e->getMessage());
            return 0;
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Privados
    // ──────────────────────────────────────────────────────────────────────────

    private static function resolveRecipients(\PDO $pdo, array $rules, array $context): array
    {
        $triggered_by = (int)($context['triggered_by'] ?? 0);
        $collected    = [];   // key → merged config
        // COMPANY_CONTACT usa clave "wa:{cliente_id}" para no colisionar con user_ids internos

        foreach ($rules as $rule) {
            $user_ids   = [];
            $type       = $rule['recipient_type'];
            $via_wa     = (bool)($rule['via_whatsapp'] ?? false);

            // ── COMPANY_CONTACT: destinatario es el cliente (no un usuario interno) ──
            if ($type === 'COMPANY_CONTACT') {
                $cli_id = (int)($context['cliente_id'] ?? 0);
                if (!$cli_id) {
                    self::dbg("  resolveRecipients COMPANY_CONTACT skip: cliente_id no en context");
                    continue;
                }
                if (!$via_wa) {
                    self::dbg("  resolveRecipients COMPANY_CONTACT skip: via_whatsapp=0");
                    continue;
                }
                $key = 'wa:' . $cli_id;
                if (!isset($collected[$key])) {
                    $collected[$key] = [
                        'user_id'      => null,
                        'cliente_id'   => $cli_id,
                        'via_interna'  => false,
                        'via_push'     => false,
                        'via_whatsapp' => true,
                        'priority'     => (int)$rule['priority'],
                    ];
                } else {
                    $collected[$key]['via_whatsapp'] = true;
                }
                self::dbg("  resolveRecipients COMPANY_CONTACT cliente_id={$cli_id} → WA queued");
                continue;
            }

            switch ($type) {
                case 'ROLE':
                    if (empty($rule['role'])) {
                        self::dbg("  resolveRecipients ROLE skip: rule id={$rule['id']} sin role");
                        break;
                    }
                    $st = $pdo->prepare("SELECT id FROM usuarios WHERE rol = ? AND activo = 1");
                    $st->execute([$rule['role']]);
                    $user_ids = $st->fetchAll(\PDO::FETCH_COLUMN);
                    self::dbg("  resolveRecipients ROLE '{$rule['role']}' → " .
                        count($user_ids) . " usuarios: [" . implode(',', $user_ids) . "]");
                    break;

                case 'PROPOSAL_OWNER':
                    $cot_id = (int)($context['cotizacion_id'] ?? 0);
                    if ($cot_id) {
                        $st = $pdo->prepare("SELECT COALESCE(ejecutivo_id, creado_por) FROM cotizaciones WHERE id = ?");
                        $st->execute([$cot_id]);
                        $uid = $st->fetchColumn();
                        if ($uid) $user_ids = [(int)$uid];
                        self::dbg("  resolveRecipients PROPOSAL_OWNER cot_id={$cot_id} → user_id=" . ($uid ?: 'null'));
                    } else {
                        self::dbg("  resolveRecipients PROPOSAL_OWNER skip: cotizacion_id no en context");
                    }
                    break;

                case 'COMPANY_EXECUTIVE':
                    $cli_id = (int)($context['cliente_id'] ?? 0);
                    if ($cli_id) {
                        $st = $pdo->prepare("
                            SELECT COALESCE(responsible_user_id, created_by_user_id, ejecutivo_id)
                            FROM clientes WHERE id = ?
                        ");
                        $st->execute([$cli_id]);
                        $uid = $st->fetchColumn();
                        if ($uid) $user_ids = [(int)$uid];
                        self::dbg("  resolveRecipients COMPANY_EXECUTIVE cli_id={$cli_id} → user_id=" . ($uid ?: 'null'));
                    } else {
                        self::dbg("  resolveRecipients COMPANY_EXECUTIVE skip: cliente_id no en context");
                    }
                    break;

                case 'USER':
                    if (!empty($rule['user_id'])) {
                        $user_ids = [(int)$rule['user_id']];
                        self::dbg("  resolveRecipients USER → user_id={$rule['user_id']}");
                    }
                    break;
            }

            foreach ($user_ids as $uid) {
                $uid = (int)$uid;
                if (!$uid) continue;
                if ($triggered_by && $uid === $triggered_by) {
                    self::dbg("  resolveRecipients EXCLUIDO user_id={$uid} (es triggered_by)");
                    continue;
                }
                if (!isset($collected[$uid])) {
                    $collected[$uid] = [
                        'user_id'      => $uid,
                        'cliente_id'   => null,
                        'via_interna'  => (bool)$rule['via_interna'],
                        'via_push'     => (bool)$rule['via_push'],
                        'via_whatsapp' => false,
                        'priority'     => (int)$rule['priority'],
                    ];
                } else {
                    $collected[$uid]['via_push']    = $collected[$uid]['via_push']    || (bool)$rule['via_push'];
                    $collected[$uid]['via_interna'] = $collected[$uid]['via_interna'] || (bool)$rule['via_interna'];
                    $collected[$uid]['priority']    = min($collected[$uid]['priority'],  (int)$rule['priority']);
                }
            }
        }

        return array_values($collected);
    }

    private static function buildUrl(string $pattern, array $context): string
    {
        if ($pattern === '') return '';
        $url = $pattern;
        foreach ($context as $k => $v) {
            $url = str_replace('{' . $k . '}', (string)($v ?? ''), $url);
        }
        if (defined('APP_URL') && $url !== '' && strpos($url, 'http') !== 0) {
            $url = rtrim(APP_URL, '/') . $url;
        }
        return $url;
    }
}
