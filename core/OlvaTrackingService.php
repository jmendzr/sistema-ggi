<?php
/**
 * OlvaTrackingService
 * Consulta el estado de guías Olva Courier via su API interna.
 * Formato de guía esperado: "1398645-26" (remito-año de 2 dígitos)
 */
class OlvaTrackingService {

    private const API_URL = 'https://reports.olvaexpress.pe/webservice/rest/getTrackingInformation';
    private const API_KEY = 'a82e5d192fae9bbfee43a964024498e87dfecb884b67c7e95865a3bb07b607dd';

    // Estados técnicos que se filtran en la vista amigable
    private const ESTADOS_OCULTOS = [
        'TRACKING IMPRESO', 'TRACKING EN GUIA', 'TRACKING EN TRANSPORTE',
        'PRE DESPACHO', 'RECEPCION GUIA', 'CONFIRMACION GUIAS DESPACHO',
    ];

    // Traducciones para el usuario final
    private const TRADUCCIONES = [
        'REGISTRADO'                  => 'Guía registrada',
        'RECEPCION TIENDA'            => 'Recepcionado en oficina de origen',
        'CONFIRMACION RECOJO OFICINA' => 'Confirmación de recojo en oficina',
        'DESPACHADO'                  => 'Despachado hacia destino',
        'RECEPCION DESPACHO'          => 'Recepcionado en almacén de despacho',
        'TRANSITO'                    => 'En tránsito',
        'ASIGNADO'                    => 'Asignado para entrega',
        'CONFIRMACION EN TIENDA'      => 'En oficina destino',
        'MOTIVADO'                    => 'En proceso de entrega',
        'ENTREGADO'                   => 'Entregado',
        'CONFIRMACION RECOJO'         => 'Entrega confirmada',
        'ASIGNADO A DEVOLUCION'       => 'Asignado para devolución',
        'REZAGADO'                    => 'Envío rezagado',
        'DEVUELTO'                    => 'Devuelto al remitente',
    ];

    // Mapeo estado Olva → id de estado interno GGI
    // IDs: 7=Por preparar, 1=Preparado, 8=Recepcionado, 3=En tránsito,
    //      4=En distribución, 5=Entregado, 6=Devuelto
    private const MAPA_ESTADOS = [
        'REGISTRADO'                  => 8,
        'RECEPCION TIENDA'            => 8,
        'TRACKING IMPRESO'            => 8,
        'TRACKING EN GUIA'            => 8,
        'CONFIRMACION RECOJO OFICINA' => 8,
        'PRE DESPACHO'                => 8,
        'TRACKING EN TRANSPORTE'      => 3,
        'DESPACHADO'                  => 3,
        'RECEPCION DESPACHO'          => 3,
        'TRANSITO'                    => 3,
        'CONFIRMACION GUIAS DESPACHO' => 3,
        'RECEPCION GUIA'              => 3,
        'ASIGNADO'                    => 4,
        'CONFIRMACION EN TIENDA'      => 4,
        'MOTIVADO'                    => 4,
        'ENTREGADO'                   => 5,
        'CONFIRMACION RECOJO'         => 5,
        'ASIGNADO A DEVOLUCION'       => 6,
        'REZAGADO'                    => 6,
        'DEVUELTO'                    => 6,
    ];

    // Las 3 fases que se muestran en el stepper del portal
    public const FASES_STEPPER = [
        1 => [
            'label'   => 'Recepcionado',
            'estados' => [
                'REGISTRADO','RECEPCION TIENDA','TRACKING IMPRESO',
                'TRACKING EN GUIA','CONFIRMACION RECOJO OFICINA','PRE DESPACHO',
            ],
        ],
        2 => [
            'label'   => 'En camino',
            'estados' => [
                'TRACKING EN TRANSPORTE','DESPACHADO','RECEPCION DESPACHO',
                'TRANSITO','CONFIRMACION GUIAS DESPACHO','RECEPCION GUIA',
            ],
        ],
        3 => [
            'label'   => 'En proceso de entrega',
            'estados' => [
                'ASIGNADO','CONFIRMACION EN TIENDA','MOTIVADO',
                'ENTREGADO','CONFIRMACION RECOJO',
                'ASIGNADO A DEVOLUCION','REZAGADO','DEVUELTO',
            ],
        ],
    ];

    /**
     * Consulta el historial completo de una guía Olva.
     * Retorna null si el formato es incorrecto, la API no responde o no hay datos.
     */
    public static function consultar(string $guia): ?array
    {
        if (!preg_match('/^(\d+)-(\d{2})$/', trim($guia), $m)) {
            return null;
        }
        [, $remito, $emision] = $m;

        $url = self::API_URL . '?' . http_build_query([
            'tracking' => $remito,
            'emision'  => $emision,
            'apikey'   => self::API_KEY,
            'details'  => '1',
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Origin: https://tracking.olvaexpress.pe',
                'Referer: https://tracking.olvaexpress.pe/',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200 || !$body) return null;

        $json = json_decode($body, true);
        if (!($json['success'] ?? false) || empty($json['data'])) return null;

        $g = $json['data']['general'] ?? [];
        $d = $json['data']['details']  ?? [];

        $estado_olva = strtoupper(trim($g['nombre_estado_tracking'] ?? ''));

        // Determinar fase del stepper
        $fase_actual = 0;
        foreach (self::FASES_STEPPER as $num => $fase) {
            if (in_array($estado_olva, $fase['estados'])) {
                $fase_actual = $num;
                break;
            }
        }

        // Separar eventos: todos los técnicos para ver.php, filtrados para portal
        $eventos_todos    = [];
        $eventos_friendly = [];
        foreach ($d as $ev) {
            $raw = strtoupper(trim($ev['estado_tracking'] ?? ''));
            $evento = [
                'fecha'  => self::formatFecha($ev['fecha_creacion'] ?? ''),
                'estado' => $raw,
                'sede'   => $ev['nombre_sede']     ?? '',
                'obs'    => $ev['obs']             ?? '',
            ];
            $eventos_todos[] = $evento;

            if (!in_array($raw, self::ESTADOS_OCULTOS)) {
                $eventos_friendly[] = array_merge($evento, [
                    'label' => self::TRADUCCIONES[$raw] ?? ucwords(strtolower($raw)),
                ]);
            }
        }

        return [
            'estado_olva'      => $estado_olva,
            'estado_label'     => self::TRADUCCIONES[$estado_olva] ?? ucwords(strtolower($estado_olva)),
            'estado_ggi_id'    => self::MAPA_ESTADOS[$estado_olva] ?? null,
            'fase_stepper'     => $fase_actual,
            'destinatario'     => $g['consignado']     ?? '',
            'remitente'        => $g['remitente']      ?? '',
            'origen'           => $g['origen']         ?? '',
            'destino'          => $g['destino']        ?? '',
            'fecha_envio'      => $g['fecha_envio']    ?? '',
            'contenido'        => $g['contenido']      ?? '',
            'peso'             => $g['peso']           ?? '',
            'tipo_oficina'     => $g['tipo_oficina']   ?? '',
            'oficina_dest'     => $g['nombre_oficina'] ?? '',
            'eventos'          => $eventos_todos,
            'eventos_friendly' => $eventos_friendly,
        ];
    }

    /**
     * Sincroniza el estado de un envío en BD si Olva está más avanzado.
     * Solo avanza, nunca retrocede. Retorna el nuevo estado_id o null si no hubo cambio.
     */
    public static function sincronizar(int $envio_id, PDO $pdo): ?int
    {
        $stmt = $pdo->prepare("
            SELECT e.codigo_envio, e.estado_envio_id, e.fecha_recibido,
                   es.orden AS orden_actual
            FROM envios e
            JOIN estados_envio es ON es.id = e.estado_envio_id
            WHERE e.id = ? AND e.deleted_at IS NULL
        ");
        $stmt->execute([$envio_id]);
        $row = $stmt->fetch();
        if (!$row || empty($row['codigo_envio'])) return null;

        $data = self::consultar($row['codigo_envio']);
        if (!$data || !$data['estado_ggi_id']) return null;

        $new_id = $data['estado_ggi_id'];

        $ord = $pdo->prepare("SELECT orden FROM estados_envio WHERE id = ?");
        $ord->execute([$new_id]);
        $orden_nuevo = (int)$ord->fetchColumn();

        // Sin cambio de estado — pero corregir fecha_recibido si falta estando Entregado
        if ($orden_nuevo <= (int)$row['orden_actual']) {
            if ((int)$row['estado_envio_id'] === 5 && empty($row['fecha_recibido'])) {
                $ev_entregado = array_filter($data['eventos'] ?? [], fn($e) => $e['estado'] === 'ENTREGADO');
                $ev_entregado = reset($ev_entregado);
                if ($ev_entregado) {
                    $fecha_rec = date('Y-m-d', strtotime($ev_entregado['fecha']));
                    $pdo->prepare("UPDATE envios SET fecha_recibido=? WHERE id=?")
                        ->execute([$fecha_rec, $envio_id]);
                }
            }
            return null;
        }

        // Avance de estado
        if ($new_id === 5) {
            $ev_entregado = array_filter($data['eventos'] ?? [], fn($e) => $e['estado'] === 'ENTREGADO');
            $ev_entregado = reset($ev_entregado);
            $fecha_rec = $ev_entregado ? date('Y-m-d', strtotime($ev_entregado['fecha'])) : null;
            if ($fecha_rec) {
                $pdo->prepare("UPDATE envios SET estado_envio_id=?, fecha_recibido=? WHERE id=?")
                    ->execute([$new_id, $fecha_rec, $envio_id]);
            } else {
                $pdo->prepare("UPDATE envios SET estado_envio_id=? WHERE id=?")
                    ->execute([$new_id, $envio_id]);
            }
        } else {
            $pdo->prepare("UPDATE envios SET estado_envio_id=? WHERE id=?")
                ->execute([$new_id, $envio_id]);
        }

        return $new_id;
    }

    /**
     * Formatea fecha de la API Olva a "DD/MM/YYYY" (o "DD/MM/YYYY HH:MM" si tiene hora).
     * Entrada: "2026-01-22" o "2026-01-22 14:30:00"
     */
    private static function formatFecha(string $raw): string
    {
        if ($raw === '') return '';
        $ts = strtotime($raw);
        if ($ts === false) return $raw;
        $hora = date('H:i', $ts);
        return $hora !== '00:00'
            ? date('d/m/Y', $ts) . ' ' . $hora
            : date('d/m/Y', $ts);
    }
}
