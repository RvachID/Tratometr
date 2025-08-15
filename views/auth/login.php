<?php
use yii\helpers\Html;
use yii\helpers\Url;

$this->title = 'Вход';
?>
<div class="container mt-4" style="max-width:480px">
    <h1 class="h4 mb-3 text-center"><?= Html::encode($this->title) ?></h1>

    <form method="post" action="<?= Url::to(['auth/login']) ?>">
        <?= Html::hiddenInput(Yii::$app->request->csrfParam, Yii::$app->request->getCsrfToken()) ?>

        <div class="mb-3">
            <label class="form-label">E-mail</label>
            <input type="email" name="email" class="form-control" required autocomplete="username" placeholder="you@example.com">
        </div>

        <div class="mb-3">
            <label class="form-label">Пароль</label>
            <input type="password" name="password" class="form-control" required autocomplete="current-password" placeholder="Ваш пароль">
        </div>

        <button type="submit" class="btn btn-outline-secondary w-100">Войти</button>
    </form>
</div>
