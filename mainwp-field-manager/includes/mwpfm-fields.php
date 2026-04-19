<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_menu', 'mwpfm_register_admin_pages' );
add_action( 'admin_init', 'mwpfm_register_custom_fields_settings' );

add_action( 'mainwp_manage_sites_edit', 'mwpfm_manage_sites_edit_dynamic', 10, 1 );
add_action( 'mainwp_site_added', 'mwpfm_save_dynamic_custom_fields', 10, 1 );
add_action( 'mainwp_update_site', 'mwpfm_save_dynamic_custom_fields', 10, 1 );

add_filter( 'mainwp_sitestable_getcolumns', 'mwpfm_sitestable_getcolumns_dynamic', 10, 1 );
add_filter( 'mainwp_sitestable_item', 'mwpfm_sitestable_item_dynamic', 10, 1 );
add_action( 'mainwp_site_info_table_bottom', 'mwpfm_site_info_table_bottom_dynamic', 10, 1 );

add_filter( 'mainwp_getmetaboxes', 'mwpfm_register_dynamic_overview_widgets', 10, 1 );

/**
 * Register settings + bulk pages.
 *
 * @return void
 */
function mwpfm_register_admin_pages() {
	add_menu_page(
		__( 'MainWP Field Manager', 'mainwp-field-manager' ),
		__( 'MainWP Field Manager', 'mainwp-field-manager' ),
		'manage_options',
		MWPFM_MENU_SLUG,
		'mwpfm_render_custom_fields_settings_page',
		'dashicons-database-view',
		81
	);

	add_submenu_page(
		MWPFM_MENU_SLUG,
		__( 'Field Definitions', 'mainwp-field-manager' ),
		__( 'Field Definitions', 'mainwp-field-manager' ),
		'manage_options',
		MWPFM_MENU_SLUG,
		'mwpfm_render_custom_fields_settings_page'
	);

	add_submenu_page(
		MWPFM_MENU_SLUG,
		__( 'Bulk Update', 'mainwp-field-manager' ),
		__( 'Bulk Update', 'mainwp-field-manager' ),
		'manage_options',
		MWPFM_BULK_MENU_SLUG,
		'mwpfm_render_bulk_update_page'
	);

	add_submenu_page(
		MWPFM_MENU_SLUG,
		__( 'Settings', 'mainwp-field-manager' ),
		__( 'Settings', 'mainwp-field-manager' ),
		'manage_options',
		MWPFM_SETTINGS_MENU_SLUG,
		'mwpfm_render_settings_page'
	);
}

/**
 * Register option group.
 *
 * @return void
 */
function mwpfm_register_custom_fields_settings() {
	register_setting(
		MWPFM_SETTINGS_GROUP,
		MWPFM_OPTION_FIELDS,
		array(
			'type'              => 'array',
			'sanitize_callback' => 'mwpfm_sanitize_custom_fields_config',
			'default'           => array(),
		)
	);

	register_setting(
		MWPFM_SETTINGS_GROUP,
		MWPFM_OPTION_PLUGIN_SETTINGS,
		array(
			'type'              => 'array',
			'sanitize_callback' => 'mwpfm_sanitize_plugin_settings',
			'default'           => array(
				'prerelease_db_flag' => 'stable',
			),
		)
	);
}

/**
 * Sanitize field configuration.
 *
 * @param mixed $input Raw input.
 * @return array
 */
function mwpfm_sanitize_custom_fields_config( $input ) {
	$existing_fields = mwpfm_get_custom_fields_config();

	if ( ! is_array( $input ) ) {
		return $existing_fields;
	}

	$existing_by_key = array();
	foreach ( $existing_fields as $existing_field ) {
		if ( ! empty( $existing_field['key'] ) ) {
			$existing_by_key[ $existing_field['key'] ] = $existing_field;
		}
	}

	$sanitized = array();
	$used_keys = array();

	foreach ( $input as $row ) {
		$label        = isset( $row['label'] ) ? sanitize_text_field( wp_unslash( $row['label'] ) ) : '';
		$key          = isset( $row['key'] ) ? sanitize_key( wp_unslash( $row['key'] ) ) : '';
		$original_key = isset( $row['original_key'] ) ? sanitize_key( wp_unslash( $row['original_key'] ) ) : '';
		$locked_key   = ! empty( $row['locked_key'] ) ? 1 : 0;

		$type        = isset( $row['type'] ) ? sanitize_key( wp_unslash( $row['type'] ) ) : 'text';
		$show_table  = ! empty( $row['show_table'] ) ? 1 : 0;
		$show_counts = ! empty( $row['show_counts'] ) ? 1 : 0;

		$options_raw = isset( $row['options_raw'] ) ? wp_unslash( $row['options_raw'] ) : '';
		$options     = mwpfm_parse_select_options( $options_raw );

		$renames_raw = isset( $row['renames_raw'] ) ? wp_unslash( $row['renames_raw'] ) : '';
		$renames     = mwpfm_parse_option_renames( $renames_raw );

		if ( '' === $label && '' === $key ) {
			continue;
		}

		if ( $locked_key && '' !== $original_key ) {
			$key = $original_key;
		}

		if ( '' === $key && '' !== $label ) {
			$key = sanitize_key( $label );
		}

		if ( '' === $label || '' === $key ) {
			continue;
		}

		if ( ! in_array( $type, array( 'text', 'textarea', 'select' ), true ) ) {
			$type = 'text';
		}

		$base_key = $key;
		$i        = 2;
		while ( in_array( $key, $used_keys, true ) ) {
			$key = $base_key . '_' . $i;
			++$i;
		}
		$used_keys[] = $key;

		$existing_field = isset( $existing_by_key[ $key ] ) ? $existing_by_key[ $key ] : null;

		if ( $existing_field && isset( $existing_field['type'] ) && 'select' === $existing_field['type'] ) {
			$existing_options = ! empty( $existing_field['options'] ) && is_array( $existing_field['options'] )
				? $existing_field['options']
				: array();

			$saved_values = mwpfm_get_saved_values_for_field( $key );

			if ( ! empty( $saved_values ) ) {
				if ( 'select' !== $type ) {
					$type = 'select';

					mwpfm_add_settings_error(
						sprintf(
							__( 'Field "%1$s" (%2$s) cannot be changed from Dropdown because some sites still use saved dropdown values.', 'mainwp-field-manager' ),
							$label,
							$key
						)
					);
				}

				if ( ! empty( $renames ) ) {
					foreach ( $renames as $old_value => $new_value ) {
						if ( ! in_array( $old_value, $existing_options, true ) ) {
							mwpfm_add_settings_error(
								sprintf(
									__( 'Rename skipped for "%1$s": "%2$s" is not an existing option.', 'mainwp-field-manager' ),
									$label,
									$old_value
								)
							);
							continue;
						}

						if ( ! in_array( $new_value, $options, true ) ) {
							mwpfm_add_settings_error(
								sprintf(
									__( 'Rename skipped for "%1$s": new value "%2$s" must exist in the dropdown options list before migration.', 'mainwp-field-manager' ),
									$label,
									$new_value
								)
							);
							continue;
						}

						$updated_count = mwpfm_migrate_select_option_value( $key, $old_value, $new_value );

						if ( $updated_count > 0 ) {
							mwpfm_add_settings_error(
								sprintf(
									__( 'Updated %1$d site(s) for "%2$s": "%3$s" → "%4$s".', 'mainwp-field-manager' ),
									$updated_count,
									$label,
									$old_value,
									$new_value
								),
								'updated'
							);
						}
					}
				}

				$saved_values   = mwpfm_get_saved_values_for_field( $key );
				$removed_in_use = array_diff( $saved_values, $options );

				if ( ! empty( $removed_in_use ) ) {
					$options = $existing_options;

					mwpfm_add_settings_error(
						sprintf(
							__( 'Some dropdown options for "%1$s" could not be removed because they are still in use: %2$s', 'mainwp-field-manager' ),
							$label,
							implode( ', ', $removed_in_use )
						)
					);
				}
			}
		}

		if ( 'select' !== $type ) {
			$options = array();
		}

		$sanitized[] = array(
			'key'         => $key,
			'label'       => $label,
			'type'        => $type,
			'options'     => $options,
			'show_table'  => $show_table,
			'show_counts' => $show_counts,
			'locked_key'  => 1,
		);
	}

	foreach ( $existing_fields as $existing_field ) {
		if ( empty( $existing_field['key'] ) || empty( $existing_field['label'] ) ) {
			continue;
		}

		$exists_in_new_config = false;

		foreach ( $sanitized as $new_field ) {
			if ( $new_field['key'] === $existing_field['key'] ) {
				$exists_in_new_config = true;
				break;
			}
		}

		if ( $exists_in_new_config ) {
			continue;
		}

		$saved_values = mwpfm_get_saved_values_for_field( $existing_field['key'] );

		if ( ! empty( $saved_values ) ) {
			$sanitized[] = $existing_field;

			mwpfm_add_settings_error(
				sprintf(
					__( 'Field "%1$s" (%2$s) could not be removed because some sites still have values saved for it.', 'mainwp-field-manager' ),
					$existing_field['label'],
					$existing_field['key']
				)
			);
		}
	}

	return $sanitized;
}

/**
 * Render field definitions page.
 *
 * @return void
 */
function mwpfm_render_custom_fields_settings_page() {
	$fields = mwpfm_get_custom_fields_config();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'MainWP Field Manager', 'mainwp-field-manager' ); ?></h1>
		<p><?php esc_html_e( 'Create custom fields for MainWP sites.', 'mainwp-field-manager' ); ?></p>
		<p><?php esc_html_e( 'Keys are permanent internal references. Labels can be changed later without losing saved data.', 'mainwp-field-manager' ); ?></p>
		<p><?php esc_html_e( 'For dropdown fields, add one option per line. To rename an option safely, add the new option and use the rename rules box.', 'mainwp-field-manager' ); ?></p>

		<form method="post" action="options.php">
			<?php
			settings_fields( MWPFM_SETTINGS_GROUP );
			settings_errors( MWPFM_OPTION_FIELDS );
			?>

			<style>
				#mwpfm-custom-fields-table .mwpfm-label-field,
				#mwpfm-custom-fields-table .mwpfm-key-field { width:100%; max-width:260px; box-sizing:border-box; }
				#mwpfm-custom-fields-table .mwpfm-type-field { width:100%; max-width:140px; }
				#mwpfm-custom-fields-table .mwpfm-options-field,
				#mwpfm-custom-fields-table .mwpfm-renames-field { width:100%; min-width:180px; box-sizing:border-box; }
				#mwpfm-custom-fields-table td { vertical-align:top; }
			</style>

			<table class="widefat" id="mwpfm-custom-fields-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Label', 'mainwp-field-manager' ); ?></th>
						<th><?php esc_html_e( 'Key', 'mainwp-field-manager' ); ?></th>
						<th><?php esc_html_e( 'Type', 'mainwp-field-manager' ); ?></th>
						<th><?php esc_html_e( 'Dropdown Options', 'mainwp-field-manager' ); ?></th>
						<th><?php esc_html_e( 'Option Renames', 'mainwp-field-manager' ); ?></th>
						<th><?php esc_html_e( 'Sites Table', 'mainwp-field-manager' ); ?></th>
						<th><?php esc_html_e( 'Overview Widget', 'mainwp-field-manager' ); ?></th>
						<th><?php esc_html_e( 'Action', 'mainwp-field-manager' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! empty( $fields ) ) : ?>
						<?php foreach ( $fields as $index => $field ) : ?>
							<tr class="mwpfm-field-row">
								<td><input type="text" class="mwpfm-label-field" name="<?php echo esc_attr( MWPFM_OPTION_FIELDS . '[' . $index . '][label]' ); ?>" value="<?php echo esc_attr( $field['label'] ); ?>" /></td>
								<td>
									<input type="text" class="mwpfm-key-field" name="<?php echo esc_attr( MWPFM_OPTION_FIELDS . '[' . $index . '][key]' ); ?>" value="<?php echo esc_attr( $field['key'] ); ?>" readonly="readonly" />
									<input type="hidden" class="mwpfm-original-key-field" name="<?php echo esc_attr( MWPFM_OPTION_FIELDS . '[' . $index . '][original_key]' ); ?>" value="<?php echo esc_attr( $field['key'] ); ?>" />
									<input type="hidden" class="mwpfm-locked-key-field" name="<?php echo esc_attr( MWPFM_OPTION_FIELDS . '[' . $index . '][locked_key]' ); ?>" value="1" />
								</td>
								<td>
									<select class="mwpfm-type-field" name="<?php echo esc_attr( MWPFM_OPTION_FIELDS . '[' . $index . '][type]' ); ?>">
										<option value="text" <?php selected( $field['type'], 'text' ); ?>><?php esc_html_e( 'Text', 'mainwp-field-manager' ); ?></option>
										<option value="textarea" <?php selected( $field['type'], 'textarea' ); ?>><?php esc_html_e( 'Textarea', 'mainwp-field-manager' ); ?></option>
										<option value="select" <?php selected( $field['type'], 'select' ); ?>><?php esc_html_e( 'Dropdown', 'mainwp-field-manager' ); ?></option>
									</select>
								</td>
								<td><textarea class="large-text code mwpfm-options-field" name="<?php echo esc_attr( MWPFM_OPTION_FIELDS . '[' . $index . '][options_raw]' ); ?>" rows="5"><?php echo esc_textarea( ! empty( $field['options'] ) ? implode( "\n", $field['options'] ) : '' ); ?></textarea></td>
								<td><textarea class="large-text code mwpfm-renames-field" name="<?php echo esc_attr( MWPFM_OPTION_FIELDS . '[' . $index . '][renames_raw]' ); ?>" rows="5" placeholder="<?php esc_attr_e( "Old Value => New Value\nKrystal => Krystal Hosting", 'mainwp-field-manager' ); ?>"></textarea></td>
								<td><label><input type="checkbox" name="<?php echo esc_attr( MWPFM_OPTION_FIELDS . '[' . $index . '][show_table]' ); ?>" value="1" <?php checked( ! empty( $field['show_table'] ) ); ?> /> <?php esc_html_e( 'Show', 'mainwp-field-manager' ); ?></label></td>
								<td><label><input type="checkbox" name="<?php echo esc_attr( MWPFM_OPTION_FIELDS . '[' . $index . '][show_counts]' ); ?>" value="1" <?php checked( ! empty( $field['show_counts'] ) ); ?> /> <?php esc_html_e( 'Enable', 'mainwp-field-manager' ); ?></label></td>
								<td><button type="button" class="button mwpfm-remove-row"><?php esc_html_e( 'Remove', 'mainwp-field-manager' ); ?></button></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>

					<tr class="mwpfm-field-row">
						<td><input type="text" class="mwpfm-label-field" name="<?php echo esc_attr( MWPFM_OPTION_FIELDS . '[new][label]' ); ?>" value="" placeholder="<?php esc_attr_e( 'e.g. Hosting Provider', 'mainwp-field-manager' ); ?>" /></td>
						<td>
							<input type="text" class="mwpfm-key-field" name="<?php echo esc_attr( MWPFM_OPTION_FIELDS . '[new][key]' ); ?>" value="" placeholder="<?php esc_attr_e( 'e.g. webhost', 'mainwp-field-manager' ); ?>" />
							<input type="hidden" class="mwpfm-original-key-field" name="<?php echo esc_attr( MWPFM_OPTION_FIELDS . '[new][original_key]' ); ?>" value="" />
							<input type="hidden" class="mwpfm-locked-key-field" name="<?php echo esc_attr( MWPFM_OPTION_FIELDS . '[new][locked_key]' ); ?>" value="0" />
						</td>
						<td>
							<select class="mwpfm-type-field" name="<?php echo esc_attr( MWPFM_OPTION_FIELDS . '[new][type]' ); ?>">
								<option value="text"><?php esc_html_e( 'Text', 'mainwp-field-manager' ); ?></option>
								<option value="textarea"><?php esc_html_e( 'Textarea', 'mainwp-field-manager' ); ?></option>
								<option value="select"><?php esc_html_e( 'Dropdown', 'mainwp-field-manager' ); ?></option>
							</select>
						</td>
						<td><textarea class="large-text code mwpfm-options-field" name="<?php echo esc_attr( MWPFM_OPTION_FIELDS . '[new][options_raw]' ); ?>" rows="5" placeholder="<?php esc_attr_e( "One option per line\nKrystal\nCloudways\nSiteGround", 'mainwp-field-manager' ); ?>"></textarea></td>
						<td><textarea class="large-text code mwpfm-renames-field" name="<?php echo esc_attr( MWPFM_OPTION_FIELDS . '[new][renames_raw]' ); ?>" rows="5" placeholder="<?php esc_attr_e( "Old Value => New Value\nKrystal => Krystal Hosting", 'mainwp-field-manager' ); ?>"></textarea></td>
						<td><label><input type="checkbox" name="<?php echo esc_attr( MWPFM_OPTION_FIELDS . '[new][show_table]' ); ?>" value="1" /> <?php esc_html_e( 'Show', 'mainwp-field-manager' ); ?></label></td>
						<td><label><input type="checkbox" name="<?php echo esc_attr( MWPFM_OPTION_FIELDS . '[new][show_counts]' ); ?>" value="1" /> <?php esc_html_e( 'Enable', 'mainwp-field-manager' ); ?></label></td>
						<td><button type="button" class="button mwpfm-remove-row"><?php esc_html_e( 'Remove', 'mainwp-field-manager' ); ?></button></td>
					</tr>
				</tbody>
			</table>

			<p><button type="button" class="button" id="mwpfm-add-row"><?php esc_html_e( 'Add Field', 'mainwp-field-manager' ); ?></button></p>

			<?php submit_button( __( 'Save Field Definitions', 'mainwp-field-manager' ) ); ?>
		</form>
	</div>

	<script>
	document.addEventListener('DOMContentLoaded', function () {
		const tableBody = document.querySelector('#mwpfm-custom-fields-table tbody');
		const addRowBtn = document.getElementById('mwpfm-add-row');

		function slugify(value) {
			return value.toLowerCase().trim().replace(/[^a-z0-9_ -]/g, '').replace(/\s+/g, '_').replace(/-+/g, '_').replace(/_+/g, '_').replace(/^_+|_+$/g, '');
		}

		function toggleOptionsVisibility() {
			const rows = tableBody.querySelectorAll('.mwpfm-field-row');
			rows.forEach(function (row) {
				const typeField = row.querySelector('.mwpfm-type-field');
				const optionsField = row.querySelector('.mwpfm-options-field');
				const renamesField = row.querySelector('.mwpfm-renames-field');
				if (!typeField || !optionsField || !renamesField) {
					return;
				}
				if (typeField.value === 'select') {
					optionsField.removeAttribute('disabled');
					optionsField.style.opacity = '1';
					renamesField.removeAttribute('disabled');
					renamesField.style.opacity = '1';
				} else {
					optionsField.setAttribute('disabled', 'disabled');
					optionsField.style.opacity = '0.45';
					renamesField.setAttribute('disabled', 'disabled');
					renamesField.style.opacity = '0.45';
				}
			});
		}

		addRowBtn.addEventListener('click', function () {
			const index = 'new_' + Date.now();
			const row = document.createElement('tr');
			row.className = 'mwpfm-field-row';
			row.innerHTML = `
				<td><input type="text" class="mwpfm-label-field" name="<?php echo esc_js( MWPFM_OPTION_FIELDS ); ?>[${index}][label]" value="" /></td>
				<td>
					<input type="text" class="mwpfm-key-field" name="<?php echo esc_js( MWPFM_OPTION_FIELDS ); ?>[${index}][key]" value="" />
					<input type="hidden" class="mwpfm-original-key-field" name="<?php echo esc_js( MWPFM_OPTION_FIELDS ); ?>[${index}][original_key]" value="" />
					<input type="hidden" class="mwpfm-locked-key-field" name="<?php echo esc_js( MWPFM_OPTION_FIELDS ); ?>[${index}][locked_key]" value="0" />
				</td>
				<td>
					<select class="mwpfm-type-field" name="<?php echo esc_js( MWPFM_OPTION_FIELDS ); ?>[${index}][type]">
						<option value="text"><?php echo esc_js( __( 'Text', 'mainwp-field-manager' ) ); ?></option>
						<option value="textarea"><?php echo esc_js( __( 'Textarea', 'mainwp-field-manager' ) ); ?></option>
						<option value="select"><?php echo esc_js( __( 'Dropdown', 'mainwp-field-manager' ) ); ?></option>
					</select>
				</td>
				<td><textarea class="large-text code mwpfm-options-field" name="<?php echo esc_js( MWPFM_OPTION_FIELDS ); ?>[${index}][options_raw]" rows="5"></textarea></td>
				<td><textarea class="large-text code mwpfm-renames-field" name="<?php echo esc_js( MWPFM_OPTION_FIELDS ); ?>[${index}][renames_raw]" rows="5"></textarea></td>
				<td><label><input type="checkbox" name="<?php echo esc_js( MWPFM_OPTION_FIELDS ); ?>[${index}][show_table]" value="1" /> <?php echo esc_js( __( 'Show', 'mainwp-field-manager' ) ); ?></label></td>
				<td><label><input type="checkbox" name="<?php echo esc_js( MWPFM_OPTION_FIELDS ); ?>[${index}][show_counts]" value="1" /> <?php echo esc_js( __( 'Enable', 'mainwp-field-manager' ) ); ?></label></td>
				<td><button type="button" class="button mwpfm-remove-row"><?php echo esc_js( __( 'Remove', 'mainwp-field-manager' ) ); ?></button></td>
			`;
			tableBody.appendChild(row);
			toggleOptionsVisibility();
		});

		tableBody.addEventListener('click', function (event) {
			if (!event.target.classList.contains('mwpfm-remove-row')) {
				return;
			}
			const row = event.target.closest('.mwpfm-field-row');
			if (row) {
				row.remove();
			}
		});

		tableBody.addEventListener('input', function (event) {
			if (!event.target.classList.contains('mwpfm-label-field')) {
				return;
			}
			const row = event.target.closest('.mwpfm-field-row');
			const keyField = row.querySelector('.mwpfm-key-field');
			const lockedKeyField = row.querySelector('.mwpfm-locked-key-field');
			if (keyField && lockedKeyField && lockedKeyField.value === '0' && keyField.value.trim() === '') {
				keyField.value = slugify(event.target.value);
			}
		});

		tableBody.addEventListener('change', function (event) {
			if (event.target.classList.contains('mwpfm-type-field')) {
				toggleOptionsVisibility();
			}
		});

		toggleOptionsVisibility();
	});
	</script>
	<?php
}

/**
 * Render custom fields on MainWP edit site page.
 *
 * @param object $website Website object.
 * @return void
 */
function mwpfm_manage_sites_edit_dynamic( $website ) {
	$fields = mwpfm_get_custom_fields_config();

	if ( empty( $fields ) ) {
		return;
	}
	?>
	<h3 class="ui dividing header"><?php esc_html_e( 'Additional information (Optional)', 'mainwp-field-manager' ); ?></h3>

	<?php foreach ( $fields as $field ) : ?>
		<?php
		$key     = isset( $field['key'] ) ? $field['key'] : '';
		$label   = isset( $field['label'] ) ? $field['label'] : '';
		$type    = isset( $field['type'] ) ? $field['type'] : 'text';
		$options = ! empty( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();

		if ( '' === $key || '' === $label ) {
			continue;
		}

		$value = apply_filters( 'mainwp_getwebsiteoptions', false, $website, $key );
		$value = is_string( $value ) ? $value : '';
		?>
		<div class="ui grid field mainwp_addition_fields_addsite">
			<label class="six wide column middle aligned"><?php echo esc_html( $label ); ?></label>
			<div class="ui six wide column" data-position="top left">
				<?php if ( 'select' === $type ) : ?>
					<select id="<?php echo esc_attr( 'mwpfm_field_' . $key ); ?>" name="<?php echo esc_attr( 'mwpfm_fields[' . $key . ']' ); ?>" class="ui dropdown" style="width:100%;">
						<option value=""><?php esc_html_e( 'Select an option', 'mainwp-field-manager' ); ?></option>
						<?php foreach ( $options as $option ) : ?>
							<option value="<?php echo esc_attr( $option ); ?>" <?php selected( $value, $option ); ?>><?php echo esc_html( $option ); ?></option>
						<?php endforeach; ?>
					</select>
				<?php elseif ( 'textarea' === $type ) : ?>
					<textarea id="<?php echo esc_attr( 'mwpfm_field_' . $key ); ?>" name="<?php echo esc_attr( 'mwpfm_fields[' . $key . ']' ); ?>" rows="5" style="width:100%;"><?php echo esc_textarea( $value ); ?></textarea>
				<?php else : ?>
					<div class="ui left labeled input" style="width:100%;">
						<input type="text" id="<?php echo esc_attr( 'mwpfm_field_' . $key ); ?>" name="<?php echo esc_attr( 'mwpfm_fields[' . $key . ']' ); ?>" value="<?php echo esc_attr( $value ); ?>" />
					</div>
				<?php endif; ?>
			</div>
		</div>
	<?php endforeach; ?>
	<?php
}

/**
 * Save custom fields from MainWP site edit/add screens.
 *
 * @param int $site_id Site ID.
 * @return void
 */
function mwpfm_save_dynamic_custom_fields( $site_id ) {
	$fields = mwpfm_get_custom_fields_config();

	if ( empty( $fields ) ) {
		return;
	}

	$posted_fields = isset( $_POST['mwpfm_fields'] ) && is_array( $_POST['mwpfm_fields'] )
		? wp_unslash( $_POST['mwpfm_fields'] )
		: array();

	foreach ( $fields as $field ) {
		$key = isset( $field['key'] ) ? $field['key'] : '';

		if ( '' === $key ) {
			continue;
		}

		$raw_value = isset( $posted_fields[ $key ] ) ? $posted_fields[ $key ] : '';
		$value     = mwpfm_sanitize_field_value( $raw_value, $field );

		mwpfm_update_site_field_value( $site_id, $key, $value );
	}
}

/**
 * Add enabled fields as Manage Sites table columns.
 *
 * @param array $columns Existing columns.
 * @return array
 */
function mwpfm_sitestable_getcolumns_dynamic( $columns ) {
	$fields = mwpfm_get_custom_fields_config();

	foreach ( $fields as $field ) {
		if ( empty( $field['key'] ) || empty( $field['label'] ) || empty( $field['show_table'] ) ) {
			continue;
		}
		$columns[ $field['key'] ] = esc_html( $field['label'] );
	}

	return $columns;
}

/**
 * Populate custom columns.
 *
 * @param array $item Site row.
 * @return array
 */
function mwpfm_sitestable_item_dynamic( $item ) {
	$fields = mwpfm_get_custom_fields_config();

	foreach ( $fields as $field ) {
		$key = isset( $field['key'] ) ? $field['key'] : '';

		if ( '' === $key || empty( $field['show_table'] ) ) {
			continue;
		}

		$value = mwpfm_get_site_field_value( $item['id'], $key );

		if ( isset( $field['type'] ) && 'textarea' === $field['type'] ) {
			$value = wp_trim_words( $value, 12, '…' );
		}

		$item[ $key ] = $value;
	}

	return $item;
}

/**
 * Append custom fields to site info widget.
 *
 * @param object $website Website object.
 * @return void
 */
function mwpfm_site_info_table_bottom_dynamic( $website ) {
	$fields = mwpfm_get_custom_fields_config();

	foreach ( $fields as $field ) {
		$key   = isset( $field['key'] ) ? $field['key'] : '';
		$label = isset( $field['label'] ) ? $field['label'] : '';

		if ( '' === $key || '' === $label ) {
			continue;
		}

		$value = mwpfm_get_site_field_value( $website->id, $key );
		$value = trim( $value );
		$value = '' !== $value ? $value : '—';

		if ( isset( $field['type'] ) && 'textarea' === $field['type'] && '—' !== $value ) {
			$value = nl2br( esc_html( $value ) );
		} else {
			$value = esc_html( $value );
		}
		?>
		<tr>
			<td><?php echo esc_html( $label ); ?></td>
			<td class="right aligned"><?php echo $value; ?></td>
		</tr>
		<?php
	}
}

/**
 * Register overview widgets for count-enabled fields.
 *
 * @param array $metaboxes Existing metaboxes.
 * @return array
 */
function mwpfm_register_dynamic_overview_widgets( $metaboxes ) {
	if ( ! isset( $_GET['page'] ) || 'mainwp_tab' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return $metaboxes;
	}

	$fields = mwpfm_get_countable_fields_config();

	if ( empty( $fields ) ) {
		return $metaboxes;
	}

	foreach ( $fields as $field ) {
		$metaboxes[] = array(
			'metabox_title' => sprintf( __( '%s Overview', 'mainwp-field-manager' ), $field['label'] ),
			'plugin'        => MWPFM_PLUGIN_FILE,
			'key'           => 'mwpfm_' . $field['key'] . '_overview',
			'callback'      => function () use ( $field ) {
				mwpfm_render_dynamic_overview_widget( $field );
			},
		);
	}

	return $metaboxes;
}

/**
 * Render overview widget.
 *
 * @param array $field Field config.
 * @return void
 */
function mwpfm_render_dynamic_overview_widget( $field ) {
	$key   = isset( $field['key'] ) ? $field['key'] : '';
	$label = isset( $field['label'] ) ? $field['label'] : __( 'Custom Field', 'mainwp-field-manager' );

	if ( '' === $key ) {
		echo '<p>' . esc_html__( 'Invalid field configuration.', 'mainwp-field-manager' ) . '</p>';
		return;
	}

	$websites = mwpfm_get_current_user_websites();

	if ( empty( $websites ) ) {
		echo '<p>' . esc_html__( 'No sites found.', 'mainwp-field-manager' ) . '</p>';
		return;
	}

	$counts  = array();
	$not_set = esc_html__( '(not set)', 'mainwp-field-manager' );

	foreach ( $websites as $website ) {
		$value = trim( mwpfm_get_site_field_value( $website->id, $key ) );
		$value = '' !== $value ? $value : $not_set;

		if ( ! isset( $counts[ $value ] ) ) {
			$counts[ $value ] = 0;
		}
		++$counts[ $value ];
	}

	uksort(
		$counts,
		function ( $a, $b ) use ( $not_set ) {
			if ( $a === $not_set ) {
				return 1;
			}
			if ( $b === $not_set ) {
				return -1;
			}
			return strcasecmp( $a, $b );
		}
	);

	echo '<div class="ui grid mainwp-widget-header">';
	echo '<div class="twelve wide column">';
	echo '<h2 class="ui header handle-drag">';
	echo esc_html( $label );
	echo '<div class="sub header">' . esc_html( sprintf( __( 'The number of websites per %s value', 'mainwp-field-manager' ), strtolower( $label ) ) ) . '</div>';
	echo '</h2>';
	echo '</div>';
	echo '</div>';

	echo '<div class="mainwp-scrolly-overflow">';
	echo '<div class="ui middle aligned divided list">';

	foreach ( $counts as $value => $count ) {
		echo '<div class="item"><div class="ui grid">';
		echo '<div class="fourteen wide column middle aligned">' . esc_html( $value ) . '</div>';
		echo '<div class="two wide column right aligned">' . esc_html( $count ) . '</div>';
		echo '</div></div>';
	}

	echo '</div></div>';
}
