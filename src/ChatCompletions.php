<?php

namespace SenseNova;

use Closure;
use PDO;
use Reflection;
use ReflectionFunction;
use SenseNova\ChatCompletions\Attributes\Parameter;
use SenseNova\ChatCompletions\Attributes\Tool;

class ChatCompletions
{
    protected $client;
    private $tools = [];
    private $tools_map = [];
    private $messages = [];
    private $model;

    public function __construct(\GuzzleHttp\Client $client)
    {
        $this->client = $client;
    }

    public function create(array $body)
    {
        $resp = $this->client->post("llm/chat-completions", [
            "json" => $body
        ]);
        return json_decode($resp->getBody()->getContents(), true)["data"];
    }

    public function addTool(Closure $tool)
    {
        $func = new ReflectionFunction($tool);

        if ($func->getAttributes(Tool::class) == null) {
            throw new \Exception("Function must have Tool attribute");
        }

        $tool_attr = $func->getAttributes(Tool::class);
        $args = $tool_attr[0]->getArguments();

        $name = $args['name'] ?? $func->getName();


        $tool_instance = [
            "type" => "function",
            "function" => [
                "name" => $name,
                "description" => $args['description'],
            ]
        ];


        foreach ($func->getParameters() as $param) {
            $param_attr = $param->getAttributes(Parameter::class);
            if ($param_attr) {
                $param_attr = $param_attr[0];

                $property = [];
                $property["description"] = ($param_attr->newInstance())->getDescription();
                $property["type"] = $param->getType()->getName();

                $properties[$param->getName()] = $property;

                if (!$param->isOptional()) {
                    $required[] = $param->getName();
                }
            }
        }

        $tool_instance["function"]["parameters"] = [
            "type" => "object",
            "properties" => $properties,
            "required" => $required
        ];

        $this->tools[] = $tool_instance;
        $this->tools_map[$name] = $func;
    }

    public function addMessage(array $message)
    {
        $this->messages[] = $message;
    }

    public function setModel(string $model)
    {
        $this->model = $model;
    }

    public function run()
    {
        do {
            $data = $this->create([
                "model" => $this->model,
                "messages" => $this->messages,
                "tools" => $this->tools
            ]);

            if ($tool_calls = $data["choices"][0]["tool_calls"]) {
                $this->messages[] = [
                    "role" => "assistant",
                    "tool_calls" => array_map(function ($tool_call) {
                        return [
                            "id" => $tool_call["id"],
                            "type" => "function",
                            "function" => $tool_call["function"]
                        ];
                    }, $tool_calls)
                ];

                foreach ($tool_calls as $tool_call) {
                    $this->messages[] = [
                        "role" => "tool",
                        "tool_call_id" => $tool_call["id"],
                        "content" => json_encode($this->executeFunction($tool_call["function"]), JSON_UNESCAPED_UNICODE),
                    ];
                }
            } else {

                $this->messages[] = [
                    "role" => "assistant",
                    "content" => $data["choices"][0]["content"]
                ];
                break;
            }
        } while (true);

        return $data;
    }

    private function executeFunction($function)
    {
        $name = $function["name"];
        $arguments = json_decode($function["arguments"], true);

        if (!array_key_exists($name, $this->tools_map)) {
            return null;
        }
        $func = $this->tools_map[$name];
        return $func->invoke(...$arguments);
    }
}
