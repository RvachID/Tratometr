<?php
use app\assets\AppAsset;
use app\widgets\Alert;
use yii\bootstrap5\Breadcrumbs;
use yii\bootstrap5\Html;
use yii\bootstrap5\Nav;
use yii\bootstrap5\NavBar;

/** @var yii\web\View $this */
/** @var string $content */

AppAsset::register($this);
?>
<?php $this->beginPage() ?>
<!DOCTYPE html>
<html lang="<?= Yii::$app->language ?>" class="h-100">
<head>
    <meta charset="<?= Yii::$app->charset ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <?php $this->registerCsrfMetaTags() ?>   <!-- единственный источник CSRF-мета -->
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
        'options'    => ['class' => 'navbar navbar-expand-md navbar-dark bg-dark fixed-top'],
    ]);

    echo Nav::widget([
        'options' => ['class' => 'navbar-nav ms-auto'],
        'items' => [
            ['label' => 'О проекте', 'url' => ['/site/about']],
            Yii::$app->user->isGuest
                ? ['label' => 'Авторизоваться', 'url' => ['/auth/login']]
                : '<li class="nav-item">'
                . Html::beginForm(['/auth/logout'], 'post', ['class' => 'd-inline'])
                . Html::submitButton(
                    'Выйти (' . Html::encode(explode('@', Yii::$app->user->identity->email)[0]) . ')',
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
    <div class="container" style="padding-top: 70px;"><!-- отступ под fixed-top -->
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
                версия <?= Html::encode(Yii::$app->params['version'] ?? '') ?>
            </div>
        </div>
    </div>
</footer>

<script>
    /** Автозакрытие алертов */
    (function () {
        const LIFETIME = 3000;   // 3 сек
        const STAGGER  = 150;
        document.querySelectorAll('.alert').forEach((el, i) => {
            el.classList.add('fade','show');
            setTimeout(() => {
                if (window.bootstrap && bootstrap.Alert) {
                    bootstrap.Alert.getOrCreateInstance(el).close();
                } else {
                    el.style.transition = 'opacity .25s ease';
                    el.style.opacity = '0';
                    setTimeout(() => el.remove(), 250);
                }
            }, LIFETIME + i * STAGGER);
        });
    })();

    /** Закрытие бургер-меню по клику вне его области */
    (function () {
        const toggler = document.querySelector('.navbar-toggler');
        if (!toggler) return;

        const targetSel  = toggler.getAttribute('data-bs-target') || toggler.getAttribute('data-target') || '.navbar-collapse';
        const collapseEl = document.querySelector(targetSel);
        if (!collapseEl) return;

        const hasBS  = !!(window.bootstrap && bootstrap.Collapse);
        const ctrl   = hasBS ? bootstrap.Collapse.getOrCreateInstance(collapseEl, {toggle: false}) : null;
        const isOpen = () => collapseEl.classList.contains('show');

        const openUI  = () => document.body.classList.add('nav-open');
        const closeUI = () => document.body.classList.remove('nav-open');
        const closeMenu = () => {
            if (!isOpen()) return;
            if (ctrl) ctrl.hide(); else collapseEl.classList.remove('show');
            closeUI();
        };

        collapseEl.addEventListener('shown.bs.collapse', openUI);
        collapseEl.addEventListener('hidden.bs.collapse', closeUI);

        document.addEventListener('click', (e) => {
            if (!isOpen()) return;
            if (e.target.closest(targetSel)) return;         // клик внутри меню — не закрываем
            if (e.target.closest('.navbar-toggler')) return; // по самой кнопке — Bootstrap разрулит
            closeMenu();
        }, true);

        document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeMenu(); });
        window.addEventListener('resize', () => { if (!isOpen()) closeUI(); });
    })();

    /** Кука с IANA-таймзоной (используем и для гостей, и как резерв при логине/регистрации) */
    (function(){
        try {
            var tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
            if (!tz) return;
            var has = document.cookie.split('; ').some(function(c){ return c.indexOf('tz=') === 0; });
            if (!has) {
                document.cookie = 'tz=' + tz + ';path=/;max-age=' + (60*60*24*365) + ';SameSite=Lax';
            }
        } catch(e){}
    })();
</script>

<?php $this->endBody() ?>
</body>
</html>
<?php $this->endPage() ?>
