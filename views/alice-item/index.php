<?php
/** @var yii\web\View $this */
/** @var app\models\AliceItem[] $items */

use yii\helpers\Html;
use yii\helpers\Url;

$this->title = 'Список покупок';
?>

<h1><?= Html::encode($this->title) ?></h1>

<hr>

<!-- ===== Добавление нового пункта ===== -->
<form method="post" action="<?= Url::to(['alice-item/create']) ?>" style="margin-bottom:20px;">
    <?= Html::hiddenInput(Yii::$app->request->csrfParam, Yii::$app->request->csrfToken) ?>

    <input
        type="text"
        name="title"
        placeholder="Добавить товар..."
        required
        style="width:300px;"
    >
    <button type="submit">Добавить</button>
</form>

<!-- ===== Список ===== -->
<table border="1" cellpadding="6" cellspacing="0" width="100%">
    <thead>
    <tr>
        <th width="40">✓</th>
        <th>Название</th>
        <th width="60">📌</th>
        <th width="140">Действия</th>
    </tr>
    </thead>
    <tbody>

    <?php foreach ($items as $item): ?>
        <tr style="<?= $item->is_done ? 'opacity:0.5;' : '' ?>">
            <!-- DONE -->
            <td align="center">
                <form method="post"
                      action="<?= Url::to(['alice-item/toggle-done', 'id' => $item->id]) ?>">
                    <?= Html::hiddenInput(Yii::$app->request->csrfParam, Yii::$app->request->csrfToken) ?>
                    <button type="submit">
                        <?= $item->is_done ? '☑' : '☐' ?>
                    </button>
                </form>
            </td>

            <!-- TITLE / EDIT -->
            <td>
                <form method="post"
                      action="<?= Url::to(['alice-item/update', 'id' => $item->id]) ?>">
                    <?= Html::hiddenInput(Yii::$app->request->csrfParam, Yii::$app->request->csrfToken) ?>

                    <input
                        type="text"
                        name="title"
                        value="<?= Html::encode($item->title) ?>"
                        style="width:100%;"
                    >
                </form>
            </td>

            <!-- PIN -->
            <td align="center">
                <form method="post"
                      action="<?= Url::to(['alice-item/toggle-pinned', 'id' => $item->id]) ?>">
                    <?= Html::hiddenInput(Yii::$app->request->csrfParam, Yii::$app->request->csrfToken) ?>
                    <button type="submit">
                        <?= $item->is_pinned ? '📌' : '—' ?>
                    </button>
                </form>
            </td>

            <!-- DELETE -->
            <td align="center">
                <form method="post"
                      action="<?= Url::to(['alice-item/delete', 'id' => $item->id]) ?>"
                      onsubmit="return confirm('Удалить пункт?');">
                    <?= Html::hiddenInput(Yii::$app->request->csrfParam, Yii::$app->request->csrfToken) ?>
                    <button type="submit">🗑</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>

    </tbody>
</table>
