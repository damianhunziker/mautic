<?php

namespace Mautic\CampaignBundle\Controller\Api;

use Doctrine\Persistence\ManagerRegistry;
use Mautic\ApiBundle\Controller\CommonApiController;
use Mautic\ApiBundle\Helper\EntityResultHelper;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CampaignBundle\EventCollector\EventCollector;
use Mautic\CampaignBundle\Model\CampaignModel;
use Mautic\CampaignBundle\Model\EventModel;
use Mautic\CoreBundle\Factory\ModelFactory;
use Mautic\CoreBundle\Helper\AppVersion;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\CoreBundle\Translation\Translator;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;

class CampaignCanvasApiController extends CommonApiController
{
    public function __construct(
        CorePermissions $security,
        Translator $translator,
        EntityResultHelper $entityResultHelper,
        RouterInterface $router,
        FormFactoryInterface $formFactory,
        AppVersion $appVersion,
        RequestStack $requestStack,
        ManagerRegistry $doctrine,
        ModelFactory $modelFactory,
        EventDispatcherInterface $dispatcher,
        CoreParametersHelper $coreParametersHelper,
        private EventModel $eventModel,
        private EventCollector $eventCollector,
        private CampaignModel $campaignModel,
    ) {
        $this->model             = $this->campaignModel;
        $this->entityClass       = Campaign::class;
        $this->entityNameOne     = 'campaign';
        $this->entityNameMulti   = 'campaigns';
        $this->permissionBase    = 'campaign:campaigns';
        $this->serializerGroups  = [
            'campaignDetails',
            'campaignEventDetails',
            'categoryList',
            'publishDetails',
            'leadListList',
            'formList',
        ];

        parent::__construct(
            $security,
            $translator,
            $entityResultHelper,
            $router,
            $formFactory,
            $appVersion,
            $requestStack,
            $doctrine,
            $modelFactory,
            $dispatcher,
            $coreParametersHelper
        );
    }

    public function getEventTypesAction(): Response
    {
        $events = $this->eventCollector->getEvents();

        $result = [
            'actions'    => $this->serializeEventConfig($events->getActions()),
            'conditions' => $this->serializeEventConfig($events->getConditions()),
            'decisions'  => $this->serializeEventConfig($events->getDecisions()),
        ];

        $view = $this->view($result, Response::HTTP_OK);

        return $this->handleView($view);
    }

    public function newEventAction(Request $request, $id): Response
    {
        $campaign = $this->campaignModel->getEntity($id);
        if (null === $campaign) {
            return $this->notFound();
        }

        if (!$this->checkEntityAccess($campaign, 'edit')) {
            return $this->accessDenied();
        }

        $parameters = $this->getRequestParameters($request);

        // Block empty properties
        if (array_key_exists('properties', $parameters) && is_array($parameters['properties']) && empty($parameters['properties'])) {
            $view = $this->view(['error' => 'properties cannot be empty'], Response::HTTP_BAD_REQUEST);

            return $this->handleView($view);
        }

        $tempId = 'new_'.bin2hex(random_bytes(8));

        $sessionEvents  = $this->buildCurrentEventsArray($campaign);
        $canvasSettings = $this->getCurrentCanvasSettings($campaign);

        $eventData = [
            'id'                          => $tempId,
            'name'                        => $parameters['name'] ?? '',
            'type'                        => $parameters['type'] ?? '',
            'eventType'                   => $parameters['eventType'] ?? Event::TYPE_ACTION,
            'order'                       => $parameters['order'] ?? (count($sessionEvents) + 1),
            'properties'                  => $parameters['properties'] ?? [],
            'triggerMode'                 => $parameters['triggerMode'] ?? Event::TRIGGER_MODE_IMMEDIATE,
            'triggerInterval'             => $parameters['triggerInterval'] ?? 0,
            'triggerIntervalUnit'         => $parameters['triggerIntervalUnit'] ?? null,
            'triggerDate'                 => $parameters['triggerDate'] ?? null,
            'triggerHour'                 => $parameters['triggerHour'] ?? null,
            'triggerRestrictedStartHour'  => $parameters['triggerRestrictedStartHour'] ?? null,
            'triggerRestrictedStopHour'   => $parameters['triggerRestrictedStopHour'] ?? null,
            'triggerRestrictedDaysOfWeek' => $parameters['triggerRestrictedDaysOfWeek'] ?? [],
            'triggerWindow'               => $parameters['triggerWindow'] ?? null,
            'description'                 => $parameters['description'] ?? '',
            'decisionPath'                => null,
            'tempId'                      => $tempId,
            'children'                    => [],
            'parent'                      => null,
            'channel'                     => $parameters['channel'] ?? null,
            'channelId'                   => $parameters['channelId'] ?? null,
        ];

        $sessionEvents[$tempId] = $eventData;

        $canvasSettings['nodes'][] = [
            'id'        => $tempId,
            'positionX' => $parameters['positionX'] ?? 320,
            'positionY' => $parameters['positionY'] ?? 160,
        ];

        $this->campaignModel->setEvents($campaign, $sessionEvents, $canvasSettings, []);
        $this->campaignModel->saveEntity($campaign);
        $this->campaignModel->setCanvasSettings($campaign, $canvasSettings);

        $createdEvent = null;
        foreach ($campaign->getEvents() as $event) {
            if ($event->getTempId() === $tempId || $event->getName() === $parameters['name']) {
                $createdEvent = $event;
            }
        }

        if (!$createdEvent) {
            $createdEvent = $campaign->getEvents()->last();
        }

        $view = $this->view(
            ['event' => $createdEvent, 'campaign_id' => $campaign->getId()],
            Response::HTTP_CREATED
        );
        $this->setSerializationContext($view);

        return $this->handleView($view);
    }

    public function editEventAction(Request $request, $eventId): Response
    {
        $event = $this->eventModel->getEntity($eventId);
        if (null === $event || $event->isDeleted()) {
            return $this->notFound();
        }

        $campaign = $event->getCampaign();
        if (!$this->checkEntityAccess($campaign, 'edit')) {
            return $this->accessDenied();
        }

        $parameters = $this->getRequestParameters($request);

        // Block empty properties
        if (array_key_exists('properties', $parameters) && is_array($parameters['properties']) && empty($parameters['properties'])) {
            $view = $this->view(['error' => 'properties cannot be empty'], Response::HTTP_BAD_REQUEST);

            return $this->handleView($view);
        }

        $updatableFields = [
            'name', 'description', 'type', 'eventType', 'order',
            'properties', 'triggerMode', 'triggerInterval', 'triggerIntervalUnit',
            'triggerDate', 'triggerHour', 'triggerRestrictedStartHour',
            'triggerRestrictedStopHour', 'triggerRestrictedDaysOfWeek',
            'triggerWindow', 'channel', 'channelId', 'decisionPath',
        ];

        foreach ($updatableFields as $field) {
            if (array_key_exists($field, $parameters)) {
                $setter = 'set'.ucfirst($field);
                if (method_exists($event, $setter)) {
                    $event->$setter($parameters[$field]);
                }
            }
        }

        // Handle parentId: set or remove parent relationship
        if (array_key_exists('parentId', $parameters)) {
            $parentId        = $parameters['parentId'];
            $currentParent   = $event->getParent();
            $currentParentId = $currentParent ? $currentParent->getId() : null;

            if (null === $parentId || '' === $parentId || 0 === $parentId) {
                // Remove parent — always allowed
                if ($currentParent) {
                    $currentParent->removeChild($event);
                    $event->removeParent();
                }
            } else {
                $newParentId = (int) $parentId;

                // Block if this event already has a different parent
                if (null !== $currentParentId && $currentParentId !== $newParentId) {
                    $view = $this->view(
                        ['error' => 'Event already has a parent ('.$currentParentId.'). Remove it first with parentId: null or via DELETE /campaigns/{campaignId}/connections.'],
                        Response::HTTP_CONFLICT
                    );

                    return $this->handleView($view);
                }

                // Set new parent
                $parentEvent = $this->eventModel->getEntity($newParentId);
                if (null === $parentEvent || $parentEvent->isDeleted()) {
                    $view = $this->view(['error' => 'parentId event not found'], Response::HTTP_BAD_REQUEST);

                    return $this->handleView($view);
                }
                if ($parentEvent->getCampaign()->getId() !== $campaign->getId()) {
                    $view = $this->view(['error' => 'parent event must belong to the same campaign'], Response::HTTP_BAD_REQUEST);

                    return $this->handleView($view);
                }

                if ($currentParent) {
                    $currentParent->removeChild($event);
                }
                $event->setParent($parentEvent);
                $parentEvent->addChild($event);
            }

            // Sync canvasSettings with the new parent relationship
            $canvasSettings = $this->getCurrentCanvasSettings($campaign);
            $eventIdStr     = (string) $event->getId();

            // Remove all connections targeting this event
            $canvasSettings['connections'] = array_values(
                array_filter(
                    $canvasSettings['connections'] ?? [],
                    fn ($conn) => (string) ($conn['targetId'] ?? '') !== $eventIdStr
                )
            );

            // Add new connection if parent is set
            $newParent = $event->getParent();
            if ($newParent) {
                // Determine correct anchor: decisions use yes/no, actions use bottom
                $parentEventType = $newParent->getEventType();
                if (in_array($parentEventType, ['decision', 'condition'], true)) {
                    $defaultAnchor = 'yes';
                } else {
                    $defaultAnchor = 'bottom';
                }
                $canvasSettings['connections'][] = [
                    'sourceId' => (string) $newParent->getId(),
                    'targetId' => $eventIdStr,
                    'anchors'  => [
                        'source' => $event->getDecisionPath() ?? $defaultAnchor,
                        'target' => 'top',
                    ],
                ];
            }

            // Rebuild events from canvas to keep DB parent/children in sync
            $sessionEvents = $this->buildCurrentEventsArray($campaign);
            $this->campaignModel->setEvents($campaign, $sessionEvents, $canvasSettings, []);
            $this->campaignModel->saveEntity($campaign);
            $this->campaignModel->setCanvasSettings($campaign, $canvasSettings);

            $view = $this->view(['event' => $event], Response::HTTP_OK);
            $this->setSerializationContext($view);

            return $this->handleView($view);
        }

        $this->eventModel->getRepository()->saveEntity($event);

        $view = $this->view(['event' => $event], Response::HTTP_OK);
        $this->setSerializationContext($view);

        return $this->handleView($view);
    }

    public function deleteEventAction(Request $request, $eventId): Response
    {
        $event = $this->eventModel->getEntity($eventId);
        if (null === $event || $event->isDeleted()) {
            return $this->notFound();
        }

        $campaign = $event->getCampaign();
        if (!$this->checkEntityAccess($campaign, 'edit')) {
            return $this->accessDenied();
        }

        $parameters = $this->getRequestParameters($request);

        $sessionEvents  = $this->buildCurrentEventsArray($campaign);
        $canvasSettings = $this->getCurrentCanvasSettings($campaign);

        unset($sessionEvents[$eventId]);

        $canvasSettings['nodes'] = array_values(
            array_filter(
                $canvasSettings['nodes'] ?? [],
                fn ($node) => (string) ($node['id'] ?? '') !== (string) $eventId
            )
        );

        $canvasSettings['connections'] = array_values(
            array_filter(
                $canvasSettings['connections'] ?? [],
                fn ($conn) => (string) ($conn['sourceId'] ?? '') !== (string) $eventId
                    && (string) ($conn['targetId'] ?? '') !== (string) $eventId
            )
        );

        $redirectEventId = $parameters['redirectEventId'] ?? null;
        $deletedEvents   = [['id' => $eventId, 'redirectEvent' => $redirectEventId]];

        $this->campaignModel->setEvents($campaign, $sessionEvents, $canvasSettings, $deletedEvents);
        $this->campaignModel->saveEntity($campaign);
        $this->campaignModel->setCanvasSettings($campaign, $canvasSettings);

        if (!empty($redirectEventId)) {
            $this->eventModel->deleteEvents($campaign->getEvents()->toArray(), $deletedEvents);
        }

        $view = $this->view(['success' => true, 'deleted_event_id' => (int) $eventId], Response::HTTP_OK);

        return $this->handleView($view);
    }

    public function addConnectionAction(Request $request, $id): Response
    {
        $campaign = $this->campaignModel->getEntity($id);
        if (null === $campaign) {
            return $this->notFound();
        }

        if (!$this->checkEntityAccess($campaign, 'edit')) {
            return $this->accessDenied();
        }

        $parameters = $this->getRequestParameters($request);

        $sourceId = $parameters['sourceId'] ?? null;
        $targetId = $parameters['targetId'] ?? null;

        if (!$sourceId || !$targetId) {
            $view = $this->view(['error' => 'sourceId and targetId are required'], Response::HTTP_BAD_REQUEST);

            return $this->handleView($view);
        }

        $sessionEvents  = $this->buildCurrentEventsArray($campaign);
        $canvasSettings = $this->getCurrentCanvasSettings($campaign);

        if (!isset($canvasSettings['connections'])) {
            $canvasSettings['connections'] = [];
        }

        // Block duplicate parent: each event can only have one parent
        $targetIdStr = (string) $targetId;
        foreach ($canvasSettings['connections'] as $conn) {
            if ((string) ($conn['targetId'] ?? '') === $targetIdStr) {
                $existingSource = $conn['sourceId'];
                $view           = $this->view(
                    ['error' => "Event {$targetIdStr} already has a parent ({$existingSource}). Remove the existing connection first via DELETE /campaigns/{$id}/connections."],
                    Response::HTTP_CONFLICT
                );

                return $this->handleView($view);
            }
        }

        // Determine the correct default anchor based on source event type
        //   - actions: use 'bottom' (output anchor for actions)
        //   - decisions/conditions: use 'yes' (output anchor for decisions)
        $sourceEvent         = $this->eventModel->getEntity((int) $sourceId);
        $defaultSourceAnchor = 'bottom';
        if ($sourceEvent && in_array($sourceEvent->getEventType(), ['decision', 'condition'], true)) {
            $defaultSourceAnchor = 'yes';
        }

        $canvasSettings['connections'][] = [
            'sourceId' => (string) $sourceId,
            'targetId' => $targetIdStr,
            'anchors'  => [
                'source' => $parameters['anchorSource'] ?? $defaultSourceAnchor,
                'target' => $parameters['anchorTarget'] ?? 'top',
            ],
        ];

        $this->campaignModel->setEvents($campaign, $sessionEvents, $canvasSettings, []);
        $this->campaignModel->saveEntity($campaign);
        $this->campaignModel->setCanvasSettings($campaign, $canvasSettings);

        $view = $this->view(['success' => true, 'connection' => [
            'sourceId' => (string) $sourceId,
            'targetId' => (string) $targetId,
        ]], Response::HTTP_CREATED);

        return $this->handleView($view);
    }

    public function removeConnectionAction(Request $request, $id): Response
    {
        $campaign = $this->campaignModel->getEntity($id);
        if (null === $campaign) {
            return $this->notFound();
        }

        if (!$this->checkEntityAccess($campaign, 'edit')) {
            return $this->accessDenied();
        }

        $parameters = $this->getRequestParameters($request);

        $sourceId = (string) ($parameters['sourceId'] ?? '');
        $targetId = (string) ($parameters['targetId'] ?? '');

        if (!$sourceId || !$targetId) {
            $view = $this->view(['error' => 'sourceId and targetId are required'], Response::HTTP_BAD_REQUEST);

            return $this->handleView($view);
        }

        $sessionEvents  = $this->buildCurrentEventsArray($campaign);
        $canvasSettings = $this->getCurrentCanvasSettings($campaign);

        $canvasSettings['connections'] = array_values(
            array_filter(
                $canvasSettings['connections'] ?? [],
                fn ($conn) => (string) ($conn['sourceId'] ?? '') !== $sourceId
                    || (string) ($conn['targetId'] ?? '') !== $targetId
            )
        );

        $this->campaignModel->setEvents($campaign, $sessionEvents, $canvasSettings, []);
        $this->campaignModel->saveEntity($campaign);
        $this->campaignModel->setCanvasSettings($campaign, $canvasSettings);

        $view = $this->view(['success' => true], Response::HTTP_OK);

        return $this->handleView($view);
    }

    public function getCanvasAction($id): Response
    {
        $campaign = $this->campaignModel->getEntity($id);
        if (null === $campaign) {
            return $this->notFound();
        }

        if (!$this->checkEntityAccess($campaign, 'view')) {
            return $this->accessDenied();
        }

        $canvasSettings = $campaign->getCanvasSettings() ?? ['nodes' => [], 'connections' => []];

        $view = $this->view($canvasSettings, Response::HTTP_OK);

        return $this->handleView($view);
    }

    public function updateCanvasAction(Request $request, $id): Response
    {
        $campaign = $this->campaignModel->getEntity($id);
        if (null === $campaign) {
            return $this->notFound();
        }

        if (!$this->checkEntityAccess($campaign, 'edit')) {
            return $this->accessDenied();
        }

        $parameters = $this->getRequestParameters($request);

        $sessionEvents = $this->buildCurrentEventsArray($campaign);

        $canvasSettings = [
            'nodes'       => $parameters['nodes'] ?? [],
            'connections' => $parameters['connections'] ?? [],
        ];

        $this->campaignModel->setEvents($campaign, $sessionEvents, $canvasSettings, []);
        $this->campaignModel->saveEntity($campaign);
        $this->campaignModel->setCanvasSettings($campaign, $canvasSettings);

        $view = $this->view(['success' => true, 'canvasSettings' => $canvasSettings], Response::HTTP_OK);

        return $this->handleView($view);
    }

    private function getRequestParameters(Request $request): array
    {
        $content = $request->getContent();
        if (!empty($content) && str_starts_with((string) $content, '{')) {
            return json_decode($content, true) ?? [];
        }

        return $request->request->all();
    }

    private function buildCurrentEventsArray(Campaign $campaign): array
    {
        $events = [];

        foreach ($campaign->getEvents() as $event) {
            if ($event->isDeleted()) {
                continue;
            }

            $parentId = null;
            if ($event->getParent()) {
                $parentId = $event->getParent()->getId();
            }

            $children = [];
            foreach ($event->getChildren() as $child) {
                if (!$child->isDeleted()) {
                    $children[] = $child->getId();
                }
            }

            $events[$event->getId()] = [
                'id'                          => $event->getId(),
                'name'                        => $event->getName(),
                'description'                 => $event->getDescription(),
                'type'                        => $event->getType(),
                'eventType'                   => $event->getEventType(),
                'order'                       => $event->getOrder(),
                'properties'                  => $event->getProperties(),
                'triggerMode'                 => $event->getTriggerMode(),
                'triggerInterval'             => $event->getTriggerInterval(),
                'triggerIntervalUnit'         => $event->getTriggerIntervalUnit(),
                'triggerDate'                 => $event->getTriggerDate()?->format('Y-m-d\TH:i:sP'),
                'triggerHour'                 => $event->getTriggerHour()?->format('H:i'),
                'triggerRestrictedStartHour'  => $event->getTriggerRestrictedStartHour()?->format('H:i'),
                'triggerRestrictedStopHour'   => $event->getTriggerRestrictedStopHour()?->format('H:i'),
                'triggerRestrictedDaysOfWeek' => $event->getTriggerRestrictedDaysOfWeek(),
                'triggerWindow'               => $event->getTriggerWindow(),
                'decisionPath'                => $event->getDecisionPath(),
                'channel'                     => $event->getChannel(),
                'channelId'                   => $event->getChannelId(),
                'tempId'                      => null,
                'children'                    => $children,
                'parent'                      => $parentId,
            ];
        }

        return $events;
    }

    private function getCurrentCanvasSettings(Campaign $campaign): array
    {
        $settings = $campaign->getCanvasSettings() ?? [];

        if (!isset($settings['nodes'])) {
            $settings['nodes'] = [];
        }

        if (!isset($settings['connections'])) {
            $settings['connections'] = [];
        }

        return $settings;
    }

    private function serializeEventConfig(array $events): array
    {
        $result = [];

        foreach ($events as $key => $accessor) {
            $result[$key] = [
                'key'                     => $key,
                'label'                   => $accessor->getLabel(),
                'description'             => $accessor->getDescription(),
                'formType'                => $accessor->getFormType(),
                'formTypeOptions'         => $accessor->getFormTypeOptions(),
                'formTheme'               => $accessor->getFormTheme(),
                'channel'                 => $accessor->getChannel(),
                'channelIdField'          => $accessor->getChannelIdField(),
                'connectionRestrictions'  => $accessor->getConnectionRestrictions(),
                'extraProperties'         => $accessor->getExtraProperties(),
            ];
        }

        return $result;
    }
}
