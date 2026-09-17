<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('fast-landings:check-domains')->everyMinute()->withoutOverlapping();
Schedule::command('fast-landings:prune-staging')->hourly()->withoutOverlapping();
Schedule::command('fast-landings:previews')->everyMinute()->withoutOverlapping();
