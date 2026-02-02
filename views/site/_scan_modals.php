
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

                            <div class="alice-select-wrap">
                                <a
                                        href="index.php?r=alice-item/index"
                                        class="alice-select-gear"
                                        title="Редактировать список покупок"
                                        aria-label="Редактировать список покупок"
                                            >
                                            ⚙️
                                </a>

                                <select id="m-alice-item" class="form-select alice-select">
                                    <option value="">выберите...</option>
                                </select>
                            </div>

                            <small class="text-muted">
Выбранный пункт пометим как купленный
</small>
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