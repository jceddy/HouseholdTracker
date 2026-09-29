<?php

declare(strict_types=1);

namespace HouseholdTracker\Household;

use HouseholdTracker\Repository\HouseholdContactRepository;
use HouseholdTracker\Repository\HouseholdInventoryRepository;
use HouseholdTracker\Repository\HouseholdMemberRepository;

/**
 * Household inventory/asset tracking (issue #23): valuable possessions
 * (appliances, electronics, furniture) with purchase info, warranty
 * expiration, serial numbers, and location -- useful for insurance claims
 * and knowing what's still under warranty. See migration 0040's own
 * comment for the v1 design decisions (no value-tracking rollups, no
 * automatic warranty reminder, a single optional service_contact_id
 * instead of a full service-history log).
 *
 * Same "no privacy tiers, any member can add/edit/remove" permission
 * model as pets/contacts/staples -- a shared household resource, not one
 * member's private content.
 *
 * service_contact_id, if given, must reference a contact already in the
 * same household -- validateServiceContactId() below, the exact same
 * "must belong to this household" check HouseholdService::
 * validateVetContactId() already uses for household_pets.vet_contact_id.
 */
final class HouseholdInventoryService
{
    private const MAX_NAME_LENGTH = 150;
    private const MAX_CATEGORY_LENGTH = 50;
    private const MAX_SERIAL_NUMBER_LENGTH = 100;
    private const MAX_LOCATION_LENGTH = 150;
    private const MAX_NOTES_LENGTH = 2000;
    private const MAX_PRICE = 99999999.99;

    public function __construct(
        private readonly HouseholdMemberRepository $members,
        private readonly HouseholdInventoryRepository $items,
        private readonly HouseholdContactRepository $contacts,
    ) {
    }

    public function listItems(int $callerId, int $householdId): array
    {
        $this->requireMember($householdId, $callerId);

        return $this->items->listForHousehold($householdId);
    }

    public function createItem(
        int $callerId,
        int $householdId,
        string $name,
        ?string $category,
        ?string $purchaseDate,
        ?string $purchasePrice,
        ?string $warrantyExpiresAt,
        ?string $serialNumber,
        ?string $location,
        ?string $notes,
        ?int $serviceContactId
    ): array {
        $this->requireMember($householdId, $callerId);
        [$name, $category, $purchaseDate, $purchasePrice, $warrantyExpiresAt, $serialNumber, $location, $notes]
            = $this->validateInput($name, $category, $purchaseDate, $purchasePrice, $warrantyExpiresAt, $serialNumber, $location, $notes);
        $this->validateServiceContactId($householdId, $serviceContactId);

        return $this->items->create(
            $householdId,
            $callerId,
            $name,
            $category,
            $purchaseDate,
            $purchasePrice,
            $warrantyExpiresAt,
            $serialNumber,
            $location,
            $notes,
            $serviceContactId
        );
    }

    public function updateItem(
        int $callerId,
        int $itemId,
        string $name,
        ?string $category,
        ?string $purchaseDate,
        ?string $purchasePrice,
        ?string $warrantyExpiresAt,
        ?string $serialNumber,
        ?string $location,
        ?string $notes,
        ?int $serviceContactId
    ): array {
        $item = $this->requireItem($itemId);
        $this->requireMember((int) $item['household_id'], $callerId);
        [$name, $category, $purchaseDate, $purchasePrice, $warrantyExpiresAt, $serialNumber, $location, $notes]
            = $this->validateInput($name, $category, $purchaseDate, $purchasePrice, $warrantyExpiresAt, $serialNumber, $location, $notes);
        $this->validateServiceContactId((int) $item['household_id'], $serviceContactId);

        $this->items->update(
            (int) $item['id'],
            $name,
            $category,
            $purchaseDate,
            $purchasePrice,
            $warrantyExpiresAt,
            $serialNumber,
            $location,
            $notes,
            $serviceContactId
        );

        return $this->items->findById((int) $item['id']);
    }

    public function deleteItem(int $callerId, int $itemId): void
    {
        $item = $this->requireItem($itemId);
        $this->requireMember((int) $item['household_id'], $callerId);
        $this->items->delete((int) $item['id']);
    }

    private function requireItem(int $itemId): array
    {
        $item = $this->items->findById($itemId);
        if ($item === null) {
            throw new InventoryItemNotFoundException('Inventory item not found.');
        }

        return $item;
    }

    private function validateInput(
        string $name,
        ?string $category,
        ?string $purchaseDate,
        ?string $purchasePrice,
        ?string $warrantyExpiresAt,
        ?string $serialNumber,
        ?string $location,
        ?string $notes
    ): array {
        $name = trim($name);
        if ($name === '' || strlen($name) > self::MAX_NAME_LENGTH) {
            throw new \InvalidArgumentException('Item name must be 1-' . self::MAX_NAME_LENGTH . ' characters.');
        }

        $category = $category !== null ? trim($category) : null;
        $category = $category === '' ? null : $category;
        if ($category !== null && strlen($category) > self::MAX_CATEGORY_LENGTH) {
            throw new \InvalidArgumentException('Category must be ' . self::MAX_CATEGORY_LENGTH . ' characters or fewer.');
        }

        $purchaseDate = $this->validateOptionalDate($purchaseDate, 'purchase_date');
        $warrantyExpiresAt = $this->validateOptionalDate($warrantyExpiresAt, 'warranty_expires_at');
        $purchasePrice = $this->validatePrice($purchasePrice);

        $serialNumber = $serialNumber !== null ? trim($serialNumber) : null;
        $serialNumber = $serialNumber === '' ? null : $serialNumber;
        if ($serialNumber !== null && strlen($serialNumber) > self::MAX_SERIAL_NUMBER_LENGTH) {
            throw new \InvalidArgumentException('Serial number must be ' . self::MAX_SERIAL_NUMBER_LENGTH . ' characters or fewer.');
        }

        $location = $location !== null ? trim($location) : null;
        $location = $location === '' ? null : $location;
        if ($location !== null && strlen($location) > self::MAX_LOCATION_LENGTH) {
            throw new \InvalidArgumentException('Location must be ' . self::MAX_LOCATION_LENGTH . ' characters or fewer.');
        }

        $notes = $notes !== null ? trim($notes) : null;
        $notes = $notes === '' ? null : $notes;
        if ($notes !== null && strlen($notes) > self::MAX_NOTES_LENGTH) {
            throw new \InvalidArgumentException('Notes must be ' . self::MAX_NOTES_LENGTH . ' characters or fewer.');
        }

        return [$name, $category, $purchaseDate, $purchasePrice, $warrantyExpiresAt, $serialNumber, $location, $notes];
    }

    private function validateOptionalDate(?string $date, string $fieldName): ?string
    {
        $date = $date !== null ? trim($date) : null;
        if ($date === null || $date === '') {
            return null;
        }

        $parsed = \DateTime::createFromFormat('Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException($fieldName . ' must be in YYYY-MM-DD format.');
        }

        return $date;
    }

    private function validatePrice(?string $price): ?string
    {
        $price = $price !== null ? trim($price) : null;
        if ($price === null || $price === '') {
            return null;
        }

        if (!is_numeric($price) || (float) $price < 0 || (float) $price > self::MAX_PRICE) {
            throw new \InvalidArgumentException('purchase_price must be a non-negative number.');
        }

        return number_format((float) $price, 2, '.', '');
    }

    private function validateServiceContactId(int $householdId, ?int $serviceContactId): void
    {
        if ($serviceContactId === null) {
            return;
        }

        $contact = $this->contacts->findById($serviceContactId);
        if ($contact === null || (int) $contact['household_id'] !== $householdId) {
            throw new \InvalidArgumentException('The service contact must be a contact in this household.');
        }
    }

    private function requireMember(int $householdId, int $userId): void
    {
        if ($this->members->find($householdId, $userId) === null) {
            throw new NotAHouseholdMemberException('You are not a member of this household.');
        }
    }
}
