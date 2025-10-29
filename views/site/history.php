++ views/site/history.php
<?php
/** @var array $items */

use yii\helpers\Html;

$this->title = 'История';
$fmt = Yii::$app->formatter;

function rowValueAndLabel(array $r): array
{
    $hasLimit = $r['limit_amount'] !== null;

    $limitRub = $hasLimit ? ((int)$r['limit_amount']) / 100 : null;
    $totalRub = ((int)$r['total_amount']) / 100;

    if ((int)$r['status'] === 9) { // закрыта
        if ($hasLimit) {
            $leftRub = (((int)$r['limit_amount']) - ((int)$r['total_amount'])) / 100;
            $value   = $leftRub;
            $label   = 'Лимит';
        } else {
            $value = $totalRub;
            $label = 'Итого';
        }
    } else { // активна
        $sumLive = array_key_exists('sum_live', $r) ? (float)$r['sum_live'] : 0.0;
        $totalRub = $sumLive;
        if ($hasLimit) {
            $value = $limitRub - $sumLive;
            $label = 'Лимит';
        } else {
            $value = $sumLive;
            $label = 'Итого';
        }
    }

    $isOver = $hasLimit && $value < 0;

    $tsCandidates = [
        $r['last_ts']     ?? null,
        $r['closed_at']   ?? null,
        $r['updated_at']  ?? null,
        $r['started_at']  ?? null,
    ];

    $ts = 0;
    foreach ($tsCandidates as $candidate) {
        if ($candidate !== null && (int)$candidate > 0) {
            $ts = (int)$candidate;
            break;
        }
    }

    return [$value, $label, $isOver, $ts, $totalRub, $limitRub];
}

?>
<div class="container mt-3">

    <h1 class="h4 mb-3">История</h1>
    <!-- ≥ sm: таблица -->
    <div class="d-none d-sm-block">
        <table class="table table-sm align-middle">
            <thead>
            <tr>
                <th style="width:160px;">Дата и время</th>
                <th>Магазин</th>
                <th>Категория</th>
                <th style="width:110px;">Тип</th>
                <th class="text-end" style="width:140px;">Сумма</th>
                <th class="text-end" style="width:90px;">Действия</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($items as $r):
                [$value, $label, $isOver, $ts, $sumRub, $limitRub] = rowValueAndLabel($r);
                ?>
                <tr>
                    <td>
                        <?= $fmt->asTime($ts, 'php:H:i') ?><br>
                        <span class="text-muted small"><?= $fmt->asDate($ts, 'php:d.m.Y') ?></span>
                    </td>
                    <td><?= Html::encode($r['shop']) ?></td>
                    <td><?= Html::encode($r['category']) ?></td>
                    <td><?= Html::encode($label) ?></td>
                    <td class="text-end <?= $isOver ? 'text-danger fw-bold' : '' ?>">
                        <?= number_format($value, 2, '.', ' ') ?>
                        <?php if ($limitRub !== null): ?>
                            <div class="text-muted small">(<?= number_format($sumRub, 2, '.', ' ') ?>)</div>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <?= Html::beginForm(['site/delete-session', 'id' => (int)$r['id']], 'post', [
                            'onsubmit' => "return confirm('Удалить сессию и все её позиции? Это действие необратимо.');"
                        ]) ?>
                        <button type="submit" class="btn btn-outline-secondary btn-sm">Удалить</button>
                        <?= Html::endForm() ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- < sm: карточки -->
    <div class="d-sm-none">
        <?php foreach ($items as $r):
            [$value, $label, $isOver, $ts, $sumRub, $limitRub] = rowValueAndLabel($r);
            ?>
            <div class="card border-0 shadow-sm mb-2">
                <div class="card-body py-2">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="fw-semibold"><?= $fmt->asTime($ts, 'php:H:i') ?></div>
                            <div class="text-muted small"><?= $fmt->asDate($ts, 'php:d.m.Y') ?></div>
                            <div class="small mt-1">
                                <span class="fw-semibold"><?= Html::encode($r['shop']) ?></span>
                                <span class="text-muted"> · <?= Html::encode($r['category']) ?></span>
                            </div>
                        </div>
                        <div class="text-end">
                            <div class="small text-muted"><?= Html::encode($label) ?></div>
                            <div class="<?= $isOver ? 'text-danger fw-bold' : 'fw-semibold' ?>">
                                <?= number_format($value, 2, '.', ' ') ?>
                                <?php if ($limitRub !== null): ?>
                                    <div class="text-muted small">(<?= number_format($sumRub, 2, '.', ' ') ?>)</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end mt-2">
                        <?= Html::beginForm(['site/delete-session', 'id' => (int)$r['id']], 'post', [
                            'onsubmit' => "return confirm('Удалить сессию и все её позиции? Это действие необратимо.');"
                        ]) ?>
                        <button type="submit" class="btn btn-outline-secondary btn-sm">Удалить</button>
                        <?= Html::endForm() ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
