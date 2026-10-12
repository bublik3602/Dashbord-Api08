<?php
/**
 * Клиент интеграции с CRM системой (Электронная очередь автотранспорта)
 * Поддерживает прямое SQL-подключение к внешней БД CRM или работу через локальную реплику/таблицу.
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
     * Подключение к внешней базе данных CRM (если включено)
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
            $host   = $this->config['host'] ?? '127.0.0.1';
            $port   = $this->config['port'] ?? 3306;
            $dbname = $this->config['database'] ?? 'crm_transport';
            $charset= $this->config['charset'] ?? 'utf8mb4';

            $dsn = "{$driver}:host={$host};port={$port};dbname={$dbname};charset={$charset}";
            $this->crmPdo = new PDO(
                $dsn,
                $this->config['username'] ?? '',
                $this->config['password'] ?? '',
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_TIMEOUT            => 3,
                ]
            );
            return $this->crmPdo;
        } catch (Exception $e) {
            error_log("Ошибка подключения к БД CRM: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Получение текущей воронки транспорта и статистики
     */
    public function getTransportFunnel(): array {
        $crm = $this->getCrmConnection();

        if ($crm !== null) {
            // Если подключена внешняя CRM, запрашиваем из неё
            try {
                $stmt = $crm->query($this->config['query_funnel'] ?? "SELECT status, COUNT(*) as count FROM transport_queue WHERE DATE(created_at) = CURDATE() GROUP BY status");
                $counts = [];
                while ($row = $stmt->fetch()) {
                    $counts[$row['status']] = (int)$row['count'];
                }

                $avgStmt = $crm->query($this->config['query_avg_time'] ?? "SELECT AVG(TIMESTAMPDIFF(MINUTE, entry_time, exit_time)) as avg_minutes FROM transport_queue WHERE exit_time >= NOW() - INTERVAL 1 DAY");
                $avgRow = $avgStmt->fetch();
                $avgMinutes = (int)round($avgRow['avg_minutes'] ?? 95);

                return [
                    'queued'             => $counts['queued'] ?? 0,
                    'entered'            => $counts['entered'] ?? 0,
                    'weighing'           => $counts['weighing'] ?? 0,
                    'exited'             => $counts['exited'] ?? 0,
                    'total_active'       => ($counts['queued'] ?? 0) + ($counts['entered'] ?? 0) + ($counts['weighing'] ?? 0),
                    'avg_stay_minutes'   => $avgMinutes,
                    'source'             => 'crm_direct'
                ];
            } catch (Exception $e) {
                error_log("Ошибка запроса к внешней CRM: " . $e->getMessage());
            }
        }

        // Если внешняя CRM не подключена — берем из локальной БД дашборда
        $db = DashboardDB::getConnection();
        $stmt = $db->query("SELECT status, COUNT(*) as cnt FROM transport_queue GROUP BY status");
        $counts = [];
        while ($row = $stmt->fetch()) {
            $counts[$row['status']] = (int)$row['cnt'];
        }

        $avgStmt = $db->query("SELECT AVG(stay_duration_minutes) as avg_mins FROM transport_queue WHERE status = 'exited'");
        $avgRow = $avgStmt->fetch();
        $avgMinutes = (int)round($avgRow['avg_mins'] ?? 90);

        return [
            'queued'           => $counts['queued'] ?? 6,
            'entered'          => $counts['entered'] ?? 4,
            'weighing'         => $counts['weighing'] ?? 2,
            'exited'           => $counts['exited'] ?? 6,
            'total_active'     => ($counts['queued'] ?? 6) + ($counts['entered'] ?? 4) + ($counts['weighing'] ?? 2),
            'avg_stay_minutes' => $avgMinutes ?: 90,
            'source'           => 'local_db'
        ];
    }

    /**
     * Получение детального списка активных грузовиков
     */
    public function getActiveTrucksList(): array {
        $db = DashboardDB::getConnection();
        $stmt = $db->query("
            SELECT truck_number, driver_name, cargo_type, status, recorded_at, entry_time, weighing_time, stay_duration_minutes
            FROM transport_queue
            WHERE status != 'exited'
            ORDER BY 
                CASE status 
                    WHEN 'weighing' THEN 1 
                    WHEN 'entered' THEN 2 
                    WHEN 'queued' THEN 3 
                    ELSE 4 
                END, recorded_at ASC
            LIMIT 25
        ");
        return $stmt->fetchAll();
    }
}
