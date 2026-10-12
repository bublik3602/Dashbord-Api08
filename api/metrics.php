<?php
/**
 * REST API: Получение актуальных показателей дашборда
 * Метод: GET
 * Параметры: ?period=shift|day|week|month (по умолчанию: day)
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/metrics_calculator.php';

try {
    $period = filter_input(INPUT_GET, 'period', FILTER_DEFAULT) ?? 'day';
    $validPeriods = ['shift', 'day', 'week', 'month'];
    if (!in_array($period, $validPeriods, true)) {
        $period = 'day';
    }

    $calculator = new MetricsCalculator();
    $data = $calculator->getDashboardData($period);

    echo json_encode([
        'status'  => 'success',
        'data'    => $data,
        'cached'  => false,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
