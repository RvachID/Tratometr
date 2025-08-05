<?php

use yii\db\Migration;

/**
 * Handles the creation of table `{{%telegram_message}}`.
 */
class m250804_135945_create_telegram_message_table extends Migration
{
    public function safeUp()
    {
        $this->createTable('telegram_message', [
            'id' => $this->primaryKey(),
            'chat_id' => $this->bigInteger(),
            'user_id' => $this->bigInteger(),
            'username' => $this->string(),
            'first_name' => $this->string(),
            'text' => $this->text(),
            'date' => $this->integer(),
            'created_at' => $this->timestamp()->defaultExpression('CURRENT_TIMESTAMP')
        ]);
    }

    public function safeDown()
    {
        $this->dropTable('telegram_message');
    }

}
