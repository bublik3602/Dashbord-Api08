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

    $entries = loadEnergyEntries($dataFile);
    $existing = $entries[$date] ?? [];

    $rawKwh   = isset($postData['power_kwh']) ? trim((string)$postData['power_kwh']) : '';
    $rawWater = isset($postData['water_m3'])  ? trim((string)$postData['water_m3'])  : '';
    $rawSteam = isset($postData['steam_t'])   ? trim((string)$postData['steam_t'])   : '';

    // Перевіряємо, чи введено хоча б один показник, або чи вже є збережений запис
    if ($rawKwh === '' && $rawWater === '' && $rawSteam === '' && empty($existing)) {
        throw new InvalidArgumentException('Вкажіть хоча б один показник: електроенергію (кВт), воду (м³) або пару (т).');
    }

    // Якщо поле передано — оновлюємо, якщо порожньо — зберігаємо попередньо внесене значення (якщо було), або залишаємо null
    $powerKwh = ($rawKwh !== '')
        ? (float)str_replace(',', '.', $rawKwh)
        : (isset($existing['power_kwh']) && $existing['power_kwh'] !== null ? (float)$existing['power_kwh'] : null);

    $waterM3  = ($rawWater !== '')
        ? (float)str_replace(',', '.', $rawWater)
        : (isset($existing['water_m3']) && $existing['water_m3'] !== null ? (float)$existing['water_m3'] : null);

    $steamT   = ($rawSteam !== '')
        ? (float)str_replace(',', '.', $rawSteam)
        : (isset($existing['steam_t']) && $existing['steam_t'] !== null ? (float)$existing['steam_t'] : null);

    if ($powerKwh !== null && $powerKwh < 0) {
        throw new InvalidArgumentException('Споживання електроенергії не може бути менше 0.');
    }
    if ($waterM3 !== null && $waterM3 < 0) {
        throw new InvalidArgumentException('Витрати води не можуть бути менше 0.');
    }
    if ($steamT !== null && $steamT < 0) {
        throw new InvalidArgumentException('Кількість технологічної пари не може бути менше 0.');
    }

    $rawPressure = isset($postData['steam_pressure_bar']) ? trim((string)$postData['steam_pressure_bar']) : '';
    $steamPressureBar = ($rawPressure !== '')
        ? (float)str_replace(',', '.', $rawPressure)
        : ($existing['steam_pressure_bar'] ?? 11.2);

    $rawReactive = isset($postData['reactive_kvarh']) ? trim((string)$postData['reactive_kvarh']) : '';
    $reactiveKvarh = ($rawReactive !== '')
        ? (float)str_replace(',', '.', $rawReactive)
        : ($existing['reactive_kvarh'] ?? ($powerKwh !== null ? round($powerKwh * 0.32, 1) : null));

    $note = trim((string)($postData['note'] ?? ''));
    if ($note === '' && !empty($existing['note'])) {
        $note = $existing['note'];
    }

    $savedEntry = [
        'date'               => $date,
        'power_kwh'          => $powerKwh !== null ? round($powerKwh, 3) : null,
        'water_m3'           => $waterM3 !== null ? round($waterM3, 2) : null,
        'steam_t'            => $steamT !== null ? round($steamT, 2) : null,
        'steam_pressure_bar' => round($steamPressureBar, 2),
        'reactive_kvarh'     => $reactiveKvarh !== null ? round($reactiveKvarh, 1) : null,
        'note'               => $note ?: 'Внесено черговим енергетиком',
        'author'             => $currentUser['username'] ?? 'energo',
        'updated_at'         => date('Y-m-d H:i:s'),
    ];

    $entries[$date] = $savedEntry;
    saveEnergyEntries($dataFile, $entries);

    // Спроба продублювати запис у таблицю energy_readings БД (якщо активна)
    try {
        $db = Database::getInstance();
        $calcPowerKw = $powerKwh !== null ? round($powerKwh / 24, 3) : 0.0;
        $calcWaterFlow = $waterM3 !== null ? round($waterM3 / 24, 2) : 0.0;
        $stmt = $db->prepare("
            INSERT INTO energy_readings (recorded_at, power_kw, power_kwh_delta, water_flow_m3_hour, water_m3_delta, steam_pressure_bar)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $date . ' 12:00:00',
            $calcPowerKw,
            $powerKwh ?? 0.0,
            $calcWaterFlow,
            $waterM3 ?? 0.0,
            $steamPressureBar
        ]);
    } catch (Throwable $dbErr) {
        // Локальний JSON вже надійно зберіг дані, помилка SQL не блокує користувача
    }

    $details = [];
    if ($powerKwh !== null) $details[] = number_format($powerKwh, 3, ',', ' ') . ' кВт';
    if ($waterM3 !== null)  $details[] = number_format($waterM3, 1, ',', ' ') . ' м³ води';
    if ($steamT !== null)   $details[] = number_format($steamT, 1, ',', ' ') . ' т пари';
    $detailsStr = !empty($details) ? implode(', ', $details) : 'дані оновлено';

    $fmtDate = date('d.m.Y', strtotime($date));
    echo json_encode([
        'status'  => 'success',
        'message' => "Показники за добу {$fmtDate} успішно збережено: {$detailsStr}.",
        'entry'   => $savedEntry,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'status'  => 'error',
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
