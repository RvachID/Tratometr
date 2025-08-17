<?php

use yii\db\Migration;

class m250817_102738_purchase_session_and_link extends Migration
{
    public function safeUp()
    {
        // 1) Таблица сессии покупки
        $this->createTable('purchase_session', [
            'id'          => $this->primaryKey(),
            'user_id'     => $this->integer()->notNull(),
            'shop'        => $this->string(120)->notNull(),
            'category'    => $this->string(120)->notNull(),
            'limit_amount'=> $this->integer()->null(),  // в копейках/центах; или int рублей — как у тебя
            'status'      => $this->tinyInteger()->notNull()->defaultValue(1), // 1=ACTIVE, 9=CLOSED
            'started_at'  => $this->integer()->notNull(),
            'updated_at'  => $this->integer()->notNull(),
        ]);
        $this->createIndex('idx_ps_user_status', 'purchase_session', ['user_id','status']);
        // FK опционально (если есть user.id):
        // $this->addForeignKey('fk_ps_user', 'purchase_session', 'user_id', 'user', 'id', 'CASCADE', 'CASCADE');

        // 2) Привязываем записи к сессии (НЕ ломаem старые записи — поле nullable)
        $this->addColumn('price_entry', 'session_id', $this->integer()->null()->after('user_id'));
        $this->createIndex('idx_price_entry_session', 'price_entry', ['session_id']);
        // $this->addForeignKey('fk_price_entry_session', 'price_entry','session_id','purchase_session','id','SET NULL','CASCADE');
    }

    public function safeDown()
    {
        // $this->dropForeignKey('fk_price_entry_session', 'price_entry');
        $this->dropIndex('idx_price_entry_session', 'price_entry');
        $this->dropColumn('price_entry', 'session_id');

        // $this->dropForeignKey('fk_ps_user', 'purchase_session');
        $this->dropIndex('idx_ps_user_status', 'purchase_session');
        $this->dropTable('purchase_session');
    }
}
