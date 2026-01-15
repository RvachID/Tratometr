<?php
/** @var yii\web\View $this */

/** @var app\models\AliceItem[] $items */

use yii\helpers\Html;

$this->title = 'Список покупок';
?>

<div class="container mt-3">

    <h1 class="h4 mb-3">Список покупок</h1>

    <!-- ===== Добавление ===== -->
    <div class="mb-3">
        <?= Html::beginForm(['alice-item/create'], 'post', ['class' => 'd-flex gap-2']) ?>
        <?= Html::hiddenInput(Yii::$app->request->csrfParam, Yii::$app->request->csrfToken) ?>

        <input
                type="text"
                name="title"
                class="form-control"
                placeholder="Добавить товар…"
                required
        >
        <button type="submit" class="btn btn-outline-secondary">Добавить</button>

        <?= Html::endForm() ?>
    </div>

    <div class="d-sm-none">

        <div id="section-pinned">
            <div class="list-section-title">Регулярные покупки</div>
        </div>

        <div id="section-active">
            <div class="list-section-title">Остальное</div>
        </div>

        <div id="section-done">
            <div class="list-section-title">Архив</div>
        </div>

        <?php foreach ($items as $item): ?>
            <?php
            $sectionId = $item->is_done
                ? 'section-done'
                : ($item->is_pinned ? 'section-pinned' : 'section-active');
            ?>

            <div
                    class="alice-swipe-wrap <?= $item->is_done ? 'opacity-75' : '' ?>"
                    data-id="<?= (int)$item->id ?>"
                    data-pinned="<?= (int)$item->is_pinned ?>"
                    data-section="<?= $sectionId ?>"
            >

                <div class="swipe-bg swipe-bg-left"></div>
                <div class="swipe-bg swipe-bg-right"></div>

                <div class="alice-card">
                    <div class="alice-row-mobile">

                        <button
                                class="done-toggle <?= $item->is_done ? 'is-done' : '' ?>"
                                data-id="<?= (int)$item->id ?>"
                        ><span class="check">✓</span></button>

                        <input
                                type="text"
                                value="<?= Html::encode($item->title) ?>"
                                class="alice-title-input"
                                data-id="<?= (int)$item->id ?>"
                        >

                    </div>
                </div>
            </div>

            <script>
                document.getElementById('<?= $sectionId ?>')
                    .appendChild(document.currentScript.previousElementSibling);
            </script>
        <?php endforeach; ?>

    </div>

</div>
