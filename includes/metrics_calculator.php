<?php
/**
 * Калькулятор производственных показателей, материального баланса и энергоэффективности
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/crm_client.php';
require_once __DIR__ . '/zabbix_client.php';
require_once __DIR__ . '/semena_helper.php';

class MetricsCalculator {
    private PDO $db;
    private array $config;
    private array $thresholds;
    private ?array $semenaCache = null;

    public function __construct() {
        $this->db = DashboardDB::getConnection();
        $this->config = get_config();
        $this->thresholds = $this->config['thresholds'] ?? [];
    }

    /**
     * Завантажити дані з Листа погодження (кешовано в межах запиту)
     */
    private function getSemenaData(): array {
        if ($this->semenaCache === null) {
            $this->semenaCache = SemenaHelper::loadData();
        }
        return $this->semenaCache;
    }

    /**
     * Динамічний розрахунок фізичного завезення (без очистки та сушки) та якості лабораторії з Листа погодження
     */
    private function getSemenaPeriodStats(string $period): array {
        $semena = $this->getSemenaData();
        $dailyRows = $semena['daily_rows'] ?? [];
        if (empty($dailyRows)) {
            return [];
        }

        $filtered = [];
        switch ($period) {
            case 'day_active':
                foreach ($dailyRows as $r) {
                    if (($r['date'] ?? '') === '06.10.2026') {
                        $filtered[] = $r;
                    }
                }
                if (empty($filtered)) {
                    $filtered = [end($dailyRows)];
                }
                break;
            case 'week':
                $filtered = array_slice($dailyRows, -7);
                break;
            case 'month_aug':
                foreach ($dailyRows as $r) {
                    if (strpos($r['date'] ?? '', '.08.') !== false) {
                        $filtered[] = $r;
                    }
                }
                break;
            case 'month_sep':
                foreach ($dailyRows as $r) {
                    if (strpos($r['date'] ?? '', '.09.') !== false) {
                        $filtered[] = $r;
                    }
                }
                break;
            case 'month_oct':
            case 'month':
                foreach ($dailyRows as $r) {
                    if (strpos($r['date'] ?? '', '.10.') !== false) {
                        $filtered[] = $r;
                    }
                }
                break;
            case 'season':
                $filtered = $dailyRows;
                break;
            case 'shift':
                $last = end($dailyRows);
                if ($last) {
                    $half = $last;
                    $half['delivered'] = round(((float)($last['delivered'] ?? 0)) / 2, 3);
                    $half['credit_weight'] = round(((float)($last['credit_weight'] ?? 0)) / 2, 3);
                    $half['discount'] = round(((float)($last['discount'] ?? 0)) / 2, 3);
                    $filtered = [$half];
                }
                break;
            case 'day_today':
                $filtered = [end($dailyRows)];
                break;
            case 'day_prev':
                $filtered = (count($dailyRows) >= 2) ? [$dailyRows[count($dailyRows) - 2]] : [end($dailyRows)];
                break;
            case 'day':
            default:
                $filtered = [end($dailyRows)];
                break;
        }

        if (empty($filtered)) {
            return [];
        }

        $sumDelivered = 0.0;
        $sumCredit    = 0.0;
        $sumDiscount  = 0.0;
        $sumTrucks    = 0;
        $wOil         = 0.0;
        $wOilWeight   = 0.0;
        $wMoist       = 0.0;
        $wMoistWeight = 0.0;
        $wTrash       = 0.0;
        $wTrashWeight = 0.0;

        foreach ($filtered as $r) {
            $deliv  = (float)($r['delivered'] ?? 0);
            $cred   = (float)($r['credit_weight'] ?? $deliv);
            $disc   = (float)($r['discount'] ?? ($deliv - $cred));
            $trucks = (int)($r['cars'] ?? 0);
            $oil    = (float)($r['oil'] ?? 0);
            $moist  = (float)($r['moisture'] ?? 0);
            $trash  = (float)($r['trash'] ?? 0);

            $sumDelivered += $deliv;
            $sumCredit    += $cred;
            $sumDiscount  += $disc;
            $sumTrucks    += $trucks;

            if ($deliv > 0) {
                if ($oil > 0) {
                    $wOil += $deliv * $oil;
                    $wOilWeight += $deliv;
                }
                if ($moist > 0) {
                    $wMoist += $deliv * $moist;
                    $wMoistWeight += $deliv;
                }
                if ($trash > 0) {
                    $wTrash += $deliv * $trash;
                    $wTrashWeight += $deliv;
                }
            }
        }

        $firstDate = $filtered[0]['date'] ?? '';
        $lastDate  = end($filtered)['date'] ?? '';

        return [
            'seed_in_plant'          => round($sumDelivered, 2), // Фізична вага з Листа погодження (без очистки та сушки)
            'credit_weight'          => round($sumCredit, 2),
            'weight_discount_tonnes' => round(abs($sumDiscount), 2),
            'trucks_count'           => $sumTrucks,
            'days_count'             => count($filtered),
            'oil_content_pct'        => $wOilWeight > 0 ? round($wOil / $wOilWeight, 2) : 48.0,
            'moisture_pct'           => $wMoistWeight > 0 ? round($wMoist / $wMoistWeight, 2) : 7.0,
            'impurity_pct'           => $wTrashWeight > 0 ? round($wTrash / $wTrashWeight, 2) : 3.0,
            'first_date'             => $firstDate,
            'last_date'              => $lastDate,
        ];
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

        $semenaStats = $this->getSemenaPeriodStats($period);
        $lastDateLabel = $semenaStats['last_date'] ?? '12.10.2026';
        $seedInVal = $materialBalance['receiving_zone']['seed_in_plant']
            ?? $materialBalance['seed_in_plant']
            ?? ($semenaStats['seed_in_plant'] ?? 471.85);
        $seedInFormatted = number_format($seedInVal, 2, ',', ' ');

        $rangeDisplay = '';
        switch ($period) {
            case 'day_today':  $rangeDisplay = "Поточна доба ({$lastDateLabel} — прийнято за Листом погодження: {$seedInFormatted} т без очистки та сушки)"; break;
            case 'day_prev':   $rangeDisplay = "Минула виробнича доба ({$lastDateLabel} — прийнято {$seedInFormatted} т з плану 600 т, переробка МЕЗ: 0 т)"; break;
            case 'day_active': $rangeDisplay = "Остання доба активної переробки (06.10.2026 — прийнято {$seedInFormatted} т, перероблено 600,3 т)"; break;
            case 'shift':      $rangeDisplay = "Поточна зміна ({$shiftBounds})"; break;
            case 'week':       $rangeDisplay = "За тиждень ({$semenaStats['first_date']} — {$lastDateLabel}, 7 діб приймання / 3 доби переробки: прийнято {$seedInFormatted} т, перероблено 1 310,3 т)"; break;
            case 'month_aug':  $rangeDisplay = "Місяць Серпень 2026 (0 діб переробки з 31 доби • ППР заводу, прийнято {$seedInFormatted} т)"; break;
            case 'month_sep':  $rangeDisplay = "Місяць Вересень 2026 (працював 16 діб переробки: 15.09–30.09 з 30 діб • перероблено 8 823,7 т)"; break;
            case 'month_oct':
            case 'month':      $rangeDisplay = "Місяць Жовтень 2026 (працював 7 діб переробки: 01.10–07.10 • прийнято {$seedInFormatted} т, перероблено 3 646,4 т)"; break;
            case 'season':     $rangeDisplay = "Виробничий сезон 2026–2027 (01.08.2026 — 01.08.2027 • 23 доби переробки • прийнято {$seedInFormatted} т, перероблено 12 470,0 т)"; break;
            case 'day':
            default:
                $rangeDisplay = "Доба ({$lastDateLabel} — прийнято за Листом погодження: {$seedInFormatted} т з плану 600 т, без очистки та сушки)";
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
            'historical_analytics' => $this->getHistoricalAnalytics($period),
            'period_comparison'    => $this->getHistoricalAnalytics($period)['period_comparison'] ?? [],
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
                return "recorded_at >= '2026-08-01 08:00:00' AND recorded_at < '2027-08-01 08:00:00'";
            default:
                return "date(recorded_at) = '2026-10-11'";
        }
    }

    private function getPeriodLabel(string $period): string {
        $semenaStats = $this->getSemenaPeriodStats($period);
        $lastDate = $semenaStats['last_date'] ?? '12.10.2026';
        $seedIn = isset($semenaStats['seed_in_plant']) ? number_format($semenaStats['seed_in_plant'], 2, ',', ' ') : '471,85';

        switch ($period) {
            case 'day_today':  return "Сьогодні ({$lastDate} — {$seedIn} т за Листом погодження)";
            case 'day_prev':   return "Минула доба ({$lastDate} — {$seedIn} т прийомка, 0 т переробка)";
            case 'day_active': return 'Остання доба переробки (06.10.2026 — 600,3 т)';
            case 'shift':      return 'Поточна зміна (12 год)';
            case 'week':       return 'За тиждень (7 діб прийомки / 3 доби переробки)';
            case 'month_aug':  return 'Серпень 2026 (0 діб переробки з 31 • ППР)';
            case 'month_sep':  return 'Вересень 2026 (працював 16 діб: 15.09–30.09 з 30)';
            case 'month_oct':
            case 'month':      return 'Жовтень 2026 (працював 7 діб: 01.10–07.10)';
            case 'season':     return 'Сезон 2026–2027 (01.08.2026 — 01.08.2027 • 23 доби переробки)';
            case 'day':
            default:           return "За добу ({$lastDate} — {$seedIn} т прийомка без очистки та сушки)";
        }
    }

    /**
     * Блок А: Розрахунок матеріального балансу та втрат (суворо за вкладками Excel-звітів заводу)
     */
    private function calculateMaterialBalance(string $dateFilter, string $period): array {
        // Еталонні перевірені дані безпосередньо з вкладок «Октябрь26», «Сент.26», «СВОДНАЯ 2026-27», «Графік завезення» та «1..12 число»
        // План приймання на добу = 600,0 т
        $verifiedPeriods = [
            'day_today' => [
                'seed_in_plant' => 465.86,
                'seed_in_prod'  => 0.00,
                'cond_loss'     => 0.00,
                'cond_loss_pct' => 0.00,
                'oil_out'       => 0.00,
                'meal_out'      => 0.00,
                'husk_out'      => 0.00,
                'seed_plan'     => 600.0,
            ],
            'day_prev' => [
                // 11.10.2026: прийомка 465,86 т з плану 600,0 т (77,6%, 18 авто), переробка МЕЗ стояла (0,00 т)
                'seed_in_plant' => 465.86,
                'seed_in_prod'  => 0.00,
                'cond_loss'     => 0.00,
                'cond_loss_pct' => 0.00,
                'oil_out'       => 0.00,
                'meal_out'      => 0.00,
                'husk_out'      => 0.00,
                'seed_plan'     => 600.0,
            ],
            'day' => [
                // Остання завершена доба з Листа погодження: прийомка 465,86 т з плану 600,0 т (без очистки та сушки), переробка 0,00 т
                'seed_in_plant' => 465.86,
                'seed_in_prod'  => 0.00,
                'cond_loss'     => 0.00,
                'cond_loss_pct' => 0.00,
                'oil_out'       => 0.00,
                'meal_out'      => 0.00,
                'husk_out'      => 0.00,
                'seed_plan'     => 600.0,
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
                'seed_plan'     => 300.0,
            ],
            'week' => [
                // Тиждень 05.10 — 11.10 (7 діб * 600 т = 4 200 т план): сума за 05.10..11.10 з «Графік завезення» та «Октябрь26»
                'seed_in_plant' => 3767.78,
                'seed_in_prod'  => 1310.34,
                'cond_loss'     => 17.90,
                'cond_loss_pct' => 1.35,
                'oil_out'       => 598.98,
                'meal_out'      => 575.86,
                'husk_out'      => 194.32,
                'seed_plan'     => 4200.0,
            ],
            'month_aug' => [
                // Серпень 2026: ППР, прийомка 207,96 т (8 авто, 2 дні приймання), переробка 0 т
                'seed_in_plant' => 207.96,
                'seed_in_prod'  => 0.00,
                'cond_loss'     => 0.00,
                'cond_loss_pct' => 0.00,
                'oil_out'       => 0.00,
                'meal_out'      => 0.00,
                'husk_out'      => 5.91,
                'seed_plan'     => 600.0,
            ],
            'month_sep' => [
                // Вересень 2026 (з «Сент.26» та «СВОДНАЯ 2026-27»): працював 16 діб переробки (15.09-30.09)
                'seed_in_plant' => 19077.54,
                'seed_in_prod'  => 8823.66,
                'cond_loss'     => 94.74,
                'cond_loss_pct' => 1.06,
                'oil_out'       => 3811.66,
                'meal_out'      => 3683.21,
                'husk_out'      => 1261.23,
                'seed_plan'     => 18000.0,
            ],
            'month_oct' => [
                // Жовтень 2026 (01.10 — 11.10 з «Октябрь26»): працював 7 діб переробки (01.10-07.10), 11 діб приймання * 600 т = 6 600 т
                'seed_in_plant' => 6075.91,
                'seed_in_prod'  => 3646.35,
                'cond_loss'     => 53.60,
                'cond_loss_pct' => 1.45,
                'oil_out'       => 1646.15,
                'meal_out'      => 1521.68,
                'husk_out'      => 554.87,
                'seed_plan'     => 6600.0,
            ],
            'month' => [
                'seed_in_plant' => 6075.91,
                'seed_in_prod'  => 3646.35,
                'cond_loss'     => 53.60,
                'cond_loss_pct' => 1.45,
                'oil_out'       => 1646.15,
                'meal_out'      => 1521.68,
                'husk_out'      => 554.87,
                'seed_plan'     => 6600.0,
            ],
            'season' => [
                // Сезон 2026–2027 разом (01.08.2026 — 01.08.2027 з «СВОДНАЯ 2026-27»): працював 23 доби переробки
                'seed_in_plant' => 25361.41,
                'seed_in_prod'  => 12470.01,
                'cond_loss'     => 148.35,
                'cond_loss_pct' => 1.18,
                'oil_out'       => 5457.81,
                'meal_out'      => 5204.89,
                'husk_out'      => 1816.10,
                'seed_plan'     => 25200.0,
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

        // Зона приймання: ЗАВЖДИ беремо фізичне завезення (без очистки та сушки) з Листа погодження («Графік завезення (по днях)»)
        $semenaStats = $this->getSemenaPeriodStats($period);
        if (!empty($semenaStats) && isset($semenaStats['seed_in_plant']) && $semenaStats['seed_in_plant'] > 0) {
            $seedInPlant = (float)$semenaStats['seed_in_plant'];
            if (in_array($period, ['month_oct', 'month'], true) && !empty($semenaStats['days_count'])) {
                $seedPlan = (float)($semenaStats['days_count'] * 600.0);
            }
        }

        // Якщо в БД були імпортовані свіжіші записи виробничого звіту, враховуємо їх для поточного дня
        if (in_array($period, ['day', 'day_prev', 'day_today'], true)) {
            try {
                $stmt = $this->db->query("
                    SELECT seed_in_plant, seed_in_production, oil_out, meal_out, husk_out, conditioning_loss, conditioning_loss_pct, recorded_at
                    FROM material_balance_logs
                    ORDER BY id DESC LIMIT 1
                ");
                $latestRow = $stmt ? $stmt->fetch() : null;
                if ($latestRow && (float)($latestRow['seed_in_production'] ?? 0) > 0 && empty($semenaStats)) {
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
            'seed_in_plant'        => $seedInPlant,
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
     * Завантажити структурований технологічний облік пари з database/steam_data.json
     */
    private function getSteamData(): array {
        static $steamCache = null;
        if ($steamCache !== null) {
            return $steamCache;
        }
        $file = __DIR__ . '/../database/steam_data.json';
        if (file_exists($file)) {
            $json = file_get_contents($file);
            $steamCache = json_decode($json, true) ?: [];
        } else {
            $steamCache = [];
        }
        return $steamCache;
    }

    /**
     * Блок В: Енергоресурси (електроенергія, вода, каналізація, пара, природний газ — за звітом головного енергетика та журналами обліку)
     */
    private function calculateEnergyMetrics(string $dateFilter, string $period): array {
        $steamDb = $this->getSteamData();
        $steamDaily = $steamDb['daily_sep26'] ?? [];
        $steamMonthly = array_slice($steamDb['monthly_summary'] ?? [], 0, 12);

        // Помісячне зведення за 12 місяців (з «Розподіл енергоносіїв за структурними підрозділами» В. В. Фокшек / С. М. Ворона)
        // Примітка: Жовтень, Листопад та Грудень у вихідному файлі були скопійовані з сезону 2025 р. як база.
        // За вимогою користувача вони обнулені з поміткою «немає даних». Фактичні дані 2026 р. є лише по Вересень включно.
        $monthly12 = [
            ['month' => 'Січень 2026',   'short' => 'Січ', 'active_kwh' => 84026,  'reactive_kvarh' => 32708.4,  'gas_m3' => 72784.0, 'water_m3' => 1300, 'sewage_m3' => 758,  'daily_water_m3' => 43.6,  'press_kwh' => 18160,  'extr_kwh' => 14060,  'elev_kwh' => 39334,  'boiler_kwh' => 7583,  'specific_kwh' => 50.95, 'has_data' => true],
            ['month' => 'Лютий 2026',    'short' => 'Лют', 'active_kwh' => 432539, 'reactive_kvarh' => 124520.1, 'gas_m3' => 82248.0, 'water_m3' => 2901, 'sewage_m3' => 1709, 'daily_water_m3' => 93.6,  'press_kwh' => 131080, 'extr_kwh' => 209160, 'elev_kwh' => 60526,  'boiler_kwh' => 28550, 'specific_kwh' => 60.88, 'has_data' => true],
            ['month' => 'Березень 2026', 'short' => 'Бер', 'active_kwh' => 209627, 'reactive_kvarh' => 71189.7,  'gas_m3' => 49555.0, 'water_m3' => 1819, 'sewage_m3' => 1080, 'daily_water_m3' => 58.7,  'press_kwh' => 103480, 'extr_kwh' => 36440,  'elev_kwh' => 37792,  'boiler_kwh' => 27700, 'specific_kwh' => 73.14, 'has_data' => true],
            ['month' => 'Квітень 2026',  'short' => 'Кві', 'active_kwh' => 273083, 'reactive_kvarh' => 91759.8,  'gas_m3' => 1.0,     'water_m3' => 1954, 'sewage_m3' => 1100, 'daily_water_m3' => 63.0,  'press_kwh' => 134360, 'extr_kwh' => 56260,  'elev_kwh' => 63706,  'boiler_kwh' => 14700, 'specific_kwh' => 58.75, 'has_data' => true],
            ['month' => 'Травень 2026',  'short' => 'Тра', 'active_kwh' => 49518,  'reactive_kvarh' => 32949.0,  'gas_m3' => 0.0,     'water_m3' => 324,  'sewage_m3' => 200,  'daily_water_m3' => 10.5,  'press_kwh' => 14140,  'extr_kwh' => 4020,   'elev_kwh' => 23609,  'boiler_kwh' => 640,   'specific_kwh' => 0.00,  'has_data' => true],
            ['month' => 'Червень 2026',  'short' => 'Чер', 'active_kwh' => 255231, 'reactive_kvarh' => 94658.0,  'gas_m3' => 24707.0, 'water_m3' => 2013, 'sewage_m3' => 1153, 'daily_water_m3' => 67.1,  'press_kwh' => 136243, 'extr_kwh' => 68613,  'elev_kwh' => 28346,  'boiler_kwh' => 14920, 'specific_kwh' => 56.61, 'has_data' => true],
            ['month' => 'Липень 2026',   'short' => 'Лип', 'active_kwh' => 46923,  'reactive_kvarh' => 37957.6,  'gas_m3' => 3364.1,  'water_m3' => 464,  'sewage_m3' => 274,  'daily_water_m3' => 15.0,  'press_kwh' => 21850,  'extr_kwh' => 1500,   'elev_kwh' => 15140,  'boiler_kwh' => 1448,  'specific_kwh' => 0.00,  'has_data' => true],
            ['month' => 'Серпень 2026',  'short' => 'Сер', 'active_kwh' => 49379,  'reactive_kvarh' => 67011.0,  'gas_m3' => 96.0,    'water_m3' => 616,  'sewage_m3' => 400,  'daily_water_m3' => 19.9,  'press_kwh' => 14640,  'extr_kwh' => 1360,   'elev_kwh' => 14918,  'boiler_kwh' => 960,   'specific_kwh' => 0.00,  'has_data' => true],
            ['month' => 'Вересень 2026', 'short' => 'Вер', 'active_kwh' => 485070, 'reactive_kvarh' => 184589.7, 'gas_m3' => 990.9,   'water_m3' => 2508, 'sewage_m3' => 1409, 'daily_water_m3' => 83.6,  'press_kwh' => 270231, 'extr_kwh' => 106000, 'elev_kwh' => 75249,  'boiler_kwh' => 29940, 'specific_kwh' => 54.97, 'has_data' => true],
            ['month' => 'Жовтень 2026',  'short' => 'Жов', 'active_kwh' => null,   'reactive_kvarh' => null,     'gas_m3' => null,    'water_m3' => null, 'sewage_m3' => null, 'daily_water_m3' => null,  'press_kwh' => null,   'extr_kwh' => null,   'elev_kwh' => null,   'boiler_kwh' => null,  'specific_kwh' => null,  'has_data' => false, 'note' => 'Немає даних (звіт очікується)'],
            ['month' => 'Листопад 2026', 'short' => 'Лис', 'active_kwh' => null,   'reactive_kvarh' => null,     'gas_m3' => null,    'water_m3' => null, 'sewage_m3' => null, 'daily_water_m3' => null,  'press_kwh' => null,   'extr_kwh' => null,   'elev_kwh' => null,   'boiler_kwh' => null,  'specific_kwh' => null,  'has_data' => false, 'note' => 'Немає даних'],
            ['month' => 'Грудень 2026',  'short' => 'Гру', 'active_kwh' => null,   'reactive_kvarh' => null,     'gas_m3' => null,    'water_m3' => null, 'sewage_m3' => null, 'daily_water_m3' => null,  'press_kwh' => null,   'extr_kwh' => null,   'elev_kwh' => null,   'boiler_kwh' => null,  'specific_kwh' => null,  'has_data' => false, 'note' => 'Немає даних'],
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

        // Детальний технологічний облік води та стоків по вузлах (Вересень 2026 - факт)
        $waterMetersAccounting = [
            [
                'node'        => '1. Вхідний лічильник (Загальний ввід МЕЗ)',
                'responsible' => 'Усов А.В.',
                'sep_m3'      => 2059.0,
                'sep_daily'   => 137.3,
                'oct_m3'      => null,
                'oct_daily'   => null,
                'season_m3'   => 2059.0,
                'note'        => 'Вересень (факт з 15.09) • Жовтень: звіт очікується',
            ],
            [
                'node'        => '2. Водонасосна станція (Тех. вода на виробництво)',
                'responsible' => 'Усов А.В.',
                'sep_m3'      => 2001.0,
                'sep_daily'   => 133.4,
                'oct_m3'      => null,
                'oct_daily'   => null,
                'season_m3'   => 2001.0,
                'note'        => 'Подача води у виробничі цехи (Вересень 2026)',
            ],
            [
                'node'        => '3. Котельня на лушпинні (Загальна подача ХВО)',
                'responsible' => 'Усов А.В.',
                'sep_m3'      => 1023.0,
                'sep_daily'   => 68.2,
                'oct_m3'      => null,
                'oct_daily'   => null,
                'season_m3'   => 1023.0,
                'note'        => 'Пермеат (пара): 716 м³ (70,0%) • Скид у КНС: 307 м³ (30%)',
            ],
            [
                'node'        => '4. Водооборотка цеху екстракції (Підживлення градирень)',
                'responsible' => 'Хлібець В.',
                'sep_m3'      => 544.0,
                'sep_daily'   => 36.3,
                'oct_m3'      => null,
                'oct_daily'   => null,
                'season_m3'   => 544.0,
                'note'        => 'Випар на градирнях ГМВ: 544 м³ (100% у Вересні)',
            ],
            [
                'node'        => '5. Барометричний конденсатор (Екстракція)',
                'responsible' => 'Хлібець В.',
                'sep_m3'      => 137.0,
                'sep_daily'   => 9.1,
                'oct_m3'      => null,
                'oct_daily'   => null,
                'season_m3'   => 137.0,
                'note'        => 'Технологічні стоки барометрички (Вересень)',
            ],
            [
                'node'        => '6. СБК (Станція біологічної / побутової очистки)',
                'responsible' => 'Усов А.В.',
                'sep_m3'      => 161.0,
                'sep_daily'   => 10.7,
                'oct_m3'      => null,
                'oct_daily'   => null,
                'season_m3'   => 161.0,
                'note'        => 'Очистка господарсько-побутових стоків (Вересень)',
            ],
            [
                'node'        => '7. ВТЛ (Виробничо-технічна лабораторія)',
                'responsible' => 'Нікітіна А.',
                'sep_m3'      => 36.0,
                'sep_daily'   => 2.4,
                'oct_m3'      => null,
                'oct_daily'   => null,
                'season_m3'   => 36.0,
                'note'        => 'Лабораторні потреби та охолодження дистиляторів',
            ],
            [
                'node'        => '8. Ввід №1 (Загальнозаводська мережа)',
                'responsible' => 'Усов А.В.',
                'sep_m3'      => 93.0,
                'sep_daily'   => 6.2,
                'oct_m3'      => null,
                'oct_daily'   => null,
                'season_m3'   => 93.0,
                'note'        => 'Побутові та допоміжні приміщення вводу №1',
            ],
            [
                'node'        => '9. Госп.-питні потреби: АПК (Адмінпобуткорпус)',
                'responsible' => 'Усов А.В.',
                'sep_m3'      => 34.0,
                'sep_daily'   => 2.3,
                'oct_m3'      => null,
                'oct_daily'   => null,
                'season_m3'   => 34.0,
                'note'        => 'Душові, санвузли та побутові приміщення АПК',
            ],
            [
                'node'        => '10. Госп.-питні потреби: ОПЦ (Олійнопресовий цех)',
                'responsible' => 'Довгаленко С.',
                'sep_m3'      => 22.0,
                'sep_daily'   => 1.5,
                'oct_m3'      => null,
                'oct_daily'   => null,
                'season_m3'   => 22.0,
                'note'        => 'Побутові та санітарні потреби персоналу пресового цеху',
            ],
            [
                'node'        => '11. Госп.-питні потреби: Пожежна / мех. майстерня та Вагова',
                'responsible' => 'Усов А.В. / Судак Л.',
                'sep_m3'      => 9.0,
                'sep_daily'   => 0.6,
                'oct_m3'      => null,
                'oct_daily'   => null,
                'season_m3'   => 9.0,
                'note'        => 'Пож./мех. майстерня та Вагова (Вересень)',
            ],
            [
                'node'        => '12. КАНАЛІЗАЦІЯ РАЗОМ (Госп.-побутові + Пром. стоки)',
                'responsible' => 'Усов А.В.',
                'sep_m3'      => 764.3,
                'sep_daily'   => 51.0,
                'oct_m3'      => null,
                'oct_daily'   => null,
                'season_m3'   => 764.3,
                'note'        => 'Госп.-побутові: 182 м³ + Пром. стоки: 582,3 м³ (скид у КНС: 1 409 м³)',
            ],
        ];

        switch ($period) {
            case 'month_sep':
                // Вересень 2026: точні дані з журналу пари ТОВ «Агропроінвест 08» (1 980,50 т котлом, 1 844,04 т на виробництво)
                return [
                    'has_data'                   => true,
                    'current_power_kw'           => 673.7,
                    'total_kwh'                  => 485070,
                    'reactive_kvarh'             => 184590,
                    'current_water_m3_hour'      => 3.48,
                    'daily_water_m3'             => 83.6,
                    'total_water_m3'             => 2508.0,
                    'sewage_m3'                  => 1409.0,
                    'gas_m3'                     => 990.9,
                    'steam_pressure_bar'         => 11.2,
                    'specific_kwh_per_tonne'     => 54.97,
                    'specific_subtext'           => 'Прес: 60,93 + Екстр: 23,90 кВт·год/т',
                    'boiler_water_m3'            => 1023.0,
                    'extraction_water_m3'        => 544.0,
                    // Показники пари
                    'steam_boiler_tonnes'        => 1980.50,
                    'steam_production_tonnes'    => 1844.04,
                    'steam_extraction_tonnes'    => 1076.80,
                    'steam_prep_tonnes'          => 758.39,
                    'steam_elevator_tonnes'      => 5.84,
                    'steam_heating_tonnes'       => 3.01,
                    'steam_sn_tonnes'            => 136.46,
                    'steam_specific_kg_per_tonne'=> 208.9,
                    'steam_subtext'              => 'Виробництво: 1 844,0 т • СН: 136,5 т • Опалення: 3,0 т',
                    'steam_distrib_subtext'      => 'Екстракція: 1 076,8 т • Підготовка: 758,4 т • Елеватор: 5,8 т',
                    'steam_daily_table'          => $steamDaily,
                    'steam_monthly_history'      => $steamMonthly,
                    'period_note'                => 'Вересень 2026 (фактичні дані за звітний місяць)',
                    'departments'                => $departmentsSep,
                    'water_meters'               => $waterMetersAccounting,
                    'monthly_12'                 => $monthly12,
                    'timeline' => [
                        'labels'     => ['15.09', '16.09', '17.09', '18.09', '19.09', '20.09', '21.09', '22.09', '23.09', '24.09', '25.09', '26.09', '27.09', '28.09', '29.09', '30.09'],
                        'power'      => [31500, 31800, 29400, 28600, 28200, 28900, 24200, 23500, 23100, 25400, 25100, 25600, 25200, 23800, 27600, 24900],
                        'water'      => [215,   215,   190,   182,   180,   186,   137,   130,   129,   142,   140,   144,   141,   131,   160,   139],
                        'steam'      => [102.3, 118.0, 122.9, 124.5, 122.0, 123.6, 122.0, 125.2, 122.2, 106.0, 123.6, 122.5, 126.8, 132.5, 131.3, 130.1],
                        'power_name' => 'Електроенергія за добу (кВт·год)',
                        'water_name' => 'Вода за добу по лічильнику (м³)',
                        'steam_name' => 'Технологічна пара за добу (т)',
                        'power_unit' => 'кВт·год',
                        'water_unit' => 'м³/д',
                        'steam_unit' => 'т/д',
                    ]
                ];

            case 'month_aug':
                // Серпень 2026 (звіт В. В. Фокшек): ППР заводу
                return [
                    'has_data'                   => true,
                    'current_power_kw'           => 66.4,
                    'total_kwh'                  => 49379,
                    'reactive_kvarh'             => 67011,
                    'current_water_m3_hour'      => 0.83,
                    'daily_water_m3'             => 19.9,
                    'total_water_m3'             => 616.0,
                    'sewage_m3'                  => 400.0,
                    'gas_m3'                     => 96.0,
                    'steam_pressure_bar'         => 0.0,
                    'specific_kwh_per_tonne'     => 0.0,
                    'specific_subtext'           => 'ППР заводу (приймання на елеватор)',
                    'boiler_water_m3'            => 57.0,
                    'extraction_water_m3'        => 28.0,
                    // Показники пари
                    'steam_boiler_tonnes'        => 0.0,
                    'steam_production_tonnes'    => 0.0,
                    'steam_extraction_tonnes'    => 0.0,
                    'steam_prep_tonnes'          => 0.0,
                    'steam_elevator_tonnes'      => 0.0,
                    'steam_heating_tonnes'       => 0.0,
                    'steam_sn_tonnes'            => 0.0,
                    'steam_specific_kg_per_tonne'=> 0.0,
                    'steam_subtext'              => 'ППР заводу • Котельня на ремонті',
                    'steam_distrib_subtext'      => 'Виробництво пари: 0,0 т',
                    'steam_daily_table'          => [],
                    'steam_monthly_history'      => $steamMonthly,
                    'period_note'                => 'Серпень 2026 (ППР заводу)',
                    'departments'                => $departmentsAug,
                    'water_meters'               => $waterMetersAccounting,
                    'monthly_12'                 => $monthly12,
                    'timeline' => [
                        'labels'     => ['01.08', '05.08', '10.08', '15.08', '20.08', '25.08', '31.08'],
                        'power'      => [1420, 1510, 1540, 1580, 1620, 1680, 1820],
                        'water'      => [18,   19,   19,   20,   20,   21,   23],
                        'steam'      => [0,    0,    0,    0,    0,    0,    0],
                        'power_name' => 'Електроенергія за добу (кВт·год)',
                        'water_name' => 'Вода за добу по лічильнику (м³)',
                        'steam_name' => 'Технологічна пара за добу (т)',
                        'power_unit' => 'кВт·год',
                        'water_unit' => 'м³/д',
                        'steam_unit' => 'т/д',
                    ]
                ];

            // За вимогою: сьогодні, тиждень, місяць (жовтень) не виводити; виводити лише коли обереш Вересень, а для Жовтня писати "немає даних"
            default:
                return [
                    'has_data'                   => false,
                    'current_power_kw'           => 0.0,
                    'total_kwh'                  => 0,
                    'reactive_kvarh'             => 0,
                    'current_water_m3_hour'      => 0.0,
                    'daily_water_m3'             => 0.0,
                    'total_water_m3'             => 0.0,
                    'sewage_m3'                  => 0.0,
                    'gas_m3'                     => 0.0,
                    'steam_pressure_bar'         => 0.0,
                    'specific_kwh_per_tonne'     => 0.0,
                    'specific_subtext'           => 'Жовтень 2026: звіт енергетика очікується',
                    'boiler_water_m3'            => 0.0,
                    'extraction_water_m3'        => 0.0,
                    'steam_boiler_tonnes'        => 0.0,
                    'steam_production_tonnes'    => 0.0,
                    'steam_extraction_tonnes'    => 0.0,
                    'steam_prep_tonnes'          => 0.0,
                    'steam_elevator_tonnes'      => 0.0,
                    'steam_heating_tonnes'       => 0.0,
                    'steam_sn_tonnes'            => 0.0,
                    'steam_specific_kg_per_tonne'=> 0.0,
                    'steam_subtext'              => 'Жовтень 2026: дані відсутні (звіт котельні очікується)',
                    'steam_distrib_subtext'      => 'Оберіть «Вересень» для перегляду фактичних показників',
                    'steam_daily_table'          => [],
                    'steam_monthly_history'      => $steamMonthly,
                    'period_note'                => 'Жовтень 2026: немає даних (звіт очікується)',
                    'no_data_reason'             => 'Жовтень: немає даних',
                    'departments'                => [],
                    'water_meters'               => $waterMetersAccounting,
                    'monthly_12'                 => $monthly12,
                    'timeline' => [
                        'labels'     => [],
                        'power'      => [],
                        'water'      => [],
                        'steam'      => [],
                        'power_name' => 'Електроенергія за добу (кВт·год)',
                        'water_name' => 'Вода за добу по лічильнику (м³)',
                        'steam_name' => 'Технологічна пара за добу (т)',
                        'power_unit' => 'кВт·год',
                        'water_unit' => 'м³/д',
                        'steam_unit' => 'т/д',
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
     * Завантажити актуальні складські залишки з database/svod_stocks.json та таблиці warehouse_stocks
     */
    private function loadLiveSvodStocks(): array {
        $defaults = [
            'seed_stock_tonnes'            => 13115.47,
            'seed_stock_accounting_tonnes' => 13794.46,
            'seed_received_tonnes'         => 465.77,
            'oil_stock_tonnes'             => 3332.32,
            'oil_shipped_tonnes'           => 26.44,
            'meal_stock_tonnes'            => 773.73,
            'meal_shipped_tonnes'          => 282.40,
            'husk_stock_tonnes'            => 87.64,
            'husk_shipped_tonnes'          => 29.50,
            'month_oil_shipped_tonnes'     => 1050.54,
            'month_meal_shipped_tonnes'    => 2461.42,
            'month_husk_shipped_tonnes'    => 507.58,
            'sheet_title'                  => '12 число',
            'updated_at'                   => '12.10.2026',
        ];

        $svodJsonPath = __DIR__ . '/../database/svod_stocks.json';
        if (file_exists($svodJsonPath)) {
            $decoded = json_decode((string)file_get_contents($svodJsonPath), true);
            if (is_array($decoded)) {
                $defaults = array_merge($defaults, $decoded);
            }
        }

        // Також перевіряємо останній запис у БД warehouse_stocks (якщо імпортовано через БД)
        try {
            $stmt = $this->db->query("
                SELECT seed_stock_tonnes, seed_stock_accounting_tonnes, oil_stock_tonnes, meal_stock_tonnes, husk_stock_tonnes,
                       oil_shipped_tonnes, meal_shipped_tonnes, husk_shipped_tonnes, recorded_at
                FROM warehouse_stocks
                ORDER BY id DESC LIMIT 1
            ");
            $row = $stmt ? $stmt->fetch() : null;
            // Якщо в БД не застарілий дефолт 13326.41 або немає JSON-файлу
            if ($row && (!file_exists($svodJsonPath) || abs((float)$row['seed_stock_tonnes'] - 13326.41) > 0.05)) {
                $defaults['seed_stock_tonnes']            = round((float)$row['seed_stock_tonnes'], 2);
                $defaults['seed_stock_accounting_tonnes'] = round((float)$row['seed_stock_accounting_tonnes'], 2);
                $defaults['oil_stock_tonnes']             = round((float)$row['oil_stock_tonnes'], 2);
                $defaults['meal_stock_tonnes']            = round((float)$row['meal_stock_tonnes'], 2);
                $defaults['husk_stock_tonnes']            = round((float)$row['husk_stock_tonnes'], 2);
                if ((float)$row['oil_shipped_tonnes'] > 0 || (float)$row['meal_shipped_tonnes'] > 0 || (float)$row['husk_shipped_tonnes'] > 0) {
                    $defaults['oil_shipped_tonnes']  = round((float)$row['oil_shipped_tonnes'], 2);
                    $defaults['meal_shipped_tonnes'] = round((float)$row['meal_shipped_tonnes'], 2);
                    $defaults['husk_shipped_tonnes'] = round((float)$row['husk_shipped_tonnes'], 2);
                }
            }
        } catch (Throwable $e) {}

        return $defaults;
    }

    /**
     * Складські залишки готової продукції та насіння (звіти «ежедн.отчёт 1» та «Ежедн.отчет2» Агропроінвест 08)
     */
    public function getWarehouseStocks(string $period = 'day'): array {
        $live = $this->loadLiveSvodStocks();
        $seedStock     = round((float)$live['seed_stock_tonnes'], 2);
        $seedAcct      = round((float)$live['seed_stock_accounting_tonnes'], 2);
        $oilStock      = round((float)$live['oil_stock_tonnes'], 2);
        $mealStock     = round((float)$live['meal_stock_tonnes'], 2);
        $huskStock     = round((float)$live['husk_stock_tonnes'], 2);
        $daysReserve   = round($seedStock / 600.0, 1);
        $sheetTitle    = $live['sheet_title'] ?? '12 число';
        $monthOilShip  = round((float)($live['month_oil_shipped_tonnes'] ?? 1050.54), 2);
        $monthMealShip = round((float)($live['month_meal_shipped_tonnes'] ?? 2461.42), 2);
        $monthHuskShip = round((float)($live['month_husk_shipped_tonnes'] ?? 507.58), 2);

        switch ($period) {
            case 'week':
                return [
                    'seed_stock_tonnes'            => $seedStock,
                    'seed_stock_accounting_tonnes' => $seedAcct,
                    'oil_stock_tonnes'             => $oilStock,
                    'meal_stock_tonnes'            => $mealStock,
                    'husk_stock_tonnes'            => $huskStock,
                    'husk_boiler_spent_tonnes'     => 102.05,
                    'days_seed_reserve'            => $daysReserve,
                    'oil_shipped_tonnes'           => 728.80,
                    'meal_shipped_tonnes'          => 1626.30,
                    'husk_shipped_tonnes'          => 321.76,
                    'report_date'                  => "Ежедн.отчет2 ({$sheetTitle}, тиждень)",
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
                    'seed_stock_tonnes'            => $seedStock,
                    'seed_stock_accounting_tonnes' => $seedAcct,
                    'oil_stock_tonnes'             => $oilStock,
                    'meal_stock_tonnes'            => $mealStock,
                    'husk_stock_tonnes'            => $huskStock,
                    'husk_boiler_spent_tonnes'     => 294.17,
                    'days_seed_reserve'            => $daysReserve,
                    'oil_shipped_tonnes'           => $monthOilShip,
                    'meal_shipped_tonnes'          => $monthMealShip,
                    'husk_shipped_tonnes'          => $monthHuskShip,
                    'report_date'                  => "Ежедн.отчет2 ({$sheetTitle}, СВОД_ОКТЯБРЬ 26)",
                ];
            case 'season':
                return [
                    'seed_stock_tonnes'            => $seedStock,
                    'seed_stock_accounting_tonnes' => $seedAcct,
                    'oil_stock_tonnes'             => $oilStock,
                    'meal_stock_tonnes'            => $mealStock,
                    'husk_stock_tonnes'            => $huskStock,
                    'husk_boiler_spent_tonnes'     => 710.19,
                    'days_seed_reserve'            => $daysReserve,
                    'oil_shipped_tonnes'           => round(76.64 + 1090.30 + $monthOilShip, 2),
                    'meal_shipped_tonnes'          => round(1390.54 + 2332.34 + $monthMealShip, 2),
                    'husk_shipped_tonnes'          => round(78.60 + 490.66 + $monthHuskShip, 2),
                    'report_date'                  => "Ежедн.отчет2 ({$sheetTitle})",
                ];
            case 'day_today':
            case 'day_prev':
            case 'day':
            default:
                // Фактичні складські залишки з файлу «Ежедн.отчет2» (наприклад, на «12 число»: насіння 13 115,47 т, бух.облік 13 794,46 т)
                return [
                    'seed_stock_tonnes'            => $seedStock,
                    'seed_stock_accounting_tonnes' => $seedAcct,
                    'oil_stock_tonnes'             => $oilStock,
                    'meal_stock_tonnes'            => $mealStock,
                    'husk_stock_tonnes'            => $huskStock,
                    'husk_boiler_spent_tonnes'     => 0.00,
                    'days_seed_reserve'            => $daysReserve,
                    'oil_shipped_tonnes'           => round((float)$live['oil_shipped_tonnes'], 2),
                    'meal_shipped_tonnes'          => round((float)$live['meal_shipped_tonnes'], 2),
                    'husk_shipped_tonnes'          => round((float)$live['husk_shipped_tonnes'], 2),
                    'report_date'                  => "Ежедн.отчет2 ({$sheetTitle})",
                ];
        }
    }

    /**
     * Повна ретроспективна аналітика та помісячне порівняння (Серпень, Вересень, Жовтень 2026 та Сезон 2025–2026)
     */
    public function getHistoricalAnalytics(string $period = 'day'): array {
        $live = $this->loadLiveSvodStocks();
        $octStats = $this->getSemenaPeriodStats('month_oct');
        $seasonStats = $this->getSemenaPeriodStats('season');

        $octSeedIn = !empty($octStats['seed_in_plant']) ? (float)$octStats['seed_in_plant'] : 6075.91;
        $octTrucks = !empty($octStats['trucks_count']) ? (int)$octStats['trucks_count'] : 231;
        $seasonSeedIn = !empty($seasonStats['seed_in_plant']) ? (float)$seasonStats['seed_in_plant'] : 25361.41;
        $seasonTrucks = !empty($seasonStats['trucks_count']) ? (int)$seasonStats['trucks_count'] : 977;

        $seedStock = round((float)$live['seed_stock_tonnes'], 2);
        $oilStock  = round((float)$live['oil_stock_tonnes'], 2);
        $mealStock = round((float)$live['meal_stock_tonnes'], 2);
        $huskStock = round((float)$live['husk_stock_tonnes'], 2);
        $octOilShip = round((float)($live['month_oil_shipped_tonnes'] ?? 1050.54), 2);
        $octMealShip = round((float)($live['month_meal_shipped_tonnes'] ?? 2461.42), 2);
        $octHuskShip = round((float)($live['month_husk_shipped_tonnes'] ?? 507.58), 2);

        $periodComparison = $this->getPeriodComparison($period);

        return [
            'period_comparison' => $periodComparison,
            'months' => [
                'aug' => [
                    'id'                 => 'month_aug',
                    'name'               => 'Серпень 2026',
                    'short_name'         => 'Серпень',
                    'days_count'         => 31,
                    'working_days'       => 0,
                    'working_days_label' => '0 діб переробки (з 31 доби • ППР)',
                    'status_note'        => 'Завод у ППР • Переробка: 0 діб з 31',
                    'seed_in'            => 207.96,
                    'seed_processed'     => 0.00,
                    'oil_out'            => 0.00,
                    'oil_yield_pct'      => 0.00,
                    'meal_out'           => 0.00,
                    'meal_yield_pct'     => 0.00,
                    'husk_out'           => 5.91,
                    'oil_shipped'        => 76.64,
                    'meal_shipped'       => 1390.54,
                    'husk_shipped'       => 78.60,
                    'trucks_count'       => 8,
                    'seed_stock'         => 883.40,
                    'oil_stock'          => 15.35,
                    'meal_stock'         => 362.60,
                    'husk_stock'         => 0.00,
                    'boiler_husk'        => 6.85,
                ],
                'sep' => [
                    'id'                 => 'month_sep',
                    'name'               => 'Вересень 2026',
                    'short_name'         => 'Вересень',
                    'days_count'         => 30,
                    'working_days'       => 16,
                    'working_days_label' => 'Працював: 16 діб (15.09–30.09) з 30',
                    'status_note'        => 'Працював 16 діб переробки (15.09–30.09) з 30',
                    'seed_in'            => 19077.54,
                    'seed_processed'     => 8823.66,
                    'oil_out'            => 3811.66,
                    'oil_yield_pct'      => 43.20,
                    'meal_out'           => 3683.21,
                    'meal_yield_pct'     => 41.74,
                    'husk_out'           => 1261.23,
                    'oil_shipped'        => 1090.30,
                    'meal_shipped'       => 2332.34,
                    'husk_shipped'       => 490.66,
                    'trucks_count'       => 738,
                    'seed_stock'         => 10966.28,
                    'oil_stock'          => 2736.71,
                    'meal_stock'         => 1713.47,
                    'husk_stock'         => 334.22,
                    'boiler_husk'        => 409.17,
                ],
                'oct' => [
                    'id'                 => 'month_oct',
                    'name'               => 'Жовтень 2026 (1-12)',
                    'short_name'         => 'Жовтень',
                    'days_count'         => 12,
                    'working_days'       => 7,
                    'working_days_label' => 'Працював: 7 діб (01.10–07.10) з 12',
                    'status_note'        => 'Працював 7 діб переробки (01.10–07.10) з 12',
                    'seed_in'            => $octSeedIn,
                    'seed_processed'     => 3646.35,
                    'oil_out'            => 1646.15,
                    'oil_yield_pct'      => 45.15,
                    'meal_out'           => 1521.68,
                    'meal_yield_pct'     => 41.73,
                    'husk_out'           => 554.87,
                    'oil_shipped'        => $octOilShip,
                    'meal_shipped'       => $octMealShip,
                    'husk_shipped'       => $octHuskShip,
                    'trucks_count'       => $octTrucks,
                    'seed_stock'         => $seedStock,
                    'oil_stock'          => $oilStock,
                    'meal_stock'         => $mealStock,
                    'husk_stock'         => $huskStock,
                    'boiler_husk'        => 294.17,
                ]
            ],
            'season_total' => [
                'name'               => 'Разом за сезон 2026–2027 рр. (01.08.2026 — 01.08.2027)',
                'period'             => '01.08.2026 — 01.08.2027 (поточний стан: 23 доби переробки)',
                'working_days'       => 23,
                'working_days_label' => 'Працював: 23 доби переробки (з 74 діб сезону)',
                'seed_in'            => $seasonSeedIn,
                'seed_processed'     => 12470.01,
                'oil_out'            => 5457.81,
                'oil_yield_pct'      => 43.77,
                'meal_out'           => 5204.89,
                'meal_yield_pct'     => 41.74,
                'husk_out'           => 1822.01,
                'oil_shipped'        => round(76.64 + 1090.30 + $octOilShip, 2),
                'meal_shipped'       => round(1390.54 + 2332.34 + $octMealShip, 2),
                'husk_shipped'       => round(78.60 + 490.66 + $octHuskShip, 2),
                'trucks_count'       => $seasonTrucks,
                'seed_stock'         => $seedStock,
                'oil_stock'          => $oilStock,
                'meal_stock'         => $mealStock,
                'husk_stock'         => $huskStock,
                'boiler_husk'        => 710.19,
            ],
            'chart_comparison' => [
                'categories'     => ['Серпень 2026', 'Вересень 2026', 'Жовтень 2026 (1-12)'],
                'seed_in'        => [207.96, 19077.54, $octSeedIn],
                'seed_processed' => [0.00, 8823.66, 3646.35],
                'oil_out'        => [0.00, 3811.66, 1646.15],
                'meal_out'       => [0.00, 3683.21, 1521.68],
                'husk_out'       => [5.91, 1261.23, 554.87],
                'trucks'         => [8, 738, $octTrucks],
                'oil_stocks'     => [15.35, 2736.71, $oilStock],
                'meal_stocks'    => [362.60, 1713.47, $mealStock],
                'seed_stocks'    => [883.40, 10966.28, $seedStock],
            ],
            'season_2025_comparison' => [
                'name' => 'Сезон 2025–2026 рр. (01.08.2025 — 01.08.2026 • Офіційний звіт заводу)',
                'period_label' => '01.08.2025 — 01.08.2026 (повний виробничий цикл попереднього сезону)',
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
                'full_season_total' => [
                    'label'           => 'РАЗОМ за сезон 2025–2026 рр. (01.08.2025 — 01.08.2026)',
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
                'categories'     => ['Вересень 2025', 'Жовтень 2025', 'Вер-Жов 2025', 'Весь сезон 2025-26'],
                'seed_in'        => [4470.82, 16858.22, 21329.04, 62403.27],
                'seed_processed' => [4435.44, 16681.68, 21117.12, 61892.86],
                'oil_out'        => [1865.62, 7414.22, 9279.84, 27416.87],
                'oil_yield_pct'  => [42.06, 44.45, 43.94, 44.30],
                'meal_out'       => [1869.21, 6966.59, 8835.80, 25790.44],
                'meal_yield_pct' => [42.14, 41.76, 41.84, 41.67],
                'husk_out'       => [620.13, 2058.44, 2678.57, 7971.81],
            ],
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
     * Динамічне зіставлення обраного періоду 2026 (доба, тиждень, місяць, сезон)
     * з аналогічним періодом сезону 2025 та еталоном повного року 2025–2026
     */
    public function getPeriodComparison(string $period = 'day'): array {
        $p = strtolower(trim($period));
        if (in_array($p, ['day_active', 'active_day', 'work_day'], true)) {
            $norm = 'day_active';
        } elseif (in_array($p, ['day', 'day_today', 'day_prev', 'shift'], true)) {
            $norm = 'day';
        } elseif ($p === 'week') {
            $norm = 'week';
        } elseif ($p === 'month_sep') {
            $norm = 'month_sep';
        } elseif ($p === 'month_aug') {
            $norm = 'month_aug';
        } elseif ($p === 'season') {
            $norm = 'season';
        } else {
            // 'month', 'month_oct' etc.
            $norm = 'month_oct';
        }

        $live = $this->loadLiveSvodStocks();
        $seedStock = round((float)($live['seed_stock_tonnes'] ?? 13115.47), 2);

        // Дані з Листа погодження поточної вибірки
        $stats = $this->getSemenaPeriodStats($norm);
        $labOil = !empty($stats['oil_content_pct']) ? (float)$stats['oil_content_pct'] : 48.00;

        // Перевіряємо чи є фактична переробка сьогодні в material_balance_logs
        $todaySeedInProd = 0.0;
        $todayOilOut = 0.0;
        $todayMealOut = 0.0;
        $todayHuskOut = 0.0;
        try {
            $stmt = $this->db->query("
                SELECT seed_in_production, oil_out, meal_out, husk_out
                FROM material_balance_logs
                ORDER BY id DESC LIMIT 1
            ");
            $latestMb = $stmt ? $stmt->fetch() : null;
            if ($latestMb && (float)($latestMb['seed_in_production'] ?? 0) > 0) {
                $todaySeedInProd = (float)$latestMb['seed_in_production'];
                $todayOilOut     = (float)$latestMb['oil_out'];
                $todayMealOut    = (float)$latestMb['meal_out'];
                $todayHuskOut    = (float)$latestMb['husk_out'];
            }
        } catch (Throwable $e) {}

        if ($norm === 'day') {
            $seedIn = !empty($stats['seed_in_plant']) ? (float)$stats['seed_in_plant'] : 471.85;

            if ($todaySeedInProd > 0) {
                // Якщо сьогодні була фактична переробка
                $oilPct = round(($todayOilOut / $todaySeedInProd) * 100, 2);
                $mealPct = round(($todayMealOut / $todaySeedInProd) * 100, 2);
                $sumPct = round($oilPct + $mealPct, 2);
                $oilDev = round($oilPct - 44.45, 2);
                $mealDev = round($mealPct - 41.76, 2);
                $sumDev = round($sumPct - 86.21, 2);
                $seedDev = round($todaySeedInProd - 538.1, 1);
                $seedDevPct = round(($seedDev / 538.1) * 100, 1);

                return [
                    'period'       => 'day',
                    'badge_label'  => 'Порівняння доби: 2026 vs 2025',
                    'kpis' => [
                        'seed_processed' => [
                            'title'        => '⚙️ Переробка насіння',
                            'badge'        => 'Доба 2026 (план 600 т)',
                            'number'       => number_format($todaySeedInProd, 1, ',', ' '),
                            'unit'         => 'т (доба 2026)',
                            'subtext'      => '2025 аналог: <strong>538,1 т/добу</strong> &bull; Відхилення: <strong>' . ($seedDev >= 0 ? '+' : '') . number_format($seedDev, 1, ',', ' ') . ' т (' . ($seedDevPct >= 0 ? '+' : '') . $seedDevPct . '%)</strong>',
                            'progress_pct' => min(100.0, round(($todaySeedInProd / 600.0) * 100, 1)),
                            'color'        => '#10b981',
                        ],
                        'oil_yield' => [
                            'title'        => '💧 Вихід Олії',
                            'badge'        => ($oilDev >= 0 ? '+' : '') . number_format($oilDev, 2, ',', '') . '% до 2025',
                            'number'       => number_format($oilPct, 2, ',', '') . '%',
                            'unit'         => 'доба 2026 (' . number_format($todayOilOut, 1, ',', ' ') . ' т)',
                            'subtext'      => '2025 аналог: <strong>44,45% (239,2 т)</strong> &bull; Відхилення: <strong>' . ($oilDev >= 0 ? '+' : '') . number_format($oilDev, 2, ',', '') . '%</strong>',
                            'progress_pct' => $oilPct,
                            'color'        => '#f59e0b',
                        ],
                        'meal_yield' => [
                            'title'        => '🌾 Вихід Шроту',
                            'badge'        => ($mealDev >= 0 ? '+' : '') . number_format($mealDev, 2, ',', '') . '% до 2025',
                            'number'       => number_format($mealPct, 2, ',', '') . '%',
                            'unit'         => 'доба 2026 (' . number_format($todayMealOut, 1, ',', ' ') . ' т)',
                            'subtext'      => '2025 аналог: <strong>41,76% (224,7 т)</strong> &bull; Відхилення: <strong>' . ($mealDev >= 0 ? '+' : '') . number_format($mealDev, 2, ',', '') . '%</strong>',
                            'progress_pct' => $mealPct,
                            'color'        => '#2563eb',
                        ],
                        'oil_meal_sum' => [
                            'title'        => '⚖️ Олія + Шрот',
                            'badge'        => ($sumDev >= 0 ? '+' : '') . number_format($sumDev, 2, ',', '') . '% до 2025',
                            'number'       => number_format($sumPct, 2, ',', '') . '%',
                            'unit'         => 'доба 2026 (' . number_format($todayOilOut + $todayMealOut, 1, ',', ' ') . ' т)',
                            'subtext'      => '2025 аналог: <strong>86,21%</strong> &bull; Відхилення: <strong>' . ($sumDev >= 0 ? '+' : '') . number_format($sumDev, 2, ',', '') . '%</strong>',
                            'progress_pct' => $sumPct,
                            'color'        => '#8b5cf6',
                        ],
                    ],
                    'headers' => [
                        'col_indicator'   => 'Показник виробництва',
                        'col_2026'        => 'Доба 2026 (сьогодні)',
                        'col_2025_analog' => 'Аналогічна доба 2025 (Жовтень)',
                        'col_2025_year'   => 'Сезон 2025–2026 (61,9 тис. т)',
                        'col_trend'       => 'Динаміка',
                    ],
                    'rows' => [
                        [
                            'name'        => 'Завезено насіння (авто/елеватор)',
                            'val_2026'    => number_format($seedIn, 2, ',', ' ') . ' т',
                            'val_2025_an' => '543,8 т/добу',
                            'val_2025_yr' => '62 403,3 т',
                            'trend_text'  => 'Прийомка доби',
                            'trend_class' => 'trend-good',
                        ],
                        [
                            'name'        => 'Перероблено соняшнику (очищене)',
                            'val_2026'    => number_format($todaySeedInProd, 1, ',', ' ') . ' т',
                            'val_2025_an' => '538,1 т/добу',
                            'val_2025_yr' => '61 892,9 т',
                            'trend_text'  => ($seedDev >= 0 ? '+' : '') . $seedDevPct . '%',
                            'trend_class' => ($seedDev >= 0) ? 'trend-good' : 'trend-neutral',
                        ],
                        [
                            'name'        => 'Середня олійність сировини (вхід у цех)',
                            'val_2026'    => number_format($labOil, 2, ',', '') . '% (лаб. якість)',
                            'val_2025_an' => '45,29%',
                            'val_2025_yr' => '45,13%',
                            'trend_text'  => '+' . number_format($labOil - 45.29, 2, ',', '') . '% якість',
                            'trend_class' => 'trend-good',
                        ],
                        [
                            'name'        => 'Вихід олії (т / %)',
                            'val_2026'    => number_format($todayOilOut, 1, ',', ' ') . ' т (' . number_format($oilPct, 2, ',', '') . '%)',
                            'val_2025_an' => '239,2 т (44,45%)',
                            'val_2025_yr' => '27 416,9 т (44,30%)',
                            'trend_text'  => ($oilDev >= 0 ? '+' : '') . number_format($oilDev, 2, ',', '') . '% до 2025',
                            'trend_class' => 'trend-good',
                        ],
                        [
                            'name'        => 'Вихід шроту (т / %)',
                            'val_2026'    => number_format($todayMealOut, 1, ',', ' ') . ' т (' . number_format($mealPct, 2, ',', '') . '%)',
                            'val_2025_an' => '224,7 т (41,76%)',
                            'val_2025_yr' => '25 790,4 т (41,67%)',
                            'trend_text'  => number_format($mealDev, 2, ',', '') . '% в нормі',
                            'trend_class' => 'trend-good',
                        ],
                        [
                            'name'        => 'Сума Олія + Шрот (%)',
                            'val_2026'    => number_format($sumPct, 2, ',', '') . '%',
                            'val_2025_an' => '86,21%',
                            'val_2025_yr' => '85,97%',
                            'trend_text'  => ($sumDev >= 0 ? '+' : '') . number_format($sumDev, 2, ',', '') . '%',
                            'trend_class' => 'trend-good',
                        ],
                        [
                            'name'        => 'Залишок насіння на складі заводу',
                            'val_2026'    => number_format($seedStock, 1, ',', ' ') . ' т',
                            'val_2025_an' => '176,5 т',
                            'val_2025_yr' => '510,4 т',
                            'trend_text'  => '21.9 діб автономії',
                            'trend_class' => 'trend-good',
                        ],
                    ]
                ];
            } else {
                // СЬОГОДНІ НЕМАЄ ПЕРЕРОБКИ (ПРОСТІЙ ЗАВОДУ / 0 Т)
                return [
                    'period'       => 'day',
                    'badge_label'  => 'Порівняння доби: 2026 (сьогодні немає переробки) vs 2025',
                    'kpis' => [
                        'seed_processed' => [
                            'title'        => '⚙️ Переробка насіння',
                            'badge'        => 'Сьогодні немає переробки',
                            'number'       => '0,0',
                            'unit'         => 'т (сьогодні немає переробки)',
                            'subtext'      => '2025 аналог: <strong>538,1 т/добу</strong> &bull; Відхилення: <strong>-538,1 т (простій)</strong> &bull; На складі: <strong>' . number_format($seedStock, 1, ',', ' ') . ' т</strong>',
                            'progress_pct' => 0.0,
                            'color'        => '#94a3b8',
                        ],
                        'oil_yield' => [
                            'title'        => '💧 Вихід Олії',
                            'badge'        => 'Немає переробки',
                            'number'       => '0,00%',
                            'unit'         => 'доба 2026 (0,0 т)',
                            'subtext'      => '2025 аналог: <strong>44,45% (239,2 т)</strong> &bull; Відхилення: <strong>виробництво зупинено</strong>',
                            'progress_pct' => 0.0,
                            'color'        => '#94a3b8',
                        ],
                        'meal_yield' => [
                            'title'        => '🌾 Вихід Шроту',
                            'badge'        => 'Немає переробки',
                            'number'       => '0,00%',
                            'unit'         => 'доба 2026 (0,0 т)',
                            'subtext'      => '2025 аналог: <strong>41,76% (224,7 т)</strong> &bull; Відхилення: <strong>виробництво зупинено</strong>',
                            'progress_pct' => 0.0,
                            'color'        => '#94a3b8',
                        ],
                        'oil_meal_sum' => [
                            'title'        => '⚖️ Олія + Шрот',
                            'badge'        => 'Немає переробки',
                            'number'       => '0,00%',
                            'unit'         => 'доба 2026 (0,0 т)',
                            'subtext'      => '2025 аналог: <strong>86,21%</strong> &bull; Відхилення: <strong>виробництво зупинено</strong>',
                            'progress_pct' => 0.0,
                            'color'        => '#94a3b8',
                        ],
                    ],
                    'headers' => [
                        'col_indicator'   => 'Показник виробництва',
                        'col_2026'        => 'Доба 2026 (сьогодні)',
                        'col_2025_analog' => 'Аналогічна доба 2025 (Жовтень)',
                        'col_2025_year'   => 'Сезон 2025–2026 (61,9 тис. т)',
                        'col_trend'       => 'Динаміка',
                    ],
                    'rows' => [
                        [
                            'name'        => 'Завезено насіння (авто/елеватор)',
                            'val_2026'    => number_format($seedIn, 2, ',', ' ') . ' т',
                            'val_2025_an' => '543,8 т/добу',
                            'val_2025_yr' => '62 403,3 т',
                            'trend_text'  => 'Прийомка йде',
                            'trend_class' => 'trend-good',
                        ],
                        [
                            'name'        => 'Перероблено соняшнику (очищене)',
                            'val_2026'    => '0,0 т (сьогодні немає переробки)',
                            'val_2025_an' => '538,1 т/добу',
                            'val_2025_yr' => '61 892,9 т',
                            'trend_text'  => 'Завод зупинено',
                            'trend_class' => 'trend-bad',
                        ],
                        [
                            'name'        => 'Середня олійність сировини (вхід у цех)',
                            'val_2026'    => number_format($labOil, 2, ',', '') . '% (лаб. якість)',
                            'val_2025_an' => '45,29%',
                            'val_2025_yr' => '45,13%',
                            'trend_text'  => '+' . number_format($labOil - 45.29, 2, ',', '') . '% якість',
                            'trend_class' => 'trend-good',
                        ],
                        [
                            'name'        => 'Вихід олії (т / %)',
                            'val_2026'    => '0,0 т (0,00%)',
                            'val_2025_an' => '239,2 т (44,45%)',
                            'val_2025_yr' => '27 416,9 т (44,30%)',
                            'trend_text'  => 'Немає переробки',
                            'trend_class' => 'trend-neutral',
                        ],
                        [
                            'name'        => 'Вихід шроту (т / %)',
                            'val_2026'    => '0,0 т (0,00%)',
                            'val_2025_an' => '224,7 т (41,76%)',
                            'val_2025_yr' => '25 790,4 т (41,67%)',
                            'trend_text'  => 'Немає переробки',
                            'trend_class' => 'trend-neutral',
                        ],
                        [
                            'name'        => 'Вихід лузги (т / %)',
                            'val_2026'    => '0,0 т (0,00%)',
                            'val_2025_an' => '66,4 т (12,34%)',
                            'val_2025_yr' => '7 971,8 т (12,88%)',
                            'trend_text'  => 'Немає переробки',
                            'trend_class' => 'trend-neutral',
                        ],
                        [
                            'name'        => 'Втрати з елеватора (підробка)',
                            'val_2026'    => '0,0 т (0,00%)',
                            'val_2025_an' => '7,8 т (1,45%)',
                            'val_2025_yr' => '510,4 т (0,82%)',
                            'trend_text'  => 'Немає переробки',
                            'trend_class' => 'trend-neutral',
                        ],
                        [
                            'name'        => 'Сума Олія + Шрот (%)',
                            'val_2026'    => '0,00%',
                            'val_2025_an' => '86,21%',
                            'val_2025_yr' => '85,97%',
                            'trend_text'  => 'Немає переробки',
                            'trend_class' => 'trend-neutral',
                        ],
                        [
                            'name'        => 'Загальний вихід готової продукції',
                            'val_2026'    => '0,00%',
                            'val_2025_an' => '98,55%',
                            'val_2025_yr' => '98,85%',
                            'trend_text'  => 'Немає переробки',
                            'trend_class' => 'trend-neutral',
                        ],
                        [
                            'name'        => 'Залишок насіння на складі заводу',
                            'val_2026'    => number_format($seedStock, 1, ',', ' ') . ' т',
                            'val_2025_an' => '176,5 т',
                            'val_2025_yr' => '510,4 т',
                            'trend_text'  => '21.9 діб автономії',
                            'trend_class' => 'trend-good',
                        ],
                    ]
                ];
            }
        } elseif ($norm === 'day_active') {
            // РОБОЧА ДОБА 2026 (Остання активна доба з переробкою 600,3 т)
            return [
                'period'       => 'day_active',
                'badge_label'  => 'Порівняння робочої доби: 2026 (робота) vs 2025',
                'kpis' => [
                    'seed_processed' => [
                        'title'        => '⚙️ Переробка насіння',
                        'badge'        => 'Робоча доба (план 600 т)',
                        'number'       => '600,3',
                        'unit'         => 'т (активна доба 2026)',
                        'subtext'      => '2025 аналог: <strong>538,1 т/добу</strong> &bull; Відхилення: <strong>+62,2 т (+11,5%)</strong>',
                        'progress_pct' => 100.0,
                        'color'        => '#10b981',
                    ],
                    'oil_yield' => [
                        'title'        => '💧 Вихід Олії',
                        'badge'        => '+1,46% до 2025',
                        'number'       => '45,91%',
                        'unit'         => 'активна доба (275,6 т)',
                        'subtext'      => '2025 аналог: <strong>44,45% (239,2 т)</strong> &bull; Відхилення: <strong>+1,46% (+8,8 т)</strong>',
                        'progress_pct' => 45.9,
                        'color'        => '#f59e0b',
                    ],
                    'meal_yield' => [
                        'title'        => '🌾 Вихід Шроту',
                        'badge'        => '-0,24% (в нормі)',
                        'number'       => '41,52%',
                        'unit'         => 'активна доба (249,2 т)',
                        'subtext'      => '2025 аналог: <strong>41,76% (224,7 т)</strong> &bull; Відхилення: <strong>-0,24% (в нормі)</strong>',
                        'progress_pct' => 41.5,
                        'color'        => '#2563eb',
                    ],
                    'oil_meal_sum' => [
                        'title'        => '⚖️ Олія + Шрот',
                        'badge'        => '+1,22% до 2025',
                        'number'       => '87,43%',
                        'unit'         => 'активна доба (524,8 т)',
                        'subtext'      => '2025 аналог: <strong>86,21%</strong> &bull; Відхилення: <strong>+1,22% (рекорд якості)</strong>',
                        'progress_pct' => 87.4,
                        'color'        => '#8b5cf6',
                    ],
                ],
                'headers' => [
                    'col_indicator'   => 'Показник виробництва',
                    'col_2026'        => 'Робоча доба 2026 (06.10)',
                    'col_2025_analog' => 'Аналогічна доба 2025 (Жовтень)',
                    'col_2025_year'   => 'Сезон 2025–2026 (61,9 тис. т)',
                    'col_trend'       => 'Динаміка',
                ],
                'rows' => [
                    [
                        'name'        => 'Завезено насіння (авто/елеватор)',
                        'val_2026'    => '589,8 т',
                        'val_2025_an' => '543,8 т/добу',
                        'val_2025_yr' => '62 403,3 т',
                        'trend_text'  => 'Прийомка доби',
                        'trend_class' => 'trend-good',
                    ],
                    [
                        'name'        => 'Перероблено соняшнику (очищене)',
                        'val_2026'    => '600,3 т (активна доба)',
                        'val_2025_an' => '538,1 т/добу',
                        'val_2025_yr' => '61 892,9 т',
                        'trend_text'  => '+11.5% при роботі',
                        'trend_class' => 'trend-good',
                    ],
                    [
                        'name'        => 'Середня олійність сировини (вхід у цех)',
                        'val_2026'    => number_format($labOil, 2, ',', '') . '% (лаб. якість)',
                        'val_2025_an' => '45,29%',
                        'val_2025_yr' => '45,13%',
                        'trend_text'  => '+' . number_format($labOil - 45.29, 2, ',', '') . '% якість',
                        'trend_class' => 'trend-good',
                    ],
                    [
                        'name'        => 'Вихід олії (т / %)',
                        'val_2026'    => '275,6 т (45,91%)',
                        'val_2025_an' => '239,2 т (44,45%)',
                        'val_2025_yr' => '27 416,9 т (44,30%)',
                        'trend_text'  => '+1.46% до 2025',
                        'trend_class' => 'trend-good',
                    ],
                    [
                        'name'        => 'Вихід шроту (т / %)',
                        'val_2026'    => '249,2 т (41,52%)',
                        'val_2025_an' => '224,7 т (41,76%)',
                        'val_2025_yr' => '25 790,4 т (41,67%)',
                        'trend_text'  => '-0.24% в нормі',
                        'trend_class' => 'trend-good',
                    ],
                    [
                        'name'        => 'Вихід лузги (т / %)',
                        'val_2026'    => '87,1 т (14,51%)',
                        'val_2025_an' => '66,4 т (12,34%)',
                        'val_2025_yr' => '7 971,8 т (12,88%)',
                        'trend_text'  => 'Паливний надлишок',
                        'trend_class' => 'trend-good',
                    ],
                    [
                        'name'        => 'Втрати з елеватора (підробка)',
                        'val_2026'    => '7,3 т (1,20%)',
                        'val_2025_an' => '7,8 т (1,45%)',
                        'val_2025_yr' => '510,4 т (0,82%)',
                        'trend_text'  => 'Краще на 0.25%',
                        'trend_class' => 'trend-good',
                    ],
                    [
                        'name'        => 'Сума Олія + Шрот (%)',
                        'val_2026'    => '87,43%',
                        'val_2025_an' => '86,21%',
                        'val_2025_yr' => '85,97%',
                        'trend_text'  => '+1.22% до 2025',
                        'trend_class' => 'trend-good',
                    ],
                    [
                        'name'        => 'Загальний вихід готової продукції',
                        'val_2026'    => '101,94% (+1,94%)',
                        'val_2025_an' => '98,55%',
                        'val_2025_yr' => '98,85%',
                        'trend_text'  => '+3.39% вище еталону',
                        'trend_class' => 'trend-good',
                    ],
                    [
                        'name'        => 'Залишок насіння на складі заводу',
                        'val_2026'    => number_format($seedStock, 1, ',', ' ') . ' т',
                        'val_2025_an' => '176,5 т',
                        'val_2025_yr' => '510,4 т',
                        'trend_text'  => '21.9 діб автономії',
                        'trend_class' => 'trend-good',
                    ],
                ]
            ];
        } elseif ($norm === 'week') {
            $seedIn = !empty($stats['seed_in_plant']) ? (float)$stats['seed_in_plant'] : 3767.78;
            return [
                'period'       => 'week',
                'badge_label'  => 'Порівняння тижня: 2026 vs 2025',
                'kpis' => [
                    'seed_processed' => [
                        'title'        => '⚙️ Переробка насіння',
                        'badge'        => 'Тиждень 2026 (7 діб)',
                        'number'       => '1 310,3',
                        'unit'         => 'т (тиждень 2026)',
                        'subtext'      => '2025 аналог: <strong>3 766,8 т</strong> &bull; Відхилення: <strong>-2 456,5 т</strong> (технологічна пауза)',
                        'progress_pct' => 34.8,
                        'color'        => '#10b981',
                    ],
                    'oil_yield' => [
                        'title'        => '💧 Вихід Олії',
                        'badge'        => '+1,26% до 2025',
                        'number'       => '45,71%',
                        'unit'         => 'тиждень 2026 (599,0 т)',
                        'subtext'      => '2025 аналог: <strong>44,45%</strong> &bull; Відхилення: <strong>+1,26% (+16,5 т олії)</strong>',
                        'progress_pct' => 45.7,
                        'color'        => '#f59e0b',
                    ],
                    'meal_yield' => [
                        'title'        => '🌾 Вихід Шроту',
                        'badge'        => '+2,19% до 2025',
                        'number'       => '43,95%',
                        'unit'         => 'тиждень 2026 (575,9 т)',
                        'subtext'      => '2025 аналог: <strong>41,76%</strong> &bull; Відхилення: <strong>+2,19% (+28,7 т шроту)</strong>',
                        'progress_pct' => 43.9,
                        'color'        => '#2563eb',
                    ],
                    'oil_meal_sum' => [
                        'title'        => '⚖️ Олія + Шрот',
                        'badge'        => '+3,45% до 2025',
                        'number'       => '89,66%',
                        'unit'         => 'тиждень 2026 (1 174,8 т)',
                        'subtext'      => '2025 аналог: <strong>86,21%</strong> &bull; Відхилення: <strong>+3,45% (макс. вихід)</strong>',
                        'progress_pct' => 89.7,
                        'color'        => '#8b5cf6',
                    ],
                ],
                'headers' => [
                    'col_indicator'   => 'Показник виробництва',
                    'col_2026'        => 'Тиждень 2026 (7 діб)',
                    'col_2025_analog' => 'Аналогічний тиждень 2025 (7 діб)',
                    'col_2025_year'   => 'Сезон 2025–2026 (61,9 тис. т)',
                    'col_trend'       => 'Динаміка',
                ],
                'rows' => [
                    [
                        'name'        => 'Завезено насіння (авто/елеватор)',
                        'val_2026'    => number_format($seedIn, 1, ',', ' ') . ' т',
                        'val_2025_an' => '3 806,7 т',
                        'val_2025_yr' => '62 403,3 т',
                        'trend_text'  => '-1.0% на рівні 2025',
                        'trend_class' => 'trend-good',
                    ],
                    [
                        'name'        => 'Перероблено соняшнику (очищене)',
                        'val_2026'    => '1 310,3 т (активні доби)',
                        'val_2025_an' => '3 766,8 т',
                        'val_2025_yr' => '61 892,9 т',
                        'trend_text'  => 'Технологічна пауза',
                        'trend_class' => 'trend-neutral',
                    ],
                    [
                        'name'        => 'Середня олійність сировини (вхід у цех)',
                        'val_2026'    => number_format($labOil, 2, ',', '') . '% (лаб.)',
                        'val_2025_an' => '45,29%',
                        'val_2025_yr' => '45,13%',
                        'trend_text'  => '+2.71% якість',
                        'trend_class' => 'trend-good',
                    ],
                    [
                        'name'        => 'Вихід олії (т / %)',
                        'val_2026'    => '599,0 т (45,71%)',
                        'val_2025_an' => '1 674,2 т (44,45%)',
                        'val_2025_yr' => '27 416,9 т (44,30%)',
                        'trend_text'  => '+1.26% до 2025',
                        'trend_class' => 'trend-good',
                    ],
                    [
                        'name'        => 'Вихід шроту (т / %)',
                        'val_2026'    => '575,9 т (43,95%)',
                        'val_2025_an' => '1 573,1 т (41,76%)',
                        'val_2025_yr' => '25 790,4 т (41,67%)',
                        'trend_text'  => '+2.19% вище норми',
                        'trend_class' => 'trend-good',
                    ],
                    [
                        'name'        => 'Вихід лузги (т / %)',
                        'val_2026'    => '194,3 т (14,83%)',
                        'val_2025_an' => '464,8 т (12,34%)',
                        'val_2025_yr' => '7 971,8 т (12,88%)',
                        'trend_text'  => 'Запас котельні',
                        'trend_class' => 'trend-good',
                    ],
                    [
                        'name'        => 'Втрати з елеватора (підробка)',
                        'val_2026'    => '17,9 т (1,35%)',
                        'val_2025_an' => '54,7 т (1,45%)',
                        'val_2025_yr' => '510,4 т (0,82%)',
                        'trend_text'  => '-0.10% втрат',
                        'trend_class' => 'trend-good',
                    ],
                    [
                        'name'        => 'Сума Олія + Шрот (%)',
                        'val_2026'    => '89,66%',
                        'val_2025_an' => '86,21%',
                        'val_2025_yr' => '85,97%',
                        'trend_text'  => '+3.45% рекорд',
                        'trend_class' => 'trend-good',
                    ],
                    [
                        'name'        => 'Загальний вихід готової продукції',
                        'val_2026'    => '104,49% (+4,49%)',
                        'val_2025_an' => '98,55%',
                        'val_2025_yr' => '98,85%',
                        'trend_text'  => '+5.94% баланс +',
                        'trend_class' => 'trend-good',
                    ],
                    [
                        'name'        => 'Залишок насіння на складі заводу',
                        'val_2026'    => number_format($seedStock, 1, ',', ' ') . ' т',
                        'val_2025_an' => '176,5 т',
                        'val_2025_yr' => '510,4 т',
                        'trend_text'  => 'Повний склад',
                        'trend_class' => 'trend-good',
                    ],
                ]
            ];
        } elseif ($norm === 'month_sep') {
            return [
                'period'       => 'month_sep',
                'badge_label'  => 'Вересень 2026 vs Вересень 2025',
                'kpis' => [
                    'seed_processed' => [
                        'title'        => '⚙️ Переробка насіння',
                        'badge'        => '+98,9% до 2025',
                        'number'       => '8 823,7',
                        'unit'         => 'т (Вересень 2026)',
                        'subtext'      => '2025 аналог: <strong>4 435,4 т</strong> (10 діб) &bull; Відхилення: <strong>+4 388,3 т (+98,9%)</strong>',
                        'progress_pct' => 100.0,
                        'color'        => '#10b981',
                    ],
                    'oil_yield' => [
                        'title'        => '💧 Вихід Олії',
                        'badge'        => '+1,14% до 2025',
                        'number'       => '43,20%',
                        'unit'         => 'Вересень 2026 (3 811,7 т)',
                        'subtext'      => '2025 еталон: <strong>42,06% (1 865,6 т)</strong> &bull; Відхилення: <strong>+1,14% (+100,6 т)</strong>',
                        'progress_pct' => 43.2,
                        'color'        => '#f59e0b',
                    ],
                    'meal_yield' => [
                        'title'        => '🌾 Вихід Шроту',
                        'badge'        => '-0,40% (в нормі)',
                        'number'       => '41,74%',
                        'unit'         => 'Вересень 2026 (3 683,2 т)',
                        'subtext'      => '2025 еталон: <strong>42,14% (1 869,2 т)</strong> &bull; Відхилення: <strong>-0,40% (в нормі)</strong>',
                        'progress_pct' => 41.7,
                        'color'        => '#2563eb',
                    ],
                    'oil_meal_sum' => [
                        'title'        => '⚖️ Олія + Шрот',
                        'badge'        => '+0,74% до 2025',
                        'number'       => '84,94%',
                        'unit'         => 'Вересень 2026 (7 494,9 т)',
                        'subtext'      => '2025 еталон: <strong>84,20%</strong> &bull; Відхилення: <strong>+0,74% (+1,04% вихід)</strong>',
                        'progress_pct' => 84.9,
                        'color'        => '#8b5cf6',
                    ],
                ],
                'headers' => [
                    'col_indicator'   => 'Показник виробництва',
                    'col_2026'        => 'Вересень 2026 (16 діб)',
                    'col_2025_analog' => 'Вересень 2025 (старт)',
                    'col_2025_year'   => 'Сезон 2025–2026 (61,9 тис. т)',
                    'col_trend'       => 'Динаміка',
                ],
                'rows' => [
                    [
                        'name'        => 'Завезено насіння (авто/елеватор)',
                        'val_2026'    => '19 077,5 т',
                        'val_2025_an' => '4 470,8 т',
                        'val_2025_yr' => '62 403,3 т',
                        'trend_text'  => '+326.7% до вер. 2025',
                        'trend_class' => 'trend-good',
                    ],
                    [
                        'name'        => 'Перероблено соняшнику (очищене)',
                        'val_2026'    => '8 823,7 т (16 діб)',
                        'val_2025_an' => '4 435,4 т (10 діб)',
                        'val_2025_yr' => '61 892,9 т',
                        'trend_text'  => '+98.9% до вер. 2025',
                        'trend_class' => 'trend-good',
                    ],
                    [
                        'name'        => 'Середня олійність сировини (вхід у цех)',
                        'val_2026'    => '42,93%',
                        'val_2025_an' => '42,93%',
                        'val_2025_yr' => '45,13%',
                        'trend_text'  => 'Точний збіг',
                        'trend_class' => 'trend-good',
                    ],
                    [
                        'name'        => 'Вихід олії (т / %)',
                        'val_2026'    => '3 811,7 т (43,20%)',
                        'val_2025_an' => '1 865,6 т (42,06%)',
                        'val_2025_yr' => '27 416,9 т (44,30%)',
                        'trend_text'  => '+1.14% до 2025',
                        'trend_class' => 'trend-good',
                    ],
                    [
                        'name'        => 'Вихід шроту (т / %)',
                        'val_2026'    => '3 683,2 т (41,74%)',
                        'val_2025_an' => '1 869,2 т (42,14%)',
                        'val_2025_yr' => '25 790,4 т (41,67%)',
                        'trend_text'  => '-0.40% в нормі',
                        'trend_class' => 'trend-good',
                    ],
                    [
                        'name'        => 'Вихід лузги (т / %)',
                        'val_2026'    => '1 261,2 т (14,29%)',
                        'val_2025_an' => '620,1 т (13,98%)',
                        'val_2025_yr' => '7 971,8 т (12,88%)',
                        'trend_text'  => '+0.31% баланс',
                        'trend_class' => 'trend-good',
                    ],
                    [
                        'name'        => 'Втрати з елеватора (підробка)',
                        'val_2026'    => '94,7 т (1,06%)',
                        'val_2025_an' => '80,5 т (1,81%)',
                        'val_2025_yr' => '510,4 т (0,82%)',
                        'trend_text'  => '-0.75% менше втрат',
                        'trend_class' => 'trend-good',
                    ],
                    [
                        'name'        => 'Сума Олія + Шрот (%)',
                        'val_2026'    => '84,94%',
                        'val_2025_an' => '84,20%',
                        'val_2025_yr' => '85,97%',
                        'trend_text'  => '+0.74% до 2025',
                        'trend_class' => 'trend-good',
                    ],
                    [
                        'name'        => 'Загальний вихід готової продукції',
                        'val_2026'    => '99,23%',
                        'val_2025_an' => '98,19%',
                        'val_2025_yr' => '98,85%',
                        'trend_text'  => '+1.04% до 2025',
                        'trend_class' => 'trend-good',
                    ],
                    [
                        'name'        => 'Залишок насіння на складі заводу',
                        'val_2026'    => '10 966,3 т',
                        'val_2025_an' => '35,4 т',
                        'val_2025_yr' => '510,4 т',
                        'trend_text'  => 'Склад заповнений',
                        'trend_class' => 'trend-good',
                    ],
                ]
            ];
        } elseif ($norm === 'season') {
            $seasonSeed = !empty($this->getSemenaPeriodStats('season')['seed_in_plant']) ? (float)$this->getSemenaPeriodStats('season')['seed_in_plant'] : 25353.92;
            return [
                'period'       => 'season',
                'badge_label'  => 'Сезон 2026–2027 vs Сезон 2025–2026',
                'kpis' => [
                    'seed_processed' => [
                        'title'        => '⚙️ Переробка насіння',
                        'badge'        => 'Сезон 2026–2027 (23 доби)',
                        'number'       => '12 470,0',
                        'unit'         => 'т (Сезон 2026–2027)',
                        'subtext'      => '2025–2026 (Вер–Жов): <strong>21 117,1 т</strong> &bull; Сезон 2025–2026: <strong>61 892,9 т</strong> (20,1%)',
                        'progress_pct' => 100.0,
                        'color'        => '#10b981',
                    ],
                    'oil_yield' => [
                        'title'        => '💧 Вихід Олії',
                        'badge'        => '-0,17% до старту 25/26',
                        'number'       => '43,77%',
                        'unit'         => 'Сезон 2026–2027 (5 457,8 т)',
                        'subtext'      => '2025–2026 (Вер–Жов): <strong>43,94%</strong> (сезон 44,30%) &bull; Жовтень 2026: <strong>45,15%</strong>',
                        'progress_pct' => 43.8,
                        'color'        => '#f59e0b',
                    ],
                    'meal_yield' => [
                        'title'        => '🌾 Вихід Шроту',
                        'badge'        => '+0,07% до сезону 25/26',
                        'number'       => '41,74%',
                        'unit'         => 'Сезон 2026–2027 (5 204,9 т)',
                        'subtext'      => '2025–2026 (Вер–Жов): <strong>41,84%</strong> (сезон 41,67%) &bull; Відхилення: <strong>+0,07%</strong>',
                        'progress_pct' => 41.7,
                        'color'        => '#2563eb',
                    ],
                    'oil_meal_sum' => [
                        'title'        => '⚖️ Олія + Шрот',
                        'badge'        => 'Ціль 85–86%',
                        'number'       => '85,51%',
                        'unit'         => 'Сезон 2026–2027 (10 662,7 т)',
                        'subtext'      => '2025–2026 (Вер–Жов): <strong>85,78%</strong> (сезон 85,97%) &bull; Готова прод.: <strong>100,07%</strong>',
                        'progress_pct' => 85.5,
                        'color'        => '#8b5cf6',
                    ],
                ],
                'headers' => [
                    'col_indicator'   => 'Показник виробництва',
                    'col_2026'        => 'Сезон 2026–2027 (01.08–01.08)',
                    'col_2025_analog' => 'Сезон 2025–2026 (Вер–Жов)',
                    'col_2025_year'   => 'Весь сезон 2025–2026 (61,9 тис. т)',
                    'col_trend'       => 'Динаміка',
                ],
                'rows' => [
                    [
                        'name'        => 'Завезено насіння (авто/елеватор)',
                        'val_2026'    => number_format($seasonSeed, 1, ',', ' ') . ' т',
                        'val_2025_an' => '21 329,0 т',
                        'val_2025_yr' => '62 403,3 т',
                        'trend_text'  => '+18.9% завіз',
                        'trend_class' => 'trend-good',
                    ],
                    [
                        'name'        => 'Перероблено соняшнику (очищене)',
                        'val_2026'    => '12 470,0 т',
                        'val_2025_an' => '21 117,1 т',
                        'val_2025_yr' => '61 892,9 т',
                        'trend_text'  => 'Запуск вересень',
                        'trend_class' => 'trend-neutral',
                    ],
                    [
                        'name'        => 'Середня олійність сировини (вхід у цех)',
                        'val_2026'    => '44,69% (лаб. 49,19%)',
                        'val_2025_an' => '44,79%',
                        'val_2025_yr' => '45,13%',
                        'trend_text'  => 'В межах норми',
                        'trend_class' => 'trend-good',
                    ],
                    [
                        'name'        => 'Вихід олії (т / %)',
                        'val_2026'    => '5 457,8 т (43,77%)',
                        'val_2025_an' => '9 279,8 т (43,94%)',
                        'val_2025_yr' => '27 416,9 т (44,30%)',
                        'trend_text'  => 'Жовтень 45,15%',
                        'trend_class' => 'trend-good',
                    ],
                    [
                        'name'        => 'Вихід шроту (т / %)',
                        'val_2026'    => '5 204,9 т (41,74%)',
                        'val_2025_an' => '8 835,8 т (41,84%)',
                        'val_2025_yr' => '25 790,4 т (41,67%)',
                        'trend_text'  => '+0,07% до року',
                        'trend_class' => 'trend-good',
                    ],
                    [
                        'name'        => 'Вихід лузги (т / %)',
                        'val_2026'    => '1 816,1 т (14,56%)',
                        'val_2025_an' => '2 678,6 т (12,68%)',
                        'val_2025_yr' => '7 971,8 т (12,88%)',
                        'trend_text'  => 'Паливний баланс',
                        'trend_class' => 'trend-neutral',
                    ],
                    [
                        'name'        => 'Втрати з елеватора (підробка)',
                        'val_2026'    => '148,3 т (1,18%)',
                        'val_2025_an' => '322,9 т (1,53%)',
                        'val_2025_yr' => '510,4 т (0,82%)',
                        'trend_text'  => 'Норма < 2,5%',
                        'trend_class' => 'trend-good',
                    ],
                    [
                        'name'        => 'Сума Олія + Шрот (%)',
                        'val_2026'    => '85,51%',
                        'val_2025_an' => '85,78%',
                        'val_2025_yr' => '85,97%',
                        'trend_text'  => 'Ціль 85–86%',
                        'trend_class' => 'trend-good',
                    ],
                    [
                        'name'        => 'Загальний вихід готової продукції',
                        'val_2026'    => '100,07% (+0,07%)',
                        'val_2025_an' => '98,47%',
                        'val_2025_yr' => '98,85%',
                        'trend_text'  => 'Вище еталону',
                        'trend_class' => 'trend-good',
                    ],
                    [
                        'name'        => 'Залишок насіння на складі заводу',
                        'val_2026'    => number_format($seedStock, 1, ',', ' ') . ' т',
                        'val_2025_an' => '211,9 т',
                        'val_2025_yr' => '510,4 т',
                        'trend_text'  => '22,2 доби автономії',
                        'trend_class' => 'trend-good',
                    ],
                ]
            ];
        } else {
            // Жовтень 2026 vs Жовтень 2025
            $octSeed = !empty($stats['seed_in_plant']) ? (float)$stats['seed_in_plant'] : 6075.91;
            return [
                'period'       => 'month_oct',
                'badge_label'  => 'Жовтень 2026 vs Жовтень 2025',
                'kpis' => [
                    'seed_processed' => [
                        'title'        => '⚙️ Переробка насіння',
                        'badge'        => 'Жовтень 2026 (7 діб)',
                        'number'       => '3 646,4',
                        'unit'         => 'т (Жовтень 2026)',
                        'subtext'      => '2025 аналог: <strong>16 681,7 т</strong> (31 доба) &bull; Відхилення: <strong>+52,8 т/добу (+11,5%)</strong>',
                        'progress_pct' => 86.8,
                        'color'        => '#10b981',
                    ],
                    'oil_yield' => [
                        'title'        => '💧 Вихід Олії',
                        'badge'        => '+0,70% до 2025',
                        'number'       => '45,15%',
                        'unit'         => 'Жовтень 2026 (1 646,2 т)',
                        'subtext'      => '2025 еталон: <strong>44,45% (7 414,2 т)</strong> &bull; Відхилення: <strong>+0,70% (+25,5 т)</strong>',
                        'progress_pct' => 45.2,
                        'color'        => '#f59e0b',
                    ],
                    'meal_yield' => [
                        'title'        => '🌾 Вихід Шроту',
                        'badge'        => '-0,03% (в нормі)',
                        'number'       => '41,73%',
                        'unit'         => 'Жовтень 2026 (1 521,7 т)',
                        'subtext'      => '2025 еталон: <strong>41,76% (6 966,6 т)</strong> &bull; Відхилення: <strong>-0,03% (-1,1 т)</strong>',
                        'progress_pct' => 41.7,
                        'color'        => '#2563eb',
                    ],
                    'oil_meal_sum' => [
                        'title'        => '⚖️ Олія + Шрот',
                        'badge'        => '+0,67% до 2025',
                        'number'       => '86,88%',
                        'unit'         => 'Жовтень 2026 (3 167,8 т)',
                        'subtext'      => '2025 еталон: <strong>86,21%</strong> &bull; Відхилення: <strong>+0,67% (висока якість)</strong>',
                        'progress_pct' => 86.9,
                        'color'        => '#8b5cf6',
                    ],
                ],
                'headers' => [
                    'col_indicator'   => 'Показник виробництва',
                    'col_2026'        => 'Жовтень 2026 (01–12.10)',
                    'col_2025_analog' => 'Жовтень 2025 (повний місяць)',
                    'col_2025_year'   => 'Сезон 2025–2026 (61,9 тис. т)',
                    'col_trend'       => 'Динаміка',
                ],
                'rows' => [
                    [
                        'name'        => 'Завезено насіння (авто/елеватор)',
                        'val_2026'    => number_format($octSeed, 1, ',', ' ') . ' т',
                        'val_2025_an' => '16 858,2 т',
                        'val_2025_yr' => '62 403,3 т',
                        'trend_text'  => '506,3 т/добу прийомка',
                        'trend_class' => 'trend-good',
                    ],
                    [
                        'name'        => 'Перероблено соняшнику (очищене)',
                        'val_2026'    => '3 646,4 т (7 діб)',
                        'val_2025_an' => '16 681,7 т (31 день)',
                        'val_2025_yr' => '61 892,9 т',
                        'trend_text'  => '520,9 т/добу роботи',
                        'trend_class' => 'trend-good',
                    ],
                    [
                        'name'        => 'Середня олійність сировини (вхід у цех)',
                        'val_2026'    => '44,69% (лаб. 49,19%)',
                        'val_2025_an' => '45,29%',
                        'val_2025_yr' => '45,13%',
                        'trend_text'  => 'В межах норми',
                        'trend_class' => 'trend-good',
                    ],
                    [
                        'name'        => 'Вихід олії (т / %)',
                        'val_2026'    => '1 646,2 т (45,15%)',
                        'val_2025_an' => '7 414,2 т (44,45%)',
                        'val_2025_yr' => '27 416,9 т (44,30%)',
                        'trend_text'  => '+0.70% до 2025',
                        'trend_class' => 'trend-good',
                    ],
                    [
                        'name'        => 'Вихід шроту (т / %)',
                        'val_2026'    => '1 521,7 т (41,73%)',
                        'val_2025_an' => '6 966,6 т (41,76%)',
                        'val_2025_yr' => '25 790,4 т (41,67%)',
                        'trend_text'  => '-0.03% стабільність',
                        'trend_class' => 'trend-good',
                    ],
                    [
                        'name'        => 'Вихід лузги (т / %)',
                        'val_2026'    => '554,9 т (15,22%)',
                        'val_2025_an' => '2 058,4 т (12,34%)',
                        'val_2025_yr' => '7 971,8 т (12,88%)',
                        'trend_text'  => 'Паливна безпека',
                        'trend_class' => 'trend-good',
                    ],
                    [
                        'name'        => 'Втрати з елеватора (підробка)',
                        'val_2026'    => '53,6 т (1,45%)',
                        'val_2025_an' => '242,4 т (1,45%)',
                        'val_2025_yr' => '510,4 т (0,82%)',
                        'trend_text'  => '1 в 1 з 2025 (1.45%)',
                        'trend_class' => 'trend-good',
                    ],
                    [
                        'name'        => 'Сума Олія + Шрот (%)',
                        'val_2026'    => '86,88%',
                        'val_2025_an' => '86,21%',
                        'val_2025_yr' => '85,97%',
                        'trend_text'  => '+0.67% до 2025',
                        'trend_class' => 'trend-good',
                    ],
                    [
                        'name'        => 'Загальний вихід готової продукції',
                        'val_2026'    => '102,10% (+2,10%)',
                        'val_2025_an' => '98,55%',
                        'val_2025_yr' => '98,85%',
                        'trend_text'  => '+3.55% надлишок',
                        'trend_class' => 'trend-good',
                    ],
                    [
                        'name'        => 'Залишок насіння на складі заводу',
                        'val_2026'    => number_format($seedStock, 1, ',', ' ') . ' т',
                        'val_2025_an' => '176,5 т',
                        'val_2025_yr' => '510,4 т',
                        'trend_text'  => '22,2 доби автономії',
                        'trend_class' => 'trend-good',
                    ],
                ]
            ];
        }
    }

    /**
     * Показники якості лабораторії (з файлу «Лист_погодження.xlsx» -> вкладка «Графік завезення (по днях)» з розрахунком за день, тиждень, місяць і сезон)
     */
    public function getLabQuality(string $period = 'day'): array {
        $stats = $this->getSemenaPeriodStats($period);
        if (!empty($stats) && isset($stats['oil_content_pct'])) {
            $oilPct = (float)$stats['oil_content_pct'];
            $basePct = 48.00;
            $firstDate = $stats['first_date'] ?? '';
            $lastDate  = $stats['last_date'] ?? '';
            $dateLabel = ($firstDate === $lastDate || empty($firstDate))
                ? "{$lastDate} (Лист погодження — по днях)"
                : "{$firstDate} — {$lastDate} ({$stats['days_count']} діб, Лист погодження)";

            return [
                'oil_content_pct'        => $oilPct,
                'oil_content_base_pct'   => $basePct,
                'oil_deviation_pct'      => round($oilPct - $basePct, 2),
                'moisture_pct'           => (float)$stats['moisture_pct'],
                'impurity_pct'           => (float)$stats['impurity_pct'],
                'weight_discount_tonnes' => (float)$stats['weight_discount_tonnes'],
                'report_date'            => $dateLabel,
            ];
        }

        return [
            'oil_content_pct'        => 49.61,
            'oil_content_base_pct'   => 48.00,
            'oil_deviation_pct'      => 1.61,
            'moisture_pct'           => 7.71,
            'impurity_pct'           => 3.28,
            'weight_discount_tonnes' => 2.65,
            'report_date'            => '11.10.2026 (Лист погодження — по днях)',
        ];
    }

    /**
     * Контрактація та виконання специфікацій (Реєстр договорів та довіз з «Листа погодження» 1С)
     */
    public function getContractsSummary(string $period = 'day'): array {
        $semena = $this->getSemenaData();
        $summary = $semena['summary'] ?? [];
        $stats = $this->getSemenaPeriodStats($period);

        $activeSpecs = (int)($summary['active_specs'] ?? 11);
        $pendingSpecs = (int)($summary['pending_specs'] ?? 3);

        switch ($period) {
            case 'week':
                $deliv = !empty($stats['seed_in_plant']) ? (float)$stats['seed_in_plant'] : 3767.78;
                $plan  = 4200.0;
                return [
                    'contracted_tonnes'   => $plan,
                    'delivered_tonnes'    => round($deliv, 2),
                    'remaining_tonnes'    => round(max(0.0, $plan - $deliv), 2),
                    'fulfillment_pct'     => round(($deliv / $plan) * 100, 1),
                    'active_specs_count'  => $activeSpecs,
                    'pending_specs_count' => $pendingSpecs,
                    'report_date'         => ($stats['first_date'] ?? '05.10') . ' — ' . ($stats['last_date'] ?? '11.10.2026') . ' (Тиждень)',
                ];
            case 'day_active':
                $deliv = !empty($stats['seed_in_plant']) ? (float)$stats['seed_in_plant'] : 589.76;
                $plan  = 600.0;
                return [
                    'contracted_tonnes'   => $plan,
                    'delivered_tonnes'    => round($deliv, 2),
                    'remaining_tonnes'    => round(max(0.0, $plan - $deliv), 2),
                    'fulfillment_pct'     => round(($deliv / $plan) * 100, 1),
                    'active_specs_count'  => $activeSpecs,
                    'pending_specs_count' => $pendingSpecs,
                    'report_date'         => '06.10.2026 (Доба переробки)',
                ];
            case 'month_aug':
                $deliv = !empty($stats['seed_in_plant']) ? (float)$stats['seed_in_plant'] : 207.96;
                return [
                    'contracted_tonnes'   => 5000.0,
                    'delivered_tonnes'    => round($deliv, 2),
                    'remaining_tonnes'    => round(max(0.0, 5000.0 - $deliv), 2),
                    'fulfillment_pct'     => round(($deliv / 5000.0) * 100, 1),
                    'active_specs_count'  => 4,
                    'pending_specs_count' => 12,
                    'report_date'         => 'Серпень 2026',
                ];
            case 'month_sep':
                $deliv = !empty($stats['seed_in_plant']) ? (float)$stats['seed_in_plant'] : 19077.54;
                return [
                    'contracted_tonnes'   => 20000.0,
                    'delivered_tonnes'    => round($deliv, 2),
                    'remaining_tonnes'    => round(max(0.0, 20000.0 - $deliv), 2),
                    'fulfillment_pct'     => round(($deliv / 20000.0) * 100, 1),
                    'active_specs_count'  => 18,
                    'pending_specs_count' => 1,
                    'report_date'         => 'Вересень 2026',
                ];
            case 'month_oct':
            case 'month':
                $deliv = !empty($stats['seed_in_plant']) ? (float)$stats['seed_in_plant'] : 6075.91;
                return [
                    'contracted_tonnes'   => 8000.0,
                    'delivered_tonnes'    => round($deliv, 2),
                    'remaining_tonnes'    => round(max(0.0, 8000.0 - $deliv), 2),
                    'fulfillment_pct'     => round(($deliv / 8000.0) * 100, 1),
                    'active_specs_count'  => $activeSpecs,
                    'pending_specs_count' => $pendingSpecs,
                    'report_date'         => 'Жовтень 2026',
                ];
            case 'season':
                $deliv = !empty($stats['seed_in_plant']) ? (float)$stats['seed_in_plant'] : 25361.41;
                $rem   = (float)($summary['unfulfilled_rem'] ?? 1647.93);
                $contr = round($deliv + $rem, 2);
                return [
                    'contracted_tonnes'   => $contr,
                    'delivered_tonnes'    => round($deliv, 2),
                    'remaining_tonnes'    => round($rem, 2),
                    'fulfillment_pct'     => $contr > 0 ? round(($deliv / $contr) * 100, 1) : 93.9,
                    'active_specs_count'  => $activeSpecs,
                    'pending_specs_count' => $pendingSpecs,
                    'report_date'         => 'Сезон 2026–2027 (Лист погодження)',
                ];
            case 'day_prev':
                $deliv = !empty($stats['seed_in_plant']) ? (float)$stats['seed_in_plant'] : 465.86;
                return [
                    'contracted_tonnes'   => 600.0,
                    'delivered_tonnes'    => round($deliv, 2),
                    'remaining_tonnes'    => round(max(0.0, 600.0 - $deliv), 2),
                    'fulfillment_pct'     => round(($deliv / 600.0) * 100, 1),
                    'active_specs_count'  => $activeSpecs,
                    'pending_specs_count' => $pendingSpecs,
                    'report_date'         => ($stats['last_date'] ?? '11.10.2026') . ' 20:00',
                ];
            case 'day_today':
            case 'day':
            default:
                $contr = (float)($summary['total_contract'] ?? 18711.0);
                $deliv = (float)($summary['total_delivered'] ?? 17642.9);
                $rem   = (float)($summary['unfulfilled_rem'] ?? 1647.9);
                $pct   = (float)($summary['fulfillment_pct'] ?? ($contr > 0 ? round(($deliv / $contr) * 100, 1) : 94.3));
                return [
                    'contracted_tonnes'   => round($contr, 1),
                    'delivered_tonnes'    => round($deliv, 1),
                    'remaining_tonnes'    => round($rem, 1),
                    'fulfillment_pct'     => $pct,
                    'active_specs_count'  => $activeSpecs,
                    'pending_specs_count' => $pendingSpecs,
                    'report_date'         => ($semena['updated_at'] ?? '12.10.2026') . ' (Лист погодження)',
                ];
        }
    }
}

