<h1>Вход</h1>
<form method="post">
    <input type="hidden" name="_csrf" value="<?= Yii::$app->request->getCsrfToken() ?>">
    Email: <input type="email" name="email" required><br>
    PIN: <input type="password" name="pin_code" required><br>
    <button type="submit">Войти</button>
</form>
