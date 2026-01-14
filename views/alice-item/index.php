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
        <button type="submit" class="btn btn-primary">Добавить</button>

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
                        <button type="submit"
                                class="btn btn-sm <?= $item->is_done ? 'btn-outline-success' : 'btn-outline-secondary' ?>"
                                title="Отметить как купленное / вернуть в список">
                            <?= $item->is_done ? '✓' : '' ?>
                        </button>
                        <?= Html::endForm() ?>
                    </td>

                    <!-- TITLE -->
                    <td>
                        <?= Html::beginForm(['alice-item/update', 'id' => $item->id], 'post') ?>
                        <?= Html::hiddenInput(Yii::$app->request->csrfParam, Yii::$app->request->csrfToken) ?>
                        <input
                                type="text"
                                name="title"
                                value="<?= Html::encode($item->title) ?>"
                                class="form-control form-control-sm"
                        >
                        <?= Html::endForm() ?>
                    </td>

                    <!-- PIN -->
                    <td class="text-center">
                        <?= Html::beginForm(['alice-item/toggle-pinned', 'id' => $item->id], 'post') ?>
                        <?= Html::hiddenInput(Yii::$app->request->csrfParam, Yii::$app->request->csrfToken) ?>
                        <button type="submit"
                                class="btn btn-sm <?= $item->is_pinned ? 'btn-outline-warning' : 'btn-outline-secondary' ?>"
                                title="Закрепить / открепить">
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
    <div class="d-sm-none">
        <?php foreach ($items as $item): ?>
            <div class="card border-0 shadow-sm mb-2 <?= $item->is_done ? 'text-muted' : '' ?>">
                <div class="card-body py-2">

                    <div class="d-flex justify-content-between align-items-start">
                        <div class="flex-grow-1 me-2">
                            <?= Html::beginForm(['alice-item/update', 'id' => $item->id], 'post') ?>
                            <?= Html::hiddenInput(Yii::$app->request->csrfParam, Yii::$app->request->csrfToken) ?>
                            <input
                                    type="text"
                                    name="title"
                                    value="<?= Html::encode($item->title) ?>"
                                    class="form-control form-control-sm"
                            >
                            <?= Html::endForm() ?>
                        </div>

                        <?= Html::beginForm(['alice-item/toggle-done', 'id' => $item->id], 'post') ?>
                        <?= Html::hiddenInput(Yii::$app->request->csrfParam, Yii::$app->request->csrfToken) ?>
                        <button type="submit"
                                class="btn btn-sm <?= $item->is_done ? 'btn-outline-success' : 'btn-outline-secondary' ?>">
                            <?= $item->is_done ? '✓' : '' ?>
                        </button>
                        <?= Html::endForm() ?>
                    </div>

                    <div class="d-flex justify-content-end gap-2 mt-2">
                        <?= Html::beginForm(['alice-item/toggle-pinned', 'id' => $item->id], 'post') ?>
                        <?= Html::hiddenInput(Yii::$app->request->csrfParam, Yii::$app->request->csrfToken) ?>
                        <button type="submit" class="btn btn-outline-secondary btn-sm">
                            <?= $item->is_pinned ? '📌' : 'Закрепить' ?>
                        </button>
                        <?= Html::endForm() ?>

                        <?= Html::beginForm(['alice-item/delete', 'id' => $item->id], 'post', [
                            'onsubmit' => "return confirm('Удалить пункт?');"
                        ]) ?>
                        <?= Html::hiddenInput(Yii::$app->request->csrfParam, Yii::$app->request->csrfToken) ?>
                        <button type="submit" class="btn btn-outline-secondary btn-sm">
                            Удалить
                        </button>
                        <?= Html::endForm() ?>
                    </div>

                </div>
            </div>
        <?php endforeach; ?>
    </div>

</div>
