<?php

namespace app\controllers;

use app\models\PriceEntry;
use Yii;
use yii\filters\AccessControl;
use yii\web\Controller;
use yii\web\Response;
use yii\filters\VerbFilter;
use app\models\LoginForm;
use app\models\ContactForm;
use app\models\PurchaseSession;
class SiteController extends Controller
{
    private const INACTIVITY_AUTOCLOSE_SEC = 10800;
    /**
     * {@inheritdoc}
     */
    public function behaviors()
    {
        return [
            'access' => [
                'class' => \yii\filters\AccessControl::class,
                'only' => ['logout'],
                'rules' => [
                    [
                        'actions' => ['logout'],
                        'allow'   => true,
                        'roles'   => ['@'],
                    ],
                ],
            ],
            'verbs' => [
                'class'   => \yii\filters\VerbFilter::class,
                'actions' => [
                    'logout'         => ['post'],
                    'close-session'  => ['post'],
                    'delete-session' => ['post'],
                ],
            ],
        ];
    }


    /**
     * {@inheritdoc}
     */
    public function actions()
    {
        return [
            'error' => [
                'class' => 'yii\web\ErrorAction',
            ],
            'captcha' => [
                'class' => 'yii\captcha\CaptchaAction',
                'fixedVerifyCode' => YII_ENV_TEST ? 'testme' : null,
            ],
        ];
    }


    /**
     * Login action.
     *
     * @return Response|string
     */
    public function actionLogin()
    {
        if (!Yii::$app->user->isGuest) {
            return $this->goHome();
        }

        $model = new LoginForm();
        if ($model->load(Yii::$app->request->post()) && $model->login()) {
            return $this->goBack();
        }

        $model->password = '';
        return $this->render('login', [
            'model' => $model,
        ]);
    }

    /**
     * Logout action.
     *
     * @return Response
     */
    public function actionLogout()
    {
        Yii::$app->user->logout();

        return $this->goHome();
    }

    /**
     * Displays contact page.
     *
     * @return Response|string
     */
    public function actionContact()
    {
        $model = new ContactForm();
        if ($model->load(Yii::$app->request->post()) && $model->contact(Yii::$app->params['adminEmail'])) {
            Yii::$app->session->setFlash('contactFormSubmitted');

            return $this->refresh();
        }
        return $this->render('contact', [
            'model' => $model,
        ]);
    }

    /**
     * Displays about page.
     *
     * @return string
     */
    public function actionAbout()
    {
        return $this->render('about');
    }

    // ----- страницы -----
    public function actionIndex()
    {
        if (Yii::$app->user->isGuest) {
            return $this->redirect(['auth/login']);
        }

        $entries = PriceEntry::find()
            ->where(['user_id' => Yii::$app->user->id])
            ->orderBy(['created_at' => SORT_DESC])
            ->limit(20)
            ->all();

        $total = array_reduce($entries, fn($sum, $e) => $sum + $e->amount * $e->qty, 0);

        // Активная покупка для панели на главной
        $ps = $this->getActivePurchaseSession();
        $psInfo = null;
        if ($ps) {
            $lastTs = $this->getLastActivityTs($ps);
            $psInfo = [
                'id'        => $ps->id,
                'shop'      => $ps->shop,
                'category'  => $ps->category,
                'startedAt' => $ps->started_at,
                'lastTs'    => $lastTs,
                'limit'     => $ps->limit_amount,
            ];
        }


        $db = Yii::$app->db;
        $lastQuoteId = Yii::$app->session->get('last_quote_id');
        $quotesTotal = (new \yii\db\Query())->from('quotes')->count('*', $db);
        $quote = null;
        if ($quotesTotal > 0) {
            $q = (new \yii\db\Query())->from('quotes');
            if ($quotesTotal > 1 && $lastQuoteId) $q->where(['<>','id',$lastQuoteId]);
            $quote = $q->orderBy(new \yii\db\Expression('RAND()'))->limit(1)->one($db)
                ?: (new \yii\db\Query())->from('quotes')->orderBy(new \yii\db\Expression('RAND()'))->limit(1)->one($db);
            if ($quote) Yii::$app->session->set('last_quote_id', $quote['id']);
        }

        return $this->render('index', [
            'entries' => $entries,
            'total'   => $total,
            'quote'   => $quote,
            'psInfo'  => $psInfo,
        ]);
    }


    // страница сканера
    public function actionScan()
    {
        if (Yii::$app->user->isGuest) return $this->redirect(['auth/login']);

        $ps = $this->getActivePurchaseSession();
        if (!$ps) {
            // Нет активной — пусть фронт покажет модалку выбора
            return $this->render('scan', [
                'store'      => '',
                'category'   => '',
                'entries'    => [],
                'total'      => 0.0,
                'needPrompt' => true,
            ]);
        }

        // Позиции ТОЛЬКО текущей сессии
        $entries = PriceEntry::find()
            ->where(['user_id'=>Yii::$app->user->id, 'session_id'=>$ps->id])
            ->orderBy(['id'=>SORT_DESC])
            ->limit(200)
            ->all();

        $db = Yii::$app->db;
        $total = (float)$db->createCommand(
            'SELECT COALESCE(SUM(amount*qty),0) FROM price_entry WHERE user_id=:u AND session_id=:sid',
            [':u'=>Yii::$app->user->id, ':sid'=>$ps->id]
        )->queryScalar();

        // подправим "живость"
        $ps->updateAttributes(['updated_at'=>time()]);

        return $this->render('scan', [
            'store'      => $ps->shop,
            'category'   => $ps->category,
            'entries'    => $entries,
            'total'      => $total,
            'needPrompt' => false, // активная есть
        ]);
    }


    public function actionSessionStatus()
    {
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

        if (Yii::$app->user->isGuest) {
            return ['ok'=>false, 'needPrompt'=>true];
        }

        $ps = $this->getActivePurchaseSession();
        if (!$ps) {
            return [
                'ok'         => true,
                'needPrompt' => true,
                'store'      => '',
                'category'   => '',
                'idle'       => null,
            ];
        }

        $lastTs = $this->getLastActivityTs($ps);
        return [
            'ok'         => true,
            'needPrompt' => false,
            'store'      => (string)$ps->shop,
            'category'   => (string)$ps->category,
            'idle'       => time() - $lastTs,
        ];
    }


    public function actionBeginAjax()
    {
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

        if (Yii::$app->user->isGuest) {
            return ['ok'=>false, 'error'=>'Требуется вход'];
        }

        $store    = trim((string)Yii::$app->request->post('store', ''));
        $category = trim((string)Yii::$app->request->post('category', ''));
        if ($store === '') return ['ok' => false, 'error' => 'Укажите магазин'];

        // Закрываем любую активную — одна активная на юзера
        PurchaseSession::updateAll(
            ['status'=>PurchaseSession::STATUS_CLOSED, 'updated_at'=>time()],
            ['user_id'=>Yii::$app->user->id, 'status'=>PurchaseSession::STATUS_ACTIVE]
        );

        $ps = new PurchaseSession([
            'user_id'  => Yii::$app->user->id,
            'shop'     => $store,
            'category' => $category,
            'status'   => PurchaseSession::STATUS_ACTIVE,
        ]);
        $ps->save(false);

        Yii::$app->session->set('purchase_session_id', $ps->id);

        return ['ok'=>true, 'store'=>$store, 'category'=>$category];
    }


    /** Берём активную сессию из БД (восстанавливаем даже если PHP-сессия умерла) */
    private function getActivePurchaseSession(): ?PurchaseSession
    {
        if (Yii::$app->user->isGuest) return null;

        $sid = Yii::$app->session->get('purchase_session_id');
        if ($sid) {
            $ps = PurchaseSession::findOne([
                'id' => $sid,
                'user_id' => Yii::$app->user->id,
                'status' => PurchaseSession::STATUS_ACTIVE
            ]);
            if ($ps) { $this->autoCloseIfStale($ps); return $ps->status === PurchaseSession::STATUS_ACTIVE ? $ps : null; }
        }

        $ps = PurchaseSession::find()
            ->where(['user_id'=>Yii::$app->user->id, 'status'=>PurchaseSession::STATUS_ACTIVE])
            ->orderBy(['updated_at'=>SORT_DESC])->limit(1)->one();

        if ($ps) {
            Yii::$app->session->set('purchase_session_id', $ps->id);
            $this->autoCloseIfStale($ps);
            return $ps->status === PurchaseSession::STATUS_ACTIVE ? $ps : null;
        }
        return null;
    }

    /** Последняя активность: последний чек в сессии, иначе старт */
    private function getLastActivityTs(PurchaseSession $ps): int
    {
        $last = (new \yii\db\Query())
            ->from('price_entry')
            ->where(['session_id'=>$ps->id, 'user_id'=>Yii::$app->user->id])
            ->max('created_at');
        return (int)($last ?: $ps->started_at);
    }

    /** Автозакрытие по 3ч неактивности */
    private function autoCloseIfStale(PurchaseSession $ps): void
    {
        $lastTs = $this->getLastActivityTs($ps);
        if (time() - $lastTs >= self::INACTIVITY_AUTOCLOSE_SEC) {
            $ps->updateAttributes(['status'=>PurchaseSession::STATUS_CLOSED, 'updated_at'=>time()]);
            Yii::$app->session->remove('purchase_session_id');
        }
    }


    public function actionCloseSession()
    {
        if (Yii::$app->user->isGuest) return $this->redirect(['auth/login']);
        $ps = $this->getActivePurchaseSession();
        if ($ps) {
            $ps->updateAttributes(['status'=>PurchaseSession::STATUS_CLOSED, 'updated_at'=>time()]);
            Yii::$app->session->remove('purchase_session_id');
            Yii::$app->session->setFlash('success','Покупка закрыта.');
        }
        return $this->redirect(['site/index']);
    }

    public function actionDeleteSession()
    {
        if (Yii::$app->user->isGuest) return $this->redirect(['auth/login']);
        $ps = $this->getActivePurchaseSession();
        if ($ps) {
            PriceEntry::deleteAll(['user_id'=>Yii::$app->user->id, 'session_id'=>$ps->id]);
            PurchaseSession::deleteAll(['id'=>$ps->id, 'user_id'=>Yii::$app->user->id]);
            Yii::$app->session->remove('purchase_session_id');
            Yii::$app->session->setFlash('success','Покупка удалена.');
        }
        return $this->redirect(['site/index']);
    }

}
