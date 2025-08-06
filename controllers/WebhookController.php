<?php

namespace app\controllers;

use Yii;
use yii\web\Controller;
use yii\web\Response;
use app\models\User;

class WebhookController extends Controller
{
    public $enableCsrfValidation = false;

    public function actionIndex()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $raw = Yii::$app->request->getRawBody();
        $this->log('@runtime/webhook.log', $raw);

        try {
            $u = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

            $msg          = $u['message'] ?? [];
            $chatId       = $msg['chat']['id']    ?? null;
            $tgUserId     = $msg['from']['id']    ?? null;
            $username     = $msg['from']['username'] ?? null;
            $firstName    = $msg['from']['first_name'] ?? null;
            $text         = trim($msg['text'] ?? '');
            $date         = $msg['date'] ?? null;

            // 1) Логируем текстовые сообщения в telegram_message (как было)
            if ($chatId || $tgUserId || $text) {
                Yii::$app->db->createCommand()->insert('telegram_message', [
                    'chat_id'    => $chatId,
                    'user_id'    => $tgUserId,
                    'username'   => $username,
                    'first_name' => $firstName,
                    'text'       => $text,
                    'date'       => $date,
                ])->execute();
            }

            $botToken = getenv('BOT_TOKEN');
            if (!$botToken) {
                throw new \RuntimeException('BOT_TOKEN is not set');
            }

            // 2) Обработка команды /scan — запрос фото у пользователя
            if ($text === '/scan' || $text === 'scan') {
                $this->sendMessage($chatId, "Отправьте фото ценника 📷");
                return ['status' => 'wait_photo'];
            }

            // 3) Если пришло фото
            if (!empty($msg['photo'])) {
                $largestPhoto = end($msg['photo']); // последнее — самое большое
                $fileId = $largestPhoto['file_id'];

                // 3.1 Получаем путь к файлу с серверов Telegram
                $fileInfo = $this->getFile($fileId, $botToken);
                $fileUrl = "https://api.telegram.org/file/bot{$botToken}/{$fileInfo['file_path']}";

                // 3.2 Скачиваем во временный файл
                $tempPath = Yii::getAlias('@runtime') . '/' . basename($fileInfo['file_path']);
                file_put_contents($tempPath, file_get_contents($fileUrl));

                // 3.3 Обрабатываем изображение (контраст, обрезка, ч/б)
                $processedPath = $this->processImage($tempPath);

                // 3.4 Распознаём через OCR
                $ocrResult = $this->processOcr($processedPath);

                // 3.5 Ищем пользователя по telegram_id
                $user = User::findOne(['telegram_id' => $tgUserId]);
                if ($user) {
                    Yii::$app->db->createCommand()->insert('price_entry', [
                        'user_id' => $user->id,
                        'recognized_amount' => $ocrResult['amount'],
                        'recognized_text' => $ocrResult['text'],
                        'photo_path' => $fileUrl,
                        'source' => 'bot',
                        'created_at' => time(),
                        'updated_at' => time(),
                    ])->execute();
                }

                // 3.6 Отправляем inline‑кнопку для открытия mini app
                $this->sendMessage($chatId, "✅ Готово! Открываю приложение...", [
                    'inline_keyboard' => [
                        [[
                            'text' => 'Открыть приложение',
                            'web_app' => ['url' => 'https://tratometr.yourdomain.com/price/index']
                        ]]
                    ]
                ]);

                return ['status' => 'photo_processed', 'amount' => $ocrResult['amount']];
            }

            // 4) Эхо для остальных текстов (тест)
            if ($chatId && $text !== '') {
                $this->sendMessage($chatId, 'Ты написал: ' . $text);
            }

            return ['status' => 'ok'];
        } catch (\Throwable $e) {
            $this->log('@runtime/webhook_error.log',
                sprintf("[%s] %s\n%s", date('Y-m-d H:i:s'), $e->getMessage(), $e->getTraceAsString())
            );
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Логирование в файл
     */
    private function log(string $aliasPath, string $line): void
    {
        $file = Yii::getAlias($aliasPath);
        @file_put_contents($file, sprintf("[%s] %s\n", date('Y-m-d H:i:s'), $line), FILE_APPEND);
    }

    /**
     * Отправка сообщения в Telegram
     */
    private function sendMessage($chatId, $text, $replyMarkup = null)
    {
        $botToken = getenv('BOT_TOKEN');
        $data = [
            'chat_id' => $chatId,
            'text' => $text,
        ];
        if ($replyMarkup) {
            $data['reply_markup'] = json_encode($replyMarkup);
        }

        file_get_contents(
            'https://api.telegram.org/bot' . $botToken . '/sendMessage?' . http_build_query($data)
        );
    }

    /**
     * Получение информации о файле через API Telegram
     */
    private function getFile($fileId, $botToken)
    {
        $resp = file_get_contents('https://api.telegram.org/bot' . $botToken . '/getFile?file_id=' . $fileId);
        $data = json_decode($resp, true);
        return $data['result'] ?? [];
    }

    /**
     * Обработка изображения (обрезка центра, контраст, ч/б)
     */
    private function processImage($inputPath)
    {
        $outputPath = Yii::getAlias('@runtime') . '/' . uniqid('proc_') . '.jpg';
        $imagick = new \Imagick($inputPath);

        // Ресайз до макс 1024 px
        $imagick->resizeImage(1024, 1024, \Imagick::FILTER_LANCZOS, 1, true);

        // Обрезка центральной области (90% ширины, 80% высоты)
        $width = $imagick->getImageWidth();
        $height = $imagick->getImageHeight();
        $cropWidth = (int)($width * 0.9);
        $cropHeight = (int)($height * 0.8);
        $startX = (int)(($width - $cropWidth) / 2);
        $startY = (int)(($height - $cropHeight) / 2);
        $imagick->cropImage($cropWidth, $cropHeight, $startX, $startY);

        // Усиление контраста
        $imagick->contrastImage(true);
        $imagick->modulateImage(100, 200, 100);

        // Ч/б
        $imagick->setImageType(\Imagick::IMGTYPE_GRAYSCALE);

        $imagick->writeImage($outputPath);
        $imagick->clear();
        $imagick->destroy();

        return $outputPath;
    }

    /**
     * Распознавание текста через OCR.Space
     */
    private function processOcr($imagePath)
    {
        $apikey = 'K82943706188957';
        $formData = [
            'apikey' => $apikey,
            'language' => 'rus',
            'isOverlayRequired' => true,
            'file' => new \CURLFile($imagePath)
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.ocr.space/parse/image');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $formData);
        $result = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($result, true);
        $text = $data['ParsedResults'][0]['ParsedText'] ?? '';
        $amount = null;

        // Ищем наибольшую сумму по размеру шрифта
        if (!empty($data['ParsedResults'][0]['TextOverlay']['Lines'])) {
            foreach ($data['ParsedResults'][0]['TextOverlay']['Lines'] as $line) {
                foreach ($line['Words'] as $word) {
                    $clean = preg_replace('/[^\d.,]/', '', $word['WordText']);
                    $val = floatval(str_replace(',', '.', $clean));
                    if ($val > $amount) {
                        $amount = $val;
                    }
                }
            }
        }

        return ['amount' => $amount, 'text' => $text];
    }
}
