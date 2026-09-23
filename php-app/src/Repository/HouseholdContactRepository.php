<?php

declare(strict_types=1);

namespace HouseholdTracker\Repository;

use HouseholdTracker\Database\Connection;

final class HouseholdContactRepository
{
    public function create(
        int $householdId,
        int $createdByUserId,
        string $name,
        ?string $category,
        array $phones,
        array $emails,
        ?string $address,
        ?string $notes
    ): array {
        $pdo = Connection::get();
        $stmt = $pdo->prepare(
            'INSERT INTO household_contacts (household_id, name, category, address, notes, created_by_user_id)
             VALUES (:household_id, :name, :category, :address, :notes, :created_by_user_id)'
        );
        $stmt->execute([
            'household_id' => $householdId,
            'name' => $name,
            'category' => $category,
            'address' => $address,
            'notes' => $notes,
            'created_by_user_id' => $createdByUserId,
        ]);
        $id = (int) $pdo->lastInsertId();
        $this->replacePhones($id, $phones);
        $this->replaceEmails($id, $emails);

        return $this->findById($id);
    }

    public function findById(int $id): ?array
    {
        $stmt = Connection::get()->prepare('SELECT * FROM household_contacts WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        $row['phones'] = $this->listPhonesForContacts([$id])[$id] ?? [];
        $row['emails'] = $this->listEmailsForContacts([$id])[$id] ?? [];

        return $row;
    }

    public function listForHousehold(int $householdId): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT * FROM household_contacts WHERE household_id = :household_id ORDER BY name ASC'
        );
        $stmt->execute(['household_id' => $householdId]);
        $rows = $stmt->fetchAll();

        $contactIds = array_map(fn (array $row): int => (int) $row['id'], $rows);
        $phonesByContact = $this->listPhonesForContacts($contactIds);
        $emailsByContact = $this->listEmailsForContacts($contactIds);

        return array_map(function (array $row) use ($phonesByContact, $emailsByContact): array {
            $row['phones'] = $phonesByContact[(int) $row['id']] ?? [];
            $row['emails'] = $emailsByContact[(int) $row['id']] ?? [];

            return $row;
        }, $rows);
    }

    public function update(
        int $id,
        string $name,
        ?string $category,
        array $phones,
        array $emails,
        ?string $address,
        ?string $notes
    ): void {
        $stmt = Connection::get()->prepare(
            'UPDATE household_contacts
             SET name = :name, category = :category, address = :address, notes = :notes
             WHERE id = :id'
        );
        $stmt->execute([
            'name' => $name,
            'category' => $category,
            'address' => $address,
            'notes' => $notes,
            'id' => $id,
        ]);
        $this->replacePhones($id, $phones);
        $this->replaceEmails($id, $emails);
    }

    public function delete(int $id): void
    {
        $stmt = Connection::get()->prepare('DELETE FROM household_contacts WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * replacePhones(...)/replaceEmails(...) - wholesale replace, not a
     * diff, same "simplest correct thing for a small list edited as a
     * whole" approach HouseholdTaskRepository::replaceAssignees() already
     * uses for a task's assignees.
     *
     * @param array<array{label: string, phone: string}> $phones
     */
    public function replacePhones(int $contactId, array $phones): void
    {
        $pdo = Connection::get();
        $pdo->prepare('DELETE FROM household_contact_phones WHERE contact_id = :contact_id')->execute(['contact_id' => $contactId]);

        $stmt = $pdo->prepare('INSERT INTO household_contact_phones (contact_id, label, phone) VALUES (:contact_id, :label, :phone)');
        foreach ($phones as $phone) {
            $stmt->execute(['contact_id' => $contactId, 'label' => $phone['label'], 'phone' => $phone['phone']]);
        }
    }

    /**
     * @param array<array{label: string, email: string}> $emails
     */
    public function replaceEmails(int $contactId, array $emails): void
    {
        $pdo = Connection::get();
        $pdo->prepare('DELETE FROM household_contact_emails WHERE contact_id = :contact_id')->execute(['contact_id' => $contactId]);

        $stmt = $pdo->prepare('INSERT INTO household_contact_emails (contact_id, label, email) VALUES (:contact_id, :label, :email)');
        foreach ($emails as $email) {
            $stmt->execute(['contact_id' => $contactId, 'label' => $email['label'], 'email' => $email['email']]);
        }
    }

    /**
     * listPhonesForContacts(...)/listEmailsForContacts(...) - bulk-fetch
     * every phone/email row for every given contact id in one query each,
     * grouped by contact_id, so listForHousehold() doesn't run two queries
     * per row -- same shape as HouseholdTaskRepository::
     * listAssigneesForTasks().
     *
     * @param array<int> $contactIds
     * @return array<int, array<array{label: string, phone: string}>>
     */
    public function listPhonesForContacts(array $contactIds): array
    {
        if ($contactIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($contactIds), '?'));
        $stmt = Connection::get()->prepare(
            "SELECT contact_id, label, phone FROM household_contact_phones
             WHERE contact_id IN ({$placeholders}) ORDER BY id ASC"
        );
        $stmt->execute(array_values($contactIds));

        $byContact = [];
        foreach ($stmt->fetchAll() as $row) {
            $byContact[(int) $row['contact_id']][] = ['label' => $row['label'], 'phone' => $row['phone']];
        }

        return $byContact;
    }

    /**
     * @param array<int> $contactIds
     * @return array<int, array<array{label: string, email: string}>>
     */
    public function listEmailsForContacts(array $contactIds): array
    {
        if ($contactIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($contactIds), '?'));
        $stmt = Connection::get()->prepare(
            "SELECT contact_id, label, email FROM household_contact_emails
             WHERE contact_id IN ({$placeholders}) ORDER BY id ASC"
        );
        $stmt->execute(array_values($contactIds));

        $byContact = [];
        foreach ($stmt->fetchAll() as $row) {
            $byContact[(int) $row['contact_id']][] = ['label' => $row['label'], 'email' => $row['email']];
        }

        return $byContact;
    }
}
