<?php

namespace App\Http\Controllers\V1;

use App\Helpers\SecretFileUploadHelper;
use App\Http\Controllers\Controller;
use App\Models\Secret;
use App\Services\ExternalStorageService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class SecretController extends Controller
{
    public function __construct(
        private ExternalStorageService $externalStorageService
    ) {}

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function add(Request $request): JsonResponse
    {
        $message   = $request->input('message');
        $files     = $request->input('files');
        $id        = $request->input('id');

        $hasPassword = (bool) $request->input('has_password', false);

        $encryptionVersion = strtolower(trim((string) $request->input('encryption_version', 'v1')));
        if (! in_array($encryptionVersion, ['v1', 'v2'], true)) {
            return response()->json([
                'response_code'    => Response::HTTP_BAD_REQUEST,
                'response_message' => 'Invalid encryption_version',
            ], Response::HTTP_BAD_REQUEST);
        }


        $expiresAt = $request->input('expires_at');

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
            $secret = Secret::create([
                'id'                 => $id,
                'message'            => $message,
                'expires_at'         => $expiresAt,
                'has_password'       => $hasPassword,
                'encryption_version' => $encryptionVersion,
            ]);

            if (is_array($files)) {
                $fileNumber = 0;

                foreach ($files as $file) {
                    if ($fileNumber > 0) {
                        break;
                    }

                    if (! isset($file['id'], $file['content'])) {
                        continue;
                    }

                    Storage::disk('azure')->put($file['id'], $file['content']);
                    $fileNumber++;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Secret create failed', ['error' => $e->getMessage()]);

            return response()->json([
                'response_code'    => Response::HTTP_BAD_REQUEST,
                'response_message' => 'Could not create secret.',
            ], Response::HTTP_BAD_REQUEST);
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

        if ($id === null) {
            return response()->json(null, 400);
        }

        $secret = Secret::where('id', $id)->first();

        if ($secret === null) {
            return response()->json(['response_code' => Response::HTTP_BAD_REQUEST]);
        }

        $secret->delete();

        return response()->json(['response_code' => Response::HTTP_OK]);
    }

    /**
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

        // dynamisk property til respons, ikke DB
        $secret->fileIds = $fileIds;

        return response()->json($secret);
    }

    /**
     * Runs by Azure functions.
     */
    public function scheduler(Request $request): void
    {
        $secrets = Secret::where('expires_at', '<', Carbon::now())->get();

        foreach ($secrets as $secret) {
            $secret->delete();

            $file = $this->externalStorageService->file($secret->id);

            if ($file !== null && $file->status() === Response::HTTP_OK) {
                SecretFileUploadHelper::deleteIfFailedTryAgain($secret->id);
            }
        }
    }
}
