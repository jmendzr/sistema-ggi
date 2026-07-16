<?php
/**
 * SerpostTrackingService
 * Consulta el estado de envíos Serpost vía su API REST oficial (la misma que usa
 * https://www.serpost.com.pe/Cliente/SegumientoLinea).
 *
 * Flujo:
 *   1) POST  /Web_Api_Seguimiento/api/Autenticacion/Validar   → { "token": "<JWT>" }
 *   2) GET   /Web_Api_Seguimiento/api/Tracking/{codigo}        → eventos NACIONALES ("común")
 *   3) GET   /Web_Api_Seguimiento/api/EventoIPS/{codigo}       → eventos INTERNACIONALES (IPS)
 *      (header: Authorization: BEARER <JWT>)
 *
 * Un código puede ser nacional o internacional. Se prueba Tracking primero; si viene
 * vacío, se consulta EventoIPS (envíos internacionales tipo RS022128065CW).
 */
class SerpostTrackingService
{
    private const API_BASE  = 'https://webservice.serpost.com.pe/Web_Api_Seguimiento/api';
    // Credenciales públicas embebidas en el JS del portal de Serpost.
    private const AUTH_BODY  = '{"_Ox76":"_0x2eb74d(7)","_Ox77":"_53/X73POo57/x520"}';

    /** Mapa de códigos de evento NACIONAL (api/Tracking) → etiqueta legible. */
    private const EVENTOS_NAC = [
        '001' => 'Admitido',
        '005' => 'En tránsito',
        '007' => 'En distribución',
        '008' => 'Incidencia en la entrega',
        '012' => 'En tránsito',
        '016' => 'En tránsito',
        '018' => 'En distribución',
        '019' => 'Incidencia en la entrega',
        '020' => 'Entregado',
        '024' => 'En oficina (disponible para recojo)',
        '025' => 'En oficina (disponible para recojo)',
        '028' => 'En distribución',
        '031' => 'En tránsito',
        '035' => 'Procesado',
        '037' => 'Devolución',
        '040' => 'En tránsito',
        '066' => 'Procesado',
        '067' => 'Disponible para entrega',
    ];

    /** Obtiene un token JWT (válido ~17 min). Devuelve null si falla. */
    private static function token(): ?string
    {
        $ch = curl_init(self::API_BASE . '/Autenticacion/Validar');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => self::AUTH_BODY,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200 || !$body) return null;
        $json = json_decode($body, true);
        $tk = $json['token'] ?? null;
        return (is_string($tk) && $tk !== '') ? $tk : null;
    }

    /** GET autenticado a un recurso de la API; devuelve el array decodificado (o null). */
    private static function get(string $path, string $token): ?array
    {
        $ch = curl_init(self::API_BASE . '/' . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => ['Authorization: BEARER ' . $token, 'Accept: application/json'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200 || !$body) return null;
        $json = json_decode($body, true);
        return is_array($json) ? $json : null;
    }

    /**
     * Consulta un envío Serpost (nacional o internacional). Devuelve null si no hay datos.
     * @param string $tracking código de seguimiento (ej. "RS022128065CW" o "EE123456789PE")
     */
    public static function consultar(string $tracking): ?array
    {
        $tracking = strtoupper(preg_replace('/\s+/', '', $tracking));
        if ($tracking === '') return null;

        $token = self::token();
        if (!$token) return null;

        // 1) Nacional ("común")
        $nac = self::get('Tracking/' . rawurlencode($tracking), $token);
        if (is_array($nac) && count($nac) > 0) {
            return self::parseNacional($tracking, $nac);
        }

        // 2) Internacional (IPS)
        $ips = self::get('EventoIPS/' . rawurlencode($tracking), $token);
        if (is_array($ips) && count($ips) > 0) {
            return self::parseInternacional($tracking, $ips);
        }

        return null;
    }

    /** Construye la respuesta desde los eventos nacionales (api/Tracking). */
    private static function parseNacional(string $tracking, array $rows): array
    {
        $cab = $rows[0];
        $eventos = [];
        foreach ($rows as $r) {
            $cod   = trim((string)($r['codevento'] ?? ''));
            // La API podría traer texto; si no, se usa el mapa por código.
            $label = trim((string)($r['descevento'] ?? $r['evento'] ?? ''));
            if ($label === '') $label = self::EVENTOS_NAC[$cod] ?? ('Evento ' . $cod);
            $fecha = trim((string)($r['fecha'] ?? ''));
            $hora  = trim((string)($r['hora'] ?? ''));
            $eventos[] = [
                'fecha'  => $hora !== '' ? ($fecha . ' ' . $hora) : $fecha,
                'estado' => mb_strtoupper($label),     // para color/dotClass en el front
                'label'  => $label,
                'sede'   => trim((string)($r['oficinadestino'] ?? '')),
            ];
        }
        $estadoLabel = $eventos[0]['label'] ?? 'Sin estado';

        return [
            'tracking'      => $tracking,
            'tipo'          => 'nacional',
            'origen'        => trim((string)($cab['oficinaorigen'] ?? $cab['codorigen'] ?? '')),
            'destino'       => trim((string)($cab['destino'] ?? '')),
            'servicio'      => trim((string)($cab['tipodesp'] ?? '')),
            'estado'        => mb_strtoupper($estadoLabel),
            'estado_label'  => $estadoLabel,
            'estado_ggi_id' => self::mapEstadoGgi($estadoLabel),
            'eventos'       => $eventos,
        ];
    }

    /** Construye la respuesta desde los eventos internacionales (api/EventoIPS). */
    private static function parseInternacional(string $tracking, array $rows): array
    {
        $eventos = [];
        foreach ($rows as $r) {
            $desc = trim((string)($r['descespa'] ?? ''));
            if ($desc === '') continue;              // eventos sin descripción (ruido)
            $eventos[] = [
                'fecha'  => self::fechaIps((string)($r['fecha'] ?? '')),
                'estado' => mb_strtoupper($desc),    // para color/dotClass en el front
                'label'  => self::titulo($desc),
            ];
        }
        if (empty($eventos)) {
            // todos sin descripción → tomar el más reciente igual
            $eventos[] = ['fecha' => self::fechaIps((string)($rows[0]['fecha'] ?? '')),
                          'estado' => '', 'label' => 'En proceso'];
        }

        $cab         = $rows[0];
        $estadoLabel = $eventos[0]['label'];

        return [
            'tracking'      => $tracking,
            'tipo'          => 'internacional',
            'origen'        => trim((string)($cab['paiS_ORIGEN'] ?? $cab['pais_origen'] ?? '')),
            'destino'       => trim((string)($cab['paiS_DESTINO'] ?? $cab['pais_destino'] ?? '')),
            'servicio'      => 'Postal internacional',
            'estado'        => mb_strtoupper($estadoLabel),
            'estado_label'  => $estadoLabel,
            'estado_ggi_id' => self::mapEstadoGgi($estadoLabel),
            'eventos'       => $eventos,
        ];
    }

    /**
     * Sincroniza el estado de un envío en BD si Serpost está más avanzado.
     * Solo avanza, nunca retrocede. Retorna el nuevo estado_id o null si no hubo cambio.
     * (Mismo contrato que OlvaTrackingService::sincronizar.)
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

        $new_id = (int)$data['estado_ggi_id'];

        $ord = $pdo->prepare("SELECT orden FROM estados_envio WHERE id = ?");
        $ord->execute([$new_id]);
        $orden_nuevo = (int)$ord->fetchColumn();

        // Sin avance — pero corregir fecha_recibido si falta estando Entregado
        if ($orden_nuevo <= (int)$row['orden_actual']) {
            if ((int)$row['estado_envio_id'] === 5 && empty($row['fecha_recibido'])) {
                $fecha_rec = self::fechaEntrega($data['eventos'] ?? []);
                if ($fecha_rec) {
                    $pdo->prepare("UPDATE envios SET fecha_recibido=? WHERE id=?")
                        ->execute([$fecha_rec, $envio_id]);
                }
            }
            return null;
        }

        // Avance de estado
        if ($new_id === 5) {
            $fecha_rec = self::fechaEntrega($data['eventos'] ?? []);
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

    /** Busca el evento de entrega y devuelve su fecha en formato Y-m-d (o null). */
    private static function fechaEntrega(array $eventos): ?string
    {
        foreach ($eventos as $ev) {
            if (str_contains(mb_strtoupper($ev['estado'] ?? ''), 'ENTREGAD')) {
                // eventos['fecha'] viene como "DD/MM/YYYY[ HH:MM]" → parse seguro
                $dt = \DateTime::createFromFormat('d/m/Y', substr($ev['fecha'] ?? '', 0, 10));
                return $dt ? $dt->format('Y-m-d') : null;
            }
        }
        return null;
    }

    /**
     * Mapea un estado/descripción Serpost a un id de estado interno GGI (heurística por palabras clave).
     * IDs: 8=Recepcionado, 3=En tránsito, 4=En distribución, 5=Entregado, 6=Devuelto.
     */
    private static function mapEstadoGgi(string $estado): ?int
    {
        $e = mb_strtoupper($estado);
        if ($e === '') return null;
        if (str_contains($e, 'ENTREGAD'))                                   return 5;
        if (str_contains($e, 'DEVUEL') || str_contains($e, 'DEVOLUC')
            || str_contains($e, 'REZAGAD') || str_contains($e, 'NO ENTREGAD')) return 6;
        if (str_contains($e, 'DISTRIBUC') || str_contains($e, 'REPARTO')
            || str_contains($e, 'DISPONIBLE PARA ENTREGA')
            || str_contains($e, 'DISPONIBLE PARA RECOJO'))                  return 4;
        if (str_contains($e, 'TRÁNSITO') || str_contains($e, 'TRANSITO')
            || str_contains($e, 'CAMINO') || str_contains($e, 'DESPACH')
            || str_contains($e, 'RUMBO'))                                   return 3;
        if (str_contains($e, 'PROCESAD') || str_contains($e, 'ADMIT')
            || str_contains($e, 'RECEP') || str_contains($e, 'IMPOSIC')
            || str_contains($e, 'INGRES'))                                  return 8;
        return null;
    }

    private static function titulo(string $s): string
    {
        $s = trim(preg_replace('/\s+/', ' ', $s));   // colapsa \r\n y espacios
        return $s === '' ? '' : mb_convert_case(mb_strtolower($s), MB_CASE_TITLE, 'UTF-8');
    }

    /** EventoIPS entrega fechas "MM/DD/YYYY HH:MM:SS" → "DD/MM/YYYY HH:MM". */
    private static function fechaIps(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') return '';
        $dt = \DateTime::createFromFormat('m/d/Y H:i:s', $raw);
        if (!$dt) $dt = \DateTime::createFromFormat('m/d/Y H:i', $raw);
        if (!$dt) $dt = \DateTime::createFromFormat('m/d/Y', substr($raw, 0, 10));
        if (!$dt) return $raw;
        $hora = $dt->format('H:i');
        return $hora !== '00:00' ? $dt->format('d/m/Y') . ' ' . $hora : $dt->format('d/m/Y');
    }
}
