<?php

use yii\helpers\Html;

$this->title = 'Сканнер';

$total = $total ?? 0;
$entries = $entries ?? [];

?>
    <div class="container mt-3 text-center"
         id="scan-root"
         data-store="<?= Html::encode($store) ?>"
         data-category="<?= Html::encode($category) ?>"
         data-need-prompt="<?= !empty($needPrompt) ? '1' : '0' ?>">

        <div class="container mt-3 text-center">
            <h6 id="scan-title" class="mb-2">Тратометр</h6>
            <button id="start-scan" class="btn btn-outline-secondary mb-3" type="button">📷 Открыть камеру</button>
            <button id="manual-add" class="btn btn-outline-secondary mb-3 ms-2" type="button">✍️ Ввести вручную</button>

            <div id="camera-wrapper" style="display:none;">
                <video id="camera" autoplay playsinline width="100%" style="max-width:400px;"></video>
                <button id="capture" class="btn btn-outline-secondary">
                    <span class="spinner d-none spinner-border spinner-border-sm me-1"></span>
                    <span class="btn-text">📸 Сканировать</span>
                </button>
                <button id="ocr-cancel-btn" class="btn btn-outline-secondary d-none" type="button">✖ Отмена</button>
            </div>
            <!-- Модалка выбора магазина/категории -->
            <div class="modal fade" id="shopModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Начать покупки</h5>
                        </div>
                        <div class="modal-body">
                            <div class="mb-2">
                                <label class="form-label">Магазин</label>
                                <input type="text" class="form-control" id="shop-store" placeholder="Пятёрочка / Lidl / ..." required>
                            </div>
                            <div class="mb-2">
                                <label class="form-label">Категория</label>
                                <select class="form-select" id="shop-category">
                                    <option>Еда</option>
                                    <option>Одежда</option>
                                    <option>Детское</option>
                                    <option>Дом</option>
                                    <option>Аптека</option>
                                    <option>Техника</option>
                                    <option>Транспорт</option>
                                    <option>Развлечения</option>
                                    <option>Питомцы</option>
                                    <option>Другое</option>
                                </select>
                            </div>
                            <small class="text-muted">Эти поля сохранятся к каждой позиции из текущих покупок.</small>
                            <div class="mb-2">
                                <label for="shop-limit" class="form-label">Лимит (опц.)</label>
                                <input id="shop-limit" type="number" step="0.01" inputmode="decimal" class="form-control"
                                       placeholder="например, 5000.00">
                            </div>

                        </div>
                        <div class="modal-footer">
                            <button class="btn btn-outline-secondary" id="shop-begin">Начать</button>
                        </div>
                    </div>
                </div>
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

                            <div class="mb-3 text-center">
                                <label for="m-amount" class="form-label mb-1">Цена</label>
                                <input id="m-amount"
                                       type="text"
                                       class="form-control form-control-lg amount-input text-center"
                                       inputmode="numeric"
                                       autocomplete="off"
                                       placeholder="0.00"
                                       value="0.00">
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
                                <label class="form-label">Заметка или название товара (опц.)</label>
                                <input type="text" class="form-control" id="m-note">
                            </div>

                            <div class="mb-2" id="m-photo-wrap" style="display:none;">
                                <img id="m-photo" class="img-fluid" alt="Фото скана"/>
                            </div>

                        </div>
                        <div class="modal-footer d-flex justify-content-between flex-wrap gap-2">
                            <div class="d-flex gap-2">
                                <button class="btn btn-outline-secondary" id="m-show-photo" type="button">📸 Скан</button>
                            </div>
                            <div class="d-flex gap-2">
                                <button class="btn btn-outline-secondary" id="m-retake" type="button">Переснять</button>
                                <button class="btn btn-outline-secondary" id="m-save" type="button">Сохранить</button>
                            </div>
                        </div>

                    </div>
                </div>
            </div>

            <?php
            $label   = $limit !== null ? 'До лимита:' : ($totalLabel ?? 'Общая сумма:');
            $value   = $limit !== null ? ($limit - ($total ?? 0)) : ($total ?? 0);
            $isOver  = $limit !== null && $value < 0;
            $dataLim = $limit !== null ? number_format($limit, 2, '.', '') : '';
            ?>
            <div class="total mt-3" id="total-wrap" data-limit="<?= $dataLim ?>">
                <span class="me-1"><strong id="scan-total-label"><?= $label ?></strong></span>
                <strong id="scan-total" class="<?= $isOver ? 'text-danger fw-bold' : '' ?>">
                    <?= number_format($value, 2, '.', ' ') ?>
                </strong>
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
// Подключения js постранично

$this->registerJsFile('@web/js/common.js',  [
    'depends'  => [\yii\bootstrap5\BootstrapPluginAsset::class], // <= важно
    'position' => \yii\web\View::POS_END
]);
$this->registerJsFile('@web/js/entries.js', [
    'depends'  => [\yii\bootstrap5\BootstrapPluginAsset::class],
    'position' => \yii\web\View::POS_END
]);
$this->registerJsFile('@web/js/scanner.js', [
    'depends'  => [\yii\bootstrap5\BootstrapPluginAsset::class],
    'position' => \yii\web\View::POS_END
]);


