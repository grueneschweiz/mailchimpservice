<?php

namespace App\Console\Commands;

class EditEndpoint extends EndpointCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'endpoint:edit
                            {id : Endpoint ID}
                            {--config= : The new config file.}
                            {--secret : Generate a new endpoint secret.}';
    
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Edit Mailchimp Endpoint';
    
    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }
    
    /**
     * Change config file and/or secret according to the given options.
     *
     * @return integer
     */
    public function handle()
    {
        $id = (int)$this->argument('id');
        $config = $this->option('config');
        $secret = $this->option('secret');
        
        $endpoint = $this->getEndpointById($id);
        
        if (!$endpoint) {
            return 1;
        }
        
        if (!empty($config)) {
            if ($this->isValidConfig($config)) {
                $endpoint->config = $config;
                $endpoint->save();
                $this->info('Config file changed.');
            } else {
                $this->printConfigErrors();
                
                return 1;
            }
        }
        
        if (!empty($secret)) {
            $endpoint->secret = $this->getNewEndpointSecret();
            $endpoint->save();
            
            $this->info('New endpoint secret generated.');
            $this->line('<comment>Endpoint secret:</comment> ' . $endpoint->secret);
            $this->line('<comment>Endpoint url:</comment> ' . route(self::MC_ENDPOINT_ROUTE_NAME, ['secret' => $endpoint->secret]));
        }
        
        return 0;
    }
}
