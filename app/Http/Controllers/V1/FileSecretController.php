<?php

namespace App\Http\Controllers\V1;

use App\Services\ExternalStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class FileSecretController
{

    private ExternalStorageService $externalStorageService;

    public function __construct(ExternalStorageService $externalStorageService) {
        $this->externalStorageService = $externalStorageService;
    }


    public function find(Request $request): JsonResponse
    {

        $fileIds = $request->input('fileIds');

        if(!is_array($fileIds)) {
            return response()->json(['response_code' => Response::HTTP_BAD_REQUEST]);
        }

        $files = [];

        foreach($fileIds as $fileId) {
            $file = $this->externalStorageService->file($fileId);

            if($file !== null && $file->status() !== null && $file->status() == Response::HTTP_OK) {
                $files[] = ['content' => $file->body()];
            }
        }

        return response()->json($files);

    }

    public function delete(Request $request): JsonResponse
    {

        $fileIds = $request->input('fileIds');

        if(!is_array($fileIds)) {
            return response()->json(['response_code' => Response::HTTP_BAD_REQUEST]);
        }

        foreach($fileIds as $fileId) {
            Storage::disk('azure')->delete($fileId);
        }

        return response()->json(['response_code' => Response::HTTP_OK]);

    }


}
