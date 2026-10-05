<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScanEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['meal_id', 'code_scanned', 'flight_id', 'result'];
}
