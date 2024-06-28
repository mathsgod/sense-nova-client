## SenseNovo php client

### Authentication

```php

use SenseNovo\Client;

$client = new Client('your_access_key', 'your_secret_key');

```



### Chat completions


```php

print_r($client->chatCompletions()->create([
    "model" => "SenseChat-5",
    "messages"=>[
        [
            "role"=>"user",
            "content"=>"Hello, how are you?"
        ]
    ]
]));

```


#### Chat completions tools call

Tool file
```php
use SenseNova\ChatCompletions\Attributes\Parameter;
use SenseNova\ChatCompletions\Attributes\Tool;

class Tool
{
    public $price = "$799";

    #[Tool(description: 'Get the price of iphone')]
    public function getIPhonePrice(#[Parameter("model of the phone")] string $model)
    {
        return ["price" => $this->price, "model" => $model];
    }

    #[Tool(description: 'Get the release date of iphone')]
    public function getIPhoneReleaseDate(#[Parameter("model of the phone")] string $model)
    {
        return ["date" => "2023-01-01", "model" => $model];
    }
}
```

```php
$tool=new Tool();
$cc = $client->chatCompletions();
$cc->setModel("SenseChat-5");
$cc->addTool(Closure::fromCallable([$tool, "getIPhonePrice"]));
$cc->addTool(Closure::fromCallable([$tool, "getIPhoneReleaseDate"]));

$cc->addMessage(["role" => "user", "content" => "get iphone 14 price and release date"]);

print_R($cc->run());




