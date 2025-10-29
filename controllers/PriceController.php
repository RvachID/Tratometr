<?php

namespace app\controllers;

use app\models\PriceEntry;
use app\services\Price\PriceEntryService;
use app\services\Purchase\SessionManager;
use DomainException;
use Yii;
use yii\filters\AccessControl;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class PriceController extends Controller
{
    private PriceEntryService $priceEntryService;
    private SessionManager $sessionManager;

    public function init()
    {
        parent::init();
        $this->priceEntryService = Yii::$app->get('priceService');
        $this->sessionManager = Yii::$app->get('sessionManager');
    }

    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'only' => ['index', 'list', 'save', 'qty', 'delete'],
                'rules' => [
                    ['allow' => true, 'roles' => ['@']],
                ],
            ],
        ];
    }

    public function actionIndex()
    {
        return $this->render('index');
    }

    public function actionList($offset = 0, $limit = 50)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $data = $this->priceEntryService->getList(Yii::$app->user->id, (int)$offset, (int)$limit);

        return [
            'total' => number_format($data['totalAmount'], 2, '.', ''),
            'items' => array_map(function (PriceEntry $m) {
                return [
                    'id' => $m->id,
                    'created_at' => Yii::$app->formatter->asDatetime($m->created_at),
                    'store' => (string)$m->store,
                    'category' => (string)$m->category,
                    'amount' => (float)$m->amount,
                    'qty' => (float)$m->qty,
                    'rowTotal' => number_format($m->amount * $m->qty, 2, '.', ''),
                    'source' => (string)$m->source,
                    'note' => (string)$m->note,
                ];
            }, $data['items']),
            'hasMore' => $data['hasMore'],
        ];
    }

    public function actionSave()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $userId = Yii::$app->user->id;
        $session = $this->sessionManager->getActive($userId);
        if (!$session) {
            return ['error' => 'Нет активной сессии.'];
        }

        $id = (int)Yii::$app->request->post('id', 0);
        $model = $id
            ? PriceEntry::findOne(['id' => $id, 'user_id' => $userId])
            : new PriceEntry();

        if (!$model) {
            throw new NotFoundHttpException('Запись не найдена');
        }

        try {
            $result = $this->priceEntryService->saveManual(
                $userId,
                $session,
                $model,
                Yii::$app->request->post()
            );
        } catch (DomainException $e) {
            return ['error' => $e->getMessage()];
        }

        return [
            'id' => $result['entry']->id,
            'rowTotal' => number_format($result['entry']->amount * $result['entry']->qty, 2, '.', ''),
            'listTotal' => number_format($result['sessionTotal'], 2, '.', ''),
        ];
    }

    public function actionQty($id)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        try {
            $data = $this->priceEntryService->adjustQuantity(
                Yii::$app->user->id,
                (int)$id,
                (string)Yii::$app->request->post('op'),
                Yii::$app->request->post('value')
            );
        } catch (DomainException $e) {
            throw new NotFoundHttpException($e->getMessage());
        }

        return [
            'qty' => (float)$data['entry']->qty,
            'rowTotal' => number_format($data['entry']->amount * $data['entry']->qty, 2, '.', ''),
            'listTotal' => number_format($data['listTotal'], 2, '.', ''),
        ];
    }

    public function actionDelete($id)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        try {
            $listTotal = $this->priceEntryService->delete(Yii::$app->user->id, (int)$id);
        } catch (DomainException $e) {
            throw new NotFoundHttpException($e->getMessage());
        }

        return ['listTotal' => number_format($listTotal, 2, '.', '')];
    }

    public function actionGetLast()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        if (Yii::$app->user->isGuest) {
            return ['error' => 'Not authorized'];
        }

        $last = $this->priceEntryService->getLast(Yii::$app->user->id);
        if ($last) {
            return [
                'price' => $last['price'],
                'text' => $last['text'],
            ];
        }
        return ['price' => null];
    }
}
