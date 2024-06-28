<?php

namespace SenseNova;

use Firebase\JWT\JWT;

class Client
{
    public $client;

    public function __construct($access_key, $secret_key)
    {
        $authorization = JWT::encode([
            "iss" => $access_key,
            "exp" => time() + 3600,
            "nbf" => time() - 5,
        ], $secret_key, "HS256");

        $this->client = new \GuzzleHttp\Client([
            "base_uri" => "https://api.sensenova.cn/v1/",
            "verify" => false,
            "headers" => [
                "Authorization" => "Bearer " . $authorization
            ]
        ]);
    }

    public function chatCompletions()
    {
        return new ChatCompletions($this->client);
    }

    public function models()
    {
        return new Models($this->client);
    }
}
