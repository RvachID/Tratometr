<?php
/** @var yii\web\View $this */
/** @var app\models\AliceUserLink|null $link */

use yii\helpers\Html;

$this->title = 'Привязка Алисы';
?>

<div class="container mt-3">
    <h1 class="h4 mb-3">Привязка Алисы</h1>

    <?php if ($link): ?>
        <div class="alert alert-success">
            Навык Алисы уже привязан к вашему аккаунту.
        </div>

        <?= Html::beginForm(['alice-link/unlink'], 'post') ?>
        <?= Html::hiddenInput(Yii::$app->request->csrfParam, Yii::$app->request->csrfToken) ?>
        <button type="submit" class="btn btn-outline-danger">
            Отключить привязку
        </button>
        <?= Html::endForm() ?>
    <?php else: ?>
        <p class="text-muted">
            Скажите колонке: «Алиса, запусти навык Умные траты», затем «привязать аккаунт».
            Алиса назовет код. Введите его здесь в течение 10 минут.
        </p>

        <?= Html::beginForm(['alice-link/index'], 'post', ['class' => 'card card-body']) ?>
        <?= Html::hiddenInput(Yii::$app->request->csrfParam, Yii::$app->request->csrfToken) ?>

        <label class="form-label" for="alice-link-code">Код из Алисы</label>
        <input
            id="alice-link-code"
            type="text"
            name="code"
            class="form-control mb-3"
            inputmode="numeric"
            pattern="\d{6}"
            maxlength="6"
            autocomplete="one-time-code"
            required
        >

        <button type="submit" class="btn btn-primary">
            Привязать
        </button>
        <?= Html::endForm() ?>
    <?php endif; ?>
</div>
