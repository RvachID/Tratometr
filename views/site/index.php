<?php
use yii\helpers\Url;
$this->title = 'Тратометр';
?>
<div class="container mt-3 text-center">
    <h2>Тратометр</h2>
    <a href="<?= Url::to(['site/start']) ?>" class="btn btn-primary w-100 mb-2">🛒 За покупками</a>

    <!-- тут потом появятся История/Статистика
    <div class="d-grid gap-2">
        <a href="#" class="btn btn-outline-secondary disabled">📜 История (скоро)</a>
        <a href="#" class="btn btn-outline-secondary disabled">📈 Статистика (скоро)</a>
    </div>-->
</div>
