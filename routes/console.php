<?php

use Illuminate\Support\Facades\Schedule;

// Renova os tokens do Instagram que vencem em menos de 10 dias (fila "publish").
Schedule::command('postflow:instagram-refresh')->dailyAt('03:00')->onOneServer()->withoutOverlapping();

// Ciclo do PostFlow: ideias e agendas viram posts, gera, expira e publica.
// No cPanel, o cron chama "php artisan schedule:run" a cada minuto.
Schedule::command('postflow:ciclo')->everyMinute()->onOneServer()->withoutOverlapping(15);
