<?php

use yii\db\Migration;

class m260618_120000_add_product_name_to_price_entry extends Migration
{
    public function safeUp()
    {
        $this->addColumn(
            '{{%price_entry}}',
            'product_name',
            $this->string(255)->null()->after('category')
        );

        $rows = (new \yii\db\Query())
            ->select(['price_entry.id', 'alice_item.title'])
            ->from('{{%price_entry}} price_entry')
            ->innerJoin('{{%alice_item}} alice_item', 'alice_item.id = price_entry.alice_item_id')
            ->all();

        foreach ($rows as $row) {
            $this->update(
                '{{%price_entry}}',
                ['product_name' => $row['title']],
                ['id' => $row['id']]
            );
        }
    }

    public function safeDown()
    {
        $this->dropColumn('{{%price_entry}}', 'product_name');
    }
}
