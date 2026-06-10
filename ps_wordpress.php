<?php

namespace Deployer;

use Deployer\Exception\GracefulShutdownException;

require __DIR__ . '/ps_base.php';

set('keep_releases', 5);
set('writable_mode', 'chmod');
set('remote_db_backup_path', '/container/backups/containers/latest/databases');
set('remote_assets_backup_path', '/container/backups/containers/latest/application/shared/public/assets');
set('deploy_path', '/container/application/theme');
set('identity_file', '~/.ssh/id_rsa');
set('shared_path', '/container/application/public');
set('sitehost_restart_mode', 'container');
set('sub_directory', 'wp-content/themes');

set('shared_dirs', []);
set('shared_files', []);
set('writable_dirs', []);

// ==================================================================
// Initial Preparation

/**
 * Checks if wp-cli is installed on the remote server.
 * If not found, downloads and installs it to ~/bin/wp.
 */
task('sitehost:wpcli', function () {
    if (test('command -v wp')) {
        writeln('<info>wp-cli is already installed: ' . run('wp --info --allow-root 2>&1 | head -1') . '</info>');
    } else {
        writeln('wp-cli not found — installing...');
        run('mkdir -p ~/bin');
        run('curl -sS -o ~/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar');
        run('chmod +x ~/bin/wp');
        run('echo "export PATH=\$HOME/bin:\$PATH" >> ~/.bashrc');
        writeln('<info>wp-cli installed to ~/bin/wp</info>');
        writeln('<comment>Note: You may need to reconnect or run `source ~/.bashrc` for `wp` to be available in PATH.</comment>');
    }
});

/**
 * Checks for an existing SSH key on the remote server.
 * If none is found, generates a new RSA key and outputs the public key
 * to be added as a deploy key on GitHub.
 */
task('sitehost:ssh', function () {
    if (test('[ ! -f ~/.ssh/id_rsa ]')) {
        writeln('Generating new ssh key');
        run('ssh-keygen -f ~/.ssh/id_rsa -t rsa -N ""');
        run('cat ~/.ssh/id_rsa.pub', ['real_time_output' => true]);
        writeln('Copy this key to the projects deploy keys on github');
    } else {
        writeln('ssh key found - skipping');
        run('cat ~/.ssh/id_rsa.pub', ['real_time_output' => true]);
        writeln('Copy this key to the projects deploy keys on github');
    }
});

/**
 * Modifies wp-config.php on the remote server:
 * - Comments out the WP_DEBUG definition.
 * - Inserts a require_once for wp-config-env.php above the "stop editing" line.
 */
task('sitehost:config', function () {
    $wpConfig = '/container/application/public/wp-config.php';
    $wpConfigEnv = '/container/application/public/wp-config-env.php';

    // Stage 1: Comment out WP_DEBUG
    run("sed -i \"s|^define('WP_DEBUG', false);|// define('WP_DEBUG', false);|\" {$wpConfig}");
    writeln('<info>WP_DEBUG line commented out in ' . $wpConfig . '</info>');

    // Stage 2: Insert require_once above the "stop editing" line (only if not already present)
    run("grep -qF \"require_once(ABSPATH . 'wp-config-env.php');\" {$wpConfig} || sed -i \"/\\/\\* That's all, stop editing! Happy blogging. \\*\\//i require_once(ABSPATH . 'wp-config-env.php');\" {$wpConfig}");
    writeln('<info>require_once wp-config-env.php inserted in ' . $wpConfig . '</info>');

    // Stage 3: Touch wp-config-env.php with <?php at the start (only if not already present)
    run("[ -f {$wpConfigEnv} ] || echo '<?php' > {$wpConfigEnv}");
    writeln('<info>wp-config-env.php created at ' . $wpConfigEnv . '</info>');
});

/**
 * Runs initial server preparation steps: SSH key setup and wp-config modifications.
 */
task('sitehost:prepare', [
    'sitehost:wpcli',
    'sitehost:ssh',
    'sitehost:config',
]);

// ==================================================================
// Ongoing Development

task('savefromremote', [
    'savefromremote:db',
    'savefromremote:plugins',
    'savefromremote:assets'
]);

task('savefromremote:latest', [
    'sitehost:backup',
    'savefromremote:db',
    'savefromremote:plugins',
    'savefromremote:assets'
]);

task('syncfromremote', [
    'syncfromremote:confirm',
    'syncfromremote:db',
    'syncfromremote:plugins',
    'syncfromremote:assets'
]);

task('syncfromremote:latest', [
    'syncfromremote:confirm',
    'sitehost:backup',
    'syncfromremote:db',
    'syncfromremote:plugins',
    'syncfromremote:assets'
]);

task('sitehost:backup', function () {
    if (testLocally('[ -f /var/www/sitehost-api-key.txt ]')) {
        $config = file_get_contents('/var/www/sitehost-api-key.txt');
        set('sitehost_api_key', trim($config));
    }

    if (!get('sitehost_api_key')) {
        writeln('<error>SKIPPING SITEHOST BACKUP - sitehost_api_key not set - You may need to add a the sitehost-api-key.txt to your parent directory or update your docker image</error>');
        return;
    }

    if (!get('sitehost_client_id')) {
        writeln('<error>SKIPPING SITEHOST BACKUP - sitehost_client_id not set</error>');
        return;
    }

    if (!get('sitehost_server_name')) {
        writeln('<error>SKIPPING SITEHOST BACKUP - sitehost_server_name not set</error>');
        return;
    }

    if (!get('sitehost_stack_name')) {
        writeln('<error>SKIPPING SITEHOST BACKUP - sitehost_stack_name not set</error>');
        return;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.sitehost.nz/1.2/cloud/stack/backup.json");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    $body = array(
        'apikey' => get('sitehost_api_key'),
        'client_id' => get('sitehost_client_id'),
        'server' => get('sitehost_server_name'),
        'name' => get('sitehost_stack_name'),
    );
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    writeln('<info>Trigger a containter backup {{sitehost_stack_name}} on {{sitehost_server_name}}</info>');

    $backupResponse = curl_exec($ch);
    $backupStatusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    writeln('<info>Response from Sitehost: ' . $backupResponse . '</info>');

    $backupResponse = json_decode($backupResponse, true);

    // if response.status is true, start looping the job endpoint to wait for a completed response
    if ($backupStatusCode == 200 && isset($backupResponse['return']) && isset($backupResponse['return']['job_id'])) {

        writeln('<info>Waiting for backup to complete...</info>');

        $job_url = "https://api.sitehost.nz/1.2/job/get.json?apikey=" . get('sitehost_api_key') . "&job_id=" . $backupResponse['return']['job_id'] . "&type=scheduler"; // scheduler or daemon

        $ch = curl_init();

        // Loop until the job is completed
        do {
            sleep(5); //. Check every 5 seconds for completed backup

            curl_setopt($ch, CURLOPT_URL, $job_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            $jobResponse = curl_exec($ch);
            $jobStatusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            // Decode the response and check the status
            $jobStatus = json_decode($jobResponse, true);
        } while ($jobStatus['return']['state'] != 'Completed');

        writeln('<info>Backup completed</info>');
    }
});

task('syncfromremote:confirm', function () {
    if (!askConfirmation('This will OVERWRITE your LOCAL database, plugins and uploads. Continue?')) {
        writeln('Aborting sync.');
        throw new GracefulShutdownException('User aborted the sync.');
    }
});

task('savefromremote:db', function () {
    writeln('<info>Retrieving db from SiteHost</info>');
    writeln('<comment>Running rsync command "rsync -avhzrP {{remote_user}}@{{alias}}:{{remote_db_backup_path}} ./from-remote/"</comment>');
    //-a, –archive | -v, –verbose | -h, –human-readable | -z, –compress | r, –recursive | -P,  --partial and --progress
    runLocally('rsync -aqzrP {{remote_user}}@{{alias}}:{{remote_db_backup_path}} ./from-remote/', ['timeout' => 1800]);
    writeln('<info>Done!</info>');
});

task('syncfromremote:db', function () {
    $remoteUser = get('remote_user');
    $hostname   = get('hostname');
    $sharedPath = get('shared_path');
    $localUrl   = 'http://' . get('local_url');

    $remoteUrl = trim(run("cd {$sharedPath} && wp option get siteurl --allow-root"));

    writeln("<info>Remote URL: {$remoteUrl}</info>");
    writeln("<info>Local URL: {$localUrl}</info>");

    writeln('<comment>Syncing database...</comment>');
    runLocally(
        "ssh {$remoteUser}@{$hostname} 'cd {$sharedPath} && wp db export - --allow-root' | wp db import -",
        ['timeout' => 1800]
    );

    writeln('<comment>Running search-replace...</comment>');
    runLocally(
        "wp search-replace '{$remoteUrl}' '{$localUrl}' --all-tables --precise --skip-columns=guid",
        ['timeout' => 1800]
    );

    runLocally("wp cache flush");
    runLocally("wp rewrite flush --hard");
    writeln('<info>Database sync complete</info>');
});

task('savefromremote:assets', function () {
    writeln('<info>Save assets from SiteHost</info>');
    writeln('<comment>Running rsync command rsync -avhzrP {{remote_user}}@{{alias}}:{{shared_path}}/wp-content/uploads/ ./wp-content/uploads/</comment>');
    //-a, –archive | -v, –verbose | -h, –human-readable | -z, –compress | r, –recursive | -P,  --partial and --progress
    runLocally('rsync -avhzrP {{remote_user}}@{{alias}}:{{shared_path}}/wp-content/uploads/ ./wp-content/uploads/', ['timeout' => 1800]);
    writeln('<info>Done!</info>');
});

task('syncfromremote:assets', function () {
    writeln('<info>Save assets from SiteHost</info>');
    writeln('<comment>Note: These replace your local wp-content/uploads directory</comment>');
    writeln('<comment>Running rsync command rsync -avhzrP {{remote_user}}@{{alias}}:{{shared_path}}/wp-content/uploads/ ./wp-content/uploads/</comment>');
    // Clear existing uploads before syncing to avoid deleted files lingering in the uploads directory
    runLocally('rm -rf ./wp-content/uploads/*');
    //-a, –archive | -v, –verbose | -h, –human-readable | -z, –compress | r, –recursive | -P,  --partial and --progress
    runLocally('rsync -avhzrP {{remote_user}}@{{alias}}:{{shared_path}}/wp-content/uploads/ ./wp-content/uploads/', ['timeout' => 1800]);
    writeln('<info>Done!</info>');
});

task('savefromremote:plugins', function () {
    writeln('<info>Save plugins from SiteHost</info>');
    writeln('<comment>Running rsync command rsync -avhzrP {{remote_user}}@{{alias}}:{{shared_path}}/wp-content/plugins/ ./wp-content/plugins/</comment>');
    //-a, –archive | -v, –verbose | -h, –human-readable | -z, –compress | r, –recursive | -P,  --partial and --progress
    runLocally('rsync -avhzrP {{remote_user}}@{{alias}}:{{shared_path}}/wp-content/plugins/ ./wp-content/plugins/', ['timeout' => 1800]);
    writeln('<info>Done!</info>');
});

task('syncfromremote:plugins', function () {
    writeln('<info>Save plugins from SiteHost</info>');
    writeln('<comment>Note: These replace your local wp-content/plugins directory</comment>');
    writeln('<comment>Running rsync command rsync -avhzrP {{remote_user}}@{{alias}}:{{shared_path}}/wp-content/plugins/ ./wp-content/plugins/</comment>');
    // Clear existing plugins before syncing to avoid deleted plugins lingering in the plugins directory
    runLocally('rm -rf ./wp-content/plugins/*');
    //-a, –archive | -v, –verbose | -h, –human-readable | -z, –compress | r, –recursive | -P,  --partial and --progress
    runLocally('rsync -avhzrP {{remote_user}}@{{alias}}:{{shared_path}}/wp-content/plugins/ ./wp-content/plugins/', ['timeout' => 1800]);
    writeln('<info>Done!</info>');
});

task('synctoremote', [
    'synctoremote:confirm',
    'synctoremote:doubleconfirm',
    'sitehost:backup',
    'synctoremote:plugins',
    'synctoremote:assets',
    'synctoremote:db',
]);

task('synctoremote:confirm', function () {
    $siteUrl = get('site_url');
    $input = ask("You are about to OVERWRITE the REMOTE database, plugins and uploads on {$siteUrl}. Type the site URL to confirm: ");
    
    if (trim($input) !== $siteUrl) {
        writeln('<error>Input did not match. Aborting.</error>');
        throw new GracefulShutdownException('User aborted the sync.');
    }

    writeln("<info>Confirmed. Taking a backup and syncing to {$siteUrl}...</info>");
});

task('synctoremote:doubleconfirm', function () {
    if (!askConfirmation('WARNING: Are you sure you want to sync your local environment to production?')) {
        writeln('Ok, quitting.');
        throw new GracefulShutdownException('User aborted the sync.');
    }
})->select('stage=prod');

task('synctoremote:plugins', function () {
    $remoteUser = get('remote_user');
    $hostname   = get('hostname');
    $sharedPath = get('shared_path');

    writeln('<info>Syncing plugins to remote...</info>');
    writeln('<comment>Note: This replaces the remote wp-content/plugins directory</comment>');

    run("rm -rf {$sharedPath}/wp-content/plugins/*");
    runLocally(
        "rsync -avhzrP ./wp-content/plugins/ {$remoteUser}@{$hostname}:{$sharedPath}/wp-content/plugins/",
        ['timeout' => 1800]
    );

    writeln('<info>Done!</info>');
});

task('synctoremote:assets', function () {
    $remoteUser = get('remote_user');
    $hostname   = get('hostname');
    $sharedPath = get('shared_path');

    writeln('<info>Syncing uploads to remote...</info>');
    writeln('<comment>Note: This replaces the remote wp-content/uploads directory</comment>');

    run("rm -rf {$sharedPath}/wp-content/uploads/*");
    runLocally(
        "rsync -avhzrP ./wp-content/uploads/ {$remoteUser}@{$hostname}:{$sharedPath}/wp-content/uploads/",
        ['timeout' => 1800]
    );

    writeln('<info>Done!</info>');
});

task('synctoremote:db', function () {
    $remoteUser = get('remote_user');
    $hostname   = get('hostname');
    $sharedPath = get('shared_path');
    $localUrl   = 'http://' . get('local_url');
    $remoteUrl  = 'https://' . get('site_url');

    writeln("<info>Local URL: {$localUrl}</info>");
    writeln("<info>Remote URL: {$remoteUrl}</info>");

    writeln('<comment>Exporting local database and importing to remote...</comment>');
    runLocally(
        "wp db export - | ssh {$remoteUser}@{$hostname} 'cd {$sharedPath} && wp db import - --allow-root'",
        ['timeout' => 1800]
    );

    writeln('<comment>Running search-replace on remote...</comment>');
    run(
        "cd {$sharedPath} && wp search-replace '{$localUrl}' '{$remoteUrl}' --all-tables --precise --skip-columns=guid --allow-root",
        ['timeout' => 1800]
    );

    run("cd {$sharedPath} && wp cache flush --allow-root");
    run("cd {$sharedPath} && wp rewrite flush --hard --allow-root");
    writeln('<info>Database sync complete</info>');
});

// ==================================================================
// Deployment

/**
 * Prompts for confirmation before deploying to production.
 * Aborts the deployment if the user does not confirm.
 */
task('confirm', function () {
    if (!askConfirmation('Are you sure you want to deploy to production?')) {
        writeln('Ok, quitting.');
        throw new GracefulShutdownException('User aborted the deployment.');
    }
})->select('stage=prod');

/**
 * Uploads wp-config-env.php from the local project root to the remote server.
 * Aborts deployment if the file is not present locally.
 */
task('deploy:config', function () {
    // Find project root (3 levels up from this file)
    $localFile = dirname(__DIR__, 3) . '/wp-config-env.php';
    if (!file_exists($localFile)) {
        throw new GracefulShutdownException('wp-config-env.php not found locally. Deployment aborted.');
    }

    $remoteUser = get('remote_user');
    $alias = get('alias');
    $remotePath = '/container/application/public/';
    writeln("<comment>Running rsync command: rsync -avP $localFile $remoteUser@$alias:$remotePath</comment>");
    runLocally("rsync -avP $localFile $remoteUser@$alias:$remotePath", ['timeout' => 60]);
    writeln('<info>wp-config-env.php uploaded to /container/application/public/</info>');
});

/**
 * Runs composer install in the theme directory if composer.json is present.
 */
task('composer:install', function () {
    $themePath = '{{release_path}}/{{theme_folder}}';
    $lsOutput = run('ls -lah ' . $themePath . ' || echo "[theme directory missing]"');
    writeln($lsOutput);
    // $realPath = run('cd ' . $themePath . ' && pwd || echo "[theme directory missing]"');
    $composerExists = test('[ -f ' . $themePath . '/composer.json ]');
    if ($composerExists) {
        run('cd ' . $themePath . ' && composer install --no-dev --optimize-autoloader');
        writeln('<info>Composer install complete in theme directory</info>');
    } else {
        writeln('<comment>No composer.json found in theme directory — skipping composer install for theme</comment>');
    }
});

/**
 * Creates a symlink from the shared WordPress theme directory to the current
 * Deployer release. Moves aside any existing non-symlinked theme directory.
 */
task('wordpress:theme:symlink', function () {
    $wpThemeDir = '{{shared_path}}/{{sub_directory}}/{{theme_folder}}';
    $deployerCurrent = '{{deploy_path}}/current';

    run('mkdir -p ' . dirname($wpThemeDir));

    if (test('[ ! -L ' . $wpThemeDir . ' ] && [ -d ' . $wpThemeDir . ' ]')) {
        writeln('<comment>Theme directory exists as real dir — moving aside to {{theme_folder}}-backup</comment>');
        run('mv ' . $wpThemeDir . ' ' . $wpThemeDir . '-backup');
    }

    run('ln -sfn ' . $deployerCurrent . ' ' . $wpThemeDir);
    writeln('<info>Symlinked: ' . $wpThemeDir . ' → ' . $deployerCurrent . '</info>');
});

desc('Deploy theme');
task('deploy', [
    'confirm',
    'deploy:config',
    'deploy:prepare',
    'deploy:publish',
    'wordpress:theme:symlink',
    'composer:install',
]);

after('deploy:failed', 'deploy:unlock');
