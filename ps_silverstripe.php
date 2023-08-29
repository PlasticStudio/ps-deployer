<?php

namespace Deployer;

use Deployer\Exception\GracefulShutdownException;

require 'recipe/common.php';

// set('dotenv', '{{current_path}}/.env');
set('keep_releases', 5);
set('writable_mode', 'chmod');
set('remote_db_backup_path', '/container/backups/containers/latest/databases/');
set('deploy_path', '/container/application');
set('current_path', '/container/application/public');
set('identity_file', '~/.ssh/id_rsa');
set('upgrade_path', '/container/application/upgrade');
set('shared_path', '/container/application/shared');

/**
 * Sitehost - this is the upgrade script from mysql 5.7 to 8
 * This will immediately make the changes to the environment
 */
task('sitehost:upgrade-mysql', function () {

    if (!askConfirmation('Are you sure you want to upgrade - this will immediately make the changes to the environment?')) {
        writeln('Ok, quitting.');
        throw new GracefulShutdownException('User aborted the deployment.');
    }

    //1) Export current 
    writeln('mkdir to save contents - {{upgrade_path}}');
    run(" mkdir -p {{upgrade_path}}");    
    writeln('Export using .env details');
    run("cd {{shared_path}} && export $(grep -v '^#' .env | xargs) && mysqldump -u \$SS_DATABASE_USERNAME -p\$SS_DATABASE_PASSWORD -h \$SS_DATABASE_SERVER --column-statistics=0 --no-tablespaces \$SS_DATABASE_NAME > {{upgrade_path}}/mysql57-backup.sql");
    writeln('Finished exporting db');

    //2) Set up new db fields
    $env_SS_DATABASE_SERVER = ask('SS_DATABASE_SERVER', 'mysql8');
    $env_SS_DATABASE_NAME = ask('SS_DATABASE_NAME');
    $env_SS_DATABASE_USERNAME = ask('SS_DATABASE_USERNAME');
    $env_SS_DATABASE_PASSWORD = ask('SS_DATABASE_PASSWORD');

    //3) Import into new db
    writeln('Import db into new '.$env_SS_DATABASE_SERVER.' - '.$env_SS_DATABASE_NAME);
    run("mysql -u ".$env_SS_DATABASE_USERNAME." -p'".$env_SS_DATABASE_PASSWORD."' -h ".$env_SS_DATABASE_SERVER." ".$env_SS_DATABASE_NAME." < {{upgrade_path}}/mysql57-backup.sql");

    //4) make backup of .env and update .env file
    writeln('Backup current .env to {{upgrade_path}}/.env.backup');
    run('cp {{shared_path}}/.env {{upgrade_path}}/.env.backup');
    writeln('Overwrite .env with new db details');
    run('sed -i "s/SS_DATABASE_SERVER=\".*\"/SS_DATABASE_SERVER=\"'.$env_SS_DATABASE_SERVER.'\"/g" {{shared_path}}/.env');
    run('sed -i "s/SS_DATABASE_NAME=\".*\"/SS_DATABASE_NAME=\"'.$env_SS_DATABASE_NAME.'\"/g" {{shared_path}}/.env');
    run('sed -i "s/SS_DATABASE_USERNAME=\".*\"/SS_DATABASE_USERNAME=\"'.$env_SS_DATABASE_USERNAME.'\"/g" {{shared_path}}/.env');
    run('sed -i "s/SS_DATABASE_PASSWORD=\".*\"/SS_DATABASE_PASSWORD=\"'.$env_SS_DATABASE_PASSWORD.'\"/g" {{shared_path}}/.env');    
    writeln('Finshed - go test website - if there are issues, rollback using dep sitehost:upgrade-mysql:rollback to swap .env files back');
});

/**
 * Sitehost - Roll back to old .env
 */
task('sitehost:upgrade-mysql:rollback', function () {
    run('cp {{upgrade_path}}/.env.backup {{shared_path}}/.env');
});


task('sitehost:prepare', [
    'sitehost:symlink',
    'sitehost:ssh',
    'sitehost:phpconfig',
    'sitehost:listreleases'
]);


//deploy post Sitehost major upgrade
task('sitehost:prepare:deploy', [
    'sitehost:prepare',
    'deploy'
]);

//Future default .env file based on inputs, this might need to be in "first"
//TODO: Set up sitehost container via api

/**
 * Sitehost
 */
task('sitehost:symlink', function () {
    //If the public folder is a directory and not a symlink, then we need to remove it
    //This should only happen on creation of a server
    if (test('[ ! -L {{current_path}} ] && [ -d {{current_path}} ]')) {
        writeln('Public web root is a Directory - So we can symlink this on deployment');
        run('rm -rf {{current_path}}');
    } else {
        writeln('Public web root is a symlink - skipping');
    }
});

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

/**
 * Sitehost
 */
task('sitehost:phpconfig', function () {
    //Update php config to default
    if (test('[ ! -f ~/container/config/php/conf.d/ps-custom.ini ]')) {
        writeln('No default custom php has been configured');
        writeln('Creating "~/container/config/php/conf.d/ps-custom.ini" and adding defaults');
        run('echo "memory_limit=512M" >> ~/container/config/php/conf.d/ps-custom.ini');
    //TODO: POST_MAX
    //TODO: EXECUTION TIME
    //TODO: UPLOAD_MAX
    } else {
        writeln('php has been configured - skipping');
    }
});

/**
 * Sitehost
 */
task('sitehost:listreleases', function () {
    if (test('[ -d ~/container/application/releases ]')) {
        run('ls ~/container/application/releases', ['real_time_output' => true]);
    } else {
        writeln('No releases yet - skipping');
    }
});



task('sitehost:restart', function () {
    if (testLocally('[ -f /var/www/sitehost-api-key.txt ]')) {
        $config = file_get_contents('/var/www/sitehost-api-key.txt');
        set('sitehost_api_key', trim($config));
    }

    if (!get('sitehost_api_key')) {
        writeln('<error>SKIPPING SITEHOST RESTART - sitehost_api_key not set - You may need to add a the sitehost-api-key.txt to your parent directory or update your docker image</error>');
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
    $body = array(
        'apikey' => get('sitehost_api_key'),
        'client_id' => get('sitehost_client_id'),
        'server' => get('sitehost_server_name'),
        'name' => get('sitehost_stack_name'),
    );
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    writeln('<info>Trigger a containter restart {{sitehost_stack_name}} on {{sitehost_server_name}}</info>');

    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    writeln('<info>Response from Sitehost: ' . $response . '</info>');

    curl_close($ch);

    //Todo loop over and wait for success response
});






/**
 * Silverstripe configuration
 */
set('shared_assets', function () {
    if (test('[ -d {{deploy_path}}/release/public ]') || test('[ -d {{deploy_path}}/shared/public ]')) {
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
        throw new GracefulShutdownException('User aborted the deployment.');

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
    'deploy:publish',
    'sitehost:restart'
]);


// before('deploy', 'what_branch');

// [Optional] If deploy fails automatically unlock.
after('deploy:failed', 'deploy:unlock');
