<?php

use yii\db\Migration;

class m250821_104748_add_session_totals_cache extends Migration
{
    public function safeUp()
    {
        // Кэш по сессии — в КОПЕЙКАХ (INT), как и limit_amount
        $this->addColumn('{{%purchase_session}}', 'total_amount',
            $this->integer()->notNull()->defaultValue(0)->comment('Итог по сессии, копейки'));
        $this->addColumn('{{%purchase_session}}', 'limit_left',
            $this->integer()->null()->comment('Остаток по лимиту, копейки (NULL если лимита нет)'));
        $this->addColumn('{{%purchase_session}}', 'closed_at',
            $this->integer()->null()->comment('Момент закрытия сессии'));

        // Индексы для статистики/фильтров
        $this->createIndex('idx_ps_user_closed',   '{{%purchase_session}}', ['user_id','closed_at']);
        $this->createIndex('idx_ps_user_category', '{{%purchase_session}}', ['user_id','category']);
        $this->createIndex('idx_ps_status',        '{{%purchase_session}}', 'status');

        // Инициализация для уже закрытых сессий (STATUS_CLOSED = 9)
        // amount — DECIMAL(12,2) (руб), qty — DECIMAL(10,3)
        // Пересчёт в КОПЕЙКИ: SUM(ROUND(amount*qty*100)) с CAST → INT
        $this->execute("
            UPDATE {{%purchase_session}} ps
            LEFT JOIN (
                SELECT
                    session_id,
                    COALESCE(SUM(CAST(ROUND(amount * qty * 100) AS SIGNED)), 0) AS sum_k
                FROM {{%price_entry}}
                GROUP BY session_id
            ) t ON t.session_id = ps.id
            SET
                ps.total_amount = COALESCE(t.sum_k, 0),
                ps.limit_left   = CASE
                    WHEN ps.limit_amount IS NULL THEN NULL
                    ELSE GREATEST(ps.limit_amount - COALESCE(t.sum_k,0), 0)
                END,
                ps.closed_at    = CASE
                    WHEN ps.status = 9 AND ps.closed_at IS NULL THEN ps.updated_at
                    ELSE ps.closed_at
                END
            WHERE ps.status = 9
        ");
    }

    public function safeDown()
    {
        $this->dropIndex('idx_ps_status',        '{{%purchase_session}}');
        $this->dropIndex('idx_ps_user_category', '{{%purchase_session}}');
        $this->dropIndex('idx_ps_user_closed',   '{{%purchase_session}}');

        $this->dropColumn('{{%purchase_session}}', 'closed_at');
        $this->dropColumn('{{%purchase_session}}', 'limit_left');
        $this->dropColumn('{{%purchase_session}}', 'total_amount');
    }
}
