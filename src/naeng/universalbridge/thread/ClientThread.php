<?php

declare(strict_types=1);

namespace naeng\universalbridge\thread;

use pocketmine\snooze\SleeperHandlerEntry;
use pocketmine\thread\Thread;
use pmmp\thread\ThreadSafeArray;


class ClientThread extends Thread{

    private int $nextId = 0;
    private bool $shutdown = false;

    public function __construct(
        protected readonly int $port,
        protected readonly string $password,
        protected readonly SleeperHandlerEntry $sleeperHandlerEntry,
        protected ThreadSafeArray $inboundQueue,
        protected ThreadSafeArray $responseQueue
    ){}

    protected function onRun() : void{
        $serverSocket = stream_socket_server(
            "tcp://0.0.0.0:" . $this->port,
            $errno,
            $errstr
        );

        if(!$serverSocket){
            echo "서버 소켓 생성 실패: $errstr ($errno)\n";
            return;
        }

        stream_set_blocking($serverSocket, false);

        while(!$this->shutdown){
            $clientSocket = @stream_socket_accept($serverSocket, 0);

            if($clientSocket){
                $data = fgets($clientSocket);
                $receivedData = ["error" => "비 정상적인 타입의 데이터"];

                try{
                    $receivedData = json_decode($data, true);
                }catch(\Exception $e){
                    try{
                        $receivedData = [(string)$data];
                    } catch (\Exception $e) {}
                }

                $receivedData["client_ip"] = stream_socket_get_name($clientSocket, true);

                $isFromValidServer = ($receivedData["password"] ?? null) === $this->password;
                if(!$isFromValidServer){
                    $receivedData["error"] = "인증되지 않음";
                }

                if(!isset($receivedData["type"])){
                    $receivedData["error"] = "타입이 지정되지 않음";
                }

                $id = $this->nextId++;

                $receivedData["id"] = $id;

                $this->inboundQueue[] = igbinary_serialize($receivedData);
                $this->sleeperHandlerEntry->createNotifier()->wakeupSleeper();
                
                if(!$isFromValidServer){
                    fputs($clientSocket, json_encode([
                        "status" => "failed",
                        "reason" => $receivedData["error"] ?? ""
                    ]));
                    fclose($clientSocket);

                    continue;
                }

                stream_set_timeout($clientSocket, 5);
                $temp = time();

                while(true){
                    if(time() - $temp > 3){ // 3초 지남
                        fputs($clientSocket, json_encode([
                            "status" => "failed",
                            "reason" => "서버에서 응답하지 않음"
                        ]));
                        break;
                    }

                    $response = $this->responseQueue->shift();
                    if($response === null){
                        continue;
                    }

                    $response = igbinary_unserialize($response);
                    
                    if(($response["id"] ?? -1) !== $id){
                        if($response !== null){
                            $this->responseQueue[] = igbinary_serialize($response);
                        }
                        continue; 
                    }

                    if(($response["response"] ?? "") === ""){
                        fputs($clientSocket, json_encode([
                            "status" => "failed",
                            "reason" => "서버에서 응답하지 않음"
                        ]));
                        break;
                    }

                    fputs($clientSocket, json_encode([
                        "status" => "success",
                        "response" => $response["response"]
                    ]));
                    break;
                }

                fclose($clientSocket);
            }

            usleep(100000); // 0.1초 대기
        }

        fclose($serverSocket);
    }

    public function shutdown() : void{
        $this->shutdown = true;
    }

}