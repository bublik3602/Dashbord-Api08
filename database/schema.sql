-- ===================================================================
-- Схема базы данных: Единый мониторинг завода (Маслоэкстракционный)
-- Совместимо с MariaDB 10.4+ и MySQL 5.7+ / 8.0+
-- ===================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- 1. Таблица материального баланса и переработки сырья
CREATE TABLE IF NOT EXISTS `material_balance_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `recorded_at` DATETIME NOT NULL COMMENT 'Время замера или фиксации смены',
    `shift_type` ENUM('day', 'night') NOT NULL DEFAULT 'day' COMMENT 'Смена: дневная (8-20) или ночная (20-8)',
    
    -- Входящие потоки сырья (тонн)
    `seed_in_plant` DECIMAL(10, 3) NOT NULL DEFAULT 0.000 COMMENT 'Вход: Семечка на завод (по весовой)',
    `seed_in_production` DECIMAL(10, 3) NOT NULL DEFAULT 0.000 COMMENT 'Вход: Семечка в производство (после очистки/сушилки)',
    
    -- Выходящие потоки готовой продукции (тонн)
    `oil_out` DECIMAL(10, 3) NOT NULL DEFAULT 0.000 COMMENT 'Выход: Масло подсолнечное',
    `meal_out` DECIMAL(10, 3) NOT NULL DEFAULT 0.000 COMMENT 'Выход: Шрот тостированный',
    `husk_out` DECIMAL(10, 3) NOT NULL DEFAULT 0.000 COMMENT 'Выход: Лузга гранулированная/топливная',
    
    -- Расчетные потери (тонн и проценты)
    `conditioning_loss` DECIMAL(10, 3) GENERATED ALWAYS AS (`seed_in_plant` - `seed_in_production`) STORED COMMENT 'Потери при подработке (усушка/сор)',
    `conditioning_loss_pct` DECIMAL(6, 2) GENERATED ALWAYS AS (
        CASE WHEN `seed_in_plant` > 0 
             THEN ROUND(((`seed_in_plant` - `seed_in_production`) / `seed_in_plant`) * 100, 2)
             ELSE 0.00 END
    ) STORED COMMENT 'Потери подработки, %',
    
    `production_loss` DECIMAL(10, 3) GENERATED ALWAYS AS (`seed_in_production` - (`oil_out` + `meal_out` + `husk_out`)) STORED COMMENT 'Производственные потери переработки',
    `production_loss_pct` DECIMAL(6, 2) GENERATED ALWAYS AS (
        CASE WHEN `seed_in_production` > 0 
             THEN ROUND(((`seed_in_production` - (`oil_out` + `meal_out` + `husk_out`)) / `seed_in_production`) * 100, 2)
             ELSE 0.00 END
    ) STORED COMMENT 'Потери переработки, %',
    
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_recorded_at` (`recorded_at`),
    INDEX `idx_shift` (`recorded_at`, `shift_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Таблица логов энергопотребления и расхода воды
CREATE TABLE IF NOT EXISTS `energy_readings` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `recorded_at` DATETIME NOT NULL,
    `power_kw` DECIMAL(10, 2) NOT NULL DEFAULT 0.00 COMMENT 'Текущая мощность, кВт',
    `power_kwh_delta` DECIMAL(10, 2) NOT NULL DEFAULT 0.00 COMMENT 'Потребление за период, кВт*ч',
    `water_flow_m3_hour` DECIMAL(8, 2) NOT NULL DEFAULT 0.00 COMMENT 'Мгновенный расход воды, м3/час',
    `water_m3_delta` DECIMAL(8, 2) NOT NULL DEFAULT 0.00 COMMENT 'Расход воды за период, м3',
    `steam_pressure_bar` DECIMAL(5, 2) DEFAULT 0.00 COMMENT 'Давление пара из котельной, бар',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_energy_time` (`recorded_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Состояние узлов и производственного оборудования
CREATE TABLE IF NOT EXISTS `equipment_nodes` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(50) NOT NULL UNIQUE COMMENT 'Код оборудования в Zabbix',
    `name` VARCHAR(150) NOT NULL COMMENT 'Название узла',
    `section` VARCHAR(100) NOT NULL COMMENT 'Цех/Участок (Приемка, Подработка, Прессовый, Экстракция, Котельная)',
    `status` ENUM('running', 'warning', 'stopped', 'maintenance') NOT NULL DEFAULT 'running',
    `load_pct` DECIMAL(5, 1) DEFAULT 100.0 COMMENT 'Текущая нагрузка, %',
    `current_temperature_c` DECIMAL(5, 1) DEFAULT NULL,
    `last_update` DATETIME NOT NULL,
    `details` VARCHAR(255) DEFAULT 'Работает штатно'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Логистика: Электронная очередь транспорта (копия из CRM или синхронизированные данные)
CREATE TABLE IF NOT EXISTS `transport_queue` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `truck_number` VARCHAR(30) NOT NULL COMMENT 'Госномер грузовика',
    `driver_name` VARCHAR(100) DEFAULT NULL,
    `cargo_type` VARCHAR(100) NOT NULL DEFAULT 'Семечка подсолнечника' COMMENT 'Вид груза: Семечка, Масло налив, Шрот',
    `status` ENUM('queued', 'entered', 'weighing', 'exited') NOT NULL DEFAULT 'queued' COMMENT 'Статус в воронке',
    
    `recorded_at` DATETIME NOT NULL COMMENT 'Время записи в электронную очередь',
    `entry_time` DATETIME DEFAULT NULL COMMENT 'Время въезда на территорию',
    `weighing_time` DATETIME DEFAULT NULL COMMENT 'Время на автовесах (брутто/нетто)',
    `exit_time` DATETIME DEFAULT NULL COMMENT 'Время выезда с завода',
    
    `weight_netto_tonnes` DECIMAL(8, 3) DEFAULT 0.000,
    `stay_duration_minutes` INT DEFAULT 0 COMMENT 'Время на заводе (минуты)',
    
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_transport_status` (`status`),
    INDEX `idx_transport_recorded` (`recorded_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Журнал синхронизации (Zabbix & CRM)
CREATE TABLE IF NOT EXISTS `sync_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `source` VARCHAR(50) NOT NULL COMMENT 'zabbix или crm',
    `status` ENUM('success', 'warning', 'error') NOT NULL,
    `records_processed` INT DEFAULT 0,
    `message` TEXT,
    `executed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Складські залишки готової продукції та сировини
CREATE TABLE IF NOT EXISTS `warehouse_stocks` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `recorded_at` DATETIME NOT NULL COMMENT 'Дата фіксації залишків',
    `seed_stock_tonnes` DECIMAL(10, 3) NOT NULL DEFAULT 0.000 COMMENT 'Залишок насіння на елеваторі (фіз. вага), т',
    `seed_stock_accounting_tonnes` DECIMAL(10, 3) NOT NULL DEFAULT 0.000 COMMENT 'Залишок насіння заліковий (бух. облік), т',
    `oil_stock_tonnes` DECIMAL(10, 3) NOT NULL DEFAULT 0.000 COMMENT 'Олія в резервуарному парку, т',
    `meal_stock_tonnes` DECIMAL(10, 3) NOT NULL DEFAULT 0.000 COMMENT 'Шрот на складі готової продукції, т',
    `husk_stock_tonnes` DECIMAL(10, 3) NOT NULL DEFAULT 0.000 COMMENT 'Лузга на складі СГП, т',
    `husk_boiler_spent_tonnes` DECIMAL(10, 3) NOT NULL DEFAULT 0.000 COMMENT 'Лузга спалена в котельні за добу, т',
    `days_seed_reserve` DECIMAL(5, 1) NOT NULL DEFAULT 12.0 COMMENT 'Кількість днів запасу роботи заводу (при 600 т/добу)',
    `oil_shipped_tonnes` DECIMAL(10, 3) NOT NULL DEFAULT 0.000 COMMENT 'Відвантажено олії за добу, т',
    `meal_shipped_tonnes` DECIMAL(10, 3) NOT NULL DEFAULT 0.000 COMMENT 'Відвантажено шроту за добу, т',
    `husk_shipped_tonnes` DECIMAL(10, 3) NOT NULL DEFAULT 0.000 COMMENT 'Відвантажено лузги за добу, т',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_stock_date` (`recorded_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Показники якості лабораторії (Лист погодження / Приймання)
CREATE TABLE IF NOT EXISTS `lab_quality_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `recorded_at` DATETIME NOT NULL,
    `oil_content_pct` DECIMAL(5, 2) NOT NULL DEFAULT 49.50 COMMENT 'Фактична олійність на суху речовину, %',
    `oil_content_base_pct` DECIMAL(5, 2) NOT NULL DEFAULT 48.00 COMMENT 'Базова договірна олійність, %',
    `oil_deviation_pct` DECIMAL(5, 2) GENERATED ALWAYS AS (`oil_content_pct` - `oil_content_base_pct`) STORED COMMENT 'Відхилення олійності, %',
    `moisture_pct` DECIMAL(5, 2) NOT NULL DEFAULT 7.20 COMMENT 'Вологість факт, % (база 8.0%)',
    `impurity_pct` DECIMAL(5, 2) NOT NULL DEFAULT 3.20 COMMENT 'Смітна домішка (сор) факт, % (база 3.0%)',
    `weight_discount_tonnes` DECIMAL(8, 3) NOT NULL DEFAULT 0.000 COMMENT 'Натуральна скидка ваги на смітті та волозі, т',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_lab_date` (`recorded_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Контрактація та виконання специфікацій на довіз
CREATE TABLE IF NOT EXISTS `contracts_summary` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `recorded_at` DATETIME NOT NULL,
    `contracted_tonnes` DECIMAL(10, 3) NOT NULL DEFAULT 15000.000 COMMENT 'Законтрактовано за договорами, т',
    `delivered_tonnes` DECIMAL(10, 3) NOT NULL DEFAULT 13328.694 COMMENT 'Завезено фактично, т',
    `remaining_tonnes` DECIMAL(10, 3) NOT NULL DEFAULT 1671.306 COMMENT 'Залишок довезти (на довозі), т',
    `active_specs_count` INT UNSIGNED NOT NULL DEFAULT 14 COMMENT 'Специфікацій у стадії виконання',
    `pending_specs_count` INT UNSIGNED NOT NULL DEFAULT 3 COMMENT 'Специфікацій, де ще не приїхало жодного авто',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
