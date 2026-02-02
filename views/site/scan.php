<?php

use yii\helpers\Html;

/** @var \app\models\AliceItem[] $aliceItems */

$mode = $mode ?? 'scan';
$isView = $mode === 'view';

$this->title = $isView ? 'Покупки' : 'Сканнер';

$total = $total ?? 0;
$entries = $entries ?? [];

if (!$isView) {
    $this->registerJsFile('@web/js/scanner.js', ['depends' => [\yii\web\JqueryAsset::class]]);
}

$sum = (float)$total;
$lim = $limit !== null ? (float)$limit : null;
$rest = $lim !== null ? ($lim - $sum) : null;
$isOver = $lim !== null && $rest < 0;

$fmt = fn($v) => number_format((float)$v, 2, '.', ' ');

?>

<div class="container mt-3 text-center"
     id="scan-root"
     data-store="<?= Html::encode($store) ?>"
     data-category="<?= Html::encode($category) ?>"
     data-need-prompt="<?= !empty($needPrompt) ? '1' : '0' ?>">

    <!-- =========================================
            SCAN MODE ONLY
    ========================================== -->

    <?php if (!$isView): ?>

        <h6 id="scan-title" class="mb-2">Тратометр</h6>

        <div class="d-flex flex-column flex-sm-row justify-content-center gap-2 mb-3">
            <button id="start-scan" class="btn btn-outline-secondary">📷 Открыть камеру</button>
            <button id="manual-add" class="btn btn-outline-secondary">✍️ Ввести вручную</button>
        </div>

        <div id="camera-wrapper"
             class="text-center position-relative"
             style="display:none; max-width:400px; margin:0 auto;">

            <video id="camera" autoplay playsinline class="d-block w-100"></video>
            <div id="zoom-overlay"></div>
        </div>

        <button id="capture" class="btn btn-outline-secondary d-block mx-auto mt-2">
            <span class="spinner d-none spinner-border spinner-border-sm me-1"></span>
            <span class="btn-text">📸 Сканировать</span>
        </button>

        <button id="ocr-cancel-btn"
                class="btn btn-outline-secondary d-none mt-2">
            ✖ Отмена
        </button>

    <?php endif; ?>


    <!-- =========================================
            SESSION HEADER (VIEW MODE)
    ========================================== -->

    <?php if ($isView): ?>

        <div class="card border-0 shadow-sm mb-3 text-start">
            <div class="card-body py-3">

                <div class="fw-semibold">
                    <?= Html::encode($category) ?>
                </div>

                <div class="text-muted small">
                    <?= Html::encode($store) ?>
                </div>

                <?php if (!empty($sessionTs)): ?>
                    <div class="text-muted small">
                        <?= Yii::$app->formatter->asDatetime($sessionTs, 'php:d.m.Y H:i') ?>
                    </div>
                <?php endif; ?>

                <?php if ($lim !== null): ?>
                    <div class="small mt-2">
                        Лимит:
                        <span class="fw-semibold">
                        <?= $fmt($lim) ?>
                    </span>
                    </div>
                <?php endif; ?>

                <div class="mt-1">
                    <span class="text-muted">Итого:</span>
                    <span class="fw-bold">
                    <?= $fmt($sum) ?>
                </span>
                </div>

            </div>
        </div>

    <?php endif; ?>


    <!-- =========================================
            TOTAL (SCAN MODE)
    ========================================== -->

    <?php if (!$isView): ?>

        <div class="mt-3" id="total-wrap"
             data-limit="<?= $lim !== null ? $fmt($lim) : '' ?>"
             data-has-limit="<?= $lim !== null ? '1' : '0' ?>">

            <?php if ($lim === null): ?>

                <div class="total-total">
                    <span class="me-1"><strong id="scan-total-label">Общая сумма:</strong></span>
                    <strong id="scan-total"><?= $fmt($sum) ?></strong>
                </div>

            <?php else: ?>

                <div class="total-total">
                    <span class="me-1"><strong>До лимита:</strong></span>
                    <strong class="<?= $isOver ? 'text-danger fw-bold' : '' ?>">
                        <?= $fmt($rest) ?>
                    </strong>
                </div>

                <div class="text-muted small mt-1">
                    Итого: <?= $fmt($sum) ?>
                    • Лимит: <?= $fmt($lim) ?>
                </div>

            <?php endif; ?>

        </div>

    <?php endif; ?>


    <!-- =========================================
            ENTRIES
    ========================================== -->

    <div class="mt-3 text-start">

        <?php foreach ($entries as $entry):

            $entrySum = $entry->qty * $entry->amount;
            ?>

            <div class="card border-0 shadow-sm mb-2">
                <div class="card-body py-2">

                    <?php if ($entry->aliceItem): ?>
                        <div class="fw-semibold">
                            <?= Html::encode($entry->aliceItem->title) ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($entry->note): ?>
                        <div class="text-muted small">
                            <?= Html::encode($entry->note) ?>
                        </div>
                    <?php endif; ?>


                    <?php if ($isView): ?>

                        <!-- READ MODE -->

                        <div class="d-flex justify-content-between mt-2 small">

                            <div>
                                Кол-во:
                                <span class="fw-semibold">
                        <?= rtrim(rtrim(number_format($entry->qty, 3, '.', ''), '0'), '.') ?>
                    </span>
                            </div>

                            <div>
                                Цена:
                                <span class="fw-semibold">
                        <?= $fmt($entry->amount) ?>
                    </span>
                            </div>

                            <div>
                                Сумма:
                                <span class="fw-bold">
                        <?= $fmt($entrySum) ?>
                    </span>
                            </div>

                        </div>

                    <?php else: ?>

                        <!-- EDIT MODE -->

                        <form class="entry-form" data-id="<?= $entry->id ?>">

                            Цена:
                            <input type="number"
                                   step="0.01"
                                   name="amount"
                                   value="<?= $entry->amount ?>"
                                   class="form-control mb-1">

                            <input type="hidden"
                                   name="category"
                                   value="<?= Html::encode($entry->category) ?>">

                            Штук или килограмм:
                            <input type="number"
                                   step="0.001"
                                   name="qty"
                                   value="<?= $entry->qty ?>"
                                   class="form-control mb-1">

                            <input type="hidden"
                                   name="note"
                                   value="<?= Html::encode($entry->note) ?>">
                        </form>

                        <div class="entry-note-wrap"></div>

                        <div class="d-flex gap-2 mt-2">
                            <button class="btn btn-sm btn-outline-danger delete-entry">🗑 Удалить</button>
                            <button class="btn btn-sm btn-outline-success save-entry d-none">💾</button>
                        </div>

                    <?php endif; ?>

                </div>
            </div>

        <?php endforeach; ?>

    </div>

</div>
