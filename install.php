<?php
/**
 * Веб-установщик в один клик для Hestia CP
 * Файл: install.php
 */

$isInstalled = file_exists(__DIR__ . '/config.php');
$error = null;
$success = false;
$serverPath = realpath(__DIR__);

// Проверка системных требований
$requirements = [
    'php_version' => [
        'title'  => 'Версия PHP (>= 7.4)',
        'passed' => version_compare(PHP_VERSION, '7.4.0', '>='),
        'detail' => PHP_VERSION
    ],
    'pdo_mysql' => [
        'title'  => 'Расширение PDO MySQL',
        'passed' => extension_loaded('pdo_mysql'),
        'detail' => extension_loaded('pdo_mysql') ? 'Установлено' : 'Отсутствует'
    ],
    'curl' => [
        'title'  => 'Расширение cURL (для Zabbix API)',
        'passed' => extension_loaded('curl'),
        'detail' => extension_loaded('curl') ? 'Установлено' : 'Отсутствует'
    ],
    'write_perm' => [
        'title'  => 'Права на запись в директорию',
        'passed' => is_writable(__DIR__),
        'detail' => is_writable(__DIR__) ? 'Разрешено' : 'Запрещено'
    ],
];

$allReqPassed = !in_array(false, array_column($requirements, 'passed'), true);

// Обработка отправки формы установки
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'install') {
    $dbHost = trim($_POST['db_host'] ?? 'localhost');
    $dbPort = (int)($_POST['db_port'] ?? 3306);
    $dbName = trim($_POST['db_name'] ?? '');
    $dbUser = trim($_POST['db_user'] ?? '');
    $dbPass = $_POST['db_pass'] ?? '';
    $installDemo = isset($_POST['install_demo']);

    if (empty($dbName) || empty($dbUser)) {
        $error = "Пожалуйста, укажите имя базы данных и имя пользователя.";
    } else {
        try {
            // 1. Подключение к MySQL / MariaDB
            try {
                // Сначала пробуем подключиться напрямую к указанной БД
                $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
                $pdo = new PDO($dsn, $dbUser, $dbPass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 5,
                    PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
                ]);
            } catch (PDOException $e) {
                // Если база еще не создана и есть права администратора — пробуем создать
                $dsn = "mysql:host={$dbHost};port={$dbPort};charset=utf8mb4";
                $pdo = new PDO($dsn, $dbUser, $dbPass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 5,
                    PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
                ]);
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $pdo->exec("USE `{$dbName}`");
            }

            // 2. Выполнение схемы таблиц (из database/schema.sql или встроенного резерва)
            $schemaFile = __DIR__ . '/database/schema.sql';
            if (file_exists($schemaFile)) {
                $schemaSql = file_get_contents($schemaFile);
            } else {
                $schemaSql = "
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `material_balance_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `recorded_at` DATETIME NOT NULL,
    `report_date` VARCHAR(50) DEFAULT NULL,
    `shift_type` ENUM('day', 'night') NOT NULL DEFAULT 'day',
    `seed_in_plant` DECIMAL(10, 3) NOT NULL DEFAULT 0.000,
    `seed_in_production` DECIMAL(10, 3) NOT NULL DEFAULT 0.000,
    `oil_out` DECIMAL(10, 3) NOT NULL DEFAULT 0.000,
    `meal_out` DECIMAL(10, 3) NOT NULL DEFAULT 0.000,
    `husk_out` DECIMAL(10, 3) NOT NULL DEFAULT 0.000,
    `conditioning_loss` DECIMAL(10, 3) NOT NULL DEFAULT 0.000,
    `conditioning_loss_pct` DECIMAL(6, 2) NOT NULL DEFAULT 0.00,
    `production_loss` DECIMAL(10, 3) NOT NULL DEFAULT 0.000,
    `production_loss_pct` DECIMAL(6, 2) NOT NULL DEFAULT 0.00,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_recorded_at` (`recorded_at`),
    INDEX `idx_shift` (`recorded_at`, `shift_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `energy_readings` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `recorded_at` DATETIME NOT NULL,
    `report_date` VARCHAR(50) DEFAULT NULL,
    `power_kw` DECIMAL(14, 4) NOT NULL DEFAULT 0.0000,
    `power_kwh_delta` DECIMAL(14, 4) NOT NULL DEFAULT 0.0000,
    `reactive_kvarh` DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    `water_flow_m3_hour` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    `water_m3_delta` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    `sewage_m3` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    `gas_m3` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    `steam_pressure_bar` DECIMAL(5, 2) DEFAULT 0.00,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_energy_time` (`recorded_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `equipment_nodes` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(50) NOT NULL UNIQUE,
    `name` VARCHAR(150) NOT NULL,
    `section` VARCHAR(100) NOT NULL,
    `status` ENUM('running', 'warning', 'stopped', 'maintenance') NOT NULL DEFAULT 'running',
    `load_pct` DECIMAL(5, 1) DEFAULT 100.0,
    `current_temperature_c` DECIMAL(5, 1) DEFAULT NULL,
    `last_update` DATETIME NOT NULL,
    `details` VARCHAR(255) DEFAULT 'Работает штатно'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `transport_queue` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `truck_number` VARCHAR(30) NOT NULL,
    `driver_name` VARCHAR(100) DEFAULT NULL,
    `cargo_type` VARCHAR(100) NOT NULL DEFAULT 'Семечка подсолнечника',
    `status` ENUM('queued', 'entered', 'weighing', 'exited') NOT NULL DEFAULT 'queued',
    `recorded_at` DATETIME NOT NULL,
    `entry_time` DATETIME DEFAULT NULL,
    `weighing_time` DATETIME DEFAULT NULL,
    `exit_time` DATETIME DEFAULT NULL,
    `weight_netto_tonnes` DECIMAL(8, 3) DEFAULT 0.000,
    `stay_duration_minutes` INT DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_transport_status` (`status`),
    INDEX `idx_transport_recorded` (`recorded_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sync_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `source` VARCHAR(50) NOT NULL,
    `status` ENUM('success', 'warning', 'error') NOT NULL,
    `records_processed` INT DEFAULT 0,
    `message` TEXT,
    `executed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `warehouse_stocks` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `recorded_at` DATETIME NOT NULL,
    `report_date` VARCHAR(50) DEFAULT NULL,
    `seed_stock_tonnes` DECIMAL(10, 3) NOT NULL DEFAULT 0.000,
    `seed_stock_accounting_tonnes` DECIMAL(10, 3) NOT NULL DEFAULT 0.000,
    `oil_stock_tonnes` DECIMAL(10, 3) NOT NULL DEFAULT 0.000,
    `meal_stock_tonnes` DECIMAL(10, 3) NOT NULL DEFAULT 0.000,
    `husk_stock_tonnes` DECIMAL(10, 3) NOT NULL DEFAULT 0.000,
    `husk_boiler_spent_tonnes` DECIMAL(10, 3) NOT NULL DEFAULT 0.000,
    `days_seed_reserve` DECIMAL(5, 1) NOT NULL DEFAULT 12.0,
    `oil_shipped_tonnes` DECIMAL(10, 3) NOT NULL DEFAULT 0.000,
    `meal_shipped_tonnes` DECIMAL(10, 3) NOT NULL DEFAULT 0.000,
    `husk_shipped_tonnes` DECIMAL(10, 3) NOT NULL DEFAULT 0.000,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_stock_date` (`recorded_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lab_quality_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `recorded_at` DATETIME NOT NULL,
    `report_date` VARCHAR(50) DEFAULT NULL,
    `oil_content_pct` DECIMAL(5, 2) NOT NULL DEFAULT 49.50,
    `oil_content_base_pct` DECIMAL(5, 2) NOT NULL DEFAULT 48.00,
    `oil_deviation_pct` DECIMAL(5, 2) NOT NULL DEFAULT 1.50,
    `moisture_pct` DECIMAL(5, 2) NOT NULL DEFAULT 7.20,
    `impurity_pct` DECIMAL(5, 2) NOT NULL DEFAULT 3.20,
    `weight_discount_tonnes` DECIMAL(8, 3) NOT NULL DEFAULT 0.000,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_lab_date` (`recorded_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `contracts_summary` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `recorded_at` DATETIME NOT NULL,
    `report_date` VARCHAR(50) DEFAULT NULL,
    `contracted_tonnes` DECIMAL(10, 3) NOT NULL DEFAULT 15000.000,
    `delivered_tonnes` DECIMAL(10, 3) NOT NULL DEFAULT 13328.694,
    `remaining_tonnes` DECIMAL(10, 3) NOT NULL DEFAULT 1671.306,
    `active_specs_count` INT UNSIGNED NOT NULL DEFAULT 14,
    `pending_specs_count` INT UNSIGNED NOT NULL DEFAULT 3,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
";
            }
            $pdo->exec($schemaSql);

            // 2.1 Гарантоване додавання колонки report_date та зняття GENERATED ALWAYS, якщо таблиці вже існували раніше
            $upgradeColumns = [
                'material_balance_logs' => 'report_date',
                'warehouse_stocks'      => 'report_date',
                'lab_quality_logs'      => 'report_date',
                'contracts_summary'     => 'report_date',
                'energy_readings'       => 'report_date',
            ];
            foreach ($upgradeColumns as $tbl => $col) {
                try {
                    $pdo->exec("ALTER TABLE `{$tbl}` ADD COLUMN `{$col}` VARCHAR(50) DEFAULT NULL");
                } catch (Throwable $ignore) {}
            }

            $energyNewCols = [
                'reactive_kvarh' => 'DECIMAL(12, 2) NOT NULL DEFAULT 0.00',
                'sewage_m3'      => 'DECIMAL(10, 2) NOT NULL DEFAULT 0.00',
                'gas_m3'         => 'DECIMAL(10, 2) NOT NULL DEFAULT 0.00',
            ];
            foreach ($energyNewCols as $col => $def) {
                try {
                    $pdo->exec("ALTER TABLE `energy_readings` ADD COLUMN `{$col}` {$def}");
                } catch (Throwable $ignore) {}
            }

            try {
                $pdo->exec("
                    ALTER TABLE `material_balance_logs`
                        MODIFY COLUMN `conditioning_loss` DECIMAL(10, 3) NOT NULL DEFAULT 0.000,
                        MODIFY COLUMN `conditioning_loss_pct` DECIMAL(6, 2) NOT NULL DEFAULT 0.00,
                        MODIFY COLUMN `production_loss` DECIMAL(10, 3) NOT NULL DEFAULT 0.000,
                        MODIFY COLUMN `production_loss_pct` DECIMAL(6, 2) NOT NULL DEFAULT 0.00
                ");
            } catch (Throwable $ignore) {}

            try {
                $pdo->exec("ALTER TABLE `lab_quality_logs` MODIFY COLUMN `oil_deviation_pct` DECIMAL(5, 2) NOT NULL DEFAULT 1.50");
            } catch (Throwable $ignore) {}

            try {
                $pdo->exec("
                    ALTER TABLE `energy_readings`
                        MODIFY COLUMN `power_kw` DECIMAL(14, 4) NOT NULL DEFAULT 0.0000,
                        MODIFY COLUMN `power_kwh_delta` DECIMAL(14, 4) NOT NULL DEFAULT 0.0000,
                        MODIFY COLUMN `water_flow_m3_hour` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
                        MODIFY COLUMN `water_m3_delta` DECIMAL(10, 2) NOT NULL DEFAULT 0.00
                ");
            } catch (Throwable $ignore) {}

            // 3. Загрузка демонстрационных данных при выборе
            if ($installDemo) {
                $seedFile = __DIR__ . '/database/seed_data.sql';
                if (file_exists($seedFile)) {
                    $seedSql = file_get_contents($seedFile);
                    $pdo->exec($seedSql);
                }
            }

            // 5. Создание рабочего config.php
            $sampleConfigFile = __DIR__ . '/config.sample.php';
            $sampleContent = file_exists($sampleConfigFile) ? file_get_contents($sampleConfigFile) : '';

            $configCode = "<?php\n/**\n * Конфігурація дашборда заводу (Згенеровано інсталятором)\n * Дата: " . date('Y-m-d H:i:s') . "\n */\n\nreturn [\n"
                . "    'app_mode' => 'production',\n"
                . "    'app_name' => 'Олійноекстракційний Завод — Єдиний Моніторинг',\n"
                . "    'timezone' => 'Europe/Kyiv',\n\n"
                . "    'db' => [\n"
                . "        'host'     => " . var_export($dbHost, true) . ",\n"
                . "        'port'     => " . $dbPort . ",\n"
                . "        'database' => " . var_export($dbName, true) . ",\n"
                . "        'username' => " . var_export($dbUser, true) . ",\n"
                . "        'password' => " . var_export($dbPass, true) . ",\n"
                . "        'charset'  => 'utf8mb4',\n"
                . "    ],\n\n"
                . "    'crm_db' => [\n"
                . "        'enabled'  => true,\n"
                . "        'driver'   => 'mysql',\n"
                . "        'host'     => 'localhost',\n"
                . "        'port'     => 3306,\n"
                . "        'database' => 'apgk_api08',\n"
                . "        'username' => 'apgk_api08',\n"
                . "        'password' => 'orrz20054Q+',\n"
                . "        'charset'  => 'utf8mb4',\n"
                . "        'readonly' => true,\n"
                . "    ],\n\n"
                . "    'zabbix' => [\n"
                . "        'enabled'  => false,\n"
                . "        'url'      => 'http://127.0.0.1/zabbix/api_jsonrpc.php',\n"
                . "        'token'    => '',\n"
                . "        'user'     => 'Admin',\n"
                . "        'password' => '',\n"
                . "        'items'    => [\n"
                . "            'seed_in_plant_tonnes'     => 'scale.seed.in.plant.total',\n"
                . "            'seed_in_production_tonnes'=> 'scale.seed.in.prod.total',\n"
                . "            'oil_out_tonnes'           => 'production.oil.yield.tonnes',\n"
                . "            'meal_out_tonnes'          => 'production.meal.yield.tonnes',\n"
                . "            'husk_out_tonnes'          => 'production.husk.yield.tonnes',\n"
                . "            'power_kw_current'         => 'power.meter.main.kw',\n"
                . "            'power_kwh_accumulated'    => 'power.meter.main.kwh.day',\n"
                . "            'water_flow_m3_hour'       => 'water.meter.feed.flow.rate',\n"
                . "            'water_m3_accumulated'     => 'water.meter.feed.total.day',\n"
                . "            'status_cleaner'           => 'equipment.cleaner.status',\n"
                . "            'status_dryer'             => 'equipment.dryer.status',\n"
                . "            'status_dehuller'          => 'equipment.dehuller.status',\n"
                . "            'status_press'             => 'equipment.press_line.status',\n"
                . "            'status_extraction'        => 'equipment.extraction.status',\n"
                . "            'status_boiler'            => 'equipment.boiler.status',\n"
                . "        ],\n"
                . "    ],\n\n"
                . "    'thresholds' => [\n"
                . "        'conditioning_loss_warning_pct' => 2.5,\n"
                . "        'conditioning_loss_danger_pct'  => 3.5,\n"
                . "        'production_loss_warning_pct'   => 1.2,\n"
                . "        'production_loss_danger_pct'    => 1.8,\n"
                . "        'truck_stay_target_minutes'     => 120,\n"
                . "        'truck_stay_warning_minutes'    => 180,\n"
                . "        'truck_stay_danger_minutes'     => 240,\n"
                . "        'power_per_tonne_target_kwh'    => 65.0,\n"
                . "        'power_per_tonne_danger_kwh'    => 80.0,\n"
                . "    ],\n\n"
                . "    'refresh_interval_seconds' => 30,\n"
                . "];\n";

            file_put_contents(__DIR__ . '/config.php', $configCode);
            $success = true;

        } catch (Throwable $e) {
            $error = "Ошибка установки: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Установка дашборда завода — Hestia CP</title>
    <style>
        :root {
            --primary: #0284c7;
            --primary-hover: #0369a1;
            --success: #10b981;
            --danger: #ef4444;
            --border: #e2e8f0;
            --text: #1e293b;
            --muted: #64748b;
            --bg: #f8fafc;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
            background: var(--bg);
            color: var(--text);
            padding: 30px 16px;
            display: flex;
            justify-content: center;
        }
        .install-box {
            background: #ffffff;
            border: 1px solid var(--border);
            border-radius: 12px;
            width: 100%;
            max-width: 680px;
            padding: 32px 36px;
            box-shadow: 0 10px 25px -5px rgba(0,0,0,0.06);
        }
        .header {
            text-align: center;
            margin-bottom: 28px;
        }
        .header h1 {
            font-size: 22px;
            font-weight: 800;
            color: var(--text);
            margin-bottom: 6px;
        }
        .header p {
            font-size: 14px;
            color: var(--muted);
        }
        .alert {
            padding: 14px 18px;
            border-radius: 8px;
            font-size: 14px;
            margin-bottom: 24px;
            line-height: 1.4;
        }
        .alert-success { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; }
        .alert-error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
        .alert-warning { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; }

        .req-list {
            background: #f1f5f9;
            border-radius: 8px;
            padding: 14px 18px;
            margin-bottom: 24px;
        }
        .req-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 13px;
            padding: 5px 0;
            border-bottom: 1px solid #e2e8f0;
        }
        .req-item:last-child { border-bottom: none; }
        .badge {
            font-size: 11px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 999px;
        }
        .badge-ok { background: #dcfce7; color: #15803d; }
        .badge-fail { background: #fee2e2; color: #b91c1c; }

        .form-group {
            margin-bottom: 16px;
        }
        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 6px;
        }
        .form-control {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 14px;
            transition: border 0.15s;
        }
        .form-control:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.15);
        }
        .form-row {
            display: grid;
            grid-template-columns: 3fr 1fr;
            gap: 12px;
        }
        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            cursor: pointer;
            margin-top: 10px;
        }
        .btn {
            background: var(--primary);
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 700;
            width: 100%;
            cursor: pointer;
            transition: background 0.15s;
            margin-top: 10px;
        }
        .btn:hover { background: var(--primary-hover); }
        .btn-success { background: var(--success); }
        .btn-success:hover { background: #059669; }

        .cron-code {
            background: #1e293b;
            color: #38bdf8;
            padding: 10px 14px;
            border-radius: 6px;
            font-family: monospace;
            font-size: 12px;
            margin: 6px 0 12px;
            word-break: break-all;
        }
    </style>
</head>
<body>

<div class="install-box">
    <div class="header">
        <h1>⚙️ Установка Дашборда Завода</h1>
        <p>Автоматическая настройка базы данных MariaDB/MySQL и генерация config.php</p>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success">
            <strong>🎉 Установка успешно завершена!</strong><br>
            Таблицы созданы, демо-данные записаны, файл <code>config.php</code> сохранен.
        </div>

        <h3 style="font-size: 15px; margin-bottom: 8px;">📋 Настройте CRON в панели Hestia CP:</h3>
        <p style="font-size: 12px; color: var(--muted); margin-bottom: 6px;">1. Опрос Zabbix API (каждые 5 минут):</p>
        <div class="cron-code">*/5 * * * * /usr/bin/php <?= htmlspecialchars($serverPath) ?>/cron/sync_zabbix.php > /dev/null 2>&1</div>

        <p style="font-size: 12px; color: var(--muted); margin-bottom: 6px;">2. Синхронизация очереди транспорта (каждые 2 минуты):</p>
        <div class="cron-code">*/2 * * * * /usr/bin/php <?= htmlspecialchars($serverPath) ?>/cron/sync_logistics.php > /dev/null 2>&1</div>

        <div class="alert alert-warning" style="margin-top: 16px;">
            ⚠️ <strong>Рекомендация по безопасности:</strong> После первой проверки удалите или переименуйте файл <code>install.php</code> на сервере.
        </div>

        <a href="index.php" style="text-decoration: none;">
            <button type="button" class="btn btn-success">🚀 Открыть Дашборд</button>
        </a>

    <?php else: ?>

        <?php if ($error): ?>
            <div class="alert alert-error">
                <strong>Ошибка:</strong> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($isInstalled): ?>
            <div class="alert alert-warning">
                ℹ️ <strong>Файл config.php уже существует.</strong> Если вы повторите установку, база данных будет перезаписана новыми таблицами.
            </div>
        <?php endif; ?>

        <!-- Системные требования -->
        <div class="req-list">
            <div style="font-weight: 700; font-size: 13px; margin-bottom: 6px;">Проверка сервера:</div>
            <?php foreach ($requirements as $req): ?>
                <div class="req-item">
                    <span><?= $req['title'] ?>: <strong><?= $req['detail'] ?></strong></span>
                    <span class="badge <?= $req['passed'] ? 'badge-ok' : 'badge-fail' ?>">
                        <?= $req['passed'] ? 'OK' : 'ОШИБКА' ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>

        <form method="POST" action="install.php">
            <input type="hidden" name="action" value="install">

            <div class="form-row">
                <div class="form-group">
                    <label>Хост базы данных (MySQL / MariaDB):</label>
                    <input type="text" name="db_host" class="form-control" value="localhost" required>
                </div>
                <div class="form-group">
                    <label>Порт:</label>
                    <input type="number" name="db_port" class="form-control" value="3306" required>
                </div>
            </div>

            <div class="form-group">
                <label>Имя базы данных (с префиксом Hestia CP):</label>
                <input type="text" name="db_name" class="form-control" placeholder="например: apgk_dash-api08" value="" required>
                <small style="color: var(--muted); font-size: 11px;">В Hestia CP указывайте полное имя базы данных с префиксом (например, <code>apgk_dash-api08</code>)</small>
            </div>

            <div class="form-group">
                <label>Пользователь базы данных (с префиксом Hestia CP):</label>
                <input type="text" name="db_user" class="form-control" placeholder="например: apgk_dash-api08" value="" required>
                <small style="color: var(--muted); font-size: 11px;">Полное имя пользователя в Hestia CP (например, <code>apgk_dash-api08</code>)</small>
            </div>

            <div class="form-group">
                <label>Пароль пользователя БД:</label>
                <input type="password" name="db_pass" class="form-control" placeholder="Пароль">
            </div>

            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" name="install_demo" value="1" checked>
                    <span>Загрузить тестовые данные (смены, баланс семян, очередь грузовиков, энергетика)</span>
                </label>
            </div>

            <button type="submit" class="btn" <?= !$allReqPassed ? 'disabled style="opacity: 0.5;"' : '' ?>>
                ⚡ Установить всё в 1 клик
            </button>
        </form>

    <?php endif; ?>
</div>

</body>
</html>
