<?php

namespace rndpxl\ShopifyAPI\Exceptions;

use Throwable;

class APIException extends \Exception {

	private $_message = '';
	private $_code = 0;
	private $_data = [];

	public function __construct($message = "", $code = 0, $data = [], Throwable $previous = null)
	{
		parent::__construct($message, $code, $previous);
		$this->_message = $message;
		$this->_code = $code;
		$this->_data = $data;
	}

	public function __get($key = '')
	{
		switch(strtolower($key))
		{
			case 'data':
				return $this->_data;

			case 'message':
				return $this->_message;

			case 'code':
				return $this->_code;
		}
	}
}