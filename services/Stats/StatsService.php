<?php

namespace app\services\Stats;

use app\models\PurchaseSession;
use DateTime;
use DateTimeZone;
use yii\db\Query;

/**
 * Сервис статистики и истории покупок.
 */
class StatsService
{
    public function getHistory(int $userId, int $limit = 500): array
    {
        return (new Query())
            ->select([
                'ps.id',
                'ps.shop',
                'ps.category',
                'ps.started_at',
                'ps.closed_at',
                'ps.total_amount',
                'ps.limit_amount',
                'ps.limit_left',
                'ps.status',
                new \yii\db\Expression('MAX(pe.created_at) AS last_ts'),
                new \yii\db\Expression('COUNT(pe.id) AS items_count'),
                new \yii\db\Expression('SUM(pe.amount * pe.qty) AS amount_sum'),
            ])
            ->from(['ps' => 'purchase_session'])
            ->leftJoin(['pe' => 'price_entry'], 'pe.session_id = ps.id AND pe.user_id = ps.user_id AND ps.status <> 9')
            ->where(['ps.user_id' => $userId])
            ->groupBy(['ps.id'])
            ->orderBy(['last_ts' => SORT_DESC, 'ps.id' => SORT_DESC])
            ->limit($limit)
            ->all();
    }

    public function resolvePeriod(?string $dateFrom, ?string $dateTo, DateTimeZone $tz): array
    {
        if (!$dateTo) {
            $now = new DateTime('now', $tz);
            $dateTo = $now->format('Y-m-d');
        }

        if (!$dateFrom) {
            $base = new DateTime($dateTo . ' 00:00:00', $tz);
            $dateFrom = $base->modify('-6 days')->format('Y-m-d');
        }

        $fromLocal = new DateTime($dateFrom . ' 00:00:00', $tz);
        $toLocal = new DateTime($dateTo . ' 23:59:59', $tz);

        $tsFrom = (int)$fromLocal->setTimezone(new DateTimeZone('UTC'))->format('U');
        $tsTo = (int)$toLocal->setTimezone(new DateTimeZone('UTC'))->format('U');

        return [
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'tsFrom' => $tsFrom,
            'tsTo' => $tsTo,
            'fromLocal' => $fromLocal,
            'toLocal' => $toLocal,
        ];
    }

    public function getCategories(int $userId, int $tsFrom, int $tsTo): array
    {
        return (new Query())
            ->select('category')
            ->from('purchase_session')
            ->where([
                'user_id' => $userId,
                'status'  => PurchaseSession::STATUS_CLOSED,
            ])
            ->andWhere(['between', 'closed_at', $tsFrom, $tsTo])
            ->andWhere("category IS NOT NULL AND category <> ''")
            ->groupBy('category')
            ->orderBy('category ASC')
            ->column();
    }

    public function collectStats(int $userId, int $tsFrom, int $tsTo, array $categories = []): array
    {
        $query = (new Query())
            ->select([
                'category',
                new \yii\db\Expression('SUM(total_amount) AS sum_k'),
            ])
            ->from('purchase_session')
            ->where([
                'user_id' => $userId,
                'status'  => PurchaseSession::STATUS_CLOSED,
            ])
            ->andWhere(['between', 'closed_at', $tsFrom, $tsTo])
            ->andWhere("category IS NOT NULL AND category <> ''");

        if (!empty($categories)) {
            $query->andWhere(['in', 'category', $categories]);
        }

        $rows = $query->groupBy(['category'])->orderBy(['sum_k' => SORT_DESC])->all();

        $labels = [];
        $values = [];
        foreach ($rows as $row) {
            $labels[] = (string)$row['category'];
            $values[] = round(((int)$row['sum_k']) / 100, 2);
        }

        return [
            'labels' => $labels,
            'values' => $values,
        ];
    }
}
