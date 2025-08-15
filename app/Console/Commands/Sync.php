<?php

namespace App\Console\Commands;

use App\Exceptions\ConfigException;
use App\Exceptions\ParseCrmDataException;
use App\Synchronizer\CrmToMailchimpSynchronizer;
use App\Synchronizer\MailchimpToCrmCronSynchronizer;
use Illuminate\Console\Command;

class Sync extends Command
{
    private const DIRECTION_MAILCHIMP = 'toMailchimp';
    private const DIRECTION_CRM = 'toCrm';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:all
                            {direction : Possible values are "' . self::DIRECTION_CRM . '" and "' . self::DIRECTION_MAILCHIMP . '".}
                            {config : The name of the config file to use.}
                            {--limit=100 : How may records should be synchronized at a time.}
                            {--offset=0 : How many records should be skipped. Usually used in combination with --limit.}
                            {--all : Ignore revision and sync all records, not just changes.}
                            {--force : Ignore locks of previously started (running or dead) sync processes.}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Synchronize all relevant records from the crm to mailchimp and vice versa.';

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
     * Execute the console command.
     *
     * @return integer
     *
     * @throws \Exception
     */
    public function handle()
    {
        switch ($this->argument('direction')) {
            case self::DIRECTION_CRM:
                return $this->syncToCrm();
            case self::DIRECTION_MAILCHIMP:
                return $this->syncToMailchimp();
            default:
                $this->error('Invalid direction argument.');
                $this->info("Possible values are:\n - " . self::DIRECTION_MAILCHIMP . "\n - " . self::DIRECTION_CRM);

                return 1;
        }
    }

    /**
     * Sync changes from mailchimp to the crm
     *
     * @return int
     * 
     * @throws \Exception
     */
    private function syncToCrm()
    {
        $limit = $this->option('limit');
        $offset = $this->option('offset');

        if (!is_numeric($limit) || (int)$limit <= 0) {
            $this->error('The limit option must pass an integer > 0.');

            return 1;
        }

        if (!is_numeric($offset) || (int)$offset < 0) {
            $this->error('The offset option must pass an integer >= 0.');

            return 1;
        }

        try {
            $sync = new MailchimpToCrmCronSynchronizer($this->argument('config'));

            $this->info('Starting Mailchimp to CRM synchronization...');
            $result = $sync->syncAll((int)$limit, (int)$offset);
            $this->info("Synchronization completed: {$result['processed']} processed, {$result['success']} successful, {$result['failed']} failed");

            return 0;
        } catch (\App\Exceptions\ConfigException $e) {
            $this->error('Configuration error: ' . $e->getMessage());
            return 1;
        } catch (\Exception $e) {
            $this->error('Error during synchronization: ' . $e->getMessage());
            return 1;
        }
    }

    /**
     * Sync crm records to mailchimp
     *
     * @return int
     *
     * @throws \Exception
     */
    private function syncToMailchimp()
    {
        $limit = $this->option('limit');
        $offset = $this->option('offset');
        $all = $this->option('all');
        $force = $this->option('force');

        if (!is_numeric($limit) || (int)$limit <= 0) {
            $this->error('The limit option must pass an integer > 0.');

            return 1;
        }

        if (!is_numeric($offset) || (int)$offset < 0) {
            $this->error('The offset option must pass an integer >= 0.');

            return 1;
        }

        try {
            $sync = new CrmToMailchimpSynchronizer($this->argument('config'));

            if ($force) {
                $this->info('Force sync -> removing locks if present.');
                $sync->unlock();
            }

            $this->info('Syncing... please be patient!');

            try {
                $sync->syncAllChanges((int)$limit, (int)$offset, (bool)$all); // this is the relevant line! the rest is error handling...
            } catch (ParseCrmDataException $e) {
                $this->error('ParseCrmDataException: ' . $e->getMessage());
                $this->error($e->getFile() . ' on line ' . $e->getLine() . "\n" . $e->getTraceAsString(), 'v');
            } catch (\Exception $e) {
                $this->error($e->getMessage());
                $this->error($e->getFile() . ' on line ' . $e->getLine() . "\n" . $e->getTraceAsString(), 'v');
            }
        } catch (ConfigException $e) {
            $this->error('ConfigException: ' . $e->getMessage());
            $this->error($e->getFile() . ' on line ' . $e->getLine() . "\n" . $e->getTraceAsString(), 'v');
        }

        return 0;
    }
}
