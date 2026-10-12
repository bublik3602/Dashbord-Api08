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

-- 2. Матеріальний баланс (реальні дані за Жовтень 2026, 01.10 — 11.10)
INSERT INTO `material_balance_logs` (`recorded_at`, `report_date`, `shift_type`, `seed_in_plant`, `seed_in_production`, `oil_out`, `meal_out`, `husk_out`) VALUES
('2026-10-01 20:00:00', '2026-10-01', 'day', 549.500, 533.000, 234.500, 218.500, 73.500),
('2026-10-02 20:00:00', '2026-10-02', 'day', 597.000, 579.100, 254.800, 237.400, 79.900),
('2026-10-03 20:00:00', '2026-10-03', 'day', 720.700, 699.100, 307.600, 286.600, 96.500),
('2026-10-04 20:00:00', '2026-10-04', 'day', 441.000, 427.800, 188.200, 175.400, 59.000),
('2026-10-05 20:00:00', '2026-10-05', 'day', 661.800, 641.900, 282.400, 263.200, 88.600),
('2026-10-06 20:00:00', '2026-10-06', 'day', 589.800, 572.100, 251.700, 234.600, 78.900),
('2026-10-07 20:00:00', '2026-10-07', 'day', 501.900, 486.800, 214.200, 199.600, 67.200),
('2026-10-08 20:00:00', '2026-10-08', 'day', 609.300, 591.000, 260.000, 242.300, 81.600),
('2026-10-09 20:00:00', '2026-10-09', 'day', 439.300, 426.100, 187.500, 174.700, 58.800),
('2026-10-10 20:00:00', '2026-10-10', 'day', 499.900, 484.900, 213.400, 198.800, 66.900),
('2026-10-11 20:00:00', '2026-10-11', 'day', 465.800, 452.000, 198.800, 185.300, 62.400),
('2026-10-12 20:00:00', '2026-10-12', 'day', 0.000, 0.000, 0.000, 0.000, 0.000);

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
INSERT INTO `warehouse_stocks` (`recorded_at`, `report_date`, `seed_stock_tonnes`, `seed_stock_accounting_tonnes`, `oil_stock_tonnes`, `meal_stock_tonnes`, `husk_stock_tonnes`, `husk_boiler_spent_tonnes`, `days_seed_reserve`, `oil_shipped_tonnes`, `meal_shipped_tonnes`, `husk_shipped_tonnes`) VALUES
(NOW(), '12.10.2026 08:00', 7284.327, 7268.882, 3091.323, 757.074, 116.844, 37.618, 12.1, 130.000, 189.960, 48.760);

-- 7. Показники якості лабораторії (реальні середньозважені з Листа погодження)
INSERT INTO `lab_quality_logs` (`recorded_at`, `report_date`, `oil_content_pct`, `oil_content_base_pct`, `moisture_pct`, `impurity_pct`, `weight_discount_tonnes`) VALUES
(NOW(), '12.10.2026 09:34', 49.62, 48.00, 7.15, 3.20, 12.450);

-- 8. Контрактація та виконання специфікацій (реальні дані з Реєстру)
INSERT INTO `contracts_summary` (`recorded_at`, `report_date`, `contracted_tonnes`, `delivered_tonnes`, `remaining_tonnes`, `active_specs_count`, `pending_specs_count`) VALUES
(NOW(), '12.10.2026 09:34', 15146.000, 13328.694, 1817.306, 14, 3);
