<?php

use yii\db\Migration;

class m250812_103207_add_ocr_rate_limit_fields_to_user_table extends Migration
{
    public function safeUp()
    {
        $this->addColumn('{{%user}}', 'ocr_allowance', $this->integer()->notNull()->defaultValue(10)->comment('Оставшийся лимит OCR-запросов'));
        $this->addColumn('{{%user}}', 'ocr_allowance_updated_at', $this->integer()->notNull()->defaultValue(time())->comment('Время последнего обновления OCR-лимита'));
    }

    public function safeDown()
    {
        $this->dropColumn('{{%user}}', 'ocr_allowance');
        $this->dropColumn('{{%user}}', 'ocr_allowance_updated_at');
    }
}
