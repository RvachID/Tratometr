<?php

use yii\helpers\Html;

$this->title = 'Сканнер';

$total = $total ?? 0;
$entries = $entries ?? [];

?>
    <div class="container mt-3 text-center"
         id="scan-root"
         data-store="<?= Html::encode($store) ?>"
         data-category="<?= Html::encode($category) ?>">
        <div class="container mt-3 text-center">
            <h2>Тратометр</h2>
            <button id="start-scan" class="btn btn-outline-secondary mb-3" type="button">📷 Открыть камеру</button>
            <button id="manual-add" class="btn btn-outline-secondary mb-3 ms-2" type="button">✍️ Ввести вручную</button>

            <div id="camera-wrapper" style="display:none;">
                <video id="camera" autoplay playsinline width="100%" style="max-width:400px;"></video>
                <button id="capture" class="btn btn-outline-secondary mt-2" type="button">
                    <span class="btn-text">📸 Сканировать</span>
                    <span class="spinner" style="display:none;"></span>
                </button>
            </div>


            <!-- Модалка предпросмотра -->
            <div class="modal fade" id="scanModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Предпросмотр</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"
                                    aria-label="Закрыть"></button>
                        </div>
                        <div class="modal-body">

                            <div class="mb-2 text-start">
                                <label class="form-label">Цена</label>
                                <input type="number" step="0.01" class="form-control" id="m-amount">
                            </div>

                            <div class="mb-2 text-start">
                                <label class="form-label">Количество</label>
                                <div class="input-group">
                                    <button class="btn btn-outline-secondary" type="button" id="m-qty-minus">–</button>
                                    <input type="number" step="0.001" class="form-control text-center" id="m-qty"
                                           value="1">
                                    <button class="btn btn-outline-secondary" type="button" id="m-qty-plus">+</button>
                                </div>
                                <small class="text-muted">Штуки добавляем через +/-; килограммы (дробные) можно вводить
                                    вручную.</small>
                            </div>

                            <div class="mb-2 text-start">
                                <label class="form-label">Заметка (опц.)</label>
                                <input type="text" class="form-control" id="m-note">
                            </div>

                            <div class="mb-2" id="m-photo-wrap" style="display:none;">
                                <img id="m-photo" class="img-fluid" alt="Фото скана"/>
                            </div>

                        </div>
                        <div class="modal-footer">
                            <button class="btn btn-outline-secondary" id="m-show-photo" type="button">Скан</button>
                            <button class="btn btn-outline-secondary" id="m-retake" type="button">Переснять</button>
                            <button class="btn btn-outline-secondary" id="m-save" type="button">Сохранить</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-3">
                <h5>Общая сумма: <strong><?= number_format($total, 2, '.', ' ') ?></strong> ₽</h5>
            </div>

            <div class="mt-3 text-start">
                <?php foreach ($entries as $entry): ?>
                    <div class="border p-2 mb-2">
                        <form class="entry-form" data-id="<?= $entry->id ?>">
                            Цена:
                            <input type="number" step="0.01" name="amount" value="<?= $entry->amount ?>"
                                   class="form-control mb-1">

                            <input type="hidden" name="category" value="<?= Html::encode($entry->category) ?>">

                            Штук или килограмм:
                            <input type="number" step="0.001" name="qty" value="<?= $entry->qty ?>"
                                   class="form-control mb-1">

                            <!-- сюда фронт уже кладёт текст заметки -->
                            <input type="hidden" name="note" value="<?= Html::encode($entry->note) ?>">
                        </form>

                        <!-- слот для заметки (JS будет рендерить сюда) -->
                        <div class="entry-note-wrap"></div>

                        <!-- Кнопки всегда внизу -->
                        <div class="d-flex gap-2 mt-2">
                            <button class="btn btn-sm btn-outline-danger delete-entry" type="button">🗑 Удалить</button>
                            <button class="btn btn-sm btn-outline-success save-entry d-none" type="button">💾</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

        </div>
    </div>

<?php
// Подключения js по-странично

$this->registerJsFile('@web/js/common.js',  ['position' => \yii\web\View::POS_END]);
$this->registerJsFile('@web/js/entries.js', ['position' => \yii\web\View::POS_END]);
$this->registerJsFile('@web/js/scanner.js', ['position' => \yii\web\View::POS_END]);

