<?php

namespace App\Helpers;

use App\Jobs\ProcessDeleteSecretFile;
use Illuminate\Support\Facades\Storage;

class SecretFileUploadHelper
{
    public static function deleteIfFailedTryAgain(string $fileId): void {
        $deleted = Storage::disk('azure')->delete($fileId);

        if(!$deleted) {
            ProcessDeleteSecretFile::dispatch($fileId)->delay(now()->addSeconds(60));
        }

    }
}
