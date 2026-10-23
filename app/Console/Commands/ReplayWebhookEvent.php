<?php

namespace App\Console\Commands;

use App\Jobs\ProcessWebhookEvent;
use App\Models\WebhookEvent;
use Illuminate\Console\Command;

class ReplayWebhookEvent extends Command
{
    protected $signature = 'webhooks:replay {--id= : Only replay this event row id}';

    protected $description = 'Queue failed webhook events again';

    public function handle(): int
    {
        $events = WebhookEvent::where('status', WebhookEvent::FAILED)
            ->when($this->option('id'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        foreach ($events as $event) {
            $event->update(['status' => WebhookEvent::RECEIVED, 'attempts' => 0, 'error' => null]);
            ProcessWebhookEvent::dispatch($event);
        }

        $this->info("Queued {$events->count()} event(s).");

        return self::SUCCESS;
    }
}
