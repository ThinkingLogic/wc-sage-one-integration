<?php

namespace ThinkingLogic;

class Logger {

	const OPTION_CUSTOM_NOTICES = 'tl_wc_sage_custom_notices';
	const OPTION_LOG_DEBUG = 'tl_wc_sage_log_debug';

	private static $_messages = '';
	private static $_log_debug = null;

	/**
	 * Adds an admin notice to be displayed to the user.
	 *
	 * @param string $message The message
	 */
	public static function addAdminNotice( string $message ) {
		self::log( $message );
		self::addCustomNotice( $message );
	}

	/**
	 * Adds an admin notice to be displayed to the user, formatted as a warning.
	 *
	 * @param string $message The message
	 */
	public static function addAdminWarning( string $message ) {
		self::log( 'WARN: ' . $message );
		self::addCustomNotice( '<span style="color: red; font-weight: bold">' . $message . '</span>' );
	}

	/**
	 * Removes admin notices.
	 */
	public static function clearAdminNotices() {
		self::$_messages = '';
		update_option( self::OPTION_CUSTOM_NOTICES, self::$_messages );
	}

	/**
	 * Displays admin notices.
	 */
	public static function showAdminNotices() {
		$messages = self::loadMessages();
		if ( empty( $messages ) ) {
			self::log( 'showAdminNotices - no notices' );
		} else {
			self::log( 'showAdminNotices showing notices' );
			echo '<div id="tl-sage-admin-message" class="notice is-dismissible">';
			echo $messages;
			echo '</div>';
			$js_file = plugin_dir_url( __FILE__ ) . '/js/display-wc-admin-messages.js';
			wp_enqueue_script( 'tl_sage_display_admin_message', $js_file, array( 'jquery' ), '1.0.0' );
		}
	}

	/**
	 * Logs a message to the wp debug log (wp-content/debug.log), if OPTION_LOG_DEBUG is set to true.
	 *
	 * @param string $message The message
	 */
	public static function debug( string $message ) {
		if ( self::isDebugging() ) {
			self::log( $message );
		}
	}

	/**
	 * Logs a message to the wp debug log (wp-content/debug.log), if both WP_DEBUG and WP_DEBUG_LOG are true.
	 *
	 * @param string $message The message
	 */
	public static function log( string $message ) {
		if ( constant( 'WP_DEBUG' ) && constant( 'WP_DEBUG_LOG' ) ) {
			error_log( "ThinkingLogicWCSage: " . $message );
		}
	}

	/**
	 * Adds a message to the Wordpress admin notices.
	 *
	 * @param string $message The message
	 */
	private static function addCustomNotice( string $message ) {
		self::loadMessages();
		self::$_messages .= '<p>' . $message . '</p>';
		update_option( self::OPTION_CUSTOM_NOTICES, self::$_messages );
	}

	private static function loadMessages() {
		if ( empty( self::$_messages ) ) {
			self::$_messages = get_option( self::OPTION_CUSTOM_NOTICES, '' );
		}

		return self::$_messages;
	}

	private static function isDebugging(): bool {
		if ( empty( self::$_log_debug ) ) {
			self::$_log_debug = get_option( self::OPTION_LOG_DEBUG, 'false' );
		}

		return self::$_log_debug === 'true';
	}

}