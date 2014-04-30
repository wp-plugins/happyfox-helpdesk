<?php
	//if uninstall not called from WordPress exit
	if (!defined('WP_UNINSTALL_PLUGIN')) {
		exit();
	}

	$happyfox_settings = 'happyfox_settings';
	delete_option($happyfox_settings);
?>
