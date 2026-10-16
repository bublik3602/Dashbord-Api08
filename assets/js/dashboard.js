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
        FactoryCharts.initWaterSteamChart(window.INITIAL_DASHBOARD_DATA.energy.timeline);
        if (window.INITIAL_DASHBOARD_DATA.energy && window.INITIAL_DASHBOARD_DATA.energy.timeline) {
            FactoryCharts.initPlantPowerChart(window.INITIAL_DASHBOARD_DATA.energy.timeline, 'kwh');
        }
        FactoryCharts.initGauges(
            window.INITIAL_DASHBOARD_DATA.lab_quality,
            window.INITIAL_DASHBOARD_DATA.contracts_summary,
            window.INITIAL_DASHBOARD_DATA.production_lab_quality
        );
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

    // 2.01 Делегований обробник швидкого переходу на місяць/період (працює і для динамічних кнопок у таблицях)
    document.addEventListener('click', function (e) {
        const elem = e.target.closest('.btn-quick-period, .history-month-card');
        if (!elem) return;
        const p = elem.getAttribute('data-period') || elem.getAttribute('data-period-trigger');
        if (!p || isFetching) return;
        const targetBtn = document.querySelector(`.period-btn[data-period="${p}"]`);
        if (targetBtn) {
            targetBtn.click();
        } else if (monthDropdown) {
            monthDropdown.value = p;
            monthDropdown.dispatchEvent(new Event('change'));
        }
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });

    // 2.02 Обробники міні-перемикача періоду в блоці «Порівняння з сезоном 2025–2026 рр.»
    document.querySelectorAll('.season-pill-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            const p = this.getAttribute('data-period');
            if (!p || isFetching) return;
            const targetBtn = document.querySelector(`.period-btn[data-period="${p}"]`);
            if (targetBtn) {
                targetBtn.click();
            } else if (monthDropdown && ['month_sep', 'month_aug', 'month_oct'].includes(p)) {
                monthDropdown.value = p;
                monthDropdown.dispatchEvent(new Event('change'));
            } else {
                periodButtons.forEach(b => b.classList.remove('active'));
                currentPeriod = p;
                updateExportLink();
                fetchDashboardData(true);
            }
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
            setElementText('#header-sync-time', 'Оновлено: ' + (pi.current_time || new Date().toLocaleTimeString('uk-UA', { hour: '2-digit', minute: '2-digit', second: '2-digit' })));

            const subElem = document.querySelector('#banner-period-sub');
            if (subElem) {
                subElem.innerHTML = `Розрахунок доби: <strong>з 08:00 до 08:00</strong> наступного дня`;
            }
        }

        if (data.lab_quality && data.lab_quality.report_date) {
            setElementText('#lbl-lab-time', 'Звіт 1С станом на: ' + data.lab_quality.report_date);
            setElementText('#banner-lab-time', data.lab_quality.report_date);
        }

        if (data.production_lab_quality && data.production_lab_quality.report_date) {
            setElementText('#lbl-prod-lab-time', 'Звіт цеху переробки станом на: ' + data.production_lab_quality.report_date);
            setElementText('#banner-prod-lab-time', data.production_lab_quality.report_date);
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
        setElementText('#val-seed-in-plant', formatNumber(rz.seed_in_plant, 2) + ' т');
        const trucksTxt = rz.trucks_count ? ` • ${rz.trucks_count} авто` : '';
        setElementText('#val-seed-plan', 'План: ' + formatNumber(rz.seed_plan, 1) + ' т (' + rz.plan_fulfillment_pct + '%)' + trucksTxt);
        
        if (data.warehouse_stocks) {
            const ws = data.warehouse_stocks;
            setElementText('#val-seed-stock', formatNumber(ws.seed_stock_tonnes, 2) + ' т');
            setElementText('#badge-days-reserve', (ws.days_seed_reserve || 21.4) + ' дн. (при 600 т/д)');
            setElementText('#val-seed-stock-sub', `Факт: ${formatNumber(ws.seed_stock_tonnes, 2)} т • Облік: ${formatNumber(ws.seed_stock_accounting_tonnes, 2)} т`);

            // Залишки готової продукції на складах та відвантаження за обраний період
            setElementText('#val-stock-oil', formatNumber(ws.oil_stock_tonnes, 2) + ' т');
            const shippedPrefix = (currentPeriod === 'week') 
                ? 'Відвантажено за тиждень: ' 
                : ((currentPeriod.indexOf('month') !== -1) 
                    ? 'Відвантажено за місяць: ' 
                    : (currentPeriod === 'season' ? 'Відвантажено за сезон: ' : 'Відвантажено за добу: '));

            setElementText('#val-shipped-oil', `${shippedPrefix}${formatNumber(ws.oil_shipped_tonnes, 2)} т`);
            setElementText('#val-stock-meal', formatNumber(ws.meal_stock_tonnes, 2) + ' т');
            setElementText('#val-shipped-meal', `${shippedPrefix}${formatNumber(ws.meal_shipped_tonnes, 2)} т`);
            setElementText('#val-stock-husk', formatNumber(ws.husk_stock_tonnes, 2) + ' т');
            setElementText('#val-shipped-husk', `${shippedPrefix}${formatNumber(ws.husk_shipped_tonnes, 2)} т`);
            setElementText('#val-boiler-husk', formatNumber(ws.husk_boiler_spent_tonnes, 2) + ' т');

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
        const rawSeedTxt = rz.seed_in_production_raw ? `Неочищене: ${formatNumber(rz.seed_in_production_raw, 2)} т • ` : '';
        setElementText('#val-cond-loss', `${rawSeedTxt}Підробка: ${formatNumber(rz.conditioning_loss, 2)} т`);

        // --- Блок А: Лабораторія якості та виконання контрактів ---
        if (data.lab_quality) {
            const lq = data.lab_quality;
            setElementText('#val-lab-oil', formatNumber(lq.oil_content_pct, 2) + '%');
            const devSign = lq.oil_deviation_pct >= 0 ? '+' : '';
            const devLabel = lq.oil_deviation_pct >= 0 ? '(Бонус)' : '(Дисконт)';
            setElementText('#badge-lab-oil-dev', `${devSign}${formatNumber(lq.oil_deviation_pct, 2)}% ${devLabel}`);
            
            const oilDevBadge = document.querySelector('#badge-lab-oil-dev');
            if (oilDevBadge) {
                oilDevBadge.className = 'gauge-badge ' + (lq.oil_deviation_pct >= 0 ? 'badge-bonus' : 'badge-discount');
            }
            setElementText('#val-lab-oil-sub', `Базова норма: ${formatNumber(lq.oil_content_base_pct, 1)}% (Лабораторія)`);

            setElementText('#val-lab-moisture', formatNumber(lq.moisture_pct, 2) + '%');
            const moistBadge = document.querySelector('#badge-lab-moisture');
            if (moistBadge) {
                if (lq.moisture_pct <= 8.0) {
                    moistBadge.textContent = 'В нормі (< 8%)';
                    moistBadge.className = 'gauge-badge badge-bonus';
                } else {
                    moistBadge.textContent = 'Перевищення (> 8%)';
                    moistBadge.className = 'gauge-badge badge-discount';
                }
            }

            setElementText('#val-lab-impurity', formatNumber(lq.impurity_pct, 2) + '%');
            setElementText('#badge-lab-impurity', `Знижка: ${formatNumber(lq.weight_discount_tonnes, 2)} т`);
        }

        if (data.contracts_summary) {
            const cs = data.contracts_summary;
            setElementText('#val-contracts-delivered', `${formatNumber(cs.delivered_tonnes, 0)} т / ${formatNumber(cs.contracted_tonnes, 0)} т`);
            setElementText('#badge-contracts-pct', `${formatNumber(cs.fulfillment_pct, 1)}%`);
            setElementText('#val-contracts-sub', `Залишок: ${formatNumber(cs.remaining_tonnes, 0)} т (${cs.active_specs_count || 14} у роботі, ${cs.pending_specs_count || 3} очікують)`);
        }

        // --- Блок: Лабораторія якості - Подача на переробку (МЕЗ) ---
        if (data.production_lab_quality) {
            const plq = data.production_lab_quality;
            const oilDate = plq.oil_date || '13.10';
            const oilVal = (plq.oil_content_pct !== undefined && plq.oil_content_pct > 0) ? plq.oil_content_pct : 44.49;
            const oilAsrVal = (plq.oil_asr_pct !== undefined && plq.oil_asr_pct > 0) ? plq.oil_asr_pct : 48.30;

            const oilLossKg = plq.oil_loss_kg !== undefined ? plq.oil_loss_kg : 41258;
            const oilLossPct = plq.oil_loss_pct !== undefined ? plq.oil_loss_pct : 0.81;
            const oilUnaccLossKg = plq.oil_unaccounted_loss_kg !== undefined ? plq.oil_unaccounted_loss_kg : -5484;
            const oilUnaccLossPct = plq.oil_unaccounted_loss_pct !== undefined ? plq.oil_unaccounted_loss_pct : -0.11;

            const isUnaccNeg = Number(oilUnaccLossKg) < 0;
            const unaccBg = isUnaccNeg ? '#f0fdf4' : '#fff1f2';
            const unaccBorder = isUnaccNeg ? '#bbf7d0' : '#fecdd3';
            const unaccTxtColor = isUnaccNeg ? '#166534' : '#9f1239';
            const unaccValColor = isUnaccNeg ? '#15803d' : '#881337';
            const unaccBadge = isUnaccNeg ? 'badge-bonus' : 'badge-discount';
            const unaccFormattedKg = (Number(oilUnaccLossKg) < 0 ? '- ' : '') + formatNumber(Math.abs(Number(oilUnaccLossKg)), 0);

            setElementText('#badge-prod-oil-asr', `за ${oilDate} • ${formatNumber(oilVal, 2)}%`);
            const valAsrEl = document.querySelector('#val-prod-oil-asr');
            if (valAsrEl) {
                valAsrEl.innerHTML = `Товарна олійність (за ${oilDate}): <strong>${formatNumber(oilVal, 2)}%</strong> (а.с.р. ${formatNumber(oilAsrVal, 2)}%)`;
            }
            const noteOilEl = document.querySelector('#note-prod-oil');
            if (noteOilEl) {
                noteOilEl.textContent = (plq.oil_note || `Фактична товарна олійність за ${oilDate} як у звіті МЕЗ`) + ' • Справка по переработке';
            }

            // Оновлення таблиці балансу виходу продукції та втрат олії
            setElementText('#badge-prod-oil-balance-date', `за ${oilDate} • Справка по переработке`);
            setElementText('#table-sunflower-oil', `${formatNumber(plq.sunflower_oil_pct !== undefined ? plq.sunflower_oil_pct : 45.3, 1)}%`);
            setElementText('#table-yield-oil', `${formatNumber(plq.yield_oil_pct !== undefined ? plq.yield_oil_pct : 44.59, 2)}%`);
            setElementText('#table-yield-meal', `${formatNumber(plq.yield_meal_pct !== undefined ? plq.yield_meal_pct : 41.54, 2)}%`);
            setElementText('#table-meal-oil', `${formatNumber(plq.meal_oil_pct !== undefined ? plq.meal_oil_pct : 0.77, 2)}%`);
            setElementText('#table-yield-husk', `${formatNumber(plq.yield_husk_pct !== undefined ? plq.yield_husk_pct : 15.06, 2)}%`);
            setElementText('#table-husk-oil', `${formatNumber(plq.husk_oil_pct !== undefined ? plq.husk_oil_pct : 3.27, 2)}%`);
            setElementText('#table-process-loss', `${formatNumber(plq.process_loss_pct !== undefined ? plq.process_loss_pct : -1.20, 2)}%`);

            setElementText('#table-balance-calc', `${formatNumber(plq.balance_oil_calc_kg !== undefined ? plq.balance_oil_calc_kg : 2298106.1, 1)}`);
            setElementText('#table-balance-fact', `${formatNumber(plq.balance_oil_fact_kg !== undefined ? plq.balance_oil_fact_kg : 2303590, 0)}`);
            setElementText('#table-oil-out-kg', `${formatNumber(plq.oil_out_kg !== undefined ? plq.oil_out_kg : 2262332, 0)} кг`);
            setElementText('#table-oil-loss-kg', `${formatNumber(oilLossKg, 0)} кг`);
            setElementText('#table-oil-loss-pct', `${formatNumber(oilLossPct, 2)}%`);
            setElementText('#table-oil-meal-kg', `${formatNumber(plq.oil_in_meal_kg !== undefined ? plq.oil_in_meal_kg : 16260.9, 1)} кг`);
            setElementText('#table-oil-husk-kg', `${formatNumber(plq.oil_in_husk_kg !== undefined ? plq.oil_in_husk_kg : 24997, 0)} кг`);
            
            const unaccKgEl = document.querySelector('#table-unacc-kg');
            if (unaccKgEl) {
                unaccKgEl.textContent = `${unaccFormattedKg} кг`;
                unaccKgEl.style.color = unaccValColor;
            }
            const unaccPctEl = document.querySelector('#table-unacc-pct');
            if (unaccPctEl) {
                unaccPctEl.textContent = `${formatNumber(oilUnaccLossPct, 2)}%`;
                unaccPctEl.className = unaccBadge;
            }

            const moistBadge = document.querySelector('#badge-prod-moisture');
            if (moistBadge) {
                if (plq.moisture_pct <= 7.0 && plq.moisture_pct > 0) {
                    moistBadge.textContent = `В нормі (${formatNumber(plq.moisture_pct, 1)}%)`;
                    moistBadge.className = 'gauge-badge badge-bonus';
                } else if (plq.moisture_pct === 0) {
                    moistBadge.textContent = 'Немає даних';
                    moistBadge.className = 'gauge-badge badge-normal';
                } else {
                    moistBadge.textContent = `Перевищення (${formatNumber(plq.moisture_pct, 1)}%)`;
                    moistBadge.className = 'gauge-badge badge-discount';
                }
            }

            const impBadge = document.querySelector('#badge-prod-impurity');
            if (impBadge) {
                if (plq.impurity_pct <= 2.0 && plq.impurity_pct > 0) {
                    impBadge.textContent = `В нормі (≤ 2.0%)`;
                    impBadge.className = 'gauge-badge badge-bonus';
                } else if (plq.impurity_pct === 0) {
                    impBadge.textContent = 'Немає даних';
                    impBadge.className = 'gauge-badge badge-normal';
                } else {
                    impBadge.textContent = `Перевищення (${formatNumber(plq.impurity_pct, 1)}%)`;
                    impBadge.className = 'gauge-badge badge-discount';
                }
            }

            setElementText('#badge-prod-seed-tonnes', formatNumber(plq.seed_processed_tonnes, 1) + ' т');
            setElementText('#val-prod-seed-processed', formatNumber(plq.seed_processed_tonnes, 1) + ' т');
            if (plq.processed_note) {
                setElementText('#val-prod-seed-sub', plq.processed_note);
            }
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

        // Сповіщення коли за обрану добу переробка на паузі (0 т)
        const idleNoticeEl = document.querySelector('#badge-prod-idle-notice');
        if (idleNoticeEl) {
            idleNoticeEl.style.display = (pz.seed_in_production <= 0) ? 'inline-flex' : 'none';
        }
        const idleHintEl = document.querySelector('#prod-idle-hint');
        if (idleHintEl) {
            idleHintEl.style.display = (pz.seed_in_production <= 0) ? 'flex' : 'none';
        }

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
            const hasData = Boolean(en.has_data);
            if (en.period_note) {
                setElementText('#lbl-energy-period', en.period_note);
            }
            const lblPeriod = document.querySelector('#lbl-energy-period');
            if (lblPeriod) {
                lblPeriod.className = 'section-badge ' + (hasData ? 'badge-green' : 'badge-blue');
            }

            if (hasData) {
                // 1. Електроенергія (4 знаки після коми / крапки)
                if (en.has_power && en.total_kwh !== null && en.total_kwh !== undefined) {
                    setElementText('#val-total-kwh', formatNumber(en.total_kwh, 4) + ' кВт');
                    setElementText('#val-current-power', `Потужність: ${formatNumber(en.current_power_kw, 4)} кВт • Реакт.: ${formatNumber(en.reactive_kvarh || 0, 1)} кВАр·год`);
                    setElementText('#val-specific-kwh', formatNumber(en.specific_kwh_per_tonne, 4) + ' кВт/т');
                    setElementText('#val-specific-sub', en.specific_subtext || 'Розраховано від насіння у переробці');
                } else {
                    setElementText('#val-total-kwh', 'Не внесено');
                    setElementText('#val-current-power', 'Очікується показник лічильника кВт');
                    setElementText('#val-specific-kwh', '—');
                    setElementText('#val-specific-sub', 'Електроенергія ще не внесена');
                }

                // 2. Вода
                if (en.has_water && en.total_water_m3 !== null && en.total_water_m3 !== undefined) {
                    setElementText('#val-water-total', formatNumber(en.total_water_m3, 1) + ' м³');
                    setElementText('#val-water-flow', `Потік: ${formatNumber(en.current_water_m3_hour, 2)} м³/год (${formatNumber(en.daily_water_m3 || en.total_water_m3, 1)} м³/добу)`);
                    setElementText('#val-sewage-total', `Стоки: ${formatNumber(en.sewage_m3 || 0, 1)} м³`);
                    setElementText('#val-boiler-water', `Котельня: ${formatNumber(en.boiler_water_m3 || 0, 0)} м³ • Екстракція: ${formatNumber(en.extraction_water_m3 || 0, 0)} м³`);
                } else {
                    setElementText('#val-water-total', 'Не внесено');
                    setElementText('#val-water-flow', 'Очікується показник водоміра');
                    setElementText('#val-sewage-total', 'Стоки: —');
                    setElementText('#val-boiler-water', 'Вода ще не внесена');
                }

                // 3. Пара
                if (en.has_steam && en.steam_boiler_tonnes !== null && en.steam_boiler_tonnes !== undefined) {
                    setElementText('#val-steam-total', formatNumber(en.steam_boiler_tonnes || 0, 1) + ' т');
                    setElementText('#val-steam-sub', en.steam_subtext || 'Котельня на лушпинні');
                    const specificSteam = Number(en.steam_specific_kg_per_tonne || 0);
                    setElementText('#val-steam-specific', specificSteam > 0 ? (formatNumber(specificSteam, 1) + ' кг/т') : '—');
                    setElementText('#val-steam-distrib', en.steam_distrib_subtext || '');
                } else {
                    setElementText('#val-steam-total', 'Не внесено');
                    setElementText('#val-steam-sub', 'Очікується журнал котельні');
                    setElementText('#val-steam-specific', '—');
                    setElementText('#val-steam-distrib', 'Пара ще не внесена');
                }
            } else {
                setElementText('#val-total-kwh', 'Жовтень: немає даних');
                setElementText('#val-current-power', 'Звіт головного енергетика очікується');

                setElementText('#val-water-total', 'Жовтень: немає даних');
                setElementText('#val-water-flow', 'Журнал лічильника води очікується');

                setElementText('#val-steam-total', 'Жовтень: немає даних');
                setElementText('#val-steam-sub', 'Журнал котельні за жовтень очікується');

                setElementText('#val-steam-specific', 'Жовтень: немає даних');
                setElementText('#val-steam-distrib', 'Оберіть «Вересень» для перегляду факту');

                setElementText('#val-specific-kwh', 'Жовтень: немає даних');
                setElementText('#val-specific-sub', 'Звіт енергетика очікується');

                setElementText('#val-sewage-total', 'Жовтень: немає даних');
                setElementText('#val-boiler-water', 'Оберіть «Вересень» для перегляду');
            }

            const steamTbody = document.querySelector('#steam-daily-tbody');
            if (steamTbody) {
                if (hasData && Array.isArray(en.steam_daily_table) && en.steam_daily_table.length > 0) {
                    steamTbody.innerHTML = en.steam_daily_table.map(r => `
                        <tr>
                            <td class="bold">${r.date || (r.day + '.09.2026')}</td>
                            <td class="bold" style="color: #d97706;">${formatNumber(r.boiler_t, 2)}</td>
                            <td class="bold" style="color: #0284c7;">${formatNumber(r.prod_t, 2)}</td>
                            <td>${formatNumber(r.sn_t, 2)}</td>
                            <td style="color: #2563eb;">${formatNumber(r.extr_t, 2)}</td>
                            <td style="color: #10b981;">${formatNumber(r.prep_t, 2)}</td>
                            <td>${formatNumber(r.elev_t, 2)}</td>
                            <td>${formatNumber(r.heating_t, 2)}</td>
                        </tr>
                    `).join('');
                } else {
                    steamTbody.innerHTML = `<tr><td colspan="8" style="text-align: center; color: #64748b; padding: 22px; font-weight: 600;">Жовтень 2026: добовий журнал обліку пари очікується. <button type="button" class="btn-quick-period" data-period="month_sep" style="background: #0284c7; color: #fff; border: none; padding: 4px 10px; border-radius: 4px; font-size: 11px; margin-left: 8px; cursor: pointer; font-weight: 700;">Обрати Вересень 2026</button></td></tr>`;
                }
            }

            const deptTbody = document.querySelector('#energy-departments-tbody');
            if (deptTbody) {
                if (hasData && Array.isArray(en.departments) && en.departments.length > 0) {
                    deptTbody.innerHTML = en.departments.map(d => `
                        <tr>
                            <td class="bold">${d.name}</td>
                            <td class="bold" style="color: #0284c7;">${formatNumber(d.active_kwh, 3)}</td>
                            <td>${formatNumber(d.reactive_kvarh, 1)}</td>
                            <td>${Number(d.specific_kwh) > 0 ? formatNumber(d.specific_kwh, 3) : '—'}</td>
                            <td class="bold" style="color: #059669;">${formatNumber(d.water_m3, 0)}</td>
                            <td>${formatNumber(d.sewage_m3, 0)}</td>
                        </tr>
                    `).join('');
                } else {
                    deptTbody.innerHTML = `<tr><td colspan="6" style="text-align: center; color: #64748b; padding: 22px; font-weight: 600;">Жовтень 2026: дані відсутні (звіт енергетика очікується). <button type="button" class="btn-quick-period" data-period="month_sep" style="background: #0284c7; color: #fff; border: none; padding: 4px 10px; border-radius: 4px; font-size: 11px; margin-left: 8px; cursor: pointer; font-weight: 700;">Обрати Вересень 2026</button></td></tr>`;
                }
            }
        }

        // --- Блок В: Статуси обладнання ---
        if (eq && eq.nodes) {
            setElementText('#equip-health-summary', `${eq.running_count}/${eq.total_count} вузлів у роботі`);
        }

        // --- Блок Г: Порівняння сезонів 2026 vs 2025 (День / Тиждень / Місяць / Сезон) ---
        const periodComp = (data.historical_analytics && data.historical_analytics.period_comparison) 
                            ? data.historical_analytics.period_comparison 
                            : (data.period_comparison || null);
        if (periodComp) {
            updateSeasonComparisonUI(periodComp);
        }

        if (data.historical_analytics) {
            updateHistoricalMonthCards(data.historical_analytics);
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

    /**
     * Оновлення блоку «Порівняння з сезоном 2025–2026 рр.» (4 KPI-картки та таблиця зіставлення)
     */
    function updateSeasonComparisonUI(comp) {
        if (!comp) return;

        // 0. Оновлюємо бейдж та активні пігулки вибору періоду
        if (comp.badge_label) {
            setElementText('#badge-season-compare-period', comp.badge_label);
        }

        const periodKey = comp.period || currentPeriod;
        document.querySelectorAll('.season-pill-btn').forEach(btn => {
            const btnP = btn.getAttribute('data-period');
            const isActive = (btnP === periodKey) ||
                             (btnP === 'day' && ['day', 'day_today', 'day_prev', 'shift'].includes(periodKey)) ||
                             (btnP === 'month' && ['month', 'month_oct', 'month_sep', 'month_aug'].includes(periodKey));
            btn.classList.toggle('active', isActive);
        });

        // 1. Оновлюємо 4 KPI картки
        const kpis = comp.kpis;
        if (kpis) {
            // Переробка насіння
            if (kpis.seed_processed) {
                const sp = kpis.seed_processed;
                setElementText('#kpi-season-seed-title', sp.title);
                setElementText('#kpi-season-seed-badge', sp.badge);
                setElementText('#kpi-season-seed-num', sp.number);
                setElementText('#kpi-season-seed-unit', sp.unit);
                const subEl = document.querySelector('#kpi-season-seed-sub');
                if (subEl) subEl.innerHTML = sp.subtext;
                const barEl = document.querySelector('#kpi-season-seed-bar');
                if (barEl) barEl.style.width = Math.min(100, Math.max(0, sp.progress_pct || 100)) + '%';
            }

            // Вихід Олії
            if (kpis.oil_yield) {
                const oy = kpis.oil_yield;
                setElementText('#kpi-season-oil-title', oy.title);
                setElementText('#kpi-season-oil-badge', oy.badge);
                setElementText('#kpi-season-oil-num', oy.number);
                setElementText('#kpi-season-oil-unit', oy.unit);
                const subEl = document.querySelector('#kpi-season-oil-sub');
                if (subEl) subEl.innerHTML = oy.subtext;
                const barEl = document.querySelector('#kpi-season-oil-bar');
                if (barEl) barEl.style.width = Math.min(100, Math.max(0, oy.progress_pct || 45)) + '%';
            }

            // Вихід Шроту
            if (kpis.meal_yield) {
                const my = kpis.meal_yield;
                setElementText('#kpi-season-meal-title', my.title);
                setElementText('#kpi-season-meal-badge', my.badge);
                setElementText('#kpi-season-meal-num', my.number);
                setElementText('#kpi-season-meal-unit', my.unit);
                const subEl = document.querySelector('#kpi-season-meal-sub');
                if (subEl) subEl.innerHTML = my.subtext;
                const barEl = document.querySelector('#kpi-season-meal-bar');
                if (barEl) barEl.style.width = Math.min(100, Math.max(0, my.progress_pct || 41)) + '%';
            }

            // Сума Олія + Шрот
            if (kpis.oil_meal_sum) {
                const oms = kpis.oil_meal_sum;
                setElementText('#kpi-season-sum-title', oms.title);
                setElementText('#kpi-season-sum-badge', oms.badge);
                setElementText('#kpi-season-sum-num', oms.number);
                setElementText('#kpi-season-sum-unit', oms.unit);
                const subEl = document.querySelector('#kpi-season-sum-sub');
                if (subEl) subEl.innerHTML = oms.subtext;
                const barEl = document.querySelector('#kpi-season-sum-bar');
                if (barEl) barEl.style.width = Math.min(100, Math.max(0, oms.progress_pct || 86)) + '%';
            }
        }

        // 2. Шапка порівняльної таблиці
        const headers = comp.headers;
        if (headers) {
            setElementText('#th-season-compare-2026', headers.col_2026);
            setElementText('#th-season-compare-2025-analog', headers.col_2025_analog);
            setElementText('#th-season-compare-2025-year', headers.col_2025_year);
            setElementText('#th-season-compare-trend', headers.col_trend);
        }

        // 3. Рядки таблиці
        const tbody = document.querySelector('#tbody-season-comparison');
        if (tbody && Array.isArray(comp.rows)) {
            tbody.innerHTML = comp.rows.map(r => `
                <tr>
                    <td>${escapeHtml(r.name)}</td>
                    <td class="bold">${escapeHtml(r.val_2026)}</td>
                    <td>${escapeHtml(r.val_2025_an)}</td>
                    <td>${escapeHtml(r.val_2025_yr)}</td>
                    <td><span class="trend-badge ${escapeHtml(r.trend_class)}">${escapeHtml(r.trend_text)}</span></td>
                </tr>
            `).join('');
        }
    }

    /**
     * Оновлення помісячних карток (Жовтень, Сезон) Блоку Г на основі реальних даних БД
     */
    function updateHistoricalMonthCards(histData) {
        if (!histData) return;
        const months = histData.months || {};
        const oct = months.oct;
        const season = histData.season_total;

        if (season) {
            if (season.badge_label) {
                setElementText('#badge-historical-season', season.badge_label);
            }
            if (season.working_days_label) {
                setElementText('#val-season-work-days', season.working_days_label);
            }
            if (season.seed_in !== undefined) {
                setElementText('#val-season-seed-in', formatNumber(season.seed_in, 2) + ' т');
            }
            if (season.seed_processed !== undefined) {
                setElementText('#val-season-seed-proc', formatNumber(season.seed_processed, 2) + ' т (' + (season.working_days || 25) + ' діб)');
            }
            if (season.oil_out !== undefined) {
                setElementText('#val-season-oil-out', formatNumber(season.oil_out, 2) + ' т (' + (season.oil_yield_pct || 43.60) + '%)');
            }
            if (season.meal_out !== undefined) {
                setElementText('#val-season-meal-out', formatNumber(season.meal_out, 2) + ' т (' + (season.meal_yield_pct || 41.47) + '%)');
            }
            if (season.trucks_count !== undefined) {
                setElementText('#val-season-trucks', season.trucks_count + ' авто');
            }
            if (season.husk_out !== undefined) {
                setElementText('#val-season-husk-out', formatNumber(season.husk_out, 2) + ' т');
            }
            const footerSeason = document.querySelector('#val-season-footer');
            if (footerSeason && season.oil_stock !== undefined && season.meal_stock !== undefined) {
                footerSeason.innerHTML = `<span>Залишки зараз: Олія ${formatNumber(season.oil_stock, 1)} т • Шрот ${formatNumber(season.meal_stock, 1)} т</span>`;
            }
        }

        if (oct) {
            if (oct.name) {
                setElementText('#name-oct-card', oct.name);
            }
            if (oct.working_days_label) {
                setElementText('#badge-oct-working-days', oct.working_days_label);
                setElementText('#val-oct-work-days', oct.working_days_label);
            }
            if (oct.seed_in !== undefined) {
                setElementText('#val-oct-seed-in', formatNumber(oct.seed_in, 2) + ' т');
            }
            if (oct.seed_processed !== undefined) {
                setElementText('#val-oct-seed-proc', formatNumber(oct.seed_processed, 2) + ' т (' + (oct.working_days || 9) + ' діб)');
            }
            if (oct.oil_out !== undefined) {
                setElementText('#val-oct-oil-out', formatNumber(oct.oil_out, 2) + ' т (' + (oct.oil_yield_pct || 44.35) + '%)');
            }
            if (oct.meal_out !== undefined) {
                setElementText('#val-oct-meal-out', formatNumber(oct.meal_out, 2) + ' т (' + (oct.meal_yield_pct || 40.96) + '%)');
            }
            if (oct.trucks_count !== undefined) {
                setElementText('#val-oct-trucks', oct.trucks_count + ' авто');
            }
            if (oct.seed_stock !== undefined) {
                setElementText('#val-oct-seed-stock', formatNumber(oct.seed_stock, 2) + ' т');
            }
            const footerOct = document.querySelector('#val-oct-footer');
            if (footerOct && oct.husk_out !== undefined) {
                footerOct.innerHTML = `<span>Лузга: ${formatNumber(oct.husk_out, 1)} т &bull; Відвантажено: олія ${formatNumber(oct.oil_shipped || 0, 0)} т, шрот ${formatNumber(oct.meal_shipped || 0, 0)} т</span>`;
            }
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

    function detectFileGuideCategory(filename) {
        const lower = (filename || '').toLowerCase();
        if (lower.includes('prihod') || lower.includes('приход') || lower.includes('spec') || lower.includes('спец') || lower.includes('погоджен') || lower.includes('согласован') || lower.includes('лаборатор')) {
            return { key: 'lab', label: '1С Приход + Специфікація / Лист погодження' };
        }
        if (lower.includes('ежедн.отчет2') || lower.includes('свод') || lower.includes('залишк') || lower.includes('остатк')) {
            return { key: 'stocks', label: 'Залишки СВОД / Відвантаження' };
        }
        if (lower.includes('справка по переработке') || lower.includes('переработк') || lower.includes('переробк')) {
            return { key: 'prod', label: 'Переробка / Вихід продукції' };
        }
        if (lower.includes('ежедневный отчет') || lower.includes('план производства')) {
            return { key: 'daily_prod', label: 'План-факт виробництва' };
        }
        if (lower.includes('энергоносител') || lower.includes('енергоносі') || lower.includes('агропроінвест') || lower.includes('агропроинвест') || lower.includes('вод') || lower.includes('енерг') || lower.includes('пар') || lower.includes('котел')) {
            return { key: 'energy', label: 'Електроенергія, Вода та Пара' };
        }
        return { key: null, label: 'Звіт Excel' };
    }

    function updateGuideHighlights() {
        const allKeys = ['lab', 'stocks', 'prod', 'daily_prod', 'energy'];
        const matchedKeys = new Set();
        pendingFiles.forEach(f => {
            const cat = detectFileGuideCategory(f.name);
            if (cat.key) matchedKeys.add(cat.key);
        });

        allKeys.forEach(k => {
            const itemEl = document.querySelector(`.guide-item[data-expected="${k}"]`);
            const badgeEl = document.getElementById(`guide-status-${k}`);
            if (matchedKeys.has(k)) {
                if (itemEl) itemEl.style.borderColor = '#10b981';
                if (itemEl) itemEl.style.background = '#f0fdf4';
                if (badgeEl) badgeEl.style.display = 'inline-block';
            } else {
                if (itemEl) itemEl.style.borderColor = '';
                if (itemEl) itemEl.style.background = '';
                if (badgeEl) badgeEl.style.display = 'none';
            }
        });
    }

    function renderSelectedFiles() {
        if (!selectedFilesList || !filesPillsWrap) return;

        updateGuideHighlights();

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
            const cat = detectFileGuideCategory(f.name);
            pill.innerHTML = `<span>📄 <strong>${escapeHtml(f.name)}</strong> <small style="color:#059669; font-weight:600;">[${escapeHtml(cat.label)}]</small> (${sizeKb} KB)</span>`;
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
        updateGuideHighlights();
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

    // -------------------------------------------------------------
    // 12. ЕНЕРГЕТИКА: ПЕРЕМИКАЧ ОДИНИЦЬ ГРАФІКА СПОЖИВАННЯ (кВт / МВт)
    // -------------------------------------------------------------
    const powerUnitButtons = document.querySelectorAll('.btn-power-unit');
    const lblPlantPowerUnit = document.getElementById('lbl-plant-power-unit');

    powerUnitButtons.forEach(btn => {
        btn.addEventListener('click', function () {
            powerUnitButtons.forEach(b => {
                b.classList.remove('active');
                b.style.background = 'transparent';
                b.style.color = '#475569';
            });
            this.classList.add('active');
            this.style.background = '#0284c7';
            this.style.color = '#ffffff';

            const unit = this.getAttribute('data-unit') || 'kwh';
            if (lblPlantPowerUnit) {
                if (unit === 'mwh') lblPlantPowerUnit.textContent = 'Режим: МВт за добу';
                else if (unit === 'mw') lblPlantPowerUnit.textContent = 'Режим: Середня потужність (МВт)';
                else lblPlantPowerUnit.textContent = 'Режим: кВт за добу';
            }

            if (window.FactoryCharts && FactoryCharts.initPlantPowerChart) {
                FactoryCharts.initPlantPowerChart(FactoryCharts._currentEnergyTimeline, unit);
            }
        });
    });

    // -------------------------------------------------------------
    // 13. ЕНЕРГЕТИКА: ПЕРЕМИКАЧ ПЕРІОДІВ (Доба, Тиждень, Місяць)
    // -------------------------------------------------------------
    const energyPeriodButtons = document.querySelectorAll('.energy-period-btn');
    energyPeriodButtons.forEach(btn => {
        btn.addEventListener('click', function () {
            const p = this.getAttribute('data-period');
            if (!p || isFetching) return;

            energyPeriodButtons.forEach(b => b.classList.remove('active'));
            this.classList.add('active');

            // Синхронізуємо з глобальним селектором періоду, якщо є
            const globalBtn = document.querySelector(`.period-btn:not(.energy-period-btn)[data-period="${p}"]`);
            if (globalBtn) {
                periodButtons.forEach(b => b.classList.remove('active'));
                globalBtn.classList.add('active');
            }

            currentPeriod = p;
            updateExportLink();
            fetchDashboardData(true);
        });
    });

    // -------------------------------------------------------------
    // 14. МОДАЛЬНЕ ВІКНО: РУЧНИЙ ВВІД ПОКАЗНИКІВ ЕНЕРГОНОСІЇВ
    // -------------------------------------------------------------
    const btnOpenEnergyEntry   = document.getElementById('btn-open-energy-entry');
    const energyEntryModal     = document.getElementById('energy-entry-modal');
    const btnCloseEnergyEntry  = document.getElementById('btn-close-energy-entry');
    const btnCancelEnergyEntry = document.getElementById('btn-cancel-energy-entry');
    const formEnergyEntry      = document.getElementById('form-energy-entry');
    const energyHistoryTbody   = document.getElementById('energy-history-tbody');
    const btnReloadEnergyHist  = document.getElementById('btn-reload-energy-history');
    const energyFormAlert      = document.getElementById('energy-form-alert');

    function openEnergyModal() {
        if (!energyEntryModal) return;
        energyEntryModal.style.display = 'flex';
        loadEnergyHistory();
        if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    function closeEnergyModal() {
        if (!energyEntryModal) return;
        energyEntryModal.style.display = 'none';
        if (energyFormAlert) energyFormAlert.style.display = 'none';
    }

    if (btnOpenEnergyEntry) btnOpenEnergyEntry.addEventListener('click', openEnergyModal);
    if (btnCloseEnergyEntry) btnCloseEnergyEntry.addEventListener('click', closeEnergyModal);
    if (btnCancelEnergyEntry) btnCancelEnergyEntry.addEventListener('click', closeEnergyModal);
    if (btnReloadEnergyHist) btnReloadEnergyHist.addEventListener('click', loadEnergyHistory);

    // Закриття кліком по підкладці
    if (energyEntryModal) {
        energyEntryModal.addEventListener('click', function (e) {
            if (e.target === energyEntryModal) closeEnergyModal();
        });
    }

    // Допоміжна функція автопідстановки збережених значень для обраної дати
    function fillFormForDate(dateStr) {
        const entry = (window._energyHistoryEntries || []).find(x => x.date === dateStr);
        const kwhInput   = document.getElementById('energy-form-kwh');
        const waterInput = document.getElementById('energy-form-water');
        const steamInput = document.getElementById('energy-form-steam');
        const pressInput = document.getElementById('energy-form-pressure');
        const reactInput = document.getElementById('energy-form-reactive');
        const noteInput  = document.getElementById('energy-form-note');

        if (entry) {
            if (kwhInput)   kwhInput.value   = (entry.power_kwh !== null && entry.power_kwh !== undefined) ? entry.power_kwh : '';
            if (waterInput) waterInput.value = (entry.water_m3 !== null && entry.water_m3 !== undefined) ? entry.water_m3 : '';
            if (steamInput) steamInput.value = (entry.steam_t !== null && entry.steam_t !== undefined) ? entry.steam_t : '';
            if (pressInput && entry.steam_pressure_bar) pressInput.value = entry.steam_pressure_bar;
            if (reactInput && entry.reactive_kvarh)     reactInput.value = entry.reactive_kvarh;
            if (noteInput && entry.note)               noteInput.value  = entry.note;
        } else {
            if (kwhInput)   kwhInput.value   = '';
            if (reactInput) reactInput.value = '';
            if (waterInput) waterInput.value = '';
            if (steamInput) steamInput.value = '';
            if (noteInput)  noteInput.value  = '';
        }
    }

    const inputEnergyDate = document.getElementById('energy-form-date');
    if (inputEnergyDate) {
        inputEnergyDate.addEventListener('change', function () {
            fillFormForDate(this.value);
        });
    }

    // Завантаження історії записів енергетика
    async function loadEnergyHistory() {
        if (!energyHistoryTbody) return;
        try {
            energyHistoryTbody.innerHTML = '<tr><td colspan="7" style="text-align: center; color: #64748b; padding: 10px;">Завантаження...</td></tr>';
            const res = await fetch('api/save_energy_entry.php', { cache: 'no-store' });
            const json = await res.json();
            if (json.status === 'success' && Array.isArray(json.entries) && json.entries.length > 0) {
                window._energyHistoryEntries = json.entries;
                energyHistoryTbody.innerHTML = json.entries.map(e => {
                    const kwhTxt = (e.power_kwh !== null && e.power_kwh !== undefined && e.power_kwh !== '')
                        ? `${formatNumber(e.power_kwh, 4)} кВт`
                        : `<span style="color:#94a3b8;">—</span>`;
                    const rctTxt = (e.reactive_kvarh !== null && e.reactive_kvarh !== undefined && e.reactive_kvarh !== '')
                        ? `${formatNumber(e.reactive_kvarh, 1)} кВАр`
                        : `<span style="color:#94a3b8;">—</span>`;
                    const watTxt = (e.water_m3 !== null && e.water_m3 !== undefined && e.water_m3 !== '')
                        ? `${formatNumber(e.water_m3, 1)} м³`
                        : `<span style="color:#94a3b8;">—</span>`;
                    const stmTxt = (e.steam_t !== null && e.steam_t !== undefined && e.steam_t !== '')
                        ? `${formatNumber(e.steam_t, 1)} т`
                        : `<span style="color:#94a3b8;">—</span>`;

                    return `
                        <tr class="energy-history-row" data-date="${e.date}" style="cursor: pointer;" title="Клікніть для підстановки показників у форму">
                            <td class="bold">${e.date}</td>
                            <td class="bold" style="color: #0284c7;">${kwhTxt}</td>
                            <td style="color: #7c3aed; font-weight: 600;">${rctTxt}</td>
                            <td style="color: #059669;">${watTxt}</td>
                            <td style="color: #d97706;">${stmTxt}</td>
                            <td><span style="font-size: 10px; color: #64748b;">${escapeHtml(e.author || 'energo')}</span></td>
                            <td>
                                <button type="button" class="btn-delete-energy-entry" data-date="${e.date}" title="Видалити запис" style="background: none; border: none; color: #ef4444; cursor: pointer; font-size: 13px; font-weight: 700; padding: 2px 6px;">&times;</button>
                            </td>
                        </tr>
                    `;
                }).join('');

                // Клік по рядку підставляє дату та збережені дані у форму
                document.querySelectorAll('.energy-history-row').forEach(row => {
                    row.addEventListener('click', function (ev) {
                        if (ev.target.closest('.btn-delete-energy-entry')) return;
                        const dateStr = this.getAttribute('data-date');
                        if (inputEnergyDate) {
                            inputEnergyDate.value = dateStr;
                            fillFormForDate(dateStr);
                        }
                    });
                });

                // Навішуємо обробник видалення
                document.querySelectorAll('.btn-delete-energy-entry').forEach(btn => {
                    btn.addEventListener('click', async function (ev) {
                        ev.stopPropagation();
                        const d = this.getAttribute('data-date');
                        if (!confirm(`Ви дійсно бажаєте видалити збережені показники за дату ${d}?`)) return;
                        try {
                            const delRes = await fetch('api/save_energy_entry.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ action: 'delete', date: d })
                            });
                            const delJson = await delRes.json();
                            if (delJson.status === 'success') {
                                showToast(delJson.message);
                                loadEnergyHistory();
                                fetchDashboardData(true);
                            } else {
                                alert(delJson.message || 'Помилка видалення');
                            }
                        } catch (err) {
                            alert('Помилка видалення запису: ' + err.message);
                        }
                    });
                });
            } else {
                window._energyHistoryEntries = [];
                energyHistoryTbody.innerHTML = '<tr><td colspan="6" style="text-align: center; color: #94a3b8; padding: 10px;">Записів ще немає. Введіть перший показник вище.</td></tr>';
            }
        } catch (err) {
            energyHistoryTbody.innerHTML = `<tr><td colspan="6" style="text-align: center; color: #ef4444; padding: 10px;">Помилка: ${escapeHtml(err.message)}</td></tr>`;
        }
    }

    // Збереження форми показників
    if (formEnergyEntry) {
        formEnergyEntry.addEventListener('submit', async function (e) {
            e.preventDefault();
            const btnSubmit = document.getElementById('btn-submit-energy-entry');
            const dateVal     = document.getElementById('energy-form-date').value;
            const kwhVal      = (document.getElementById('energy-form-kwh').value || '').trim();
            const waterVal    = (document.getElementById('energy-form-water').value || '').trim();
            const steamVal    = (document.getElementById('energy-form-steam').value || '').trim();
            const pressureVal = document.getElementById('energy-form-pressure') ? document.getElementById('energy-form-pressure').value : 11.2;
            const reactiveVal = document.getElementById('energy-form-reactive') ? document.getElementById('energy-form-reactive').value : '';
            const noteVal     = document.getElementById('energy-form-note') ? document.getElementById('energy-form-note').value : '';

            // Перевіряємо, чи введено хоча б одне поле
            if (!kwhVal && !reactiveVal && !waterVal && !steamVal) {
                if (energyFormAlert) {
                    energyFormAlert.style.display = 'block';
                    energyFormAlert.style.background = '#fee2e2';
                    energyFormAlert.style.color = '#991b1b';
                    energyFormAlert.style.border = '1px solid #fca5a5';
                    energyFormAlert.innerHTML = `<strong>⚠️ Увага:</strong> Будь ласка, вкажіть хоча б один показник: електроенергію (кВт), реактивну енергію (кВАр·год), воду (м³) або пару (т).`;
                }
                showToast('Введіть хоча б один показник: світло, реактив, воду або пару', true);
                return;
            }

            if (btnSubmit) {
                btnSubmit.disabled = true;
                btnSubmit.innerHTML = `<span>Збереження...</span>`;
            }

            try {
                const payload = {
                    action: 'save',
                    date: dateVal,
                    power_kwh: kwhVal,
                    water_m3: waterVal,
                    steam_t: steamVal,
                    steam_pressure_bar: pressureVal,
                    reactive_kvarh: reactiveVal,
                    note: noteVal
                };

                const res = await fetch('api/save_energy_entry.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const json = await res.json();

                if (json.status === 'success') {
                    showToast(json.message);
                    if (energyFormAlert) {
                        energyFormAlert.style.display = 'block';
                        energyFormAlert.style.background = '#dcfce7';
                        energyFormAlert.style.color = '#15803d';
                        energyFormAlert.style.border = '1px solid #86efac';
                        energyFormAlert.innerHTML = `<strong>✓ Успішно збережено:</strong> ${escapeHtml(json.message)}`;
                    }

                    // Перезавантажуємо історію та оновлюємо дашборд
                    loadEnergyHistory();
                    fetchDashboardData(true);

                    setTimeout(() => {
                        closeEnergyModal();
                    }, 1200);
                } else {
                    throw new Error(json.message || 'Помилка збереження даних.');
                }
            } catch (err) {
                if (energyFormAlert) {
                    energyFormAlert.style.display = 'block';
                    energyFormAlert.style.background = '#fee2e2';
                    energyFormAlert.style.color = '#991b1b';
                    energyFormAlert.style.border = '1px solid #fca5a5';
                    energyFormAlert.innerHTML = `<strong>⚠️ Помилка:</strong> ${escapeHtml(err.message)}`;
                }
                showToast(err.message, true);
            } finally {
                if (btnSubmit) {
                    btnSubmit.disabled = false;
                    btnSubmit.innerHTML = `<i data-lucide="check" style="width: 14px; height: 14px;"></i><span>Зберегти показники</span>`;
                    if (typeof lucide !== 'undefined') lucide.createIcons();
                }
            }
        });
    }

    // Запуск таймера
    startRefreshTimer();
});
