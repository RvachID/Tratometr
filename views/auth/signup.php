<?php

use yii\helpers\Html;
use yii\widgets\ActiveForm;

/** @var $model app\models\SignupForm */
$this->title = 'Регистрация';
?>
<div class="container mt-4" style="max-width:480px">
    <h1 class="h4 mb-3 text-center"><?= Html::encode($this->title) ?></h1>

    <?php $form = ActiveForm::begin(['id' => 'signup-form']); ?>

    <?= $form->field($model, 'email')
        ->input('email', ['autocomplete' => 'username', 'placeholder' => 'you@example.com']) ?>

    <?= $form->field($model, 'password')
        ->passwordInput(['autocomplete' => 'new-password', 'placeholder' => 'Минимум 8 символов, буквы и цифры']) ?>

    <?= $form->field($model, 'password_repeat')
        ->passwordInput(['autocomplete' => 'new-password', 'placeholder' => 'Повторите пароль']) ?>

    <?= Html::submitButton('Зарегистрироваться', ['class' => 'btn btn-outline-secondary w-100']) ?>

    <?php ActiveForm::end(); ?>
</div>
