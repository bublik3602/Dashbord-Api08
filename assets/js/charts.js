/**
 * Инициализация и управление графиками ApexCharts для дашборда завода
 */

window.FactoryCharts = {
    donutChart: null,
    energyChart: null,
    waterSteamChart: null,
    plantPowerChart: null,
    _currentPlantPowerUnit: 'kwh',
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

        this._currentDonutData = initialData || {};

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
                show: false // Відображаємо кастомну легенду
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
                enabled: true,
                custom: function ({ series, seriesIndex, dataPointIndex, w }) {
                    const label = (w.globals.labels && w.globals.labels[seriesIndex]) ? w.globals.labels[seriesIndex] : 'Продукт';
                    const val = Number(series[seriesIndex]) || 0;
                    const total = series.reduce((a, b) => a + Number(b), 0);
                    const shareOfTotal = total > 0 ? ((val / total) * 100).toFixed(1) : '0.0';

                    const donutMeta = window.FactoryCharts._currentDonutData || {};
                    const seedProcessed = Number(donutMeta.seed_processed) || 0;

                    let yieldPct = shareOfTotal;
                    if (donutMeta.percentages && donutMeta.percentages[seriesIndex] !== undefined) {
                        yieldPct = Number(donutMeta.percentages[seriesIndex]).toFixed(1);
                    } else if (seedProcessed > 0) {
                        yieldPct = ((val / seedProcessed) * 100).toFixed(1);
                    }

                    const color = (w.globals.colors && w.globals.colors[seriesIndex]) ? w.globals.colors[seriesIndex] : '#f59e0b';
                    const kgVal = Math.round(val * 1000).toLocaleString('uk-UA');
                    const tonnesVal = val.toLocaleString('uk-UA', { minimumFractionDigits: 1, maximumFractionDigits: 2 });

                    return `
                        <div class="apex-rich-tooltip donut-tooltip-box">
                            <div class="art-header" style="border-left: 4px solid ${color};">
                                <span class="art-dot" style="background: ${color};"></span>
                                <span class="art-title">${label}</span>
                            </div>
                            <div class="art-body">
                                <div class="art-metric-row highlight">
                                    <span class="art-lbl">Об'єм виходу:</span>
                                    <span class="art-val bold">${tonnesVal} т <small>(${kgVal} кг)</small></span>
                                </div>
                                <div class="art-metric-row">
                                    <span class="art-lbl">Вихід від переробки:</span>
                                    <span class="art-val bold" style="color: ${color}; font-size: 15px;">${yieldPct}%</span>
                                </div>
                                <div class="art-metric-row">
                                    <span class="art-lbl">Частка серед продуктів:</span>
                                    <span class="art-val">${shareOfTotal}%</span>
                                </div>
                                ${seedProcessed > 0 ? `
                                <div class="art-footer-calc">
                                    <span>База: ${seedProcessed.toLocaleString('uk-UA', {minimumFractionDigits: 1})} т переробки насіння</span>
                                </div>` : ''}
                            </div>
                        </div>
                    `;
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

        timelineData = timelineData || {};
        this._currentEnergyTimeline = timelineData;

        const powerName    = timelineData.power_name || 'Активна електроенергія (кВт)';
        const reactiveName = timelineData.reactive_name || 'Реактивна електроенергія (кВАр·год)';
        const powerUnit    = timelineData.power_unit || 'кВт';
        const reactiveUnit = timelineData.reactive_unit || 'кВАр·год';
        const hasReactive  = Array.isArray(timelineData.reactive) && timelineData.reactive.length > 0;

        // Серії: тільки активна та реактивна електроенергія ЖИРНИМИ лініями
        const seriesList = [
            {
                name: powerName,
                type: 'line',
                data: timelineData.power || [29400, 31200, 31800, 30950, 31210, 31930, 11450, 4820, 4410, 4650, 4520, 4032]
            }
        ];
        const colors = ['#0284c7'];
        const strokeWidths = [4.5]; // Жирна синя лінія для активної енергії

        if (hasReactive) {
            seriesList.push({
                name: reactiveName,
                type: 'line',
                data: timelineData.reactive
            });
            colors.push('#8b5cf6');
            strokeWidths.push(4.0); // Жирна фіолетова лінія для реактивної енергії
        }

        const options = {
            series: seriesList,
            chart: {
                height: 270,
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
                width: strokeWidths,
                curve: 'smooth'
            },
            markers: {
                size: 4,
                hover: {
                    sizeOffset: 3
                }
            },
            fill: {
                type: 'solid'
            },
            colors: colors,
            dataLabels: {
                enabled: false
            },
            xaxis: {
                categories: timelineData.labels || ['01.10', '02.10', '03.10', '04.10', '05.10', '06.10', '07.10', '08.10', '09.10', '10.10', '11.10', '12.10'],
                axisBorder: {
                    show: false
                },
                axisTicks: {
                    show: false
                },
                labels: {
                    style: {
                        colors: '#64748b',
                        fontSize: '11px',
                        fontWeight: 600
                    }
                }
            },
            yaxis: {
                title: {
                    text: hasReactive ? `${powerUnit} / ${reactiveUnit}` : powerUnit,
                    style: { color: '#0284c7', fontSize: '11px', fontWeight: 700 }
                },
                labels: {
                    style: { colors: '#0284c7', fontSize: '11px', fontWeight: 600 },
                    formatter: function (v) {
                        if (v === undefined || v === null || isNaN(v)) return '0';
                        return v >= 10000 ? (v / 1000).toFixed(0) + 'k' : Math.round(v).toLocaleString('uk-UA');
                    }
                }
            },
            grid: {
                borderColor: '#f1f5f9',
                strokeDashArray: 4,
            },
            legend: {
                position: 'top',
                horizontalAlign: 'right',
                fontSize: '12px',
                fontWeight: 600,
                labels: {
                    colors: '#334155'
                }
            },
            tooltip: {
                shared: true,
                intersect: false,
                custom: function ({ series, seriesIndex, dataPointIndex, w }) {
                    const tl = window.FactoryCharts._currentEnergyTimeline || {};
                    const labels = tl.labels || (w.globals && w.globals.categoryLabels) || [];
                    const pointLabel = labels[dataPointIndex] || '';

                    const pVal = Number(tl.power && tl.power[dataPointIndex] !== undefined ? tl.power[dataPointIndex] : 0) || 0;
                    const rVal = Number(tl.reactive && tl.reactive[dataPointIndex] !== undefined ? tl.reactive[dataPointIndex] : 0) || 0;

                    const pUnit = tl.power_unit || 'кВт';
                    const rUnit = tl.reactive_unit || 'кВАр·год';

                    const pSum = (tl.power || []).reduce((a, b) => a + (Number(b) || 0), 0);
                    const pShare = pSum > 0 ? ((pVal / pSum) * 100).toFixed(1) : '0.0';

                    const fmtP = pVal.toLocaleString('uk-UA', { minimumFractionDigits: 1, maximumFractionDigits: 3 });
                    const fmtR = rVal.toLocaleString('uk-UA', { minimumFractionDigits: 1, maximumFractionDigits: 2 });

                    let cosPhiStr = '';
                    if (pVal > 0 && rVal > 0) {
                        const sTotal = Math.sqrt(pVal * pVal + rVal * rVal);
                        const cosPhi = sTotal > 0 ? (pVal / sTotal).toFixed(3) : '1.000';
                        const tgPhi = (rVal / pVal).toFixed(3);
                        cosPhiStr = ` &bull; cos φ: ${cosPhi} (tg φ: ${tgPhi})`;
                    }

                    return `
                        <div class="apex-rich-tooltip bar-tooltip-box">
                            <div class="art-header bar-head">
                                <span class="art-title">⚡ Дата / період: ${pointLabel}</span>
                                <span class="art-pill" style="background: #e0f2fe; color: #0369a1;">Електроенергія</span>
                            </div>
                            <div class="art-body">
                                <div class="art-row" style="border-left: 4px solid #0284c7; background: rgba(2, 132, 199, 0.04);">
                                    <div class="art-row-main">
                                        <span class="art-lbl">⚡ <b>Активна ел-енергія:</b></span>
                                        <span class="art-val bold" style="color: #0284c7; font-size: 13px;">${fmtP} ${pUnit} (${(pVal / 1000).toFixed(3)} МВт)</span>
                                    </div>
                                    <div class="art-sub-calc">Загальне добове споживання МЕЗ &bull; Частка: ${pShare}% &bull; Питома: ~${(pVal / 596.8).toFixed(2)} кВт/т насіння</div>
                                </div>
                                ${rVal > 0 ? `
                                <div class="art-row" style="border-left: 4px solid #8b5cf6; background: rgba(139, 92, 246, 0.04);">
                                    <div class="art-row-main">
                                        <span class="art-lbl">🟣 <b>Реактивна ел-енергія:</b></span>
                                        <span class="art-val bold" style="color: #7c3aed; font-size: 13px;">${fmtR} ${rUnit}</span>
                                    </div>
                                    <div class="art-sub-calc">Реактивна складова${cosPhiStr}</div>
                                </div>` : ''}
                            </div>
                        </div>
                    `;
                }
            }
        };

        if (this.energyChart) {
            try { this.energyChart.destroy(); } catch (e) {}
            this.energyChart = null;
        }

        this.energyChart = new ApexCharts(el, options);
        this.energyChart.render();

        const hasLabels = Array.isArray(timelineData.labels) && timelineData.labels.length > 0;
        let noticeEl = document.querySelector("#energy-chart-no-data");
        if (!hasLabels) {
            if (!noticeEl) {
                noticeEl = document.createElement("div");
                noticeEl.id = "energy-chart-no-data";
                noticeEl.innerHTML = `
                    <div style="padding: 32px 20px; text-align: center; background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 8px; margin: 10px 0;">
                        <div style="font-size: 14px; font-weight: 700; color: #334155; margin-bottom: 6px;">Жовтень 2026: дані графіків відсутні (звіт енергетика та котельні очікується)</div>
                        <div style="font-size: 12px; color: #64748b; margin-bottom: 14px;">Поточний місяць триває. Фактичні добові дані споживання електроенергії, води та пари доступні за Вересень 2026.</div>
                        <button type="button" class="btn-quick-period" data-period="month_sep" style="background: #0284c7; color: #ffffff; border: none; padding: 7px 16px; border-radius: 6px; font-weight: 700; font-size: 12px; cursor: pointer; box-shadow: 0 2px 4px rgba(2,132,199,0.25);">
                            📅 Переглянути графіки за Вересень 2026 (факт)
                        </button>
                    </div>
                `;
                el.parentNode.insertBefore(noticeEl, el.nextSibling);
            }
            noticeEl.style.display = "block";
            el.style.display = "none";
        } else {
            if (noticeEl) noticeEl.style.display = "none";
            el.style.display = "block";
        }

        // Автоматична ініціалізація окремого графіка води та пари
        this.initWaterSteamChart(timelineData);
    },

    /**
     * Окремий графік обліку води та технологічної пари
     */
    initWaterSteamChart: function (timelineData) {
        const el = document.querySelector("#water-steam-timeline-chart");
        if (!el) return;

        timelineData = timelineData || this._currentEnergyTimeline || {};

        const waterName = timelineData.water_name || 'Вода за добу по лічильнику (м³)';
        const steamName = timelineData.steam_name || 'Технологічна пара за добу (т)';
        const waterUnit = timelineData.water_unit || 'м³/д';
        const steamUnit = timelineData.steam_unit || 'т/д';
        const hasSteam  = Array.isArray(timelineData.steam) && timelineData.steam.length > 0;
        const hasLabels = Array.isArray(timelineData.labels) && timelineData.labels.length > 0;

        let noticeEl = document.querySelector("#water-steam-chart-no-data");
        if (!hasLabels) {
            if (!noticeEl) {
                noticeEl = document.createElement("div");
                noticeEl.id = "water-steam-chart-no-data";
                noticeEl.innerHTML = `
                    <div style="padding: 24px 20px; text-align: center; background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 8px; margin: 10px 0;">
                        <div style="font-size: 13px; font-weight: 700; color: #334155; margin-bottom: 4px;">Дані щодо води та пари очікуються або ще не внесені</div>
                        <div style="font-size: 11px; color: #64748b;">Фактичні подобові дані обліку доступні за Вересень 2026.</div>
                    </div>
                `;
                el.parentNode.insertBefore(noticeEl, el.nextSibling);
            }
            noticeEl.style.display = "block";
            el.style.display = "none";
            return;
        } else {
            if (noticeEl) noticeEl.style.display = "none";
            el.style.display = "block";
        }

        const seriesList = [
            {
                name: waterName,
                type: 'line',
                data: timelineData.water || []
            }
        ];
        const colors = ['#10b981'];
        const strokeWidths = [3.5];

        if (hasSteam) {
            seriesList.push({
                name: steamName,
                type: 'line',
                data: timelineData.steam || []
            });
            colors.push('#f59e0b');
            strokeWidths.push(3.5);
        }

        const yaxes = [
            {
                title: {
                    text: waterUnit,
                    style: { color: '#059669', fontSize: '11px', fontWeight: 700 }
                },
                labels: {
                    style: { colors: '#059669', fontSize: '11px', fontWeight: 600 },
                    formatter: function (v) {
                        if (v === undefined || v === null || isNaN(v)) return '0';
                        return v < 20 ? Number(v).toFixed(1) : Math.round(v).toLocaleString('uk-UA');
                    }
                },
                min: 0
            }
        ];

        if (hasSteam) {
            yaxes.push({
                opposite: true,
                title: {
                    text: steamUnit,
                    style: { color: '#d97706', fontSize: '11px', fontWeight: 700 }
                },
                labels: {
                    style: { colors: '#d97706', fontSize: '11px', fontWeight: 600 },
                    formatter: function (v) {
                        if (v === undefined || v === null || isNaN(v)) return '0';
                        return Number(v).toFixed(0);
                    }
                },
                min: 0
            });
        }

        const options = {
            series: seriesList,
            chart: {
                height: 250,
                type: 'line',
                fontFamily: 'inherit',
                toolbar: { show: false },
                animations: { enabled: true, speed: 500 }
            },
            stroke: {
                width: strokeWidths,
                curve: 'smooth'
            },
            markers: {
                size: 4,
                hover: { sizeOffset: 3 }
            },
            fill: {
                type: 'solid'
            },
            colors: colors,
            grid: {
                borderColor: '#f1f5f9',
                strokeDashArray: 4
            },
            legend: {
                position: 'top',
                horizontalAlign: 'right',
                fontSize: '12px',
                fontWeight: 600,
                labels: { colors: '#334155' }
            },
            xaxis: {
                categories: timelineData.labels || [],
                axisBorder: { show: false },
                axisTicks: { show: false },
                labels: {
                    style: { colors: '#64748b', fontSize: '11px', fontWeight: 600 }
                }
            },
            yaxis: yaxes,
            tooltip: {
                shared: true,
                intersect: false,
                custom: function ({ series, seriesIndex, dataPointIndex, w }) {
                    const tl = window.FactoryCharts._currentEnergyTimeline || {};
                    const labels = tl.labels || (w.globals && w.globals.categoryLabels) || [];
                    const pointLabel = labels[dataPointIndex] || '';

                    const wVal = Number(tl.water && tl.water[dataPointIndex] !== undefined ? tl.water[dataPointIndex] : 0) || 0;
                    const sVal = Number(tl.steam && tl.steam[dataPointIndex] !== undefined ? tl.steam[dataPointIndex] : 0) || 0;

                    const wUnit = tl.water_unit || 'м³/д';
                    const sUnit = tl.steam_unit || 'т/д';

                    const fmtW = wVal.toLocaleString('uk-UA', { minimumFractionDigits: wVal < 20 ? 1 : 0, maximumFractionDigits: 2 });
                    const fmtS = sVal.toLocaleString('uk-UA', { minimumFractionDigits: 1, maximumFractionDigits: 2 });

                    return `
                        <div class="apex-rich-tooltip bar-tooltip-box">
                            <div class="art-header bar-head">
                                <span class="art-title">💧💨 Дата / період: ${pointLabel}</span>
                                <span class="art-pill" style="background: #ecfdf5; color: #047857;">Вода &bull; Пара</span>
                            </div>
                            <div class="art-body">
                                <div class="art-row" style="border-left: 4px solid #10b981; background: rgba(16, 185, 129, 0.04);">
                                    <div class="art-row-main">
                                        <span class="art-lbl">💧 <b>Вода (лічильник заводу):</b></span>
                                        <span class="art-val bold" style="color: #059669; font-size: 13px;">${fmtW} ${wUnit}</span>
                                    </div>
                                    <div class="art-sub-calc">Питома витрата: ~${(wVal / 596.8).toFixed(3)} м³/т переробленого насіння</div>
                                </div>
                                ${sVal > 0 ? `
                                <div class="art-row" style="border-left: 4px solid #f59e0b; background: rgba(245, 158, 11, 0.04);">
                                    <div class="art-row-main">
                                        <span class="art-lbl">💨 <b>Технологічна пара:</b></span>
                                        <span class="art-val bold" style="color: #d97706; font-size: 13px;">${fmtS} ${sUnit}</span>
                                    </div>
                                    <div class="art-sub-calc">Котельня на лушпинні &bull; Питома: ~${(sVal / 596.8).toFixed(3)} т/т насіння</div>
                                </div>` : ''}
                            </div>
                        </div>
                    `;
                }
            }
        };

        if (this.waterSteamChart) {
            try { this.waterSteamChart.destroy(); } catch (e) {}
            this.waterSteamChart = null;
        }

        this.waterSteamChart = new ApexCharts(el, options);
        this.waterSteamChart.render();
    },

    /**
     * Подобовий графік споживання електроенергії заводу (у кВт, МВт або середня потужність у МВт / кВт)
     */
    initPlantPowerChart: function (timelineData, unit) {
        const el = document.querySelector("#plant-daily-power-chart");
        if (!el) return;

        timelineData = timelineData || this._currentEnergyTimeline || {};
        this._currentEnergyTimeline = timelineData;
        unit = unit || this._currentPlantPowerUnit || 'kwh';
        this._currentPlantPowerUnit = unit;

        const labels = timelineData.labels || [];
        if (!labels.length) {
            el.innerHTML = '<div style="padding: 24px; text-align: center; color: #64748b; font-size: 12px; font-style: italic;">Дані подобового споживання заводу очікуються або ще не внесені</div>';
            return;
        }

        let seriesName = 'Споживання електроенергії заводу (кВт)';
        let rawPowers  = timelineData.power || [];
        let seriesData = rawPowers;
        let yaxisTitle = 'кВт';

        if (unit === 'mwh') {
            seriesName = 'Споживання електроенергії заводу (МВт)';
            seriesData = timelineData.power_mwh || rawPowers.map(v => Number((v / 1000).toFixed(2)));
            yaxisTitle = 'МВт';
        } else if (unit === 'mw') {
            seriesName = 'Середня електрична потужність заводу (МВт)';
            seriesData = timelineData.power_mw_avg || rawPowers.map(v => Number((v / 24000).toFixed(3)));
            yaxisTitle = 'Потужність (МВт)';
        }

        if (this.plantPowerChart) {
            try { this.plantPowerChart.destroy(); } catch (e) {}
            this.plantPowerChart = null;
        }

        const self = this;
        const options = {
            series: [{
                name: seriesName,
                data: seriesData
            }],
            chart: {
                type: 'bar',
                height: 250,
                fontFamily: 'inherit',
                toolbar: { show: false },
                animations: { enabled: true, speed: 450 }
            },
            plotOptions: {
                bar: {
                    borderRadius: 5,
                    columnWidth: '50%',
                    dataLabels: { position: 'top' }
                }
            },
            colors: ['#0284c7'],
            dataLabels: {
                enabled: true,
                formatter: function (val) {
                    if (unit === 'mwh') return val.toFixed(1) + ' МВт';
                    if (unit === 'mw') return val.toFixed(2) + ' МВт';
                    return val >= 1000 ? Math.round(val / 1000) + 'k' : Math.round(val);
                },
                offsetY: -18,
                style: {
                    fontSize: '11px',
                    fontWeight: 700,
                    colors: ['#0369a1']
                }
            },
            xaxis: {
                categories: labels,
                axisBorder: { show: false },
                axisTicks: { show: false },
                labels: {
                    style: { colors: '#64748b', fontSize: '11px', fontWeight: 600 }
                }
            },
            yaxis: {
                title: {
                    text: yaxisTitle,
                    style: { color: '#0284c7', fontSize: '11px', fontWeight: 700 }
                },
                labels: {
                    style: { colors: '#0284c7', fontSize: '11px', fontWeight: 600 },
                    formatter: function (v) {
                        if (v === undefined || v === null || isNaN(v)) return '0';
                        if (unit === 'mwh' || unit === 'mw') return v.toLocaleString('uk-UA');
                        return v >= 1000 ? (v / 1000).toFixed(0) + 'k' : Math.round(v).toLocaleString('uk-UA');
                    }
                }
            },
            grid: {
                borderColor: '#f1f5f9',
                strokeDashArray: 4
            },
            tooltip: {
                shared: true,
                intersect: false,
                custom: function ({ series, seriesIndex, dataPointIndex, w }) {
                    const rawKwh = Number(rawPowers[dataPointIndex] || (unit === 'mwh' ? Number(seriesData[dataPointIndex]) * 1000 : 0)) || 0;
                    const pointLabel = labels[dataPointIndex] || '';
                    const mwhVal = (rawKwh / 1000).toFixed(4);
                    const avgKw  = (rawKwh / 24).toFixed(4);
                    const avgMw  = (rawKwh / 24000).toFixed(4);
                    const specKwh = (rawKwh / 596.8).toFixed(4);
                    const fmtRawKwh = rawKwh.toLocaleString('uk-UA', { minimumFractionDigits: 4, maximumFractionDigits: 4 });

                    return `
                        <div class="apex-rich-tooltip bar-tooltip-box" style="padding: 10px 14px; min-width: 250px;">
                            <div class="art-header bar-head" style="margin-bottom: 8px;">
                                <span class="art-title" style="font-weight: 700; color: #0f172a;">⚡ Дата: ${pointLabel}</span>
                                <span class="art-pill" style="background:#e0f2fe; color:#0284c7; padding: 2px 6px; border-radius: 4px; font-size: 10px; font-weight: 700;">МЕЗ Завод</span>
                            </div>
                            <div class="art-body" style="font-size: 11.5px; line-height: 1.5;">
                                <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                                    <span style="color: #64748b;">Споживання (кВт):</span>
                                    <strong style="color: #0284c7;">${fmtRawKwh} кВт</strong>
                                </div>
                                <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                                    <span style="color: #64748b;">Споживання (МВт):</span>
                                    <strong style="color: #0369a1;">${mwhVal} МВт</strong>
                                </div>
                                <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                                    <span style="color: #64748b;">Середня потужність:</span>
                                    <strong style="color: #d97706;">${avgMw} МВт (${avgKw} кВт)</strong>
                                </div>
                                <div style="border-top: 1px dashed #cbd5e1; margin-top: 6px; padding-top: 4px; display: flex; justify-content: space-between;">
                                    <span style="color: #475569;">⚙️ Питома на 1 т насіння:</span>
                                    <strong style="color: #4f46e5;">~${specKwh} кВт/т</strong>
                                </div>
                            </div>
                        </div>
                    `;
                }
            }
        };

        this.plantPowerChart = new ApexCharts(el, options);
        this.plantPowerChart.render();
    },

    /**
     * Ініціалізація графічних радіальних діаграм (Лабораторія приходу та Лабораторія переробки)
     */
    initGauges: function (labData, contractsData, prodLabData) {
        labData = labData || {};
        contractsData = contractsData || {};
        prodLabData = prodLabData || {};

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

        // --- Блок 1: Прихід насіння (1С) ---
        // 1. Олійність приходу
        const elOil = document.querySelector("#chart-gauge-oil");
        if (elOil) {
            const oilColor = (oilVal >= baseOil) ? '#10b981' : '#ef4444';
            this.gaugeOilChart = new ApexCharts(elOil, makeGaugeOptions(oilVal, 30, 60, oilColor, 2));
            this.gaugeOilChart.render();
        }

        // 2. Вологість приходу
        const elMoist = document.querySelector("#chart-gauge-moisture");
        if (elMoist) {
            const moistColor = (moistVal <= 8.0) ? '#0284c7' : '#f59e0b';
            this.gaugeMoistureChart = new ApexCharts(elMoist, makeGaugeOptions(moistVal, 0, 14, moistColor, 2));
            this.gaugeMoistureChart.render();
        }

        // 3. Смітна домішка приходу
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

        // --- Блок 2: Подача на переробку (МЕЗ) ---
        // 1. Олійність на переробку
        const elProdOil = document.querySelector("#chart-gauge-prod-oil");
        if (elProdOil) {
            const prodOilVal = parseFloat(prodLabData.oil_content_pct !== undefined ? prodLabData.oil_content_pct : 44.03);
            const prodOilColor = '#f59e0b';
            this.gaugeProdOilChart = new ApexCharts(elProdOil, makeGaugeOptions(prodOilVal, 30, 60, prodOilColor, 2));
            this.gaugeProdOilChart.render();
        }

        // 2. Вологість у цех
        const elProdMoist = document.querySelector("#chart-gauge-prod-moisture");
        if (elProdMoist) {
            const prodMoistVal = parseFloat(prodLabData.moisture_pct !== undefined ? prodLabData.moisture_pct : 6.00);
            const prodMoistColor = (prodMoistVal <= 7.0) ? '#0284c7' : '#f59e0b';
            this.gaugeProdMoistChart = new ApexCharts(elProdMoist, makeGaugeOptions(prodMoistVal, 0, 14, prodMoistColor, 2));
            this.gaugeProdMoistChart.render();
        }

        // 3. Смітна домішка після очищення
        const elProdImp = document.querySelector("#chart-gauge-prod-impurity");
        if (elProdImp) {
            const prodImpVal = parseFloat(prodLabData.impurity_pct !== undefined ? prodLabData.impurity_pct : 2.00);
            const prodImpColor = (prodImpVal <= 2.0) ? '#10b981' : '#ef4444';
            this.gaugeProdImpChart = new ApexCharts(elProdImp, makeGaugeOptions(prodImpVal, 0, 8, prodImpColor, 2));
            this.gaugeProdImpChart.render();
        }

        // 4. Завантаження потужності переробки (%)
        const elProdSeed = document.querySelector("#chart-gauge-prod-seed");
        if (elProdSeed) {
            const prodSeedPct = parseFloat(prodLabData.plan_fulfillment_pct !== undefined ? prodLabData.plan_fulfillment_pct : 86.8);
            const prodSeedColor = '#059669';
            this.gaugeProdSeedChart = new ApexCharts(elProdSeed, makeGaugeOptions(prodSeedPct, 0, 120, prodSeedColor, 1));
            this.gaugeProdSeedChart.render();
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
                shared: true,
                intersect: false,
                custom: function ({ series, seriesIndex, dataPointIndex, w }) {
                    const categories = (w.globals && (w.globals.categoryLabels || w.globals.labels)) ? (w.globals.categoryLabels || w.globals.labels) : [];
                    const category = categories[dataPointIndex] || 'Місяць';

                    const seedIn = Number(series[0] ? series[0][dataPointIndex] : 0) || 0;
                    const seedProc = Number(series[1] ? series[1][dataPointIndex] : 0) || 0;
                    const oil = Number(series[2] ? series[2][dataPointIndex] : 0) || 0;
                    const meal = Number(series[3] ? series[3][dataPointIndex] : 0) || 0;

                    const oilYield = (seedProc > 0) ? ((oil / seedProc) * 100).toFixed(2) : '0.00';
                    const mealYield = (seedProc > 0) ? ((meal / seedProc) * 100).toFixed(2) : '0.00';
                    const oilMealSum = (seedProc > 0) ? (((oil + meal) / seedProc) * 100).toFixed(2) : '0.00';
                    const procRatio = (seedIn > 0) ? ((seedProc / seedIn) * 100).toFixed(1) : '0.0';

                    const fmt = (v) => Number(v).toLocaleString('uk-UA', { minimumFractionDigits: 1, maximumFractionDigits: 2 });
                    const kg = (v) => Math.round(Number(v) * 1000).toLocaleString('uk-UA');

                    return `
                        <div class="apex-rich-tooltip bar-tooltip-box">
                            <div class="art-header bar-head">
                                <span class="art-title">📅 ${category}</span>
                                <span class="art-pill">${seedProc > 0 ? 'Виробничий період' : 'ППР / Міжсезоння'}</span>
                            </div>
                            <div class="art-body">
                                <div class="art-row" style="border-left: 3px solid #0284c7;">
                                    <div class="art-row-main">
                                        <span class="art-lbl">🌻 Завіз насіння:</span>
                                        <span class="art-val">${fmt(seedIn)} т <small>(${kg(seedIn)} кг)</small></span>
                                    </div>
                                </div>
                                <div class="art-row" style="border-left: 3px solid #8b5cf6;">
                                    <div class="art-row-main">
                                        <span class="art-lbl">⚙️ Переробка сировини:</span>
                                        <span class="art-val">${fmt(seedProc)} т <small>(${kg(seedProc)} кг)</small></span>
                                    </div>
                                    ${seedIn > 0 ? `<div class="art-sub-calc">${procRatio}% від надходження</div>` : ''}
                                </div>
                                <div class="art-row highlight-oil" style="border-left: 3px solid #f59e0b;">
                                    <div class="art-row-main">
                                        <span class="art-lbl">💧 Вихід Олії:</span>
                                        <span class="art-val text-amber bold">${fmt(oil)} т</span>
                                    </div>
                                    <div class="art-badge-pct bg-amber">Вихід: ${oilYield}%</div>
                                </div>
                                <div class="art-row highlight-meal" style="border-left: 3px solid #10b981;">
                                    <div class="art-row-main">
                                        <span class="art-lbl">🌾 Вихід Шроту:</span>
                                        <span class="art-val text-green bold">${fmt(meal)} т</span>
                                    </div>
                                    <div class="art-badge-pct bg-green">Вихід: ${mealYield}%</div>
                                </div>
                                ${seedProc > 0 ? `
                                <div class="art-footer-summary">
                                    <span>Сума Олія + Шрот: <strong style="color: #0f172a;">${oilMealSum}%</strong> (${fmt(oil + meal)} т)</span>
                                </div>` : `
                                <div class="art-footer-summary ppr">
                                    <span>Регламентні роботи ППР заводу (переробка 0 т)</span>
                                </div>`}
                            </div>
                        </div>
                    `;
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
            this._currentDonutData = data.material_balance.yield_chart;
            this.donutChart.updateSeries(data.material_balance.yield_chart.values);
        }

        if (this.energyChart && data.energy && data.energy.timeline) {
            const tl = data.energy.timeline;
            this._currentEnergyTimeline = tl;
            const pName = tl.power_name || 'Активна електроенергія (кВт)';
            const rName = tl.reactive_name || 'Реактивна електроенергія (кВАр·год)';
            const wName = tl.water_name || 'Вода за добу по лічильнику (м³)';
            const sName = tl.steam_name || 'Технологічна пара за добу (т)';
            const pUnit = tl.power_unit || 'кВт';
            const rUnit = tl.reactive_unit || 'кВАр·год';
            const wUnit = tl.water_unit || 'м³/д';
            const sUnit = tl.steam_unit || 'т/д';
            const hasReactive = Array.isArray(tl.reactive) && tl.reactive.length > 0;
            const hasSteam = Array.isArray(tl.steam) && tl.steam.length > 0;
            const hasLabels = Array.isArray(tl.labels) && tl.labels.length > 0;

            const chartEl = document.querySelector("#energy-timeline-chart");
            let noticeEl = document.querySelector("#energy-chart-no-data");

            if (!hasLabels) {
                if (!noticeEl && chartEl) {
                    noticeEl = document.createElement("div");
                    noticeEl.id = "energy-chart-no-data";
                    noticeEl.innerHTML = `
                        <div style="padding: 32px 20px; text-align: center; background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 8px; margin: 10px 0;">
                            <div style="font-size: 14px; font-weight: 700; color: #334155; margin-bottom: 6px;">Жовтень 2026: дані графіків відсутні (звіт енергетика та котельні очікується)</div>
                            <div style="font-size: 12px; color: #64748b; margin-bottom: 14px;">Поточний місяць триває. Фактичні добові дані споживання електроенергії, води та пари доступні за Вересень 2026.</div>
                            <button type="button" class="btn-quick-period" data-period="month_sep" style="background: #0284c7; color: #ffffff; border: none; padding: 7px 16px; border-radius: 6px; font-weight: 700; font-size: 12px; cursor: pointer; box-shadow: 0 2px 4px rgba(2,132,199,0.25);">
                                📅 Переглянути графіки за Вересень 2026 (факт)
                            </button>
                        </div>
                    `;
                    chartEl.parentNode.insertBefore(noticeEl, chartEl.nextSibling);
                }
                if (noticeEl) noticeEl.style.display = "block";
                if (chartEl) chartEl.style.display = "none";
            } else {
                if (noticeEl) noticeEl.style.display = "none";
                if (chartEl) chartEl.style.display = "block";

                const seriesList = [
                    {
                        name: pName,
                        type: 'line',
                        data: tl.power || []
                    }
                ];
                const colors = ['#0284c7'];
                const strokeWidths = [4.5]; // Жирна лінія для активної енергії

                if (hasReactive) {
                    seriesList.push({
                        name: rName,
                        type: 'line',
                        data: tl.reactive || []
                    });
                    colors.push('#8b5cf6');
                    strokeWidths.push(4.0); // Жирна лінія для реактивної енергії
                }

                this.energyChart.updateOptions({
                    colors: colors,
                    stroke: { width: strokeWidths, curve: 'smooth' },
                    fill: { type: 'solid' },
                    markers: {
                        size: 4,
                        hover: { sizeOffset: 3 }
                    },
                    xaxis: {
                        categories: tl.labels || []
                    },
                    yaxis: {
                        title: {
                            text: hasReactive ? `${pUnit} / ${rUnit}` : pUnit,
                            style: { color: '#0284c7', fontSize: '11px', fontWeight: 700 }
                        },
                        labels: {
                            style: { colors: '#0284c7', fontSize: '11px', fontWeight: 600 },
                            formatter: function (v) {
                                if (v === undefined || v === null || isNaN(v)) return '0';
                                return v >= 10000 ? (v / 1000).toFixed(0) + 'k' : Math.round(v).toLocaleString('uk-UA');
                            }
                        }
                    },
                    series: seriesList
                }, true, true);
            }

            // Оновлення окремого графіка води та пари
            this.initWaterSteamChart(tl);

            if (tl) {
                this.initPlantPowerChart(tl, this._currentPlantPowerUnit || 'kwh');
            }
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

        if (data.production_lab_quality) {
            const plq = data.production_lab_quality;
            const prodOilVal = parseFloat(plq.oil_content_pct !== undefined ? plq.oil_content_pct : 44.49);
            const prodBaseOil = parseFloat(plq.oil_content_base_pct || 48.0);
            const prodMoistVal = parseFloat(plq.moisture_pct !== undefined ? plq.moisture_pct : 6.00);
            const prodImpVal = parseFloat(plq.impurity_pct !== undefined ? plq.impurity_pct : 2.00);
            const prodSeedPct = parseFloat(plq.plan_fulfillment_pct !== undefined ? plq.plan_fulfillment_pct : 86.8);

            if (this.gaugeProdOilChart) {
                this.gaugeProdOilChart.updateSeries([prodOilVal]);
                this.gaugeProdOilChart.updateOptions({
                    fill: { colors: ['#f59e0b'] }
                });
            }
            if (this.gaugeProdMoistChart) {
                this.gaugeProdMoistChart.updateSeries([prodMoistVal]);
                this.gaugeProdMoistChart.updateOptions({
                    fill: { colors: [prodMoistVal <= 7.0 ? '#0284c7' : '#f59e0b'] }
                });
            }
            if (this.gaugeProdImpChart) {
                this.gaugeProdImpChart.updateSeries([prodImpVal]);
                this.gaugeProdImpChart.updateOptions({
                    fill: { colors: [prodImpVal <= 2.0 ? '#10b981' : '#ef4444'] }
                });
            }
            if (this.gaugeProdSeedChart) {
                this.gaugeProdSeedChart.updateSeries([prodSeedPct]);
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
