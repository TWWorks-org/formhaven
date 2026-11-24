<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/** A notice that a form is illegal or abusive (DSA art. 16), sent through /report. */
class AbuseReport extends Model
{
    use MassPrunable;

    protected $fillable = ['url', 'reason', 'name', 'email'];

    /** Kept 2 years to show how a report was handled, as the privacy policy says; then deleted. */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subYears(2));
    }
}
