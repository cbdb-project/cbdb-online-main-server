<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Http\Controllers\WikiMaintenanceController;

class WikiImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $taskId;
    protected $url;
    protected $targetSourceId;
    protected $sourceName;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($taskId, $url, $targetSourceId, $sourceName)
    {
        $this->taskId = $taskId;
        $this->url = $url;
        $this->targetSourceId = $targetSourceId;
        $this->sourceName = $sourceName;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $controller = new WikiMaintenanceController();
        $controller->executeImportTask($this->taskId, $this->url, $this->targetSourceId, $this->sourceName);
    }
}
