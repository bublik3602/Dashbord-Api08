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
$config = get_config();

$hour = (int)date('H');
$shiftName = ($hour >= 8 && $hour < 20) ? 'Дневная смена (08:00 — 20:00)' : 'Ночная смена (20:00 — 08:00)';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($config['app_name'] ?? 'Дашборд завода') ?></title>
    
    <!-- Стили дашборда -->
    <link rel="stylesheet" href="assets/css/dashboard.css">
    
    <!-- Библиотека графиков ApexCharts (CDN) -->
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    
    <!-- Иконки Feather/Lucide -->
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body>

<div class="dashboard-container">
    
    <!-- =============================================================
         ШАПКА: НАЗВАНИЕ, СМЕНА, ФИЛЬТР ПЕРИОДОВ, ТАЙМЕР
         ============================================================= -->
    <header class="dashboard-header">
        <div class="brand-section">
            <div class="brand-icon">
                <i data-lucide="factory"></i>
            </div>
            <div>
                <h1 class="brand-title"><?= htmlspecialchars($config['app_name'] ?? 'Маслоэкстракционный завод') ?></h1>
                <div class="brand-subtitle">
                    <span class="live-pill"><span class="pulse-dot"></span> LIVE</span>
                    <span>&bull;</span>
                    <span><?= $shiftName ?></span>
                    <span>&bull;</span>
                    <span>Обновлено: <?= date('H:i:s') ?></span>
                </div>
            </div>
        </div>

        <div class="controls-section">
            <!-- Переключатель периодов -->
            <div class="period-switcher">
                <button type="button" class="period-btn" data-period="shift">Смена</button>
                <button type="button" class="period-btn active" data-period="day">Сутки</button>
                <button type="button" class="period-btn" data-period="week">Неделя</button>
                <button type="button" class="period-btn" data-period="month">Месяц</button>
            </div>

            <!-- Ручное обновление и обратный отсчет -->
            <div class="refresh-control">
                <button type="button" class="btn-refresh" id="btn-manual-refresh" title="Обновить сейчас">
                    <i data-lucide="refresh-cw" class="refresh-icon" style="width: 14px; height: 14px;"></i>
                    <span>Обновить</span>
                </button>
                <span class="refresh-timer" id="refresh-timer-count" title="Следующее автообновление через">30с</span>
            </div>
        </div>
    </header>

    <!-- =============================================================
         БЛОК А: ПРОИЗВОДСТВО И МАТЕРИАЛЬНЫЙ БАЛАНС (ВЕРХНИЙ РЯД KPI)
         ============================================================= -->
    <div class="section-title">
        <i data-lucide="layers" style="width: 18px; height: 18px; color: var(--color-primary);"></i>
        <span>Блок А: Производство и материальный баланс</span>
        <span class="section-badge badge-blue">Контроль сырья и потерь</span>
    </div>

    <div class="kpi-grid">
        <!-- 1. Зона приемки: Семечка на завод -->
        <div class="kpi-card accent-blue">
            <div class="kpi-zone-tag">Зона приёмки</div>
            <div class="kpi-title">Семечка на завод (вход)</div>
            <div class="kpi-value-row">
                <div class="kpi-main-number" id="val-seed-in-plant">
                    <?= number_format($mb['receiving_zone']['seed_in_plant'], 1, ',', ' ') ?><span class="kpi-unit">т</span>
                </div>
                <span class="kpi-badge badge-blue">Автовесы</span>
            </div>
            <div class="kpi-subtext">
                <span id="val-seed-plan">План: <?= number_format($mb['receiving_zone']['seed_plan'], 1, ',', ' ') ?> т (<?= $mb['receiving_zone']['plan_fulfillment_pct'] ?>%)</span>
                <span>Факт приемки</span>
            </div>
        </div>

        <!-- 2. Зона приемки: Потери подработки (усушка/сор) -->
        <div class="kpi-card accent-amber">
            <div class="kpi-zone-tag">Зона приёмки</div>
            <div class="kpi-title">Потери при подработке (усушка / сор)</div>
            <div class="kpi-value-row">
                <div class="kpi-main-number" id="val-cond-loss">
                    <?= number_format($mb['receiving_zone']['conditioning_loss'], 1, ',', ' ') ?><span class="kpi-unit">т</span>
                </div>
                <span class="kpi-badge <?= $mb['receiving_zone']['status_class'] ?>" id="badge-cond-loss">
                    <?= $mb['receiving_zone']['conditioning_loss_pct'] ?>%
                </span>
            </div>
            <div class="kpi-subtext">
                <span>Семечка на завод &minus; в производство</span>
                <span>Норма: &lt; 2.5%</span>
            </div>
        </div>

        <!-- 3. Зона производства: Подача в переработку -->
        <div class="kpi-card accent-emerald">
            <div class="kpi-zone-tag">Зона производства</div>
            <div class="kpi-title">Семечка в переработку (очищенная)</div>
            <div class="kpi-value-row">
                <div class="kpi-main-number" id="val-seed-in-prod">
                    <?= number_format($mb['production_zone']['seed_processed'], 1, ',', ' ') ?><span class="kpi-unit">т</span>
                </div>
                <span class="kpi-badge badge-green">После сушилки</span>
            </div>
            <div class="kpi-subtext">
                <span>Подача в рушально-веечный цех</span>
                <span>Влажность 7.2%</span>
            </div>
        </div>

        <!-- 4. Зона производства: Производственные потери -->
        <div class="kpi-card accent-purple">
            <div class="kpi-zone-tag">Зона производства</div>
            <div class="kpi-title">Производственные потери переработки</div>
            <div class="kpi-value-row">
                <div class="kpi-main-number" id="val-prod-loss">
                    <?= number_format($mb['production_zone']['production_loss'], 1, ',', ' ') ?><span class="kpi-unit">т</span>
                </div>
                <span class="kpi-badge <?= $mb['production_zone']['status_class'] ?>" id="badge-prod-loss">
                    <?= $mb['production_zone']['production_loss_pct'] ?>%
                </span>
            </div>
            <div class="kpi-subtext">
                <span>Сырье &minus; (Масло + Шрот + Лузга)</span>
                <span>Норма: &lt; 1.2%</span>
            </div>
        </div>
    </div>

    <!-- =============================================================
         СРЕДНИЙ РЯД: ВЫХОД ПРОДУКЦИИ (DONUT) + ЛОГИСТИКА CRM
         ============================================================= -->
    <div class="middle-grid">
        
        <!-- Круговая диаграмма выхода готовой продукции -->
        <div class="card-panel">
            <div class="section-title">
                <i data-lucide="pie-chart" style="width: 18px; height: 18px; color: var(--color-oil);"></i>
                <span>Выход готовой продукции</span>
                <span class="section-badge badge-orange">Материальный баланс</span>
            </div>

            <div class="chart-layout">
                <div id="production-donut-chart"></div>

                <div class="chart-legend">
                    <div class="legend-item">
                        <span class="legend-name">
                            <span class="legend-color-tag" style="background: var(--color-oil);"></span>
                            Масло сырое
                        </span>
                        <div>
                            <span class="legend-values" id="legend-oil-val"><?= number_format($mb['production_zone']['oil_out'], 1, ',', ' ') ?> т</span>
                            <span class="legend-pct" id="legend-oil-pct"><?= $mb['yield_chart']['percentages'][0] ?>%</span>
                        </div>
                    </div>

                    <div class="legend-item">
                        <span class="legend-name">
                            <span class="legend-color-tag" style="background: var(--color-meal);"></span>
                            Шрот тостированный
                        </span>
                        <div>
                            <span class="legend-values" id="legend-meal-val"><?= number_format($mb['production_zone']['meal_out'], 1, ',', ' ') ?> т</span>
                            <span class="legend-pct" id="legend-meal-pct"><?= $mb['yield_chart']['percentages'][1] ?>%</span>
                        </div>
                    </div>

                    <div class="legend-item">
                        <span class="legend-name">
                            <span class="legend-color-tag" style="background: var(--color-husk);"></span>
                            Лузга гранулированная
                        </span>
                        <div>
                            <span class="legend-values" id="legend-husk-val"><?= number_format($mb['production_zone']['husk_out'], 1, ',', ' ') ?> т</span>
                            <span class="legend-pct" id="legend-husk-pct"><?= $mb['yield_chart']['percentages'][2] ?>%</span>
                        </div>
                    </div>

                    <div class="legend-item">
                        <span class="legend-name">
                            <span class="legend-color-tag" style="background: var(--color-loss);"></span>
                            Технологические потери
                        </span>
                        <div>
                            <span class="legend-values" id="legend-loss-val"><?= number_format($mb['production_zone']['production_loss'], 1, ',', ' ') ?> т</span>
                            <span class="legend-pct" id="legend-loss-pct"><?= $mb['yield_chart']['percentages'][3] ?>%</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Блок Б: Логистика (Электронная очередь CRM - Только чтение) -->
        <div class="card-panel">
            <div class="section-title" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <i data-lucide="truck" style="width: 18px; height: 18px; color: #0284c7;"></i>
                    <span>Блок Б: Логистика транспорта</span>
                    <span class="section-badge badge-blue">БД: apgk_api08 (Read-Only)</span>
                </div>
                <!-- Фильтр по потокам груза -->
                <div class="cargo-tabs" id="cargo-tabs-container">
                    <button type="button" class="cargo-tab-btn active" data-cargo="Все">Все</button>
                    <button type="button" class="cargo-tab-btn" data-cargo="Семена">🌻 Семена</button>
                    <button type="button" class="cargo-tab-btn" data-cargo="Масло">🛢️ Масло</button>
                    <button type="button" class="cargo-tab-btn" data-cargo="Шрот">🌾 Шрот</button>
                    <button type="button" class="cargo-tab-btn" data-cargo="Лузга">🪵 Лузга</button>
                </div>
            </div>

            <!-- Время нахождения (въезд -> выезд) раздельно по 4 культурам -->
            <div class="cargo-stay-grid">
                <div class="cargo-stay-card">
                    <span class="cargo-name">🌻 Семена</span>
                    <span class="cargo-stay-time" id="val-stay-seed"><?= $log['categories']['Семена']['avg_stay_text'] ?? '1 ч 30 мин' ?></span>
                    <span class="cargo-truck-count" id="val-cnt-seed">На заводе: <?= $log['categories']['Семена']['on_site'] ?? 0 ?></span>
                </div>
                <div class="cargo-stay-card">
                    <span class="cargo-name">💧 Масло</span>
                    <span class="cargo-stay-time" id="val-stay-oil"><?= $log['categories']['Масло']['avg_stay_text'] ?? '1 ч 15 мин' ?></span>
                    <span class="cargo-truck-count" id="val-cnt-oil">На заводе: <?= $log['categories']['Масло']['on_site'] ?? 0 ?></span>
                </div>
                <div class="cargo-stay-card">
                    <span class="cargo-name">🌾 Шрот</span>
                    <span class="cargo-stay-time" id="val-stay-meal"><?= $log['categories']['Шрот']['avg_stay_text'] ?? '1 ч 30 мин' ?></span>
                    <span class="cargo-truck-count" id="val-cnt-meal">На заводе: <?= $log['categories']['Шрот']['on_site'] ?? 0 ?></span>
                </div>
                <div class="cargo-stay-card">
                    <span class="cargo-name">🪵 Лузга</span>
                    <span class="cargo-stay-time" id="val-stay-husk"><?= $log['categories']['Лузга']['avg_stay_text'] ?? '50 мин' ?></span>
                    <span class="cargo-truck-count" id="val-cnt-husk">На заводе: <?= $log['categories']['Лузга']['on_site'] ?? 0 ?></span>
                </div>
            </div>

            <!-- Воронка движения автотранспорта -->
            <div class="funnel-container">
                <div class="funnel-step">
                    <span class="funnel-icon">📝</span>
                    <div class="funnel-count" id="funnel-queued-count"><?= $log['queued'] ?></div>
                    <div class="funnel-label">1. Записано</div>
                </div>

                <div class="funnel-step active">
                    <span class="funnel-icon">🚚</span>
                    <div class="funnel-count" id="funnel-entered-count"><?= $log['entered'] ?></div>
                    <div class="funnel-label">2. Заехало</div>
                </div>

                <div class="funnel-step active">
                    <span class="funnel-icon">⚖️</span>
                    <div class="funnel-count" id="funnel-weighing-count"><?= $log['weighing'] ?></div>
                    <div class="funnel-label">3. На весах</div>
                </div>

                <div class="funnel-step">
                    <span class="funnel-icon">🏁</span>
                    <div class="funnel-count" id="funnel-exited-count"><?= $log['exited'] ?></div>
                    <div class="funnel-label">4. Выехало</div>
                </div>
            </div>

            <!-- Метрики эффективности автотранспорта -->
            <div class="logistics-stats-row">
                <div class="stat-left">
                    <div class="stat-icon-wrap" style="color: #0284c7;">
                        <i data-lucide="clock" style="width: 20px; height: 20px;"></i>
                    </div>
                    <div>
                        <div class="stat-title">Среднее время на заводе (<span id="active-cargo-label">Все</span>)</div>
                        <div class="stat-value" id="val-avg-stay-time"><?= $log['avg_stay_text'] ?? format_duration_minutes($log['avg_stay_minutes']) ?></div>
                    </div>
                </div>
                <div>
                    <span class="kpi-badge status-normal" id="badge-stay-norm">Норма (до 2 ч)</span>
                </div>
            </div>

            <div class="logistics-stats-row" style="margin-top: 8px;">
                <div class="stat-left">
                    <div class="stat-icon-wrap" style="color: #10b981;">
                        <i data-lucide="map-pin" style="width: 20px; height: 20px;"></i>
                    </div>
                    <div>
                        <div class="stat-title">Транспорта в обработке (<span id="active-cargo-count-label">Все</span>)</div>
                        <div class="stat-value" id="val-total-active-trucks"><?= $log['total_active'] ?> машин</div>
                    </div>
                </div>
                <div>
                    <span class="kpi-badge badge-blue" id="val-on-territory">На территории: <?= $log['entered'] + $log['weighing'] ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- =============================================================
         БЛОК В: СОСТОЯНИЕ УЗЛОВ И ЭНЕРГОРЕСУРСЫ (ZABBIX API)
         ============================================================= -->
    <div class="bottom-grid">
        
        <!-- График энергетики и воды -->
        <div class="card-panel">
            <div class="section-title">
                <i data-lucide="zap" style="width: 18px; height: 18px; color: #f59e0b;"></i>
                <span>Блок В: Энергоресурсы завода (Zabbix API)</span>
                <span class="section-badge badge-green">Энергоэффективность</span>
            </div>

            <!-- Микро-карточки расхода -->
            <div class="energy-summary-cards">
                <div class="energy-card-mini">
                    <div class="mini-title">Текущая мощность ввода</div>
                    <div class="mini-number" id="val-current-power"><?= number_format($en['current_power_kw'], 0, ',', ' ') ?> кВт</div>
                </div>
                <div class="energy-card-mini">
                    <div class="mini-title">Расход воды (мгновенный)</div>
                    <div class="mini-number" id="val-water-flow"><?= number_format($en['current_water_m3_hour'], 1, ',', ' ') ?> м³/ч</div>
                </div>
                <div class="energy-card-mini">
                    <div class="mini-title">Удельный расход на тонну</div>
                    <div class="mini-number" id="val-specific-kwh"><?= $en['specific_kwh_per_tonne'] ?> кВт*ч/т</div>
                </div>
            </div>

            <!-- Интерактивный таймлайн-график -->
            <div id="energy-timeline-chart"></div>
        </div>

        <!-- Панель статуса оборудования -->
        <div class="card-panel">
            <div class="section-title">
                <i data-lucide="activity" style="width: 18px; height: 18px; color: #10b981;"></i>
                <span>Состояние узлов завода</span>
                <span class="section-badge badge-blue" id="equip-health-summary"><?= $eq['running_count'] ?>/<?= $eq['total_count'] ?> в работе</span>
            </div>

            <div class="equipment-list">
                <?php foreach ($eq['nodes'] as $node): ?>
                    <div class="equip-item">
                        <div class="equip-info">
                            <div class="equip-status-light <?= $node['status'] === 'running' ? 'light-online' : 'light-offline' ?>"></div>
                            <div>
                                <div class="equip-name"><?= htmlspecialchars($node['name']) ?></div>
                                <div class="equip-section"><?= htmlspecialchars($node['section']) ?></div>
                            </div>
                        </div>
                        <div>
                            <span class="equip-badge <?= $node['status'] === 'running' ? 'status-normal' : 'status-danger' ?>">
                                <?= $node['status'] === 'running' ? 'РАБОТАЕТ' : 'СТОП' ?>
                            </span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

    </div>

    <!-- =============================================================
         ПОДВАЛ
         ============================================================= -->
    <footer class="dashboard-footer">
        <div>
            &copy; <?= date('Y') ?> Внутренний портал управления производством &bull; Hestia CP Server
        </div>
        <div class="footer-tags">
            <span class="footer-pill">Zabbix API: <?= $config['zabbix']['enabled'] ? 'Активен' : 'Демо/Синхронизация' ?></span>
            <span class="footer-pill">CRM SQL: <?= $config['crm_db']['enabled'] ? 'Прямой' : 'Локальная БД' ?></span>
            <span class="footer-pill">PHP <?= PHP_VERSION ?></span>
        </div>
    </footer>

</div>

<!-- Контейнер для всплывающих уведомлений (Toast) -->
<div id="toast-container" class="toast-container"></div>

<!-- Передача начальных серверных данных в клиентский JS -->
<script>
    window.INITIAL_DASHBOARD_DATA = <?= json_encode($dashboardData, JSON_UNESCAPED_UNICODE) ?>;
</script>

<script src="assets/js/charts.js"></script>
<script src="assets/js/dashboard.js"></script>
<script>
    // Рендер иконок Lucide
    lucide.createIcons();
</script>

</body>
</html>
