<?php

namespace Spatie\LaravelSettings\SettingsRepositories;

use DB;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Spatie\LaravelSettings\Models\SettingsProperty;

class DatabaseSettingsRepository implements SettingsRepository
{
    /** @var string|\Illuminate\Database\Eloquent\Model */
    protected string $propertyModel;
    protected Model $model;

    protected ?string $connection;

    public function __construct(array $config)
    {
        $this->propertyModel = $config['model'] ?? SettingsProperty::class;
        $this->model = new $this->propertyModel;

        $this->connection = $config['connection'] ?? $this->model->getConnectionName();
    }

    public function getBuilderForGlobalDefaults(string $group): Builder
    {
        return $this->model::on($this->connection)
            ->where('group', $group)
            ->where('team_id', '=', 0)
            ->whereNull('user_id');
    }

    public function getBuilderForTeam(int $teamId, string $group): Builder
    {
        return $this->model::on($this->connection)
            ->where('group', $group)
            ->where('team_id', $teamId)
            ->whereNull('user_id');
    }

    public function getBuilderForUser(int $userId, string $group): Builder
    {
        return $this->model::on($this->connection)
            ->where('group', $group)
            ->where('user_id', $userId);
    }

    public function getBuilderForUserAndTeam(int $userId, int $teamId, string $group): Builder
    {
        return $this->model::on($this->connection)
            ->where('group', $group)
            ->where('user_id', $userId)
            ->where('team_id', $teamId);
    }

    public function getPropertiesInGroup(string $group, ?int $teamId = 0, ?int $userId = null): array
    {
        $cols = ['name', 'payload'];

        $defaults = $this->makeKeyValueArray($this->getBuilderForGlobalDefaults($group)->get($cols));

        $forTeam = ($teamId > 0) ? $this->makeKeyValueArray($this->getBuilderForTeam($teamId, $group)->get($cols)) : [];
        $forUserTeam = (($teamId > 0) && ($userId > 0)) ? $this->makeKeyValueArray($this->getBuilderForUserAndTeam($userId, $teamId, $group)->get($cols)) : [];
        $forUser = ($userId > 0) ? $this->makeKeyValueArray($this->getBuilderForUser($userId, $group)->get($cols)) : [];

        $settings = collect($defaults)->merge($forTeam)->merge($forUser)->merge($forUserTeam);

        return $settings->toArray();
    }

    public function makeKeyValueArray(Collection $list): array
    {
        return $list->mapWithKeys(function ($object) {
            return [$object->name => json_decode($object->payload, true)];
        })->toArray();
    }

    public function checkIfPropertyExists(string $group, string $name, ?int $teamId = 0, ?int $userId = null): bool
    {
        if (($teamId > 0) && ($userId > 0)) {
            return $this->getBuilderForUserAndTeam($userId, $teamId, $group)->where('name', $name)->exists();
        }
        if ($teamId > 0) {
            return $this->getBuilderForTeam($teamId, $group)->where('name', $name)->exists();
        }
        if ($userId > 0) {
            return $this->getBuilderForUser($userId, $group)->where('name', $name)->exists();
        }
        return $this->getBuilderForGlobalDefaults($group)->where('name', $name)->exists();
    }

    public function getPropertyPayload(string $group, string $name, ?int $teamId = 0, ?int $userId = null)
    {
        $default = $this->getBuilderForGlobalDefaults($group)->first('payload');

        $forTeam = ($teamId > 0) ? $this->getBuilderForTeam($teamId, $group)->first('payload') : null;
        $forUserTeam = (($teamId > 0) && ($userId > 0)) ? $this->getBuilderForUserAndTeam($userId, $teamId, $group)->first('payload') : null;
        $forUser = ($userId > 0) ? $this->getBuilderForUser($userId, $group)->first('payload') : null;

        $return = $forUserTeam ?? $forUser;
        $return = $return ?? $forTeam;
        $return = $return ?? $default;
        return json_decode(data_get($return, 'payload', null));
    }

    public function createProperty(string $group, string $name, $payload, ?int $teamId = 0, ?int $userId = null): void
    {
        $this->propertyModel::on($this->connection)->create([
            'group' => $group,
            'name' => $name,
            'team_id' => $teamId,
            'user_id' => $userId,
            'payload' => json_encode($payload),
            'locked' => false,
        ]);
    }

    public function updatePropertyPayload(string $group, string $name, $value, ?int $teamId = 0, ?int $userId = null): void
    {
        if (!$this->checkIfPropertyExists($group, $name, $teamId, $userId)) {
            $this->createProperty($group, $name, $value, $teamId, $userId);
            return;
        }

        $this->getMostSpecificBuilder($group, $teamId, $userId)
            ->update([
                'payload' => json_encode($value),
            ]);
    }

    public function getMostSpecificBuilder(string $group, ?int $teamId = 0, ?int $userId = null): Builder
    {
        if (($teamId > 0) && ($userId > 0)) {
            return $this->getBuilderForUserAndTeam($userId, $teamId, $group);
        }
        if (($userId > 0)) {
            return $this->getBuilderForUser($userId, $group);
        }
        if (($teamId > 0)) {
            return $this->getBuilderForUser($teamId, $group);
        }
        return $this->getBuilderForGlobalDefaults($group);
    }

    public function deleteProperty(string $group, string $name): void
    {
         $this->propertyModel::on($this->connection)
            ->where('group', $group)
            ->where('name', $name)
            ->delete();
    }

    public function lockProperties(string $group, array $properties): void
    {
        $this->propertyModel::on($this->connection)
            ->where('group', $group)
            ->whereIn('name', $properties)
            ->update(['locked' => true]);
    }

    public function unlockProperties(string $group, array $properties): void
    {
        $this->propertyModel::on($this->connection)
            ->where('group', $group)
            ->whereIn('name', $properties)
            ->update(['locked' => false]);
    }

    public function getLockedProperties(string $group): array
    {
        return $this->propertyModel::on($this->connection)
            ->where('group', $group)
            ->where('locked', true)
            ->pluck('name')
            ->toArray();
    }
}
