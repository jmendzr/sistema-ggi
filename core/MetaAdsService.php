<?php
/**
 * core/MetaAdsService.php — Lectura del gasto/rendimiento de anuncios de Meta.
 *
 * Usa la Marketing API (Ads Insights) con un token de usuario del sistema que
 * tiene el permiso `ads_read` (META_ADS_TOKEN) sobre la cuenta publicitaria
 * (META_AD_ACCOUNT_ID, formato act_XXXX). Solo lectura.
 *
 * Cachea las respuestas en disco (storage/cache/meta_ads/) por un rato para no
 * golpear la API en cada carga de página ni pegar contra los límites de Meta.
 */
class MetaAdsService
{
    /** Minutos de vida del caché (el gasto de días pasados no cambia). */
    private const CACHE_MIN = 180;

    private string $token;
    private string $acct;
    private string $ver;
    public  ?string $lastError = null;

    public function __construct()
    {
        $this->token = defined('META_ADS_TOKEN') ? (string)META_ADS_TOKEN : '';
        $this->acct  = defined('META_AD_ACCOUNT_ID') ? (string)META_AD_ACCOUNT_ID : '';
        $this->ver   = defined('META_WA_VERSION') ? (string)META_WA_VERSION : 'v21.0';
    }

    public function configurado(): bool
    {
        return $this->token !== '' && $this->acct !== '';
    }

    /** Info básica de la cuenta (nombre, moneda, estado). */
    public function cuenta(): ?array
    {
        return $this->get($this->acct, [
            'fields' => 'name,currency,account_status,timezone_name',
        ], 1440); // 1 día
    }

    /**
     * Gasto por mes de un año. Devuelve [1..12] => float (S/), meses sin gasto = 0.
     */
    public function gastoMensual(int $anio): array
    {
        $meses = array_fill(1, 12, 0.0);
        $hoy   = date('Y-m-d');
        $until = ($anio === (int)date('Y')) ? $hoy : sprintf('%04d-12-31', $anio);
        $ttl   = ($anio === (int)date('Y')) ? 60 : self::CACHE_MIN; // año en curso: refresco más ágil

        $res = $this->get($this->acct . '/insights', [
            'fields'        => 'spend',
            'time_increment'=> 'monthly',
            'time_range'    => json_encode(['since' => sprintf('%04d-01-01', $anio), 'until' => $until]),
            'level'         => 'account',
        ], $ttl);

        foreach (($res['data'] ?? []) as $row) {
            $m = (int)date('n', strtotime($row['date_start'] ?? ''));
            if ($m >= 1 && $m <= 12) $meses[$m] += (float)($row['spend'] ?? 0);
        }
        return $meses;
    }

    /**
     * Gasto por campaña en un rango de fechas (YYYY-MM-DD). Ordenado desc.
     * Cada fila: ['campaign' => str, 'spend' => float, 'impressions' => int, 'clicks' => int].
     */
    public function gastoPorCampania(string $since, string $until): array
    {
        $ttl = ($until >= date('Y-m-d')) ? 60 : self::CACHE_MIN;
        $out = [];
        $res = $this->get($this->acct . '/insights', [
            'fields'     => 'campaign_name,spend,impressions,clicks',
            'level'      => 'campaign',
            'time_range' => json_encode(['since' => $since, 'until' => $until]),
            'limit'      => 200,
        ], $ttl);
        foreach (($res['data'] ?? []) as $r) {
            $out[] = [
                'campaign'    => $r['campaign_name'] ?? '(sin nombre)',
                'spend'       => (float)($r['spend'] ?? 0),
                'impressions' => (int)($r['impressions'] ?? 0),
                'clicks'      => (int)($r['clicks'] ?? 0),
            ];
        }
        usort($out, fn($a, $b) => $b['spend'] <=> $a['spend']);
        return $out;
    }

    /**
     * Gasto por ANUNCIO en un rango. Clave = ad_id (coincide con el source_id del
     * referral CTWA). Cada valor: ['ad_id','ad_name','campaign','spend','impressions','clicks'].
     */
    public function gastoPorAnuncio(string $since, string $until): array
    {
        $ttl = ($until >= date('Y-m-d')) ? 60 : self::CACHE_MIN;
        $out = [];
        $res = $this->get($this->acct . '/insights', [
            'fields'     => 'ad_id,ad_name,campaign_name,spend,impressions,clicks',
            'level'      => 'ad',
            'time_range' => json_encode(['since' => $since, 'until' => $until]),
            'limit'      => 500,
        ], $ttl);
        foreach (($res['data'] ?? []) as $r) {
            $id = (string)($r['ad_id'] ?? '');
            if ($id === '') continue;
            $out[$id] = [
                'ad_id'       => $id,
                'ad_name'     => $r['ad_name'] ?? '',
                'campaign'    => $r['campaign_name'] ?? '',
                'spend'       => (float)($r['spend'] ?? 0),
                'impressions' => (int)($r['impressions'] ?? 0),
                'clicks'      => (int)($r['clicks'] ?? 0),
            ];
        }
        return $out;
    }

    // ── Interno ──────────────────────────────────────────────────────────────

    /** GET a Graph con caché en disco. Devuelve el array decodificado o null. */
    private function get(string $path, array $params, int $ttlMin): ?array
    {
        if (!$this->configurado()) { $this->lastError = 'Marketing API no configurada'; return null; }

        $params['access_token'] = $this->token;
        $url = "https://graph.facebook.com/{$this->ver}/{$path}?" . http_build_query($params);

        $ck = $this->cacheKey($path, $params);
        if (($hit = $this->cacheGet($ck, $ttlMin)) !== null) return $hit;

        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if ($raw === false) { $this->lastError = 'Red: ' . $cerr; return $this->cacheGet($ck, 43200); /* usa caché viejo si hay */ }
        $j = json_decode($raw, true);
        if (isset($j['error'])) {
            $this->lastError = '(' . ($j['error']['code'] ?? '?') . ') ' . ($j['error']['message'] ?? 'error');
            return $this->cacheGet($ck, 43200); // ante error, sirve caché viejo si existe
        }
        if ($code >= 200 && $code < 300 && is_array($j)) {
            $this->cachePut($ck, $j);
            return $j;
        }
        $this->lastError = "HTTP $code";
        return null;
    }

    private function cacheDir(): string
    {
        $d = __DIR__ . '/../storage/cache/meta_ads';
        if (!is_dir($d)) @mkdir($d, 0755, true);
        return $d;
    }

    private function cacheKey(string $path, array $params): string
    {
        $p = $params; unset($p['access_token']);
        return md5($path . '|' . json_encode($p));
    }

    private function cacheGet(string $key, int $ttlMin): ?array
    {
        $f = $this->cacheDir() . '/' . $key . '.json';
        if (!is_file($f)) return null;
        if ($ttlMin > 0 && (time() - filemtime($f)) > $ttlMin * 60) return null;
        $c = json_decode((string)file_get_contents($f), true);
        return is_array($c) ? $c : null;
    }

    private function cachePut(string $key, array $data): void
    {
        @file_put_contents($this->cacheDir() . '/' . $key . '.json', json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
