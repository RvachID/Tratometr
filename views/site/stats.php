<?php
/** @var string $dateFrom */
/** @var string $dateTo */
/** @var array $allCats */

/** @var array $selectedCats */

use yii\helpers\Html;
use yii\helpers\Url;

$this->title = 'Статистика';
?>

<div class="container mt-3">
    <h1 class="h4 mb-3">📈 Статистика</h1>

    <form id="stats-form" class="row g-2 align-items-end mb-3" action="<?= Url::to(['site/stats']) ?>" method="get">
        <div class="col-6 col-sm-3">
            <label class="form-label small text-muted mb-1">От</label>
            <input type="date" name="date_from" class="form-control form-control-sm"
                   value="<?= Html::encode($dateFrom) ?>">
        </div>
        <div class="col-6 col-sm-3">
            <label class="form-label small text-muted mb-1">До</label>
            <input type="date" name="date_to" class="form-control form-control-sm"
                   value="<?= Html::encode($dateTo) ?>">
        </div>

        <div class="col-12 col-sm-4">
            <label class="form-label small text-muted mb-1 d-block">Категории</label>
            <div class="d-flex flex-wrap gap-2">
                <?php foreach ($allCats as $cat): ?>
                    <label class="form-check form-check-inline small">
                        <input class="form-check-input" type="checkbox" name="categories[]"
                               value="<?= Html::encode($cat) ?>"
                            <?= in_array($cat, $selectedCats, true) ? 'checked' : '' ?>>
                        <span class="form-check-label"><?= Html::encode($cat) ?></span>
                    </label>
                <?php endforeach; ?>
                <?php if (empty($allCats)): ?>
                    <div class="text-muted small">Категории не найдены в выбранном периоде</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-12 col-sm-2">
            <button class="btn btn-outline-secondary w-100 btn-sm">Применить</button>
        </div>
    </form>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <!-- canvas и заглушка: показываем один из них -->
            <canvas id="statsChart" height="150" class="d-none"></canvas>
            <div id="statsEmpty" class="text-center py-5 small">
                <div class="fw-semibold" style="color:#7C4F35">Нет данных за выбранный период</div>
                <div class="text-muted" style="color:#A98467">Учитываются только завершённые сессии</div>
            </div>
        </div>
    </div>

    <p class="text-center mt-2 small" style="color:#000;">
        Нажмите на диаграмму для информации
    </p>

</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>
<script>
    (function () {
        const form = document.getElementById('stats-form');
        const ctx = document.getElementById('statsChart').getContext('2d');
        let chart;

        async function loadAndRender() {
            const formEl = document.getElementById('stats-form');
            const canvasEl = document.getElementById('statsChart');
            const emptyEl = document.getElementById('statsEmpty');

            const api = '<?= \yii\helpers\Url::to(['site/stats-data']) ?>'; // index.php?r=site%2Fstats-data
            const url = new URL(api, window.location.origin);

            const fd = new FormData(formEl);
            for (const [k, v] of fd.entries()) url.searchParams.append(k, v);

            // локальные помощники
            function showEmpty(msgMain = 'Нет данных за выбранный период', msgSub = 'Учитываются только завершённые сессии') {
                canvasEl.classList.add('d-none');
                emptyEl.classList.remove('d-none');
                emptyEl.innerHTML =
                    `<div class="fw-semibold" style="color:#7C4F35">${msgMain}</div>` +
                    `<div class="text-muted" style="color:#A98467">${msgSub}</div>`;
            }

            function showChart() {
                emptyEl.classList.add('d-none');
                canvasEl.classList.remove('d-none');
            }

            try {
                const res = await fetch(url.toString(), {
                    headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
                    cache: 'no-store'
                });
                const text = await res.text();
                let json;
                try {
                    json = JSON.parse(text);
                } catch {
                    showEmpty('Ошибка данных');
                    return;
                }
                if (!json.ok) {
                    showEmpty('Нет доступа');
                    return;
                }

                const total = (json.values || []).reduce((a, b) => a + b, 0);
                if (!json.values || json.values.length === 0 || total === 0) {
                    if (window.chart) {
                        window.chart.destroy();
                        window.chart = null;
                    }
                    showEmpty();
                    return;
                }

                // Палитра от САМЫХ СВЕТЛЫХ к тёмным
                const base = ['#F0DAB7', '#E3C59B', '#D1B280', '#C19A6B', '#B08D57', '#A98467', '#9C6B45', '#8C5A3C', '#7C4F35', '#5E3B29'];
                const fill = json.values.map((_, i) => hexToRgba(base[i % base.length], 0.95));
                const hover = json.values.map((_, i) => hexToRgba(base[i % base.length], 1.00));

                // Вспомогалка: вычислить читаемый цвет шрифта на фоне сектора
                const labelColor = (rgba) => {
                    const m = rgba.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/i);
                    if (!m) return '#000';
                    const [r, g, b] = [parseInt(m[1]), parseInt(m[2]), parseInt(m[3])];
                    const L = 0.2126 * r + 0.7152 * g + 0.0722 * b; // относительная яркость
                    return (L < 140) ? '#fff' : '#3b2b1a';
                };

                const data = {
                    labels: json.labels,
                    datasets: [{
                        label: 'Расходы, ₽',
                        data: json.values,
                        backgroundColor: fill,
                        hoverBackgroundColor: hover,
                        borderColor: '#FFFFFF',
                        borderWidth: 1,
                        hoverOffset: 4
                    }]
                };

                const opts = {
                    responsive: true,
                    plugins: {
                        legend: {position: 'bottom', labels: {color: '#7C4F35'}},
                        tooltip: {
                            callbacks: {
                                label: (ctx) => {
                                    const val = Number(ctx.parsed);
                                    const p = total ? (val / total * 100).toFixed(1) : '0.0';
                                    return ` ${ctx.label}: ${val.toFixed(2)} ₽ (${p}%)`;
                                }
                            }
                        },
                        // Подписи прямо на кусках
                        datalabels: {
                            formatter: (value, ctx) => {
                                const p = total ? (value / total * 100) : 0;
                                // Показываем «Название 45%» для значимых кусков, иначе – только %
                                return p >= 6 ? `${ctx.chart.data.labels[ctx.dataIndex]} ${p.toFixed(0)}%`
                                    : `${p.toFixed(0)}%`;
                            },
                            color: (ctx) => labelColor(ctx.dataset.backgroundColor[ctx.dataIndex]),
                            font: {weight: '600', size: 11},
                            // Для мелких долей чуть выносим подпись наружу
                            anchor: (ctx) => {
                                const v = ctx.dataset.data[ctx.dataIndex];
                                const p = total ? v / total * 100 : 0;
                                return p < 6 ? 'end' : 'center';
                            },
                            align: (ctx) => {
                                const v = ctx.dataset.data[ctx.dataIndex];
                                const p = total ? v / total * 100 : 0;
                                return p < 6 ? 'end' : 'center';
                            },
                            offset: (ctx) => {
                                const v = ctx.dataset.data[ctx.dataIndex];
                                const p = total ? v / total * 100 : 0;
                                return p < 6 ? 8 : 0;
                            },
                            clamp: true,
                            clip: false
                        }
                    }
                };

                showChart();
                if (window.chart) window.chart.destroy();
                window.chart = new Chart(canvasEl.getContext('2d'), {type: 'pie', data, options: opts});
            } catch (err) {
                console.error('Failed to load stats:', err);
                showEmpty('Не удалось загрузить данные');
            }

            // helpers
            function hexToRgba(hex, a) {
                const h = hex.replace('#', '');
                const r = parseInt(h.slice(0, 2), 16);
                const g = parseInt(h.slice(2, 4), 16);
                const b = parseInt(h.slice(4, 6), 16);
                return `rgba(${r},${g},${b},${a})`;
            }
        }


        form.addEventListener('submit', function (e) {
            e.preventDefault();
            loadAndRender();

            const baseHref = '<?= \yii\helpers\Url::to(['site/stats']) ?>';
            const u = new URL(baseHref, window.location.origin);

            const fd = new FormData(form);
            for (const [k, v] of fd.entries()) {
                u.searchParams.append(k, v);
            }

            history.replaceState(null, '', u.toString());
        });


        loadAndRender();
    })();
</script>

