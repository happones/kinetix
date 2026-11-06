<?php

declare(strict_types=1);

namespace Happones\Kinetix\Membership;

use Happones\Kinetix\Data\FormData;
use Happones\Kinetix\Forms\Form;

/**
 * The form of a member's custom fields, held to the fields SHOWN to the
 * acting admin: a field is shown unless it or a layout around it is hidden.
 * Whatever is not shown takes no rules (a required field in a hidden section
 * cannot block a save), no submitted value, and never travels to the browser.
 *
 * @internal
 */
final class MemberForm extends Form
{
    /** @var array<string, true> */
    private array $shown = [];

    /**
     * @param array<int, string> $names
     */
    public function restrictTo(array $names): static
    {
        $this->shown = array_fill_keys($names, true);

        return $this;
    }

    /**
     * @return array<int, string>
     */
    public function shownNames(): array
    {
        return array_map('strval', array_keys($this->shown));
    }

    public function getValidationRules(array $data = []): array
    {
        return array_intersect_key(parent::getValidationRules($data), $this->shown);
    }

    public function getState(array $inputData = []): array
    {
        return array_intersect_key(parent::getState($inputData), $this->shown);
    }

    public function toData(): FormData
    {
        $data       = parent::toData();
        $data->data = array_intersect_key($data->data, $this->shown);

        return $data;
    }

    /**
     * Member forms are rebuilt by the membership endpoints, never by the
     * recompute endpoint (reactivity is not supported there).
     */
    protected function buildRecomputeDescriptor(): ?string
    {
        return null;
    }
}
