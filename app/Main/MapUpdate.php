<?php
/**
 * Created by PhpStorm.
 * User: Exodus
 * Date: 02.12.2016
 * Time: 22:29
 */

namespace Exodus4D\Socket\Main;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class MapUpdate implements MessageComponentInterface {

    /**
     * timestamp (ms) from last healthCheck ping
     * -> timestamp received from remote TCP socket
     * @var
     */
    protected $healthCheckToken;

    /**
     * expire time for map access tokens (seconds)
     * @var int
     */
    protected $mapAccessExpireSeconds = 30;

    /**
     * character access tokens for clients
     * -> tokens are unique and expire onSubscribe!
     * @var
     */

    protected $characterAccessData;
    /**
     * access tokens for clients grouped by mapId
     * -> tokens are unique and expire onSubscribe!
     * @var
     */
    protected $mapAccessData;

    /**
     * connected characters
     * @var
     */
    protected $characters;
    /**
     * valid client connections  subscribed to maps
     * @var array
     */
    protected $subscriptions;

    /**
     * enable debug output
     * -> check debug() for more information
     * @var bool
     */
    protected $debug = false;

    /**
     * internal socket for response calls
     * @var null | \React\ZMQ\SocketWrapper
     */
    protected $internalSocket = null;

    public function __construct($socket) {
        $this->internalSocket = $socket;
        $this->characterAccessData = [];
        $this->mapAccessData = [];
        $this->characters = [];
        $this->subscriptions = [];

        $this->log('Server START ------------------------------------------');
    }

    public function onOpen(ConnectionInterface $conn) {
        $this->log('NEW connection. ID (' . $conn->resourceId .') ');
    }

    public function onMessage(ConnectionInterface $conn, $msg) {
        $msg = json_decode($msg, true);

        if(
            isset($msg['task']) &&
            isset($msg['load'])
        ){
            $task = $msg['task'];
            $load = $msg['load'];

            switch($task){
                case 'subscribe':
                    $this->subscribe($conn, $load);
                    break;
                case 'healthCheck':
                    $this->validateHealthCheck($conn, $load);
                    break;
                default:
                    break;
            }
        }
    }

    /**
     * @param ConnectionInterface $conn
     */
    public function onClose(ConnectionInterface $conn) {
        $this->unSubscribeConnection($conn);

        $this->log('DISCONNECTED connection. ID (' . $conn->resourceId .') ');
    }

    /**
     * @param ConnectionInterface $conn
     * @param \Exception $e
     */
    public function onError(ConnectionInterface $conn, \Exception $e) {
        $this->unSubscribeConnection($conn);
        $this->log('ERROR "' . $e->getMessage() . '" ID (' . $conn->resourceId .') ');
        $conn->close();
    }

    /**
     * Check token (timestamp from initial TCP healthCheck poke against token send from client
     * @param ConnectionInterface $conn
     * @param $token
     */
    private function validateHealthCheck($conn, $token){
        $isValid = 0;

        if(
            $token && $this->healthCheckToken &&
            $token === $this->healthCheckToken
        ){
            $isValid = 1;
        }
        $conn->send( json_encode($isValid) );

        // reset token
        $this->healthCheckToken = null;
    }

    /**
     * subscribes a connection to valid accessible maps
     * @param ConnectionInterface $conn
     * @param $subscribeData
     */
    private function subscribe(ConnectionInterface $conn, $subscribeData){
        $characterId = (int)$subscribeData['id'];
        $characterToken = $subscribeData['token'];

        if($characterId && $characterToken){

            // check if character access token is valid (exists and not expired in $this->characterAccessData
            if( $this->checkCharacterAccess($characterId, $characterToken) ){
                $this->characters[$characterId][$conn->resourceId] = $conn;

                // valid character -> check map access
                foreach((array)$subscribeData['mapData'] as $data){
                    $mapId = (int)$data['id'];
                    $mapToken = $data['token'];

                    if($mapId && $mapToken){
                        // check if token is valid (exists and not expired) in $this->mapAccessData
                        if( $this->checkMapAccess($characterId, $mapId, $mapToken) ){
                            // valid map subscribe request
                            $this->subscriptions[$mapId][$characterId] = $characterId;
                        }
                    }
                }
            }
        }
    }

    /**
     * subscribes an active connection from maps
     * @param ConnectionInterface $conn
     */
    private function unSubscribeConnection(ConnectionInterface $conn){
        $characterIds = $this->getCharacterIdsByConnection($conn);

        foreach($characterIds as $characterId){
            $this->unSubscribeCharacterId($characterId, $conn);
        }
    }

    /**
     * unSubscribe a $characterId from ALL maps
     * -> if $conn is set -> just unSub the $characterId from this $conn
     * @param int $characterId
     * @param null $conn
     * @return bool
     */
    private function unSubscribeCharacterId($characterId, $conn = null){
        if($characterId){

            // unSub from $this->characters -------------------------------------------------------
            if($conn){
                // just unSub a specific connection (e.g. single browser window)
                $resourceId = $conn->resourceId;
                if( isset($this->characters[$characterId][$resourceId]) ){
                    unset($this->characters[$characterId][$resourceId]);

                    if( !count($this->characters[$characterId]) ){
                        // no connection left for this character
                        unset($this->characters[$characterId]);
                    }
                }
            }else{
                // unSub ALL connections from a character (e.g. multiple browsers)
                if( isset($this->characters[$characterId]) ){
                    unset($this->characters[$characterId]);
                }
            }

            // unSub from $this->subscriptions ----------------------------------------------------
            foreach($this->subscriptions as $mapId => $characterIds){
                if(array_key_exists($characterId, $characterIds)){
                    unset($this->subscriptions[$mapId][$characterId]);

                    if( !count($this->subscriptions[$mapId]) ){
                        // no characters left on this map
                        unset($this->subscriptions[$mapId]);
                    }
                }
            }
        }

        return true;
    }

    /**
     * delete mapId from subscriptions and broadcast "delete msg" to clients
     * @param string $task
     * @param int $mapId
     * @return int
     */
    private function deleteMapId($task, $mapId){
        $connectionCount =  $this->broadcastMapData($task, $mapId, $mapId);

        // remove map from subscriptions
        if( isset($this->subscriptions[$mapId]) ){
            unset($this->subscriptions[$mapId]);
        }

        return $connectionCount;
    }

    /**
     * @param ConnectionInterface $conn
     * @return int[]
     */
    private function getCharacterIdsByConnection(ConnectionInterface $conn){
        $characterIds = [];
        $resourceId = $conn->resourceId;

        foreach($this->characters as $characterId => $resourceIDs){
            if(
                array_key_exists($resourceId, $resourceIDs) &&
                !in_array($characterId, $characterIds)
            ){
                $characterIds[] = $characterId;
            }
        }
        return $characterIds;
    }

    /**
     * @param $mapId
     * @return array
     */
    private function getCharacterIdsByMapId($mapId){
        $characterIds = [];

        if( !empty($this->subscriptions[$mapId]) ){
            $characterIds =  array_values( (array)$this->subscriptions[$mapId]);
        }

        return $characterIds;
    }

    /**
     * get connection objects by characterIds
     * @param int[] $characterIds
     * @return \SplObjectStorage
     */
    private function getConnectionsByCharacterIds($characterIds){
        $connections = new \SplObjectStorage;

        foreach($characterIds as $characterId){
            if($charConnections = (array)$this->characters[$characterId] ){
                foreach($charConnections as $conn){
                    if( !$connections->contains($conn) ){
                        $connections->attach($conn);
                    }
                }
            }
        }

        return $connections;
    }

    /**
     * check character access against $this->characterAccessData whitelist
     * @param $characterId
     * @param $characterToken
     * @return bool
     */
    private function checkCharacterAccess($characterId, $characterToken){
        $access = false;
        if( !empty($characterAccessData = (array)$this->characterAccessData[$characterId]) ){
            foreach($characterAccessData as $i => $data){
                $deleteToken = false;
                // check expire for $this->characterAccessData -> check ALL characters and remove expired
                if( ((int)$data['expire'] - time()) > 0 ){
                    // still valid -> check token
                    if($characterToken === $data['token']){
                        $access = true;
                        $deleteToken = true;
                    }
                }else{
                    // token expired
                    $deleteToken = true;
                }

                if($deleteToken){
                    unset($this->characterAccessData[$characterId][$i]);
                    // -> check if tokens for this charId is empty
                    if( empty($this->characterAccessData[$characterId]) ){
                        unset($this->characterAccessData[$characterId]);

                    }
                }
            }
        }
        return $access;
    }

    /**
     * check map access against $this->mapAccessData whitelist
     * @param $characterId
     * @param $mapId
     * @param $mapToken
     * @return bool
     */
    private function checkMapAccess($characterId, $mapId, $mapToken){
        $access = false;
        if( !empty($mapAccessData = (array)$this->mapAccessData[$mapId][$characterId]) ){
            foreach($mapAccessData as $i => $data){
                $deleteToken = false;
                // check expire for $this->mapAccessData -> check ALL characters and remove expired
                if( ((int)$data['expire'] - time()) > 0 ){
                    // still valid -> check token
                    if($mapToken === $data['token']){
                        $access = true;
                        $deleteToken = true;
                    }
                }else{
                    // token expired
                    $deleteToken = true;
                }

                if($deleteToken){
                    unset($this->mapAccessData[$mapId][$characterId][$i]);
                    // -> check if tokens for this charId is empty
                    if( empty($this->mapAccessData[$mapId][$characterId]) ){
                        unset($this->mapAccessData[$mapId][$characterId]);
                        // -> check if map has no access tokens left for characters
                        if( empty($this->mapAccessData[$mapId]) ){
                            unset($this->mapAccessData[$mapId]);
                        }
                    }
                }
            }
        }
        return $access;
    }

    /**
     * broadcast data ($load) to $connections
     * @param ConnectionInterface[] | \SplObjectStorage $connections
     * @param $task
     * @param $load
     * @param int[] $characterIds optional, recipients (e.g if multiple browser tabs are open)
     */
    private function broadcastData($connections, $task, $load, $characterIds = []){
        $response = [
            'task' => $task,
            'characterIds' => $characterIds,
            'load' => $load
        ];

        foreach($connections as $conn){
            $conn->send( json_encode($response) );
        }
    }

    // custom calls ===============================================================================

    /**
     * receive data from TCP socket (main App)
     * -> send response back
     * @param $data
     */
    public function receiveData($data){
        $data = (array)json_decode($data, true);
        $load = $data['load'];
        $task = $data['task'];
        $response = false;

        switch($data['task']){
            case 'characterLogout':
                $response = $this->unSubscribeCharacterId($load);
                break;
            case 'mapConnectionAccess':
                $response = $this->setConnectionAccess($load);
                break;
            case 'mapAccess':
                $response = $this->setAccess($task, $load);
                break;
            case 'mapUpdate':
                $response = $this->broadcastMapUpdate($task, $load);
                break;
            case 'mapDeleted':
                $response = $this->deleteMapId($task, $load);
                break;
            case 'healthCheck':
                $this->healthCheckToken = (float)$load;
                $response = 'OK';
                break;
        }

        $this->internalSocket->send($response);
    }

    /**
     * @param string $task
     * @param array $mapData
     * @return int
     */
    private function broadcastMapUpdate($task, $mapData){
        $mapId = (int)$mapData['config']['id'];

        return $this->broadcastMapData($task, $mapId, $mapData);
    }

    /**
     * send map data to ALL connected clients
     * @param string $task
     * @param int $mapId
     * @param mixed $load
     * @return int
     */
    private function broadcastMapData($task, $mapId, $load){
        $characterIds = $this->getCharacterIdsByMapId($mapId);
        $connections = $this->getConnectionsByCharacterIds($characterIds);

        $this->broadcastData($connections, $task, $load, $characterIds);
        return count($connections);
    }

    /**
     * set/update map access for allowed characterIds
     * @param string $task
     * @param array $accessData
     * @return int count of connected characters
     */
    private function setAccess($task, $accessData){
        $NewMapCharacterIds = [];

        if($mapId = (int)$accessData['id']){
            $characterIds = (array)$accessData['characterIds'];
            $currentMapCharacterIds = array_values((array)$this->subscriptions[$mapId]);

            // check all charactersIds that have access... ----------------------------------------
            foreach($characterIds as $characterId){
                // ... for it least ONE active connection ...
                if( !empty($this->characters[$characterId]) ){
                    // ... add characterId to new subscriptions for a map
                    $NewMapCharacterIds[$characterId] = $characterId;
                }
            }

            // broadcast "map delete" to no longer valid characters -------------------------------
            $removedMapCharacterIds = array_diff($currentMapCharacterIds, array_values($NewMapCharacterIds) );
            $removedMapCharacterConnections = $this->getConnectionsByCharacterIds($removedMapCharacterIds);
            $this->broadcastData($removedMapCharacterConnections, $task, $mapId, $removedMapCharacterIds);

            // update map subscriptions -----------------------------------------------------------
            if( !empty($NewMapCharacterIds) ){
                // set new characters that have map access (overwrites existing subscriptions for that map)
                $this->subscriptions[$mapId] = $NewMapCharacterIds;
            }else{
                // no characters (left) on this map
                unset($this->subscriptions[$mapId]);
            }
        }
        return count($NewMapCharacterIds);
    }

    /**
     * set map access data (whitelist) tokens for map access
     * @param $connectionAccessData
     * @return bool
     */
    private function setConnectionAccess($connectionAccessData) {
        $response = false;
        $characterId = (int)$connectionAccessData['id'];
        $characterToken = $connectionAccessData['token'];

        if(
            $characterId &&
            $characterToken
        ){
            // expire time for character and map tokens
            $expireTime = time() + $this->mapAccessExpireSeconds;

            // tokens for character access
            $this->characterAccessData[$characterId][] = [
                'token' => $characterToken,
                'expire' => $expireTime
            ];

            foreach((array)$connectionAccessData['mapData'] as $mapData){
                $mapId = (int)$mapData['id'];

                $this->mapAccessData[$mapId][$characterId][] = [
                    'token' => $mapData['token'],
                    'expire' => $expireTime
                ];
            }

            $response = 'OK';
        }

        return $response;
    }


    // logging ====================================================================================

    /**
     * outputs a custom log text
     * -> The output can be written to a *.log file if running the webSocket server (cmd.php) as a service
     * @param $text
     */
    protected function log($text){
        $text = date('Y-m-d H:i:s') . ' ' . $text;
        echo $text . "\n";

        $this->debug();
    }

    protected function debug(){
        if( $this->debug ){
            $mapId = 1;
            $characterId = 1946320202;

            $subscriptions = $this->subscriptions[$mapId];
            $connectionsForChar = count($this->characters[$characterId]);
            $mapAccessData = $this->mapAccessData[$mapId][$characterId];
            echo "\n" . "========== START ==========" . "\n";

            echo "-> characterAccessData: " . "\n";
            var_dump( $this->characterAccessData );

            echo "\n" . "-> Subscriptions mapId: " . $mapId . " " . "\n";
            var_dump($subscriptions);

            echo "\n" . "-> connectionsForChar characterId: " . $characterId . " count: " .  $connectionsForChar . " " . "\n";

            echo "-> mapAccessData: " . "\n";
            var_dump($mapAccessData);

            echo "\n" . "========== END ==========" . "\n";
        }

    }

}