<?php
/** @var yii\web\View $this */
use yii\helpers\Url;
use yii\helpers\Html;

$this->title = 'Траты';
$listUrl = Url::to(['price/list']);
$saveUrl = Url::to(['price/save']);
$qtyUrl  = Url::to(['price/qty']);
$delUrl  = Url::to(['price/delete']);
$csrf = Yii::$app->request->getCsrfToken();
?>

<?php
$this->title = 'Сканер цен';
?>
<script src="https://cdn.jsdelivr.net/npm/axios/dist/axios.min.js"></script>
<style>
    .scan-btn {
        display: block;
        width: 80%;
        max-width: 300px;
        margin: 40px auto;
        padding: 15px;
        font-size: 20px;
        background-color: #4CAF50;
        color: white;
        border: none;
        border-radius: 8px;
        cursor: pointer;
    }
    .scan-btn:active { background-color: #45a049; }
    #result { text-align: center; margin-top: 20px; font-size: 24px; font-weight: bold; }
    #spinner { display: none; text-align: center; margin-top: 20px; }
</style>

<input type="file" id="imageInput" accept="image/*" capture="environment" style="display:none">
<button id="scanBtn" class="scan-btn">📷 Сканировать</button>
<div id="spinner">⏳ Обработка...</div>
<div id="result"></div>

<script>
    const scanBtn = document.getElementById('scanBtn');
    const imageInput = document.getElementById('imageInput');
    const spinner = document.getElementById('spinner');
    const resultDiv = document.getElementById('result');

    scanBtn.addEventListener('click', () => imageInput.click());

    if (imageInput) {
        imageInput.addEventListener("change", function () {
            const file = imageInput.files[0];
            if (!file || file.size === 0 || !file.type) {
                alert("Изображение повреждено или не выбрано.");
                return;
            }
            if (!file.type.startsWith("image/")) {
                alert("Файл не является изображением.");
                return;
            }

            spinner.style.display = "block";
            resultDiv.innerText = "";

            const reader = new FileReader();
            reader.onload = function (e) {
                const img = new Image();
                img.onload = function () {
                    if (img.width === 0 || img.height === 0) {
                        spinner.style.display = "none";
                        alert("Ошибка: изображение пустое.");
                        return;
                    }

                    // 1. Ресайз
                    const maxSize = 1024;
                    const scale = Math.min(maxSize / img.width, maxSize / img.height);
                    const resizedCanvas = document.createElement("canvas");
                    const rctx = resizedCanvas.getContext("2d");
                    resizedCanvas.width = img.width * scale;
                    resizedCanvas.height = img.height * scale;
                    rctx.drawImage(img, 0, 0, resizedCanvas.width, resizedCanvas.height);

                    // 2. Обрезка центральной области
                    const cropCanvas = document.createElement("canvas");
                    const cctx = cropCanvas.getContext("2d");
                    const cropWidth = resizedCanvas.width * 0.9;  // 90% ширины
                    const cropHeight = resizedCanvas.height * 0.8; // 80% высоты
                    const startX = (resizedCanvas.width - cropWidth) / 2;
                    const startY = (resizedCanvas.height - cropHeight) / 2;
                    cropCanvas.width = cropWidth;
                    cropCanvas.height = cropHeight;
                    cctx.drawImage(resizedCanvas, startX, startY, cropWidth, cropHeight, 0, 0, cropWidth, cropHeight);

                    // 3. Усиление контраста и перевод в ч/б
                    const imageData = cctx.getImageData(0, 0, cropWidth, cropHeight);
                    const data = imageData.data;
                    const contrast = 40; // процент усиления (0-100)
                    const factor = (259 * (contrast + 255)) / (255 * (259 - contrast));

                    for (let i = 0; i < data.length; i += 4) {
                        // усиление контраста
                        data[i]   = truncate(factor * (data[i] - 128) + 128);
                        data[i+1] = truncate(factor * (data[i+1] - 128) + 128);
                        data[i+2] = truncate(factor * (data[i+2] - 128) + 128);
                        // преобразование в ч/б (среднее)
                        const avg = (data[i] + data[i+1] + data[i+2]) / 3;
                        const bw = avg > 128 ? 255 : 0;
                        data[i] = data[i+1] = data[i+2] = bw;
                    }
                    cctx.putImageData(imageData, 0, 0);

                    // 4. Конвертируем в base64
                    const compressedDataUrl = cropCanvas.toDataURL("image/jpeg", 0.9);

                    // 5. Отправка в OCR.Space
                    const formData = new FormData();
                    formData.append("base64Image", compressedDataUrl);
                    formData.append("apikey", "K82943706188957"); // твой API ключ
                    formData.append("language", "rus");
                    formData.append("isOverlayRequired", true);

                    axios.post("https://api.ocr.space/parse/image", formData)
                        .then(response => {
                            spinner.style.display = "none";
                            const overlay = response.data?.ParsedResults?.[0]?.TextOverlay?.Lines;
                            if (Array.isArray(overlay)) {
                                extractLargestPrice(overlay);
                            } else {
                                resultDiv.innerText = "Не удалось распознать текст.";
                            }
                        })
                        .catch(error => {
                            spinner.style.display = "none";
                            resultDiv.innerText = "Ошибка OCR: " + error.message;
                        });
                };
                img.onerror = function () {
                    spinner.style.display = "none";
                    alert("Ошибка загрузки изображения.");
                };
                img.src = e.target.result;
            };
            reader.readAsDataURL(file);
        });
    }

    function truncate(value) {
        return Math.min(255, Math.max(0, value));
    }

    function extractLargestPrice(overlay) {
        let maxFontSize = 0;
        let maxPrice = 0;
        overlay.forEach(line => {
            line.Words.forEach(word => {
                if (!word.IsStrikethrough && !/%/.test(word.WordText)) {
                    const cleanText = word.WordText.replace(/[^\d.,]/g, '');
                    const numericValue = parseFloat(cleanText.replace(',', '.'));
                    const fontSize = Math.abs(word.Height);
                    if (!isNaN(numericValue) && numericValue > 0 && fontSize > maxFontSize) {
                        maxFontSize = fontSize;
                        maxPrice = numericValue;
                    }
                }
            });
        });
        if (maxPrice > 0) {
            resultDiv.innerText = "Цена: " + maxPrice;
        } else {
            resultDiv.innerText = "Цена не найдена.";
        }
    }
</script>
