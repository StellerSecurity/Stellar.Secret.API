<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Secret extends Model
{
    protected $table = 'secrets';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'message',
        'expires_at',
        'has_password',
        'encryption_version'
    ];

    protected $casts = [
        'has_password' => 'boolean',
        'expires_at'   => 'datetime',
    ];

    public $timestamps = false;
}
