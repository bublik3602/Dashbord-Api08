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
$historical = $dashboardData['historical_analytics'] ?? [];
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
            <!-- Перемикач періодів: Доба, Тиждень, Місяць та випадаючий список інших місяців -->
            <div class="period-switcher">
                <button type="button" class="period-btn active" data-period="day">Доба</button>
                <button type="button" class="period-btn" data-period="week">Тиждень</button>
                <button type="button" class="period-btn" data-period="month">Місяць</button>
                <div class="month-select-wrapper">
                    <select id="select-other-months" class="month-dropdown-select" title="Обрати інший місяць або період">
                        <option value="" disabled selected>📅 Інші місяці...</option>
                        <option value="month_oct">Жовтень 2026 (11 діб)</option>
                        <option value="month_sep">Вересень 2026 (30 діб)</option>
                        <option value="month_aug">Серпень 2026 (31 доба, ППР)</option>
                        <option value="season">Сезон 2026 (Серпень — Жовтень)</option>
                        <option value="day_prev">Минула доба (11.10 — прийомка 465,9 т, переробка 0 т)</option>
                        <option value="day_active">Остання доба переробки (06.10 — перероблено 600,3 т)</option>
                        <option value="day_today">Сьогодні (12.10, без переробки)</option>
                    </select>
                </div>
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
                <span id="val-cond-loss">Усушка/сміття: <?= number_format($mb['receiving_zone']['conditioning_loss'], 1, ',', ' ') ?> т</span>
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
                    <?= (($mb['production_zone']['yield_surplus_pct'] ?? 0) > 0) ? ('+' . $mb['production_zone']['yield_surplus_pct'] . '% вихід') : ($mb['production_zone']['production_loss_pct'] . '%') ?>
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
                    <span class="gauge-badge badge-bonus" id="badge-lab-oil-dev">+<?= number_format($lab['oil_deviation_pct'] ?? 1.61, 2, ',', ' ') ?>% (Бонус)</span>
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
                    <span class="gauge-badge <?= ($lab['moisture_pct'] ?? 7.71) <= 8.0 ? 'badge-bonus' : 'badge-discount' ?>" id="badge-lab-moisture">
                        <?= ($lab['moisture_pct'] ?? 7.71) <= 8.0 ? 'В нормі (< 8%)' : 'Перевищення' ?>
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
                    <span class="gauge-badge badge-normal" id="badge-lab-impurity">Знижка: <?= number_format($lab['weight_discount_tonnes'] ?? 3.10, 1, ',', ' ') ?> т</span>
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
                    <span class="gauge-badge badge-bonus" id="badge-contracts-pct"><?= number_format($contracts['fulfillment_pct'] ?? 94.3, 1, ',', ' ') ?>%</span>
                </div>
                <div id="chart-gauge-contracts" class="gauge-chart-container"></div>
                <div class="gauge-footer">
                    <div class="gauge-sub">Завезено: <strong id="val-contracts-delivered"><?= number_format($contracts['delivered_tonnes'] ?? 17642.9, 0, ',', ' ') ?> т / <?= number_format($contracts['contracted_tonnes'] ?? 18711.0, 0, ',', ' ') ?> т</strong></div>
                    <div class="gauge-note" id="val-contracts-sub">Залишок: <?= number_format($contracts['remaining_tonnes'] ?? 1647.9, 0, ',', ' ') ?> т (<?= $contracts['active_specs_count'] ?? 11 ?> у роботі, <?= $contracts['pending_specs_count'] ?? 3 ?> очікують)</div>
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

            <!-- ВЕЛИКІ ЦИФРИ: ВИХІД ПРОДУКЦІЇ У ВІДСОТКАХ (ОЛІЯ + ШРОТ + ЛУЗГА) -->
            <div class="yield-big-metrics-grid">
                <div class="yield-big-card yield-card-oil">
                    <div class="yield-big-label">💧 Олія</div>
                    <div class="yield-big-pct" id="big-yield-oil"><?= $mb['production_zone']['oil_yield_pct'] ?>%</div>
                    <div class="yield-big-val" id="sub-yield-oil"><?= number_format($mb['production_zone']['oil_out'], 1, ',', ' ') ?> т</div>
                </div>
                <div class="yield-big-card yield-card-meal">
                    <div class="yield-big-label">🌾 Шрот</div>
                    <div class="yield-big-pct" id="big-yield-meal"><?= $mb['production_zone']['meal_yield_pct'] ?>%</div>
                    <div class="yield-big-val" id="sub-yield-meal"><?= number_format($mb['production_zone']['meal_out'], 1, ',', ' ') ?> т</div>
                </div>
                <div class="yield-big-card yield-card-husk">
                    <div class="yield-big-label">🪵 Лузга</div>
                    <div class="yield-big-pct" id="big-yield-husk"><?= $mb['production_zone']['husk_yield_pct'] ?>%</div>
                    <div class="yield-big-val" id="sub-yield-husk"><?= number_format($mb['production_zone']['husk_out'], 1, ',', ' ') ?> т</div>
                </div>
                <div class="yield-big-card yield-card-total">
                    <div class="yield-big-label">📦 Разом (Олія + Шрот + Лузга)</div>
                    <div class="yield-big-pct" id="big-yield-total">
                        <?= $mb['production_zone']['total_yield_pct'] ?>%<?php if (($mb['production_zone']['yield_surplus_pct'] ?? 0) > 0): ?> <span class="yield-plus-badge">+<?= $mb['production_zone']['yield_surplus_pct'] ?>%</span><?php endif; ?>
                    </div>
                    <div class="yield-big-val" id="sub-yield-total">
                        <?= number_format($mb['production_zone']['products_total'], 1, ',', ' ') ?> т<?= (($mb['production_zone']['yield_surplus_tonnes'] ?? 0) > 0) ? (' (+' . number_format($mb['production_zone']['yield_surplus_tonnes'], 1, ',', ' ') . ' т)') : '' ?>
                    </div>
                </div>
            </div>

            <div class="chart-layout">
                <div id="production-donut-chart"></div>

                <div class="chart-legend">
                    <div class="legend-item">
                        <span class="legend-name">
                            <span class="legend-color-tag" style="background: var(--color-oil);"></span>
                            Олія
                        </span>
                        <div>
                            <span class="legend-values" id="legend-oil-val"><?= number_format($mb['production_zone']['oil_out'], 1, ',', ' ') ?> т</span>
                            <span class="legend-pct" id="legend-oil-pct"><?= $mb['yield_chart']['percentages'][0] ?>%</span>
                        </div>
                    </div>

                    <div class="legend-item">
                        <span class="legend-name">
                            <span class="legend-color-tag" style="background: var(--color-meal);"></span>
                            Шрот
                        </span>
                        <div>
                            <span class="legend-values" id="legend-meal-val"><?= number_format($mb['production_zone']['meal_out'], 1, ',', ' ') ?> т</span>
                            <span class="legend-pct" id="legend-meal-pct"><?= $mb['yield_chart']['percentages'][1] ?>%</span>
                        </div>
                    </div>

                    <div class="legend-item">
                        <span class="legend-name">
                            <span class="legend-color-tag" style="background: var(--color-husk);"></span>
                            Лузга
                        </span>
                        <div>
                            <span class="legend-values" id="legend-husk-val"><?= number_format($mb['production_zone']['husk_out'], 1, ',', ' ') ?> т</span>
                            <span class="legend-pct" id="legend-husk-pct"><?= $mb['yield_chart']['percentages'][2] ?>%</span>
                        </div>
                    </div>

                    <div class="legend-item">
                        <span class="legend-name">
                            <span class="legend-color-tag" id="legend-loss-dot" style="background: <?= (($mb['production_zone']['yield_surplus_pct'] ?? 0) > 0) ? '#10b981' : 'var(--color-loss)' ?>;"></span>
                            <span id="legend-loss-label"><?= (($mb['production_zone']['yield_surplus_pct'] ?? 0) > 0) ? 'Перевищення виходу (баланс +)' : 'Технологічні втрати' ?></span>
                        </span>
                        <div>
                            <span class="legend-values" id="legend-loss-val"><?= (($mb['production_zone']['yield_surplus_tonnes'] ?? 0) > 0) ? ('+' . number_format($mb['production_zone']['yield_surplus_tonnes'], 1, ',', ' ') . ' т') : (number_format($mb['production_zone']['production_loss'], 1, ',', ' ') . ' т') ?></span>
                            <span class="legend-pct" id="legend-loss-pct"><?= (($mb['production_zone']['yield_surplus_pct'] ?? 0) > 0) ? ('+' . $mb['production_zone']['yield_surplus_pct'] . '%') : ($mb['yield_chart']['percentages'][3] . '%') ?></span>
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
                    <span class="wh-stock-title">💧 Олія (Резервуари)</span>
                    <span class="wh-stock-value" id="val-stock-oil"><?= number_format($stocks['oil_stock_tonnes'] ?? 3091.3, 1, ',', ' ') ?> т</span>
                    <div class="wh-progress-wrap"><div class="wh-progress-fill wh-fill-oil" style="width: <?= min(100, round((($stocks['oil_stock_tonnes'] ?? 3091.3) / 5000) * 100)) ?>%;"></div></div>
                    <span class="wh-stock-sub" id="val-shipped-oil">Відвантажено за добу: <?= number_format($stocks['oil_shipped_tonnes'] ?? 130.0, 1, ',', ' ') ?> т</span>
                </div>
                <div class="wh-stock-item">
                    <span class="wh-stock-title">🌾 Шрот на складі</span>
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
                    <span class="cargo-truck-count" id="val-cnt-seed">На заводі: <?= $log['categories']['Насіння']['on_site'] ?? $log['categories']['Семена']['on_site'] ?? 0 ?> &bull; Виїхало: <?= $log['categories']['Насіння']['exited'] ?? $log['categories']['Семена']['exited'] ?? 0 ?></span>
                </div>
                <div class="cargo-stay-card">
                    <span class="cargo-name">💧 Олія</span>
                    <span class="cargo-stay-time">Сер. час: <strong id="val-stay-oil"><?= $log['categories']['Олія']['avg_stay_text'] ?? $log['categories']['Масло']['avg_stay_text'] ?? '1 год 15 хв' ?></strong></span>
                    <div class="cargo-meter-bar">
                        <div class="cargo-meter-fill <?= (($log['categories']['Олія']['avg_stay_minutes'] ?? 75) > 120) ? 'meter-warn' : 'meter-good' ?>" id="bar-stay-oil" style="width: <?= min(100, round((($log['categories']['Олія']['avg_stay_minutes'] ?? 75) / 120) * 100)) ?>%;"></div>
                    </div>
                    <span class="cargo-truck-count" id="val-cnt-oil">На заводі: <?= $log['categories']['Олія']['on_site'] ?? $log['categories']['Масло']['on_site'] ?? 0 ?> &bull; Виїхало: <?= $log['categories']['Олія']['exited'] ?? $log['categories']['Масло']['exited'] ?? 0 ?></span>
                </div>
                <div class="cargo-stay-card">
                    <span class="cargo-name">🌾 Шрот</span>
                    <span class="cargo-stay-time">Сер. час: <strong id="val-stay-meal"><?= $log['categories']['Шрот']['avg_stay_text'] ?? '1 год 30 хв' ?></strong></span>
                    <div class="cargo-meter-bar">
                        <div class="cargo-meter-fill <?= (($log['categories']['Шрот']['avg_stay_minutes'] ?? 90) > 120) ? 'meter-warn' : 'meter-good' ?>" id="bar-stay-meal" style="width: <?= min(100, round((($log['categories']['Шрот']['avg_stay_minutes'] ?? 90) / 120) * 100)) ?>%;"></div>
                    </div>
                    <span class="cargo-truck-count" id="val-cnt-meal">На заводі: <?= $log['categories']['Шрот']['on_site'] ?? 0 ?> &bull; Виїхало: <?= $log['categories']['Шрот']['exited'] ?? 0 ?></span>
                </div>
                <div class="cargo-stay-card">
                    <span class="cargo-name">🪵 Лузга</span>
                    <span class="cargo-stay-time">Сер. час: <strong id="val-stay-husk"><?= $log['categories']['Лузга']['avg_stay_text'] ?? '45 хв' ?></strong></span>
                    <div class="cargo-meter-bar">
                        <div class="cargo-meter-fill <?= (($log['categories']['Лузга']['avg_stay_minutes'] ?? 45) > 120) ? 'meter-warn' : 'meter-good' ?>" id="bar-stay-husk" style="width: <?= min(100, round((($log['categories']['Лузга']['avg_stay_minutes'] ?? 45) / 120) * 100)) ?>%;"></div>
                    </div>
                    <span class="cargo-truck-count" id="val-cnt-husk">На заводі: <?= $log['categories']['Лузга']['on_site'] ?? 0 ?> &bull; Виїхало: <?= $log['categories']['Лузга']['exited'] ?? 0 ?></span>
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

            <!-- Деталізація виїхавших машин за культурами (Насіння, Олія, Шрот, Лузга) -->
            <div class="exited-breakdown-box" id="exited-breakdown-box">
                <div class="exited-breakdown-title">
                    🏁 Виїхало: <strong id="val-exited-total-inline"><?= $log['categories']['Всі']['exited'] ?? ($log['exited'] ?? 0) ?> машин</strong> — з них:
                </div>
                <div class="exited-breakdown-pills">
                    <span class="exited-pill pill-seed">🌻 Насіння: <strong id="val-exited-seed"><?= $log['categories']['Насіння']['exited'] ?? $log['categories']['Семена']['exited'] ?? 0 ?></strong></span>
                    <span class="exited-pill pill-oil">💧 Олія: <strong id="val-exited-oil"><?= $log['categories']['Олія']['exited'] ?? $log['categories']['Масло']['exited'] ?? 0 ?></strong></span>
                    <span class="exited-pill pill-meal">🌾 Шрот: <strong id="val-exited-meal"><?= $log['categories']['Шрот']['exited'] ?? 0 ?></strong></span>
                    <span class="exited-pill pill-husk">🪵 Лузга: <strong id="val-exited-husk"><?= $log['categories']['Лузга']['exited'] ?? 0 ?></strong></span>
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
         БЛОК В: ЕНЕРГОРЕСУРСИ ЗАВОДУ (ЕЛЕКТРОЕНЕРГІЯ, ВОДА, СТОКИ, ГАЗ)
         ============================================================= -->
    <div class="bottom-grid">
        
        <!-- Графік енергетики та води + Звіт головного енергетика -->
        <div class="card-panel">
            <div class="section-title" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <i data-lucide="zap" style="width: 18px; height: 18px; color: #f59e0b;"></i>
                    <span>Блок В: Електроенергія та водопостачання заводу</span>
                    <span class="section-badge badge-green" id="lbl-energy-period"><?= htmlspecialchars($en['period_note'] ?? 'Звіт гол. енергетика та лічильники') ?></span>
                </div>
                <span class="section-badge badge-blue">Лічильники ТП 2×1600 / ТП-2000 та водомірний вузол</span>
            </div>

            <!-- 4 картки витрат електроенергії та води -->
            <div class="energy-summary-cards energy-cards-4">
                <div class="energy-card-mini" style="border-top: 3px solid #0284c7;">
                    <div class="mini-title">⚡ Активна електроенергія</div>
                    <div class="mini-number" id="val-total-kwh" style="color: #0284c7;"><?= number_format($en['total_kwh'], 0, ',', ' ') ?> кВт·год</div>
                    <div class="mini-sub" id="val-current-power">Потужність: <?= number_format($en['current_power_kw'], 1, ',', ' ') ?> кВт &bull; Реакт.: <?= number_format($en['reactive_kvarh'] ?? 1450, 0, ',', ' ') ?> кВАр·год</div>
                </div>
                <div class="energy-card-mini" style="border-top: 3px solid #10b981;">
                    <div class="mini-title">💧 Споживання води (вхідний ліч.)</div>
                    <div class="mini-number" id="val-water-total" style="color: #059669;"><?= number_format($en['total_water_m3'], 1, ',', ' ') ?> м³</div>
                    <div class="mini-sub" id="val-water-flow">Потік: <?= number_format($en['current_water_m3_hour'], 2, ',', ' ') ?> м³/год (<?= number_format($en['daily_water_m3'] ?? 163.7, 1, ',', ' ') ?> м³/добу)</div>
                </div>
                <div class="energy-card-mini" style="border-top: 3px solid #f59e0b;">
                    <div class="mini-title">⚙️ Питома витрата на 1 т насіння</div>
                    <div class="mini-number" id="val-specific-kwh" style="color: #d97706;"><?= number_format($en['specific_kwh_per_tonne'], 2, ',', ' ') ?> кВт·год/т</div>
                    <div class="mini-sub" id="val-specific-sub"><?= htmlspecialchars($en['specific_subtext'] ?? 'Прес: 27,92 + Екстр: 13,26 кВт·год/т') ?></div>
                </div>
                <div class="energy-card-mini" style="border-top: 3px solid #8b5cf6;">
                    <div class="mini-title">🔥 Котельня, екстракція та стоки</div>
                    <div class="mini-number" id="val-sewage-total" style="color: #6d28d9;">Стоки: <?= number_format($en['sewage_m3'] ?? 72.6, 1, ',', ' ') ?> м³</div>
                    <div class="mini-sub" id="val-boiler-water">Котельня: <?= number_format($en['boiler_water_m3'] ?? 95, 0, ',', ' ') ?> м³ &bull; Екстракція: <?= number_format($en['extraction_water_m3'] ?? 32, 0, ',', ' ') ?> м³</div>
                </div>
            </div>

            <!-- Інтерактивний графік електроенергії та води -->
            <div id="energy-timeline-chart"></div>

            <!-- Розподіл за структурними підрозділами та помісячний баланс року -->
            <div class="energy-details-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 14px;">
                <!-- Таблиця 1: Розподіл за структурними підрозділами -->
                <details class="season-monthly-details" open>
                    <summary>
                        <span><i data-lucide="cpu" style="width: 14px; height: 14px; vertical-align: middle; margin-right: 4px;"></i> Розподіл електроенергії та води за структурними підрозділами</span>
                        <span style="font-size: 11px; color: #64748b;">Активний період</span>
                    </summary>
                    <div class="season-monthly-table-wrap">
                        <table class="data-table-clean" style="font-size: 11px;">
                            <thead>
                                <tr>
                                    <th>Підрозділ заводу</th>
                                    <th>Активна (кВт·год)</th>
                                    <th>Реактивна (кВАр·год)</th>
                                    <th>Питома (кВт·год/т)</th>
                                    <th>Вода (м³)</th>
                                    <th>Каналізація (м³)</th>
                                </tr>
                            </thead>
                            <tbody id="energy-departments-tbody">
                                <?php foreach (($en['departments'] ?? []) as $dept): ?>
                                <tr>
                                    <td class="bold"><?= htmlspecialchars($dept['name']) ?></td>
                                    <td class="bold" style="color: #0284c7;"><?= number_format($dept['active_kwh'], 0, ',', ' ') ?></td>
                                    <td><?= number_format($dept['reactive_kvarh'], 1, ',', ' ') ?></td>
                                    <td><?= $dept['specific_kwh'] > 0 ? number_format($dept['specific_kwh'], 2, ',', '') : '—' ?></td>
                                    <td class="bold" style="color: #059669;"><?= number_format($dept['water_m3'], 0, ',', ' ') ?></td>
                                    <td><?= number_format($dept['sewage_m3'], 0, ',', ' ') ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </details>

                <!-- Таблиця 2: Помісячне зведення енергоносіїв за 12 місяців -->
                <details class="season-monthly-details" open>
                    <summary>
                        <span><i data-lucide="calendar" style="width: 14px; height: 14px; vertical-align: middle; margin-right: 4px;"></i> Річний баланс: Електроенергія, Вода, Газ та Каналізація (12 місяців)</span>
                        <span style="font-size: 11px; color: #64748b;">Січень — Грудень</span>
                    </summary>
                    <div class="season-monthly-table-wrap">
                        <table class="data-table-clean" style="font-size: 11px;">
                            <thead>
                                <tr>
                                    <th>Місяць</th>
                                    <th>Ел.енергія (кВт·год)</th>
                                    <th>Реактивна</th>
                                    <th>Прес + Екстр</th>
                                    <th>Вода (м³)</th>
                                    <th>Вода/добу</th>
                                    <th>Стоки (м³)</th>
                                    <th>Газ (м³)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (($en['monthly_12'] ?? []) as $em): ?>
                                <tr>
                                    <td class="bold"><?= htmlspecialchars($em['month']) ?></td>
                                    <td class="bold" style="color: #0284c7;"><?= number_format($em['active_kwh'], 0, ',', ' ') ?></td>
                                    <td><?= number_format($em['reactive_kvarh'], 0, ',', ' ') ?></td>
                                    <td><?= number_format($em['press_kwh'] + $em['extr_kwh'], 0, ',', ' ') ?></td>
                                    <td class="bold" style="color: #059669;"><?= number_format($em['water_m3'], 0, ',', ' ') ?></td>
                                    <td><?= number_format($em['daily_water_m3'], 1, ',', '') ?> м³</td>
                                    <td><?= number_format($em['sewage_m3'], 0, ',', ' ') ?></td>
                                    <td><?= $em['gas_m3'] > 0 ? number_format($em['gas_m3'], 0, ',', ' ') : '—' ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </details>
            </div>
        </div>

    </div>

    <!-- =============================================================
         БЛОК Г: АНАЛІТИКА ТА ПОРІВНЯННЯ ПЕРІОДІВ (СЕРПЕНЬ — ВЕРЕСЕНЬ — ЖОВТЕНЬ 2026)
         ============================================================= -->
    <?php
    $histMonths  = $historical['months'] ?? [];
    $mAug        = $histMonths['aug'] ?? [];
    $mSep        = $histMonths['sep'] ?? [];
    $mOct        = $histMonths['oct'] ?? [];
    $seasonTot   = $historical['season_total'] ?? [];
    $season2025  = $historical['season_2025_comparison'] ?? [];
    $early2025   = $season2025['early_season_sep_oct'] ?? [];
    $full2025    = $season2025['full_season_total'] ?? [];
    $months2025  = $season2025['months'] ?? [];
    ?>
    <div class="historical-analytics-section" id="historical-analytics-section">
        <div class="section-title">
            <i data-lucide="line-chart" style="width: 18px; height: 18px; color: var(--color-primary);"></i>
            <span>Блок Г: Порівняльна аналітика періодів (Серпень — Вересень — Жовтень 2026)</span>
            <span class="section-badge badge-blue">Виробничий сезон 2026 (72 доби)</span>
        </div>

        <!-- Картки-підсумки помісячно (Серпень, Вересень, Жовтень, Сезон) -->
        <div class="historical-cards-grid">
            
            <!-- Серпень 2026 -->
            <div class="history-month-card" data-period-trigger="month_aug">
                <div class="month-card-header">
                    <div class="month-card-title">
                        <span class="month-name">Серпень 2026</span>
                        <span class="month-badge badge-amber">31 доба &bull; ППР</span>
                    </div>
                    <button type="button" class="btn-quick-period" data-period="month_aug" title="Показати дашборд за серпень">
                        <span>Детально</span> &rarr;
                    </button>
                </div>
                <div class="month-metrics-list">
                    <div class="metric-row">
                        <span class="metric-lbl">🌻 Завіз насіння:</span>
                        <span class="metric-val bold"><?= number_format($mAug['seed_in'] ?? 207.96, 2, ',', ' ') ?> т</span>
                    </div>
                    <div class="metric-row">
                        <span class="metric-lbl">⚙️ Переробка:</span>
                        <span class="metric-val text-muted">0,00 т (Ремонт)</span>
                    </div>
                    <div class="metric-row">
                        <span class="metric-lbl">💧 Вихід олії:</span>
                        <span class="metric-val text-muted">0,00 т</span>
                    </div>
                    <div class="metric-row">
                        <span class="metric-lbl">🌾 Вихід шроту:</span>
                        <span class="metric-val text-muted">0,00 т</span>
                    </div>
                    <div class="metric-row">
                        <span class="metric-lbl">🚚 Автотранспорт:</span>
                        <span class="metric-val"><?= $mAug['trucks_count'] ?? 8 ?> авто</span>
                    </div>
                    <div class="metric-row">
                        <span class="metric-lbl">🏢 Залишок насіння:</span>
                        <span class="metric-val"><?= number_format($mAug['seed_stock'] ?? 883.4, 2, ',', ' ') ?> т</span>
                    </div>
                </div>
                <div class="month-card-footer">
                    <span>Відвантажено: олія 76,6 т, шрот 1 390,5 т</span>
                </div>
            </div>

            <!-- Вересень 2026 -->
            <div class="history-month-card" data-period-trigger="month_sep">
                <div class="month-card-header">
                    <div class="month-card-title">
                        <span class="month-name">Вересень 2026</span>
                        <span class="month-badge badge-green">30 діб &bull; 100% запуск</span>
                    </div>
                    <button type="button" class="btn-quick-period" data-period="month_sep" title="Показати дашборд за вересень">
                        <span>Детально</span> &rarr;
                    </button>
                </div>
                <div class="month-metrics-list">
                    <div class="metric-row">
                        <span class="metric-lbl">🌻 Завіз насіння:</span>
                        <span class="metric-val bold text-primary"><?= number_format($mSep['seed_in'] ?? 19070.05, 2, ',', ' ') ?> т</span>
                    </div>
                    <div class="metric-row">
                        <span class="metric-lbl">⚙️ Переробка:</span>
                        <span class="metric-val bold"><?= number_format($mSep['seed_processed'] ?? 8823.66, 2, ',', ' ') ?> т</span>
                    </div>
                    <div class="metric-row">
                        <span class="metric-lbl">💧 Вихід олії:</span>
                        <span class="metric-val bold" style="color: #d97706;"><?= number_format($mSep['oil_out'] ?? 3811.66, 2, ',', ' ') ?> т (<?= $mSep['oil_yield_pct'] ?? 43.20 ?>%)</span>
                    </div>
                    <div class="metric-row">
                        <span class="metric-lbl">🌾 Вихід шроту:</span>
                        <span class="metric-val bold" style="color: #2563eb;"><?= number_format($mSep['meal_out'] ?? 3683.21, 2, ',', ' ') ?> т (<?= $mSep['meal_yield_pct'] ?? 41.74 ?>%)</span>
                    </div>
                    <div class="metric-row">
                        <span class="metric-lbl">🚚 Автотранспорт:</span>
                        <span class="metric-val bold"><?= $mSep['trucks_count'] ?? 738 ?> авто</span>
                    </div>
                    <div class="metric-row">
                        <span class="metric-lbl">🏢 Залишок насіння:</span>
                        <span class="metric-val"><?= number_format($mSep['seed_stock'] ?? 10966.28, 2, ',', ' ') ?> т</span>
                    </div>
                </div>
                <div class="month-card-footer">
                    <span>Лузга: 1 261,2 т &bull; Відвантажено: олія 1 090 т, шрот 2 332 т</span>
                </div>
            </div>

            <!-- Жовтень 2026 -->
            <div class="history-month-card" data-period-trigger="month_oct">
                <div class="month-card-header">
                    <div class="month-card-title">
                        <span class="month-name">Жовтень 2026</span>
                        <span class="month-badge badge-blue">11 діб (01.10 — 11.10)</span>
                    </div>
                    <button type="button" class="btn-quick-period" data-period="month_oct" title="Показати дашборд за жовтень">
                        <span>Детально</span> &rarr;
                    </button>
                </div>
                <div class="month-metrics-list">
                    <div class="metric-row">
                        <span class="metric-lbl">🌻 Завіз насіння:</span>
                        <span class="metric-val bold text-primary"><?= number_format($mOct['seed_in'] ?? 6075.91, 2, ',', ' ') ?> т</span>
                    </div>
                    <div class="metric-row">
                        <span class="metric-lbl">⚙️ Переробка:</span>
                        <span class="metric-val bold"><?= number_format($mOct['seed_processed'] ?? 3646.35, 2, ',', ' ') ?> т</span>
                    </div>
                    <div class="metric-row">
                        <span class="metric-lbl">💧 Вихід олії:</span>
                        <span class="metric-val bold" style="color: #d97706;"><?= number_format($mOct['oil_out'] ?? 1646.15, 2, ',', ' ') ?> т (<?= $mOct['oil_yield_pct'] ?? 45.15 ?>%)</span>
                    </div>
                    <div class="metric-row">
                        <span class="metric-lbl">🌾 Вихід шроту:</span>
                        <span class="metric-val bold" style="color: #2563eb;"><?= number_format($mOct['meal_out'] ?? 1521.68, 2, ',', ' ') ?> т (<?= $mOct['meal_yield_pct'] ?? 41.73 ?>%)</span>
                    </div>
                    <div class="metric-row">
                        <span class="metric-lbl">🚚 Автотранспорт:</span>
                        <span class="metric-val bold"><?= $mOct['trucks_count'] ?? 231 ?> авто</span>
                    </div>
                    <div class="metric-row">
                        <span class="metric-lbl">🏢 Залишок насіння:</span>
                        <span class="metric-val"><?= number_format($mOct['seed_stock'] ?? 13326.41, 2, ',', ' ') ?> т</span>
                    </div>
                </div>
                <div class="month-card-footer">
                    <span>Лузга: 554,9 т &bull; Відвантажено: олія 1 024 т, шрот 2 179 т</span>
                </div>
            </div>

            <!-- Разом за сезон 2026 -->
            <div class="history-month-card season-summary-card" data-period-trigger="season">
                <div class="month-card-header">
                    <div class="month-card-title">
                        <span class="month-name">Сезон 2026 РАЗОМ</span>
                        <span class="month-badge badge-green">72 доби моніторингу</span>
                    </div>
                    <button type="button" class="btn-quick-period" data-period="season" title="Показати дашборд за сезон">
                        <span>Детально</span> &rarr;
                    </button>
                </div>
                <div class="month-metrics-list">
                    <div class="metric-row">
                        <span class="metric-lbl">🌻 Завіз насіння:</span>
                        <span class="metric-val bold text-primary"><?= number_format($seasonTot['seed_in'] ?? 25353.92, 2, ',', ' ') ?> т</span>
                    </div>
                    <div class="metric-row">
                        <span class="metric-lbl">⚙️ Переробка:</span>
                        <span class="metric-val bold"><?= number_format($seasonTot['seed_processed'] ?? 12470.01, 2, ',', ' ') ?> т</span>
                    </div>
                    <div class="metric-row">
                        <span class="metric-lbl">💧 Вихід олії:</span>
                        <span class="metric-val bold" style="color: #d97706;"><?= number_format($seasonTot['oil_out'] ?? 5457.81, 2, ',', ' ') ?> т (<?= $seasonTot['oil_yield_pct'] ?? 43.77 ?>%)</span>
                    </div>
                    <div class="metric-row">
                        <span class="metric-lbl">🌾 Вихід шроту:</span>
                        <span class="metric-val bold" style="color: #2563eb;"><?= number_format($seasonTot['meal_out'] ?? 5204.89, 2, ',', ' ') ?> т (<?= $seasonTot['meal_yield_pct'] ?? 41.74 ?>%)</span>
                    </div>
                    <div class="metric-row">
                        <span class="metric-lbl">🚚 Автотранспорт:</span>
                        <span class="metric-val bold"><?= $seasonTot['trucks_count'] ?? 977 ?> авто</span>
                    </div>
                    <div class="metric-row">
                        <span class="metric-lbl">🪵 Лузга всього:</span>
                        <span class="metric-val"><?= number_format($seasonTot['husk_out'] ?? 1822.01, 2, ',', ' ') ?> т</span>
                    </div>
                </div>
                <div class="month-card-footer">
                    <span>Залишки зараз: Олія <?= number_format($seasonTot['oil_stock'] ?? 3358.63, 1, ',', ' ') ?> т • Шрот <?= number_format($seasonTot['meal_stock'] ?? 1056.13, 1, ',', ' ') ?> т</span>
                </div>
            </div>

        </div>

        <!-- Графіки та порівняльна таблиця (Двоколонковий блок) -->
        <div class="historical-details-grid">
            
            <!-- Колонка 1: Графік помісячного порівняння виробництва -->
            <div class="card-panel">
                <div class="panel-header-simple">
                    <div class="panel-title-text">
                        <i data-lucide="bar-chart-2" style="width: 16px; height: 16px; color: var(--color-primary);"></i>
                        <span>Порівняння обсягів: Серпень vs Вересень vs Жовтень (тонн)</span>
                    </div>
                    <span class="section-badge badge-blue">Динаміка 2026</span>
                </div>
                <div id="monthly-analytics-chart" style="min-height: 290px;"></div>
            </div>

            <!-- Колонка 2: Сезонне зіставлення 2026 vs 2025 (Інфографіка та великі цифри) -->
            <div class="card-panel">
                <div class="panel-header-simple">
                    <div class="panel-title-text">
                        <i data-lucide="scale" style="width: 16px; height: 16px; color: #10b981;"></i>
                        <span>Порівняння з сезоном 2025–2026 рр. (Офіційний звіт заводу)</span>
                    </div>
                    <span class="section-badge badge-green">Еталон урожаю 2025</span>
                </div>

                <!-- БЛОК ВЕЛИКИХ ЦИФР ТА ІНФОГРАФІКИ (4 KPI КАРТКИ) -->
                <div class="season-kpi-grid">
                    
                    <!-- 1. Переробка соняшнику -->
                    <div class="season-kpi-card card-accent-seed" title="Перероблено у 2025: Вересень 4 435 т + Жовтень 16 682 т = 21 117 т; Всього за рік 61 893 т">
                        <div class="season-kpi-header">
                            <span class="season-kpi-title">⚙️ Переробка насіння</span>
                            <span class="season-kpi-badge">Вер–Жов: 21,1 тис. т</span>
                        </div>
                        <div class="season-kpi-number-wrap">
                            <span class="season-kpi-number"><?= number_format($full2025['seed_processed'] ?? 61892.86, 0, ',', ' ') ?></span>
                            <span class="season-kpi-unit">т (рік)</span>
                        </div>
                        <div class="season-kpi-subtext">
                            Сезон 2026: <strong><?= number_format($seasonTot['seed_processed'] ?? 12470.01, 1, ',', ' ') ?> т</strong> (запуск у вересні)
                        </div>
                        <div class="season-progress-track" title="Співвідношення поточного обсягу до річного обсягу 2025">
                            <div class="season-progress-fill" style="width: 20.1%; background: #10b981;"></div>
                        </div>
                    </div>

                    <!-- 2. Вихід Олії -->
                    <div class="season-kpi-card card-accent-oil" title="Вихід олії 2025: Вер 42.06%, Жов 44.45%, Рік 44.30% (27 416.9 т) при олійності 45.13%">
                        <div class="season-kpi-header">
                            <span class="season-kpi-title" style="color: #b45309;">💧 Вихід Олії</span>
                            <span class="season-kpi-badge" style="background: #fef3c7; color: #b45309;">27,4 тис. т</span>
                        </div>
                        <div class="season-kpi-number-wrap">
                            <span class="season-kpi-number" style="color: #d97706;"><?= number_format($full2025['oil_pct'] ?? 44.30, 2, ',', '') ?>%</span>
                            <span class="season-kpi-unit">еталон року</span>
                        </div>
                        <div class="season-kpi-subtext">
                            Вер–Жов 2025: <strong>43,94%</strong> &bull; Сезон 2026: <strong><?= number_format($seasonTot['oil_yield_pct'] ?? 43.77, 2, ',', '') ?>%</strong>
                        </div>
                        <div class="season-progress-track">
                            <div class="season-progress-fill" style="width: 44.3%; background: #f59e0b;"></div>
                        </div>
                    </div>

                    <!-- 3. Вихід Шроту -->
                    <div class="season-kpi-card card-accent-meal" title="Вихід шроту 2025: Вер 42.14%, Жов 41.76%, Рік 41.67% (25 790.4 т)">
                        <div class="season-kpi-header">
                            <span class="season-kpi-title" style="color: #1d4ed8;">🌾 Вихід Шроту</span>
                            <span class="season-kpi-badge" style="background: #dbeafe; color: #1e40af;">25,8 тис. т</span>
                        </div>
                        <div class="season-kpi-number-wrap">
                            <span class="season-kpi-number" style="color: #2563eb;"><?= number_format($full2025['meal_pct'] ?? 41.67, 2, ',', '') ?>%</span>
                            <span class="season-kpi-unit">еталон року</span>
                        </div>
                        <div class="season-kpi-subtext">
                            Вер–Жов 2025: <strong>41,84%</strong> &bull; Сезон 2026: <strong><?= number_format($seasonTot['meal_yield_pct'] ?? 41.74, 2, ',', '') ?>%</strong>
                        </div>
                        <div class="season-progress-track">
                            <div class="season-progress-fill" style="width: 41.7%; background: #2563eb;"></div>
                        </div>
                    </div>

                    <!-- 4. Сума Олія + Шрот -->
                    <div class="season-kpi-card card-accent-sum" title="Сума олія+шрот: 85.97% за рік (85.78% за Вер-Жов 2025; 85.51% за Сезон 2026). Загальний вихід готової продукції з лузгою 98.85%">
                        <div class="season-kpi-header">
                            <span class="season-kpi-title" style="color: #6d28d9;">⚖️ Олія + Шрот</span>
                            <span class="season-kpi-badge" style="background: #ede9fe; color: #6d28d9;">Сезон 26: 85,51%</span>
                        </div>
                        <div class="season-kpi-number-wrap">
                            <span class="season-kpi-number" style="color: #7c3aed;"><?= number_format($full2025['oil_meal_pct'] ?? 85.97, 2, ',', '') ?>%</span>
                            <span class="season-kpi-unit">еталон року</span>
                        </div>
                        <div class="season-kpi-subtext">
                            Втрати виробництва 2025: <strong>1,15%</strong> &bull; Сезон 2026: <strong>85,51% (олія+шрот)</strong>
                        </div>
                        <div class="season-progress-track">
                            <div class="season-progress-fill" style="width: 86.0%; background: #8b5cf6;"></div>
                        </div>
                    </div>

                </div>

                <!-- ДЕТАЛЬНА ПОРІВНЯЛЬНА ТАБЛИЦЯ ВИРОБНИЦТВА -->
                <div class="seasonal-comparison-table-wrap" style="margin-top: 14px;">
                    <table class="data-table-clean">
                        <thead>
                            <tr>
                                <th>Показник виробництва</th>
                                <th>Сезон 2026 (72 дн.)</th>
                                <th>Сезон 2025 (Вер–Жов)</th>
                                <th>Рік 2025–26 (61,9 тис. т)</th>
                                <th>Динаміка</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Завезено насіння (авто/елеватор)</td>
                                <td class="bold">25 353,9 т</td>
                                <td>21 329,0 т</td>
                                <td>62 403,3 т</td>
                                <td><span class="trend-badge trend-good">+18.9% завіз</span></td>
                            </tr>
                            <tr>
                                <td>Перероблено соняшнику (очищене)</td>
                                <td class="bold">12 470,0 т</td>
                                <td>21 117,1 т</td>
                                <td>61 892,9 т</td>
                                <td><span class="trend-badge trend-neutral">Запуск вересень</span></td>
                            </tr>
                            <tr>
                                <td>Середня олійність сировини (вхід у цех)</td>
                                <td class="bold">44,69% (лаб. 49,19%)</td>
                                <td>44,79%</td>
                                <td>45,13%</td>
                                <td><span class="trend-badge trend-good">В межах норми</span></td>
                            </tr>
                            <tr>
                                <td>Вихід олії (т / %)</td>
                                <td class="bold" style="color: #d97706;">5 457,8 т (43,77%)</td>
                                <td>9 279,8 т (43,94%)</td>
                                <td>27 416,9 т (44,30%)</td>
                                <td><span class="trend-badge trend-good">Жовтень 45,15%</span></td>
                            </tr>
                            <tr>
                                <td>Вихід шроту (т / %)</td>
                                <td class="bold" style="color: #2563eb;">5 204,9 т (41,74%)</td>
                                <td>8 835,8 т (41,84%)</td>
                                <td>25 790,4 т (41,67%)</td>
                                <td><span class="trend-badge trend-good">+0,07% до року</span></td>
                            </tr>
                            <tr>
                                <td>Вихід лузги (т / %)</td>
                                <td class="bold">1 816,1 т (14,56%)</td>
                                <td>2 678,6 т (12,68%)</td>
                                <td>7 971,8 т (12,88%)</td>
                                <td><span class="trend-badge trend-neutral">Паливний баланс</span></td>
                            </tr>
                            <tr>
                                <td>Втрати з елеватора (підробка)</td>
                                <td class="bold">148,3 т (1,18%)</td>
                                <td>211,9 т (1,00%)</td>
                                <td>510,4 т (0,82%)</td>
                                <td><span class="trend-badge trend-good">Норма &lt; 2,5%</span></td>
                            </tr>
                            <tr>
                                <td>Сума Олія + Шрот (%)</td>
                                <td class="bold" style="color: #7c3aed;">85,51%</td>
                                <td>85,78%</td>
                                <td>85,97%</td>
                                <td><span class="trend-badge trend-good">Ціль 85–86%</span></td>
                            </tr>
                            <tr>
                                <td>Загальний вихід готової продукції</td>
                                <td class="bold">100,07% (+0,07%) / Жов 102,10% (+2,10%)</td>
                                <td>98,47%</td>
                                <td>98,85%</td>
                                <td><span class="trend-badge trend-good">Вище еталону</span></td>
                            </tr>
                            <tr>
                                <td>Залишок насіння на складі заводу</td>
                                <td class="bold" style="color: #059669;">13 326,4 т</td>
                                <td>211,9 т</td>
                                <td>510,4 т</td>
                                <td><span class="trend-badge trend-good">22,2 доби автономії</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- РОЗГОРТАННЯ ОФІЦІЙНОГО ПОМІСЯЧНОГО ЗВІТУ 2025 (ВСІ 8 МІСЯЦІВ) -->
                <?php if (!empty($months2025)): ?>
                <details class="season-monthly-details">
                    <summary>
                        <span><i data-lucide="table" style="width: 14px; height: 14px; vertical-align: middle; margin-right: 4px;"></i> Офіційний помісячний звіт сезону 2025–2026 рр. (Вересень — Червень, 8 місяців)</span>
                        <span style="font-size: 11px; color: #64748b;">Натисніть для перегляду</span>
                    </summary>
                    <div class="season-monthly-table-wrap">
                        <table class="data-table-clean" style="font-size: 11px;">
                            <thead>
                                <tr>
                                    <th>Місяць</th>
                                    <th>Надійшло (т)</th>
                                    <th>Перероблено (т)</th>
                                    <th>Олійність</th>
                                    <th>Олія (т / %)</th>
                                    <th>Шрот (т / %)</th>
                                    <th>Лузга (т / %)</th>
                                    <th>Втрати (%)</th>
                                    <th>Олія+Шрот</th>
                                    <th>Вихід</th>
                                    <th>Залишок (т)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($months2025 as $m): ?>
                                <tr>
                                    <td class="bold"><?= htmlspecialchars($m['name']) ?></td>
                                    <td><?= number_format($m['seed_in'], 1, ',', ' ') ?></td>
                                    <td class="bold"><?= number_format($m['seed_processed'], 1, ',', ' ') ?></td>
                                    <td><?= number_format($m['oil_content_pct'], 2, ',', '') ?>%</td>
                                    <td style="color: #b45309;"><?= number_format($m['oil_out'], 1, ',', ' ') ?> (<?= number_format($m['oil_pct'], 2, ',', '') ?>%)</td>
                                    <td style="color: #1d4ed8;"><?= number_format($m['meal_out'], 1, ',', ' ') ?> (<?= number_format($m['meal_pct'], 2, ',', '') ?>%)</td>
                                    <td><?= number_format($m['husk_out'], 1, ',', ' ') ?> (<?= number_format($m['husk_pct'], 2, ',', '') ?>%)</td>
                                    <td><?= number_format($m['losses_pct'], 2, ',', '') ?>%</td>
                                    <td class="bold" style="color: #7c3aed;"><?= number_format($m['oil_meal_pct'], 2, ',', '') ?>%</td>
                                    <td class="bold"><?= number_format($m['total_yield_pct'], 2, ',', '') ?>%</td>
                                    <td><?= number_format($m['seed_stock_end'], 1, ',', ' ') ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <tr style="background: #f1f5f9; font-weight: 700;">
                                    <td>ИТОГО 2025–26</td>
                                    <td>62 403,3</td>
                                    <td>61 892,9</td>
                                    <td>45,13%</td>
                                    <td style="color: #b45309;">27 416,9 (44,30%)</td>
                                    <td style="color: #1d4ed8;">25 790,4 (41,67%)</td>
                                    <td>7 971,8 (12,88%)</td>
                                    <td>1,15%</td>
                                    <td style="color: #7c3aed;">85,97%</td>
                                    <td>98,85%</td>
                                    <td>510,4</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </details>
                <?php endif; ?>

                <div class="seasonal-note-box" style="margin-top: 12px;">
                    <i data-lucide="info" style="width: 14px; height: 14px; color: var(--color-primary); flex-shrink: 0; margin-top: 2px;"></i>
                    <span>
                        <strong>Еталонний звіт сезону 2025:</strong> за повний річний цикл перероблено 61 892,9 т при вхідній олійності 45.13%. 
                        Вихід олії склав 44.30%, шроту 41.67%, сума основних продуктів 85.97%, а загальний вихід готової продукції досяг 98.85% (технологічні втрати лише 1.15%).
                    </span>
                </div>
            </div>

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
                            <div class="guide-item-desc">Містить фактичну переробку насіння за зміну/добу, вихід олії, шроту, лузги та технологічні втрати.</div>
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
