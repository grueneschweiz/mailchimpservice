<?php

namespace App\Console\Commands;

class DeleteEndpoint extends EndpointCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'endpoint:delete {id* : List of endpoints separated by a space.}';
    
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete Mailchimp Endpoint';
    
    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }
    
    /** @noinspection PhpDocMissingThrowsInspection */
    /**
     * Execute the console command.
     *
     * @return integer
     */
    public function handle()
    {
        $ids = $this->argument('id');
        
        foreach ($ids as $id) {
            $endpoint = $this->getEndpointById($id);
            if (!$endpoint) {
                if (1 === count($ids)) {
                    return 1;
                } else {
                    continue;
                }
            }
            
            /** @noinspection PhpUnhandledExceptionInspection */
            $endpoint->delete();
            $this->info("<comment>Successfully deleted endpoint:</comment> {$endpoint->id}");
        }
        
        return 0;
    }
}
