<?php

namespace Deployer;

use Deployer\Exception\GracefulShutdownException;

require __DIR__ . '/ps_base.php';

set('keep_releases', 5);
set('writable_mode', 'chmod');
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

    // Stage 1: Comment out WP_DEBUG
    run("sed -i \"s|^define('WP_DEBUG', false);|// define('WP_DEBUG', false);|\" {$wpConfig}");
    writeln('<info>WP_DEBUG line commented out in ' . $wpConfig . '</info>');

    // Stage 2: Insert require_once above the "stop editing" line (only if not already present)
    run("grep -qF \"require_once(ABSPATH . 'wp-config-env.php');\" {$wpConfig} || sed -i \"/\\/\\* That's all, stop editing! Happy blogging. \\*\\//i require_once(ABSPATH . 'wp-config-env.php');\" {$wpConfig}");
    writeln('<info>require_once wp-config-env.php inserted in ' . $wpConfig . '</info>');
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

task('savefromremote:assets', function () {
    writeln('<info>Save assets from SiteHost</info>');
    writeln('<comment>Running rsync command rsync -avhzrP {{remote_user}}@{{alias}}:{{shared_path}}/wp-content/uploads/ ./wp-content/uploads/</comment>');
    //-a, –archive | -v, –verbose | -h, –human-readable | -z, –compress | r, –recursive | -P,  --partial and --progress
    runLocally('rsync -avhzrP {{remote_user}}@{{alias}}:{{shared_path}}/wp-content/uploads/ ./wp-content/uploads/', ['timeout' => 1800]);

    writeln('<info>==============</info>');
    writeln('<info>Done!</info>');
    writeln('<info>==============</info>');
});

/**
 * Syncs the database and uploads from a remote environment (uat or prod) to the local machine.
 *
 * Prompts for the source environment, then:
 * - Exports the remote database via wp-cli and imports it locally.
 * - Runs a search-replace to swap the remote site URL for the local URL.
 * - Rsyncs wp-content/uploads from the remote server to the local project.
 * - Flushes rewrite rules locally.
 *
 * Requires `local_url` to be set in the host configuration.
 */
task('syncfromremote', function () {

    $stages = array_values(array_unique(array_filter(
        array_map(fn($host) => $host->get('labels')['stage'] ?? null, Deployer::get()->hosts->toArray())
    )));

    $stage = askChoice(
        'Which environment do you want to sync FROM?',
        $stages,
        0
    );

    // Apply stage selection
    on(select("stage={$stage}"), function ($host) {

        if (!askConfirmation("This will OVERWRITE your LOCAL database and uploads from {$host->getHostname()}. Continue?")) {
            writeln('Aborting sync.');
            return;
        }

        writeln("<info>Syncing from {$host->getHostname()} ({$host->get('labels')['stage']})</info>");

        writeln('<comment>Detecting remote URL...</comment>');

        $remoteUrl = run("cd {{shared_path}} && wp option get siteurl --allow-root");

        $localUrl = 'http://' . get('local_url');

        writeln("<info>Remote URL: {$remoteUrl}</info>");
        writeln("<info>Local URL: {$localUrl}</info>");

        writeln('<comment>Syncing database...</comment>');

        runLocally(
            "ssh {{remote_user}}@{{hostname}} 'cd {{shared_path}} && wp db export - --allow-root' | wp db import -",
            ['timeout' => 1800]
        );

        writeln('<info>Database imported locally</info>');

        writeln('<comment>Running search-replace...</comment>');

        runLocally(
            "wp search-replace '{$remoteUrl}' '{$localUrl}' --all-tables --precise --skip-columns=guid",
            ['timeout' => 1800]
        );

        writeln('<info>URLs updated</info>');

        writeln('<comment>Syncing uploads...</comment>');

        runLocally(
            "rsync -avhzrP {{remote_user}}@{{hostname}}:{{shared_path}}/wp-content/uploads/ ./wp-content/uploads/",
            ['timeout' => 1800]
        );

        writeln('<info>Uploads synced</info>');

        runLocally("wp rewrite flush");

        writeln('<info>Permalinks flushed</info>');
        writeln('<info>Sync complete</info>');
    });
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

    // Use scp to upload the file from local to remote, like loadtoremote:assets
    $remoteUser = get('remote_user');
    $alias = get('alias');
    $remotePath = '/container/application/public/wp-config-env.php';
    writeln("<comment>Running scp command: scp $localFile $remoteUser@$alias:$remotePath</comment>");
    runLocally("scp $localFile $remoteUser@$alias:$remotePath", ['timeout' => 60]);
    writeln('<info>wp-config-env.php uploaded to /container/application/public/</info>');
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
]);

after('deploy:failed', 'deploy:unlock');
