# ps-deployer

## Important

To continue using old deployer set up
Uncomment this line in the deployer file. This will make deployer use the old directory of "current"

```
//set('current_path', '/container/application/current');
```

## Migrate from v6 to 7


1. Make sure you are running the latest docker image (you can pull this from inside docker desktop). deployer 7 currently works with 8.1 and 8.0 docker images.
2. Install the new module `composer require PlasticStudio/ps-deployer`
3. Update `deploy.php` to new code and update all relevant lines.
    ```
    <?php

    namespace Deployer;

    require 'vendor/plasticstudio/ps-deployer/ps_silverstripe.php';

    //Legacy deployer v6 path to use
    //set('current_path', '/container/application/current');
    set('repository', 'git@github.com:PlasticStudio/skeletor.git');
    set('remote_assets_backup_path', '/container/backups/latest/application/shared/public/assets'); //no trailing slash is important
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
4. NEW CONTAINER: If this is a new container then we will need to prepare sitehost
   `dep sitehost:prepare`
   This will delete public folder and have it ready for symlink
   Set up ssh key for you to copy
   Set up php default config
   
   EXISITING CONTAINER: If this is an existing container then we will need to prepare sitehost. You will want to keep the current path for deploying. Uncomment the below line in your deploy.php file.
   `//set('current_path', '/container/application/current');`

5. If this is an existing container then the final thing we will need to do is on your FIRST deployment, deploy by release_name. This will be the current release +1.
To find our the current release, ssh into the container and check the releases folder.
```
dep deploy -o release_name=43
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
set('remote_assets_backup_path', '/container/backups/latest/application/shared/public/assets'); //no trailing slash is important
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




## Deployments

### Docker
Deployer comes with ps docker image.

make sure to include `- ~/.ssh:/tmp/.ssh:ro` as a mounted volume in the docker-compose.yml 


### Deploying a site

todo update

It’s as easy as `dep deploy`.  will get current git HEAD branch as default branch to deploy.

```
dep deploy stage=prod --branch=master
```

On the first deploy, you’ll probably want to include the database and assets:

```
dep deploy
```

You’ll also be asked (the first time you deploy to a given stage) to provide database credentials used to populate `.env`.

#### Deploying to production

todo update

Much the same as deploying to staging, just provide a third argument to select the stage (either `staging` or `production`):

```
dep deploy production
```

#### Deploy a branch/tag

todo update

```
# Deploy the dev branch
dep deploy --branch=dev

# Deploy tag 1.0.1 to production
dep deploy production --tag=1.0.1
```


### Database and assets

todo update

#### Assets


#### database
