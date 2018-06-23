#!/bin/bash -e
# Stop (don't exit) on error.
#-------------------------------------------------------------------------------
### Library: simplecomplex/http
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
    # Comma-separated list (including HTTP port).
    # Angular (ng serve, npm start): http://localhost:4200.
    echo "http://localhost:4200" > ${doc_root}'/.cross_origin_allow_sites'
fi


### Configuration ######################

## Symlink configuration dir.
ln -s ${path_backend}'/vendor/simplecomplex/http/config-ini' ${path_conf}'/ini/base/http'


### Service response validation ########

## Ensure rule-set directory.
if [ ! -d ${path_conf}'/json/http/response-validation-rule-sets' ]; then
    mkdir -p ${path_conf}'/json/http/response-validation-rule-sets'
    sleep 1
fi


### Service response mocks #############

## Ensure mock directory.
if [ ! -d ${path_conf}'/json/http/response-mocks' ]; then
    mkdir -p ${path_conf}'/json/http/response-mocks'
    sleep 1
fi


#### PHP CLI ###################################################################

### Refresh global configuration #######
export PHP_LIB_SIMPLECOMPLEX_UTILS_CLI_SKIP_CONFIRM=1
export PHP_LIB_SIMPLECOMPLEX_UTILS_CLI_SILENT=1
php cli.phpsh config-refresh global -y
sleep 1
unset PHP_LIB_SIMPLECOMPLEX_UTILS_CLI_SKIP_CONFIRM
unset PHP_LIB_SIMPLECOMPLEX_UTILS_CLI_SILENT


### Success ############################
echo -e "\n\033[01;32m[success]\033[0m"' SimpleComplex Http setup succeeded.'

#### END #######################################################################
