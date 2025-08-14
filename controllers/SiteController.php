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

class SiteController extends Controller
{
    private const ASK_THRESHOLD_SEC = 45 * 60;   // 45 минут
    private const RESET_THRESHOLD_SEC = 120 * 60;  // 2 часа
    /**
     * {@inheritdoc}
     */
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'only' => ['logout'],
                'rules' => [
                    [
                        'actions' => ['logout'],
                        'allow' => true,
                        'roles' => ['@'],
                    ],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'logout' => ['post'],
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

        return $this->render('index', [
            'entries' => $entries,
            'total' => $total,
        ]);
    }
    // ----- сессия «магазин/категория» -----
    private function getShopSession(): array
    {
        return Yii::$app->session->get('shopSession', [
            'store'        => '',
            'category'     => '',
            'started_at'   => 0,
            'last_scan_at' => 0,
        ]);
    }
    private function setShopSession(string $store, string $category): void
    {
        $now = time();
        Yii::$app->session->set('shopSession', [
            'store'        => $store,
            'category'     => $category,
            'started_at'   => $now,
            'last_scan_at' => $now,
        ]);
    }

    // ----- страницы -----

    // страница сканера с проверкой таймаутов
    public function actionScan()
    {
        $sess = $this->getShopSession();
        $now  = time();

        $store      = (string)($sess['store'] ?? '');
        $category   = (string)($sess['category'] ?? '');
        $startedAt  = (int)($sess['started_at'] ?? 0);
        $lastScan   = (int)($sess['last_scan_at'] ?? 0);
        $idle       = $lastScan ? ($now - $lastScan) : PHP_INT_MAX;

        $needPrompt = false;

        if ($store === '' || $idle >= self::RESET_THRESHOLD_SEC) {
            // сессии нет/старая — обнуляем и просим начать
            Yii::$app->session->remove('shopSession');
            $store = '';
            $category = '';
            $startedAt = 0;
            $needPrompt = true;
        } elseif ($idle >= self::ASK_THRESHOLD_SEC) {
            // 45–120 мин — предложим подтвердить/сменить магазин
            $needPrompt = true;
        }

        // ⚠️ только текущая сессия
        $entries = [];
        $total   = 0.0;

        if ($store !== '' && $startedAt > 0) {
            $q = \app\models\PriceEntry::find()
                ->where(['user_id' => Yii::$app->user->id, 'store' => $store])
                ->andWhere(['>=', 'created_at', $startedAt])
                ->orderBy(['id' => SORT_DESC])
                ->limit(200);

            // категория может быть пустой (NULL)
            if ($category === '') {
                $q->andWhere(['category' => null]);
            } else {
                $q->andWhere(['category' => $category]);
            }

            $entries = $q->all();

            // тотал только по текущей сессии
            $db = Yii::$app->db;
            if ($category === '') {
                $total = (float)$db->createCommand(
                    'SELECT COALESCE(SUM(amount*qty),0) 
                 FROM price_entry 
                 WHERE user_id=:u AND store=:s AND category IS NULL AND created_at>=:from',
                    [':u' => Yii::$app->user->id, ':s' => $store, ':from' => $startedAt]
                )->queryScalar();
            } else {
                $total = (float)$db->createCommand(
                    'SELECT COALESCE(SUM(amount*qty),0) 
                 FROM price_entry 
                 WHERE user_id=:u AND store=:s AND category=:c AND created_at>=:from',
                    [':u' => Yii::$app->user->id, ':s' => $store, ':c' => $category, ':from' => $startedAt]
                )->queryScalar();
            }
        }

        return $this->render('scan', [
            'store'        => $store,
            'category'     => $category,
            'entries'      => $entries,
            'total'        => $total,
            'needPrompt'   => $needPrompt,
        ]);
    }

    // GET: статусы таймеров
    public function actionSessionStatus()
    {
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

        $sess = Yii::$app->session->get('shopSession', [
            'store' => '', 'category' => '', 'started_at' => 0, 'last_scan_at' => 0
        ]);

        $now  = time();
        $idle = $now - (int)$sess['last_scan_at'];

        $needPrompt = false;
        if (!empty($sess['store'])) {
            if ($idle >= self::RESET_THRESHOLD_SEC) $needPrompt = true;      // >2ч — точно спросить
            elseif ($idle >= self::ASK_THRESHOLD_SEC) $needPrompt = true;    // 45–120 мин — спросить
        } else {
            $needPrompt = true; // нет активной сессии — просим ввести
        }

        return [
            'ok'        => true,
            'store'     => (string)($sess['store'] ?? ''),
            'category'  => (string)($sess['category'] ?? ''),
            'needPrompt'=> $needPrompt,
            'idle'      => $idle,
        ];
    }

// POST: установить/сменить магазин+категорию (AJAX)
    public function actionBeginAjax()
    {
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

        $store    = trim((string)Yii::$app->request->post('store', ''));
        $category = trim((string)Yii::$app->request->post('category', ''));
        if ($store === '') return ['ok' => false, 'error' => 'Укажите магазин'];

        $now = time();
        Yii::$app->session->set('shopSession', [
            'store'        => $store,
            'category'     => $category,
            'started_at'   => $now,
            'last_scan_at' => $now,
        ]);

        return ['ok' => true, 'store' => $store, 'category' => $category];
    }

}
