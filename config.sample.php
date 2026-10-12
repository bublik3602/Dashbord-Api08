<?php
/**
 * Конфигурация системы "Дашборд завод"
 * 
 * ВНИМАНИЕ: Не сохраняйте этот файл с реальными паролями в Git!
 * Скопируйте этот файл в config.php и настройте параметры.
 */

return [
    // Режим работы: 'production' или 'demo'
    // В режиме 'demo' при отсутствии связи с Zabbix/CRM используются данные из локальной БД/генератора
    'app_mode' => 'demo',
    'app_name' => 'Олійноекстракційний Завод — Єдиний Моніторинг',
    'timezone' => 'Europe/Kyiv',

    // Локальная база данных дашборда (Hestia CP / MariaDB / MySQL)
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'database' => 'dashboard_zavod',
        'username' => 'dash_user',
        'password' => 'secret_password_here',
        'charset'  => 'utf8mb4',
    ],

    // Подключение к базе данных CRM (Логистика и электронная очередь транспорта)
    // ВНИМАНИЕ: База данных используется СТРОГО В РЕЖИМЕ ТОЛЬКО ЧТЕНИЕ (READ-ONLY)
    'crm_db' => [
        'enabled'  => true, // true = прямое чтение очередей транспорта
        'driver'   => 'mysql',
        'host'     => 'localhost',
        'port'     => 3306,
        'database' => 'apgk_api08',
        'username' => 'apgk_api08',
        'password' => 'orrz20054Q+',
        'charset'  => 'utf8mb4',
        'readonly' => true, // КАТЕГОРИЧЕСКИ ТОЛЬКО ЧТЕНИЕ
    ],

    // Интеграция с Zabbix API (Датчики, весы, расходомеры, энергетика)
    'zabbix' => [
        'enabled'  => false, // true = опрос реального Zabbix
        'url'      => 'http://192.168.1.100/zabbix/api_jsonrpc.php',
        'token'    => 'your_zabbix_api_token_here', // Zabbix 5.4+ API Token или логин/пароль ниже
        'user'     => 'Admin',
        'password' => 'zabbix_password',
        
        // Маппинг ключей или itemid в Zabbix для сбора показателей
        'items' => [
            // Весы сырья на входе (Семечка на завод, тонн)
            'seed_in_plant_tonnes'     => 'scale.seed.in.plant.total',
            // Весы очищенного сырья в переработку (Семечка в производство, тонн)
            'seed_in_production_tonnes'=> 'scale.seed.in.prod.total',
            
            // Выход готовой продукции (тонн)
            'oil_out_tonnes'           => 'production.oil.yield.tonnes',
            'meal_out_tonnes'          => 'production.meal.yield.tonnes',  // Шрот
            'husk_out_tonnes'          => 'production.husk.yield.tonnes',  // Лузга
            
            // Энергоресурсы
            'power_kw_current'         => 'power.meter.main.kw',
            'power_kwh_accumulated'    => 'power.meter.main.kwh.day',
            'water_flow_m3_hour'       => 'water.meter.feed.flow.rate',
            'water_m3_accumulated'     => 'water.meter.feed.total.day',
            
            // Состояние ключевого оборудования (0 = остановлен/авария, 1 = в работе)
            'status_cleaner'           => 'equipment.cleaner.status',
            'status_dryer'             => 'equipment.dryer.status',
            'status_dehuller'          => 'equipment.dehuller.status',     // Рушально-веечный
            'status_press'             => 'equipment.press_line.status',   // Прессовый цех
            'status_extraction'        => 'equipment.extraction.status',   // Экстракция
            'status_boiler'            => 'equipment.boiler.status',       // Котельная
        ],
    ],

    // Нормативы и пороговые значения для цветовой индикации
    'thresholds' => [
        // Допустимые нормы потерь при подработке (усушка/сор), %
        'conditioning_loss_warning_pct' => 2.5, // > 2.5% — желтый (предупреждение)
        'conditioning_loss_danger_pct'  => 3.5, // > 3.5% — красный (превышение нормы)

        // Допустимые нормы производственных потерь при переработке, %
        'production_loss_warning_pct'   => 1.2, // > 1.2% — желтый
        'production_loss_danger_pct'    => 1.8, // > 1.8% — красный

        // Норматив нахождения грузовика на заводе (минуты)
        'truck_stay_target_minutes'     => 120, // 2 часа норма
        'truck_stay_warning_minutes'    => 180, // > 3 часов — задержка
        'truck_stay_danger_minutes'     => 240, // > 4 часов — критично

        // Норматив удельного расхода э/э на тонну семечки (кВт*ч / т)
        'power_per_tonne_target_kwh'    => 65.0,
        'power_per_tonne_danger_kwh'    => 80.0,
    ],

    // Периоды обновления и интервалы
    'refresh_interval_seconds' => 30,
];
