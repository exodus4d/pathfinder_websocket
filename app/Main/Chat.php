<?php
namespace Exodus4D\Socket\Main;


use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class Chat implements MessageComponentInterface {
    protected $clients;

    public function __construct() {
        $this->clients = new \SplObjectStorage;
        echo "__construct() \n";
    }

    public function onOpen(ConnectionInterface $conn) {
        //store the new connection
        $this->clients->attach($conn);
        echo "NEW connection! ({$conn->resourceId})\n";
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        $numRecv = count($this->clients) - 1;
        echo sprintf('Connection %d sending to %d other connection%s' . "\n"
            , $from->resourceId, $numRecv, $numRecv == 1 ? '' : 's');

        foreach ($this->clients as $client) {
           // if ($from !== $client) {
                $client->send($msg);
           // }
        }
    }

    public function onClose(ConnectionInterface $conn) {
        $this->clients->detach($conn);

        echo "Connection {$conn->resourceId} has disconnected\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "An error has occurred: {$e->getMessage()}\n";

        $conn->close();
    }
}