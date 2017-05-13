<?php

namespace Amp\Socket;

use Amp\Deferred;
use Amp\Loop;
use Amp\Promise;
use Amp\Struct;
use Amp\Success;

/** @internal */
class SocketPoolStruct {
    use Struct;

    public $id;
    public $uri;
    public $resource;
    public $isAvailable;
    public $idleWatcher;
    public $idleTimeout;
}

class SocketPool {
    const OP_HOST_CONNECTION_LIMIT = "amp.socket.socketpool.host-connection-limit";
    const OP_IDLE_TIMEOUT = "amp.socket.socketpool.idle-timeout";
    const OP_CONNECT_TIMEOUT = "amp.socket.socketpool.connect-timeout";
    const OP_BINDTO = "amp.socket.socketpool.bindto";

    private $sockets = [];
    private $queuedSocketRequests = [];
    private $socketIdUriMap = [];
    private $pendingSockets = [];
    private $options = [
        self::OP_HOST_CONNECTION_LIMIT => 8,
        self::OP_IDLE_TIMEOUT => 10000,
        self::OP_CONNECT_TIMEOUT => 10000,
        self::OP_BINDTO => "",
    ];
    private $needsRebind;

    /**
     * Checkout a socket from the specified URI authority.
     *
     * The resulting socket resource should be checked back in via `SocketPool::checkin()` once the calling code is
     * finished with the stream (even if the socket has been closed). Failure to checkin sockets will result in memory
     * leaks and socket queue blockage.
     *
     * @param string $uri A string of the form example.com:80 or 192.168.1.1:443
     * @param array  $options
     *
     * @return \Amp\Promise Returns a promise that resolves to a socket once a connection is available
     */
    public function checkout(string $uri, array $options = []): Promise {
        $uri = stripos($uri, 'unix://') === 0 ? $uri : strtolower($uri);
        $options = $options ? array_merge($this->options, $options) : $this->options;

        $socket = $this->checkoutExistingSocket($uri, $options);

        return $socket ? new Success($socket) : $this->checkoutNewSocket($uri, $options);
    }

    private function checkoutExistingSocket($uri, $options) {
        if (empty($this->sockets[$uri])) {
            return null;
        }

        $needsRebind = false;

        foreach ($this->sockets[$uri] as $socketId => $poolStruct) {
            if (!$poolStruct->isAvailable) {
                continue;
            } elseif ($this->isSocketDead($poolStruct->resource)) {
                unset($this->sockets[$uri][$socketId]);
            } elseif (($bindToIp = @stream_context_get_options($poolStruct->resource)['socket']['bindto'])
                && ($bindToIp === $options[self::OP_BINDTO])
            ) {
                $poolStruct->isAvailable = false;
                if (isset($poolStruct->idleWatcher)) {
                    Loop::disable($poolStruct->idleWatcher);
                }
                return $poolStruct->resource;
            } elseif ($bindToIp) {
                $needsRebind = true;
            } else {
                $poolStruct->isAvailable = false;
                if (isset($poolStruct->idleWatcher)) {
                    Loop::disable($poolStruct->idleWatcher);
                }

                return $poolStruct->resource;
            }
        }

        $this->needsRebind = $needsRebind;

        return null;
    }

    private function checkoutNewSocket($uri, $options) {
        $needsRebind = $this->needsRebind;
        $this->needsRebind = null;
        $deferred = new Deferred;

        if ($this->allowsNewConnection($uri, $options) || $needsRebind) {
            $this->initializeNewConnection($deferred, $uri, $options);
        } else {
            $this->queuedSocketRequests[$uri][] = [$deferred, $uri, $options];
        }

        return $deferred->promise();
    }

    private function allowsNewConnection($uri, $options) {
        $maxConnectionsPerHost = $options[self::OP_HOST_CONNECTION_LIMIT];

        if ($maxConnectionsPerHost <= 0) {
            return true;
        }

        $pendingCount = isset($this->pendingSockets[$uri]) ? $this->pendingSockets[$uri] : 0;
        $existingCount = isset($this->sockets[$uri]) ? count($this->sockets[$uri]) : 0;
        $totalCount = $pendingCount + $existingCount;

        if ($totalCount < $maxConnectionsPerHost) {
            return true;
        }

        return false;
    }

    private function initializeNewConnection(Deferred $deferred, $uri, $options) {
        $this->pendingSockets[$uri] = isset($this->pendingSockets[$uri])
            ? $this->pendingSockets[$uri] + 1
            : 1;
        $futureSocket = \Amp\Socket\rawConnect($uri, $options);
        $futureSocket->onResolve(function ($error, $socket) use ($deferred, $uri, $options) {
            if ($error) {
                $deferred->fail($error);
            } else {
                $this->finalizeNewConnection($deferred, $uri, $socket, $options);
            }
        });
    }

    private function finalizeNewConnection(Deferred $deferred, $uri, $socket, $options) {
        if (--$this->pendingSockets[$uri] === 0) {
            unset($this->pendingSockets[$uri]);
        }

        $socketId = (int) $socket;
        $poolStruct = new SocketPoolStruct;
        $poolStruct->id = $socketId;
        $poolStruct->uri = $uri;
        $poolStruct->resource = $socket;
        $poolStruct->isAvailable = false;
        $poolStruct->idleTimeout = $options[self::OP_IDLE_TIMEOUT];
        $this->sockets[$uri][$socketId] = $poolStruct;
        $this->socketIdUriMap[$socketId] = $uri;
        $deferred->resolve($poolStruct->resource);

        if (empty($this->queuedSocketRequests[$uri])) {
            unset($this->queuedSocketRequests[$uri]);
        }
    }

    /**
     * Remove the specified socket from the pool.
     *
     * @param resource $resource
     *
     * @return self
     */
    public function clear($resource): self {
        $socketId = (int) $resource;
        if (isset($this->socketIdUriMap[$socketId])) {
            $uri = $this->socketIdUriMap[$socketId];
            $this->unloadSocket($uri, $socketId);
        }

        return $this;
    }

    private function unloadSocket($uri, $socketId) {
        if (!isset($this->sockets[$uri][$socketId])) {
            return;
        }

        $poolStruct = $this->sockets[$uri][$socketId];
        if ($poolStruct->idleWatcher) {
            Loop::cancel($poolStruct->idleWatcher);
        }
        unset(
            $this->sockets[$uri][$socketId],
            $this->socketIdUriMap[$socketId]
        );

        if (empty($this->sockets[$uri])) {
            unset($this->sockets[$uri][$socketId]);
        }

        if (!empty($this->queuedSocketRequests[$uri])) {
            $this->dequeueNextWaitingSocket($uri);
        }
    }

    private function dequeueNextWaitingSocket($uri) {
        $queueStruct = current($this->queuedSocketRequests[$uri]);
        /** @var Deferred $deferred */
        list($deferred, $uri, $options) = $queueStruct;

        if ($socket = $this->checkoutExistingSocket($uri, $options)) {
            array_shift($this->queuedSocketRequests[$uri]);
            $deferred->resolve($socket);
            return;
        }

        if ($this->allowsNewConnection($uri, $options)) {
            array_shift($this->queuedSocketRequests[$uri]);
            $this->initializeNewConnection($deferred, $uri, $options);
        }
    }

    /**
     * Return a previously checked-out socket to the pool.
     *
     * @param resource $resource
     *
     * @throws \Error on resource unknown to the pool
     */
    public function checkin($resource) {
        $socketId = (int) $resource;

        if (!isset($this->socketIdUriMap[$socketId])) {
            throw new \Error(
                sprintf('Unknown socket: %s', $resource)
            );
        }

        $uri = $this->socketIdUriMap[$socketId];

        if ($this->isSocketDead($resource)) {
            $this->unloadSocket($uri, $socketId);
        } else {
            $this->finalizeSocketCheckin($uri, $socketId);
        }
    }

    private function isSocketDead($resource) {
        return !is_resource($resource) || feof($resource);
    }

    private function finalizeSocketCheckin($uri, $socketId) {
        $poolStruct = $this->sockets[$uri][$socketId];
        $poolStruct->isAvailable = true;

        if (!empty($this->queuedSocketRequests[$uri])) {
            $this->dequeueNextWaitingSocket($uri);
        } elseif ($poolStruct->idleTimeout > 0) {
            $this->initializeIdleTimeout($poolStruct);
        }
    }

    private function initializeIdleTimeout(SocketPoolStruct $poolStruct) {
        if (isset($poolStruct->idleWatcher)) {
            Loop::enable($poolStruct->idleWatcher);
        } else {
            $poolStruct->idleWatcher = Loop::delay($poolStruct->idleTimeout, function () use ($poolStruct) {
                $this->unloadSocket($poolStruct->uri, $poolStruct->id);
            });
        }
    }

    /**
     * Set socket pool option.
     *
     * @param string $option
     * @param mixed  $value
     *
     * @throws \Error on unknown option
     */
    public function setOption(string $option, $value) {
        switch ($option) {
            case self::OP_HOST_CONNECTION_LIMIT:
                $this->options[self::OP_HOST_CONNECTION_LIMIT] = (int) $value;
                break;
            case self::OP_CONNECT_TIMEOUT:
                $this->options[self::OP_CONNECT_TIMEOUT] = (int) $value;
                break;
            case self::OP_IDLE_TIMEOUT:
                $this->options[self::OP_IDLE_TIMEOUT] = (int) $value;
                break;
            case self::OP_BINDTO:
                $this->options[self::OP_BINDTO] = $value;
                break;
            default:
                throw new \Error(
                    sprintf('Unknown option: %s', $option)
                );
        }
    }
}
