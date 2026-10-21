<?php

namespace App\Core\Service\Crud;

use App\Core\Contract\UserInterface;
use App\Core\Entity\Setting;
use App\Core\Enum\LogActionEnum;
use App\Core\Enum\SettingTypeEnum;
use App\Core\Service\Logs\LogService;
use BackedEnum;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\InvalidArgumentException;
use Throwable;
use UnitEnum;

readonly class PanelCrudService
{
    private const MASKED_VALUE = '********';

    private const SENSITIVE_FIELD_PATTERN = '/password|token|secret|api_?key|hash/i';

    private const MAX_STRING_LENGTH = 500;

    public function __construct(
        private CrudTemplateService    $crudTemplateService,
        private LogService             $logService,
        private EntityManagerInterface $entityManager,
    )
    {
    }

    /**
     * Snapshot of mapped scalar fields (associations reduced to identifiers) with sensitive values masked.
     * With $fromOriginalData the values loaded from the database are used instead of the current ones.
     */
    public function createEntitySnapshot(object $entityInstance, bool $fromOriginalData = false): array
    {
        $metadata = $this->entityManager->getClassMetadata($entityInstance::class);
        $originalData = $fromOriginalData
            ? $this->entityManager->getUnitOfWork()->getOriginalEntityData($entityInstance)
            : null;

        $snapshot = [];
        foreach ($metadata->getFieldNames() as $fieldName) {
            $value = $originalData !== null
                ? ($originalData[$fieldName] ?? null)
                : $metadata->getFieldValue($entityInstance, $fieldName);
            $snapshot[$fieldName] = $this->normalizeValue($value);
        }

        foreach ($metadata->getAssociationNames() as $associationName) {
            if (!$metadata->isSingleValuedAssociation($associationName)) {
                continue;
            }

            $value = $originalData !== null
                ? ($originalData[$associationName] ?? null)
                : $metadata->getFieldValue($entityInstance, $associationName);
            $snapshot[$associationName] = $this->normalizeAssociation($value);
        }

        return $this->maskSensitiveFields($entityInstance, $snapshot);
    }

    public function logEntityAction(
        LogActionEnum $action,
        object $entityInstance,
        UserInterface $user,
        string $entityName,
        ?array $previousSnapshot = null,
    ): void
    {
        $details = ['entityName' => $entityName];

        if ($action === LogActionEnum::ENTITY_DELETE) {
            $snapshot = $previousSnapshot ?? $this->createEntitySnapshot($entityInstance);
            $details['entityId'] = $snapshot['id'] ?? null;
            $details['entity'] = $snapshot;
        } else {
            $snapshot = $this->createEntitySnapshot($entityInstance);
            $details['entityId'] = $snapshot['id'] ?? null;

            if ($action === LogActionEnum::ENTITY_EDIT && $previousSnapshot !== null) {
                $details['changes'] = $this->getChanges($previousSnapshot, $snapshot);
            } else {
                $details['entity'] = $snapshot;
            }
        }

        $this->logService->logAction($user, $action, $details);
    }

    /**
     * @throws InvalidArgumentException
     */
    public function getTemplatesToOverride(array $templateContext): array
    {
        return $this->crudTemplateService->getTemplatesToOverride($templateContext);
    }

    private function getChanges(array $previousSnapshot, array $currentSnapshot): array
    {
        $changes = [];
        foreach ($currentSnapshot as $field => $newValue) {
            $oldValue = $previousSnapshot[$field] ?? null;
            if ($oldValue !== $newValue) {
                $changes[$field] = ['old' => $oldValue, 'new' => $newValue];
            }
        }

        return $changes;
    }

    private function maskSensitiveFields(object $entityInstance, array $snapshot): array
    {
        foreach ($snapshot as $field => $value) {
            if ($value !== null && preg_match(self::SENSITIVE_FIELD_PATTERN, $field)) {
                $snapshot[$field] = self::MASKED_VALUE;
            }
        }

        if ($entityInstance instanceof Setting
            && $entityInstance->getType() === SettingTypeEnum::SECRET->value
            && array_key_exists('value', $snapshot)) {
            $snapshot['value'] = self::MASKED_VALUE;
        }

        return $snapshot;
    }

    private function normalizeValue(mixed $value): mixed
    {
        return match (true) {
            $value === null, is_bool($value), is_int($value), is_float($value) => $value,
            is_string($value) => mb_strlen($value) > self::MAX_STRING_LENGTH
                ? mb_substr($value, 0, self::MAX_STRING_LENGTH) . '...'
                : $value,
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            is_array($value) => array_map(fn ($item) => $this->normalizeValue($item), $value),
            default => $this->normalizeAssociation($value),
        };
    }

    private function normalizeAssociation(mixed $value): mixed
    {
        if (!is_object($value)) {
            return $value;
        }

        try {
            // Identifier is read without initializing lazy proxies
            $identifier = $this->entityManager->getUnitOfWork()->getSingleIdentifierValue($value);
        } catch (Throwable) {
            $identifier = null;
        }

        return $identifier ?? $value::class;
    }
}
