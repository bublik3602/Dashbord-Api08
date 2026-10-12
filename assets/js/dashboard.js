/**
 * Основная логика дашборда: работа с API, переключение периодов, таймер и обновление DOM
 */

document.addEventListener('DOMContentLoaded', function () {
    let currentPeriod = 'day';
    let currentCargo = 'Все';
    let refreshSecondsLeft = 30;
    let refreshTimerInterval = null;
    let isFetching = false;
    let cachedLogisticsData = window.INITIAL_DASHBOARD_DATA ? window.INITIAL_DASHBOARD_DATA.logistics : null;

    const timerDisplay = document.getElementById('refresh-timer-count');
    const btnRefresh = document.getElementById('btn-manual-refresh');
    const periodButtons = document.querySelectorAll('.period-btn');
    const cargoButtons = document.querySelectorAll('.cargo-tab-btn');

    // 1. Инициализация графиков при первой загрузке страницы
    if (window.INITIAL_DASHBOARD_DATA) {
        FactoryCharts.initDonutChart(window.INITIAL_DASHBOARD_DATA.material_balance.yield_chart);
        FactoryCharts.initEnergyChart(window.INITIAL_DASHBOARD_DATA.energy.timeline);
    }

    // 2. Обработчики клика по фильтру периода
    periodButtons.forEach(btn => {
        btn.addEventListener('click', function () {
            const period = this.getAttribute('data-period');
            if (period === currentPeriod || isFetching) return;

            periodButtons.forEach(b => b.classList.remove('active'));
            this.classList.add('active');

            currentPeriod = period;
            fetchDashboardData(true);
        });
    });

    // 2.1 Обработчики переключения табов груза (Все, Семена, Масло, Шрот, Лузга)
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

    // 3. Ручное обновление данных
    if (btnRefresh) {
        btnRefresh.addEventListener('click', function () {
            if (isFetching) return;
            fetchDashboardData(true);
        });
    }

    // 4. Таймер автообновления
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

    // 5. Запрос к REST API
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
                    showToast('Данные дашборда обновлены');
                }
            } else {
                throw new Error(json.message || 'Ошибка ответа сервера');
            }
        } catch (err) {
            console.error('Ошибка обновления данных:', err);
            showToast('Сбой обновления данных: ' + err.message, true);
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

    // 6. Обновление значений в интерфейсе
    function updateDashboardUI(data) {
        const mb = data.material_balance;
        const en = data.energy;
        const log = data.logistics;
        const eq = data.equipment;

        // --- Блок А: Зона приемки ---
        const rz = mb.receiving_zone;
        setElementText('#val-seed-in-plant', formatNumber(rz.seed_in_plant) + ' т');
        setElementText('#val-seed-plan', 'План: ' + formatNumber(rz.seed_plan) + ' т (' + rz.plan_fulfillment_pct + '%)');
        setElementText('#val-cond-loss', formatNumber(rz.conditioning_loss) + ' т');
        
        const condLossBadge = document.querySelector('#badge-cond-loss');
        if (condLossBadge) {
            condLossBadge.textContent = rz.conditioning_loss_pct + '%';
            condLossBadge.className = 'kpi-badge ' + rz.status_class;
        }

        // --- Блок А: Зона производства ---
        const pz = mb.production_zone;
        setElementText('#val-seed-in-prod', formatNumber(pz.seed_processed) + ' т');
        setElementText('#val-prod-loss', formatNumber(pz.production_loss) + ' т');
        
        const prodLossBadge = document.querySelector('#badge-prod-loss');
        if (prodLossBadge) {
            prodLossBadge.textContent = pz.production_loss_pct + '%';
            prodLossBadge.className = 'kpi-badge ' + pz.status_class;
        }

        // Легенда выхода продукции
        setElementText('#legend-oil-val', formatNumber(pz.oil_out) + ' т');
        setElementText('#legend-oil-pct', mb.yield_chart.percentages[0] + '%');

        setElementText('#legend-meal-val', formatNumber(pz.meal_out) + ' т');
        setElementText('#legend-meal-pct', mb.yield_chart.percentages[1] + '%');

        setElementText('#legend-husk-val', formatNumber(pz.husk_out) + ' т');
        setElementText('#legend-husk-pct', mb.yield_chart.percentages[2] + '%');

        setElementText('#legend-loss-val', formatNumber(pz.production_loss) + ' т');
        setElementText('#legend-loss-pct', mb.yield_chart.percentages[3] + '%');

        // --- Блок Б: Логистика и воронка (разделение на Семена, Масло, Шрот, Лузга) ---
        cachedLogisticsData = log;
        if (log && log.categories) {
            const cats = log.categories;
            if (cats['Семена']) {
                setElementText('#val-stay-seed', cats['Семена'].avg_stay_text);
                setElementText('#val-cnt-seed', 'На заводе: ' + (cats['Семена'].on_site !== undefined ? cats['Семена'].on_site : cats['Семена'].total_active));
            }
            if (cats['Масло']) {
                setElementText('#val-stay-oil', cats['Масло'].avg_stay_text);
                setElementText('#val-cnt-oil', 'На заводе: ' + (cats['Масло'].on_site !== undefined ? cats['Масло'].on_site : cats['Масло'].total_active));
            }
            if (cats['Шрот']) {
                setElementText('#val-stay-meal', cats['Шрот'].avg_stay_text);
                setElementText('#val-cnt-meal', 'На заводе: ' + (cats['Шрот'].on_site !== undefined ? cats['Шрот'].on_site : cats['Шрот'].total_active));
            }
            if (cats['Лузга']) {
                setElementText('#val-stay-husk', cats['Лузга'].avg_stay_text);
                setElementText('#val-cnt-husk', 'На заводе: ' + (cats['Лузга'].on_site !== undefined ? cats['Лузга'].on_site : cats['Лузга'].total_active));
            }
        }
        renderLogisticsByCargo(currentCargo);

        // --- Блок В: Энергетика ---
        setElementText('#val-current-power', formatNumber(en.current_power_kw, 0) + ' кВт');
        setElementText('#val-total-kwh', formatNumber(en.total_kwh, 0) + ' кВт*ч');
        setElementText('#val-water-flow', formatNumber(en.current_water_m3_hour, 1) + ' м³/ч');
        setElementText('#val-water-total', formatNumber(en.total_water_m3, 0) + ' м³');
        setElementText('#val-specific-kwh', en.specific_kwh_per_tonne + ' кВт*ч / т');
        setElementText('#val-steam-pressure', en.steam_pressure_bar + ' бар');

        // --- Блок В: Статусы оборудования ---
        if (eq && eq.nodes) {
            setElementText('#equip-health-summary', `${eq.running_count}/${eq.total_count} узлов в работе`);
        }

        // Обновление графиков
        FactoryCharts.updateCharts(data);

        // Предупреждение о потерях
        if (rz.status_class === 'status-danger') {
            showToast(`Внимание! Потери при подработке превысили норму: ${rz.conditioning_loss_pct}%`, true);
        } else if (pz.status_class === 'status-danger') {
            showToast(`Внимание! Производственные потери превысили норму: ${pz.production_loss_pct}%`, true);
        }
    }

    // Отрисовка воронки и показателей для выбранной культуры (Все, Семена, Масло, Шрот, Лузга)
    function renderLogisticsByCargo(cargoKey) {
        if (!cachedLogisticsData || !cachedLogisticsData.categories) return;
        const catData = cachedLogisticsData.categories[cargoKey] || cachedLogisticsData.categories['Все'] || cachedLogisticsData;

        setElementText('#funnel-queued-count', catData.queued !== undefined ? catData.queued : 0);
        setElementText('#funnel-entered-count', catData.entered !== undefined ? catData.entered : 0);
        setElementText('#funnel-weighing-count', catData.weighing !== undefined ? catData.weighing : 0);
        setElementText('#funnel-exited-count', catData.exited !== undefined ? catData.exited : 0);
        setElementText('#active-cargo-label', cargoKey);
        setElementText('#active-cargo-count-label', cargoKey);
        setElementText('#val-avg-stay-time', catData.avg_stay_text || formatMinutes(catData.avg_stay_minutes || 0));
        setElementText('#val-on-territory', 'На территории: ' + (catData.on_site !== undefined ? catData.on_site : ((catData.entered || 0) + (catData.weighing || 0))));
    }

    // Вспомогательные функции
    function setElementText(selector, text) {
        const el = document.querySelector(selector);
        if (el) el.textContent = text;
    }

    function formatNumber(num, decimals = 1) {
        if (num === undefined || num === null) return '0';
        return parseFloat(num).toLocaleString('ru-RU', {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals
        });
    }

    function formatMinutes(mins) {
        if (!mins) return '0 мин';
        const h = Math.floor(mins / 60);
        const m = mins % 60;
        if (h > 0 && m > 0) return `${h} ч ${m} мин`;
        if (h > 0) return `${h} ч`;
        return `${m} мин`;
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

    // Запуск таймера
    startRefreshTimer();
});
