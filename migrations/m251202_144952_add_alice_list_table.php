<?php

use yii\db\Migration;

class m251202_144952_add_alice_list_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->createTable('alice_item', [
            'id'         => $this->primaryKey(),
            'user_id'    => $this->integer()->notNull(),
            'title'      => $this->string(255)->notNull(),
            'is_done'    => $this->boolean()->notNull()->defaultValue(false),
            'created_at' => $this->integer()->notNull(),
            'updated_at' => $this->integer()->notNull(),
        ]);

        $this->createIndex('idx_alice_item_user', 'alice_item', 'user_id');
        $this->createIndex('idx_alice_item_user_done', 'alice_item', ['user_id', 'is_done']);
    }

    public function safeDown()
    {
        $this->dropTable('alice_item');
    }

}
