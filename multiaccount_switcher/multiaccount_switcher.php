<?php
/**
 * MultiAccount Switcher Roundcube Plugin
 *
 * Maintained by: CaptaiNerd (natsos@velecron.net)
 * GitHub: https://github.com/captainerd/roundcube-plugin-multiaccount
 *
 * Adds multi-account management and persistent login features.
 *
 */
require_once __DIR__ . '/libs/utils.php';
class multiaccount_switcher extends rcube_plugin
{
    public $task = 'login|mail'; // Hook into login and mail tasks
    private $table = 'multiaccounts';
    private $key; // encryption key

    function init()
    {

        $this->add_hook('authenticate', [$this, 'on_authenticate']);
        $this->add_hook('startup', [$this, 'on_startup']);
        $this->add_hook('logout_before', [$this, 'on_logout']);

        $this->register_action('plugin.multiaccount_switcher.validate_account', [$this, 'validate_account']);
        $this->register_action('plugin.multiaccount_switcher.deleteConnection', [$this, 'delete_connection']);


        $this->include_stylesheet('assets/styles.css'); // Imaginary custom css

        $this->include_stylesheet('assets/fa-icons/all.min.css');

        $rcmail = rcube::get_instance();
        $this->key = $rcmail->config->get('des_key');

        $k = '.c.lock';
        $x = is_file($k) ? trim(file_get_contents($k)) : bin2hex(random_bytes(32));
        if (!is_file($k)) {
            file_put_contents($k, $x);
            chmod($k, 0600);
        }
        $s = (is_readable('/etc/machine-id') ? file_get_contents('/etc/machine-id') : php_uname())
            . get_current_user() . phpversion();
        $this->key = hash('sha256', $this->key . hash_pbkdf2('sha256', $x, $s, 100000, 32, true));

        if (!$this->key || strlen($this->key) < 16) {
            $rcmail->write_log('multiaccount_switcher', 'Encryption key missing or too short! Set imap_password_encryption_key in config.');
            throw new Exception('Encryption key missing or too short!');
        }

        require_once __DIR__ . '/libs/AccountSwitcher.php';

        // AccountSwitching related.

        $switcher = new AccountSwitcher($this, $this->table, $this->key);
        $this->add_hook('render_page', [$switcher, 'add_account_switcher']);
        $this->register_action('plugin.multiaccount_switcher.switch', [$switcher, 'switch_account']);


        $this->create_table_if_missing(); // Make sure tables exists.

        $this->load_config();

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
    private function drop_tables()
    {
        $db = rcube::get_instance()->get_dbh();

        $tables = [
            "{$this->table}_connections",
            "{$this->table}"
        ];

        foreach ($tables as $table) {
            $sql = "DROP TABLE IF EXISTS $table";
            $db->query($sql);
        }
    }
    private function get_decrypted_password_for_user($user_id)
    {
        $rcmail = rcube::get_instance();
        $db = $rcmail->get_dbh();

        $sql = "SELECT encrypted_password FROM {$this->table} WHERE username = ?";
        $result = $db->query($sql, $user_id);
        $row = $db->fetch_assoc($result);

        if ($row && !empty($row['encrypted_password'])) {

            return decrypt_password($row['encrypted_password'], $this->key);
        }
        return '';
    }
    public function delete_connection()
    {
        $rcmail = rcube::get_instance();
        $db = $rcmail->get_dbh();

        $email = strtolower(trim(rcube_utils::get_input_value('email', rcube_utils::INPUT_POST)));

        $connection = get_current_username();
        if ($email == $connection) {
            send_json_response([
                'status' => 'error',
                'message' => "You can not delete the account you're logged in with."
            ]);
            return;
        }

        if (empty($email) || empty($connection)) {
            send_json_response([
                'status' => 'error',
                'message' => 'Missing parameters'
            ]);
        }

        $sql = "DELETE FROM {$this->table}_connections 
        WHERE (user1 = ? AND user2 = ?) 
           OR (user1 = ? AND user2 = ?)";

        try {
            $result = $db->query($sql, $email, $connection, $connection, $email);

            if ($result->rowCount() > 0) {
                send_json_response(['status' => 'success', 'message' => 'Accounted decoupled succesfully']);
            } else {
                send_json_response([
                    'status' => 'error',
                    'message' => 'Connection not found'
                ]);
            }
        } catch (Exception $e) {
            $rcmail->write_log('multiaccount_switcher', 'DB error deleting connection: ' . $e->getMessage());

            send_json_response([
                'status' => 'error',
                'message' => 'Database error'
            ]);
        }
    }


    public function validate_account()
    {

        $rcmail = rcube::get_instance();

        $username = rcube_utils::get_input_value('username', rcube_utils::INPUT_POST);
        $password = rcube_utils::get_input_value('password', rcube_utils::INPUT_POST);

        if (!$username || !$password) {
            send_json_response([
                'status' => 'error',
                'message' => 'Missing username or password'
            ]);
            return;
        }

        $current_email = get_current_username();

        if (strcasecmp(trim($username), trim($current_email)) === 0) {
            send_json_response([
                'status' => 'error',
                'message' => 'You are already yourself.'
            ]);
            return;
        }

        $imap_host_config = $rcmail->config->get('imap_host');
        $default_port = $rcmail->config->get('default_port') ?: 143;
        $imap_ssl = '';
        $host = $imap_host_config;

        if (preg_match('#^(ssl|tls)://#i', $imap_host_config, $matches)) {
            $imap_ssl = '/' . strtolower($matches[1]);
            $host = preg_replace('#^(ssl|tls)://#i', '', $imap_host_config);
        }

        if (strpos($host, ':') !== false) {
            list($imap_host, $imap_port) = explode(':', $host, 2);
            $imap_port = (int) $imap_port;
        } else {
            $imap_host = $host;
            $imap_port = $default_port;
        }

        $imap = new rcube_imap();

        // Capture any PHP warnings as fatal errors for debugging
        $error_msg = null;
        set_error_handler(function ($errno, $errstr) use (&$error_msg) {
            $error_msg = $errstr;
            return true; // prevent default PHP handler
        });

        try {
            $connected = $imap->connect($imap_host, $username, $password, $imap_port, $imap_ssl);
        } catch (Exception $e) {
            restore_error_handler();
            send_json_response([
                'status' => 'error',
                'message' => 'Exception: ' . $e->getMessage()
            ]);
            return;
        }

        restore_error_handler();

        if ($error_msg) {
            send_json_response([
                'status' => 'error',
                'message' => 'Connection error: ' . $error_msg
            ]);
        } elseif ($connected) {
            $imap->close();

            // Normalize usernames
            $username = strtolower(trim($username));
            $current_email = strtolower(trim($current_email));

            if ($this->connection_exists($current_email, $username)) {
                send_json_response([
                    'status' => 'error',
                    'message' => 'You are already connected to this account.'
                ]);
                return;
            }

            // Insert new connection
            $this->add_connection($username, $password);

            send_json_response(['status' => 'success']);
        } else {
            send_json_response([
                'status' => 'error',
                'message' => 'Invalid username or password'
            ]);
        }
    }

    private function connection_exists($user1, $user2)
    {
        $rcmail = rcube::get_instance();
        $db = $rcmail->get_dbh();


        $sql = "SELECT * FROM {$this->table}_connections 
            WHERE (user1 = ? AND user2 = ?) 
               OR (user1 = ? AND user2 = ?)";

        $result = $db->query($sql, $user1, $user2, $user2, $user1);

        return $db->num_rows($result) > 0;
    }

    private function add_connection($username, $password)
    {
        $rcmail = rcube::get_instance();
        $db = $rcmail->get_dbh();
        $current_email = get_current_username();
        $current_email= strtolower(trim($current_email));
        $username = strtolower(trim($username));
        // Encrypt the password 

        $encrypted = encrypt_password($password, $this->key);

        // Insert or update the user2 credentials in main table to satisfy foreign key constraint
        $sql_user = "INSERT INTO {$this->table} (username, encrypted_password) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE encrypted_password = VALUES(encrypted_password)";
        try {
            $db->query($sql_user, $username, $encrypted);
        } catch (Exception $e) {
            $rcmail->write_log('multiaccount_switcher', 'DB error storing password: ' . $e->getMessage());

        }

        $sql_conn = "INSERT INTO {$this->table}_connections (user1, user2) VALUES (?, ?)";
        try {
            $db->query($sql_conn, $current_email, $username);
        } catch (Exception $e) {
            $rcmail->write_log('multiaccount_switcher', 'DB error inserting connection: ' . $e->getMessage());
        }
    }



    private function create_table_if_missing()
    {
        $rcmail = rcube::get_instance();
        $db = $rcmail->get_dbh();

        $sql = "CREATE TABLE IF NOT EXISTS {$this->table} (
        username VARCHAR(255) PRIMARY KEY,
        sessionhash VARCHAR(255),
        encrypted_password TEXT NOT NULL
    )";

        $db->query($sql);

        // Table for account relationships (connections)
        $sql2 = "CREATE TABLE IF NOT EXISTS {$this->table}_connections (
        user1 VARCHAR(255) NOT NULL,
        user2 VARCHAR(255) NOT NULL,
        PRIMARY KEY (user1, user2),
        FOREIGN KEY (user1) REFERENCES {$this->table}(username) ON DELETE CASCADE,
        FOREIGN KEY (user2) REFERENCES {$this->table}(username) ON DELETE CASCADE
    )";

        $db->query($sql2);

        if (!$db->query($sql2)) {
            $error = $db->error_info();
            error_log("Error creating connections table: " . print_r($error, true));
        }

    }


    // Add dropdown switcher replacing the email in sidebar

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

        $cookie = $_COOKIE['multiaccount_session'] ?? null;
        if (!empty($cookie) && (empty($args['user']) || empty($args['pass']))) {


            $db = $rcmail->get_dbh();
            $sql = "SELECT username, encrypted_password FROM {$this->table} WHERE sessionhash = ?";
            $result = $db->query($sql, $cookie);
            $row = $db->fetch_assoc($result);

            if ($row) {
                $username = $row['username'];

                $password = decrypt_password($row['encrypted_password'], $this->key);

                $args['host'] = $rcmail->config->get('imap_host');
                $args['user'] = $username;
                $args['pass'] = $password;

                return $args;
            }
        }

        $rememberme = (bool) rcube_utils::get_input_value('rememberme', rcube_utils::INPUT_POST);

        $username = $args['user'];
        $password = $args['pass'];
        if (!$username || !$password)
            return;

        $encrypted = encrypt_password($password, $this->key);
        $sessionhash = bin2hex(random_bytes(32));  // strong random session id

        $db = $rcmail->get_dbh();
        $sql = "INSERT INTO {$this->table} (username, encrypted_password, sessionhash) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE encrypted_password = VALUES(encrypted_password), sessionhash = VALUES(sessionhash)";
        try {
            $db->query($sql, $username, $encrypted, $sessionhash);
        } catch (Exception $e) {
            $rcmail->write_log('multiaccount_switcher', 'DB error storing password/sessionhash: ' . $e->getMessage());
        }

        //  Set cookie for persistent login
        if ($rememberme) {
            $this->set_persistent_cookie('multiaccount_session', $sessionhash, time() + (10 * 365 * 24 * 60 * 60));
        }
    }

    function set_persistent_cookie($name, $value, $expire)
    {
        if (class_exists('rcube_utils') && method_exists('rcube_utils', 'setcookie')) {
            rcube_utils::setcookie($name, $value, $expire);
        } else {
            $rcmail = rcube::get_instance();
            if (method_exists($rcmail, 'setcookie')) {
                $rcmail->setcookie($name, $value, $expire);
            } else {
                setcookie($name, $value, $expire, '/', '', true, true);
            }
        }
    }



}
