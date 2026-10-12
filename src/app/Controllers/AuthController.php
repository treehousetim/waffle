<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Auth\AccountDisabled;
use App\Domain\Auth\AccountNotVerified;
use App\Domain\Auth\InvalidCredentials;
use App\Domain\Auth\Permissions;
use App\Domain\Auth\TooManyAttempts;
use App\Domain\Ports\IdentityPort;
use App\Libraries\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Session login, logout, and the identity endpoint the SPA's router guard calls.
 */
final class AuthController extends BaseController
{
    public function __construct(
        JsonResponder $json,
        private readonly IdentityPort $identity,
    ) {
        parent::__construct($json);
    }

    public function login(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->body($request);
        $email = $this->string($body, 'email');
        $password = $this->string($body, 'password');

        if ($email === '' || $password === '') {
            return $this->jsonError($response, 'Email address and password are required.', 422);
        }

        try {
            $this->identity->login($email, $password);
        } catch (InvalidCredentials $e) {
            return $this->jsonError($response, $e->getMessage(), 401);
        } catch (AccountDisabled | AccountNotVerified $e) {
            return $this->jsonError($response, $e->getMessage(), 403);
        } catch (TooManyAttempts $e) {
            return $this->jsonError($response, $e->getMessage(), 429);
        }

        return $this->jsonSuccess($response, $this->currentUser());
    }

    public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->identity->logout();

        return $this->jsonSuccess($response, null);
    }

    public function me(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->jsonSuccess($response, $this->currentUser());
    }

    /**
     * Who is signed in, and what they may do. `permissions` is what the
     * frontend hides pages, links and buttons by, so a level that cannot do
     * something is never shown it.
     *
     * @return array{id:?int,email:?string,username:?string,role:?string,role_label:?string,permissions:list<string>}
     */
    private function currentUser(): array
    {
        $role = $this->identity->role();

        return [
            'id' => $this->identity->id(),
            'email' => $this->identity->email(),
            'username' => $this->identity->username(),
            'role' => $role?->value,
            'role_label' => $role?->label(),
            'permissions' => Permissions::valuesFor($role),
        ];
    }
}
