<?php
namespace SDKless;

require_once 'Utilities.php';
require_once 'Exception.php';

class Output {

	private $config = null;

	/**
	 * $output starts as an array; if paging, add to array; otherwise overwrite output
	 *
	 * if no output config defined
	 * - if response is scalar, overwrite/add-to output as is (depending on paging as above)
	 * - if response is structure, merge with output array
	 */
	public function populate($config, $response, &$output) {
		$this->config = $config;
		$response_count = 0;
		$output_config = $config->get_endpoint_setting('output');
		$paging = $config->get_endpoint_setting('paging');

		if (empty($output_config)) {
			if (Utilities::is_structure($response)) {
				$output = array_merge($output, (array)$response);
				return count($response);
			} else {
				if (empty($paging))
					$output = $response;
				else
					$output[] = $response;

				return 1;
			}
		}

		$data = $this->get_data($response);
		
		if (empty($data))
			return 0;

		$output_format = (empty($output_config->data->format)? '' : $output_config->data->format);

		switch ($output_format) {
			case 'iterable': // like an array of contact records
				if (!Utilities::is_structure($data))
					throw new SDKlessException("output config specifies structure data format but response is not a structure");

				// put in specified output format, if applicable
				if (empty($output_config->data->items->locations)) {
					$output = array_merge($output, (array)$data);
					$response_count = count($data);
				} else {
					foreach ($data as $data_key => $data_value) {
						if (!empty($output_config->data->key_filter)) {
							if (($output_config->data->key_filter == 'numeric') && !is_numeric($data_key))
								continue;
						}
						
						// if $output_config->data->items is specified, we are expecting the data structure to contain child structures
						if (!Utilities::is_structure($data_value))
							throw new SDKlessException("output config specifies data items but response children are not structures");

						if (is_object($data_value))
							$data_value = get_object_vars($data_value);

						$output_item = $this->get_item($data_value);

						$output[] = $output_item;
						$response_count++;
					}
				}

				return $response_count;
			default: // non-iterable (like scalar value or single contact record)
				if (Utilities::is_structure($data) && !empty($output_config->data->items->locations))
					$return_output = $this->get_item((array)$data);
				else
					$return_output = $data; // leave data as is

				if (empty($paging))
					$output = $return_output;
				else
					$output[] = $return_output;

				return 1;
				break;
		}
	}

	private function get_data($data) {
		$output_config = $this->config->get_endpoint_setting('output');

		if (empty($output_config->data->location))
			return $data;
		
		if (!is_array($output_config->data->location))
			throw new SDKlessException("endpoint output location must be an array");
		
		// drill down to desired data
		foreach ($output_config->data->location as $location_key) {
			$data = Utilities::get_by_key($data, $location_key);

			if (is_null($data))
				throw new SDKlessException("specified key not found in response: $location_key");
		}

		return $data;
	}

	private function get_item($data) {
		$output_config = $this->config->get_endpoint_setting('output');
		$output_item = array();

		foreach ($output_config->data->items->locations as $location_key => $location) {
			if (is_scalar($location)) {
				if (isset($data[$location]))
					$output_item[$location_key] = $data[$location];
				else
					$output_item[$location_key] = null;
			} else {
				// locations entry is an array like: ["email_addresses", 0, "email_address"],
				$data_copy = $data;

				foreach ($location as $location_item) {
					if (is_scalar($location_item)) {
						$data_copy = Utilities::get_by_key($data_copy, $location_item);

						if (is_null($data_copy))
							throw new SDKlessException("specified key not found in response: $location_item");

						$output_item[$location_key] = $data_copy;
					} else {
						// if location item is a structure, this indicates a search; data must be a structure
						$this->set_item_by_search($data_copy, $location_item, $location_key, $output_item);
					}
				}
			}
		}

		return $output_item;
	}

	private function set_item_by_search($data, $location_item, $location_key, &$output_item) {
		if (!Utilities::is_structure($data))
			throw new SDKlessException("output data item must be a structure when config location item is a structure");
		
		if (empty($location_item->search_key) || !isset($location_item->search_value) || !isset($location_item->return_key))
			throw new SDKlessException("search_key, search_value, and return_key are required when location item is a structure");

		$search_key = $location_item->search_key;
		$return_key = $location_item->return_key;

		foreach ($data as $child_item) {
			// if location item is a structure, data must be a structure
			if (!Utilities::is_structure($child_item))
				throw new SDKlessException("output data item must be a structure when config location item is a structure");

			if (isset($child_item->$search_key) && ($child_item->$search_key == $location_item->search_value))
				$output_item[$location_key] = $child_item->$return_key;
		}
	}

}