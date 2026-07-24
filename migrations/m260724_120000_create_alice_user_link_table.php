<?php

use yii\db\Migration;

class m260724_120000_create_alice_user_link_table extends Migration
{
    public function safeUp()
    {
        $this->createTable('alice_user_link', [
            'id' => $this->primaryKey(),
            'user_id' => $this->integer()->null(),
            'application_id' => $this->string(128)->notNull(),
            'link_code_hash' => $this->string(64)->null(),
            'code_expires_at' => $this->integer()->null(),
            'created_at' => $this->integer()->notNull(),
            'updated_at' => $this->integer()->notNull(),
        ]);

        $this->createIndex('idx_alice_user_link_user', 'alice_user_link', 'user_id');
        $this->createIndex('idx_alice_user_link_code_hash', 'alice_user_link', 'link_code_hash');
        $this->createIndex('uq_alice_user_link_application', 'alice_user_link', 'application_id', true);
        $this->addForeignKey(
            'fk_alice_user_link_user',
            'alice_user_link',
            'user_id',
            '{{%user}}',
            'id',
            'CASCADE',
            'CASCADE'
        );
    }

    public function safeDown()
    {
        $this->dropForeignKey('fk_alice_user_link_user', 'alice_user_link');
        $this->dropTable('alice_user_link');
    }
}
