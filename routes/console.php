<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('ipamferry:prune-dumps')->daily();
