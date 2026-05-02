<?php
/**
 * Plugin Name: ISU LatePoint Translation Notice Silencer
 * Description: Suppresses WordPress 6.7 early translation-loading notices for LatePoint domains only.
 */

if (!defined('ABSPATH')) {
	exit;
}

add_filter('doing_it_wrong_trigger_error', function ($trigger, $function_name, $message, $version) {
	if ('_load_textdomain_just_in_time' !== $function_name) {
		return $trigger;
	}

	$domains = [
		'<code>latepoint</code>',
		'<code>latepoint-apple-calendar</code>',
	];

	foreach ($domains as $domain) {
		if (false !== strpos($message, $domain)) {
			return false;
		}
	}

	return $trigger;
}, 10, 4);
