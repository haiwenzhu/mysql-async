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

##Usage
```php
<?php
require('./src/Wrapper/MysqlAsync.php');

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
You can specific a timeout param for the `loop` method, which will return true when all query completed, otherwise return fasel.
