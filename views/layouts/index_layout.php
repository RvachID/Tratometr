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
        'brandLabel' => Yii::$app->name,
        'brandUrl'   => Yii::$app->homeUrl,
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
    <!-- Невидимая подложка для клика/свайпа вне меню -->
    <div id="navOverlay" class="nav-overlay" hidden></div>
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
        const LIFETIME = 7000;              // 3 сек
        const STAGGER  = 150;               // лёгкая «лесенка» при множестве алертов

        document.querySelectorAll('.alert').forEach((el, i) => {
            // на всякий случай гарантируем анимацию
            el.classList.add('fade','show');

            setTimeout(() => {
                if (window.bootstrap && bootstrap.Alert) {
                    bootstrap.Alert.getOrCreateInstance(el).close(); // корректно удалит
                } else {
                    // фолбэк без Bootstrap JS
                    el.style.transition = 'opacity .25s ease';
                    el.style.opacity = '0';
                    setTimeout(() => el.remove(), 250);
                }
            }, LIFETIME + i * STAGGER);
        });
    })();
    (function() {
        // Находим элементы
        const toggler = document.querySelector('.navbar-toggler');
        if (!toggler) return;

        // Определяем целевой collapse по data-bs-target у тогглера
        const targetSel = toggler.getAttribute('data-bs-target') || toggler.getAttribute('data-target');
        const collapseEl = targetSel ? document.querySelector(targetSel) : document.querySelector('.navbar-collapse');
        if (!collapseEl) return;

        const overlay = document.getElementById('navOverlay');
        const bsCollapse = (window.bootstrap && window.bootstrap.Collapse)
            ? bootstrap.Collapse.getOrCreateInstance(collapseEl, { toggle:false })
            : null;

        const isOpen = () => collapseEl.classList.contains('show');
        const openUI  = () => { overlay.hidden = false; document.body.classList.add('nav-open'); };
        const closeUI = () => { overlay.hidden = true;  document.body.classList.remove('nav-open'); };

        function closeMenu() {
            if (!isOpen()) return;
            if (bsCollapse) bsCollapse.hide();
            else collapseEl.classList.remove('show'); // фолбэк без bootstrap.js
            closeUI();
        }

        // Поддержка событий Bootstrap (если JS подключён)
        collapseEl.addEventListener('shown.bs.collapse', openUI);
        collapseEl.addEventListener('hidden.bs.collapse', closeUI);

        // Клик по подложке закрывает меню
        overlay.addEventListener('click', closeMenu);

        // Закрываем по ESC
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeMenu();
        });

        // Клик по ссылке в меню — закрыть (после перехода)
        collapseEl.addEventListener('click', (e) => {
            const a = e.target.closest('.nav-link, .dropdown-item, a[href]');
            if (a) closeMenu();
        });

        // Свайп вверх на подложке — закрыть
        let touchY0 = null, touchX0 = null, t0 = 0;
        overlay.addEventListener('touchstart', (e) => {
            const t = e.touches[0]; touchY0 = t.clientY; touchX0 = t.clientX; t0 = Date.now();
        }, {passive:true});
        overlay.addEventListener('touchend', (e) => {
            if (touchY0 === null) return;
            const t = e.changedTouches[0];
            const dy = touchY0 - t.clientY; // >0 — свайп вверх
            const dx = Math.abs((touchX0 ?? t.clientX) - t.clientX);
            const dt = Date.now() - t0;
            const isSwipeUp = dy > 50 && dx < 40 && dt < 600; // пороги
            touchY0 = touchX0 = null;
            if (isSwipeUp) closeMenu();
        }, {passive:true});

        // Фолбэк: если bootstrap события недоступны — отслеживаем класс show сами
        const mo = new MutationObserver(() => {
            if (isOpen()) openUI(); else closeUI();
        });
        mo.observe(collapseEl, { attributes:true, attributeFilter:['class'] });

    })();
</script>

<?php $this->endBody() ?>
</body>
</html>
<?php $this->endPage() ?>
