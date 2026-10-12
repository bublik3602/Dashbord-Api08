/**
 * Инициализация и управление графиками ApexCharts для дашборда завода
 */

window.FactoryCharts = {
    donutChart: null,
    energyChart: null,
    gaugeOilChart: null,
    gaugeMoistureChart: null,
    gaugeImpurityChart: null,
    gaugeContractsChart: null,
    monthlyChart: null,

    /**
     * Инициализация круговой диаграммы выхода готовой продукции
     */
    initDonutChart: function (initialData) {
        const el = document.querySelector("#production-donut-chart");
        if (!el) return;

        const options = {
            series: initialData.values || [205.9, 182.2, 80.0, 5.3],
            chart: {
                type: 'donut',
                height: 250,
                fontFamily: 'inherit',
                animations: {
                    enabled: true,
                    easing: 'easeinout',
                    speed: 600
                }
            },
            labels: initialData.labels || ['Олія', 'Шрот', 'Лузга', 'Втрати'],
            colors: ['#f59e0b', '#3b82f6', '#10b981', '#ef4444'],
            stroke: {
                show: true,
                width: 2,
                colors: ['#ffffff']
            },
            dataLabels: {
                enabled: false
            },
            legend: {
                show: false // Відображаємо кастомну легінду
            },
            plotOptions: {
                pie: {
                    donut: {
                        size: '72%',
                        labels: {
                            show: true,
                            name: {
                                show: true,
                                fontSize: '13px',
                                fontWeight: 600,
                                color: '#64748b'
                            },
                            value: {
                                show: true,
                                fontSize: '20px',
                                fontWeight: 800,
                                color: '#1e293b',
                                formatter: function (val) {
                                    return parseFloat(val).toFixed(1) + ' т';
                                }
                            },
                            total: {
                                show: true,
                                label: 'Продукція',
                                fontSize: '12px',
                                fontWeight: 600,
                                color: '#94a3b8',
                                formatter: function (w) {
                                    const sum = w.globals.seriesTotals.reduce((a, b) => a + b, 0);
                                    return sum.toFixed(1) + ' т';
                                }
                            }
                        }
                    }
                }
            },
            tooltip: {
                y: {
                    formatter: function (value) {
                        return value + ' т';
                    }
                }
            }
        };

        this.donutChart = new ApexCharts(el, options);
        this.donutChart.render();
    },

    /**
     * Ініціалізація графіка енергоспоживання та витрати води
     */
    initEnergyChart: function (timelineData) {
        const el = document.querySelector("#energy-timeline-chart");
        if (!el) return;

        const options = {
            series: [
                {
                    name: 'Електроенергія (кВт)',
                    type: 'area',
                    data: timelineData.power || [3420, 3510, 3580, 3620, 3590, 3480, 3410, 3650, 3820, 3890, 3850, 3940]
                },
                {
                    name: 'Витрата води (м³/год)',
                    type: 'line',
                    data: timelineData.water || [48, 51, 52, 53, 52, 49, 47, 54, 57, 58, 56, 58]
                }
            ],
            chart: {
                height: 240,
                type: 'line',
                fontFamily: 'inherit',
                toolbar: {
                    show: false
                },
                animations: {
                    enabled: true,
                    speed: 500
                }
            },
            stroke: {
                width: [2, 2],
                curve: 'smooth'
            },
            fill: {
                type: ['gradient', 'solid'],
                gradient: {
                    shade: 'light',
                    type: "vertical",
                    shadeIntensity: 0.2,
                    gradientToColors: undefined,
                    inverseColors: false,
                    opacityFrom: 0.35,
                    opacityTo: 0.05,
                    stops: [0, 90, 100]
                }
            },
            colors: ['#0284c7', '#10b981'],
            dataLabels: {
                enabled: false
            },
            xaxis: {
                categories: timelineData.labels || ['10:00', '11:00', '12:00', '13:00', '14:00', '15:00', '16:00', '17:00', '18:00', '19:00', '20:00', '21:00'],
                axisBorder: {
                    show: false
                },
                axisTicks: {
                    show: false
                },
                labels: {
                    style: {
                        colors: '#94a3b8',
                        fontSize: '11px'
                    }
                }
            },
            yaxis: [
                {
                    title: {
                        text: 'кВт',
                        style: { color: '#0284c7', fontSize: '11px', fontWeight: 600 }
                    },
                    labels: {
                        style: { colors: '#64748b', fontSize: '11px' },
                        formatter: function (v) { return Math.round(v); }
                    }
                },
                {
                    opposite: true,
                    title: {
                        text: 'м³/год',
                        style: { color: '#10b981', fontSize: '11px', fontWeight: 600 }
                    },
                    labels: {
                        style: { colors: '#64748b', fontSize: '11px' },
                        formatter: function (v) { return Math.round(v); }
                    }
                }
            ],
            grid: {
                borderColor: '#f1f5f9',
                strokeDashArray: 4,
            },
            legend: {
                position: 'top',
                horizontalAlign: 'right',
                fontSize: '12px',
                labels: {
                    colors: '#64748b'
                }
            },
            tooltip: {
                shared: true,
                intersect: false,
            }
        };

        this.energyChart = new ApexCharts(el, options);
        this.energyChart.render();
    },

    /**
     * Ініціалізація 4 графічних радіальних діаграм (Лабораторія та Контракти)
     */
    initGauges: function (labData, contractsData) {
        labData = labData || {};
        contractsData = contractsData || {};

        const oilVal = parseFloat(labData.oil_content_pct || 49.62);
        const baseOil = parseFloat(labData.oil_content_base_pct || 48.0);
        const moistVal = parseFloat(labData.moisture_pct || 7.15);
        const impVal = parseFloat(labData.impurity_pct || 3.20);
        const contractPct = parseFloat(contractsData.fulfillment_pct || 88.0);

        const makeGaugeOptions = (seriesVal, minVal, maxVal, color, decimals = 2) => {
            return {
                series: [seriesVal],
                chart: {
                    type: 'radialBar',
                    height: 180,
                    sparkline: { enabled: true }
                },
                plotOptions: {
                    radialBar: {
                        startAngle: -105,
                        endAngle: 105,
                        hollow: { size: '65%' },
                        track: {
                            background: '#f1f5f9',
                            strokeWidth: '95%'
                        },
                        dataLabels: {
                            name: { show: false },
                            value: {
                                offsetY: -2,
                                fontSize: '20px',
                                fontWeight: '800',
                                color: '#0f172a',
                                formatter: function (val) {
                                    return parseFloat(val).toFixed(decimals) + '%';
                                }
                            }
                        }
                    }
                },
                fill: {
                    type: 'solid',
                    colors: [color]
                },
                yaxis: {
                    min: minVal,
                    max: maxVal
                }
            };
        };

        // 1. Олійність
        const elOil = document.querySelector("#chart-gauge-oil");
        if (elOil) {
            const oilColor = (oilVal >= baseOil) ? '#10b981' : '#ef4444';
            this.gaugeOilChart = new ApexCharts(elOil, makeGaugeOptions(oilVal, 30, 60, oilColor, 2));
            this.gaugeOilChart.render();
        }

        // 2. Вологість
        const elMoist = document.querySelector("#chart-gauge-moisture");
        if (elMoist) {
            const moistColor = (moistVal <= 8.0) ? '#0284c7' : '#f59e0b';
            this.gaugeMoistureChart = new ApexCharts(elMoist, makeGaugeOptions(moistVal, 0, 14, moistColor, 2));
            this.gaugeMoistureChart.render();
        }

        // 3. Смітна домішка
        const elImp = document.querySelector("#chart-gauge-impurity");
        if (elImp) {
            const impColor = (impVal <= 3.0) ? '#10b981' : '#f59e0b';
            this.gaugeImpurityChart = new ApexCharts(elImp, makeGaugeOptions(impVal, 0, 8, impColor, 2));
            this.gaugeImpurityChart.render();
        }

        // 4. Контрактація 1С
        const elContr = document.querySelector("#chart-gauge-contracts");
        if (elContr) {
            const contrColor = '#6366f1';
            this.gaugeContractsChart = new ApexCharts(elContr, makeGaugeOptions(contractPct, 0, 100, contrColor, 1));
            this.gaugeContractsChart.render();
        }
    },

    /**
     * Ініціалізація графіка помісячного порівняння виробництва (Серпень, Вересень, Жовтень 2026)
     */
    initMonthlyAnalyticsChart: function (historicalData) {
        const el = document.querySelector("#monthly-analytics-chart");
        if (!el) return;

        const hist = (historicalData && historicalData.chart_comparison) ? historicalData.chart_comparison : {
            categories: ['Серпень 2026', 'Вересень 2026', 'Жовтень 2026 (1-11)'],
            seed_in: [207.96, 19070.05, 6075.91],
            seed_processed: [0.00, 8918.40, 4296.35],
            oil_out: [0.00, 3811.66, 1593.13],
            meal_out: [0.00, 3683.21, 1521.68]
        };

        const options = {
            series: [
                {
                    name: 'Завіз насіння (т)',
                    data: hist.seed_in || [208, 19070, 6076]
                },
                {
                    name: 'Переробка сировини (т)',
                    data: hist.seed_processed || [0, 8918, 4296]
                },
                {
                    name: 'Олія (т)',
                    data: hist.oil_out || [0, 3812, 1593]
                },
                {
                    name: 'Шрот (т)',
                    data: hist.meal_out || [0, 3683, 1522]
                }
            ],
            chart: {
                type: 'bar',
                height: 290,
                fontFamily: 'inherit',
                toolbar: {
                    show: false
                },
                animations: {
                    enabled: true,
                    speed: 600
                }
            },
            plotOptions: {
                bar: {
                    horizontal: false,
                    columnWidth: '55%',
                    borderRadius: 4
                }
            },
            dataLabels: {
                enabled: false
            },
            stroke: {
                show: true,
                width: 2,
                colors: ['transparent']
            },
            colors: ['#0284c7', '#8b5cf6', '#f59e0b', '#10b981'],
            xaxis: {
                categories: hist.categories || ['Серпень', 'Вересень', 'Жовтень'],
                labels: {
                    style: {
                        colors: '#64748b',
                        fontSize: '12px',
                        fontWeight: 600
                    }
                }
            },
            yaxis: {
                labels: {
                    formatter: function (val) {
                        return (val >= 1000 ? (val / 1000).toFixed(1) + ' тис. т' : val.toFixed(0) + ' т');
                    },
                    style: {
                        colors: '#94a3b8',
                        fontSize: '11px'
                    }
                }
            },
            fill: {
                opacity: 0.95
            },
            tooltip: {
                y: {
                    formatter: function (val) {
                        return parseFloat(val).toLocaleString('uk-UA', { minimumFractionDigits: 1, maximumFractionDigits: 2 }) + ' т';
                    }
                }
            },
            legend: {
                position: 'top',
                horizontalAlign: 'center',
                fontSize: '12px'
            },
            grid: {
                borderColor: '#f1f5f9',
                strokeDashArray: 3
            }
        };

        this.monthlyChart = new ApexCharts(el, options);
        this.monthlyChart.render();
    },

    /**
     * Оновлення даних графіків
     */
    updateCharts: function (data) {
        if (this.donutChart && data.material_balance && data.material_balance.yield_chart) {
            this.donutChart.updateSeries(data.material_balance.yield_chart.values);
        }

        if (this.energyChart && data.energy && data.energy.timeline) {
            this.energyChart.updateOptions({
                xaxis: {
                    categories: data.energy.timeline.labels
                }
            });
            this.energyChart.updateSeries([
                {
                    name: 'Електроенергія (кВт)',
                    data: data.energy.timeline.power
                },
                {
                    name: 'Витрата води (м³/год)',
                    data: data.energy.timeline.water
                }
            ]);
        }

        if (data.lab_quality) {
            const lq = data.lab_quality;
            const oilVal = parseFloat(lq.oil_content_pct || 49.62);
            const baseOil = parseFloat(lq.oil_content_base_pct || 48.0);
            const moistVal = parseFloat(lq.moisture_pct || 7.15);
            const impVal = parseFloat(lq.impurity_pct || 3.20);

            if (this.gaugeOilChart) {
                this.gaugeOilChart.updateSeries([oilVal]);
                this.gaugeOilChart.updateOptions({
                    fill: { colors: [oilVal >= baseOil ? '#10b981' : '#ef4444'] }
                });
            }
            if (this.gaugeMoistureChart) {
                this.gaugeMoistureChart.updateSeries([moistVal]);
                this.gaugeMoistureChart.updateOptions({
                    fill: { colors: [moistVal <= 8.0 ? '#0284c7' : '#f59e0b'] }
                });
            }
            if (this.gaugeImpurityChart) {
                this.gaugeImpurityChart.updateSeries([impVal]);
                this.gaugeImpurityChart.updateOptions({
                    fill: { colors: [impVal <= 3.0 ? '#10b981' : '#f59e0b'] }
                });
            }
        }

        if (data.contracts_summary && this.gaugeContractsChart) {
            const cs = data.contracts_summary;
            this.gaugeContractsChart.updateSeries([parseFloat(cs.fulfillment_pct || 88.0)]);
        }

        if (this.monthlyChart && data.historical_analytics && data.historical_analytics.chart_comparison) {
            const hc = data.historical_analytics.chart_comparison;
            this.monthlyChart.updateSeries([
                { name: 'Завіз насіння (т)', data: hc.seed_in },
                { name: 'Переробка сировини (т)', data: hc.seed_processed },
                { name: 'Олія (т)', data: hc.oil_out },
                { name: 'Шрот (т)', data: hc.meal_out }
            ]);
        }
    }
};
