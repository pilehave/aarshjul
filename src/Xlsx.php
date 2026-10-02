<?php
declare(strict_types=1);

/**
 * Minimal .xlsx-skriver (kræver kun PHP's zip-udvidelse). Én fane, fed overskriftsrække,
 * datoceller som rigtige Excel-datoer, frosset overskrift og autofilter.
 */
final class Xlsx
{
    /** @param array<int,array<int,string|int|float|DateTimeInterface|null>> $rows første række = overskrifter */
    public static function build(string $sheetName, array $rows, array $colWidths = []): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . self::x(mb_substr($sheetName, 0, 31)) . '" sheetId="1" r:id="rId1"/></sheets><definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">\'' . self::x(mb_substr($sheetName, 0, 31)) . '\'!$A$1:$' . self::col(count($rows[0] ?? [1]) - 1) . '$' . max(1, count($rows)) . '</definedName></definedNames></workbook>');
        // Stilarter: 0 = normal, 1 = fed overskrift med baggrund, 2 = dato dd-mm-åååå, 3 = ombrudt tekst
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="164" formatCode="dd-mm-yyyy"/></numFmts><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF1F2D48"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="4"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/><xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');

        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
        if ($colWidths) {
            $sheet .= '<cols>';
            foreach (array_values($colWidths) as $i => $w) {
                $sheet .= sprintf('<col min="%1$d" max="%1$d" width="%2$s" customWidth="1"/>', $i + 1, $w);
            }
            $sheet .= '</cols>';
        }
        $sheet .= '<sheetData>';
        foreach ($rows as $r => $row) {
            $sheet .= '<row r="' . ($r + 1) . '">';
            foreach (array_values($row) as $c => $v) {
                $ref = self::col($c) . ($r + 1);
                if ($v === null || $v === '') {
                    continue;
                }
                if ($r === 0) {
                    $sheet .= '<c r="' . $ref . '" s="1" t="inlineStr"><is><t>' . self::x((string)$v) . '</t></is></c>';
                } elseif ($v instanceof DateTimeInterface) {
                    $serial = intdiv((int)$v->setTime(0, 0)->format('U') + (int)$v->format('Z'), 86400) + 25569;
                    $sheet .= '<c r="' . $ref . '" s="2"><v>' . $serial . '</v></c>';
                } elseif (is_int($v) || is_float($v)) {
                    $sheet .= '<c r="' . $ref . '"><v>' . $v . '</v></c>';
                } else {
                    $sheet .= '<c r="' . $ref . '" s="3" t="inlineStr"><is><t xml:space="preserve">' . self::x((string)$v) . '</t></is></c>';
                }
            }
            $sheet .= '</row>';
        }
        $sheet .= '</sheetData><autoFilter ref="A1:' . self::col(count($rows[0] ?? [1]) - 1) . max(1, count($rows)) . '"/></worksheet>';
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();

        $data = file_get_contents($tmp);
        unlink($tmp);
        return $data;
    }

    private static function col(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26) . $s;
        }
        return $s;
    }

    private static function x(string $s): string
    {
        // Fjern tegn, der er ugyldige i XML
        $s = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $s);
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
