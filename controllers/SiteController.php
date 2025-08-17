<?php
namespace app\controllers;

use Yii;
use yii\web\Controller;
use yii\web\Response;
use yii\filters\VerbFilter;
use yii\filters\AccessControl;
use yii\db\Expression;
use yii\db\Query;
use app\models\PriceEntry;
use app\models\PurchaseSession;

class SiteController extends Controller
{
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'only'  => ['logout'],
                'rules' => [[ 'actions' => ['logout'], 'allow' => true, 'roles' => ['@'] ]],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'logout'         => ['post'],
                    'close-session'  => ['post'],
                    'delete-session' => ['post'],
                ],
            ],
        ];
    }

    public function actions()
    {
        return [
            'error'   => ['class' => 'yii\web\ErrorAction'],
            'captcha' => ['class' => 'yii\captcha\CaptchaAction', 'fixedVerifyCode' => YII_ENV_TEST ? 'testme' : null],
        ];
    }

    public function actionLogin()
    {
        if (!Yii::$app->user->isGuest) return $this->goHome();
        $model = new \app\models\LoginForm();
        if ($model->load(Yii::$app->request->post()) && $model->login()) return $this->goBack();
        $model->password = '';
        return $this->render('login', ['model' => $model]);
    }

    public function actionLogout()
    {
        Yii::$app->user->logout();
        return $this->goHome();
    }

    public function actionIndex()
    {
        if (Yii::$app->user->isGuest) return $this->redirect(['auth/login']);

        $entries = PriceEntry::find()
            ->where(['user_id' => Yii::$app->user->id])
            ->orderBy(['created_at' => SORT_DESC])
            ->limit(20)->all();

        $total = array_reduce($entries, fn($s,$e) => $s + $e->amount * $e->qty, 0);

        // активная покупка для панели
        $ps = Yii::$app->ps->active(Yii::$app->user->id);
        $psInfo = null;
        if ($ps) {
            $lastTs = Yii::$app->ps->lastActivityTs($ps);
            $psInfo = [
                'id'       => $ps->id,
                'shop'     => $ps->shop,
                'category' => $ps->category,
                'lastTs'   => $lastTs,
                'limit'    => $ps->limit_amount,
            ];
        }

        // цитата (как было)
        $db = Yii::$app->db;
        $lastQuoteId = Yii::$app->session->get('last_quote_id');
        $quotesTotal = (new Query())->from('quotes')->count('*', $db);
        $quote = null;
        if ($quotesTotal > 0) {
            $q = (new Query())->from('quotes');
            if ($quotesTotal > 1 && $lastQuoteId) $q->where(['<>','id',$lastQuoteId]);
            $quote = $q->orderBy(new Expression('RAND()'))->limit(1)->one($db)
                ?: (new Query())->from('quotes')->orderBy(new Expression('RAND()'))->limit(1)->one($db);
            if ($quote) Yii::$app->session->set('last_quote_id', $quote['id']);
        }

        return $this->render('index', compact('entries','total','quote','psInfo'));
    }

    /** Страница сканера */
    public function actionScan()
    {
        if (Yii::$app->user->isGuest) return $this->redirect(['auth/login']);

        $ps = Yii::$app->ps->active(Yii::$app->user->id);
        if (!$ps) {
            return $this->render('scan', [
                'store' => '', 'category' => '', 'entries' => [], 'total' => 0.0, 'needPrompt' => true,
            ]);
        }

        $entries = PriceEntry::find()
            ->where(['user_id'=>Yii::$app->user->id, 'session_id'=>$ps->id])
            ->orderBy(['id'=>SORT_DESC])->limit(200)->all();

        $total = (float) PriceEntry::find()
            ->where(['user_id'=>Yii::$app->user->id, 'session_id'=>$ps->id])
            ->sum('amount * qty');

        return $this->render('scan', [
            'store' => $ps->shop,
            'category' => $ps->category,
            'entries' => $entries,
            'total' => $total,
            'needPrompt' => false,
        ]);
    }

    /** Для фронта: есть ли активная покупка и сколько простаиваем */
    public function actionSessionStatus()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        if (Yii::$app->user->isGuest) return ['ok'=>false, 'needPrompt'=>true];

        $ps = Yii::$app->ps->active(Yii::$app->user->id);
        if (!$ps) return ['ok'=>true, 'needPrompt'=>true, 'store'=>'', 'category'=>'', 'idle'=>null];

        $idle = time() - Yii::$app->ps->lastActivityTs($ps);
        return [
            'ok' => true,
            'needPrompt' => false,
            'store' => (string)$ps->shop,
            'category' => (string)$ps->category,
            'idle' => $idle,
        ];
    }

    /** Создание новой серверной сессии из модалки */
    public function actionBeginAjax()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        if (Yii::$app->user->isGuest) return ['ok'=>false, 'error'=>'Требуется вход'];

        $store    = trim((string)Yii::$app->request->post('store', ''));
        $category = trim((string)Yii::$app->request->post('category', ''));
        if ($store === '') return ['ok'=>false, 'error'=>'Укажите магазин'];

        // ровно одна активная
        Yii::$app->ps->closeActive(Yii::$app->user->id);

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

    public function actionCloseSession()
    {
        if (Yii::$app->user->isGuest) return $this->redirect(['auth/login']);
        Yii::$app->ps->closeActive(Yii::$app->user->id);
        Yii::$app->session->setFlash('success','Покупка закрыта.');
        return $this->redirect(['site/index']);
    }

    public function actionDeleteSession()
    {
        if (Yii::$app->user->isGuest) return $this->redirect(['auth/login']);

        $ps = Yii::$app->ps->active(Yii::$app->user->id);
        if ($ps) {
            PriceEntry::deleteAll(['user_id'=>Yii::$app->user->id, 'session_id'=>$ps->id]);
            PurchaseSession::deleteAll(['id'=>$ps->id, 'user_id'=>$ps->user_id]);
            Yii::$app->session->remove('purchase_session_id');
            Yii::$app->session->setFlash('success','Закупка удалена.');
        }
        return $this->redirect(['site/index']);
    }
}
