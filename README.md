## KkBase Http ##

### Allowing Cross Origin requests ###

Place a ```.cross_origin_allow_sites``` text file in document root, containing list allowed sites, like:  
```http://localhost:4200,http://my-project.build.local.host:80```

### Relevant CLI commands ###


##### Service configuration #####

Service configuration has four levels - provider, service, endpoint and method (or alias, like 'retrieve').  
They, and options passed via request(), get merged at runtime to produce final options of the request.
```bash
# Show provider settings.
php cli.phpsh config-get -aip global http-provider.prvdr

# Show service settings.
php cli.phpsh config-get -aip global http-service.prvdr.srvc

# Show endpoint settings.
php cli.phpsh config-get -aip global http-endpoint.prvdr.srvc.ndpnt

# Show method settings.
php cli.phpsh config-get -aip global http-method.prvdr.srvc.ndpnt.mthd
```

##### Validation rule sets #####

```bash
# Show cached validation rule set.
php cli.phpsh cache-get -piy http-response_validation-rule-set prvdr.srvc.ndpnt.mthd

# Delete cached validation rule set.
php cli.phpsh cache-delete http-response_validation-rule-set prvdr.srvc.ndpnt.mthd
```

##### Mock responses #####

```bash
# Show cached mock response.
php cli.phpsh cache-get -piy http-response_mock prvdr.srvc.ndpnt.mthd

# Delete cached mock response.
php cli.phpsh cache-delete http-response_mock prvdr.srvc.ndpnt.mthd
```

### Requirements ###

- PHP >=7.0
- [KkBase Base](https://kkgit.kk.dk/php-psr.kk-base/base)
- [SimpleComplex RestMini](https://github.com/simplecomplex/restmini)
- [SimpleComplex Inspect](https://github.com/simplecomplex/inspect)
- [SimpleComplex Utils](https://github.com/simplecomplex/php-utils)

<!--
##### Suggestions #####
-->
