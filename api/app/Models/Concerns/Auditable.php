<?php

namespace App\Models\Concerns;

use App\Actions\Audit\RecordAudit;
use App\Enums\AuditAction;
use App\Support\CurrentOrganization;
use Closure;
use LogicException;

/**
 * Records created, updated and deleted events of a tenant-owned model in the audit
 * log, with the changed attributes as the API would serialize them (casts applied).
 * The model needs AuditAction cases "{auditName()}.created", ".updated" and
 * ".deleted". Keys, organization_id, timestamps and $hidden are never recorded;
 * list other columns to leave out in $auditExclude.
 *
 * Builder updates and deletes (Model::query()->update()) skip model events and
 * aren't recorded: change audited models one by one, or record the change yourself.
 *
 * @property list<string> $auditExclude
 */
trait Auditable
{
    private static bool $auditingDisabled = false;

    private const AUDIT_ALWAYS_EXCLUDED = ['id', 'organization_id', 'created_at', 'updated_at'];

    private const AUDIT_MAX_LENGTH = 500;

    public static function bootAuditable(): void
    {
        static::created(fn (self $model) => $model->recordAudit('created', [], $model->attributesToArray()));
        static::updated(fn (self $model) => $model->recordAudit('updated', $model->auditOriginal(), $model->attributesToArray(), array_keys($model->getChanges())));
        static::deleted(fn (self $model) => $model->recordAudit('deleted', $model->attributesToArray(), []));
    }

    /**
     * Run code without recording changes of this class's models (e.g. setting up a
     * new organization).
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function withoutAuditing(Closure $callback): mixed
    {
        $previous = self::$auditingDisabled;
        self::$auditingDisabled = true;

        try {
            return $callback();
        } finally {
            self::$auditingDisabled = $previous;
        }
    }

    /**
     * The subject type in audit entries, e.g. "task".
     */
    public function auditName(): string
    {
        return strtolower(class_basename($this));
    }

    public function auditLabel(): ?string
    {
        return $this->getAttribute('name');
    }

    /**
     * Turn the diff into what the log keeps, e.g. ids into names that still read
     * once the record they point at is gone.
     *
     * @param  array<string, array{old: mixed, new: mixed}>  $changes
     * @return array<string, array{old: mixed, new: mixed}>
     */
    protected function auditChanges(array $changes): array
    {
        return $changes;
    }

    /**
     * The attributes before this update, serialized like attributesToArray().
     *
     * @return array<string, mixed>
     */
    private function auditOriginal(): array
    {
        return (clone $this)->setRawAttributes($this->getRawOriginal())->attributesToArray();
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  list<string>|null  $keys  the changed columns (default: every column on either side)
     */
    private function recordAudit(string $event, array $before, array $after, ?array $keys = null): void
    {
        if (self::$auditingDisabled) {
            return;
        }

        // The entry goes to the current organization's log: never let a record of
        // another organization end up there (e.g. a model saved inside run()).
        if ((int) $this->getAttribute('organization_id') !== app(CurrentOrganization::class)->id()) {
            throw new LogicException('An audited model can only be changed while acting in its organization.');
        }

        $excluded = [
            ...self::AUDIT_ALWAYS_EXCLUDED,
            ...$this->getHidden(),
            ...(property_exists($this, 'auditExclude') ? $this->auditExclude : []),
        ];
        $changes = [];

        foreach (array_diff($keys ?? array_keys($before + $after), $excluded) as $key) {
            $old = $before[$key] ?? null;
            $new = $after[$key] ?? null;

            // Compare full values; only what is stored is shortened.
            if ($old !== $new) {
                $changes[$key] = ['old' => $this->auditShorten($old), 'new' => $this->auditShorten($new)];
            }
        }

        if ($event === 'updated' && $changes === []) {
            return;
        }

        app(RecordAudit::class)->handle(
            AuditAction::from("{$this->auditName()}.{$event}"),
            ['type' => $this->auditName(), 'id' => $this->getKey(), 'label' => $this->auditLabel()],
            $this->auditChanges($changes),
        );
    }

    private function auditShorten(mixed $value): mixed
    {
        return match (true) {
            is_array($value) => array_map($this->auditShorten(...), $value),
            is_string($value) && mb_strlen($value) > self::AUDIT_MAX_LENGTH => mb_substr($value, 0, self::AUDIT_MAX_LENGTH).'…',
            default => $value,
        };
    }
}
