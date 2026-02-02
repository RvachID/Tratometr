<?php

use yii\helpers\Html;

/** @var \app\models\AliceItem[] $aliceItems */

$mode = $mode ?? 'scan';
$isView = $mode === 'view';

$this->title = $isView ? 'Покупки' : 'Сканнер';

$total = $total ?? 0;
$entries = $entries ?? [];

?>

<div class="container mt-3 text-center"
     id="scan-root"
     data-store="<?= Html::encode($store) ?>"
     data-category="<?= Html::encode($category) ?>"
     data-need-prompt="<?= !empty($needPrompt) ? '1' : '0' ?>">


    <!-- =====================================================
            SCAN UI — ОРИГИНАЛ. НЕ ТРОГАЕМ.
    ===================================================== -->

    <?php if (!$isView): ?>

        <div class="container mt-3 text-center">
            <h6 id="scan-title" class="mb-2">Тратометр</h6>

            <div class="d-flex flex-column flex-sm-row justify-content-center gap-2 mb-3">
                <button id="start-scan" class="btn btn-outline-secondary" type="button">📷 Открыть камеру</button>
                <button id="manual-add" class="btn btn-outline-secondary" type="button">✍️ Ввести вручную</button>
            </div>

            <div id="camera-wrapper"
                 class="text-center position-relative"
                 style="display:none; max-width:400px; margin:0 auto;">

                <video id="camera"
                       autoplay
                       playsinline
                       class="d-block w-100">
                </video>

                <div id="zoom-overlay"></div>
            </div>

            <button id="capture" class="btn btn-outline-secondary d-block mx-auto mt-2" type="button">
                <span class="spinner d-none spinner-border spinner-border-sm me-1"></span>
                <span class="btn-text">📸 Сканировать</span>
            </button>

            <button id="ocr-cancel-btn" class="btn btn-outline-secondary d-none mt-2" type="button">✖ Отмена</button>
        </div>


        <!-- МОДАЛКИ — тоже только scan -->

        <!-- Модалка выбора магазина/категории -->
        <div class="modal fade" id="shopModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static"
             data-bs-keyboard="false">
            ...
        </div>

        <!-- Модалка предпросмотра -->
        <div class="modal fade" id="scanModal" tabindex="-1" aria-hidden="true">
            ...
        </div>

    <?php endif; ?>


    <!-- =====================================================
            VIEW HEADER (новый, безопасный)
    ===================================================== -->

    <?php if ($isView): ?>

        <div class="card border-0 shadow-sm mb-3 text-start">
            <div class="card-body">

                <div class="fw-semibold">
                    <?= Html::encode($category) ?>
                </div>

                <div class="text-muted small">
                    <?= Html::encode($store) ?>
                </div>

                <?php if ($limit !== null): ?>
                    <div class="small mt-2">
                        Лимит:
                        <strong><?= number_format((float)$limit, 2, '.', ' ') ?></strong>
                    </div>
                <?php endif; ?>

                <div class="mt-1">
                    Итого:
                    <strong><?= number_format((float)$total, 2, '.', ' ') ?></strong>
                </div>

            </div>
        </div>

    <?php endif; ?>


    <!-- =====================================================
            TOTAL — ТОЛЬКО SCAN
    ===================================================== -->

    <?php if (!$isView): ?>

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

                <div class="total-total">
                    <span class="me-1"><strong id="scan-total-label"><?= $totalLabel ?? 'Общая сумма:' ?></strong></span>
                    <strong id="scan-total"><?= $fmt($sum) ?></strong>
                </div>

            <?php else: ?>

                <div class="total-total">
                    <span class="me-1"><strong id="scan-remaining-label">До лимита:</strong></span>
                    <strong id="scan-remaining" class="<?= $isOver ? 'text-danger fw-bold' : '' ?>">
                        <?= $fmt($rest) ?>
                    </strong>
                </div>

                <div class="text-muted small mt-1" id="scan-secondary">
                    <span id="scan-sum-label">Итого:</span>
                    <span id="scan-sum"><?= $fmt($sum) ?></span>
                    <span class="mx-1">•</span>
                    <span id="scan-limit-label">Лимит:</span>
                    <span id="scan-limit"><?= $fmt($lim) ?></span>
                </div>

            <?php endif; ?>

        </div>

    <?php endif; ?>


    <!-- =====================================================
            ENTRIES
    ===================================================== -->

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


                <?php if ($isView): ?>

                    <!-- VIEW MODE — БЕЗ INPUT -->

                    <div class="d-flex justify-content-between">
                        <div>Кол-во: <strong><?= $entry->qty ?></strong></div>
                        <div>Цена: <strong><?= number_format($entry->amount, 2) ?></strong></div>
                        <div>Сумма: <strong><?= number_format($entry->qty * $entry->amount, 2) ?></strong></div>
                    </div>

                    <?php if ($entry->note): ?>
                        <div class="text-muted small mt-1">
                            <?= Html::encode($entry->note) ?>
                        </div>
                    <?php endif; ?>


                <?php else: ?>

                    <!-- ORIGINAL SCAN FORM -->

                    <form class="entry-form" data-id="<?= $entry->id ?>">
                        Цена:
                        <input type="number" step="0.01" name="amount" value="<?= $entry->amount ?>"
                               class="form-control mb-1">

                        <input type="hidden" name="category" value="<?= Html::encode($entry->category) ?>">

                        Штук или килограмм:
                        <input type="number" step="0.001" name="qty" value="<?= $entry->qty ?>"
                               class="form-control mb-1">

                        <input type="hidden" name="note" value="<?= Html::encode($entry->note) ?>">
                    </form>

                    <div class="entry-note-wrap"></div>

                    <div class="d-flex gap-2 mt-2">
                        <button class="btn btn-sm btn-outline-danger delete-entry" type="button">🗑 Удалить</button>
                        <button class="btn btn-sm btn-outline-success save-entry d-none" type="button">💾</button>
                    </div>

                <?php endif; ?>

            </div>

        <?php endforeach; ?>

    </div>

</div>
