<?php
$this->title = 'Мои траты';
?>
<h1><?= $this->title ?></h1>

<!-- Кнопка для открытия камеры в системном браузере -->
<button id="openCameraBtn" class="btn btn-primary">
    📷 Сканировать (в браузере)
</button>

<!-- Тут можно оставить твой список цен -->
<div id="priceList">
    <!-- Список цен -->
</div>

<script src="https://telegram.org/js/telegram-web-app.js"></script>
<script>
    document.getElementById('openCameraBtn').addEventListener('click', function() {
        // Откроет внешнюю ссылку — Telegram предложит выбрать Chrome/Safari
        window.open('https://tratometr.yourdomain.com/camera', '_blank');
    });
</script>
