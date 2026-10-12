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
$shiftName = ($hour >= 8 && $hour < 20) ? 'Денна зміна (08:00 — 20:00)' : 'Нічна зміна (20:00 — 08:00)';
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($config['app_name'] ?? 'Олійноекстракційний завод — Єдиний Моніторинг') ?></title>
    
    <!-- Стилі дашборда -->
    <link rel="stylesheet" href="assets/css/dashboard.css">
    
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
         БЛОК А: ПРОИЗВОДСТВО И МАТЕРИАЛЬНЫЙ БАЛАНС (ВЕРХНИЙ РЯД KPI)
         ============================================================= -->
    <div class="section-title">
        <i data-lucide="layers" style="width: 18px; height: 18px; color: var(--color-primary);"></i>
        <span>Блок А: Виробництво та матеріальний баланс</span>
        <span class="section-badge badge-blue">Контроль сировини та втрат</span>
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

        <!-- 2. Зона приймання: Втрати підробки (усушка/сміття) -->
        <div class="kpi-card accent-amber">
            <div class="kpi-zone-tag">Зона приймання</div>
            <div class="kpi-title">Втрати підробки (усушка / сміття)</div>
            <div class="kpi-value-row">
                <div class="kpi-main-number" id="val-cond-loss">
                    <?= number_format($mb['receiving_zone']['conditioning_loss'], 1, ',', ' ') ?><span class="kpi-unit">т</span>
                </div>
                <span class="kpi-badge <?= $mb['receiving_zone']['status_class'] ?>" id="badge-cond-loss">
                    <?= $mb['receiving_zone']['conditioning_loss_pct'] ?>%
                </span>
            </div>
            <div class="kpi-subtext">
                <span>Насіння на завод &minus; у переробку</span>
                <span>Норма: &lt; 2.5%</span>
            </div>
        </div>

        <!-- 3. Зона виробництва: Подача в переробку -->
        <div class="kpi-card accent-emerald">
            <div class="kpi-zone-tag">Зона виробництва</div>
            <div class="kpi-title">Насіння у переробку (очищене)</div>
            <div class="kpi-value-row">
                <div class="kpi-main-number" id="val-seed-in-prod">
                    <?= number_format($mb['production_zone']['seed_processed'], 1, ',', ' ') ?><span class="kpi-unit">т</span>
                </div>
                <span class="kpi-badge badge-green">Після сушарки</span>
            </div>
            <div class="kpi-subtext">
                <span>Подача в рушально-віяльний цех</span>
                <span>Вологість 7.2%</span>
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
                    <span class="cargo-truck-count" id="val-cnt-seed">Зараз на заводі: <?= $log['categories']['Насіння']['on_site'] ?? $log['categories']['Семена']['on_site'] ?? 0 ?> авто</span>
                </div>
                <div class="cargo-stay-card">
                    <span class="cargo-name">💧 Олія</span>
                    <span class="cargo-stay-time">Сер. час: <strong id="val-stay-oil"><?= $log['categories']['Олія']['avg_stay_text'] ?? $log['categories']['Масло']['avg_stay_text'] ?? '1 год 15 хв' ?></strong></span>
                    <span class="cargo-truck-count" id="val-cnt-oil">Зараз на заводі: <?= $log['categories']['Олія']['on_site'] ?? $log['categories']['Масло']['on_site'] ?? 0 ?> авто</span>
                </div>
                <div class="cargo-stay-card">
                    <span class="cargo-name">🌾 Шрот</span>
                    <span class="cargo-stay-time">Сер. час: <strong id="val-stay-meal"><?= $log['categories']['Шрот']['avg_stay_text'] ?? '1 год 30 хв' ?></strong></span>
                    <span class="cargo-truck-count" id="val-cnt-meal">Зараз на заводі: <?= $log['categories']['Шрот']['on_site'] ?? 0 ?> авто</span>
                </div>
                <div class="cargo-stay-card">
                    <span class="cargo-name">🪵 Лузга</span>
                    <span class="cargo-stay-time">Сер. час: <strong id="val-stay-husk"><?= $log['categories']['Лузга']['avg_stay_text'] ?? '45 хв' ?></strong></span>
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

        <!-- Панель статусу обладнання -->
        <div class="card-panel">
            <div class="section-title">
                <i data-lucide="activity" style="width: 18px; height: 18px; color: #10b981;"></i>
                <span>Стан вузлів заводу</span>
                <span class="section-badge badge-blue" id="equip-health-summary"><?= $eq['running_count'] ?>/<?= $eq['total_count'] ?> у роботі</span>
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
                                <?= $node['status'] === 'running' ? 'У РОБОТІ' : 'СТОП' ?>
                            </span>
                        </div>
                    </div>
                <?php endforeach; ?>
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
