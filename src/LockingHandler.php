<?php

/*
 * This file is used to overRide the SessionHandlerInterface of Predis package and introduce locking to our sessions.
 *
 * @author: Doram Greenblat <doram.greenblat@payfast.co.za>
 */

namespace demo;

use Predis\ClientInterface;

/**
 * Session handler class that relies on Predis\Client to store PHP's sessions
 * data into one or multiple Redis servers.
 *
 * @author Doram Greenblat <doram.greenblat@payfast.co.za>
 * Extended to facilitate locking using adaptation of the algorithm of the lsw memcached handler located at
 * https://github.com/LeaseWeb/LswMemcacheBundle/blob/master/Session/Storage/LockingSessionHandler.php
 *
 *
 */
class LockingHandler extends \Predis\Session\Handler implements \SessionHandlerInterface
{

    const DEFAULT_MAX_EXECUTION_TIME = 30;

    private $prefix;

    /**
     * @var string|null Идентификатор последней заблокированной сессии
     */
    private $lock_key;
    private $spin_lock_wait;
    private $lock_max_wait;
    private $instance_id;

    /**
     * List of available options:
     * @param ClientInterface $client Fully initialized client instance.
     * @param array $options Session handler options.
     */
    public function __construct(ClientInterface $client, array $options = array())
    {

        parent::__construct($client, $options);
        $this->prefix = "session";
        $this->lock_key = null;
        $this->spin_lock_wait = rand(100000, 300000);
        $this->lock_max_wait = ini_get('max_execution_time');

        if ($this->lock_max_wait == 0) {
            $this->lock_max_wait = self::DEFAULT_MAX_EXECUTION_TIME;
        }

        $hostname = gethostname();

        if (!$hostname) {
            throw new \RuntimeException('Не удалось установить имя хоста.');
        }

        $this->instance_id = $hostname . getmypid();

    }


    /**
     * lockSession
     * Creates a Session Lock
     * Algorithm loosely Based on lsw memcached handler
     * @access private
     * @param string $session_id
     * @return Boolean
     * @author: Doram Greenblat <doram.greenblat@payfast.co.za>
     */
    private function lockSession(string $session_id)
    {
        $attempts = intval((1000000 / $this->spin_lock_wait) * $this->lock_max_wait);
        $this->lock_key = $session_id . '.lock';
        $iterations = 0;
        $lock_attained = false;
        while (($iterations < $attempts) && (!$lock_attained)) {
            $iterations++;
            if (!$this->checkLock()) {
                $this->client->setnx($this->prefix . $this->lock_key, $this->instance_id);
                if ($this->client->get($this->prefix . $this->lock_key) == $this->instance_id) {
                    $this->client->expire($this->prefix . $this->lock_key, $this->lock_max_wait);
                    $lock_attained = true;
                }
            } else {
                usleep($this->spin_lock_wait);
            }
        }

        return $lock_attained;
    }

    /**
     * unLockSession
     * Releases a Session Lock
     * Algorithm loosely Based on lsw memcached handler
     * @access private
     * @param boolean An Override to force session Unlock, not in use but could be if we ever encounter deadlocks.
     * @return boolean
     * @author: Doram Greenblat <doram.greenblat@payfast.co.za>
     */
    private function unLockSession($force = false): bool
    {
        $return = false;
        // Prevent other Users from closing $this session.
        if (($force == true) || ($this->checkLock() && ($this->client->get($this->prefix . $this->lock_key)) == $this->instance_id)) {
            $this->client->del($this->prefix . $this->lock_key);
            $return = true;
        }

        return $return;
    }

    /**
     * checkLock
     * Checks Status of Lock in Redis DB
     * @access private
     * @return boolean
     * @author: Doram Greenblat <doram.greenblat@payfast.co.za>
     */
    private function checkLock(): bool
    {
        $return = false;
        if ($this->client->exists($this->prefix . $this->lock_key)) {
            $return = true;
        }

        return $return;
    }


    /**
     * read
     * Called by PHP Session magic.
     * Inspects Lock status and implements then reads and returns data
     * @access public
     * @param string passed by php
     * @return string
     * @author: Doram Greenblat <doram.greenblat@payfast.co.za>
     */
    public function read($session_id): string
    {
        if (!$this->lockSession($session_id)) {
            throw new \RuntimeException('Session locking failed.');
        }

        return parent::read($this->prefix . $session_id);

    }

    public function write($session_id, $session_data)
    {
        return parent::write($this->prefix . $session_id, $session_data);
    }

    /**
     * close
     * Closes Session and calls session release Lock
     * @access public
     * @return boolean
     * @author: Doram Greenblat <doram.greenblat@payfast.co.za>
     */
    public function close()
    {
        return $this->unLockSession();
    }


    /**
     * destroy
     * Kills Session record and any associated Lock entry
     * @access public
     * @param $session_id
     * @return boolean
     * @author: Doram Greenblat <doram.greenblat@payfast.co.za>
     */
    public function destroy($session_id)
    {
        $this->client->del($this->prefix . $session_id);
        $this->close();

        return true;
    }

}