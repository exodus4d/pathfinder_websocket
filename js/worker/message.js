var msgWorker = class MessageWorker {

    constructor(cmd){
        // message properties
        this.cmd = cmd;
        this.msgBody = null;

        // webSocket  props
        this.ws = {
            url: undefined,
            readyState: undefined,
        };
    }

    set socket(socket){
        this.ws.url = socket.url;
        this.ws.readyState = socket.readyState;
    }

    get socket(){
        return this.ws;
    }

    get command(){
        return this.cmd;
    }


    data(data) {
        if(data){
            this.msgBody = data;
        }

        return this.msgBody;
    }
};
