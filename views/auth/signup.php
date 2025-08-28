<?php

use yii\helpers\Html;
use yii\widgets\ActiveForm;

/** @var $model app\models\SignupForm */
$this->title = 'Регистрация';
?>
<div class="container mt-4" style="max-width:480px">
    <h1 class="h4 mb-3 text-center"><?= Html::encode($this->title) ?></h1>

    <?php $form = ActiveForm::begin([
        'id' => 'signup-form',
        'fieldConfig' => [
            'options' => ['class' => 'mb-3'],
            'labelOptions' => ['class' => 'form-label small text-muted'],
            'inputOptions' => ['class' => 'form-control'],
            'errorOptions' => ['class' => 'invalid-feedback d-block'],
        ],
    ]); ?>

    <?= $form->field($model, 'email')
        ->input('email', [
            'autocomplete' => 'username',
            'placeholder'  => 'you@example.com',
        ]) ?>

    <?= $form->field($model, 'password')
        ->passwordInput([
            'autocomplete' => 'new-password',
            'placeholder'  => 'Минимум 8 символов, буквы и цифры',
        ]) ?>

    <?= $form->field($model, 'password_repeat')
        ->passwordInput([
            'autocomplete' => 'new-password',
            'placeholder'  => 'Повторите пароль',
        ]) ?>

    <!-- Таймзона пользователя (IANA), попадёт в POST -->
    <?= Html::hiddenInput('tz', '', ['id' => 'tz-field']) ?>

    <?= Html::submitButton('Зарегистрироваться', ['class' => 'btn btn-outline-secondary w-100']) ?>

    <?php ActiveForm::end(); ?>
</div>

<?php

$js = <<<JS
(function(){
  try{
    var tz = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
    var fld = document.getElementById('tz-field');
    if (fld) fld.value = tz;

    // Держим куку на будущее (не критично, но полезно)
    if (tz && document.cookie.indexOf('tz=') === -1) {
      document.cookie = 'tz=' + tz + ';path=/;max-age=' + (60*60*24*365) + ';SameSite=Lax';
    }
  }catch(e){}
})();
JS;
$this->registerJs($js);
?>
