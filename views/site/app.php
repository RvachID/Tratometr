<?php
$this->title = 'Тратометр';
?>
<script src="https://telegram.org/js/telegram-web-app.js"></script>
<style>
    body { margin:0; font-family: system-ui, -apple-system, 'Segoe UI', Roboto, Arial; }
    .wrap { padding: 12px; }
    .hidden { display:none; }
</style>

<div class="wrap">
    <div id="loading">Авторизация…</div>
    <div id="app" class="hidden">
        <!-- Здесь подключай SPA/страницу трат -->
        <h2>Добро пожаловать!</h2>
        <p><a href="/price/index">Перейти к тратам</a></p>
    </div>
</div>

<script>
    (async function(){
        const tg = window.Telegram.WebApp;
        tg.ready();

        const res = await fetch('/auth/tg-login', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            credentials: 'same-origin',
            body: JSON.stringify({ initData: tg.initData })
        }).then(r=>r.json()).catch(()=>({error:'network'}));

        document.getElementById('loading').classList.add('hidden');

        if (res && (res.status === 'ok' || res.status === 'new')) {
            document.getElementById('app').classList.remove('hidden');
        } else {
            alert(res.error || 'Ошибка авторизации');
        }
    })();
</script>
