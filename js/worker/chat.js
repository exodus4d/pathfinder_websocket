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
            msgWorkerOpen.socket = this;

            ports[ports.length - 1].postMessage(msgWorkerOpen);

            socket.onmessage = function(e){
                let load = JSON.parse(e.data);

                let msgWorkerSend = new msgWorker('ws:send');
                msgWorkerSend.socket = this;

                msgWorkerSend.data(load);

                for (let i = 0; i < ports.length; i++) {
                    ports[i].postMessage(msgWorkerSend);
                }

                if(notifications){
                    new Notification('Message: ' + load.text);
                }
            };

            socket.onclose = function(){
                let msgWorkerWsClosed = new msgWorker('ws:closed');
                msgWorkerWsClosed.socket = this;

                console.log(socket.readyState);
                for (let i = 0; i < ports.length; i++) {
                    ports[i].postMessage(msgWorkerWsClosed);
                }
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
    let port = event.ports[0];
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
                closeSocket();
                break;
            case 'ws:notify':
                notifications = load.data().status;
                break;
        }
    }, false);

    port.start();
}, false);


// Util ================================================================
var closeSocket = function(){
    // only close if active
    if(socket.readyState === socket.OPEN){
        socket.close();
    }
};
