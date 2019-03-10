<?php
/**
 * based on:
 *
 * @see       https://github.com/zendframework/zend-expressive-session for the canonical source repository
 * @copyright Copyright (c) 2017-2018 Zend Technologies USA Inc. (https://www.zend.com)
 * @license   https://github.com/zendframework/zend-expressive-session/blob/master/LICENSE.md New BSD License
 */

declare(strict_types=1);

namespace App\Session;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

use App\Session\SessionPersistenceInterface;
use App\Session\SessionInterface;

class SessionMiddleware implements MiddlewareInterface
{
    /**
     * @var SessionPersistenceInterface
     */
    private $persistence;

    public function __construct(SessionPersistenceInterface $persistence)
    {
        $this->persistence = $persistence;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler) : ResponseInterface
    {
        // If a session is already in the request attributes (for instance a
        // stateless session to feed to bots) then skip adding it
        $session = $request->getAttribute(SessionInterface::class);
        if ($session instanceof SessionInterface) {
            return $handler->handle($request);
        }

        $session = $this->persistence->initializeSessionFromRequest($request);
        $response = $handler->handle(
            $request->withAttribute(SessionInterface::class, $session)
        );

        return $this->persistence->persistSession($session, $response);
    }
}
