-- ===================================================================
-- Демо-данные для первоначального запуска и тестирования дашборда
-- ===================================================================

SET NAMES utf8mb4;

-- Очистка старых данных перед наполнением
TRUNCATE TABLE `equipment_nodes`;
TRUNCATE TABLE `material_balance_logs`;
TRUNCATE TABLE `energy_readings`;
TRUNCATE TABLE `transport_queue`;
TRUNCATE TABLE `sync_logs`;
TRUNCATE TABLE `warehouse_stocks`;
TRUNCATE TABLE `lab_quality_logs`;
TRUNCATE TABLE `contracts_summary`;

-- 1. Оборудование и узлы завода
INSERT INTO `equipment_nodes` (`code`, `name`, `section`, `status`, `load_pct`, `current_temperature_c`, `last_update`, `details`) VALUES
('cleaner_01', 'Сепаратор БИС-100 (Очистка семян)', 'Приемно-очистительный участок', 'running', 94.5, 24.2, NOW(), 'Сорность на выходе 1.4% (Норма)'),
('dryer_01', 'Сушильный комплекс зерновой КЗС-50', 'Сушильный участок', 'running', 91.0, 78.5, NOW(), 'Влажность семян 7.2% (Норма)'),
('dehuller_01', 'Рушально-веечный агрегат РВО-2', 'Подготовительный цех', 'running', 98.2, 36.8, NOW(), 'Масличность лузги 0.8% (Штатно)'),
('press_line_01', 'Форпрессы МП-68 (Линия 1 и 2)', 'Прессовый цех', 'running', 96.0, 102.4, NOW(), 'Отжим масла в норме, температура зеера 102°C'),
('extraction_01', 'Экстрактор карусельный НД-1250', 'Цех экстракции', 'running', 89.5, 58.0, NOW(), 'Расход растворителя 1.1 кг/т, остаточная масличность шрота 0.75%'),
('boiler_01', 'Паровой котел на лузге ДКВР-10', 'Энергокомплекс / Котельная', 'running', 92.0, 195.0, NOW(), 'Давление пара 11.2 бар (Сжигание лузги 100%)');

-- 2. Материальный баланс (за последние 7 дней и текущие смены)
-- День 1-6 (история)
INSERT INTO `material_balance_logs` (`recorded_at`, `shift_type`, `seed_in_plant`, `seed_in_production`, `oil_out`, `meal_out`, `husk_out`) VALUES
(DATE_SUB(NOW(), INTERVAL 6 DAY), 'day',   720.500, 703.200, 305.800, 270.700, 118.800),
(DATE_SUB(NOW(), INTERVAL 6 DAY), 'night', 680.000, 663.800, 288.700, 255.500, 112.200),

(DATE_SUB(NOW(), INTERVAL 5 DAY), 'day',   750.000, 731.250, 318.000, 281.500, 123.500),
(DATE_SUB(NOW(), INTERVAL 5 DAY), 'night', 710.000, 693.000, 301.400, 266.800, 117.100),

(DATE_SUB(NOW(), INTERVAL 4 DAY), 'day',   740.200, 721.700, 313.900, 277.800, 122.000),
(DATE_SUB(NOW(), INTERVAL 4 DAY), 'night', 695.000, 678.300, 295.000, 261.100, 114.600),

(DATE_SUB(NOW(), INTERVAL 3 DAY), 'day',   760.000, 739.500, 321.600, 284.700, 125.000),
(DATE_SUB(NOW(), INTERVAL 3 DAY), 'night', 705.500, 687.800, 299.100, 264.800, 116.200),

-- День 2 назад: небольшое превышение потерь на сушке (усушка из-за повышенной влажности семян 11%)
(DATE_SUB(NOW(), INTERVAL 2 DAY), 'day',   730.000, 705.100, 306.700, 271.400, 119.200),
(DATE_SUB(NOW(), INTERVAL 2 DAY), 'night', 690.000, 672.700, 292.600, 259.000, 113.600),

-- Вчера
(DATE_SUB(NOW(), INTERVAL 1 DAY), 'day',   755.000, 736.800, 320.500, 283.600, 124.500),
(DATE_SUB(NOW(), INTERVAL 1 DAY), 'night', 715.000, 698.500, 303.800, 268.900, 118.000),

-- Сегодняшняя текущая смена (накопленный факт к текущему часу)
(NOW(), 'day', 485.600, 473.400, 205.900, 182.200, 80.000);

-- 3. Энергетика и вода (почасовые срезы за последние 24 часа)
INSERT INTO `energy_readings` (`recorded_at`, `power_kw`, `power_kwh_delta`, `water_flow_m3_hour`, `water_m3_delta`, `steam_pressure_bar`) VALUES
(DATE_SUB(NOW(), INTERVAL 23 HOUR), 3420.5, 3420.5, 48.2, 48.2, 11.1),
(DATE_SUB(NOW(), INTERVAL 22 HOUR), 3450.0, 3450.0, 49.0, 49.0, 11.2),
(DATE_SUB(NOW(), INTERVAL 21 HOUR), 3510.8, 3510.8, 51.5, 51.5, 11.0),
(DATE_SUB(NOW(), INTERVAL 20 HOUR), 3580.0, 3580.0, 52.0, 52.0, 11.3),
(DATE_SUB(NOW(), INTERVAL 19 HOUR), 3620.4, 3620.4, 53.4, 53.4, 11.2),
(DATE_SUB(NOW(), INTERVAL 18 HOUR), 3590.0, 3590.0, 52.8, 52.8, 11.1),
(DATE_SUB(NOW(), INTERVAL 17 HOUR), 3480.2, 3480.2, 49.5, 49.5, 11.0),
(DATE_SUB(NOW(), INTERVAL 16 HOUR), 3410.0, 3410.0, 47.9, 47.9, 11.2),
(DATE_SUB(NOW(), INTERVAL 15 HOUR), 3390.5, 3390.5, 47.0, 47.0, 11.3),
(DATE_SUB(NOW(), INTERVAL 14 HOUR), 3440.0, 3440.0, 48.6, 48.6, 11.1),
(DATE_SUB(NOW(), INTERVAL 13 HOUR), 3500.0, 3500.0, 50.1, 50.1, 11.2),
(DATE_SUB(NOW(), INTERVAL 12 HOUR), 3650.0, 3650.0, 54.0, 54.0, 11.4),
(DATE_SUB(NOW(), INTERVAL 11 HOUR), 3780.2, 3780.2, 56.3, 56.3, 11.3),
(DATE_SUB(NOW(), INTERVAL 10 HOUR), 3820.0, 3820.0, 57.1, 57.1, 11.2),
(DATE_SUB(NOW(), INTERVAL 9 HOUR),  3890.5, 3890.5, 58.4, 58.4, 11.3),
(DATE_SUB(NOW(), INTERVAL 8 HOUR),  3920.0, 3920.0, 59.0, 59.0, 11.1),
(DATE_SUB(NOW(), INTERVAL 7 HOUR),  3870.0, 3870.0, 57.5, 57.5, 11.0),
(DATE_SUB(NOW(), INTERVAL 6 HOUR),  3840.8, 3840.8, 56.9, 56.9, 11.2),
(DATE_SUB(NOW(), INTERVAL 5 HOUR),  3790.0, 3790.0, 55.0, 55.0, 11.4),
(DATE_SUB(NOW(), INTERVAL 4 HOUR),  3810.0, 3810.0, 55.8, 55.8, 11.3),
(DATE_SUB(NOW(), INTERVAL 3 HOUR),  3850.5, 3850.5, 56.2, 56.2, 11.2),
(DATE_SUB(NOW(), INTERVAL 2 HOUR),  3880.0, 3880.0, 57.0, 57.0, 11.1),
(DATE_SUB(NOW(), INTERVAL 1 HOUR),  3915.2, 3915.2, 58.2, 58.2, 11.3),
(NOW(),                             3940.0, 3940.0, 58.9, 58.9, 11.2);

-- 4. Логистика: Очередь грузовиков и воронка движения
-- Записано (в очереди на подъезде к заводу)
INSERT INTO `transport_queue` (`truck_number`, `driver_name`, `cargo_type`, `status`, `recorded_at`, `entry_time`, `weighing_time`, `exit_time`, `weight_netto_tonnes`) VALUES
('А742ВХ 161', 'Иванов С. М.',    'Семечка подсолнечника', 'queued', DATE_SUB(NOW(), INTERVAL 45 MINUTE), NULL, NULL, NULL, 0.000),
('В819СВ 134', 'Петров Д. К.',    'Семечка подсолнечника', 'queued', DATE_SUB(NOW(), INTERVAL 30 MINUTE), NULL, NULL, NULL, 0.000),
('О512МК 64',  'Смирнов А. В.',   'Семечка подсолнечника', 'queued', DATE_SUB(NOW(), INTERVAL 25 MINUTE), NULL, NULL, NULL, 0.000),
('Е931ТТ 161', 'Кузнецов В. И.',  'Семечка подсолнечника', 'queued', DATE_SUB(NOW(), INTERVAL 15 MINUTE), NULL, NULL, NULL, 0.000),
('М204РО 26',  'Попов Е. Г.',     'Масло налив',          'queued', DATE_SUB(NOW(), INTERVAL 10 MINUTE), NULL, NULL, NULL, 0.000),
('У777АА 126', 'Федоров Н. С.',   'Шрот насыпью',         'queued', DATE_SUB(NOW(), INTERVAL 5 MINUTE),  NULL, NULL, NULL, 0.000);

-- Заехало (на территории завода: досмотр / отбор проб лаборатории / ожидание разгрузки)
INSERT INTO `transport_queue` (`truck_number`, `driver_name`, `cargo_type`, `status`, `recorded_at`, `entry_time`, `weighing_time`, `exit_time`, `weight_netto_tonnes`) VALUES
('К312ЕХ 61',  'Васильев П. О.',  'Семечка подсолнечника', 'entered', DATE_SUB(NOW(), INTERVAL 75 MINUTE), DATE_SUB(NOW(), INTERVAL 40 MINUTE), NULL, NULL, 0.000),
('Р405АВ 36',  'Николаев Р. С.',  'Семечка подсолнечника', 'entered', DATE_SUB(NOW(), INTERVAL 65 MINUTE), DATE_SUB(NOW(), INTERVAL 35 MINUTE), NULL, NULL, 0.000),
('Т621ОО 161', 'Соколов В. П.',   'Масло налив',          'entered', DATE_SUB(NOW(), INTERVAL 50 MINUTE), DATE_SUB(NOW(), INTERVAL 25 MINUTE), NULL, NULL, 0.000),
('С834ХУ 23',  'Михайлов А. А.',  'Шрот насыпью',         'entered', DATE_SUB(NOW(), INTERVAL 40 MINUTE), DATE_SUB(NOW(), INTERVAL 18 MINUTE), NULL, NULL, 0.000);

-- Взвешивается (на автомобильных весах - брутто или тара)
INSERT INTO `transport_queue` (`truck_number`, `driver_name`, `cargo_type`, `status`, `recorded_at`, `entry_time`, `weighing_time`, `exit_time`, `weight_netto_tonnes`) VALUES
('Н543КВ 161', 'Морозов К. И.',   'Семечка подсолнечника', 'weighing', DATE_SUB(NOW(), INTERVAL 95 MINUTE), DATE_SUB(NOW(), INTERVAL 55 MINUTE), DATE_SUB(NOW(), INTERVAL 6 MINUTE), NULL, 28.450),
('В119ТР 68',  'Новиков Г. А.',   'Семечка подсолнечника', 'weighing', DATE_SUB(NOW(), INTERVAL 85 MINUTE), DATE_SUB(NOW(), INTERVAL 48 MINUTE), DATE_SUB(NOW(), INTERVAL 3 MINUTE), NULL, 31.200);

-- Выехало (завершенные рейсы за сегодня с фиксацией времени нахождения)
INSERT INTO `transport_queue` (`truck_number`, `driver_name`, `cargo_type`, `status`, `recorded_at`, `entry_time`, `weighing_time`, `exit_time`, `weight_netto_tonnes`, `stay_duration_minutes`) VALUES
('А102ВК 61', 'Павлов С. В.',     'Семечка подсолнечника', 'exited', DATE_SUB(NOW(), INTERVAL 300 MINUTE), DATE_SUB(NOW(), INTERVAL 240 MINUTE), DATE_SUB(NOW(), INTERVAL 180 MINUTE), DATE_SUB(NOW(), INTERVAL 150 MINUTE), 29.800, 90),
('Р218СХ 161', 'Козлов М. Д.',    'Семечка подсолнечника', 'exited', DATE_SUB(NOW(), INTERVAL 270 MINUTE), DATE_SUB(NOW(), INTERVAL 220 MINUTE), DATE_SUB(NOW(), INTERVAL 160 MINUTE), DATE_SUB(NOW(), INTERVAL 120 MINUTE), 32.150, 100),
('О432ТМ 31', 'Лебедев Д. Н.',    'Семечка подсолнечника', 'exited', DATE_SUB(NOW(), INTERVAL 240 MINUTE), DATE_SUB(NOW(), INTERVAL 195 MINUTE), DATE_SUB(NOW(), INTERVAL 130 MINUTE), DATE_SUB(NOW(), INTERVAL 105 MINUTE), 27.900, 90),
('Е501ХР 161', 'Семенов Б. И.',   'Масло налив',          'exited', DATE_SUB(NOW(), INTERVAL 210 MINUTE), DATE_SUB(NOW(), INTERVAL 165 MINUTE), DATE_SUB(NOW(), INTERVAL 110 MINUTE), DATE_SUB(NOW(), INTERVAL 75 MINUTE), 24.500, 90),
('К612АС 26', 'Егоров А. М.',     'Шрот насыпью',         'exited', DATE_SUB(NOW(), INTERVAL 180 MINUTE), DATE_SUB(NOW(), INTERVAL 140 MINUTE), DATE_SUB(NOW(), INTERVAL 80 MINUTE), DATE_SUB(NOW(), INTERVAL 50 MINUTE), 26.300, 90),
('М723ММ 161', 'Волков П. Е.',    'Семечка подсолнечника', 'exited', DATE_SUB(NOW(), INTERVAL 150 MINUTE), DATE_SUB(NOW(), INTERVAL 110 MINUTE), DATE_SUB(NOW(), INTERVAL 55 MINUTE), DATE_SUB(NOW(), INTERVAL 20 MINUTE), 30.700, 90);

-- 5. Журнал синхронизации
INSERT INTO `sync_logs` (`source`, `status`, `records_processed`, `message`) VALUES
('zabbix', 'success', 12, 'Синхронизация показателей датчиков и весов выполнена успешно'),
('crm',    'success', 18, 'Синхронизация статусов электронной очереди выполнена успешно');

-- 6. Складські залишки готової продукції та насіння (реальні дані Агропроінвест 08)
INSERT INTO `warehouse_stocks` (`recorded_at`, `seed_stock_tonnes`, `seed_stock_accounting_tonnes`, `oil_stock_tonnes`, `meal_stock_tonnes`, `husk_stock_tonnes`, `husk_boiler_spent_tonnes`, `days_seed_reserve`, `oil_shipped_tonnes`, `meal_shipped_tonnes`, `husk_shipped_tonnes`) VALUES
(NOW(), 7284.327, 7268.882, 3091.323, 757.074, 116.844, 37.618, 12.1, 130.000, 189.960, 48.760);

-- 7. Показники якості лабораторії (реальні середньозважені з Листа погодження)
INSERT INTO `lab_quality_logs` (`recorded_at`, `oil_content_pct`, `oil_content_base_pct`, `moisture_pct`, `impurity_pct`, `weight_discount_tonnes`) VALUES
(NOW(), 49.62, 48.00, 7.15, 3.20, 12.450);

-- 8. Контрактація та виконання специфікацій (реальні дані з Реєстру)
INSERT INTO `contracts_summary` (`recorded_at`, `contracted_tonnes`, `delivered_tonnes`, `remaining_tonnes`, `active_specs_count`, `pending_specs_count`) VALUES
(NOW(), 15146.000, 13328.694, 1817.306, 14, 3);
