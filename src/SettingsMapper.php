<?php

namespace Spatie\LaravelSettings;

use Illuminate\Support\Collection;
use Spatie\LaravelSettings\Events\LoadingSettings;
use Spatie\LaravelSettings\Exceptions\MissingSettings;
use Spatie\LaravelSettings\Support\Crypto;

class SettingsMapper
{
    /** @var array<string, \Spatie\LaravelSettings\SettingsConfig> */
    private array $configs = [];

    public function initialize(string $settingsClass): SettingsConfig
    {
        if ($this->has($settingsClass)) {
            return $this->configs[$settingsClass];
        }

        $config = new SettingsConfig($settingsClass);

        return $this->configs[$settingsClass] = $config;
    }

    public function has(string $settingsClass): bool
    {
        return array_key_exists($settingsClass, $this->configs);
    }

    public function load(string $settingsClass, int $teamId, ?int $userId): Collection
    {
        $config = $this->getConfig($settingsClass);

        $properties = $this->fetchProperties(
            $settingsClass,
            $teamId,
            $config->getReflectedProperties()->keys(),
            $userId
        );

        event(new LoadingSettings($settingsClass, $properties));

        $this->ensureNoMissingSettings($config, $properties, 'loading');

        return $properties;
    }

    public function save(
        string $settingsClass,
        int $teamId,
        Collection $properties,
        ?int $userId
    ): Collection {
        $config = $this->getConfig($settingsClass);

        $this->ensureNoMissingSettings($config, $properties, 'saving');

        $changedProperties = $properties
            ->reject(fn ($payload, string $name) => $config->isLocked($name))
            ->each(function ($payload, string $name) use ($config, $userId, $teamId) {
                if ($cast = $config->getCast($name)) {
                    $payload = $cast->set($payload);
                }

                if ($config->isEncrypted($name)) {
                    $payload = Crypto::encrypt($payload);
                }

                $config->getRepository()->updatePropertyPayload(
                    $config->getGroup(),
                    $name,
                    $payload,
                    $teamId,
                    $userId
                );
            });

        return $this
            ->fetchProperties($settingsClass, $teamId, $config->getLocked(),  $userId)
            ->merge($changedProperties);
    }

    public function fetchProperties(string $settingsClass,int $teamId, Collection $names, ?int $userId): Collection
    {
        $config = $this->getConfig($settingsClass);

        return collect($config->getRepository()->getPropertiesInGroup($config->getGroup(), $teamId, $userId))
            ->filter(fn ($payload, string $name) => $names->contains($name))
            ->map(function ($payload, string $name) use ($config) {
                if ($config->isEncrypted($name)) {
                    $payload = Crypto::decrypt($payload);
                }

                if ($cast = $config->getCast($name)) {
                    $payload = $cast->get($payload);
                }

                return $payload;
            });
    }

    private function getConfig(string $settingsClass): SettingsConfig
    {
        if (! $this->has($settingsClass)) {
            $this->initialize($settingsClass);
        }

        return $this->configs[$settingsClass];
    }

    private function ensureNoMissingSettings(
        SettingsConfig $config,
        Collection $properties,
        string $operation
    ): void {
        $missingSettings = $config
            ->getReflectedProperties()
            ->keys()
            ->diff($properties->keys())
            ->toArray();

        if (! empty($missingSettings)) {
            throw MissingSettings::create($config->getName(), $missingSettings, $operation);
        }
    }
}
