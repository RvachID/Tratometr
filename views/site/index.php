<?php
use yii\helpers\Url;
$this->title = 'Тратометр';
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

    <!-- тут потом появятся История/Статистика
    <div class="d-grid gap-2">
        <a href="#" class="btn btn-outline-secondary disabled">📜 История (скоро)</a>
        <a href="#" class="btn btn-outline-secondary disabled">📈 Статистика (скоро)</a>
    </div>-->
</div>
