<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShortageAck extends Model
{
    protected $fillable = ['flight_id', 'missing_count', 'handler', 'note'];
}
