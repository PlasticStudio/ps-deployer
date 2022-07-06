# ps-deployer


## Set up

`composer require PlasticStudio/ps-deployer`

Create a new `deploy.php` file with the following contents and update where required:

```
<?php

namespace Deployer;

require 'vendor/plasticstudio/ps-deployer/ps_silverstripe.php';

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

## Deployments

### Docker
Deployer comes with ps docker image.

make sure to include `- ~/.ssh:/tmp/.ssh:ro` as a mounted volume in the docker-compose.yml 


### Deploying a site

todo update

It’s as easy as `dep deploy`.

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
