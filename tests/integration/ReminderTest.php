<?php

namespace Tests;

use App\CalendarEvent;
use App\Group;
use App\Notifications\UpcomingCalendarEvent;
use App\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/**
 * Event reminders, whether the reminder command runs every 5 minutes or once an hour.
 */
class ReminderTest extends BrowserKitTestCase
{
    private Carbon $start;

    protected function setUp(): void
    {
        parent::setUp();

        $this->start = Carbon::parse('2026-11-14 10:00:00');
        Notification::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * An event starting at this time, with one participant who wants a reminder this many minutes ahead.
     */
    private function eventWithParticipant(Carbon $start, int $minutesAhead): User
    {
        $suffix = bin2hex(random_bytes(4));
        $user = User::create(['name' => 'Participant ' . $suffix, 'email' => $suffix . '@agorakit.local', 'password' => bcrypt('x')]);
        $group = new Group();
        $group->name = 'Group ' . $suffix;
        $group->body = 'Events';
        $group->save();

        $event = new CalendarEvent(['name' => 'Meeting ' . $suffix, 'body' => 'Agenda', 'start' => $start, 'stop' => $start->copy()->addHour()]);
        $event->user()->associate($user);
        $event->group()->associate($group);
        $event->save();
        $event->participation()->attach($user->id, ['status' => 10, 'notification' => $minutesAhead]);

        return $user;
    }

    private function runAt(Carbon $time): void
    {
        Carbon::setTestNow($time);
        Artisan::call('agorakit:sendreminders');
    }

    public function testEveryFiveMinutesSendsTheHourReminderOnce()
    {
        $user = $this->eventWithParticipant($this->start->copy()->addMinutes(62), 60);

        foreach ([0, 5, 10, 15] as $minutes) {
            $this->runAt($this->start->copy()->addMinutes($minutes));
        }

        Notification::assertSentToTimes($user, UpcomingCalendarEvent::class, 1);
    }

    public function testOnceAnHourStillSendsTheHourReminderOnce()
    {
        $user = $this->eventWithParticipant($this->start->copy()->addMinutes(97), 60);

        foreach ([0, 60, 120, 180] as $minutes) {
            $this->runAt($this->start->copy()->addMinutes($minutes));
        }

        Notification::assertSentToTimes($user, UpcomingCalendarEvent::class, 1);
    }

    public function testOnceAnHourStillSendsTheDayReminderOnce()
    {
        $user = $this->eventWithParticipant($this->start->copy()->addDay()->addMinutes(97), 60 * 24);

        foreach ([0, 60, 120, 180] as $minutes) {
            $this->runAt($this->start->copy()->addMinutes($minutes));
        }

        Notification::assertSentToTimes($user, UpcomingCalendarEvent::class, 1);
    }

    public function testParticipantGetsOnlyTheReminderTheyAskedFor()
    {
        $user = $this->eventWithParticipant($this->start->copy()->addMinutes(62), 60 * 24);

        $this->runAt($this->start);
        $this->runAt($this->start->copy()->addMinutes(5));

        Notification::assertNotSentTo($user, UpcomingCalendarEvent::class);
    }

    public function testEventAlreadyStartedIsNotReminded()
    {
        // the previous run was long ago, for instance before a server outage
        Cache::forever('agorakit:sendreminders:last-run', $this->start->copy()->subHours(3)->getTimestamp());
        $user = $this->eventWithParticipant($this->start->copy()->subMinutes(10), 60);

        $this->runAt($this->start);

        Notification::assertNotSentTo($user, UpcomingCalendarEvent::class);
    }
}
