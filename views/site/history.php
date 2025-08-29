<?php
/** @var array $items */

use yii\helpers\Html;

$this->title = 'История';
$fmt = Yii::$app->formatter;

function rowValueAndLabel(array $r): array
{
    $hasLimit = $r['limit_amount'] !== null;

    // В рублях
    $limitRub = $hasLimit ? ((int)$r['limit_amount']) / 100 : null;
    $totalRub = ((int)$r['total_amount']) / 100; // для активных ниже перезапишем из sum_live

    if ((int)$r['status'] === 9) { // ЗАКРЫТА
        if ($hasLimit) {
            // Остаток пересчитываем напрямую, чтобы не зависеть от кэша limit_left
            $leftRub = (((int)$r['limit_amount']) - ((int)$r['total_amount'])) / 100;
            $value   = $leftRub;
            $label   = 'Лимит';
        } else {
            $value = $totalRub;
            $label = 'Итого';
        }
    } else { // АКТИВНА
        $sumLive = (float)$r['sum_live']; // здесь уже в рублях
        $totalRub = $sumLive;
        if ($hasLimit) {
            $value = $limitRub - $sumLive; // остаток в рублях
            $label = 'Лимит';
        } else {
            $value = $sumLive;
            $label = 'Итого';
        }
    }

    $isOver = $hasLimit && $value < 0;
    $ts = (int)$r['last_ts'];

    // Возвращаем: [основное_значение, ярлык, признак_перерасхода, ts, итоговая_сумма, лимит]
    return [$value, $label, $isOver, $ts, $totalRub, $limitRub];
}


?>
<div class="container mt-3">
    <h1 class="h4 mb-3">📝 История</h1>

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
                    <td><?= $label ?></td>
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
                        <button type="submit" class="btn btn-outline-secondary btn-sm">🗑 Удалить</button>
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
                            <div class="small text-muted"><?= $label ?></div>
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
                        <button type="submit" class="btn btn-outline-secondary btn-sm">🗑 Удалить</button>
                        <?= Html::endForm() ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
