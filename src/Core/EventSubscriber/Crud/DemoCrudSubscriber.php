<?php

namespace App\Core\EventSubscriber\Crud;

use App\Core\Event\Crud\CrudEntityDeletingEvent;
use App\Core\Event\Crud\CrudEntityPersistingEvent;
use App\Core\Event\Crud\CrudEntityUpdatingEvent;
use App\Core\Service\System\DemoModeService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

readonly class DemoCrudSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private DemoModeService $demoModeService,
        private TranslatorInterface $translator,
        private RequestStack $requestStack,
    ) {
    }

    public function onCrudEntityPersisting(CrudEntityPersistingEvent $event): void
    {
        $this->blockCrudAction($event);
    }

    public function onCrudEntityUpdating(CrudEntityUpdatingEvent $event): void
    {
        $this->blockCrudAction($event);
    }

    public function onCrudEntityDeleting(CrudEntityDeletingEvent $event): void
    {
        $this->blockCrudAction($event);
    }

    private function blockCrudAction(CrudEntityPersistingEvent|CrudEntityUpdatingEvent|CrudEntityDeletingEvent $event): void
    {
        if (!$this->demoModeService->isDemoModeEnabled()) {
            return;
        }

        $event->stopPropagation();

        $session = $this->requestStack->getSession();
        $session->getFlashBag()->add(
            'danger',
            $this->translator->trans($this->demoModeService->getDemoModeMessage())
        );
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CrudEntityPersistingEvent::class => 'onCrudEntityPersisting',
            CrudEntityUpdatingEvent::class => 'onCrudEntityUpdating',
            CrudEntityDeletingEvent::class => 'onCrudEntityDeleting',
        ];
    }
}
