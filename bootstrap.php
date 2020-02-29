<?php

/**
 * Plugin Name: AAM-CLI
 * Description: Extends WP CLI with AAM specific commands
 * Version: 0.0.1
 * Author: Vasyl Martyniuk <vasyl@vasyltech.com>
 * Author URI: https://vasyltech.com
 *
 * -------
 * LICENSE: This file is subject to the terms and conditions defined in
 * file 'LICENSE', which is part of AAM Protected Media Files source package.
 **/

namespace AAM\AddOn\Cli;

use WP_CLI;

/**
 * Main add-on's bootstrap class
 *
 * @package AAM\AddOn\Cli
 * @author Vasyl Martyniuk <vasyl@vasyltech.com>
 * @version 0.0.1
 */
class Bootstrap
{

    /**
     * Undocumented function
     *
     * @return void
     */
    public static function register()
    {
        // Register WP-CLI commands
        if (class_exists('WP_CLI')) {
            WP_CLI::add_command('aam', 'AAM\AddOn\Cli\Commands');
        }
    }

    /**
     * Activation hook
     *
     * @return void
     *
     * @access public
     * @version 0.0.1
     */
    public static function activate()
    {
        global $wp_version;

        if (version_compare(PHP_VERSION, '5.6.40') === -1) {
            exit(__('PHP 5.6.40 or higher is required.'));
        } elseif (version_compare($wp_version, '4.7.0') === -1) {
            exit(__('WP 4.7.0 or higher is required.'));
        } elseif (!defined('AAM_VERSION') || (version_compare(AAM_VERSION, '6.3.3') === -1)) {
            exit(__('Free Advanced Access Manager plugin 6.3.3 or higher is required.'));
        }
    }

}

if (defined('ABSPATH')) {
    // Activation hooks
    register_activation_hook(__FILE__, __NAMESPACE__ . '\Bootstrap::activate');

    require_once __DIR__ . '/commands.php';

    add_action('plugins_loaded', function() {
        \AAM\AddOn\Cli\Bootstrap::register();
    });
}