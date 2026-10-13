<?php
/**
 * Допоміжний модуль для сторінки «СЕМЕНА (Лист погодження — Звіт з постачання соняшнику)»
 * Забезпечує:
 * - Читання та збереження повного набору вкладок у database/semena_data.json
 * - Перевірку актуальності дати 1С (блокування відкату назад старішим файлом)
 * - Автоматичне оновлення всіх 6 вкладок при завантаженні свіжого Лист_погодження.xlsx
 */

class SemenaHelper {

    public static function getJsonPath(): string {
        return __DIR__ . '/../database/semena_data.json';
    }

    public static function getExcelPath(): string {
        return __DIR__ . '/../database/Лист_погодження.xlsx';
    }

    public static function loadData(): array {
        $path = self::getJsonPath();
        if (file_exists($path)) {
            $raw = file_get_contents($path);
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [
            'report_datetime' => '12.10.2026 09:34',
            'subtitle'        => 'Дані прийомок 1С станом на 12.10.2026 час 09:34.',
            'agg_rows'        => [],
            'unf_rows'        => [],
            'disc_rows'       => [],
            'daily_rows'      => [],
            'truck_rows'      => [],
            'history_rows'    => [],
        ];
    }

    /**
     * Перетворює рядок дати/часу "12.10.2026 09:34" або "12.10.2026 час 09:34" у ISO-рядок "2026-10-12 09:34" для порівняння
     */
    public static function toSortableIso(string $dtStr): string {
        if (preg_match('/(\d{1,2})\.(\d{1,2})\.(\d{2,4})(?:.*?(\d{1,2}):(\d{2}))?/u', $dtStr, $m)) {
            $d = str_pad($m[1], 2, '0', STR_PAD_LEFT);
            $mo = str_pad($m[2], 2, '0', STR_PAD_LEFT);
            $y = strlen($m[3]) === 2 ? '20' . $m[3] : $m[3];
            $h = isset($m[4]) && $m[4] !== '' ? str_pad($m[4], 2, '0', STR_PAD_LEFT) : '00';
            $mi = isset($m[5]) && $m[5] !== '' ? str_pad($m[5], 2, '0', STR_PAD_LEFT) : '00';
            return "{$y}-{$mo}-{$d} {$h}:{$mi}";
        }
        return '';
    }

    private static function fval($v, float $default = 0.0): float {
        if ($v === null || $v === '') return $default;
        $clean = str_replace([' ', ','], ['', '.'], trim((string)$v));
        return is_numeric($clean) ? (float)$clean : $default;
    }

    private static function sval($v): string {
        if ($v === null) return '';
        return trim((string)$v);
    }

    /**
     * Парсить вкладки файлу Лист_погодження.xlsx та оновлює database/semena_data.json
     * Якщо файл має старішу дату 1С — викидає виняток (захист від завантаження старого приходу)
     */
    public static function updateFromSheets(array $sheetsData, string $uploadedTmpPath = '', bool $enforceStalenessCheck = true): array {
        $sheetMain = null;
        $sheetUnf  = null;
        $sheetDisc = null;
        $sheetDaily = null;
        $sheetTrucks = null;
        $sheetHist = null;

        foreach ($sheetsData as $sName => $rows) {
            $low = mb_strtolower($sName, 'UTF-8');
            if (strpos($low, 'погоджен') !== false || strpos($low, 'согласован') !== false) {
                $sheetMain = $rows;
            } elseif (strpos($low, 'невиконан') !== false || strpos($low, 'невыполнен') !== false) {
                $sheetUnf = $rows;
            } elseif (strpos($low, 'скидк') !== false || strpos($low, 'скидка') !== false || strpos($low, 'сор') !== false) {
                $sheetDisc = $rows;
            } elseif (strpos($low, 'графік') !== false || strpos($low, 'график') !== false || strpos($low, 'по днях') !== false) {
                $sheetDaily = $rows;
            } elseif (strpos($low, 'помашин') !== false || strpos($low, 'прийомок') !== false) {
                $sheetTrucks = $rows;
            } elseif (strpos($low, 'історі') !== false || strpos($low, 'истори') !== false) {
                $sheetHist = $rows;
            }
        }

        if (!$sheetMain) {
            return ['updated' => false, 'reason' => 'Не знайдено аркуш «Лист погодження»'];
        }

        // Визначаємо дату та час 1С з підзаголовка
        $subtitle = '';
        $newReportDt = '';
        foreach (array_slice($sheetMain, 0, 5) as $r) {
            $line = implode(' ', array_filter($r, 'is_string'));
            if (strpos($line, 'станом на') !== false || strpos($line, 'Дані прийомок') !== false) {
                $subtitle = trim($line);
                if (preg_match('/станом на\s*(\d{1,2}\.\d{1,2}\.\d{2,4})(?:\s*час\s*(\d{1,2}:\d{2}))?/iu', $line, $m)) {
                    $newReportDt = $m[1] . (!empty($m[2]) ? ' ' . $m[2] : '');
                }
                break;
            }
        }

        $existing = self::loadData();
        $existingDt = $existing['report_datetime'] ?? '';

        if ($enforceStalenessCheck && $newReportDt !== '' && $existingDt !== '') {
            $newIso = self::toSortableIso($newReportDt);
            $oldIso = self::toSortableIso($existingDt);
            if ($newIso !== '' && $oldIso !== '' && $newIso < $oldIso) {
                throw new Exception(
                    "⛔ Завантаження заблоковано (застарілий файл прийомок 1С): дата у файлі ({$newReportDt}) старіша за актуальну дату в базі ({$existingDt}). Оновлення назад у часі заборонено, щоб уникнути помилкового зменшення ваги."
                );
            }
        }

        if ($newReportDt === '') {
            $newReportDt = $existingDt ?: date('d.m.Y H:i');
        }
        if ($subtitle === '') {
            $subtitle = $existing['subtitle'] ?? "Дані прийомок 1С станом на {$newReportDt}.";
        }

        // 1. Лист погодження
        $aggRows = [];
        foreach ($sheetMain as $idx => $r) {
            if ($idx < 4) continue;
            $sup = self::sval($r[1] ?? '');
            $spec = self::sval($r[2] ?? '');
            if ($sup === '' || mb_strpos(mb_strtolower($sup, 'UTF-8'), 'всього') === 0 || mb_strpos(mb_strtolower($spec, 'UTF-8'), 'всього') === 0) {
                continue;
            }
            $contract = round(self::fval($r[6] ?? 0), 2);
            $deliv    = round(self::fval($r[7] ?? 0), 3);
            $zach     = round(self::fval($r[8] ?? 0), 3);
            $diffW    = round($deliv - $zach, 3);
            $oilBase  = round(self::fval($r[12] ?? 48.0, 48.0), 2);
            $wFact    = round(self::fval($r[13] ?? 0), 2);
            $sFact    = round(self::fval($r[14] ?? 0), 2);
            $oilFact  = round(self::fval($r[15] ?? 0), 2);
            $oilDiff  = $oilFact > 0 ? round($oilFact - $oilBase, 2) : 0.0;

            $aggRows[] = [
                'num'           => count($aggRows) + 1,
                'supplier'      => $sup,
                'spec'          => $spec,
                'date'          => self::sval($r[3] ?? ''),
                'manager'       => self::sval($r[4] ?? ''),
                'terms'         => self::sval($r[5] ?? ''),
                'contract'      => $contract,
                'delivered'     => $deliv,
                'zachet'        => $zach,
                'weight_diff'   => $diffW,
                'price_dost'    => round(self::fval($r[10] ?? 0), 2),
                'price_no_dost' => round(self::fval($r[11] ?? 0), 2),
                'oil_base'      => $oilBase,
                'w_fact'        => $wFact,
                's_fact'        => $sFact,
                'oil_fact'      => $oilFact,
                'oil_diff'      => $oilDiff,
                'note'          => self::sval($r[17] ?? '') ?: 'Без змін',
            ];
        }

        // 2. Невиконані специфікації
        $unfRows = [];
        $seenSpecs = [];
        if ($sheetUnf) {
            foreach ($sheetUnf as $idx => $r) {
                if ($idx < 4) continue;
                $st   = self::sval($r[1] ?? '');
                $sup  = self::sval($r[2] ?? '');
                $spec = self::sval($r[3] ?? '');
                if ($sup === '' || mb_strpos(mb_strtolower($sup, 'UTF-8'), 'всього') === 0 || mb_strpos(mb_strtolower($st, 'UTF-8'), 'всього') === 0) {
                    continue;
                }
                $contract = round(self::fval($r[7] ?? 0), 2);
                $deliv    = round(self::fval($r[8] ?? 0), 3);
                $zach     = round(self::fval($r[9] ?? 0), 3);
                $rem      = round(max(0.0, $contract - $deliv), 3);
                $pct      = $contract > 0 ? round(($deliv / $contract) * 100, 1) : 0.0;
                $stCode   = (strpos($st, 'Не приїхало') !== false || $deliv <= 0.001) ? 'not_started' : 'in_progress';
                $seenSpecs[$spec] = true;

                $unfRows[] = [
                    'num'           => count($unfRows) + 1,
                    'status'        => $st,
                    'status_code'   => $stCode,
                    'supplier'      => $sup,
                    'spec'          => $spec,
                    'date'          => self::sval($r[4] ?? ''),
                    'manager'       => self::sval($r[5] ?? ''),
                    'terms'         => self::sval($r[6] ?? ''),
                    'contract'      => $contract,
                    'delivered'     => $deliv,
                    'zachet'        => $zach,
                    'remaining'     => $rem,
                    'pct'           => $pct,
                    'price_dost'    => round(self::fval($r[12] ?? 0), 2),
                    'price_no_dost' => round(self::fval($r[13] ?? 0), 2),
                    'note'          => self::sval($r[14] ?? ''),
                ];
            }
        }

        foreach ($aggRows as $ar) {
            if (!isset($seenSpecs[$ar['spec']]) && $ar['contract'] > 0) {
                $rem = round(max(0.0, $ar['contract'] - $ar['delivered']), 3);
                $pct = round(($ar['delivered'] / $ar['contract']) * 100, 1);
                $unfRows[] = [
                    'num'           => count($unfRows) + 1,
                    'status'        => '🟢 Виконано (в межах толерансу)',
                    'status_code'   => 'fulfilled',
                    'supplier'      => $ar['supplier'],
                    'spec'          => $ar['spec'],
                    'date'          => $ar['date'],
                    'manager'       => $ar['manager'],
                    'terms'         => $ar['terms'],
                    'contract'      => $ar['contract'],
                    'delivered'     => $ar['delivered'],
                    'zachet'        => $ar['zachet'],
                    'remaining'     => $rem,
                    'pct'           => $pct,
                    'price_dost'    => $ar['price_dost'],
                    'price_no_dost' => $ar['price_no_dost'],
                    'note'          => sprintf('Виконано (%s%%): завезено %.2f т з %.2f т', $pct, $ar['delivered'], $ar['contract']),
                ];
            }
        }

        // 3. Натуральна скидка (сор, волога)
        $discRows = $existing['disc_rows'] ?? [];
        if ($sheetDisc) {
            $discRows = [];
            foreach ($sheetDisc as $idx => $r) {
                if ($idx < 4) continue;
                $sup = self::sval($r[1] ?? '');
                $lowS = mb_strtolower($sup, 'UTF-8');
                if ($sup === '' || strpos($lowS, 'разом') === 0 || strpos($lowS, 'всього') === 0) continue;
                $deliv = round(self::fval($r[4] ?? 0), 3);
                $zach  = round(self::fval($r[5] ?? 0), 3);
                $diffT = round($deliv - $zach, 3);
                $diffP = $deliv > 0 ? round(($diffT / $deliv) * 100, 2) : 0.0;
                $discRows[] = [
                    'num'          => count($discRows) + 1,
                    'supplier'     => $sup,
                    'specs_count'  => (int)self::fval($r[2] ?? 0),
                    'trucks_count' => (int)self::fval($r[3] ?? 0),
                    'delivered'    => $deliv,
                    'zachet'       => $zach,
                    'diff_t'       => $diffT,
                    'diff_pct'     => $diffP,
                    'w_fact'       => round(self::fval($r[8] ?? 0), 2),
                    's_fact'       => round(self::fval($r[9] ?? 0), 2),
                    'oil_fact'     => round(self::fval($r[10] ?? 0), 2),
                ];
            }
        }

        // 4. Графік завезення (по днях)
        $dailyRows = $existing['daily_rows'] ?? [];
        if ($sheetDaily) {
            $dailyRows = [];
            foreach ($sheetDaily as $idx => $r) {
                if ($idx < 4) continue;
                $dtS = self::sval($r[1] ?? '');
                $lowD = mb_strtolower($dtS, 'UTF-8');
                if ($dtS === '' || strpos($lowD, 'разом') === 0 || strpos($lowD, 'всього') === 0) continue;
                $deliv = round(self::fval($r[4] ?? 0), 3);
                $zach  = round(self::fval($r[5] ?? 0), 3);
                $diffT = round($deliv - $zach, 3);
                $diffP = $deliv > 0 ? round(($diffT / $deliv) * 100, 2) : 0.0;
                $dailyRows[] = [
                    'num'       => count($dailyRows) + 1,
                    'date'      => $dtS,
                    'day'       => self::sval($r[2] ?? ''),
                    'trucks'    => (int)self::fval($r[3] ?? 0),
                    'delivered' => $deliv,
                    'zachet'    => $zach,
                    'diff_t'    => $diffT,
                    'diff_pct'  => $diffP,
                    'oil'       => round(self::fval($r[8] ?? 0), 2),
                    'w'         => round(self::fval($r[9] ?? 0), 2),
                    's'         => round(self::fval($r[10] ?? 0), 2),
                ];
            }
        }

        // 5. Реєстр прийомок (помашинно)
        $truckRows = $existing['truck_rows'] ?? [];
        if ($sheetTrucks) {
            $truckRows = [];
            foreach ($sheetTrucks as $idx => $r) {
                if ($idx < 4) continue;
                $sup = self::sval($r[1] ?? '');
                $lowT = mb_strtolower($sup, 'UTF-8');
                if ($sup === '' || strpos($lowT, 'разом') === 0 || strpos($lowT, 'всього') === 0) continue;
                $deliv = round(self::fval($r[8] ?? 0), 3);
                $zach  = round(self::fval($r[9] ?? 0), 3);
                $oilB  = round(self::fval($r[13] ?? 48.0, 48.0), 2);
                $oilF  = round(self::fval($r[16] ?? 0), 2);
                $truckRows[] = [
                    'num'           => count($truckRows) + 1,
                    'supplier'      => $sup,
                    'spec'          => self::sval($r[2] ?? ''),
                    'car_num'       => self::sval($r[3] ?? ''),
                    'ttn'           => self::sval($r[4] ?? ''),
                    'date'          => self::sval($r[5] ?? ''),
                    'manager'       => self::sval($r[6] ?? ''),
                    'terms'         => self::sval($r[7] ?? ''),
                    'delivered'     => $deliv,
                    'zachet'        => $zach,
                    'weight_diff'   => round($deliv - $zach, 3),
                    'price_dost'    => round(self::fval($r[11] ?? 0), 2),
                    'price_no_dost' => round(self::fval($r[12] ?? 0), 2),
                    'oil_base'      => $oilB,
                    'w_fact'        => round(self::fval($r[14] ?? 0), 2),
                    's_fact'        => round(self::fval($r[15] ?? 0), 2),
                    'oil_fact'      => $oilF,
                    'oil_diff'      => $oilF > 0 ? round($oilF - $oilB, 2) : 0.0,
                ];
            }
        }

        // 6. Історія змін
        $historyRows = $existing['history_rows'] ?? [];
        if ($sheetHist) {
            $historyRows = [];
            foreach ($sheetHist as $idx => $r) {
                if ($idx < 4) continue;
                $dtS = self::sval($r[1] ?? '');
                $sup = self::sval($r[2] ?? '');
                if ($dtS === '' && $sup === '') continue;
                $historyRows[] = [
                    'num'      => count($historyRows) + 1,
                    'datetime' => $dtS,
                    'supplier' => $sup,
                    'spec'     => self::sval($r[3] ?? ''),
                    'manager'  => self::sval($r[4] ?? ''),
                    'category' => self::sval($r[5] ?? ''),
                    'details'  => self::sval($r[6] ?? ''),
                ];
            }
        }

        $newData = [
            'report_datetime' => $newReportDt,
            'subtitle'        => $subtitle,
            'agg_rows'        => $aggRows,
            'unf_rows'        => $unfRows,
            'disc_rows'       => $discRows,
            'daily_rows'      => $dailyRows,
            'truck_rows'      => $truckRows,
            'history_rows'    => $historyRows,
        ];

        @file_put_contents(self::getJsonPath(), json_encode($newData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        if ($uploadedTmpPath !== '' && file_exists($uploadedTmpPath)) {
            @copy($uploadedTmpPath, self::getExcelPath());
        }

        return [
            'updated'         => true,
            'report_datetime' => $newReportDt,
            'specs_count'     => count($aggRows),
            'trucks_count'    => count($truckRows),
            'days_count'      => count($dailyRows),
        ];
    }
}
