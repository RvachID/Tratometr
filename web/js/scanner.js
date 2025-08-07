const video = document.getElementById('camera');
const canvas = document.createElement('canvas');
const startBtn = document.getElementById('start-scan');
const captureBtn = document.getElementById('capture');
const cameraWrapper = document.getElementById('camera-wrapper');

startBtn.onclick = async () => {
    const stream = await navigator.mediaDevices.getUserMedia({video: true});
    video.srcObject = stream;
    cameraWrapper.style.display = 'block';
};

captureBtn.onclick = () => {
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    canvas.getContext('2d').drawImage(video, 0, 0);

    canvas.toBlob(blob => {
        const formData = new FormData();
        formData.append('image', blob, 'scan.jpg');

        fetch('/index.php?r=scan/upload', {
            method: 'POST',
            body: formData
        })
            .then(r => r.json())
            .then(res => {
                alert('Распознанный текст: ' + res.text);
                location.reload(); // Перезагружаем для обновления списка
            });
    }, 'image/jpeg');
};

// сохранение отредактированных записей
document.querySelectorAll('.entry-form').forEach(form => {
    form.querySelector('.save-entry').onclick = e => {
        e.preventDefault();
        const formData = new FormData(form);
        const id = form.dataset.id;

        fetch(`/index.php?r=scan/update&id=${id}`, {
            method: 'POST',
            body: formData
        }).then(() => location.reload());
    };
});
