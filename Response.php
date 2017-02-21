<?php
namespace SDKless;

require_once 'Exception.php';

class Response {
	
	public $curl_opts = array();
	public $curl_info = array();
	public $responses = array();
	public $last_status_code = null;
	private $config = null;
	private $time_limit = null;

	public function get($config, $time_limit) {
		$this->config = $config;
		$this->time_limit = $time_limit;
		$this->config->set_method();
		$this->set_time_limit();
		$this->set_uri();
		$this->curl_opts[$this->config->custom_endpoint_name][] = $this->get_curl_opts();
		$response = $this->do_curl();
		
		switch ($this->config->get_endpoint_setting('output_format')) {
			case 'json':
				$output = $this->json_decode($response);
				break;
			case 'json_text_lines':
				$output = $this->json_text_lines_decode($response);
				break;
			case 'query_string':
				parse_str($response, $output);
				break;
			default:
				$output = $response;
		}
		
		$this->responses[$this->config->custom_endpoint_name][] = $output;
		$this->http_code_check($this->last_status_code);

		return $output;
	}

	private function set_uri() {
		$uri = $this->config->get_endpoint_setting('uri');
		$this->config->make_uri($uri);
		$uri_parts = explode('?', $uri);
		$uri = $uri_parts[0];
		$params = $this->config->get_endpoint_setting('parameters');

		// only add query string if it doesn't already exist
		// this is so that cursor paging can set a new uri w/ query string w/o having unwanted original endpoint params added
		if (empty($uri_parts[1])) {
			if (!empty($params) && ($this->config->method == 'get')) {
				$params = http_build_query($params);
				$uri .= "?$params";
			}
		} else {
			$uri = implode('?', $uri_parts);
		}

		$this->uri = $uri;
		return $uri;
	}

	public function do_curl() {
		$curl = curl_init();
		$current_index = count($this->curl_opts[$this->config->custom_endpoint_name]) -1;
		$this->curl_opts[$this->config->custom_endpoint_name][$current_index]['CURLOPT_URL'] = $this->uri;
		$opts = array();

		foreach ($this->curl_opts[$this->config->custom_endpoint_name][$current_index] as $key => $value) {
			$const = constant($key);

			if (!is_null($const)) 
				$opts[$const] = $value;
		}

		curl_setopt_array($curl, $opts);
		$response = curl_exec($curl);
		$curl_errno = curl_errno($curl);
		$curl_error = curl_error($curl);
		$this->last_status_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);

		// error_log("*** Response::do_curl:opts:" . print_r($this->curl_opts, true));
		// error_log("*** Response::do_curl:errno:$curl_errno");
		// error_log("*** Response::do_curl:error:$curl_error");
		// error_log("*** Response::do_curl:http_code:{$this->last_status_code}");
		// error_log("*** Response::do_curl:url:{$this->uri}");
		// error_log("*** Response::do_curl:getinfo:" . print_r(curl_getinfo($curl), true));
		// error_log("*** Response::do_curl:response:" . print_r($response, true));

		if (!empty($curl_errno) || !empty($curl_error))
			throw new SDKlessException("curl error: $curl_error ($curl_errno)");

		$curl_info = curl_getinfo($curl);
		$curl_info['errno'] = $curl_errno;
		$curl_info['error'] = $curl_error;
		$this->curl_info[$this->config->custom_endpoint_name][] = $curl_info;
		curl_close($curl);

		return $response;
	}

	private function get_curl_opts() {
		$request_options = $this->config->get_endpoint_setting('request_options');
		$opts = array(
			'CURLOPT_RETURNTRANSFER' => true,
			'CURLOPT_FOLLOWLOCATION' => true,
		);

		if (!empty($this->time_limit)) {
			$opts['CURLOPT_CONNECTTIMEOUT'] = $this->time_limit;
			$opts['CURLOPT_TIMEOUT'] = $this->time_limit;
		}

		if (!empty($request_options)) {
			foreach ($request_options as $key => $value) {
				switch ($key) {
					case 'headers':
						$headers = array();
						foreach ($value as $header_key => $header_value) {
							$headers[] = "$header_key: $header_value";
						}
						$opts['CURLOPT_HTTPHEADER'] = $headers;
						break;
					default:
						$opts["CURLOPT_$key"] = $value;
				}
			}
		}
		
		if ($this->config->method == 'post') {
			$opts['CURLOPT_POST'] = true;
			$params = $this->config->get_endpoint_setting('parameters');

			if (empty($params)) {
				$opts['CURLOPT_POSTFIELDS'] = '';
			} else {
				$input_format = $this->config->get_endpoint_setting('input_format');

				switch ($input_format) {
					case 'json':
						$params = json_encode($params);
						break;
					case 'query_string':
						$params = http_build_query($params);
						break;
					default:
						// multi-level structures will cause uncatchable error in curl_setopt
						foreach ($params as $param) {
							if (Utilities::is_structure($param))
								throw new SDKlessException("multi-level structure endpoint parameters must be encoded");
						}
				}

				$opts['CURLOPT_POSTFIELDS'] = $params;
			}
		}

		return $opts;
	}

	private function set_time_limit() {
		$time_limit = $this->config->get_endpoint_setting('time_limit');

		if (!empty($time_limit))
			set_time_limit($time_limit);
	}

	// check if returned code warrants an exception
	// supports any length code
	private function http_code_check($code) {
		$http_code_check = $this->config->get_endpoint_setting('http_code_check');

		// if returned code doesn't start with the ok code
		if (!empty($http_code_check) && strpos($code, $http_code_check) !== 0)
			throw new SDKlessException("failed http code check");
	}

	private function json_decode($response) {
		$output = json_decode($response);

		if (empty($output)) {
			$this->responses[$this->config->actual_endpoint_name][] = $response;
			throw new SDKlessException("API returned invalid JSON");
		}

		$this->error_check($output);

		return $output;
	}

	private function error_check($output) {
		$output_config = $this->config->get_endpoint_setting('output');

		if (!empty($output_config->error->location))
			$error_location = $output_config->error->location;

		if (empty($error_location))
			return;

		if (!is_array($error_location)) {
			$this->responses[$this->config->actual_endpoint_name][] = $output;
			throw new SDKlessException("config error location must be an array");
		}

		// drill down to desired data
		foreach ($error_location as $location_key) {
			$output = Utilities::get_by_key($output, $location_key);

			if (empty($output))
				break;
		}

		if (!empty($output)) {
			$this->responses[$this->config->actual_endpoint_name][] = $output;
			throw new SDKlessException("API returned error: $output");
		}
	}

	private function json_text_lines_decode($response) {
		$lines = preg_split('/\r*\n+|\r+/', trim($response));
		$headers = json_decode(array_shift($lines));
		$output = array();

		foreach ($lines as $line) {
			$line = json_decode($line);

			if (empty($line))
				throw new SDKlessException("API returned invalid JSON");

			$contact = array();

			foreach ($line as $key => $value)
				$contact[$headers[$key]] = $value;

			$output[] = $contact;
		}

		return $output;
	}

}