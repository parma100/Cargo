<?php
/**
 * Displays the results of a Cargo query in one of several possible
 * structured data formats - in some cases for use by an Ajax-based
 * display format.
 *
 * @author Yaron Koren
 * @ingroup Cargo
 */

class CargoExport extends UnlistedSpecialPage {

	/**
	 * Constructor
	 */
	function __construct() {
		parent::__construct( 'CargoExport' );
	}

	function execute( $query ) {
		$this->getOutput()->setArticleBodyOnly( true );

		$req = $this->getRequest();
		$tableArray = $req->getArray( 'tables' );
		$fieldsArray = $req->getArray( 'fields' );
		$whereArray = $req->getArray( 'where' );
		$joinOnArray = $req->getArray( 'join_on' );
		$groupByArray = $req->getArray( 'group_by' );
		$orderByArray = $req->getArray( 'order_by' );
		$limitArray = $req->getArray( 'limit' );

		$sqlQueries = array();
		foreach ( $tableArray as $i => $table ) {
			$sqlQueries[] = CargoSQLQuery::newFromValues(
					$table, $fieldsArray[$i], $whereArray[$i], $joinOnArray[$i], $groupByArray[$i],
					$orderByArray[$i], $limitArray[$i] );
		}

		$format = $req->getVal( 'format' );

		if ( $format == 'fullcalendar' ) {
			$this->displayCalendarData( $sqlQueries );
		} elseif ( $format == 'timeline' ) {
			$this->displayTimelineData( $sqlQueries );
		} elseif ( $format == 'nvd3chart' ) {
			$this->displayNVD3ChartData( $sqlQueries );
		} elseif ( $format == 'csv' ) {
			$delimiter = $req->getVal( 'delimiter' );
			if ( $delimiter == '' ) {
				$delimiter = ',';
			} elseif ( $delimiter == '\t' ) {
				$delimiter = "\t";
			}
			$filename = $req->getVal( 'filename' );
			if ( $filename == '' ) {
				$filename = 'results.csv';
			}
			$this->displayCSVData( $sqlQueries, $delimiter, $filename );
		} elseif ( $format == 'excel' ) {
			$filename = $req->getVal( 'filename' );
			if ( $filename == '' ) {
				$filename = 'results.xls';
			}
			$this->displayExcelData( $sqlQueries, $filename );
		} elseif ( $format == 'json' ) {
			$this->displayJSONData( $sqlQueries );
		}
	}

	/**
	 * Used for calendar format
	 */
	function displayCalendarData( $sqlQueries ) {
		$req = $this->getRequest();

		$colorArray = $req->getArray( 'color' );

		$startDate = $req->getVal( 'start' );
		$endDate = $req->getVal( 'end' );

		$displayedArray = array();
		foreach ( $sqlQueries as $i => $sqlQuery ) {
			$dateFieldRealNames = array();
			$dateFieldAliases = array();
			foreach( $sqlQuery->mFieldDescriptions as $alias => $description ) {
				if ( $description->mType == 'Date' || $description->mType == 'Datetime' ) {
					$dateFieldAliases[] = $alias;
					$realFieldName = $sqlQuery->mAliasedFieldNames[$alias];
					$dateFieldRealNames[] = $realFieldName;
				}
			}

			$where = $sqlQuery->mWhereStr;
			if ( $where != '' ) {
				$where .= " AND ";
			}
			$where .= "(";
			foreach ( $dateFieldRealNames as $j => $dateField ) {
				if ( $j > 0 ) {
					$where .= " OR ";
				}
				$where .= "($dateField >= '$startDate' AND $dateField <= '$endDate')";
			}
			$where .= ")";
			$sqlQuery->mWhereStr = $where;

			$queryResults = $sqlQuery->run();

			foreach ( $queryResults as $queryResult ) {
				if ( array_key_exists( 'name', $queryResult ) ) {
					$eventTitle = $queryResult['name'];
				} else {
					$eventTitle = reset( $queryResult );
				}
				$title = Title::newFromText( $queryResult['_pageName'] );
				$displayedArray[] = array(
					// Get first field for the title - not
					// necessarily the page name.
					'title' => $eventTitle,
					'url' => $title->getLocalURL(),
					'start' => $queryResult[$dateFieldAliases[0]],
					'color' => $colorArray[$i]
				);
			}
		}

		print json_encode( $displayedArray );
	}

	/**
	 * Used by displayTimelineData().
	 */
	function timelineDatesCmp( $a, $b ) {
		if ( $a['start'] == $b['start'] ) {
			return 0;
		}
		return ( $a['start'] < $b['start'] ) ? -1 : 1;
	}

	function displayTimelineData( $sqlQueries ) {
		$displayedArray = array();
		foreach ( $sqlQueries as $i => $sqlQuery ) {
			$dateFields = array();
			foreach( $sqlQuery->mFieldDescriptions as $field => $description ) {
				if ( $description->mType == 'Date' || $description->mType == 'Datetime' ) {
					$dateFields[] = $field;
				}
			}

			$queryResults = $sqlQuery->run();

			foreach ( $queryResults as $queryResult ) {
				$title = Title::newFromText( $queryResult['_pageName'] );
				$eventDescription = '';
				$firstField = true;
				foreach ( $sqlQuery->mFieldDescriptions as $fieldName => $fieldDescription ) {
					// Don't display the first field (it'll
					// be the title), or date fields.
					if ( $firstField ) {
						$firstField = false;
						continue;
					}
					if ( in_array( $fieldName, $dateFields ) ) {
						continue;
					}
					if ( !array_key_exists( $fieldName, $queryResult ) ) {
						continue;
					}
					$fieldValue = $queryResult[$fieldName];
					if ( trim( $fieldValue ) == '' ) {
						continue;
					}
					$eventDescription .= "<strong>$fieldName:</strong> $fieldValue<br />\n";
				}

				$displayedArray[] = array(
					// Get first field for the 'title' - not
					// necessarily the page name.
					'title' => reset( $queryResult ),
					'start' => $queryResult[$dateFields[0]],
					'description' => $eventDescription,
					'link' => $title->getFullURL(),
				);
			}
		}
		// Sort by date, ascending.
		usort( $displayedArray, 'self::timelineDatesCmp' );

		$displayedArray = array( 'events' => $displayedArray );
		print json_encode( $displayedArray, JSON_HEX_TAG | JSON_HEX_QUOT );
	}

	function displayNVD3ChartData( $sqlQueries ) {
		$req = $this->getRequest();

		// We'll only use the first query, if there's more than one.
		$sqlQuery = $sqlQueries[0];
		$queryResults = $sqlQuery->run();

		// @TODO - this array needs to be longer.
		$colorsArray = array( '#60BD68', '#FAA43A', '#5DA6DA', '#CC333F' );

		// Initialize everything, using the field names.
		$firstRow = reset( $queryResults );
		$displayedArray = array();
		$labelNames = array();
		$fieldNum = 0;
		foreach( $firstRow as $fieldName => $value ) {
			if ( $fieldNum == 0 ) {
				$labelNames[] = $value;
			} else {
				$curSeries = array(
					'key' => $fieldName,
					'color' => $colorsArray[$fieldNum - 1],
					'values' => array()
				);
				$displayedArray[] = $curSeries;
			}
			$fieldNum++;
		}

		foreach ( $queryResults as $i => $queryResult ) {
			$fieldNum = 0;
			foreach ( $queryResult as $fieldName => $value ) {
				if ( $fieldNum == 0 ) {
					$labelName = $value;
				} else {
					$displayedArray[$fieldNum - 1]['values'][] = array(
						'label' => $labelName,
						'value' => $value
					);
				}
				$fieldNum++;
			}
		}

		print json_encode( $displayedArray, JSON_NUMERIC_CHECK | JSON_HEX_TAG );
	}

	function displayCSVData( $sqlQueries, $delimiter, $filename ) {
		header( "Content-Type: text/csv" );
		header( "Content-Disposition: attachment; filename=$filename" );

		// We'll only use the first query, if there's more than one.
		$sqlQuery = $sqlQueries[0];
		$queryResults = $sqlQuery->run();
		$out = fopen('php://output', 'w');
		// Display header row.
		fputcsv( $out, array_keys( reset( $queryResults ) ), $delimiter );
		foreach( $queryResults as $queryResult ) {
			fputcsv( $out, $queryResult, $delimiter );
		}
		fclose( $out );
	}

	function displayExcelData( $sqlQueries, $filename ) {

		// We'll only use the first query, if there's more than one.
		$sqlQuery = $sqlQueries[0];
		$queryResults = $sqlQuery->run();

		$file = new PHPExcel();
		$file->setActiveSheetIndex(0);

		// Create array with header row and query results.
		$header[] = array_keys( reset( $queryResults ) );
		$rows = array_merge($header, $queryResults);

		$file->getActiveSheet()->fromArray($rows, null, 'A1');
		header("Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
		header("Content-Disposition: attachment;filename=$filename");
		header("Cache-Control: max-age=0");

		$writer = PHPExcel_IOFactory::createWriter($file, 'Excel5');

		$writer->save('php://output');
	}

	function displayJSONData( $sqlQueries ) {
		header( "Content-Type: application/json" );

		$allQueryResults = array();
		foreach ( $sqlQueries as $sqlQuery ) {
			$queryResults = $sqlQuery->run();
			$allQueryResults = array_merge( $allQueryResults, $queryResults );
		}
		print json_encode( $allQueryResults, JSON_NUMERIC_CHECK | JSON_HEX_TAG | JSON_PRETTY_PRINT );
	}
}
