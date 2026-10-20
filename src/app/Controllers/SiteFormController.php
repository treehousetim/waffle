<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Forms\InvalidForm;
use App\Domain\Forms\SitePath;
use App\Domain\Forms\Visitor;
use App\Libraries\Forms\FormsNotFound;
use App\Libraries\Forms\Reception;
use App\Libraries\Forms\TooManySubmissions;
use App\Libraries\Http\JsonResponder;
use App\Models\Forms\Site;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A site's form, received (docs/forms.md). JSON gets JSON back; a
 * form-encoded post (a browser without script) gets a 303 to a path on the
 * site (8., 9.).
 */
final class SiteFormController extends BaseController
{
    public function __construct(
        JsonResponder $json,
        private readonly Reception $reception,
    ) {
        parent::__construct($json);
    }

    public function receive(ServerRequestInterface $request, ResponseInterface $response, string $name): ResponseInterface
    {
        /** @var Site $site */
        $site = $request->getAttribute('site');
        $input = $this->body($request);
        $address = Visitor::address($request->getHeaderLine('X-Forwarded-For'), (string) ($request->getServerParams()['REMOTE_ADDR'] ?? ''));
        $browser = !str_starts_with(strtolower($request->getHeaderLine('Content-Type')), 'application/json');

        try {
            $thanks = $this->reception->receive($site, $name, $input, $address);
        } catch (FormsNotFound $e) {
            return $browser ? $this->back($response, $input) : $this->jsonError($response, $e->getMessage(), 404);
        } catch (TooManySubmissions $e) {
            return $browser ? $this->back($response, $input) : $this->jsonError($response, $e->getMessage(), 429);
        } catch (InvalidForm $e) {
            return $browser
                ? $this->back($response, $input)
                : $this->json->write($response, ['success' => false, 'error' => $e->getMessage(), 'fields' => $e->fields], 422);
        }

        if ($browser) {
            return $response->withStatus(303)->withHeader('Location', SitePath::or($input['redirect'] ?? null));
        }

        return $this->jsonSuccess($response, ['message' => $thanks]);
    }

    /** @param array<string,mixed> $input */
    private function back(ResponseInterface $response, array $input): ResponseInterface
    {
        return $response->withStatus(303)->withHeader('Location', SitePath::or($input['back'] ?? null));
    }
}
