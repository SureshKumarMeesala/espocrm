<?php
/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM - Open Source CRM application.
 * Copyright (C) 2014-2021 Yurii Kuznietsov, Taras Machyshyn, Oleksii Avramenko
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
 * along with EspoCRM. If not, see http://www.gnu.org/licenses/.phpppph
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
 ************************************************************************/

namespace Espo\Modules\Crm\EntryPoints;

use Espo\Core\Exceptions\NotFound;
use Espo\Core\Exceptions\BadRequest;

use Espo\Core\EntryPoints\{
    EntryPoint,
    NoAuth,
};

use Espo\Core\{
    Api\Request,
    Api\Response,
    ORM\EntityManager,
    Utils\ClientManager,
    HookManager,
    Utils\Config,
    Utils\Metadata,
    Utils\Hasher,
    ServiceFactory,
};

class Unsubscribe implements EntryPoint
{
    use NoAuth;

    protected $entityManager;
    protected $clientManager;
    protected $hookManager;
    protected $config;
    protected $metadata;
    protected $hasher;
    protected $serviceFactory;

    public function __construct(
        EntityManager $entityManager,
        ClientManager $clientManager,
        HookManager $hookManager,
        Config $config,
        Metadata $metadata,
        Hasher $hasher,
        ServiceFactory $serviceFactory
    ) {
        $this->entityManager = $entityManager;
        $this->clientManager = $clientManager;
        $this->hookManager = $hookManager;
        $this->config = $config;
        $this->metadata = $metadata;
        $this->hasher = $hasher;
        $this->serviceFactory = $serviceFactory;
    }

    public function run(Request $request, Response $response) : void
    {
        $id = $request->get('id') ?? null;
        $emailAddress = $request->get('emailAddress') ?? null;
        $hash = $request->get('hash') ?? null;

        if ($emailAddress && $hash) {
            $this->processWithHash($emailAddress, $hash);

            return;
        }

        if (!$id) {
            throw new BadRequest();
        }

        $queueItemId = $id;

        $queueItem = $this->entityManager->getEntity('EmailQueueItem', $queueItemId);

        if (!$queueItem) {
            throw new NotFound();
        }

        $campaign = null;
        $target = null;

        $massEmailId = $queueItem->get('massEmailId');

        if ($massEmailId) {
            $massEmail = $this->entityManager->getEntity('MassEmail', $massEmailId);

            if ($massEmail) {
                $campaignId = $massEmail->get('campaignId');

                if ($campaignId) {
                    $campaign = $this->entityManager->getEntity('Campaign', $campaignId);
                }

                $targetType = $queueItem->get('targetType');
                $targetId = $queueItem->get('targetId');

                if ($targetType && $targetId) {
                    $target = $this->entityManager->getEntity($targetType, $targetId);

                    if (!$target) {
                        throw new NotFound();
                    }

                    if ($massEmail->get('optOutEntirely')) {
                        $emailAddress = $target->get('emailAddress');

                        if ($emailAddress) {
                            $ea = $this->entityManager->getRepository('EmailAddress')->getByAddress($emailAddress);

                            if ($ea) {
                                $ea->set('optOut', true);
                                $this->entityManager->saveEntity($ea);
                            }
                        }
                    }

                    $link = null;

                    $m = [
                        'Account' => 'accounts',
                        'Contact' => 'contacts',
                        'Lead' => 'leads',
                        'User' => 'users',
                    ];

                    if (!empty($m[$target->getEntityType()])) {
                        $link = $m[$target->getEntityType()];
                    }

                    if ($link) {
                        $targetListList = $this->entityManager
                            ->getRepository('MassEmail')
                            ->getRelation($massEmail, 'targetLists')
                            ->find();

                        foreach ($targetListList as $targetList) {
                            $optedOutResult = $this->entityManager
                                ->getRepository('TargetList')
                                ->updateRelation($targetList, $link, $target->id, ['optedOut' => true]);

                            if ($optedOutResult) {
                                $hookData = [
                                   'link' => $link,
                                   'targetId' => $targetId,
                                   'targetType' => $targetType,
                                ];

                                $this->hookManager->process('TargetList', 'afterOptOut', $targetList, [], $hookData);
                            }
                        }

                        $this->hookManager->process($target->getEntityType(), 'afterOptOut', $target, [], []);

                        $this->display(['queueItemId' => $queueItemId]);
                    }
                }
            }
        }

        if ($campaign && $target) {
            $campaignService = $this->serviceFactory->create('Campaign');

            $campaignService->logOptedOut(
                $campaignId, $queueItemId, $target, $queueItem->get('emailAddress'), null, $queueItem->get('isTest')
            );
        }
    }

    protected function display(array $actionData)
    {
        $data = [
            'actionData' => $actionData,
            'view' => $this->metadata->get(['clientDefs', 'Campaign', 'unsubscribeView']),
            'template' => $this->metadata->get(['clientDefs', 'Campaign', 'unsubscribeTemplate']),
        ];

        $runScript = "
            Espo.require('crm:controllers/unsubscribe', function (Controller) {
                var controller = new Controller(app.baseController.params, app.getControllerInjection());
                controller.masterView = app.masterView;
                controller.doAction('unsubscribe', ".json_encode($data).");
            });
        ";

        $this->clientManager->display($runScript);
    }

    protected function processWithHash(string $emailAddress, string $hash)
    {
        $hash2 = $this->hasher->hash($emailAddress);

        if ($hash2 !== $hash) {
            throw new NotFound();
        }

        $repository = $this->entityManager->getRepository('EmailAddress');

        $ea = $repository->getByAddress($emailAddress);

        if ($ea) {
            $entityList = $repository->getEntityListByAddressId($ea->id);

            if (!$ea->get('optOut')) {
                $ea->set('optOut', true);
                $this->entityManager->saveEntity($ea);

                foreach ($entityList as $entity) {
                    $this->hookManager->process($entity->getEntityType(), 'afterOptOut', $entity, [], []);
                }
            }

            $this->display([
                'emailAddress' => $emailAddress,
                'hash' => $hash,
            ]);
        }
        else {
            throw new NotFound();
        }
    }
}
