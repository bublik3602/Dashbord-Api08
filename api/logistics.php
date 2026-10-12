<?php
/**
 * REST API: Детальная информация по электронной очереди транспорта
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/crm_client.php';

try {
    $crm = new CRMClient();
    $funnel = $crm->getTransportFunnel();
    $activeTrucks = $crm->getActiveTrucksList();

    echo json_encode([
        'status' => 'success',
        'funnel' => $funnel,
        'trucks' => $activeTrucks,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
