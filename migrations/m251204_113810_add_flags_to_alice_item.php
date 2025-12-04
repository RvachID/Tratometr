<?php

use yii\db\Migration;

class m251204_113810_add_flags_to_alice_item extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {

    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        echo "m251204_113810_add_flags_to_alice_item cannot be reverted.\n";

        return false;
    }

    /*
    // Use up()/down() to run migration code without a transaction.
    public function up()
    {

    }

    public function down()
    {
        echo "m251204_113810_add_flags_to_alice_item cannot be reverted.\n";

        return false;
    }
    */
}
