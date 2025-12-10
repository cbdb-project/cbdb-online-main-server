<?php

namespace App\Jobs;

use App\Http\Controllers\WikiMaintenanceController;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class WikiImportJob implements ShouldQueue {
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    protected $taskId;
    protected $url;
    protected $targetSourceId;
    protected $sourceName;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($taskId, $url, $targetSourceId, $sourceName) {
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
    public function handle() {
        $controller = new WikiMaintenanceController();
        $controller->executeImportTask($this->taskId, $this->url, $this->targetSourceId, $this->sourceName);
    }
}
