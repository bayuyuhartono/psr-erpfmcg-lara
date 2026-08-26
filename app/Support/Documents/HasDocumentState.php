<?php

namespace App\Support\Documents;

use App\Models\User;
use App\Support\Documents\Exceptions\DocumentFrozen;
use App\Support\Documents\Exceptions\IllegalTransition;
use App\Support\Documents\Exceptions\TransitionNotPermitted;

/**
 * Guards the status column of a document model.
 *
 * `transitionTo()` is the only way a status changes: assigning to it and
 * saving throws instead. A status string updated by hand anywhere in the
 * codebase is a bug, so this makes it a loud one.
 *
 * The guards hang off model events, which a mass `update()` on the query
 * builder bypasses. Documents are transitioned one at a time, deliberately.
 */
trait HasDocumentState
{
    private bool $transitioning = false;

    abstract public static function states(): DocumentStates;

    /**
     * @param  array<string, mixed>  $attributes  transition metadata written with the
     *                                            status: approved_by, released_at, a hold reason.
     */
    public function transitionTo(string $status, ?User $actor = null, array $attributes = []): static
    {
        $states = static::states();
        $from = $this->currentStatus();

        if (! $states->allows($from, $status)) {
            throw new IllegalTransition(sprintf(
                '%s cannot move from "%s" to "%s".',
                class_basename($this),
                $from,
                $status,
            ));
        }

        $permission = $states->permissionFor($from, $status);
        if ($permission !== null && ! $actor?->hasPermission($permission)) {
            throw new TransitionNotPermitted(sprintf(
                'Moving %s from "%s" to "%s" requires the "%s" permission.',
                class_basename($this),
                $from,
                $status,
                $permission,
            ));
        }

        $this->transitioning = true;

        try {
            $this->forceFill($attributes + ['status' => $status])->save();
        } finally {
            $this->transitioning = false;
        }

        return $this;
    }

    public function canTransitionTo(string $status): bool
    {
        return static::states()->allows($this->currentStatus(), $status);
    }

    public function isFrozen(): bool
    {
        return static::states()->freezes($this->currentStatus());
    }

    /** The status as stored, not as assigned in memory. */
    private function currentStatus(): string
    {
        return (string) $this->getOriginal('status', $this->status);
    }

    public static function bootHasDocumentState(): void
    {
        static::creating(function (self $document): void {
            $states = static::states();

            // Creating a document without naming a status is normal — the
            // migration has a default. Fill it from the state machine so the
            // two cannot drift, rather than rejecting a perfectly ordinary
            // create with a confusing message about the empty string.
            if (blank($document->status)) {
                $document->status = $states->initial();

                return;
            }

            if (! in_array((string) $document->status, $states->statuses(), true)) {
                throw new IllegalTransition(sprintf(
                    '"%s" is not a status %s recognises.',
                    $document->status,
                    class_basename($document),
                ));
            }
        });

        static::updating(function (self $document): void {
            if ($document->transitioning) {
                return;
            }

            if ($document->isDirty('status')) {
                throw new IllegalTransition(sprintf(
                    'Change %s status through transitionTo(), never by assignment.',
                    class_basename($document),
                ));
            }

            if ($document->isFrozen()) {
                throw new DocumentFrozen(sprintf(
                    '%s is %s and can no longer be edited. Reverse it instead.',
                    class_basename($document),
                    $document->currentStatus(),
                ));
            }
        });

        static::deleting(function (self $document): void {
            if ($document->isFrozen()) {
                throw new DocumentFrozen(sprintf(
                    '%s is %s and cannot be deleted. Reverse it instead.',
                    class_basename($document),
                    $document->currentStatus(),
                ));
            }
        });
    }
}
