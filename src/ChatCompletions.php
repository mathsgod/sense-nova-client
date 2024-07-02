<?php

namespace SenseNova;

use Closure;
use Generator;
use PDO;
use Psr\Http\Message\ResponseInterface;
use React\EventLoop\Loop;
use React\Http\Browser;
use React\Stream\ReadableStreamInterface;
use React\Stream\ThroughStream;
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
    private $temperature = 0.8;

    private $authorization;

    public function __construct(\GuzzleHttp\Client $client, string $authorization)
    {
        $this->client = $client;
        $this->authorization = $authorization;
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

    public function setTemperature(string $temperature)
    {
        $this->temperature = $temperature;
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
                "tools" => $this->tools,
                "temperature" => $this->temperature,

                /*   "plugins"=>[
                    "web_search"=>[
                        "search_enabled"=>true,
                        "result_enable"=>true

                    ]
                ] */

            ]);

            /*    echo json_encode([
                "model" => $this->model,
                "messages" => $this->messages,
                "tools" => $this->tools
            ], JSON_PRETTY_PRINT) . "\n\n";
 */

            if (isset($data["choices"][0]["tool_calls"]) && $tool_calls = $data["choices"][0]["tool_calls"]) {
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
                    "content" => $data["choices"][0]["message"]
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

    public function runAsync()
    {

        $broswer = new Browser();


        $promise = $broswer->requestStreaming("POST", "https://api.sensenova.cn/v1/llm/chat-completions", [
            "Content-Type" => "application/json",
            "Authorization" => "Bearer " . $this->authorization
        ], json_encode([
            "model" => $this->model,
            "stream" => true,
            "messages" => $this->messages,
            "tools" => $this->tools
        ]));


        $stream = new ThroughStream();

        $promise->then(function (ResponseInterface $response) use (&$stream) {

            $tool_calls = [];
            $s = $response->getBody();
            assert($s instanceof ReadableStreamInterface);
            $s->on("data", function ($chunk) use (&$stream, &$tool_calls) {

                $lines = explode("\n\n", $chunk);
                //filter out empty lines
                $lines = array_filter($lines);
                foreach ($lines as $line) {

                    //remove data:
                    $line = trim(substr($line, 5));

                    if ($line == "[DONE]") {
                        if (count($tool_calls)) {

                            $this->messages[] = [
                                "role" => "assistant",
                                "tool_calls" => $tool_calls,
                            ];

                            foreach ($tool_calls as $tool_call) {
                                //execute function

                                $this->messages[] = [
                                    "role" => "tool",
                                    "tool_call_id" => $tool_call["id"],
                                    "content" => json_encode($this->executeFunction($tool_call["function"]), JSON_UNESCAPED_UNICODE),
                                ];
                            }

                            $s1 = $this->runAsync();
                            $s1->on('data', function ($data) use ($stream) {
                                $stream->write($data);
                            });
                            $s1->on("end", function () use ($stream) {
                                $stream->end();
                            });

                            $s1->on("close", function () use ($stream) {
                                $stream->close();
                            });
                        } else {
                            $stream->write("data: [DONE]");
                            $stream->end();
                            $stream->close();
                        }
                        return;
                    }



                    $message = json_decode($line, true)["data"];


                    //print_R($message);


                    //if (isset($message["usage"])) {
                    //  $this->usages[] = $message["usage"];
                    //  continue;
                    //}

                    if (isset($message["choices"][0]["delta"])) {
                        //$s->write("data: " . $delta["content"] . "\n\n");
                        $contents[] = $message["choices"][0]["delta"];
                        $stream->write("data: " . $line . "\n\n");
                        return;
                    }

                    if (isset($delta["tool_calls"])) {
                        $tool_call = $delta["tool_calls"][0];

                        if (isset($tool_call["id"])) {
                            $tool_calls[] = $tool_call;
                        } else {
                            $index = intval($tool_call["index"]);
                            $tool_calls[$index]["function"]["arguments"] .= $tool_call["function"]["arguments"];
                        }
                    }
                }
            });

            /* $body = $response->getBody();
            $next_chunk = "";
            $tool_calls = [];

            while (!$body->eof()) {

                $chunk = $body->read(100);

                $chunk = $next_chunk . $chunk;
                $lines = explode("\n\n", $chunk);

                //if last line is not empty, then it is not complete, only process the lines without last line
                if ($lines[count($lines) - 1]) {
                    $next_chunk = array_pop($lines);
                } else {
                    $next_chunk = "";
                }

                //filter out empty lines
                $lines = array_filter($lines);
                foreach ($lines as $line) {

                    if (substr($line, 0, 5) != "data:") continue;

                    //remove data:
                    $line = substr($line, 5);


                    if ($line == "[DONE]") {
                        if (count($tool_calls)) {

                            $this->messages[] = [
                                "role" => "assistant",
                                "tool_calls" => $tool_calls,
                            ];

                            foreach ($tool_calls as $tool_call) {
                                //execute function

                                $this->messages[] = [
                                    "role" => "tool",
                                    "tool_call_id" => $tool_call["id"],
                                    "content" => json_encode($this->executeFunction($tool_call["function"]), JSON_UNESCAPED_UNICODE),
                                ];
                            }

                            $s1 = $this->runAsync();
                            $s1->on('data', function ($data) use ($stream) {
                                $stream->write($data);
                            });
                            $s1->on("end", function () use ($stream) {
                                $stream->end();
                            });

                            $s1->on("close", function () use ($stream) {
                                $stream->close();
                            });
                        } else {
                            $stream->write("data: [DONE]\n\n");
                            $stream->end();
                            $stream->close();
                        }
                        break;
                    }


                    $message = json_decode($line, true)["data"];

                    //print_R($message);


                    //if (isset($message["usage"])) {
                    //  $this->usages[] = $message["usage"];
                    //  continue;
                    //}

                    if (isset($message["choices"][0]["delta"])) {
                        //$s->write("data: " . $delta["content"] . "\n\n");
                        $contents[] = $message["choices"][0]["delta"];
                        $stream->write("data: " . $line . "\n\n");
                        continue;
                    }

                    if (isset($delta["tool_calls"])) {
                        $tool_call = $delta["tool_calls"][0];

                        if (isset($tool_call["id"])) {
                            $tool_calls[] = $tool_call;
                        } else {
                            $index = intval($tool_call["index"]);
                            $tool_calls[$index]["function"]["arguments"] .= $tool_call["function"]["arguments"];
                        }
                    }
                }
            } */
        });

        return $stream;
    }
}
