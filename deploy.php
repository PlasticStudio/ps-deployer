<?php
namespace Deployer;
require 'recipe/common.php';

// Config
set('repository', 'git@github.com:PlasticStudio/project.git');
set('default_stage', 'staging');
set('ssh_multiplexing', true);
set('writable_mode', 'chmod');
set('forwardAgent', false);
set('deploy_path', '/container/application');
set('remote_db_backup_path', '/container/backups/latest/databases/');
set('remote_assets_backup_path', '/container/backups/latest/application/shared/assets'); //no trailing slash is important
set('remote_assets_path', '/container/application/shared/assets/');
set('local_assets_path', '/var/www/html/assets/');
set('keep_releases', 5);

//Staging
host('sitehost-domain.co.nz')
    ->user('stagesshuser')
    ->stage('staging')
    ->roles('app')
    ->set('http_user', 'stagesshuser')
    ->set('remote_user', 'stagesshuser');

//Production
// host('convex-prod.plasticstudio.co.nz')
//     ->user('convexproduser')
//     ->stage('production')
//     ->roles('app')
//     ->set('http_user', 'convexproduser')
//     ->set('remote_user', 'convexproduser');

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

// Silverstripe cli script
set('silverstripe_cli_script', function () {
    $paths = [
        'framework/cli-script.php',
        'vendor/silverstripe/framework/cli-script.php'
    ];
    foreach ($paths as $path) {
        if (test('[ -f {{release_path}}/'.$path.' ]')) {
            return $path;
        }
    }
});

/**
 * Helper tasks
 */
task('silverstripe:build', function () {
    return run('{{bin/php}} {{release_path}}/{{silverstripe_cli_script}} /dev/build');
})->desc('Run /dev/build');
task('silverstripe:buildflush', function () {
    return run('{{bin/php}} {{release_path}}/{{silverstripe_cli_script}} /dev/build flush=all');
})->desc('Run /dev/build?flush=all');

// if deploy to production, then ask to be sure
task( 'confirm', function () {
	if ( ! askConfirmation( 'Are you sure you want to deploy to production?' ) ) {
		write( 'Ok, quitting.' );
		die;
	}
} )->onStage( 'production' );

task('savefromremote', [
    'savefromremote:db',
    'savefromremote:assets'
]);

task('savefromremote:db', function () {
    writeln('<info>Retrieving db from SiteHost</info>');
    writeln( '<comment>Running rsync command "rsync -avhzrP {{remote_user}}@{{hostname}}:{{remote_db_backup_path}} ./from-remote/"</comment>' );
    //-a, –archive | -v, –verbose | -h, –human-readable | -z, –compress | r, –recursive | -P,  --partial and --progress
    runLocally('rsync -aqzrP {{remote_user}}@{{hostname}}:{{remote_db_backup_path}} ./from-remote/' , ['timeout' => 1800]);
    writeln('<info>Done!</info>');
});

task('savefromremote:assets', function () {
    writeln('<info>Save assets from SiteHost</info>');
    writeln( '<comment>Running rsync command rsync -avhzrP {{remote_user}}@{{hostname}}:{{remote_assets_backup_path}} ./from-remote/</comment>' );
    //-a, –archive | -v, –verbose | -h, –human-readable | -z, –compress | r, –recursive | -P,  --partial and --progress
    runLocally('rsync -avhzrP  {{remote_user}}@{{hostname}}:{{remote_assets_backup_path}} ./from-remote/', ['timeout' => 1800]);    
    writeln('<info>Done!</info>');
});

task('loadtoremote:assets', function () {
    writeln('<info>Load assets to SiteHost</info>');
    writeln( '<comment>Running rsync command rsync -avhn --delete {{local_assets_path}} {{remote_user}}@{{hostname}}:{{remote_assets_path}}</comment>' );
    //-a, –archive | -v, –verbose | -h, –human-readable | -z, –compress | r, –recursive | -P,  --partial and --progress
    runLocally('rsync -avhn --delete /var/www/html/{{shared_assets}}/ {{remote_user}}@{{hostname}}:{{remote_assets_path}}', ['timeout' => 1800]);    
    writeln('<info>Done!</info>');
});

// task('sspak:save', function () {
//     $file = ask('Which sspak file?');
//     // $host = server();
//     runLocally('sspak load '.$file.' {{user}}@{{hostname}}:{{release_path}}');
// });

// task('sspak:load', function () {
//     $file = ask('Which sspak file?');

//     upload('test.sspak', "{{deploy_path}}/sspak");

//     write('sspak load '.$file.' {{deploy_path}}/current' );
//     write( 'sspak loaded' );
// });

// task('what_branch', function () {
//     $branch = ask('What branch to deploy?');
//     on(roles('app'), function ($host) use ($branch) {
//         set('branch', $branch);
//     });
// })->local();

// Tasks
desc('Deploy your project');
task('deploy', [
    'confirm',
    'deploy:info',
    'deploy:prepare',
    'deploy:lock',
    'deploy:release',
    'deploy:update_code',
    'deploy:shared',
    'deploy:writable',
    'deploy:vendors',
    'deploy:clear_paths',    
    'silverstripe:buildflush',
    'deploy:symlink',
    'deploy:unlock',
    'cleanup',
    'success'
]);

// before('deploy', 'what_branch');

// [Optional] If deploy fails automatically unlock.
after('deploy:failed', 'deploy:unlock');
