<?php

class Utils
{
    private string $key;

    public function __construct(string $key)
    {
        $this->key = $key;
    }

    public function encrypt_password(string $password, string $key = null): string
    {
        $key = $key ?? $this->key;
        $iv = openssl_random_pseudo_bytes(16);
        $ciphertext = openssl_encrypt($password, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $ciphertext);
    }

    public function decrypt_password(string $encrypted, string $key = null): ?string
    {
        $key = $key ?? $this->key;
        $data = base64_decode($encrypted);
        if ($data === false || strlen($data) < 16) {
            return null; // invalid data
        }
        $iv = substr($data, 0, 16);
        $ciphertext = substr($data, 16);
        return openssl_decrypt($ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    }

    public function get_current_username(): ?string
    {
        return $_SESSION['username'] ?? null;
    }

    public function send_json_response($data): void
    {
        $rcmail = rcube::get_instance();
        $rcmail->output->header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data);
        exit;
    }

    public function get_decrypted_password_for_user(string $user_id): ?string
    {
        $user_id = strtolower(trim($user_id));
        $cookie_name = 'multiaccount_' . hash('sha256', strtolower(trim($user_id)));

        if (!empty($_COOKIE[$cookie_name])) {
       
            $encrypted = $_COOKIE[$cookie_name];
            $encrypted = base64_decode($encrypted, true);
            return $this->decrypt_password($encrypted, $this->derive_key($user_id));
        }  
        return null;
    }

    public function get_browser_fingerprint(): string
    {
        $pepper = "A7f9d3C21b8E4f5D06a9B7c3e1F2d48a9C7e6b5d4f3a1e0b7c9d2f5e8a1b3c4d";
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        return hash('sha256', $pepper . $ua);
    }

    public function derive_key(string $username): string
    {
        $fingerprint = $this->get_browser_fingerprint();
        return hash_hmac('sha256', strtolower(trim($username)) . $fingerprint, $this->key, true);
    }

    public function store_usercookie(string $username, string $password): void
    {
        $username = strtolower(trim($username));
        $cookie_name = 'multiaccount_' . hash('sha256', strtolower(trim($username)));

        $derived_key = $this->derive_key($username);

        $encrypted = $this->encrypt_password($password, $derived_key);

        $this->set_persistent_cookie(
            $cookie_name,
            base64_encode($encrypted),
            time() + (10 * 365 * 24 * 60 * 60)
        );
    }

    public function set_persistent_cookie(string $name, string $value, int $expire): void
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

     // Save connections array securely in one cookie
    public function store_connections(array $connections): void {
        $data = json_encode([
            'connections' => array_map('strtolower', $connections),
            'timestamp' => time()
        ]);

        $encrypted = $this->encrypt_password($data, $this->key);
        $cookie_value = base64_encode($encrypted);

        $this->set_persistent_cookie('multiaccount_connections', $cookie_value, time() + (10 * 365 * 24 * 60 * 60));
    }

    // Retrieve and decrypt connections from cookie
    public function get_connections(): array {
        if (empty($_COOKIE['multiaccount_connections'])) {
            return [];
        }

        $encrypted = base64_decode($_COOKIE['multiaccount_connections']);
        if ($encrypted === false) {
            return [];
        }

        $json = $this->decrypt_password($encrypted, $this->key);
        if (!$json) {
            return [];
        }

        $data = json_decode($json, true);
        if (!is_array($data) || empty($data['connections'])) {
            return [];
        }

        return $data['connections'];
    }
}

?>
