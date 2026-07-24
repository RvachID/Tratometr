<?php

namespace app\models;

use yii\db\ActiveRecord;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $application_id
 * @property string|null $link_code_hash
 * @property int|null $code_expires_at
 * @property int $created_at
 * @property int $updated_at
 */
class AliceUserLink extends ActiveRecord
{
    public static function tableName(): string
    {
        return 'alice_user_link';
    }

    public function rules(): array
    {
        return [
            [['application_id'], 'required'],
            [['user_id', 'code_expires_at', 'created_at', 'updated_at'], 'integer'],
            ['application_id', 'string', 'max' => 128],
            ['application_id', 'unique'],
            ['link_code_hash', 'string', 'max' => 64],
        ];
    }
}
