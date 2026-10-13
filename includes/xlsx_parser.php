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
            $sxml = self::loadCleanXml($sharedXmlContent);
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
            $relsXml = self::loadCleanXml($relsContent);
            if ($relsXml) {
                foreach ($relsXml->Relationship as $rel) {
                    $sheetMap[(string)$rel['Id']] = (string)$rel['Target'];
                }
            }
        }

        if ($wbContent !== false) {
            $wbXml = self::loadCleanXml($wbContent);
            if ($wbXml && isset($wbXml->sheets)) {
                $sheetIdx = 1;
                foreach ($wbXml->sheets->sheet as $s) {
                    $sName = (string)$s['name'];
                    $rId = (string)($s['id'] ?? $s->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] ?? '');
                    if (empty($rId) && isset($s->attributes()['id'])) {
                        $rId = (string)$s->attributes()['id'];
                    }
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

            $sheetXml = self::loadCleanXml($sheetContent);
            if (!$sheetXml || !isset($sheetXml->sheetData)) continue;

            $rows = [];
            $lastRowNum = 0;
            foreach ($sheetXml->sheetData->row as $rowNode) {
                $rowNum = (int)$rowNode['r'];
                if ($rowNum > 0 && $lastRowNum > 0 && $rowNum > $lastRowNum + 1) {
                    // Додаємо пропущені порожні рядки для точного збереження індексів рядків Excel
                    for ($emptyR = $lastRowNum + 1; $emptyR < $rowNum; $emptyR++) {
                        $rows[] = [];
                    }
                } elseif ($rowNum > 1 && $lastRowNum === 0) {
                    for ($emptyR = 1; $emptyR < $rowNum; $emptyR++) {
                        $rows[] = [];
                    }
                }
                if ($rowNum > 0) {
                    $lastRowNum = $rowNum;
                }

                $rowData = [];
                $maxCol = 0;

                foreach ($rowNode->c as $cNode) {
                    $cellRef = (string)$cNode['r']; // наприклад, 'B5'
                    $colLetter = preg_replace('/[0-9]/', '', $cellRef);
                    $colIdx = $colLetter !== '' ? self::colLetterToIndex($colLetter) : ($maxCol + 1);
                    $maxCol = max($maxCol, $colIdx);

                    $type = (string)($cNode['t'] ?? '');
                    $val = null;

                    if ($type === 's') {
                        // Спільний рядок
                        $idx = (int)$cNode->v;
                        $val = $sharedStrings[$idx] ?? '';
                    } elseif ($type === 'inlineStr') {
                        // Вбудований рядок (може бути <is><t> або <is><r><t>)
                        if (isset($cNode->is->t)) {
                            $val = (string)$cNode->is->t;
                        } elseif (isset($cNode->is->r)) {
                            $t = '';
                            foreach ($cNode->is->r as $r) {
                                $t .= (string)($r->t ?? '');
                            }
                            $val = $t;
                        } else {
                            $val = trim((string)($cNode->is ?? ''));
                        }
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

                $rows[] = $denseRow;
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

    /**
     * Завантаження XML з очищенням просторів імен та префіксів (xr:, mc:, x14ac:, r:) для безпомилкового читання SimpleXML
     */
    private static function loadCleanXml(string $xmlContent) {
        // Видаляємо UTF-8 BOM, якщо він присутній (як у файлах вивантаження 1С)
        $xmlContent = preg_replace('/^\xEF\xBB\xBF/', '', $xmlContent);
        if (trim($xmlContent) === '') return false;

        // 1. Видаляємо всі оголошення xmlns="..." та xmlns:prefix="..."
        $cleaned = preg_replace('/\sxmlns(?::[\w\-]+)?="[^"]*"/i', '', $xmlContent);

        // 2. Видаляємо префікси просторів імен у тегах (<x:worksheet> -> <worksheet>, </x:worksheet> -> </worksheet>)
        $cleaned = preg_replace('/<(\/?)[a-zA-Z0-9_\-]+:([a-zA-Z0-9_\-]+)/', '<$1$2', $cleaned);

        // 3. Видаляємо префікси просторів імен в атрибутах (xr:uid="..." -> uid="...", r:id="..." -> id="...", mc:Ignorable="..." -> Ignorable="..."), окрім xml:*
        $cleaned = preg_replace('/(\s)(?!xml:)[a-zA-Z0-9_\-]+:([a-zA-Z0-9_\-]+\s*=)/i', '$1$2', $cleaned);

        return @simplexml_load_string($cleaned, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_PARSEHUGE);
    }
}
