// ===== DOM =====
const startBtn   = document.getElementById('start-scan');
const wrap       = document.getElementById('camera-wrapper');
const video      = document.getElementById('camera');
const captureBtn = document.getElementById('capture');
const previewImg = document.getElementById('preview-image');

// элементы внутри кнопки "Сфоткать" для спиннера
const btnTextEl    = captureBtn.querySelector('.btn-text') || captureBtn; // на случай, если .btn-text не добавили
const btnSpinnerEl = captureBtn.querySelector('.spinner');

// ===== Состояние =====
let currentStream = null;
let scanBusy = false;

// ===== Камера =====
async function stopStream() {
    if (currentStream) {
        currentStream.getTracks().forEach(t => t.stop());
        currentStream = null;
    }
}

async function getStream(constraints) {
    return await navigator.mediaDevices.getUserMedia(constraints);
}

async function initCamera() {
    await stopStream();

    // пробуем тыльную камеру
    const primary = { video: { facingMode: { ideal: 'environment' } }, audio: false };

    try {
        currentStream = await getStream(primary);
    } catch (e) {
        console.warn('environment camera failed, fallback to any camera:', e?.name, e?.message);
        currentStream = await getStream({ video: true, audio: false });
    }

    video.setAttribute('playsinline', 'true'); // для iOS/Safari
    video.srcObject = currentStream;

    // ждём метаданные и play()
    await new Promise((res) => {
        const h = () => { video.removeEventListener('loadedmetadata', h); res(); };
        if (video.readyState >= 1) res(); else video.addEventListener('loadedmetadata', h);
    });

    try { await video.play(); } catch (e) { console.warn('video.play blocked', e); }
}

// ===== Снимок + отправка =====
async function captureAndSend() {
    if (scanBusy) return;
    scanBusy = true;
    captureBtn.disabled = true;

    // показать спиннер/текст если есть разметка
    if (btnSpinnerEl) btnSpinnerEl.style.display = 'inline-block';
    if (btnTextEl && btnTextEl !== captureBtn) btnTextEl.textContent = 'Сканируем…';
    else captureBtn.textContent = 'Сканируем…';

    try {
        if (!video.videoWidth || !video.videoHeight) {
            alert('Камера ещё не готова');
            return;
        }

        // рисуем в канвас (создаём динамически)
        const canvas = document.createElement('canvas');
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        const ctx = canvas.getContext('2d');
        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

        // Ч/Б бинаризация (простая)
        const img = ctx.getImageData(0, 0, canvas.width, canvas.height);
        const data = img.data;
        for (let i = 0; i < data.length; i += 4) {
            const avg = (data[i] + data[i+1] + data[i+2]) / 3;
            const bw = avg > 128 ? 255 : 0;
            data[i] = data[i+1] = data[i+2] = bw;
        }
        ctx.putImageData(img, 0, 0);

        // превью
        await new Promise((resolve) => {
            canvas.toBlob((blob) => {
                if (!blob) {
                    alert('Не удалось получить изображение');
                    resolve(null);
                    return;
                }
                const url = URL.createObjectURL(blob);
                previewImg.src = url;

                const formData = new FormData();
                formData.append('image', blob, 'scan.jpg');

                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
                if (!csrfToken) {
                    alert('CSRF-токен не найден');
                    console.error('CSRF-токен отсутствует в <meta>');
                    resolve(null);
                    return;
                }

                fetch('/index.php?r=scan/upload', {
                    method: 'POST',
                    headers: { 'X-CSRF-Token': csrfToken },
                    body: formData,
                    credentials: 'include'
                })
                    .then(async r => {
                        const ct = r.headers.get('content-type') || '';
                        if (!ct.includes('application/json')) {
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
                            alert(res.error || 'Ошибка: не удалось распознать сумму');
                        }
                        resolve(true);
                    })
                    .catch(err => {
                        alert('Ошибка при отправке: ' + err.message);
                        console.error('Ошибка fetch:', err);
                        resolve(false);
                    });
            }, 'image/jpeg', 0.9);
        });

    } finally {
        scanBusy = false;
        captureBtn.disabled = false;
        if (btnSpinnerEl) btnSpinnerEl.style.display = 'none';
        if (btnTextEl && btnTextEl !== captureBtn) btnTextEl.textContent = '📸 Сфоткать';
        else captureBtn.textContent = '📸 Сфоткать';
    }
}

// ===== Сохранение записей =====
function bindEntrySaves() {
    document.querySelectorAll('.entry-form').forEach(form => {
        const saveBtn = form.querySelector('.save-entry');
        if (!saveBtn) return;

        saveBtn.onclick = (e) => {
            e.preventDefault();
            const formData = new FormData(form);
            const id = form.dataset.id;

            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            if (!csrfToken) {
                alert('CSRF-токен не найден');
                console.error('CSRF-токен отсутствует в <meta>');
                return;
            }

            fetch(`index.php?r=scan/update&id=${id}`, {
                method: 'POST',
                headers: { 'X-CSRF-Token': csrfToken },
                body: formData
            })
                .then(() => location.reload())
                .catch(err => {
                    alert('Ошибка сохранения: ' + err.message);
                    console.error('Ошибка при сохранении записи:', err);
                });
        };
    });
}

// ===== События =====
startBtn.onclick = async () => {
    wrap.style.display = 'block';
    try {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            alert('Доступ к камере не поддерживается в этом браузере');
            return;
        }
        await initCamera();
    } catch (e) {
        console.error('initCamera error:', e);
        alert('Не удалось открыть камеру: ' + (e?.message || e));
    }
};

captureBtn.onclick = captureAndSend;

// Инициализация обработчиков сохранения
bindEntrySaves();
