<?php

namespace app\controllers;

use app\services\Price\PriceEntryService;
use app\services\Purchase\SessionManager;
use app\services\Scan\ScanService;
use DomainException;
use Yii;
use yii\filters\RateLimiter;
use yii\web\Controller;
use yii\web\Response;
use yii\web\UploadedFile;

class ScanController extends Controller
{
    public $enableCsrfValidation = true;

    private ScanService $scanService;
    private PriceEntryService $priceEntryService;
    private SessionManager $sessionManager;

    public function init()
    {
        parent::init();
        $this->scanService = Yii::$app->get('scanService');
        $this->priceEntryService = Yii::$app->get('priceService');
        $this->sessionManager = Yii::$app->get('sessionManager');
    }

    public function beforeAction($action)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        if ($action->id === 'recognize') {
            $this->enableCsrfValidation = false;
        }

        if (Yii::$app->user->isGuest) {
            Yii::$app->response->statusCode = 401;
            Yii::$app->response->data = ['success' => false, 'error' => 'Требуется авторизация'];
            return false;
        }
        return parent::beforeAction($action);
    }

    public function behaviors()
    {
        $b = parent::behaviors();
        $b['rateLimiter'] = [
            'class' => RateLimiter::class,
            'enableRateLimitHeaders' => true,
            'only' => ['recognize'],
        ];
        return $b;
    }

    public function actionRecognize()
    {
        $image = UploadedFile::getInstanceByName('image');
        $result = $this->scanService->recognize($image);

        if (!$result->success) {
            return [
                'success' => false,
                'error' => $result->error ?? 'Ошибка распознавания',
                'reason' => $result->reason,
            ];
        }

        return [
            'success' => true,
            'recognized_amount' => (float)$result->amount,
            'parsed_text' => (string)$result->parsedText,
            'pass' => $result->pass,
        ];
    }

    public function actionStore()
    {
        try {
            $userId  = Yii::$app->user->id;
            $session = $this->sessionManager->requireActive($userId);

            $amount = (float)Yii::$app->request->post('amount');
            $qty    = (float)Yii::$app->request->post('qty', 1);
            $note   = (string)Yii::$app->request->post('note', '');
            $text   = (string)Yii::$app->request->post('parsed_text', '');
            $aliceItemId = Yii::$app->request->post('alice_item_id');

            $result = $this->priceEntryService->createFromScan(
                $userId,
                $session,
                $amount,
                $qty,
                $note,
                $text,
                $aliceItemId ? (int)$aliceItemId : null
            );

            $entry = $result['entry'];

            return [
                'success' => true,
                'entry'   => [
                    'id'          => $entry->id,
                    'amount'      => (float)$entry->amount,
                    'qty'         => (float)$entry->qty,
                    'note'        => (string)$entry->note,
                    'store'       => (string)$entry->store,
                    'category'    => $entry->category,
                    'alice_title' => $entry->aliceItem ? $entry->aliceItem->title : null,
                ],
                'total' => number_format($result['listTotal'], 2, '.', ''),
            ];
        } catch (DomainException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        } catch (\Throwable $e) {
            Yii::error($e->getMessage()."\n".$e->getTraceAsString(), __METHOD__);
            return ['success' => false, 'error' => 'Внутренняя ошибка сервиса'];
        }
    }


    public function actionUpdate($id)
    {
        try {
            $userId = Yii::$app->user->id;
            $session = $this->sessionManager->requireActive($userId);

            $result = $this->priceEntryService->updateFromScan(
                $userId,
                $session,
                (int)$id,
                Yii::$app->request->post()
            );

            return [
                'success' => true,
                'total' => number_format($result['sessionTotal'], 2, '.', ''),
            ];
        } catch (DomainException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        } catch (\Throwable $e) {
            Yii::error($e->getMessage(), __METHOD__);
            return ['success' => false, 'error' => 'Внутренняя ошибка сервиса'];
        }
    }

    public function actionDelete($id)
    {
        try {
            $userId = Yii::$app->user->id;
            $session = $this->sessionManager->requireActive($userId);

            $total = $this->priceEntryService->deleteFromSession($userId, $session, (int)$id);

            return [
                'success' => true,
                'total' => number_format($total, 2, '.', ''),
            ];
        } catch (DomainException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        } catch (\Throwable $e) {
            Yii::error($e->getMessage(), __METHOD__);
            return ['success' => false, 'error' => 'Внутренняя ошибка сервиса'];
        }
    }
}
