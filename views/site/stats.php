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
            <button class="btn btn-primary btn-sm w-100">Применить</button>
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
    (function() {
        const form = document.getElementById('stats-form');
        const ctx  = document.getElementById('statsChart').getContext('2d');
        let chart;

        async function loadAndRender() {
            const url = new URL('<?= Url::to(['site/stats-data'], true) ?>');
            const fd  = new FormData(form);
            for (const [k, v] of fd.entries()) url.searchParams.append(k, v);

            const res = await fetch(url.toString(), {headers: {'Accept': 'application/json'}});
            const json = await res.json();
            if (!json.ok) return;

            const data = {
                labels: json.labels,
                datasets: [{
                    label: 'Расходы, ₽',
                    data: json.values,
                    borderWidth: 2,
                    tension: 0.25,
                    fill: true
                }]
            };
            const opts = {
                responsive: true,
                scales: {
                    y: {beginAtZero: true}
                },
                plugins: {
                    legend: {display: false},
                    tooltip: {
                        callbacks: {
                            label: (ctx) => ' ' + (ctx.parsed.y ?? 0).toFixed(2) + ' ₽'
                        }
                    }
                }
            };

            if (chart) chart.destroy();
            chart = new Chart(ctx, {type: 'line', data, options: opts});
        }

        form.addEventListener('submit', function(e) {
            e.preventDefault();
            loadAndRender();
            // и обновим URL (чтобы шарился фильтр)
            const params = new URLSearchParams(new FormData(form)).toString();
            window.history.replaceState(null, '', '<?= Url::to(['site/stats']) ?>' + (params ? '?' + params : ''));
        });

        // первый рендер
        loadAndRender();
    })();
</script>
