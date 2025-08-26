<?php
/** @var \yii\web\View $this */
/** @var \app\models\LoginForm $model */

use yii\helpers\Html;
use yii\helpers\Url;
use yii\bootstrap5\ActiveForm;

$this->title = 'Вход';
?>
<div class="container mt-3">

    <!-- Приветственный блок -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <div class="d-flex align-items-center mb-2">
                <div class="me-2">📊</div>
                <h1 class="h5 mb-0">Привет! Это Тратометр</h1>
            </div>
            <p class="text-muted small mb-2">
                Помогаю быстро фиксировать покупки и видеть, куда уходят деньги.
                Сканируй ценники/чеки или вводи вручную — статистика строится автоматически.
            </p>
            <a class="small" href="<?= Url::to(['/site/about']) ?>">Подробнее о проекте →</a>
        </div>
    </div>

    <!-- Авторизация + регистрация -->
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <h2 class="h6 mb-3">Войти</h2>

            <?php $form = ActiveForm::begin([
                'id' => 'login-form',
                'fieldConfig' => [
                    'options' => ['class' => 'mb-2'],
                    'inputOptions' => ['class' => 'form-control form-control-sm'],
                    'labelOptions' => ['class' => 'form-label small text-muted mb-1'],
                    'errorOptions' => ['class' => 'invalid-feedback d-block'],
                ],
            ]); ?>

            <?= $form->field($model, 'username')->textInput(['autofocus' => true, 'placeholder'=>'Логин или e-mail']) ?>
            <?= $form->field($model, 'password')->passwordInput(['placeholder'=>'Пароль']) ?>

            <div class="d-grid gap-2 mt-3">
                <button type="submit" class="btn btn-outline-secondary">Войти</button>
                <a class="btn btn-outline-secondary" href="<?= Url::to(['/auth/signup']) ?>">
                    Зарегистрироваться
                </a>
            </div>

            <?php ActiveForm::end(); ?>
        </div>
    </div>

</div>
