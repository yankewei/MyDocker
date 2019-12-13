<?php
$sendBuf = $argv[1];
$resource = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
socket_connect($resource, '0.0.0.0', 9501);
$sentByte = socket_send($resource, $sendBuf, strlen($sendBuf), MSG_EOF);
if (!$sentByte) {
    echo '发送失败';
}

$recvByte = socket_read($resource, 1024);
socket_close($resource);
echo "收到的数据:$recvByte\n";