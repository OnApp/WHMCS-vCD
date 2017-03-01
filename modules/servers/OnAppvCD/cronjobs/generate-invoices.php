<?php

require __DIR__ . '/common.php';

class OnAppvCD_Cron_Invoices extends OnAppvCD_Cron {
	const TYPE = 'invoices';

	protected function run() {
		$this->process();
	}

	private function process() {
		# get admin
		$qry = 'SELECT
					`username`
				FROM
					`tbladmins`
                WHERE
                    disabled = 0
				LIMIT 1';
		$res = mysql_query( $qry );
		//$admin = mysql_result( $res, 0 );
        $adminArr = mysql_fetch_assoc( $res );
        $admin = $adminArr['username'];

        # calculate invoice due date
		$this->dueDate = date( 'Ymd' );

		while( $client = mysql_fetch_assoc( $this->clients ) ) {
			if( $client[ 'billingType' ] != 'postpaid' ) {
				continue;
			}

			$clientAmount = $this->getAmount( $client );
			if( !$clientAmount ) {
				continue;
			}


			if( $clientAmount->total_cost > 0 ) {
				$data = $this->generateInvoiceData( $clientAmount, $client );
				if( $data == false ) {
					continue;
				}

				if( $this->logEnabled ) {
					$this->log[ ] = $data;
				}

				$result = localAPI( 'CreateInvoice', $data, $admin );
				if( $result[ 'result' ] != 'success' ) {
					if( $this->printEnabled ) {
						echo 'An Error occurred trying to create a invoice: ', $result[ 'result' ], PHP_EOL;
						print_r( $result, true );
					}
					if( $this->logEnabled ) {
						$this->log[ ] = 'An Error occurred trying to create a invoice: ' . $result[ 'result' ];
						$this->log[ ] = print_r( $result, true );
					}
					logactivity( 'An Error occurred trying to create a invoice: ' . $result[ 'result' ] );
				}
				else {
					if( $this->printEnabled ) {
						print_r( $result );
						echo PHP_EOL, PHP_EOL;
					}
					if( $this->logEnabled ) {
						$this->log[ ] = print_r( $result, true );
						$this->log[ ] = '========== SPLIT =============';
					}
					$qry = 'UPDATE
								`tblinvoiceitems`
							SET
								`relid` = :WHMCSServiceID,
								`type` = "OnAppvCD"
							WHERE
								`invoiceid` = :invoiceID';
					$qry = str_replace( ':WHMCSServiceID', $client[ 'service_id' ], $qry );
					$qry = str_replace( ':invoiceID', $result[ 'invoiceid' ], $qry );
					full_query( $qry );

					# save OnApp amount
					$table  = 'OnAppvCD_Cache';
					$values = [
						'itemID' => $result[ 'invoiceid' ],
						'type'   => 'invoiceData',
						'data'   => $this->dataTMP->total_cost
					];
					insert_query( $table, $values );
				}
			}
		}
	}
}
new OnAppvCD_Cron_Invoices;