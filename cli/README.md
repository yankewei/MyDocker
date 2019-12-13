## 从一个简单的socket通信开始

socket的中文名字叫做套接字，这种东西就是对TCP/IP的“封装”。

在php中，有一套`socket_*`系列的函数，其实就是把C语言的那一套原封不动的拿过来用了。创建过程如下：

- 创建一个套接字：socket_create()
- 绑定一个端口：socket_bind()
- 开始监听socket：socket_listen()
- 接受客户端请求： socket_accept()
- 获取请求的数据： socket_read()
- 响应客户端请求： socket_write()
- 关闭链接：socket_close()

```php
<?php
$host = '0.0.0.0';
$port = 9501;
// 创建一个tcp socket
$listen_socket = socket_create( AF_INET, SOCK_STREAM, SOL_TCP );
// 将socket bind到IP：port上
socket_bind( $listen_socket, $host, $port );
// 开始监听socket
socket_listen( $listen_socket );
while( true ){
    // 此处将会阻塞住，一直到有客户端来连接服务器.
    $connection_socket = socket_accept( $listen_socket );
    // 获取客户端的数据
    $readBuf = socket_read($connection_socket, 2048);
    if ($readBuf === 'block') {
        sleep(5);
    }
    echo "客户端的请求数据：{$readBuf}\n";
    socket_write( $connection_socket, $readBuf, strlen( $readBuf ) );
    socket_close( $connection_socket );
}
socket_close( $listen_socket );
```

上面这个案例中，有两个很大的缺陷：

- 一次只可以为一个客户端提供服务，如果正在响应第一个客户端的同时有第二个客户端来连接，那么第二个客户端就必须要等待片刻才行。
- 很容易受到攻击，造成拒绝服务。

## 多进程的socket通信

联想到多进程，我们可以预先设置10个进程，由系统决定请求应该分配到哪个进程，这样问题不就解决了吗？

```php
<?php
$host = '0.0.0.0';
$port = 9501;
// 创建一个tcp socket
$listen_socket = socket_create( AF_INET, SOCK_STREAM, SOL_TCP );
// 将socket bind到IP：port上
socket_bind( $listen_socket, $host, $port );
// 开始监听socket
socket_listen( $listen_socket );
// 给主进程换个名字
cli_set_process_title( "phpserver master process" );
// 创建10个进程
for ($i = 0; $i < 10; $i ++) {
  $pid = pcntl_fork();
  // 在子进程中处理当前连接的请求业务
  if( 0 == $pid ){
    cli_set_process_title( "phpserver worker process" );
    while (true) {
      // 此处将会阻塞住，一直到有客户端来连接服务器.
      $connection_socket = socket_accept( $listen_socket );
      // 获取客户端的数据
      $readBuf = socket_read($connection_socket, 2048);
      if ($readBuf === 'block') {
          sleep(5);
      }
      $pid = getmypid();
      file_put_contents("/Code/data.txt", "进程号: {$pid} ,客户端的请求数据：{$readBuf}\n", FILE_APPEND);
      socket_write( $connection_socket, $readBuf, strlen( $readBuf ) );
      socket_close( $connection_socket );
    }
  }
}
// 为了不让主进程挂掉，使用了while循环，合适的做法应该去监听子进程，处理子进程的各种信号
while( true ){
  sleep(1);
}
socket_close( $listen_socket );
```

上面这个多进程的案例，也有很多的问题：

- 首先进程间的切换就耗费资源
- 处理请求有限，10个进程同时只能处理10个请求，假如我设置100个进程，内存占用也很大

## I/O多路复用

对于linux来说，一切皆文件，上述socket编程也是网络I/O，进程拒绝其他的请求，主要原因就是在等待，比如数据库的返回。那么我们可不可以同时在多个文件描述符上阻塞，并在其中某个可以读写时收到通知。因此I/O多路复用关键所在就是：

1. 当任何一个文件描述符I/O就绪时进行通知
2. 都不可用？在有可用的文件描述符之前一直处于睡眠状态
3. 唤醒：哪个文件描述符可用了？
4. 处理所有I/O就绪的文件描述符
5. 返回第一步，重新开始

### select(凑活凑活也能用)

```php
socket_select ( array &$read , array &$write , array &$except , int $tv_sec [, int $tv_usec = 0 ] ) : int
```

监视的文件描述符可以分为3类，分别等待不同的事件:

- 对于 `$read` 中的文件描述符，监视是否有数据可读；
- 对于 `$write` 中的文件描述符，监视是否有某个写操作可以无阻塞完成；
- 对于 `$except` 中的文件描述符，监视是否发生异常；
- 第四个参数表示在经过 `$tv_sec` 秒之后，即使没有一个文件描述符处于就绪状态，也会返回，为 `null` 表示不设置超时限制
- 第五个可选和第四个参数一样的作用，可以指定到微秒级别
- <font color="#FFA07A">返回值：</font>返回有多少个已经I/O就绪的文件描述符。

> 函数原型的前三个参数，都是使用的引用，也就是说在函数执行完之后，会重新赋值上可用的描述符

```php
<?php
$address = '0.0.0.0';
$port    = 9501;
// 假设我们最大支持10个客户端
$max_clients = 10;
// 存储所有的客户端
$clients = Array();
// 创建一个套接字
$master_socket = socket_create(AF_INET, SOCK_STREAM, 0);
// 绑定套接字到端口上
socket_bind($master_socket, $address, $port);
// 开始监听套接字
socket_listen($master_socket);

while (true) {
  // 初始化可读的文件描述符
  $read   = array();
  $read[] = $master_socket;
  // 添加所有的客户端到可读的文件描述符中
  foreach($clients as $client){
    $read[] = $client;
  }
  $ready = socket_select($read, $write, $exp, null);
  if ($ready == 0){
    continue;
  }

  echo "监听到有{$ready} 个可读的描述符\n";
  print_r($read);
  // 在可读的文件描述符中有套接字，表示有新的客户端建立了连接
  if (in_array($master_socket, $read)){
    if (count($clients) <= $max_clients){
      echo "接受新的客户端连接\n";
      $clients[] = socket_accept($master_socket);
    }else{
      echo "max clients reached...\n";
    }
    // 从可读的描述符中取出掉我们的套接字，因为下边会遍历所有的客户端进行通信
    $key = array_search($master_socket, $read);
    unset($read[$key]);
  }
  echo "所有的客户端: \n";
  print_r($clients);
  // 如果有其他的客户端连接符也在可读的描述符中，表示它发送了消息，我们进行处理
  foreach($read as $client){
    $input = socket_read($client, 1024);
    if (!$input) {
      $key = array_search($client, $clients);
      unset($clients[$key]);
    }
    $input = trim($input);
    if ($input) {
      echo "收到客户端消息:{$input}\n";
      socket_write($client, $input);
    }
  }
}

socket_close($master_socket);
```

`select`比上面的多进程要强多了，一个进程就能搞定成千上万的连接，但是也发现了它的缺点：

- 一个进程所使用的文件描述符有限，我记的默认是1024个，当然可以更改默认值；
- select挑选可用的文件描述符的过程是一个轮询的过程，就是说我有1w个连接，我需要遍历一遍才知道谁可用，复杂度就是O(n)

### epoll(解决c10k的大功臣)

php有两个event的的扩展，分别是 `libevent` 和 `event` , `libevent`貌似不再维护了，版本停留在 0.1.0，`event`还一直很在维护，最新的是`2.5.3`，并且一直在更新，所以我们拿 `event` 来编写一个简单的基于`epoll`的httpserver。

主要使用到了三个类 `EventConfig`、`EventBase` 和 `Event`；

`EventConfig`则是一个配置类，实例化后的对象作为参数可以传递给`EventBase`类，这样在初始化`EventBase`类的时候会根据这个配置初始化出不同的`EventBase`实例。

```php
public Event::__construct ( EventBase $base , mixed $fd , int $what , callable $cb [, mixed $arg = NULL ] )
```

- 第一个参数是一个eventBase对象即可
- 第二个参数是文件描述符，可以是一个监听socket、一个连接socket、一个fopen打开的文件或者stream流等。如果是时钟时间，则传入-1。如果是其他信号事件，用相应的信号常量即可，比如SIGHUP、SIGTERM等等
- 第三个参数表示事件类型，依次是Event::READ、Event::WRITE、Event::SIGNAL、Event::TIMEOUT。其中，加上Event::PERSIST则表示是持久发生，而不是只发生一次就再也没反应了。比如Event::READ | Event::PERSIST就表示某个文件描述第一次可读的时候发生一次，后面如果又可读就绪了那么还会继续发生一次。
- 第四个参数就是事件回调了，意思就是当某个事件发生后那么应该具体做什么相应
- 第五个参数是自定义数据，这个数据会传递给第四个参数的回调函数，回调函数中可以用这个数据。

```php
<?php
$host = '0.0.0.0';
$port = 9501;
$fd = socket_create( AF_INET, SOCK_STREAM, SOL_TCP );
socket_bind( $fd, $host, $port );
socket_listen( $fd );
// 注意，将“监听socket”设置为非阻塞模式
// socket_set_nonblock( $fd );

// 这里值得注意，我们声明两个数组用来保存 事件 和 连接socket
$event_arr = []; 
$conn_arr = []; 

echo PHP_EOL.PHP_EOL."欢迎来到ti-chat聊天室!发言注意遵守当地法律法规!".PHP_EOL;
echo "        tcp://{$host}:{$port}".PHP_EOL;

$event_base = new EventBase();
$event = new Event( $event_base, $fd, Event::READ | Event::PERSIST, function( $fd ){
  // 使用全局的event_arr 和 conn_arr
  global $event_arr,$conn_arr,$event_base;
  // 非阻塞模式下，注意accpet的写法会稍微特殊一些。如果不想这么写，请往前面添加@符号，不过不建议这种写法
  $conn = socket_accept( $fd );
    echo date('Y-m-d H:i:s').'：欢迎'.intval( $conn ).'来到聊天室'.PHP_EOL;
	// 将连接socket也设置为非阻塞模式
    socket_set_nonblock( $conn );
	// 此处值得注意，我们需要将连接socket保存到数组中去
    $conn_arr[ intval( $conn ) ] = $conn;
    $event = new Event( $event_base, $conn, Event::READ | Event::PERSIST, function( $conn )  { 
      global $conn_arr;
      $buffer = socket_read( $conn, 65535 );
      foreach( $conn_arr as $conn_key => $conn_item ){
        if( $conn != $conn_item ){
          $msg = intval( $conn ).'说 : '.$buffer;
          socket_write( $conn_item, $msg, strlen( $msg ) );
        }   
      }   
    }, $conn );
    $event->add();
	// 此处值得注意，我们需要将事件本身存储到全局数组中，如果不保存，连接会话会丢失，也就是说服务端和客户端将无法保持持久会话
    $event_arr[ intval( $conn ) ] = $event;
  // }
}, $fd );
$event->add();
$event_base->loop();
```