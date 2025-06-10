 
# v2 roundcube-plugin-multiaccount\_switcher

### Summary

**Version 1** relied heavily on database tables and machine entropy to salt the Roundcube `des_key` with random bytes, creating a unique secret key. This key was used to store account relations and passwords encrypted in the database for IMAP user switching.

---

### What’s new in v2?

For **security reasons**, we moved away from centralized server-side encryption. Why? Because if the server gets compromised (e.g., shell access), a hacker could reverse-engineer the entire system to decrypt all stored passwords.

So, in **v2**:

* The server *only* holds the `des_key` and **does not store any passwords**.
* All passwords are **encrypted client-side into cookies**, using algorithms salted with server-side data.
* Usernames are hashed, making client cookies useless to an attacker.
* This means:

  * A compromised server alone is not enough to get passwords.
  * A compromised user machine is not enough either (no keys or algorithms stored there).

This decentralized approach is **much more secure** — but comes at the cost of less persistent multi-account sessions. For example, if users clear cookies or change browsers, they’ll need to re-add accounts manually.

---

### Upgrading from v1 to v2

1. Delete all old plugin files.
2. Remove old database tables via phpMyAdmin:

   * `multiaccounts`
   * `multiaccounts_connections`

If you installed **v1 quickly** and want to upgrade:

Either:
 
Run this method once to drop old tables:

```php
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
    die('Tables dropped');
}
```

---

 
