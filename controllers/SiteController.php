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
                'class' => \yii\filters\AccessControl::class,
                // ограничиваем только эти экшены
                'only'  => ['logout', 'stats', 'stats-data'],
                'rules' => [
                    [
                        'actions' => ['logout', 'stats', 'stats-data'],
                        'allow'   => true,
                        'roles'   => ['@'], // только авторизованные
                    ],
                ],
            ],
            'verbs' => [
                'class' => \yii\filters\VerbFilter::class,
                'actions' => [
                    'logout'         => ['post'],
                    'close-session'  => ['post'],
                    'delete-session' => ['post'],
                    'stats'          => ['get'],
                    'stats-data'     => ['get'],
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
    {$this->layout = '@app/views/layouts/index_layout';

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
        $limit = ($ps && $ps->limit_amount !== null) ? round(((int)$ps->limit_amount)/100, 2) : null;

        return $this->render('scan', [
            'store'      => $store,
            'category'   => $category,
            'entries'    => $entries,
            'total'      => $total,
            'needPrompt' => $needPrompt,
            'limit'    => $limit,
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
        if (Yii::$app->user->isGuest) {
            return $this->redirect(['auth/login']);
        }

        $uid = Yii::$app->user->id;

        // Берём активную сессию пользователя (если их гарантированно одна — ок)
        $session = \app\models\PurchaseSession::find()
            ->where(['user_id' => $uid, 'status' => \app\models\PurchaseSession::STATUS_ACTIVE])
            ->orderBy(['started_at' => SORT_DESC])
            ->one();

        if (!$session) {
            Yii::$app->session->setFlash('warning', 'Активная сессия не найдена.');
            return $this->redirect(['site/index']);
        }

        if ($session->finalize()) {
            $fmt = Yii::$app->formatter;

            // total_amount в копейках → рубли
            $totalRub = $fmt->asDecimal(((int)$session->total_amount) / 100, 2);

            $msg = "Сессия закрыта. Итог: {$totalRub}.";

            // остаток выводим ТОЛЬКО если лимит был указан
            if ($session->limit_amount !== null) {
                $leftRub = $fmt->asDecimal(((int)$session->limit_left) / 100, 2);
                $msg .= " Остаток по лимиту: {$leftRub}.";
            }

            Yii::$app->session->setFlash('success', $msg);
        } else {
            Yii::$app->session->setFlash('error', 'Не удалось закрыть сессию. Повторите позже.');
        }


        return $this->redirect(['site/index']);
    }

    public function actionDeleteSession($id = null)
    {
        if (Yii::$app->user->isGuest) return $this->redirect(['auth/login']);

        $userId = Yii::$app->user->id;
        $ps = null;

        if ($id === null) {
            // удалить активную
            $ps = Yii::$app->ps->active($userId);
        } else {
            // удалить конкретную из истории
            $ps = \app\models\PurchaseSession::findOne(['id' => (int)$id, 'user_id' => $userId]);
        }

        if (!$ps) {
            Yii::$app->session->setFlash('error', 'Сессия не найдена или недоступна.');
            return $id === null ? $this->redirect(['site/index']) : $this->redirect(['site/history']);
        }

        $db = Yii::$app->db;
        $tx = $db->beginTransaction();
        try {
            \app\models\PriceEntry::deleteAll(['user_id' => $userId, 'session_id' => $ps->id]);
            $ps->delete(false);

            // если удалили активную — очистим маркер
            if ((int)Yii::$app->session->get('purchase_session_id') === (int)$ps->id) {
                Yii::$app->session->remove('purchase_session_id');
            }

            $tx->commit();
            Yii::$app->session->setFlash('success', 'Сессия удалена.');
        } catch (\Throwable $e) {
            $tx->rollBack();
            Yii::error($e->getMessage() . "\n" . $e->getTraceAsString(), __METHOD__);
            Yii::$app->session->setFlash('error', 'Не удалось удалить сессию.');
        }

        // возвращаем туда, откуда удаляли
        return $id === null ? $this->redirect(['site/index']) : $this->redirect(['site/history']);
    }

    public function actionHistory()
    {
        if (Yii::$app->user->isGuest) {
            return $this->redirect(['auth/login']);
        }

        $userId = Yii::$app->user->id;

        // LEFT JOIN только для активных сессий — закрытые не утяжеляем
        $rows = (new \yii\db\Query())
            ->select([
                'ps.id',
                'ps.shop',
                'ps.category',
                'ps.status',
                'ps.limit_amount',          // в копейках (как было)
                'ps.total_amount',          // ₽ DECIMAL(12,2), кэш
                'ps.limit_left',            // ₽ DECIMAL(12,2) или NULL, кэш
                // last_ts: closed_at если есть, иначе последний скан/обновление/старт
                new \yii\db\Expression(
                    'COALESCE(ps.closed_at, MAX(pe.created_at), ps.updated_at, ps.started_at) AS last_ts'
                ),
                // Сумма "вживую" считаем только для активных (через условный JOIN ниже)
                new \yii\db\Expression('COALESCE(SUM(pe.amount * pe.qty), 0) AS sum_live'),
            ])
            ->from(['ps' => 'purchase_session'])
            ->leftJoin(
                ['pe' => 'price_entry'],
                'pe.session_id = ps.id AND pe.user_id = ps.user_id AND ps.status <> 9' // только активные
            )
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

    public function actionStats()
    {
        if (Yii::$app->user->isGuest) {
            return $this->redirect(['auth/login']);
        }

        $uid = Yii::$app->user->id;

        // Период по умолчанию: последние 7 дней (включая сегодня)
        $dateTo   = Yii::$app->request->get('date_to', date('Y-m-d'));
        $dateFrom = Yii::$app->request->get('date_from', date('Y-m-d', strtotime('-6 days', strtotime($dateTo))));

        // Достаём НЕ пустые категории пользователя в этом диапазоне из закрытых сессий
        $tsFrom = strtotime($dateFrom . ' 00:00:00');
        $tsTo   = strtotime($dateTo   . ' 23:59:59');

        $allCats = (new Query())
            ->select('category')
            ->from('purchase_session')
            ->where([
                'user_id' => $uid,
                'status'  => \app\models\PurchaseSession::STATUS_CLOSED,
            ])
            ->andWhere(['between', 'closed_at', $tsFrom, $tsTo])
            ->andWhere("category IS NOT NULL AND category <> ''")
            ->groupBy('category')
            ->orderBy('category ASC')
            ->column();

        // Выбранные категории из GET (по умолчанию — все найденные)
        $selectedCats = Yii::$app->request->get('categories', $allCats);
        if (!is_array($selectedCats)) $selectedCats = [$selectedCats];
        // Отфильтруем мусор
        $selectedCats = array_values(array_intersect($selectedCats, $allCats));

        return $this->render('stats', [
            'dateFrom'     => $dateFrom,
            'dateTo'       => $dateTo,
            'allCats'      => $allCats,
            'selectedCats' => $selectedCats,
        ]);
    }

    /** JSON-данные для диаграммы: сумма по дням в рублях */
    public function actionStatsData()
    {
        if (Yii::$app->user->isGuest) {
            return $this->asJson(['ok' => false, 'error' => 'auth']);
        }

        $uid      = Yii::$app->user->id;
        $dateTo   = Yii::$app->request->get('date_to', date('Y-m-d'));
        $dateFrom = Yii::$app->request->get('date_from', date('Y-m-d', strtotime('-6 days', strtotime($dateTo))));
        $cats     = Yii::$app->request->get('categories', []);
        if (!is_array($cats)) $cats = [$cats];

        $tsFrom = strtotime($dateFrom . ' 00:00:00');
        $tsTo   = strtotime($dateTo   . ' 23:59:59');

        // Агрегация по КАТЕГОРИЯМ (берём только закрытые сессии)
        $q = (new \yii\db\Query())
            ->select([
                'category',
                new \yii\db\Expression('SUM(total_amount) AS sum_k'),
            ])
            ->from('purchase_session')
            ->where([
                'user_id' => $uid,
                'status'  => \app\models\PurchaseSession::STATUS_CLOSED,
            ])
            ->andWhere(['between', 'closed_at', $tsFrom, $tsTo])
            ->andWhere("category IS NOT NULL AND category <> ''");

        if (!empty($cats)) {
            $q->andWhere(['in', 'category', $cats]);
        }

        $rows = $q->groupBy(['category'])->orderBy(['sum_k' => SORT_DESC])->all();

        $labels = [];
        $values = [];
        foreach ($rows as $r) {
            $labels[] = (string)$r['category'];
            $values[] = round(((int)$r['sum_k']) / 100, 2); // копейки -> ₽
        }

        return $this->asJson([
            'ok'     => true,
            'labels' => $labels,
            'values' => $values,
            'period' => [$dateFrom, $dateTo],
        ]);
    }

    public function actionAbout()
    {
        return $this->render('about');
    }


}
