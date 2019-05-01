<?php


namespace App\Synchronizer;


use App\Exceptions\ConfigException;
use App\Synchronizer\Mapper\FieldMapFacade;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class Config {

	private $fields;
	private $auth;
	private $dataOwner;
	private $mailchimp;

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
		} catch ( ParseException $e ) {
			throw new ConfigException( "YAML parse error: {$e->getMessage()}" );
		}

		// prevalidate config
		if ( $config['auth'] ) {
			$this->auth = $config['auth'];
		} else {
			throw new ConfigException( "Missing 'auth' section." );
		}

		if ( $config['dataOwner'] ) {
			$this->dataOwner = $config['dataOwner'];
		} else {
			throw new ConfigException( "Missing 'field' section." );
		}

		if ( $config['mailchimp'] ) {
			$this->mailchimp = $config['mailchimp'];
		} else {
			throw new ConfigException( "Missing 'mailchimp' section." );
		}

		if ( $config['fields'] ) {
			$this->fields = $config['fields'];
		} else {
			throw new ConfigException( "Missing 'field' section." );
		}
	}

	/**
	 * Return array with crm credentials
	 *
	 * @return array {clientId: string, clientSecret: string, url: string}
	 *
	 * @throws ConfigException
	 */
	public function getCrmCredentials(): array {
		if ( empty( $this->auth['crm'] )
		     || empty( $this->auth['crm']['clientId'] )
		     || empty( $this->auth['crm']['clientSecret'] )
		     || empty( $this->auth['crm']['url'] )
		) {
			throw new ConfigException( "Missing CRM credentials." );
		}

		return $this->auth['crm'];
	}

	/**
	 * Return array with mailchimp credentials
	 *
	 * @return array {apikey: string, url: string}
	 *
	 * @throws ConfigException
	 */
	public function getMailchimpCredentials(): array {
		if ( empty( $this->auth['mailchimp'] )
		     || empty( $this->auth['mailchimp']['apikey'] )
		) {
			throw new ConfigException( "Missing Mailchimp credentials." );
		}

		return $this->auth['mailchimp'];
	}

	/**
	 * Return array with name and email of data owner
	 *
	 * @return array {email: string, name: string,}
	 *
	 * @throws ConfigException
	 */
	public function getDataOwner(): array {
		if ( empty( $this->dataOwner )
		     || empty( $this->dataOwner['email'] )
		     || empty( $this->dataOwner['name'] )
		) {
			throw new ConfigException( "Missing CRM credentials." );
		}

		return $this->dataOwner;
	}

	/**
	 * Return array with the field maps
	 *
	 * @return FieldMapFacade[]
	 *
	 * @throws ConfigException
	 */
	public function getFieldMaps(): array {
		if ( ! is_array( $this->fields ) ) {
			throw new ConfigException( "Fields configuration must be an array." );
		}

		$fields = [];
		foreach ( $this->fields as $config ) {
			$fields[] = new FieldMapFacade( $config );
		}

		return $fields;
	}

	/**
	 * Return bool that indicates if all records should be synced even if they dont have
	 * relevant subscriptions
	 *
	 * @return bool
	 */
	public function getSyncAll(): bool {
		if ( array_key_exists( 'syncAll', $this->mailchimp ) ) {
			return filter_var( $this->mailchimp['syncAll'], FILTER_VALIDATE_BOOLEAN );
		}

		return false;
	}

	/**
	 * Return the default list id in Mailchimp
	 *
	 * @return string the list id
	 *
	 * @throws ConfigException
	 */
	public function getMailchimpListId(): string {
		if ( empty( $this->mailchimp['listId'] ) ) {
			throw new ConfigException( "Missing mailchimp list id." );
		}

		return $this->mailchimp['listId'];
	}

	/**
	 * The the mailchimp merge field key that corresponds to the crm's id
	 *
	 * @return string
	 * @throws ConfigException
	 */
	public function getMailchimpKeyOfCrmId(): string {
		foreach ( $this->getFieldMaps() as $map ) {
			if ( 'id' === $map->getCrmKey() ) {
				$keys = array_keys( $map->getMailchimpDataArray() );

				return reset( $keys );
			}
		}

		throw new ConfigException( 'Missing "id" field.' );
	}
}