<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_post_mwpfm_export_settings', 'mwpfm_handle_export_settings' );
add_action( 'admin_post_mwpfm_import_settings', 'mwpfm_handle_import_settings' );

add_action(
	'admin_init',
	function () {
		$errors = get_transient( 'settings_errors' );

		if ( empty( $errors ) || ! is_array( $errors ) ) {
			return;
		}

		delete_transient( 'settings_errors' );

		foreach ( $errors as $error ) {
			add_settings_error(
				isset( $error['setting'] ) ? $error['setting'] : MWPFM_OPTION_FIELDS,
				isset( $error['code'] ) ? $error['code'] : 'mwpfm_notice',
				isset( $error['message'] ) ? $error['message'] : '',
				isset( $error['type'] ) ? $error['type'] : 'error'
			);
		}
	}
);

/**
 * Render plugin settings page.
 *
 * @return void
 */
function mwpfm_render_settings_page() {
	$settings = mwpfm_get_plugin_settings();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'MainWP Field Manager — Settings', 'mainwp-field-manager' ); ?></h1>

		<?php settings_errors( MWPFM_OPTION_FIELDS ); ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="mwpfm_prerelease_db_flag">
						<?php esc_html_e( 'Release Channel', 'mainwp-field-manager' ); ?>
					</label>
				</th>
				<td>
					<select
						id="mwpfm_prerelease_db_flag"
						name="<?php echo esc_attr( MWPFM_OPTION_PLUGIN_SETTINGS . '[prerelease_db_flag]' ); ?>"
					>
						<option value="stable" <?php selected( $settings['prerelease_db_flag'], 'stable' ); ?>>
							<?php esc_html_e( 'Stable (Recommended)', 'mainwp-field-manager' ); ?>
						</option>
						<option value="dev" <?php selected( $settings['prerelease_db_flag'], 'dev' ); ?>>
							<?php esc_html_e( 'Early Access (Dev)', 'mainwp-field-manager' ); ?>
						</option>
					</select>

					<p class="description">
						<?php esc_html_e(
							'Choose how you receive updates. Stable provides fully tested releases. Early Access gives you the latest features and improvements before release, but may include unfinished or experimental changes.',
							'mainwp-field-manager'
						); ?>
					</p>
				</td>
			</tr>
		</table>

		<?php submit_button( __( 'Save Settings', 'mainwp-field-manager' ) ); ?>

		<hr />

		<h2><?php esc_html_e( 'Export', 'mainwp-field-manager' ); ?></h2>
		<p><?php esc_html_e( 'Download field definitions and plugin settings as a JSON file.', 'mainwp-field-manager' ); ?></p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="mwpfm_export_settings" />
			<?php wp_nonce_field( 'mwpfm_export_settings', 'mwpfm_export_nonce' ); ?>
			<?php submit_button( __( 'Export Settings', 'mainwp-field-manager' ), 'secondary', 'submit', false ); ?>
		</form>

		<hr />

		<h2><?php esc_html_e( 'Import', 'mainwp-field-manager' ); ?></h2>
		<p><?php esc_html_e( 'Import field definitions and plugin settings from a previously exported JSON file.', 'mainwp-field-manager' ); ?></p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
			<input type="hidden" name="action" value="mwpfm_import_settings" />
			<?php wp_nonce_field( 'mwpfm_import_settings', 'mwpfm_import_nonce' ); ?>

			<input type="file" name="mwpfm_import_file" accept=".json,application/json" required />

			<p>
				<label>
					<input type="checkbox" name="mwpfm_import_overwrite" value="1" />
					<?php esc_html_e( 'Overwrite existing field definitions and settings', 'mainwp-field-manager' ); ?>
				</label>
			</p>

			<?php submit_button( __( 'Import Settings', 'mainwp-field-manager' ), 'secondary', 'submit', false ); ?>
		</form>
	</div>
	<?php
}

/**
 * Export plugin configuration as JSON.
 *
 * @return void
 */
function mwpfm_handle_export_settings() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'mainwp-field-manager' ), 403 );
	}

	check_admin_referer( 'mwpfm_export_settings', 'mwpfm_export_nonce' );

	$export = array(
		'plugin'          => 'mainwp-field-manager',
		'version'         => MWPFM_VERSION,
		'exported_at_gmt' => gmdate( 'c' ),
		'fields'          => mwpfm_get_custom_fields_config(),
		'settings'        => mwpfm_get_plugin_settings(),
	);

	$filename = 'mainwp-field-manager-export-' . gmdate( 'Y-m-d-His' ) . '.json';

	nocache_headers();
	header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
	header( 'Content-Disposition: attachment; filename=' . $filename );

	echo wp_json_encode( $export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	exit;
}

/**
 * Import plugin configuration from JSON.
 *
 * @return void
 */
function mwpfm_handle_import_settings() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'mainwp-field-manager' ), 403 );
	}

	check_admin_referer( 'mwpfm_import_settings', 'mwpfm_import_nonce' );

	$redirect_url = admin_url( 'admin.php?page=' . MWPFM_SETTINGS_MENU_SLUG );

	if ( empty( $_FILES['mwpfm_import_file']['tmp_name'] ) ) {
		add_settings_error(
			MWPFM_OPTION_FIELDS,
			'mwpfm_import_missing_file',
			__( 'No import file was uploaded.', 'mainwp-field-manager' ),
			'error'
		);

		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect( $redirect_url );
		exit;
	}

	$file_contents = file_get_contents( $_FILES['mwpfm_import_file']['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

	if ( false === $file_contents || '' === trim( $file_contents ) ) {
		add_settings_error(
			MWPFM_OPTION_FIELDS,
			'mwpfm_import_empty_file',
			__( 'The uploaded file is empty or could not be read.', 'mainwp-field-manager' ),
			'error'
		);

		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect( $redirect_url );
		exit;
	}

	$data = json_decode( $file_contents, true );

	if ( ! is_array( $data ) ) {
		add_settings_error(
			MWPFM_OPTION_FIELDS,
			'mwpfm_import_invalid_json',
			__( 'The uploaded file is not valid JSON.', 'mainwp-field-manager' ),
			'error'
		);

		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect( $redirect_url );
		exit;
	}

	$import_fields   = isset( $data['fields'] ) && is_array( $data['fields'] ) ? $data['fields'] : array();
	$import_settings = isset( $data['settings'] ) && is_array( $data['settings'] ) ? $data['settings'] : array();
	$overwrite       = ! empty( $_POST['mwpfm_import_overwrite'] );

	if ( ! $overwrite ) {
		$current_fields   = mwpfm_get_custom_fields_config();
		$current_settings = mwpfm_get_plugin_settings();

		$existing_keys = array();
		foreach ( $current_fields as $field ) {
			if ( ! empty( $field['key'] ) ) {
				$existing_keys[ $field['key'] ] = true;
			}
		}

		foreach ( $import_fields as $field ) {
			if ( empty( $field['key'] ) || isset( $existing_keys[ $field['key'] ] ) ) {
				continue;
			}
			$current_fields[] = $field;
		}

		$import_fields   = $current_fields;
		$import_settings = wp_parse_args( $import_settings, $current_settings );
	}

	$sanitized_fields   = mwpfm_sanitize_custom_fields_config( $import_fields );
	$sanitized_settings = mwpfm_sanitize_plugin_settings( $import_settings );

	update_option( MWPFM_OPTION_FIELDS, $sanitized_fields );
	update_option( MWPFM_OPTION_PLUGIN_SETTINGS, $sanitized_settings );

	add_settings_error(
		MWPFM_OPTION_FIELDS,
		'mwpfm_import_success',
		__( 'Settings imported successfully.', 'mainwp-field-manager' ),
		'updated'
	);

	set_transient( 'settings_errors', get_settings_errors(), 30 );
	wp_safe_redirect( $redirect_url );
	exit;
}
