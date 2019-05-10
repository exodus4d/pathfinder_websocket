<?php
/**
 * Created by PhpStorm.
 * User: Exodus
 * Date: 01.11.2016
 * Time: 18:21
 */

namespace Exodus4D\Socket;


use Exodus4D\Socket\Socket\TcpSocket;
use React\EventLoop;
use React\Socket;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;

class WebSockets {

    /**
     * @var string
     */
    protected $dsn;

    /**
     * @var int
     */
    protected $wsListenPort;

    /**
     * @var string
     */
    protected $wsListenHost;

    /**
     * WebSockets constructor.
     * @param string $dsn
     * @param int $wsListenPort
     * @param string $wsListenHost
     */
    function __construct(string $dsn, int $wsListenPort, string $wsListenHost){
        $this->dsn = $dsn;
        $this->wsListenPort = $wsListenPort;
        $this->wsListenHost = $wsListenHost;

        $this->startMapSocket();
    }

    private function startMapSocket(){
        // global EventLoop
        $loop   = EventLoop\Factory::create();

        // global MessageComponent (main app) (handles all business logic)
        $mapUpdate = new Main\MapUpdate();

        // TCP Socket -------------------------------------------------------------------------------------------------
        $tcpSocket = new TcpSocket($loop, $mapUpdate);
        // TCP Server (WebServer <-> TCPServer <-> TCPSocket communication)
        $server = new Socket\Server($this->dsn, $loop, [
            'tcp' => [
                'backlog' => 20,
                'so_reuseport' => true
            ]
        ]);

        $server->on('connection', function(Socket\ConnectionInterface $connection) use ($tcpSocket){
            $tcpSocket->onConnect($connection);
        });

        $server->on('error', function(\Exception $e){
            echo 'error: ' . $e->getMessage() . PHP_EOL;
        });

        // WebSocketServer --------------------------------------------------------------------------------------------

        // Binding to 0.0.0.0 means remotes can connect (Web Clients)
        $webSocketURI = $this->wsListenHost . ':' . $this->wsListenPort;

        // Set up our WebSocket server for clients subscriptions
        $webSock = new Socket\TcpServer($webSocketURI, $loop);
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