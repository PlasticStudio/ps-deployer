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
Pulls a full copy of a remote environment down to your local machine. Prompts you to choose an environment, then syncs the database, runs a URL search-replace, and rsyncs uploads. Requires `local_url` to be set in your host config.

---

## Deployment

**`dep deploy`**
Deploys the theme to the server. On production, you will be asked to confirm before anything runs. Uploads `wp-config-env.php`, runs the standard Deployer release steps, and creates a symlink from the theme directory to the current release.
