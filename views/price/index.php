<?php
$this->title = 'Сканер цен';
$botUsername = 'tratometrN1_bot'; // без @
?>
<button id="btnScan" class="btn btn-camera">📷 Сканировать</button>
<div id="result"></div>

<script src="https://telegram.org/js/telegram-web-app.js"></script>
<script>
    const tg = window.Telegram.WebApp;
    tg.ready();

    document.getElementById('btnScan').addEventListener('click', () => {
        tg.openTelegramLink('https://t.me/<?= $botUsername ?>?start=scan');
    });

    // При открытии Mini App — подтянуть последнюю цену
    (async function loadLastPrice() {
        const res = await fetch('/price/get-last', {credentials: 'same-origin'})
            .then(r => r.json()).catch(() => null);
        if (res && res.price) {
            document.getElementById('result').innerText = 'Цена: ' + res.price;
        }
    })();
</script>
