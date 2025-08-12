<?php

use yii\helpers\Html;
use yii\helpers\Url;

$this->title = 'Тратометр';
?>

<div class="container mt-3 text-center">
    <h2>Тратометр</h2>

    <img id="preview-image" style="max-width: 100%; border: 1px solid #ccc; margin-top: 10px;" />


    <button id="start-scan" class="btn btn-primary mb-3">📷 Сканировать</button>

    <div id="camera-wrapper" style="display:none;">
        <video id="camera" autoplay playsinline width="100%" style="max-width:400px;"></video>
        <br>
        <button id="captureBtn" type="button">
            <span class="btn-text">Сфоткать</span>
            <span class="spinner" style="display:none;"></span>
        </button>
    </div>

    <div class="mt-3">
        <h5>Общая сумма: <strong><?= number_format($total, 2, '.', ' ') ?></strong> ₽</h5>
    </div>

    <div class="mt-3 text-start">
        <?php foreach ($entries as $entry): ?>
            <div class="border p-2 mb-2">
                <form class="entry-form" data-id="<?= $entry->id ?>">
                    Сумма: <input type="number" step="0.01" name="amount" value="<?= $entry->amount ?>"
                                  class="form-control mb-1">
                    Кол-во: <input type="number" step="0.001" name="qty" value="<?= $entry->qty ?>"
                                   class="form-control mb-1">
                    Категория: <input type="text" name="category" value="<?= Html::encode($entry->category) ?>"
                                      class="form-control mb-1">
                    <button class="btn btn-sm btn-outline-success save-entry">💾</button>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php
$this->registerJsFile(Url::to('@web/js/scanner.js'), ['depends' => [\yii\web\JqueryAsset::class]]);
?>
