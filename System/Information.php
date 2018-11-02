<?php
/**
 * Elite Dangerous Star Map
 * @link https://www.edsm.net/
 */

namespace   EDDN\System;

use         Alias\System\Allegiance;
use         Alias\System\Government;
use         Alias\System\Economy;
use         Alias\System\State;
use         Alias\System\Security;

class Information
{
    static public function handle($systemId, $message)
    {
        $message        = self::_convertJournalMessage($message);

        $currentSystem  = \Component\System::getInstance($systemId);

        if($currentSystem->isValid() && $currentSystem->isHidden() === false)
        {
            // Update ID64
            try
            {
                if(is_null($currentSystem->getId64()))
                {
                    $id64 = null;

                    if(array_key_exists('SystemAddress', $message))
                    {
                        $id64 = $message['SystemAddress'];
                    }
                    else
                    {
                        $id64 = $currentSystem->getId64FromEDTS();
                    }

                    if(!is_null($id64))
                    {
                        \EDSM_Api_Logger::log('<span class="text-info">EDDN\System\Information:</span>        Updated ID64 : ' . $id64);

                        $systemsModel = new \Models_Systems;
                        $systemsModel->updateById(
                            $currentSystem->getId(),
                            array(
                                'id64'  => $id64,
                            ),
                            false
                        );
                    }
                }
            }
            catch(\Zend_Exception $e)
            {

            }

            $currentInformation = $currentSystem->getInformation();
            $newInformation     = array();

            // Check if message is newer than the last stored done
            if(!is_null($currentInformation) && strtotime($currentInformation['dateUpdated']) > strtotime($message['timestamp']))
            {
                \EDSM_Api_Logger::log('<span class="text-info">EDDN\System\Information:</span>        <span class="text-danger">' . $currentSystem->getName() . ' (#' . $currentSystem->getId() . ') information too old (' . $message['timestamp'] . ').</span>');
                return;
            }

            if(array_key_exists('SystemAllegiance', $message) && !empty(trim($message['SystemAllegiance'])))
            {
                // Population
                if(array_key_exists('Population', $message) && $message['Population'] != $currentInformation['population'])
                {
                    $newInformation['population'] = $message['Population'];
                }

                // Allegiance
                $allegiance         = trim($message['SystemAllegiance']);
                $allegianceAlias    = Allegiance::getFromFd($allegiance);

                if(!empty($allegiance) && !is_null($allegianceAlias))
                {
                    if(is_null($currentInformation) || $currentInformation['allegiance'] != $allegianceAlias)
                    {
                        $newInformation['allegiance'] = $allegianceAlias;
                    }
                }
                elseif(!empty($allegiance) && is_null($allegianceAlias))
                {
                    \EDSM_Api_Logger_Alias::log('Alias\System\Allegiance #' . $currentSystem->getId() . ':' . $allegiance);
                }
                else
                {
                    $newInformation['allegiance'] = 0;
                }

                // Government
                $government         = trim($message['SystemGovernment']);
                $governmentAlias    = Government::getFromFd($government);

                if(!empty($government))
                {
                    if(!is_null($governmentAlias))
                    {
                        if(is_null($currentInformation) || $currentInformation['government'] != $governmentAlias)
                        {
                            $newInformation['government'] = $governmentAlias;
                        }
                    }
                    else
                    {
                        \EDSM_Api_Logger_Alias::log('Alias\System\Government #' . $currentSystem->getId() . ':' . $government);
                    }
                }

                // Faction
                if(array_key_exists('SystemFaction', $message))
                {
                    $factionsModel  = new \Models_Factions;
                    $factionName    = trim($message['SystemFaction']);

                    if(!empty($factionName))
                    {
                        $factionId      = $factionsModel->getByName($factionName);

                        if(is_null($factionId))
                        {
                            $factionId = $factionsModel->insert(array('name' => $factionName));
                        }
                        else
                        {
                            $factionId = $factionId['id'];
                        }

                        if(!is_null($factionId) && (is_null($currentInformation) || $currentInformation['refFaction'] != $factionId))
                        {
                            $newInformation['refFaction'] = $factionId;
                        }

                        // Faction State
                        if(array_key_exists('FactionState', $message))
                        {
                            $state      = trim($message['FactionState']);
                            $stateAlias = State::getFromFd($state);

                            if(!empty($state) && !is_null($stateAlias))
                            {
                                if(is_null($currentInformation) || $currentInformation['factionState'] != $stateAlias)
                                {
                                    $newInformation['factionState'] = $stateAlias;
                                }
                            }
                            elseif(!empty($state) && is_null($stateAlias))
                            {
                                \EDSM_Api_Logger_Alias::log('Alias\System\State #' . $currentSystem->getId() . ':' . $state);
                            }
                            elseif(!is_null($currentInformation) && $currentInformation['factionState'] != 0)
                            {
                                $newInformation['factionState'] = 0;
                            }
                        }
                        elseif(!is_null($currentInformation) && $currentInformation['factionState'] != 0)
                        {
                            $newInformation['factionState'] = 0;
                        }
                    }
                }

                // Security
                $security       = trim($message['SystemSecurity']);
                $securityAlias  = Security::getFromFd($security);

                if(!empty($security) && !is_null($securityAlias))
                {
                    if(is_null($currentInformation) || $currentInformation['security'] != $securityAlias)
                    {
                        $newInformation['security'] = $securityAlias;
                    }
                }
                elseif(!empty($security) && is_null($securityAlias))
                {
                    \EDSM_Api_Logger_Alias::log('Alias\System\Security #' . $currentSystem->getId() . ':' . $security);
                }
                else
                {
                    $newInformation['security'] = 0;
                }

                // Economy
                $economy        = trim($message['SystemEconomy']);
                $economyAlias   = Economy::getFromFd($economy);

                if(!empty($economy) && !is_null($economyAlias))
                {
                    if(is_null($currentInformation) || $currentInformation['economy'] != $economyAlias)
                    {
                        $newInformation['economy'] = $economyAlias;
                    }
                }
                elseif(!empty($economy) && is_null($economyAlias))
                {
                    \EDSM_Api_Logger_Alias::log('Alias\System\Economy #' . $currentSystem->getId() . ':' . $economy);
                }
                else
                {
                    $newInformation['economy'] = 0;
                }

                // Second Economy
                if(array_key_exists('SystemSecondEconomy', $message))
                {
                    $economy        = trim($message['SystemSecondEconomy']);
                    $economyAlias   = Economy::getFromFd($economy);

                    if(!empty($economy) && !is_null($economyAlias))
                    {
                        if(is_null($currentInformation) || !array_key_exists('secondEconomy', $currentInformation) || $currentInformation['secondEconomy'] != $economyAlias)
                        {
                            $newInformation['secondEconomy'] = $economyAlias;
                        }
                    }
                    elseif(!empty($economy) && is_null($economyAlias) && $economy != '$economy_Undefined;')
                    {
                        \EDSM_Api_Logger_Alias::log('Alias\System\Economy #' . $currentSystem->getId() . ':' . $economy);
                    }
                    else
                    {
                        $newInformation['secondEconomy'] = 0;
                    }
                }

                // Update system informations
                if(count($newInformation) > 0)
                {
                    $systemsInformationsModel       = new \Models_Systems_Informations;
                    $newInformation['dateUpdated']  = $message['timestamp'];

                    if(!is_null($currentInformation))
                    {
                        $systemsInformationsModel->updateByRefSystem($currentSystem->getId(), $newInformation);
                    }
                    else
                    {
                        $newInformation['refSystem'] = $currentSystem->getId();
                        $systemsInformationsModel->insert($newInformation);
                    }

                    \EDSM_Api_Logger::log('<span class="text-info">EDDN\System\Information:</span>        ' . $currentSystem->getName() . ' (#' . $currentSystem->getId() . ') updated information.');

                    if(array_key_exists('allegiance', $newInformation))
                    {
                        \EDSM_Api_Logger::log('<span class="text-info">EDDN\System\Information:</span>            - Allegiance     : ' . Allegiance::get($newInformation['allegiance']));
                    }
                    if(array_key_exists('government', $newInformation))
                    {
                        \EDSM_Api_Logger::log('<span class="text-info">EDDN\System\Information:</span>            - Government     : ' . Government::get($newInformation['government']));
                    }
                    if(array_key_exists('refFaction', $newInformation))
                    {
                        \EDSM_Api_Logger::log('<span class="text-info">EDDN\System\Information:</span>            - Faction        : ' . \EDSM_System_Station_Faction::getInstance($newInformation['refFaction'])->getName() . ' #' . $newInformation['refFaction']);
                    }
                    if(array_key_exists('factionState', $newInformation))
                    {
                        \EDSM_Api_Logger::log('<span class="text-info">EDDN\System\Information:</span>            - Faction State  : ' . State::get($newInformation['factionState']));
                    }
                    if(array_key_exists('security', $newInformation))
                    {
                        \EDSM_Api_Logger::log('<span class="text-info">EDDN\System\Information:</span>            - Security       : ' . Security::get($newInformation['security']));
                    }
                    if(array_key_exists('economy', $newInformation))
                    {
                        \EDSM_Api_Logger::log('<span class="text-info">EDDN\System\Information:</span>            - Economy        : ' . Economy::get($newInformation['economy']));
                    }
                }
            }
        }
    }

    static private function _convertJournalMessage($message)
    {
        if(array_key_exists('Allegiance', $message))
        {
            $message['SystemAllegiance'] = $message['Allegiance'];
            unset($message['Allegiance']);
        }

        if(array_key_exists('Government', $message))
        {
            $message['SystemGovernment'] = $message['Government'];
            unset($message['Government']);
        }

        if(array_key_exists('Faction', $message))
        {
            $message['SystemFaction'] = $message['Faction'];
            unset($message['Faction']);
        }

        if(array_key_exists('Security', $message))
        {
            $message['SystemSecurity'] = $message['Security'];
            unset($message['Security']);
        }

        if(array_key_exists('Economy', $message))
        {
            $message['SystemEconomy'] = $message['Economy'];
            unset($message['Economy']);
        }

        return $message;
    }
}