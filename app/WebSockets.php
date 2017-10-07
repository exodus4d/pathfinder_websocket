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

        // Socket for map update data -------------------------------------------------------------
        $pull = $context->getSocket(\ZMQ::SOCKET_PULL);
        // Binding to 127.0.0.1 means, the only client that can connect is itself
        $pull->bind( $this->dns );

        // main app -> inject socket for response
        $mapUpdate = new Main\MapUpdate();
        // "onMessage" listener
        $pull->on('message', [$mapUpdate, 'receiveData']);

        // Socket for log data --------------------------------------------------------------------
        //$pullSocketLogs = $context->getSocket(\ZMQ::SOCKET_PULL);
        //$pullSocketLogs->bind( "tcp://127.0.0.1:5555" );
        //$pullSocketLogs->on('message', [$mapUpdate, 'receiveLogData']) ;

        // Binding to 0.0.0.0 means remotes can connect (Web Clients)
        $webSocketURI = $this->wsListenHost . ':' . $this->wsListenPort;

        // Set up our WebSocket server for clients wanting real-time updates
        $webSock = new React\Socket\Server($webSocketURI, $loop);
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