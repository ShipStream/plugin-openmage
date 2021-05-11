<?php

/**
 * Magento Soap Client
 *
 * @method string startSession()
 * @method bool endSession(string $sessionId)
 * @method string login(string $username, string $apiKey)
 * @method array multiCall(string $sessionId, array $calls, array $options)
 * @method array resources(string $sessionId)
 * @method array resourceFaults(string $sessionId, string $resourceName)
 * @method array globalFaults(string $sessionId)
 */
class ShipStream_Magento1_Client extends SoapClient
{
    const ERROR_SESSION_EXPIRED = 5;

    /** @var null|string */
    protected $_sessionId;

    /** @var array */
    protected $_config = array();

    /**
     * Soap client constructor
     *
     * @param array $config
     * @param array|null $options
     * @throws Plugin_Exception
     */
    public function __construct(array $config, $options = NULL)
    {
        if ( ! extension_loaded('soap')) {
            throw new Plugin_Exception('SOAP extension is not loaded.');
        }

        foreach (['base_url', 'login', 'password'] as $key) {
            if (empty($config[$key])) {
                throw new Plugin_Exception(sprintf('Configuration parameter \'%s\' is required.', $key));
            }
        }
        $this->_config = $config;

        try {
            parent::__construct($this->_config['base_url'].'?wsdl=1', $options);
        } catch (SoapFault $e) {
            throw new Plugin_Exception('WSDL URI cannot be loaded: '. $e->getMessage());
        }
    }

    /**
     * Wrapper for "call" method
     *
     * @param string $method
     * @param array $args
     * @param bool $canRetry
     * @throws Plugin_Exception
     * @return mixed
     */
    public function call($method, $args = array(), $canRetry = TRUE)
    {
        $this->_login();
        try {
            $return = $this->__soapCall('call', [$this->_sessionId, $method, $args]);
        } catch (SoapFault $e) {
            $faultCode = isset($e->faultcode) ? (int)$e->faultcode : 0;
            if ($faultCode == self::ERROR_SESSION_EXPIRED) {
                $this->_sessionId = FALSE;
                if ($canRetry) {
                    $this->_login();
                    return $this->call($method, $args, FALSE);
                }
            }
            throw new Plugin_Exception(sprintf('(%d) %s', $faultCode, $e->getMessage()), $faultCode);
        }
        return $return;
    }

    /**
     * Destructor
     */
    public function __destruct()
    {
        if ($this->_sessionId) {
            $this->endSession($this->_sessionId); // For already expired sessions the exception is not thrown
        }
    }

    /**
     * Start session
     *
     * @return $this
     * @throws Plugin_Exception
     */
    protected function _login()
    {
        try {
            if ( ! $this->_sessionId) {
                $this->_sessionId = $this->login($this->_config['login'], $this->_config['password']);
            }
        } catch (SoapFault $e) {
            throw new Plugin_Exception('Login failed: '.$e->getMessage());
        }
        return $this;
    }
}
