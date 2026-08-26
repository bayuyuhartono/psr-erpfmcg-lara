<?php

namespace App\Support\Documents;

/**
 * The legal moves for one kind of document, declared in one place.
 *
 * Posting bugs live in status changes: a document posted twice, edited after
 * posting, or cancelled without a reversal. Every such move is a transition
 * named here, so the set of them is readable rather than scattered across the
 * modules that perform them.
 */
abstract class DocumentStates
{
    /** @return array<string, list<string>> status => the statuses it may move to */
    abstract public function transitions(): array;

    /**
     * Statuses after which the document's own fields are frozen. A frozen
     * document may still transition — posted → cancelled — but nothing else
     * about it changes; corrections are reversals.
     *
     * @return list<string>
     */
    public function frozen(): array
    {
        return [];
    }

    /** @return array<string, string> "from>to" => the permission it requires */
    public function permissions(): array
    {
        return [];
    }

    /**
     * The status a new document starts in: the first one declared, unless a
     * document overrides it. Creating a document without naming a status uses
     * this, so the column default in the migration and the state machine
     * cannot drift apart.
     */
    public function initial(): string
    {
        return (string) array_key_first($this->transitions());
    }

    /** @return list<string> */
    public function statuses(): array
    {
        $transitions = $this->transitions();

        return array_values(array_unique(array_merge(array_keys($transitions), ...array_values($transitions))));
    }

    public function allows(string $from, string $to): bool
    {
        return in_array($to, $this->transitions()[$from] ?? [], true);
    }

    public function permissionFor(string $from, string $to): ?string
    {
        return $this->permissions()[$from.'>'.$to] ?? null;
    }

    public function freezes(string $status): bool
    {
        return in_array($status, $this->frozen(), true);
    }
}
