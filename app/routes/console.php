<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('oficina:conciliar-pix')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('oficina:reprocessar-pendencias')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
