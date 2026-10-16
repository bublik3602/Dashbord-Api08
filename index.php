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
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/metrics_calculator.php';

// Обов'язкова авторизація: головний дашборд доступний тільки для дозволених ролей (admin)
// Користувач 'semena' автоматично перенаправляється на semena.php
$currentUser = Auth::requireAuth('dashboard');
$isEnergo = Auth::isEnergo();

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
$prodLab = $dashboardData['production_lab_quality'] ?? [];
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
            <div class="brand-icon" style="<?= $isEnergo ? 'background: linear-gradient(135deg, #fef3c7, #fde68a); border-color: #f59e0b;' : '' ?>">
                <i data-lucide="<?= $isEnergo ? 'zap' : 'factory' ?>" style="<?= $isEnergo ? 'color: #d97706;' : '' ?>"></i>
            </div>
            <div>
                <h1 class="brand-title">
                    <?= $isEnergo ? 'Олійноекстракційний завод — Енергоресурси (Блок В)' : htmlspecialchars($config['app_name'] ?? 'Олійноекстракційний завод — Єдиний Моніторинг') ?>
                </h1>
                <div class="brand-subtitle">
                    <span class="live-pill"><span class="pulse-dot"></span> LIVE</span>
                    <span>&bull;</span>
                    <span id="header-sync-time">Оновлено: <?= date('H:i:s') ?></span>
                    <?php if ($isEnergo): ?>
                        <span>&bull;</span>
                        <span style="color: #d97706; font-weight: 700;">Служба енергетики та технологічної пари</span>
                    <?php endif; ?>
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
                        <option value="" disabled selected>📅 Інші періоди / місяці...</option>
                        <option value="day_active">⚡ Остання доба переробки (<?= htmlspecialchars(substr($prodLab['report_date'] ?? '14.10', 0, 5)) ?> — <?= number_format($prodLab['seed_processed_tonnes'] ?? 596.8, 1, ',', ' ') ?> т перероблено)</option>
                        <option value="month_oct">Жовтень 2026 (працював <?= $mOct['working_days'] ?? 9 ?> діб з <?= $mOct['days_count'] ?? 14 ?> • <?= number_format($mOct['seed_processed'] ?? 4764.15, 1, ',', ' ') ?> т)</option>
                        <option value="month_sep">Вересень 2026 (працював 16 діб з 30 • 8 823,7 т)</option>
                        <option value="month_aug">Серпень 2026 (0 діб переробки, ППР)</option>
                        <option value="season">Сезон 2026–2027 (<?= $seasonTot['working_days'] ?? 25 ?> діб переробки • <?= number_format($seasonTot['seed_processed'] ?? 13587.81, 1, ',', ' ') ?> т)</option>
                        <option value="day_prev">Минула доба (13.10 — перероблено 521,0 т)</option>
                        <option value="day_today">Сьогодні (поточний оперативний стан)</option>
                    </select>
                </div>
            </div>

            <!-- Дії: Вкладка СЕМЕНА, Імпорт та Експорт звітів -->
            <div class="action-buttons-group">
                <?php if (!$isEnergo): ?>
                <a href="semena.php" class="btn-action" id="btn-open-semena" title="Відкрити детальний звіт з постачання соняшнику (Лист погодження, Невиконані, Графік, Реєстр прийомок)" style="background: linear-gradient(135deg, #f59e0b, #d97706); color: #ffffff; border: 1px solid #b45309; font-weight: 800; box-shadow: 0 2px 8px rgba(245, 158, 11, 0.35); text-decoration: none;">
                    <span>🌻 СЕМЕНА</span>
                </a>
                <?php endif; ?>
                <button type="button" class="btn-action btn-import" id="btn-open-import" title="<?= $isEnergo ? 'Імпортувати звіти енергетики (РАСПРЕД.ЭНЕРГОНОСИТЕЛЕЙ, АГРОПРОІНВЕСТ 08, Облік води, Учет пара)' : 'Імпортувати щоденні звіти завода (Лист погодження, СВОД, Баланс)' ?>">
                    <i data-lucide="upload" style="width: 14px; height: 14px;"></i>
                    <span>Імпорт Excel</span>
                </button>
                <?php if (!$isEnergo): ?>
                <a href="api/export.php" class="btn-action btn-export" id="btn-export-excel" title="Завантажити звіт за зміну / добу в Excel">
                    <i data-lucide="download" style="width: 14px; height: 14px;"></i>
                    <span>Експорт в Excel</span>
                </a>
                <?php endif; ?>
            </div>

            <!-- Ручне оновлення та зворотний відлік -->
            <div class="refresh-control">
                <button type="button" class="btn-refresh" id="btn-manual-refresh" title="Оновити зараз">
                    <i data-lucide="refresh-cw" class="refresh-icon" style="width: 14px; height: 14px;"></i>
                    <span>Оновити</span>
                </button>
                <span class="refresh-timer" id="refresh-timer-count" title="Наступне автооновлення через">30с</span>
            </div>

            <!-- Користувач та вихід із системи -->
            <div class="user-session-control" style="display: flex; align-items: center; gap: 6px; margin-left: 6px;">
                <span class="btn-action" style="<?= $isEnergo ? 'background: #fef3c7; border: 1px solid #fcd34d; color: #b45309;' : 'background: rgba(31, 78, 120, 0.08); border: 1px solid rgba(31, 78, 120, 0.2); color: #1e40af;' ?> cursor: default; font-weight: 700; padding: 6px 10px; font-size: 13px;" title="<?= htmlspecialchars($currentUser['display_name'] ?? '') ?>">
                    👤 <?= htmlspecialchars($currentUser['username'] ?? 'admin') ?> <?= $isEnergo ? '(Енергетика)' : '' ?>
                </span>
                <a href="logout.php" class="btn-action" style="background: #fee2e2; border: 1px solid #fca5a5; color: #991b1b; text-decoration: none; padding: 6px 10px; font-size: 13px; font-weight: 700;" title="Вийти з системи">
                    🚪 Вийти
                </a>
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
    $labDate = $lab['report_date'] ?? '11.10.2026 (Лист погодження — по днях)';
    $prodLabDate = $prodLab['report_date'] ?? '13.10.2026 (Звіт виробництва)';
    $stocksDate = $stocks['report_date'] ?? 'Ежедн.отчет2 (14 число)';
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
                    <span id="banner-period-sub">Розрахунок доби: <strong>з 08:00 до 08:00</strong> наступного дня</span>
                    <?php if ($isEnergo): ?>
                        <span class="banner-separator">&bull;</span>
                        <span>Облік енергоносіїв заводу: <strong style="color: #0284c7;">Електроенергія</strong> &bull; <strong style="color: #059669;">Водопостачання</strong> &bull; <strong style="color: #d97706;">Технологічна пара</strong> &bull; <strong style="color: #7c3aed;">Стоки</strong></span>
                    <?php else: ?>
                        <span class="banner-separator">&bull;</span>
                        <span>Прихід насіння 1С: <strong id="banner-lab-time"><?= htmlspecialchars($labDate) ?></strong></span>
                        <span class="banner-separator">&bull;</span>
                        <span>Переробка МЕЗ: <strong id="banner-prod-lab-time"><?= htmlspecialchars($prodLabDate) ?></strong></span>
                        <span class="banner-separator">&bull;</span>
                        <span>СВОД залишків: <strong id="banner-stocks-time"><?= htmlspecialchars($stocksDate) ?></strong></span>
                    <?php endif; ?>
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

    <?php if (!$isEnergo): ?>
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
                    <?= number_format($mb['receiving_zone']['seed_in_plant'], 2, ',', ' ') ?><span class="kpi-unit">т</span>
                </div>
                <span class="kpi-badge badge-blue">Лист погодження</span>
            </div>
            <div class="kpi-subtext">
                <span id="val-seed-plan">План: <?= number_format($mb['receiving_zone']['seed_plan'], 1, ',', ' ') ?> т (<?= $mb['receiving_zone']['plan_fulfillment_pct'] ?>%)<?php if (!empty($mb['receiving_zone']['trucks_count'])): ?> • <?= $mb['receiving_zone']['trucks_count'] ?> авто<?php endif; ?></span>
                <span>Без очистки та сушки</span>
            </div>
        </div>

        <!-- 2. Склад сировини: Запас насіння та автономність заводу -->
        <div class="kpi-card accent-emerald">
            <div class="kpi-zone-tag">Склад сировини</div>
            <div class="kpi-title">Запас насіння на елеваторі</div>
            <div class="kpi-value-row">
                <div class="kpi-main-number" id="val-seed-stock">
                    <?= number_format($stocks['seed_stock_tonnes'] ?? 12857.66, 2, ',', ' ') ?><span class="kpi-unit">т</span>
                </div>
                <span class="kpi-badge badge-green" id="badge-days-reserve" title="Розраховано від стандарту подачі насіння 600 т/добу (12 857,66 т / 600 т = 21,4 дн.)">
                    <?= ($stocks['days_seed_reserve'] ?? 21.4) ?> дн. (при 600 т/д)
                </span>
            </div>
            <div class="kpi-subtext">
                <span id="val-seed-stock-sub">Факт: <?= number_format($stocks['seed_stock_tonnes'] ?? 12857.66, 2, ',', ' ') ?> т &bull; Облік: <?= number_format($stocks['seed_stock_accounting_tonnes'] ?? 13310.97, 2, ',', ' ') ?> т</span>
                <span>Ежедн.отчет2</span>
            </div>
        </div>

        <!-- 3. Зона виробництва: Подача в переробку -->
        <div class="kpi-card accent-amber">
            <div class="kpi-zone-tag">Зона виробництва</div>
            <div class="kpi-title">Насіння у переробку (очищене)</div>
            <div class="kpi-value-row">
                <div class="kpi-main-number" id="val-seed-in-prod">
                    <?= number_format($mb['production_zone']['seed_processed'], 2, ',', ' ') ?><span class="kpi-unit">т</span>
                </div>
                <span class="kpi-badge <?= $mb['receiving_zone']['status_class'] ?>" id="badge-cond-loss">
                    Підробка: <?= $mb['receiving_zone']['conditioning_loss_pct'] ?>%
                </span>
            </div>
            <div class="kpi-subtext">
                <span id="val-cond-loss">Неочищене: <?= number_format($mb['receiving_zone']['seed_in_production_raw'] ?? 602.122, 2, ',', ' ') ?> т &bull; Підробка: <?= number_format($mb['receiving_zone']['conditioning_loss'], 2, ',', ' ') ?> т</span>
                <span>Справка МЕЗ</span>
            </div>
        </div>

        <!-- 4. Зона виробництва: Виробничі втрати -->
        <div class="kpi-card accent-purple">
            <div class="kpi-zone-tag">Зона виробництва</div>
            <div class="kpi-title">Виробничі втрати переробки</div>
            <div class="kpi-value-row">
                <div class="kpi-main-number" id="val-prod-loss">
                    <?= number_format($mb['production_zone']['production_loss'], 2, ',', ' ') ?><span class="kpi-unit">т</span>
                </div>
                <span class="kpi-badge <?= $mb['production_zone']['status_class'] ?>" id="badge-prod-loss">
                    <?= (($mb['production_zone']['yield_surplus_pct'] ?? 0) > 0) ? ('+' . $mb['production_zone']['yield_surplus_pct'] . '% вихід') : ($mb['production_zone']['production_loss_pct'] . '%') ?>
                </span>
            </div>
            <div class="kpi-subtext">
                <span>Від чистих семян (<?= number_format($mb['production_zone']['seed_processed'], 1, ',', ' ') ?> т)</span>
                <span>Норма: &lt; 1.2%</span>
            </div>
        </div>
    </div>

    <!-- =============================================================
         ЛАБОРАТОРІЯ ТА КОНТРАКТАЦІЯ: ПРИХІД НАСІННЯ (1С)
         ============================================================= -->
    <div class="card-panel quality-gauges-panel">
        <div class="section-title">
            <i data-lucide="scale" style="width: 18px; height: 18px; color: var(--color-primary);"></i>
            <span>Лабораторія якості: Прихід насіння (1С)</span>
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
                    <div style="margin-top: 6px;">
                        <a href="semena.php" style="display: inline-flex; align-items: center; gap: 5px; font-size: 11px; font-weight: 700; color: #b45309; background: #fef3c7; border: 1px solid #fcd34d; padding: 3px 10px; border-radius: 6px; text-decoration: none;">
                            🌻 Відкрити звіт «СЕМЕНА» (всі вкладки) &rarr;
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- =============================================================
         ЛАБОРАТОРІЯ: ПОДАЧА НА ПЕРЕРОБКУ (ТЕХНОЛОГІЧНА ЯКІСТЬ МЕЗ)
         ============================================================= -->
    <div class="card-panel quality-gauges-panel" style="margin-top: 14px;">
        <div class="section-title">
            <i data-lucide="flask-conical" style="width: 18px; height: 18px; color: #0284c7;"></i>
            <span>Лабораторія якості: Подача на переробку (виробництво МЕЗ)</span>
            <span class="section-badge badge-blue" id="lbl-prod-lab-time">Звіт цеху переробки станом на: <?= htmlspecialchars($prodLabDate) ?></span>
        </div>

        <div class="gauges-grid">
            <!-- 1. Графік: Олійність у переробку -->
            <div class="gauge-card">
                <div class="gauge-header">
                    <span class="gauge-title">🧪 Товарна олійність у переробку</span>
                    <span class="gauge-badge badge-bonus" id="badge-prod-oil-asr">за <?= htmlspecialchars($prodLab['oil_date'] ?? '13.10') ?> • <?= number_format($prodLab['oil_content_pct'] ?? 44.49, 2, ',', ' ') ?>%</span>
                </div>
                <div id="chart-gauge-prod-oil" class="gauge-chart-container"></div>
                <div class="gauge-footer">
                    <div class="gauge-sub" id="val-prod-oil-asr">Товарна олійність (за <?= htmlspecialchars($prodLab['oil_date'] ?? '13.10') ?>): <strong><?= number_format($prodLab['oil_content_pct'] ?? 44.49, 2, ',', ' ') ?>%</strong> (а.с.р. <?= number_format($prodLab['oil_asr_pct'] ?? 48.30, 2, ',', ' ') ?>%)</div>
                    <div class="gauge-oil-loss-container" id="val-prod-oil-loss" style="margin-top: 6px; display: flex; flex-direction: column; gap: 4px; width: 100%;">
                        <div style="display: flex; justify-content: space-between; align-items: center; background: #fefce8; border: 1px solid #fef08a; border-radius: 4px; padding: 2px 7px; font-size: 11px;">
                            <span style="color: #854d0e; font-weight: 600;">Виробн. втрати олії:</span>
                            <div>
                                <strong style="color: #713f12;"><?= number_format($prodLab['oil_loss_kg'] ?? 38654, 0, '', ' ') ?> кг</strong>
                                <span class="badge-discount" style="padding: 1px 5px; font-size: 10px; font-weight: 700; border-radius: 4px; margin-left: 4px;"><?= number_format($prodLab['oil_loss_pct'] ?? 0.81, 2, ',', ' ') ?>%</span>
                            </div>
                        </div>
                        <div style="display: flex; justify-content: space-between; align-items: center; background: #fff1f2; border: 1px solid #fecdd3; border-radius: 4px; padding: 2px 7px; font-size: 11px;">
                            <span style="color: #9f1239; font-weight: 600;">Невраховані втрати:</span>
                            <div>
                                <strong style="color: #881337;"><?= number_format($prodLab['oil_unaccounted_loss_kg'] ?? 15094, 0, '', ' ') ?> кг</strong>
                                <span class="badge-discount" style="padding: 1px 5px; font-size: 10px; font-weight: 700; border-radius: 4px; margin-left: 4px;"><?= number_format($prodLab['oil_unaccounted_loss_pct'] ?? 0.32, 2, ',', ' ') ?>%</span>
                            </div>
                        </div>
                    </div>
                    <div class="gauge-note" id="note-prod-oil"><?= htmlspecialchars($prodLab['oil_note'] ?? 'Фактична товарна олійність за 13.10 як у звіті МЕЗ') ?> • Справка по переработке</div>
                </div>
            </div>

            <!-- 2. Графік: Вологість у цех -->
            <div class="gauge-card">
                <div class="gauge-header">
                    <span class="gauge-title">💧 Вологість у цех</span>
                    <span class="gauge-badge <?= ($prodLab['moisture_pct'] ?? 6.0) <= 7.0 ? 'badge-bonus' : 'badge-discount' ?>" id="badge-prod-moisture">
                        <?= ($prodLab['moisture_pct'] ?? 6.0) <= 7.0 ? 'В нормі (' . number_format($prodLab['moisture_pct'] ?? 6.0, 1, ',', ' ') . '%)' : 'Перевищення' ?>
                    </span>
                </div>
                <div id="chart-gauge-prod-moisture" class="gauge-chart-container"></div>
                <div class="gauge-footer">
                    <div class="gauge-sub">Техн. норма: <strong>5.50% — 7.00%</strong></div>
                    <div class="gauge-note">Оптимум для вальців та жаровень</div>
                </div>
            </div>

            <!-- 3. Графік: Смітна домішка після очищення -->
            <div class="gauge-card">
                <div class="gauge-header">
                    <span class="gauge-title">🌾 Смітна домішка (сор після БІС)</span>
                    <span class="gauge-badge <?= ($prodLab['impurity_pct'] ?? 2.0) <= 2.0 ? 'badge-bonus' : 'badge-discount' ?>" id="badge-prod-impurity">
                        <?= ($prodLab['impurity_pct'] ?? 2.0) <= 2.0 ? 'В нормі (≤ 2.0%)' : 'Перевищення' ?>
                    </span>
                </div>
                <div id="chart-gauge-prod-impurity" class="gauge-chart-container"></div>
                <div class="gauge-footer">
                    <div class="gauge-sub">Норма цеху: <strong>до 2.00%</strong></div>
                    <div class="gauge-note">Очищення сепаратором БІС</div>
                </div>
            </div>

            <!-- 4. Графік: Подача насіння в переробку -->
            <div class="gauge-card">
                <div class="gauge-header">
                    <span class="gauge-title">⚙️ Подача у переробку</span>
                    <span class="gauge-badge badge-bonus" id="badge-prod-seed-tonnes"><?= number_format($prodLab['seed_processed_tonnes'] ?? 520.99, 1, ',', ' ') ?> т</span>
                </div>
                <div id="chart-gauge-prod-seed" class="gauge-chart-container"></div>
                <div class="gauge-footer">
                    <div class="gauge-sub">Перероблено: <strong id="val-prod-seed-processed"><?= number_format($prodLab['seed_processed_tonnes'] ?? 520.99, 1, ',', ' ') ?> т</strong> (План: <?= number_format($prodLab['seed_plan_tonnes'] ?? 600.0, 0, ',', ' ') ?> т)</div>
                    <div class="gauge-note" id="val-prod-seed-sub"><?= htmlspecialchars($prodLab['processed_note'] ?? 'Подано у вальцьовий цех та екстракцію') ?></div>
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
            <div class="section-title" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <i data-lucide="pie-chart" style="width: 18px; height: 18px; color: var(--color-oil);"></i>
                    <span>Вихід готової продукції</span>
                    <span class="section-badge badge-orange">Матеріальний баланс</span>
                </div>
                <div id="badge-prod-idle-notice" style="<?= (($mb['production_zone']['seed_in_production'] ?? 0) <= 0) ? 'display: inline-flex;' : 'display: none;' ?> align-items: center; gap: 6px; font-size: 11px; color: #b45309; background: #fef3c7; border: 1px solid #fde68a; border-radius: 6px; padding: 3px 10px; font-weight: 600;">
                    <span>⚠️ За обрану добу переробки не було (0 т). Завод на прийомці насіння.</span>
                    <button type="button" class="btn-quick-period" data-period="month_oct" style="background:#0284c7; color:#fff; border:none; border-radius:4px; padding:2px 7px; cursor:pointer; font-size:10px; font-weight:700;">Жовтень (3 646 т) &rarr;</button>
                </div>
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

            <div id="prod-idle-hint" style="<?= (($mb['production_zone']['seed_in_production'] ?? 0) <= 0) ? 'display: flex;' : 'display: none;' ?> align-items: center; justify-content: center; gap: 8px; margin-top: 10px; padding: 8px 12px; background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 6px; font-size: 12px; color: #64748b;">
                <span>💡 Щоб переглянути вихід готової продукції з переробки насіння:</span>
                <button type="button" class="btn-quick-period" data-period="month_oct" style="border: 1px solid #0284c7; background: #f0f9ff; color: #0284c7; border-radius: 4px; padding: 2px 8px; cursor: pointer; font-size: 11px; font-weight: 700;">📅 Жовтень 2026 (3 646,4 т)</button>
                <button type="button" class="btn-quick-period" data-period="day_active" style="border: 1px solid #059669; background: #ecfdf5; color: #059669; border-radius: 4px; padding: 2px 8px; cursor: pointer; font-size: 11px; font-weight: 700;">⚡ Доба 06.10 (600,3 т)</button>
                <button type="button" class="btn-quick-period" data-period="month_sep" style="border: 1px solid #64748b; background: #f1f5f9; color: #334155; border-radius: 4px; padding: 2px 8px; cursor: pointer; font-size: 11px; font-weight: 700;">Вересень (8 823,7 т)</button>
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
                    <span class="wh-stock-value" id="val-stock-oil"><?= number_format($stocks['oil_stock_tonnes'] ?? 3650.10, 2, ',', ' ') ?> т</span>
                    <div class="wh-progress-wrap"><div class="wh-progress-fill wh-fill-oil" style="width: <?= min(100, round((($stocks['oil_stock_tonnes'] ?? 3650.10) / 5000) * 100)) ?>%;"></div></div>
                    <span class="wh-stock-sub" id="val-shipped-oil">Відвантажено за добу: <?= number_format($stocks['oil_shipped_tonnes'] ?? 26.44, 2, ',', ' ') ?> т</span>
                </div>
                <div class="wh-stock-item">
                    <span class="wh-stock-title">🌾 Шрот на складі</span>
                    <span class="wh-stock-value" id="val-stock-meal"><?= number_format($stocks['meal_stock_tonnes'] ?? 674.86, 2, ',', ' ') ?> т</span>
                    <div class="wh-progress-wrap"><div class="wh-progress-fill wh-fill-meal" style="width: <?= min(100, round((($stocks['meal_stock_tonnes'] ?? 674.86) / 1500) * 100)) ?>%;"></div></div>
                    <span class="wh-stock-sub" id="val-shipped-meal">Відвантажено за добу: <?= number_format($stocks['meal_shipped_tonnes'] ?? 282.40, 2, ',', ' ') ?> т</span>
                </div>
                <div class="wh-stock-item">
                    <span class="wh-stock-title">🪵 Лузга на складі</span>
                    <span class="wh-stock-value" id="val-stock-husk"><?= number_format($stocks['husk_stock_tonnes'] ?? 166.77, 2, ',', ' ') ?> т</span>
                    <div class="wh-progress-wrap"><div class="wh-progress-fill wh-fill-husk" style="width: <?= min(100, round((($stocks['husk_stock_tonnes'] ?? 166.77) / 500) * 100)) ?>%;"></div></div>
                    <span class="wh-stock-sub" id="val-shipped-husk">Відвантажено за добу: <?= number_format($stocks['husk_shipped_tonnes'] ?? 29.50, 2, ',', ' ') ?> т</span>
                </div>
                <div class="wh-stock-item" style="background: #fffbeb; border-color: #fef3c7;">
                    <span class="wh-stock-title" style="color: #b45309;">🔥 Лузга на котельню</span>
                    <span class="wh-stock-value" style="color: #b45309;" id="val-boiler-husk"><?= number_format($stocks['husk_boiler_spent_tonnes'] ?? 0.0, 2, ',', ' ') ?> т</span>
                    <div class="wh-progress-wrap"><div class="wh-progress-fill wh-fill-boiler" style="width: <?= min(100, round((($stocks['husk_boiler_spent_tonnes'] ?? 0.0) / 60) * 100)) ?>%;"></div></div>
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
    <?php endif; // end !$isEnergo (Block A, Lab, Yield, Stocks) ?>

    <!-- =============================================================
         БЛОК В: ЕНЕРГОРЕСУРСИ ЗАВОДУ (ЕЛЕКТРОЕНЕРГІЯ, ВОДА, СТОКИ, ГАЗ)
         ============================================================= -->
    <div class="bottom-grid">
        
        <!-- Графік енергетики, води та пари + Звіт головного енергетика та облік пари -->
        <div class="card-panel">
            <div class="section-title" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <i data-lucide="zap" style="width: 18px; height: 18px; color: #f59e0b;"></i>
                    <span>Блок В: Електроенергія, водопостачання та технологічна пара заводу</span>
                    <span class="section-badge <?= !empty($en['has_data']) ? 'badge-green' : 'badge-blue' ?>" id="lbl-energy-period"><?= htmlspecialchars($en['period_note'] ?? 'Жовтень 2026: немає даних') ?></span>
                </div>
                <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                    <!-- Перемикач періодів для енергетика: Доба, Тиждень, Місяць -->
                    <div class="period-switcher energy-period-switcher" style="margin: 0; background: #f1f5f9; padding: 2px; border-radius: 6px; display: inline-flex; gap: 2px; border: 1px solid #cbd5e1;">
                        <button type="button" class="period-btn energy-period-btn active" data-period="day" style="padding: 4px 10px; font-size: 11px; font-weight: 700;">📅 Доба</button>
                        <button type="button" class="period-btn energy-period-btn" data-period="week" style="padding: 4px 10px; font-size: 11px; font-weight: 700;">🗓️ Тиждень</button>
                        <button type="button" class="period-btn energy-period-btn" data-period="month" style="padding: 4px 10px; font-size: 11px; font-weight: 700;">📆 Місяць</button>
                    </div>

                    <button type="button" class="btn-quick-period" data-period="month_sep" style="background: #0284c7; color: #ffffff; border: none; padding: 5px 11px; border-radius: 6px; font-size: 11px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; box-shadow: 0 1px 3px rgba(2,132,199,0.3);">
                        <i data-lucide="calendar" style="width: 12px; height: 12px;"></i>
                        <span>📅 Вересень (факт)</span>
                    </button>

                    <!-- Кнопка вводу показників енергетика -->
                    <button type="button" id="btn-open-energy-entry" style="background: linear-gradient(135deg, #10b981, #059669); color: #ffffff; border: 1px solid #047857; padding: 5px 12px; border-radius: 6px; font-size: 11px; font-weight: 800; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; box-shadow: 0 2px 6px rgba(16,185,129,0.35);">
                        <i data-lucide="edit-3" style="width: 13px; height: 13px;"></i>
                        <span>+ Внести показники</span>
                    </button>
                </div>
            </div>

            <!-- 6 карток витрат енергоносіїв (Електроенергія, Вода, Пара) -->
            <div class="energy-summary-cards energy-cards-6">
                <!-- 1. Активна електроенергія -->
                <div class="energy-card-mini" style="border-top: 3px solid #0284c7;">
                    <div class="mini-title">⚡ Активна електроенергія за добу</div>
                    <div class="mini-number" id="val-total-kwh" style="color: #0284c7;"><?= (!empty($en['has_data']) && !empty($en['has_power']) && $en['total_kwh'] !== null) ? (number_format($en['total_kwh'], 3, ',', ' ') . ' кВт') : (!empty($en['has_data']) ? 'Не внесено' : 'Жовтень: немає даних') ?></div>
                    <div class="mini-sub" id="val-current-power"><?= (!empty($en['has_data']) && !empty($en['has_power']) && $en['current_power_kw'] !== null) ? ('Потужність: ' . number_format($en['current_power_kw'], 3, ',', ' ') . ' кВт &bull; Реакт.: ' . number_format($en['reactive_kvarh'] ?? 0, 1, ',', ' ') . ' кВАр·год') : (!empty($en['has_data']) ? 'Очікується показник лічильника' : 'Звіт головного енергетика очікується') ?></div>
                </div>

                <!-- 2. Споживання води -->
                <div class="energy-card-mini" style="border-top: 3px solid #10b981;">
                    <div class="mini-title">💧 Споживання води (вхідний ліч.)</div>
                    <div class="mini-number" id="val-water-total" style="color: #059669;"><?= (!empty($en['has_data']) && !empty($en['has_water']) && $en['total_water_m3'] !== null) ? (number_format($en['total_water_m3'], 1, ',', ' ') . ' м³') : (!empty($en['has_data']) ? 'Не внесено' : 'Жовтень: немає даних') ?></div>
                    <div class="mini-sub" id="val-water-flow"><?= (!empty($en['has_data']) && !empty($en['has_water']) && $en['current_water_m3_hour'] !== null) ? ('Потік: ' . number_format($en['current_water_m3_hour'], 2, ',', ' ') . ' м³/год (' . number_format($en['daily_water_m3'] ?? 0, 1, ',', ' ') . ' м³/добу)') : (!empty($en['has_data']) ? 'Очікується показник водоміра' : 'Журнал лічильника очікується') ?></div>
                </div>

                <!-- 3. Технологічна пара котельні -->
                <div class="energy-card-mini" style="border-top: 3px solid #f59e0b;">
                    <div class="mini-title">💨 Технологічна пара котельні</div>
                    <div class="mini-number" id="val-steam-total" style="color: #d97706;"><?= (!empty($en['has_data']) && !empty($en['has_steam']) && ($en['steam_boiler_tonnes'] ?? null) !== null) ? (number_format($en['steam_boiler_tonnes'], 1, ',', ' ') . ' т') : (!empty($en['has_data']) ? 'Не внесено' : 'Жовтень: немає даних') ?></div>
                    <div class="mini-sub" id="val-steam-sub"><?= htmlspecialchars($en['steam_subtext'] ?? 'Жовтень: немає даних') ?></div>
                </div>

                <!-- 4. Розподіл пари по цехах & питома норма -->
                <div class="energy-card-mini" style="border-top: 3px solid #ea580c;">
                    <div class="mini-title">⚖️ Питома пара на 1 т насіння</div>
                    <div class="mini-number" id="val-steam-specific" style="color: #c2410c;"><?= (!empty($en['has_data']) && !empty($en['has_steam']) && ($en['steam_specific_kg_per_tonne'] ?? 0) > 0) ? (number_format($en['steam_specific_kg_per_tonne'], 1, ',', ' ') . ' кг/т') : (!empty($en['has_data']) ? 'Не внесено' : 'Жовтень: немає даних') ?></div>
                    <div class="mini-sub" id="val-steam-distrib"><?= htmlspecialchars($en['steam_distrib_subtext'] ?? 'Оберіть «Вересень» для перегляду') ?></div>
                </div>

                <!-- 5. Питома витрата електроенергії -->
                <div class="energy-card-mini" style="border-top: 3px solid #6366f1;">
                    <div class="mini-title">⚙️ Питома ел.енергія на 1 т</div>
                    <div class="mini-number" id="val-specific-kwh" style="color: #4f46e5;"><?= (!empty($en['has_data']) && !empty($en['has_power']) && ($en['specific_kwh_per_tonne'] ?? 0) > 0) ? (number_format($en['specific_kwh_per_tonne'], 3, ',', ' ') . ' кВт/т') : (!empty($en['has_data']) ? 'Не внесено' : 'Жовтень: немає даних') ?></div>
                    <div class="mini-sub" id="val-specific-sub"><?= htmlspecialchars($en['specific_subtext'] ?? 'Звіт очікується') ?></div>
                </div>

                <!-- 6. Котельня, екстракція та стоки -->
                <div class="energy-card-mini" style="border-top: 3px solid #8b5cf6;">
                    <div class="mini-title">🔥 Котельня, екстракція та стоки</div>
                    <div class="mini-number" id="val-sewage-total" style="color: #6d28d9;"><?= (!empty($en['has_data']) && !empty($en['has_water']) && ($en['sewage_m3'] ?? null) !== null) ? ('Стоки: ' . number_format($en['sewage_m3'], 1, ',', ' ') . ' м³') : (!empty($en['has_data']) ? 'Не внесено' : 'Жовтень: немає даних') ?></div>
                    <div class="mini-sub" id="val-boiler-water"><?= (!empty($en['has_data']) && !empty($en['has_water']) && ($en['boiler_water_m3'] ?? null) !== null) ? ('Котельня: ' . number_format($en['boiler_water_m3'], 0, ',', ' ') . ' м³ &bull; Екстракція: ' . number_format($en['extraction_water_m3'] ?? 0, 0, ',', ' ') . ' м³') : 'Оберіть «Вересень» для перегляду' ?></div>
                </div>
            </div>

            <!-- Подобовий графік споживання електроенергії заводу (у кВт за добу або МВт) -->
            <div class="card-panel-inner" style="background: #ffffff; border: 1px solid var(--border-color, #e2e8f0); border-radius: 8px; padding: 14px 16px; margin-top: 14px; margin-bottom: 14px;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; margin-bottom: 10px;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <i data-lucide="bar-chart-2" style="width: 16px; height: 16px; color: #0284c7;"></i>
                        <span style="font-weight: 700; font-size: 13px; color: #1e293b;">Подобовий графік споживання електроенергії заводу (МЕЗ)</span>
                        <span class="section-badge badge-blue" id="lbl-plant-power-unit">Режим: кВт за добу</span>
                    </div>
                    <div style="display: inline-flex; align-items: center; gap: 4px; background: #f8fafc; padding: 3px; border-radius: 6px; border: 1px solid #e2e8f0;">
                        <span style="font-size: 11px; color: #64748b; font-weight: 600; margin-right: 4px;">Одиниці:</span>
                        <button type="button" class="btn-power-unit active" data-unit="kwh" id="btn-toggle-kwh" style="border: none; background: #0284c7; color: #fff; padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: 700; cursor: pointer;">кВт за добу</button>
                        <button type="button" class="btn-power-unit" data-unit="mwh" id="btn-toggle-mwh" style="border: none; background: transparent; color: #475569; padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: 700; cursor: pointer;">МВт за добу</button>
                        <button type="button" class="btn-power-unit" data-unit="mw" id="btn-toggle-mw" style="border: none; background: transparent; color: #475569; padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: 700; cursor: pointer;">Потужність (МВт)</button>
                    </div>
                </div>
                <div id="plant-daily-power-chart"></div>
            </div>

            <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 10px; margin-bottom: 6px;">
                <span style="font-size: 12px; font-weight: 700; color: #334155;">📊 Комплексний баланс: Електроенергія (кВт), Вода (м³) та Технологічна пара (т)</span>
                <span style="font-size: 11px; color: #64748b;">(Наведіть на графік для перегляду розрахунку на 1 т переробленого насіння)</span>
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
                                    <th>Активна (кВт)</th>
                                    <th>Реактивна (кВАр·год)</th>
                                    <th>Питома (кВт/т)</th>
                                    <th>Вода (м³)</th>
                                    <th>Каналізація (м³)</th>
                                </tr>
                            </thead>
                            <tbody id="energy-departments-tbody">
                                <?php if (!empty($en['departments'])): ?>
                                    <?php foreach ($en['departments'] as $dept): ?>
                                    <tr>
                                        <td class="bold"><?= htmlspecialchars($dept['name']) ?></td>
                                        <td class="bold" style="color: #0284c7;"><?= number_format($dept['active_kwh'], 0, ',', ' ') ?></td>
                                        <td><?= number_format($dept['reactive_kvarh'], 1, ',', ' ') ?></td>
                                        <td><?= $dept['specific_kwh'] > 0 ? number_format($dept['specific_kwh'], 2, ',', '') : '—' ?></td>
                                        <td class="bold" style="color: #059669;"><?= number_format($dept['water_m3'], 0, ',', ' ') ?></td>
                                        <td><?= number_format($dept['sewage_m3'], 0, ',', ' ') ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" style="text-align: center; color: #64748b; padding: 22px; font-weight: 600;">
                                            Жовтень 2026: дані відсутні (звіт енергетика очікується). 
                                            <button type="button" class="btn-quick-period" data-period="month_sep" style="background: #0284c7; color: #fff; border: none; padding: 4px 10px; border-radius: 4px; font-size: 11px; margin-left: 8px; cursor: pointer; font-weight: 700;">
                                                Обрати Вересень 2026
                                            </button>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </details>

                <!-- Таблиця 2: Детальний технологічний облік води та стоків по вузлах і відповідальних («Облік води») -->
                <details class="season-monthly-details" open>
                    <summary>
                        <span><i data-lucide="droplets" style="width: 14px; height: 14px; vertical-align: middle; margin-right: 4px; color: #059669;"></i> Детальний облік води та стоків по вузлах і відповідальних (Журнал «Облік води»)</span>
                        <span style="font-size: 11px; color: #059669; font-weight: 600;">Пермеат котельні &bull; Випар ГМВ &bull; Побутові та пром. стоки</span>
                    </summary>
                    <div class="season-monthly-table-wrap">
                        <table class="data-table-clean" style="font-size: 11px;">
                            <thead>
                                <tr>
                                    <th>Вузол обліку води / стоків</th>
                                    <th>Відповідальний</th>
                                    <th>Вересень (м³ / доб.)</th>
                                    <th>Жовтень</th>
                                    <th>Разом (м³)</th>
                                    <th>Технологічний баланс (пермеат / випар / КНС)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (($en['water_meters'] ?? []) as $wm): ?>
                                <tr>
                                    <td class="bold"><?= htmlspecialchars($wm['node']) ?></td>
                                    <td><span class="kpi-badge badge-blue" style="font-size: 10px; padding: 2px 6px;"><?= htmlspecialchars($wm['responsible']) ?></span></td>
                                    <td><strong><?= number_format($wm['sep_m3'], 0, ',', ' ') ?></strong> <span style="color:#64748b;">(<?= number_format($wm['sep_daily'], 1, ',', '') ?>/д)</span></td>
                                    <td><?= $wm['oct_m3'] !== null ? ('<strong style="color: #059669;">' . number_format($wm['oct_m3'], 0, ',', ' ') . '</strong> <span style="color:#64748b;">(' . number_format($wm['oct_daily'], 1, ',', '') . '/д)</span>') : '<span style="color: #94a3b8; font-style: italic;">Немає даних</span>' ?></td>
                                    <td class="bold" style="color: #0284c7;"><?= number_format($wm['season_m3'], 0, ',', ' ') ?></td>
                                    <td style="font-size: 10.5px; color: #475569;"><?= htmlspecialchars($wm['note']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </details>

                <!-- Таблиця 3: Помісячне зведення енергоносіїв за 12 місяців -->
                <details class="season-monthly-details">
                    <summary>
                        <span><i data-lucide="calendar" style="width: 14px; height: 14px; vertical-align: middle; margin-right: 4px;"></i> Річний баланс: Електроенергія, Вода, Газ та Каналізація (12 місяців)</span>
                        <span style="font-size: 11px; color: #64748b;">Січень — Грудень</span>
                    </summary>
                    <div class="season-monthly-table-wrap">
                        <table class="data-table-clean" style="font-size: 11px;">
                            <thead>
                                <tr>
                                    <th>Місяць</th>
                                    <th>Ел.енергія (кВт)</th>
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
                                    <?php if (!empty($em['has_data'])): ?>
                                        <td class="bold" style="color: #0284c7;"><?= number_format($em['active_kwh'], 0, ',', ' ') ?></td>
                                        <td><?= number_format($em['reactive_kvarh'], 0, ',', ' ') ?></td>
                                        <td><?= number_format($em['press_kwh'] + $em['extr_kwh'], 0, ',', ' ') ?></td>
                                        <td class="bold" style="color: #059669;"><?= number_format($em['water_m3'], 0, ',', ' ') ?></td>
                                        <td><?= number_format($em['daily_water_m3'], 1, ',', '') ?> м³</td>
                                        <td><?= number_format($em['sewage_m3'], 0, ',', ' ') ?></td>
                                        <td><?= $em['gas_m3'] > 0 ? number_format($em['gas_m3'], 0, ',', ' ') : '—' ?></td>
                                    <?php else: ?>
                                        <td colspan="7" style="color: #94a3b8; font-style: italic; text-align: center;">Немає даних (звіт очікується)</td>
                                    <?php endif; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </details>
            </div>

            <!-- Блок технологічного обліку пари заводу («Учет пара на ООО “Агропроинвест 08”») -->
            <div style="margin-top: 16px;">
                <details class="season-monthly-details" open>
                    <summary>
                        <span><i data-lucide="flame" style="width: 14px; height: 14px; vertical-align: middle; margin-right: 4px; color: #f59e0b;"></i> Добовий журнал обліку технологічної пари («Учет пара на ООО “Агропроинвест 08”»)</span>
                        <span style="font-size: 11px; color: #f59e0b; font-weight: 700;">Лузгова котельня &bull; Цех екстракції &bull; Підготовчо-пресовий цех &bull; Елеватор</span>
                    </summary>
                    <div class="season-monthly-table-wrap">
                        <table class="data-table-clean" style="font-size: 11px;">
                            <thead>
                                <tr>
                                    <th>Дата / День</th>
                                    <th>Котел (т)</th>
                                    <th>Відпуск на пр-во (т)</th>
                                    <th>Власні потреби СН (т)</th>
                                    <th>Екстракція (т)</th>
                                    <th>Підготовка (т)</th>
                                    <th>Елеватор (т)</th>
                                    <th>Опалення (т)</th>
                                </tr>
                            </thead>
                            <tbody id="steam-daily-tbody">
                                <?php if (!empty($en['steam_daily_table'])): ?>
                                    <?php foreach ($en['steam_daily_table'] as $sr): ?>
                                    <tr>
                                        <td class="bold"><?= htmlspecialchars($sr['date'] ?? ($sr['day'] . '.09.2026')) ?></td>
                                        <td class="bold" style="color: #d97706;"><?= number_format($sr['boiler_t'], 2, ',', ' ') ?></td>
                                        <td class="bold" style="color: #0284c7;"><?= number_format($sr['prod_t'], 2, ',', ' ') ?></td>
                                        <td><?= number_format($sr['sn_t'], 2, ',', ' ') ?></td>
                                        <td style="color: #2563eb;"><?= number_format($sr['extr_t'], 2, ',', ' ') ?></td>
                                        <td style="color: #10b981;"><?= number_format($sr['prep_t'], 2, ',', ' ') ?></td>
                                        <td><?= number_format($sr['elev_t'], 2, ',', ' ') ?></td>
                                        <td><?= number_format($sr['heating_t'], 2, ',', ' ') ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="8" style="text-align: center; color: #64748b; padding: 22px; font-weight: 600;">
                                            Жовтень 2026: добовий журнал обліку пари очікується. 
                                            <button type="button" class="btn-quick-period" data-period="month_sep" style="background: #0284c7; color: #fff; border: none; padding: 4px 10px; border-radius: 4px; font-size: 11px; margin-left: 8px; cursor: pointer; font-weight: 700;">
                                                Обрати Вересень 2026
                                            </button>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </details>

                <!-- Помісячний технологічний баланс пари (Архів 2025–2026 рр.) -->
                <details class="season-monthly-details" style="margin-top: 10px;">
                    <summary>
                        <span><i data-lucide="history" style="width: 14px; height: 14px; vertical-align: middle; margin-right: 4px; color: #6366f1;"></i> Помісячний баланс виробітку та споживання пари (Архів 2025–2026 рр.)</span>
                        <span style="font-size: 11px; color: #64748b;">Звіт котельні МЕЗ</span>
                    </summary>
                    <div class="season-monthly-table-wrap">
                        <table class="data-table-clean" style="font-size: 11px;">
                            <thead>
                                <tr>
                                    <th>Місяць / Рік</th>
                                    <th>Виробіток котлом (т)</th>
                                    <th>Відпуск на пр-во (т)</th>
                                    <th>Власні потреби СН (т)</th>
                                    <th>Екстракція (т)</th>
                                    <th>Підготовка (т)</th>
                                    <th>Елеватор (т)</th>
                                    <th>Опалення (т)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (($en['steam_monthly_history'] ?? []) as $sm): ?>
                                <tr>
                                    <td class="bold"><?= htmlspecialchars($sm['month_ua'] ?? $sm['title']) ?></td>
                                    <td class="bold" style="color: #d97706;"><?= number_format($sm['boiler_t'], 2, ',', ' ') ?></td>
                                    <td class="bold" style="color: #0284c7;"><?= number_format($sm['prod_t'], 2, ',', ' ') ?></td>
                                    <td><?= number_format($sm['sn_t'], 2, ',', ' ') ?></td>
                                    <td style="color: #2563eb;"><?= number_format($sm['extr_t'], 2, ',', ' ') ?></td>
                                    <td style="color: #10b981;"><?= number_format($sm['prep_t'], 2, ',', ' ') ?></td>
                                    <td><?= number_format($sm['elev_t'], 2, ',', ' ') ?></td>
                                    <td><?= number_format($sm['heating_t'], 2, ',', ' ') ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </details>
            </div>
        </div>

    </div>

    <?php if (!$isEnergo): ?>
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
    $periodComp  = $historical['period_comparison'] ?? [];
    $pcKpi       = $periodComp['kpis'] ?? [];
    $pcHeaders   = $periodComp['headers'] ?? [];
    $pcRows      = $periodComp['rows'] ?? [];
    ?>
    <div class="historical-analytics-section" id="historical-analytics-section">
        <div class="section-title">
            <i data-lucide="line-chart" style="width: 18px; height: 18px; color: var(--color-primary);"></i>
            <span>Блок Г: Порівняльна аналітика періодів (Серпень — Вересень — Жовтень 2026)</span>
            <span class="section-badge badge-blue" id="badge-historical-season"><?= htmlspecialchars($seasonTot['badge_label'] ?? ('Виробничий сезон 2026–2027 (01.08.2026 — 01.08.2027 • ' . ($seasonTot['working_days'] ?? 25) . ' діб переробки)')) ?></span>
        </div>

        <!-- Картки-підсумки помісячно (Серпень, Вересень, Жовтень, Сезон) -->
        <div class="historical-cards-grid">
            
            <!-- Серпень 2026 -->
            <div class="history-month-card" data-period-trigger="month_aug">
                <div class="month-card-header">
                    <div class="month-card-title">
                        <span class="month-name">Серпень 2026</span>
                        <span class="month-badge badge-amber">Працював: 0 діб з 31 &bull; ППР</span>
                    </div>
                    <button type="button" class="btn-quick-period" data-period="month_aug" title="Показати дашборд за серпень">
                        <span>Детально</span> &rarr;
                    </button>
                </div>
                <div class="month-metrics-list">
                    <div class="metric-row">
                        <span class="metric-lbl">🗓️ Днів роботи (переробка):</span>
                        <span class="metric-val text-muted bold">0 діб (з 31 доби &bull; ППР)</span>
                    </div>
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
                        <span class="month-badge badge-green">Працював: 16 діб з 30 (15.09–30.09)</span>
                    </div>
                    <button type="button" class="btn-quick-period" data-period="month_sep" title="Показати дашборд за вересень">
                        <span>Детально</span> &rarr;
                    </button>
                </div>
                <div class="month-metrics-list">
                    <div class="metric-row">
                        <span class="metric-lbl">🗓️ Днів роботи (переробка):</span>
                        <span class="metric-val bold" style="color: #059669;">16 діб (15.09 — 30.09) з 30</span>
                    </div>
                    <div class="metric-row">
                        <span class="metric-lbl">🌻 Завіз насіння:</span>
                        <span class="metric-val bold text-primary"><?= number_format($mSep['seed_in'] ?? 19070.05, 2, ',', ' ') ?> т</span>
                    </div>
                    <div class="metric-row">
                        <span class="metric-lbl">⚙️ Переробка:</span>
                        <span class="metric-val bold"><?= number_format($mSep['seed_processed'] ?? 8823.66, 2, ',', ' ') ?> т (16 діб)</span>
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
                        <span class="month-name" id="name-oct-card"><?= htmlspecialchars($mOct['name'] ?? 'Жовтень 2026') ?></span>
                        <span class="month-badge badge-blue" id="badge-oct-working-days"><?= htmlspecialchars($mOct['working_days_label'] ?? ('Працював: ' . ($mOct['working_days'] ?? 9) . ' діб з ' . ($mOct['days_count'] ?? 14))) ?></span>
                    </div>
                    <button type="button" class="btn-quick-period" data-period="month_oct" title="Показати дашборд за жовтень">
                        <span>Детально</span> &rarr;
                    </button>
                </div>
                <div class="month-metrics-list">
                    <div class="metric-row">
                        <span class="metric-lbl">🗓️ Днів роботи (переробка):</span>
                        <span class="metric-val bold" id="val-oct-work-days" style="color: #0284c7;"><?= htmlspecialchars($mOct['working_days_label'] ?? ('Працював: ' . ($mOct['working_days'] ?? 9) . ' діб з ' . ($mOct['days_count'] ?? 14))) ?></span>
                    </div>
                    <div class="metric-row">
                        <span class="metric-lbl">🌻 Завіз насіння:</span>
                        <span class="metric-val bold text-primary" id="val-oct-seed-in"><?= number_format($mOct['seed_in'] ?? 6075.91, 2, ',', ' ') ?> т</span>
                    </div>
                    <div class="metric-row">
                        <span class="metric-lbl">⚙️ Переробка:</span>
                        <span class="metric-val bold" id="val-oct-seed-proc"><?= number_format($mOct['seed_processed'] ?? 4764.15, 2, ',', ' ') ?> т (<?= $mOct['working_days'] ?? 9 ?> діб)</span>
                    </div>
                    <div class="metric-row">
                        <span class="metric-lbl">💧 Вихід олії:</span>
                        <span class="metric-val bold" id="val-oct-oil-out" style="color: #d97706;"><?= number_format($mOct['oil_out'] ?? 2113.05, 2, ',', ' ') ?> т (<?= $mOct['oil_yield_pct'] ?? 44.35 ?>%)</span>
                    </div>
                    <div class="metric-row">
                        <span class="metric-lbl">🌾 Вихід шроту:</span>
                        <span class="metric-val bold" id="val-oct-meal-out" style="color: #2563eb;"><?= number_format($mOct['meal_out'] ?? 1951.41, 2, ',', ' ') ?> т (<?= $mOct['meal_yield_pct'] ?? 40.96 ?>%)</span>
                    </div>
                    <div class="metric-row">
                        <span class="metric-lbl">🚚 Автотранспорт:</span>
                        <span class="metric-val bold" id="val-oct-trucks"><?= $mOct['trucks_count'] ?? 231 ?> авто</span>
                    </div>
                    <div class="metric-row">
                        <span class="metric-lbl">🏢 Залишок насіння:</span>
                        <span class="metric-val" id="val-oct-seed-stock"><?= number_format($mOct['seed_stock'] ?? 13326.41, 2, ',', ' ') ?> т</span>
                    </div>
                </div>
                <div class="month-card-footer" id="val-oct-footer">
                    <span>Лузга: <?= number_format($mOct['husk_out'] ?? 718.27, 1, ',', ' ') ?> т &bull; Відвантажено: олія <?= number_format($mOct['oil_shipped'] ?? 1050.54, 0, ',', ' ') ?> т, шрот <?= number_format($mOct['meal_shipped'] ?? 2461.42, 0, ',', ' ') ?> т</span>
                </div>
            </div>

            <!-- Разом за сезон 2026–2027 -->
            <div class="history-month-card season-summary-card" data-period-trigger="season">
                <div class="month-card-header">
                    <div class="month-card-title">
                        <span class="month-name">Сезон 2026–2027 РАЗОМ</span>
                        <span class="month-badge badge-green" id="badge-season-date-range">01.08.2026 — 01.08.2027</span>
                    </div>
                    <button type="button" class="btn-quick-period" data-period="season" title="Показати дашборд за сезон">
                        <span>Детально</span> &rarr;
                    </button>
                </div>
                <div class="month-metrics-list">
                    <div class="metric-row">
                        <span class="metric-lbl">🗓️ Днів роботи (переробка):</span>
                        <span class="metric-val bold" id="val-season-work-days" style="color: #059669;"><?= htmlspecialchars($seasonTot['working_days_label'] ?? ('Працював: ' . ($seasonTot['working_days'] ?? 25) . ' діб переробки (з ' . ($seasonTot['total_days'] ?? 75) . ' діб сезону)')) ?></span>
                    </div>
                    <div class="metric-row">
                        <span class="metric-lbl">🌻 Завіз насіння:</span>
                        <span class="metric-val bold text-primary" id="val-season-seed-in"><?= number_format($seasonTot['seed_in'] ?? 25361.41, 2, ',', ' ') ?> т</span>
                    </div>
                    <div class="metric-row">
                        <span class="metric-lbl">⚙️ Переробка:</span>
                        <span class="metric-val bold" id="val-season-seed-proc"><?= number_format($seasonTot['seed_processed'] ?? 13587.81, 2, ',', ' ') ?> т (<?= $seasonTot['working_days'] ?? 25 ?> діб)</span>
                    </div>
                    <div class="metric-row">
                        <span class="metric-lbl">💧 Вихід олії:</span>
                        <span class="metric-val bold" id="val-season-oil-out" style="color: #d97706;"><?= number_format($seasonTot['oil_out'] ?? 5924.71, 2, ',', ' ') ?> т (<?= $seasonTot['oil_yield_pct'] ?? 43.60 ?>%)</span>
                    </div>
                    <div class="metric-row">
                        <span class="metric-lbl">🌾 Вихід шроту:</span>
                        <span class="metric-val bold" id="val-season-meal-out" style="color: #2563eb;"><?= number_format($seasonTot['meal_out'] ?? 5634.62, 2, ',', ' ') ?> т (<?= $seasonTot['meal_yield_pct'] ?? 41.47 ?>%)</span>
                    </div>
                    <div class="metric-row">
                        <span class="metric-lbl">🚚 Автотранспорт:</span>
                        <span class="metric-val bold" id="val-season-trucks"><?= $seasonTot['trucks_count'] ?? 977 ?> авто</span>
                    </div>
                    <div class="metric-row">
                        <span class="metric-lbl">🪵 Лузга всього:</span>
                        <span class="metric-val" id="val-season-husk-out"><?= number_format($seasonTot['husk_out'] ?? 1979.50, 2, ',', ' ') ?> т</span>
                    </div>
                </div>
                <div class="month-card-footer" id="val-season-footer">
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
                <div class="panel-header-simple" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                    <div class="panel-title-text" style="display: flex; align-items: center; gap: 8px;">
                        <i data-lucide="scale" style="width: 16px; height: 16px; color: #10b981;"></i>
                        <span>Порівняння з сезоном 2025–2026 рр. (Офіційний звіт заводу)</span>
                        <span class="section-badge badge-green" id="badge-season-compare-period"><?= htmlspecialchars($periodComp['badge_label'] ?? 'Порівняння доби: 2026 vs 2025') ?></span>
                    </div>
                    <!-- Міні-перемикач періоду порівняння (синхронізований з дашбордом) -->
                    <div class="season-compare-pills" id="season-compare-pills">
                        <button type="button" class="season-pill-btn <?= ($initialPeriod === 'day') ? 'active' : '' ?>" data-period="day">Доба (сьогодні)</button>
                        <button type="button" class="season-pill-btn" data-period="day_active">Робоча доба (600 т)</button>
                        <button type="button" class="season-pill-btn <?= ($initialPeriod === 'week') ? 'active' : '' ?>" data-period="week">Тиждень</button>
                        <button type="button" class="season-pill-btn <?= ($initialPeriod === 'month') ? 'active' : '' ?>" data-period="month">Місяць</button>
                        <button type="button" class="season-pill-btn <?= ($initialPeriod === 'season') ? 'active' : '' ?>" data-period="season">Сезон</button>
                    </div>
                </div>

                <!-- БЛОК ВЕЛИКИХ ЦИФР ТА ІНФОГРАФІКИ (4 KPI КАРТКИ) -->
                <div class="season-kpi-grid">
                    
                    <!-- 1. Переробка соняшнику -->
                    <div class="season-kpi-card card-accent-seed" id="card-season-seed">
                        <div class="season-kpi-header">
                            <span class="season-kpi-title" id="kpi-season-seed-title"><?= htmlspecialchars($pcKpi['seed_processed']['title'] ?? '⚙️ Переробка насіння') ?></span>
                            <span class="season-kpi-badge" id="kpi-season-seed-badge"><?= htmlspecialchars($pcKpi['seed_processed']['badge'] ?? 'Сьогодні немає переробки') ?></span>
                        </div>
                        <div class="season-kpi-number-wrap">
                            <span class="season-kpi-number" id="kpi-season-seed-num"><?= htmlspecialchars($pcKpi['seed_processed']['number'] ?? '0,0') ?></span>
                            <span class="season-kpi-unit" id="kpi-season-seed-unit"><?= htmlspecialchars($pcKpi['seed_processed']['unit'] ?? 'т (сьогодні немає переробки)') ?></span>
                        </div>
                        <div class="season-kpi-subtext" id="kpi-season-seed-sub">
                            <?= $pcKpi['seed_processed']['subtext'] ?? '2025 аналог: <strong>538,1 т/добу</strong> &bull; Відхилення: <strong>-538,1 т (простій)</strong> &bull; На складі: 13 115,5 т' ?>
                        </div>
                        <div class="season-progress-track">
                            <div class="season-progress-fill" id="kpi-season-seed-bar" style="width: <?= min(100, (float)($pcKpi['seed_processed']['progress_pct'] ?? 0)) ?>%; background: #94a3b8;"></div>
                        </div>
                    </div>

                    <!-- 2. Вихід Олії -->
                    <div class="season-kpi-card card-accent-oil" id="card-season-oil">
                        <div class="season-kpi-header">
                            <span class="season-kpi-title" style="color: #b45309;" id="kpi-season-oil-title"><?= htmlspecialchars($pcKpi['oil_yield']['title'] ?? '💧 Вихід Олії') ?></span>
                            <span class="season-kpi-badge" style="background: #f1f5f9; color: #64748b;" id="kpi-season-oil-badge"><?= htmlspecialchars($pcKpi['oil_yield']['badge'] ?? 'Немає переробки') ?></span>
                        </div>
                        <div class="season-kpi-number-wrap">
                            <span class="season-kpi-number" style="color: #d97706;" id="kpi-season-oil-num"><?= htmlspecialchars($pcKpi['oil_yield']['number'] ?? '0,00%') ?></span>
                            <span class="season-kpi-unit" id="kpi-season-oil-unit"><?= htmlspecialchars($pcKpi['oil_yield']['unit'] ?? 'доба 2026 (0,0 т)') ?></span>
                        </div>
                        <div class="season-kpi-subtext" id="kpi-season-oil-sub">
                            <?= $pcKpi['oil_yield']['subtext'] ?? '2025 аналог: <strong>44,45% (239,2 т)</strong> &bull; Відхилення: <strong>виробництво зупинено</strong>' ?>
                        </div>
                        <div class="season-progress-track">
                            <div class="season-progress-fill" id="kpi-season-oil-bar" style="width: <?= min(100, (float)($pcKpi['oil_yield']['progress_pct'] ?? 0)) ?>%; background: #94a3b8;"></div>
                        </div>
                    </div>

                    <!-- 3. Вихід Шроту -->
                    <div class="season-kpi-card card-accent-meal" id="card-season-meal">
                        <div class="season-kpi-header">
                            <span class="season-kpi-title" style="color: #1d4ed8;" id="kpi-season-meal-title"><?= htmlspecialchars($pcKpi['meal_yield']['title'] ?? '🌾 Вихід Шроту') ?></span>
                            <span class="season-kpi-badge" style="background: #f1f5f9; color: #64748b;" id="kpi-season-meal-badge"><?= htmlspecialchars($pcKpi['meal_yield']['badge'] ?? 'Немає переробки') ?></span>
                        </div>
                        <div class="season-kpi-number-wrap">
                            <span class="season-kpi-number" style="color: #2563eb;" id="kpi-season-meal-num"><?= htmlspecialchars($pcKpi['meal_yield']['number'] ?? '0,00%') ?></span>
                            <span class="season-kpi-unit" id="kpi-season-meal-unit"><?= htmlspecialchars($pcKpi['meal_yield']['unit'] ?? 'доба 2026 (0,0 т)') ?></span>
                        </div>
                        <div class="season-kpi-subtext" id="kpi-season-meal-sub">
                            <?= $pcKpi['meal_yield']['subtext'] ?? '2025 аналог: <strong>41,76% (224,7 т)</strong> &bull; Відхилення: <strong>виробництво зупинено</strong>' ?>
                        </div>
                        <div class="season-progress-track">
                            <div class="season-progress-fill" id="kpi-season-meal-bar" style="width: <?= min(100, (float)($pcKpi['meal_yield']['progress_pct'] ?? 0)) ?>%; background: #94a3b8;"></div>
                        </div>
                    </div>

                    <!-- 4. Сума Олія + Шрот -->
                    <div class="season-kpi-card card-accent-sum" id="card-season-sum">
                        <div class="season-kpi-header">
                            <span class="season-kpi-title" style="color: #6d28d9;" id="kpi-season-sum-title"><?= htmlspecialchars($pcKpi['oil_meal_sum']['title'] ?? '⚖️ Олія + Шрот') ?></span>
                            <span class="season-kpi-badge" style="background: #f1f5f9; color: #64748b;" id="kpi-season-sum-badge"><?= htmlspecialchars($pcKpi['oil_meal_sum']['badge'] ?? 'Немає переробки') ?></span>
                        </div>
                        <div class="season-kpi-number-wrap">
                            <span class="season-kpi-number" style="color: #7c3aed;" id="kpi-season-sum-num"><?= htmlspecialchars($pcKpi['oil_meal_sum']['number'] ?? '0,00%') ?></span>
                            <span class="season-kpi-unit" id="kpi-season-sum-unit"><?= htmlspecialchars($pcKpi['oil_meal_sum']['unit'] ?? 'доба 2026 (0,0 т)') ?></span>
                        </div>
                        <div class="season-kpi-subtext" id="kpi-season-sum-sub">
                            <?= $pcKpi['oil_meal_sum']['subtext'] ?? '2025 аналог: <strong>86,21%</strong> &bull; Відхилення: <strong>виробництво зупинено</strong>' ?>
                        </div>
                        <div class="season-progress-track">
                            <div class="season-progress-fill" id="kpi-season-sum-bar" style="width: <?= min(100, (float)($pcKpi['oil_meal_sum']['progress_pct'] ?? 0)) ?>%; background: #94a3b8;"></div>
                        </div>
                    </div>

                </div>

                <!-- ДЕТАЛЬНА ПОРІВНЯЛЬНА ТАБЛИЦЯ ВИРОБНИЦТВА -->
                <div class="seasonal-comparison-table-wrap" style="margin-top: 14px;">
                    <table class="data-table-clean">
                        <thead>
                            <tr>
                                <th>Показник виробництва</th>
                                <th id="th-season-compare-2026"><?= htmlspecialchars($pcHeaders['col_2026'] ?? 'Доба 2026 (11-12.10)') ?></th>
                                <th id="th-season-compare-2025-analog"><?= htmlspecialchars($pcHeaders['col_2025_analog'] ?? 'Аналогічна доба 2025 (Жовтень)') ?></th>
                                <th id="th-season-compare-2025-year"><?= htmlspecialchars($pcHeaders['col_2025_year'] ?? 'Сезон 2025–2026 (61,9 тис. т)') ?></th>
                                <th id="th-season-compare-trend"><?= htmlspecialchars($pcHeaders['col_trend'] ?? 'Динаміка') ?></th>
                            </tr>
                        </thead>
                        <tbody id="tbody-season-comparison">
                            <?php foreach (($pcRows ?: []) as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['name']) ?></td>
                                <td class="bold"><?= htmlspecialchars($row['val_2026']) ?></td>
                                <td><?= htmlspecialchars($row['val_2025_an']) ?></td>
                                <td><?= htmlspecialchars($row['val_2025_yr']) ?></td>
                                <td><span class="trend-badge <?= htmlspecialchars($row['trend_class']) ?>"><?= htmlspecialchars($row['trend_text']) ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- РОЗГОРТАННЯ ОФІЦІЙНОГО ПОМІСЯЧНОГО ЗВІТУ 2025–2026 (01.08.2025 — 01.08.2026) -->
                <?php if (!empty($months2025)): ?>
                <details class="season-monthly-details">
                    <summary>
                        <span><i data-lucide="table" style="width: 14px; height: 14px; vertical-align: middle; margin-right: 4px;"></i> Офіційний помісячний звіт сезону 2025–2026 рр. (01.08.2025 — 01.08.2026)</span>
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
                                    <td>РАЗОМ СЕЗОН 2025–2026</td>
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
                        <strong>Еталонний звіт сезону 2025–2026 (01.08.2025 — 01.08.2026):</strong> за повний річний виробничий сезон перероблено 61 892,9 т при вхідній олійності 45.13%. 
                        Вихід олії склав 44.30%, шроту 41.67%, сума основних продуктів 85.97%, а загальний вихід готової продукції досяг 98.85% (технологічні втрати лише 1.15%).
                    </span>
                </div>
            </div>

        </div>

    </div>
    <?php endif; // end !$isEnergo (Block G) ?>

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
                <div class="modal-icon"><i data-lucide="<?= $isEnergo ? 'zap' : 'file-spreadsheet' ?>"></i></div>
                <div>
                    <h2 class="modal-title"><?= $isEnergo ? 'Імпорт звітів енергоносіїв заводу' : 'Щоденний імпорт звітів заводу' ?></h2>
                    <p class="modal-subtitle"><?= $isEnergo ? 'Завантажте файли Excel (.xlsx / .xls) для оновлення Блоку В (Електроенергія, Водопостачання, Пара)' : 'Завантажте файли Excel (.xlsx / .csv) для автоматичного оновлення показників МЕЗ' ?></p>
                </div>
            </div>
            <button type="button" class="modal-close-btn" id="btn-close-import" title="Закрити">&times;</button>
        </div>

        <div class="modal-body">
            <!-- Список файлів з точними назвами -->
            <div class="import-files-guide">
                <?php if ($isEnergo): ?>
                <div class="guide-title">
                    <i data-lucide="info" style="width: 15px; height: 15px; color: #d97706;"></i>
                    <span>Точні назви файлів звітів енергетика для завантаження (можна обирати по одному або всі разом):</span>
                </div>

                <div class="guide-list">
                    <!-- Файл 1: РАСПРЕД.ЭНЕРГОНОСИТЕЛЕЙ 2026.xlsx -->
                    <div class="guide-item" data-expected="energy">
                        <div class="guide-item-icon">⚡</div>
                        <div class="guide-item-content">
                            <div class="guide-item-name">1. <code>РАСПРЕД.ЭНЕРГОНОСИТЕЛЕЙ 2026.xlsx</code> <span class="guide-status-pill" id="guide-status-energy" style="display:none;">✓ Обрано</span></div>
                            <div class="guide-item-filename">Вкладки всередині: <code>ЖОВТЕНЬ</code>, <code>2026</code>, <code>ел. енергія 2026</code>, помісячний розподіл</div>
                            <div class="guide-item-desc"><strong>Що оновлює:</strong> Добовий розподіл активної електроенергії по підстанціях та об'єктах МЕЗ, графіки споживання та питому норму кВт/т насіння.</div>
                        </div>
                        <span class="guide-tag tag-primary">Розподіл електроенергії</span>
                    </div>

                    <!-- Файл 2: АГРОПРОІНВЕСТ 08(28).xlsx -->
                    <div class="guide-item" data-expected="energy">
                        <div class="guide-item-icon">🏢</div>
                        <div class="guide-item-content">
                            <div class="guide-item-name">2. <code>АГРОПРОІНВЕСТ 08(28).xlsx</code></div>
                            <div class="guide-item-filename">Вкладки всередині: помісячні акти та зведення споживання ТОВ «Агропроінвест 08»</div>
                            <div class="guide-item-desc"><strong>Що оновлює:</strong> Зведені показники комерційного обліку енергоносіїв, договірні ліміти та акти звірки з обленерго/постачальниками.</div>
                        </div>
                        <span class="guide-tag tag-amber">Агропроінвест 08</span>
                    </div>

                    <!-- Файл 3: Облік води.xlsx -->
                    <div class="guide-item" data-expected="energy">
                        <div class="guide-item-icon">💧</div>
                        <div class="guide-item-content">
                            <div class="guide-item-name">3. <code>Облік води.xlsx</code></div>
                            <div class="guide-item-filename">Вкладки всередині: <code>Лист1 (Облік води)</code>, щоденні показники водомірних лічильників</div>
                            <div class="guide-item-desc"><strong>Що оновлює:</strong> Водомірний вузол по відповідальних особах (Усов А.В., Хлібець В., Нікітіна А., Довгаленко С.), підживлення котельні, витрати пермеату, випар ГМВ та стоки.</div>
                        </div>
                        <span class="guide-tag tag-emerald">Облік води</span>
                    </div>

                    <!-- Файл 4: Учет пара.xlsx -->
                    <div class="guide-item" data-expected="energy">
                        <div class="guide-item-icon">♨️</div>
                        <div class="guide-item-content">
                            <div class="guide-item-name">4. <code>Учет пара.xlsx</code></div>
                            <div class="guide-item-filename">Вкладки всередині: щодобовий облік виробництва та споживання технологічної пари котельнею МЕЗ</div>
                            <div class="guide-item-desc"><strong>Що оновлює:</strong> Виробництво технологічної пари котельнею на луззі, тиск пари, розподіл на пресове та екстракційне відділення.</div>
                        </div>
                        <span class="guide-tag tag-slate">Технологічна пара</span>
                    </div>
                </div>
                <?php else: ?>
                <div class="guide-title">
                    <i data-lucide="info" style="width: 15px; height: 15px; color: var(--color-primary);"></i>
                    <span>Точні назви ваших файлів Excel для завантаження (можна завантажувати по одному або всі разом):</span>
                </div>

                <div class="guide-list">
                    <!-- Файл 1: Приход + Специфікація 1С (або Лист_погодження.xlsx) -->
                    <div class="guide-item" data-expected="lab">
                        <div class="guide-item-icon">📋</div>
                        <div class="guide-item-content">
                            <div class="guide-item-name">1. 2 файли з 1С: <code>prihod13-10.xlsx</code> (Приход) + <code>spec13-10.xlsx</code> (Специфікація) <span style="font-weight:400; font-size:11px; color:#64748b;">(або готовий <code>Лист_погодження.xlsx</code>)</span> <span class="guide-status-pill" id="guide-status-lab" style="display:none;">✓ Обрано</span></div>
                            <div class="guide-item-filename">Автоматично зводить <code>Лист погодження</code> без десктопної програми (всі 6 вкладок «🌻 СЕМЕНА», аудит цін та невиконані договори)</div>
                            <div class="guide-item-desc"><strong>Що оновлює:</strong> Сторінку «🌻 СЕМЕНА», Зону приймання за добу/тиждень/місяць (фіз. вага без очистки та сушки, авто), Лабораторію якості (олійність, вологість, смітна домішка) та Контрактацію 1С.</div>
                        </div>
                        <span class="guide-tag tag-primary">1С Приход + Специфікація</span>
                    </div>

                    <!-- Файл 2: Ежедн.отчет2_ Октябрь 26  31.xlsx -->
                    <div class="guide-item" data-expected="stocks">
                        <div class="guide-item-icon">🏢</div>
                        <div class="guide-item-content">
                            <div class="guide-item-name">2. <code>Ежедн.отчет2_ Октябрь 26  31.xlsx</code> <span style="font-weight:400; font-size:11px; color:#64748b;">(або <code>Ежедн.отчет2_ Сентябрь 26.xlsx</code> / <code>Август 26.xlsx</code>)</span> <span class="guide-status-pill" id="guide-status-stocks" style="display:none;">✓ Обрано</span></div>
                            <div class="guide-item-filename">Вкладки всередині: <code>СВОД_ОКТЯБРЬ 26</code>, <code>СВОД_сент.</code>, <code>СВОД_авг.</code> та щоденні <code>1 число</code> … <code>31 число</code></div>
                            <div class="guide-item-desc"><strong>Що оновлює:</strong> Складські залишки СВОД на ранок (насіння на елеваторі, олія в баках, шрот, лузга), запас автономної роботи заводу в добах, добове відвантаження та спалювання лузги в котельні.</div>
                        </div>
                        <span class="guide-tag tag-emerald">Залишки СВОД + Відвантаження</span>
                    </div>

                    <!-- Файл 3: Справка по переработке урожая 2026 .xlsx -->
                    <div class="guide-item" data-expected="prod">
                        <div class="guide-item-icon">⚙️</div>
                        <div class="guide-item-content">
                            <div class="guide-item-name">3. <code>Справка по переработке урожая 2026 .xlsx</code> <span style="font-weight:400; font-size:11px; color:#64748b;">(також <code>Справка по переработке урожая 2025 .xlsx</code>)</span> <span class="guide-status-pill" id="guide-status-prod" style="display:none;">✓ Обрано</span></div>
                            <div class="guide-item-filename">Вкладки всередині: <code>Октябрь26</code>, <code>Сент.26</code>, <code>СВОДНАЯ 2026-27</code> (та <code>СВОДНАЯ 2025-26</code>)</div>
                            <div class="guide-item-desc"><strong>Що оновлює:</strong> Переробку насіння у виробництві (цех екстракції) по днях та місяцях, вихід готової продукції у тоннах і % (Олія + Шрот + Лузга, включно з плюсовим виходом >100%) та втрати.</div>
                        </div>
                        <span class="guide-tag tag-amber">Переробка + Вихід продукції</span>
                    </div>

                    <!-- Файл 4: Ежедневный отчет _ Октябрь 26.xlsx -->
                    <div class="guide-item" data-expected="daily_prod">
                        <div class="guide-item-icon">📊</div>
                        <div class="guide-item-content">
                            <div class="guide-item-name">4. <code>Ежедневный отчет _ Октябрь 26.xlsx</code> <span style="font-weight:400; font-size:11px; color:#64748b;">(або за Вересень / Серпень)</span> <span class="guide-status-pill" id="guide-status-daily_prod" style="display:none;">✓ Обрано</span></div>
                            <div class="guide-item-filename">Вкладки всередині: <code>ПЛАН производства</code>, <code>СВОД за период</code>, <code>1 число</code> … <code>31 число</code></div>
                            <div class="guide-item-desc"><strong>Що оновлює:</strong> Оперативний щоденний звіт виробництва та виконання виробничого плану по змінах/добах.</div>
                        </div>
                        <span class="guide-tag tag-slate">План виробництва</span>
                    </div>

                    <!-- Файл 5: РАСПРЕД.ЭНЕРГОНОСИТЕЛЕЙ 2026.xlsx, АГРОПРОІНВЕСТ 08(28).xlsx та Облік води.xlsx -->
                    <div class="guide-item" data-expected="energy">
                        <div class="guide-item-icon">⚡</div>
                        <div class="guide-item-content">
                            <div class="guide-item-name">5. <code>РАСПРЕД.ЭНЕРГОНОСИТЕЛЕЙ 2026.xlsx</code>, <code>АГРОПРОІНВЕСТ 08(28).xlsx</code> та <code>Облік води.xlsx</code> <span class="guide-status-pill" id="guide-status-energy" style="display:none;">✓ Обрано</span></div>
                            <div class="guide-item-filename">Вкладки всередині: <code>січень 26</code> … <code>вересень 26</code>, <code>ЖОВТЕНЬ</code>, <code>2026</code>, <code>ел. енергія 2026</code>, <code>Лист1 (Облік води)</code></div>
                            <div class="guide-item-desc"><strong>Що оновлює:</strong> Блок В — графік електроенергії та води по днях, питому витрату кВт/т, показники водомірного вузла по відповідальних (Усов А.В., Хлібець В., Нікітіна А., Довгаленко С.), пермеат котельні, випар ГМВ та стоки.</div>
                        </div>
                        <span class="guide-tag tag-primary">Електроенергія + Вода</span>
                    </div>
                </div>
                <?php endif; ?>
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

<!-- =============================================================
     МОДАЛЬНЕ ВІКНО: ВВІД ПОКАЗНИКІВ ЕНЕРГОНОСІЇВ (ЕЛЕКТРОЕНЕРГІЯ, ВОДА, ПАРА)
     ============================================================= -->
<div id="energy-entry-modal" class="modal-overlay" style="display: none;">
    <div class="modal-container" style="max-width: 680px;">
        <div class="modal-header" style="background: linear-gradient(135deg, #eff6ff, #f0fdf4); border-bottom: 1px solid #e2e8f0;">
            <div class="modal-title-wrap">
                <div class="modal-icon" style="background: #0284c7; color: #ffffff;"><i data-lucide="zap"></i></div>
                <div>
                    <h2 class="modal-title" style="color: #0f172a; font-size: 17px;">Ввід показників енергоносіїв (Служба головного енергетика)</h2>
                    <p class="modal-subtitle">Оберіть дату та введіть споживання електроенергії у кВт за добу, витрату води та пару</p>
                </div>
            </div>
            <button type="button" class="modal-close-btn" id="btn-close-energy-entry" title="Закрити">&times;</button>
        </div>

        <form id="form-energy-entry" autocomplete="off">
            <div class="modal-body" style="padding: 20px;">
                <!-- Дата обліку -->
                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-size: 12px; font-weight: 700; color: #334155; margin-bottom: 6px;">
                        📅 Дата зняття показників / доба:
                    </label>
                    <input type="date" id="energy-form-date" class="form-input-clean" value="<?= date('Y-m-d') ?>" style="width: 100%; max-width: 260px; padding: 8px 12px; font-size: 13px; font-weight: 700; border: 1px solid #cbd5e1; border-radius: 6px; color: #0f172a; outline: none;" required>
                    <div style="font-size: 11px; color: #64748b; margin-top: 4px;">Показники записуються за повну робочу добу заводу (24 години).</div>
                </div>

                <div style="font-size: 11.5px; color: #334155; background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 6px; padding: 8px 12px; margin-bottom: 14px;">
                    💡 <strong>Гнучкий ввід:</strong> можна внести та зберегти <em>тільки воду</em>, <em>тільки пару</em> або <em>тільки електроенергію</em>. Непотрібні на цей момент поля залишайте порожніми (попередньо внесені дані за цю добу не зітруться). Для електроенергії підтримується 3 знаки після коми / крапки.
                </div>

                <!-- 3 основні показники в рядку -->
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; margin-bottom: 16px;">
                    <!-- 1. Електроенергія -->
                    <div style="background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 8px; padding: 12px;">
                        <label style="display: flex; align-items: center; gap: 4px; font-size: 12px; font-weight: 700; color: #0369a1; margin-bottom: 6px;">
                            ⚡ Споживання електроенергії (кВт):
                        </label>
                        <input type="number" step="0.001" min="0" id="energy-form-kwh" placeholder="напр. 31200.000" style="width: 100%; padding: 8px 10px; font-size: 15px; font-weight: 800; border: 1px solid #7dd3fc; border-radius: 6px; color: #0284c7; background: #fff;">
                        <div style="font-size: 10.5px; color: #0284c7; margin-top: 4px;">3 знаки після коми (напр. 31800,125 або 31,800)</div>
                    </div>

                    <!-- 2. Вода -->
                    <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 12px;">
                        <label style="display: flex; align-items: center; gap: 4px; font-size: 12px; font-weight: 700; color: #15803d; margin-bottom: 6px;">
                            💧 Витрата води (м³):
                        </label>
                        <input type="number" step="0.01" min="0" id="energy-form-water" placeholder="напр. 185.0" style="width: 100%; padding: 8px 10px; font-size: 15px; font-weight: 800; border: 1px solid #86efac; border-radius: 6px; color: #16a34a; background: #fff;">
                        <div style="font-size: 10.5px; color: #15803d; margin-top: 4px;">Вхідний лічильник водопроводу МЕЗ (необов'язково)</div>
                    </div>

                    <!-- 3. Технологічна пара -->
                    <div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 12px;">
                        <label style="display: flex; align-items: center; gap: 4px; font-size: 12px; font-weight: 700; color: #b45309; margin-bottom: 6px;">
                            ♨️ Кількість пари (т):
                        </label>
                        <input type="number" step="0.01" min="0" id="energy-form-steam" placeholder="напр. 124.5" style="width: 100%; padding: 8px 10px; font-size: 15px; font-weight: 800; border: 1px solid #fcd34d; border-radius: 6px; color: #d97706; background: #fff;">
                        <div style="font-size: 10.5px; color: #b45309; margin-top: 4px;">Виробіток котельні на лушпинні (необов'язково)</div>
                    </div>
                </div>

                <!-- Додаткові поля (тиск, реактивна енергія, примітка) -->
                <details style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 14px; margin-bottom: 16px;">
                    <summary style="font-size: 11.5px; font-weight: 700; color: #475569; cursor: pointer;">
                        ⚙️ Додаткові параметри (тиск пари, реактивна енергія, примітка)
                    </summary>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 10px;">
                        <div>
                            <label style="display: block; font-size: 11px; font-weight: 600; color: #64748b; margin-bottom: 4px;">Тиск пари в магістралі (бар):</label>
                            <input type="number" step="0.1" id="energy-form-pressure" value="11.2" placeholder="11.2" style="width: 100%; padding: 6px 10px; font-size: 12px; border: 1px solid #cbd5e1; border-radius: 5px;">
                        </div>
                        <div>
                            <label style="display: block; font-size: 11px; font-weight: 600; color: #64748b; margin-bottom: 4px;">Реактивна енергія (кВАр·год):</label>
                            <input type="number" step="0.1" id="energy-form-reactive" placeholder="напр. 9900" style="width: 100%; padding: 6px 10px; font-size: 12px; border: 1px solid #cbd5e1; border-radius: 5px;">
                        </div>
                    </div>
                    <div style="margin-top: 8px;">
                        <label style="display: block; font-size: 11px; font-weight: 600; color: #64748b; margin-bottom: 4px;">Примітка чергового енергетика / зміни:</label>
                        <input type="text" id="energy-form-note" placeholder="напр. Чергова зміна №1, нормальний режим переробки" style="width: 100%; padding: 6px 10px; font-size: 12px; border: 1px solid #cbd5e1; border-radius: 5px;">
                    </div>
                </details>

                <!-- Останні внесені записи (таблиця історії) -->
                <div>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                        <span style="font-size: 11.5px; font-weight: 700; color: #334155;">📋 Останні збережені показники енергетика:</span>
                        <button type="button" id="btn-reload-energy-history" style="background: none; border: none; font-size: 11px; color: #0284c7; cursor: pointer; text-decoration: underline;">Оновити таблицю</button>
                    </div>
                    <div style="max-height: 140px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 6px;">
                        <table class="data-table-clean" style="font-size: 11px; width: 100%;">
                            <thead>
                                <tr>
                                    <th>Дата</th>
                                    <th>Електроенергія (кВт)</th>
                                    <th>Вода</th>
                                    <th>Пара</th>
                                    <th>Автор</th>
                                    <th>Дія</th>
                                </tr>
                            </thead>
                            <tbody id="energy-history-tbody">
                                <tr><td colspan="6" style="text-align: center; color: #94a3b8; padding: 10px;">Завантаження історії...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div id="energy-form-alert" style="display: none; margin-top: 12px; padding: 8px 12px; border-radius: 6px; font-size: 12px;"></div>
            </div>

            <div class="modal-footer" style="padding: 12px 20px; background: #f8fafc; border-top: 1px solid #e2e8f0; display: flex; justify-content: flex-end; gap: 8px;">
                <button type="button" class="btn-modal-cancel" id="btn-cancel-energy-entry" style="padding: 8px 14px; font-size: 12px;">Скасувати</button>
                <button type="submit" class="btn-modal-submit" id="btn-submit-energy-entry" style="background: linear-gradient(135deg, #10b981, #059669); color: #fff; padding: 8px 16px; font-size: 12px; font-weight: 700; border: none; border-radius: 6px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
                    <i data-lucide="check" style="width: 14px; height: 14px;"></i>
                    <span>Зберегти показники</span>
                </button>
            </div>
        </form>
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
