<?php

use yii\db\Migration;

class m250821_104748_add_session_totals_cache extends Migration
{
    public function safeUp()
    {
        $this->addColumn('purchase_session', 'total_amount', $this->decimal(12,2)->notNull()->defaultValue(0));
        $this->addColumn('purchase_session', 'limit_left',   $this->decimal(12,2)->null());
        $this->addColumn('purchase_session', 'closed_at',    $this->integer()->null());

        // индексы для статистики
        $this->createIndex('idx_ps_user_closed', 'purchase_session', ['user_id','closed_at']);
        $this->createIndex('idx_ps_user_category', 'purchase_session', ['user_id','category']);

        // первичная инициализация для уже закрытых сессий (STATUS_CLOSED = 9)
        $this->execute("
            UPDATE purchase_session ps
            LEFT JOIN (
                SELECT session_id, ROUND(SUM(amount*qty),2) AS sum_total
                FROM price_entry
                GROUP BY session_id
            ) t ON t.session_id = ps.id
            SET
                ps.total_amount = COALESCE(t.sum_total, 0),
                ps.limit_left   = CASE
                    WHEN ps.limit_amount IS NULL THEN NULL
                    ELSE GREATEST(ps.limit_amount - COALESCE(t.sum_total,0), 0)
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
        $this->dropIndex('idx_ps_user_category', 'purchase_session');
        $this->dropIndex('idx_ps_user_closed',   'purchase_session');
        $this->dropColumn('purchase_session', 'closed_at');
        $this->dropColumn('purchase_session', 'limit_left');
        $this->dropColumn('purchase_session', 'total_amount');
    }
}
