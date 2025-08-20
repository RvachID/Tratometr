<?php
/** @var yii\web\View $this */
/** @var array $items */

use yii\helpers\Html;

$this->title = 'История';
$fmt = Yii::$app->formatter;
?>
<div class="container mt-3">
    <h2 class="mb-3">📜 История</h2>

    <?php if (empty($items)): ?>
        <div class="alert alert-light border">Записей пока нет.</div>
        <a href="<?= \yii\helpers\Url::to(['site/index']) ?>" class="btn btn-outline-secondary">← На главную</a>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                <tr>
                    <th style="width: 180px;">Дата и время</th>
                    <th>Магазин</th>
                    <th>Категория</th>
                    <th class="text-end" style="width: 220px;">Итого / До лимита</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $it): ?>
                    <?php
                    $lastTs   = (int)($it['last_ts'] ?? 0);
                    $dtStr    = $lastTs ? $fmt->asDatetime($lastTs, 'php:H:i d.m.Y') : '—';
                    $shop     = (string)($it['shop'] ?? '');
                    $cat      = (string)($it['category'] ?? '');
                    $total    = (float)($it['total_sum'] ?? 0);

                    // лимит хранится в копейках
                    $limitRub = isset($it['limit_amount']) && $it['limit_amount'] !== null
                        ? ((int)$it['limit_amount']) / 100
                        : null;

                    if ($limitRub !== null) {
                        $val   = $limitRub - $total; // остаток до лимита
                        $label = 'До лимита';
                        $class = $val < 0 ? 'text-danger fw-bold' : '';
                        $valStr = number_format($val, 2, '.', ' ');
                    } else {
                        $label = 'Итого';
                        $class = '';
                        $valStr = number_format($total, 2, '.', ' ');
                    }
                    ?>
                    <tr>
                        <td><?= Html::encode($dtStr) ?></td>
                        <td><?= Html::encode($shop) ?></td>
                        <td><?= Html::encode($cat) ?></td>
                        <td class="text-end">
                            <span class="text-muted me-1"><?= $label ?>:</span>
                            <strong class="<?= $class ?>"><?= $valStr ?></strong>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <a href="<?= \yii\helpers\Url::to(['site/index']) ?>" class="btn btn-outline-secondary mt-2">← На главную</a>
    <?php endif; ?>
</div>
