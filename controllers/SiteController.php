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


    public function actionStart()
    {
        // форма «Магазин + Категория»
        $categories = ['Еда','Одежда','Детское','Дом','Аптека','Техника','Транспорт','Развлечения','Питомцы','Другое'];
        return $this->render('start', ['categories' => $categories]);
    }

    // обработка формы «Начать покупки»
    public function actionBegin()
    {
        $store    = trim((string)Yii::$app->request->post('store', ''));
        $category = trim((string)Yii::$app->request->post('category', ''));
        if ($store === '') return $this->redirect(['site/start']);

        $this->setShopSession($store, $category);
        return $this->redirect(['site/scan']);
    }

    // страница сканера с проверкой таймаутов
    public function actionScan()
    {
        $sess = $this->getShopSession();

        if (empty($sess['store'])) {
            return $this->redirect(['site/start']);
        }

        $idle = time() - (int)$sess['last_scan_at'];
        if ($idle >= self::RESET_THRESHOLD_SEC) {
            Yii::$app->session->remove('shopSession');
            return $this->redirect(['site/start']);
        }

        if ($idle >= self::ASK_THRESHOLD_SEC && Yii::$app->request->get('resume') === null) {
            return $this->render('resume', [
                'store'    => $sess['store'],
                'category' => $sess['category'],
            ]);
        }

        // отдаём страницу со СТАРЫМ функционалом (камера/список/модалка)
        return $this->render('scan', [
            'store'    => $sess['store'],
            'category' => $sess['category'],
        ]);
    }

    public function actionResume()
    {
        $choice = Yii::$app->request->post('choice', 'continue'); // continue|new
        if ($choice === 'new') {
            Yii::$app->session->remove('shopSession');
            return $this->redirect(['site/start']);
        }
        return $this->redirect(['site/scan', 'resume' => 1]);
    }
}
