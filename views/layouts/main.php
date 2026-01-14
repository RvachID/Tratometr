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
        'brandUrl' => '/site/index',
        'options' => ['class' => 'navbar-expand-md navbar-dark bg-dark fixed-top'],
    ]);

    // Левое меню (как было)
    echo Nav::widget([
        'options' => ['class' => 'navbar-nav'],
        'items' => [
            ['label' => 'История', 'url' => ['/site/history']],
            ['label' => 'Статистика', 'url' => ['/site/stats']],
            ['label' => 'Список покупок', 'url' => ['/alice-item/index']],
            ['label' => 'О проекте', 'url' => ['/site/about']],
            Yii::$app->user->isGuest
                ? ['label' => 'Авторизоваться', 'url' => ['/auth/login']]
                : '<li class="nav-item">'
                . Html::beginForm(['/auth/logout'], 'post', ['class' => 'd-inline'])
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
        <div class="row justify-content-center text-muted">
            <div class="col-auto text-center">
                &copy; Rvach_dev <?= date('Y') ?> —
                версия <?= \yii\helpers\Html::encode(Yii::$app->params['version'] ?? '') ?>
            </div>
        </div>
    </div>
</footer>
<script>
    (function () {
        const LIFETIME = 3000;  // мс
        const STAGGER = 150;   // «лесенка» между несколькими алертами

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
            el.classList.add('fade', 'show');
            closeAlert(el, LIFETIME + i * STAGGER);
        });

        // если алерт появится позже (ajax/pjax) — тоже закроем
        const mo = new MutationObserver(muts => {
            muts.forEach(m => {
                m.addedNodes.forEach(n => {
                    if (!(n instanceof HTMLElement)) return;
                    if (n.classList && n.classList.contains('alert')) {
                        n.classList.add('fade', 'show');
                        closeAlert(n, LIFETIME);
                    }
                    n.querySelectorAll?.('.alert').forEach(el => {
                        el.classList.add('fade', 'show');
                        closeAlert(el, LIFETIME);
                    });
                });
            });
        });
        mo.observe(document.body, {childList: true, subtree: true});
    })();

    (function () {
        const toggler = document.querySelector('.navbar-toggler');
        if (!toggler) return;

        // Определяем цель из data-bs-target или берём первый .navbar-collapse
        const targetSel = toggler.getAttribute('data-bs-target') || toggler.getAttribute('data-target') || '.navbar-collapse';
        const collapseEl = document.querySelector(targetSel);
        if (!collapseEl) return;

        const hasBS = !!(window.bootstrap && bootstrap.Collapse);
        const control = hasBS ? bootstrap.Collapse.getOrCreateInstance(collapseEl, {toggle: false}) : null;

        const isOpen = () => collapseEl.classList.contains('show');
        const openUI = () => document.body.classList.add('nav-open');
        const closeUI = () => document.body.classList.remove('nav-open');
        const closeMenu = () => {
            if (!isOpen()) return;
            if (control) control.hide(); else collapseEl.classList.remove('show');
            closeUI();
        };

        // Поддерживаем Bootstrap-события (если подключён JS Bootstrap)
        collapseEl.addEventListener('shown.bs.collapse', openUI);
        collapseEl.addEventListener('hidden.bs.collapse', closeUI);

        // 1) Клик ВНЕ меню закрывает его. Клики ВНУТРИ — игнорируем.
        document.addEventListener('click', (e) => {
            if (!isOpen()) return;
            if (e.target.closest(targetSel)) return;            // внутри меню — ничего не делаем
            if (e.target.closest('.navbar-toggler')) return;    // по кнопке-бургеру — Bootstrap сам разрулит
            closeMenu();
        }, true); // capture, чтобы сработать до перехода по ссылке

        // 2) Esc закрывает
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeMenu();
        });

        // 3) (опционально) якорные ссылки внутри меню можно закрывать сразу
        collapseEl.addEventListener('click', (e) => {
            const a = e.target.closest('a[href^="#"]');
            if (a) closeMenu();
        });

        // 4) При ресайзе убираем фиксацию скролла, если меню уже закрыто
        window.addEventListener('resize', () => {
            if (!isOpen()) closeUI();
        });
    })();

    (function () {
        try {
            var tz = Intl.DateTimeFormat().resolvedOptions().timeZone; // напр. "Europe/Belgrade"
            if (!tz) return;

            // НЕ кодируем! '/' в cookie допустим, поэтому храним как есть
            var has = document.cookie.split('; ').some(function (c) {
                return c.indexOf('tz=') === 0;
            });
            if (!has) {
                document.cookie = 'tz=' + tz + ';path=/;max-age=' + (60 * 60 * 24 * 365) + ';SameSite=Lax';
            }
        } catch (e) {
        }
    })();


</script>
<?php $this->registerJs(
'window.AliceOptions = ' . json_encode($aliceOptions ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';',
\yii\web\View::POS_HEAD
);

$this->registerJsFile('@web/js/common.js', [
'depends' => [\yii\bootstrap5\BootstrapPluginAsset::class], // <= важно
'position' => \yii\web\View::POS_END
]);
$this->registerJsFile('@web/js/entries.js', [
'depends' => [\yii\bootstrap5\BootstrapPluginAsset::class],
'position' => \yii\web\View::POS_END
]);
$this->registerJsFile('@web/js/scanner.js', [
'depends' => [\yii\bootstrap5\BootstrapPluginAsset::class],
'position' => \yii\web\View::POS_END
]);
 $this->endBody() ?>
</body>
</html>
<?php $this->endPage() ?>
