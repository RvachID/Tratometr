<?php
use yii\helpers\Url;
use yii\helpers\Html;

/** @var array|null $quote */
/** @var array|null $psInfo */
/** @var yii\web\View $this */

$this->title = 'Тратометр';
$fmt = Yii::$app->formatter;
?>
<div class="quote container mt-3 text-center">
    <?php if (!empty($quote)): ?>
        <div class="text-muted small mb-3">
            «<?= htmlspecialchars($quote['text']) ?>»
            <?php if (!empty($quote['author'])): ?>
                — <?= htmlspecialchars($quote['author']) ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <a href="<?= Url::to(['site/scan']) ?>" class="btn btn-outline-secondary w-100 mb-2">🛒 За покупками</a>

    <?php if (!empty($psInfo)): ?>
        <div class="card border-0 shadow-sm mt-2 text-start">
            <div class="card-body">
                <div class="small text-muted mb-2">Открытая сессия</div>

                <div class="row">
                    <div class="col-12 col-md-4 mb-1">
                        <strong>Магазин:</strong> <?= Html::encode($psInfo['shop']) ?>
                    </div>
                    <div class="col-12 col-md-4 mb-1">
                        <strong>Категория:</strong> <?= Html::encode($psInfo['category']) ?>
                    </div>
                    <div class="col-12 col-md-4 mb-1">
                        <strong>Время:</strong> <?= $fmt->asDatetime($psInfo['lastTs'], 'php:H:i d.m.Y') ?>
                    </div>
                </div>

                <?php if (!empty($psInfo['limit'])): ?>
                    <div class="mt-1">
                        <strong>Лимит:</strong> <?= number_format($psInfo['limit'], 2, '.', ' ') ?>
                    </div>
                <?php endif; ?>

                <!-- Продолжить — одна длинная кнопка -->
                <div class="mt-3">
                    <a href="<?= Url::to(['site/scan']) ?>" class="btn btn-outline-secondary w-100">▶️ Продолжить</a>
                </div>

                <!-- Ниже: две кнопки в один ряд, поровну -->
                <div class="row mt-2 g-2">
                    <div class="col-6">
                        <?= Html::beginForm(['site/close-session'], 'post') ?>
                        <?= Html::submitButton('✅ Закончить', ['class' => 'btn btn-outline-secondary w-100']) ?>
                        <?= Html::endForm() ?>
                    </div>
                    <div class="col-6">
                        <?= Html::beginForm(['site/delete-session'], 'post', [
                            'onsubmit' => "return confirm('Удалить сессию и все позиции? Это действие необратимо.')"
                        ]) ?>
                        <?= Html::submitButton('🗑️ Удалить', ['class' => 'btn btn-outline-secondary w-100']) ?>
                        <?= Html::endForm() ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- тут потом появятся История/Статистика
    <div class="d-grid gap-2">
        <a href="#" class="btn btn-outline-secondary disabled">📜 История (скоро)</a>
        <a href="#" class="btn btn-outline-secondary disabled">📈 Статистика (скоро)</a>
    </div>-->
</div>
