<?php

namespace Deployer;

use Deployer\Exception\GracefulShutdownException;

set('keep_releases', 5);
set('writable_mode', 'chmod');
set('remote_db_backup_path', '/container/backups/containers/latest/databases/');
set('deploy_path', '/container/application/theme');
set('identity_file', '~/.ssh/id_rsa');
set('shared_path', '/container/application/shared');
set('sitehost_restart_mode', 'container');
set('sub_directory', 'wp-content/themes');

set('shared_dirs', []);
set('shared_files', []);
set('writable_dirs', []);

task('wordpress:theme:symlink', function () {
    $wpThemeDir = '{{shared_path}}/{{sub_directory}}/{{theme_folder}}';
    $deployerCurrent = '{{deploy_path}}/current';

    if (test('[ ! -L ' . $wpThemeDir . ' ] && [ -d ' . $wpThemeDir . ' ]')) {
        writeln('<comment>Theme directory exists as real dir — moving aside to {{theme_folder}}-backup</comment>');
        run('mv ' . $wpThemeDir . ' ' . $wpThemeDir . '-backup');
    }

    run('ln -sfn ' . $deployerCurrent . ' ' . $wpThemeDir);
    writeln('<info>Symlinked: ' . $wpThemeDir . ' → ' . $deployerCurrent . '</info>');
});

task('confirm', function () {
    if (!askConfirmation('Are you sure you want to deploy to production?')) {
        writeln('Ok, quitting.');
        throw new GracefulShutdownException('User aborted the deployment.');
    }
})->select('stage=prod');

// Sitehost tasks (copied from ps_silverstripe.php, only what WP needs)
task('sitehost:restart', function () {
    if (get('sitehost_restart_mode') == 'apache-php') {
        writeln('<info>Restarting apache & php</info>');
        run('supervisorctl restart apache2 php');
        return;
    }

    if (testLocally('[ -f /var/www/sitehost-api-key.txt ]')) {
        $config = file_get_contents('/var/www/sitehost-api-key.txt');
        set('sitehost_api_key', trim($config));
    }

    if (!get('sitehost_api_key')) {
        writeln('<error>SKIPPING SITEHOST RESTART - sitehost_api_key not set</error>');
        return;
    }
    if (!get('sitehost_client_id')) {
        writeln('<error>SKIPPING SITEHOST RESTART - sitehost_client_id not set</error>');
        return;
    }
    if (!get('sitehost_server_name')) {
        writeln('<error>SKIPPING SITEHOST RESTART - sitehost_server_name not set</error>');
        return;
    }
    if (!get('sitehost_stack_name')) {
        writeln('<error>SKIPPING SITEHOST RESTART - sitehost_stack_name not set</error>');
        return;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.sitehost.nz/1.2/cloud/stack/restart.json");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, [
        'apikey'    => get('sitehost_api_key'),
        'client_id' => get('sitehost_client_id'),
        'server'    => get('sitehost_server_name'),
        'name'      => get('sitehost_stack_name'),
    ]);
    writeln('<info>Triggering container restart {{sitehost_stack_name}} on {{sitehost_server_name}}</info>');
    $response = curl_exec($ch);
    writeln('<info>Response from Sitehost: ' . $response . '</info>');
    curl_close($ch);
});

desc('Deploy theme');
task('deploy', [
    'confirm',
    'deploy:prepare',
    'deploy:publish',
    'wordpress:theme:symlink',
    'sitehost:restart',
]);

after('deploy:failed', 'deploy:unlock');
