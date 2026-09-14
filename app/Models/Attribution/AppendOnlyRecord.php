<?php

namespace App\Models\Attribution;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/** Model guards do not protect raw SQL; trusted writers must preserve history. */
abstract class AppendOnlyRecord extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected static function booted(): void
    {
        static::updating(function (self $record): void {
            if ($record->isDirty()) {
                throw ValidationException::withMessages(['history' => 'Append a new evaluation or correction; history cannot be changed.']);
            }
        });
        static::deleting(function (): void {
            throw ValidationException::withMessages(['history' => 'History cannot be deleted through ordinary model operations.']);
        });
    }
}
