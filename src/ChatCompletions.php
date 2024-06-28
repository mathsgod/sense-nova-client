<?php

namespace SenseNova;

class ChatCompletions
{
    protected $client;

    public function __construct(\GuzzleHttp\Client $client)
    {
        $this->client = $client;
    }

    public function create(array $body)
    {
        return $this->client->post("llm/chat-completions", [
            "json" => $body
        ]);
    }
}
