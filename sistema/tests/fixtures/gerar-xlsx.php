<?php
// Gera um .xlsx mínimo (primeira folha) a partir de linhas JSON — para testar a importação de Excel.
// Uso: php gerar-xlsx.php saida.xlsx '[["nome","email"],["A","a@b.ao"]]'
$rows = json_decode($argv[2], true);
$ss = [];
$idx = function ($s) use (&$ss) { $k = array_search($s, $ss, true); if ($k === false) { $ss[] = $s; $k = count($ss) - 1; } return $k; };
$sheet = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
foreach ($rows as $r => $row) {
    $sheet .= '<row r="' . ($r + 1) . '">';
    foreach ($row as $c => $v) {
        $ref = chr(65 + $c) . ($r + 1);
        if (is_int($v) || is_float($v)) $sheet .= "<c r=\"$ref\"><v>$v</v></c>";
        else $sheet .= "<c r=\"$ref\" t=\"s\"><v>" . $idx((string)$v) . '</v></c>';
    }
    $sheet .= '</row>';
}
$sheet .= '</sheetData></worksheet>';
$sst = '<?xml version="1.0" encoding="UTF-8"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($ss) . '" uniqueCount="' . count($ss) . '">';
foreach ($ss as $s) $sst .= '<si><t>' . htmlspecialchars($s, ENT_XML1) . '</t></si>';
$sst .= '</sst>';
$z = new ZipArchive();
$z->open($argv[1], ZipArchive::CREATE | ZipArchive::OVERWRITE);
$z->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/></Types>');
$z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
$z->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Folha1" sheetId="1" r:id="rId1"/></sheets></workbook>');
$z->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/></Relationships>');
$z->addFromString('xl/worksheets/sheet1.xml', $sheet);
$z->addFromString('xl/sharedStrings.xml', $sst);
$z->close();
