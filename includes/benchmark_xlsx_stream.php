<?php
/**
 * Escritor de .xlsx por flujo (streaming) para exportaciones grandes de Benchmark.
 *
 * Por qué existe: PhpSpreadsheet guarda cada celda en memoria (~1 KB por celda), así que 100,000 filas
 * piden gigas de RAM. Este escritor manda cada fila a un archivo temporal apenas se crea y al final
 * empaqueta el .xlsx con ZipArchive (ext-zip). La memoria queda casi constante sin importar cuántas filas haya.
 *
 * Alcance deliberadamente pequeño: una hoja, estilos (fuente, relleno, bordes, alineación, formato numérico),
 * combinar celdas, anchos de columna, panel fijo, autofiltro, impresión horizontal, pie de página y un logo PNG.
 * No usa librerías externas. Solo escribe texto (inlineStr), números y fórmulas.
 *
 * Uso:
 *   $x = new BenchmarkXlsxStream('Benchmark', [6, 22, ...], 6);   // título, anchos, filas fijas
 *   $s = $x->estilo(['b' => true, 'color' => '12306E', 'bg' => 'F4F6FB']);
 *   $x->fila([0 => ['Hola', $s], 1 => [12.5, $s]], 20);
 *   $x->enviar('archivo.xlsx');
 */
final class BenchmarkXlsxStream
{
    private string $titulo;
    /** @var float[] */
    private array $anchos;
    private int $filasFijas;
    private int $nCols;
    /** @var resource */
    private $hoja;
    private string $rutaHoja;
    private int $fila = 0;
    /** @var array<string,int> */
    private array $estilos = [];
    /** @var array<int,array> */
    private array $especEstilos = [];
    /** @var string[] */
    private array $combinadas = [];
    private ?string $autofiltro = null;
    private ?string $logo = null;
    private ?int $filaTitulosImpresion = null;
    private string $pieImpresion = '';

    public function __construct(string $titulo, array $anchos, int $filasFijas = 0)
    {
        $this->titulo = mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/u', ' ', $titulo), 0, 31);
        $this->anchos = array_values($anchos);
        $this->nCols = count($anchos);
        $this->filasFijas = $filasFijas;
        $this->rutaHoja = tempnam(sys_get_temp_dir(), 'bxs');
        $this->hoja = fopen($this->rutaHoja, 'wb');
        $this->estilo([]); // estilo 0 = predeterminado
        fwrite($this->hoja, $this->encabezadoHoja());
    }

    public function __destruct()
    {
        if (is_resource($this->hoja)) {
            fclose($this->hoja);
        }
        if (is_file($this->rutaHoja)) {
            @unlink($this->rutaHoja);
        }
    }

    /**
     * Registra un estilo y devuelve su id (los repetidos se reutilizan).
     * Claves: b, i, sz, color, bg, h (left|center|right), v (top|center|bottom), wrap, indent, fmt,
     *         bt/bb/bl/br = [estilo, color] para borde superior/inferior/izquierdo/derecho.
     */
    public function estilo(array $spec): int
    {
        ksort($spec);
        $clave = json_encode($spec);
        if (!isset($this->estilos[$clave])) {
            $this->estilos[$clave] = count($this->especEstilos);
            $this->especEstilos[] = $spec;
        }
        return $this->estilos[$clave];
    }

    public function combinar(string $rango): void
    {
        $this->combinadas[] = $rango;
    }

    public function autofiltro(string $rango): void
    {
        $this->autofiltro = $rango;
    }

    public function logo(string $rutaPng): void
    {
        if (is_file($rutaPng) && @getimagesize($rutaPng) !== false) {
            $this->logo = $rutaPng;
        }
    }

    public function repetirFila(int $fila): void
    {
        $this->filaTitulosImpresion = $fila;
    }

    public function pie(string $izquierda): void
    {
        $this->pieImpresion = $izquierda;
    }

    /** Número de la última fila escrita. */
    public function filaActual(): int
    {
        return $this->fila;
    }

    /**
     * Escribe una fila. $celdas: [indiceColumna(0..) => [valor, estilo]]. Valor: string, int|float, null,
     * o ['f' => 'SUM(A1:A3)'] para fórmula. $relleno: estilo para las columnas sin valor (bandas, celdas combinadas).
     */
    public function fila(array $celdas, ?float $alto = null, ?int $relleno = null): int
    {
        $this->fila++;
        $r = $this->fila;
        $xml = '<row r="' . $r . '"' . ($alto !== null ? ' ht="' . $alto . '" customHeight="1"' : '') . '>';
        for ($c = 0; $c < $this->nCols; $c++) {
            $ref = self::columna($c) . $r;
            if (!isset($celdas[$c])) {
                if ($relleno !== null) {
                    $xml .= '<c r="' . $ref . '" s="' . $relleno . '"/>';
                }
                continue;
            }
            [$v, $s] = $celdas[$c] + [1 => 0];
            $s = (int)$s;
            if (is_array($v) && isset($v['f'])) {
                $xml .= '<c r="' . $ref . '" s="' . $s . '"><f>' . self::xml((string)$v['f']) . '</f></c>';
            } elseif (is_int($v) || is_float($v)) {
                $xml .= '<c r="' . $ref . '" s="' . $s . '"><v>' . (is_float($v) ? rtrim(rtrim(sprintf('%.10F', $v), '0'), '.') : $v) . '</v></c>';
            } elseif ($v === null || $v === '') {
                $xml .= '<c r="' . $ref . '" s="' . $s . '"/>';
            } else {
                $xml .= '<c r="' . $ref . '" s="' . $s . '" t="inlineStr"><is><t xml:space="preserve">' . self::xml((string)$v) . '</t></is></c>';
            }
        }
        fwrite($this->hoja, $xml . '</row>');
        return $r;
    }

    /** Fila vacía (separador) con alto fijo. */
    public function filaVacia(float $alto): int
    {
        $this->fila++;
        fwrite($this->hoja, '<row r="' . $this->fila . '" ht="' . $alto . '" customHeight="1"/>');
        return $this->fila;
    }

    /** Arma el .xlsx y lo manda al navegador. */
    public function enviar(string $nombreArchivo): void
    {
        $destino = $this->construir();
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
        header('Content-Length: ' . filesize($destino));
        header('Cache-Control: no-store');
        readfile($destino);
        @unlink($destino);
    }

    /** Arma el .xlsx en un archivo temporal y devuelve su ruta (también sirve para pruebas). */
    public function construir(): string
    {
        fwrite($this->hoja, '</sheetData>' . $this->pieHoja());
        fclose($this->hoja);

        $destino = tempnam(sys_get_temp_dir(), 'bxx');
        $zip = new ZipArchive();
        if ($zip->open($destino, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se pudo crear el archivo temporal del Excel.');
        }
        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . ($this->logo ? '<Default Extension="png" ContentType="image/png"/>' : '')
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . ($this->logo ? '<Override PartName="/xl/drawings/drawing1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>' : '')
            . '</Types>';
        $zip->addFromString('[Content_Types].xml', $ct);
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');

        $nombresDef = '';
        if ($this->filaTitulosImpresion !== null) {
            $nombresDef = '<definedNames><definedName name="_xlnm.Print_Titles" localSheetId="0">\''
                . self::xml($this->titulo) . '\'!$' . $this->filaTitulosImpresion . ':$' . $this->filaTitulosImpresion . '</definedName></definedNames>';
        }
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . self::xml($this->titulo) . '" sheetId="1" r:id="rId1"/></sheets>'
            . $nombresDef . '<calcPr calcId="191029" fullCalcOnLoad="1"/></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/styles.xml', $this->xmlEstilos());
        $zip->addFile($this->rutaHoja, 'xl/worksheets/sheet1.xml');

        if ($this->logo) {
            [$w, $h] = getimagesize($this->logo);
            $alto = 54; // px
            $ancho = (int)round($w * $alto / max(1, $h));
            $cx = $ancho * 9525;
            $cy = $alto * 9525;
            $zip->addFromString('xl/worksheets/_rels/sheet1.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing1.xml"/>'
                . '</Relationships>');
            $zip->addFromString('xl/drawings/drawing1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
                . '<xdr:oneCellAnchor><xdr:from><xdr:col>0</xdr:col><xdr:colOff>57150</xdr:colOff><xdr:row>0</xdr:row><xdr:rowOff>38100</xdr:rowOff></xdr:from>'
                . '<xdr:ext cx="' . $cx . '" cy="' . $cy . '"/>'
                . '<xdr:pic><xdr:nvPicPr><xdr:cNvPr id="2" name="Logo"/><xdr:cNvPicPr><a:picLocks noChangeAspect="1"/></xdr:cNvPicPr></xdr:nvPicPr>'
                . '<xdr:blipFill><a:blip xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:embed="rId1"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill>'
                . '<xdr:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr></xdr:pic>'
                . '<xdr:clientData/></xdr:oneCellAnchor></xdr:wsDr>');
            $zip->addFromString('xl/drawings/_rels/drawing1.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/logo.png"/>'
                . '</Relationships>');
            $zip->addFile($this->logo, 'xl/media/logo.png');
        }
        if (!$zip->close()) {
            throw new RuntimeException('No se pudo empaquetar el Excel.');
        }
        return $destino;
    }

    // ── XML de la hoja ──────────────────────────────────────────────────────
    private function encabezadoHoja(): string
    {
        $pane = '';
        if ($this->filasFijas > 0) {
            $f = $this->filasFijas;
            $pane = '<pane ySplit="' . $f . '" topLeftCell="A' . ($f + 1) . '" activePane="bottomLeft" state="frozen"/>'
                . '<selection pane="bottomLeft" activeCell="A' . ($f + 1) . '" sqref="A' . ($f + 1) . '"/>';
        }
        $cols = '<cols>';
        foreach ($this->anchos as $i => $w) {
            $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
        }
        $cols .= '</cols>';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'
            . '<sheetViews><sheetView showGridLines="0" workbookViewId="0">' . $pane . '</sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="20" customHeight="1"/>'
            . $cols . '<sheetData>';
    }

    private function pieHoja(): string
    {
        $x = '';
        if ($this->autofiltro !== null) {
            $x .= '<autoFilter ref="' . $this->autofiltro . '"/>';
        }
        if ($this->combinadas) {
            $x .= '<mergeCells count="' . count($this->combinadas) . '">';
            foreach ($this->combinadas as $m) {
                $x .= '<mergeCell ref="' . $m . '"/>';
            }
            $x .= '</mergeCells>';
        }
        $x .= '<printOptions horizontalCentered="1"/>'
            . '<pageMargins left="0.4" right="0.4" top="0.5" bottom="0.6" header="0.3" footer="0.3"/>'
            . '<pageSetup paperSize="1" orientation="landscape" fitToWidth="1" fitToHeight="0"/>';
        if ($this->pieImpresion !== '') {
            $x .= '<headerFooter><oddFooter>' . self::xml('&L&8' . $this->pieImpresion . '&R&8Página &P de &N') . '</oddFooter></headerFooter>';
        }
        if ($this->logo) {
            $x .= '<drawing r:id="rId1"/>';
        }
        return $x . '</worksheet>';
    }

    // ── XML de estilos ──────────────────────────────────────────────────────
    private function xmlEstilos(): string
    {
        $fuentes = [];
        $rellenos = [];
        $bordes = [];
        $formatos = [];
        $registrar = function (array &$lista, string $xml): int {
            $i = array_search($xml, $lista, true);
            if ($i === false) {
                $lista[] = $xml;
                $i = count($lista) - 1;
            }
            return $i;
        };
        // Valores por defecto (índice 0 de cada lista)
        $registrar($fuentes, '<font><sz val="11"/><name val="Calibri"/><family val="2"/></font>');
        $registrar($rellenos, '<fill><patternFill patternType="none"/></fill>');
        $registrar($rellenos, '<fill><patternFill patternType="gray125"/></fill>');
        $registrar($bordes, '<border><left/><right/><top/><bottom/><diagonal/></border>');

        $xfs = '';
        foreach ($this->especEstilos as $e) {
            $f = '<font>' . (!empty($e['b']) ? '<b/>' : '') . (!empty($e['i']) ? '<i/>' : '')
                . '<sz val="' . ($e['sz'] ?? 11) . '"/>'
                . (isset($e['color']) ? '<color rgb="FF' . strtoupper($e['color']) . '"/>' : '')
                . '<name val="Calibri"/><family val="2"/></font>';
            $idF = $registrar($fuentes, $f);

            $idR = 0;
            if (isset($e['bg'])) {
                $idR = $registrar($rellenos, '<fill><patternFill patternType="solid"><fgColor rgb="FF' . strtoupper($e['bg']) . '"/><bgColor indexed="64"/></patternFill></fill>');
            }

            $lado = function (string $tag, $def): string {
                return $def ? '<' . $tag . ' style="' . $def[0] . '"><color rgb="FF' . strtoupper($def[1]) . '"/></' . $tag . '>' : '<' . $tag . '/>';
            };
            $idB = $registrar($bordes, '<border>' . $lado('left', $e['bl'] ?? null) . $lado('right', $e['br'] ?? null)
                . $lado('top', $e['bt'] ?? null) . $lado('bottom', $e['bb'] ?? null) . '<diagonal/></border>');

            $idN = 0;
            if (isset($e['fmt'])) {
                $idN = 164 + $registrar($formatos, '<numFmt numFmtId="%ID%" formatCode="' . self::xml($e['fmt']) . '"/>');
            }

            $alinea = '';
            if (isset($e['h']) || isset($e['v']) || !empty($e['wrap']) || !empty($e['indent'])) {
                $alinea = '<alignment' . (isset($e['h']) ? ' horizontal="' . $e['h'] . '"' : '')
                    . (isset($e['v']) ? ' vertical="' . $e['v'] . '"' : '')
                    . (!empty($e['wrap']) ? ' wrapText="1"' : '')
                    . (!empty($e['indent']) ? ' indent="' . (int)$e['indent'] . '"' : '') . '/>';
            }
            $xfs .= '<xf numFmtId="' . $idN . '" fontId="' . $idF . '" fillId="' . $idR . '" borderId="' . $idB . '" xfId="0"'
                . ($idN ? ' applyNumberFormat="1"' : '') . ' applyFont="1"' . ($idR ? ' applyFill="1"' : '')
                . ' applyBorder="1"' . ($alinea ? ' applyAlignment="1">' . $alinea . '</xf>' : '/>');
        }

        $nf = '';
        if ($formatos) {
            $nf = '<numFmts count="' . count($formatos) . '">';
            foreach ($formatos as $i => $xml) {
                $nf .= str_replace('%ID%', (string)(164 + $i), $xml);
            }
            $nf .= '</numFmts>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' . $nf
            . '<fonts count="' . count($fuentes) . '">' . implode('', $fuentes) . '</fonts>'
            . '<fills count="' . count($rellenos) . '">' . implode('', $rellenos) . '</fills>'
            . '<borders count="' . count($bordes) . '">' . implode('', $bordes) . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="' . count($this->especEstilos) . '">' . $xfs . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    // ── Utilidades ──────────────────────────────────────────────────────────
    public static function columna(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26) . $s;
        }
        return $s;
    }

    private static function xml(string $t): string
    {
        $t = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $t) ?? '';
        return htmlspecialchars($t, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
