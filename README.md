# ps-deployer


## Quick access commands

`dep deploy stage=uat`

`dep deploy stage=uat --branch=master`

`dep deploy stage=prod --tag=1.0.1`

`dep sitehost:prepare`

`dep sitehost:prepare:deploy stage=uat --branch=master`

`dep sitehost:backup`

`dep savefromremote`

`dep savefromremote:db`

`dep savefromremote:assets`

`dep loadtoremote:assets`

## Important

To keep normal set up - add to your docker file environment

`- DEPLOYER_VERSION=6.8.0`

## Migrate from v6 to 7


1. Make sure you are running the latest docker image (you can pull this from inside docker desktop). deployer 7 currently works with 8.1 and 8.0 docker images.
2. Install the new module `composer require --dev PlasticStudio/ps-deployer ` this now contains all the tasks that were present in the old deploy.php
3. Rename your `deploy.php` to `deploy-backup.php`
4. Create new `deploy.php` and add below new code all. 
    ```
    <?php

    namespace Deployer;

    require 'vendor/plasticstudio/ps-deployer/ps_silverstripe.php';

    //Legacy deployer v6 path to use
    //set('current_path', '/container/application/current');
    set('repository', 'git@github.com:PlasticStudio/skeletor.git');
    set('remote_assets_backup_path', '/container/backups/containers/latest/application/shared/public/assets'); //no trailing slash is important
    set('remote_assets_path', '/container/application/shared/public/assets/');
    set('local_assets_path', '/var/www/html/public/assets/');

    //Staging
    host('uat.domain.co.nz')
        ->set('labels', ['stage' => 'uat'])
        ->set('http_user', 'uatuser')
        ->set('remote_user', 'uatuser');


    //Production
    host('production.domain.co.nz')
        ->set('labels', ['stage' => 'prod'])
        ->set('http_user', 'produser')
        ->set('remote_user', 'uatuser');
    ```

5. Open files side by side copying across all site specific variables such as user, host, and files paths
6. After copying you can now delete your old `deploy-backup.php`
7. NEW CONTAINER: If this is a new container then we will need to prepare sitehost
   `dep sitehost:prepare`
   This will delete public folder and have it ready for symlink
   Set up ssh key for you to copy
   Set up php default config
   
   EXISITING CONTAINER: If this is an existing container then we will need to prepare sitehost. You will want to keep the current path for deploying. Uncomment the below line in your deploy.php file.
   `//set('current_path', '/container/application/current');`
   
   UPGRADING CONTAINER: todo

8. If this is an existing container then the final thing we will need to do is on your FIRST deployment, deploy by release_name. This will be the current release +1.
To find our the current release, ssh into the container and check the releases folder.
```
dep deploy stage=uat --branch=master -o release_name=43
```


## New set up

`composer require PlasticStudio/ps-deployer`

Create a new `deploy.php` file with the following contents and update where required:

```
<?php

namespace Deployer;

require 'vendor/plasticstudio/ps-deployer/ps_silverstripe.php';

//Legacy deployer v6 path to use
//set('current_path', '/container/application/current');
set('repository', 'git@github.com:PlasticStudio/skeletor.git');
set('remote_assets_backup_path', '/container/backups/containers/latest/application/shared/public/assets'); //no trailing slash is important
set('remote_assets_path', '/container/application/shared/public/assets/');
set('local_assets_path', '/var/www/html/public/assets/');

//Staging
host('uat.domain.co.nz')
    ->set('labels', ['stage' => 'uat'])
    ->set('http_user', 'uatuser')
    ->set('remote_user', 'uatuser');


//Production
host('production.domain.co.nz')
    ->set('labels', ['stage' => 'prod'])
    ->set('http_user', 'produser')
    ->set('remote_user', 'uatuser');

```



## Set up SiteHost ready for Deployer

todo
follow guru card to set up, then you can run this command - this only needs to be run once

`dep sitehost:prepare`

This will:

- Delete public directory which is created on first creation of a Sitehost server, so we can use this path as a symlink
- Generates ssh key which you can copy to deployment keys on github project
- Create php default config 


If you are doing a container upgrade on Sitehost then you will want to run this command immediately after 

`dep sitehost:prepare:deploy stage=uat --branch=master`

This will update the config to the defaults and fix the symlink 




## Deployments


### Deploying a site

It’s as easy as `dep deploy`.  This will give you the option of which host to deploy to and will get current git HEAD to deploy.

```
dep deploy stage=uat
```

```
# Deploy the dev branch
dep deploy stage=uat --branch=master


# Deploy tag 1.0.1 to production
dep deploy stage=prod --tag=1.0.1
```


### Database and assets

Make sure your `deployer.php` paths are set up correctly

Save both db and assets from remote
`dep savefromremote`

Saves the most recent backup i.e. previous nights DB
`dep savefromremote:db`

Saves the most recent backup i.e. previous nights Assets
`dep savefromremote:assets`

Makes a temporary copy of current live assets, rolls back to this if there is a transfer issue.
`loadtoremote:assets`



### Docker
Deployer comes with ps docker image.

make sure to include `- ~/.ssh:/tmp/.ssh:ro` as a mounted volume in the docker-compose.yml 


Keep old version of 
