<?php

namespace App\Core\Service\Crud;

use App\Core\Contract\UserInterface;
use App\Core\Entity\Setting;
use App\Core\Enum\LogActionEnum;
use App\Core\Enum\SettingTypeEnum;
use App\Core\Service\Logs\LogService;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\Serializer\SerializerInterface;

readonly class PanelCrudService
{
    public function __construct(
        private CrudTemplateService $crudTemplateService,
        private LogService          $logService,
        private SerializerInterface $serializer,
    )
    {
    }

    public function logEntityAction(LogActionEnum $action, $entityInstance, UserInterface $user, string $entityName): void
    {
        // Create a clone for logging to avoid mutating the original entity
        $entityForLogging = $entityInstance;

        if (is_a($entityInstance, Setting::class)
            && $entityInstance->getType() === SettingTypeEnum::SECRET->value) {
            // Clone the entity to avoid mutating the original that will be persisted
            $entityForLogging = clone $entityInstance;
            $entityForLogging->setValue('********');
        }

        $this->logService->logAction(
            $user,
            $action,
            [
                'entityName' => $entityName,
                'entity' => $this->serializer->normalize($entityForLogging, null, ['groups' => 'log'])
            ],
        );
    }

    /**
     * @throws InvalidArgumentException
     */
    public function getTemplatesToOverride(array $templateContext): array
    {
        return $this->crudTemplateService->getTemplatesToOverride($templateContext);
    }
}
