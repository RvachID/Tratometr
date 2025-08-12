<?php
use yii\helpers\Html;
use yii\helpers\Url;

// ❗ Если Bootstrap 5 не подключён глобально в layout, раскомментируй строки ниже:
    yii\bootstrap5\BootstrapAsset::register($this);
    yii\bootstrap5\BootstrapPluginAsset::register($this);

$this->title = 'Тратометр';

// CSS для спиннера в кнопке
$this->registerCss(<<<CSS
.spinner {
  border: 2px solid #f3f3f3;
  border-top: 2px solid #3498db;
  border-radius: 50%;
  width: 14px; height: 14px;
  animation: spin 0.8s linear infinite;
  margin-left: 6px;
  display: inline-block;
  vertical-align: middle;
}
@keyframes spin { 0% {transform: rotate(0)} 100% {transform: rotate(360deg)} }
CSS);
?>

<div class="container mt-3 text-center">
    <h2>Тратометр</h2>

    <img id="preview-image" style="max-width:100%; border:1px solid #ccc; margin-top:10px;" />

    <button id="start-scan" class="btn btn-primary mb-3" type="button">📷 Сканировать</button>

    <div id="camera-wrapper" style="display:none;">
        <video id="camera" autoplay playsinline width="100%" style="max-width:400px;"></video>
        <br>
        <button id="capture" class="btn btn-success mt-2" type="button">
            <span class="btn-text">📸 Сфоткать</span>
            <span class="spinner" style="display:none;"></span>
        </button>
    </div>

    <!-- Модалка предпросмотра -->
    <div class="modal fade" id="scanModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Предпросмотр</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть"></button>
                </div>
                <div class="modal-body">

                    <div class="mb-2 text-start">
                        <label class="form-label">Сумма</label>
                        <input type="number" step="0.01" class="form-control" id="m-amount">
                    </div>

                    <div class="mb-2 text-start">
                        <label class="form-label">Количество</label>
                        <div class="input-group">
                            <button class="btn btn-outline-secondary" type="button" id="m-qty-minus">–</button>
                            <input type="number" step="0.001" class="form-control text-center" id="m-qty" value="1">
                            <button class="btn btn-outline-secondary" type="button" id="m-qty-plus">+</button>
                        </div>
                        <small class="text-muted">Целые удобнее через +/-; дробные можно вводить вручную.</small>
                    </div>

                    <div class="mb-2 text-start">
                        <label class="form-label">Заметка (опц.)</label>
                        <input type="text" class="form-control" id="m-note">
                    </div>

                    <div class="mb-2" id="m-photo-wrap" style="display:none;">
                        <img id="m-photo" class="img-fluid" alt="Фото скана" />
                    </div>

                </div>
                <div class="modal-footer">
                    <button class="btn btn-link" id="m-show-photo" type="button">Показать фото</button>
                    <button class="btn btn-outline-secondary" id="m-retake" type="button">Переснять</button>
                    <button class="btn btn-primary" id="m-save" type="button">Сохранить</button>
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
                    Сумма:
                    <input type="number" step="0.01" name="amount" value="<?= $entry->amount ?>" class="form-control mb-1">

                    <!-- Категорию временно скрываем -->
                    <input type="hidden" name="category" value="<?= Html::encode($entry->category) ?>">

                    <!-- Qty: JS сам обернёт в input-group и добавит +/- при инициализации -->
                    Кол-во:
                    <input type="number" step="0.001" name="qty" value="<?= $entry->qty ?>" class="form-control mb-1">

                    <!-- кнопку сохранения прячем: теперь автосейв -->
                    <button class="btn btn-sm btn-outline-success save-entry d-none" type="button">💾</button>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php
// Подключаем твой JS (обновлённый scanner.js)
$this->registerJsFile(Url::to('@web/js/scanner.js'), ['depends' => [\yii\web\JqueryAsset::class]]);
?>
