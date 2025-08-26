<?php
use yii\helpers\Html;
use yii\helpers\Url;

$this->title = 'Вход';
?>
<div class="container mt-4" style="max-width:480px">

    <!-- Приветственный блок -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <div class="d-flex align-items-center mb-2">
                <div class="me-2">📊</div>
                <h1 class="h6 mb-0">Привет! Это Тратометр</h1>
            </div>
            <p class="text-muted small mb-2">
                Помогаю быстро фиксировать покупки и видеть, куда уходят деньги.
                Сканируй ценники/чеки или вводи вручную — статистика строится автоматически.
            </p>
            <a class="small" href="<?= Url::to(['/site/about']) ?>">Подробнее о проекте →</a>
        </div>
    </div>

    <h2 class="h5 mb-3 text-center"><?= Html::encode($this->title) ?></h2>

    <!-- Форма входа -->
    <form method="post" action="<?= Url::to(['auth/login']) ?>" novalidate>
        <?= Html::hiddenInput(Yii::$app->request->csrfParam, Yii::$app->request->getCsrfToken()) ?>

        <div class="mb-3">
            <label class="form-label small text-muted">E-mail</label>
            <input type="email"
                   name="email"
                   class="form-control"
                   required
                   autocomplete="username"
                   placeholder="you@example.com">
        </div>

        <div class="mb-3">
            <label class="form-label small text-muted">Пароль</label>
            <input type="password"
                   name="password"
                   class="form-control"
                   required
                   autocomplete="current-password"
                   placeholder="Ваш пароль">
        </div>

        <button type="submit" class="btn btn-outline-secondary w-100">Войти</button>
    </form>

    <!-- Разделитель и регистрация -->
    <div class="text-center my-3">
        <span class="text-muted small">или</span>
    </div>
    <a href="<?= Url::to(['/auth/signup']) ?>" class="btn btn-outline-secondary w-100">
        Зарегистрироваться
    </a>
</div>
