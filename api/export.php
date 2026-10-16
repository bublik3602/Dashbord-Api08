<?php
/**
 * REST API: Експорт повного звіту дашборда в Excel (.xls)
 * Метод: GET
 */

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/metrics_calculator.php';

Auth::requireAuth('dashboard');

if (Auth::isRole('energo')) {
    header('HTTP/1.1 403 Forbidden');
    echo 'Доступ до експорту загального звіту заводу обмежено для облікового запису енергетика.';
    exit;
}

try {
    $period = filter_input(INPUT_GET, 'period', FILTER_DEFAULT) ?? 'day';
    $validPeriods = ['shift', 'day', 'week', 'month', 'month_oct', 'month_sep', 'month_aug', 'season', 'month_sep_2025'];
    if (!in_array($period, $validPeriods, true)) {
        $period = 'day';
    }

    $calculator = new MetricsCalculator();
    $data = $calculator->getDashboardData($period);

    $mb = $data['material_balance'];
    $en = $data['energy'];
    $log = $data['logistics'];
    $stocks = $data['warehouse_stocks'];
    $lab = $data['lab_quality'];
    $contracts = $data['contracts_summary'];

    $dateStr = date('Y-m-d_H-i');
    $filename = "Звіт_МЕЗ_Агропроінвест08_{$dateStr}.xls";

    header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
    header("Content-Disposition: attachment; filename=\"{$filename}\"");
    header("Cache-Control: max-age=0, no-cache, must-revalidate, proxy-revalidate");
    header("Pragma: public");

    // Вивід UTF-8 BOM для бездоганного відкриття кирилиці в MS Excel
    echo "\xEF\xBB\xBF";
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Calibri, Arial, sans-serif; font-size: 11pt; color: #1e293b; }
        table { border-collapse: collapse; width: 100%; margin-bottom: 24px; }
        th, td { border: 1px solid #cbd5e1; padding: 6px 10px; text-align: left; }
        th { background-color: #0284c7; color: #ffffff; font-weight: bold; }
        .sub-header { background-color: #f1f5f9; font-weight: bold; color: #334155; }
        .title-row { font-size: 16pt; font-weight: bold; color: #0f172a; border: none; padding-bottom: 8px; }
        .meta-row { font-size: 10pt; color: #64748b; border: none; }
        .num { text-align: right; }
        .bold { font-weight: bold; }
        .badge-green { background-color: #dcfce7; color: #15803d; font-weight: bold; }
        .badge-amber { background-color: #fef3c7; color: #b45309; font-weight: bold; }
        .section-title { font-size: 13pt; font-weight: bold; color: #0369a1; padding-top: 14px; border: none; }
    </style>
</head>
<body>

    <!-- ШАПКА ЗВІТУ -->
    <table>
        <tr>
            <td colspan="5" class="title-row">ОЛІЙНОЕКСТРАКЦІЙНИЙ ЗАВОД «АГРОПРОІНВЕСТ 08»</td>
        </tr>
        <tr>
            <td colspan="5" class="meta-row">Єдиний моніторинг виробництва &bull; Оперативний звіт керівника</td>
        </tr>
        <tr>
            <td colspan="5" class="meta-row">
                Період: <strong><?= htmlspecialchars($data['period_label']) ?></strong> &bull; 
                Дата вивантаження: <strong><?= date('d.m.Y H:i:s') ?></strong>
            </td>
        </tr>
    </table>

    <!-- 1. МАТЕРІАЛЬНИЙ БАЛАНС ТА ПЕРЕРОБКА -->
    <table>
        <tr>
            <td colspan="4" class="section-title">1. МАТЕРІАЛЬНИЙ БАЛАНС ТА ПЕРЕРОБКА СИРОВИНИ</td>
        </tr>
        <tr>
            <th>Показник</th>
            <th>Факт, тонн</th>
            <th>План / Норма, тонн</th>
            <th>Частка / Втрати, %</th>
        </tr>
        <tr>
            <td class="bold">Насіння соняшнику на завод (вхід через автоваги)</td>
            <td class="num"><?= number_format($mb['receiving_zone']['seed_in_plant'], 1, ',', ' ') ?></td>
            <td class="num"><?= number_format($mb['receiving_zone']['seed_plan'], 1, ',', ' ') ?></td>
            <td class="num"><?= $mb['receiving_zone']['plan_fulfillment_pct'] ?>% викон.</td>
        </tr>
        <tr>
            <td>Втрати підробки (усушка та сміття)</td>
            <td class="num"><?= number_format($mb['receiving_zone']['conditioning_loss'], 1, ',', ' ') ?></td>
            <td class="num">&lt; 2.5%</td>
            <td class="num <?= $mb['receiving_zone']['conditioning_loss_pct'] > 2.5 ? 'badge-amber' : '' ?>"><?= $mb['receiving_zone']['conditioning_loss_pct'] ?>%</td>
        </tr>
        <tr>
            <td class="bold">Насіння у переробку (після сушарки та очистки)</td>
            <td class="num bold"><?= number_format($mb['production_zone']['seed_processed'], 1, ',', ' ') ?></td>
            <td class="num">-</td>
            <td class="num">100.0%</td>
        </tr>
        <tr>
            <td>Вихід: Олія</td>
            <td class="num"><?= number_format($mb['production_zone']['oil_out'], 1, ',', ' ') ?></td>
            <td class="num"><?= number_format($mb['production_zone']['oil_plan'], 1, ',', ' ') ?></td>
            <td class="num"><?= $mb['production_zone']['oil_yield_pct'] ?>%</td>
        </tr>
        <tr>
            <td>Вихід: Шрот</td>
            <td class="num"><?= number_format($mb['production_zone']['meal_out'], 1, ',', ' ') ?></td>
            <td class="num">-</td>
            <td class="num"><?= $mb['production_zone']['meal_yield_pct'] ?>%</td>
        </tr>
        <tr>
            <td>Вихід: Лузга</td>
            <td class="num"><?= number_format($mb['production_zone']['husk_out'], 1, ',', ' ') ?></td>
            <td class="num">-</td>
            <td class="num"><?= $mb['production_zone']['husk_yield_pct'] ?>%</td>
        </tr>
        <tr class="sub-header">
            <td class="bold">Разом вихід готової продукції (Олія + Шрот + Лузга)</td>
            <td class="num bold"><?= number_format($mb['production_zone']['products_total'], 1, ',', ' ') ?></td>
            <td class="num">-</td>
            <td class="num bold"><?= $mb['production_zone']['total_yield_pct'] ?>%</td>
        </tr>
        <tr>
            <td>Технологічні виробничі втрати переробки</td>
            <td class="num"><?= number_format($mb['production_zone']['production_loss'], 1, ',', ' ') ?></td>
            <td class="num">&lt; 1.2%</td>
            <td class="num"><?= $mb['production_zone']['production_loss_pct'] ?>%</td>
        </tr>
    </table>

    <!-- 2. СКЛАДСЬКІ ЗАЛИШКИ ТА АВТОНОМНІСТЬ -->
    <table>
        <tr>
            <td colspan="4" class="section-title">2. СКЛАДСЬКІ ЗАЛИШКИ ТА АВТОНОМНІСТЬ ЕЛЕВАТОРА</td>
        </tr>
        <tr>
            <th>Найменування складу / резервуару</th>
            <th>Поточний залишок, тонн</th>
            <th>Відвантажено за добу, тонн</th>
            <th>Примітки / Автономність</th>
        </tr>
        <tr>
            <td class="bold">Запас насіння на елеваторі (фізична вага)</td>
            <td class="num bold"><?= number_format($stocks['seed_stock_tonnes'], 1, ',', ' ') ?></td>
            <td class="num">-</td>
            <td class="badge-green"><?= $stocks['days_seed_reserve'] ?> днів автономної роботи (при 600 т/д)</td>
        </tr>
        <tr>
            <td>Запас насіння за бухгалтерським обліком</td>
            <td class="num"><?= number_format($stocks['seed_stock_accounting_tonnes'], 1, ',', ' ') ?></td>
            <td class="num">-</td>
            <td>Різниця обліку: <?= number_format($stocks['seed_stock_tonnes'] - $stocks['seed_stock_accounting_tonnes'], 1, ',', ' ') ?> т</td>
        </tr>
        <tr>
            <td>Олія в резервуарному парку</td>
            <td class="num"><?= number_format($stocks['oil_stock_tonnes'], 1, ',', ' ') ?></td>
            <td class="num"><?= number_format($stocks['oil_shipped_tonnes'], 1, ',', ' ') ?></td>
            <td>Автоцистерни</td>
        </tr>
        <tr>
            <td>Шрот на складі СГП</td>
            <td class="num"><?= number_format($stocks['meal_stock_tonnes'], 1, ',', ' ') ?></td>
            <td class="num"><?= number_format($stocks['meal_shipped_tonnes'], 1, ',', ' ') ?></td>
            <td>Насипом / Автотранспорт</td>
        </tr>
        <tr>
            <td>Лузга на складі готової продукції</td>
            <td class="num"><?= number_format($stocks['husk_stock_tonnes'], 1, ',', ' ') ?></td>
            <td class="num"><?= number_format($stocks['husk_shipped_tonnes'], 1, ',', ' ') ?></td>
            <td>Насипом / Автотранспорт</td>
        </tr>
        <tr>
            <td class="bold">Лузга, спалена в котельні заводу</td>
            <td class="num bold"><?= number_format($stocks['husk_boiler_spent_tonnes'], 1, ',', ' ') ?></td>
            <td class="num">-</td>
            <td class="badge-amber">Власне паливо котельні на технологічну пару</td>
        </tr>
    </table>

    <!-- 3. ЛАБОРАТОРІЯ ЯКОСТІ ТА КОНТРАКТАЦІЯ (1С) -->
    <table>
        <tr>
            <td colspan="4" class="section-title">3. ЛАБОРАТОРІЯ ТА ВИКОНАННЯ КОНТРАКТІВ (ЛИСТ ПОГОДЖЕННЯ 1С)</td>
        </tr>
        <tr>
            <th>Параметр якості сировини</th>
            <th>Фактичне значення</th>
            <th>Базова норма</th>
            <th>Відхилення / Бонус / Знижка</th>
        </tr>
        <tr>
            <td class="bold">Олійність насіння на суху речовину</td>
            <td class="num bold"><?= number_format($lab['oil_content_pct'], 2, ',', ' ') ?>%</td>
            <td class="num"><?= number_format($lab['oil_content_base_pct'], 1, ',', ' ') ?>%</td>
            <td class="badge-green">+<?= number_format($lab['oil_deviation_pct'], 2, ',', ' ') ?>% (Бонус до ціни)</td>
        </tr>
        <tr>
            <td>Вологість насіння соняшнику</td>
            <td class="num"><?= number_format($lab['moisture_pct'], 2, ',', ' ') ?>%</td>
            <td class="num">&lt; 8.00%</td>
            <td><?= $lab['moisture_pct'] <= 8.0 ? 'В нормі (сушіння не потрібне)' : 'Потребує сушіння' ?></td>
        </tr>
        <tr>
            <td>Смітна домішка (сор)</td>
            <td class="num"><?= number_format($lab['impurity_pct'], 2, ',', ' ') ?>%</td>
            <td class="num">3.00%</td>
            <td>Знижка з фізичної ваги: <?= number_format($lab['weight_discount_tonnes'], 1, ',', ' ') ?> т</td>
        </tr>
        <tr>
            <td class="bold">Законтрактовано за договорами (1С)</td>
            <td class="num bold"><?= number_format($contracts['contracted_tonnes'], 1, ',', ' ') ?> т</td>
            <td class="num">-</td>
            <td>100.0%</td>
        </tr>
        <tr>
            <td class="bold">Завезено фактично на завод</td>
            <td class="num bold"><?= number_format($contracts['delivered_tonnes'], 1, ',', ' ') ?> т</td>
            <td class="num"><?= number_format($contracts['fulfillment_pct'], 1, ',', ' ') ?>%</td>
            <td class="badge-green"><?= number_format($contracts['fulfillment_pct'], 1, ',', ' ') ?>% виконання</td>
        </tr>
        <tr>
            <td>Залишок довезти (на довозі)</td>
            <td class="num"><?= number_format($contracts['remaining_tonnes'], 1, ',', ' ') ?> т</td>
            <td class="num"><?= $contracts['active_specs_count'] ?> у роботі</td>
            <td><?= $contracts['pending_specs_count'] ?> специфікацій ще не приїхало</td>
        </tr>
    </table>

    <!-- 4. ЛОГІСТИКА АВТОТРАНСПОРТУ -->
    <table>
        <tr>
            <td colspan="4" class="section-title">4. ЛОГІСТИКА ТА ЧАС ПЕРЕБУВАННЯ АВТОТРАНСПОРТУ</td>
        </tr>
        <tr>
            <th>Культура / Вантаж</th>
            <th>Сер. час на заводі (в'їзд &rarr; виїзд)</th>
            <th>Зараз на території</th>
            <th>Виїхало за добу (08:00 - 08:00)</th>
        </tr>
        <?php foreach ($log['categories'] as $cName => $cData): ?>
        <tr>
            <td class="bold"><?= htmlspecialchars($cName) ?></td>
            <td class="num"><?= htmlspecialchars($cData['avg_stay_text'] ?? '-') ?></td>
            <td class="num"><?= $cData['on_site'] ?? 0 ?> авто</td>
            <td class="num"><?= $cData['exited'] ?? 0 ?> авто</td>
        </tr>
        <?php endforeach; ?>
        <tr class="sub-header">
            <td>РАЗОМ ЗА ВСІМА ВАНТАЖАМИ:</td>
            <td class="num"><?= htmlspecialchars($log['avg_stay_text'] ?? '-') ?></td>
            <td class="num"><?= $log['on_site'] ?? 0 ?> на території</td>
            <td class="num"><?= $log['exited'] ?? 0 ?> виїхало</td>
        </tr>
    </table>

    <!-- 5. ЕНЕРГОРЕСУРСИ -->
    <table>
        <tr>
            <td colspan="3" class="section-title">5. ЕНЕРГОРЕСУРСИ ТА ЕНЕРГОЕФЕКТИВНІСТЬ</td>
        </tr>
        <tr>
            <th>Показник</th>
            <th>Поточне значення</th>
            <th>Норматив</th>
        </tr>
        <tr>
            <td>Поточна потужність вводу заводу</td>
            <td class="num bold"><?= number_format($en['current_power_kw'], 0, ',', ' ') ?> кВт</td>
            <td class="num">&le; 4 200 кВт</td>
        </tr>
        <tr>
            <td>Миттєва витрата технічної води</td>
            <td class="num"><?= number_format($en['current_water_m3_hour'], 1, ',', ' ') ?> м³/год</td>
            <td class="num">-</td>
        </tr>
        <tr>
            <td>Питома витрата електроенергії на 1 т переробки</td>
            <td class="num bold"><?= $en['specific_kwh_per_tonne'] ?> кВт*год / т</td>
            <td class="num">&le; 65.0 кВт*год / т</td>
        </tr>
    </table>

    <!-- 6. АНАЛІТИКА ТА ПОРІВНЯННЯ ПЕРІОДІВ (СЕРПЕНЬ — ВЕРЕСЕНЬ — ЖОВТЕНЬ 2026) -->
    <?php if (!empty($data['historical_analytics'])): 
        $hist = $data['historical_analytics'];
        $st = $hist['season_total'];
    ?>
    <table>
        <tr>
            <td colspan="7" class="section-title">6. АНАЛІТИКА ТА ПОРІВНЯННЯ ПЕРІОДІВ (СЕРПЕНЬ — ВЕРЕСЕНЬ — ЖОВТЕНЬ 2026)</td>
        </tr>
        <tr>
            <th>Період / Місяць</th>
            <th>Діб</th>
            <th>Завіз насіння, т</th>
            <th>Переробка, т</th>
            <th>Олія вихід, т (%)</th>
            <th>Шрот вихід, т (%)</th>
            <th>Лузга, т</th>
        </tr>
        <?php foreach ($hist['months'] as $m): ?>
        <tr>
            <td class="bold"><?= htmlspecialchars($m['name']) ?></td>
            <td class="num"><?= $m['days_count'] ?></td>
            <td class="num"><?= number_format($m['seed_in'], 2, ',', ' ') ?></td>
            <td class="num"><?= number_format($m['seed_processed'], 2, ',', ' ') ?></td>
            <td class="num"><?= number_format($m['oil_out'], 2, ',', ' ') ?> (<?= $m['oil_yield_pct'] ?>%)</td>
            <td class="num"><?= number_format($m['meal_out'], 2, ',', ' ') ?> (<?= $m['meal_yield_pct'] ?>%)</td>
            <td class="num"><?= number_format($m['husk_out'], 2, ',', ' ') ?></td>
        </tr>
        <?php endforeach; ?>
        <tr class="sub-header">
            <td class="bold"><?= htmlspecialchars($st['name']) ?> (72 доби)</td>
            <td class="num">72</td>
            <td class="num bold"><?= number_format($st['seed_in'], 2, ',', ' ') ?></td>
            <td class="num bold"><?= number_format($st['seed_processed'], 2, ',', ' ') ?></td>
            <td class="num bold"><?= number_format($st['oil_out'], 2, ',', ' ') ?> (<?= $st['oil_yield_pct'] ?>%)</td>
            <td class="num bold"><?= number_format($st['meal_out'], 2, ',', ' ') ?> (<?= $st['meal_yield_pct'] ?>%)</td>
            <td class="num bold"><?= number_format($st['husk_out'], 2, ',', ' ') ?></td>
        </tr>
    </table>
    <?php endif; ?>

</body>
</html>
<?php
} catch (Throwable $e) {
    http_response_code(500);
    echo "Помилка формування експорту: " . htmlspecialchars($e->getMessage());
}
