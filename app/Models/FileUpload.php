<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FileUpload extends Model
{

    protected $table = "secrets_fileupload";

    public $incrementing = false;

    protected $fillable = ['secret_id', 'file_name', 'file_content'];

    public $timestamps = false;

}
