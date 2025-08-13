<?php
use yii\helpers\Html;
use yii\helpers\Url;
/** @var string $store */
/** @var string $category */
$this->title = 'Продолжить покупки?';
?>
<div class="container mt-3 text-center">
    <h2>Продолжить покупки в «<?= Html::encode($store) ?>»?</h2>
    <div class="text-muted mb-3"><?= Html::encode($category) ?></div>

    <form method="post" action="<?= Url::to(['site/resume']) ?>" class="d-grid gap-2">
        <?= Html::hiddenInput(Yii::$app->request->csrfParam, Yii::$app->request->getCsrfToken()) ?>
        <button name="choice" value="continue" class="btn btn-primary">Да, продолжить</button>
        <button name="choice" value="new" class="btn btn-outline-secondary">Нет, начать новую</button>
    </form>
</div>
