<?php
/**
 * Клиент интеграции с CRM базой данных (Логистика и электронная очередь транспорта)
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
     * Безопасное подключение к внешней базе данных CRM в режиме ТОЛЬКО ЧТЕНИЕ
     */
    private function getCrmConnection(): ?PDO {
        if (!($this->config['enabled'] ?? false)) {
            return null;
        }

        if ($this->crmPdo !== null) {
            return $this->crmPdo;
        }

        try {
            $driver = $this->config['driver'] ?? 'mysql';
            $host   = $this->config['host'] ?? 'localhost';
            $port   = $this->config['port'] ?? 3306;
            $dbname = $this->config['database'] ?? 'apgk_api08';
            $charset= $this->config['charset'] ?? 'utf8mb4';

            $dsn = "{$driver}:host={$host};port={$port};dbname={$dbname};charset={$charset}";
            
            // Включаем режим только чтения на уровне сессии MySQL
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT            => 3,
                PDO::MYSQL_ATTR_INIT_COMMAND => 'SET SESSION TRANSACTION READ ONLY;'
            ];

            $this->crmPdo = new PDO(
                $dsn,
                $this->config['username'] ?? 'apgk_api08',
                $this->config['password'] ?? '',
                $options
            );

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
     * Получение сводной информации по автотранспорту с разделением на 4 категории:
     * Семена, Масло, Шрот, Лузга
     */
    public function getTransportFunnel(): array {
        $truckRows = $this->fetchRawTruckData();

        // Инициализируем категории
        $categories = [
            'Все'    => ['name' => 'Все грузы', 'queued' => 0, 'entered' => 0, 'weighing' => 0, 'exited' => 0, 'stay_minutes' => []],
            'Семена' => ['name' => 'Семена (Семечка)', 'queued' => 0, 'entered' => 0, 'weighing' => 0, 'exited' => 0, 'stay_minutes' => []],
            'Масло'  => ['name' => 'Масло подсолнечное', 'queued' => 0, 'entered' => 0, 'weighing' => 0, 'exited' => 0, 'stay_minutes' => []],
            'Шрот'   => ['name' => 'Шрот тостированный', 'queued' => 0, 'entered' => 0, 'weighing' => 0, 'exited' => 0, 'stay_minutes' => []],
            'Лузга'  => ['name' => 'Лузга гранулированная', 'queued' => 0, 'entered' => 0, 'weighing' => 0, 'exited' => 0, 'stay_minutes' => []],
        ];

        $classifiedTrucks = [];

        foreach ($truckRows as $truck) {
            $cat = categorize_cargo($truck['cargo_type'] ?? '');
            $status = $truck['status'] ?? 'entered';
            $duration = (int)($truck['stay_duration_minutes'] ?? 0);

            // Добавляем в категорию "Все"
            if (isset($categories['Все'][$status])) {
                $categories['Все'][$status]++;
            }
            if ($status === 'exited' && $duration > 0) {
                $categories['Все']['stay_minutes'][] = $duration;
            }

            // Добавляем в профильную категорию
            if (isset($categories[$cat])) {
                if (isset($categories[$cat][$status])) {
                    $categories[$cat][$status]++;
                }
                if ($status === 'exited' && $duration > 0) {
                    $categories[$cat]['stay_minutes'][] = $duration;
                }
            }

            $truck['category'] = $cat;
            $classifiedTrucks[] = $truck;
        }

        // Расчет средних времен нахождения
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
                'total_active'     => $data['queued'] + $data['entered'] + $data['weighing'],
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
            'total_active'       => $allData['total_active'],
            'avg_stay_minutes'   => $allData['avg_stay_minutes'],
            'avg_stay_text'      => $allData['avg_stay_text'],
            'categories'         => $summary,
            'trucks_sample'      => array_slice($classifiedTrucks, 0, 20),
            'source'             => ($this->crmPdo !== null) ? 'crm_api08_readonly' : 'local_cache',
        ];
    }

    /**
     * Получение сырых записей транспорта из CRM БД или локального кэша
     */
    private function fetchRawTruckData(): array {
        $crm = $this->getCrmConnection();

        if ($crm !== null) {
            try {
                // Проверяем список таблиц в подключенной БД apgk_api08
                $tablesStmt = $this->safeSelect($crm, "SHOW TABLES");
                $tables = $tablesStmt->fetchAll(PDO::FETCH_COLUMN);

                // 1. Если есть таблица transport_queue / queue / cars
                foreach (['transport_queue', 'queue', 'cars', 'truck_queue', 'logistics_queue'] as $candidate) {
                    if (in_array($candidate, $tables, true)) {
                        $stmt = $this->safeSelect($crm, "
                            SELECT truck_number, driver_name, cargo_type, status, recorded_at, entry_time, weighing_time, exit_time,
                                   CASE 
                                       WHEN exit_time IS NOT NULL AND entry_time IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, entry_time, exit_time)
                                       WHEN entry_time IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, entry_time, NOW())
                                       ELSE 0 
                                   END AS stay_duration_minutes
                            FROM `{$candidate}`
                            WHERE recorded_at >= NOW() - INTERVAL 1 DAY
                            ORDER BY id DESC
                            LIMIT 150
                        ");
                        $rows = $stmt->fetchAll();
                        if (!empty($rows)) return $rows;
                    }
                }

                // 2. Если есть таблица requests / export_requests (как в trans-logistics-board)
                if (in_array('requests', $tables, true) || in_array('export_requests', $tables, true)) {
                    $sql = "
                        SELECT 
                            request_number AS truck_number,
                            '' AS driver_name,
                            cargo AS cargo_type,
                            CASE WHEN status = 'closed' THEN 'exited' ELSE 'entered' END AS status,
                            created_at AS recorded_at,
                            created_at AS entry_time,
                            NULL AS weighing_time,
                            closed_at AS exit_time,
                            CASE 
                                WHEN closed_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, created_at, closed_at)
                                ELSE TIMESTAMPDIFF(MINUTE, created_at, NOW())
                            END AS stay_duration_minutes
                        FROM requests
                        WHERE created_at >= NOW() - INTERVAL 7 DAY
                        ORDER BY id DESC
                        LIMIT 100
                    ";
                    $stmt = $this->safeSelect($crm, $sql);
                    $rows = $stmt->fetchAll();
                    if (!empty($rows)) return $rows;
                }

            } catch (Throwable $e) {
                error_log("CRM safe read query error: " . $e->getMessage());
            }
        }

        // Если подключение к внешней CRM отключено или таблица пуста — читаем из локальной БД
        try {
            $db = DashboardDB::getConnection();
            $stmt = $db->query("
                SELECT truck_number, driver_name, cargo_type, status, recorded_at, entry_time, weighing_time, exit_time, stay_duration_minutes
                FROM transport_queue
                ORDER BY id DESC
                LIMIT 100
            ");
            return $stmt->fetchAll();
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
