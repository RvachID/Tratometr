<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Сканер ценника</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: sans-serif; text-align: center; padding: 20px; }
        #openBtn {
            display: none;
            padding: 15px 25px;
            background: #4CAF50;
            color: white;
            font-size: 18px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            margin-top: 50px;
        }
        #status { margin-top: 20px; font-size: 16px; }
    </style>
</head>
<body>
<h1>Сканер ценника</h1>
<input type="file" accept="image/*" capture="environment" id="cameraInput" style="display:none;">
<button id="openBtn">📷 Открыть камеру</button>
<div id="status">Загрузка...</div>

<script>
    function isTelegramWebView() {
        const ua = navigator.userAgent || '';
        return /Telegram/i.test(ua);
    }

    document.addEventListener('DOMContentLoaded', function() {
        const cameraInput = document.getElementById('cameraInput');
        cameraInput.click(); // в системном браузере сработает сразу
    });
</script>
</body>
</html>
