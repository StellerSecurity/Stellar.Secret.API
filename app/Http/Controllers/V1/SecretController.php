<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\FileUpload;
use App\Models\Secret;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SecretController extends Controller
{

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function add(Request $request): JsonResponse
    {
        try {
            $secret = Secret::create($request->only(['id', 'message', 'expires_at', 'password']));

            /*$files = $request->input('files');

            foreach ($files as $file) {
                $file['secret_id'] = $secret->id;
                $file = FileUpload::create($file);
            }*/

        } catch (UniqueConstraintViolationException $constraintViolationException) {
            return response()->json(['response_code' => 400, 'response_message' => 'Constraint violation'], 400);
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
        //FileUpload::where('secret_id', $request->input('secret_id'))->delete();

        return response()->json(['response_code' => 200]);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function find(Request $request): JsonResponse
    {

        $id = $request->input('id');

        if($id === null) {
            return response()->json(null);
        }

        $secret = Secret::where('id', $id)->first();

        return response()->json($secret);
    }

    /**
     * Runs by Azure functions.
     * @param Request $request
     * @return void
     */
    public function scheduler(Request $request): void
    {
        Secret::where('expires_at','<', Carbon::now())->delete();
    }

}
