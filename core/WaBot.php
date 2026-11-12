<?php
/**
 * core/WaBot.php — Bot de recepción de leads en WhatsApp (motor guiado por datos).
 *
 * El flujo NO está en el código: vive en las tablas wa_bot_nodos / wa_bot_botones /
 * wa_bot_triggers y se edita desde Mantenimiento → Bot WhatsApp. Cada paso es un
 * NODO con un tipo (menú, mensaje, pedir RUC, pedir normas, derivar, fin). Los
 * botones de un menú enlazan a otros nodos; los disparadores saltan a un nodo por
 * palabra clave. Este motor sólo recorre esos datos.
 *
 * Sólo responde en números CONVERSACIONALES (Frank/Andrés, nunca el de
 * notificaciones). Si un humano toma la conversación, el bot se calla.
 */
require_once __DIR__ . '/WhatsAppService.php';
require_once __DIR__ . '/WaInbox.php';
require_once __DIR__ . '/IaAgente.php';

class WaBot
{
    private const MAX_HOPS     = 12;   // saltos por mensaje (evita bucles de datos)
    private const MAX_INTENTOS = 5;    // reintentos en un paso antes de derivar

    public static function activo(\PDO $pdo): bool { return self::cfg($pdo, 'wa_bot_activo', '1') === '1'; }

    private static function cfg(\PDO $pdo, string $k, string $def = ''): string
    {
        $q = $pdo->prepare("SELECT valor FROM configuracion WHERE clave = ?");
        $q->execute([$k]);
        $v = $q->fetchColumn();
        return ($v !== false && trim((string)$v) !== '') ? (string)$v : $def;
    }

    /** Punto de entrada desde el webhook (mensaje ENTRANTE de un cliente). */
    public static function procesar(\PDO $pdo, int $convId, string $telefono, ?string $phoneNumberId, string $tipo, ?string $texto): void
    {
        // Sólo números conversacionales (nunca el de notificaciones).
        $notif = defined('META_WA_PHONE_NUMBER_ID') ? META_WA_PHONE_NUMBER_ID : '';
        if (!$phoneNumberId || ($notif !== '' && $phoneNumberId === $notif)) return;
        if (!self::activo($pdo)) return;

        // Modo prueba: si wa_bot_test_numero tiene un número, sólo responde a ese.
        $test = preg_replace('/\D/', '', self::cfg($pdo, 'wa_bot_test_numero', ''));
        if ($test !== '' && preg_replace('/\D/', '', $telefono) !== $test) return;

        $c = $pdo->prepare("SELECT bot_estado, bot_nodo, bot_ruc, bot_certs, bot_razon, bot_intentos, bot_at, asignado_a FROM wa_conversaciones WHERE id = ?");
        $c->execute([$convId]);
        $cv = $c->fetch(\PDO::FETCH_ASSOC);
        if (!$cv) return;

        // Un humano ya tomó la conversación → el bot se calla.
        if (!empty($cv['asignado_a'])) { self::set($pdo, $convId, ['bot_estado' => 'humano']); return; }
        $estado = $cv['bot_estado'] ?? null;
        if ($estado === 'humano') return;

        // Ya se derivó (datos capturados): acuse ÚNICO y luego silencio en ráfaga;
        // si vuelve a escribir horas después, se le re-asegura una sola vez.
        if ($estado === 'completo' || $estado === 'entregado') {
            $ultimo = !empty($cv['bot_at']) ? strtotime((string)$cv['bot_at']) : 0;
            $horas  = $ultimo ? (time() - $ultimo) / 3600 : 999;
            if ($estado === 'completo' || $horas >= 6) {
                self::send($pdo, $telefono, $phoneNumberId,
                    '¡Gracias! Ya registramos tu solicitud ✅. Nuestro asesor te responderá muy pronto dentro de nuestro horario de atención. Si tienes algo más, déjalo por aquí y lo verá. 🙌');
                self::set($pdo, $convId, ['bot_estado' => 'entregado', 'bot_at' => date('Y-m-d H:i:s')]);
            }
            return;
        }

        $txt  = trim((string)$texto);
        $txtL = mb_strtolower($txt);

        // Datos capturados hasta ahora (se van actualizando a medida que avanzamos).
        $data = ['ruc' => (string)($cv['bot_ruc'] ?? ''), 'certs' => (string)($cv['bot_certs'] ?? ''), 'razon' => (string)($cv['bot_razon'] ?? '')];

        // ── Elegir nodo de arranque ──────────────────────────────────────────
        // 1) Si está respondiendo a un menú y su respuesta coincide con un botón,
        //    ese botón manda (tiene prioridad sobre los disparadores).
        // 2) Si no, un disparador por palabra clave puede saltar a otro nodo.
        // 3) Si no hay nodo previo, arranca en el nodo de inicio.
        // 4) Si no, sigue en el nodo donde estaba (captura de RUC/normas, re-menú).
        $stored = !empty($cv['bot_nodo']) ? self::nodo($pdo, $cv['bot_nodo']) : null;

        $menuDest = ($stored && $stored['tipo'] === 'menu') ? self::matchBoton($pdo, (int)$stored['id'], $txt, $txtL) : null;

        if ($menuDest && ($n = self::nodo($pdo, $menuDest))) {
            $nodo = $n; $arrived = true; self::set($pdo, $convId, ['bot_intentos' => 0]);
        } else {
            $tt = self::triggerMatch($pdo, $txtL);
            if ($tt && ($n = self::nodo($pdo, $tt))) { $nodo = $n; $arrived = true; }
            elseif (!$stored) { $nodo = self::nodoInicio($pdo); $arrived = true; }
            else { $nodo = $stored; $arrived = false; }
        }
        if (!$nodo) return; // flujo sin nodo de inicio configurado

        // ── Recorrer el flujo ────────────────────────────────────────────────
        $hops = 0;
        while ($nodo && $hops++ < self::MAX_HOPS) {
            // Al pasar por un nodo con "fijar_normas" (ej. Pack) se fijan las normas.
            if (!empty($nodo['fijar_normas']) && $data['certs'] === '') {
                $data['certs'] = (string)$nodo['fijar_normas'];
                self::set($pdo, $convId, ['bot_certs' => $data['certs']]);
            }

            switch ($nodo['tipo']) {

                case 'menu':
                    if ($arrived) {
                        self::enviarMenu($pdo, $telefono, $phoneNumberId, $nodo, $data);
                        self::set($pdo, $convId, ['bot_nodo' => $nodo['clave'], 'bot_intentos' => 0]);
                        return;
                    }
                    // Respondiendo al menú y NO coincidió con ningún botón → reintento.
                    $int = (int)$cv['bot_intentos'] + 1;
                    self::set($pdo, $convId, ['bot_intentos' => $int]);
                    if ($int >= self::MAX_INTENTOS) { self::derivarFallback($pdo, $convId, $telefono, $phoneNumberId); return; }
                    self::enviarMenu($pdo, $telefono, $phoneNumberId, $nodo, $data);
                    return;

                case 'mensaje':
                    self::send($pdo, $telefono, $phoneNumberId, self::tokens($pdo, $nodo['texto'], $data));
                    if (!empty($nodo['siguiente_clave']) && ($sig = self::nodo($pdo, $nodo['siguiente_clave']))) {
                        $nodo = $sig; $arrived = true; continue 2;
                    }
                    self::set($pdo, $convId, ['bot_nodo' => $nodo['clave']]);
                    return;

                case 'pedir_ruc':
                    if ($arrived) {
                        self::send($pdo, $telefono, $phoneNumberId, self::tokens($pdo, $nodo['texto'], $data));
                        self::set($pdo, $convId, ['bot_nodo' => $nodo['clave'], 'bot_intentos' => 0]);
                        return;
                    }
                    if (preg_match('/(?<!\d)(\d{11})(?!\d)/', $txt, $m)) {
                        $data['ruc'] = $m[1];
                        $upd = ['bot_ruc' => $m[1], 'bot_intentos' => 0];
                        if ($data['razon'] === '' && ($rz = self::buscarRazon($pdo, $m[1]))) { $data['razon'] = $rz; $upd['bot_razon'] = $rz; }
                        self::set($pdo, $convId, $upd);
                        if (!empty($nodo['siguiente_clave']) && ($sig = self::nodo($pdo, $nodo['siguiente_clave']))) { $nodo = $sig; $arrived = true; continue 2; }
                        self::set($pdo, $convId, ['bot_nodo' => $nodo['clave']]);
                        return;
                    }
                    $int = (int)$cv['bot_intentos'] + 1; self::set($pdo, $convId, ['bot_intentos' => $int]);
                    if ($int >= self::MAX_INTENTOS) { self::derivarFallback($pdo, $convId, $telefono, $phoneNumberId); return; }
                    if (preg_match('/\d{6,}/', $txt)) self::send($pdo, $telefono, $phoneNumberId, 'El *RUC* debe tener 11 dígitos, ¿me lo confirmas?');
                    else self::send($pdo, $telefono, $phoneNumberId, self::tokens($pdo, $nodo['texto'], $data));
                    return;

                case 'pedir_normas':
                    if ($arrived) {
                        self::send($pdo, $telefono, $phoneNumberId, self::tokens($pdo, $nodo['texto'], $data));
                        self::set($pdo, $convId, ['bot_nodo' => $nodo['clave'], 'bot_intentos' => 0]);
                        return;
                    }
                    $limpio = preg_replace('/(?<!\d)\d{11}(?!\d)/', ' ', $txt);
                    $limpio = trim(preg_replace('/\b(mi\s+)?ruc\b|\bes\b|n[úu]mero/iu', ' ', $limpio));
                    $limpio = trim(preg_replace('/\s+/', ' ', $limpio));
                    if (mb_strlen($limpio) >= 3) {
                        $data['certs'] = mb_substr($limpio, 0, 255);
                        self::set($pdo, $convId, ['bot_certs' => $data['certs'], 'bot_intentos' => 0]);
                        if (!empty($nodo['siguiente_clave']) && ($sig = self::nodo($pdo, $nodo['siguiente_clave']))) { $nodo = $sig; $arrived = true; continue 2; }
                        self::set($pdo, $convId, ['bot_nodo' => $nodo['clave']]);
                        return;
                    }
                    $int = (int)$cv['bot_intentos'] + 1; self::set($pdo, $convId, ['bot_intentos' => $int]);
                    if ($int >= self::MAX_INTENTOS) { self::derivarFallback($pdo, $convId, $telefono, $phoneNumberId); return; }
                    self::send($pdo, $telefono, $phoneNumberId, self::tokens($pdo, $nodo['texto'], $data));
                    return;

                case 'send_template':
                    // Envía una plantilla aprobada de Meta y avanza al siguiente paso.
                    $cfg = json_decode($nodo['config_json'] ?? '{}', true) ?: [];
                    $tn  = trim((string)($cfg['template'] ?? ''));
                    if ($tn !== '') {
                        $params = array_map(fn($p) => self::tokens($pdo, (string)$p, $data), $cfg['params'] ?? []);
                        $waId = WhatsAppService::sendTemplate($telefono, $tn, $params, (string)($cfg['lang'] ?? 'es'), $phoneNumberId);
                        WaInbox::registrarSaliente($pdo, $telefono, $waId, 'template', $tn, $tn, null, $waId ? 'sent' : 'failed', $waId ? null : WhatsAppService::lastError(), $phoneNumberId);
                    }
                    if (!empty($nodo['siguiente_clave']) && ($sig = self::nodo($pdo, $nodo['siguiente_clave']))) { $nodo = $sig; $arrived = true; continue 2; }
                    self::set($pdo, $convId, ['bot_nodo' => $nodo['clave']]);
                    return;

                case 'esperar':
                    // Wait for Response: (opcional) manda un texto y ESPERA la respuesta;
                    // en el siguiente mensaje avanza.
                    if ($arrived) {
                        if (!empty($nodo['texto'])) self::send($pdo, $telefono, $phoneNumberId, self::tokens($pdo, $nodo['texto'], $data));
                        self::set($pdo, $convId, ['bot_nodo' => $nodo['clave']]);
                        return;
                    }
                    if (!empty($nodo['siguiente_clave']) && ($sig = self::nodo($pdo, $nodo['siguiente_clave']))) { $nodo = $sig; $arrived = true; continue 2; }
                    self::set($pdo, $convId, ['bot_nodo' => $nodo['clave']]);
                    return;

                case 'webhook':
                    // Llama a una API externa (admin-config) y continúa. Best-effort.
                    $cfg = json_decode($nodo['config_json'] ?? '{}', true) ?: [];
                    if (!empty($cfg['url'])) self::webhook((string)$cfg['url'], strtoupper((string)($cfg['method'] ?? 'POST')), self::tokens($pdo, (string)($cfg['body'] ?? ''), $data));
                    if (!empty($nodo['siguiente_clave']) && ($sig = self::nodo($pdo, $nodo['siguiente_clave']))) { $nodo = $sig; $arrived = true; continue 2; }
                    self::set($pdo, $convId, ['bot_nodo' => $nodo['clave']]);
                    return;

                case 'emit':
                    // Registra un evento del flujo (para métricas/seguimiento) y continúa.
                    $cfg = json_decode($nodo['config_json'] ?? '{}', true) ?: [];
                    self::emitEvento($pdo, $convId, (string)($cfg['evento'] ?? 'evento'));
                    if (!empty($nodo['siguiente_clave']) && ($sig = self::nodo($pdo, $nodo['siguiente_clave']))) { $nodo = $sig; $arrived = true; continue 2; }
                    self::set($pdo, $convId, ['bot_nodo' => $nodo['clave']]);
                    return;

                case 'condicion':
                    // Nodo router: evalúa reglas sobre los datos capturados y el último
                    // mensaje; salta al primer destino que coincida, o al "else" (siguiente).
                    $dest = self::evalCondicion($pdo, (int)$nodo['id'], $data, $txtL);
                    if ($dest === null && !empty($nodo['siguiente_clave'])) $dest = $nodo['siguiente_clave'];
                    if ($dest && ($sig = self::nodo($pdo, $dest))) { $nodo = $sig; $arrived = true; continue 2; }
                    self::set($pdo, $convId, ['bot_nodo' => $nodo['clave']]);
                    return;

                case 'ia':
                    // Al llegar fresco con texto de intro: lo envía y espera la 1ª duda.
                    if ($arrived && !empty($nodo['texto'])) {
                        self::send($pdo, $telefono, $phoneNumberId, self::tokens($pdo, $nodo['texto'], $data));
                        self::set($pdo, $convId, ['bot_nodo' => $nodo['clave']]);
                        return;
                    }
                    // Responde el mensaje del cliente con el copiloto de IA y se queda en
                    // "modo IA" (cada mensaje se responde) hasta que un disparador lo saque.
                    $resp = self::responderIA($pdo, $convId);
                    if ($resp !== null && $resp !== '') {
                        self::send($pdo, $telefono, $phoneNumberId, $resp);
                        self::set($pdo, $convId, ['bot_nodo' => $nodo['clave']]);
                        return;
                    }
                    // IA no disponible o sin respuesta → salida segura.
                    if (!empty($nodo['siguiente_clave']) && ($sig = self::nodo($pdo, $nodo['siguiente_clave']))) { $nodo = $sig; $arrived = true; continue 2; }
                    self::derivarFallback($pdo, $convId, $telefono, $phoneNumberId);
                    return;

                case 'derivar':
                    self::derivar($pdo, $convId, $telefono, $phoneNumberId, $nodo, $data);
                    return;

                case 'fin':
                default:
                    if (!empty($nodo['texto'])) self::send($pdo, $telefono, $phoneNumberId, self::tokens($pdo, $nodo['texto'], $data));
                    self::set($pdo, $convId, ['bot_estado' => 'completo', 'bot_nodo' => $nodo['clave'], 'bot_at' => date('Y-m-d H:i:s')]);
                    return;
            }
        }
    }

    // ── Cierre + handoff al asesor ───────────────────────────────────────────
    private static function derivar(\PDO $pdo, int $convId, string $telefono, ?string $phoneNumberId, array $nodo, array $data): void
    {
        $tieneDatos = ($data['ruc'] !== '' || $data['certs'] !== '');
        if (!empty($nodo['texto'])) self::send($pdo, $telefono, $phoneNumberId, self::tokens($pdo, $nodo['texto'], $data));

        self::set($pdo, $convId, ['bot_estado' => 'completo', 'bot_nodo' => $nodo['clave'], 'bot_at' => date('Y-m-d H:i:s')]);
        $etq = !empty($nodo['etiqueta_nombre']) ? (string)$nodo['etiqueta_nombre'] : ($tieneDatos ? 'Datos completos' : 'Requiere seguimiento');
        self::etiquetar($pdo, $convId, $etq);
        $pdo->prepare("UPDATE wa_conversaciones SET fijada = 1 WHERE id = ?")->execute([$convId]);
        self::notificarAsesor($pdo, $convId, $data, $phoneNumberId, $tieneDatos);
    }

    /** Derivación genérica cuando se agotan los reintentos en un paso. */
    private static function derivarFallback(\PDO $pdo, int $convId, string $telefono, ?string $phoneNumberId): void
    {
        $c = $pdo->prepare("SELECT bot_ruc, bot_certs, bot_razon FROM wa_conversaciones WHERE id = ?");
        $c->execute([$convId]);
        $cv = $c->fetch(\PDO::FETCH_ASSOC) ?: [];
        $data = ['ruc' => (string)($cv['bot_ruc'] ?? ''), 'certs' => (string)($cv['bot_certs'] ?? ''), 'razon' => (string)($cv['bot_razon'] ?? '')];
        self::send($pdo, $telefono, $phoneNumberId, 'Con gusto nuestro asesor te ayudará a completar tu solicitud. Se pondrá en contacto contigo a la brevedad posible dentro de nuestro horario de atención. 🙌');
        $tieneDatos = ($data['ruc'] !== '' || $data['certs'] !== '');
        self::set($pdo, $convId, ['bot_estado' => 'completo', 'bot_at' => date('Y-m-d H:i:s')]);
        self::etiquetar($pdo, $convId, $tieneDatos ? 'Datos completos' : 'Requiere seguimiento');
        $pdo->prepare("UPDATE wa_conversaciones SET fijada = 1 WHERE id = ?")->execute([$convId]);
        self::notificarAsesor($pdo, $convId, $data, $phoneNumberId, $tieneDatos);
    }

    // ── Envío de un nodo de menú (botones ≤3, lista 4–10) ────────────────────
    private static function enviarMenu(\PDO $pdo, string $telefono, ?string $phoneNumberId, array $nodo, array $data): void
    {
        $body = self::tokens($pdo, $nodo['texto'], $data);
        $q = $pdo->prepare("SELECT label, destino_clave FROM wa_bot_botones WHERE nodo_id = ? ORDER BY orden, id");
        $q->execute([(int)$nodo['id']]);
        $botones = $q->fetchAll(\PDO::FETCH_ASSOC);
        $labels  = array_map(fn($b) => $b['label'], $botones);

        if (!$botones) { // menú sin botones → sólo texto
            self::send($pdo, $telefono, $phoneNumberId, $body);
            return;
        }

        if (count($botones) <= 3) {
            $reply = [];
            foreach ($botones as $i => $b) {
                $reply[] = ['type' => 'reply', 'reply' => ['id' => 'n' . $i, 'title' => mb_substr($b['label'], 0, 20)]];
            }
            $interactive = ['type' => 'button', 'body' => ['text' => mb_substr($body, 0, 1024)], 'action' => ['buttons' => $reply]];
        } else {
            $rows = [];
            foreach (array_slice($botones, 0, 10) as $i => $b) {
                $rows[] = ['id' => 'n' . $i, 'title' => mb_substr($b['label'], 0, 24)];
            }
            $interactive = [
                'type'   => 'list',
                'body'   => ['text' => mb_substr($body, 0, 1024)],
                'action' => ['button' => 'Ver opciones', 'sections' => [['title' => 'Opciones', 'rows' => $rows]]],
            ];
        }
        $waId = WhatsAppService::sendInteractive($telefono, $interactive, $phoneNumberId);
        WaInbox::registrarSaliente($pdo, $telefono, $waId, 'interactive',
            $body . "\n[Opciones: " . implode(' · ', $labels) . "]",
            null, null, $waId ? 'sent' : 'failed', $waId ? null : WhatsAppService::lastError(), $phoneNumberId);
    }

    /** ¿La respuesta coincide con algún botón del menú? Devuelve la clave destino. */
    private static function matchBoton(\PDO $pdo, int $nodoId, string $txt, string $txtL): ?string
    {
        $q = $pdo->prepare("SELECT label, destino_clave FROM wa_bot_botones WHERE nodo_id = ? ORDER BY orden, id");
        $q->execute([$nodoId]);
        $botones = $q->fetchAll(\PDO::FETCH_ASSOC);
        if (!$botones) return null;

        // Selección numérica ("1", "2"...) tal como aparecen los botones.
        if (preg_match('/^\s*(\d{1,2})\s*$/', $txt, $m)) {
            $idx = (int)$m[1] - 1;
            if (isset($botones[$idx])) return $botones[$idx]['destino_clave'];
        }
        foreach ($botones as $b) {
            $lab = mb_strtolower(trim($b['label']));
            if ($lab === '') continue;
            if ($txtL === $lab || mb_strpos($txtL, $lab) !== false || mb_strpos($lab, $txtL) !== false && mb_strlen($txtL) >= 4) {
                return $b['destino_clave'];
            }
        }
        return null;
    }

    /** Evalúa las reglas de un nodo Condición; devuelve la clave destino o null. */
    private static function evalCondicion(\PDO $pdo, int $nodoId, array $data, string $txtL): ?string
    {
        $q = $pdo->prepare("SELECT campo, operador, valor, destino_clave FROM wa_bot_reglas WHERE nodo_id = ? ORDER BY orden, id");
        $q->execute([$nodoId]);
        foreach ($q->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            if (self::cumpleRegla($r, $data, $txtL)) return $r['destino_clave'];
        }
        return null;
    }

    private static function cumpleRegla(array $r, array $data, string $txtL): bool
    {
        $op = $r['operador'];
        if ($r['campo'] === 'texto') {
            $val = mb_strtolower(trim((string)$r['valor']));
            if ($val === '') return false;
            $hit = mb_strpos($txtL, $val) !== false;
            return $op === 'no_contiene' ? !$hit : $hit;
        }
        $tiene = match ($r['campo']) {
            'ruc'    => $data['ruc']   !== '',
            'normas' => $data['certs'] !== '',
            'razon'  => $data['razon'] !== '',
            default  => false,
        };
        return $op === 'no_tiene' ? !$tiene : $tiene;
    }

    /** Llama a una API externa (paso Webhook). Best-effort, timeout corto, solo http/https. */
    private static function webhook(string $url, string $method, string $body): void
    {
        if (!preg_match('#^https?://#i', $url)) return;
        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 6,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_FOLLOWLOCATION => false,
            ]);
            if ($method === 'POST' || $method === 'PUT') {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            }
            curl_exec($ch);
            curl_close($ch);
        } catch (\Throwable $e) { error_log('[WaBot webhook] ' . $e->getMessage()); }
    }

    /** Registra un evento del flujo (paso Registrar evento / Emit Event). */
    private static function emitEvento(\PDO $pdo, int $convId, string $evento): void
    {
        try {
            $pdo->prepare("INSERT INTO wa_bot_eventos (conversacion_id, evento) VALUES (?, ?)")
                ->execute([$convId, mb_substr(trim($evento) !== '' ? $evento : 'evento', 0, 120)]);
        } catch (\Throwable $e) {}
    }

    /** Genera una respuesta con el copiloto de IA para la conversación (o null). */
    private static function responderIA(\PDO $pdo, int $convId): ?string
    {
        if (!IaAgente::disponible()) return null;
        $c = $pdo->prepare("SELECT c.cliente_id, COALESCE(cl.razon_social, CONCAT('+', c.telefono)) AS titulo
                            FROM wa_conversaciones c LEFT JOIN clientes cl ON cl.id = c.cliente_id WHERE c.id = ?");
        $c->execute([$convId]);
        $cv = $c->fetch(\PDO::FETCH_ASSOC);
        if (!$cv) return null;

        $mq = $pdo->prepare("SELECT direccion AS dir, tipo, texto FROM wa_mensajes WHERE conversacion_id = ? ORDER BY id DESC LIMIT 25");
        $mq->execute([$convId]);
        $mensajes = array_reverse($mq->fetchAll(\PDO::FETCH_ASSOC) ?: []);

        try {
            $ctx = IaAgente::contextoCliente($pdo, $cv['cliente_id'] ? (int)$cv['cliente_id'] : null);
            $cat = IaAgente::catalogoNormas($pdo);
            $r   = IaAgente::sugerir($mensajes, $ctx, (string)$cv['titulo'], $cat);
            return (!empty($r['ok']) && !empty($r['texto'])) ? (string)$r['texto'] : null;
        } catch (\Throwable $e) {
            error_log('[WaBot IA] ' . $e->getMessage());
            return null;
        }
    }

    /** Disparador por palabra clave → clave del nodo destino (o null). */
    private static function triggerMatch(\PDO $pdo, string $txtL): ?string
    {
        if ($txtL === '') return null;
        $rs = $pdo->query("SELECT palabras, destino_clave FROM wa_bot_triggers WHERE activo = 1 ORDER BY prioridad DESC, id");
        foreach ($rs->fetchAll(\PDO::FETCH_ASSOC) as $t) {
            foreach (explode(',', mb_strtolower($t['palabras'])) as $kw) {
                $kw = trim($kw);
                if (mb_strlen($kw) >= 3 && mb_strpos($txtL, $kw) !== false) return $t['destino_clave'];
            }
        }
        return null;
    }

    // ── Aviso al asesor del número ───────────────────────────────────────────
    private static function notificarAsesor(\PDO $pdo, int $convId, array $data, ?string $phoneNumberId, bool $tieneDatos = true): void
    {
        if ($tieneDatos) {
            $certs = $data['certs'] !== '' ? $data['certs'] : '—';
            $ruc   = $data['ruc']   !== '' ? $data['ruc']   : '—';
            $razon = $data['razon'];
            $titulo  = "🆕 Lead con datos" . ($razon ? " — {$razon}" : "");
            $mensaje = "Normas: {$certs} · RUC: {$ruc}" . ($razon ? " ({$razon})" : "") . ". Listo para preparar la cotización.";
        } else {
            $titulo  = "🔔 Cliente pidió atención";
            $mensaje = "Un cliente solicitó información / hablar con un asesor por WhatsApp. Atiéndelo desde el inbox.";
        }
        $url = (defined('APP_URL') ? APP_URL : '') . '/modules/inbox/index.php';

        $dest = [];
        if ($phoneNumberId) {
            $q = $pdo->prepare("SELECT nu.usuario_id FROM wa_numero_usuarios nu JOIN wa_numeros n ON n.id = nu.numero_id WHERE n.phone_number_id = ?");
            $q->execute([$phoneNumberId]);
            $dest = array_map('intval', $q->fetchAll(\PDO::FETCH_COLUMN));
        }
        if (!$dest) {
            foreach ($pdo->query("SELECT id FROM usuarios WHERE activo=1 AND rol IN ('gerente_general','gerente_operaciones')")->fetchAll(\PDO::FETCH_COLUMN) as $g) $dest[] = (int)$g;
        }

        if (is_file(__DIR__ . '/PushService.php')) require_once __DIR__ . '/PushService.php';
        $insN = $pdo->prepare("INSERT INTO notificaciones (usuario_id, tipo, titulo, mensaje, url_accion) VALUES (?, 'lead_bot', ?, ?, ?)");
        foreach (array_unique($dest) as $uid) {
            try { $insN->execute([$uid, $titulo, $mensaje, $url]); } catch (\Throwable $e) {}
            if (class_exists('PushService')) { try { PushService::notify($uid, $titulo, $mensaje, $url, 'leadbot-' . $convId); } catch (\Throwable $e) {} }
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────
    /** Reemplaza tokens {precio}{ruc}{normas}{razon} en un texto. */
    private static function tokens(\PDO $pdo, ?string $texto, array $data): string
    {
        $razonTok = $data['razon'] !== '' ? ' — ' . $data['razon'] : '';
        return strtr((string)$texto, [
            '{precio}' => self::cfg($pdo, 'wa_bot_pack_precio', 'S/ 4,720'),
            '{ruc}'    => $data['ruc'],
            '{normas}' => $data['certs'],
            '{razon}'  => $razonTok,
        ]);
    }

    private static function nodo(\PDO $pdo, string $clave): ?array
    {
        $q = $pdo->prepare("SELECT * FROM wa_bot_nodos WHERE clave = ? AND activo = 1 LIMIT 1");
        $q->execute([$clave]);
        $r = $q->fetch(\PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    private static function nodoInicio(\PDO $pdo): ?array
    {
        $r = $pdo->query("SELECT * FROM wa_bot_nodos WHERE es_inicio = 1 AND activo = 1 ORDER BY orden, id LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    private static function etiquetar(\PDO $pdo, int $convId, string $nombre): void
    {
        $eid = $pdo->prepare("SELECT id FROM wa_etiquetas WHERE nombre = ? LIMIT 1");
        $eid->execute([$nombre]);
        $id = $eid->fetchColumn();
        if (!$id) return;
        try {
            $pdo->prepare("INSERT IGNORE INTO wa_conversacion_etiquetas (conversacion_id, etiqueta_id) VALUES (?, ?)")->execute([$convId, (int)$id]);
        } catch (\Throwable $e) {}
    }

    private static function buscarRazon(\PDO $pdo, string $ruc): ?string
    {
        try {
            $q = $pdo->prepare("SELECT razon_social FROM clientes WHERE ruc = ? AND deleted_at IS NULL LIMIT 1");
            $q->execute([$ruc]);
            $r = $q->fetchColumn();
            return $r ?: null;
        } catch (\Throwable $e) { return null; }
    }

    private static function set(\PDO $pdo, int $convId, array $campos): void
    {
        $cols = []; $vals = [];
        foreach ($campos as $k => $v) { $cols[] = "$k = ?"; $vals[] = $v; }
        $vals[] = $convId;
        $pdo->prepare("UPDATE wa_conversaciones SET " . implode(', ', $cols) . " WHERE id = ?")->execute($vals);
    }

    private static function send(\PDO $pdo, string $telefono, ?string $phoneNumberId, string $msg): void
    {
        $waId = WhatsAppService::sendText($telefono, $msg, null, $phoneNumberId);
        WaInbox::registrarSaliente($pdo, $telefono, $waId, 'text', $msg, null, null, $waId ? 'sent' : 'failed', $waId ? null : WhatsAppService::lastError(), $phoneNumberId);
    }
}
