<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\Secret;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
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
        try {
            $secret = Secret::create($request->all());
        } catch (UniqueConstraintViolationException $constraintViolationException) {
            return response()->json(['response_code' => 400, 'response_message' => 'Oh something is not sexy.']);
        }
        return response()->json($secret);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function delete(Request $request): JsonResponse
    {

        $secret = Secret::where('id', $request->input('id'))->first();

        if($secret === null) {
            return response()->json(['response_code' => 400]);
        }

        $secret->delete();

        return response()->json(['response_code' => 200]);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function find(Request $request): JsonResponse
    {

        $secret = Secret::where('id', $request->input('id'))->first();
        return response()->json($secret);
    }

    public function scheduler(Request $request)
    {
        Secret::where('expires_at','<', Carbon::now())->delete();
    }

}
