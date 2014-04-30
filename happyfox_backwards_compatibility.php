<?php
	/*
	 * HappyFox for WordPress backwards compatibility
	 *
	 * This file contains some functions which are
	 * not available in older versions of WordPress.
	 * These functions will enable WordPress 3.0 or
	 * less to support the HappyFox plugin.
	 *
	 * @author Balu Vaithinathan
	 * @version 1.0.0
	 *
	 */
 
	/*
	 * Function esc_textarea
	 *
	 * Escape textarea values
	 */
	if(!function_exists('esc_textarea')) {
		function esc_textarea($text) {
			$safe_text = htmlspecialchars($text, ENT_QUOTES);
			return apply_filters('esc_textarea', $safe_text, $text);
		}
	}
	
	/*
	 * Function get_user_meta
	 *
	 * Retrieve the user meta data from the wordpress
	 * database.
	 */
	if(!function_exists('get_user_meta')) {
		function get_user_meta($user_id, $key, $single = false) {
			return get_usermeta($user_id, $key);
		}
	}
	
	/*
	 * Function update_user_meta
	 *
	 * Update a user's metadata in the wordpress
	 * database.
	 */
	if(!function_exists('update_user_meta')) {
		function update_user_meta($user_id, $key, $value, $prev_value = '') {
			return update_usermeta($user_id, $key, $value);
		}
	}
?>