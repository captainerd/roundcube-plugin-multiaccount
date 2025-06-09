<?php


class AccountSwitcher extends multiaccount_switcher
{
    protected $plugin;
    protected $db;
    protected $table;
    protected $rcmail;
    protected $key;

    public function __construct($plugin = null, $table = null, $key = null)
    {
        $this->plugin = $plugin;
        $this->table = $table;
        $this->key = $key;
        $this->rcmail = rcmail::get_instance();
        $this->db = $this->rcmail->get_dbh();
    }

    public function add_account_switcher($args)
    {

        if ($args['template'] != 'mail') {
            return $args;
        }

        $this->plugin->include_script('assets/account_switcher.js');

        $current_email = get_current_username();
        if (!$current_email) {
            return $args;
        }

        $connections = $this->get_connected_users($current_email);

        $accounts = [
            $current_email => rcube_user::user2email($current_email, true) ?? $current_email,
        ];
        foreach ($connections as $user) {
            if ($user !== $current_email) {
                $accounts[$user] = rcube_user::user2email($user, true) ?? $user;
            }
        }
        $accounts['manage'] = '🔧&nbsp;Manage Accounts';

        $options_html = '';
        foreach ($accounts as $email => $name) {
            $selected = ($email === $current_email) ? 'selected' : '';
            $options_html .= "<option value=\"$email\" $selected>$name</option>";
        }
        $select_html = "<select class=\"custom-select mt-3 form-control pretty-select\" style=\"margin: 0 5px;\">$options_html</select>";

        $args['content'] = preg_replace(
            '/<span class="header-title username">.*?<\/span>/i',
            $select_html,
            $args['content']
        );
        $manage_template = file_get_contents(__DIR__ . '/../templates/manage_accounts.html');
        $add_template = file_get_contents(__DIR__ . '/../templates/add_account.html');


        $account_rows = '';
        foreach ($accounts as $email => $name) {
            if ($email === 'manage')
                continue;
            $account_rows .= '
            <div data-email="' . htmlspecialchars($email) . '-row" class="row  account-row align-items-center mb-2">
                <div   class="col">' . htmlspecialchars($name) . '</div>
                <div class="col-auto">
                    <button type="button" class="btn btn-danger btn-sm delete-account-btn" data-email="' . htmlspecialchars($email) . '"><i class="fas fa-trash"></i></button>
                </div>
            </div>';
        }
        $manage_modal_html = str_replace('{{accounts}}', $account_rows, $manage_template);

        $args['content'] = str_replace('</body>', $manage_modal_html . $add_template . '</body>', $args['content']);

        $csrf_token = $this->rcmail->get_request_token();
        $switch_url = $this->rcmail->url(['action' => 'plugin.multiaccount_switcher.switch']);
        $delete_url = $this->rcmail->url(['_action' => 'plugin.multiaccount_switcher.deleteConnection']);
        $root = trim($this->rcmail->url('', false, false, false));
        $config = [
            'csrfToken' => $csrf_token,
            'currentEmail' => $current_email,
            'switchUrl' => $switch_url,
            'deleteUrl' => $delete_url,
            'rootUrl' => $root
        ];

        $js_config = '<script>window.AccountSwitcherConfig = '
            . json_encode($config, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES)
            . ';</script>';


        $args['content'] = str_replace('</body>', $js_config . '</body>', $args['content']);

        return $args;
    }

    public function switch_account()
    {
        $username = rcube_utils::get_input_value('account', rcube_utils::INPUT_POST);
        if (!$username) {
            $this->rcmail->output->command('display_message', 'No account specified', 'error');
            return;
        }

        $sql = "SELECT encrypted_password FROM {$this->table} WHERE username = ?";
        $result = $this->db->query($sql, $username);
        $row = $this->db->fetch_assoc($result);

        if (!$row) {
            $this->rcmail->output->command('display_message', 'Account password missing', 'error');
            return;
        }

        $password = decrypt_password($row['encrypted_password'], $this->key);

        if ($this->rcmail->login($username, $password)) {
            header('Location: ' . $this->rcmail->url(['_task' => 'mail']));
            exit;
        } else {
            $this->rcmail->output->command('display_message', 'Failed to switch user', 'error');
        }
    }

    private function get_connected_users($current_email)
    {
        $rcmail = rcube::get_instance();
        $db = $rcmail->get_dbh();

        $current_email = strtolower(trim($db->escapeSimple($current_email)));

        $sql = "SELECT user1, user2 FROM {$this->table}_connections 
            WHERE user1 = ? OR user2 = ?";

        $result = $db->query($sql, $current_email, $current_email);
        $connected = [];

        if ($result) {
            while ($row = $db->fetch_assoc($result)) {
                // Add the user connected to current_email, excluding current_email itself
                if ($row['user1'] !== $current_email) {
                    $connected[] = $row['user1'];
                }
                if ($row['user2'] !== $current_email) {
                    $connected[] = $row['user2'];
                }
            }
        }
        // Remove duplicates (in case of symmetrical entries)
        return array_values(array_unique($connected));
    }
}
