<?php

namespace Wrapper;

use mysqli;
use SplObjectStorage;

class MysqlAsync
{
    private $_host;
    private $_user;
    private $_password;
    private $_dbname;
    private $_port;
    private $_connection_pool;
    private $_callback;
    private $_timeout;
    private $_running;

    const DB_CONNECTION_FREE = 0;
    const DB_CONNECTION_BUSY = 1;
    const TIMER_TICK = 0.01;

    public function __construct($host = null, $user = null, $password = null, $dbname = null, $port = null)
    {
        $this->_timeout = null;
        $this->_running = false;
        $this->_host = $host;
        $this->_user = $user;
        $this->_password = $password;
        $this->_dbname = $dbname;
        $this->_port = $port;
        $this->_connection_pool = new SplObjectStorage();
        $this->_callback = new SplObjectStorage();
    }

    public function connect($host = null, $user = null, $password = null, $dbname = null, $port = null)
    {
        return $this->_connect($host, $user, $password, $dbname, $port);
    }

    protected function _connect($host, $user, $password, $dbname, $port)
    {
        $host = $host !== null ? $host : ini_get('mysqli.default_host');
        $user = $user !== null ? $user : ini_get('mysqli.default_user');
        $password = $password !== null ? $password : ini_get('mysqli.default_pw');
        $port = $port !== null ? $port : ini_get('mysqli.default_port');
        $db = new mysqli($host, $user, $password, $dbname, $port);
        if ($db->connect_error) {
            trigger_error("db connect error({$db->connect_errno}) {$db->connect_error}", E_USER_ERROR);
        }
        return $db;
    }

    public function query($sql, $callback = null, $error_callback, $db = null)
    {
        $db = empty($db) ? $this->_getDb() : $db;
        if (!empty($callback) && !is_callable($callback)) {
            trigger_error("param callback is not callable", E_USER_ERROR);
        }
        if (!empty($error_callback) && !is_callable($error_callback)) {
            trigger_error("param callback is not callable", E_USER_ERROR);
        }
        $this->_setCallback($db, $callback, $error_callback);
        return $db->query($sql, MYSQLI_ASYNC);
    }

    public function loop($timeout = null)
    {
        if ($this->_running) {
            return;
        }
        $this->_setTimeout($timeout);
        return $this->_loop();
    }

    private function _loop()
    {
        $this->_running = true;
        while (!$this->_getTimeout() && $this->_getActiveLinksCount() > 0) {
            $read = $error = $reject = $this->_getActiveLinks();
            if (mysqli::poll($read, $error, $reject, self::TIMER_TICK) === false) {
                trigger_error("mysql poll error", E_USER_ERROR);
            }
            array_walk($read, array($this, '_invokeCallback'));
            array_walk($error, array($this, '_invokeErrorCallback'));
        }
        $this->_running = false;
        return $this->_getActiveLinksCount() > 0 ? false : true;
    }

    private function _getActiveLinks()
    {
        $links = array();
        foreach ($this->_callback as $val) {
            $links[] = $val;
        }
        return $links;
    }

    private function _getActiveLinksCount()
    {
        $count = 0;
        foreach ($this->_callback as $val) {
            $count++;
        }
        return $count;
    }

    private function _getTime()
    {
        return microtime(true);
    }

    private function _setTimeout($timeout)
    {
        if (!empty($timeout)) {
            $this->_timeout = $this->_getTime() + (float)$timeout;
        }
    }

    private function _getTimeout()
    {
        $timeout = !empty($this->_timeout) ? $this->_getTime() > $this->_timeout : false;
        if ($timeout) {
            $this->_timeout = null;
        }
        return $timeout;
    }

    private function _setCallback($link, $callback, $error_callback)
    {
        $this->_callback[$link] = array($callback, $error_callback);
        if (isset($this->_connection_pool[$link])) {
            $this->_connection_pool[$link] = self::DB_CONNECTION_BUSY;
        }
    }

    private function _invokeCallback($link)
    {
        $result = $link->reap_async_query();
        if ($result !== false) {
            call_user_func($this->_callback[$link][0], $result);
            unset($this->_callback[$link]);
            if (isset($this->_connection_pool[$link])) {
                $this->_connection_pool[$link] = self::DB_CONNECTION_FREE;
            }
        } else {
            $this->_invokeErrorCallback($link);
        }
    }

    private function _invokeErrorCallback($link)
    {
        if (!empty($this->_callback[$link][1])) {
            call_user_func($this->_callback[$link][1], $link->error, $link->errno);
            unset($this->_callback[$link]);
            if (isset($this->_connection_pool[$link])) {
                $this->_connection_pool[$link] = self::DB_CONNECTION_FREE;
            }
        }
    }

    private function _getDb()
    {
        $db = null;
        foreach ($this->_connection_pool as $key => $val) {
            if ($val === self::DB_CONNECTION_FREE) {
                return $key;
            }
        }
        
        if (empty($db)) {
            $db = $this->_connect($this->_host, $this->_user, $this->_password, $this->_dbname, $this->_port);
            if (empty($db)) {
                trigger_error("connect to {$this->_host} failed", E_USER_ERROR);
            }
            $this->_connection_pool[$db] = self::DB_CONNECTION_BUSY;
        }
            
        return $db;
    }
}
