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
    `stay_duration_minutes` INT GENERATED ALWAYS AS (
        CASE WHEN `exit_time` IS NOT NULL AND `entry_time` IS NOT NULL 
             THEN TIMESTAMPDIFF(MINUTE, `entry_time`, `exit_time`)
             WHEN `entry_time` IS NOT NULL
             THEN TIMESTAMPDIFF(MINUTE, `entry_time`, NOW())
             ELSE 0 END
    ) VIRTUAL COMMENT 'Время на заводе (минуты)',
    
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

SET FOREIGN_KEY_CHECKS = 1;
