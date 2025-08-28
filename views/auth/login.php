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
                <strong>Я считаю траты до оплаты.</strong><br>
                Сканируйте ценники до покупки и контролируйте бюджет в моменте, а не по факту.<br>
            </p>

            <a class="small link-brand" href="<?= \yii\helpers\Url::to(['/site/about']) ?>">Подробнее о проекте →</a>
        </div>
    </div>

    <h2 class="h5 mb-3 text-center"><?= Html::encode($this->title) ?></h2>

    <!-- Форма входа -->
    <form method="post" action="<?= Url::to(['auth/login']) ?>" novalidate>
        <?= Html::hiddenInput(Yii::$app->request->csrfParam, Yii::$app->request->getCsrfToken()) ?>

        <!-- антибот: отметка времени рендера + honeypot -->
        <?= Html::hiddenInput('render_ts', time()) ?>
        <input type="text" name="hp" value="" style="display:none" tabindex="-1" autocomplete="off" aria-hidden="true">

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

        <?= Html::hiddenInput('tz', '', ['id' => 'tz-field']) ?>
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
<script>
    (function(){
        try{
            var tz = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
            var fld = document.getElementById('tz-field');
            if (fld) fld.value = tz;
            // держим куку на будущее (не критично, но полезно)
            if (tz && document.cookie.indexOf('tz=') === -1) {
                document.cookie = 'tz=' + tz + ';path=/;max-age='+(60*60*24*365)+';SameSite=Lax';
            }
        }catch(e){}
    })();
</script>
