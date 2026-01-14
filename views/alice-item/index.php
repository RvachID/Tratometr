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

    <?php
    $shownPinnedHeader  = false;
    $shownRegularHeader = false;
    $shownDoneHeader    = false;
    ?>

    <!-- ================= ≥ sm: TABLE ================= -->
    <div class="d-none d-sm-block">
        <table class="table table-sm align-middle">
            <thead>
            <tr>
                <th style="width:40px;"></th>
                <th>Название</th>
                <th style="width:60px;" class="text-center">📌</th>
                <th style="width:120px;" class="text-end">Действия</th>
            </tr>
            </thead>
            <tbody>

            <?php foreach ($items as $item): ?>

                <?php
                if (!$shownDoneHeader && $item->is_done) {
                    echo '<tr><td colspan="4"><div class="list-section-title mt-3">Куплено</div></td></tr>';
                    $shownDoneHeader = true;
                } elseif (!$shownPinnedHeader && !$item->is_done && $item->is_pinned) {
                    echo '<tr><td colspan="4"><div class="list-section-title">Регулярные покупки</div></td></tr>';
                    $shownPinnedHeader = true;
                } elseif (!$shownRegularHeader && !$item->is_done && !$item->is_pinned) {
                    echo '<tr><td colspan="4"><div class="list-section-title">Остальное</div></td></tr>';
                    $shownRegularHeader = true;
                }
                ?>

                <tr class="<?= $item->is_done ? 'text-muted' : '' ?>">

                    <!-- DONE -->
                    <td class="text-center">
                        <?= Html::beginForm(['alice-item/toggle-done', 'id' => $item->id], 'post') ?>
                        <?= Html::hiddenInput(Yii::$app->request->csrfParam, Yii::$app->request->csrfToken) ?>
                        <button
                                type="submit"
                                class="btn btn-sm done-toggle <?= $item->is_done ? 'btn-outline-success is-done' : 'btn-outline-secondary' ?>"
                        >
                            <span class="check">✓</span>
                        </button>
                        <?= Html::endForm() ?>
                    </td>

                    <!-- TITLE -->
                    <td>
                        <input
                                type="text"
                                value="<?= Html::encode($item->title) ?>"
                                class="form-control form-control-sm alice-title-input"
                                data-id="<?= (int)$item->id ?>"
                        >
                    </td>

                    <!-- PIN -->
                    <td class="text-center">
                        <?= Html::beginForm(['alice-item/toggle-pinned', 'id' => $item->id], 'post') ?>
                        <?= Html::hiddenInput(Yii::$app->request->csrfParam, Yii::$app->request->csrfToken) ?>
                        <button
                                type="submit"
                                class="btn btn-sm <?= $item->is_pinned ? 'btn-outline-warning' : 'btn-outline-secondary' ?>"
                        >
                            <?= $item->is_pinned ? '📌' : '—' ?>
                        </button>
                        <?= Html::endForm() ?>
                    </td>

                    <!-- DELETE -->
                    <td class="text-end">
                        <?= Html::beginForm(['alice-item/delete', 'id' => $item->id], 'post', [
                            'onsubmit' => "return confirm('Удалить пункт?');"
                        ]) ?>
                        <?= Html::hiddenInput(Yii::$app->request->csrfParam, Yii::$app->request->csrfToken) ?>
                        <button type="submit" class="btn btn-outline-secondary btn-sm">
                            Удалить
                        </button>
                        <?= Html::endForm() ?>
                    </td>

                </tr>

            <?php endforeach; ?>

            </tbody>
        </table>
    </div>

    <!-- ================= < sm: MOBILE ================= -->
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
