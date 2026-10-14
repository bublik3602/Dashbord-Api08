<?php
/**
 * Допоміжний модуль для роботи зі звітом «СЕМЕНА — Лист погодження з постачання соняшнику»
 * Повністю замінює десктопну програму: приймає напряму 2 файли з 1С («Приход» + «Специфікація»)
 * або готовий «Лист_погодження.xlsx», проводить аудит змін цін/ваги та формує всі вкладки звіту.
 */

class SemenaHelper {

    public static function getJsonPath(): string {
        return __DIR__ . '/../database/semena_data.json';
    }

    public static function getXlsxPath(): string {
        return __DIR__ . '/../database/Лист_погодження.xlsx';
    }

    public static function getHistoryPath(): string {
        return __DIR__ . '/../database/changes_history.json';
    }

    public static function getRawPrihodPath(): string {
        return __DIR__ . '/../database/semena_raw_prihod.json';
    }

    public static function getRawSpecPath(): string {
        return __DIR__ . '/../database/semena_raw_spec.json';
    }

    /**
     * Нормалізує дату формату DD.MM.YY -> DD.MM.YYYY
     */
    public static function normalizeDate(string $dStr): string {
        $s = trim(explode(' ', trim($dStr))[0] ?? '');
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{2,4})$/', $s, $m)) {
            $year = strlen($m[3]) === 2 ? ('20' . $m[3]) : $m[3];
            return $m[1] . '.' . $m[2] . '.' . $year;
        }
        return $s;
    }

    /**
     * Перетворює дату DD.MM.YYYY на ключ сортування YYYY-MM-DD
     */
    public static function dateSortKey(string $dVal): string {
        $s = self::normalizeDate($dVal);
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $s, $m)) {
            return $m[3] . '-' . $m[2] . '-' . $m[1];
        }
        return '9999-99-99';
    }

    /**
     * Перетворює дату та час на ключ сортування YYYY-MM-DD HH:MM
     */
    public static function dateTimeSortKey(string $dtVal): string {
        $s = trim($dtVal);
        if (preg_match('/(\d{2})\.(\d{2})\.(\d{4})(?:[\sчас]+(\d{1,2}):(\d{2}))?/', $s, $m)) {
            $y = $m[3]; $mo = $m[2]; $d = $m[1];
            $h = isset($m[4]) && $m[4] !== '' ? str_pad($m[4], 2, '0', STR_PAD_LEFT) : '00';
            $mi = isset($m[5]) && $m[5] !== '' ? str_pad($m[5], 2, '0', STR_PAD_LEFT) : '00';
            return "{$y}-{$mo}-{$d} {$h}:{$mi}";
        }
        return '0000-00-00 00:00';
    }

    /**
     * День тижня українською (Пн, Вт, Ср, Чт, Пт, Сб, Нд)
     */
    public static function getUkrainianDayOfWeek(string $dateStr): string {
        $s = self::normalizeDate($dateStr);
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $s, $m)) {
            $ts = mktime(12, 0, 0, (int)$m[2], (int)$m[1], (int)$m[3]);
            if ($ts !== false) {
                $days = [1 => 'Пн', 2 => 'Вт', 3 => 'Ср', 4 => 'Чт', 5 => 'Пт', 6 => 'Сб', 7 => 'Нд'];
                return $days[(int)date('N', $ts)] ?? '';
            }
        }
        return '';
    }

    /**
     * Витягує код договору (наприклад 'А-800') та номер специфікації ('Сп.1').
     * Повертає ['code' => 'А-800', 'sp_num' => 'Сп.1', 'display' => 'А-800 (Сп.1)']
     */
    public static function extractCodeAndSpecNum(string $text): array {
        $s = trim($text);
        if ($s === '') {
            return ['code' => '', 'sp_num' => '', 'display' => ''];
        }

        // Якщо рядок є суто числовим без літер (наприклад, вага авто 25460 або тара) — це НЕ код специфікації!
        if (preg_match('/^\d+(\.\d+)?$/', $s)) {
            return ['code' => '', 'sp_num' => '', 'display' => ''];
        }

        $spNum = '';
        if (preg_match('/(?:Сп\.?|СП\.?|№)\s*(\d+)/iu', $s, $m)) {
            $spNum = 'Сп.' . $m[1];
        }

        $code = '';
        if (preg_match('/([A-Za-zА-Яа-яІіЇїЄєҐґ]\s*[-–]\s*\d+)/u', $s, $cm)) {
            $code = mb_strtoupper(preg_replace('/\s+/u', '', str_replace('–', '-', $cm[1])), 'UTF-8');
        } elseif (preg_match('/\(([^)]+)\)/u', $s, $pm) && !preg_match('/(?:Сп\.?|СП\.?|№)\s*\d+/iu', $pm[1])) {
            $code = mb_strtoupper(trim($pm[1]), 'UTF-8');
        } else {
            $parts = explode('(', $s);
            $candidate = mb_strtoupper(trim($parts[0]), 'UTF-8');
            // Кандидат не повинен бути числом
            if (!preg_match('/^\d+$/', $candidate)) {
                $code = $candidate;
            }
        }

        if ($code === '' || preg_match('/^\d+$/', $code)) {
            return ['code' => '', 'sp_num' => '', 'display' => ''];
        }

        $display = ($spNum !== '' && $code !== '') ? "{$code} ({$spNum})" : $code;
        return ['code' => $code, 'sp_num' => $spNum, 'display' => $display];
    }

    /**
     * Число з комірки Excel
     */
    public static function parseFloatVal($val): ?float {
        if ($val === null || $val === '') return null;
        if (is_int($val) || is_float($val)) return (float)$val;
        $s = str_replace(["\xC2\xA0", ' ', ','], ['', '', '.'], trim((string)$val));
        $s = preg_replace('/[^0-9.\-+]/', '', $s);
        if ($s === '' || $s === '-' || $s === '+') return null;
        return is_numeric($s) ? (float)$s : null;
    }

    /**
     * Завантажити історію змін (changes_history.json)
     */
    public static function loadChangesHistory(): array {
        $default = [
            'last_receipts_datetime' => '',
            'last_receipts_iso'      => '',
            'spec_price_notes'       => [],
            'events'                 => [],
        ];
        $path = self::getHistoryPath();
        if (file_exists($path)) {
            $decoded = json_decode((string)file_get_contents($path), true);
            if (is_array($decoded)) {
                return array_merge($default, $decoded);
            }
        }
        return $default;
    }

    /**
     * Зберегти історію змін (changes_history.json)
     */
    public static function saveChangesHistory(array $history): void {
        $path = self::getHistoryPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        file_put_contents($path, json_encode($history, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    /**
     * Перевіряє, чи таблиця є Файлом 1 («Приход кормов и добавок» / Реєстр прийомок 1С)
     */
    public static function isPrihodSheet(array $rows): bool {
        $maxR = min(15, count($rows));
        $hasPrihodKormov = false;
        $hasVehiclesHeader = false;
        $hasSpecsCol = false;
        $isSupplySheet = false;

        for ($r = 0; $r < $maxR; $r++) {
            $rowStr = mb_strtolower(implode(' ', array_map('strval', $rows[$r] ?? [])), 'UTF-8');
            if (strpos($rowStr, 'приход кормов') !== false) {
                $hasPrihodKormov = true;
            }
            if (strpos($rowStr, 'поставка подсолнечника') !== false || strpos($rowStr, 'движения сырья') !== false || strpos($rowStr, 'план производства') !== false || strpos($rowStr, 'свод за период') !== false) {
                $isSupplySheet = true;
            }
            if (strpos($rowStr, 'дата и время въезда') !== false) {
                $hasVehiclesHeader = true;
            }
            if (strpos($rowStr, 'спецификац') !== false || strpos($rowStr, 'договор') !== false) {
                $hasSpecsCol = true;
            }
        }

        if ($isSupplySheet) {
            return false;
        }

        // Пряма офіційна назва 1С
        if ($hasPrihodKormov) {
            return true;
        }

        // Запасний варіант: обов'язково шапка авто + специфікація + не менше 25 колонок
        $maxCols = 0;
        foreach (array_slice($rows, 0, 8) as $r) {
            $maxCols = max($maxCols, count($r));
        }
        if ($hasVehiclesHeader && $hasSpecsCol && $maxCols >= 25) {
            return true;
        }

        return false;
    }

    /**
     * Перевіряє, чи таблиця є Файлом 2 («Реєстр специфікацій» / Спецификация 1С)
     */
    public static function isSpecSheet(array $rows): bool {
        $maxR = min(15, count($rows));
        $hasPodsoln = false;
        $hasSpecCols = false;
        $isSupplySheet = false;

        for ($r = 0; $r < $maxR; $r++) {
            $rowStr = mb_strtolower(implode(' ', array_map('strval', $rows[$r] ?? [])), 'UTF-8');
            if (strpos($rowStr, 'поставка подсолнечника') !== false || strpos($rowStr, 'движения сырья') !== false || strpos($rowStr, 'план производства') !== false || strpos($rowStr, 'свод за период') !== false || strpos($rowStr, 'баланс') !== false || strpos($rowStr, 'расход сырья') !== false) {
                $isSupplySheet = true;
            }
            if (strpos($rowStr, 'номенклатура равно') !== false || strpos($rowStr, 'период контрактов') !== false || strpos($rowStr, 'семена подсолнечника') !== false) {
                $hasPodsoln = true;
            }
            if (strpos($rowStr, 'цена поставки (с дост.)') !== false || (strpos($rowStr, 'срок поставки') !== false && strpos($rowStr, 'подоговору') !== false)) {
                $hasSpecCols = true;
            }
        }

        if ($isSupplySheet) {
            return false;
        }

        // Обов'язково наявність шапки 1С з номенклатурою соняшника або періоду контрактів ТА колонок специфікацій
        return $hasPodsoln && $hasSpecCols;
    }

    /**
     * Витягує дату та час формування звіту прийомок 1С з шапки Файлу 1 («Приход»)
     * Повертає ['iso' => '2026-10-13T07:57:18', 'display' => '13.10.2026 час 07:57']
     */
    public static function parseReceiptsDatetimeFromRows(array $rows): array {
        $maxR = min(10, count($rows));
        for ($r = 0; $r < $maxR; $r++) {
            foreach (($rows[$r] ?? []) as $cell) {
                $val = trim((string)$cell);
                if ($val === '') continue;

                if (preg_match('/[-–—]\s*(\d{2})\.(\d{2})\.(\d{4})\s+(\d{1,2}):(\d{2})(?::(\d{2}))?/u', $val, $m)) {
                    $d = $m[1]; $mo = $m[2]; $y = $m[3];
                    $h = str_pad($m[4], 2, '0', STR_PAD_LEFT);
                    $mi = str_pad($m[5], 2, '0', STR_PAD_LEFT);
                    $sec = isset($m[6]) && $m[6] !== '' ? str_pad($m[6], 2, '0', STR_PAD_LEFT) : '00';
                    return [
                        'iso'     => "{$y}-{$mo}-{$d}T{$h}:{$mi}:{$sec}",
                        'display' => "{$d}.{$mo}.{$y} час {$h}:{$mi}",
                    ];
                }

                if (preg_match_all('/(\d{2})\.(\d{2})\.(\d{4})\s+(\d{1,2}):(\d{2})(?::(\d{2}))?/u', $val, $all, PREG_SET_ORDER) && count($all) >= 2) {
                    $last = end($all);
                    $d = $last[1]; $mo = $last[2]; $y = $last[3];
                    $h = str_pad($last[4], 2, '0', STR_PAD_LEFT);
                    $mi = str_pad($last[5], 2, '0', STR_PAD_LEFT);
                    $sec = isset($last[6]) && $last[6] !== '' ? str_pad($last[6], 2, '0', STR_PAD_LEFT) : '00';
                    return [
                        'iso'     => "{$y}-{$mo}-{$d}T{$h}:{$mi}:{$sec}",
                        'display' => "{$d}.{$mo}.{$y} час {$h}:{$mi}",
                    ];
                }
            }
        }
        return [
            'iso'     => date('Y-m-d\TH:i:s'),
            'display' => date('d.m.Y час H:i'),
        ];
    }

    /**
     * Парсить Файл 2 («Специфікація» 1С) — повний аналог load_specifications() з sunflower_processor.py
     */
    public static function parseSpecificationsRows(array $rows): array {
        $headerRow = 7; // 0-indexed (Row 8)
        $startRow  = 8; // 0-indexed (Row 9)

        $maxScan = min(15, count($rows));
        for ($r = 0; $r < $maxScan; $r++) {
            $rStr = implode(' ', array_map('strval', array_slice($rows[$r] ?? [], 0, 25)));
            if (mb_stripos($rStr, 'Спецификация', 0, 'UTF-8') !== false || mb_stripos($rStr, 'Менеджер', 0, 'UTF-8') !== false) {
                $headerRow = $r;
                $startRow  = $r + 1;
                break;
            }
        }

        // Будуємо карту колонок з 3 рядків шапки
        $maxCols = 0;
        for ($hr = max(0, $headerRow - 2); $hr <= $headerRow; $hr++) {
            $maxCols = max($maxCols, count($rows[$hr] ?? []));
        }

        $colSpec      = 12; // Col 13
        $colSupplier  = 3;  // Col 4
        $colTerms     = 4;  // Col 5
        $colManager   = 13; // Col 14
        $colDate      = 0;  // Col 1
        $colDost      = 10; // Col 11 «Доставка грн.»
        $colPricePost = 22; // Col 23 «Цена поставки (с дост.)»
        $colPriceDost = 25; // Col 26 «Цена с доставкой»
        $colPriceDog  = 26; // Col 27 «Цена по договору»
        $colDogTons   = 6;  // Col 7 «ПоДоговору»
        $colTons      = 28; // Col 29 «Контракты, т»
        $colOilBase   = 34; // Col 35 «Маслич.(сухая)»
        $colOilFact   = 33; // Col 34 «Маслич.факт»
        $colWFact     = 32; // Col 33 «Влажность»
        $colSFact     = 45; // Col 46 «Сорность»

        for ($c = 0; $c < $maxCols; $c++) {
            $parts = [];
            for ($hr = max(0, $headerRow - 2); $hr <= $headerRow; $hr++) {
                $v = trim((string)($rows[$hr][$c] ?? ''));
                if ($v !== '') $parts[] = $v;
            }
            $name = mb_strtolower(implode(' ', $parts), 'UTF-8');
            if ($name === '') continue;

            if (strpos($name, 'спецификация') !== false && strpos($name, 'номер') === false) {
                $colSpec = $c;
            } elseif (strpos($name, 'контрагент') !== false) {
                $colSupplier = $c;
            } elseif (strpos($name, 'условие') !== false) {
                $colTerms = $c;
            } elseif (strpos($name, 'менеджер') !== false) {
                $colManager = $c;
            } elseif (strpos($name, 'срок поставки') !== false) {
                $colDate = $c;
            } elseif (strpos($name, 'доставка грн') !== false) {
                $colDost = $c;
            } elseif (strpos($name, 'цена поставки (с дост.)') !== false) {
                $colPricePost = $c;
            } elseif (strpos($name, 'цена с доставкой') !== false) {
                $colPriceDost = $c;
            } elseif (strpos($name, 'цена по договору') !== false) {
                $colPriceDog = $c;
            } elseif (strpos($name, 'подоговору') !== false) {
                $colDogTons = $c;
            } elseif (strpos($name, 'маслич.(сухая)') !== false) {
                $colOilBase = $c;
            } elseif (strpos($name, 'маслич.факт') !== false) {
                $colOilFact = $c;
            } elseif (strpos($name, 'влажность') !== false && strpos($name, 'баз') === false && strpos($name, 'заявл') === false) {
                $colWFact = $c;
            } elseif (strpos($name, 'сорность') !== false && strpos($name, 'баз') === false && strpos($name, 'заявл') === false) {
                $colSFact = $c;
            }
        }

        $specs = [];
        $totalRows = count($rows);
        for ($r = $startRow; $r < $totalRows; $r++) {
            $row = $rows[$r] ?? [];
            $specCell = trim((string)($row[$colSpec] ?? ''));
            if ($specCell === '' || mb_stripos($specCell, 'итого', 0, 'UTF-8') === 0) {
                continue;
            }

            $extracted = self::extractCodeAndSpecNum($specCell);
            $code = $extracted['code'];
            if ($code === '') continue;

            $supplier = trim((string)($row[$colSupplier] ?? ''));
            $terms    = trim((string)($row[$colTerms] ?? ''));
            $manager  = trim((string)($row[$colManager] ?? ''));
            $dateVal  = self::normalizeDate((string)($row[$colDate] ?? ''));

            $contractTons = self::parseFloatVal($row[$colTons] ?? null);
            if ($contractTons === null || $contractTons <= 0) {
                $contractTons = self::parseFloatVal($row[$colDogTons] ?? null);
            }

            $dostCost = self::parseFloatVal($row[$colDost] ?? null) ?? 0.0;
            $priceNoDost = self::parseFloatVal($row[$colPriceDog] ?? null);

            $priceDost = self::parseFloatVal($row[$colPricePost] ?? null);
            if ($priceDost === null) {
                $priceDost = self::parseFloatVal($row[$colPriceDost] ?? null);
            }
            if ($priceDost === null && $priceNoDost !== null) {
                $priceDost = $priceNoDost + $dostCost;
            }
            if ($priceNoDost === null && $priceDost !== null) {
                if (strtoupper($terms) === 'DAP' || $dostCost == 0.0) {
                    $priceNoDost = $priceDost;
                } else {
                    $priceNoDost = $priceDost - $dostCost;
                }
            }

            $specs[$code] = [
                'code'              => $code,
                'sp_num'            => $extracted['sp_num'],
                'spec_display'      => $extracted['display'],
                'spec_full_name'    => $specCell,
                'supplier'          => $supplier,
                'delivery_terms'    => $terms,
                'delivery_cost'     => $dostCost,
                'manager'           => $manager,
                'date'              => $dateVal,
                'contract_tons'     => $contractTons,
                'price_delivery'    => $priceDost,
                'price_no_delivery' => $priceNoDost,
                'base_oil'          => self::parseFloatVal($row[$colOilBase] ?? null),
                'fact_oil'          => self::parseFloatVal($row[$colOilFact] ?? null),
                'fact_w'            => self::parseFloatVal($row[$colWFact] ?? null),
                'fact_s'            => self::parseFloatVal($row[$colSFact] ?? null),
            ];
        }

        return $specs;
    }

    /**
     * Парсить Файл 1 («Приход» 1С) — повний аналог load_receipts() з sunflower_processor.py
     */
    public static function parseReceiptsRows(array $rows): array {
        $dtInfo = self::parseReceiptsDatetimeFromRows($rows);
        $dataStart = 6; // 0-indexed (Row 7)
        $vsegoStart = null;
        $totalRows = count($rows);

        for ($r = 0; $r < $totalRows; $r++) {
            $c1 = trim((string)($rows[$r][0] ?? ''));
            $c2 = trim((string)($rows[$r][1] ?? ''));
            if ($c1 === 'Всего' || $c2 === 'Всего') {
                $vsegoStart = $r;
                break;
            }
        }

        $truckRows = [];
        $maxTruckRow = $vsegoStart !== null ? ($vsegoStart - 1) : ($totalRows - 1);

        for ($r = $dataStart; $r <= $maxTruckRow; $r++) {
            $row = $rows[$r] ?? [];
            $numPp = $row[0] ?? null;
            if ($numPp === null || trim((string)$numPp) === '' || !is_numeric(trim((string)$numPp))) {
                continue;
            }

            $auto     = trim((string)($row[1] ?? ''));
            $dateIn   = self::normalizeDate((string)($row[2] ?? ''));
            $docNo    = trim((string)($row[4] ?? ''));
            $supplier = trim((string)($row[7] ?? ''));
            $terms    = trim((string)($row[9] ?? ''));
            $specRaw  = trim((string)($row[11] ?? ''));

            $netto   = self::parseFloatVal($row[23] ?? null);
            $totalKg = self::parseFloatVal($row[24] ?? null);
            $zachet  = self::parseFloatVal($row[25] ?? null) ?? 0.0;

            $wFact   = self::parseFloatVal($row[31] ?? null);
            $sFact   = self::parseFloatVal($row[34] ?? null);
            $oilFact = self::parseFloatVal($row[37] ?? null);

            $extracted = self::extractCodeAndSpecNum($specRaw);
            $deliveredKg = $netto !== null ? $netto : ($totalKg ?? 0.0);

            $truckRows[] = [
                'num_pp'       => (int)$numPp,
                'auto'         => $auto,
                'date'         => $dateIn,
                'doc_no'       => $docNo,
                'supplier'     => $supplier,
                'terms'        => $terms,
                'spec_raw'     => $specRaw,
                'spec_display' => $extracted['display'],
                'code'         => $extracted['code'],
                'delivered_kg' => $deliveredKg,
                'zachet_kg'    => $zachet,
                'diff_kg'      => $deliveredKg - $zachet,
                'w_fact'       => $wFact,
                's_fact'       => $sFact,
                'oil_fact'     => $oilFact,
            ];
        }

        $summaryRows = [];
        if ($vsegoStart !== null) {
            for ($r = $vsegoStart + 1; $r < $totalRows; $r++) {
                $row = $rows[$r] ?? [];
                $specRaw = trim((string)($row[11] ?? ''));
                if ($specRaw === '') continue;

                $extracted = self::extractCodeAndSpecNum($specRaw);
                if ($extracted['code'] === '') continue;

                $supplier = trim((string)($row[8] ?? ($row[7] ?? '')));
                $terms    = trim((string)($row[10] ?? ''));
                $delivKg  = self::parseFloatVal($row[24] ?? null) ?? 0.0;
                $zachKg   = self::parseFloatVal($row[25] ?? null) ?? 0.0;
                $oilFact  = self::parseFloatVal($row[36] ?? null) ?? self::parseFloatVal($row[37] ?? null);

                $summaryRows[] = [
                    'supplier'     => $supplier,
                    'terms'        => $terms,
                    'spec_raw'     => $specRaw,
                    'spec_display' => $extracted['display'],
                    'code'         => $extracted['code'],
                    'delivered_kg' => $delivKg,
                    'zachet_kg'    => $zachKg,
                    'diff_kg'      => $delivKg - $zachKg,
                    'oil_fact'     => $oilFact,
                ];
            }
        }

        // Валідація: якщо таблиця не містить валідних специфікацій (наприклад, колонка 11 містила вагу авто чи інше)
        $validCodesCount = 0;
        foreach ($truckRows as $tr) {
            if (!empty($tr['code'])) {
                $validCodesCount++;
            }
        }
        if (count($truckRows) > 0 && $validCodesCount < 3) {
            return [
                'receipts_datetime' => '',
                'last_receipts_iso' => '',
                'truck_rows'        => [],
                'summary_rows'      => [],
            ];
        }

        return [
            'receipts_datetime' => $dtInfo['display'],
            'last_receipts_iso' => $dtInfo['iso'],
            'truck_rows'        => $truckRows,
            'summary_rows'      => $summaryRows,
        ];
    }

    /**
     * Повний конвеєр зведення звіту «Лист погодження» з 2 файлів 1С («Приход» + «Специфікація»)
     * Можна передати обидва або один із них (другий підтягнеться з кешу database/semena_raw_*.json)
     */
    public static function processRaw1CData(?array $prihodData, ?array $specsDb, bool $allowOlderReceipts = false): array {
        // Якщо передано лише один з 2 файлів — підвантажуємо інший зі збереженого кешу
        if ($prihodData === null) {
            $prihodCachePath = self::getRawPrihodPath();
            if (file_exists($prihodCachePath)) {
                $prihodData = json_decode((string)file_get_contents($prihodCachePath), true);
            }
        }
        if ($specsDb === null) {
            $specCachePath = self::getRawSpecPath();
            if (file_exists($specCachePath)) {
                $specsDb = json_decode((string)file_get_contents($specCachePath), true);
            }
        }

        if (empty($prihodData['truck_rows']) && empty($specsDb)) {
            throw new RuntimeException('Не вдалося розпізнати дані Приходу або Специфікації 1С.');
        }

        $historyData = self::loadChangesHistory();
        $existingSemena = self::loadData();

        // Перевірка актуальності дати прийомок (захист від завантаження старішого файлу Приходу)
        $newIso = $prihodData['last_receipts_iso'] ?? '';
        $newDisplay = $prihodData['receipts_datetime'] ?? '';
        $existIso = $historyData['last_receipts_iso'] ?? ($existingSemena['last_receipts_iso'] ?? '');
        $existDisplay = $historyData['last_receipts_datetime'] ?? ($existingSemena['updated_at'] ?? '');

        if (!$allowOlderReceipts && $newIso !== '' && $existIso !== '' && strcmp($newIso, $existIso) < 0) {
            throw new RuntimeException(
                "Завантаження заблоковано: вибрано застарілий файл прийомок 1С ({$newDisplay}), " .
                "тоді як у системі вже є новіші дані ({$existDisplay}). " .
                "Оновлення назад у часі заборонено, щоб не затерти актуальну вагу та історію змін."
            );
        }

        // Зберігаємо сирі дані Приходу і Специфікацій у кеш
        if ($prihodData !== null) {
            file_put_contents(self::getRawPrihodPath(), json_encode($prihodData, JSON_UNESCAPED_UNICODE));
        }
        if ($specsDb !== null) {
            file_put_contents(self::getRawSpecPath(), json_encode($specsDb, JSON_UNESCAPED_UNICODE));
        }

        $truckRows   = $prihodData['truck_rows'] ?? [];
        $summaryRows = $prihodData['summary_rows'] ?? [];
        $specsDb     = $specsDb ?? [];

        // Існуючі рядки для аудиту змін (порівняння Було -> Стало)
        $existingByCode = [];
        foreach (($existingSemena['agg_rows'] ?? []) as $er) {
            $c = $er['code'] ?? self::extractCodeAndSpecNum($er['spec'] ?? '')['code'];
            if ($c !== '') {
                $existingByCode[$c] = $er;
            }
        }

        // Групуємо авто за кодом специфікації
        $trucksByCode = [];
        foreach ($truckRows as $t) {
            $c = $t['code'] ?? '';
            if ($c !== '') {
                $trucksByCode[$c][] = $t;
            }
        }

        $summaryByCode = [];
        foreach ($summaryRows as $s) {
            $c = $s['code'] ?? '';
            if ($c !== '') {
                $summaryByCode[$c] = $s;
            }
        }

        $allCodes = [];
        foreach ($summaryRows as $s) {
            $c = $s['code'] ?? '';
            if ($c !== '' && !in_array($c, $allCodes, true)) $allCodes[] = $c;
        }
        foreach ($truckRows as $t) {
            $c = $t['code'] ?? '';
            if ($c !== '' && !in_array($c, $allCodes, true)) $allCodes[] = $c;
        }

        $nowTs   = date('d.m.Y H:i');
        $shortTs = date('d.m');
        $aggRows = [];

        foreach ($allCodes as $code) {
            $specInfo = $specsDb[$code] ?? [];
            $sumInfo  = $summaryByCode[$code] ?? [];
            $tList    = $trucksByCode[$code] ?? [];

            $supplier = ($sumInfo['supplier'] ?? '') ?: (($tList[0]['supplier'] ?? '') ?: ($specInfo['supplier'] ?? ''));
            $specDisplay = ($specInfo['spec_display'] ?? '') ?: (($sumInfo['spec_display'] ?? '') ?: (($tList[0]['spec_display'] ?? '') ?: $code));

            $dateStr = self::normalizeDate((string)($specInfo['date'] ?? ''));
            if ($dateStr === '' && !empty($tList)) {
                $dates = array_filter(array_map(fn($x) => self::normalizeDate($x['date'] ?? ''), $tList));
                if (!empty($dates)) {
                    usort($dates, fn($a, $b) => strcmp(self::dateSortKey($a), self::dateSortKey($b)));
                    $dateStr = $dates[0];
                }
            }

            $manager = $specInfo['manager'] ?? '';
            $terms   = ($sumInfo['terms'] ?? '') ?: (($tList[0]['terms'] ?? '') ?: ($specInfo['delivery_terms'] ?? ''));

            if (isset($sumInfo['delivered_kg']) && $sumInfo['delivered_kg'] > 0) {
                $weightDelivered = $sumInfo['delivered_kg'] / 1000.0;
                $weightZachet    = $sumInfo['zachet_kg'] / 1000.0;
            } elseif (!empty($tList)) {
                $weightDelivered = array_sum(array_column($tList, 'delivered_kg')) / 1000.0;
                $weightZachet    = array_sum(array_column($tList, 'zachet_kg')) / 1000.0;
            } elseif (!empty($specInfo['contract_tons'])) {
                $weightDelivered = (float)$specInfo['contract_tons'];
                $weightZachet    = (float)$specInfo['contract_tons'];
            } else {
                $weightDelivered = 0.0;
                $weightZachet    = 0.0;
            }

            $weightDiff = round($weightDelivered - $weightZachet, 3);
            $priceDelivery   = isset($specInfo['price_delivery']) ? (float)$specInfo['price_delivery'] : null;
            $priceNoDelivery = isset($specInfo['price_no_delivery']) ? (float)$specInfo['price_no_delivery'] : null;
            $baseOil         = isset($specInfo['base_oil']) ? (float)$specInfo['base_oil'] : 48.0;

            $factW   = null;
            $factS   = null;
            $factOil = $sumInfo['oil_fact'] ?? null;

            if (!empty($tList)) {
                $totW = 0.0;
                $wSum = 0.0; $wCnt = 0;
                $sSum = 0.0; $sCnt = 0;
                $oSum = 0.0; $oCnt = 0;

                foreach ($tList as $t) {
                    $wgt = ($t['zachet_kg'] > 0) ? $t['zachet_kg'] : (($t['delivered_kg'] > 0) ? $t['delivered_kg'] : 1.0);
                    $totW += $wgt;
                    if ($t['w_fact'] !== null) { $wSum += $t['w_fact'] * $wgt; $wCnt++; }
                    if ($t['s_fact'] !== null) { $sSum += $t['s_fact'] * $wgt; $sCnt++; }
                    if ($t['oil_fact'] !== null) { $oSum += $t['oil_fact'] * $wgt; $oCnt++; }
                }
                if ($wCnt > 0 && $totW > 0) $factW = $wSum / $totW;
                if ($sCnt > 0 && $totW > 0) $factS = $sSum / $totW;
                if ($factOil === null && $oCnt > 0 && $totW > 0) $factOil = $oSum / $totW;
            }

            if ($factW === null && isset($specInfo['fact_w'])) $factW = (float)$specInfo['fact_w'];
            if ($factS === null && isset($specInfo['fact_s'])) $factS = (float)$specInfo['fact_s'];
            if ($factOil === null && isset($specInfo['fact_oil'])) $factOil = (float)$specInfo['fact_oil'];

            $factW   = $factW !== null ? round($factW, 2) : 0.0;
            $factS   = $factS !== null ? round($factS, 2) : 0.0;
            $factOil = $factOil !== null ? round($factOil, 2) : 0.0;
            $oilDiff = ($factOil > 0 && $baseOil > 0) ? round($factOil - $baseOil, 2) : 0.0;

            // Інкрементальний аудит змін (Ціна / Вага / Якість)
            $note = 'Без змін';
            $changeType = 'same';
            $savedPriceInfo = $historyData['spec_price_notes'][$code] ?? null;
            $priceChanges = [];
            $otherChanges = [];

            if (!empty($existingByCode)) {
                if (!isset($existingByCode[$code])) {
                    $note = 'Новий запис';
                    $changeType = 'new';
                    $evId = "{$code}_new";
                    $existsEv = false;
                    foreach (($historyData['events'] ?? []) as $ev) {
                        if (($ev['id'] ?? '') === $evId) { $existsEv = true; break; }
                    }
                    if (!$existsEv) {
                        $historyData['events'][] = [
                            'id'             => $evId,
                            'timestamp'      => $nowTs,
                            'code'           => $code,
                            'supplier'       => $supplier,
                            'specification'  => $specDisplay,
                            'manager'        => $manager,
                            'category'       => 'new',
                            'category_label' => '🆕 Новий договір',
                            'summary'        => "Додано нову специфікацію {$specDisplay}",
                        ];
                    }
                } else {
                    $old = $existingByCode[$code];
                    $oldPriceDeliv = isset($old['price_delivered']) && $old['price_delivered'] > 0 ? (float)$old['price_delivered'] : null;
                    $oldPriceExw   = isset($old['price_exw']) && $old['price_exw'] > 0 ? (float)$old['price_exw'] : null;
                    $oldDeliv      = (float)($old['delivered'] ?? 0);
                    $oldZach       = (float)($old['credit_weight'] ?? 0);
                    $oldOil        = (float)($old['oil_fact'] ?? 0);

                    if ($priceDelivery !== null && $oldPriceDeliv !== null && abs($priceDelivery - $oldPriceDeliv) > 0.05) {
                        $diffP = $priceDelivery - $oldPriceDeliv;
                        $sign = $diffP >= 0 ? '+' : '';
                        $priceChanges[] = sprintf("Ціна з дост: %s -> %s (%s%.2f)", number_format($oldPriceDeliv, 2, '.', ','), number_format($priceDelivery, 2, '.', ','), $sign, $diffP);
                    }
                    if ($priceNoDelivery !== null && $oldPriceExw !== null && abs($priceNoDelivery - $oldPriceExw) > 0.05) {
                        $diffP = $priceNoDelivery - $oldPriceExw;
                        $sign = $diffP >= 0 ? '+' : '';
                        $priceChanges[] = sprintf("Ціна без дост: %s -> %s (%s%.2f)", number_format($oldPriceExw, 2, '.', ','), number_format($priceNoDelivery, 2, '.', ','), $sign, $diffP);
                    }
                    if (abs($weightDelivered - $oldDeliv) > 0.05) {
                        $otherChanges[] = sprintf("Завезено: %.2f -> %.2f т", $oldDeliv, $weightDelivered);
                    }
                    if (abs($weightZachet - $oldZach) > 0.05) {
                        $otherChanges[] = sprintf("Залікова: %.2f -> %.2f т", $oldZach, $weightZachet);
                    }
                    if ($factOil > 0 && $oldOil > 0 && abs($factOil - $oldOil) > 0.01) {
                        $otherChanges[] = sprintf("Факт масла: %.2f%% -> %.2f%%", $oldOil, $factOil);
                    }

                    if (!empty($priceChanges)) {
                        $pText = implode('; ', $priceChanges);
                        $savedPriceInfo = [
                            'timestamp'     => $shortTs,
                            'price_note'    => $pText,
                            'supplier'      => $supplier,
                            'specification' => $specDisplay,
                        ];
                        $historyData['spec_price_notes'][$code] = $savedPriceInfo;
                        $evId = "{$code}_price_{$priceDelivery}_{$priceNoDelivery}";
                        $existsEv = false;
                        foreach (($historyData['events'] ?? []) as $ev) {
                            if (($ev['id'] ?? '') === $evId) { $existsEv = true; break; }
                        }
                        if (!$existsEv) {
                            $historyData['events'][] = [
                                'id'             => $evId,
                                'timestamp'      => $nowTs,
                                'code'           => $code,
                                'supplier'       => $supplier,
                                'specification'  => $specDisplay,
                                'manager'        => $manager,
                                'category'       => 'price',
                                'category_label' => '💰 Зміна ціни',
                                'summary'        => $pText,
                            ];
                        }
                    }

                    if (!empty($otherChanges)) {
                        $oText = implode('; ', $otherChanges);
                        $evId = "{$code}_wq_" . round($weightDelivered, 2) . "_{$factOil}";
                        $existsEv = false;
                        foreach (($historyData['events'] ?? []) as $ev) {
                            if (($ev['id'] ?? '') === $evId) { $existsEv = true; break; }
                        }
                        if (!$existsEv) {
                            $historyData['events'][] = [
                                'id'             => $evId,
                                'timestamp'      => $nowTs,
                                'code'           => $code,
                                'supplier'       => $supplier,
                                'specification'  => $specDisplay,
                                'manager'        => $manager,
                                'category'       => 'weight_quality',
                                'category_label' => '⚖️ Вага / Якість',
                                'summary'        => $oText,
                            ];
                        }
                    }

                    $allCur = array_merge($priceChanges, $otherChanges);
                    if (!empty($allCur)) {
                        $note = 'Змінено: ' . implode('; ', $allCur);
                        if (empty($priceChanges) && !empty($savedPriceInfo['price_note'])) {
                            $tsS = !empty($savedPriceInfo['timestamp']) ? " ({$savedPriceInfo['timestamp']})" : '';
                            $note .= " | 💰 Зміна ціни{$tsS}: " . $savedPriceInfo['price_note'];
                        }
                        $changeType = 'changed';
                    } else {
                        if (!empty($savedPriceInfo['price_note'])) {
                            $tsS = !empty($savedPriceInfo['timestamp']) ? " ({$savedPriceInfo['timestamp']})" : '';
                            $note = "💰 Зміна ціни{$tsS}: " . $savedPriceInfo['price_note'];
                        } else {
                            $note = 'Без змін';
                        }
                    }
                }
            } elseif (!empty($savedPriceInfo['price_note'])) {
                $tsS = !empty($savedPriceInfo['timestamp']) ? " ({$savedPriceInfo['timestamp']})" : '';
                $note = "💰 Зміна ціни{$tsS}: " . $savedPriceInfo['price_note'];
            }

            $aggRows[] = [
                'supplier'          => $supplier,
                'spec'              => $specDisplay,
                'specification'     => $specDisplay,
                'code'              => $code,
                'date'              => $dateStr,
                'spec_date'         => $dateStr,
                'manager'           => $manager,
                'terms'             => $terms,
                'delivery_terms'    => $terms,
                'contract'          => round((float)($specInfo['contract_tons'] ?? 0), 2),
                'contract_t'        => round((float)($specInfo['contract_tons'] ?? 0), 2),
                'contract_tons'     => round((float)($specInfo['contract_tons'] ?? 0), 2),
                'delivered'         => round($weightDelivered, 3),
                'delivered_t'       => round($weightDelivered, 3),
                'weight_delivered'  => round($weightDelivered, 3),
                'credit_weight'     => round($weightZachet, 3),
                'accounting_t'      => round($weightZachet, 3),
                'weight_zachet'     => round($weightZachet, 3),
                'weight_diff'       => $weightDiff,
                'price_delivered'   => round((float)($priceDelivery ?? 0), 2),
                'price_with_deliv'  => round((float)($priceDelivery ?? 0), 2),
                'price_delivery'    => round((float)($priceDelivery ?? 0), 2),
                'price_exw'         => round((float)($priceNoDelivery ?? 0), 2),
                'price_no_deliv'    => round((float)($priceNoDelivery ?? 0), 2),
                'price_no_delivery' => round((float)($priceNoDelivery ?? 0), 2),
                'oil_base'          => round($baseOil, 2),
                'moisture'          => $factW,
                'w_fact'            => $factW,
                'trash'             => $factS,
                's_fact'            => $factS,
                'oil_fact'          => $factOil,
                'oil_diff'          => $oilDiff,
                'note'              => ($note === 'Без змін' ? '' : $note),
                'change_type'       => $changeType,
            ];
        }

        // Хронологічне сортування agg_rows
        usort($aggRows, function ($a, $b) {
            $ka = self::dateSortKey($a['date'] ?? '');
            $kb = self::dateSortKey($b['date'] ?? '');
            if ($ka !== $kb) return strcmp($ka, $kb);
            return strcmp($a['supplier'] ?? '', $b['supplier'] ?? '');
        });
        foreach ($aggRows as $idx => &$r) {
            $r['num'] = $idx + 1;
        }
        unset($r);

        // 2. Будуємо Невиконані специфікації (unfulfilled_rows та unf_rows) за правилом толерансу <= 25 т або <= 10%
        $unfulfilledRows = [];
        $unfAllRows = [];
        foreach ($specsDb as $code => $sInfo) {
            $contractTons = (float)($sInfo['contract_tons'] ?? 0);
            $sumInfo = $summaryByCode[$code] ?? [];
            $tList   = $trucksByCode[$code] ?? [];

            if (isset($sumInfo['delivered_kg']) && $sumInfo['delivered_kg'] > 0) {
                $delivTons = $sumInfo['delivered_kg'] / 1000.0;
                $zachTons  = $sumInfo['zachet_kg'] / 1000.0;
            } elseif (!empty($tList)) {
                $delivTons = array_sum(array_column($tList, 'delivered_kg')) / 1000.0;
                $zachTons  = array_sum(array_column($tList, 'zachet_kg')) / 1000.0;
            } else {
                $delivTons = 0.0;
                $zachTons  = 0.0;
            }

            $remTons     = round(max(0.0, $contractTons - $delivTons), 3);
            $pct         = $contractTons > 0 ? round(($delivTons / $contractTons) * 100, 1) : 0.0;
            $truckCnt    = count($tList);
            $tol10Pct    = round(0.10 * $contractTons, 3);
            $isFulfilled = ($delivTons >= $contractTons) || ($remTons <= 25.0) || ($contractTons > 0 && $remTons <= $tol10Pct);

            if ($delivTons <= 0.001) {
                $cat = 'not_started';
                $statusCode = 'pending';
                $statusLbl = '🔴 Не приїхало (0%)';
                $noteTxt = 'Жодної машини не надходило';
            } elseif (!$isFulfilled) {
                $cat = 'in_progress';
                $statusCode = 'in_progress';
                $statusLbl = '🟡 У стадії виконання';
                $noteTxt = sprintf("Завезено %d авто (%.2f т з %.2f т, залишок %.2f т)", $truckCnt, $delivTons, $contractTons, $remTons);
            } else {
                $cat = 'fulfilled';
                $statusCode = 'fulfilled';
                $statusLbl = '🟢 Виконано';
                $noteTxt = sprintf("Виконано (%d авто, %.2f т з %.2f т)", $truckCnt, $delivTons, $contractTons);
            }

            $rowItem = [
                'category'         => $cat,
                'status'           => $statusLbl,
                'status_code'      => $statusCode,
                'status_raw'       => $statusLbl,
                'status_label'     => $statusLbl,
                'supplier'         => $sInfo['supplier'] ?? '',
                'spec'             => $sInfo['spec_display'] ?? $code,
                'spec_display'     => $sInfo['spec_display'] ?? $code,
                'date'             => self::normalizeDate($sInfo['date'] ?? ''),
                'spec_date'        => self::normalizeDate($sInfo['date'] ?? ''),
                'manager'          => $sInfo['manager'] ?? '',
                'terms'            => $sInfo['delivery_terms'] ?? '',
                'delivery_terms'   => $sInfo['delivery_terms'] ?? '',
                'contract'         => round($contractTons, 2),
                'contract_t'       => round($contractTons, 2),
                'contract_tons'    => round($contractTons, 2),
                'delivered'        => round($delivTons, 3),
                'delivered_t'      => round($delivTons, 3),
                'delivered_tons'   => round($delivTons, 3),
                'credit_weight'    => round($zachTons, 3),
                'zachet_tons'      => round($zachTons, 3),
                'remaining'        => $remTons,
                'remaining_t'      => $remTons,
                'remaining_tons'   => $remTons,
                'pct'              => $pct,
                'pct_fulfilled'    => $pct,
                'price_delivered'  => round((float)($sInfo['price_delivery'] ?? 0), 2),
                'price_with_deliv' => round((float)($sInfo['price_delivery'] ?? 0), 2),
                'price_exw'        => round((float)($sInfo['price_no_delivery'] ?? 0), 2),
                'note'             => $noteTxt,
            ];

            $unfAllRows[] = $rowItem;
            if ($cat !== 'fulfilled') {
                $unfulfilledRows[] = $rowItem;
            }
        }

        $sortUnfFn = function ($a, $b) {
            $order = ['not_started' => 0, 'in_progress' => 1, 'fulfilled' => 2];
            $sa = $order[$a['category'] ?? 'in_progress'] ?? 1;
            $sb = $order[$b['category'] ?? 'in_progress'] ?? 1;
            if ($sa !== $sb) return $sa <=> $sb;
            if ($sa === 1 && $a['pct'] !== $b['pct']) return $a['pct'] <=> $b['pct'];
            return strcmp(self::dateSortKey($a['date'] ?? ''), self::dateSortKey($b['date'] ?? ''));
        };
        usort($unfulfilledRows, $sortUnfFn);
        usort($unfAllRows, $sortUnfFn);
        foreach ($unfulfilledRows as $idx => &$u) { $u['num'] = $idx + 1; }
        unset($u);
        foreach ($unfAllRows as $idx => &$u) { $u['num'] = $idx + 1; }
        unset($u);

        // 3. Будуємо Графік завезення по днях (daily_rows)
        $dailyMap = [];
        foreach ($truckRows as $t) {
            $d = self::normalizeDate($t['date'] ?? '') ?: 'Невідомо';
            if (!isset($dailyMap[$d])) {
                $dailyMap[$d] = [
                    'date'         => $d,
                    'trucks_count' => 0,
                    'delivered_kg' => 0.0,
                    'zachet_kg'    => 0.0,
                    'oil_w'        => 0.0,
                    'w_w'          => 0.0,
                    's_w'          => 0.0,
                ];
            }
            $dailyMap[$d]['trucks_count']++;
            $dailyMap[$d]['delivered_kg'] += (float)$t['delivered_kg'];
            $dailyMap[$d]['zachet_kg']    += (float)$t['zachet_kg'];
            $z = (float)$t['zachet_kg'];
            if ($t['oil_fact'] !== null && $z > 0) $dailyMap[$d]['oil_w'] += $t['oil_fact'] * $z;
            if ($t['w_fact'] !== null && $z > 0)   $dailyMap[$d]['w_w']   += $t['w_fact'] * $z;
            if ($t['s_fact'] !== null && $z > 0)   $dailyMap[$d]['s_w']   += $t['s_fact'] * $z;
        }

        $daysSorted = array_keys($dailyMap);
        usort($daysSorted, fn($a, $b) => strcmp(self::dateSortKey($a), self::dateSortKey($b)));

        $dailyRows = [];
        foreach ($daysSorted as $idx => $d) {
            $info   = $dailyMap[$d];
            $delivT = round($info['delivered_kg'] / 1000.0, 3);
            $zachT  = round($info['zachet_kg'] / 1000.0, 3);
            $diffT  = round($delivT - $zachT, 3);
            $diffP  = $delivT > 0 ? round(($diffT / $delivT) * 100, 2) : 0.0;
            $z      = $info['zachet_kg'];
            $avgOil = $z > 0 ? round($info['oil_w'] / $z, 2) : 0.0;
            $avgW   = $z > 0 ? round($info['w_w'] / $z, 2) : 0.0;
            $avgS   = $z > 0 ? round($info['s_w'] / $z, 2) : 0.0;
            $dow    = self::getUkrainianDayOfWeek($d);

            $dailyRows[] = [
                'num'            => $idx + 1,
                'date'           => $d,
                'weekday'        => $dow,
                'day_name'       => $dow,
                'day_of_week'    => $dow,
                'trucks'         => $info['trucks_count'],
                'cars'           => $info['trucks_count'],
                'trucks_count'   => $info['trucks_count'],
                'delivered'      => $delivT,
                'delivered_t'    => $delivT,
                'delivered_tons' => $delivT,
                'credit_weight'  => $zachT,
                'accounting_t'   => $zachT,
                'zachet_tons'    => $zachT,
                'discount'       => $diffT,
                'diff_t'         => $diffT,
                'diff_tons'      => $diffT,
                'discount_pct'   => $diffP,
                'diff_pct'       => $diffP,
                'oil'            => $avgOil,
                'oil_fact'       => $avgOil,
                'moisture'       => $avgW,
                'w_fact'         => $avgW,
                'trash'          => $avgS,
                's_fact'         => $avgS,
            ];
        }

        // 4. Будуємо Натуральну скидку у вазі (сор, волога) за постачальниками (discount_rows / disc_rows)
        $supsMap = [];
        foreach ($truckRows as $t) {
            $sup  = ($t['supplier'] ?? '') ?: 'Без назви';
            $code = $t['code'] ?? '';
            if (!isset($supsMap[$sup])) {
                $supsMap[$sup] = [
                    'supplier'     => $sup,
                    'codes'        => [],
                    'trucks_count' => 0,
                    'delivered_kg' => 0.0,
                    'zachet_kg'    => 0.0,
                    'oil_w'        => 0.0,
                    'w_w'          => 0.0,
                    's_w'          => 0.0,
                ];
            }
            if ($code !== '') $supsMap[$sup]['codes'][$code] = true;
            $supsMap[$sup]['trucks_count']++;
            $supsMap[$sup]['delivered_kg'] += (float)$t['delivered_kg'];
            $supsMap[$sup]['zachet_kg']    += (float)$t['zachet_kg'];
            $z = (float)$t['zachet_kg'];
            if ($t['oil_fact'] !== null && $z > 0) $supsMap[$sup]['oil_w'] += $t['oil_fact'] * $z;
            if ($t['w_fact'] !== null && $z > 0)   $supsMap[$sup]['w_w']   += $t['w_fact'] * $z;
            if ($t['s_fact'] !== null && $z > 0)   $supsMap[$sup]['s_w']   += $t['s_fact'] * $z;
        }

        $supList = array_values($supsMap);
        usort($supList, fn($a, $b) => (($b['delivered_kg'] - $b['zachet_kg']) <=> ($a['delivered_kg'] - $a['zachet_kg'])));

        $discountRows = [];
        foreach ($supList as $idx => $s) {
            $delivT = round($s['delivered_kg'] / 1000.0, 3);
            $zachT  = round($s['zachet_kg'] / 1000.0, 3);
            $diffT  = round($delivT - $zachT, 3);
            $diffP  = $delivT > 0 ? round(($diffT / $delivT) * 100, 2) : 0.0;
            $z      = $s['zachet_kg'];
            $discountRows[] = [
                'num'           => $idx + 1,
                'supplier'      => $s['supplier'],
                'specs_count'   => count($s['codes']),
                'trucks'        => $s['trucks_count'],
                'cars'          => $s['trucks_count'],
                'trucks_count'  => $s['trucks_count'],
                'delivered'     => $delivT,
                'delivered_t'   => $delivT,
                'credit_weight' => $zachT,
                'accounting_t'  => $zachT,
                'discount'      => $diffT,
                'diff_t'        => $diffT,
                'discount_pct'  => $diffP,
                'diff_pct'      => $diffP,
                'moisture'      => $z > 0 ? round($s['w_w'] / $z, 2) : 0.0,
                'trash'         => $z > 0 ? round($s['s_w'] / $z, 2) : 0.0,
                'oil'           => $z > 0 ? round($s['oil_w'] / $z, 2) : 0.0,
            ];
        }

        // 5. Будуємо Реєстр прийомок (помашинно) (truck_rows)
        $formattedTrucks = [];
        foreach ($truckRows as $idx => $t) {
            $code = $t['code'] ?? '';
            $specInfo = $specsDb[$code] ?? [];
            $delivT = round(((float)($t['delivered_kg'] ?? 0)) / 1000.0, 3);
            $zachT  = round(((float)($t['zachet_kg'] ?? 0)) / 1000.0, 3);
            $diffT  = round($delivT - $zachT, 3);
            $baseOil = isset($specInfo['base_oil']) ? (float)$specInfo['base_oil'] : 48.0;
            $oilFact = isset($t['oil_fact']) && $t['oil_fact'] !== null ? round((float)$t['oil_fact'], 2) : null;
            $oilDiff = ($oilFact !== null && $oilFact > 0) ? round($oilFact - $baseOil, 2) : null;

            $formattedTrucks[] = [
                'num'              => (int)($t['num_pp'] ?? ($idx + 1)),
                'supplier'         => $t['supplier'] ?? ($specInfo['supplier'] ?? ''),
                'spec'             => ($specInfo['spec_display'] ?? '') ?: ($t['spec_display'] ?? $code),
                'car_num'          => $t['auto'] ?? '',
                'ttn'              => $t['doc_no'] ?? '',
                'date'             => self::normalizeDate($t['date'] ?? ''),
                'manager'          => $specInfo['manager'] ?? '',
                'terms'            => ($t['terms'] ?? '') ?: ($specInfo['delivery_terms'] ?? ''),
                'delivered_t'      => $delivT,
                'accounting_t'     => $zachT,
                'weight_diff'      => $diffT,
                'price_with_deliv' => isset($specInfo['price_delivery']) ? round((float)$specInfo['price_delivery'], 2) : null,
                'price_no_deliv'   => isset($specInfo['price_no_delivery']) ? round((float)$specInfo['price_no_delivery'], 2) : null,
                'oil_base'         => $baseOil,
                'moisture'         => isset($t['w_fact']) && $t['w_fact'] !== null ? round((float)$t['w_fact'], 2) : null,
                'trash'            => isset($t['s_fact']) && $t['s_fact'] !== null ? round((float)$t['s_fact'], 2) : null,
                'oil_fact'         => $oilFact,
                'oil_diff'         => $oilDiff,
            ];
        }

        // Сортуємо Реєстр прийомок помашинно від нових до старих (сьогоднішня дата зверху таблиці)
        usort($formattedTrucks, function($a, $b) {
            $ka = self::dateSortKey($a['date'] ?? '');
            $kb = self::dateSortKey($b['date'] ?? '');
            if ($ka !== $kb) {
                return strcmp($kb, $ka); // descending date
            }
            return ($b['num'] ?? 0) <=> ($a['num'] ?? 0); // descending truck number
        });

        // Оновлюємо історію змін
        if ($newDisplay !== '') {
            $historyData['last_receipts_datetime'] = $newDisplay;
        }
        if ($newIso !== '') {
            $historyData['last_receipts_iso'] = $newIso;
        }
        self::saveChangesHistory($historyData);

        // Сортуємо події історії змін від нових до старих (сьогоднішня дата зверху таблиці)
        $histEvents = $historyData['events'] ?? [];
        usort($histEvents, function($a, $b) {
            $ka = self::dateTimeSortKey($a['timestamp'] ?? ($a['dt'] ?? ''));
            $kb = self::dateTimeSortKey($b['timestamp'] ?? ($b['dt'] ?? ''));
            return strcmp($kb, $ka);
        });

        $historyRows = [];
        foreach (array_slice($histEvents, 0, 200) as $ev) {
            $historyRows[] = [
                'dt'       => (string)($ev['dt'] ?? $ev['timestamp'] ?? ''),
                'supplier' => (string)($ev['supplier'] ?? ''),
                'spec'     => (string)($ev['spec'] ?? $ev['specification'] ?? ''),
                'manager'  => (string)($ev['manager'] ?? ''),
                'category' => (string)($ev['category_label'] ?? $ev['category'] ?? ''),
                'details'  => (string)($ev['details'] ?? $ev['summary'] ?? ''),
            ];
        }

        $updatedAt = $newDisplay !== '' ? $newDisplay : date('d.m.Y час H:i');
        $finalData = [
            'updated_at'        => $updatedAt,
            'report_datetime'   => $updatedAt,
            'last_receipts_iso' => $historyData['last_receipts_iso'] ?? '',
            'source_subtitle'   => "Дані прийомок 1С станом на {$updatedAt}. Зведено автоматично з 2 файлів (Приход + Специфікація).",
            'agg_rows'          => $aggRows,
            'unfulfilled_rows'  => $unfulfilledRows,
            'unf_rows'          => $unfAllRows,
            'daily_rows'        => $dailyRows,
            'discount_rows'     => $discountRows,
            'disc_rows'         => $discountRows,
            'truck_rows'        => $formattedTrucks,
            'history_events'    => array_slice($histEvents, 0, 200),
            'history_rows'      => $historyRows,
        ];

        file_put_contents(self::getJsonPath(), json_encode($finalData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $loaded = self::loadData();
        self::generateExcelWorkbook($loaded);
        return $loaded;
    }

    /**
     * Завантажити дані з database/semena_data.json та обчислити підсумкові показники (summary)
     */
    public static function loadData(): array {
        $path = self::getJsonPath();
        $data = [
            'updated_at'        => date('d.m.Y H:i'),
            'report_datetime'   => date('d.m.Y H:i'),
            'last_receipts_iso' => '',
            'source_subtitle'   => 'Дані прийомок 1С з Листа погодження.',
            'agg_rows'          => [],
            'unfulfilled_rows'  => [],
            'unf_rows'          => [],
            'daily_rows'        => [],
            'discount_rows'     => [],
            'disc_rows'         => [],
            'truck_rows'        => [],
            'history_events'    => [],
            'history_rows'      => [],
        ];

        if (file_exists($path)) {
            $decoded = json_decode((string)file_get_contents($path), true);
            if (is_array($decoded)) {
                $data = array_merge($data, $decoded);
            }
        }

        if (empty($data['report_datetime']) || $data['report_datetime'] === date('d.m.Y H:i')) {
            $data['report_datetime'] = $data['updated_at'] ?? date('d.m.Y H:i');
        }

        // Якщо history_events порожні, підтягуємо з changes_history.json
        if (empty($data['history_events'])) {
            $hist = self::loadChangesHistory();
            if (!empty($hist['events'])) {
                $data['history_events'] = array_slice(array_reverse($hist['events']), 0, 200);
            }
        }

        // Формуємо history_rows для semena.php
        $historyRows = [];
        foreach (($data['history_events'] ?? []) as $ev) {
            $historyRows[] = [
                'dt'       => (string)($ev['dt'] ?? $ev['timestamp'] ?? ''),
                'supplier' => (string)($ev['supplier'] ?? ''),
                'spec'     => (string)($ev['spec'] ?? $ev['specification'] ?? ''),
                'manager'  => (string)($ev['manager'] ?? ''),
                'category' => (string)($ev['category_label'] ?? $ev['category'] ?? ''),
                'details'  => (string)($ev['details'] ?? $ev['summary'] ?? ''),
            ];
        }
        // Сортуємо history_rows від нових до старих (сьогоднішня дата зверху таблиці)
        usort($historyRows, function($a, $b) {
            $ka = self::dateTimeSortKey($a['dt'] ?? '');
            $kb = self::dateTimeSortKey($b['dt'] ?? '');
            return strcmp($kb, $ka);
        });
        $data['history_rows'] = $historyRows;

        // Сортуємо truck_rows від нових до старих (сьогоднішня дата зверху таблиці)
        if (!empty($data['truck_rows'])) {
            usort($data['truck_rows'], function($a, $b) {
                $ka = self::dateSortKey($a['date'] ?? '');
                $kb = self::dateSortKey($b['date'] ?? '');
                if ($ka !== $kb) {
                    return strcmp($kb, $ka); // descending date
                }
                return ($b['num'] ?? 0) <=> ($a['num'] ?? 0); // descending truck number
            });
        }

        // Нормалізуємо ключі agg_rows
        $normalizedAgg = [];
        foreach (($data['agg_rows'] ?? []) as $idx => $r) {
            $contract      = (float)($r['contract'] ?? $r['contract_t'] ?? $r['contract_tons'] ?? 0);
            $delivered     = (float)($r['delivered'] ?? $r['delivered_t'] ?? $r['weight_delivered'] ?? 0);
            $creditWeight  = (float)($r['credit_weight'] ?? $r['accounting_t'] ?? $r['weight_zachet'] ?? 0);
            $weightDiff    = (float)($r['weight_diff'] ?? round($delivered - $creditWeight, 3));
            $priceDeliv    = (float)($r['price_delivered'] ?? $r['price_with_deliv'] ?? $r['price_delivery'] ?? 0);
            $priceExw      = (float)($r['price_exw'] ?? $r['price_no_deliv'] ?? $r['price_no_delivery'] ?? 0);
            $oilBase       = (float)($r['oil_base'] ?? 48.0);
            $moisture      = (float)($r['moisture'] ?? $r['w_fact'] ?? 0);
            $trash         = (float)($r['trash'] ?? $r['s_fact'] ?? 0);
            $oilFact       = (float)($r['oil_fact'] ?? 0);
            $oilDiff       = (float)($r['oil_diff'] ?? ($oilFact > 0 ? round($oilFact - $oilBase, 2) : 0));
            $specStr       = (string)($r['spec'] ?? $r['specification'] ?? '');
            $termsStr      = (string)($r['terms'] ?? $r['delivery_terms'] ?? '');
            $dNorm         = self::normalizeDate((string)($r['date'] ?? $r['spec_date'] ?? ''));
            $noteRaw       = (string)($r['note'] ?? '');
            if ($noteRaw === 'Без змін') $noteRaw = '';

            $normalizedAgg[] = [
                'num'               => (int)($r['num'] ?? ($idx + 1)),
                'supplier'          => (string)($r['supplier'] ?? ''),
                'spec'              => $specStr,
                'specification'     => $specStr,
                'code'              => (string)($r['code'] ?? ''),
                'date'              => $dNorm,
                'spec_date'         => $dNorm,
                'manager'           => (string)($r['manager'] ?? ''),
                'terms'             => $termsStr,
                'delivery_terms'    => $termsStr,
                'contract'          => $contract,
                'contract_t'        => $contract,
                'contract_tons'     => $contract,
                'delivered'         => $delivered,
                'delivered_t'       => $delivered,
                'weight_delivered'  => $delivered,
                'credit_weight'     => $creditWeight,
                'accounting_t'      => $creditWeight,
                'weight_zachet'     => $creditWeight,
                'weight_diff'       => $weightDiff,
                'price_delivered'   => $priceDeliv,
                'price_with_deliv'  => $priceDeliv,
                'price_delivery'    => $priceDeliv,
                'price_exw'         => $priceExw,
                'price_no_deliv'    => $priceExw,
                'price_no_delivery' => $priceExw,
                'oil_base'          => $oilBase,
                'moisture'          => $moisture,
                'w_fact'            => $moisture,
                'trash'             => $trash,
                's_fact'            => $trash,
                'oil_fact'          => $oilFact,
                'oil_diff'          => $oilDiff,
                'note'              => $noteRaw,
                'change_type'       => (string)($r['change_type'] ?? 'same'),
            ];
        }
        $data['agg_rows'] = $normalizedAgg;

        // Будуємо unf_rows (включаючи виконані) та unfulfilled_rows
        $normalizedUnf = [];
        $allUnfRows = [];
        $notStartedCount = 0;
        $inProgressCount = 0;
        $fulfilledCount  = 0;

        foreach ($normalizedAgg as $idx => $r) {
            $contract  = $r['contract'];
            $delivered = $r['delivered'];
            $creditW   = $r['credit_weight'];
            $rem       = round(max(0.0, $contract - $delivered), 3);
            $pct       = $contract > 0 ? round(($delivered / $contract) * 100, 1) : 0.0;
            $tol10Pct  = round(0.10 * $contract, 3);
            $isFulfilled = ($delivered >= $contract && $contract > 0) || ($contract > 0 && $rem <= 25.0) || ($contract > 0 && $rem <= $tol10Pct) || ($contract <= 0 && $delivered > 0);

            if ($delivered <= 0.001) {
                $cat = 'not_started';
                $statusCode = 'pending';
                $statusRaw = '🔴 Не приїхало (0%)';
                $notStartedCount++;
            } elseif (!$isFulfilled) {
                $cat = 'in_progress';
                $statusCode = 'in_progress';
                $statusRaw = '🟡 У стадії виконання';
                $inProgressCount++;
            } else {
                $cat = 'fulfilled';
                $statusCode = 'fulfilled';
                $statusRaw = '🟢 Виконано';
                $fulfilledCount++;
            }

            $item = [
                'num'              => $idx + 1,
                'category'         => $cat,
                'status'           => $statusRaw,
                'status_code'      => $statusCode,
                'status_raw'       => $statusRaw,
                'status_label'     => $statusRaw,
                'supplier'         => $r['supplier'],
                'spec'             => $r['spec'],
                'spec_display'     => $r['spec'],
                'date'             => $r['date'],
                'spec_date'        => $r['date'],
                'manager'          => $r['manager'],
                'terms'            => $r['terms'],
                'delivery_terms'   => $r['terms'],
                'contract'         => $contract,
                'contract_t'       => $contract,
                'contract_tons'    => $contract,
                'delivered'        => $delivered,
                'delivered_t'      => $delivered,
                'delivered_tons'   => $delivered,
                'credit_weight'    => $creditW,
                'zachet_tons'      => $creditW,
                'remaining'        => $rem,
                'remaining_t'      => $rem,
                'remaining_tons'   => $rem,
                'pct'              => $pct,
                'pct_fulfilled'    => $pct,
                'price_delivered'  => $r['price_delivered'],
                'price_with_deliv' => $r['price_delivered'],
                'price_exw'        => $r['price_exw'],
                'note'             => $r['note'],
            ];

            $allUnfRows[] = $item;
            if ($cat !== 'fulfilled') {
                $normalizedUnf[] = $item;
            }
        }

        $sortFn = function ($a, $b) {
            $order = ['not_started' => 0, 'in_progress' => 1, 'fulfilled' => 2];
            $sa = $order[$a['category']] ?? 1;
            $sb = $order[$b['category']] ?? 1;
            if ($sa !== $sb) return $sa <=> $sb;
            if ($sa === 1 && $a['pct'] !== $b['pct']) return $a['pct'] <=> $b['pct'];
            return strcmp(self::dateSortKey($a['date'] ?? ''), self::dateSortKey($b['date'] ?? ''));
        };
        usort($normalizedUnf, $sortFn);
        usort($allUnfRows, $sortFn);
        foreach ($normalizedUnf as $i => &$u) { $u['num'] = $i + 1; }
        unset($u);
        foreach ($allUnfRows as $i => &$u) { $u['num'] = $i + 1; }
        unset($u);

        $data['unfulfilled_rows'] = $normalizedUnf;
        $data['unf_rows'] = $allUnfRows;

        // Нормалізуємо ключі daily_rows
        $normalizedDaily = [];
        foreach (($data['daily_rows'] ?? []) as $idx => $r) {
            $cars      = (int)($r['cars'] ?? $r['trucks'] ?? $r['trucks_count'] ?? 0);
            $delivered = (float)($r['delivered'] ?? $r['delivered_t'] ?? $r['delivered_tons'] ?? 0);
            $creditW   = (float)($r['credit_weight'] ?? $r['accounting_t'] ?? $r['zachet_tons'] ?? 0);
            $discount  = (float)($r['discount'] ?? $r['diff_t'] ?? $r['diff_tons'] ?? round($delivered - $creditW, 3));
            $discPct   = (float)($r['discount_pct'] ?? $r['diff_pct'] ?? ($delivered > 0 ? round(($discount / $delivered) * 100, 2) : 0));
            $oil       = (float)($r['oil'] ?? $r['oil_fact'] ?? 0);
            $moisture  = (float)($r['moisture'] ?? $r['w_fact'] ?? 0);
            $trash     = (float)($r['trash'] ?? $r['s_fact'] ?? 0);
            $dNorm     = self::normalizeDate((string)($r['date'] ?? ''));
            $dayName   = (string)($r['weekday'] ?? $r['day_name'] ?? $r['day_of_week'] ?? self::getUkrainianDayOfWeek($dNorm));

            $normalizedDaily[] = [
                'num'            => (int)($r['num'] ?? ($idx + 1)),
                'date'           => $dNorm,
                'weekday'        => $dayName,
                'day_name'       => $dayName,
                'day_of_week'    => $dayName,
                'trucks'         => $cars,
                'cars'           => $cars,
                'trucks_count'   => $cars,
                'delivered'      => $delivered,
                'delivered_t'    => $delivered,
                'delivered_tons' => $delivered,
                'credit_weight'  => $creditW,
                'accounting_t'   => $creditW,
                'zachet_tons'    => $creditW,
                'discount'       => $discount,
                'diff_t'         => $discount,
                'diff_tons'      => $discount,
                'discount_pct'   => $discPct,
                'diff_pct'       => $discPct,
                'oil'            => $oil,
                'oil_fact'       => $oil,
                'moisture'       => $moisture,
                'w_fact'         => $moisture,
                'trash'          => $trash,
                's_fact'         => $trash,
            ];
        }
        $data['daily_rows'] = $normalizedDaily;

        // Нормалізуємо discount_rows / disc_rows
        $rawDisc = !empty($data['discount_rows']) ? $data['discount_rows'] : ($data['disc_rows'] ?? []);
        $normalizedDisc = [];
        foreach ($rawDisc as $idx => $s) {
            $cars  = (int)($s['trucks'] ?? $s['cars'] ?? $s['trucks_count'] ?? 0);
            $deliv = (float)($s['delivered'] ?? $s['delivered_t'] ?? 0);
            $cred  = (float)($s['credit_weight'] ?? $s['accounting_t'] ?? 0);
            $disc  = (float)($s['discount'] ?? $s['diff_t'] ?? round($deliv - $cred, 3));
            $pct   = (float)($s['discount_pct'] ?? $s['diff_pct'] ?? ($deliv > 0 ? round(($disc / $deliv) * 100, 2) : 0));

            $normalizedDisc[] = [
                'num'           => (int)($s['num'] ?? ($idx + 1)),
                'supplier'      => (string)($s['supplier'] ?? ''),
                'specs_count'   => (int)($s['specs_count'] ?? 1),
                'trucks'        => $cars,
                'cars'          => $cars,
                'trucks_count'  => $cars,
                'delivered'     => $deliv,
                'delivered_t'   => $deliv,
                'credit_weight' => $cred,
                'accounting_t'  => $cred,
                'discount'      => $disc,
                'diff_t'        => $disc,
                'discount_pct'  => $pct,
                'diff_pct'      => $pct,
                'moisture'      => (float)($s['moisture'] ?? 0),
                'trash'         => (float)($s['trash'] ?? 0),
                'oil'           => (float)($s['oil'] ?? 0),
            ];
        }
        $data['discount_rows'] = $normalizedDisc;
        $data['disc_rows']     = $normalizedDisc;

        // Якщо truck_rows порожні, але є semena_raw_prihod.json — підтягуємо помашинний реєстр
        if (empty($data['truck_rows']) && file_exists(self::getRawPrihodPath())) {
            $rawPrihod = json_decode((string)file_get_contents(self::getRawPrihodPath()), true);
            $rawSpecs  = file_exists(self::getRawSpecPath())
                ? (json_decode((string)file_get_contents(self::getRawSpecPath()), true) ?: [])
                : [];
            if (!empty($rawPrihod['truck_rows'])) {
                $tFormatted = [];
                foreach ($rawPrihod['truck_rows'] as $idx => $t) {
                    $code = $t['code'] ?? '';
                    $specInfo = $rawSpecs[$code] ?? [];
                    $delivT = round(((float)($t['delivered_kg'] ?? 0)) / 1000.0, 3);
                    $zachT  = round(((float)($t['zachet_kg'] ?? 0)) / 1000.0, 3);
                    $diffT  = round($delivT - $zachT, 3);
                    $baseOil = isset($specInfo['base_oil']) ? (float)$specInfo['base_oil'] : 48.0;
                    $oilFact = isset($t['oil_fact']) && $t['oil_fact'] !== null ? round((float)$t['oil_fact'], 2) : null;
                    $oilDiff = ($oilFact !== null && $oilFact > 0) ? round($oilFact - $baseOil, 2) : null;

                    $tFormatted[] = [
                        'num'              => $idx + 1,
                        'supplier'         => $t['supplier'] ?? ($specInfo['supplier'] ?? ''),
                        'spec'             => ($specInfo['spec_display'] ?? '') ?: ($t['spec_display'] ?? $code),
                        'car_num'          => $t['auto'] ?? '',
                        'ttn'              => $t['doc_no'] ?? '',
                        'date'             => self::normalizeDate($t['date'] ?? ''),
                        'manager'          => $specInfo['manager'] ?? '',
                        'terms'            => ($t['terms'] ?? '') ?: ($specInfo['delivery_terms'] ?? ''),
                        'delivered_t'      => $delivT,
                        'accounting_t'     => $zachT,
                        'weight_diff'      => $diffT,
                        'price_with_deliv' => isset($specInfo['price_delivery']) ? round((float)$specInfo['price_delivery'], 2) : null,
                        'price_no_deliv'   => isset($specInfo['price_no_delivery']) ? round((float)$specInfo['price_no_delivery'], 2) : null,
                        'oil_base'         => $baseOil,
                        'moisture'         => isset($t['w_fact']) && $t['w_fact'] !== null ? round((float)$t['w_fact'], 2) : null,
                        'trash'            => isset($t['s_fact']) && $t['s_fact'] !== null ? round((float)$t['s_fact'], 2) : null,
                        'oil_fact'         => $oilFact,
                        'oil_diff'         => $oilDiff,
                    ];
                }
                $data['truck_rows'] = $tFormatted;
            }
        }

        // Обчислюємо зведені KPI (summary)
        $totalContract   = 0.0;
        $totalDelivered  = 0.0;
        $totalCredit     = 0.0;
        $wOilSum         = 0.0;
        $wOilWeight      = 0.0;
        $wMoistSum       = 0.0;
        $wMoistWeight    = 0.0;
        $wTrashSum       = 0.0;
        $wTrashWeight    = 0.0;
        $wPriceDelivSum  = 0.0;
        $wPriceDelivW    = 0.0;
        $wPriceExwSum    = 0.0;
        $wPriceExwW      = 0.0;
        $deviationsCount = 0;
        $suppliersSet    = [];

        foreach ($normalizedAgg as $r) {
            $totalContract  += $r['contract'];
            $totalDelivered += $r['delivered'];
            $totalCredit    += $r['credit_weight'];
            if ($r['supplier'] !== '') {
                $suppliersSet[$r['supplier']] = true;
            }
            $w = $r['delivered'] > 0 ? $r['delivered'] : $r['contract'];
            if ($w > 0) {
                if ($r['oil_fact'] > 0) { $wOilSum += $w * $r['oil_fact']; $wOilWeight += $w; }
                if ($r['moisture'] > 0) { $wMoistSum += $w * $r['moisture']; $wMoistWeight += $w; }
                if ($r['trash'] > 0)    { $wTrashSum += $w * $r['trash']; $wTrashWeight += $w; }
                if ($r['price_delivered'] > 0) { $wPriceDelivSum += $w * $r['price_delivered']; $wPriceDelivW += $w; }
                if ($r['price_exw'] > 0)       { $wPriceExwSum += $w * $r['price_exw']; $wPriceExwW += $w; }
            }
            if (abs($r['oil_diff']) >= 1.0) {
                $deviationsCount++;
            }
        }

        $unfulfilledRem = 0.0;
        foreach ($normalizedUnf as $u) {
            $unfulfilledRem += $u['remaining'];
        }

        $dailyCars      = 0;
        $dailyDelivered = 0.0;
        $dailyCredit    = 0.0;
        $dailyDiscount  = 0.0;
        foreach ($normalizedDaily as $d) {
            $dailyCars      += $d['cars'];
            $dailyDelivered += $d['delivered'];
            $dailyCredit    += $d['credit_weight'];
            $dailyDiscount  += $d['discount'];
        }

        $avgOil   = $wOilWeight > 0 ? round($wOilSum / $wOilWeight, 2) : 49.30;
        $avgMoist = $wMoistWeight > 0 ? round($wMoistSum / $wMoistWeight, 2) : 7.01;
        $avgTrash = $wTrashWeight > 0 ? round($wTrashSum / $wTrashWeight, 2) : 3.24;

        $data['summary'] = [
            // Ключі для semena.php
            'total_specs'         => count($normalizedAgg),
            'unique_suppliers'    => count($suppliersSet),
            'total_contract_t'    => round($totalContract, 2),
            'total_delivered_t'   => round($totalDelivered, 2),
            'total_accounting_t'  => round($totalCredit, 2),
            'total_diff_t'        => round($totalDelivered - $totalCredit, 2),
            'total_trucks'        => count($data['truck_rows']) > 0 ? count($data['truck_rows']) : $dailyCars,
            'not_started_count'   => $notStartedCount,
            'in_progress_count'   => $inProgressCount,
            'fulfilled_count'     => $fulfilledCount,
            // Ключі для metrics_calculator.php
            'specs_count'         => count($normalizedAgg),
            'suppliers_count'     => count($suppliersSet),
            'total_contract'      => round($totalContract, 2),
            'total_delivered'     => round($totalDelivered, 2),
            'total_credit_weight' => round($totalCredit, 2),
            'total_weight_diff'   => round($totalDelivered - $totalCredit, 2),
            'fulfillment_pct'     => $totalContract > 0 ? round(($totalDelivered / $totalContract) * 100, 1) : 0.0,
            'avg_oil'             => $avgOil,
            'avg_oil_diff'        => round($avgOil - 48.0, 2),
            'avg_moisture'        => $avgMoist,
            'avg_trash'           => $avgTrash,
            'avg_price_delivered' => $wPriceDelivW > 0 ? round($wPriceDelivSum / $wPriceDelivW, 2) : 0.0,
            'avg_price_exw'       => $wPriceExwW > 0 ? round($wPriceExwSum / $wPriceExwW, 2) : 0.0,
            'unfulfilled_count'   => count($normalizedUnf),
            'unfulfilled_rem'     => round($unfulfilledRem, 2),
            'active_specs'        => $inProgressCount,
            'pending_specs'       => $notStartedCount,
            'deviations_count'    => $deviationsCount,
            'days_count'          => count($normalizedDaily),
            'daily_cars'          => $dailyCars,
            'daily_delivered'     => round($dailyDelivered, 2),
            'daily_credit_weight' => round($dailyCredit, 2),
            'daily_discount'      => round($dailyDiscount, 2),
        ];

        return $data;
    }

    /**
     * Оновити database/semena_data.json із розпарсених вкладок готового файлу «Лист_погодження.xlsx»
     */
    public static function updateFromSheets(array $sheets, ?string $sourceFilePath = null, bool $enforceFreshness = false): array {
        $existing = self::loadData();
        $aggRows = [];
        $unfRows = [];
        $dailyRows = [];
        $discountRows = [];
        $updatedAt = $existing['updated_at'] ?? date('d.m.Y H:i');
        $subtitle  = $existing['source_subtitle'] ?? '';

        foreach ($sheets as $sName => $rows) {
            $sLower = mb_strtolower($sName, 'UTF-8');

            // 1. Вкладка «Лист погодження» / «Зведена відомість»
            if ((strpos($sLower, 'погоджен') !== false || strpos($sLower, 'зведен') !== false || strpos($sLower, 'всі_специфікації') !== false || strpos($sLower, 'все_спецификации') !== false) && strpos($sLower, 'відхилен') === false && strpos($sLower, 'невиконан') === false) {
                if (!empty($rows[1][0])) {
                    $sub = trim((string)$rows[1][0]);
                    $subtitle = $sub;
                    if (preg_match('/(\d{2}\.\d{2}\.\d{4}(?:\s+час\s+\d{1,2}:\d{2})?)/u', $sub, $m)) {
                        $updatedAt = $m[1];
                    }
                }
                foreach ($rows as $ri => $r) {
                    $num = trim((string)($r[0] ?? ''));
                    if (!is_numeric($num)) continue;
                    $sup = trim((string)($r[1] ?? ''));
                    if ($sup === '' || mb_stripos($sup, 'всього', 0, 'UTF-8') !== false || mb_stripos($sup, 'разом', 0, 'UTF-8') !== false) {
                        continue;
                    }

                    $contract  = (float)self::parseFloatVal($r[6] ?? 0);
                    $delivered = (float)self::parseFloatVal($r[7] ?? 0);
                    $creditW   = (float)self::parseFloatVal($r[8] ?? 0);
                    $oilBase   = (float)(self::parseFloatVal($r[12] ?? 48.0) ?: 48.0);
                    $oilFact   = (float)self::parseFloatVal($r[15] ?? 0);
                    $specStr   = trim((string)($r[2] ?? ''));

                    $aggRows[] = [
                        'num'             => (int)$num,
                        'supplier'        => $sup,
                        'spec'            => $specStr,
                        'code'            => self::extractCodeAndSpecNum($specStr)['code'],
                        'date'            => self::normalizeDate(trim((string)($r[3] ?? ''))),
                        'manager'         => trim((string)($r[4] ?? '')),
                        'terms'           => trim((string)($r[5] ?? '')),
                        'contract'        => round($contract, 2),
                        'delivered'       => round($delivered, 3),
                        'credit_weight'   => round($creditW, 3),
                        'weight_diff'     => round($delivered - $creditW, 3),
                        'price_delivered' => round((float)self::parseFloatVal($r[10] ?? 0), 2),
                        'price_exw'       => round((float)self::parseFloatVal($r[11] ?? 0), 2),
                        'oil_base'        => round($oilBase, 2),
                        'moisture'        => round((float)self::parseFloatVal($r[13] ?? 0), 2),
                        'trash'           => round((float)self::parseFloatVal($r[14] ?? 0), 2),
                        'oil_fact'        => round($oilFact, 2),
                        'oil_diff'        => $oilFact > 0 ? round($oilFact - $oilBase, 2) : 0.0,
                        'note'            => trim((string)($r[17] ?? '')),
                    ];
                }
            }

            // 2. Вкладка «Невиконані специфікації»
            if (strpos($sLower, 'невиконан') !== false || strpos($sLower, 'невыполнен') !== false) {
                foreach ($rows as $ri => $r) {
                    $num = trim((string)($r[0] ?? ''));
                    $statusRaw = trim((string)($r[1] ?? ''));
                    $sup = trim((string)($r[2] ?? ''));
                    if ($sup === '' || !is_numeric($num)) continue;

                    $contract  = (float)self::parseFloatVal($r[7] ?? 0);
                    $delivered = (float)self::parseFloatVal($r[8] ?? 0);
                    $creditW   = (float)self::parseFloatVal($r[9] ?? 0);
                    $rem       = max(0.0, round($contract - $delivered, 3));
                    $pct       = $contract > 0 ? round(($delivered / $contract) * 100, 1) : 0.0;
                    $isPending = ($delivered <= 0.001) || (mb_stripos($statusRaw, 'не приїхало', 0, 'UTF-8') !== false);

                    $unfRows[] = [
                        'num'             => (int)$num,
                        'status_raw'      => $statusRaw,
                        'status'          => $isPending ? 'pending' : 'in_progress',
                        'supplier'        => $sup,
                        'spec'            => trim((string)($r[3] ?? '')),
                        'date'            => self::normalizeDate(trim((string)($r[4] ?? ''))),
                        'manager'         => trim((string)($r[5] ?? '')),
                        'terms'           => trim((string)($r[6] ?? '')),
                        'contract'        => round($contract, 2),
                        'delivered'       => round($delivered, 3),
                        'credit_weight'   => round($creditW, 3),
                        'remaining'       => $rem,
                        'pct'             => $pct,
                        'price_delivered' => round((float)self::parseFloatVal($r[12] ?? 0), 2),
                        'price_exw'       => round((float)self::parseFloatVal($r[13] ?? 0), 2),
                        'note'            => trim((string)($r[14] ?? '')),
                    ];
                }
            }

            // 3. Вкладка «Графік завезення (по днях)»
            if (strpos($sLower, 'графік') !== false || strpos($sLower, 'график') !== false || strpos($sLower, 'по днях') !== false) {
                foreach ($rows as $ri => $r) {
                    $num  = trim((string)($r[0] ?? ''));
                    $dStr = self::normalizeDate(trim((string)($r[1] ?? '')));
                    if (!is_numeric($num) || !preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $dStr)) continue;

                    $cars      = (int)self::parseFloatVal($r[3] ?? 0);
                    $delivered = (float)self::parseFloatVal($r[4] ?? 0);
                    $creditW   = (float)self::parseFloatVal($r[5] ?? 0);
                    $disc      = round($delivered - $creditW, 3);
                    $discPct   = $delivered > 0 ? round(($disc / $delivered) * 100, 2) : 0.0;

                    $dailyRows[] = [
                        'num'           => (int)$num,
                        'date'          => $dStr,
                        'day_name'      => trim((string)($r[2] ?? '')),
                        'cars'          => $cars,
                        'delivered'     => round($delivered, 3),
                        'credit_weight' => round($creditW, 3),
                        'discount'      => $disc,
                        'discount_pct'  => $discPct,
                        'oil'           => round((float)self::parseFloatVal($r[8] ?? 0), 2),
                        'moisture'      => round((float)self::parseFloatVal($r[9] ?? 0), 2),
                        'trash'         => round((float)self::parseFloatVal($r[10] ?? 0), 2),
                    ];
                }
            }

            // 4. Вкладка «Натуральна скидка (сор, волога)»
            if (strpos($sLower, 'натуральн') !== false || strpos($sLower, 'скидк') !== false) {
                foreach ($rows as $ri => $r) {
                    $num = trim((string)($r[0] ?? ''));
                    $sup = trim((string)($r[1] ?? ''));
                    if ($sup === '' || !is_numeric($num) || mb_stripos($sup, 'разом', 0, 'UTF-8') !== false) continue;

                    $deliv = (float)self::parseFloatVal($r[4] ?? 0);
                    $cred  = (float)self::parseFloatVal($r[5] ?? 0);
                    $disc  = round($deliv - $cred, 3);

                    $discountRows[] = [
                        'num'           => (int)$num,
                        'supplier'      => $sup,
                        'specs_count'   => (int)self::parseFloatVal($r[2] ?? 0),
                        'cars'          => (int)self::parseFloatVal($r[3] ?? 0),
                        'delivered'     => round($deliv, 3),
                        'credit_weight' => round($cred, 3),
                        'discount'      => $disc,
                        'discount_pct'  => $deliv > 0 ? round(($disc / $deliv) * 100, 2) : 0.0,
                        'moisture'      => round((float)self::parseFloatVal($r[8] ?? 0), 2),
                        'trash'         => round((float)self::parseFloatVal($r[9] ?? 0), 2),
                        'oil'           => round((float)self::parseFloatVal($r[10] ?? 0), 2),
                    ];
                }
            }

            // 5. Вкладка «Реєстр прийомок» (помашинний реєстр)
            if (strpos($sLower, 'реєстр') !== false || strpos($sLower, 'реестр') !== false || strpos($sLower, 'прийомок') !== false || strpos($sLower, 'приемок') !== false || strpos($sLower, 'помашин') !== false) {
                $parsedTruckRows = [];
                foreach ($rows as $ri => $r) {
                    $num = trim((string)($r[0] ?? ''));
                    if (!is_numeric($num)) continue;
                    $dStr = self::normalizeDate(trim((string)($r[5] ?? '')));
                    if (empty($dStr)) continue;

                    $delivT = (float)self::parseFloatVal($r[8] ?? 0);
                    $zachT  = (float)self::parseFloatVal($r[9] ?? 0);
                    $diffT  = (float)self::parseFloatVal($r[10] ?? ($delivT - $zachT));

                    $parsedTruckRows[] = [
                        'num'              => (int)$num,
                        'supplier'         => trim((string)($r[1] ?? '')),
                        'spec'             => trim((string)($r[2] ?? '')),
                        'car_num'          => trim((string)($r[3] ?? '')),
                        'ttn'              => trim((string)($r[4] ?? '')),
                        'date'             => $dStr,
                        'manager'          => trim((string)($r[6] ?? '')),
                        'terms'            => trim((string)($r[7] ?? '')),
                        'delivered_t'      => round($delivT, 3),
                        'accounting_t'     => round($zachT, 3),
                        'weight_diff'      => round($diffT, 3),
                        'price_with_deliv' => self::parseFloatVal($r[11] ?? null),
                        'price_no_deliv'   => self::parseFloatVal($r[12] ?? null),
                        'oil_base'         => (float)(self::parseFloatVal($r[13] ?? 48.0) ?: 48.0),
                        'moisture'         => self::parseFloatVal($r[14] ?? null),
                        'trash'            => self::parseFloatVal($r[15] ?? null),
                        'oil_fact'         => self::parseFloatVal($r[16] ?? null),
                        'oil_diff'         => self::parseFloatVal($r[17] ?? null),
                    ];
                }
                if (!empty($parsedTruckRows)) {
                    $truckRows = $parsedTruckRows;
                }
            }
        }

        $truckRowsFinal = !empty($truckRows) ? $truckRows : ($existing['truck_rows'] ?? []);

        // Якщо в Реєстрі прийомок є дати, яких немає в Графіку завезення (наприклад, свіжі авто за 14.10)
        if (!empty($truckRowsFinal)) {
            $existingDailyDates = array_column($dailyRows, 'date');
            $truckDatesMap = [];
            foreach ($truckRowsFinal as $t) {
                $td = $t['date'] ?? '';
                if (empty($td)) continue;
                if (!isset($truckDatesMap[$td])) {
                    $truckDatesMap[$td] = [
                        'cars'      => 0,
                        'delivered' => 0.0,
                        'credit_w'  => 0.0,
                        'oil_sum'   => 0.0,
                        'moist_sum' => 0.0,
                        'trash_sum' => 0.0,
                        'oil_cnt'   => 0,
                    ];
                }
                $truckDatesMap[$td]['cars']++;
                $deliv = (float)($t['delivered_t'] ?? 0);
                $cred  = (float)($t['accounting_t'] ?? 0);
                $truckDatesMap[$td]['delivered'] += $deliv;
                $truckDatesMap[$td]['credit_w']  += $cred;
                if (isset($t['oil_fact']) && $t['oil_fact'] > 0) {
                    $truckDatesMap[$td]['oil_sum'] += (float)$t['oil_fact'] * $deliv;
                    $truckDatesMap[$td]['oil_cnt'] += $deliv;
                }
                if (isset($t['moisture']) && $t['moisture'] > 0) {
                    $truckDatesMap[$td]['moist_sum'] += (float)$t['moisture'] * $deliv;
                }
                if (isset($t['trash']) && $t['trash'] > 0) {
                    $truckDatesMap[$td]['trash_sum'] += (float)$t['trash'] * $deliv;
                }
            }

            foreach ($truckDatesMap as $td => $tStat) {
                if (!in_array($td, $existingDailyDates, true)) {
                    $deliv = round($tStat['delivered'], 3);
                    $cred  = round($tStat['credit_w'], 3);
                    $disc  = round($deliv - $cred, 3);
                    $discP = $deliv > 0 ? round(($disc / $deliv) * 100, 2) : 0.0;
                    $avgOil = $tStat['oil_cnt'] > 0 ? round($tStat['oil_sum'] / $tStat['oil_cnt'], 2) : 49.0;
                    $avgM   = $tStat['oil_cnt'] > 0 ? round($tStat['moist_sum'] / $tStat['oil_cnt'], 2) : 7.0;
                    $avgT   = $tStat['oil_cnt'] > 0 ? round($tStat['trash_sum'] / $tStat['oil_cnt'], 2) : 3.0;
                    $dow    = self::getUkrainianDayOfWeek($td);

                    $dailyRows[] = [
                        'num'           => count($dailyRows) + 1,
                        'date'          => $td,
                        'weekday'       => $dow,
                        'day_name'      => $dow,
                        'cars'          => $tStat['cars'],
                        'trucks'        => $tStat['cars'],
                        'trucks_count'  => $tStat['cars'],
                        'delivered'     => $deliv,
                        'delivered_t'   => $deliv,
                        'credit_weight' => $cred,
                        'accounting_t'  => $cred,
                        'discount'      => $disc,
                        'discount_pct'  => $discP,
                        'oil'           => $avgOil,
                        'moisture'      => $avgM,
                        'trash'         => $avgT,
                    ];
                }
            }

            usort($dailyRows, fn($a, $b) => strcmp(self::dateSortKey($a['date'] ?? ''), self::dateSortKey($b['date'] ?? '')));
            foreach ($dailyRows as $idx => &$dr) {
                $dr['num'] = $idx + 1;
            }
            unset($dr);
        }

        if ($sourceFilePath && file_exists($sourceFilePath)) {
            @copy($sourceFilePath, self::getXlsxPath());
        }

        if (!empty($truckRowsFinal)) {
            usort($truckRowsFinal, function($a, $b) {
                $ka = self::dateSortKey($a['date'] ?? '');
                $kb = self::dateSortKey($b['date'] ?? '');
                if ($ka !== $kb) {
                    return strcmp($kb, $ka); // descending date
                }
                return ($b['num'] ?? 0) <=> ($a['num'] ?? 0); // descending truck number
            });
        }

        $newData = [
            'updated_at'       => $updatedAt,
            'report_datetime'  => $updatedAt,
            'source_subtitle'  => $subtitle,
            'agg_rows'         => !empty($aggRows) ? $aggRows : ($existing['agg_rows'] ?? []),
            'unfulfilled_rows' => !empty($unfRows) ? $unfRows : ($existing['unfulfilled_rows'] ?? []),
            'daily_rows'       => !empty($dailyRows) ? $dailyRows : ($existing['daily_rows'] ?? []),
            'discount_rows'    => !empty($discountRows) ? $discountRows : ($existing['discount_rows'] ?? []),
            'truck_rows'       => $truckRowsFinal,
            'history_events'   => $existing['history_events'] ?? [],
        ];

        file_put_contents(self::getJsonPath(), json_encode($newData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $loaded = self::loadData();
        self::generateExcelWorkbook($loaded);
        return $loaded;
    }

    /**
     * Автоматично формує повноцінний Excel-файл Лист_погодження.xlsx з усіма вкладками
     */
    public static function generateExcelWorkbook(array $data): bool {
        $xlsxPath = self::getXlsxPath();
        $dir = dirname($xlsxPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        if (!class_exists('ZipArchive')) {
            return false;
        }

        $aggRows   = $data['agg_rows'] ?? [];
        $unfRows   = $data['unfulfilled_rows'] ?? ($data['unf_rows'] ?? []);
        $dailyRows = $data['daily_rows'] ?? [];
        $discRows  = $data['discount_rows'] ?? ($data['disc_rows'] ?? []);
        $truckRows = $data['truck_rows'] ?? [];
        $repDt     = $data['report_datetime'] ?? ($data['updated_at'] ?? date('d.m.Y H:i'));

        $tmpFile = tempnam(sys_get_temp_dir(), 'xlsx_');
        $zip = new ZipArchive();
        if ($zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return false;
        }

        // 1. [Content_Types].xml
        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
            '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
            '<Default Extension="xml" ContentType="application/xml"/>' .
            '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
            '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' .
            '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
            '<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
            '<Override PartName="/xl/worksheets/sheet3.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
            '<Override PartName="/xl/worksheets/sheet4.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
            '<Override PartName="/xl/worksheets/sheet5.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
            '</Types>';
        $zip->addFromString('[Content_Types].xml', $contentTypes);

        // 2. _rels/.rels
        $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
            '</Relationships>';
        $zip->addFromString('_rels/.rels', $rootRels);

        // 3. xl/_rels/workbook.xml.rels
        $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' .
            '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>' .
            '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet3.xml"/>' .
            '<Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet4.xml"/>' .
            '<Relationship Id="rId5" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet5.xml"/>' .
            '<Relationship Id="rId6" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>' .
            '</Relationships>';
        $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);

        // 4. xl/workbook.xml
        $wbXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
            '<sheets>' .
            '<sheet name="Лист погодження" sheetId="1" r:id="rId1"/>' .
            '<sheet name="Невиконані специфікації" sheetId="2" r:id="rId2"/>' .
            '<sheet name="Графік завезення (по днях)" sheetId="3" r:id="rId3"/>' .
            '<sheet name="Натуральна скидка" sheetId="4" r:id="rId4"/>' .
            '<sheet name="Реєстр прийомок" sheetId="5" r:id="rId5"/>' .
            '</sheets>' .
            '</workbook>';
        $zip->addFromString('xl/workbook.xml', $wbXml);

        // 5. xl/styles.xml
        $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
            '<fonts count="2"><font><name val="Calibri"/><sz val="11"/></font><font><b/><name val="Calibri"/><sz val="11"/></font></fonts>' .
            '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>' .
            '<borders count="1"><border><left/><right/><top/><bottom/></border></borders>' .
            '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>' .
            '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0"/></cellXfs>' .
            '</styleSheet>';
        $zip->addFromString('xl/styles.xml', $stylesXml);

        // Функція для генерації рядка XML листа
        $buildSheetXml = function (array $headers, array $tableRows, ?string $titleStr = null): string {
            $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
                '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
                '<sheetData>';
            $rNum = 1;
            if ($titleStr !== null) {
                $xml .= '<row r="' . $rNum . '"><c r="A' . $rNum . '" t="inlineStr" s="1"><is><t>' . htmlspecialchars($titleStr, ENT_XML1, 'UTF-8') . '</t></is></c></row>';
                $rNum++;
            }

            // Headers
            $xml .= '<row r="' . $rNum . '">';
            $cCol = 'A';
            foreach ($headers as $h) {
                $xml .= '<c r="' . $cCol . $rNum . '" t="inlineStr" s="1"><is><t>' . htmlspecialchars((string)$h, ENT_XML1, 'UTF-8') . '</t></is></c>';
                $cCol++;
            }
            $xml .= '</row>';
            $rNum++;

            // Data rows
            foreach ($tableRows as $row) {
                $xml .= '<row r="' . $rNum . '">';
                $cCol = 'A';
                foreach ($row as $cellVal) {
                    $ref = $cCol . $rNum;
                    if ($cellVal === null || $cellVal === '') {
                        // порожня
                    } elseif (is_numeric($cellVal)) {
                        $xml .= '<c r="' . $ref . '"><v>' . (float)$cellVal . '</v></c>';
                    } else {
                        $xml .= '<c r="' . $ref . '" t="inlineStr"><is><t>' . htmlspecialchars((string)$cellVal, ENT_XML1, 'UTF-8') . '</t></is></c>';
                    }
                    $cCol++;
                }
                $xml .= '</row>';
                $rNum++;
            }

            $xml .= '</sheetData></worksheet>';
            return $xml;
        };

        // Аркуш 1: Лист погодження
        $h1 = ['№', 'Контрагент', 'Специфікація', 'Дата', 'Менеджер', 'Умови', 'Договір (т)', 'Завезено (т)', 'Залікова (т)', 'Різниця (т)', 'Ціна з дост.', 'Ціна без дост.', 'Масло База', 'Волога, %', 'Сор, %', 'Факт олійність', 'Відхилення', 'Примітка (Аудит)'];
        $d1 = [];
        foreach ($aggRows as $i => $r) {
            $d1[] = [$i + 1, $r['supplier'] ?? '', $r['spec'] ?? '', $r['date'] ?? '', $r['manager'] ?? '', $r['terms'] ?? '', $r['contract'] ?? 0, $r['delivered'] ?? 0, $r['credit_weight'] ?? 0, $r['weight_diff'] ?? 0, $r['price_delivered'] ?? '', $r['price_exw'] ?? '', $r['oil_base'] ?? 48.0, $r['moisture'] ?? 0, $r['trash'] ?? 0, $r['oil_fact'] ?? 0, $r['oil_diff'] ?? 0, $r['note'] ?? ''];
        }
        $zip->addFromString('xl/worksheets/sheet1.xml', $buildSheetXml($h1, $d1, "Лист погодження постачання соняшнику (1С станом на {$repDt})"));

        // Аркуш 2: Невиконані специфікації
        $h2 = ['№', 'Статус', 'Контрагент', 'Специфікація', 'Дата', 'Менеджер', 'Умови', 'Договір (т)', 'Завезено (т)', 'Залікова (т)', 'Залишок (т)', '% викон.', 'Ціна з дост.', 'Ціна без дост.', 'Примітка'];
        $d2 = [];
        foreach ($unfRows as $i => $r) {
            $d2[] = [$i + 1, $r['status_raw'] ?? ($r['status'] ?? ''), $r['supplier'] ?? '', $r['spec'] ?? '', $r['date'] ?? '', $r['manager'] ?? '', $r['terms'] ?? '', $r['contract'] ?? 0, $r['delivered'] ?? 0, $r['credit_weight'] ?? 0, $r['remaining'] ?? 0, ($r['pct'] ?? 0) . '%', $r['price_delivered'] ?? '', $r['price_exw'] ?? '', $r['note'] ?? ''];
        }
        $zip->addFromString('xl/worksheets/sheet2.xml', $buildSheetXml($h2, $d2, "Невиконані договори та специфікації (станом на {$repDt})"));

        // Аркуш 3: Графік завезення
        $h3 = ['№', 'Дата прийомки', 'День тижня', 'Кількість авто', 'Фізичний завіз (т)', 'Залікова вага (т)', 'Скидка (т)', 'Скидка (%)', 'Олійність (%)', 'Вологість (%)', 'Сорність (%)'];
        $d3 = [];
        foreach ($dailyRows as $i => $r) {
            $d3[] = [$i + 1, $r['date'] ?? '', $r['weekday'] ?? ($r['day_name'] ?? ''), $r['cars'] ?? ($r['trucks'] ?? 0), $r['delivered'] ?? 0, $r['credit_weight'] ?? 0, $r['discount'] ?? 0, ($r['discount_pct'] ?? 0) . '%', $r['oil'] ?? 0, $r['moisture'] ?? 0, $r['trash'] ?? 0];
        }
        $zip->addFromString('xl/worksheets/sheet3.xml', $buildSheetXml($h3, $d3, "Графік щоденного завезення соняшнику (станом на {$repDt})"));

        // Аркуш 4: Натуральна скидка
        $h4 = ['№', 'Постачальник', 'Кількість спец.', 'Кількість авто', 'Завезено (т)', 'Залікова (т)', 'Скидка (т)', 'Скидка (%)', 'Вологість (%)', 'Сорність (%)', 'Олійність (%)'];
        $d4 = [];
        foreach ($discRows as $i => $r) {
            $d4[] = [$i + 1, $r['supplier'] ?? '', $r['specs_count'] ?? 1, $r['trucks'] ?? ($r['cars'] ?? 0), $r['delivered'] ?? 0, $r['credit_weight'] ?? 0, $r['discount'] ?? 0, ($r['discount_pct'] ?? 0) . '%', $r['moisture'] ?? 0, $r['trash'] ?? 0, $r['oil'] ?? 0];
        }
        $zip->addFromString('xl/worksheets/sheet4.xml', $buildSheetXml($h4, $d4, "Натуральна скидка за сором та вологою (станом на {$repDt})"));

        // Аркуш 5: Реєстр прийомок (помашинно)
        $h5 = ['№', 'Постачальник', 'Специфікація', '№ Авто', 'ТТН / Документ', 'Дата', 'Менеджер', 'Умови', 'Фіз. вага (т)', 'Залікова (т)', 'Різниця (т)', 'Ціна з дост.', 'Ціна без дост.', 'Масло База', 'Волога, %', 'Сор, %', 'Факт олійність', 'Відхилення'];
        $d5 = [];
        foreach ($truckRows as $i => $r) {
            $d5[] = [$i + 1, $r['supplier'] ?? '', $r['spec'] ?? '', $r['car_num'] ?? '', $r['ttn'] ?? '', $r['date'] ?? '', $r['manager'] ?? '', $r['terms'] ?? '', $r['delivered_t'] ?? 0, $r['accounting_t'] ?? 0, $r['weight_diff'] ?? 0, $r['price_with_deliv'] ?? '', $r['price_no_deliv'] ?? '', $r['oil_base'] ?? 48.0, $r['moisture'] ?? '', $r['trash'] ?? '', $r['oil_fact'] ?? '', $r['oil_diff'] ?? ''];
        }
        $zip->addFromString('xl/worksheets/sheet5.xml', $buildSheetXml($h5, $d5, "Помашинний реєстр прийомок соняшнику 1С (станом на {$repDt})"));

        $zip->close();
        @copy($tmpFile, $xlsxPath);
        @unlink($tmpFile);
        return true;
    }
}
