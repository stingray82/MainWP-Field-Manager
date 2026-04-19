<?php
/**
 * Plugin Name:       MainWP Field Manager
 * Description:       Adds configurable custom fields to MainWP sites, overview widgets, and bulk editing tools.
 * Tested up to:      6.9.4
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Version:           1.0.0-beta.2
 * Author:            Reallyusefulplugins.com
 * Author URI:        https://reallyusefulplugins.com
 * License:           GPL3
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       mainwp-field-manager
 * Website:           https://reallyusefulplugins.com
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define('MWPFM_VERSION', '1.0.0-beta.2');
define( 'MWPFM_PLUGIN_FILE', __FILE__ );
define( 'MWPFM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

define( 'MWPFM_OPTION_FIELDS', 'mainwp_field_manager_custom_fields' );
define( 'MWPFM_OPTION_PLUGIN_SETTINGS', 'mainwp_field_manager_plugin_settings' );

define( 'MWPFM_SETTINGS_GROUP', 'mainwp_field_manager_group' );

define( 'MWPFM_MENU_SLUG', 'mainwp-field-manager' );
define( 'MWPFM_BULK_MENU_SLUG', 'mainwp-field-manager-bulk' );
define( 'MWPFM_SETTINGS_MENU_SLUG', 'mainwp-field-manager-settings' );

// =============================================================================
// SHARED HELPERS
// =============================================================================

/**
 * Get configured fields.
 *
 * @return array
 */
function mwpfm_get_custom_fields_config() {
	$fields = get_option( MWPFM_OPTION_FIELDS, array() );
	return is_array( $fields ) ? $fields : array();
}

/**
 * Get one field config by key.
 *
 * @param string $key Field key.
 * @return array|null
 */
function mwpfm_get_field_config_by_key( $key ) {
	$fields = mwpfm_get_custom_fields_config();

	foreach ( $fields as $field ) {
		if ( isset( $field['key'] ) && $field['key'] === $key ) {
			return $field;
		}
	}

	return null;
}

/**
 * Get fields enabled for overview widgets.
 *
 * @return array
 */
function mwpfm_get_countable_fields_config() {
	$fields = mwpfm_get_custom_fields_config();

	return array_values(
		array_filter(
			$fields,
			function ( $field ) {
				return ! empty( $field['key'] ) && ! empty( $field['label'] ) && ! empty( $field['show_counts'] );
			}
		)
	);
}

/**
 * Parse dropdown options from textarea.
 *
 * @param string $raw Raw textarea.
 * @return array
 */
function mwpfm_parse_select_options( $raw ) {
	$lines   = preg_split( '/\r\n|\r|\n/', (string) $raw );
	$options = array();

	if ( empty( $lines ) ) {
		return $options;
	}

	foreach ( $lines as $line ) {
		$line = trim( wp_strip_all_tags( $line ) );

		if ( '' === $line ) {
			continue;
		}

		$options[] = $line;
	}

	return array_values( array_unique( $options ) );
}

/**
 * Parse rename rules from textarea.
 *
 * Format:
 * Old => New
 *
 * @param string $raw Raw textarea.
 * @return array
 */
function mwpfm_parse_option_renames( $raw ) {
	$lines   = preg_split( '/\r\n|\r|\n/', (string) $raw );
	$renames = array();

	if ( empty( $lines ) ) {
		return $renames;
	}

	foreach ( $lines as $line ) {
		$line = trim( wp_strip_all_tags( $line ) );

		if ( '' === $line || false === strpos( $line, '=>' ) ) {
			continue;
		}

		$parts = explode( '=>', $line, 2 );
		$old   = isset( $parts[0] ) ? trim( $parts[0] ) : '';
		$new   = isset( $parts[1] ) ? trim( $parts[1] ) : '';

		if ( '' === $old || '' === $new || $old === $new ) {
			continue;
		}

		$renames[ $old ] = $new;
	}

	return $renames;
}

/**
 * Get plugin settings.
 *
 * @return array
 */
function mwpfm_get_plugin_settings() {
	$settings = get_option(
		MWPFM_OPTION_PLUGIN_SETTINGS,
		array(
			'prerelease_db_flag' => 'stable',
		)
	);

	if ( ! is_array( $settings ) ) {
		$settings = array();
	}

	return wp_parse_args(
		$settings,
		array(
			'prerelease_db_flag' => 'stable',
		)
	);
}

/**
 * Get prerelease DB flag.
 *
 * @return string
 */
function mwpfm_get_prerelease_db_flag() {
	$settings = mwpfm_get_plugin_settings();
	$flag     = isset( $settings['prerelease_db_flag'] ) ? $settings['prerelease_db_flag'] : 'stable';

	return in_array( $flag, array( 'stable', 'dev' ), true ) ? $flag : 'stable';
}

/**
 * Sanitize plugin settings.
 *
 * @param mixed $input Raw input.
 * @return array
 */
function mwpfm_sanitize_plugin_settings( $input ) {
	$existing = mwpfm_get_plugin_settings();

	if ( ! is_array( $input ) ) {
		return $existing;
	}

	$flag = isset( $input['prerelease_db_flag'] ) ? sanitize_key( wp_unslash( $input['prerelease_db_flag'] ) ) : 'stable';

	if ( ! in_array( $flag, array( 'stable', 'dev' ), true ) ) {
		$flag = 'stable';
	}

	return array(
		'prerelease_db_flag' => $flag,
	);
}

/**
 * Is using the stable database channel.
 *
 * @return bool
 */
function mwpfm_is_stable_db() {
	return 'stable' === mwpfm_get_prerelease_db_flag();
}

/**
 * Is using the development database channel.
 *
 * @return bool
 */
function mwpfm_is_dev_db() {
	return 'dev' === mwpfm_get_prerelease_db_flag();
}

/**
 * Alias for non-stable.
 *
 * @return bool
 */
function mwpfm_is_prerelease_db() {
	return ! mwpfm_is_stable_db();
}

/**
 * Add a settings/admin notice.
 *
 * @param string $message Notice text.
 * @param string $type    error|warning|success|info|updated.
 * @return void
 */
function mwpfm_add_settings_error( $message, $type = 'error' ) {
	add_settings_error(
		MWPFM_OPTION_FIELDS,
		'mwpfm_notice_' . wp_generate_uuid4(),
		$message,
		$type
	);
}

/**
 * Get MainWP websites for current user.
 *
 * @return array
 */
function mwpfm_get_current_user_websites() {
	if ( ! class_exists( '\MainWP\Dashboard\MainWP_DB' ) ) {
		return array();
	}

	return \MainWP\Dashboard\MainWP_DB::instance()->get_websites_for_current_user();
}

/**
 * Get saved values for a field across all websites.
 *
 * @param string $field_key Field key.
 * @return array
 */
function mwpfm_get_saved_values_for_field( $field_key ) {
	$values   = array();
	$websites = mwpfm_get_current_user_websites();

	if ( empty( $websites ) ) {
		return $values;
	}

	foreach ( $websites as $website ) {
		$value = apply_filters( 'mainwp_getwebsiteoptions', '', $website->id, $field_key );
		$value = is_string( $value ) ? trim( $value ) : '';

		if ( '' !== $value ) {
			$values[] = $value;
		}
	}

	return array_values( array_unique( $values ) );
}

/**
 * Get a website object by id.
 *
 * @param int $site_id Site ID.
 * @return object|null
 */
function mwpfm_get_website_by_id( $site_id ) {
	$websites = mwpfm_get_current_user_websites();

	foreach ( $websites as $website ) {
		if ( (int) $website->id === (int) $site_id ) {
			return $website;
		}
	}

	return null;
}

/**
 * Get a website label.
 *
 * @param object $website Website object.
 * @return string
 */
function mwpfm_get_website_label( $website ) {
	if ( isset( $website->name ) && '' !== trim( (string) $website->name ) ) {
		return $website->name;
	}

	if ( isset( $website->url ) && '' !== trim( (string) $website->url ) ) {
		return $website->url;
	}

	if ( isset( $website->siteurl ) && '' !== trim( (string) $website->siteurl ) ) {
		return $website->siteurl;
	}

	return '#' . ( isset( $website->id ) ? (int) $website->id : 0 );
}

/**
 * Sanitize a single field value according to field type.
 *
 * @param mixed $value Raw value.
 * @param array $field Field config.
 * @return string
 */
function mwpfm_sanitize_field_value( $value, $field ) {
	$type = isset( $field['type'] ) ? $field['type'] : 'text';

	if ( 'textarea' === $type ) {
		return sanitize_textarea_field( $value );
	}

	$value = sanitize_text_field( $value );

	if ( 'select' !== $type ) {
		return $value;
	}

	$options = ! empty( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();

	if ( in_array( $value, $options, true ) ) {
		return $value;
	}

	return '';
}

/**
 * Update one MainWP website option.
 *
 * @param int    $site_id Site ID.
 * @param string $field_key Field key.
 * @param string $value Value.
 * @return void
 */
function mwpfm_update_site_field_value( $site_id, $field_key, $value ) {
	apply_filters( 'mainwp_updatewebsiteoptions', false, (int) $site_id, $field_key, $value );
}

/**
 * Get one MainWP website option.
 *
 * @param int    $site_id Site ID.
 * @param string $field_key Field key.
 * @return string
 */
function mwpfm_get_site_field_value( $site_id, $field_key ) {
	$value = apply_filters( 'mainwp_getwebsiteoptions', '', (int) $site_id, $field_key );
	return is_string( $value ) ? $value : '';
}

/**
 * Migrate a dropdown option value across sites.
 *
 * @param string $field_key Field key.
 * @param string $old_value Old value.
 * @param string $new_value New value.
 * @return int
 */
function mwpfm_migrate_select_option_value( $field_key, $old_value, $new_value ) {
	$updated  = 0;
	$websites = mwpfm_get_current_user_websites();

	if ( empty( $websites ) ) {
		return $updated;
	}

	foreach ( $websites as $website ) {
		$current = mwpfm_get_site_field_value( $website->id, $field_key );
		$current = trim( $current );

		if ( $current !== $old_value ) {
			continue;
		}

		mwpfm_update_site_field_value( $website->id, $field_key, $new_value );
		++$updated;
	}

	return $updated;
}

// =============================================================================
// LOAD MODULES
// =============================================================================

require_once MWPFM_PLUGIN_DIR . 'includes/mwpfm-fields.php';
require_once MWPFM_PLUGIN_DIR . 'includes/mwpfm-bulk.php';
require_once MWPFM_PLUGIN_DIR . 'includes/mwpfm-settings.php';


// ──────────────────────────────────────────────────────────────────────────
// Updater bootstrap
// ──────────────────────────────────────────────────────────────────────────
add_action(
	'plugins_loaded',
	function () {
		$updater_file = MWPFM_PLUGIN_DIR . 'includes/updater.php';

		if ( ! file_exists( $updater_file ) ) {
			return;
		}

		require_once $updater_file;

		$updater_config = array(
			'vendor'      => 'rup',
			'plugin_file' => plugin_basename( MWPFM_PLUGIN_FILE ),
			'slug'        => 'mainwp-field-manager',
			'name'        => 'MainWP Field Manager',
			'version'     => MWPFM_VERSION,
			'key'         => '',
			'server'      => 'https://raw.githubusercontent.com/stingray82/MainWP-Field-Manager/main/uupd/index.json',
		);

		\RUP\Updater\Updater_V2::register( $updater_config );
	},
	20
);

// Pre-Release Filter against our ottion

add_filter(
	'uupd/allow_prerelease/rup/mainwp-field-manager',
	function ( $allow, $vendor, $slug, $instance_key ) {
		return mwpfm_is_dev_db();
	},
	10,
	4
);
