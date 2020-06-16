# ps-deployer

## Set up SiteHost ready for Deployer

1. Create new container in SiteHost
2. Create new ssh user
3. Click to assign all dev's public keys 
4. When container created - ssh in using ssh user you created and container ip address `ssh user@111.222.333`
5. Generate ssh keypair and copy pub key to the github project `https://help.github.com/en/github/authenticating-to-github/generating-a-new-ssh-key-and-adding-it-to-the-ssh-agent`
6. `ssh -T git@github.com`  to authenticate and add to hosts
7. `nano /container/config/apache2/sites-available/000-default.conf` to edit apache config
8. Change `/var/www/html/public/` to `/var/www/html/current` (this occurs on two lines), this will change the webroot to be deployers output directory
9. In the SiteHost Web UI - Reset container


## Deployments

We use [Deployer](https://deployer.org/) for deployments, which can be installed either globally (recommended):

```bash
curl -LO https://deployer.org/deployer.phar
mv deployer.phar /usr/local/bin/dep
chmod +x /usr/local/bin/dep
```

or comes with PS docker image


### SS3 notes
update deploy.php share file from `.env` to `_ss_environment.php`

note file mapping path of `_ss_enviornment.php` file
```
// This is used by sake to know which directory points to which URL
global $_FILE_TO_URL_MAPPING;
$_FILE_TO_URL_MAPPING[realpath('/container/application/release')] = 'http://mta-test.plasticstudio.co.nz';
```

### Deploying a site

It’s as easy as `dep deploy`.

On the first deploy, you’ll probably want to include the database and assets:

```
dep deploy
```

You’ll also be asked (the first time you deploy to a given stage) to provide database credentials used to populate `.env`.

#### Deploying to production

Much the same as deploying to staging, just provide a third argument to select the stage (either `staging` or `production`):

```
dep deploy production
```

#### Deploy a branch/tag

```
# Deploy the dev branch
dep deploy --branch=dev

# Deploy tag 1.0.1 to production
dep deploy production --tag=1.0.1
```