<?php

namespace Bot;

/**
 * Created by PhpStorm.
 * User: masterx2
 * Date: 06.12.15
 * Time: 22:46
 */

use ION\Deferred;
use Pimple\Container;

class Groovy {
    const BOT_IDLE = 1;
    const BOT_WAIT_FOR_MESSAGES = 2;
    const BOT_EXIT = 3;

    /** @var Container */
    public $storage;
    private $state = self::BOT_IDLE;
    private $lastMessageId = 0;

    /**
     * Groovy constructor.
     */
    public function __construct() {
        $this->state = self::BOT_IDLE;
        $this->configure();
    }

    public function configure() {
        $this->storage = new Container();
        foreach(require(DOC_ROOT.'/config/groovy-default.php') as $item => $value) {
            $this->storage[$item] = $value;
        };

        $this->storage['redis'] = function($c) {
            $config = $c['redis.config'];
            $redis = new \Redis();
            $redis->connect($config['host'], $config['port']);
            return $redis;
        };
    }

    public function start() {
        echo "Start Listen...\n";

        while ($this->state != self::BOT_EXIT) {
            $defer = new Deferred(function(){});
            $this->state = self::BOT_WAIT_FOR_MESSAGES;
            try {
                $request = $this->requestApi(
                    'getUpdates',
                    ['timeout' => $this->storage['lpTimeout'], 'offset' => $this->lastMessageId],
                    ['timeout' => -1]
                );

                if ($request->status_code != 200) { $defer->fail(new \ErrorException('ServerError'));}

                $data = json_decode($request->body, true);
                $result = $data['result'];

                if (!empty($result)) {
                    $message = $result[count($result) - 1];
                    $this->lastMessageId = $message['update_id'] + 1;
                    $defer->done($message['message']);
                }

            } catch (\ErrorException $e) {
                echo $e->getMessage();
            }

            $defer->onDone(function($data) {
                $this->dispatchMessage($data);
            });

            $defer->onFail(function() {
                $this->state = self::BOT_EXIT;
            });
        }
    }

    public function requestApi($method, $data, $options = []) {
        return \Requests::post('https://api.telegram.org/bot'.$this->storage['api_key'].'/'.$method,
            ['Content-Type' => 'application/x-www-form-urlencoded'], $data, $options);
    }

    public function dispatchMessage($message) {
        echo "{$message['from']['username']} > {$message['text']}" . PHP_EOL;
        $subcommand = $this->storage['redis']->get($message['from']['id']);

        if ($subcommand) {
            $parts = array_merge([$subcommand], explode(' ', $message['text']));
            $this->storage['redis']->del($message['from']['id']);
        } else {
            $parts = explode(' ', $message['text']);
        }

        switch ($parts[0]) {
            case "/start":
                $this->requestApi('sendMessage', [
                    "chat_id" => $message['chat']['id'],
                    "text" => "Привет, {$message['from']['first_name']} {$message['from']['last_name']}, меня зовут Groovy! Я пока что ничего не умею, но быстро учусь!"
                ]);
                break;
            case "/status":
                $this->requestApi('sendMessage', [
                    "chat_id" => $message['chat']['id'],
                    "text" => "Привет, {$message['from']['username']}, я в полном порядке =)"
                ]);
                break;
            case "/images":
                if (isset($parts[1])) {
                    $this->requestApi('sendMessage', [
                        "chat_id" => $message['chat']['id'],
                        "text" => "http://lorempixel.com/400/400/{$parts[1]}/?".rand(1,999),
                        "reply_markup" => json_encode([
                            "hide_keyboard" => true
                        ])
                    ]);
                } else {
                    $this->storage['redis']->set($message['from']['id'], "/images", 60);
                    $this->requestApi('sendMessage', [
                        "chat_id" => $message['chat']['id'],
                        "text" => "Выбери категорию",
                        "reply_markup" => json_encode(["keyboard" =>
                            [
                                ["abstract","animals","business"],
                                ["cats","city","food"],
                                ["night","life","fashion"],
                                ["people","nature","sports"],
                                ["technics","transport"]
                            ],
                            "one_time_keyboard" => true
                        ])
                    ]);
                }
                break;
            case "/debug":
                $this->requestApi('sendMessage', [
                    "chat_id" => $message['chat']['id'],
                    "text" => serialize($message)
                ]);
        }
    }
}