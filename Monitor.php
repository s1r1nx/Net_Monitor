<?php
// +----------------------------------------------------------------------+
// | PHP Version 4                                                        |
// +----------------------------------------------------------------------+
// | Copyright (c) 1997-2004 The PHP Group                                |
// +----------------------------------------------------------------------+
// | This source file is subject to version 3.0 of the PHP license,       |
// | that is bundled with this package in the file LICENSE, and is        |
// | available at through the world-wide-web at                           |
// | http://www.php.net/license/3_0.txt.                                  |
// | If you did not receive a copy of the PHP license and are unable to   |
// | obtain it through the world-wide-web, please send a note to          |
// | license@php.net so we can mail you a copy immediately.               |
// +----------------------------------------------------------------------+
// | Author: Robert Peake <robert@peakepro.com>                           |
// +----------------------------------------------------------------------+
//
// $Id$
//
// Remote service monitor
/**
 * Net_Monitor
 *
 * A unified interface for checking the availability services on external 
 * servers and sending meaningful alerts through a variety of media if a 
 * service becomes unavailable.
 *
 * @package Net_Monitor
 * @author Robert Peake <robert@peakepro.com>
 * @copyright 2004
 * @license http://www.php.net/license/3_0.txt
 * @version 0.2.0
 * 
 */
/**
 * Requires the main Pear class
 */
require_once 'PEAR.php';

/**  
 * class Net_Monitor
 *
 * @access public
 * @package Net_Monitor
 */
class Net_Monitor 
{
    /**
     * Array of services to check
     *
     * @access private
     * @var array $_services
     */
    var $_services = array();
    /**
     * Array of alerts to be sent organized by protocols
     *
     * @access private
     * @var array $_alerts
     */
    var $_alerts = array();
    /**
     * Array of options to be used in the current monitoring session
     *
     * Options are:
     * <ul>
     * <li> state_directory - the directory where the state file gets saved
     * <li> state_file - the name of the state file
     * <li> subject_line - the subject line of the alert message
     * <li> alert_line - the format string for the alert where:
     *     <ul>
     *     <li>%h = host
     *     <li>%s = service
     *     <li>%m = message
     *     <li>%c = code
     *     </ul>
     * <li> notify_change - send alerts only on state change
     * <li> notify_ok - send an alert when a service returns to a code 200 state
     * <li> smtp_debug - send debugging output to STDOUT for the SMTP alert
     *
     * @access private
     * @var array $_options
     */
    var $_options = array('state_directory' => '/tmp/',
                          'state_file'      => 'Net_Monitor_State',
                          'subject_line'    => 'Net_Monitor Alert',
                          'alert_line'      => '%h: %s: %m',
                          'notify_change'   => 1,
                          'notify_ok'       => 1,
                          'smtp_debug'      => FALSE
                         );
    /**
     * Array of client objects to be used when testing a service
     *
     * @access private
     * @var array $_clients
     */
    var $_clients = array();
    /**
     * Array of alerter objects to be used when sending alerts
     *
     * @access private
     * @var array $_alerters
     */
    var $_alerters = array();
    /**
     * Array of results from most recent service check
     *
     * @access private
     * @var array $_results
     */
    var $_results = array();
    /**
     * Array of differences in results between previous session and this session
     *
     * @access private
     * @var array $_results_diff
     */
    var $_results_diff = array();

    /** 
     * function Net_Monitor
     *
     * @access public
     * @param array $services
     * @param array $alerts
     * @return void
     */
    function Net_Monitor($services=array(),$alerts=array(),$options=array()) 

    {
        if (is_array($options) && sizeof($options) > 0) {
            $this->setOptions($options);
        }
        if (is_array($services) && sizeof($services) > 0) {
            $this->setServices($services);
        }
        if (is_array($alerts) && sizeof($alerts) > 0) {
            $this->setAlerts($alerts);
        }
    }
    /** 
     * function setOptions
     *
     * <p>Sets additional options for the class</p>
     * <p>Merges input array ($options) with $this->_options</p>
     * 
     * @access public
     * @param array $options
     * @return void
     */
    function setOptions($options) 

    {
        foreach ($options as $key => $value) {
            $this->_options[$key] = $value;
        }
    }
    /** 
     * function setServices
     *
     * <p>Sets the services to monitor for the class</p>
     * <p>Overwrites $_services with input array ($services)</p>
     * <p>Net_Monitor_Services are of the form: <br />
     * <pre>
     * $services = array('foo.example.com'=>array('SMTP','DNS'),
     *                   'bar.example.com'=>array('HTTP','FTP','DNS'));
     * </pre>
     * 
     * @access public
     * @param array $services
     * @return void
     */
    function setServices($services) 

    {
        $this->_services = $services;
    }
    /** 
     * function setAlerts
     *
     * <p>Sets the alerts for the class</p>
     * <p>Overwrites $_alerts with input array ($alerts)</p>
     * <p>Net_Monitor_Alerts are of the form: <br />
     * <pre>
     * $alerts = array('User1' => array('SMTP'=>'user1@example.com'),
     *                 'User2' => array('SMTP'=>'user2@example.com'));
     * </pre>
     *
     * @param array $alerts
     * @return void
     */
    function setAlerts($alerts) 

    {
        foreach ($alerts as $user => $parAlert) {
            foreach ($parAlert as $proto => $param) {
                if (!isset($this->_alerts[$proto])) {
                    $this->_alerts[$proto] = array();
                }
                $this->_alerts[$proto][$user] = $param;
            }
        }
    }
    /** 
     * function checkAll
     *
     * Checks all services and sends all alerts. 
     *
     * @access public
     * @return void
     */
    function checkAll() 

    {
        //initialize the _results and _results_diff arrays
        $this->_results = array();
        $this->_results_diff = array();
        //check all services and populate the _results array
        if (is_array($this->_services) && sizeof($this->_services) > 0) {
            $this->loadClients(); //load client objects once and only once per service
            foreach ($this->_services as $server => $services) {
                foreach ($services as $service) {
                    $result = $this->check($server,$service);
                    if ($result) {
                        $this->_results[$server][$service] = $result;
                    }
                }
            }
        } else {
            pear::raiseError('No services found to check.');
        }
        if (is_array($this->_results) && sizeof($this->_results) > 0) {
            $last_state = $this->getState();
            $this->saveState();
            $this->_results_diff = $this->stateDiff($last_state);
            /* UNCOMMENT THE FOLLOWING TO DEBUG DIFFERENTIALS
            //  print "Last state: \n\n";
            //  print_r($last_state);
            //  print "Current state: \n\n";
            //  print_r($this->_results);
            //  print "Difference: \n\n";
            //  print_r($this->_results_diff);
            */
            
        }
        if (is_array($this->_results_diff) && sizeof($this->_results_diff) > 0) {
            if (is_array($this->_alerts) && sizeof($this->_alerts) > 0) {
                $this->loadAlerters();
                //loop through alerts, sending the result message
                foreach ($this->_alerts as $method => $alert_array) {
                    $this->alert($method, $alert_array);
                }
            } else {
                //nobody to alert? print the result to STDOUT
                foreach ($this->_results_diff as $host=>$results) {
                    foreach ($results as $service=>$result) {
                        $this->printAlert($host, $service, $result);
                    }
                }
            }
            return true;
        } else {
            return false;
        }
    }
    /** 
     * function check
     *
     * Check a single service ($service) on a single server ($server)
     *
     * @access public
     * @param mixed $server
     * @param string $service
     * @return mixed 
     */
    function check($server,$service) 

    {
        $client =& $this->_clients[$service];
        return $client->check($server);
    }
    /** 
     * function loadClients
     *
     * Load all clients into the Net_Monitor object so that
     * each type of service client is only instantiated once.
     *
     * @access public
     * @return void
     */
    function loadClients() 

    {
        $services_array = array_values($this->_services);
        $types_array = array_keys($this->_clients);
        for ($i=0;$i<sizeof($services_array);$i++) {
            $sub_array = $services_array[$i];
            for ($j=0; $j<sizeof($sub_array); $j++) {
                $type = $sub_array[$j];
                if (!in_array($type,$types_array)) {
                    $types_array[] = $type;
                    $this->_clients[$type] =& $this->getClient($type);
                }
            }
        }
    }
    /** 
     * function loadAlerters
     *
     * Load alert objects into Net_Monitor so that each type of alert
     * is only used once.
     *
     * @access public
     * @return void
     */
    function loadAlerters() 

    {
        $alerts_array = array_keys($this->_alerts);
        foreach ($alerts_array as $alert_type) {
            if (!isset($this->_alerters[$alert_type])) {
                $this->_alerters[$alert_type] =
                    & $this->getAlerter($alert_type);
            }
        }
    }
    /** 
     * function getClient
     *
     * Returns a client of the type specified in $type. <em>Note:
     * does not check to see if this client has already been
     * loaded into Net_Monitor::clients - that is handled elsewhere.</em>
     *
     * @access private
     * @param string $type
     * @return object
     */
    function &getClient($type) 

    {
        require_once "Net/Monitor/Service/$type.php";
        $service = "Net_Monitor_Service_$type";
        return new $service();
    }
    /** 
     * function getAlerter
     *
     * Returns an alerter of the type specified by $type. <em>Note:
     * does not check to see if this alerter has already been
     * loaded into Net_Monitor::alerters - that is handled elsewhere.</em>
     *
     * @access private
     * @param string $type
     * @return object
     *
     */
    function &getAlerter($type) 

    {
        require_once "Net/Monitor/Alert/$type.php";
        $alerter = "Net_Monitor_Alert_$type";
        return new $alerter();
    }
    /** 
     * function alert
     *
     * Send a single alert specified in $method to the server specified in $server
     *
     * @access private
     * @param string $method
     * @param mixed $server
     *
     */
    function alert($method,$server) 

    {
        $alerter =& $this->_alerters[$method];
        return $alerter->alert($server,$this->_results_diff,$this->_options);
    }
    /** 
     * function saveState
     *
     * Saves the current $_results array to the directory specified in
     * $_options['state_directory'] as a file named $_options['state_file'].
     *
     * If an array ($results) is passed to the function, that array is saved as state,
     * otherwise this function acts upon $_results.
     *
     * @access public
     * @param array $results
     * @return void
     *
     */
    function saveState($results = null)

    {
        $options = $this->_options;
        $path = $options['state_directory'];
        $file = $options['state_file'];
        if (!is_writable($path)) {
            PEAR::raiseError($path.' is not writeable');
        }
        if (file_exists($path.$file)) {
            if (!is_writable($path.$file)) {
                PEAR::raiseError($path.$file.' exists but is not writeable');
            }
        }
        $fp = @fopen($path.$file,'w');
        if (is_null($results)) {
            $results = $this->_results;
        }
        $line = serialize($results);
        @fwrite($fp,$line);
        @fclose($fp);
    }
    /** 
     * function getState
     *
     * Retrieves previous state information from the directory specified in
     * $_options['state_directory'] via a  file named $_options['state_file']
     *
     * @access public
     * @return array
     *
     */
    function getState() 

    {
        $my_line = "";
        $options = $this->_options;
        $path = $options['state_directory'];
        $file = $options['state_file'];
        if (file_exists($path.$file)) {
            if (!is_readable($path.$file)) {
                PEAR::raiseError($path.$file.' exists but is not readable');
            }
        } else {
            return array();
        }
        $fp = @fopen($path.$file,'r');
        while(!feof($fp)) {
           $my_line .= fgets($fp,4096);
        }
        $return_array = unserialize($my_line);
        @fclose($fp);
        return $return_array;
    }
    /** 
     * function stateDiff
     *
     * Computes the difference between the $primary and $secondary
     * arrays representing state, i.e. all values in primary that 
     * are not already in secondary. 
     *
     * Also returns an OK status for values in secondary that are 
     * not in primary unless
     * $_options['notify_ok'] is set to false. 
     * 
     * Returns values in primary whose code value differs 
     * from values in secondary unless
     * $_options['notify_change'] is set to false.
     *
     * @access private
     * @param array $secondary states to compare to current
     * @return array
     */
    function stateDiff($secondary) {
        $return_array = array();
        //loop through primary array
        foreach ($this->_results as $host=>$services) {
            foreach ($services as $service=>$result) {
                if (isset($secondary[$host][$service])) {
                    // host and service identical in current and secondary
                    if ($result[0] !== $secondary[$host][$service][0]) {
                         //different codes 
                         if($this->_options['notify_change']) {
                             //notify_change on; move to return
                             $return_array[$host][$service] = $result;
                         }
                    }
                // anyway unset so ok to withdrawn services to the end
                unset( $secondary[$host][$service]);
                } else { // it's a new host/service so to be announced
                    $return_array[$host][$service] = $result;
                }
            }
        }
        if($this->_options['notify_ok']) {
            foreach ($secondary as $host=>$services) {
                foreach ($services as $service=>$result) {
                    //remaining states in secondary added OK to return
                    $return_array[$host][$service] = array( 200, $result[1]);
                }
            }
        }
        return $return_array;
    }
    /** 
     * function resetState
     *
     * Resets the results and results differential arrays
     * and deletes the state file. 
     *
     * Returns true if the file has been deleted or never existed
     * in the first place; false otherwise.
     *
     * @access public
     * @return boolean
     */
    function resetState() 

    {
         $this->_results = array();
         $this->_results_diff = array();
         $options = $this->_options;
         $path = $options['state_directory'];
         $file = $options['state_file'];
         if (file_exists($path.$file)) {
             if (!is_writable($path.$file)) {
                 PEAR::raiseError($path.$file.' exists but is not writeable');
             }
             return unlink($path.$file);
         } else {
             return true;
         }
    }
    /** 
     * function resetHostState
     *
     * Resets the state for a single host ($host). Optionally takes in a
     * second parameter, $service which maybe an array, whereby the function only
     * resets the results for that/those particular host/service test.
     *
     * @param string $host
     * @param mixed $service
     * @return void
     * @access public
     */
    function resetHostState($host,$service = null) 

    {
        $last_state = $this->getState();
        if (!isset($last_state[$host])) {
            return;
        }
        if ($service != null) {
            if (is_array($service)) {
                foreach ($service as $serelt) {
                    unset($last_state[$host][$serelt]);
                }
            } else {
                unset($last_state[$host][$service]);
            }
        } else {
            unset($last_state[$host]);
        }
        $this->saveState($last_state);
    }
     /** 
      * function printAlert
      *
      * Prints the alert for a host/service
      * to STDOUT. Formats the alert according to $_options['alert_line'].
      *
      * @param string $host
      * @param string $service
      * @param array $result (code, message)
      * @return void
      * @access public
      */
     function printAlert($host, $service, $result) {
         if ($this->_options['alert_line']) {
             $alert_line = $this->_options['alert_line'];
         } else {
             $alert_line = '%h: %s: %m';
         }
         print str_replace(
                    array('%h', '%s',    '%c',      '%m'),
                    array($host,$service,$result[0],$result[1]),
                    $alert_line)."\r\n";
     }
}
?>