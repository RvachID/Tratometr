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
        if (Yii::$app->user->isGuest) {
            return $this->redirect(['auth/login']);
        }

        // Активная покупка (серверная сессия)
        $ps = Yii::$app->ps->active(Yii::$app->user->id);

        $needPrompt = $ps === null; // если сессии нет — покажем модалку выбора магазина
        $store     = $ps ? (string)$ps->shop      : '';
        $category  = $ps ? (string)$ps->category  : '';
        $limit     = ($ps && $ps->limit_amount !== null)
            ? round(((int)$ps->limit_amount) / 100, 2) // копейки → рубли
            : null;

        // Данные текущей сессии
        $entries = [];
        $total   = 0.0;

        if ($ps) {
            $entries = \app\models\PriceEntry::find()
                ->where([
                    'user_id'    => Yii::$app->user->id,
                    'session_id' => $ps->id,
                ])
                ->orderBy(['id' => SORT_DESC])
                ->limit(200)
                ->all();

            $sum = \app\models\PriceEntry::find()
                ->where([
                    'user_id'    => Yii::$app->user->id,
                    'session_id' => $ps->id,
                ])
                ->sum('amount * qty');

            $total = (float)($sum ?? 0);
        }
        $limitRub = ($ps && $ps->limit_amount !== null) ? round(((int)$ps->limit_amount)/100, 2) : null;
        return $this->render('scan', [
            'store'      => $store,
            'category'   => $category,
            'entries'    => $entries,
            'total'      => $total,
            'needPrompt' => $needPrompt,
            'limit'    => $limitRub,   // для нового кода
            'limitRub' => $limitRub,   // алиас для старого шаблона
        ]);
    }

    /** Для фронта: есть ли активная покупка и сколько простаиваем */
    public function actionSessionStatus()
    {
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

        if (Yii::$app->user->isGuest) return ['ok'=>false,'needPrompt'=>true];

        $ps = Yii::$app->ps->active(Yii::$app->user->id);
        if (!$ps) return ['ok'=>true,'needPrompt'=>true,'store'=>'','category'=>'','idle'=>null,'limit'=>null];

        $idle = time() - Yii::$app->ps->lastActivityTs($ps);
        $limitRub = $ps->limit_amount !== null ? round(((int)$ps->limit_amount)/100, 2) : null;

        return [
            'ok'         => true,
            'needPrompt' => false,
            'store'      => (string)$ps->shop,
            'category'   => (string)$ps->category,
            'idle'       => $idle,
            'limit'      => $limitRub, // <—
        ];
    }

    /** Создание новой серверной сессии из модалки */
    public function actionBeginAjax()
    {
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;
        if (Yii::$app->user->isGuest) return ['ok'=>false,'error'=>'Требуется вход'];

        $store    = trim((string)Yii::$app->request->post('store',''));
        $category = trim((string)Yii::$app->request->post('category',''));
        $limitStr = Yii::$app->request->post('limit', ''); // может быть пусто

        if ($store === '') return ['ok'=>false,'error'=>'Укажите магазин'];

        Yii::$app->ps->closeActive(Yii::$app->user->id);

        $ps = new \app\models\PurchaseSession([
            'user_id'  => Yii::$app->user->id,
            'shop'     => $store,
            'category' => $category,
            'status'   => \app\models\PurchaseSession::STATUS_ACTIVE,
        ]);

        // лимит (в копейках в БД)
        $limitRub = null;
        if (($v = $this->parseMoney($limitStr)) !== null) {
            $limitRub = $v;
            $ps->limit_amount = (int)round($v * 100);
        }

        $ps->save(false);
        Yii::$app->session->set('purchase_session_id', $ps->id);

        return ['ok'=>true, 'store'=>$store, 'category'=>$category, 'limit'=>$limitRub];
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

    public function actionHistory()
    {
        if (Yii::$app->user->isGuest) {
            return $this->redirect(['auth/login']);
        }

        $userId = Yii::$app->user->id;

        // Агрегируем по сессиям: последняя активность и сумма по позициям
        $rows = (new \yii\db\Query())
            ->select([
                'ps.id',
                'ps.shop',
                'ps.category',
                'ps.status',
                'ps.limit_amount',
                // последний скан: MAX(pe.created_at), иначе updated_at/created_at сессии
                'last_ts'   => new \yii\db\Expression('COALESCE(MAX(pe.created_at), ps.updated_at, ps.created_at)'),
                'total_sum' => new \yii\db\Expression('COALESCE(SUM(pe.amount * pe.qty), 0)'),
            ])
            ->from(['ps' => 'purchase_session'])
            ->leftJoin(['pe' => 'price_entry'], 'pe.session_id = ps.id AND pe.user_id = ps.user_id')
            ->where(['ps.user_id' => $userId])
            ->groupBy(['ps.id'])
            ->orderBy(['last_ts' => SORT_DESC, 'ps.id' => SORT_DESC])
            ->limit(500)
            ->all();

        return $this->render('history', ['items' => $rows]);
    }

    private function parseMoney($raw): ?float {
        $s = trim((string)$raw);
        if ($s === '') return null;
        // допускаем "3 000,50" и "3000.50"
        $s = str_replace([' ', ','], ['', '.'], $s);
        if (!is_numeric($s)) return null;
        return round((float)$s, 2);
    }
}
