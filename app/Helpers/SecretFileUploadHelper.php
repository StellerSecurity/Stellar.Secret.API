<?php

namespace App\Helpers;

class SecretFileUploadHelper
{

    const MAX_FILE_SIZE_MB = 15;

    public static function getMaxSizeInMB(): int {
        return self::MAX_FILE_SIZE_MB;
    }

}
