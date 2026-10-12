<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Settings\InvalidSetting;
use App\Libraries\Http\JsonResponder;
use App\Models\Setting;
use App\Models\SettingRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The Settings page: read every setting, change the editable ones.
 *
 *   GET /api/settings                        -> { settings: [...] }
 *   PUT /api/settings  { values: {key: v} }  -> { settings: [...] }
 *
 * A save is all or nothing: every value is checked before any is written, so a
 * form with one bad field changes nothing.
 */
final class SettingController extends BaseController
{
    public function __construct(
        JsonResponder $json,
        private readonly SettingRepository $settings,
    ) {
        parent::__construct($json);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->jsonSuccess($response, ['settings' => $this->listed()]);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $values = $this->body($request)['values'] ?? null;

        if (!is_array($values) || $values === []) {
            return $this->jsonError($response, 'Send the settings to change as `values`: {key: value}.', 422);
        }

        $accepted = [];

        foreach ($values as $key => $raw) {
            $setting = $this->settings->find((string) $key);

            if ($setting === null) {
                return $this->jsonError($response, "There is no setting called '{$key}'.", 422);
            }

            if (!$setting->editable) {
                return $this->jsonError($response, "{$setting->label} cannot be changed here.", 422);
            }

            try {
                $accepted[$setting->key] = $setting->type->normalise($raw, $setting->options);
            } catch (InvalidSetting $e) {
                return $this->jsonError($response, "{$setting->label} {$e->getMessage()}.", 422);
            }
        }

        foreach ($accepted as $key => $value) {
            $this->settings->update($key, $value);
        }

        return $this->jsonSuccess($response, ['settings' => $this->listed()]);
    }

    /** @return list<array<string,mixed>> */
    private function listed(): array
    {
        return array_map(static fn (Setting $setting): array => $setting->toArray(), $this->settings->all());
    }
}
