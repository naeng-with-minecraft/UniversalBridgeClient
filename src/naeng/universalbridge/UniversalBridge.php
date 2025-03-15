<?php

declare(strict_types=1);

namespace naeng\universalbridge;

use naeng\DiscordCore\DiscordCore;
use naeng\DiscordCore\webhook\Webhook;
use naeng\universalbridge\thread\ClientThread;
use pocketmine\plugin\PluginBase;
use pocketmine\snooze\SleeperHandlerEntry;
use pmmp\thread\ThreadSafeArray;
use pocketmine\utils\SingletonTrait;
use SOFe\AwaitGenerator\Await;


class UniversalBridge extends PluginBase{

    use SingletonTrait;

    private SleeperHandlerEntry $sleeperHandlerEntry;
    private ThreadSafeArray $inboundQueue;
    private ThreadSafeArray $responseQueue;
    private ClientThread $thread;

    private int $notifierId = -1;

    private array $subscribers = [];


    public function onLoad() : void{
        self::setInstance($this);
    }

    public function onEnable() : void{
        $config = $this->getConfig();
        if($config->get("password", null) === null){
            $config->set("password", "YOUR_PASSWORD");
            $config->save();

            $this->getLogger()->warning("비밀번호가 설정되지 않았습니다. 기본값인 'test_password'로 설정합니다.");
        }

        if($config->get("webhook", null) === null){
            $config->set("webhook", "YOUR_DISCORD_WEBHOOK_URL");
            $config->save();

            $this->getLogger()->warning("웹훅 URL이 설정되지 않았습니다.");
        }

        if($config->get("port", null) === null){
            $config->set("port", 8888);
            $config->save();

            $this->getLogger()->warning("포트가 설정되지 않았습니다. 기본값인 8888로 설정합니다.");
        }

        $this->inboundQueue = new ThreadSafeArray();
        $this->responseQueue = new ThreadSafeArray();

        $logger = $this->getLogger();
        $isDiscordCordLoaded = class_exists(DiscordCore::class);

        $webhookUrl = $config->get("webhook");

        $this->sleeperHandlerEntry = $this->getServer()->getTickSleeper()->addNotifier(function() use($logger, $isDiscordCordLoaded, $webhookUrl){
            while(($data = $this->inboundQueue->shift()) !== null){
                $receivedData = igbinary_unserialize($data);
                unset($receivedData["password"]);

                $stringtifiedData = json_encode($receivedData, JSON_UNESCAPED_UNICODE);

                if($isDiscordCordLoaded){
                    $webhook = new Webhook();
                    $webhook->setName((isset($receivedData["error"]) ? "[ERROR] " : "") . "universalbridge")
                        ->setContent($stringtifiedData)
                        ->send($webhookUrl);
                }else{
                    $logger->info($stringtifiedData);
                }

                if(isset($receivedData["error"])){
                    $logger->error($receivedData["error"]);
                    continue;
                }

                try{
                    $id = $receivedData["id"];
                    $type = $receivedData["type"];
                    
                    $temp = explode(":", $receivedData["client_ip"]);
                    $clientIp = $temp[0];
                    $clientPort = intval($temp[1]);

                    $subscriber = $this->subscribers[$type] ?? null;
                    $response = $subscriber === null ? "" : $subscriber($id, $clientIp, $clientPort, $receivedData);

                    if($response instanceof \Generator){
                        Await::f2c(function() use($response, $id, $clientIp, $clientPort, $receivedData){
                            try{
                                $response = yield from $response;
                            }catch(\Exception $e){
                                return;
                            }

                            $this->responseQueue[] = igbinary_serialize([
                                "id" => $id,
                                "response" => $response
                            ]);
                        });
                    }else{
                        $this->responseQueue[] = igbinary_serialize([
                            "id" => $id,
                            "response" => $response
                        ]);
                    }
                }catch(\Exception $e){}
            }
        });

        $this->notifierId = $this->sleeperHandlerEntry->getNotifierId();

        $this->thread = new ClientThread(
            $config->get("port"),
            $config->get("password"),
            $this->sleeperHandlerEntry,
            $this->inboundQueue,
            $this->responseQueue
        );

        $this->thread->start();
    }

    public function subscribe(string $type, \Closure|\Generator $resultClosure) : void{
        $this->subscribers[$type] = $resultClosure;
    }

    public function onDisable() : void{
        if(isset($this->thread)){
            $this->thread->shutdown();
        }

        if($this->notifierId != -1){
            $this->getServer()->getTickSleeper()->removeNotifier($this->notifierId);
        }
    }

}