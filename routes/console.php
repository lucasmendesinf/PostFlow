<?php

use Illuminate\Support\Facades\Schedule;

// Renova os tokens do Instagram que vencem em menos de 10 dias (fila "publish").
Schedule::command('postflow:instagram-refresh')->dailyAt('03:00')->onOneServer()->withoutOverlapping();
