<?php

namespace Acesync;

use Alert\Reactor,
    After\Failure,
    After\Success,
    After\Deferred;

class Encryptor {
    private $reactor;
    private $pending = [];
    private $isLegacy;
    private $defaultCryptoMethod;
    private $defaultCaFile;
    private $defaultCiphers;
    private $msCryptoTimeout = 10000;

    /**
     * @param \Alert\Reactor $reactor
     */
    public function __construct(Reactor $reactor) {
        $this->reactor = $reactor;
        $this->isLegacy = $isLegacy = (PHP_VERSION_ID < 50600);
        $this->defaultCaFile = __DIR__ . '/../vendor/bagder/ca-bundle/ca-bundle.crt'
        $this->defaultCryptoMethod = $isLegacy
            ? STREAM_CRYPTO_METHOD_SSLv23_CLIENT
            : STREAM_CRYPTO_METHOD_ANY_CLIENT;
        $this->defaultCiphers = implode(':', [
            'ECDHE-RSA-AES128-GCM-SHA256',
            'ECDHE-ECDSA-AES128-GCM-SHA256',
            'ECDHE-RSA-AES256-GCM-SHA384',
            'ECDHE-ECDSA-AES256-GCM-SHA384',
            'DHE-RSA-AES128-GCM-SHA256',
            'DHE-DSS-AES128-GCM-SHA256',
            'kEDH+AESGCM',
            'ECDHE-RSA-AES128-SHA256',
            'ECDHE-ECDSA-AES128-SHA256',
            'ECDHE-RSA-AES128-SHA',
            'ECDHE-ECDSA-AES128-SHA',
            'ECDHE-RSA-AES256-SHA384',
            'ECDHE-ECDSA-AES256-SHA384',
            'ECDHE-RSA-AES256-SHA',
            'ECDHE-ECDSA-AES256-SHA',
            'DHE-RSA-AES128-SHA256',
            'DHE-RSA-AES128-SHA',
            'DHE-DSS-AES128-SHA256',
            'DHE-RSA-AES256-SHA256',
            'DHE-DSS-AES256-SHA',
            'DHE-RSA-AES256-SHA',
            'AES128-GCM-SHA256',
            'AES256-GCM-SHA384',
            'ECDHE-RSA-RC4-SHA',
            'ECDHE-ECDSA-RC4-SHA',
            'AES128',
            'AES256',
            'RC4-SHA',
            'HIGH',
            '!aNULL',
            '!eNULL',
            '!EXPORT',
            '!DES',
            '!3DES',
            '!MD5',
            '!PSK'
        ]);
    }

    /**
     * Encrypt the specified socket using settings from the $options array
     *
     * @param resource $socket
     * @param array $options
     * @return \After\Promise
     */
    public function enable($socket, array $options) {
        $socketId = (int) $socket;

        if (isset($this->pending[$socketId])) {
            return new Failure(new CryptoException(
                'Cannot enable crypto: operation currently in progress for this socket'
            ));
        }

        $streamType = stream_get_meta_data($socket)['stream_type'];
        if ($streamType !== 'tcp_socket/ssl') {
            return new Failure(new \DomainException(
                sprintf('Cannot encrypt invalid stream type (%s); tcp_socket/ssl expected', $streamType)
            ));
        }

        if ($this->isLegacy) {
            $options = $this->normalizeLegacyCryptoOptions($options);
        } elseif (empty($options['cafile'])) {
            // Don't explicitly trust OS certs in 5.6+
            $options['cafile'] = $this->defaultCaFile;
        }

        $existingContext = @stream_context_get_options($socket)['ssl'];

        if ($this->isContextOptionMatch($existingContext, $options)) {
            return new Success($socket);
        } elseif ($existingContext && empty($existingContext['SNI_nb_hack'])) {
            // If crypto was previously enabled for this socket we need to disable
            // it before we can negotiate the new options.
            return $this->renegotiate($socket, $options);
        }

        $options['SNI_nb_hack'] = false;
        stream_context_set_option($socket, ['ssl'=> $options]);

        if ($result = $this->doEnable($socket)) {
            return new Success($socket);
        } elseif ($result === false) {
            return new Failure($this->generateErrorException());
        } else {
            return $this->watch($socket, 'doEnable');
        }
    }

    private function isContextOptionMatch(array $a, array $b) {
        unset($a['SNI_nb_hack'], $b['SNI_nb_hack'], $a['peer_certificate'], $b['peer_certificate']);

        return ($a == $b);
    }

    private function normalizeLegacyCryptoOptions(array $options) {
        // For pre-5.6 we always manually verify names in userland using the captured
        // peer certificate
        $options['capture_peer_cert'] = true;
        if (isset($options['CN_match'])) {
            $peerName = $options['CN_match'];
            $options['peer_name'] = $peerName;
            unset($options['CN_match']);
        }
        if (empty($options['cafile'])) {
            $options['cafile'] = $this->defaultCaFile;
        }
        if (empty($options['ciphers'])) {
            $options['ciphers'] = $this->defaultCiphers;
        }

        return $options;
    }

    private function renegotiate($socket, $options) {
        $deferred = new Deferred;
        $deferredDisable = $this->disable($socket);
        $deferredDisable->onResolve(function($error, $result) use ($deferred, $options) {
            if ($error) {
                $deferred->fail(new CryptoException(
                    'Failed renegotiating crypto',
                    0,
                    $error
                ));
            } else {
                $deferredEnable = $this->encrypt($result, $options);
                $deferredEnable->onResolve(function($error, $result) use ($deferred) {
                    return $error ? $deferred->fail($error) : $deferred->succeed($result);
                });
            }
        });

        return $deferred->promise();
    }

    private function doEnable($socket) {
        return $this->isLegacy
            ? $this->doLegacyEnable($socket)
            : @stream_socket_enable_crypto($socket, true);
    }

    private function doLegacyEnable($socket) {
        $cryptoOpts = stream_context_get_options($socket)['ssl'];
        $cryptoMethod = empty($cryptoOpts['crypto_method'])
            ? $this->defaultCryptoMethod
            : $cryptoOpts['crypto_method'];

        // If PHP's internal verification routines return false or zero we're finished
        if (!$result = @stream_socket_enable_crypto($socket, true, $cryptoMethod)) {
            return $result;
        }

        $cert = stream_context_get_options($socket)['ssl']['peer_certificate'];
        $certInfo = openssl_x509_parse($cert);

        $peerFingerprint = isset($cryptoOpts['peer_fingerprint'])
            ? $cryptoOpts['peer_fingerprint']
            : null;

        if ($peerFingerprint && !$this->legacyVerifyPeerFingerprint($peerFingerprint, $cert)) {
            @trigger_error('Peer fingerprint verification failed', E_USER_WARNING);
            return false;
        }

        $peerName = isset($cryptoOpts['peer_name'])
            ? $cryptoOpts['peer_name']
            : null;

        if ($peerName && !$this->legacyVerifyPeerName($peerName, $certInfo)) {
            @trigger_error('Peer name verification failed', E_USER_WARNING);
            return false;
        }

        return true;
    }

    private function legacyVerifyPeerFingerprint($peerFingerprint, $cert) {
        if (is_string($peerFingerprint)) {
            $peerFingerprint = [$peerFingerprint];
        } elseif (!is_array($peerFingerprint)) {
            @trigger_error(
                sprintf('Invalid peer_fingerprint; string or array required (%s)', gettype($peerFingerprint)),
                E_USER_WARNING
            );
            return false;
        }

        if (!openssl_x509_export($cert, $str, false)) {
            @trigger_error('Failed exporting peer cert for fingerprint verification', E_USER_WARNING);
            return false;
        }

        if (!preg_match("/-+BEGIN CERTIFICATE-+(.+)-+END CERTIFICATE-+/s", $str, $matches)) {
            @trigger_error('Failed parsing cert PEM for fingerprint verification', E_USER_WARNING);
            return false;
        }

        $pem = $matches[1];
        $pem = base64_decode($pem);

        foreach ($peerFingerprint as $expectedFingerprint) {
            $algo = (strlen($expectedFingerprint) === 40) ? 'sha1' : 'md5';
            $actualFingerprint = openssl_digest($pem, $algo);
            if ($expectedFingerprint === $actualFingerprint) {
                return true;
            }
        }

        return false;
    }

    private function legacyVerifyPeerName($peerName, array $certInfo) {
        if ($this->matchesWildcardName($peerName, $certInfo['subject']['CN'])) {
            return true;
        }

        if (empty($certInfo['extensions']['subjectAltName'])) {
            return false;
        }

        $subjectAltNames = array_map('trim', explode(',', $certInfo['extensions']['subjectAltName']));

        foreach ($subjectAltNames as $san) {
            if (stripos($san, 'DNS:') !== 0) {
                continue;
            }
            $sanName = substr($san, 4);

            if ($this->matchesWildcardName($peerName, $sanName)) {
                return true;
            }
        }

        return false;
    }

    private function matchesWildcardName($peerName, $certName) {
        if (strcasecmp($peerName, $certName) === 0) {
            return true;
        }
        if (!(stripos($certName, '*.') === 0 && stripos($peerName, '.'))) {
            return false;
        }
        $certName = substr($certName, 2);
        $peerName = explode(".", $peerName);
        unset($peerName[0]);
        $peerName = implode(".", $peerName);

        return ($peerName == $certName);
    }

    private function generateErrorException() {
        return new CryptoException(
            sprintf('Crypto error: %s', error_get_last()['message'])
        );
    }

    private function watch($socket, $func) {
        $socketId = (int) $socket;
        $encryptorStruct = new EncryptorStruct;
        $encryptorStruct->id = $socketId;
        $encryptorStruct->socket = $socket;
        $encryptorStruct->deferred = new Deferred;
        $encryptorStruct->pendingWatcher = $this->reactor->onReadable($socket, function() use ($encryptorStruct, $func) {
            $socket = $encryptorStruct->socket;
            if ($result = $this->{$func}($socket)) {
                $encryptorStruct->deferred->succeed($socket);
                $this->unloadPendingStruct($encryptorStruct);
            } elseif ($result === false) {
                $encryptorStruct->deferred->fail($this->generateErrorException());
                $this->unloadPendingStruct($encryptorStruct);
            }
        });
        $encryptorStruct->timeoutWatcher = $this->reactor->once(function() use ($encryptorStruct) {
            $encryptorStruct->deferred->fail(new TimeoutException(
                sprintf('Crypto timeout exceeded: %d ms', $this->msCryptoTimeout)
            ));
            $this->unloadPendingStruct($encryptorStruct);
        }, $this->msCryptoTimeout);

        $this->pending[$socketId] = $encryptorStruct;

        return $encryptorStruct->deferred;
    }

    private function unloadPendingStruct(EncryptorStruct $encryptorStruct) {
        $socketId = $encryptorStruct->id;
        unset($this->pending[$socketId]);
        $this->reactor->cancel($encryptorStruct->pendingWatcher);
        $this->reactor->cancel($encryptorStruct->timeoutWatcher);
    }

    /**
     * Disable crypto on the specified socket
     *
     * @param resource $socket
     * @return \After\Promise
     */
    public function disable($socket) {
        $socketId = (int) $socket;

        if (isset($this->pending[$socketId])) {
            return new Failure(new CryptoException(
                'Cannot disable crypto: operation currently in progress for this socket'
            ));
        }

        // @TODO This may be unnecessary. Decide if it is.
        if (!@stream_context_get_options($socket)['ssl']) {
            // If crypto is already disabled we're finished here
            return new Success($socket);
        } elseif ($result = $this->doDisable($socket)) {
            return new Success($socket);
        } elseif ($result === false) {
            return new Failure($this->generateErrorException());
        } else {
            return $this->watch($socket, 'doDisable');
        }
    }

    private function doDisable($socket) {
        return @stream_socket_enable_crypto($socket, false);
    }
}
