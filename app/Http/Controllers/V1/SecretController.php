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
     * Create a new secret.
     *
     * The UI API sends:
     * - id: hashed secret id
     * - message: encrypted message ciphertext, or empty string for file-only secrets
     * - files: optional encrypted file payloads
     * - has_password: whether a password was used client-side
     * - encryption_version: v1 or v2
     * - expires_at: expiration datetime from the UI API
     */
    public function add(Request $request): JsonResponse
    {
        $id = $request->input('id');
        $message = $request->input('message');
        $files = $request->input('files');
        $expiresAt = $request->input('expires_at');

        $hasPassword = (bool) $request->input('has_password', false);

        $encryptionVersion = strtolower(trim((string) $request->input('encryption_version', 'v1')));

        if (! in_array($encryptionVersion, ['v1', 'v2'], true)) {
            return response()->json([
                'response_code' => Response::HTTP_BAD_REQUEST,
                'response_message' => 'Invalid encryption_version',
            ], Response::HTTP_BAD_REQUEST);
        }

        if (! is_string($id) || $id === '') {
            return response()->json([
                'response_code' => Response::HTTP_BAD_REQUEST,
                'response_message' => 'ID is empty',
            ], Response::HTTP_BAD_REQUEST);
        }

        if (strlen($id) < 16) {
            return response()->json([
                'response_code' => Response::HTTP_BAD_REQUEST,
                'response_message' => 'ID is too short',
            ], Response::HTTP_BAD_REQUEST);
        }

        if ($message !== null && ! is_string($message)) {
            return response()->json([
                'response_code' => Response::HTTP_BAD_REQUEST,
                'response_message' => 'Message must be a string',
            ], Response::HTTP_BAD_REQUEST);
        }

        if ($files !== null && ! is_array($files)) {
            return response()->json([
                'response_code' => Response::HTTP_BAD_REQUEST,
                'response_message' => 'Files must be an array',
            ], Response::HTTP_BAD_REQUEST);
        }

        if (is_array($files)) {
            foreach ($files as $file) {
                if (! is_array($file)) {
                    return response()->json([
                        'response_code' => Response::HTTP_BAD_REQUEST,
                        'response_message' => 'File item must be an object',
                    ], Response::HTTP_BAD_REQUEST);
                }

                if (! isset($file['id']) || ! is_string($file['id']) || $file['id'] === '') {
                    return response()->json([
                        'response_code' => Response::HTTP_BAD_REQUEST,
                        'response_message' => 'File ID is missing',
                    ], Response::HTTP_BAD_REQUEST);
                }

                if (! isset($file['content']) || ! is_string($file['content']) || $file['content'] === '') {
                    return response()->json([
                        'response_code' => Response::HTTP_BAD_REQUEST,
                        'response_message' => 'File content is missing',
                    ], Response::HTTP_BAD_REQUEST);
                }
            }
        }

        $hasMessage = is_string($message) && $message !== '';
        $hasFiles = is_array($files) && count($files) > 0;

        if (! $hasMessage && ! $hasFiles) {
            return response()->json([
                'response_code' => Response::HTTP_BAD_REQUEST,
                'response_message' => 'Message or file is required.',
            ], Response::HTTP_BAD_REQUEST);
        }

        if (! $hasMessage) {
            $message = '';
        }

        try {
            $secret = Secret::create([
                'id' => $id,
                'message' => $message,
                'expires_at' => $expiresAt,
                'has_password' => $hasPassword,
                'encryption_version' => $encryptionVersion,
            ]);

            if ($hasFiles) {
                $firstFile = $files[0];

                /*
                 * Store the encrypted file by secret ID.
                 *
                 * The find() and scheduler() methods both look up/delete files by the secret ID.
                 * If we store by $firstFile['id'] instead, retrieval and cleanup can break unless
                 * the UI always makes file.id equal to the secret id.
                 */
                Storage::disk('azure')->put($id, $firstFile['content']);
            }
        } catch (\Throwable $e) {
            Log::warning('Secret create failed', [
                'secret_id' => is_string($id) ? $id : null,
                'has_message' => $hasMessage,
                'has_files' => $hasFiles,
                'encryption_version' => $encryptionVersion,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'response_code' => Response::HTTP_BAD_REQUEST,
                'response_message' => 'Could not create secret.',
            ], Response::HTTP_BAD_REQUEST);
        }

        return response()->json($secret);
    }

    public function delete(Request $request): JsonResponse
    {
        $id = $request->input('id');

        if ($id === null) {
            return response()->json(null, Response::HTTP_BAD_REQUEST);
        }

        $secret = Secret::where('id', $id)->first();

        if ($secret === null) {
            return response()->json([
                'response_code' => Response::HTTP_BAD_REQUEST,
            ], Response::HTTP_BAD_REQUEST);
        }

        $secret->delete();

        $file = $this->externalStorageService->file($id);

        if ($file !== null && $file->status() === Response::HTTP_OK) {
            SecretFileUploadHelper::deleteIfFailedTryAgain($id);
        }

        return response()->json([
            'response_code' => Response::HTTP_OK,
        ]);
    }

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
        $file = $this->externalStorageService->file($id);

        if ($file !== null && ($file->status() === null || $file->status() === Response::HTTP_OK)) {
            $fileIds = [$id];
        }

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
