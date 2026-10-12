<?php
/**
 * Главный экран веб-дашборда руководителя завода
 * Разрабатывался под окружение Hestia CP (PHP 8+, Nginx/Apache, MySQL/MariaDB)
 */

if (!file_exists(__DIR__ . '/config.php') && file_exists(__DIR__ . '/install.php')) {
    header("Location: install.php");
    exit;
}

require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/metrics_calculator.php';

try {
    $calculator = new MetricsCalculator();
    $initialPeriod = 'day';
    $dashboardData = $calculator->getDashboardData($initialPeriod);
} catch (Throwable $e) {
    if (file_exists(__DIR__ . '/install.php')) {
        header("Location: install.php");
        exit;
    }
    die("<div style='font-family:sans-serif;padding:30px;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:8px;max-width:600px;margin:40px auto;'>"
        . "<h2 style='margin-bottom:10px;'>⚠️ Ошибка базы данных</h2>"
        . "<p>" . htmlspecialchars($e->getMessage()) . "</p>"
        . "<p style='margin-top:15px;'><a href='install.php' style='display:inline-block;padding:10px 16px;background:#0284c7;color:white;text-decoration:none;border-radius:6px;font-weight:bold;'>Запустить установку (install.php)</a></p>"
        . "</div>");
}

$mb = $dashboardData['material_balance'];
$en = $dashboardData['energy'];
$eq = $dashboardData['equipment'];
$log = $dashboardData['logistics'];
$stocks = $dashboardData['warehouse_stocks'] ?? [];
$lab = $dashboardData['lab_quality'] ?? [];
$contracts = $dashboardData['contracts_summary'] ?? [];
$config = get_config();

$hour = (int)date('H');
$shiftName = ($hour >= 8 && $hour < 20) ? 'Денна зміна (08:00 — 20:00)' : 'Нічна зміна (20:00 — 08:00)';
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($config['app_name'] ?? 'Олійноекстракційний завод — Єдиний Моніторинг') ?></title>
    
    <!-- Стилі дашборда (з кеш-бастінгом для миттєвого оновлення у браузері) -->
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?= file_exists(__DIR__ . '/assets/css/dashboard.css') ? filemtime(__DIR__ . '/assets/css/dashboard.css') : time() ?>">
    
    <!-- Бібліотека графіків ApexCharts (CDN) -->
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    
    <!-- Іконки Feather/Lucide -->
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body>

<div class="dashboard-container">
    
    <!-- =============================================================
         ШАПКА: НАЗВА, ЗМІНА, ФІЛЬТР ПЕРІОДІВ, ТАЙМЕР
         ============================================================= -->
    <header class="dashboard-header">
        <div class="brand-section">
            <div class="brand-icon">
                <i data-lucide="factory"></i>
            </div>
            <div>
                <h1 class="brand-title"><?= htmlspecialchars($config['app_name'] ?? 'Олійноекстракційний завод — Єдиний Моніторинг') ?></h1>
                <div class="brand-subtitle">
                    <span class="live-pill"><span class="pulse-dot"></span> LIVE</span>
                    <span>&bull;</span>
                    <span><?= $shiftName ?></span>
                    <span>&bull;</span>
                    <span>Оновлено: <?= date('H:i:s') ?></span>
                </div>
            </div>
        </div>

        <div class="controls-section">
            <!-- Перемикач періодів -->
            <div class="period-switcher">
                <button type="button" class="period-btn" data-period="shift">Зміна</button>
                <button type="button" class="period-btn active" data-period="day">Доба</button>
                <button type="button" class="period-btn" data-period="week">Тиждень</button>
                <button type="button" class="period-btn" data-period="month">Місяць</button>
            </div>

            <!-- Дії: Імпорт та Експорт звітів -->
            <div class="action-buttons-group">
                <button type="button" class="btn-action btn-import" id="btn-open-import" title="Імпортувати щоденні звіти завода (Лист погодження, СВОД, Баланс)">
                    <i data-lucide="upload" style="width: 14px; height: 14px;"></i>
                    <span>Імпорт Excel</span>
                </button>
                <a href="api/export.php" class="btn-action btn-export" id="btn-export-excel" title="Завантажити звіт за зміну / добу в Excel">
                    <i data-lucide="download" style="width: 14px; height: 14px;"></i>
                    <span>Експорт в Excel</span>
                </a>
            </div>

            <!-- Ручне оновлення та зворотний відлік -->
            <div class="refresh-control">
                <button type="button" class="btn-refresh" id="btn-manual-refresh" title="Оновити зараз">
                    <i data-lucide="refresh-cw" class="refresh-icon" style="width: 14px; height: 14px;"></i>
                    <span>Оновити</span>
                </button>
                <span class="refresh-timer" id="refresh-timer-count" title="Наступне автооновлення через">30с</span>
            </div>
        </div>
    </header>

    <!-- =============================================================
         ЧАСОВИЙ БАНЕР: ПЕРІОД МОНІТОРИНГУ ТА ЧАС АКТУАЛІЗАЦІЇ
         ============================================================= -->
    <?php
    $bounds = get_operational_day_bounds();
    $pInfo = $dashboardData['period_info'] ?? [];
    $activeRangeText = $pInfo['range_display'] ?? ("Виробнича доба (" . $bounds['label'] . ", з 08:00 до 08:00)");
    $labDate = $lab['report_date'] ?? '12.10.2026 09:34';
    $stocksDate = $stocks['report_date'] ?? '12.10.2026 08:00';
    ?>
    <div class="active-period-banner" id="active-period-banner">
        <div class="banner-left">
            <div class="banner-icon-wrap">
                <i data-lucide="calendar-clock"></i>
            </div>
            <div>
                <div class="banner-main-text">
                    <span>Поточний період моніторингу:</span>
                    <strong id="banner-period-title"><?= htmlspecialchars($activeRangeText) ?></strong>
                </div>
                <div class="banner-sub-text">
                    <span id="banner-period-sub">Розрахунок доби: <strong>з 08:00 до 08:00</strong> наступного дня &bull; Поточна: <strong><?= $shiftName ?></strong></span>
                    <span class="banner-separator">&bull;</span>
                    <span>Лабораторія 1С: <strong id="banner-lab-time"><?= htmlspecialchars($labDate) ?></strong></span>
                    <span class="banner-separator">&bull;</span>
                    <span>СВОД залишків: <strong id="banner-stocks-time"><?= htmlspecialchars($stocksDate) ?></strong></span>
                </div>
            </div>
        </div>
        <div class="banner-right">
            <div class="banner-live-status">
                <span class="pulse-dot"></span>
                <span id="banner-status-text">Дані актуальні</span>
            </div>
            <div class="banner-sync-time" id="banner-sync-time">
                Оновлено: <?= date('d.m.Y H:i:s') ?>
            </div>
        </div>
    </div>

    <!-- =============================================================
         БЛОК А: ПРОИЗВОДСТВО И МАТЕРИАЛЬНЫЙ БАЛАНС (ВЕРХНИЙ РЯД KPI)
         ============================================================= -->
    <div class="section-title">
        <i data-lucide="layers" style="width: 18px; height: 18px; color: var(--color-primary);"></i>
        <span>Блок А: Виробництво, запаси та матеріальний баланс</span>
        <span class="section-badge badge-blue" id="lbl-prod-period">Період: <?= $bounds['label'] ?></span>
    </div>

    <div class="kpi-grid">
        <!-- 1. Зона приймання: Насіння на завод -->
        <div class="kpi-card accent-blue">
            <div class="kpi-zone-tag">Зона приймання</div>
            <div class="kpi-title">Насіння на завод (вхід)</div>
            <div class="kpi-value-row">
                <div class="kpi-main-number" id="val-seed-in-plant">
                    <?= number_format($mb['receiving_zone']['seed_in_plant'], 1, ',', ' ') ?><span class="kpi-unit">т</span>
                </div>
                <span class="kpi-badge badge-blue">Автоваги</span>
            </div>
            <div class="kpi-subtext">
                <span id="val-seed-plan">План: <?= number_format($mb['receiving_zone']['seed_plan'], 1, ',', ' ') ?> т (<?= $mb['receiving_zone']['plan_fulfillment_pct'] ?>%)</span>
                <span>Факт приймання</span>
            </div>
        </div>

        <!-- 2. Склад сировини: Запас насіння та автономність заводу -->
        <div class="kpi-card accent-emerald">
            <div class="kpi-zone-tag">Склад сировини</div>
            <div class="kpi-title">Запас насіння на елеваторі</div>
            <div class="kpi-value-row">
                <div class="kpi-main-number" id="val-seed-stock">
                    <?= number_format($stocks['seed_stock_tonnes'] ?? 7284.3, 1, ',', ' ') ?><span class="kpi-unit">т</span>
                </div>
                <span class="kpi-badge badge-green" id="badge-days-reserve">
                    <?= ($stocks['days_seed_reserve'] ?? 12.1) ?> дн. запасу
                </span>
            </div>
            <div class="kpi-subtext">
                <span id="val-seed-stock-sub">Облік: <?= number_format($stocks['seed_stock_accounting_tonnes'] ?? 7268.9, 1, ',', ' ') ?> т &bull; Потреба: 600 т/д</span>
                <span>Автономність МЕЗ</span>
            </div>
        </div>

        <!-- 3. Зона виробництва: Подача в переробку -->
        <div class="kpi-card accent-amber">
            <div class="kpi-zone-tag">Зона виробництва</div>
            <div class="kpi-title">Насіння у переробку (очищене)</div>
            <div class="kpi-value-row">
                <div class="kpi-main-number" id="val-seed-in-prod">
                    <?= number_format($mb['production_zone']['seed_processed'], 1, ',', ' ') ?><span class="kpi-unit">т</span>
                </div>
                <span class="kpi-badge <?= $mb['receiving_zone']['status_class'] ?>" id="badge-cond-loss">
                    Підробка: <?= $mb['receiving_zone']['conditioning_loss_pct'] ?>%
                </span>
            </div>
            <div class="kpi-subtext">
                <span>Усушка/сміття: <?= number_format($mb['receiving_zone']['conditioning_loss'], 1, ',', ' ') ?> т</span>
                <span>Подача в РВЦ</span>
            </div>
        </div>

        <!-- 4. Зона виробництва: Виробничі втрати -->
        <div class="kpi-card accent-purple">
            <div class="kpi-zone-tag">Зона виробництва</div>
            <div class="kpi-title">Виробничі втрати переробки</div>
            <div class="kpi-value-row">
                <div class="kpi-main-number" id="val-prod-loss">
                    <?= number_format($mb['production_zone']['production_loss'], 1, ',', ' ') ?><span class="kpi-unit">т</span>
                </div>
                <span class="kpi-badge <?= $mb['production_zone']['status_class'] ?>" id="badge-prod-loss">
                    <?= $mb['production_zone']['production_loss_pct'] ?>%
                </span>
            </div>
            <div class="kpi-subtext">
                <span>Сировина &minus; (Олія + Шрот + Лузга)</span>
                <span>Норма: &lt; 1.2%</span>
            </div>
        </div>
    </div>

    <!-- =============================================================
         ЛАБОРАТОРІЯ ТА КОНТРАКТАЦІЯ (ГРАФІЧНІ ДІАГРАМИ-СПІДОМЕТРИ)
         ============================================================= -->
    <div class="card-panel quality-gauges-panel">
        <div class="section-title">
            <i data-lucide="gauge" style="width: 18px; height: 18px; color: var(--color-primary);"></i>
            <span>Лабораторія якості сировини та контрактація (1С)</span>
            <span class="section-badge badge-blue" id="lbl-lab-time">Звіт 1С станом на: <?= htmlspecialchars($labDate) ?></span>
        </div>

        <div class="gauges-grid">
            <!-- 1. Графік: Олійність насіння -->
            <div class="gauge-card">
                <div class="gauge-header">
                    <span class="gauge-title">🧪 Олійність насіння</span>
                    <span class="gauge-badge badge-bonus" id="badge-lab-oil-dev">+<?= number_format($lab['oil_deviation_pct'] ?? 1.62, 2, ',', ' ') ?>% (Бонус)</span>
                </div>
                <div id="chart-gauge-oil" class="gauge-chart-container"></div>
                <div class="gauge-footer">
                    <div class="gauge-sub">Базова норма: <strong><?= number_format($lab['oil_content_base_pct'] ?? 48.0, 1, ',', ' ') ?>%</strong></div>
                    <div class="gauge-note">Премія до розрахункової ціни</div>
                </div>
            </div>

            <!-- 2. Графік: Вологість сировини -->
            <div class="gauge-card">
                <div class="gauge-header">
                    <span class="gauge-title">💧 Вологість сировини</span>
                    <span class="gauge-badge <?= ($lab['moisture_pct'] ?? 7.15) <= 8.0 ? 'badge-bonus' : 'badge-discount' ?>" id="badge-lab-moisture">
                        <?= ($lab['moisture_pct'] ?? 7.15) <= 8.0 ? 'В нормі (< 8%)' : 'Перевищення' ?>
                    </span>
                </div>
                <div id="chart-gauge-moisture" class="gauge-chart-container"></div>
                <div class="gauge-footer">
                    <div class="gauge-sub">Базова норма: <strong>до 8.00%</strong></div>
                    <div class="gauge-note">Сушіння не потрібне</div>
                </div>
            </div>

            <!-- 3. Графік: Смітна домішка -->
            <div class="gauge-card">
                <div class="gauge-header">
                    <span class="gauge-title">🌾 Смітна домішка (сор)</span>
                    <span class="gauge-badge badge-normal" id="badge-lab-impurity">Знижка: <?= number_format($lab['weight_discount_tonnes'] ?? 12.45, 1, ',', ' ') ?> т</span>
                </div>
                <div id="chart-gauge-impurity" class="gauge-chart-container"></div>
                <div class="gauge-footer">
                    <div class="gauge-sub">Базова норма: <strong>3.00%</strong></div>
                    <div class="gauge-note">Утримання з фізичної ваги</div>
                </div>
            </div>

            <!-- 4. Графік: Виконання контрактів 1С -->
            <div class="gauge-card">
                <div class="gauge-header">
                    <span class="gauge-title">📋 Виконання контрактів (1С)</span>
                    <span class="gauge-badge badge-bonus" id="badge-contracts-pct"><?= number_format($contracts['fulfillment_pct'] ?? 88.0, 1, ',', ' ') ?>%</span>
                </div>
                <div id="chart-gauge-contracts" class="gauge-chart-container"></div>
                <div class="gauge-footer">
                    <div class="gauge-sub">Завезено: <strong id="val-contracts-delivered"><?= number_format($contracts['delivered_tonnes'] ?? 13328.7, 0, ',', ' ') ?> т</strong> / <?= number_format($contracts['contracted_tonnes'] ?? 15146.0, 0, ',', ' ') ?> т</div>
                    <div class="gauge-note" id="val-contracts-sub">Залишок: <?= number_format($contracts['remaining_tonnes'] ?? 1817.3, 0, ',', ' ') ?> т (<?= $contracts['active_specs_count'] ?? 14 ?> у роботі, <?= $contracts['pending_specs_count'] ?? 3 ?> очікують)</div>
                </div>
            </div>
        </div>
    </div>

    <!-- =============================================================
         СЕРЕДНІЙ РЯД: ВИХІД ПРОДУКЦІЇ (DONUT) + ЛОГІСТИКА CRM
         ============================================================= -->
    <div class="middle-grid">
        
        <!-- Кругова діаграма виходу готової продукції -->
        <div class="card-panel">
            <div class="section-title">
                <i data-lucide="pie-chart" style="width: 18px; height: 18px; color: var(--color-oil);"></i>
                <span>Вихід готової продукції</span>
                <span class="section-badge badge-orange">Матеріальний баланс</span>
            </div>

            <div class="chart-layout">
                <div id="production-donut-chart"></div>

                <div class="chart-legend">
                    <div class="legend-item">
                        <span class="legend-name">
                            <span class="legend-color-tag" style="background: var(--color-oil);"></span>
                            Олія сира
                        </span>
                        <div>
                            <span class="legend-values" id="legend-oil-val"><?= number_format($mb['production_zone']['oil_out'], 1, ',', ' ') ?> т</span>
                            <span class="legend-pct" id="legend-oil-pct"><?= $mb['yield_chart']['percentages'][0] ?>%</span>
                        </div>
                    </div>

                    <div class="legend-item">
                        <span class="legend-name">
                            <span class="legend-color-tag" style="background: var(--color-meal);"></span>
                            Шрот тостований
                        </span>
                        <div>
                            <span class="legend-values" id="legend-meal-val"><?= number_format($mb['production_zone']['meal_out'], 1, ',', ' ') ?> т</span>
                            <span class="legend-pct" id="legend-meal-pct"><?= $mb['yield_chart']['percentages'][1] ?>%</span>
                        </div>
                    </div>

                    <div class="legend-item">
                        <span class="legend-name">
                            <span class="legend-color-tag" style="background: var(--color-husk);"></span>
                            Лузга гранульована
                        </span>
                        <div>
                            <span class="legend-values" id="legend-husk-val"><?= number_format($mb['production_zone']['husk_out'], 1, ',', ' ') ?> т</span>
                            <span class="legend-pct" id="legend-husk-pct"><?= $mb['yield_chart']['percentages'][2] ?>%</span>
                        </div>
                    </div>

                    <div class="legend-item">
                        <span class="legend-name">
                            <span class="legend-color-tag" style="background: var(--color-loss);"></span>
                            Технологічні втрати
                        </span>
                        <div>
                            <span class="legend-values" id="legend-loss-val"><?= number_format($mb['production_zone']['production_loss'], 1, ',', ' ') ?> т</span>
                            <span class="legend-pct" id="legend-loss-pct"><?= $mb['yield_chart']['percentages'][3] ?>%</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Складські залишки готової продукції та витрата лузги на котельню -->
            <div class="wh-stocks-header">
                <span class="wh-stocks-title">Складські залишки готової продукції та насіння</span>
                <span class="wh-stocks-time-badge" id="lbl-stocks-time">Станом на: <strong><?= htmlspecialchars($stocksDate) ?></strong> (СВОД)</span>
            </div>
            <div class="warehouse-stocks-grid">
                <div class="wh-stock-item">
                    <span class="wh-stock-title">💧 Олія сира (Резервуари)</span>
                    <span class="wh-stock-value" id="val-stock-oil"><?= number_format($stocks['oil_stock_tonnes'] ?? 3091.3, 1, ',', ' ') ?> т</span>
                    <div class="wh-progress-wrap"><div class="wh-progress-fill wh-fill-oil" style="width: <?= min(100, round((($stocks['oil_stock_tonnes'] ?? 3091.3) / 5000) * 100)) ?>%;"></div></div>
                    <span class="wh-stock-sub" id="val-shipped-oil">Відвантажено за добу: <?= number_format($stocks['oil_shipped_tonnes'] ?? 130.0, 1, ',', ' ') ?> т</span>
                </div>
                <div class="wh-stock-item">
                    <span class="wh-stock-title">🌾 Шрот тостований</span>
                    <span class="wh-stock-value" id="val-stock-meal"><?= number_format($stocks['meal_stock_tonnes'] ?? 757.1, 1, ',', ' ') ?> т</span>
                    <div class="wh-progress-wrap"><div class="wh-progress-fill wh-fill-meal" style="width: <?= min(100, round((($stocks['meal_stock_tonnes'] ?? 757.1) / 1500) * 100)) ?>%;"></div></div>
                    <span class="wh-stock-sub" id="val-shipped-meal">Відвантажено за добу: <?= number_format($stocks['meal_shipped_tonnes'] ?? 189.9, 1, ',', ' ') ?> т</span>
                </div>
                <div class="wh-stock-item">
                    <span class="wh-stock-title">🪵 Лузга на складі</span>
                    <span class="wh-stock-value" id="val-stock-husk"><?= number_format($stocks['husk_stock_tonnes'] ?? 116.8, 1, ',', ' ') ?> т</span>
                    <div class="wh-progress-wrap"><div class="wh-progress-fill wh-fill-husk" style="width: <?= min(100, round((($stocks['husk_stock_tonnes'] ?? 116.8) / 500) * 100)) ?>%;"></div></div>
                    <span class="wh-stock-sub" id="val-shipped-husk">Відвантажено за добу: <?= number_format($stocks['husk_shipped_tonnes'] ?? 48.8, 1, ',', ' ') ?> т</span>
                </div>
                <div class="wh-stock-item" style="background: #fffbeb; border-color: #fef3c7;">
                    <span class="wh-stock-title" style="color: #b45309;">🔥 Лузга на котельню</span>
                    <span class="wh-stock-value" style="color: #b45309;" id="val-boiler-husk"><?= number_format($stocks['husk_boiler_spent_tonnes'] ?? 37.6, 1, ',', ' ') ?> т</span>
                    <div class="wh-progress-wrap"><div class="wh-progress-fill wh-fill-boiler" style="width: <?= min(100, round((($stocks['husk_boiler_spent_tonnes'] ?? 37.6) / 60) * 100)) ?>%;"></div></div>
                    <span class="wh-stock-sub" style="color: #92400e;" id="val-boiler-husk-sub">Паливо на технологічну пару</span>
                </div>
            </div>
        </div>

        <!-- Блок Б: Логістика (Електронна черга CRM - Тільки читання) -->
        <div class="card-panel">
            <div class="section-title" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <i data-lucide="truck" style="width: 18px; height: 18px; color: #0284c7;"></i>
                    <span>Блок Б: Логістика транспорту</span>
                    <span class="section-badge badge-blue">БД: apgk_api08 (Тільки читання)</span>
                </div>
                <!-- Фільтр за потоками вантажу -->
                <div class="cargo-tabs" id="cargo-tabs-container">
                    <button type="button" class="cargo-tab-btn active" data-cargo="Всі">Всі</button>
                    <button type="button" class="cargo-tab-btn" data-cargo="Насіння">🌻 Насіння</button>
                    <button type="button" class="cargo-tab-btn" data-cargo="Олія">💧 Олія</button>
                    <button type="button" class="cargo-tab-btn" data-cargo="Шрот">🌾 Шрот</button>
                    <button type="button" class="cargo-tab-btn" data-cargo="Лузга">🪵 Лузга</button>
                </div>
            </div>

            <!-- Час перебування (в'їзд -> виїзд) окремо за 4 культурами -->
            <div class="cargo-stay-grid">
                <div class="cargo-stay-card">
                    <span class="cargo-name">🌻 Насіння</span>
                    <span class="cargo-stay-time">Сер. час: <strong id="val-stay-seed"><?= $log['categories']['Насіння']['avg_stay_text'] ?? $log['categories']['Семена']['avg_stay_text'] ?? '1 год 30 хв' ?></strong></span>
                    <div class="cargo-meter-bar">
                        <div class="cargo-meter-fill <?= (($log['categories']['Насіння']['avg_stay_minutes'] ?? 84) > 120) ? 'meter-warn' : 'meter-good' ?>" id="bar-stay-seed" style="width: <?= min(100, round((($log['categories']['Насіння']['avg_stay_minutes'] ?? 84) / 120) * 100)) ?>%;"></div>
                    </div>
                    <span class="cargo-truck-count" id="val-cnt-seed">Зараз на заводі: <?= $log['categories']['Насіння']['on_site'] ?? $log['categories']['Семена']['on_site'] ?? 0 ?> авто</span>
                </div>
                <div class="cargo-stay-card">
                    <span class="cargo-name">💧 Олія</span>
                    <span class="cargo-stay-time">Сер. час: <strong id="val-stay-oil"><?= $log['categories']['Олія']['avg_stay_text'] ?? $log['categories']['Масло']['avg_stay_text'] ?? '1 год 15 хв' ?></strong></span>
                    <div class="cargo-meter-bar">
                        <div class="cargo-meter-fill <?= (($log['categories']['Олія']['avg_stay_minutes'] ?? 75) > 120) ? 'meter-warn' : 'meter-good' ?>" id="bar-stay-oil" style="width: <?= min(100, round((($log['categories']['Олія']['avg_stay_minutes'] ?? 75) / 120) * 100)) ?>%;"></div>
                    </div>
                    <span class="cargo-truck-count" id="val-cnt-oil">Зараз на заводі: <?= $log['categories']['Олія']['on_site'] ?? $log['categories']['Масло']['on_site'] ?? 0 ?> авто</span>
                </div>
                <div class="cargo-stay-card">
                    <span class="cargo-name">🌾 Шрот</span>
                    <span class="cargo-stay-time">Сер. час: <strong id="val-stay-meal"><?= $log['categories']['Шрот']['avg_stay_text'] ?? '1 год 30 хв' ?></strong></span>
                    <div class="cargo-meter-bar">
                        <div class="cargo-meter-fill <?= (($log['categories']['Шрот']['avg_stay_minutes'] ?? 90) > 120) ? 'meter-warn' : 'meter-good' ?>" id="bar-stay-meal" style="width: <?= min(100, round((($log['categories']['Шрот']['avg_stay_minutes'] ?? 90) / 120) * 100)) ?>%;"></div>
                    </div>
                    <span class="cargo-truck-count" id="val-cnt-meal">Зараз на заводі: <?= $log['categories']['Шрот']['on_site'] ?? 0 ?> авто</span>
                </div>
                <div class="cargo-stay-card">
                    <span class="cargo-name">🪵 Лузга</span>
                    <span class="cargo-stay-time">Сер. час: <strong id="val-stay-husk"><?= $log['categories']['Лузга']['avg_stay_text'] ?? '45 хв' ?></strong></span>
                    <div class="cargo-meter-bar">
                        <div class="cargo-meter-fill <?= (($log['categories']['Лузга']['avg_stay_minutes'] ?? 45) > 120) ? 'meter-warn' : 'meter-good' ?>" id="bar-stay-husk" style="width: <?= min(100, round((($log['categories']['Лузга']['avg_stay_minutes'] ?? 45) / 120) * 100)) ?>%;"></div>
                    </div>
                    <span class="cargo-truck-count" id="val-cnt-husk">Зараз на заводі: <?= $log['categories']['Лузга']['on_site'] ?? 0 ?> авто</span>
                </div>
            </div>

            <!-- Воронка руху автотранспорту (3 етапи: 1. У черзі, 2. На заводі, 3. Виїхало за добу 08:00 - 08:00) -->
            <div class="funnel-container">
                <div class="funnel-step">
                    <span class="funnel-icon">📝</span>
                    <div class="funnel-count" id="funnel-queued-count"><?= $log['queued'] ?? 0 ?></div>
                    <div class="funnel-label">1. У черзі</div>
                </div>

                <div class="funnel-step active">
                    <span class="funnel-icon">🚚</span>
                    <div class="funnel-count" id="funnel-entered-count"><?= $log['on_site'] ?? ($log['entered'] ?? 0) ?></div>
                    <div class="funnel-label">2. На заводі</div>
                </div>

                <div class="funnel-step">
                    <span class="funnel-icon">🏁</span>
                    <div class="funnel-count" id="funnel-exited-count"><?= $log['exited'] ?? 0 ?></div>
                    <div class="funnel-label">3. Виїхало</div>
                </div>
            </div>

            <!-- Метрики ефективності автотранспорту -->
            <div class="logistics-stats-row">
                <div class="stat-left">
                    <div class="stat-icon-wrap" style="color: #0284c7;">
                        <i data-lucide="clock" style="width: 20px; height: 20px;"></i>
                    </div>
                    <div>
                        <div class="stat-title">Середній час на заводі (<span id="active-cargo-label">Всі</span>)</div>
                        <div class="stat-value" id="val-avg-stay-time"><?= $log['avg_stay_text'] ?? format_duration_minutes($log['avg_stay_minutes']) ?></div>
                    </div>
                </div>
                <div>
                    <span class="kpi-badge status-normal" id="badge-stay-norm">Норма (до 2 год)</span>
                </div>
            </div>

            <div class="logistics-stats-row" style="margin-top: 8px;">
                <div class="stat-left">
                    <div class="stat-icon-wrap" style="color: #10b981;">
                        <i data-lucide="calendar" style="width: 20px; height: 20px;"></i>
                    </div>
                    <div>
                        <div class="stat-title">Виробнича доба (з 08:00 до 08:00)</div>
                        <div class="stat-value" style="font-size: 13px; font-weight: 700; color: #475569;" id="val-operational-day-text"><?= $log['operational_day'] ?? get_operational_day_bounds()['label'] ?></div>
                    </div>
                </div>
                <div>
                    <span class="kpi-badge badge-blue" id="val-on-territory">На території: <?= $log['on_site'] ?? ($log['entered'] ?? 0) ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- =============================================================
         БЛОК В: СОСТОЯНИЕ УЗЛОВ И ЭНЕРГОРЕСУРСЫ (ZABBIX API)
         ============================================================= -->
    <div class="bottom-grid">
        
        <!-- Графік енергетики та води -->
        <div class="card-panel">
            <div class="section-title">
                <i data-lucide="zap" style="width: 18px; height: 18px; color: #f59e0b;"></i>
                <span>Блок В: Енергоресурси заводу (Zabbix API)</span>
                <span class="section-badge badge-green">Енергоефективність</span>
            </div>

            <!-- Мікро-картки витрат -->
            <div class="energy-summary-cards">
                <div class="energy-card-mini">
                    <div class="mini-title">Поточна потужність вводу</div>
                    <div class="mini-number" id="val-current-power"><?= number_format($en['current_power_kw'], 0, ',', ' ') ?> кВт</div>
                </div>
                <div class="energy-card-mini">
                    <div class="mini-title">Витрата води (миттєва)</div>
                    <div class="mini-number" id="val-water-flow"><?= number_format($en['current_water_m3_hour'], 1, ',', ' ') ?> м³/год</div>
                </div>
                <div class="energy-card-mini">
                    <div class="mini-title">Питома витрата на тонну</div>
                    <div class="mini-number" id="val-specific-kwh"><?= $en['specific_kwh_per_tonne'] ?> кВт*год/т</div>
                </div>
            </div>

            <!-- Інтерактивний таймлайн-графік -->
            <div id="energy-timeline-chart"></div>
        </div>

    </div>

    <!-- =============================================================
         ПІДВАЛ
         ============================================================= -->
    <footer class="dashboard-footer">
        <div>
            &copy; <?= date('Y') ?> Внутрішній портал управління виробництвом &bull; Hestia CP Server
        </div>
        <div class="footer-tags">
            <span class="footer-pill">Zabbix API: <?= $config['zabbix']['enabled'] ? 'Активний' : 'Демо / Синхронізація' ?></span>
            <span class="footer-pill">CRM SQL: <?= $config['crm_db']['enabled'] ? 'Прямий (Тільки читання)' : 'Локальна БД' ?></span>
            <span class="footer-pill">PHP <?= PHP_VERSION ?></span>
        </div>
    </footer>

</div>

<!-- =============================================================
     МОДАЛЬНЕ ВІКНО: ЩОДЕННИЙ ІМПОРТ ЗВІТІВ ЗАВОДУ
     ============================================================= -->
<div id="import-modal" class="modal-overlay" style="display: none;">
    <div class="modal-container">
        <div class="modal-header">
            <div class="modal-title-wrap">
                <div class="modal-icon"><i data-lucide="file-spreadsheet"></i></div>
                <div>
                    <h2 class="modal-title">Щоденний імпорт звітів заводу</h2>
                    <p class="modal-subtitle">Завантажте файли Excel (.xlsx / .csv) для автоматичного оновлення показників МЕЗ</p>
                </div>
            </div>
            <button type="button" class="modal-close-btn" id="btn-close-import" title="Закрити">&times;</button>
        </div>

        <div class="modal-body">
            <!-- Список файлів з обов'язковими назвами та описом -->
            <div class="import-files-guide">
                <div class="guide-title">
                    <i data-lucide="info" style="width: 15px; height: 15px; color: var(--color-primary);"></i>
                    <span>Перелік щоденних звітів та обов'язкові назви файлів:</span>
                </div>

                <div class="guide-list">
                    <!-- Файл 1 -->
                    <div class="guide-item" data-expected="lab">
                        <div class="guide-item-icon">📋</div>
                        <div class="guide-item-content">
                            <div class="guide-item-name">1. Звіт «Лист погодження» з постачання соняшнику (1С)</div>
                            <div class="guide-item-filename">Орієнтовна назва: <code>Лист погодження.xlsx</code> або <code>Лист_погодження_*.xlsx</code></div>
                            <div class="guide-item-desc">Містить показники лабораторії (олійність факт/база, вологість, смітна домішка, знижка з ваги) та реєстр специфікацій на довіз.</div>
                        </div>
                        <span class="guide-tag tag-primary">Лабораторія + 1С</span>
                    </div>

                    <!-- Файл 2 -->
                    <div class="guide-item" data-expected="stocks">
                        <div class="guide-item-icon">🏢</div>
                        <div class="guide-item-content">
                            <div class="guide-item-name">2. Звіт «Рух сировини та готової продукції» (СВОД)</div>
                            <div class="guide-item-filename">Орієнтовна назва: <code>СВОД руху продукції.xlsx</code> або <code>Залишки.xlsx</code></div>
                            <div class="guide-item-desc">Містить фізичний залишок насіння на елеваторі (розрахунок днів автономії при 600 т/д), залишок олії в баках, шроту та лузги, списання лузги на котельню.</div>
                        </div>
                        <span class="guide-tag tag-emerald">Складські залишки</span>
                    </div>

                    <!-- Файл 3 -->
                    <div class="guide-item" data-expected="prod">
                        <div class="guide-item-icon">⚙️</div>
                        <div class="guide-item-content">
                            <div class="guide-item-name">3. Звіт «Виробництво та переробка МЕЗ» (Пресування / Екстракція)</div>
                            <div class="guide-item-filename">Орієнтовна назва: <code>Виробництво МЕЗ.xlsx</code> або <code>Матеріальний_баланс.xlsx</code></div>
                            <div class="guide-item-desc">Містить фактичну переробку насіння за зміну/добу, вихід сирої олії, тостованого шроту, гранульованої лузги та технологічні втрати.</div>
                        </div>
                        <span class="guide-tag tag-amber">Матеріальний баланс</span>
                    </div>

                    <!-- Файл 4 -->
                    <div class="guide-item" data-expected="logistics">
                        <div class="guide-item-icon">🚚</div>
                        <div class="guide-item-content">
                            <div class="guide-item-name">4. Реєстр ТТН та вагових (опціонально)</div>
                            <div class="guide-item-filename">Орієнтовна назва: <code>ТТН_автотранспорт.xlsx</code> або <code>Вагова.xlsx</code></div>
                            <div class="guide-item-desc">Використовується для додаткової синхронізації рейсів автотранспорту (якщо відключено пряму базу CRM apgk_api08).</div>
                        </div>
                        <span class="guide-tag tag-slate">Логістика (опція)</span>
                    </div>
                </div>
            </div>

            <!-- Зона завантаження файлів (Drag-and-Drop) -->
            <div class="upload-dropzone" id="upload-dropzone">
                <input type="file" id="file-input-modal" multiple accept=".xlsx,.xls,.csv" style="display: none;">
                <div class="dropzone-icon">
                    <i data-lucide="cloud-upload" style="width: 44px; height: 44px; color: var(--color-primary);"></i>
                </div>
                <div class="dropzone-title">Перетягніть файли звітів сюди або натисніть для вибору</div>
                <div class="dropzone-hint">Підтримуються формати: .xlsx, .xls, .csv (можна обрати кілька файлів одночасно)</div>
                <button type="button" class="btn-browse-files" id="btn-browse-files">
                    <i data-lucide="folder-open" style="width: 14px; height: 14px;"></i>
                    <span>Обрати файли на комп'ютері</span>
                </button>
            </div>

            <!-- Список обраних файлів перед завантаженням -->
            <div class="selected-files-list" id="selected-files-list" style="display: none;">
                <div class="selected-files-header">
                    <span>Обрані файли для імпорту:</span>
                    <button type="button" class="btn-clear-selection" id="btn-clear-files">Очистити</button>
                </div>
                <div class="files-pills-wrap" id="files-pills-wrap"></div>
            </div>

            <!-- Лог результату імпорту -->
            <div id="import-result-box" class="import-result-box" style="display: none;"></div>
        </div>

        <div class="modal-footer">
            <button type="button" class="btn-modal-cancel" id="btn-cancel-modal">Скасувати</button>
            <button type="button" class="btn-modal-submit" id="btn-submit-import" disabled>
                <i data-lucide="check-circle" style="width: 16px; height: 16px;"></i>
                <span>Завантажити та оновити дашборд</span>
            </button>
        </div>
    </div>
</div>

<!-- Контейнер для всплывающих уведомлений (Toast) -->
<div id="toast-container" class="toast-container"></div>

<!-- Передача начальных серверных данных в клиентский JS -->
<script>
    window.INITIAL_DASHBOARD_DATA = <?= json_encode($dashboardData, JSON_UNESCAPED_UNICODE) ?>;
</script>

<script src="assets/js/charts.js?v=<?= file_exists(__DIR__ . '/assets/js/charts.js') ? filemtime(__DIR__ . '/assets/js/charts.js') : time() ?>"></script>
<script src="assets/js/dashboard.js?v=<?= file_exists(__DIR__ . '/assets/js/dashboard.js') ? filemtime(__DIR__ . '/assets/js/dashboard.js') : time() ?>"></script>
<script>
    // Рендер иконок Lucide
    lucide.createIcons();
</script>

</body>
</html>
