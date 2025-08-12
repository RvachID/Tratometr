const video = document.getElementById('camera');
const canvas = document.createElement('canvas');
const startBtn = document.getElementById('start-scan');
const captureBtn = document.getElementById('capture');
const cameraWrapper = document.getElementById('camera-wrapper');

let stream = null;

// 🚀 Открыть камеру (заднюю по умолчанию)
startBtn.onclick = async () => {
    try {
        stream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: { ideal: 'environment' } },
            audio: false
        });

        video.srcObject = stream;
        cameraWrapper.style.display = 'block';
    } catch (err) {
        alert('Ошибка при доступе к камере: ' + err.message);
        console.error('Ошибка открытия камеры:', err);
    }
};

let scanBusy = false;
const btnTextEl = captureBtn.querySelector('.btn-text');
const btnSpinnerEl = captureBtn.querySelector('.spinner');

captureBtn.onclick = async () => {
    if (scanBusy) return;
    scanBusy = true;
    captureBtn.disabled = true;

    // Показать спиннер и изменить текст
    btnTextEl.textContent = 'Сканируем…';
    btnSpinnerEl.style.display = 'inline-block';

    try {
        if (!video.videoWidth || !video.videoHeight) {
            alert('Камера ещё не готова');
            return;
        }

        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        const ctx = canvas.getContext('2d');
        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

        // 🖤 ЧБ + Контраст
        const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
        const data = imageData.data;
        for (let i = 0; i < data.length; i += 4) {
            const avg = (data[i] + data[i + 1] + data[i + 2]) / 3;
            const bw = avg > 128 ? 255 : 0;
            data[i] = data[i + 1] = data[i + 2] = bw;
        }
        ctx.putImageData(imageData, 0, 0);

        // 📤 Отправка
        canvas.toBlob(blob => {
            if (!blob) {
                alert('Не удалось получить изображение');
                return;
            }

            const formData = new FormData();
            formData.append('image', blob, 'scan.jpg');

            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            if (!csrfToken) {
                alert('CSRF-токен не найден');
                console.error('CSRF-токен отсутствует в <meta>');
                return;
            }
            const preview = document.getElementById('preview-image');
            preview.src = URL.createObjectURL(blob);

            fetch('/index.php?r=scan/upload', {
                method: 'POST',
                headers: {
                    'X-CSRF-Token': csrfToken
                },
                body: formData,
                credentials: 'include'
            })
                .then(async r => {
                    const contentType = r.headers.get('content-type') || '';
                    if (!contentType.includes('application/json')) {
                        const text = await r.text();
                        console.error('Ожидали JSON, получили:', text);
                        throw new Error('Сервер вернул не JSON. См. консоль.');
                    }
                    return r.json();
                })
                .then(res => {
                    console.log('Ответ от сервера:', res);
                    if (res.success) {
                        alert('Распознано: ' + res.text + '\nСумма: ' + res.amount);
                        location.reload();
                    } else {
                        alert('Ошибка: не удалось распознать сумму');
                    }
                })
                .catch(err => {
                    alert('Ошибка при отправке: ' + err.message);
                    console.error('Ошибка fetch:', err);
                })
                .finally(() => {
                    scanBusy = false;
                    captureBtn.disabled = false;
                    btnTextEl.textContent = 'Сканировать';
                    btnSpinnerEl.style.display = 'none';
                });
        }, 'image/jpeg');

    } catch (err) {
        console.error(err);
        scanBusy = false;
        captureBtn.disabled = false;
        btnTextEl.textContent = 'Сканировать';
        btnSpinnerEl.style.display = 'none';
    }
};

