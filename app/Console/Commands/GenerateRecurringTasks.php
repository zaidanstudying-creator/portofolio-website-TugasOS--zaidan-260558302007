<?php

namespace App\Console\Commands;

use App\Services\RecurringTaskGenerator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tasks:generate-recurring')]
#[Description('Create task instances due within the next 30 days')]
class GenerateRecurringTasks extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(RecurringTaskGenerator $generator): int
    {
        $count = $generator->generate();
        $this->components->info("Created {$count} recurring task(s).");

        return self::SUCCESS;
    }
}
