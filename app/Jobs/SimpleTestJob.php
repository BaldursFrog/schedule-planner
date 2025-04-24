<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue; 
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SimpleTestJob implements ShouldQueue 
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

 
    public function __construct()
    {

    }


    public function handle(): void
    {
        Log::info('SimpleTestJob has been processed by the queue worker!');
        echo "Processing job: SimpleTestJob processed successfully!\n"; 
    }
}