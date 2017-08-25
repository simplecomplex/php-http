#!/bin/bash -e
# Stop (don't exit) on error.
#-------------------------------------------------------------------------------
### Library: http
### PHP source dir: backend
### Angular source dir: frontend

## PLACE YOURSELF IN THE SITE'S DOCUMENT ROOT.
#cd [document root]

## Script accepts one optional 'environment' argument: dev|prod

# Establish environment: dev|prod.
if [ "$1" == 'prod' ]; then
    environment='prod'
else
    environment='dev'
fi

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

### (dev only) CROSS ORIGIN SITES ######

if [ ${environment} != 'prod' ]; then
    # Comma-separated list; no spaces.
    # Angular (ng serve, npm start): http://localhost:4200.
    echo "http://localhost:4200" > ${doc_root}'/.access_control_allow_origin'
fi


### Configuration (global) #############

## Symlink base configuration files.
ln -s ${path_backend}'/vendor/kk-seb/http/config-ini/http.global.ini' ${path_conf}'/ini/base/http.global.ini'
ln -s ${path_backend}'/vendor/kk-seb/http/config-ini/http-services' ${path_conf}'/ini/base/http-services'

## Override configuration files
# http.dev.override.global.ini vs. http.prod.override.global.ini.
ln -s ${path_backend}'/vendor/kk-seb/http/config-ini/http.'${environment}'.override.global.ini' ${path_conf}'/ini/override/http.'${environment}'.override.global.ini'


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
export PHP_LIB_SIMPLECOMPLEX_UTILS_CLI_SKIP_CONFIRM=1
export PHP_LIB_SIMPLECOMPLEX_UTILS_CLI_SILENT=1
php cli.phpsh config-refresh global -y
sleep 1
unset PHP_LIB_SIMPLECOMPLEX_UTILS_CLI_SKIP_CONFIRM
unset PHP_LIB_SIMPLECOMPLEX_UTILS_CLI_SILENT


### Success ############################
echo -e "\n\033[01;32m[success]\033[0m"' KkSeb Http setup successfully.'

#### END #######################################################################
