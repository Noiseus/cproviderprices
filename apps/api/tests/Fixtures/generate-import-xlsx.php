<?php

// Deterministic synthetic artifact generator. No customer data or workbook formulas.
$rows = [
    ['Product Text', 'Case Cost', 'Vendor Code', 'UOM', 'Size Label'],
    ['TOM BEEFSTEAK 25#', '23.00', 'B001', 'CS', '25#'],
    ['OIL CANOLA 6/1 GAL', '42.50', 'B002', 'CS', '6/1 GAL'],
    ['MOZZ WHOLE MILK 4/5 LB', '47.00', 'B003', 'CS', '4/5 LB'],
];
$sheet = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
foreach ($rows as $index => $cells) {
    $row = $index + 1;
    $sheet .= '<row r="'.$row.'">';
    foreach ($cells as $column => $value) {
        $sheet .= '<c r="'.chr(65 + $column).$row.'" t="inlineStr"><is><t>'.htmlspecialchars($value, ENT_XML1, 'UTF-8').'</t></is></c>';
    }
    $sheet .= '</row>';
}
$sheet .= '</sheetData></worksheet>';
$entries = [
    '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
    '_rels/.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
    'xl/workbook.xml' => '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Prices" sheetId="1" r:id="rId1"/></sheets></workbook>',
    'xl/_rels/workbook.xml.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
    'xl/worksheets/sheet1.xml' => $sheet,
];
$zip = new ZipArchive;
if ($zip->open(__DIR__.'/import-supplier-b.xlsx', ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException('Could not create synthetic fixture.');
}
foreach ($entries as $name => $xml) {
    $zip->addFromString($name, $xml);
    $zip->setMtimeName($name, 946684800);
    $zip->setCompressionName($name, ZipArchive::CM_STORE);
}
$zip->close();
