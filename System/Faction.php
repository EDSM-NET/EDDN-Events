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
    private static $delayInfluenceCache = 43200;
    
    static public function handle($systemId, $message)
    {
        $currentSystem  = \EDSM_System::getInstance($systemId);
        
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
                                    
                                    //TODO: DELETE CAUSE NEW BGS?
                                    // Faction is in WAR/CIVIL WAR/ELECTION, update all other systems, stations controlled by the faction to NONE
                                    //         If war, both Factions are in war in systems where both factions exists
                                    // Faction is in BOOM/EXPANSION, update all other controlled systems and stations to the same state
                                    // yes, it was needed, because there may be the state "none" received from the system where is not the faction at war, although it still at war in another system.
                                    // So basically, when the other state than W/CW/E/None is received, I set it for all the systems, when "none" received, I am checking if there is no W/CW/E state pending in any system for that faction and then I am setting it as none for the faction.
                                    // It may be a little bit "slower" when there was a a war, etc. pending in multiple systems and there is low rate of data received from some of them, but it is unfortunately the only way how to do it, otherwise the W/CW/E state is reset too early for the faction
                                    /*
                                    if(
                                           $stateAlias == 12    // WAR
                                        || $stateAlias == 1     // BOOM
                                        || $stateAlias == 6     // EXPANSION
                                    )
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
                                    */
                                    
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
                            
                            // Faction Active States
                            //TODO: Unlock Q4 ;)
                            /*
                            if(!array_key_exists('ActiveStates', $newFactions[$oldFaction['refFaction']]))
                            {
                                // If no active states, resets for current faction
                                $newFactions[$oldFaction['refFaction']]['ActiveStates'] = null;
                            }
                            
                            if(array_key_exists('ActiveStates', $newFactions[$oldFaction['refFaction']]))
                            {
                                $activeStates = $newFactions[$oldFaction['refFaction']]['ActiveStates'];
                                
                                // Convert active states to aliases
                                if(!is_null($activeStates))
                                {
                                    $temp           = array();
                                    
                                    foreach($activeStates AS $activeState)
                                    {
                                        $stateAlias = State::getFromFd($activeState['State']);
                                        
                                        if(!is_null($stateAlias))
                                        {
                                            $temp[] = array(
                                                'trend'     => $activeState['Trend'],
                                                'state'     => $stateAlias,
                                            );
                                        }
                                        else
                                        {
                                            \EDSM_Api_Logger_Alias::log('Alias\System\State #' . $currentSystem->getId() . ':' . $activeStates['State']);
                                        }
                                    }
                                    
                                    $activeStates = \Zend_Json::encode($temp);
                                    unset($temp);
                                }
                                
                                if(!array_key_exists('activeStates', $oldFaction) || $oldFaction['activeStates'] != $activeStates)
                                {
                                    $updateArray['activeStates']   = $activeStates;
                                    $insertHistory['activeStates'] = $oldFaction['activeStates'];
                                }
                            }
                            */
                            
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
                                    $updateArray['pendingStates']   = $pendingStates;
                                    $insertHistory['pendingStates'] = $oldFaction['pendingStates'];
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
                                    $updateArray['recoveringStates']    = $recoveringStates;
                                    $insertHistory['recoveringStates']  = $oldFaction['recoveringStates'];
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