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
    }
};

// 📸 Сфоткать и отправить
captureBtn.onclick = () => {
    if (!video.videoWidth || !video.videoHeight) {
        alert('Камера ещё не готова');
        return;
    }

    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    const ctx = canvas.getContext('2d');
    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

    canvas.toBlob(blob => {
        if (!blob) {
            alert('Не удалось получить изображение');
            return;
        }

        const formData = new FormData();
        formData.append('image', blob, 'scan.jpg');

        fetch('/index.php?r=scan/upload', {
            method: 'POST',
            body: formData
        })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    alert('Распознано: ' + res.text + '\nСумма: ' + res.amount);
                    location.reload();
                } else {
                    alert('Ошибка: не удалось распознать сумму');
                }
            })
            .catch(err => alert('Ошибка при отправке: ' + err.message));
    }, 'image/jpeg');
};

// 💾 Сохранение изменений в записях
document.querySelectorAll('.entry-form').forEach(form => {
    const saveBtn = form.querySelector('.save-entry');
    if (!saveBtn) return;

    saveBtn.onclick = e => {
        e.preventDefault();
        const formData = new FormData(form);
        const id = form.dataset.id;

        fetch(`/index.php?r=scan/update&id=${id}`, {
            method: 'POST',
            body: formData
        })
            .then(() => location.reload())
            .catch(err => alert('Ошибка сохранения: ' + err.message));
    };
});
