<?php

namespace App\Console\Commands;

use App\Group;
use App\CalendarEvent;
use App\Notifications\UpcomingCalendarEvent;
use App\User;
use Carbon\Carbon;
use Notification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SendReminders extends Command
{
    /**
    *  The name and signature of the console command.
    *
    * @var string
    */
    protected $signature = 'agorakit:sendreminders {--batch=1000}';

    /**
    * The console command description.
    *
    * @var string
    */
    protected $description = 'Sends all reminders to participants who asked for it, for the events starting in one hour or one day';

    /**
     * When the previous run happened, so that the next one starts where it ended.
     */
    private const LAST_RUN = 'agorakit:sendreminders:last-run';

    /**
     * How far back a run looks at most, when the previous one is long ago or unknown.
     */
    private const MAX_LOOKBACK_MINUTES = 120;

    /**
    * Create a new command instance.
    *
    * @return void
    */
    public function __construct()
    {
        parent::__construct();
    }

    /**
    * Execute the console command.
    *
    * @return mixed
    */
    public function handle()
    {
        // Each run covers the time since the previous one: every 5 minutes as
        // the scheduler asks, or once an hour as some shared hosts allow, no
        // event is skipped and none is reminded twice.
        $now = Carbon::now();
        $previous = Cache::get(self::LAST_RUN);
        $since = $previous
            // in the time zone of the application, like the start of the events it is compared with
            ? Carbon::createFromTimestamp($previous, $now->getTimezone())->max($now->copy()->subMinutes(self::MAX_LOOKBACK_MINUTES))
            : $now->copy()->subMinutes(5);
        Cache::forever(self::LAST_RUN, $now->getTimestamp());

        // events that start in one hour; never one that has started already
        $this->remind($since->copy()->addHour()->max($now), $now->copy()->addHour(), 60);

        // events that start in one day
        $this->remind($since->copy()->addDay(), $now->copy()->addDay(), 60 * 24);
    }

    /**
     * Notifies the participants who asked for a reminder this many minutes
     * ahead, for the events starting after $from and until $until.
     */
    private function remind(Carbon $from, Carbon $until, int $minutesAhead): void
    {
        $events = CalendarEvent::where('start', '>', $from)->where('start', '<=', $until)->get();

        foreach ($events as $event) {
            $users = $event->attending()->where('notification', $minutesAhead)->get();
            Notification::send($users, new UpcomingCalendarEvent($event));
            if ($users->count() > 0) {
                $this->info($users->count() . ' users notified for ' . $event->name);
            }
        }
    }
}
