<?php

namespace rndpxl\shopifyAPI;

use GuzzleHttp\Client;
use \Psr\Http\Message\ResponseInterface;
use \GuzzleHttp\Exception\ClientException;

use rndpxl\shopifyAPI\Exceptions\APIException;
use rndpxl\shopifyAPI\Exceptions\InvalidDomainException;

class API
{
	private $_type = 'app';
	private $_client = NULL;

	private $_callsUsed = 0;
	private $_callsRemaining = 0;
	private $_callsLimit = 0;

	private $lastCall = NULL;

	private $_shopURL = '';
	private $_apiVersion = '';

	private $_nextURL = NULL;
	private $_nextData = [];
	private $_previousURL = NULL;
	private $_currentURL = NULL;

	private $_appCredentials = [
		'key' => NULL,
		'secret' => NULL,
		'access_token' => NULL
	];

	private $_privateCredentials = [
		'key' => NULL,
		'password' => NULL,
		'shared' => NULL
	];



	/**** Initializers ****/
	/**
	 * Create an api instance for a public Shopify app
	 * @param string $domain
	 * @param string $apiVersion
	 * @param string $key
	 * @param string $secret
	 * @param string $token
	 * @return API
	 */
	public static function app(string $domain, string $apiVersion, string $key, string $secret, string $token)
	{
		return new API('app',
			$domain,
			$apiVersion,
			[
				'key' => $key,
				'secret' => $secret,
				'access_token' => $token
			]);
	}

	/**
	 * Create an api instance for a private Shopify app
	 * @param string $domain
	 * @param string $apiVersion
	 * @param string $key
	 * @param string $password
	 * @param string $shared
	 * @return API
	 */
	public static function private(string $domain, string $apiVersion, string $key, string $password, string $shared)
	{
		return new API('private',
			$domain,
			$apiVersion,
			[
				'key' => $key,
				'password' => $password,
				'shared' => $shared
			]);
	}


	public function __get(string $property)
	{
		if (property_exists($this, $property))
		{
			return $this->{$property};
		}
	}


	/**
	 * Extracts/verifies the *.myshopify domain for API calls
	 * @param string $domain
	 * @return mixed
	 * @throws InvalidDomainException
	 */
	private function extractShopifyDomain(string $domain)
	{
		$domainMatches = [];
		preg_match('/\b([a-zA-Z0-9\-\_]+)\.myshopify\.com/', $domain, $domainMatches);

		if (count($domainMatches))
		{
			return $domainMatches[0];
		}

		throw new InvalidDomainException('Invalid format: ' . $domain . '. Please use [subdomain].myshopify.com format.');
	}

	/**
	 * Try to get URL into correct format
	 * Largely used in part for cursor-based pagination with private apps
	 * @param string $url
	 * @return string|string[]
	 */
	private function formatURL(string $url)
	{
		// Strip out extraneous info
		if (strpos($url, '.com/') !== FALSE)
		{
			$url = substr($url, strrpos($url, '.com/') + 5);
		}

		if (strpos($url, $this->_apiVersion) !== FALSE)
		{
			$url = substr($url, strrpos($url, $this->_apiVersion) + strlen($this->_apiVersion) + 1);
		}

		// Insert version
		$url = '/admin/api/' . $this->_apiVersion . '/' . $url;
		return str_replace('//', '/', $url);
	}

	public function __construct(string $type, string $domain, string $version, array $settings)
	{
		$this->_shopURL = $this->extractShopifyDomain($domain);
		$this->_apiVersion = $version;

		switch(strtolower($type))
		{
			case 'app':
				$this->_appCredentials = $settings;
				$this->_client = new Client([
					'base_uri' => 'https://' . $this->_shopURL,
					'headers' => [
						'X-Shopify-Access-Token' => $this->_appCredentials['access_token']
					]
				]);
				break;

			case 'private':
				$this->_privateCredentials = $settings;
				$this->_client = new Client([
					'base_uri' => 'https://' . $this->_privateCredentials['key'] . ':' . $this->_privateCredentials['password'] . '@' . $this->_shopURL
				]);
				break;
		}

		// throw invalid app type?
	}

	public function getResult()
	{

	}

	private function call(string $type, string $url, array $params)
	{
		try
		{
			$response = $this->_client->request(strtoupper($type), $url, $params);
			$this->reset();
			$this->saveMeta($response);

			$this->lastCall = new Result($response);
			return $this->lastCall;
		}
		catch(ClientException $e)
		{
			$response = $e->getResponse();
			throw new APIException($e->getMessage(), $response->getStatusCode(), json_decode($response->getBody()->getContents()), $e);
		}
	}

	/**
	 * Clean up before calling `saveMeta()` so that values which
	 * aren't present in the response are not persisted
	 */
	private function reset()
	{
		$this->_nextURL = NULL;
		$this->_nextData = NULL;
		$this->_previousURL = NULL;
		$this->_currentURL = NULL; //  maybe?
	}

	/**
	 * Extracts relevant info from a response
	 * @param ResponseInterface $response
	 */
	private function saveMeta(ResponseInterface $response)
	{
		$headers = $response->getHeaders();

		// Get Next/Previous links
		if (array_key_exists('Link', $headers))
		{
			$links = $headers['Link'];
			if (count($links) === 1)
			{
				$linkEntry = $links[0];

				// If we have both next and previous links, they're separated by a comma
				foreach(explode(',', $linkEntry) as $link)
				{
					$split = explode(';', $link);

					// Make sure it was somewhat in the right format
					if (count($split) === 2)
					{
						// Clean it up
						$url = trim($split[0], '<>');
						$rel = strtolower(trim(str_replace('rel=', '', trim($split[1])), '"'));

						// Determine which way the link points
						switch ($rel)
						{
							case 'next':
								$this->_nextURL = $url;
								break;

							case 'previous':
								$this->_previousURL = $url;
								break;
						}
					}
				}
			}
		}

		// Get API Use Count & Limit
		if (array_key_exists('X-Shopify-Shop-Api-Call-Limit', $headers))
		{
			$calls = $headers['X-Shopify-Shop-Api-Call-Limit'];
			if (count($calls) && strpos($calls[0], '/') !== FALSE)
			{
				$callInfo = $calls[0];
				list($used, $limit) = explode('/', $callInfo);
				$this->_callsUsed = $used;
				$this->_callsRemaining = $limit - $used;
				$this->_callLimit = $limit;
			}
		}
	}

	// For paginated calls
	public function setup($url = '', $data = [])
	{
		$this->_nextURL = $url;
		$this->_nextData = $data;
	}

	public function get($url = '', $data = [])
	{
		if (!$url)
		{
			$url = $this->formatURL($this->_nextURL);
			$data = $this->_nextData;
			$this->_nextData = [];
		}
		else
		{
			$url = $this->formatURL($url);
		}

		$additional = [];
		if ($data)
		{
			$additional['query'] = $data;
		}
		
		return $this->call('GET', $url, $additional);
	}

	public function post(string $url, $data = [])
	{
		return $this->call('POST', $this->formatURL($url), $data);
	}

	public function put(string $url, $data = [])
	{
		return $this->call('PUT', $this->formatURL($url), [ 'json' => $data ]);
	}

	public function delete(string $url, $data = [])
	{
		return $this->call('DELETE', $this->formatURL($url), [ 'json' => $data ]);
	}

	public function hasPrevious()
	{
		return $this->_previousURL !== NULL;
	}

	public function hasNext()
	{
		return $this->_nextURL !== NULL;
	}

	public function previous()
	{
		$this->_currentURL = $this->_previousURL;
		return $this;
	}

	public function next()
	{
		$this->_currentURL = $this->_nextURL;
		return $this;
	}


	public static function verifyRequest()
	{

	}


	public function hasCallsRemaining()
	{
		return $this->_callsRemaining;
	}

	public function sleepIfNecessary($threshold = 5, $sleepTime = 5, $callback = NULL)
	{
		if ($this->_callsRemaining < $threshold)
		{
			if (is_callable($callback))
			{
				$callback($this->_callsRemaining, $this->_callsUsed, $this->_callsLimit);
			}

			sleep($sleepTime);
		}
	}

}