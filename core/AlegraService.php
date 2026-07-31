<?php
/**
 * core/AlegraService.php — Facturación electrónica SUNAT vía Alegra (Perú).
 *
 * Emite facturas de venta directamente a SUNAT tras el pago, reemplazando el
 * proceso manual (crear en Alegra + subir PDF). Credenciales en .env
 * (ALEGRA_EMAIL / ALEGRA_TOKEN). Basic Auth = base64(email:token).
 *
 * IDs verificados contra la cuenta real (GET /items, /retentions):
 *   Ítems:   15 = "Servicios…" (total) · 14 = "Anticipo…" · 16 = "Saldo…"
 *   IGV:     tax id 1 (18%)
 *   Detracción [012] Intermediación laboral y tercerización 12% = retention id 17
 *   Numeración factura FA01 = numberTemplate id 13
 */

require_once __DIR__ . '/../config/config.php';

class AlegraService
{
    // ── Catálogo (IDs de la cuenta Alegra de GGI) ──
    const ITEM_TOTAL     = 15;
    const ITEM_ANTICIPO  = 14;
    const ITEM_SALDO     = 16;
    const TAX_IGV        = 1;    // IGV 18%
    const DETRACCION_012 = 17;   // [012] Intermediación laboral y tercerización 12%
    const NUMBER_FACTURA = 13;   // Serie FA01
    const IGV_RATE       = 0.18;
    const DETRACCION_MIN = 700;  // umbral SUNAT: detracción solo si total > S/700

    public static $lastError = null;

    public static function configurada(): bool
    {
        return defined('ALEGRA_EMAIL') && ALEGRA_EMAIL !== ''
            && defined('ALEGRA_TOKEN') && ALEGRA_TOKEN !== '';
    }

    /** Petición HTTP a la API de Alegra. Devuelve [httpCode, arrayDecodificado]. */
    private static function req(string $method, string $path, ?array $body = null): array
    {
        $ch = curl_init(ALEGRA_API . $path);
        $opts = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Basic ' . base64_encode(ALEGRA_EMAIL . ':' . ALEGRA_TOKEN),
                'Accept: application/json',
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 8,
        ];
        if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        curl_setopt_array($ch, $opts);
        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);
        if ($raw === false) { self::$lastError = 'curl: ' . $cerr; return [0, null]; }
        return [$code, json_decode($raw, true)];
    }

    /**
     * Busca el contacto en Alegra por RUC; si no existe, lo crea.
     * @return int|null id del contacto en Alegra, o null si falla.
     */
    public static function buscarOCrearContacto(array $cliente): ?int
    {
        $ruc = preg_replace('/\D/', '', (string)($cliente['ruc'] ?? ''));
        if ($ruc === '') { self::$lastError = 'cliente sin RUC'; return null; }

        [$c, $r] = self::req('GET', '/contacts?identification=' . rawurlencode($ruc));
        if ($c === 200 && isset($r[0]['id'])) return (int)$r[0]['id'];

        // Crear
        $payload = array_filter([
            'name'           => $cliente['razon_social'] ?? '',
            'identification' => $ruc,
            'type'           => ['client'],
            'address'        => !empty($cliente['direccion']) ? ['address' => $cliente['direccion']] : null,
        ], fn($v) => $v !== null && $v !== '');
        // Perú: tipo de documento
        $payload['identificationObject'] = ['type' => 'RUC', 'number' => $ruc];

        [$c2, $r2] = self::req('POST', '/contacts', $payload);
        if (in_array($c2, [200, 201], true) && isset($r2['id'])) return (int)$r2['id'];

        self::$lastError = 'no se pudo crear contacto (HTTP ' . $c2 . '): ' . json_encode($r2, JSON_UNESCAPED_UNICODE);
        return null;
    }

    /**
     * Construye el payload de la factura.
     *
     * @param int    $contactId    id del contacto en Alegra
     * @param string $tipo         'total' | 'anticipo' | 'saldo'
     * @param float  $base         precio de la línea (sin IGV)
     * @param string $descripcion  descripción del ítem (normas / saldo)
     * @param string $detraccionModo 'auto' (INTERNAL_SALE) | 'cliente' (SALE_SUBJECT_TO_DEDUCTION) | 'ninguna'
     * @param bool   $generateStamp true = emite a SUNAT; false = borrador de prueba
     */
    public static function construirPayload(int $contactId, string $tipo, float $base, string $descripcion, string $detraccionModo, bool $generateStamp): array
    {
        $itemId = [
            'total'    => self::ITEM_TOTAL,
            'anticipo' => self::ITEM_ANTICIPO,
            'saldo'    => self::ITEM_SALDO,
        ][$tipo] ?? self::ITEM_TOTAL;

        $total = round($base * (1 + self::IGV_RATE), 2);
        $aplicaDetraccion = ($detraccionModo !== 'ninguna') && ($total > self::DETRACCION_MIN);

        $operationType = ($detraccionModo === 'cliente' && $aplicaDetraccion)
            ? 'SALE_SUBJECT_TO_DEDUCTION'
            : 'INTERNAL_SALE';

        $hoy = date('Y-m-d');
        $payload = [
            'date'           => $hoy,
            'dueDate'        => $hoy,
            'client'         => ['id' => $contactId],
            'numberTemplate' => ['id' => self::NUMBER_FACTURA],
            'operationType'  => $operationType,
            'paymentMethod'  => 'CASH',
            'items'          => [[
                'id'          => $itemId,
                'description' => $descripcion,
                'price'       => $base,
                'quantity'    => 1,
                'tax'         => [['id' => self::TAX_IGV]],
            ]],
            'stamp'          => ['generateStamp' => $generateStamp],
        ];
        // Detracción [012] explícita (estandariza; hoy usan a veces la genérica).
        // Alegra exige el monto (12% del total).
        if ($aplicaDetraccion) {
            $payload['retentions'] = [[
                'id'         => self::DETRACCION_012,
                'percentage' => 12,
                'amount'     => round($total * 0.12, 2),
            ]];
        }
        return $payload;
    }

    /** Crea la factura en Alegra. Devuelve [httpCode, respuesta]. */
    public static function crear(array $payload): array
    {
        return self::req('POST', '/invoices', $payload);
    }

    /** Elimina una factura (solo borradores de prueba). */
    public static function eliminar(int $alegraId): array
    {
        return self::req('DELETE', '/invoices/' . $alegraId);
    }

    /** Arma la descripción del ítem a partir de las normas de la cotización. */
    public static function descripcionNormas(array $normas, string $tipo, ?string $refAnticipo = null): string
    {
        $lista = array_values(array_filter(array_map('trim', $normas)));
        if ($tipo === 'saldo') {
            $ref = $refAnticipo ? " N° {$refAnticipo}" : '';
            return "Saldo correspondiente al servicio de asesoría y gestión para certificaciones ISO, según contrato, habiéndose emitido previamente factura por pago anticipado{$ref}";
        }
        if (count($lista) === 0) return 'Servicio de asesoría y gestión para certificaciones ISO';
        if (count($lista) === 1) return 'Incluye norma ISO ' . $lista[0];
        return 'Incluye normas ISO ' . implode(', ', $lista);
    }
}
