<?php

namespace App\Core\EventSubscriber\Crud;

use App\Core\Event\Crud\CrudActionsConfiguredEvent;
use App\Core\Service\System\DemoModeService;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

readonly class DemoCrudActionsSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private DemoModeService $demoModeService,
    ) {
    }

    public function onCrudActionsConfigured(CrudActionsConfiguredEvent $event): void
    {
        if (!$this->demoModeService->isDemoModeEnabled()) {
            return;
        }

        // In demo mode, we keep all actions visible (NEW, EDIT, DELETE, DETAIL)
        // Users can see the full interface and click buttons
        // The actual save/delete operations are blocked by:
        // - DemoCrudSubscriber (blocks CRUD events)
        // - DemoModeSubscriber (blocks HTTP POST/PUT/DELETE/PATCH)

        // No actions to disable - let users explore the full interface
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CrudActionsConfiguredEvent::class => 'onCrudActionsConfigured',
        ];
    }
}
