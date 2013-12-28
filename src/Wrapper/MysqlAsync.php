<?php
/**
 * MysqlAsync
 * Provide a high level wrapper for asyncnorous MySQL query
 * 
 * @author haiwenzhu <bugwhen@gmail.com>
 */

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
    
    /**
     * @var SplObjectStorage
     */
    private $_connection_pool;
    
    /**
     * @var SplObjectStorage
     */
    private $_callback;
    
    private $_timeout;
    private $_running;

    const DB_CONNECTION_IDLE = 0;
    const DB_CONNECTION_BUSY = 1;
    const TIMER_TICK = 0.01;
    
    const IDX_CALLBACK = 0;
    const IDX_ERROR_CALLBACK = 1;
    const IDX_EVENT = 2;

    public function __construct($host = null, $user = null, $password = null, $dbname = null, $port = null)
    {
        $this->_host = $host;
        $this->_user = $user;
        $this->_password = $password;
        $this->_dbname = $dbname;
        $this->_port = $port;
        $this->_connection_pool = new SplObjectStorage();
        $this->_callback = new SplObjectStorage();
        $this->_timeout = null;
        $this->_running = false;
    }
    
    /**
     * asynchornous query
     *
     * @param string $sql
     * @param callable $callback
     * @param callable $error_callback
     * @param mysqli $db
     * @return mixed
     */
    public function query($sql, $callback = null, $error_callback = null, $db = null)
    {
    	return $this->_query($sql, $callback, $error_callback, $db);
    }
    
	private function _query($sql, $callback = null, $error_callback = null, $db = null, $event = null)
    {
    	$db = empty($db) ? $this->_getDb() : $db;
    	if (!empty($callback) && !is_callable($callback)) {
    		trigger_error("param callback is not callable", E_USER_ERROR);
    	}
    	if (!empty($error_callback) && !is_callable($error_callback)) {
    		trigger_error("param callback is not callable", E_USER_ERROR);
    	}
    	$this->_setCallback($db, $callback, $error_callback, $event);
    	return $db->query($sql, MYSQLI_ASYNC);
    }
    
    /**
     * run query
     *
     * @param float $timeout
     * @return bool
     */
    public function loop($timeout = null)
    {
    	if ($this->_running) {
    		return;
    	}
    	$this->_setTimeout($timeout);
    	return $this->_loop();
    }
    
    public function loopForEvent()
    {
    	$timeout = func_get_arg(2);
    	$this->loop($timeout);
    }
    
	/**
	 * add asyncornous query event
	 * 
	 * @param event_base $event_base
	 * @param event $event
	 * @param string $sql
	 * @param callable $callback
	 * @param callable $error_callback
	 * @param mysqli $link
	 * @return null
	 */
    public function addQueryEvent($event_base, $event, $sql, $callback, $error_callback = null, $link = null)
    {
    	$link = $this->_getDb();
    	$link_stream = mysql_async_get_socket($link);
    	$this->_query($sql, $callback, $error_callback, $link, $event);
    	event_set($event, mysql_async_get_socket($link), EV_READ, array($this, 'loopForEvent'), self::TIMER_TICK);
    	event_base_set($event, $event_base);
    	event_add($event);
    }
    
    /**
     * check the active query's result
     * return true when all query completed, return false otherwise
     * 
     * @return bool
     */
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

    /**
     * connect database
     */
    public function connect($host = null, $user = null, $password = null, $dbname = null, $port = null)
    {
        return $this->_connect($host, $user, $password, $dbname, $port);
    }

    /**
     * connect database
     * use default param when param is null
     * 
     * @return mysqli
     */
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

    /**
     * get database links which query is uncompleted
     * 
     * @return array
     */
    private function _getActiveLinks()
    {
        $links = array();
        foreach ($this->_callback as $val) {
            $links[] = $val;
        }
        return $links;
    }

    /**
     * get count of uncompleted database links
     * 
     * @return int
     */
    private function _getActiveLinksCount()
    {
        $count = 0;
        foreach ($this->_callback as $val) {
            $count++;
        }
        return $count;
    }

    /**
     * get current time
     * 
     * @return float
     */
    private function _getTime()
    {
        return microtime(true);
    }

    /**
     * set time out for the loop action
     */
    private function _setTimeout($timeout)
    {
        if (!empty($timeout)) {
            $this->_timeout = $this->_getTime() + (float)$timeout;
        }
    }

    /**
     * check timeout
     * 
     * @return bool
     */
    private function _getTimeout()
    {
        $timeout = !empty($this->_timeout) ? $this->_getTime() > $this->_timeout : false;
        if ($timeout) {
            $this->_timeout = null;
        }
        return $timeout;
    }

    /**
     * set callback for a specific query
     * 
     * @param mysqli $link
     * @param callable $callback
     * @param callable $error_callback
     */
    private function _setCallback($link, $callback, $error_callback, $event)
    {
    	$this->_callback[$link] = array(
    			self::IDX_CALLBACK => $callback,
    			self::IDX_ERROR_CALLBACK => $error_callback,
    			self::IDX_EVENT => $event
    	);
        if (isset($this->_connection_pool[$link])) {
            $this->_connection_pool[$link] = self::DB_CONNECTION_BUSY;
        }
    }

    /**
     * invoke callback for a specific link
     */
    private function _invokeCallback($link)
    {
        $result = $link->reap_async_query();
        if ($result !== false) {
            call_user_func($this->_callback[$link][self::IDX_CALLBACK], $result);
	    	if (!empty($this->_callback[$link][self::IDX_EVENT])) {
	    		event_del($this->_callback[$link][self::IDX_EVENT]);
	    	}
            unset($this->_callback[$link]);
            if (isset($this->_connection_pool[$link])) {
                $this->_connection_pool[$link] = self::DB_CONNECTION_IDLE;
            }
        } else {
            $this->_invokeErrorCallback($link);
        }
    }

    /**
     * invoke error callback for a specific link
     */
    private function _invokeErrorCallback($link)
    {
    	if (!empty($this->_callback[$link][self::IDX_EVENT])) {
    		event_del($this->_callback[$link][self::IDX_EVENT]);
    	}
        if (!empty($this->_callback[$link][self::IDX_ERROR_CALLBACK])) {
            call_user_func($this->_callback[$link][self::IDX_ERROR_CALLBACK], $link->error, $link->errno);
            unset($this->_callback[$link]);
            if (isset($this->_connection_pool[$link])) {
                $this->_connection_pool[$link] = self::DB_CONNECTION_IDLE;
            }
        }
    }

    /**
     * get a idle database link from the connetion pool
     */
    private function _getDb()
    {
        $db = null;
        foreach ($this->_connection_pool as $key => $val) {
            if ($val === self::DB_CONNECTION_IDLE) {
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
