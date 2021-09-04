## Http  penetrate


* swoole 实现了客户端和服务端
* go 刚开使用，只实现了服务端(支持php客户端连接)
* 客户端可查看请求，及重新发送请求

## swoole

```
pcel install swoole
```

## client 

在本地运行

```
php proxy_client.php
```
[http://127.0.0.1:7400](http://127.0.0.1:7400)

![](./WechatIMG585.png)

## swoole server

![](./WechatIMG586.png)

打开 9502 和9503 端口

在有公网服务器上运行


```
php proxy_server.php
```

简单的后台运行
```
nohup php proxy_server.php &
```

关闭服务端
```
ps -aux | grep proxy_server.php

找到 pid

kill -9 pid
```

 ## go server

 ```
 go run main.go
 ``` 


 ## 如何80 端口访问

 ```
 server {
    listen 80;
    server_name  yourdomain.com;//你自己的域名

    location / {
      proxy_pass http://xxxx:9503;//填写终端输出的域名或者你自己的域名
      proxy_set_header    Host             $host;#保留代理之前的host
      proxy_set_header    X-Real-IP        $remote_addr;#保留代理之前的真实客户端ip
      proxy_set_header    X-Forwarded-For  $proxy_add_x_forwarded_for;
      proxy_set_header    HTTP_X_FORWARDED_FOR $remote_addr;#在多级代理的情况下，记录每次代理之前的客户端真实ip
#      proxy_redirect      default;#指定修改被代理服务器返回的响应头中的location头域跟refresh头域数值

    }
    access_log /var/log/nginx/yourdomain.access.log;
    error_log  /var/log/nginx/yourdomain.error.log;
}

 ```