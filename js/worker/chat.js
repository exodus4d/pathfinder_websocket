'use strict';
self.importScripts('message.js');

var socket = new WebSocket(self.name);
var ports = [];
var notifications = false;
var tmp = self;
console.log(socket._socket);

self.addEventListener('connect', function (event) {
    var port = event.ports[0];
    ports.push(port);
console.log('B: ' + socket.readyState);
    port.onmessage = function (event) {
        let load = event.data;
        load.__proto__ = msgWorker.prototype;

console.log('C: ' + socket.readyState);

        switch(load.command){
            case 'send':
                socket.send(JSON.stringify(load.data()));
                break;
            case 'WS_close':
                closeSocket(socket);
                break;
            case 'notify':
                notifications = load.data().status;
        }
    };


     if(socket.readyState === socket.OPEN){
         var msgWorkerOpen = new msgWorker('ready');
         port.postMessage(msgWorkerOpen);
     }

    port.start();
}, false);


socket.onopen = function(e){

    var msgWorkerOpen = new msgWorker('open');
    for (var i = 0; i < ports.length; i++) {
        ports[i].postMessage(msgWorkerOpen);
    }

    socket.onmessage = function(e){
        let load = JSON.parse(e.data);
        var msgWorkerSend = new msgWorker('send');
        msgWorkerSend.data(load);

        for (var i = 0; i < ports.length; i++) {
            ports[i].postMessage(msgWorkerSend);
        }

        if(notifications){
            new Notification('Message: ' + load.text);
        }
    };

    socket.onclose = function(){

        console.log(this.remoteAddress);
        console.info('ws: onclose()');
    };

    socket.onerror = function(){
        console.error('ws: onerror()');
    };

};

// Util ================================================================
var closeSocket = function(socket){
    // only close if active
    if(socket.readyState === socket.OPEN){
        // send "close" event before close call
        var msgWorkerWsClosed = new msgWorker('WS_closed');
        for (var i = 0; i < ports.length; i++) {
            ports[i].postMessage(msgWorkerWsClosed);
        }

        socket.close();
    }
};
