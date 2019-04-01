<?php
/**
 * Based on
 * @see https://github.com/zendframework/zend-expressive-session-ext
 * @see https://github.com/dflydev/dflydev-fig-cookies
 *
 * @license   New BSD License
 */

namespace App\Session\Persistence\Ext;

use RuntimeException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use App\Session\SessionPersistenceInterface;
use App\Session\SessionInterface;
//use App\Session\DataContainer; FGS, pick a name!!!
//use App\Session\Container; FGS, pick a name!!!
use App\Session\DataContainer; // OK, this is it!
use App\Session\Session;
use App\Session\LazySession;

use function bin2hex;
use function filemtime;
use function filter_var;
use function getlastmod;
use function gmdate;
use function ini_get;
use function random_bytes;
use function session_id;
use function session_name;
use function session_start;
use function session_status;
use function session_write_close;
use function sprintf;
use function time;

use const FILTER_NULL_ON_FAILURE;
use const FILTER_VALIDATE_BOOLEAN;
use const PHP_SESSION_ACTIVE;
use const PHP_SESSION_DISABLED;
use const PHP_SESSION_NONE;

/**
 * Session persistence using ext-session.
 *
 * Adapts ext-session to work with PSR-7 by disabling its auto-cookie creation
 * (`use_cookies => false`), while simultaneously requiring cookies for session
 * handling (`use_only_cookies => true`). The implementation pulls cookies
 * manually from the request, and injects a `Set-Cookie` header into the
 * response.
 *
 * Session identifiers are generated using random_bytes (and casting to hex).
 * During persistence, if the session regeneration flag is true, a new session
 * identifier is created, and the session re-started.
 */
class PhpSessionPersistence implements SessionPersistenceInterface
{
    /**
     * Use a session with a lazy container?
     * This should always be set to true. The false option here is only used as
     * a PoC for demonstrating the usage of standard/non-lazy session.
     *
     * @var bool
     */
    private $useLazySession = true;

    /** @var bool */
    private $nonLocking = false;

    private $requiredOptions = [
        'use_trans_sid'    => false,
        'use_cookies'      => false,
        'use_only_cookies' => true,
        'use_strict_mode'  => false,
        'cache_limiter'    => '',
    ];

    /**
     * The time-to-live for cached session pages in minutes as specified in php
     * ini settings. This has no effect for 'nocache' limiter.
     *
     * @var int
     */
    private $cacheExpire;

    /**
     * The cache control method used for session pages as specified in php ini
     * settings. It may be one of the following values: 'nocache', 'private',
     * 'private_no_expire', or 'public'.
     *
     * @var string
     */
    private $cacheLimiter;

    /** @var array */
    private static $supported_cache_limiters = [
        'nocache'           => true,
        'public'            => true,
        'private'           => true,
        'private_no_expire' => true,
    ];

    /**
     * This unusual past date value is taken from the php-engine source code and
     * used "as is" for consistency.
     *
     * (btw, it's Sascha Schumann's birthday!)
     * @see https://github.com/php/php-src/blob/php-7.1.26/ext/session/session.c#L1137
     */
    public const CACHE_PAST_DATE  = 'Thu, 19 Nov 1981 08:52:00 GMT';

    public const HTTP_DATE_FORMAT = 'D, d M Y H:i:s T';

    /**
     * Memoize session ini settings before starting the request.
     *
     * The cache_limiter setting is actually "stolen", as we will start the
     * session with a forced empty value in order to instruct the php engine to
     * skip sending the cache headers (this being php's default behaviour).
     * Those headers will be added programmatically to the response along with
     * the session set-cookie header when the session data is persisted.
     */
    public function __construct(bool $useLazySession = true, bool $nonLocking = false)
    {
        $this->useLazySession = $useLazySession;
        $this->nonLocking = $nonLocking;

        $this->cacheLimiter = ini_get('session.cache_limiter');
        $this->cacheExpire  = (int) ini_get('session.cache_expire');
    }

    public function initializeSessionFromRequest(ServerRequestInterface $request) : SessionInterface
    {
        $requestId = $this->getSessionIdFromRequest($request);
        $id = $requestId ?: $this->generateId();

        $isNew = ! $requestId;

        if ($this->useLazySession) {
            // inject $requestId from current scope
            return new LazySession($id, $isNew, function () use ($requestId) {
                return $this->createDataContainer($requestId);
            });
        }

        // PoC non lazy-session
        return new Session($id, $isNew, $this->createDataContainer($requestId));
    }

    private function createDataContainer(string $id) : DataContainer
    {
        if ($id) {
            $this->startSession($id, [
                'read_and_close' => $this->nonLocking, // prevent session locking
            ]);
        }

        return new DataContainer($_SESSION ?? []);
    }

    public function persistSession(SessionInterface $session, ResponseInterface $response) : ResponseInterface
    {
        $id = $session->getId();

        if ($session->isRegenerated()) {
            $id = $this->generateId();
        }

        $this->writeAndClose($id, $session);

        $response = $this->addSessionCookie($response, $id, $session);
        $response = $this->addCacheHeaders($response);

        return $response;
    }

    /**
     * We need to write session data into a session file only in 2 cases:
     *
     * 1. the session data has changed
     * 2. the session is marked as regenerated, so we need to save it into a new
     *    file identified by a newly generated id but only if there is data to
     *    be saved
     *
     * @param string $id The last opened session id
     */
    private function writeAndClose(string $id, SessionInterface $session) : void
    {
        // TODO: less nesting levels? ...mmm.. code looks clearer this way!
        $hasChanged = $session->hasChanged();
        if ($hasChanged || $session->isRegenerated()) {
            $data = $session->toArray(); // triggers container instantiation => session_start
            if ($hasChanged || ! empty($data)) {
                if ($this->startSession($id)) {
                    $_SESSION = $data;
                }
            }
        }
        // write close any active session file
        session_write_close();
    }

    /**
     * Try to a new php-session enforcing required options
     *
     * @param array $options Additional options to pass to `session_start()`.
     *
     * @return bool Returns true if started a new session or intercepted an open
     *      session with the same id
     *
     * @throws RuntimeException
     */
    private function startSession(string $id, array $options = []) : bool
    {
        if (PHP_SESSION_DISABLED === session_status()) {
            throw new RuntimeException(
                "Unable to start a new php session: php sessions are disabled!"
            );
        }

        // get current session id if any
        $session_id = session_id();

        if (PHP_SESSION_ACTIVE === session_status()) {
            // If we are intercepting an open session with the same id, skip
            // closing it and starting a new one with the same id
            if ($id === $session_id) {
                return true;
            }
            // if a different session file with was open then close it
            session_write_close();
        }

        session_id($id);
        $started = session_start($this->requiredOptions + $options);
        // Restore previous session id if unable to start a new one
        if (! $started) {
            session_id($session_id);
        }

        return $started;
    }

    /**
     * Generate a session identifier.
     */
    private function generateId() : string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Estrapolate the request session id from the request Cookie header, if set.
     */
    private function getSessionIdFromRequest(ServerRequestInterface $request) : string
    {
        if (! $request->hasHeader('Cookie')) {
            return '';
        }

        $headerLine = $request->getHeaderLine('Cookie');
        if ('' === $headerLine) {
            return '';
        }

        $sessionName = session_name();

        $cookieStrings = preg_split('/\s*;\s*/', $headerLine);

        foreach ($cookieStrings as $cookieString) {
            $parts = explode('=', $cookieString);
            $name  = urldecode($parts[0]);
            if ($sessionName === $name) {
                return isset($parts[1]) ? urldecode($parts[1]) : '';
            }
        }

        return '';
    }

    /**
     * We send a response session cookie only if at least one of the following
     * conditions is met:
     *
     * 1. a new session lifetime has been provided
     * 2. the current session id differs from the request session id (new or
     *    regenerated)
     *
     * @param string $id The last opened session id
     */
    private function addSessionCookie(
        ResponseInterface $response,
        string $id,
        SessionInterface $session
    ) : ResponseInterface {
        // Add a response cookie if the id or the lifetime have changed
        $lifetime = $session->getLifetime();
        if (null !== $lifetime
            || $session->isNew()
            || $session->isRegenerated()
        ) {
            return $response = $response->withAddedHeader(
                'Set-Cookie',
                $this->createSetCookieHeaderLine($id, $lifetime)
            );
        }

        return $response;
    }

    /**
     * Build a response Set-Cookie header line from current session
     */
    private function createSetCookieHeaderLine(string $id, int $lifetime = null) : string
    {
        $parts = [
            urlencode(session_name()) . '=' . urlencode($id),
        ];

        $lifetime = $lifetime ?? (int) ini_get('session.cookie_lifetime');
        if ($lifetime > 0) {
            $parts[] = 'Expires=' . gmdate(self::HTTP_DATE_FORMAT, time() + $lifetime);
            $parts[] = 'Max-Age=' . $lifetime;
        }

        if ($domain = ini_get('session.cookie_domain')) {
            $parts[] = 'Domain=' . $domain;
        }

        if ($path = ini_get('session.cookie_path')) {
            $parts[] = 'Path=' . $path;
        }

        $filter_bool = FILTER_VALIDATE_BOOLEAN;
        $filter_flag = FILTER_NULL_ON_FAILURE;

        $secure = ini_get('session.cookie_secure');
        $secure = filter_var($secure, $filter_bool, $filter_flag);
        if ($secure) {
            $parts[] = 'Secure';
        }

        $httpOnly = ini_get('session.cookie_httponly');
        $httpOnly = filter_var($httpOnly, $filter_bool, $filter_flag);
        if ($httpOnly) {
            $parts[] = 'HttpOnly';
        }

        return implode('; ', $parts);
    }

    private function addCacheHeaders(ResponseInterface $response) : ResponseInterface
    {
        if (! $this->cacheLimiter
            || $this->responseAlreadyHasCacheHeaders($response)
        ) {
            return $response;
        }

        $cacheHeaders = $this->createCacheHeaders();
        foreach ($cacheHeaders as $name => $value) {
            if (false !== $value) {
                $response = $response->withHeader($name, $value);
            }
        }

        return $response;
    }

    /**
     * Generate cache http headers for this instance's session cache_limiter and
     * cache_expire values. This are always sent if a session was started and a
     * valid not-empty cache_limiter is set
     */
    private function createCacheHeaders() : array
    {
        // Unsupported cache_limiter
        if (! isset(self::$supported_cache_limiters[$this->cacheLimiter])) {
            return [];
        }

        // cache_limiter: 'nocache'
        if ('nocache' === $this->cacheLimiter) {
            return [
                'Expires'       => self::CACHE_PAST_DATE,
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
                'Pragma'        => 'no-cache',
            ];
        }

        $maxAge       = 60 * $this->cacheExpire;
        $lastModified = $this->getLastModified();

        // cache_limiter: 'public'
        if ('public' === $this->cacheLimiter) {
            return [
                'Expires'       => gmdate(self::HTTP_DATE_FORMAT, time() + $maxAge),
                'Cache-Control' => sprintf('public, max-age=%d', $maxAge),
                'Last-Modified' => $lastModified,
            ];
        }

        // cache_limiter: 'private'
        if ('private' === $this->cacheLimiter) {
            return [
                'Expires'       => self::CACHE_PAST_DATE,
                'Cache-Control' => sprintf('private, max-age=%d', $maxAge),
                'Last-Modified' => $lastModified,
            ];
        }

        // last possible case, cache_limiter = 'private_no_expire'
        return [
            'Cache-Control' => sprintf('private, max-age=%d', $maxAge),
            'Last-Modified' => $lastModified,
        ];
    }

    /**
     * Return the Last-Modified header line based on main script of execution
     * modified time. If unable to get a valid timestamp we use this class file
     * modification time as fallback.
     * @return string|false
     */
    private function getLastModified()
    {
        $lastmod = getlastmod() ?: filemtime(__FILE__);
        return $lastmod ? gmdate(self::HTTP_DATE_FORMAT, $lastmod) : false;
    }

    /**
     * Check if the response already carries cache headers
     */
    private function responseAlreadyHasCacheHeaders(ResponseInterface $response) : bool
    {
        return (
            $response->hasHeader('Expires')
            || $response->hasHeader('Last-Modified')
            || $response->hasHeader('Cache-Control')
            || $response->hasHeader('Pragma')
        );
    }
}
