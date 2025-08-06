<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Сканер ценника</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: sans-serif; text-align: center; padding: 20px; }
        #openBtn {
            display: inline-block;
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
<button id="openBtn" style="display:none;">📷 Открыть камеру</button>
<div id="status"></div>

<script>
    function isTelegramWebView() {
        const ua = navigator.userAgent || '';
        return /Telegram/i.test(ua);
    }

    document.addEventListener('DOMContentLoaded', function() {
        const cameraInput = document.getElementById('cameraInput');
        const openBtn = document.getElementById('openBtn');
        const statusDiv = document.getElementById('status');

        const launchCamera = () => {
            cameraInput.click();
        };

        if (isTelegramWebView()) {
            // Внутри Telegram WebView — нужен явный клик
            openBtn.style.display = 'inline-block';
            openBtn.addEventListener('click', launchCamera);
        } else {
            // Системный браузер — сразу пробуем открыть камеру
            launchCamera();
        }

        cameraInput.addEventListener('change', async function() {
            if (!this.files || !this.files[0]) {
                statusDiv.textContent = 'Фото не выбрано';
                return;
            }

            statusDiv.textContent = 'Обработка фото...';

            const file = this.files[0];
            const reader = new FileReader();
            reader.onload = async function(e) {
                const base64Image = e.target.result;

                // Отправляем фото в mini app backend
                const res = await fetch('/price/upload-from-camera', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: 'image=' + encodeURIComponent(base64Image)
                }).then(r => r.json());

                if (res.status === 'ok') {
                    statusDiv.textContent = 'Готово! Возвращаемся в приложение...';
                    // Редирект в mini app
                    window.location.href = 'https://t.me/ТВОЙ_БОТ?startapp=scan_done';
                } else {
                    statusDiv.textContent = 'Ошибка: ' + (res.error || 'Неизвестная ошибка');
                }
            };
            reader.readAsDataURL(file);
        });
    });
</script>
</body>
</html>
