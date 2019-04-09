<?php


namespace App\Synchronizer;


use App\Exceptions\ConfigException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class Config {

	private $fields;
	private $auth;
	private $mcListId;
	private $mcInteresstCatId;

	/**
	 * Config constructor.
	 *
	 * @param string $configFileName file name of the config file
	 *
	 * @throws ConfigException
	 */
	public function __construct( string $configFileName ) {
		$this->loadConfig( $configFileName );
	}

	/**
	 * Read the config file and populate object
	 *
	 * @param string $configFileName file name of the config file
	 *
	 * @throws ConfigException
	 */
	private function loadConfig( string $configFileName ) {
		$configFolderPath = rtrim( config( 'app.config_base_path' ), '/' );
		$configFilePath   = base_path( $configFolderPath . '/' . $configFileName );

		if ( ! file_exists( $configFilePath ) ) {
			throw new ConfigException( 'The config file was not found.' );
		}

		try {
			$config = Yaml::parseFile( $configFilePath );

			$this->fields           = $config['fields'];
			$this->auth             = $config['auth'];
			$this->mcListId         = $config['mailchimpListId'];
			$this->mcInteresstCatId = $config['mailchimpInteresstCategoriesId'];
		} catch ( ParseException $e ) {
			throw new ConfigException( "YAML parse error: {$e->getMessage()}" );
		}
	}
}