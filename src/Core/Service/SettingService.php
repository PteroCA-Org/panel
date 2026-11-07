<?php

namespace App\Core\Service;

use App\Core\Entity\Setting;
use App\Core\Enum\SettingContextEnum;
use App\Core\Enum\SettingEnum;
use App\Core\Repository\SettingRepository;
use Psr\Cache\InvalidArgumentException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class SettingService
{
    public const CACHE_TTL = 86400;

    public function __construct(
        private readonly SettingRepository $settingRepository,
        private readonly CacheInterface $cache,
    ) {}

    /**
     * @throws InvalidArgumentException
     */
    public function getSetting(string $name): ?string
    {
        return $this->cache->get(
            $this->createSettingCacheKey($name),
            function (ItemInterface $item) use ($name) {
                $item->expiresAfter(self::CACHE_TTL);
                $setting = $this->settingRepository->findOneBy(['name' => $name]);
                return $setting?->getValue();
            }
        );
    }

    public function isPublicRegistrationEnabled(): bool
    {
        return $this->settingRepository->findOneBy([
            'name' => SettingEnum::PUBLIC_REGISTRATION_ENABLED->value,
            'context' => SettingContextEnum::SECURITY->value,
        ])?->getValue() === '1';
    }

    /**
     * @throws InvalidArgumentException
     */
    public function saveSetting(string $name, ?string $value): void
    {
        $setting = $this->settingRepository->findOneBy(['name' => $name]);

        if (empty($setting)) {
            $setting = new Setting();
            $setting->setName($name);
        }

        $setting->setValue($value);
        $this->settingRepository->save($setting);
        $this->saveSettingInCache($name, $value);
    }

    /**
     * @throws InvalidArgumentException
     */
    public function saveSettingInCache(string $name, ?string $value): void
    {
        $settingKey = $this->createSettingCacheKey($name);
        $this->deleteSettingFromCache($name);
        $this->cache->get(
            $settingKey,
            function (ItemInterface $item) use ($value) {
                $item->expiresAfter(self::CACHE_TTL);
                return $value;
            }
        );
    }

    /**
     * @throws InvalidArgumentException
     */
    public function deleteSettingFromCache(string $name): void
    {
        $this->cache->delete($this->createSettingCacheKey($name));
    }

    private function createSettingCacheKey(string $name): string
    {
        return sprintf('app_setting_%s', $name);
    }
}
