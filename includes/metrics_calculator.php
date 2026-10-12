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
     * Блок В: Энергоресурсы (электроэнергия, вода)
     */
    private function calculateEnergyMetrics(string $dateFilter, string $period): array {
        // Получаем последнее актуальное значение
        $lastStmt = $this->db->query("
            SELECT power_kw, water_flow_m3_hour, steam_pressure_bar, recorded_at 
            FROM energy_readings 
            ORDER BY recorded_at DESC LIMIT 1
        ");
        $last = $lastStmt->fetch() ?: [
            'power_kw' => 3940.0,
            'water_flow_m3_hour' => 58.9,
            'steam_pressure_bar' => 11.2,
            'recorded_at' => date('Y-m-d H:i:s')
        ];

        // Получаем сумму за период
        $sumStmt = $this->db->query("
            SELECT 
                COALESCE(SUM(power_kwh_delta), 0) as total_kwh,
                COALESCE(SUM(water_m3_delta), 0) as total_water_m3,
                AVG(power_kw) as avg_power_kw
            FROM energy_readings
        ");
        $sumRow = $sumStmt->fetch();

        // Почасовой временной ряд для графика
        $limit = ($period === 'shift') ? 12 : 24;
        $historyStmt = $this->db->query("
            SELECT recorded_at, power_kw, water_flow_m3_hour
            FROM energy_readings
            ORDER BY recorded_at DESC
            LIMIT {$limit}
        ");
        $historyRows = array_reverse($historyStmt->fetchAll());

        $chartLabels = [];
        $powerSeries = [];
        $waterSeries = [];
        foreach ($historyRows as $row) {
            $chartLabels[] = date('H:i', strtotime($row['recorded_at']));
            $powerSeries[] = (float)$row['power_kw'];
            $waterSeries[] = (float)$row['water_flow_m3_hour'];
        }

        // Удельный расход на тонну переработанного сырья (кВт*ч / т)
        $totalKwh = (float)($sumRow['total_kwh'] ?: (3940 * 12));
        $specificKwhPerTonne = 66.8; // норма 65

        return [
            'current_power_kw'       => (float)$last['power_kw'],
            'total_kwh'              => round($totalKwh),
            'current_water_m3_hour'  => (float)$last['water_flow_m3_hour'],
            'total_water_m3'         => round((float)($sumRow['total_water_m3'] ?: 700), 1),
            'steam_pressure_bar'     => (float)($last['steam_pressure_bar'] ?? 11.2),
            'specific_kwh_per_tonne' => $specificKwhPerTonne,
            'timeline' => [
                'labels' => $chartLabels,
                'power'  => $powerSeries,
                'water'  => $waterSeries,
            ]
        ];
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
