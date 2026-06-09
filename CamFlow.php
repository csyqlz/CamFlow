<?php
/**
 * Plugin Name: CamFlow
 * Plugin URI: https://www.camwt.com
 * Description: 面向 WP 系统的内容互动自动化工具，支持用户池、评论、社区帖、AI 接入和运行日志。
 * Version: 1.2.6
 * Author: CAMWT
 * Author URI: https://www.camwt.com
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Text Domain: camflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ZIBI_NAME_VERSION', '1.2.6' );
define( 'ZIBI_NAME_FILE', __FILE__ );
define( 'ZIBI_NAME_DIR', plugin_dir_path( __FILE__ ) );
define( 'ZIBI_NAME_URL', plugin_dir_url( __FILE__ ) );

require_once ZIBI_NAME_DIR . 'includes/class-zibi-name.php';

register_activation_hook( __FILE__, array( 'Zibi_Name', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Zibi_Name', 'deactivate' ) );

Zibi_Name::init();
