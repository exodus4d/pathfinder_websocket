var msgWorker = class MsgWorkerTest {
    constructor(cmd){
        this.cmd = cmd;
        this.msgBody = null;
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
