<?php
/**
 * Вспомогательные функции форматирования и расчета статусов
 */

/**
 * Загрузка конфигурации приложения
 */
function get_config(): array {
    static $config = null;
    if ($config === null) {
        $configFile = __DIR__ . '/../config.php';
        $sampleFile = __DIR__ . '/../config.sample.php';
        
        if (file_exists($configFile)) {
            $config = require $configFile;
        } elseif (file_exists($sampleFile)) {
            $config = require $sampleFile;
        } else {
            $config = [];
        }

        // Встановлюємо часовий пояс за конфігурацією (за замовчуванням Europe/Kyiv)
        $tz = $config['timezone'] ?? 'Europe/Kyiv';
        if (!empty($tz)) {
            date_default_timezone_set($tz);
        }
    }
    return $config;
}

// Застосовуємо часовий пояс одразу при підключенні helpers.php
get_config();

/**
 * Форматирование веса в тоннах
 */
function format_tonnes(float $value, int $decimals = 1): string {
    return number_format($value, $decimals, ',', ' ') . ' т';
}

/**
 * Форматирование процентов
 */
function format_percent(float $value, int $decimals = 2): string {
    return number_format($value, $decimals, ',', ' ') . '%';
}

/**
 * Форматирование мощности (кВт)
 */
function format_power(float $value, int $decimals = 0): string {
    return number_format($value, $decimals, ',', ' ') . ' кВт';
}

/**
 * Форматирование объема воды (м3)
 */
function format_water(float $value, int $decimals = 1): string {
    return number_format($value, $decimals, ',', ' ') . ' м³';
}

/**
 * Межі поточної виробничої доби (з 08:00 до 08:00 наступного дня)
 */
function get_operational_day_bounds(?DateTimeInterface $now = null): array {
    $tz = new DateTimeZone('Europe/Kyiv');
    $dt = $now ? (new DateTime($now->format('Y-m-d H:i:s'), $tz)) : new DateTime('now', $tz);
    
    $hour = (int)$dt->format('G');
    if ($hour >= 8) {
        $start = (clone $dt)->setTime(8, 0, 0);
        $end   = (clone $start)->modify('+1 day');
    } else {
        $end   = (clone $dt)->setTime(8, 0, 0);
        $start = (clone $end)->modify('-1 day');
    }

    return [
        'start'      => $start->format('Y-m-d H:i:s'),
        'end'        => $end->format('Y-m-d H:i:s'),
        'start_fmt'  => $start->format('d.m H:i'),
        'end_fmt'    => $end->format('d.m H:i'),
        'label'      => $start->format('d.m.Y 08:00') . ' — ' . $end->format('d.m.Y 08:00'),
    ];
}

/**
 * Форматування хвилин у рядок "X год Y хв"
 */
function format_duration_minutes(int $minutes): string {
    if ($minutes <= 0) {
        return '0 хв';
    }
    $hours = intdiv($minutes, 60);
    $mins = $minutes % 60;
    
    if ($hours > 0 && $mins > 0) {
        return "{$hours} год {$mins} хв";
    } elseif ($hours > 0) {
        return "{$hours} год";
    } else {
        return "{$mins} хв";
    }
}

/**
 * Определение CSS-класса для показателя потерь подработки
 */
function get_conditioning_loss_class(float $lossPct, array $thresholds): string {
    $warning = $thresholds['conditioning_loss_warning_pct'] ?? 2.5;
    $danger  = $thresholds['conditioning_loss_danger_pct'] ?? 3.5;
    
    if ($lossPct >= $danger) {
        return 'status-danger';
    } elseif ($lossPct >= $warning) {
        return 'status-warning';
    }
    return 'status-normal';
}

/**
 * Определение CSS-класса для показателя потерь переработки
 */
function get_production_loss_class(float $lossPct, array $thresholds): string {
    $warning = $thresholds['production_loss_warning_pct'] ?? 1.2;
    $danger  = $thresholds['production_loss_danger_pct'] ?? 1.8;
    
    if ($lossPct >= $danger) {
        return 'status-danger';
    } elseif ($lossPct >= $warning) {
        return 'status-warning';
    }
    return 'status-normal';
}

/**
 * Определение CSS-класса для среднего времени нахождения грузовика
 */
function get_truck_stay_class(int $minutes, array $thresholds): string {
    $warning = $thresholds['truck_stay_warning_minutes'] ?? 180;
    $danger  = $thresholds['truck_stay_danger_minutes'] ?? 240;
    
    if ($minutes >= $danger) {
        return 'status-danger';
    } elseif ($minutes >= $warning) {
        return 'status-warning';
    }
    return 'status-normal';
}

/**
 * Форматирование даты/времени для отображения на UI
 */
function format_datetime(string $dtString): string {
    $time = strtotime($dtString);
    if (!$time) return $dtString;
    return date('d.m.Y H:i', $time);
}

/**
 * Класифікація вантажу за 4 цільовими категоріями заводу: Насіння, Олія, Шрот, Лузга
 */
function categorize_cargo(string $rawCargo): string {
    $c = mb_strtolower(trim($rawCargo));
    if (preg_match('/масл|олі|oil/u', $c)) {
        return 'Олія';
    } elseif (preg_match('/шрот|жмых|макух|meal/u', $c)) {
        return 'Шрот';
    } elseif (preg_match('/лузг|лушп|пеллет|топлив|husk/u', $c)) {
        return 'Лузга';
    }
    return 'Насіння';
}

/**
 * Автоматичний менеджер місяців та періодів заводу
 * Забезпечує динамічне визначення активного місяця без ручного переписування коду щомісяця
 */
class MonthHelper {
    public const MONTHS_UA = [
        1  => ['name' => 'Січень',   'short' => 'Січ', 'slug' => 'jan'],
        2  => ['name' => 'Лютий',    'short' => 'Лют', 'slug' => 'feb'],
        3  => ['name' => 'Березень', 'short' => 'Бер', 'slug' => 'mar'],
        4  => ['name' => 'Квітень',  'short' => 'Кві', 'slug' => 'apr'],
        5  => ['name' => 'Травень',  'short' => 'Тра', 'slug' => 'may'],
        6  => ['name' => 'Червень',  'short' => 'Чер', 'slug' => 'jun'],
        7  => ['name' => 'Липень',   'short' => 'Лип', 'slug' => 'jul'],
        8  => ['name' => 'Серпень',  'short' => 'Сер', 'slug' => 'aug'],
        9  => ['name' => 'Вересень', 'short' => 'Вер', 'slug' => 'sep'],
        10 => ['name' => 'Жовтень',  'short' => 'Жов', 'slug' => 'oct'],
        11 => ['name' => 'Листопад', 'short' => 'Лис', 'slug' => 'nov'],
        12 => ['name' => 'Грудень',  'short' => 'Гру', 'slug' => 'dec'],
    ];

    /**
     * Визначає поточний активний місяць та рік заводу на основі:
     * 1) Останніх дат у базі даних та звітах (СВОД 1С, енергетика, облік пари)
     * 2) Поточного системного місяця date('Y-m')
     */
    public static function getActiveMonthYear(): array {
        static $cached = null;
        if ($cached !== null) return $cached;

        $maxDate = date('Y-m-d'); // календарний мінімум

        // 1. Дата останнього звіту 1С (СВОД)
        $svodFile = __DIR__ . '/../database/svod_live_stocks.json';
        if (file_exists($svodFile)) {
            $raw = @file_get_contents($svodFile);
            if ($raw) {
                $j = json_decode($raw, true);
                if (!empty($j['report_date']) && preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $j['report_date'], $m)) {
                    $d = "{$m[3]}-{$m[2]}-{$m[1]}";
                    if ($d > $maxDate) $maxDate = $d;
                }
            }
        }

        // 2. Остання дата ручного обліку енергетика
        $manualFile = __DIR__ . '/../database/energy_manual_entries.json';
        if (file_exists($manualFile)) {
            $raw = @file_get_contents($manualFile);
            if ($raw) {
                $j = json_decode($raw, true);
                if (is_array($j) && !empty($j)) {
                    $keys = array_filter(array_keys($j), fn($k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $k));
                    if (!empty($keys)) {
                        $mK = max($keys);
                        if ($mK > $maxDate) $maxDate = $mK;
                    }
                }
            }
        }

        // 3. Облік пари
        $steamFile = __DIR__ . '/../database/steam_data.json';
        if (file_exists($steamFile)) {
            $raw = @file_get_contents($steamFile);
            if ($raw) {
                $j = json_decode($raw, true);
                $summs = $j['monthly_summary'] ?? [];
                if (!empty($summs) && !empty($summs[0]['key']) && preg_match('/^\d{4}-\d{2}$/', $summs[0]['key'])) {
                    $d = $summs[0]['key'] . '-01';
                    if ($d > substr($maxDate, 0, 7) . '-01') {
                        $maxDate = $d;
                    }
                }
            }
        }

        $y = (int)date('Y', strtotime($maxDate));
        $m = (int)date('n', strtotime($maxDate));
        $key = sprintf('%04d-%02d', $y, $m);
        $mMeta = self::MONTHS_UA[$m] ?? ['name' => 'Місяць ' . $m, 'short' => (string)$m, 'slug' => 'm' . $m];

        $cached = [
            'year'       => $y,
            'month'      => $m,
            'key'        => $key,
            'name'       => $mMeta['name'],
            'full_name'  => "{$mMeta['name']} {$y}",
            'short'      => $mMeta['short'] . "'" . substr((string)$y, 2),
            'slug'       => $mMeta['slug'],
            'period_key' => "month_{$mMeta['slug']}",
            'is_current' => true,
        ];
        return $cached;
    }

    /**
     * Динамічно розпізнає будь-який рядок періоду як місяць:
     * - 'month' => активний місяць
     * - 'month_nov', 'month_dec', 'month_jan' тощо
     * - '2026-11', '2026-12', 'month_2026_11', 'month_2026-11'
     */
    public static function resolvePeriod(string $period): ?array {
        $active = self::getActiveMonthYear();

        if ($period === 'month') {
            return $active;
        }

        // Формати: '2026-11', '2026_11', 'month_2026_11', 'month_2026-11'
        if (preg_match('/(?:month_)?(20\d{2})[-_](\d{1,2})$/', $period, $pm)) {
            $y = (int)$pm[1];
            $m = (int)$pm[2];
            if ($m >= 1 && $m <= 12) {
                $mMeta = self::MONTHS_UA[$m] ?? ['name' => 'Місяць ' . $m, 'short' => (string)$m, 'slug' => 'm' . $m];
                $key = sprintf('%04d-%02d', $y, $m);
                return [
                    'year'       => $y,
                    'month'      => $m,
                    'key'        => $key,
                    'name'       => $mMeta['name'],
                    'full_name'  => "{$mMeta['name']} {$y}",
                    'short'      => $mMeta['short'] . "'" . substr((string)$y, 2),
                    'slug'       => $mMeta['slug'],
                    'period_key' => ($key === $active['key']) ? 'month' : "month_{$mMeta['slug']}",
                    'is_current' => ($key === $active['key']),
                ];
            }
        }

        // Формати: 'month_nov', 'month_dec', 'month_sep_2025'
        if (preg_match('/^month_([a-z]{3})(?:_(\d{4}))?$/', $period, $sm)) {
            $slug = $sm[1];
            $yr = !empty($sm[2]) ? (int)$sm[2] : $active['year'];
            foreach (self::MONTHS_UA as $mNum => $meta) {
                if ($meta['slug'] === $slug) {
                    $key = sprintf('%04d-%02d', $yr, $mNum);
                    return [
                        'year'       => $yr,
                        'month'      => $mNum,
                        'key'        => $key,
                        'name'       => $meta['name'],
                        'full_name'  => "{$meta['name']} {$yr}",
                        'short'      => $meta['short'] . "'" . substr((string)$yr, 2),
                        'slug'       => $slug,
                        'period_key' => $period,
                        'is_current' => ($key === $active['key']),
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Повертає список доступних місяців (поточний + архів)
     */
    public static function getSelectableMonths(): array {
        $active = self::getActiveMonthYear();
        $months = [];

        $curTs = strtotime("{$active['key']}-01");
        for ($i = 0; $i < 16; $i++) {
            $ts = strtotime("-{$i} month", $curTs);
            $y = (int)date('Y', $ts);
            $m = (int)date('n', $ts);
            $key = sprintf('%04d-%02d', $y, $m);
            $meta = self::MONTHS_UA[$m] ?? ['name' => 'Місяць ' . $m, 'short' => (string)$m, 'slug' => 'm' . $m];
            $isCurrent = ($i === 0);
            $label = "{$meta['name']} {$y}" . ($isCurrent ? ' (Поточний факт)' : '');
            $periodKey = "month_{$meta['slug']}" . ($y !== $active['year'] ? "_{$y}" : "");

            $months[] = [
                'key'        => $key,
                'period_key' => $periodKey,
                'name'       => $meta['name'],
                'full_name'  => "{$meta['name']} {$y}",
                'label'      => $label,
                'year'       => $y,
                'month'      => $m,
                'is_current' => $isCurrent,
            ];
        }

        return $months;
    }

    /**
     * Рендерить HTML <option> для випадаючих списків місяців
     */
    public static function renderMonthOptions(?string $selectedVal = null, string $valueField = 'key'): string {
        $months = self::getSelectableMonths();
        $html = '';
        foreach ($months as $idx => $m) {
            $val = ($valueField === 'period_key') ? $m['period_key'] : $m['key'];
            $isSelected = false;
            if ($selectedVal !== null) {
                $isSelected = ($selectedVal === $val || $selectedVal === $m['key'] || $selectedVal === $m['period_key'] || ($selectedVal === 'month' && $m['is_current']));
            } else {
                $isSelected = ($idx === 0);
            }
            $selAttr = $isSelected ? ' selected' : '';
            $html .= "<option value=\"{$val}\"{$selAttr}>" . htmlspecialchars($m['label']) . "</option>\n";
        }
        return $html;
    }
}
