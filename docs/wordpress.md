# WordPress Deployer

## Initial Setup

Run these commands once when setting up a new server.

**`dep sitehost:prepare`**
Runs all preparation steps in order: installs wp-cli, sets up an SSH key, and modifies wp-config.php.

**`dep sitehost:wpcli`**
Checks if wp-cli is installed on the remote server. If not, downloads and installs it to `~/bin/wp`.

**`dep sitehost:ssh`**
Checks for an SSH key on the remote server. If none exists, generates a new one and prints the public key to add as a GitHub deploy key.

**`dep sitehost:config`**
Modifies `wp-config.php` on the remote server. Comments out `WP_DEBUG` and adds a `require_once` for `wp-config-env.php`.

---

## Ongoing Development

**`dep savefromremote:assets`**
Downloads the uploads folder from the remote server to your local machine.

**`dep syncfromremote`**
Pulls a full copy of a remote environment down to your local machine. Prompts you to choose an environment, then syncs the database, runs a URL search-replace, and rsyncs plugins, themes, and uploads. The configured `theme_folder` is excluded from the themes sync so local child theme development is preserved. Requires `local_url` to be set in your host config.

---

## Deployment

**`dep deploy`**
Deploys the theme to the server. On production, you will be asked to confirm before anything runs. Uploads `wp-config-env.php`, runs the standard Deployer release steps, creates a symlink from the theme directory to the current release, and synchronises optional `php_config` values to `/container/config/php/php.ini`.

PHP settings can be configured per host and are only written when they differ.
For a WordPress site that handles larger uploads and heavier imports, use:

```php
host('wordpress.example.com')
    ->set('labels', ['stage' => 'prod'])
    ->set('http_user', 'wordpressuser')
    ->set('remote_user', 'wordpressuser')
    ->set('php_config', [
        'memory_limit' => '512M',
        'upload_max_filesize' => '256M',
        'post_max_size' => '300M',
        'max_execution_time' => '300',
        'max_input_time' => '300',
        'max_input_vars' => '5000',
        'max_file_uploads' => '50',
    ]);
```

`post_max_size` is intentionally higher than `upload_max_filesize` to leave
room for the rest of the request payload.
