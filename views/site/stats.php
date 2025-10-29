<?php
/** @var string $dateFrom */
/** @var string $dateTo */
/** @var array  $allCats */
/** @var array  $selectedCats */

use yii\helpers\Html;
use yii\helpers\Url;

$this->title = 'Статистика';
?>

<div class="container mt-3">
    <h1 class="h4 mb-3">Итоги расходов</h1>

    <form id="stats-form" class="row g-2 align-items-end mb-3" action="<?= Url::to(['site/stats']) ?>" method="get">
        <div class="col-6 col-sm-3">
            <label class="form-label small text-muted mb-1">С даты</label>
            <input type="date" name="date_from" class="form-control form-control-sm"
                   value="<?= Html::encode($dateFrom) ?>">
        </div>
        <div class="col-6 col-sm-3">
            <label class="form-label small text-muted mb-1">По дату</label>
            <input type="date" name="date_to" class="form-control form-control-sm"
                   value="<?= Html::encode($dateTo) ?>">
        </div>

        <div class="col-12 col-sm-4">
            <label class="form-label small text-muted mb-1 d-block">Категории</label>
            <div id="stats-categories" class="d-flex flex-wrap gap-2">
                <?php if (!empty($allCats)): ?>
                    <?php foreach ($allCats as $cat): ?>
                        <label class="form-check form-check-inline small">
                            <input class="form-check-input" type="checkbox" name="categories[]"
                                   value="<?= Html::encode($cat) ?>"
                                <?= in_array($cat, $selectedCats, true) ? 'checked' : '' ?>>
                            <span class="form-check-label"><?= Html::encode($cat) ?></span>
                        </label>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-muted small">Категории за выбранный период отсутствуют</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-12 col-sm-2">
            <button class="btn btn-outline-secondary w-100 btn-sm" type="submit">Показать</button>
        </div>
    </form>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <canvas id="statsChart" height="150" class="d-none"></canvas>
            <div id="statsEmpty" class="text-center py-5 small">
                <div class="fw-semibold" style="color:#7C4F35">Данных пока нет: попробуйте выбрать другой период</div>
                <div class="text-muted" style="color:#A98467">Измените диапазон дат или категории, чтобы увидеть статистику</div>
            </div>
        </div>
    </div>

    <p class="text-center mt-2 small text-body">
        Обновление статистики может занимать до нескольких секунд
    </p>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>
<script>
    (function () {
        const form = document.getElementById('stats-form');
        if (!form) {
            return;
        }

        const categoriesWrap = document.getElementById('stats-categories');
        const canvasEl = document.getElementById('statsChart');
        const ctx = canvasEl.getContext('2d');
        const emptyEl = document.getElementById('statsEmpty');
        const apiUrl = '<?= Url::to(['site/stats-data']) ?>';
        const noCategoriesHtml = '<div class="text-muted small">Категории за выбранный период отсутствуют</div>';

        let chart;

        if (typeof ChartDataLabels !== 'undefined') {
            Chart.register(ChartDataLabels);
        }

        const escapeHtml = (str) => String(str).replace(/[&<>"']/g, (ch) => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        })[ch]);

        function appendFormDataToUrl(url, formEl) {
            const fd = new FormData(formEl);
            let hasCategoryParam = false;

            for (const [key, value] of fd.entries()) {
                url.searchParams.append(key, value);
                if (key === 'categories[]' || key === 'categories') {
                    hasCategoryParam = true;
                }
            }

            const catInputs = formEl.querySelectorAll('input[name="categories[]"]');
            const checked = formEl.querySelectorAll('input[name="categories[]"]:checked');
            if (catInputs.length > 0 && checked.length === 0 && !hasCategoryParam) {
                url.searchParams.append('categories', '');
            }
        }

        function renderCategories(allCats, selectedCats) {
            if (!categoriesWrap) {
                return;
            }
            const cats = Array.isArray(allCats) ? allCats : [];
            const selected = new Set(Array.isArray(selectedCats) ? selectedCats : []);

            if (cats.length === 0) {
                categoriesWrap.innerHTML = noCategoriesHtml;
                return;
            }

            const html = cats.map((cat) => {
                const safe = escapeHtml(cat);
                const checked = selected.has(cat) ? 'checked' : '';
                return `<label class="form-check form-check-inline small">
    <input class="form-check-input" type="checkbox" name="categories[]" value="${safe}" ${checked}>
    <span class="form-check-label">${safe}</span>
</label>`;
            }).join('');

            categoriesWrap.innerHTML = html;
        }

        function showEmpty(mainText = 'Данных пока нет: попробуйте выбрать другой период',
                           subText = 'Измените диапазон дат или категории, чтобы увидеть статистику') {
            canvasEl.classList.add('d-none');
            emptyEl.classList.remove('d-none');
            emptyEl.innerHTML = `<div class="fw-semibold" style="color:#7C4F35">${mainText}</div>` +
                `<div class="text-muted" style="color:#A98467">${subText}</div>`;
        }

        function showChart() {
            emptyEl.classList.add('d-none');
            canvasEl.classList.remove('d-none');
        }

        const hexToRgba = (hex, alpha) => {
            const clean = hex.replace('#', '');
            const r = parseInt(clean.slice(0, 2), 16);
            const g = parseInt(clean.slice(2, 4), 16);
            const b = parseInt(clean.slice(4, 6), 16);
            return `rgba(${r},${g},${b},${alpha})`;
        };

        const labelColor = (rgba) => {
            const match = rgba.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/i);
            if (!match) {
                return '#000';
            }
            const [r, g, b] = [parseInt(match[1]), parseInt(match[2]), parseInt(match[3])];
            const L = 0.2126 * r + 0.7152 * g + 0.0722 * b;
            return L < 140 ? '#fff' : '#3b2b1a';
        };

        async function loadAndRender() {
            const url = new URL(apiUrl, window.location.origin);
            appendFormDataToUrl(url, form);

            try {
                const response = await fetch(url.toString(), {credentials: 'same-origin'});
                if (!response.ok) {
                    throw new Error(`Network error ${response.status}`);
                }

                const json = await response.json();
                if (!json || json.ok !== true) {
                    renderCategories([], []);
                    showEmpty();
                    return;
                }

                renderCategories(json.categories || [], json.selectedCategories || []);

                const labels = Array.isArray(json.labels) ? json.labels : [];
                const values = Array.isArray(json.values) ? json.values.map(Number) : [];

                if (!labels.length || !values.length) {
                    showEmpty('Нет данных для выбранных условий');
                    return;
                }

                const total = values.reduce((sum, v) => sum + (Number.isFinite(v) ? v : 0), 0);
                if (!total) {
                    showEmpty('Сумма по выбранным категориям равна нулю');
                    return;
                }

                const palette = ['#F7C59F', '#A98467', '#8A5A44', '#7C4F35', '#DBA37A', '#EED7C5', '#F1DEC6', '#C68B59'];
                const background = labels.map((_, idx) => palette[idx % palette.length]);
                const hover = background.map((color) => hexToRgba(color, 0.85));

                const data = {
                    labels,
                    datasets: [{
                        label: 'Сумма расходов, ₽',
                        data: values,
                        backgroundColor: background,
                        hoverBackgroundColor: hover,
                        borderColor: '#FFFFFF',
                        borderWidth: 1,
                        hoverOffset: 4
                    }]
                };

                const options = {
                    responsive: true,
                    plugins: {
                        legend: {position: 'bottom', labels: {color: '#7C4F35'}},
                        tooltip: {
                            callbacks: {
                                label: (ctx) => {
                                    const val = Number(ctx.parsed) || 0;
                                    const percent = total ? (val / total * 100).toFixed(1) : '0.0';
                                    return ` ${ctx.label}: ${val.toFixed(2)} ₽ (${percent}%)`;
                                }
                            }
                        },
                        datalabels: {
                            formatter: (value, ctx) => {
                                const percent = total ? (value / total * 100) : 0;
                                return percent >= 6
                                    ? `${ctx.chart.data.labels[ctx.dataIndex]} ${percent.toFixed(0)}%`
                                    : `${percent.toFixed(0)}%`;
                            },
                            color: (ctx) => labelColor(ctx.dataset.backgroundColor[ctx.dataIndex]),
                            font: {weight: 600, size: 11},
                            anchor: (ctx) => {
                                const percent = total ? (ctx.dataset.data[ctx.dataIndex] / total * 100) : 0;
                                return percent < 6 ? 'end' : 'center';
                            },
                            align: (ctx) => {
                                const percent = total ? (ctx.dataset.data[ctx.dataIndex] / total * 100) : 0;
                                return percent < 6 ? 'end' : 'center';
                            },
                            offset: (ctx) => {
                                const percent = total ? (ctx.dataset.data[ctx.dataIndex] / total * 100) : 0;
                                return percent < 6 ? 8 : 0;
                            },
                            clamp: true,
                            clip: false
                        }
                    }
                };

                showChart();
                if (chart) {
                    chart.destroy();
                }
                chart = new Chart(ctx, {type: 'pie', data, options});
            } catch (error) {
                console.error('Failed to load stats:', error);
                showEmpty('Не удалось загрузить статистику');
            }
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            loadAndRender();

            const baseHref = '<?= Url::to(['site/stats']) ?>';
            const url = new URL(baseHref, window.location.origin);
            appendFormDataToUrl(url, form);
            history.replaceState(null, '', url.toString());
        });

        loadAndRender();
    })();
</script>