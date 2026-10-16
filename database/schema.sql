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
    `report_date` VARCHAR(50) DEFAULT NULL COMMENT 'Дата звіту або доби (наприклад 11.10.2026)',
    `shift_type` ENUM('day', 'night') NOT NULL DEFAULT 'day' COMMENT 'Смена: дневная (8-20) или ночная (20-8)',
    
    -- Входящие потоки сырья (тонн)
    `seed_in_plant` DECIMAL(10, 3) NOT NULL DEFAULT 0.000 COMMENT 'Вход: Семечка на завод (по весовой)',
    `seed_in_production` DECIMAL(10, 3) NOT NULL DEFAULT 0.000 COMMENT 'Вход: Семечка в производство (после очистки/сушилки)',
    
    -- Выходящие потоки готовой продукции (тонн)
    `oil_out` DECIMAL(10, 3) NOT NULL DEFAULT 0.000 COMMENT 'Выход: Масло подсолнечное',
    `meal_out` DECIMAL(10, 3) NOT NULL DEFAULT 0.000 COMMENT 'Выход: Шрот',
    `husk_out` DECIMAL(10, 3) NOT NULL DEFAULT 0.000 COMMENT 'Выход: Лузга',
    
    -- Расчетные потери (тонн и проценты)
    `conditioning_loss` DECIMAL(10, 3) NOT NULL DEFAULT 0.000 COMMENT 'Потери при подработке с элеватора (усушка/сор)',
    `conditioning_loss_pct` DECIMAL(6, 2) NOT NULL DEFAULT 0.00 COMMENT 'Потери подработки, %',
    `production_loss` DECIMAL(10, 3) NOT NULL DEFAULT 0.000 COMMENT 'Производственные потери переработки',
    `production_loss_pct` DECIMAL(6, 2) NOT NULL DEFAULT 0.00 COMMENT 'Потери переработки, %',
    
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_recorded_at` (`recorded_at`),
    INDEX `idx_shift` (`recorded_at`, `shift_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Таблица логов энергопотребления и расхода воды
CREATE TABLE IF NOT EXISTS `energy_readings` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `recorded_at` DATETIME NOT NULL,
    `report_date` VARCHAR(50) DEFAULT NULL COMMENT 'Дата або період заміру (напр. 06.10.2026 або Жовтень 2026)',
    `power_kw` DECIMAL(14, 4) NOT NULL DEFAULT 0.0000 COMMENT 'Поточна/середня потужність, кВт',
    `power_kwh_delta` DECIMAL(14, 4) NOT NULL DEFAULT 0.0000 COMMENT 'Споживання активної ел.енергії за період, кВт*год',
    `reactive_kvarh` DECIMAL(12, 2) NOT NULL DEFAULT 0.00 COMMENT 'Реактивна ел.енергія за період, кВАр*год',
    `water_flow_m3_hour` DECIMAL(10, 2) NOT NULL DEFAULT 0.00 COMMENT 'Витрата води, м3/год',
    `water_m3_delta` DECIMAL(10, 2) NOT NULL DEFAULT 0.00 COMMENT 'Витрата води за період (вхідний лічильник), м3',
    `sewage_m3` DECIMAL(10, 2) NOT NULL DEFAULT 0.00 COMMENT 'Каналізація / стоки за період, м3',
    `gas_m3` DECIMAL(10, 2) NOT NULL DEFAULT 0.00 COMMENT 'Природний газ за період, м3',
    `steam_pressure_bar` DECIMAL(5, 2) DEFAULT 0.00 COMMENT 'Тиск пари з котельні, бар',
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
    `report_date` VARCHAR(50) DEFAULT NULL COMMENT 'Дата та час фіксації СВОД (наприклад 12.10.2026 08:00)',
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
    `report_date` VARCHAR(50) DEFAULT NULL COMMENT 'Дата та час листа погодження лабораторії 1С',
    `oil_content_pct` DECIMAL(5, 2) NOT NULL DEFAULT 49.50 COMMENT 'Фактична олійність на суху речовину, %',
    `oil_content_base_pct` DECIMAL(5, 2) NOT NULL DEFAULT 48.00 COMMENT 'Базова договірна олійність, %',
    `oil_deviation_pct` DECIMAL(5, 2) NOT NULL DEFAULT 1.50 COMMENT 'Відхилення олійності, %',
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
    `report_date` VARCHAR(50) DEFAULT NULL COMMENT 'Дата та час реєстру договорів 1С',
    `contracted_tonnes` DECIMAL(10, 3) NOT NULL DEFAULT 15000.000 COMMENT 'Законтрактовано за договорами, т',
    `delivered_tonnes` DECIMAL(10, 3) NOT NULL DEFAULT 13328.694 COMMENT 'Завезено фактично, т',
    `remaining_tonnes` DECIMAL(10, 3) NOT NULL DEFAULT 1671.306 COMMENT 'Залишок довезти (на довозі), т',
    `active_specs_count` INT UNSIGNED NOT NULL DEFAULT 14 COMMENT 'Специфікацій у стадії виконання',
    `pending_specs_count` INT UNSIGNED NOT NULL DEFAULT 3 COMMENT 'Специфікацій, де ще не приїхало жодного авто',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Користувачі та ролі дашборду (RBAC)
CREATE TABLE IF NOT EXISTS `dashboard_users` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE COMMENT 'Логін користувача',
    `password_hash` VARCHAR(255) NOT NULL COMMENT 'Bcrypt хеш пароля',
    `display_name` VARCHAR(100) NOT NULL DEFAULT '',
    `role` VARCHAR(50) NOT NULL DEFAULT 'semena' COMMENT 'Роль: admin, semena, viewer тощо',
    `allowed_pages` VARCHAR(255) NOT NULL DEFAULT 'semena' COMMENT 'Дозволені розділи (all або semena)',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `last_login_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
