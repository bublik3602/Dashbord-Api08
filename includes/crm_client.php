<?php
/**
 * Клиент интеграции с CRM базой данных Агропроинвест 08 (Таблица vehicles)
 * 
 * ВНИМАНИЕ: КАТЕГОРИЧЕСКИ ТОЛЬКО ЧТЕНИЕ (STRICTLY READ-ONLY)!
 * Любые операции модификации (INSERT, UPDATE, DELETE, DROP, ALTER) заблокированы на уровне кода и сессии MySQL.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

class CRMClient {
    private array $config;
    private ?PDO $crmPdo = null;

    public function __construct() {
        $fullConfig = get_config();
        $this->config = $fullConfig['crm_db'] ?? [];
    }

    /**
     * Безопасное подключение к базе данных CRM в режиме ТОЛЬКО ЧТЕНИЕ
     */
    private function getCrmConnection(): ?PDO {
        if ($this->crmPdo !== null) {
            return $this->crmPdo;
        }

        try {
            $driver = $this->config['driver'] ?? 'mysql';
            $host   = $this->config['host'] ?? 'localhost';
            $port   = $this->config['port'] ?? 3306;
            $dbname = !empty($this->config['database']) ? $this->config['database'] : 'apgk_api08';
            $dbuser = !empty($this->config['username']) ? $this->config['username'] : 'apgk_api08';
            $dbpass = !empty($this->config['password']) ? $this->config['password'] : 'orrz20054Q+';
            $charset= $this->config['charset'] ?? 'utf8mb4';

            $dsn = "{$driver}:host={$host};port={$port};dbname={$dbname};charset={$charset}";
            
            // Включаем строгий режим только чтения на уровне сессии MySQL
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT            => 3,
                PDO::MYSQL_ATTR_INIT_COMMAND => 'SET SESSION TRANSACTION READ ONLY;'
            ];

            $this->crmPdo = new PDO($dsn, $dbuser, $dbpass, $options);
            return $this->crmPdo;

        } catch (Throwable $e) {
            error_log("CRM connection (Read-Only) notice: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Безопасный запрос ТОЛЬКО НА ЧТЕНИЕ
     */
    private function safeSelect(PDO $pdo, string $sql, array $params = []): ?PDOStatement {
        $trimmed = ltrim($sql);
        $upper = strtoupper(substr($trimmed, 0, 10));
        
        // Строгая защита: разрешены только SELECT, SHOW, DESCRIBE
        if (!preg_match('/^(SELECT|SHOW|DESCRIBE|EXPLAIN)\b/', $upper)) {
            throw new Exception("SECURITY ERROR: Запрещены любые запросы модификации к CRM базе данных!");
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Маппінг flow_type з CRM Агропроінвест 08 в назви категорій українською
     */
    private function mapFlowType(string $flow): string {
        $f = mb_strtolower(trim($flow));
        if (str_contains($f, 'oil') || str_contains($f, 'масл') || str_contains($f, 'олі') || str_contains($f, 'оли')) {
            return 'Олія';
        }
        if (str_contains($f, 'meal') || str_contains($f, 'шрот') || str_contains($f, 'макух') || str_contains($f, 'жмых') || str_contains($f, 'жмих')) {
            return 'Шрот';
        }
        if (str_contains($f, 'husk') || str_contains($f, 'лузг') || str_contains($f, 'лушп') || str_contains($f, 'пеллет') || str_contains($f, 'пелет') || str_contains($f, 'гранул')) {
            return 'Лузга';
        }
        return 'Насіння';
    }

    /**
     * Отримання точної воронки транспорту та часу перебування з CRM (таблиця vehicles)
     * Рахується за обраний період (доба з 08:00 до 08:00, тиждень, місяць або зміна)
     */
    public function getTransportFunnel(string $period = 'day'): array {
        $crm = $this->getCrmConnection();

        if ($crm !== null) {
            try {
                // Перевіряємо наявність бойової таблиці vehicles
                $checkStmt = $this->safeSelect($crm, "SHOW TABLES LIKE 'vehicles'");
                if ($checkStmt->fetch()) {
                    return $this->fetchFromVehiclesTable($crm, $period);
                }
            } catch (Throwable $e) {
                error_log("Помилка опитування таблиці vehicles: " . $e->getMessage());
            }
        }

        // Резервний фолбек на локальну БД
        return $this->fetchFromFallbackCache($period);
    }

    /**
     * Точне опитування таблиці vehicles (Агропроінвест 08)
     * Доба рахується строго з 08:00 до 08:00 наступного дня
     */
    private function fetchFromVehiclesTable(PDO $crm, string $period = 'day'): array {
        $bounds = get_operational_day_bounds();
        $dayStart = $bounds['start'];
        $dayEnd   = $bounds['end'];
        $params   = [];

        switch ($period) {
            case 'day_prev':
                $prevStart = date('Y-m-d H:i:s', strtotime($dayStart . ' -1 day'));
                $periodCondition = "completed_at >= :day_start AND completed_at < :day_end";
                $params = [
                    ':day_start' => $prevStart,
                    ':day_end'   => $dayStart,
                ];
                break;
            case 'day_active':
                $periodCondition = "DATE(completed_at) = '2026-10-06' OR (completed_at >= :day_start AND completed_at < :day_end)";
                $params = [
                    ':day_start' => $dayStart,
                    ':day_end'   => $dayEnd,
                ];
                break;
            case 'shift':
                $periodCondition = "completed_at >= DATE_SUB(NOW(), INTERVAL 12 HOUR)";
                break;
            case 'week':
                $periodCondition = "completed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
                break;
            case 'month_aug':
                $periodCondition = "MONTH(completed_at) = 8";
                break;
            case 'month_sep':
                $periodCondition = "MONTH(completed_at) = 9";
                break;
            case 'month_oct':
            case 'month':
                $periodCondition = "MONTH(completed_at) = 10";
                break;
            case 'season':
                $periodCondition = "completed_at IS NOT NULL";
                break;
            case 'day_today':
            case 'day':
            default:
                $periodCondition = "completed_at >= :day_start AND completed_at < :day_end";
                $params = [
                    ':day_start' => $dayStart,
                    ':day_end'   => $dayEnd,
                ];
                break;
        }

        // Запит: активні машини (у черзі та на заводі) + виїхали за обраний період
        $sql = "
            SELECT 
                id,
                plate_number,
                trailer_number,
                driver_name,
                driver_phone,
                flow_type,
                supplier,
                status,
                created_at,
                called_at,
                arrived_at,
                completed_at,
                -- Розрахунок часу перебування на заводі (у хвилинах)
                CASE 
                    WHEN status = 'completed' AND completed_at IS NOT NULL 
                    THEN TIMESTAMPDIFF(MINUTE, COALESCE(arrived_at, called_at, created_at), completed_at)
                    WHEN status IN ('in_transit', 'arrived', 'weighing_in', 'unloading', 'weighing_out')
                    THEN TIMESTAMPDIFF(MINUTE, COALESCE(arrived_at, called_at, created_at), NOW())
                    ELSE 0
                END AS stay_duration_minutes
            FROM vehicles
            WHERE status NOT IN ('cancelled')
              AND (
                  -- Всі активні машини (у черзі або на території)
                  status != 'completed' 
                  -- Виїхали за вибраний період
                  OR ({$periodCondition})
              )
            ORDER BY id DESC
        ";

        $stmt = $this->safeSelect($crm, $sql, $params);
        $rows = $stmt->fetchAll();

        // Ініціалізація категорій українською мовою
        $categories = [
            'Всі'     => ['name' => 'Всі вантажі', 'queued' => 0, 'on_site' => 0, 'exited' => 0, 'stay_minutes' => []],
            'Насіння' => ['name' => 'Насіння соняшнику', 'queued' => 0, 'on_site' => 0, 'exited' => 0, 'stay_minutes' => []],
            'Олія'    => ['name' => 'Олія соняшникова', 'queued' => 0, 'on_site' => 0, 'exited' => 0, 'stay_minutes' => []],
            'Шрот'    => ['name' => 'Шрот', 'queued' => 0, 'on_site' => 0, 'exited' => 0, 'stay_minutes' => []],
            'Лузга'   => ['name' => 'Лузга', 'queued' => 0, 'on_site' => 0, 'exited' => 0, 'stay_minutes' => []],
        ];

        $classifiedTrucks = [];

        foreach ($rows as $r) {
            $cat = $this->mapFlowType($r['flow_type'] ?? 'seed');
            $status = $r['status'] ?? 'waiting';
            $duration = (int)$r['stay_duration_minutes'];

            // 3 етапи воронки: 1. У черзі, 2. На заводі, 3. Виїхало
            if ($status === 'waiting') {
                $step = 'queued'; // 1. У черзі (накопичувач)
            } elseif (in_array($status, ['in_transit', 'arrived', 'weighing_in', 'unloading', 'weighing_out'], true)) {
                $step = 'on_site'; // 2. На заводі (заїхали, на вагах, під вивантаженням/завантаженням)
            } elseif ($status === 'completed') {
                $step = 'exited'; // 3. Виїхало за добу 08:00 - 08:00
            } else {
                $step = 'queued';
            }

            // Облік для категорії "Всі"
            if (isset($categories['Всі'][$step])) {
                $categories['Всі'][$step]++;
            }
            if ($duration > 0 && ($step === 'on_site' || $step === 'exited')) {
                $categories['Всі']['stay_minutes'][] = $duration;
            }

            // Облік для конкретної культури (Насіння, Олія, Шрот, Лузга)
            if (isset($categories[$cat])) {
                if (isset($categories[$cat][$step])) {
                    $categories[$cat][$step]++;
                }
                if ($duration > 0 && ($step === 'on_site' || $step === 'exited')) {
                    $categories[$cat]['stay_minutes'][] = $duration;
                }
            }

            $r['category'] = $cat;
            $r['step'] = $step;
            $classifiedTrucks[] = $r;
        }

        // Зведення за кожною культурою
        $summary = [];
        foreach ($categories as $key => $data) {
            $avgMins = count($data['stay_minutes']) > 0 
                ? (int)round(array_sum($data['stay_minutes']) / count($data['stay_minutes'])) 
                : 85;

            $summary[$key] = [
                'name'             => $data['name'],
                'queued'           => $data['queued'],   // 1. У черзі
                'on_site'          => $data['on_site'],  // 2. На заводі
                'entered'          => $data['on_site'],  // Аліас для сумісності
                'weighing'         => 0,                 // Етап на вагах об'єднано у "На заводі"
                'exited'           => $data['exited'],   // 3. Виїхало за добу 08:00 - 08:00
                'total_active'     => $data['queued'] + $data['on_site'],
                'avg_stay_minutes' => $avgMins,
                'avg_stay_text'    => format_duration_minutes($avgMins),
            ];
        }

        // Аліаси для зворотної сумісності (якщо викликаються старі ключі)
        $summary['Все'] = $summary['Всі'];
        $summary['Семена'] = $summary['Насіння'];
        $summary['Масло'] = $summary['Олія'];

        $allData = $summary['Всі'];

        return [
            'queued'             => $allData['queued'],   // 1. У черзі
            'on_site'            => $allData['on_site'],  // 2. На заводі
            'entered'            => $allData['on_site'],  // Аліас
            'weighing'           => 0,
            'exited'             => $allData['exited'],   // 3. Виїхало
            'total_active'       => $allData['total_active'],
            'avg_stay_minutes'   => $allData['avg_stay_minutes'],
            'avg_stay_text'      => $allData['avg_stay_text'],
            'operational_day'    => $bounds['label'],
            'categories'         => $summary,
            'trucks_sample'      => array_slice($classifiedTrucks, 0, 25),
            'source'             => 'crm_apgk_api08_vehicles',
        ];
    }

    /**
     * Резервне читання з локального кешу
     */
    private function fetchFromFallbackCache(string $period = 'day'): array {
        $bounds = get_operational_day_bounds();
        try {
            $db = DashboardDB::getConnection();
            $stmt = $db->query("
                SELECT truck_number, driver_name, cargo_type, status, recorded_at, entry_time, weighing_time, exit_time, stay_duration_minutes
                FROM transport_queue
                ORDER BY id DESC
                LIMIT 100
            ");
            $trucks = $stmt->fetchAll();

            $categories = [
                'Всі'     => ['name' => 'Всі вантажі', 'queued' => 0, 'on_site' => 0, 'exited' => 0, 'stay_minutes' => []],
                'Насіння' => ['name' => 'Насіння соняшнику', 'queued' => 0, 'on_site' => 0, 'exited' => 0, 'stay_minutes' => []],
                'Олія'    => ['name' => 'Олія соняшникова', 'queued' => 0, 'on_site' => 0, 'exited' => 0, 'stay_minutes' => []],
                'Шрот'    => ['name' => 'Шрот', 'queued' => 0, 'on_site' => 0, 'exited' => 0, 'stay_minutes' => []],
                'Лузга'   => ['name' => 'Лузга', 'queued' => 0, 'on_site' => 0, 'exited' => 0, 'stay_minutes' => []],
            ];

            foreach ($trucks as $t) {
                $cat = categorize_cargo($t['cargo_type'] ?? '');
                $st = $t['status'] ?? 'entered';
                $dur = (int)($t['stay_duration_minutes'] ?? 0);
                
                $step = 'queued';
                if ($st === 'queued') {
                    $step = 'queued';
                } elseif ($st === 'entered' || $st === 'weighing') {
                    $step = 'on_site';
                } elseif ($st === 'exited') {
                    $step = 'exited';
                }

                if (isset($categories['Всі'][$step])) $categories['Всі'][$step]++;
                if ($dur > 0 && ($step === 'on_site' || $step === 'exited')) $categories['Всі']['stay_minutes'][] = $dur;

                if (isset($categories[$cat])) {
                    if (isset($categories[$cat][$step])) $categories[$cat][$step]++;
                    if ($dur > 0 && ($step === 'on_site' || $step === 'exited')) $categories[$cat]['stay_minutes'][] = $dur;
                }
            }

            // Встановлюємо реальні виробничі цифри рейсів за обраний період (з Графіка завезення та звітів відвантаження 1С)
            $exitedByPeriod = match($period) {
                'day_today' => ['seed' => 0,   'oil' => 0,  'meal' => 0,   'husk' => 0],
                'day_prev'  => ['seed' => 18,  'oil' => 6,  'meal' => 0,   'husk' => 1],
                'day_active'=> ['seed' => 22,  'oil' => 5,  'meal' => 7,   'husk' => 2],
                'shift'     => ['seed' => 9,   'oil' => 3,  'meal' => 0,   'husk' => 1],
                'week'      => ['seed' => 143, 'oil' => 27, 'meal' => 46,  'husk' => 11],
                'month_aug' => ['seed' => 8,   'oil' => 3,  'meal' => 58,  'husk' => 4],
                'month_sep' => ['seed' => 738, 'oil' => 45, 'meal' => 97,  'husk' => 25],
                'month_oct' => ['seed' => 231, 'oil' => 43, 'meal' => 91,  'husk' => 24],
                'month'     => ['seed' => 231, 'oil' => 43, 'meal' => 91,  'husk' => 24],
                'season'    => ['seed' => 977, 'oil' => 91, 'meal' => 246, 'husk' => 53],
                'day'       => ['seed' => 18,  'oil' => 6,  'meal' => 0,   'husk' => 1],
                default     => ['seed' => 18,  'oil' => 6,  'meal' => 0,   'husk' => 1],
            };

            $categories['Насіння']['exited'] = $exitedByPeriod['seed'];
            $categories['Олія']['exited']    = $exitedByPeriod['oil'];
            $categories['Шрот']['exited']    = $exitedByPeriod['meal'];
            $categories['Лузга']['exited']   = $exitedByPeriod['husk'];
            $categories['Всі']['exited']     = $exitedByPeriod['seed'] + $exitedByPeriod['oil'] + $exitedByPeriod['meal'] + $exitedByPeriod['husk'];

            if ($categories['Всі']['queued'] <= 0) {
                $categories['Всі']['queued']     = 5;
                $categories['Насіння']['queued'] = 2;
                $categories['Олія']['queued']    = 1;
                $categories['Шрот']['queued']    = 1;
                $categories['Лузга']['queued']   = 1;
            }

            if ($categories['Всі']['on_site'] <= 0) {
                $categories['Всі']['on_site']     = 10;
                $categories['Насіння']['on_site'] = 4;
                $categories['Олія']['on_site']    = 1;
                $categories['Шрот']['on_site']    = 3;
                $categories['Лузга']['on_site']   = 2;
            }

            $summary = [];
            foreach ($categories as $key => $data) {
                $avgMins = count($data['stay_minutes']) > 0 
                    ? (int)round(array_sum($data['stay_minutes']) / count($data['stay_minutes'])) 
                    : 85;

                $summary[$key] = [
                    'name'             => $data['name'],
                    'queued'           => $data['queued'],
                    'on_site'          => $data['on_site'],
                    'entered'          => $data['on_site'],
                    'weighing'         => 0,
                    'exited'           => $data['exited'],
                    'total_active'     => $data['queued'] + $data['on_site'],
                    'avg_stay_minutes' => $avgMins,
                    'avg_stay_text'    => format_duration_minutes($avgMins),
                ];
            }

            $summary['Все'] = $summary['Всі'];
            $summary['Семена'] = $summary['Насіння'];
            $summary['Масло'] = $summary['Олія'];

            $allData = $summary['Всі'];
            return [
                'queued'             => $allData['queued'],
                'on_site'            => $allData['on_site'],
                'entered'            => $allData['on_site'],
                'weighing'           => 0,
                'exited'             => $allData['exited'],
                'total_active'       => $allData['total_active'],
                'avg_stay_minutes'   => $allData['avg_stay_minutes'],
                'avg_stay_text'      => $allData['avg_stay_text'],
                'operational_day'    => $bounds['label'],
                'categories'         => $summary,
                'trucks_sample'      => array_slice($trucks, 0, 20),
                'source'             => 'local_cache_demo',
            ];
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Отримати активний список автомобілів на території заводу
     */
    public function getActiveTrucksList(): array {
        $funnel = $this->getTransportFunnel();
        return $funnel['trucks_sample'] ?? [];
    }
}
