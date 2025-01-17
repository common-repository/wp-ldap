<?php
/**
 * The WP-LDAP plugin for WordPress.
 *
 * WordPress plugin header information:
 *
 * * Plugin Name: WP-LDAP
 * * Plugin URI: https://github.com/meitar/wp-ldap
 * * Description: Feature-rich LDAP connector for WordPress and WP Multisite.
 * * Version: 0.1.1
 * * Author: Meitar Moscovitz <meitarm@gmail.com>
 * * Author URI: https://maymay.net/
 * * License: GPL-3.0
 * * License URI: https://www.gnu.org/licenses/gpl-3.0.en.html
 * * Text Domain: wp-ldap
 * * Domain Path: /languages
 *
 * @link https://developer.wordpress.org/plugins/the-basics/header-requirements/
 *
 * @license https://www.gnu.org/licenses/gpl-3.0.en.html
 *
 * @copyright Copyright (c) 2017 by Meitar Moscovitz
 *
 * @package WordPress\Plugin\WP-LDAP
 */

namespace WP_LDAP;

require_once plugin_dir_path( __FILE__ ) . '/includes/class-wp-ldap-user.php';
require_once plugin_dir_path( __FILE__ ) . '/includes/class-wp-ldap-api.php';
require_once plugin_dir_path( __FILE__ ) . '/includes/class-wp-ldap-search-result.php';

if (!defined('ABSPATH')) { exit; } // Disallow direct HTTP access.

/**
 * Base class that WordPress uses to register and initialize plugin.
 */
class WP_LDAP {

    /**
     * String to prefix option names, settings, etc. in shared spaces.
     *
     * Some WordPress data storage areas are basically one globally
     * shared namespace. For example, names of options saved in WP's
     * options table must be globally unique. When saving data in any
     * such shared space, we need to prefix the name we use.
     *
     * @var string
     */
    const prefix = 'wp_ldap_';

    /**
     * Get the current network's search base DN setting.
     *
     * @return string
     */
    static private function getSearchBaseDN () {
        return API::sanitize_dn( get_network_option(
            null,
            self::prefix . 'search_base_dn',
            'dc=' . str_replace( '.', ',dc=', parse_url( get_network_option( null, 'siteurl' ), PHP_URL_HOST ) )
        ) );
    }

    /**
     * Get an LDAP API object.
     *
     * @return \WP_LDAP\API
     */
    static private function getLdapApi () {
        return new API(
            esc_url_raw(
                get_network_option( null, self::prefix . 'connect_uri' ),
                array('ldap', 'ldaps', 'ldapi')
            ),
            API::sanitize_dn( get_network_option( null, self::prefix . 'bind_dn' ) ),
            get_network_option( null, self::prefix . 'bind_password' )
        );
    }

    /**
     * Entry point for the WordPress framework into plugin code.
     *
     * This is the method called when WordPress loads the plugin file.
     * It is responsible for "registering" the plugin's main functions
     * with the {@see https://codex.wordpress.org/Plugin_API WordPress Plugin API}.
     *
     * @uses add_action()
     * @uses register_activation_hook()
     * @uses register_deactivation_hook()
     *
     * @return void
     */
    public static function register () {
        add_action( 'plugins_loaded', array( __CLASS__, 'registerL10n' ) );
        add_action( 'init', array( __CLASS__, 'initialize' ) );
        add_action( 'wpmu_options', array( __CLASS__, 'wpmu_options' ) );
        add_action( 'update_wpmu_options', array( __CLASS__, 'update_wpmu_options' ) );
        add_action( 'wpmu_new_user', array( __CLASS__, 'wpmu_new_user' ) );
        add_action( 'profile_update', array( __CLASS__, 'profile_update' ), 150, 2 ); // run late
        add_action( 'user_profile_update_errors', array( __CLASS__, 'user_profile_update_errors' ), 10, 3 );
        add_action( 'after_password_reset', array( __CLASS__, 'after_password_reset' ), 10, 2 );
        add_action( 'shutdown', array( __CLASS__, 'shutdown' ) );

        register_activation_hook( __FILE__, array( __CLASS__, 'activate' ) );
        register_deactivation_hook( __FILE__, array( __CLASS__, 'deactivate' ) );
    }

    /**
     * Loads localization files from plugin's languages directory.
     *
     * @uses load_plugin_textdomain()
     *
     * @return void
     */
    public static function registerL10n () {
        load_plugin_textdomain('wp-ldap', false, dirname(plugin_basename(__FILE__)) . '/languages/');
    }

    /**
     * Loads plugin componentry and calls that component's register()
     * method. Called at the WordPress `init` hook.
     *
     * @return void
     */
    public static function initialize () {
        // TODO
    }

    /**
     * Prints the Network-wide LDAP configuration settings.
     *
     * @return void
     */
    public static function wpmu_options () {
        require_once plugin_dir_path( __FILE__ ) . '/admin/network-settings.php' ;
    }

    /**
     * Saves Network Settings.
     */
    public static function update_wpmu_options () {
        if ( $_POST ) {
            $options = array( self::prefix . 'connect_uri', self::prefix . 'bind_dn', self::prefix . 'bind_password', self::prefix . 'search_base_dn' );
            $updated_options = array_intersect_key( $_POST, array_flip( $options ) );
            foreach ( $updated_options as $option => $value ) {
                switch ( $option ) {
                    case self::prefix . 'connect_uri':
                        $p = parse_url( $value );
                        if ( 'ldap' === $p['scheme'] ) {
                            // force loopback IP address
                            $value = 'ldap://127.0.0.1';
                            if ( ! empty( $p['port'] ) ) {
                                $value .= ":{$p['port']}";
                            }
                            $value .= '/';
                        }
                        $value = esc_url_raw( $value, array( 'ldap', 'ldaps', 'ldapi' ) );
                        break;
                    case self::prefix . 'bind_dn':
                    case self::prefix . 'search_base_dn':
                        $value = API::sanitize_dn( $value );
                        break;
                    default:
                        $value = filter_var( $value, FILTER_SANITIZE_STRING );
                        break;
                }
                update_network_option( null, $option, $value );
            }
        }
    }

    /**
     * Checks the LDAP DIT for an existing user to link, or adds one.
     *
     * @param int $user_id
     *
     * @see https://developer.wordpress.org/reference/hooks/wpmu_new_user/
     */
    public static function wpmu_new_user ( $user_id ) {
        $WP_User = get_userdata( $user_id );

        $LDAP = self::getLdapApi();
        if ( ! $LDAP->bind() ) {
            // TODO: Record an admin notice that this failed.
        }

        // Search to see if we have that user in the LDAP DIT already.
        $sb = self::getSearchBaseDN();
        $LDAP->setBaseDN( $sb );
        $search_results = $LDAP->search(
            '(&(objectClass=inetOrgPerson)(uid=' . API::escape_filter( $WP_User->user_login ) . '))'
        );

        if ( 1 > count( $search_results ) ) {
            $LDAP_User = new \WP_LDAP\User();
            $LDAP_User->setWordPressUser( $WP_User );
            $LDAP->add(
                $LDAP_User->getEntityDN( $sb ),
                /**
                 * Filters the LDAP entry array.
                 *
                 * @param array $entry The array of LDAP object attributes and values.
                 *
                 * @see https://secure.php.net/manual/en/function.ldap-add.php
                 * @see \WP_LDAP\API::wp2entity()
                 */
                apply_filters( self::prefix . 'user_to_entity' , $LDAP_User->wp2entity() )
            );
        } else {
            foreach( $search_results as $i => $r ) {
            }
        }

        $LDAP->disconnect();
    }

    /**
     * Method to run when the plugin is activated by a user in the
     * WordPress Dashboard admin screen.
     *
     * @uses My_WP_Plugin::checkPrereqs()
     *
     * @return void
     */
    public static function activate () {
        self::checkPrereqs();
    }

    /**
     * Checks system requirements and exits if they are not met.
     *
     * This first checks to ensure minimum WordPress versions have
     * been satisfied. If not, the plugin deactivates and exits.
     *
     * @global $wp_version
     *
     * @uses $wp_version
     * @uses self::get_minimum_wordpress_version()
     * @uses deactivate_plugins()
     * @uses plugin_basename()
     *
     * @return void
     */
    public static function checkPrereqs () {
        global $wp_version;
        $min_wp_version = self::get_minimum_wordpress_version();
        if ( version_compare( $min_wp_version, $wp_version ) > 0 ) {
            deactivate_plugins( plugin_basename( __FILE__ ) );
            wp_die( sprintf(
                __( 'WP-LDAP requires at least WordPress version %1$s. You have WordPress version %2$s.', 'wp-ldap' ),
                $min_wp_version, $wp_version
            ) );
        }

        if ( ! function_exists( 'ldap_connect' ) ) {
            deactivate_plugins( plugin_basename( __FILE__ ) );
            wp_die( __( 'WP-LDAP requires the PHP LDAP extension. It is missing, or not available.', 'wp-ldap' ) );
        }
    }

    /**
     * Returns the "Requires at least" value from plugin's readme.txt.
     *
     * @link https://wordpress.org/plugins/about/readme.txt WordPress readme.txt standard
     *
     * @return string
     */
    public static function get_minimum_wordpress_version () {
        $lines = @file(plugin_dir_path(__FILE__) . 'readme.txt');
        foreach ($lines as $line) {
            preg_match('/^Requires at least: ([0-9.]+)$/', $line, $m);
            if ($m) {
                return $m[1];
            }
        }
    }

    /**
     * Method to run when the plugin is deactivated by a user in the
     * WordPress Dashboard admin screen.
     *
     * @return void
     */
    public static function deactivate () {
        // TODO
    }

    /**
     * Updates a user's profile information in the LDAP DIT.
     *
     * @param int $user_id
     * @param object $old_user_data
     *
     * @see https://developer.wordpress.org/reference/hooks/profile_update/
     */
    public static function profile_update ( $user_id, $old_user_data ) {
        $LDAP_User = new User();
        $LDAP_User->setWordPressUser( get_userdata( $user_id ) );
        $LDAP = self::getLdapApi();
        if ( ! $LDAP->bind() ) {
            // TODO: Warn with Admin notice?
        }
        $sb = self::getSearchBaseDN();
        $LDAP->setBaseDN( $sb );
        $LDAP->modify(
            $LDAP_User->getEntityDN( $sb ),
            apply_filters( self::prefix . 'user_to_entity', $LDAP_User->wp2entity() )
        );
        $LDAP->disconnect();
    }

    /**
     * Checks for a user password change and updates the LDAP DIT.
     *
     * This is the last chance to grab the plaintext password when an
     * update to a user profile occurs.
     *
     * @param WP_Error $errors
     * @param bool $update
     * @param object $user
     *
     * @see https://developer.wordpress.org/reference/hooks/user_profile_update_errors/
     */
    public static function user_profile_update_errors ( $errors, $update, $user ) {
        if ( is_int( $errors->get_error_code() ) ) {
            return; // There were errors, bail.
        }

        if ( empty( $user->user_pass ) ) {
            return; // Password is not being updated, nothing to do.
        }

        $LDAP_User = new User();
        $LDAP_User->setWordPressUser( $user );
        $LDAP = self::getLdapApi();
        if ( ! $LDAP->bind() ) {
            // TODO: Warn with Admin notice?
        }
        $sb = self::getSearchBaseDN();
        $LDAP->setBaseDN( $sb );
        if ( $update ) {
            $LDAP->modify(
                $LDAP_User->getEntityDN( $sb ),
                array( 'userPassword' => API::hashPassword( $user->user_pass ) )
            );
        } else {
            $LDAP->add(
                $LDAP_User->getEntityDN( $sb ),
                array_merge(
                    array( 'userPassword' => API::hashPassowrd( $user->user_pass ) ),
                    apply_filters( self::prefix . 'user_to_entity', $LDAP_User->wp2entity() )
                )
            );
        }
        $LDAP->disconnect();
    }

    /**
     * Updates the user's password in the LDAP DIT when it is reset.
     *
     * @param object $user
     * @param string $new_pass
     *
     * @see https://developer.wordpress.org/reference/hooks/after_password_reset/
     */
    public static function after_password_reset ( $user, $new_pass ) {
        $LDAP_User = new User();
        $LDAP_User->setWordPressUser( $user );
        $LDAP = self::getLdapApi();
        if ( ! $LDAP->bind() ) {
            // TODO: Warn with Admin notice?
        }
        $sb = self::getSearchBaseDN();
        $LDAP->setBaseDN( $sb );
        $LDAP->modify(
            $LDAP_User->getEntityDN( $sb ),
            array( 'userPassword' => API::hashPassword( $new_pass ) )
        );
        $LDAP->disconnect();
    }

    /**
     * Cleans up any remaining messiness as WP's PHP execution ends.
     *
     * @see https://developer.wordpress.org/reference/hooks/shutdown/
     */
    public static function shutdown () {
    }

}

WP_LDAP::register();
