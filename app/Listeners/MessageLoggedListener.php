<?php

namespace App\Listeners;

use Illuminate\Log\Events\MessageLogged;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

class MessageLoggedListener {
	/**
	 * Create the event listener.
	 *
	 * @return void
	 */
	public function __construct() {
		//
	}

	/**
	 * Handle the event.
	 *
	 * @param MessageLogged $event
	 *
	 * @return void
	 */
	public function handle( MessageLogged $event ) {
		if ( app()->runningInConsole() ) {
			$output = new ConsoleOutput( OutputInterface::VERBOSITY_VERBOSE );
			$output->writeln( "<{$event->level}>{$event->message}</{$event->level}>" );
		}
	}
}
