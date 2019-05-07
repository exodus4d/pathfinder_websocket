## WebSocket server for [Pathfinder](https://github.com/exodus4d/pathfinder)

### Requirements
- A working instance of *[Pathfinder](https://github.com/exodus4d/pathfinder)* **(≥ v1.2.0)**
- [_Composer_](https://getcomposer.org/download/) to install packages for the WebSocket server

### Install
1. Checkout this project in a **new** folder (NOT the install for _Pathfinder_ itself) e.g. `/var/www/websocket.pathfinder`
1. Install [_Composer_](https://getcomposer.org/download/)
2. Install Composer dependencies from `composer.json` file:
  - `$ cd /var/www/websocket.pathfinder`
  - `$ composer install`
3. Start WebSocket server `$ php cmd.php`
 
### Configuration

#### Default

**Clients (WebBrowser) listen for connections**
- Host: `0.0.0.0.` (=> any client can connect)
- Port: `8020`
- URI: `127.0.0.1:8020` (Your WebServer (e.g. Nginx) should pass all WebSocket connections to this source)

**TCP TcpSocket connection (Internal use for WebServer ⇄ WebSocket communication)**
- Host: `127.0.0.1` (=> Assumed WebServer and WebSocket Server running on the same machine)
- Port: `5555`
- URI: `tcp://127.0.0.1:5555`
 
#### Custom [Optional]

The default configuration should be fine for most installations. 
You can change/overwrite the default **Host** and **Port** configuration by adding additional CLI parameters when starting the WebSocket server:

`$ php cmd.php --pf_listen_host [CLIENTS_HOST] --pf_listen_port [CLIENTS_PORT] --pf_host [TCP_HOST] --pf_port [TCP_PORT]`
 
### Unix Service (systemd)

#### New Service
It is recommended to wrap the `cmd.php` script in a Unix service, that over control the WebSocket server.
This creates a systemd service on CentOS7:
1. `$ cd /etc/systemd/system`
2. `$ vi websocket.pathfinder.service`
3. Copy script and adjust `ExecStart` and `WorkingDirectory` values:

```
[Unit]
Description = WebSocket server (Pathfinder) [LIVE] environment
After = multi-user.target

[Service]
Type = idle
ExecStart = /usr/bin/php /var/www/websocket.pathfinder/pathfinder_websocket/cmd.php
WorkingDirectory = /var/www/websocket.pathfinder/pathfinder_websocket
TimeoutStopSec = 0
Restart = always
LimitNOFILE = 10000
Nice = 10

[Install]
WantedBy = multi-user.target
```

Now you can use the service to start/stop/restart your WebSocket server
- `$ systemctl start websocket.pathfinder.service`
- `$ systemctl restart websocket.pathfinder.service`
- `$ systemctl stop websocket.pathfinder.service`

#### Auto-Restart the Service
You can automatically restart your service (e.g. on _EVE-Online_ downtime). Create a new "timer" for the automatic restart.
1. `$ cd /etc/systemd/system` (same dir as before)
2. `$ vi restart.websocket.pathfinder.timer`
3. Copy script:

```
[Unit]
Description = Restart timer (EVE downtime) for WebSocket server [LIVE]

[Timer]
OnCalendar = *-*-* 12:01:00
Persistent = true

[Install]
WantedBy = timer.target
```
Now we need a new "restart service" for the timer:
1. `$ cd /etc/systemd/system` (same dir as before)
2. `$ vi restart.websocket.pathfinder.service`
3. Copy script:

```
[Unit]
Description = Restart (periodically)  WebSocket server [LIVE]

[Service]
Type = oneshot
ExecStart = /usr/bin/systemctl try-restart websocket.pathfinder.service
```

### Info
- [*Ratchet*](http://socketo.me/) - "WebSockets for PHP"
