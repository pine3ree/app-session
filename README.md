# PoC for state-less zend-expressive-session-ext

As a Proof of Concept I simply kept the `App\Session` namespace with the persistence
implementation in the 'Persistence/Ext' subdirectory.

The code here is a customization of `zend-expressive-session`, `zend-expressive-session-ext` and `dflydev-fig-cookies`

Differences from zend-expressive-session/zend-expressive-session-ext are explained below:

## 1. SessionInterface

For simplicity (and because I always need all features) I merged all the original 
interfaces into a single SessionInterface and renamed a couple of methods:

- `SessionInterface::persistSessionFor(...)` to `SessionInterface::persistFor(...)`
- `SessionInterface::getSessionLifetime()` to `SessionInterface::getLifetime()`

I find the word "Session" pleonastic in this case, as in `$customer->getCustomerName()` 
or `$user->getUserEmail()`...

A new interface method has also been added:

`SessionInterface::isNew()`

php-ext sends a response session cookie only if the SID has changed. This 
happens when starting a new session when the initial request didn't have a session
cookie, when regenerating the session or when calling `session_id($newId)`. (
Actually php assumes that if you set an id you want to change the current id, so 
it sends a response cookie even if using the current sid as argument).

In order to determine if the SID changed we must either pass-in the request session id
and add a `getRequestId()`, sync the session instance id for every identifier 
changes and compare the original/last id value when persisting the session, and 
this is what I initially did. 
Or we can initialize the session id with an isNew property to espose the fact that the current
session id is different from the one in the request. When we need to establish whether
we need to send a response cookie or not, we can call isNew() and isRegenerated(). If
either call returns `true` then we have a new id.

The following method's signature has been changed:

`SessionInterface::getLifetime() : ?int`

The session lifetime is not stored in the session data. The session cookie already
contains this information. We only want to update the lifetime when we need to.
To achieve that we need to send the cookie again with the new max-age info. I use a `null`
value of $session->getLifetime() as a no-change/no-set-cookie flag. In this case 
the default `session.cookie_lifetime` ini value will be used in the first response.
php-ext session will not send another cookie if `session.cookie_lifetime` ini value
is changed. By using a not-null value of `SessionInterface::getLifetime()`, set
via a programmatic `persistFor()` call, we can bypass this php limitation.

This also allows us to perform usefule actions:

- Resetting a timed session cookie to a standard session cookie by calling `$session->persistFor(0)`.
- Setting the cookie lifetime as x-seconds from last access by calling `$session->persistFor($xSeconds)` on each request
- Setting the cookie lifetime as x-days from first access ("remember me" for 30 days) by calling `$session->persistFor(86400 x 30)` on first login

## 2. LazySession, Session, AbstractSession, SessionContainer data container.

All data access methods have been moved into a SessionContainer utility class.

Initially the standard Session class extended the container, while the LazySession
composed a lazily instantiated data container. Aftewards I created a common base
abstract class that composed the data container for both LazySession and standard
Session. This made the code a bit cleaner, shorter, and simmetric. The difference
in the 2 classes are now only in the constructor (Session may receive session data
array or a data container, while LazySession receives a data container factory).
The abstract `data()` method implementation accesses the already initialized data 
container instance in the Session class, and calls the data factory to initialize 
the container in LazySession.

The trick for the lazy session is that the initial session is opened only if accessed
and only if the request had a session id cookie. The factory is created and passed
into the lazy instance in the persistence method `initiliazeSessionFormRequest`, 
stealing the `$requestId` value from its scope.

## 3. PhpSessionPersistence

A new private method:

`PhpSessionPersistence::createContainer() : SessionContainer`

has been added. Initially I mad this as part of the interface and performed a call
inside the `LazySession::data()` method. But using a data container factory resulted
in prettier code.

Request and Response cookie support methods has been implemented in place, removing
the dependency on third-party package.

As PoC in the `initiliazeSessionFormRequest` method I allowed the creation of both 
a lazy and a standard session instance.

## 4. Configuration

There are 2 ConfigProvider classes to enable and test:

- `App\Session\ConfigProvider` for the base session package
- `App\Session\Persistence\Ext\ConfigProvider` for the session-persistence part

```php
// file: data/autoload/session.global.php
return [
    'session' => [
        'persistence' => [
            'use_lazy_session' => true,
            'ext' => [
                'non_locking' => true, // activate `read_and_close` in first session_start call, if called 
            ],
        ],
    ],
];
```

## 5. Caveats
- This is meant to PoC code only, use at your own risk.
- You may find unspaced `!` operators, as my personaly preference is to only use space in cases like `! $obj instanceof SomeClass`...


