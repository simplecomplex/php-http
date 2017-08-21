#!/bin/bash -e
# Stop (don't exit) on error.
#-------------------------------------------------------------------------------
### PHP source dir: backend
### Angular source dir: frontend

## PLACE YOURSELF IN THE SITE'S DOCUMENT ROOT.
#cd [document root]

# Set document root var.
doc_root=`pwd`

# Set conf dir var.
cd ../
path_conf=`pwd`'/conf'
cd ${doc_root}

# Set private files dir var.
cd ../
path_private=`pwd`'/private'
cd ${doc_root}

# Set backend and frontend dir vars.
path_backend=${doc_root}'/backend'
path_frontend=${doc_root}'/frontend'


#### BACKEND INSTALLATION ######################################################

### Configuration (global) #############

## Symlink base configuration files.
ln -s ${path_backend}'/vendor/kk-seb/http/config-ini/http.ini' ${path_conf}'/ini/base/http.ini'
ln -s ${path_backend}'/vendor/kk-seb/http/config-ini/http-services' ${path_conf}'/ini/base/http-services'

## Override configuration files
# PRODUCTION
#ln -s ${path_backend}'/vendor/kk-seb/http/config-ini/http.prod.override.ini' ${path_conf}'/ini/override/http.prod.override.ini'
# DEVELOPMENT
ln -s ${path_backend}'/vendor/kk-seb/http/config-ini/http.dev.override.ini' ${path_conf}'/ini/override/http.dev.override.ini'


### Service response validation ########

## Create rule-set dir
mkdir -p ${path_conf}'/json/http/response-validation-rule-sets'

## Symlink dir of KkSeb/Http rule-sets.
ln -s ${path_backend}'/vendor/kk-seb/http/response-validation-rule-sets' ${path_conf}'/json/http/response-validation-rule-sets/http'


### Service response mocks #############
mkdir -p ${path_conf}'/json/http/response-mocks'

## Symlink dir of KkSeb/Http mocks.
ln -s ${path_backend}'/vendor/kk-seb/http/response-mocks' ${path_conf}'/json/http/response-mocks/http'


### Refresh global configuration #######
php cli.phpsh config-refresh global -y

#### END #######################################################################
