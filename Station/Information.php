<?php
/**
 * Elite Dangerous Star Map
 * @link https://www.edsm.net/
 */

namespace   EDDN\Station;

use         Alias\System\Allegiance;
use         Alias\System\Government;
use         Alias\System\Economy;
use         Alias\System\State;

use         Alias\Station\Type                  as StationType;
use         Alias\Station\State                 as StationState;

use         Alias\Station\Engineer\Station      as EngineerStation;

class Information
{
    static public function handle($systemId, $stationId, $message)
    {
        $currentSystem  = \Component\System::getInstance($systemId);
        $currentStation = \EDSM_System_Station::getInstance($stationId);

        if($currentSystem->isValid() && $currentSystem->isHidden() === false && $currentStation->isValid() && $currentSystem->getId() == $currentStation->getSystem()->getId())
        {
            $stationLastUpdate = $currentStation->getUpdateTime();

            if(!is_null($stationLastUpdate) && strtotime($message['timestamp']) < strtotime($stationLastUpdate))
            {
                \EDSM_Api_Logger::log('<span class="text-info">EDDN\Station\Information:</span>       <span class="text-danger">' . $currentStation->getName() . ' (#' . $currentStation->getId() . ') information too old (' . $message['timestamp'] . ').</span>');
                return;
            }

            $newInformation     = array();

            // Type
            if(array_key_exists('StationType', $message))
            {
                $type       = trim($message['StationType']);

                if(!empty($type))
                {
                    $typeAlias  = StationType::getFromFd($type);

                    if(!is_null($typeAlias))
                    {
                        if($currentStation->getType() != $typeAlias)
                        {
                            $newInformation['type'] = $typeAlias;
                        }
                    }
                    else
                    {
                        \EDSM_Api_Logger_Alias::log('Alias\Station\Type #' . $currentStation->getId() . ':' . $type);
                    }
                }
            }

            // State
            if(array_key_exists('StationState', $message))
            {
                $state       = trim($message['StationState']);

                if(!empty($state))
                {
                    $stateAlias  = StationState::getFromFd($state);

                    if(!is_null($stateAlias))
                    {
                        if($currentStation->getState() != $stateAlias)
                        {
                            $newInformation['state'] = $stateAlias;
                        }
                    }
                    else
                    {
                        \EDSM_Api_Logger_Alias::log('Alias\Station\State #' . $currentStation->getId() . ':' . $state);
                    }
                }
            }
            elseif(!is_null($currentStation->getState()))
            {
                $newInformation['state'] = new \Zend_Db_Expr('NULL');
            }

            if(array_key_exists('DistFromStarLS', $message) && $message['DistFromStarLS'] != $currentStation->getDistanceToArrival())
            {
                $newInformation['distanceToArrival'] = $message['DistFromStarLS'];
            }

            // Station body
            if(array_key_exists('BodyName', $message)) // EDMC < 2.4
            {
                $message['Body'] = $message['BodyName'];
                unset($message['BodyName']);
            }
            if(array_key_exists('Body', $message) && array_key_exists('BodyType', $message) && $message['BodyType'] == 'Planet')
            {
                // Find message body id, from the list of current system
                $currentSystemBodies    = $currentSystem->getBodies();
                $newBodyId              = null;

                foreach($currentSystemBodies AS $currentBody)
                {
                    if($currentBody->getName() == trim($message['Body']))
                    {
                        $newBodyId = $currentBody->getId();
                    }
                }

                if(!is_null($newBodyId))
                {
                    $currentBody = $currentStation->getBody();

                    if(!is_null($currentBody))
                    {
                        if($currentBody->getId() != $newBodyId)
                        {
                            $newInformation['refBody'] = $newBodyId;
                        }
                    }
                    else
                    {
                        $newInformation['refBody'] = $newBodyId;
                    }
                }
            }

            // Allegiance
            if(array_key_exists('StationAllegiance', $message))
            {
                $allegiance         = trim($message['StationAllegiance']);
                $allegianceAlias    = Allegiance::getFromFd($allegiance);

                if(!empty($allegiance) && !is_null($allegianceAlias))
                {
                    if($currentStation->getAllegiance() != $allegianceAlias)
                    {
                        $newInformation['allegiance'] = $allegianceAlias;
                    }
                }
                elseif(!empty($allegiance) && is_null($allegianceAlias))
                {
                    \EDSM_Api_Logger_Alias::log('Alias\System\Allegiance #' . $currentStation->getId() . ':' . $allegiance);
                }
                else
                {
                    // No allegiance for station is Independent
                    $newInformation['allegiance'] = 4;
                }
            }
            elseif($currentStation->getAllegiance() != 4)
            {
                $newInformation['allegiance'] = 4;
            }


            // Government
            if(array_key_exists('StationGovernment', $message))
            {
                $government         = trim($message['StationGovernment']);
                $governmentAlias    = Government::getFromFd($government);

                if(!is_null($governmentAlias))
                {
                    if($currentStation->getGovernment() != $governmentAlias)
                    {
                        $newInformation['government'] = $governmentAlias;
                    }
                }
                else
                {
                    \EDSM_Api_Logger_Alias::log('Alias\System\Government #' . $currentStation->getId() . ':' . $government);
                }
            }

            // New Q4 form...
            // "StationFaction": { "Name":"Union of Luhman 16 Values Party", "FactionState":"CivilWar" }
            if(array_key_exists('StationFaction', $message) && is_array($message['StationFaction']))
            {
                if(array_key_exists('FactionState', $message['StationFaction']))
                {
                    $message['FactionState']    = $message['StationFaction']['FactionState'];
                }

                $message['StationFaction']  = $message['StationFaction']['Name'];
            }

            // Faction
            if(array_key_exists('StationFaction', $message) && !in_array($currentStation->getId(), EngineerStation::getAll()))
            {
                $factionsModel  = new \Models_Factions;
                $factionName    = trim($message['StationFaction']);
                $factionId      = $factionsModel->getByName($factionName);

                if(is_null($factionId))
                {
                    $factionId = $factionsModel->insert(array('name' => $factionName));
                }
                else
                {
                    $factionId = $factionId['id'];
                }

                if(!is_null($factionId) && (is_null($currentStation->getFaction()) || $currentStation->getFaction()->getId() != $factionId))
                {
                    $newInformation['refFaction'] = $factionId;
                }
            }

            // Faction State
            if(array_key_exists('FactionState', $message) && !in_array($currentStation->getId(), EngineerStation::getAll()))
            {
                $state      = trim($message['FactionState']);
                $stateAlias = State::getFromFd($state);

                if(!empty($state) && !is_null($stateAlias))
                {
                    if($currentStation->getFactionState() != $stateAlias)
                    {
                        $newInformation['factionState'] = $stateAlias;
                    }
                }
                elseif(!empty($state) && !is_null($stateAlias))
                {
                    \EDSM_Api_Logger_Alias::log('Alias\System\State #' . $currentStation->getId() . ':' . $state);
                }
                elseif(!is_null($currentStation->getFactionState()) && $currentStation->getFactionState() != 0)
                {
                    $newInformation['factionState'] = 0;
                }
            }
            elseif(!is_null($currentStation->getFactionState()) && $currentStation->getFactionState() != 0)
            {
                $newInformation['factionState'] = 0;
            }

            // Economy
            if(array_key_exists('StationEconomies', $message))
            {
                //TODO: Order by proportion?

                foreach($message['StationEconomies'] AS $key => $economy)
                {
                    if($key == 0)
                    {
                        $message['StationEconomy'] = $economy['Name'];
                    }
                    if($key == 1)
                    {
                        $message['StationSecondEconomy'] = $economy['Name'];
                    }
                }
            }

            if(array_key_exists('StationEconomy', $message))
            {
                $economy        = trim($message['StationEconomy']);
                $economyAlias   = Economy::getFromFd($economy);

                if(!empty($economy) && !is_null($economyAlias))
                {
                    if($currentStation->getEconomy() != $economyAlias)
                    {
                        $newInformation['economy'] = $economyAlias;
                    }
                }
                elseif(!empty($economy) && is_null($economyAlias))
                {
                    \EDSM_Api_Logger_Alias::log('Alias\System\Economy #' . $currentStation->getId() . ':' . $economy);
                }
                else
                {
                    $newInformation['economy'] = 0;
                }
            }

            if(array_key_exists('StationSecondEconomy', $message))
            {
                $economy        = trim($message['StationSecondEconomy']);
                $economyAlias   = Economy::getFromFd($economy);

                if(!empty($economy) && !is_null($economyAlias))
                {
                    if($currentStation->getEconomy() != $economyAlias)
                    {
                        $newInformation['secondEconomy'] = $economyAlias;
                    }
                }
                elseif(!empty($economy) && is_null($economyAlias))
                {
                    \EDSM_Api_Logger_Alias::log('Alias\System\Economy #' . $currentStation->getId() . ':' . $economy);
                }
                else
                {
                    $newInformation['secondEconomy'] = 0;
                }
            }

            $stationsModel                  = new \Models_Stations;
            $newInformation['updateTime']   = $message['timestamp'];
            $stationsModel->updateById($currentStation->getId(), $newInformation);

            if(count($newInformation) > 1)
            {
                \EDSM_Api_Logger::log('<span class="text-info">EDDN\Station\Information:</span>       ' . $currentStation->getName() . ' (#' . $currentStation->getId() . ') updated information.');

                if(array_key_exists('type', $newInformation))
                {
                    \EDSM_Api_Logger::log('<span class="text-info">EDDN\Station\Information:</span>           - Type           : ' . StationType::get($newInformation['type']));
                }
                if(array_key_exists('state', $newInformation) && !$newInformation['state'] instanceof \Zend_Db_Expr)
                {
                    \EDSM_Api_Logger::log('<span class="text-info">EDDN\Station\Information:</span>           - State          : ' . StationState::get($newInformation['state']));
                }
                if(array_key_exists('distanceToArrival', $newInformation))
                {
                    \EDSM_Api_Logger::log('<span class="text-info">EDDN\Station\Information:</span>           - Distance       : ' . $newInformation['distanceToArrival']);
                }
                if(array_key_exists('refBody', $newInformation))
                {
                    \EDSM_Api_Logger::log('<span class="text-info">EDDN\Station\Information:</span>           - Parent body    : #' . $newInformation['refBody']);
                }

                if(array_key_exists('allegiance', $newInformation))
                {
                    \EDSM_Api_Logger::log('<span class="text-info">EDDN\Station\Information:</span>           - Allegiance     : ' . Allegiance::get($newInformation['allegiance']));
                }
                if(array_key_exists('government', $newInformation))
                {
                    \EDSM_Api_Logger::log('<span class="text-info">EDDN\Station\Information:</span>           - Government     : ' . Government::get($newInformation['government']));
                }

                if(array_key_exists('refFaction', $newInformation))
                {
                    \EDSM_Api_Logger::log('<span class="text-info">EDDN\Station\Information:</span>           - Faction        : ' . \EDSM_System_Station_Faction::getInstance($newInformation['refFaction'])->getName() . ' #' . $newInformation['refFaction']);
                }
                if(array_key_exists('factionState', $newInformation))
                {
                    \EDSM_Api_Logger::log('<span class="text-info">EDDN\Station\Information:</span>           - Faction State  : ' . State::get($newInformation['factionState']));
                }

                if(array_key_exists('economy', $newInformation))
                {
                    \EDSM_Api_Logger::log('<span class="text-info">EDDN\Station\Information:</span>           - Economy        : ' . Economy::get($newInformation['economy']));
                }
                if(array_key_exists('secondEconomy', $newInformation))
                {
                    \EDSM_Api_Logger::log('<span class="text-info">EDDN\Station\Information:</span>           - Economy (2)    : ' . Economy::get($newInformation['secondEconomy']));
                }
            }
        }
    }
}