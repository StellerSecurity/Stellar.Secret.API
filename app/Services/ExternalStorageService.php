<?php

namespace App\Services;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class ExternalStorageService
{

    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = getenv('AZURE_STORAGE_BASEURL');
    }

    public function file(string $fileId): PromiseInterface|Response|null
    {

        try {
            $response = Http::retry(3, 100)->get($this->baseUrl . "/" . $fileId);
            return $response;
        } catch (RequestException $exception) {
            return null;
        }
    }

}
