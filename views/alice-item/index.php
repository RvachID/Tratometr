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

    <!-- ================= ≥ sm: таблица ================= -->
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
                <tr class="<?= $item->is_done ? 'text-muted' : '' ?>">

                    <!-- DONE -->
                    <td class="text-center">
                        <?= Html::beginForm(['alice-item/toggle-done', 'id' => $item->id], 'post') ?>
                        <?= Html::hiddenInput(Yii::$app->request->csrfParam, Yii::$app->request->csrfToken) ?>
                        <button
                                type="submit"
                                class="btn btn-sm done-toggle <?= $item->is_done ? 'btn-outline-success is-done' : 'btn-outline-secondary' ?>"
                                title="Отметить как купленное / вернуть в список"
                        >
                            <span class="check">✓</span>
                        </button>
                        <?= Html::endForm() ?>
                    </td>

                    <!-- TITLE (inline edit) -->
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
                                title="Закрепить / открепить"
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

    <!-- ================= < sm: карточки ================= -->
    <?php
    $shownDoneHeader = false;
    ?>

    <div class="d-sm-none">

        <?php $shownDoneHeader = false; ?>

        <?php foreach ($items as $item): ?>

            <?php if (!$shownDoneHeader && $item->is_done): ?>
                <div class="list-section-title mt-3">Куплено</div>
                <?php $shownDoneHeader = true; ?>
            <?php endif; ?>

            <div class="card border-0 shadow-sm mb-2 <?= $item->is_done ? 'opacity-75' : '' ?>">
                <div class="card-body py-2 px-2">

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

                        <button
                                class="pin-toggle <?= $item->is_pinned ? 'is-pinned' : '' ?>"
                                data-id="<?= (int)$item->id ?>"
                        >
                            📌
                        </button>

                        <button
                                class="delete-toggle"
                                data-id="<?= (int)$item->id ?>"
                        >
                            🗑
                        </button>

                    </div>

                </div>
            </div>

        <?php endforeach; ?>
    </div>

</div>
