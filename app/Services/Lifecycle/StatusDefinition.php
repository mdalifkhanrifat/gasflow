<?php

namespace App\Services\Lifecycle;

/**
 * One row of status_definitions: a status of an entity with its labels and tone.
 */
final readonly class StatusDefinition
{
    /**
     * @param  array<string, string>  $translatedLabels  locale => label (English lives in $label)
     */
    public function __construct(
        public string $code,
        public string $label,
        public string $tone,
        public ?bool $impliesActive,
        public bool $isInitial,
        public bool $isTerminal,
        public int $sortOrder,
        public array $translatedLabels = [],
    ) {}

    /**
     * The label in the given locale, falling back to English.
     */
    public function labelFor(string $locale): string
    {
        return $this->translatedLabels[$locale] ?? $this->label;
    }

    /**
     * @param  array{code: string, label: string, tone: string, implies_active: ?bool, is_initial: bool, is_terminal: bool, sort_order: int, translations: string}  $row
     */
    public static function fromRow(array $row): self
    {
        /** @var array<string, array{label?: string}> $translations */
        $translations = json_decode($row['translations'], true) ?: [];
        $labels = [];
        foreach ($translations as $locale => $values) {
            if (isset($values['label'])) {
                $labels[$locale] = $values['label'];
            }
        }

        return new self(
            code: $row['code'],
            label: $row['label'],
            tone: $row['tone'],
            impliesActive: $row['implies_active'],
            isInitial: $row['is_initial'],
            isTerminal: $row['is_terminal'],
            sortOrder: $row['sort_order'],
            translatedLabels: $labels,
        );
    }
}
