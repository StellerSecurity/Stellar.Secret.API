<?php

namespace App\Http\Controllers\V1;

use App\Helpers\SecretFileUploadHelper;
use App\Http\Controllers\Controller;
use App\Models\FileUpload;
use App\Models\Secret;
use App\Services\ExternalStorageService;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class SecretController extends Controller
{

    private ExternalStorageService $externalStorageService;

    public function __construct(ExternalStorageService $externalStorageService) {
        $this->externalStorageService = $externalStorageService;
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function add(Request $request): JsonResponse
    {

        $message = $request->input('message');
        $files = $request->input('files');
        $id = $request->input('id');

        if (empty($id)) {
            return response()->json([
                'response_code'    => Response::HTTP_BAD_REQUEST,
                'response_message' => 'ID is empty',
            ], Response::HTTP_BAD_REQUEST);
        }

        if (strlen($id) < 16) {
            return response()->json([
                'response_code'    => Response::HTTP_BAD_REQUEST,
                'response_message' => 'ID is too short',
            ], Response::HTTP_BAD_REQUEST);
        }

        if (empty($message) && empty($files)) {
            return response()->json([
                'response_code'    => Response::HTTP_BAD_REQUEST,
                'response_message' => 'Message && files is empty.',
            ], Response::HTTP_BAD_REQUEST);
        }


        try {
            $secret = Secret::create($request->only(['id', 'message', 'expires_at', 'password']));

            if(is_array($files)) {
                $fileNumber = 0;
                foreach($files as $file) {
                    // currently, we only support one file, might change it in the future.
                    if($fileNumber > 0) break;
                    if(!isset($file['id']) || !isset($file['content'])) continue;

                    // NOTICE: Azure storage will check MAX_FILE_SIZE_MB && take care of auto-deletion if the user does not open the secret-link for some reason after a period of time.
                    // Azure storage will only delete if Scheduler for some reason does not run or fails.
                    Storage::disk('azure')->put($file['id'], $file['content']);
                    $fileNumber++;
                }
            }

        } catch (UniqueConstraintViolationException $constraintViolationException) {
            return response()->json(['response_code' => Response::HTTP_BAD_REQUEST, 'response_message' => 'Constraint violation'], Response::HTTP_BAD_REQUEST);
        }

        return response()->json($secret);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function delete(Request $request): JsonResponse
    {

        $id = $request->input('id');

        if($id === null) {
            return response()->json(null, 400);
        }

        $secret = Secret::where('id', $id)->first();

        if($secret === null) {
            return response()->json(['response_code' => Response::HTTP_BAD_REQUEST]);
        }

        $secret->delete();
        //FileUpload::where('secret_id', $request->input('secret_id'))->delete();

        return response()->json(['response_code' => Response::HTTP_OK]);
    }


    /** bo
     * @param Request $request
     * @return JsonResponse
     */
    public function find(Request $request): JsonResponse
    {
        $id = $request->input('id');

        if ($id === null) {
            return response()->json(null);
        }

        $secret = Secret::where('id', $id)->first();

        if ($secret === null) {
            return response()->json(null);
        }

        $fileIds = null;
        $file    = $this->externalStorageService->file($id);

        if ($file !== null && ($file->status() === null || $file->status() === Response::HTTP_OK)) {
            $fileIds = [$id];
        }

        $secret->fileIds = $fileIds;

        return response()->json($secret);
    }

    /**
     * Runs by Azure functions.
     * @param Request $request
     * @return void
     */
    public function scheduler(Request $request): void
    {

        $secrets = Secret::where('expires_at','<', Carbon::now())->get();

        foreach($secrets as $secret) {
            $secret->delete();
            $file = $this->externalStorageService->file($secret->id);
            if ($file !== null && $file->status() === Response::HTTP_OK) {
                SecretFileUploadHelper::deleteIfFailedTryAgain($secret->id);
            }
        }

    }

}
