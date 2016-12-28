### [WIP] WebSocket server for [Pathfinder](https://github.com/exodus4d/pathfinder)

####Install:
1. Install [Composer](https://getcomposer.org/download/)
2. Install Composer dependencies from `composer.json` file:
    - `php composer.phar install` (edit composer.phar path to your Composer installation)
3. Start WebSocket server `php cmd.php` 

####Default Configuration
**Clients (WebBrowser) listen for connections**
- Host:`0.0.0.0.` (=> any client can connect)
- Port:`8020`
- URI:`127.0.0.1:8020` (Your WebServer (e.g. Nginx) should pass all WebSocket connections to this source)

**TCP Socket connection (Internal use fore WebServer <=> WebSocket communication)**
- Host:`127.0.0.1` (=> Assumed WebServer and WebSocket Server running on the same machine)
- Port:`5555`
- URI: `tcp://127.0.0.1:5555`

**[Optional]**
The default configuration should be fine for most installations. 
You can change/overwrite the default **Host** and **Port** configuration by adding additional CLI parameters when starting the WebSocket server:

`php cmd.php --pf_listen_host [CLIENTS_HOST] --pf_listen_port [CLIENTS_PORT] --pf_host [TCP_HOST] --pf_port [TCP_PORT]`

####Info:
- [*Ratchet*](http://socketo.me/) - "WebSockets for PHP"
