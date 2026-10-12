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
    }

    // 2. Обробники кліку на фільтр періоду
    periodButtons.forEach(btn => {
        btn.addEventListener('click', function () {
            const period = this.getAttribute('data-period');
            if (period === currentPeriod || isFetching) return;

            periodButtons.forEach(b => b.classList.remove('active'));
            this.classList.add('active');

            currentPeriod = period;
            if (typeof updateExportLink === 'function') updateExportLink();
            fetchDashboardData(true);
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

        // --- Блок А: Зона приймання та склад насіння ---
        const rz = mb.receiving_zone;
        setElementText('#val-seed-in-plant', formatNumber(rz.seed_in_plant) + ' т');
        setElementText('#val-seed-plan', 'План: ' + formatNumber(rz.seed_plan) + ' т (' + rz.plan_fulfillment_pct + '%)');
        
        if (data.warehouse_stocks) {
            const ws = data.warehouse_stocks;
            setElementText('#val-seed-stock', formatNumber(ws.seed_stock_tonnes) + ' т');
            setElementText('#badge-days-reserve', (ws.days_seed_reserve || 12.1) + ' дн. запасу');
            setElementText('#val-seed-stock-sub', `Облік: ${formatNumber(ws.seed_stock_accounting_tonnes)} т • Потреба: 600 т/д`);

            // Залишки готової продукції на складах
            setElementText('#val-stock-oil', formatNumber(ws.oil_stock_tonnes) + ' т');
            setElementText('#val-shipped-oil', `Відвантажено за добу: ${formatNumber(ws.oil_shipped_tonnes)} т`);

            setElementText('#val-stock-meal', formatNumber(ws.meal_stock_tonnes) + ' т');
            setElementText('#val-shipped-meal', `Відвантажено за добу: ${formatNumber(ws.meal_shipped_tonnes)} т`);

            setElementText('#val-stock-husk', formatNumber(ws.husk_stock_tonnes) + ' т');
            setElementText('#val-shipped-husk', `Відвантажено за добу: ${formatNumber(ws.husk_shipped_tonnes)} т`);

            setElementText('#val-boiler-husk', formatNumber(ws.husk_boiler_spent_tonnes) + ' т');
        }

        const condLossBadge = document.querySelector('#badge-cond-loss');
        if (condLossBadge) {
            condLossBadge.textContent = 'Підробка: ' + rz.conditioning_loss_pct + '%';
            condLossBadge.className = 'kpi-badge ' + rz.status_class;
        }

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
        
        const prodLossBadge = document.querySelector('#badge-prod-loss');
        if (prodLossBadge) {
            prodLossBadge.textContent = pz.production_loss_pct + '%';
            prodLossBadge.className = 'kpi-badge ' + pz.status_class;
        }

        // Легенда виходу продукції
        setElementText('#legend-oil-val', formatNumber(pz.oil_out) + ' т');
        setElementText('#legend-oil-pct', mb.yield_chart.percentages[0] + '%');

        setElementText('#legend-meal-val', formatNumber(pz.meal_out) + ' т');
        setElementText('#legend-meal-pct', mb.yield_chart.percentages[1] + '%');

        setElementText('#legend-husk-val', formatNumber(pz.husk_out) + ' т');
        setElementText('#legend-husk-pct', mb.yield_chart.percentages[2] + '%');

        setElementText('#legend-loss-val', formatNumber(pz.production_loss) + ' т');
        setElementText('#legend-loss-pct', mb.yield_chart.percentages[3] + '%');

        // --- Блок Б: Логістика та воронка (Насіння, Олія, Шрот, Лузга) ---
        cachedLogisticsData = log;
        if (log && log.categories) {
            const cats = log.categories;
            const seedCat = cats['Насіння'] || cats['Семена'];
            if (seedCat) {
                setElementText('#val-stay-seed', seedCat.avg_stay_text);
                const cnt = seedCat.on_site !== undefined ? seedCat.on_site : (seedCat.total_active || 0);
                setElementText('#val-cnt-seed', `Зараз на заводі: ${cnt} авто`);
            }
            const oilCat = cats['Олія'] || cats['Масло'];
            if (oilCat) {
                setElementText('#val-stay-oil', oilCat.avg_stay_text);
                const cnt = oilCat.on_site !== undefined ? oilCat.on_site : (oilCat.total_active || 0);
                setElementText('#val-cnt-oil', `Зараз на заводі: ${cnt} авто`);
            }
            const mealCat = cats['Шрот'];
            if (mealCat) {
                setElementText('#val-stay-meal', mealCat.avg_stay_text);
                const cnt = mealCat.on_site !== undefined ? mealCat.on_site : (mealCat.total_active || 0);
                setElementText('#val-cnt-meal', `Зараз на заводі: ${cnt} авто`);
            }
            const huskCat = cats['Лузга'];
            if (huskCat) {
                setElementText('#val-stay-husk', huskCat.avg_stay_text);
                const cnt = huskCat.on_site !== undefined ? huskCat.on_site : (huskCat.total_active || 0);
                setElementText('#val-cnt-husk', `Зараз на заводі: ${cnt} авто`);
            }
        }
        renderLogisticsByCargo(currentCargo);

        // --- Блок В: Енергетика ---
        setElementText('#val-current-power', formatNumber(en.current_power_kw, 0) + ' кВт');
        setElementText('#val-total-kwh', formatNumber(en.total_kwh, 0) + ' кВт*год');
        setElementText('#val-water-flow', formatNumber(en.current_water_m3_hour, 1) + ' м³/год');
        setElementText('#val-water-total', formatNumber(en.total_water_m3, 0) + ' м³');
        setElementText('#val-specific-kwh', en.specific_kwh_per_tonne + ' кВт*год / т');
        setElementText('#val-steam-pressure', en.steam_pressure_bar + ' бар');

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

                const res = await response.json();

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
