<?php
/**
 * Elite Dangerous Star Map
 * @link https://www.edsm.net/
 */

namespace   EDDN\Station;

use         Alias\Ship\Type as ShipType;

class Ships
{
    static public function handle($systemId, $stationId, $message)
    {
        $currentSystem  = \EDSM_System::getInstance($systemId);
        $currentStation = \EDSM_System_Station::getInstance($stationId);
        
        if($currentSystem->isValid() && $currentSystem->isHidden() === false && $currentStation->isValid() && $currentSystem->getId() == $currentStation->getSystem()->getId())
        {
            if(array_key_exists('ships', $message) && array_key_exists('timestamp', $message))
            {
                $message['timestamp']       = str_replace(array('T', 'Z'), array(' ', ''), $message['timestamp']);
                
                // Compare last update with message timestamp
                if(strtotime($currentStation->getShipyardLastUpdate()) < strtotime($message['timestamp']))
                {
                    $tweetDeletedShips          = array();
                    $tweetAddedShips            = array();
                    
                    $stationsModel              = new \Models_Stations;
                    $stationsShipsModel         = new \Models_Stations_Ships;
                    
                    \EDSM_Api_Logger::log('<span class="text-info">EDDN\Station\Ships:</span>             ' . $currentStation->getName() . ' (#' . $currentStation->getId() . ') updated shipyard.');
                    
                    $newShips       = array();
                    $nbUpdates      = 0;
                    
                    // Create list of ships with ID
                    foreach($message['ships'] AS $ship)
                    {
                        $shipId = ShipType::getFromFd($ship);
                        
                        if(is_null($shipId))
                        {
                            \EDSM_Api_Logger_Alias::log('Alias\Ship\Type #' . $currentStation->getId() . ':' . $ship);
                        }
                        else
                        {
                            $newShips[] = $shipId;
                        }
                    }
                    
                    $oldShips       = $stationsShipsModel->getByRefStation($currentStation->getId());
                    
                    // Delete ships not in the station anymore
                    if(!is_null($oldShips) && count($oldShips) > 0)
                    {
                        foreach($oldShips AS $oldShip)
                        {
                            // Update
                            if(in_array($oldShip['refShip'], $newShips))
                            {
                                $nbUpdates++;
                                
                                unset($newShips[array_search($oldShip['refShip'], $newShips)]);
                            }
                            else
                            {
                                \EDSM_Api_Logger::log('<span class="text-info">EDDN\Station\Ships:</span>                 - Delete ' . ShipType::get($oldShip['refShip']));
                                
                                $stationsShipsModel->deleteById($oldShip['id']);
                                $tweetDeletedShips[] = ShipType::get($oldShip['refShip']);
                            }
                        }
                    }
                    
                    if($nbUpdates > 0)
                    {
                        \EDSM_Api_Logger::log('<span class="text-info">EDDN\Station\Ships:</span>                 - Updated ' . \Zend_Locale_Format::toNumber($nbUpdates) . ' ships');
                    }
                    
                    // Add new ships
                    foreach($newShips AS $newShip)
                    {
                        \EDSM_Api_Logger::log('<span class="text-info">EDDN\Station\Ships:</span>                 - Add ' . ShipType::get($newShip));
                        
                        $stationsShipsModel->insert(array(
                            'refStation'    => $currentStation->getId(),
                            'refShip'       => $newShip
                        ));
                        
                        if(!is_null($oldShips) && count($oldShips) > 0)
                        {
                            $tweetAddedShips[] = ShipType::get($newShip);
                        }
                        
                        // Find alerts
                        $usersAlertsModel   = new \Models_Users_Alerts;
                        $alerts             = $usersAlertsModel->getAlerts(
                            $currentStation->getId(),
                            2,
                            $newShip
                        );
                        
                        foreach($alerts AS $alert)
                        {
                            $user = \Component\User::getInstance($alert['refUser']);
                            
                            if($user->isValid())
                            {
                                try
                                {
                                    $mail = new \EDSM_Mail();
                                    $mail->setTemplate('alert.phtml');
                                    $mail->setLanguage($user->getLocale());
                                    $mail->setVariables(array(
                                        'commander'     => $user->getCMDR(),
                                        'system'        => $currentSystem,
                                        'station'       => $currentStation,
                                        'objectToFind'  => ShipType::get($newShip),
                                    ));
                                    
                                    $mail->addTo($user->getEmail());      
                                    
                                    $mail->setSubject($mail->getView()->translate('EMAIL\%1$s is in stock!' , ShipType::get($newShip)));
                                    $mail->send();
                                } 
                                catch(\Zend_Exception $e)
                                {
                                    // Do nothing, too bad the user will not see our alert email :)
                                }
                            }
                            
                            $usersAlertsModel->deleteById($alert['id']);
                            
                            \EDSM_Api_Logger::log('<span class="text-info">EDDN\Station\Ships:</span>                 - Send alert to ' . $user->getCMDR());
                        }
                    }
                    
                    // Update update date into station
                    $stationsModel->updateById($currentStation->getId(), array('shipyardUpdateTime' => $message['timestamp']));
                    
                    // Tweet deleted ships
                    if(count($tweetDeletedShips) > 0)
                    {
                        try
                        {
                            sort($tweetDeletedShips);
                            \EDSM_Api_Tweet::status(
                                  '"' . implode('", "', $tweetDeletedShips) . '" '
                                . ( (count($tweetDeletedShips) > 1) ? 'are' : 'is') 
                                . ' no longer in stock at ' 
                                . '"' . $currentSystem->getName() . ' / ' . $currentStation->getName() . '"'
                                //. ' #EliteDangerous'
                                . ' https://www.edsm.net/en/system/stations/id/' . $currentSystem->getId() . '/name//details/idS/' . $currentStation->getId() . '/nameS/' . urlencode($currentStation->getName()),
                                true
                            );
                        }
                        catch(\Zend_Exception $e){}
                    }
                    
                    // Tweet added ships
                    if(count($tweetAddedShips) > 0)
                    {
                        try
                        {
                            sort($tweetAddedShips);
                            \EDSM_Api_Tweet::status(
                                  '"' . implode('", "', $tweetAddedShips) . '" '
                                . ( (count($tweetAddedShips) > 1) ? 'are' : 'is') 
                                . ' back in stock at ' 
                                . '"' . $currentSystem->getName() . ' / ' . $currentStation->getName() . '"'
                                //. ' #EliteDangerous'
                                . ' https://www.edsm.net/en/system/stations/id/' . $currentSystem->getId() . '/name//details/idS/' . $currentStation->getId() . '/nameS/' . urlencode($currentStation->getName()),
                                true
                            );
                        }
                        catch(\Zend_Exception $e){}
                    }
                }
                else
                {
                    \EDSM_Api_Logger::log('<span class="text-info">EDDN\Station\Ships:</span>             <span class="text-danger">' . $currentStation->getName() . ' (#' . $currentStation->getId() . ') shipyard too old (' . $message['timestamp'] . ').</span>');
                }
            }
        }
    }
}