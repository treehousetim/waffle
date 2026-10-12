<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Settings\SettingType;

/**
 * One row of `settings`: a value, and everything the Settings page needs to
 * render and validate it.
 */
final readonly class Setting
{
    /**
     * @param list<string> $options the allowed values, for a select
     */
    public function __construct(
        public string $key,
        public string $value,
        public string $label,
        public string $description,
        public SettingType $type,
        public array $options,
        public string $group,
        public bool $editable,
        public int $sort,
        public ?string $updatedAt,
    ) {
    }

    /**
     * @return array{key:string,value:string,label:string,description:string,type:string,options:list<string>,group:string,editable:bool,updated_at:?string}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'value' => $this->value,
            'label' => $this->label,
            'description' => $this->description,
            'type' => $this->type->value,
            'options' => $this->options,
            'group' => $this->group,
            'editable' => $this->editable,
            'updated_at' => $this->updatedAt,
        ];
    }
}
