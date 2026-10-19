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

        // Реальні щоденні дані за Серпень (ППР) та Жовтень 2026
        $prodDays = [
            // Серпень 2026: ППР (28.08 завезено 78.59 т; 31.08 завезено 129.37 т)
            ['2026-08-01', 0.0, 0.0, 0.0, 0.0, 0.0],
            ['2026-08-28', 78.59, 0.0, 0.0, 0.0, 0.0],
            ['2026-08-31', 129.37, 0.0, 0.0, 0.0, 0.0],
            // Вересень 2026: ППР (01.09 - 14.09) та активна переробка (15.09 - 30.09)
            ['2026-09-01', 0.0, 0.0, 0.0, 0.0, 0.0],
            ['2026-09-02', 33.91, 0.0, 0.0, 0.0, 0.0],
            ['2026-09-03', 133.23, 0.0, 0.0, 0.0, 0.0],
            ['2026-09-04', 454.84, 0.0, 0.0, 0.0, 0.0],
            ['2026-09-07', 548.04, 0.0, 0.0, 0.0, 0.0],
            ['2026-09-08', 427.28, 0.0, 0.0, 0.0, 0.0],
            ['2026-09-09', 667.50, 0.0, 0.0, 0.0, 0.0],
            ['2026-09-10', 699.26, 0.0, 0.0, 0.0, 0.0],
            ['2026-09-11', 656.38, 0.0, 0.0, 0.0, 0.0],
            ['2026-09-12', 547.77, 0.0, 0.0, 0.0, 0.0],
            ['2026-09-13', 324.41, 0.0, 0.0, 0.0, 0.0],
            ['2026-09-14', 586.29, 0.0, 0.0, 0.0, 0.0],
            ['2026-09-15', 746.44, 678.88, 285.13, 257.98, 93.69],
            ['2026-09-16', 696.39, 299.19, 111.71, 136.42, 36.80],
            ['2026-09-17', 707.73, 595.83, 254.34, 250.45, 57.98],
            ['2026-09-18', 558.48, 592.30, 257.25, 244.73, 87.54],
            ['2026-09-19', 579.07, 600.33, 259.40, 255.25, 89.14],
            ['2026-09-20', 0.00, 594.40, 255.63, 247.43, 84.19],
            ['2026-09-21', 601.05, 601.61, 259.31, 253.44, 86.99],
            ['2026-09-22', 656.76, 600.32, 258.07, 257.36, 84.23],
            ['2026-09-23', 410.38, 243.14, 106.58, 105.03, 35.39],
            ['2026-09-24', 791.70, 481.76, 202.87, 191.04, 69.21],
            ['2026-09-25', 514.17, 598.24, 258.07, 258.71, 84.19],
            ['2026-09-26', 781.59, 585.45, 255.83, 242.04, 80.12],
            ['2026-09-27', 703.35, 589.23, 263.98, 251.21, 79.79],
            ['2026-09-28', 588.91, 585.40, 259.44, 243.25, 86.59],
            ['2026-09-29', 475.29, 598.44, 270.14, 247.72, 90.10],
            ['2026-09-30', 595.05, 579.16, 253.91, 241.15, 88.12],
            // Жовтень 2026: переробка з довідки МЕЗ
            ['2026-10-01', 549.5, 533.0, 234.5, 218.5, 73.5],
            ['2026-10-02', 597.0, 579.1, 254.8, 237.4, 79.9],
            ['2026-10-03', 720.7, 699.1, 307.6, 286.6, 96.5],
            ['2026-10-04', 441.0, 427.8, 188.2, 175.4, 59.0],
            ['2026-10-05', 661.8, 641.9, 282.4, 263.2, 88.6],
            ['2026-10-06', 589.8, 572.1, 251.7, 234.6, 78.9],
            ['2026-10-07', 501.9, 486.8, 214.2, 199.6, 67.2],
            ['2026-10-08', 609.3, 591.0, 260.0, 242.3, 81.6],
            ['2026-10-09', 439.3, 426.1, 187.5, 174.7, 58.8],
            ['2026-10-11', 465.8, 0.0, 0.0, 0.0, 0.0],
            ['2026-10-12', 465.8, 0.0, 0.0, 0.0, 0.0],
            ['2026-10-13', 563.6, 521.0, 210.4, 180.9, 76.4],
            ['2026-10-14', 318.7, 596.8, 256.5, 248.8, 87.0],
            ['2026-10-15', 436.5, 309.0, 149.3, 156.1, 45.9],
            ['2026-10-16', 0.0, 0.0, 0.0, 0.0, 0.0],
            ['2026-10-17', 0.0, 0.0, 0.0, 0.0, 0.0],
            ['2026-10-18', 153.5, 0.0, 0.0, 0.0, 0.0],
        ];

        foreach ($prodDays as $od) {
            $dStr = $od[0];
            $inPlant = $od[1];
            $inProd = $od[2];
            $oil = $od[3];
            $meal = $od[4];
            $husk = $od[5];
            $lossCond = round(max(0, $inPlant - $inProd), 1);
            $lossCondPct = $inPlant > 0 ? round(($lossCond / $inPlant) * 100, 2) : 0.0;
            $lossProd = round($inProd - ($oil + $meal + $husk), 1);
            $lossProdPct = $inProd > 0 ? round(($lossProd / $inProd) * 100, 2) : 0.0;

            $stmt = $pdo->prepare("
                INSERT INTO material_balance_logs 
                (recorded_at, shift_type, seed_in_plant, seed_in_production, oil_out, meal_out, husk_out, conditioning_loss, conditioning_loss_pct, production_loss, production_loss_pct)
                VALUES (?, 'day', ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute(["{$dStr} 20:00:00", $inPlant, $inProd, $oil, $meal, $husk, $lossCond, $lossCondPct, $lossProd, $lossProdPct]);
        }

        // Щомісячні підсумки енергетики та води (Січень — Серпень 2026)
        $energyDays = [
            ['2026-01-31 20:00:00', 112.94, 84026.00,  1.82, 1300.00, 8.5],
            ['2026-02-28 20:00:00', 643.66, 432539.00, 4.32, 2901.00, 11.2],
            ['2026-03-31 20:00:00', 281.76, 209627.00, 2.44, 1819.00, 10.4],
            ['2026-04-30 20:00:00', 379.28, 273083.00, 2.71, 1954.00, 11.0],
            ['2026-05-31 20:00:00',  66.56, 49518.00,  0.44,  324.00, 0.0],
            ['2026-06-30 20:00:00', 354.49, 255231.13, 2.80, 2013.00, 11.1],
            ['2026-07-31 20:00:00',  63.07, 46923.00,  0.62,  464.00, 0.0],
            ['2026-08-31 20:00:00',  66.37, 49379.00,  0.83,  616.00, 0.0],
        ];
        foreach ($energyDays as $ed) {
            $stmt = $pdo->prepare("
                INSERT INTO energy_readings (recorded_at, power_kw, power_kwh_delta, water_flow_m3_hour, water_m3_delta, steam_pressure_bar)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$ed[0], $ed[1], $ed[2], $ed[3], $ed[4], $ed[5]]);
        }

        // Автомобілі (реальні ТТН та українські держномери з Листа погодження 1С)
        $trucks = [
            ['АР 5872 ІХ', 'Шевченківське АПК ТОВ', 'Семечка подсолнечника', 'queued', -45, null, null, null, 0, 0],
            ['AP 5976 IX', 'ЧИСТОПІЛЛЯ ТОВ',        'Семечка подсолнечника', 'queued', -30, null, null, null, 0, 0],
            ['КА 1495 РЕ', 'ЧИСТОПІЛЛЯ ТОВ',        'Семечка подсолнечника', 'queued', -25, null, null, null, 0, 0],
            ['АР 4635 ЕА', 'ЧИСТОПІЛЛЯ ТОВ',        'Семечка подсолнечника', 'queued', -15, null, null, null, 0, 0],
            ['АР 6422 ЕВ', 'ЧИСТОПІЛЛЯ ТОВ',        'Семечка подсолнечника', 'queued', -10, null, null, null, 0, 0],
            ['КР 2765 АВ', 'Узун С(Ф)Г',            'Семечка подсолнечника', 'queued', -5,  null, null, null, 0, 0],
            ['ВТ 3814 АР', 'Узун С(Ф)Г',            'Семечка подсолнечника', 'entered', -75, -40, null, null, 0, 40],
            ['АР 5629 ІО', 'Узун С(Ф)Г',            'Семечка подсолнечника', 'entered', -65, -35, null, null, 0, 35],
            ['АР 9158 ЕМ', 'Скорук А.А. ФГ',        'Семечка подсолнечника', 'entered', -50, -25, null, null, 0, 25],
            ['АР 4683 КА', 'Скорук А.А. ФГ',        'Семечка подсолнечника', 'entered', -40, -18, null, null, 0, 20],
            ['AP 2105 KB', 'Скорук А.А. ФГ',        'Семечка подсолнечника', 'weighing', -95, -55, -6, null, 18.30, 55],
            ['AP 7279 IX', 'Скорук А.А. ФГ',        'Семечка подсолнечника', 'weighing', -85, -48, -3, null, 24.48, 48],
            ['ВТ 3119 ВА', 'РОЗА-АГРО ТОВ',         'Семечка подсолнечника', 'exited', -300, -240, -180, -150, 32.23, 90],
            ['AP 9781 HC', 'АЛТРЕЙД ЛТД ТОВ',       'Семечка подсолнечника', 'exited', -270, -220, -160, -120, 22.38, 100],
            ['14337',      'Шевченківське АПК ТОВ', 'Семечка подсолнечника', 'exited', -240, -195, -130, -105, 20.94, 90],
            ['АР 5872 ІХ', 'Шевченківське АПК ТОВ', 'Масло налив',          'exited', -210, -165, -110, -75, 26.84, 90],
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
