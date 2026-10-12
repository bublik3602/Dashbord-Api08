<?php
/**
 * Подключение к локальной базе данных дашборда (PDO MySQL / MariaDB)
 * с поддержкой демо-режима и отказоустойчивости.
 */

require_once __DIR__ . '/helpers.php';

class DashboardDB {
    private static ?PDO $instance = null;
    private static bool $isFallback = false;

    public static function getConnection(): PDO {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $config = get_config();
        $dbCfg = $config['db'] ?? [];
        $appMode = $config['app_mode'] ?? 'demo';

        // Попытка подключения к MySQL / MariaDB
        try {
            $host = $dbCfg['host'] ?? '127.0.0.1';
            $port = $dbCfg['port'] ?? 3306;
            $dbname = $dbCfg['database'] ?? 'dashboard_zavod';
            $charset = $dbCfg['charset'] ?? 'utf8mb4';

            $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 2, // Быстрый таймаут для проверки доступности
            ];

            self::$instance = new PDO($dsn, $dbCfg['username'] ?? 'root', $dbCfg['password'] ?? '', $options);
            self::$isFallback = false;
            return self::$instance;

        } catch (PDOException $e) {
            // Если в режиме demo или MySQL недоступен локально — используем SQLite для демонстрации
            if ($appMode === 'demo') {
                self::$isFallback = true;
                return self::initSqliteFallback();
            }

            // В production режиме выбрасываем понятную ошибку
            throw new Exception("Ошибка подключения к базе данных MySQL ({$dbCfg['host']}): " . $e->getMessage());
        }
    }

    /**
     * Инициализация локального SQLite для мгновенного предпросмотра без MySQL
     */
    private static function initSqliteFallback(): PDO {
        $dbPath = __DIR__ . '/../database/dashboard_demo.sqlite';
        $isNew = !file_exists($dbPath);

        $pdo = new PDO("sqlite:" . $dbPath, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        if ($isNew) {
            self::seedSqlite($pdo);
        }

        self::$instance = $pdo;
        return self::$instance;
    }

    /**
     * Создание таблиц и демо-записей для SQLite
     */
    private static function seedSqlite(PDO $pdo): void {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS material_balance_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                recorded_at DATETIME NOT NULL,
                report_date TEXT DEFAULT NULL,
                shift_type TEXT NOT NULL DEFAULT 'day',
                seed_in_plant REAL NOT NULL DEFAULT 0,
                seed_in_production REAL NOT NULL DEFAULT 0,
                oil_out REAL NOT NULL DEFAULT 0,
                meal_out REAL NOT NULL DEFAULT 0,
                husk_out REAL NOT NULL DEFAULT 0,
                conditioning_loss REAL NOT NULL DEFAULT 0,
                conditioning_loss_pct REAL NOT NULL DEFAULT 0,
                production_loss REAL NOT NULL DEFAULT 0,
                production_loss_pct REAL NOT NULL DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS energy_readings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                recorded_at DATETIME NOT NULL,
                power_kw REAL NOT NULL DEFAULT 0,
                power_kwh_delta REAL NOT NULL DEFAULT 0,
                water_flow_m3_hour REAL NOT NULL DEFAULT 0,
                water_m3_delta REAL NOT NULL DEFAULT 0,
                steam_pressure_bar REAL DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS equipment_nodes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                code TEXT NOT NULL UNIQUE,
                name TEXT NOT NULL,
                section TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'running',
                load_pct REAL DEFAULT 100,
                current_temperature_c REAL DEFAULT NULL,
                last_update DATETIME NOT NULL,
                details TEXT DEFAULT 'Работает штатно'
            );

            CREATE TABLE IF NOT EXISTS transport_queue (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                truck_number TEXT NOT NULL,
                driver_name TEXT,
                cargo_type TEXT NOT NULL DEFAULT 'Семечка подсолнечника',
                status TEXT NOT NULL DEFAULT 'queued',
                recorded_at DATETIME NOT NULL,
                entry_time DATETIME,
                weighing_time DATETIME,
                exit_time DATETIME,
                weight_netto_tonnes REAL DEFAULT 0,
                stay_duration_minutes INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS sync_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                source TEXT NOT NULL,
                status TEXT NOT NULL,
                records_processed INTEGER DEFAULT 0,
                message TEXT,
                executed_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
        ");

        // Заполнение базовыми данными
        $now = date('Y-m-d H:i:s');
        
        // Оборудование
        $pdo->exec("
            INSERT INTO equipment_nodes (code, name, section, status, load_pct, current_temperature_c, last_update, details) VALUES
            ('cleaner_01', 'Сепаратор БИС-100 (Очистка семян)', 'Приемно-очистительный участок', 'running', 94.5, 24.2, '{$now}', 'Сорность 1.4% (Норма)'),
            ('dryer_01', 'Сушильный комплекс зерновой КЗС-50', 'Сушильный участок', 'running', 91.0, 78.5, '{$now}', 'Влажность 7.2% (Норма)'),
            ('dehuller_01', 'Рушально-веечный агрегат РВО-2', 'Подготовительный цех', 'running', 98.2, 36.8, '{$now}', 'Масличность лузги 0.8% (Штатно)'),
            ('press_line_01', 'Форпрессы МП-68 (Линии 1 и 2)', 'Прессовый цех', 'running', 96.0, 102.4, '{$now}', 'Отжим масла в норме, T зеера 102°C'),
            ('extraction_01', 'Экстрактор карусельный НД-1250', 'Цех экстракции', 'running', 89.5, 58.0, '{$now}', 'Расход растворителя 1.1 кг/т'),
            ('boiler_01', 'Паровой котел на лузге ДКВР-10', 'Энергокомплекс / Котельная', 'running', 92.0, 195.0, '{$now}', 'Давление пара 11.2 бар');
        ");

        // Реальні щоденні дані за Жовтень 2026 з графіку прийомки заводу (01.10 — 11.10)
        $octoberDays = [
            ['2026-10-01', 549.5, 533.0, 234.5, 218.5, 73.5],
            ['2026-10-02', 597.0, 579.1, 254.8, 237.4, 79.9],
            ['2026-10-03', 720.7, 699.1, 307.6, 286.6, 96.5],
            ['2026-10-04', 441.0, 427.8, 188.2, 175.4, 59.0],
            ['2026-10-05', 661.8, 641.9, 282.4, 263.2, 88.6],
            ['2026-10-06', 589.8, 572.1, 251.7, 234.6, 78.9],
            ['2026-10-07', 501.9, 486.8, 214.2, 199.6, 67.2],
            ['2026-10-08', 609.3, 591.0, 260.0, 242.3, 81.6],
            ['2026-10-09', 439.3, 426.1, 187.5, 174.7, 58.8],
            ['2026-10-10', 499.9, 484.9, 213.4, 198.8, 66.9],
            ['2026-10-11', 465.8, 452.0, 198.8, 185.3, 62.4], // Минула виробнича доба
        ];

        foreach ($octoberDays as $od) {
            $dStr = $od[0];
            $inPlant = $od[1];
            $inProd = $od[2];
            $oil = $od[3];
            $meal = $od[4];
            $husk = $od[5];
            $lossCond = round($inPlant - $inProd, 1);
            $lossCondPct = round(($lossCond / $inPlant) * 100, 2);
            $lossProd = round($inProd - ($oil + $meal + $husk), 1);
            $lossProdPct = round(($lossProd / $inProd) * 100, 2);

            $stmt = $pdo->prepare("
                INSERT INTO material_balance_logs 
                (recorded_at, shift_type, seed_in_plant, seed_in_production, oil_out, meal_out, husk_out, conditioning_loss, conditioning_loss_pct, production_loss, production_loss_pct)
                VALUES (?, 'day', ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute(["{$dStr} 20:00:00", $inPlant, $inProd, $oil, $meal, $husk, $lossCond, $lossCondPct, $lossProd, $lossProdPct]);
        }

        // Реальні заміри електроенергії та води (Жовтень 2026: з журналу вхідного лічильника 35973 -> 38391 та ТП)
        $energyDays = [
            ['2026-10-01 20:00:00', 1225.4, 29410.0, 8.79, 211.0, 11.2],
            ['2026-10-02 20:00:00', 1304.6, 31310.0, 6.33, 152.0, 11.2],
            ['2026-10-03 20:00:00', 1325.0, 31800.0, 9.79, 235.0, 11.3],
            ['2026-10-04 20:00:00', 1321.3, 31710.0, 8.58, 206.0, 11.2],
            ['2026-10-05 20:00:00', 1300.4, 31210.0, 8.58, 206.0, 11.2],
            ['2026-10-06 20:00:00', 1330.4, 31930.0, 8.58, 206.0, 11.3],
            ['2026-10-07 20:00:00',  477.1, 11450.0, 5.88, 141.0,  8.4],
            ['2026-10-08 20:00:00',  200.8,  4820.0, 8.04, 193.0,  0.0],
            ['2026-10-09 20:00:00',  183.8,  4410.0, 6.29, 151.0,  0.0],
            ['2026-10-10 20:00:00',  193.8,  4650.0, 9.42, 226.0,  0.0],
            ['2026-10-11 20:00:00',  188.3,  4520.0, 6.82, 163.7,  0.0],
            ['2026-10-12 08:00:00',  185.0,  2220.0, 6.80,  81.6,  0.0],
        ];
        foreach ($energyDays as $ed) {
            $stmt = $pdo->prepare("
                INSERT INTO energy_readings (recorded_at, power_kw, power_kwh_delta, water_flow_m3_hour, water_m3_delta, steam_pressure_bar)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$ed[0], $ed[1], $ed[2], $ed[3], $ed[4], $ed[5]]);
        }

        // Грузовики
        $trucks = [
            ['А742ВХ 161', 'Иванов С. М.', 'Семечка подсолнечника', 'queued', -45, null, null, null, 0, 0],
            ['В819СВ 134', 'Петров Д. К.', 'Семечка подсолнечника', 'queued', -30, null, null, null, 0, 0],
            ['О512МК 64',  'Смирнов А. В.', 'Семечка подсолнечника', 'queued', -25, null, null, null, 0, 0],
            ['Е931ТТ 161', 'Кузнецов В. И.', 'Семечка подсолнечника', 'queued', -15, null, null, null, 0, 0],
            ['М204РО 26',  'Попов Е. Г.', 'Масло налив', 'queued', -10, null, null, null, 0, 0],
            ['К312ЕХ 61',  'Васильев П. О.', 'Семечка подсолнечника', 'entered', -75, -40, null, null, 0, 40],
            ['Р405АВ 36',  'Николаев Р. С.', 'Семечка подсолнечника', 'entered', -65, -35, null, null, 0, 35],
            ['Т621ОО 161', 'Соколов В. П.', 'Масло налив', 'entered', -50, -25, null, null, 0, 25],
            ['Н543КВ 161', 'Морозов К. И.', 'Семечка подсолнечника', 'weighing', -95, -55, -6, null, 28.45, 55],
            ['В119ТР 68',  'Новиков Г. А.', 'Семечка подсолнечника', 'weighing', -85, -48, -3, null, 31.20, 48],
            ['А102ВК 61', 'Павлов С. В.', 'Семечка подсолнечника', 'exited', -300, -240, -180, -150, 29.8, 90],
            ['Р218СХ 161', 'Козлов М. Д.', 'Семечка подсолнечника', 'exited', -270, -220, -160, -120, 32.15, 100],
            ['О432ТМ 31', 'Лебедев Д. Н.', 'Семечка подсолнечника', 'exited', -240, -195, -130, -105, 27.9, 90],
            ['Е501ХР 161', 'Семенов Б. И.', 'Масло налив', 'exited', -210, -165, -110, -75, 24.5, 90],
            ['К612АС 26', 'Егоров А. М.', 'Шрот насыпью', 'exited', -180, -140, -80, -50, 26.3, 90],
            ['М723ММ 161', 'Волков П. Е.', 'Семечка подсолнечника', 'exited', -150, -110, -55, -20, 30.7, 90],
        ];

        foreach ($trucks as $t) {
            $rec = date('Y-m-d H:i:s', strtotime("{$t[4]} minutes"));
            $ent = $t[5] ? date('Y-m-d H:i:s', strtotime("{$t[5]} minutes")) : null;
            $wei = $t[6] ? date('Y-m-d H:i:s', strtotime("{$t[6]} minutes")) : null;
            $exi = $t[7] ? date('Y-m-d H:i:s', strtotime("{$t[7]} minutes")) : null;
            $dur = $t[9];

            $stmt = $pdo->prepare("
                INSERT INTO transport_queue (truck_number, driver_name, cargo_type, status, recorded_at, entry_time, weighing_time, exit_time, weight_netto_tonnes, stay_duration_minutes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$t[0], $t[1], $t[2], $t[3], $rec, $ent, $wei, $exi, $t[8], $dur]);
        }
    }

    public static function isFallback(): bool {
        return self::$isFallback;
    }
}
