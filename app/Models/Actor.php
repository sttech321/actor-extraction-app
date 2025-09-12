<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Actor extends Model
{
    protected $fillable = [
        'email',
        'description',
        'first_name',
        'last_name',
        'address',
        'height',
        'weight',
        'gender',
        'age',
    ];

}
