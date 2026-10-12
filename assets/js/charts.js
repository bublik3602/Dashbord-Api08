/**
 * Инициализация и управление графиками ApexCharts для дашборда завода
 */

window.FactoryCharts = {
    donutChart: null,
    energyChart: null,

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
            labels: initialData.labels || ['Масло', 'Шрот', 'Лузга', 'Потери'],
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
                show: false // Отображаем кастомную детальную легенду с тоннами и процентами
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
                                label: 'Продукция',
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
     * Инициализация графика энергопотребления и расхода воды
     */
    initEnergyChart: function (timelineData) {
        const el = document.querySelector("#energy-timeline-chart");
        if (!el) return;

        const options = {
            series: [
                {
                    name: 'Электроэнергия (кВт)',
                    type: 'area',
                    data: timelineData.power || [3420, 3510, 3580, 3620, 3590, 3480, 3410, 3650, 3820, 3890, 3850, 3940]
                },
                {
                    name: 'Расход воды (м³/ч)',
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
                        text: 'м³/ч',
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
     * Обновление данных графиков
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
                    name: 'Электроэнергия (кВт)',
                    data: data.energy.timeline.power
                },
                {
                    name: 'Расход воды (м³/ч)',
                    data: data.energy.timeline.water
                }
            ]);
        }
    }
};
