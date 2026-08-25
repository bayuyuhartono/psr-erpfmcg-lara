<?php

namespace App\Support\Audit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class AuditObserver
{
    public function created(Model $model): void
    {
        $this->write($model, 'insert', null, $model->getAttributes());
    }

    public function updated(Model $model): void
    {
        $old = array_intersect_key($model->getRawOriginal(), $model->getChanges());
        $this->write($model, 'update', $old, $model->getChanges());
    }

    public function deleted(Model $model): void
    {
        $this->write($model, 'delete', $model->getRawOriginal(), null);
    }

    private function write(Model $model, string $action, ?array $old, ?array $new): void
    {
        if ($model->getTable() === 'audit_logs' || ! $model->getKey()) {
            return;
        }

        $hidden = array_flip(array_merge($model->getHidden(), ['password', 'remember_token']));
        $old = $old === null ? null : array_diff_key($old, $hidden);
        $new = $new === null ? null : array_diff_key($new, $hidden);

        DB::table('audit_logs')->insert([
            'user_id' => Auth::id(),
            'table_name' => $model->getTable(),
            'record_id' => (string) $model->getKey(),
            'action' => $action,
            'old_data' => $old === null ? null : json_encode($old, JSON_THROW_ON_ERROR),
            'new_data' => $new === null ? null : json_encode($new, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }
}
