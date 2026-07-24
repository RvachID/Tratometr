<?php

namespace app\controllers;

use app\services\Alice\AliceAccountLinkService;
use DomainException;
use Yii;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;

class AliceLinkController extends Controller
{
    public function behaviors(): array
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'only' => ['index', 'unlink'],
                'rules' => [
                    [
                        'allow' => true,
                        'roles' => ['@'],
                    ],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'unlink' => ['POST'],
                ],
            ],
        ];
    }

    public function actionIndex()
    {
        $service = new AliceAccountLinkService();
        $userId = (int)Yii::$app->user->id;

        if (Yii::$app->request->isPost) {
            try {
                $service->claimCode($userId, (string)Yii::$app->request->post('code'));
                Yii::$app->session->setFlash('success', 'Навык Алисы привязан к вашему аккаунту.');
                return $this->refresh();
            } catch (DomainException $e) {
                Yii::$app->session->setFlash('error', $e->getMessage());
            }
        }

        return $this->render('index', [
            'link' => $service->getLinkForUser($userId),
        ]);
    }

    public function actionUnlink()
    {
        (new AliceAccountLinkService())->unlinkUser((int)Yii::$app->user->id);
        Yii::$app->session->setFlash('success', 'Привязка Алисы отключена.');

        return $this->redirect(['index']);
    }
}
