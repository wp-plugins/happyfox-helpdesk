<?php
/*
 * Plugin Name: HappyFox Helpdesk
 * Plugin URI: http://happyfox.com
 * Description: HappyFox integration for WordPress
 * Author: Balu Vaithinathan
 * Version: 1.0.0
 * Author URI: http://baluvaithinathan.com
 *
 */

 
require_once(plugin_dir_path(__FILE__) . 'happyfox_backwards_compatibility.php');
require_once(plugin_dir_path(__FILE__) . 'happyfox_api.php');
 
 class HappyFox {
	public $settings = array();
	public $is_ticket_reply = false;
	
	public function __construct() {
		add_action('admin_menu', array(&$this, '_admin_menu'));
		add_action('admin_init', array(&$this, '_admin_init'));
		add_action('wp_ajax_happyfox_convert_to_ticket', array( &$this, '_convert_to_ticket'));
		add_action('wp_ajax_happyfox_convert_to_ticket_dialog', array(&$this, '_convert_to_ticket_dialog'));
		add_action('wp_ajax_view_happyfox_ticket', array(&$this, '_view_ticket_details'));
		add_action('wp_ajax_happyfox_filter_view', array(&$this, '_filter_view'));
		add_action('wp_ajax_happyfox_paging', array(&$this, '_go_to_page'));
		add_action('wp_insert_comment', array(&$this, '_update_happyfox_ticket'), 34, 2);
		
		//Initialize the plugin
		$this->setup();
		
		//Set up the HappyFox widget in Admin Panel
		add_action('wp_dashboard_setup', array(&$this, '_happyfox_widget_setup'));
		
		//Check if support tab is enabled
		if(isset($this->settings['happyfox_support_tab']) && $this->settings['happyfox_support_tab'] == "enabled") {
			add_action('wp_head', array(&$this, '_happyfox_support_tab'));
		}
	}
	
	public function setup() {
		//Read and load settings and set up the API object
		$this->_settings();
		
		if($this->settings === false) {
			$this->_default_settings();
		}
		
		$this->happyfox_url = 'https://' . $this->settings['account'] . '.happyfox.com';
		$this->api_key = $this->settings['api_key'];
		$this->api_auth = $this->settings['api_auth'];
		$this->api = new HappyFox_API($this->happyfox_url, $this->api_key, $this->api_auth);
	}
	
	private function _settings() {
		//Read settings from db, else assign false to $this->settings
		//will be false during first run, and default settings will take effect
		$this->settings = get_option('happyfox_settings', false);
		$this->default_settings = array(
			//account related
			'account' => '',
			'api_key' => '',
			'api_auth' => '',
			
			//default ticket category for viewing and adding
			'happyfox_tickets_category' => '',
			
			//contact form settings
			'happyfox_dashboard_administrator' => 'tickets',
			'happyfox_dashboard_editor' => 'contact-form',
			'happyfox_dashboard_author' => 'contact-form',
			'happyfox_dashboard_contributor' => 'contact-form',
			'happyfox_dashboard_subscriber' => 'contact-form',
			'happyfox_contact_form_title' => 'HappyFox Contact Form',
			'happyfox_contact_form_summary' => 'Please describe your question briefly',
			'happyfox_contact_form_description' => 'Please provide us some additional details',
			'happyfox_contact_form_submit' => 'Submit',
			
			//support tab
			'happyfox_support_tab' => 'disabled',
			'happyfox_support_tab_code' => '',
		);
	}
	
	private function _default_settings() {
		$this->settings = $this->default_settings;
		$this->_update_happyfox_settings();
	}
	
	private function _update_happyfox_settings() {
		update_option('happyfox_settings', $this->settings);
	}
	
	private function _delete_happyfox_settings() {
		delete_option('happyfox_settings');
	}
	
	public function _admin_init() {
		//Load js and css
		add_action('admin_print_styles', array(&$this, '_load_scripts_and_styles'));
		
		//Add a column in Comments and a custom "Convert to HappyFox Ticket" link
		add_filter('manage_edit-comments_columns', array(&$this, '_happyfox_comments_column'), 10, 1);
		add_filter('comment_row_actions', array(&$this, '_happyfox_comment_row_actions'), 10, 2);
		add_action('manage_comments_custom_column', array(&$this, '_happyfox_comments_column_action'), 10, 1);
		add_action('admin_notices', array(&$this, '_happyfox_admin_notices'));
		
		//HappyFox Settings
		register_setting('happyfox_settings', 'happyfox_settings', array(&$this, '_validate_settings'));
		
		//Display Settings fields
		add_settings_section('credentials', "Your HappyFox Account", array(&$this, '_happyfox_settings_credentials'), 'happyfox_settings');
		add_settings_field('account', 'Subdomain *', array(&$this, '_happyfox_settings_account'), 'happyfox_settings', 'credentials');
		add_settings_field('api_key', 'API Key *', array(&$this, '_happyfox_settings_api_key'), 'happyfox_settings', 'credentials');
		add_settings_field('api_auth', 'API Auth Code *', array(&$this, '_happyfox_settings_api_auth'), 'happyfox_settings', 'credentials');
		
		//Show other settings fields once an account has been set up
		if($this->settings['account']) {
			//Default category selection for viewing and creating tickets
			add_settings_section('happyfox_tickets_category', 'Choose Ticket Category',
				array(&$this, '_happyfox_settings_tickets_category'), 'happyfox_settings');
			
			add_settings_field('tickets_category', 'Choose Category', array(&$this, '_happyfox_category_selection'),
				'happyfox_settings', 'happyfox_tickets_category');
			
			//Dashboard Widget Visibility
			add_settings_section('happyfox_dashboard_widget', 'Dashboard Widget Visibility',
				array(&$this, '_happyfox_settings_dashboard_widget' ), 'happyfox_settings');
				
			add_settings_field('dashboard_administrator', 'Administrators', array(&$this,
				'_happyfox_settings_dashboard_access' ), 'happyfox_settings', 'happyfox_dashboard_widget',
				array('role' => 'administrator'));
      
			add_settings_field('dashboard_editor', 'Editors', array(&$this, '_happyfox_settings_dashboard_access'),
				'happyfox_settings', 'happyfox_dashboard_widget', array('role' => 'editor'));
			
			add_settings_field('dashboard_author', 'Authors', array(&$this, '_happyfox_settings_dashboard_access'),
				'happyfox_settings', 'happyfox_dashboard_widget', array('role' => 'author'));
		
			add_settings_field('dashboard_contributor', 'Contributors', array(&$this, '_happyfox_settings_dashboard_access'),
				'happyfox_settings', 'happyfox_dashboard_widget', array('role' => 'contributor'));
			
			add_settings_field('dashboard_subscriber', 'Subscribers', array(&$this, '_happyfox_settings_dashboard_access'),
				'happyfox_settings', 'happyfox_dashboard_widget', array('role' => 'subscriber'));
			
			
			//Contact Form Settings, coming soon!
			/*add_settings_section('happyfox_contact_form', 'Contact Form Settings', array(&$this, '_happyfox_settings_contact_form'),
				'happyfox_settings');
			
			add_settings_field('happyfox_contact_form_title', 'Contact Form Title', array(&$this, '_happyfox_settings_contact_title'),
				'happyfox_settings', 'happyfox_contact_form');
			
			add_settings_field('happyfox_contact_form_summary', 'Summary Label', array(&$this, '_happyfox_settings_contact_summary'),
				'happyfox_settings', 'happyfox_contact_form');
			
			add_settings_field('happyfox_contact_form_description', 'Description Label', array(&$this, '_happyfox_settings_contact_description'),
				'happyfox_settings', 'happyfox_contact_form');
			
			add_settings_field('happyfox_contact_form_submit', 'Submit Button Label', array(&$this, '_happyfox_settings_contact_submit'),
				'happyfox_settings', 'happyfox_contact_form');*/
			
			add_settings_section('happyfox_support_tab', 'HappyFox Support Tab', array(&$this, '_happyfox_settings_support_tab'),
				'happyfox_settings');
				
			add_settings_field('happyfox_support_tab_display', 'Display', array(&$this, '_happyfox_settings_support_tab_display'),
				'happyfox_settings', 'happyfox_support_tab');
			
			add_settings_field('happyfox_support_tab_code', 'HappyFox Support Tab code', array(&$this, '_happyfox_settings_support_tab_code'),
				'happyfox_settings', 'happyfox_support_tab');
		}
	}
	
	/*
	 * Admin Menu
	 *
	 * Registers an admin menu page for HappyFox, where
	 * various HappyFox for WordPress plugin options are
	 * configured.
	 *
	 */
	 public function _admin_menu() {
		add_menu_page('HappyFox Settings', 'HappyFox', 'manage_options', 'happyfox_settings',
			array(&$this, '_admin_menu_html'), plugins_url('/images/happyfox-16.png', __FILE__));
	 }
	 
	/*
	 * Admin Menu HTML
	 *
	 * Outputs the required HTML for the HappyFox
	 * settings page. This is the function hooked
	 * to the 'admin_menu' action hook.
	 */
	public function _admin_menu_html() {
		?>
		<div class="wrap">
			<div id="happyfox-icon" class="icon32"></div>
			<h2>HappyFox Settings</h2>
			
			<!-- if HappyFox account is not set up yet, ask for it -->
			<?php if(!$this->settings['account']): ?>
			<div id="message">
				<p>
					<strong>You're almost ready! Set up your HappyFox subdomain...</strong>
				</p>
				
				<p>
					Before you can start using the HappyFox plugin for WordPress, we need to know
					your HappyFox subdomain, API Key and Auth Code so that we can identify your
					account and retrieve your tickets.
				</p>
			</div>
			<?php endif; ?>
			
			<form method="post" action="options.php">
				<?php wp_nonce_field('update_options');?>
				<?php settings_fields('happyfox_settings');?>
				<?php do_settings_sections('happyfox_settings');?>
				<input type="submit" value="Save Settings" name="Submit" class="button-primary"/>
			</form>
		</div>
		<?php
	}
	
	/*
	 * CSS and JavaScript files for the HappyFox
	 * Admin Menu
	 */
	public function _load_scripts_and_styles() {
		wp_enqueue_style('happyfox_admin', plugins_url('/css/happyfox.css', __FILE__));
		wp_enqueue_style('colorbox', plugins_url('/css/colorbox.css', __FILE__));
		wp_enqueue_style('happyfox_pagination', plugins_url('/css/jqpagination.css', __FILE__));
		wp_enqueue_script('happyfox_admin', plugins_url('/js/happyfox.js', __FILE__), array('jquery'));
		wp_enqueue_script('colorbox', plugins_url('/js/jquery.colorbox-min.js', __FILE__), array('jquery'));
		wp_enqueue_script('happyfox_pagination', plugins_url('/js/jquery.jqpagination.min.js', __FILE__), array('jquery'));
	}
	
	/*
	 * HappyFox Support Tab
	 *
	 * Displays the JavaScript code for the HappyFox 
	 * Support tab, copied and pasted from HappyFox. 
	 */
	public function _happyfox_support_tab() {
		echo $this->settings['happyfox_support_tab_code'];
	}
	
	/*
	 * Validate changes made to HappyFox settings. Sanitizes
	 * and returns the settings in an array, which is stored
	 * in the database.
	 */
	public function _validate_settings($settings) {
		$roles = array('administrator', 'editor', 'author', 'contributor', 'subscriber'); //Available WP roles
		$form_options = array(
			'happyfox_contact_form_title',
			'happyfox_contact_form_summary',
			'happyfox_contact_form_details',
			'happyfox_contact_form_submit'
		); //Form customization options
		
		//Validate account name
		if(!preg_match('/^[a-zA-Z0-9]{1,}$/', $settings['account']) || empty($settings['account'])) {
			unset($settings['account']);
			add_settings_error('happyfox_settings', 'happyfox_account_required', 'Please fill in your HappyFox Account name. Only letters and numbers allowed.');
		}
		
		if(empty($settings['api_key']) || empty($settings['api_auth'])) {
			add_settings_error('happyfox_settings', 'happyfox_api_info', 'Please fill in your API Key and API Auth Code.');
		}
		
		//Ticket category validation
		if(!empty($settings['api_key']) && !empty($settings['api_auth']) && empty($settings['happyfox_tickets_category'])) {
			add_settings_error('happyfox_settings', 'happyfox_choose_category', 'You must choose a HappyFox Category to display tickets in the HappyFox Tickets Widget!');
		}
		
		//Dashboard widget settings
		foreach($roles as $role) {
			if(isset($settings['dashboard_' . $role]) && !array_key_exists($settings['dashboard_' . $role], $this->_widget_options)) {
				unset($settings['dashboard_' . $role]);
			}
		}
		
		//Contact form options
		foreach($form_options as $option) {
			$settings[$option] = empty($settings[$option]) ? $this->default_settings[$option] : htmlspecialchars(trim($settings[$option]));
		}
		
		//Merge and save settings if it exists, or merge with default settings
		if(is_array($this->settings)) {
			array_merge($this->settings, $settings);
		} else {
			array_merge($this->default_settings, $settings);
		}
		
		return $settings;
	}
	
	/*
	 * Convert to ticket link
	 *
	 * This method checks if comments contain either 
	 * happyfox_ticket or happyfox_reply as the meta,
	 * and if not, will add a "create a happyfox
	 * ticket" link to the comment options.
	 */
	public function _happyfox_comment_row_actions($actions, $comment) {
		if($comment->comment_type !== 'pingback' && (!get_comment_meta($comment->comment_ID, 'happyfox_ticket', true) && !get_comment_meta($comment->comment_ID, 'happyfox_reply', true)) && get_comment_author_email($comment->comment_ID) !== "") {
			$actions['happyfox'] = '<a class="happyfox-convert" href="#" onclick="return false;" data-id="' . $comment->comment_ID . '">Create a HappyFox Ticket</a>';
		}
		return $actions;
	}
	
	public function _happyfox_comments_column($columns) {
		$columns['happyfox'] = "HappyFox Ticket ID";
		return $columns;
	}
	
	public function _happyfox_comments_column_action($column) {
		global $comment;
		
		if($column == "happyfox") {
			$ticket_id = get_comment_meta($comment->comment_ID, 'happyfox_ticket', true);
		}
		
		if($comment->comment_type !== 'pingback' && $ticket_id) {
			echo '<a target="_blank" class="happyfox-comment-ticket-id" href="' . $this->_ticket_url($ticket_id) . '">' . $ticket_id . '</a>';
		}
	}

	
	/*
	 * -------------------------------------------------
	 * ALL SERIOUS BUSINESS HAPPENS FROM THIS POINT ON!
	 * -------------------------------------------------
	 */
	 
	/*
	 * AJAX "Convert comment to HappyFox ticket" dialog
	 *
	 * Displays the "Convert comment to HappyFox ticket"
	 * dialog, and returns the actual comment content to
	 * be converted to as the HappyFox ticket. Also
	 * verifies if current commenter has an email address.
	 * Only returns the comment if the commenter has an
	 * email address.
	 */
	public function _convert_to_ticket_dialog() {
		if(isset($_REQUEST['comment_id']) && is_numeric($_REQUEST['comment_id'])) {
			$comment_id = $_REQUEST['comment_id'];
			$comment = get_comment($comment_id);
			$current_user = wp_get_current_user();
			$happyfox_and_wp_emails_are_same = $this->api->get_staff($current_user->user_email);
			
			if($comment) {
				$html = array(); //html for the popup dialog
				
				//The comment being converted
				$html[] = '<div class="happyfox-convert-to-ticket clearfix">';
				$html[] = get_avatar($comment->get_author_email, 64);
				$html[] = '<div class="happyfox-comment-box">';
				$html[] = '<p class="happyfox-comment-author"><strong>' . $comment->comment_author . '</strong>' . ' said...</p>';
				$html[] = wpautop(strip_tags($comment->comment_content));
				$html[] = '<p class="happyfox-comment-date">' . date(get_option('date_format') . ' \a\t ' .get_option('time_format'), strtotime($comment->comment_date)) . '</p>';
				$html[] = '</div>';
				
				if($happyfox_and_wp_emails_are_same == false) {
					$html[] = '</div>'; //happyfox-convert-to-ticket
					$html[] = '<div class="happyfox-error">';
					$html[] = '<p class="happyfox-ticket-notice">';
					$html[] = "Whoops! It looks like your HappyFox Account email address and your WordPress profile email address are <strong><em>different</em></strong>, and
						as such, you will not be able to create a HappyFox Ticket and add a staff reply to it! :( Please use your <strong><em>HappyFox Staff</em></strong>
						email address for your WordPress account in order to create HappyFox Tickets.";
					$html[] = '</p></div>';
				} else {
					//Notice to ticket admin
					$html[] = '<p class="happyfox-ticket-notice">';
					$html[] = "A new ticket will be created in your HappyFox account, and your reply below will be added as a comment to that ticket";
					$html[] = '</p>';
				
					//User response form
					$html[] = get_avatar(get_current_user_id(), 64);
					$html[] = '<div class="happyfox-comment-box">';
					$html[] = '<p class="happyfox-comment-author"><strong>Your</strong> response...</p>';
					$html[] = '<form class="happyfox-ticket-converter-form">';
					$html[] = '<textarea name="comment-reply" class="happyfox-reply-textarea"></textarea>';
					$html[] = '<div class="happyfox_options">';
					$html[] = '<label><input name="happyfox_comment_reply_option" value="public" type="radio" checked="checked"/> Post this as a public comment on Wordpress</label><br/>';
					$html[] = '<label><input name="happyfox_comment_reply_option" value="email" type="radio"/> Send as a personal notification only</label>';
					$html[] = '</div>'; //class happyfox_options
					$html[] = '<input type="hidden" name="happyfox_comment_id" value="' . $comment->comment_ID . '"/>';
					$html[] = '<div class="happyfox-disclaimer"><p>Any update to the comment will be updated on HappyFox ticket and be triggered as a personal notification to the end user</p></div>';
					$html[] = '<input type="submit" value="Create Ticket" class="happyfox-submit button-primary"/><br/><br/>';
					$html[] = '<div class="happyfox-spinner" style="display:none;">Submitting...</div>';
					$html[] = '<div class="happyfox-notification-area"></div>';
					$html[] = '</form>';
					$html[] = '</div>'; //comment box
					$html[] = '</div>';
				}
				
				$html = implode("\n", $html);
				
				$response = array(
					'status' => 200,
					'html' => $html
				);
			}
		}
		
		echo json_encode($response);
		die();
	}
	
	/*
	 * Convert comments into HappyFox Tickets
	 *
	 * This method handles the conversion of WordPress
	 * comments into HappyFox tickets. It also handles
	 * the assignment of the comment reply as a public
	 * comment on the ticket, posting the reply on
	 * WordPress, associating a WordPress comment with
	 * a ticket and simply emailing the commenter with
	 * the reply.
	 */
	public function _convert_to_ticket() {
		$response = array(
			'status' => 500,
			'error' => 'An error occurred while communicating with HappyFox servers. Please, try again later'
		);
		
		if(isset($_REQUEST['comment_id']) && is_numeric($_REQUEST['comment_id'])) {
			$comment_id = $_REQUEST['comment_id'];
			$comment = get_comment($comment_id);
			
			//Check if comment is valid
			if($comment && $comment->comment_type !== 'pingback') {
				//Check if the commenter has an email address
				if(get_comment_author_email($comment_id) != "") {
					//The post in which the comment was posted
					$post = get_post($comment->comment_post_ID);
					
					$message = trim(stripslashes($_REQUEST['comment_reply']));
					$public_comment = $_REQUEST['happyfox_public_comment']; //reply on wp also?
					
					if($public_comment != "public" && $public_comment != "email") {
						$public_comment = "email"; //default
					}
					
					$category = $this->settings['happyfox_tickets_category'];
					$subject = "Comment from WordPress blog post: " . $post->post_title;
					$description = htmlspecialchars($comment->comment_content);
					$raised_by = $comment->comment_author;
					$raised_by_email = $comment->comment_author_email;
					
					$ticket_id = $this->api->create_ticket($category, $subject, $description, $raised_by, $raised_by_email);
					
					if($ticket_id) {
						update_comment_meta($comment->comment_ID, 'happyfox_ticket', $ticket_id);
						
					
						$this->is_ticket_reply = true;
						$current_user = wp_get_current_user();
						
						//convert ticket display id into normal ID
						if(!empty($message)) {
							//Post the reply as a reply in the created ticket
							//get staff id through email, to post reply as a staff update in the ticket
							$staff = $this->api->get_staff($current_user->user_email);
							$staff = $staff->id; //get the staff ID
							$id = (string) intval(substr($ticket_id, -8));
							$replied = $this->api->add_staff_reply($staff, $id, $message);
						} else {
							$replied = true; //hacky, but hey...
						}
						
						$ticket_url = $this->_ticket_url($ticket_id);
						
						if($replied) {
							//everything went well
							$response = array(
								'status' => 200,
								'ticket_url' => $ticket_url,
								'ticket_id' => $ticket_id,
							);
							
							//Check if admin wants to make the update a WP reply to the original comment
							if($public_comment === "public" && !empty($message)) {
								$wp_comment = array(
									'comment_post_ID' => $post->ID,
									'comment_author' => $current_user->display_name,
									'comment_author_email' => $current_user->user_email,
									'comment_content' => $message,
									'comment_parent' => $comment_id,
									'user_id' => $this->user->ID,
									'comment_date' => current_time('mysql'),
									'comment_approved' => 1
								);
								
								$comment_reply_id = wp_insert_comment($wp_comment);
								
								//update meta of the comment reply and mark it as happyfox_reply
								$id = (string) intval(substr($ticket_id, -8)); //display ID into normal ID
								update_comment_meta($comment_reply_id, 'happyfox_reply', $id);
							}
						} else {
							//could not add reply to ticket
							$response = array(
								'status' => 207,
								'ticket_url' => $ticket_url,
								'ticket_id' => $ticket_id,
								'message' => "A HappyFox ticket was successfully created, but your reply could not be added to it."
							);
						}
					} else {
						$response = array(
							'status' => 500,
							'message' => "An error occurred while trying to create a HappyFox ticket. Please, try again later.");
					}
				} else {
					$response = array(
						'status' => 500,
						'message' => "The comment author has not shared their email address for follow up communication, and hence cannot be converted into a ticket :("
					);
				}
			}
		}
		
		echo json_encode($response);
		die();
	}
	
	/*
	 * Update a HappyFox Ticket
	 *
	 * This method is executed right after a comment 
	 * is added to any post. This method checks if a 
	 * comment is a reply to another comment that has
	 * been converted to a HappyFox Ticket. If so, it
	 * will also update that HappyFox ticket with the
	 * user reply.
	 */
	public function _update_happyfox_ticket($comment_id, $comment_object) {
		//if the new comment is a reply...
		if($comment_object->comment_parent != 0) {
			$parent_comment_id = $comment_object->comment_parent;
			$top_most = false;
			$ticket_id = 0;
			
			//Check if reply is from ticket converter dialog, or from within WordPress
			//If former, return immediately as there is nothing to do here. Else, add reply to HappyFox.
			if($this->is_ticket_reply) {
				$this->is_ticket_reply = false;
				return;
			}
			
			//Loop until encountering a HappyFox Ticket / HappyFox Ticket Reply
			//or until topmost comment is reached
			while($top_most == false) {
				if($parent_comment_id == 0) {
					//top most comment reached, end loop
					break;
				}
				
				//check if parent comment is a HappyFox ticket
				$ticket_id = get_comment_meta($parent_comment_id, 'happyfox_ticket', true);
				
				if($ticket_id) {
					//must update a HappyFox Ticket using API
					//only update ticket if reply is from original customer or HF Staff
					
					$original_customer_email = get_comment($parent_comment_id)->comment_author_email;
					$current_user_email = wp_get_current_user()->user_email;
					
					if($original_customer_email === $comment_object->comment_author_email || $current_user_email === $comment_object->comment_author_email) {
						//good to go
						
						//check if commenter is already a HappyFox contact
						$client_id = $this->api->user_is_a_contact($comment_object->comment_author_email);
						
						if($client_id === false) {
							//check if the reply author is a HappyFox staff
							$client_id = $this->api->get_staff($comment_object->comment_author_email);
							
							//If neither an existing contact nor a staff, create new contact
							if($client_id === false) {
								//$client_id = $this->api->create_contact($comment_object->comment_author_email, $comment_object->comment_author);
								//do nothing, just a regular commenter
								return; //exit loop
							} else {
								//reply author is a HappyFox staff, add reply and return.
								update_comment_meta($comment_id, 'happyfox_reply', $ticket_id); //mark as ticket reply, so as to hide "create ticket" link in comments section
								$client_id = $client_id->id;
								$ticket_id = (string) intval(substr($ticket_id, -8)); //convert ticket display_id into normal id
								$message = $comment_object->comment_content;
								$this->api->add_staff_reply($client_id, $ticket_id, $message);
								return;
							}
						} else {
							if($client_id) {
								$parent_comment = get_comment($parent_comment_id);
								
								//update comment meta to happyfox_reply if topmost comment author and this author are same
								//that is, the same customer is replying to their own ticket on WordPress
								if($parent_comment->comment_author_email === $comment_object->comment_author_email) {
									update_comment_meta($comment_id, 'happyfox_reply', $ticket_id);
								}
								
								$ticket_id = (string) intval(substr($ticket_id, -8)); //convert ticket display_id into normal id
								
								//now, add the user reply to the ticket
								$message = $comment_object->comment_content;
								$this->api->add_user_reply($client_id, $ticket_id, $message);
								
								$top_most = true; //break outta the loop
							}
						}
					}
				} else {
					//go one level up, if possible
					$parent_comment = get_comment($parent_comment_id);
					$parent_comment_id = $parent_comment->comment_parent;
				}
			}
		}
	}
	
	/*
	 * ----------------------------------------------------
	 * HappyFox Tickets Widget for Administrator Dashboard
	 * ----------------------------------------------------
	 *
	 * This section contains methods related to the
	 * admin dashboard widget, such as filtering views,
	 * viewing ticket details, contact form for making
	 * support requests etc.
	 */
	
	/*
	 * A list of available options for the HappyFox
	 * dashboard widget. This method returns an
	 * array of all available dashboard widget
	 * options.
	 */
	private function _widget_options() {
		return array('none', 'contact-form', 'tickets');
	}
	
	/*
	 * Get the HappyFox widget type for the current user
	 */
	private function _get_dashboard_widget_type() {
		$role = $this->_get_current_user_role();
		
		if(array_key_exists('happyfox_dashboard_' . $role, (array) $this->settings)) {
			return $this->settings['happyfox_dashboard_' . $role];
		}
		
		return 'none';
	}
	
	/*
	 * Filter view
	 *
	 * This method allows a HappyFox user to retrieve
	 * tickets in the tickets widget on the basis of 
	 * its status: "pending" or "solved".
	 */
	public function _filter_view() {
		if(isset($_REQUEST['view']) && !empty($_REQUEST['view'])) {
			$requested_view = $_REQUEST['view'];
			
			if($this->_get_dashboard_widget_type() != 'tickets') {
				return; //No cheating plz.
			}
			
			if(!isset($this->settings['current_view'])) {
				$this->settings['current_view'] = $requested_view;
				//save this view in options so the same view will be loaded next time
				update_option('happyfox_settings', $this->settings);
			} else {
				if($this->settings['current_view'] != $requested_view) {
					//update the ticket view change
					$this->settings['current_view'] = $requested_view;
					update_option('happyfox_settings', $this->settings);
				}
			}
			
			$current_user = wp_get_current_user();
			$tickets = $this->api->get_tickets_with_status($requested_view, $this->settings['happyfox_tickets_category'], $current_user->user_email);
			
			if(is_wp_error($tickets)) {
				$response = array(
					'status' => 404,
					'error' => $tickets->get_error_message()
				);
			} else {
				$response = array(
					'status' => 200,
					'html' => $this->_tickets_into_html($tickets, true)
				);
			}
		} else {
			$response = array(
				'status' => 403,
				'error' => "Access denied."
			);
		}
		
		echo json_encode($response);
		die();
	}
	
	/*
	 * Go to page
	 *
	 * This method fetches a particular page number
	 * if there is more than 1 page of HappyFox
	 * tickets.
	 */
	public function _go_to_page() {
		if(isset($_REQUEST['page']) && is_numeric($_REQUEST['page'])) {
			$page = $_REQUEST['page'];
			$view = $_REQUEST['view'];
			
			$tickets = $this->api->get_page($page, $view, $this->settings['happyfox_tickets_category']);
			
			if(!is_wp_error($tickets)) {
				$response = array(
					'status' => 200,
					'html' => $this->_tickets_into_html($tickets)
				);
			} else {
				$response = array(
					'status' => 404,
					'error' => $tickets->get_error_message()
				);
			}
			
			echo json_encode($response);
			die();
		}
	}
	
	/*
	 * HappyFox Dashboard Widget setup
	 *
	 * This method sets up the correct dashboard widget
	 * for each user type, based on the settings.
	 */
	public function _happyfox_widget_setup() {
		$widget_options = $this->_get_dashboard_widget_type();
		
		if(!isset($this->settings['account']) || empty($this->settings['account'])) {
			wp_add_dashboard_widget('happyfox_dashboard_widget', 'HappyFox for WordPress', array(&$this, '_dashboard_widget_config'));
			return;
		}
		
		if(!isset($this->settings['happyfox_tickets_category']) || empty($this->settings['happyfox_tickets_category'])) {
			wp_add_dashboard_widget('happyfox_dashboard_widget', 'HappyFox for WordPress', array(&$this, '_dashboard_widget_config'));
			return;
		}
		
		if(isset($this->settings['account']) && (!isset($widget_options) || $widget_options == "none")) {
			return;
		}
		
		if($widget_options == "contact-form") {
			wp_add_dashboard_widget('happyfox_dashboard_widget', 'HappyFox Contact Support Form', array(&$this, '_dashboard_widget_contact_form'));
		} else {
			wp_add_dashboard_widget('happyfox_dashboard_widget', 'HappyFox Tickets Widget', array(&$this, '_dashboard_widget_tickets'));
		}
	}
	
	/*
	 * Dashboard Widget Configuration
	 *
	 * Displays a message to the admin to set up the 
	 * plugin if no settings are available, or if the
	 * user is non-admin, displays a message
	 * prompting them to contact their administrator.
	 */
	public function _dashboard_widget_config() {
		?>
		<div class="inside">
			<?php if(current_user_can('manage_options')): ?>
			<img class="happyfox-logo" src="<?php echo plugins_url('/images/happyfox-32.png', __FILE__);?>" alt="HappyFox"/>
			<p class="description">
				<?php echo "Your're almost ready to use HappyFox for WordPress! You just need to ";?>
				<a href="<?php echo admin_url('admin.php?page=happyfox_settings');?>">set up your HappyFox account details first</a>
			</p>
			<?php else:?>
			<img class="happyfox-logo" src="<?php echo plugins_url('/images/happyfox-32.png', __FILE__);?>" alt="HappyFox"/>
			<p class="description">Whoops! Looks like your administrator has not set this plugin up yet. Please contact them to set it up.</p>
			<?php endif;?>
		</div>
		<?php
	}
	
	/*
	 * Dashboard Widget Tickets
	 *
	 * This method displays the tickets widget in the
	 * administrator dashboard. This also contains a 
	 * placeholder for viewing single ticket info.
	 */
	public function _dashboard_widget_tickets() {
		?>
		<div class="inside">
		<?php
			if(!isset($this->settings['current_view'])) {
				$this->settings['current_view'] = "pending";
				update_option('happyfox_settings', $this->settings);
			}
			
			$current_user = wp_get_current_user();
			$tickets = $this->api->get_tickets_with_status($this->settings['current_view'], $this->settings['happyfox_tickets_category'], $current_user->user_email);
			
			if(is_wp_error($tickets)) {
				$error_msg = $tickets->get_error_message();
				
				if(strcasecmp($error_msg, "Authorization Required") === 0) {
					$error_msg = "The HappyFox server says: \"Authorization Required\". Please check your API url, API Key and API Auth Code values in your HappyFox settings.";
				}
				
				$this->_add_notice('happyfox_tickets_widget', $error_msg, 'alert');
				$tickets = array();
			}
			
			$this->_process_notices('happyfox_tickets_widget');
		?>
		</div>
		
		<?php if(!isset($error_msg)): ?>
		<div class="happyfox-tickets-widget">
			<!-- title and filters -->
			<p class="happyfox-widget-title">
				Viewing <span class="filter-type"><?php echo ucwords($this->settings['current_view']); ?></span> tickets
				<span class="happyfox-filters">
	
				<?php if($this->settings['current_view'] == "pending"):?>
					[<a onclick="return false;" class="happyfox-filter-pending happyfox-inactive" title="View all pending tickets">Pending</a> | 
					<a href="#" onclick="return false;" class="happyfox-filter-solved" title="View completed tickets">Completed</a>]
				<?php else:?>
					[<a href="#" onclick="return false;" class="happyfox-filter-pending" title="View all pending tickets">Pending</a> |
					<a onclick="return false;" class="happyfox-filter-solved happyfox-inactive" title="View completed tickets">Completed</a>]
				<?php endif;?>
				
					<span class="happyfox-spinner" style="display:none;"></span>
				
				</span>
			</p>
			<div class="happyfox-tickets-widget-main">
				<?php echo $this->_tickets_into_html($tickets, true);?>
			</div>
			
			<div class="happyfox-tickets-widget-single" style="display:none">
				<h3 class="happyfox-widget-title">Viewing ticket
					<span class="happyfox-ticket-title"></span>
					<span class="happyfox-heading-link">(<a class="happyfox-cancel" href="<?php echo admin_url();?>">Back</a>)</span>
				</h3>
				<div id="happyfox-ticket-details-placeholder"></div>
			</div>
			
			<?php if(isset($this->settings['account'])):?>
			<div class="happyfox-tickets-widget-footer">
				<a class="button" href="https://<?php echo $this->settings['account'];?>.happyfox.com/staff" target="_blank">My HelpDesk</a>
				<a href="http://www.happyfox.com/" title="Powered by HappyFox" target="_blank" class="powered_by_happyfox">HappyFox</a>
			</div>
			<?php endif;?>
		</div>
		<?php endif;?>
		<?php
	}
	
	/*
	 * View Ticket Details
	 *
	 * This method displays all important information
	 * about a clicked ticket on the HappyFox Tickets
	 * Admin Dashboard Widget.
	 */
	public function _view_ticket_details() {
		if(isset($_REQUEST['ticket_id']) && is_numeric($_REQUEST['ticket_id'])) {
			$ticket_id = $_REQUEST['ticket_id'];
			
			$ticket = $this->api->get_ticket_info($ticket_id);
			
			if(!is_wp_error($ticket)) {
				$ticket_info = array(
					'Subject:' => htmlspecialchars($ticket->subject),
					'Raised by:' => '<a target="_blank" href="' . $this->_user_url($ticket->user->id) . '">' . $ticket->user->name . '</a>',
					'Ticket Status:' => $ticket->status->name,
					'Created on:' => date(get_option('date_format') . ' \a\t ' . get_option('time_format'), strtotime($ticket->created_at)),
					'Last update:' => date(get_option('date_format') . ' \a\t ' . get_option('time_format'), strtotime($ticket->last_updated_at)),
					'Description:' => htmlspecialchars($ticket->first_message),
				);
				
				$ticket_actions = array(
					'View:' => '<a href="' . $this->_ticket_url($ticket_id) . '" target="_blank">View this ticket on HappyFox</a>',
				);
				
				$html = array();
				
				$html[] = '<table id="happyfox-ticket-info">';
				
				foreach($ticket_info as $label => $value) {
					$html[] = '<tr><td class="happyfox-label"><span class="description">' . $label . '</span></td>';
					$html[] = '<td>' . $value . '</td></tr>';
				}
				
				$html[] = '<tr><td colspan="2"><p class="happyfox-heading">Actions</p></td></tr>';
				
				foreach($ticket_actions as $label => $value) {
					$html[] = '<tr><td class="happyfox-label"><span class="description">' . $label . '</span></td>';
					$html[] = '<td>' . $value . '</td></tr>';
				}
				
				$html[] = '</table>';
				$html = implode("\n", $html);
				
				$response = array(
					'status' => 200,
					'html' => $html,
					'ticket' => $ticket
				);
			} else {
				$response = array(
					'status' => 404,
					'data' => $ticket->get_error_message()
				);
			}
		} else {
			$response = array(
				'status' => 404,
				'data' => 'The requested ticket was not found.'
			);
		}
		
		echo json_encode($response);
		die();
	}
	
	/*
	 * -----------------------------
	 * Settings page HTML and stuff
	 * -----------------------------
	 */
	
	public function _happyfox_settings_account() {
		?>
		<?php if(!$this->settings['account']):?>
		<strong>https://<input type="text" name="happyfox_settings[account]" value="<?php echo $this->settings['account'];?>"/>.happyfox.com</strong>
		<br/>
		<?php else:?>
		<?php
			echo '<span class="happyfox_account">https://<strong>' . $this->settings['account'] . '</strong>.happyfox.com</span>';
			echo '<span class="happyfox_new_account" style="display:none;">https://<input style="width:234px; font-size:.9em; display: none;"  type="text" name="happyfox_settings[account]" value="' . $this->settings['account'] . '"/>.happyfox.com</span>';
			echo ' <a href="#" class="happyfox_edit_account" onclick="return false;">Edit</a>';
		?>
		<?php endif;?>
		<?php
	}
	
	public function _happyfox_settings_credentials() {
		echo "Please enter your HappyFox subdomain, API Key and API Auth Code information so we know which HappyFox account to access."; 
	}
	
	public function _happyfox_settings_api_key() {
		?>
		<?php if(!$this->settings['api_key']):?>
		<input type="text" name="happyfox_settings[api_key]" value="<?php echo $this->settings['api_key'];?>"/>
		<br/>
		<?php else: ?>
		<?php
			echo '<span class="happyfox_api_key">' . $this->settings['api_key'] . '</span>';
			echo '<input class="happyfox_new_api_key" style="display:none; width:234px; font-size:.9em;" type="text" name="happyfox_settings[api_key]" value="' . $this->settings['api_key'] . '"/>';
			echo ' <a href="#" class="happyfox_edit_api_key" onclick="return false;">Edit</a>';
		?>
		<?php endif;?>
		<?php
	}
	
	public function _happyfox_settings_api_auth() {
		?>
		<?php if(!$this->settings['api_auth']):?>
		<input type="text" name="happyfox_settings[api_auth]" value="<?php echo $this->settings['api_auth'];?>"/>
		<br/>
		<?php else:?>
		<?php
			echo '<span class="happyfox_api_auth">' . $this->settings['api_auth'] . '</span>';
			echo '<input class="happyfox_new_api_auth" style="display:none; width:234px; font-size:.9em;" type="text" name="happyfox_settings[api_auth]" value="' . $this->settings['api_auth'] . '"/>';
			echo ' <a href="#" class="happyfox_edit_api_auth" onclick="return false;">Edit</a>';
		?>
		<?php endif;?>
		<?php
	}
	
	public function _happyfox_settings_tickets_category() {
		echo "Choose the HappyFox Category to which all your converted comments would be added to. The HappyFox Tickets widget will also display tickets from the selected category below.";
	}
	
	public function _happyfox_category_selection() {
		$admin_user = wp_get_current_user();
		//read all available categories
		$categories = $this->api->get_categories();
		$admin = $this->api->get_staff($admin_user->user_email);
		$admin_categories = $admin->categories; //array
		
		if(!is_wp_error($categories) && $admin) {
			echo '<select name="happyfox_settings[happyfox_tickets_category]" id="happyfox_category_selection">';
			
			if($this->settings['happyfox_tickets_category'] == "") {
				echo '<option value="" selected="selected">Choose a category...</option>';
			} else {
				echo '<option value="">Choose a category...</option>';
			}
			
			foreach($categories as $category) {
				if(in_array($category->id, $admin_categories)) {
					if($this->settings['happyfox_tickets_category'] == $category->id) {
						$selected = 'selected="selected"';
					} else {
						$selected = '';
					}
					
					echo '<option value="' . $category->id . '" ' . $selected . '>' . $category->name . '</option>';
				}
			}
			
			echo '</select>';
		} else {
			echo "Could not fetch your available categories. Either the HappyFox Server timed out, your HappyFox Account credentials above are wrong, or your <strong><em>WordPress email address</em></strong> and <strong><em>HappyFox Staff email address</em></strong> are <strong><em>not</em></strong> the same. Please, try again in a little while to change this setting.";
		}
	}
	
	public function _happyfox_settings_dashboard_widget() {
		echo "The Administrator Dashboard Widget can be configured according to a user's role. For each type of user, you can choose whether to display the HappyFox Tickets Widget on their Dashboard, or not.";
	}
	
	/*
	 * Widget and Contact Form visibility
	 */
	public function _happyfox_settings_dashboard_access($args) {
		if(!isset($args['role']) || !in_array($args['role'], array('administrator', 'editor', 'author', 'contributor', 'subscriber'))) {
			return;
		}
		
		$role = $args['role'];
		?>
		<label><input type="radio" value="tickets" name="happyfox_settings[happyfox_dashboard_<?php echo $role;?>]" <?php checked($this->settings['happyfox_dashboard_' . $role], "tickets");?> />Tickets widget</label>
		<label><input type="radio" value="none" name="happyfox_settings[happyfox_dashboard_<?php echo $role;?>]" <?php checked($this->settings['happyfox_dashboard_' . $role], "none");?> />None</label>
		<?php
	}
	
	public function _happyfox_settings_contact_form() {
		//echo "Your users can make use of the contact form to make support requests. The contact form functionality is in development and options will be available soon.";
	}
	
	public function _happyfox_settings_contact_title() {
	}
	
	public function _happyfox_settings_contact_summary() {
	}
	
	public function _happyfox_settings_contact_description() {
	}
	
	public function _happyfox_settings_contact_submit() {
	}
	
	public function _happyfox_settings_support_tab() {
		echo "Place a convenient Contact Support tab on all your pages that allows your visitors to contact you via a pop-up form.";
	}
	
	public function _happyfox_settings_support_tab_display() {
		?>
		<select name="happyfox_settings[happyfox_support_tab]" id="happyfox_support_tab">
			<option value="disabled" <?php selected($this->settings['happyfox_support_tab'] == "disabled"); ?>>Do not display the HappyFox Support Tab anywhere</option>
			<option value="enabled" <?php selected($this->settings['happyfox_support_tab'] == "enabled");?>>Display the HappyFox Support Tab on all posts and pages</option>
		</select>
		<?php
	}
	
	public function _happyfox_settings_support_tab_code() {
		$url = trailingslashit($this->happyfox_url) . 'staff/manage/integrations/embed-forms/';
		?>
		<span class="description float-left"><strong>Obtain your HappyFox Support Tab from <a href="<?php echo $url;?>" target="_blank"><?php echo $url;?></a> and paste the code below.</span>
		<textarea id="happyfox_support_tab_code" cols="45" rows="5" name="happyfox_settings[happyfox_support_tab_code]"><?php echo esc_textarea($this->settings['happyfox_support_tab_code']);?></textarea><br/>
		<?php
	}
	
	
	/*
	 * ----------------------------------------
	 * HappyFox for WordPress helper functions
	 * ----------------------------------------
	 */
	
	/*
	 * Get the current user's role
	 */
	private function _get_current_user_role() {
		$roles = array('administrator', 'editor', 'author', 'contributor', 'subscriber');
		
		foreach($roles as $role) {
			if(current_user_can($role)) {
				return $role;
			}
		}
	}
	
	/*
	 * Convert HappyFox tickets array into
	 * HTML for display in the widget.
	 */
	private function _tickets_into_html($tickets, $pagination = false) {
		$html = array();
		
		//the tickets
		$html[] = '<table class="happyfox-tickets-table">';
		
		if(count($tickets->data) > 0 && is_array($tickets->data)) {
			foreach($tickets->data as $ticket) {
				if(!strlen($ticket->subject)) {
					$ticket->subject = $this->_snippet($ticket->first_message, 10);
				}
				
				$url = $this->_ticket_url($ticket->id);
				$status = substr($ticket->status->name, 0, 8);
				
				if(strlen($ticket->status->name) > 8) {
					$status .= "...";
				}
				
				$color = $ticket->status->color;
				$subject = substr($ticket->subject, 0, 45);
				$ellipsis = substr($subject, -3);
				
				//add ellipsis only if it doesn't already exist
				if($ellipsis !== "...") {
					$subject .= "...";
				}
				
				$html[] = '<tr>';
				//ticket display id
				$html[] = '<td class="happyfox-ticket-id"><div class="happyfox-spinner" style="display:none;"></div><a data-id="' . $ticket->id . '" href="' . $url . '" onclick="return false;" target="_blank" class="happyfox-ticket-id-text">' . $ticket->display_id . '</a></td>';
				//status
				$html[] = '<td class="happyfox-ticket-status"><a href="' . $url . '" data-id="' . $ticket->id . '" target=_blank" onclick="return false;" style="background: #' . $color . '">' . $status . '</a></td>';
				//subject
				$html[] = '<td class="happyfox-ticket-subject"><a data-id="' . $ticket->id . '" href="' . $url . '" target="_blank" onclick="return false;">' . $subject . '</a></td>';
				//open ticket in new window link
				$html[] = '<td class="happyfox-view-ticket"><a href="' . $url . '" target="_blank" title="View this ticket in HappyFox">View in HappyFox</a></td>';
				$html[] = '</tr>';
			}
		} else {
			$html[] = '<tr><td><span class="happyfox-description">There are no tickets to display in this view</span></td></tr>';
		}
		
		$html[] = '</table>';
		
		if($tickets->page_info->page_count > 1 && $pagination === true) {
			$html[] = '<div class="clearfix">';
			$html[] = '<div class="happyfox-tickets-pagination clearfix">';
			$html[] = '<a href="#" onclick="return false;" class="first" data-action="first">&laquo;</a>';
			$html[] = '<a href="#" onclick="return false;" class="previous" data-action="previous">&lsaquo;</a>';
			$html[] = '<input type="text" readonly="readonly" data-max-page="' . $tickets->page_info->page_count . '" />';
			$html[] = '<a href="#" onclick="return false;" class="next" data-action="next">&rsaquo;</a>';
			$html[] = '<a href="#" onclick="return false;" class="last" data-action="last">&raquo;</a>';
			$html[] = '</div>';
			$html[] = '</div>';
		}
		
		return implode("\n", $html);
	}
	
	/*
	 * Return excerpts of passed strings
	 *
	 * By default, returns 45 word excerpts, unless
	 * otherwise specified.
	 */
	private function _snippet($string, $words = 45) {
		$string_holder = explode(' ', $string);
		$snippet = '';
		
		if(count($string_holder) < $words) {
			return $string;
		}
		
		for($i = 0; $i < $words; $i++) {
			$snippet .= $string_holder[$i] . ' ';
		}
		
		return $snippet . '...';
	}
	
	/*
	 * Convert to HappyFox Ticket URL
	 *
	 * Converts a provided $ticket_id into a HappyFox
	 * ticket URL.
	 */
	private function _ticket_url($ticket_id) {
		$id = (string) intval(substr($ticket_id, -8)); //fetch the ID part and get the intval
		return trailingslashit($this->happyfox_url) . 'staff/ticket/' . $id;
	}
	
	/*
	 * Convert to HappyFox User URL
	 * Converts a provided $user_id into a HappyFox  
	 * user URL.
	 */
	private function _user_url($user_id) {
		return trailingslashit($this->happyfox_url) . 'staff/contact/' . $user_id;
	}
	
	/*
	 * HappyFox Admin Notices
	 *
	 * This method displays all errors registered to 
	 * 'happyfox_settings' when there are validation 
	 * errors to be displayed.
	 */
	public function _happyfox_admin_notices() {
		settings_errors('happyfox_settings');
	}
	
	/*
	 * Add notices to specific contexts, like widget,
	 * contact form etc. to display different colored
	 * output based on the third argument.
	 */
	private function _add_notice($context, $message, $type) {
		if(isset($this->notices[$context . '_' . $type])) {
			$this->notices[$context . '_' . $type][] = $message;
		} else {
			$this->notices[$context . '_' . $type] = array($message);
		}
	}
	
	private function _process_notices($context) {
		echo '<div class="happyfox-notices-group">';
		foreach(array('alert', 'confirm', 'note') as $type) {
			if(isset($this->notices[$context . '_' . $type])) {
				$notices = $this->notices[$context . '_' . $type];
				
				foreach($notices as $notice) {
					$this->_notice($notice, $type);
				}
			}
		}
		echo '</div>';
	}
	
	private function _notice($message, $type = 'note') {
		?>
		<div class="happyfox-admin-notice happyfox-<?php echo $type;?>">
			<p><?php echo $message;?></p>
		</div>
		<?php
	}
 }
 
 add_action('init', create_function('', 'global $happyfox; $happyfox = new HappyFox();' ) );
?>