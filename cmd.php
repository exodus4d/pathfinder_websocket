<?php
require 'vendor/autoload.php';

use Exodus4D\Socket;

if(PHP_SAPI === 'cli'){
    // optional CLI params
    $options = getopt('', [
        'pf_listen_host:',
        'pf_listen_port:',
        'pf_host:',
        'pf_port:'
    ]);

    /**
     * WebSocket connection (for WebClients => Browser)
     * default WebSocket URI: ws://127.0.0.1:8020
     *
     * pf_client_ip '0.0.0.0' <-- any client can connect
     * pf_ws_port 8020 <-- any client can connect
     */
    $wsListenHost = (!empty($options['pf_listen_host'])) ? $options['pf_listen_host'] : '0.0.0.0' ;
    $wsListenPort = (!empty($options['pf_listen_port'])) ? (int)$options['pf_listen_port'] : 8020 ;

    $host = (!empty($options['pf_host'])) ? $options['pf_host'] : '127.0.0.1' ;
    $port = (!empty($options['pf_port'])) ? (int)$options['pf_port'] : 5555 ;

    $dns = 'tcp://' . $host . ':' . $port;

    new Socket\WebSockets($dns, $wsListenPort, $wsListenHost);
}else{
    echo "Script need to be called by CLI!";
}



