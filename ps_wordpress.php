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

// ==================================================================
// Initial Preparation

/**
 * Sitehost
 */
task('sitehost:ssh', function () {
    //Test if ssh keys for deployments have been generated.
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

task('sitehost:prepare', [
    'sitehost:ssh'
]);

// ==================================================================
// Ongoing Development

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

task('confirm', function () {
    if (!askConfirmation('Are you sure you want to deploy to production?')) {
        writeln('Ok, quitting.');
        throw new GracefulShutdownException('User aborted the deployment.');
    }
})->select('stage=prod');

desc('Deploy theme');
task('deploy', [
    'confirm',
    'deploy:prepare',
    'deploy:publish',
    'wordpress:theme:symlink',
]);

after('deploy:failed', 'deploy:unlock');
