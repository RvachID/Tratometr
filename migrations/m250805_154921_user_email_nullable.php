<?php

use yii\db\Migration;

class m250805_154921_user_email_nullable extends Migration
{
    public function safeUp()
    {
        // делаем email NULL DEFAULT NULL
        $this->alterColumn('{{%user}}', 'email', $this->string()->null());
    }

    public function safeDown()
    {
        // если откатывать — вернём NOT NULL (будьте уверены, что пустых значений нет)
        $this->alterColumn('{{%user}}', 'email', $this->string()->notNull());
    }
}
