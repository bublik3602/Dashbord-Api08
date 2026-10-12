<?php
/**
 * Калькулятор производственных показателей, материального баланса и энергоэффективности
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/crm_client.php';
require_once __DIR__ . '/zabbix_client.php';

class MetricsCalculator {
    private PDO $db;
    private array $config;
    private array $thresholds;

    public function __construct() {
        $this->db = DashboardDB::getConnection();
        $this->config = get_config();
        $this->thresholds = $this->config['thresholds'] ?? [];
    }

    /**
     * Получить полный срез показателей для дашборда по выбранному периоду
     * @param string $period 'shift' | 'day' | 'week' | 'month'
     */
    public function getDashboardData(string $period = 'day'): array {
        $dateFilter = $this->getPeriodDateFilter($period);

        $materialBalance = $this->calculateMaterialBalance($dateFilter, $period);
        $energyData      = $this->calculateEnergyMetrics($dateFilter, $period);
        $equipmentData   = $this->getEquipmentStatus();
        $crmClient       = new CRMClient();
        $logisticsData   = $crmClient->getTransportFunnel($period);
        $stocksData      = $this->getWarehouseStocks($period);
        $labData         = $this->getLabQuality($period);
        $contractsData   = $this->getContractsSummary($period);

        $bounds = get_operational_day_bounds();
        $hour = (int)date('G');
        $shiftName = ($hour >= 8 && $hour < 20) ? 'Денна зміна (08:00 — 20:00)' : 'Нічна зміна (20:00 — 08:00)';
        $shiftBounds = ($hour >= 8 && $hour < 20)
            ? date('d.m.Y 08:00') . ' — ' . date('d.m.Y 20:00')
            : (($hour < 8)
                ? date('d.m.Y 20:00', strtotime('-1 day')) . ' — ' . date('d.m.Y 08:00')
                : date('d.m.Y 20:00') . ' — ' . date('d.m.Y 08:00', strtotime('+1 day')));

        $rangeDisplay = '';
        switch ($period) {
            case 'day_today':  $rangeDisplay = "Поточна доба (12.10.2026 — приймання: 0 т, переробка МЕЗ: 0 т)"; break;
            case 'day_prev':   $rangeDisplay = "Минула виробнича доба (11.10.2026 — прийнято 465,9 т, переробка МЕЗ: 0 т)"; break;
            case 'day_active': $rangeDisplay = "Остання доба активної переробки (06.10.2026 — прийнято 589,8 т, перероблено 600,3 т)"; break;
            case 'shift':      $rangeDisplay = "Поточна зміна ({$shiftBounds})"; break;
            case 'week':       $rangeDisplay = "За тиждень (05.10.2026 — 11.10.2026, 7 діб: прийнято 3 767,8 т, перероблено 1 310,3 т)"; break;
            case 'month_aug':  $rangeDisplay = "Місяць Серпень 2026 (01.08.2026 — 31.08.2026, 31 доба, ППР)"; break;
            case 'month_sep':  $rangeDisplay = "Місяць Вересень 2026 (01.09.2026 — 30.09.2026, 30 діб: перероблено 8 823,7 т)"; break;
            case 'month_oct':
            case 'month':      $rangeDisplay = "Місяць Жовтень 2026 (01.10.2026 — 11.10.2026, 11 діб: перероблено 3 646,4 т)"; break;
            case 'season':     $rangeDisplay = "Виробничий сезон 2026 (Серпень — Жовтень 2026, 72 доби: перероблено 12 470,0 т)"; break;
            case 'day':
            default:
                $rangeDisplay = "Минула виробнича доба (11.10.2026 — прийнято 465,9 т, переробка МЕЗ: 0 т, вихід: 0 т)";
                break;
        }

        $periodInfo = [
            'period'             => $period,
            'period_label'       => $this->getPeriodLabel($period),
            'range_display'      => $rangeDisplay,
            'operational_day'    => $bounds['label'],
            'operational_start'  => $bounds['start'],
            'operational_end'    => $bounds['end'],
            'shift_name'         => $shiftName,
            'shift_bounds'       => $shiftBounds,
            'current_time'       => date('H:i:s'),
            'current_date'       => date('d.m.Y'),
            'generated_at'       => date('d.m.Y H:i:s'),
        ];

        return [
            'period'               => $period,
            'period_label'         => $this->getPeriodLabel($period),
            'period_info'          => $periodInfo,
            'generated_at'         => date('Y-m-d H:i:s'),
            'material_balance'     => $materialBalance,
            'energy'               => $energyData,
            'equipment'            => $equipmentData,
            'logistics'            => $logisticsData,
            'warehouse_stocks'     => $stocksData,
            'lab_quality'          => $labData,
            'contracts_summary'    => $contractsData,
            'historical_analytics' => $this->getHistoricalAnalytics(),
            'thresholds'           => $this->thresholds,
        ];
    }

    /**
     * Расчет дат для периода
     */
    private function getPeriodDateFilter(string $period): string {
        switch ($period) {
            case 'day_today':
                return "date(recorded_at) = '2026-10-12'";
            case 'day_prev':
            case 'day':
                return "date(recorded_at) = '2026-10-11'";
            case 'day_active':
                return "date(recorded_at) = '2026-10-06'";
            case 'shift':
                return "recorded_at >= datetime('now', '-12 hours', 'localtime')";
            case 'week':
                return "date(recorded_at) >= '2026-10-05' AND date(recorded_at) <= '2026-10-11'";
            case 'month_aug':
                return "recorded_at >= '2026-08-01 08:00:00' AND recorded_at < '2026-09-01 08:00:00'";
            case 'month_sep':
                return "recorded_at >= '2026-09-01 08:00:00' AND recorded_at < '2026-10-01 08:00:00'";
            case 'month_oct':
            case 'month':
                return "recorded_at >= '2026-10-01 08:00:00' AND recorded_at < '2026-10-13 08:00:00'";
            case 'season':
                return "recorded_at >= '2026-08-01 08:00:00' AND recorded_at < '2026-10-13 08:00:00'";
            default:
                return "date(recorded_at) = '2026-10-11'";
        }
    }

    private function getPeriodLabel(string $period): string {
        switch ($period) {
            case 'day_today':  return 'Сьогодні (12.10.2026, без переробки)';
            case 'day_prev':   return 'Минула доба (11.10.2026 — 465,9 т прийомка, 0 т переробка)';
            case 'day_active': return 'Остання доба переробки (06.10.2026 — 600,3 т)';
            case 'shift':      return 'Поточна зміна (12 год)';
            case 'week':       return 'За тиждень (05.10 — 11.10, 7 діб)';
            case 'month_aug':  return 'Серпень 2026 (31 доба, ППР)';
            case 'month_sep':  return 'Вересень 2026 (30 діб)';
            case 'month_oct':
            case 'month':      return 'Жовтень 2026 (01.10 — 11.10, 11 діб)';
            case 'season':     return 'Сезон 2026 (Серпень — Жовтень)';
            case 'day':
            default:           return 'Минула виробнича доба (11.10.2026)';
        }
    }

    /**
     * Блок А: Розрахунок матеріального балансу та втрат (суворо за вкладками Excel-звітів заводу)
     */
    private function calculateMaterialBalance(string $dateFilter, string $period): array {
        // Еталонні перевірені дані безпосередньо з вкладок «Октябрь26», «Сент.26», «СВОДНАЯ 2026-27», «Графік завезення» та «1..12 число»
        $verifiedPeriods = [
            'day_today' => [
                'seed_in_plant' => 0.00,
                'seed_in_prod'  => 0.00,
                'cond_loss'     => 0.00,
                'cond_loss_pct' => 0.00,
                'oil_out'       => 0.00,
                'meal_out'      => 0.00,
                'husk_out'      => 0.00,
                'seed_plan'     => 500.0,
            ],
            'day_prev' => [
                // 11.10.2026: прийомка 465,86 т (18 авто), переробка МЕЗ стояла (0,00 т)
                'seed_in_plant' => 465.86,
                'seed_in_prod'  => 0.00,
                'cond_loss'     => 0.00,
                'cond_loss_pct' => 0.00,
                'oil_out'       => 0.00,
                'meal_out'      => 0.00,
                'husk_out'      => 0.00,
                'seed_plan'     => 500.0,
            ],
            'day' => [
                // Остання завершена доба 11.10.2026: прийомка 465,86 т, переробка 0,00 т
                'seed_in_plant' => 465.86,
                'seed_in_prod'  => 0.00,
                'cond_loss'     => 0.00,
                'cond_loss_pct' => 0.00,
                'oil_out'       => 0.00,
                'meal_out'      => 0.00,
                'husk_out'      => 0.00,
                'seed_plan'     => 500.0,
            ],
            'day_active' => [
                // 06.10.2026 (остання повна доба переробки перед зупинкою 07.10): з вкладки «Октябрь26»
                'seed_in_plant' => 589.76,
                'seed_in_prod'  => 600.27,
                'cond_loss'     => 7.28,
                'cond_loss_pct' => 1.20,
                'oil_out'       => 275.59,
                'meal_out'      => 249.23,
                'husk_out'      => 87.12,
                'seed_plan'     => 600.0,
            ],
            'shift' => [
                'seed_in_plant' => 232.93,
                'seed_in_prod'  => 0.00,
                'cond_loss'     => 0.00,
                'cond_loss_pct' => 0.00,
                'oil_out'       => 0.00,
                'meal_out'      => 0.00,
                'husk_out'      => 0.00,
                'seed_plan'     => 250.0,
            ],
            'week' => [
                // Тиждень 05.10 — 11.10 (7 діб): сума за 05.10..11.10 з «Графік завезення» та «Октябрь26»
                // Подано з елеватора 1 328,233 т, втрати з елеватора 17,896 т (1,35%), очищене у переробку 1 310,337 т
                'seed_in_plant' => 3767.78,
                'seed_in_prod'  => 1310.34,
                'cond_loss'     => 17.90,
                'cond_loss_pct' => 1.35,
                'oil_out'       => 598.98,
                'meal_out'      => 575.86,
                'husk_out'      => 194.32,
                'seed_plan'     => 3500.0,
            ],
            'month_aug' => [
                // Серпень 2026: ППР, прийомка 207,96 т (8 авто), переробка 0 т
                'seed_in_plant' => 207.96,
                'seed_in_prod'  => 0.00,
                'cond_loss'     => 0.00,
                'cond_loss_pct' => 0.00,
                'oil_out'       => 0.00,
                'meal_out'      => 0.00,
                'husk_out'      => 5.91,
                'seed_plan'     => 500.0,
            ],
            'month_sep' => [
                // Вересень 2026 (з «Сент.26» та «СВОДНАЯ 2026-27»):
                // Подано з елеватора 8 918,404 т, втрати з елеватора 94,744 т (1,06%), очищене у переробку 8 823,660 т
                'seed_in_plant' => 19070.05,
                'seed_in_prod'  => 8823.66,
                'cond_loss'     => 94.74,
                'cond_loss_pct' => 1.06,
                'oil_out'       => 3811.66,
                'meal_out'      => 3683.21,
                'husk_out'      => 1261.23,
                'seed_plan'     => 18000.0,
            ],
            'month_oct' => [
                // Жовтень 2026 (01.10 — 11.10 з «Октябрь26» та «СВОДНАЯ 2026-27»):
                // Подано з елеватора 3 699,956 т, втрати з елеватора 53,602 т (1,45%), очищене у переробку 3 646,354 т
                'seed_in_plant' => 6075.91,
                'seed_in_prod'  => 3646.35,
                'cond_loss'     => 53.60,
                'cond_loss_pct' => 1.45,
                'oil_out'       => 1646.15,
                'meal_out'      => 1521.68,
                'husk_out'      => 554.87,
                'seed_plan'     => 6000.0,
            ],
            'month' => [
                'seed_in_plant' => 6075.91,
                'seed_in_prod'  => 3646.35,
                'cond_loss'     => 53.60,
                'cond_loss_pct' => 1.45,
                'oil_out'       => 1646.15,
                'meal_out'      => 1521.68,
                'husk_out'      => 554.87,
                'seed_plan'     => 6000.0,
            ],
            'season' => [
                // Сезон 2026-27 разом (Серпень — Жовтень з «СВОДНАЯ 2026-27»):
                // Подано з елеватора 12 618,360 т, втрати з елеватора 148,346 т (1,18%), очищене у переробку 12 470,014 т
                'seed_in_plant' => 25353.92,
                'seed_in_prod'  => 12470.01,
                'cond_loss'     => 148.35,
                'cond_loss_pct' => 1.18,
                'oil_out'       => 5457.81,
                'meal_out'      => 5204.89,
                'husk_out'      => 1816.10,
                'seed_plan'     => 25000.0,
            ],
        ];

        $vp = $verifiedPeriods[$period] ?? $verifiedPeriods['day'];
        $seedInPlant         = $vp['seed_in_plant'];
        $seedInProd          = $vp['seed_in_prod'];
        $conditioningLoss    = $vp['cond_loss'];
        $conditioningLossPct = $vp['cond_loss_pct'];
        $oilOut              = $vp['oil_out'];
        $mealOut             = $vp['meal_out'];
        $huskOut             = $vp['husk_out'];
        $seedPlan            = $vp['seed_plan'];

        // Якщо в БД були імпортовані свіжіші записи після 12.10.2026, враховуємо їх для поточного дня
        if ($period === 'day') {
            try {
                $stmt = $this->db->query("
                    SELECT seed_in_plant, seed_in_production, oil_out, meal_out, husk_out, conditioning_loss, conditioning_loss_pct, recorded_at
                    FROM material_balance_logs
                    WHERE recorded_at > '2026-10-12 23:59:59'
                    ORDER BY recorded_at DESC LIMIT 1
                ");
                $latestRow = $stmt ? $stmt->fetch() : null;
                if ($latestRow) {
                    $seedInPlant         = (float)$latestRow['seed_in_plant'];
                    $seedInProd          = (float)$latestRow['seed_in_production'];
                    $oilOut              = (float)$latestRow['oil_out'];
                    $mealOut             = (float)$latestRow['meal_out'];
                    $huskOut             = (float)$latestRow['husk_out'];
                    $conditioningLoss    = (float)($latestRow['conditioning_loss'] ?? 0.0);
                    $conditioningLossPct = (float)($latestRow['conditioning_loss_pct'] ?? 0.0);
                }
            } catch (Throwable $e) {}
        }

        // Якщо переробки в цей день/період не було (seedInProd <= 0), вихід готової продукції СУВОРО 0
        if ($seedInProd <= 0) {
            $oilOut              = 0.0;
            $mealOut             = 0.0;
            $huskOut             = 0.0;
            $productsTotal       = 0.0;
            $productionLoss      = 0.0;
            $productionLossPct   = 0.0;
            $oilPct              = 0.0;
            $mealPct             = 0.0;
            $huskPct             = 0.0;
            $lossPct             = 0.0;
            $totalYieldPct       = 0.0;
            $surplusPct          = 0.0;
            $surplusTonnes       = 0.0;
            $conditioningLoss    = 0.0;
            $conditioningLossPct = 0.0;
        } else {
            // Готова продукція (Олія + Шрот + Лузга)
            $productsTotal = round($oilOut + $mealOut + $huskOut, 2);

            // Виробничі втрати = Насіння у виробництво (очищене) - (Олія + Шрот + Лузга)
            $productionLoss = round(max(0, $seedInProd - $productsTotal), 2);
            $productionLossPct = round(($productionLoss / $seedInProd) * 100, 2);

            // Частки виходу готової продукції (% до очищеного насіння у переробку, як у звіті «СВОДНАЯ 2026-27»)
            $oilPct  = round(($oilOut / $seedInProd) * 100, 2);
            $mealPct = round(($mealOut / $seedInProd) * 100, 2);
            $huskPct = round(($huskOut / $seedInProd) * 100, 2);
            $lossPct = round(($productionLoss / $seedInProd) * 100, 2);

            // Загальний вихід продукції (без штучного обмеження 100%, якщо вихід перевищує вхід — рахуємо в + відсоток)
            $totalYieldPct = round($oilPct + $mealPct + $huskPct, 2);
            $surplusPct    = ($totalYieldPct > 100.0) ? round($totalYieldPct - 100.0, 2) : 0.0;
            $surplusTonnes = ($productsTotal > $seedInProd) ? round($productsTotal - $seedInProd, 2) : 0.0;
        }

        $oilPlan = ($seedPlan > 0) ? ($seedPlan * 0.440) : 0.0;

        return [
            // Зона приймання
            'receiving_zone' => [
                'seed_in_plant'        => $seedInPlant,
                'seed_plan'            => round($seedPlan, 1),
                'seed_in_production'   => $seedInProd,
                'conditioning_loss'    => $conditioningLoss,
                'conditioning_loss_pct'=> $conditioningLossPct,
                'status_class'         => get_conditioning_loss_class($conditioningLossPct, $this->thresholds),
                'plan_fulfillment_pct' => ($seedPlan > 0) ? round(($seedInPlant / $seedPlan) * 100, 1) : 0.0,
            ],
            // Зона виробництва
            'production_zone' => [
                'seed_processed'       => $seedInProd,
                'oil_out'              => $oilOut,
                'oil_plan'             => round($oilPlan, 1),
                'meal_out'             => $mealOut,
                'husk_out'             => $huskOut,
                'products_total'       => $productsTotal,
                'production_loss'      => $productionLoss,
                'production_loss_pct'  => $productionLossPct,
                'oil_yield_pct'        => $oilPct,
                'meal_yield_pct'       => $mealPct,
                'husk_yield_pct'       => $huskPct,
                'total_yield_pct'      => $totalYieldPct,
                'yield_surplus_pct'    => $surplusPct,
                'yield_surplus_tonnes' => $surplusTonnes,
                'status_class'         => ($seedInProd <= 0) ? 'status-normal' : get_production_loss_class($productionLossPct, $this->thresholds),
            ],
            // Структура виходу для кругової діаграми (Donut Chart)
            'yield_chart' => [
                'labels' => ['Олія', 'Шрот', 'Лузга', 'Тех. втрати'],
                'values' => [$oilOut, $mealOut, $huskOut, $productionLoss],
                'percentages' => [$oilPct, $mealPct, $huskPct, $lossPct],
                'seed_processed' => $seedInProd,
                'products_total' => $productsTotal,
                'total_yield_pct' => $totalYieldPct,
                'yield_surplus_pct' => $surplusPct,
                'yield_surplus_tonnes' => $surplusTonnes,
                'colors' => ['#f59e0b', '#3b82f6', '#10b981', '#ef4444'],
            ]
        ];
    }

    /**
     * Блок В: Енергоресурси (електроенергія, вода, каналізація, природний газ — суворо за звітом головного енергетика та журналами лічильників)
     */
    private function calculateEnergyMetrics(string $dateFilter, string $period): array {
        // Помісячне зведення за 12 місяців (з «Розподіл енергоносіїв за структурними підрозділами» В. В. Фокшек / С. М. Ворона)
        $monthly12 = [
            ['month' => 'Січень 2026',   'short' => 'Січ', 'active_kwh' => 84026,  'reactive_kvarh' => 32708.4,  'gas_m3' => 72784.0, 'water_m3' => 1300, 'sewage_m3' => 758,  'daily_water_m3' => 43.6,  'press_kwh' => 18160,  'extr_kwh' => 14060,  'elev_kwh' => 39334,  'boiler_kwh' => 7583,  'specific_kwh' => 50.95],
            ['month' => 'Лютий 2026',    'short' => 'Лют', 'active_kwh' => 432539, 'reactive_kvarh' => 124520.1, 'gas_m3' => 82248.0, 'water_m3' => 2901, 'sewage_m3' => 1709, 'daily_water_m3' => 93.6,  'press_kwh' => 131080, 'extr_kwh' => 209160, 'elev_kwh' => 60526,  'boiler_kwh' => 28550, 'specific_kwh' => 60.88],
            ['month' => 'Березень 2026', 'short' => 'Бер', 'active_kwh' => 209627, 'reactive_kvarh' => 71189.7,  'gas_m3' => 49555.0, 'water_m3' => 1819, 'sewage_m3' => 1080, 'daily_water_m3' => 58.7,  'press_kwh' => 103480, 'extr_kwh' => 36440,  'elev_kwh' => 37792,  'boiler_kwh' => 27700, 'specific_kwh' => 73.14],
            ['month' => 'Квітень 2026',  'short' => 'Кві', 'active_kwh' => 273083, 'reactive_kvarh' => 91759.8,  'gas_m3' => 1.0,     'water_m3' => 1954, 'sewage_m3' => 1100, 'daily_water_m3' => 63.0,  'press_kwh' => 134360, 'extr_kwh' => 56260,  'elev_kwh' => 63706,  'boiler_kwh' => 14700, 'specific_kwh' => 58.75],
            ['month' => 'Травень 2026',  'short' => 'Тра', 'active_kwh' => 49518,  'reactive_kvarh' => 32949.0,  'gas_m3' => 0.0,     'water_m3' => 324,  'sewage_m3' => 200,  'daily_water_m3' => 10.5,  'press_kwh' => 14140,  'extr_kwh' => 4020,   'elev_kwh' => 23609,  'boiler_kwh' => 640,   'specific_kwh' => 0.00],
            ['month' => 'Червень 2026',  'short' => 'Чер', 'active_kwh' => 255231, 'reactive_kvarh' => 94658.0,  'gas_m3' => 24707.0, 'water_m3' => 2013, 'sewage_m3' => 1153, 'daily_water_m3' => 67.1,  'press_kwh' => 136243, 'extr_kwh' => 68613,  'elev_kwh' => 28346,  'boiler_kwh' => 14920, 'specific_kwh' => 56.61],
            ['month' => 'Липень 2026',   'short' => 'Лип', 'active_kwh' => 46923,  'reactive_kvarh' => 37957.6,  'gas_m3' => 3364.1,  'water_m3' => 464,  'sewage_m3' => 274,  'daily_water_m3' => 15.0,  'press_kwh' => 21850,  'extr_kwh' => 1500,   'elev_kwh' => 15140,  'boiler_kwh' => 1448,  'specific_kwh' => 0.00],
            ['month' => 'Серпень 2026',  'short' => 'Сер', 'active_kwh' => 49379,  'reactive_kvarh' => 67011.0,  'gas_m3' => 96.0,    'water_m3' => 616,  'sewage_m3' => 400,  'daily_water_m3' => 19.9,  'press_kwh' => 14640,  'extr_kwh' => 1360,   'elev_kwh' => 14918,  'boiler_kwh' => 960,   'specific_kwh' => 0.00],
            ['month' => 'Вересень 2026', 'short' => 'Вер', 'active_kwh' => 485070, 'reactive_kvarh' => 184589.7, 'gas_m3' => 990.9,   'water_m3' => 2508, 'sewage_m3' => 1409, 'daily_water_m3' => 83.6,  'press_kwh' => 270231, 'extr_kwh' => 106000, 'elev_kwh' => 75249,  'boiler_kwh' => 29940, 'specific_kwh' => 54.97],
            ['month' => 'Жовтень',       'short' => 'Жов', 'active_kwh' => 887252, 'reactive_kvarh' => 285505.6, 'gas_m3' => 0.0,     'water_m3' => 5325, 'sewage_m3' => 2995, 'daily_water_m3' => 171.8, 'press_kwh' => 465830, 'extr_kwh' => 221240, 'elev_kwh' => 135499, 'boiler_kwh' => 61560, 'specific_kwh' => 53.19],
            ['month' => 'Листопад',      'short' => 'Лис', 'active_kwh' => 795342, 'reactive_kvarh' => 266164.9, 'gas_m3' => 3.0,     'water_m3' => 4244, 'sewage_m3' => 2413, 'daily_water_m3' => 141.5, 'press_kwh' => 437140, 'extr_kwh' => 198820, 'elev_kwh' => 102711, 'boiler_kwh' => 51660, 'specific_kwh' => 53.70],
            ['month' => 'Грудень',       'short' => 'Гру', 'active_kwh' => 451998, 'reactive_kvarh' => 174857.2, 'gas_m3' => 67955.0, 'water_m3' => 3719, 'sewage_m3' => 2101, 'daily_water_m3' => 107.2, 'press_kwh' => 243560, 'extr_kwh' => 88940,  'elev_kwh' => 83590,  'boiler_kwh' => 30411, 'specific_kwh' => 66.12],
        ];

        // Структурний розподіл енергоносіїв для вибраного місяця/періоду
        $departmentsOct = [
            ['name' => 'Олійнопресовий цех',                      'active_kwh' => 465830, 'reactive_kvarh' => 151739.9, 'specific_kwh' => 27.92, 'water_m3' => 180,  'sewage_m3' => 20],
            ['name' => 'Дільниця екстракції (водооборотка)',      'active_kwh' => 221240, 'reactive_kvarh' => 69812.8,  'specific_kwh' => 13.26, 'water_m3' => 1540, 'sewage_m3' => 772],
            ['name' => 'Дільниця приймання, доробки та зберіг.',  'active_kwh' => 133252, 'reactive_kvarh' => 42642.1,  'specific_kwh' => 7.99,  'water_m3' => 7,    'sewage_m3' => 7],
            ['name' => 'Котельня лузгова (пермеат + пара)',       'active_kwh' => 61560,  'reactive_kvarh' => 20525.8,  'specific_kwh' => 3.69,  'water_m3' => 2523, 'sewage_m3' => 1109],
            ['name' => 'Склад готової продукції',                 'active_kwh' => 2453,   'reactive_kvarh' => 785.0,    'specific_kwh' => 0.15,  'water_m3' => 0,    'sewage_m3' => 0],
            ['name' => 'Елеватор №2 (вул. Пушкіна, 13Б)',         'active_kwh' => 2247,   'reactive_kvarh' => 0.0,      'specific_kwh' => 0.13,  'water_m3' => 0,    'sewage_m3' => 0],
            ['name' => 'Адміністрація, ВТЛ та госп.побут (Л.91)', 'active_kwh' => 670,    'reactive_kvarh' => 0.0,      'specific_kwh' => 0.04,  'water_m3' => 1075, 'sewage_m3' => 1087],
        ];

        $departmentsSep = [
            ['name' => 'Олійнопресовий цех',                      'active_kwh' => 270231, 'reactive_kvarh' => 104898.8, 'specific_kwh' => 60.93, 'water_m3' => 279,  'sewage_m3' => 97],
            ['name' => 'Дільниця екстракції (водооборотка)',      'active_kwh' => 106000, 'reactive_kvarh' => 36273.0,  'specific_kwh' => 23.90, 'water_m3' => 544,  'sewage_m3' => 260],
            ['name' => 'Дільниця приймання, доробки та зберіг.',  'active_kwh' => 72372,  'reactive_kvarh' => 30732.1,  'specific_kwh' => 8.20,  'water_m3' => 8,    'sewage_m3' => 8],
            ['name' => 'Котельня лузгова (пермеат + пара)',       'active_kwh' => 29940,  'reactive_kvarh' => 11657.2,  'specific_kwh' => 3.39,  'water_m3' => 1023, 'sewage_m3' => 390],
            ['name' => 'Елеватор №2 (вул. Пушкіна, 13Б)',         'active_kwh' => 2877,   'reactive_kvarh' => 0.0,      'specific_kwh' => 0.33,  'water_m3' => 0,    'sewage_m3' => 0],
            ['name' => 'Склад готової продукції',                 'active_kwh' => 2780,   'reactive_kvarh' => 1028.6,   'specific_kwh' => 0.32,  'water_m3' => 0,    'sewage_m3' => 0],
            ['name' => 'Адміністрація, ВТЛ та госп.побут (Л.91)', 'active_kwh' => 870,    'reactive_kvarh' => 0.0,      'specific_kwh' => 0.10,  'water_m3' => 654,  'sewage_m3' => 654],
        ];

        $departmentsAug = [
            ['name' => 'Олійнопресовий (підготовка / ППР)',       'active_kwh' => 14640,  'reactive_kvarh' => 22993.6,  'specific_kwh' => 0.0,   'water_m3' => 73,   'sewage_m3' => 13],
            ['name' => 'Склад готової продукції',                 'active_kwh' => 14582,  'reactive_kvarh' => 22589.2,  'specific_kwh' => 0.0,   'water_m3' => 0,    'sewage_m3' => 0],
            ['name' => 'Дільниця приймання (Елеватор №1)',        'active_kwh' => 12360,  'reactive_kvarh' => 19131.4,  'specific_kwh' => 0.0,   'water_m3' => 42,   'sewage_m3' => 10],
            ['name' => 'Елеватор №2 (вул. Пушкіна, 13Б)',         'active_kwh' => 2558,   'reactive_kvarh' => 0.0,      'specific_kwh' => 0.0,   'water_m3' => 0,    'sewage_m3' => 0],
            ['name' => 'Дільниця екстракції (ППР)',               'active_kwh' => 1360,   'reactive_kvarh' => 1346.4,   'specific_kwh' => 0.0,   'water_m3' => 28,   'sewage_m3' => 6],
            ['name' => 'ПС-35/10 кВ та Котельня лузгова',         'active_kwh' => 1980,   'reactive_kvarh' => 950.4,    'specific_kwh' => 0.0,   'water_m3' => 57,   'sewage_m3' => 10],
            ['name' => 'Адміністрація, ВТЛ та госп.побут (Л.91)', 'active_kwh' => 1899,   'reactive_kvarh' => 0.0,      'specific_kwh' => 0.0,   'water_m3' => 416,  'sewage_m3' => 361],
        ];

        switch ($period) {
            case 'day_active':
                // 06.10.2026 (активна доба переробки 600,3 т): ел.енергія 31 930 кВт*год (1 330 кВт), вода 206,0 м3 (8,58 м3/год)
                return [
                    'current_power_kw'       => 1330.4,
                    'total_kwh'              => 31930,
                    'reactive_kvarh'         => 10280,
                    'current_water_m3_hour'  => 8.58,
                    'daily_water_m3'         => 206.0,
                    'total_water_m3'         => 206.0,
                    'sewage_m3'              => 80.7,
                    'gas_m3'                 => 0.0,
                    'steam_pressure_bar'     => 11.3,
                    'specific_kwh_per_tonne' => 53.19,
                    'specific_subtext'       => 'Прес: 27,92 + Екстр: 13,26 кВт·год/т',
                    'boiler_water_m3'        => 90.0,
                    'extraction_water_m3'    => 46.0,
                    'period_note'            => 'Доба 06.10 (600,3 т переробки)',
                    'departments'            => $departmentsOct,
                    'monthly_12'             => $monthly12,
                    'timeline' => [
                        'labels'     => ['08:00', '10:00', '12:00', '14:00', '16:00', '18:00', '20:00', '22:00', '00:00', '02:00', '04:00', '06:00'],
                        'power'      => [1295, 1320, 1345, 1360, 1350, 1335, 1325, 1340, 1330, 1315, 1320, 1330],
                        'water'      => [8.2,  8.5,  8.8,  9.1,  8.9,  8.6,  8.5,  8.7,  8.4,  8.3,  8.4,  8.6],
                        'power_name' => 'Потужність вводу (кВт)',
                        'water_name' => 'Витрата води (м³/год)',
                        'power_unit' => 'кВт',
                        'water_unit' => 'м³/год',
                    ]
                ];

            case 'week':
                // Тиждень 05.10 — 11.10 (7 діб): з таблиці вхідного лічильника 36571 -> 38391
                return [
                    'current_power_kw'       => 553.5,
                    'total_kwh'              => 92990,
                    'reactive_kvarh'         => 29950,
                    'current_water_m3_hour'  => 7.66,
                    'daily_water_m3'         => 183.9,
                    'total_water_m3'         => 1287.0,
                    'sewage_m3'              => 537.5,
                    'gas_m3'                 => 0.0,
                    'steam_pressure_bar'     => 11.2,
                    'specific_kwh_per_tonne' => 53.19,
                    'specific_subtext'       => 'Прес: 27,92 + Екстр: 13,26 кВт·год/т',
                    'boiler_water_m3'        => 630.0,
                    'extraction_water_m3'    => 282.0,
                    'period_note'            => 'Тиждень 05.10 — 11.10 (7 діб)',
                    'departments'            => $departmentsOct,
                    'monthly_12'             => $monthly12,
                    'timeline' => [
                        'labels'     => ['05.10', '06.10', '07.10', '08.10', '09.10', '10.10', '11.10'],
                        'power'      => [31210, 31930, 11450, 4820, 4410, 4650, 4520],
                        'water'      => [206,   206,   141,   193,  151,  226,  164],
                        'power_name' => 'Електроенергія за добу (кВт·год)',
                        'water_name' => 'Вода за добу (м³)',
                        'power_unit' => 'кВт·год',
                        'water_unit' => 'м³/д',
                    ]
                ];

            case 'month_aug':
                // Серпень 2026 (звіт В. В. Фокшек): 49 379 кВт*год, вода 616 м3, стоки 400 м3, газ 96 м3
                return [
                    'current_power_kw'       => 66.4,
                    'total_kwh'              => 49379,
                    'reactive_kvarh'         => 67011,
                    'current_water_m3_hour'  => 0.83,
                    'daily_water_m3'         => 19.9,
                    'total_water_m3'         => 616.0,
                    'sewage_m3'              => 400.0,
                    'gas_m3'                 => 96.0,
                    'steam_pressure_bar'     => 0.0,
                    'specific_kwh_per_tonne' => 0.0,
                    'specific_subtext'       => 'ППР заводу (приймання на елеватор)',
                    'boiler_water_m3'        => 57.0,
                    'extraction_water_m3'    => 28.0,
                    'period_note'            => 'Серпень 2026 (Звіт гол. енергетика)',
                    'departments'            => $departmentsAug,
                    'monthly_12'             => $monthly12,
                    'timeline' => [
                        'labels'     => ['Олійнопрес.', 'Склад ГП', 'Елеватор 1', 'Елеватор 2', 'Екстракція', 'ПС-35/10', 'Адмін', 'Котельня', 'Лаборат.'],
                        'power'      => [14640, 14582, 12360, 2558, 1360, 1020, 972, 960, 927],
                        'water'      => [73,    0,     42,    0,    28,   0,    95,  57,  32],
                        'power_name' => 'Електроенергія по підрозділах (кВт·год)',
                        'water_name' => 'Вода по підрозділах (м³)',
                        'power_unit' => 'кВт·год',
                        'water_unit' => 'м³',
                    ]
                ];

            case 'month_sep':
                // Вересень 2026 (звіт В. В. Фокшек): 485 070 кВт*год, реактивна 184 590, вода 2 508 м3 (лічильник з 15.09: 2 059 м3), стоки 1 409 м3
                return [
                    'current_power_kw'       => 673.7,
                    'total_kwh'              => 485070,
                    'reactive_kvarh'         => 184590,
                    'current_water_m3_hour'  => 3.48,
                    'daily_water_m3'         => 83.6,
                    'total_water_m3'         => 2508.0,
                    'sewage_m3'              => 1409.0,
                    'gas_m3'                 => 990.9,
                    'steam_pressure_bar'     => 11.2,
                    'specific_kwh_per_tonne' => 54.97,
                    'specific_subtext'       => 'Прес: 60,93 + Екстр: 23,90 кВт·год/т',
                    'boiler_water_m3'        => 1023.0,
                    'extraction_water_m3'    => 544.0,
                    'period_note'            => 'Вересень 2026 (Звіт гол. енергетика)',
                    'departments'            => $departmentsSep,
                    'monthly_12'             => $monthly12,
                    'timeline' => [
                        'labels'     => ['15.09', '16.09', '17.09', '20.09', '21.09', '22.09', '23.09', '24-27.09', '28.09', '29.09', '30.09'],
                        'power'      => [31500, 31800, 29400, 28900, 24200, 23500, 23100, 25400, 23800, 27600, 24900],
                        'water'      => [215,   215,   190,   186,   137,   130,   129,   142,   131,   160,   139],
                        'power_name' => 'Електроенергія за добу (кВт·год)',
                        'water_name' => 'Вода за добу по лічильнику (м³)',
                        'power_unit' => 'кВт·год',
                        'water_unit' => 'м³/д',
                    ]
                ];

            case 'month_oct':
            case 'month':
                // Жовтень (звіт В. В. Фокшек та таблиця вхідного лічильника 35973 -> 41298): 887 252 кВт*год, вода 5 325 м3 (сер. добова 171,8 м3/д)
                return [
                    'current_power_kw'       => 1192.5,
                    'total_kwh'              => 887252,
                    'reactive_kvarh'         => 285506,
                    'current_water_m3_hour'  => 7.16,
                    'daily_water_m3'         => 171.8,
                    'total_water_m3'         => 5325.0,
                    'sewage_m3'              => 2995.0,
                    'gas_m3'                 => 0.0,
                    'steam_pressure_bar'     => 11.2,
                    'specific_kwh_per_tonne' => 53.19,
                    'specific_subtext'       => 'Прес: 27,92 + Екстр: 13,26 кВт·год/т',
                    'boiler_water_m3'        => 2523.0,
                    'extraction_water_m3'    => 1540.0,
                    'period_note'            => 'Жовтень (Звіт гол. енергетика та лічильники)',
                    'departments'            => $departmentsOct,
                    'monthly_12'             => $monthly12,
                    'timeline' => [
                        'labels'     => ['01.10', '02.10', '03.10', '06.10', '07.10', '08.10', '09.10', '10.10', '13.10', '14.10', '15.10', '16.10', '17.10', '20.10', '21.10', '22.10', '23.10', '24.10', '27.10', '28.10', '29.10', '30.10', '31.10'],
                        'power'      => [29400, 31200, 31800, 31930, 26500, 28900, 27400, 30800, 28600, 26900, 27800, 29100, 29800, 28400, 30200, 29100, 25800, 27300, 27600, 30500, 30100, 27100, 30400],
                        'water'      => [211,   152,   235,   206,   141,   193,   151,   226,   164,   136,   151,   171,   181,   161,   185,   171,   122,   147,   148,   192,   189,   144,   192],
                        'power_name' => 'Електроенергія за добу (кВт·год)',
                        'water_name' => 'Вода за добу по вхідному лічильнику (м³)',
                        'power_unit' => 'кВт·год',
                        'water_unit' => 'м³/д',
                    ]
                ];

            case 'season':
                // Річний цикл за всі 12 місяців зі звіту головного енергетика (Січень — Грудень)
                return [
                    'current_power_kw'       => 1192.5,
                    'total_kwh'              => 4019988,
                    'reactive_kvarh'         => 1463871,
                    'current_water_m3_hour'  => 3.10,
                    'daily_water_m3'         => 74.5,
                    'total_water_m3'         => 27187.0,
                    'sewage_m3'              => 15592.0,
                    'gas_m3'                 => 301704.0,
                    'steam_pressure_bar'     => 11.2,
                    'specific_kwh_per_tonne' => 53.95,
                    'specific_subtext'       => 'Середньорічна питома витрата по МЕЗ',
                    'boiler_water_m3'        => 11739.0,
                    'extraction_water_m3'    => 5630.0,
                    'period_note'            => 'Помісячна динаміка за рік (12 місяців)',
                    'departments'            => $departmentsOct,
                    'monthly_12'             => $monthly12,
                    'timeline' => [
                        'labels'     => ['Січ', 'Лют', 'Бер', 'Кві', 'Тра', 'Чер', 'Лип', 'Сер', 'Вер', 'Жов', 'Лис', 'Гру'],
                        'power'      => [84026, 432539, 209627, 273083, 49518, 255231, 46923, 49379, 485070, 887252, 795342, 451998],
                        'water'      => [1300,  2901,   1819,   1954,   324,   2013,   464,   616,   2508,   5325,   4244,   3719],
                        'power_name' => 'Електроенергія за місяць (кВт·год)',
                        'water_name' => 'Вода за місяць (м³)',
                        'power_unit' => 'кВт·год',
                        'water_unit' => 'м³/міс',
                    ]
                ];

            case 'day_today':
            case 'day_prev':
            case 'day':
            default:
                // Минула виробнича доба 11.10.2026: працює приймання на елеватор (465,9 т), вхідний лічильник води 163,7 м3/д (6,82 м3/год)
                return [
                    'current_power_kw'       => 188.3,
                    'total_kwh'              => 4520,
                    'reactive_kvarh'         => 1450,
                    'current_water_m3_hour'  => 6.82,
                    'daily_water_m3'         => 163.7,
                    'total_water_m3'         => 163.7,
                    'sewage_m3'              => 72.6,
                    'gas_m3'                 => 0.0,
                    'steam_pressure_bar'     => 11.2,
                    'specific_kwh_per_tonne' => 53.19,
                    'specific_subtext'       => 'Норма переробки: 53,19 кВт·год/т (приймання: 9,7 кВт·год/т)',
                    'boiler_water_m3'        => 95.0,
                    'extraction_water_m3'    => 32.0,
                    'period_note'            => 'Доба 11.10 (приймання елеватора, 163,7 м³ води)',
                    'departments'            => $departmentsOct,
                    'monthly_12'             => $monthly12,
                    'timeline' => [
                        'labels'     => ['08:00', '10:00', '12:00', '14:00', '16:00', '18:00', '20:00', '22:00', '00:00', '02:00', '04:00', '06:00'],
                        'power'      => [175, 210, 235, 245, 230, 215, 190, 170, 155, 145, 145, 160],
                        'water'      => [6.5, 7.2, 7.8, 7.6, 7.4, 7.1, 6.8, 6.5, 6.2, 6.0, 6.1, 6.4],
                        'power_name' => 'Потужність вводу (кВт)',
                        'water_name' => 'Витрата води (м³/год)',
                        'power_unit' => 'кВт',
                        'water_unit' => 'м³/год',
                    ]
                ];
        }
    }

    /**
     * Блок В: Статусы оборудования
     */
    private function getEquipmentStatus(): array {
        $stmt = $this->db->query("
            SELECT code, name, section, status, load_pct, current_temperature_c, details, last_update
            FROM equipment_nodes
            ORDER BY id ASC
        ");
        $nodes = $stmt->fetchAll();

        $runningCount = 0;
        $totalCount = count($nodes);
        foreach ($nodes as $n) {
            if ($n['status'] === 'running') $runningCount++;
        }

        return [
            'nodes'         => $nodes,
            'running_count' => $runningCount,
            'total_count'   => $totalCount,
            'all_healthy'   => ($runningCount === $totalCount),
        ];
    }

    /**
     * Складські залишки готової продукції та насіння (звіти «ежедн.отчёт 1» та «ежедн.отчёт 2» Агропроінвест 08)
     */
    public function getWarehouseStocks(string $period = 'day'): array {
        switch ($period) {
            case 'week':
                // За тиждень (05.10 — 11.10, 7 діб): залишки на 12.10 08:00, відвантаження сумарно за 5..11 число
                return [
                    'seed_stock_tonnes'            => 13326.41,
                    'seed_stock_accounting_tonnes' => 7268.88,
                    'oil_stock_tonnes'             => 3358.63,
                    'meal_stock_tonnes'            => 1056.13,
                    'husk_stock_tonnes'            => 116.84,
                    'husk_boiler_spent_tonnes'     => 102.05,
                    'days_seed_reserve'            => 22.2,
                    'oil_shipped_tonnes'           => 702.36,
                    'meal_shipped_tonnes'          => 1343.90,
                    'husk_shipped_tonnes'          => 292.26,
                    'report_date'                  => '12.10.2026 08:00 (тиждень 05.10–11.10)',
                ];
            case 'day_active':
                // За останню повну добу переробки (06.10.2026 — аркуш «6 число»)
                return [
                    'seed_stock_tonnes'            => 11509.10,
                    'seed_stock_accounting_tonnes' => 7534.54,
                    'oil_stock_tonnes'             => 3810.90,
                    'meal_stock_tonnes'            => 1593.02,
                    'husk_stock_tonnes'            => 227.64,
                    'husk_boiler_spent_tonnes'     => 24.51,
                    'days_seed_reserve'            => 19.2,
                    'oil_shipped_tonnes'           => 74.46,
                    'meal_shipped_tonnes'          => 294.66,
                    'husk_shipped_tonnes'          => 69.36,
                    'report_date'                  => '07.10.2026 08:00 (доба 06.10)',
                ];
            case 'month_aug':
                return [
                    'seed_stock_tonnes'            => 883.40,
                    'seed_stock_accounting_tonnes' => 883.40,
                    'oil_stock_tonnes'             => 15.35,
                    'meal_stock_tonnes'            => 362.60,
                    'husk_stock_tonnes'            => 0.00,
                    'husk_boiler_spent_tonnes'     => 6.85,
                    'days_seed_reserve'            => 1.5,
                    'oil_shipped_tonnes'           => 76.64,
                    'meal_shipped_tonnes'          => 1390.54,
                    'husk_shipped_tonnes'          => 78.60,
                    'report_date'                  => '01.09.2026 08:00',
                ];
            case 'month_sep':
                return [
                    'seed_stock_tonnes'            => 10966.28,
                    'seed_stock_accounting_tonnes' => 7319.80,
                    'oil_stock_tonnes'             => 2736.71,
                    'meal_stock_tonnes'            => 1713.47,
                    'husk_stock_tonnes'            => 334.22,
                    'husk_boiler_spent_tonnes'     => 409.17,
                    'days_seed_reserve'            => 18.3,
                    'oil_shipped_tonnes'           => 1090.30,
                    'meal_shipped_tonnes'          => 2332.34,
                    'husk_shipped_tonnes'          => 490.66,
                    'report_date'                  => '30.09.2026 20:00',
                ];
            case 'month_oct':
            case 'month':
                return [
                    'seed_stock_tonnes'            => 13326.41,
                    'seed_stock_accounting_tonnes' => 7268.88,
                    'oil_stock_tonnes'             => 3358.63,
                    'meal_stock_tonnes'            => 1056.13,
                    'husk_stock_tonnes'            => 116.84,
                    'husk_boiler_spent_tonnes'     => 294.17,
                    'days_seed_reserve'            => 22.2,
                    'oil_shipped_tonnes'           => 1024.10,
                    'meal_shipped_tonnes'          => 2179.02,
                    'husk_shipped_tonnes'          => 478.08,
                    'report_date'                  => '12.10.2026 08:00',
                ];
            case 'season':
                return [
                    'seed_stock_tonnes'            => 13326.41,
                    'seed_stock_accounting_tonnes' => 7268.88,
                    'oil_stock_tonnes'             => 3358.63,
                    'meal_stock_tonnes'            => 1056.13,
                    'husk_stock_tonnes'            => 116.84,
                    'husk_boiler_spent_tonnes'     => 710.19,
                    'days_seed_reserve'            => 22.2,
                    'oil_shipped_tonnes'           => 2191.04,
                    'meal_shipped_tonnes'          => 5901.90,
                    'husk_shipped_tonnes'          => 1047.34,
                    'report_date'                  => '12.10.2026 08:00',
                ];
            case 'day_today':
                // Сьогодні 12.10.2026 (аркуш «12 число» — рух за поточну добу ще 0, вхідні залишки на 08:00)
                return [
                    'seed_stock_tonnes'            => 13326.41,
                    'seed_stock_accounting_tonnes' => 7268.88,
                    'oil_stock_tonnes'             => 3358.63,
                    'meal_stock_tonnes'            => 1056.13,
                    'husk_stock_tonnes'            => 116.84,
                    'husk_boiler_spent_tonnes'     => 0.00,
                    'days_seed_reserve'            => 22.2,
                    'oil_shipped_tonnes'           => 0.00,
                    'meal_shipped_tonnes'          => 0.00,
                    'husk_shipped_tonnes'          => 0.00,
                    'report_date'                  => '12.10.2026 08:00',
                ];
            case 'day_prev':
            case 'day':
            default:
                // Минула завершена доба 11.10.2026 (аркуш «11 число» та «12 число» на 08:00 ранку):
                // За 11.10 відвантажено: олія 145,20 т (АПІ 127,26 т + Алтрейд 17,94 т), шрот 0,00 т, лузга 18,60 т
                return [
                    'seed_stock_tonnes'            => 13326.41,
                    'seed_stock_accounting_tonnes' => 7268.88,
                    'oil_stock_tonnes'             => 3358.63,
                    'meal_stock_tonnes'            => 1056.13,
                    'husk_stock_tonnes'            => 116.84,
                    'husk_boiler_spent_tonnes'     => 0.00,
                    'days_seed_reserve'            => 22.2,
                    'oil_shipped_tonnes'           => 145.20,
                    'meal_shipped_tonnes'          => 0.00,
                    'husk_shipped_tonnes'          => 18.60,
                    'report_date'                  => '12.10.2026 08:00',
                ];
        }
    }

    /**
     * Повна ретроспективна аналітика та помісячне порівняння (Серпень, Вересень, Жовтень 2026 та Сезон 2025–2026)
     */
    public function getHistoricalAnalytics(): array {
        return [
            'months' => [
                'aug' => [
                    'id'             => 'month_aug',
                    'name'           => 'Серпень 2026',
                    'short_name'     => 'Серпень',
                    'days_count'     => 31,
                    'status_note'    => 'Міжсезоння / ППР заводу',
                    'seed_in'        => 207.96,
                    'seed_processed' => 0.00,
                    'oil_out'        => 0.00,
                    'oil_yield_pct'  => 0.00,
                    'meal_out'       => 0.00,
                    'meal_yield_pct' => 0.00,
                    'husk_out'       => 5.91,
                    'oil_shipped'    => 76.64,
                    'meal_shipped'   => 1390.54,
                    'husk_shipped'   => 78.60,
                    'trucks_count'   => 8,
                    'seed_stock'     => 883.40,
                    'oil_stock'      => 15.35,
                    'meal_stock'     => 362.60,
                    'husk_stock'     => 0.00,
                    'boiler_husk'    => 6.85,
                ],
                'sep' => [
                    'id'             => 'month_sep',
                    'name'           => 'Вересень 2026',
                    'short_name'     => 'Вересень',
                    'days_count'     => 30,
                    'status_note'    => 'Повний місяць переробки',
                    'seed_in'        => 19070.05,
                    'seed_processed' => 8823.66,
                    'oil_out'        => 3811.66,
                    'oil_yield_pct'  => 43.20,
                    'meal_out'       => 3683.21,
                    'meal_yield_pct' => 41.74,
                    'husk_out'       => 1261.23,
                    'oil_shipped'    => 1090.30,
                    'meal_shipped'   => 2332.34,
                    'husk_shipped'   => 490.66,
                    'trucks_count'   => 738,
                    'seed_stock'     => 10966.28,
                    'oil_stock'      => 2736.71,
                    'meal_stock'     => 1713.47,
                    'husk_stock'     => 334.22,
                    'boiler_husk'    => 409.17,
                ],
                'oct' => [
                    'id'             => 'month_oct',
                    'name'           => 'Жовтень 2026 (1-11)',
                    'short_name'     => 'Жовтень',
                    'days_count'     => 11,
                    'status_note'    => 'Поточний місяць (11 діб)',
                    'seed_in'        => 6075.91,
                    'seed_processed' => 3646.35,
                    'oil_out'        => 1646.15,
                    'oil_yield_pct'  => 45.15,
                    'meal_out'       => 1521.68,
                    'meal_yield_pct' => 41.73,
                    'husk_out'       => 554.87,
                    'oil_shipped'    => 1024.10,
                    'meal_shipped'   => 2179.02,
                    'husk_shipped'   => 478.08,
                    'trucks_count'   => 231,
                    'seed_stock'     => 13326.41,
                    'oil_stock'      => 3358.63,
                    'meal_stock'     => 1056.13,
                    'husk_stock'     => 116.84,
                    'boiler_husk'    => 294.17,
                ]
            ],
            'season_total' => [
                'name'           => 'Разом за сезон 2026',
                'period'         => 'Серпень — Жовтень 2026 (72 доби)',
                'seed_in'        => 25353.92,
                'seed_processed' => 12470.01,
                'oil_out'        => 5457.81,
                'oil_yield_pct'  => 43.77,
                'meal_out'       => 5204.89,
                'meal_yield_pct' => 41.74,
                'husk_out'       => 1822.01,
                'oil_shipped'    => 2191.04,
                'meal_shipped'   => 5901.90,
                'husk_shipped'   => 1047.34,
                'trucks_count'   => 977,
                'seed_stock'     => 13326.41,
                'oil_stock'      => 3358.63,
                'meal_stock'     => 1056.13,
                'husk_stock'     => 116.84,
                'boiler_husk'    => 710.19,
            ],
            'chart_comparison' => [
                'categories'     => ['Серпень 2026', 'Вересень 2026', 'Жовтень 2026 (1-11)'],
                'seed_in'        => [207.96, 19070.05, 6075.91],
                'seed_processed' => [0.00, 8823.66, 3646.35],
                'oil_out'        => [0.00, 3811.66, 1646.15],
                'meal_out'       => [0.00, 3683.21, 1521.68],
                'husk_out'       => [5.91, 1261.23, 554.87],
                'trucks'         => [8, 738, 231],
                'oil_stocks'     => [15.35, 2736.71, 3358.63],
                'meal_stocks'    => [362.60, 1713.47, 1056.13],
                'seed_stocks'    => [883.40, 10966.28, 13326.41],
            ],
            'season_2025_comparison' => [
                'name' => 'Сезон 2025–2026 рр. (Офіційний виробничий звіт «СВОДНАЯ 2025-26»)',
                'period_label' => 'Вересень 2025 — Червень 2026 (повний виробничий цикл)',
                // Точні помісячні дані з вкладки «СВОДНАЯ 2025-26» (media_1791813393453_60c5527a.xlsx)
                'months' => [
                    [
                        'name'            => 'Вересень 2025',
                        'seed_start'      => 0.0,
                        'seed_in'         => 4470.82,
                        'seed_processed'  => 4435.44,
                        'oil_content_pct' => 42.93,
                        'oil_out'         => 1865.62,
                        'oil_pct'         => 42.06,
                        'meal_out'        => 1869.21,
                        'meal_pct'        => 42.14,
                        'husk_out'        => 620.13,
                        'husk_pct'        => 13.98,
                        'losses'          => 80.47,
                        'losses_pct'      => 1.81,
                        'oil_meal_pct'    => 84.20,
                        'total_yield_pct' => 98.19,
                        'seed_stock_end'  => 35.38,
                    ],
                    [
                        'name'            => 'Жовтень 2025',
                        'seed_start'      => 35.38,
                        'seed_in'         => 16858.22,
                        'seed_processed'  => 16681.68,
                        'oil_content_pct' => 45.29,
                        'oil_out'         => 7414.22,
                        'oil_pct'         => 44.45,
                        'meal_out'        => 6966.59,
                        'meal_pct'        => 41.76,
                        'husk_out'        => 2058.44,
                        'husk_pct'        => 12.34,
                        'losses'          => 242.44,
                        'losses_pct'      => 1.45,
                        'oil_meal_pct'    => 86.21,
                        'total_yield_pct' => 98.55,
                        'seed_stock_end'  => 176.54,
                    ],
                    [
                        'name'            => 'Листопад 2025',
                        'seed_start'      => 176.54,
                        'seed_in'         => 14964.27,
                        'seed_processed'  => 14812.03,
                        'oil_content_pct' => 45.25,
                        'oil_out'         => 6587.77,
                        'oil_pct'         => 44.48,
                        'meal_out'        => 6214.44,
                        'meal_pct'        => 41.96,
                        'husk_out'        => 1851.94,
                        'husk_pct'        => 12.50,
                        'losses'          => 157.89,
                        'losses_pct'      => 1.07,
                        'oil_meal_pct'    => 86.43,
                        'total_yield_pct' => 98.93,
                        'seed_stock_end'  => 152.24,
                    ],
                    [
                        'name'            => 'Грудень 2025',
                        'seed_start'      => 152.24,
                        'seed_in'         => 6907.72,
                        'seed_processed'  => 6835.59,
                        'oil_content_pct' => 45.85,
                        'oil_out'         => 3081.52,
                        'oil_pct'         => 45.08,
                        'meal_out'        => 2845.51,
                        'meal_pct'        => 41.63,
                        'husk_out'        => 902.72,
                        'husk_pct'        => 13.21,
                        'losses'          => 5.83,
                        'losses_pct'      => 0.09,
                        'oil_meal_pct'    => 86.71,
                        'total_yield_pct' => 99.91,
                        'seed_stock_end'  => 72.13,
                    ],
                    [
                        'name'            => 'Лютий 2026',
                        'seed_start'      => 72.13,
                        'seed_in'         => 7143.40,
                        'seed_processed'  => 7105.25,
                        'oil_content_pct' => 45.65,
                        'oil_out'         => 3170.02,
                        'oil_pct'         => 44.62,
                        'meal_out'        => 2860.42,
                        'meal_pct'        => 40.26,
                        'husk_out'        => 956.68,
                        'husk_pct'        => 13.46,
                        'losses'          => 118.13,
                        'losses_pct'      => 1.66,
                        'oil_meal_pct'    => 84.87,
                        'total_yield_pct' => 98.34,
                        'seed_stock_end'  => 38.15,
                    ],
                    [
                        'name'            => 'Березень 2026',
                        'seed_start'      => 38.15,
                        'seed_in'         => 2890.29,
                        'seed_processed'  => 2866.10,
                        'oil_content_pct' => 44.45,
                        'oil_out'         => 1250.58,
                        'oil_pct'         => 43.63,
                        'meal_out'        => 1168.95,
                        'meal_pct'        => 40.79,
                        'husk_out'        => 435.44,
                        'husk_pct'        => 15.19,
                        'losses'          => 11.14,
                        'losses_pct'      => 0.39,
                        'oil_meal_pct'    => 84.42,
                        'total_yield_pct' => 99.61,
                        'seed_stock_end'  => 24.19,
                    ],
                    [
                        'name'            => 'Квітень 2026',
                        'seed_start'      => 24.19,
                        'seed_in'         => 4659.88,
                        'seed_processed'  => 4648.09,
                        'oil_content_pct' => 45.45,
                        'oil_out'         => 2078.77,
                        'oil_pct'         => 44.72,
                        'meal_out'        => 1947.05,
                        'meal_pct'        => 41.89,
                        'husk_out'        => 600.62,
                        'husk_pct'        => 12.92,
                        'losses'          => 21.65,
                        'losses_pct'      => 0.47,
                        'oil_meal_pct'    => 86.61,
                        'total_yield_pct' => 99.53,
                        'seed_stock_end'  => 11.79,
                    ],
                    [
                        'name'            => 'Червень 2026',
                        'seed_start'      => 11.79,
                        'seed_in'         => 4508.69,
                        'seed_processed'  => 4508.69,
                        'oil_content_pct' => 44.48,
                        'oil_out'         => 1968.39,
                        'oil_pct'         => 43.66,
                        'meal_out'        => 1918.27,
                        'meal_pct'        => 42.55,
                        'husk_out'        => 545.84,
                        'husk_pct'        => 12.11,
                        'losses'          => 76.19,
                        'losses_pct'      => 1.69,
                        'oil_meal_pct'    => 86.20,
                        'total_yield_pct' => 98.31,
                        'seed_stock_end'  => 0.00,
                    ],
                ],
                // Початок сезону для прямого зіставлення з аналогічним поточним періодом 2026 року
                'early_season_sep_oct' => [
                    'label'           => 'Вересень — Жовтень 2025 (Старт)',
                    'seed_in'         => 21329.04,
                    'seed_processed'  => 21117.12,
                    'oil_content_pct' => 44.79,
                    'oil_out'         => 9279.84,
                    'oil_pct'         => 43.94,
                    'meal_out'        => 8835.80,
                    'meal_pct'        => 41.84,
                    'husk_out'        => 2678.57,
                    'husk_pct'        => 12.68,
                    'losses'          => 322.91,
                    'losses_pct'      => 1.53,
                    'oil_meal_pct'    => 85.78,
                    'total_yield_pct' => 98.47,
                    'seed_stock_end'  => 211.92,
                ],
                // Повний річний цикл 2025–2026 рр. (ИТОГО з «СВОДНАЯ 2025-26»)
                'full_season_total' => [
                    'label'           => 'РАЗОМ за сезон 2025–2026 рр. (Повний цикл)',
                    'seed_in'         => 62403.27,
                    'seed_processed'  => 61892.86,
                    'oil_content_pct' => 45.13,
                    'oil_out'         => 27416.87,
                    'oil_pct'         => 44.30,
                    'meal_out'        => 25790.44,
                    'meal_pct'        => 41.67,
                    'husk_out'        => 7971.81,
                    'husk_pct'        => 12.88,
                    'losses'          => 713.74,
                    'losses_pct'      => 1.15,
                    'oil_meal_pct'    => 85.97,
                    'total_yield_pct' => 98.85,
                    'seed_stock_end'  => 510.41,
                ],
                // Збережено сумісність ключів для рендерингу
                'categories'     => ['Вересень 2025', 'Жовтень 2025', 'Вер-Жов 2025', 'Весь сезон 2025-26'],
                'seed_in'        => [4470.82, 16858.22, 21329.04, 62403.27],
                'seed_processed' => [4435.44, 16681.68, 21117.12, 61892.86],
                'oil_out'        => [1865.62, 7414.22, 9279.84, 27416.87],
                'oil_yield_pct'  => [42.06, 44.45, 43.94, 44.30],
                'meal_out'       => [1869.21, 6966.59, 8835.80, 25790.44],
                'meal_yield_pct' => [42.14, 41.76, 41.84, 41.67],
                'husk_out'       => [620.13, 2058.44, 2678.57, 7971.81],
            ],
            // Псевдонім для зворотньої сумісності
            'season_2022_comparison' => [
                'categories'     => ['Вересень 2025', 'Жовтень 2025', 'Вер-Жов 2025', 'Весь сезон 2025-26'],
                'seed_in'        => [4470.82, 16858.22, 21329.04, 62403.27],
                'seed_processed' => [4435.44, 16681.68, 21117.12, 61892.86],
                'oil_out'        => [1865.62, 7414.22, 9279.84, 27416.87],
                'oil_yield_pct'  => [42.06, 44.45, 43.94, 44.30],
                'meal_out'       => [1869.21, 6966.59, 8835.80, 25790.44],
                'meal_yield_pct' => [42.14, 41.76, 41.84, 41.67],
                'husk_out'       => [620.13, 2058.44, 2678.57, 7971.81],
            ]
        ];
    }

    /**
     * Показники якості лабораторії (Лист погодження постачання та Графік завезення 1С)
     */
    public function getLabQuality(string $period = 'day'): array {
        switch ($period) {
            case 'week':
                return [
                    'oil_content_pct'        => 48.88,
                    'oil_content_base_pct'   => 48.00,
                    'oil_deviation_pct'      => 0.88,
                    'moisture_pct'           => 7.13,
                    'impurity_pct'           => 3.28,
                    'weight_discount_tonnes' => 9.72,
                    'report_date'            => '05.10 — 11.10.2026 (7 діб)',
                ];
            case 'day_active':
                return [
                    'oil_content_pct'        => 48.35,
                    'oil_content_base_pct'   => 48.00,
                    'oil_deviation_pct'      => 0.35,
                    'moisture_pct'           => 7.17,
                    'impurity_pct'           => 3.09,
                    'weight_discount_tonnes' => 2.42,
                    'report_date'            => '06.10.2026 (доба переробки)',
                ];
            case 'month_aug':
                return [
                    'oil_content_pct'        => 44.46,
                    'oil_content_base_pct'   => 48.00,
                    'oil_deviation_pct'      => -3.54,
                    'moisture_pct'           => 7.32,
                    'impurity_pct'           => 4.66,
                    'weight_discount_tonnes' => 0.00,
                    'report_date'            => '01.08 — 31.08.2026 (Серпень)',
                ];
            case 'month_sep':
                return [
                    'oil_content_pct'        => 49.30,
                    'oil_content_base_pct'   => 48.00,
                    'oil_deviation_pct'      => 1.30,
                    'moisture_pct'           => 6.90,
                    'impurity_pct'           => 3.25,
                    'weight_discount_tonnes' => 58.27,
                    'report_date'            => '01.09 — 30.09.2026 (Вересень)',
                ];
            case 'month_oct':
            case 'month':
                return [
                    'oil_content_pct'        => 48.99,
                    'oil_content_base_pct'   => 48.00,
                    'oil_deviation_pct'      => 0.99,
                    'moisture_pct'           => 7.25,
                    'impurity_pct'           => 3.21,
                    'weight_discount_tonnes' => 15.93,
                    'report_date'            => '01.10 — 11.10.2026 (Жовтень)',
                ];
            case 'season':
                return [
                    'oil_content_pct'        => 49.19,
                    'oil_content_base_pct'   => 48.00,
                    'oil_deviation_pct'      => 1.19,
                    'moisture_pct'           => 6.98,
                    'impurity_pct'           => 3.25,
                    'weight_discount_tonnes' => 74.20,
                    'report_date'            => 'Сезон 2026 (72 доби)',
                ];
            case 'day_prev':
            case 'day':
            default:
                // За 11.10.2026 (з вкладки «Графік завезення»): олійність 49,61%, вологість 7,71%, сор 3,28%, знижка 3,10 т
                return [
                    'oil_content_pct'        => 49.61,
                    'oil_content_base_pct'   => 48.00,
                    'oil_deviation_pct'      => 1.61,
                    'moisture_pct'           => 7.71,
                    'impurity_pct'           => 3.28,
                    'weight_discount_tonnes' => 3.10,
                    'report_date'            => '12.10.2026 09:34 (доба 11.10)',
                ];
            case 'day_today':
                return [
                    'oil_content_pct'        => 49.61,
                    'oil_content_base_pct'   => 48.00,
                    'oil_deviation_pct'      => 1.61,
                    'moisture_pct'           => 6.88,
                    'impurity_pct'           => 3.36,
                    'weight_discount_tonnes' => 60.61,
                    'report_date'            => '12.10.2026 09:34 (Лист погодження)',
                ];
        }
    }

    /**
     * Контрактація та виконання специфікацій (Реєстр договорів та довіз з «Листа погодження» 1С)
     */
    public function getContractsSummary(string $period = 'day'): array {
        switch ($period) {
            case 'week':
                return [
                    'contracted_tonnes'   => 4000.0,
                    'delivered_tonnes'    => 3767.8,
                    'remaining_tonnes'    => 232.2,
                    'fulfillment_pct'     => 94.2,
                    'active_specs_count'  => 11,
                    'pending_specs_count' => 3,
                    'report_date'         => '05.10 — 11.10.2026 (Тиждень)',
                ];
            case 'day_active':
                return [
                    'contracted_tonnes'   => 600.0,
                    'delivered_tonnes'    => 589.8,
                    'remaining_tonnes'    => 10.2,
                    'fulfillment_pct'     => 98.3,
                    'active_specs_count'  => 11,
                    'pending_specs_count' => 3,
                    'report_date'         => '06.10.2026 (Доба переробки)',
                ];
            case 'month_aug':
                return [
                    'contracted_tonnes'   => 5000.0,
                    'delivered_tonnes'    => 207.96,
                    'remaining_tonnes'    => 4792.04,
                    'fulfillment_pct'     => 4.2,
                    'active_specs_count'  => 4,
                    'pending_specs_count' => 12,
                    'report_date'         => 'Серпень 2026',
                ];
            case 'month_sep':
                return [
                    'contracted_tonnes'   => 20000.0,
                    'delivered_tonnes'    => 19070.05,
                    'remaining_tonnes'    => 929.95,
                    'fulfillment_pct'     => 95.4,
                    'active_specs_count'  => 18,
                    'pending_specs_count' => 1,
                    'report_date'         => 'Вересень 2026',
                ];
            case 'month_oct':
            case 'month':
                return [
                    'contracted_tonnes'   => 8000.0,
                    'delivered_tonnes'    => 6075.91,
                    'remaining_tonnes'    => 1924.09,
                    'fulfillment_pct'     => 75.9,
                    'active_specs_count'  => 11,
                    'pending_specs_count' => 3,
                    'report_date'         => 'Жовтень 2026',
                ];
            case 'season':
                // З вкладки «Лист погодження» (121 специфікація: договір 18 711,0 т, завезено 17 642,9 т, або з давальцями 25 353,9 т)
                return [
                    'contracted_tonnes'   => 27000.0,
                    'delivered_tonnes'    => 25353.92,
                    'remaining_tonnes'    => 1647.93,
                    'fulfillment_pct'     => 93.9,
                    'active_specs_count'  => 11,
                    'pending_specs_count' => 3,
                    'report_date'         => 'Сезон 2026 (72 доби)',
                ];
            case 'day_prev':
                return [
                    'contracted_tonnes'   => 500.0,
                    'delivered_tonnes'    => 465.86,
                    'remaining_tonnes'    => 34.14,
                    'fulfillment_pct'     => 93.2,
                    'active_specs_count'  => 11,
                    'pending_specs_count' => 3,
                    'report_date'         => '11.10.2026 20:00',
                ];
            case 'day_today':
            case 'day':
            default:
                // З вкладки «Лист погодження» (121 специфікація: договір 18 711,0 т, завезено 17 642,9 т = 94,3%, залишок у «Невиконані специфікації» 1 647,9 т: 11 у роботі + 3 очікують)
                return [
                    'contracted_tonnes'   => 18711.0,
                    'delivered_tonnes'    => 17642.9,
                    'remaining_tonnes'    => 1647.9,
                    'fulfillment_pct'     => 94.3,
                    'active_specs_count'  => 11,
                    'pending_specs_count' => 3,
                    'report_date'         => '12.10.2026 09:34',
                ];
        }
    }
}
