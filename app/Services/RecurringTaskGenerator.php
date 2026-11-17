<?php

namespace App\Services;

use App\Models\RecurrenceRule;
use App\Models\Task;
use App\Models\TaskSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class RecurringTaskGenerator
{
    public function generate(int $daysAhead = 30, ?int $userId = null): int
    {
        $horizon = CarbonImmutable::today()->addDays($daysAhead);
        $createdCount = 0;

        foreach (RecurrenceRule::query()
            ->where('is_active', true)
            ->when($userId !== null, fn ($query) => $query->where('user_id', $userId))
            ->pluck('id') as $ruleId) {
            $createdCount += DB::transaction(function () use ($ruleId, $horizon): int {
                $rule = RecurrenceRule::query()->with('templateTask.items', 'templateTask.schedules')->lockForUpdate()->whereKey($ruleId)->first();

                if (! $rule || ! $rule->is_active) {
                    return 0;
                }

                $template = $rule->templateTask;

                if (! $template || $template->archived_at !== null) {
                    $rule->update(['is_active' => false]);

                    return 0;
                }

                $occurrence = CarbonImmutable::parse($rule->next_run_at);
                $endDate = $rule->ends_on ? CarbonImmutable::parse($rule->ends_on) : null;
                $createdForRule = 0;

                if ($rule->frequency === 'weekly' && $rule->weekdays && ! in_array($occurrence->dayOfWeek, $rule->weekdays, true)) {
                    $occurrence = $this->nextOccurrence($rule, $occurrence);
                }

                while ($occurrence->lessThanOrEqualTo($horizon) && (! $endDate || $occurrence->lessThanOrEqualTo($endDate))) {
                    $previousTemplateProgress = $template->computedProgress();
                    $dueAt = $template->due_at
                        ? $occurrence->setTime((int) $template->due_at->format('H'), (int) $template->due_at->format('i'))
                        : null;
                    $startsAt = $template->starts_at
                        ? $occurrence->setTime((int) $template->starts_at->format('H'), (int) $template->starts_at->format('i'))
                        : null;

                    $task = Task::query()->firstOrCreate(
                        [
                            'recurrence_rule_id' => $rule->id,
                            'recurrence_occurrence_date' => $occurrence->toDateString(),
                        ],
                        [
                            'user_id' => $rule->user_id,
                            'parent_task_id' => $template->id,
                            'category_id' => $template->category_id,
                            'course_id' => $template->course_id,
                            'semester_id' => $template->semester_id,
                            'title' => $template->title,
                            'description' => $template->description,
                            'type' => $template->type,
                            'status' => 'todo',
                            'priority' => $template->priority,
                            'starts_at' => $startsAt,
                            'due_at' => $dueAt,
                            'estimated_minutes' => $template->estimated_minutes,
                            'weight' => $template->weight,
                            'notes' => $template->notes,
                        ],
                    );

                    if ($task->wasRecentlyCreated) {
                        $task->assigners()->sync($template->assigners()->pluck('assigners.id'));
                        $task->items()->createMany($template->items->map(fn ($item): array => [
                            'title' => $item->title,
                            'position' => $item->position,
                            'is_completed' => false,
                        ])->all());
                        $templateAnchor = CarbonImmutable::parse($rule->starts_on)->startOfDay();
                        $task->schedules()->createMany($template->schedules->map(function (TaskSchedule $schedule) use ($rule, $templateAnchor, $occurrence): array {
                            $dayOffset = (int) $templateAnchor->diffInDays($schedule->starts_at->startOfDay(), absolute: false);
                            $scheduledDate = $occurrence->addDays($dayOffset);
                            $start = $schedule->is_all_day
                                ? $scheduledDate->startOfDay()
                                : $scheduledDate->setTime(
                                    (int) $schedule->starts_at->format('H'),
                                    (int) $schedule->starts_at->format('i'),
                                    (int) $schedule->starts_at->format('s'),
                                );
                            $duration = $schedule->is_all_day
                                ? max(1, (int) $schedule->starts_at->diffInDays($schedule->ends_at, absolute: true))
                                : null;
                            $end = $schedule->is_all_day
                                ? $start->addDays($duration)
                                : $start->addSeconds($schedule->starts_at->diffInSeconds($schedule->ends_at, absolute: true));

                            return [
                                'user_id' => $rule->user_id,
                                'starts_at' => $start,
                                'ends_at' => $end,
                                'is_all_day' => $schedule->is_all_day,
                                'label' => $schedule->label,
                            ];
                        })->all());
                        $task->statusEvents()->create([
                            'user_id' => $rule->user_id,
                            'from_status' => null,
                            'to_status' => 'todo',
                        ]);
                        $template->unsetRelation('children');
                        $templateProgress = $template->computedProgress();

                        if ($templateProgress !== $previousTemplateProgress) {
                            $template->progressEvents()->create([
                                'user_id' => $rule->user_id,
                                'from_progress' => $previousTemplateProgress,
                                'to_progress' => $templateProgress,
                            ]);
                        }

                        $createdForRule++;
                    }

                    $occurrence = $this->nextOccurrence($rule, $occurrence);
                }

                $rule->update(['next_run_at' => $occurrence->toDateString()]);

                if ($endDate && $occurrence->greaterThan($endDate)) {
                    $rule->update(['is_active' => false]);
                }

                return $createdForRule;
            });
        }

        return $createdCount;
    }

    private function nextOccurrence(RecurrenceRule $rule, CarbonImmutable $current): CarbonImmutable
    {
        $interval = max(1, (int) $rule->interval);

        if ($rule->frequency === 'daily') {
            return $current->addDays($interval);
        }

        if ($rule->frequency === 'monthly') {
            $startsOn = CarbonImmutable::parse($rule->starts_on);
            $monthsSinceStart = (((int) $current->format('Y') - (int) $startsOn->format('Y')) * 12)
                + ((int) $current->format('n') - (int) $startsOn->format('n'));
            $targetMonth = $startsOn->startOfMonth()->addMonthsNoOverflow($monthsSinceStart + $interval);

            return $targetMonth->setDay(min($startsOn->day, $targetMonth->daysInMonth));
        }

        if (empty($rule->weekdays)) {
            return $current->addWeeks($interval);
        }

        $firstWeek = CarbonImmutable::parse($rule->starts_on)->startOfWeek();
        $candidate = $current->addDay();

        for ($day = 0; $day < 370; $day++) {
            $weeksFromStart = intdiv((int) $firstWeek->diffInDays($candidate->startOfWeek()), 7);

            if ($weeksFromStart % $interval === 0 && in_array($candidate->dayOfWeek, $rule->weekdays, true)) {
                return $candidate;
            }

            $candidate = $candidate->addDay();
        }

        return $current->addWeeks($interval);
    }
}
