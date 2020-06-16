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
set('keep_releases', 5);

//Staging
host('sitehost-domain.co.nz')
    ->user('stagesshuser')
    ->stage('staging')
    ->roles('app')
    ->set('http_user', 'stagesshuser');

//Production
// host('convex-prod.plasticstudio.co.nz')
//     ->user('convexproduser')
//     ->stage('production')
//     ->roles('app')
//     ->set('http_user', 'convexproduser');

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