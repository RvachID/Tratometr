<?php

use yii\db\Migration;

/**
 * Handles the creation of table `{{%price_entry}}`.
 */
class m250805_135433_create_price_entry_table extends Migration
{
    public function safeUp() {
        $this->createTable('{{%price_entry}}', [
            'id' => $this->primaryKey(),
            'user_id' => $this->integer()->notNull(),
            'amount' => $this->decimal(12,2)->notNull(),   // цена за единицу
            'qty' => $this->decimal(10,3)->notNull()->defaultValue(1), // поддержка дробных штук/кг
            'store' => $this->string()->null(),
            'category' => $this->string()->null(),
            'source' => $this->string()->notNull()->defaultValue('manual'), // manual|price_tag|receipt
            'note' => $this->string()->null(),
            'photo_path' => $this->string()->null(), // если храним превью/оригинал
            'recognized_text' => $this->text()->null(),
            'recognized_amount' => $this->decimal(12,2)->null(),
            'created_at' => $this->integer()->notNull(),
            'updated_at' => $this->integer()->notNull(),
        ]);
        $this->createIndex('idx_price_entry_user_created', '{{%price_entry}}', ['user_id','created_at']);
        $this->addForeignKey('fk_price_entry_user', '{{%price_entry}}', 'user_id', '{{%user}}', 'id', 'CASCADE', 'CASCADE');
    }
    public function safeDown() {
        $this->dropForeignKey('fk_price_entry_user', '{{%price_entry}}');
        $this->dropTable('{{%price_entry}}');
    }
}
