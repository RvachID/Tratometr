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

    <!-- ================= DESKTOP (≥ sm) ================= -->
    <div class="d-none d-sm-block">

        <table class="table table-sm align-middle">
            <thead>
            <tr>
                <th style="width:40px;"></th>
                <th>Название</th>
                <th style="width:60px;" class="text-center">📌</th>
                <th style="width:100px;" class="text-end">Удалить</th>
            </tr>
            </thead>
            <tbody>
            <?php
            $shownPinned = false;
            $shownOther  = false;
            $shownDone   = false;
            ?>

            <?php foreach ($items as $item): ?>

                <?php if ($item->is_pinned && !$shownPinned): ?>
                    <tr class="table-light">
                        <td colspan="4" class="fw-semibold text-muted small">
                            Регулярные покупки
                        </td>
                    </tr>
                    <?php $shownPinned = true; ?>
                <?php endif; ?>

                <?php if (!$item->is_pinned && !$item->is_done && !$shownOther): ?>
                    <tr class="table-light">
                        <td colspan="4" class="fw-semibold text-muted small">
                            Остальное
                        </td>
                    </tr>
                    <?php $shownOther = true; ?>
                <?php endif; ?>

                <?php if ($item->is_done && !$shownDone): ?>
                    <tr class="table-light">
                        <td colspan="4" class="fw-semibold text-muted small">
                            Архив
                        </td>
                    </tr>
                    <?php $shownDone = true; ?>
                <?php endif; ?>

                <tr class="<?= $item->is_done ? 'text-muted' : '' ?>">
                    <!-- DONE -->
                    <td class="text-center">
                        <?= Html::beginForm(['alice-item/toggle-done', 'id' => $item->id], 'post') ?>
                        <?= Html::hiddenInput(Yii::$app->request->csrfParam, Yii::$app->request->csrfToken) ?>
                        <button
                                type="submit"
                                class="btn done-toggle <?= $item->is_done ? 'is-done btn-outline-success' : 'btn-outline-secondary' ?>"
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
                            🗑
                        </button>
                        <?= Html::endForm() ?>
                    </td>
                </tr>

            <?php endforeach; ?>
            </tbody>

        </table>
    </div>

    <!-- ================= MOBILE (< sm) ================= -->
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
                        >
                            <span class="check">✓</span>
                        </button>

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
