<?php

namespace PlasticStudio\Deployer;

require 'recipe/common.php';

set('keep_releases', 5);

set('writable_mode', 'chmod');
set('deploy_path', '/container/application');
set('current_path', '/container/application/public');
set('identity_file', '~/.ssh/id_rsa');

task('prepare:sitehost', function () {
    //TODO: Set up sitehost container via api


    //If the public folder is a directory and not a symlink, then we need to remove it
    //This should only happen on creation of a server
    if (test('[ ! -L {{current_path}} ] && [ -d {{current_path}} ]')) {
        writeln('Public web root is a Directory - So we can symlink this on deployment');
        run('rm -rf {{current_path}}');
    } else {
        writeln('Public web root is a symlink - skipping');
    }

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

    //Update php config to default
    if (test('[ ! -f ~/container/config/php/conf.d/ps-custom.ini ]')) {
        writeln('No default custom php has been configured');
        writeln('Creating "~/container/config/php/conf.d/ps-custom.ini" and adding defaults');
        run('echo "memory_limit=256M" >> ~/container/config/php/conf.d/ps-custom.ini');
    //TODO: POST_MAX
        //TODO: EXECUTION TIME
        //TODO: UPLOAD_MAX
    } else {
        writeln('php has been configured - skipping');
    }

    //Future default .env file based on inputs, this might need to be in "first"
});

/**
 * Silverstripe configuration
 */
set('shared_assets', function () {
    if (test('[ -d {{release_path}}/public ]') || test('[ -d {{deploy_path}}/shared/public ]')) {
        return 'public/assets';
    }
    return 'assets';
});

// Shared files/dirs between deploys
set('shared_dirs', [
    '{{shared_assets}}'
]);

//if ss3 project use _ss_environment.php
set('shared_files', ['.env']);

// Silverstripe writable dirs
set('writable_dirs', [
    '{{shared_assets}}'
]);

/**
 *  Silverstripe cli script
 */
set('silverstripe_cli_script', function () {
    $paths = [
        'framework/cli-script.php',
        'vendor/silverstripe/framework/cli-script.php'
    ];
    foreach ($paths as $path) {
        if (test('[ -f {{release_path}}/' . $path . ' ]')) {
            return $path;
        }
    }
});

/**
 * Helper tasks
 */
task('silverstripe:build', function () {
    run('{{bin/php}} {{release_path}}/{{silverstripe_cli_script}} /dev/build');
})->desc('Run /dev/build');
task('silverstripe:buildflush', function () {
    run('{{bin/php}} {{release_path}}/{{silverstripe_cli_script}} /dev/build flush=all');
})->desc('Run /dev/build?flush=all');

/**
 * If deploy to production, then ask to be sure
 */
task('confirm', function () {
    if (!askConfirmation('Are you sure you want to deploy to production?')) {
        writeln('Ok, quitting.');
        die;
    }
})->select('stage=prod');

task('savefromremote', [
    'savefromremote:db',
    'savefromremote:assets'
]);

/**
 * Save DB from server.
 * Grabs the most recent backup i.e. previous nights DB
 */
task('savefromremote:db', function () {
    writeln('<info>Retrieving db from SiteHost</info>');
    writeln('<comment>Running rsync command "rsync -avhzrP {{remote_user}}@{{alias}}:{{remote_db_backup_path}} ./from-remote/"</comment>');
    //-a, –archive | -v, –verbose | -h, –human-readable | -z, –compress | r, –recursive | -P,  --partial and --progress
    runLocally('rsync -aqzrP {{remote_user}}@{{alias}}:{{remote_db_backup_path}} ./from-remote/', ['timeout' => 1800]);
    writeln('<info>Done!</info>');
});

/**
 * Save Assets from server.
 * Grabs the most recent backup i.e. previous nights Assets
 */
task('savefromremote:assets', function () {
    writeln('<info>Save assets from SiteHost</info>');
    writeln('<comment>Running rsync command rsync -avhzrP {{remote_user}}@{{alias}}:{{remote_assets_backup_path}} ./from-remote/</comment>');
    //-a, –archive | -v, –verbose | -h, –human-readable | -z, –compress | r, –recursive | -P,  --partial and --progress
    runLocally('rsync -avhzrP  {{remote_user}}@{{alias}}:{{remote_assets_backup_path}} ./from-remote/', ['timeout' => 1800]);

    writeln('<info>==============</info>');
    writeln('<info>Done!</info>');
    writeln('<info>==============</info>');
});

/**
 * Load local assets to server
 * Makes a temporary copy of current live assets, rolls back to this if there is a transfer issue.
 */
task('loadtoremote:assets', function () {
    writeln('<info>Backing up remote assets to temporary directory</info>');
    writeln('<comment>Running mv assets/ assets-backup/</comment>');
    //Make assets directory if not exists
    run('mkdir -p {{remote_assets_path}}');
    run('mv {{remote_assets_path}} /container/application/shared/assets-backup');
    writeln('<info>Backup copy complete.</info>');
    writeln('<info>------------------------------------------------------------</info>');

    writeln('<info>Loading assets to SiteHost</info>');
    writeln('<comment>Running rsync command rsync -avP --delete {{local_assets_path}} {{remote_user}}@{{alias}}:{{remote_assets_path}}</comment>');
    //-a, –archive | -v, –verbose | -h, –human-readable | -z, –compress | r, –recursive | -P,  --partial and --progress
    runLocally('rsync -avP --delete /var/www/html/{{shared_assets}}/ {{remote_user}}@{{alias}}:{{remote_assets_path}}', ['timeout' => 1800]);
    writeln('<info>Sucessful transfer!</info>');

    writeln('<comment>Deleting /assets-backup/ from server</comment>');
    run('rm -rf /container/application/shared/assets-backup');

    writeln('<info>============================================================</info>');
    writeln('<info>Done!</info>');
    writeln('<info>============================================================</info>');
});

/**
 * Roll back if transfer failure
 */
fail('loadtoremote:assets', 'loadtoremote:assets:failed');

task('loadtoremote:assets:failed', function () {
    writeln('<info>Rolling back!</info>');
    run('mv /container/application/shared/assets-backup /container/application/shared/assets');
    writeln('<info>Succesfully rolled back assets to current live version.</info>');
});


// Tasks
desc('Deploy your project');
task('deploy', [
    'confirm',
    'deploy:prepare',
    'deploy:vendors',
    // TODO: check if required 'deploy:clear_paths',
    'silverstripe:buildflush',
    'deploy:publish'
    // TODO: restart sitehost after deployment
]);

// before('deploy', 'what_branch');

// [Optional] If deploy fails automatically unlock.
after('deploy:failed', 'deploy:unlock');
