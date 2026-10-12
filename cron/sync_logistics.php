<?php
/**
 * Скрипт синхронизации с базой CRM (Электронная очередь транспорта) по CRON
 * Запуск из Hestia CP:
 * * /2 * * * * /usr/bin/php /home/admin/web/dash.factory.local/public_html/cron/sync_logistics.php > /dev/null 2>&1
 */

if (php_sapi_name() !== 'cli' && ($_GET['token'] ?? '') !== 'cron_secure_token') {
    die("Access denied. CLI only.\n");
}

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/crm_client.php';

echo "[" . date('Y-m-d H:i:s') . "] Запуск синхронизации очереди автотранспорта...\n";

try {
    $db = DashboardDB::getConnection();
    $crm = new CRMClient();
    $funnel = $crm->getTransportFunnel();

    $logStmt = $db->prepare("
        INSERT INTO sync_logs (source, status, records_processed, message)
        VALUES ('crm', 'success', ?, ?)
    ");
    $logStmt->execute([
        $funnel['total_active'] ?? 0,
        "Синхронизация воронки транспорта ({$funnel['source']}): Активно: {$funnel['total_active']}, Ср. время: {$funnel['avg_stay_minutes']} мин"
    ]);

    echo "[" . date('Y-m-d H:i:s') . "] Синхронизация логистики завершена успешно.\n";

} catch (Throwable $e) {
    echo "ОШИБКА: " . $e->getMessage() . "\n";
    if (isset($db)) {
        $logStmt = $db->prepare("
            INSERT INTO sync_logs (source, status, records_processed, message)
            VALUES ('crm', 'error', 0, ?)
        ");
        $logStmt->execute([$e->getMessage()]);
    }
    exit(1);
}
