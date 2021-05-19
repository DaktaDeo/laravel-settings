<?php

namespace Spatie\LaravelSettings\SettingsRepositories;

interface SettingsRepository
{
    /**
     * Get all the properties in the repository for a single group
     */
    public function getPropertiesInGroup(string $group, ?int $teamId = 0, ?int $userId = null): array;

    /**
     * Check if a property exists in a group
     */
    public function checkIfPropertyExists(string $group, string $name, ?int $teamId = 0, ?int $userId = null): bool;

    /**
     * Get the payload of a property
     */
    public function getPropertyPayload(string $group, string $name, ?int $teamId = 0, ?int $userId = null);

    /**
     * Create a property within a group with a payload
     */
    public function createProperty(string $group, string $name, $payload, ?int $teamId = 0, ?int $userId = null): void;

    /**
     * Update the payload of a property within a group
     */
    public function updatePropertyPayload(string $group, string $name, $value, ?int $teamId = 0, ?int $userId = null): void;

    /**
     * Delete a property from a group
     */
    public function deleteProperty(string $group, string $name): void;

    /**
     * Lock a set of properties for a specific group
     */
    public function lockProperties(string $group, array $properties): void;

    /**
     * Unlock a set of properties for a group
     */
    public function unlockProperties(string $group, array $properties): void;

    /**
     * Get all the locked properties within a group
     */
    public function getLockedProperties(string $group): array;
}
