<?php

namespace rndpxl\shopifyAPI;

use \Psr\Http\Message\ResponseInterface;

class Result
{
	private $_response;
	private $_data;
	private $_headers;


	/**
	 * Load up the Guzzle Response on creation
	 * @param \Psr\Http\Message\ResponseInterface $response
	 */
	public function __construct(ResponseInterface $response)
	{
		$this->_response = $response;
		$this->_data = json_decode($this->_response->getBody()->getContents());
		$this->headers = $this->_response->getHeaders();
	}

	public function __toString()
	{
		return json_encode($this->_data);
	}

	/**
	 * If the requested key exists on the Shopify response, return that
	 * Otherwise check for something on this object itself
	 * @param string $key
	 * @return mixed
	 */
	public function __get($key = '')
	{
		if (property_exists($this->_data, $key))
		{
			return $this->_data->{$key};
		}
		elseif (property_exists($this, $key))
		{
			return $this->{$key};
		}

		return NULL;
	}

	/**
	 * Retrieve a header
	 * @param string $key
	 * @return array|null
	 */
	public function header($key = '')
	{
		if (property_exists($this->_headers, $key))
		{
			return $this->_headers->{$key};
		}
		return NULL;
	}




}