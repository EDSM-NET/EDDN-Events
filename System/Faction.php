<?php
/**
 * Elite Dangerous Star Map
 * @link https://www.edsm.net/
 */

namespace   EDDN\System;

use         Alias\System\Allegiance;
use         Alias\System\Government;
use         Alias\System\State;

use         Alias\Station\Engineer;

class Faction
{
    static public function handle($systemId, $message)
    {
        $currentSystem  = \EDSM_System::getInstance($systemId);
        
        if($currentSystem->isValid() && $currentSystem->isHidden() === false)
        {
            $factionsInfluencesDateUpdate   = $message['timestamp']; // Make sure all factions are updated at the same time
            
            $factionsModel                  = new \Models_Factions;
            $factionsInfluencesModel        = new \Models_Factions_Influences;
            
            $oldFactions                    = $factionsInfluencesModel->getByRefSystem($currentSystem->getId());
            $newFactions                    = array();
            
            // Create a list of factions with their IDs
            if(array_key_exists('Factions', $message))
            {
                foreach($message['Factions'] AS $faction)
                {
                    $faction['Name']    = trim($faction['Name']);
                    $factionId          = null;
                    
                    if(!empty($faction['Name']) && !in_array($faction['Name'], Engineer::getAll()))
                    {
                        // Special continue case for Pilots Federation Local Branch
                        if($faction['Name'] == 'Pilots Federation Local Branch' && $faction['Influence'] == 0)
                        {
                            continue;
                        }
                        
                        $factionId          = $factionsModel->getByName($faction['Name']);
                        $governmentAlias    = Government::getFromFd($faction['Government']);
                        $allegianceAlias    = Allegiance::getFromFd($faction['Allegiance']);
                        
                        if(is_null($factionId))
                        {
                            try
                            {
                                $insert     = array('name' => $faction['Name']);
                                $factionId  = $factionsModel->insert($insert);
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
                    
                    if(!is_null($factionId) && array_key_exists('id', $factionId))
                    {
                        $factionId = $factionId['id'];
                        
                        if(!is_null($allegianceAlias))
                        {
                            if(\EDSM_System_Station_Faction::getInstance($factionId)->getAllegiance() != $allegianceAlias)
                            {
                                $factionsModel->updateById(
                                    $factionId,
                                    array('allegiance' => $allegianceAlias)
                                );
                                
                                //\EDSM_Api_Logger::log('FACTION:     - Allegiance     : ' . Allegiance::get($allegianceAlias));
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
                                $factionsModel->updateById(
                                    $factionId,
                                    array('government' => $governmentAlias)
                                );
                                
                                //\EDSM_Api_Logger::log('FACTION:     - Government     : ' . Government::get($governmentAlias));
                            }
                        }
                        else
                        {
                            \EDSM_Api_Logger_Alias::log('Alias\System\Government #' . $currentSystem->getId() . ':' . $faction['Government']);
                        }
                        
                        $newFactions[$factionId] = $faction;
                    }
                }
            }
            
            // Update old factions
            if(!is_null($oldFactions) && count($oldFactions) > 0)
            {
                \EDSM_Api_Logger::log('<span class="text-info">EDDN\System\Faction:</span>            ' . $currentSystem->getName() . ' #' . $currentSystem->getId() . ' updated factions.');
                
                $currentSystemStations = $currentSystem->getStations();
                
                foreach($oldFactions AS $oldFaction)
                {
                    // Do not update if event timestamps are older than last update
                    if(strtotime($oldFaction['dateUpdated']) < strtotime($message['timestamp']))
                    {
                        // Do not delete faction not present anymore, just state they have gone by putting influence to zero and keep the history
                        if(!array_key_exists($oldFaction['refFaction'], $newFactions))
                        {
                            //$factionsInfluencesModel->deleteById($oldFaction['id']);
                            
                            $newFactions[$oldFaction['refFaction']] = array(
                                'Influence'     => 0,
                                'FactionState'  => 'none',
                            );
                        }
                        
                        // Update values
                        if(array_key_exists($oldFaction['refFaction'], $newFactions))
                        {
                            $updateArray = array();
                            
                            // Faction influence
                            if(
                                   $oldFaction['influence'] != $newFactions[$oldFaction['refFaction']]['Influence'] 
                                && (
                                       $newFactions[$oldFaction['refFaction']]['Influence'] != $oldFaction['oldInfluence']
                                    || ($newFactions[$oldFaction['refFaction']]['Influence'] == $oldFaction['oldInfluence'] && (strtotime($factionsInfluencesDateUpdate) - 21600) >  strtotime($oldFaction['dateUpdated']))
                                )
                            )
                            {
                                $updateArray['influence']       = $newFactions[$oldFaction['refFaction']]['Influence'];
                                $updateArray['oldInfluence']    = $oldFaction['influence'];
                                    
                                if(is_null($oldFaction['influenceHistory']))
                                {
                                    $updateArray['influenceHistory'] = array(strtotime($oldFaction['dateUpdated']) => $oldFaction['influence']);
                                }
                                else
                                {
                                    $updateArray['influenceHistory'] = \Zend_Json::decode($oldFaction['influenceHistory']);
                                }
                                
                                $updateArray['influenceHistory'][strtotime($factionsInfluencesDateUpdate)] = $newFactions[$oldFaction['refFaction']]['Influence'];
                                
                                if(count($updateArray['influenceHistory']) > 1)
                                {
                                    // Clean if history have at least 180 updates and the first update is more than 6 month old.
                                    reset($updateArray['influenceHistory']);
                                    $firstDateUpdate = key($updateArray['influenceHistory']);
                                    if(count($updateArray['influenceHistory']) > 180 &&  $firstDateUpdate < strtotime('6 MONTH AGO'))
                                    {
                                        unset($updateArray['influenceHistory'][$firstDateUpdate]);
                                    }
                                }
                                
                                $updateArray['influenceHistory'] = \Zend_Json::encode($updateArray['influenceHistory']);
                                
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
                                    
                                    if(is_null($oldFaction['stateHistory']))
                                    {
                                        $updateArray['stateHistory'] = array(strtotime($oldFaction['dateUpdated']) => $oldFaction['state']);
                                    }
                                    else
                                    {
                                        $updateArray['stateHistory'] = \Zend_Json::decode($oldFaction['stateHistory']);
                                    }
                                
                                    $updateArray['stateHistory'][strtotime($factionsInfluencesDateUpdate)] = $stateAlias;
                                    
                                    if(count($updateArray['stateHistory']) > 1)
                                    {
                                        // Clean if history have at least 180 updates and the first update is more than 6 month old.
                                        reset($updateArray['stateHistory']);
                                        $firstDateUpdate = key($updateArray['stateHistory']);
                                        if(count($updateArray['stateHistory']) > 180 &&  $firstDateUpdate < strtotime('6 MONTH AGO'))
                                        {
                                            unset($updateArray['stateHistory'][$firstDateUpdate]);
                                        }
                                    }
                                    
                                    $updateArray['stateHistory'] = \Zend_Json::encode($updateArray['stateHistory']);
                                    
                                    // Faction is in WAR/CIVIL WAR/ELECTION, update all other systems, stations controlled by the faction to NONE
                                    //         If war, both Factions are in war in systems where both factions exists
                                    // Faction is in BOOM/EXPANSION, update all other controlled systems and stations to the same state
                                    // yes, it was needed, because there may be the state "none" received from the system where is not the faction at war, although it still at war in another system.
                                    // So basically, when the other state than W/CW/E/None is received, I set it for all the systems, when "none" received, I am checking if there is no W/CW/E state pending in any system for that faction and then I am setting it as none for the faction.
                                    // It may be a little bit "slower" when there was a a war, etc. pending in multiple systems and there is low rate of data received from some of them, but it is unfortunately the only way how to do it, otherwise the W/CW/E state is reset too early for the faction
                                    if($stateAlias == 12 /* WAR */ || $stateAlias == 1 /* BOOM */ | $stateAlias == 6 /* EXPANSION */)
                                    {
                                        $currentFactionInfluences = $factionsInfluencesModel->getByRefFaction($oldFaction['refFaction']);
                                        
                                        if(!is_null($currentFactionInfluences))
                                        {
                                            foreach($currentFactionInfluences AS $factionInfluence)
                                            {
                                                
                                                if($oldFaction['refSystem'] != $factionInfluence['refSystem'])
                                                {
                                                    if($factionInfluence['state'] > 0)
                                                    {
                                                        //\EDSM_Api_Logger::log('FACTION UPDATE STATE: ' . $newFactions[$oldFaction['refFaction']]['Name'] . ' / ' . $factionInfluence['refSystem'] . ' / ' . $factionInfluence['state']);
                                                    }
                                                }
                                                else
                                                {
                                                    //\EDSM_Api_Logger::log('FACTION KEEP STATE: ' . $newFactions[$oldFaction['refFaction']]['Name'] . ' / ' . $factionInfluence['refSystem'] . ' / ' . $factionInfluence['state']);
                                                }
                                            }
                                        }
                                    }
                                    
                                    
                                    if($stateAlias > 0 && strtolower($newFactions[$oldFaction['refFaction']]['FactionState']) != 'none')
                                    {
                                        $currentFactionInfluences = $factionsInfluencesModel->getByRefFaction($oldFaction['refFaction']);
                                        
                                        if(!is_null($currentFactionInfluences))
                                        {
                                            foreach($currentFactionInfluences AS $factionInfluence)
                                            {
                                                
                                                if($oldFaction['refSystem'] != $factionInfluence['refSystem'])
                                                {
                                                    if($factionInfluence['state'] > 0)
                                                    {
                                                        //\EDSM_Api_Logger::log('FACTION UPDATE STATE: ' . $newFactions[$oldFaction['refFaction']]['Name'] . ' / ' . $factionInfluence['refSystem'] . ' / ' . $factionInfluence['state']);
                                                    }
                                                }
                                                else
                                                {
                                                    //\EDSM_Api_Logger::log('FACTION KEEP STATE: ' . $newFactions[$oldFaction['refFaction']]['Name'] . ' / ' . $factionInfluence['refSystem'] . ' / ' . $factionInfluence['state']);
                                                }
                                            }
                                        }
                                    }
                                    
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
                            
                            // Faction Pending States
                            if(!array_key_exists('PendingStates', $newFactions[$oldFaction['refFaction']]))
                            {
                                // If no pending states, resets for current faction
                                $newFactions[$oldFaction['refFaction']]['PendingStates'] = null;
                            }
                            
                            if(array_key_exists('PendingStates', $newFactions[$oldFaction['refFaction']]))
                            {
                                $pendingStates = $newFactions[$oldFaction['refFaction']]['PendingStates'];
                                
                                // Convert pending states to aliases
                                if(!is_null($pendingStates))
                                {
                                    $temp           = array();
                                    
                                    foreach($pendingStates AS $pendingState)
                                    {
                                        $stateAlias = State::getFromFd($pendingState['State']);
                                        
                                        if(!is_null($stateAlias))
                                        {
                                            $temp[] = array(
                                                'trend'     => $pendingState['Trend'],
                                                'state'     => $stateAlias,
                                            );
                                        }
                                        else
                                        {
                                            \EDSM_Api_Logger_Alias::log('Alias\System\State #' . $currentSystem->getId() . ':' . $pendingState['State']);
                                        }
                                    }
                                    
                                    $pendingStates = \Zend_Json::encode($temp);
                                    unset($temp);
                                }
                                
                                if(!array_key_exists('pendingStates', $oldFaction) || $oldFaction['pendingStates'] != $pendingStates)
                                {
                                    $updateArray['pendingStates'] = $pendingStates;
                                    
                                    if(!array_key_exists('pendingStatesHistory', $oldFaction) || is_null($oldFaction['pendingStatesHistory']))
                                    {
                                        $updateArray['pendingStatesHistory'] = array();
                                    }
                                    else
                                    {
                                        $updateArray['pendingStatesHistory'] = \Zend_Json::decode($oldFaction['pendingStatesHistory']);
                                    }
                                
                                    $updateArray['pendingStatesHistory'][strtotime($factionsInfluencesDateUpdate)] = (!is_null($pendingStates)) ? \Zend_Json::decode($pendingStates) : null;
                                    
                                    if(count($updateArray['pendingStatesHistory']) > 1)
                                    {
                                        // Clean if history have at least 180 updates and the first update is more than 6 month old.
                                        reset($updateArray['pendingStatesHistory']);
                                        $firstDateUpdate = key($updateArray['pendingStatesHistory']);
                                        if(count($updateArray['pendingStatesHistory']) > 180 &&  $firstDateUpdate < strtotime('6 MONTH AGO'))
                                        {
                                            unset($updateArray['pendingStatesHistory'][$firstDateUpdate]);
                                        }
                                    }
                                    
                                    $updateArray['pendingStatesHistory'] = \Zend_Json::encode($updateArray['pendingStatesHistory']);
                                }
                            }
                            
                            // Faction Recovering States
                            if(!array_key_exists('RecoveringStates', $newFactions[$oldFaction['refFaction']]))
                            {
                                // If no recovering states, resets for current faction
                                $newFactions[$oldFaction['refFaction']]['RecoveringStates'] = null;
                            }
                            
                            if(array_key_exists('RecoveringStates', $newFactions[$oldFaction['refFaction']]))
                            {
                                $recoveringStates = $newFactions[$oldFaction['refFaction']]['RecoveringStates'];
                                
                                // Convert recovering states to aliases
                                if(!is_null($recoveringStates))
                                {
                                    $temp           = array();
                                    
                                    foreach($recoveringStates AS $recoveringState)
                                    {
                                        $stateAlias = State::getFromFd($recoveringState['State']);
                                        
                                        if(!is_null($stateAlias))
                                        {
                                            $temp[] = array(
                                                'trend'     => $recoveringState['Trend'],
                                                'state'     => $stateAlias,
                                            );
                                        }
                                        else
                                        {
                                            \EDSM_Api_Logger_Alias::log('Alias\System\State #' . $currentSystem->getId() . ':' . $recoveringState['State']);
                                        }
                                    }
                                    
                                    $recoveringStates = \Zend_Json::encode($temp);
                                    unset($temp);
                                }
                                
                                if(!array_key_exists('recoveringStates', $oldFaction) || $oldFaction['recoveringStates'] != $recoveringStates)
                                {
                                    $updateArray['recoveringStates'] = $recoveringStates;
                                    
                                    if(!array_key_exists('recoveringStatesHistory', $oldFaction) || is_null($oldFaction['recoveringStatesHistory']))
                                    {
                                        $updateArray['recoveringStatesHistory'] = array();
                                    }
                                    else
                                    {
                                        $updateArray['recoveringStatesHistory'] = \Zend_Json::decode($oldFaction['recoveringStatesHistory']);
                                    }
                                
                                    $updateArray['recoveringStatesHistory'][strtotime($factionsInfluencesDateUpdate)] = (!is_null($recoveringStates)) ? \Zend_Json::decode($recoveringStates) : null;
                                    
                                    if(count($updateArray['recoveringStatesHistory']) > 1)
                                    {
                                        // Clean if history have at least 180 updates and the first update is more than 6 month old.
                                        reset($updateArray['recoveringStatesHistory']);
                                        $firstDateUpdate = key($updateArray['recoveringStatesHistory']);
                                        if(count($updateArray['recoveringStatesHistory']) > 180 &&  $firstDateUpdate < strtotime('6 MONTH AGO'))
                                        {
                                            unset($updateArray['recoveringStatesHistory'][$firstDateUpdate]);
                                        }
                                    }
                                    
                                    $updateArray['recoveringStatesHistory'] = \Zend_Json::encode($updateArray['recoveringStatesHistory']);
                                }
                            }
                            
                            if(count($updateArray) > 0 || (strtotime($factionsInfluencesDateUpdate) - 21600) >  strtotime($oldFaction['dateUpdated']))
                            {
                                $updateArray['dateUpdated'] = $factionsInfluencesDateUpdate;
                            }
                            
                            if(count($updateArray) > 0)
                            {
                                $factionsInfluencesModel->updateById($oldFaction['id'], $updateArray);
                                
                                \EDSM_Api_Logger::log('<span class="text-info">EDDN\System\Faction:</span>                - Update ' . \EDSM_System_Station_Faction::getInstance($oldFaction['refFaction'])->getName() . ' #' . $oldFaction['refFaction']);
                            }
                            
                            unset($newFactions[$oldFaction['refFaction']]);
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
                                    $currentUser = \EDSM_User::getInstance($refUser);
                                    
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
                                    $currentUser = \EDSM_User::getInstance($refUser);
                                    
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
                                $currentUser = \EDSM_User::getInstance($refUser);
                                
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