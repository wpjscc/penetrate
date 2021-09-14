<?php

$prefix = uniqid();
$domain = "{$prefix}.test.wpjs.cc";

$local_ip = '127.0.0.1';//可以监听任何ip，这里默认本地
$local_port = 10080;//只需换成你自己的端口即可

//下方不用改

echo "visit:http://{$domain}:9503\n";
echo "Local:listen:http://127.0.0.1:7400\n";

return [
    // 'server_addr'=>'127.0.0.1',
    'server_addr'=>'47.96.15.116',
    // 'server_port'=>8080,
    'server_port'=>9502,
    'admin_addr' => '127.0.0.1',
    'vhost_http_port'=>'9503',
    'admin_port' => 7400,
    'admin_user'=>'admin',
    'admin_pwd'=>'admin',
    'configs' => [
        [
            'type'=>'http',
            'local_ip'=> $local_ip,
            'local_port'=> $local_port,//
            'custom_domains'=> [
                $domain,
                '127.0.0.1',
                '47.96.15.116',
                
            ]
        ],
        // [
        //     'type'=>'tcp',
        //     'local_ip'=> '127.0.0.1',
        //     'local_port'=> 3306,//
        //     'token'=> '123456789',
        //     'server_port'=> 9504,//服务器端口9504 转发到此一对一

        // ],
        // [
        //     'type'=>'tcp',
        //     'local_ip'=> '10.8.0.9',
        //     'local_port'=> 22,//
        //     'token'=> '123456789',//允许注册到服务器
        //     'server_port'=> 9504,//服务器端口9504 一对一


        // ],
        [
            'type'=>'tcp',
            'local_ip'=> '127.0.0.1',
            'local_port'=> 10080,//
            'token'=> '123456789',
            'server_port'=> 9504,//服务器端口9504 转发到此一对一

        ],
    ]
];