## Http ##

High-level HTTP client and (Slim) service utility.  
Client provides response validation and mocking.  
Service provides standardized and predictable responses.

<details>
<summary>

### Client ###
</summary>

#### Request options ####

The ```HttpClient->request()``` method accepts it's own options  
as well as options for the underlying [RestMini Client](https://github.com/simplecomplex/restmini#client-options).

##### Own options #####

- (bool) **debug_dump**: log request and response
- (bool|arr) **cacheable**: cache response/load response from [cache](https://github.com/simplecomplex/php-cache)
- (bool|arr) **validate_response**: validate the response body against a [validation rule set](https://github.com/simplecomplex/php-validate)
- (bool|arr) **mock_response**: don't send the request, return predefined mock response
- (int) **retry_on_unavailable**: millisecs; try again later upon  
     503 Service Unavailable|host not found|connection failed
- (arr) **require_response_headers**: list of response header keys required
- (bool) **err_on_endpoint_not_found**: err on 404 + HTML response
- (bool) **err_on_resource_not_found**: err on 204 or 404 + JSON response
- (arr) **log_warning_on_status**: key is status code, value is true

##### Options processing #####

Apart from options passed directly to ```HttpClient->request()```  
there may also exist settings (see [Config](https://github.com/simplecomplex/php-config)) for the:
- **provider**: the service host
- **service**: a group of endpoints
- **endpoint**: the actual endpoint
- **method**: literal HTTP method or an alias (like GET aliases index and retrieve)

During request preparations settings and options get merged, so that  
– _options_ override _method_ settings, which override _endpoint_ settings, which override... (you get it).  
Sounds like a lot of work, but it isn't really.


  <details>
  <summary>
  
  #### Client CLI commands ####
  </summary>

##### (remote) Service configuration #####

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
  </details>

  <details>
  <summary>
  
  #### Client error codes ####
  </summary>
  
  For every error code there's an equivalent prefab safe and user-friendly (localizable) error message.
  
  - ```unknown```: overall error fallback
  - ```local-unknown```: local error fallback
  - ```local-algo```: in-package logical error
  - ```local-use```: invalid argument et al.
  - ```local-configuration```: bad config var
  - ```local-option```: bad option var
  - ```local-init```: RestMini Client or cURL cannot request
  - ```host-unavailable```: DNS or actually no such host
  - ```service-unavailable```: status 503 Service Unavailable
  - ```too-many-redirects```: too many redirects
  - ```timeout```: cURL 504
  - ```timeout-propagated```: status 504 Gateway Timeout
  - ```response-none```: cURL 500 (RestMini Client 'response-false')
  - ```remote```: status 500 Internal Server Error
  - ```remote-propagated```: remote says 502 Bad Gateway
  - ```malign-status-unexpected```: unsupported 5xx status
  - ```endpoint-not-found```: status 404 + Content-Type not JSON (probably HTML); no such endpoint
  - ```resource-not-found```: status 204, status 404 + Content-Type JSON; no such resource (object)
  - ```remote-validation-bad```: 400 Bad Request 
  - ```remote-validation-failed```: 412 Precondition Failed, 422 Unprocessable Entity
  - ```response-type```: content type mismatch
  - ```response-format```: parse error
  - ```benign-status-unexpected```: unsupported non-5xx status
  - ```header-missing```: setting/option _require_response_headers_
  - ```response-validation```: response body validation failure; service will send X-Http-Response-Invalid header
  
  </details>

</details>

<details>
<summary>

### Service ###
</summary>

Producing a service response is not as hard as requesting a remote service,  
so the service part of **Http** is not as rich as the client part.  
It is assumed that one will simply echo something and send some headers
– or use a service framework like [Slim](https://www.slimframework.com/).

The ```HttpServiceSlim``` class suggest means of interacting with Slim, and **Http** includes a simple example.
@todo  
And **Http** also provides a few other service utilities.

#### Allowing Cross Origin requests ####

Preferably only at development site. Necessary when developing Angular-based frontend.

Place a ```.cross_origin_allow_sites``` text file in document root, containing list allowed sites, like:  
```http://localhost:4200,http://my-project.build.local.host:80```

  <details>
  <summary>
  
  #### Service error codes ####
  </summary>
  
  For every error code there's an equivalent prefab safe and user-friendly (localizable) error message.
  
  - ```unknown```: overall fallback
  - ```request-unacceptable```: (some kind of) bad request; ```HttpResponseRequestUnacceptable```
  - ```unauthenticated```: authentication (login) failure; ```HttpResponseRequestUnauthenticated```
  - ```unauthorized```: authorization (permission) failure; ```HttpResponseRequestUnauthorized```
  - ```request-validation```: request header/argument validation failure; ```HttpResponseRequestInvalid```
  - ```frontend-response-format```: frontend only; parse error
  - ```frontend-response-validation```: frontend only; response validation failure
  
  </details>

</details>

### Service and client combined ###

#### Standardized wrapped response body ####

A service requestor should never be in doubt whether a request to your service went alltogether well.  
```HttpClient->request()``` returns ```HttpResponse``` object:

- status ```int```: suggested status to send to requestor
- headers ```array```: suggested header to send
- body ```HttpResponseBody```
  - **success** ```bool```
  - **status** ```int```: status received from remote service
  - **data** ```mixed|null```: that actual data (on success)
  - **message** ```string|null```: safe and user-friendly message (on failure)
  - **code** ```int```: error code (on failure), or optionally some other flag (on success)
- originalHeaders ```array```: headers received – _not_ to be sent to requestor
- validated ```bool|null```: whether the response was validated, and then the outcome

The general idea is to always send a response body containing _metadata_ about the procedings – success, status, code.  
And – on failure – a prefab safe and user-friendly message, which the client can use to inform the visitor  
(instead of just failing silently/ungracefully).

When exposing a service that does a remote request, the service should however have access to more detailed info
about the remote request/response  
– therefore the further wrapping in an object that suggests status and headers (et al.).

<details>
  <summary>
  
#### Service response headers ####
</summary>

**Http** uses a number of custom response headers, to flag stuff to the client.  
Some are issued by ```HttpClient``` upon every remote request (the original/final statuses).  
Others are only issued if a service uses/sends one of the prefab ```HttpResponse``` extensions.

- <sup>(int)</sup>```X-Http-Original-Status```: status received from remote service (or interpretated ditto)
- <sup>(int)</sup>```X-Http-Final-Status```: final status to be sent to client
- ```X-Http-Response-Invalid```: response validation failure; ```HttpResponseResponseInvalid```
- ```X-Http-Mock-Response```: ```HttpClient``` never called remote service; used prefab mock response
- ```X-Http-Request-Invalid```: request header/argument validation failure; ```HttpResponseRequestInvalid```
- ```X-Http-Request-Unacceptable```: (some kind of) bad request; ```HttpResponseRequestUnacceptable```
- ```X-Http-Request-Unauthenticated```: authentication (login) failure; ```HttpResponseRequestUnauthenticated```
- ```X-Http-Request-Unathorized```: authorization (permission) failure; ```HttpResponseRequestUnauthorized```

</details>

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
