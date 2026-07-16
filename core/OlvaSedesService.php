<?php
/**
 * OlvaSedesService
 * Obtiene los 1874 distritos de cobertura de Olva Courier (punto de llegada)
 * desde la API pública de www.olvacourier.com.
 * Cachea el resultado por 24 h en /tmp.
 */
class OlvaSedesService {

    private const BASE_URL   = 'https://www.olvacourier.com';
    private const CACHE_TTL  = 86400; // 24 horas
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36';
    // App de registro de envíos (lista completa de tiendas/agentes por distrito)
    private const TIENDA_BASE   = 'https://registrodeenvios.olvacourier.com';
    private const TIENDA_ORIGEN = '15'; // sede_origen (Lima); solo requiere ser no vacío

    private static function cacheFile(): string {
        return sys_get_temp_dir() . '/olva_sedes.json';
    }

    private static function post(string $path, array $data): array {
        $ch = curl_init(self::BASE_URL . '/' . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($data),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded',
                'User-Agent: ' . self::USER_AGENT,
            ],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200 || !$body) return [];
        return json_decode($body, true) ?? [];
    }

    private static function get(string $path): array {
        $ch = curl_init(self::BASE_URL . '/' . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER     => ['User-Agent: ' . self::USER_AGENT],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200 || !$body) return [];
        return json_decode($body, true) ?? [];
    }

    /**
     * Descarga todos los distritos de cobertura de Olva (~1874 a nivel nacional).
     * Itera: departamentos → provincias → distritos.
     */
    private static function fetchAll(): array {
        $depts = self::get('departamentos');
        if (empty($depts)) return [];

        $result = [];
        foreach ($depts as $dept) {
            $deptName = mb_strtoupper($dept['departamento'] ?? '');
            $deptId   = $dept['id'] ?? '';
            if (!$deptId) continue;

            $provs = self::post('provincias', ['departamento' => $deptId]);
            foreach ($provs as $prov) {
                $provName = mb_strtoupper($prov['provincia'] ?? '');
                $provId   = $prov['id'] ?? '';
                if (!$provId) continue;

                $dists = self::post('distritos', ['provincia' => $provId]);
                foreach ($dists as $dist) {
                    $distName = trim(mb_strtoupper($dist['name'] ?? ''));
                    if (!$distName) continue;

                    $label = implode(' - ', array_filter([$deptName, $provName, $distName]));
                    $result[] = [
                        'id'           => $dist['id'] ?? '',
                        'departamento' => $deptName,
                        'dept_id'      => $deptId,
                        'provincia'    => $provName,
                        'prov_id'      => $provId,
                        'distrito'     => $distName,
                        'label'        => $label,
                        'lat'          => $dist['lat'] ?? '',
                        'lng'          => $dist['lng'] ?? '',
                    ];
                }
                usleep(40000); // 40 ms entre llamadas
            }
        }

        usort($result, fn($a, $b) => strcmp($a['label'], $b['label']));
        return $result;
    }

    /**
     * Corrige nombres/direcciones con doble-codificación UTF-8 que a veces
     * devuelve la API de Olva (ej. "Jesús" → "JesÃºs").
     */
    private static function fixEnc(string $s): string {
        if ($s !== '' && preg_match('/Ã.|Â./u', $s)) {
            $d = @mb_convert_encoding($s, 'ISO-8859-1', 'UTF-8');
            if ($d !== false && $d !== '' && mb_check_encoding($d, 'UTF-8')) return $d;
        }
        return $s;
    }

    private static function tiendasCacheFile(string $ubigeo): string {
        return sys_get_temp_dir() . '/olva_tiendas_' . $ubigeo . '.json';
    }

    /**
     * Lista COMPLETA de puntos de recojo Olva (tiendas / oficinas / agentes) del
     * distrito de destino, igual que la web de Olva. Usa el endpoint `/tienda` de
     * registrodeenvios.olvacourier.com (Laravel, requiere sesión + CSRF).
     * cod_prov = ubigeo del distrito (6 díg.), cod_dpto = 2 primeros díg.
     * Cache 24 h por distrito en /tmp/olva_tiendas_{ubigeo}.json.
     *
     * @return array<int,array{nombre:string}>
     */
    public static function tiendasPorDistrito(string $ubigeo): array {
        $ubigeo = preg_replace('/\D/', '', $ubigeo);
        if (strlen($ubigeo) < 4) return [];

        $cache = self::tiendasCacheFile($ubigeo);
        if (file_exists($cache) && (time() - filemtime($cache)) < self::CACHE_TTL) {
            $d = json_decode(file_get_contents($cache), true);
            if (is_array($d)) return $d;
        }

        $result = self::fetchTiendas($ubigeo);
        if (!empty($result)) {
            file_put_contents($cache, json_encode($result, JSON_UNESCAPED_UNICODE));
            return $result;
        }
        // API falló → usar cache previo si existe
        if (file_exists($cache)) {
            $d = json_decode(file_get_contents($cache), true);
            if (is_array($d)) return $d;
        }
        return [];
    }

    /** Handshake Laravel (sesión + CSRF) y POST /tienda; devuelve las opciones parseadas. */
    private static function fetchTiendas(string $ubigeo): array {
        $jar = tempnam(sys_get_temp_dir(), 'olvasess');

        // 1) Sesión + token CSRF desde /principal
        $ch = curl_init(self::TIENDA_BASE . '/');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_COOKIEJAR      => $jar,
            CURLOPT_COOKIEFILE     => $jar,
            CURLOPT_HTTPHEADER     => ['User-Agent: ' . self::USER_AGENT],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $home = curl_exec($ch);
        curl_close($ch);
        if (!$home || !preg_match('/csrf-token"\s+content="([^"]+)"/', $home, $m)) { @unlink($jar); return []; }
        $token = $m[1];

        // 2) POST /tienda
        $ch = curl_init(self::TIENDA_BASE . '/tienda');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'cod_dpto'       => substr($ubigeo, 0, 2),
                'cod_prov'       => $ubigeo,
                'agente_oficina' => 1,
                'sede_origen'    => self::TIENDA_ORIGEN,
            ]),
            CURLOPT_COOKIEJAR  => $jar,
            CURLOPT_COOKIEFILE => $jar,
            CURLOPT_HTTPHEADER => [
                'User-Agent: ' . self::USER_AGENT,
                'X-CSRF-TOKEN: ' . $token,
                'X-Requested-With: XMLHttpRequest',
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $html = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        @unlink($jar);
        if ($code !== 200 || !$html) return [];

        // 3) Parsear <option> (saltar el placeholder sin value); decodificar doble entidad
        $out = [];
        if (preg_match_all('/<option\b([^>]*)>(.*?)<\/option>/si', $html, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $op) {
                if (!preg_match('/value="[^"]+"/', $op[1])) continue; // placeholder
                $nombre = strip_tags($op[2]);
                $nombre = html_entity_decode(html_entity_decode($nombre, ENT_QUOTES | ENT_HTML5), ENT_QUOTES | ENT_HTML5);
                $nombre = self::fixEnc(trim(preg_replace('/\s+/', ' ', $nombre)));
                if ($nombre !== '') $out[] = ['nombre' => $nombre];
            }
        }
        return $out;
    }

    /**
     * Devuelve el listado completo de distritos de cobertura de Olva.
     * Cache de 24 h en /tmp/olva_sedes.json.
     */
    public static function listar(): array {
        $cache = self::cacheFile();

        // Cargar cache existente (aunque esté expirado) como respaldo.
        $cacheData = null;
        if (file_exists($cache)) {
            $d = json_decode(file_get_contents($cache), true);
            if (is_array($d) && count($d) > 100) $cacheData = $d;
        }

        // Cache fresco → devolver directo.
        if ($cacheData !== null && (time() - filemtime($cache)) < self::CACHE_TTL) {
            return $cacheData;
        }

        // Cache vencido o ausente → reconstruir desde la API (proceso lento).
        $result = self::fetchAll();
        if (!empty($result)) {
            file_put_contents($cache, json_encode($result, JSON_UNESCAPED_UNICODE));
            return $result;
        }

        // La reconstrucción falló (timeout/API caída) → usar cache previo si existe.
        if ($cacheData !== null) {
            @touch($cache); // evita reintentar el fetch costoso en cada request por 24h
            return $cacheData;
        }
        return [];
    }
}
