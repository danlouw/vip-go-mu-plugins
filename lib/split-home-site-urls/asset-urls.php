<?php
/*
 Plugin name: Static File Host
 Description: Rewrite static assets to be served from a different URL
 Version: 1.1
 Author: Erick Hitter
 Author URI: http://www.ethitter.com
 License: GPLv2
*/

/*
This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; version 2 of the License.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

class ETH_CDN {
	/**
	 * Singleton
	 */
	private static $instance = null;

	/**
	 * Class variables
	 */
	private $static_host = null;

	private static $network_domains    = null;
	private $network_domains_cache_key = 'eth_cdn_network_domains';

	private static $mapped_domains     = null;
	private $mapped_domains_cache_key  = 'eth_cdn_mapped_domains';

	private $mtimes_cache_key = 'eth_cdn_mtimes';

	private $cache_life = 1800;

	/**
	 * Silence is golden!
	 */
	private function __construct() {}

	/**
	 * Instantiate singleton
	 */
	public static function get_instance() {
		if ( ! is_a( self::$instance, __CLASS__ ) ) {
			self::$instance = new self;
			self::$instance->setup();
		}

		return self::$instance;
	}

	/**
	 * Register plugin's actions and filters
	 *
	 * @uses add_action()
	 * @uses add_filter()
	 * @return null
	 */
	private function setup() {
		add_action( 'plugins_loaded', array( $this, 'action_plugins_loaded' ) );

		// Enqueued assets
		add_filter( 'script_loader_src', array( $this, 'filter_enqueued_asset' ), 10, 2 );
		add_filter( 'style_loader_src', array( $this, 'filter_enqueued_asset' ), 10, 2 );

		// Theme assets
		add_filter( 'template_directory_uri', array( $this, 'filter_theme_paths' ) );
		add_filter( 'stylesheet_directory_uri', array( $this, 'filter_theme_paths' ) );
		add_filter( 'stylesheet_uri', array( $this, 'filter_stylesheet_uri' ) );

		// Plugin assets
		add_filter( 'plugins_url', array( $this, 'filter_plugin_paths' ) );
		add_filter( 'jetpack_static_url', array( $this, 'filter_jetpack_static_urls' ), 999 );

		// Paths to uploaded files
		add_filter( 'pre_option_upload_url_path', array( $this, 'filter_upload_url_path' ) );

		// Concatenated assets
		add_filter( 'ngx_http_concat_site_url', array( $this, 'filter_concat_base_url' ) );

		// DNS Prefetch
		add_action( 'wp_head', array( $this, 'action_wp_head_early' ), -999 );
	}

	/**
	 * Expose static host for filtering
	 *
	 * @uses apply_filters()
	 * @action plugins_loaded
	 * @return null
	 */
	public function action_plugins_loaded() {
		$host              = defined( 'ETH_CDN_STATIC_HOST' ) ? ETH_CDN_STATIC_HOST : 's.ethitter.com';
		$this->static_host = apply_filters( 'eth_cdn_static_host', $host );
	}

	/**
	 * Rewrite enqueued assets to static host
	 *
	 * @param string $src
	 * @param string $handle
	 * @uses this::staticize()
	 * @filter script_loader_src
	 * @filter style_loader_src
	 * @return string
	 */
	public function filter_enqueued_asset( $src, $handle ) {
		return $this->staticize( $src, $handle );
	}

	/**
	 * Rewrite theme assets to static host
	 *
	 * @param string $uri
	 * @uses this::staticize()
	 * @uses current_filter()
	 * @filter template_directory_uri
	 * @filter stylesheet_directory_uri
	 * @return string
	 */
	public function filter_theme_paths( $uri ) {
		return $this->staticize( $uri, current_filter() );
	}

	/**
	 * Rewrite theme's stylesheet to static host
	 *
	 * @param string $uri
	 * @uses this::staticize()
	 * @uses current_filter()
	 * @filter template_directory_uri
	 * @filter stylesheet_uri
	 * @return string
	 */
	public function filter_stylesheet_uri( $uri ) {
		return $this->staticize( $uri, current_filter() );
	}

	/**
	 * Rewrite certain plugin assets to static host
	 * Since plugins can do many crazy things, only select extensions are rewritten
	 *
	 * @param string $uri
	 * @uses this::staticize()
	 * @uses current_filter
	 * @filter plugins_url
	 * @return string
	 */
	public function filter_plugin_paths( $uri ) {
		$allowed_exts = array(
			'gif',
			'png',
			'jpg',
			'jpeg',
			'js',
			'css',
		);

		$extension = pathinfo( $uri, PATHINFO_EXTENSION );
		if ( ! in_array( $extension, $allowed_exts ) ) {
			return $uri;
		}

		return $this->staticize( $uri, current_filter() );
	}

	/**
	 * Rewrite Jetpack's static paths if they aren't served from an a8c CDN
	 *
	 * @param string $uri
	 * @uses this::staticize()
	 * @uses current_filter
	 * @filter jetpack_static_url
	 * @return string
	 */
	public function filter_jetpack_static_urls( $uri ) {
		// Extract hostname from URL
		$host = parse_url( $uri, PHP_URL_HOST );

		// Explode hostname on '.'
		$exploded_host = explode( '.', $host );

		// Retrieve the name and TLD
		if ( count( $exploded_host ) > 1 ) {
			$name = $exploded_host[ count( $exploded_host ) - 2 ];
			$tld = $exploded_host[ count( $exploded_host ) - 1 ];
			// Rebuild domain excluding subdomains
			$domain = $name . '.' . $tld;
		} else {
			$domain = $host;
		}

		// Array of Automattic domains
		$domain_whitelist = array( 'wordpress.com', 'wp.com' );

		// Return $uri if an Automattic domain, as it's already CDN'd
		if ( in_array( $domain, $domain_whitelist ) ) {
			return $uri;
		}

		// URI isn't served from a8c CDN, so serve fro mine
		return $this->staticize( $uri, current_filter() );
	}

	/**
	 * Rewrite all upload URLs to point to the CDN
	 * Ensures images not processed with Photon are at least served from the CDN
	 *
	 * @param string $url
	 * @uses get_current_blog_id()
	 * @uses esc_url_raw()
	 * @filter pre_option_upload_url_path
	 * @return string
	 */
	public function filter_upload_url_path( $url ) {
		$url = 'https://' . $this->static_host . '/wp-content/uploads';

		if ( 2 === get_current_blog_id() ) {
			$url .= '/sites/' . get_current_blog_id();
		}

		return esc_url_raw( $url );
	}

	/**
	 * Rewrite URLs for concatenated assets
	 *
	 * @param string $url
	 * @uses set_url_scheme
	 * @filter ngx_http_concat_site_url
	 * @return string
	 */
	public function filter_concat_base_url( $url ) {
		return set_url_scheme( 'https://' . $this->static_host );
	}

	/**
	 * Pretech DNS for certain common domains
	 */
	public function action_wp_head_early() {
		?>
		<link rel='dns-prefetch' href='//s2.e15r.co'>
		<link rel='dns-prefetch' href='//stats.e15r.co'>
		<link rel='dns-prefetch' href='//ethitter.com'>
		<link rel='dns-prefetch' href='//i.ethitter.com'>
		<link rel='dns-prefetch' href='//stats.ethitter.com'>
		<?php
	}

	/**
	 ** UTILITY METHODS
	 **/

	/**
	 * Rewrite host to static
	 *
	 * @param string $src
	 * @param string $context Optional.
	 * @uses this::should_staticize()
	 * @uses apply_filters
	 * @uses this::add_cache_buster()
	 * @return string
	 */
	private function staticize( $src, $context = '' ) {
		// Abort if called too early
		if ( ! $this->static_host ) {
			return $src;
		}

		// Don't rewrite PHP URLs
		$extension = pathinfo( $src, PATHINFO_EXTENSION );
		if ( 0 === strpos( $extension, 'php' ) ) {
			return $src;
		}

		// Attempt to rewrite if proper conditions are met.
		$parsed_host = parse_url( $src, PHP_URL_HOST );
		if ( $parsed_host && $parsed_host !== $this->static_host && $this->should_staticize( $parsed_host ) && apply_filters( 'eth_cdn_staticize', true, $parsed_host, $src, $context ) ) {
			$src = str_replace( $parsed_host, $this->static_host, $src );
		}

		// Add a cache buster based on the file's modified time
		// $src = $this->add_cache_buster( $src );

		// Return something!
		return $src;
	}

	/**
	 * Is current host appropriate for staticization?
	 *
	 * @param string $host
	 * @uses this::get_domains()
	 * @uses apply_filters
	 * @return bool
	 */
	private function should_staticize( $host ) {
		$domains = $this->get_domains();

		return (bool) apply_filters( 'eth_cdn_should_staticize', isset( $domains[ $host ] ), $host );
	}

	/**
	 * Build array of domains associated with this site
	 *
	 * @uses this::get_network_domains()
	 * @uses this::get_mapped_domains()
	 * @return array
	 */
	private function get_domains() {
		// Base WP URLs
		$network = array();

		if ( is_multisite() ) {
			$network = $this->get_network_domains();
			if ( ! is_array( $network ) ) {
				$network = array();
			}
		} else {
			$network[ parse_url( home_url( '/' ), PHP_URL_HOST ) ] = 1;
			$network[ parse_url( site_url( '/' ), PHP_URL_HOST ) ] = 1;
		}

		// WordPress MU domain mapping
		$mapped = array();
		if ( function_exists( 'domain_mapping_siteurl' ) ) {
			$mapped = $this->get_mapped_domains();
		}

		return array_merge( $mapped, $network );
	}

	/**
	 * Build array of site addresses assigned by WP
	 *
	 * @global $wpdb
	 * @uses get_site_transient()
	 * @uses set_site_transient()
	 * @return array
	 */
	private function get_network_domains() {
		if ( null === self::$network_domains ) {
			// Check the persistent cache first
			$domains = get_site_transient( $this->network_domains_cache_key );
			if ( is_array( $domains ) ) {
				self::$network_domains = $domains;
				return $domains;
			}

			// Rebuild cache, both local and persistent, if needed
			global $wpdb;

			$domains = array();

			// Retrieve all domains
			$domain_objects = $wpdb->get_results( "SELECT * FROM {$wpdb->blogs}" );

			if ( $domain_objects ) {
				foreach ( $domain_objects as $domain_object ) {
					$domains[ $domain_object->domain ] = (int) $domain_object->blog_id;
				}
			}

			self::$network_domains = $domains;
			set_site_transient( $this->network_domains_cache_key, self::$network_domains, $this->cache_life );
		}

		return self::$network_domains;
	}

	/**
	 * Build array of domains mapped to sites on this network
	 *
	 * @global $wpdb
	 * @uses get_site_transient()
	 * @uses set_site_transient()
	 * @return array
	 */
	private function get_mapped_domains() {
		if ( null === self::$mapped_domains ) {
			// Check the persistent cache first
			$domains = get_site_transient( $this->mapped_domains_cache_key );
			if ( is_array( $domains ) ) {
				self::$mapped_domains = $domains;
				return $domains;
			}

			// Rebuild cache, both local and persistent, if needed
			global $wpdb;

			$domains = array();

			// Retrieve all domains
			$domain_objects = $wpdb->get_results( "SELECT * FROM {$wpdb->dmtable}" );

			if ( $domain_objects ) {
				foreach ( $domain_objects as $domain_object ) {
					$domains[ $domain_object->domain ] = (int) $domain_object->blog_id;
				}
			}

			self::$mapped_domains = $domains;
			set_site_transient( $this->mapped_domains_cache_key, self::$mapped_domains, $this->cache_life );
		}

		return self::$mapped_domains;
	}

	/**
	 * Add a cache busting query string to each URL
	 *
	 * Uses Unix timestamp of file's modification time
	 *
	 * @param string $src
	 * @uses get_site_transient()
	 * @uses path_join()
	 * @uses set_site_transient()
	 * @uses add_query_arg()
	 * @return string
	 */
	private function add_cache_buster( $src ) {
		// Get the relative path of the file, if we can
		$path = parse_url( $src, PHP_URL_PATH );
		if ( false === $path || ! preg_match( '#[A-Z0-9\-_]+\.[A-Z0-9]+$#i', $path ) ) {
			return $src;
		}

		// Cache mtime for faster future lookups
		$key   = md5( $this->mtimes_cache_key . $path );
		$mtime = (int) get_site_transient( $key );

		// Not cached, so let
		if ( ! $mtime ) {
			// Defaults to be cached for the current $key
			$mtime  = time();
			$expiry = 900;

			// Need to strip the leading slash from the path, as ABSPATH is trailing slashed
			$path = substr( $path, 1 );

			// If we can access the path, get the file's modification time and cache the value for five minutes
			if ( $path ) {
				$path = path_join( ABSPATH, $path );
				if ( file_exists( $path ) ) {
					$mtime  = filemtime( $path );
					$expiry = 86400;
				}
			}

			// Always cache something, to reduce future lookups
			set_site_transient( $key, $mtime, $expiry );
		}

		// Only add if we have a value
		if ( $mtime ) {
			$src = add_query_arg( 'm', $mtime, $src );
		}

		return $src;
	}
}

ETH_CDN::get_instance();