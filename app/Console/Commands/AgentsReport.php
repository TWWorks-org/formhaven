<?php

namespace App\Console\Commands;

use App\Models\FormClaim;
use App\Support\AdminStats;
use Illuminate\Console\Command;

/** How the agent start flow is doing: how many forms agents started and how many a person activated. Same source as /admin. */
class AgentsReport extends Command
{
    protected $signature = 'agents:report';

    protected $description = 'Forms started by agents, how many site owners activated them, and how many got a real message';

    public function handle(AdminStats $stats): int
    {
        foreach ([7, 28] as $days) {
            $s = $stats->agentStarts($days);

            $this->line("Last {$days} days");
            $this->line('  Started:   '.$s['started'].' (claims older than '.FormClaim::DAYS.' days that were never activated are pruned, so older starts undercount)');
            $this->line('  Activated: '.$s['activated'].($s['started'] ? ' ('.round($s['activated'] / $s['started'] * 100).'%)' : ''));
            $this->line('  Activated and got a real message: '.$s['with_message']);

            foreach ($s['clients'] as $name => $n) {
                $this->line("    {$name}: {$n}");
            }
            $this->newLine();
        }

        return self::SUCCESS;
    }
}
