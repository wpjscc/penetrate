<?php

require __DIR__ . '/vendor/autoload.php';

use Swlib\SaberGM;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Coroutine\Http\Server;
use function Swoole\Coroutine\run;
use function Swoole\Coroutine\go;
use Swoole\Coroutine\Client;
use Swoole\WebSocket\Frame;
use function Swlib\Http\parse_request;
use Swoole\Coroutine as Co;



// $config = require_once __DIR__ . '/client_config.php';
if(file_exists(__DIR__ . '/client_config_self.php')){
    $config = require_once __DIR__ . '/client_config_self.php';
}else{
    $config = require_once __DIR__ . '/client_config.php';

}


ini_set('date.timezone', 'Asia/Shanghai');

global $tunnelWsObjects;
global $httpToTunnelWs;
global $myClient;
global $myClientProxy;

global $wsObjects;
global $wsObjectConfigs;

$wsObjectConfigs=[];
$wsObjects=[];
$tunnelWsObjects=[];
$httpObjects = [];
//监听状态
global $httpStatusToServerClient;
$httpStatusToServerClient=[];
$serverWsObjects=[];


$wsObjectConfigs=$config;

require_once __DIR__ . '/event_log.php';

class MyClient
{
    public $client_id;

    public function onMessage($ws, $message)
    {
        $message = json_decode($message, true);

        $event = data_get($message, 'event');
        $data = data_get($message, 'data');

        if ($event&&$data) {
            if (method_exists($this, $event)) {
                call_user_func([$this,$event], $ws, $data);
            }
        }
    }

    public function system($ws, $message)
    {
        //todo 处理一些前置事件
        echo "Event:system\n";
        $event = data_get($message, 'event');
        $data = data_get($message, 'data');
        if ($event&&$data) {
            echo "Event:{$event}\n";
            if (method_exists($this, $event)) {
                call_user_func([$this,$event], $ws, $data);
            }
        }
        //todo 处理一些后置事件
    }

    public function setClientId($ws, $data)
    {
        $this->client_id = $data['client_id'];
        echo "client_id:".$this->client_id."\n";
    }

    /**
     * system->client(event)
     *
     * @param [type] $ws
     * @param [type] $data
     * @return void
     */
    public function sendConfig($ws, $data)
    {
        global $config;
        $ws->push(json_encode([
            'event'=>'client',
            'data' => [
                'event' =>'receiveConfig',
                'data'=>[
                    'client_id'=>$data['client_id'],
                    'configs' => $config['configs']
                ]
               
            ]
        ]));
    }

    /**
     * ws 是local(浏览器内的)
     *
     * @param [type] $ws
     * @param [type] $data
     * @return void
     */
    public function localRequest($ws, $data)
    {
        var_dump($data);
        $extra = data_get($data, 'extra', []);

        unset($data['extra']);
        $data = array_merge($data, $extra);

        global $myClientProxy;
        $myClientProxy->receiveRequest($ws, $data, true);
    }

    /**
     * system->client
     *
     * @param [type] $ws
     * @param [type] $data
     * @param integer $time
     * @return void
     */
    public function createProxy($ws, $data, $time=0)
    {
        // var_dump($data['request_id']);
        go(function () use ($ws, $data, $time) {
            global $myClientProxy;
            global $config;
            eventStart('MyClient', [
                'time' => microtime(true),
                'event' => 'createProxy',
                'uniqid' => $data['uniqid'],
                'content' => $data['host']
            ]);
            try {
                $websocket = SaberGM::websocket("ws://{$config['server_addr']}:{$config['server_port']}/tunnel");
            } catch (Exception $e) {
                //todo 记录exception
                echo "time:$time\n";
                if ($time>=3) {
                    eventFail('MyClient', [
                        'time' => microtime(true),
                        'event' => 'createProxy',
                        'uniqid' => $data['uniqid'],
                        'content' => $data['host']
                    ]);
                    $ws->push(json_encode([
                        'event'=>'client',
                        'data' => [
                            'event' =>'proxyError',
                            'data'=>[
                                'time'=> $time,
                                'uniqid'=>$data['uniqid'],
                                'request_id'=>$data['request_id'],
                                'content'=> $data['host']."proxy connect failed. Error:".$e->getMessage()
                            ]
                           
                        ]
                    ]));
                    return;
                }
                Co::sleep(2);
                $time++;
            
                $this->createProxy($ws, $data, $time);
                return;
            }

            //todo connect local_ip and local_port  $data 里有要链接的本地ip和端口

            echo "time:".microtime(true).'-'."createProxy:".$data['proxy_client_id']."\n";

            $objectId = spl_object_id($websocket);
            $websocket->objectId = $objectId;
            $websocket->proxy_client_id = $data['proxy_client_id'];
            $websocket->request_id = $data['request_id'];
            $tunnelWsObjects[$websocket->objectId] = $websocket;
            $httpToTunnelWs[$data['request_id']]=$websocket->objectId;
            
            $websocket->push(json_encode([
                'event'=>'client',
                'data' => [
                    'event' =>'proxyRequest',
                    'data'=>[
                        'request_id'=>$data['request_id'],
                        'proxy_client_id'=>$data['proxy_client_id'],
                        'local_port'=> $data['local_port'],
                        'local_ip'=> $data['local_ip'],
                        'uniqid' => $data['uniqid'],
                        'host' => $data['host'],
                    ]
                   
                ]
            ]));
            eventSuccess('MyClient', [
                'time' => microtime(true),
                'event' => 'createProxy',
                'uniqid' => $data['uniqid'],
                'content' => $data['host']
            ]);
            while (true) {
                if ($websocket->client->getStatusCode()>0) {
                    $data = $websocket->recv(2);
                    // echo 'AppClientProxy:recv: ' . $data . "\n";
                    // var_dump($data);
                    if ($data) {
                        $myClientProxy->onMessage($websocket, $data);
                    }
                } else {
                    echo "time:".microtime(true).'-'."ProxyClient:{$websocket->objectId}-{$websocket->request_id}-{$websocket->proxy_client_id}-close\n";
                    unset($tunnelWsObjects[$websocket->objectId]);

                    unset($httpToTunnelWs[$websocket->request_id]);
                    break;
                }
            }
        });
    }
}
class MyClientProxy
{
    public function onMessage($ws, $message)
    {
        $message = json_decode($message, true);

        $event = data_get($message, 'event');
        $data = data_get($message, 'data');

        if ($event&&$data) {
            if (method_exists($this, $event)) {
                call_user_func([$this,$event], $ws, $data);
            }
        }
    }

    public function system($ws, $message)
    {
        //todo 处理一些前置事件
        echo "ProxyEvent:system\n";
        $event = data_get($message, 'event');
        $data = data_get($message, 'data');
        if ($event&&$data) {
            echo "ProxyEvent:system:{$event}\n";
            if (method_exists($this, $event)) {
                call_user_func([$this,$event], $ws, $data);
            }
        }
        //todo 处理一些后置事件
    }

    /**
     * server->prosyClient(event)
     *
     * @param [type] $ws
     * @param [type] $message
     * @return void
     */
    public function receiveRequest($ws, $message, $local = false)
    {
        
        // var_dump($message['data']);
        go(function () use ($ws, $message, $local) {
            //todo connect local config
            // var_dump($message);
            //todo  http

            $httpObjects[$message['uniqid']] = 1;
            echo "time:".microtime(true).'-'."receiveRequest:".$message['proxy_client_id']."\n";
            $proxyRequest = parse_request(base64_decode($message['content']));
            $method = $proxyRequest->getMethod();
            $proxyRequest = $proxyRequest->withHeader('Host', $message['local_ip'].':'.$message['local_port']);
            
            $proxyRequest = parse_request((string)$proxyRequest);
            // var_dump((string)$proxyRequest);
            // exit();
            $uri = $proxyRequest->getUri();
           
            $headers = $proxyRequest->getHeaders();
            $params = $proxyRequest->getQueryParams();
            $body =  $proxyRequest->getBody();


            eventStart('MyClientProxy', [
                'time' => microtime(true),
                'event' => 'proxyRequest',
                'uniqid' => $message['uniqid'],
                'content' => (string)$proxyRequest,
                'extra'=>[
                    'local_ip'=>$message['local_ip'],
                    'local_port'=>$message['local_port'],
                    'proxy_client_id'=>$message['proxy_client_id'],
                    'host'=>$message['host'],
                ]
            ]);


        
            
            try {
                eventSuccess('MyClientProxy', [
                    'time' => microtime(true),
                    'event' => 'proxyRequest',
                    'uniqid' => $message['uniqid'],
                ]);
                eventStart('MyClientProxy', [
                    'time' => microtime(true),
                    'event' => 'proxyReponse',
                    'uniqid' => $message['uniqid'],
                ]);
                $request = SaberGM::psr([
                    'redirect'=>0,
                    // 'timeout' => 20
                ])->withMethod($method)
                ->withHeaders($headers)
                ->withUri($uri)
                ->withQueryParams($params)
                
                ->withBody($body);
                $response = $request->exec()->recv();
            } catch (Exception $e) {
                if (!$local) {
                    $ws->push(json_encode([
                        'event'=>'client',
                        'data' => [
                            'event' =>'proxyException',
                            'data'=>[
                                'request_id'=>$message['request_id'],
                                'uniqid'=>$message['uniqid'],
                                'content'=> "Local proxyException. Error:".$e->getMessage().':'.$uri
                            ]
                           
                        ]
                    ]));
                }
                
                eventFail('MyClientProxy', [
                    'time' => microtime(true),
                    'event' => 'proxyReponse',
                    'uniqid' => $message['uniqid'],
                    'content' => "Local proxyException. Error:".$e->getMessage()
                ]);
                unset($httpObjects[$message['uniqid']]);
                return ;
            }
            
           
            $cks = $response->cookies->toResponse();

            if (!empty($cks)) {
                $response->withoutHeader('Set-Cookie');
            }
            foreach ($cks as $ck) {
                $response->withAddedHeader('Set-Cookie', str_replace($message['local_ip'], $message['host'], $ck));
            }

            if ($response->getStatusCode() == 302) {
            } else {
            }
            
            if (!empty($response->getRedirectHeaders())) {
            }
            if ($method == 'POST') {
                if ($response->getStatusCode()==200) {
                }
            }

            if ($response->hasHeader('set-cookie')) {
            }

            
            try {
                if (!$local) {
                    $ws->push(json_encode([
                    'event' => 'client',
                    'data'=>[
                        'event' =>'proxyReponse',
                        'data'=>[
                            'content'=> base64_encode((string)$response),
                            'uniqid' => $message['uniqid'],
                            'host' => $message['host']
                        ]
                    ]
                ]));
                }
                
                eventSuccess('MyClientProxy', [
                    'time' => microtime(true),
                    'event' => 'proxyReponse',
                    'uniqid' => $message['uniqid'],
                    'content' => (string)$response
                ]);
                unset($httpObjects[$message['uniqid']]);
            } catch (Exception $e) {
                unset($httpObjects[$message['uniqid']]);
                eventFail('MyClientProxy', [
                    'time' => microtime(true),
                    'event' => 'proxyReponse',
                    'uniqid' => $message['uniqid'],
                    'content' => 'error: '.$e->getMessage()
                ]);
                echo 'error: '.$e->getMessage();
            }
            
            echo "time:".microtime(true).'-'."proxyReponse:".$message['proxy_client_id']."\n";

            // var_dump(33333);
            if (!$local) {
                $ws->close();
            }
            //  exit();

            return ;



        });
    }
}

$myClient = new MyClient();
$myClientProxy = new MyClientProxy();

run(function () {
    Swoole\Timer::tick(5000, function () {
        echo '运行内存：'.round(memory_get_usage()/1024/1024, 2)."MB\n";
    });
    go(function () {
        global $httpObjects;
        global $wsObjects;
        global $wsObjectConfigs;
        global $tunnelWsObjects;
        global $serverWsObjects;
        global $httpStatusToServerClient;
        
        while (true) {
            if (empty($serverWsObjects)) {
                $httpStatusToServerClient = [];
            } else {
                foreach ($serverWsObjects as $serverWs) {
                    $serverWs->push(json_encode([
                            'event' => 'system',
                            'data' => [
                                'event' => 'getStatus',
                                'data' => [
                                    'http_count'=> count($httpObjects),
                                    'client_count'=> count($wsObjects),
                                    'server_client_count'=> count($serverWsObjects),
                                    'proxy_client_count'=> count($tunnelWsObjects),
                                    'wsObjectConfigs'=> $wsObjectConfigs,
                                    'http_status_to_server_client_count'=> count($httpStatusToServerClient),
                                    'server_memory'=> '运行内存：'.round(memory_get_usage()/1024/1024, 2)."MB",
                                ]
                            ]
                        ]));
                }
                    
                echo "getStatus\n";
                foreach ($httpStatusToServerClient as $k=>$http) {
                    foreach ($serverWsObjects as $serverWs) {
                        $serverWs->push(json_encode($http));
                    }
                    unset($httpStatusToServerClient[$k]);
                }
                    


                // $httpStatusToServerClient = [];
            }
            Co::sleep(1);
        }
    });
    go(function () {
        global $config;
        $server = new Server($config['admin_addr'], $config['admin_port'], false);


        $server->handle('/server', function (Request $request, Response $ws) {
    
            // var_dump($request);
            if (!isset($request->header['sec-websocket-key'])) {//
                //todo 兼容http请求
                return;
            }
            global $myClient;
            global $serverWsObjects;
    
    
            $ws->upgrade();
            $objectId = spl_object_id($ws);
            $ws->objectId = $objectId;
            $serverWsObjects[$objectId] = $ws;
    
           
            
    
            while (true) {
                $frame = $ws->recv();
                if ($frame === '') {
                    unset($serverWsObjects[$ws->objectId]);
                    $ws->close();
                    break;
                } elseif ($frame === false) {
                    echo 'errorCode: ' . swoole_last_error() . "\n";
                    unset($serverWsObjects[$ws->objectId]);
                    
                    $ws->close();
                    break;
                } else {
                    if ($frame->data == 'close' || get_class($frame) === CloseFrame::class) {
                        $ws->close();
                        unset($serverWsObjects[$ws->objectId]);
                        break;
                    }
    
                    $data = $frame->data;
                    // var_dump($data);
                    $myClient->onMessage($ws, $data, $request);//todo $request??
                    
                    // $ws->push("How are you, {$frame->data}?");
                }
            }
        });
        $server->handle('/', function (Request $request, Response $response) {
            if(!isset($request->header['authorization'])){
                $response->header('www-authenticate', 'Basic',false);
                $response->header('Content-Type', 'text/html; charset=UTF-8');
                $response->status(401);
                $response->end();
                return ;
            }
            global $config;

            $authorization =  explode(':', base64_decode(substr($request->header['authorization'],6)));
            $admin_user = $authorization[0];
            $admin_pwd = $authorization[1];

            if($config['admin_user']!=$admin_user|| $config['admin_pwd']!=$admin_pwd){
                $response->header('Content-Type', 'text/html; charset=UTF-8');
                $response->header('www-authenticate', 'Basic',false);
                $response->status(401);
                $response->end('账号或密码错误');
                return;
            }
            

            $response->header('Content-Type', 'text/html; charset=UTF-8');
            $response->end(str_replace(['9502','Proxy Server'], [$config['admin_port'],'Proxy Client'], file_get_contents('./index.html')));
        });
    

        $server->handle('/favicon.ico', function (Request $request, Response $response) {
            $response->end('abc');
        });
    
       
    
        $server->start();
    });
    $two = function () {
        go(function () {
            global $i;
            global $myClient;
            global $config;
            global $wsObjects;

            $websocket = SaberGM::websocket("ws://{$config['server_addr']}:{$config['server_port']}/websocket?token=".($config['token']??''));
            // $websocketHandler = new WebSocketHandle($websocket);

            Swoole\Timer::tick(30000, function () use (&$websocket) {
                if ($websocket->client->getStatusCode()>0) {
                    $pingFrame = new Frame;
                    $pingFrame->opcode = WEBSOCKET_OPCODE_PING;
                    $websocket->push($pingFrame);
                }
            });
            $objectId = spl_object_id($websocket);
            $websocket->objectId = $objectId;
            $wsObjects[$objectId] = $websocket;
            while (true) {
                if ($websocket->client->getStatusCode()>0) {
                    echo "connected\n";
                    $data = $websocket->recv();
                    // var_dump($data);
                    // echo 'recv:' .$data. "\n";
                    if ($data) {
                        // var_dump($data->data);
                        $myClient->onMessage($websocket, $data);
                        echo $i++. "\n";
                    }
                } else {
                    unset($wsObjects[$websocket->objectId??null]);
                    echo "connect fail\n";
                    echo "errorCode:".$websocket->client->getStatusCode()."\n";

                    co::sleep(2);
                    try {
                        echo "try connect";
                        $websocket = SaberGM::websocket("ws://{$config['server_addr']}:{$config['server_port']}/websocket?token=".($config['token']??''));

                        // $websocketHandler = new WebSocketHandle($websocket);
                        $objectId = spl_object_id($websocket);
                        $websocket->objectId = $objectId;
                        $wsObjects[$objectId] = $websocket;
                        echo "try connected";
                    } catch (\Exception $e) {
                        unset($wsObjects[$websocket->objectId??null]);

                        echo "connect:error".$e->getMessage();
                    }
                }
            }
        });
    };

    for ($j=0;$j<1;$j++) {
        $two();
    }
});
