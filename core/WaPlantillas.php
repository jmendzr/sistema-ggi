<?php
/**
 * core/WaPlantillas.php — Estado real de las plantillas de WhatsApp.
 *
 * El sistema mantiene DOS realidades que pueden desincronizarse:
 *   1. whatsapp_templates: qué plantilla usa cada evento y con qué variables.
 *   2. Meta: si esa plantilla existe, en qué estado está y qué forma tiene.
 *
 * Cuando divergen, las notificaciones fallan en silencio. Este archivo
 * concentra el diagnóstico para que el desajuste sea visible, en vez de
 * descubrirse semanas después revisando por qué un cliente nunca recibió nada.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/WhatsAppService.php';

class WaPlantillas
{
    /** Estados de Meta que permiten enviar. */
    public const ENVIABLES = ['APPROVED'];

    /**
     * Cruza la configuración del sistema con Meta y devuelve el estado de cada
     * plantilla, más las que existen en Meta y nadie usa.
     *
     * @param array|null $meta Resultado de listarPlantillas(); si es null se
     *                         usa lo último sincronizado en la base.
     */
    public static function diagnostico(\PDO $pdo, ?array $meta = null): array
    {
        $idx = [];
        if ($meta !== null) {
            foreach ($meta as $t) {
                $idx[$t['name']] = self::resumirMeta($t);
            }
        }

        $filas = [];
        $q = $pdo->query(
            "SELECT event_code, template_name, language_code, param_keys, url_button_key,
                    activo, body_text, estado_meta, notas
               FROM whatsapp_templates ORDER BY event_code"
        );

        foreach ($q as $r) {
            $claves = json_decode($r['param_keys'] ?? '[]', true) ?: [];
            $m      = $idx[$r['template_name']] ?? null;

            // Sin sincronización fresca se usa el último estado conocido
            $estado = $m['status'] ?? ($r['estado_meta'] ?: null);
            $cuerpo = $m['body']   ?? ($r['body_text'] ?: '');

            $problemas = [];
            if ($meta !== null && !$m) {
                $problemas[] = ['grave', 'No existe en Meta'];
            } else {
                if ($estado === null)              $problemas[] = ['aviso', 'Sin sincronizar'];
                elseif ($estado === 'REJECTED')    $problemas[] = ['grave', 'Rechazada'
                                                        . (!empty($m['motivo']) ? ': ' . self::motivo($m['motivo']) : '')];
                elseif ($estado === 'PENDING')     $problemas[] = ['aviso', 'En revisión de Meta'];
                elseif ($estado === 'PAUSED')      $problemas[] = ['grave', 'Pausada por calidad'];
                elseif ($estado === 'DISABLED')    $problemas[] = ['grave', 'Deshabilitada por Meta'];

                if ($m && $m['lang'] !== $r['language_code']) {
                    $problemas[] = ['grave', 'Idioma distinto: sistema ' . $r['language_code'] . ', Meta ' . $m['lang']];
                }
                if ($m && $m['params'] !== count($claves)) {
                    $problemas[] = ['grave', 'Variables: el sistema envía ' . count($claves)
                                          . ' y la plantilla espera ' . $m['params']];
                }
                if ($m && $m['header'] === 'DOCUMENT' && $r['event_code'] !== 'factura_emitida') {
                    $problemas[] = ['aviso', 'Requiere adjuntar un documento'];
                }
            }

            // Una plantilla rota pero inactiva no dispara nada: es menos urgente
            $urgente = $r['activo'] && array_filter($problemas, fn($p) => $p[0] === 'grave');

            $filas[] = [
                'event_code'    => $r['event_code'],
                'template_name' => $r['template_name'],
                'idioma'        => $r['language_code'],
                'claves'        => $claves,
                'boton_url'     => $r['url_button_key'],
                'activo'        => (bool) $r['activo'],
                'cuerpo'        => $cuerpo,
                'estado'        => $estado,
                'categoria'     => $m['categoria'] ?? null,
                'calidad'       => $m['calidad']   ?? null,
                'problemas'     => $problemas,
                'urgente'       => (bool) $urgente,
                'notas'         => $r['notas'],
            ];
        }

        // Plantillas que existen en Meta y ningún evento usa
        $huerfanas = [];
        if ($meta !== null) {
            $usadas = array_column($filas, 'template_name');
            foreach ($idx as $nombre => $m) {
                if (!in_array($nombre, $usadas, true)) {
                    $huerfanas[] = ['nombre' => $nombre] + $m;
                }
            }
        }

        return ['filas' => $filas, 'huerfanas' => $huerfanas];
    }

    /** Extrae de la respuesta de Meta solo lo que el diagnóstico necesita. */
    private static function resumirMeta(array $t): array
    {
        $body = ''; $header = null; $botones = 0;
        foreach ($t['components'] ?? [] as $c) {
            if (($c['type'] ?? '') === 'BODY')    $body   = $c['text'] ?? '';
            if (($c['type'] ?? '') === 'HEADER')  $header = $c['format'] ?? 'TEXT';
            if (($c['type'] ?? '') === 'BUTTONS') $botones = count($c['buttons'] ?? []);
        }
        preg_match_all('/\{\{(\d+)\}\}/', $body, $m);

        return [
            'status'    => $t['status'] ?? null,
            'lang'      => $t['language'] ?? null,
            'categoria' => $t['category'] ?? null,
            'motivo'    => ($t['rejected_reason'] ?? 'NONE') !== 'NONE' ? $t['rejected_reason'] : null,
            'calidad'   => $t['quality_score']['score'] ?? null,
            'body'      => $body,
            'params'    => count(array_unique($m[1])),
            'header'    => $header,
            'botones'   => $botones,
        ];
    }

    /** Traduce el motivo de rechazo de Meta a algo accionable. */
    public static function motivo(string $codigo): string
    {
        return [
            'INCORRECT_CATEGORY'     => 'categoría equivocada (el texto es promocional, no utilitario)',
            'INVALID_FORMAT'         => 'formato inválido',
            'ABUSIVE_CONTENT'        => 'contenido no permitido',
            'PROMOTIONAL'            => 'contenido promocional en categoría utilitaria',
            'TAGGED_AS_PROMOTIONAL'  => 'marcada como promocional',
            'SCAM'                   => 'detectada como engañosa',
            'NONE'                   => '',
        ][$codigo] ?? strtolower(str_replace('_', ' ', $codigo));
    }

    /**
     * Valida un nombre de plantilla según las reglas de Meta.
     * Devuelve el error, o null si es válido.
     */
    public static function validarNombre(string $n): ?string
    {
        if ($n === '')                              return 'El nombre es obligatorio';
        if (strlen($n) > 512)                       return 'El nombre supera los 512 caracteres';
        if (!preg_match('/^[a-z0-9_]+$/', $n))      return 'Solo minúsculas, números y guion bajo';
        return null;
    }

    /**
     * Valida el cuerpo. Meta rechaza variables no correlativas, que empiecen
     * en un número distinto de 1, o un cuerpo que empiece/termine con variable.
     */
    public static function validarCuerpo(string $body): ?string
    {
        $body = trim($body);
        if ($body === '')            return 'El mensaje es obligatorio';
        if (mb_strlen($body) > 1024) return 'El mensaje supera los 1024 caracteres';

        preg_match_all('/\{\{(\d+)\}\}/', $body, $m);
        $nums = array_map('intval', $m[1]);

        if ($nums) {
            $unicos = array_values(array_unique($nums));
            sort($unicos);
            if ($unicos[0] !== 1) return 'Las variables deben empezar en {{1}}';
            foreach ($unicos as $i => $v) {
                if ($v !== $i + 1) return 'Las variables deben ser correlativas: falta {{' . ($i + 1) . '}}';
            }
            if (preg_match('/^\s*\{\{\d+\}\}/', $body)) return 'El mensaje no puede empezar con una variable';
            if (preg_match('/\{\{\d+\}\}\s*$/', $body)) return 'El mensaje no puede terminar con una variable';
        }

        return null;
    }

    /** Cuántas variables usa un cuerpo. */
    public static function contarParams(string $body): int
    {
        preg_match_all('/\{\{(\d+)\}\}/', $body, $m);
        return count(array_unique($m[1]));
    }
}
