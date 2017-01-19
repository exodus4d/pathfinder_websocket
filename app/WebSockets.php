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

use React;

class WebSockets {

    protected $dns;
    protected $wsListenPort;
    protected $wsListenHost;

    function __construct($dns, $wsListenPort, $wsListenHost){
        $this->dns = $dns;
        $this->wsListenPort = (int)$wsListenPort;
        $this->wsListenHost = $wsListenHost;

        $this->startMapSocket();
    }

    private function startMapSocket(){
        $loop   = React\EventLoop\Factory::create();

        // Listen for the web server to make a ZeroMQ push after an ajax request
        $context = new React\ZMQ\Context($loop);

        //$pull = $context->getSocket(\ZMQ::SOCKET_REP);
        $pull = $context->getSocket(\ZMQ::SOCKET_PULL);
        // Binding to 127.0.0.1 means, the only client that can connect is itself
        $pull->bind( $this->dns );

        // main app -> inject socket for response
        $mapUpdate = new Main\MapUpdate($pull);
        // "onMessage" listener
        $pull->on('message', [$mapUpdate, 'receiveData']);

        // Set up our WebSocket server for clients wanting real-time updates
        $webSock = new React\Socket\Server($loop);
        // Binding to 0.0.0.0 means remotes can connect (Web Clients)
        $webSock->listen($this->wsListenPort, $this->wsListenHost);
        new IoServer(
            new HttpServer(
                new WsServer(
                    $mapUpdate
                )
            ),
            $webSock
        );

        $loop->run();
    }

}