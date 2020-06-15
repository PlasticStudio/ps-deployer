# ps-deployer

## Deployments

We use [Deployer](https://deployer.org/) for deployments, which can be installed either globally (recommended):

```bash
curl -LO https://deployer.org/deployer.phar
mv deployer.phar /usr/local/bin/dep
chmod +x /usr/local/bin/dep
```

or comes with PS docker image


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