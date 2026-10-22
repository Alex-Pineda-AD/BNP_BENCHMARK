<?php
// Minimal OOXML export for the application's PHP 7.4 runtime. Inline strings
// preserve references/accounts and prevent user text from becoming formulas.
function conciliacionXml(string $texto): string {
    $texto=preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u','',$texto);
    return htmlspecialchars($texto,ENT_XML1|ENT_QUOTES,'UTF-8');
}
function conciliacionColumna(int $n): string {
    $s=''; while($n>0) { $n--; $s=chr(65+$n%26).$s; $n=intdiv($n,26); } return $s;
}
function conciliacionExcel(array $reporte): string {
    if(!class_exists('ZipArchive')) throw new RuntimeException('La extensión ZIP es necesaria para exportar Excel.');
    $ruta=tempnam(sys_get_temp_dir(),'conciliacion_'); if($ruta===false) throw new RuntimeException('No se pudo preparar el Excel.');
    $zip=new ZipArchive();
    try {
        if($zip->open($ruta,ZipArchive::OVERWRITE)!==true) throw new RuntimeException('No se pudo crear el Excel.');
        $ns='http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $zip->addFromString('[Content_Types].xml','<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('_rels/.rels','<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml','<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="'.$ns.'" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Resumen del cierre" sheetId="1" r:id="rId1"/><sheet name="Movimientos conciliados" sheetId="2" r:id="rId2"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels','<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml','<?xml version="1.0"?><styleSheet xmlns="'.$ns.'"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF41464D"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"><alignment vertical="top" wrapText="1"/></xf><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFill="1" applyFont="1"><alignment wrapText="1"/></xf><xf numFmtId="4" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
        $hojas=[array_merge([['Dato','Valor']],$reporte['resumen']),array_merge([$reporte['cabeceras']],$reporte['filas'])];
        foreach($hojas as $indice=>$filas) {
            $columnas=count($filas[0]); $fin=conciliacionColumna($columnas).count($filas);
            $xml='<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="'.$ns.'"><dimension ref="A1:'.$fin.'"/><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>';
            for($col=1;$col<=$columnas;$col++) $xml.='<col min="'.$col.'" max="'.$col.'" width="'.($indice===0?($col===1?28:48):(in_array($col,[5,17,18],true)?45:22)).'" customWidth="1"/>';
            $xml.='</cols><sheetData>';
            foreach($filas as $r=>$fila) {
                $numero=$r+1; $xml.='<row r="'.$numero.'"'.($r===0?' ht="32" customHeight="1"':'').'>';
                foreach($fila as $k=>$valor) {
                    $valor=(string)($valor??''); $celda=conciliacionColumna($k+1).$numero;
                    $numerico=$r>0 && ($indice===0?($k===1 && in_array($fila[0],['Saldo inicial','Total débitos','Total créditos','Saldo final','Diferencia original','Total ajustes'],true)):in_array($k,[5,6,7,12],true)) && preg_match('/^-?\d+(?:\.\d+)?$/D',$valor);
                    $xml.=$numerico?'<c r="'.$celda.'" s="2"><v>'.$valor.'</v></c>':'<c r="'.$celda.'" s="'.($r===0?1:0).'" t="inlineStr"><is><t xml:space="preserve">'.conciliacionXml($valor).'</t></is></c>';
                }
                $xml.='</row>';
            }
            $xml.='</sheetData>'.($indice===1?'<autoFilter ref="A1:'.$fin.'"/>':'').'<pageMargins left="0.3" right="0.3" top="0.5" bottom="0.5" header="0.2" footer="0.2"/><pageSetup orientation="landscape" paperSize="9" fitToWidth="1" fitToHeight="0"/></worksheet>';
            $zip->addFromString('xl/worksheets/sheet'.($indice+1).'.xml',$xml);
        }
        if(!$zip->close()) throw new RuntimeException('No se pudo finalizar el Excel.');
        return $ruta;
    } catch(Throwable $e) { if($zip->status===ZipArchive::ER_OK) @$zip->close(); if(is_file($ruta)) unlink($ruta); throw $e; }
}
