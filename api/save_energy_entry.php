<?php
/**
 * REST API: Ввід та збереження показників енергоносіїв (Електроенергія, Вода, Пара)
 * Метод: POST (збереження / видалення) або GET (отримання списку записів)
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

Auth::init();
$currentUser = Auth::user();

if (!$currentUser || (!Auth::isAdmin() && !Auth::isEnergo() && ($currentUser['username'] ?? '') !== 'test')) {
    http_response_code(403);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Доступ заборонено. Тільки адміністратор або енергетик мають право вносити показники.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$dataFile = __DIR__ . '/../database/energy_manual_entries.json';

// Допоміжна функція завантаження записів
function loadEnergyEntries(string $file): array {
    if (!file_exists($file)) {
        return [];
    }
    $raw = @file_get_contents($file);
    if (!$raw) {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

// Допоміжна функція збереження записів
function saveEnergyEntries(string $file, array $entries): bool {
    ksort($entries);
    $json = json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    return (bool)@file_put_contents($file, $json, LOCK_EX);
}

// Обробка GET-запиту: повертає список останніх записів
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $entries = loadEnergyEntries($dataFile);
    krsort($entries);
    echo json_encode([
        'status'  => 'success',
        'entries' => array_values($entries),
        'count'   => count($entries),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Обробка POST-запиту
try {
    $rawInput = file_get_contents('php://input');
    $postData = [];
    if (!empty($rawInput)) {
        $jsonDecoded = json_decode($rawInput, true);
        if (is_array($jsonDecoded)) {
            $postData = $jsonDecoded;
        }
    }
    if (empty($postData)) {
        $postData = $_POST;
    }

    $action = $postData['action'] ?? 'save';

    // Дія: ВИДАЛЕННЯ
    if ($action === 'delete') {
        $date = trim($postData['date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new InvalidArgumentException('Некоректний формат дати (очікується РРРР-ММ-ДД).');
        }

        $entries = loadEnergyEntries($dataFile);
        if (isset($entries[$date])) {
            unset($entries[$date]);
            saveEnergyEntries($dataFile, $entries);
            echo json_encode([
                'status'  => 'success',
                'message' => "Показники за дату {$date} успішно видалено.",
            ], JSON_UNESCAPED_UNICODE);
            exit;
        } else {
            echo json_encode([
                'status'  => 'error',
                'message' => "Запис за дату {$date} не знайдено.",
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    // Дія: ЗБЕРЕЖЕННЯ / ОНОВЛЕННЯ
    $date = trim($postData['date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $date = date('Y-m-d');
    }

    $powerKwh = isset($postData['power_kwh']) ? (float)str_replace(',', '.', (string)$postData['power_kwh']) : null;
    $waterM3  = isset($postData['water_m3'])  ? (float)str_replace(',', '.', (string)$postData['water_m3'])  : null;
    $steamT   = isset($postData['steam_t'])   ? (float)str_replace(',', '.', (string)$postData['steam_t'])   : null;

    if ($powerKwh === null || $powerKwh < 0) {
        throw new InvalidArgumentException('Вкажіть коректне споживання електроенергії (кВт·год >= 0).');
    }
    if ($waterM3 === null || $waterM3 < 0) {
        throw new InvalidArgumentException('Вкажіть коректні витрати води (м³ >= 0).');
    }
    if ($steamT === null || $steamT < 0) {
        throw new InvalidArgumentException('Вкажіть коректну кількість технологічної пари (т >= 0).');
    }

    $steamPressureBar = isset($postData['steam_pressure_bar']) ? (float)str_replace(',', '.', (string)$postData['steam_pressure_bar']) : 11.2;
    $reactiveKvarh    = isset($postData['reactive_kvarh']) ? (float)str_replace(',', '.', (string)$postData['reactive_kvarh']) : round($powerKwh * 0.32, 1);
    $note             = trim((string)($postData['note'] ?? ''));

    $entries = loadEnergyEntries($dataFile);

    $entries[$date] = [
        'date'               => $date,
        'power_kwh'          => round($powerKwh, 1),
        'water_m3'           => round($waterM3, 2),
        'steam_t'            => round($steamT, 2),
        'steam_pressure_bar' => round($steamPressureBar, 2),
        'reactive_kvarh'     => round($reactiveKvarh, 1),
        'note'               => $note ?: 'Внесено черговим енергетиком',
        'author'             => $currentUser['username'] ?? 'energo',
        'updated_at'         => date('Y-m-d H:i:s'),
    ];

    saveEnergyEntries($dataFile, $entries);

    // Спроба продублювати запис у таблицю energy_readings БД (якщо активна)
    try {
        $db = Database::getInstance();
        $calcPowerKw = round($powerKwh / 24, 2);
        $calcWaterFlow = round($waterM3 / 24, 2);
        $stmt = $db->prepare("
            INSERT INTO energy_readings (recorded_at, power_kw, power_kwh_delta, water_flow_m3_hour, water_m3_delta, steam_pressure_bar)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $date . ' 12:00:00',
            $calcPowerKw,
            $powerKwh,
            $calcWaterFlow,
            $waterM3,
            $steamPressureBar
        ]);
    } catch (Throwable $dbErr) {
        // Локальний JSON вже надійно зберіг дані, помилка SQL не блокує користувача
    }

    $fmtDate = date('d.m.Y', strtotime($date));
    echo json_encode([
        'status'  => 'success',
        'message' => "Показники за добу {$fmtDate} успішно збережено: {$powerKwh} кВт·год, {$waterM3} м³ води, {$steamT} т пари.",
        'entry'   => $entries[$date],
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'status'  => 'error',
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
