<?php

declare(strict_types=1);

namespace App\Libraries\Forms;

use App\Domain\Forms\InvalidForm;
use App\Domain\Forms\SiteInput;
use App\Domain\Forms\SiteKey;
use App\Domain\Forms\SiteStatus;
use App\Domain\Ports\ClockPort;
use App\Domain\Status\IllegalTransition;
use App\Models\Forms\Site;
use App\Models\Forms\SiteRepository;
use PDOException;

/** Managing the sites that send forms, and their keys (docs/forms.md). */
final readonly class Sites
{
    public function __construct(
        private SiteRepository $sites,
        private ClockPort $clock,
    ) {
    }

    /** @throws InvalidForm */
    public function create(mixed $slug, mixed $name, mixed $origin): Site
    {
        $input = SiteInput::parse($slug, $name, $origin);

        try {
            $id = $this->sites->insert($input, $this->clock->now());
        } catch (PDOException $e) {
            throw ($e->errorInfo[1] ?? null) === 1062 ? InvalidForm::because("There is already a site called '{$input->slug}'.") : $e;
        }

        return $this->mustFind($id);
    }

    /**
     * A new key for the site, replacing any before it at once: returned this
     * once, kept only as its hash (2.).
     *
     * @return array{0:Site,1:string}
     *
     * @throws FormsNotFound
     */
    public function issueKey(int $id): array
    {
        $this->mustFind($id);
        [$key, $hash] = SiteKey::issue();
        $this->sites->setKeyHash($id, $hash, $this->clock->now());

        return [$this->mustFind($id), $key];
    }

    /** @throws FormsNotFound|IllegalTransition */
    public function setStatus(int $id, SiteStatus $status): Site
    {
        $this->sites->setStatus($this->mustFind($id), $status, $this->clock->now());

        return $this->mustFind($id);
    }

    private function mustFind(int $id): Site
    {
        return $this->sites->find($id) ?? throw new FormsNotFound('There is no site with that id.');
    }
}
