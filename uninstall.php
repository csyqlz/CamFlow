<?php
/**
 * Remove CamFlow data when the plugin is deleted from WordPress.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-zibi-name.php';

if ( class_exists( 'Zibi_Name' ) ) {
	Zibi_Name::uninstall();
}
