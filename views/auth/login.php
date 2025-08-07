<?php

use yii\helpers\Html;
use yii\helpers\Url;

$this->title = 'Вход';
?>

<div class="container mt-5" style="max-width: 400px;">
    <h2 class="mb-4 text-center"><?= Html::encode($this->title) ?></h2>

    <?php if (Yii::$app->session->hasFlash('error')): ?>
        <div class="alert alert-danger">
            <?= Yii::$app->session->getFlash('error') ?>
        </div>
    <?php endif; ?>

    <form method="post" action="<?= Url::to(['auth/login']) ?>">
        <?= Html::hiddenInput('_csrf', Yii::$app->request->getCsrfToken()) ?>

        <div class="mb-3">
            <label for="email" class="form-label">Email</label>
            <input type="email" name="email" class="form-control" id="email" required autofocus>
        </div>

        <div class="mb-3">
            <label for="pin_code" class="form-label">PIN</label>
            <input type="password" name="pin_code" class="form-control" id="pin_code" required pattern="\d{4,6}">
        </div>

        <div class="d-grid">
            <button type="submit" class="btn btn-primary">Войти</button>
        </div>
    </form>

    <p class="mt-3 text-center">
        Нет аккаунта? <?= Html::a('Зарегистрироваться', ['auth/signup']) ?>
    </p>
</div>
