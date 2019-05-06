<?php
/**
 * Created by PhpStorm.
 * User: Exodus
 * Date: 02.12.2016
 * Time: 22:29
 */

namespace Exodus4D\Socket\Main;

use Exodus4D\Socket\Main\Handler\LogFileHandler;
use Exodus4D\Socket\Main\Formatter\SubscriptionFormatter;
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
     * [
     *      'charId_1' => [
     *          [
     *              'token' => $characterToken1,
     *              'expire' => $expireTime1,
     *              'characterData' => $characterData1
     *          ],
     *          [
     *              'token' => $characterToken2,
     *              'expire' => $expireTime2,
     *              'characterData' => $characterData1
     *          ]
     *      ],
     *      'charId_2' => [
     *          [
     *              'token' => $characterToken3,
     *              'expire' => $expireTime3,
     *               'characterData' => $characterData2
     *          ]
     *      ]
     * ]
     * @var array
     */
    protected $characterAccessData;

    /**
     * access tokens for clients grouped by mapId
     * -> tokens are unique and expire onSubscribe!
     * @var array
     */
    protected $mapAccessData;

    /**
     * connected characters
     * [
     *      'charId_1' => [
     *          '$conn1->resourceId' => $conn1,
     *          '$conn2->resourceId' => $conn2
     *      ],
     *      'charId_2' => [
     *          '$conn1->resourceId' => $conn1,
     *          '$conn3->resourceId' => $conn3
     *      ]
     * ]
     * @var array
     */
    protected $characters;

    /**
     * valid client connections subscribed to maps
     * [
     *      'mapId_1' => [
     *          'charId_1' => $charId_1,
     *          'charId_2' => $charId_2
     *      ],
     *      'mapId_2' => [
     *          'charId_1' => $charId_1,
     *          'charId_3' => $charId_3
     *      ]
     * ]
     *
     * @var array
     */
    protected $subscriptions;

    /**
     * collection of characterData for valid subscriptions
     * [
     *      'charId_1' => $characterData1,
     *      'charId_2' => $characterData2
     * ]
     *
     * @var array
     */
    protected $characterData;

    /**
     * enable debug output
     * -> check debug() for more information
     * @var bool
     */
    protected $debug = false;

    public function __construct() {
        $this->characterAccessData = [];
        $this->mapAccessData = [];
        $this->characters = [];
        $this->subscriptions = [];
        $this->characterData = [];

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
            (int)$token === (int)$this->healthCheckToken
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
            // check if character access token is valid (exists and not expired in $this->characterAccessData)
            if($characterData = $this->checkCharacterAccess($characterId, $characterToken)){
                $this->characters[$characterId][$conn->resourceId] = $conn;

                // insert/update characterData cache
                // -> even if characterId does not have access to a map "yet"
                // -> no maps found but character can get map access at any time later
                $this->setCharacterData($characterData);

                // valid character -> check map access
                $changedSubscriptionsMapIds = [];
                foreach((array)$subscribeData['mapData'] as $data){
                    $mapId = (int)$data['id'];
                    $mapToken = $data['token'];

                    if($mapId && $mapToken){
                        // check if token is valid (exists and not expired) in $this->mapAccessData
                        if( $this->checkMapAccess($characterId, $mapId, $mapToken) ){
                            // valid map subscribe request
                            $this->subscriptions[$mapId][$characterId] = $characterId;
                            $changedSubscriptionsMapIds[] = $mapId;
                        }
                    }
                }

                // broadcast all active subscriptions to subscribed connections -------------------------------------------
                $this->broadcastMapSubscriptions('mapSubscriptions', $changedSubscriptionsMapIds);
            }
        }
    }

    /**
     * subscribes an active connection from maps
     * @param ConnectionInterface $conn
     */
    private function unSubscribeConnection(ConnectionInterface $conn){
        $characterIds = $this->getCharacterIdsByConnection($conn);
        $this->unSubscribeCharacterIds($characterIds, $conn);
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
            // unSub from $this->characters ---------------------------------------------------------------------------
            if($conn){
                // just unSub a specific connection (e.g. single browser window)
                unset($this->characters[$characterId][$conn->resourceId]);

                if( !count($this->characters[$characterId]) ){
                    // no connection left for this character
                    unset($this->characters[$characterId]);
                }
                // TODO unset $this->>$characterData if $characterid does not have any other map subscribed to
            }else{
                // unSub ALL connections from a character (e.g. multiple browsers)
                unset($this->characters[$characterId]);

                // unset characterData cache
                $this->deleteCharacterData($characterId);
            }

            // unSub from $this->subscriptions ------------------------------------------------------------------------
            $changedSubscriptionsMapIds = [];
            foreach($this->subscriptions as $mapId => $characterIds){
                if(array_key_exists($characterId, $characterIds)){
                    unset($this->subscriptions[$mapId][$characterId]);

                    if( !count($this->subscriptions[$mapId]) ){
                        // no characters left on this map
                        unset($this->subscriptions[$mapId]);
                    }

                    $changedSubscriptionsMapIds[] = $mapId;
                }
            }

            // broadcast all active subscriptions to subscribed connections -------------------------------------------
            $this->broadcastMapSubscriptions('mapSubscriptions', $changedSubscriptionsMapIds);
        }

        return true;
    }

    /**
     * unSubscribe $characterIds from ALL maps
     * -> if $conn is set -> just unSub the $characterId from this $conn
     * @param array $characterIds
     * @param null $conn
     * @return bool
     */
    private function unSubscribeCharacterIds(array $characterIds, $conn = null): bool{
        $response = false;
        foreach($characterIds as $characterId){
            $response = $this->unSubscribeCharacterId($characterId, $conn);
        }
        return $response;
    }

    /**
     * delete mapId from subscriptions and broadcast "delete msg" to clients
     * @param string $task
     * @param int $mapId
     * @return int
     */
    private function deleteMapId(string $task, $mapId){
        $connectionCount =  $this->broadcastMapData($task, $mapId, $mapId);

        // remove map from subscriptions
        if( isset($this->subscriptions[$mapId]) ){
            unset($this->subscriptions[$mapId]);
        }

        return $connectionCount;
    }

    /**
     * get all mapIds a characterId has subscribed to
     * @param int $characterId
     * @return array
     */
    private function getMapIdsByCharacterId(int $characterId) : array {
        $mapIds = [];
        foreach($this->subscriptions as $mapId => $characterIds){
            if(array_key_exists($characterId, $characterIds)){
                $mapIds[] = $mapId;
            }
        }
        return $mapIds;
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
    private function getCharacterIdsByMapId(int $mapId) : array {
        $characterIds = [];
        if(
            array_key_exists($mapId, $this->subscriptions) &&
            is_array($this->subscriptions[$mapId])
        ){
            $characterIds = array_keys($this->subscriptions[$mapId]);
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
     * @return array
     */
    private function checkCharacterAccess($characterId, $characterToken) : array {
        $characterData = [];
        if( !empty($characterAccessData = (array)$this->characterAccessData[$characterId]) ){
            foreach($characterAccessData as $i => $data){
                $deleteToken = false;
                // check expire for $this->characterAccessData -> check ALL characters and remove expired
                if( ((int)$data['expire'] - time()) > 0 ){
                    // still valid -> check token
                    if($characterToken === $data['token']){
                        $characterData = $data['characterData'];
                        $deleteToken = true;
                        // NO break; here -> check other characterAccessData as well
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
        return $characterData;
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
    private function broadcastData($connections, string $task, $load, array $characterIds = []){
        $response = [
            'task' => $task,
            'characterIds' => $characterIds,
            'load' => $load
        ];

        foreach($connections as $conn){
            $conn->send( json_encode($response) );
        }
    }

    // custom calls ===================================================================================================

    /**
     * receive data from TCP socket (main App)
     * -> send response back
     * @param string $task
     * @param null $load
     * @return bool|float|int|null
     */
    public function receiveData(string $task, $load = null){
        $responseLoad = null;

        switch($task){
            case 'healthCheck':
                $this->healthCheckToken = (float)$load;
                $responseLoad = $this->healthCheckToken;
                break;
            case 'characterUpdate':
                $this->updateCharacterData($load);
                $mapIds = $this->getMapIdsByCharacterId((int)$load['id']);
                $this->broadcastMapSubscriptions('mapSubscriptions', $mapIds);
                break;
            case 'characterLogout':
                $responseLoad = $this->unSubscribeCharacterIds($load);
                break;
            case 'mapConnectionAccess':
                $responseLoad = $this->setConnectionAccess($load);
                break;
            case 'mapAccess':
                $responseLoad = $this->setAccess($task, $load);
                break;
            case 'mapUpdate':
                $responseLoad = $this->broadcastMapUpdate($task, $load);
                break;
            case 'mapDeleted':
                $responseLoad = $this->deleteMapId($task, $load);
                break;
            case 'logData':
                $this->handleLogData((array)$load['meta'], (array)$load['log']);
                break;
        }

        return $responseLoad;
    }

    private function setCharacterData(array $characterData){
        $characterId = (int)$characterData['id'];
        if($characterId){
            $this->characterData[$characterId] = $characterData;
        }
    }

    private function getCharacterData(int $characterId) : array {
        return empty($this->characterData[$characterId]) ? [] : $this->characterData[$characterId];
    }

    private function getCharactersData(array $characterIds) : array {
        return array_filter($this->characterData, function($characterId) use($characterIds) {
            return in_array($characterId, $characterIds);
        }, ARRAY_FILTER_USE_KEY);
    }

    private function updateCharacterData(array $characterData){
        $characterId = (int)$characterData['id'];
        if($this->getCharacterData($characterId)){
            $this->setCharacterData($characterData);
        }
    }

    private function deleteCharacterData(int $characterId){
        unset($this->characterData[$characterId]);
    }

    /**
     * @param string $task
     * @param array $mapIds
     */
    private function broadcastMapSubscriptions(string $task, array $mapIds){
        $mapIds = array_unique($mapIds);

        foreach($mapIds as $mapId){
            if(
                !empty($characterIds = $this->getCharacterIdsByMapId($mapId)) &&
                !empty($charactersData = $this->getCharactersData($characterIds))
            ){
                $systems = SubscriptionFormatter::groupCharactersDataBySystem($charactersData);

                $mapUserData = (object)[];
                $mapUserData->config = (object)['id' => $mapId];
                $mapUserData->data = (object)['systems' => $systems];

                $this->broadcastMapData($task, $mapId, $mapUserData);
            }
        }
    }

    /**
     * @param string $task
     * @param array $mapData
     * @return int
     */
    private function broadcastMapUpdate(string $task, $mapData){
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
    private function broadcastMapData(string $task, int $mapId, $load) : int {
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
    private function setAccess(string $task, $accessData) : int {
        $newMapCharacterIds = [];

        if($mapId = (int)$accessData['id']){
            $characterIds = (array)$accessData['characterIds'];
            // check all charactersIds that have map access... --------------------------------------------------------
            foreach($characterIds as $characterId){
                // ... for at least ONE active connection ...
                // ... and characterData cache exists for characterId
                if(
                    !empty($this->characters[$characterId]) &&
                    !empty($this->getCharacterData($characterId))
                ){
                    $newMapCharacterIds[$characterId] = $characterId;
                }
            }

            $currentMapCharacterIds = (array)$this->subscriptions[$mapId];

            // broadcast "map delete" to no longer valid characters ---------------------------------------------------
            $removedMapCharacterIds = array_keys(array_diff_key($currentMapCharacterIds, $newMapCharacterIds));
            $removedMapCharacterConnections = $this->getConnectionsByCharacterIds($removedMapCharacterIds);
            $this->broadcastData($removedMapCharacterConnections, $task, $mapId, $removedMapCharacterIds);

            // update map subscriptions -------------------------------------------------------------------------------
            if( !empty($newMapCharacterIds) ){
                // set new characters that have map access (overwrites existing subscriptions for that map)
                $this->subscriptions[$mapId] = $newMapCharacterIds;

                // check if subscriptions have changed
                if( !$this->arraysEqualKeys($currentMapCharacterIds, $newMapCharacterIds) ){
                    $this->broadcastMapSubscriptions('mapSubscriptions', [$mapId]);
                }
            }else{
                // no characters (left) on this map
                unset($this->subscriptions[$mapId]);
            }
        }
        return count($newMapCharacterIds);
    }

    /**
     * set map access data (whitelist) tokens for map access
     * @param $connectionAccessData
     * @return bool
     */
    private function setConnectionAccess($connectionAccessData) {
        $response = false;
        $characterId = (int)$connectionAccessData['id'];
        $characterData = $connectionAccessData['characterData'];
        $characterToken = $connectionAccessData['token'];

        if(
            $characterId &&
            $characterData &&
            $characterToken
        ){
            // expire time for character and map tokens
            $expireTime = time() + $this->mapAccessExpireSeconds;

            // tokens for character access
            $this->characterAccessData[$characterId][] = [
                'token' => $characterToken,
                'expire' => $expireTime,
                'characterData' => $characterData
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

    /**
     * compare two assoc arrays by keys. Key order is ignored
     * -> if all keys from array1 exist in array2 && all keys from array2 exist in array 1, arrays are supposed to be equal
     * @param array $array1
     * @param array $array2
     * @return bool
     */
    protected function arraysEqualKeys(array $array1, array $array2) : bool {
        return !array_diff_key($array1, $array2) && !array_diff_key($array2, $array1);
    }

    /**
     * dispatch log writing to a LogFileHandler
     * @param array $meta
     * @param array $log
     */
    private function handleLogData(array $meta, array $log){
        $logHandler = new LogFileHandler((string)$meta['stream']);
        $logHandler->write($log);
    }


    // logging ========================================================================================================

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