var domain = location.host.replace('www.','');
var workerProtocol = (window.location.protocol === 'https:') ? 'wss:' : 'ws:';
var workerUri = workerProtocol + '//' + domain + ':8080';

window.onload = function (){

	var msgWorker = this.msgWorker;

	var worker = new SharedWorker('js/worker/chat.js', 'worker_name');

	worker.port.addEventListener('message', function(e){
		let load = e.data;
		load.__proto__ = msgWorker.prototype;

		switch(load.command){
			case 'ws:open':
				// WebSocket in SharedWorker is open
				setSocketStatus(true);
				initChat();
				break;
			case 'ws:send':
				updateMessages(load.data());
				break;
			case 'ws:closed':
				setSocketStatus(false);
				break;
		}

		// show webSocket info
		console.info(load.socket);
	}, false);

	worker.onerror = function(e){
		console.error('SharedWorker onerror:');
	};

	worker.port.start();

	var msgWorkerInit = new msgWorker('ws:init');
	msgWorkerInit.data({
		uri: workerUri
	});
	worker.port.postMessage(msgWorkerInit);


	// Chat init ==================================================================================
	var user;
	var messages = [];

	var messages_template = Handlebars.compile($('#messages-template').html());

	var initChat = function(){

		$('#join-chat').click(function(){
			user = $('#user').val();
			$('#user-container').addClass('hidden');
			$('#main-container').removeClass('hidden');

			var msgWorkerSend = new msgWorker('ws:send');
			msgWorkerSend.data({
				'user': user,
				'text': user + ' entered the room',
				'time': moment().format('hh:mm a')
			});

			worker.port.postMessage(msgWorkerSend);

			$('#user').val('');
		});

		$('#send-msg').click(function(){
			var text = $('#msg').val();

			var msgWorkerSend = new msgWorker('ws:send');
			msgWorkerSend.data({
				'user': user,
				'text': text,
				'time': moment().format('hh:mm a')
			});

			worker.port.postMessage(msgWorkerSend);

			$('#msg').val('');
		});

		$('#leave-room').click(function(){
			var msgWorkerSend = new msgWorker('ws:send');
			msgWorkerSend.data({
				'user': user,
				'text': user + ' has left the room',
				'time': moment().format('hh:mm a')
			});

			worker.port.postMessage(msgWorkerSend);

			$('#messages').html('');
			messages = [];

			$('#main-container').addClass('hidden');
			$('#user-container').removeClass('hidden');


		});
	};

	var setSocketStatus = function(status){
		$('#socket-status').toggleClass('red', !status).toggleClass('green', status);
	};

	var updateMessages = function(msg){
		messages.push(msg);
		var messages_html = messages_template({'messages': messages});
		$('#messages').html(messages_html);
		$("#messages").animate({ scrollTop: $('#messages')[0].scrollHeight}, 1000);
	};

	// Notification init ==========================================================================

	var updateNotification = function(status){
		$('#notification-status').toggleClass('red', !status).toggleClass('green', status);
	};

	var notifyMe = function(){
		var msgWorkerNotify = new msgWorker('ws:notify');

		if (Notification.permission === 'granted'){
			msgWorkerNotify.data({
				status: true
			});
			worker.port.postMessage(msgWorkerNotify);

			updateNotification(true);
		}else{
			Notification.requestPermission(function (permission) {
				msgWorkerNotify.data({
					status: permission === 'granted'
				});
				worker.port.postMessage(msgWorkerNotify);

				updateNotification(permission === 'granted');
			});
		}
	};

	$('#toggle-notification').on('click', notifyMe);

	// ============================================================================================
	/*
	window.onbeforeunload = function() {
		var msgWorkerClose = new msgWorker('ws:close');
		worker.port.postMessage(msgWorkerClose);

		//console.log('test close');
		//worker.port.close();

		return 'sdf';
	};
	*/
};



