<?php
// core/SunatService.php — Consulta RUC via apiperu.dev
require_once __DIR__ . '/../config/config.php';

class SunatService {

    private static function headers(): array {
        return [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . SUNAT_API_TOKEN,
        ];
    }

    // ── Consulta datos principales del RUC ──
    public static function consultarRuc(string $ruc): array {
        if (!preg_match('/^\d{11}$/', $ruc)) {
            return ['ok' => false, 'mensaje' => 'RUC debe tener 11 dígitos.'];
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL             => 'https://apiperu.dev/api/ruc',
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_CUSTOMREQUEST   => 'POST',
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST  => 2,
            CURLOPT_POSTFIELDS      => json_encode(['ruc' => $ruc]),
            CURLOPT_CONNECTTIMEOUT  => 5,   // abortar si el servidor no responde en 5 s
            CURLOPT_TIMEOUT         => 10,  // tiempo total máximo de la petición
            CURLOPT_HTTPHEADER      => self::headers(),
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['ok' => false, 'mensaje' => 'Error de conexión: ' . $error];
        }

        $json = json_decode($response, true);

        if ($httpCode !== 200 || empty($json['data'])) {
            return ['ok' => false, 'mensaje' => $json['message'] ?? 'RUC no encontrado.'];
        }

        $d = $json['data'];

        // Usar direccion_completa si existe, sino armar desde partes
        if (!empty($d['direccion_completa'])) {
            $direccion = $d['direccion_completa'];
        } else {
            $partes = array_filter([
                $d['direccion']    ?? '',
                $d['distrito']     ?? '',
                $d['provincia']    ?? '',
                $d['departamento'] ?? '',
            ]);
            $direccion = implode(' - ', $partes);
        }

        // ubigeo puede venir como array ['20','2001','200104'] → extraer el código de distrito (último)
        $ubigeo_raw = $d['ubigeo'] ?? '';
        if (is_array($ubigeo_raw)) {
            $ubigeo_raw = end($ubigeo_raw) ?: implode('', $ubigeo_raw);
        }

        return [
            'ok'           => true,
            'ruc'          => $d['ruc']                   ?? $ruc,
            'razon_social' => $d['nombre_o_razon_social'] ?? $d['razon_social'] ?? '',
            'direccion'    => $direccion,
            'departamento' => $d['departamento']          ?? '',
            'provincia'    => $d['provincia']             ?? '',
            'distrito'     => $d['distrito']              ?? '',
            'ubigeo'       => $ubigeo_raw,
            'estado'       => $d['estado']                ?? '',
            'condicion'    => $d['condicion_domicilio']   ?? $d['condicion'] ?? '',
        ];
    }

    // ── Consulta domicilio fiscal del RUC ──
    public static function consultarDomicilioFiscal(string $ruc): array {
        if (!preg_match('/^\d{11}$/', $ruc)) {
            return ['ok' => false, 'mensaje' => 'RUC debe tener 11 dígitos.'];
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL             => 'https://apiperu.dev/api/ruc-domicilio-fiscal',
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_CUSTOMREQUEST   => 'POST',
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST  => 2,
            CURLOPT_POSTFIELDS      => json_encode(['ruc' => $ruc]),
            CURLOPT_CONNECTTIMEOUT  => 5,
            CURLOPT_TIMEOUT         => 8,
            CURLOPT_HTTPHEADER      => self::headers(),
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error || $httpCode !== 200) {
            return ['ok' => false, 'mensaje' => $error ?: 'Error HTTP ' . $httpCode];
        }

        $json = json_decode($response, true);

        if (empty($json['success']) || empty($json['data'])) {
            return ['ok' => false, 'mensaje' => $json['message'] ?? 'Sin datos de domicilio.'];
        }

        $d = $json['data'];

        return [
            'ok'               => true,
            'direccion'        => $d['direccion']          ?? '',
            'direccion_completa' => $d['direccion_completa'] ?? '',
            'departamento'     => $d['departamento']       ?? '',
            'provincia'        => $d['provincia']          ?? '',
            'distrito'         => $d['distrito']           ?? '',
        ];
    }

    /**
     * Consulta el número de trabajadores registrados en SUNAT para un RUC.
     * Retorna ['ok'=>true, 'num_trabajadores'=>N] o ['ok'=>false, 'mensaje'=>'...'].
     */
    public static function consultarTrabajadores(string $ruc): array {
        if (!preg_match('/^\d{11}$/', $ruc)) {
            return ['ok' => false, 'mensaje' => 'RUC inválido.'];
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL             => 'https://apiperu.dev/api/ruc_trabajadores',
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_CUSTOMREQUEST   => 'POST',
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST  => 2,
            CURLOPT_POSTFIELDS      => json_encode(['ruc' => $ruc]),
            CURLOPT_CONNECTTIMEOUT  => 5,
            CURLOPT_TIMEOUT         => 10,
            CURLOPT_HTTPHEADER      => self::headers(),
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['ok' => false, 'mensaje' => 'Error de conexión: ' . $error];
        }

        $json = json_decode($response, true);

        if ($httpCode !== 200 || empty($json['data'])) {
            return ['ok' => false, 'mensaje' => $json['message'] ?? 'Sin datos de trabajadores.'];
        }

        $d = $json['data'];

        // La API devuelve un array de registros mensuales, cada uno con 'periodo' y 'trabajadores'.
        // Tomamos el registro del último mes (mayor periodo) y extraemos 'trabajadores'.
        $total = null;
        if (is_array($d) && isset($d[0])) {
            // Ordenar por campo 'periodo' descendente para asegurar el último mes
            usort($d, fn($a, $b) => strcmp((string)($b['periodo'] ?? ''), (string)($a['periodo'] ?? '')));
            $ultimo = $d[0];
            if (isset($ultimo['trabajadores']) && is_numeric($ultimo['trabajadores'])) {
                $total = (int)$ultimo['trabajadores'];
            }
        } elseif (isset($d['trabajadores']) && is_numeric($d['trabajadores'])) {
            $total = (int)$d['trabajadores'];
        } elseif (isset($d['total']) && is_numeric($d['total'])) {
            $total = (int)$d['total'];
        } elseif (isset($d['cantidad']) && is_numeric($d['cantidad'])) {
            $total = (int)$d['cantidad'];
        }

        if ($total === null) {
            return ['ok' => false, 'mensaje' => 'Respuesta de API no reconocida.'];
        }

        return ['ok' => true, 'num_trabajadores' => $total];
    }

    /**
     * Consulta representantes legales con reintentos automáticos.
     * Hasta 3 intentos (intento 0, 1, 2) con pausa de 1 segundo entre fallos.
     * Distingue entre "empresa no tiene representantes" y "API falló":
     *   - ['ok'=>false, 'sin_representante'=>true]  → empresa sin datos en SUNAT
     *   - ['ok'=>false, 'sin_representante'=>false] → fallo de red / timeout
     */
    public static function consultarGerente(string $ruc, int $intentos = 3): array {
        if (!preg_match('/^\d{11}$/', $ruc)) {
            return ['ok' => false, 'sin_representante' => false, 'mensaje' => 'RUC inválido.'];
        }

        $ultimo_error = 'Sin respuesta.';

        for ($intento = 0; $intento < $intentos; $intento++) {
            // Pausa progresiva entre reintentos (0s, 1s, 2s)
            if ($intento > 0) sleep($intento);

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL             => 'https://apiperu.dev/api/ruc_representantes',
                CURLOPT_RETURNTRANSFER  => true,
                CURLOPT_CUSTOMREQUEST   => 'POST',
                CURLOPT_SSL_VERIFYPEER  => true,
                CURLOPT_SSL_VERIFYHOST  => 2,
                CURLOPT_POSTFIELDS      => json_encode(['ruc' => $ruc]),
                CURLOPT_CONNECTTIMEOUT  => 5,
                CURLOPT_TIMEOUT         => 8,
                CURLOPT_HTTPHEADER      => self::headers(),
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error    = curl_error($ch);
            curl_close($ch);

            if ($error) {
                $ultimo_error = 'Error de red: ' . $error;
                continue; // reintentar
            }

            $json = json_decode($response, true);

            // HTTP 404 o data vacía = empresa sin representantes registrados (no reintentar)
            if ($httpCode === 404 || ($httpCode === 200 && isset($json['data']) && empty($json['data']))) {
                return ['ok' => false, 'sin_representante' => true, 'mensaje' => 'La empresa no tiene representantes en SUNAT.'];
            }

            if ($httpCode !== 200 || empty($json['data'])) {
                $ultimo_error = $json['message'] ?? ('HTTP ' . $httpCode);
                continue; // reintentar
            }

            // ── Éxito: procesar representantes ──
            $representantes = $json['data'];

            $prioridad = [
                'GERENTE GENERAL',
                'TITULAR-GERENTE',
                'TITULAR GERENTE',
                'GERENTE',
                'DIRECTOR GERENTE',
                'APODERADO',
            ];

            $elegido = null;
            foreach ($prioridad as $cargo_buscado) {
                foreach ($representantes as $rep) {
                    $cargo = strtoupper(trim($rep['cargo'] ?? ''));
                    if (str_contains($cargo, $cargo_buscado)) {
                        $elegido = $rep;
                        break 2;
                    }
                }
            }

            if (!$elegido && !empty($representantes)) {
                $elegido = $representantes[0];
            }

            if (!$elegido) {
                return ['ok' => false, 'sin_representante' => true, 'mensaje' => 'Sin representante válido.'];
            }

            // SUNAT devuelve: APELLIDO1 APELLIDO2 NOMBRE1 NOMBRE2
            $nombre_completo = trim($elegido['nombre'] ?? $elegido['nombre_completo'] ?? '');
            $partes          = explode(' ', $nombre_completo);
            $apellido = '';
            $nombre   = $nombre_completo;
            if (count($partes) >= 3) {
                $apellido = $partes[0] . ' ' . $partes[1];
                $nombre   = implode(' ', array_slice($partes, 2));
            } elseif (count($partes) === 2) {
                $apellido = $partes[0];
                $nombre   = $partes[1];
            }

            return [
                'ok'               => true,
                'sin_representante'=> false,
                'nombre'           => ucwords(strtolower($nombre)),
                'apellido'         => ucwords(strtolower($apellido)),
                'cargo'            => ucwords(strtolower(trim($elegido['cargo'] ?? ''))),
            ];
        }

        // Todos los intentos fallaron
        return ['ok' => false, 'sin_representante' => false, 'mensaje' => $ultimo_error];
    }
}
