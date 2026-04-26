<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Validation;

use Laminas\Filter\StringTrim;
use Laminas\Filter\ToInt;
use Laminas\InputFilter\Input;
use Laminas\InputFilter\InputFilter;
use Laminas\Validator\Between;
use Laminas\Validator\NotEmpty;
use Laminas\Validator\StringLength;

/**
 * Validates DNS record create/edit form fields.
 *
 * - name    : required, 1-255 chars
 * - type    : required, 1-32 chars (further type validation is done by RecordType enum)
 * - ttl     : integer 30-604800 (RecordValidator::MIN_TTL / MAX_TTL)
 * - content : required, max 65 535 chars
 *
 * @template-extends InputFilter<array<string, mixed>>
 */
final class RecordInputFilter extends InputFilter
{
    public function __construct()
    {
        $name = new Input('name');
        $name->getFilterChain()->attach(new StringTrim());
        $name->getValidatorChain()
            ->attach(new NotEmpty(), true)
            ->attach(new StringLength(['min' => 1, 'max' => 255]));

        $type = new Input('type');
        $type->getFilterChain()->attach(new StringTrim());
        $type->getValidatorChain()
            ->attach(new NotEmpty(), true)
            ->attach(new StringLength(['min' => 1, 'max' => 32]));

        $ttl = new Input('ttl');
        $ttl->getFilterChain()->attach(new ToInt());
        $ttl->getValidatorChain()
            ->attach(new Between(['min' => 30, 'max' => 604800, 'inclusive' => true]));

        $content = new Input('content');
        $content->getFilterChain()->attach(new StringTrim());
        $content->getValidatorChain()
            ->attach(new NotEmpty(), true)
            ->attach(new StringLength(['min' => 1, 'max' => 65535]));

        $this->add($name);
        $this->add($type);
        $this->add($ttl);
        $this->add($content);
    }
}
