<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\Secret;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SecretController extends Controller
{

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function add(Request $request): JsonResponse
    {
        $secret = Secret::add($request->all());
        return response()->json($secret);
    }

    public function delete(Request $request): JsonResponse
    {

        $secret = Secret::where('id', $request->input('id'))->first();

        if($secret === null) {
            return response()->json(['response_code' => 400]);
        }

        $secret->delete();

        return response()->json(['response_code' => 200]);
    }

    public function find(Request $request): JsonResponse
    {
        $secret = Secret::where('id', $request->input('id'))->first();
        return response()->json($secret);
    }

}
