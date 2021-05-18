<?php

namespace Spatie\LaravelSettings\SettingsRepositories;

use Illuminate\Redis\RedisManager;

class RedisSettingsRepository implements SettingsRepository
{
    /** @var \Redis */
    protected $connection;

    protected string $prefix;

    public function __construct(array $config, RedisManager $connection)
    {
        $this->connection = $connection
            ->connection($config['connection'] ?? null)
            ->client();

        $this->prefix = array_key_exists('prefix', $config)
            ? "{$config['prefix']}."
            : '';
    }

    public function getPropertiesInGroup(string $group, ?int $teamId = 0, ?int $userId = null): array
    {
        $defaults = collect($this->connection->hGetAll($this->getKey($group,0,$userId)));
        $overrulers = collect($this->connection->hGetAll($this->getKey($group,$teamId,$userId)));
        $merged = $defaults->merge($overrulers);
        return $merged->mapWithKeys(function ($payload, string $name) {
                return [$name => json_decode($payload, true)];
            })->toArray();
    }

    public function checkIfPropertyExists(string $group, string $name, ?int $teamId = 0, ?int $userId = null): bool
    {
        return $this->connection->hExists($this->getKey($group,$teamId,$userId), $name);
    }

    public function getPropertyPayload(string $group, string $name, ?int $teamId = 0, ?int $userId = null)
    {
        return json_decode($this->connection->hGet($this->getKey($group,$teamId,$userId), $name));
    }

    public function createProperty(string $group, string $name, $payload, ?int $teamId = 0, ?int $userId = null): void
    {
        $this->connection->hSet($this->getKey($group,$teamId,$userId), $name, json_encode($payload));
    }

    public function updatePropertyPayload(string $group, string $name, $value, ?int $teamId = 0, ?int $userId = null): void
    {
        $this->connection->hSet($this->getKey($group,$teamId,$userId), $name, json_encode($value));
    }

    public function deleteProperty(string $group, string $name, ?int $teamId = 0, ?int $userId = null): void
    {
        $this->connection->hDel($this->getKey($group,$teamId,$userId), $name);
    }

    public function lockProperties(string $group, array $properties, ?int $teamId = 0, ?int $userId = null): void
    {
        $this->connection->sAdd($this->getLocksSetKey($group,$teamId,$userId), ...$properties);
    }

    public function unlockProperties(string $group, array $properties, ?int $teamId = 0, ?int $userId = null): void
    {
        $this->connection->sRem($this->getLocksSetKey($group,$teamId,$userId), ...$properties);
    }

    public function getLockedProperties(string $group, ?int $teamId = 0, ?int $userId = null): array
    {
        return $this->connection->sMembers($this->getLocksSetKey($group, $userId, $teamId));
    }

    protected function getLocksSetKey(string $group, ?int $teamId = 0, ?int $userId = null): string
    {
        if($userId !== null ){
            return $this->prefix .".locks.$teamId.$userId.$group";
        }
        return $this->prefix .".locks.$teamId.$group";
    }

    public function getKey(string $group, ?int $teamId = 0, ?int $userId = null): string
    {
        if($userId !== null ){
            return $this->prefix ."$teamId.$userId.$group";
        }
        return $this->prefix . "$teamId.$group";
    }
}
