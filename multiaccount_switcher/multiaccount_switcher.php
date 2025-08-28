<?php

/**
 * MultiAccount Switcher Roundcube Plugin v2
 *
 * Maintained by: CaptaiNerd (natsos@velecron.net)
 * GitHub: https://github.com/captainerd/roundcube-plugin-multiaccount
 *
 * Adds multi-account management and persistent login features.
 *
 */

class multiaccount_switcher extends rcube_plugin
{

    public $task = 'login|mail'; // Hook into login and mail tasks 
    private $key; // encryption key
    private $utils;

    function init()
    {


        $this->include_stylesheet('assets/styles.css'); // Imaginary custom css

        $this->include_stylesheet('assets/fa-icons/all.min.css');

        $rcmail = rcube::get_instance();
        $this->key = $rcmail->config->get('des_key');

        if (!$this->key || strlen($this->key) < 16) {
            $rcmail->write_log('multiaccount_switcher', 'Encryption key missing or too short! Set des_key in config.');
            throw new Exception('Encryption key missing or too short!');
        }

        require_once __DIR__ . '/libs/AccountSwitcher.php';
        require_once __DIR__ . '/libs/utils.php';
        // AccountSwitching related.
        $this->utils = new Utils($this->key);
        $switcher = new AccountSwitcher($this, $this->key, $this->utils);

        $this->load_config();

        $this->add_hook('render_page', [$switcher, 'add_account_switcher']);
        $this->add_hook('authenticate', [$this, 'on_authenticate']);
        $this->add_hook('startup', [$this, 'on_startup']);
        $this->add_hook('logout_before', [$this, 'on_logout']);

        $this->register_action('plugin.multiaccount_switcher.switch', [$switcher, 'switch_account']);
        $this->register_action('plugin.multiaccount_switcher.validate_account', [$this, 'validate_account']);
        $this->register_action('plugin.multiaccount_switcher.deleteConnection', [$this, 'delete_connection']);


        $this->include_script('assets/logintoggle.js'); // Adds the remember me toggle to login
    }
    public function on_logout($args)
    {

        // Delete the multiaccount_session cookie by setting expiration to past
        if (isset($_COOKIE['multiaccount_session'])) {
            setcookie('multiaccount_session', '', time() - 3600, '/');
            unset($_COOKIE['multiaccount_session']);
        }
        return $args;
    }

    public function delete_connection()
    {
        $email = strtolower(trim(rcube_utils::get_input_value('email', rcube_utils::INPUT_POST)));
        $current_user = strtolower(trim($this->utils->get_current_username()));

        if ($email === $current_user) {
            $this->utils->send_json_response([
                'status' => 'error',
                'message' => "You cannot delete the account you're logged in with."
            ]);
            return;
        }

        if (empty($email) || empty($current_user)) {
            $this->utils->send_json_response([
                'status' => 'error',
                'message' => 'Missing parameters'
            ]);
            return;
        }

        $connections = $this->utils->get_connections();

        // Remove the target email from connections
        $new_connections = [];
        $found = false;

        foreach ($connections as $conn_email) {
            if (strtolower(trim($conn_email)) === $email) {
                $found = true;
                continue; // skip this one, delete it
            }
            $new_connections[] = $conn_email;
        }

        if (!$found) {
            $this->utils->send_json_response([
                'status' => 'error',
                'message' => 'Connection not found'
            ]);
            return;
        }

        $data = ['connections' => $new_connections];
        $json = json_encode($data);
        $encrypted = $this->utils->encrypt_password($json, $this->key);  // Assuming encrypt_password works for general data
        $cookie_value = base64_encode($encrypted);

        $this->utils->set_persistent_cookie(
            'multiaccount_connections',
            $cookie_value,
            time() + (10 * 365 * 24 * 60 * 60)
        );

        $this->utils->send_json_response([
            'status' => 'success',
            'message' => 'Account decoupled successfully'
        ]);
    }



    public function validate_account()
    {
        $rcmail = rcube::get_instance();

        // Get input
        $username = rcube_utils::get_input_value('username', rcube_utils::INPUT_POST);
        $password = rcube_utils::get_input_value('password', rcube_utils::INPUT_POST);

        if (!$username || !$password) {
            $this->utils->send_json_response([
                'status' => 'error',
                'message' => 'Missing username or password'
            ]);
            return;
        }

        $current_email = $this->utils->get_current_username();

        if (strcasecmp(trim($username), trim($current_email)) === 0) {
            $this->utils->send_json_response([
                'status' => 'error',
                'message' => 'You are already yourself.'
            ]);
            return;
        }

        // IMAP host & port detection
        $imap_host_config = $rcmail->config->get('imap_host');
        $default_port = $rcmail->config->get('default_port') ?: 143;
        $imap_ssl = '';
        $host = $imap_host_config;

        if (preg_match('#^(ssl|tls)://#i', $imap_host_config, $matches)) {
            $imap_ssl = strtolower($matches[1]); // 'ssl' or 'tls'
            $host = preg_replace('#^(ssl|tls)://#i', '', $imap_host_config);
        }

        if (strpos($host, ':') !== false) {
            list($imap_host, $imap_port) = explode(':', $host, 2);
            $imap_port = (int) $imap_port;
        } else {
            $imap_host = $host;
            $imap_port = $imap_ssl === 'ssl' ? 993 : ($imap_ssl === 'tls' ? 143 : $default_port);
        }

        $imap = new rcube_imap();

        // Capture PHP warnings as errors
        $error_msg = null;
        set_error_handler(function ($errno, $errstr) use (&$error_msg) {
            $error_msg = $errstr;
            return true;
        });

        try {
            $connected = $imap->connect($imap_host, $username, $password, $imap_port, $imap_ssl);
        } catch (Exception $e) {
            restore_error_handler();
            $this->utils->send_json_response([
                'status' => 'error',
                'message' => 'Exception: ' . $e->getMessage()
            ]);
            return;
        }

        restore_error_handler();

        if ($error_msg) {
            $this->utils->send_json_response([
                'status' => 'error',
                'message' => 'Connection error: ' . $error_msg
            ]);
        } elseif ($connected) {
            $imap->close();

            // Normalize usernames
            $username = strtolower(trim($username));
            $current_email = strtolower(trim($current_email));

            if ($this->connection_exists($current_email, $username)) {
                $this->utils->send_json_response([
                    'status' => 'error',
                    'message' => 'You are already connected to this account.'
                ]);
                return;
            }

            // Insert new connection
            $this->add_connection($username, $password);

            $this->utils->send_json_response(['status' => 'success']);
        } else {
            $this->utils->send_json_response([
                'status' => 'error',
                'message' => 'Invalid username or password'
            ]);
        }
    }


    private function add_connection($username, $password)
    {
        $current_email = strtolower(trim($this->utils->get_current_username()));
        $username = strtolower(trim($username));

        $this->utils->store_usercookie($username, $password);

        $connections = $this->utils->get_connections();

        // Add new user if not already present
        if (!in_array($username, $connections, true)) {
            $connections[] = $username;
        }
        if (!in_array($current_email, $connections, true)) {
            $connections[] = $current_email;
        }

        // Limit to max 10 accounts
        if (count($connections) > 10) {
            $connections = array_slice($connections, 0, 10);
        }

        // Store updated connections cookie
        $this->utils->store_connections($connections);
    }


    private function connection_exists(string $user1, string $user2): bool
    {
        $user1 = strtolower(trim($user1));
        $user2 = strtolower(trim($user2));
        $connections = $this->utils->get_connections(); // returns array of usernames
        return in_array($user1, $connections, true) && in_array($user2, $connections, true);
    }

    private function create_table_if_missing()
    {
        $rcmail = rcube::get_instance();
        $db = $rcmail->get_dbh();

        $sql = "CREATE TABLE IF NOT EXISTS {$this->table}_connections (
        user1 VARCHAR(255) NOT NULL,
        user2 VARCHAR(255) NOT NULL,
        PRIMARY KEY (user1, user2) 
    )";


        try {
            $result = $db->query($sql);
            if (!$result) {
                $error = $db->error_info();
                error_log("Error creating {$this->table}_connections table: " . print_r($error, true));
            }
        } catch (Exception $e) {
            error_log("Exception caught creating {$this->table}_connections table: " . $e->getMessage());
        }
    }


    public function on_startup($args)
    {

        $rcmail = rcube::get_instance();

        // Already logged in? Skip.
        if ($rcmail->user && $rcmail->user->ID) {
            return $args;
        }

        // Check for our session cookie
        $cookie = $_COOKIE['multiaccount_session'] ?? null;
        if (!$cookie) {
            return $args;
        }

        $args['action'] = 'login';
        if ($_GET['_task'] == "logout") {
            $this->on_logout($args);
        }


        return $args;
    }

    public function on_authenticate($args)
    {
        $rcmail = rcube::get_instance();

        // Auto-auth from persistent session cookie
        $cookie = $_COOKIE['multiaccount_session'] ?? null;
        if (!empty($cookie) && (empty($args['user']) || empty($args['pass']))) {
            $username = strtolower(trim($cookie));

            $password = $this->utils->get_decrypted_password_for_user($username);
            if (!empty($password)) {
                $args['host'] = $rcmail->config->get('imap_host');
                $args['user'] = $username;
                $args['pass'] = $password;
                return $args;
            }
        }

        // Manual login
        $rememberme = (bool) rcube_utils::get_input_value('rememberme', rcube_utils::INPUT_POST);
        $username = strtolower(trim($args['user']));
        $password = $args['pass'];

        if (!$username || !$password) {
            return;  // invalid login attempt
        }

        if ($rememberme) {

            $this->utils->set_persistent_cookie('multiaccount_session', $username, time() + (10 * 365 * 24 * 60 * 60));
            $this->utils->store_usercookie($username, $password);
        }

        return $args;
    }

}
