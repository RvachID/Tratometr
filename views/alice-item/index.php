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

        <?php
        $shownPinned = false;
        $shownOther = false;
        $shownDone = false;
        ?>

        <?php foreach ($items as $item): ?>

            <?php if ($item->is_pinned && !$shownPinned): ?>
                <div class="list-section-title">Регулярные покупки</div>
                <?php $shownPinned = true; ?>
            <?php endif; ?>

            <?php if (!$item->is_pinned && !$item->is_done && !$shownOther): ?>
                <div class="list-section-title">Остальное</div>
                <?php $shownOther = true; ?>
            <?php endif; ?>

            <?php if ($item->is_done && !$shownDone): ?>
                <div class="list-section-title">Куплено</div>
                <?php $shownDone = true; ?>
            <?php endif; ?>

            <div
                    class="alice-swipe-wrap <?= $item->is_done ? 'opacity-75' : '' ?>"
                    data-id="<?= (int)$item->id ?>"
                    data-pinned="<?= (int)$item->is_pinned ?>"
            >

                <!-- подложки -->
                <div class="swipe-bg swipe-bg-left">
                    <?= $item->is_pinned ? '🟢 Открепить' : '📌 Закрепить' ?>
                </div>
                <div class="swipe-bg swipe-bg-right">
                    🗑 Удалить
                </div>

                <!-- карточка -->
                <div class="alice-card">
                    <div class="alice-row-mobile">

                        <!-- DONE -->
                        <button
                                class="done-toggle <?= $item->is_done ? 'is-done' : '' ?>"
                                data-id="<?= (int)$item->id ?>"
                        >
                            ✓
                        </button>

                        <!-- TITLE -->
                        <input
                                type="text"
                                value="<?= Html::encode($item->title) ?>"
                                class="alice-title-input"
                                data-id="<?= (int)$item->id ?>"
                        >

                    </div>
                </div>

            </div>

        <?php endforeach; ?>

    </div>


</div>
