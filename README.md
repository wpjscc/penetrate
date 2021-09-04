## Http  penetrate


* swoole 实现了客户端和服务端
* go 刚开使用，只实现了服务端

## swoole

```
pcel install swoole
```

## client 

在本地运行

```
php proxy_client.php
```


## swoole server


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