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
            <canvas id="statsChart" height="150"></canvas>
        </div>
    </div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
    (function () {
        const form = document.getElementById('stats-form');
        const ctx  = document.getElementById('statsChart').getContext('2d');
        let chart;

        async function loadAndRender() {
            const api = '<?= \yii\helpers\Url::to(['site/stats-data']) ?>'; // index.php?r=site%2Fstats-data
            const url = new URL(api, window.location.origin);

            // добавляем параметры формы (даты + категории[]), r не трогаем
            const fd = new FormData(form);
            for (const [k, v] of fd.entries()) url.searchParams.append(k, v);

            try {
                const res  = await fetch(url.toString(), {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    cache: 'no-store'
                });

                const text = await res.text();
                let json;
                try { json = JSON.parse(text); }
                catch (e) { console.error('stats-data вернул не JSON:', text.slice(0, 300)); return; }

                if (!json.ok) { console.warn('stats-data not ok', json); return; }

                const total = json.values.reduce((a, b) => a + b, 0);

                const data = {
                    labels: json.labels,        // категории
                    datasets: [{
                        label: 'Доли расходов, ₽',
                        data: json.values         // суммы по категориям в ₽
                    }]
                };

                const opts = {
                    responsive: true,
                    cutout: '55%',              // делает donut
                    plugins: {
                        legend: { position: 'bottom' },
                        tooltip: {
                            callbacks: {
                                label: (ctx) => {
                                    const val = Number(ctx.parsed);
                                    const p = total ? (val / total * 100).toFixed(1) : '0.0';
                                    return ` ${val.toFixed(2)} ₽ (${p}%)`;
                                }
                            }
                        }
                    }
                };

                if (chart) chart.destroy();
                chart = new Chart(ctx, { type: 'doughnut', data, options: opts });

                if (total === 0) console.info('Нет данных за выбранный период');
            } catch (err) {
                console.error('Failed to load stats:', err);
            }
        }


        form.addEventListener('submit', function (e) {
            e.preventDefault();
            loadAndRender();
            // обновляем адрес (но не уходим со страницы)
            const base   = '<?= \yii\helpers\Url::to(['site/stats']) ?>';
            const params = new URLSearchParams(new FormData(form)).toString();
            history.replaceState(null, '', base + (params ? '?' + params : ''));
        });

        // первый рендер
        loadAndRender();
    })();
</script>

