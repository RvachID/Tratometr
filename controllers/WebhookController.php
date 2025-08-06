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

            // Логируем текстовые сообщения в telegram_message
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

            // === Обработка команд ===
            if ($text) {
                // /start scan
                if (strpos($text, '/start') === 0) {
                    $parts = explode(' ', $text, 2);
                    $param = $parts[1] ?? '';
                    if ($param === 'scan') {
                        $this->sendMessage($chatId, "Отправьте фото ценника 📷");
                        return ['status' => 'wait_photo_start_param'];
                    }
                }
                // /scan
                if ($text === '/scan' || $text === 'scan') {
                    $this->sendMessage($chatId, "Отправьте фото ценника 📷");
                    return ['status' => 'wait_photo_command'];
                }
            }

            // === Приём фото ===
            if (!empty($msg['photo'])) {
                $largestPhoto = end($msg['photo']);
                $fileId = $largestPhoto['file_id'];

                // Получаем путь к файлу
                $fileInfo = $this->getFile($fileId, $botToken);
                $fileUrl = "https://api.telegram.org/file/bot{$botToken}/{$fileInfo['file_path']}";

                // Скачиваем во временный файл
                $tempPath = Yii::getAlias('@runtime') . '/' . basename($fileInfo['file_path']);
                file_put_contents($tempPath, file_get_contents($fileUrl));

                // Обработка изображения
                $processedPath = $this->processImage($tempPath);

                // OCR
                $ocrResult = $this->processOcr($processedPath);

                // Сохраняем в price_entry
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

                // Кнопка для открытия mini app
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

            // Эхо для прочих текстов
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

    private function log(string $aliasPath, string $line): void
    {
        $file = Yii::getAlias($aliasPath);
        @file_put_contents($file, sprintf("[%s] %s\n", date('Y-m-d H:i:s'), $line), FILE_APPEND);
    }

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

    private function getFile($fileId, $botToken)
    {
        $resp = file_get_contents('https://api.telegram.org/bot' . $botToken . '/getFile?file_id=' . $fileId);
        $data = json_decode($resp, true);
        return $data['result'] ?? [];
    }

    private function processImage($inputPath)
    {
        $outputPath = Yii::getAlias('@runtime') . '/' . uniqid('proc_') . '.jpg';
        $imagick = new \Imagick($inputPath);

        $imagick->resizeImage(1024, 1024, \Imagick::FILTER_LANCZOS, 1, true);

        $width = $imagick->getImageWidth();
        $height = $imagick->getImageHeight();
        $cropWidth = (int)($width * 0.9);
        $cropHeight = (int)($height * 0.8);
        $startX = (int)(($width - $cropWidth) / 2);
        $startY = (int)(($height - $cropHeight) / 2);
        $imagick->cropImage($cropWidth, $cropHeight, $startX, $startY);

        $imagick->contrastImage(true);
        $imagick->modulateImage(100, 200, 100);

        $imagick->setImageType(\Imagick::IMGTYPE_GRAYSCALE);

        $imagick->writeImage($outputPath);
        $imagick->clear();
        $imagick->destroy();

        return $outputPath;
    }

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
