<?php


    function encrypt_password($password, $key)
    {
        $iv = openssl_random_pseudo_bytes(16);
        $ciphertext = openssl_encrypt($password, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $ciphertext);
    }

      function decrypt_password($encrypted, $key)
    {
        $data = base64_decode($encrypted);
        $iv = substr($data, 0, 16);
        $ciphertext = substr($data, 16);
        return openssl_decrypt($ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    }

    function get_current_username()
    {
        return $_SESSION['username'] ?? null;
    }

     function send_json_response($data)
    {
        $rcmail = rcube::get_instance();
        $rcmail->output->header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data);

        exit;
    }
    ?>

