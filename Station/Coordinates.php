<?php
/**
 * Elite Dangerous Star Map
 * @link https://www.edsm.net/
 */

namespace   EDDN\Station;

use         Alias\Station\Type as StationType;

class Coordinates
{
    static public function handle($systemId, $message, $softwareName, $softwareVersion)
    {
        if(array_key_exists('StationName', $message) && $message['StationName'] == '')
        {
            return null;
        }

        $currentSystem  = \Component\System::getInstance($systemId);

        // Search station by MarketID
        if(array_key_exists('marketId', $message))
        {
            $message['MarketID'] = $message['marketId'];
            unset($message['marketId']);
        }

        if(array_key_exists('MarketID', $message))
        {
            $stationsModel  = new \Models_Stations;
            $station        = $stationsModel->getByMarketId($message['MarketID']);

            if(!is_null($station) && strtotime($station['updateTime']) < strtotime($message['timestamp']))
            {
                $station = $stationsModel->getById($station['id']);
                $update  = array();

                // Save megaship systems history if moved
                if($station['refSystem'] != $currentSystem->getId() && in_array($station['type'], array(12, 21, 31)))
                {
                    // Add old system to history
                    $systemsHistory         = array();
                    if(!empty($station['systemsHistory']))
                    {
                        $systemsHistory         = \Zend_Json::decode($station['systemsHistory']);
                    }

                    $systemsHistory[time()]     = $station['refSystem'];
                    $systemsHistory             = array_slice($systemsHistory, -100);

                    $update['refSystem']        = $currentSystem->getId();
                    $update['systemsHistory']   = \Zend_Json::encode($systemsHistory);
                    $update['refBody']          = new \Zend_Db_Expr('NULL');
                }

                // Rescue ship changed name?
                if(array_key_exists('StationName', $message) && $message['StationName'] != $station['name'])
                {
                    $update['name'] = $message['StationName'];
                }

                if(count($update) > 0)
                {
                    // Update system/name
                    $stationsModel->updateById($station['id'], $update);
                }

                return $station['id'];
            }
        }

        // Search by name inside current system
        if(array_key_exists('StationName', $message))
        {
            $stationName = $message['StationName'];
        }
        elseif(array_key_exists('stationName', $message))
        {
            $stationName = $message['stationName'];
        }
        else
        {
            return null;
        }

        // Filter wrong station names ;)
        $stationName    = trim($stationName);
        if(substr($stationName, -3) === 'Stn'){ $stationName = trim(str_replace(' Stn', ' Station', $stationName)); }
        if($stationName == 'Nicollier Hanger'){ $stationName = 'Nicollier Hangar'; }
        if($stationName == 'Henry O\'Hare\'s Hanger'){ $stationName = 'Henry O\'Hare\'s Hangar'; }

        if($currentSystem->isValid() && $currentSystem->isHidden() === false && !empty($stationName))
        {
            $stations       = $currentSystem->getStations(true);

            if(count($stations) > 0)
            {
                foreach($stations AS $station)
                {
                    if($station['name'] == $stationName)
                    {
                        if(array_key_exists('MarketID', $message) && is_null($station['marketId']))
                        {
                            $stationsModel->updateById($station['id'], array('marketId' => $message['MarketID']));
                        }

                        return $station['id'];
                    }
                }
            }

            if(array_key_exists('StationType', $message))
            {
                $typeAlias = null;

                // MegaShips can change system, update if not found in current system
                $type = trim($message['StationType']);
                if(!empty($type))
                {
                    $typeAlias  = StationType::getFromFd($type);

                    if(!is_null($typeAlias) && $typeAlias == 12)
                    {
                        // Find the station by name and type
                        $stationsModel = new \Models_Stations;
                        $station       = $stationsModel->fetchRow(
                            $stationsModel->select()
                                          ->where('name = ?', $stationName)
                                          ->where('type = ?', 12)
                        );

                        if(!is_null($station))
                        {
                            if($station->refSystem != $currentSystem->getId())
                            {
                                // Add old system to history
                                if(empty($station->systemsHistory))
                                {
                                    $systemsHistory         = array();
                                }
                                else
                                {
                                    $systemsHistory         = \Zend_Json::decode($station->systemsHistory);
                                }


                                $systemsHistory[time()] = $station->refSystem;

                                // Update system
                                $stationsModel->updateById(
                                    $station->id,
                                    array(
                                        'refSystem'         => $currentSystem->getId(),
                                        'systemsHistory'    => \Zend_Json::encode($systemsHistory),
                                        'refBody'           => new \Zend_Db_Expr('NULL'),
                                    )
                                );
                            }

                            return $station->id;
                        }
                    }
                }

                // Station not found, create it
                try
                {
                    $insert = array(
                        'refSystem'     => $systemId,
                        'name'          => $stationName,
                        'updateTime'    => $message['timestamp'],
                    );

                    if(!is_null($typeAlias))
                    {
                        $insert['type'] = $typeAlias;
                    }

                    if(array_key_exists('DistFromStarLS', $message))
                    {
                        $insert['distanceToArrival'] = $message['DistFromStarLS'];
                    }

                    if(array_key_exists('MarketID', $message))
                    {
                        $insert['marketId'] = $message['MarketID'];
                    }

                    $stationsModel  = new \Models_Stations;
                    $stationId      = $stationsModel->insert($insert);
                    $currentStation = \EDSM_System_Station::getInstance($stationId);
                }
                catch(\Zend_Db_Exception $e)
                {
                    if(strpos($e->getMessage(), '1062 Duplicate') !== false) // Can happen when the same station is submitted twice during the process
                    {
                        return self::handle($systemId, $message, $softwareName, $softwareVersion);
                    }
                    else
                    {
                        return null;
                    }
                }

                \EDSM_Api_Logger::log('<span class="text-info">EDDN\Station\Coordinates:</span>       ' . $currentStation->getName() . ' (#' . $currentStation->getId() . ') created!');

                return $currentStation->getId();
            }
        }

        return null;
    }
}