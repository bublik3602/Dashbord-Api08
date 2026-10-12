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
     * Маппинг flow_type из CRM Агропроинвест 08 в названия категорий
     */
    private function mapFlowType(string $flow): string {
        switch (strtolower(trim($flow))) {
            case 'seed':
            case 'насіння':
            case 'семечка':
            case 'семена':
                return 'Семена';

            case 'oil':
            case 'масло':
            case 'олія':
                return 'Масло';

            case 'meal':
            case 'шрот':
                return 'Шрот';

            case 'husk':
            case 'лузга':
            case 'лушпиння':
                return 'Лузга';

            default:
                return 'Семена';
        }
    }

    /**
     * Получение точной воронки транспорта и времени нахождения из CRM (таблица vehicles)
     */
    public function getTransportFunnel(): array {
        $crm = $this->getCrmConnection();

        if ($crm !== null) {
            try {
                // Проверяем наличие боевой таблицы vehicles
                $checkStmt = $this->safeSelect($crm, "SHOW TABLES LIKE 'vehicles'");
                if ($checkStmt->fetch()) {
                    return $this->fetchFromVehiclesTable($crm);
                }
            } catch (Throwable $e) {
                error_log("Ошибка опроса таблицы vehicles: " . $e->getMessage());
            }
        }

        // Резервный фолбэк на локальный кэш
        return $this->fetchFromFallbackCache();
    }

    /**
     * Точный опрос таблицы vehicles (Агропроинвест 08)
     */
    private function fetchFromVehiclesTable(PDO $crm): array {
        // Запрос всех активных машин + выехавших за сегодня
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
                -- Расчет времени нахождения на заводе (в минутах)
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
                  status != 'completed' 
                  OR DATE(COALESCE(completed_at, created_at)) >= CURDATE()
              )
            ORDER BY id DESC
        ";

        $stmt = $this->safeSelect($crm, $sql);
        $rows = $stmt->fetchAll();

        // Инициализируем структуры по 4 культурам
        $categories = [
            'Все'    => ['name' => 'Все грузы', 'queued' => 0, 'entered' => 0, 'weighing' => 0, 'exited' => 0, 'on_site' => 0, 'stay_minutes' => []],
            'Семена' => ['name' => 'Семена (Насіння)', 'queued' => 0, 'entered' => 0, 'weighing' => 0, 'exited' => 0, 'on_site' => 0, 'stay_minutes' => []],
            'Масло'  => ['name' => 'Масло подсолнечное', 'queued' => 0, 'entered' => 0, 'weighing' => 0, 'exited' => 0, 'on_site' => 0, 'stay_minutes' => []],
            'Шрот'   => ['name' => 'Шрот тостированный', 'queued' => 0, 'entered' => 0, 'weighing' => 0, 'exited' => 0, 'on_site' => 0, 'stay_minutes' => []],
            'Лузга'  => ['name' => 'Лузга гранулированная', 'queued' => 0, 'entered' => 0, 'weighing' => 0, 'exited' => 0, 'on_site' => 0, 'stay_minutes' => []],
        ];

        $classifiedTrucks = [];

        foreach ($rows as $r) {
            $cat = $this->mapFlowType($r['flow_type'] ?? 'seed');
            $status = $r['status'] ?? 'waiting';
            $duration = (int)$r['stay_duration_minutes'];

            // Определение этапа воронки
            $step = 'queued'; // 1. Записано (В черзі / Накопичувач)
            $isOnSite = false;

            if ($status === 'waiting') {
                $step = 'queued';
            } elseif ($status === 'weighing_in' || $status === 'weighing_out') {
                $step = 'weighing'; // 3. На весах
                $isOnSite = true;
            } elseif (in_array($status, ['in_transit', 'arrived', 'unloading'], true)) {
                $step = 'entered'; // 2. Заехало / В дорозі / На территории
                $isOnSite = true;
            } elseif ($status === 'completed') {
                $step = 'exited'; // 4. Выехало сегодня
            }

            // Учет для категории "Все"
            if (isset($categories['Все'][$step])) {
                $categories['Все'][$step]++;
            }
            if ($isOnSite) {
                $categories['Все']['on_site']++;
            }
            if ($duration > 0 && ($isOnSite || $step === 'exited')) {
                $categories['Все']['stay_minutes'][] = $duration;
            }

            // Учет для конкретной культуры (Семена, Масло, Шрот, Лузга)
            if (isset($categories[$cat])) {
                if (isset($categories[$cat][$step])) {
                    $categories[$cat][$step]++;
                }
                if ($isOnSite) {
                    $categories[$cat]['on_site']++;
                }
                if ($duration > 0 && ($isOnSite || $step === 'exited')) {
                    $categories[$cat]['stay_minutes'][] = $duration;
                }
            }

            $r['category'] = $cat;
            $r['step'] = $step;
            $classifiedTrucks[] = $r;
        }

        // Сводный расчет по каждой культуре
        $summary = [];
        foreach ($categories as $key => $data) {
            $avgMins = count($data['stay_minutes']) > 0 
                ? (int)round(array_sum($data['stay_minutes']) / count($data['stay_minutes'])) 
                : 90;

            $summary[$key] = [
                'name'             => $data['name'],
                'queued'           => $data['queued'],   // 1. В черзі
                'entered'          => $data['entered'],  // 2. Заехало / на погрузке
                'weighing'         => $data['weighing'], // 3. На весах
                'exited'           => $data['exited'],   // 4. Выехало
                'on_site'          => $data['on_site'],  // Всего на заводе (как на табло Агропроинвест 08)
                'total_active'     => $data['queued'] + $data['on_site'],
                'avg_stay_minutes' => $avgMins,
                'avg_stay_text'    => format_duration_minutes($avgMins),
            ];
        }

        $allData = $summary['Все'];

        return [
            'queued'             => $allData['queued'],
            'entered'            => $allData['entered'],
            'weighing'           => $allData['weighing'],
            'exited'             => $allData['exited'],
            'on_site'            => $allData['on_site'], // 9 машин на заводе!
            'total_active'       => $allData['total_active'],
            'avg_stay_minutes'   => $allData['avg_stay_minutes'],
            'avg_stay_text'      => $allData['avg_stay_text'],
            'categories'         => $summary,
            'trucks_sample'      => array_slice($classifiedTrucks, 0, 25),
            'source'             => 'crm_apgk_api08_vehicles',
        ];
    }

    /**
     * Резервное чтение из локального кэша
     */
    private function fetchFromFallbackCache(): array {
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
                'Все'    => ['name' => 'Все грузы', 'queued' => 0, 'entered' => 0, 'weighing' => 0, 'exited' => 0, 'on_site' => 0, 'stay_minutes' => []],
                'Семена' => ['name' => 'Семена (Насіння)', 'queued' => 0, 'entered' => 0, 'weighing' => 0, 'exited' => 0, 'on_site' => 0, 'stay_minutes' => []],
                'Масло'  => ['name' => 'Масло подсолнечное', 'queued' => 0, 'entered' => 0, 'weighing' => 0, 'exited' => 0, 'on_site' => 0, 'stay_minutes' => []],
                'Шрот'   => ['name' => 'Шрот тостированный', 'queued' => 0, 'entered' => 0, 'weighing' => 0, 'exited' => 0, 'on_site' => 0, 'stay_minutes' => []],
                'Лузга'  => ['name' => 'Лузга гранулированная', 'queued' => 0, 'entered' => 0, 'weighing' => 0, 'exited' => 0, 'on_site' => 0, 'stay_minutes' => []],
            ];

            foreach ($trucks as $t) {
                $cat = categorize_cargo($t['cargo_type'] ?? '');
                $st = $t['status'] ?? 'entered';
                $dur = (int)($t['stay_duration_minutes'] ?? 0);
                $isOnSite = ($st === 'entered' || $st === 'weighing');

                if (isset($categories['Все'][$st])) $categories['Все'][$st]++;
                if ($isOnSite) $categories['Все']['on_site']++;
                if ($dur > 0) $categories['Все']['stay_minutes'][] = $dur;

                if (isset($categories[$cat])) {
                    if (isset($categories[$cat][$st])) $categories[$cat][$st]++;
                    if ($isOnSite) $categories[$cat]['on_site']++;
                    if ($dur > 0) $categories[$cat]['stay_minutes'][] = $dur;
                }
            }

            $summary = [];
            foreach ($categories as $key => $data) {
                $avgMins = count($data['stay_minutes']) > 0 
                    ? (int)round(array_sum($data['stay_minutes']) / count($data['stay_minutes'])) 
                    : 90;

                $summary[$key] = [
                    'name'             => $data['name'],
                    'queued'           => $data['queued'],
                    'entered'          => $data['entered'],
                    'weighing'         => $data['weighing'],
                    'exited'           => $data['exited'],
                    'on_site'          => $data['on_site'],
                    'total_active'     => $data['queued'] + $data['on_site'],
                    'avg_stay_minutes' => $avgMins,
                    'avg_stay_text'    => format_duration_minutes($avgMins),
                ];
            }

            $allData = $summary['Все'];
            return [
                'queued'             => $allData['queued'],
                'entered'            => $allData['entered'],
                'weighing'           => $allData['weighing'],
                'exited'             => $allData['exited'],
                'on_site'            => $allData['on_site'],
                'total_active'       => $allData['total_active'],
                'avg_stay_minutes'   => $allData['avg_stay_minutes'],
                'avg_stay_text'      => $allData['avg_stay_text'],
                'categories'         => $summary,
                'trucks_sample'      => array_slice($trucks, 0, 20),
                'source'             => 'local_cache_demo',
            ];
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Получить активный список автомобилей на территории завода
     */
    public function getActiveTrucksList(): array {
        $funnel = $this->getTransportFunnel();
        return $funnel['trucks_sample'] ?? [];
    }
}
