<?php
/**
 * Легкий та швидкий парсер файлів Excel (.xlsx) для Hestia CP / PHP 7.4+
 * Працює через вбудовані ZipArchive та SimpleXML без сторонніх бібліотек (composer).
 */

class XLSXParser {
    /**
     * Читання всіх аркушів із файлу .xlsx
     * 
     * @param string $filePath Шлях до файлу .xlsx
     * @return array [ 'sheet_name' => [ [row1_col1, row1_col2...], ... ] ]
     */
    public static function parse(string $filePath): array {
        if (!file_exists($filePath)) {
            throw new Exception("Файл не знайдено: {$filePath}");
        }

        if (!class_exists('ZipArchive')) {
            throw new Exception("Розширення PHP ZipArchive не встановлено на сервері.");
        }

        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new Exception("Не вдалося відкрити файл як ZIP-архів (некоректний XLSX).");
        }

        // 1. Отримуємо список спільних рядків (sharedStrings.xml), якщо вони є
        $sharedStrings = [];
        $sharedXmlContent = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedXmlContent !== false) {
            $sxml = @simplexml_load_string($sharedXmlContent);
            if ($sxml) {
                foreach ($sxml->si as $si) {
                    if (isset($si->t)) {
                        $sharedStrings[] = (string)$si->t;
                    } elseif (isset($si->r)) {
                        // Текст з форматуванням (rich text)
                        $t = '';
                        foreach ($si->r as $r) {
                            $t .= (string)$r->t;
                        }
                        $sharedStrings[] = $t;
                    } else {
                        $sharedStrings[] = '';
                    }
                }
            }
        }

        // 2. Отримуємо зв'язку між назвами аркушів та файлами sheet*.xml
        $sheets = [];
        $wbContent = $zip->getFromName('xl/workbook.xml');
        $relsContent = $zip->getFromName('xl/_rels/workbook.xml.rels');

        $sheetMap = []; // rId => target filename
        if ($relsContent !== false) {
            $relsXml = @simplexml_load_string($relsContent);
            if ($relsXml) {
                foreach ($relsXml->Relationship as $rel) {
                    $sheetMap[(string)$rel['Id']] = (string)$rel['Target'];
                }
            }
        }

        if ($wbContent !== false) {
            $wbXml = @simplexml_load_string($wbContent);
            if ($wbXml && isset($wbXml->sheets)) {
                $sheetIdx = 1;
                foreach ($wbXml->sheets->sheet as $s) {
                    $sName = (string)$s['name'];
                    $rId = (string)$s->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                    $target = $sheetMap[$rId] ?? ("worksheets/sheet{$sheetIdx}.xml");
                    
                    // Нормалізуємо шлях у ZIP (наприклад, worksheets/sheet1.xml -> xl/worksheets/sheet1.xml)
                    $zipPath = (strpos($target, 'xl/') === 0) ? $target : ('xl/' . ltrim($target, '/'));
                    $sheets[$sName] = $zipPath;
                    $sheetIdx++;
                }
            }
        }

        // Якщо мапінг не знайдено, перебираємо файли sheet*.xml напряму
        if (empty($sheets)) {
            for ($i = 1; $i <= 50; $i++) {
                $p = "xl/worksheets/sheet{$i}.xml";
                if ($zip->locateName($p) !== false) {
                    $sheets["Аркуш {$i}"] = $p;
                } else {
                    break;
                }
            }
        }

        // 3. Читаємо дані з кожного аркуша
        $result = [];
        foreach ($sheets as $sheetName => $xmlPath) {
            $sheetContent = $zip->getFromName($xmlPath);
            if ($sheetContent === false) continue;

            $sheetXml = @simplexml_load_string($sheetContent);
            if (!$sheetXml || !isset($sheetXml->sheetData)) continue;

            $rows = [];
            foreach ($sheetXml->sheetData->row as $rowNode) {
                $rowNum = (int)$rowNode['r'];
                $rowData = [];
                $maxCol = 0;

                foreach ($rowNode->c as $cNode) {
                    $cellRef = (string)$cNode['r']; // наприклад, 'B5'
                    $colLetter = preg_replace('/[0-9]/', '', $cellRef);
                    $colIdx = self::colLetterToIndex($colLetter);
                    $maxCol = max($maxCol, $colIdx);

                    $type = (string)$cNode['t'];
                    $val = null;

                    if ($type === 's') {
                        // Спільний рядок
                        $idx = (int)$cNode->v;
                        $val = $sharedStrings[$idx] ?? '';
                    } elseif ($type === 'inlineStr') {
                        // Вбудований рядок
                        $val = (string)($cNode->is->t ?? '');
                    } elseif ($type === 'str') {
                        // Рядок-результат формули
                        $val = (string)($cNode->v ?? '');
                    } else {
                        // Число, формула або дата
                        $v = (string)($cNode->v ?? '');
                        if ($v !== '') {
                            $val = is_numeric($v) ? (float)$v : $v;
                        }
                    }

                    $rowData[$colIdx] = $val;
                }

                // Заповнюємо порожні комірки до максимальної колонки
                $denseRow = [];
                for ($c = 1; $c <= $maxCol; $c++) {
                    $denseRow[] = $rowData[$c] ?? null;
                }

                if (!empty($denseRow)) {
                    $rows[] = $denseRow;
                }
            }

            $result[$sheetName] = $rows;
        }

        $zip->close();
        return $result;
    }

    /**
     * Конвертує літерне позначення колонки (A, B, Z, AA, AB) у числовий індекс (1, 2, 26, 27)
     */
    private static function colLetterToIndex(string $col): int {
        $col = strtoupper($col);
        $len = strlen($col);
        $idx = 0;
        for ($i = 0; $i < $len; $i++) {
            $idx = $idx * 26 + (ord($col[$i]) - 64);
        }
        return $idx;
    }
}
