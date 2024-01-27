<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Secret extends Model
{

    protected $table = "secrets";

    public $incrementing = false;

    protected $fillable = ['id', 'message', 'expires_at', 'password'];

    public $timestamps = false;


}
