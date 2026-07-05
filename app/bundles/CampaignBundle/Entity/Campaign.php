<?php

namespace Mautic\CampaignBundle\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\RequestBody as OpenApiRequestBody;
use ApiPlatform\OpenApi\Model\Response as OpenApiResponse;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\Common\Collections\Order;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Mautic\ApiBundle\Serializer\Driver\ApiMetadataDriver;
use Mautic\CampaignBundle\Validator\Constraints\NoOrphanEvents;
use Mautic\CategoryBundle\Entity\Category;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;
use Mautic\CoreBundle\Entity\FormEntity;
use Mautic\CoreBundle\Entity\OptimisticLockInterface;
use Mautic\CoreBundle\Entity\OptimisticLockTrait;
use Mautic\CoreBundle\Entity\UuidInterface;
use Mautic\CoreBundle\Entity\UuidTrait;
use Mautic\FormBundle\Entity\Form;
use Mautic\LeadBundle\Entity\Lead as Contact;
use Mautic\LeadBundle\Entity\LeadList;
use Mautic\ProjectBundle\Entity\Project;
use Mautic\ProjectBundle\Entity\ProjectTrait;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Mapping\ClassMetadata;

#[ApiResource(
    operations: [
        new GetCollection(security: "is_granted('campaign:campaigns:viewown')"),
        new Post(security: "is_granted('campaign:campaigns:create')"),
        new Get(security: "is_granted('campaign:campaigns:viewown', object)"),
        new Put(security: "is_granted('campaign:campaigns:editown', object)"),
        new Patch(security: "is_granted('campaign:campaigns:editother', object)"),
        new Delete(security: "is_granted('campaign:campaigns:deleteown', object)"),

        // Agent-friendly campaign canvas operations (MCP support)
        new Get(
            uriTemplate: '/campaigns/events/types',
            controller: 'Mautic\CampaignBundle\Controller\Api\CampaignCanvasApiController::getEventTypesAction',
            read: false,
            deserialize: false,
            security: "is_granted('campaign:campaigns:viewown')",
            name: 'campaign_event_types',
            openapi: new OpenApiOperation(
                summary: 'List available campaign event types',
                description: 'Returns all actions, conditions, and decisions available for campaign automation. Agents use this to discover valid event types, their properties, and connection restrictions.',
                responses: [
                    '200' => new OpenApiResponse(description: 'Event types grouped by category (actions, conditions, decisions)'),
                ]
            ),
        ),
        new Post(
            uriTemplate: '/campaigns/{id}/events',
            controller: 'Mautic\CampaignBundle\Controller\Api\CampaignCanvasApiController::newEventAction',
            read: false,
            deserialize: false,
            security: "is_granted('campaign:campaigns:editown')",
            name: 'campaign_new_event',
            openapi: new OpenApiOperation(
                summary: 'Create a campaign event',
                description: 'Adds a new event to a campaign. The event is automatically added to the canvas with a generated temporary ID that gets replaced on save. Returns the created event with its real ID.',
                requestBody: new OpenApiRequestBody(
                    description: 'Event data (name, type, eventType, properties, triggerMode, etc.)'
                ),
                responses: [
                    '201' => new OpenApiResponse(description: 'Event created with real ID'),
                    '404' => new OpenApiResponse(description: 'Campaign not found'),
                ]
            ),
        ),
        new Put(
            uriTemplate: '/campaigns/events/{eventId}',
            controller: 'Mautic\CampaignBundle\Controller\Api\CampaignCanvasApiController::editEventAction',
            read: false,
            deserialize: false,
            security: "is_granted('campaign:campaigns:editown')",
            name: 'campaign_edit_event',
            openapi: new OpenApiOperation(
                summary: 'Update a campaign event',
                description: 'Updates event properties (name, description, trigger settings, etc.). Does not change canvas position or connections.',
                requestBody: new OpenApiRequestBody(description: 'Event properties to update'),
                responses: [
                    '200' => new OpenApiResponse(description: 'Event updated'),
                    '404' => new OpenApiResponse(description: 'Event not found'),
                ]
            ),
        ),
        new Delete(
            uriTemplate: '/campaigns/events/{eventId}',
            controller: 'Mautic\CampaignBundle\Controller\Api\CampaignCanvasApiController::deleteEventAction',
            read: false,
            deserialize: false,
            security: "is_granted('campaign:campaigns:editown')",
            name: 'campaign_delete_event',
            openapi: new OpenApiOperation(
                summary: 'Delete a campaign event',
                description: 'Removes an event from the campaign, including its canvas node and all connections. Optionally redirects contacts to another event.',
                requestBody: new OpenApiRequestBody(description: 'Optional: {"redirectEventId": 123}'),
                responses: [
                    '200' => new OpenApiResponse(description: 'Event deleted'),
                    '404' => new OpenApiResponse(description: 'Event not found'),
                ]
            ),
        ),
        new Post(
            uriTemplate: '/campaigns/{id}/connections',
            controller: 'Mautic\CampaignBundle\Controller\Api\CampaignCanvasApiController::addConnectionAction',
            read: false,
            deserialize: false,
            security: "is_granted('campaign:campaigns:editown')",
            name: 'campaign_add_connection',
            openapi: new OpenApiOperation(
                summary: 'Add a canvas connection between events',
                description: 'Creates a directional connection between two events in the campaign canvas. Sets the parent/child relationship for execution flow.',
                requestBody: new OpenApiRequestBody(description: '{"sourceId": 1, "targetId": 2, "anchorSource": "yes", "anchorTarget": "top"}'),
                responses: [
                    '201' => new OpenApiResponse(description: 'Connection created'),
                    '404' => new OpenApiResponse(description: 'Campaign not found'),
                ]
            ),
        ),
        new Delete(
            uriTemplate: '/campaigns/{id}/connections',
            controller: 'Mautic\CampaignBundle\Controller\Api\CampaignCanvasApiController::removeConnectionAction',
            read: false,
            deserialize: false,
            security: "is_granted('campaign:campaigns:editown')",
            name: 'campaign_remove_connection',
            openapi: new OpenApiOperation(
                summary: 'Remove a canvas connection',
                description: 'Removes a directional connection between two events, eliminating their parent/child execution relationship.',
                requestBody: new OpenApiRequestBody(description: '{"sourceId": 1, "targetId": 2}'),
                responses: [
                    '200' => new OpenApiResponse(description: 'Connection removed'),
                    '404' => new OpenApiResponse(description: 'Campaign not found'),
                ]
            ),
        ),
        new Get(
            uriTemplate: '/campaigns/{id}/canvas',
            controller: 'Mautic\CampaignBundle\Controller\Api\CampaignCanvasApiController::getCanvasAction',
            read: false,
            deserialize: false,
            security: "is_granted('campaign:campaigns:viewown')",
            name: 'campaign_get_canvas',
            openapi: new OpenApiOperation(
                summary: 'Get campaign canvas layout',
                description: 'Returns the full canvas layout including all nodes (with positions) and connections for the campaign.',
                responses: [
                    '200' => new OpenApiResponse(description: 'Canvas layout with nodes and connections'),
                    '404' => new OpenApiResponse(description: 'Campaign not found'),
                ]
            ),
        ),
        new Put(
            uriTemplate: '/campaigns/{id}/canvas',
            controller: 'Mautic\CampaignBundle\Controller\Api\CampaignCanvasApiController::updateCanvasAction',
            read: false,
            deserialize: false,
            security: "is_granted('campaign:campaigns:editown')",
            name: 'campaign_update_canvas',
            openapi: new OpenApiOperation(
                summary: 'Replace the entire canvas layout',
                description: 'Replaces all nodes and connections in the campaign canvas. Use connect_events/disconnect_events for granular changes.',
                requestBody: new OpenApiRequestBody(description: '{"nodes": [...], "connections": [...]}'),
                responses: [
                    '200' => new OpenApiResponse(description: 'Canvas updated'),
                    '404' => new OpenApiResponse(description: 'Campaign not found'),
                ]
            ),
        ),
    ],
    normalizationContext: [
        'groups'                  => ['campaign:read'],
        'swagger_definition_name' => 'Read',
        'api_included'            => ['category', 'events', 'lists', 'forms', 'fields', 'actions'],
    ],
    denormalizationContext: [
        'groups'                  => ['campaign:write'],
        'swagger_definition_name' => 'Write',
    ]
)]
class Campaign extends FormEntity implements OptimisticLockInterface, UuidInterface
{
    use UuidTrait;

    use OptimisticLockTrait;

    use ProjectTrait;

    public const TABLE_NAME  = 'campaigns';
    public const ENTITY_NAME = 'campaign';

    /**
     * @var int
     */
    #[Groups(['campaign:read', 'campaign:write'])]
    private $id;

    /**
     * @var string|null
     */
    #[Groups(['campaign:read', 'campaign:write'])]
    private $name;

    /**
     * @var string|null
     */
    #[Groups(['campaign:read', 'campaign:write'])]
    private $description;

    /**
     * @var \DateTimeInterface|null
     */
    #[Groups(['campaign:read', 'campaign:write'])]
    private $publishUp;

    /**
     * @var \DateTimeInterface|null
     */
    #[Groups(['campaign:read', 'campaign:write'])]
    private $publishDown;

    #[Groups(['campaign:read', 'campaign:write'])]
    public ?\DateTimeInterface $deleted = null;

    // see Mautic\CampaignBundle\Enum\RepublishBehavior for available values.
    #[Groups(['campaign:read', 'campaign:write'])]
    private ?string $republishBehavior = null;

    /**
     * @var Category|null
     **/
    #[Groups(['campaign:read', 'campaign:write'])]
    private $category;

    /**
     * @var Collection<int, Event>|ArrayCollection<int, Event>
     */
    #[Groups(['campaign:read', 'campaign:write'])]
    private $events;

    /**
     * @var ArrayCollection<int, Lead>
     */
    #[Groups(['campaign:read', 'campaign:write'])]
    private Collection $leads;

    /**
     * @var Collection<int, LeadList>
     */
    #[Groups(['campaign:read', 'campaign:write'])]
    private Collection $lists;

    /**
     * @var Collection<int, Form>
     */
    #[Groups(['campaign:read', 'campaign:write'])]
    private Collection $forms;

    #[Groups(['campaign:read', 'campaign:write'])]
    private array $canvasSettings = [];

    #[Groups(['campaign:read', 'campaign:write'])]
    private bool $allowRestart = false;

    public function __construct()
    {
        $this->events = new ArrayCollection();
        $this->leads  = new ArrayCollection();
        $this->lists  = new ArrayCollection();
        $this->forms  = new ArrayCollection();
        $this->initializeProjects();
    }

    public function __clone()
    {
        $this->leads  = new ArrayCollection();
        $this->events = new ArrayCollection();
        $this->lists  = new ArrayCollection();
        $this->forms  = new ArrayCollection();
        $this->id     = null;

        parent::__clone();
    }

    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);

        $builder->setTable(self::TABLE_NAME)
            ->setCustomRepositoryClass(CampaignRepository::class);

        $builder->addIdColumns();

        $builder->addPublishDates();

        $builder->createField('republishBehavior', Types::STRING)
            ->columnName('republish_behavior')
            ->nullable()
            ->length(32)
            ->build();

        $builder->addCategory();

        $builder->createOneToMany('events', Event::class)
            ->setIndexBy('id')
            ->setOrderBy(['order' => 'ASC'])
            ->mappedBy('campaign')
            ->cascadeAll()
            ->fetchExtraLazy()
            ->build();

        $builder->createOneToMany('leads', Lead::class)
            ->mappedBy('campaign')
            ->fetchExtraLazy()
            ->build();

        $builder->createManyToMany('lists', LeadList::class)
            ->setJoinTable('campaign_leadlist_xref')
            ->setIndexBy('id')
            ->addInverseJoinColumn('leadlist_id', 'id', false, false, 'CASCADE')
            ->addJoinColumn('campaign_id', 'id', true, false, 'CASCADE')
            ->build();

        $builder->createManyToMany('forms', Form::class)
            ->setJoinTable('campaign_form_xref')
            ->setIndexBy('id')
            ->addInverseJoinColumn('form_id', 'id', false, false, 'CASCADE')
            ->addJoinColumn('campaign_id', 'id', true, false, 'CASCADE')
            ->build();

        $builder->createField('canvasSettings', 'array')
            ->columnName('canvas_settings')
            ->nullable()
            ->build();

        $builder->addNamedField('allowRestart', 'boolean', 'allow_restart');
        $builder->addNullableField('deleted', 'datetime');

        self::addVersionField($builder);
        static::addUuidField($builder);
        self::addProjectsField($builder, 'campaign_projects_xref', 'campaign_id');
    }

    public static function loadValidatorMetadata(ClassMetadata $metadata): void
    {
        $metadata->addPropertyConstraint(
            'name',
            new Assert\NotBlank(
                [
                    'message' => 'mautic.core.name.required',
                ]
            )
        );

        $metadata->addConstraint(new NoOrphanEvents());
    }

    /**
     * Prepares the metadata for API usage.
     */
    public static function loadApiMetadata(ApiMetadataDriver $metadata): void
    {
        $metadata->setGroupPrefix('campaign')
            ->addListProperties(
                [
                    'id',
                    'name',
                    'category',
                    'description',
                ]
            )
            ->addProperties(
                [
                    'allowRestart',
                    'publishUp',
                    'publishDown',
                    'republishBehavior',
                    'events',
                    'forms',
                    'lists', // @deprecated, will be renamed to 'segments' in 3.0.0
                    'canvasSettings',
                ]
            )
            ->setGroupPrefix('campaignBasic')
            ->addListProperties(
                [
                    'id',
                    'name',
                    'description',
                    'allowRestart',
                    'events',
                    'publishUp',
                    'publishDown',
                    'deleted',
                ]
            )
            ->build();

        self::addProjectsInLoadApiMetadata($metadata, 'campaign');
    }

    public function convertToArray(): array
    {
        return get_object_vars($this);
    }

    /**
     * @param string $prop
     * @param mixed  $val
     */
    protected function isChanged($prop, $val)
    {
        $getter  = 'get'.ucfirst($prop);
        $current = $this->$getter();
        if ('category' == $prop) {
            $currentId = ($current) ? $current->getId() : '';
            $newId     = ($val) ? $val->getId() : null;
            if ($currentId != $newId) {
                $this->changes[$prop] = [$currentId, $newId];
            }
        } elseif ('projects' === $prop) {
            // Initialize project tracking on first change
            if (!isset($this->changes['projects']['old'])) {
                $currentProjects           = array_map(fn ($project) => $project->getName(), iterator_to_array($current));
                $this->changes['projects'] = [
                    'old' => $currentProjects,
                    'new' => $currentProjects,
                ];
            }

            // Update the new state based on the operation
            if ($val instanceof Project) {
                // Add project if not already in the list
                $projectName = $val->getName();
                if (!in_array($projectName, $this->changes['projects']['new'], true)) {
                    $this->changes['projects']['new'][] = $projectName;
                }
            } else {
                // Remove project from the list
                $this->changes['projects']['new'] = array_values(
                    array_diff($this->changes['projects']['new'], [$val])
                );
            }
        } else {
            parent::isChanged($prop, $val);
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Override to convert projects changes to final format.
     */
    public function getChanges($includePast = false)
    {
        $changes = parent::getChanges($includePast);

        // Convert projects format if it exists and is in the intermediate format
        if (isset($changes['projects']['old']) && isset($changes['projects']['new'])) {
            $changes['projects'] = [
                implode(', ', $changes['projects']['old']),
                implode(', ', $changes['projects']['new']),
            ];
        }

        return $changes;
    }

    /**
     * @param string $description
     *
     * @return Campaign
     */
    public function setDescription($description)
    {
        $this->isChanged('description', $description);
        $this->description = $description;

        return $this;
    }

    /**
     * @return string
     */
    public function getDescription()
    {
        return $this->description;
    }

    /**
     * @return Campaign
     */
    public function setName(string $name)
    {
        $this->isChanged('name', $name);
        $this->name = $name;

        return $this;
    }

    /**
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Calls $this->addEvent on every item in the collection.
     *
     * @return Campaign
     */
    public function addEvents(array $events)
    {
        foreach ($events as $id => $event) {
            $this->addEvent($id, $event);
        }

        return $this;
    }

    /**
     * @return Campaign
     */
    public function addEvent($key, Event $event)
    {
        if ($changes = $event->getChanges()) {
            $this->changes['events']['added'][$key] = [$key, $changes];
        }
        $this->events[$key] = $event;

        return $this;
    }

    public function removeEvent(Event $event): void
    {
        $this->changes['events']['removed'][$event->getId()] = $event->getName();

        $this->events->removeElement($event);
    }

    /**
     * @return ArrayCollection<int, Event>
     */
    public function getEvents()
    {
        return $this->events;
    }

    public function getRootEvents(): ArrayCollection
    {
        $criteria = Criteria::create()->where(
            Criteria::expr()->andX(
                Criteria::expr()->isNull('parent'),
                Criteria::expr()->isNull('deleted')
            )
        );
        $events   = $this->getEvents()->matching($criteria);

        return $this->reindexEventsByIdKey($events);
    }

    public function getInactionBasedEvents(): ArrayCollection
    {
        $criteria = Criteria::create()->where(Criteria::expr()->eq('decisionPath', Event::PATH_INACTION));
        $events   = $this->getEvents()->matching($criteria);

        return $this->reindexEventsByIdKey($events);
    }

    /**
     * @param string $type
     *
     * @return ArrayCollection<int,Event>
     */
    public function getEventsByType($type): ArrayCollection
    {
        $criteria = Criteria::create()->where(Criteria::expr()->eq('eventType', $type));
        $events   = $this->getEvents()->matching($criteria);

        return $this->reindexEventsByIdKey($events);
    }

    /**
     * @return ArrayCollection<int, Event>
     */
    public function getEmailSendEvents(): ArrayCollection
    {
        $criteria = Criteria::create()->where(Criteria::expr()->eq('type', 'email.send'));
        $events   = $this->getEvents()->matching($criteria);

        // Doctrine loses the indexBy mapping definition when using matching so we have to manually reset them.
        // @see https://github.com/doctrine/doctrine2/issues/4693
        $keyedArrayCollection = new ArrayCollection();
        /** @var Event $event */
        foreach ($events as $event) {
            $keyedArrayCollection->set($event->getId(), $event);
        }

        return $keyedArrayCollection;
    }

    public function isEmailCampaign(): bool
    {
        $criteria     = Criteria::create()->where(Criteria::expr()->eq('type', 'email.send'))->setMaxResults(1);
        $emailEvent   = $this->getEvents()->matching($criteria);

        return !$emailEvent->isEmpty();
    }

    /**
     * @param ?\DateTime $publishUp
     *
     * @return Campaign
     */
    public function setPublishUp($publishUp)
    {
        $this->isChanged('publishUp', $publishUp);
        $this->publishUp = $publishUp;

        return $this;
    }

    /**
     * @return \DateTimeInterface|null
     */
    public function getPublishUp()
    {
        return $this->publishUp;
    }

    /**
     * @param ?\DateTime $publishDown
     *
     * @return Campaign
     */
    public function setPublishDown($publishDown)
    {
        $this->isChanged('publishDown', $publishDown);
        $this->publishDown = $publishDown;

        return $this;
    }

    public function getRepublishBehavior(): ?string
    {
        return $this->republishBehavior;
    }

    public function setRepublishBehavior(?string $republishBehavior): self
    {
        $this->isChanged('republishBehavior', $republishBehavior);
        $this->republishBehavior = $republishBehavior;

        return $this;
    }

    /**
     * @return \DateTimeInterface
     */
    public function getPublishDown()
    {
        return $this->publishDown;
    }

    /**
     * @return mixed
     */
    public function getCategory()
    {
        return $this->category;
    }

    /**
     * @param mixed $category
     */
    public function setCategory($category): void
    {
        $this->isChanged('category', $category);
        $this->category = $category;
    }

    /**
     * @return Campaign
     */
    public function addLead($key, Lead $lead)
    {
        $action     = ($this->leads->contains($lead)) ? 'updated' : 'added';
        $leadEntity = $lead->getLead();

        $this->changes['leads'][$action][$leadEntity->getId()] = $leadEntity->getPrimaryIdentifier();
        $this->leads[$key]                                     = $lead;

        return $this;
    }

    public function removeLead(Lead $lead): void
    {
        $leadEntity                                              = $lead->getLead();
        $this->changes['leads']['removed'][$leadEntity->getId()] = $leadEntity->getPrimaryIdentifier();
        $this->leads->removeElement($lead);
    }

    /**
     * @return Lead[]|Collection
     */
    public function getLeads()
    {
        return $this->leads;
    }

    /**
     * @return ArrayCollection<int, LeadList>
     */
    public function getLists()
    {
        return $this->lists;
    }

    /**
     * @return Campaign
     */
    public function addList(LeadList $list)
    {
        $this->lists[$list->getId() ?? ''] = $list;

        $this->changes['lists']['added'][$list->getId() ?? ''] = $list->getName();

        return $this;
    }

    public function removeList(LeadList $list): void
    {
        $this->changes['lists']['removed'][$list->getId() ?? ''] = $list->getName();
        $this->lists->removeElement($list);
    }

    /**
     * @return ArrayCollection<int, Form>
     */
    public function getForms()
    {
        return $this->forms;
    }

    /**
     * @return Campaign
     */
    public function addForm(Form $form)
    {
        $this->forms[$form->getId() ?? ''] = $form;

        $this->changes['forms']['added'][$form->getId() ?? ''] = $form->getName();

        return $this;
    }

    public function removeForm(Form $form): void
    {
        $this->changes['forms']['removed'][$form->getId() ?? ''] = $form->getName();
        $this->forms->removeElement($form);
    }

    /**
     * @return mixed
     */
    public function getCanvasSettings()
    {
        return $this->canvasSettings;
    }

    public function setCanvasSettings(array $canvasSettings): void
    {
        $this->canvasSettings = $canvasSettings;
    }

    /**
     * Check if there are any orphan events that are not connected to any parent node.
     */
    public function hasOrphanEvents(): bool
    {
        $canvasSettings = $this->getCanvasSettings() ?? [];

        if (empty($canvasSettings['nodes'])) {
            return false;
        }

        // Extract event IDs from canvas nodes (excludes 'lists', 'forms' and other non-event nodes)
        $eventIds = array_filter(
            array_column($canvasSettings['nodes'], 'id'),
            fn ($id) => !in_array($id, ['lists', 'forms'])
        );

        if (empty($eventIds)) {
            return false;
        }

        // Extract connected event IDs from connections
        $connectedEventIds = [];
        if (!empty($canvasSettings['connections'])) {
            $connectedEventIds = array_filter(array_column($canvasSettings['connections'], 'targetId'));
        }

        return !empty(array_diff($eventIds, $connectedEventIds));
    }

    public function getAllowRestart(): bool
    {
        return (bool) $this->allowRestart;
    }

    public function allowRestart(): bool
    {
        return $this->getAllowRestart();
    }

    /**
     * @param bool $allowRestart
     *
     * @return Campaign
     */
    public function setAllowRestart($allowRestart)
    {
        $allowRestart = (bool) $allowRestart;
        $this->isChanged('allowRestart', $allowRestart);

        $this->allowRestart = $allowRestart;

        return $this;
    }

    public function setDeleted(?\DateTimeInterface $deleted): void
    {
        $this->isChanged('deleted', $deleted);
        $this->deleted = $deleted;
    }

    public function isDeleted(): bool
    {
        return !is_null($this->deleted);
    }

    /**
     * Get contact membership.
     */
    public function getContactMembership(Contact $contact): Collection
    {
        return $this->leads->matching(
            Criteria::create()
                ->where(Criteria::expr()->eq('lead', $contact))
                ->orderBy(['dateAdded' => Order::Descending->value])
        );
    }

    /**
     * @deprecated use CoreEvents::VIEW_INJECT_CUSTOM_TEMPLATE to change template params instead
     */
    public function getOnclickMethod(): string
    {
        return 'Mautic.confirmationCampaignPublishStatus(mQuery(this));';
    }

    /**
     * @deprecated use CoreEvents::VIEW_INJECT_CUSTOM_TEMPLATE to change template params instead
     *
     * @return array<string, string>
     */
    public function getDataAttributes(): array
    {
        return [
            'data-toggle'           => 'confirmation',
            'data-confirm-callback' => 'confirmCallbackCampaignPublishStatus',
            'data-cancel-callback'  => 'dismissConfirmation',
        ];
    }

    /**
     * @deprecated use CoreEvents::VIEW_INJECT_CUSTOM_TEMPLATE to change template params instead
     *
     * @return array<string, string>
     */
    public function getTranslationKeysDataAttributes(): array
    {
        return [
            'data-message'      => 'mautic.campaign.form.confirmation.message',
            'data-confirm-text' => 'mautic.campaign.form.confirmation.confirm_text',
            'data-cancel-text'  => 'mautic.campaign.form.confirmation.cancel_text',
        ];
    }

    /**
     * Re-index collection by event ID to work around Doctrine's indexBy mapping issue.
     *
     * @see https://github.com/doctrine/doctrine2/issues/4693
     */
    private function reindexEventsByIdKey(Collection $events): ArrayCollection
    {
        // Doctrine loses the indexBy mapping definition when using matching so we have to manually reset them.
        // @see https://github.com/doctrine/doctrine2/issues/4693
        $keyedArrayCollection = new ArrayCollection();
        /** @var Event $event */
        foreach ($events as $event) {
            $keyedArrayCollection->set($event->getId(), $event);
        }
        unset($events);

        return $keyedArrayCollection;
    }
}
