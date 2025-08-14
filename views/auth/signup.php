<?php

use yii\helpers\Html;
use yii\widgets\ActiveForm;

$this->title = 'Регистрация';
?>

<h1><?= Html::encode($this->title) ?></h1>

<?php $form = ActiveForm::begin(); ?>
<?= $form->field($model, 'email')->input('email') ?>
<?= $form->field($model, 'pin_code')->passwordInput() ?>
<div class="form-group">
    <?= Html::submitButton('Зарегистрироваться', ['class' => 'btn btn-outline-secondary']) ?>
</div>
<?php ActiveForm::end(); ?>
