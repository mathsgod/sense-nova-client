<?php

namespace SenseNova;

class Models
{
    protected $client;

    public function __construct(\GuzzleHttp\Client $client)
    {
        $this->client = $client;
    }

    public function list()
    {
        return json_decode($this->client->get("llm/models")->getBody()->getContents(), true)["data"];
    }
}
