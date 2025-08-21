<?php

/** @var yii\web\View $this */

/** @var string $content */

use app\assets\AppAsset;
use app\widgets\Alert;
use yii\bootstrap5\Breadcrumbs;
use yii\bootstrap5\Html;
use yii\bootstrap5\Nav;
use yii\bootstrap5\NavBar;

AppAsset::register($this);

$this->registerCsrfMetaTags();
$this->registerMetaTag(['charset' => Yii::$app->charset], 'charset');
$this->registerMetaTag(['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, shrink-to-fit=no']);
$this->registerMetaTag(['name' => 'description', 'content' => $this->params['meta_description'] ?? '']);
$this->registerMetaTag(['name' => 'keywords', 'content' => $this->params['meta_keywords'] ?? '']);
$this->registerLinkTag(['rel' => 'icon', 'type' => 'image/x-icon', 'href' => Yii::getAlias('@web/favicon.ico')]);
?>
<?php $this->beginPage() ?>
<!DOCTYPE html>
<html lang="<?= Yii::$app->language ?>" class="h-100">
<head>
    <?= \yii\helpers\Html::csrfMetaTags() ?>
    <title><?= Html::encode($this->title) ?></title>
    <?php $this->head() ?>
</head>
<body class="d-flex flex-column h-100">
<?php $this->beginBody() ?>

<header id="header">
    <?php
    NavBar::begin([
        'brandLabel' => '<- На главную',
        'brandUrl'   => '/site/index',
        'options'    => ['class' => 'navbar-expand-md navbar-dark bg-dark fixed-top'],
    ]);

    // Левое меню (как было)
    echo Nav::widget([
        'options' => ['class' => 'navbar-nav'],
        'items' => [
            ['label' => 'История',  'url' => ['/site/history']],
            ['label' => 'Статистика',  'url' => ['/site/stats']],
            ['label' => 'О проекте','url' => ['/site/about']],
            Yii::$app->user->isGuest
                ? ['label' => 'Авторизоваться', 'url' => ['/auth/login']]
                : '<li class="nav-item">'
                . Html::beginForm(['/site/logout'], 'post', ['class' => 'd-inline'])
                . Html::submitButton(
                    'Выйти (' . explode('@', Yii::$app->user->identity->email)[0] . ')',
                    ['class' => 'nav-link btn btn-link logout']
                )
                . Html::endForm()
                . '</li>',
        ],
    ]);

    NavBar::end();
    ?>
</header>


<main id="main" class="flex-shrink-0" role="main">
    <div class="container">
        <?php if (!empty($this->params['breadcrumbs'])): ?>
            <?= Breadcrumbs::widget(['links' => $this->params['breadcrumbs']]) ?>
        <?php endif ?>
        <?= Alert::widget() ?>
        <?= $content ?>
    </div>
</main>

<footer id="footer" class="mt-auto py-3 bg-light">
    <div class="container">
        <div class="row text-muted">
            <div class="col-md-6 text-center text-md-start">
                &copy; Rvach_dev <?= date('Y') ?> — версия <?= Yii::$app->params['version'] ?>
            </div>
        </div>
    </div>
</footer>
<script>
    (function() {
        const LIFETIME = 3000;  // мс
        const STAGGER  = 150;   // «лесенка» между несколькими алертами

        function closeAlert(el, delay) {
            setTimeout(() => {
                if (window.bootstrap && bootstrap.Alert) {
                    bootstrap.Alert.getOrCreateInstance(el).close();
                } else {
                    el.classList.remove('show');
                    el.style.transition = 'opacity .25s ease';
                    el.style.opacity = '0';
                    setTimeout(() => el.remove(), 250);
                }
            }, delay);
        }

        // закрываем все уже отображённые
        document.querySelectorAll('.alert').forEach((el, i) => {
            el.classList.add('fade','show');
            closeAlert(el, LIFETIME + i * STAGGER);
        });

        // если алерт появится позже (ajax/pjax) — тоже закроем
        const mo = new MutationObserver(muts => {
            muts.forEach(m => {
                m.addedNodes.forEach(n => {
                    if (!(n instanceof HTMLElement)) return;
                    if (n.classList && n.classList.contains('alert')) {
                        n.classList.add('fade','show');
                        closeAlert(n, LIFETIME);
                    }
                    n.querySelectorAll?.('.alert').forEach(el => {
                        el.classList.add('fade','show');
                        closeAlert(el, LIFETIME);
                    });
                });
            });
        });
        mo.observe(document.body, { childList: true, subtree: true });
    })();
</script>

<?php $this->endBody() ?>
</body>
</html>
<?php $this->endPage() ?>
