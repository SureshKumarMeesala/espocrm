<?php
/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM - Open Source CRM application.
 * Copyright (C) 2014-2022 Yurii Kuznietsov, Taras Machyshyn, Oleksii Avramenko
 * Website: https://www.espocrm.com
 *
 * EspoCRM is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * EspoCRM is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with EspoCRM. If not, see http://www.gnu.org/licenses/.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
 ************************************************************************/

namespace Espo\Core\Authentication;

use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Metadata;
use Espo\Core\Utils\Config;

class LoginFactory
{
    private const DEFAULT_METHOD = 'Espo';

    private InjectableFactory $injectableFactory;
    private Metadata $metadata;
    private Config $config;

    public function __construct(InjectableFactory $injectableFactory, Metadata $metadata, Config $config)
    {
        $this->injectableFactory = $injectableFactory;
        $this->metadata = $metadata;
        $this->config = $config;
    }

    public function create(string $method, bool $isPortal = false): Login
    {
        /** @var class-string<Login> $className */
        $className = $this->metadata->get(['authenticationMethods', $method, 'implementationClassName']);

        if (!$className) {
            $sanitizedName = preg_replace('/[^a-zA-Z0-9]+/', '', $method);

            if (!class_exists($className)) {
                /** @var class-string<Login> $className */
                $className = "Espo\\Core\\Authentication\\Logins\\" . $sanitizedName;
            }
        }

        return $this->injectableFactory->createWith($className, [
            'isPortal' => $isPortal,
        ]);
    }

    public function createDefault(): Login
    {
        $method = $this->config->get('authenticationMethod', self::DEFAULT_METHOD);

        return $this->create($method);
    }
}
