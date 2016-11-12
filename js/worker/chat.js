'use strict';
self.importScripts('message.js');

var socket = null;
var ports = [];
var notifications = false;

var initSocket = function(uri){
    var msgWorkerOpen = new msgWorker('ws:open');

    if(socket === null){
        socket = new WebSocket(uri);

        socket.onopen = function(e){

            //
            ports[ports.length - 1].postMessage(msgWorkerOpen);

            socket.onmessage = function(e){

                let load = JSON.parse(e.data);
                var msgWorkerSend = new msgWorker('ws:send');
                msgWorkerSend.data(load);

                for (var i = 0; i < ports.length; i++) {
                    ports[i].postMessage(msgWorkerSend);
                }

                if(notifications){
                    new Notification('Message: ' + load.text);
                }
            };

            socket.onclose = function(){
                console.info('ws: onclose()');
            };

            socket.onerror = function(){
                console.error('ws: onerror()');
            };
        }
    }else{
        // socket still open
        ports[ports.length - 1].postMessage(msgWorkerOpen);
    }
};

self.addEventListener('connect', function (event){
    var port = event.ports[0];
    ports.push(port);

    port.addEventListener('message', function (e){
        let load = e.data;
        load.__proto__ = msgWorker.prototype;

        switch(load.command){
            case 'ws:init':
                initSocket(load.data().uri);
                break;
            case 'ws:send':
                socket.send(JSON.stringify(load.data()));
                break;
            case 'ws:close':
                closeSocket(socket);
                break;
            case 'ws:notify':
                notifications = load.data().status;
                break;
        }
    }, false);

    port.start();
}, false);



// Util ================================================================
var closeSocket = function(socket){
    // only close if active
    console.log(socket.readyState + ' - ' + socket.OPEN);
    if(socket.readyState === socket.OPEN){
        // send "close" event before close call
        var msgWorkerWsClosed = new msgWorker('ws:closed');
        for (var i = 0; i < ports.length; i++) {
            ports[i].postMessage(msgWorkerWsClosed);
        }

        socket.close();
    }
};
