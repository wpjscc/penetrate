<?php

function colorLog($str, $type = 'i')
{
    switch ($type) {
        case 'e': //error
            echo "\033[31m$str \033[0m\n";
        break;
        case 's': //success
            echo "\033[32m$str \033[0m\n";
        break;
        case 'w': //warning
            echo "\033[33m$str \033[0m\n";
        break;
        case 'i': //info
            echo "\033[36m$str \033[0m\n";
        break;
        default:
        # code...
        break;
    }
}
function eventStart($group, $data)
{
    $time = $data['time'];
    $eventName = $data['event'];
    $uniqid = $data['uniqid'];
    $content = $data['content']??null;
    $extra = $data['extra']??[];
    global  $httpStatusToServerClient;
    $micro = sprintf("%06d", ($data['time'] - floor($data['time'])) * 1000000);
    $d = new DateTime(date('Y-m-d H:i:s.'.$micro, $data['time']));
    $httpStatusToServerClient [] = [
            'event' => 'system',
            'data' => [
                'event' =>'proxyRequest',
                'data' => [
                    'group' => $group,
                    'event' => $eventName,
                    'uniqid' => $data['uniqid'],
                    'time' => $data['time'],
                    'date' => $d->format("Y-m-d H:i:s.u"),
                    'content' => base64_encode($content),
                    'status' => 'start',
                    'extra' => $extra
                ]
            ]
                
        ];
        
    colorLog("start-{$group}-{$eventName}-{$uniqid}-{$time}\n");
}
function eventSuccess($group, $data)
{
    $time = $data['time'];
    $eventName = $data['event'];
    $uniqid = $data['uniqid'];
    $content = $data['content']??null;
    $extra = $data['extra']??[];

    global  $serverWsObjects;
    global  $httpStatusToServerClient;

    $micro = sprintf("%06d", ($data['time'] - floor($data['time'])) * 1000000);
    $d = new DateTime(date('Y-m-d H:i:s.'.$micro, $data['time']));

    $httpStatusToServerClient [] = [
            'event' => 'system',
            'data' => [
                'event' =>'proxyRequest',
                'data' => [
                    'group' => $group,
                    'event' => $eventName,
                    'uniqid' => $data['uniqid'],
                    'time' => $data['time'],
                    'date' => $d->format("Y-m-d H:i:s.u"),
                    'content' => base64_encode($content),
                    'status' => 'success',
                    'extra' => $extra

                ]
            ]
            
        ];
    foreach ($serverWsObjects as $obj) {
        // $obj->push("success-{$group}-{$eventName}-{$uniqid}-{$time}");
            
                

            // $obj->push(json_encode([
            //     'event' => 'system',
            //     'data' => [
            //         'event' =>'proxyRequest',
            //         'data' => [
            //             'group' => $group,
            //             'event' => $eventName,
            //             'uniqid' => $data['uniqid'],
            //             'time' => $data['time'],
            //             'date' => $d->format("Y-m-d H:i:s.u"),
            //             'content' => $content,
            //             'status' => 'success'
            //         ]
            //     ]
                
            // ]));
    }
    colorLog("success-{$group}-{$eventName}-{$uniqid}-{$time}\n", 's');
}
function eventFail($group, $data)
{
    $time = $data['time'];
    $eventName = $data['event'];
    $uniqid = $data['uniqid'];
    $content = $data['content']??null;
    $extra = $data['extra']??[];

    global  $serverWsObjects;
    global  $httpStatusToServerClient;
    $micro = sprintf("%06d", ($data['time'] - floor($data['time'])) * 1000000);
    $d = new DateTime(date('Y-m-d H:i:s.'.$micro, $data['time']));
    $httpStatusToServerClient [] = [
                'event' => 'system',
                'data' => [
                    'event' =>'proxyRequest',
                    'data' => [
                        'group' => $group,
                        'event' => $eventName,
                        'uniqid' => $data['uniqid'],
                        'time' => $data['time'],
                        'date' => $d->format("Y-m-d H:i:s.u"),
                        'content' => base64_encode($content),
                        'status' => 'fail',
                        'extra' => $extra

                    ]
                ]
                
        ];
        
    colorLog("fail-{$group}-{$eventName}-{$uniqid}-{$time}\n", 'e');
}