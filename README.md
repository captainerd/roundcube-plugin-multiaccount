# Roundcube Plugin: MultiAccount Switcher

This plugin does exactly what the title says — brings (to me, the most important feature) to make any webmail client remotely usable.

## What it does

1. **Multiple accounts under one login:**  
   Save multiple accounts to your main login. Once logged in, you can switch back and forth between accounts easily, just like Gmail or similar modern webmail clients.

   **What it *doesn't* do:**  
   It does **not** merge all emails into one inbox. Accounts stay separate, just easily accessible without logging out.

2. **Remember Me option:**  
   Adds a “Remember Me” checkbox on the login page so you stay logged in indefinitely — no more typing your password like an idiot every single time.

![Screenshot 1](https://i.imgur.com/A6ALEBy.png)

![Screenshot 2](https://i.imgur.com/WamnzeJ.png)

![Screenshot 3](https://i.imgur.com/1kW0i7B.png)

   
## IMPORTANT NOTES

Roundcube is basically an ancient dinosaur. It still calls themes (or templates) “skins.” This plugin is designed **only** for the “elastic” skin — the only skin that supports Bootstrap and actually works. 

The rest of the skins are relics from the 80s with messy CSS/HTML and **won't work** with this plugin. 

If you have any skin built on or based on elastic (following the same HTML structure, IDs, and Bootstrap framework), it will probably work fine.

## How to install

Easy peasy:  
- Copy the folder `multiaccount_switcher` to your `plugins` directory  
- Enable it in `config.inc.php` by adding `'multiaccount_switcher'` to the plugins array  
- Example:  
  ```bash
  git clone http://github.com/captainerd/roundcube-plugin-multiaccount plugins/multiaccount_switcher
# roundcube-plugin-multiaccount
# roundcube-plugin-multiaccount
