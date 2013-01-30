<?php
/**
 * Plugin Name: WP OAuth Provider
 * Description: Enable WordPress to act as an OAuth provider!
 *
 * A massive thanks to Morten Fangel, as without his guide, this would
 * have taken a lot longer to write.
 */

if (!class_exists('OAuthServer')) {
	require_once(dirname(__FILE__) . '/oauth.php');
}

class WPOAuthProvider {
	protected static $data;
	protected static $server;

	const PATH_AUTHORIZE = '/oauth/authorize/';

	public static function bootstrap() {
		self::$data = new WPOAuthProvider_DataStore();
		self::$server = new OAuthServer(self::$data);

		$hmac = new OAuthSignatureMethod_HMAC_SHA1();
		self::$server->add_signature_method($hmac);

		// only allow plaintext if we're over a secure connection
		if (is_ssl()) {
			$plaintext = new OAuthSignatureMethod_PLAINTEXT();
			self::$oauth->add_signature_method($plaintext);
		}

		register_activation_hook(__FILE__, array(get_class(), 'activate'));
		register_deactivation_hook(__FILE__, array(get_class(), 'deactivate'));

		add_action('admin_menu', array(__CLASS__, 'menu'), -100);

		add_filter('authenticate', array(get_class(), 'authenticate'), 15, 3);
		add_filter('plugins_loaded', array(get_class(), 'plugins_loaded'));
		add_filter('rewrite_rules_array', array(get_class(), 'rewrite_rules_array'));
		add_filter('query_vars', array(get_class(), 'query_vars'));
		add_filter('redirect_canonical', array(get_class(), 'redirect_canonical'), 10, 2);
		add_action('template_redirect', array(get_class(), 'template_redirect'));

		add_action('login_form', array(get_class(), 'setup_register_mangle'));
		add_action('register_form', array(get_class(), 'setup_register_mangle'));
		add_action('lostpassword_form', array(get_class(), 'setup_register_mangle'));

		add_action('update_user_metadata', array(get_class(), 'after_register_autologin'), 10, 4);
	}

	public static function activate() {
		global $wp_rewrite;
		$wp_rewrite->flush_rules();
	}

	public static function deactivate() {
		global $wp_rewrite;
		remove_filter('rewrite_rules_array', array(get_class(), 'rewrite_rules_array'));
		$wp_rewrite->flush_rules();
	}

	/**
	 * Add our menu page
	 *
	 * @wp-action admin_menu
	 */
	public static function menu() {
		add_dashboard_page('OAuth Keys', 'OAuth Keys', 'manage_options', 'wpoaprovider', array(__CLASS__, 'oauth_config'));
	}

	/**
	 * OAuth configuration page
	 *
	 * Hooked via `add_dashboard_page()`
	 * @see menu()
	 * @param WP_User $user Current user
	 */
	public static function oauth_config($user) {
		$consumers = get_option('wpoaprovider_consumers', array());
		if (!empty($_GET['action'])) {
			if ($_GET['action'] === 'create') {
				$key = WPOAuthProvider::create_consumer();
				$consumers[$key] = $key;
				update_option('wpoaprovider_consumers', $consumers);
			}
			elseif ($_GET['action'] === 'delete' && !empty($_GET['key'])) {
				WPOAuthProvider::delete_consumer($_GET['key']);
				unset($consumers[$_GET['key']]);
				update_option('wpoaprovider_consumers', $consumers);
			}
		}
?>
	<h2><?php _e('OAuth Details' , 'wpoaprovider'); ?></h2>
	<p><a href="index.php?page=wpoaprovider&amp;action=create">New Pair</a></p>
<?php

?>
	<table>
		<thead>
			<tr>
				<th>Key</th>
				<th>Secret</th>
				<th>Action</th>
			</tr>
		</head>
		<tbody>
<?php
		foreach ($consumers as $key) {
			$consumer = WPOAuthProvider::get_consumer($key);
?>
			<tr>
				<td><code><?php echo $consumer->key ?></code></td>
				<td><code><?php echo $consumer->secret ?></code></td>
				<td><a href="index.php?page=wpoaprovider&amp;action=delete&amp;key=<?php echo esc_attr($consumer->key) ?>">Delete</a></td>
			</tr>
<?php
		}
?>
		</tbody>
	</table>
<?php
	}

	public static function rewrite_rules_array($rules) {
		$newrules = array();
		$newrules['oauth/authorize$'] = 'index.php?oauth=authorize';
		return array_merge($newrules, $rules);
	}

	public static function query_vars($vars) {
		$vars[] = 'oauth';
		return $vars;
	}

	public static function redirect_canonical($new, $old) {
		if (strlen(get_query_var('oauth')) > 0) {
			return false;
		}

		return $new;
	}

	public static function template_redirect() {
		$page = get_query_var('oauth');
		if (!$page) {
			return;
		}

		switch ($page) {
			case 'authorize':
				self::authorize();
				break;
			default:
				global $wp_query;
				$wp_query->set_404();
				return;
		}

		die();
	}

	public static function setup_register_mangle() {
		add_filter('site_url', array(get_class(), 'register_mangle'), 10, 3);
		add_filter('lostpassword_url', array(get_class(), 'login_mangle'));
		add_filter('login_url', array(get_class(), 'login_mangle'));
	}

	public static function after_register_autologin($metaid, $userid, $key, $value) {
		// We only care about the password nag event. Ignore anything else.
		if ( 'default_password_nag' !== $key || true != $value) {
			return;
		}

		// Set the current user variables, and give him a cookie. 
		wp_set_current_user( $userid );
		wp_set_auth_cookie( $userid );

		// If we're redirecting, ensure they know we are
		if (!empty($_POST['redirect_to'])) {
			$_POST['redirect_to'] = add_query_arg('checkemail', 'registered', $_POST['redirect_to']);
		}
	}


	/**
	 * Ensure the redirect_to parameter is carried through to registration
	 *
	 * @wp-filter site_url
	 */
	public static function register_mangle($url, $path, $scheme) {
		if ($scheme !== 'login' || $path !== 'wp-login.php?action=register' || empty($_REQUEST['redirect_to'])) {
			return $url;
		}

		$url = add_query_arg('redirect_to', $_REQUEST['redirect_to'], $url);
		return $url;
	}
	public static function login_mangle($url) {
		if (empty($_REQUEST['redirect_to'])) {
			return $url;
		}

		$url = add_query_arg('redirect_to', $_REQUEST['redirect_to'], $url);
		return $url;
	}

	public static function get_consumer($key) {
		return self::$data->lookup_consumer($key);
	}

	public static function create_consumer() {
		return self::$data->new_consumer();
	}

	public static function delete_consumer($key) {
		return self::$data->delete_consumer($key);
	}

	public static function request_token($request) {
		$token = self::$server->fetch_request_token($request);

		$data = array(
			'oauth_token' => OAuthUtil::urlencode_rfc3986($token->key),
			'oauth_token_secret' => OAuthUtil::urlencode_rfc3986($token->secret)
		);

		$token->callback = $request->get_parameter('oauth_callback');
		if (!empty($token->callback)) {
			$data['oauth_callback_confirmed'] = 'true';
			$token->save();
		}

		return $data;
	}

	public static function authorize() {
		if (empty($_REQUEST['oauth_token'])) {
			wp_die('No OAuth token found in request. Please ensure your client is configured correctly.', 'OAuth Error', array('response' => 400));
		}

		$request = OAuthRequest::from_request();
		$url = home_url('/oauth/authorize');
		$url = add_query_arg('oauth_token', $request->get_parameter('oauth_token'), $url);

		if (!is_user_logged_in()) {
			wp_redirect(wp_login_url($url));
			die();
		}

		$token    = get_transient('wpoa_' . $request->get_parameter('oauth_token'));
		$consumer = self::$data->lookup_consumer($token->consumer);

		if (empty($_POST['wpoauth_nonce']) || empty($_POST['wpoauth_button'])) {
			return self::authorize_page($consumer, $request->get_parameter('oauth_token'), $token, $url);
		}

		if (!wp_verify_nonce($_POST['wpoauth_nonce'], 'wpoauth')) {
			status_header(400);
			wp_die('Invalid request.');
		}

		$current_user = wp_get_current_user();
		switch ($_POST['wpoauth_button']) {
			case 'authorize':
				$token->user = $current_user->ID;
				$token->verifier = wp_generate_password(8, false);
				$token->authorize();

				$data = array(
					'oauth_token' => $request->get_parameter('oauth_token'),
					'oauth_verifier' => $token->verifier
				);
				break;
			case 'cancel':
				$token->delete();

				$data = array(
					'denied' => true
				);
				break;
			default:
				// wtf?
				status_header(500);
				wp_die('Weird');
				break;
		}

		if (empty($token->callback) && $request->get_parameter('oauth_callback')) {
			$token->callback = $request->get_parameter('oauth_callback');
			$token->save();
		}

		if (!empty($token->callback) && $token->callback !== 'oob') {
			$callback = add_query_arg($data, $token->callback);
			wp_redirect($callback);
			die();
		}


		header('Content-Type: text/plain');
		echo http_build_query($data, null, '&');
		die();
	}

	protected static function authorize_page($consumer, $token, $request, $current_page) {
		$domain = parse_url($request->callback, PHP_URL_HOST);

		$template = get_query_template('oauth-link');
		include ($template);
	}

	public static function access_token($request) {
		$token = self::$server->fetch_access_token($request);

		header('Content-Type: application/x-www-form-urlencoded');
		return sprintf(
			'oauth_token=%s&oauth_token_secret=%s',
			OAuthUtil::urlencode_rfc3986($token->key),
			OAuthUtil::urlencode_rfc3986($token->secret)
		);
	}

	public static function authenticate($user, $username, $password) {
		if (is_a($user, 'WP_User') || PHP_SAPI === 'cli') {
			return $user;
		}

		try {
			$request = OAuthRequest::from_request();
			list($consumer, $token) = self::$server->verify_request($request);

			$user = new WP_User($token->user);
		}
		catch (OAuthException $e) {
			// header('WWW-Authenticate: OAuth realm="' . site_url() . '"');
		}

		return $user;
	}

	public static function plugins_loaded() {
		if (PHP_SAPI === 'cli') {
			return;
		}

		try {
			$request = OAuthRequest::from_request();
			list($consumer, $token) = self::$server->verify_request($request);

			global $current_user;
			$current_user = new WP_User($token->user);
		}
		catch (OAuthException $e) {
			// header('WWW-Authenticate: OAuth realm="' . site_url() . '"');
		}
	}
}

class WPOAuthProvider_DataStore {
	const RETAIN_TIME = 3600; // retain nonces for 1 hour

	/**
	 * @param string $consumer_key
	 * @return object Has properties "key" and "secret"
	 */
	public function lookup_consumer($consumer_key) {
		$consumer = get_option('wpoa_consumer_' . $consumer_key, false);
		if (!$consumer) {
			return null;
		}

		return $consumer;
	}

	/**
	 * @return string Consumer key
	 */
	public function new_consumer() {
		$key    = wp_generate_password(12, false);
		$secret = self::generate_secret();

		$consumer = new WPOAuthProvider_Consumer($key, $secret);

		$result = update_option('wpoa_consumer_' . $key, $consumer);
		if (!$result) {
			return false;
		}

		return $key;
	}

	/**
	 * @param string $consumer_key
	 * @return boolean
	 */
	public function delete_consumer($consumer_key) {
		return delete_option('wpoa_consumer_' . $consumer_key, false);
	}

	/**
	 * @param WPOAuthProvider_Consumer $consumer
	 * @return WPOAuthProvider_Token_Request|null
	 */
	public function new_request_token($consumer) {
		$key    = self::generate_key('rt');
		$secret = self::generate_secret();

		$token = new WPOAuthProvider_Token_Request($key, $secret);
		$token->consumer = $consumer->key;
		$token->authorized = false;

		if (!$token->save()) {
			return null;
		}

		return $token;
	}

	/**
	 * @param WPOAuth_Provider_Token_Request
	 * @param WPOAuthProvider_Consumer $consumer
	 * @param string $verifier
	 * @return WPOAuthProvider_Token_Access|null
	 */
	public function new_access_token($token, $consumer, $verifier) {
		if (!$token->authorized) {
			throw new OAuthException('Unauthorized access token');
		}
		if ($token->verifier !== $verifier) {
			throw new OAuthException('Verifier does not match');
		}

		$key    = self::generate_key('at');
		$secret = self::generate_secret();

		$access = new WPOAuthProvider_Token_Access($key, $secret);
		$access->consumer = $consumer->key;
		$access->user = $token->user;

		$access->save();
		$token->delete();

		return $access;
	}

	/**
	 * @param WPOAuthProvider_Consumer $consumer
	 * @param string $token_type Either 'request' or 'access'
	 * @return WPOAuthProvider_Token|null
	 */
	public function lookup_token($consumer, $token_type, $token) {
		switch ($token_type) {
			case 'access':
				$token = get_option('wpoa_' . $token);
				break;
			case 'request':
				$token = get_transient('wpoa_' . $token);
				break;
			default:
				throw new OAuthException('Invalid token type');
				break;
		}

		if ($token === false || $token->consumer !== $consumer->key) {
			return null;
		}

		return $token;
	}

	/**
	 * @param WPOAuthProvider_Consumer $consumer
	 * @param WPOAuthProvider_Token $token
	 * @param string $nonce
	 * @param int $timestamp
	 * @return bool
	 */
	public function lookup_nonce($consumer, $token, $nonce, $timestamp) {
		if ($timestamp < (time() - self::RETAIN_TIME)) {
			return true;
		}

		if ($token !== null) {
			$real = sha1($nonce . $consumer->key . $token->key . $timestamp);
		}
		else {
			$real = sha1($nonce . $consumer->key . 'notoken' . $timestamp);
		}

		$existing = get_transient('wpoa_n_' . $real);

		if ($existing !== false) {
			return true;
		}

		set_transient('wpoa_n_' . $real, true);
		return false;
	}

	/**
	 * Generate an OAuth key
	 *
	 * The max key length is 43 characters, we use 24 to play it safe.
	 * @param string $type Either 'at' or 'rt' (access/request resp.)
	 * @return string
	 */
	protected function generate_key($type = 'at') {
		// 
		return $type . '_' . wp_generate_password(24, false);
	}

	/**
	 * Generate an OAuth secret
	 *
	 * @return string
	 */
	protected function generate_secret() {
		return wp_generate_password(48, false);
	}
}

/**
 * WordPress OAuth token class
 */
abstract class WPOAuthProvider_Token extends OAuthToken {
	/*
	public $key;
	public $secret;
	*/
	public $consumer;

	/**
	 * Save token
	 *
	 * @return bool
	 */
	abstract public function save();

	/**
	 * Remove token
	 *
	 * @return bool
	 */
	abstract public function delete();
}

/**
 * WordPress OAuth request token class
 */
class WPOAuthProvider_Token_Request extends WPOAuthProvider_Token {
	/*
	public $key;
	public $secret;
	public $consumer;
	*/
	public $authorized = false;
	public $callback;
	public $verifier;

	/**
	 * How long should we keep request tokens?
	 */
	const RETAIN_TIME = 86400; // keep for 24 hours

	/**
	 * Authorize a token
	 */
	public function authorize() {
		$this->authorized = true;
		$this->save();
	}

	/**
	 * Save token
	 *
	 * @return bool
	 */
	public function save() {
		return set_transient('wpoa_' . $this->key, $this, self::RETAIN_TIME);
	}

	/**
	 * Remove token
	 *
	 * @return bool
	 */
	public function delete() {
		return delete_transient('wpoa_' . $this->key);
	}
}

/**
 * WordPress OAuth access token class
 */
class WPOAuthProvider_Token_Access extends WPOAuthProvider_Token {
	/*
	public $key;
	public $secret;
	public $consumer;
	*/
	public $user;

	/**
	 * Save token
	 *
	 * @return bool
	 */
	public function save() {
		return update_option('wpoa_' . $this->key, $this);
	}

	/**
	 * Remove token
	 *
	 * @return bool
	 */
	public function delete() {
		return delete_option('wpoa_' . $this->key);
	}
}

class WPOAuthProvider_Consumer extends OAuthConsumer {
}

WPOAuthProvider::bootstrap();