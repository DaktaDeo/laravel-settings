<?php

namespace Spatie\LaravelSettings\SettingsRepositories;

use DB;
use Spatie\LaravelSettings\Models\SettingsProperty;

class DatabaseSettingsRepository implements SettingsRepository
{
    /** @var string|\Illuminate\Database\Eloquent\Model */
    protected string $propertyModel;

    protected ?string $connection;

    public function __construct(array $config)
    {
        $this->propertyModel = $config['model'] ?? SettingsProperty::class;

        $this->connection = $config['connection'] ?? null;
    }

    public function getPropertiesInGroup(string $group, ?int $teamId = 0, ?int $userId = null): array
    {
        /**
         * @var \Spatie\LaravelSettings\Models\SettingsProperty $temp
         * @psalm-suppress UndefinedClass
         */
        $temp = new $this->propertyModel;

        $defaults = DB::connection($this->connection ?? $temp->getConnectionName())
            ->table($temp->getTable())
            ->where('group', $group)
            ->where('team_id', '=', 0)
            ->whereNull('user_id')
            ->get(['name', 'payload']);

        $overrulers = DB::connection($this->connection ?? $temp->getConnectionName())
            ->table($temp->getTable())
            ->where('group', $group)
            ->where('team_id', $teamId)
            ->where(function ($query) use ($userId) {
                $query->where('user_id', $userId)->orWhereNull('user_id');
            })
            ->get(['name', 'payload']);

        $merged = $defaults->merge($overrulers);

        return $merged->mapWithKeys(function ($object) {
            return [$object->name => json_decode($object->payload, true)];
        })->toArray();
    }

    public function checkIfPropertyExists(string $group, string $name, ?int $teamId = 0, ?int $userId = null): bool
    {
        return $this->propertyModel::on($this->connection)
            ->where('group', $group)
            ->where('team_id', $teamId)
            ->where(function ($query) use ($userId) {
                $query->where('user_id', $userId)->orWhereNull('user_id');
            })
            ->where('name', $name)
            ->exists();
    }

    public function getPropertyPayload(string $group, string $name, ?int $teamId = 0, ?int $userId = null)
    {
        $setting = $this->propertyModel::on($this->connection)
            ->where('group', $group)
            ->where('name', $name)
            ->where('team_id', $teamId)
            ->where(function ($query) use ($userId) {
                $query->where('user_id', $userId)->orWhereNull('user_id');
            })
            ->first('payload')
            ->toArray();

        return json_decode($setting['payload']);
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
        if(!$this->checkIfPropertyExists($group,$name,$teamId,$userId)){
            $this->createProperty($group,$name,$value,$teamId,$userId);
            return;
        }
        if ($userId !== null) {
            $this->propertyModel::on($this->connection)
                ->where('group', $group)
                ->where('name', $name)
                ->where('team_id', $teamId)
                ->where('user_id', $userId)
                ->update([
                    'payload' => json_encode($value),
                ]);
            return;
        }
        $this->propertyModel::on($this->connection)
            ->where('group', $group)
            ->where('name', $name)
            ->where('team_id', $teamId)
            ->update([
                'payload' => json_encode($value),
            ]);
    }

    public function deleteProperty(string $group, string $name, ?int $teamId = 0, ?int $userId = null): void
    {
        if ($userId !== null) {
            $this->propertyModel::on($this->connection)
                ->where('group', $group)
                ->where('name', $name)
                ->where('team_id', $teamId)
                ->where('user_id', $userId)
                ->delete();
            return;
        }
        $this->propertyModel::on($this->connection)
            ->where('group', $group)
            ->where('name', $name)
            ->where('team_id', $teamId)
            ->delete();
    }

    public function lockProperties(string $group, array $properties, ?int $teamId = 0, ?int $userId = null): void
    {
        $this->propertyModel::on($this->connection)
            ->where('group', $group)
            ->whereIn('name', $properties)
            ->where('team_id', $teamId)
            ->where(function ($query) use ($userId) {
                $query->where('user_id', $userId)->orWhereNull('user_id');
            })
            ->update(['locked' => true]);
    }

    public function unlockProperties(string $group, array $properties, ?int $teamId = 0, ?int $userId = null): void
    {
        $this->propertyModel::on($this->connection)
            ->where('group', $group)
            ->whereIn('name', $properties)
            ->where('team_id', $teamId)
            ->where(function ($query) use ($userId) {
                $query->where('user_id', $userId)->orWhereNull('user_id');
            })
            ->update(['locked' => false]);
    }

    public function getLockedProperties(string $group, ?int $teamId = 0, ?int $userId = null): array
    {
        return $this->propertyModel::on($this->connection)
            ->where('group', $group)
            ->where('locked', true)
            ->where('team_id', $teamId)
            ->where(function ($query) use ($userId) {
                $query->where('user_id', $userId)->orWhereNull('user_id');
            })
            ->pluck('name')
            ->toArray();
    }
}
