<?php
/**
 * Created by PhpStorm.
 * User: Exodus
 * Date: 01.11.2016
 * Time: 18:21
 */

namespace Exodus4D\Socket;

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;

class WebSockets {

    function __construct(){
        $this->startChat();
    }

    private function startChat(){
        $server = IoServer::factory(
            new HttpServer(
                new WsServer(
                    new Main\Chat()
                )
            ),
            8020
        );

        $server->run();
    }

}