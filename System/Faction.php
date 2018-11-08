<?php
/**
 * Elite Dangerous Star Map
 * @link https://www.edsm.net/
 */

namespace   EDDN\System;

use         Alias\System\Allegiance;
use         Alias\System\Government;
use         Alias\System\State;
use         Alias\System\Happiness;

use         Alias\Station\Engineer;

class Faction
{
    private static $delayInfluenceCache = 43200; // 21600

    static public function handle($systemId, $message)
    {
        $currentSystem  = \Component\System::getInstance($systemId);

        if($currentSystem->isValid() && $currentSystem->isHidden() === false)
        {
            $factionsInfluencesDateUpdate   = $message['timestamp']; // Make sure all factions are updated at the same time
            $newFactions                    = array();

            // Create a list of factions with their IDs
            if(array_key_exists('Factions', $message))
            {
                $factionsModel = new \Models_Factions;

                foreach($message['Factions'] AS $faction)
                {
                    $faction['Name']    = trim($faction['Name']);
                    $factionId          = null;

                    // We skip the engineers ;)
                    if(!empty($faction['Name']) && !in_array($faction['Name'], Engineer::getAll()))
                    {
                        // Special continue case for Pilots Federation Local Branch ;)
                        if($faction['Name'] == 'Pilots Federation Local Branch' && $faction['Influence'] == 0)
                        {
                            continue;
                        }

                        $factionId          = $factionsModel->getByName($faction['Name']);

                        // Try to insert the new faction
                        if(is_null($factionId))
                        {
                            try
                            {
                                $factionId  = $factionsModel->insert(['name' => $faction['Name']]);
                                $factionId  = array('id' => $factionId);
                            }
                            catch(\Zend_Db_Exception $e)
                            {
                                if(strpos($e->getMessage(), '1062 Duplicate') !== false) // Can happen when the same faction is submitted twice during the process
                                {
                                    $factionId = $factionsModel->getByName($faction['Name']);
                            	}
                                else
                                {
                                    $factionId = null;
                                }
                            }
                        }
                    }


                    // Update allegiance and government if needed
                    if(!is_null($factionId) && array_key_exists('id', $factionId))
                    {
                        $factionId          = $factionId['id'];
                        $factionUpdate      = array();

                        $allegianceAlias    = Allegiance::getFromFd($faction['Allegiance']);
                        $governmentAlias    = Government::getFromFd($faction['Government']);

                        if(!is_null($allegianceAlias))
                        {
                            if(\EDSM_System_Station_Faction::getInstance($factionId)->getAllegiance() != $allegianceAlias)
                            {
                                $factionUpdate['allegiance'] = $allegianceAlias;
                            }
                        }
                        else
                        {
                            \EDSM_Api_Logger_Alias::log('Alias\System\Allegiance #' . $currentSystem->getId() . ':' . $faction['Allegiance']);
                        }

                        if(!is_null($governmentAlias))
                        {
                            if(\EDSM_System_Station_Faction::getInstance($factionId)->getGovernment() != $governmentAlias)
                            {
                                $factionUpdate['government'] = $governmentAlias;
                            }
                        }
                        else
                        {
                            \EDSM_Api_Logger_Alias::log('Alias\System\Government #' . $currentSystem->getId() . ':' . $faction['Government']);
                        }

                        $factionsModel->updateById($factionId, $factionUpdate);

                        $newFactions[$factionId] = $faction;

                        unset($factionUpdate, $allegianceAlias, $governmentAlias);
                    }
                }

                unset($factionsModel);
            }

            $factionsInfluencesModel        = new \Models_Factions_Influences;
            $factionsHistoryModel           = new \Models_Factions_History;

            $oldFactions                    = $factionsInfluencesModel->getByRefSystem($currentSystem->getId());

            // Update old factions
            if(!is_null($oldFactions) && count($oldFactions) > 0)
            {
                \EDSM_Api_Logger::log('<span class="text-info">EDDN\System\Faction:</span>            ' . $currentSystem->getName() . ' #' . $currentSystem->getId() . ' updating factions.');

                $currentSystemStations = $currentSystem->getStations();

                foreach($oldFactions AS $oldFaction)
                {
                    // Do not update if event timestamps are older than last update
                    if(strtotime($oldFaction['dateUpdated']) < strtotime($message['timestamp']))
                    {
                        // Do not delete faction not present anymore
                        // Just state they have gone by putting influence to zero and keep the history in case they're coming back later
                        if(!array_key_exists($oldFaction['refFaction'], $newFactions))
                        {
                            $newFactions[$oldFaction['refFaction']]                 = array();
                            $newFactions[$oldFaction['refFaction']]['Influence']    = 0;
                            $newFactions[$oldFaction['refFaction']]['FactionState'] = 'none';
                        }

                        // Update values and insert new history
                        if(array_key_exists($oldFaction['refFaction'], $newFactions))
                        {
                            $updateArray    = array();
                            $insertHistory  = array();

                            // Faction influence
                            if(
                                   // Influences are different
                                   $oldFaction['influence'] != $newFactions[$oldFaction['refFaction']]['Influence']
                                && (
                                    // Yet not the same as the last value stored, not game cache
                                       $newFactions[$oldFaction['refFaction']]['Influence'] != $oldFaction['oldInfluence']
                                    // Or it's the same but interval between last value is enought to be considered not from the game cache
                                    || ($newFactions[$oldFaction['refFaction']]['Influence'] == $oldFaction['oldInfluence'] && (strtotime($factionsInfluencesDateUpdate) - static::$delayInfluenceCache) >  strtotime($oldFaction['dateUpdated']))
                                )
                            )
                            {
                                $updateArray['influence']       = $newFactions[$oldFaction['refFaction']]['Influence'];
                                $updateArray['oldInfluence']    = $oldFaction['influence'];
                                $insertHistory['influence']     = $oldFaction['influence'];

                                // Check if an alert should be sent to a guild
                                self::handleGuildsAlerts($currentSystem, $oldFaction['refFaction'], $updateArray['oldInfluence'], $updateArray['influence']);
                            }

                            // Faction state
                            $stateAlias = State::getFromFd($newFactions[$oldFaction['refFaction']]['FactionState']);

                            if(!empty($newFactions[$oldFaction['refFaction']]['FactionState']) && !is_null($stateAlias))
                            {
                                if($oldFaction['state'] != $stateAlias)
                                {
                                    $updateArray['state']       = $stateAlias;
                                    $updateArray['oldState']    = $oldFaction['state'];
                                    $insertHistory['state']     = $oldFaction['state'];

                                    // Check station controlling faction and also update state, so data is not stale
                                    if(!is_null($currentSystemStations) && count($currentSystemStations) > 0)
                                    {
                                        foreach($currentSystemStations AS $key => $currentStation)
                                        {
                                            if(!($currentStation instanceof \EDSM_System_Station))
                                            {
                                                $currentSystemStations[$key] = $currentStation = \EDSM_System_Station::getInstance($currentStation['id']);
                                            }

                                            $currentStationFaction = $currentStation->getFaction();

                                            if(!is_null($currentStationFaction) && $currentStationFaction->getId() == $oldFaction['refFaction'])
                                            {
                                                $stationLastUpdate = $currentStation->getUpdateTime();

                                                if(is_null($stationLastUpdate) || strtotime($stationLastUpdate) < strtotime($message['timestamp']))
                                                {
                                                    if($currentStation->getFactionState() != $stateAlias)
                                                    {
                                                        //\EDSM_Api_Logger::log('<span class="text-danger">STATION STATE: ' . $currentStation->getName() . ': ' . $currentStation->getFactionState() . ' => ' . $stateAlias . '</span>');

                                                        $stationsModel                  = new \Models_Stations;
                                                        $newInformation                 = array();
                                                        $newInformation['factionState'] = $stateAlias;
                                                        $newInformation['updateTime']   = $message['timestamp'];
                                                        $stationsModel->updateById($currentStation->getId(), $newInformation);
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                            else
                            {
                                \EDSM_Api_Logger_Alias::log('Alias\System\State #' . $currentSystem->getId() . ':' . $newFactions[$oldFaction['refFaction']]['FactionState']);
                            }

                            // Faction Happiness
                            if(array_key_exists('Happiness', $newFactions[$oldFaction['refFaction']]))
                            {
                                if(empty($newFactions[$oldFaction['refFaction']]['Happiness']))
                                {
                                    $happinessAlias = 0; // None
                                }
                                else
                                {
                                    $happinessAlias = Happiness::getFromFd($newFactions[$oldFaction['refFaction']]['Happiness']);
                                }

                                if(!is_null($happinessAlias))
                                {
                                    // Default value
                                    if(!array_key_exists('happiness', $oldFaction)) { $oldFaction['happiness'] = 0; }

                                    if($oldFaction['happiness'] != $happinessAlias)
                                    {
                                        $updateArray['happiness']       = $happinessAlias;
                                        $updateArray['oldHappiness']    = $oldFaction['happiness'];
                                        $insertHistory['happiness']     = $oldFaction['happiness'];
                                    }
                                }
                                else
                                {
                                    \EDSM_Api_Logger_Alias::log('Alias\System\Happiness #' . $currentSystem->getId() . ':' . $newFactions[$oldFaction['refFaction']]['Happiness']);
                                }
                            }

                            // Faction states
                            $availableStates = array(
                                'activeStates',
                                'recoveringStates',
                                'pendingStates',
                            );

                            foreach($availableStates AS $currentAvailableState)
                            {
                                if(!array_key_exists(ucfirst($currentAvailableState), $newFactions[$oldFaction['refFaction']]))
                                {
                                    // If no active states, resets for current faction
                                    $newFactions[$oldFaction['refFaction']][ucfirst($currentAvailableState)] = null;
                                }

                                $currentStates = $newFactions[$oldFaction['refFaction']][ucfirst($currentAvailableState)];

                                // Convert active states to aliases
                                if(!is_null($currentStates))
                                {
                                    $temp           = array();

                                    foreach($currentStates AS $currentState)
                                    {
                                        $stateAlias = State::getFromFd($currentState['State']);

                                        if(!is_null($stateAlias))
                                        {
                                            if(array_key_exists('Trend', $currentState))
                                            {
                                                $temp[] = array(
                                                    'trend'     => $currentState['Trend'],
                                                    'state'     => $stateAlias,
                                                );
                                            }
                                            else
                                            {
                                                $temp[] = array(
                                                    'state'     => $stateAlias,
                                                );
                                            }
                                        }
                                        else
                                        {
                                            \EDSM_Api_Logger_Alias::log('Alias\System\State #' . $currentSystem->getId() . ':' . $currentStates['State']);
                                        }
                                    }

                                    $currentStates = \Zend_Json::encode($temp);
                                    unset($temp);
                                }

                                if(!array_key_exists($currentAvailableState, $oldFaction) || $oldFaction[$currentAvailableState] != $currentStates)
                                {
                                    $updateArray[$currentAvailableState]    = $currentStates;
                                    $insertHistory[$currentAvailableState]  = $oldFaction[$currentAvailableState];
                                }
                            }

                            if(count($updateArray) > 0 || (strtotime($factionsInfluencesDateUpdate) - static::$delayInfluenceCache) >  strtotime($oldFaction['dateUpdated']))
                            {
                                $updateArray['dateUpdated'] = $factionsInfluencesDateUpdate;
                            }

                            if(count($updateArray) > 0)
                            {
                                $factionsInfluencesModel->updateById($oldFaction['id'], $updateArray);

                                \EDSM_Api_Logger::log('<span class="text-info">EDDN\System\Faction:</span>                - Update ' . \EDSM_System_Station_Faction::getInstance($oldFaction['refFaction'])->getName() . ' #' . $oldFaction['refFaction']);
                            }

                            if(count($insertHistory) > 0)
                            {
                                // Always add the last influence when any state have been updated
                                if(!array_key_exists('influence', $insertHistory))
                                {
                                    $insertHistory['influence']     = $oldFaction['influence'];
                                }

                                $insertHistory['refSystem']     = $currentSystem->getId();
                                $insertHistory['refFaction']    = $oldFaction['refFaction'];
                                $insertHistory['dateUpdated']   = $oldFaction['dateUpdated'];

                                try
                                {
                                    $factionsHistoryModel->insert($insertHistory);
                                }
                                catch(\Zend_Db_Exception $e)
                                {
                                    $registry = \Zend_Registry::getInstance();

                                    if($registry->offsetExists('sentryClient'))
                                    {
                                        $sentryClient = $registry->offsetGet('sentryClient');
                                        $sentryClient->captureException($e);
                                    }
                                }
                            }

                            unset($newFactions[$oldFaction['refFaction']], $updateArray, $insertHistory);
                        }
                    }
                    else
                    {
                        unset($newFactions[$oldFaction['refFaction']]);
                    }
                }
            }

            // Add the new factions
            foreach($newFactions AS $factionId => $faction)
            {
                $insert                 = array();
                $insert['refSystem']    = $currentSystem->getId();
                $insert['refFaction']   = $factionId;
                $insert['influence']    = $faction['Influence'];
                $insert['dateUpdated']  = $factionsInfluencesDateUpdate;

                $stateAlias             = State::getFromFd($faction['FactionState']);

                if(!empty($faction['FactionState']) && !is_null($stateAlias))
                {
                    $insert['state'] = $stateAlias;
                }
                else
                {
                    \EDSM_Api_Logger_Alias::log('Alias\System\State #' . $currentSystem->getId() . ':' . $faction['FactionState']);
                }

                $factionsInfluencesModel->insert($insert);

                // Check if an alert should be sent to a guild
                self::handleGuildsAlerts($currentSystem, $insert['refFaction'], null, $insert['influence']);

                unset($insert);
            }

            //\EDSM_Api_Logger::log('FACTION UPDATE STATES: OK ???');
        }
    }

    static public function handleGuildsAlerts($currentSystem, $factionId, $oldInfluence, $newInfluence)
    {
        $needToCheck    = false;
        $resetAlert     = null;
        $threshold      = null;
        $newInfluence   = $newInfluence * 100;

        if(!is_null($oldInfluence))
        {
            $oldInfluence   = $oldInfluence * 100;

            // New primary alert
            if($oldInfluence > $newInfluence && $oldInfluence > \EDSM_Guild::$priorityThreshold && $newInfluence <= \EDSM_Guild::$priorityThreshold)
            {
                $needToCheck    = true;
                $resetAlert     = false;
                $threshold      = \EDSM_Guild::$priorityThreshold;
            }
             // New secondary alert
            if($oldInfluence > $newInfluence && $oldInfluence > \EDSM_Guild::$secondaryThreshold && $newInfluence <= \EDSM_Guild::$secondaryThreshold)
            {
                $needToCheck    = true;
                $resetAlert     = false;
                $threshold      = \EDSM_Guild::$secondaryThreshold;
            }
            // Reset primary alert
            if($oldInfluence < $newInfluence && $oldInfluence <= \EDSM_Guild::$secondaryThreshold && $newInfluence > \EDSM_Guild::$secondaryThreshold)
            {
                $needToCheck    = true;
                $resetAlert     = true;
                $threshold      = \EDSM_Guild::$secondaryThreshold;
            }
            // Reset secondary alert
            if($oldInfluence < $newInfluence && $oldInfluence <= \EDSM_Guild::$priorityThreshold && $newInfluence > \EDSM_Guild::$priorityThreshold)
            {
                $needToCheck    = true;
                $resetAlert     = true;
                $threshold      = \EDSM_Guild::$priorityThreshold;
            }
        }
        else
        {
            // Targeted alert!
            $needToCheck    = true;
            $resetAlert     = true;
            $threshold      = 0;
        }

        if($needToCheck === true)
        {
            $guildsSystemsModel = new \Models_Guilds_Systems;
            $selectAlerts       = $guildsSystemsModel->select()
                                                     ->from($guildsSystemsModel)
                                                     ->where('`refSystem` = ?', $currentSystem->getId());

            if($resetAlert === true)
            {
                $selectAlerts->where('`dateAlert` IS NULL');
            }
            if($threshold == \EDSM_Guild::$priorityThreshold)
            {
                $selectAlerts->where('`state` = ?', 'priority');
            }
            if($threshold == \EDSM_Guild::$secondaryThreshold)
            {
                $selectAlerts->where('`state` = ?', 'secondary');
            }
            if($threshold == 0)
            {
                $selectAlerts->where('`state` = ?', 'targeted');
            }

            $selectAlerts       = $guildsSystemsModel->fetchAll($selectAlerts);

            if(!is_null($selectAlerts) && count($selectAlerts) > 0)
            {
                $selectAlerts = $selectAlerts->toArray();

                foreach($selectAlerts AS $selectAlert)
                {
                    // Check if guild has something to do with current faction
                    $currentGuild   = \EDSM_Guild::getInstance($selectAlert['refGuild']);
                    $currentFaction = $currentGuild->getFaction();

                    if(!is_null($currentFaction) && $currentFaction->getId() == $factionId)
                    {
                        // Select users for emailing
                        $tempUsers      = $currentGuild->getAcceptedUsers();
                        $currentUsers   = array();

                        foreach($tempUsers AS $refUser)
                        {
                            if($currentGuild->canManageSystems($refUser['refUser']) === true)
                            {
                                $currentUsers[] = $refUser['refUser'];
                            }
                        }

                        // Reset all alerts
                        if($resetAlert === true)
                        {
                            // Targeted systems becomes unclassified
                            if($threshold == 0)
                            {
                                $guildsSystemsModel->deleteByRefGuildAndRefSystem(
                                    $selectAlert['refGuild'],
                                    $selectAlert['refSystem']
                                );

                                foreach($currentUsers AS $refUser)
                                {
                                    $currentUser = \Component\User::getInstance($refUser);

                                    if($currentUser->isValid())
                                    {
                                        // TODO: Send email that system is now unclassified!
                                        \EDSM_Api_Logger::log('<span class="text-warning">EDDN\System\Faction:</span>                - Sending mail to ' . $currentUser->getCMDR() . ' / NOW UNCLASSIFIED');
                                    }
                                }
                            }
                            else
                            {
                                $guildsSystemsModel->updateByRefGuildAndRefSystem(
                                    $selectAlert['refGuild'],
                                    $selectAlert['refSystem'],
                                    array('dateAlert' => new \Zend_Db_Expr('NULL'))
                                );

                                foreach($currentUsers AS $refUser)
                                {
                                    $currentUser = \Component\User::getInstance($refUser);

                                    if($currentUser->isValid())
                                    {
                                        // TODO: Send email that system is fine now!
                                        \EDSM_Api_Logger::log('<span class="text-warning">EDDN\System\Faction:</span>                - Sending mail to ' . $currentUser->getCMDR() . ' / Threshold: ' . $threshold . ' / GOOD');
                                    }
                                }
                            }
                        }

                        // Send according alerts
                        if($resetAlert === false)
                        {
                            $guildsSystemsModel->updateByRefGuildAndRefSystem(
                                $selectAlert['refGuild'],
                                $selectAlert['refSystem'],
                                array('dateAlert' => new \Zend_Db_Expr('NOW()'))
                            );

                            foreach($currentUsers AS $refUser)
                            {
                                $currentUser = \Component\User::getInstance($refUser);

                                if($currentUser->isValid())
                                {
                                    // TODO: Send email that system is fine now!
                                    \EDSM_Api_Logger::log('<span class="text-warning">EDDN\System\Faction:</span>                - Sending mail to ' . $currentUser->getCMDR() . ' / Threshold: ' . $threshold . ' / TOO LOW');
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}