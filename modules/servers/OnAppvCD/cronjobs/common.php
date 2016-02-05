<?php

abstract class OnAppElasticUsers_Cron {
	protected $root;
	protected $clients;
	protected $fromDate;
	protected $tillDate;
	protected $fromDateUTC;
	protected $tillDateUTC;
	protected $timeZoneOffset;
	protected $dueDate;
	protected $cliOptions;
	protected $logEnabled   = false;
	protected $printEnabled = false;
	protected $servers      = array();
	protected $log          = array();

	abstract protected function run();

	public function __construct() {
		$this->checkCLIMode();
		$this->root = realpath( dirname( dirname( dirname( dirname( dirname( $_SERVER[ 'argv' ][ 0 ] ) ) ) ) ) ) . DIRECTORY_SEPARATOR;

		$this->getRequiredFiles();
		$this->setCLIoptions();
		$this->checkSQL();
		$this->getServers();
		$this->getClients();

		if( $this->logEnabled ) {
			$this->log[ 'Run at' ] = date( 'Y-m-d H:i:s' );
		}

		$this->calculateDates();
		$this->run();
	}

	protected function getAmount( array $user ) {
		if( $this->logEnabled ) {
			$tmp = "\n\t\t\t" . 'WHMCS user ID: ' . $user[ 'WHMCSUserID' ] . PHP_EOL;
			$tmp .= "\t\t\t" . 'OnApp user ID: ' . $user[ 'OnAppUserID' ] . PHP_EOL;
			$tmp .= "\t\t\t" . 'Server ID: ' . $user[ 'serverID' ];
			$this->log[ ] = $tmp;
		}

		$date = array(
			'period[startdate]' => $this->fromDateUTC,
			'period[enddate]'   => $this->tillDateUTC,
		);
		$data = $this->getResourcesData( $user, $date );

		if( !$data ) {
			return false;
		}

		$data = $data->user_stat;
		$unset = array(
			'vm_stats',
			'stat_time',
			'user_resources_cost',
			'currency_code',
			'user_id',
		);
		$this->dataTMP = clone $data;
		foreach( $data as $key => &$value ) {
			if( in_array( $key, $unset ) ) {
				unset( $data->$key );
			}
			else {
				$data->$key *= $user[ 'rate' ];
			}
		}

		return $data;
	}

	protected function getResourcesData( $client, $date ) {
		$date = http_build_query( $date );

		$url = $this->servers[ $client[ 'serverID' ] ][ 'address' ] . '/users/' . $client[ 'OnAppUserID' ] . '/user_statistics.json?' . $date;
		$data = $this->sendRequest( $url, $this->servers[ $client[ 'serverID' ] ][ 'username' ], $this->servers[ $client[ 'serverID' ] ][ 'password' ] );

		if( $data ) {
			return json_decode( $data );
		}
		else {
			return false;
		}
	}

	protected function sendRequest( $url, $user, $password ) {
		if( $this->printEnabled ) {
			echo 'API request: ', $url, PHP_EOL;
		}
		if( $this->logEnabled ) {
			$this->log[ ] = $url;
		}

		$curl = new CURL();
		$curl->addOption( CURLOPT_USERPWD, $user . ':' . $password );
		$curl->addOption( CURLOPT_HTTPHEADER, array( 'Accept: application/json', 'Content-type: application/json' ) );
		$curl->addOption( CURLOPT_HEADER, true );
		$data = $curl->get( $url );

		if( $curl->getRequestInfo( 'http_code' ) != 200 ) {
			$e = 'ERROR: ' . PHP_EOL;
			$e .= "\trequest URL:\t\t" . $url . PHP_EOL;
			$e .= "\trequest response:\t" . $curl->getRequestInfo( 'response_body' ) . PHP_EOL;
			if( $this->printEnabled ) {
				echo $e;
			}
			if( $this->logEnabled ) {
				$this->log[ ] = $e;
			}

			return false;
		}
		else {
			return $data;
		}
	}

	protected function getClients() {
		$sql = 'SELECT
					tblclients.taxexempt,
					tblclients.state,
					tblclients.country,
					tblclients.currency,
					tblclients.language,
					tblcurrencies.rate,
					tblhosting.paymentmethod,
					tblhosting.domain,
					tblhosting.id AS service_id,
					`OnAppElasticUsers`.`serverID`,
					`OnAppElasticUsers`.`WHMCSUserID`,
					`OnAppElasticUsers`.`OnAppUserID`,
					`OnAppElasticUsers`.`billingType`,
					tblproducts.tax,
					tblproducts.name AS packagename,
					tblproducts.configoption5 AS dueDate
				FROM
					OnAppElasticUsers
				LEFT JOIN tblhosting ON
					tblhosting.userid = `OnAppElasticUsers`.`WHMCSUserID`
					AND tblhosting.server = `OnAppElasticUsers`.`serverID`
				LEFT JOIN tblproducts ON
					tblhosting.packageid = tblproducts.id
					AND tblproducts.servertype = "OnAppElasticUsers"
				LEFT JOIN tblclients ON
					tblclients.id = `OnAppElasticUsers`.`WHMCSUserID`
				LEFT JOIN tblcurrencies ON
					tblcurrencies.id = tblclients.currency
				WHERE
					tblhosting.domainstatus IN ( "Active", "Suspended" )
					AND `OnAppElasticUsers`.`isTrial` = FALSE
				ORDER BY
					`OnAppElasticUsers`.`OnAppUserID`';
		$this->clients = full_query( $sql );
	}

	protected function getServers() {
		$sql = 'SELECT
					id,
					secure,
					username,
					hostname,
					`password`,
					ipaddress
				FROM
					tblservers
				WHERE
					type = "OnAppElasticUsers"';
		$result = full_query( $sql );
		while( $server = mysql_fetch_assoc( $result ) ) {
			$server[ 'password' ] = decrypt( $server[ 'password' ] );
			if( $server[ 'secure' ] ) {
				$server[ 'address' ] = 'https://';
			}
			else {
				$server[ 'address' ] = 'http://';
			}
			if( empty( $server[ 'ipaddress' ] ) ) {
				$server[ 'address' ] .= $server[ 'hostname' ];
			}
			else {
				$server[ 'address' ] .= $server[ 'ipaddress' ];
			}
			unset( $server[ 'ipaddress' ], $server[ 'hostname' ], $server[ 'secure' ] );
			$this->servers[ $server[ 'id' ] ] = $server;
		}
	}

	protected function generateInvoiceData( $data, array $client ) {
		require_once dirname( __DIR__ ) . '/OnAppElasticUsers.php';
		$module = new OnAppElasticUsersModule;
		$lang = $module->loadLang()->Invoice;

		//check if invoice should be generated
		$fromTime = strtotime( $this->fromDate );
		$tillTime = strtotime( $this->tillDate );
		if( $fromTime >= $tillTime ) {
			return false;
		}

		if( empty( $client[ 'dueDate' ] ) ) {
			$dueDate = $this->dueDate;
		}
		else {
			$dueDate = date( 'Ymd', ( time() + $client[ 'dueDate' ] * 86400 ) );
		}

		# check if the item should be taxed
		$taxed = empty( $client[ 'taxexempt' ] ) && (int)$client[ 'tax' ];
		if( $taxed ) {
			$taxrate = getTaxRate( 1, $client[ 'state' ], $client[ 'country' ] );
			$taxrate = $taxrate[ 'rate' ];
		}
		else {
			$taxrate = '';
		}

		$timeZone = ' UTC';
		if( $this->timeZoneOffset > 0 ) {
			$timeZone .= '+' . $this->timeZoneOffset / 3600;
		}
		elseif( $this->timeZoneOffset < 0 ) {
			$timeZone .= $this->timeZoneOffset / 3600;
		}

		$this->fromDate = date( $lang->DateFormat, $fromTime );
		$this->tillDate = date( $lang->DateFormat, $tillTime );
		$invoiceDescription = array(
			$lang->Product . $client[ 'packagename' ],
			$lang->Period . $this->fromDate . ' - ' . $this->tillDate . $timeZone,
		);
		$invoiceDescription = implode( PHP_EOL, $invoiceDescription );

		$return = array(
			'userid'           => $client[ 'WHMCSUserID' ],
			'date'             => $this->dueDate,
			'duedate'          => $dueDate,
			'paymentmethod'    => $client[ 'paymentmethod' ],
			'taxrate'          => $taxrate,
			'sendinvoice'      => true,
			'itemdescription1' => $invoiceDescription,
			'itemamount1'      => 0,
			'itemtaxed1'       => $taxed,
		);

		unset( $data->total_cost );
		$i = 1;
		foreach( $data as $key => $value ) {
			if( $value > 0 ) {
				$tmp = array(
					'itemdescription' . ++$i => $lang->$key,
					'itemamount' . $i        => $value,
					'itemtaxed' . $i         => $taxed,
				);
				$return = array_merge( $return, $tmp );
			}
		}

		if( $this->printEnabled ) {
			print_r( $return );
		}

		return $return;
	}

	protected function getRequiredFiles() {
		global $whmcsmysql, $cc_encryption_hash, $templates_compiledir, $CONFIG, $_LANG, $whmcs;

		require_once $this->root . 'init.php';
		require_once $this->root . '/modules/servers/OnAppElasticUsers/includes/php/CURL.php';
		require_once $this->root . '/modules/servers/OnAppElasticUsers/includes/php/SOP.php';
		include_once $this->root . 'includes/processinvoices.php';
		include_once $this->root . 'includes/invoicefunctions.php';

		error_reporting( E_ALL ^ E_NOTICE );
		ini_set( 'display_errors', 'On' );
		ini_set( 'memory_limit', '512M' );
		ini_set( 'max_execution_time', 0 );
		set_time_limit( 0 );
	}

	private function checkSQL() {
		// todo rewrite
		if( file_exists( $file = dirname( __DIR__ ) . '/module.sql' ) ) {
			logactivity( 'OnApp User Module: process SQL file, called from cronjob.' );

			$sql = file_get_contents( $file );
			$sql = explode( PHP_EOL . PHP_EOL, $sql );

			foreach( $sql as $qry ) {
				full_query( $qry );
			}
			unlink( $file );
		}
	}

	private function checkCLIMode() {
		if( PHP_SAPI != 'cli' ) {
			if( !empty( $_SERVER[ 'REMOTE_ADDR' ] ) ) {
				$this->log[ 'error' ] = 'Not allowed!';
				exit( 'Not allowed!' . PHP_EOL );
			}
		}
	}

	private function calculateDates() {
		$tmp = time();
		$this->timeZoneOffset = strtotime( date( 'Y-m-d H:i:s', $tmp ) ) - strtotime( gmdate( 'Y-m-d H:i:s', $tmp ) );

		if( isset( $this->cliOptions->since ) ) {
			$fromDate = $this->cliOptions->since;
		}
		else {
			$fromDate = date( 'Y-m-d 00:00', strtotime( 'first day of last month' ) );
		}
		if( isset( $this->cliOptions->till ) ) {
			$tillDate = $this->cliOptions->till;
		}
		else {
			$tillDate = date( 'Y-m-d 00:00', strtotime( 'first day of next month', strtotime( $fromDate ) ) );
		}

		$this->fromDateUTC = $this->getUTCTime( $fromDate, 'Y-m-d H:30' );
		$this->tillDateUTC = $this->getUTCTime( $tillDate, 'Y-m-d H:30' );

		if( $this->logEnabled ) {
			$this->log[ 'UTC time from' ] = $this->fromDateUTC;
			$this->log[ 'UTC time till' ] = $this->tillDateUTC;
		}
		if( $this->printEnabled ) {
			echo 'UTC time from: ', "'", $this->fromDateUTC, "'", PHP_EOL;
			echo 'UTC time till: ', "'", $this->tillDateUTC, "'", PHP_EOL;
		}

		$this->fromDate = $fromDate;
		$this->tillDate = $tillDate;
	}

	protected function getUTCTime( $date, $format = 'Y-m-d H:i' ) {
		return gmdate( $format, strtotime( $date ) );
	}

	private function setCLIOptions() {
		$options = array(
			'since' => array(
				'description' => 'date to start',
				'validation'  => '^(20\d{2})-([0][1-9]|[1][0-2])-([\d]{2}) ([0-1][0-9]|[2][0-3]):([0-5][0-9])$',
				'short'       => 's',
			),
			'till'  => array(
				'description' => 'date to finish',
				'validation'  => '^(20\d{2})-([0][1-9]|[1][0-2])-([\d]{2}) ([0-1][0-9]|[2][0-3]):([0-5][0-9])$',
				'short'       => 't',
			),
			'log'   => array(
				'description' => 'log data to file',
				'short'       => 'l',
			),
			'print' => array(
				'description' => 'print data to screen',
				'short'       => 'p',
			),
		);

		$options = new SOP( $options );
		$options->setBanner( 'OnApp User Module detailed statistics and invoices processor' );
		$this->cliOptions = $options->parse();

		if( isset( $this->cliOptions->log ) ) {
			$this->logEnabled = true;
		}
		if( isset( $this->cliOptions->print ) ) {
			$this->printEnabled = true;
		}
	}

	private function writeLog() {
		$c = get_called_class();
		$logFile = __DIR__ . '/logs/' . $c::TYPE . '/' . date( 'Y-m-d-H-i-s' );

		if( !( file_exists( $logFile ) && is_writable( $logFile ) ) ) {
			if( !$log = @fopen( $logFile, 'w' ) ) {
				exit( 'Can\'t write log file. Check if file ' . $logFile . ' exists and is writable!' . PHP_EOL );
			}
			else {
				fclose( $log );
			}
		}

		$log = print_r( $this->log, true );

		file_put_contents( $logFile, $log );
		echo PHP_EOL, ' Log has been saved in ' . $logFile, PHP_EOL;
	}

	public function __destruct() {
		if( $this->logEnabled ) {
			$this->writeLog();
		}
	}
}