<?php
/**
 * core/XlsxTemplateFiller.php
 * Rellena una plantilla .xlsx inyectando filas de datos en una hoja, sin librerías
 * externas. Conserva TODO lo demás del archivo (hojas ocultas, desplegables /
 * dataValidations, estilos, márgenes), reemplazando solo el bloque <sheetData> de
 * la hoja objetivo: mantiene las filas de encabezado y escribe las filas nuevas.
 *
 * Valores: int/float → celda numérica; string → inlineStr (preserva DNI/celular con
 * ceros y evita notación científica). Cadena vacía → celda omitida.
 */
class XlsxTemplateFiller
{
    /**
     * @param string $templatePath  Ruta a la plantilla .xlsx
     * @param string $sheetFile      Nombre del archivo de hoja (ej. "sheet5.xml")
     * @param int    $keepHeaderUpto Nº de la última fila de encabezado a conservar
     * @param int    $firstDataRow   Nº de fila donde arrancan los datos
     * @param array  $rows           Lista de filas; cada fila = [colLetra => valor]
     * @return string|null  Ruta de un archivo temporal .xlsx, o null si falla
     */
    public static function fill(string $templatePath, string $sheetFile, int $keepHeaderUpto, int $firstDataRow, array $rows): ?string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ggixlsx') . '.xlsx';
        if (!@copy($templatePath, $tmp)) return null;

        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) { @unlink($tmp); return null; }

        $entry = "xl/worksheets/$sheetFile";
        $xml   = $zip->getFromName($entry);
        if ($xml === false) { $zip->close(); @unlink($tmp); return null; }

        // Extraer el bloque <sheetData ...> ... </sheetData>
        if (!preg_match('/(<sheetData\b[^>]*>)(.*)(<\/sheetData>)/s', $xml, $m)) {
            $zip->close(); @unlink($tmp); return null;
        }
        $open  = $m[1];
        $inner = $m[2];

        // Conservar filas de encabezado (r <= keepHeaderUpto), verbatim
        $header = '';
        if (preg_match_all('/<row\b[^>]*?(?:\/>|>.*?<\/row>)/s', $inner, $rm)) {
            foreach ($rm[0] as $rowXml) {
                if (preg_match('/\br="(\d+)"/', $rowXml, $rr) && (int)$rr[1] <= $keepHeaderUpto) {
                    $header .= $rowXml;
                }
            }
        }

        // Construir filas de datos
        $data = '';
        $rn = $firstDataRow;
        foreach ($rows as $row) {
            // Ordenar celdas por índice de columna
            $cells = [];
            foreach ($row as $col => $val) {
                if ($val === '' || $val === null) continue;
                $cells[self::colIndex($col)] = self::cellXml($col . $rn, $val);
            }
            ksort($cells);
            $data .= '<row r="' . $rn . '">' . implode('', $cells) . '</row>';
            $rn++;
        }

        $newSheetData = $open . $header . $data . '</sheetData>';
        $newXml = preg_replace('/<sheetData\b[^>]*>.*<\/sheetData>/s', $newSheetData, $xml, 1);

        $zip->addFromString($entry, $newXml);
        $zip->close();
        return $tmp;
    }

    private static function cellXml(string $ref, $val): string
    {
        if (is_int($val) || is_float($val)) {
            return '<c r="' . $ref . '"><v>' . self::num($val) . '</v></c>';
        }
        $s = htmlspecialchars((string)$val, ENT_QUOTES | ENT_XML1, 'UTF-8');
        return '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . $s . '</t></is></c>';
    }

    private static function num($val): string
    {
        if (is_int($val)) return (string)$val;
        // float: sin ceros sobrantes, punto decimal
        $s = rtrim(rtrim(sprintf('%.4f', $val), '0'), '.');
        return $s === '' ? '0' : $s;
    }

    private static function colIndex(string $letters): int
    {
        $letters = strtoupper($letters);
        $n = 0;
        for ($i = 0, $len = strlen($letters); $i < $len; $i++) {
            $n = $n * 26 + (ord($letters[$i]) - 64);
        }
        return $n;
    }
}
