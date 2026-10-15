<?php

declare(strict_types=1);

namespace App\Domain\Users\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedWorkspace extends Model
{
    protected $fillable = ['user_id', 'name', 'layout', 'is_default'];

    protected function casts(): array
    {
        return [
            'layout' => 'array',
            'is_default' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
