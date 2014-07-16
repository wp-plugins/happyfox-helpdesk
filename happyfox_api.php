<?php

	/*
	 * HappyFox API Class
	 * 
	 * Contains code related to HappyFox API calls,
	 * such as viewing tickets, posting replies,
	 * creating tickets etc.
	 */
	 
	 class HappyFox_API {
		private $api_url = '';
		private $api_key = '';
		private $api_auth = '';
		private $cache_timeout = 30;
		
		public function __construct($api_url, $api_key, $api_auth) {
			$this->api_url = $api_url . '/api/1.1/json';
			$this->api_key = $api_key;
			$this->api_auth = $api_auth;
		}
		
		/*
		 * Create HappyFox ticket
		 *
		 * Creates a new HappyFox ticket. Takes as parameters
		 * the subject, description, the name of the person
		 * raising the ticket and the email address of the
		 * person raising the ticket.
		 */
		public function create_ticket($category, $subject, $description, $raised_by, $raised_by_email) {
			$ticket = array(
				'subject' => $subject,
				'text' => $description,
				'name' => $raised_by,
				'email' => $raised_by_email,
				'category' => $category
			);
			
			$response = $this->_post('/tickets/', $ticket);
			
			if(!is_wp_error($response) && $response['response']['code'] == 200) {
				$result = json_decode($response['body']);
				return $result->display_id;
			} else {
				return false;
			}
		}
		
		/*
		 * Retrieve Staff details based on an email  
		 * address of a user.
		 */
		public function get_staff($email) {
			$response = $this->_get('/staff/' . $email . '/');
			
			if(!is_wp_error($response) && $response['response']['code'] == 200) {
				$result = json_decode($response['body']);
				return $result;
			} else {
				return false;
			}
		}
		
		/*
		 * Get categories
		 *
		 * This method fetches all available HappyFox
		 * categories from a HappyFox account.
		 */
		public function get_categories() {
			$response = $this->_get('/categories/');
			if(!is_wp_error($response) && $response['response']['code'] == 200) {
				$result = json_decode($response['body']);
				return $result;
			} else {
				return new WP_Error('happyfox_error', "Could not fetch your available categories. Either the HappyFox Server timed out, your HappyFox Account credentials above are wrong, or your WordPress email address and HappyFox Staff email address are not the same. Please, try again in a little while to change this setting.");
			}
		}
		
		/*
		 * Add a staff reply
		 *
		 * Add the reply of the admin to a converted 
		 * comment as an update to the ticket.
		 */
		public function add_staff_reply($staff_id, $ticket_id, $message) {
			$ticket_category = $this->_get_ticket_category($ticket_id);
			$staff_categories = $this->_get_staff_categories($staff_id);
			
			if($ticket_category && $staff_categories) {
				if(in_array($ticket_category, $staff_categories)) {
					//Staff belongs to this category, can add replies
					$data = array(
						'staff' => $staff_id,
						'text' => $message
					);
			
					$response = $this->_post('/ticket/' . $ticket_id . '/staff_update/', $data);
			
					if(!is_wp_error($response) && $response['response']['code'] == 200) {
						return true;
					} else {
						return false;
					}
				}
			}
		}
		
		/*
		 * ------------------------
		 * Widget related API Calls
		 * ------------------------
		 */
		 
		 /*
		  * Retrieve all open HappyFox tickets.
		  */
		public function get_tickets() {
			$transient_key = $this->_salt('tickets');
			
			if(($tickets = get_transient($transient_key)) == false) {
				$response = $this->_get('/tickets/?status+_pending');
				
				if(!is_wp_error($response) && $response['response']['code'] == 200) {
					$tickets = json_decode($response['body']);
					$tickets = $tickets->data;
					set_transient($transient_key, $tickets, $this->cache_timeout);
					return $tickets;
				} else {
					return new WP_Error('happyfox_error', 'There was a problem fetching tickets. Please, try again later.');
				}
			}
			
			return $tickets; //return from cache
		}
		
		/*
		 * Retrieve tickets with a specified status. 
		 */
		public function get_tickets_with_status($status, $category, $email = "") {
			$transient_key = $this->_salt($status . '_tickets');
			$ticket_status = '';
			
			switch($status) {
				case "solved":
					$user_statuses = $this->_get('/statuses/');
					
					if(!is_wp_error($user_statuses) && $user_statuses['response']['code'] == 200) {
						$user_statuses = json_decode($user_statuses['body']);
					} else {
						return new WP_Error('happyfox_error', "Tickets with status $status could not be fetched at this time. Please, try again later (or check your HappyFox Account settings).");
					}
					
					foreach($user_statuses as $user_status) {
						if($user_status->behavior === "completed") {
							$ticket_status .= $user_status->name . ",";
						}
					}
					
					$ticket_status = "q=status" . urlencode(':' . rtrim($ticket_status, ",")); //remove last comma from the querystring
					
					break;
				default:
					$ticket_status = "status=_pending";
					break;
			}
			
			if(($tickets = get_transient($transient_key)) == false) {
				$response = $this->_get('/tickets/?' . $ticket_status . '&category=' . $category);
				
				if(!is_wp_error($response) && $response['response']['code'] == 200) {
					$tickets = json_decode($response['body']);
					set_transient($transient_key, $tickets, $this->cache_timeout);
					return $tickets;
				} else {
					return new WP_Error('happyfox_error', "Tickets with status $status could not be fetched at this time. Please, try again later (or check your HappyFox Account settings).");
				}
			}
			
			return $tickets; //from cache
		}
		
		/*
		 * Get page
		 *
		 * Retrieves a given page of HappyFox tickets
		 */
		public function get_page($page, $view, $category) {
			$transient_key = $this->_salt('happyfox_tickets_page_' . $page);
			$status = '';
		
			if($view === "solved") {
				$user_statuses = $this->_get('/statuses/');
				
				if(!is_wp_error($user_statuses) && $user_statuses['response']['code'] == 200) {
					$user_statuses = json_decode($user_statuses['body']);
				} else {
					return new WP_Error('happyfox_error', "Tickets with status $status could not be fetched at this time. Please, try again later (or check your HappyFox Account settings).");
				}
				
				foreach($user_statuses as $user_status) {
					if($user_status->behavior === "completed") {
						$status .= $user_status->name . ",";
					}
				}
				
				$status = "q=status" . urlencode(":" . rtrim($status, ",")); //remove last comma from the querystring
			} else {
				$status = "status=_pending";
			}
			
			if(($tickets = get_transient($transient_key)) == false) {
				$response = $this->_get('/tickets/?' . $status . '&page=' . $page . '&category=' . $category);
				
				if(!is_wp_error($response) && $response['response']['code'] == 200) {
					$tickets = json_decode($response['body']);
					set_transient($transient_key, $tickets, $this->cache_timeout);
					return $tickets;
				} else {
					return new WP_Error('happyfox_error', "An error occurred while fetching page $page, please try again later.");
				}
			}
			
			return $tickets; //from cache
		}
		
		/*
		 * Get Ticket Info
		 *
		 * Retrieves all important information about 
		 * a given $ticket_id
		 */
		public function get_ticket_info($ticket_id) {
			$transient_key = $this->_salt('happyfox_ticket_' . $ticket_id);
			
			if(($ticket = get_transient($transient_key)) == false) {
				$response = $this->_get('/ticket/' . $ticket_id .'/');
				
				if(!is_wp_error($response) && $response['response']['code'] == 200) {
					$ticket = json_decode($response['body']);
					
					set_transient($transient_key, $ticket, $this->cache_timeout);
					return $ticket;
				} else {
					return new WP_Error('happyfox_error', "Ticket information could not be fetched at this time. Please, try again later.");
				}
			}
			
			return $ticket; //from cache
		}
		
		/*
		 * ----------------------
		 * User-related API calls
		 * ----------------------
		 */
		
		/*
		 * Is user a contact?
		 *
		 * This method checks if a contact exists in 
		 * a HappyFox account via the API. Returns
		 * the client_id if contact exists, else,
		 * returns false.
		 */
		public function user_is_a_contact($email) {
			$response = $this->_get('/user/' . $email . '/');
			
			if(!is_wp_error($response) && $response['response']['code'] == 200) {
				$result = json_decode($response['body']);
				return $result->id;
			} else if($response['response']['code'] == 404) {
				return false;
			}
		}
		
		/*
		 * Create new contact
		 *
		 * This method creates a new contact
		 * on a HappyFox account and returns the user
		 * ID of the newly created contact.
		 */
		public function create_contact($email, $name) {
			$data = array(
				'name' => $name,
				'email' => $email
			);
			
			$response = $this->_post('/users/', $data);
			
			if($response['response']['code'] == 200 && !is_wp_error($response)) {
				$result = json_decode($response['body']);
				return $result->id;
			} else {
				error_log("New HappyFox contact could not be created! :(");
				return false;
			}
		}
		
		/*
		 * Add a user reply
		 *
		 * This method adds a user reply to an
		 * existing HappyFox ticket.
		 */
		public function add_user_reply($client_id, $ticket_id, $message) {
			$data = array(
				'client' => $client_id,
				'text' => $message,
			);
			
			$response = $this->_post('/ticket/' . $ticket_id . '/user_reply/', $data);
			
			if(!is_wp_error($response) && $response['response']['code'] == 200) {
				return true;
			} else {
			}
		}
		
		/*
		 * Get ticket category
		 *
		 * This method queries the HappyFox API about
		 * a ticket with its id, and returns the
		 * category id it belongs to.
		 */
		private function _get_ticket_category($ticket_id) {
			$response = $this->_get('/ticket/' . $ticket_id . '/');
			
			if(!is_wp_error($response) && $response['response']['code'] == 200) {
				$ticket = json_decode($response['body']);
				return $ticket->category->id;
			} else {
				return false;
			}
		}
		
		/*
		 * Get staff categories
		 *
		 * This method fetches all the categories to 
		 * which a HappyFox Staff belongs to.
		 */
		private function _get_staff_categories($staff_id) {
			$response = $this->_get('/staff/' . $staff_id . '/');
			
			if(!is_wp_error($response) && $response['response']['code']) {
				$staff = json_decode($response['body']);
				return $staff->categories;
			} else {
				return false;
			}
		}
		
		/*
		 * HappyFox API GET method
		 *
		 * Makes HTTP GET API calls to HappyFox.
		 * Takes as parameter the desired resource.
		 */
		private function _get($resource) {
			$headers = array(
				'Authorization' => 'Basic ' . base64_encode($this->api_key . ':' . $this->api_auth),
				'Content-Type' => 'application/json',
			);
			
			return wp_remote_get($this->api_url . $resource, array('headers' => $headers, 'sslverify' => false));
		}
		
		/*
		 * HappyFox API POST method
		 *
		 * Makes HTTP POST API calls to HappyFox.
		 * Takes as parameters the resource to post to,
		 * and the POST data to be sent to the server.
		 */
		private function _post($resource, $data = null) {
			$data = json_encode($data);
			$headers = array(
				'Authorization' => 'Basic ' . base64_encode($this->api_key . ':' . $this->api_auth),
				'Content-Type' => 'application/json'
			);
			
			return wp_remote_post($this->api_url . $resource, array('redirection' => 0, 'headers' => $headers,
				'body' => $data, 'sslverify' => false));
		}
		
		/*
		 * Salt method
		 *
		 * This method is used to return a unique
		 * identifier for use in the WP Transient API
		 */
		private function _salt($append) {
			return 'hf-' . md5('happyfox' . $this->api_url . $this->api_key . $append);
		}
	}

?>
