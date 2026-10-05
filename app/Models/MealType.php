<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MealType extends Model
{
    protected $fillable = ['code', 'name_zh', 'restriction_class'];

    /** 过敏/医疗/宗教类：不得以普通餐或偏好餐替代 */
    public const RESTRICTED_CLASSES = ['allergy', 'medical', 'religious'];

    public function isRestricted(): bool
    {
        return in_array($this->restriction_class, self::RESTRICTED_CLASSES, true);
    }
}
