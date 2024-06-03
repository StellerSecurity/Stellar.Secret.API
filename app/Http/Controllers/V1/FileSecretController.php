<?php

namespace App\Http\Controllers\V1;

use App\Models\FileUpload;
use Illuminate\Http\Request;

class FileSecretController
{

    public function find(Request $request): \Illuminate\Http\JsonResponse
    {

        $files = FileUpload::where('secret_id', $request->input('secret_id'))->get();
        return response()->json($files);

    }

}
