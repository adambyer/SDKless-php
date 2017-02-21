<?php
namespace SDKless;

require_once 'Exception.php';

class Authentication {
	
	private $config = null;

	public function __construct($config) {
		$this->config = $config;
	}

	/**
	 * add incoming params to outgoing parameters of current step
	 * parameter_maps (key/value) maps incoming keys (key) from previous step to required keys (value) for current step
	 * - if incoming key exists in parameter_maps of current step, use the associated value as the parameter key for current step
	 */
	public function prepare_auth_step($step, $incoming_params, $global_vars) {
		if (!empty($incoming_params)) {
			if ($step->type == 'endpoint') {
				if (empty($this->config->settings->endpoints->{$step->endpoint}))
					return;

				// merge incoming params with global_vars and re-setup config
				if (!empty($this->config->settings->endpoints->{$step->endpoint}->merge_maps)) {
					foreach ($this->config->settings->endpoints->{$step->endpoint}->merge_maps as $incoming_key => $merge_key) {
						if (!empty($incoming_params[$incoming_key]))
							$global_vars['merge'][$merge_key] = $incoming_params[$incoming_key];
					}

					$this->config->apply_global_vars($global_vars);
				}

				$param_location = $this->config->settings->endpoints->{$step->endpoint};
			} else {
				$param_location = $step;
			}

			if (empty($param_location->parameter_maps)) {
				if (empty($param_location->parameters)) {
					$param_location->parameters = (object)$incoming_params;
				} else {
					// if params are specified in config, only set those
					foreach ($param_location->parameters as $key => $value) {
						if (!empty($incoming_params[$key]))
							$param_location->parameters->$key = $incoming_params[$key];
					}
				}
			} else {
				foreach ($incoming_params as $key => $value) {
					$param_key = $key;

					if (!empty($param_location->parameter_maps->$key))
						$param_key = $param_location->parameter_maps->$key;
					
					// if params are specified in config, only set those
					if (empty($param_location->parameters) || isset($param_location->parameters->$param_key))
						$param_location->parameters->$param_key = $value;
				}
			}
		}
	}

	/**
	 * this happens last, before calling the API
	 * oauth_nonce, oauth_timestamp, and oauth_signature will be set here if not already set by global vars, endpoint vars, or custom config
	 **/
	public function setup_oauth_header($global_vars) {
		$this->config->set_method();
		$request_params = $this->config->get_endpoint_setting('parameters');
		$include_oauth_header = $this->config->get_endpoint_setting('include_oauth_header');

		if (empty($include_oauth_header))
			return;

		if (empty($this->config->settings->authentication->oauth_header_parameters))
			return;

		$oauth_nonce = md5(uniqid(rand(), true));
		$oauth_timestamp = time();
		$oauth_params = $this->get_oauth_params($oauth_nonce, $oauth_timestamp);
		$oauth_signature = $this->get_oauth_signature($oauth_params, $request_params);
		$oauth_header_array = array();
		
		foreach ($oauth_params as $key => $value) {
			$value = rawurlencode($value);
			$oauth_header_array[$key] = "$key=\"$value\"";
		}

		$oauth_header_array['oauth_signature'] = 'oauth_signature="' . rawurlencode($oauth_signature) . '"';
		ksort($oauth_header_array);
		// error_log("*** Authentication::setup_oauth_header:oauth_header_array:" . print_r($oauth_header_array, true));
		$global_vars['merge']['OAUTH-HEADER-PARAMS'] = implode(', ', $oauth_header_array);
		// error_log("*** Authentication::setup_oauth_header:OAUTH-HEADER-PARAMS:" . $global_vars['merge']['OAUTH-HEADER-PARAMS']);
		$this->config->apply_global_vars($global_vars);
	}

	private function get_oauth_params($oauth_nonce, $oauth_timestamp) {
		$oauth_header_parameters = $this->config->settings->authentication->oauth_header_parameters;
		$params = array();

		foreach ($oauth_header_parameters as $key => $value) {
			switch ($key) {
				case 'oauth_consumer_secret':
				case 'oauth_token_secret':
				case 'oauth_signature':
					$value = null;
					break;
				case 'oauth_callback':
					if ($this->config->actual_endpoint_name != 'request_token')
						$value = null;

					break;
				case 'oauth_nonce':
					$value = $oauth_nonce;
					break;
				case 'oauth_timestamp':
					$value = $oauth_timestamp;
					break;
			}

			if (empty($value))
				continue;

			if (!$this->config->is_merged($value))
				continue;

			$params[$key] = $value;
		}

		ksort($params);
		return $params;
	}

	// collect applicable oauth parameters from config:authentication:oauth_header_parameters and endpoint parameters
	// sort and encode with signing key
	private function get_oauth_signature($oauth_params, $request_params) {
		$oauth_header_parameters = $this->config->settings->authentication->oauth_header_parameters;

		if (empty($request_params))
			$request_params = array();

		if (is_object($request_params))
			$request_params = get_object_vars($request_params);

		$signature_params = array_merge($oauth_params, $request_params);
		$signature_pairs = array();

		ksort($signature_params);

		foreach ($signature_params as $key => $value) {
			if (empty($value))
				continue;

			$value = rawurlencode($value);
			$signature_pairs[] = "$key=$value";
		}

		$uri = $this->config->get_endpoint_setting('uri');
		$this->config->make_uri($uri);
		$parameter_string = strtoupper($this->config->method) . '&' . rawurlencode($uri) . '&' . rawurlencode(implode('&', $signature_pairs));
		$signing_key = "";

		if (!empty($oauth_header_parameters->oauth_consumer_secret) && $this->config->is_merged($oauth_header_parameters->oauth_consumer_secret))
			$signing_key .= rawurlencode($oauth_header_parameters->oauth_consumer_secret) . '&';

		if (!empty($oauth_header_parameters->oauth_token_secret) && $this->config->is_merged($oauth_header_parameters->oauth_token_secret))
			$signing_key .= rawurlencode($oauth_header_parameters->oauth_token_secret);

		$oauth_signature = base64_encode(hash_hmac('sha1', $parameter_string, $signing_key, true));

		// error_log("*** SDKless:Authentication::get_oauth_signature:signature_params:" . print_r($signature_params, true));
		// error_log("*** SDKless:Authentication::get_oauth_signature:signature_pairs:" . print_r($signature_pairs, true));
		// error_log("*** SDKless:Authentication::get_oauth_signature:parameter_string:$parameter_string");
		// error_log("*** SDKless:Authentication::get_oauth_signature:signing_key:$signing_key");
		// error_log("*** SDKless:Authentication::get_oauth_signature:oauth_signature:$oauth_signature");

		return $oauth_signature;
	}

}