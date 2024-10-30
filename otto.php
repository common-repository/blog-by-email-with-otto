<?php
/*

Plugin Name: Blog By Email With Otto
Author: VileGnosis
Version: 1.0.5
Description: WP Blogging by Email With Otto

*/

class Otto
{
    private $options;

	private $debug = false;
	private $debugNonce = false;

	private $version = "1.0.5";



    public function __construct()
    {
		// Only force display when debugging plugin
		if($this->debug == true)
		{
			ini_set("display_errors", 1);
			ini_set("display_startup_errors", 1);
			error_reporting(E_ALL);
		}
		
		add_action("admin_menu", Array($this, "otto_register_options_page"));
		
		add_filter("plugin_action_links_" . plugin_basename(__FILE__), Array($this, "otto_plugin_links"));
		
		add_action("admin_notices", Array($this, "otto_admin_notice"));
		
		register_activation_hook(__FILE__, Array($this, "plugin_activated"));
		
		add_action("admin_init", Array($this, "admin_init"));
    }
	
	public function admin_init()
	{
		add_action("wp_ajax_nopriv_otto_request", Array($this, "otto_request"));
	}
	
	public function plugin_activated()
	{
		set_transient("mp-admin-notice-activation", true, 5);
	}
	
	public function otto_admin_notice()
	{
		if(get_transient("mp-admin-notice-activation") !== false)
		{
			?>
				<div class="notice notice-success is-dismissible">
					<p>Thanks for activating Otto. Visit the <a href="<?php echo esc_url(get_admin_url(null, "options-general.php?page=otto")); ?>">Settings Page</a> and register your email to use Otto today!</p>
				</div>
			<?php
			
			delete_transient("mp-admin-notice-activation");
		}
	}
	
	public function otto_plugin_links($links)
	{
		$links[] = "<a href=\"" . esc_url(get_admin_url(null, "options-general.php?page=otto")) . "\">Settings</a>";
		$links[] = "<a href=\"https://blog1.com/\" target=\"_blank\">Visit Otto</a>";
		
		return $links;
	}

	// For debugging purposes
	private function dump($object, $title = "")
	{
		if($this->debug == true)
		{
			echo "<pre>";
			var_dump(Array("Title: " . $title, $object));
			echo "</pre>";
		}
	}

	function log($message)
	{		
		if($this->debug == true)
		{
			$fp = fopen("request-log.txt", "a+");
			
			fputs($fp, "[" . date("F j, Y, g:i a") . "] " . $message . "\r\n\r\n");
			
			fclose($fp);
		}
	}

	function clear_log()
	{
		if($this->debug == true)
		{
			$fp = fopen("request-log.txt", "w+");
			
			fputs($fp, "");
			
			fclose($fp);
		}
	}
	
	// Verify that the API request came from otto
	function verify_nonce($nonce)
	{
		if($this->debugNonce == true)
			return true;

		$result = false;
		
		$postData = Array(
			"data" => json_encode(
					Array(
					"action" => "verifyNonce",
					"nonce" => $nonce,
					"blogUrl" => get_site_url()
				)
			)
		);
		
		$data = $this->send_request("http://ottoApi.blog1.com/aspx/wordpress/wpverifynonce.aspx", $postData);
		
		$this->log("Verify nonce response: " . json_encode($data, JSON_PRETTY_PRINT));
		
		if(isset($data["success"]) == true && $data["success"] === true)
			$result = $data["success"];
		
		return $result;
	}

	// Get json settings
	function get_settings()
	{
		$settings = Array(
			"linkedEmails" => Array(),
			"codeWord" => ""
		);
		
		$content = get_option("otto-settings");
		
		if($content !== false)
			$settings = array_merge($settings, $content);
		
		return $settings;
	}
	
	// Save the settings
	function set_settings($settings)
	{
		update_option("otto-settings", $settings);
	}

	// Handle API requests
	function otto_request()
	{
		// Create the response object
		$response = Array();
		$response["success"] = false;
		$response["reason"] = "";
		$response["version"] = $this->version;
		
		// If receving 
		if(isset($_POST["data"]) == true)
		{
			$this->clear_log();
			$this->log("Received post data: " . json_encode($_POST, JSON_PRETTY_PRINT));
			
			$data = json_decode(stripslashes($_POST["data"]), true);
			$this->log("Data Object: " . json_encode($data, JSON_PRETTY_PRINT));
			
			if(isset($data["action"]) == true)
			{
				$action = $data["action"];
				
				if($this->verify_nonce($data["nonce"]) == true)
				{
					if($action == "getCategories")
					{
						$response["payload"] = get_categories(array("hide_empty" => false));
						$response["success"] = true;
					}
					else if($action == "setPostThumbnail")
					{
						if(gettype($data["thumbnailId"]) == "integer" && gettype($data["postId"]) == "integer")
						{
							$response["payload"] = set_post_thumbnail($data["postId"], $data["thumbnailId"]);
							$response["success"] = true;
						}
						else
							$response["reason"] = "Integer parameters required";
					}
					else if($action == "pluginConnected")
					{
						$settings = $this->get_settings();
						
						$settings["linkedEmails"] = Array();

						foreach($data["email"] as $email)
							array_push($settings["linkedEmails"], sanitize_email($email));

						$settings["codeWord"] = sanitize_text_field($data["codeWord"]);
						
						$this->set_settings($settings);
						
						$response["success"] = true;
					}
					else if($action == "addCategory")
					{
						$result = wp_insert_term(
							sanitize_text_field($data["name"]),
							"category",
							array(
							  "description"	=> sanitize_text_field($data["description"]),
							  "slug" 		=> sanitize_text_field($data["slug"])
							)
						);
	
						if(is_wp_error($result) == false)
						{
							$response["success"] = true;
							$response["payload"] = $result["term_id"];
						}
						else
						{
							$response["reason"] = $result->get_error_message();
						}
					}
					else if($action == "getTags")
					{
						$response["payload"] = get_tags(array("hide_empty" => false));
						$response["success"] = true;
					}
					else if($action == "addTag")
					{
						$result = wp_insert_term(
							sanitize_text_field($data["name"]),
							"post_tag",
							array(
							  "description"	=> sanitize_text_field($data["description"]),
							  "slug" 		=> sanitize_text_field($data["slug"])
							)
						);
	
						if(is_wp_error($result) == false)
						{
							$response["success"] = true;
							$response["payload"] = $result["term_id"];
						}
						else
						{
							$response["reason"] = $result->get_error_message();
						}
					}
					else if($action == "addPost")
					{
						$types = Array(
							"ID" => "integer",
							"post_author" => "integer",
							"post_date" => "string",
							"post_date_gmt" => "string",
							"post_content" => "string",
							"post_content_filtered" => "string",
							"post_title" => "string",
							"post_excerpt" => "string",
							"post_status" => "string",
							"post_type" => "string",
							"comment_status" => "string",
							"ping_status" => "string",
							"post_password" => "string",
							"post_name" => "string",
							"to_ping" => "string",
							"pinged" => "string",
							"post_modified" => "string",
							"post_modified_gmt" => "string",
							"post_parent" => "integer",
							"menu_order" => "integer",
							"post_mime_type" => "string",
							"guid" => "string",
							"post_category" => "array",
							"tags_input" => "array"
						);

						$valid = true;

						foreach($data["params"] as $key => $value)
						{
							if(isset($types[$key]) == true)
							{
								if(gettype($value) != $types[$key])
								{
									$valid = false;
									
									$response["reason"] = "Key " . $key . " does not match required type " . $types[$key];
									break;
								}
								else if($key == "post_category" || $key == "tags_input")
								{
									foreach($data["params"][$key] as $item)
									{
										if(gettype($item) != "integer")
										{
											$response["reason"] = "param for key " . $key . " is not an int";
											$valid = false;
											break;
										}
									}
								}
								else if(gettype($value) == "string")
								{
									if($key != "post_content")
									{
										$data["params"][$key] = sanitize_text_field($value);
									}
								}
							}
							else
							{
								$valid = false;
								$response["reason"] = "Key " . $key . " is not valid";
								break;
							}
						}

						if($valid == true)
						{
							$result = wp_insert_post($data["params"]);
	
							if(is_wp_error($result) == false)
							{
								if($result > 0)
									$result = get_post($result, "ARRAY_A", "edit");
								
								$response["success"] = true;
								$response["payload"] = $result;
								$response["payload"]["permalink"] = get_the_permalink($result["ID"]);
							}
							else
							{
								$response["reason"] = $result->get_error_message();
							}
						}						
					}
					else if($action == "addMedia")
					{
						$parent_post_id = 0;
						$filename = sanitize_file_name($data["fileName"]);
						
						if(preg_match('/^[a-zA-Z0-9\/\r\n+]*={0,2}$/', $data["bits"]) !== false)
						{
							$upload_file = wp_upload_bits($filename, null, base64_decode($data["bits"]));
							$data["bits"] = "replaced_for_speed";
							
							if($upload_file["error"] == false)
							{
								$wp_filetype = wp_check_filetype($filename, null);
								
								$caption = "";
								
								if(isset($data["caption"]) == true)
									$caption = sanitize_text_field($data["caption"]);
								
								$attachment = array(
									"post_mime_type" => sanitize_text_field($wp_filetype["type"]),
									"post_parent" => $parent_post_id,
									"post_title" => preg_replace('/\.[^.]+$/', "", $filename),
									"post_excerpt" => $caption, 
									"post_content" => "",
									"post_status" => "inherit"
								);
								
								$attachment_id = wp_insert_attachment( $attachment, $upload_file["file"], $parent_post_id );
								
								if (is_wp_error($attachment_id) == false)
								{
									require_once(ABSPATH . "wp-admin" . "/includes/image.php");
									$attachment_data = wp_generate_attachment_metadata( $attachment_id, $upload_file["file"] );
									wp_update_attachment_metadata( $attachment_id,  $attachment_data );
									
									$response["success"] = true;
									$response["payload"] = wp_get_attachment_metadata($attachment_id);
									$response["payload"]["media_id"] = $attachment_id;
									$response["payload"]["full_attachment_url"] = wp_get_attachment_url($attachment_id);
								}
								else
								{
									$response["reason"] = $attachment_id->get_error_message();
								}
							}
							else
							{
								$response["reason"] = $upload_file["error"];
							}
						}
					}
					else
					{
						$response["reason"] = "No valid action specified.";
					}
					
				}
				else
				{
					$response["reason"] = "Unable to verify nonce.";
				}
			}
			else
			{
				$response["reason"] = "No action specified or invalid json format.";
			}
			
			$this->dump(json_encode($response, JSON_PRETTY_PRINT), "The response:");
			$this->log("Response: " . json_encode($response, JSON_PRETTY_PRINT));
		}
		else
			$response["reason"] = "Missing data parameter";
		
		// Echo the response and kill the script
		echo json_encode($response);
		
		exit(0);
	}
	
	function otto_register_options_page()
	{
		add_options_page("Otto", "Otto", "manage_options", "otto", Array($this, "otto_options_page"));
	}
	
	function send_request($url, $postData)
	{
		$page = wp_remote_post($url, Array(
			"body" => $postData,
		));
		
		return json_decode($page["body"], true);
	}
	
	function otto_options_page()
	{
		if( isset($_POST["otto_nonce_field"]) == true &&
			isset($_POST["otto-register"]) == true &&
			wp_verify_nonce($_POST["otto_nonce_field"], "otto_register_email")  == true)
		{
			$email = $_POST["email"];
			$error = "";
			
			if(filter_var($email, FILTER_VALIDATE_EMAIL) == true)
			{
				$email = sanitize_email($email);
				
				$apiUrl = admin_url("admin-ajax.php");
				
				$postData = Array(
					"data" => json_encode(
							Array(
							"action" => "registerEmail",
							"email" => $email,
							"blogUrl" => get_site_url(),
							"apiUrl" => $apiUrl
						)
					)
				);
				
				$data = $this->send_request("http://ottoApi.blog1.com/aspx/wordpress/wpsetup.aspx", $postData);
				
				if(isset($data["success"]) == true)
				{
					if($data["success"] == true)
					{
						add_settings_error("otto-group", "error-message", htmlentities($data["message"]), "success");
					}
					else
					{
						add_settings_error("otto-group", "error-message",  htmlentities($data["reason"]), "error");
					}
				}
				else
				{
					add_settings_error("otto-group", "error-message", "Unable to register your email. Please visit https://www.blog1.com for more information.", "error");
				}
			}
			else
			{
				add_settings_error("otto-group", "error-message", "Please enter a valid email.", "error");
			}
		}
		
		$settings = $this->get_settings();
		
		?>
			<?php
				settings_errors("otto-group");
			?>
			<div class="wrap">
				<form action="" method="POST">
					<h1>Greetings! This is Otto.</h1>
					
					<img src="<?php echo plugins_url("img/otto.png", __FILE__); ?>" />
					
					<p>
						Otto will assist you in posting blog entries via email.
						<br />
						Otto accepts emails from virtually any device and has helped post blog entries for everyone from hikers on the Pacific Crest Trail (posting via handheld trackers) to boaters crossing the Pacific posting via a satellite phone.
						<br />
						<br />
						To make your introduction to Otto enter your email address in the box below.
						<br />
						You will be sent an email confirming that you'd like Otto to post blog entries to your website.
						<br />
						Once you click the confirmation link Otto will email you instructions on how to start communicating. 
						<br />
						Visit our <a href="http://www.blog1.com/documentation">Documentation Page</a> for more information on how to use Otto!
					</p>
					
					<h2>Register Email</h2>
					<p>
						Enter the email you want to create your posts from.
					</p>
					<table class="form-table">
						<tbody>
							<tr>
								<th scope="row">
									<label for="email">Email</label>
								</th>
								<td>
									<input name="email" type="text" class="regular-text" placeholder="Enter your email..." />
								</td>
							</tr>
						</tbody>
					</table>
					<p class="submit">
						<input name="otto-register" type="submit" value="Submit" class="button button-primary" />
					</p>
					<?php wp_nonce_field("otto_register_email", "otto_nonce_field"); ?>
				</form>
				
				<h2>Linked Emails</h2>
				<p>
					This is a list of all emails verified for this blog
				</p>
				<table class="form-table">
					<tbody>
						<tr>
							<th scope="row">
								<label for="email">Emails</label>
							</th>
							<td>
								<?php
									
									if(count($settings["linkedEmails"]) > 0)
									{
										foreach($settings["linkedEmails"] as $email)
										{
											?>
												<p><?php echo htmlentities($email); ?></p>
											<?php
										}
									}
									else
									{
										?>
											<p>None</p>
										<?php
									}
								
								?>
							</td>
						</tr>
					</tbody>
				</table>
				
				
				<h2>Code Word<h2>
				<p>
					This code will be required when posting to your website. Visit <a href="http://www.blog1.com/codeword">http://www.blog1.com/codeword</a> for more information
				</p>
				<input type="text" readonly value="<?php echo htmlentities($settings["codeWord"]); ?>" />
				
				<br />
				<p style="font-size: 9px; margin-top: 40px; ">Plugin version v<?php echo $this->version; ?></p>
			</div>
		<?php
	}
}

$otto = new Otto();

?>