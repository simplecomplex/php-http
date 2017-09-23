## Http ##

High-level HTTP client and (Slim) service utility.  
Client provides response validation and mocking.  
Service provides standardized and predictable responses.

### Allowing Cross Origin requests ###

Preferably only at development site. Necessary when developing Angular-based frontend.

Place a ```.cross_origin_allow_sites``` text file in document root, containing list allowed sites, like:  
```http://localhost:4200,http://my-project.build.local.host:80```

### Relevant CLI commands ###


##### Service configuration #####

Service configuration has four levels - provider, service, endpoint and method (or alias, like 'retrieve').  
They, and options passed via request(), get merged at runtime to produce final options of the request.
```bash
# Show provider settings.
php cli.phpsh config-get -a global http-provider.prvdr

# Show service settings.
php cli.phpsh config-get -a global http-service.prvdr.example-service

# Show endpoint settings.
php cli.phpsh config-get -a global http-endpoint.prvdr.example-service.ndpnt

# Show method settings.
php cli.phpsh config-get -a global http-method.prvdr.example-service.ndpnt.GET
```

##### Validation rule sets #####

```bash
# Show cached validation rule set.
php cli.phpsh cache-get http-response_validation-rule-set prvdr.example-service.ndpnt.GET

# Delete cached validation rule set.
php cli.phpsh cache-delete http-response_validation-rule-set prvdr.example-service.ndpnt.GET
```

##### Mock responses #####

```bash
# Show cached mock response.
php cli.phpsh cache-get http-response_mock prvdr.example-service.ndpnt.GET

# Delete cached mock response.
php cli.phpsh cache-delete http-response_mock prvdr.example-service.ndpnt.GET
```

### Requirements ###

- PHP >=7.0
- [PSR-3 Log](https://github.com/php-fig/log)
- [SimpleComplex Utils](https://github.com/simplecomplex/php-utils)
- [SimpleComplex Cache](https://github.com/simplecomplex/php-cache)
- [SimpleComplex Config](https://github.com/simplecomplex/php-config)
- [SimpleComplex Locale](https://github.com/simplecomplex/php-locale)
- [SimpleComplex Validate](https://github.com/simplecomplex/php-validate)
- [SimpleComplex RestMini](https://github.com/simplecomplex/restmini)
- [SimpleComplex Inspect](https://github.com/simplecomplex/inspect)

#### Suggestions ####
- [Slim](https://github.com/slimphp/Slim) 
