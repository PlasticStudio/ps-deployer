<?php

namespace Deployer;

require 'recipe/common.php';

// Config
set('repository', 'git@github.com:PlasticStudio/skeletor.git');
set('default_stage', 'staging');
set('ssh_multiplexing', true);
set('writable_mode', 'chmod');
set('forwardAgent', false);
set('deploy_path', '/container/application');
set('remote_db_backup_path', '/container/backups/latest/databases/');
set('remote_assets_backup_path', '/container/backups/latest/application/shared/assets'); //no trailing slash is important
set('remote_assets_path', '/container/application/shared/assets/');
set('local_assets_path', '/var/www/html/public/assets/');
set('keep_releases', 5);

//Staging
host('skeletor.shuat.plasticstudio.co')
	->user('skeletoruser')
	->stage('staging')
	->roles('app')
	->set('http_user', 'skeletoruser')
	->set('remote_user', 'skeletoruser');

//Production
// host('skeletor.shuat.plasticstudio.co')
//     ->user('skeletoruser')
//     ->stage('production')
//     ->roles('app')
//     ->set('http_user', 'skeletoruser')
//     ->set('remote_user', 'skeletoruser');

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

// if deploy to production, then ask to be sure
task('confirm', function () {
	if (!askConfirmation('Are you sure you want to deploy to production?')) {
		write('Ok, quitting.');
		die;
	}
})->onStage('production');

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
	writeln('<comment>Running rsync command "rsync -avhzrP {{remote_user}}@{{hostname}}:{{remote_db_backup_path}} ./from-remote/"</comment>');
	//-a, –archive | -v, –verbose | -h, –human-readable | -z, –compress | r, –recursive | -P,  --partial and --progress
	runLocally('rsync -aqzrP {{remote_user}}@{{hostname}}:{{remote_db_backup_path}} ./from-remote/', ['timeout' => 1800]);
	writeln('<info>Done!</info>');
});

/**
 * Save Assets from server.
 * Grabs the most recent backup i.e. previous nights Assets
 */
task('savefromremote:assets', function () {
	writeln('<info>Save assets from SiteHost</info>');
	writeln('<comment>Running rsync command rsync -avhzrP {{remote_user}}@{{hostname}}:{{remote_assets_backup_path}} ./from-remote/</comment>');
	//-a, –archive | -v, –verbose | -h, –human-readable | -z, –compress | r, –recursive | -P,  --partial and --progress
	runLocally('rsync -avhzrP  {{remote_user}}@{{hostname}}:{{remote_assets_backup_path}} ./from-remote/', ['timeout' => 1800]);

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
	run('mv {{remote_assets_path}} /container/application/shared/assets-backup');
	writeln('<info>Backup copy complete.</info>');
	writeln('<info>------------------------------------------------------------</info>');

	writeln('<info>Loading assets to SiteHost</info>');
	writeln('<comment>Running rsync command rsync -avP --delete {{local_assets_path}} {{remote_user}}@{{hostname}}:{{remote_assets_path}}</comment>');
	//-a, –archive | -v, –verbose | -h, –human-readable | -z, –compress | r, –recursive | -P,  --partial and --progress
	runLocally('rsync -avP --delete /var/www/html/{{shared_assets}}/ {{remote_user}}@{{hostname}}:{{remote_assets_path}}', ['timeout' => 1800]);
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

