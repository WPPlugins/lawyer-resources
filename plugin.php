<?php
/*
Plugin Name: Lawyer Plugin
Plugin URI: https://www.lawyerplugin.com
Description: Give your visitors access to thousands of legal resources, improve the SEO performance of your website by linking to high-quality information, and get your law firm listed in the world's first peer-to-peer lawyer directory with precision geo-competitor filtering.
Author: Lawyer Plugin
Version: 1.0

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA 02111-1307 USA
*/

define('LP_VERSION', '1.0.1');
define('LP_PLUGIN_URL', admin_url('options-general.php?page=lawyer-resources'));
define('LP_PLUGIN_DIR', plugin_dir_url(__FILE__));
define('LP_URL', "https://www.lawyerplugin.com/");
define('LP_API', "http://www.lawyerplugin.com/wp-content/plugins/lawyer-plugin-server/");
define('LP_CATSLUG', "resources-category");

class Lawyer_Plugin {

	function Lawyer_Plugin() {
		$this->__construct();
	}
	
	function __construct() {
		$this->client = get_option('wplp-client');
		$this->build = get_option('wplp-build');
		$this->lastsync = get_option('wplp-lastsync');
		
		register_activation_hook(__FILE__, array($this,'activate'));
		register_deactivation_hook(__FILE__, array($this,'deactivate'));
		
		add_action('init', array($this, 'custom_post_setup'));
		add_action('plugins_loaded', array($this, 'plugins_loaded'));
		add_action('admin_init', array($this, 'admin_init'));
		add_action('admin_notices', array($this, 'admin_notices'));
		add_action('admin_menu', array($this, 'admin_menu'));
		add_action('wp_enqueue_scripts', array($this, 'display_style'));
		add_action('wplp-sync', array($this, 'sync'));
		
		add_filter("plugin_action_links_$plugin_basename", array($this, 'plugin_action_links'));
		add_filter('wp_title', array($this, 'wp_title'), 10, 3 );
		
		add_shortcode('resources', array($this, 'shortcode'));
		
		if($this->client) {
			if(!wp_next_scheduled('wplp-sync')) {
				wp_schedule_single_event(time() + 120, 'wplp-sync');
			}
			$this->nextsync = wp_next_scheduled('wplp-sync');
		}
	}
	
	function api($api_action, $api_args = array()) {
		if($this->client) {
			$api_args['user_login'] = $this->client['user_login'];
			$api_args['user_pass'] = $this->client['user_pass'];
		}
		
		$api_args['action'] = $api_action;
		
		$api_response_raw = wp_remote_post(LP_API, array(
			'body' => $api_args,
			'timeout' => 30,
			'sslverify' => false,
			'user-agent' => $this->api_user_agent()
		));

		if(is_wp_error($api_response_raw) || ($api_response_raw['response']['code'] != 200)) {
			return new WP_Error('plugins_api_failed', __('An Unexpected HTTP Error occurred during the API request.</p> <p><a href="?" onclick="document.location.reload(); return false;">Try again</a>'), $api_response_raw['error']);
		}

		return json_decode(wp_remote_retrieve_body($api_response_raw), true);
	}
	
	function api_user_agent() {
		global $wp_version;
		return 'WordPress/' . $wp_version . '; LawyerPlugin/' . LP_VERSION . '; ' . get_bloginfo('url');
	}
	
	function activate() {
		global $wp_rewrite;
		$wp_rewrite->add_endpoint(LP_CATSLUG, EP_ALL);
		$wp_rewrite->flush_rules();
		$this->flush_settings();
		$this->flush_resources();
	}
	
	function deactivate() {
		global $wp_rewrite;
		$wp_rewrite->flush_rules();
		$this->flush_settings();
		$this->flush_resources();
	}

	function custom_post_setup() {
	
		add_rewrite_endpoint(LP_CATSLUG, EP_ALL);
		
		$resource_labels = array(
			'name' => _x('Resources', 'post type general name'),
			'singular_name' => _x('Resource', 'post type singular name'),
			'add_new' => _x('Add New Resource', 'wplp-resource'),
			'add_new_item' => __('Add New Resource'),
			'edit_item' => __('Edit Resource'),
			'new_item' => __('New Resource'),
			'view_item' => __('View Resources'),
			'search_items' => __('Search Resources'),
			'not_found' => __('No Resources Found'),
			'not_found_in_trash' => __('No Resources Found In Trash'), 
			'parent_item_colon' => ''
		);
		register_post_type('wplp-resource', array(
			'labels' => $resource_labels,
			'show_ui' => false,
			'capability_type' => 'post',
			'supports' => array('title','editor','custom-fields')
		));
		
		$category_labels = array(
			'name' => _x('Categories', 'taxonomy general name'),
			'singular_name' => _x('Category', 'taxonomy singular name'),
			'search_items' =>  __('Search Categories'),
			'all_items' => __('All Categories'),
			'parent_item' => __('Parent Category'),
			'parent_item_colon' => __('Parent Category:'),
			'edit_item' => __('Edit Category'), 
			'update_item' => __('Update Category'),
			'add_new_item' => __('Add New Category'),
			'new_item_name' => __('New Category Name'),
			'menu_name' => __('Categories'),
		); 	
		register_taxonomy('resource-category',array('wplp-resource'), array(
			'hierarchical' => true,
			'labels' => $category_labels
		));
		
		$practice_areas_labels = array(
			'name' => _x('Practice Areas', 'taxonomy general name'),
			'singular_name' => _x('Practice Area', 'taxonomy singular name'),
			'search_items' =>  __('Search Practice Areas'),
			'popular_items' => __('Popular Practice Areas'),
			'all_items' => __('All Practice Areas'),
			'parent_item' => null,
			'parent_item_colon' => null,
			'edit_item' => __('Edit Practice Area'), 
			'update_item' => __('Update Practice Area'),
			'add_new_item' => __('Add New Practice Area'),
			'new_item_name' => __('New Practice Area Name'),
			'separate_items_with_commas' => __('Separate practice areas with commas'),
			'add_or_remove_items' => __('Add or remove practice areas'),
			'choose_from_most_used' => __('Choose from the most used practice areas'),
			'menu_name' => __('Practice Areas'),
		); 
		register_taxonomy('resource-practice-area','wplp-resource', array(
			'hierarchical' => false,
			'labels' => $practice_areas_labels
		));
	}
	
	function plugins_loaded() {
		$plugin_dir = basename(dirname(__FILE__));
		load_plugin_textdomain('lawyer-resources', false, $plugin_dir);
	}
	
	function admin_init() {
		if($this->client) {
			register_setting('wplp-form-resource', 'wplp-resource', array($this, 'api_resource_update'));
		} else {
			register_setting('wplp-form-login', 'wplp-login', array($this, 'api_client_login'));
			register_setting('wplp-form-register', 'wplp-register', array($this, 'api_client_register'));
		}
	}
	
	function admin_notices() {
		$page = isset($_GET['page']) ? $_GET['page'] : null;
		if(!is_array($this->client) && $page != 'lawyer-resources') {
			echo '<div class="updated fade"><p>' . sprintf(__('Lawyer Plugin is not running, %1$sactivate the plugin%2$s now for the latest legal resoruces.', 'lawyer-resources'), '<a href="' . LP_PLUGIN_URL . '">', '</a>') . '</p></div>';
		}
	}
	
	function admin_menu() {
		if($this->client) {
			add_options_page('Lawyer Plugin', 'Lawyer Plugin', 'manage_options', 'lawyer-resources', array($this, 'admin_page_registered'));
		} else {
			add_options_page('Lawyer Plugin', 'Lawyer Plugin', 'manage_options', 'lawyer-resources', array($this, 'admin_page_unregistered'));
		}
	}

	function api_client_login($login_data) {
		if(isset($login_data) && empty($login_data)) {
			return false;
		}
		$client_login = $this->api("login", $login_data);
		$client_login_msgtype = ($client_login['status'] == 'error') ? 'error' : 'updated';
		add_settings_error(
			'wplp-api',
			'api',
			$client_login['message'],
			$client_login_msgtype
		);
		if(isset($client_login['response'])) {
			update_option('wplp-client', $client_login['response']);
		}
	}
	
	function api_client_register($register_data) {
		if(isset($register_data) && empty($register_data)) {
			return false;
		}
		$client_register = $this->api("register", $register_data);
		$client_register_msgtype = ($client_register['status'] == 'error') ? 'error' : 'updated';
		add_settings_error(
			'wplp-api',
			'api',
			$client_register['message'],
			$client_register_msgtype
		);
		if(isset($client_register['response'])) {
			update_option('wplp-client', $client_register['response']);
		}
	}

	function admin_page_unregistered() {
		?>
		<div class="wrap"><br />
		<h2><img src="<?php echo LP_PLUGIN_DIR;?>logo-full.png" alt="Lawyer Plugin" /></h2>

		<h2 id="lawyerplugin-login" class="nav-tab-wrapper"><a href="#layerplugin-login" class="nav-tab nav-tab-active"><?php _e('Activate Plugin');?></a></h2>		
		<h4><?php _e('Sign in with your LawyerPlugin.com account to easily manage your profile.');?></h4>	

		<form method="post" action="options.php">
			<?php settings_fields('wplp-form-login'); ?>
			<table class="form-table">
				<tr valign="top">
					<th scope="row"><label for="wplp-login[email]"><?php _e('LawyerPlugin.com Email');?></label></th>
					<td><input type="text" name="wplp-login[email]" id="wplp-login[email]" class="regular-text" /></td>
				</tr>
				<tr valign="top">
					<th scope="row"><label for="wplp-login[password]"><?php _e('LawyerPlugin.com Password');?></label></th>
					<td><input type="password" name="wplp-login[password]" id="wplp-login[password]" /></td>
				</tr>
			</table>
			<?php submit_button(__('Authenticate', 'lawyer-resources')); ?>
		</form>
		
		<h2 id="layerplugin-register" class="nav-tab-wrapper"><a href="#layerplugin-register" class="nav-tab nav-tab-active"><?php _e('Register an Account', 'lawyer-resources');?></a></h2>
		<h4><?php _e("Don't have an account with Lawyer Plugin? Spare a few minutes to register below to get relevant legal resources.");?></h4>

		<form method="post" action="options.php">
			<?php settings_fields('wplp-form-register'); ?>
			<table class="form-table">
				<tr valign="top">
					<th scope="row"><label for="wplp-register[first]"><?php _e('Full Name');?></label></th>
					<td>
						<input type="text" name="wplp-register[first]" id="wplp-register[first]" />
						<input type="text" name="wplp-register[last]" id="wplp-register[last]" />
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><label for="wplp-register[email]"><?php _e('Your Email');?></label></th>
					<td><input type="text" name="wplp-register[email]" id="wplp-register[email]" class="regular-text" value="<?php bloginfo('admin_email');?>" /></td>
				</tr>
				<tr valign="top">
					<th scope="row"><label for="wplp-register[password]"><?php _e('Password');?></label></th>
					<td><input type="password" name="wplp-register[password]" id="wplp-register[password]" /></td>
				</tr>
				<tr valign="top">
					<th scope="row">&nbsp;</th>
					<td><?php echo sprintf(__('Use of this plugin means you have read and agree to the %1$sterms%2$s.', 'lawyer-resources'), '<a href="' . LP_URL . 'tos/" target="_blank">', '</a>');?></label></td>
				</tr>
			</table>
			<?php submit_button(__("Register")); ?> <span class="description"><?php _e('Your will receive a verification email with instructions to complete your registration.');?></span>
		</form>
			
		</div>
	<?php }
	
	function api_resource_get() {
		$resource_get = $this->api("get_resource");
		if(!isset($resource_get['response'])) {
			add_settings_error(
				'wplp-api',
				'api',
				$resource_get['message'],
				'error'
			);
			return $resource_get;
		}
		return $resource_get['response'];
	}
	
	function api_resource_update($update_data) {
		if(isset($update_data) && empty($update_data)) {
			return false;
		}
		$resource_update = $this->api("update_resource", $update_data);
		$resource_update_msgtype = ($resource_update['status'] == 'error') ? 'error' : 'updated';
		add_settings_error(
			'wplp-api',
			'api',
			$resource_update['message'],
			$resource_update_msgtype
		);
	}

	function admin_page_registered() {			
		$resource = $this->api_resource_get();
		$lastsync = ($this->lastsync > 0) ? human_time_diff($this->lastsync, time()) . __(' ago') : __('never');
		$nextsync = ($this->nextsync > 0) ? human_time_diff($this->nextsync, time()) . __(' from now') : __('not scheduled');
		?>
		
		<div class="wrap"><br />
		<h2><img src="<?php echo LP_PLUGIN_DIR;?>logo-full.png" alt="Lawyer Plugin" /></h2>
		
		<h3><?php echo sprintf(__('Signed in as %1$s (%2$s)', 'lawyer-resources'), $this->client['display_name'], $this->client['user_email']);?> | <?php echo sprintf(__('Last update was %1$s, next check is %2$s.', 'lawyer-resources'), $lastsync, $nextsync);?></h3>

		<hr />
		
		<h3><?php _e('How to use Lawyer Plugin.', 'lawyer-resources');?></h3>
		<ol>
			<li><?php _e('Fill out your profile below.');?></li>
			<li><?php _e('Create a new page or edit an existing one.');?></li>
			<li>Use the shortcode <code>[resources]</code> anywhere in the content.</li>
			<li><?php _e("That's it! You resources will be automatically be synced in the background.");?></li>
			<li><?php echo sprintf(__('Suggested: Read the %1$sLawyer Plugin documentation%2$s.', 'lawyer-resources'), '<a href="' . LP_URL . 'docs/" target="_blank">', '</a>');?></h3>
		</ol>
		
		<hr />

		<form method="post" action="options.php">
			<?php settings_fields('wplp-form-resource');?>

			<?php
			switch($resource['status']) {
				case"publish":
					$post_status = __('Your profile is approved and will be shown to users across our network.', 'lawyer-resources');
				break;
				case"draft":
					$post_status = __('Your profile is pending moderation and will not be shown to users across our network.', 'lawyer-resources');
				break;
				case"trash":
					$post_status = __('Your profile has been removed for from our network. You can submit changes to try to have it relisted.', 'lawyer-resources');
				break;
				default:
					$post_status = __("Your profile does not exist, get started by filling out your firm's info below.", 'lawyer-resources');
			}
			echo "<h4>{$post_status}</h4>";
			?>
			
			<table class="form-table">
				<tr valign="top">
					<th scope="row">
						<label for="wplp-resource[title]"><strong><?php _e('Practice Name');?></strong></label>
					</th>
					<td><input type="text" name="wplp-resource[title]" id="wplp-resource[title]" class="regular-text" value="<?php echo esc_html($resource['title']);?>" /></td>
				</tr>
				<tr valign="top">
					<th scope="row"><label for="wplp-resource[address][street]"><strong><?php _e('Firm Address');?></strong></label></th>
					<td>
						<input type="text" name="wplp-resource[address][street]" id="wplp-resource[address][street]" class="regular-text" value="<?php if(isset($resource['address']['street'])) { echo esc_html($resource['address']['street']);} ?>" /> <br/>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row">
						<label for="wplp-resource[address][city]"><strong><?php _e('City');?></strong></label> / <label for="wplp-resource[address][state]"><strong><?php _e('State');?></strong></label> / <label for="wplp-resource[address][zip]"><strong><?php _e('ZIP Code');?></strong></label>
					</th>
					<td>
						<input type="text" name="wplp-resource[address][city]" id="wplp-resource[city]" value="<?php if(isset($resource['address']['city'])) { echo esc_html($resource['address']['city']);} ?>">
						<select name="wplp-resource[address][state]" id="wplp-resource[address][state]">
							<option value=""></option>
							<option value="AL" <?php selected($resource['address']['state'], 'AL');?>>Alabama</option>
							<option value="AK" <?php selected($resource['address']['state'], 'AK');?>>Alaska</option>
							<option value="AZ" <?php selected($resource['address']['state'], 'AZ');?>>Arizona</option>
							<option value="AR" <?php selected($resource['address']['state'], 'AR');?>>Arkansas</option>
							<option value="CA" <?php selected($resource['address']['state'], 'CA');?>>California</option>
							<option value="CO" <?php selected($resource['address']['state'], 'CO');?>>Colorado</option>
							<option value="CT" <?php selected($resource['address']['state'], 'CT');?>>Connecticut</option>
							<option value="DE" <?php selected($resource['address']['state'], 'DE');?>>Delaware</option>
							<option value="DC" <?php selected($resource['address']['state'], 'DC');?>>District of Columbia</option>
							<option value="FL" <?php selected($resource['address']['state'], 'FL');?>>Florida</option>
							<option value="GA" <?php selected($resource['address']['state'], 'GA');?>>Georgia</option>
							<option value="HI" <?php selected($resource['address']['state'], 'HI');?>>Hawaii</option>
							<option value="ID" <?php selected($resource['address']['state'], 'ID');?>>Idaho</option>
							<option value="IL" <?php selected($resource['address']['state'], 'IL');?>>Illinois</option>
							<option value="IN" <?php selected($resource['address']['state'], 'IN');?>>Indiana</option>
							<option value="IA" <?php selected($resource['address']['state'], 'IA');?>>Iowa</option>
							<option value="KS" <?php selected($resource['address']['state'], 'KS');?>>Kansas</option>
							<option value="KY" <?php selected($resource['address']['state'], 'KY');?>>Kentucky</option>
							<option value="LA" <?php selected($resource['address']['state'], 'LA');?>>Louisiana</option>
							<option value="ME" <?php selected($resource['address']['state'], 'ME');?>>Maine</option>
							<option value="MD" <?php selected($resource['address']['state'], 'MD');?>>Maryland</option>
							<option value="MA" <?php selected($resource['address']['state'], 'MA');?>>Massachusetts</option>
							<option value="MI" <?php selected($resource['address']['state'], 'MI');?>>Michigan</option>
							<option value="MN" <?php selected($resource['address']['state'], 'MN');?>>Minnesota</option>
							<option value="MS" <?php selected($resource['address']['state'], 'MS');?>>Mississippi</option>
							<option value="MO" <?php selected($resource['address']['state'], 'MO');?>>Missouri</option>
							<option value="MT" <?php selected($resource['address']['state'], 'MT');?>>Montana</option>
							<option value="NE" <?php selected($resource['address']['state'], 'NE');?>>Nebraska</option>
							<option value="NV" <?php selected($resource['address']['state'], 'NV');?>>Nevada</option>
							<option value="NH" <?php selected($resource['address']['state'], 'NH');?>>New Hampshire</option>
							<option value="NJ" <?php selected($resource['address']['state'], 'NJ');?>>New Jersey</option>
							<option value="NM" <?php selected($resource['address']['state'], 'NM');?>>New Mexico</option>
							<option value="NY" <?php selected($resource['address']['state'], 'NY');?>>New York</option>
							<option value="NC" <?php selected($resource['address']['state'], 'NC');?>>North Carolina</option>
							<option value="ND" <?php selected($resource['address']['state'], 'ND');?>>North Dakota</option>
							<option value="OH" <?php selected($resource['address']['state'], 'OH');?>>Ohio</option>
							<option value="OK" <?php selected($resource['address']['state'], 'OK');?>>Oklahoma</option>
							<option value="OR" <?php selected($resource['address']['state'], 'OR');?>>Oregon</option>
							<option value="PA" <?php selected($resource['address']['state'], 'PA');?>>Pennsylvania</option>
							<option value="RI" <?php selected($resource['address']['state'], 'RI');?>>Rhode Island</option>
							<option value="SC" <?php selected($resource['address']['state'], 'SC');?>>South Carolina</option>
							<option value="SD" <?php selected($resource['address']['state'], 'SD');?>>South Dakota</option>
							<option value="TN" <?php selected($resource['address']['state'], 'TN');?>>Tennessee</option>
							<option value="TX" <?php selected($resource['address']['state'], 'TX');?>>Texas</option>
							<option value="UT" <?php selected($resource['address']['state'], 'UT');?>>Utah</option>
							<option value="VT" <?php selected($resource['address']['state'], 'VT');?>>Vermont</option>
							<option value="VA" <?php selected($resource['address']['state'], 'VA');?>>Virginia</option>
							<option value="WA" <?php selected($resource['address']['state'], 'WA');?>>Washington</option>
							<option value="WV" <?php selected($resource['address']['state'], 'WV');?>>West Virginia</option>
							<option value="WI" <?php selected($resource['address']['state'], 'WI');?>>Wisconsin</option>
							<option value="WY" <?php selected($resource['address']['state'], 'WY');?>>Wyoming</option>
						</select>
						<input type="text" name="wplp-resource[address][zip]" id="wplp-resource[address][zip]" value="<?php if(isset($resource['address']['zip'])) { echo esc_html($resource['address']['zip']);} ?>">
					</td>
				</tr>
				<tr valign="top">
					<th scope="row">
						<label for="wplp-resource[practice-areas][0]"><strong><?php _e('Select your practice areas');?></label>
					</th>
					<td>
						<select name="wplp-resource[practice-areas][0]" id="wplp-resource[practice-areas][0]">
							<option value=""></option>
							<option value="bankruptcy" <?php selected($resource['practice-areas'][0]['slug'], 'bankruptcy');?>>Bankruptcy</option>
							<option value="business-law" <?php selected($resource['practice-areas'][0]['slug'], 'business-law');?>>Business Law</option>
							<option value="criminal-defense" <?php selected($resource['practice-areas'][0]['slug'], 'criminal-defense');?>>Criminal Defense</option>
							<option value="elder-law" <?php selected($resource['practice-areas'][0]['slug'], 'elder-law');?>>Elder Law</option>
							<option value="employment-law" <?php selected($resource['practice-areas'][0]['slug'], 'employment-law');?>>Employment Law</option>
							<option value="estate-planning" <?php selected($resource['practice-areas'][0]['slug'], 'estate-planning');?>>Estate Planning</option>
							<option value="family-law" <?php selected($resource['practice-areas'][0]['slug'], 'family-law');?>>Family Law</option>
							<option value="immigration-law" <?php selected($resource['practice-areas'][0]['slug'], 'immigration-law');?>>Immigration Law</option>
							<option value="intellectual-property" <?php selected($resource['practice-areas'][0]['slug'], 'intellectual-property');?>>Intellectual Property</option>
							<option value="legal-malpractice" <?php selected($resource['practice-areas'][0]['slug'], 'legal-malpractice');?>>Legal Malpractice</option>
							<option value="medical-malpractice" <?php selected($resource['practice-areas'][0]['slug'], 'medical-malpractice');?>>Medical Malpractice</option>
							<option value="personal-injury" <?php selected($resource['practice-areas'][0]['slug'], 'personal-injury');?>>Personal Injury</option>
							<option value="social-security-disability" <?php selected($resource['practice-areas'][0]['slug'], 'social-security-disability');?>>Social Security Disability</option>
							<option value="special-needs-planning" <?php selected($resource['practice-areas'][0]['slug'], 'special-needs-planning');?>>Special Needs Planning</option>
							<option value="veterans-benefits" <?php selected($resource['practice-areas'][0]['slug'], 'veterans-benefits');?>>Veterans Benefits</option>
						</select>
						<select name="wplp-resource[practice-areas][1]" id="wplp-resource[practice-areas][1]">
							<option value=""></option>
							<option value="bankruptcy" <?php selected($resource['practice-areas'][1]['slug'], 'bankruptcy');?>>Bankruptcy</option>
							<option value="business-law" <?php selected($resource['practice-areas'][1]['slug'], 'business-law');?>>Business Law</option>
							<option value="criminal-defense" <?php selected($resource['practice-areas'][1]['slug'], 'criminal-defense');?>>Criminal Defense</option>
							<option value="elder-law" <?php selected($resource['practice-areas'][1]['slug'], 'elder-law');?>>Elder Law</option>
							<option value="employment-law" <?php selected($resource['practice-areas'][1]['slug'], 'employment-law');?>>Employment Law</option>
							<option value="estate-planning" <?php selected($resource['practice-areas'][1]['slug'], 'estate-planning');?>>Estate Planning</option>
							<option value="family-law" <?php selected($resource['practice-areas'][1]['slug'], 'family-law');?>>Family Law</option>
							<option value="immigration-law" <?php selected($resource['practice-areas'][1]['slug'], 'immigration-law');?>>Immigration Law</option>
							<option value="intellectual-property" <?php selected($resource['practice-areas'][1]['slug'], 'intellectual-property');?>>Intellectual Property</option>
							<option value="legal-malpractice" <?php selected($resource['practice-areas'][1]['slug'], 'legal-malpractice');?>>Legal Malpractice</option>
							<option value="medical-malpractice" <?php selected($resource['practice-areas'][1]['slug'], 'medical-malpractice');?>>Medical Malpractice</option>
							<option value="personal-injury" <?php selected($resource['practice-areas'][1]['slug'], 'personal-injury');?>>Personal Injury</option>
							<option value="social-security-disability" <?php selected($resource['practice-areas'][1]['slug'], 'social-security-disability');?>>Social Security Disability</option>
							<option value="special-needs-planning" <?php selected($resource['practice-areas'][1]['slug'], 'special-needs-planning');?>>Special Needs Planning</option>
							<option value="veterans-benefits" <?php selected($resource['practice-areas'][1]['slug'], 'veterans-benefits');?>>Veterans Benefits</option>
						</select>
						<select name="wplp-resource[practice-areas][2]" id="wplp-resource[practice-areas][2]">
							<option value=""></option>
							<option value="bankruptcy" <?php selected($resource['practice-areas'][2]['slug'], 'bankruptcy');?>>Bankruptcy</option>
							<option value="business-law" <?php selected($resource['practice-areas'][2]['slug'], 'business-law');?>>Business Law</option>
							<option value="criminal-defense" <?php selected($resource['practice-areas'][2]['slug'], 'criminal-defense');?>>Criminal Defense</option>
							<option value="elder-law" <?php selected($resource['practice-areas'][2]['slug'], 'elder-law');?>>Elder Law</option>
							<option value="employment-law" <?php selected($resource['practice-areas'][2]['slug'], 'employment-law');?>>Employment Law</option>
							<option value="estate-planning" <?php selected($resource['practice-areas'][2]['slug'], 'estate-planning');?>>Estate Planning</option>
							<option value="family-law" <?php selected($resource['practice-areas'][2]['slug'], 'family-law');?>>Family Law</option>
							<option value="immigration-law" <?php selected($resource['practice-areas'][2]['slug'], 'immigration-law');?>>Immigration Law</option>
							<option value="intellectual-property" <?php selected($resource['practice-areas'][2]['slug'], 'intellectual-property');?>>Intellectual Property</option>
							<option value="legal-malpractice" <?php selected($resource['practice-areas'][2]['slug'], 'legal-malpractice');?>>Legal Malpractice</option>
							<option value="medical-malpractice" <?php selected($resource['practice-areas'][2]['slug'], 'medical-malpractice');?>>Medical Malpractice</option>
							<option value="personal-injury" <?php selected($resource['practice-areas'][2]['slug'], 'personal-injury');?>>Personal Injury</option>
							<option value="social-security-disability" <?php selected($resource['practice-areas'][2]['slug'], 'social-security-disability');?>>Social Security Disability</option>
							<option value="special-needs-planning" <?php selected($resource['practice-areas'][2]['slug'], 'special-needs-planning');?>>Special Needs Planning</option>
							<option value="veterans-benefits" <?php selected($resource['practice-areas'][2]['slug'], 'veterans-benefits');?>>Veterans Benefits</option>
						</select><br />
						<span class="description"><?php _e('Only select areas of law related to your practice');?></span>
					</td>
				</tr>
			</table>
			<?php submit_button(__("Update Profile")); ?>
			<h3><?php echo sprintf(__('Visit %s to manage your profile though our secure connection.', 'lawyer-resources'), "<a href='".LP_URL."dashboard/' target='_blank'>" . LP_URL . "dashboard/</a>");?></h3>
		</form>

		<?php
	}
	
	function admin_page_deauthorize() {
		$this->flush_settings();
		$this->flush_resources();
		wp_die(sprintf(__('%1$s<h3>Invalid email/username combination, your plugin was de-authorized.</h3> Refresh the page to %2$sre-authorize the plugin%3$s.', 'lawyer-resources'),
			'<img src="' . LP_PLUGIN_DIR . 'logo-full.png" alt="Lawyer Plugin" />',
			'<a href="' . LP_PLUGIN_URL . '">',
			'</a>'
		));
	}

	function admin_page_outdated() {
		wp_die(sprintf(__('%1$s<h3>Your plugin is out of date.</h3> Visit the %2$splugins page%3$s to update to the latest version.', 'lawyer-resources'),
			'<img src="' . LP_PLUGIN_DIR . 'logo-full.png" alt="Lawyer Plugin" />',
			'<a href="' . admin_url('plugins.php') . '">',
			'</a>'
		));
	}

	function shortcode($atts, $content=null) {
		
		if(!$this->client) {
			return '<p><em>' . sprintf(__('Lawyer Plugin is not registered. %1$sSign in%2$s to populate this page with attorney resources.', 'lawyer-resources'), "<a href='".LP_PLUGIN_URL."'>", '</a>') . '</em></p>';
		}
		
		if(!$taxonomies = get_terms('resource-category', array('hide_empty' => 0))) {
			return '<p><em>' . sprintf(__('Lawyer Plugin is registered. Check the %1$ssettings page%2$s to see when the next sync will happen.', 'lawyer-resources'), "<a href='".LP_PLUGIN_URL."'>", '</a>') . '</em></p>';
		}
		
		extract(shortcode_atts(array(
			'class' => 'default',
			'icons' => 'true',
		), $atts));
		
		global $post, $wp_query;
		$link_base = get_permalink($post->ID);
		$link_category = get_query_var(LP_CATSLUG);
		$output = '<div id="lawyer-resources" class="' . $class . ' icons-' . $icons . '">';

		if($link_category = get_term_by('slug', $link_category, 'resource-category')) {
			
			$output .= '<h2 class="lawyer-resources-heading">' . $link_category->name . ' <small><a href="' . $link_base . '">&laquo; ' . __('Back') . '</a></small></h2>';
			$output .= '<ul id="lawyer-resources-resources">';

			$resource_args = array(
				'style' => 'none',
				'post_type' => 'wplp-resource',
				'post_status' => 'publish',
				'numberposts' => -1,
				'orderby' => 'title',
				'order' => 'ASC',
				'tax_query' => array(
					array(
						'taxonomy' => 'resource-category',
						'field' => 'slug',
						'terms' => array($link_category->slug)
					)
				)
			);
			if($law_resources = get_posts($resource_args)) {

				foreach($law_resources as $law_resource) {
					$output .= '<li id="resource-' . $law_resource->ID . '" class="resource-item">';
					$output .= '<h4 class="resource-title"><a href="' . esc_url($law_resource->post_content) . '" target="_blank">' . $law_resource->post_title . '</a></h4>';
					$output .= '<p class="resource-meta"><a href="' . esc_url($law_resource->post_content) . '" target="_blank">' . esc_url($law_resource->post_content) . '</a></p>';

					$resource_address =  json_decode(get_post_meta($law_resource->ID, 'address', true), true);
					if(!empty($resource_address['street'])) {
						$output .= '<p class="resource-address">';
						$output .= $resource_address['street'] . '<br />' . $resource_address['city'] . ', ' . $resource_address['state'] . ' ' . $resource_address['zip'];
						$output .= '</p>';
					}
					
					$practice_areas = wp_get_post_terms($law_resource->ID, 'resource-practice-area', array('fields' => 'names'));
					if(count($practice_areas)) {
						$output .= '<p class="resource-meta">Practice Areas: ' . implode(', ', $practice_areas) . '</p>';
					}
					
					$output .= '</li>';
				}
				
			} else {
				$output .= '<li><em>' . __("No resources found (this isn't good)") . '</em></li>';
			}
			
			$output .= "</ul><!-- #lawyer-resources-resources -->";
			
			if($link_category->slug == 'lawyer'){
				$output .= '<p><small>' . __('This list of law firms is provided for information only. This firm does not necessarily endorse any of these law firms/attorneys nor should your decision to retain a lawyer presented on this website constitute as a referral from this firm to your hired lawyer or law firm. This firm has not received any monetary compensation from the law firms/attorneys listed on this page.') . '</small></p>';
			}
			$output .= '<p><small>' . __('The resources provided on this page are for information only. Providing these resources and/or referencing them does not constitute legal advice neither does use of this website constitute an attorney client relationship. This law firm does not necessarily endorse any resource provided on the websites linked to on this page.') . '</small></p>';
		
		} else {
		
			$output .= '<h2 class="lawyer-resources-heading"">' . __('Legal and Attorney Resources') . '</h2>';
			$output .= '<ul id="category-list">';
			
			foreach((array) $taxonomies as $taxonomy) {
				if(get_option('permalink_structure')) {
					$cat_link = trailingslashit($link_base) . LP_CATSLUG . '/' . $taxonomy->slug;
				} else {
					$cat_link = add_query_arg(array(LP_CATSLUG => $taxonomy->slug), $link_base);
				}
				
				$output .= '<li id="category-' . $taxonomy->slug . '" class="category-item" style="background-image:url(\'' . LP_PLUGIN_DIR . 'icon-' . $taxonomy->slug . '.png\');">';
				$output .= '<h3 class="category-title"><a href="' . $cat_link . '">' . $taxonomy->name . '</a></h3>';
				$output .= '<p class="category-description">' . $taxonomy->description . '</p>';
				$output .= '</li>';
			}
			
			$output .= "</ul><!-- #lawyer-resources-categories -->";
			
		}
		
		$output .= '</div><!-- #lawyer-resources-html -->';
		return $output;
	}
	
	function display_style() {
		wp_register_style('wplp-style', LP_PLUGIN_DIR . 'default.css', array(), LP_VERSION, 'screen');
		wp_enqueue_style('wplp-style');
	}
	
	function plugin_action_links($links) {
		$settings_link = '<a href="'.LP_PLUGIN_URL.'">' . __("Configure") . '</a>';
		array_unshift($links, $settings_link);
		return $links;
	}
	
	function wp_title($title) {
		global $wp_query;
		if($queried_category_exists = get_term_by('slug', $wp_query->query_vars[LP_CATSLUG], 'resource-category')) {
			return $queried_category_exists->name . ' &raquo ' . $title;
		}
		return $title;
	}
	
	function flush_settings() {		
		delete_option('wplp-client');
		delete_option('wplp-lastsync');
		wp_clear_scheduled_hook('wplp-sync');
	}
	
	function flush_resources() {
		global $wpdb;
		$wpdb->query("DELETE a,b,c,d
		FROM {$wpdb->posts} a
		LEFT JOIN {$wpdb->term_relationships} b ON ( a.ID = b.object_id )
		LEFT JOIN {$wpdb->postmeta} c ON ( a.ID = c.post_id )
		LEFT JOIN {$wpdb->term_taxonomy} d ON ( d.term_taxonomy_id = b.term_taxonomy_id )
		LEFT JOIN {$wpdb->terms} e ON ( e.term_id = d.term_id )
		WHERE a.post_type = 'wplp-resource'");
		
		$existing_categories = get_terms('resource-category', array('hide_empty' => 0));
		foreach($existing_categories as $existing_category) {
			wp_delete_term($existing_category->term_id, 'resource-category');
		}
		
		$existing_practice_areas = get_terms('resource-practice-area', array('hide_empty' => 0));
		foreach($existing_practice_areas as $existing_practice_area) {
			wp_delete_term($existing_practice_area->term_id, 'resource-practice-area');
		}
	}
	
	function sync() {
		$upload_dir_array = wp_upload_dir();
		$import_zip = $upload_dir_array['basedir']."/resources.zip";
		$import_json = $upload_dir_array['basedir']."/resources.json";
		
		$api_args = array(
			'action' => "download",
			'user_login' => $this->client['user_login'],
			'user_pass' => $this->client['user_pass']
		);
		
		$api_response_raw = wp_remote_post(LP_API, array(
			'body' => $api_args,
			'timeout' => 30,
			'sslverify' => false,
			'user-agent' => $this->api_user_agent()
		));
		
		if($api_response_raw['headers']['content-type'] == "application/json") {
			$api_response = json_decode(wp_remote_retrieve_body($api_response_raw), true);
			if(isset($api_response['unauthorized'])) {
				$this->admin_page_deauthorize();
			}
			if(isset($api_response['outdated'])) {
				$this->admin_page_outdated();
			}
			echo "json response";
			exit;
		}
		
		if($api_response_raw['headers']['content-type'] != "application/octet-stream") {
			echo "not a zip";
			exit;
		}
		
		require_once(ABSPATH . 'wp-admin/includes/file.php');
		WP_Filesystem();		
		global $wp_filesystem;

		$wp_filesystem->put_contents(
		  $import_zip,
		  wp_remote_retrieve_body($api_response_raw),
		  FS_CHMOD_FILE
		);
		
		if(!file_exists($import_zip)) {	
			echo "no zip saved";
			exit;
		}

		unzip_file($import_zip, trailingslashit($upload_dir_array['basedir']));
		
		$import_raw = $wp_filesystem->get_contents($import_json);
		$import_data = json_decode($import_raw, true);
		
		if(!$import_data) {
			echo "no data import";
			exit;
		}
		
		update_option("wplp-built",  $import_data['built']);
		
		$this->flush_resources();
	
		foreach((array) $import_data['categories'] as $import_category) {
			wp_insert_term(
				$import_category['name'],
				'resource-category',
				array(
					'description'=> $import_category['desc'],
					'slug' => $import_category['slug']
				)
			);
		}
		
		foreach((array) $import_data['resources'] as $import_resource) {
			$resource_id = wp_insert_post(array(
				'post_title' => $import_resource['title'],
				'post_type' => 'wplp-resource',
				'post_status' => 'publish',
				'post_content' => $import_resource['url'],
				'post_author' => 0
			));

			update_post_meta($resource_id, "address", json_encode($import_resource['address']));

			if(isset($import_resource['category'])){
				$category_exists = term_exists($import_resource['category']['name'] , 'resource-category');
				if($category_exists !== 0 && $category_exists !== null) {
					wp_set_post_terms($resource_id, $category_exists['term_id'], 'resource-category');
				}			
			}
			
			if(isset($import_resource['practice-areas'])) {
				foreach($import_resource['practice-areas'] as $resource_practice_area){
					wp_set_post_terms($resource_id, $resource_practice_area['name'], 'resource-practice-area', true);
				}
			}
			
		}
		
		unlink($import_json);
		unlink($import_zip);
		unset($import_data);
		
		update_option("wplp-lastsync", time());
		wp_schedule_event(time() + (60*50*24), 'daily', 'wplp-sync');

		exit;
	}

}

new Lawyer_Plugin;