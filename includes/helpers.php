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
    }
    return $config;
}

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
