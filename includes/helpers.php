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
 * Форматирование минут в строку "X ч Y мин"
 */
function format_duration_minutes(int $minutes): string {
    if ($minutes <= 0) {
        return '0 мин';
    }
    $hours = intdiv($minutes, 60);
    $mins = $minutes % 60;
    
    if ($hours > 0 && $mins > 0) {
        return "{$hours} ч {$mins} мин";
    } elseif ($hours > 0) {
        return "{$hours} ч";
    } else {
        return "{$mins} мин";
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
