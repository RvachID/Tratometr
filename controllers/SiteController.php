<?php

namespace app\controllers;

use app\models\PriceEntry;
use app\models\PurchaseSession;
use app\services\Price\PriceEntryService;
use app\services\Purchase\SessionManager;
use app\services\Stats\StatsService;
use Yii;
use yii\db\Expression;
use yii\db\Query;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\Response;

class SiteController extends Controller
{
    private SessionManager $sessionManager;
    private StatsService $statsService;
    private PriceEntryService $priceEntryService;

    public function init()
    {
        parent::init();
        $this->sessionManager = Yii::$app->get('sessionManager');
        $this->statsService = Yii::$app->get('statsService');
        $this->priceEntryService = Yii::$app->get('priceService');
    }

    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'only' => ['logout', 'stats', 'stats-data'],
                'rules' => [
                    [
                        'actions' => ['logout', 'stats', 'stats-data'],
                        'allow' => true,
                        'roles' => ['@'],
                    ],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'logout' => ['post'],
                    'close-session' => ['post'],
                    'delete-session' => ['post'],
                    'stats' => ['get'],
                    'stats-data' => ['get'],
                ],
            ],
        ];
    }

    public function actions()
    {
        return [
            'error' => ['class' => 'yii\web\ErrorAction'],
            'captcha' => ['class' => 'yii\captcha\CaptchaAction', 'fixedVerifyCode' => YII_ENV_TEST ? 'testme' : null],
        ];
    }

    public function actionIndex()
    {
        $this->layout = '@app/views/layouts/index_layout';

        if (Yii::$app->user->isGuest) {
            return $this->redirect(['auth/login']);
        }

        $userId = Yii::$app->user->id;

        $entries = PriceEntry::find()
            ->where(['user_id' => $userId])
            ->orderBy(['created_at' => SORT_DESC])
            ->limit(20)
            ->all();

        $total = array_reduce($entries, static fn($sum, PriceEntry $entry) => $sum + $entry->amount * $entry->qty, 0.0);

        $session = $this->sessionManager->getActive($userId);
        $psInfo = null;
        if ($session) {
            $lastTs = $this->sessionManager->lastActivityTimestamp($session);
            $psInfo = [
                'id' => $session->id,
                'shop' => $session->shop,
                'category' => $session->category,
                'lastTs' => $lastTs,
                'limit' => $session->limit_amount,
            ];
        }

        $db = Yii::$app->db;
        $lastQuoteId = Yii::$app->session->get('last_quote_id');
        $quotesTotal = (new Query())->from('quotes')->count('*', $db);
        $quote = null;
        if ($quotesTotal > 0) {
            $q = (new Query())->from('quotes');
            if ($quotesTotal > 1 && $lastQuoteId) {
                $q->where(['<>', 'id', $lastQuoteId]);
            }
            $quote = $q->orderBy(new Expression('RAND()'))->limit(1)->one($db)
                ?: (new Query())->from('quotes')->orderBy(new Expression('RAND()'))->limit(1)->one($db);
            if ($quote) {
                Yii::$app->session->set('last_quote_id', $quote['id']);
            }
        }

        return $this->render('index', compact('entries', 'total', 'quote', 'psInfo'));
    }

    public function actionScan()
    {
        if (Yii::$app->user->isGuest) {
            return $this->redirect(['auth/login']);
        }

        $userId = Yii::$app->user->id;
        $session = $this->sessionManager->getActive($userId);

        $needPrompt = $session === null;
        $store = $session ? (string)$session->shop : '';
        $category = $session ? (string)$session->category : '';
        $limit = $session ? $this->sessionManager->formatLimit($session) : null;

        $entries = [];
        $total = 0.0;

        if ($session) {
            $entries = PriceEntry::find()
                ->where([
                    'user_id' => $userId,
                    'session_id' => $session->id,
                ])
                ->orderBy(['id' => SORT_DESC])
                ->limit(200)
                ->all();

            $total = $this->priceEntryService->getUserTotal($userId, $session->id);
        }

        return $this->render('scan', [
            'store'      => $store,
            'category'   => $category,
            'entries'    => $entries,
            'total'      => $total,
            'needPrompt' => $needPrompt,
            'limit'      => $limit,
        ]);
    }

    public function actionSessionStatus()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        if (Yii::$app->user->isGuest) {
            return ['ok' => false, 'needPrompt' => true];
        }

        $status = $this->sessionManager->buildStatus(Yii::$app->user->id);

        if ($status['session'] === null) {
            return [
                'ok' => true,
                'needPrompt' => true,
                'store' => '',
                'category' => '',
                'idle' => null,
                'limit' => null,
            ];
        }

        $session = $status['session'];

        return [
            'ok' => true,
            'needPrompt' => false,
            'store' => (string)$session->shop,
            'category' => (string)$session->category,
            'idle' => $status['idleSeconds'],
            'limit' => $status['limitRub'],
        ];
    }

    public function actionBeginAjax()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        if (Yii::$app->user->isGuest) {
            return ['ok' => false, 'error' => 'Требуется авторизация'];
        }

        $uid = Yii::$app->user->id;
        $store = trim((string)Yii::$app->request->post('store', ''));
        $category = trim((string)Yii::$app->request->post('category', ''));
        $limitStr = (string)Yii::$app->request->post('limit', '');

        if ($store === '') {
            return ['ok' => false, 'error' => 'Название магазина обязательно'];
        }

        $this->sessionManager->closeActive($uid, 'begin-new');

        try {
            $session = $this->sessionManager->begin($uid, $store, $category === '' ? null : $category, $limitStr);
        } catch (\Throwable $e) {
            Yii::error('Begin session failed: ' . $e->getMessage(), __METHOD__);
            return ['ok' => false, 'error' => 'Не удалось открыть сессию. Попробуйте позже.'];
        }

        Yii::$app->session->set('purchase_session_id', $session->id);

        return [
            'ok'       => true,
            'store'    => $session->shop,
            'category' => $session->category,
            'limit'    => $this->sessionManager->formatLimit($session),
        ];
    }

    public function actionCloseSession()
    {
        if (Yii::$app->user->isGuest) {
            return $this->redirect(['auth/login']);
        }

        $uid = Yii::$app->user->id;
        $session = $this->sessionManager->getActive($uid);
        if (!$session) {
            Yii::$app->session->setFlash('warning', 'Активная сессия не найдена.');
            return $this->redirect(['site/index']);
        }

        try {
            $this->sessionManager->finalize($session, 'manual');
            $session->refresh();

            [$totalRub, $leftRub] = $this->sessionManager->formatTotals($session);
            $msg = "Сессия закрыта. Итого: {$totalRub}.";
            if ($leftRub !== null) {
                $msg .= " Остаток лимита: {$leftRub}.";
            }
            Yii::$app->session->setFlash('success', $msg);
        } catch (\Throwable $e) {
            Yii::error('Manual finalize failed: ' . $e->getMessage(), __METHOD__);
            Yii::$app->session->setFlash('error', 'Не удалось закрыть сессию. Попробуйте позже.');
        }

        return $this->redirect(['site/index']);
    }

    public function actionDeleteSession($id = null)
    {
        if (Yii::$app->user->isGuest) {
            return $this->redirect(['auth/login']);
        }

        $userId = Yii::$app->user->id;
        $session = null;

        if ($id === null) {
            $session = $this->sessionManager->getActive($userId);
        } else {
            $session = PurchaseSession::findOne(['id' => (int)$id, 'user_id' => $userId]);
        }

        if (!$session) {
            Yii::$app->session->setFlash('error', 'Сессия не найдена или уже удалена.');
            return $id === null ? $this->redirect(['site/index']) : $this->redirect(['site/history']);
        }

        try {
            $this->sessionManager->deleteSession($session);
            if ((int)Yii::$app->session->get('purchase_session_id') === (int)$session->id) {
                Yii::$app->session->remove('purchase_session_id');
            }
            Yii::$app->session->setFlash('success', 'Сессия удалена.');
        } catch (\Throwable $e) {
            Yii::error($e->getMessage() . "\n" . $e->getTraceAsString(), __METHOD__);
            Yii::$app->session->setFlash('error', 'Не удалось удалить сессию: ' . $e->getMessage());
        }

        return $id === null ? $this->redirect(['site/index']) : $this->redirect(['site/history']);
    }

    public function actionHistory()
    {
        if (Yii::$app->user->isGuest) {
            return $this->redirect(['auth/login']);
        }

        $items = $this->statsService->getHistory(Yii::$app->user->id);
        return $this->render('history', ['items' => $items]);
    }

    public function actionStats()
    {
        if (Yii::$app->user->isGuest) {
            return $this->redirect(['auth/login']);
        }

        $uid = Yii::$app->user->id;
        $tzId = Yii::$app->formatter->timeZone;
        $tz = new \DateTimeZone($tzId);

        $dateTo = Yii::$app->request->get('date_to');
        $dateFrom = Yii::$app->request->get('date_from');

        $period = $this->statsService->resolvePeriod($dateFrom, $dateTo, $tz);

        $allCats = $this->statsService->getCategories($uid, $period['tsFrom'], $period['tsTo']);

        $selectedCats = Yii::$app->request->get('categories', $allCats);
        if (!is_array($selectedCats)) {
            $selectedCats = [$selectedCats];
        }
        $selectedCats = array_values(array_intersect($selectedCats, $allCats));

        return $this->render('stats', [
            'dateFrom'     => $period['dateFrom'],
            'dateTo'       => $period['dateTo'],
            'allCats'      => $allCats,
            'selectedCats' => $selectedCats,
        ]);
    }

    public function actionStatsData()
    {
        if (Yii::$app->user->isGuest) {
            return $this->asJson(['ok' => false, 'error' => 'auth']);
        }

        $uid = Yii::$app->user->id;
        $tzId = Yii::$app->formatter->timeZone;
        $tz = new \DateTimeZone($tzId);

        $request = Yii::$app->request;
        $dateTo = $request->get('date_to');
        $dateFrom = $request->get('date_from');

        $period = $this->statsService->resolvePeriod($dateFrom, $dateTo, $tz);
        $allCats = $this->statsService->getCategories($uid, $period['tsFrom'], $period['tsTo']);

        $rawCats = $request->getQueryParam('categories', null);
        if ($rawCats === null) {
            $selectedCats = $allCats;
        } else {
            $catsArray = is_array($rawCats) ? $rawCats : [$rawCats];
            $selectedCats = array_values(array_intersect($catsArray, $allCats));
            if (!$selectedCats) {
                $selectedCats = $allCats;
            }
        }

        $data = $this->statsService->collectStats($uid, $period['tsFrom'], $period['tsTo'], $selectedCats);

        return $this->asJson([
            'ok'     => true,
            'labels' => $data['labels'],
            'values' => $data['values'],
            'categories' => array_values($allCats),
            'selectedCategories' => array_values($selectedCats),
            'period' => [$period['dateFrom'], $period['dateTo']],
        ]);
    }

    public function actionAbout()
    {
        return $this->render('about');
    }
}
