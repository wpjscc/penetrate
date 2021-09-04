<?php

require __DIR__ . '/vendor/autoload.php';

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\WebSocket\CloseFrame;
use Swoole\Coroutine\Http\Server;
use function Swoole\Coroutine\run;
use function Swoole\Coroutine\go;
use Swoole\Coroutine as Co;
use function Swlib\Http\parse_response;
use function Swlib\Http\parse_request;

global $wsObjects;//websocket
global $wsObjectConfigs;//配置
global $tunnelWsObjects;
global $httpObjects;
global $httpToTunnelWs;


global $httpStatusToServerClient;
$httpStatusToServerClient=[];

ini_set('date.timezone','Asia/Shanghai');

$serverWsObjects=[];//服务端 client->server(server)

$wsObjects=[];//client->server(websocket)
$wsObjectConfigs=[];
$tunnelWsObjects=[];
$httpObjects=[];
$httpToTunnelWs=[];

$config = require_once __DIR__.'/server_config.php';

require_once __DIR__ . '/event_log.php';



class MyApp
{
    public function onMessage($res, $ws, $message)
    {
        $message = json_decode($message, true);

        $event = data_get($message, 'event');
        $data = data_get($message, 'data');

        if ($event&&$data) {
            if (method_exists($this, $event)) {
                call_user_func([$this,$event], $res, $ws, $data);
            }
        }
    }

    public function client($res, $ws, $message)
    {
        //todo 处理一些前置事件
        echo "Event:client\n";
        $event = data_get($message, 'event');
        $data = data_get($message, 'data');
        if ($event&&$data) {
            echo "Event:client:$event\n";
            if (method_exists($this, $event)) {
                call_user_func([$this,$event], $res, $ws, $data);
            }
        }
        //todo 处理一些后置事件
    }

    public function system($res, $ws, $message)
    {
        // var_dump($message);
        //todo 处理一些前置事件
        echo "Event:system\n";
        $event = data_get($message, 'event');
        $data = data_get($message, 'data');
        if ($event&&$data) {
            echo "Event:system:$event\n";
            if (method_exists($this, $event)) {
                call_user_func([$this,$event], $res, $ws, $data);
            }
        }
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

        global $myAppProxy;
        $myAppProxy->proxyLocalRequest($ws, $data);
    }

    public function getStatus($res, $ws, $data)
    {
        global $httpObjects;
        global $wsObjects;
        global $wsObjectConfigs;
        global $tunnelWsObjects;
        global $serverWsObjects;
        global $httpStatusToServerClient;
        echo "getStatus\n";
        // $ws->push(json_encode([
        //     'event' => 'system',
        //     'data' => [
        //         'event' => 'getStatus',
        //         'data' => [
        //             'http_count'=> count($httpObjects),
        //             'client_count'=> count($wsObjects),
        //             'server_client_count'=> count($serverWsObjects),
        //             'proxy_client_count'=> count($tunnelWsObjects),
        //             'wsObjectConfigs'=> $wsObjectConfigs,
        //             'http_status_to_server_client_count'=> count($httpStatusToServerClient),
        //         ]
        //     ]
        // ]));
    }



    public function receiveConfig($res, $ws, $data)
    {

        //todo 之前验证
        global $wsObjectConfigs;//配置

        $configs = data_get($data, 'configs');
        //todo 验证config

        $wsObjectConfigs[$ws->objectId] = $configs;
    }

    /**
     * server->createProxy
     *
     * @param [type] $ws
     * @param [type] $requestId
     * @return void
     */
    public function createProxy($ws, $requestId, $localIp, $localPort,$host)
    {
        $proxyClientId = uniqid();
        echo "time:".microtime(true).'-'."createProxy:".$proxyClientId."\n";

        eventStart('MyApp', [
            'time' => microtime(true),
            'event' => 'createProxy',
            'uniqid' => $proxyClientId,
            'content' => $host
        ]);

        $ws->push(json_encode([
            'event' => 'system',
            'data' => [
                'event' => 'createProxy',
                'data' => [
                    'request_id'=>$requestId,
                    'local_ip'=> $localIp,
                    'local_port'=> $localPort,
                    'proxy_client_id'=> $proxyClientId,
                    'uniqid' => $proxyClientId,
                    'host' => $host,

                ]
            ]
        ]));
    }


    /**
     * client->server(event)
     *
     * @param [type] $res
     * @param [type] $ws
     * @param [type] $message
     * @return void
     */
    public function proxyError($res, $ws, $message)
    {
        global $httpObjects;
        eventFail('MyApp', [
            'time' => microtime(true),
            'event' => 'createProxy',
            'uniqid' => $message['uniqid'],
            'content' => $message['content'],
        ]);
        $requestId = data_get($message, 'request_id');
        $http = $httpObjects[$requestId];
        $response = $http['response'];
        $response->end($message['content']);//错误信息返回
        unset($httpObjects[$ws->request_id]);
    }
}

class MyAppProxy
{
    public function onMessage($res, $ws, $message)
    {
        $message = json_decode($message, true);

        $event = data_get($message, 'event');
        $data = data_get($message, 'data');

        if ($event&&$data) {
            if (method_exists($this, $event)) {
                call_user_func([$this,$event], $res, $ws, $data);
            }
        }
    }

    public function client($res, $ws, $message)
    {
        //todo 处理一些前置事件
        echo "ProxyEvent:client\n";
        $event = data_get($message, 'event');
        $data = data_get($message, 'data');
        if ($event&&$data) {
            echo "ProxyEvent:client:$event\n";
            if (method_exists($this, $event)) {
                call_user_func([$this,$event], $res, $ws, $data);
            }
        }
        //todo 处理一些后置事件
    }

    /**
     * 浏览器请求
     *
     * @param [type] $w
     * @param [type] $data
     * @return void
     */
    public function proxyLocalRequest($w,$message)
    {
        eventStart('MyAppProxy', [
            'time' => microtime(true),
            'event' => 'proxyRequest',
            'uniqid' => $message['uniqid'],
            'content' => $message['content'],
            'extra' => [
                'local_port' => $message['local_port'],
                'local_ip' => $message['local_ip'],
                'proxy_client_id'=>$message['proxy_client_id'],
                'request_id'=>$message['request_id'],
                'host'=>$message['host'],
            ]
        ]);

        //todo server->client 新建一条通道
    }

    /**
     * proxyClient->AppProxy
     *
     * @param [type] $res
     * @param [type] $ws
     * @param [type] $message
     * @return void
     */
    public function proxyRequest($res, $ws, $message)
    {
        global $httpObjects;
        global $httpToTunnelWs;


        
        $proxyClientId = $message['proxy_client_id'];
        $requestId = $message['request_id'];
        $ws->proxy_client_id = $proxyClientId;
        $ws->request_id = $requestId;

        $httpToTunnelWs[$requestId]=$proxyClientId;

        //todo send
        $http = $httpObjects[$requestId];

        $request = $http['request'];
        $response = $http['response'];

        eventSuccess('MyApp', [
            'time' => microtime(true),
            'event' => 'createProxy',
            'uniqid' => $message['uniqid'],
            'content' => $message['host']
        ]);

        eventStart('MyAppProxy', [
            'time' => microtime(true),
            'event' => 'proxyRequest',
            'uniqid' => $message['uniqid'],
            'content' => $request->getData(),
            'extra' => [
                'local_port' => $message['local_port'],
                'local_ip' => $message['local_ip'],
                'proxy_client_id'=>$proxyClientId,
                'request_id'=>$requestId,
                'host'=>$message['host'],
            ]
        ]);

        //todo 将http请求内容发送至客户端
        echo "time:".microtime(true).'-'."proxyRequest:{$proxyClientId}\n";
        $ws->push(json_encode([
            'event' => 'system',
            'data'=>[
                'event' =>'receiveRequest',
                'data' =>[
                    'content'=> base64_encode($request->getData()),
                    'local_port' => $message['local_port'],
                    'local_ip' => $message['local_ip'],
                    'proxy_client_id'=>$proxyClientId,
                    'request_id'=>$requestId,
                    'uniqid'=>$message['uniqid'],
                    'host'=>$message['host'],
                ]
            ]
        ]));
        // var_dump($request->getData());
        // exit();
    }
    public function proxyReponse($res, $ws, $message)
    {
        global $httpObjects;
        global $tunnelWsObjects;
        global $httpToTunnelWs;
        $http = $httpObjects[$ws->request_id];
        $response = $http['response'];
        eventSuccess('MyAppProxy', [
            'time' => microtime(true),
            'event' => 'proxyRequest',
            'uniqid' => $message['uniqid'],
        ]);

        eventStart('MyAppProxy', [
            'time' => microtime(true),
            'event' => 'proxyReponse',
            'uniqid' => $message['uniqid'],
        ]);

        try {
            $proxyReponse = parse_response(base64_decode($message['content']));
        } catch (Exception $e) {//返回的信息不符合规范
            $response->end($e->getMessage);
            $ws->close();
            unset($httpObjects[$ws->request_id]);
            unset($tunnelWsObjects[$ws->objectId]);
            unset($httpToTunnelWs[$ws->request_id]);
            eventFail('MyAppProxy', [
                'time' => microtime(true),
                'event' => 'proxyReponse',
                'uniqid' => $message['uniqid'],
                'content' => $e->getMessage
            ]);
            return;
        }


        $headers = $proxyReponse->getHeaders();
        // $cookies = $proxyReponse->getCookies();

        // var_dump($cookies);
        // $body = (string)$proxyReponse->getBody();
        // var_dump($headers);
        // var_dump(strlen($body));
        // exit();
        foreach ($headers as $key=> $value) {
            // echo "{$key}:".$proxyReponse->getHeaderLine($key)."123\n";
            $response->header($key, $value,true);

        }
        
        // var_dump($response);
        // exit();
        // var_dump($res->getStatusCode());
        // var_dump((string)$proxyReponse->getBody());
        // exit();
        // $response->status($proxyReponse->getStatusCode());

        if($proxyReponse->getStatusCode()==302){
            var_dump((string)$proxyReponse);
            var_dump($headers);
            // exit();
        }

        $response->status($proxyReponse->getStatusCode());
        if((string)$proxyReponse->getBody()){
            $response->write($proxyReponse->getBody());
        }
        $response->end();

        eventSuccess('MyAppProxy', [
            'time' => microtime(true),
            'event' => 'proxyReponse',
            'uniqid' => $message['uniqid'],
            'content' => (string) $proxyReponse
        ]);
        echo "time:".microtime(true).'-'."proxyReponse:{$ws->proxy_client_id}\n";

        unset($httpObjects[$ws->request_id]);
        unset($tunnelWsObjects[$ws->objectId]);
        unset($httpToTunnelWs[$ws->request_id]);
        // $response->end($message['content']);
        $ws->close();
    }

    public function proxyException($res, $ws, $message)
    {
        global $httpObjects;
        global $tunnelWsObjects;
        global $httpToTunnelWs;
        $http = $httpObjects[$ws->request_id];
        eventFail('MyAppProxy', [
            'time' => microtime(true),
            'event' => 'proxyRequest',
            'uniqid' => $message['uniqid'],
            'content' => $message['content'],
        ]);
        $response = $http['response'];
        $response->end($message['content']);

        unset($httpObjects[$ws->request_id]);
        unset($tunnelWsObjects[$ws->objectId]);
        unset($httpToTunnelWs[$ws->request_id]);

        $ws->close();
    }
}
global $myApp;
if (!isset($myApp)) {
    $myApp = new MyApp();
}
global $myAppProxy;
if (!isset($myAppProxy)) {
    $myAppProxy = new MyAppProxy();
}
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
        
        while(true){
            if(empty($serverWsObjects)){
                // echo "55555555\n";
                $httpStatusToServerClient = [];
            }else{
                foreach ($serverWsObjects as $serverWs){
                    $serverWs->push(json_encode([
                        'event' => 'system',
                        'data' => [
                            'event' => 'getStatus',
                            'data' => [
                                'http_count'=> count($httpObjects),
                                'client_count'=> count($wsObjects),
                                'server_client_count'=> count($serverWsObjects),
                                'proxy_client_count'=> count($tunnelWsObjects),
                                // 'wsObjectConfigs'=> $wsObjectConfigs,
                                'http_status_to_server_client_count'=> count($httpStatusToServerClient),
                                'server_memory'=> '运行内存：'.round(memory_get_usage()/1024/1024, 2)."MB",
                            ]
                        ]
                    ]));

                }
                echo "getStatus\n";
                $httpStatusToServerClient = [];
            }
            Co::sleep(2);
            
        }
    });
    go(function () {
        global $config;
        $server = new Server('0.0.0.0', $config['server_port'], false);

        $server->set([
            'buffer_input_size' => 32 * 1024 * 1024,
            'buffer_output_size' => 32 * 1024 * 1024, //必须为数字
        ]);


        $server->handle('/websocket', function (Request $request, Response $ws) {
    
            // var_dump($request);
            if (!isset($request->header['sec-websocket-key'])) {//
                //todo 兼容http请求
                return;
            }
            global $myApp;
            global $wsObjects;
            global $wsObjectConfigs;
    
    
            $ws->upgrade();
            $objectId = spl_object_id($ws);
            $ws->objectId = $objectId;
            $ws->objectId = $objectId;
            $wsObjects[$objectId] = $ws;
    
            $ws->push(json_encode([
                'event' => 'system',
                'data' => [
                    'event'=>'setClientId',
                    'data' => [
                        'client_id' => $objectId,
                    ]
                ]
            ]));
            $ws->push(json_encode([
                'event' => 'system',
                'data' => [
                    'event'=>'sendConfig',
                    'data' => [
                        'client_id' => $objectId,
                    ]
                ]
            ]));
    
            while (true) {
                $frame = $ws->recv();
                if ($frame === '') {
                    unset($wsObjects[$ws->objectId]);
                    unset($wsObjectConfigs[$ws->objectId]);
                    $ws->close();
                    break;
                } elseif ($frame === false) {
                    echo 'errorCode: ' . swoole_last_error() . "\n";
                    unset($wsObjects[$ws->objectId]);
                    unset($wsObjectConfigs[$ws->objectId]);
                    
                    $ws->close();
                    break;
                } else {
                    if ($frame->data == 'close' || get_class($frame) === CloseFrame::class) {
                        $ws->close();
                        unset($wsObjects[$ws->objectId]);
                        unset($wsObjectConfigs[$ws->objectId]);
    
                        break;
                    }
    
                    $data = $frame->data;
                    $myApp->onMessage($request, $ws, $data);
                    // var_dump($request->get);
                    // var_dump($frame);
                    // echo 'recv: ' . $frame->data . "\n";
                    // foreach ($wsObjects as $obj) {
                    //     // $obj->push("Server：{$frame->data}");
                    //     $ws->push("How are you, {$frame->data}?");
    
                    // }
                    // $ws->push("How are you, {$frame->data}?");
                }
            }
        });

        $server->handle('/server', function (Request $request, Response $ws) {
    
            // var_dump($request);
            if (!isset($request->header['sec-websocket-key'])) {//
                //todo 兼容http请求
                return;
            }
            global $myApp;
            global $wsObjects;
            global $serverWsObjects;
            global $wsObjectConfigs;
    
    
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
                    $myApp->onMessage($request, $ws, $data);
                    
                    // $ws->push("How are you, {$frame->data}?");
                }
            }
        });
        $server->handle('/', function (Request $request, Response $response) {
            global $config;
            $response->header('Content-Type', 'text/html; charset=UTF-8');
            $response->end(str_replace('127.0.0.1',$config['server_addr'],file_get_contents('./index.html')) );
        });
    

        $server->handle('/tunnel', function (Request $request, Response $ws) {
            global $myAppProxy;
            global $httpObjects;
            global $tunnelWsObjects;
            global $httpToTunnelWs;
            $ws->upgrade();
            $objectId = spl_object_id($ws);
            $ws->objectId = $objectId;
            $tunnelWsObjects[$objectId] = $ws;
            // var_dump($httpObjects);
            while (true) {
                $frame = $ws->recv();
                if ($frame === '') {
                    // var_dump($ws->request_id);
                    // var_dump($httpObjects);
                    $http = data_get($httpObjects, $ws->request_id??null);
                    if ($http) {
                        colorLog("time:".microtime(true).'-'."ProxyClient:{$ws->objectId}-{$ws->request_id}-{$ws->proxy_client_id}-close-4041", 'e');
                        $http['response']->end("time:".microtime(true).'-'."ProxyClient:{$ws->objectId}-{$ws->request_id}-{$ws->proxy_client_id}-close-4041\n");
                    } else {
                        colorLog("time:".microtime(true).'-'."ProxyClient:{$ws->objectId}-{$ws->request_id}-{$ws->proxy_client_id}-close-4041");

                        colorLog('4041');
                    }
                    echo "4041\n";
                    var_dump($frame);
                    unset($tunnelWsObjects[$ws->objectId]);
                    unset($httpObjects[$ws->request_id??null]);
                    unset($httpToTunnelWs[$ws->request_id??null]);
                    $ws->close();
                    break;
                } elseif ($frame === false) {
                    echo "time:".microtime(true).'-'.'errorCode: ' . swoole_last_error() . "\n";
                    $http = data_get($httpObjects, $ws->request_id??null);
                    if ($http) {
                        colorLog("time:".microtime(true).'-'."ProxyClient:{$ws->objectId}-{$ws->request_id}-{$ws->proxy_client_id}-close-4042", 'e');
                        colorLog('4042', 'e');

                        $http['response']->end("time:".microtime(true).'-'."ProxyClient:{$ws->objectId}-{$ws->request_id}-{$ws->request_id}-{$ws->proxy_client_id}-close-4042\n");
                    } else {
                        colorLog("time:".microtime(true).'-'."ProxyClient:{$ws->objectId}-{$ws->request_id}-{$ws->proxy_client_id}-close-4042");
                        colorLog('4042');
                    }
                    unset($tunnelWsObjects[$ws->objectId]);
                    unset($httpObjects[$ws->request_id??null]);
                    unset($httpToTunnelWs[$ws->request_id??null]);
                    $ws->close();
                    echo "4042\n";
                    var_dump($frame);


    
                    break;
                } else {
                    if ($frame->data == 'close' || get_class($frame) === CloseFrame::class) {
                        $http = data_get($httpObjects, $ws->request_id??null);
                        if ($http) {
                            colorLog("time:".microtime(true).'-'."ProxyClient:{$ws->objectId}-{$ws->request_id}-{$ws->proxy_client_id}-close-4043", 'e');
                            colorLog('4043', 'e');

                            $http['response']->end("time:".microtime(true).'-'."ProxyClient:{$ws->objectId}-{$ws->request_id}-{$ws->proxy_client_id}-close-4043\n");
                        } else {
                            colorLog("time:".microtime(true).'-'."ProxyClient:{$ws->objectId}-{$ws->request_id}-{$ws->proxy_client_id}-close-4043");
                            colorLog('4043');
                        }
                        echo "4043\n";
                        var_dump($frame);
                        
                        unset($tunnelWsObjects[$ws->objectId]);
                        unset($httpObjects[$ws->request_id??null]);
                        unset($httpToTunnelWs[$ws->request_id??null]);
                        $ws->close();
                        break;
                    }
    
                    $data = $frame->data;
                    // echo 'AppProxy:recv: ' . $frame->data . "\n";
    
                    $myAppProxy->onMessage($request, $ws, $data);
    
                    // var_dump($frame);
                    // echo 'recv: ' . $frame->data . "\n";
    
                    // $ws->push("How are you, {$frame->data}?");
                }
            }
        });

        $server->handle('/favicon.ico', function (Request $request, Response $response) {
            $response->end('abc');
        });
    
       
    
        $server->start();
    });
    function retain_key_shuffle(array $arr)
    {
        if (!empty($arr)) {
            $key = array_keys($arr);
            shuffle($key);
            foreach ($key as $value) {
                $arr2[$value] = $arr[$value];
            }
            global $wsObjects;
            $wsObjects=$arr2;
        }
    }
    go(function () {// http 服务
        global $config;

        $server = new Server('0.0.0.0', $config['vhost_http_port'], false,true);
        $server->handle('/', function (Request $request, Response $response) {
            global $wsObjects;
            global $httpObjects;
            global $wsObjectConfigs;//配置
            
            // var_dump($httpObjects);
            //todo find a client 先返回第一个
    
            // var_dump($response);
    
            if (count($wsObjects)==0) {
                $response->end("no clientProxy");
                return;
            }
    
            $objectId = spl_object_id($response);
            $request->objectId = $objectId;
            $response->objectId = $objectId;
            // echo "request_id:".$objectId."\nHost:".$request->header['host']."\n";
            $httpObjects[$objectId] = [
                'request' =>$request,
                'response' => $response
            ];
            $host = $request->header['host'];
            $host = explode(":", $host)[0];
            // var_dump($request->getData());
            // $res = parse_request($request->getData());

            // var_dump($res);
            // var_dump($res->getUri()->getHost());

            // exit();
            global $myApp;

            retain_key_shuffle($wsObjects);
            // global $wsObjects;
            foreach ($wsObjects as $key=>$ws) {
                $configs = data_get($wsObjectConfigs, $ws->objectId);
                if (empty($configs)) {
                    continue;
                }


                foreach ($configs as $config) {
                    $custom_domains = data_get($config, 'custom_domains', []);
                    $type = data_get($config, 'type', 'http');
                    $localIp = data_get($config, 'local_ip', '127.0.0.1');
                    $localPort = data_get($config, 'local_port', 80);
                    // var_dump($config);
                    // var_dump($localIp);
                    // var_dump($localPort);
                    // var_dump($host);
                    if ($localIp&&$localPort&&in_array($host, $custom_domains)) {//todo 客户端custom_domains白名单
                        $myApp->createProxy($ws, $objectId, $localIp, $localPort,$host);

                        return;
                        break 2;
                    }
                }
            }
    
            unset($httpObjects[$objectId]);
            $response->header('Content-Type', 'text/html; charset=UTF-8');
            $response->end("没有可用的代理");
    
            // $response->end("<h1>Swoole hello</h1><pre><code>".json_encode(array_keys($wsObjects))."</code></pre>");
        });
        $server->start();
    });
});
