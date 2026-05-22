<?php

if (!defined('_PS_VERSION_'))
	exit;

require_once(_PS_MODULE_DIR_ . '/dpdgeopost/classes/controller.php');
require_once(_PS_MODULE_DIR_ . '/dpdgeopost/classes/apiDebugLogger.php');
require_once(_PS_MODULE_DIR_ . '/dpdgeopost/classes/apiCache.php');

class DpdGeopostWS extends DpdGeopostController
{

	protected $config;
	private $endpoint;

	private $credentials = array();

	protected $targetNamespace;
	protected $serviceName;

	const	applicationType = 9;
	const 	debug 			= true;
	const 	FILENAME 		= 'dpdgeopost.ws';

	const 	DEBUG_FILENAME			= 'DPDGEOPOST_DEBUG_FILENAME';
	const 	DEBUG_POPUP				= false;
	const 	DEBUG_FILENAME_LENGTH 	= 16;

	/** Per-request in-memory cache shared across all DpdGeopostWS callers.
	 *  Sits in front of the persistent filesystem cache to keep same-request
	 *  repeats off the disk. Scope and cacheability rules match the persistent
	 *  tier (see DpdGeopostApiCache::isCacheable). */
	private static $requestMemoryCache = array();

	public function __construct()
	{
		parent::__construct();
		$this->config = new DpdGeopostConfiguration;
	}

	/** $name must be without /v1/ */
	public function __call($name, $payload)
	{

		self::$errors = array();

		if(stripos($name, 'wsrest') === false) {
			return false;
		}

		$methodName = str_ireplace('wsrest_', '', $name);
		$path = str_ireplace('_', '/', $methodName);
		//list($_t, $path) = explode('_', $name);

		if ($this->loadWSData()) {
			$this->loadEndpoint();

			$payload = array_merge($payload[0], $this->credentials);
            $production_url = trim($this->config->ws_production_url);
			$urlMethod = $production_url . $path;

			//$payload = $this->trimRequest($payload);

            $debugBacktrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8);
            $dataToLog = array(
                'url'  => $urlMethod,
                'debug_backtrace' => $debugBacktrace,
                'payload_raw' => $payload,
            );

            $data_string = json_encode($payload);

            $rawFlag = !empty($payload['_raw']);
            $shopId = (isset($this->context->shop) && Validate::isLoadedObject($this->context->shop))
                ? (int) $this->context->shop->id
                : 0;
            $payloadForKey = DpdGeopostApiCache::stripCredentials($payload);
            $payloadForKey['__raw'] = $rawFlag ? 1 : 0;
            $cacheKey = DpdGeopostApiCache::buildKey($path, $payloadForKey, $shopId);
            $cacheable = DpdGeopostApiCache::isCacheable($path);

            if ($cacheable) {
                if (array_key_exists($cacheKey, self::$requestMemoryCache)) {
                    DpdGeopostApiDebugLogger::writeCacheEvent(
                        'hit',
                        $methodName,
                        $payload,
                        $cacheKey,
                        array('source' => __METHOD__, 'scope' => 'request-memory')
                    );

                    return self::$requestMemoryCache[$cacheKey];
                }

                $cached = DpdGeopostApiCache::get($cacheKey);
                if ($cached !== false) {
                    self::$requestMemoryCache[$cacheKey] = $cached;
                    DpdGeopostApiDebugLogger::writeCacheEvent(
                        'hit',
                        $methodName,
                        $payload,
                        $cacheKey,
                        array('source' => __METHOD__, 'scope' => 'persistent')
                    );

                    return $cached;
                }
            }

			$ch = curl_init($urlMethod);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLINFO_HEADER_OUT, true);
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 60);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
			curl_setopt(
				$ch,
				CURLOPT_HTTPHEADER,
				array(
					'Content-Type: application/json',
					'Content-Length: ' . strlen($data_string)
				)
			);

			$callResult = curl_exec($ch);

			$error = curl_error($ch);
			$error_no = curl_errno($ch);
            $requestHeaders = curl_getinfo($ch, CURLINFO_HEADER_OUT);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$times = array(
			    'url' => curl_getinfo($ch,  CURLINFO_EFFECTIVE_URL ),
			    'total' => curl_getinfo($ch, CURLINFO_TOTAL_TIME),
                'connect' => curl_getinfo($ch,  CURLINFO_CONNECT_TIME ),
                'pretransfer' => curl_getinfo($ch,   CURLINFO_PRETRANSFER_TIME  ),
                'pretransfer' => curl_getinfo($ch,   CURLINFO_PRETRANSFER_TIME  ),
                'dns' => curl_getinfo($ch,    CURLINFO_NAMELOOKUP_TIME   ),
            );

            curl_close($ch);


			$callResultDecoded = json_decode($callResult, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $callResultDecoded =  ['error' => [
                    'context' => $callResult
                    ]
                ];
            }

            // Silent-failure prevention: surface API-level errors via self::$errors
            // so callers (Pickup::arrange, Shipment::*, etc.) — which all branch on
            // `if (!self::$errors)` — actually treat error responses as failures.
            // Without this, payloads like
            //     {"error":{"context":"order_consignments_validator.locality_not_served",
            //               "message":"Curierul nu poate ajunge in zona ...","code":1}}
            // were silently treated as success and a green "arranged" toast was shown.
            // isCacheableResponse() (line 310) already uses isset($decoded['error']) as
            // the canonical "this call failed" signal; we now propagate the same signal
            // upstream to the error array.
            //
            // Skip this entirely when $rawFlag is true: those callers (e.g.
            // Shipment::getLabelsPdf, getVouchersPdf) intentionally pass non-JSON
            // binary back through this method, and the json_decode failure above
            // synthesises a fake `error.context` containing the binary body. Treating
            // that as an API error would push the binary into self::$errors and
            // make getLabelsPdf return false — which is exactly what was breaking
            // PDF label download (the response ended up being the binary mangled
            // by strip_tags + whitespace collapse instead of the real PDF).
            if (!$rawFlag && is_array($callResultDecoded) && !empty($callResultDecoded['error'])) {
                $apiError = $callResultDecoded['error'];
                if (is_array($apiError)) {
                    if (!empty($apiError['message'])) {
                        $errorMessage = (string) $apiError['message'];
                    } elseif (!empty($apiError['context'])) {
                        $errorMessage = (string) $apiError['context'];
                    } else {
                        $errorMessage = 'DPD API error';
                    }
                } else {
                    $errorMessage = (string) $apiError;
                }
                // The API embeds <br>/<b> in the message text; strip so it renders
                // safely in flash banners and the pickup dialog message div.
                $errorMessage = trim(preg_replace('/\s+/', ' ', strip_tags($errorMessage)));
                if ($errorMessage !== '') {
                    self::$errors[] = $errorMessage;
                }
            }

            $dataToLog['times'] = $times;
            $dataToLog['result_raw'] = $callResult;
            $dataToLog['payload_json'] = $data_string;
            $dataToLog['result_json'] = $callResultDecoded;
            $dataToLog['request_headers'] = $requestHeaders;
            $dataToLog['http_code'] = $httpCode;
            $dataToLog['curl_error'] = $error;
            $dataToLog['curl_errno'] = $error_no;
            $dataToLog['response_time_ms'] = round(((float) $times['total']) * 1000, 2);

            DpdGeopostApiDebugLogger::write(array(
                'method' => $methodName,
                'path' => $path,
                'url' => $urlMethod,
                'request' => array(
                    'headers' => $requestHeaders,
                    'payload_raw' => $payload,
                    'payload_json' => $data_string,
                ),
                'response' => array(
                    'http_code' => $httpCode,
                    'raw' => $callResult,
                    'json' => $callResultDecoded,
                    'curl_error' => $error,
                    'curl_errno' => $error_no,
                ),
                'timing' => array(
                    'total_seconds' => $times['total'],
                    'response_time_ms' => round(((float) $times['total']) * 1000, 2),
                    'connect_seconds' => $times['connect'],
                    'dns_seconds' => $times['dns'],
                    'pretransfer_seconds' => $times['pretransfer'],
                ),
                'origin' => $this->buildLogOrigin($debugBacktrace),
                'source_chain' => $this->buildSourceChain($debugBacktrace),
                'request_context' => $this->buildRequestContext(),
                'debug_backtrace' => $dataToLog['debug_backtrace'],
            ));

            $result = $rawFlag ? $callResult : $callResultDecoded;

            if ($cacheable && $this->isCacheableResponse($httpCode, $error_no, $callResultDecoded)) {
                self::$requestMemoryCache[$cacheKey] = $result;
                $ttl = DpdGeopostApiCache::getTtl($path);
                if ($ttl > 0 && DpdGeopostApiCache::set($cacheKey, $result, $ttl)) {
                    DpdGeopostApiDebugLogger::writeCacheEvent(
                        'store',
                        $methodName,
                        $payload,
                        $cacheKey,
                        array('source' => __METHOD__, 'scope' => 'persistent', 'ttl' => $ttl)
                    );
                }
            }

            return $result;
		}

		return false;
	}


    private function buildLogOrigin(array $backtrace)
    {
        foreach ($backtrace as $frame) {
            $class = isset($frame['class']) ? (string) $frame['class'] : '';
            $function = isset($frame['function']) ? (string) $frame['function'] : '';
            if ($class === __CLASS__ && $function === '__call') {
                continue;
            }

            return $this->normalizeTraceFrame($frame);
        }

        return array();
    }

    private function buildSourceChain(array $backtrace)
    {
        $chain = array();
        foreach ($backtrace as $frame) {
            $class = isset($frame['class']) ? (string) $frame['class'] : '';
            $function = isset($frame['function']) ? (string) $frame['function'] : '';
            if ($class === __CLASS__ && $function === '__call') {
                continue;
            }

            $chain[] = $this->normalizeTraceFrame($frame);
            if (count($chain) >= 6) {
                break;
            }
        }

        return $chain;
    }

    private function normalizeTraceFrame($frame)
    {
        if (!is_array($frame)) {
            return array();
        }

        $file = isset($frame['file']) ? str_replace('\\', '/', (string) $frame['file']) : '';
        $line = isset($frame['line']) ? (int) $frame['line'] : 0;
        $class = isset($frame['class']) ? (string) $frame['class'] : '';
        $function = isset($frame['function']) ? (string) $frame['function'] : '';
        $summary = trim(($class ? $class . '::' : '') . $function);
        if ($file !== '') {
            $summary .= ' @ ' . $file;
            if ($line > 0) {
                $summary .= ':' . $line;
            }
        }

        return array(
            'file' => $file,
            'line' => $line,
            'class' => $class,
            'function' => $function,
            'summary' => $summary,
        );
    }

    private function buildRequestContext()
    {
        $controller = isset($this->context->controller) ? $this->context->controller : null;
        $controllerClass = is_object($controller) ? get_class($controller) : '';
        $controllerName = '';
        if (is_object($controller) && isset($controller->controller_name)) {
            $controllerName = (string) $controller->controller_name;
        }

        return array(
            'request_uri' => isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '',
            'http_referer' => isset($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : '',
            'controller' => Tools::getValue('controller', ''),
            'controller_name' => $controllerName,
            'controller_class' => $controllerClass,
            'is_ajax' => (bool) Tools::getValue('ajax'),
            'is_admin' => isset($this->context->employee) && Validate::isLoadedObject($this->context->employee),
            'shop_id' => (isset($this->context->shop) && Validate::isLoadedObject($this->context->shop)) ? (int) $this->context->shop->id : 0,
            'cart_id' => (int) Tools::getValue('id_cart'),
            'order_id' => (int) Tools::getValue('id_order'),
            'address_id' => (int) Tools::getValue('id_address'),
        );
    }
    private function isCacheableResponse($httpCode, $curlErrno, $decoded)
    {
        if ((int) $curlErrno !== 0) {
            return false;
        }
        $httpCode = (int) $httpCode;
        if ($httpCode < 200 || $httpCode >= 300) {
            return false;
        }
        if (!is_array($decoded) || isset($decoded['error'])) {
            return false;
        }

        return true;
    }

	private function loadWSData()
	{

        if ($this->config->ws_username && $this->config->ws_password) {
            $this->credentials = array(
                'userName' => pSQL($this->config->ws_username),
                'password' => pSQL($this->config->ws_password)
            );

            return true;
        } else {
            self::$errors[] = $this->l('WS username / password is missing');
            return false;
        }

	}

	private function loadEndpoint()
	{

	    if($this->config->ws_production_url) {
            $this->endpoint = $this->config->ws_production_url;
        } else {
            self::$errors[] = $this->l('DPD API URL is missing.');
            return false;
        }

		return true;
	}

	protected function getError($result)
	{
		$transaction_id = isset($result['transactionId']) ? '. ' . $this->module_instance->l('Transaction Id:') . ' ' . $result['transactionId'] : '';

		if (isset($result['detail']))
			return $result['detail']['EShopException']['error']['text'] . $transaction_id;

		if (isset($result['priceList']['error']['text']))
			return $result['priceList']['error']['text'] . $transaction_id;

		if (isset($result['resultList']['error']['text']))
			return $result['resultList']['error']['text'] . $transaction_id;

		if (isset($result['error']['text']))
			return $result['error']['text'] . $transaction_id;

		if (isset($result['prestashop_message']))
			return $result['prestashop_message'] . $transaction_id;

		return null;
	}

	private function createDebugFileIfNotExists()
	{
		if ((!$debug_filename = Configuration::get(self::DEBUG_FILENAME)) || !$this->isDebugFileName($debug_filename)) {
			$debug_filename = Tools::passwdGen(self::DEBUG_FILENAME_LENGTH) . '.html';
			Configuration::updateValue(self::DEBUG_FILENAME, $debug_filename);
		}

		if (!file_exists(_DPDGEOPOST_MODULE_DIR_ . $debug_filename)) {
			$file = fopen(_DPDGEOPOST_MODULE_DIR_ . $debug_filename, 'w');
			fclose($file);
		}

		return $debug_filename;
	}

	private function isDebugFileName($debug_filename)
	{
		return Tools::strlen($debug_filename) == (int)self::DEBUG_FILENAME_LENGTH + 5 && preg_match('#^[a-zA-Z0-9]+\.html$#', $debug_filename);
	}

	private function trimRequest($request) {
		if(!is_array($request) && is_string($request) ) {
			return trim($request);
		}

		if(!is_array($request)) {
			return $request;
		}

		return array_map( array($this, 'trimRequest'), $request);
	}

}