<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;

#[Fillable(['telegram_user_id', 'username', 'first_name', 'activated_at', 'revoked_at'])]
class TelegramAccount extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return [
            'telegram_user_id' => 'integer',
            'activated_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** Shown as the "who" of bot-made changes in the activity log (causer). */
    protected function name(): Attribute
    {
        return Attribute::get(fn () => 'Telegram · '.($this->username ? "@{$this->username}" : ($this->first_name ?: $this->telegram_user_id)));
    }
}
