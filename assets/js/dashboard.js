/**
 * Основная логика дашборда: работа с API, переключение периодов, таймер и обновление DOM
 */

document.addEventListener('DOMContentLoaded', function () {
    let currentPeriod = 'day';
    let currentCargo = 'Всі';
    let refreshSecondsLeft = 30;
    let refreshTimerInterval = null;
    let isFetching = false;
    let cachedLogisticsData = window.INITIAL_DASHBOARD_DATA ? window.INITIAL_DASHBOARD_DATA.logistics : null;

    const timerDisplay = document.getElementById('refresh-timer-count');
    const btnRefresh = document.getElementById('btn-manual-refresh');
    const periodButtons = document.querySelectorAll('.period-btn');
    const cargoButtons = document.querySelectorAll('.cargo-tab-btn');

    // 1. Ініціалізація графіків при першому завантаженні сторінки
    if (window.INITIAL_DASHBOARD_DATA) {
        FactoryCharts.initDonutChart(window.INITIAL_DASHBOARD_DATA.material_balance.yield_chart);
        FactoryCharts.initEnergyChart(window.INITIAL_DASHBOARD_DATA.energy.timeline);
        FactoryCharts.initGauges(window.INITIAL_DASHBOARD_DATA.lab_quality, window.INITIAL_DASHBOARD_DATA.contracts_summary);
        FactoryCharts.initMonthlyAnalyticsChart(window.INITIAL_DASHBOARD_DATA.historical_analytics);
    }

    function updateExportLink() {
        const btnExport = document.getElementById('btn-export-excel');
        if (btnExport) {
            btnExport.href = `api/export.php?period=${currentPeriod}`;
        }
    }

    // 2. Обробники кліку на фільтр періоду
    periodButtons.forEach(btn => {
        btn.addEventListener('click', function () {
            const period = this.getAttribute('data-period');
            if (period === currentPeriod || isFetching) return;

            periodButtons.forEach(b => b.classList.remove('active'));
            this.classList.add('active');

            const monthDropdown = document.getElementById('select-other-months');
            if (monthDropdown) monthDropdown.value = '';

            currentPeriod = period;
            updateExportLink();
            fetchDashboardData(true);
        });
    });

    // 2.0 Обробник вибору місяця/періоду з випадаючого списку
    const monthDropdown = document.getElementById('select-other-months');
    if (monthDropdown) {
        monthDropdown.addEventListener('change', function () {
            const period = this.value;
            if (!period || isFetching) return;

            periodButtons.forEach(b => b.classList.remove('active'));
            currentPeriod = period;
            updateExportLink();
            fetchDashboardData(true);
        });
    }

    // 2.01 Обробники швидкого переходу на місяць з карток аналітики
    document.querySelectorAll('.btn-quick-period, .history-month-card').forEach(elem => {
        elem.addEventListener('click', function (e) {
            const p = this.getAttribute('data-period') || this.getAttribute('data-period-trigger');
            if (!p) return;
            const targetBtn = document.querySelector(`.period-btn[data-period="${p}"]`);
            if (targetBtn) {
                targetBtn.click();
            } else if (monthDropdown) {
                monthDropdown.value = p;
                monthDropdown.dispatchEvent(new Event('change'));
            }
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    });

    // 2.1 Обробники перемикання вкладок вантажу (Всі, Насіння, Олія, Шрот, Лузга)
    cargoButtons.forEach(btn => {
        btn.addEventListener('click', function () {
            const cargo = this.getAttribute('data-cargo');
            if (cargo === currentCargo) return;

            cargoButtons.forEach(b => b.classList.remove('active'));
            this.classList.add('active');

            currentCargo = cargo;
            renderLogisticsByCargo(currentCargo);
        });
    });

    // 3. Ручне оновлення даних
    if (btnRefresh) {
        btnRefresh.addEventListener('click', function () {
            if (isFetching) return;
            fetchDashboardData(true);
        });
    }

    // 4. Таймер автооновлення
    function startRefreshTimer() {
        if (refreshTimerInterval) clearInterval(refreshTimerInterval);
        refreshSecondsLeft = 30;
        updateTimerDisplay();

        refreshTimerInterval = setInterval(() => {
            refreshSecondsLeft--;
            updateTimerDisplay();

            if (refreshSecondsLeft <= 0) {
                fetchDashboardData(false);
                refreshSecondsLeft = 30;
            }
        }, 1000);
    }

    function updateTimerDisplay() {
        if (timerDisplay) {
            timerDisplay.textContent = `${refreshSecondsLeft}с`;
        }
    }

    // 5. Запит до REST API
    async function fetchDashboardData(showFeedback = false) {
        if (isFetching) return;
        isFetching = true;

        if (showFeedback && btnRefresh) {
            btnRefresh.classList.add('opacity-50');
            const icon = btnRefresh.querySelector('.refresh-icon');
            if (icon) icon.style.transform = 'rotate(180deg)';
        }

        try {
            const res = await fetch(`api/metrics.php?period=${currentPeriod}&_t=${Date.now()}`);
            if (!res.ok) throw new Error(`HTTP error ${res.status}`);

            const json = await res.json();
            if (json.status === 'success' && json.data) {
                updateDashboardUI(json.data);
                if (showFeedback) {
                    showToast('Дані дашборда оновлено');
                }
            } else {
                throw new Error(json.message || 'Помилка відповіді сервера');
            }
        } catch (err) {
            console.error('Помилка оновлення даних:', err);
            showToast('Збій оновлення даних: ' + err.message, true);
        } finally {
            isFetching = false;
            refreshSecondsLeft = 30;
            if (btnRefresh) {
                btnRefresh.classList.remove('opacity-50');
                const icon = btnRefresh.querySelector('.refresh-icon');
                if (icon) icon.style.transform = '';
            }
        }
    }

    // 6. Оновлення значень в інтерфейсі
    function updateDashboardUI(data) {
        const mb = data.material_balance;
        const en = data.energy;
        const log = data.logistics;
        const eq = data.equipment;

        // --- Часовий банер та період моніторингу ---
        if (data.period_info) {
            const pi = data.period_info;
            setElementText('#banner-period-title', pi.range_display || pi.period_label);
            setElementText('#lbl-prod-period', 'Період: ' + (pi.period_label || pi.range_display));
            setElementText('#banner-sync-time', 'Оновлено: ' + (pi.generated_at || new Date().toLocaleTimeString()));

            const subElem = document.querySelector('#banner-period-sub');
            if (subElem && pi.shift_name) {
                subElem.innerHTML = `Розрахунок доби: <strong>з 08:00 до 08:00</strong> наступного дня &bull; Поточна: <strong>${escapeHtml(pi.shift_name)}</strong>`;
            }
        }

        if (data.lab_quality && data.lab_quality.report_date) {
            setElementText('#lbl-lab-time', 'Звіт 1С станом на: ' + data.lab_quality.report_date);
            setElementText('#banner-lab-time', data.lab_quality.report_date);
        }

        if (data.warehouse_stocks && data.warehouse_stocks.report_date) {
            const stocksLbl = document.querySelector('#lbl-stocks-time');
            if (stocksLbl) stocksLbl.innerHTML = `Станом на: <strong>${escapeHtml(data.warehouse_stocks.report_date)}</strong> (СВОД)`;
            setElementText('#banner-stocks-time', data.warehouse_stocks.report_date);
        }

        if (data.logistics && data.logistics.operational_day) {
            setElementText('#lbl-logistics-time', 'Доба: ' + data.logistics.operational_day + ' (08:00 — 08:00)');
            setElementText('#val-operational-day-text', data.logistics.operational_day);
        }

        // --- Блок А: Зона приймання та склад насіння ---
        const rz = mb.receiving_zone;
        setElementText('#val-seed-in-plant', formatNumber(rz.seed_in_plant) + ' т');
        setElementText('#val-seed-plan', 'План: ' + formatNumber(rz.seed_plan) + ' т (' + rz.plan_fulfillment_pct + '%)');
        
        if (data.warehouse_stocks) {
            const ws = data.warehouse_stocks;
            setElementText('#val-seed-stock', formatNumber(ws.seed_stock_tonnes) + ' т');
            setElementText('#badge-days-reserve', (ws.days_seed_reserve || 12.1) + ' дн. запасу');
            setElementText('#val-seed-stock-sub', `Облік: ${formatNumber(ws.seed_stock_accounting_tonnes)} т • Потреба: 600 т/д`);

            // Залишки готової продукції на складах та відвантаження за обраний період
            setElementText('#val-stock-oil', formatNumber(ws.oil_stock_tonnes) + ' т');
            const shippedPrefix = (currentPeriod === 'week') 
                ? 'Відвантажено за тиждень: ' 
                : ((currentPeriod.indexOf('month') !== -1) 
                    ? 'Відвантажено за місяць: ' 
                    : (currentPeriod === 'season' ? 'Відвантажено за сезон: ' : 'Відвантажено за добу: '));

            setElementText('#val-shipped-oil', `${shippedPrefix}${formatNumber(ws.oil_shipped_tonnes)} т`);
            setElementText('#val-stock-meal', formatNumber(ws.meal_stock_tonnes) + ' т');
            setElementText('#val-shipped-meal', `${shippedPrefix}${formatNumber(ws.meal_shipped_tonnes)} т`);
            setElementText('#val-stock-husk', formatNumber(ws.husk_stock_tonnes) + ' т');
            setElementText('#val-shipped-husk', `${shippedPrefix}${formatNumber(ws.husk_shipped_tonnes)} т`);
            setElementText('#val-boiler-husk', formatNumber(ws.husk_boiler_spent_tonnes) + ' т');

            // Оновлення прогрес-барів залишків
            const fillOil = document.querySelector('.wh-fill-oil');
            if (fillOil) fillOil.style.width = Math.min(100, Math.round((ws.oil_stock_tonnes / 5000) * 100)) + '%';
            const fillMeal = document.querySelector('.wh-fill-meal');
            if (fillMeal) fillMeal.style.width = Math.min(100, Math.round((ws.meal_stock_tonnes / 1500) * 100)) + '%';
            const fillHusk = document.querySelector('.wh-fill-husk');
            if (fillHusk) fillHusk.style.width = Math.min(100, Math.round((ws.husk_stock_tonnes / 500) * 100)) + '%';
        }

        const condLossBadge = document.querySelector('#badge-cond-loss');
        if (condLossBadge) {
            condLossBadge.textContent = 'Підробка: ' + rz.conditioning_loss_pct + '%';
            condLossBadge.className = 'kpi-badge ' + rz.status_class;
        }
        setElementText('#val-cond-loss', 'Усушка/сміття: ' + formatNumber(rz.conditioning_loss) + ' т');

        // --- Блок А: Лабораторія якості та виконання контрактів ---
        if (data.lab_quality) {
            const lq = data.lab_quality;
            setElementText('#val-lab-oil', formatNumber(lq.oil_content_pct, 2) + '%');
            const devSign = lq.oil_deviation_pct >= 0 ? '+' : '';
            const devLabel = lq.oil_deviation_pct >= 0 ? '(Бонус)' : '(Дисконт)';
            setElementText('#badge-lab-oil-dev', `${devSign}${formatNumber(lq.oil_deviation_pct, 2)}% ${devLabel}`);
            
            const oilDevBadge = document.querySelector('#badge-lab-oil-dev');
            if (oilDevBadge) {
                oilDevBadge.className = 'lab-badge ' + (lq.oil_deviation_pct >= 0 ? 'badge-bonus' : 'badge-discount');
            }
            setElementText('#val-lab-oil-sub', `Базова норма: ${formatNumber(lq.oil_content_base_pct, 1)}% (Лабораторія)`);

            setElementText('#val-lab-moisture', formatNumber(lq.moisture_pct, 2) + '%');
            const moistBadge = document.querySelector('#badge-lab-moisture');
            if (moistBadge) {
                if (lq.moisture_pct <= 8.0) {
                    moistBadge.textContent = 'В нормі (< 8%)';
                    moistBadge.className = 'lab-badge badge-bonus';
                } else {
                    moistBadge.textContent = 'Перевищення (> 8%)';
                    moistBadge.className = 'lab-badge badge-discount';
                }
            }

            setElementText('#val-lab-impurity', formatNumber(lq.impurity_pct, 2) + '%');
            setElementText('#badge-lab-impurity', `Знижка: ${formatNumber(lq.weight_discount_tonnes, 1)} т`);
        }

        if (data.contracts_summary) {
            const cs = data.contracts_summary;
            setElementText('#val-contracts-delivered', `${formatNumber(cs.delivered_tonnes, 0)} т / ${formatNumber(cs.contracted_tonnes, 0)} т`);
            setElementText('#badge-contracts-pct', `${formatNumber(cs.fulfillment_pct, 1)}%`);
            setElementText('#val-contracts-sub', `Залишок: ${formatNumber(cs.remaining_tonnes, 0)} т (${cs.active_specs_count || 14} у роботі, ${cs.pending_specs_count || 3} очікують)`);
        }

        // --- Блок А: Зона виробництва ---
        const pz = mb.production_zone;
        setElementText('#val-seed-in-prod', formatNumber(pz.seed_processed) + ' т');
        setElementText('#val-prod-loss', formatNumber(pz.production_loss) + ' т');

        // Великі цифри виходу продукції у відсотках (Олія + Шрот + Лузга)
        const oilPctVal = (pz.oil_yield_pct !== undefined) ? Number(pz.oil_yield_pct) : Number(mb.yield_chart.percentages[0] || 0.0);
        const mealPctVal = (pz.meal_yield_pct !== undefined) ? Number(pz.meal_yield_pct) : Number(mb.yield_chart.percentages[1] || 0.0);
        const huskPctVal = (pz.husk_yield_pct !== undefined) ? Number(pz.husk_yield_pct) : Number(mb.yield_chart.percentages[2] || 0.0);
        const totalPctVal = (pz.total_yield_pct !== undefined) ? Number(pz.total_yield_pct) : (Math.round((oilPctVal + mealPctVal + huskPctVal) * 100) / 100);
        const surplusPctVal = (pz.yield_surplus_pct !== undefined) ? Number(pz.yield_surplus_pct) : (totalPctVal > 100 ? Math.round((totalPctVal - 100) * 100) / 100 : 0.0);
        const surplusTonnesVal = (pz.yield_surplus_tonnes !== undefined) ? Number(pz.yield_surplus_tonnes) : ((Number(pz.products_total) > Number(pz.seed_processed) && Number(pz.seed_processed) > 0) ? Math.round((Number(pz.products_total) - Number(pz.seed_processed)) * 100) / 100 : 0.0);

        const prodLossBadge = document.querySelector('#badge-prod-loss');
        if (prodLossBadge) {
            if (surplusPctVal > 0) {
                prodLossBadge.textContent = `+${surplusPctVal}% вихід`;
                prodLossBadge.className = 'kpi-badge status-normal';
            } else {
                prodLossBadge.textContent = pz.production_loss_pct + '%';
                prodLossBadge.className = 'kpi-badge ' + pz.status_class;
            }
        }

        setElementText('#big-yield-oil', oilPctVal + '%');
        setElementText('#sub-yield-oil', formatNumber(pz.oil_out) + ' т');

        setElementText('#big-yield-meal', mealPctVal + '%');
        setElementText('#sub-yield-meal', formatNumber(pz.meal_out) + ' т');

        setElementText('#big-yield-husk', huskPctVal + '%');
        setElementText('#sub-yield-husk', formatNumber(pz.husk_out) + ' т');

        const bigYieldTotalEl = document.querySelector('#big-yield-total');
        if (bigYieldTotalEl) {
            if (surplusPctVal > 0) {
                bigYieldTotalEl.innerHTML = `${totalPctVal}% <span class="yield-plus-badge">+${surplusPctVal}%</span>`;
            } else {
                bigYieldTotalEl.textContent = totalPctVal + '%';
            }
        }
        setElementText('#sub-yield-total', formatNumber(pz.products_total) + ' т' + (surplusTonnesVal > 0 ? ` (+${formatNumber(surplusTonnesVal)} т)` : ''));

        // Легенда виходу продукції
        setElementText('#legend-oil-val', formatNumber(pz.oil_out) + ' т');
        setElementText('#legend-oil-pct', oilPctVal + '%');

        setElementText('#legend-meal-val', formatNumber(pz.meal_out) + ' т');
        setElementText('#legend-meal-pct', mealPctVal + '%');

        setElementText('#legend-husk-val', formatNumber(pz.husk_out) + ' т');
        setElementText('#legend-husk-pct', huskPctVal + '%');

        const lossDotEl = document.querySelector('#legend-loss-dot');
        if (surplusPctVal > 0) {
            if (lossDotEl) lossDotEl.style.background = '#10b981';
            setElementText('#legend-loss-label', 'Перевищення виходу (баланс +)');
            setElementText('#legend-loss-val', '+' + formatNumber(surplusTonnesVal) + ' т');
            setElementText('#legend-loss-pct', '+' + surplusPctVal + '%');
        } else {
            if (lossDotEl) lossDotEl.style.background = 'var(--color-loss)';
            setElementText('#legend-loss-label', 'Технологічні втрати');
            setElementText('#legend-loss-val', formatNumber(pz.production_loss) + ' т');
            setElementText('#legend-loss-pct', mb.yield_chart.percentages[3] + '%');
        }

        // --- Блок Б: Логістика та воронка (Насіння, Олія, Шрот, Лузга) ---
        cachedLogisticsData = log;
        if (log && log.categories) {
            const cats = log.categories;
            const updateCargoStayBar = (barId, minutes) => {
                const bar = document.querySelector(barId);
                if (bar && minutes !== undefined) {
                    const pct = Math.min(100, Math.round((minutes / 120) * 100));
                    bar.style.width = pct + '%';
                    bar.className = 'cargo-meter-fill ' + (minutes > 120 ? 'meter-warn' : 'meter-good');
                }
            };

            const allCat  = cats['Всі'] || cats['Все'] || log;
            const seedCat = cats['Насіння'] || cats['Семена'];
            const oilCat  = cats['Олія'] || cats['Масло'];
            const mealCat = cats['Шрот'];
            const huskCat = cats['Лузга'];

            if (seedCat) {
                setElementText('#val-stay-seed', seedCat.avg_stay_text);
                const cnt = seedCat.on_site !== undefined ? seedCat.on_site : (seedCat.total_active || 0);
                const ex  = seedCat.exited !== undefined ? seedCat.exited : 0;
                setElementText('#val-cnt-seed', `На заводі: ${cnt} • Виїхало: ${ex}`);
                setElementText('#val-exited-seed', ex);
                updateCargoStayBar('#bar-stay-seed', seedCat.avg_stay_minutes);
            }
            if (oilCat) {
                setElementText('#val-stay-oil', oilCat.avg_stay_text);
                const cnt = oilCat.on_site !== undefined ? oilCat.on_site : (oilCat.total_active || 0);
                const ex  = oilCat.exited !== undefined ? oilCat.exited : 0;
                setElementText('#val-cnt-oil', `На заводі: ${cnt} • Виїхало: ${ex}`);
                setElementText('#val-exited-oil', ex);
                updateCargoStayBar('#bar-stay-oil', oilCat.avg_stay_minutes);
            }
            if (mealCat) {
                setElementText('#val-stay-meal', mealCat.avg_stay_text);
                const cnt = mealCat.on_site !== undefined ? mealCat.on_site : (mealCat.total_active || 0);
                const ex  = mealCat.exited !== undefined ? mealCat.exited : 0;
                setElementText('#val-cnt-meal', `На заводі: ${cnt} • Виїхало: ${ex}`);
                setElementText('#val-exited-meal', ex);
                updateCargoStayBar('#bar-stay-meal', mealCat.avg_stay_minutes);
            }
            if (huskCat) {
                setElementText('#val-stay-husk', huskCat.avg_stay_text);
                const cnt = huskCat.on_site !== undefined ? huskCat.on_site : (huskCat.total_active || 0);
                const ex  = huskCat.exited !== undefined ? huskCat.exited : 0;
                setElementText('#val-cnt-husk', `На заводі: ${cnt} • Виїхало: ${ex}`);
                setElementText('#val-exited-husk', ex);
                updateCargoStayBar('#bar-stay-husk', huskCat.avg_stay_minutes);
            }

            const totalExited = (allCat && allCat.exited !== undefined) ? allCat.exited : (log.exited || 0);
            setElementText('#val-exited-total-inline', `${totalExited} машин`);
        }
        renderLogisticsByCargo(currentCargo);

        // --- Блок В: Енергетика та водопостачання ---
        if (en) {
            if (en.period_note) {
                setElementText('#lbl-energy-period', en.period_note);
            }
            setElementText('#val-total-kwh', formatNumber(en.total_kwh, 0) + ' кВт·год');
            setElementText('#val-current-power', `Потужність: ${formatNumber(en.current_power_kw, 1)} кВт • Реакт.: ${formatNumber(en.reactive_kvarh || 0, 0)} кВАр·год`);

            setElementText('#val-water-total', formatNumber(en.total_water_m3, 1) + ' м³');
            setElementText('#val-water-flow', `Потік: ${formatNumber(en.current_water_m3_hour, 2)} м³/год (${formatNumber(en.daily_water_m3 || en.total_water_m3, 1)} м³/добу)`);

            setElementText('#val-specific-kwh', formatNumber(en.specific_kwh_per_tonne, 2) + ' кВт·год/т');
            if (en.specific_subtext) {
                setElementText('#val-specific-sub', en.specific_subtext);
            }

            setElementText('#val-sewage-total', `Стоки: ${formatNumber(en.sewage_m3 || 0, 1)} м³`);
            setElementText('#val-boiler-water', `Котельня: ${formatNumber(en.boiler_water_m3 || 0, 0)} м³ • Екстракція: ${formatNumber(en.extraction_water_m3 || 0, 0)} м³`);

            const deptTbody = document.querySelector('#energy-departments-tbody');
            if (deptTbody && Array.isArray(en.departments)) {
                deptTbody.innerHTML = en.departments.map(d => `
                    <tr>
                        <td class="bold">${d.name}</td>
                        <td class="bold" style="color: #0284c7;">${formatNumber(d.active_kwh, 0)}</td>
                        <td>${formatNumber(d.reactive_kvarh, 1)}</td>
                        <td>${Number(d.specific_kwh) > 0 ? formatNumber(d.specific_kwh, 2) : '—'}</td>
                        <td class="bold" style="color: #059669;">${formatNumber(d.water_m3, 0)}</td>
                        <td>${formatNumber(d.sewage_m3, 0)}</td>
                    </tr>
                `).join('');
            }
        }

        // --- Блок В: Статуси обладнання ---
        if (eq && eq.nodes) {
            setElementText('#equip-health-summary', `${eq.running_count}/${eq.total_count} вузлів у роботі`);
        }

        // Оновлення графіків
        FactoryCharts.updateCharts(data);

        // Попередження про втрати
        if (rz.status_class === 'status-danger') {
            showToast(`Увага! Втрати підробки перевищили норму: ${rz.conditioning_loss_pct}%`, true);
        } else if (pz.status_class === 'status-danger') {
            showToast(`Увага! Виробничі втрати перевищили норму: ${pz.production_loss_pct}%`, true);
        }
    }

    // Відмальовка воронки та показників для вибраної культури (Всі, Насіння, Олія, Шрот, Лузга)
    function renderLogisticsByCargo(cargoKey) {
        if (!cachedLogisticsData || !cachedLogisticsData.categories) return;
        const cats = cachedLogisticsData.categories;
        const catData = cats[cargoKey] || 
                        (cargoKey === 'Всі' || cargoKey === 'Все' ? (cats['Всі'] || cats['Все']) : null) || 
                        cats['Всі'] || 
                        cats['Все'] || 
                        cachedLogisticsData;

        // 1. У черзі
        setElementText('#funnel-queued-count', catData.queued !== undefined ? catData.queued : 0);
        // 2. На заводі
        const onSiteCount = catData.on_site !== undefined ? catData.on_site : (catData.entered !== undefined ? catData.entered : 0);
        setElementText('#funnel-entered-count', onSiteCount);
        // 3. Виїхало за добу 08:00 - 08:00
        setElementText('#funnel-exited-count', catData.exited !== undefined ? catData.exited : 0);

        setElementText('#active-cargo-label', cargoKey);
        setElementText('#active-cargo-count-label', cargoKey);
        setElementText('#val-avg-stay-time', catData.avg_stay_text || formatMinutes(catData.avg_stay_minutes || 0));
        setElementText('#val-on-territory', 'На території: ' + onSiteCount);
    }

    // Допоміжні функції
    function setElementText(selector, text) {
        const el = document.querySelector(selector);
        if (el) el.textContent = text;
    }

    function formatNumber(num, decimals = 1) {
        if (num === undefined || num === null) return '0';
        return parseFloat(num).toLocaleString('uk-UA', {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals
        });
    }

    function formatMinutes(mins) {
        if (!mins || mins <= 0) return '0 хв';
        const h = Math.floor(mins / 60);
        const m = mins % 60;
        if (h > 0 && m > 0) return `${h} год ${m} хв`;
        if (h > 0) return `${h} год`;
        return `${m} хв`;
    }

    function showToast(message, isError = false) {
        const container = document.getElementById('toast-container');
        if (!container) return;

        const toast = document.createElement('div');
        toast.className = 'toast' + (isError ? ' bg-red-600' : '');
        toast.textContent = message;
        container.appendChild(toast);

        setTimeout(() => toast.classList.add('show'), 10);
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, 3500);
    }

    // =============================================================
    // 7. МОДАЛЬНЕ ВІКНО ІМПОРТУ ЗВІТІВ ТА ЕКСПОРТ В EXCEL
    // =============================================================
    const btnOpenImport     = document.getElementById('btn-open-import');
    const importModal       = document.getElementById('import-modal');
    const btnCloseImport    = document.getElementById('btn-close-import');
    const btnCancelModal    = document.getElementById('btn-cancel-modal');
    const dropzone          = document.getElementById('upload-dropzone');
    const fileInput         = document.getElementById('file-input-modal');
    const btnBrowse         = document.getElementById('btn-browse-files');
    const selectedFilesList = document.getElementById('selected-files-list');
    const filesPillsWrap    = document.getElementById('files-pills-wrap');
    const btnClearFiles     = document.getElementById('btn-clear-files');
    const btnSubmitImport   = document.getElementById('btn-submit-import');
    const importResultBox   = document.getElementById('import-result-box');
    const btnExportExcel    = document.getElementById('btn-export-excel');

    let pendingFiles = [];

    // Оновлення посилання на експорт при зміні періоду
    function updateExportLink() {
        if (btnExportExcel) {
            btnExportExcel.href = `api/export.php?period=${currentPeriod}`;
        }
    }
    updateExportLink();

    if (btnOpenImport && importModal) {
        btnOpenImport.addEventListener('click', () => {
            importModal.style.display = 'flex';
            if (window.lucide) lucide.createIcons();
        });

        const closeModal = () => {
            importModal.style.display = 'none';
            resetUploadState();
        };

        if (btnCloseImport) btnCloseImport.addEventListener('click', closeModal);
        if (btnCancelModal) btnCancelModal.addEventListener('click', closeModal);

        importModal.addEventListener('click', (e) => {
            if (e.target === importModal) closeModal();
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && importModal.style.display === 'flex') {
                closeModal();
            }
        });
    }

    if (dropzone && fileInput) {
        if (btnBrowse) {
            btnBrowse.addEventListener('click', (e) => {
                e.stopPropagation();
                fileInput.click();
            });
        }

        dropzone.addEventListener('click', () => {
            fileInput.click();
        });

        fileInput.addEventListener('change', (e) => {
            if (e.target.files && e.target.files.length > 0) {
                addFilesToPending(Array.from(e.target.files));
            }
        });

        ['dragenter', 'dragover'].forEach(eventName => {
            dropzone.addEventListener(eventName, (e) => {
                e.preventDefault();
                e.stopPropagation();
                dropzone.classList.add('dragover');
            });
        });

        ['dragleave', 'drop'].forEach(eventName => {
            dropzone.addEventListener(eventName, (e) => {
                e.preventDefault();
                e.stopPropagation();
                dropzone.classList.remove('dragover');
            });
        });

        dropzone.addEventListener('drop', (e) => {
            if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length > 0) {
                addFilesToPending(Array.from(e.dataTransfer.files));
            }
        });
    }

    function addFilesToPending(newFiles) {
        newFiles.forEach(f => {
            if (!pendingFiles.some(existing => existing.name === f.name && existing.size === f.size)) {
                pendingFiles.push(f);
            }
        });
        renderSelectedFiles();
    }

    function renderSelectedFiles() {
        if (!selectedFilesList || !filesPillsWrap) return;

        if (pendingFiles.length === 0) {
            selectedFilesList.style.display = 'none';
            if (btnSubmitImport) btnSubmitImport.disabled = true;
            return;
        }

        selectedFilesList.style.display = 'block';
        if (btnSubmitImport) btnSubmitImport.disabled = false;

        filesPillsWrap.innerHTML = '';
        pendingFiles.forEach((f) => {
            const pill = document.createElement('div');
            pill.className = 'file-pill';
            const sizeKb = (f.size / 1024).toFixed(1);
            pill.innerHTML = `<span>📄 <strong>${escapeHtml(f.name)}</strong> (${sizeKb} KB)</span>`;
            filesPillsWrap.appendChild(pill);
        });
    }

    if (btnClearFiles) {
        btnClearFiles.addEventListener('click', () => {
            resetUploadState();
        });
    }

    function resetUploadState() {
        pendingFiles = [];
        if (fileInput) fileInput.value = '';
        if (selectedFilesList) selectedFilesList.style.display = 'none';
        if (btnSubmitImport) {
            btnSubmitImport.disabled = true;
            btnSubmitImport.innerHTML = `<i data-lucide="check-circle" style="width: 16px; height: 16px;"></i><span>Завантажити та оновити дашборд</span>`;
        }
        if (importResultBox) {
            importResultBox.style.display = 'none';
            importResultBox.className = 'import-result-box';
            importResultBox.innerHTML = '';
        }
        if (window.lucide) lucide.createIcons();
    }

    if (btnSubmitImport) {
        btnSubmitImport.addEventListener('click', async () => {
            if (pendingFiles.length === 0) return;

            btnSubmitImport.disabled = true;
            btnSubmitImport.innerHTML = `<span>⏳ Обробка файлів...</span>`;
            if (importResultBox) {
                importResultBox.style.display = 'none';
            }

            const formData = new FormData();
            pendingFiles.forEach(f => {
                formData.append('files[]', f);
            });

            try {
                const response = await fetch('api/import.php', {
                    method: 'POST',
                    body: formData
                });

                let res;
                const responseText = await response.text();
                try {
                    res = JSON.parse(responseText);
                } catch (jsonErr) {
                    throw new Error(`Помилка сервера (HTTP ${response.status}): ${responseText.replace(/<[^>]*>/g, ' ').substring(0, 250)}`);
                }

                if (res.status === 'success') {
                    if (importResultBox) {
                        importResultBox.style.display = 'block';
                        importResultBox.className = 'import-result-box success';
                        let html = `<strong>🎉 ${escapeHtml(res.message)}</strong>`;
                        if (res.details && res.details.length > 0) {
                            html += `<ul style="margin: 8px 0 0 18px; font-size: 12px; line-height: 1.5;">`;
                            res.details.forEach(d => {
                                html += `<li>${escapeHtml(d)}</li>`;
                            });
                            html += `</ul>`;
                        }
                        importResultBox.innerHTML = html;
                    }

                    showToast('Імпорт успішно завершено! Показники оновлено.');
                    btnSubmitImport.innerHTML = `<span>✅ Успішно оновлено!</span>`;

                    // Оновлюємо дані на дашборді в реальному часі
                    fetchDashboardData(true);

                    // Закриваємо модалку через 3.5 секунди
                    setTimeout(() => {
                        if (importModal && importModal.style.display === 'flex') {
                            importModal.style.display = 'none';
                            resetUploadState();
                        }
                    }, 3500);

                } else {
                    throw new Error(res.message || 'Помилка обробки файлів.');
                }
            } catch (err) {
                console.error('Помилка імпорту:', err);
                if (importResultBox) {
                    importResultBox.style.display = 'block';
                    importResultBox.className = 'import-result-box error';
                    importResultBox.innerHTML = `<strong>⚠️ Помилка імпорту:</strong> ${escapeHtml(err.message)}`;
                }
                showToast('Помилка імпорту: ' + err.message, true);
                btnSubmitImport.disabled = false;
                btnSubmitImport.innerHTML = `<span>Спробувати ще раз</span>`;
            }
        });
    }

    function escapeHtml(text) {
        if (!text) return '';
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return text.replace(/[&<>"']/g, m => map[m]);
    }

    // Запуск таймера
    startRefreshTimer();
});
