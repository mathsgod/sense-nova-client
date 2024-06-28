<?php

namespace SenseNova;

use Firebase\JWT\JWT;

class Client
{

    public $client;
    public $chatCompletions;
    public $models;

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

        $this->chatCompletions = new ChatCompletions($this->client);

        $this->models = new Models($this->client);
    }
}
