<?php
/**
 * REST API: Статус оборудования в реальном времени
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';

try {
    $db = DashboardDB::getConnection();
    $stmt = $db->query("
        SELECT id, code, name, section, status, load_pct, current_temperature_c, details, last_update
        FROM equipment_nodes
        ORDER BY id ASC
    ");
    $nodes = $stmt->fetchAll();

    echo json_encode([
        'status' => 'success',
        'data'   => $nodes,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
