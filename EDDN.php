<?php
/**
 * Elite Dangerous Star Map
 * @link https://www.edsm.net/
 */

define('APPLICATION_CLI', 'EDDN');
define('APPLICATION_SHUTDOWN', false);

require_once dirname(__FILE__) . '/../EDSM/BootstrapCLI.php';


/**
 *  START
 */
EDSM_Api_Logger::setCache('lastEDDNAction');

$bootstrap      = Zend_Registry::get('Zend_Application');
$cacheManager   = $bootstrap->getResource('cachemanager');
$cache          = $cacheManager->getCache('database');

$validSchemas   = array(
    'https://eddn.edcd.io/schemas/commodity/3',
    'https://eddn.edcd.io/schemas/shipyard/2',
    'https://eddn.edcd.io/schemas/outfitting/2',
    'https://eddn.edcd.io/schemas/blackmarket/1',
    'https://eddn.edcd.io/schemas/journal/1',

    'https://eddn.edcd.io/schemas/commodity/3/live',
    'https://eddn.edcd.io/schemas/shipyard/2/live',
    'https://eddn.edcd.io/schemas/outfitting/2/live',
    'https://eddn.edcd.io/schemas/blackmarket/1/live',
    'https://eddn.edcd.io/schemas/journal/1/live',
);

/**
 * HANDLE MESSAGE
 */
$cacheKey   = $argv[1];
$messages   = $cache->load($cacheKey);

if($messages !== false)
{
    $cache->remove($cacheKey);

    foreach($messages['messages'] AS $message)
    {
        try
        {
            $json = zlib_decode($message);
            $json = Zend_Json::decode($json);
        }
        catch(Zend_Json_Exception $e)
        {
            EDSM_Api_Logger::log('Invalid JSON');
            continue;
        }

        $schemaRef  = strtolower($json['$schemaRef']);
        $header     = $json['header'];
        $message    = $json['message'];

        // Do not handle test messages
        if(in_array($schemaRef, $validSchemas))
        {
            $softwaresModel     = new Models_Softwares;
            $softwareId         = $softwaresModel->getId($header['softwareName'], $header['softwareVersion']);
            $softwareBlackList  = include LIBRARY_PATH . '/Alias/BlacklistSoftware.php';

            // Is software globally/EDDN removed from EDSM?
            if(in_array($softwareId, $softwareBlackList['GLOBAL']) || in_array($softwareId, $softwareBlackList['EDDN']))
            {
                continue;
            }

            // Fix some wrong format
            $message['timestamp'] = trim(str_replace('T', ' ', $message['timestamp']), 'Z');
            $message['timestamp'] = explode('.', $message['timestamp']);
            $message['timestamp'] = $message['timestamp'][0];

            // Add software/message to Sentry tags
            $registry = \Zend_Registry::getInstance();
            if($registry->offsetExists('sentryClient'))
            {
                $sentryClient = $registry->offsetGet('sentryClient');
                $sentryClient->tags_context(array(
                    'softwareName'      => $header['softwareName'],
                    'softwareVersion'   => $header['softwareVersion'],
                ));
                $sentryClient->extra_context(array('header' => $header, 'message' => $message));
            }

            if(strpos($schemaRef, '/journal/1') !== false)
            {
                if(array_key_exists('event', $message) && in_array(strtolower(trim($message['event'])), array('fsdjump', 'location')))
                {
                    $systemId = \EDDN\System\Coordinates::handle($message, $header['softwareName'], $header['softwareVersion'], true);

                    if(!is_null($systemId))
                    {
                        \EDDN\System\Information::handle($systemId, $message);
                        \EDDN\System\Faction::handle($systemId, $message);

                        if(strtolower(trim($message['event'])) == 'fsdjump')
                        {
                            \EDDN\System\PowerPlay::handle($systemId, $message);
                        }
                    }

                    continue;
                }

                if(array_key_exists('event', $message) && strtolower(trim($message['event'])) == 'docked')
                {
                    $systemId = \EDDN\System\Coordinates::handle($message, $header['softwareName'], $header['softwareVersion']);

                    if(!is_null($systemId))
                    {
                        $stationId = \EDDN\Station\Coordinates::handle($systemId, $message, $header['softwareName'], $header['softwareVersion']);

                        if(!is_null($stationId))
                        {
                            \EDDN\Station\Information::handle($systemId, $stationId, $message);
                            \EDDN\Station\Services::handle($stationId, $message);
                        }
                    }

                    continue;
                }

                if(array_key_exists('event', $message) && strtolower(trim($message['event'])) == 'scan')
                {
                    $systemId = \EDDN\System\Coordinates::handle($message, $header['softwareName'], $header['softwareVersion'], false, true);

                    if(!is_null($systemId))
                    {
                        \EDDN\System\Body::handle($systemId, $message);
                    }

                    continue;
                }

                EDSM_Api_Logger::log('Unknown journal event (' . $schemaRef . ' [' . $message['event'] . ']).');
            }

            // Stations commodities
            if(strpos($schemaRef, '/commodity/3') !== false)
            {
                //TODO: Convert to StarSystem
                if(array_key_exists('systemName', $message))
                {
                    $systemsModel   = new Models_Systems;
                    $currentSystem  = $systemsModel->getByName($message['systemName']);

                    if(!is_null($currentSystem))
                    {
                        $systemId = $currentSystem['id'];
                    }

                    unset($currentSystem);
                }

                if(isset($systemId) && !is_null($systemId))
                {
                    $stationId = \EDDN\Station\Coordinates::handle($systemId, $message, $header['softwareName'], $header['softwareVersion']);

                    if(!is_null($stationId))
                    {
                        \EDDN\Station\Commodities::handle($systemId, $stationId, $message);
                    }
                }

                continue;
            }

            // Stations ships
            if(strpos($schemaRef, '/shipyard/2') !== false)
            {
                //TODO: Convert to StarSystem
                if(array_key_exists('systemName', $message))
                {
                    $systemsModel   = new Models_Systems;
                    $currentSystem  = $systemsModel->getByName($message['systemName']);

                    if(!is_null($currentSystem))
                    {
                        $systemId = $currentSystem['id'];
                    }

                    unset($currentSystem);
                }

                if(isset($systemId) && !is_null($systemId))
                {
                    $stationId = \EDDN\Station\Coordinates::handle($systemId, $message, $header['softwareName'], $header['softwareVersion']);

                    if(!is_null($stationId))
                    {
                        \EDDN\Station\Ships::handle($systemId, $stationId, $message);
                    }
                }

                continue;
            }

            // Stations outfitting
            if(strpos($schemaRef, '/outfitting/2') !== false)
            {
                //TODO: Convert to StarSystem
                if(array_key_exists('systemName', $message))
                {
                    $systemsModel   = new Models_Systems;
                    $currentSystem  = $systemsModel->getByName($message['systemName']);

                    if(!is_null($currentSystem))
                    {
                        $systemId = $currentSystem['id'];
                    }

                    unset($currentSystem);
                }

                if(isset($systemId) && !is_null($systemId))
                {
                    $stationId = \EDDN\Station\Coordinates::handle($systemId, $message, $header['softwareName'], $header['softwareVersion']);

                    if(!is_null($stationId))
                    {
                        \EDDN\Station\Outfittings::handle($systemId, $stationId, $message);
                    }
                }

                continue;
            }
        }
    }

    EDSM_Api_Logger::log('<span class="text-success">Processed batch messages (' . count($messages['messages']) . ').</span>');
}
else
{
    EDSM_Api_Logger::log('<span class="text-danger">Invalid CacheKey: ' . $cacheKey . '</span>');
}

// Exit correctly
exit(0);