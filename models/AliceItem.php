<?php

namespace app\models;

use yii\db\ActiveRecord;

/**
 * @property int $id
 * @property int $user_id
 * @property string $title
 * @property int $is_done
 * @property int $created_at
 * @property int $updated_at
 */
class AliceItem extends ActiveRecord
{
    public static function tableName(): string
    {
        return 'alice_item';
    }

    public function rules(): array
    {
        return [
            [['user_id', 'title'], 'required'],
            [['user_id', 'is_done', 'created_at', 'updated_at'], 'integer'],
            ['title', 'string', 'max' => 255],
        ];
    }
}
