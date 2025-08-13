<?php
use yii\helpers\Html;
use yii\helpers\Url;
/** @var array $categories */
$this->title = 'Начать покупки';
?>
<div class="container mt-3 text-center">
    <h2>Начать покупки</h2>

    <form class="mt-3 text-start" method="post" action="<?= Url::to(['site/begin']) ?>">
        <?= Html::hiddenInput(Yii::$app->request->csrfParam, Yii::$app->request->getCsrfToken()) ?>

        <label class="form-label">Магазин</label>
        <input type="text" name="store" class="form-control mb-3" placeholder="Пятёрочка / Lidl / ..." required>

        <label class="form-label">Категория</label>
        <select name="category" class="form-select mb-3">
            <?php foreach ($categories as $c): ?>
                <option value="<?= Html::encode($c) ?>"><?= Html::encode($c) ?></option>
            <?php endforeach; ?>
        </select>

        <button type="submit" class="btn btn-success w-100">Перейти к сканнеру</button>
    </form>
</div>
