<?php
namespace SDKless;

require_once 'Utilities.php';
require_once 'Exception.php';
require_once 'Configuration.php';
require_once 'Authentication.php';
require_once 'Response.php';
require_once 'Output.php';

class SDKless {
	
	const MERGE_OPEN = '*|';
	const MERGE_CLOSE = '|*';

	public $config = null;
	public $api_name = null;
	public $uri = null;
	public $global_vars = array();
	public $endpoint_vars = array();
	public $local_vars = array();

	protected $prerequisites_complete = false;
	protected $output = null;
	protected $auth = null;
	protected $time_limit = null;

	public function __construct($api_name = null, $global_vars = array()) {
		if (empty($api_name))
			return;

		$sdkless_dir = dirname(dirname(__FILE__));
		$this->api_name = $api_name;
		$this->global_vars = $global_vars;
		$this->config = new Configuration(static::MERGE_OPEN, static::MERGE_CLOSE); // static used to prefer const defined in child class
		$this->config->json = file_get_contents("$sdkless_dir/sdkless/config/{$this->api_name}.json");
		$this->config->json_custom = @file_get_contents("$sdkless_dir/sdkless/config-custom/{$this->api_name}.custom.json");
		$this->config->setup();

		/** 
		 * order of precedence
		 * - any applied merge vars will negate subsequent merge vars
		 * --- ex. an endpoint merge var will not apply if already applied by global vars
		 * - set vars can be overwritten
		 * --- ex. an endpoint set var will overwrite the same one done by global vars
		 **/
		$this->config->apply_custom_global_vars();
		$this->config->apply_global_vars($this->global_vars);

		$this->auth = new Authentication($this->config);
		$this->response = new Response();
		$this->output = new Output();
	}

	// $params (array or object) are any parameters coming back as a result of the previous step
	public function authenticate($step_id, $params = null) {
		# a step_id of -1 indicates a redirect step
		# find the next id after the redirect step
		if ($step_id === -1) {
			$step_id = $this->config->get_authentication_redirect_step_id();

			if ($step_id !== null)
				$step_id++;
		}

		if (empty($this->config->settings->authentication->steps))
			throw new SDKlessException("authentication steps not defined");

		if (empty($this->config->settings->authentication->steps[$step_id]))
			return array('step_id' => $step_id, 'params' => $params, 'done' => true);

		$params = (array)$params;

		$step = $this->config->settings->authentication->steps[$step_id];
		$this->auth->prepare_auth_step($step, $params, $this->global_vars);

		switch ($step->type) {
			case "redirect":
				$uri = $step->uri;
				$this->config->make_uri($uri);

				if (!empty($step->parameters))
					$uri .= ('?' . http_build_query($step->parameters));

				header("Location: " . $uri);
				exit; // needed to prevent loop calling this method from continuing
			case "endpoint":
				$endpoint_name = $step->endpoint;
				$output = $this->go($endpoint_name);

				// merge output of steps
				return array('step_id' => $step_id, 'params' => array_merge($params, (array)$output), 'done' => false);
				break;
			default:
				throw new SDKlessException("invalid step type");
		}
	}

	public function go($endpoint_name, $endpoint_vars = array(), $local_vars = array()) {
		if (is_array($local_vars))
			$this->local_vars = $local_vars;

		// must set endpoint name before checking for bypass_prerequisites
		$this->config->custom_endpoint_name = $endpoint_name;
		$this->config->set_actual_endpoint_name();
		$this->config->apply_custom_endpoint_params();

		if (empty($this->config->settings->endpoints->{$this->config->custom_endpoint_name}->bypass_prerequisites))
			$this->process_prerequisites();

		// must set endpoint name after processing prerequisites to setup requested endpoint
		$this->config->custom_endpoint_name = $endpoint_name;
		$this->config->set_actual_endpoint_name();
		$this->config->apply_custom_endpoint_params();
		
		if (empty($this->config->settings->endpoints->{$this->config->actual_endpoint_name}))
			throw new SDKlessException("specified endpoint does not exist in config: {$this->config->actual_endpoint_name}");

		if (is_array($endpoint_vars))
			$this->endpoint_vars = $endpoint_vars;
		else
			$this->endpoint_vars = array();

		if (!empty($this->endpoint_vars['array_set']))
			$this->config->apply_endpoint_array_set_vars($this->endpoint_vars['array_set']);
		
		$this->config->apply_endpoint_vars($this->endpoint_vars);
		$this->config->set_method();
		$this->auth->setup_oauth_header($this->global_vars);

		$this->time_limit = (array_key_exists('time_limit', $this->local_vars)? $this->local_vars['time_limit'] : $this->config->get_endpoint_setting('time_limit'));
		$output = array();
		$total_count = 0;

		// loop for paging
		while (true) {
			$response = $this->response->get($this->config, $this->time_limit);

			if (empty($response))
				break;

			$response_count = $this->output->populate($this->config, $response, $output);
			
			if ($response_count == 0)
				break;

			$total_count += $response_count;

			// if total count >= limit, truncate/break
			$limit = $this->config->get_endpoint_setting('limit');

			if (!empty($limit) && ($total_count >= $limit)) {
				if (is_array($output))
					$output = array_slice($output, 0, $limit);

				break;
			}

			// if paging, setup next page param
			$paging = $this->config->get_endpoint_setting('paging');
			
			if (empty($paging->parameters))
				break;

			$paging_type = (empty($paging->type)? "page_number" : $paging->type);
			$endpoint_params = $this->config->get_endpoint_setting('parameters');
			$page_size_param = (empty($paging->parameters->page_size->name)? "page_size" : $paging->parameters->page_size->name);
			$page_size = (empty($endpoint_params->$page_size_param)? null : $endpoint_params->$page_size_param);
			$paging_base = (isset($paging->parameters->$paging_type->base)? $paging->parameters->$paging_type->base : 1);

			switch ($paging_type) {
				case 'page_number':
					if (empty($paging->parameters->$paging_type->name))
						throw new SDKlessException("paging $paging_type name required");

					$paging_counter_param = $paging->parameters->$paging_type->name;

					if (!isset($paging_counter))
						$paging_counter = $paging_base;

					$paging_counter++;

					$keys = array('parameters', $paging_counter_param);
					$this->config->set_endpoint_setting($keys, $paging_counter);

					// response count is less than page size; break
					if (!empty($page_size) && ($response_count < $page_size))
						break 2;

					break;
				case 'record_offset':
					if (empty($paging->parameters->$paging_type->name))
						throw new SDKlessException("paging $paging_type name required");

					$paging_counter_param = $paging->parameters->$paging_type->name;

					if (!isset($paging_counter))
						$paging_counter = $paging_base;

					if (empty($page_size))
						throw new SDKlessException("endpoint page size parameter required for offset paging");

					$paging_counter += $page_size;
					$keys = array('parameters', $paging_counter_param);

					$this->config->set_endpoint_setting($keys, $paging_counter);

					// response count is less than page size; break
					if (!empty($page_size) && ($response_count < $page_size))
						break 2;

					break;
				case 'cursor':
					if (empty($paging->parameters->$paging_type->location) || !is_array($paging->parameters->$paging_type->location))
						throw new SDKlessException("paging $paging_type location array required");

					$data = json_decode(json_encode($response)); // make copy of response (can't be output; may no longer contain paging info)

					foreach ($paging->parameters->$paging_type->location as $location_key)
						$data = Utilities::get_by_key($data, $location_key);
					
					if (empty($data))
						break 2;

					$this->config->set_endpoint_setting('uri', $data);

					break;
			}
		}

		$output_config = $this->config->get_endpoint_setting('output');

		// filter output
		if (Utilities::is_structure($output) && !empty($output_config->filter)) {
			$unfiltered_output = json_decode(json_encode($output));
			$output = array();

			if (!Utilities::is_structure($output_config->filter))
				throw new SDKlessException("config endpoint output filter must be a structure");
				
			foreach ($output_config->filter as $filter) {
				$match_found = false;

				if (empty($filter->search_key) || !isset($filter->search_value))
					throw new SDKlessException("search_key and search_value are required for output filtering");
					
				foreach ($unfiltered_output as $item) {
					$item_value = Utilities::get_by_key($item, $filter->search_key);

					if (is_null($item_value))
						continue;

					if ($item_value == $filter->search_value) {
						$match_found = true;

						if (!empty($filter->return_key)) {
							$return_key = $filter->return_key;
							return Utilities::get_by_key($item, $return_key);
						}

						$output[] = $item;
					}
				}

				if (!empty($filter->return_type)) {
					switch ($filter->return_type) {
						case 'boolean':
							return $match_found;
							break;
						case '!boolean':
							return !$match_found;
							break;
					}
				}
			}
		}

		$this->config->reset_to_unmerged();
		return $output;
	}

	protected function process_prerequisites() {
		if (!empty($this->config->settings->endpoint_prerequisites)) {
			foreach ($this->config->settings->endpoint_prerequisites as $prerequisite) {
				if (empty($prerequisite->repeat) && $this->prerequisites_complete)
					continue;

				if (!empty($prerequisite->protocol)) {
					switch ($prerequisite->protocol) {
						case 'cookie':
							$cookie_file = sys_get_temp_dir() . "/sdkless_{$this->api_name}_cookie";

							if (!empty($this->local_vars['cookie_id']))
								$cookie_file .= ('_' . $this->local_vars['cookie_id']);
							
							$this->config->settings->common_endpoint_settings->all->curl_options->COOKIEFILE = $cookie_file;
							$this->config->settings->common_endpoint_settings->all->curl_options->COOKIEJAR = $cookie_file;
							break;
					}
				}

				if (!empty($prerequisite->endpoint)) {
					if (empty($this->config->settings->endpoints->{$prerequisite->endpoint}))
						throw new SDKlessException("specified prerequisite endpoint does not exist in config");

					$this->config->custom_endpoint_name = $prerequisite->endpoint;
					$this->config->set_actual_endpoint_name();
					$this->config->apply_custom_endpoint_params();

					$response = $this->response->get($this->config, $this->time_limit);

					if (!empty($response) && !empty($prerequisite->merge_maps)) {
						$response = (array)$response;

						foreach ($prerequisite->merge_maps as $response_key => $merge_key) {
							if (!empty($response[$response_key]))
								$this->global_vars['merge'][$merge_key] = $response[$response_key];
						}

						$this->config->apply_global_vars($this->global_vars);
					}
				}
			}

			$this->prerequisites_complete = true;
		}
	}

}