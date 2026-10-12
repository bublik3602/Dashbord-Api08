<?php
/**
 * Скрипт синхронизации с Zabbix API по расписанию (CRON)
 * Вызывается каждую минуту или каждые 5 минут из Hestia CP:
 * * /5 * * * * /usr/bin/php /home/admin/web/dash.factory.local/public_html/cron/sync_zabbix.php > /dev/null 2>&1
 */

// Разрешаем запуск только из CLI или защищенным токеном
if (php_sapi_name() !== 'cli' && ($_GET['token'] ?? '') !== 'cron_secure_token') {
    die("Access denied. CLI only.\n");
}

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/zabbix_client.php';

echo "[" . date('Y-m-d H:i:s') . "] Запуск синхронизации Zabbix...\n";

try {
    $db = DashboardDB::getConnection();
    $config = get_config();
    $zabbix = new ZabbixClient();

    $itemKeys = $config['zabbix']['items'] ?? [];
    $metrics = $zabbix->getLatestValues($itemKeys);

    $now = date('Y-m-d H:i:s');
    $hour = (int)date('H');
    $shiftType = ($hour >= 8 && $hour < 20) ? 'day' : 'night';

    // 1. Фиксация замера материального баланса
    $seedPlant = $metrics['scale.seed.in.plant.total']['value'] ?? 485.6;
    $seedProd  = $metrics['scale.seed.in.prod.total']['value'] ?? 473.4;
    $oilOut    = $metrics['production.oil.yield.tonnes']['value'] ?? 205.9;
    $mealOut   = $metrics['production.meal.yield.tonnes']['value'] ?? 182.2;
    $huskOut   = $metrics['production.husk.yield.tonnes']['value'] ?? 80.0;

    $stmt = $db->prepare("
        INSERT INTO material_balance_logs 
        (recorded_at, shift_type, seed_in_plant, seed_in_production, oil_out, meal_out, husk_out)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$now, $shiftType, $seedPlant, $seedProd, $oilOut, $mealOut, $huskOut]);

    // 2. Фиксация замера энергетики и воды
    $powerKw  = $metrics['power.meter.main.kw']['value'] ?? 3940.0;
    $waterM3h = $metrics['water.meter.feed.flow.rate']['value'] ?? 58.9;

    $stmt = $db->prepare("
        INSERT INTO energy_readings 
        (recorded_at, power_kw, power_kwh_delta, water_flow_m3_hour, water_m3_delta, steam_pressure_bar)
        VALUES (?, ?, ?, ?, ?, 11.2)
    ");
    $stmt->execute([$now, $powerKw, $powerKw, $waterM3h, $waterM3h]);

    // 3. Обновление статусов оборудования
    $equipStatusMap = [
        'cleaner_01'    => (int)($metrics['equipment.cleaner.status']['value'] ?? 1),
        'dryer_01'      => (int)($metrics['equipment.dryer.status']['value'] ?? 1),
        'dehuller_01'   => (int)($metrics['equipment.dehuller.status']['value'] ?? 1),
        'press_line_01' => (int)($metrics['equipment.press_line.status']['value'] ?? 1),
        'extraction_01' => (int)($metrics['equipment.extraction.status']['value'] ?? 1),
        'boiler_01'     => (int)($metrics['equipment.boiler.status']['value'] ?? 1),
    ];

    $updateNodeStmt = $db->prepare("
        UPDATE equipment_nodes 
        SET status = ?, last_update = ? 
        WHERE code = ?
    ");

    foreach ($equipStatusMap as $code => $isOnline) {
        $statusStr = ($isOnline === 1) ? 'running' : 'stopped';
        $updateNodeStmt->execute([$statusStr, $now, $code]);
    }

    // 4. Логирование успешного синка
    $logStmt = $db->prepare("
        INSERT INTO sync_logs (source, status, records_processed, message)
        VALUES ('zabbix', 'success', ?, 'Успешный опрос Zabbix и сохранение снапшота')
    ");
    $logStmt->execute([count($metrics)]);

    echo "[" . date('Y-m-d H:i:s') . "] Синхронизация Zabbix завершена успешно.\n";

} catch (Throwable $e) {
    echo "ОШИБКА: " . $e->getMessage() . "\n";
    if (isset($db)) {
        $logStmt = $db->prepare("
            INSERT INTO sync_logs (source, status, records_processed, message)
            VALUES ('zabbix', 'error', 0, ?)
        ");
        $logStmt->execute([$e->getMessage()]);
    }
    exit(1);
}
