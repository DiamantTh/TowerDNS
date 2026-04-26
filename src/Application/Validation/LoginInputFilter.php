<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Validation;

use Laminas\Filter\StringToLower;
use Laminas\Filter\StringTrim;
use Laminas\InputFilter\Input;
use Laminas\InputFilter\InputFilter;
use Laminas\Validator\EmailAddress;
use Laminas\Validator\NotEmpty;
use Laminas\Validator\StringLength;

/**
 * Validates the login form (e-mail + password fields).
 *
 * @template-extends InputFilter<array<string, mixed>>
 */
final class LoginInputFilter extends InputFilter
{
    public function __construct()
    {
        $email = new Input('email');
        $email->getFilterChain()
            ->attach(new StringTrim())
            ->attach(new StringToLower());
        $email->getValidatorChain()
            ->attach(new NotEmpty(), true)
            ->attach(new EmailAddress(['useMxCheck' => false]))
            ->attach(new StringLength(['max' => 255]));

        $password = new Input('password');
        $password->getValidatorChain()
            ->attach(new NotEmpty());

        $this->add($email);
        $this->add($password);
    }
}
