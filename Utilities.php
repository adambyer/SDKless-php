<?php
namespace SDKless;

class Utilities {
	
	public static function is_structure($value) {
		return (is_array($value) || is_object($value));
	}

	public static function get_by_key($haystack, $key) {
		if (is_object($haystack)) {
			if (!isset($haystack->$key))
				return null;

			return $haystack->$key;
		} else {
			if (!isset($haystack[$key]))
				return null;

			return $haystack[$key];
		}

		return null;
	}

}