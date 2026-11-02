<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One stored override of a config default.
 *
 * Read and written through App\Services\SettingsService, never
 * directly: the service owns the cache and the encryption flag, and a
 * write that goes around it leaves both wrong.
 */
class Setting extends Model
{
    protected $fillable = [
        'key',
        'value',
        'is_encrypted',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_encrypted' => 'boolean',
        ];
    }

    /** The person who last changed it. May be gone. */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
