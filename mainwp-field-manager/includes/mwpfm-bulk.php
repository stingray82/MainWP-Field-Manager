<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_post_mwpfm_bulk_update', 'mwpfm_handle_bulk_update_submission' );

/**
 * Get candidate site ids from request or all websites.
 *
 * @return int[]
 */
function mwpfm_get_bulk_candidate_site_ids() {
	$site_ids = array();

	if ( isset( $_REQUEST['mwpfm_site_ids'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$raw = wp_unslash( $_REQUEST['mwpfm_site_ids'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( is_array( $raw ) ) {
			$site_ids = array_map( 'intval', $raw );
		} else {
			$site_ids = array_map( 'intval', preg_split( '/[\s,]+/', (string) $raw ) );
		}
	}

	$site_ids = array_values( array_filter( array_unique( $site_ids ) ) );

	if ( ! empty( $site_ids ) ) {
		return $site_ids;
	}

	$websites = mwpfm_get_current_user_websites();

	foreach ( $websites as $website ) {
		$site_ids[] = (int) $website->id;
	}

	return array_values( array_unique( $site_ids ) );
}

/**
 * Get sites matching bulk filters.
 *
 * @param array $args Filter args.
 * @return array
 */
function mwpfm_get_bulk_filtered_websites( $args = array() ) {
	$defaults = array(
		'candidate_site_ids'  => array(),
		'search'              => '',
		'current_field_key'   => '',
		'current_field_value' => '',
		'current_empty_only'  => 0,
	);

	$args     = wp_parse_args( $args, $defaults );
	$websites = mwpfm_get_current_user_websites();

	if ( empty( $websites ) ) {
		return array();
	}

	$filtered = array();

	foreach ( $websites as $website ) {
		if ( ! empty( $args['candidate_site_ids'] ) && ! in_array( (int) $website->id, $args['candidate_site_ids'], true ) ) {
			continue;
		}

		$label = mwpfm_get_website_label( $website );
		$url   = isset( $website->url ) ? (string) $website->url : '';

		if ( '' !== $args['search'] ) {
			$haystack = strtolower( $label . ' ' . $url );
			if ( false === strpos( $haystack, strtolower( $args['search'] ) ) ) {
				continue;
			}
		}

		if ( '' !== $args['current_field_key'] ) {
			$current_value = trim( mwpfm_get_site_field_value( $website->id, $args['current_field_key'] ) );

			if ( ! empty( $args['current_empty_only'] ) ) {
				if ( '' !== $current_value ) {
					continue;
				}
			} elseif ( '' !== $args['current_field_value'] && $current_value !== $args['current_field_value'] ) {
				continue;
			}
		}

		$filtered[] = $website;
	}

	return $filtered;
}

/**
 * Get allowed bulk actions for one field.
 *
 * @param array $field Field config.
 * @return array
 */
function mwpfm_get_bulk_actions_for_field( $field ) {
	$type = isset( $field['type'] ) ? $field['type'] : 'text';

	if ( 'textarea' === $type ) {
		return array(
			'set'             => __( 'Replace with new value', 'mainwp-field-manager' ),
			'clear'           => __( 'Clear value', 'mainwp-field-manager' ),
			'append'          => __( 'Append text', 'mainwp-field-manager' ),
			'prepend'         => __( 'Prepend text', 'mainwp-field-manager' ),
			'replace_old_new' => __( 'Replace old text with new text', 'mainwp-field-manager' ),
		);
	}

	if ( 'select' === $type ) {
		return array(
			'set'             => __( 'Set selected option', 'mainwp-field-manager' ),
			'clear'           => __( 'Clear value', 'mainwp-field-manager' ),
			'replace_old_new' => __( 'Replace one option with another', 'mainwp-field-manager' ),
		);
	}

	return array(
		'set'             => __( 'Replace with new value', 'mainwp-field-manager' ),
		'clear'           => __( 'Clear value', 'mainwp-field-manager' ),
		'replace_old_new' => __( 'Replace old text with new text', 'mainwp-field-manager' ),
	);
}

/**
 * Apply one bulk action to one site.
 *
 * @param int    $site_id Site ID.
 * @param array  $field Field config.
 * @param string $action Action.
 * @param string $new_value New value.
 * @param string $old_value Old value.
 * @param bool   $only_empty Only empty flag.
 * @return bool
 */
function mwpfm_apply_bulk_action_to_site( $site_id, $field, $action, $new_value, $old_value = '', $only_empty = false ) {
	$key     = isset( $field['key'] ) ? $field['key'] : '';
	$type    = isset( $field['type'] ) ? $field['type'] : 'text';
	$current = mwpfm_get_site_field_value( $site_id, $key );

	if ( $only_empty && '' !== trim( $current ) ) {
		return false;
	}

	$updated = $current;

	switch ( $action ) {
		case 'clear':
			$updated = '';
			break;

		case 'append':
			$updated = $current . $new_value;
			break;

		case 'prepend':
			$updated = $new_value . $current;
			break;

		case 'replace_old_new':
			if ( '' === $old_value ) {
				return false;
			}

			if ( 'select' === $type ) {
				if ( trim( $current ) !== $old_value ) {
					return false;
				}
				$updated = $new_value;
			} else {
				if ( false === strpos( $current, $old_value ) ) {
					return false;
				}
				$updated = str_replace( $old_value, $new_value, $current );
			}
			break;

		case 'set':
		default:
			$updated = $new_value;
			break;
	}

	$updated = mwpfm_sanitize_field_value( $updated, $field );

	if ( $updated === $current ) {
		return false;
	}

	mwpfm_update_site_field_value( $site_id, $key, $updated );
	return true;
}

/**
 * Handle bulk form.
 *
 * @return void
 */
function mwpfm_handle_bulk_update_submission() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'mainwp-field-manager' ), 403 );
	}

	check_admin_referer( 'mwpfm_bulk_update', 'mwpfm_bulk_nonce' );

	$field_key     = isset( $_POST['mwpfm_bulk_field_key'] ) ? sanitize_key( wp_unslash( $_POST['mwpfm_bulk_field_key'] ) ) : '';
	$action        = isset( $_POST['mwpfm_bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['mwpfm_bulk_action'] ) ) : 'set';
	$new_value     = isset( $_POST['mwpfm_bulk_new_value'] ) ? wp_unslash( $_POST['mwpfm_bulk_new_value'] ) : '';
	$old_value     = isset( $_POST['mwpfm_bulk_old_value'] ) ? wp_unslash( $_POST['mwpfm_bulk_old_value'] ) : '';
	$only_empty    = ! empty( $_POST['mwpfm_bulk_only_empty'] );
	$search        = isset( $_POST['mwpfm_bulk_search'] ) ? sanitize_text_field( wp_unslash( $_POST['mwpfm_bulk_search'] ) ) : '';
	$current_field = isset( $_POST['mwpfm_bulk_current_field_key'] ) ? sanitize_key( wp_unslash( $_POST['mwpfm_bulk_current_field_key'] ) ) : '';
	$current_value = isset( $_POST['mwpfm_bulk_current_field_value'] ) ? sanitize_text_field( wp_unslash( $_POST['mwpfm_bulk_current_field_value'] ) ) : '';
	$current_empty = ! empty( $_POST['mwpfm_bulk_current_empty_only'] );

	$site_ids = array();

	if ( ! empty( $_POST['mwpfm_site_ids_serialized'] ) ) {
		$site_ids = array_map(
			'intval',
			preg_split( '/[\s,]+/', wp_unslash( $_POST['mwpfm_site_ids_serialized'] ) )
		);
		$site_ids = array_values( array_filter( array_unique( $site_ids ) ) );
	} elseif ( isset( $_POST['mwpfm_site_ids'] ) ) {
		$site_ids = array_map( 'intval', (array) wp_unslash( $_POST['mwpfm_site_ids'] ) );
		$site_ids = array_values( array_filter( array_unique( $site_ids ) ) );
	}

	$redirect_url = admin_url( 'admin.php?page=' . MWPFM_BULK_MENU_SLUG );
	$field        = mwpfm_get_field_config_by_key( $field_key );

	if ( empty( $field ) ) {
		set_transient(
			'mwpfm_bulk_notice_' . get_current_user_id(),
			array(
				'message' => __( 'Invalid field selected.', 'mainwp-field-manager' ),
				'type'    => 'error',
			),
			30
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	$targets = mwpfm_get_bulk_filtered_websites(
		array(
			'candidate_site_ids'  => $site_ids,
			'search'              => $search,
			'current_field_key'   => $current_field,
			'current_field_value' => $current_value,
			'current_empty_only'  => $current_empty ? 1 : 0,
		)
	);

	$changed = 0;

	foreach ( $targets as $website ) {
		if ( mwpfm_apply_bulk_action_to_site( $website->id, $field, $action, $new_value, $old_value, $only_empty ) ) {
			++$changed;
		}
	}

	set_transient(
		'mwpfm_bulk_notice_' . get_current_user_id(),
		array(
			'message' => sprintf( __( 'Bulk update complete. %d site(s) changed.', 'mainwp-field-manager' ), $changed ),
			'type'    => 'updated',
		),
		30
	);

	wp_safe_redirect( $redirect_url );
	exit;
}

/**
 * Render bulk update page.
 *
 * @return void
 */
function mwpfm_render_bulk_update_page() {
	$fields   = mwpfm_get_custom_fields_config();
	$websites = mwpfm_get_current_user_websites();

	$notice = get_transient( 'mwpfm_bulk_notice_' . get_current_user_id() );
	if ( $notice ) {
		delete_transient( 'mwpfm_bulk_notice_' . get_current_user_id() );
		echo '<div class="notice notice-' . esc_attr( 'error' === $notice['type'] ? 'error' : 'success' ) . '"><p>' . esc_html( $notice['message'] ) . '</p></div>';
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'MainWP Field Manager — Bulk Update', 'mainwp-field-manager' ); ?></h1>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="mwpfm_bulk_form">
			<input type="hidden" name="action" value="mwpfm_bulk_update" />
			<input type="hidden" name="mwpfm_site_ids_serialized" id="mwpfm_site_ids_serialized" value="" />
			<?php wp_nonce_field( 'mwpfm_bulk_update', 'mwpfm_bulk_nonce' ); ?>

			<h2><?php esc_html_e( '1. Select Sites', 'mainwp-field-manager' ); ?></h2>

			<p><input type="text" id="mwpfm_site_search" name="mwpfm_bulk_search" placeholder="<?php esc_attr_e( 'Search sites...', 'mainwp-field-manager' ); ?>" style="width:100%; margin-bottom:10px;" /></p>

			<select id="mwpfm_site_selector" multiple size="12" style="width:100%;">
				<?php foreach ( $websites as $site ) : ?>
					<option value="<?php echo (int) $site->id; ?>"
						<?php foreach ( $fields as $f ) : ?>
							<?php
							$val = mwpfm_get_site_field_value( $site->id, $f['key'] );
							echo ' data-' . esc_attr( preg_replace( '/[^a-z0-9]/', '', strtolower( $f['key'] ) ) ) . '="' . esc_attr( strtolower( $val ) ) . '"';
							?>
						<?php endforeach; ?>
					>
						<?php echo esc_html( mwpfm_get_website_label( $site ) . ' (#' . $site->id . ')' ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<p>
				<button type="button" class="button" id="mwpfm-select-all"><?php esc_html_e( 'Select All', 'mainwp-field-manager' ); ?></button>
				<button type="button" class="button" id="mwpfm-clear-all"><?php esc_html_e( 'Clear', 'mainwp-field-manager' ); ?></button>
			</p>

			<hr>

			<h2><?php esc_html_e( '2. Optional Filters', 'mainwp-field-manager' ); ?></h2>

			<p>
				<select id="mwpfm_filter_field" name="mwpfm_bulk_current_field_key">
					<option value=""><?php esc_html_e( 'No filter', 'mainwp-field-manager' ); ?></option>
					<?php foreach ( $fields as $field ) : ?>
						<option value="<?php echo esc_attr( $field['key'] ); ?>" data-type="<?php echo esc_attr( $field['type'] ); ?>" data-options="<?php echo esc_attr( wp_json_encode( ! empty( $field['options'] ) ? $field['options'] : array() ) ); ?>">
							<?php echo esc_html( $field['label'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</p>

			<div id="mwpfm_filter_value_text_wrap">
				<input type="text" id="mwpfm_filter_value_text" name="mwpfm_bulk_current_field_value" placeholder="<?php esc_attr_e( 'Exact value', 'mainwp-field-manager' ); ?>" />
			</div>

			<div id="mwpfm_filter_value_select_wrap" style="display:none;">
				<select id="mwpfm_filter_value_select">
					<option value=""><?php esc_html_e( 'Select option', 'mainwp-field-manager' ); ?></option>
				</select>
			</div>

			<p>
				<label><input type="checkbox" id="mwpfm_filter_empty" name="mwpfm_bulk_current_empty_only" value="1" /> <?php esc_html_e( 'Only empty', 'mainwp-field-manager' ); ?></label>
			</p>

			<p>
				<button type="button" class="button button-primary" id="mwpfm-select-filtered"><?php esc_html_e( 'Select Filtered Sites', 'mainwp-field-manager' ); ?></button>
			</p>

			<hr>

			<h2><?php esc_html_e( '3. Update Field', 'mainwp-field-manager' ); ?></h2>

			<p>
				<select name="mwpfm_bulk_field_key" id="mwpfm_bulk_field_key">
					<option value=""><?php esc_html_e( 'Select field', 'mainwp-field-manager' ); ?></option>
					<?php foreach ( $fields as $field ) : ?>
						<option value="<?php echo esc_attr( $field['key'] ); ?>" data-type="<?php echo esc_attr( $field['type'] ); ?>" data-options="<?php echo esc_attr( wp_json_encode( ! empty( $field['options'] ) ? $field['options'] : array() ) ); ?>">
							<?php echo esc_html( $field['label'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</p>

			<p>
				<select name="mwpfm_bulk_action" id="mwpfm_action">
					<option value="set"><?php esc_html_e( 'Set', 'mainwp-field-manager' ); ?></option>
					<option value="clear"><?php esc_html_e( 'Clear', 'mainwp-field-manager' ); ?></option>
					<option value="replace_old_new"><?php esc_html_e( 'Replace', 'mainwp-field-manager' ); ?></option>
					<option value="append"><?php esc_html_e( 'Append', 'mainwp-field-manager' ); ?></option>
					<option value="prepend"><?php esc_html_e( 'Prepend', 'mainwp-field-manager' ); ?></option>
				</select>
			</p>

			<div id="mwpfm_new_value_text_wrap">
				<p><label for="mwpfm_bulk_new_value_text"><?php esc_html_e( 'Value to apply', 'mainwp-field-manager' ); ?></label><br /><input type="text" id="mwpfm_bulk_new_value_text" class="regular-text" /></p>
			</div>

			<div id="mwpfm_new_value_textarea_wrap" style="display:none;">
				<p><label for="mwpfm_bulk_new_value_textarea"><?php esc_html_e( 'Value to apply', 'mainwp-field-manager' ); ?></label><br /><textarea id="mwpfm_bulk_new_value_textarea" class="large-text" rows="6"></textarea></p>
			</div>

			<div id="mwpfm_new_value_select_wrap" style="display:none;">
				<p><label for="mwpfm_bulk_new_value_select"><?php esc_html_e( 'Option to apply', 'mainwp-field-manager' ); ?></label><br />
					<select id="mwpfm_bulk_new_value_select">
						<option value=""><?php esc_html_e( 'Select option', 'mainwp-field-manager' ); ?></option>
					</select>
				</p>
			</div>

			<div id="mwpfm_old_value_text_wrap" style="display:none;">
				<p><label for="mwpfm_bulk_old_value_text"><?php esc_html_e( 'Value to replace', 'mainwp-field-manager' ); ?></label><br /><input type="text" id="mwpfm_bulk_old_value_text" class="regular-text" /></p>
			</div>

			<div id="mwpfm_old_value_select_wrap" style="display:none;">
				<p><label for="mwpfm_bulk_old_value_select"><?php esc_html_e( 'Option to replace', 'mainwp-field-manager' ); ?></label><br />
					<select id="mwpfm_bulk_old_value_select">
						<option value=""><?php esc_html_e( 'Select option', 'mainwp-field-manager' ); ?></option>
					</select>
				</p>
			</div>

			<input type="hidden" name="mwpfm_bulk_new_value" id="mwpfm_bulk_new_value_hidden" value="" />
			<input type="hidden" name="mwpfm_bulk_old_value" id="mwpfm_bulk_old_value_hidden" value="" />

			<p><label><input type="checkbox" name="mwpfm_bulk_only_empty" value="1" /> <?php esc_html_e( 'Only update empty values', 'mainwp-field-manager' ); ?></label></p>

			<?php submit_button( __( 'Apply Bulk Update', 'mainwp-field-manager' ) ); ?>
		</form>
	</div>

	<script>
	document.addEventListener('DOMContentLoaded', function () {
		const bulkForm = document.getElementById('mwpfm_bulk_form');
		const siteSelect = document.getElementById('mwpfm_site_selector');
		const siteSearch = document.getElementById('mwpfm_site_search');
		const siteIdsSerialized = document.getElementById('mwpfm_site_ids_serialized');

		const filterField = document.getElementById('mwpfm_filter_field');
		const filterValueTextWrap = document.getElementById('mwpfm_filter_value_text_wrap');
		const filterValueText = document.getElementById('mwpfm_filter_value_text');
		const filterValueSelectWrap = document.getElementById('mwpfm_filter_value_select_wrap');
		const filterValueSelect = document.getElementById('mwpfm_filter_value_select');
		const filterEmpty = document.getElementById('mwpfm_filter_empty');

		const bulkField = document.getElementById('mwpfm_bulk_field_key');
		const bulkAction = document.getElementById('mwpfm_action');

		const newTextWrap = document.getElementById('mwpfm_new_value_text_wrap');
		const newTextareaWrap = document.getElementById('mwpfm_new_value_textarea_wrap');
		const newSelectWrap = document.getElementById('mwpfm_new_value_select_wrap');
		const newText = document.getElementById('mwpfm_bulk_new_value_text');
		const newTextarea = document.getElementById('mwpfm_bulk_new_value_textarea');
		const newSelect = document.getElementById('mwpfm_bulk_new_value_select');

		const oldTextWrap = document.getElementById('mwpfm_old_value_text_wrap');
		const oldSelectWrap = document.getElementById('mwpfm_old_value_select_wrap');
		const oldText = document.getElementById('mwpfm_bulk_old_value_text');
		const oldSelect = document.getElementById('mwpfm_bulk_old_value_select');

		const newValueHidden = document.getElementById('mwpfm_bulk_new_value_hidden');
		const oldValueHidden = document.getElementById('mwpfm_bulk_old_value_hidden');

		function getOptionData(selectEl) {
			const selected = selectEl.options[selectEl.selectedIndex];
			if (!selected) {
				return { type: 'text', options: [] };
			}

			let options = [];
			try {
				options = JSON.parse(selected.getAttribute('data-options') || '[]');
			} catch (e) {
				options = [];
			}

			return {
				type: selected.getAttribute('data-type') || 'text',
				options: Array.isArray(options) ? options : []
			};
		}

		function rebuildSelectOptions(selectEl, options) {
			selectEl.innerHTML = '';
			const placeholder = document.createElement('option');
			placeholder.value = '';
			placeholder.textContent = 'Select option';
			selectEl.appendChild(placeholder);

			options.forEach(function (option) {
				const el = document.createElement('option');
				el.value = option;
				el.textContent = option;
				selectEl.appendChild(el);
			});
		}

		function syncFilterInputUi() {
			const data = getOptionData(filterField);

			if (data.type === 'select') {
				rebuildSelectOptions(filterValueSelect, data.options);
				filterValueTextWrap.style.display = 'none';
				filterValueSelectWrap.style.display = '';
				filterValueText.disabled = true;
				filterValueSelect.disabled = false;
				filterValueText.value = '';
			} else {
				filterValueTextWrap.style.display = '';
				filterValueSelectWrap.style.display = 'none';
				filterValueText.disabled = false;
				filterValueSelect.disabled = true;
				filterValueSelect.innerHTML = '<option value="">Select option</option>';
			}
		}

		function syncBulkValueUi() {
			const data = getOptionData(bulkField);
			const action = bulkAction.value;

			newTextWrap.style.display = 'none';
			newTextareaWrap.style.display = 'none';
			newSelectWrap.style.display = 'none';
			oldTextWrap.style.display = 'none';
			oldSelectWrap.style.display = 'none';

			newText.disabled = true;
			newTextarea.disabled = true;
			newSelect.disabled = true;
			oldText.disabled = true;
			oldSelect.disabled = true;

			if (data.type === 'select') {
				rebuildSelectOptions(newSelect, data.options);
				rebuildSelectOptions(oldSelect, data.options);

				if (action !== 'clear') {
					newSelectWrap.style.display = '';
					newSelect.disabled = false;
				}

				if (action === 'replace_old_new') {
					oldSelectWrap.style.display = '';
					oldSelect.disabled = false;
				}
			} else if (data.type === 'textarea') {
				if (action !== 'clear') {
					newTextareaWrap.style.display = '';
					newTextarea.disabled = false;
				}

				if (action === 'replace_old_new') {
					oldTextWrap.style.display = '';
					oldText.disabled = false;
				}
			} else {
				if (action !== 'clear') {
					newTextWrap.style.display = '';
					newText.disabled = false;
				}

				if (action === 'replace_old_new') {
					oldTextWrap.style.display = '';
					oldText.disabled = false;
				}
			}

			if (data.type !== 'textarea') {
				for (let i = 0; i < bulkAction.options.length; i++) {
					const val = bulkAction.options[i].value;
					if (val === 'append' || val === 'prepend') {
						bulkAction.options[i].style.display = 'none';
					} else {
						bulkAction.options[i].style.display = '';
					}
				}

				if (action === 'append' || action === 'prepend') {
					bulkAction.value = 'set';
					syncBulkValueUi();
				}
			} else {
				for (let i = 0; i < bulkAction.options.length; i++) {
					bulkAction.options[i].style.display = '';
				}
			}
		}

		function collectSelectedSiteIds() {
			const selected = [];
			for (let i = 0; i < siteSelect.options.length; i++) {
				if (siteSelect.options[i].selected) {
					selected.push(siteSelect.options[i].value);
				}
			}
			return selected;
		}

		function syncHiddenValuesForSubmit() {
			const bulkFieldData = getOptionData(bulkField);

			if (bulkFieldData.type === 'select') {
				newValueHidden.value = newSelect.value || '';
				oldValueHidden.value = oldSelect.value || '';
			} else if (bulkFieldData.type === 'textarea') {
				newValueHidden.value = newTextarea.value || '';
				oldValueHidden.value = oldText.value || '';
			} else {
				newValueHidden.value = newText.value || '';
				oldValueHidden.value = oldText.value || '';
			}

			const filterFieldData = getOptionData(filterField);
			if (filterFieldData.type === 'select') {
				filterValueText.value = filterValueSelect.value || '';
			}

			siteIdsSerialized.value = collectSelectedSiteIds().join(',');
		}

		siteSearch.addEventListener('input', function () {
			const term = this.value.toLowerCase();
			for (let i = 0; i < siteSelect.options.length; i++) {
				const opt = siteSelect.options[i];
				opt.style.display = opt.text.toLowerCase().includes(term) ? '' : 'none';
			}
		});

		document.getElementById('mwpfm-select-all').addEventListener('click', function () {
			for (let i = 0; i < siteSelect.options.length; i++) {
				const opt = siteSelect.options[i];
				if (opt.style.display !== 'none') {
					opt.selected = true;
				}
			}
			siteSelect.focus();
		});

		document.getElementById('mwpfm-clear-all').addEventListener('click', function () {
			for (let i = 0; i < siteSelect.options.length; i++) {
				siteSelect.options[i].selected = false;
			}
			siteSelect.focus();
		});

		document.getElementById('mwpfm-select-filtered').addEventListener('click', function () {
			const search = siteSearch.value.toLowerCase();
			const field = filterField.value;
			const empty = filterEmpty.checked;
			const fieldData = getOptionData(filterField);
			const value = fieldData.type === 'select'
				? (filterValueSelect.value || '').toLowerCase()
				: (filterValueText.value || '').toLowerCase();

			for (let i = 0; i < siteSelect.options.length; i++) {
				const opt = siteSelect.options[i];
				let match = true;

				if (search && !opt.text.toLowerCase().includes(search)) {
					match = false;
				}

				if (field) {
					const key = field.replace(/[^a-z0-9]/gi, '').toLowerCase();
					const val = (opt.dataset[key] || '').toLowerCase();

					if (empty) {
						if (val !== '') {
							match = false;
						}
					} else if (value && val !== value) {
						match = false;
					}
				}

				opt.selected = !!match;
			}

			siteSelect.focus();
		});

		filterField.addEventListener('change', syncFilterInputUi);
		bulkField.addEventListener('change', syncBulkValueUi);
		bulkAction.addEventListener('change', syncBulkValueUi);

		bulkForm.addEventListener('submit', function () {
			syncHiddenValuesForSubmit();
			for (let i = 0; i < siteSelect.options.length; i++) {
				if (siteSelect.options[i].selected) {
					siteSelect.options[i].style.display = '';
				}
			}
		});

		syncFilterInputUi();
		syncBulkValueUi();
	});
	</script>
	<?php
}
