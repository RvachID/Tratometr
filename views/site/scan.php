<?php

use yii\helpers\Html;

/** @var \app\models\AliceItem[] $aliceItems */
/** @var array $aliceOptions */

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

            <div class="d-flex flex-column flex-sm-row justify-content-center gap-2 mb-3">
                <button id="start-scan" class="btn btn-outline-secondary" type="button">📷 Открыть камеру</button>
                <button id="manual-add" class="btn btn-outline-secondary" type="button">✍️ Ввести вручную</button>
            </div>

            <div id="camera-wrapper" class="text-center" style="display:none;">
                <video id="camera" autoplay playsinline class="d-block mx-auto"
                       style="width:100%; max-width:400px;"></video>

                <button id="capture" class="btn btn-outline-secondary d-block mx-auto mt-2" type="button">
                    <span class="spinner d-none spinner-border spinner-border-sm me-1"></span>
                    <span class="btn-text">📸 Сканировать</span>
                </button>

                <button id="ocr-cancel-btn" class="btn btn-outline-secondary d-none mt-2" type="button">✖ Отмена
                </button>
            </div>
        </div>

        <!-- Модалка выбора магазина/категории -->
        <div class="modal fade" id="shopModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static"
             data-bs-keyboard="false">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Начать покупки</h5>
                    </div>
                    <div class="modal-body">
                        <div class="mb-2">
                            <label class="form-label">Магазин</label>
                            <input type="text" class="form-control" id="shop-store" placeholder="Пятёрочка / Lidl / ..."
                                   required>
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Категория</label>
                            <select class="form-select" id="shop-category">
                                <option>Продукты питания</option>
                                <option>Овощи/фрукты</option>
                                <option>Бытовая химия</option>
                                <option>Косметика</option>
                                <option>Одежда</option>
                                <option>Детские товары</option>
                                <option>Лекарства</option>
                                <option>Электроника/бытовая техника</option>
                                <option>Транспорт</option>
                                <option>Питомцы</option>
                                <option>Другое</option>
                            </select>
                        </div>

                        <div class="mb-2">
                            <label for="shop-limit" class="form-label">Лимит (опц.)</label>
                            <input id="shop-limit" type="number" step="0.01" inputmode="decimal" class="form-control"
                                   placeholder="например, 5000.00">
                        </div>
                        <small class="text-muted">При указании лимита предупредим о его превышении.</small>
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

                        <div class="mb-2 text-start">
                            <label class="form-label">Из списка покупок (опц.)</label>

                            <select id="m-alice-item" class="form-select">
                                <option value="">выберите...</option>
                            </select>

                            <div class="d-flex justify-content-between align-items-center mt-1">
                                <small class="text-muted">
                                    Выбранный пункт пометим как купленный
                                </small>

                                <small class="text-muted d-flex align-items-center gap-1">
                                    <span>Долгий тап</span>
                                    <span title="Редактировать список покупок">✏️</span>
                                </small>
                            </div>
                        </div>


                        <div class="mb-2" id="m-photo-wrap" style="display:none;">
                            <img id="m-photo" class="img-fluid" alt="Фото скана"/>
                        </div>

                    </div>
                    <div class="modal-footer d-flex justify-content-between flex-wrap gap-2">
                        <div class="d-flex gap-2">
                            <button class="btn btn-outline-secondary" id="m-show-photo" type="button"></button>
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
        $sum = (float)($total ?? 0.0);
        $lim = $limit !== null ? (float)$limit : null;
        $rest = $lim !== null ? ($lim - $sum) : null;
        $isOver = $lim !== null && $rest < 0;

        $fmt = fn($v) => number_format((float)$v, 2, '.', ' ');
        ?>
        <div class="mt-3" id="total-wrap"
             data-limit="<?= $lim !== null ? $fmt($lim) : '' ?>"
             data-has-limit="<?= $lim !== null ? '1' : '0' ?>">

            <?php if ($lim === null): ?>
                <!-- режим без лимита -->
                <div class="total-total">
                    <span class="me-1"><strong
                                id="scan-total-label"><?= $totalLabel ?? 'Общая сумма:' ?></strong></span>
                    <strong id="scan-total" class=""><?= $fmt($sum) ?></strong>
                </div>
            <?php else: ?>
                <!-- режим с лимитом: главная строка — остаток/перерасход -->
                <div class="total-total">
                    <span class="me-1"><strong id="scan-remaining-label">До лимита:</strong></span>
                    <strong id="scan-remaining" class="<?= $isOver ? 'text-danger fw-bold' : '' ?>">
                        <?= $fmt($rest) ?>
                    </strong>
                </div>
                <!-- тонкая вторая строка: итог + лимит -->
                <div class="text-muted small mt-1" id="scan-secondary">
                    <span id="scan-sum-label">Итого:</span>
                    <span id="scan-sum"><?= $fmt($sum) ?></span>
                    <span class="mx-1">•</span>
                    <span id="scan-limit-label">Лимит:</span>
                    <span id="scan-limit"><?= $fmt($lim) ?></span>
                </div>
            <?php endif; ?>
        </div>

        <div class="mt-3 text-start">
            <?php foreach ($entries as $entry): ?>
                <div class="border p-2 mb-2">
                    <?php if ($entry->aliceItem): ?>
                        <div class="mb-2">
        <span class="badge entry-badge">
            <?= Html::encode($entry->aliceItem->title) ?>
        </span>
                        </div>
                    <?php endif; ?>


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



