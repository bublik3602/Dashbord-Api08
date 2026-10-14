<?php
/**
 * Сторінка «🌻 СЕМЕНА» — Автоматизоване зведення звітів з постачання соняшнику (Лист погодження 1С)
 * Об'єднаний веб-модуль програми закупівель з вкладками:
 * 1. Лист погодження (ієрархія Постачальник -> Специфікації)
 * 2. Невиконані специфікації (🔴 Не приїхало / 🟡 В процесі / 🟢 Виконані)
 * 3. Графік завезення (по днях) + діаграма
 * 4. Натуральна скидка (сор, волога)
 * 5. Реєстр прийомок (помашинно)
 * 6. Історія змін (Аудит 1С)
 */

require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/semena_helper.php';

// Авторизація та контроль доступу
$currentUser = Auth::requireAuth('semena');

// Завантаження оригінального або сформованого Excel-файлу Лист_погодження.xlsx
if (isset($_GET['download']) && $_GET['download'] === '1') {
    $xlsxPath = SemenaHelper::getXlsxPath();
    if (file_exists($xlsxPath)) {
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="Лист_погодження.xlsx"');
        header('Content-Length: ' . filesize($xlsxPath));
        header('Cache-Control: no-cache, must-revalidate');
        readfile($xlsxPath);
        exit;
    } else {
        http_response_code(404);
        echo "Файл Лист_погодження.xlsx ще не завантажено.";
        exit;
    }
}

$semenaData = SemenaHelper::loadData();
$reportDt   = $semenaData['report_datetime'] ?? '12.10.2026 09:34';
$updatedAt  = $semenaData['updated_at'] ?? date('d.m.Y H:i:s');
$summary    = $semenaData['summary'] ?? [];
$aggRows    = $semenaData['agg_rows'] ?? [];
$unfRows    = $semenaData['unf_rows'] ?? [];
$discRows   = $semenaData['disc_rows'] ?? [];
$dailyRows  = $semenaData['daily_rows'] ?? [];
$truckRows  = $semenaData['truck_rows'] ?? [];
$histRows   = $semenaData['history_rows'] ?? [];
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🌻 СЕМЕНА — Лист погодження та постачання соняшнику (1С)</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <style>
        :root {
            --navy-primary: #1F4E78;
            --navy-dark: #153656;
            --navy-light: #2C5E8A;
            --bg-main: #F4F6F9;
            --card-bg: #FFFFFF;
            --border-color: #D5DCE6;
            --text-dark: #1E293B;
            --text-muted: #5A6A85;
            --supplier-bg: #E8EEF5;
            --total-bg: #D9E1F2;
            --pos-bg: #EAF2E8;
            --pos-fg: #276A3C;
            --neg-bg: #FDECE8;
            --neg-fg: #A61C1C;
            --chg-bg: #FFF2CC;
            --chg-fg: #806000;
            --new-bg: #D9E1F2;
            --new-fg: #1F4E78;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', 'Segoe UI', sans-serif;
            background-color: var(--bg-main);
            color: var(--text-dark);
            font-size: 13px;
            line-height: 1.4;
            padding-bottom: 58px; /* місце під нижній бар деталей */
        }

        /* Верхня шапка (як у програмі app_gui.py) */
        .top-header {
            background: linear-gradient(135deg, var(--navy-primary), var(--navy-dark));
            color: #ffffff;
            padding: 12px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.15);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .header-title-block h1 {
            font-size: 18px;
            font-weight: 800;
            letter-spacing: -0.01em;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .header-subtitle {
            font-size: 11.5px;
            color: #B4C6E7;
            margin-top: 2px;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .btn-top {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 13px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            border: 1px solid transparent;
            transition: all 0.15s ease;
            white-space: nowrap;
        }

        .btn-dashboard {
            background: #0F172A;
            color: #F8FAFC;
            border-color: #334155;
        }
        .btn-dashboard:hover {
            background: #1E293B;
        }

        .btn-upload {
            background: #F59E0B;
            color: #ffffff;
            border-color: #D97706;
        }
        .btn-upload:hover {
            background: #D97706;
        }

        .btn-download {
            background: #27AE60;
            color: #ffffff;
            border-color: #1E8449;
        }
        .btn-download:hover {
            background: #1E8449;
        }

        .btn-history {
            background: #2C5E8A;
            color: #ffffff;
            border-color: #4878A6;
        }
        .btn-history:hover {
            background: #3772A6;
        }

        /* Панель KPI-підсумків */
        .kpi-strip {
            background: #ffffff;
            border-bottom: 1px solid var(--border-color);
            padding: 10px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
        }

        .kpi-pills {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }

        .kpi-pill {
            background: #F8FAFC;
            border: 1px solid #E2E8F0;
            border-radius: 6px;
            padding: 5px 10px;
            font-size: 12px;
            color: #334155;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .kpi-pill strong {
            color: #0F172A;
            font-weight: 800;
        }

        .kpi-pill.pill-date {
            background: #EBF5FB;
            border-color: #AED6F1;
            color: #1B4F72;
        }

        .kpi-pill.pill-unf {
            background: #FFF8E7;
            border-color: #F5CBA7;
        }

        /* Навігація вкладок (Notebook) */
        .tabs-nav {
            display: flex;
            gap: 4px;
            padding: 10px 20px 0 20px;
            background: #E8EEF5;
            border-bottom: 2px solid var(--navy-primary);
            overflow-x: auto;
        }

        .tab-btn {
            padding: 9px 16px;
            background: #D6E0EC;
            color: #2C3E50;
            border: 1px solid #B8C6D8;
            border-bottom: none;
            border-radius: 7px 7px 0 0;
            font-size: 12.5px;
            font-weight: 700;
            cursor: pointer;
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.15s;
        }

        .tab-btn:hover {
            background: #E3EBF4;
        }

        .tab-btn.active {
            background: var(--navy-primary);
            color: #ffffff;
            border-color: var(--navy-primary);
        }

        .tab-badge {
            font-size: 11px;
            padding: 1px 6px;
            border-radius: 10px;
            background: rgba(0, 0, 0, 0.12);
            color: inherit;
        }

        .tab-btn.active .tab-badge {
            background: rgba(255, 255, 255, 0.22);
            color: #ffffff;
        }

        /* Контейнер вкладки */
        .tab-pane {
            display: none;
            padding: 12px 20px;
        }

        .tab-pane.active {
            display: block;
        }

        /* Панель фільтрів над таблицею */
        .filter-bar {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 8px 12px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
        }

        .filter-left, .filter-right {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }

        .btn-sm {
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 11.5px;
            font-weight: 700;
            cursor: pointer;
            border: 1px solid #CBD5E1;
            background: #E8EEF5;
            color: var(--navy-primary);
        }
        .btn-sm:hover {
            background: #D6E2F0;
        }

        .search-wrap {
            display: inline-flex;
            align-items: center;
            border: 1px solid #CBD5E1;
            border-radius: 5px;
            background: #F8FAFC;
            padding: 0 6px;
        }

        .search-wrap input {
            border: none;
            background: transparent;
            padding: 5px 6px;
            font-size: 12px;
            width: 240px;
            outline: none;
            color: #0F172A;
        }

        .search-clear {
            border: none;
            background: transparent;
            color: #64748B;
            cursor: pointer;
            font-weight: 700;
            padding: 2px 4px;
        }
        .search-clear:hover {
            color: #DC2626;
        }

        .checkbox-label, .radio-label {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            user-select: none;
            padding: 4px 8px;
            border-radius: 4px;
            background: #F8FAFC;
            border: 1px solid #E2E8F0;
        }

        .checkbox-label:hover, .radio-label:hover {
            background: #F1F5F9;
        }

        /* Таблиці звіту */
        .table-container {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            overflow: auto;
            max-height: calc(100vh - 255px);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
        }

        table.report-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
            white-space: nowrap;
        }

        table.report-table thead th {
            position: sticky;
            top: 0;
            background: var(--navy-primary);
            color: #ffffff;
            font-weight: 700;
            padding: 8px 8px;
            border: 1px solid #163C5E;
            text-align: center;
            z-index: 10;
            font-size: 11.5px;
        }

        table.report-table tbody td {
            padding: 5px 8px;
            border: 1px solid #E2E8F0;
            color: #1E293B;
        }

        table.report-table tfoot td {
            position: sticky;
            bottom: 0;
            background: var(--total-bg);
            color: #0F172A;
            font-weight: 800;
            padding: 7px 8px;
            border: 1px solid #94A3B8;
            z-index: 9;
        }

        .text-left { text-align: left !important; }
        .text-right { text-align: right !important; }
        .text-center { text-align: center !important; }
        .font-bold { font-weight: 700 !important; }

        /* Кольорові теги рядків як у app_gui.py */
        tr.supplier-row {
            background-color: var(--supplier-bg);
            font-weight: 700;
            color: #1B365D;
            cursor: pointer;
        }
        tr.supplier-row:hover td {
            background-color: #DCE6F2 !important;
        }

        tr.tag-positive td {
            background-color: var(--pos-bg);
            color: var(--pos-fg);
        }
        tr.tag-negative td {
            background-color: var(--neg-bg);
            color: var(--neg-fg);
        }
        tr.tag-changed td {
            background-color: var(--chg-bg);
            color: var(--chg-fg);
        }
        tr.tag-new td {
            background-color: var(--new-bg);
            color: var(--new-fg);
            font-weight: 600;
        }
        tr.tag-even td {
            background-color: #FFFFFF;
        }
        tr.tag-odd td {
            background-color: #F9FBFD;
        }

        /* Статуси невиконаних */
        tr.unf-red td {
            background-color: #FCE4D6;
            color: #900C3F;
        }
        tr.unf-yellow td {
            background-color: #FFF2CC;
            color: #7D6608;
        }
        tr.unf-green td {
            background-color: #E2EFDA;
            color: #1E8449;
        }

        /* Кольори натуральної скидки */
        tr.disc-both td {
            background-color: #FADBD8;
            color: #78281F;
        }
        tr.disc-trash td {
            background-color: #FCE4D6;
            color: #78281F;
        }
        tr.disc-moist td {
            background-color: #FFF2CC;
            color: #7D6608;
        }

        /* Виділення активного рядка */
        table.report-table tbody tr.selected-row td {
            background-color: #2C5E8A !important;
            color: #ffffff !important;
        }

        table.report-table tbody tr:not(.supplier-row):hover td {
            filter: brightness(0.96);
            cursor: pointer;
        }

        /* Нижня панель швидкого перегляду рядка (як у app_gui.py) */
        .detail-footer-bar {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: #EDF2F8;
            border-top: 2px solid var(--navy-primary);
            padding: 8px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            z-index: 120;
            box-shadow: 0 -2px 8px rgba(0,0,0,0.08);
        }

        .detail-footer-text {
            font-size: 12px;
            color: #1B365D;
            font-weight: 600;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            flex: 1;
        }

        /* Модальне вікно деталей та завантаження */
        .modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 300;
            padding: 16px;
        }
        .modal-backdrop.open {
            display: flex;
        }

        .modal-box {
            background: #ffffff;
            border-radius: 8px;
            width: 100%;
            max-width: 720px;
            max-height: 88vh;
            display: flex;
            flex-direction: column;
            box-shadow: 0 20px 35px rgba(0, 0, 0, 0.25);
            overflow: hidden;
        }

        .modal-header {
            background: var(--navy-primary);
            color: #ffffff;
            padding: 12px 18px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-weight: 700;
            font-size: 14px;
        }

        .modal-close {
            background: transparent;
            border: none;
            color: #ffffff;
            font-size: 18px;
            cursor: pointer;
            font-weight: 700;
        }

        .modal-body {
            padding: 16px 18px;
            overflow-y: auto;
            font-size: 13px;
        }

        .modal-footer {
            padding: 10px 18px;
            background: #F4F6F9;
            border-top: 1px solid #E2E8F0;
            display: flex;
            justify-content: flex-end;
            gap: 8px;
        }

        .detail-kv-row {
            display: grid;
            grid-template-columns: 220px 1fr;
            padding: 6px 8px;
            border-bottom: 1px solid #F1F5F9;
        }
        .detail-kv-row:nth-child(even) {
            background: #F8FAFC;
        }
        .detail-kv-key {
            font-weight: 700;
            color: var(--navy-primary);
        }
        .detail-kv-val {
            color: #0F172A;
            word-break: break-word;
        }
        .detail-kv-row.highlight {
            background: #FFF2CC;
        }

        /* Розділ графіка завезення */
        .daily-split-layout {
            display: grid;
            grid-template-columns: 1fr;
            gap: 12px;
        }

        .chart-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 12px 16px;
        }
    </style>
</head>
<body>

    <!-- ВЕРХНЯ ШАПКА -->
    <header class="top-header">
        <div class="header-title-block">
            <h1>🌻 Лист погодження — Звіт з постачання соняшнику (1С)</h1>
            <div class="header-subtitle">
                Автоматизоване зведення звітів з постачання соняшнику &bull; Толеранс виконання: 25 т або 10% &bull; Захист від застарілих даних 1С
            </div>
        </div>
        <div class="header-actions">
            <?php if (Auth::hasAccess('dashboard')): ?>
            <a href="index.php" class="btn-top btn-dashboard" title="Повернутися на головний дашборд заводу">
                🏭 На головний дашборд
            </a>
            <?php endif; ?>
            <?php if (Auth::canUpload()): ?>
            <button type="button" class="btn-top btn-upload" onclick="openUploadModal()" title="Завантажити 2 файли з 1С: Приход (prihod.xlsx) + Специфікація (spec.xlsx) для автоматичного формування Листа погодження">
                ⚡ Завантажити Приход + Специфікацію (1С)
            </button>
            <?php endif; ?>
            <a href="semena.php?download=1" class="btn-top btn-download" title="Скачати повний Excel-файл Лист_погодження.xlsx з усіма вкладками">
                📥 Скачати Лист_погодження.xlsx
            </a>
            <button type="button" class="btn-top btn-history" onclick="switchTab('tab-history')">
                📜 Історія змін (<?= count($histRows) ?>)
            </button>
            <span class="btn-top" style="background: rgba(255, 255, 255, 0.12); border: 1px solid rgba(255, 255, 255, 0.25); color: #fff; cursor: default; font-weight: 600;">
                👤 <?= htmlspecialchars($currentUser['username'] ?? 'semena') ?>
            </span>
            <a href="logout.php" class="btn-top" style="background: #ef4444; color: #fff; text-decoration: none; border: 1px solid #dc2626;" title="Вийти з облікового запису">
                🚪 Вийти
            </a>
        </div>
    </header>

    <!-- СТРІЧКА ПІДСУМКІВ (KPI) -->
    <div class="kpi-strip">
        <div class="kpi-pills">
            <span class="kpi-pill pill-date">
                📅 Дані 1С: <strong><?= htmlspecialchars($reportDt) ?> ✓</strong>
            </span>
            <span class="kpi-pill">
                📋 Специфікацій: <strong><?= (int)($summary['total_specs'] ?? count($aggRows)) ?></strong>
            </span>
            <span class="kpi-pill">
                🏢 Контрагентів: <strong><?= (int)($summary['unique_suppliers'] ?? count($discRows)) ?></strong>
            </span>
            <span class="kpi-pill">
                ⚖️ Договірна вага: <strong><?= number_format((float)($summary['total_contract_t'] ?? 0), 2, ',', ' ') ?> т</strong>
            </span>
            <span class="kpi-pill">
                🚚 Завезено (фіз.): <strong style="color:#0284C7;"><?= number_format((float)($summary['total_delivered_t'] ?? 0), 2, ',', ' ') ?> т</strong>
            </span>
            <span class="kpi-pill">
                🌾 Залікова вага: <strong style="color:#1E8449;"><?= number_format((float)($summary['total_accounting_t'] ?? 0), 2, ',', ' ') ?> т</strong>
            </span>
            <span class="kpi-pill">
                📉 Скидка: <strong style="color:#C0392B;"><?= number_format((float)($summary['total_diff_t'] ?? 0), 2, ',', ' ') ?> т</strong>
            </span>
            <span class="kpi-pill">
                🚛 Автомашин: <strong><?= (int)($summary['total_trucks'] ?? count($truckRows)) ?></strong>
            </span>
            <span class="kpi-pill pill-unf">
                ⏳ Статус:
                <strong style="color:#C0392B;">🔴 <?= (int)($summary['not_started_count'] ?? 0) ?> Не приїхало</strong> &bull;
                <strong style="color:#B7950B;">🟡 <?= (int)($summary['in_progress_count'] ?? 0) ?> У процесі</strong> &bull;
                <strong style="color:#1E8449;">🟢 <?= (int)($summary['fulfilled_count'] ?? 0) ?> Виконано</strong>
            </span>
        </div>
    </div>

    <!-- ВКЛАДКИ ЗВІТУ (6 ВКЛАДОК ЯК У ПРОГРАМІ) -->
    <nav class="tabs-nav">
        <button type="button" class="tab-btn active" data-tab="tab-agg" onclick="switchTab('tab-agg')">
            📋 Лист погодження <span class="tab-badge"><?= count($aggRows) ?> спец.</span>
        </button>
        <button type="button" class="tab-btn" data-tab="tab-unf" onclick="switchTab('tab-unf')">
            ⏳ Невиконані специфікації <span class="tab-badge">🔴 <?= (int)($summary['not_started_count'] ?? 0) ?> / 🟡 <?= (int)($summary['in_progress_count'] ?? 0) ?></span>
        </button>
        <button type="button" class="tab-btn" data-tab="tab-daily" onclick="switchTab('tab-daily')">
            📈 Графік завезення (по днях) <span class="tab-badge"><?= count($dailyRows) ?> діб</span>
        </button>
        <button type="button" class="tab-btn" data-tab="tab-disc" onclick="switchTab('tab-disc')">
            🌾 Натуральна скидка (сор, волога) <span class="tab-badge"><?= count($discRows) ?> контр.</span>
        </button>
        <button type="button" class="tab-btn" data-tab="tab-trucks" onclick="switchTab('tab-trucks')">
            🚛 Реєстр прийомок (помашинно) <span class="tab-badge"><?= count($truckRows) ?> авто</span>
        </button>
        <button type="button" class="tab-btn" data-tab="tab-history" onclick="switchTab('tab-history')">
            📜 Історія змін (Аудит 1С) <span class="tab-badge"><?= count($histRows) ?></span>
        </button>
    </nav>

    <!-- =========================================================================
         ВКЛАДКА 1: 📋 ЛИСТ ПОГОДЖЕННЯ (ІЄРАРХІЯ ПОСТАЧАЛЬНИК -> СПЕЦИФІКАЦІЇ)
         ========================================================================= -->
    <section id="tab-agg" class="tab-pane active">
        <div class="filter-bar">
            <div class="filter-left">
                <button type="button" class="btn-sm" onclick="toggleAllSuppliers(true)">➕ Розгорнути всі</button>
                <button type="button" class="btn-sm" onclick="toggleAllSuppliers(false)">➖ Згорнути всі</button>
                <div class="search-wrap">
                    <span>🔍</span>
                    <input type="text" id="agg-search" placeholder="Пошук: контрагент, спец., менеджер..." oninput="renderAggTable()">
                    <button type="button" class="search-clear" onclick="document.getElementById('agg-search').value=''; renderAggTable();">✕</button>
                </div>
                <label class="checkbox-label">
                    <input type="checkbox" id="agg-oil-dev" onchange="renderAggTable()">
                    <span>🔍 Відхилення олійності &ge; 1.0%</span>
                </label>
            </div>
            <div class="filter-right">
                <span id="agg-filter-status" style="font-weight:700; color:var(--navy-primary);"></span>
            </div>
        </div>

        <div class="table-container">
            <table class="report-table" id="table-agg">
                <thead>
                    <tr>
                        <th class="text-left">Постачальник / Специфікація</th>
                        <th>Специфікація</th>
                        <th>Дата</th>
                        <th>Менеджер</th>
                        <th>Умови</th>
                        <th>Договір, т</th>
                        <th>Завезено, т</th>
                        <th>Залікова, т</th>
                        <th>Різниця, т</th>
                        <th>Ціна з дост.</th>
                        <th>Ціна без дост.</th>
                        <th>Масло База</th>
                        <th>Волога, %</th>
                        <th>Сор, %</th>
                        <th>Факт олійн.</th>
                        <th>Разница</th>
                        <th class="text-left">Примітка (Аудит змін)</th>
                    </tr>
                </thead>
                <tbody id="tbody-agg"></tbody>
                <tfoot id="tfoot-agg"></tfoot>
            </table>
        </div>
    </section>

    <!-- =========================================================================
         ВКЛАДКА 2: ⏳ НЕВИКОНАНІ СПЕЦИФІКАЦІЇ
         ========================================================================= -->
    <section id="tab-unf" class="tab-pane">
        <div class="filter-bar">
            <div class="filter-left">
                <span style="font-weight:700; color:var(--navy-primary);">Фільтр статусу:</span>
                <label class="radio-label">
                    <input type="radio" name="unf-mode" value="unfulfilled" checked onchange="renderUnfTable()">
                    <span>Всі невиконані</span>
                </label>
                <label class="radio-label">
                    <input type="radio" name="unf-mode" value="not_started" onchange="renderUnfTable()">
                    <span>🔴 Не приїхало (0%)</span>
                </label>
                <label class="radio-label">
                    <input type="radio" name="unf-mode" value="in_progress" onchange="renderUnfTable()">
                    <span>🟡 У стадії виконання</span>
                </label>
                <label class="radio-label">
                    <input type="radio" name="unf-mode" value="fulfilled" onchange="renderUnfTable()">
                    <span>🟢 Виконані (толеранс &le;25т / &le;10%)</span>
                </label>
                <label class="radio-label">
                    <input type="radio" name="unf-mode" value="all" onchange="renderUnfTable()">
                    <span>Всі договори</span>
                </label>
                <div class="search-wrap">
                    <span>🔍</span>
                    <input type="text" id="unf-search" placeholder="Контрагент, специфікація, менеджер..." oninput="renderUnfTable()">
                    <button type="button" class="search-clear" onclick="document.getElementById('unf-search').value=''; renderUnfTable();">✕</button>
                </div>
            </div>
            <div class="filter-right">
                <span id="unf-summary-lbl" style="font-weight:700; color:#806000;"></span>
            </div>
        </div>

        <div class="table-container">
            <table class="report-table" id="table-unf">
                <thead>
                    <tr>
                        <th>№</th>
                        <th class="text-left">Контрагент (Постачальник)</th>
                        <th>Специфікація</th>
                        <th>Дата спец.</th>
                        <th>Менеджер</th>
                        <th>Умови</th>
                        <th>Договір, т</th>
                        <th>Завезено, т</th>
                        <th>Залишок довезти, т</th>
                        <th>% викон.</th>
                        <th>Ціна з дост.</th>
                        <th class="text-left">Статус виконання</th>
                    </tr>
                </thead>
                <tbody id="tbody-unf"></tbody>
                <tfoot id="tfoot-unf"></tfoot>
            </table>
        </div>
    </section>

    <!-- =========================================================================
         ВКЛАДКА 3: 📈 ГРАФІК ЗАВЕЗЕННЯ (ПО ДНЯХ)
         ========================================================================= -->
    <section id="tab-daily" class="tab-pane">
        <div class="filter-bar">
            <div class="filter-left">
                <span style="font-weight:800; color:var(--navy-primary); font-size:13px;">
                    📈 Динаміка щоденного завезення соняшнику та середньозважені показники якості по днях
                </span>
            </div>
            <div class="filter-right">
                <span style="font-weight:700; color:#1E8449;">
                    Пікові дні (&ge; 800 т/добу) виділені темно-зеленим &bull; Пунктир — середній завіз за добу
                </span>
            </div>
        </div>

        <div class="daily-split-layout">
            <div class="chart-card">
                <div id="daily-intake-chart" style="min-height: 290px;"></div>
            </div>

            <div class="table-container" style="max-height: 420px;">
                <table class="report-table" id="table-daily">
                    <thead>
                        <tr>
                            <th>№</th>
                            <th>Дата прийомки</th>
                            <th>День тижня</th>
                            <th>Кількість авто</th>
                            <th>Фізичний завіз (т)</th>
                            <th>Залікова вага (т)</th>
                            <th>Натуральна скидка (т)</th>
                            <th>Скидка (%)</th>
                            <th>Олійність факт (%)</th>
                            <th>Вологість факт (%)</th>
                            <th>Сорність факт (%)</th>
                        </tr>
                    </thead>
                    <tbody id="tbody-daily"></tbody>
                    <tfoot id="tfoot-daily"></tfoot>
                </table>
            </div>
        </div>
    </section>

    <!-- =========================================================================
         ВКЛАДКА 4: 🌾 НАТУРАЛЬНА СКИДКА (СОР, ВОЛОГА)
         ========================================================================= -->
    <section id="tab-disc" class="tab-pane">
        <div class="filter-bar">
            <div class="filter-left">
                <div class="search-wrap">
                    <span>🔍</span>
                    <input type="text" id="disc-search" placeholder="Пошук контрагента..." oninput="renderDiscTable()">
                    <button type="button" class="search-clear" onclick="document.getElementById('disc-search').value=''; renderDiscTable();">✕</button>
                </div>
                <span style="font-size:11.5px; color:#475569;">
                    Легенда:
                    <span style="background:#FFF2CC; padding:2px 6px; border-radius:4px; font-weight:600;">Вологість &gt; 8%</span>
                    <span style="background:#FCE4D6; padding:2px 6px; border-radius:4px; font-weight:600;">Сорність &gt; 3%</span>
                    <span style="background:#FADBD8; padding:2px 6px; border-radius:4px; font-weight:600;">Волога &gt; 8% та Сор &gt; 3%</span>
                </span>
            </div>
            <div class="filter-right">
                <span id="disc-summary-lbl" style="font-weight:700; color:#78281F;"></span>
            </div>
        </div>

        <div class="table-container">
            <table class="report-table" id="table-disc">
                <thead>
                    <tr>
                        <th>№</th>
                        <th class="text-left">Контрагент (Постачальник)</th>
                        <th>К-ть авто</th>
                        <th>Завезено фіз. (т)</th>
                        <th>Залікова вага (т)</th>
                        <th>Скидка / Втрата ваги (т)</th>
                        <th>Скидка (%)</th>
                        <th>Сер. вологість (%)</th>
                        <th>Сер. сорність (%)</th>
                        <th>Сер. олійність (%)</th>
                    </tr>
                </thead>
                <tbody id="tbody-disc"></tbody>
                <tfoot id="tfoot-disc"></tfoot>
            </table>
        </div>
    </section>

    <!-- =========================================================================
         ВКЛАДКА 5: 🚛 РЕЄСТР ПРИЙОМОК (ПОМАШИННО)
         ========================================================================= -->
    <section id="tab-trucks" class="tab-pane">
        <div class="filter-bar">
            <div class="filter-left">
                <div class="search-wrap">
                    <span>🔍</span>
                    <input type="text" id="trucks-search" placeholder="Номер авто, ТТН, контрагент, дата..." oninput="renderTrucksTable()">
                    <button type="button" class="search-clear" onclick="document.getElementById('trucks-search').value=''; renderTrucksTable();">✕</button>
                </div>
                <span style="font-size:11.5px; color:#5A6A85;">(наприклад: АР 1662 ЕК, ТОВ, 10.10.2026)</span>
            </div>
            <div class="filter-right">
                <span id="trucks-summary-lbl" style="font-weight:700; color:var(--navy-primary);"></span>
            </div>
        </div>

        <div class="table-container">
            <table class="report-table" id="table-trucks">
                <thead>
                    <tr>
                        <th>№</th>
                        <th class="text-left">Назва Контрагента</th>
                        <th>Специфікація</th>
                        <th>№ Авто</th>
                        <th>ТТН / Док</th>
                        <th>Дата</th>
                        <th>Менеджер</th>
                        <th>Умови</th>
                        <th>Завезено, т</th>
                        <th>Залікова, т</th>
                        <th>Різниця, т</th>
                        <th>Ціна з дост.</th>
                        <th>Ціна без дост.</th>
                        <th>Масло База</th>
                        <th>Волога, %</th>
                        <th>Сор, %</th>
                        <th>Факт олійн.</th>
                        <th>Разница</th>
                    </tr>
                </thead>
                <tbody id="tbody-trucks"></tbody>
                <tfoot id="tfoot-trucks"></tfoot>
            </table>
        </div>
    </section>

    <!-- =========================================================================
         ВКЛАДКА 6: 📜 ІСТОРІЯ ЗМІН (АУДИТ 1С)
         ========================================================================= -->
    <section id="tab-history" class="tab-pane">
        <div class="filter-bar">
            <div class="filter-left">
                <div class="search-wrap">
                    <span>🔍</span>
                    <input type="text" id="hist-search" placeholder="Пошук змін: контрагент, спец., деталі..." oninput="renderHistTable()">
                    <button type="button" class="search-clear" onclick="document.getElementById('hist-search').value=''; renderHistTable();">✕</button>
                </div>
                <select id="hist-cat-filter" onchange="renderHistTable()" style="padding:5px 10px; border:1px solid #CBD5E1; border-radius:5px; font-size:12px; font-weight:600;">
                    <option value="">Всі категорії змін</option>
                    <option value="Новий">🆕 Новий договір</option>
                    <option value="Зміна ціни">💰 Зміна ціни</option>
                    <option value="Вага">⚖️ Вага / Завіз</option>
                </select>
            </div>
            <div class="filter-right">
                <span id="hist-summary-lbl" style="font-weight:700; color:var(--navy-primary);"></span>
            </div>
        </div>

        <div class="table-container">
            <table class="report-table" id="table-hist">
                <thead>
                    <tr>
                        <th>Дата / Час</th>
                        <th class="text-left">Контрагент</th>
                        <th>Специфікація</th>
                        <th>Менеджер</th>
                        <th>Категорія</th>
                        <th class="text-left">Деталі змін (Було &rarr; Стало)</th>
                    </tr>
                </thead>
                <tbody id="tbody-hist"></tbody>
            </table>
        </div>
    </section>

    <!-- НИЖНЯ ПАНЕЛЬ ДЕТАЛЕЙ РЯДКА -->
    <div class="detail-footer-bar">
        <div class="detail-footer-text" id="bottom-detail-text">
            📌 Клікніть на будь-який рядок таблиці для перегляду повної інформації (подвійний клік відкриває детальне вікно)
        </div>
        <button type="button" class="btn-sm" onclick="openDetailModal()">
            🔍 Відкрити повне вікно
        </button>
    </div>

    <!-- МОДАЛЬНЕ ВІКНО ДЕТАЛЬНОЇ ІНФОРМАЦІЇ ПРО РЯДОК -->
    <div class="modal-backdrop" id="detail-modal">
        <div class="modal-box">
            <div class="modal-header">
                <span id="detail-modal-title">📌 Детальна інформація</span>
                <button type="button" class="modal-close" onclick="closeDetailModal()">&times;</button>
            </div>
            <div class="modal-body" id="detail-modal-body"></div>
            <div class="modal-footer">
                <button type="button" class="btn-top btn-history" id="btn-copy-detail" onclick="copyDetailInfo()">
                    📋 Копіювати всю інформацію
                </button>
                <button type="button" class="btn-sm" onclick="closeDetailModal()">Закрити</button>
            </div>
        </div>
    </div>

    <?php if (Auth::canUpload()): ?>
    <!-- МОДАЛЬНЕ ВІКНО ОНОВЛЕННЯ ЗВІТУ (2 ФАЙЛИ 1С: ПРИХОД + СПЕЦИФІКАЦІЯ АБО ГОТОВИЙ ЛИСТ_ПОГОДЖЕННЯ) -->
    <div class="modal-backdrop" id="upload-modal">
        <div class="modal-box" style="max-width: 640px;">
            <div class="modal-header">
                <span>⚡ Автоматичне зведення звіту з 2 файлів 1С (Приход + Специфікація)</span>
                <button type="button" class="modal-close" onclick="closeUploadModal()">&times;</button>
            </div>
            <div class="modal-body">
                <p style="margin-bottom: 12px; color: #334155; font-size: 12.5px;">
                    Як у десктопній програмі закупівель, вивантажте з 1С два звіти у форматі Excel (<code>.xlsx</code>) та оберіть їх нижче — сайт сам автоматично зведе <strong>Лист погодження</strong>, перевірить зміну цін/ваги, порахує невиконані договори і оновить головний дашборд заводу без жодних сторонніх програм.
                </p>

                <div style="display: grid; grid-template-columns: 1fr; gap: 10px; margin-bottom: 12px;">
                    <!-- ФАЙЛ 1: ПРИХОД 1С -->
                    <div style="background:#F8FAFC; border:1px solid #CBD5E1; border-left:4px solid #1F4E78; border-radius:6px; padding:10px 12px;">
                        <label style="display:block; font-weight:800; color:#1F4E78; margin-bottom:4px; font-size:12.5px;">
                            📥 Файл 1 — Приход кормов и добавок 1С (Реєстр прийомок, напр. <code>prihod13-10.xlsx</code>):
                        </label>
                        <div style="font-size:11.5px; color:#64748B; margin-bottom:6px;">
                            Містить помашинний реєстр прийомок на завод, фізичну/залікову вагу, вологу, сорність та фактичну олійність (можна обрати одразу обидва файли).
                        </div>
                        <input type="file" id="prihod-file-input" accept=".xlsx,.xls,.csv" multiple style="width:100%; padding:7px; border:1px dashed #64748B; border-radius:5px; background:#ffffff; cursor:pointer; font-size:12px;">
                    </div>

                    <!-- ФАЙЛ 2: СПЕЦИФІКАЦІЯ 1С -->
                    <div style="background:#F8FAFC; border:1px solid #CBD5E1; border-left:4px solid #D97706; border-radius:6px; padding:10px 12px;">
                        <label style="display:block; font-weight:800; color:#B45309; margin-bottom:4px; font-size:12.5px;">
                            📋 Файл 2 — Специфікація 1С (Реєстр договорів, напр. <code>spec13-10.xlsx</code>):
                        </label>
                        <div style="font-size:11.5px; color:#64748B; margin-bottom:6px;">
                            Містить договірний тоннаж, ціну з доставкою / без доставки (з місця), менеджера та базову олійність.
                        </div>
                        <input type="file" id="spec-file-input" accept=".xlsx,.xls,.csv" multiple style="width:100%; padding:7px; border:1px dashed #D97706; border-radius:5px; background:#ffffff; cursor:pointer; font-size:12px;">
                    </div>
                </div>

                <div style="background:#EBF5FB; border:1px solid #AED6F1; border-radius:6px; padding:9px 12px; margin-bottom:10px; font-size:12px; color:#1B4F72; display:flex; flex-direction:column; gap:6px;">
                    <div>
                        🛡️ <strong>Захист від застарілих даних 1С:</strong> поточна актуальність звіту — <strong><?= htmlspecialchars($reportDt) ?></strong>. Якщо випадково обрати старіший файл «Приход», система заблокує перезапис.
                    </div>
                    <label style="display:inline-flex; align-items:center; gap:6px; cursor:pointer; font-weight:700; color:#0F172A; margin-top:2px;">
                        <input type="checkbox" id="allow-older-checkbox">
                        <span>Дозволити старішу дату прийомок (вимкнути захист часу для цього оновлення)</span>
                    </label>
                </div>

                <details style="margin-top:8px; font-size:12px; color:#475569;">
                    <summary style="cursor:pointer; font-weight:700; color:#1F4E78;">📂 Або завантажити вже готовий зведений файл «Лист_погодження.xlsx»</summary>
                    <div style="margin-top:8px; padding:8px; background:#F1F5F9; border-radius:6px;">
                        <input type="file" id="semena-file-input" accept=".xlsx,.xls" multiple style="width:100%; padding:6px; border:1px dashed #94A3B8; border-radius:5px; background:#ffffff; cursor:pointer; font-size:12px;">
                    </div>
                </details>

                <div id="upload-status-box" style="margin-top:12px; display:none; padding:10px; border-radius:6px; font-size:12.5px;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-top btn-upload" id="btn-submit-upload" onclick="submitSemenaUpload()">
                    ⚡ Сформувати та оновити Лист погодження
                </button>
                <button type="button" class="btn-sm" onclick="closeUploadModal()">Скасувати</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script>
        // Всі дані звіту з PHP
        const AGG_ROWS   = <?= json_encode($aggRows, JSON_UNESCAPED_UNICODE) ?>;
        const UNF_ROWS   = <?= json_encode($unfRows, JSON_UNESCAPED_UNICODE) ?>;
        const DISC_ROWS  = <?= json_encode($discRows, JSON_UNESCAPED_UNICODE) ?>;
        const DAILY_ROWS = <?= json_encode($dailyRows, JSON_UNESCAPED_UNICODE) ?>;
        const TRUCK_ROWS = <?= json_encode($truckRows, JSON_UNESCAPED_UNICODE) ?>;
        const HIST_ROWS  = <?= json_encode($histRows, JSON_UNESCAPED_UNICODE) ?>;

        // Стан розгортання груп постачальників у вкладці 1
        const expandedSuppliers = {};
        let currentDetailTitle = '';
        let currentDetailPairs = [];
        let dailyChartInstance = null;

        function fmtNum(val, decimals = 2) {
            if (val === null || val === undefined || val === '') return '';
            const n = Number(val);
            if (isNaN(n)) return String(val);
            return n.toLocaleString('uk-UA', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
        }

        function fmtPrice(val) {
            if (val === null || val === undefined || val === '' || Number(val) === 0) return '';
            return Number(val).toLocaleString('uk-UA', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
        }

        function esc(str) {
            if (str === null || str === undefined) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }

        // Перемикання вкладок
        function switchTab(tabId) {
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.toggle('active', btn.getAttribute('data-tab') === tabId);
            });
            document.querySelectorAll('.tab-pane').forEach(pane => {
                pane.classList.toggle('active', pane.id === tabId);
            });
            if (tabId === 'tab-daily') {
                setTimeout(initDailyChart, 50);
            }
        }

        // Вибір рядка та показ у нижній панелі / модальному вікні
        function selectRow(trEl, title, pairs) {
            document.querySelectorAll('table.report-table tbody tr.selected-row').forEach(el => {
                el.classList.remove('selected-row');
            });
            if (trEl) trEl.classList.add('selected-row');
            currentDetailTitle = title;
            currentDetailPairs = pairs;

            const nonEmpty = pairs.filter(p => p[1] !== '' && p[1] !== '—');
            const preview = nonEmpty.map(p => `${p[0]}: ${p[1]}`).join('   |   ');
            document.getElementById('bottom-detail-text').textContent = `📌 ${preview}`;
        }

        function openDetailModal() {
            if (!currentDetailPairs.length) {
                alert('Спочатку клікніть на будь-який рядок у таблиці.');
                return;
            }
            document.getElementById('detail-modal-title').textContent = `📌 ${currentDetailTitle}`;
            const body = document.getElementById('detail-modal-body');
            body.innerHTML = currentDetailPairs.map(([k, v]) => {
                const isNote = k.includes('Примітка') || k.includes('Деталі') || k.includes('Статус');
                return `
                    <div class="detail-kv-row ${isNote && v ? 'highlight' : ''}">
                        <div class="detail-kv-key">${esc(k)}:</div>
                        <div class="detail-kv-val">${esc(v || '—')}</div>
                    </div>
                `;
            }).join('');
            document.getElementById('detail-modal').classList.add('open');
        }

        function closeDetailModal() {
            document.getElementById('detail-modal').classList.remove('open');
        }

        function copyDetailInfo() {
            const text = currentDetailPairs
                .filter(p => p[1] !== '')
                .map(p => `${p[0]}: ${p[1]}`)
                .join('\n');
            navigator.clipboard.writeText(text).then(() => {
                const btn = document.getElementById('btn-copy-detail');
                btn.textContent = '✅ Скопійовано в буфер!';
                setTimeout(() => { btn.textContent = '📋 Копіювати всю інформацію'; }, 2000);
            });
        }

        // =====================================================================
        // 1. ВІДМАЛЬОВКА ВКЛАДКИ «ЛИСТ ПОГОДЖЕННЯ» (ГРУПУВАННЯ ПО ПОСТАЧАЛЬНИКАХ)
        // =====================================================================
        function toggleAllSuppliers(expand) {
            AGG_ROWS.forEach(r => {
                expandedSuppliers[r.supplier || 'Без назви'] = expand;
            });
            renderAggTable();
        }

        function toggleSupplierGroup(supName) {
            expandedSuppliers[supName] = !expandedSuppliers[supName];
            renderAggTable();
        }

        function renderAggTable() {
            const q = (document.getElementById('agg-search').value || '').trim().toLowerCase();
            const onlyHighDev = document.getElementById('agg-oil-dev').checked;

            const filtered = AGG_ROWS.filter(r => {
                if (onlyHighDev) {
                    if (r.oil_diff === null || Math.abs(Number(r.oil_diff)) < 1.0) return false;
                }
                if (q) {
                    const hay = `${r.supplier} ${r.spec} ${r.spec_date} ${r.manager} ${r.terms} ${r.note}`.toLowerCase();
                    if (!hay.includes(q)) return false;
                }
                return true;
            });

            // Групування по постачальниках
            const groupsMap = new Map();
            filtered.forEach(r => {
                const sup = r.supplier || 'Без назви';
                if (!groupsMap.has(sup)) groupsMap.set(sup, []);
                groupsMap.get(sup).push(r);
            });

            const tbody = document.getElementById('tbody-agg');
            const htmlRows = [];

            let totContract = 0, totDeliv = 0, totAcc = 0, totDiff = 0;
            let wOil = 0, wMoist = 0, wTrash = 0, wWeight = 0;

            groupsMap.forEach((items, sup) => {
                if (expandedSuppliers[sup] === undefined) expandedSuppliers[sup] = true;
                const isOpen = (q !== '' || onlyHighDev) ? true : expandedSuppliers[sup];

                let supContract = 0, supDeliv = 0, supAcc = 0, supDiff = 0;
                const managers = new Set();
                const termsSet = new Set();
                let notesCount = 0;

                items.forEach(it => {
                    supContract += Number(it.contract_t || 0);
                    supDeliv += Number(it.delivered_t || 0);
                    supAcc += Number(it.accounting_t || 0);
                    supDiff += Number(it.weight_diff || 0);
                    if (it.manager) managers.add(it.manager);
                    if (it.terms) termsSet.add(it.terms);
                    if (it.note) notesCount++;

                    totContract += Number(it.contract_t || 0);
                    totDeliv += Number(it.delivered_t || 0);
                    totAcc += Number(it.accounting_t || 0);
                    totDiff += Number(it.weight_diff || 0);

                    const d = Number(it.delivered_t || 0);
                    if (d > 0 && it.oil_fact !== null) {
                        wOil += Number(it.oil_fact) * d;
                        wMoist += Number(it.moisture || 0) * d;
                        wTrash += Number(it.trash || 0) * d;
                        wWeight += d;
                    }
                });

                const icon = isOpen ? '▼' : '▶';
                const supTitle = `${icon} 🏢 ${sup} (${items.length} спец.)`;
                const supNote = notesCount > 0 ? `🔔 Змін: ${notesCount}` : '';

                htmlRows.push(`
                    <tr class="supplier-row" onclick="toggleSupplierGroup('${esc(sup).replace(/'/g, "\\'")}')">
                        <td class="text-left font-bold">${esc(supTitle)}</td>
                        <td>Усього: ${items.length} спец.</td>
                        <td></td>
                        <td>${esc(Array.from(managers).join(', '))}</td>
                        <td>${esc(Array.from(termsSet).join(', '))}</td>
                        <td class="text-right">${supContract > 0 ? fmtNum(supContract) : ''}</td>
                        <td class="text-right">${fmtNum(supDeliv)}</td>
                        <td class="text-right">${fmtNum(supAcc)}</td>
                        <td class="text-right">${fmtNum(supDiff)}</td>
                        <td></td><td></td><td></td><td></td><td></td><td></td><td></td>
                        <td class="text-left">${esc(supNote)}</td>
                    </tr>
                `);

                if (isOpen) {
                    items.forEach((r, idx) => {
                        let rowClass = (idx % 2 === 0) ? 'tag-even' : 'tag-odd';
                        const noteStr = r.note || '';
                        if (noteStr.includes('Новий')) {
                            rowClass = 'tag-new';
                        } else if (noteStr !== '') {
                            rowClass = 'tag-changed';
                        } else if (r.oil_diff !== null) {
                            if (Number(r.oil_diff) > 0) rowClass = 'tag-positive';
                            else if (Number(r.oil_diff) < 0) rowClass = 'tag-negative';
                        }

                        const oilDiffStr = (r.oil_diff !== null && r.oil_diff !== '')
                            ? ((Number(r.oil_diff) > 0 ? '+' : '') + fmtNum(r.oil_diff))
                            : '';

                        const rowPayload = encodeURIComponent(JSON.stringify([
                            ['Постачальник', r.supplier],
                            ['Специфікація', r.spec],
                            ['Дата специфікації', r.spec_date],
                            ['Менеджер', r.manager],
                            ['Умови поставки', r.terms],
                            ['Договірна вага (т)', r.contract_t !== null ? fmtNum(r.contract_t) : ''],
                            ['Завезено фізично (т)', fmtNum(r.delivered_t)],
                            ['Залікова вага (т)', fmtNum(r.accounting_t)],
                            ['Різниця ваги / скидка (т)', fmtNum(r.weight_diff)],
                            ['Ціна з доставкою (грн/т)', fmtPrice(r.price_with_deliv)],
                            ['Ціна без доставки з місця (грн/т)', fmtPrice(r.price_no_deliv)],
                            ['Масло База (%)', fmtNum(r.oil_base, 1)],
                            ['Вологість (%)', fmtNum(r.moisture)],
                            ['Сорність (%)', fmtNum(r.trash)],
                            ['Факт олійність (%)', fmtNum(r.oil_fact)],
                            ['Відхилення олійності (%)', oilDiffStr],
                            ['Примітка (Аудит змін)', r.note || 'Без змін']
                        ]));

                        htmlRows.push(`
                            <tr class="${rowClass}" onclick="handleRowClick(this, 'Специфікація ${esc(r.spec)}', '${rowPayload}')" ondblclick="openDetailModal()">
                                <td class="text-left" style="padding-left:24px;">↳ 📄 ${esc(r.spec || 'Без спец.')}</td>
                                <td class="text-center font-bold">${esc(r.spec)}</td>
                                <td class="text-center">${esc(r.spec_date)}</td>
                                <td class="text-center">${esc(r.manager)}</td>
                                <td class="text-center">${esc(r.terms)}</td>
                                <td class="text-right">${r.contract_t !== null ? fmtNum(r.contract_t) : ''}</td>
                                <td class="text-right font-bold">${fmtNum(r.delivered_t)}</td>
                                <td class="text-right">${fmtNum(r.accounting_t)}</td>
                                <td class="text-right">${fmtNum(r.weight_diff)}</td>
                                <td class="text-right">${fmtPrice(r.price_with_deliv)}</td>
                                <td class="text-right">${fmtPrice(r.price_no_deliv)}</td>
                                <td class="text-center">${fmtNum(r.oil_base, 1)}</td>
                                <td class="text-right">${fmtNum(r.moisture)}</td>
                                <td class="text-right">${fmtNum(r.trash)}</td>
                                <td class="text-right font-bold">${fmtNum(r.oil_fact)}</td>
                                <td class="text-right font-bold">${oilDiffStr}</td>
                                <td class="text-left">${esc(r.note)}</td>
                            </tr>
                        `);
                    });
                }
            });

            tbody.innerHTML = htmlRows.join('');

            const avgOil = wWeight > 0 ? (wOil / wWeight) : 0;
            const avgMoist = wWeight > 0 ? (wMoist / wWeight) : 0;
            const avgTrash = wWeight > 0 ? (wTrash / wWeight) : 0;
            const avgDiff = avgOil > 0 ? (avgOil - 48.0) : 0;

            document.getElementById('tfoot-agg').innerHTML = `
                <tr>
                    <td class="text-left">ВСЬОГО (${filtered.length} специфікацій / ${groupsMap.size} постачальників)</td>
                    <td></td><td></td><td></td><td></td>
                    <td class="text-right">${fmtNum(totContract)}</td>
                    <td class="text-right">${fmtNum(totDeliv)}</td>
                    <td class="text-right">${fmtNum(totAcc)}</td>
                    <td class="text-right">${fmtNum(totDiff)}</td>
                    <td></td><td></td>
                    <td class="text-center">48,0</td>
                    <td class="text-right">${wWeight > 0 ? fmtNum(avgMoist) : ''}</td>
                    <td class="text-right">${wWeight > 0 ? fmtNum(avgTrash) : ''}</td>
                    <td class="text-right">${wWeight > 0 ? fmtNum(avgOil) : ''}</td>
                    <td class="text-right">${wWeight > 0 ? ((avgDiff >= 0 ? '+' : '') + fmtNum(avgDiff)) : ''}</td>
                    <td></td>
                </tr>
            `;

            document.getElementById('agg-filter-status').textContent =
                `Показано: ${filtered.length} з ${AGG_ROWS.length} спец. (${groupsMap.size} контрагентів)`;
        }

        function handleRowClick(trEl, title, encodedPairs) {
            try {
                const pairs = JSON.parse(decodeURIComponent(encodedPairs));
                selectRow(trEl, title, pairs);
            } catch (e) {}
        }

        // =====================================================================
        // 2. ВІДМАЛЬОВКА ВКЛАДКИ «НЕВИКОНАНІ СПЕЦИФІКАЦІЇ»
        // =====================================================================
        function renderUnfTable() {
            const modeEl = document.querySelector('input[name="unf-mode"]:checked');
            const mode = modeEl ? modeEl.value : 'unfulfilled';
            const q = (document.getElementById('unf-search').value || '').trim().toLowerCase();

            const filtered = UNF_ROWS.filter(r => {
                if (mode === 'unfulfilled' && !(r.category === 'not_started' || r.category === 'in_progress')) return false;
                if (mode === 'not_started' && r.category !== 'not_started') return false;
                if (mode === 'in_progress' && r.category !== 'in_progress') return false;
                if (mode === 'fulfilled' && r.category !== 'fulfilled') return false;

                if (q) {
                    const hay = `${r.supplier} ${r.spec} ${r.spec_date} ${r.manager} ${r.terms} ${r.status}`.toLowerCase();
                    if (!hay.includes(q)) return false;
                }
                return true;
            });

            let sumContract = 0, sumDeliv = 0, sumRem = 0;
            const tbody = document.getElementById('tbody-unf');

            tbody.innerHTML = filtered.map((r, i) => {
                sumContract += Number(r.contract_t || 0);
                sumDeliv += Number(r.delivered_t || 0);
                sumRem += Number(r.remaining_t || 0);

                let cls = 'unf-green';
                if (r.category === 'not_started') cls = 'unf-red';
                else if (r.category === 'in_progress') cls = 'unf-yellow';

                const payload = encodeURIComponent(JSON.stringify([
                    ['№', i + 1],
                    ['Контрагент', r.supplier],
                    ['Специфікація', r.spec],
                    ['Дата специфікації', r.spec_date],
                    ['Менеджер', r.manager],
                    ['Умови', r.terms],
                    ['Договір (т)', fmtNum(r.contract_t)],
                    ['Завезено (т)', fmtNum(r.delivered_t)],
                    ['Залишок довезти (т)', fmtNum(r.remaining_t)],
                    ['% виконання', fmtNum(r.pct, 1) + '%'],
                    ['Ціна з доставкою', fmtPrice(r.price_with_deliv)],
                    ['Статус виконання', r.status]
                ]));

                return `
                    <tr class="${cls}" onclick="handleRowClick(this, 'Невиконана специфікація ${esc(r.spec)}', '${payload}')" ondblclick="openDetailModal()">
                        <td class="text-center">${i + 1}</td>
                        <td class="text-left font-bold">${esc(r.supplier)}</td>
                        <td class="text-center font-bold">${esc(r.spec)}</td>
                        <td class="text-center">${esc(r.spec_date)}</td>
                        <td class="text-center">${esc(r.manager)}</td>
                        <td class="text-center">${esc(r.terms)}</td>
                        <td class="text-right">${fmtNum(r.contract_t)}</td>
                        <td class="text-right font-bold">${fmtNum(r.delivered_t)}</td>
                        <td class="text-right font-bold">${fmtNum(r.remaining_t)}</td>
                        <td class="text-right font-bold">${fmtNum(r.pct, 1)}%</td>
                        <td class="text-right">${fmtPrice(r.price_with_deliv)}</td>
                        <td class="text-left font-bold">${esc(r.status)}</td>
                    </tr>
                `;
            }).join('');

            const totPct = sumContract > 0 ? (sumDeliv / sumContract * 100) : 0;
            document.getElementById('tfoot-unf').innerHTML = `
                <tr>
                    <td></td>
                    <td class="text-left">РАЗОМ ПО ВИБІРЦІ (${filtered.length} спец.)</td>
                    <td></td><td></td><td></td><td></td>
                    <td class="text-right">${fmtNum(sumContract)}</td>
                    <td class="text-right">${fmtNum(sumDeliv)}</td>
                    <td class="text-right">${fmtNum(sumRem)}</td>
                    <td class="text-right">${fmtNum(totPct, 1)}%</td>
                    <td></td>
                    <td class="text-left">Залишок довезти: ${fmtNum(sumRem)} т</td>
                </tr>
            `;

            document.getElementById('unf-summary-lbl').textContent =
                `Показано: ${filtered.length} спец. | Договір: ${fmtNum(sumContract)} т | Завезено: ${fmtNum(sumDeliv)} т | Залишок: ${fmtNum(sumRem)} т`;
        }

        // =====================================================================
        // 3. ВІДМАЛЬОВКА ВКЛАДКИ «ГРАФІК ЗАВЕЗЕННЯ (ПО ДНЯХ)»
        // =====================================================================
        function renderDailyTable() {
            let totTrucks = 0, totDeliv = 0, totAcc = 0, totDiff = 0;
            let wOil = 0, wMoist = 0, wTrash = 0;

            const tbody = document.getElementById('tbody-daily');
            tbody.innerHTML = DAILY_ROWS.map((r, i) => {
                const d = Number(r.delivered_t || 0);
                totTrucks += Number(r.trucks || 0);
                totDeliv += d;
                totAcc += Number(r.accounting_t || 0);
                totDiff += Number(r.diff_t || 0);

                wOil += Number(r.oil || 0) * d;
                wMoist += Number(r.moisture || 0) * d;
                wTrash += Number(r.trash || 0) * d;

                const isPeak = d >= 800;
                const rowClass = isPeak ? 'tag-positive' : (i % 2 === 0 ? 'tag-even' : 'tag-odd');

                const payload = encodeURIComponent(JSON.stringify([
                    ['Дата прийомки', r.date],
                    ['День тижня', r.weekday],
                    ['Кількість авто', r.trucks],
                    ['Фізичний завіз (т)', fmtNum(r.delivered_t)],
                    ['Залікова вага (т)', fmtNum(r.accounting_t)],
                    ['Натуральна скидка (т)', fmtNum(r.diff_t)],
                    ['Скидка (%)', fmtNum(r.diff_pct) + '%'],
                    ['Олійність факт (%)', fmtNum(r.oil) + '%'],
                    ['Вологість факт (%)', fmtNum(r.moisture) + '%'],
                    ['Сорність факт (%)', fmtNum(r.trash) + '%']
                ]));

                return `
                    <tr class="${rowClass}" onclick="handleRowClick(this, 'Завіз за ${esc(r.date)}', '${payload}')" ondblclick="openDetailModal()">
                        <td class="text-center">${r.num}</td>
                        <td class="text-center font-bold">${esc(r.date)}</td>
                        <td class="text-center">${esc(r.weekday)}</td>
                        <td class="text-center font-bold">${r.trucks}</td>
                        <td class="text-right font-bold">${fmtNum(r.delivered_t)}</td>
                        <td class="text-right">${fmtNum(r.accounting_t)}</td>
                        <td class="text-right">${fmtNum(r.diff_t)}</td>
                        <td class="text-right">${fmtNum(r.diff_pct)}%</td>
                        <td class="text-right font-bold">${fmtNum(r.oil)}%</td>
                        <td class="text-right">${fmtNum(r.moisture)}%</td>
                        <td class="text-right">${fmtNum(r.trash)}%</td>
                    </tr>
                `;
            }).join('');

            const totDiffPct = totDeliv > 0 ? (totDiff / totDeliv * 100) : 0;
            const avgOil = totDeliv > 0 ? (wOil / totDeliv) : 0;
            const avgMoist = totDeliv > 0 ? (wMoist / totDeliv) : 0;
            const avgTrash = totDeliv > 0 ? (wTrash / totDeliv) : 0;

            document.getElementById('tfoot-daily').innerHTML = `
                <tr>
                    <td></td>
                    <td class="text-center">РАЗОМ (${DAILY_ROWS.length} діб)</td>
                    <td></td>
                    <td class="text-center">${totTrucks} авто</td>
                    <td class="text-right">${fmtNum(totDeliv)} т</td>
                    <td class="text-right">${fmtNum(totAcc)} т</td>
                    <td class="text-right">${fmtNum(totDiff)} т</td>
                    <td class="text-right">${fmtNum(totDiffPct)}%</td>
                    <td class="text-right">${fmtNum(avgOil)}%</td>
                    <td class="text-right">${fmtNum(avgMoist)}%</td>
                    <td class="text-right">${fmtNum(avgTrash)}%</td>
                </tr>
            `;
        }

        function initDailyChart() {
            const el = document.getElementById('daily-intake-chart');
            if (!el || !DAILY_ROWS.length) return;
            if (dailyChartInstance) {
                dailyChartInstance.destroy();
            }

            const categories = DAILY_ROWS.map(r => r.date.slice(0, 5));
            const tonnesData = DAILY_ROWS.map(r => Number(r.delivered_t || 0));
            const trucksData = DAILY_ROWS.map(r => Number(r.trucks || 0));
            const colors = tonnesData.map(v => v >= 800 ? '#1E8449' : '#2E75B6');
            const avgDaily = tonnesData.reduce((a, b) => a + b, 0) / (tonnesData.length || 1);

            const options = {
                chart: {
                    type: 'bar',
                    height: 290,
                    fontFamily: 'Inter, sans-serif',
                    toolbar: { show: false }
                },
                series: [
                    { name: 'Фізичний завіз (т)', type: 'column', data: tonnesData },
                    { name: 'Кількість авто', type: 'line', data: trucksData }
                ],
                colors: ['#2E75B6', '#D97706'],
                plotOptions: {
                    bar: {
                        columnWidth: '68%',
                        borderRadius: 3,
                        distributed: false
                    }
                },
                dataLabels: {
                    enabled: true,
                    enabledOnSeries: [0],
                    formatter: function(val) {
                        return val >= 200 ? Math.round(val) : '';
                    },
                    style: { fontSize: '10px', colors: ['#1E293B'] },
                    offsetY: -16
                },
                stroke: { width: [0, 2.5], curve: 'smooth' },
                xaxis: {
                    categories: categories,
                    labels: { rotate: -45, style: { fontSize: '10px', fontWeight: 600 } }
                },
                yaxis: [
                    {
                        title: { text: 'Фізичний завіз (тонн)' },
                        labels: { formatter: v => Math.round(v) + ' т' }
                    },
                    {
                        opposite: true,
                        title: { text: 'Автомашин (шт.)' },
                        labels: { formatter: v => Math.round(v) + ' авто' }
                    }
                ],
                annotations: {
                    yaxis: [{
                        y: avgDaily,
                        borderColor: '#E67E22',
                        strokeDashArray: 5,
                        label: {
                            borderColor: '#E67E22',
                            style: { color: '#fff', background: '#E67E22', fontWeight: 700 },
                            text: `Середній завіз: ${fmtNum(avgDaily, 1)} т/добу`
                        }
                    }]
                },
                tooltip: {
                    shared: true,
                    intersect: false
                }
            };

            dailyChartInstance = new ApexCharts(el, options);
            dailyChartInstance.render();
        }

        // =====================================================================
        // 4. ВІДМАЛЬОВКА ВКЛАДКИ «НАТУРАЛЬНА СКИДКА (СОР, ВОЛОГА)»
        // =====================================================================
        function renderDiscTable() {
            const q = (document.getElementById('disc-search').value || '').trim().toLowerCase();
            const filtered = DISC_ROWS.filter(r => !q || (r.supplier || '').toLowerCase().includes(q));

            let totTrucks = 0, totDeliv = 0, totAcc = 0, totDiff = 0;
            let wMoist = 0, wTrash = 0, wOil = 0;

            const tbody = document.getElementById('tbody-disc');
            tbody.innerHTML = filtered.map((r, i) => {
                const d = Number(r.delivered_t || 0);
                const m = Number(r.moisture || 0);
                const t = Number(r.trash || 0);
                const o = Number(r.oil || 0);

                totTrucks += Number(r.trucks || 0);
                totDeliv += d;
                totAcc += Number(r.accounting_t || 0);
                totDiff += Number(r.diff_t || 0);

                wMoist += m * d;
                wTrash += t * d;
                wOil += o * d;

                let cls = (i % 2 === 0) ? 'tag-even' : 'tag-odd';
                if (m > 8.0 && t > 3.0) cls = 'disc-both';
                else if (t > 3.0) cls = 'disc-trash';
                else if (m > 8.0) cls = 'disc-moist';

                const payload = encodeURIComponent(JSON.stringify([
                    ['Рейтинг №', i + 1],
                    ['Контрагент (Постачальник)', r.supplier],
                    ['Кількість авто', r.trucks],
                    ['Завезено фізично (т)', fmtNum(r.delivered_t)],
                    ['Залікова вага (т)', fmtNum(r.accounting_t)],
                    ['Скидка / Втрата ваги (т)', fmtNum(r.diff_t)],
                    ['Скидка (%)', fmtNum(r.diff_pct) + '%'],
                    ['Середня вологість (%)', fmtNum(r.moisture) + '%'],
                    ['Середня сорність (%)', fmtNum(r.trash) + '%'],
                    ['Середня олійність (%)', fmtNum(r.oil) + '%']
                ]));

                return `
                    <tr class="${cls}" onclick="handleRowClick(this, 'Натуральна скидка: ${esc(r.supplier)}', '${payload}')" ondblclick="openDetailModal()">
                        <td class="text-center">${i + 1}</td>
                        <td class="text-left font-bold">${esc(r.supplier)}</td>
                        <td class="text-center">${r.trucks}</td>
                        <td class="text-right">${fmtNum(r.delivered_t)}</td>
                        <td class="text-right">${fmtNum(r.accounting_t)}</td>
                        <td class="text-right font-bold">${fmtNum(r.diff_t)}</td>
                        <td class="text-right font-bold">${fmtNum(r.diff_pct)}%</td>
                        <td class="text-right">${fmtNum(r.moisture)}%</td>
                        <td class="text-right">${fmtNum(r.trash)}%</td>
                        <td class="text-right font-bold">${fmtNum(r.oil)}%</td>
                    </tr>
                `;
            }).join('');

            const pct = totDeliv > 0 ? (totDiff / totDeliv * 100) : 0;
            document.getElementById('tfoot-disc').innerHTML = `
                <tr>
                    <td></td>
                    <td class="text-left">ВСЬОГО ПО ВИБІРЦІ (${filtered.length} контрагентів)</td>
                    <td class="text-center">${totTrucks}</td>
                    <td class="text-right">${fmtNum(totDeliv)}</td>
                    <td class="text-right">${fmtNum(totAcc)}</td>
                    <td class="text-right">${fmtNum(totDiff)}</td>
                    <td class="text-right">${fmtNum(pct)}%</td>
                    <td class="text-right">${totDeliv > 0 ? fmtNum(wMoist / totDeliv) + '%' : ''}</td>
                    <td class="text-right">${totDeliv > 0 ? fmtNum(wTrash / totDeliv) + '%' : ''}</td>
                    <td class="text-right">${totDeliv > 0 ? fmtNum(wOil / totDeliv) + '%' : ''}</td>
                </tr>
            `;

            document.getElementById('disc-summary-lbl').textContent =
                `Контрагентів: ${filtered.length} | Фіз. вага: ${fmtNum(totDeliv)} т | Залікова: ${fmtNum(totAcc)} т | Скидка: ${fmtNum(totDiff)} т (${fmtNum(pct)}%)`;
        }

        // =====================================================================
        // 5. ВІДМАЛЬОВКА ВКЛАДКИ «РЕЄСТР ПРИЙОМОК (ПОМАШИННО)»
        // =====================================================================
        function renderTrucksTable() {
            const q = (document.getElementById('trucks-search').value || '').trim().toLowerCase();
            const filtered = TRUCK_ROWS.filter(r => {
                if (!q) return true;
                const hay = `${r.supplier} ${r.spec} ${r.car_num} ${r.ttn} ${r.date} ${r.manager}`.toLowerCase();
                return hay.includes(q);
            });

            let sumDeliv = 0, sumAcc = 0, sumDiff = 0;
            const tbody = document.getElementById('tbody-trucks');

            tbody.innerHTML = filtered.map((r, i) => {
                sumDeliv += Number(r.delivered_t || 0);
                sumAcc += Number(r.accounting_t || 0);
                sumDiff += Number(r.weight_diff || 0);

                let cls = (i % 2 === 0) ? 'tag-even' : 'tag-odd';
                if (r.oil_diff !== null) {
                    if (Number(r.oil_diff) > 0) cls = 'tag-positive';
                    else if (Number(r.oil_diff) < 0) cls = 'tag-negative';
                }

                const oilDiffStr = (r.oil_diff !== null && r.oil_diff !== '')
                    ? ((Number(r.oil_diff) > 0 ? '+' : '') + fmtNum(r.oil_diff))
                    : '';

                const payload = encodeURIComponent(JSON.stringify([
                    ['№ п/п', r.num],
                    ['Контрагент', r.supplier],
                    ['Специфікація', r.spec],
                    ['№ Авто', r.car_num],
                    ['ТТН / Документ', r.ttn],
                    ['Дата прийомки', r.date],
                    ['Менеджер', r.manager],
                    ['Умови', r.terms],
                    ['Завезено фіз. (т)', fmtNum(r.delivered_t)],
                    ['Залікова вага (т)', fmtNum(r.accounting_t)],
                    ['Різниця ваги (т)', fmtNum(r.weight_diff)],
                    ['Ціна з доставкою', fmtPrice(r.price_with_deliv)],
                    ['Ціна без доставки', fmtPrice(r.price_no_deliv)],
                    ['Масло База (%)', fmtNum(r.oil_base, 1)],
                    ['Вологість (%)', fmtNum(r.moisture)],
                    ['Сорність (%)', fmtNum(r.trash)],
                    ['Факт олійність (%)', fmtNum(r.oil_fact)],
                    ['Відхилення олійності (%)', oilDiffStr]
                ]));

                return `
                    <tr class="${cls}" onclick="handleRowClick(this, 'Авто ${esc(r.car_num)} (${esc(r.date)})', '${payload}')" ondblclick="openDetailModal()">
                        <td class="text-center">${r.num}</td>
                        <td class="text-left font-bold">${esc(r.supplier)}</td>
                        <td class="text-center">${esc(r.spec)}</td>
                        <td class="text-center font-bold">${esc(r.car_num)}</td>
                        <td class="text-center">${esc(r.ttn)}</td>
                        <td class="text-center">${esc(r.date)}</td>
                        <td class="text-center">${esc(r.manager)}</td>
                        <td class="text-center">${esc(r.terms)}</td>
                        <td class="text-right font-bold">${fmtNum(r.delivered_t)}</td>
                        <td class="text-right">${fmtNum(r.accounting_t)}</td>
                        <td class="text-right">${fmtNum(r.weight_diff)}</td>
                        <td class="text-right">${fmtPrice(r.price_with_deliv)}</td>
                        <td class="text-right">${fmtPrice(r.price_no_deliv)}</td>
                        <td class="text-center">${fmtNum(r.oil_base, 1)}</td>
                        <td class="text-right">${fmtNum(r.moisture)}</td>
                        <td class="text-right">${fmtNum(r.trash)}</td>
                        <td class="text-right font-bold">${fmtNum(r.oil_fact)}</td>
                        <td class="text-right font-bold">${oilDiffStr}</td>
                    </tr>
                `;
            }).join('');

            document.getElementById('tfoot-trucks').innerHTML = `
                <tr>
                    <td></td>
                    <td class="text-left">ВСЬОГО ПО ВИБІРЦІ (${filtered.length} авто)</td>
                    <td></td><td></td><td></td><td></td><td></td><td></td>
                    <td class="text-right">${fmtNum(sumDeliv)}</td>
                    <td class="text-right">${fmtNum(sumAcc)}</td>
                    <td class="text-right">${fmtNum(sumDiff)}</td>
                    <td></td><td></td><td></td><td></td><td></td><td></td><td></td>
                </tr>
            `;

            document.getElementById('trucks-summary-lbl').textContent =
                `Показано: ${filtered.length} з ${TRUCK_ROWS.length} авто | Завезено: ${fmtNum(sumDeliv)} т | Залікова: ${fmtNum(sumAcc)} т`;
        }

        // =====================================================================
        // 6. ВІДМАЛЬОВКА ВКЛАДКИ «ІСТОРІЯ ЗМІН (АУДИТ 1С)»
        // =====================================================================
        function renderHistTable() {
            const q = (document.getElementById('hist-search').value || '').trim().toLowerCase();
            const cat = (document.getElementById('hist-cat-filter').value || '').trim();

            const filtered = HIST_ROWS.filter(r => {
                if (cat && !(r.category || '').includes(cat)) return false;
                if (q) {
                    const hay = `${r.dt} ${r.supplier} ${r.spec} ${r.manager} ${r.category} ${r.details}`.toLowerCase();
                    if (!hay.includes(q)) return false;
                }
                return true;
            });

            const tbody = document.getElementById('tbody-hist');
            tbody.innerHTML = filtered.map((r, i) => {
                let cls = (i % 2 === 0) ? 'tag-even' : 'tag-odd';
                if ((r.category || '').includes('Новий')) cls = 'tag-new';
                else if ((r.category || '').includes('ціни')) cls = 'tag-changed';

                const payload = encodeURIComponent(JSON.stringify([
                    ['Дата / Час', r.dt],
                    ['Контрагент', r.supplier],
                    ['Специфікація', r.spec],
                    ['Менеджер', r.manager],
                    ['Категорія', r.category],
                    ['Деталі змін', r.details]
                ]));

                return `
                    <tr class="${cls}" onclick="handleRowClick(this, 'Аудит змін: ${esc(r.spec)}', '${payload}')" ondblclick="openDetailModal()">
                        <td class="text-center">${esc(r.dt)}</td>
                        <td class="text-left font-bold">${esc(r.supplier)}</td>
                        <td class="text-center font-bold">${esc(r.spec)}</td>
                        <td class="text-center">${esc(r.manager)}</td>
                        <td class="text-center font-bold">${esc(r.category)}</td>
                        <td class="text-left">${esc(r.details)}</td>
                    </tr>
                `;
            }).join('');

            document.getElementById('hist-summary-lbl').textContent =
                `Записів в історії: ${filtered.length} з ${HIST_ROWS.length}`;
        }

        // =====================================================================
        // МОДАЛЬНЕ ВІКНО ЗАВАНТАЖЕННЯ EXCEL
        // =====================================================================
        function openUploadModal() {
            document.getElementById('upload-status-box').style.display = 'none';
            document.getElementById('upload-modal').classList.add('open');
        }

        function closeUploadModal() {
            document.getElementById('upload-modal').classList.remove('open');
        }

        async function submitSemenaUpload() {
            const prihodInp = document.getElementById('prihod-file-input');
            const specInp   = document.getElementById('spec-file-input');
            const readyInp  = document.getElementById('semena-file-input');
            const allowOld  = document.getElementById('allow-older-checkbox');
            const box       = document.getElementById('upload-status-box');
            const btn       = document.getElementById('btn-submit-upload');

            const hasPrihod = prihodInp && prihodInp.files && prihodInp.files.length > 0;
            const hasSpec   = specInp && specInp.files && specInp.files.length > 0;
            const hasReady  = readyInp && readyInp.files && readyInp.files.length > 0;

            if (!hasPrihod && !hasSpec && !hasReady) {
                box.style.display = 'block';
                box.style.background = '#FDECE8';
                box.style.color = '#A61C1C';
                box.innerHTML = '⚠️ Будь ласка, оберіть <strong>Файл 1 (Приход 1С)</strong> та <strong>Файл 2 (Специфікація 1С)</strong> (або готовий Лист_погодження.xlsx).';
                return;
            }

            const fd = new FormData();
            if (hasPrihod) {
                for (let i = 0; i < prihodInp.files.length; i++) {
                    fd.append('prihod_file', prihodInp.files[i]);
                    fd.append('report_files[]', prihodInp.files[i]);
                }
            }
            if (hasSpec) {
                for (let i = 0; i < specInp.files.length; i++) {
                    fd.append('spec_file', specInp.files[i]);
                    fd.append('report_files[]', specInp.files[i]);
                }
            }
            if (hasReady) {
                for (let i = 0; i < readyInp.files.length; i++) {
                    fd.append('file', readyInp.files[i]);
                    fd.append('report_files[]', readyInp.files[i]);
                }
            }
            if (allowOld && allowOld.checked) {
                fd.append('allow_older', '1');
            }

            btn.disabled = true;
            btn.textContent = '⏳ Зведення даних 1С...';
            box.style.display = 'block';
            box.style.background = '#EBF5FB';
            box.style.color = '#1B4F72';
            box.textContent = 'Обробка файлів Приход + Специфікація, аудит цін та формування Листа погодження...';

            try {
                const resp = await fetch('api/import.php', { method: 'POST', body: fd });
                const text = await resp.text();
                let data;
                try {
                    data = JSON.parse(text);
                } catch (jsonErr) {
                    throw new Error(`Помилка сервера (HTTP ${resp.status}): ${text.replace(/<[^>]*>/g, ' ').trim().substring(0, 200) || 'Порожня відповідь сервера'}`);
                }

                if ((data.status === 'error') || (data.errors && data.errors.length > 0 && (!data.processed_reports || data.processed_reports.length === 0))) {
                    box.style.background = '#FDECE8';
                    box.style.color = '#A61C1C';
                    const errList = (data.errors && data.errors.length) ? data.errors.map(esc).join('<br>') : esc(data.message || 'Помилка імпорту');
                    box.innerHTML = '<strong>❌ Оновлення зупинено:</strong><br>' + errList;
                } else {
                    box.style.background = '#EAF2E8';
                    box.style.color = '#1E8449';
                    const msg = (data.details && data.details.length) ? data.details.join('<br>') : data.message;
                    const errWarn = (data.errors && data.errors.length) ? '<br><span style="color:#A61C1C;">' + data.errors.map(esc).join('<br>') + '</span>' : '';
                    box.innerHTML = `<strong>✅ Звіт успішно сформовано!</strong><br>${msg}${errWarn}<br><em>Сторінка зараз оновиться...</em>`;
                    if (!data.errors || data.errors.length === 0) {
                        setTimeout(() => window.location.reload(), 1300);
                    }
                }
            } catch (err) {
                box.style.background = '#FDECE8';
                box.style.color = '#A61C1C';
                box.textContent = '❌ Помилка з\'єднання з сервером: ' + err.message;
            } finally {
                btn.disabled = false;
                btn.textContent = '⚡ Сформувати та оновити Лист погодження';
            }
        }

        // Ініціалізація всіх таблиць після завантаження сторінки
        document.addEventListener('DOMContentLoaded', () => {
            renderAggTable();
            renderUnfTable();
            renderDailyTable();
            renderDiscTable();
            renderTrucksTable();
            renderHistTable();
        });
    </script>
</body>
</html>
