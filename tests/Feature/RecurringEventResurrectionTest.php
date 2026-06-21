<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Event;
use DateTime;

class RecurringEventResurrectionTest extends TestCase
{
    use RefreshDatabase;

    private function makeWeeklyTemplate() {
        // Start a few days out so the first generated occurrence is in the
        // future and lands inside the scheduler's lookahead window.
        $start = (new DateTime())->modify('+3 days');

        $event = new Event;
        $event->name = 'Weekly Standup';
        $event->key = 'recur-resurrect-test';
        $event->is_template = true;
        $event->recurrence_interval = 'weekly_dow';
        $event->start_date = $start->format('Y-m-d');
        $event->save();

        return [$event, $start->format('Y-m-d')];
    }

    public function testDeletedOccurrenceIsNotResurrected() {
        [$template, $firstDate] = $this->makeWeeklyTemplate();

        $template->create_upcoming_recurrences();

        $generated = Event::where('created_from_template_event_id', $template->id)->count();
        $this->assertGreaterThan(0, $generated, 'Scheduler should have generated instances');

        // A user deletes a single occurrence (soft delete).
        $victim = Event::where('created_from_template_event_id', $template->id)
            ->where('start_date', $firstDate)->firstOrFail();
        $victim->delete();

        // The scheduler runs again (e.g. the daily recurring:schedule command).
        $template->create_upcoming_recurrences();

        // The deleted date must NOT come back as an active event...
        $active = Event::where('created_from_template_event_id', $template->id)
            ->where('start_date', $firstDate)->count();
        $this->assertEquals(0, $active, 'Deleted occurrence was resurrected');

        // ...and it should remain as a single soft-deleted tombstone, not duplicated.
        $tombstones = Event::withTrashed()
            ->where('created_from_template_event_id', $template->id)
            ->where('start_date', $firstDate)->count();
        $this->assertEquals(1, $tombstones, 'Expected exactly one soft-deleted tombstone');
    }

    public function testEditingTemplateStillRegeneratesInstances() {
        [$template, ] = $this->makeWeeklyTemplate();

        $template->create_upcoming_recurrences();
        $before = Event::where('created_from_template_event_id', $template->id)->count();
        $this->assertGreaterThan(0, $before);

        // Editing a template clears the future slate, then regenerates. The
        // clear is a force-delete, so regeneration is not blocked by the
        // withTrashed() existence check.
        $template->delete_upcoming_recurrences();
        $template->create_upcoming_recurrences();

        $after = Event::where('created_from_template_event_id', $template->id)->count();
        $this->assertEquals($before, $after, 'Template edit should regenerate the same instances');
    }
}
