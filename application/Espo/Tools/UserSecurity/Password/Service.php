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

namespace Espo\Tools\UserSecurity\Password;

use Espo\Core\Authentication\Logins\Espo;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\FieldValidation\FieldValidationManager;
use Espo\Core\Mail\EmailSender;
use Espo\Core\Mail\Exceptions\SendingError;
use Espo\Core\ORM\Repository\SaveOption;
use Espo\Core\Record\ServiceContainer;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\PasswordHash;
use Espo\Entities\User;
use Espo\ORM\EntityManager;

class Service
{
    private User $user;
    private ServiceContainer $serviceContainer;
    private EmailSender $emailSender;
    private Config $config;
    private Generator $generator;
    private Sender $sender;
    private PasswordHash $passwordHash;
    private EntityManager $entityManager;
    private RecoveryService $recovery;
    private FieldValidationManager $fieldValidationManager;
    private Checker $checker;

    public function __construct(
        User $user,
        ServiceContainer $serviceContainer,
        EmailSender $emailSender,
        Config $config,
        Generator $generator,
        Sender $sender,
        PasswordHash $passwordHash,
        EntityManager $entityManager,
        RecoveryService $recovery,
        FieldValidationManager $fieldValidationManager,
        Checker $checker
    ) {
        $this->user = $user;
        $this->serviceContainer = $serviceContainer;
        $this->emailSender = $emailSender;
        $this->config = $config;
        $this->generator = $generator;
        $this->sender = $sender;
        $this->passwordHash = $passwordHash;
        $this->entityManager = $entityManager;
        $this->recovery = $recovery;
        $this->fieldValidationManager = $fieldValidationManager;
        $this->checker = $checker;
    }

    /**
     * Create and send a password recovery link in an email. Only for admin.
     *
     * @throws Forbidden
     * @throws NotFound
     * @throws Error
     */
    public function createAndSendPasswordRecovery(string $id): void
    {
        if (!$this->user->isAdmin()) {
            throw new Forbidden();
        }

        /** @var ?User $user */
        $user = $this->entityManager->getEntityById(User::ENTITY_TYPE, $id);

        if (!$user) {
            throw new NotFound();
        }

        if (!$user->isActive()) {
            throw new Forbidden("User is not active.");
        }

        if (
            !$user->isRegular() &&
            !$user->isAdmin() &&
            !$user->isPortal()
        ) {
            throw new Forbidden();
        }

        $this->recovery->createAndSendRequestForExistingUser($user);
    }

    /**
     * Change a password by a recovery request.
     *
     * @param string $requestId A request ID.
     * @param string $password A new password.
     *
     * @return ?string A URL to suggest to a user.
     * @throws Error
     * @throws Forbidden
     * @throws NotFound
     */
    public function changePasswordByRecovery(string $requestId, string $password): ?string
    {
        $request = $this->recovery->getRequest($requestId);

        $this->changePassword($request->getUserId(), $password);
        $this->recovery->removeRequest($requestId);

        return $request->getUrl();
    }

    /**
     * Change a password with a current password check.
     *
     * @throws Forbidden
     * @throws NotFound
     * @throws Error
     */
    public function changePasswordWithCheck(string $userId, string $password, string $currentPassword): void
    {
        $this->changePasswordInternal($userId, $password, true, $currentPassword);
    }

    /**
     * Change a password.
     *
     * @throws Forbidden
     * @throws NotFound
     * @throws Error
     */
    private function changePassword(string $userId, string $password): void
    {
        $this->changePasswordInternal($userId, $password);
    }

    /**
     * @throws Forbidden
     * @throws Error
     * @throws NotFound
     */
    private function changePasswordInternal(
        string $userId,
        string $password,
        bool $checkCurrentPassword = false,
        ?string $currentPassword = null
    ): void {

        /** @var ?User $user */
        $user = $this->entityManager->getEntityById(User::ENTITY_TYPE, $userId);

        if (!$user) {
            throw new NotFound();
        }

        if (
            $user->isSuperAdmin() &&
            !$this->user->isSuperAdmin()
        ) {
            throw new Forbidden();
        }

        $authenticationMethod = $this->config->get('authenticationMethod', Espo::NAME);

        if (!$user->isAdmin() && $authenticationMethod !== Espo::NAME) {
            throw new Forbidden("Authentication method is not `Espo`.");
        }

        if (empty($password)) {
            throw new Error("Password can't be empty.");
        }

        if ($checkCurrentPassword) {
            $u = $this->entityManager
                ->getRDBRepository(User::ENTITY_TYPE)
                ->where([
                    'id' => $user->getId(),
                    'password' => $this->passwordHash->hash($currentPassword ?? ''),
                ])
                ->findOne();

            if (!$u) {
                throw new Forbidden("Wrong password.");
            }
        }

        if (!$this->checker->checkStrength($password)) {
            throw new Forbidden("Password is weak.");
        }

        $validLength = $this->fieldValidationManager->check(
            $user,
            'password',
            'maxLength',
            (object) ['password' => $password]
        );

        if (!$validLength) {
            throw new Forbidden("Password exceeds max length.");
        }

        $user->set('password', $this->passwordHash->hash($password));

        $this->entityManager->saveEntity($user);
    }

    /**
     * Send access info for a new user.
     *
     * @throws Error
     * @throws SendingError
     */
    public function sendAccessInfoForNewUser(User $user): void
    {
        $emailAddress = $user->getEmailAddress();

        if ($emailAddress === null) {
            throw new Error("Can't send access info for user '{$user->getId()}' w/o email address.");
        }

        if (!$this->isSmtpConfigured()) {
            throw new Error("Can't send access info. SMTP is not configured.");
        }

        $stubPassword = $this->generator->generate();

        $this->savePasswordSilent($user, $stubPassword);

        $request = $this->recovery->createRequestForNewUser($user);

        $this->sender->sendAccessInfo($user, $request);
    }

    /**
     * Generate a new password and send it in an email. Only for admin.
     *
     * @throws Forbidden
     * @throws NotFound
     * @throws SendingError
     * @throws Error
     */
    public function generateAndSendNewPasswordForUser(string $id): void
    {
        if (!$this->user->isAdmin()) {
            throw new Forbidden();
        }

        /** @var ?User $user */
        $user = $this->serviceContainer
            ->get(User::ENTITY_TYPE)
            ->getEntity($id);

        if (!$user) {
            throw new NotFound();
        }

        if ($user->isApi()) {
            throw new Forbidden();
        }

        if ($user->isSuperAdmin()) {
            throw new Forbidden();
        }

        if ($user->isSystem()) {
            throw new Forbidden();
        }

        if (!$user->getEmailAddress()) {
            throw new Forbidden("Generate new password: Can't process because user doesn't have email address.");
        }

        if (!$this->isSmtpConfigured()) {
            throw new Forbidden("Generate new password: Can't process because SMTP is not configured.");
        }

        $password = $this->generator->generate();

        $this->sender->sendPassword($user, $password);
        $this->savePassword($user, $password);
    }

    private function savePassword(User $user, string $password): void
    {
        $user->set('password', $this->passwordHash->hash($password));

        $this->entityManager->saveEntity($user);
    }

    private function savePasswordSilent(User $user, string $password): void
    {
        $user->set('password', $this->passwordHash->hash($password));

        $this->entityManager->saveEntity($user, [SaveOption::SILENT => true]);
    }

    private function isSmtpConfigured(): bool
    {
        return
            $this->emailSender->hasSystemSmtp() ||
            $this->config->get('internalSmtpServer');
    }
}
