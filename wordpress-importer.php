<?php
/**
 * Plugin Name:     WordPress Importer
 * Plugin URI:      https://github.com/jeroenpf/wordpress-importer
 * Description:     A plugin that lets you import content into your Wordpress site.
 * Author:          wordpressdotorg
 * Text Domain:     wordpress-importer
 * Version:         0.1.0
 * requires PHP:    5.6
 *
 * @package         WordpressImporter
 */

namespace Importer;

defined( 'ABSPATH' ) || exit;

require __DIR__ . '/vendor/autoload.php';

add_filter(
	'upload_size_limit',
	function() {
		return 524288000;
	}
);

