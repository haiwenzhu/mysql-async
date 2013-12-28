#MysqlAsync
Asynchronous MySQL Wrapper

##Install
You can install via Composer, add the following code to you composer.json:
```
{
    "require": {
        "wrapper/mysql-async": "dev-master"
    },
    "repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/haiwenzhu/mysql-async"
    }
    ]
}
```
Or you can just include the file `src/Wrapper/MysqlAsync.php`

##Description
Class method description:
- MysqlAsync::query

```
mixed MysqlAsync::query ( string $sql , callable $callback [ , callable $error_callback , mysqli $link ] )
```
MysqlAsync query use database link from a connection pool, and you can use your specific link.
- MysqlAsync::loop

```
mixed MysqlAsync::loop ( float $timeout )
```
This method return while all query is finished or timeout occured. return true when all query completed, false otherwise. 
-MysqlAsync::addQueryEvent

```
resource addQueryEvent ( resource $event_base , resource $event , string $sql [ , callable $callback , callable $error_callback , mysqli $link ] )
```
This method provide a event based interface to query asyncornously, you can use this with libevent function. You must install a simple [php extension](https://github.com/haiwenzhu/mysqlasync_ext) if you want use this event style method.

##Usage
Common usage:
```php
<?php
require('./vendor/autoload.php');

$async_wrapper = new Wrapper\MysqlAsync('localhost', 'root', '');
$callback = function($result) {
    print_r($result->fetch_all());
};
$error_callback = function($error, $errno) {
    echo "db error({$errno}) $error\n";
};

$async_wrapper->query("select sleep(1), 1", $callback, $error_callback);
$async_wrapper->query("select sleep(2), 2", $callback, $error_callback);
$async_wrapper->loop();

/*
//loop with timeout
do {
    $done = $async_wrapper->loop(0.1);
    echo "do something else\n";
} while(!$done);
*/
```
Libevent based usage:
```php
<?php
require('./vendor/autoload.php');

$mysql_async = new Wrapper\MysqlAsync('localhost', 'root', '');

$callback = function($result) {
    print_r($result->fetch_all());
};
$error_callback = function($error, $errno) {
    echo "db error({$errno}) $error\n";
};

$event_base = event_base_new();
$event_1 = event_new();
$event_2 = event_new();
$mysql_async->addQueryEvent($event_base, $event_1, "select sleep(1), 1", $callback, $error_callback);
$mysql_async->addQueryEvent($event_base, $event_2, "select sleep(2), 2", $callback, $error_callback);

event_base_loop($event_base);
```
