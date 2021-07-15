<?php

/**
 * @author Yaron Koren
 * @ingroup Cargo
 */
class CargoLeafletFormat extends CargoMapsFormat {

        public function __construct( $output ) {
                parent::__construct( $output );
                self::$mappingService = "Leaflet";
        }

        public static function allowedParameters() {
                $allowedParams = parent::allowedParameters();
                $allowedParams['image'] = [ 'type' => 'string' ];
                return $allowedParams;
        }

        public static function getScripts() {
                $scripts = array();
                array_push($scripts, "https://unpkg.com/leaflet@1.7.1/dist/leaflet.js");
                array_push($scripts, "https://unpkg.com/leaflet.markercluster@1.4.1/dist/leaflet.markercluster.js");
                return $scripts;
        }

        public static function getStyles() {
                $styles = array();
                array_push($styles,"https://unpkg.com/leaflet@1.7.1/dist/leaflet.css");
                array_push($styles,"https://unpkg.com/leaflet.markercluster@1.4.1/dist/MarkerCluster.css");
                array_push($styles,"https://unpkg.com/leaflet.markercluster@1.4.1/dist/MarkerCluster.Default.css");
                return $styles;
        }

}
