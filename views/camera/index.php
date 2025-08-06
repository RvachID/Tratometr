<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Сканер ценника</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body>
<input type="file" accept="image/*" capture="environment" id="cameraInput" style="display:none;">
<div id="status">Открываем камеру...</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const cameraInput = document.getElementById('cameraInput');
        const statusDiv = document.getElementById('status');

        // Запрос камеры сразу
        cameraInput.click();

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

                // Отправляем на сервер
                const res = await fetch('/price/upload-from-camera', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: 'image=' + encodeURIComponent(base64Image)
                }).then(r => r.json());

                if (res.status === 'ok') {
                    statusDiv.textContent = 'Готово! Возвращаемся...';
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
