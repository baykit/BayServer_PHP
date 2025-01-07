<?php

namespace baykit\bayserver\docker\built_in;



use baykit\bayserver\agent\GrandAgent;
use baykit\bayserver\agent\multiplexer\SecureTransporter;
use baykit\bayserver\BayLog;
use baykit\bayserver\BayServer;
use baykit\bayserver\common\Transporter;
use baykit\bayserver\ConfigException;
use baykit\bayserver\docker\base\DockerBase;
use baykit\bayserver\docker\Secure;
use baykit\bayserver\ship\Ship;
use baykit\bayserver\util\IOException;
use baykit\bayserver\util\StringUtil;

class BuiltInSecureDocker extends DockerBase implements  Secure  {
    const DEFAULT_CLIENT_AUTH = false;
    const DEFAULT_SSL_PROTOCOL = "TLS";

    public $keyStore;
    public $keyStorePass;
    public $clientAuth = self::DEFAULT_CLIENT_AUTH;
    public $sslProtocol = self::DEFAULT_SSL_PROTOCOL;
    public $keyFile;
    public $certFile;
    public $certs;
    public $certsPass;
    public $traceSsl = false;
    public $sslctx;
    public $appProtocols = [];

    //////////////////////////////////////////////////////
    // Implements Docker
    //////////////////////////////////////////////////////

    public function init($elm, $parent) : void
    {
        parent::init($elm, $parent);

        if (($this->keyStore === null) and (($this->keyFile === null) or ($this->certFile === null)))
            throw new ConfigException($elm->fileName, $elm->lineNo, "Key file or cert file is not specified");

        try {
            $this->initSsl();
        }
        catch (\Exception $e) {
            throw $e;
        }
    }

    //////////////////////////////////////////////////////
    // Implements DockerBase
    //////////////////////////////////////////////////////

    public function initKeyVal($kv) : bool
    {
        $key = strtolower($kv->key);

        switch ($key) {
            case "key":
                $this->keyFile = $this->getFilePath($kv->value);
                break;

            case "cert":
                $this->certFile = $this->getFilePath($kv->value);
                break;

            case "keystore":
                $this->keyStore = $this->getFilePath($kv->value);
                break;

            case "keystorepass":
                $this->keyStorePass = $kv->value;
                break;

            case "clientauth":
                $this->clientAuth = StringUtil::parseBool($kv->value);
                break;

            case "sslprotocol":
                $this->sslProtocol = $kv->value;
                break;

            case "trustcerts":
                $this->certs = $this->getFilePath($kv->value);
                break;

            case "certspass":
                $this->certsPass = $kv->value;
                break;

            case "tracessl":
                $this->traceSsl = StringUtil::parseBool($kv->value);
                break;

            default:
                return false;
        }
        return true;
    }


    //////////////////////////////////////////////////////
    // Implements Secure
    //////////////////////////////////////////////////////


    public function setAppProtocols(string $protocols) : void
    {
        $this->appProtocols = $protocols;
        stream_context_set_option($this->sslctx, 'ssl', 'alpn_protocols', $protocols);
    }

    public function newTransporter(int $agtId, Ship $sip, int $bufsiz) : Transporter
    {
        $agt = GrandAgent::get($agtId);
        $tp = new SecureTransporter(
            $agt->netMultiplexer,
            $sip,
            true,
            $bufsiz,
            $this->traceSsl,
            $this->sslctx);

        $tp->init();
        return $tp;
    }

    public function reloadCert() : void
    {
        $this->initSsl();
    }

    public function initSsl()
    {
        BayLog::debug("init ssl");
        #$this->sslctx = ssl.create_default_context(ssl.Purpose.SERVER_AUTH)
        $this->sslctx = stream_context_create();

        if ($this->keyStore === null) {
            if(!file_exists($this->certFile))
                throw new IOException("Cert file not found: " . $this->certFile);
            if(!file_exists($this->keyFile))
                throw new IOException("Key file not found: " . $this->keyFile);
            stream_context_set_option($this->sslctx, 'ssl', 'local_cert', $this->certFile);
            stream_context_set_option($this->sslctx, 'ssl', 'local_pk', $this->keyFile);
            //stream_context_set_option($context, 'ssl', 'passphrase', $pem_passphrase);
            //$this->sslctx.load_cert_chain(certfile=$this->cert_file, keyfile=$this->key_file)
        }

        stream_context_set_option($this->sslctx, 'ssl', 'allow_self_signed', true);
        stream_context_set_option($this->sslctx, 'ssl', 'verify_peer', false);
        stream_context_set_option($this->sslctx, 'ssl', 'verify_peer_name', false);

        stream_context_set_params($this->sslctx, array("notification" => function(){stream_notification_callback();}));
    }


    //////////////////////////////////////////////////////
    // Other methods
    //////////////////////////////////////////////////////

    private function getFilePath(string $fileName) : string
    {
        $path = BayServer::parsePath($fileName);
        if ($path == null)
            throw new IOException("File not found: " . $fileName);

        return $path;
    }
}