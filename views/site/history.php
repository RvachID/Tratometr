<?php
/** @var array $items */
use yii\helpers\Html;

$this->title = 'История';
$fmt = Yii::$app->formatter;
?>
<div class="container mt-3">
    <h1 class="h4 mb-3">📜 История</h1>

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
            </tr>
            </thead>
            <tbody>
            <?php foreach ($items as $r):
                $sum        = (float)$r['total_sum'];                 // сумма по записям
                $limitCents = $r['limit_amount'];                     // NULL или целое (копейки)
                $hasLimit   = $limitCents !== null;
                $limitRub   = $hasLimit ? ((int)$limitCents)/100 : null;
                $value      = $hasLimit ? ($limitRub - $sum) : $sum;  // остаток / итого
                $label      = $hasLimit ? 'До лимита' : 'Итого';
                $isOver     = $hasLimit && $value < 0;
                $ts         = (int)$r['last_ts'];
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
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- < sm: карточки -->
    <div class="d-sm-none">
        <?php foreach ($items as $r):
            $sum        = (float)$r['total_sum'];
            $limitCents = $r['limit_amount'];
            $hasLimit   = $limitCents !== null;
            $limitRub   = $hasLimit ? ((int)$limitCents)/100 : null;
            $value      = $hasLimit ? ($limitRub - $sum) : $sum;
            $label      = $hasLimit ? 'До лимита' : 'Итого';
            $isOver     = $hasLimit && $value < 0;
            $ts         = (int)$r['last_ts'];
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
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <a class="btn btn-outline-secondary mt-3" href="<?= Html::encode(Yii::$app->homeUrl) ?>">← На главную</a>
</div>
