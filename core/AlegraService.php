<?php
/**
 * core/AlegraService.php — Facturación electrónica SUNAT vía Alegra (Perú).
 *
 * Emite facturas de venta directamente a SUNAT tras el pago, reemplazando el
 * proceso manual (crear en Alegra + subir PDF). Credenciales en .env
 * (ALEGRA_EMAIL / ALEGRA_TOKEN). Basic Auth = base64(email:token).
 *
 * IDs verificados contra la cuenta real (GET /items, /retentions):
 *   Ítems:   18 = "Servicios…" (factura total) · 14 = "Anticipo…" · 16 = "Saldo…"
 *   IGV:     tax id 1 (18%)
 *   Detracción [012] Intermediación laboral y tercerización 12% = retention id 17
 *   Numeración factura FA01 = numberTemplate id 13
 */

require_once __DIR__ . '/../config/config.php';

class AlegraService
{
    // ── Catálogo (IDs de la cuenta Alegra de GGI) ──
    const ITEM_TOTAL     = 18;   // factura por el total (una sola factura). Antes era 15.
    const ITEM_ANTICIPO  = 14;   // factura de anticipo (pago inicial, flujo de 2 facturas)
    const ITEM_SALDO     = 16;   // factura de saldo (pago final, flujo de 2 facturas)
    const TAX_IGV        = 1;    // IGV 18%
    const DETRACCION_012 = 17;   // [012] Intermediación laboral y tercerización 12%
    const NUMBER_FACTURA = 13;   // Serie FA01
    const NUMBER_NOTA_CR = 14;   // Serie F100 — nota de crédito electrónica a factura
    const IGV_RATE       = 0.18;
    const DETRACCION_MIN = 700;  // umbral SUNAT: detracción solo si total > S/700

    /**
     * Tipos de nota de crédito SUNAT (catálogo 09). El código es el valor exacto
     * que espera el campo `type` de Alegra; el texto es la etiqueta para la UI.
     * Ordenados por uso real de GGI (anular una factura mal emitida es lo común).
     */
    const NOTA_CR_TIPOS = [
        'VOID_OPERATION'                     => 'Anulación de la operación',
        'CORRECTION_BY_ERROR_ON_DESCRIPTION' => 'Corrección por error en la descripción',
        'VOID_OPERATION_BY_ERROR_IN_RUC'     => 'Anulación por error en el RUC',
        'TOTAL_DEVOLUTION'                   => 'Devolución total',
        'DECREASE_IN_VALUE'                  => 'Disminución en el valor',
        'GLOBAL_DISCOUNT'                    => 'Descuento global',
        'OTHER_CONCEPTS'                     => 'Otros conceptos',
    ];

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

    /** Busca el contacto en Alegra por RUC (solo lectura). null si no existe. */
    public static function buscarContacto(string $ruc): ?int
    {
        $ruc = preg_replace('/\D/', '', $ruc);
        if ($ruc === '') return null;
        [$c, $r] = self::req('GET', '/contacts?identification=' . rawurlencode($ruc));
        return ($c === 200 && isset($r[0]['id'])) ? (int)$r[0]['id'] : null;
    }

    /** Facturas recientes de un contacto en Alegra (para avisar de duplicados). */
    public static function facturasDeContacto(int $contactId, int $limit = 8): array
    {
        [$c, $r] = self::req('GET', '/invoices?client_id=' . $contactId . '&start=0&limit=' . $limit . '&order_direction=DESC&order_field=id');
        if ($c !== 200 || !is_array($r)) return [];
        $out = [];
        foreach ($r as $f) {
            $out[] = [
                'numero' => $f['numberTemplate']['fullNumber'] ?? ('#' . ($f['id'] ?? '')),
                'total'  => (float)($f['total'] ?? 0),
                'date'   => $f['date'] ?? '',
                'status' => $f['status'] ?? '',
                'item'   => $f['items'][0]['name'] ?? '',
            ];
        }
        return $out;
    }

    /**
     * Busca el contacto en Alegra por RUC; si no existe, lo crea.
     * @return int|null id del contacto en Alegra, o null si falla.
     */
    public static function buscarOCrearContacto(array $cliente): ?int
    {
        $ruc = preg_replace('/\D/', '', (string)($cliente['ruc'] ?? ''));
        if ($ruc === '') { self::$lastError = 'cliente sin RUC'; return null; }

        if ($id = self::buscarContacto($ruc)) return $id;

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
            // "Operación sujeta a detracción" exige la cuenta de detracción (Banco de la Nación).
            if ($operationType === 'SALE_SUBJECT_TO_DEDUCTION' && defined('ALEGRA_DETRACCION_CUENTA') && ALEGRA_DETRACCION_CUENTA !== '') {
                $payload['accountWithholding'] = ALEGRA_DETRACCION_CUENTA;
            }
        }
        return $payload;
    }

    /** Crea la factura en Alegra. Devuelve [httpCode, respuesta]. */
    public static function crear(array $payload): array
    {
        return self::req('POST', '/invoices', $payload);
    }

    /**
     * Clasifica el estado de timbrado SUNAT a partir del objeto `stamp` de Alegra.
     * SUNAT es asíncrono: al emitir suele quedar STAMPED_AND_WAITING_RESPONSE y
     * minutos después pasa a STAMPED_AND_ACCEPTED (o *_REJECTED). El PDF útil (y el
     * envío al cliente) solo debe hacerse cuando ya está ACEPTADO.
     *
     * @return string 'accepted' | 'waiting' | 'rejected' | 'unknown'
     */
    public static function clasificarTimbrado(?array $stamp): string
    {
        if (!$stamp) return 'unknown';
        $es = strtoupper((string)($stamp['emissionStatus'] ?? ''));
        $ls = mb_strtolower((string)($stamp['legalStatus'] ?? ''));
        if (strpos($es, 'REJECTED') !== false || strpos($ls, 'rechaz') !== false) return 'rejected';
        if (strpos($es, 'ACCEPTED') !== false || strpos($ls, 'acept')  !== false) return 'accepted';
        if (strpos($es, 'WAITING')  !== false || strpos($es, 'PROCESS') !== false || strpos($es, 'STAMPED') !== false) return 'waiting';
        return 'unknown';
    }

    /** Relee la factura en Alegra y devuelve su estado de timbrado (para el re-chequeo). */
    public static function estadoTimbradoDe(int $alegraId): array
    {
        [$c, $r] = self::req('GET', '/invoices/' . $alegraId . '?fields=stamp,numberTemplate,status');
        if ($c !== 200) return ['clase'=>'unknown', 'emissionStatus'=>null, 'resp'=>$r];
        $stamp = $r['stamp'] ?? null;
        return [
            'clase'          => self::clasificarTimbrado($stamp),
            'emissionStatus' => $stamp['emissionStatus'] ?? null,
            'legalStatus'    => $stamp['legalStatus'] ?? null,
            'numero'         => $r['numberTemplate']['fullNumber'] ?? null,
            'resp'           => $stamp,
        ];
    }

    /** Elimina una factura (solo borradores de prueba). */
    public static function eliminar(int $alegraId): array
    {
        return self::req('DELETE', '/invoices/' . $alegraId);
    }

    /** URL del PDF de una factura (GET /invoices/{id}?fields=pdf → campo 'pdf'). */
    public static function pdfUrl(int $alegraId): ?string
    {
        [$c, $r] = self::req('GET', '/invoices/' . $alegraId . '?fields=pdf');
        return ($c === 200 && !empty($r['pdf'])) ? (string)$r['pdf'] : null;
    }

    /** URL del PDF de una nota de crédito (GET /credit-notes/{id}?fields=pdf). */
    public static function pdfUrlNota(int $notaId): ?string
    {
        [$c, $r] = self::req('GET', '/credit-notes/' . $notaId . '?fields=pdf');
        return ($c === 200 && !empty($r['pdf'])) ? (string)$r['pdf'] : null;
    }

    /**
     * Descarga un PDF (factura o nota de crédito) desde su URL de Alegra y lo
     * guarda en la carpeta de facturas del cliente. Devuelve [rutaRelativa, nombre] o null.
     */
    private static function guardarPdfDesde(?string $url, int $clienteId, string $numero, string $etiqueta): ?array
    {
        if (!$url) { self::$lastError = 'sin URL de PDF'; return null; }

        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_FOLLOWLOCATION => true]);
        $bin  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($bin === false || $code !== 200 || substr((string)$bin, 0, 4) !== '%PDF') {
            self::$lastError = 'descarga PDF falló (HTTP ' . $code . ')';
            return null;
        }

        $dir = '/home/groupglo/domains/sistema.groupglobaliso.com/public_html/documentaciones_clientes/facturas/' . $clienteId . '/';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) { self::$lastError = 'no se pudo crear el directorio'; return null; }
        $safe     = preg_replace('/[^A-Za-z0-9\-_]/', '_', $numero !== '' ? $numero : ($etiqueta . '_x'));
        $filename = $safe . '_' . date('Ymd_His') . '.pdf';
        if (@file_put_contents($dir . $filename, $bin) === false) { self::$lastError = 'no se pudo guardar el PDF'; return null; }

        return ['documentaciones_clientes/facturas/' . $clienteId . '/' . $filename, ($numero !== '' ? $numero : $etiqueta) . '.pdf'];
    }

    /**
     * Descarga el PDF de la factura y lo guarda junto a las facturas del cliente
     * (misma convención que la carga manual). Devuelve [rutaRelativa, nombre] o null.
     */
    public static function descargarPdf(int $alegraId, int $clienteId, string $numero): ?array
    {
        return self::guardarPdfDesde(self::pdfUrl($alegraId), $clienteId, $numero, 'Factura');
    }

    /** Descarga el PDF de la nota de crédito y lo guarda igual que las facturas. */
    public static function descargarPdfNota(int $notaId, int $clienteId, string $numero): ?array
    {
        return self::guardarPdfDesde(self::pdfUrlNota($notaId), $clienteId, $numero, 'NotaCredito');
    }

    /** Trae la factura de Alegra (ítems, total, saldo, cliente) para armar la nota de crédito. */
    public static function facturaAlegra(int $alegraId): ?array
    {
        [$c, $r] = self::req('GET', '/invoices/' . $alegraId);
        if ($c !== 200 || empty($r['id'])) { self::$lastError = 'factura no hallada en Alegra (HTTP ' . $c . ')'; return null; }
        return $r;
    }

    /**
     * Construye el payload de una nota de crédito ELECTRÓNICA a una factura.
     * Anula/rectifica el total de la factura copiando sus ítems.
     *
     * @param array  $factura       respuesta de facturaAlegra()
     * @param string $tipo          código SUNAT catálogo 09 (clave de NOTA_CR_TIPOS)
     * @param string $causa         motivo de emisión (texto libre, obligatorio)
     * @param bool   $generateStamp true = emite a SUNAT; false = borrador de prueba
     */
    public static function construirNotaCredito(array $factura, string $tipo, string $causa, bool $generateStamp): array
    {
        // Copiar ítems de la factura (id, descripción, precio, cantidad, IGV).
        $items = [];
        foreach (($factura['items'] ?? []) as $it) {
            $items[] = [
                'id'          => $it['id'],
                'description' => $it['description'] ?? ($it['name'] ?? ''),
                'price'       => (float)$it['price'],
                'quantity'    => (float)($it['quantity'] ?? 1),
                'tax'         => [['id' => self::TAX_IGV]],
            ];
        }
        return [
            'date'           => date('Y-m-d'),
            'client'         => ['id' => (int)($factura['client']['id'] ?? 0)],
            'numberTemplate' => ['id' => self::NUMBER_NOTA_CR],
            'type'           => $tipo,
            'cause'          => $causa,
            'items'          => $items,
            'invoices'       => [[
                'id'     => (int)$factura['id'],
                'amount' => (float)$factura['total'],
            ]],
            'stamp'          => ['generateStamp' => $generateStamp],
        ];
    }

    /** Crea la nota de crédito en Alegra. Devuelve [httpCode, respuesta]. */
    public static function crearNotaCredito(array $payload): array
    {
        return self::req('POST', '/credit-notes', $payload);
    }

    /** Elimina una nota de crédito (solo borradores de prueba). */
    public static function eliminarNotaCredito(int $notaId): array
    {
        return self::req('DELETE', '/credit-notes/' . $notaId);
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
        // Si TODAS son "ISO XXXX" → "Incluye norma(s) ISO 9001, 14001, ..." (ISO una vez).
        $nums = []; $todasISO = true;
        foreach ($lista as $c) {
            if (preg_match('/^ISO\s+(.+)$/i', $c, $m)) $nums[] = trim($m[1]);
            else { $todasISO = false; break; }
        }
        if ($todasISO) {
            return (count($nums) === 1 ? 'Incluye norma ISO ' : 'Incluye normas ISO ') . implode(', ', $nums);
        }
        // Mezcla o no-ISO (SA8000, BPL…): usar el código completo.
        return (count($lista) === 1 ? 'Incluye norma ' : 'Incluye normas ') . implode(', ', $lista);
    }
}
