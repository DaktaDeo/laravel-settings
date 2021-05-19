<?php

namespace Spatie\LaravelSettings;

use Illuminate\Container\Container;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Support\Collection;
use ReflectionProperty;
use Serializable;
use Spatie\LaravelSettings\Events\SavingSettings;
use Spatie\LaravelSettings\Events\SettingsLoaded;
use Spatie\LaravelSettings\Events\SettingsSaved;

abstract class Settings implements Arrayable, Jsonable, Responsable, Serializable
{
    private SettingsMapper $mapper;

    private SettingsConfig $config;

    private bool $loaded = false;
    protected ?int $teamId = 0;
    protected ?int $userId = null;

    private bool $configInitialized = false;

    protected ?Collection $originalValues = null;

    abstract public static function group(): string;

    public static function repository(): ?string
    {
        return null;
    }

    public static function casts(): array
    {
        return [];
    }

    public static function encrypted(): array
    {
        return [];
    }

    /**
     * @param array $values
     *
     * @return static
     */
    public static function fake(array $values, ?int $teamId=0, ?int $userId = null): self
    {
        $settingsMapper = app(SettingsMapper::class);

        $propertiesToLoad = $settingsMapper->initialize(static::class)
            ->getReflectedProperties()
            ->keys()
            ->reject(fn (string $name) => array_key_exists($name, $values));

        $mergedValues = $settingsMapper
            ->fetchProperties(static::class,$teamId, $propertiesToLoad, $userId)
            ->merge($values)
            ->toArray();

        return app(Container::class)->instance(static::class, new static(
            $mergedValues
        ));
    }

    public function __construct(array $values = [], ?int $teamId=0, ?int $userId = null)
    {
        $this->ensureConfigIsLoaded();
        $this->userId = $userId;
        $this->teamId = $teamId;

        foreach ($this->config->getReflectedProperties()->keys() as $name) {
            unset($this->{$name});
        }

        if (! empty($values)) {
            $this->loadValues($values);
        }
    }

    public function __get($name)
    {
        $this->loadValues();

        return $this->{$name};
    }

    public function __set($name, $value)
    {
        $this->loadValues();

        $this->{$name} = $value;
    }

    public function __debugInfo()
    {
        $this->loadValues();
    }

    /**
     * @param \Illuminate\Support\Collection|array $properties
     *
     * @return $this
     */
    public function fill($properties): self
    {
        foreach ($properties as $name => $payload) {
            $this->{$name} = $payload;
        }

        return $this;
    }

    public function save(): self
    {
        $properties = $this->toCollection();

        event(new SavingSettings($properties, $this->originalValues, $this));

        $values = $this->mapper->save(static::class,$this->teamId, $properties, $this->userId);

        $this->fill($values);
        $this->originalValues = $values;

        event(new SettingsSaved($this));

        return $this;
    }

    public function lock(string ...$properties)
    {
        $this->ensureConfigIsLoaded();

        $this->config->lock(...$properties);
    }

    public function unlock(string ...$properties)
    {
        $this->ensureConfigIsLoaded();

        $this->config->unlock(...$properties);
    }

    public function getLockedProperties(): array
    {
        $this->ensureConfigIsLoaded();

        return $this->config->getLocked()->toArray();
    }

    public function toCollection(): Collection
    {
        $this->ensureConfigIsLoaded();

        return $this->config
            ->getReflectedProperties()
            ->mapWithKeys(fn (ReflectionProperty $property) => [
                $property->getName() => $this->{$property->getName()},
            ]);
    }

    public function toArray(): array
    {
        return $this->toCollection()->toArray();
    }

    public function toJson($options = 0): string
    {
        return json_encode($this->toArray(), $options);
    }

    public function toResponse($request)
    {
        return response()->json($this->toJson());
    }

    public function serialize(): string
    {
        return serialize($this->toArray());
    }

    public function unserialize($serialized): void
    {
        $values = unserialize($serialized);

        $this->loaded = false;
        $this->loadValues($values);
    }

    private function loadValues(?array $values = null): self
    {
        if ($this->loaded) {
            return $this;
        }

        $values ??= $this->mapper->load(static::class,$this->teamId,$this->userId);

        $this->loaded = true;

        $this->fill($values);
        $this->originalValues = collect($values);

        event(new SettingsLoaded($this));

        return $this;
    }

    private function ensureConfigIsLoaded(): self
    {
        ray('init');
        if ($this->configInitialized) {
            ray('->self');
            return $this;
        }
        ray('->new');

        $this->mapper = app(SettingsMapper::class);
        $this->config = $this->mapper->initialize(static::class);
        $this->configInitialized = true;

        return $this;
    }
}
