<?php
/**
 * MU Plugins Loader
 *
 * WordPress loads PHP files from the root mu-plugins directory, but it does not
 * automatically load plugin files placed inside subdirectories. This loader keeps
 * the root clean and loads every PHP file from first-level subdirectories only.
 */

if (!defined('ABSPATH')) {
	exit;
}

$directories = glob(__DIR__ . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);
if ($directories === false) {
	return;
}

sort($directories, SORT_STRING);

foreach ($directories as $directory) {
	$files = glob($directory . DIRECTORY_SEPARATOR . '*.php');
	if ($files === false) {
		continue;
	}

	sort($files, SORT_STRING);

	foreach ($files as $file) {
		require_once $file;
	}
}
