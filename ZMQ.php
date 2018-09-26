<?php
/**
 * Elite Dangerous Star Map
 * @link https://www.edsm.net/
 */

define('APPLICATION_CLI', 'ZMQ');

require_once dirname(__FILE__) . '/../EDSM/BootstrapCLI.php';

/**
 *  START
 */
$relayEDDN              = 'tcp://eddn.edcd.io:9500';

// Grab database cache
$bootstrap      = Zend_Registry::get('Zend_Application');
$cacheManager   = $bootstrap->getResource('cachemanager');
$cache          = $cacheManager->getCache('database');

// Add logger
EDSM_Api_Logger::setCache('lastEDDNAction', $cache);

EDSM_Api_Logger::log('');
EDSM_Api_Logger::log('Starting EDDN task (' . APPLICATION_ENV . ')');
EDSM_Api_Logger::log('');

$context = new ZMQContext();
$socket = $context->getSocket(ZMQ::SOCKET_SUB);
$socket->setSockOpt(ZMQ::SOCKOPT_SUBSCRIBE, "");
$socket->setSockOpt(ZMQ::SOCKOPT_RCVTIMEO, 600000);

$messagesDefault    = array('batch' => true, 'messages' => array());
$messagesBatch      = 100;
$messagesBatchTime  = 10;

$messages           = $messagesDefault;
$lastTimeMessages   = time();

while (true)
{
    try
    {
        $socket->connect($relayEDDN);
        EDSM_Api_Logger::log('');
        EDSM_Api_Logger::log('<span class="text-success">Connecting to: ' . $relayEDDN . '</span>');
        EDSM_Api_Logger::log('');
        
        while(true)
        {
            $message = $socket->recv();
            
            if($message === false)
            {
                $socket->disconnect($relayEDDN);
                EDSM_Api_Logger::log('');
                EDSM_Api_Logger::log('<span class="text-danger">Disconnecting from: ' . $relayEDDN . '</span>');
                EDSM_Api_Logger::log('');
                break;
            }
            
            $messages['messages'][] = $message;
            
            if(count($messages['messages']) >= $messagesBatch || time() > ($lastTimeMessages + $messagesBatchTime))
            {
                // Create message temp cache
                $cacheKey = 'EDDN_' . sha1(microtime()) . '_' . mt_rand();
                while($cache->load($cacheKey) !== false)
                {
                    $cacheKey = 'EDDN_' . sha1(microtime()) . '_' . mt_rand();
                }
                
                // Save messages batch
                $cache->save($messages, $cacheKey, array(), 300);
                
                // Execute message batch handle
                EDSM_Api_Logger::log('<span class="text-success">Execute batch (' . count($messages['messages']) . '): ' . $cacheKey . '</span>');                
                exec('/usr/bin/php7.2 -f ' . LIBRARY_PATH . '/EDDN/EDDN.php -- "' . $cacheKey . '" > /dev/null 2>&1 &');
                
                // Purge messages and reset timer              
                $messages           = $messagesDefault;
                $lastTimeMessages   = time();
                
                if(file_exists(APPLICATION_PATH . '/Data/edsm.eddn.stop'))
                {
                    unlink(APPLICATION_PATH . '/Data/edsm.eddn.stop');
                    
                    EDSM_Api_Logger::log('');
                    EDSM_Api_Logger::log('<span class="text-danger">Stop signal (' . APPLICATION_ENV . ')</span>');
                    
                    exit();
                }
            }
        }
    }
    catch (ZMQSocketException $e)
    {
        EDSM_Api_Logger::log('');
        EDSM_Api_Logger::log('ZMQSocketException: ' . $e);
        EDSM_Api_Logger::log('');
        sleep(10);
    }
}

// Exit correctly
exit(0);